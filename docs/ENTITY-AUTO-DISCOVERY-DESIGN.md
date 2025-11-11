# Entity Auto-Discovery Design

## Executive Summary

Transform RAG/Neo4j integration from verbose configuration to Laravel-style convention-over-configuration. Make it as simple as defining Eloquent relationships.

**Goal:** Zero configuration for 80% of use cases, minimal overrides for the rest.

---

## Current Problem

### Before (Current System)
```php
// Model - Just uses the trait
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function orders() {
        return $this->hasMany(Order::class);
    }
}

// config/entities.php - 50+ lines of duplication!
'Customer' => [
    'graph' => [
        'label' => 'Customer',
        'properties' => ['id', 'name', 'email', 'status', 'country', 'lifetime_value', 'created_at'],
        'relationships' => [
            [
                'type' => 'PLACED',
                'target_label' => 'Order',
                'foreign_key' => 'customer_id', // Already in Order model!
            ],
        ],
    ],
    'vector' => [
        'collection' => 'customers',
        'embed_fields' => ['name', 'email'],
        'metadata' => ['id', 'email', 'status'],
    ],
    'metadata' => [
        'aliases' => ['customer', 'customers', 'client', 'clients'],
        'description' => 'Customer records',
        'scopes' => [...],
    ],
],
```

**Problems:**
1. Duplicating `$fillable` → `properties`
2. Duplicating Eloquent relationships → Neo4j relationships
3. Duplicating table name → collection name
4. Manual alias definition
5. Scopes don't leverage Eloquent scopes

### After (Auto-Discovery)
```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'status', 'country', 'lifetime_value'];

    public function orders() {
        return $this->hasMany(Order::class);
    }

    public function scopeActive($query) {
        return $query->where('status', 'active');
    }

    // Optional: Override defaults
    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->embedFields(['name', 'email']) // Only override what you need
            ->addAlias('client'); // Add to auto-discovered aliases
    }
}
```

That's it! Everything else is auto-discovered.

---

## Architecture

### 1. EntityAutoDiscovery Service

The brain of the system. Introspects Eloquent models to build configuration.

