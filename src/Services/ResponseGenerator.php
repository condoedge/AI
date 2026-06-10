<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\ResponseSectionInterface;
use Condoedge\Ai\Contracts\ToolCallingLlmInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\ActionToolChannel;
use Condoedge\Ai\Services\Actions\ActionToolGate;
use Condoedge\Ai\Services\Actions\ToolLoopRunner;
use Condoedge\Ai\Services\ResponseSections\SystemPromptSection;
use Condoedge\Ai\Services\ResponseSections\ResponseProjectContextSection;
use Condoedge\Ai\Services\ResponseSections\ResultsDataSection;
use Condoedge\Ai\Services\ResponseSections\GuidelinesSection;

/**
 * ResponseGenerator — turns a PipelineContext into a natural-language answer.
 *
 * generate() assembles a role-split prompt via the priority-ordered section
 * pipeline (buildPrompts() returns {system, user}: identity + security policy in
 * the system role, the untrusted question + data in the user role) and calls the
 * LLM. Sections are configured under `config('ai.response_generator_sections')`
 * and managed through the HasInternalModules trait (addModule/removeModule/
 * replaceModule/extendAfter). Returns: answer, insights, visualizations, format,
 * metadata.
 *
 * Action channel: when an {@see ActionToolGate} + {@see ToolLoopRunner} are wired,
 * generate() consults the gate ONCE per turn — Native runs the bounded tool loop
 * instead of complete(); Markdown/None (and legacy construction without the
 * collaborators) keep today's single complete() call byte-identical.
 *
 * @uses HasInternalModules<ResponseSectionInterface>
 * @see ResponseSectionInterface For creating custom sections.
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
     * @param ActionToolGate|null $toolGate Channel decision point (null = markdown-only legacy behavior)
     * @param ToolLoopRunner|null $toolLoop Native tool loop (null = markdown-only legacy behavior)
     */
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly array $config = [],
        private readonly ?ActionToolGate $toolGate = null,
        private readonly ?ToolLoopRunner $toolLoop = null
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
     * Generate natural language response from pipeline context
     *
     * @param PipelineContext $context Pipeline context with question, query result, execution data, etc.
     * @param array $options Generation options
     * @return array Response with answer, insights, visualizations
     * @throws \RuntimeException If response generation fails
     */
    public function generate(
        PipelineContext $context,
        array $options = []
    ): array {
        $format = $options['format'] ?? $this->config['default_format'] ?? 'text';
        $style = $options['style'] ?? $this->config['default_style'] ?? 'detailed';
        $includeInsights = $options['include_insights'] ?? true;
        $includeVisualization = $options['include_visualization'] ?? true;
        $maxLength = $options['max_length'] ?? $this->config['default_max_length'] ?? 500;
        $temperature = $options['temperature'] ?? 0.3;

        $sectionOptions = [
            'format' => $format,
            'style' => $style,
            'max_length' => $maxLength,
        ];

        // THE channel decision — once per turn, one call. Absent collaborators (legacy
        // construction) and the default-off kill switch both mean Markdown: the pre-existing
        // path, byte-identical, with no spec construction paid. The channel rides the section
        // options so the model is never taught the link grammar while holding real tools.
        $toolSpecs = [];
        $channel = ActionToolChannel::Markdown;
        if ($this->toolGate !== null && $this->toolLoop !== null) {
            [$channel, $toolSpecs] = $this->toolGate->decide($this->llm, $context, $sectionOptions);
        }
        $sectionOptions[ActionToolChannel::OPTION] = $channel;

        // Build prompt using section pipeline, split into a system message
        // (identity + security policy) and a user message (context + data).
        ['system' => $systemPrompt, 'user' => $userPrompt] = $this->buildPrompts($context, $sectionOptions);

        $data = $context->executionResult->data;
        $cypherQuery = $context->queryResult->cypher;

        $llmOptions = [
            'temperature' => $temperature,
            'max_tokens' => $this->calculateMaxTokens($maxLength),
        ];

        try {
            $loopResult = null;
            if ($channel === ActionToolChannel::Native && $this->llm instanceof ToolCallingLlmInterface) {
                // A loop failure with nothing accumulated propagates into this method's
                // wrap — never a markdown complete() call against a possibly-open breaker.
                $loopResult = $this->toolLoop->run(
                    $this->llm,
                    $this->toolLoopMessages($systemPrompt, $userPrompt),
                    $toolSpecs,
                    $this->toolLoopContext($context, $options),
                    $llmOptions,
                );
                $answer = $loopResult->answer;
            } else {
                // Generate response — identity/security ride in the real system role,
                // untrusted context/data stays in the user role.
                $answer = $this->llm->complete($userPrompt, $systemPrompt !== '' ? $systemPrompt : null, $llmOptions);
            }

            $insights = $includeInsights ? $this->extractInsights($data) : [];

            $visualizations = $includeVisualization
                ? $this->suggestVisualizations($data, $cypherQuery)
                : [];

            $metadata = [
                'style' => $style,
                'result_count' => count($data),
                'summarized' => count($data) > 10,
            ];
            if ($loopResult !== null) {
                // Riding the metadata keeps AiManager untouched: buildFinalResponse already
                // passes response metadata through, where the chat persistence layer reads
                // the filtered invocations to store beside response_data/suggestions.
                $metadata['tool_calling'] = $loopResult->telemetry;
                $metadata['invocations'] = array_map(
                    static fn ($invocation) => $invocation->toArray(),
                    $loopResult->invocations
                );
            }

            return [
                'answer' => trim($answer),
                'insights' => $insights,
                'visualizations' => $visualizations,
                'format' => $format,
                'metadata' => $metadata,
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
     * The native loop starts from the SAME role-split section prompt as the markdown
     * path, expressed as chat messages.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function toolLoopMessages(string $systemPrompt, string $userPrompt): array
    {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        return $messages;
    }

    /**
     * Dispatch context for in-loop action execution — the same keys the render-time link
     * path passes (user, result_set). The runner adds entity_type/entity_id per call.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function toolLoopContext(PipelineContext $context, array $options): array
    {
        $dispatch = ['result_set' => $context->executionResult->data];
        if (isset($options['user'])) {
            $dispatch['user'] = $options['user'];
        }

        return $dispatch;
    }

    /**
     * Build the prompt split by role.
     *
     * Each section declares role() => 'system' | 'user'. System sections (identity,
     * security policy) are concatenated into the system message; everything else into
     * the user message. Section ORDER is preserved within each role (the pipeline is
     * still processed in priority order). Before/after callbacks are appended to the
     * user message to preserve their historical placement.
     *
     * @param PipelineContext $context Pipeline context with question, data, etc.
     * @param array $options Options for sections
     * @return array{system: string, user: string}
     */
    public function buildPrompts(PipelineContext $context, array $options = []): array
    {
        $system = '';
        $user = '';

        $this->processModules(
            beforeCallbackProcess: function($callback) use (&$user, $context, $options) {
                $user .= $callback($context, $options);
            },
            moduleProcess: function(ResponseSectionInterface $section) use (&$system, &$user, $context, $options) {
                if (!$section->shouldInclude($context, $options)) {
                    return;
                }

                $content = $section->format($context, $options);

                if ($section->role() === 'system') {
                    $system .= $content;
                } else {
                    $user .= $content;
                }
            },
            afterCallbackProcess: function($callback) use (&$user, $context, $options) {
                $user .= $callback($context, $options);
            }
        );

        return ['system' => $system, 'user' => $user];
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
        ?string $cypherQuery,
        array $options = []
    ): array {
        $format = $options['format'] ?? 'text';
        $temperature = $options['temperature'] ?? 0.3;

        // Carry the same system-role policy (identity, non-disclosure, privacy) as the
        // grounded path — abstention must not run with a null system prompt, or a
        // "which model are you?" question detours here and self-identifies the provider.
        // The executed query is intentionally NOT placed in the prompt (schema must not leak).
        $context = new PipelineContext();
        $context->question = $originalQuestion;
        ['system' => $systemPrompt] = $this->buildPrompts($context, ['format' => $format]);

        // The LLM-facing scaffold is neutral English on purpose: localizing it (via __()) forces
        // the model into the app locale even when the question was asked in another language. The
        // reply language is driven by the question itself plus the system-prompt language policy.
        $prompt = "The user asked: \"{$originalQuestion}\"\n\n";
        $prompt .= "No matching records were found for this question. Briefly and helpfully tell the "
            . "user that no results were found, and if useful suggest how they might rephrase or what "
            . "else they could ask.\n\n";
        // If the question is really a request to DO something the app supports (book/reserve/contact
        // support/...), offer that action as a link instead of a dead-end "no results".
        $actionsHint = $this->emptyResponseActionsHint();
        if ($actionsHint !== '') {
            $prompt .= $actionsHint . "\n\n";
        }
        $prompt .= "Respond in the SAME language as the user's question above.";

        try {
            $answer = $this->llm->complete($prompt, $systemPrompt !== '' ? $systemPrompt : null, [
                'temperature' => $temperature,
                'max_tokens' => 200,
            ]);

            return [
                'answer' => trim($answer),
                'insights' => [__('ai.insight.no_results')],
                'visualizations' => [],
                'format' => $format,
                'metadata' => [
                    'empty_result' => true,
                    'result_count' => 0,
                ],
            ];

        } catch (\Exception $e) {
            return [
                'answer' => __('ai.response.no_results_fallback', ['question' => $originalQuestion]),
                'insights' => [__('ai.insight.no_results')],
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
     * A hint listing the available generic actions, so an action request that returns no data
     * (e.g. "book a reservation") is answered with the matching action link instead of a dead-end
     * "no results". The registry is the single source of truth (the same actions the response
     * sections and the action link handler use). Returns '' when none are configured/resolvable.
     */
    private function emptyResponseActionsHint(): string
    {
        try {
            // 90c W6: only actions THIS user survives authorize() on are offered — the
            // renderer strips the rest, which would read as a dead offer. Same principal
            // resolution as the dispatcher's render-time gate (auth user, null if no context).
            $user = null;
            try {
                $user = function_exists('auth') ? auth()->user() : null;
            } catch (\Throwable $e) {
                // no auth context (console/queue) — gated actions fail closed
            }

            $actions = app(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class)->getAuthorizedGenericActions($user);
        } catch (\Throwable $e) {
            return '';
        }

        $lines = [];
        foreach ($actions as $action) {
            $key = $action['action_key'] ?? null;
            if (!$key) {
                continue;
            }
            $label = $action['label'] ?? $key;
            $aliases = !empty($action['aliases']) ? ' (e.g. ' . implode(', ', $action['aliases']) . ')' : '';
            $lines[] = "- [{$label}](action://{$key}){$aliases}";
        }

        if (empty($lines)) {
            return '';
        }

        return "If the user's request actually asks to DO one of these, offer it as a clickable "
            . "markdown link instead of saying no results were found:\n" . implode("\n", $lines);
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
        $answer = __('ai.error.encountered_issue', ['question' => $originalQuestion]) . ' ';

        // Add specific guidance based on error type
        if (str_contains($error->getMessage(), 'timeout')) {
            $answer .= __('ai.error.timeout_guidance');
        } elseif (str_contains($error->getMessage(), 'syntax')) {
            $answer .= __('ai.error.syntax_guidance');
        } else {
            $answer .= __('ai.error.generic_guidance');
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
            'insights' => [__('ai.insight.error_occurred')],
            'visualizations' => [],
            'format' => $format,
            'metadata' => $metadata,
        ];
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
        $insights[] = trans_choice('ai.insight.found_results', $count, ['count' => $count]);

        // Detect if numeric data
        if ($this->isNumericData($queryResult)) {
            $stats = $this->calculateStatistics($queryResult);

            if ($stats) {
                $insights[] = __('ai.insight.average_value', ['value' => round($stats['avg'], 2)]);

                if ($stats['max'] > $stats['avg'] * 2) {
                    $insights[] = __('ai.insight.high_values');
                }

                if ($stats['min'] < $stats['avg'] * 0.5 && $stats['min'] > 0) {
                    $insights[] = __('ai.insight.low_values');
                }
            }
        }

        // Raw property/column names are schema details that must not leak into
        // user-facing insights, so no "properties: id, name, ..." insight is emitted.
        // The result count already conveys shape.

        return $insights;
    }

    /**
     * Suggest appropriate visualizations
     *
     * @param array $queryResult Query results
     * @param string $cypherQuery Original query
     * @return array Suggested visualization types with rationale
     */
    public function suggestVisualizations(array $queryResult, ?string $cypherQuery): array
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
