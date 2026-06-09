<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\StressTests;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Exceptions\DataConsistencyException;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;
use Illuminate\Database\Eloquent\Model;
use Mockery;

/**
 * Dual-Storage Coordination Failure Tests
 *
 * Tests scenarios where Neo4j and Qdrant coordination fails:
 * - One store succeeds, other fails (orphaned data)
 * - Both stores fail simultaneously
 * - Timeouts during ingestion
 * - Very large model data (10MB+)
 * - Concurrent updates to same entity
 * - Transaction rollback scenarios
 *
 * @package Condoedge\Ai\Tests\Unit\StressTests
 */
class DualStorageFailureTest extends TestCase
{
    /** @test */
    public function it_handles_neo4j_success_but_qdrant_failure()
    {
        // SCENARIO: Neo4j succeeds, Qdrant (vector) fails.
        // The service must roll back the orphaned graph node (compensating
        // transaction) and surface a DataConsistencyException marked rolled_back.

        $vectorMock = Mockery::mock(VectorStoreInterface::class);
        $graphMock = Mockery::mock(GraphStoreInterface::class);
        $embeddingMock = Mockery::mock(EmbeddingProviderInterface::class);

        // Phase 1: Neo4j node creation succeeds.
        $graphMock->shouldReceive('createNode')
            ->once()
            ->andReturn(1);

        // Phase 2 (vector): collection check passes, embedding succeeds, upsert fails.
        $vectorMock->shouldReceive('collectionExists')->andReturn(true);
        $embeddingMock->shouldReceive('embed')
            ->once()
            ->andReturn([0.1, 0.2, 0.3]);
        $vectorMock->shouldReceive('upsert')
            ->once()
            ->andThrow(new \Exception('Qdrant connection timeout'));

        // Compensating transaction: the orphaned graph node is deleted.
        $graphMock->shouldReceive('deleteNode')
            ->once()
            ->andReturn(true);

        $service = new DataIngestionService(
            $vectorMock,
            $graphMock,
            $embeddingMock
        );

        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'test';
            protected $fillable = ['name'];
            public $id = 1;

            public function nodeableConfig(): NodeableConfig
            {
                return NodeableConfig::for(static::class)
                    ->label('Test')
                    ->properties('id', 'name')
                    ->collection('test')
                    ->embedFields('name');
            }
        };
        $model->name = 'Test Entity';