```php
namespace Condoedge\Ai\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;

/**
 * Auto-discovers entity configuration from Eloquent models
 *
 * Leverages Laravel conventions:
 * - $fillable → Neo4j properties
 * - relationships → Neo4j relationships
 * - $table → collection name
 * - scopeX() → semantic scopes
 */
class EntityAutoDiscovery
{
    private ConfigCache $cache;

    public function __construct(ConfigCache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Discover full configuration for an entity
     *
     * @param Model&Nodeable $entity
     * @return DiscoveredConfig
     */
    public function discover(Model $entity): DiscoveredConfig
    {
        $cacheKey = $this->getCacheKey($entity);

        return $this->cache->remember($cacheKey, function() use ($entity) {
            return new DiscoveredConfig(
                graph: $this->discoverGraphConfig($entity),
                vector: $this->discoverVectorConfig($entity),
                metadata: $this->discoverMetadata($entity)
            );
        });
    }

    /**
     * Discover Neo4j graph configuration
     */
    private function discoverGraphConfig(Model $entity): GraphConfig
    {
        $label = $this->discoverLabel($entity);
        $properties = $this->discoverProperties($entity);
        $relationships = $this->discoverRelationships($entity);

        return new GraphConfig($label, $properties, $relationships);
    }

    /**
     * Discover node label from model name
     *
     * Examples:
     * - Customer → "Customer"
     * - TestCustomer → "Customer" (removes Test prefix)
     */
    private function discoverLabel(Model $entity): string
    {
        $className = class_basename($entity);

        // Remove common test prefixes
        $className = preg_replace('/^Test/', '', $className);

        return $className;
    }

    /**
     * Discover properties from model attributes
     *
     * Strategy:
     * 1. Start with $fillable (most important)
     * 2. Add $casts keys
     * 3. Add $dates
     * 4. Always include 'id' and timestamps
     * 5. Remove $hidden and $guarded
     */
    private function discoverProperties(Model $entity): array
    {
        $properties = ['id']; // Always include ID

        // Add fillable attributes
        if (property_exists($entity, 'fillable')) {
            $properties = array_merge($properties, $entity->getFillable());
        }

        // Add casted attributes (usually important)
        $properties = array_merge($properties, array_keys($entity->getCasts()));

        // Add dates
        if (method_exists($entity, 'getDates')) {
            $properties = array_merge($properties, $entity->getDates());
        }

        // Add timestamps if enabled
        if ($entity->timestamps) {
            $properties[] = 'created_at';
            $properties[] = 'updated_at';
        }

        // Remove duplicates
        $properties = array_unique($properties);

        // Remove hidden attributes
        if (property_exists($entity, 'hidden')) {
            $properties = array_diff($properties, $entity->getHidden());
        }

        // Remove password-like fields
        $properties = array_filter($properties, function($prop) {
            return !in_array($prop, ['password', 'remember_token']);
        });

        return array_values($properties);
    }

    /**
     * Discover relationships by introspecting relationship methods
     *
     * Only includes:
     * - belongsTo (most important for graph traversal)
     * - morphTo
     *
     * Excludes:
     * - hasMany, hasOne (inverse relationships, discovered from target)
     * - belongsToMany (requires pivot table handling)
     */
    private function discoverRelationships(Model $entity): array
    {
        $relationships = [];
        $reflection = new ReflectionClass($entity);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip non-relationship methods
            if ($method->getNumberOfParameters() > 0) {
                continue;
            }

            if ($method->class !== get_class($entity)) {
                continue; // Skip inherited methods
            }

            try {
                $return = $method->invoke($entity);

                if (!$return instanceof Relation) {
                    continue;
                }

                $relationship = $this->buildRelationshipConfig(
                    $entity,
                    $method->getName(),
                    $return
                );

                if ($relationship) {
                    $relationships[] = $relationship;
                }
            } catch (\Throwable $e) {
                // Skip methods that throw errors
                continue;
            }
        }

        return $relationships;
    }

    /**
     * Build relationship configuration from Eloquent relation
     */
    private function buildRelationshipConfig(
        Model $entity,
        string $methodName,
        Relation $relation
    ): ?RelationshipConfig {
        // Only auto-discover belongsTo relationships
        if (!$relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            return null;
        }

        // Get foreign key (e.g., 'customer_id')
        $foreignKey = $relation->getForeignKeyName();

        // Get related model
        $relatedModel = $relation->getRelated();
        $targetLabel = class_basename($relatedModel);
        $targetLabel = preg_replace('/^Test/', '', $targetLabel);

        // Generate relationship type from method name
        // customer() → BELONGS_TO_CUSTOMER
        // team() → BELONGS_TO_TEAM
        $type = 'BELONGS_TO_' . strtoupper($this->singularize($methodName));

        return new RelationshipConfig(
            type: $type,
            targetLabel: $targetLabel,
            foreignKey: $foreignKey,
            properties: []
        );
    }

    /**
     * Discover vector configuration
     *
     * Defaults:
     * - collection: pluralized table name
     * - embed_fields: text-like fields from properties
     * - metadata: all properties except text fields
     */
    private function discoverVectorConfig(Model $entity): ?VectorConfig
    {
        $properties = $this->discoverProperties($entity);

        // Discover fields to embed (text-like fields)
        $embedFields = $this->discoverEmbedFields($entity, $properties);

        if (empty($embedFields)) {
            // No text fields found, entity is not vectorizable
            return null;
        }

        // Collection name from table
        $collection = $entity->getTable();

        // Metadata: all fields except the ones being embedded
        $metadata = array_diff($properties, $embedFields);

        return new VectorConfig(
            collection: $collection,
            embedFields: $embedFields,
            metadata: array_values($metadata)
        );
    }

    /**
     * Discover which fields should be embedded
     *
     * Strategy:
     * 1. Look for common text fields: name, title, description, notes, content, body
     * 2. Check $casts for 'string' or 'text' types
     * 3. Exclude: id, foreign keys, dates, numeric fields
     */
    private function discoverEmbedFields(Model $entity, array $properties): array
    {
        $textFields = [];
        $casts = $entity->getCasts();

        // Common text field names
        $textFieldPatterns = [
            'name', 'title', 'description', 'notes', 'content', 'body',
            'bio', 'summary', 'details', 'comment', 'message', 'text'
        ];

        foreach ($properties as $property) {
            // Skip IDs and foreign keys
            if ($property === 'id' || str_ends_with($property, '_id')) {
                continue;
            }

            // Skip dates
            if (str_ends_with($property, '_at') || str_ends_with($property, '_date')) {
                continue;
            }

            // Include if it matches text patterns
            foreach ($textFieldPatterns as $pattern) {
                if (str_contains(strtolower($property), $pattern)) {
                    $textFields[] = $property;
                    break;
                }
            }

            // Include if cast as string or text
            if (isset($casts[$property]) && in_array($casts[$property], ['string', 'text'])) {
                if (!in_array($property, $textFields)) {
                    $textFields[] = $property;
                }
            }
        }

        return $textFields;
    }

    /**
     * Discover semantic metadata
     *
     * - Aliases from table name
     * - Scopes from scopeX() methods
     * - Description from docblock
     */
    private function discoverMetadata(Model $entity): array
    {
        return [
            'aliases' => $this->discoverAliases($entity),
            'description' => $this->discoverDescription($entity),
            'scopes' => $this->discoverScopes($entity),
        ];
    }

    /**
     * Discover aliases from table name
     *
     * Examples:
     * - customers → ['customer', 'customers']
     * - people → ['person', 'people', 'user', 'users']
     * - orders → ['order', 'orders', 'purchase', 'purchases']
     */
    private function discoverAliases(Model $entity): array
    {
        $table = $entity->getTable();
        $singular = $this->singularize($table);
        $plural = $this->pluralize($singular);

        $aliases = [$singular, $plural];

        // Add common domain aliases
        $domainAliases = [
            'customers' => ['client', 'clients'],
            'people' => ['user', 'users', 'individual', 'individuals', 'member', 'members'],
            'orders' => ['purchase', 'purchases', 'sale', 'sales'],
            'products' => ['item', 'items'],
        ];

        if (isset($domainAliases[$table])) {
            $aliases = array_merge($aliases, $domainAliases[$table]);
        }

        return array_unique($aliases);
    }

    /**
     * Discover description from model docblock
     */
    private function discoverDescription(Model $entity): string
    {
        $reflection = new ReflectionClass($entity);
        $docComment = $reflection->getDocComment();

        if (!$docComment) {
            $label = $this->discoverLabel($entity);
            return "Represents {$label} entities in the system";
        }

        // Extract first line of docblock
        preg_match('/@description\s+(.+)/i', $docComment, $matches);
        if (!empty($matches[1])) {
            return trim($matches[1]);
        }

        // Fallback: use class summary
        preg_match('/\/\*\*\s*\n\s*\*\s*(.+)/i', $docComment, $matches);
        if (!empty($matches[1])) {
            return trim($matches[1]);
        }

        $label = $this->discoverLabel($entity);
        return "Represents {$label} entities in the system";
    }

    /**
     * Discover scopes from scopeX() methods
     *
     * Converts Eloquent scopes to semantic scope metadata:
     *
     * scopeActive() → 'active' scope with auto-generated Cypher
     */
    private function discoverScopes(Model $entity): array
    {
        $scopes = [];
        $reflection = new ReflectionClass($entity);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->getName();

            if (!str_starts_with($methodName, 'scope')) {
                continue;
            }

            // Extract scope name: scopeActive → active
            $scopeName = lcfirst(substr($methodName, 5));

            if (empty($scopeName)) {
                continue;
            }

            $scopes[$scopeName] = $this->buildScopeMetadata($entity, $scopeName, $method);
        }

        return $scopes;
    }

    /**
     * Build scope metadata from Eloquent scope method
     *
     * Analyzes the scope method to generate semantic metadata
     */
    private function buildScopeMetadata(Model $entity, string $scopeName, ReflectionMethod $method): array
    {
        // Try to extract where clauses from method body
        $sourceCode = $this->getMethodSource($method);

        $scope = [
            'description' => ucfirst($scopeName) . ' ' . strtolower($this->discoverLabel($entity)),
            'examples' => $this->generateScopeExamples($entity, $scopeName),
        ];

        // Try to parse simple where clauses
        $filter = $this->parseSimpleWhere($sourceCode);
        if ($filter) {
            $scope['filter'] = $filter;
            $scope['cypher_pattern'] = $this->buildCypherPattern($filter);
            $scope['specification_type'] = 'property_filter';
        }

        return $scope;
    }

    /**
     * Parse simple where clauses from scope method
     *
     * Handles:
     * - where('status', 'active')
     * - where('status', '=', 'active')
     */
    private function parseSimpleWhere(string $sourceCode): ?array
    {
        // Match: ->where('field', 'value')
        if (preg_match('/->where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"](.+?)[\'"]\s*\)/', $sourceCode, $matches)) {
            return [$matches[1] => $matches[2]];
        }

        // Match: ->where('field', '=', 'value')
        if (preg_match('/->where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]=[\'"],\s*[\'"](.+?)[\'"]\s*\)/', $sourceCode, $matches)) {
            return [$matches[1] => $matches[2]];
        }

        return null;
    }

    /**
     * Build Cypher pattern from filter array
     */
    private function buildCypherPattern(array $filter): string
    {
        $conditions = [];
        foreach ($filter as $key => $value) {
            $conditions[] = "{$key} = '{$value}'";
        }
        return implode(' AND ', $conditions);
    }

    /**
     * Generate example queries for scope
     */
    private function generateScopeExamples(Model $entity, string $scopeName): array
    {
        $label = $this->discoverLabel($entity);
        $labelLower = strtolower($label);
        $table = $entity->getTable();

        return [
            "Show {$scopeName} {$table}",
            "List {$scopeName} {$labelLower}",
            "How many {$scopeName} {$table}?",
            "Find {$scopeName} {$labelLower}",
        ];
    }

    /**
     * Get method source code
     */
    private function getMethodSource(ReflectionMethod $method): string
    {
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        if (!$filename || !$startLine || !$endLine) {
            return '';
        }

        $lines = file($filename);
        $methodLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);

        return implode('', $methodLines);
    }

    /**
     * Get cache key for entity
     */
    private function getCacheKey(Model $entity): string
    {
        return 'entity_discovery:' . get_class($entity);
    }

    /**
     * Singularize a word
     */
    private function singularize(string $word): string
    {
        return \Illuminate\Support\Str::singular($word);
    }

    /**
     * Pluralize a word
     */
    private function pluralize(string $word): string
    {
        return \Illuminate\Support\Str::plural($word);
    }
}
```

