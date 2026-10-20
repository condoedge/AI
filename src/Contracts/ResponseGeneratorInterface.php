<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * Response Generator Interface
 *
 * Transforms raw query results into natural language explanations
 * using LLM to make data accessible to non-technical users.
 */
interface ResponseGeneratorInterface
{
    /**
     * Generate natural language response from pipeline context
     *
     * @param PipelineContext $context Pipeline context containing question, query result, execution data, etc.
     * @param array $options Generation options:
     *                       - format: 'text', 'markdown', 'json' (default: 'text')
     *                       - style: 'concise', 'detailed', 'technical' (default: 'detailed')
     *                       - max_length: Max response length in words (default: 200)
     *                       - temperature: LLM temperature (default: 0.3)
     * @return array Response with keys:
     *               - answer: Natural language answer
     *               - format: Response format used
     *               - metadata: Additional metadata
     * @throws \RuntimeException If response generation fails
     */
    public function generate(
        PipelineContext $context,
        array $options = []
    ): array;

    /**
     * Generate response for empty results
     *
     * @param string $originalQuestion User's original question
     * @param string $cypherQuery The query that returned no results
     * @param array $options Generation options
     * @return array Response explaining why no results were found
     */
    public function generateEmptyResponse(
        string $originalQuestion,
        ?string $cypherQuery,
        array $options = []
    ): array;

    /**
     * Generate response for error cases
     *
     * @param string $originalQuestion User's original question
     * @param \Throwable $error The error that occurred
     * @param array $options Generation options
     * @return array User-friendly error response
     */
    public function generateErrorResponse(
        string $originalQuestion,
        \Throwable $error,
        array $options = []
    ): array;
}
