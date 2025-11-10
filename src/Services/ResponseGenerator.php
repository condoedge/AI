<?php

declare(strict_types=1);

namespace AiSystem\Services;

use AiSystem\Contracts\ResponseGeneratorInterface;
use AiSystem\Contracts\LlmProviderInterface;

/**
 * Response Generator Service
 *
 * Transforms raw query results into natural language explanations
 * using LLM to make data accessible to non-technical users.
 *
 * @package AiSystem\Services
 */
class ResponseGenerator implements ResponseGeneratorInterface
{
    /**
     * Response style prompts
     */
    private array $stylePrompts = [
        'concise' => 'Be brief and to the point. 1-2 sentences maximum.',
        'detailed' => 'Provide comprehensive explanation with context and examples.',
        'technical' => 'Include technical details and reference the Cypher query.',
    ];

    /**
     * Constructor
     *
     * @param LlmProviderInterface $llm LLM provider for response generation
     * @param array $config Configuration options
     */
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly array $config = []
    ) {
    }

    /**
     * Generate natural language response from query results
     *
     * @param string $originalQuestion User's original question
     * @param array $queryResult Results from QueryExecutor
     * @param string $cypherQuery The Cypher query that was executed
     * @param array $options Generation options
     * @return array Response with answer, insights, visualizations
     * @throws \RuntimeException If response generation fails
     */
    public function generate(
        string $originalQuestion,
        array $queryResult,
        string $cypherQuery,
        array $options = []
    ): array {
        // Merge options with defaults
        $format = $options['format'] ?? $this->config['default_format'] ?? 'text';
        $style = $options['style'] ?? $this->config['default_style'] ?? 'detailed';
        $includeInsights = $options['include_insights'] ?? true;
        $includeVisualization = $options['include_visualization'] ?? true;
        $maxLength = $options['max_length'] ?? 200;
        $temperature = $options['temperature'] ?? 0.3;

        // Handle empty results
        if (empty($queryResult['data'])) {
            return $this->generateEmptyResponse($originalQuestion, $cypherQuery, $options);
        }

        // Prepare data
        $data = $queryResult['data'];
        $stats = $queryResult['stats'] ?? [];

        // Summarize if too large
        if (count($data) > 10) {
            $summarizedData = $this->summarize($data, 10);
        } else {
            $summarizedData = $data;
        }

        // Build prompt
        $prompt = $this->buildPrompt(
            $originalQuestion,
            $cypherQuery,
            $summarizedData,
            $stats,
            $style,
            $format,
            $maxLength
        );

        try {
            // Generate response
            $answer = $this->llm->complete($prompt, null, [
                'temperature' => $temperature,
                'max_tokens' => $this->calculateMaxTokens($maxLength),
            ]);

            // Extract insights
            $insights = $includeInsights ? $this->extractInsights($data) : [];

            // Suggest visualizations
            $visualizations = $includeVisualization
                ? $this->suggestVisualizations($data, $cypherQuery)
                : [];

            return [
                'answer' => trim($answer),
                'insights' => $insights,
                'visualizations' => $visualizations,
                'format' => $format,
                'metadata' => [
                    'style' => $style,
                    'result_count' => count($data),
                    'summarized' => count($data) > 10,
                ],
            ];

        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Response generation failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

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
    ): array {
        $format = $options['format'] ?? 'text';
        $temperature = $options['temperature'] ?? 0.3;

        $prompt = "The user asked: \"{$originalQuestion}\"\n\n";
        $prompt .= "We executed this query:\n{$cypherQuery}\n\n";
        $prompt .= "The query returned no results.\n\n";
        $prompt .= "Please explain in a friendly way why there might be no results. ";
        $prompt .= "Suggest what the user could try instead or how to rephrase their question.";

        try {
            $answer = $this->llm->complete($prompt, null, [
                'temperature' => $temperature,
                'max_tokens' => 200,
            ]);

            return [
                'answer' => trim($answer),
                'insights' => ['No results found'],
                'visualizations' => [],
                'format' => $format,
                'metadata' => [
                    'empty_result' => true,
                    'result_count' => 0,
                ],
            ];

        } catch (\Exception $e) {
            // Fallback response
            return [
                'answer' => "No results were found for your question: \"{$originalQuestion}\". You might want to try rephrasing or checking if the data you're looking for exists.",
                'insights' => ['No results found'],
                'visualizations' => [],
                'format' => $format,
                'metadata' => [
                    'empty_result' => true,
                    'result_count' => 0,
                    'fallback' => true,
                ],
            ];
        }
    }

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
    ): array {
        $format = $options['format'] ?? 'text';
        $includeDetails = $options['include_details'] ?? false;

        // User-friendly error message
        $answer = "I encountered an issue while trying to answer your question: \"{$originalQuestion}\". ";

        // Add specific guidance based on error type
        if (str_contains($error->getMessage(), 'timeout')) {
            $answer .= "The query took too long to execute. Try asking a more specific question or limiting the scope.";
        } elseif (str_contains($error->getMessage(), 'syntax')) {
            $answer .= "There was an issue with the generated query. Please try rephrasing your question.";
        } else {
            $answer .= "Please try rephrasing your question or contact support if the issue persists.";
        }

        $metadata = [
            'error' => true,
            'error_type' => get_class($error),
        ];

        if ($includeDetails) {
            $metadata['error_message'] = $error->getMessage();
        }

        return [
            'answer' => $answer,
            'insights' => ['Error occurred during query execution'],
            'visualizations' => [],
            'format' => $format,
            'metadata' => $metadata,
        ];
    }

    /**
     * Summarize large result sets
     *
     * @param array $queryResult Large result set to summarize
     * @param int $maxItems Max items to include in summary
     * @return array Summarized results
     */
    public function summarize(array $queryResult, int $maxItems = 10): array
    {
        if (count($queryResult) <= $maxItems) {
            return $queryResult;
        }

        // Take first N items
        return array_slice($queryResult, 0, $maxItems);
    }

    /**
     * Extract insights from data
     *
     * @param array $queryResult Query results to analyze
     * @return array Array of insights (patterns, outliers, trends)
     */
    public function extractInsights(array $queryResult): array
    {
        $insights = [];

        // Basic count insight
        $count = count($queryResult);
        $insights[] = "Found {$count} result" . ($count !== 1 ? 's' : '');

        // Detect if numeric data
        if ($this->isNumericData($queryResult)) {
            $stats = $this->calculateStatistics($queryResult);

            if ($stats) {
                $insights[] = "Average value: " . round($stats['avg'], 2);

                if ($stats['max'] > $stats['avg'] * 2) {
                    $insights[] = "Contains some notably high values";
                }

                if ($stats['min'] < $stats['avg'] * 0.5 && $stats['min'] > 0) {
                    $insights[] = "Contains some notably low values";
                }
            }
        }

        // Detect patterns in keys
        if (!empty($queryResult)) {
            $firstItem = $queryResult[0];
            $keys = array_keys($firstItem);

            if (count($keys) > 1) {
                $insights[] = "Results contain " . count($keys) . " properties: " . implode(', ', $keys);
            }
        }

        return $insights;
    }

    /**
     * Suggest appropriate visualizations
     *
     * @param array $queryResult Query results
     * @param string $cypherQuery Original query
     * @return array Suggested visualization types with rationale
     */
    public function suggestVisualizations(array $queryResult, string $cypherQuery): array
    {
        $suggestions = [];

        if (empty($queryResult)) {
            return $suggestions;
        }

        $count = count($queryResult);
        $firstItem = $queryResult[0];

        // Detect count queries
        if (isset($firstItem['count']) || stripos($cypherQuery, 'count(') !== false) {
            $suggestions[] = [
                'type' => 'number',
                'rationale' => 'Query returns a count value, best displayed as a number or KPI card',
            ];
        }

        // Detect relationship queries
        if (stripos($cypherQuery, 'MATCH') !== false && stripos($cypherQuery, '-[') !== false) {
            $suggestions[] = [
                'type' => 'graph',
                'rationale' => 'Query involves relationships, suitable for graph visualization',
            ];
        }

        // Detect list/table data
        if ($count > 1 && count(array_keys($firstItem)) > 2) {
            $suggestions[] = [
                'type' => 'table',
                'rationale' => 'Multiple results with several properties, suitable for table display',
            ];
        }

        // Detect grouping/aggregation
        if (stripos($cypherQuery, 'GROUP BY') !== false ||
            stripos($cypherQuery, 'count(') !== false && $count > 1) {
            $suggestions[] = [
                'type' => 'bar-chart',
                'rationale' => 'Aggregated data, suitable for bar or column chart',
            ];
        }

        // Detect time series
        if ($this->hasTimeComponent($queryResult)) {
            $suggestions[] = [
                'type' => 'line-chart',
                'rationale' => 'Data contains time component, suitable for line chart',
            ];
        }

        // Default fallback
        if (empty($suggestions)) {
            $suggestions[] = [
                'type' => 'table',
                'rationale' => 'General purpose table display for structured data',
            ];
        }

        return $suggestions;
    }

    /**
     * Build prompt for response generation
     *
     * @param string $question Original question
     * @param string $cypher Cypher query
     * @param array $data Query results
     * @param array $stats Execution statistics
     * @param string $style Response style
     * @param string $format Output format
     * @param int $maxLength Max response length
     * @return string Prompt for LLM
     */
    private function buildPrompt(
        string $question,
        string $cypher,
        array $data,
        array $stats,
        string $style,
        string $format,
        int $maxLength
    ): string {
        $prompt = "You are a data analyst who explains query results clearly and accurately.\n\n";

        $prompt .= "Original Question: {$question}\n\n";

        $prompt .= "Query Executed:\n{$cypher}\n\n";

        $prompt .= "Results:\n" . json_encode($data, JSON_PRETTY_PRINT) . "\n\n";

        if (!empty($stats)) {
            $prompt .= "Statistics:\n";
            $prompt .= "- Execution time: " . ($stats['execution_time_ms'] ?? 'N/A') . "ms\n";
            $prompt .= "- Rows returned: " . ($stats['rows_returned'] ?? count($data)) . "\n\n";
        }

        $prompt .= "Task: Explain these results in natural language.\n\n";

        $prompt .= "Guidelines:\n";
        $prompt .= "- Start with a direct answer to the question\n";
        $prompt .= "- Use specific numbers and facts from the data\n";
        $prompt .= "- " . ($this->stylePrompts[$style] ?? $this->stylePrompts['detailed']) . "\n";
        $prompt .= "- Keep response under {$maxLength} words\n";

        if ($format === 'markdown') {
            $prompt .= "- Format response in Markdown\n";
        } elseif ($format === 'json') {
            $prompt .= "- Structure response as JSON with 'summary' and 'details' keys\n";
        }

        $prompt .= "\nGenerate response:";

        return $prompt;
    }

    /**
     * Check if data contains numeric values
     *
     * @param array $data Query results
     * @return bool True if data contains numeric values
     */
    private function isNumericData(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        foreach ($data[0] as $value) {
            if (is_numeric($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate statistics for numeric data
     *
     * @param array $data Query results
     * @return array|null Statistics (avg, min, max, sum) or null
     */
    private function calculateStatistics(array $data): ?array
    {
        $numericValues = [];

        foreach ($data as $row) {
            foreach ($row as $value) {
                if (is_numeric($value)) {
                    $numericValues[] = (float) $value;
                }
            }
        }

        if (empty($numericValues)) {
            return null;
        }

        return [
            'count' => count($numericValues),
            'sum' => array_sum($numericValues),
            'avg' => array_sum($numericValues) / count($numericValues),
            'min' => min($numericValues),
            'max' => max($numericValues),
        ];
    }

    /**
     * Check if data has time component
     *
     * @param array $data Query results
     * @return bool True if data contains time/date fields
     */
    private function hasTimeComponent(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $firstItem = $data[0];

        foreach (array_keys($firstItem) as $key) {
            $keyLower = strtolower($key);
            if (in_array($keyLower, ['date', 'time', 'timestamp', 'created_at', 'updated_at', 'datetime'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate max tokens based on word limit
     *
     * @param int $maxWords Max words in response
     * @return int Max tokens
     */
    private function calculateMaxTokens(int $maxWords): int
    {
        // Rough estimate: 1 token ≈ 0.75 words
        return (int) ceil($maxWords / 0.75);
    }
}
