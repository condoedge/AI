<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Services\Retrieval\NullReranker;
use PHPUnit\Framework\TestCase;

/**
 * NullReranker is the identity reranker: it preserves input order and only
 * truncates to $limit. It is the safe default when reranking is disabled or no
 * concrete reranker is bound.
 */
class NullRerankerTest extends TestCase
{
    private function textOf(): callable
    {
        return fn ($c) => $c['text'] ?? '';
    }

    /** @test */
    public function it_preserves_input_order(): void
    {
        $reranker = new NullReranker();

        $candidates = [
            ['id' => 'a', 'text' => 'first'],
            ['id' => 'b', 'text' => 'second'],
            ['id' => 'c', 'text' => 'third'],
        ];

        $result = $reranker->rerank('query', $candidates, $this->textOf(), 10);

        $this->assertSame(['a', 'b', 'c'], array_column($result, 'id'));
    }

    /** @test */
    public function it_truncates_to_limit(): void
    {
        $reranker = new NullReranker();

        $candidates = [
            ['id' => 'a', 'text' => 'first'],
            ['id' => 'b', 'text' => 'second'],
            ['id' => 'c', 'text' => 'third'],
        ];

        $result = $reranker->rerank('query', $candidates, $this->textOf(), 2);

        $this->assertCount(2, $result);
        $this->assertSame(['a', 'b'], array_column($result, 'id'));
    }

    /** @test */
    public function it_reindexes_to_a_clean_zero_based_list(): void
    {
        $reranker = new NullReranker();

        // Sparse / non-sequential keys must be re-indexed.
        $candidates = [
            5 => ['id' => 'a', 'text' => 'first'],
            9 => ['id' => 'b', 'text' => 'second'],
        ];

        $result = $reranker->rerank('query', $candidates, $this->textOf(), 10);

        $this->assertSame([0, 1], array_keys($result));
        $this->assertSame(['a', 'b'], array_column($result, 'id'));
    }

    /** @test */
    public function it_handles_empty_candidates(): void
    {
        $reranker = new NullReranker();

        $result = $reranker->rerank('query', [], $this->textOf(), 5);

        $this->assertSame([], $result);
    }

    /** @test */
    public function it_handles_zero_limit(): void
    {
        $reranker = new NullReranker();

        $candidates = [['id' => 'a', 'text' => 'first']];

        $result = $reranker->rerank('query', $candidates, $this->textOf(), 0);

        $this->assertSame([], $result);
    }
}
