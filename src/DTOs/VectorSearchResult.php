<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

/**
 * Normalized vector search result from Qdrant.
 * Handles the payload/metadata inconsistency in one place.
 */
class VectorSearchResult
{
    public readonly string $type;
    public readonly float $score;
    public readonly ?string $entityName;
    public readonly string $text;
    public readonly string $relationshipKey;
    public readonly string $fromEntity;
    public readonly string $toEntity;
    public readonly string $relationshipType;
    public readonly ?string $foreignKey;
    public readonly string $direction;
    public readonly string $scopeKey;
    public readonly ?string $property;

    public function __construct(array $raw)
    {
        // Normalize: Qdrant returns 'payload', some code expects 'metadata'
        $meta = $raw['metadata'] ?? $raw['payload'] ?? [];

        $this->score = (float) ($raw['score'] ?? 0);
        $this->type = $meta['type'] ?? 'unknown';
        $this->entityName = $meta['entity'] ?? null;
        $this->text = $meta['text'] ?? '';
        $this->relationshipKey = $meta['relationship_key'] ?? '';
        $this->fromEntity = $meta['from_entity'] ?? '';
        $this->toEntity = $meta['to_entity'] ?? '';
        $this->relationshipType = $meta['relationship_type'] ?? '';
        $this->foreignKey = $meta['foreign_key'] ?? null;
        $this->direction = $meta['direction'] ?? 'outgoing';
        $this->scopeKey = $meta['scope_key'] ?? '';
        $this->property = $meta['property'] ?? null;
    }
}