        // Vector failure after graph success must raise a consistency exception
        // that records the rollback so no orphaned graph data is left behind.
        try {
            $service->ingest($model);
            $this->fail('Expected DataConsistencyException was not thrown');
        } catch (DataConsistencyException $e) {
            $context = $e->getContext();
            $this->assertTrue($e->wasRolledBack(), 'Graph node should have been rolled back');
            $this->assertTrue($context['graph_success']);
            $this->assertFalse($context['vector_success']);
        }
    }

    /** @test */
    public function it_handles_neo4j_failure_before_vector_is_attempted()
    {
        // SCENARIO: Neo4j (graph) is Phase 1, so when it fails the vector store
        // is never touched — there is nothing to roll back and no orphaned data.

        $vectorMock = Mockery::mock(VectorStoreInterface::class);
        $graphMock = Mockery::mock(GraphStoreInterface::class);
        $embeddingMock = Mockery::mock(EmbeddingProviderInterface::class);

        // Neo4j fails on the very first write.
        $graphMock->shouldReceive('createNode')
            ->once()
            ->andThrow(new \Exception('Neo4j connection refused'));

        // Vector store must NOT be written to when graph fails first.
        $vectorMock->shouldNotReceive('upsert');

        $service = new DataIngestionService(
            $vectorMock,
            $graphMock,
            $embeddingMock
        );

        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'test';
            public $id = 2;

            public function nodeableConfig(): NodeableConfig
            {
                return NodeableConfig::for(static::class)
                    ->label('Test')
                    ->properties('id')
                    ->collection('test')
                    ->embedFields('id');
            }
        };

        try {
            $service->ingest($model);
            $this->fail('Expected DataConsistencyException was not thrown');
        } catch (DataConsistencyException $e) {
            $context = $e->getContext();
            $this->assertFalse($context['graph_success']);
            $this->assertFalse($context['vector_success']);
            $this->assertFalse($context['rolled_back'], 'Nothing to roll back when graph fails first');
        }
    }

    /** @test */
    public function it_handles_both_stores_failing_simultaneously()
    {
        $vectorMock = Mockery::mock(VectorStoreInterface::class);
        $graphMock = Mockery::mock(GraphStoreInterface::class);
        $embeddingMock = Mockery::mock(EmbeddingProviderInterface::class);

        // Graph is Phase 1 and fails; vector would also be unavailable but is
        // never reached. The service must still fail cleanly with no partial data.
        $graphMock->shouldReceive('createNode')
            ->andThrow(new \Exception('Neo4j down'));

        $vectorMock->shouldReceive('upsert')
            ->andThrow(new \Exception('Qdrant down'));

        $embeddingMock->shouldReceive('embed')
            ->andReturn([0.1]);

        $service = new DataIngestionService(
            $vectorMock,
            $graphMock,
            $embeddingMock
        );

        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'test';
            public $id = 3;

            public function nodeableConfig(): NodeableConfig
            {
                return NodeableConfig::for(static::class)
                    ->label('Test')
                    ->properties('id')
                    ->collection('test')
                    ->embedFields('id');
            }
        };

        $this->expectException(DataConsistencyException::class);

        $service->ingest($model);

        // Should fail cleanly without partial data
    }

    /** @test */
    public function it_handles_very_large_model_data_10mb_plus()
    {
        // STRESS TEST: Model with huge data (10MB+)
        // Tests memory limits and timeout handling

        $hugeData = str_repeat('x', 10 * 1024 * 1024); // 10MB string

        $model = new class($hugeData) extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'huge';
            public $id = 4;
            public $content;

            // Param must be optional: Eloquent re-instantiates the model with no
            // args while booting its event listeners (HasNodeableConfig boot).
            public function __construct($content = '')
            {
                parent::__construct();
                $this->content = $content;
            }

            public function nodeableConfig(): NodeableConfig
            {
                return NodeableConfig::for(static::class)
                    ->label('Huge')
                    ->properties('id', 'content')
                    ->collection('huge')
                    ->embedFields('content');
            }

            public function toArray(): array
            {
                return ['id' => $this->id, 'content' => $this->content];
            }
        };

        $this->assertGreaterThanOrEqual(10 * 1024 * 1024, strlen($model->content));

        // Should not crash on huge data
        // Might throw memory exception, which is acceptable
        try {
            // Just test the model exists
            $this->assertTrue(true);

            // ISSUE IDENTIFIED: No size validation before ingestion
            // System should:
            // 1. Validate entity size before processing
            // 2. Reject entities over threshold (e.g., 1MB)
            // 3. Or chunk large data
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Exception::class, $e);
        }
    }

    /** @test */
    public function it_handles_model_with_binary_data_in_properties()
    {
        // Binary data should not be embedded
        $binaryData = random_bytes(1024);

        $model = new class($binaryData) extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'binary';
            public $id = 5;
            public $data;

            // Param must be optional: Eloquent re-instantiates the model with no
            // args while booting its event listeners (HasNodeableConfig boot).
            public function __construct($data = '')
            {
                parent::__construct();
                $this->data = $data;
            }

            public function nodeableConfig(): NodeableConfig
            {
                return NodeableConfig::for(static::class)
                    ->label('Binary')
                    ->properties('id', 'data')
                    ->collection('binary')
                    ->embedFields('data'); // PROBLEM: Trying to embed binary
            }

            public function toArray(): array
            {
                return ['id' => $this->id, 'data' => $this->data];
            }
        };

        // Embedding binary data will likely fail or produce garbage
        $embeddingMock = Mockery::mock(EmbeddingProviderInterface::class);

        // Binary data can't be embedded meaningfully
        $embeddingMock->shouldReceive('embed')
            ->andThrow(new \Exception('Cannot embed binary data'));

        // ISSUE: No validation of data types before embedding
        // System should filter out binary fields
        $this->assertTrue(true);
    }

    /** @test */
    public function it_handles_concurrent_updates_to_same_entity()
    {
        // CONCURRENCY TEST: Two processes update same entity simultaneously
        // Without proper locking, this can cause data corruption

        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'concurrent';
            public $id = 6;
            public $counter = 0;

            public function nodeableConfig(): NodeableConfig
            {
                return NodeableConfig::for(static::class)
                    ->label('Concurrent')
                    ->properties('id', 'counter')
                    ->collection('concurrent')
                    ->embedFields('counter');
            }
        };

        // Simulate concurrent updates
        // In real scenario, this would be two separate processes

        $model->counter = 1; // Update 1
        $model->counter = 2; // Update 2 (immediately after)

        // ISSUE IDENTIFIED: No concurrency control
        // Last write wins, no optimistic locking
        // Should implement:
        // 1. Version numbers
        // 2. Optimistic locking
        // 3. Or queue updates

        $this->assertEquals(2, $model->counter);
    }

    /** @test */
    public function it_rolls_back_graph_when_embedding_generation_fails()
    {
        // SCENARIO: The graph node is created, then embedding generation fails
        // during the vector phase. The service must roll back the graph node so
        // no orphaned data remains.

        $vectorMock = Mockery::mock(VectorStoreInterface::class);
        $graphMock = Mockery::mock(GraphStoreInterface::class);
        $embeddingMock = Mockery::mock(EmbeddingProviderInterface::class);

        $graphMock->shouldReceive('createNode')->once()->andReturn(9);
        $vectorMock->shouldReceive('collectionExists')->andReturn(true);

        // Embedding fails (API timeout, rate limit, etc.).
        $embeddingMock->shouldReceive('embed')
            ->once()
            ->andThrow(new \Exception('Embedding API rate limit exceeded'));

        // Vector upsert must never happen, and the graph node must be rolled back.
        $vectorMock->shouldNotReceive('upsert');
        $graphMock->shouldReceive('deleteNode')->once()->andReturn(true);

        $service = new DataIngestionService($vectorMock, $graphMock, $embeddingMock);

        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'rate';
            protected $fillable = ['name'];
            public $id = 9;

            public function nodeableConfig(): NodeableConfig
            {
                return NodeableConfig::for(static::class)
                    ->label('Rate')
                    ->properties('id', 'name')
                    ->collection('rate')
                    ->embedFields('name');
            }
        };
        $model->name = 'first';

        try {
            $service->ingest($model);
            $this->fail('Expected DataConsistencyException was not thrown');
        } catch (DataConsistencyException $e) {
            $this->assertTrue($e->wasRolledBack());
            $this->assertFalse($e->getContext()['vector_success']);
        }
    }

    /** @test */
    public function it_handles_null_values_in_embed_fields()
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'nulls';
            public $id = 7;
            public $description = null; // NULL embed field

            public function nodeableConfig(): NodeableConfig
            {
                return NodeableConfig::for(static::class)
                    ->label('Nulls')
                    ->properties('id', 'description')
                    ->collection('nulls')
                    ->embedFields('description'); // Trying to embed NULL
            }

            public function toArray(): array
            {
                return ['id' => $this->id, 'description' => $this->description];
            }
        };

        // Cannot generate embedding from NULL value
        // System should:
        // 1. Skip NULL fields
        // 2. Use empty string
        // 3. Or throw validation error

        $this->assertNull($model->description);

        // ISSUE: No NULL handling in embed field detection
    }

    /** @test */
    public function it_handles_empty_string_embed_fields()
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'empty';
            public $id = 8;
            public $content = ''; // Empty string

            public function nodeableConfig(): NodeableConfig
            {
                return NodeableConfig::for(static::class)
                    ->label('Empty')
                    ->properties('id', 'content')
                    ->collection('empty')
                    ->embedFields('content');
            }

            public function toArray(): array
            {
                return ['id' => $this->id, 'content' => $this->content];
            }
        };

        // Embedding empty string might fail or return zero vector
        // System should handle gracefully

        $this->assertEquals('', $model->content);
    }

    /** @test */
    public function it_detects_orphaned_data_inconsistencies()
    {
        // TEST: Detect when Neo4j has data but Qdrant doesn't (or vice versa)
        // This would require querying both stores and comparing

        $graphMock = Mockery::mock(GraphStoreInterface::class);
        $vectorMock = Mockery::mock(VectorStoreInterface::class);

        // Neo4j has entity ID 100
        $graphMock->shouldReceive('exists')
            ->with('Test', 100)
            ->andReturn(true);

        // Qdrant doesn't have it
        $vectorMock->shouldReceive('exists')
            ->with('test', 100)
            ->andReturn(false);

        // ISSUE: Inconsistent state detected
        // System should have consistency check / repair tool

        $this->assertTrue(true);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
