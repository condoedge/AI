<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\Retrieval\SimilarQuerySearcher;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class SimilarQuerySearcherTest extends TestCase
{
    private $vectorStore;
    private $embeddingProvider;
    private SimilarQuerySearcher $searcher;

    public function setUp(): void
    {
        parent::setUp();

        $this->vectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->vectorStore->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $this->embeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);
        $this->searcher = new SimilarQuerySearcher(
            $this->vectorStore,
            $this->embeddingProvider,
            new AiLogger()
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_passes_collection_limit_and_threshold_to_the_store(): void
    {
        $this->embeddingProvider->shouldReceive('embed')->once()->with('q')->andReturn([0.1, 0.2]);
        $this->vectorStore->shouldReceive('search')
            ->once()
            ->with('my_collection', [0.1, 0.2], 7, [], 0.5)
            ->andReturn([]);

        $this->searcher->search('q', 'my_collection', 7, 0.5);

        $this->assertTrue(true);
    }

    /** @test */
    public function it_formats_results_with_question_query_and_score(): void
    {
        $this->embeddingProvider->shouldReceive('embed')->andReturn([0.1]);
        $this->vectorStore->shouldReceive('search')->andReturn([
            ['score' => 0.9, 'payload' => ['question' => 'List teams', 'cypher_query' => 'MATCH (t:Team) RETURN t']],
            ['score' => 0.7, 'payload' => ['question' => 'Show customers', 'query' => 'MATCH (c:Customer) RETURN c']],
        ]);

        $results = $this->searcher->search('q', 'questions', 5, 0.0);

        $this->assertCount(2, $results);
        $this->assertSame('List teams', $results[0]['question']);
        $this->assertSame('MATCH (t:Team) RETURN t', $results[0]['query']);
        $this->assertSame(0.9, $results[0]['score']);
        $this->assertSame('MATCH (c:Customer) RETURN c', $results[1]['query']);
    }

    /** @test */
    public function it_skips_entries_missing_question_or_query(): void
    {
        $this->embeddingProvider->shouldReceive('embed')->andReturn([0.1]);
        $this->vectorStore->shouldReceive('search')->andReturn([
            ['score' => 0.9, 'payload' => ['question' => 'Has both', 'cypher_query' => 'MATCH (n) RETURN n']],
            ['score' => 0.8, 'payload' => ['question' => 'No query']],
            ['score' => 0.8, 'payload' => ['cypher_query' => 'MATCH (n) RETURN n']],
        ]);

        $results = $this->searcher->search('q', 'questions', 5, 0.0);

        $this->assertCount(1, $results);
        $this->assertSame('Has both', $results[0]['question']);
    }

    /** @test */
    public function it_throws_when_embedding_fails(): void
    {
        $this->embeddingProvider->shouldReceive('embed')->andThrow(new \Exception('API error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to generate embedding for question');

        $this->searcher->search('q', 'questions', 5, 0.0);
    }

    /** @test */
    public function it_throws_when_embedding_is_empty(): void
    {
        $this->embeddingProvider->shouldReceive('embed')->andReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Embedding provider returned empty vector');

        $this->searcher->search('q', 'questions', 5, 0.0);
    }

    /** @test */
    public function it_throws_when_vector_store_fails(): void
    {
        $this->embeddingProvider->shouldReceive('embed')->andReturn([0.1]);
        $this->vectorStore->shouldReceive('search')->andThrow(new \Exception('timeout'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vector store search failed');

        $this->searcher->search('q', 'questions', 5, 0.0);
    }

    /**
     * A missing few-shot collection is the normal state before any Q->Cypher pair
     * has been stored. It must degrade to "no examples" silently rather than raise
     * a vector-search error that inflates errors_count and degrades context quality.
     *
     * @test
     */
    public function it_returns_empty_when_collection_absent_without_searching(): void
    {
        $this->vectorStore->shouldReceive('collectionExists')->with('questions')->andReturn(false);
        $this->vectorStore->shouldNotReceive('search');
        $this->embeddingProvider->shouldReceive('embed')->andReturn([0.1]);

        $this->assertSame([], $this->searcher->search('q', 'questions', 5, 0.0));
    }
}
