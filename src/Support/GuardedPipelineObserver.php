<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

use Condoedge\Ai\Contracts\PipelineObserverInterface;

/**
 * Isolation decorator: an observer can NEVER break or change a turn.
 *
 * Every event is forwarded inside try/catch; the first failure is reported
 * once at debug level and every failure is swallowed. The pipeline obtains
 * its observer through wrap() (AiManager::answerQuestion, the chat use case,
 * ContextRetriever, QueryUnderstandingService, PipelineObserverScope), so a
 * host observer is never called unguarded. The null observer is left as-is.
 */
final class GuardedPipelineObserver implements PipelineObserverInterface
{
    private bool $reported = false;

    private function __construct(private readonly PipelineObserverInterface $inner)
    {
    }

    public static function wrap(PipelineObserverInterface $observer): PipelineObserverInterface
    {
        if ($observer instanceof self || $observer instanceof NullPipelineObserver) {
            return $observer;
        }

        return new self($observer);
    }

    public function inner(): PipelineObserverInterface
    {
        return $this->inner;
    }

    public function stageStarted(string $stage, array $data = []): void
    {
        try {
            $this->inner->stageStarted($stage, $data);
        } catch (\Throwable $e) {
            $this->report('stageStarted', $e);
        }
    }

    public function stageFinished(string $stage, array $data = [], float $ms = 0.0): void
    {
        try {
            $this->inner->stageFinished($stage, $data, $ms);
        } catch (\Throwable $e) {
            $this->report('stageFinished', $e);
        }
    }

    public function promptBuilt(string $stage, array $messages): void
    {
        try {
            $this->inner->promptBuilt($stage, $messages);
        } catch (\Throwable $e) {
            $this->report('promptBuilt', $e);
        }
    }

    public function stageError(string $stage, \Throwable $e): void
    {
        try {
            $this->inner->stageError($stage, $e);
        } catch (\Throwable $inner) {
            $this->report('stageError', $inner);
        }
    }

    public function usage(string $stage, array $usage): void
    {
        try {
            $this->inner->usage($stage, $usage);
        } catch (\Throwable $e) {
            $this->report('usage', $e);
        }
    }

    private function report(string $method, \Throwable $e): void
    {
        if ($this->reported) {
            return;
        }
        $this->reported = true;

        try {
            \Illuminate\Support\Facades\Log::debug('Pipeline observer ' . get_class($this->inner) . "::{$method} threw — swallowed (further failures of this observer are not logged)", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // Logging is best effort too.
        }
    }
}
