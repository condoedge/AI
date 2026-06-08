<?php

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\DTOs\FileChunk;

/**
 * Qdrant-based chunk storage service
 *
 * This service stores file chunks with their embeddings in Qdrant
 * and provides semantic search capabilities.
 */
class QdrantChunkStore implements ChunkStoreInterface
{
    /**
     * Default collection name for file chunks
     */
    private const DEFAULT_COLLECTION = 'file_chunks';

    /**
     * Default embedding vector dimension (OpenAI ada-002)
     */
    private const DEFAULT_VECTOR_SIZE = 1536;

    /**
     * @var int Embedding vector dimension
     */
    private readonly int $vectorSize;

    /**
     * @var array<string,bool> Collections whose existence/index has been verified
     */
    private array $verifiedCollections = [];

    /**
     * @param VectorStoreInterface $vectorStore
     * @param EmbeddingProviderInterface $embeddingProvider
     * @param string $collection Collection name
     * @param int|null $vectorSize Embedding dimension (defaults to config or 1536)
     */
    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly string $collection = self::DEFAULT_COLLECTION,
        ?int $vectorSize = null
    ) {
        $this->vectorSize = $vectorSize ?? $this->resolveVectorSize();
    }

    /**
     * {@inheritdoc}
     */
    public function storeChunk(FileChunk $chunk, ?string $collection = null): bool
    {
        return $this->storeChunks([$chunk], $collection);
    }

    /**
     * {@inheritdoc}
     */
    public function storeChunks(array $chunks, ?string $collection = null): bool
    {
        if (empty($chunks)) {
            return true;
        }

        $target = $this->resolveCollection($collection);
        $this->ensureCollectionExists($target);

        $points = [];

        foreach ($chunks as $chunk) {
            if (!($chunk instanceof FileChunk)) {
                throw new \InvalidArgumentException('All chunks must be FileChunk instances');
            }

            $points[] = [
                'id' => $chunk->getVectorId(),
                'vector' => $chunk->embedding,
                'payload' => [
                    'file_id' => $chunk->fileId,
                    'file_name' => $chunk->fileName,
                    'content' => $chunk->content,
                    'chunk_index' => $chunk->chunkIndex,
                    'total_chunks' => $chunk->totalChunks,
                    'start_position' => $chunk->startPosition,
                    'end_position' => $chunk->endPosition,
                    'metadata' => $chunk->metadata,
                    'created_at' => time(),
                ],
            ];
        }

        return $this->vectorStore->upsert($target, $points);
    }

    /**
     * {@inheritdoc}
     */
    public function searchByContent(string $query, int $limit = 10, array $filters = [], ?string $collection = null): array
    {
        $target = $this->resolveCollection($collection);
        $this->ensureCollectionExists($target);

        // Generate embedding for the query
        $queryEmbedding = $this->embeddingProvider->embed($query);

        // Build Qdrant filter
        $qdrantFilter = $this->buildQdrantFilter($filters);

        // Get minimum score threshold
        $scoreThreshold = $filters['min_score'] ?? 0.0;

        // Search vector store
        $results = $this->vectorStore->search(
            $target,
            $queryEmbedding,
            $limit,
            $qdrantFilter,
            $scoreThreshold
        );

        // Convert results to FileChunk objects with scores
        return array_map(function ($result) {
            $payload = $result['payload'] ?? [];

            $chunk = new FileChunk(
                fileId: $payload['file_id'],
                fileName: $payload['file_name'],
                content: $payload['content'],
                embedding: [], // Don't include embedding in search results to save memory
                chunkIndex: $payload['chunk_index'],
                totalChunks: $payload['total_chunks'],
                startPosition: $payload['start_position'],
                endPosition: $payload['end_position'],
                metadata: $payload['metadata'] ?? []
            );

            return [
                'chunk' => $chunk,
                'score' => $result['score'] ?? 0.0,
            ];
        }, $results);
    }

    /**
     * {@inheritdoc}
     */
    public function searchByFilename(string $filename, int $limit = 10, array $filters = [], ?string $collection = null): array
    {
        $target = $this->resolveCollection($collection);
        $this->ensureCollectionExists($target);

        // Return early for empty filenames to avoid matching all files.
        if (trim($filename) === '') {
            return [];
        }

        // Build filter for filename matching (uses Qdrant text match:{text}).
        $qdrantFilter = $this->buildFilenameFilter($filename, $filters);

        // PRIMARY: indexed text-match path. Wrapped so a missing/absent payload
        // index (or any Qdrant error) degrades to the scroll fallback below
        // instead of throwing and wiping all file context (FILE-2).
        try {
            if (empty($qdrantFilter)) {
                return [];
            }

            // Count matching points
            $totalCount = $this->vectorStore->count($target, $qdrantFilter);

            if ($totalCount === 0) {
                return [];
            }

            // Use search with filter and dummy vector (similar to getFileChunks approach)
            // We need a higher limit to account for multiple chunks per file
            // Cap the request limit to prevent memory issues when totalCount is very high
            // Absolute cap of 500 ensures large caller limits don't cause memory issues
            $requestLimit = min(max($limit * 3, 100), $totalCount, 500);
            $results = $this->vectorStore->search(
                $target,
                array_fill(0, $this->vectorSize, 0.0), // Dummy vector for filter-only search
                $requestLimit,
                $qdrantFilter,
                0.0 // No score threshold for filename search
            );

            return $this->buildFilenameResults($results, $filename, $limit);
        } catch (\Throwable $e) {
            // FALLBACK: scroll the collection (optionally narrowed by file_id) and
            // perform a case-insensitive substring match on file_name in PHP. This
            // works even when the file_name full-text payload index is missing.
            return $this->searchByFilenameFallback($filename, $limit, $filters, $target);
        }
    }

    /**
     * Filename search fallback using scroll + PHP substring matching.
     *
     * Used when the indexed text-match path is unavailable (e.g. the file_name
     * payload index does not exist). Pulls points via scroll and filters by a
     * case-insensitive substring match on file_name. Never throws — returns an
     * empty array if even the scroll fails.
     *
     * @param string $filename
     * @param int $limit
     * @param array $filters
     * @param string $collection Target collection to scroll
     * @return array
     */
    private function searchByFilenameFallback(string $filename, int $limit, array $filters, string $collection): array
    {
        // Only constrain by file_id in the scroll filter (file_name can't be
        // reliably filtered server-side without the index — that's the whole point).
        $scrollFilter = [];
        if (isset($filters['file_id'])) {
            $scrollFilter = [
                'must' => [
                    ['key' => 'file_id', 'match' => ['value' => $filters['file_id']]],
                ],
            ];
        }

        try {
            // Pull a bounded page of points; cap to protect memory.
            $scrollLimit = min(max($limit * 20, 200), 1000);
            $points = $this->vectorStore->scroll($collection, $scrollFilter, $scrollLimit);
        } catch (\Throwable $e) {
            return [];
        }

        $needle = strtolower(trim($filename));

        // Keep only points whose file_name contains the search term.
        $matching = [];
        foreach ($points as $point) {
            $storedFilename = $point['payload']['file_name'] ?? null;
            if ($storedFilename === null) {
                continue;
            }

            if (str_contains(strtolower($storedFilename), $needle)) {
                $matching[] = $point;
            }
        }

        return $this->buildFilenameResults($matching, $filename, $limit);
    }

    /**
     * Build deduplicated filename-search results from raw vector-store points.
     *
     * Groups by file_id (one entry per file), scores by match quality
     * (exact = 1.0, partial = 0.85), enforces the limit, and sorts exact
     * matches first. Shared by the indexed and fallback filename paths.
     *
     * @param array $results Raw points (each with a 'payload')
     * @param string $filename Original search term
     * @param int $limit Max unique files to return
     * @return array
     */
    private function buildFilenameResults(array $results, string $filename, int $limit): array
    {
        // Group by file_id and return unique files
        $seenFiles = [];
        $output = [];

        foreach ($results as $result) {
            $payload = $result['payload'] ?? [];
            $fileId = $payload['file_id'] ?? null;

            if ($fileId === null || isset($seenFiles[$fileId])) {
                continue;
            }

            $seenFiles[$fileId] = true;

            $chunk = new FileChunk(
                fileId: $payload['file_id'],
                fileName: $payload['file_name'],
                content: $payload['content'],
                embedding: [],
                chunkIndex: $payload['chunk_index'],
                totalChunks: $payload['total_chunks'],
                startPosition: $payload['start_position'],
                endPosition: $payload['end_position'],
                metadata: $payload['metadata'] ?? []
            );

            // Score based on match quality:
            // - Exact match (case-insensitive): 1.0
            // - Partial match (filename contains search term): 0.85
            $storedFilename = $payload['file_name'];
            $isExactMatch = strcasecmp($storedFilename, $filename) === 0;

            $output[] = [
                'chunk' => $chunk,
                'score' => $isExactMatch ? 1.0 : 0.85,
                'match_type' => $isExactMatch ? 'exact' : 'partial',
            ];

            if (count($output) >= $limit) {
                break;
            }
        }

        // Sort exact matches first
        usort($output, fn($a, $b) => $b['score'] <=> $a['score']);

        return $output;
    }

    /**
     * Build filter for filename search
     *
     * @param string $filename
     * @param array $filters
     * @return array
     */
    private function buildFilenameFilter(string $filename, array $filters): array
    {
        // Return empty filter for empty filename to prevent matching all files
        if (trim($filename) === '') {
            return [];
        }

        $must = [];

        // Qdrant text match searches within string values (substring match)
        $must[] = [
            'key' => 'file_name',
            'match' => ['text' => $filename],
        ];

        // Apply additional filters
        if (isset($filters['file_id'])) {
            $must[] = [
                'key' => 'file_id',
                'match' => ['value' => $filters['file_id']],
            ];
        }

        return empty($must) ? [] : ['must' => $must];
    }

    /**
     * {@inheritdoc}
     */
    public function getFileChunks(int|string $fileId, ?string $collection = null): array
    {
        $target = $this->resolveCollection($collection);
        $this->ensureCollectionExists($target);

        // Use count to check if we need to paginate
        $totalCount = $this->vectorStore->count($target, [
            'must' => [
                ['key' => 'file_id', 'match' => ['value' => $fileId]],
            ],
        ]);

        if ($totalCount === 0) {
            return [];
        }

        // Search with file_id filter and high limit
        $results = $this->vectorStore->search(
            $target,
            array_fill(0, $this->vectorSize, 0.0), // Dummy vector for filter-only search
            $totalCount,
            [
                'must' => [
                    ['key' => 'file_id', 'match' => ['value' => $fileId]],
                ],
            ],
            0.0 // No score threshold for fetching all chunks
        );

        // Convert to FileChunk objects
        $chunks = array_map(function ($result) {
            $payload = $result['payload'] ?? [];

            return new FileChunk(
                fileId: $payload['file_id'],
                fileName: $payload['file_name'],
                content: $payload['content'],
                embedding: [], // Don't include embedding to save memory
                chunkIndex: $payload['chunk_index'],
                totalChunks: $payload['total_chunks'],
                startPosition: $payload['start_position'],
                endPosition: $payload['end_position'],
                metadata: $payload['metadata'] ?? []
            );
        }, $results);

        // Sort by chunk index
        usort($chunks, fn($a, $b) => $a->chunkIndex <=> $b->chunkIndex);

        return $chunks;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteFileChunks(int|string $fileId, ?string $collection = null): bool
    {
        $target = $this->resolveCollection($collection);

        // Get all chunk IDs for this file
        $chunks = $this->getFileChunks($fileId, $target);

        if (empty($chunks)) {
            return true;
        }

        $ids = array_map(fn($chunk) => $chunk->getVectorId(), $chunks);

        return $this->vectorStore->deletePoints($target, $ids);
    }

    /**
     * {@inheritdoc}
     */
    public function getChunkCount(array $filters = []): int
    {
        $qdrantFilter = $this->buildQdrantFilter($filters);

        return $this->vectorStore->count($this->collection, $qdrantFilter);
    }

    /**
     * {@inheritdoc}
     */
    public function hasFileChunks(int|string $fileId, ?string $collection = null): bool
    {
        $target = $this->resolveCollection($collection);

        $count = $this->vectorStore->count($target, [
            'file_id' => $fileId
        ]);

        return $count > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getChunk(string $vectorId): ?FileChunk
    {
        $point = $this->vectorStore->getPoint($this->collection, $vectorId);

        if (!$point) {
            return null;
        }

        $payload = $point['payload'] ?? [];

        return new FileChunk(
            fileId: $payload['file_id'],
            fileName: $payload['file_name'],
            content: $payload['content'],
            embedding: $point['vector'] ?? [],
            chunkIndex: $payload['chunk_index'],
            totalChunks: $payload['total_chunks'],
            startPosition: $payload['start_position'],
            endPosition: $payload['end_position'],
            metadata: $payload['metadata'] ?? []
        );
    }

    /**
     * Resolve the collection to operate on for a given call.
     *
     * A null override resolves to the bound (default) collection, preserving the
     * single-collection behavior. A non-null value routes the call to that
     * collection (e.g. 'documentation_chunks' for physical docs).
     *
     * @param string|null $collection
     * @return string
     */
    private function resolveCollection(?string $collection): string
    {
        $resolved = $collection !== null && trim($collection) !== '' ? $collection : $this->collection;

        return $resolved;
    }

    /**
     * Ensure a collection exists in Qdrant (lazy - only called when needed).
     *
     * Verification is tracked per collection so multiple collections (e.g.
     * file_chunks + documentation_chunks) can be used by the same store.
     *
     * @param string|null $collection Collection to verify (defaults to bound collection)
     * @return void
     */
    private function ensureCollectionExists(?string $collection = null): void
    {
        $target = $this->resolveCollection($collection);

        if (!empty($this->verifiedCollections[$target])) {
            return;
        }

        if (!$this->vectorStore->collectionExists($target)) {
            $this->vectorStore->createCollection(
                $target,
                $this->vectorSize,
                'cosine'
            );
        }

        // Best-effort: ensure the file_name full-text index exists even for
        // collections created before this path (createCollection also does this
        // for fresh collections). Never throws.
        $this->vectorStore->ensurePayloadIndex($target, 'file_name', 'text');

        $this->verifiedCollections[$target] = true;
    }

    /**
     * Resolve the vector size from config or active provider dimensions.
     *
     * @return int
     */
    private function resolveVectorSize(): int
    {
        $explicit = config('ai.embedding.vector_size');
        if ($explicit) {
            return (int) $explicit;
        }

        $provider = config('ai.embedding.default', 'openai');
        return (int) config("ai.embedding.{$provider}.dimensions", self::DEFAULT_VECTOR_SIZE);
    }

    /**
     * Build Qdrant filter from search filters
     *
     * @param array $filters
     * @return array
     */
    private function buildQdrantFilter(array $filters): array
    {
        $must = [];

        // Filter by file_id
        if (isset($filters['file_id'])) {
            $must[] = [
                'key' => 'file_id',
                'match' => ['value' => $filters['file_id']],
            ];
        }

        // Filter by file_types (extension extracted from filename)
        if (!empty($filters['file_types'])) {
            $should = [];
            foreach ($filters['file_types'] as $type) {
                $should[] = [
                    'key' => 'file_name',
                    'match' => ['text' => ".$type"],
                ];
            }

            if (!empty($should)) {
                $must[] = ['should' => $should];
            }
        }

        return empty($must) ? [] : ['must' => $must];
    }
}
