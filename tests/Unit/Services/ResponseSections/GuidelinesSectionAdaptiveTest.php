<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\ResponseSections\GuidelinesSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * Lever L1 (B/C/D/E) — the response guidelines must:
 *  B) adapt length guidance to the QU question_type instead of a hardcoded
 *     "2-3 sentences maximum" (config-driven, sensible fallbacks);
 *  C) allow saying "the data cannot answer this" / asking ONE clarifying question,
 *     and never present a partial list as complete;
 *  D) require citation-or-decline for law/rule/document claims;
 *  E) offer a next step instead of a dead end on empty results.
 */
class GuidelinesSectionAdaptiveTest extends TestCase
{
    private function context(string $questionType = 'hybrid', array $data = []): PipelineContext
    {
        $context = new PipelineContext();
        $context->qu = new QueryUnderstandingResult(questionType: $questionType);
        $context->executionResult = new QueryExecutionResult(success: true, data: $data);

        return $context;
    }

    private function format(PipelineContext $context, array $options = []): string
    {
        return (new GuidelinesSection())->format($context, $options);
    }

    // =========================================================================
    // B) Adaptive length by question_type
    // =========================================================================

    /** @test */
    public function the_hardcoded_sentence_cap_is_gone(): void
    {
        foreach (['data_query', 'knowledge_query', 'hybrid'] as $type) {
            $output = $this->format($this->context($type), ['style' => 'friendly']);
            $this->assertStringNotContainsString('2-3 sentences', $output);
        }
    }

    /** @test */
    public function data_query_gets_concise_direct_answer_guidance(): void
    {
        $output = $this->format($this->context('data_query'), ['style' => 'friendly']);

        $this->assertStringContainsString('direct answer', $output);
        $this->assertStringContainsString('concise', strtolower($output));
    }

    /** @test */
    public function knowledge_query_allows_a_short_structured_overview(): void
    {
        $output = $this->format($this->context('knowledge_query'), ['style' => 'friendly']);

        $this->assertStringContainsString('structured', strtolower($output));
        $this->assertMatchesRegularExpression('/headings|bullet/i', $output);
    }

    /** @test */
    public function unknown_question_type_falls_back_to_hybrid_guidance(): void
    {
        $hybrid = $this->format($this->context('hybrid'), ['style' => 'friendly']);
        $unknown = $this->format($this->context('something_else'), ['style' => 'friendly']);

        $this->assertSame($hybrid, $unknown);
    }

    /** @test */
    public function length_guidance_is_config_overridable_per_question_type(): void
    {
        config(['ai.response_generation.length_guidance' => [
            'data_query' => 'CUSTOM-DATA-GUIDANCE',
        ]]);

        $output = $this->format($this->context('data_query'), ['style' => 'friendly']);

        $this->assertStringContainsString('CUSTOM-DATA-GUIDANCE', $output);
    }

    /** @test */
    public function terse_styles_keep_their_explicit_one_line_contract(): void
    {
        // 'minimal'/'concise' are explicit host/style choices for one-line answers;
        // the adaptive length line must not contradict them.
        foreach (['minimal', 'concise'] as $style) {
            $output = $this->format($this->context('knowledge_query'), ['style' => $style]);
            $this->assertStringNotContainsString('structured', strtolower($output));
        }
    }

    /** @test */
    public function the_global_word_cap_line_remains(): void
    {
        $output = $this->format($this->context('data_query'), ['style' => 'friendly']);

        $this->assertStringContainsString('under 100 words', $output);
    }

    // =========================================================================
    // C) Honesty / clarify affordance
    // =========================================================================

    /** @test */
    public function it_allows_saying_the_data_cannot_answer_or_asking_one_clarifying_question(): void
    {
        $output = $this->format($this->context('data_query', [['total' => 1]]), ['style' => 'friendly']);

        $this->assertStringContainsString('cannot', strtolower($output));
        $this->assertStringContainsString('clarifying question', $output);
    }

    /** @test */
    public function it_forbids_presenting_a_partial_list_as_complete(): void
    {
        $output = $this->format($this->context('data_query', [['id' => 1]]), ['style' => 'friendly']);

        $this->assertStringContainsString('partial', strtolower($output));
        $this->assertMatchesRegularExpression('/more (rows|results)/i', $output);
    }

    // =========================================================================
    // D) Citation-or-decline for law/rule/document claims
    // =========================================================================