### 2. NodeableConfig Class (Fluent Builder)

Optional class for overriding auto-discovered config:

```php
namespace Condoedge\Ai\Domain;

use Illuminate\Database\Eloquent\Model;
use Condoedge\Ai\Services\EntityAutoDiscovery;

/**
 * Fluent builder for entity configuration
 *
 * Allows selective overrides of auto-discovered config
 */
class NodeableConfig
{
    private DiscoveredConfig $discovered;
    private array $overrides = [];

    private function __construct(DiscoveredConfig $discovered)
    {
        $this->discovered = $discovered;
    }

    /**
     * Start with auto-discovered config
     */
    public static function discover(Model $entity): self
    {
        $discovery = app(EntityAutoDiscovery::class);
        $discovered = $discovery->discover($entity);

        return new self($discovered);
    }

    /**
     * Start with blank config (no auto-discovery)
     */
    public static function blank(): self
    {
        return new self(new DiscoveredConfig());
    }

    // ========================================
    // Graph Configuration
    // ========================================

    public function label(string $label): self
    {
        $this->overrides['graph']['label'] = $label;
        return $this;
    }

    public function properties(array $properties): self
    {
        $this->overrides['graph']['properties'] = $properties;
        return $this;
    }

    public function addProperty(string $property): self
    {
        $this->overrides['graph']['add_properties'][] = $property;
        return $this;
    }

    public function removeProperty(string $property): self
    {
        $this->overrides['graph']['remove_properties'][] = $property;
        return $this;
    }

    public function addRelationship(
        string $type,
        string $targetLabel,
        string $foreignKey,
        array $properties = []
    ): self {
        $this->overrides['graph']['add_relationships'][] = compact(
            'type', 'targetLabel', 'foreignKey', 'properties'
        );
        return $this;
    }

    // ========================================
    // Vector Configuration
    // ========================================

    public function collection(string $collection): self
    {
        $this->overrides['vector']['collection'] = $collection;
        return $this;
    }

    public function embedFields(array $fields): self
    {
        $this->overrides['vector']['embed_fields'] = $fields;
        return $this;
    }

    public function addEmbedField(string $field): self
    {
        $this->overrides['vector']['add_embed_fields'][] = $field;
        return $this;
    }

    public function metadata(array $metadata): self
    {
        $this->overrides['vector']['metadata'] = $metadata;
        return $this;
    }

    // ========================================
    // Semantic Metadata
    // ========================================

    public function aliases(array $aliases): self
    {
        $this->overrides['metadata']['aliases'] = $aliases;
        return $this;
    }

    public function addAlias(string $alias): self
    {
        $this->overrides['metadata']['add_aliases'][] = $alias;
        return $this;
    }

    public function description(string $description): self
    {
        $this->overrides['metadata']['description'] = $description;
        return $this;
    }

    public function addScope(string $name, array $config): self
    {
        $this->overrides['metadata']['scopes'][$name] = $config;
        return $this;
    }

    // ========================================
    // Disable Auto-Discovery
    // ========================================

    public function disableVectorStore(): self
    {
        $this->overrides['vector'] = null;
        return $this;
    }

    public function disableAutoDiscovery(): self
    {
        $this->overrides['disable_discovery'] = true;
        return $this;
    }

    // ========================================
    // Build Final Config
    // ========================================

    /**
     * Build final configuration by merging overrides
     */
    public function build(): array
    {
        $config = $this->discovered->toArray();

        // Apply overrides
        foreach ($this->overrides as $section => $values) {
            if ($values === null) {
                unset($config[$section]);
                continue;
            }

            if (is_array($values)) {
                $config[$section] = $this->mergeRecursive(
                    $config[$section] ?? [],
                    $values
                );
            }
        }

        return $config;
    }

    /**
     * Convert to GraphConfig
     */
    public function toGraphConfig(): GraphConfig
    {
        $config = $this->build();
        return GraphConfig::fromArray($config['graph']);
    }

    /**
     * Convert to VectorConfig
     */
    public function toVectorConfig(): ?VectorConfig
    {
        $config = $this->build();

        if (!isset($config['vector'])) {
            return null;
        }

        return VectorConfig::fromArray($config['vector']);
    }

    private function mergeRecursive(array $base, array $overrides): array
    {
        // Handle add/remove operations
        if (isset($overrides['add_properties'])) {
            $base['properties'] = array_unique(array_merge(
                $base['properties'] ?? [],
                $overrides['add_properties']
            ));
            unset($overrides['add_properties']);
        }

        if (isset($overrides['remove_properties'])) {
            $base['properties'] = array_diff(
                $base['properties'] ?? [],
                $overrides['remove_properties']
            );
            unset($overrides['remove_properties']);
        }

        if (isset($overrides['add_relationships'])) {
            $base['relationships'] = array_merge(
                $base['relationships'] ?? [],
                $overrides['add_relationships']
            );
            unset($overrides['add_relationships']);
        }

        if (isset($overrides['add_embed_fields'])) {
            $base['embed_fields'] = array_unique(array_merge(
                $base['embed_fields'] ?? [],
                $overrides['add_embed_fields']
            ));
            unset($overrides['add_embed_fields']);
        }

        if (isset($overrides['add_aliases'])) {
            $base['aliases'] = array_unique(array_merge(
                $base['aliases'] ?? [],
                $overrides['add_aliases']
            ));
            unset($overrides['add_aliases']);
        }

        return array_merge($base, $overrides);
    }
}
```

