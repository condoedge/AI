<?php

declare(strict_types=1);

namespace Condoedge\Ai\VectorStore;

use Condoedge\Ai\Contracts\VectorStoreInterface;

/**
 * The vector store while the master switch is off (AI_ENABLED=false): no connection, nothing
 * written, nothing found, nothing thrown. See AiFeature.
 */
final class DisabledVectorStore implements VectorStoreInterface
{
    public function createCollection(string $name, int $vectorSize, string $distance = 'cosine'): bool
    {
        return false;
    }

    public function collectionExists(string $name): bool
    {
        return false;
    }

    public function deleteCollection(string $name): bool
    {
        return false;
    }

    public function upsert(string $collection, array $points): bool
    {
        return false;
    }

    public function listCollections(): array
    {
        return [];
    }

    public function search(
        string $collection,
        array $vector,
        int $limit = 10,
        array $filter = [],
        float $scoreThreshold = 0.0
    ): array {
        return [];
    }

    public function getPoint(string $collection, string|int $id): ?array
    {
        return null;
    }

    public function deletePoints(string $collection, array $ids): bool
    {
        return false;
    }

    public function getCollectionInfo(string $name): array
    {
        return [];
    }

    public function count(string $collection, array $filter = []): int
    {
        return 0;
    }

    public function ensurePayloadIndex(string $collection, string $field, string $schema = 'keyword'): bool
    {
        return false;
    }

    public function scroll(string $collection, array $filter = [], int $limit = 100, bool $withPayload = true): array
    {
        return [];
    }

    public function ensureCollection(string $name, int $vectorSize, string $distance = 'cosine'): void
    {
    }

    public function deleteAll(string $collection): bool
    {
        return false;
    }

    public function upsertBatch(string $collection, array $points): bool
    {
        return false;
    }
}
