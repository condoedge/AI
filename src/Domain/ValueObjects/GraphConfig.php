<?php

namespace AiSystem\Domain\ValueObjects;

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
     */
    public function __construct(
        public readonly string $label,
        public readonly array $properties,
        public readonly array $relationships = []
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

        return new self(
            label: $config['label'],
            properties: $config['properties'],
            relationships: $relationships
        );
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
