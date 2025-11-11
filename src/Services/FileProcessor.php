<?php

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Contracts\FileChunkerInterface;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\DTOs\ProcessingResult;
use Condoedge\Ai\DTOs\FileChunk;

/**
 * File processing service
 *
 * This service orchestrates the complete file processing pipeline:
 * 1. Extract text from file
 * 2. Chunk the text
 * 3. Generate embeddings for each chunk
 * 4. Store chunks with embeddings in vector store
 */
class FileProcessor implements FileProcessorInterface
{
    /**
     * @param FileExtractorRegistry $extractorRegistry
     * @param FileChunkerInterface $chunker
     * @param EmbeddingProviderInterface $embeddingProvider
     * @param ChunkStoreInterface $chunkStore
     */
    public function __construct(
        private readonly FileExtractorRegistry $extractorRegistry,
        private readonly FileChunkerInterface $chunker,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly ChunkStoreInterface $chunkStore
    ) {}

    /**
     * {@inheritdoc}
     */
    public function processFile(object $file, array $options = []): ProcessingResult
    {
        $startTime = microtime(true);

        try {
            // Validate file object
            $this->validateFileObject($file);

            // Check if already processed and not forcing reprocess
            if (!($options['force'] ?? false) && $this->isProcessed($file)) {
                return ProcessingResult::failure(
                    $file->id,
                    "File already processed. Use 'force' option to reprocess.",
                    microtime(true) - $startTime
                );
            }

            // Get file path
            $filePath = $this->getFilePath($file);

            if (!file_exists($filePath)) {
                return ProcessingResult::failure(
                    $file->id,
                    "File not found: {$filePath}",
                    microtime(true) - $startTime
                );
            }

            // Get file extension
            $extension = pathinfo($file->name, PATHINFO_EXTENSION);

            // Check if file type is supported
            if (!$this->supportsFileType($extension)) {
                return ProcessingResult::failure(
                    $file->id,
                    "Unsupported file type: {$extension}",
                    microtime(true) - $startTime
                );
            }

            // Step 1: Extract text
            $text = $this->extractorRegistry->extract($filePath);

            if (empty(trim($text))) {
                return ProcessingResult::failure(
                    $file->id,
                    "No text content extracted from file",
                    microtime(true) - $startTime
                );
            }

            // Step 2: Chunk text
            $chunkSize = $options['chunk_size'] ?? $this->chunker->getRecommendedChunkSize($extension);
            $overlap = $options['overlap'] ?? $this->chunker->getRecommendedOverlap($extension);

            $textChunks = $this->chunker->chunk($text, [
                'chunk_size' => $chunkSize,
                'overlap' => $overlap,
            ]);

            if (empty($textChunks)) {
                return ProcessingResult::failure(
                    $file->id,
                    "Failed to chunk text content",
                    microtime(true) - $startTime
                );
            }

            // Step 3: Generate embeddings
            $embeddings = $this->embeddingProvider->embedBatch($textChunks);

            // Step 4: Create FileChunk objects
            $fileChunks = $this->createFileChunks(
                $file,
                $textChunks,
                $embeddings,
                $text
            );

            // Step 5: Store chunks
            $storeSuccess = $this->chunkStore->storeChunks($fileChunks);

            if (!$storeSuccess) {
                return ProcessingResult::failure(
                    $file->id,
                    "Failed to store chunks in vector database",
                    microtime(true) - $startTime
                );
            }

            // Success!
            return ProcessingResult::success(
                $file->id,
                count($fileChunks),
                count($embeddings),
                microtime(true) - $startTime,
                [
                    'file_name' => $file->name,
                    'file_size' => filesize($filePath),
                    'text_length' => strlen($text),
                    'chunk_size' => $chunkSize,
                    'overlap' => $overlap,
                ]
            );
        } catch (\Exception $e) {
            return ProcessingResult::failure(
                $file->id,
                "Processing error: {$e->getMessage()}",
                microtime(true) - $startTime,
                ['exception' => get_class($e)]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function reprocessFile(object $file): ProcessingResult
    {
        // First remove existing chunks
        $this->removeFile($file);

        // Then process with force option
        return $this->processFile($file, ['force' => true]);
    }

    /**
     * {@inheritdoc}
     */
    public function removeFile(object $file): bool
    {
        $this->validateFileObject($file);

        return $this->chunkStore->deleteFileChunks($file->id);
    }

    /**
     * {@inheritdoc}
     */
    public function isProcessed(object $file): bool
    {
        $this->validateFileObject($file);

        return $this->chunkStore->hasFileChunks($file->id);
    }

    /**
     * {@inheritdoc}
     */
    public function getFileStats(object $file): array
    {
        $this->validateFileObject($file);

        $chunks = $this->chunkStore->getFileChunks($file->id);

        if (empty($chunks)) {
            return [
                'processed' => false,
                'chunk_count' => 0,
            ];
        }

        $totalSize = array_sum(array_map(
            fn($chunk) => $chunk->getContentLength(),
            $chunks
        ));

        return [
            'processed' => true,
            'chunk_count' => count($chunks),
            'total_content_size' => $totalSize,
            'average_chunk_size' => $totalSize / count($chunks),
            'first_chunk_preview' => substr($chunks[0]->content, 0, 100) . '...',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsFileType(string $extension): bool
    {
        return $this->extractorRegistry->supports($extension);
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedFileTypes(): array
    {
        return $this->extractorRegistry->getSupportedExtensions();
    }

    /**
     * Validate that the file object has required properties
     *
     * @param object $file
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateFileObject(object $file): void
    {
        $required = ['id', 'name', 'path'];

        foreach ($required as $property) {
            if (!property_exists($file, $property)) {
                throw new \InvalidArgumentException(
                    "File object must have '{$property}' property"
                );
            }
        }
    }

    /**
     * Get the full file path
     *
     * @param object $file
     * @return string
     */
    private function getFilePath(object $file): string
    {
        // If path is already absolute, use it
        if ($this->isAbsolutePath($file->path)) {
            return $file->path;
        }

        // Otherwise, construct from storage disk
        $disk = $file->disk ?? 'local';
        $storagePath = config("filesystems.disks.{$disk}.root");

        if (!$storagePath) {
            throw new \RuntimeException("Storage disk '{$disk}' not configured");
        }

        return $storagePath . DIRECTORY_SEPARATOR . $file->path;
    }

    /**
     * Check if a path is absolute
     *
     * @param string $path
     * @return bool
     */
    private function isAbsolutePath(string $path): bool
    {
        // Windows: C:\ or \\server\share
        if (preg_match('/^[A-Z]:/i', $path) || str_starts_with($path, '\\\\')) {
            return true;
        }

        // Unix: /path
        return str_starts_with($path, '/');
    }

    /**
     * Create FileChunk objects from text chunks and embeddings
     *
     * @param object $file
     * @param array $textChunks
     * @param array $embeddings
     * @param string $fullText
     * @return array
     */
    private function createFileChunks(
        object $file,
        array $textChunks,
        array $embeddings,
        string $fullText
    ): array {
        $fileChunks = [];
        $totalChunks = count($textChunks);
        $position = 0;

        foreach ($textChunks as $index => $chunkText) {
            $chunkLength = strlen($chunkText);
            $endPosition = $position + $chunkLength;

            $fileChunks[] = new FileChunk(
                fileId: $file->id,
                fileName: $file->name,
                content: $chunkText,
                embedding: $embeddings[$index] ?? [],
                chunkIndex: $index,
                totalChunks: $totalChunks,
                startPosition: $position,
                endPosition: $endPosition,
                metadata: []
            );

            $position = $endPosition;
        }

        return $fileChunks;
    }
}
