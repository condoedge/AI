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
use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig;
use Mockery;
use InvalidArgumentException;
use RuntimeException;
use Exception;
use Condoedge\Ai\Exceptions\DataConsistencyException;
use CondoedgeAiExceptionsDataConsistencyException;

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

        $this->mockGraphStore = Mockery::mock(GraphStoreInterface::class);

        // Single-entity ingest/sync lazily ensures the target collection exists
        // before embedding (DataIngestionService::ingestToVector ->
        // VectorPayloadBuilder::ensureCollectionExists -> collectionExists()).
        // Report the collection as already present so the real vector path is
        // exercised instead of failing on an un-mocked call. byDefault() lets a
        // specific test override this if it ever needs to.

        // ingestBatch() first filters out entities already present in the graph
        // via graphStore->query(MATCH ... WHERE n.id IN $ids). Default to "none
        // already ingested" so every entity flows through batchIngestToGraph,
        // matching each batch test's createNode/embedBatch expectations.
        $this->mockGraphStore
            ->shouldReceive('query')
            ->andReturn([])
            ->byDefault();

        $this->service = new DataIngestionService(
            $this->mockGraphStore
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
            $this->mockGraphStore
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

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertTrue($result['graph_stored']);
        $this->assertEquals(0, $result['relationships_created']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Review I-2: auto-sync (observers/jobs) never ran the command flush. A write that
     * creates a label the cached schema lacks flushes it; a known label does not.
     *
     * @dataProvider cachedLabels
     */
    public function test_ingest_flushes_the_schema_cache_only_for_a_new_label(array $cachedLabels, bool $expectFlushed)
    {
        config(['ai.graph.neo4j.database' => 'neo4j']);
        \Illuminate\Support\Facades\Cache::forever('ai:schema:v1:neo4j', [
            'labels' => $cachedLabels, 'relationshipTypes' => [], 'relationshipPatterns' => [],
        ]);
        $entity = $this->createMockEntity(['id' => 1, 'name' => 'A', 'description' => 'B']);
        $this->mockGraphStore->shouldReceive('createNode')->once();

        $this->service->ingest($entity);

        $this->assertSame(!$expectFlushed, \Illuminate\Support\Facades\Cache::has('ai:schema:v1:neo4j'));
    }

    public static function cachedLabels(): array
    {
        return [
            'label already known' => [['TestEntity'], false],
            'label new to the graph' => [['Other'], true],
        ];
    }

    public function test_ingest_syncs_decimal_cast_attributes_as_floats()
    {
        // Arrange - an Eloquent-like entity whose decimal:2 cast yields a string amount.
        // The graph node must receive a float (Cypher SUM/comparisons), while the
        // numeric-string account code (no decimal cast) must stay a string.
        $entity = $this->createDecimalCastEntity();

        $capturedProperties = null;
        $this->mockGraphStore
            ->shouldReceive('createNode')
            ->once()
            ->with('Invoice', Mockery::on(function ($properties) use (&$capturedProperties) {
                $capturedProperties = $properties;
                return true;
            }));

        // Act
        $this->service->ingest($entity);

        // Assert
        $this->assertSame(1234.5, $capturedProperties['calc_due_amount']);
        $this->assertSame('2410', $capturedProperties['account_code']);
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

        // Expect DataConsistencyException to be thrown
        $this->expectException(DataConsistencyException::class);
        $this->expectExceptionMessageMatches('/Graph store failed for entity 1/');

        // Act
        $this->service->ingest($entity);
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

        // Expect DataConsistencyException to be thrown (graph fails first)
        $this->expectException(DataConsistencyException::class);
        $this->expectExceptionMessage('Graph store failed for entity 1');

        // Act
        $this->service->ingest($entity);
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

        // RelationshipSyncer guards each relationship: the target node must
        // exist (nodeExists) and the relationship must not already exist
        // (relationshipExists) before createRelationship is called. Report the
        // target as present and no duplicate so the real path reaches creation.
        $this->mockGraphStore
            ->shouldReceive('nodeExists')
            ->with('Team', 42)
            ->andReturn(true);

        $this->mockGraphStore
            ->shouldReceive('relationshipExists')
            ->andReturn(false);

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

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertTrue($result['graph_stored']);
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

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertIsArray($result);
        $this->assertIsBool($result['graph_stored']);
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

        // Vector store upsert (once per collection)

        // Act
        $result = $this->service->ingestBatch($entities);

        // Assert
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(3, $result['succeeded']);
        $this->assertEquals(0, $result['partially_succeeded']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEmpty($result['errors']);
    }

    public function test_ingest_batch_returns_correct_summary_structure()
    {
        // Arrange
        $entities = [
            $this->createMockEntity(['id' => 1, 'name' => 'Test', 'description' => 'Desc'])
        ];

        $this->mockGraphStore->shouldReceive('createNode')->once();

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

        // Expect DataConsistencyException to be thrown
        $this->expectException(DataConsistencyException::class);
        $this->expectExceptionMessage('Graph deletion failed for entity 1');

        // Act
        $this->service->remove($entity);
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

        // Expect DataConsistencyException to be thrown (graph fails first)
        $this->expectException(DataConsistencyException::class);
        $this->expectExceptionMessage('Graph deletion failed for entity 1');

        // Act
        $this->service->remove($entity);
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

        // Act
        $result = $this->service->sync($entity);

        // Assert
        $this->assertEquals('created', $result['action']);
        $this->assertTrue($result['graph_synced']);
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

        // Act
        $result = $this->service->sync($entity);

        // Assert
        $this->assertEquals('updated', $result['action']);
        $this->assertTrue($result['graph_synced']);
    }

    public function test_sync_update_path_syncs_decimal_cast_attributes_as_floats()
    {
        // Arrange - the update path must coerce decimal-cast strings exactly like create.
        $entity = $this->createDecimalCastEntity();

        $this->mockGraphStore
            ->shouldReceive('nodeExists')
            ->once()
            ->with('Invoice', 7)
            ->andReturn(true);

        $capturedProperties = null;
        $this->mockGraphStore
            ->shouldReceive('updateNode')
            ->once()
            ->with('Invoice', 7, Mockery::on(function ($properties) use (&$capturedProperties) {
                $capturedProperties = $properties;
                return true;
            }));

        // Act
        $this->service->sync($entity);

        // Assert
        $this->assertSame(1234.5, $capturedProperties['calc_due_amount']);
        $this->assertSame('2410', $capturedProperties['account_code']);
    }

    public function test_sync_returns_correct_action_type_created()
    {
        // Arrange
        $entity = $this->createMockEntity(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        $this->mockGraphStore->shouldReceive('nodeExists')->once()->andReturn(false);
        $this->mockGraphStore->shouldReceive('createNode')->once();

        // Act
        $result = $this->service->sync($entity);

        // Assert
        $this->assertEquals('created', $result['action']);
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

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

        // Act
        $result = $this->service->ingest($entity);

        // Assert
        $this->assertEquals(0, $result['relationships_created']);
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

        return $entity;
    }

    /**
     * Create an Eloquent-like Nodeable whose getCasts() declares a decimal:2 amount.
     * toArray() yields the amount as a string, the way PDO/decimal casts do.
     */
    private function createDecimalCastEntity(): Nodeable
    {
        return new class implements Nodeable {
            public function getId(): string|int
            {
                return 7;
            }

            public function toArray(): array
            {
                return [
                    'id' => 7,
                    'name' => 'Invoice 7',
                    'description' => 'Test invoice',
                    'calc_due_amount' => '1234.50',
                    'account_code' => '2410',
                ];
            }

            public function getCasts(): array
            {
                return ['calc_due_amount' => 'decimal:2'];
            }

            public function getGraphConfig(): GraphConfig
            {
                return new GraphConfig(
                    label: 'Invoice',
                    properties: ['id', 'calc_due_amount', 'account_code'],
                    relationships: []
                );
            }
        };
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

        return $entity;
    }
}
