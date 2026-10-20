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
 * BLOCKING truncation/count honesty guard (structural, not semantic).
 *
 * Trigger: the turn's result_count — or the true total stamped by the companion
 * count (executionResult metadata true_total) — EXCEEDS the rows rendered into
 * the prompt's data sample. The shipped answer must then contain the EXACT
 * total as a literal number AND additionally the shown count or the exact
 * remainder (a bare total — "more than 523 results" — is not a disclosure).
 * One bounded regeneration on a miss; a still-missing disclosure is appended
 * DETERMINISTICALLY, so a truncated answer physically cannot ship undisclosed.
 * Flag: metadata truncation_honesty = clean|recovered|patched.
 */
class ResponseGeneratorTruncationHonestyTest extends TestCase
{
    private LlmProviderInterface $llmMock;
    private ResponseGenerator $generator;

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.response_generation.truncation_honesty' => true]);
        // The LLM guard governs the NARRATIVE-ONLY path. With deterministic tables
        // (Phase 8 batch A) the code-rendered table carries its own "Showing X of Y"
        // line instead — pinned in ResponseGeneratorDeterministicDataTest.
        config(['ai.response_generation.deterministic_tables' => false]);

        $this->llmMock = Mockery::mock(LlmProviderInterface::class);
        $this->generator = new ResponseGenerator($this->llmMock, [
            'default_format' => 'text',
            'default_style' => 'friendly',
            'default_max_length' => 200,
        ]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * A data-query context with $rowCount execution rows (10 rendered into the
     * prompt sample by ResultsDataSection's default), optionally carrying the
     * companion count's true total.
     */
    private function truncatedContext(int $rowCount = 100, ?int $trueTotal = null): PipelineContext
    {
        $data = [];
        for ($i = 1; $i <= $rowCount; $i++) {
            $data[] = ['id' => $i, 'name' => "Member {$i}"];
        }

        $context = new PipelineContext();
        $context->question = 'Who are the members of my team?';
        $context->qu = new QueryUnderstandingResult(questionType: 'data_query');
        $context->queryResult = new QueryGenerationResult(cypher: 'MATCH (u:User) RETURN u LIMIT 100');
        $context->executionResult = new QueryExecutionResult(
            success: true,
            data: $data,
            stats: $trueTotal !== null ? ['true_total' => $trueTotal] : [],
            metadata: $trueTotal !== null ? ['true_total' => $trueTotal] : [],
        );

        return $context;
    }

    /** @test */
    public function a_draft_that_states_the_total_and_the_shown_count_ships_untouched_and_is_flagged_clean(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('Your team has 100 members; here are the first 10.');

        $result = $this->generator->generate($this->truncatedContext(100));

        $this->assertSame('Your team has 100 members; here are the first 10.', $result['answer']);
        $this->assertSame('clean', $result['metadata']['truncation_honesty'] ?? null);
    }

    /** @test */
    public function a_draft_that_states_the_total_and_the_remainder_is_also_clean(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('Your team has 100 members — 90 more are not shown.');

        $result = $this->generator->generate($this->truncatedContext(100));

        $this->assertSame('clean', $result['metadata']['truncation_honesty'] ?? null);
    }

    /** @test */
    public function a_bare_total_without_shown_or_remainder_is_no_longer_clean(): void
    {
        // Pinned from the 2026-07-23 iter-9 live failure (team-info t0): "more
        // than 523 results available" states the total but neither the shown
        // count (10) nor the remainder (513) — the reader cannot tell how much
        // was withheld. The guard must fire; the retry instruction demands the
        // exact total with the entity noun (no "more than", no "results").
        // Phase 8-B M4: a truncation slip alone is fixable — patched, no retry.
        $this->llmMock->shouldReceive('complete')->once()->andReturn('There are more than 523 results available.');

        $result = $this->generator->generate($this->truncatedContext(523));

        $this->assertSame('patched', $result['metadata']['truncation_honesty'] ?? null);
        $this->assertStringContainsString('Showing 10 of 523', $result['answer']);
        $this->assertSame('all_fixable', $result['metadata']['validation']['retry_skipped'] ?? null);
    }

    /** @test */
    public function the_combined_retry_carries_the_completeness_rule_banning_the_observed_phrasing(): void
    {
        // The draft also invents a figure (needs the LLM), so the ONE combined retry
        // runs and carries the RESULT COMPLETENESS rule.
        $calls = [];
        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturnUsing(function ($user) use (&$calls) {
                $calls[] = $user;
                return count($calls) === 1
                    ? 'There are more than 523 results available, 9,999.99 $ in dues.'
                    : 'Still not disclosing properly.';
            });

        $result = $this->generator->generate($this->truncatedContext(523));

        $this->assertSame('patched', $result['metadata']['truncation_honesty'] ?? null);
        $this->assertStringContainsString('Showing 10 of 523', $result['answer']);
        $this->assertStringContainsString('RESULT COMPLETENESS', $calls[1]);
        $this->assertStringContainsString("'more than 523'", $calls[1]);
        $this->assertStringContainsString("not the word 'results'", $calls[1]);
    }

    /** @test */
    public function a_bare_remainder_without_the_total_is_not_clean(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->once() // Phase 8-B M4: fixable-only — patched, no retry
            ->andReturn('Here are ten members — 90 more are not shown.');

        $result = $this->generator->generate($this->truncatedContext(100));

        $this->assertSame('patched', $result['metadata']['truncation_honesty'] ?? null);
        $this->assertMatchesRegularExpression('/(?<!\d)100(?!\d)/', $result['answer']);
    }

    /** @test */
    public function a_missing_disclosure_riding_the_combined_retry_can_recover(): void
    {
        $calls = [];
        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturnUsing(function ($user) use (&$calls) {
                $calls[] = $user;
                return count($calls) === 1
                    ? 'Here are the members of your team (dues 9,999.99 $).' // no total + an invented figure
                    : 'Here are 10 of your 100 team members.';
            });

        $result = $this->generator->generate($this->truncatedContext(100));

        $this->assertCount(2, $calls, 'Exactly one combined retry');
        // The retry prompt carries the hard instruction, the numbers, and the draft.
        $this->assertStringContainsString('RESULT COMPLETENESS', $calls[1]);
        $this->assertStringContainsString('100', $calls[1]);
        $this->assertStringContainsString('Here are the members of your team', $calls[1]);
        $this->assertSame('Here are 10 of your 100 team members.', $result['answer']);
        $this->assertSame('recovered', $result['metadata']['truncation_honesty'] ?? null);
    }

    /** @test */
    public function a_still_missing_disclosure_is_patched_deterministically_so_it_can_never_ship_undisclosed(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->once() // Phase 8-B M4: fixable-only — patched, no retry
            ->andReturn('Here are your team members.');

        $result = $this->generator->generate($this->truncatedContext(100));

        $this->assertSame('patched', $result['metadata']['truncation_honesty'] ?? null);
        // The draft is kept and the localized disclosure line appended: the answer
        // PHYSICALLY contains the total now.
        $this->assertStringContainsString('Here are your team members.', $result['answer']);
        $this->assertStringContainsString('Showing 10 of 100', $result['answer']);
        $this->assertMatchesRegularExpression('/(?<!\d)100(?!\d)/', $result['answer']);
    }

    /** @test */
    public function a_retry_error_still_ends_in_the_deterministic_patch(): void
    {
        $calls = 0;
        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturnUsing(function () use (&$calls) {
                $calls++;
                if ($calls === 2) {
                    throw new \RuntimeException('LLM down');
                }
                return 'Undisclosed truncated list, 9,999.99 $ in dues.'; // the figure forces the retry
            });

        $result = $this->generator->generate($this->truncatedContext(100));

        $this->assertSame('patched', $result['metadata']['truncation_honesty'] ?? null);
        $this->assertStringContainsString('Showing 10 of 100', $result['answer']);
    }

    /** @test */
    public function the_companion_true_total_wins_over_the_retrieved_row_count(): void
    {
        // 100 retrieved rows are the executor cap; the companion count knows the
        // population is really 250. A draft quoting "100" is NOT a disclosure of
        // the true total (nor of the 240 remainder) — the guard must fire.
        $this->llmMock->shouldReceive('complete')
            ->once() // Phase 8-B M4: fixable-only — patched, no retry
            ->andReturn('Your team has 100 members.');

        $result = $this->generator->generate($this->truncatedContext(100, 250));

        $this->assertSame('patched', $result['metadata']['truncation_honesty'] ?? null);
        $this->assertStringContainsString('Showing 10 of 250', $result['answer']);
        $this->assertSame(250, $result['metadata']['result_total'] ?? null);
    }

    /** @test */
    public function it_never_fires_when_every_row_was_rendered(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('Here are your five members.');

        $result = $this->generator->generate($this->truncatedContext(5));

        $this->assertArrayNotHasKey('truncation_honesty', $result['metadata']);
    }

    /** @test */
    public function the_patch_line_is_localized_by_the_qu_question_language(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->once() // Phase 8-B M4: fixable-only — patched, no retry
            ->andReturn('Voici les membres.');

        $context = $this->truncatedContext(100);
        $context->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'fr');

        $result = $this->generator->generate($context);

        $this->assertSame('patched', $result['metadata']['truncation_honesty'] ?? null);
        $this->assertStringContainsString('Affichage de 10 sur 100', $result['answer']);
    }

    /** @test */
    public function the_config_kill_switch_disables_the_guard(): void
    {
        config(['ai.response_generation.truncation_honesty' => false]);

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('Undisclosed truncated list.');

        $result = $this->generator->generate($this->truncatedContext(100));

        $this->assertSame('Undisclosed truncated list.', $result['answer']);
        $this->assertArrayNotHasKey('truncation_honesty', $result['metadata']);
    }

    /** @test */
    public function a_later_guards_retry_cannot_drop_the_disclosed_total(): void
    {
        // Composition purity (Phase 8-B: one validation pass): the draft discloses
        // the total but presents the user's email unmarked AND invents a figure, so
        // the combined retry runs; the retry marks the email but DROPS the total.
        // The final validation catches it and the disclosure is patched back.
        config(['ai.response_generation.self_reference_guard' => true]);
        $email = 'j.rousseau@novogestion.ca';

        $calls = [];
        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturnUsing(function ($user) use (&$calls, $email) {
                $calls[] = $user;
                return count($calls) === 1
                    ? "Your team has 100 members; 10 shown. Contact {$email} for details (dues 9,999.99 $)."
                    : "The listed email {$email} (that's you) is your own address."; // no 100, no 10, no 90
            });

        $result = $this->generator->generate(
            $this->truncatedContext(100),
            ['user' => (object) ['id' => 13, 'email' => $email]]
        );

        $this->assertSame('recovered', $result['metadata']['self_reference_guard'] ?? null);
        // The shipped answer STILL contains the total — re-appended deterministically.
        $this->assertMatchesRegularExpression('/(?<!\d)100(?!\d)/', $result['answer']);
        $this->assertSame('patched', $result['metadata']['truncation_honesty'] ?? null);
    }

    /** @test */
    public function a_remainder_equal_to_the_rendered_count_is_ambiguous_and_requires_the_total(): void
    {
        // 20 rows, 10 rendered: a draft naturally says "here are the 10 members"
        // — that literal 10 is the RENDERED count. Without the exact total (20)
        // the tightened rule (total AND shown-or-remainder) is not satisfied.
        $this->llmMock->shouldReceive('complete')
            ->once() // Phase 8-B M4: fixable-only — patched, no retry
            ->andReturn('Here are the 10 members of your team.');

        $result = $this->generator->generate($this->truncatedContext(20));

        $this->assertSame('patched', $result['metadata']['truncation_honesty'] ?? null, 'the ambiguous remainder is not a disclosure');
        $this->assertMatchesRegularExpression('/(?<!\d)20(?!\d)/', $result['answer']);
    }

    /** @test */
    public function a_locale_grouped_total_counts_as_disclosed(): void
    {
        // White-label hosts have totals >= 1000, commonly written with digit
        // grouping ("1,500"). The grouped form IS the total, disclosed.
        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('Showing the first 10 of 1,500 members.');

        $result = $this->generator->generate($this->truncatedContext(100, 1500));

        $this->assertSame('clean', $result['metadata']['truncation_honesty'] ?? null);
    }

    /** @test */
    public function metadata_reports_the_rendered_row_count_beside_the_result_total(): void
    {
        // The eval gate needs the LIVE rendered count (hosts may raise
        // setMaxDataItems) — result_shown is the generator's own evidence.
        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('Your team has 100 members; here are the first 10.');

        $result = $this->generator->generate($this->truncatedContext(100));

        $this->assertSame(10, $result['metadata']['result_shown'] ?? null);
    }
}
