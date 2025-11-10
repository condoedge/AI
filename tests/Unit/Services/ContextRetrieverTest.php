<?php

declare(strict_types=1);

namespace AiSystem\Tests\Unit\Services;

use AiSystem\Tests\TestCase;
use AiSystem\Services\ContextRetriever;
use AiSystem\Contracts\ContextRetrieverInterface;
use AiSystem\Contracts\VectorStoreInterface;
use AiSystem\Contracts\GraphStoreInterface;
use AiSystem\Contracts\EmbeddingProviderInterface;
use Mockery;
use InvalidArgumentException;
use RuntimeException;
use Exception;

/**
 * Unit Tests for ContextRetriever
 *
 * These tests verify the ContextRetriever's ability to:
 * - Retrieve comprehensive context from multiple sources (vector + graph)
 * - Search for similar questions using vector similarity
 * - Get graph schema information (labels, relationships, properties)
 * - Retrieve example entities for concrete context
 * - Handle partial failures gracefully (graceful degradation)
 * - Validate input and prevent Cypher injection
 *
 * All dependencies are mocked - NO real database calls are made.
 */
class ContextRetrieverTest extends TestCase
{
    private $mockVectorStore;
    private $mockGraphStore;
    private $mockEmbeddingProvider;
    private $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockGraphStore = Mockery::mock(GraphStoreInterface::class);
        $this->mockEmbeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        $this->service = new ContextRetriever(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Constructor & Setup Tests
    // =========================================================================

    /**
     * Test that constructor accepts valid dependencies
     */
    public function test_constructor_accepts_valid_dependencies()
    {
        $service = new ContextRetriever(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider
        );

        $this->assertInstanceOf(ContextRetriever::class, $service);
    }

    /**
     * Test that service implements ContextRetrieverInterface
     */
    public function test_service_implements_context_retriever_interface()
    {
        $this->assertInstanceOf(ContextRetrieverInterface::class, $this->service);
    }

    /**
     * Test that dependencies are stored correctly
     */
    public function test_constructor_stores_dependencies_correctly()
    {
        // Test by calling methods that use dependencies
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1, 0.2]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        // This call will fail if dependencies aren't stored
        $this->service->searchSimilar('test question');

        // Mockery will verify expectations
        $this->assertTrue(true);
    }

    // =========================================================================
    // retrieveContext() Method Tests
    // =========================================================================

