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
     * @param VectorStoreInterface $vectorStore
     * @param EmbeddingProviderInterface $embeddingProvider
     * @param string $collection Collection name
     */
    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly string $collection = self::DEFAULT_COLLECTION
    ) {
        $this->ensureCollectionExists();
    }

    /**
     * {@inheritdoc}
     */
    public function storeChunk(FileChunk $chunk): bool
    {
        return $this->storeChunks([$chunk]);
    }

    /**
     * {@inheritdoc}
     */
    public function storeChunks(array $chunks): bool
    {
        if (empty($chunks)) {
            return true;
        }

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

        return $this->vectorStore->upsert($this->collection, $points);
    }

    /**
     * {@inheritdoc}
     */
    public function searchByContent(string $query, int $limit = 10, array $filters = []): array
    {
        // Generate embedding for the query
        $queryEmbedding = $this->embeddingProvider->embed($query);

        // Build Qdrant filter
        $qdrantFilter = $this->buildQdrantFilter($filters);

        // Get minimum score threshold
        $scoreThreshold = $filters['min_score'] ?? 0.0;

        // Search vector store
        $results = $this->vectorStore->search(
            $this->collection,
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
    public function getFileChunks(int $fileId): array
    {
        // Use count to check if we need to paginate
        $totalCount = $this->vectorStore->count($this->collection, [
            'must' => [
                ['key' => 'file_id', 'match' => ['value' => $fileId]],
            ],
        ]);

        if ($totalCount === 0) {
            return [];
        }

        // Search with file_id filter and high limit
        $results = $this->vectorStore->search(
            $this->collection,
            array_fill(0, 1536, 0.0), // Dummy vector for filter-only search
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
    public function deleteFileChunks(int $fileId): bool
    {
        // Get all chunk IDs for this file
        $chunks = $this->getFileChunks($fileId);

        if (empty($chunks)) {
            return true;
        }

        $ids = array_map(fn($chunk) => $chunk->getVectorId(), $chunks);

        return $this->vectorStore->deletePoints($this->collection, $ids);
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
    public function hasFileChunks(int $fileId): bool
    {
        $count = $this->vectorStore->count($this->collection, [
            'must' => [
                ['key' => 'file_id', 'match' => ['value' => $fileId]],
            ],
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
     * Ensure the collection exists in Qdrant
     *
     * @return void
     */
    private function ensureCollectionExists(): void
    {
        if (!$this->vectorStore->collectionExists($this->collection)) {
            $this->vectorStore->createCollection(
                $this->collection,
                1536, // OpenAI ada-002 embedding size
                'cosine'
            );
        }
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
