<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReflectionClass;
use ReflectionMethod;

/**
 * RelationshipDiscoverer
 *
 * Discovers Eloquent relationships from model methods and converts them
 * to Neo4j relationship format. Analyzes model methods that return
 * Relation instances and enhances with foreign key hints from schema.
 *
 * Usage:
 *   $discoverer = new RelationshipDiscoverer($schemaInspector);
 *   $relationships = $discoverer->discover($customer);
 *   // [
 *   //   ['type' => 'PLACED', 'target_label' => 'Order', 'foreign_key' => 'customer_id'],
 *   //   ...
 *   // ]
 *
 * @package Condoedge\Ai\Services\Discovery
 */
class RelationshipDiscoverer
{
    /**
     * Track models being discovered to prevent infinite recursion
     */
    private array $discoveryStack = [];

    /**
     * Maximum stack depth to prevent runaway recursion
     */
    private const MAX_STACK_DEPTH = 5;

    /**
     * Create a new relationship discoverer
     *
     * @param SchemaInspector|null $schema Schema inspector for foreign key hints
     */
    public function __construct(
        private ?SchemaInspector $schema = null
    ) {}

    /**
     * Discover relationships from a model with recursion protection
     *
     * @param string|Model $model Model class name or instance
     * @return array<int, array{type: string, target_label: string, foreign_key?: string, inverse?: bool}>
     * @throws \RuntimeException If maximum recursion depth exceeded
     */
    public function discover(string|Model $model): array
    {
        $modelInstance = $this->resolveModel($model);
        $modelClass = get_class($modelInstance);

        // Recursion guard: check if we're already discovering this model
        if (isset($this->discoveryStack[$modelClass])) {
            // Circular reference detected - return empty to break cycle
            return [];
        }

        // Stack depth guard: prevent runaway recursion
        if (count($this->discoveryStack) >= self::MAX_STACK_DEPTH) {
            throw new \RuntimeException(
                "Maximum relationship discovery depth (" . self::MAX_STACK_DEPTH . ") exceeded. " .
                "Possible circular relationship structure in models."
            );
        }

        // Add model to stack
        $this->discoveryStack[$modelClass] = true;

        try {
            // Get relationships from Eloquent methods
            $relationships = $this->fromEloquentMethods($modelInstance);

            // Enhance with foreign key hints if schema inspector available
            if ($this->schema !== null) {
                try {
                    $table = $modelInstance->getTable();
                    if ($table !== null) {
                        $foreignKeys = $this->schema->getForeignKeys($table);
                        $relationships = $this->enhanceWithForeignKeys($relationships, $foreignKeys, $modelInstance);
                    }
                } catch (\Throwable $e) {
                    // Skip schema enhancement if table is not configured
                }
            }

            return array_values($relationships);
        } finally {
            // Always remove from stack when done, even if exception thrown
            unset($this->discoveryStack[$modelClass]);
        }
    }

    /**
     * Get relationships from Eloquent methods
     *
     * @param Model $model Model instance
     * @return array Discovered relationships
     */
    private function fromEloquentMethods(Model $model): array
    {
        $relationships = [];
        $reflectionClass = new ReflectionClass($model);

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip magic methods, constructors, and inherited methods
            if ($this->shouldSkipMethod($method, $model)) {
                continue;
            }

            try {
                $relation = $method->invoke($model);

                // Check if it's an Eloquent relation
                if (!$this->isRelation($relation)) {
                    continue;
                }

                // Convert to Neo4j relationship config
                $relationshipConfig = $this->convertToNeo4jRelationship(
                    $method->getName(),
                    $relation
                );

                if ($relationshipConfig) {
                    $relationships[$method->getName()] = $relationshipConfig;
                }
            } catch (\Throwable $e) {
                // Skip methods that throw exceptions
                continue;
            }
        }

