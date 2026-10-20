<?php

namespace Condoedge\Ai\Models\Plugins;

use Condoedge\Utils\Models\Plugins\ModelPlugin;
use Condoedge\Ai\Contracts\ChunkLinkStoreInterface;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileOwnerResolverInterface;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Files\RecordAncestry;
use Condoedge\Ai\Jobs\ProcessFileJob;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * File Processing Plugin
 *
 * This plugin coordinates dual-storage for File models:
 * - Neo4j: File metadata and relationships (via AI facade)
 * - Qdrant: File content chunks for semantic search (via FileProcessor)
 *
 * Auto-processes files on creation/update and cleans up on deletion.
 */
class FileProcessingPlugin extends ModelPlugin
{
    private const CREATED = 'created';
    private const REPLACED = 'replaced';
    private const RELINKED = 'relinked';

    /** @var \WeakMap<object, bool>|null model instances whose create was queued */
    private static ?\WeakMap $createdInstances = null;

    /**
     * Boot the plugin and register event listeners
     *
     * @return void
     */
    public function onBoot(): void
    {
        $modelClass = $this->modelClass;

        // All the file indexing happens in background since sometimes we run ocr and parsing that can take too much

        // Create and update both flow through a single 'saved' listener so one
        // save dispatches exactly one processing path.
        //
        // WHAT HAPPENED IS DECIDED HERE, while the saved model is in hand, and
        // travels with the job as a plain string. A queued closure does not carry
        // the model: it carries its identifier and the worker fetches the row
        // again — never "recently created", never dirty. Decided inside the job
        // (as it was), neither branch ran on any queue but `sync` and a new upload
        // was never indexed.
        $modelClass::saved(function ($file) {
            $event = $this->savedEvent($file);
            if ($event === null) {
                return;
            }

            dispatch(function () use ($file, $event) {
                match ($event) {
                    self::CREATED => $this->handleFileCreated($file),
                    self::REPLACED => $this->handleFileUpdated($file, true),
                    self::RELINKED => $this->handleFileRelinked($file),
                };
            });
        });

        $modelClass::deleting(function ($file) {
            dispatch(function () use ($file) {
                 $this->handleFileDeleting($file);
            });
        });
    }

    /**
     * What a save did to the file, read from the model that was just saved:
     * created; its stored object replaced (the path changed); moved to another
     * record (one of the columns its owner is derived from changed) — or nothing
     * the index cares about (null: no job is queued).
     */
    protected function savedEvent($file): ?string
    {
        if ($file->wasRecentlyCreated && !$this->handledCreate($file)) {
            return self::CREATED;
        }
        if ($file->isDirty('path') || $file->wasChanged('path')) {
            return self::REPLACED;
        }

        $columns = $this->linkColumns($file);
        if ($columns !== [] && ($file->isDirty($columns) || $file->wasChanged($columns))) {
            return self::RELINKED;
        }

        return null;
    }

    /**
     * Whether the create of this model instance was already queued: an instance
     * stays "recently created" for its whole life, and a second save of it is an
     * update.
     */
    private function handledCreate($file): bool
    {
        // Static: the plugin itself is bound into the queued closure and must stay
        // serializable; a WeakMap forgets an instance with it.
        self::$createdInstances ??= new \WeakMap();
        if (isset(self::$createdInstances[$file])) {
            return true;
        }
        self::$createdInstances[$file] = true;

        return false;
    }

