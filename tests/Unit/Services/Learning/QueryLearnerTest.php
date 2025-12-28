<?php
// tests/Unit/Services/Learning/QueryLearnerTest.php

namespace Condoedge\Ai\Tests\Unit\Services\Learning;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\Learning\QueryLearner;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class QueryLearnerTest extends TestCase
{
    /** @test */
    public function it_adds_learned_query_to_vector_store(): void
    {
        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $embeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        $embeddingProvider->shouldReceive('embed')
            ->with('How many customers?')
            ->andReturn([0.1, 0.2, 0.3]);

        $vectorStore->shouldReceive('upsert')
            ->once()
            ->with('learned_queries', Mockery::on(function ($data) {
                return isset($data[0]['payload']['question'])
                    && $data[0]['payload']['question'] === 'How many customers?';
            }));

        $learner = new QueryLearner($vectorStore, $embeddingProvider);
        $learner->addLearnedQuery('How many customers?', 'MATCH (n:Customer) RETURN count(n)');
    }

    /** @test */
    public function it_finds_similar_learned_query(): void
    {
        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $embeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        $embeddingProvider->shouldReceive('embed')
            ->andReturn([0.1, 0.2, 0.3]);

        $vectorStore->shouldReceive('search')
            ->andReturn([
                [
                    'payload' => [
                        'question' => 'How many customers?',
                        'cypher_query' => 'MATCH (n:Customer) RETURN count(n)',
                    ],
                    'score' => 0.92,
                ],
            ]);

        $learner = new QueryLearner($vectorStore, $embeddingProvider);
        $result = $learner->findSimilarLearnedQuery('Count all customers');

        $this->assertNotNull($result);
        $this->assertEquals('How many customers?', $result['question']);
        $this->assertEquals(0.92, $result['score']);
    }

    /** @test */
    public function it_returns_null_when_no_similar_query_found(): void
    {
        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $embeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        $embeddingProvider->shouldReceive('embed')
            ->andReturn([0.1, 0.2, 0.3]);

        $vectorStore->shouldReceive('search')
            ->andReturn([]);

        $learner = new QueryLearner($vectorStore, $embeddingProvider);
        $result = $learner->findSimilarLearnedQuery('Something completely different');

        $this->assertNull($result);
    }
}
