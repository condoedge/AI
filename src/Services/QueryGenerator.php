<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Services\Resilience\RateLimiter;
use Condoedge\Ai\GraphStore\CypherSanitizer;
use Condoedge\Ai\Support\AiLogger;

/**
 * Query Generator Service
 *
 * Transforms natural language questions into safe, valid Cypher queries
 * using LLM and context from RAG (graph schema, similar queries, examples).
 *
 * @package Condoedge\Ai\Services
 */
class QueryGenerator implements QueryGeneratorInterface
{
    /**
     * Dangerous keywords that modify or delete data
     */
    private array $dangerousKeywords = [
        'DELETE', 'REMOVE', 'DROP', 'CREATE', 'MERGE', 'SET', 'DETACH'
    ];

    /**
     * Query templates for common patterns
     */
    private array $templates = [
        'list_all' => [
            'pattern' => '/^(show|list|get|display|find)\s+all\s+(\w+)\s*[?.!]?$/i',
            'cypher' => 'MATCH (n:{label}) RETURN n LIMIT 100',
            'description' => 'List all entities of a type',
            'example_question' => 'Show all customers',
            'example_cypher' => 'MATCH (n:Customer) RETURN n LIMIT 100'
        ],
        'count' => [
            'pattern' => '/^(how many|count|number of)\s+(\w+)\s*(are there|do we have|exist|total)?\s*[?.!]?$/i',
            'cypher' => 'MATCH (n:{label}) RETURN count(n) as count',
            'description' => 'Count entities of a type',
            'example_question' => 'How many orders?',
            'example_cypher' => 'MATCH (n:Order) RETURN count(n) as count'
        ],
        'find_by_property' => [
            'pattern' => '/^find\s+(\w+)\s+(with|where|having)\s+(\w+)\s*(=|is|equals)\s*(.+)/i',
            'cypher' => 'MATCH (n:{label} {{{property}: $value}}) RETURN n LIMIT 100',
            'description' => 'Find entities by property value',
            'example_question' => 'Find customers with email john@example.com',
            'example_cypher' => 'MATCH (n:Customer {{email: $value}}) RETURN n LIMIT 100'
        ],
        'relationship_query' => [
            'pattern' => '/^(show|find|get)\s+(\w+)\s+(connected to|related to|linked to)\s+(\w+)/i',
            'cypher' => 'MATCH (a:{label1})-[r]-(b:{label2}) RETURN a, r, b LIMIT 100',
            'description' => 'Find related entities',
            'example_question' => 'Show customers connected to orders',
            'example_cypher' => 'MATCH (a:Customer)-[r]-(b:Order) RETURN a, r, b LIMIT 100'
        ],
        'aggregation' => [
            'pattern' => '/^(sum|total|average|avg|max|min)\s+(.+)/i',
            'cypher' => 'MATCH (n:{label}) RETURN {aggregation}(n.{property}) as result',
            'description' => 'Aggregate property values',
            'example_question' => 'What is the average order total?',
            'example_cypher' => 'MATCH (n:Order) RETURN avg(n.total) as result'
        ],
        'filtering' => [
            'pattern' => '/^(\w+)\s+where\s+(.+)/i',
            'cypher' => 'MATCH (n:{label}) WHERE {condition} RETURN n LIMIT 100',
            'description' => 'Filter entities by condition',
            'example_question' => 'Customers where age > 30',
            'example_cypher' => 'MATCH (n:Customer) WHERE n.age > 30 RETURN n LIMIT 100'
        ],
    ];

    /**
     * Semantic prompt builder (optional - for semantic metadata support)
     */
    private ?SemanticPromptBuilder $promptBuilder = null;

    /**
     * Rate limiter for LLM API calls
     */
    private RateLimiter $rateLimiter;

    /**
     * Logger instance
     */
    private AiLogger $logger;

