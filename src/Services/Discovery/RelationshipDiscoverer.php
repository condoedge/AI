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
use Illuminate\Support\Str;
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
     * Traversal scope generator for detecting discriminators
     */
    private ?TraversalScopeGenerator $traversalGenerator = null;

    /**
     * Create a new relationship discoverer
     *
     * @param SchemaInspector|null $schema Schema inspector for foreign key hints
     * @param TraversalScopeGenerator|null $traversalGenerator Traversal scope generator
     */
    public function __construct(
        private ?SchemaInspector $schema = null,
        ?TraversalScopeGenerator $traversalGenerator = null
    ) {
        $this->traversalGenerator = $traversalGenerator ?? new TraversalScopeGenerator();
    }

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
                    // Enhance with discriminator information
                    $relationshipConfig = $this->enhanceWithDiscriminatorInfo(
                        $relationshipConfig,
                        $relation
                    );

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
            $sourceLabel = class_basename($relation->getParent());
            return [
                'type' => $type,
                'target_label' => $targetLabel,
                'foreign_key' => $relation->getForeignKeyName(),
                'direction' => 'outgoing',
                'inverse_type' => 'HAS_' . strtoupper($this->toSnakeCase(Str::plural($sourceLabel))),
            ];
        }

        if ($relation instanceof HasMany || $relation instanceof HasOne) {
            // Inbound relationship (related model → this model)
            // We mark these as inverse to handle them differently
            $sourceLabel = class_basename($relation->getParent());
            return [
                'type' => $type,
                'target_label' => $targetLabel,
                'inverse' => true,
                'direction' => 'incoming',
                'inverse_type' => 'BELONGS_TO_' . strtoupper($this->toSnakeCase($sourceLabel)),
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
     * Discover bidirectional relationships from a model
     *
     * For every relationship discovered, this creates both the original
     * relationship AND its inverse. For example:
     * - Order->customer() creates both Order-[CUSTOMER]->Customer
     *   and Customer-[HAS_ORDERS]->Order
     *
     * @param string|Model $model Model class name or instance
     * @return array Bidirectional relationships
     */
    public function discoverBidirectional(string|Model $model): array
    {
        $relationships = $this->discover($model);
        $bidirectional = [];

        foreach ($relationships as $rel) {
            // Add original relationship
            $originalName = $this->generateRelationshipName($rel);
            $bidirectional[$originalName] = $rel;

            // Add inverse relationship if applicable
            if (isset($rel['inverse_type']) && isset($rel['direction'])) {
                $inverseName = $this->generateInverseName($rel);
                if (!isset($bidirectional[$inverseName])) {
                    $bidirectional[$inverseName] = $this->createInverseRelationship($rel);
                }
            }
        }

        return array_values($bidirectional);
    }

    /**
     * Generate a unique name for a relationship
     *
     * @param array $relationship Relationship config
     * @return string Relationship name
     */
    private function generateRelationshipName(array $relationship): string
    {
        $type = strtolower($relationship['type']);
        $target = strtolower($relationship['target_label']);
        return "{$type}_to_{$target}";
    }

    /**
     * Generate inverse relationship name
     *
     * @param array $relationship Original relationship config
     * @return string Inverse relationship name
     */
    private function generateInverseName(array $relationship): string
    {
        $inverseType = strtolower($relationship['inverse_type']);

        // For outgoing relationships, the inverse goes back to the source
        // For incoming relationships, the inverse goes to the target
        if ($relationship['direction'] === 'outgoing') {
            // BelongsTo: Order-[CUSTOMER]->Customer
            // Inverse: Customer-[HAS_ORDERS]->Order
            $sourceLabel = class_basename($relationship['target_label']);
            return "{$inverseType}_from_{$sourceLabel}";
        } else {
            // HasMany: Customer-[ORDERS]->Order (marked as inverse)
            // Actual inverse: Order-[BELONGS_TO_CUSTOMER]->Customer
            $targetLabel = strtolower($relationship['target_label']);
            return "{$inverseType}_to_{$targetLabel}";
        }
    }

    /**
     * Create inverse relationship config
     *
     * @param array $relationship Original relationship config
     * @return array Inverse relationship config
     */
    private function createInverseRelationship(array $relationship): array
    {
        $inverse = [
            'type' => $relationship['inverse_type'],
            'target_label' => $relationship['target_label'],
            'is_inverse' => true,
        ];

        // Flip the direction
        if ($relationship['direction'] === 'outgoing') {
            $inverse['direction'] = 'incoming';
        } else {
            $inverse['direction'] = 'outgoing';
        }

        // Copy foreign key if present (for BelongsTo inverse)
        if (isset($relationship['foreign_key']) && $relationship['direction'] === 'outgoing') {
            $inverse['foreign_key'] = $relationship['foreign_key'];
        }

        return $inverse;
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

    /**
     * Enhance relationship with discriminator field information
     *
     * Detects if the target model has discriminator fields and includes
     * that information in the relationship config.
     *
     * @param array $relationshipConfig Current relationship config
     * @param \Illuminate\Database\Eloquent\Relations\Relation $relation Eloquent relation
     * @return array Enhanced relationship config
     */
    private function enhanceWithDiscriminatorInfo(
        array $relationshipConfig,
        $relation
    ): array {
        try {
            $relatedModel = $relation->getRelated();
            $relatedClass = get_class($relatedModel);

            // Get properties of the related model
            $properties = $this->getModelProperties($relatedModel);

            // Detect discriminator fields
            if ($this->traversalGenerator) {
                $discriminatorFields = $this->traversalGenerator->detectDiscriminatorFields($properties);

                if (!empty($discriminatorFields)) {
                    $relationshipConfig['discriminator_fields'] = $discriminatorFields;
                    $relationshipConfig['related_model'] = $relatedClass;
                }
            }
        } catch (\Throwable $e) {
            // If detection fails, continue without discriminator info
        }

        return $relationshipConfig;
    }

    /**
     * Get properties from a model instance
     *
     * @param Model $model Model instance
     * @return array List of property names
     */
    private function getModelProperties(Model $model): array
    {
        $properties = [];

        // Get fillable attributes
        try {
            $fillable = $model->getFillable();
            if (!empty($fillable)) {
                $properties = array_merge($properties, $fillable);
            }
        } catch (\Throwable $e) {
            // Skip if model doesn't support fillable
        }

        // Get casted attributes
        try {
            $casts = $model->getCasts();
            if (!empty($casts)) {
                $properties = array_merge($properties, array_keys($casts));
            }
        } catch (\Throwable $e) {
            // Skip if model doesn't support casts
        }

        return array_unique($properties);
    }

    /**
     * Detect discriminator fields in related model
     *
     * Public method to detect discriminator fields for a given relationship.
     *
     * @param string|Model $model Model class name or instance
     * @param string $relationshipName Relationship method name
     * @return array Discriminator information or empty array
     */
    public function detectDiscriminatorInRelation(
        string|Model $model,
        string $relationshipName
    ): array {
        $modelInstance = $this->resolveModel($model);

        try {
            // Get the relationship
            $relation = $modelInstance->$relationshipName();

            if (!$this->isRelation($relation)) {
                return [];
            }

            $relatedModel = $relation->getRelated();
            $properties = $this->getModelProperties($relatedModel);

            if (!$this->traversalGenerator) {
                return [];
            }

            $discriminatorFields = $this->traversalGenerator->detectDiscriminatorFields($properties);

            if (empty($discriminatorFields)) {
                return [];
            }

            return [
                'fields' => $discriminatorFields,
                'related_model' => get_class($relatedModel),
                'relationship_type' => strtoupper($this->toSnakeCase($relationshipName)),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
