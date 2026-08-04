<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * GuidelinesSection
 *
 * Provides guidelines for response generation with configurable verbosity.
 * Priority: 70
 *
 * Available styles:
 * - 'minimal': Just the answer, nothing else (e.g., "Admin System" or "42")
 * - 'concise': One sentence answer (e.g., "The next birthday is Admin System on Nov 29.")
 * - 'friendly': Natural conversation style, length adapted to the question type
 * - 'detailed': Full explanation with some context
 * - 'technical': Includes query details and execution info
 *
 * Length is question_type-adaptive (lever L1-B): instead of a hardcoded sentence
 * cap, the QU question_type drives the guidance — a data question gets a concise
 * direct answer, a knowledge/document question may use a short structured overview.
 * Hosts can override per type via config('ai.response_generation.length_guidance').
 * The global word cap (max_length / default_max_length) still applies.
 *
 * Configuration via config/ai.php:
 * ```php
 * 'response_generation' => [
 *     'default_style' => 'friendly',
 *     'length_guidance' => ['data_query' => '...', 'knowledge_query' => '...', 'hybrid' => '...'],
 *     'hide_technical_details' => true,
 *     'hide_execution_stats' => true,
 *     'hide_project_info' => true,
 * ]
 * ```
 */
class GuidelinesSection extends BaseResponseSection
{
    protected string $name = 'guidelines';
    protected int $priority = 70;

    /**
     * Styles whose explicit contract is a one-line answer; the adaptive length
     * guidance and [N] citation-marker instructions must not contradict them
     * (single-sourced idea with FileContextSection::TERSE_STYLES).
     */
    private const TERSE_STYLES = ['minimal', 'concise'];

    /**
     * Question_type-adaptive length guidance defaults (lever L1-B). Overridable per
     * type via config('ai.response_generation.length_guidance'). Unknown types fall
     * back to 'hybrid'.
     */
    private const DEFAULT_LENGTH_GUIDANCE = [
        'data_query' => 'This is a data question: keep it concise. Lead with the direct answer in the first sentence, then only the essential supporting details.',
        'knowledge_query' => 'This is a knowledge/document question: a short structured overview is appropriate — you may use brief headings or bullet points when they genuinely organize the answer. Stay focused; no padding.',
        'hybrid' => 'Lead with the direct answer, then add brief structure (a few bullet points) only where it genuinely helps.',
    ];

    /**
     * Style prompts - from minimal to technical
     */
    private array $stylePrompts = [
        'minimal' => 'Give ONLY the direct answer. No explanation, no context, no extra words. Just the answer value.',
        'concise' => 'One sentence only. Direct answer with the key fact. No explanation of how you got it.',
        'friendly' => 'Answer in a natural, conversational way. Focus on what the user asked, not how you found it.',
        'detailed' => 'Provide a helpful explanation with relevant context. Avoid technical jargon.',
        'technical' => 'Include technical details like query structure and execution metrics for debugging.',
    ];

    /**
     * Things to avoid based on configuration
     */
    private array $avoidGuidelines = [];

    /**
     * Custom guidelines
     */
    private array $customGuidelines = [];

    /**
     * Add a custom guideline
     */
    public function addGuideline(string $guideline): self
    {
        $this->customGuidelines[] = $guideline;
        return $this;
    }

    /**
     * Set all custom guidelines
     */
    public function setGuidelines(array $guidelines): self
    {
        $this->customGuidelines = $guidelines;
        return $this;
    }

    /**
     * Add or override a style
     */
    public function addStyle(string $name, string $prompt): self
    {
        $this->stylePrompts[$name] = $prompt;
        return $this;
    }

    /**
     * Set things to avoid in the response
     */
    public function setAvoidGuidelines(array $avoid): self
    {
        $this->avoidGuidelines = $avoid;
        return $this;
    }

