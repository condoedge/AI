<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Support\AiLogger;
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
    private $logger;
    private $service;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        // The similar-query searcher now guards on collection existence before
        // searching; default it present so existing search expectations still fire.
        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $this->mockGraphStore = Mockery::mock(GraphStoreInterface::class);
        $this->mockEmbeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);
        $this->logger = new AiLogger();

        $this->service = new ContextRetriever(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider,
            $this->logger,
            [] // Empty entity configs for testing
        );
    }

    /** Every graph query() issued during retrieval (Task 5.2 type probes only). */
    private array $graphQueries = [];

    /**
     * Task 1.7: retrieval must never sample example nodes (cross-tenant rows sent to
     * the LLM). Since Task 5.2 the ONLY graph read beyond the schema is the cached
     * per-label property-type probe, whose RETURN carries type names and boolean
     * format flags only — never node values. Record every query and assert that.
     */
    private function allowOnlyValueFreeTypeProbes(): void
    {
        $this->mockGraphStore->shouldReceive('query')->andReturnUsing(function (string $cypher) {
            $this->graphQueries[] = $cypher;

            return [];
        });
    }

    private function assertOnlyValueFreeTypeProbes(): void
    {
        foreach ($this->graphQueries as $cypher) {
            $this->assertMatchesRegularExpression('/^MATCH \(n:`(\w+)`\)/', $cypher);
            preg_match('/^MATCH \(n:(`\w+`)\)/', $cypher, $m);
            $this->assertSame(\Condoedge\Ai\Services\Schema\PropertyTypeSampler::probeCypher($m[1]), $cypher);
        }
    }

    public function tearDown(): void
    {
        $this->assertOnlyValueFreeTypeProbes();
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
            $this->mockEmbeddingProvider,
            $this->logger,
            []
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

        // Mock embedding generation (also used by the schema-relevance fallback)
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->with($question)
            ->andReturn([0.1, 0.2, 0.3]);

        // Mock similar-query vector search
        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('questions', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn([
                [
                    'score' => 0.89,
                    'payload' => [
                        'question' => 'List all teams',
                        'cypher_query' => 'MATCH (t:Team) RETURN t',
                    ],
                ],
            ]);

        // Schema fallback queries the 'semantic_entities' collection (no selector configured)
        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('semantic_entities', Mockery::any(), Mockery::any())
            ->andReturn([]);

        // Mock graph schema retrieval
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Team', 'Person'],
                'relationshipTypes' => ['MEMBER_OF'],
                'propertyKeys' => ['id', 'name'],
            ]);

        // Task 1.7: no example-node sampling (was: query() twice, Team + Person,
        // unscoped). Retrieval must not touch the graph beyond the schema.
        $this->allowOnlyValueFreeTypeProbes();

        // Act
        $context = $this->service->retrieveContext($question);

        // Assert
        $this->assertInstanceOf(PipelineContext::class, $context);

        // The context must carry the question it was built for, so the grounded
        // response path sees what was asked (not just the rows).
        $this->assertSame($question, $context->question);

        $this->assertNotEmpty($context->similarQueries);
        $this->assertNotEmpty($context->graphSchema);
        $this->assertEmpty($context->errors);
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
            ->andReturn([0.1]);

        // Verify custom collection is used for the similar-query search
        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with($customCollection, Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn([]);

        // Schema fallback queries the 'semantic_entities' collection (no selector configured)
        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('semantic_entities', Mockery::any(), Mockery::any())
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
            ->andReturn([0.1]);

        // Verify custom limit is used on the similar-query search ('questions' collection)
        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with('questions', Mockery::any(), $customLimit, Mockery::any(), Mockery::any())
            ->andReturn([]);

        // Schema fallback queries the 'semantic_entities' collection (no selector configured)
        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('semantic_entities', Mockery::any(), Mockery::any())
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

        $this->assertEmpty($context->graphSchema);
    }

    /**
     * Test that current identity labels survive schema filtering even when the
     * relevance fallback selects a different entity.
     */
    public function test_retrieve_context_keeps_identity_labels_in_filtered_schema()
    {
        config(['ai.semantic_context.always_include_identity_labels' => ['User', 'Team']]);

        $question = 'Show invoice totals';
        $entityConfigs = [
            'App\\Models\\Billing\\Invoice' => [
                'graph' => ['label' => 'Invoice', 'properties' => ['id', 'total']],
            ],
            'App\\Models\\Auth\\User' => [
                'graph' => ['label' => 'User', 'properties' => ['id', 'email', 'current_team_id']],
            ],
            'App\\Models\\Auth\\Team' => [
                'graph' => ['label' => 'Team', 'properties' => ['id', 'team_name']],
            ],
        ];
        $service = new ContextRetriever(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider,
            $this->logger,
            $entityConfigs
        );

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('questions', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn([]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('semantic_entities', Mockery::any(), Mockery::any())
            ->andReturn([
                ['payload' => ['label' => 'Invoice']],
            ]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Invoice', 'User', 'Team', 'Contact'],
                'relationshipTypes' => ['BELONGS_TO', 'HAS_CONTACT'],
                'relationshipPatterns' => [
                    ['from_label' => 'User', 'type' => 'BELONGS_TO', 'to_label' => 'Team', 'count' => 4],
                    ['from_label' => 'Contact', 'type' => 'HAS_CONTACT', 'to_label' => 'User', 'count' => 2],
                ],
                'propertyKeys' => ['id', 'total', 'email', 'current_team_id', 'team_name'],
            ]);

        $context = $service->retrieveContext($question);

        $this->assertContains('Invoice', $context->graphSchema['labels']);
        $this->assertContains('User', $context->graphSchema['labels']);
        $this->assertContains('Team', $context->graphSchema['labels']);
        $this->assertNotContains('Contact', $context->graphSchema['labels']);
        $this->assertArrayHasKey('User', $context->graphSchema['properties']);
        $this->assertArrayHasKey('Team', $context->graphSchema['properties']);
        $this->assertSame(['User', 'Team'], $context->selectionInfo['identity_entities']);
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
            ->andReturn([0.1]);

        // Verify scoreThreshold is passed to the similar-query search
        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), $scoreThreshold)
            ->andReturn([]);

        // Schema fallback queries the 'semantic_entities' collection (no selector configured)
        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('semantic_entities', Mockery::any(), Mockery::any())
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $this->service->retrieveContext($question, ['scoreThreshold' => $scoreThreshold]);

        $this->assertTrue(true);
    }

    /**
     * Task 1.9: without an explicit scoreThreshold option, few-shot retrieval uses
     * the config-driven ai.query_generation.example_min_similarity (default 0.80)
     * instead of 0.0 (where ANY similarity counted as a usable example).
     *
     * @dataProvider exampleMinSimilarityProvider
     */
    public function test_similar_query_threshold_defaults_to_the_configured_example_min_similarity(?float $configured, float $expected)
    {
        if ($configured === null) {
            // A host config that predates the key (shallow-merged query_generation).
            config()->set('ai.query_generation', ['default_limit' => 100]);
        } else {
            config()->set('ai.query_generation.example_min_similarity', $configured);
        }

        $this->mockEmbeddingProvider->shouldReceive('embed')->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with('questions', Mockery::any(), Mockery::any(), Mockery::any(), $expected)
            ->andReturn([]);
        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('semantic_entities', Mockery::any(), Mockery::any())
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $this->service->retrieveContext('Test question');

        $this->assertTrue(true);
    }

    public static function exampleMinSimilarityProvider(): array
    {
        return [
            'unset falls back to 0.80' => [null, 0.80],
            'configured value wins' => [0.65, 0.65],
        ];
    }

    /** Review M-5: an empty env value (AI_EXAMPLE_MIN_SIMILARITY="") must not become 0.0. */
    public function test_a_non_numeric_configured_similarity_falls_back_to_the_default()
    {
        config()->set('ai.query_generation.example_min_similarity', '');

        $this->mockEmbeddingProvider->shouldReceive('embed')->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('search')->once()
            ->with('questions', Mockery::any(), Mockery::any(), Mockery::any(), 0.80)
            ->andReturn([]);

        $this->service->searchSimilar('Test question');

        $this->assertTrue(true);
    }

    /** Review M-4: the public searchSimilar() honours the configured threshold too. */
    public function test_search_similar_uses_the_configured_example_min_similarity()
    {
        config()->set('ai.query_generation.example_min_similarity', 0.7);

        $this->mockEmbeddingProvider->shouldReceive('embed')->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('search')->once()
            ->with('questions', Mockery::any(), 5, Mockery::any(), 0.7)
            ->andReturn([]);

        $this->service->searchSimilar('Test question');

        $this->assertTrue(true);
    }

    /**
     * Test graceful degradation when vector search fails
     */
    public function test_retrieve_context_handles_vector_store_failure_gracefully()
    {
        $question = 'Test question';

        // Vector search fails (embedding throws on both the similar-query and
        // the schema-fallback paths; the fallback swallows its own failure)
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->andThrow(new RuntimeException('Embedding API failed'));

        // Graph operations still succeed
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => ['Team'], 'relationshipTypes' => [], 'propertyKeys' => []]);

        // Task 1.7: no example sampling.
        $this->allowOnlyValueFreeTypeProbes();

        $context = $this->service->retrieveContext($question);

        // Vector search failed but graph data succeeded
        $this->assertEmpty($context->similarQueries);
        $this->assertNotEmpty($context->graphSchema);
        $this->assertNotEmpty($context->errors);
        $this->assertStringContainsString('Vector search failed', $context->errors[0]);
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
        $this->assertNotEmpty($context->similarQueries);
        $this->assertEmpty($context->graphSchema);
        $this->assertNotEmpty($context->errors);
        $this->assertStringContainsString('Schema retrieval failed', $context->errors[0]);
    }

    /**
     * Test graceful degradation when embedding generation fails
     */
    public function test_retrieve_context_handles_embedding_generation_failure()
    {
        $question = 'Test question';

        // Embedding fails (on both the similar-query and schema-fallback paths)
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->andThrow(new Exception('API rate limit exceeded'));

        // Graph still works
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $context = $this->service->retrieveContext($question);

        $this->assertEmpty($context->similarQueries);
        $this->assertNotEmpty($context->errors);
        $this->assertStringContainsString('Vector search failed', $context->errors[0]);
    }

    /**
     * Test that retrieveContext returns proper structure with all required keys
     */
    public function test_retrieve_context_returns_proper_structure()
    {
        $question = 'Test question';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $context = $this->service->retrieveContext($question);

        $this->assertInstanceOf(PipelineContext::class, $context);

        $this->assertIsArray($context->similarQueries);
        $this->assertIsArray($context->graphSchema);
        $this->assertIsArray($context->errors);
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

        $this->assertCount(2, $context->errors);
        $this->assertStringContainsString('Vector search failed', $context->errors[0]);
        $this->assertStringContainsString('Schema retrieval failed', $context->errors[1]);
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
            // Review M-4: the configured example_min_similarity (0.80 default), not 0.0.
            ->with($collection, $embedding, $limit, [], 0.80)
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
                    // Above the 0.80 example_min_similarity floor (review M-4).
                    'score' => 0.82,
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

        // Second result (uses 'query' fallback)
        $this->assertEquals('Show customer details', $results[1]['question']);
        $this->assertEquals('MATCH (c:Customer) RETURN c.name, c.email', $results[1]['query']);
        $this->assertEquals(0.82, $results[1]['score']);
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

        // Should use default collection='questions', limit=5 and the configured
        // example_min_similarity (0.80 default — review M-4)
        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with('questions', Mockery::any(), 5, [], 0.80)
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
     * Plan 9.0-p: graph.computed properties are real node properties, so the per-label
     * property list the schema cards/projector read must include them.
     */
    public function test_get_graph_schema_lists_computed_properties_per_label()
    {
        $this->mockGraphStore->shouldReceive('getSchema')->andReturn(['labels' => ['Invoice']]);

        $service = new ContextRetriever(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider,
            $this->logger,
            ['Invoice' => ['graph' => [
                'label' => 'Invoice',
                'properties' => ['id', 'status'],
                'computed' => ['due_amount' => 'real_due_amount', 'status' => 'status_label'],
            ]]]
        );

        $schema = $service->getGraphSchema();

        $this->assertSame(['id', 'status', 'due_amount'], $schema['properties']['Invoice']);
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
                'relationshipPatterns' => [
                    ['from_label' => 'User', 'type' => 'MEMBER_OF', 'to_label' => 'Team', 'count' => 4],
                ],
                'propertyKeys' => ['id'],
            ]);

        $schema = $this->service->getGraphSchema();

        $this->assertEquals(['Team'], $schema['labels']);
        $this->assertEquals(['MEMBER_OF'], $schema['relationships']);
        $this->assertEquals([
            ['from_label' => 'User', 'type' => 'MEMBER_OF', 'to_label' => 'Team', 'count' => 4],
        ], $schema['relationshipPatterns']);
        // The flat property-key list from the store is normalized under 'propertyKeys';
        // 'properties' is reserved for the per-label structure derived from entity configs.
        $this->assertEquals(['id'], $schema['propertyKeys']);
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

        // A flat 'properties' list from the store is normalized into 'propertyKeys'.
        $this->assertEquals(['id', 'price', 'sku'], $schema['propertyKeys']);
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
    // Integration Tests (Multiple Methods)
    // =========================================================================

    /**
     * Task 1.7: example-node sampling ran `MATCH (n:Label) … ORDER BY size(keys(n))`
     * WITHOUT any tenant filter and shipped the rows into the query-generation
     * prompt — other tenants' data sent to the LLM provider. Retrieval must never
     * sample example nodes, even when labels exist.
     */
    /**
     * Review I-1: a failed pattern scan (null) must reach the prompt as "unknown",
     * never be coerced to [] ("no edges exist").
     */
    public function test_unknown_relationship_patterns_stay_null_in_the_context()
    {
        $this->mockEmbeddingProvider->shouldReceive('embed')->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('search')->andReturn([]);
        $this->mockGraphStore->shouldReceive('getSchema')
            ->andReturn(['labels' => ['Team'], 'relationshipTypes' => ['MEMBER_OF'], 'relationshipPatterns' => null, 'propertyKeys' => []]);
        $this->allowOnlyValueFreeTypeProbes();

        $context = $this->service->retrieveContext('Show me the teams');

        $this->assertArrayHasKey('all_relationship_patterns', $context->graphSchema);
        $this->assertNull($context->graphSchema['all_relationship_patterns']);
    }

    /** Review I-3: only the labels that will get a schema card are type-probed. */
    public function test_only_the_carded_labels_are_type_probed()
    {
        $labels = array_map(fn ($i) => 'L' . $i, range(1, \Condoedge\Ai\Services\Schema\SchemaCardBuilder::MAX_CARDS + 5));
        $this->mockEmbeddingProvider->shouldReceive('embed')->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('search')->andReturn([]);
        $this->mockGraphStore->shouldReceive('getSchema')
            ->andReturn(['labels' => $labels, 'relationshipTypes' => [], 'relationshipPatterns' => [], 'propertyKeys' => []]);
        $this->allowOnlyValueFreeTypeProbes();

        $this->service->retrieveContext('Show me everything');

        $probed = array_map(fn ($c) => preg_match('/^MATCH \(n:`(\w+)`\)/', $c, $m) ? $m[1] : null, $this->graphQueries);
        $this->assertSame(array_slice($labels, 0, \Condoedge\Ai\Services\Schema\SchemaCardBuilder::MAX_CARDS), $probed);
    }

    public function test_retrieve_context_never_samples_example_nodes()
    {
        $this->mockEmbeddingProvider->shouldReceive('embed')->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('search')->andReturn([]);
        $this->mockGraphStore->shouldReceive('getSchema')->once()
            ->andReturn(['labels' => ['Team', 'Person'], 'relationshipTypes' => [], 'propertyKeys' => []]);

        // No Neo4j sampling query may be issued at all.
        $this->allowOnlyValueFreeTypeProbes();

        $context = $this->service->retrieveContext('Show me the teams');

        $this->assertInstanceOf(\Condoedge\Ai\DTOs\PipelineContext::class, $context);
    }

    /**
     * Test that retrieveContext skips example retrieval when no labels exist
     */
    public function test_retrieve_context_skips_examples_when_no_labels_exist()
    {
        $question = 'Test query';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
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

        $this->assertInstanceOf(\Condoedge\Ai\DTOs\PipelineContext::class, $context);
    }

    /**
     * Test complete workflow with all components working
     */
    public function test_complete_workflow_with_all_components()
    {
        $question = 'Show me active teams';

        // Mock embedding (also used by the schema-relevance fallback)
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->with($question)
            ->andReturn([0.1, 0.2, 0.3]);

        // Mock similar-query vector search
        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('questions', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn([
                [
                    'score' => 0.92,
                    'payload' => [
                        'question' => 'List all teams',
                        'cypher_query' => 'MATCH (t:Team) RETURN t',
                    ],
                ],
            ]);

        // Schema fallback queries the 'semantic_entities' collection (no selector configured)
        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('semantic_entities', Mockery::any(), Mockery::any())
            ->andReturn([]);

        // Mock schema
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Team', 'Person'],
                'relationshipTypes' => ['MEMBER_OF'],
                'propertyKeys' => ['id', 'name', 'active'],
            ]);

        // Task 1.7: example entities are no longer sampled.
        $this->allowOnlyValueFreeTypeProbes();

        $context = $this->service->retrieveContext($question);

        // Verify all components
        $this->assertCount(1, $context->similarQueries);
        $this->assertEquals(0.92, $context->similarQueries[0]['score']);

        $this->assertCount(2, $context->graphSchema['labels']);
        $this->assertContains('Team', $context->graphSchema['labels']);

        $this->assertEmpty($context->errors);
    }
}
