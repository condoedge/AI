<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * In-place payload update capability for a vector store.
 *
 * Deliberately separate from VectorStoreInterface (public API hosts implement
 * and tests mock — adding a method there is a BC break):
 * `instanceof UpdatesPayloadInterface` IS the capability check, and a store
 * without it simply cannot have data attached to points after ingest.
 * Implemented by QdrantStore.
 */
interface UpdatesPayloadInterface
{
    /**
     * Merge payload keys into points that already exist.
     *
     * ADDITIVE: only the given top-level keys are written; the points' vectors
     * and every other payload key stay untouched, so a caller never has to
     * re-embed (or even re-read) a point to annotate it. An empty id list is a
     * successful no-op.
     *
     * @param string $collection Collection name
     * @param array<string,mixed> $payload Top-level payload keys to set
     * @param array<int,string|int> $pointIds Ids of the points to update
     * @return bool True when the store confirmed the write
     */
    public function setPayload(string $collection, array $payload, array $pointIds): bool;
}
