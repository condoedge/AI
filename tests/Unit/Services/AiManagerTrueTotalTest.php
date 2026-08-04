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
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * TRUE-TOTAL COMPANION COUNT (B): when execution returns EXACTLY the executor
 * cap, AiManager calls QueryExecutor::executeCount with the SAME scoped
 * query/options (trailing LIMIT stripped) to establish the true total, and
 * threads it into (a) the executionResult stats+metadata the response prompt
 * reads, (b) the export sourceMeta (total_rows real, complete computed
 * honestly). On count failure, the pre-existing "more may exist" behavior is
 * kept unchanged.
 */
class AiManagerTrueTotalTest extends TestCase
{
    private AiManager $manager;
    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.query_execution.default_limit', 100);

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

    /** @return array<int, array<string, mixed>> */
    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['id' => $i, 'name' => "Member {$i}"];
        }

        return $rows;
    }

    private function pipelineContextFor(string $cypher): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: $cypher, explanation: '', confidence: 0.9, warnings: [], metadata: []));
    }

    /** @test */
    public function a_cap_hit_execution_gets_the_true_total_stamped_and_threaded_to_the_response_prompt(): void
    {
        $cypher = 'MATCH (u:User) RETURN u.name AS name LIMIT 100';
        $this->pipelineContextFor($cypher);

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));

        // The companion count runs with the SAME scoped options and the TRAILING
        // LIMIT stripped (the count must see the uncapped population).
        $this->mockQueryExecutor->shouldReceive('executeCount')
            ->once()
            ->andReturnUsing(function ($countCypher, $params, $options) {
                $this->assertStringNotContainsString('LIMIT', strtoupper($countCypher));
                $this->assertStringContainsString('MATCH (u:User) RETURN u.name AS name', $countCypher);
                $this->assertSame(13, $options['user']->id ?? null, 'count must carry the same identity options');
                return 250;
            });

        $capturedCtx = null;
        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (PipelineContext $ctx) use (&$capturedCtx) {
                $capturedCtx = $ctx;
                return ['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []];
            });

        $result = $this->manager->answerQuestion('who are my team members?', [
            'user' => (object) ['id' => 13],
        ]);

        $this->assertSame(250, $capturedCtx->executionResult->metadata['true_total'] ?? null);
        $this->assertSame(250, $capturedCtx->executionResult->stats['true_total'] ?? null);
        $this->assertSame(250, $result['metadata']['execution']['true_total'] ?? null);
    }

    /** @test */
    public function a_below_cap_execution_never_runs_the_companion_count(): void
    {
        $this->pipelineContextFor('MATCH (u:User) RETURN u.name AS name LIMIT 100');

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(7)));
        $this->mockQueryExecutor->shouldReceive('executeCount')->never();

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);

        $result = $this->manager->answerQuestion('who are my team members?', []);

        $this->assertArrayNotHasKey('true_total', $result['metadata']['execution']);
    }

    /** @test */
    public function a_count_failure_keeps_the_result_unstamped_and_the_turn_alive(): void
    {
        $this->pipelineContextFor('MATCH (u:User) RETURN u.name AS name LIMIT 100');

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));
        $this->mockQueryExecutor->shouldReceive('executeCount')
            ->once()
            ->andThrow(new \RuntimeException('count backend down'));

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);

        $result = $this->manager->answerQuestion('who are my team members?', []);

        $this->assertArrayNotHasKey('true_total', $result['metadata']['execution']);
        $this->assertCount(100, $result['data']);
    }

    /** @test */
    public function an_inconsistent_count_below_the_retrieved_rows_is_distrusted(): void
    {
        $this->pipelineContextFor('MATCH (u:User) RETURN u.name AS name LIMIT 100');

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));
        $this->mockQueryExecutor->shouldReceive('executeCount')->once()->andReturn(40);

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);

        $result = $this->manager->answerQuestion('who are my team members?', []);

        $this->assertArrayNotHasKey('true_total', $result['metadata']['execution']);
    }

    /** @test */
    public function a_cold_export_at_the_cap_uses_the_true_total_for_honest_source_meta(): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', exportFormat: 'excel');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: 'MATCH (u:User) RETURN u.name AS name LIMIT 100', explanation: '', confidence: 0.9, warnings: [], metadata: []));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));
        $this->mockQueryExecutor->shouldReceive('executeCount')->once()->andReturn(250);

        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = $this->manager->answerQuestion('excel of all my team members please', []);

        $response = $result['metadata']['response'];
        $this->assertTrue($response['export_intent']);
        $this->assertFalse($response['export_source_complete'], '100 of 250 is NOT complete');
        $this->assertSame(250, $response['result_total']);
        $this->assertStringContainsString('100 of 250', $result['answer']);
    }

    /** @test */
    public function a_cold_export_whose_count_confirms_the_cap_equals_the_population_is_complete(): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', exportFormat: 'excel');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: 'MATCH (u:User) RETURN u.name AS name LIMIT 100', explanation: '', confidence: 0.9, warnings: [], metadata: []));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));
        $this->mockQueryExecutor->shouldReceive('executeCount')->once()->andReturn(100);

        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = $this->manager->answerQuestion('excel of all my team members please', []);

        $response = $result['metadata']['response'];
        // The companion count PROVED the cap-hit set is the whole population:
        // complete=true, and no "more may exist" hedging anywhere.
        $this->assertTrue($response['export_source_complete']);
        $this->assertStringNotContainsString('more may exist', $result['answer']);
        $this->assertStringNotContainsString('more may exist', $response['export_document_markdown']);
    }

    /** @test */
    public function a_follow_up_export_re_execution_at_the_cap_uses_the_companion_count_for_its_total(): void
    {
        $recordedCypher = 'MATCH (u:User) RETURN u.name AS name LIMIT 100';

        $ctx = new PipelineContext();
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function ($cypher) use ($recordedCypher) {
                if ($cypher === $recordedCypher) {
                    return new QueryExecutionResult(success: true, data: $this->rows(100));
                }

                return new QueryExecutionResult(success: true, data: []);
            });
        $this->mockQueryExecutor->shouldReceive('executeCount')->once()->andReturn(250);

        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = $this->manager->answerQuestion('export that to Excel', [
            'conversation_context' => [
                'last_cypher_query' => $recordedCypher,
                'last_result_count' => 100,
                'last_result_sample' => array_slice($this->rows(100), 0, 10),
            ],
        ]);

        $response = $result['metadata']['response'];
        $this->assertSame('previous_result', $response['export_source']);
        $this->assertFalse($response['export_source_complete']);
        $this->assertSame(250, $response['result_total']);
        $this->assertStringContainsString('100 of 250', $result['answer']);
    }
}
