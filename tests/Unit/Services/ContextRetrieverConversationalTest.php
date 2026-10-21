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
 * DEFERRED RETRIEVAL — the retriever's half of a conversational turn.
 *
 * On the caller's request (ContextRetriever::DEFER_WHEN_NOTHING_NAMED, AiManager's first
 * pass), a reading that names nothing to look up stops after Query Understanding: no
 * semantic entity selection (embedding + vector search), no few-shot search, no graph
 * schema, no property sampling, no entity metadata — the QU call is the only one, and
 * PipelineContext::$retrievalDeferred says so. When the turn is not conversation, the
 * caller retrieves in full on the SAME reading (ContextRetriever::UNDERSTOOD): QU is not
 * asked again. Without the request, or on a reading that names something, nothing changes.
 */
class ContextRetrieverConversationalTest extends TestCase
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
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param int $quCalls how many times the QU service may be asked */
    private function retriever(QueryUnderstandingResult $qu, int $quCalls = 1): ContextRetriever
    {
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTrace')->andReturn('trace')->byDefault();
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        foreach (['info', 'debug', 'error', 'warning', 'fallback'] as $level) {
            $logger->shouldReceive($level)->byDefault();
        }

        $retriever = new ContextRetriever($this->vectorStore, $this->graphStore, $this->embedding, $logger, []);
        $retriever->setContextSelector($this->selector);

        $service = Mockery::mock(QueryUnderstandingService::class);
        $service->shouldReceive('analyze')->times($quCalls)->andReturn($qu);
        $retriever->setQueryUnderstanding($service);

        return $retriever;
    }

    private function expectNoRetrievalCall(): void
    {
        $this->selector->shouldReceive('selectRelevantContext')->never();
        $this->embedding->shouldReceive('embed')->never();
        $this->embedding->shouldReceive('embedBatch')->never();
        $this->vectorStore->shouldReceive('search')->never();
        $this->graphStore->shouldReceive('getSchema')->never();
        $this->graphStore->shouldReceive('query')->never();
    }

    private function allowRetrieval(): void
    {
        $this->embedding->shouldReceive('embed')->andReturn([0.1, 0.2])->byDefault();
        $this->vectorStore->shouldReceive('search')->andReturn([])->byDefault();
        $this->graphStore->shouldReceive('getSchema')->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []])->byDefault();
        $this->graphStore->shouldReceive('query')->andReturn([])->byDefault();
    }

    /** @test */
    public function a_reading_that_names_nothing_stops_after_query_understanding_when_asked(): void
    {
        $this->expectNoRetrievalCall();

        $ctx = $this->retriever(new QueryUnderstandingResult(questionType: 'knowledge_query', language: 'en'))
            ->retrieveContext('How are you?', [ContextRetriever::DEFER_WHEN_NOTHING_NAMED => true]);

        $this->assertTrue($ctx->retrievalDeferred);
        $this->assertTrue($ctx->qu->asksForNothingToLookUp());
        $this->assertSame('How are you?', $ctx->question);
        $this->assertSame([], $ctx->graphSchema);
        $this->assertSame([], $ctx->similarQueries);
    }

    /** @test Without the caller's request nothing changes (every other caller of the retriever). */
    public function without_the_request_the_context_is_built_as_before(): void
    {
        $this->allowRetrieval();
        $this->selector->shouldReceive('selectRelevantContext')->once()->andReturn(new SemanticSelectionResult());

        $ctx = $this->retriever(new QueryUnderstandingResult(questionType: 'knowledge_query'))->retrieveContext('How are you?');

        $this->assertFalse($ctx->retrievalDeferred);
    }

    /** @test A host without Query Understanding (the empty reading names nothing by construction) keeps its pipeline. */
    public function without_query_understanding_nothing_is_deferred(): void
    {
        $this->allowRetrieval();
        $this->selector->shouldReceive('selectRelevantContext')->once()->andReturn(new SemanticSelectionResult());
        $logger = Mockery::mock(AiLogger::class)->shouldIgnoreMissing();
        $logger->shouldReceive('stopTimer')->andReturn(0.0);
        $retriever = new ContextRetriever($this->vectorStore, $this->graphStore, $this->embedding, $logger, []);
        $retriever->setContextSelector($this->selector);

        $ctx = $retriever->retrieveContext('How are you?', [ContextRetriever::DEFER_WHEN_NOTHING_NAMED => true]);

        $this->assertFalse($ctx->retrievalDeferred);
    }

    /** @test A reading that names something is built as before, request or not. */
    public function a_reading_that_names_something_is_built_as_before(): void
    {
        $this->allowRetrieval();
        $this->selector->shouldReceive('selectRelevantContext')->once()->andReturn(new SemanticSelectionResult());

        $ctx = $this->retriever(new QueryUnderstandingResult(entityTypes: ['BoardMember'], questionType: 'data_query'))
            ->retrieveContext('Hi! Who is on our board?', [ContextRetriever::DEFER_WHEN_NOTHING_NAMED => true]);

        $this->assertFalse($ctx->retrievalDeferred);
    }

    /** @test The full second pass reuses the turn's reading: QU is not asked again. */
    public function the_full_pass_reuses_the_reading_it_is_given(): void
    {
        $this->allowRetrieval();
        $this->selector->shouldReceive('selectRelevantContext')->once()->andReturn(new SemanticSelectionResult());
        $reading = new QueryUnderstandingResult(intent: 'page_explanation', questionType: 'knowledge_query', language: 'en');

        $ctx = $this->retriever(new QueryUnderstandingResult(), quCalls: 0)
            ->retrieveContext('What can I do on this page?', [ContextRetriever::UNDERSTOOD => $reading]);

        $this->assertSame($reading, $ctx->qu);
        $this->assertFalse($ctx->retrievalDeferred);
    }
}
