<?php

namespace Condoedge\Ai\Contracts;

/**
 * VectorStoreInterface
 *
 * Abstraction for vector database operations (Qdrant, Pinecone, Weaviate, etc.)
 * Stores and searches embeddings for semantic similarity.
 */
interface VectorStoreInterface
{
    /**
     * Create a new collection for storing vectors
     *
     * @param string $name Collection name
     * @param int $vectorSize Dimension of vectors (e.g., 1536 for OpenAI ada-002)
     * @param string $distance Distance metric ('cosine', 'euclidean', 'dot')
     * @return bool Success status
     */
    public function createCollection(string $name, int $vectorSize, string $distance = 'cosine'): bool;

    /**
     * Check if a collection exists
     *
     * @param string $name Collection name
     * @return bool
     */
    public function collectionExists(string $name): bool;

    /**
     * Delete a collection
     *
     * @param string $name Collection name
     * @return bool Success status
     */
    public function deleteCollection(string $name): bool;

    /**
     * Insert or update vectors in a collection
     *
     * @param string $collection Collection name
     * @param array $points Array of points: [['id' => ..., 'vector' => [...], 'payload' => [...]]]
     * @return bool Success status
     */
    public function upsert(string $collection, array $points): bool;


    /**
     * List all collections in the vector store.
     *
     * @return array<string> Collection names
     */
    public function listCollections(): array;

    /**
     * Search for similar vectors
     *
     * @param string $collection Collection name
     * @param array $vector Query vector
     * @param int $limit Number of results to return
     * @param array $filter Optional payload filters
     * @param float $scoreThreshold Minimum similarity score (0.0 - 1.0)
     * @return array Array of results with 'id', 'score', 'payload'
     */
    public function search(
        string $collection,
        array $vector,
        int $limit = 10,
        array $filter = [],
        float $scoreThreshold = 0.0
    ): array;

    /**
     * Get a specific point by ID
     *
     * @param string $collection Collection name
     * @param string|int $id Point ID
     * @return array|null Point data or null if not found
     */
    public function getPoint(string $collection, string|int $id): ?array;

    /**
     * Delete points from a collection
     *
     * @param string $collection Collection name
     * @param array $ids Array of point IDs to delete
     * @return bool Success status
     */
    public function deletePoints(string $collection, array $ids): bool;

    /**
     * Get collection information
     *
     * @param string $name Collection name
     * @return array Collection metadata
     */
    public function getCollectionInfo(string $name): array;

    /**
     * Count points in a collection
     *
     * @param string $collection Collection name
     * @param array $filter Optional payload filters
     * @return int Number of points
     */
    public function count(string $collection, array $filter = []): int;

    /**
     * Ensure a payload field index exists on a collection.
     *
     * Used to create full-text ('text') or keyword indexes so payload filters
     * (e.g. match:{text} on file_name) are reliable and efficient.
     * Implementations should treat this as best-effort and never throw.
     *
     * @param string $collection Collection name
     * @param string $field Payload field to index
     * @param string $schema Field schema ('text', 'keyword', 'integer', ...)
     * @return bool True if created/confirmed, false on failure
     */
    public function ensurePayloadIndex(string $collection, string $field, string $schema = 'keyword'): bool;

    /**
     * Scroll (page) through points in a collection without a query vector.
     *
     * @param string $collection Collection name
     * @param array $filter Optional payload filter
     * @param int $limit Maximum number of points to return
     * @param bool $withPayload Whether to include payloads
     * @return array Array of points (each with 'id' and 'payload')
     */
    public function scroll(string $collection, array $filter = [], int $limit = 100, bool $withPayload = true): array;

    /**
     * Ensure a collection exists, create if it doesn't
     *
     * @param string $name Collection name
     * @param int $vectorSize Dimension of vectors
     * @param string $distance Distance metric ('cosine', 'euclidean', 'dot')
     * @return void
     */
    public function ensureCollection(string $name, int $vectorSize, string $distance = 'cosine'): void;

    /**
     * Delete all points in a collection (but keep the collection)
     *
     * @param string $collection Collection name
     * @return bool Success status
     */
    public function deleteAll(string $collection): bool;

    /**
     * Batch upsert points (alias for upsert, ensures batch optimization)
     *
     * @param string $collection Collection name
     * @param array $points Array of points with 'id', 'vector', 'metadata' keys
     * @return bool Success status
     */
    public function upsertBatch(string $collection, array $points): bool;
}
