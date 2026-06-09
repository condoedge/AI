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
            $this->validateFileObject($file);

            // Resolve the target collection for this call. Null => the chunk
            // store's bound collection (e.g. file_chunks). The docs ingest path
            // passes 'documentation_chunks' so physical docs land in their own
            // collection.
            $collection = $options['collection'] ?? null;

            if (!($options['force'] ?? false) && $this->isProcessed($file, $collection)) {
                return ProcessingResult::failure(
                    $file->id,
                    "File already processed. Use 'force' option to reprocess.",
                    microtime(true) - $startTime
                );
            }

            $filePath = $this->getFilePath($file);

            if (!file_exists($filePath)) {
                return ProcessingResult::failure(
                    $file->id,
                    "File not found: {$filePath}",
                    microtime(true) - $startTime
                );
            }

            $extension = pathinfo($file->name, PATHINFO_EXTENSION);

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

            // Step 5: Store chunks (routed to the requested collection if given)
            $storeSuccess = $this->chunkStore->storeChunks($fileChunks, $collection);

            if (!$storeSuccess) {
                return ProcessingResult::failure(
                    $file->id,
                    "Failed to store chunks in vector database",
                    microtime(true) - $startTime
                );
            }

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
    public function reprocessFile(object $file, ?string $collection = null): ProcessingResult
    {
        $this->removeFile($file, $collection);

        // Force reprocess, preserving the target collection.
        return $this->processFile($file, ['force' => true, 'collection' => $collection]);
    }

    /**
     * {@inheritdoc}
     */
    public function removeFile(object $file, ?string $collection = null): bool
    {
        $this->validateFileObject($file);

        return $this->chunkStore->deleteFileChunks($file->id, $collection);
    }

    /**
     * {@inheritdoc}
     */
    public function isProcessed(object $file, ?string $collection = null): bool
    {
        $this->validateFileObject($file);

        return $this->chunkStore->hasFileChunks($file->id, $collection);
    }

    /**
     * {@inheritdoc}
     */
    public function getFileStats(object $file, ?string $collection = null): array
    {
        $this->validateFileObject($file);

        $chunks = $this->chunkStore->getFileChunks($file->id, $collection);

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
            if (!isset($file->$property)) {
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
        if ($this->isAbsolutePath($file->path)) {
            return $file->path;
        }

        // Construct from the storage disk root for relative paths.
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
        $this->assertEmbeddingsAlignWithChunks($textChunks, $embeddings);

        $fileChunks = [];
        $totalChunks = count($textChunks);

        // Moving character cursor over the source. Chunks overlap and the
        // chunker may renormalize whitespace between joined units, so positions
        // are resolved by locating each chunk's text in the source from the
        // cursor rather than by cumulatively summing chunk lengths (which would
        // run past the true end once overlap is involved).
        $cursor = 0;

        foreach ($textChunks as $index => $chunkText) {
            [$startPosition, $endPosition] = $this->resolveChunkOffsets(
                $fullText,
                $chunkText,
                $cursor
            );

            $fileChunks[] = new FileChunk(
                fileId: $file->id,
                fileName: $file->name,
                content: $chunkText,
                embedding: $embeddings[$index],
                chunkIndex: $index,
                totalChunks: $totalChunks,
                startPosition: $startPosition,
                endPosition: $endPosition,
                metadata: []
            );

            // Advance the cursor to this chunk's start so the next (overlapping)
            // chunk is searched forward of here, never behind it.
            $cursor = $startPosition;
        }

        return $fileChunks;
    }

    /**
     * Resolve the real character [start, end) offsets of a chunk in the source.
     *
     * The chunk length is authoritative (end = start + character length). The
     * start is the chunk's character position in the source, found from the
     * moving cursor. Whitespace-normalized joins (paragraph/sentence chunks)
     * may not be verbatim substrings, so we fall back to anchoring on the
     * chunk's leading token; if even that fails the cursor itself is used.
     *
     * @param string $fullText
     * @param string $chunkText
     * @param int $cursor Character offset to search forward from
     * @return array{0:int,1:int} [start, end]
     */
    private function resolveChunkOffsets(string $fullText, string $chunkText, int $cursor): array
    {
        $chunkLength = mb_strlen($chunkText, 'UTF-8');

        $start = mb_strpos($fullText, $chunkText, $cursor, 'UTF-8');

        if ($start === false) {
            // Anchor on the chunk's first line / leading token to keep start
            // truthful for whitespace-normalized joins.
            $anchor = trim((string) preg_split('/\r\n|\r|\n/', $chunkText, 2)[0]);

            $start = $anchor !== ''
                ? mb_strpos($fullText, $anchor, $cursor, 'UTF-8')
                : false;

            if ($start === false) {
                $start = $cursor;
            }
        }

        return [$start, $start + $chunkLength];
    }

    /**
     * Guard that every chunk has exactly one matching embedding so a chunk can
     * never be paired with the wrong vector or an empty/short batch silently
     * becoming an unsearchable empty vector.
     *
     * @param array $textChunks
     * @param array $embeddings
     * @return void
     * @throws \RuntimeException
     */
    private function assertEmbeddingsAlignWithChunks(array $textChunks, array $embeddings): void
    {
        if (count($embeddings) !== count($textChunks)) {
            throw new \RuntimeException(sprintf(
                'Embedding/chunk count mismatch: %d chunks vs %d embeddings.',
                count($textChunks),
                count($embeddings)
            ));
        }

        foreach ($textChunks as $index => $chunkText) {
            $vector = $embeddings[$index] ?? null;

            if (!is_array($vector) || $vector === []) {
                throw new \RuntimeException(
                    "Empty or missing embedding for chunk index {$index}."
                );
            }
        }
    }
}
