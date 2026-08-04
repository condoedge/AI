<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Services\Resilience\RateLimiter;
use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Support\AiLogger;

/**
 * Query Generator Service
 *
 * Transforms natural language questions into safe, valid Cypher queries
 * using LLM and context from RAG (graph schema, similar queries, examples).
 *
 * Validation/sanitization is delegated to {@see CypherValidator} and the
 * deterministic template path to {@see TemplateMatcher}; this class owns the
 * LLM retry/validate loop and prompt assembly.
 *
 * @package Condoedge\Ai\Services
 */
class QueryGenerator implements QueryGeneratorInterface
{
    /**
     * Semantic prompt builder (optional - for semantic metadata support)
     */
    private ?SemanticPromptBuilder $promptBuilder = null;

    /**
     * Cypher validation / sanitization (canonical keyword list lives here)
     */
    private CypherValidator $validator;

    /**
     * Deterministic, LLM-free template generation path
     */
    private TemplateMatcher $templateMatcher;

    /**
     * Rate limiter for LLM API calls
     */
    private RateLimiter $rateLimiter;

    /**
     * Logger instance
     */
    private AiLogger $logger;

    /**
     * Recognizer for the tenant-scopeable subset. Used in the validate step to
     * teach the LLM to regenerate in-subset (usefulness layer), so the execution
     * security boundary rarely has to reject. Safety does NOT depend on this — the
     * SecurityFilterInjector re-analyzes and fails closed at execution time.
     */
    private CypherScopeAnalyzer $scopeAnalyzer;

