<?php

namespace Condoedge\Ai\Domain\ValueObjects;

/**
 * GraphConfig - Configuration for Neo4j graph storage
 *
 * Defines how an entity should be stored in Neo4j:
 * - Node label (like a table name)
 * - Properties to store
 * - Relationships to other nodes
 */
class GraphConfig
{
    /**
     * @param string $label Node label (e.g., "Customer", "Person", "Order")
     * @param array $properties List of property names to store (e.g., ['id', 'name', 'email'])
     * @param RelationshipConfig[] $relationships Array of relationship configurations
     * @param array<string, string|callable> $computed Graph property => model accessor/method
     *        name (string) or callable fn($model). Evaluated at ingest by GraphPropertyMapper.
     * @param string[] $with Relations eager-loaded before computed properties are evaluated
     *        (ingest chunk queries use ->with(), single-model sync uses loadMissing()).
     * @param array<string, string> $casts Graph property => forced graph type
     *        (string|int|float|bool|date). Absent = today's behaviour (no cast).
     */
    public function __construct(
        public readonly string $label,
        public readonly array $properties,
        public readonly array $relationships = [],
        public readonly array $computed = [],
        public readonly array $with = [],
        public readonly array $casts = [],
    ) {
        if (empty($this->label)) {
            throw new \InvalidArgumentException('Graph label cannot be empty');
        }

        if (empty($this->properties)) {
            throw new \InvalidArgumentException('Graph properties cannot be empty');
        }

        foreach ($this->relationships as $relationship) {
            if (!$relationship instanceof RelationshipConfig) {
                throw new \InvalidArgumentException(
                    'All relationships must be instances of RelationshipConfig'
                );
            }
        }
    }

    /**
     * Create from array configuration
     *
     * @param array $config ['label' => '...', 'properties' => [...], 'relationships' => [...]]
     * @return self
     */
    public static function fromArray(array $config): self
    {
        $relationships = [];
        foreach ($config['relationships'] ?? [] as $relationship) {
            $relationships[] = is_array($relationship)
                ? RelationshipConfig::fromArray($relationship)
                : $relationship;
        }

        $computed = [];
        foreach ((array) ($config['computed'] ?? []) as $property => $source) {
            if (is_string($property) && $property !== '' && (is_string($source) || is_callable($source))) {
                $computed[$property] = $source;
            }
        }

        $with = array_values(array_filter(
            (array) ($config['with'] ?? []),
            static fn ($relation) => is_string($relation) && $relation !== ''
        ));

        $casts = [];
        foreach ((array) ($config['casts'] ?? []) as $property => $type) {
            if (is_string($property) && is_string($type) && $type !== '') {
                $casts[$property] = $type;
            }
        }

        return new self(
            label: $config['label'],
            properties: $config['properties'],
            relationships: array_filter($relationships),
            computed: $computed,
            with: $with,
            casts: $casts,
        );
    }

    /**
     * Get all relationships
     *
     * @return RelationshipConfig[]
     */
    public function getRelationships(): array
    {
        return $this->relationships;
    }

    /**
     * Check if a relationship exists for a given foreign key
     */
    public function hasRelationship(string $foreignKey): bool
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship->foreignKey === $foreignKey) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get relationship by foreign key
     */
    public function getRelationship(string $foreignKey): ?RelationshipConfig
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship->foreignKey === $foreignKey) {
                return $relationship;
            }
        }
        return null;
    }
}
