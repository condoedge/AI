<?php

namespace Condoedge\Ai\Domain\Traits;

use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Facades\AI;
use Illuminate\Support\Facades\Log;

/**
 * HasNodeableConfig Trait
 *
 * Use this trait in models to automatically load configuration from config files
 * instead of implementing getGraphConfig() and getVectorConfig() manually.
 *
 * Also provides automatic synchronization with Neo4j and Qdrant on create, update, delete.
 *
 * Example:
 *   class Customer implements Nodeable {
 *       use HasNodeableConfig;
 *   }
 *
 * This will load config from: config/ai/entities.php => 'Customer' key
 *
 * Auto-sync can be disabled globally in config/ai.php or per model:
 *   protected $aiAutoSync = false;
 *   protected $aiSyncQueue = true;  // Queue sync operations
 */
trait HasNodeableConfig
{
    /**
     * Cached resolved configuration
     */
    protected ?array $resolvedConfig = null;

    /**
     * Boot the trait and register model event listeners
     */
    public static function bootHasNodeableConfig(): void
    {
        // Handle model creation
        static::created(function ($model) {
            if (!$model->shouldAutoSync('create')) {
                return;
            }

            $model->performAutoSync('create', function () use ($model) {
                if ($model->shouldEagerLoadRelationships()) {
                    $model->load($model->getRelationshipsToLoad());
                }
                AI::ingest($model);
            });
        });

        // Handle model updates
        static::updated(function ($model) {
            if (!$model->shouldAutoSync('update')) {
                return;
            }

            $model->performAutoSync('update', function () use ($model) {
                if ($model->shouldEagerLoadRelationships()) {
                    $model->load($model->getRelationshipsToLoad());
                }
                AI::sync($model);
            });
        });

        // Handle model deletion
        static::deleted(function ($model) {
            if (!$model->shouldAutoSync('delete')) {
                return;
            }

            $model->performAutoSync('delete', function () use ($model) {
                AI::remove($model);
            });
        });
    }

    /**
     * Check if auto-sync should be performed for this operation
     *
     * @param string $operation create, update, or delete
     * @return bool
     */
    protected function shouldAutoSync(string $operation): bool
    {
        // Check model-level override
        if (property_exists($this, 'aiAutoSync') && $this->aiAutoSync === false) {
            return false;
        }

        // Check global config
        $globalEnabled = config('ai.auto_sync.enabled', true);
        if (!$globalEnabled) {
            return false;
        }

        // Check operation-specific config
        $operationEnabled = config("ai.auto_sync.operations.{$operation}", true);
        if (!$operationEnabled) {
            return false;
        }

        // Check entity-specific config (optional)
        $entityKey = $this->getConfigKey();
        $entityConfig = config("entities.{$entityKey}.auto_sync");
        if ($entityConfig !== null) {
            // Entity has explicit auto_sync setting
            if (is_bool($entityConfig)) {
                return $entityConfig;
            }
            if (is_array($entityConfig) && isset($entityConfig[$operation])) {
                return $entityConfig[$operation];
            }
        }

        return true;
    }

    /**
     * Perform the auto-sync operation
     *
     * @param string $operation
     * @param callable $callback
     * @return void
     */
    protected function performAutoSync(string $operation, callable $callback): void
    {
        $shouldQueue = $this->shouldQueueSync();

        if ($shouldQueue) {
            $this->dispatchSyncJob($operation);
            return;
        }

        try {
            $callback();
        } catch (\Throwable $e) {
            $this->handleSyncError($operation, $e);
        }
    }

    /**
     * Check if sync operations should be queued
     *
     * @return bool
     */
    protected function shouldQueueSync(): bool
    {
        // Check model-level override
        if (property_exists($this, 'aiSyncQueue')) {
            return $this->aiSyncQueue;
        }

        // Check global config
        return config('ai.auto_sync.queue', false);
    }

    /**
     * Dispatch a queue job for syncing
     *
     * @param string $operation
     * @return void
     */
    protected function dispatchSyncJob(string $operation): void
    {
        $queueConnection = config('ai.auto_sync.queue_connection');
        $queueName = config('ai.auto_sync.queue_name', 'default');

        // Job will be created in next step
        $jobClass = match ($operation) {
            'create' => \Condoedge\Ai\Jobs\IngestEntityJob::class,
            'update' => \Condoedge\Ai\Jobs\SyncEntityJob::class,
            'delete' => \Condoedge\Ai\Jobs\RemoveEntityJob::class,
        };

        $job = new $jobClass($this);

        if ($queueConnection) {
            $job->onConnection($queueConnection);
        }

        if ($queueName !== 'default') {
            $job->onQueue($queueName);
        }

        dispatch($job);
    }

