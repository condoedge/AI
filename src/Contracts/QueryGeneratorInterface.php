<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;

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
     * @param PipelineContext $context Pipeline context with similar queries, graph schema, entities, etc.
     * @param array $options Optional parameters:
     *                       - temperature: LLM temperature (default: 0.1 for consistency)
     *                       - max_retries: Max retry attempts if validation fails (default: 3)
     *                       - allow_write: Allow write operations (default: false)
     *                       - explain: Include query explanation (default: true)
     * @return QueryGenerationResult Generated cypher, explanation, confidence, warnings and metadata
     * @throws \RuntimeException If query generation fails after retries
     */
    public function generate(string $question, PipelineContext $context, array $options = []): QueryGenerationResult;

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
    public function validate(?string $cypherQuery, array $options = []): array;
}
