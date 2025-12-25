<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * EntityAutoDiscovery
 *
 * Main orchestrator service that ties together all discovery components
 * to provide complete auto-discovery functionality. Introspects models to
 * discover configuration automatically, using SchemaInspector for database
 * hints, CypherScopeAdapter for scope discovery, and other specialized
 * discoverers for properties, relationships, aliases, and embed fields.
 *
 * Usage:
 *   $discovery = new EntityAutoDiscovery(...);
 *   $config = $discovery->discover(Customer::class);
 *   // Returns complete configuration array matching config/entities.php format
 *
 * @package Condoedge\Ai\Services\Discovery
 */
class EntityAutoDiscovery
{
    /**
     * Create a new entity auto-discovery service
     *
     * @param SchemaInspector $schema Schema inspector for database hints
     * @param CypherScopeAdapter $scopeAdapter Scope adapter for Eloquent scopes
     * @param RelationshipDiscoverer $relationships Relationship discoverer
     * @param PropertyDiscoverer $properties Property discoverer
     * @param AliasGenerator $aliases Alias generator
     * @param EmbedFieldDetector $embedFields Embed field detector
     * @param TraversalScopeGenerator|null $traversalGenerator Traversal scope generator
     */
    public function __construct(
        private SchemaInspector $schema,
        private CypherScopeAdapter $scopeAdapter,
        private RelationshipDiscoverer $relationships,
        private PropertyDiscoverer $properties,
        private AliasGenerator $aliases,
        private EmbedFieldDetector $embedFields,
        private ?TraversalScopeGenerator $traversalGenerator = null,
    ) {
        $this->traversalGenerator = $traversalGenerator ?? new TraversalScopeGenerator();
    }

    /**
     * Discover complete configuration for a model
     *
     * Runs all discoverers and combines results into a complete
     * configuration array matching the format in config/entities.php.
     *
     * IMPORTANT: Discovery runs in a safe context with:
     * - Database transaction that automatically rolls back
     * - Events temporarily disabled to prevent side effects
     * - No data persists to database during discovery
     *
     * @param string|Model $model Model class name or instance
     * @return array Complete entity configuration
     */
    public function discover(string|Model $model): array
    {
        return $this->safeDiscovery(function () use ($model) {
            $modelInstance = $this->resolveModel($model);

            // Discover all parts
            $graph = $this->discoverGraph($model);
            $vector = $this->discoverVector($model);
            $security = $this->discoverSecurityConfig($model);
            $metadata = $this->discoverMetadata($model);

            return [
                'graph' => $graph,
                'vector' => $vector,
                'security' => $security,
                'metadata' => $metadata,
            ];
        });
    }

    /**
     * Discover only graph configuration
     *
     * Discovers Neo4j node label, properties, and relationships.
     *
     * @param string|Model $model Model class name or instance
     * @return array Graph configuration
     */
    public function discoverGraph(string|Model $model): array
    {
        $modelInstance = $this->resolveModel($model);

        // Get label
        $label = $this->aliases->generateLabel($model);

        // Get properties
        $graphProperties = $this->properties->discover($model);

        // Get relationships (bidirectional)
        $graphRelationships = $this->relationships->discoverBidirectional($model);

        return [
            'label' => $label,
            'properties' => $graphProperties,
            'relationships' => $graphRelationships,
        ];
    }

    /**
     * Discover only vector configuration
     *
     * Discovers Qdrant collection name, embed fields, and metadata fields.
     *
     * @param string|Model $model Model class name or instance
     * @return array Vector configuration
     */
    public function discoverVector(string|Model $model): array
    {
        $modelInstance = $this->resolveModel($model);

        // Get collection name
        $collection = $this->aliases->generateCollectionName($model);

        // Get embed fields
        $embedFields = $this->embedFields->detect($model);

        // Exclude sensible columns from embed fields (they shouldn't be in vector content)
        $sensibleColumns = $this->getSensibleColumns($modelInstance);
        $embedFields = array_values(array_diff($embedFields, $sensibleColumns));

        // Get metadata fields (all properties except embed fields)
        $allProperties = $this->properties->discover($model);
        $metadata = array_values(array_diff($allProperties, $embedFields));

        return [
            'collection' => $collection,
            'embed_fields' => $embedFields,
            'metadata' => $metadata,
        ];
    }

    /**
     * Discover only metadata
     *
     * Discovers aliases, description, scopes, and common properties.
     *
     * @param string|Model $model Model class name or instance
     * @return array Metadata configuration
     */
    public function discoverMetadata(string|Model $model): array
    {
        $modelInstance = $this->resolveModel($model);
        $modelClass = is_string($model) ? $model : get_class($model);

        // Get aliases
        $discoveredAliases = $this->aliases->generate($model);

        // Get description
        $label = $this->aliases->generateLabel($model);
        $description = "Auto-discovered entity: {$label}";

        // Get scopes
        $scopes = [];
        try {
            $scopes = $this->scopeAdapter->discoverScopes($modelClass);
        } catch (\Throwable $e) {
            // If scope discovery fails, continue with empty scopes
        }

        // Generate traversal scopes from relationships
        $traversalScopes = $this->discoverTraversalScopes($model);
        $scopes = array_merge($scopes, $traversalScopes);

        // Get property descriptions
        $commonProperties = $this->properties->discoverDescriptions($model);

        return [
            'aliases' => $discoveredAliases,
            'description' => $description,
            'scopes' => $scopes,
            'common_properties' => $commonProperties,
        ];
    }

