<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Support\CompositePipelineObserver;
use Condoedge\Ai\Support\NullPipelineObserver;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\Fixtures\ThrowingPipelineObserver;
use Orchestra\Testbench\TestCase;

/**
 * Silent fallbacks (AiLogger::fallback) become observable per turn, and the
 * composite observer fans events out with per-observer isolation.
 */
class PipelineObserverScopeFallbackTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    /** @test */
    public function fallbacks_logged_inside_a_turn_are_collected_for_that_turn_only(): void
    {
        $logger = new AiLogger();

        $inside = PipelineObserverScope::run(new NullPipelineObserver(), function () use ($logger): array {
            $logger->fallback('semantic_selector', 'vector_search', 'keyword_matching', 'HTTP 408');

            return PipelineObserverScope::fallbacks();
        });

        $this->assertSame([[
            'service' => 'semantic_selector',
            'from' => 'vector_search',
            'to' => 'keyword_matching',
            'reason' => 'HTTP 408',
        ]], $inside);
        $this->assertSame([], PipelineObserverScope::fallbacks(), 'no carry-over past the turn');

        $logger->fallback('x', 'a', 'b', 'outside a turn');
        $this->assertSame([], PipelineObserverScope::fallbacks(), 'outside a turn nothing is recorded');
    }

    /** @test */
    public function the_fallback_buffer_is_capped_and_reasons_are_trimmed_and_host_masked(): void
    {
        $logger = new AiLogger();

        $fallbacks = PipelineObserverScope::run(new NullPipelineObserver(), function () use ($logger): array {
            $logger->fallback('semantic_selector', 'vector_search', 'keyword_matching',
                'cURL error 28 for https://qdrant.internal.example:6333/collections/context_index (see http://docs.x/y) at 10.0.0.12 and localhost:6333 ' . str_repeat('x', 800));
            for ($i = 0; $i < 80; $i++) {
                $logger->fallback('svc', 'a', 'b', "reason {$i}");
            }

            return PipelineObserverScope::fallbacks();
        });

        $this->assertCount(PipelineObserverScope::MAX_FALLBACKS, $fallbacks);
        $this->assertSame(50, PipelineObserverScope::MAX_FALLBACKS);
        $reason = $fallbacks[0]['reason'];
        $this->assertLessThanOrEqual(PipelineObserverScope::MAX_REASON_CHARS, mb_strlen($reason));
        $this->assertSame(500, PipelineObserverScope::MAX_REASON_CHARS);
        foreach (['qdrant.internal.example', '6333', 'docs.x', '10.0.0.12', 'localhost'] as $leak) {
            $this->assertStringNotContainsString($leak, $reason);
        }
        $this->assertStringContainsString('cURL error 28 for [url]', $reason);
        $this->assertStringContainsString('at [host] and [host]', $reason);
    }

    /** @test */
    public function the_composite_observer_fans_out_and_isolates_a_throwing_observer(): void
    {
        $recorder = new RecordingPipelineObserver();
        $composite = CompositePipelineObserver::of(new ThrowingPipelineObserver(), null, $recorder);

        $composite->stageStarted('understanding');
        $composite->stageFinished('understanding', ['a' => 1], 2.0);
        $composite->stageError('understanding', new \RuntimeException('x'));

        $this->assertSame(['understanding'], $recorder->finishedStages());
        $this->assertCount(1, $recorder->errors());
        $this->assertNull(CompositePipelineObserver::of(null, null));
        $this->assertSame($recorder, CompositePipelineObserver::of($recorder, null));
    }
}
