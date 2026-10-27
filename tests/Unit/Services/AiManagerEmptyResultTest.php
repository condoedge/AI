<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Eval p16 A#3 ("When is our next meeting?") and B#1 ("So how much is my
 * contribution this year?") — an EMPTY result is explained from the EXECUTED
 * query, through the scoped executor, with no extra LLM call:
 *
 *  - a TEAM-scoped empty whose entity has ONE host-declared attention source with
 *    a labeled fallback is answered from the fallback rows (data path, framed as
 *    fallback) once the source's own query confirmed there is nothing;
 *  - the abstention's scope is the executed query's (own vs team), never QU's
 *    possessive flag;
 *  - an own-data empty carries the ownership count as a fact.
 */
class AiManagerEmptyResultTest extends TestCase
{
    private const NEXT_MEETING = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids AND m.start_date > toString(date()) '
        . 'WITH m ORDER BY m.start_date ASC LIMIT 1 OPTIONAL MATCH (s:Schedule)-[:BELONGS_TO_MEETING]->(m) '
        . 'RETURN m.name AS meeting_name, m.start_date AS start_date, collect(s.title) AS agenda';

    private const PRIMARY = 'MATCH (m:Meeting) WHERE m.start_date >= toString(date()) RETURN m.id AS id, m.name AS name, m.start_date AS start_date ORDER BY m.start_date ASC';
    private const FALLBACK = 'MATCH (m:Meeting) WHERE m.start_date < toString(date()) RETURN m.id AS id, m.name AS name, m.start_date AS start_date ORDER BY m.start_date DESC';

    private const CONTRIBUTION = "MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL "
        . "MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE substring(i.invoiced_at, 0, 4) = '2026' "
        . 'RETURN i.invoice_number AS invoice_number, i.due_amount AS contribution LIMIT 100';

    private const UNIT_COUNT = 'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(n:Unit) WHERE cu.sold_at IS NULL RETURN count(DISTINCT n) AS total';

    private AiManager $manager;
    private $mockContext;
    private $mockQueryGenerator;
    private $mockQueryExecutor;
    private $mockResponseGenerator;

