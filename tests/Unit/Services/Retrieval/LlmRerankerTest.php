<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\Retrieval\LlmReranker;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * LlmReranker scores candidates via a single chatJson call (temperature 0) and
 * re-orders them by model relevance. It MUST never throw into the pipeline and
 * MUST gracefully fall back to the INPUT order on any error / empty / garbage
 * scoring response.
 */
class LlmRerankerTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function textOf(): callable
    {
        return fn ($c) => $c['text'] ?? '';
    }

    private function candidates(): array
    {
        return [
            ['id' => 'a', 'text' => 'apples are red'],
            ['id' => 'b', 'text' => 'bananas are yellow'],
            ['id' => 'c', 'text' => 'cherries are dark red'],
        ];
    }

    /** @test */
    public function it_reorders_candidates_by_model_score(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);

        // Model says candidate index 2 (c) is most relevant, then 0 (a), then 1 (b).
        $llm->shouldReceive('chatJson')
            ->once()
            ->with(
                Mockery::type('array'),
                Mockery::on(fn ($opts) => ($opts['temperature'] ?? null) === 0.0)
            )
            ->andReturn([
                'scores' => [
                    ['index' => 0, 'score' => 0.5],
                    ['index' => 1, 'score' => 0.1],
                    ['index' => 2, 'score' => 0.9],
                ],
            ]);

        $reranker = new LlmReranker($llm);

        $result = $reranker->rerank('which fruit is red?', $this->candidates(), $this->textOf(), 3);

        $this->assertSame(['c', 'a', 'b'], array_column($result, 'id'));
    }

    /** @test */
    public function it_returns_top_limit_after_reordering(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);

        $llm->shouldReceive('chatJson')
            ->once()
            ->andReturn([
                'scores' => [
                    ['index' => 0, 'score' => 0.2],
                    ['index' => 1, 'score' => 0.95],
                    ['index' => 2, 'score' => 0.6],
                ],
            ]);

        $reranker = new LlmReranker($llm);

        // limit=2 -> top two by score: b (0.95), c (0.6)
        $result = $reranker->rerank('q', $this->candidates(), $this->textOf(), 2);

        $this->assertCount(2, $result);
        $this->assertSame(['b', 'c'], array_column($result, 'id'));
    }

    /** @test */
    public function it_falls_back_to_input_order_on_exception(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);

        $llm->shouldReceive('chatJson')
            ->once()
            ->andThrow(new \RuntimeException('LLM unavailable'));

        $reranker = new LlmReranker($llm);

        $result = $reranker->rerank('q', $this->candidates(), $this->textOf(), 3);

        // Graceful fallback: original input order, never throws.
        $this->assertSame(['a', 'b', 'c'], array_column($result, 'id'));
    }

    /** @test */
    public function it_falls_back_to_input_order_on_empty_response(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);

        $llm->shouldReceive('chatJson')
            ->once()
            ->andReturn([]); // no usable scores

        $reranker = new LlmReranker($llm);

        $result = $reranker->rerank('q', $this->candidates(), $this->textOf(), 3);

        $this->assertSame(['a', 'b', 'c'], array_column($result, 'id'));
    }

    /** @test */
    public function it_falls_back_to_input_order_on_garbage_response(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);

        // Indices are out of range / non-sensical -> nothing usable parsed.
        $llm->shouldReceive('chatJson')
            ->once()
            ->andReturn([
                'scores' => [
                    ['index' => 99, 'score' => 0.9],
                    ['nonsense' => true],
                ],
            ]);

        $reranker = new LlmReranker($llm);

        $result = $reranker->rerank('q', $this->candidates(), $this->textOf(), 3);

        $this->assertSame(['a', 'b', 'c'], array_column($result, 'id'));
    }

    /** @test */
    public function it_accepts_object_response_and_partial_scores(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);

        // Object (stdClass) response; only index 2 scored. Unscored indices are
        // treated as 0.0 and keep their relative input order after the scored one.
        $response = (object) [
            'scores' => [
                (object) ['index' => 2, 'score' => 0.8],
            ],
        ];

        $llm->shouldReceive('chatJson')
            ->once()
            ->andReturn($response);

        $reranker = new LlmReranker($llm);

        $result = $reranker->rerank('q', $this->candidates(), $this->textOf(), 3);

        // c (0.8) first, then a, b (both 0.0, input order preserved).
        $this->assertSame(['c', 'a', 'b'], array_column($result, 'id'));
    }

    /** @test */
    public function it_caps_candidates_sent_to_the_model_and_appends_overflow_in_input_order(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);

        // 5 candidates, cap of 2: only indices 0 and 1 are scored by the model.
        $candidates = [
            ['id' => 'a', 'text' => 'a'],
            ['id' => 'b', 'text' => 'b'],
            ['id' => 'c', 'text' => 'c'],
            ['id' => 'd', 'text' => 'd'],
            ['id' => 'e', 'text' => 'e'],
        ];

        $captured = null;
        $llm->shouldReceive('chatJson')
            ->once()
            ->andReturnUsing(function ($messages) use (&$captured) {
                $captured = $messages;
                // Reverse the two scored candidates.
                return ['scores' => [
                    ['index' => 0, 'score' => 0.1],
                    ['index' => 1, 'score' => 0.9],
                ]];
            });

        $reranker = new LlmReranker($llm, 2); // hard cap = 2

        $result = $reranker->rerank('q', $candidates, $this->textOf(), 10);

        // Scored block reordered (b, a) then overflow in input order (c, d, e).
        $this->assertSame(['b', 'a', 'c', 'd', 'e'], array_column($result, 'id'));

        // The prompt must only contain the 2 capped candidates, not c/d/e.
        $userContent = $captured[1]['content'] ?? '';
        $this->assertStringContainsString('[0]', $userContent);
        $this->assertStringContainsString('[1]', $userContent);
        $this->assertStringNotContainsString('[2]', $userContent);
    }

    /** @test */
    public function it_does_not_call_the_model_for_empty_candidates(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->never();

        $reranker = new LlmReranker($llm);

        $result = $reranker->rerank('q', [], $this->textOf(), 5);

        $this->assertSame([], $result);
    }
}