    /**
     * Constructor
     *
     * @param LlmProviderInterface $llm LLM provider for query generation
     * @param GraphStoreInterface $graphStore Graph store for schema access
     * @param array $config Configuration options
     * @param SemanticPromptBuilder|null $promptBuilder Optional semantic prompt builder
     * @param AiLogger|null $logger Optional logger instance
     * @param CypherValidator|null $validator Optional validator (canonical keyword list)
     * @param TemplateMatcher|null $templateMatcher Optional template matcher
     * @param CypherScopeAnalyzer|null $scopeAnalyzer Optional subset recognizer
     */
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly GraphStoreInterface $graphStore,
        private readonly array $config = [],
        ?SemanticPromptBuilder $promptBuilder = null,
        ?AiLogger $logger = null,
        ?CypherValidator $validator = null,
        ?TemplateMatcher $templateMatcher = null,
        ?CypherScopeAnalyzer $scopeAnalyzer = null
    ) {
        $this->promptBuilder = $promptBuilder ?? new SemanticPromptBuilder(
            new PatternLibrary()
        );
        $this->rateLimiter = RateLimiter::forLlm();
        $this->logger = $logger ?? app(AiLogger::class);
        $this->validator = $validator ?? new CypherValidator();
        $this->templateMatcher = $templateMatcher ?? new TemplateMatcher();
        $this->scopeAnalyzer = $scopeAnalyzer ?? new CypherScopeAnalyzer();
    }

    /**
     * Generate a Cypher query from natural language question
     *
     * @param string $question Natural language question
     * @param PipelineContext $context Pipeline context with schema, metadata, and detected scopes
     * @param array $options Optional parameters
     * @return QueryGenerationResult Result with cypher, explanation, confidence, warnings, metadata
     * @throws QueryGenerationException If generation fails after retries
     */
    public function generate(string $question, PipelineContext $context, array $options = []): QueryGenerationResult
    {
        $this->logger->startTimer('query_generation');

        $temperature = $options['temperature'] ?? $this->config['temperature'] ?? 0.1;
        $maxRetries = $options['max_retries'] ?? $this->config['max_retries'] ?? 3;
        $allowWrite = $options['allow_write'] ?? $this->config['allow_write_operations'] ?? false;
        $explain = $options['explain'] ?? false;

        // Check if templates are enabled.
        // Skip templates only when HIGH-CONFIDENCE scopes are detected — these indicate
        // the question has filtering conditions (e.g., "unpaid", "expired", "open") that
        // templates can't handle. Low-confidence scope matches (< 0.7) are noise and
        // should NOT prevent simple template queries like "How many units?".
        $scopeBypassThreshold = $this->config['scope_bypass_threshold'] ?? 0.7;
        $hasDetectedScopes = false;
        foreach ($context->entityMetadata['detected_scopes'] ?? [] as $scope) {
            if (($scope['match_score'] ?? 0) >= $scopeBypassThreshold) {
                $hasDetectedScopes = true;
                break;
            }
        }

        if (($this->config['enable_templates'] ?? true) && !$hasDetectedScopes) {
            $template = $this->detectTemplate($question);
            if ($template) {
                $this->logger->debug('query_generator', "Template matched: {$template}", [
                    'question' => $question,
                    'template' => $template,
                ]);

                $result = $this->templateMatcher->generateFromTemplate($question, $template, $context);
                if ($result !== null) {
                    // The deterministic path is safety-checked like the LLM path: a
                    // template that produces invalid Cypher falls through to LLM
                    // generation rather than shipping straight to the executor.
                    $validation = $this->validate($result['cypher'], ['allow_write' => $allowWrite]);
                    if ($validation['valid']) {
                        $durationMs = $this->logger->stopTimer('query_generation');
                        $this->logger->info('query_generator', 'Query generated via template', [
                            'duration_ms' => $durationMs,
                            'source' => 'template',
                            'template' => $template,
                            'confidence' => $result['confidence'],
                        ]);
                        return new QueryGenerationResult(
                            cypher: $result['cypher'],
                            explanation: $result['explanation'] ?? '',
                            confidence: (float) ($result['confidence'] ?? 0.0),
                            warnings: $result['warnings'] ?? [],
                            metadata: $result['metadata'] ?? [],
                        );
                    }

                    $this->logger->warning('query_generator', 'Template output failed validation, falling through to LLM', [
                        'template' => $template,
                        'errors' => $validation['errors'],
                    ]);
                }
            } else {
                $this->logger->debug('query_generator', 'No template matched for question', [
                    'question' => $question,
                ]);
            }
        } elseif ($hasDetectedScopes) {
            $this->logger->debug('query_generator', 'Templates skipped (scopes detected)', [
                'scope_count' => count($context->entityMetadata['detected_scopes'] ?? []),
            ]);
        }

        // Try LLM generation with retries
        $retryCount = 0;
        $lastError = null;

        // Prompt-capture seam for the eval harness: attach the VERBATIM final
        // system/user strings to result metadata ONLY when explicitly requested,
        // so production payloads never grow. On retries the LAST attempt's
        // prompts win — that is the pair that produced the returned cypher.
        $capturePrompts = ($options['capture_prompts'] ?? false) === true;
        $capturedPrompts = null;

        while ($retryCount < $maxRetries) {
            try {
                // Build prompt, split into system (identity + static rules) and user.
                ['system' => $systemPrompt, 'user' => $userPrompt] =
                    $this->buildPrompts($question, $context, $allowWrite, $lastError);

                if ($capturePrompts) {
                    $capturedPrompts = ['system' => $systemPrompt, 'user' => $userPrompt];
                }

                $this->logger->debug('query_generator', 'Sending prompt to LLM', [
                    'prompt_size_bytes' => strlen($systemPrompt) + strlen($userPrompt),
                    'attempt' => $retryCount + 1,
                ]);

                if (!$this->rateLimiter->waitAndAttempt(10)) {
                    throw new QueryGenerationException('Rate limit exceeded for LLM API');
                }

                // Call LLM — identity/static rules in the real system role.
                $response = $this->llm->complete($userPrompt, $systemPrompt !== '' ? $systemPrompt : null, [
                    'temperature' => $temperature,
                    'max_tokens' => 500,
                ]);

                $this->logger->debug('query_generator', 'LLM response received', [
                    'response_size_bytes' => strlen($response ?? ''),
                    'raw_response' => $response,
                ]);

                if ($response == 'NO QUERY REQUIRED' || !isset($response) || empty($response)) {
                    $durationMs = $this->logger->stopTimer('query_generation');
                    $this->logger->info('query_generator', 'No query required', [
                        'duration_ms' => $durationMs,
                        'source' => 'llm',
                        'confidence' => 1.0,
                    ]);
                    return new QueryGenerationResult(
                        cypher: '',
                        explanation: 'No query required to answer the question.',
                        confidence: 1.0,
                        warnings: [],
                        metadata: [
                            'template_used' => null,
                            'retry_count' => $retryCount,
                            'complexity' => 0,
                        ] + ($capturedPrompts !== null ? ['prompts' => $capturedPrompts] : []),
                    );
                }

                $cypher = $this->extractCypher($response);

                $validation = $this->validate($cypher, ['allow_write' => $allowWrite]);

                // Tenant-scopeability gate (usefulness layer): if security is on, a
                // draft outside the safe-scopeable subset would fail closed at
                // execution. Reject it here with a teachable reason so the EXISTING
                // retry loop regenerates an in-subset query instead of erroring.
                // (Safety still lives in the injector; this only reduces over-DENY.)
                if ($validation['valid'] && $this->subsetEnforcementEnabled($options)) {
                    $subset = $this->scopeAnalyzer->validateSubset($cypher);
                    if (!$subset['valid']) {
                        $validation['valid'] = false;
                        $validation['errors'] = array_merge($validation['errors'] ?? [], $subset['errors']);
                    }
                }

                $this->logger->debug('query_generator', 'Validation result', [
                    'valid' => $validation['valid'],
                    'errors' => $validation['errors'] ?? [],
                    'warnings' => $validation['warnings'],
                    'complexity' => $validation['complexity'],
                ]);

                if ($validation['valid']) {
                    $confidence = $this->calculateConfidence($cypher, $context);
                    $durationMs = $this->logger->stopTimer('query_generation');
                    $this->logger->info('query_generator', 'Query generated via LLM', [
                        'duration_ms' => $durationMs,
                        'source' => 'llm',
                        'confidence' => $confidence,
                        'retry_count' => $retryCount,
                        'complexity' => $validation['complexity'],
                    ]);
                    return new QueryGenerationResult(
                        cypher: $cypher,
                        explanation: $explain ? $this->generateExplanation($cypher, $question) : '',
                        confidence: $confidence,
                        warnings: $validation['warnings'],
                        metadata: [
                            'template_used' => null,
                            'retry_count' => $retryCount,
                            'complexity' => $validation['complexity'],
                        ] + ($capturedPrompts !== null ? ['prompts' => $capturedPrompts] : []),
                    );
                }

                // Validation failed, prepare for retry
                $lastError = implode(', ', $validation['errors']);
                $retryCount++;

                $this->logger->warning('query_generator', "Retrying query generation (attempt {$retryCount}/{$maxRetries})", [
                    'retry_number' => $retryCount,
                    'error' => $lastError,
                ]);

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $retryCount++;

                $this->logger->warning('query_generator', "Retrying query generation (attempt {$retryCount}/{$maxRetries})", [
                    'retry_number' => $retryCount,
                    'error' => $lastError,
                    'exception_class' => get_class($e),
                ]);

                // Exponential backoff for rate limit errors
                if (stripos($lastError, 'rate limit') !== false && $retryCount < $maxRetries) {
                    $waitSeconds = pow(2, $retryCount); // 2s, 4s, 8s
                    $this->logger->debug('query_generator', "Backing off {$waitSeconds}s for rate limit");
                    sleep($waitSeconds);
                }
            }
        }

        // All retries exhausted
        $durationMs = $this->logger->stopTimer('query_generation');
        $this->logger->error('query_generator', "Failed to generate valid query after {$maxRetries} attempts", [
            'duration_ms' => $durationMs,
            'last_error' => $lastError,
            'max_retries' => $maxRetries,
        ]);

        // Attach the LAST attempt's verbatim prompts (capture-mode only) so a
        // failed generation is still inspectable by the eval trace — the prompts
        // must not be lost precisely when the stage fails.
        throw (new QueryGenerationException(
            "Failed to generate valid query after {$maxRetries} attempts. Last error: {$lastError}"
        ))->setPrompts($capturedPrompts);
    }

    /**
     * Validate a Cypher query for syntax and safety
     *
     * @param string $cypherQuery Query to validate
     * @param array $options Validation options
     * @return array Validation result
     * @throws \InvalidArgumentException If query is empty
     */
    public function validate(?string $cypherQuery, array $options = []): array
    {
        $options['max_complexity'] = $options['max_complexity']
            ?? $this->config['max_complexity']
            ?? 100;

        return $this->validator->validate($cypherQuery, $options);
    }

    /**
     * Whether the generation loop should reject out-of-subset drafts. Enabled
     * whenever team security is enabled (so generation targets the shape the
     * execution boundary will accept), unless explicitly turned off.
     */
    private function subsetEnforcementEnabled(array $options): bool
    {
        if (($options['skip_security'] ?? false) === true) {
            return false;
        }

        if (!config('ai.security.enabled', true)) {
            return false;
        }

        return (bool) config('ai.security.enforce_scopeable_subset', true);
    }

    /**
     * Check if a label exists in the graph schema.
     *
     * @param string $label The label to validate
     * @param array $validLabels Array of valid labels from schema
     * @return bool True if the label is valid
     */
    public function isValidLabel(string $label, array $validLabels): bool
    {
        return $this->templateMatcher->isValidLabel($label, $validLabels);
    }

    /**
     * Get available query templates
     *
     * @return array Array of template metadata
     */
    public function getTemplates(): array
    {
        return $this->templateMatcher->getTemplates();
    }

    /**
     * Detect which template (if any) matches the question
     *
     * @param string $question Natural language question
     * @return string|null Template name or null if no match
     */
    public function detectTemplate(string $question): ?string
    {
        return $this->templateMatcher->detectTemplate($question);
    }

    /**
     * Build the LLM prompt split by role (system / user).
     *
     * @param string $question User question
     * @param PipelineContext $context Pipeline context
     * @param bool $allowWrite Allow write operations
     * @param string|null $previousError Previous error for retry
     * @return array{system: string, user: string}
     */
    private function buildPrompts(string $question, PipelineContext $context, bool $allowWrite, ?string $previousError): array
    {
        // Always use SemanticPromptBuilder for consistent, high-quality prompts
        // It handles all context types including project metadata, entity metadata, scopes, etc.
        if (!$this->promptBuilder) {
            throw new \RuntimeException('SemanticPromptBuilder not initialized');
        }

        $prompts = $this->promptBuilder->buildPrompts($question, $context, $allowWrite);

        // Add retry context to the user message if needed (it concerns this attempt's data).
        if ($previousError) {
            $prompts['user'] .= "\n\nPrevious attempt failed with error: {$previousError}\n";
            $prompts['user'] .= "Please fix the error and regenerate the query.\n\n";
            $prompts['user'] .= "CYPHER QUERY:";
        }

        return $prompts;
    }

    /**
     * Extract Cypher from LLM response
     *
     * @param string $response LLM response
     * @return string Extracted Cypher
     */
    private function extractCypher(string $response): string
    {
        // Remove markdown code blocks
        $cypher = preg_replace('/```(?:cypher)?\s*(.*?)\s*```/s', '$1', $response);

        // Remove extra whitespace
        $cypher = trim($cypher);

        return $cypher;
    }

    /**
     * Generate explanation for query
     *
     * @param string $cypher Generated query
     * @param string $question Original question
     * @return string Explanation
     */
    private function generateExplanation(string $cypher, string $question): string
    {
        try {
            $prompt = "Explain this Cypher query in simple terms for the question '{$question}':\n\n{$cypher}";
            return $this->llm->complete($prompt, null, ['temperature' => 0.3, 'max_tokens' => 150]);
        } catch (\Exception $e) {
            $this->logger->error('query_generator', 'Failed to generate explanation', [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'question' => $question,
            ]);
            return "Query generated for: {$question}";
        }
    }

    /**
     * Calculate confidence score
     *
     * @param string $cypher Generated query
     * @param PipelineContext $context Pipeline context
     * @return float Confidence score (0-1)
     */
    private function calculateConfidence(string $cypher, PipelineContext $context): float
    {
        $confidence = 0.5; // Base confidence

        // Boost if schema labels are referenced
        if (!empty($context->graphSchema['labels'])) {
            foreach ($context->graphSchema['labels'] as $label) {
                if (stripos($cypher, $label) !== false) {
                    $confidence += 0.1;
                }
            }
        }

        // Boost if has LIMIT
        if (preg_match('/\bLIMIT\b/i', $cypher)) {
            $confidence += 0.1;
        }

        // Cap at 1.0
        return min($confidence, 1.0);
    }
}
