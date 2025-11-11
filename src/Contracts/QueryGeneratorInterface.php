<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Query Generator Interface
 *
 * Transforms natural language questions into Cypher queries using LLM
 * and context from RAG (graph schema, similar queries, examples).
 */
interface QueryGeneratorInterface
{
    /**
     * Generate a Cypher query from natural language question
     *
     * @param string $question Natural language question
     * @param array $context RAG context with keys:
     *                       - similar_queries: Array of similar past queries
     *                       - graph_schema: Graph structure (labels, relationships, properties)
     *                       - relevant_entities: Example entities for reference
     * @param array $options Optional parameters:
     *                       - temperature: LLM temperature (default: 0.1 for consistency)
     *                       - max_retries: Max retry attempts if validation fails (default: 3)
     *                       - allow_write: Allow write operations (default: false)
     *                       - explain: Include query explanation (default: true)
     * @return array Result with keys:
     *               - cypher: Generated Cypher query
     *               - explanation: Human-readable explanation of what query does
     *               - confidence: Confidence score (0-1)
     *               - warnings: Array of warnings about query safety/performance
     *               - metadata: Additional metadata (template used, retry count, etc.)
     * @throws \RuntimeException If query generation fails after retries
     */
    public function generate(string $question, array $context, array $options = []): array;

    /**
     * Validate a Cypher query for syntax and safety
     *
     * @param string $cypherQuery Query to validate
     * @param array $options Validation options:
     *                       - allow_write: Allow write operations (default: false)
     *                       - max_complexity: Max query complexity score (default: 100)
     * @return array Validation result with keys:
     *               - valid: Boolean indicating if query is valid
     *               - errors: Array of validation errors
     *               - warnings: Array of warnings (performance, security)
     *               - complexity: Query complexity score
     *               - is_read_only: Boolean indicating if query only reads data
     * @throws \InvalidArgumentException If query is empty
     */
    public function validate(string $cypherQuery, array $options = []): array;

    /**
     * Sanitize a Cypher query by removing dangerous operations
     *
     * @param string $cypherQuery Query to sanitize
     * @return string Sanitized query
     */
    public function sanitize(string $cypherQuery): string;

    /**
     * Get available query templates
     *
     * @return array Array of template metadata:
     *               - name: Template identifier
     *               - description: What the template does
     *               - pattern: Natural language pattern it matches
     *               - example_question: Example question
     *               - example_cypher: Example generated query
     */
    public function getTemplates(): array;

    /**
     * Detect which template (if any) matches the question
     *
     * @param string $question Natural language question
     * @return string|null Template name or null if no match
     */
    public function detectTemplate(string $question): ?string;
}
