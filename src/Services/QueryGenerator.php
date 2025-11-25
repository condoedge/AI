<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Exceptions\QueryValidationException;
use Condoedge\Ai\Exceptions\UnsafeQueryException;

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
            'pattern' => '/^(show|list|get|display|find)\s+all\s+(\w+)/i',
            'cypher' => 'MATCH (n:{label}) RETURN n LIMIT 100',
            'description' => 'List all entities of a type',
            'example_question' => 'Show all customers',
            'example_cypher' => 'MATCH (n:Customer) RETURN n LIMIT 100'
        ],
        'count' => [
            'pattern' => '/^(how many|count|number of)\s+(\w+)/i',
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
     * Constructor
     *
     * @param LlmProviderInterface $llm LLM provider for query generation
     * @param GraphStoreInterface $graphStore Graph store for schema access
     * @param array $config Configuration options
     * @param SemanticPromptBuilder|null $promptBuilder Optional semantic prompt builder
     */
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly GraphStoreInterface $graphStore,
        private readonly array $config = [],
        ?SemanticPromptBuilder $promptBuilder = null
    ) {
        // Initialize semantic prompt builder if not provided
        $this->promptBuilder = $promptBuilder ?? new SemanticPromptBuilder(
            new PatternLibrary()
        );
    }

    /**
     * Generate a Cypher query from natural language question
     *
     * @param string $question Natural language question
     * @param array $context RAG context (similar_queries, graph_schema, relevant_entities)
     * @param array $options Optional parameters
     * @return array Result with cypher, explanation, confidence, warnings, metadata
     * @throws QueryGenerationException If generation fails after retries
     */
    public function generate(string $question, array $context, array $options = []): array
    {
        // Merge options with defaults
        $temperature = $options['temperature'] ?? $this->config['temperature'] ?? 0.1;
        $maxRetries = $options['max_retries'] ?? $this->config['max_retries'] ?? 3;
        $allowWrite = $options['allow_write'] ?? $this->config['allow_write_operations'] ?? false;
        $explain = $options['explain'] ?? false;

        // Check if templates are enabled
        if ($this->config['enable_templates'] ?? true) {
            $template = $this->detectTemplate($question);
            if ($template) {
                return $this->generateFromTemplate($question, $template, $context);
            }
        }

        // Try LLM generation with retries
        $retryCount = 0;
        $lastError = null;

        while ($retryCount < $maxRetries) {
            try {
                // Build prompt
                $prompt = $this->buildPrompt($question, $context, $allowWrite, $lastError);

                // Call LLM
                $response = $this->llm->complete($prompt, null, [
                    'temperature' => $temperature,
                    'max_tokens' => 500,
                ]);

                // Extract and clean Cypher
                $cypher = $this->extractCypher($response);

                // Validate
                $validation = $this->validate($cypher, ['allow_write' => $allowWrite]);

                if ($validation['valid']) {
                    // Success!
                    return [
                        'cypher' => $cypher,
                        'explanation' => $explain ? $this->generateExplanation($cypher, $question) : '',
                        'confidence' => $this->calculateConfidence($cypher, $context),
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

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $retryCount++;
            }
        }

        // All retries exhausted
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
    public function validate(string $cypherQuery, array $options = []): array
    {
        if (empty(trim($cypherQuery))) {
            throw new \InvalidArgumentException('Query cannot be empty');
        }

        $allowWrite = $options['allow_write'] ?? false;
        $maxComplexity = $options['max_complexity'] ?? $this->config['max_complexity'] ?? 100;

        $errors = [];
        $warnings = [];

        // Check for dangerous operations
        foreach ($this->dangerousKeywords as $keyword) {
            if (stripos($cypherQuery, $keyword) !== false && !$allowWrite) {
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

        // Determine if read-only
        $isReadOnly = true;
        foreach ($this->dangerousKeywords as $keyword) {
            if (stripos($cypherQuery, $keyword) !== false) {
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
    public function sanitize(string $cypherQuery): string
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
     * @param string $question Original question
     * @param string $templateName Template name
     * @param array $context RAG context
     * @return array Generation result
     */
    private function generateFromTemplate(string $question, string $templateName, array $context): array
    {
        $template = $this->templates[$templateName];

        // Extract parameters from question
        preg_match($template['pattern'], $question, $matches);

        // Get label from schema
        $schema = $context['graph_schema'] ?? [];
        $label = $this->inferLabel($matches, $schema);

        // Generate query from template
        $cypher = str_replace('{label}', $label, $template['cypher']);

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
     * Build prompt for LLM
     *
     * @param string $question User question
     * @param array $context RAG context
     * @param bool $allowWrite Allow write operations
     * @param string|null $previousError Previous error for retry
     * @return string Prompt
     */
    private function buildPrompt(string $question, array $context, bool $allowWrite, ?string $previousError): string
    {
        // Always use SemanticPromptBuilder for consistent, high-quality prompts
        // It handles all context types including project metadata, entity metadata, scopes, etc.
        if (!$this->promptBuilder) {
            throw new \RuntimeException('SemanticPromptBuilder not initialized');
        }

        $prompt = $this->promptBuilder->buildPrompt($question, $context, $allowWrite);

        // Add retry context if needed
        if ($previousError) {
            $prompt .= "\n\nPrevious attempt failed with error: {$previousError}\n";
            $prompt .= "Please fix the error and regenerate the query.\n\n";
            $prompt .= "CYPHER QUERY:";
        }

        return $prompt;
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
            return "Query generated for: {$question}";
        }
    }

    /**
     * Calculate confidence score
     *
     * @param string $cypher Generated query
     * @param array $context RAG context
     * @return float Confidence score (0-1)
     */
    private function calculateConfidence(string $cypher, array $context): float
    {
        $confidence = 0.5; // Base confidence

        // Boost if schema labels are referenced
        if (!empty($context['graph_schema']['labels'])) {
            foreach ($context['graph_schema']['labels'] as $label) {
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

    /**
     * Check if context contains semantic scopes (new format)
     *
     * Semantic scopes have specification_type field indicating they use
     * the new declarative format with relationship_spec, pattern, etc.
     *
     * @param array $context Context array
     * @return bool True if semantic scopes detected
     */
    private function hasSemanticScopes(array $context): bool
    {
        // Check if we have detected scopes
        if (empty($context['entity_metadata']['detected_scopes'])) {
            return false;
        }

        // Check if any scope has specification_type (indicates new format)
        foreach ($context['entity_metadata']['detected_scopes'] as $scope) {
            if (isset($scope['specification_type'])) {
                return true;
            }
        }

        return false;
    }
}
