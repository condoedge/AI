<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

use Condoedge\Ai\Contracts\PipelineObserverInterface;

/**
 * Records every pipeline event in order (tests, eval harness).
 *
 * toArray() returns the full ordered event log plus convenience projections
 * (finished stage sequence, prompts per stage, errors, usage).
 */
class RecordingPipelineObserver implements PipelineObserverInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    public function stageStarted(string $stage, array $data = []): void
    {
        $this->events[] = ['event' => 'stage_started', 'stage' => $stage, 'data' => $data];
    }

    public function stageFinished(string $stage, array $data = [], float $ms = 0.0): void
    {
        $this->events[] = ['event' => 'stage_finished', 'stage' => $stage, 'data' => $data, 'ms' => $ms];
    }

    public function promptBuilt(string $stage, array $messages): void
    {
        $this->events[] = ['event' => 'prompt', 'stage' => $stage, 'messages' => $messages];
    }

    public function stageError(string $stage, \Throwable $e): void
    {
        $this->events[] = [
            'event' => 'error',
            'stage' => $stage,
            'class' => get_class($e),
            'message' => $e->getMessage(),
        ];
    }

    public function usage(string $stage, array $usage): void
    {
        $this->events[] = ['event' => 'usage', 'stage' => $stage, 'usage' => $usage];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Stage names in the order they STARTED.
     *
     * @return array<int, string>
     */
    public function startedStages(): array
    {
        return $this->stagesOf('stage_started');
    }

    /**
     * Stage names in the order they FINISHED.
     *
     * @return array<int, string>
     */
    public function finishedStages(): array
    {
        return $this->stagesOf('stage_finished');
    }

    /**
     * The data of the LAST finished occurrence of a stage (null when it never finished).
     */
    public function stageData(string $stage): ?array
    {
        $found = null;
        foreach ($this->events as $event) {
            if ($event['event'] === 'stage_finished' && $event['stage'] === $stage) {
                $found = $event['data'];
            }
        }

        return $found;
    }

    /**
     * Every message list a stage sent to an LLM, in call order.
     *
     * @return array<int, array>
     */
    public function prompts(string $stage): array
    {
        $prompts = [];
        foreach ($this->events as $event) {
            if ($event['event'] === 'prompt' && $event['stage'] === $stage) {
                $prompts[] = $event['messages'];
            }
        }

        return $prompts;
    }

    /**
     * @return array<int, array{stage: string, class: string, message: string}>
     */
    public function errors(): array
    {
        $errors = [];
        foreach ($this->events as $event) {
            if ($event['event'] === 'error') {
                $errors[] = ['stage' => $event['stage'], 'class' => $event['class'], 'message' => $event['message']];
            }
        }

        return $errors;
    }

    /**
     * @return array<int, array{stage: string, usage: array}>
     */
    public function usages(): array
    {
        $usages = [];
        foreach ($this->events as $event) {
            if ($event['event'] === 'usage') {
                $usages[] = ['stage' => $event['stage'], 'usage' => $event['usage']];
            }
        }

        return $usages;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $prompts = [];
        foreach ($this->events as $event) {
            if ($event['event'] === 'prompt') {
                $prompts[$event['stage']][] = $event['messages'];
            }
        }

        return [
            'stages' => $this->finishedStages(),
            'events' => $this->events,
            'prompts' => $prompts,
            'errors' => $this->errors(),
            'usage' => $this->usages(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stagesOf(string $type): array
    {
        $stages = [];
        foreach ($this->events as $event) {
            if ($event['event'] === $type) {
                $stages[] = $event['stage'];
            }
        }

        return $stages;
    }
}
