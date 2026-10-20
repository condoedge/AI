<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

use Condoedge\Ai\Contracts\PipelineObserverInterface;

/**
 * Fans every pipeline event out to several observers (e.g. the persisted turn
 * trace AND a caller-supplied recorder). Each observer is individually guarded,
 * so one failing observer never starves the others.
 */
final class CompositePipelineObserver implements PipelineObserverInterface
{
    /** @var array<int, PipelineObserverInterface> */
    private array $observers;

    public function __construct(PipelineObserverInterface ...$observers)
    {
        $this->observers = array_map(
            static fn (PipelineObserverInterface $o): PipelineObserverInterface => GuardedPipelineObserver::wrap($o),
            $observers
        );
    }

    /**
     * One observer for the given (nullable) list: null when empty, the observer
     * itself when single, a composite otherwise.
     */
    public static function of(?PipelineObserverInterface ...$observers): ?PipelineObserverInterface
    {
        $observers = array_values(array_filter($observers, static fn ($o) => $o !== null));

        return match (count($observers)) {
            0 => null,
            1 => $observers[0],
            default => new self(...$observers),
        };
    }

    public function stageStarted(string $stage, array $data = []): void
    {
        foreach ($this->observers as $observer) {
            $observer->stageStarted($stage, $data);
        }
    }

    public function stageFinished(string $stage, array $data = [], float $ms = 0.0): void
    {
        foreach ($this->observers as $observer) {
            $observer->stageFinished($stage, $data, $ms);
        }
    }

    public function promptBuilt(string $stage, array $messages): void
    {
        foreach ($this->observers as $observer) {
            $observer->promptBuilt($stage, $messages);
        }
    }

    public function stageError(string $stage, \Throwable $e): void
    {
        foreach ($this->observers as $observer) {
            $observer->stageError($stage, $e);
        }
    }

    public function usage(string $stage, array $usage): void
    {
        foreach ($this->observers as $observer) {
            $observer->usage($stage, $usage);
        }
    }
}
