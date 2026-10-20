<?php

namespace Condoedge\Ai\Domain\ValueObjects;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * NodeableConfig - Fluent builder for entity configuration
 *
 * Provides a fluent API to build entity configurations that output
 * the exact same array structure as manual config arrays.
 *
 * Usage:
 *   $config = NodeableConfig::for(Customer::class)
 *       ->label('Customer')
 *       ->properties('id', 'name', 'email')
 *       ->relationship('PURCHASED', 'Order', 'order_id')
 *       ->aliases('customer', 'client')
 *       ->description('Customer entity')
 *       ->toArray();
 *
 * The builder maintains internal state matching the entities config format
 * and can be used interchangeably with array configuration.
 */
class NodeableConfig
{
    /**
     * Internal configuration array matching entities config structure
     */
    private array $config = [];

    /**
     * Model class this config is for
     */
    private ?string $modelClass = null;

    /**
     * Private constructor - use factory methods instead
     */
    private function __construct()
    {
        // Initialize empty structure
        $this->config = [];
    }

    /**
     * Create a new builder for a specific model class
     *
     * @param string $modelClass Fully qualified model class name
     * @return self
     */
    public static function for(string $modelClass): self
    {
        $instance = new self();
        $instance->modelClass = $modelClass;
        return $instance;
    }

    /**
     * Create a builder from existing array configuration
     *
     * @param array $config Existing entity configuration array
     * @return self
     */
    public static function fromArray(array $config): self
    {
        $instance = new self();
        $instance->config = $config;
        return $instance;
    }

    /**
     * Auto-discover configuration from a model instance
     *
     * Uses EntityAutoDiscovery service to introspect the model and generate
     * complete configuration automatically. The builder can then be used to
     * override or enhance the auto-discovered settings.
     *
     * @param Model $model Model instance to discover from
     * @return self
     */
    public static function discover(Model $model): self
    {
        $instance = new self();
        $instance->modelClass = get_class($model);

        // Check if auto-discovery service is available
        if (!app()->bound(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class)) {
            return $instance;
        }

        // Use EntityAutoDiscovery service
        $discovery = app(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class);
        $config = $discovery->discover($model);

        // Load the discovered config into the builder
        $instance->config = $config;

        return $instance;
    }

    // =========================================================================
    // Graph Configuration Methods
    // =========================================================================

    /**
     * Set the Neo4j node label
     *
     * @param string $label Node label (e.g., 'Customer', 'Order')
     * @return self
     */
    public function label(string $label): self
    {
        if (!isset($this->config['graph'])) {
            $this->config['graph'] = [];
        }
        $this->config['graph']['label'] = $label;
        return $this;
    }

    /**
     * Set the properties to store in Neo4j
     *
     * Accepts properties as multiple arguments or arrays:
     *   ->properties('id', 'name', 'email')
     *   ->properties(['id', 'name'], 'email')
     *
     * @param string|array ...$properties Property names
     * @return self
     */
    public function properties(string|array ...$properties): self
    {
        if (!isset($this->config['graph'])) {
            $this->config['graph'] = [];
        }

        // Flatten nested arrays
        $flattened = [];
        foreach ($properties as $prop) {
            if (is_array($prop)) {
                $flattened = array_merge($flattened, $prop);
            } else {
                $flattened[] = $prop;
            }
        }

        $this->config['graph']['properties'] = $flattened;
        return $this;
    }

    /**
     * Add a relationship to the graph configuration
     *
     * @param string $type Relationship type (e.g., 'MEMBER_OF', 'PURCHASED')
     * @param string $targetLabel Target node label (e.g., 'Team', 'Order')
     * @param string|null $foreignKey Foreign key field (e.g., 'team_id')
     * @param array $relationshipProperties Additional properties on the relationship
     * @return self
     */
    public function relationship(
        string $type,
        string $targetLabel,
        ?string $foreignKey = null,
        array $relationshipProperties = []
    ): self {
        if (!isset($this->config['graph'])) {
            $this->config['graph'] = [];
        }
        if (!isset($this->config['graph']['relationships'])) {
            $this->config['graph']['relationships'] = [];
        }

        $relationship = [
            'type' => $type,
            'target_label' => $targetLabel,
        ];

        if ($foreignKey !== null) {
            $relationship['foreign_key'] = $foreignKey;
        }

        if (!empty($relationshipProperties)) {
            $relationship['properties'] = $relationshipProperties;
        }

        $this->config['graph']['relationships'][] = $relationship;
        return $this;
    }

