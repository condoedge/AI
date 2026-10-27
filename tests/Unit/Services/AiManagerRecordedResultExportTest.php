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
 * Eval p18 (B resilience t3 "Ok, can you export that members list to excel?", B
 * french t1, A pdf t1): an export follow-up that asks for the recorded result as it
 * is skips query generation — the file is the recorded query re-executed through the
 * scoped executor up to the document row limit, exactly as before. An export that
 * adds an attribute ("…with their contact info") still generates.
 */
class AiManagerRecordedResultExportTest extends TestCase
{
    private const ROSTER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role LIMIT 100';

    private const ROSTER_UNCAPPED = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role LIMIT 250';

    private AiManager $manager;
    private $mockContext;
    private $mockQueryGenerator;
    private $mockQueryExecutor;
    private $mockResponseGenerator;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $executed = [];

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.query_execution.default_limit', 100);
        config()->set('ai.export.document_row_limit', 250);
        config()->set('ai.attached_actions.project_record_ids', false);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

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

    /** @param array<string, mixed> $qu */
    private function exportTurn(array $qu): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(...($qu + [
            'entityTypes' => ['Team', 'User', 'TeamRole'],
            'questionType' => 'data_query',
            'intent' => 'other',
            'exportFormat' => 'excel',
            'language' => 'en',
            'followUpReference' => true,
        ]));
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
    }

    /** @return list<array<string, mixed>> */
    private static function members(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['name' => "Member {$i}", 'email' => "m{$i}@x.test", 'role' => 'owner'];
        }

        return $rows;
    }

    private function conversation(): array
    {
        return [
            'user' => (object) ['id' => 13],
            'store_examples' => false,
            'conversation_context' => [
                'last_cypher_query' => self::ROSTER,
                'last_result_count' => 100,
                'last_result_sample' => array_slice(self::members(100), 0, 10),
                'last_subject' => 'Team Members and Roles',
                'focused_entity' => 'Team',
            ],
        ];
    }

    private function graph(): void
    {
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(function ($query, $params, $options) {
                $query = (string) $query;
                if ($query === '') {
                    return QueryExecutionResult::empty();
                }
                $this->executed[] = [$query, $options];
                $limit = preg_match('/LIMIT\s+(\d+)\s*$/', $query, $m) === 1 ? (int) $m[1] : (int) ($options['limit'] ?? 100);

                return new QueryExecutionResult(
                    success: true,
                    data: str_contains($query, 'phone') ? array_map(static fn (array $r) => $r + ['phone' => '555'], self::members(min($limit, 180))) : self::members(min($limit, 180)),
                    metadata: ['security_filter_applied' => true],
                );
            });
        $this->mockQueryExecutor->shouldReceive('executeCount')->andReturn(180);
    }

    /** @test */
    public function an_export_of_what_was_shown_skips_query_generation(): void
    {
        $this->exportTurn(['searchQuery' => 'team members roles', 'subjectLabel' => 'Team Members and Roles']);
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->graph();
        $observer = new RecordingPipelineObserver();

        $result = $this->manager->answerQuestion('Ok, can you export that members list to excel?', $this->conversation() + ['observer' => $observer]);

        // The file: the recorded query, without its display cap, through the executor.
        $this->assertSame([self::ROSTER_UNCAPPED], array_column($this->executed, 0), 'ONE execution: the recorded query up to the document row limit');
        $this->assertSame(13, $this->executed[0][1]['user']->id);
        $this->assertNotEmpty($this->executed[0][1]['rate_limit_batch']);

        $response = $result['metadata']['response'];
        $this->assertSame('previous_result', $response['export_source']);
        $this->assertCount(180, $response['export_document_rows']);
        $this->assertSame(['name' => 'Member 1', 'email' => 'm1@x.test', 'role' => 'owner'], $response['export_document_rows'][0]);
        $this->assertTrue($response['export_source_complete']);
        $this->assertStringContainsString('Fields included: Name, Email, Role.', $result['answer']);

        $this->assertNull($result['cypher'], 'the turn ran no query of its own');
        $this->assertSame([], $result['data'], 'the recorded result stays the conversation\'s result (no rows to overwrite it)');
        $this->assertTrue($result['metadata']['security_filter_applied'], 'the file\'s rows were tenant-scoped');
        $this->assertSame('recorded_result_export', $result['metadata']['query']['generation_skipped']);

        // Trace: no generation, no execution stage of a fresh query.
        $this->assertNotContains('query_generation', $observer->finishedStages());
        $this->assertNotContains('execution', $observer->finishedStages());
        $this->assertSame(
            ['generation_skipped' => true, 'format' => 'excel', 'recorded_rows' => 100],
            $observer->stageData('recorded_result_export')
        );
        $this->assertSame('previous_result', $observer->stageData('export')['export_source']);
    }

    /** @test */
    public function an_export_that_adds_an_attribute_still_generates(): void
    {
        $fresh = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
            . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
            . 'RETURN u.name AS name, u.email AS email, tr.role AS role, u.phone AS phone LIMIT 100';
        $this->exportTurn([
            'propertyConcepts' => ['contact', 'email', 'role'],
            'searchQuery' => 'team members contact information',
            'subjectLabel' => 'Team Members Contact Information',
        ]);
        $this->mockQueryGenerator->shouldReceive('generate')->once()
            ->andReturn(new QueryGenerationResult(cypher: $fresh, explanation: '', confidence: 0.9));
        $this->graph();
        $observer = new RecordingPipelineObserver();

        $result = $this->manager->answerQuestion('Perfect — give me that as an Excel with their contact info.', $this->conversation() + ['observer' => $observer]);

        $this->assertContains('query_generation', $observer->finishedStages());
        $this->assertNotContains('recorded_result_export', $observer->finishedStages());
        $this->assertSame($fresh, $result['cypher']);
        $this->assertSame('current_result', $result['metadata']['response']['export_source'], 'the richer fresh result is the file');
        $this->assertArrayHasKey('phone', $result['metadata']['response']['export_document_rows'][0]);
    }

    /**
     * @test Gold context-degradation t8: after a task list, "back to the very first thing — export that members
     * list with their emails" generated the members query, yet the file was the 6 tasks of the previous turn.
     * A fresh result about OTHER records (another anchor, no shared column) is what the export is about.
     */
    public function a_follow_up_export_about_other_records_exports_its_own_result(): void
    {
        $tasks = 'MATCH (tk:Task) WHERE tk.team_id = 3 AND tk.urgent = 1 RETURN tk.title AS title, tk.id AS task_id LIMIT 100';
        $members = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id RETURN u.name AS user_name, u.email AS email, u.id AS user_id LIMIT 100';
        $this->exportTurn(['searchQuery' => 'team members emails', 'subjectLabel' => 'Team Members', 'entityTypes' => ['Team', 'User']]);
        $this->mockQueryGenerator->shouldReceive('generate')->once()
            ->andReturn(new QueryGenerationResult(cypher: $members, explanation: '', confidence: 0.9));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturnUsing(function ($query) {
            $query = (string) $query;
            $rows = str_contains($query, ':Task')
                ? [['title' => 'Fix the roof', 'task_id' => 1], ['title' => 'Paint', 'task_id' => 2]]
                : [['user_name' => 'Ann', 'email' => 'ann@x.test', 'user_id' => 7], ['user_name' => 'Bob', 'email' => 'bob@x.test', 'user_id' => 8]];

            return $query === '' ? QueryExecutionResult::empty() : new QueryExecutionResult(success: true, data: $rows, metadata: ['security_filter_applied' => true]);
        });
        $this->mockQueryExecutor->shouldReceive('executeCount')->andReturn(2);

        $result = $this->manager->answerQuestion('Now back to the very first thing — export that members list with their emails to Excel.', [
            'user' => (object) ['id' => 13],
            'store_examples' => false,
            'conversation_context' => [
                'last_cypher_query' => $tasks,
                'last_result_count' => 2,
                'last_result_sample' => [['title' => 'Fix the roof', 'task_id' => 1], ['title' => 'Paint', 'task_id' => 2]],
                'last_subject' => 'Urgent Tasks',
                'focused_entity' => 'Task',
            ],
        ]);

        $response = $result['metadata']['response'];
        $this->assertSame('current_result', $response['export_source']);
        $this->assertSame('ann@x.test', $response['export_document_rows'][0]['email']);
    }

    /** @test */
    public function the_skip_can_be_switched_off(): void
    {
        config()->set('ai.export.skip_generation_for_recorded_result', false);
        $this->exportTurn(['searchQuery' => 'team members roles', 'subjectLabel' => 'Team Members and Roles']);
        $this->mockQueryGenerator->shouldReceive('generate')->once()
            ->andReturn(new QueryGenerationResult(cypher: self::ROSTER, explanation: '', confidence: 0.9));
        $this->graph();

        $result = $this->manager->answerQuestion('Ok, can you export that members list to excel?', $this->conversation());

        $this->assertSame('previous_result', $result['metadata']['response']['export_source']);
    }
}
