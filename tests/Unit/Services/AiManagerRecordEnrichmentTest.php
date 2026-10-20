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
 * Eval p18-B digest t1 ("When was our last meeting then?"): a data turn whose result
 * is ONE record of a configured entity is enriched with the name of the parent that
 * record belongs to (RecordParentEnricher) — one extra scoped execution, stamped
 * `record_enriched`, observed as the 'record_enrichment' stage. Lists, aggregates,
 * export turns and empty results are never touched; a failed lookup changes nothing.
 */
class AiManagerRecordEnrichmentTest extends TestCase
{
    private const LAST_MEETING = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids AND m.start_date < toString(date()) '
        . 'RETURN m.name AS meeting_name, m.start_date AS date, m.id AS meeting_id ORDER BY m.start_date DESC LIMIT 1';

    private const LOOKUP = 'MATCH (n:Meeting) WHERE n.id = $ai_record_id OPTIONAL MATCH (n)-[:BELONGS_TO_UNION]->(p0:Union) RETURN p0.name AS p0_0 LIMIT 1';

    private const ROW = ['meeting_name' => 'AGA 2023', 'date' => '2023-10-24 16:00', 'meeting_id' => 140];

    private AiManager $manager;
    private $mockContext;
    private $mockQueryGenerator;
    private $mockQueryExecutor;
    private $mockResponseGenerator;

