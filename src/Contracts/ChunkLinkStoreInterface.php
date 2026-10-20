<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * File-link capability for a chunk store: writing the owner of an uploaded file
 * onto chunks that are ALREADY stored ({@see \Condoedge\Ai\Support\FileLink}).
 *
 * Separate from ChunkStoreInterface for the reason ChunkMapStoreInterface is:
 * `instanceof ChunkLinkStoreInterface` IS the capability check. Implemented by
 * QdrantChunkStore.
 */
interface ChunkLinkStoreInterface
{
    /**
     * Set the `owner` / `ancestors` / `dedupe` keys of every stored chunk of one
     * file — a payload-only write: no vector is read, re-embedded or replaced,
     * no point is added or removed. Idempotent: a chunk that already carries
     * exactly these keys is not written again.
     *
     * @param array{owner?: ?string, ancestors?: array<int, mixed>} $owner
     * @param string|null $collection Optional collection override (defaults to the bound collection)
     * @param bool $dryRun Report what would change, write nothing
     * @return array{points: int, changed: int} the file's chunks, and how many of them were (or would be) written
     */
    public function linkFileChunks(int|string $fileId, array $owner, ?string $collection = null, bool $dryRun = false): array;

    /**
     * The ids of the files that have chunks in the collection.
     *
     * @return list<int|string>
     */
    public function storedFileIds(?string $collection = null): array;

    /**
     * Create the payload indexes the link keys are filtered and grouped by.
     * Idempotent and best-effort.
     */
    public function ensureLinkIndexes(?string $collection = null): void;
}
