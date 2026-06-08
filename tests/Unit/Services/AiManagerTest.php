<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Mockery;

/**
 * Tests for AiManager facade pass-through methods.
 *
 * Covers:
 * - P0-1: syncRelationships() delegates to the ingestion service (the observer
 *   calls AI::syncRelationships([...]) so the method must exist).
 * - P0-5: searchSimilar(string, array $options) must unpack the options array
 *   into the retriever's (string $collection, int $limit) signature without a
 *   TypeError under strict_types.
 * - P0-6: getExampleEntities(array $labels, int $limit) must call the retriever
 *   per label (string $label) and merge results without a TypeError.
 */
class AiManagerTest extends TestCase
{
    private AiManager $manager;
    private DataIngestionServiceInterface $mockIngestion;
    private ContextRetrieverInterface $mockContext;
    private EmbeddingProviderInterface $mockEmbedding;
    private LlmProviderInterface $mockLlm;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private VectorStoreInterface $mockVectorStore;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockIngestion = Mockery::mock(DataIngestionServiceInterface::class);
        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockEmbedding = Mockery::mock(EmbeddingProviderInterface::class);
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);

        $this->manager = new AiManager(
            $this->mockIngestion,
            $this->mockContext,
            $this->mockEmbedding,
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $this->mockVectorStore
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // P0-1: syncRelationships pass-through
    // =========================================================================

    /** @test */
    public function sync_relationships_delegates_to_ingestion_service(): void
    {
        $entities = [
            Mockery::mock(\Condoedge\Ai\Domain\Contracts\Nodeable::class),
            Mockery::mock(\Condoedge\Ai\Domain\Contracts\Nodeable::class),
        ];

        $summary = [
            'total_entities' => 2,
            'total_relationships_checked' => 4,
            'relationships_created' => 1,
            'relationships_skipped' => 3,
            'relationships_failed' => 0,
            'errors' => [],
        ];

        $this->mockIngestion->shouldReceive('syncRelationships')
            ->once()
            ->with($entities)
            ->andReturn($summary);

        $result = $this->manager->syncRelationships($entities);

        $this->assertSame($summary, $result);
    }

    // =========================================================================
    // P0-5: searchSimilar unpacks options array
    // =========================================================================

    /** @test */
    public function search_similar_unpacks_options_without_type_error(): void
    {
        $question = 'Find all customers';
        $expected = [
            ['question' => 'Find all customers', 'query' => 'MATCH (c:Customer) RETURN c', 'score' => 0.9],
        ];

        // The retriever signature is (string $collection, int $limit) — passing the
        // raw options array would be a TypeError. The manager must unpack it.
        $this->mockContext->shouldReceive('searchSimilar')
            ->once()
            ->with($question, 'people', 10)
            ->andReturn($expected);

        $result = $this->manager->searchSimilar($question, [
            'collection' => 'people',
            'limit' => 10,
        ]);

        $this->assertSame($expected, $result);
    }

    /** @test */
    public function search_similar_uses_defaults_when_options_empty(): void
    {
        $question = 'Find all teams';

        $this->mockContext->shouldReceive('searchSimilar')
            ->once()
            ->with($question, 'questions', 5)
            ->andReturn([]);

        $result = $this->manager->searchSimilar($question);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // P0-6: getExampleEntities loops labels and merges
    // =========================================================================

    /** @test */
    public function get_example_entities_loops_labels_and_merges_without_type_error(): void
    {
        $teamExamples = [
            ['id' => 1, 'name' => 'Alpha Team'],
            ['id' => 2, 'name' => 'Beta Team'],
        ];
        $personExamples = [
            ['id' => 10, 'name' => 'Alice'],
        ];

        // The retriever signature is (string $label, int $limit) — passing the
        // labels array would be a TypeError. The manager must call per label.
        $this->mockContext->shouldReceive('getExampleEntities')
            ->once()
            ->with('Team', 3)
            ->andReturn($teamExamples);

        $this->mockContext->shouldReceive('getExampleEntities')
            ->once()
            ->with('Person', 3)
            ->andReturn($personExamples);

        $result = $this->manager->getExampleEntities(['Team', 'Person']);

        $this->assertSame(
            ['Team' => $teamExamples, 'Person' => $personExamples],
            $result
        );
    }

    /** @test */
    public function get_example_entities_forwards_limit_per_label(): void
    {
        $this->mockContext->shouldReceive('getExampleEntities')
            ->once()
            ->with('Customer', 5)
            ->andReturn([['id' => 1]]);

        $result = $this->manager->getExampleEntities(['Customer'], 5);

        $this->assertSame(['Customer' => [['id' => 1]]], $result);
    }
}
