<?php

namespace Condoedge\Ai\Domain\ValueObjects;

/**
 * RelationshipConfig - Configuration for Neo4j relationships
 *
 * Defines how entities are connected in the graph:
 * - Relationship type (e.g., "MEMBER_OF", "PURCHASED")
 * - Target node label
 * - Foreign key field
 * - Optional relationship properties
 */
class RelationshipConfig
{
    /**
     * @param string $type Relationship type (e.g., "MEMBER_OF", "PURCHASED", "BELONGS_TO")
     * @param string $targetLabel Target node label (e.g., "Team", "Order", "Category")
     * @param string $foreignKey Foreign key field name (e.g., "team_id", "order_id")
     * @param array $properties Additional properties on the relationship (e.g., ['since' => 'created_at'])
     */
    public function __construct(
        public readonly string $type,
        public readonly string $targetLabel,
        public readonly string $foreignKey,
        public readonly array $properties = []
    ) {
        if (empty($this->type)) {
            throw new \InvalidArgumentException('Relationship type cannot be empty');
        }

        if (empty($this->targetLabel)) {
            throw new \InvalidArgumentException('Target label cannot be empty');
        }

        if (empty($this->foreignKey)) {
            throw new \InvalidArgumentException('Foreign key cannot be empty');
        }
    }

    /**
     * Create from array configuration
     *
     * @param array $config ['type' => '...', 'target_label' => '...', 'foreign_key' => '...', 'properties' => [...]]
     * @return self|null
     */
    public static function fromArray(array $config): ?self
    {
        $foreignKey = $config['foreign_key'] ?? $config['foreignKey'] ?? null;

        if (empty($foreignKey)) {
            return null;
        }

        return new self(
            type: $config['type'],
            targetLabel: $config['target_label'] ?? $config['targetLabel'],
            foreignKey: $config['foreign_key'] ?? $config['foreignKey'] ?? null,
            properties: $config['properties'] ?? []
        );
    }

    /**
     * Check if this relationship has properties
     */
    public function hasProperties(): bool
    {
        return !empty($this->properties);
    }
}
