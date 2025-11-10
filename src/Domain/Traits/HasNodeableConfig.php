<?php

namespace AiSystem\Domain\Traits;

use AiSystem\Domain\ValueObjects\GraphConfig;
use AiSystem\Domain\ValueObjects\VectorConfig;

/**
 * HasNodeableConfig Trait
 *
 * Use this trait in models to automatically load configuration from config files
 * instead of implementing getGraphConfig() and getVectorConfig() manually.
 *
 * Example:
 *   class Customer implements Nodeable {
 *       use HasNodeableConfig;
 *   }
 *
 * This will load config from: config/ai/entities.php => 'Customer' key
 */
trait HasNodeableConfig
{
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