### 3. DiscoveredConfig DTO

Value object to hold discovered configuration:

```php
namespace Condoedge\Ai\DTOs;

use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;

/**
 * Discovered entity configuration
 */
class DiscoveredConfig
{
    public function __construct(
        public readonly ?GraphConfig $graph = null,
        public readonly ?VectorConfig $vector = null,
        public readonly array $metadata = []
    ) {}

    public function toArray(): array
    {
        $array = [];

        if ($this->graph) {
            $array['graph'] = [
                'label' => $this->graph->label,
                'properties' => $this->graph->properties,
                'relationships' => array_map(
                    fn($r) => [
                        'type' => $r->type,
                        'target_label' => $r->targetLabel,
                        'foreign_key' => $r->foreignKey,
                        'properties' => $r->properties,
                    ],
                    $this->graph->relationships
                ),
            ];
        }

        if ($this->vector) {
            $array['vector'] = [
                'collection' => $this->vector->collection,
                'embed_fields' => $this->vector->embedFields,
                'metadata' => $this->vector->metadata,
            ];
        }

        if (!empty($this->metadata)) {
            $array['metadata'] = $this->metadata;
        }

        return $array;
    }
}
```

### 4. ConfigCache Service

Caches introspection results:

```php
namespace Condoedge\Ai\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Caches entity discovery results
 *
 * Introspection is expensive, so we cache the results
 */
class ConfigCache
{
    private string $prefix = 'ai:discovery:';
    private int $ttl;
    private bool $enabled;

    public function __construct()
    {
        $this->enabled = config('ai.discovery.cache_enabled', true);
        $this->ttl = config('ai.discovery.cache_ttl', 3600); // 1 hour
    }

    public function remember(string $key, callable $callback): mixed
    {
        if (!$this->enabled) {
            return $callback();
        }

        $fullKey = $this->prefix . $key;

        return Cache::remember($fullKey, $this->ttl, $callback);
    }

    public function forget(string $key): void
    {
        $fullKey = $this->prefix . $key;
        Cache::forget($fullKey);
    }

    public function flush(): void
    {
        // Clear all discovery cache
        Cache::flush();
    }

    /**
     * Clear cache for specific entity
     */
    public function forgetEntity(string $entityClass): void
    {
        $key = 'entity_discovery:' . $entityClass;
        $this->forget($key);
    }
}
```

