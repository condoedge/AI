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
 * A conversational turn (QueryUnderstandingResult::isConversational()) needs no context:
 * after Query Understanding, the retriever makes NO further call — no semantic entity
 * selection (embedding + vector search), no few-shot search, no graph schema, no property
 * sampling, no entity metadata. Its QU call is the only one: with the conversational
 * composition, the turn makes two LLM calls in all. Every other turn is unchanged.
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

    private function retriever(QueryUnderstandingResult $qu): ContextRetriever
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
        $service->shouldReceive('analyze')->once()->andReturn($qu);
        $retriever->setQueryUnderstanding($service);

        return $retriever;
    }

    /** @test */
    public function a_conversational_turn_makes_no_call_after_query_understanding(): void
    {
        $this->selector->shouldReceive('selectRelevantContext')->never();
        $this->embedding->shouldReceive('embed')->never();
        $this->embedding->shouldReceive('embedBatch')->never();
        $this->vectorStore->shouldReceive('search')->never();
        $this->graphStore->shouldReceive('getSchema')->never();
        $this->graphStore->shouldReceive('query')->never();

        $ctx = $this->retriever(new QueryUnderstandingResult(questionType: 'conversational', language: 'en'))
            ->retrieveContext('How are you?');

        $this->assertTrue($ctx->qu->isConversational());
        $this->assertSame('How are you?', $ctx->question);
        $this->assertSame([], $ctx->graphSchema);
        $this->assertSame([], $ctx->similarQueries);
    }

    /** @test A "conversational" that names a record kind is not one: the context is built as before. */
    public function a_conversational_type_naming_a_record_kind_builds_the_context_as_before(): void
    {
        $this->embedding->shouldReceive('embed')->andReturn([0.1, 0.2])->byDefault();
        $this->vectorStore->shouldReceive('search')->andReturn([])->byDefault();
        $this->graphStore->shouldReceive('getSchema')->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []])->byDefault();
        $this->graphStore->shouldReceive('query')->andReturn([])->byDefault();
        $this->selector->shouldReceive('selectRelevantContext')->once()->andReturn(new SemanticSelectionResult());

        $ctx = $this->retriever(new QueryUnderstandingResult(entityTypes: ['BoardMember'], questionType: 'conversational'))
            ->retrieveContext('Hi! Who is on our board?');

        $this->assertFalse($ctx->qu->isConversational());
    }
}
