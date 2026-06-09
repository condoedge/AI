<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Services\RelationshipSyncer;
use Mockery;

/**
 * Unit tests for RelationshipSyncer
 *
 * Verifies the per-entity graph relationship logic extracted from
 * DataIngestionService (create-on-ingest and reconcile/sync).
 */
class RelationshipSyncerTest extends TestCase
{
    private $graphStore;
    private RelationshipSyncer $syncer;

    public function setUp(): void
    {
        parent::setUp();
        $this->graphStore = Mockery::mock(GraphStoreInterface::class);
        $this->syncer = new RelationshipSyncer($this->graphStore);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_relationships_creates_when_target_exists(): void
    {
        $entity = $this->makeEntity(['id' => 1, 'name' => 'Alice', 'team_id' => 42]);

        $this->graphStore->shouldReceive('nodeExists')
            ->once()->with('Team', 42)->andReturn(true);
        $this->graphStore->shouldReceive('relationshipExists')
            ->once()->andReturn(false);
        $this->graphStore->shouldReceive('createRelationship')
            ->once()
            ->with('User', 1, 'Team', 42, 'MEMBER_OF', Mockery::type('array'));

        $count = $this->syncer->createRelationships($entity, $entity->getGraphConfig());

        $this->assertSame(1, $count);
    }

    public function test_create_relationships_skips_null_foreign_key(): void
    {
        $entity = $this->makeEntity(['id' => 1, 'name' => 'Alice', 'team_id' => null]);

        $this->graphStore->shouldNotReceive('createRelationship');

        $count = $this->syncer->createRelationships($entity, $entity->getGraphConfig());

        $this->assertSame(0, $count);
    }

    public function test_create_relationships_skips_when_target_missing(): void
    {
        $entity = $this->makeEntity(['id' => 1, 'name' => 'Alice', 'team_id' => 42]);

        $this->graphStore->shouldReceive('nodeExists')
            ->once()->with('Team', 42)->andReturn(false);
        $this->graphStore->shouldNotReceive('createRelationship');

        $count = $this->syncer->createRelationships($entity, $entity->getGraphConfig());

        $this->assertSame(0, $count);
    }

    public function test_create_relationships_does_not_duplicate_existing(): void
    {
        $entity = $this->makeEntity(['id' => 1, 'name' => 'Alice', 'team_id' => 42]);

        $this->graphStore->shouldReceive('nodeExists')
            ->once()->with('Team', 42)->andReturn(true);
        $this->graphStore->shouldReceive('relationshipExists')
            ->once()->andReturn(true);
        $this->graphStore->shouldNotReceive('createRelationship');

        $count = $this->syncer->createRelationships($entity, $entity->getGraphConfig());

        $this->assertSame(0, $count);
    }

    public function test_sync_relationships_returns_summary_structure(): void
    {
        $result = $this->syncer->syncRelationships([]);

        $this->assertSame(0, $result['total_entities']);
        $this->assertSame(0, $result['relationships_created']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function test_sync_relationships_creates_missing_relationship(): void
    {
        $entity = $this->makeEntity(['id' => 1, 'name' => 'Alice', 'team_id' => 42]);

        // Source node exists
        $this->graphStore->shouldReceive('nodeExists')
            ->once()->with('User', 1)->andReturn(true);
        // Target node exists
        $this->graphStore->shouldReceive('nodeExists')
            ->once()->with('Team', 42)->andReturn(true);
        // Relationship not yet present
        $this->graphStore->shouldReceive('relationshipExists')
            ->once()->andReturn(false);
        $this->graphStore->shouldReceive('createRelationship')
            ->once();

        $result = $this->syncer->syncRelationships([$entity]);

        $this->assertSame(1, $result['total_entities']);
        $this->assertSame(1, $result['total_relationships_checked']);
        $this->assertSame(1, $result['relationships_created']);
    }

    public function test_sync_relationships_skips_existing_relationship(): void
    {
        $entity = $this->makeEntity(['id' => 1, 'name' => 'Alice', 'team_id' => 42]);

        $this->graphStore->shouldReceive('nodeExists')
            ->once()->with('User', 1)->andReturn(true);
        $this->graphStore->shouldReceive('nodeExists')
            ->once()->with('Team', 42)->andReturn(true);
        $this->graphStore->shouldReceive('relationshipExists')
            ->once()->andReturn(true);
        $this->graphStore->shouldNotReceive('createRelationship');

        $result = $this->syncer->syncRelationships([$entity]);

        $this->assertSame(1, $result['relationships_skipped']);
        $this->assertSame(0, $result['relationships_created']);
    }

    public function test_sync_relationships_counts_failure_when_target_missing(): void
    {
        $entity = $this->makeEntity(['id' => 1, 'name' => 'Alice', 'team_id' => 42]);

        $this->graphStore->shouldReceive('nodeExists')
            ->once()->with('User', 1)->andReturn(true);
        $this->graphStore->shouldReceive('nodeExists')
            ->once()->with('Team', 42)->andReturn(false);
        $this->graphStore->shouldNotReceive('createRelationship');

        $result = $this->syncer->syncRelationships([$entity]);

        $this->assertSame(1, $result['relationships_failed']);
        $this->assertSame(0, $result['relationships_created']);
    }

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

    private function makeEntity(array $data): Nodeable
    {
        return new class($data) implements Nodeable {
            public function __construct(private array $data) {}
            public function getId(): int { return $this->data['id']; }
            public function toArray(): array { return $this->data; }
            public function getGraphConfig(): GraphConfig
            {
                return new GraphConfig(
                    label: 'User',
                    properties: ['id', 'name'],
                    relationships: [
                        new RelationshipConfig(
                            type: 'MEMBER_OF',
                            targetLabel: 'Team',
                            foreignKey: 'team_id',
                            properties: [],
                        ),
                    ],
                );
            }
            public function getVectorConfig(): VectorConfig
            {
                return new VectorConfig('users', ['name'], ['id']);
            }
        };
    }
}
