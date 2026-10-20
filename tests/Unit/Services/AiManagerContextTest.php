<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Mockery;

/**
 * Tests for AiManager conversation context passing
 *
 * Task 8: Verify that answerQuestion() passes conversation_context
 * from options through to generateQuery()
 *
 * Current contract: context flows through the pipeline as a typed
 * PipelineContext DTO (not a plain array). retrieveContext() returns a
 * PipelineContext; AiManager sets the conversation context on the typed
 * $ctx->conversationContext property; QueryGenerator::generate() and
 * ResponseGenerator::generate() both receive that same PipelineContext.
 */
class AiManagerContextTest extends TestCase
{
    private AiManager $manager;
    private DataIngestionServiceInterface $mockIngestion;
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

        // These tests exercise the happy path of the pipeline, not security.
        // Fail-closed security would otherwise throw on entity queries.
        config(['ai.security.enabled' => false]);

        $this->mockIngestion = Mockery::mock(DataIngestionServiceInterface::class);
        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockEmbedding = Mockery::mock(EmbeddingProviderInterface::class);
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);

        $this->manager = new AiManager(
            $this->mockIngestion,
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

    /**
     * Build a fresh PipelineContext that ContextRetriever would return for a
     * given question. Mirrors the real contract: a typed DTO seeded with the
     * question, graph schema, and (empty) similar queries / entities.
     */
    private function makePipelineContext(string $question, array $labels = ['Customer']): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => $labels];

        return $ctx;
    }

    /** @test */
    public function it_accepts_conversation_context_option(): void
    {
        // Verify the answerQuestion method exists and accepts options array
        $this->assertTrue(
            method_exists($this->manager, 'answerQuestion'),
            'AiManager should have answerQuestion method'
        );

        // Use reflection to verify the method signature accepts array options
        $reflection = new \ReflectionMethod($this->manager, 'answerQuestion');
        $parameters = $reflection->getParameters();

        $this->assertCount(2, $parameters, 'answerQuestion should have 2 parameters');
        $this->assertEquals('question', $parameters[0]->getName());
        $this->assertEquals('options', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->isDefaultValueAvailable(), 'options should have default value');
        $this->assertEquals([], $parameters[1]->getDefaultValue(), 'options default should be empty array');
    }

    /** @test */
    public function it_passes_conversation_context_to_generate_query(): void
    {
        $question = 'Show me customers from last conversation';
        $conversationContext = [
            'entities' => [
                ['type' => 'Customer', 'id' => 123, 'name' => 'Acme Corp'],
            ],
            'recent_queries' => ['MATCH (c:Customer) RETURN c'],
            'summary' => 'User was viewing customer Acme Corp',
        ];

        // Mock context retrieval — returns a typed PipelineContext DTO
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->with($question, Mockery::any())
            ->andReturn($this->makePipelineContext($question));

        // Verify that generateQuery receives a PipelineContext with the
        // conversation context merged onto its typed property.
        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->with(
                $question,
                Mockery::on(function ($context) use ($conversationContext) {
                    return $context instanceof PipelineContext
                        && $context->conversationContext === $conversationContext;
                }),
                Mockery::any()
            )
            ->andReturn(new QueryGenerationResult(
                cypher: 'MATCH (c:Customer {id: 123}) RETURN c',
                explanation: 'Finding customer from conversation',
                confidence: 0.9,
                warnings: [],
                metadata: [],
            ));

        // Mock storeQuery call (called inside generateQuery wrapper)
        $this->mockVectorStore->shouldReceive('collectionExists')
            ->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')
            ->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')
            ->andReturn(array_fill(0, 1536, 0.1));

        // Mock query execution
        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(
                success: true,
                data: [['id' => 123, 'name' => 'Acme Corp']],
                stats: [],
                metadata: [],
                errors: [],
            ));

        // Mock response generation — data is present so the grounded
        // generate() path is taken (not generateEmptyResponse()).
        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->with(
                Mockery::on(fn ($context) => $context instanceof PipelineContext),
                Mockery::any()
            )
            ->andReturn([
                'answer' => 'Found customer Acme Corp',
                'insights' => [],
                'visualizations' => [],
                'metadata' => [],
            ]);

        // Execute the method with conversation_context option
        $result = $this->manager->answerQuestion($question, [
            'conversation_context' => $conversationContext,
        ]);

        // Verify result structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('question', $result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertEquals($question, $result['question']);
        $this->assertEquals('Found customer Acme Corp', $result['answer']);
    }

    /** @test */
    public function it_works_without_conversation_context(): void
    {
        $question = 'Show all customers';

        // Mock context retrieval — returns a typed PipelineContext DTO
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($this->makePipelineContext($question));

        // Verify generateQuery is called with a PipelineContext whose
        // conversation context remains empty when none is provided.
        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->with(
                $question,
                Mockery::on(function ($context) {
                    return $context instanceof PipelineContext
                        && $context->conversationContext === [];
                }),
                Mockery::any()
            )
            ->andReturn(new QueryGenerationResult(
                cypher: 'MATCH (c:Customer) RETURN c',
                explanation: 'Listing all customers',
                confidence: 0.95,
                warnings: [],
                metadata: [],
            ));

        // Mock storeQuery dependencies
        $this->mockVectorStore->shouldReceive('collectionExists')
            ->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')
            ->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')
            ->andReturn(array_fill(0, 1536, 0.1));

        // Mock query execution — empty result set
        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(
                success: true,
                data: [],
                stats: [],
                metadata: [],
                errors: [],
            ));

        // With no data and no file context the pipeline abstains via
        // generateEmptyResponse() (grounded generate() is reserved for
        // answers that have a source).
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')
            ->once()
            ->andReturn([
                'answer' => 'No customers found',
                'insights' => [],
                'visualizations' => [],
                'metadata' => [],
            ]);

        // Execute without conversation_context
        $result = $this->manager->answerQuestion($question);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertEquals('No customers found', $result['answer']);
    }

    /** @test */
    public function it_does_not_merge_empty_conversation_context(): void
    {
        $question = 'Show all teams';

        // Mock context retrieval — returns a typed PipelineContext DTO
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($this->makePipelineContext($question, ['Team']));

        // Verify generateQuery is called with a PipelineContext whose
        // conversation context stays empty when an empty array is provided.
        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->with(
                $question,
                Mockery::on(function ($context) {
                    return $context instanceof PipelineContext
                        && $context->conversationContext === [];
                }),
                Mockery::any()
            )
            ->andReturn(new QueryGenerationResult(
                cypher: 'MATCH (t:Team) RETURN t',
                explanation: 'Listing all teams',
                confidence: 0.95,
                warnings: [],
                metadata: [],
            ));

        // Mock storeQuery dependencies
        $this->mockVectorStore->shouldReceive('collectionExists')
            ->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')
            ->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')
            ->andReturn(array_fill(0, 1536, 0.1));

        // Mock query execution — empty result set
        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(
                success: true,
                data: [],
                stats: [],
                metadata: [],
                errors: [],
            ));

        // No data + no file context => explicit abstention path.
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')
            ->once()
            ->andReturn([
                'answer' => 'No teams found',
                'insights' => [],
                'visualizations' => [],
                'metadata' => [],
            ]);

        // Execute with empty conversation_context
        $result = $this->manager->answerQuestion($question, [
            'conversation_context' => [],
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('No teams found', $result['answer']);
    }
}