    /** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> */
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
        config()->set('ai.attention_sources', []);
        config()->set('ai.response_generation.identity_labels', ['user' => 'User', 'team' => 'Team']);
        config()->set('entities', [
            'App\\Models\\Meeting' => ['graph' => [
                'label' => 'Meeting',
                'properties' => ['id', 'name', 'start_date', 'team_id', 'union_id'],
                'relationships' => [
                    ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id', 'direction' => 'outgoing'],
                    ['type' => 'BELONGS_TO_UNION', 'target_label' => 'Union', 'foreign_key' => 'union_id', 'direction' => 'outgoing'],
                ],
            ]],
            'App\\Models\\Union' => ['graph' => ['label' => 'Union', 'properties' => ['id', 'name', 'team_id']]],
            'App\\Models\\Team' => ['graph' => ['label' => 'Team', 'properties' => ['id', 'name']]],
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
    private function turn(string $cypher, array $qu = []): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(...($qu + [
            'questionType' => 'data_query', 'intent' => 'lookup', 'language' => 'en', 'entityTypes' => ['Meeting'],
        ]));
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: $cypher, explanation: '', confidence: 0.9));
    }

    /** @param array<string, QueryExecutionResult|\Throwable> $results query text => its result */
    private function executor(array $results): void
    {
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(function ($query, $params, $options) use ($results) {
                $this->executed[] = [(string) $query, $params, $options];
                $result = $results[(string) $query] ?? new QueryExecutionResult(success: true, data: [], metadata: ['security_filter_applied' => true]);
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            });
        $this->mockQueryExecutor->shouldReceive('executeCount')->andReturn(0);
    }

    private function rows(array $rows): QueryExecutionResult
    {
        return new QueryExecutionResult(success: true, data: $rows, metadata: ['security_filter_applied' => true]);
    }

    private function expectDataResponse(?PipelineContext &$captured): void
    {
        $this->mockResponseGenerator->shouldReceive('generate')->once()
            ->andReturnUsing(function (PipelineContext $ctx) use (&$captured) {
                $captured = $ctx;

                return ['answer' => 'ok', 'format' => 'text', 'metadata' => []];
            });
    }

    private function ask(string $question = 'When was our last meeting then?', array $options = []): array
    {
        return $this->manager->answerQuestion($question, $options + ['user' => (object) ['id' => 13], 'store_examples' => false]);
    }

    /** @return list<string> */
    private function executedQueries(): array
    {
        return array_column($this->executed, 0);
    }

    /** @test */
    public function a_single_record_result_names_the_parent_the_record_belongs_to(): void
    {
        $this->turn(self::LAST_MEETING);
        $this->executor([
            self::LAST_MEETING => $this->rows([self::ROW]),
            self::LOOKUP => $this->rows([['p0_0' => 'SDC du 1240 St-Sébastien']]),
        ]);
        $captured = null;
        $this->expectDataResponse($captured);
        $observer = new RecordingPipelineObserver();

        $result = $this->ask(options: ['observer' => $observer]);

        $this->assertSame([self::LAST_MEETING, self::LOOKUP], $this->executedQueries(), 'ONE extra execution, by id');
        $this->assertSame(['ai_record_id' => 140], $this->executed[1][1]);
        $this->assertSame($this->executed[0][2]['rate_limit_batch'], $this->executed[1][2]['rate_limit_batch'], 'on the turn\'s single charge');
        $this->assertSame(13, $this->executed[1][2]['user']->id);

        $expected = self::ROW + ['union_name' => 'SDC du 1240 St-Sébastien'];
        $this->assertSame([$expected], $captured->executionResult->data, 'the composer sees the union');
        $this->assertSame([$expected], $result['data']);
        $this->assertSame(
            ['entity' => 'Meeting', 'columns' => ['union_name' => 'Union']],
            $result['metadata']['execution']['record_enriched']
        );
        $this->assertSame(self::LAST_MEETING, $result['cypher'], 'the turn\'s query is unchanged: a follow-up extends / re-runs it');

        $this->assertContains('record_enrichment', $observer->finishedStages());
        $stage = $observer->stageData('record_enrichment');
        $this->assertTrue($stage['enriched']);
        $this->assertSame('Meeting', $stage['entity']);
        $this->assertSame(['union_name' => 'Union'], $stage['columns']);
        $this->assertTrue($stage['security_filter_applied']);
        $this->assertSame(self::LOOKUP, $stage['cypher']);
    }

    /**
     * @test Eval p19-B digest t1: the record's own DETAIL fields its entity declares
     * (`metadata.detail_properties`) come with the same lookup, as ordinary columns of the
     * row — the composer, the response and the trace see the place.
     */
    public function a_single_record_result_carries_its_own_declared_detail_fields(): void
    {
        config()->set('entities.App\\Models\\Meeting.graph.properties', ['id', 'name', 'start_date', 'team_id', 'union_id', 'place']);
        config()->set('entities.App\\Models\\Meeting.metadata', ['detail_properties' => ['start_date', 'place']]);
        $lookup = 'MATCH (n:Meeting) WHERE n.id = $ai_record_id OPTIONAL MATCH (n)-[:BELONGS_TO_UNION]->(p0:Union) '
            . 'RETURN p0.name AS p0_0, n.place AS place LIMIT 1';

        $this->turn(self::LAST_MEETING);
        $this->executor([
            self::LAST_MEETING => $this->rows([self::ROW]),
            $lookup => $this->rows([['p0_0' => 'SDC du 1240 St-Sébastien', 'place' => 'ZOOM']]),
        ]);
        $captured = null;
        $this->expectDataResponse($captured);
        $observer = new RecordingPipelineObserver();

        $result = $this->ask(options: ['observer' => $observer]);

        $this->assertSame([self::LAST_MEETING, $lookup], $this->executedQueries(), 'still ONE extra execution');
        $expected = self::ROW + ['place' => 'ZOOM', 'union_name' => 'SDC du 1240 St-Sébastien'];
        $this->assertSame([$expected], $captured->executionResult->data);
        $this->assertSame([$expected], $result['data']);
        $this->assertSame(
            ['entity' => 'Meeting', 'columns' => ['union_name' => 'Union'], 'details' => ['place']],
            $result['metadata']['execution']['record_enriched']
        );
        $this->assertSame(['place'], $observer->stageData('record_enrichment')['details']);
    }

    /** @test */
    public function a_list_is_never_enriched(): void
    {
        $this->turn(self::LAST_MEETING);
        $this->executor([self::LAST_MEETING => $this->rows([self::ROW, ['meeting_name' => 'CA', 'date' => '2023-09-12', 'meeting_id' => 131]])]);
        $captured = null;
        $this->expectDataResponse($captured);

        $result = $this->ask();

        $this->assertSame([self::LAST_MEETING], $this->executedQueries());
        $this->assertArrayNotHasKey('record_enriched', $result['metadata']['execution']);
    }

    /** @test */
    public function an_aggregate_row_is_never_enriched(): void
    {
        $count = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids RETURN count(m) AS meetings';
        $this->turn($count, ['intent' => 'count']);
        $this->executor([$count => $this->rows([['meetings' => 50]])]);
        $captured = null;
        $this->expectDataResponse($captured);

        $this->ask('How many meetings did we hold?');

        $this->assertSame([$count], $this->executedQueries());
    }

    /** @test */
    public function a_file_of_one_record_carries_the_parent_too(): void
    {
        $this->turn(self::LAST_MEETING, ['exportFormat' => 'excel']);
        $this->executor([
            self::LAST_MEETING => $this->rows([self::ROW]),
            self::LOOKUP => $this->rows([['p0_0' => 'SDC du 1240 St-Sébastien']]),
        ]);
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->ask('Give me our last meeting as an Excel');

        $response = $result['metadata']['response'];
        $this->assertSame('current_result', $response['export_source']);
        $this->assertSame('SDC du 1240 St-Sébastien', $response['export_document_rows'][0]['union_name']);
    }

    /** @test */
    public function an_export_of_the_recorded_record_names_its_parent_as_the_chat_did(): void
    {
        // "Create a PDF of this." after the meeting answer: the recorded query is
        // re-executed for the file — and the record's parent is named again.
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(
            entityTypes: ['Meeting'],
            searchQuery: 'last meeting',
            questionType: 'data_query',
            exportFormat: 'pdf',
            language: 'en',
            followUpReference: true,
            subjectLabel: 'Last meeting',
        );
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->executor([
            self::LAST_MEETING => $this->rows([self::ROW]),
            self::LOOKUP => $this->rows([['p0_0' => 'SDC du 1240 St-Sébastien']]),
        ]);
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->ask('Create a PDF of this.', ['conversation_context' => [
            'last_cypher_query' => self::LAST_MEETING,
            'last_result_count' => 1,
            'last_result_sample' => [self::ROW + ['union_name' => 'SDC du 1240 St-Sébastien']],
            'last_subject' => 'Last meeting',
            'focused_entity' => 'Meeting',
        ]]);

        $this->assertSame([self::LAST_MEETING, self::LOOKUP], $this->executedQueries());
        $response = $result['metadata']['response'];
        $this->assertSame('previous_result', $response['export_source']);
        $this->assertSame('SDC du 1240 St-Sébastien', $response['export_document_rows'][0]['union_name']);
        $this->assertStringContainsString('Union Name', $result['answer']);
    }

    /** @test */
    public function a_failed_lookup_leaves_the_row_as_the_query_returned_it(): void
    {
        $this->turn(self::LAST_MEETING);
        $this->executor([
            self::LAST_MEETING => $this->rows([self::ROW]),
            self::LOOKUP => new \RuntimeException('graph down'),
        ]);
        $captured = null;
        $this->expectDataResponse($captured);
        $observer = new RecordingPipelineObserver();

        $result = $this->ask(options: ['observer' => $observer]);

        $this->assertSame([self::ROW], $result['data']);
        $this->assertArrayNotHasKey('record_enriched', $result['metadata']['execution']);
        $this->assertFalse($observer->stageData('record_enrichment')['enriched'], 'the attempt is traced');
    }

    /** @test */
    public function a_record_with_no_parent_to_name_runs_and_traces_nothing(): void
    {
        config()->set('entities.App\\Models\\Meeting.graph.relationships', []);
        $this->turn(self::LAST_MEETING);
        $this->executor([self::LAST_MEETING => $this->rows([self::ROW])]);
        $captured = null;
        $this->expectDataResponse($captured);
        $observer = new RecordingPipelineObserver();

        $this->ask(options: ['observer' => $observer]);

        $this->assertSame([self::LAST_MEETING], $this->executedQueries());
        $this->assertNotContains('record_enrichment', $observer->finishedStages());
    }

    /** @test */
    public function it_can_be_switched_off(): void
    {
        config()->set('ai.query_execution.enrich_single_record', false);
        $this->turn(self::LAST_MEETING);
        $this->executor([self::LAST_MEETING => $this->rows([self::ROW])]);
        $captured = null;
        $this->expectDataResponse($captured);

        $this->ask();

        $this->assertSame([self::LAST_MEETING], $this->executedQueries());
    }
}
