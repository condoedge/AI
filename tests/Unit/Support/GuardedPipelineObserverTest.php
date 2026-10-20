<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Support\GuardedPipelineObserver;
use Condoedge\Ai\Support\NullPipelineObserver;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\Fixtures\ThrowingPipelineObserver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/**
 * Observer isolation: a throwing observer must never reach the pipeline.
 */
class GuardedPipelineObserverTest extends TestCase
{
    /** @test */
    public function every_method_swallows_the_inner_exception_and_logs_once(): void
    {
        Log::spy();
        $guarded = GuardedPipelineObserver::wrap(new ThrowingPipelineObserver());

        $guarded->stageStarted('a');
        $guarded->stageFinished('a', [], 1.0);
        $guarded->promptBuilt('a', [['role' => 'user', 'content' => 'x']]);
        $guarded->stageError('a', new \RuntimeException('e'));
        $guarded->usage('a', ['input' => 1, 'output' => 1, 'total' => 2, 'model' => 'm']);

        Log::shouldHaveReceived('debug')->once();
        $this->assertTrue(true);
    }

    /** @test */
    public function events_still_reach_a_well_behaved_inner_observer(): void
    {
        $inner = new RecordingPipelineObserver();
        $guarded = GuardedPipelineObserver::wrap($inner);

        $guarded->stageFinished('understanding', ['x' => 1], 2.0);

        $this->assertSame(['understanding'], $inner->finishedStages());
    }

    /** @test */
    public function the_null_observer_is_left_unwrapped_and_wrapping_is_idempotent(): void
    {
        $null = new NullPipelineObserver();
        $this->assertSame($null, GuardedPipelineObserver::wrap($null));

        $once = GuardedPipelineObserver::wrap(new RecordingPipelineObserver());
        $this->assertSame($once, GuardedPipelineObserver::wrap($once));
    }

    /** @test */
    public function the_ambient_scope_and_context_resolution_are_guarded(): void
    {
        $throwing = new ThrowingPipelineObserver();

        PipelineObserverScope::run($throwing, function () {
            $this->assertInstanceOf(GuardedPipelineObserver::class, PipelineObserverScope::current());
            PipelineObserverScope::current()->usage('x', []);
        });

        $ctx = new \Condoedge\Ai\DTOs\PipelineContext();
        $ctx->observer = $throwing;
        PipelineObserverScope::for($ctx)->promptBuilt('x', []);

        $this->assertInstanceOf(PipelineObserverInterface::class, PipelineObserverScope::for($ctx));
    }
}
