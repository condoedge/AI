<?php

declare(strict_types=1);

namespace AiSystem\Contracts;

/**
 * Response Generator Interface
 *
 * Transforms raw query results into natural language explanations
 * using LLM to make data accessible to non-technical users.
 */
interface ResponseGeneratorInterface
{
    /**
     * Generate natural language response from query results
     *
     * @param string $originalQuestion User's original question
     * @param array $queryResult Results from QueryExecutor
     * @param string $cypherQuery The Cypher query that was executed
     * @param array $options Generation options:
     *                       - format: 'text', 'markdown', 'json' (default: 'text')
     *                       - style: 'concise', 'detailed', 'technical' (default: 'detailed')
     *                       - include_insights: Include data insights (default: true)
     *                       - include_visualization: Suggest visualizations (default: true)
     *                       - max_length: Max response length in words (default: 200)
     *                       - temperature: LLM temperature (default: 0.3)
     * @return array Response with keys:
     *               - answer: Natural language answer
     *               - insights: Array of insights discovered in data
     *               - visualizations: Suggested visualization types
     *               - format: Response format used
     *               - metadata: Additional metadata
     * @throws \RuntimeException If response generation fails
     */
    public function generate(
        string $originalQuestion,
        array $queryResult,
        string $cypherQuery,
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
        string $cypherQuery,
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

    /**
     * Summarize large result sets
     *
     * @param array $queryResult Large result set to summarize
     * @param int $maxItems Max items to include in summary
     * @return array Summarized results
     */
    public function summarize(array $queryResult, int $maxItems = 10): array;

    /**
     * Extract insights from data
     *
     * @param array $queryResult Query results to analyze
     * @return array Array of insights (patterns, outliers, trends)
     */
    public function extractInsights(array $queryResult): array;

    /**
     * Suggest appropriate visualizations
     *
     * @param array $queryResult Query results
     * @param string $cypherQuery Original query
     * @return array Suggested visualization types with rationale
     */
    public function suggestVisualizations(array $queryResult, string $cypherQuery): array;
}
