<?php

namespace Condoedge\Ai\DTOs;

/**
 * Data Transfer Object representing the result of file processing
 *
 * This DTO encapsulates the outcome of processing a file, including
 * success status, metrics, and any error information.
 */
class ProcessingResult
{
    /**
     * Create a new ProcessingResult instance
     *
     * @param bool $success Whether the processing was successful
     * @param int $fileId The ID of the processed file
     * @param int $chunksCreated Number of chunks created
     * @param int $embeddingsGenerated Number of embeddings generated
     * @param float $processingTimeSeconds Time taken to process (in seconds)
     * @param string|null $error Error message if processing failed
     * @param array $metadata Additional metadata about the processing
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $fileId,
        public readonly int $chunksCreated,
        public readonly int $embeddingsGenerated,
        public readonly float $processingTimeSeconds,
        public readonly ?string $error = null,
        public readonly array $metadata = []
    ) {}

    /**
     * Create a successful processing result
     *
     * @param int $fileId
     * @param int $chunksCreated
     * @param int $embeddingsGenerated
     * @param float $processingTimeSeconds
     * @param array $metadata
     * @return self
     */
    public static function success(
        int $fileId,
        int $chunksCreated,
        int $embeddingsGenerated,
        float $processingTimeSeconds,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            fileId: $fileId,
            chunksCreated: $chunksCreated,
            embeddingsGenerated: $embeddingsGenerated,
            processingTimeSeconds: $processingTimeSeconds,
            error: null,
            metadata: $metadata
        );
    }

    /**
     * Create a failed processing result
     *
     * @param int $fileId
     * @param string $error
     * @param float $processingTimeSeconds
     * @param array $metadata
     * @return self
     */
    public static function failure(
        int $fileId,
        string $error,
        float $processingTimeSeconds = 0.0,
        array $metadata = []
    ): self {
        return new self(
            success: false,
            fileId: $fileId,
            chunksCreated: 0,
            embeddingsGenerated: 0,
            processingTimeSeconds: $processingTimeSeconds,
            error: $error,
            metadata: $metadata
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
            'success' => $this->success,
            'file_id' => $this->fileId,
            'chunks_created' => $this->chunksCreated,
            'embeddings_generated' => $this->embeddingsGenerated,
            'processing_time_seconds' => $this->processingTimeSeconds,
            'error' => $this->error,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Check if processing failed
     *
     * @return bool
     */
    public function failed(): bool
    {
        return !$this->success;
    }

    /**
     * Get the processing rate (chunks per second)
     *
     * @return float
     */
    public function getProcessingRate(): float
    {
        if ($this->processingTimeSeconds === 0.0) {
            return 0.0;
        }

        return $this->chunksCreated / $this->processingTimeSeconds;
    }

    /**
     * Get a human-readable summary
     *
     * @return string
     */
    public function getSummary(): string
    {
        if (!$this->success) {
            return "Processing failed for file {$this->fileId}: {$this->error}";
        }

        $rate = round($this->getProcessingRate(), 2);
        $time = round($this->processingTimeSeconds, 2);

        return "Successfully processed file {$this->fileId}: " .
               "{$this->chunksCreated} chunks, {$this->embeddingsGenerated} embeddings " .
               "in {$time}s ({$rate} chunks/s)";
    }
}