    /**
     * Handle sync errors
     *
     * @param string $operation
     * @param \Throwable $e
     * @return void
     * @throws \Throwable
     */
    protected function handleSyncError(string $operation, \Throwable $e): void
    {
        $failSilently = config('ai.auto_sync.fail_silently', true);
        $logErrors = config('ai.auto_sync.log_errors', true);

        if ($logErrors) {
            Log::error("AI auto-sync failed for {$operation} operation", [
                'model' => get_class($this),
                'id' => $this->getId(),
                'operation' => $operation,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        if (!$failSilently) {
            throw $e;
        }
    }

    /**
     * Check if relationships should be eager loaded before sync
     *
     * @return bool
     */
    protected function shouldEagerLoadRelationships(): bool
    {
        // Check model-level override
        if (property_exists($this, 'aiEagerLoadRelationships')) {
            return $this->aiEagerLoadRelationships;
        }

        // Check global config
        return config('ai.auto_sync.eager_load_relationships', true);
    }

    /**
     * Get relationships to load before sync
     *
     * Override this method to specify which relationships to load
     *
     * @return array
     */
    protected function getRelationshipsToLoad(): array
    {
        // Check if model has explicit list
        if (property_exists($this, 'aiSyncRelationships')) {
            return $this->aiSyncRelationships;
        }

        // Try to infer from GraphConfig
        try {
            $graphConfig = $this->getGraphConfig();
            $relationshipConfigs = $graphConfig->getRelationships();

            $relationshipNames = [];
            foreach ($relationshipConfigs as $relConfig) {
                // Extract relationship method name from foreign key
                // e.g., 'customer_id' -> 'customer'
                $foreignKey = $relConfig->getForeignKey();
                if ($foreignKey && str_ends_with($foreignKey, '_id')) {
                    $relationshipNames[] = substr($foreignKey, 0, -3);
                }
            }

            return $relationshipNames;
        } catch (\Throwable $e) {
            // If we can't load config, return empty array
            return [];
        }
    }

    /**
     * Get the entity name for config lookup
     * Override this if your config key differs from class name
     */
    protected function getConfigKey(): string
    {
        $className = class_basename($this);
        return $className;
    }

    /**
     * Get Neo4j graph configuration with auto-discovery fallback
     */
    public function getGraphConfig(): GraphConfig
    {
        $config = $this->resolveConfig();

        if (!isset($config['graph'])) {
            return GraphConfig::fromArray([
                'label' => class_basename($this),
                'properties' => [],
                'relationships' => [],
            ]);
        }

        return GraphConfig::fromArray($config['graph']);
    }

    /**
     * Get Qdrant vector configuration with auto-discovery fallback
     *
     * @throws \LogicException If entity is not vectorizable
     */
    public function getVectorConfig(): VectorConfig
    {
        $config = $this->resolveConfig();

        if (!isset($config['vector'])) {
            throw new \LogicException(
                'Entity ' . static::class . ' is not vectorizable. ' .
                'Add vector configuration to config/entities.php or ensure embed_fields are discovered.'
            );
        }

        return VectorConfig::fromArray($config['vector']);
    }

    /**
     * Resolve configuration with fallback chain
     *
     * Priority (layered - later overrides earlier):
     * 1. Model properties (base layer: $embedFields, $graphLabel, etc.)
     * 2. nodeableConfig() method on model (override layer)
     * 3. config/entities.php for model class (legacy override)
     * 4. EntityAutoDiscovery service (fills missing parts)
     *
     * @return array<string, mixed> Entity configuration
     */
    protected function resolveConfig(): array
    {
        // Return cached config if available
        if ($this->resolvedConfig !== null) {
            return $this->resolvedConfig;
        }

        // 1. Start with model properties (base layer)
        $config = $this->readModelProperties();

        // 2. Merge with nodeableConfig() if exists (override layer)
        if (method_exists($this, 'nodeableConfig')) {
            $overrides = $this->nodeableConfig();

            // If returns NodeableConfig builder, convert to array
            if ($overrides instanceof \Condoedge\Ai\Domain\ValueObjects\NodeableConfig) {
                $overrides = $overrides->toArray();
            }

            $config = $this->mergeConfigDeep($config, $overrides);
        }

        // 3. Check config/entities.php (legacy override)
        $entityConfigs = config('entities', []);
        $modelClass = get_class($this);
        $configKey = $this->getConfigKey();

        if (isset($entityConfigs[$modelClass])) {
            $config = $this->mergeConfigDeep($config, $entityConfigs[$modelClass]);
        } elseif (isset($entityConfigs[$configKey])) {
            $config = $this->mergeConfigDeep($config, $entityConfigs[$configKey]);
        }

        // 4. Auto-discover missing parts
        if (config('ai.auto_discovery.runtime_enabled', false)) {
            $discovered = $this->autoDiscover();
            // Discovered goes first (base), config overrides
            $config = $this->mergeConfigDeep($discovered, $config);
        }

        // Ensure minimum required config
        $config = $this->ensureMinimumConfig($config);

        $this->resolvedConfig = $config;
        return $this->resolvedConfig;
    }

    /**
     * Read configuration from model properties
     *
     * Convention-based properties:
     * - $embedFields: array of fields to embed in vectors
     * - $graphLabel: string label for Neo4j node
     * - $graphRelationships: array of relationship configs
     * - $sensibleColumns: array of sensitive field names
     * - $nodeableAliases: array of entity aliases
     *
     * @return array Configuration from model properties
     */
    protected function readModelProperties(): array
    {
        $config = [];

        // Read $embedFields
        if (property_exists($this, 'embedFields') && !empty($this->embedFields)) {
            $config['vector']['embed_fields'] = $this->embedFields;
        }

        // Read $graphLabel
        if (property_exists($this, 'graphLabel') && !empty($this->graphLabel)) {
            $config['graph']['label'] = $this->graphLabel;
        }

        // Read $graphRelationships
        if (property_exists($this, 'graphRelationships') && !empty($this->graphRelationships)) {
            $config['graph']['relationships'] = $this->graphRelationships;
        }

        // Read $sensibleColumns
        if (property_exists($this, 'sensibleColumns') && !empty($this->sensibleColumns)) {
            $config['security']['sensible_columns'] = $this->sensibleColumns;
        }

        // Read $nodeableAliases
        if (property_exists($this, 'nodeableAliases') && !empty($this->nodeableAliases)) {
            $config['metadata']['aliases'] = $this->nodeableAliases;
        }

        return $config;
    }

    /**
     * Deep merge two config arrays (second overwrites first)
     *
     * @param array $base Base configuration
     * @param array $override Override configuration
     * @return array Merged configuration
     */
    protected function mergeConfigDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeConfigDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * Ensure minimum required configuration is present
     *
     * @param array $config Current configuration
     * @return array Configuration with defaults filled in
     */
    protected function ensureMinimumConfig(array $config): array
    {
        // Ensure graph label
        if (empty($config['graph']['label'])) {
            $config['graph']['label'] = class_basename($this);
        }

        // Ensure graph properties from fillable if not set
        if (empty($config['graph']['properties'])) {
            if (property_exists($this, 'fillable') && !empty($this->fillable)) {
                $config['graph']['properties'] = array_merge(['id'], $this->fillable);
            } else {
                $config['graph']['properties'] = ['id'];
            }
        }

        // Ensure vector collection if embed_fields exist
        if (!empty($config['vector']['embed_fields']) && empty($config['vector']['collection'])) {
            $config['vector']['collection'] = strtolower(class_basename($this)) . 's';
        }

        return $config;
    }

    /**
     * Auto-discover configuration
     *
     * @return array<string, mixed> Discovered configuration
     */
    protected function autoDiscover(): array
    {
        // Check if auto-discovery is enabled
        if (!config('ai.auto_discovery.enabled', true)) {
            return [];
        }

        // Get auto-discovery service from container
        if (!app()->bound(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class)) {
            return [];
        }

        $discovery = app(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class);

        // Discover and cache
        $config = $discovery->discover($this);

        // Allow model to customize discovery
        if (method_exists($this, 'customizeDiscovery')) {
            $nodeableConfig = \Condoedge\Ai\Domain\ValueObjects\NodeableConfig::fromArray($config);
            $customized = $this->customizeDiscovery($nodeableConfig);
            return $customized->toArray();
        }

        return $config;
    }

    /**
     * Optional: Allow models to customize discovery
     *
     * Override this method in your model to customize auto-discovered configuration.
     *
     * @param \Condoedge\Ai\Domain\ValueObjects\NodeableConfig $config Discovered configuration
     * @return \Condoedge\Ai\Domain\ValueObjects\NodeableConfig Customized configuration
     */
    public function customizeDiscovery(\Condoedge\Ai\Domain\ValueObjects\NodeableConfig $config): \Condoedge\Ai\Domain\ValueObjects\NodeableConfig
    {
        return $config; // Override in model to customize
    }

    /**
     * Load entity configuration from file (legacy support)
     *
     * @deprecated Use resolveConfig() instead
     */
    protected function loadEntityConfig(): array
    {
        return $this->resolveConfig();
    }

    /**
     * Get the path to the entities config file
     * Override this if you use a different config path
     */
    protected function getConfigPath(): string
    {
        // Try Laravel config path first
        if (function_exists('config_path')) {
            return config_path('ai/entities.php');
        }

        // Fallback: Relative to this package
        return __DIR__ . '/../../../config/entities.php';
    }

    /**
     * Get unique identifier (required by Nodeable interface)
     */
    public function getId(): string|int
    {
        return $this->id ?? throw new \LogicException('Entity must have an "id" property');
    }

    /**
     * Convert entity to array (required by Nodeable interface)
     */
    public function toArray(): array
    {
        // If using Eloquent or similar, this might already be implemented
        if (method_exists($this, 'attributesToArray')) {
            return $this->attributesToArray();
        }

        // Fallback: Use reflection to get all public properties
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        $array = [];
        foreach ($properties as $property) {
            $array[$property->getName()] = $property->getValue($this);
        }

        return $array;
    }
}