    /**
     * Constructor
     *
     * @param LlmProviderInterface $llm LLM provider for query generation
     * @param GraphStoreInterface $graphStore Graph store for schema access
     * @param array $config Configuration options
     * @param SemanticPromptBuilder|null $promptBuilder Optional semantic prompt builder
     * @param AiLogger|null $logger Optional logger instance
     */
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly GraphStoreInterface $graphStore,
        private readonly array $config = [],
        ?SemanticPromptBuilder $promptBuilder = null,
        ?AiLogger $logger = null
    ) {
        // Initialize semantic prompt builder if not provided
        $this->promptBuilder = $promptBuilder ?? new SemanticPromptBuilder(
            new PatternLibrary()
        );

        // Initialize rate limiter for LLM API calls
        $this->rateLimiter = RateLimiter::forLlm();

        // Initialize logger
        $this->logger = $logger ?? app(AiLogger::class);
    }

    /**
     * Generate a Cypher query from natural language question
     *
     * @param string $question Natural language question
     * @param PipelineContext $context Pipeline context with schema, metadata, and detected scopes
     * @param array $options Optional parameters
     * @return array Result with cypher, explanation, confidence, warnings, metadata
     * @throws QueryGenerationException If generation fails after retries
     */
    public function generate(string $question, PipelineContext $context, array $options = []): array
    {
        $this->logger->startTimer('query_generation');

        // Merge options with defaults
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

                $result = $this->generateFromTemplate($question, $template, $context);
                if ($result !== null) {
                    $durationMs = $this->logger->stopTimer('query_generation');
                    $this->logger->info('query_generator', 'Query generated via template', [
                        'duration_ms' => $durationMs,
                        'source' => 'template',
                        'template' => $template,
                        'confidence' => $result['confidence'],
                    ]);
                    return $result;
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

        while ($retryCount < $maxRetries) {
            try {
                // Build prompt, split into system (identity + static rules) and user.
                ['system' => $systemPrompt, 'user' => $userPrompt] =
                    $this->buildPrompts($question, $context, $allowWrite, $lastError);

                $this->logger->debug('query_generator', 'Sending prompt to LLM', [
                    'prompt_size_bytes' => strlen($systemPrompt) + strlen($userPrompt),
                    'attempt' => $retryCount + 1,
                ]);

                // Check rate limit before calling LLM
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
                    return [
                        'cypher' => '',
                        'explanation' => 'No query required to answer the question.',
                        'confidence' => 1.0,
                        'warnings' => [],
                        'metadata' => [
                            'template_used' => null,
                            'retry_count' => $retryCount,
                            'complexity' => 0,
                        ],
                    ];
                }

                // Extract and clean Cypher
                $cypher = $this->extractCypher($response);

                // Validate
                $validation = $this->validate($cypher, ['allow_write' => $allowWrite]);

                $this->logger->debug('query_generator', 'Validation result', [
                    'valid' => $validation['valid'],
                    'errors' => $validation['errors'] ?? [],
                    'warnings' => $validation['warnings'],
                    'complexity' => $validation['complexity'],
                ]);

                if ($validation['valid']) {
                    // Success!
                    $confidence = $this->calculateConfidence($cypher, $context);
                    $durationMs = $this->logger->stopTimer('query_generation');
                    $this->logger->info('query_generator', 'Query generated via LLM', [
                        'duration_ms' => $durationMs,
                        'source' => 'llm',
                        'confidence' => $confidence,
                        'retry_count' => $retryCount,
                        'complexity' => $validation['complexity'],
                    ]);
                    return [
                        'cypher' => $cypher,
                        'explanation' => $explain ? $this->generateExplanation($cypher, $question) : '',
                        'confidence' => $confidence,
                        'warnings' => $validation['warnings'],
                        'metadata' => [
                            'template_used' => null,
                            'retry_count' => $retryCount,
                            'complexity' => $validation['complexity'],
                        ],
                    ];
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

        throw new QueryGenerationException(
            "Failed to generate valid query after {$maxRetries} attempts. Last error: {$lastError}"
        );
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
        if (empty(trim($cypherQuery))) {
            throw new \InvalidArgumentException('Query cannot be empty');
        }

        $allowWrite = $options['allow_write'] ?? false;
        $maxComplexity = $options['max_complexity'] ?? $this->config['max_complexity'] ?? 100;

        $errors = [];
        $warnings = [];

        // Check for dangerous operations (word boundary match to avoid false positives like "deleted_at")
        foreach ($this->dangerousKeywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $cypherQuery) && !$allowWrite) {
                $errors[] = "Query contains forbidden keyword: {$keyword}";
            }
        }

        // Check for LIMIT clause
        if (!preg_match('/\bLIMIT\b/i', $cypherQuery)) {
            $warnings[] = "Query missing LIMIT clause - may return large result set";
        }

        // Basic syntax validation
        if (!preg_match('/\bMATCH\b/i', $cypherQuery) && !preg_match('/\bRETURN\b/i', $cypherQuery)) {
            $errors[] = "Query must contain MATCH or RETURN clause";
        }

        // Check complexity
        $complexity = $this->calculateComplexityScore($cypherQuery);
        if ($complexity > $maxComplexity) {
            $warnings[] = "Query complexity ({$complexity}) exceeds threshold ({$maxComplexity})";
        }

        // Determine if read-only (word boundary match to avoid false positives)
        $isReadOnly = true;
        foreach ($this->dangerousKeywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $cypherQuery)) {
                $isReadOnly = false;
                break;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'complexity' => $complexity,
            'is_read_only' => $isReadOnly,
        ];
    }

    /**
     * Sanitize a Cypher query by removing dangerous operations
     *
     * @param string $cypherQuery Query to sanitize
     * @return string Sanitized query
     */
    public function sanitize(?string $cypherQuery): string
    {
        // Remove dangerous keywords
        foreach ($this->dangerousKeywords as $keyword) {
            $cypherQuery = preg_replace('/\b' . $keyword . '\b[^;]*(;|$)/i', '', $cypherQuery);
        }

        // Add LIMIT if missing
        if (!preg_match('/\bLIMIT\b/i', $cypherQuery)) {
            $defaultLimit = $this->config['default_limit'] ?? 100;
            if (preg_match('/\bRETURN\b/i', $cypherQuery)) {
                $cypherQuery = preg_replace('/(\bRETURN\b.*)$/i', "$1 LIMIT {$defaultLimit}", $cypherQuery);
            }
        }

        return trim($cypherQuery);
    }

    /**
     * Check if a label exists in the graph schema.
     *
     * Used to validate labels extracted from user input before
     * using them in template-generated queries.
     *
     * @param string $label The label to validate
     * @param array $validLabels Array of valid labels from schema
     * @return bool True if the label is valid
     */
    public function isValidLabel(string $label, array $validLabels): bool
    {
        if (empty($validLabels)) {
            return false;
        }

        return in_array($label, $validLabels, true);
    }

    /**
     * Get available query templates
     *
     * @return array Array of template metadata
     */
    public function getTemplates(): array
    {
        return array_map(function ($name, $template) {
            return [
                'name' => $name,
                'description' => $template['description'],
                'pattern' => $template['pattern'],
                'example_question' => $template['example_question'] ?? '',
                'example_cypher' => $template['example_cypher'] ?? '',
            ];
        }, array_keys($this->templates), $this->templates);
    }

    /**
     * Detect which template (if any) matches the question
     *
     * @param string $question Natural language question
     * @return string|null Template name or null if no match
     */
    public function detectTemplate(string $question): ?string
    {
        foreach ($this->templates as $name => $template) {
            if (preg_match($template['pattern'], $question)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Generate query from template
     *
     * Security: Template queries bypass LLM, so labels extracted from user input
     * must be validated against schema and sanitized before interpolation.
     *
     * @param string $question Original question
     * @param string $templateName Template name
     * @param PipelineContext $context Pipeline context
     * @return array|null Generation result, or null to fall through to LLM
     * @throws \InvalidArgumentException If label is not in schema
     */
    private function generateFromTemplate(string $question, string $templateName, PipelineContext $context): ?array
    {
        $template = $this->templates[$templateName];

        // Extract parameters from question
        preg_match($template['pattern'], $question, $matches);

        // Get valid labels from both Neo4j schema and entity config
        $schema = $context->graphSchema;
        $validLabels = $schema['labels'] ?? [];

        // Also include labels from entity config properties (covers entities not yet ingested)
        $configLabels = array_keys($schema['properties'] ?? []);
        $validLabels = array_unique(array_merge($validLabels, $configLabels));

        // Also include labels from entity configs if available
        $entityConfigs = config('entities', []);
        foreach ($entityConfigs as $config) {
            $label = $config['graph']['label'] ?? null;
            if ($label && !in_array($label, $validLabels)) {
                $validLabels[] = $label;
            }
        }

        // Security: If no valid labels at all, fall through to LLM generation
        if (empty($validLabels)) {
            return null;
        }

        // Infer the label from user input
        $label = $this->inferLabel($matches, $schema);

        // Security: Validate the label exists in schema
        // This prevents injection of arbitrary labels from user input
        if (!$this->isValidLabel($label, $validLabels)) {
            throw new \InvalidArgumentException("Unknown label: {$label}");
        }

        // Security: Defense in depth - sanitize even after validation
        // This ensures no special characters can slip through
        $sanitizedLabel = CypherSanitizer::escapeLabel($label);

        // Generate query from template with sanitized label
        $cypher = str_replace('{label}', $sanitizedLabel, $template['cypher']);

        return [
            'cypher' => $cypher,
            'explanation' => "This query uses the '{$templateName}' template to {$template['description']}.",
            'confidence' => 0.9,
            'warnings' => [],
            'metadata' => [
                'template_used' => $templateName,
                'retry_count' => 0,
                'complexity' => 10,
            ],
        ];
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

    /**
     * Calculate complexity score
     *
     * @param string $cypher Query to analyze
     * @return int Complexity score
     */
    private function calculateComplexityScore(string $cypher): int
    {
        $complexity = 0;

        // Count MATCH clauses
        $complexity += substr_count(strtoupper($cypher), 'MATCH') * 10;

        // Count WHERE clauses
        $complexity += substr_count(strtoupper($cypher), 'WHERE') * 5;

        // Count joins
        $complexity += substr_count($cypher, ']-') * 8;

        // Count aggregations
        $complexity += preg_match_all('/\b(count|sum|avg|max|min)\b/i', $cypher) * 3;

        return $complexity;
    }

    /**
     * Infer label from matches and schema
     *
     * @param array $matches Regex matches
     * @param array $schema Graph schema
     * @return string Label name
     */
    private function inferLabel(array $matches, array $schema): string
    {
        // Get potential label from regex match
        $potentialLabel = $matches[2] ?? $matches[1] ?? 'Node';

        // Capitalize and singularize
        $label = ucfirst(rtrim(strtolower($potentialLabel), 's'));

        // Check if label exists in schema
        if (!empty($schema['labels']) && in_array($label, $schema['labels'])) {
            return $label;
        }

        // Return as-is if not in schema
        return $label;
    }
}
