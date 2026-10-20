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
     * Sentinel target label emitted by discovery for MorphTo relationships:
     * the concrete target label is only knowable per row, from the morph-type
     * column value (see morphTypeColumn() and PolymorphicTargetResolver).
     */
    public const POLYMORPHIC_TARGET = '_polymorphic';

    /**
     * @param string $type Relationship type (e.g., "MEMBER_OF", "PURCHASED", "BELONGS_TO")
     * @param string $targetLabel Target node label (e.g., "Team", "Order", "Category")
     * @param string $foreignKey Foreign key field name (e.g., "team_id", "order_id")
     * @param array $properties Additional properties on the relationship (e.g., ['since' => 'created_at'])
     * @param string|null $morph Morph-type column ("priceable_type") or base morph name ("priceable")
     */
    public function __construct(
        public readonly string $type,
        public readonly string $targetLabel,
        public readonly string $foreignKey,
        public readonly array $properties = [],
        public readonly ?string $morph = null
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
            properties: $config['properties'] ?? [],
            morph: $config['morph'] ?? null
        );
    }

    /**
     * Check if this relationship has properties
     */
    public function hasProperties(): bool
    {
        return !empty($this->properties);
    }

    /**
     * Whether the target label is the polymorphic sentinel — the concrete
     * label must then be resolved per row from the morph-type column.
     */
    public function isPolymorphic(): bool
    {
        return $this->targetLabel === self::POLYMORPHIC_TARGET;
    }

    /**
     * Full morph-type column name, e.g. 'priceable_type'.
     */
    public function morphTypeColumn(): string
    {
        if ($this->morph !== null) {
            // MorphTo discovery stores the full column name ('priceable_type');
            // MorphMany/MorphOne/MorphToMany store the base morph name
            // ('priceable'). Normalize both to the column form.
            return str_ends_with($this->morph, '_type') ? $this->morph : $this->morph . '_type';
        }

        // Convention fallback — required because existing host configs carry
        // no 'morph' key: priceable_id -> priceable_type, parent_id -> parent_type.
        return str_ends_with($this->foreignKey, '_id')
            ? substr($this->foreignKey, 0, -3) . '_type'
            : $this->foreignKey . '_type';
    }
}