    /**
     * The columns the file's owner is derived from: the bound resolver's own
     * answer when it gives one (`linkColumns()`), else the foreign keys the File
     * entity declares ({@see RecordAncestry::linkColumns()}).
     *
     * @return list<string>
     */
    protected function linkColumns($file): array
    {
        try {
            $resolver = app(FileOwnerResolverInterface::class);
            if (method_exists($resolver, 'linkColumns')) {
                return array_values(array_filter((array) $resolver->linkColumns($file), 'is_string'));
            }

            $label = EntityLabelIndex::shared()->labelForClass($file::class);

            return $label === null ? [] : app(RecordAncestry::class)->linkColumns($label);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The file was moved to another record: its stored chunks get the new owner
     * keys — a payload-only write, nothing is extracted or embedded again.
     */
    protected function handleFileRelinked($file): void
    {
        try {
            $store = app(ChunkStoreInterface::class);
            if (!$store instanceof ChunkLinkStoreInterface || !$this->isProcessed($file)) {
                return;
            }

            $store->linkFileChunks($file->id, app(FileOwnerResolverInterface::class)->ownerOf($file));
        } catch (\Throwable $e) {
            $this->handleError('relink', $file, $e);
        }
    }

    /**
     * Handle file creation
     *
     * @param object $file
     * @return void
     */
    protected function handleFileCreated($file): void
    {
        try {
            $this->syncToNeo4j($file, 'create');

            if ($this->shouldProcessContent($file)) {
                $this->processFileContent($file);
            }
        } catch (\Throwable $e) {
            $this->handleError('create', $file, $e);
        }
    }

    /**
     * Handle file update
     *
     * @param object $file
     * @return void
     */
    protected function handleFileUpdated($file, bool $replaced = false): void
    {
        try {
            $this->syncToNeo4j($file, 'update');

            // Reprocess content only when the path changed (file replaced) —
            // known from the save itself ($replaced): a model the worker fetched
            // again is never dirty.
            if (($replaced || $file->isDirty('path')) && $this->shouldProcessContent($file)) {
                $this->reprocessFileContent($file);
            }
        } catch (\Throwable $e) {
            $this->handleError('update', $file, $e);
        }
    }

    /**
     * Handle file deletion
     *
     * @param object $file
     * @return void
     */
    protected function handleFileDeleting($file): void
    {
        try {
            if ($this->isProcessed($file)) {
                $this->removeFileContent($file);
            }

            $this->removeFromNeo4j($file);
        } catch (\Throwable $e) {
            $this->handleError('delete', $file, $e);
        }
    }

    /**
     * Sync file metadata to Neo4j
     *
     * Only syncs if file implements Nodeable interface.
     * Files from external packages that don't implement Nodeable
     * will skip Neo4j and still get Qdrant content storage.
     *
     * @param object $file
     * @param string $operation 'create' or 'update'
     * @return void
     */
    protected function syncToNeo4j($file, string $operation): void
    {
        // Skip Neo4j for non-Nodeable files (they still get Qdrant storage)
        if (!$this->isNodeable($file)) {
            Log::debug('Skipping Neo4j for non-Nodeable file (Qdrant content storage still works)', [
                'file_id' => $file->id,
                'file_class' => get_class($file),
            ]);
            return;
        }

        if ($operation === 'create') {
            AI::ingest($file);
        } else {
            AI::sync($file);
        }
    }

    /**
     * Remove file from Neo4j
     *
     * Only removes if file implements Nodeable interface.
     *
     * @param object $file
     * @return void
     */
    protected function removeFromNeo4j($file): void
    {
        if (!$this->isNodeable($file)) {
            Log::debug('Skipping Neo4j removal for non-Nodeable file', [
                'file_id' => $file->id,
            ]);
            return;
        }

        AI::remove($file);
    }

    /**
     * Process file content for Qdrant
     *
     * @param object $file
     * @return void
     */
    protected function processFileContent($file): void
    {
        $processor = app(FileProcessorInterface::class);

        if ($this->shouldQueueProcessing($file)) {
            $this->queueFileProcessing($file);
            return;
        }

        $result = $processor->processFile((object) $file->getAttributes());

        if ($result->failed()) {
            Log::warning("File content processing failed", [
                'file_id' => $file->id,
                'file_name' => $file->name,
                'error' => $result->error,
            ]);
        }
    }

    /**
     * Reprocess file content (removes old chunks and processes again)
     *
     * @param object $file
     * @return void
     */
    protected function reprocessFileContent($file): void
    {
        $processor = app(FileProcessorInterface::class);

        if ($this->shouldQueueProcessing($file)) {
            $this->queueFileProcessing($file, true);
            return;
        }

        $result = $processor->reprocessFile($file);

        if ($result->failed()) {
            Log::warning("File content reprocessing failed", [
                'file_id' => $file->id,
                'file_name' => $file->name,
                'error' => $result->error,
            ]);
        }
    }

    /**
     * Remove file content from Qdrant
     *
     * @param object $file
     * @return void
     */
    protected function removeFileContent($file): void
    {
        $processor = app(FileProcessorInterface::class);
        $processor->removeFile($file);
    }

    /**
     * Check if file content should be processed
     *
     * @param object $file
     * @return bool
     */
    protected function shouldProcessContent($file): bool
    {
        if (!config('ai.file_processing.enabled', true)) {
            return false;
        }

        if (!$file->path) {
            return false;
        }

        if (!method_exists($file, 'shouldProcessContent')) {
            // Fallback: check processor directly
            $processor = app(FileProcessorInterface::class);
            $extension = pathinfo($file->name, PATHINFO_EXTENSION);
            return $processor->supportsFileType($extension);
        }

        return $file->shouldProcessContent();
    }

    /**
     * Check if file is already processed
     *
     * @param object $file
     * @return bool
     */
    protected function isProcessed($file): bool
    {
        if (method_exists($file, 'isProcessed')) {
            return $file->isProcessed();
        }

        $processor = app(FileProcessorInterface::class);
        return $processor->isProcessed($file);
    }

    /**
     * Check if file processing should be queued
     *
     * @param object $file
     * @return bool
     */
    protected function shouldQueueProcessing($file): bool
    {
        if (config('ai.file_processing.queue', false)) {
            return true;
        }

        $queueThreshold = config('ai.file_processing.queue_threshold_bytes', 5 * 1024 * 1024); // 5MB default
        if ($file->size > $queueThreshold) {
            return true;
        }

        return false;
    }

    /**
     * Queue file processing job
     *
     * @param object $file
     * @param bool $reprocess
     * @return void
     */
    protected function queueFileProcessing($file, bool $reprocess = false): void
    {
        $job = new ProcessFileJob($file, $reprocess);

        $queueConnection = config('ai.file_processing.queue_connection');
        $queueName = config('ai.file_processing.queue_name', 'default');

        if ($queueConnection) {
            $job->onConnection($queueConnection);
        }

        if ($queueName !== 'default') {
            $job->onQueue($queueName);
        }

        dispatch($job);

        Log::debug("File processing queued", [
            'file_id' => $file->id,
            'file_name' => $file->name ?? 'unknown',
            'reprocess' => $reprocess,
        ]);
    }

    /**
     * Handle errors during file processing
     *
     * @param string $operation
     * @param object $file
     * @param \Throwable $e
     * @return void
     */
    protected function handleError(string $operation, $file, \Throwable $e): void
    {
        $failSilently = config('ai.file_processing.fail_silently', true);
        $logErrors = config('ai.file_processing.log_errors', true);

        if ($logErrors) {
            Log::error("File processing plugin error during {$operation}", [
                'file_id' => $file->id ?? null,
                'file_name' => $file->name ?? null,
                'operation' => $operation,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        if (!$failSilently) {
            throw $e;
        }
    }

    /**
     * Check if file implements Nodeable interface
     *
     * @param object $file
     * @return bool
     */
    protected function isNodeable($file): bool
    {
        return $file instanceof Nodeable;
    }
}
