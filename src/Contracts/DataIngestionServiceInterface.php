<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\Domain\Contracts\Nodeable;

/**
 * DataIngestionServiceInterface
 *
 * Contract for ingesting entities into the graph store (Neo4j).
 *
 * This service provides a unified interface for:
 * - Storing entities as nodes in Neo4j graph database
 * - Managing relationships between entities
 * - Batch processing for efficient ingestion
 *
 * Design Principles:
 * - Interface-based: Depends only on abstractions (GraphStoreInterface)
 * - Transparent: Returns detailed status for each operation
 * - Testable: Can be tested in isolation with mocks
 */
interface DataIngestionServiceInterface
{
    /**
     * Ingest a single entity into the graph store
     *
     * This method:
     * 1. Extracts GraphConfig from entity and creates the node in Neo4j
     * 2. Creates relationships defined in GraphConfig
     *
     * Records are not embedded: no embedding call, no vector-store write.
     *
     * @param Nodeable $entity The entity to ingest (must implement Nodeable)
     * @return array Status array with structure:
     *               [
     *                   'graph_stored' => bool,        // True if Neo4j succeeded
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
     *                   'partially_succeeded' => int, // Always 0 (kept for the summary shape)
     *                   'failed' => int,          // Entities that failed completely
     *                   'errors' => array         // Detailed errors by entity ID
     *               ]
     * @throws \InvalidArgumentException If any entity does not implement Nodeable
     */
    public function ingestBatch(array $entities): array;

    /**
     * Remove an entity from the graph store
     *
     * Deletes the node from Neo4j (relationships are automatically removed).
     *
     * @param Nodeable $entity The entity to remove
     * @return bool True if the node was removed
     * @throws \InvalidArgumentException If entity does not implement Nodeable
     */
    public function remove(Nodeable $entity): bool;

    /**
     * Sync an entity (update if exists, create if not)
     *
     * This method checks if the entity's node exists in the graph:
     * - If exists: updates its properties
     * - If not exists: creates the node and its relationships
     *
     * Useful for keeping the graph in sync with application database changes.
     *
     * @param Nodeable $entity The entity to sync
     * @return array Status array with structure:
     *               [
     *                   'action' => string,        // 'created' or 'updated'
     *                   'graph_synced' => bool,    // True if Neo4j succeeded
     *                   'errors' => string[]       // Any error messages
     *               ]
     * @throws \InvalidArgumentException If entity does not implement Nodeable
     */
    public function sync(Nodeable $entity): array;

    /**
     * Synchronize missing relationships for entities
     *
     * This method reconciles relationships that couldn't be created during initial
     * ingestion because target nodes didn't exist yet (e.g., Users ingested before Persons).
     *
     * For each entity:
     * 1. Checks all configured relationships
     * 2. Verifies both source and target nodes exist
     * 3. Creates missing relationships (skips existing ones)
     * 4. Logs detailed information about created/skipped relationships
     *
     * Use cases:
     * - After bulk ingestion with wrong entity order
     * - After importing data from external sources
     * - Periodic reconciliation to ensure data consistency
     *
     * @param array $entities Array of Nodeable entities to sync relationships for
     * @return array Summary with structure:
     *               [
     *                   'total_entities' => int,          // Total entities processed
     *                   'total_relationships_checked' => int,  // Total relationships evaluated
     *                   'relationships_created' => int,    // New relationships created
     *                   'relationships_skipped' => int,    // Already existing relationships
     *                   'relationships_failed' => int,     // Relationships that couldn't be created
     *                   'errors' => array                  // Detailed errors by entity ID
     *               ]
     */
    public function syncRelationships(array $entities): array;
}
