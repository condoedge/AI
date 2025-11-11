<?php

namespace Condoedge\Ai\Domain\Contracts;

use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;

/**
 * Nodeable Interface
 *
 * Implement this interface in any model that should be stored in Neo4j and/or Qdrant.
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
     * Get the Qdrant vector configuration for this entity
     *
     * @return VectorConfig Configuration for vector storage
     * @throws \LogicException If entity is not vectorizable
     */
    public function getVectorConfig(): VectorConfig;

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
    public function toArray(): array;
}
