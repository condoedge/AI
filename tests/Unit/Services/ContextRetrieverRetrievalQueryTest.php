<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\DTOs\SemanticSelectionResult;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Services\SemanticContextSelector;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * P0-2: the Query-Understanding rewrite (searchQuery) must drive DATA/graph
 * grounding, not just file search. These tests capture the query string that
 * ContextRetriever passes to SemanticContextSelector::selectRelevantContext and
 * assert it is qu.searchQuery when present, and the raw question when empty.
 *
 * Real PipelineContext / QueryUnderstandingResult objects are used (no arrays).
 */
class ContextRetrieverRetrievalQueryTest extends TestCase
{
    private $vectorStore;
    private $graphStore;
    private $embedding;
    private $selector;

    public function setUp(): void
    {
        parent::setUp();

        $this->vectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->graphStore = Mockery::mock(GraphStoreInterface::class);
        $this->embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $this->selector = Mockery::mock(SemanticContextSelector::class);

        // Downstream calls are permissive — this test only cares about the query
        // string handed to the semantic selector.
        $this->embedding->shouldReceive('embed')->andReturn([0.1, 0.2])->byDefault();
        $this->vectorStore->shouldReceive('search')->andReturn([])->byDefault();
        $this->graphStore->shouldReceive('getSchema')->andReturn([
            'labels' => [],
            'relationshipTypes' => [],
            'propertyKeys' => [],
        ])->byDefault();
        $this->graphStore->shouldReceive('query')->andReturn([])->byDefault();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeLogger()
    {
        // Permissive logger mock — avoids depending on log-channel config.
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTrace')->andReturn('trace')->byDefault();
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('error')->byDefault();
        $logger->shouldReceive('fallback')->byDefault();

        return $logger;
    }

    private function makeRetriever(): ContextRetriever
    {
        $retriever = new ContextRetriever(
            $this->vectorStore,
            $this->graphStore,
            $this->embedding,
            $this->makeLogger(),
            [] // entity configs irrelevant for this test
        );
        $retriever->setContextSelector($this->selector);

        return $retriever;
    }

    /**
     * Wire a QueryUnderstandingService stub that yields the given QU result so
     * retrieveContext builds a real PipelineContext carrying qu.searchQuery.
     */
    private function withQu(ContextRetriever $retriever, QueryUnderstandingResult $qu): void
    {
        $qu_service = Mockery::mock(QueryUnderstandingService::class);
        $qu_service->shouldReceive('analyze')->andReturn($qu);
        $retriever->setQueryUnderstanding($qu_service);
    }

    /** @test */
    public function it_embeds_the_qu_search_query_for_semantic_selection_when_present(): void
    {
        $rawQuestion = 'how many parking do i have';
        $rewrite = 'parking spaces related to current person in graph';

        $retriever = $this->makeRetriever();
        $this->withQu($retriever, new QueryUnderstandingResult(searchQuery: $rewrite));

        $captured = null;
        $this->selector
            ->shouldReceive('selectRelevantContext')
            ->once()
            ->andReturnUsing(function (string $query) use (&$captured) {
                $captured = $query;
                return new SemanticSelectionResult();
            });

        $retriever->retrieveContext($rawQuestion);

        // The rewrite (not the raw question) must drive graph/entity retrieval.
        $this->assertSame($rewrite, $captured);
    }

    /** @test */
    public function it_falls_back_to_the_raw_question_when_search_query_is_empty(): void
    {
        $rawQuestion = 'list all invoices';

        $retriever = $this->makeRetriever();
        // Empty searchQuery — the raw question must be used.
        $this->withQu($retriever, new QueryUnderstandingResult(searchQuery: ''));

        $captured = null;
        $this->selector
            ->shouldReceive('selectRelevantContext')
            ->once()
            ->andReturnUsing(function (string $query) use (&$captured) {
                $captured = $query;
                return new SemanticSelectionResult();
            });

        $retriever->retrieveContext($rawQuestion);

        $this->assertSame($rawQuestion, $captured);
    }
}