    /** @var list<array{0: string, 1: array<string, mixed>}> every executed query with its options, in order */
    private array $executed = [];

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.query_execution.default_limit', 100);
        config()->set('ai.attached_actions.enabled', false);
        config()->set('ai.attached_actions.project_record_ids', false);
        config()->set('ai.attention_sources', [
            'upcoming_meetings' => [
                'label' => 'Upcoming meetings',
                'cypher' => self::PRIMARY,
                'fallback_cypher' => self::FALLBACK,
                'fallback_label' => 'Most recent meetings — none scheduled',
                'limit' => 3,
                'entity' => 'Meeting',
                'action_key' => 'meetings',
            ],
        ]);
        config()->set('ai.user_entity_paths', [
            'Unit' => ['cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(n:Unit) WHERE cu.sold_at IS NULL'],
            'Contact' => ['cypher' => 'MATCH (n:Contact {user_id: $userId})'],
        ]);
        config()->set('entities', [
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['display_name' => 'unit']],
        ]);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            Mockery::mock(VectorStoreInterface::class)
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param array<string, mixed> $qu QueryUnderstandingResult named args */
    private function turn(string $cypher, array $qu): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(...($qu + ['questionType' => 'data_query', 'intent' => 'lookup', 'language' => 'en']));
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: $cypher, explanation: '', confidence: 0.9));
    }

    /**
     * @param array<string, QueryExecutionResult|\Throwable> $results query text => its result (default: scoped empty)
     */
    private function executor(array $results = []): void
    {
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(function ($query, $params, $options) use ($results) {
                $this->executed[] = [(string) $query, $options];
                $result = $results[(string) $query] ?? new QueryExecutionResult(success: true, data: [], metadata: ['security_filter_applied' => true]);
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            });
        $this->mockQueryExecutor->shouldReceive('executeCount')->never();
    }

    /** @return list<string> */
    private function executedQueries(): array
    {
        return array_column($this->executed, 0);
    }

    private function rows(array $rows): QueryExecutionResult
    {
        return new QueryExecutionResult(success: true, data: $rows, metadata: ['security_filter_applied' => true]);
    }

    /** @return list<array<string, mixed>> */
    private function pastMeetings(): array
    {
        return [
            ['id' => 140, 'name' => 'AGA 2023', 'start_date' => '2023-10-24 16:00:00'],
            ['id' => 131, 'name' => 'CA septembre', 'start_date' => '2023-09-12 18:00:00'],
            ['id' => 120, 'name' => 'CA juin', 'start_date' => '2023-06-06 18:00:00'],
        ];
    }

    private function expectEmptyResponse(?array &$options): void
    {
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->once()
            ->andReturnUsing(function ($question, $cypher, $opts) use (&$options) {
                $options = $opts;

                return ['answer' => 'nothing', 'format' => 'text', 'metadata' => ['empty_result' => true]];
            });
    }

    private function expectDataResponse(?PipelineContext &$captured): void
    {
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->once()
            ->andReturnUsing(function (PipelineContext $ctx) use (&$captured) {
                $captured = $ctx;

                return ['answer' => 'ok', 'format' => 'text', 'metadata' => []];
            });
    }

    private function ask(string $question = 'q', array $options = []): array
    {
        return $this->manager->answerQuestion($question, $options + ['user' => (object) ['id' => 13], 'store_examples' => false]);
    }

    // ------------------------------------------------------------ E1 fallback

    /** @test */
    public function an_empty_upcoming_result_is_answered_from_the_hosts_labeled_fallback(): void
    {
        $this->turn(self::NEXT_MEETING, ['entityTypes' => ['Meeting', 'Schedule'], 'isPossessive' => true, 'searchQuery' => 'next meeting']);
        $this->executor([self::FALLBACK => $this->rows($this->pastMeetings())]);
        $captured = null;
        $this->expectDataResponse($captured);

        $result = $this->ask('When is our next meeting?');

        $this->assertSame([self::NEXT_MEETING, self::PRIMARY, self::FALLBACK], $this->executedQueries(), 'the turn\'s query, then the source\'s own query, then its fallback');
        $batches = array_unique(array_map(static fn (array $e) => $e[1]['rate_limit_batch'] ?? null, $this->executed));
        $this->assertCount(1, $batches, 'all three ride the turn\'s single rate-limit charge');
        $this->assertNotNull($batches[0]);
        foreach ($this->executed as [, $options]) {
            $this->assertSame(13, $options['user']->id);
            $this->assertArrayNotHasKey('skip_security', $options);
        }
        $this->assertSame(3, $this->executed[2][1]['limit'], 'the source\'s own row cap');

        // The fallback rows go down the NORMAL data path, stamped as fallback.
        $this->assertSame($this->pastMeetings(), $captured->executionResult->data);
        $this->assertSame([
            'source' => 'upcoming_meetings',
            'label' => 'Upcoming meetings',
            'fallback_label' => 'Most recent meetings — none scheduled',
            'entity' => 'Meeting',
            'action_key' => 'meetings',
        ], $captured->executionResult->metadata['empty_fallback']);
        $this->assertTrue($captured->executionResult->metadata['security_filter_applied']);

        // The fallback query — WITH the source's row cap — is the turn's query: a follow-up
        // export re-runs exactly what was shown, never every past meeting.
        $this->assertSame(self::FALLBACK . ' LIMIT 3', $captured->queryResult->cypher);
        $this->assertSame(self::NEXT_MEETING, $captured->queryResult->metadata['pre_fallback_cypher']);
        $this->assertSame(self::FALLBACK . ' LIMIT 3', $result['cypher']);
        $this->assertSame([], $result['cypher_parameters']);
        $this->assertSame($this->pastMeetings(), $result['data']);
        $this->assertSame('upcoming_meetings', $result['metadata']['execution']['empty_fallback']['source']);
    }

    /** @test */
    public function no_fallback_when_the_sources_own_query_finds_rows(): void
    {
        // Upcoming meetings exist: the empty question was narrower. "None scheduled" would be false.
        $this->turn(self::NEXT_MEETING, ['entityTypes' => ['Meeting'], 'isPossessive' => true, 'searchQuery' => 'next meeting']);
        $this->executor([self::PRIMARY => $this->rows([['id' => 200, 'name' => 'AGA 2027', 'start_date' => '2027-01-10 18:00:00']])]);
        $options = null;
        $this->expectEmptyResponse($options);

        $result = $this->ask('When is our next meeting with the insurer?');

        $this->assertSame([self::NEXT_MEETING, self::PRIMARY], $this->executedQueries());
        $this->assertSame(self::NEXT_MEETING, $result['cypher']);
        $this->assertArrayNotHasKey('empty_fallback', $result['metadata']['execution']);
    }

    /** @test */
    public function no_fallback_when_no_source_matches_the_querys_entity(): void
    {
        $bills = 'MATCH (b:Bill) WHERE b.team_id IN $ai_team_ids AND b.status = "2" RETURN b.bill_number AS bill_number';
        $this->turn($bills, ['entityTypes' => ['Bill'], 'searchQuery' => 'bills awaiting approval']);
        $this->executor();
        $options = null;
        $this->expectEmptyResponse($options);

        $this->ask('Any bills awaiting approval?');

        $this->assertSame([$bills], $this->executedQueries(), 'no source declares Bill: nothing else runs');
    }

    /** @test */
    public function no_fallback_when_several_sources_declare_the_entity(): void
    {
        config()->set('ai.attention_sources.board_meetings', [
            'label' => 'Board meetings', 'cypher' => 'MATCH (m:Meeting) RETURN m.id AS id', 'entity' => 'Meeting',
        ]);
        $this->turn(self::NEXT_MEETING, ['entityTypes' => ['Meeting'], 'isPossessive' => true, 'searchQuery' => 'next meeting']);
        $this->executor([self::FALLBACK => $this->rows($this->pastMeetings())]);
        $options = null;
        $this->expectEmptyResponse($options);

        $this->ask('When is our next meeting?');

        $this->assertSame([self::NEXT_MEETING], $this->executedQueries(), 'ambiguous: which source the empty query stands for is unknown');
    }

    /** @test */
    public function an_export_ask_never_exports_fallback_rows(): void
    {
        $this->turn(self::NEXT_MEETING, ['entityTypes' => ['Meeting'], 'exportFormat' => 'excel', 'searchQuery' => 'upcoming meetings']);
        $this->executor([self::FALLBACK => $this->rows($this->pastMeetings())]);
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')
            ->andReturn(['answer' => 'nothing', 'format' => 'text', 'metadata' => []]);

        $this->ask('Give me an excel of our upcoming meetings');

        $this->assertSame([self::NEXT_MEETING], $this->executedQueries(), 'a file titled by the question must not carry other rows');
    }

    /** @test */
    public function a_failed_execution_is_not_an_empty_result_and_runs_no_check(): void
    {
        $this->turn(self::NEXT_MEETING, ['entityTypes' => ['Meeting'], 'searchQuery' => 'next meeting']);
        $this->executor([self::NEXT_MEETING => new QueryExecutionResult(success: true, data: [], metadata: ['safe_stop' => true])]);
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = $this->ask('When is our next meeting?');

        $this->assertSame([self::NEXT_MEETING], $this->executedQueries());
        $this->assertSame('blocked_by_scoping', $result['metadata']['response']['status']);
    }

    /** @test A data question the query writer declined ran no lookup: the abstention is told so. */
    public function a_declined_data_question_is_abstained_as_not_in_the_data(): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', intent: 'aggregate', entityTypes: ['Transfer'], language: 'fr');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: '', explanation: '', confidence: 1.0, metadata: ['no_query_reason' => 'Not recorded.']));
        $this->executor();
        $options = null;
        $this->expectEmptyResponse($options);

        $this->ask('C\'est quoi le total des mises?');

        $this->assertTrue($options['not_in_data'] ?? false);
    }

    // --------------------------------------------------------------- E1 scope

    /** @test */
    public function the_abstention_scope_is_the_executed_querys_not_the_possessive_flag(): void
    {
        // No fallback source for this entity → the abstention. QU said possessive ("our"),
        // but the query that ran was team-scoped.
        config()->set('ai.attention_sources', []);
        $this->turn(self::NEXT_MEETING, ['entityTypes' => ['Meeting'], 'isPossessive' => true, 'searchQuery' => 'next meeting']);
        $this->executor();
        $options = null;
        $this->expectEmptyResponse($options);

        $result = $this->ask('When is our next meeting?');

        $this->assertSame('team', $options['checked_scope']);
        $this->assertFalse($options['checked_own_data'], 'never "your OWN linked records" for a team-wide query');
        $this->assertNull($options['ownership_check']);
        $this->assertSame('next meeting', $options['checked_subject']);
        $this->assertSame('team', $result['metadata']['execution']['empty_scope']);
    }

    // ----------------------------------------------------------- E2 ownership

    /** @test */
    public function an_own_data_empty_carries_the_ownership_count_as_a_fact(): void
    {
        // Even a host fallback source for the query's entity must not run here: its rows
        // would be the TEAM's, not this user's own.
        config()->set('ai.attention_sources.overdue', [
            'label' => 'Overdue', 'cypher' => 'MATCH (i:Invoice) RETURN i.id AS id',
            'fallback_cypher' => 'MATCH (i:Invoice) RETURN i.id AS id ORDER BY i.due_at DESC', 'fallback_label' => 'Latest invoices',
            'entity' => 'Invoice',
        ]);
        $this->turn(self::CONTRIBUTION, ['entityTypes' => ['Invoice', 'Union'], 'isPossessive' => true, 'searchQuery' => 'annual contribution']);
        $this->executor([self::UNIT_COUNT => new QueryExecutionResult(success: true, data: [['total' => 0]], metadata: ['security_filter_applied' => true])]);
        $options = null;
        $this->expectEmptyResponse($options);

        $result = $this->ask('So how much is my contribution this year?');

        $this->assertSame([self::CONTRIBUTION, self::UNIT_COUNT], $this->executedQueries(), 'ONE extra, deterministic execution');
        $this->assertSame($this->executed[0][1]['rate_limit_batch'], $this->executed[1][1]['rate_limit_batch']);
        $this->assertSame('own', $options['checked_scope']);
        $this->assertTrue($options['checked_own_data']);
        $this->assertSame(['entity' => 'Unit', 'display_name' => 'unit', 'count' => 0], $options['ownership_check']);
        $this->assertSame(self::CONTRIBUTION, $result['cypher'], 'the turn\'s query is unchanged');
        $this->assertSame('own', $result['metadata']['execution']['empty_scope']);
        $this->assertSame(0, $result['metadata']['execution']['ownership_check']['count']);
    }

    /** @test */
    public function the_own_data_scope_does_not_need_the_possessive_flag(): void
    {
        $this->turn(self::CONTRIBUTION, ['entityTypes' => ['Invoice'], 'isPossessive' => false, 'searchQuery' => 'annual contribution']);
        $this->executor([self::UNIT_COUNT => new QueryExecutionResult(success: true, data: [['total' => 2]])]);
        $options = null;
        $this->expectEmptyResponse($options);

        $this->ask('How much is the contribution this year?');

        $this->assertSame('own', $options['checked_scope']);
        $this->assertSame(2, $options['ownership_check']['count']);
    }

    /** @test */
    public function a_failing_ownership_count_leaves_the_plain_own_data_abstention(): void
    {
        $this->turn(self::CONTRIBUTION, ['entityTypes' => ['Invoice'], 'isPossessive' => true, 'searchQuery' => 'annual contribution']);
        $this->executor([self::UNIT_COUNT => new \RuntimeException('rate limited')]);
        $options = null;
        $this->expectEmptyResponse($options);

        $this->ask('So how much is my contribution this year?');

        $this->assertSame('own', $options['checked_scope']);
        $this->assertNull($options['ownership_check']);
    }

    // ------------------------------------------- E2: aggregate over nothing

    private const CONTRIBUTION_SUM = "MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL "
        . "MATCH (n:Invoice)-[:CUSTOMER]->(u) WHERE substring(n.invoiced_at, 0, 4) = '2026' "
        . 'RETURN sum(n.total_amount) AS contribution_amount';

    /** @test */
    public function an_own_data_total_of_zero_is_not_a_figure_when_the_user_owns_nothing_to_total(): void
    {
        // Live 2026-10-06: the generator wrote the question as a SUM → one row {0} →
        // "Your contribution amount for this year is $0.00." for a user who owns no unit.
        $this->turn(self::CONTRIBUTION_SUM, ['entityTypes' => ['Invoice', 'Union'], 'isPossessive' => true, 'searchQuery' => 'annual contribution']);
        $this->executor([
            self::CONTRIBUTION_SUM => $this->rows([['contribution_amount' => 0]]),
            self::UNIT_COUNT => $this->rows([['total' => 0]]),
        ]);
        $options = null;
        $this->expectEmptyResponse($options);

        $result = $this->ask('So how much is my contribution this year?');

        $this->assertSame([self::CONTRIBUTION_SUM, self::UNIT_COUNT], $this->executedQueries());
        $this->assertSame('own', $options['checked_scope']);
        $this->assertSame(['entity' => 'Unit', 'display_name' => 'unit', 'count' => 0], $options['ownership_check']);
        $this->assertSame([], $result['data'], 'the vacuous total is not served as data');
        $this->assertSame([['contribution_amount' => 0]], $result['metadata']['execution']['aggregate_over_nothing']);
    }

    /** @test */
    public function an_own_data_total_of_zero_stands_when_the_user_does_own_something(): void
    {
        $this->turn(self::CONTRIBUTION_SUM, ['entityTypes' => ['Invoice'], 'isPossessive' => true, 'intent' => 'aggregate', 'searchQuery' => 'annual contribution']);
        $this->executor([
            self::CONTRIBUTION_SUM => $this->rows([['contribution_amount' => 0]]),
            self::UNIT_COUNT => $this->rows([['total' => 2]]),
        ]);
        $captured = null;
        $this->expectDataResponse($captured);

        $result = $this->ask('So how much is my contribution this year?');

        $this->assertSame([['contribution_amount' => 0]], $result['data'], 'a real $0 for an owner is the answer');
        $this->assertArrayNotHasKey('aggregate_over_nothing', $captured->executionResult->metadata);
        $this->assertArrayNotHasKey('empty_scope', $captured->executionResult->metadata);
    }

    /** @test */
    public function a_team_wide_total_of_zero_is_never_second_guessed(): void
    {
        $teamSum = 'MATCH (i:Invoice) WHERE i.team_id IN $ai_team_ids AND i.due_amount >= 0.01 RETURN sum(i.due_amount) AS total_due, count(i) AS invoices';
        $this->turn($teamSum, ['entityTypes' => ['Invoice'], 'intent' => 'aggregate', 'searchQuery' => 'total receivables']);
        $this->executor([$teamSum => $this->rows([['total_due' => 0, 'invoices' => 0]])]);
        $captured = null;
        $this->expectDataResponse($captured);

        $this->ask('How much is owed to us?');

        $this->assertSame([$teamSum], $this->executedQueries(), 'no check, no fallback: "nothing is owed" is the answer');
    }

    // ------------------------------------------------------------ observation

    /** @test */
    public function the_checks_are_observed_as_one_stage_with_what_they_established(): void
    {
        $this->turn(self::NEXT_MEETING, ['entityTypes' => ['Meeting'], 'isPossessive' => true, 'searchQuery' => 'next meeting']);
        $this->executor([self::FALLBACK => $this->rows($this->pastMeetings())]);
        $captured = null;
        $this->expectDataResponse($captured);

        $observer = new RecordingPipelineObserver();

        $this->ask('When is our next meeting?', ['observer' => $observer]);

        $this->assertContains('empty_result_check', $observer->finishedStages());
        $check = $observer->stageData('empty_result_check');
        $this->assertSame('team', $check['scope']);
        $this->assertSame('upcoming_meetings', $check['fallback_source']);
        $this->assertSame(3, $check['fallback_rows']);
        $this->assertSame(self::FALLBACK . ' LIMIT 3', $check['cypher']);
        $this->assertSame(2, $check['executions'], 'the source\'s own query and its fallback');
    }
}
