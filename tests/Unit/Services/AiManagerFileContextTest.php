<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Response\ResponseFileEnricher;
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
 * Tests for AiManager file context integration
 *
 * Task 8: Verify that answerQuestion() integrates file context
 * - retrieveFileContext method exists and works
 * - enrichResponseWithFiles method exists and works
 * - File context is merged when enabled
 * - referenced_files is included in return array
 */
class AiManagerFileContextTest extends TestCase
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
    public function it_has_answer_question_method_that_accepts_options(): void
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
    public function it_has_retrieve_file_context_method(): void
    {
        // Verify the retrieveFileContext method exists
        $this->assertTrue(
            method_exists($this->manager, 'retrieveFileContext'),
            'AiManager should have retrieveFileContext method'
        );

        // Use reflection to check it's protected
        $reflection = new \ReflectionMethod($this->manager, 'retrieveFileContext');
        $this->assertTrue($reflection->isProtected(), 'retrieveFileContext should be protected');

        // Verify parameters
        $parameters = $reflection->getParameters();
        $this->assertCount(2, $parameters, 'retrieveFileContext should have 2 parameters');
        $this->assertEquals('question', $parameters[0]->getName());
        $this->assertEquals('user', $parameters[1]->getName());
    }

    /** @test */
    public function it_has_enrich_response_with_files_method(): void
    {
        // Verify the enrichResponseWithFiles method exists
        $this->assertTrue(
            method_exists($this->manager, 'enrichResponseWithFiles'),
            'AiManager should have enrichResponseWithFiles method'
        );

        // Use reflection to check it's protected
        $reflection = new \ReflectionMethod($this->manager, 'enrichResponseWithFiles');
        $this->assertTrue($reflection->isProtected(), 'enrichResponseWithFiles should be protected');

        // Verify parameters
        $parameters = $reflection->getParameters();
        $this->assertCount(3, $parameters, 'enrichResponseWithFiles should have 3 parameters');
        $this->assertEquals('response', $parameters[0]->getName());
        $this->assertEquals('fileContext', $parameters[1]->getName());
        $this->assertEquals('options', $parameters[2]->getName());
    }

    /** @test */
    public function it_retrieves_file_context_when_enabled(): void
    {
        // Enable file context
        config(['ai.file_context.enabled' => true]);

        $question = 'What are the payment policies?';
        $mockUser = (object) ['id' => 1];

        // Mock the FileContextProvider
        $mockFileContextProvider = Mockery::mock(FileContextProvider::class);
        $mockFileContextProvider->shouldReceive('getFileContext')
            ->once()
            ->with($question, $mockUser)
            ->andReturn([
                'relevant_files' => [
                    [
                        'file_id' => 1,
                        'file_name' => 'policies.pdf',
                        'snippet' => 'Payment policies...',
                        'relevance_score' => 0.85,
                        'chunk_index' => 0,
                        'source' => 'database',
                    ],
                ],
                'file_count' => 1,
                'has_physical' => false,
                'has_database' => true,
            ]);

        $this->app->instance(FileContextProvider::class, $mockFileContextProvider);

        // Mock ResponseFileEnricher
        $mockEnricher = Mockery::mock(ResponseFileEnricher::class);
        $mockEnricher->shouldReceive('enrichResponse')
            ->once()
            ->andReturnUsing(function ($response, $fileContext, $options) {
                return array_merge($response, [
                    'referenced_files' => [
                        ['ref' => 1, 'name' => 'policies.pdf'],
                    ],
                    'has_file_references' => true,
                ]);
            });

        $this->app->instance(ResponseFileEnricher::class, $mockEnricher);

        // Set up standard mocks for the pipeline
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn([
                'similar_queries' => [],
                'graph_schema' => ['labels' => []],
                'relevant_entities' => [],
            ]);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'cypher' => 'MATCH (p:Policy) RETURN p',
                'explanation' => 'Finding policies',
                'confidence' => 0.9,
                'warnings' => [],
                'metadata' => [],
            ]);

        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [['name' => 'Payment Policy']],
                'stats' => [],
                'metadata' => [],
            ]);

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'answer' => 'The payment policies are as follows [1]...',
                'content' => 'The payment policies are as follows [1]...',
                'insights' => [],
                'visualizations' => [],
                'metadata' => [],
            ]);

        // Execute
        $result = $this->manager->answerQuestion($question, [
            'user' => $mockUser,
        ]);

        // Verify referenced_files is present in response
        $this->assertArrayHasKey('referenced_files', $result);
    }

    /** @test */
    public function it_skips_file_context_when_disabled(): void
    {
        // Disable file context
        config(['ai.file_context.enabled' => false]);

        $question = 'Show all customers';

        // Mock the FileContextProvider - should NOT be called
        $mockFileContextProvider = Mockery::mock(FileContextProvider::class);
        $mockFileContextProvider->shouldNotReceive('getFileContext');

        $this->app->instance(FileContextProvider::class, $mockFileContextProvider);

        // Set up standard mocks for the pipeline
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn([
                'similar_queries' => [],
                'graph_schema' => ['labels' => ['Customer']],
                'relevant_entities' => [],
            ]);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'cypher' => 'MATCH (c:Customer) RETURN c',
                'explanation' => 'Listing customers',
                'confidence' => 0.95,
                'warnings' => [],
                'metadata' => [],
            ]);

        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [],
                'stats' => [],
                'metadata' => [],
            ]);

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'answer' => 'No customers found',
                'insights' => [],
                'visualizations' => [],
                'metadata' => [],
            ]);

        // Execute
        $result = $this->manager->answerQuestion($question);

        // Verify referenced_files is still present but empty
        $this->assertArrayHasKey('referenced_files', $result);
        $this->assertEmpty($result['referenced_files']);
    }

    /** @test */
    public function it_handles_file_context_retrieval_errors_gracefully(): void
    {
        // Enable file context
        config(['ai.file_context.enabled' => true]);

        $question = 'What is the refund policy?';
        $mockUser = (object) ['id' => 1];

        // Mock the FileContextProvider to throw an exception
        $mockFileContextProvider = Mockery::mock(FileContextProvider::class);
        $mockFileContextProvider->shouldReceive('getFileContext')
            ->once()
            ->andThrow(new \RuntimeException('File search service unavailable'));

        $this->app->instance(FileContextProvider::class, $mockFileContextProvider);

        // Set up standard mocks for the pipeline
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn([
                'similar_queries' => [],
                'graph_schema' => ['labels' => []],
                'relevant_entities' => [],
            ]);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'cypher' => 'MATCH (p:Policy) RETURN p',
                'explanation' => 'Finding policies',
                'confidence' => 0.9,
                'warnings' => [],
                'metadata' => [],
            ]);

        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [],
                'stats' => [],
                'metadata' => [],
            ]);

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'answer' => 'No refund policy information found',
                'insights' => [],
                'visualizations' => [],
                'metadata' => [],
            ]);

        // Execute - should not throw, should log warning and continue
        $result = $this->manager->answerQuestion($question, [
            'user' => $mockUser,
        ]);

        // Verify the pipeline completed successfully
        $this->assertArrayHasKey('answer', $result);
        $this->assertArrayHasKey('referenced_files', $result);
        $this->assertEmpty($result['referenced_files']);
    }

    /** @test */
    public function it_includes_file_context_in_context_array(): void
    {
        // Enable file context
        config(['ai.file_context.enabled' => true]);

        $question = 'What are the documentation guidelines?';
        $mockUser = (object) ['id' => 1];
        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 'physical:docs/guidelines.md',
                    'file_name' => 'guidelines.md',
                    'snippet' => 'Documentation guidelines...',
                    'relevance_score' => 0.92,
                    'chunk_index' => 0,
                    'source' => 'physical',
                ],
            ],
            'file_count' => 1,
            'has_physical' => true,
            'has_database' => false,
        ];

        // Mock the FileContextProvider
        $mockFileContextProvider = Mockery::mock(FileContextProvider::class);
        $mockFileContextProvider->shouldReceive('getFileContext')
            ->once()
            ->andReturn($fileContext);

        $this->app->instance(FileContextProvider::class, $mockFileContextProvider);

        // Mock ResponseFileEnricher
        $mockEnricher = Mockery::mock(ResponseFileEnricher::class);
        $mockEnricher->shouldReceive('enrichResponse')
            ->once()
            ->andReturnUsing(function ($response, $context, $options) {
                return array_merge($response, [
                    'referenced_files' => [],
                    'has_file_references' => false,
                ]);
            });

        $this->app->instance(ResponseFileEnricher::class, $mockEnricher);

        // Verify that generateQuery receives context with file_context
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn([
                'similar_queries' => [],
                'graph_schema' => ['labels' => []],
                'relevant_entities' => [],
            ]);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->with(
                $question,
                Mockery::on(function ($context) use ($fileContext) {
                    // Context should include file_context
                    return isset($context['file_context'])
                        && $context['file_context'] === $fileContext;
                }),
                Mockery::any()
            )
            ->andReturn([
                'cypher' => 'MATCH (d:Document) RETURN d',
                'explanation' => 'Finding documents',
                'confidence' => 0.9,
                'warnings' => [],
                'metadata' => [],
            ]);

        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [],
                'stats' => [],
                'metadata' => [],
            ]);

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'answer' => 'Documentation guidelines...',
                'insights' => [],
                'visualizations' => [],
                'metadata' => [],
            ]);

        // Execute
        $result = $this->manager->answerQuestion($question, [
            'user' => $mockUser,
        ]);

        // Verify result structure
        $this->assertArrayHasKey('answer', $result);
    }

    /** @test */
    public function it_returns_referenced_files_in_response(): void
    {
        // Enable file context
        config(['ai.file_context.enabled' => true]);

        $question = 'Explain the API documentation';
        $mockUser = (object) ['id' => 1];
        $referencedFiles = [
            [
                'ref' => 1,
                'id' => 5,
                'name' => 'api-docs.pdf',
                'snippet' => 'API endpoints...',
                'relevance_score' => 0.88,
                'source' => 'database',
                'chunk_index' => 2,
                'download_url' => '/files/5/download',
                'preview_url' => '/files/5/preview',
                'can_download' => true,
            ],
        ];

        // Mock the FileContextProvider
        $mockFileContextProvider = Mockery::mock(FileContextProvider::class);
        $mockFileContextProvider->shouldReceive('getFileContext')
            ->once()
            ->andReturn([
                'relevant_files' => [
                    [
                        'file_id' => 5,
                        'file_name' => 'api-docs.pdf',
                        'snippet' => 'API endpoints...',
                        'relevance_score' => 0.88,
                        'chunk_index' => 2,
                        'source' => 'database',
                    ],
                ],
                'file_count' => 1,
                'has_physical' => false,
                'has_database' => true,
            ]);

        $this->app->instance(FileContextProvider::class, $mockFileContextProvider);

        // Mock ResponseFileEnricher
        $mockEnricher = Mockery::mock(ResponseFileEnricher::class);
        $mockEnricher->shouldReceive('enrichResponse')
            ->once()
            ->andReturnUsing(function ($response, $fileContext, $options) use ($referencedFiles) {
                return array_merge($response, [
                    'referenced_files' => $referencedFiles,
                    'has_file_references' => true,
                ]);
            });

        $this->app->instance(ResponseFileEnricher::class, $mockEnricher);

        // Set up standard mocks for the pipeline
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn([
                'similar_queries' => [],
                'graph_schema' => ['labels' => []],
                'relevant_entities' => [],
            ]);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'cypher' => 'MATCH (d:Document) RETURN d',
                'explanation' => 'Finding documents',
                'confidence' => 0.9,
                'warnings' => [],
                'metadata' => [],
            ]);

        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [],
                'stats' => [],
                'metadata' => [],
            ]);

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'answer' => 'The API documentation [1] explains...',
                'content' => 'The API documentation [1] explains...',
                'insights' => [],
                'visualizations' => [],
                'metadata' => [],
            ]);

        // Execute
        $result = $this->manager->answerQuestion($question, [
            'user' => $mockUser,
        ]);

        // Verify referenced_files is present and contains expected data
        $this->assertArrayHasKey('referenced_files', $result);
        $this->assertCount(1, $result['referenced_files']);
        $this->assertEquals(1, $result['referenced_files'][0]['ref']);
        $this->assertEquals('api-docs.pdf', $result['referenced_files'][0]['name']);
    }
}
