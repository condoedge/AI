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
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Task 1.9 — the few-shot store keeps only PROVEN queries.
 *
 * Previously every generated cypher was stored as a reusable example at
 * GENERATION time (before execution), so erroring / 0-row / safe-stopped
 * queries became examples and poisoned later generations (July 2026). A
 * question->cypher pair is now stored only after it executed successfully,
 * was not safe-stopped by scoping, and returned at least one row.
 */
class AiManagerFewShotStoreTest extends TestCase
{
    private const CYPHER = 'MATCH (c:Contact {user_id: 13})-[:OWNS]->(u:Unit) RETURN u.name AS unit';

    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private VectorStoreInterface $mockVectorStore;
    private EmbeddingProviderInterface $mockEmbedding;
    private AiManager $manager;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');
        config()->set('ai.file_context.enabled', false);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockEmbedding = Mockery::mock(EmbeddingProviderInterface::class);

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            $this->mockEmbedding,
            Mockery::mock(LlmProviderInterface::class),
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $this->mockVectorStore
        );

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: self::CYPHER, confidence: 0.9));

        $this->mockResponseGenerator->shouldReceive('generate')
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')
            ->andReturn(['answer' => 'none', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);
        $this->mockResponseGenerator->shouldReceive('generateErrorResponse')
            ->andReturn(['answer' => 'error', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => ['error' => true]]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Number of few-shot writes attempted (storeQuery swallows its own exceptions). */
    private int $storeAttempts = 0;

    private function expectNoStore(): void
    {
        // storeQuery() catches its own exceptions, so a Mockery never() would be
        // swallowed — count the attempts instead and assert on them.
        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('upsert')->andReturnUsing(function () {
            $this->storeAttempts++;
            return true;
        });
    }

    protected function assertPostConditions(): void
    {
        $this->assertSame(0, $this->storeAttempts, 'no few-shot example may be stored for an unproven query');
    }

    /** @test */
    public function a_zero_row_execution_is_never_stored_as_an_example(): void
    {
        $this->expectNoStore();
        $this->mockQueryExecutor->shouldReceive('execute')->once()
            ->andReturn(new QueryExecutionResult(success: true, data: []));

        $this->manager->answerQuestion('which units do I own?', ['user' => (object) ['id' => 13]]);
    }

    /** @test */
    public function a_proven_execution_is_stored_once_with_the_parameterized_cypher(): void
    {
        $this->mockQueryExecutor->shouldReceive('execute')->once()
            ->andReturn(new QueryExecutionResult(success: true, data: [['unit' => '310']]));

        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->once()->andReturn([0.1, 0.2]);

        $stored = [];
        $this->mockVectorStore->shouldReceive('upsert')->once()
            ->andReturnUsing(function ($collection, $points) use (&$stored) {
                $stored = $points;
                return true;
            });

        $this->manager->answerQuestion('which units do I own?', ['user' => (object) ['id' => 13]]);

        $this->assertCount(1, $stored);
        $this->assertSame('which units do I own?', $stored[0]['payload']['question']);
        $this->assertStringContainsString('user_id: $currentUserId', $stored[0]['payload']['cypher_query']);
        $this->assertStringNotContainsString('user_id: 13', $stored[0]['payload']['cypher_query']);
    }

    /** @test */
    public function a_safe_stopped_execution_is_never_stored(): void
    {
        $this->expectNoStore();
        $this->mockQueryExecutor->shouldReceive('execute')->once()
            ->andReturn(new QueryExecutionResult(success: true, data: [], metadata: ['safe_stop' => true]));

        $this->manager->answerQuestion('which units do I own?', ['user' => (object) ['id' => 13]]);
    }

    /** @test */
    public function an_execution_error_is_never_stored(): void
    {
        $this->expectNoStore();
        $this->mockQueryExecutor->shouldReceive('execute')->once()
            ->andThrow(new QueryExecutionException('Neo.ClientError.Statement.SyntaxError'));

        $this->manager->answerQuestion('which units do I own?', ['user' => (object) ['id' => 13]]);
    }

    /** @test */
    public function store_examples_false_still_suppresses_storing_a_proven_query(): void
    {
        $this->expectNoStore();
        $this->mockQueryExecutor->shouldReceive('execute')->once()
            ->andReturn(new QueryExecutionResult(success: true, data: [['unit' => '310']]));

        $this->manager->answerQuestion('which units do I own?', [
            'user' => (object) ['id' => 13],
            'store_examples' => false,
        ]);
    }
}
