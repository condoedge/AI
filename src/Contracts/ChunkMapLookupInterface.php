<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Lookup capability of a document-map store: the map records already stored in
 * a collection, by the hash of the TEXT they were written from.
 *
 * Why: a gist describes text, not a file. The same document uploaded N times
 * (measured on the host: one declaration filed 36 times) is N × the same
 * chunks; with this lookup the map builder pays for each distinct text once and
 * writes the record onto the copies as a payload-only update.
 *
 * Deliberately separate from ChunkMapStoreInterface (hosts implement it and
 * tests mock it): `instanceof ChunkMapLookupInterface` IS the capability check.
 * A store without it still gets one gist per text within a single run of the
 * builder; only the reuse ACROSS runs needs it. Implemented by QdrantChunkStore.
 */
interface ChunkMapLookupInterface
{
    /**
     * The stored map records written from text with one of these hashes.
     *
     * @param list<string> $hashes Text hashes (Support\DocumentMap::hash())
     * @param string|null $collection Optional collection override (defaults to the bound collection).
     *        The lookup never leaves the collection: a record of another collection is another corpus.
     * @return array<string, list<array<string, mixed>>> hash => the records found for it (any
     *         number, in no particular order; the caller decides which one is usable).
     *         A hash without a record is absent.
     */
    public function findChunkMaps(array $hashes, ?string $collection = null): array;
}
