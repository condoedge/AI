<?php

namespace Condoedge\Ai\Domain\Contracts;

use Condoedge\Ai\Domain\ValueObjects\VectorConfig;

/**
 * Searchable Interface
 *
 * Marker interface for entities that support semantic vector search.
 * If an entity implements Nodeable but doesn't need vector search,
 * you can throw LogicException in getVectorConfig().
 *
 * This interface is optional - mainly used for type hinting and documentation.
 */
interface Searchable
{
    /**
     * Get the vector configuration for semantic search
     *
     * @return VectorConfig
     */
    public function getVectorConfig(): VectorConfig;
}
