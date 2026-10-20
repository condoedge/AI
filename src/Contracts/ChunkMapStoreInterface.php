<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\DTOs\FileChunk;

/**
 * Document-map capability for a chunk store.
 *
 * Deliberately separate from ChunkStoreInterface (hosts implement it and tests
 * mock it — adding a method there is a BC break): `instanceof
 * ChunkMapStoreInterface` IS the capability check, and a store without it simply
 * keeps unmapped chunks. Implemented by QdrantChunkStore.
 *
 * The map is built AFTER ingest, from the document as a whole, so it is written
 * onto chunks that are already stored rather than at storeChunks() time. A store
 * that implements this also reads the map back as `$chunk->metadata['map']`.
 */
interface ChunkMapStoreInterface
{
    /**
     * Attach a document map to an already-stored chunk.
     *
     * Best-effort by contract: a map is an annotation, so a store that cannot
     * write it reports false instead of throwing and the chunk stays usable
     * (unmapped) rather than failing the caller's whole pass.
     *
     * @param FileChunk $chunk The stored chunk (identified by its vector id)
     * @param array<string,mixed> $map The record to store (Support\DocumentMap): an entry
     *   ['v' => int, 'hash' => string, 'gist' => string] or the record of a failed
     *   attempt ['v' => int, 'hash' => string, 'failed' => string, 'attempts' => int],
     *   either with the scope of the chunk's part when one was judged
     *   ['scope' => 'in'|'out', 'scope_ref' => string, 'scope_why' => string]
     * @param string|null $collection Optional collection override (defaults to the bound collection)
     * @return bool True when the map was written
     */
    public function setChunkMap(FileChunk $chunk, array $map, ?string $collection = null): bool;
}
