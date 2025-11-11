<?php

namespace Condoedge\Ai\DTOs;

/**
 * Data Transfer Object representing a chunk of a processed file
 *
 * This DTO encapsulates all information about a single chunk of file content,
 * including its position, content, embedding, and metadata.
 */
class FileChunk
{
    /**
     * Create a new FileChunk instance
     *
     * @param int $fileId The ID of the source file
     * @param string $fileName The name of the source file
     * @param string $content The text content of this chunk
     * @param array $embedding The vector embedding of this chunk
     * @param int $chunkIndex The index of this chunk (0-based)
     * @param int $totalChunks The total number of chunks for this file
     * @param int $startPosition Character position where this chunk starts in original file
     * @param int $endPosition Character position where this chunk ends in original file
     * @param array $metadata Additional metadata (page numbers, section headers, etc.)
     */
    public function __construct(
        public readonly int $fileId,
        public readonly string $fileName,
        public readonly string $content,
        public readonly array $embedding,
        public readonly int $chunkIndex,
        public readonly int $totalChunks,
        public readonly int $startPosition,
        public readonly int $endPosition,
        public readonly array $metadata = []
    ) {}

    /**
     * Create a FileChunk from an array
     *
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            fileId: $data['file_id'],
            fileName: $data['file_name'],
            content: $data['content'],
            embedding: $data['embedding'],
            chunkIndex: $data['chunk_index'],
            totalChunks: $data['total_chunks'],
            startPosition: $data['start_position'],
            endPosition: $data['end_position'],
            metadata: $data['metadata'] ?? []
        );
    }

    /**
     * Convert to array representation
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'file_id' => $this->fileId,
            'file_name' => $this->fileName,
            'content' => $this->content,
            'embedding' => $this->embedding,
            'chunk_index' => $this->chunkIndex,
            'total_chunks' => $this->totalChunks,
            'start_position' => $this->startPosition,
            'end_position' => $this->endPosition,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get the unique identifier for this chunk in the vector store
     *
     * @return string
     */
    public function getVectorId(): string
    {
        return "file_{$this->fileId}_chunk_{$this->chunkIndex}";
    }

    /**
     * Get the content length
     *
     * @return int
     */
    public function getContentLength(): int
    {
        return strlen($this->content);
    }

    /**
     * Check if this is the first chunk
     *
     * @return bool
     */
    public function isFirstChunk(): bool
    {
        return $this->chunkIndex === 0;
    }

    /**
     * Check if this is the last chunk
     *
     * @return bool
     */
    public function isLastChunk(): bool
    {
        return $this->chunkIndex === ($this->totalChunks - 1);
    }
}