        return $relationships;
    }

    /**
     * Enhance relationships with foreign key hints
     *
     * @param array $relationships Discovered relationships
     * @param array $foreignKeys Foreign keys from schema
     * @param Model $model Model instance
     * @return array Enhanced relationships
     */
    private function enhanceWithForeignKeys(array $relationships, array $foreignKeys, Model $model): array
    {
        foreach ($foreignKeys as $column => $foreignKey) {
            // Skip if we already have a relationship for this foreign key
            $existingRelationship = array_filter($relationships, function ($rel) use ($column) {
                return ($rel['foreign_key'] ?? null) === $column;
            });

            if (!empty($existingRelationship)) {
                continue;
            }

            // Infer relationship from foreign key
            $tableName = $foreignKey['table'];
            $targetLabel = $this->tableNameToLabel($tableName);
            $relationshipType = $this->inferRelationshipType($column, $tableName);

            $relationships[$column] = [
                'type' => $relationshipType,
                'target_label' => $targetLabel,
                'foreign_key' => $column,
                'inferred' => true,
            ];
        }

        return $relationships;
    }

    /**
     * Should skip this method during reflection
     *
     * @param ReflectionMethod $method Method to check
     * @param Model $model Model instance
     * @return bool
     */
    private function shouldSkipMethod(ReflectionMethod $method, Model $model): bool
    {
        $methodName = $method->getName();

        // Skip static, abstract, constructor
        if ($method->isStatic() || $method->isAbstract() || $methodName === '__construct') {
            return true;
        }

        // Only process methods defined in this model class
        if ($method->getDeclaringClass()->getName() !== get_class($model)) {
            return true;
        }

        // Skip methods with parameters (relationships don't take params)
        if ($method->getNumberOfParameters() > 0) {
            return true;
        }

        // Skip magic methods
        if (str_starts_with($methodName, '__')) {
            return true;
        }

        // Skip scope methods
        if (str_starts_with($methodName, 'scope')) {
            return true;
        }

        // Skip getters/setters
        if (str_starts_with($methodName, 'get') || str_starts_with($methodName, 'set')) {
            return true;
        }

        return false;
    }

    /**
     * Check if an object is an Eloquent relation
     *
     * @param mixed $object Object to check
     * @return bool
     */
    private function isRelation($object): bool
    {
        return $object instanceof \Illuminate\Database\Eloquent\Relations\Relation;
    }

    /**
     * Convert Eloquent relationship to Neo4j relationship config
     *
     * @param string $methodName Relationship method name
     * @param \Illuminate\Database\Eloquent\Relations\Relation $relation Eloquent relation
     * @return array|null Relationship config or null if unsupported
     */
    private function convertToNeo4jRelationship(string $methodName, $relation): ?array
    {
        $relatedModel = $relation->getRelated();
        $targetLabel = class_basename($relatedModel);

        // Convert method name to Neo4j relationship type
        // e.g., 'customer' -> 'CUSTOMER', 'orders' -> 'ORDERS'
        $type = strtoupper($this->toSnakeCase($methodName));

        if ($relation instanceof BelongsTo) {
            // Outbound relationship (this model → related model)
            // e.g., Order->customer() : Order-[CUSTOMER]->Customer
            return [
                'type' => $type,
                'target_label' => $targetLabel,
                'foreign_key' => $relation->getForeignKeyName(),
            ];
        }

        if ($relation instanceof HasMany || $relation instanceof HasOne) {
            // Inbound relationship (related model → this model)
            // We mark these as inverse to handle them differently
            return [
                'type' => $type,
                'target_label' => $targetLabel,
                'inverse' => true,
            ];
        }

        if ($relation instanceof BelongsToMany) {
            // Many-to-many relationship via pivot table
            // e.g., User->roles() : User-[ROLES]->Role
            return [
                'type' => $type,
                'target_label' => $targetLabel,
                'pivot' => $relation->getTable(),
            ];
        }

        // Skip unsupported relationship types (MorphTo, etc.)
        return null;
    }

    /**
     * Convert camelCase to snake_case
     *
     * @param string $string Input string
     * @return string Snake case string
     */
    private function toSnakeCase(string $string): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
    }

    /**
     * Convert table name to label
     *
     * @param string $tableName Table name
     * @return string Label
     */
    private function tableNameToLabel(string $tableName): string
    {
        // Convert to singular and StudlyCase
        $singular = \Illuminate\Support\Str::singular($tableName);
        $label = \Illuminate\Support\Str::studly($singular);

        // Remove 'Test' prefix if present
        if (str_starts_with($label, 'Test')) {
            $label = substr($label, 4);
        }

        return $label;
    }

    /**
     * Infer relationship type from foreign key column name
     *
     * @param string $column Foreign key column name
     * @param string $tableName Target table name
     * @return string Relationship type
     */
    private function inferRelationshipType(string $column, string $tableName): string
    {
        // Remove _id suffix
        $baseName = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;

        // Convert to UPPER_SNAKE_CASE
        $type = strtoupper($this->toSnakeCase($baseName));

        return "BELONGS_TO_{$type}";
    }

    /**
     * Resolve model to instance
     *
     * @param string|Model $model Model class name or instance
     * @return Model Model instance
     */
    private function resolveModel(string|Model $model): Model
    {
        if (is_string($model)) {
            return new $model();
        }

        return $model;
    }
}
