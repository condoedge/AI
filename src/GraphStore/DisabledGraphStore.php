<?php

declare(strict_types=1);

namespace Condoedge\Ai\GraphStore;

use Condoedge\Ai\Contracts\GraphStoreInterface;

/**
 * The graph store while the master switch is off (AI_ENABLED=false): no connection, nothing
 * written, nothing found, nothing thrown. See AiFeature.
 */
final class DisabledGraphStore implements GraphStoreInterface
{
    public function createNode(string $label, array $properties): string|int
    {
        return $properties['id'] ?? 0;
    }

    public function updateNode(string $label, string|int $id, array $properties): bool
    {
        return false;
    }

    public function deleteNode(string $label, string|int $id): bool
    {
        return false;
    }

    public function createRelationship(
        string $fromLabel,
        string|int $fromId,
        string $toLabel,
        string|int $toId,
        string $type,
        array $properties = []
    ): bool {
        return false;
    }

    public function deleteRelationship(
        string $fromLabel,
        string|int $fromId,
        string $toLabel,
        string|int $toId,
        string $type
    ): bool {
        return false;
    }

    public function query(string $cypher, array $parameters = []): array
    {
        return [];
    }

    public function getSchema(): array
    {
        return ['labels' => [], 'relationshipTypes' => [], 'relationshipPatterns' => [], 'propertyKeys' => []];
    }

    public function nodeExists(string $label, string|int $id): bool
    {
        return false;
    }

    public function relationshipExists(
        string $fromLabel,
        string|int $fromId,
        string $toLabel,
        string|int $toId,
        string $type
    ): bool {
        return false;
    }

    public function getNode(string $label, string|int $id): ?array
    {
        return null;
    }
}