    /**
     * Add something to avoid
     */
    public function addAvoid(string $avoid): self
    {
        $this->avoidGuidelines[] = $avoid;
        return $this;
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $style = $options['style'] ?? config('ai.response_generation.default_style', 'friendly');
        $format = $options['format'] ?? 'text';
        $maxLength = $options['max_length'] ?? $this->getMaxLengthForStyle($style);

        $hideTechnical = $options['hide_technical'] ?? config('ai.response_generation.hide_technical_details', true);
        $hideStats = $options['hide_stats'] ?? config('ai.response_generation.hide_execution_stats', true);
        $hideProjectInfo = $options['hide_project'] ?? config('ai.response_generation.hide_project_info', true);

        $isTerse = in_array($style, self::TERSE_STYLES, true);

        $output = "Task: Answer the user's question based on the data.\n\n";
        $output .= "Guidelines:\n";
        $output .= "- ALWAYS respond in the SAME LANGUAGE the user used in their question. If the question is in French, answer in French. If English, answer in English.\n";
        $output .= "- " . ($this->stylePrompts[$style] ?? $this->stylePrompts['friendly']) . "\n";

        // L1-B: question_type-adaptive length guidance (data question => concise
        // direct answer; knowledge/document question => short structured overview
        // allowed). Suppressed for terse styles whose contract is a one-liner.
        if (!$isTerse) {
            $output .= "- " . $this->lengthGuidanceFor($context->qu->questionType) . "\n";
        }

        $output .= "- Start with a direct answer to the question when the data supports one\n";
        $output .= "- Use specific data from the results\n";
        $output .= "- Keep response under {$maxLength} words\n";

        // L1-C: honesty/clarify affordance — an unanswerable result must never be
        // forced into a fabricated direct answer.
        $output .= "- If the retrieved data cannot actually answer the question (wrong level of detail, truncated or mixed results, or an ambiguous reference), say so plainly or ask ONE short clarifying question instead of forcing an answer. Never derive, estimate, or invent a figure the data does not directly state.\n";
        $output .= "- Never present a partial list as complete: when more rows or results exist than are shown, say so explicitly.\n";

        // L1-D: citation-or-decline for law/rule/document claims. The [N]-marker
        // clause is dropped for terse styles, matching FileContextSection which
        // suppresses citation instructions there.
        $citeClause = $isTerse ? '' : ', citing it with [N] markers when file content is provided';
        $output .= "- For claims about laws, regulations, policies, or document contents: state only what the retrieved file content actually supports{$citeClause}. If the available documents do not contain the answer, say it could not be found in the available documents — never answer such questions from general knowledge.\n";

        // G7: the concrete-closer rule is UNIVERSAL — every answer type (data,
        // knowledge, hybrid, empty or not) must end with one concrete next step,
        // never a generic invitation. Previously scoped to empty results only,
        // which left ~9 of 20 graded turns ending "feel free to ask". Suppressed
        // for terse styles whose contract is a bare one-line answer.
        if (!$isTerse) {
            $output .= "- End your answer with ONE concrete, relevant next step: the closest matching action available to you (as an action link when actions are available) or ONE specific follow-up grounded in this answer. Do NOT end with a bare invitation like \"feel free to ask\".\n";
            $output .= "- When the question asks WHERE or HOW to do something in the app and a matching action is available to you, you MUST include that action's link in your answer.\n";
        }

        // L1-E: empty results additionally demand an honest "nothing was found".
        // Applies ONLY when NOTHING grounds the turn: on a file-grounded turn
        // (knowledge/document questions run with executionResult empty by design,
        // the retrieved chunks ARE the grounding) this guideline would wrongly
        // instruct the model to say nothing was found.
        if (empty($context->executionResult->data) && empty($context->fileContext['relevant_files'])) {
            $output .= "- The results are empty: say clearly that nothing was found, then offer the most relevant next step — the closest matching action available to you, or ONE more specific question that is likely to succeed.\n";
        }

        // The "don't reveal how you got it" rule is stated ONCE here, and applies to
        // every style except 'technical', which may surface those details.
        if ($style !== 'technical' && $this->shouldHideMechanics($style, $hideTechnical, $hideStats, $hideProjectInfo)) {
            $output .= "- Do NOT reveal how you found the answer: no query/database references, no execution time or performance metrics, no project/system name, and no phrases like \"The query returned\" or \"This was obtained by\". Speak directly as if you simply know the answer.\n";
        }

        if ($format === 'markdown') {
            $output .= "- Format response in Markdown\n";
        } elseif ($format === 'json') {
            $output .= "- Structure response as JSON with 'summary' and 'details' keys\n";
        }

        if (!empty($this->avoidGuidelines)) {
            $output .= "\nDo NOT include:\n";
            foreach (array_unique($this->avoidGuidelines) as $avoid) {
                $output .= "- {$avoid}\n";
            }
        }

        if (!empty($this->customGuidelines)) {
            $output .= "\nAdditional guidelines:\n";
            foreach ($this->customGuidelines as $guideline) {
                $output .= "- {$guideline}\n";
            }
        }

        return $output . "\n";
    }

    /**
     * Question_type-adaptive length guidance (L1-B). Host overrides ride
     * config('ai.response_generation.length_guidance'); unknown/blank question
     * types fall back to the 'hybrid' default. No hardcoded sentence caps.
     */
    private function lengthGuidanceFor(string $questionType): string
    {
        $questionType = $questionType !== '' ? $questionType : 'hybrid';

        $configured = (array) config('ai.response_generation.length_guidance', []);

        return $configured[$questionType]
            ?? self::DEFAULT_LENGTH_GUIDANCE[$questionType]
            ?? $configured['hybrid']
            ?? self::DEFAULT_LENGTH_GUIDANCE['hybrid'];
    }

    /**
     * Whether the "don't reveal mechanics" rule applies for this style.
     *
     * Terse styles (minimal/concise/friendly) always hide mechanics. The 'detailed'
     * style honors the config toggles. 'technical' is excluded by the caller.
     */
    private function shouldHideMechanics(string $style, bool $hideTechnical, bool $hideStats, bool $hideProjectInfo): bool
    {
        if (in_array($style, ['minimal', 'concise', 'friendly'], true)) {
            return true;
        }

        if ($style === 'detailed') {
            return $hideTechnical || $hideStats || $hideProjectInfo;
        }

        return true;
    }

    /**
     * Get appropriate max length for style
     */
    private function getMaxLengthForStyle(string $style): int
    {
        return match ($style) {
            'minimal' => 20,
            'concise' => 50,
            'friendly' => 100,
            'detailed' => 200,
            'technical' => 300,
            default => 100,
        };
    }
}
