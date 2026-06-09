<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\SelectedEntity;
use Condoedge\Ai\DTOs\SemanticSelectionResult;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\Retrieval\HybridFuser;
use Condoedge\Ai\Services\Retrieval\NullReranker;
use Condoedge\Ai\Services\Retrieval\RerankerInterface;
use Condoedge\Ai\Services\SemanticContextSelector;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Wiring tests for the hybrid (RRF) + rerank funnel in ContextRetriever.
 *
 * These cover the new behavior added on top of the existing dense-only path:
 *  - similar-query candidates are over-fetched and routed through the reranker
 *    (over-fetch -> rerank -> cut) when reranking is enabled;
 *  - the default (rerank disabled / NullReranker) path is byte-identical to before;
 *  - semantic-selection entities are re-ordered by dense+sparse RRF when hybrid is on.
 */
class ContextRetrieverHybridRerankTest extends TestCase
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

        config()->set('ai.rag.rerank_enabled', false);
        config()->set('ai.rag.hybrid_enabled', false);
        config()->set('ai.query_understanding.enabled', false);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeRetriever(
        ?RerankerInterface $reranker = null,
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
            null,
            $reranker ?? new NullReranker(),
            new HybridFuser(),
        );
    }

    // =====================================================================
    // Similar-query rerank funnel
    // =====================================================================

    /** @test */
    public function default_path_fetches_exactly_the_limit_and_does_not_over_fetch(): void
    {
        // Rerank disabled -> pool size == limit, search receives the limit unchanged.
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

    /** @test */
    public function enabling_rerank_over_fetches_the_pool_before_cutting(): void
    {
        config()->set('ai.rag.rerank_enabled', true);

        $this->embedding->shouldReceive('embed')->andReturn([0.1]);

        // limit 5 * factor 4 = 20 candidates over-fetched.
        $this->vectorStore->shouldReceive('search')
            ->once()
            ->with('questions', Mockery::any(), 20, Mockery::any(), Mockery::any())
            ->andReturn([]);

        $this->graphStore->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $retriever = $this->makeRetriever();
        $retriever->retrieveContext('test question', ['limit' => 5]);

        $this->assertTrue(true);
    }

    /** @test */
    public function over_fetch_pool_is_capped(): void
    {
        config()->set('ai.rag.rerank_enabled', true);

        $this->embedding->shouldReceive('embed')->andReturn([0.1]);

        // limit 20 * 4 = 80, capped at 40.
        $this->vectorStore->shouldReceive('search')
            ->once()
            ->with('questions', Mockery::any(), 40, Mockery::any(), Mockery::any())
            ->andReturn([]);

        $this->graphStore->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $retriever = $this->makeRetriever();
        $retriever->retrieveContext('test question', ['limit' => 20]);

        $this->assertTrue(true);
    }

    /** @test */
    public function reranker_reorders_and_cuts_the_similar_query_pool(): void
    {
        config()->set('ai.rag.rerank_enabled', true);

        $this->embedding->shouldReceive('embed')->andReturn([0.1]);

        // Similar-query searcher returns 3 score-sorted candidates (best first).
        $this->vectorStore->shouldReceive('search')
            ->with('questions', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn([
                ['score' => 0.9, 'payload' => ['question' => 'Q1', 'cypher_query' => 'C1']],
                ['score' => 0.8, 'payload' => ['question' => 'Q2', 'cypher_query' => 'C2']],
                ['score' => 0.7, 'payload' => ['question' => 'Q3', 'cypher_query' => 'C3']],
            ]);

        // Schema-relevance fallback (no selector configured) queries 'semantic_entities'.
        $this->vectorStore->shouldReceive('search')
            ->with('semantic_entities', Mockery::any(), Mockery::any())
            ->andReturn([]);

        $this->graphStore->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        // A reranker that reverses input order, then the retriever cuts to limit (2).
        $reranker = new class implements RerankerInterface {
            public function rerank(string $query, array $candidates, callable $textOf, int $limit): array
            {
                return array_slice(array_reverse(array_values($candidates)), 0, $limit);
            }
        };

        $retriever = $this->makeRetriever($reranker);
        $ctx = $retriever->retrieveContext('test question', ['limit' => 2]);

        $this->assertCount(2, $ctx->similarQueries);
        // Reversed: Q3 first, then Q2 (Q1 cut).
        $this->assertSame('Q3', $ctx->similarQueries[0]['question']);
        $this->assertSame('Q2', $ctx->similarQueries[1]['question']);
    }

    /** @test */
    public function reranker_receives_question_and_query_as_candidate_text(): void
    {
        config()->set('ai.rag.rerank_enabled', true);

        $this->embedding->shouldReceive('embed')->andReturn([0.1]);
        $this->vectorStore->shouldReceive('search')
            ->with('questions', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn([
                ['score' => 0.9, 'payload' => ['question' => 'List teams', 'cypher_query' => 'MATCH (t:Team) RETURN t']],
            ]);
        $this->vectorStore->shouldReceive('search')
            ->with('semantic_entities', Mockery::any(), Mockery::any())
            ->andReturn([]);
        $this->graphStore->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $captured = null;
        $reranker = new class($captured) implements RerankerInterface {
            public function __construct(public &$captured) {}
            public function rerank(string $query, array $candidates, callable $textOf, int $limit): array
            {
                $this->captured = $textOf($candidates[0]);
                return array_slice(array_values($candidates), 0, $limit);
            }
        };

        $retriever = $this->makeRetriever($reranker);
        $retriever->retrieveContext('test question', ['limit' => 5]);

        $this->assertSame('List teams MATCH (t:Team) RETURN t', $reranker->captured);
    }

    // =====================================================================
    // Hybrid (dense + sparse RRF) on the semantic-selection path
    // =====================================================================

    /** @test */
    public function hybrid_disabled_keeps_dense_entity_order(): void
    {
        $entityConfigs = $this->entityConfigs();
        $selector = $this->selectorReturning($this->selectionOrder(['Person', 'Invoice']));

        $this->embedding->shouldReceive('embed')->andReturn([0.1]);
        $this->vectorStore->shouldReceive('search')->andReturn([]);
        $this->graphStore->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);
        $this->graphStore->shouldReceive('query')->andReturn([]);

        $retriever = $this->makeRetriever(null, $selector, $entityConfigs);
        $ctx = $retriever->retrieveContext('show invoices', ['includeExamples' => false]);

        // Dense order preserved (Person, then Invoice) because hybrid is off.
        $this->assertSame(
            ['App\\Models\\Person', 'App\\Models\\Invoice'],
            array_keys($ctx->semanticSelection->entities)
        );
    }

    /** @test */
    public function hybrid_enabled_promotes_lexically_matching_entity(): void
    {
        config()->set('ai.rag.hybrid_enabled', true);

        $entityConfigs = $this->entityConfigs();
        // Dense buries the lexically-correct entity (Invoice) below Person.
        $selector = $this->selectorReturning($this->selectionOrder(['Person', 'Invoice']));

        $this->embedding->shouldReceive('embed')->andReturn([0.1]);
        $this->vectorStore->shouldReceive('search')->andReturn([]);
        $this->graphStore->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);
        $this->graphStore->shouldReceive('query')->andReturn([]);

        $retriever = $this->makeRetriever(null, $selector, $entityConfigs);
        // Query has an exact lexical hit on the Invoice alias 'invoices'.
        $ctx = $retriever->retrieveContext('show me the invoices', ['includeExamples' => false]);

        // RRF lifts Invoice to the top via the sparse channel.
        $this->assertSame('App\\Models\\Invoice', array_key_first($ctx->semanticSelection->entities));
        // No entity is dropped.
        $this->assertCount(2, $ctx->semanticSelection->entities);
    }

    /** @test */
    public function hybrid_enabled_with_no_lexical_overlap_keeps_dense_order(): void
    {
        config()->set('ai.rag.hybrid_enabled', true);

        $entityConfigs = $this->entityConfigs();
        $selector = $this->selectorReturning($this->selectionOrder(['Person', 'Invoice']));

        $this->embedding->shouldReceive('embed')->andReturn([0.1]);
        $this->vectorStore->shouldReceive('search')->andReturn([]);
        $this->graphStore->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);
        $this->graphStore->shouldReceive('query')->andReturn([]);

        $retriever = $this->makeRetriever(null, $selector, $entityConfigs);
        // Query shares no lexical token with any entity's name/alias/scope text.
        $ctx = $retriever->retrieveContext('quarterly weather forecast', ['includeExamples' => false]);

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
