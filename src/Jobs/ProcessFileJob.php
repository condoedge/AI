<?php

declare(strict_types=1);

namespace Condoedge\Ai\Jobs;

use Condoedge\Ai\Contracts\FileProcessorInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process File Job
 *
 * Queues the processing of a file into chunks and embeddings.
 * Triggered by FileProcessingPlugin when async processing is enabled.
 */
class ProcessFileJob implements ShouldQueue
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
    public $timeout = 300;

    /**
     * The file object to process
     *
     * @var object
     */
    protected object $file;

    /**
     * Whether to reprocess (force update)
     *
     * @var bool
     */
    protected bool $reprocess;

    /**
     * Create a new job instance.
     *
     * @param object $file The file to process
     * @param bool $reprocess Whether to force reprocessing
     */
    public function __construct(object $file, bool $reprocess = false)
    {
        $this->file = $file;
        $this->reprocess = $reprocess;
    }

    /**
     * Execute the job.
     *
     * @param FileProcessorInterface $processor
     * @return void
     */
    public function handle(FileProcessorInterface $processor): void
    {
        try {
            $result = $this->reprocess
                ? $processor->reprocessFile($this->file)
                : $processor->processFile($this->file);

            if ($result->succeeded()) {
                Log::info('File processed successfully via queue', [
                    'file_id' => $this->file->id,
                    'file_name' => $this->file->name ?? 'unknown',
                    'chunks_created' => $result->chunksCreated,
                ]);
            } else {
                Log::warning('File processing completed with issues', [
                    'file_id' => $this->file->id,
                    'file_name' => $this->file->name ?? 'unknown',
                    'error' => $result->error,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('File processing job failed', [
                'file_id' => $this->file->id,
                'file_name' => $this->file->name ?? 'unknown',
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
        Log::error('File processing job failed permanently', [
            'file_id' => $this->file->id,
            'file_name' => $this->file->name ?? 'unknown',
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
            'ai-file-processing',
            'file:' . $this->file->id,
        ];
    }
}
