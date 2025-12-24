<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Support\Str;

/**
 * TraversalScopeGenerator
 *
 * Generates traversal scopes on source entities based on relationships
 * to target entities with discriminator fields. For example, when PersonTeam
 * has a role_type field, this generates "volunteers", "scouts" scopes on Person.
 *
 * Usage:
 *   $generator = new TraversalScopeGenerator();
 *   $scopes = $generator->generateFromRelationship(
 *       'Person',
 *       'PersonTeam',
 *       'BELONGS_TO_PERSON',
 *       ['field' => 'role_type', 'values' => [3 => 'volunteers', 4 => 'scouts']]
 *   );
 *
 * @package Condoedge\Ai\Services\Discovery
 */
class TraversalScopeGenerator
{
    /**
     * Known discriminator fields to look for in related models
     */
    private const DISCRIMINATOR_FIELDS = [
        'role_type',
        'type',
        'status',
        'kind',
        'category',
        'role',
    ];

    /**
     * Generate traversal scopes from a relationship with discriminator
     *
     * @param string $sourceEntity Source entity name (e.g., 'Person')
     * @param string $targetEntity Target entity name (e.g., 'PersonTeam')
     * @param string $relationshipType Neo4j relationship type (e.g., 'BELONGS_TO_PERSON')
     * @param array $discriminatorInfo Discriminator field information
     * @return array Generated scope configurations
     */
    public function generateFromRelationship(
        string $sourceEntity,
        string $targetEntity,
        string $relationshipType,
        array $discriminatorInfo
    ): array {
        $discriminatorField = $discriminatorInfo['field'] ?? null;
        $valueMappings = $discriminatorInfo['values'] ?? [];

        if (!$discriminatorField || empty($valueMappings)) {
            return [];
        }

        $scopes = [];

        foreach ($valueMappings as $value => $scopeName) {
            $scopes[$scopeName] = $this->generateScope(
                $sourceEntity,
                $targetEntity,
                $relationshipType,
                $discriminatorField,
                $value,
                $scopeName
            );
        }

        return $scopes;
    }

    /**
     * Generate a single traversal scope
     *
     * @param string $sourceEntity Source entity name
     * @param string $targetEntity Target entity name
     * @param string $relationshipType Relationship type
     * @param string $discriminatorField Discriminator field name
     * @param mixed $value Discriminator value
     * @param string $scopeName Scope name
     * @return array Scope configuration
     */
    private function generateScope(
        string $sourceEntity,
        string $targetEntity,
        string $relationshipType,
        string $discriminatorField,
        mixed $value,
        string $scopeName
    ): array {
        // Generate Cypher pattern
        $cypherPattern = $this->generateCypherPattern(
            $sourceEntity,
            $targetEntity,
            $relationshipType,
            $discriminatorField,
            $value
        );

        // Generate concept description
        $concept = $this->generateConcept($sourceEntity, $scopeName);

        // Generate examples
        $examples = $this->generateExamples($sourceEntity, $scopeName);

        // Extract role value (human-readable form)
        $roleValue = $this->extractRoleValue($scopeName, $value);

        return [
            'specification_type' => 'relationship_traversal',
            'concept' => $concept,
            'cypher_pattern' => $cypherPattern,
            'role_value' => $roleValue,
            'examples' => $examples,
            'auto_generated' => true,
            'discriminator_field' => $discriminatorField,
            'discriminator_value' => $value,
        ];
    }

    /**
     * Generate Cypher pattern for the traversal scope
     *
     * @param string $sourceEntity Source entity name
     * @param string $targetEntity Target entity name
     * @param string $relationshipType Relationship type
     * @param string $discriminatorField Discriminator field name
     * @param mixed $value Discriminator value
     * @return string Cypher pattern
     */
    private function generateCypherPattern(
        string $sourceEntity,
        string $targetEntity,
        string $relationshipType,
        string $discriminatorField,
        mixed $value
    ): string {
        // Determine relationship direction based on naming convention
        $direction = $this->inferDirection($relationshipType);

        $targetVar = strtolower(substr($targetEntity, 0, 1));
        $formattedValue = $this->formatValue($value);

        if ($direction === 'incoming') {
            // Person<-[:BELONGS_TO_PERSON]-(p:PersonTeam)
            return "MATCH (n:{$sourceEntity})<-[:{$relationshipType}]-({$targetVar}:{$targetEntity}) " .
                   "WHERE {$targetVar}.{$discriminatorField} = {$formattedValue} " .
                   "RETURN DISTINCT n";
        } else {
            // Person-[:HAS_ROLE]->(p:PersonTeam)
            return "MATCH (n:{$sourceEntity})-[:{$relationshipType}]->({$targetVar}:{$targetEntity}) " .
                   "WHERE {$targetVar}.{$discriminatorField} = {$formattedValue} " .
                   "RETURN DISTINCT n";
        }
    }

