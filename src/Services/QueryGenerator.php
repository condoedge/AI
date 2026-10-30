<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Services\Query\AggregateOrderBy;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Exceptions\LlmInvalidJsonException;
use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Services\Resilience\RateLimiter;
use Condoedge\Ai\Services\Query\CypherBooleanPrecedence;
use Condoedge\Ai\Services\Query\CypherOptionalFilter;
use Condoedge\Ai\Services\Query\CypherScopeHint;
use Condoedge\Ai\Services\Security\CypherSchemaValidator;
use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\TokenUsage;
use Condoedge\Ai\Services\Response\PiiMasker;

/**
 * Query Generator Service
 *
 * Transforms natural language questions into safe, valid Cypher queries
 * using LLM and context from RAG (graph schema, similar queries, examples).
 *
 * Validation/sanitization is delegated to {@see CypherValidator}; this class
 * owns the (single) LLM retry/validate loop and prompt assembly.
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
     * Live-graph schema check of accepted drafts (Task 5.4): unknown labels,
     * nonexistent relationship patterns / directions, nonexistent properties.
     */
    private CypherSchemaValidator $schemaValidator;

    /**
     * Draft-quality check (eval p22-1001-B): a predicate mixing OR / XOR with AND at
     * one nesting level filters the last alternative only. Fed back like a schema
     * violation. Never part of the security pipeline.
     */
    private CypherBooleanPrecedence $precedenceCheck;

    /**
     * Draft-quality check: a text condition on an OPTIONAL MATCH the total does not use
     * filters nothing, so the total covers every row. Fed back like a schema violation.
     * Never part of the security pipeline.
     */
    private CypherOptionalFilter $optionalFilterCheck;

    /**
     * Constructor
     *
     * @param LlmProviderInterface $llm LLM provider for query generation
     * @param GraphStoreInterface $graphStore Graph store for schema access
     * @param array $config Configuration options
     * @param SemanticPromptBuilder|null $promptBuilder Optional semantic prompt builder
     * @param AiLogger|null $logger Optional logger instance
     * @param CypherValidator|null $validator Optional validator (canonical keyword list)
     * @param CypherScopeAnalyzer|null $scopeAnalyzer Optional subset recognizer
     * @param CypherSchemaValidator|null $schemaValidator Optional live-schema validator
     * @param CypherBooleanPrecedence|null $precedenceCheck Optional OR / AND precedence check
     * @param CypherOptionalFilter|null $optionalFilterCheck Optional check of conditions an OPTIONAL MATCH cannot apply
     */
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly GraphStoreInterface $graphStore,
        private readonly array $config = [],
        ?SemanticPromptBuilder $promptBuilder = null,
        ?AiLogger $logger = null,
        ?CypherValidator $validator = null,
        ?CypherScopeAnalyzer $scopeAnalyzer = null,
        ?CypherSchemaValidator $schemaValidator = null,
        ?CypherBooleanPrecedence $precedenceCheck = null,
        ?CypherOptionalFilter $optionalFilterCheck = null
    ) {
        $this->promptBuilder = $promptBuilder ?? new SemanticPromptBuilder();
        $this->rateLimiter = RateLimiter::forLlm();
        $this->logger = $logger ?? app(AiLogger::class);
        $this->validator = $validator ?? new CypherValidator();
        $this->scopeAnalyzer = $scopeAnalyzer ?? new CypherScopeAnalyzer();
        $this->schemaValidator = $schemaValidator ?? new CypherSchemaValidator();
        $this->precedenceCheck = $precedenceCheck ?? new CypherBooleanPrecedence();
        $this->optionalFilterCheck = $optionalFilterCheck ?? new CypherOptionalFilter();
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

        // Execution repair (Task 5.5): regenerate from the generator's OWN failed
        // draft + the Neo4j error.
        $repair = is_array($options['repair'] ?? null) ? $options['repair'] : null;

        // There is ONE generation path (Phase 2.B): the LLM, with the retry /
        // validate / schema-repair loop below. The former regex template path
        // (TemplateMatcher: English-only "show all X" / "how many X", naive
        // singularization, and an "Unknown label" throw OUTSIDE this retry loop
        // that hard-failed "How many customers?") was deleted.

        // Try LLM generation with retries.
        //
        // One retry budget ($maxRetries LLM calls) covers every reason to redraft:
        // an invalid / out-of-subset draft (hard — never returned) and a draft that
        // uses a label / relationship / property the live graph does not have
        // (soft, Task 5.4 — fed back as a schema repair; the best such draft is
        // returned with schema_violations recorded when the budget runs out, since
        // the security layer still guards execution). A predicate that mixes OR with
        // AND at one level (eval p22-1001-B) is the same kind of soft redraft, with
        // its own feedback and its own record (precedence_violations), and so is a text
        // condition an OPTIONAL MATCH cannot apply (optional_filter_violations).
        $retryCount = 0;
        $lastError = $repair !== null ? $this->executionRepairFeedback($repair) : null;
        $previousDraft = $repair !== null ? trim((string) ($repair['cypher'] ?? '')) : null;
        $schemaFacts = $this->schemaValidationEnabled($options)
            ? CypherSchemaValidator::schemaFromContext($context->graphSchema)
            : null;
        $checkPrecedence = $this->precedenceCheckEnabled($options);
        $checkOptionalFilter = $this->optionalFilterCheckEnabled($options);
        $schemaRepairs = 0;
        $softCandidate = null;
        $runMeta = ['execution_repair' => $repair !== null, 'precedence_repaired' => [], 'optional_filter_repaired' => [], 'decline_redrafted' => false];

        while ($retryCount < $maxRetries) {
            try {
                // Build prompt, split into system (identity + static rules) and user.
                ['system' => $systemPrompt, 'user' => $userPrompt] =
                    $this->buildPrompts($question, $context, $allowWrite, $lastError, $previousDraft);

                $this->logger->debug('query_generator', 'Sending prompt to LLM', [
                    'prompt_size_bytes' => strlen($systemPrompt) + strlen($userPrompt),
                    'attempt' => $retryCount + 1,
                ]);

                if (!$this->rateLimiter->waitAndAttempt(10)) {
                    throw new QueryGenerationException('Rate limit exceeded for LLM API');
                }

                $draft = $this->requestDraft($systemPrompt, $userPrompt, (float) $temperature, $context);
                $runMeta['output_format'] = $draft['format'];

                // A data question a domain formula matches holds its answer: a decline is redrafted once, naming the formula.
                if (!$draft['needs_query'] && !$draft['reuse_previous'] && !$runMeta['decline_redrafted'] && $retryCount + 1 < $maxRetries
                    && ($formulas = $this->matchedFormulaLabels($context)) !== []) {
                    $runMeta['decline_redrafted'] = true;
                    $lastError = 'You answered needs_query false (' . ($draft['reason'] !== '' ? $draft['reason'] : 'no reason given') . '), but this '
                        . 'question matches a domain calculation of this prompt (' . implode('; ', $formulas) . '): the data holds its answer. '
                        . "Answer with that calculation's Cypher, adapted to the question; needs_query false only if the question asks for nothing in the data.";
                    $previousDraft = null;
                    $retryCount++;
                    continue;
                }

                // Structured "no query needed" (Task 5.6) — replaces the exact-match
                // 'NO QUERY REQUIRED' sentinel that failed on punctuation/quotes.
                if (!$draft['needs_query']) {
                    $durationMs = $this->logger->stopTimer('query_generation');
                    $this->logger->info('query_generator', 'No query required', [
                        'duration_ms' => $durationMs,
                        'source' => 'llm',
                        'reason' => $draft['reason'],
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
                            'no_query_reason' => $draft['reason'],
                            'reuse_previous' => $draft['reuse_previous'],
                            'schema_violations' => [],
                            'precedence_violations' => [],
                            'optional_filter_violations' => [],
                            'repair_attempts' => $schemaRepairs,
                        ] + $runMeta,
                    );
                }

                $cypher = $draft['cypher'];

                // P-1 (Phase 8-A review): the conversation reached this prompt MASKED,
                // so a follow-up may reference a PII token ([email#1]). Rebind it as a
                // query parameter carrying the real value BEFORE validation — never an
                // inlined literal (which could alter the query's structure).
                [$cypher] = $this->bindPiiTokens($cypher, $context);
                $cypher = AggregateOrderBy::fix($cypher);

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
                    // Schema check (Task 5.4): only what the live graph PROVABLY lacks.
                    $violations = $schemaFacts !== null
                        ? $this->schemaValidator->validate($cypher, $schemaFacts)
                        : [];
                    // Precedence check (eval p22-1001-B): `A OR B AND C` at one level.
                    $ambiguous = $checkPrecedence ? $this->precedenceCheck->violations($cypher) : [];
                    // A text condition on an OPTIONAL MATCH the total does not use.
                    $ineffective = $checkOptionalFilter ? $this->optionalFilterCheck->violations($cypher) : [];

                    if ($violations === [] && $ambiguous === [] && $ineffective === []) {
                        return $this->llmResult($cypher, $question, $context, $explain, $validation, $retryCount, [], $schemaRepairs, $runMeta);
                    }

                    // Keep the least-violating draft (latest on a tie) as the fallback.
                    if ($softCandidate === null
                        || count($violations) + count($ambiguous) + count($ineffective)
                            <= count($softCandidate['violations']) + count($softCandidate['ambiguous']) + count($softCandidate['ineffective'])) {
                        $softCandidate = [
                            'cypher' => $cypher,
                            'validation' => $validation,
                            'violations' => $violations,
                            'ambiguous' => $ambiguous,
                            'ineffective' => $ineffective,
                        ];
                    }

                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        break;
                    }

                    $schemaRepairs++;
                    $lastError = $this->draftRepairFeedback($violations, $ambiguous, $ineffective);
                    $previousDraft = $cypher;
                    if ($ambiguous !== []) {
                        // What was sent back, for the trace (the predicates, not the instruction).
                        $runMeta['precedence_repaired'] = array_values(array_unique(array_merge(
                            $runMeta['precedence_repaired'],
                            $this->precedenceCheck->ambiguousPredicates($cypher)
                        )));
                    }
                    if ($ineffective !== []) {
                        $runMeta['optional_filter_repaired'] = array_values(array_unique(array_merge(
                            $runMeta['optional_filter_repaired'],
                            $this->optionalFilterCheck->ineffectiveConditions($cypher)
                        )));
                    }
                    $this->logger->warning('query_generator', "Draft violates the graph schema or is ambiguous — regenerating (attempt {$retryCount}/{$maxRetries})", [
                        'retry_number' => $retryCount,
                        'violations' => $violations,
                        'precedence_violations' => $ambiguous,
                        'optional_filter_violations' => $ineffective,
                    ]);
                    continue;
                }

                // Validation failed, prepare for retry
                $lastError = implode(', ', $validation['errors']);
                $previousDraft = $cypher;
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

        // Budget spent on schema / precedence repairs: proceed with the best draft,
        // recording its violations for trace / grading. Not blocked — the security
        // layer still scopes and guards execution.
        if ($softCandidate !== null) {
            $this->logger->warning('query_generator', 'Returning a draft that still violates the graph schema or is ambiguous', [
                'violations' => $softCandidate['violations'],
                'precedence_violations' => $softCandidate['ambiguous'],
                'optional_filter_violations' => $softCandidate['ineffective'],
                'repair_attempts' => $schemaRepairs,
            ]);

            return $this->llmResult(
                $softCandidate['cypher'],
                $question,
                $context,
                $explain,
                $softCandidate['validation'],
                $retryCount,
                $softCandidate['violations'],
                $schemaRepairs,
                $runMeta,
                $softCandidate['ambiguous'],
                $softCandidate['ineffective']
            );
        }

        // All retries exhausted
        $durationMs = $this->logger->stopTimer('query_generation');
        $this->logger->error('query_generator', "Failed to generate valid query after {$maxRetries} attempts", [
            'duration_ms' => $durationMs,
            'last_error' => $lastError,
            'max_retries' => $maxRetries,
        ]);

        // Every attempt's verbatim prompt already reached the turn observer
        // (promptBuilt above), so a failed generation stays inspectable.
        throw new QueryGenerationException(
            "Failed to generate valid query after {$maxRetries} attempts. Last error: {$lastError}"
        );
    }

    /**
     * The accepted LLM draft as a result DTO.
     *
     * @param list<string> $violations schema violations the draft still has
     * @param list<string> $ambiguous precedence violations the draft still has
     * @param list<string> $ineffective optional-filter violations the draft still has
     */
    private function llmResult(
        string $cypher,
        string $question,
        PipelineContext $context,
        bool $explain,
        array $validation,
        int $retryCount,
        array $violations,
        int $schemaRepairs,
        array $runMeta,
        array $ambiguous = [],
        array $ineffective = []
    ): QueryGenerationResult {
        $confidence = $this->calculateConfidence($cypher, $context);
        $durationMs = $this->logger->stopTimer('query_generation');
        $this->logger->info('query_generator', 'Query generated via LLM', [
            'duration_ms' => $durationMs,
            'source' => 'llm',
            'confidence' => $confidence,
            'retry_count' => $retryCount,
            'complexity' => $validation['complexity'],
            'schema_violations' => count($violations),
            'precedence_violations' => count($ambiguous),
            'optional_filter_violations' => count($ineffective),
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
                'schema_violations' => $violations,
                'precedence_violations' => $ambiguous,
                'optional_filter_violations' => $ineffective,
                'repair_attempts' => $schemaRepairs,
            ] + $runMeta,
            parameters: $this->bindPiiTokens($cypher, $context)[1],
        );
    }

    /**
     * Rebind PII tokens as `$pii_…` parameters (idempotent: an already-bound draft
     * just resolves its parameters from this turn's map / the previous turn's).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function bindPiiTokens(string $cypher, PipelineContext $context): array
    {
        $previous = $context->conversationContext['last_cypher_parameters'] ?? [];

        return PiiMasker::for($context)->bindCypher($cypher, is_array($previous) ? $previous : []);
    }

    /** @return list<string> the labels of the domain calculations matched to a data question, as the prompt lists them */
    private function matchedFormulaLabels(PipelineContext $context): array
    {
        $matched = $context->selectionInfo['calculations']['matched'] ?? null;
        if (!isset($context->qu) || $context->qu->questionType !== 'data_query' || !is_array($matched)) {
            return [];
        }

        $labels = [];
        foreach (array_keys($matched) as $key) {
            $label = config("ai.calculations.{$key}.label");
            if (is_string($label) && $label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * One structured generation call (Task 5.6): chatJson with the
     * {"needs_query", "cypher", "reason"} contract. A reply the provider could not
     * decode as JSON is read ONCE as free text (the pre-5.6 extraction) instead of
     * re-requesting; a decoded reply that is not the contract object throws so the
     * retry loop redrafts with a teachable error.
     *
     * @return array{needs_query: bool, cypher: string, reason: string, format: string, reuse_previous: bool}
     */
    private function requestDraft(string $systemPrompt, string $userPrompt, float $temperature, PipelineContext $context): array
    {
        $messages = TokenUsage::completeMessages($userPrompt, $systemPrompt !== '' ? $systemPrompt : null);
        $observer = PipelineObserverScope::for($context);
        $observer->promptBuilt('query_generation', $messages);

        try {
            $decoded = $this->llm->chatJson($messages, [
                'temperature' => $temperature,
                'max_tokens' => $this->maxOutputTokens(),
            ]);
        } catch (LlmInvalidJsonException $e) {
            // A reply CUT at the output budget holds a query fragment: never run it
            // as free text — redraft with a teachable error (same retry budget).
            if ($e->truncated) {
                $this->logger->warning('query_generator', 'Generation reply was cut at the output budget', [
                    'reply_chars' => mb_strlen($e->rawText),
                    'max_tokens' => $this->maxOutputTokens(),
                ]);

                throw new QueryGenerationException(
                    'The reply was cut at the output budget — return a shorter query (fewer RETURN columns, no redundant clauses) in a complete JSON object.'
                );
            }

            $this->logger->warning('query_generator', 'Generation reply was not valid JSON — falling back to free-text extraction', [
                'reply_chars' => mb_strlen($e->rawText),
            ]);

            $cypher = $this->extractCypher($e->rawText);
            if ($cypher === '') {
                $this->logger->info('query_generator', 'Empty free-text generation reply read as needs_query=false (no query)');
            }

            return ['needs_query' => $cypher !== '', 'cypher' => $cypher, 'reason' => '', 'format' => 'text', 'reuse_previous' => false];
        } finally {
            TokenUsage::report($observer, 'query_generation', $this->llm);
        }

        $reply = json_decode((string) json_encode($decoded), true);
        if (!is_array($reply) || array_is_list($reply)) {
            throw new QueryGenerationException(
                'Reply with ONE JSON object {"needs_query": true|false, "cypher": "<query>"|null, "reason": "<short reason>"}.'
            );
        }

        $cypher = is_string($reply['cypher'] ?? null) ? $this->extractCypher($reply['cypher']) : '';
        $needsQuery = array_key_exists('needs_query', $reply)
            ? filter_var($reply['needs_query'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ($cypher !== '')
            : $cypher !== '';
        $reason = is_string($reply['reason'] ?? null) ? trim($reply['reason']) : '';

        if ($needsQuery && $cypher === '') {
            throw new QueryGenerationException(
                'needs_query was true but "cypher" was empty — return the Cypher query in "cypher", or set needs_query to false.'
            );
        }

        return ['needs_query' => $needsQuery, 'cypher' => $cypher, 'reason' => $reason, 'format' => 'json', 'reuse_previous' => !$needsQuery && ($reply['reuse_previous'] ?? false) === true];
    }

    /**
     * Retry feedback for a draft that uses what the graph does not have.
     *
     * @param list<string> $violations
     */
    private function schemaRepairFeedback(array $violations): string
    {
        return "the query does not match the live graph schema:\n- " . implode("\n- ", $violations)
            . "\nUse only the labels, relationship directions and properties that exist.";
    }

    /**
     * Retry feedback for a draft that is valid Cypher but should be redrafted: what
     * it uses that the graph does not have, a predicate whose OR / AND precedence is
     * ambiguous, and / or a condition an OPTIONAL MATCH cannot apply (each violation
     * carries its own instruction).
     *
     * @param list<string> $violations schema violations
     * @param list<string> $ambiguous precedence violations
     * @param list<string> $ineffective optional-filter violations
     */
    private function draftRepairFeedback(array $violations, array $ambiguous, array $ineffective = []): string
    {
        $parts = [];
        if ($violations !== []) {
            $parts[] = $this->schemaRepairFeedback($violations);
        }
        if ($ambiguous !== []) {
            $parts[] = "the query's boolean logic is ambiguous as written:\n- " . implode("\n- ", $ambiguous)
                . "\nAdd the parentheses and change nothing else in that predicate.";
        }
        if ($ineffective !== []) {
            $parts[] = "the query's total does not apply its condition as written:\n- " . implode("\n- ", $ineffective);
        }

        return implode("\n", $parts);
    }

    /**
     * Retry feedback for an execution-repair request: the Neo4j error of the
     * generator's own draft (never the tenant-scoped rewrite).
     */
    private function executionRepairFeedback(array $repair): string
    {
        $error = trim((string) ($repair['error'] ?? ''));
        $feedback = 'Neo4j rejected the query when executing it: ' . ($error !== '' ? $error : 'unknown error');

        // Eval p12-1001-A: "Variable `x` not defined" alone let the model keep the
        // out-of-scope use — the hint names the clause that dropped it (structural,
        // from the error text + the draft).
        $hint = CypherScopeHint::forError($error, trim((string) ($repair['cypher'] ?? '')));

        return $hint !== null ? $feedback . "\n" . $hint : $feedback;
    }

    /**
     * Whether generated drafts are checked against the live graph schema
     * (config ai.query_generation.schema_validation, default on).
     */
    private function schemaValidationEnabled(array $options): bool
    {
        if (array_key_exists('schema_validation', $options)) {
            return (bool) $options['schema_validation'];
        }

        return (bool) config('ai.query_generation.schema_validation', $this->config['schema_validation'] ?? true);
    }

    /**
     * Whether generated drafts are checked for a predicate that mixes OR / XOR with
     * AND at one nesting level (config ai.query_generation.precedence_check, default on).
     */
    private function precedenceCheckEnabled(array $options): bool
    {
        if (array_key_exists('precedence_check', $options)) {
            return (bool) $options['precedence_check'];
        }

        return (bool) config('ai.query_generation.precedence_check', $this->config['precedence_check'] ?? true);
    }

    /**
     * Whether generated drafts are checked for a text condition on an OPTIONAL MATCH the
     * total does not use (config ai.query_generation.optional_filter_check, default on).
     */
    private function optionalFilterCheckEnabled(array $options): bool
    {
        if (array_key_exists('optional_filter_check', $options)) {
            return (bool) $options['optional_filter_check'];
        }

        return (bool) config('ai.query_generation.optional_filter_check', $this->config['optional_filter_check'] ?? true);
    }

    /**
     * Output budget of one generation call: the JSON envelope adds ~40 tokens to
     * the Cypher itself (config ai.query_generation.max_tokens, default 800).
     */
    private function maxOutputTokens(): int
    {
        return max(1, (int) ($this->config['max_tokens'] ?? config('ai.query_generation.max_tokens', 800)));
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
     * Build the LLM prompt split by role (system / user).
     *
     * @param string $question User question
     * @param PipelineContext $context Pipeline context
     * @param bool $allowWrite Allow write operations
     * @param string|null $previousError Previous error for retry
     * @param string|null $previousDraft The generator's own previous draft (shown back on retry)
     * @return array{system: string, user: string}
     */
    private function buildPrompts(string $question, PipelineContext $context, bool $allowWrite, ?string $previousError, ?string $previousDraft = null): array
    {
        // Always use SemanticPromptBuilder for consistent, high-quality prompts
        // It handles all context types including project metadata, entity metadata, scopes, etc.
        if (!$this->promptBuilder) {
            throw new \RuntimeException('SemanticPromptBuilder not initialized');
        }

        $prompts = $this->promptBuilder->buildPrompts($question, $context, $allowWrite);

        // Add retry context to the user message if needed (it concerns this attempt's data).
        if ($previousError) {
            if ($previousDraft !== null && $previousDraft !== '') {
                $prompts['user'] .= "\n\nYour previous query was:\n{$previousDraft}\n";
            }
            $prompts['user'] .= "\n\nPrevious attempt failed with error: {$previousError}\n";
            $prompts['user'] .= "Fix it and return the corrected query in the JSON object.\n\n";
            $prompts['user'] .= "JSON RESPONSE:";
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
            $explanation = $this->llm->complete($prompt, null, ['temperature' => 0.3, 'max_tokens' => 150]);
            TokenUsage::report(PipelineObserverScope::current(), 'query_generation', $this->llm);

            return $explanation;
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
