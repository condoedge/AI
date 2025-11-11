<?php

namespace Condoedge\Ai\Contracts;

/**
 * Interface for file content chunking services
 *
 * Implementations of this interface split large text content into
 * smaller, manageable chunks for embedding and semantic search.
 */
interface FileChunkerInterface
{
    /**
     * Chunk text content into smaller pieces
     *
     * @param string $content The text content to chunk
     * @param array $options Chunking options:
     *   - chunk_size: Maximum characters per chunk (default: 1000)
     *   - overlap: Characters to overlap between chunks (default: 200)
     *   - preserve_sentences: Try to break at sentence boundaries (default: true)
     *   - preserve_paragraphs: Try to break at paragraph boundaries (default: true)
     * @return array Array of text chunks (strings)
     */
    public function chunk(string $content, array $options = []): array;

    /**
     * Get recommended chunk size for a given file type
     *
     * @param string $fileType File extension (e.g., 'pdf', 'txt', 'md')
     * @return int Recommended chunk size in characters
     */
    public function getRecommendedChunkSize(string $fileType): int;

    /**
     * Get recommended overlap size for a given file type
     *
     * @param string $fileType File extension (e.g., 'pdf', 'txt', 'md')
     * @return int Recommended overlap size in characters
     */
    public function getRecommendedOverlap(string $fileType): int;
}
