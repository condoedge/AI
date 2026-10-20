<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\ResponseSections\ResultsDataSection;
use Condoedge\Ai\Tests\TestCase;

class ResultsDataSectionTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // The stats wording below is the NARRATIVE-ONLY path's (the model itself
        // discloses shown/total). The deterministic-table path is pinned in the
        // table-mode tests at the end of this class.
        config(['ai.response_generation.deterministic_tables' => false]);
    }

    /** @test Phase 8 batch A: compact masked pipe table, never pretty JSON. */
    public function the_summary_is_a_compact_masked_pipe_table(): void
    {
        config(['ai.access_control.identifying_fields' => ['*' => ['email']]]);
        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(success: true, data: [
            ['id' => 1, 'name' => 'Karen', 'email' => 'karen@x.test'],
            ['id' => 2, 'name' => 'Luc', 'email' => 'luc@x.test'],
        ]);

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringContainsString('| Name | Email |', $out);
        $this->assertStringContainsString('| Karen | [email#1] |', $out);
        $this->assertStringNotContainsString('karen@x.test', $out);
        $this->assertStringNotContainsString('"name":', $out);
        $this->assertStringNotContainsString('| Id |', $out, 'internal ids are redacted from the summary');
    }

    /** @test */
    public function table_mode_states_what_the_user_facing_table_shows(): void
    {
        config([
            'ai.response_generation.deterministic_tables' => true,
            'ai.response_generation.table_max_rows' => 25,
        ]);
        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $this->rows(100), metadata: ['true_total' => 250]);

        $out = (new ResultsDataSection())->format($ctx, ['style' => 'friendly']);

        $this->assertStringContainsString('the table the system shows the user holds 25 of 250', $out);
        $this->assertStringContainsString('{{table}}', $out);
        $this->assertStringNotContainsString('Showing first 10', $out, 'the 10-row summary is not the user-facing count');
    }

    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['name' => "Member {$i}", 'role' => 'Owner'];
        }

        return $rows;
    }

    /** @test */
    public function a_fan_out_shows_the_row_total_and_the_distinct_anchor_count_separately(): void
    {
        // Review I-1: "Showing first X of N" stays ROW-based; the distinct anchor
        // count is disclosed beside it for "how many <entities>" wording.
        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(
            success: true,
            data: $this->rows(100),
            metadata: ['true_total' => 400, 'anchor_total' => 100, 'anchor_variable' => 'm', 'anchor_label' => 'Member'],
        );

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringContainsString('Showing first 10 of 400 results', $out);
        $this->assertStringContainsString('these 400 rows cover 100 distinct Member records', $out);
    }

    /** @test */
    public function an_anchor_count_equal_to_the_row_total_emits_no_distinct_line(): void
    {
        // Task 1.27 (f), roster t0: "These 523 rows cover 523 distinct TeamRole
        // entities" — no fan-out, the line added nothing and the model echoed
        // "523 distinct roles". Only a real fan-out (anchor < rows) is disclosed.
        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(
            success: true,
            data: $this->rows(100),
            metadata: ['true_total' => 523, 'anchor_total' => 523, 'anchor_variable' => 'tr', 'anchor_label' => 'TeamRole'],
        );

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringContainsString('Showing first 10 of 523 results', $out);
        $this->assertStringNotContainsString('distinct', $out);
    }

    /** @test */
    public function the_fan_out_line_names_records_neutrally_with_the_configured_display_name(): void
    {
        config(['entities' => [
            'App\\Models\\Member' => ['graph' => ['label' => 'Member'], 'metadata' => ['display_name' => 'team member']],
        ]]);

        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(
            success: true,
            data: $this->rows(100),
            metadata: ['true_total' => 400, 'anchor_total' => 100, 'anchor_variable' => 'm', 'anchor_label' => 'Member'],
        );

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringContainsString(
            '(these 400 rows cover 100 distinct team member records — when stating how many team member records there are, use 100)',
            $out
        );
        $this->assertStringNotContainsString('entities', $out);
    }

    /** @test */
    public function the_fan_out_line_falls_back_to_the_raw_label(): void
    {
        config(['entities' => []]);

        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(
            success: true,
            data: $this->rows(100),
            metadata: ['true_total' => 400, 'anchor_total' => 100, 'anchor_variable' => 'u', 'anchor_label' => 'User'],
        );

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringContainsString('cover 100 distinct User records', $out);
    }

    /** @test */
    public function an_unlabeled_anchor_falls_back_to_entity_never_the_variable_name(): void
    {
        // Task 1.28 M3: `MATCH (m)-[:X]->(r:Role)` projects an unlabeled m — the
        // cypher variable name must never become the prompt noun.
        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(
            success: true,
            data: $this->rows(100),
            metadata: ['true_total' => 400, 'anchor_total' => 100, 'anchor_variable' => 'm', 'anchor_label' => null],
        );

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringContainsString('cover 100 distinct entity records', $out);
        $this->assertStringNotContainsString('distinct m ', $out);
    }

    /** @test */
    public function no_anchor_metadata_keeps_the_previous_output(): void
    {
        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $this->rows(100), metadata: ['true_total' => 250]);

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringContainsString('Showing first 10 of 250 results', $out);
        $this->assertStringNotContainsString('distinct', $out);
    }

    /** @test Task 5.8: StatisticsSection folded in — the row count of a complete result. */
    public function a_complete_result_states_its_row_count(): void
    {
        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $this->rows(3), stats: ['rows_returned' => 3]);

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringContainsString('(Rows returned: 3)', $out);
        $this->assertStringNotContainsString('Showing first', $out);
    }

    /** @test */
    public function a_truncated_result_states_the_total_once(): void
    {
        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $this->rows(100), stats: ['rows_returned' => 100], metadata: ['true_total' => 250]);

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringContainsString('Showing first 10 of 250 results', $out);
        $this->assertStringNotContainsString('Rows returned', $out);
    }

    /** @test */
    public function execution_time_is_only_shown_when_execution_stats_are_not_hidden(): void
    {
        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $this->rows(2), stats: ['execution_time_ms' => 12]);

        $this->assertStringNotContainsString('12ms', (new ResultsDataSection())->format($ctx));
        $this->assertStringContainsString('(Query execution time: 12ms)', (new ResultsDataSection())->format($ctx, ['hide_stats' => false]));

        config(['ai.response_generation.hide_execution_stats' => false]);
        $this->assertStringContainsString('(Query execution time: 12ms)', (new ResultsDataSection())->format($ctx));
    }

    /** @test */
    public function the_statistics_section_is_no_longer_registered(): void
    {
        $sections = config('ai.response_generator_sections');

        $this->assertContains(ResultsDataSection::class, $sections);
        $this->assertNotContains('Condoedge\\Ai\\Services\\ResponseSections\\StatisticsSection', $sections);
    }

    /**
     * @test
     * @dataProvider unavailableData
     */
    public function a_failed_or_blocked_data_part_is_never_rendered_as_no_data(QueryExecutionResult $result): void
    {
        // Focus-2: the data half of a mixed question could not be retrieved —
        // the prompt must forbid claiming there is no data.
        $ctx = new PipelineContext();
        $ctx->executionResult = $result;

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringNotContainsString('No data returned', $out);
        $this->assertStringContainsString('could not be retrieved', $out);
        $this->assertStringContainsString('do not claim there is no data', $out);
    }

    public static function unavailableData(): array
    {
        return [
            'execution failed' => [new QueryExecutionResult(success: false, errors: ['SyntaxError'])],
            'scoping safe-stop' => [new QueryExecutionResult(success: true, metadata: ['safe_stop' => true])],
        ];
    }

    /**
     * @test Eval p9-B digest t1 (2026-10-01): "When was our last meeting?" got
     * FACTS "Date: 2023-10-24 16:00" and the answer "Your last meeting is
     * scheduled for October 24, 2023" — future tense on a past date. The
     * response prompt carried no notion of today, so tense was a guess. The
     * system now computes, in code, whether each date fact is past / today /
     * future and states it beside the data.
     */
    public function a_single_row_date_fact_is_tagged_past_or_future_relative_to_today(): void
    {
        \Carbon\Carbon::setTestNow('2026-10-01 09:00:00');
        try {
            $ctx = new PipelineContext();
            $ctx->executionResult = new QueryExecutionResult(success: true, data: [
                ['name' => 'AGM 1240', 'date' => '2023-10-24 16:00:00', 'next_date' => '2027-01-15T05:00:00.000000Z', 'meeting_id' => 140],
            ]);

            $out = (new ResultsDataSection())->format($ctx);
        } finally {
            \Carbon\Carbon::setTestNow();
        }

        $this->assertStringContainsString('Today is 2026-10-01', $out);
        $this->assertMatchesRegularExpression('/- Date: 2023-10-24[^\n]*PAST/', $out);
        $this->assertMatchesRegularExpression('/- Next Date: 2027-01-15[^\n]*FUTURE/', $out);
        $this->assertStringNotContainsString('AGM 1240: ', $out, 'non-date columns get no tense line');
    }

    /**
     * @test The tense line names a date by the SAME label the FACTS block gives it — the
     * property when the alias shares no word with it (`m.start_date AS held`), so the model
     * can pair the two lines.
     */
    public function the_tense_line_names_the_date_as_the_facts_do(): void
    {
        \Carbon\Carbon::setTestNow('2026-10-01 09:00:00');
        try {
            $ctx = new PipelineContext();
            $ctx->qu = new \Condoedge\Ai\DTOs\QueryUnderstandingResult(questionType: 'data_query', language: 'en');
            $ctx->queryResult = new \Condoedge\Ai\DTOs\QueryGenerationResult(
                cypher: 'MATCH (m:Meeting) RETURN m.name AS meeting_name, m.start_date AS held, m.id AS meeting_id ORDER BY m.start_date DESC LIMIT 1'
            );
            $ctx->executionResult = new QueryExecutionResult(success: true, data: [
                ['meeting_name' => 'AGM 1240', 'held' => '2023-10-24 16:00:00', 'meeting_id' => 140],
            ]);

            $out = (new ResultsDataSection())->format($ctx);
        } finally {
            \Carbon\Carbon::setTestNow();
        }

        $this->assertStringContainsString('- Start Date: 2023-10-24 16:00', $out);
        $this->assertMatchesRegularExpression('/- Start Date: 2023-10-24[^\n]*PAST/', $out);
        $this->assertStringNotContainsString('- Held:', $out);
    }

    /** @test A date on today's day is "today", neither past nor future. */
    public function a_date_on_today_is_tagged_today(): void
    {
        \Carbon\Carbon::setTestNow('2026-10-01 09:00:00');
        try {
            $ctx = new PipelineContext();
            $ctx->executionResult = new QueryExecutionResult(success: true, data: [
                ['due_at' => '2026-10-01', 'amount' => 12.5],
            ]);

            $out = (new ResultsDataSection())->format($ctx);
        } finally {
            \Carbon\Carbon::setTestNow();
        }

        $this->assertMatchesRegularExpression('/- Due At: 2026-10-01[^\n]*TODAY/', $out);
    }

    /**
     * @test E2: the tense day is the day the user reads (app timezone) — "2026-10-02T02:00Z" is
     * 2026-10-01 22:00 in America/New_York: TODAY, and the stated value is that local date-time.
     */
    public function the_tense_day_is_the_local_day_the_value_is_shown_as(): void
    {
        config(['app.timezone' => 'America/New_York']);
        \Carbon\Carbon::setTestNow('2026-10-01 09:00:00');
        try {
            $ctx = new PipelineContext();
            $ctx->executionResult = new QueryExecutionResult(success: true, data: [
                ['name' => 'AGM', 'start_at' => '2026-10-02T02:00:00Z'],
            ]);

            $out = (new ResultsDataSection())->format($ctx);
        } finally {
            \Carbon\Carbon::setTestNow();
        }

        $this->assertMatchesRegularExpression('/- Start At: 2026-10-01 22:00[^\n]*TODAY/', $out);
    }

    /** @test Multi-row results: today's date is stated so row dates can be told apart. */
    public function a_multi_row_result_with_dates_states_today(): void
    {
        \Carbon\Carbon::setTestNow('2026-10-01 09:00:00');
        try {
            $ctx = new PipelineContext();
            $ctx->executionResult = new QueryExecutionResult(success: true, data: [
                ['name' => 'A', 'start_date' => '2023-10-24 16:00:00'],
                ['name' => 'B', 'start_date' => '2027-10-24 16:00:00'],
            ]);

            $out = (new ResultsDataSection())->format($ctx);
        } finally {
            \Carbon\Carbon::setTestNow();
        }

        $this->assertStringContainsString('Today is 2026-10-01', $out);
    }

    /** @test No date in the rows: no tense block at all (zero tokens). */
    public function a_result_without_dates_gets_no_tense_block(): void
    {
        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(success: true, data: [
            ['name' => 'Karen', 'amount' => 12.5, 'code' => '2023'],
        ]);

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringNotContainsString('Today is', $out);
    }

    /** @test A masked (sensitive) date never leaks through the tense block. */
    public function a_masked_date_column_is_not_restated(): void
    {
        config(['ai.access_control.identifying_fields' => ['*' => ['dob']]]);
        $ctx = new PipelineContext();
        $ctx->executionResult = new QueryExecutionResult(success: true, data: [
            ['name' => 'Karen', 'dob' => '1980-05-02'],
        ]);

        $out = (new ResultsDataSection())->format($ctx);

        $this->assertStringNotContainsString('1980-05-02', $out);
    }
}
