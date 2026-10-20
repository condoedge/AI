<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Condoedge\Ai\Contracts\ChunkMapLookupInterface;

/**
 * The in-memory map store with the LOOKUP capability: the stored records of a
 * collection by the hash of the text they were written from (document-map
 * tests of "one map for identical text").
 */
class LookupMapChunkStore extends InMemoryMapChunkStore implements ChunkMapLookupInterface
{
    /** @var list<array{hashes: list<string>, collection: ?string}> */
    public array $lookups = [];

    public bool $failLookups = false;

    public function findChunkMaps(array $hashes, ?string $collection = null): array
    {
        $this->lookups[] = ['hashes' => array_values($hashes), 'collection' => $collection];
        if ($this->failLookups) {
            throw new \RuntimeException('store down');
        }

        $found = [];
        foreach ($this->files[$collection ?? self::DEFAULT_COLLECTION] ?? [] as $chunks) {
            foreach ($chunks as $chunk) {
                $record = $chunk->metadata['map'] ?? null;
                if (is_array($record) && in_array($record['hash'] ?? null, $hashes, true)) {
                    $found[$record['hash']][] = $record;
                }
            }
        }

        return $found;
    }
}
