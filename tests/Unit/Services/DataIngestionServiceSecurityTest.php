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
    public function it_stores_team_ids_in_qdrant_payload(): void
    {
        $entity = $this->createMockNodeable([
            'id' => 1,
            'name' => 'Test Person',
            'team_id' => 5,
        ]);

        $this->graphStore->shouldReceive('createNode')->once();
        $this->graphStore->shouldReceive('nodeExists')->andReturn(false);

        $this->embeddingProvider->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->vectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->vectorStore->shouldReceive('upsert')
            ->withArgs(function ($collection, $points) {
                $payload = $points[0]['payload'] ?? [];
                return isset($payload['_team_ids']) && $payload['_team_ids'] === [5];
            })
            ->once();

        $this->service->ingest($entity);
    }

    /** @test */
    public function it_uses_security_related_team_ids_method_when_available(): void
    {
        $entity = $this->createMockNodeableWithSecurityTeamIds([7, 8, 9]);

        $this->graphStore->shouldReceive('createNode')->once();
        $this->graphStore->shouldReceive('nodeExists')->andReturn(false);

        $this->embeddingProvider->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->vectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->vectorStore->shouldReceive('upsert')
            ->withArgs(function ($collection, $points) {
                $payload = $points[0]['payload'] ?? [];
                return isset($payload['_team_ids']) && $payload['_team_ids'] === [7, 8, 9];
            })
            ->once();

        $this->service->ingest($entity);
    }

    /** @test */
    public function it_excludes_sensible_columns_from_vector_content(): void
    {
        $entity = $this->createMockNodeableWithSensibleColumns(
            ['name', 'bio', 'ssn'],
            ['ssn'],
            ['name' => 'John', 'bio' => 'Developer', 'ssn' => '123-45-6789']
        );

        $this->graphStore->shouldReceive('createNode')->once();
        $this->graphStore->shouldReceive('nodeExists')->andReturn(false);

        // The embedding should NOT include the SSN
        $this->embeddingProvider->shouldReceive('embed')
            ->withArgs(function ($text) {
                return !str_contains($text, '123-45-6789');
            })
            ->andReturn(array_fill(0, 1536, 0.1));

        $this->vectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->vectorStore->shouldReceive('upsert')->once();

        $this->service->ingest($entity);
    }

    /** @test */
    public function it_creates_belongs_to_team_relationships_in_neo4j(): void
    {
        $entity = $this->createMockNodeable([
            'id' => 1,
            'name' => 'Test Person',
            'team_id' => 5,
        ]);

        $this->graphStore->shouldReceive('createNode')->once();
        $this->graphStore->shouldReceive('nodeExists')
            ->with('Team', 5)
            ->andReturn(true);
        $this->graphStore->shouldReceive('nodeExists')
            ->andReturn(false);
        $this->graphStore->shouldReceive('relationshipExists')
            ->andReturn(false);
        $this->graphStore->shouldReceive('createRelationship')
            ->with(
                Mockery::any(), // fromLabel
                1, // fromId
                'Team', // toLabel
                5, // toId
                'BELONGS_TO_TEAM', // type
                Mockery::any() // properties
            )
            ->once();

        $this->embeddingProvider->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
        $this->vectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->vectorStore->shouldReceive('upsert')->once();

        $this->service->ingest($entity);
    }

    /**
     * Create a mock Nodeable entity
     */
    private function createMockNodeable(array $attributes): Nodeable
    {
        $entity = Mockery::mock(Nodeable::class);
        $entity->shouldReceive('getId')->andReturn($attributes['id']);
        $entity->shouldReceive('toArray')->andReturn($attributes);
        $entity->shouldReceive('getAttribute')
            ->with('team_id')
            ->andReturn($attributes['team_id'] ?? null);
        $entity->shouldReceive('getAttribute')->andReturn(null);

        $graphConfig = new GraphConfig('TestEntity', ['name'], []);
        $entity->shouldReceive('getGraphConfig')->andReturn($graphConfig);

        $vectorConfig = new VectorConfig('test_collection', ['name'], ['id']);
        $entity->shouldReceive('getVectorConfig')->andReturn($vectorConfig);

        return $entity;
    }

    /**
     * Create a mock Nodeable with securityRelatedTeamIds method
     */
    private function createMockNodeableWithSecurityTeamIds(array $teamIds): Nodeable
    {
        $entity = Mockery::mock(Nodeable::class);
        $entity->shouldReceive('getId')->andReturn(1);
        $entity->shouldReceive('toArray')->andReturn(['id' => 1, 'name' => 'Test']);
        $entity->shouldReceive('getAttribute')->andReturn(null);
        $entity->shouldReceive('securityRelatedTeamIds')->andReturn(collect($teamIds));

        $graphConfig = new GraphConfig('TestEntity', ['name'], []);
        $entity->shouldReceive('getGraphConfig')->andReturn($graphConfig);

        $vectorConfig = new VectorConfig('test_collection', ['name'], ['id']);
        $entity->shouldReceive('getVectorConfig')->andReturn($vectorConfig);

        return $entity;
    }

    /**
     * Create a mock Nodeable with sensibleColumns
     */
    private function createMockNodeableWithSensibleColumns(
        array $embedFields,
        array $sensibleColumns,
        array $attributes
    ): Nodeable {
        $entity = Mockery::mock(Nodeable::class);
        $entity->shouldReceive('getId')->andReturn($attributes['id'] ?? 1);
        $entity->shouldReceive('toArray')->andReturn($attributes);
        $entity->shouldReceive('getAttribute')->andReturn(null);

        // Mock the sensibleColumns property access
        $entity->sensibleColumns = $sensibleColumns;

        $graphConfig = new GraphConfig('TestEntity', array_keys($attributes), []);
        $entity->shouldReceive('getGraphConfig')->andReturn($graphConfig);

        $vectorConfig = new VectorConfig('test_collection', $embedFields, ['id']);
        $entity->shouldReceive('getVectorConfig')->andReturn($vectorConfig);

        return $entity;
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
