<?php

namespace Condoedge\Ai\Models\Plugins;

use Condoedge\Utils\Models\Plugins\ModelPlugin;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Facades\AI;
use Illuminate\Support\Facades\Log;

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
    /**
     * Boot the plugin and register event listeners
     *
     * @return void
     */
    public function onBoot(): void
    {
        $modelClass = $this->modelClass;

        // Event 1: File Created - Sync to both Neo4j and Qdrant
        $modelClass::created(function ($file) {
            $this->handleFileCreated($file);
        });

        // Event 2: File Updated - Reprocess if path changed
        $modelClass::updated(function ($file) {
            $this->handleFileUpdated($file);
        });

        // Event 3: File Deleting - Remove from both stores
        $modelClass::deleting(function ($file) {
            $this->handleFileDeleting($file);
        });
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
            // Step 1: Sync metadata to Neo4j
            $this->syncToNeo4j($file, 'create');

            // Step 2: Process content for Qdrant (if supported file type)
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
    protected function handleFileUpdated($file): void
    {
        try {
            // Step 1: Sync updated metadata to Neo4j
            $this->syncToNeo4j($file, 'update');

            // Step 2: Reprocess content if path changed (file replaced)
            if ($file->isDirty('path') && $this->shouldProcessContent($file)) {
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
            // Step 1: Remove from Qdrant
            if ($this->isProcessed($file)) {
                $this->removeFileContent($file);
            }

            // Step 2: Remove from Neo4j
            $this->removeFromNeo4j($file);
        } catch (\Throwable $e) {
            $this->handleError('delete', $file, $e);
        }
    }

    /**
     * Sync file metadata to Neo4j
     *
     * @param object $file
     * @param string $operation 'create' or 'update'
     * @return void
     */
    protected function syncToNeo4j($file, string $operation): void
    {
        if ($operation === 'create') {
            AI::ingest($file);
        } else {
            AI::sync($file);
        }
    }

    /**
     * Remove file from Neo4j
     *
     * @param object $file
     * @return void
     */
    protected function removeFromNeo4j($file): void
    {
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

        // Check if should queue processing
        if ($this->shouldQueueProcessing($file)) {
            $this->queueFileProcessing($file);
            return;
        }

        // Process synchronously
        $result = $processor->processFile($file);

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

        // Check if should queue
        if ($this->shouldQueueProcessing($file)) {
            $this->queueFileProcessing($file, true);
            return;
        }

        // Reprocess synchronously
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
        // Check if file processing is enabled
        if (!config('ai.file_processing.enabled', true)) {
            return false;
        }

        // Check if file exists on disk
        if (!method_exists($file, 'existsOnDisk') || !$file->existsOnDisk()) {
            return false;
        }

        // Check if file type is supported
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
        // Check global config
        if (config('ai.file_processing.queue', false)) {
            return true;
        }

        // Check file size threshold
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
        // Note: Job implementation will be created in a future task
        // For now, log that it should be queued
        Log::info("File processing should be queued (not implemented yet)", [
            'file_id' => $file->id,
            'file_name' => $file->name,
            'reprocess' => $reprocess,
        ]);

        // Fallback: process synchronously for now
        $processor = app(FileProcessorInterface::class);

        if ($reprocess) {
            $processor->reprocessFile($file);
        } else {
            $processor->processFile($file);
        }
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
}
