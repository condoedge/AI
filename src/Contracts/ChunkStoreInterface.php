<?php

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\DTOs\FileChunk;

/**
 * Interface for storing and retrieving file chunks with embeddings
 *
 * Implementations of this interface manage the storage of file chunks
 * in a vector database for semantic search.
 */
interface ChunkStoreInterface
{
    /**
     * Store a single file chunk
     *
     * @param FileChunk $chunk The chunk to store
     * @return bool True if successful
     */
    public function storeChunk(FileChunk $chunk): bool;

    /**
     * Store multiple file chunks in batch
     *
     * @param array $chunks Array of FileChunk objects
     * @return bool True if successful
     */
    public function storeChunks(array $chunks): bool;

    /**
     * Search for chunks by content similarity
     *
     * @param string $query The search query
     * @param int $limit Maximum number of results
     * @param array $filters Optional filters:
     *   - file_id: Filter by specific file ID
     *   - file_types: Array of file extensions to include
     *   - min_score: Minimum similarity score (0.0 to 1.0)
     * @return array Array of search results with 'chunk' and 'score' keys
     */
    public function searchByContent(string $query, int $limit = 10, array $filters = []): array;

    /**
     * Get all chunks for a specific file
     *
     * @param int $fileId The file ID
     * @return array Array of FileChunk objects
     */
    public function getFileChunks(int $fileId): array;

    /**
     * Delete all chunks for a specific file
     *
     * @param int $fileId The file ID
     * @return bool True if successful
     */
    public function deleteFileChunks(int $fileId): bool;

    /**
     * Get the total number of chunks stored
     *
     * @param array $filters Optional filters (same as searchByContent)
     * @return int
     */
    public function getChunkCount(array $filters = []): int;

    /**
     * Check if chunks exist for a specific file
     *
     * @param int $fileId The file ID
     * @return bool
     */
    public function hasFileChunks(int $fileId): bool;

    /**
     * Get a specific chunk by its vector ID
     *
     * @param string $vectorId The vector ID (e.g., "file_123_chunk_0")
     * @return FileChunk|null
     */
    public function getChunk(string $vectorId): ?FileChunk;
}
