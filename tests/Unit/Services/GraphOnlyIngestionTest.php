<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig;
use Condoedge\Ai\Exceptions\DataConsistencyException;
use Condoedge\Ai\Services\BatchDataIngestionService;
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Records are NOT embedded: ingest / sync / remove (single and batch) write the graph node and
 * its relationships only — no embedding-provider call, no vector-store call.
 *
 * The embedding provider and the vector store are bound in the container as STRICT mocks with
 * no expectation: any call to either fails the test. The entity has no vector configuration at
 * all (the Nodeable contract no longer has one).
 */
class GraphOnlyIngestionTest extends TestCase
{
    private $graph;

    public function setUp(): void
    {
        parent::setUp();

        $this->graph = Mockery::mock(GraphStoreInterface::class);
        $this->graph->shouldReceive('getSchema')->andReturn(['labels' => ['Customer', 'Team']])->byDefault();

        // Any call on these two is a test failure.
        $this->app->instance(EmbeddingProviderInterface::class, Mockery::mock(EmbeddingProviderInterface::class));
        $this->app->instance(VectorStoreInterface::class, Mockery::mock(VectorStoreInterface::class));
        $this->app->instance(GraphStoreInterface::class, $this->graph);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function entity(int $id = 7): Nodeable
    {
        return new class($id) implements Nodeable {
            public function __construct(private int $id)
            {
            }

            public function getId(): string|int
            {
                return $this->id;
            }

            public function toArray(): array
            {
                return ['id' => $this->id, 'name' => 'Acme', 'team_id' => 3];
            }

            public function getGraphConfig(): GraphConfig
            {
                return new GraphConfig(
                    label: 'Customer',
                    properties: ['id', 'name', 'team_id'],
                    relationships: [new RelationshipConfig(type: 'BELONGS_TO_TEAM', targetLabel: 'Team', foreignKey: 'team_id')]
                );
            }
        };
    }

    private function single(): DataIngestionService
    {
        return new DataIngestionService($this->graph);
    }

    /** @test */
    public function ingest_writes_the_graph_node_and_nothing_else(): void
    {
        $this->graph->shouldReceive('createNode')->once()->with('Customer', ['id' => 7, 'name' => 'Acme', 'team_id' => 3])->andReturn('7');
        $this->graph->shouldReceive('nodeExists')->andReturn(true);
        $this->graph->shouldReceive('createRelationship')->andReturn(true);

        $result = $this->single()->ingest($this->entity());

        $this->assertSame(['graph_stored', 'relationships_created', 'errors'], array_keys($result));
        $this->assertTrue($result['graph_stored']);
        $this->assertSame([], $result['errors']);
    }

    /** @test */
    public function sync_creates_a_missing_node(): void
    {
        $this->graph->shouldReceive('nodeExists')->with('Customer', 7)->andReturn(false);
        $this->graph->shouldReceive('nodeExists')->with('Team', 3)->andReturn(true);
        $this->graph->shouldReceive('createNode')->once()->andReturn('7');
        $this->graph->shouldReceive('createRelationship')->andReturn(true);

        $this->assertSame(['action' => 'created', 'graph_synced' => true, 'errors' => []], $this->single()->sync($this->entity()));
    }

    /** @test */
    public function sync_updates_an_existing_node(): void
    {
        $this->graph->shouldReceive('nodeExists')->once()->with('Customer', 7)->andReturn(true);
        $this->graph->shouldReceive('updateNode')->once()->with('Customer', 7, ['name' => 'Acme', 'team_id' => 3])->andReturn(true);

        $this->assertSame(['action' => 'updated', 'graph_synced' => true, 'errors' => []], $this->single()->sync($this->entity()));
    }

    /** @test */
    public function remove_deletes_the_node(): void
    {
        $this->graph->shouldReceive('deleteNode')->once()->with('Customer', 7)->andReturn(true);

        $this->assertTrue($this->single()->remove($this->entity()));
    }

    /** @test */
    public function a_graph_failure_is_reported_as_a_consistency_exception(): void
    {
        $this->graph->shouldReceive('createNode')->once()->andThrow(new \RuntimeException('bolt down'));

        try {
            $this->single()->ingest($this->entity());
            $this->fail('expected DataConsistencyException');
        } catch (DataConsistencyException $e) {
            $this->assertStringContainsString('bolt down', $e->getMessage());
            $this->assertFalse($e->getContext()['graph_success']);
            $this->assertSame('ingest', $e->getContext()['operation']);
        }

        $this->graph->shouldReceive('deleteNode')->once()->andReturn(false);
        $this->expectException(DataConsistencyException::class);
        $this->single()->remove($this->entity());
    }

    /** @test */
    public function the_batch_path_writes_graph_nodes_only(): void
    {
        $cyphers = [];
        $this->graph->shouldReceive('query')->andReturnUsing(function (string $cypher, array $params = []) use (&$cyphers) {
            $cyphers[] = $cypher;

            return array_map(static fn (array $row) => ['id' => $row['id']], $params['batch'] ?? []);
        });

        $batch = new BatchDataIngestionService(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->graph,
            Mockery::mock(AiLogger::class)->shouldIgnoreMissing(),
        );

        $summary = $batch->ingestBatch([$this->entity(1), $this->entity(2), $this->entity(3)]);

        $this->assertSame(3, $summary['succeeded']);
        $this->assertSame(0, $summary['partially_succeeded']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame([], $summary['errors']);
        $this->assertCount(1, $cyphers, 'one UNWIND … MERGE for the label');
        $this->assertStringContainsString('MERGE (n:`Customer`', $cyphers[0]);
    }

    /** @test */
    public function the_container_bound_services_ingest_without_embedding_or_vector_calls(): void
    {
        $this->graph->shouldReceive('nodeExists')->andReturn(true);
        $this->graph->shouldReceive('updateNode')->once()->andReturn(true);

        $service = $this->app->make(DataIngestionServiceInterface::class);
        $this->assertInstanceOf(DataIngestionService::class, $service);
        $this->assertTrue($service->sync($this->entity())['graph_synced']);

        // The batch decorator resolves too, with no vector / embedding dependency.
        $this->assertInstanceOf(BatchDataIngestionService::class, $this->app->make(BatchDataIngestionService::class));
    }
}