    /** @test */
    public function it_requires_citation_or_decline_for_document_and_law_claims(): void
    {
        $output = $this->format($this->context('knowledge_query'), ['style' => 'friendly']);

        $this->assertMatchesRegularExpression('/laws?|regulations?/i', $output);
        $this->assertStringContainsString('[N]', $output);
        $this->assertStringContainsString('could not be found in the available documents', $output);
        $this->assertStringContainsString('general knowledge', $output);
    }

    /** @test */
    public function terse_styles_get_the_decline_rule_without_citation_markers(): void
    {
        // FileContextSection suppresses [N] markers for minimal/concise — the
        // guideline must stay consistent and not demand markers those styles forbid.
        $output = $this->format($this->context('knowledge_query'), ['style' => 'concise']);

        $this->assertStringNotContainsString('[N]', $output);
        $this->assertStringContainsString('could not be found in the available documents', $output);
    }

    // =========================================================================
    // E) Next-step affordance on empty results
    // =========================================================================

    /** @test */
    public function empty_results_get_a_next_step_instead_of_a_dead_end(): void
    {
        $output = $this->format($this->context('data_query', []), ['style' => 'friendly']);

        $this->assertStringContainsString('feel free to ask', strtolower($output));
        $this->assertStringContainsString('next step', strtolower($output));
    }

    /** @test */
    public function non_empty_results_do_not_get_the_empty_result_line(): void
    {
        $output = $this->format($this->context('data_query', [['id' => 1]]), ['style' => 'friendly']);

        $this->assertStringNotContainsString('The results are empty', $output);
    }

    /** @test */
    public function file_grounded_turns_with_empty_execution_results_do_not_get_the_empty_result_line(): void
    {
        // Knowledge/document turns run with executionResult empty BY DESIGN — the
        // retrieved chunks are the grounding. The empty-results/next-step guideline
        // must not tell the model "nothing was found" on such turns (R3).
        $context = $this->context('knowledge_query', []);
        $context->fileContext = [
            'relevant_files' => [[
                'file_id' => 1,
                'filename' => 'CCQ_Co-ownership_EN.md',
                'snippet' => 'A co-owner is bound to contribute to the common expenses...',
                'relevance' => 0.7,
                'chunk_index' => 3,
                'source' => 'physical',
            ]],
            'file_count' => 1,
        ];

        $output = $this->format($context, ['style' => 'friendly']);

        $this->assertStringNotContainsString('The results are empty', $output);
    }

    /** @test */
    public function truly_ungrounded_empty_turns_still_get_the_empty_result_line(): void
    {
        // No data AND no file context: the next-step guideline must remain.
        $context = $this->context('data_query', []);
        $context->fileContext = [];

        $output = $this->format($context, ['style' => 'friendly']);

        $this->assertStringContainsString('The results are empty', $output);
    }

    // =========================================================================
    // G7) Universal concrete closer + action link on where/how questions
    // =========================================================================

    /** @test */
    public function every_answer_type_gets_the_concrete_closer_rule(): void
    {
        // The closer rule must apply to ALL answer types, not only empty/data
        // answers (iter-8: ~9 turns ended with a generic "feel free to ask"
        // because the rule was scoped to data answers).
        foreach (['data_query', 'knowledge_query', 'hybrid'] as $type) {
            foreach ([[], [['id' => 1]]] as $data) {
                $output = $this->format($this->context($type, $data), ['style' => 'friendly']);

                $this->assertStringContainsString('feel free to ask', strtolower($output), "type={$type}");
                $this->assertStringContainsString('concrete', strtolower($output), "type={$type}");
            }
        }
    }

    /** @test */
    public function where_or_how_app_questions_require_the_matching_action_link(): void
    {
        $output = $this->format($this->context('knowledge_query', []), ['style' => 'friendly']);

        $this->assertMatchesRegularExpression('/WHERE or HOW/i', $output);
        $this->assertMatchesRegularExpression('/MUST include/i', $output);
        $this->assertStringContainsString('action', strtolower($output));
    }

    /** @test */
    public function terse_styles_keep_their_one_line_contract_without_the_closer_rule(): void
    {
        foreach (['minimal', 'concise'] as $style) {
            $output = $this->format($this->context('data_query', [['id' => 1]]), ['style' => $style]);

            $this->assertStringNotContainsString('feel free to ask', strtolower($output), "style={$style}");
        }
    }
}
