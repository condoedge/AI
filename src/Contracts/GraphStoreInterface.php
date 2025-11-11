<?php

namespace Condoedge\Ai\Contracts;

/**
 * GraphStoreInterface
 *
 * Abstraction for graph database operations (Neo4j, ArangoDB, OrientDB, etc.)
 * Stores entities as nodes and relationships between them.
 */
interface GraphStoreInterface
{
    /**
     * Create a node in the graph
     *
     * @param string $label Node label (e.g., "Customer", "Person")
     * @param array $properties Node properties (e.g., ['id' => 123, 'name' => 'John'])
     * @return string|int Internal node ID
     */
    public function createNode(string $label, array $properties): string|int;

    /**
     * Update a node's properties
     *
     * @param string $label Node label
     * @param string|int $id Node's application ID (from properties)
     * @param array $properties Properties to update
     * @return bool Success status
     */
    public function updateNode(string $label, string|int $id, array $properties): bool;

    /**
     * Delete a node
     *
     * @param string $label Node label
     * @param string|int $id Node's application ID
     * @return bool Success status
     */
    public function deleteNode(string $label, string|int $id): bool;

    /**
     * Create a relationship between two nodes
     *
     * @param string $fromLabel Source node label
     * @param string|int $fromId Source node application ID
     * @param string $toLabel Target node label
     * @param string|int $toId Target node application ID
     * @param string $type Relationship type (e.g., "MEMBER_OF", "PURCHASED")
     * @param array $properties Optional relationship properties
     * @return bool Success status
     */
    public function createRelationship(
        string $fromLabel,
        string|int $fromId,
        string $toLabel,
        string|int $toId,
        string $type,
        array $properties = []
    ): bool;

    /**
     * Delete a relationship
     *
     * @param string $fromLabel Source node label
     * @param string|int $fromId Source node application ID
     * @param string $toLabel Target node label
     * @param string|int $toId Target node application ID
     * @param string $type Relationship type
     * @return bool Success status
     */
    public function deleteRelationship(
        string $fromLabel,
        string|int $fromId,
        string $toLabel,
        string|int $toId,
        string $type
    ): bool;

    /**
     * Execute a Cypher query
     *
     * @param string $cypher Cypher query
     * @param array $parameters Query parameters
     * @return array Query results
     */
    public function query(string $cypher, array $parameters = []): array;

    /**
     * Get database schema information
     *
     * Returns information about node labels, relationship types, and properties
     *
     * @return array Schema information
     */
    public function getSchema(): array;

    /**
     * Check if a node exists
     *
     * @param string $label Node label
     * @param string|int $id Node's application ID
     * @return bool
     */
    public function nodeExists(string $label, string|int $id): bool;

    /**
     * Get a node by ID
     *
     * @param string $label Node label
     * @param string|int $id Node's application ID
     * @return array|null Node properties or null if not found
     */
    public function getNode(string $label, string|int $id): ?array;

    /**
     * Begin a transaction
     *
     * @return mixed Transaction object/ID
     */
    public function beginTransaction();

    /**
     * Commit a transaction
     *
     * @param mixed $transaction Transaction object/ID
     * @return bool Success status
     */
    public function commit($transaction): bool;

    /**
     * Rollback a transaction
     *
     * @param mixed $transaction Transaction object/ID
     * @return bool Success status
     */
    public function rollback($transaction): bool;
}