---

## Updated HasNodeableConfig Trait

The trait now uses auto-discovery as the default:

```php
trait HasNodeableConfig
{
    // ... (existing auto-sync code remains) ...

    /**
     * Get Neo4j graph configuration
     *
     * Strategy:
     * 1. Check for nodeableConfig() method (explicit config)
     * 2. Check config/entities.php (legacy)
     * 3. Auto-discover from model (default)
     */
    public function getGraphConfig(): GraphConfig
    {
        // 1. Check for explicit nodeableConfig() method
        if (method_exists($this, 'nodeableConfig')) {
            $config = $this->nodeableConfig();
            if ($config instanceof NodeableConfig) {
                return $config->toGraphConfig();
            }
        }

        // 2. Check config file (legacy support)
        if ($this->hasLegacyConfig()) {
            $config = $this->loadEntityConfig();
            if (isset($config['graph'])) {
                return GraphConfig::fromArray($config['graph']);
            }
        }

        // 3. Auto-discover (default)
        $discovery = app(EntityAutoDiscovery::class);
        $discovered = $discovery->discover($this);

        if (!$discovered->graph) {
            throw new \LogicException(
                sprintf('Could not discover graph configuration for entity: %s', get_class($this))
            );
        }

        return $discovered->graph;
    }

    /**
     * Get Qdrant vector configuration
     */
    public function getVectorConfig(): VectorConfig
    {
        // 1. Check for explicit nodeableConfig() method
        if (method_exists($this, 'nodeableConfig')) {
            $config = $this->nodeableConfig();
            if ($config instanceof NodeableConfig) {
                $vectorConfig = $config->toVectorConfig();
                if ($vectorConfig) {
                    return $vectorConfig;
                }
            }
        }

        // 2. Check config file (legacy support)
        if ($this->hasLegacyConfig()) {
            $config = $this->loadEntityConfig();
            if (isset($config['vector'])) {
                return VectorConfig::fromArray($config['vector']);
            }
        }

        // 3. Auto-discover (default)
        $discovery = app(EntityAutoDiscovery::class);
        $discovered = $discovery->discover($this);

        if (!$discovered->vector) {
            throw new \LogicException(
                sprintf('Entity is not searchable: %s. No text fields found for embedding.', get_class($this))
            );
        }

        return $discovered->vector;
    }

    /**
     * Check if entity has legacy config file
     */
    private function hasLegacyConfig(): bool
    {
        $configKey = $this->getConfigKey();
        return config("ai.entities.{$configKey}") !== null;
    }
}
```

