<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

use Condoedge\Ai\Contracts\PipelineObserverInterface;

/**
 * The default observer: ignores every event (zero cost).
 */
final class NullPipelineObserver implements PipelineObserverInterface
{
    public function stageStarted(string $stage, array $data = []): void
    {
    }

    public function stageFinished(string $stage, array $data = [], float $ms = 0.0): void
    {
    }

    public function promptBuilt(string $stage, array $messages): void
    {
    }

    public function stageError(string $stage, \Throwable $e): void
    {
    }

    public function usage(string $stage, array $usage): void
    {
    }
}
