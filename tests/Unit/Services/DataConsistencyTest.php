<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Exceptions\DataConsistencyException;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Mockery;

/**
 * DataConsistencyTest
 *
 * Tests the compensating transaction logic in DataIngestionService
 * to ensure data consistency across Neo4j and Qdrant stores.
 */
class DataConsistencyTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_rolls_back_graph_insert_when_vector_store_fails()
    {
        // Arrange
        $entity = $this->createTestEntity();

        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $graphStore = Mockery::mock(GraphStoreInterface::class);
        $embeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        // Graph insert succeeds
        $graphStore->shouldReceive('createNode')
            ->once()
            ->andReturn('node-123');

        // Vector insert fails
        $embeddingProvider->shouldReceive('embed')
            ->once()
            ->andThrow(new \RuntimeException('Qdrant connection timeout'));

        // ROLLBACK: Expect graph deletion
        $graphStore->shouldReceive('deleteNode')
            ->once()
            ->with('TestEntity', 1)
            ->andReturn(true);

        $service = new DataIngestionService($vectorStore, $graphStore, $embeddingProvider);

        // Act & Assert
        try {
            $service->ingest($entity);
            $this->fail('Expected DataConsistencyException');
        } catch (DataConsistencyException $e) {
            // Verify rollback was successful
            $this->assertTrue($e->wasRolledBack());
            $this->assertStringContainsString('rolled back', $e->getMessage());

            $context = $e->getContext();
            $this->assertTrue($context['graph_success']);
            $this->assertFalse($context['vector_success']);
            $this->assertTrue($context['rolled_back']);
        }
    }

    /** @test */
    public function it_throws_critical_exception_when_rollback_fails()
    {
        // Arrange
        $entity = $this->createTestEntity();

        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $graphStore = Mockery::mock(GraphStoreInterface::class);
        $embeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        // Graph insert succeeds
        $graphStore->shouldReceive('createNode')
            ->once()
            ->andReturn('node-123');

        // Vector insert fails
        $embeddingProvider->shouldReceive('embed')
            ->once()
            ->andThrow(new \RuntimeException('Qdrant down'));

        // ROLLBACK FAILS
        $graphStore->shouldReceive('deleteNode')
            ->once()
            ->andThrow(new \RuntimeException('Neo4j connection lost'));

        $service = new DataIngestionService($vectorStore, $graphStore, $embeddingProvider);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CRITICAL DATA INCONSISTENCY');

        $service->ingest($entity);
    }

    /** @test */
    public function it_succeeds_when_both_stores_work()
    {
        // Arrange
        $entity = $this->createTestEntity();

        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $graphStore = Mockery::mock(GraphStoreInterface::class);
        $embeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        // Both succeed
        $graphStore->shouldReceive('createNode')
            ->once()
            ->andReturn('node-123');

        $embeddingProvider->shouldReceive('embed')
            ->once()
            ->andReturn(array_fill(0, 1536, 0.1));

        $vectorStore->shouldReceive('upsert')
            ->once()
            ->andReturn(true);

        $service = new DataIngestionService($vectorStore, $graphStore, $embeddingProvider);

        // Act
        $result = $service->ingest($entity);

        // Assert
        $this->assertTrue($result['graph_stored']);
        $this->assertTrue($result['vector_stored']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function it_restores_graph_node_when_vector_deletion_fails()
    {
        // Arrange
        $entity = $this->createTestEntity();

        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $graphStore = Mockery::mock(GraphStoreInterface::class);
        $embeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        // Graph deletion succeeds
        $graphStore->shouldReceive('deleteNode')
            ->once()
            ->with('TestEntity', 1)
            ->andReturn(true);

        // Vector deletion fails
        $vectorStore->shouldReceive('deletePoints')
            ->once()
            ->andThrow(new \RuntimeException('Qdrant unavailable'));

        // RESTORE: Expect graph node recreation
        $graphStore->shouldReceive('createNode')
            ->once()
            ->with('TestEntity', Mockery::any())
            ->andReturn('node-123');

        $service = new DataIngestionService($vectorStore, $graphStore, $embeddingProvider);

        // Act & Assert
        try {
            $service->remove($entity);
            $this->fail('Expected DataConsistencyException');
        } catch (DataConsistencyException $e) {
            // Verify restoration was successful
            $this->assertTrue($e->wasRolledBack());
            $this->assertStringContainsString('restored', $e->getMessage());

            $context = $e->getContext();
            $this->assertTrue($context['graph_success']);
            $this->assertFalse($context['vector_success']);
            $this->assertTrue($context['rolled_back']);
            $this->assertEquals('remove', $context['operation']);
        }
    }

    /** @test */
    public function it_throws_consistency_exception_when_graph_fails_initially()
    {
        // Arrange
        $entity = $this->createTestEntity();

        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $graphStore = Mockery::mock(GraphStoreInterface::class);
        $embeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        // Graph fails immediately
        $graphStore->shouldReceive('createNode')
            ->once()
            ->andThrow(new \RuntimeException('Neo4j unavailable'));

        // Vector should NOT be attempted
        $embeddingProvider->shouldNotReceive('embed');
        $vectorStore->shouldNotReceive('upsert');

        $service = new DataIngestionService($vectorStore, $graphStore, $embeddingProvider);

        // Act & Assert
        try {
            $service->ingest($entity);
            $this->fail('Expected DataConsistencyException');
        } catch (DataConsistencyException $e) {
            $context = $e->getContext();
            $this->assertFalse($context['graph_success']);
            $this->assertFalse($context['vector_success']);
            $this->assertFalse($context['rolled_back']); // Nothing to rollback
        }
    }

    private function createTestEntity(): Nodeable
    {
        return new class implements Nodeable {
            public function getId(): int { return 1; }

            public function toArray(): array {
                return ['id' => 1, 'name' => 'Test Entity'];
            }

            public function getGraphConfig(): GraphConfig {
                return GraphConfig::fromArray([
                    'label' => 'TestEntity',
                    'properties' => ['id', 'name'],
                    'relationships' => [],
                ]);
            }

            public function getVectorConfig(): VectorConfig {
                return VectorConfig::fromArray([
                    'collection' => 'test_entities',
                    'embed_fields' => ['name'],
                    'metadata' => ['id'],
                ]);
            }
        };
    }
}