    /**
     * Infer relationship direction from type name
     *
     * @param string $relationshipType Relationship type
     * @return string 'incoming' or 'outgoing'
     */
    private function inferDirection(string $relationshipType): string
    {
        // If relationship type contains "BELONGS_TO", it's typically incoming
        if (str_contains($relationshipType, 'BELONGS_TO')) {
            return 'incoming';
        }

        // Default to outgoing
        return 'outgoing';
    }

    /**
     * Format value for Cypher query
     *
     * @param mixed $value Value to format
     * @return string Formatted value
     */
    private function formatValue(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        // String - escape and quote
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /**
     * Generate concept description for the scope
     *
     * @param string $sourceEntity Source entity name
     * @param string $scopeName Scope name
     * @return string Concept description
     */
    private function generateConcept(string $sourceEntity, string $scopeName): string
    {
        $pluralEntity = Str::plural($sourceEntity);
        $readableName = str_replace('_', ' ', $scopeName);

        // Check if scope name is already plural and role-like
        $roleNames = ['volunteers', 'scouts', 'leaders', 'admins', 'members',
                      'managers', 'staff', 'customers', 'parents'];

        if (in_array($scopeName, $roleNames)) {
            return "{$pluralEntity} who are {$readableName}";
        }

        return "{$pluralEntity} with {$readableName} role";
    }

    /**
     * Generate example queries for the scope
     *
     * @param string $sourceEntity Source entity name
     * @param string $scopeName Scope name
     * @return array Example queries
     */
    private function generateExamples(string $sourceEntity, string $scopeName): array
    {
        $readableName = str_replace('_', ' ', $scopeName);
        $lowerEntity = strtolower(Str::plural($sourceEntity));
        $singularEntity = strtolower($sourceEntity);

        // Check if scope name is a role-like word
        $roleNames = ['volunteers', 'scouts', 'leaders', 'admins', 'members',
                      'managers', 'staff', 'customers', 'parents'];

        $isRoleName = in_array($scopeName, $roleNames);

        if ($isRoleName) {
            return [
                "Show all {$readableName}",
                "List {$readableName}",
                "Find {$readableName}",
                "How many {$readableName} are there?",
                "Who are the {$readableName}?",
                "Display {$lowerEntity} who are {$readableName}",
            ];
        }

        return [
            "Show {$lowerEntity} with {$readableName} role",
            "List {$lowerEntity} who are {$readableName}",
            "Find all {$readableName} {$lowerEntity}",
            "How many {$readableName} {$lowerEntity} are there?",
        ];
    }

    /**
     * Extract human-readable role value
     *
     * @param string $scopeName Scope name
     * @param mixed $value Discriminator value
     * @return string Role value
     */
    private function extractRoleValue(string $scopeName, mixed $value): string
    {
        // Use scope name as the role value (it's already human-readable)
        // Strip 's' suffix if plural
        $singular = Str::singular($scopeName);

        return $singular;
    }

    /**
     * Detect discriminator fields in a model
     *
     * Returns information about potential discriminator fields found in the model.
     *
     * @param array $properties Model properties
     * @return array Detected discriminator fields
     */
    public function detectDiscriminatorFields(array $properties): array
    {
        $found = [];

        foreach ($properties as $property) {
            if (in_array($property, self::DISCRIMINATOR_FIELDS)) {
                $found[] = $property;
            }
        }

        return $found;
    }

    /**
     * Get known discriminator field names
     *
     * @return array List of discriminator field names
     */
    public function getDiscriminatorFields(): array
    {
        return self::DISCRIMINATOR_FIELDS;
    }

    /**
     * Check if a field is a known discriminator
     *
     * @param string $field Field name
     * @return bool True if field is a discriminator
     */
    public function isDiscriminatorField(string $field): bool
    {
        return in_array($field, self::DISCRIMINATOR_FIELDS);
    }

    /**
     * Get role mappings from configuration for a specific entity
     *
     * @param string $entity Entity name
     * @param string $field Discriminator field name
     * @return array Role mappings (value => scope_name)
     */
    public function getRoleMappings(string $entity, string $field): array
    {
        $mappings = config("ai.auto_discovery.role_mappings.{$entity}.{$field}", []);

        if (empty($mappings)) {
            // Try to get default mappings based on field name
            return $this->getDefaultMappings($field);
        }

        return $mappings;
    }

    /**
     * Get default role mappings for common discriminator fields
     *
     * @param string $field Discriminator field name
     * @return array Default mappings
     */
    private function getDefaultMappings(string $field): array
    {
        // Return empty array - require explicit configuration
        // Could be extended with sensible defaults in the future
        return [];
    }
}
