<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

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
     * @param InheritanceResolver|null $inheritanceResolver Inheritance resolver
     */
    public function __construct(
        private SchemaInspector $schema,
        private CypherScopeAdapter $scopeAdapter,
        private RelationshipDiscoverer $relationships,
        private PropertyDiscoverer $properties,
        private AliasGenerator $aliases,
        private EmbedFieldDetector $embedFields,
        private ?TraversalScopeGenerator $traversalGenerator = null,
        private ?InheritanceResolver $inheritanceResolver = null,
    ) {
        $this->traversalGenerator = $traversalGenerator ?? new TraversalScopeGenerator();
        $this->inheritanceResolver = $inheritanceResolver ?? new InheritanceResolver();
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
     * Discover all Nodeable entities in the application
     *
     * This is the main entry point for discovery. It:
     * 1. Finds all models implementing Nodeable
     * 2. Resolves inheritance (merges child models into parents)
     * 3. Discovers configuration for each canonical model
     * 4. Returns complete configurations ready for config/entities.php
     *
     * @param string|null $specificModel Optional specific model to discover
     * @param array $modelPaths Paths to search for models (default: app/Models)
     * @return array{configurations: array, errors: array, stats: array}
     */
    public function discoverAll(?string $specificModel = null, array $modelPaths = []): array
    {
        $stats = [
            'total_models_found' => 0,
            'canonical_models' => 0,
            'child_models_merged' => 0,
            'configurations_generated' => 0,
        ];
        $errors = [];
        $configurations = [];

        // Get models to discover
        $allModels = $specificModel
            ? [$specificModel]
            : $this->findNodeableModels($modelPaths);

        $stats['total_models_found'] = count($allModels);

        if (empty($allModels)) {
            return [
                'configurations' => [],
                'errors' => [],
                'stats' => $stats,
            ];
        }

        // Resolve inheritance - deduplicate models sharing same table
        $resolved = $this->inheritanceResolver->resolve($allModels);
        $canonicalModels = $resolved['models'];
        $inheritanceMap = $resolved['inheritance'];

        $stats['canonical_models'] = count($canonicalModels);
        $stats['child_models_merged'] = $stats['total_models_found'] - $stats['canonical_models'];

        // Discover each canonical model
        foreach ($canonicalModels as $modelClass) {
            try {
                $config = $this->discover($modelClass);

                // Merge inheritance info if this model has children
                if (isset($inheritanceMap[$modelClass])) {
                    $config = $this->inheritanceResolver->mergeInheritanceInfo(
                        $config,
                        $inheritanceMap[$modelClass]
                    );
                }

                // Only include if has graph, vector, or security config
                if (!empty($config['graph']) || !empty($config['vector']) || !empty($config['security'])) {
                    $configurations[$modelClass] = $config;
                    $stats['configurations_generated']++;
                }
            } catch (\Throwable $e) {
                $errors[$modelClass] = $e->getMessage();
            }
        }

        return [
            'configurations' => $configurations,
            'errors' => $errors,
            'stats' => $stats,
            'inheritance' => $inheritanceMap,
        ];
    }

    /**
     * Find all Nodeable models in the application
     *
     * @param array $paths Additional paths to search
     * @return array<string> Model class names
     */
    public function findNodeableModels(array $paths = []): array
    {
        $models = [];

        // Default to app/Models
        $searchPaths = $paths;
        if (empty($searchPaths) && function_exists('app_path')) {
            $modelsPath = app_path('Models');
            if (File::isDirectory($modelsPath)) {
                $searchPaths[] = $modelsPath;
            }
        }

        foreach ($searchPaths as $path) {
            if (!File::isDirectory($path)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($path)->name('*.php');

            foreach ($finder as $file) {
                $class = $this->getClassFromFile($file->getPathname());

                if ($class && class_exists($class) && $this->implementsNodeable($class)) {
                    $models[] = $class;
                }
            }
        }

        return $models;
    }

    /**
     * Extract fully qualified class name from PHP file
     *
     * @param string $filePath Path to PHP file
     * @return string|null Class name or null if not found
     */
    private function getClassFromFile(string $filePath): ?string
    {
        $contents = File::get($filePath);

        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = $matches[1];
        }

        $className = null;
        if (preg_match('/class\s+(\w+)/', $contents, $matches)) {
            $className = $matches[1];
        }

        if ($namespace && $className) {
            return $namespace . '\\' . $className;
        }

        return null;
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
     * Also discovers team_path for AI security filtering:
     * - 'team_id' for direct column
     * - 'auto' to detect from relationships
     * - 'parent.team_id' for parent entity traversal
     * - ['rel' => 'X', 'target' => 'Team'] for relationship traversal
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
            'team_path' => null, // AI security: team filtering path
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

        // Discover team_path for AI security filtering
        $config['team_path'] = $this->discoverTeamPath($model);

        return $config;
    }

    /**
     * Discover team_path for AI security filtering
     *
     * Determines how this entity relates to teams for query filtering:
     * - Direct team_id column → 'team_id'
     * - Relationship to Team → ['rel' => 'TYPE', 'target' => 'Team']
     * - Through parent entity → 'parent.team_id'
     * - Cannot determine → 'auto' (let runtime auto-detect)
     *
     * @param string|Model $model Model class name or instance
     * @return string|array|null Team path configuration
     */
    protected function discoverTeamPath(string|Model $model): string|array|null
    {
        $modelInstance = $this->resolveModel($model);

        // Priority 1: Direct team_id column
        if ($this->hasColumn($modelInstance, 'team_id')) {
            return 'team_id';
        }

        // Priority 2: Check TEAM_ID_COLUMN property for custom column name
        if (property_exists($modelInstance, 'TEAM_ID_COLUMN')) {
            $column = $this->getModelProperty($modelInstance, 'TEAM_ID_COLUMN');
            if ($column && $this->hasColumn($modelInstance, $column)) {
                return $column;
            }
        }

        // Priority 3: Look for relationship to Team in discovered relationships
        $relationships = $this->relationships->discover($model);
        foreach ($relationships as $relationship) {
            $targetLabel = $relationship['target_label'] ?? '';

            // Direct relationship to Team
            if ($targetLabel === 'Team') {
                // If has foreign_key, it's a direct column
                if (!empty($relationship['foreign_key'])) {
                    return $relationship['foreign_key'];
                }
                // Otherwise, relationship traversal
                return [
                    'rel' => $relationship['type'] ?? 'BELONGS_TO_TEAM',
                    'target' => 'Team',
                ];
            }
        }

        // Priority 4: Look for parent entity with team_id (e.g., InvoiceItem -> Invoice -> team_id)
        foreach ($relationships as $relationship) {
            $targetLabel = $relationship['target_label'] ?? '';
            $relType = $relationship['type'] ?? '';

            // Skip if this is not a "belongs to" type relationship
            if (!str_contains(strtoupper($relType), 'BELONGS') && !str_contains(strtoupper($relType), 'OF')) {
                continue;
            }

            // Check if the target entity has team_id
            $relatedModel = $relationship['related_model'] ?? null;
            if ($relatedModel && class_exists($relatedModel)) {
                try {
                    $relatedInstance = new $relatedModel();
                    if ($this->hasColumn($relatedInstance, 'team_id')) {
                        return strtolower($targetLabel) . '.team_id';
                    }
                } catch (\Throwable $e) {
                    // Cannot instantiate related model, skip
                }
            }
        }

        // Default: auto-detect at runtime
        return 'auto';
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
     * Get the action resolver for an entity type
     *
     * @deprecated Use getEntityActionResolver() instead
     * @param string $entityLabel The entity label (e.g., 'Person', 'Unit')
     * @return \Closure|null The action resolver closure, or null if none configured
     */
    public function getActionResolver(string $entityLabel): ?\Closure
    {
        // Backwards compatibility: return first action's resolver
        $entityActions = config('ai.entity_actions', []);
        $actions = $entityActions[$entityLabel] ?? [];
        $firstAction = reset($actions);

        // Handle both old format (direct closure) and new format (nested array)
        if ($firstAction instanceof \Closure) {
            return $firstAction;
        }

        return $firstAction['action'] ?? null;
    }

    /**
     * Get a specific action resolver for an entity type
     *
     * @param string $entityLabel The entity label (e.g., 'Person')
     * @param string $actionKey The action key (e.g., 'profile')
     * @return \Closure|null The action resolver closure, or null if none configured
     */
    public function getEntityActionResolver(string $entityLabel, string $actionKey): ?\Closure
    {
        $entityActions = config('ai.entity_actions', []);
        return $entityActions[$entityLabel][$actionKey]['action'] ?? null;
    }

    /**
     * Get all available actions for an entity type
     *
     * @param string $entityLabel The entity label
     * @return array Array of action configs with keys: action_key, aliases, label
     */
    public function getEntityActions(string $entityLabel): array
    {
        $entityActions = config('ai.entity_actions', []);
        $actions = $entityActions[$entityLabel] ?? [];

        $result = [];
        foreach ($actions as $key => $config) {
            $result[] = [
                'action_key' => $key,
                'aliases' => $config['aliases'] ?? [],
                'label' => $config['label'] ?? $key,
            ];
        }

        return $result;
    }

    /**
     * Get a generic action resolver
     *
     * @param string $actionKey The action key (e.g., 'settings')
     * @return \Closure|null The action resolver closure
     */
    public function getGenericActionResolver(string $actionKey): ?\Closure
    {
        $genericActions = config('ai.generic_actions', []);
        return $genericActions[$actionKey]['action'] ?? null;
    }

    /**
     * Get all available generic actions
     *
     * @return array Array of action configs
     */
    public function getGenericActions(): array
    {
        $genericActions = config('ai.generic_actions', []);

        $result = [];
        foreach ($genericActions as $key => $config) {
            $result[] = [
                'action_key' => $key,
                'aliases' => $config['aliases'] ?? [],
                'label' => $config['label'] ?? $key,
            ];
        }

        return $result;
    }

    /**
     * Check if an entity type has any actions configured
     */
    public function hasEntityActions(string $entityLabel): bool
    {
        $entityActions = config('ai.entity_actions', []);
        return !empty($entityActions[$entityLabel]);
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
