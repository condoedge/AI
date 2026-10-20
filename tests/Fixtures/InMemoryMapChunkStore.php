<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Condoedge\Ai\Contracts\ChunkMapStoreInterface;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;

/**
 * An in-memory chunk store with the document-map capability (document-map tests).
 * setChunkMap() rewrites the stored chunk so the map reads back as
 * `$chunk->metadata['map']`, like QdrantChunkStore. The content search is a
 * callback: the tests decide which chunk a gist retrieves.
 */
class InMemoryMapChunkStore implements ChunkStoreInterface, ChunkMapStoreInterface
{
    public const DEFAULT_COLLECTION = '__default__';

    /** @var array<string, array<string, list<FileChunk>>> collection => file id => chunks */
    public array $files = [];

    /** @var list<array{query: string, limit: int, filters: array, collection: ?string}> */
    public array $searches = [];

    /** @var list<array{file_id: int|string, chunk_index: int, map: array, collection: ?string}> */
    public array $writes = [];

    /** @var (callable(string $query, int $limit, array $filters, ?string $collection, self $store): array)|null */
    public $search = null;

    /** Chunk indexes whose setChunkMap() reports false. */
    public array $failWritesFor = [];

    /** @param list<FileChunk> $chunks */
    public function put(int|string $fileId, array $chunks, ?string $collection = null): void
    {
        $this->files[$collection ?? self::DEFAULT_COLLECTION][(string) $fileId] = array_values($chunks);
    }

    public function chunk(int|string $fileId, int $chunkIndex, ?string $collection = null): ?FileChunk
    {
        foreach ($this->getFileChunks($fileId, $collection) as $chunk) {
            if ($chunk->chunkIndex === $chunkIndex) {
                return $chunk;
            }
        }

        return null;
    }

    public function setChunkMap(FileChunk $chunk, array $map, ?string $collection = null): bool
    {
        if (in_array($chunk->chunkIndex, $this->failWritesFor, true)) {
            return false;
        }

        $key = $collection ?? self::DEFAULT_COLLECTION;
        foreach ($this->files[$key][(string) $chunk->fileId] ?? [] as $n => $stored) {
            if ($stored->chunkIndex !== $chunk->chunkIndex) {
                continue;
            }
            $this->files[$key][(string) $chunk->fileId][$n] = new FileChunk(
                $stored->fileId,
                $stored->fileName,
                $stored->content,
                $stored->embedding,
                $stored->chunkIndex,
                $stored->totalChunks,
                $stored->startPosition,
                $stored->endPosition,
                ['map' => $map] + $stored->metadata
            );
            $this->writes[] = ['file_id' => $chunk->fileId, 'chunk_index' => $chunk->chunkIndex, 'map' => $map, 'collection' => $collection];

            return true;
        }

        return false;
    }

    public function storeChunk(FileChunk $chunk, ?string $collection = null): bool
    {
        return $this->storeChunks([$chunk], $collection);
    }

    public function storeChunks(array $chunks, ?string $collection = null): bool
    {
        foreach ($chunks as $chunk) {
            $this->files[$collection ?? self::DEFAULT_COLLECTION][(string) $chunk->fileId][] = $chunk;
        }

        return true;
    }

    public function searchByContent(string $query, int $limit = 10, array $filters = [], ?string $collection = null): array
    {
        $this->searches[] = ['query' => $query, 'limit' => $limit, 'filters' => $filters, 'collection' => $collection];

        return $this->search !== null ? ($this->search)($query, $limit, $filters, $collection, $this) : [];
    }

    public function getFileChunks(int|string $fileId, ?string $collection = null): array
    {
        return $this->files[$collection ?? self::DEFAULT_COLLECTION][(string) $fileId] ?? [];
    }

    public function deleteFileChunks(int|string $fileId, ?string $collection = null): bool
    {
        unset($this->files[$collection ?? self::DEFAULT_COLLECTION][(string) $fileId]);

        return true;
    }

    public function getChunkCount(array $filters = []): int
    {
        return 0;
    }

    public function hasFileChunks(int|string $fileId, ?string $collection = null): bool
    {
        return $this->getFileChunks($fileId, $collection) !== [];
    }

    public function getChunk(string $vectorId): ?FileChunk
    {
        return null;
    }

    public function searchByFilename(string $filename, int $limit = 10, array $filters = [], ?string $collection = null): array
    {
        return [];
    }
}
