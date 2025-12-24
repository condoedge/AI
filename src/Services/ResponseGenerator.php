<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\ResponseSectionInterface;
use Condoedge\Ai\Services\ResponseSections\SystemPromptSection;
use Condoedge\Ai\Services\ResponseSections\ResponseProjectContextSection;
use Condoedge\Ai\Services\ResponseSections\ResultsDataSection;
use Condoedge\Ai\Services\ResponseSections\GuidelinesSection;

/**
 * ResponseGenerator - Transforms Query Results to Natural Language
 *
 * Converts raw database query results into human-readable explanations using LLM.
 * Uses the HasInternalModules trait for extensible prompt composition.
 *
 * ## Architecture
 *
 * The generator uses a priority-based module pipeline where each section contributes
 * to the prompt sent to the LLM. Sections are processed in priority order (lower = first).
 *
 * Default sections (in priority order):
 * - system (10): System prompt setting the LLM's role
 * - project_context (20): Project name and description
 * - question (30): The user's original question
 * - query_info (40): The Cypher query that was executed
 * - data (50): The actual results data
 * - statistics (60): Statistics about the results
 * - guidelines (70): Guidelines for response formatting
 * - task (80): Final task instructions for the LLM
 *
 * ## Configuration
 *
 * Default sections are loaded from `config('ai.response_generator_sections')`.
 *
 * ```php
 * // config/ai.php
 * return [
 *     'response_generator_sections' => [
 *         \Condoedge\Ai\Services\ResponseSections\SystemPromptSection::class,
 *         \Condoedge\Ai\Services\ResponseSections\OriginalQuestionSection::class,
 *         // ... more sections
 *     ],
 * ];
 * ```
 *
 * ## Extension Methods
 *
 * ### Add a new section
 * ```php
 * $generator->addModule(new CustomInsightsSection());
 * ```
 *
 * ### Remove a section
 * ```php
 * $generator->removeModule('statistics');
 * ```
 *
 * ### Replace a section
 * ```php
 * $generator->replaceModule('guidelines', new MyGuidelinesSection());
 * ```
 *
 * ### Extend with callbacks
 * ```php
 * $generator->extendAfter('data', function($context, $options) {
 *     return "\n=== CUSTOM ANALYSIS ===\n\nYour analysis here\n\n";
 * });
 * ```
 *
 * ### Global extensions (applied to all instances)
 * ```php
 * ResponseGenerator::extendBuild(function($generator) {
 *     $generator->addModule(new GlobalCustomSection());
 * });
 * ```
 *
 * ## Usage Example
 *
 * ```php
 * $generator = new ResponseGenerator($llmProvider, $config);
 *
 * // Customize for your needs
 * $generator->setProjectContext([
 *     'name' => 'My CRM',
 *     'description' => 'Customer relationship management',
 * ]);
 * $generator->addGuideline('Always include percentage changes');
 *
 * // Generate response
 * $response = $generator->generate(
 *     originalQuestion: "How many customers do we have?",
 *     queryResult: ['data' => [...], 'stats' => [...]],
 *     cypherQuery: "MATCH (c:Customer) RETURN count(c) as count",
 *     options: ['format' => 'text', 'style' => 'detailed']
 * );
 *
 * // $response contains: answer, insights, visualizations, format, metadata
 * ```
 *
 * ## Response Structure
 *
 * The generate() method returns:
 * - `answer`: The natural language explanation
 * - `insights`: Array of automatically extracted insights
 * - `visualizations`: Suggested visualization types
 * - `format`: The output format used
 * - `metadata`: Additional info (style, result count, etc.)
 *
 * @uses HasInternalModules<ResponseSectionInterface> For module pipeline functionality
 *
 * @see HasInternalModules For module management methods
 * @see ResponseSectionInterface For creating custom sections
 * @see SemanticPromptBuilder For building query generation prompts
 */
class ResponseGenerator implements ResponseGeneratorInterface
{
    use HasInternalModules;

    protected $defaultModulesConfigKey = 'ai.response_generator_sections';

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
        $this->registerDefaultModules();
        $this->applyGlobalExtensions();
    }

    // =========================================================================
    // CONVENIENCE METHODS
    // =========================================================================

    /**
     * Set project context
     *
     * @param array $context Project context array
     * @return self
     */
    public function setProjectContext(array $context): self
    {
        $section = $this->getModule('project_context');
        if ($section instanceof ResponseProjectContextSection) {
            $section->setContext($context);
        }
        return $this;
    }

    /**
     * Set custom system prompt
     *
     * @param string $prompt Custom system prompt
     * @return self
     */
    public function setSystemPrompt(string $prompt): self
    {
        $section = $this->getModule('system');
        if ($section instanceof SystemPromptSection) {
            $section->setPrompt($prompt);
        }
        return $this;
    }

    /**
     * Add a custom guideline
     *
     * @param string $guideline Guideline text
     * @return self
     */
    public function addGuideline(string $guideline): self
    {
        $section = $this->getModule('guidelines');
        if ($section instanceof GuidelinesSection) {
            $section->addGuideline($guideline);
        }
        return $this;
    }

    /**
     * Set maximum data items to show
     *
     * @param int $max Maximum items
     * @return self
     */
    public function setMaxDataItems(int $max): self
    {
        $section = $this->getModule('data');
        if ($section instanceof ResultsDataSection) {
            $section->setMaxItems($max);
        }
        return $this;
    }

    // =========================================================================
    // CORE GENERATION METHODS
    // =========================================================================

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

        // Prepare context for sections
        $context = [
            'question' => $originalQuestion,
            'cypher' => $cypherQuery,
            'data' => $queryResult['data'],
            'stats' => $queryResult['stats'] ?? [],
        ];

        $sectionOptions = [
            'format' => $format,
            'style' => $style,
            'max_length' => $maxLength,
        ];

        // Build prompt using section pipeline
        $prompt = $this->buildPrompt($context, $sectionOptions);

        try {
            // Generate response
            $answer = $this->llm->complete($prompt, null, [
                'temperature' => $temperature,
                'max_tokens' => $this->calculateMaxTokens($maxLength),
            ]);

            // Extract insights
            $insights = $includeInsights ? $this->extractInsights($queryResult['data']) : [];

            // Suggest visualizations
            $visualizations = $includeVisualization
                ? $this->suggestVisualizations($queryResult['data'], $cypherQuery)
                : [];

            return [
                'answer' => trim($answer),
                'insights' => $insights,
                'visualizations' => $visualizations,
                'format' => $format,
                'metadata' => [
                    'style' => $style,
                    'result_count' => count($queryResult['data']),
                    'summarized' => count($queryResult['data']) > 10,
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
     * Build prompt using section pipeline
     *
     * @param array $context Context with question, data, etc.
     * @param array $options Options for sections
     * @return string Complete prompt
     */
    public function buildPrompt(array $context, array $options = []): string
    {
        $prompt = '';

        $this->processModules(
            beforeCallbackProcess: function($callback) use (&$prompt, $context, $options) {
                $prompt .= $callback($context, $options);
            },
            moduleProcess: function(ResponseSectionInterface $section) use (&$prompt, $context, $options) {
                if ($section->shouldInclude($context, $options)) {
                    $prompt .= $section->format($context, $options);
                }
            },
            afterCallbackProcess: function($callback) use (&$prompt, $context, $options) {
                $prompt .= $callback($context, $options);
            }
        );

        return $prompt;
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

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Check if data contains numeric values
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
     */
    private function calculateMaxTokens(int $maxWords): int
    {
        return (int) ceil($maxWords / 0.75);
    }
}
