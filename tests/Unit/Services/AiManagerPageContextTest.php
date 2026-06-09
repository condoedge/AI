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
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * AiManager merges the page_context option onto the typed PipelineContext->pageContext
 * property, symmetric to conversation_context. Default-empty keeps the page-context section
 * silent when nothing is passed.
 */
class AiManagerPageContextTest extends TestCase
{
    private AiManager $manager;
    private ContextRetrieverInterface $mockContext;
    private EmbeddingProviderInterface $mockEmbedding;
    private LlmProviderInterface $mockLlm;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private VectorStoreInterface $mockVectorStore;

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.security.enabled' => false]);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockEmbedding = Mockery::mock(EmbeddingProviderInterface::class);
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            $this->mockEmbedding,
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $this->mockVectorStore
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makePipelineContext(string $question): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['Unit']];
        $ctx->relevantEntities = [];

        return $ctx;
    }

    private function wireHappyPath(PipelineContext $ctx): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->mockQueryGenerator->shouldReceive('generate')->andReturn([
            'cypher' => 'MATCH (u:Unit) RETURN u',
            'explanation' => 'List units',
            'confidence' => 0.9,
            'warnings' => [],
            'metadata' => [],
        ]);

        $this->mockQueryExecutor->shouldReceive('execute')->andReturn([
            'success' => true,
            'data' => [['id' => 402]],
            'stats' => [],
            'metadata' => [],
        ]);

        $this->mockResponseGenerator->shouldReceive('generate')->andReturn([
            'answer' => 'ok',
            'insights' => [],
            'visualizations' => [],
            'metadata' => [],
        ]);
    }

    /** @test */
    public function it_merges_page_context_onto_the_pipeline_context(): void
    {
        $question = 'Show this unit payment history';
        $ctx = $this->makePipelineContext($question);
        $this->wireHappyPath($ctx);

        $page = [
            'route' => 'units.show',
            'entity_type' => 'Unit',
            'entity_id' => 402,
            'filters' => ['status' => 'active'],
            'label' => 'Unit 402',
        ];

        // generate() must observe the merged pageContext on the typed property.
        $this->mockQueryGenerator->shouldReceive('generate')->andReturnUsing(
            function ($q, $context) use ($page) {
                $this->assertInstanceOf(PipelineContext::class, $context);
                $this->assertSame($page, $context->pageContext);

                return [
                    'cypher' => 'MATCH (u:Unit) RETURN u',
                    'explanation' => 'List units',
                    'confidence' => 0.9,
                    'warnings' => [],
                    'metadata' => [],
                ];
            }
        );

        $this->manager->answerQuestion($question, ['page_context' => $page]);

        // The merge mutated the same PipelineContext the retriever returned.
        $this->assertSame($page, $ctx->pageContext);
    }

    /** @test */
    public function it_leaves_page_context_empty_when_not_provided(): void
    {
        $question = 'Show all units';
        $ctx = $this->makePipelineContext($question);
        $this->wireHappyPath($ctx);

        $this->manager->answerQuestion($question);

        $this->assertSame([], $ctx->pageContext);
    }

    /** @test */
    public function it_does_not_merge_an_empty_page_context(): void
    {
        $question = 'Show all units';
        $ctx = $this->makePipelineContext($question);
        $this->wireHappyPath($ctx);

        $this->manager->answerQuestion($question, ['page_context' => []]);

        $this->assertSame([], $ctx->pageContext);
    }
}
