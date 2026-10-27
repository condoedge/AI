<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Admin;

use Carbon\Carbon;
use Condoedge\Ai\Services\Admin\ConversationInsights;
use Condoedge\Ai\Services\Admin\InsightsRange;
use Condoedge\Ai\Tests\Fixtures\AdminInsightsSeed;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;

/**
 * The numbers behind the admin "AI conversations & feedback" page: KPIs, the feedback
 * list, the conversations filters and the per-answer trace summary — on a known corpus
 * (see AdminInsightsSeed) in an in-memory SQLite with the package migrations.
 */
class ConversationInsightsTest extends TestCase
{
    use AdminInsightsSeed;

    private ConversationInsights $insights;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createHostTables();
        $this->seedInsights();
        $this->insights = new ConversationInsights();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function range(?string $days = null, ?string $from = null, ?string $to = null): InsightsRange
    {
        return InsightsRange::fromInput($days, $from, $to);
    }

    /** @test */
    public function the_range_defaults_to_the_last_30_days_and_accepts_presets_and_explicit_dates(): void
    {
        $default = $this->range();
        $this->assertSame(30, $default->days);
        $this->assertSame('2026-09-22 00:00:00', $default->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 23:59:59', $default->to->format('Y-m-d H:i:s'));

        $this->assertSame(7, $this->range('7')->days);
        $this->assertSame(90, $this->range('90')->days);
        $this->assertSame(30, $this->range('13')->days, 'only the presets are accepted');

        $explicit = $this->range(null, '2026-10-01', '2026-10-05');
        $this->assertNull($explicit->days);
        $this->assertSame('2026-10-01 00:00:00', $explicit->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 23:59:59', $explicit->to->format('Y-m-d H:i:s'));

        // Garbage / inverted dates fall back to the default.
        $this->assertSame(30, $this->range(null, 'nope', '2026-10-05')->days);
        $this->assertSame(30, $this->range(null, '2026-10-05', '2026-10-01')->days);
    }

    /** @test */
    public function kpis_count_the_visible_corpus_in_the_default_range(): void
    {
        $k = $this->insights->kpis($this->range());

        $this->assertSame(3, $k['conversations'], 'A, B, F — not the old, eval or deleted ones');
        $this->assertSame(5, $k['questions']);
        $this->assertSame(3, $k['active_users']);
        $this->assertSame(5, $k['answers']);
        $this->assertSame(1, $k['positive']);
        $this->assertSame(1, $k['negative']);
        $this->assertSame(2, $k['rated']);
        $this->assertSame(50.0, $k['satisfaction_pct']);
        $this->assertSame(40.0, $k['rated_pct']);
        $this->assertSame(2, $k['errors'], 'a3 (metadata.error) + a4 (composition branch error)');
        $this->assertSame(40.0, $k['error_pct']);
        $this->assertSame(850, $k['tokens']);
        $this->assertSame(10, $k['llm_calls']);
        $this->assertSame(4, $k['traced_answers']);
        // Latencies 500, 1000, 2000 (execution_time_ms fallback), 3000, 4000 — nearest rank.
        $this->assertSame(5, $k['latency_samples']);
        $this->assertEquals(2000, $k['latency_median_ms']);
        $this->assertEquals(4000, $k['latency_p90_ms']);
    }

    /** @test */
    public function kpis_follow_the_range(): void
    {
        $narrow = $this->insights->kpis($this->range(null, '2026-10-18', '2026-10-21'));
        $this->assertSame(2, $narrow['conversations'], 'A and F');
        $this->assertSame(3, $narrow['questions']);
        $this->assertSame(3, $narrow['answers']);
        $this->assertSame(0, $narrow['errors']);

        $wide = $this->insights->kpis($this->range('90'));
        $this->assertSame(4, $wide['conversations']);
        $this->assertSame(2, $wide['positive']);
    }

    /** @test */
    public function kpis_on_an_empty_range_are_zero_and_ratios_are_null(): void
    {
        $k = $this->insights->kpis($this->range(null, '2025-01-01', '2025-01-31'));

        foreach (['conversations', 'questions', 'active_users', 'answers', 'positive', 'negative', 'rated', 'errors', 'tokens', 'llm_calls', 'latency_samples'] as $key) {
            $this->assertSame(0, $k[$key], $key);
        }
        $this->assertNull($k['satisfaction_pct']);
        $this->assertNull($k['rated_pct']);
        $this->assertNull($k['error_pct']);
        $this->assertNull($k['latency_median_ms']);
        $this->assertNull($k['latency_p90_ms']);
    }

    /** @test */
    public function kpis_run_in_a_bounded_number_of_queries(): void
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->insights->kpis($this->range());

        $this->assertLessThanOrEqual(2, $count, 'KPIs are aggregated in SQL, not per row');
    }

    /** @test */
    public function the_feedback_list_puts_negative_first_and_joins_each_answer_to_the_question_before_it(): void
    {
        $rows = $this->insights->feedbackQuery($this->range())->get();

        $this->assertSame([$this->messages['a1']->id, $this->messages['a2']->id], $rows->pluck('id')->map(fn ($v) => (int) $v)->all());

        $this->assertSame('negative', $rows[0]->feedback);
        $this->assertSame('How many units?', $rows[0]->question);
        $this->assertSame('And owners?', $rows[1]->question, 'the PRECEDING user message, not the first one');
        $this->assertSame('Alice Martin', $rows[0]->user_name);
        $this->assertSame('Les Jardins', $rows[0]->team_name, 'falls back to the user\'s current team');
        $this->assertSame($this->conversations['A']->uuid, $rows[0]->conversation_uuid);
    }

    /** @test */
    public function the_feedback_list_filters_by_kind_and_ignores_eval_and_deleted_conversations(): void
    {
        $positive = $this->insights->feedbackQuery($this->range('90'), 'positive')->get();
        $this->assertEqualsCanonicalizing(
            [$this->messages['a2']->id, $this->messages['a5']->id],
            $positive->pluck('id')->map(fn ($v) => (int) $v)->all()
        );

        $negative = $this->insights->feedbackQuery($this->range('90'), 'negative')->get();
        $this->assertSame([$this->messages['a1']->id], $negative->pluck('id')->map(fn ($v) => (int) $v)->all(), 'a6 (eval) and a7 (deleted) are excluded');
    }

    private function conversationTitles(array $filters): array
    {
        return $this->insights->conversationsQuery($this->range($filters['days'] ?? null, $filters['from'] ?? null, $filters['to'] ?? null), $filters)
            ->get()->pluck('title')->sort()->values()->all();
    }

    /** @test */
    public function the_conversations_table_filters(): void
    {
        $this->assertSame(['Bills', 'Digest <b>bold</b>', 'Units question'], $this->conversationTitles([]));
        $this->assertSame(['Units question'], $this->conversationTitles(['feedback' => 'negative']));
        $this->assertSame(['Units question'], $this->conversationTitles(['feedback' => 'positive']));
        $this->assertSame(['Units question'], $this->conversationTitles(['feedback' => 'any']));
        $this->assertSame(['Bills', 'Digest <b>bold</b>'], $this->conversationTitles(['feedback' => 'none']));
        $this->assertSame(['Digest <b>bold</b>', 'Units question'], $this->conversationTitles(['from' => '2026-10-18', 'to' => '2026-10-21']));
        $this->assertSame(['Bills'], $this->conversationTitles(['text' => 'my bills']), 'message text');
        $this->assertSame(['Units question'], $this->conversationTitles(['text' => 'units question']), 'title');
        $this->assertSame([], $this->conversationTitles(['text' => '%']), 'LIKE wildcards are literal');
        $this->assertSame(['Bills'], $this->conversationTitles(['user' => 'bob']));
        $this->assertSame(['Bills'], $this->conversationTitles(['user' => 'bob@example']));
        $this->assertSame(['Digest <b>bold</b>', 'Units question'], $this->conversationTitles(['team' => '10']));
        $this->assertSame(['Old one'], array_values(array_diff($this->conversationTitles(['days' => '90']), $this->conversationTitles([]))));
    }

    /** @test */
    public function the_conversations_table_filters_regenerated_unsourced_and_slow_answers(): void
    {
        $this->assertSame(['Units question'], $this->conversationTitles(['answers' => 'regenerated']), 'a2 only: regenerated = false is not');
        $this->assertSame(['Bills', 'Units question'], $this->conversationTitles(['answers' => 'no_sources']), 'only a8 cites a document');
        $this->assertSame([], $this->conversationTitles(['answers' => 'slow']), 'nothing above the default 20 s');

        config(['ai.admin.slow_answer_ms' => 2500]);
        $this->assertSame(['Digest <b>bold</b>', 'Units question'], $this->conversationTitles(['answers' => 'slow']), 'a2 3 s, a8 4 s');
        $this->assertSame(['Bills', 'Digest <b>bold</b>', 'Units question'], $this->conversationTitles(['answers' => 'nope']));
    }

    /** @test */
    public function kpis_per_audience_count_answers_ratings_unsourced_share_and_average_latency(): void
    {
        $rows = $this->insights->audienceKpis($this->range());

        $this->assertSame([
            ['audience' => 'members', 'conversations' => 1, 'answers' => 2, 'positive' => 1, 'negative' => 1, 'no_sources' => 2, 'no_source_pct' => 100.0, 'latency_avg_ms' => 2000.0],
            ['audience' => 'staff', 'conversations' => 1, 'answers' => 1, 'positive' => 0, 'negative' => 0, 'no_sources' => 0, 'no_source_pct' => 0.0, 'latency_avg_ms' => 4000.0],
            // a3 (no stamp) and a4 (stamped, unresolved): latency 2000 (execution_time_ms) and 500.
            ['audience' => null, 'conversations' => 1, 'answers' => 2, 'positive' => 0, 'negative' => 0, 'no_sources' => 2, 'no_source_pct' => 100.0, 'latency_avg_ms' => 1250.0],
        ], $rows);

        $this->assertSame([], $this->insights->audienceKpis($this->range(null, '2025-01-01', '2025-01-31')));
    }

    /** @test */
    public function the_conversations_table_carries_counts_user_team_and_last_activity(): void
    {
        $row = $this->insights->conversationsQuery($this->range(), ['text' => 'units'])->first();

        $this->assertSame(4, (int) $row->messages_count);
        $this->assertSame(1, (int) $row->positive_count);
        $this->assertSame(1, (int) $row->negative_count);
        $this->assertSame('Alice Martin', $row->user_name);
        $this->assertSame('Les Jardins', $row->team_name);
        $this->assertSame('2026-10-19 12:03:00', Carbon::parse($row->last_activity)->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function team_options_list_the_teams_seen_in_visible_conversations(): void
    {
        $this->assertSame([10 => 'Les Jardins', 20 => 'Tour B'], $this->insights->teamOptions());
    }

    /** @test */
    public function the_transcript_is_found_by_uuid_only_for_visible_conversations(): void
    {
        $transcript = $this->insights->transcript($this->conversations['A']->uuid);
        $this->assertNotNull($transcript);
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], $transcript['messages']->pluck('role')->all());
        $this->assertSame('Alice Martin', $transcript['conversation']->user_name);

        $this->assertNull($this->insights->transcript($this->conversations['D']->uuid), 'eval');
        $this->assertNull($this->insights->transcript($this->conversations['E']->uuid), 'deleted');
        $this->assertNull($this->insights->transcript((string) $this->conversations['A']->id), 'ids are not accepted');
    }

    /** @test */
    public function the_trace_summary_reads_route_usage_latency_errors_and_file_names(): void
    {
        $error = ConversationInsights::traceSummary($this->messages['a4']->metadata, null);
        $this->assertSame('error', $error['route']);
        $this->assertSame(1, $error['llm_calls']);
        $this->assertSame(50, $error['tokens']);
        $this->assertEquals(500, $error['latency_ms']);
        $this->assertTrue($error['is_error']);
        $this->assertSame(['execution: boom'], $error['errors']);

        $legacy = ConversationInsights::traceSummary($this->messages['a3']->metadata, 2000);
        $this->assertNull($legacy['route']);
        $this->assertTrue($legacy['is_error']);
        $this->assertEquals(2000, $legacy['latency_ms']);

        $digest = ConversationInsights::traceSummary($this->messages['a8']->metadata, null);
        $this->assertSame('digest', $digest['route']);
        $this->assertFalse($digest['is_error']);
        $this->assertSame(['minutes-2026.pdf', 'rules.md'], $digest['files']);

        $none = ConversationInsights::traceSummary(null, null);
        $this->assertNull($none['route']);
        $this->assertNull($none['latency_ms']);
        $this->assertSame([], $none['files']);
    }
}
