<?php

namespace Condoedge\Ai\Domain\Contracts;

use Condoedge\Ai\Domain\ValueObjects\GraphConfig;

/**
 * Nodeable Interface
 *
 * Implement this interface in any model that should be stored in the graph (Neo4j).
 * Records are written to the graph only: there is no per-entity vector collection.
 *
 * You can either:
 * 1. Implement methods manually for custom logic
 * 2. Use HasNodeableConfig trait to load from config files
 */
interface Nodeable
{
    /**
     * Get the Neo4j graph configuration for this entity
     *
     * @return GraphConfig Configuration for Neo4j storage
     */
    public function getGraphConfig(): GraphConfig;

    /**
     * Get the unique identifier for this entity
     *
     * @return string|int
     */
    public function getId(): string|int;

    /**
     * Get all properties as an associative array
     *
     * @return array
     */
    public function toArray();
}
