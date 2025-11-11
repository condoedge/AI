<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Database\Eloquent\Model;

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
     */
    public function __construct(
        private SchemaInspector $schema,
        private CypherScopeAdapter $scopeAdapter,
        private RelationshipDiscoverer $relationships,
        private PropertyDiscoverer $properties,
        private AliasGenerator $aliases,
        private EmbedFieldDetector $embedFields,
    ) {}

    /**
     * Discover complete configuration for a model
     *
     * Runs all discoverers and combines results into a complete
     * configuration array matching the format in config/entities.php.
     *
     * @param string|Model $model Model class name or instance
     * @return array Complete entity configuration
     */
    public function discover(string|Model $model): array
    {
        $modelInstance = $this->resolveModel($model);

        // Discover all parts
        $graph = $this->discoverGraph($model);
        $vector = $this->discoverVector($model);
        $metadata = $this->discoverMetadata($model);

        return [
            'graph' => $graph,
            'vector' => $vector,
            'metadata' => $metadata,
        ];
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

        // Get relationships
        $graphRelationships = $this->relationships->discover($model);

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
     * Deep merge two arrays
     *
     * Recursively merges arrays, with values from $override taking precedence.
     *
     * @param array $base Base array
     * @param array $override Override array
     * @return array Merged array
     */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                // Recursively merge arrays
                $base[$key] = $this->deepMerge($base[$key], $value);
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
}
