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
     * List all collections
     *
     * @return array Array of collection names
     */
    public function listCollections();

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
}
