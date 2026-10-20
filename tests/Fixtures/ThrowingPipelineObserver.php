<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Condoedge\Ai\Contracts\PipelineObserverInterface;

/**
 * A misbehaving observer that throws from every method (isolation tests).
 */
final class ThrowingPipelineObserver implements PipelineObserverInterface
{
    public function stageStarted(string $stage, array $data = []): void
    {
        throw new \LogicException("observer boom: started {$stage}");
    }

    public function stageFinished(string $stage, array $data = [], float $ms = 0.0): void
    {
        throw new \LogicException("observer boom: finished {$stage}");
    }

    public function promptBuilt(string $stage, array $messages): void
    {
        throw new \LogicException("observer boom: prompt {$stage}");
    }

    public function stageError(string $stage, \Throwable $e): void
    {
        throw new \LogicException("observer boom: error {$stage}");
    }

    public function usage(string $stage, array $usage): void
    {
        throw new \LogicException("observer boom: usage {$stage}");
    }
}