    // =========================================================================
    // Metadata Configuration Methods
    // =========================================================================

    /**
     * Set aliases for semantic matching
     *
     * Accepts aliases as multiple arguments or arrays:
     *   ->aliases('customer', 'client', 'buyer')
     *   ->aliases(['customer', 'client'])
     *
     * @param string|array ...$aliases Alias names
     * @return self
     */
    public function aliases(string|array ...$aliases): self
    {
        if (!isset($this->config['metadata'])) {
            $this->config['metadata'] = [];
        }

        // Flatten nested arrays
        $flattened = [];
        foreach ($aliases as $alias) {
            if (is_array($alias)) {
                $flattened = array_merge($flattened, $alias);
            } else {
                $flattened[] = $alias;
            }
        }

        $this->config['metadata']['aliases'] = $flattened;
        return $this;
    }

    /**
     * Add aliases to existing ones (doesn't replace)
     *
     * @param string|array ...$aliases Alias names to add
     * @return self
     */
    public function addAlias(string|array ...$aliases): self
    {
        if (!isset($this->config['metadata'])) {
            $this->config['metadata'] = [];
        }
        if (!isset($this->config['metadata']['aliases'])) {
            $this->config['metadata']['aliases'] = [];
        }

        // Flatten nested arrays
        $flattened = [];
        foreach ($aliases as $alias) {
            if (is_array($alias)) {
                $flattened = array_merge($flattened, $alias);
            } else {
                $flattened[] = $alias;
            }
        }

        // Merge with existing aliases
        $this->config['metadata']['aliases'] = array_unique(
            array_merge($this->config['metadata']['aliases'], $flattened)
        );

        return $this;
    }

    /**
     * Set entity description
     *
     * @param string $description Description of the entity
     * @return self
     */
    public function description(string $description): self
    {
        if (!isset($this->config['metadata'])) {
            $this->config['metadata'] = [];
        }
        $this->config['metadata']['description'] = $description;
        return $this;
    }

    /**
     * Add a semantic scope
     *
     * @param string $name Scope name (e.g., 'active', 'pending')
     * @param array|Closure $config Scope configuration array or closure
     * @return self
     */
    public function scope(string $name, array|Closure $config): self
    {
        if (!isset($this->config['metadata'])) {
            $this->config['metadata'] = [];
        }
        if (!isset($this->config['metadata']['scopes'])) {
            $this->config['metadata']['scopes'] = [];
        }

        // If closure provided, execute it to get the config
        if ($config instanceof Closure) {
            $config = $config();
        }

        $this->config['metadata']['scopes'][$name] = $config;
        return $this;
    }

    /**
     * Add property descriptions for common properties
     *
     * @param array $properties Key-value pairs of property => description
     * @return self
     */
    public function commonProperties(array $properties): self
    {
        if (!isset($this->config['metadata'])) {
            $this->config['metadata'] = [];
        }
        $this->config['metadata']['common_properties'] = $properties;
        return $this;
    }

    // =========================================================================
    // Auto-Sync Configuration
    // =========================================================================

    /**
     * Enable or configure auto-sync
     *
     * @param bool|array $config True/false to enable/disable all, or array with create/update/delete keys
     * @return self
     */
    public function autoSync(bool|array $config): self
    {
        $this->config['auto_sync'] = $config;
        return $this;
    }

    // =========================================================================
    // Output Methods
    // =========================================================================

    /**
     * Convert builder to array configuration
     *
     * This produces the EXACT same array structure as manual config,
     * ensuring interchangeability between builder and array approaches.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Get the model class this config is for
     *
     * @return string|null
     */
    public function getModelClass(): ?string
    {
        return $this->modelClass;
    }

    /**
     * Create GraphConfig from this builder
     *
     * @return GraphConfig
     * @throws \LogicException If graph config is not set
     */
    public function toGraphConfig(): GraphConfig
    {
        if (!isset($this->config['graph'])) {
            throw new \LogicException('Graph configuration not set');
        }

        return GraphConfig::fromArray($this->config['graph']);
    }

    /**
     * Check if graph configuration is set
     *
     * @return bool
     */
    public function hasGraphConfig(): bool
    {
        return isset($this->config['graph']);
    }

    /**
     * Check if metadata configuration is set
     *
     * @return bool
     */
    public function hasMetadata(): bool
    {
        return isset($this->config['metadata']);
    }
}
