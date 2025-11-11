<?php

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\DTOs\ProcessingResult;

/**
 * Interface for file processing services
 *
 * Implementations of this interface coordinate the extraction, chunking,
 * embedding, and storage of file content for semantic search.
 */
interface FileProcessorInterface
{
    /**
     * Process a file: extract text, chunk, embed, and store
     *
     * @param object $file File model instance (must have id, name, path properties)
     * @param array $options Processing options:
     *   - chunk_size: Override default chunk size
     *   - overlap: Override default overlap
     *   - force: Reprocess even if already processed
     *   - async: Queue for background processing (default: false)
     * @return ProcessingResult
     */
    public function processFile(object $file, array $options = []): ProcessingResult;

    /**
     * Reprocess a file (delete existing chunks and reprocess)
     *
     * @param object $file File model instance
     * @return ProcessingResult
     */
    public function reprocessFile(object $file): ProcessingResult;

    /**
     * Remove all chunks for a file from storage
     *
     * @param object $file File model instance
     * @return bool True if successful
     */
    public function removeFile(object $file): bool;

    /**
     * Check if a file has been processed
     *
     * @param object $file File model instance
     * @return bool
     */
    public function isProcessed(object $file): bool;

    /**
     * Get processing statistics for a file
     *
     * @param object $file File model instance
     * @return array Statistics (chunk_count, total_size, etc.)
     */
    public function getFileStats(object $file): array;

    /**
     * Check if a file type is supported for processing
     *
     * @param string $extension File extension
     * @return bool
     */
    public function supportsFileType(string $extension): bool;

    /**
     * Get all supported file types
     *
     * @return array
     */
    public function getSupportedFileTypes(): array;
}
