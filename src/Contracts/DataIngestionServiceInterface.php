<?php

declare(strict_types=1);

namespace AiSystem\Contracts;

use AiSystem\Domain\Contracts\Nodeable;

/**
 * DataIngestionServiceInterface
 *
 * Contract for ingesting entities into both graph (Neo4j) and vector (Qdrant) stores.
 *
 * This service provides a unified interface for:
 * - Storing entities as nodes in Neo4j graph database
 * - Creating embeddings and storing them in Qdrant vector database
 * - Managing relationships between entities
 * - Batch processing for efficient ingestion
 *
 * Design Principles:
 * - Interface-based: Depends only on abstractions (VectorStoreInterface, GraphStoreInterface)
 * - Resilient: Continues processing even if one store fails
 * - Transparent: Returns detailed status for each operation
 * - Testable: Can be tested in isolation with mocks
 */
interface DataIngestionServiceInterface
{
    /**
     * Ingest a single entity into both graph and vector stores
     *
     * This method:
     * 1. Extracts GraphConfig from entity and creates/updates node in Neo4j
     * 2. Creates relationships defined in GraphConfig
     * 3. Extracts VectorConfig from entity
     * 4. Generates embedding from specified fields
     * 5. Stores embedding in Qdrant with metadata
     *
     * The operation is resilient - if one store fails, the other continues.
     * Check the returned status array to verify complete success.
     *
     * @param Nodeable $entity The entity to ingest (must implement Nodeable)
     * @return array Status array with structure:
     *               [
     *                   'graph_stored' => bool,        // True if Neo4j succeeded
     *                   'vector_stored' => bool,       // True if Qdrant succeeded
     *                   'relationships_created' => int, // Count of relationships created
     *                   'errors' => string[]           // Any error messages
     *               ]
     * @throws \InvalidArgumentException If entity does not implement Nodeable
     */
    public function ingest(Nodeable $entity): array;

    /**
     * Ingest multiple entities in batch (more efficient than individual ingestion)
     *
     * Batch processing provides significant performance benefits:
     * - Embeddings are generated in a single API call
     * - Vector store upserts are batched
     * - Graph operations can leverage transactions
     *
     * If any individual entity fails, processing continues for remaining entities.
     *
     * @param array $entities Array of Nodeable entities
     * @return array Summary with structure:
     *               [
     *                   'total' => int,           // Total entities processed
     *                   'succeeded' => int,       // Entities fully ingested
     *                   'partially_succeeded' => int, // Entities stored in one store only
     *                   'failed' => int,          // Entities that failed completely
     *                   'errors' => array         // Detailed errors by entity ID
     *               ]
     * @throws \InvalidArgumentException If any entity does not implement Nodeable
     */
    public function ingestBatch(array $entities): array;

    /**
     * Remove an entity from both graph and vector stores
     *
     * This method:
     * 1. Deletes the node from Neo4j (relationships are automatically removed)
     * 2. Deletes the point from Qdrant vector store
     *
     * @param Nodeable $entity The entity to remove
     * @return bool True if removed from at least one store successfully
     * @throws \InvalidArgumentException If entity does not implement Nodeable
     */
    public function remove(Nodeable $entity): bool;

    /**
     * Sync an entity (update if exists, create if not)
     *
     * This method checks if the entity exists in each store:
     * - If exists: updates properties/embedding
     * - If not exists: creates new node/point
     *
     * Useful for keeping stores in sync with application database changes.
     *
     * @param Nodeable $entity The entity to sync
     * @return array Status array with structure:
     *               [
     *                   'action' => string,        // 'created' or 'updated'
     *                   'graph_synced' => bool,    // True if Neo4j succeeded
     *                   'vector_synced' => bool,   // True if Qdrant succeeded
     *                   'errors' => string[]       // Any error messages
     *               ]
     * @throws \InvalidArgumentException If entity does not implement Nodeable
     */
    public function sync(Nodeable $entity): array;
}
