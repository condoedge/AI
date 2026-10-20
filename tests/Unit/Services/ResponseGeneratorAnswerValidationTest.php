<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Phase 8 batch B: ONE post-composition validation pass (AnswerValidator) with
 * ONE combined retry and deterministic patches, replacing the three stacked
 * guard retries + the re-assert step. Scripted provider: each test pins the
 * number of complete() calls (draft + at most one retry).
 */
class ResponseGeneratorAnswerValidationTest extends TestCase
{
    /** @var list<string> user prompts seen by the provider */
    private array $calls = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function generator(string ...$replies): ResponseGenerator
    {
        $this->calls = [];
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('complete')
            ->times(count($replies))
            ->andReturnUsing(function ($user) use ($replies) {
                $this->calls[] = $user;

                return $replies[count($this->calls) - 1];
            });

        return new ResponseGenerator($llm, ['default_format' => 'text', 'default_style' => 'friendly', 'default_max_length' => 200]);
    }

    private function lawContext(): PipelineContext
    {
        $context = new PipelineContext();
        $context->question = "If I buy a unit, am I liable for the previous owner's unpaid fees?";
        $context->qu = new QueryUnderstandingResult(questionType: 'knowledge_query');
        $context->queryResult = new QueryGenerationResult(explanation: 'Knowledge query.', confidence: 1.0);
        $context->executionResult = QueryExecutionResult::empty();
        $files = [];
        foreach ([1068 => 'The syndicate keeps a register.', 1069 => 'The buyer is bound, with the seller, to the common expenses owed.', 1097 => 'Three-quarters of the votes are required.'] as $article => $text) {
            $files[] = ['file_id' => 1, 'filename' => 'CCQ_EN.md', 'snippet' => "{$article}. {$text}", 'relevance' => 0.5, 'chunk_index' => $article];
        }
        $context->fileContext = ['relevant_files' => $files, 'file_count' => 3];

        return $context;
    }

    private function suppliersContext(): PipelineContext
    {
        $context = new PipelineContext();
        $context->question = 'What do we owe our suppliers?';
        $context->qu = new QueryUnderstandingResult(questionType: 'data_query');
        $context->queryResult = new QueryGenerationResult(
            cypher: 'MATCH (s:Supplier) RETURN s.id AS id, s.name AS supplier, s.due AS amount, total AS total'
        );
        $context->executionResult = new QueryExecutionResult(success: true, data: [
            ['id' => 41, 'supplier' => 'Hydro', 'amount' => 4000.50, 'total' => 11493.17],
            ['id' => 42, 'supplier' => 'Bell', 'amount' => 7492.67, 'total' => 11493.17],
        ]);

        return $context;
    }

    /** @test */
    public function a_draft_needing_the_llm_gets_one_combined_retry_naming_the_real_markers(): void
    {
        // A bare "[N]" leaves zero valid markers: citation_missing needs the LLM.
        $result = $this->generator(
            'The buyer is bound to the arrears [N].',
            'Yes — under art. 1069 the buyer is bound to the arrears [2].'
        )->generate($this->lawContext());

        $this->assertCount(2, $this->calls);
        $this->assertStringContainsString('ANSWER VALIDATION', $this->calls[1]);
        $this->assertStringContainsString('[1], [2] or [3]', $this->calls[1]);
        // The quoted draft never carries the placeholder grammar back to the model.
        $this->assertStringNotContainsString('[N]', substr($this->calls[1], strpos($this->calls[1], 'ANSWER VALIDATION')));

        $this->assertSame('Yes — under art. 1069 the buyer is bound to the arrears [2].', $result['answer']);
        $this->assertSame([
            'violations_first' => ['citation_placeholder', 'citation_missing'],
            'retried' => true,
            'violations_final' => [],
            'patched' => [],
        ], $result['metadata']['validation']);
        $this->assertSame('recovered', $result['metadata']['citation_enforcement']);
    }

    /** @test M4 */
    public function a_draft_whose_violations_are_all_fixable_is_patched_without_a_retry(): void
    {
        $result = $this->generator('The buyer is bound to the arrears ([N] 2).')->generate($this->lawContext());

        $this->assertCount(1, $this->calls);
        $this->assertSame('The buyer is bound to the arrears [2].', $result['answer']);
        $this->assertSame([
            'violations_first' => ['citation_placeholder'],
            'retried' => false,
            'violations_final' => [],
            'patched' => ['citation_placeholder'],
            'retry_skipped' => 'all_fixable',
        ], $result['metadata']['validation']);
    }

    /** @test */
    public function a_placeholder_surviving_the_retry_never_ships(): void
    {
        $result = $this->generator(
            'The buyer is bound to the arrears [N].',
            'The buyer is bound [N] 2 to the arrears.'
        )->generate($this->lawContext());

        $this->assertSame('The buyer is bound [2] to the arrears.', $result['answer']);
        $this->assertSame(['citation_placeholder'], $result['metadata']['validation']['patched']);
        $this->assertSame([], $result['metadata']['validation']['violations_final']);
    }

    /** @test */
    public function a_misattributed_article_is_moved_to_the_passage_defining_it(): void
    {
        $result = $this->generator('Under art. 1069 the buyer is bound [1].')->generate($this->lawContext());

        $this->assertCount(1, $this->calls, 'fixable: no retry');
        $this->assertSame('Under art. 1069 the buyer is bound [2].', $result['answer']);
        $this->assertSame(['citation_article_mismatch'], $result['metadata']['validation']['patched']);
    }

