<?php

declare(strict_types=1);

namespace Condoedge\Ai\Jobs;

use Condoedge\Ai\Services\Files\DocumentMapBuilder;
use Condoedge\Ai\Support\AiFeature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Build Document Map Job
 *
 * Builds the document map of ONE file (a verified gist per passage, and the scope
 * of the document's parts) once its chunks are stored. Dispatched by
 * FileProcessor after a successful ingest when the queue is not `sync`: about
 * 2.4 s per passage (measured, small model) is far too long for an upload.
 *
 * IN SLICES. A queue hands a job out again when it has not finished within its
 * `retry_after` (90 s on Laravel's database and redis connections), so a job
 * that maps a whole file — five minutes for 128 passages — runs twice at once on
 * two workers and is then failed while still running. This job does ONE slice of
 * SLICE_SECONDS, well inside that window, and dispatches the next slice itself.
 * The builder records every passage it attempts and skips what carries a fresh
 * record, so a slice costs only what is left; a slice killed mid-way loses at
 * most the passage it was on.
 *
 * ONE AT A TIME per file: the builder works under a cache lock keyed by the file.
 * A slice that finds the file taken is released back to the queue and tried
 * again later; it never runs beside the other.
 *
 * WHAT FAILED IS RETRIED, BOUNDED. A slice that leaves passages pending, a scope
 * verdict owed or calls that failed (a provider outage) is followed by a retry
 * after a growing delay, at most MAX_RETRIES times; the last one logs an error
 * with what is left. `php artisan ai:documents:map --backfill` completes such a
 * file later (and `--retry-failed` asks again for the gists that were given up).
 *
 * A map is an annotation: nothing here may fail an ingest. The builder never
 * throws and reports instead; whatever still escapes is logged and swallowed.
 */
class BuildDocumentMapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Seconds of gist work per slice: about fifteen passages. */
    public const SLICE_SECONDS = 40;

    /** Retries after a slice in which something failed (each a job of its own). */
    public const MAX_RETRIES = 3;

    /** Seconds before the first retry; doubled for each further one (30, 60, 120). */
    public const RETRY_DELAY = 30;

    /** Seconds a slice waits before trying again when another worker holds the file. */
    public const BUSY_DELAY = 60;

    /**
     * The number of times the job may be attempted.
     *
     * A slice is attempted again only when it was released because the file was
     * busy, or when its worker died: its own retries are new jobs.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * The number of seconds the job can run before timing out.
     *
     * Under the 90 s after which a queue hands the job out again: SLICE_SECONDS of
     * gists, then room for the passage in progress and for the scope pass (one
     * summary per part and one call on the accurate model).
     *
     * @var int
     */
    public $timeout = 85;

    /**
     * The id of the file whose map is built
     *
     * @var int|string
     */
    public int|string $fileId;

    /**
     * The chunk collection the file was stored in (null: the store's bound one)
     *
     * @var string|null
     */
    public ?string $collection;

    /**
     * How many retries came before this slice (0: no slice failed so far)
     *
     * @var int
     */
    public int $attempt;

    /**
     * Seconds of gist work this slice may do (SLICE_SECONDS unless a caller sets it)
     *
     * @var int
     */
    public int $sliceSeconds = self::SLICE_SECONDS;

    /**
     * Create a new job instance.
     *
     * @param int|string $fileId The file whose map is built
     * @param string|null $collection The chunk collection it was stored in
     * @param int $attempt Retries that came before this slice
     */
    public function __construct(int|string $fileId, ?string $collection = null, int $attempt = 0)
    {
        $this->fileId = $fileId;
        $this->collection = $collection;
        $this->attempt = max(0, $attempt);
    }

    /**
     * Execute the job.
     *
     * @param DocumentMapBuilder $builder
     * @return void
     */
    public function handle(DocumentMapBuilder $builder): void
    {
        try {
            if (!AiFeature::enabled() || !config('ai.file_context.document_map.enabled', true)) {
                return; // switched off since the job was queued
            }

            $report = $builder->build($this->fileId, $this->collection, [
                'max_seconds' => $this->sliceSeconds,
                // A retry takes up again the passages whose calls failed.
                'retry_failed' => $this->attempt > 0 ? 'transient' : false,
            ]);

            if (!empty($report['busy'])) {
                // Another worker is on this file: come back when it is done.
                $this->release(self::BUSY_DELAY);

                return;
            }

            $this->continueWith($report);

            Log::info('Document map slice built via queue', [
                'file_id' => $this->fileId,
                'collection' => $this->collection,
                'attempt' => $this->attempt,
                'chunks' => $report['chunks'] ?? null,
                'mapped' => $report['mapped'] ?? null,
                'pending' => $report['pending'] ?? null,
                'gists_built' => $report['gists_built'] ?? null,
                'gists_rejected' => $report['gists_rejected'] ?? null,
                'gists_failed' => $report['gists_failed'] ?? null,
                'scope' => $report['scope'] ?? null,
                'scope_pending' => $report['scope_pending'] ?? null,
                'ms' => $report['ms'] ?? null,
                'errors' => count($report['errors'] ?? []),
            ]);
        } catch (\Throwable $e) {
            // Swallowed on purpose: the chunks are stored and searchable without
            // a map, and the command completes it later.
            $this->logFailure('Document map job failed', $e);
        }
    }

    /**
     * What follows this slice: the next one at once when it only ran out of time,
     * a retry later when something failed, nothing when the file is done.
     *
     * @param array<string, mixed> $report
     */
    private function continueWith(array $report): void
    {
        $advanced = ($report['gists_built'] ?? 0) + ($report['gists_rejected'] ?? 0) + ($report['gists_failed'] ?? 0) > 0;
        if (!empty($report['out_of_time']) && $advanced) {
            $this->dispatchSlice($this->attempt, 0);

            return;
        }

        $left = ($report['pending'] ?? 0) > 0 || !empty($report['scope_pending']) || ($report['retryable'] ?? 0) > 0;
        if ($left && $this->attempt < self::MAX_RETRIES) {
            $this->dispatchSlice($this->attempt + 1, self::RETRY_DELAY * (2 ** $this->attempt));

            return;
        }

        // The end of the line. Say so when the file is not what a reader can use:
        // work left after the last retry, or too few passages with a gist.
        if ($left || (($report['chunks'] ?? 0) > 0 && empty($report['usable']))) {
            Log::error('Document map left incomplete', [
                'file_id' => $this->fileId,
                'collection' => $this->collection,
                'retries' => $this->attempt,
                'chunks' => $report['chunks'] ?? null,
                'mapped' => $report['mapped'] ?? null,
                'pending' => $report['pending'] ?? null,
                'usable' => $report['usable'] ?? null,
                'scope_pending' => $report['scope_pending'] ?? null,
                'errors' => array_slice($report['errors'] ?? [], 0, 5),
            ]);
        }
    }

    private function dispatchSlice(int $attempt, int $delay): void
    {
        $next = new self($this->fileId, $this->collection, $attempt);
        $next->sliceSeconds = $this->sliceSeconds;
        if ($this->connection !== null) {
            $next->onConnection($this->connection);
        }
        if ($this->queue !== null) {
            $next->onQueue($this->queue);
        }
        if ($delay > 0) {
            $next->delay($delay);
        }

        app(Dispatcher::class)->dispatch($next);
    }

    /**
     * Handle a job failure (a timeout or a file that stayed busy on the last
     * attempt: nothing else is thrown).
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        $this->logFailure('Document map job failed permanently', $exception);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'ai-document-map',
            'file:' . $this->fileId,
        ];
    }

    private function logFailure(string $message, \Throwable $e): void
    {
        try {
            Log::warning($message, [
                'file_id' => $this->fileId,
                'collection' => $this->collection,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // Logging is best effort.
        }
    }
}
