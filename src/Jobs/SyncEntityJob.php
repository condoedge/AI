<?php

declare(strict_types=1);

namespace AiSystem\Jobs;

use AiSystem\Domain\Contracts\Nodeable;
use AiSystem\Facades\AI;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sync Entity Job
 *
 * Queues the synchronization of a Nodeable entity in Neo4j and Qdrant.
 * Triggered automatically when a model with HasNodeableConfig trait is updated.
 */
class SyncEntityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * The entity to sync
     *
     * @var Nodeable
     */
    protected Nodeable $entity;

    /**
     * Create a new job instance.
     *
     * @param Nodeable $entity
     */
    public function __construct(Nodeable $entity)
    {
        $this->entity = $entity;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            // Sync the entity in Neo4j and Qdrant
            AI::sync($this->entity);

            Log::info('AI entity synced successfully', [
                'model' => get_class($this->entity),
                'id' => $this->entity->getNodeId(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AI entity sync failed', [
                'model' => get_class($this->entity),
                'id' => $this->entity->getNodeId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger job retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('AI entity sync job failed permanently', [
            'model' => get_class($this->entity),
            'id' => $this->entity->getNodeId(),
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'ai-sync',
            'sync',
            get_class($this->entity),
            'entity:' . $this->entity->getNodeId(),
        ];
    }
}
