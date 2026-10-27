<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Services\SemanticContextSelector;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Docs only (ai.query_generation.enabled = false): no query is ever generated, so the turn
 * reads no entity, few-shot or graph context — a host without a graph store pays nothing
 * for one. Query Understanding still runs: the document search reads it.
 */
class ContextRetrieverDocsOnlyTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function only_query_understanding_runs(): void
    {
        config(['ai.query_generation.enabled' => false]);

        $vectors = Mockery::mock(VectorStoreInterface::class);
        $graph = Mockery::mock(GraphStoreInterface::class);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $selector = Mockery::mock(SemanticContextSelector::class);
        $selector->shouldNotReceive('selectRelevantContext');
        $vectors->shouldNotReceive('search');
        $graph->shouldNotReceive('getSchema', 'query');
        $embedding->shouldNotReceive('embed');

        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTrace')->andReturn('trace')->byDefault();
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        foreach (['info', 'debug', 'error', 'warning', 'fallback'] as $level) {
            $logger->shouldReceive($level)->byDefault();
        }

        $retriever = new ContextRetriever($vectors, $graph, $embedding, $logger, []);
        $retriever->setContextSelector($selector);
        $qu = new QueryUnderstandingResult(searchQuery: 'billet gratuit', questionType: 'knowledge_query', language: 'fr');
        $service = Mockery::mock(QueryUnderstandingService::class);
        $service->shouldReceive('analyze')->once()->andReturn($qu);
        $retriever->setQueryUnderstanding($service);

        $ctx = $retriever->retrieveContext('Comment offrir un billet gratuit?');

        $this->assertSame($qu, $ctx->qu);
        $this->assertSame([], $ctx->similarQueries);
    }
}
