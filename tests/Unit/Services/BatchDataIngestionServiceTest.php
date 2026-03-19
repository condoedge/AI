<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\BatchDataIngestionService;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig;
use Condoedge\Ai\Support\AiLogger;
use Mockery;

/**
 * Unit Tests for BatchDataIngestionService
 *
 * These tests verify the BatchDataIngestionService's ability to:
 * - Delegate single-entity operations to the inner service
 * - Batch create nodes grouped by label using UNWIND Cypher
 * - Batch sync relationships grouped by type with chunking
 * - Sanitize associative arrays for Neo4j compatibility
 * - Return correct summary counts
 *
 * All dependencies are mocked - NO real database calls are made.
 */
class BatchDataIngestionServiceTest extends TestCase
{
    private $mockInner;
    private $mockVectorStore;
    private $mockGraphStore;
    private $mockEmbeddingProvider;
    private $mockLogger;
    private BatchDataIngestionService $service;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockInner = Mockery::mock(DataIngestionServiceInterface::class);
        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockGraphStore = Mockery::mock(GraphStoreInterface::class);
        $this->mockEmbeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);
        $this->mockLogger = Mockery::mock(AiLogger::class)->shouldIgnoreMissing();

        $this->service = new BatchDataIngestionService(
            $this->mockInner,
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider,
            $this->mockLogger,
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Delegation Tests
    // =========================================================================

    public function test_ingest_delegates_to_inner_service()
    {
        // Arrange
        $entity = Mockery::mock(Nodeable::class);

        $expectedResult = [
            'graph_stored' => true,
            'vector_stored' => true,
            'relationships_created' => 0,
            'errors' => [],
        ];

        $this->mockInner
            ->shouldReceive('ingest')
            ->once()
            ->with($entity)
            ->andReturn($expectedResult);

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertEquals($expectedResult, $result);
    }

    public function test_sync_delegates_to_inner_service()
    {
        // Arrange
        $entity = Mockery::mock(Nodeable::class);

        $expectedResult = [
            'action' => 'updated',
            'graph_synced' => true,
            'vector_synced' => true,
            'errors' => [],
        ];

        $this->mockInner
            ->shouldReceive('sync')
            ->once()
            ->with($entity)
            ->andReturn($expectedResult);

        // Act
        $result = $this->service->sync($entity);

        // Assert
        $this->assertEquals($expectedResult, $result);
    }

    public function test_remove_delegates_to_inner_service()
    {
        // Arrange
        $entity = Mockery::mock(Nodeable::class);

        $this->mockInner
            ->shouldReceive('remove')
            ->once()
            ->with($entity)
            ->andReturn(true);

        // Act
        $result = $this->service->remove($entity);

        // Assert
        $this->assertTrue($result);
    }

    // =========================================================================
    // ingestBatch() Tests
    // =========================================================================

    public function test_ingestBatch_groups_entities_by_label()
    {
        // Arrange - two entities with different labels
        $entityA = $this->createMockEntity(1, 'Alpha', ['id', 'name'], 'LabelA');
        $entityB = $this->createMockEntity(2, 'Beta', ['id', 'name'], 'LabelB');

        // Expect two separate UNWIND queries (one per label)
        $this->mockGraphStore
            ->shouldReceive('query')
            ->twice()
            ->with(
                Mockery::on(fn($cypher) => str_contains($cypher, 'UNWIND')),
                Mockery::type('array')
            )
            ->andReturn([]);

        // Vector store expectations
        $this->mockEmbeddingProvider
            ->shouldReceive('embedBatch')
            ->once()
            ->andReturn([[0.1, 0.2], [0.3, 0.4]]);

        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->once();

        // Act
        $result = $this->service->ingestBatch([$entityA, $entityB]);

        // Assert
        $this->assertEquals(2, $result['total']);
        $this->assertEquals(2, $result['succeeded']);
    }

    public function test_ingestBatch_returns_correct_summary()
    {
        // Arrange - three entities: two will succeed fully, one will fail graph ingestion
        $entity1 = $this->createMockEntity(1, 'Entity1', ['id', 'name'], 'LabelA');
        $entity2 = $this->createMockEntity(2, 'Entity2', ['id', 'name'], 'LabelA');
        $entity3 = $this->createMockEntity(3, 'Entity3', ['id', 'name'], 'LabelB');

        // LabelA graph query succeeds, LabelB graph query fails
        $this->mockGraphStore
            ->shouldReceive('query')
            ->with(
                Mockery::on(fn($cypher) => str_contains($cypher, '`LabelA`')),
                Mockery::type('array')
            )
            ->once()
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('query')
            ->with(
                Mockery::on(fn($cypher) => str_contains($cypher, '`LabelB`')),
                Mockery::type('array')
            )
            ->once()
            ->andThrow(new \Exception('Graph error'));

        // Vector store succeeds for all
        $this->mockEmbeddingProvider
            ->shouldReceive('embedBatch')
            ->once()
            ->andReturn([[0.1], [0.2], [0.3]]);

        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->once();

        // Act
        $result = $this->service->ingestBatch([$entity1, $entity2, $entity3]);

        // Assert
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(2, $result['succeeded']);       // entity1 + entity2 (both stores ok)
        $this->assertEquals(1, $result['partially_succeeded']); // entity3 (vector ok, graph failed)
        $this->assertEquals(0, $result['failed']);
        $this->assertArrayHasKeys(['total', 'succeeded', 'partially_succeeded', 'failed', 'errors'], $result);
    }

    // =========================================================================
    // syncRelationships() Tests
    // =========================================================================

    public function test_syncRelationships_groups_by_relationship_type()
    {
        // Arrange - two entities with different relationship types
        $entityA = $this->createMockEntityWithRelationships(
            id: 1,
            label: 'User',
            data: ['id' => 1, 'name' => 'Alice', 'team_id' => 10],
            relationships: [
                new RelationshipConfig(
                    type: 'MEMBER_OF',
                    targetLabel: 'Team',
                    foreignKey: 'team_id',
                    properties: [],
                ),
            ]
        );

        $entityB = $this->createMockEntityWithRelationships(
            id: 2,
            label: 'Order',
            data: ['id' => 2, 'name' => 'Order1', 'customer_id' => 20],
            relationships: [
                new RelationshipConfig(
                    type: 'PLACED_BY',
                    targetLabel: 'Customer',
                    foreignKey: 'customer_id',
                    properties: [],
                ),
            ]
        );

        // Expect two UNWIND queries — one per relationship group
        $this->mockGraphStore
            ->shouldReceive('query')
            ->twice()
            ->with(
                Mockery::on(fn($cypher) => str_contains($cypher, 'UNWIND')),
                Mockery::type('array')
            )
            ->andReturn([['total' => 1]]);

        // Act
        $result = $this->service->syncRelationships([$entityA, $entityB]);

        // Assert
        $this->assertEquals(2, $result['total_entities']);
        $this->assertEquals(2, $result['total_relationships_checked']);
        $this->assertEquals(2, $result['relationships_created']);
    }

    public function test_syncRelationships_chunks_large_batches()
    {
        // Arrange - one entity type with >500 relationship pairs to trigger chunking
        $entities = [];
        for ($i = 1; $i <= 600; $i++) {
            $entities[] = $this->createMockEntityWithRelationships(
                id: $i,
                label: 'User',
                data: ['id' => $i, 'name' => "User{$i}", 'team_id' => 1],
                relationships: [
                    new RelationshipConfig(
                        type: 'MEMBER_OF',
                        targetLabel: 'Team',
                        foreignKey: 'team_id',
                        properties: [],
                    ),
                ]
            );
        }

        // 600 pairs in one group => chunked at 500 => 2 query calls
        $this->mockGraphStore
            ->shouldReceive('query')
            ->twice()
            ->with(
                Mockery::on(fn($cypher) => str_contains($cypher, 'UNWIND')),
                Mockery::type('array')
            )
            ->andReturn([['total' => 500]], [['total' => 100]]);

        // Act
        $result = $this->service->syncRelationships($entities);

        // Assert
        $this->assertEquals(600, $result['total_entities']);
        $this->assertEquals(600, $result['total_relationships_checked']);
        $this->assertEquals(600, $result['relationships_created']);
    }

    // =========================================================================
    // sanitizeForGraph() Tests
    // =========================================================================

    public function test_sanitizeForGraph_encodes_associative_arrays()
    {
        // Arrange - entity whose data contains an associative array (translation map)
        $translationMap = ['fr' => 'Bonjour', 'en' => 'Hello'];

        $entity = Mockery::mock(Nodeable::class);
        $entity->shouldReceive('getId')->andReturn(1);
        $entity->shouldReceive('toArray')->andReturn([
            'id' => 1,
            'name' => 'Test',
            'translations' => $translationMap,
        ]);
        $entity->shouldReceive('getGraphConfig')->andReturn(
            new GraphConfig(
                label: 'TestEntity',
                properties: ['id', 'name', 'translations'],
                relationships: [],
            )
        );
        $entity->shouldReceive('getVectorConfig')->andReturn(
            new VectorConfig(
                collection: 'test_collection',
                embedFields: ['name'],
                metadata: ['id'],
            )
        );

        // Capture what gets sent to graph store
        $capturedBatch = null;
        $this->mockGraphStore
            ->shouldReceive('query')
            ->once()
            ->with(
                Mockery::type('string'),
                Mockery::on(function ($params) use (&$capturedBatch) {
                    $capturedBatch = $params['batch'];
                    return true;
                })
            )
            ->andReturn([]);

        // Vector expectations
        $this->mockEmbeddingProvider
            ->shouldReceive('embedBatch')
            ->once()
            ->andReturn([[0.1, 0.2]]);

        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->once();

        // Act
        $this->service->ingestBatch([$entity]);

        // Assert - the associative array should be JSON-encoded
        $this->assertNotNull($capturedBatch);
        $this->assertCount(1, $capturedBatch);

        $storedTranslations = $capturedBatch[0]['translations'];
        $this->assertIsString($storedTranslations);
        $this->assertEquals(json_encode($translationMap, JSON_UNESCAPED_UNICODE), $storedTranslations);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a mock Nodeable entity with a given label (for graph grouping tests).
     */
    private function createMockEntity(
        int $id,
        string $name,
        array $properties = ['id', 'name'],
        string $label = 'TestEntity',
        string $collection = 'test_collection',
    ): Nodeable {
        $entity = Mockery::mock(Nodeable::class);

        $entity->shouldReceive('getId')->andReturn($id);
        $entity->shouldReceive('toArray')->andReturn([
            'id' => $id,
            'name' => $name,
        ]);

        $entity->shouldReceive('getGraphConfig')->andReturn(
            new GraphConfig(
                label: $label,
                properties: $properties,
                relationships: [],
            )
        );

        $entity->shouldReceive('getVectorConfig')->andReturn(
            new VectorConfig(
                collection: $collection,
                embedFields: ['name'],
                metadata: ['id'],
            )
        );

        return $entity;
    }

    /**
     * Create a mock Nodeable entity with relationship configurations.
     */
    private function createMockEntityWithRelationships(
        int $id,
        string $label,
        array $data,
        array $relationships,
    ): Nodeable {
        $entity = Mockery::mock(Nodeable::class);

        $entity->shouldReceive('getId')->andReturn($id);
        $entity->shouldReceive('toArray')->andReturn($data);

        $entity->shouldReceive('getGraphConfig')->andReturn(
            new GraphConfig(
                label: $label,
                properties: array_keys($data),
                relationships: $relationships,
            )
        );

        return $entity;
    }
}
