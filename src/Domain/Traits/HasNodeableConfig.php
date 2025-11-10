<?php

namespace AiSystem\Domain\Traits;

use AiSystem\Domain\ValueObjects\GraphConfig;
use AiSystem\Domain\ValueObjects\VectorConfig;
use AiSystem\Facades\AI;
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
            'create' => \AiSystem\Jobs\IngestEntityJob::class,
            'update' => \AiSystem\Jobs\SyncEntityJob::class,
            'delete' => \AiSystem\Jobs\RemoveEntityJob::class,
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
     * Get Neo4j graph configuration from config file
     */
    public function getGraphConfig(): GraphConfig
    {
        $config = $this->loadEntityConfig();

        if (!isset($config['graph'])) {
            throw new \LogicException(
                sprintf('No graph configuration found for entity: %s', $this->getConfigKey())
            );
        }

        return GraphConfig::fromArray($config['graph']);
    }

    /**
     * Get Qdrant vector configuration from config file
     */
    public function getVectorConfig(): VectorConfig
    {
        $config = $this->loadEntityConfig();

        if (!isset($config['vector'])) {
            throw new \LogicException(
                sprintf('No vector configuration found for entity: %s. Entity is not searchable.', $this->getConfigKey())
            );
        }

        return VectorConfig::fromArray($config['vector']);
    }

    /**
     * Load entity configuration from file
     */
    protected function loadEntityConfig(): array
    {
        $configKey = $this->getConfigKey();

        // Try loading from Laravel config first
        if (function_exists('config')) {
            $config = config("ai.entities.{$configKey}");
            if ($config) {
                return $config;
            }
        }

        // Fallback: Load directly from PHP config file
        $configPath = $this->getConfigPath();
        if (!file_exists($configPath)) {
            throw new \RuntimeException(
                sprintf('Entity config file not found: %s', $configPath)
            );
        }

        $allEntities = require $configPath;

        if (!isset($allEntities[$configKey])) {
            throw new \RuntimeException(
                sprintf('Entity "%s" not found in config file', $configKey)
            );
        }

        return $allEntities[$configKey];
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