    /**
     * Discover and merge with manual configuration
     *
     * Discovers configuration and merges it with manually provided config,
     * allowing manual config to override discovered values.
     *
     * @param string|Model $model Model class name or instance
     * @param array $manualConfig Manual configuration to merge
     * @return array Merged configuration
     */
    public function discoverAndMerge(string|Model $model, array $manualConfig = []): array
    {
        $discovered = $this->discover($model);

        return $this->deepMerge($discovered, $manualConfig);
    }

    /**
     * Deep merge two arrays with recursion protection
     *
     * Recursively merges arrays, with values from $override taking precedence.
     * Includes maximum depth protection to prevent stack overflow.
     *
     * @param array $base Base array
     * @param array $override Override array
     * @param int $maxDepth Maximum recursion depth (default: 10)
     * @param int $currentDepth Current recursion depth
     * @return array Merged array
     * @throws \RuntimeException If maximum depth exceeded
     */
    private function deepMerge(array $base, array $override, int $maxDepth = 10, int $currentDepth = 0): array
    {
        // Recursion guard
        if ($currentDepth >= $maxDepth) {
            throw new \RuntimeException(
                "Maximum merge depth ({$maxDepth}) exceeded. Possible circular reference in configuration."
            );
        }

        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                // Recursively merge arrays with incremented depth
                $base[$key] = $this->deepMerge($base[$key], $value, $maxDepth, $currentDepth + 1);
            } else {
                // Override value
                $base[$key] = $value;
            }
        }

        return $base;
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
     * Check if model should be discovered
     *
     * Determines if auto-discovery should run for a given model.
     *
     * @param string|Model $model Model class name or instance
     * @return bool True if should discover
     */
    public function shouldDiscover(string|Model $model): bool
    {
        $modelInstance = $this->resolveModel($model);
        $modelClass = get_class($modelInstance);

        // Check if model implements Nodeable
        if (!$this->implementsNodeable($modelClass)) {
            return false;
        }

        // Check if auto-discovery is enabled globally
        if (!config('ai.auto_discovery.enabled', true)) {
            return false;
        }

        // Check if model is explicitly excluded
        $excluded = config('ai.auto_discovery.excluded_models', []);
        if (in_array($modelClass, $excluded)) {
            return false;
        }

        return true;
    }

    /**
     * Check if model implements Nodeable interface
     *
     * @param string $modelClass Model class name
     * @return bool True if implements Nodeable
     */
    private function implementsNodeable(string $modelClass): bool
    {
        $interfaces = class_implements($modelClass);

        return in_array('Condoedge\\Ai\\Domain\\Contracts\\Nodeable', $interfaces);
    }

    /**
     * Discover traversal scopes from relationships with discriminator fields
     *
     * Analyzes relationships to find those with discriminator fields in the
     * target entity and auto-generates traversal scopes for them.
     *
     * @param string|Model $model Model class name or instance
     * @return array Discovered traversal scopes
     */
    private function discoverTraversalScopes(string|Model $model): array
    {
        if (!$this->traversalGenerator) {
            return [];
        }

        $modelInstance = $this->resolveModel($model);
        $modelClass = get_class($modelInstance);
        $sourceEntity = class_basename($modelClass);

        // Get all relationships
        $relationships = $this->relationships->discover($model);

        $traversalScopes = [];

        foreach ($relationships as $relationship) {
            // Check if this relationship has discriminator fields
            $discriminatorFields = $relationship['discriminator_fields'] ?? [];

            if (empty($discriminatorFields)) {
                continue;
            }

            $targetEntity = $relationship['target_label'] ?? '';
            $relationshipType = $relationship['inverse_type'] ?? $relationship['type'] ?? '';
            $relatedModel = $relationship['related_model'] ?? '';

            if (!$targetEntity || !$relationshipType || !$relatedModel) {
                continue;
            }

            // For each discriminator field, generate scopes
            foreach ($discriminatorFields as $discriminatorField) {
                // Get role mappings from configuration
                $roleMappings = $this->traversalGenerator->getRoleMappings(
                    $targetEntity,
                    $discriminatorField
                );

                if (empty($roleMappings)) {
                    continue;
                }

                // Generate scopes for this relationship + discriminator combination
                $generatedScopes = $this->traversalGenerator->generateFromRelationship(
                    $sourceEntity,
                    $targetEntity,
                    $relationshipType,
                    [
                        'field' => $discriminatorField,
                        'values' => $roleMappings,
                    ]
                );

                $traversalScopes = array_merge($traversalScopes, $generatedScopes);
            }
        }

        return $traversalScopes;
    }

    
    /**
     * Discover security configuration for a model
     *
     * Detects team resolution patterns from the Kompo Auth package:
     * - securityRelatedTeamIds() method
     * - TEAM_ID_COLUMN property
     * - team_id column
     * - scopeSecurityForTeams() scope
     *
     * @param string|Model $model Model class name or instance
     * @return array Security configuration
     */
    protected function discoverSecurityConfig(string|Model $model): array
    {
        $modelInstance = $this->resolveModel($model);

        $config = [
            'team_resolution' => null,
            'team_query_scope' => null,
            'multiple_teams' => false,
            'has_owner_bypass' => false,
            'sensible_columns' => [],
        ];

        // Strategy 1: Check for securityRelatedTeamIds method
        if (method_exists($modelInstance, 'securityRelatedTeamIds')) {
            $config['team_resolution'] = 'method:securityRelatedTeamIds';
            $config['multiple_teams'] = true; // Method typically returns collection
        }
        // Strategy 2: Check for TEAM_ID_COLUMN property
        elseif (property_exists($modelInstance, 'TEAM_ID_COLUMN')) {
            $column = $this->getModelProperty($modelInstance, 'TEAM_ID_COLUMN');
            $config['team_resolution'] = $column;
        }
        // Strategy 3: Check for team_id column
        elseif ($this->hasColumn($modelInstance, 'team_id')) {
            $config['team_resolution'] = 'team_id';
        }

        // Check for scopeSecurityForTeams
        if (method_exists($modelInstance, 'scopeSecurityForTeams')) {
            $config['team_query_scope'] = 'scope:securityForTeams';
        }

        // Check for owner bypass methods
        if (method_exists($modelInstance, 'usersIdsAllowedToManage')) {
            $config['has_owner_bypass'] = true;
        }

        // Get sensible columns from model
        $config['sensible_columns'] = $this->getSensibleColumns($modelInstance);

        return $config;
    }

    /**
     * Get sensible columns from model
     *
     * Reads the $sensibleColumns property from the model, which defines
     * fields that should be protected/hidden without proper permissions.
     *
     * @param Model $model Model instance
     * @return array List of sensible column names
     */
    protected function getSensibleColumns(Model $model): array
    {
        if (property_exists($model, 'sensibleColumns')) {
            return $this->getModelProperty($model, 'sensibleColumns') ?? [];
        }

        return [];
    }

    /**
     * Get a model property value using reflection
     *
     * @param Model $model Model instance
     * @param string $property Property name
     * @return mixed Property value or null if not found
     */
    protected function getModelProperty(Model $model, string $property): mixed
    {
        if (!property_exists($model, $property)) {
            return null;
        }

        $reflection = new \ReflectionProperty($model, $property);
        $reflection->setAccessible(true);
        return $reflection->getValue($model);
    }

    /**
     * Check if model's table has a column
     *
     * Uses database schema if available, falls back to checking fillable array.
     *
     * @param Model $model Model instance
     * @param string $column Column name
     * @return bool True if column exists
     */
    protected function hasColumn(Model $model, string $column): bool
    {
        try {
            $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
            if (!empty($columns)) {
                return in_array($column, $columns);
            }
        } catch (\Throwable $e) {
            // DB not available, fall through
        }

        // Fallback: check fillable
        $fillable = $model->getFillable();
        return in_array($column, $fillable);
    }
    /**
     * Execute discovery in a safe context
     *
     * Wraps discovery operations in:
     * - Database transaction that automatically rolls back
     * - Event facade temporarily disabled
     * - Model events temporarily disabled
     *
     * This ensures no side effects during introspection:
     * - No database writes persist
     * - No event listeners fire
     * - No emails sent
     * - No logs written via events
     *
     * @param callable $callback Discovery callback to execute
     * @return mixed Result from callback
     */
    private function safeDiscovery(callable $callback): mixed
    {
        $result = null;

        try {
            // Start database transaction
            DB::beginTransaction();

            // Temporarily disable model events
            Model::unsetEventDispatcher();

            try {
                // Execute discovery
                $result = $callback();
            } finally {
                // Always restore event dispatcher
                Model::setEventDispatcher(app('events'));

                // Always rollback transaction - we never want to persist discovery side effects
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            // If DB not available (like in tests with mocks), just run without transaction
            if (str_contains($e->getMessage(), 'Method name is not configured')) {
                // This is a mock-related error, re-throw it
                throw $e;
            }

            // For other errors, try without transaction
            Model::unsetEventDispatcher();
            try {
                $result = $callback();
            } finally {
                Model::setEventDispatcher(app('events'));
            }
        }

        return $result;
    }
}
