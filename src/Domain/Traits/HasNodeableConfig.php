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
        $entityConfig = config("ai.entities.{$entityKey}.auto_sync");
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
     */
    public function getVectorConfig(): ?VectorConfig
    {
        $config = $this->resolveConfig();

        return isset($config['vector'])
            ? VectorConfig::fromArray($config['vector'])
            : null;
    }

    /**
     * Resolve configuration with fallback chain
     *
     * Priority:
     * 1. nodeableConfig() method on model (highest priority)
     * 2. config/entities.php for model class
     * 3. EntityAutoDiscovery service (fallback)
     *
     * @return array<string, mixed> Entity configuration
     */
    protected function resolveConfig(): array
    {
        // 1. Check for nodeableConfig() method (developer override)
        if (method_exists($this, 'nodeableConfig')) {
            $result = $this->nodeableConfig();

            // If returns NodeableConfig builder, convert to array
            if ($result instanceof \Condoedge\Ai\Domain\ValueObjects\NodeableConfig) {
                return $result->toArray();
            }

            return $result;
        }

        // 2. Check config/entities.php (legacy support)
        $entityConfigs = config('ai.entities', []);
        $modelClass = get_class($this);
        $shortName = class_basename($modelClass);

        if (isset($entityConfigs[$modelClass])) {
            return $entityConfigs[$modelClass];
        }

        if (isset($entityConfigs[$shortName])) {
            return $entityConfigs[$shortName];
        }

        // 3. Auto-discovery (fallback)
        return $this->autoDiscover();
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