---

## Key Design Questions Answered

### 1. How to detect which relationships should be in Neo4j?

**Strategy:** Only auto-discover `belongsTo` relationships.

**Reasoning:**
- `belongsTo` represents the "ownership" direction most useful for graph traversal
- `hasMany` is the inverse and will be discovered from the target entity
- `belongsToMany` requires pivot table handling (too complex for auto-discovery)
- `morphTo` can be included with special handling

**Example:**
```php
// Order.php
public function customer() {
    return $this->belongsTo(Customer::class);
}
// → Auto-discovered: Order -[BELONGS_TO_CUSTOMER]-> Customer

// Customer.php
public function orders() {
    return $this->hasMany(Order::class);
}
// → Ignored (inverse of Order's belongsTo)
```

### 2. How to convert Eloquent scopes to Cypher patterns?

**Strategy:** Parse simple where clauses automatically, manual config for complex scopes.

**Auto-parsed:**
```php
public function scopeActive($query) {
    return $query->where('status', 'active');
}
// → Cypher: status = 'active'
```

**Manual (complex):**
```php
public function scopeHighValue($query) {
    return $query->where('lifetime_value', '>', 10000)
                 ->whereHas('orders', fn($q) => $q->where('status', 'completed'));
}
// → Needs nodeableConfig() override
```

### 3. How to determine which fields should be embedded?

**Strategy:** Heuristic-based detection:
1. Common text field names: `name`, `title`, `description`, `notes`, `content`, `body`
2. Fields cast as `string` or `text`
3. Exclude: IDs, foreign keys, dates, numeric fields

**Example:**
```php
protected $fillable = ['name', 'email', 'description', 'status', 'lifetime_value'];
protected $casts = ['lifetime_value' => 'decimal', 'description' => 'text'];

// Auto-discovered embed_fields: ['name', 'description']
// Auto-discovered metadata: ['id', 'email', 'status', 'lifetime_value']
```

### 4. Caching strategy for introspection results?

**Strategy:** Multi-level caching:

1. **Application Cache (1 hour TTL)**
   - Cache introspection results per entity class
   - Cleared on model changes (optional)
   - Configurable TTL

2. **Development Mode**
   - Cache disabled in local environment
   - Always fresh introspection during development

3. **Cache Warming**
   - Artisan command to pre-warm cache: `php artisan ai:discover:cache`
   - Run during deployment

4. **Cache Keys**
   - Pattern: `ai:discovery:{EntityClass}`
   - Easy to clear specific entities

**Configuration:**
```php
// config/ai.php
'discovery' => [
    'cache_enabled' => env('AI_DISCOVERY_CACHE', true),
    'cache_ttl' => env('AI_DISCOVERY_CACHE_TTL', 3600), // 1 hour
    'cache_driver' => env('AI_DISCOVERY_CACHE_DRIVER', 'file'),
],
```

### 5. Backward compatibility with existing config?

**Strategy:** Graceful fallback chain:

1. **Explicit `nodeableConfig()` method** (highest priority)
2. **config/entities.php** (legacy support)
3. **Auto-discovery** (default)

This allows:
- Existing projects continue working unchanged
- Gradual migration to auto-discovery
- Mix of auto-discovery and manual config

**Migration path:**
```php
// Phase 1: Legacy (works as before)
// config/entities.php has full config

// Phase 2: Gradual migration
class Customer extends Model {
    public function nodeableConfig() {
        return NodeableConfig::discover($this)
            ->addAlias('client'); // Only override what's needed
    }
}
// config/entities.php can be removed

// Phase 3: Full auto-discovery
class Customer extends Model {
    // No config needed!
}
```

---

## Usage Examples

### Example 1: Zero Configuration

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'description', 'status'];

    public function orders() {
        return $this->hasMany(Order::class);
    }

    public function scopeActive($query) {
        return $query->where('status', 'active');
    }
}