    /** @test I1 */
    public function a_transposed_total_is_patched_with_the_known_figure_without_a_retry(): void
    {
        $result = $this->generator("In total you owe \$11,943.17.\n\n{{table}}")->generate($this->suppliersContext());

        $this->assertCount(1, $this->calls);
        $this->assertStringContainsString('In total you owe $11,493.17.', $result['answer']);
        $this->assertSame(['number_unsupported'], $result['metadata']['validation']['patched']);
        $this->assertSame([], $result['metadata']['validation']['violations_final']);
    }

    /** @test I1 */
    public function a_far_off_total_goes_to_the_retry_and_is_never_silently_rewritten(): void
    {
        $result = $this->generator(
            "You owe \$10,493.17 to your suppliers.\n\n{{table}}",
            "In total you owe \$10,493.17.\n\n{{table}}"
        )->generate($this->suppliersContext());

        $this->assertStringContainsString('does not appear', $this->calls[1]);
        $this->assertStringContainsString('11,493.17', $this->calls[1], 'the retry names the real figure');
        $this->assertStringContainsString('$10,493.17', $result['answer'], 'recorded, not rewritten');
        $this->assertSame([], $result['metadata']['validation']['patched']);
        $this->assertSame([['type' => 'number_unsupported', 'detail' => '10,493.17']], $result['metadata']['validation']['unresolved']);
    }

    /** @test */
    public function an_unsupported_non_aggregate_number_is_recorded_as_unresolved(): void
    {
        $result = $this->generator(
            "Hydro is owed \$812.40.\n\n{{table}}",
            "Hydro is owed \$812.40.\n\n{{table}}"
        )->generate($this->suppliersContext());

        $this->assertStringContainsString('$812.40', $result['answer'], 'nothing is invented in its place');
        $this->assertSame(['number_unsupported'], $result['metadata']['validation']['violations_final']);
        $this->assertSame([['type' => 'number_unsupported', 'detail' => '812.40']], $result['metadata']['validation']['unresolved']);
    }

    /** @test */
    public function an_invented_link_is_stripped_keeping_its_text(): void
    {
        // The unsupported figure needs the LLM, so the retry also lists the real ids.
        $result = $this->generator(
            "Hydro is owed \$812.40 — [open Hydro](entity://Supplier/3/view).\n\n{{table}}",
            "Hydro is your largest supplier — [open Hydro](entity://Supplier/ID/view).\n\n{{table}}"
        )->generate($this->suppliersContext());

        $this->assertStringContainsString('ids must come from the rows', $this->calls[1]);
        $this->assertStringContainsString('valid Supplier ids: 41, 42', $this->calls[1]);
        $this->assertStringContainsString('Hydro is your largest supplier — open Hydro.', $result['answer']);
        $this->assertStringNotContainsString('entity://', $result['answer']);
        $this->assertSame(['link_invalid'], $result['metadata']['validation']['patched']);
    }

    /** @test I3 */
    public function an_action_link_carrying_the_current_team_id_survives(): void
    {
        // The rows carry ANOTHER team id, so the index holds the Team label: only the
        // seeded identity id keeps the current-team link alive.
        $context = $this->suppliersContext();
        $context->executionResult = new QueryExecutionResult(success: true, data: array_map(
            static fn (array $row) => $row + ['team_id' => 9],
            $context->executionResult->data
        ));

        $result = $this->generator(
            "You owe \$11,493.17. [Team settings](action://team_settings?team_id=5) [Other](action://team_settings?team_id=77)\n\n{{table}}"
        )->generate($context, ['user' => (object) ['id' => 13, 'current_team_id' => 5]]);

        // The validator backstop still lints the ids (the invented team id is the violation)…
        $this->assertStringNotContainsString('team_id=77', $result['answer'], 'an invented team id is stripped');
        $this->assertSame(['link_invalid'], $result['metadata']['validation']['patched']);
        // …and since actions plan B5 fix 2 NO model-written action link ships at all: the
        // team page is a code-attached chip; the narrative keeps the link text only.
        $this->assertStringNotContainsString('action://', $result['answer']);
        $this->assertStringContainsString('Team settings', $result['answer']);
    }

    /** @test */
    public function a_valid_link_and_supported_numbers_ship_without_a_retry(): void
    {
        $result = $this->generator(
            "You owe \$11,493.17 to 2 suppliers — [open Hydro](entity://Supplier/41/view).\n\n{{table}}"
        )->generate($this->suppliersContext());

        $this->assertCount(1, $this->calls);
        $this->assertSame(['violations_first' => [], 'retried' => false, 'violations_final' => [], 'patched' => []], $result['metadata']['validation']);
    }

    /** @test */
    public function a_retry_that_is_worse_than_the_draft_is_discarded(): void
    {
        // The draft needs the LLM for one invented figure; the retry drops the
        // citation AND invents two more figures — the draft is kept and patched.
        $result = $this->generator(
            'The buyer is bound to the arrears ([N] 2), about 4,500.00 $.',
            'The buyer owes 4,500.00 $ and 9,999.00 $ for sure.'
        )->generate($this->lawContext());

        $this->assertSame('The buyer is bound to the arrears [2], about 4,500.00 $.', $result['answer']);
    }
}
