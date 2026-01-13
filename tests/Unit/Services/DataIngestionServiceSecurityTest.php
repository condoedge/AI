<?php

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Security-related tests for DataIngestionService
 *
 * Note: Tests for _team_ids and BELONGS_TO_TEAM relationships have been removed
 * as part of the security architecture cleanup. Security is now enforced at the
 * Eloquent fetch level via HasSecurity, not at ingestion time.
 *
 * @see docs/plans/2026-01-13-cleanup-access-level-resolver-and-team-ids.md
 */
class DataIngestionServiceSecurityTest extends TestCase
{
    private $vectorStore;
    private $graphStore;
    private $embeddingProvider;
    private DataIngestionService $service;

    public function setUp(): void
    {
        parent::setUp();

        $this->vectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->graphStore = Mockery::mock(GraphStoreInterface::class);
        $this->embeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        $this->service = new DataIngestionService(
            $this->vectorStore,
            $this->graphStore,
            $this->embeddingProvider
        );
    }

    /** @test */
    public function it_stores_entity_metadata_without_team_ids(): void
    {
        $entity = $this->createMockNodeable([
            'id' => 1,
            'name' => 'Test Person',
            'team_id' => 5, // This should be ignored now
        ]);

        $this->graphStore->shouldReceive('createNode')->once();
        $this->graphStore->shouldReceive('nodeExists')->andReturn(false);

        $this->embeddingProvider->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->vectorStore->shouldReceive('collectionExists')->andReturn(true);

        $capturedPayload = null;
        $this->vectorStore->shouldReceive('upsert')
            ->once()
            ->andReturnUsing(function ($collection, $points) use (&$capturedPayload) {
                $capturedPayload = $points[0]['payload'] ?? [];
                return true;
            });

        $this->service->ingest($entity);

        // Verify _team_ids is NOT stored (security moved to Eloquent level)
        $this->assertArrayNotHasKey('_team_ids', $capturedPayload);
        $this->assertArrayHasKey('_entity_type', $capturedPayload);
        // Note: In production, _entity_type would be the actual model name
        // In tests with mocks, it's the mock class name (which is fine for unit testing)
    }

    /** @test */
    public function it_stores_entity_type_and_class_metadata(): void
    {
        $entity = $this->createMockNodeable([
            'id' => 1,
            'name' => 'Test Person',
        ]);

        $this->graphStore->shouldReceive('createNode')->once();
        $this->graphStore->shouldReceive('nodeExists')->andReturn(false);

        $this->embeddingProvider->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->vectorStore->shouldReceive('collectionExists')->andReturn(true);

        $capturedPayload = null;
        $this->vectorStore->shouldReceive('upsert')
            ->once()
            ->andReturnUsing(function ($collection, $points) use (&$capturedPayload) {
                $capturedPayload = $points[0]['payload'] ?? [];
                return true;
            });

        $this->service->ingest($entity);

        $this->assertArrayHasKey('_entity_type', $capturedPayload);
        $this->assertArrayHasKey('_entity_class', $capturedPayload);
        $this->assertArrayHasKey('id', $capturedPayload);
    }

    /**
     * Create a mock Nodeable entity
     */
    private function createMockNodeable(array $attributes): Nodeable
    {
        $entity = Mockery::mock(Nodeable::class);
        $entity->shouldReceive('getId')->andReturn($attributes['id']);
        $entity->shouldReceive('toArray')->andReturn($attributes);
        $entity->shouldReceive('getAttribute')->andReturn(null);

        $graphConfig = new GraphConfig('TestEntity', ['name'], []);
        $entity->shouldReceive('getGraphConfig')->andReturn($graphConfig);

        $vectorConfig = new VectorConfig('test_collection', ['name'], ['id']);
        $entity->shouldReceive('getVectorConfig')->andReturn($vectorConfig);

        return $entity;
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