// That's it! Everything auto-discovered:
// - Label: "Customer"
// - Properties: ['id', 'name', 'email', 'description', 'status', 'created_at', 'updated_at']
// - Relationships: (none - hasMany is ignored)
// - Collection: "customers"
// - Embed fields: ['name', 'description']
// - Metadata: ['id', 'email', 'status']
// - Aliases: ['customer', 'customers', 'client', 'clients']
// - Scopes: ['active']
```

### Example 2: Minimal Overrides

```php
class Product extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'sku', 'price', 'description'];

    public function category() {
        return $this->belongsTo(Category::class);
    }

    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->embedFields(['name', 'description', 'sku']) // Add SKU
            ->addAlias('item'); // Add custom alias
    }
}
```

### Example 3: Complex Relationships

```php
class Person extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['first_name', 'last_name', 'email', 'type', 'status'];

    public function team() {
        return $this->belongsTo(Team::class);
    }

    public function scopeVolunteers($query) {
        return $query->where('type', 'volunteer');
    }

    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->addRelationship(
                type: 'HAS_ROLE',
                targetLabel: 'PersonTeam',
                foreignKey: 'person_id'
            )
            ->addScope('volunteers', [
                'specification_type' => 'relationship_traversal',
                'concept' => 'People who volunteer on teams',
                'relationship_spec' => [
                    'start_entity' => 'Person',
                    'path' => [
                        [
                            'relationship' => 'HAS_ROLE',
                            'target_entity' => 'PersonTeam',
                            'direction' => 'outgoing',
                        ],
                    ],
                    'filter' => [
                        'entity' => 'PersonTeam',
                        'property' => 'role_type',
                        'operator' => 'equals',
                        'value' => 'volunteer',
                    ],
                ],
            ]);
    }
}
```

### Example 4: Graph-Only Entity

```php
class Team extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'created_at'];

    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->disableVectorStore(); // Graph only, no vector search
    }
}
```

### Example 5: Full Manual Override

```php
class CustomEntity extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::blank() // No auto-discovery
            ->label('SpecialEntity')
            ->properties(['id', 'custom_field_1', 'custom_field_2'])
            ->collection('special_collection')
            ->embedFields(['custom_field_1'])
            ->metadata(['id', 'custom_field_2'])
            ->aliases(['special', 'custom'])
            ->description('Custom entity with no auto-discovery');
    }
}
```

---

## Migration Strategy

### Phase 1: Add Auto-Discovery (Non-Breaking)

1. **Add new classes** (no changes to existing code):
   - `EntityAutoDiscovery`
   - `NodeableConfig`
   - `DiscoveredConfig`
   - `ConfigCache`

2. **Update `HasNodeableConfig` trait**:
   - Add auto-discovery fallback
   - Keep existing config file loading

3. **Test with new models**:
   - Create test models using auto-discovery
   - Verify introspection works correctly

**Status:** Existing projects unaffected, new projects can use auto-discovery

### Phase 2: Gradual Migration

1. **Add migration guide** for existing projects
2. **Provide artisan command** to preview auto-discovered config:
   ```bash
   php artisan ai:discover Customer
   # Outputs discovered config for review
   ```

3. **Migrate entity by entity**:
   - Review auto-discovered config
   - Remove from config/entities.php
   - Add `nodeableConfig()` only if overrides needed

**Status:** Projects can migrate at their own pace

### Phase 3: Deprecate Config File

1. **Mark config/entities.php as deprecated** (still works)
2. **Update documentation** to use auto-discovery
3. **Log warnings** when loading from config file

**Status:** Encourage migration, but don't break existing projects

### Phase 4: Remove Legacy Support (Optional)

1. **Remove config file loading** from trait
2. **Require `nodeableConfig()` or auto-discovery**

**Status:** Clean codebase, but breaking change (major version)

---

## Configuration

### config/ai.php

Add discovery section:

```php
return [
    // ... existing config ...

    /*
    |--------------------------------------------------------------------------
    | Entity Auto-Discovery
    |--------------------------------------------------------------------------
    |
    | Configure how entities are auto-discovered from Eloquent models.
    |
    */
    'discovery' => [
        // Enable auto-discovery (set to false to require explicit config)
        'enabled' => env('AI_DISCOVERY_ENABLED', true),

        // Cache discovery results
        'cache_enabled' => env('AI_DISCOVERY_CACHE', true),
        'cache_ttl' => env('AI_DISCOVERY_CACHE_TTL', 3600), // 1 hour
        'cache_driver' => env('AI_DISCOVERY_CACHE_DRIVER', 'file'),

        // Auto-discover relationships
        'relationships' => [
            // Which relationship types to auto-discover
            'types' => ['belongsTo', 'morphTo'],

            // Skip relationships with these names
            'exclude' => ['user', 'creator', 'updater'],
        ],

        // Auto-discover scopes
        'scopes' => [
            // Enable scope discovery
            'enabled' => true,

            // Skip scopes with these names
            'exclude' => [],
        ],

        // Auto-discover embed fields
        'embed_fields' => [
            // Text field patterns to include
            'patterns' => [
                'name', 'title', 'description', 'notes', 'content', 'body',
                'bio', 'summary', 'details', 'comment', 'message', 'text',
            ],

            // Cast types to include
            'casts' => ['string', 'text'],
        ],
    ],
];
```

---

## Artisan Commands

### 1. Preview Discovered Config

```bash
php artisan ai:discover Customer
```

Output:
```
Auto-discovered configuration for Customer:

Graph Config:
  Label: Customer
  Properties: id, name, email, status, created_at, updated_at
  Relationships:
    - BELONGS_TO_TEAM (team_id → Team)

Vector Config:
  Collection: customers
  Embed Fields: name, email
  Metadata: id, status, created_at

Metadata:
  Aliases: customer, customers, client, clients
  Description: Represents Customer entities in the system
  Scopes: active

Use this config? Run: php artisan ai:discover:cache Customer
```

### 2. Cache Discovered Config

```bash
php artisan ai:discover:cache [entity]
```

Warm the cache for one or all entities.

### 3. Clear Discovery Cache

```bash
php artisan ai:discover:clear [entity]
```

Clear cache for one or all entities.

### 4. Compare Config

```bash
php artisan ai:discover:compare Customer
```

Compare auto-discovered config with config/entities.php:

```
Comparing Customer configuration:

Config File          Auto-Discovered     Status
-----------------    -----------------   ------
Label: Customer      Customer            ✓ Match
Properties: 7        6                   ⚠ Differ
  - Missing: country
Relationships: 1     1                   ✓ Match
Embed Fields: 3      2                   ⚠ Differ
  - Extra: description

Recommendation: Add 'country' to $fillable, or override in nodeableConfig()
```

---

## Testing Strategy

### Unit Tests

1. **EntityAutoDiscovery**
   - Test label discovery
   - Test property discovery from $fillable, $casts, $dates
   - Test relationship discovery (belongsTo only)
   - Test embed field detection
   - Test alias generation
   - Test scope discovery and parsing

2. **NodeableConfig**
   - Test fluent builder methods
   - Test override merging
   - Test add/remove operations
   - Test conversion to GraphConfig/VectorConfig

3. **ConfigCache**
   - Test caching behavior
   - Test cache invalidation
   - Test cache key generation

### Integration Tests

1. **Full Auto-Discovery**
   - Create test models with various configurations
   - Verify correct config generation
   - Test with real Neo4j/Qdrant stores

2. **Override Scenarios**
   - Test nodeableConfig() overrides
   - Test partial overrides
   - Test disabling features

3. **Backward Compatibility**
   - Test config file loading still works
   - Test fallback chain priority
   - Test mixed config sources

### Feature Tests

1. **Real-World Scenarios**
   - Customer/Order relationship
   - Person/Team relationship with scopes
   - File entity with auto-discovery

---

## Benefits Summary

### For Developers

- **80% less configuration** for typical entities
- **Convention over configuration** (Laravel-style)
- **No duplication** of Eloquent definitions
- **Auto-discovery "just works"** for most cases
- **Gradual migration** path

### For Maintainability

- **Single source of truth** (Eloquent model)
- **Less configuration to maintain**
- **Easier to refactor** (change $fillable, auto-updates)
- **Type-safe** config building (NodeableConfig)

### For New Projects

- **Instant RAG integration** (just use trait)
- **No manual configuration** needed
- **Faster onboarding** (less to learn)

---

## Implementation Checklist

- [ ] Create `EntityAutoDiscovery` service
- [ ] Create `NodeableConfig` fluent builder
- [ ] Create `DiscoveredConfig` DTO
- [ ] Create `ConfigCache` service
- [ ] Update `HasNodeableConfig` trait with auto-discovery
- [ ] Add discovery config to `config/ai.php`
- [ ] Create artisan commands:
  - [ ] `ai:discover`
  - [ ] `ai:discover:cache`
  - [ ] `ai:discover:clear`
  - [ ] `ai:discover:compare`
- [ ] Write unit tests for all components
- [ ] Write integration tests
- [ ] Write migration guide
- [ ] Update documentation
- [ ] Create example models in README
- [ ] Add to getting started guide

---

## Future Enhancements

### Phase 2 Features

1. **Relationship Auto-Discovery++**
   - Handle `belongsToMany` with pivot config
   - Handle polymorphic relationships
   - Infer relationship types from method names

2. **Scope Auto-Discovery++**
   - Parse complex where clauses
   - Convert whereHas to graph patterns
   - Support dynamic scopes

3. **Smart Defaults**
   - ML-based field type detection
   - Learn from similar entities
   - Suggest optimizations

4. **IDE Integration**
   - PHPStorm plugin for previewing config
   - Autocomplete for nodeableConfig()
   - Inline config validation

5. **Visual Config Builder**
   - Web UI for reviewing/tweaking config
   - Export to nodeableConfig() code
   - Compare different strategies

---

## Conclusion

This design achieves the goal of **Laravel-style simplicity** for RAG/Neo4j integration:

- **Zero configuration** for 80% of use cases
- **Minimal overrides** when needed
- **Backward compatible** with existing projects
- **Type-safe** and maintainable
- **Extensible** for future enhancements

The auto-discovery system leverages existing Eloquent definitions, eliminating duplication and making RAG integration as easy as defining relationships.
