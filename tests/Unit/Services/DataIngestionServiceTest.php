<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig;
use Mockery;
use InvalidArgumentException;
use RuntimeException;
use Exception;

/**
 * Unit Tests for DataIngestionService
 *
 * These tests verify the DataIngestionService's ability to:
 * - Ingest entities into graph and vector stores
 * - Handle partial failures gracefully
 * - Batch process efficiently using embedBatch
 * - Remove entities from both stores
 * - Sync entities (create or update)
 *
 * All dependencies are mocked - NO real database calls are made.
 */
class DataIngestionServiceTest extends TestCase
{
    private $mockVectorStore;
    private $mockGraphStore;
    private $mockEmbeddingProvider;
    private $service;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockGraphStore = Mockery::mock(GraphStoreInterface::class);
        $this->mockEmbeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        $this->service = new DataIngestionService(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Constructor & Setup Tests
    // =========================================================================

    public function test_constructor_accepts_valid_dependencies()
    {
        $service = new DataIngestionService(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider
        );

        $this->assertInstanceOf(DataIngestionService::class, $service);
    }

    public function test_service_implements_data_ingestion_service_interface()
    {
        $this->assertInstanceOf(DataIngestionServiceInterface::class, $this->service);
    }

    // =========================================================================
    // ingest() Method Tests
    // =========================================================================

    public function test_ingest_successfully_stores_in_both_stores()
    {
        // Arrange
        $entity = $this->createMockEntity([
            'id' => 123,
            'name' => 'Test Entity',
            'description' => 'Test Description'
        ]);

        // Set expectations on mocks
        $this->mockGraphStore
            ->shouldReceive('createNode')
            ->once()
            ->with('TestEntity', Mockery::type('array'));

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->with('Test Entity Test Description')
            ->andReturn([0.1, 0.2, 0.3]);

        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->once()
            ->with('test_collection', Mockery::type('array'));

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertTrue($result['graph_stored']);
        $this->assertTrue($result['vector_stored']);
        $this->assertEquals(0, $result['relationships_created']);
        $this->assertEmpty($result['errors']);
    }

    public function test_ingest_continues_when_graph_store_fails()
    {
        // Arrange
        $entity = $this->createMockEntity([
            'id' => 1,
            'name' => 'Test',
            'description' => 'Desc'
        ]);

        // Graph store throws exception
        $this->mockGraphStore
            ->shouldReceive('createNode')
            ->once()
            ->andThrow(new Exception('Graph connection failed'));

        // Vector store succeeds
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1, 0.2]);

        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->once();

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertFalse($result['graph_stored']);
        $this->assertTrue($result['vector_stored']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Graph', $result['errors'][0]);
        $this->assertStringContainsString('Graph connection failed', $result['errors'][0]);
    }

    public function test_ingest_continues_when_vector_store_fails()
    {
        // Arrange
        $entity = $this->createMockEntity([
            'id' => 1,
            'name' => 'Test',
            'description' => 'Desc'
        ]);

        // Graph store succeeds
        $this->mockGraphStore
            ->shouldReceive('createNode')
            ->once();

        // Vector store throws exception
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andThrow(new Exception('Embedding API failed'));

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertTrue($result['graph_stored']);
        $this->assertFalse($result['vector_stored']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Vector', $result['errors'][0]);
        $this->assertStringContainsString('Embedding API failed', $result['errors'][0]);
    }

    public function test_ingest_handles_both_stores_failing()
    {
        // Arrange
        $entity = $this->createMockEntity(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        // Both stores throw exceptions
        $this->mockGraphStore
            ->shouldReceive('createNode')
            ->once()
            ->andThrow(new Exception('Graph failed'));

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andThrow(new Exception('Vector failed'));

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertFalse($result['graph_stored']);
        $this->assertFalse($result['vector_stored']);
        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('Graph', $result['errors'][0]);
        $this->assertStringContainsString('Vector', $result['errors'][1]);
    }

    public function test_ingest_creates_relationships_from_graph_config()
    {
        // Arrange
        $entity = $this->createMockEntityWithRelationships([
            'id' => 1,
            'name' => 'Test Entity',
            'description' => 'Desc',
            'team_id' => 42
        ]);

        // Graph store expectations
        $this->mockGraphStore
            ->shouldReceive('createNode')
            ->once();

        $this->mockGraphStore
            ->shouldReceive('createRelationship')
            ->once()
            ->with(
                'TestEntity',
                1,
                'Team',
                42,
                'MEMBER_OF',
                Mockery::type('array')
            );

        // Vector store expectations
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1, 0.2]);

        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->once();

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertTrue($result['graph_stored']);
        $this->assertTrue($result['vector_stored']);
        $this->assertEquals(1, $result['relationships_created']);
    }

