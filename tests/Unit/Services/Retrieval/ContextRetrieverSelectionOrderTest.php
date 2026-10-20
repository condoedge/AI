<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\SelectedEntity;
use Condoedge\Ai\DTOs\SemanticSelectionResult;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\SemanticContextSelector;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Wiring tests for the retrieval pools of ContextRetriever.
 *
 *  - similar-query candidates are fetched at exactly the requested limit;
 *  - semantic-selection entities keep the selector's (dense) order.
 */
class ContextRetrieverSelectionOrderTest extends TestCase
{
    private $vectorStore;
    private $graphStore;
    private $embedding;
    private AiLogger $logger;

    public function setUp(): void
    {
        parent::setUp();

        $this->vectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->vectorStore->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $this->graphStore = Mockery::mock(GraphStoreInterface::class);
        $this->embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $this->logger = new AiLogger();

        config()->set('ai.query_understanding.enabled', false);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeRetriever(
        ?SemanticContextSelector $selector = null,
        array $entityConfigs = []
    ): ContextRetriever {
        return new ContextRetriever(
            $this->vectorStore,
            $this->graphStore,
            $this->embedding,
            $this->logger,
            $entityConfigs,
            null,
            $selector,
            null,
            null,
            null,
            null,
        );
    }

    // =====================================================================
    // Similar-query pool
    // =====================================================================

    /** @test */
    public function default_path_fetches_exactly_the_limit_and_does_not_over_fetch(): void
    {
        // Pool size == limit: the search receives the limit unchanged.
        $this->embedding->shouldReceive('embed')->andReturn([0.1]);

        $this->vectorStore->shouldReceive('search')
            ->once()
            ->with('questions', Mockery::any(), 5, Mockery::any(), Mockery::any())
            ->andReturn([]);

        $this->graphStore->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $retriever = $this->makeRetriever();
        $retriever->retrieveContext('test question', ['limit' => 5]);

        $this->assertTrue(true);
    }

    // =====================================================================
    // Semantic-selection order
    // =====================================================================

    /** @test */
    public function the_selected_entities_keep_the_dense_order(): void
    {
        $entityConfigs = $this->entityConfigs();
        $selector = $this->selectorReturning($this->selectionOrder(['Person', 'Invoice']));

        $this->embedding->shouldReceive('embed')->andReturn([0.1]);
        $this->vectorStore->shouldReceive('search')->andReturn([]);
        $this->graphStore->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);
        $this->graphStore->shouldReceive('query')->andReturn([]);

        $retriever = $this->makeRetriever($selector, $entityConfigs);
        $ctx = $retriever->retrieveContext('show invoices');

        // Dense order preserved (Person, then Invoice).
        $this->assertSame(
            ['App\\Models\\Person', 'App\\Models\\Invoice'],
            array_keys($ctx->semanticSelection->entities)
        );
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    private function entityConfigs(): array
    {
        return [
            'App\\Models\\Person' => [
                'metadata' => ['aliases' => ['person', 'people', 'member'], 'scopes' => []],
            ],
            'App\\Models\\Invoice' => [
                'metadata' => ['aliases' => ['invoice', 'invoices', 'billing'], 'scopes' => []],
            ],
        ];
    }

    /**
     * Build a SemanticSelectionResult with entities in a given FQCN order.
     *
     * @param array<int, string> $shortNames
     */
    private function selectionOrder(array $shortNames): SemanticSelectionResult
    {
        $entities = [];
        $score = 0.9;
        foreach ($shortNames as $short) {
            $fqcn = 'App\\Models\\' . $short;
            $entities[$fqcn] = new SelectedEntity(
                score: $score,
                matchedText: $short,
                config: $this->entityConfigs()[$fqcn] ?? null,
            );
            $score -= 0.1;
        }

        return new SemanticSelectionResult(entities: $entities);
    }

    private function selectorReturning(SemanticSelectionResult $result): SemanticContextSelector
    {
        $selector = Mockery::mock(SemanticContextSelector::class);
        $selector->shouldReceive('selectRelevantContext')->andReturn($result);

        return $selector;
    }
}