    /**
     * Test successful retrieval of combined context from all sources
     */
    public function test_retrieve_context_successfully_retrieves_combined_context()
    {
        // Arrange
        $question = 'Show teams with most active members';

        // Mock embedding generation
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->with($question)
            ->andReturn([0.1, 0.2, 0.3]);

        // Mock vector search
        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'score' => 0.89,
                    'payload' => [
                        'question' => 'List all teams',
                        'cypher_query' => 'MATCH (t:Team) RETURN t',
                    ],
                ],
            ]);

        // Mock graph schema retrieval
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Team', 'Person'],
                'relationshipTypes' => ['MEMBER_OF'],
                'propertyKeys' => ['id', 'name'],
            ]);

        // Mock example entity retrieval
        $this->mockGraphStore
            ->shouldReceive('query')
            ->times(2) // Once for Team, once for Person
            ->andReturn(
                [['n' => ['id' => 1, 'name' => 'Alpha Team']]],
                [['n' => ['id' => 1, 'name' => 'John Doe']]]
            );

        // Act
        $context = $this->service->retrieveContext($question);

        // Assert
        $this->assertIsArray($context);
        $this->assertArrayHasKey('similar_queries', $context);
        $this->assertArrayHasKey('graph_schema', $context);
        $this->assertArrayHasKey('relevant_entities', $context);
        $this->assertArrayHasKey('errors', $context);

        $this->assertNotEmpty($context['similar_queries']);
        $this->assertNotEmpty($context['graph_schema']);
        $this->assertNotEmpty($context['relevant_entities']);
        $this->assertEmpty($context['errors']);
    }

    /**
     * Test that retrieveContext throws exception for empty question
     */
    public function test_retrieve_context_throws_exception_for_empty_question()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Question cannot be empty');

        $this->service->retrieveContext('');
    }

    /**
     * Test that retrieveContext throws exception for whitespace-only question
     */
    public function test_retrieve_context_throws_exception_for_whitespace_only_question()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Question cannot be empty');

        $this->service->retrieveContext('   ');
    }

    /**
     * Test that retrieveContext respects custom collection option
     */
    public function test_retrieve_context_respects_custom_collection_option()
    {
        $question = 'Test question';
        $customCollection = 'custom_questions';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        // Verify custom collection is used
        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with($customCollection, Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $this->service->retrieveContext($question, ['collection' => $customCollection]);

        // Mockery will verify the expectation
        $this->assertTrue(true);
    }

    /**
     * Test that retrieveContext respects custom limit option
     */
    public function test_retrieve_context_respects_custom_limit_option()
    {
        $question = 'Test question';
        $customLimit = 10;

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        // Verify custom limit is used
        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with(Mockery::any(), Mockery::any(), $customLimit, Mockery::any(), Mockery::any())
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $this->service->retrieveContext($question, ['limit' => $customLimit]);

        // Mockery will verify the expectation
        $this->assertTrue(true);
    }

    /**
     * Test that retrieveContext skips schema when includeSchema is false
     */
    public function test_retrieve_context_skips_schema_when_include_schema_false()
    {
        $question = 'Test question';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        // getSchema should NOT be called
        $this->mockGraphStore
            ->shouldNotReceive('getSchema');

        $context = $this->service->retrieveContext($question, ['includeSchema' => false]);

        $this->assertEmpty($context['graph_schema']);
        $this->assertEmpty($context['relevant_entities']);
    }

    /**
     * Test that retrieveContext skips examples when includeExamples is false
     */
    public function test_retrieve_context_skips_examples_when_include_examples_false()
    {
        $question = 'Test question';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => ['Team'], 'relationshipTypes' => [], 'propertyKeys' => []]);

        // query() should NOT be called for examples
        $this->mockGraphStore
            ->shouldNotReceive('query');

        $context = $this->service->retrieveContext($question, ['includeExamples' => false]);

        $this->assertEmpty($context['relevant_entities']);
    }

    /**
     * Test that retrieveContext respects examplesPerLabel option
     */
    public function test_retrieve_context_respects_examples_per_label_option()
    {
        $question = 'Test question';
        $examplesPerLabel = 5;

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => ['Team'], 'relationshipTypes' => [], 'propertyKeys' => []]);

        // Verify limit parameter is used
        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->with(Mockery::any(), ['limit' => $examplesPerLabel])
            ->andReturn([]);

        $this->service->retrieveContext($question, ['examplesPerLabel' => $examplesPerLabel]);

        $this->assertTrue(true);
    }

    /**
     * Test that retrieveContext respects scoreThreshold option
     */
    public function test_retrieve_context_respects_score_threshold_option()
    {
        $question = 'Test question';
        $scoreThreshold = 0.7;

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        // Verify scoreThreshold is passed to search
        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), $scoreThreshold)
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $this->service->retrieveContext($question, ['scoreThreshold' => $scoreThreshold]);

        $this->assertTrue(true);
    }

    /**
     * Test graceful degradation when vector search fails
     */
    public function test_retrieve_context_handles_vector_store_failure_gracefully()
    {
        $question = 'Test question';

        // Vector search fails
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andThrow(new RuntimeException('Embedding API failed'));

        // Graph operations still succeed
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => ['Team'], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->andReturn([['n' => ['id' => 1, 'name' => 'Alpha']]]);

        $context = $this->service->retrieveContext($question);

        // Vector search failed but graph data succeeded
        $this->assertEmpty($context['similar_queries']);
        $this->assertNotEmpty($context['graph_schema']);
        $this->assertNotEmpty($context['relevant_entities']);
        $this->assertNotEmpty($context['errors']);
        $this->assertStringContainsString('Vector search failed', $context['errors'][0]);
    }

    /**
     * Test graceful degradation when graph schema retrieval fails
     */
    public function test_retrieve_context_handles_graph_store_failure_gracefully()
    {
        $question = 'Test question';

        // Vector search succeeds
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'score' => 0.89,
                    'payload' => ['question' => 'Test', 'query' => 'MATCH (n) RETURN n'],
                ],
            ]);

        // Graph schema fails
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andThrow(new RuntimeException('Neo4j connection failed'));

        $context = $this->service->retrieveContext($question);

        // Vector search succeeded but graph failed
        $this->assertNotEmpty($context['similar_queries']);
        $this->assertEmpty($context['graph_schema']);
        $this->assertEmpty($context['relevant_entities']);
        $this->assertNotEmpty($context['errors']);
        $this->assertStringContainsString('Schema retrieval failed', $context['errors'][0]);
    }

    /**
     * Test graceful degradation when embedding generation fails
     */
    public function test_retrieve_context_handles_embedding_generation_failure()
    {
        $question = 'Test question';

        // Embedding fails
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andThrow(new Exception('API rate limit exceeded'));

        // Graph still works
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $context = $this->service->retrieveContext($question);

        $this->assertEmpty($context['similar_queries']);
        $this->assertNotEmpty($context['errors']);
        $this->assertStringContainsString('Vector search failed', $context['errors'][0]);
    }

    /**
     * Test that retrieveContext returns proper structure with all required keys
     */
    public function test_retrieve_context_returns_proper_structure()
    {
        $question = 'Test question';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $context = $this->service->retrieveContext($question);

        $this->assertIsArray($context);
        $this->assertArrayHasKey('similar_queries', $context);
        $this->assertArrayHasKey('graph_schema', $context);
        $this->assertArrayHasKey('relevant_entities', $context);
        $this->assertArrayHasKey('errors', $context);

        $this->assertIsArray($context['similar_queries']);
        $this->assertIsArray($context['graph_schema']);
        $this->assertIsArray($context['relevant_entities']);
        $this->assertIsArray($context['errors']);
    }

    /**
     * Test that retrieveContext collects multiple errors
     */
    public function test_retrieve_context_collects_multiple_errors()
    {
        $question = 'Test question';

        // Vector search fails
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andThrow(new Exception('Embedding failed'));

        // Schema retrieval fails
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andThrow(new Exception('Schema failed'));

        $context = $this->service->retrieveContext($question);

        $this->assertCount(2, $context['errors']);
        $this->assertStringContainsString('Vector search failed', $context['errors'][0]);
        $this->assertStringContainsString('Schema retrieval failed', $context['errors'][1]);
    }

    // =========================================================================
    // searchSimilar() Method Tests
    // =========================================================================

    /**
     * Test that searchSimilar embeds question successfully
     */
    public function test_search_similar_embeds_question_successfully()
    {
        $question = 'Show active users';
        $embedding = [0.1, 0.2, 0.3];

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->with($question)
            ->andReturn($embedding);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $this->service->searchSimilar($question);

        // Mockery will verify expectations
        $this->assertTrue(true);
    }

    /**
     * Test that searchSimilar searches vector store with correct parameters
     */
    public function test_search_similar_searches_vector_store_with_correct_parameters()
    {
        $question = 'Test query';
        $collection = 'my_collection';
        $limit = 10;
        $embedding = [0.5, 0.6];

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn($embedding);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with($collection, $embedding, $limit, [], 0.0)
            ->andReturn([]);

        $this->service->searchSimilar($question, $collection, $limit);

        $this->assertTrue(true);
    }

    /**
     * Test that searchSimilar returns formatted results with scores
     */
    public function test_search_similar_returns_formatted_results_with_scores()
    {
        $question = 'Find customers';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'score' => 0.89,
                    'payload' => [
                        'question' => 'List all customers',
                        'cypher_query' => 'MATCH (c:Customer) RETURN c',
                        'category' => 'retrieval',
                    ],
                ],
                [
                    'score' => 0.75,
                    'payload' => [
                        'question' => 'Show customer details',
                        'query' => 'MATCH (c:Customer) RETURN c.name, c.email',
                    ],
                ],
            ]);

        $results = $this->service->searchSimilar($question);

        $this->assertCount(2, $results);

        // First result
        $this->assertEquals('List all customers', $results[0]['question']);
        $this->assertEquals('MATCH (c:Customer) RETURN c', $results[0]['query']);
        $this->assertEquals(0.89, $results[0]['score']);
        $this->assertArrayHasKey('metadata', $results[0]);

        // Second result (uses 'query' fallback)
        $this->assertEquals('Show customer details', $results[1]['question']);
        $this->assertEquals('MATCH (c:Customer) RETURN c.name, c.email', $results[1]['query']);
        $this->assertEquals(0.75, $results[1]['score']);
    }

    /**
     * Test that searchSimilar handles empty results
     */
    public function test_search_similar_handles_empty_results()
    {
        $question = 'Unknown query';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $results = $this->service->searchSimilar($question);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /**
     * Test that searchSimilar throws exception when embedding fails
     */
    public function test_search_similar_throws_exception_when_embedding_fails()
    {
        $question = 'Test query';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andThrow(new Exception('API error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to generate embedding for question');

        $this->service->searchSimilar($question);
    }

    /**
     * Test that searchSimilar throws exception when embedding provider returns empty vector
     */
    public function test_search_similar_throws_exception_for_empty_embedding()
    {
        $question = 'Test query';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embedding provider returned empty vector');

        $this->service->searchSimilar($question);
    }

    /**
     * Test that searchSimilar throws exception when vector store search fails
     */
    public function test_search_similar_throws_exception_when_vector_store_fails()
    {
        $question = 'Test query';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1, 0.2]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andThrow(new Exception('Connection timeout'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vector store search failed');

        $this->service->searchSimilar($question);
    }

    /**
     * Test that searchSimilar uses default parameters when not provided
     */
    public function test_search_similar_uses_default_parameters()
    {
        $question = 'Test query';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        // Should use default collection='questions' and limit=5
        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with('questions', Mockery::any(), 5, [], 0.0)
            ->andReturn([]);

        $this->service->searchSimilar($question);

        $this->assertTrue(true);
    }

    // =========================================================================
    // getGraphSchema() Method Tests
    // =========================================================================

    /**
     * Test that getGraphSchema retrieves schema from graph store
     */
    public function test_get_graph_schema_retrieves_schema_from_graph_store()
    {
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Team', 'Person'],
                'relationshipTypes' => ['MEMBER_OF', 'MANAGES'],
                'propertyKeys' => ['id', 'name', 'email'],
            ]);

        $schema = $this->service->getGraphSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('labels', $schema);
        $this->assertArrayHasKey('relationships', $schema);
        $this->assertArrayHasKey('properties', $schema);
    }

    /**
     * Test that getGraphSchema returns normalized schema format
     */
    public function test_get_graph_schema_returns_normalized_format()
    {
        // Test with relationshipTypes key (Neo4j format)
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Team'],
                'relationshipTypes' => ['MEMBER_OF'],
                'propertyKeys' => ['id'],
            ]);

        $schema = $this->service->getGraphSchema();

        $this->assertEquals(['Team'], $schema['labels']);
        $this->assertEquals(['MEMBER_OF'], $schema['relationships']);
        $this->assertEquals(['id'], $schema['properties']);
    }

    /**
     * Test that getGraphSchema normalizes relationships key
     */
    public function test_get_graph_schema_normalizes_relationships_key()
    {
        // Test with 'relationships' key instead of 'relationshipTypes'
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Customer'],
                'relationships' => ['PURCHASED'],
                'propertyKeys' => ['id', 'name'],
            ]);

        $schema = $this->service->getGraphSchema();

        $this->assertEquals(['PURCHASED'], $schema['relationships']);
    }

    /**
     * Test that getGraphSchema normalizes properties key
     */
    public function test_get_graph_schema_normalizes_properties_key()
    {
        // Test with 'properties' key instead of 'propertyKeys'
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Product'],
                'relationshipTypes' => ['IN_CATEGORY'],
                'properties' => ['id', 'price', 'sku'],
            ]);

        $schema = $this->service->getGraphSchema();

        $this->assertEquals(['id', 'price', 'sku'], $schema['properties']);
    }

    /**
     * Test that getGraphSchema handles missing schema data
     */
    public function test_get_graph_schema_handles_missing_schema_data()
    {
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([]);

        $schema = $this->service->getGraphSchema();

        $this->assertEquals([], $schema['labels']);
        $this->assertEquals([], $schema['relationships']);
        $this->assertEquals([], $schema['properties']);
    }

    /**
     * Test that getGraphSchema throws exception when graph store fails
     */
    public function test_get_graph_schema_throws_exception_on_graph_store_failure()
    {
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andThrow(new Exception('Database connection failed'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database connection failed');

        $this->service->getGraphSchema();
    }

    // =========================================================================
    // getExampleEntities() Method Tests
    // =========================================================================

    /**
     * Test that getExampleEntities retrieves examples for a label
     */
    public function test_get_example_entities_retrieves_examples_for_label()
    {
        $label = 'Team';
        $limit = 3;

        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->with("MATCH (n:`{$label}`) RETURN n LIMIT \$limit", ['limit' => $limit])
            ->andReturn([
                ['n' => ['id' => 1, 'name' => 'Alpha Team']],
                ['n' => ['id' => 2, 'name' => 'Beta Team']],
            ]);

        $entities = $this->service->getExampleEntities($label, $limit);

        $this->assertCount(2, $entities);
        $this->assertEquals(['id' => 1, 'name' => 'Alpha Team'], $entities[0]);
        $this->assertEquals(['id' => 2, 'name' => 'Beta Team'], $entities[1]);
    }

    /**
     * Test that getExampleEntities respects limit parameter
     */
    public function test_get_example_entities_respects_limit_parameter()
    {
        $label = 'Person';
        $limit = 5;

        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->with(Mockery::any(), ['limit' => $limit])
            ->andReturn([]);

        $this->service->getExampleEntities($label, $limit);

        $this->assertTrue(true);
    }

    /**
     * Test that getExampleEntities uses default limit
     */
    public function test_get_example_entities_uses_default_limit()
    {
        $label = 'Customer';

        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->with(Mockery::any(), ['limit' => 3])
            ->andReturn([]);

        $this->service->getExampleEntities($label);

        $this->assertTrue(true);
    }

    /**
     * Test that getExampleEntities validates label name
     */
    public function test_get_example_entities_validates_label_name()
    {
        $invalidLabel = 'Team"; DROP TABLE users; --';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid label name');

        $this->service->getExampleEntities($invalidLabel);
    }

    /**
     * Test that getExampleEntities throws exception for empty label
     */
    public function test_get_example_entities_throws_exception_for_empty_label()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Label cannot be empty');

        $this->service->getExampleEntities('');
    }

    /**
     * Test that getExampleEntities accepts valid label with underscores
     */
    public function test_get_example_entities_accepts_valid_label_with_underscores()
    {
        $label = 'User_Account';

        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->andReturn([]);

        $this->service->getExampleEntities($label);

        $this->assertTrue(true);
    }

    /**
     * Test that getExampleEntities accepts valid label with hyphens
     */
    public function test_get_example_entities_accepts_valid_label_with_hyphens()
    {
        $label = 'Customer-Account';

        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->andReturn([]);

        $this->service->getExampleEntities($label);

        $this->assertTrue(true);
    }

    /**
     * Test that getExampleEntities rejects label starting with number
     */
    public function test_get_example_entities_rejects_label_starting_with_number()
    {
        $invalidLabel = '123Team';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid label name');

        $this->service->getExampleEntities($invalidLabel);
    }

    /**
     * Test that getExampleEntities rejects label with special characters
     */
    public function test_get_example_entities_rejects_label_with_special_characters()
    {
        $invalidLabel = 'Team@Organization';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid label name');

        $this->service->getExampleEntities($invalidLabel);
    }

    /**
     * Test that getExampleEntities handles query failures gracefully
     */
    public function test_get_example_entities_handles_query_failures()
    {
        $label = 'Team';

        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->andThrow(new Exception('Query execution failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to retrieve example entities for label 'Team'");

        $this->service->getExampleEntities($label);
    }

    /**
     * Test that getExampleEntities returns empty array for no results
     */
    public function test_get_example_entities_returns_empty_array_for_no_results()
    {
        $label = 'NonExistentLabel';

        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->andReturn([]);

        $entities = $this->service->getExampleEntities($label);

        $this->assertIsArray($entities);
        $this->assertEmpty($entities);
    }

    /**
     * Test that getExampleEntities extracts node properties correctly
     */
    public function test_get_example_entities_extracts_node_properties_correctly()
    {
        $label = 'Person';

        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->andReturn([
                ['n' => ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com']],
                ['n' => ['id' => 2, 'name' => 'Jane Smith', 'email' => 'jane@example.com']],
            ]);

        $entities = $this->service->getExampleEntities($label);

        $this->assertCount(2, $entities);
        $this->assertEquals('John Doe', $entities[0]['name']);
        $this->assertEquals('jane@example.com', $entities[1]['email']);
    }

    /**
     * Test that getExampleEntities handles missing node data gracefully
     */
    public function test_get_example_entities_handles_missing_node_data()
    {
        $label = 'Team';

        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->andReturn([
                ['n' => ['id' => 1, 'name' => 'Team A']],
                ['other_key' => ['id' => 2]], // Missing 'n' key
                ['n' => ['id' => 3, 'name' => 'Team C']],
            ]);

        $entities = $this->service->getExampleEntities($label);

        $this->assertCount(3, $entities);
        $this->assertEquals(['id' => 1, 'name' => 'Team A'], $entities[0]);
        $this->assertEquals([], $entities[1]); // Empty for missing 'n' key
        $this->assertEquals(['id' => 3, 'name' => 'Team C'], $entities[2]);
    }

    // =========================================================================
    // Integration Tests (Multiple Methods)
    // =========================================================================

    /**
     * Test that retrieveContext skips example retrieval when no labels exist
     */
    public function test_retrieve_context_skips_examples_when_no_labels_exist()
    {
        $question = 'Test query';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        // Schema has no labels
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        // query() should NOT be called
        $this->mockGraphStore
            ->shouldNotReceive('query');

        $context = $this->service->retrieveContext($question);

        $this->assertEmpty($context['relevant_entities']);
    }

    /**
     * Test that retrieveContext handles individual example retrieval failures
     */
    public function test_retrieve_context_handles_individual_example_failures()
    {
        $question = 'Test query';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => ['Team', 'Person'], 'relationshipTypes' => [], 'propertyKeys' => []]);

        // First label succeeds, second fails
        $this->mockGraphStore
            ->shouldReceive('query')
            ->twice()
            ->andReturnUsing(function ($cypher, $params) {
                if (strpos($cypher, 'Team') !== false) {
                    return [['n' => ['id' => 1, 'name' => 'Alpha']]];
                }
                throw new Exception('Query failed for Person');
            });

        $context = $this->service->retrieveContext($question);

        // Team examples retrieved, Person failed
        $this->assertArrayHasKey('Team', $context['relevant_entities']);
        $this->assertArrayNotHasKey('Person', $context['relevant_entities']);
        $this->assertNotEmpty($context['errors']);
        $this->assertStringContainsString("Example retrieval for label 'Person' failed", $context['errors'][0]);
    }

    /**
     * Test complete workflow with all components working
     */
    public function test_complete_workflow_with_all_components()
    {
        $question = 'Show me active teams';

        // Mock embedding
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->with($question)
            ->andReturn([0.1, 0.2, 0.3]);

        // Mock vector search
        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'score' => 0.92,
                    'payload' => [
                        'question' => 'List all teams',
                        'cypher_query' => 'MATCH (t:Team) RETURN t',
                    ],
                ],
            ]);

        // Mock schema
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Team', 'Person'],
                'relationshipTypes' => ['MEMBER_OF'],
                'propertyKeys' => ['id', 'name', 'active'],
            ]);

        // Mock example entities
        $this->mockGraphStore
            ->shouldReceive('query')
            ->twice()
            ->andReturn(
                [['n' => ['id' => 1, 'name' => 'Alpha Team', 'active' => true]]],
                [['n' => ['id' => 1, 'name' => 'John Doe']]]
            );

        $context = $this->service->retrieveContext($question);

        // Verify all components
        $this->assertCount(1, $context['similar_queries']);
        $this->assertEquals(0.92, $context['similar_queries'][0]['score']);

        $this->assertCount(2, $context['graph_schema']['labels']);
        $this->assertContains('Team', $context['graph_schema']['labels']);

        $this->assertArrayHasKey('Team', $context['relevant_entities']);
        $this->assertArrayHasKey('Person', $context['relevant_entities']);

        $this->assertEmpty($context['errors']);
    }
}
