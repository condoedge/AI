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
use Mockery;

/**
 * Tests for AiManager conversation context passing
 *
 * Task 8: Verify that answerQuestion() passes conversation_context
 * from options through to generateQuery()
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

        // Mock context retrieval
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->with($question, Mockery::any())
            ->andReturn([
                'similar_queries' => [],
                'graph_schema' => ['labels' => ['Customer']],
                'relevant_entities' => [],
            ]);

        // Verify that generateQuery receives context with conversation_context merged
        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->with(
                $question,
                Mockery::on(function ($context) use ($conversationContext) {
                    // The context should contain conversation_context
                    return isset($context['conversation_context'])
                        && $context['conversation_context'] === $conversationContext;
                }),
                Mockery::any()
            )
            ->andReturn([
                'cypher' => 'MATCH (c:Customer {id: 123}) RETURN c',
                'explanation' => 'Finding customer from conversation',
                'confidence' => 0.9,
                'warnings' => [],
                'metadata' => [],
            ]);

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
            ->andReturn([
                'success' => true,
                'data' => [['id' => 123, 'name' => 'Acme Corp']],
                'stats' => [],
                'metadata' => [],
            ]);

        // Mock response generation
        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
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
    }

    /** @test */
    public function it_works_without_conversation_context(): void
    {
        $question = 'Show all customers';

        // Mock context retrieval
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn([
                'similar_queries' => [],
                'graph_schema' => ['labels' => ['Customer']],
                'relevant_entities' => [],
            ]);

        // Verify generateQuery is called without conversation_context in context
        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->with(
                $question,
                Mockery::on(function ($context) {
                    // Should NOT have conversation_context when not provided
                    return !isset($context['conversation_context']);
                }),
                Mockery::any()
            )
            ->andReturn([
                'cypher' => 'MATCH (c:Customer) RETURN c',
                'explanation' => 'Listing all customers',
                'confidence' => 0.95,
                'warnings' => [],
                'metadata' => [],
            ]);

        // Mock storeQuery dependencies
        $this->mockVectorStore->shouldReceive('collectionExists')
            ->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')
            ->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')
            ->andReturn(array_fill(0, 1536, 0.1));

        // Mock query execution
        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [],
                'stats' => [],
                'metadata' => [],
            ]);

        // Mock response generation
        $this->mockResponseGenerator->shouldReceive('generate')
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
    }

    /** @test */
    public function it_does_not_merge_empty_conversation_context(): void
    {
        $question = 'Show all teams';

        // Mock context retrieval
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn([
                'similar_queries' => [],
                'graph_schema' => ['labels' => ['Team']],
                'relevant_entities' => [],
            ]);

        // Verify generateQuery is called without conversation_context when empty array provided
        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->with(
                $question,
                Mockery::on(function ($context) {
                    // Should NOT have conversation_context when empty array provided
                    return !isset($context['conversation_context']);
                }),
                Mockery::any()
            )
            ->andReturn([
                'cypher' => 'MATCH (t:Team) RETURN t',
                'explanation' => 'Listing all teams',
                'confidence' => 0.95,
                'warnings' => [],
                'metadata' => [],
            ]);

        // Mock storeQuery dependencies
        $this->mockVectorStore->shouldReceive('collectionExists')
            ->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')
            ->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')
            ->andReturn(array_fill(0, 1536, 0.1));

        // Mock query execution
        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [],
                'stats' => [],
                'metadata' => [],
            ]);

        // Mock response generation
        $this->mockResponseGenerator->shouldReceive('generate')
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
    }
}
