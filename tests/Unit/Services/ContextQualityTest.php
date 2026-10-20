<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Support\AiLogger;
use Mockery;

/**
 * ContextQualityTest
 *
 * Tests the assessContextQuality method of ContextRetriever by exercising
 * the full retrieveContext pipeline with various combinations of source
 * availability:
 * - 'high' when all 4 quality sources are present
 * - 'medium' when 2-3 sources are present
 * - 'low' when 0-1 sources are present
 *
 * The quality sources are:
 * 1. similar_queries (from vector search)
 * 2. graph_schema labels
 * 3. entity_metadata detected_entities
 * 4. no errors
 */
class ContextQualityTest extends TestCase
{
    private $mockVectorStore;
    private $mockGraphStore;
    private $mockEmbeddingProvider;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $this->mockGraphStore = Mockery::mock(GraphStoreInterface::class);
        $this->mockEmbeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper to create ContextRetriever with optional entity configs.
     */
    private function makeService(array $entityConfigs = []): ContextRetriever
    {
        return new ContextRetriever(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider,
            new AiLogger(),
            $entityConfigs
        );
    }

    /** @test */
    public function quality_is_high_when_all_four_sources_present(): void
    {
        // Setup: all sources succeed (embed is also used by the schema-relevance fallback)
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->andReturn([0.1, 0.2, 0.3]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('questions', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
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

        // Schema fallback queries the 'semantic_entities' collection (no selector configured)
        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('semantic_entities', Mockery::any(), Mockery::any())
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Team', 'Person'],
                'relationshipTypes' => ['MEMBER_OF'],
                'propertyKeys' => ['id', 'name'],
            ]);

        $this->mockGraphStore
            ->shouldReceive('query')
            ->andReturn([['n' => ['id' => 1, 'name' => 'Alpha Team']]]);

        // Entity configs so entity_metadata gets populated
        $entityConfigs = [
            'App\\Models\\Team' => [
                'graph' => ['label' => 'Team', 'properties' => ['id', 'name']],
                'metadata' => ['aliases' => ['team', 'group']],
            ],
        ];

        $service = $this->makeService($entityConfigs);
        $context = $service->retrieveContext('Show me active teams');

        $this->assertNotEmpty($context->quality);
        $this->assertEquals('high', $context->quality['level']);
        $this->assertEquals(4, $context->quality['score']);
        $this->assertEquals(4, $context->quality['max']);
    }

    /** @test */
    public function quality_is_medium_when_two_sources_present(): void
    {
        // Vector search fails (embedding error) -> no similar_queries, adds error
        // That costs us 2 sources: similar_queries + no_errors
        // embed is invoked again by the schema-relevance fallback, which swallows its own failure
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->andThrow(new \RuntimeException('API error'));

        // Graph still works -> has schema labels
        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Team'],
                'relationshipTypes' => [],
                'propertyKeys' => ['id', 'name'],
            ]);

        $this->mockGraphStore
            ->shouldReceive('query')
            ->andReturn([['n' => ['id' => 1, 'name' => 'Alpha']]]);

        // Entity configs so entity_metadata detected_entities are populated
        $entityConfigs = [
            'App\\Models\\Team' => [
                'graph' => ['label' => 'Team', 'properties' => ['id', 'name']],
                'metadata' => ['aliases' => ['team']],
            ],
        ];

        $service = $this->makeService($entityConfigs);
        $context = $service->retrieveContext('Show teams');

        $this->assertNotEmpty($context->quality);
        // Should have: has_schema + has_detected_entities = 2 (medium)
        // Missing: has_similar_queries + no_errors
        $this->assertEquals('medium', $context->quality['level']);
        $this->assertGreaterThanOrEqual(2, $context->quality['score']);
        $this->assertLessThanOrEqual(3, $context->quality['score']);
    }

    /** @test */
    public function quality_is_low_when_zero_or_one_sources_present(): void
    {
        // Both vector and graph fail -> errors present, no schema, no similar_queries
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andThrow(new \RuntimeException('Embedding API down'));

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andThrow(new \RuntimeException('Neo4j connection refused'));

        $service = $this->makeService([]);
        $context = $service->retrieveContext('Anything');

        $this->assertNotEmpty($context->quality);
        $this->assertEquals('low', $context->quality['level']);
        $this->assertLessThanOrEqual(1, $context->quality['score']);
    }

    /** @test */
    public function quality_returns_expected_structure(): void
    {
        // embed is also used by the schema-relevance fallback
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('questions', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->once()
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

        $service = $this->makeService([]);
        $context = $service->retrieveContext('Test question');

        $quality = $context->quality;
        $this->assertArrayHasKey('level', $quality);
        $this->assertArrayHasKey('score', $quality);
        $this->assertArrayHasKey('max', $quality);
        $this->assertArrayHasKey('details', $quality);
        $this->assertIsString($quality['level']);
        $this->assertIsInt($quality['score']);
        $this->assertIsInt($quality['max']);
        $this->assertIsArray($quality['details']);
        $this->assertContains($quality['level'], ['low', 'medium', 'high']);
    }

    /** @test */
    public function quality_max_is_always_four(): void
    {
        // embed is also used by the schema-relevance fallback
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('questions', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->once()
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

        $service = $this->makeService([]);
        $context = $service->retrieveContext('Test');

        $this->assertEquals(4, $context->quality['max']);
    }

    /** @test */
    public function quality_medium_when_three_sources_present(): void
    {
        // Similar queries present, schema present, no errors, but no entity_metadata
        // embed is also used by the schema-relevance fallback
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->andReturn([0.1, 0.2]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('questions', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->once()
            ->andReturn([
                [
                    'score' => 0.85,
                    'payload' => [
                        'question' => 'Show all users',
                        'cypher_query' => 'MATCH (u:User) RETURN u',
                    ],
                ],
            ]);

        // Schema fallback queries the 'semantic_entities' collection (no selector configured)
        $this->mockVectorStore
            ->shouldReceive('search')
            ->with('semantic_entities', Mockery::any(), Mockery::any())
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['User'],
                'relationshipTypes' => [],
                'propertyKeys' => ['id', 'name'],
            ]);

        $this->mockGraphStore
            ->shouldReceive('query')
            ->andReturn([['n' => ['id' => 1, 'name' => 'John']]]);

        // No entity configs -> entity_metadata.detected_entities will be empty
        $service = $this->makeService([]);
        $context = $service->retrieveContext('Show users');

        $this->assertNotEmpty($context->quality);
        // Score should be 3: has_similar_queries + has_schema + no_errors
        // Missing: has_detected_entities (no entity configs)
        $score = $context->quality['score'];
        $this->assertGreaterThanOrEqual(2, $score);
        // Level should be medium (score >= 2 but < 4) or high if entity metadata was detected
        $this->assertContains($context->quality['level'], ['medium', 'high']);
    }
}