    public function test_ingest_throws_exception_for_non_nodeable_entity()
    {
        // Arrange
        $invalidEntity = new \stdClass();

        // Assert - PHP 8.2 throws TypeError for type hint violations
        $this->expectException(\TypeError::class);

        // Act
        $this->service->ingest($invalidEntity);
    }

    public function test_ingest_returns_correct_status_structure()
    {
        // Arrange
        $entity = $this->createMockEntity(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        $this->mockGraphStore->shouldReceive('createNode')->once();
        $this->mockEmbeddingProvider->shouldReceive('embed')->once()->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('upsert')->once();

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKeys(['graph_stored', 'vector_stored', 'relationships_created', 'errors'], $result);
        $this->assertIsBool($result['graph_stored']);
        $this->assertIsBool($result['vector_stored']);
        $this->assertIsInt($result['relationships_created']);
        $this->assertIsArray($result['errors']);
    }

    public function test_ingest_calls_graph_store_create_node_with_correct_parameters()
    {
        // Arrange
        $entity = $this->createMockEntity([
            'id' => 999,
            'name' => 'Entity Name',
            'email' => 'test@example.com',
            'description' => 'Description'
        ]);

        // Expect createNode to be called with label and properties including ID
        $this->mockGraphStore
            ->shouldReceive('createNode')
            ->once()
            ->with('TestEntity', Mockery::on(function ($properties) {
                return $properties['id'] === 999 &&
                       $properties['name'] === 'Entity Name' &&
                       $properties['email'] === 'test@example.com';
            }))
            ->andReturn(999);

        $this->mockEmbeddingProvider->shouldReceive('embed')->once()->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('upsert')->once();

        // Act
        $this->service->ingest($entity);

        // Assert - Mockery will verify the expectations
        $this->assertTrue(true);
    }

    public function test_ingest_calls_embedding_provider_embed_with_correct_text()
    {
        // Arrange
        $entity = $this->createMockEntity([
            'id' => 1,
            'name' => 'John Doe',
            'description' => 'Software Engineer'
        ]);

        $this->mockGraphStore->shouldReceive('createNode')->once();

        // Expect embed to be called with concatenated fields
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->with('John Doe Software Engineer')
            ->andReturn([0.1, 0.2]);

        $this->mockVectorStore->shouldReceive('upsert')->once();

        // Act
        $this->service->ingest($entity);

        // Assert - Mockery will verify the expectations
        $this->assertTrue(true);
    }

    public function test_ingest_calls_vector_store_upsert_with_correct_data()
    {
        // Arrange
        $entity = $this->createMockEntity([
            'id' => 555,
            'name' => 'Test',
            'description' => 'Desc',
            'category' => 'TypeA'
        ]);

        $this->mockGraphStore->shouldReceive('createNode')->once();

        $vector = [0.1, 0.2, 0.3];
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn($vector);

        // Expect upsert to be called with collection and points array
        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->once()
            ->with('test_collection', Mockery::on(function ($points) use ($vector) {
                return is_array($points) &&
                       count($points) === 1 &&
                       $points[0]['id'] === 555 &&
                       $points[0]['vector'] === $vector &&
                       $points[0]['payload']['id'] === 555 &&
                       $points[0]['payload']['category'] === 'TypeA';
            }));

        // Act
        $this->service->ingest($entity);

        // Assert - Mockery will verify the expectations
        $this->assertTrue(true);
    }

    // =========================================================================
    // ingestBatch() Method Tests
    // =========================================================================

    public function test_ingest_batch_successfully_ingests_multiple_entities()
    {
        // Arrange
        $entities = [
            $this->createMockEntity(['id' => 1, 'name' => 'Entity 1', 'description' => 'Desc 1']),
            $this->createMockEntity(['id' => 2, 'name' => 'Entity 2', 'description' => 'Desc 2']),
            $this->createMockEntity(['id' => 3, 'name' => 'Entity 3', 'description' => 'Desc 3']),
        ];

        // Graph store expectations (called 3 times)
        $this->mockGraphStore
            ->shouldReceive('createNode')
            ->times(3);

        // Embedding provider should use embedBatch (once, not 3 times)
        $this->mockEmbeddingProvider
            ->shouldReceive('embedBatch')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturn([
                [0.1, 0.2],
                [0.3, 0.4],
                [0.5, 0.6]
            ]);

        // Vector store upsert (once per collection)
        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->once()
            ->with('test_collection', Mockery::type('array'));

        // Act
        $result = $this->service->ingestBatch($entities);

        // Assert
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(3, $result['succeeded']);
        $this->assertEquals(0, $result['partially_succeeded']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEmpty($result['errors']);
    }

    public function test_ingest_batch_uses_embed_batch_for_efficiency()
    {
        // Arrange
        $entities = [
            $this->createMockEntity(['id' => 1, 'name' => 'A', 'description' => 'B']),
            $this->createMockEntity(['id' => 2, 'name' => 'C', 'description' => 'D']),
        ];

        $this->mockGraphStore->shouldReceive('createNode')->times(2);

        // Critical: embedBatch should be called ONCE, not embed() twice
        $this->mockEmbeddingProvider
            ->shouldReceive('embedBatch')
            ->once()
            ->with(['A B', 'C D'])
            ->andReturn([[0.1], [0.2]]);

        // embed() should NOT be called at all
        $this->mockEmbeddingProvider
            ->shouldNotReceive('embed');

        $this->mockVectorStore->shouldReceive('upsert')->once();

        // Act
        $this->service->ingestBatch($entities);

        // Assert - Mockery will verify the expectations
        $this->assertTrue(true);
    }

    public function test_ingest_batch_handles_partial_failures()
    {
        // Arrange
        $entities = [
            $this->createMockEntity(['id' => 1, 'name' => 'Entity 1', 'description' => 'Desc 1']),
            $this->createMockEntity(['id' => 2, 'name' => 'Entity 2', 'description' => 'Desc 2']),
        ];

        // First entity succeeds in graph, second fails
        $this->mockGraphStore
            ->shouldReceive('createNode')
            ->twice()
            ->andReturnUsing(function ($label, $props) {
                if ($props['id'] === 2) {
                    throw new Exception('Graph error for entity 2');
                }
                return $props['id']; // Return the ID for successful calls
            });

        // Vector store succeeds for all
        $this->mockEmbeddingProvider
            ->shouldReceive('embedBatch')
            ->once()
            ->andReturn([[0.1], [0.2]]);

        $this->mockVectorStore->shouldReceive('upsert')->once();

        // Act
        $result = $this->service->ingestBatch($entities);

        // Assert
        $this->assertEquals(2, $result['total']);
        $this->assertEquals(1, $result['succeeded']); // Entity 1 fully succeeded
        $this->assertEquals(1, $result['partially_succeeded']); // Entity 2 partial (vector only)
        $this->assertEquals(0, $result['failed']);
    }

    public function test_ingest_batch_returns_correct_summary_structure()
    {
        // Arrange
        $entities = [
            $this->createMockEntity(['id' => 1, 'name' => 'Test', 'description' => 'Desc'])
        ];

        $this->mockGraphStore->shouldReceive('createNode')->once();
        $this->mockEmbeddingProvider->shouldReceive('embedBatch')->once()->andReturn([[0.1]]);
        $this->mockVectorStore->shouldReceive('upsert')->once();

        // Act
        $result = $this->service->ingestBatch($entities);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKeys(['total', 'succeeded', 'partially_succeeded', 'failed', 'errors'], $result);
        $this->assertIsInt($result['total']);
        $this->assertIsInt($result['succeeded']);
        $this->assertIsInt($result['partially_succeeded']);
        $this->assertIsInt($result['failed']);
        $this->assertIsArray($result['errors']);
    }

    public function test_ingest_batch_handles_empty_array()
    {
        // Act
        $result = $this->service->ingestBatch([]);

        // Assert
        $this->assertEquals(0, $result['total']);
        $this->assertEquals(0, $result['succeeded']);
        $this->assertEquals(0, $result['partially_succeeded']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEmpty($result['errors']);
    }

    public function test_ingest_batch_validates_all_entities_are_nodeable()
    {
        // Arrange
        $entities = [
            $this->createMockEntity(['id' => 1, 'name' => 'Valid', 'description' => 'Desc']),
            new \stdClass(), // Invalid
            $this->createMockEntity(['id' => 3, 'name' => 'Valid', 'description' => 'Desc']),
        ];

        $this->mockGraphStore->shouldReceive('createNode')->times(2);
        $this->mockEmbeddingProvider->shouldReceive('embedBatch')->once()->andReturn([[0.1], [0.2]]);
        $this->mockVectorStore->shouldReceive('upsert')->once();

        // Act
        $result = $this->service->ingestBatch($entities);

        // Assert
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(2, $result['succeeded']); // Only valid entities
        $this->assertEquals(1, $result['failed']); // Invalid entity
    }

    // =========================================================================
    // remove() Method Tests
    // =========================================================================

    public function test_remove_successfully_removes_from_both_stores()
    {
        // Arrange
        $entity = $this->createMockEntity(['id' => 123, 'name' => 'Test', 'description' => 'Desc']);

        $this->mockGraphStore
            ->shouldReceive('deleteNode')
            ->once()
            ->with('TestEntity', 123)
            ->andReturn(true);

        $this->mockVectorStore
            ->shouldReceive('deletePoints')
            ->once()
            ->with('test_collection', [123])
            ->andReturn(true);

        // Act
        $result = $this->service->remove($entity);

        // Assert
        $this->assertTrue($result);
    }

    public function test_remove_returns_true_if_at_least_one_store_succeeds()
    {
        // Arrange
        $entity = $this->createMockEntity(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        // Graph store fails
        $this->mockGraphStore
            ->shouldReceive('deleteNode')
            ->once()
            ->andThrow(new Exception('Graph delete failed'));

        // Vector store succeeds
        $this->mockVectorStore
            ->shouldReceive('deletePoints')
            ->once()
            ->andReturn(true);

        // Act
        $result = $this->service->remove($entity);

        // Assert
        $this->assertTrue($result);
    }

    public function test_remove_handles_store_failures_gracefully()
    {
        // Arrange
        $entity = $this->createMockEntity(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        // Both stores fail
        $this->mockGraphStore
            ->shouldReceive('deleteNode')
            ->once()
            ->andThrow(new Exception('Graph failed'));

        $this->mockVectorStore
            ->shouldReceive('deletePoints')
            ->once()
            ->andThrow(new Exception('Vector failed'));

        // Act
        $result = $this->service->remove($entity);

        // Assert - returns false when both fail
        $this->assertFalse($result);
    }

    public function test_remove_calls_delete_node_and_delete_points_with_correct_ids()
    {
        // Arrange
        $entity = $this->createMockEntity(['id' => 789, 'name' => 'Test', 'description' => 'Desc']);

        $this->mockGraphStore
            ->shouldReceive('deleteNode')
            ->once()
            ->with('TestEntity', 789)
            ->andReturn(true);

        $this->mockVectorStore
            ->shouldReceive('deletePoints')
            ->once()
            ->with('test_collection', [789])
            ->andReturn(true);

        // Act
        $result = $this->service->remove($entity);

        // Assert - Mockery will verify the expectations were met
        $this->assertTrue($result);
    }

    // =========================================================================
    // sync() Method Tests
    // =========================================================================

    public function test_sync_creates_entity_when_it_does_not_exist()
    {
        // Arrange
        $entity = $this->createMockEntity(['id' => 1, 'name' => 'New Entity', 'description' => 'Desc']);

        // Entity does not exist
        $this->mockGraphStore
            ->shouldReceive('nodeExists')
            ->once()
            ->with('TestEntity', 1)
            ->andReturn(false);

        // Should call createNode (not updateNode)
        $this->mockGraphStore
            ->shouldReceive('createNode')
            ->once()
            ->with('TestEntity', Mockery::type('array'));

        // Vector store
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->once();

        // Act
        $result = $this->service->sync($entity);

        // Assert
        $this->assertEquals('created', $result['action']);
        $this->assertTrue($result['graph_synced']);
        $this->assertTrue($result['vector_synced']);
    }

    public function test_sync_updates_entity_when_it_exists()
    {
        // Arrange
        $entity = $this->createMockEntity(['id' => 1, 'name' => 'Existing Entity', 'description' => 'Desc']);

        // Entity exists
        $this->mockGraphStore
            ->shouldReceive('nodeExists')
            ->once()
            ->with('TestEntity', 1)
            ->andReturn(true);

        // Should call updateNode (not createNode)
        $this->mockGraphStore
            ->shouldReceive('updateNode')
            ->once()
            ->with('TestEntity', 1, Mockery::type('array'));

        // Vector store
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->once();

        // Act
        $result = $this->service->sync($entity);

        // Assert
        $this->assertEquals('updated', $result['action']);
        $this->assertTrue($result['graph_synced']);
        $this->assertTrue($result['vector_synced']);
    }

    public function test_sync_returns_correct_action_type_created()
    {
        // Arrange
        $entity = $this->createMockEntity(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        $this->mockGraphStore->shouldReceive('nodeExists')->once()->andReturn(false);
        $this->mockGraphStore->shouldReceive('createNode')->once();
        $this->mockEmbeddingProvider->shouldReceive('embed')->once()->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('upsert')->once();

        // Act
        $result = $this->service->sync($entity);

        // Assert
        $this->assertEquals('created', $result['action']);
    }

    public function test_sync_handles_mixed_success_scenarios()
    {
        // Arrange
        $entity = $this->createMockEntity(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        $this->mockGraphStore->shouldReceive('nodeExists')->once()->andReturn(false);

        // Graph succeeds
        $this->mockGraphStore->shouldReceive('createNode')->once();

        // Vector fails
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andThrow(new Exception('Vector failed'));

        // Act
        $result = $this->service->sync($entity);

        // Assert
        $this->assertEquals('created', $result['action']);
        $this->assertTrue($result['graph_synced']);
        $this->assertFalse($result['vector_synced']);
        $this->assertNotEmpty($result['errors']);
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function test_ingest_handles_entity_with_empty_embed_fields()
    {
        // Arrange
        $entity = $this->createMockEntity([
            'id' => 1,
            'name' => '',  // Empty
            'description' => ''  // Empty
        ]);

        // Graph store succeeds
        $this->mockGraphStore->shouldReceive('createNode')->once();

        // Act
        $result = $this->service->ingest($entity);

        // Assert - Graph succeeds but vector fails due to empty embed text
        $this->assertTrue($result['graph_stored']);
        $this->assertFalse($result['vector_stored']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Cannot generate embedding', $result['errors'][0]);
    }

    public function test_ingest_skips_relationships_when_foreign_key_is_null()
    {
        // Arrange
        $entity = $this->createMockEntityWithRelationships([
            'id' => 1,
            'name' => 'Test',
            'description' => 'Desc',
            'team_id' => null  // Null foreign key
        ]);

        $this->mockGraphStore->shouldReceive('createNode')->once();

        // createRelationship should NOT be called when foreign key is null
        $this->mockGraphStore->shouldNotReceive('createRelationship');

        $this->mockEmbeddingProvider->shouldReceive('embed')->once()->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('upsert')->once();

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertEquals(0, $result['relationships_created']);
    }

    public function test_ingest_batch_groups_entities_by_collection()
    {
        // Arrange - Create entities with different collections
        $entity1 = $this->createMockEntity(['id' => 1, 'name' => 'A', 'description' => 'B'], 'collection_1');
        $entity2 = $this->createMockEntity(['id' => 2, 'name' => 'C', 'description' => 'D'], 'collection_2');
        $entity3 = $this->createMockEntity(['id' => 3, 'name' => 'E', 'description' => 'F'], 'collection_1');

        $this->mockGraphStore->shouldReceive('createNode')->times(3);

        // embedBatch should be called once per collection group
        $this->mockEmbeddingProvider
            ->shouldReceive('embedBatch')
            ->twice()
            ->andReturn([[0.1], [0.2]]);

        // upsert should be called once per collection
        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->with('collection_1', Mockery::type('array'))
            ->once();

        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->with('collection_2', Mockery::type('array'))
            ->once();

        // Act
        $result = $this->service->ingestBatch([$entity1, $entity2, $entity3]);

        // Assert - Verify all entities succeeded
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(3, $result['succeeded']);
    }

    public function test_remove_validates_entity_is_nodeable()
    {
        // Arrange
        $invalidEntity = new \stdClass();

        // Assert - PHP 8.2 throws TypeError for type hint violations
        $this->expectException(\TypeError::class);

        // Act
        $this->service->remove($invalidEntity);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a mock Nodeable entity
     */
    private function createMockEntity(array $data = [], string $collection = 'test_collection'): Nodeable
    {
        $entity = Mockery::mock(Nodeable::class);

        $entity->shouldReceive('getId')->andReturn($data['id'] ?? 1);
        $entity->shouldReceive('toArray')->andReturn($data);

        $entity->shouldReceive('getGraphConfig')->andReturn(
            new GraphConfig(
                label: 'TestEntity',
                properties: ['id', 'name', 'email'],
                relationships: []
            )
        );

        $entity->shouldReceive('getVectorConfig')->andReturn(
            new VectorConfig(
                collection: $collection,
                embedFields: ['name', 'description'],
                metadata: ['id', 'category']
            )
        );

        return $entity;
    }

    /**
     * Create a mock Nodeable entity with relationships
     */
    private function createMockEntityWithRelationships(array $data = []): Nodeable
    {
        $entity = Mockery::mock(Nodeable::class);

        $entity->shouldReceive('getId')->andReturn($data['id'] ?? 1);
        $entity->shouldReceive('toArray')->andReturn($data);

        $entity->shouldReceive('getGraphConfig')->andReturn(
            new GraphConfig(
                label: 'TestEntity',
                properties: ['id', 'name'],
                relationships: [
                    new RelationshipConfig(
                        type: 'MEMBER_OF',
                        targetLabel: 'Team',
                        foreignKey: 'team_id',
                        properties: []
                    )
                ]
            )
        );

        $entity->shouldReceive('getVectorConfig')->andReturn(
            new VectorConfig(
                collection: 'test_collection',
                embedFields: ['name', 'description'],
                metadata: ['id']
            )
        );

        return $entity;
    }
}
