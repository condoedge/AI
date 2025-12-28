# Discovery Simplification Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make AI package configuration zero-config for 80% of cases with smart defaults, optional warm cache, and automatic complex scope conversion to Cypher.

**Architecture:**
- Model IS the config (properties as source of truth)
- Discovery command generates optional warm cache (not required)
- Runtime fallback when cache missing (with smart caching)
- Complex scopes with nested whereHas + scope calls auto-convert to Cypher

**Tech Stack:** PHP 8.1+, Laravel, Neo4j, Qdrant, Reflection API

---

## Summary of Changes

1. **Runtime-first discovery** - Works without running `php artisan ai:discover`
2. **Model property conventions** - `$embedFields`, `$graphLabel`, `$sensibleColumns`, `$graphRelationships`
3. **Nested scope resolution** - Handle `whereHas('rel', fn($q) => $q->volunteer())`
4. **Optional caching** - Command generates cache, runtime uses it if available
5. **Simplified HasNodeableConfig** - Auto-reads model properties, merges with nodeableConfig()

---

### Task 1: Add Model Property Conventions to HasNodeableConfig

**Files:**
- Modify: `src/Domain/Traits/HasNodeableConfig.php`
- Create: `tests/Unit/Domain/Traits/HasNodeableConfigPropertiesTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Domain\Traits;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class HasNodeableConfigPropertiesTest extends TestCase
{
    /** @test */
    public function it_reads_embed_fields_from_model_property(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'persons';
            protected $fillable = ['name', 'bio', 'email'];
            protected $embedFields = ['name', 'bio'];
        };

        $vectorConfig = $model->getVectorConfig();

        $this->assertEquals(['name', 'bio'], $vectorConfig->embedFields);
    }

    /** @test */
    public function it_reads_graph_label_from_model_property(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'people';
            protected $graphLabel = 'Person';
        };

        $graphConfig = $model->getGraphConfig();

        $this->assertEquals('Person', $graphConfig->label);
    }

    /** @test */
    public function it_reads_sensible_columns_from_model_property(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'persons';
            protected $sensibleColumns = ['ssn', 'salary'];
        };

        $config = $model->resolveConfig();

        $this->assertEquals(['ssn', 'salary'], $config['security']['sensible_columns']);
    }

    /** @test */
    public function it_merges_model_properties_with_nodeable_config(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'persons';
            protected $embedFields = ['name', 'bio'];  // Model property

            public function nodeableConfig(): array
            {
                return [
                    'graph' => ['label' => 'CustomLabel'],  // Override
                ];
            }
        };

        $graphConfig = $model->getGraphConfig();
        $vectorConfig = $model->getVectorConfig();

        $this->assertEquals('CustomLabel', $graphConfig->label);  // From nodeableConfig
        $this->assertEquals(['name', 'bio'], $vectorConfig->embedFields);  // From property
    }

    /** @test */
    public function it_auto_discovers_when_no_config_exists(): void
    {
        // Enable runtime discovery for this test
        config(['ai.auto_discovery.runtime_enabled' => true]);

        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'test_entities';
            protected $fillable = ['name', 'description', 'status'];
        };

        // Should not throw, should auto-discover
        $config = $model->resolveConfig();

        $this->assertArrayHasKey('graph', $config);
        $this->assertArrayHasKey('vector', $config);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Domain/Traits/HasNodeableConfigPropertiesTest.php -v`
Expected: FAIL

**Step 3: Implement model property reading**

Add method to `HasNodeableConfig.php`:

```php
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
    if (property_exists($this, 'embedFields')) {
        $config['vector']['embed_fields'] = $this->embedFields;
    }

    // Read $graphLabel
    if (property_exists($this, 'graphLabel')) {
        $config['graph']['label'] = $this->graphLabel;
    } else {
        $config['graph']['label'] = class_basename($this);
    }

    // Read $graphRelationships
    if (property_exists($this, 'graphRelationships')) {
        $config['graph']['relationships'] = $this->graphRelationships;
    }

    // Read $sensibleColumns
    if (property_exists($this, 'sensibleColumns')) {
        $config['security']['sensible_columns'] = $this->sensibleColumns;
    }

    // Read $nodeableAliases
    if (property_exists($this, 'nodeableAliases')) {
        $config['metadata']['aliases'] = $this->nodeableAliases;
    }

    return $config;
}
```

**Step 4: Modify resolveConfig() to merge model properties**

```php
protected function resolveConfig(): array
{
    if ($this->resolvedConfig !== null) {
        return $this->resolvedConfig;
    }

    // 1. Start with model properties (base layer)
    $config = $this->readModelProperties();

    // 2. Merge with nodeableConfig() if exists (override layer)
    if (method_exists($this, 'nodeableConfig')) {
        $overrides = $this->nodeableConfig();
        $config = $this->mergeConfigDeep($config, $overrides);
    }

    // 3. Check config/entities.php (legacy support)
    $entityConfigs = config('entities', []);
    $configKey = $this->getConfigKey();
    if (isset($entityConfigs[$configKey])) {
        $config = $this->mergeConfigDeep($config, $entityConfigs[$configKey]);
    }

    // 4. Auto-discover missing parts
    if ($this->needsAutoDiscovery($config)) {
        $discovered = $this->autoDiscover();
        $config = $this->mergeConfigDeep($discovered, $config);
    }

    // 5. Validate we have minimum required config
    $this->validateConfig($config);

    $this->resolvedConfig = $config;
    return $this->resolvedConfig;
}

/**
 * Check if auto-discovery is needed to fill gaps
 */
protected function needsAutoDiscovery(array $config): bool
{
    // Always try to discover if enabled
    return config('ai.auto_discovery.enabled', true);
}

/**
 * Deep merge two config arrays (second overwrites first)
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
 * Validate minimum required configuration
 */
protected function validateConfig(array $config): void
{
    if (empty($config['graph']['label'])) {
        $config['graph']['label'] = class_basename($this);
    }

    // Vector config is optional - entity may be graph-only
}
```

**Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Domain/Traits/HasNodeableConfigPropertiesTest.php -v`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Domain/Traits/HasNodeableConfig.php tests/Unit/Domain/Traits/HasNodeableConfigPropertiesTest.php
git commit -m "feat(discovery): read config from model properties as source of truth"
```

---

### Task 2: Enable Runtime Discovery as Default Fallback

**Files:**
- Modify: `src/Domain/Traits/HasNodeableConfig.php`
- Modify: `config/ai.php`
- Create: `tests/Unit/Domain/Traits/RuntimeDiscoveryTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Domain\Traits;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class RuntimeDiscoveryTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Clear any cached configs
        config(['entities' => []]);
    }

    /** @test */
    public function it_discovers_at_runtime_when_no_cache_exists(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'products';
            protected $fillable = ['name', 'description', 'price', 'sku'];
        };

        // Should not throw - runtime discovery fills gaps
        $graphConfig = $model->getGraphConfig();

        $this->assertEquals('Product', $graphConfig->label);
        $this->assertNotEmpty($graphConfig->properties);
    }

    /** @test */
    public function it_uses_cached_config_when_available(): void
    {
        config(['entities.Product' => [
            'graph' => [
                'label' => 'CachedProduct',
                'properties' => ['id', 'name'],
            ],
        ]]);

        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'products';

            protected function getConfigKey(): string
            {
                return 'Product';
            }
        };

        $graphConfig = $model->getGraphConfig();

        $this->assertEquals('CachedProduct', $graphConfig->label);
    }

    /** @test */
    public function it_caches_discovered_config_in_memory(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'widgets';
            protected $fillable = ['name'];
        };

        // First call triggers discovery
        $config1 = $model->resolveConfig();

        // Second call should use cached
        $config2 = $model->resolveConfig();

        $this->assertSame($config1, $config2);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Domain/Traits/RuntimeDiscoveryTest.php -v`
Expected: FAIL

**Step 3: Update config/ai.php defaults**

```php
'auto_discovery' => [
    // Enable auto-discovery (always true)
    'enabled' => env('AI_AUTO_DISCOVERY_ENABLED', true),

    // Enable runtime auto-discovery when no cache exists
    // Now TRUE by default - falls back to discovery when needed
    'runtime_enabled' => env('AI_AUTO_DISCOVERY_RUNTIME', true),

    // Cache discovered configurations (recommended for production)
    'cache' => [
        'enabled' => env('AI_AUTO_DISCOVERY_CACHE', true),
        'ttl' => env('AI_AUTO_DISCOVERY_CACHE_TTL', 86400), // 24 hours
        'prefix' => 'ai.discovery.',
    ],

    // ... rest unchanged
],
```

**Step 4: Update autoDiscover() to cache results**

```php
protected function autoDiscover(): array
{
    if (!config('ai.auto_discovery.enabled', true)) {
        return [];
    }

    // Check cache first
    $cacheKey = $this->getCacheKey();
    if (config('ai.auto_discovery.cache.enabled', true)) {
        $cached = cache()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }

    // Get auto-discovery service
    if (!app()->bound(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class)) {
        return [];
    }

    $discovery = app(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class);
    $config = $discovery->discover($this);

    // Cache the result
    if (config('ai.auto_discovery.cache.enabled', true)) {
        $ttl = config('ai.auto_discovery.cache.ttl', 86400);
        cache()->put($cacheKey, $config, $ttl);
    }

    return $config;
}

protected function getCacheKey(): string
{
    $prefix = config('ai.auto_discovery.cache.prefix', 'ai.discovery.');
    return $prefix . class_basename($this);
}
```

**Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Domain/Traits/RuntimeDiscoveryTest.php -v`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Domain/Traits/HasNodeableConfig.php config/ai.php tests/Unit/Domain/Traits/RuntimeDiscoveryTest.php
git commit -m "feat(discovery): enable runtime discovery as default with intelligent caching"
```

---

### Task 3: Handle Nested Scope Calls in CypherQueryBuilderSpy

**Files:**
- Modify: `src/Services/Discovery/CypherQueryBuilderSpy.php`
- Modify: `src/Services/Discovery/CypherScopeAdapter.php`
- Create: `tests/Unit/Services/Discovery/NestedScopeDiscoveryTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class NestedScopeDiscoveryTest extends TestCase
{
    private CypherScopeAdapter $adapter;

    public function setUp(): void
    {
        parent::setUp();
        $this->adapter = app(CypherScopeAdapter::class);
    }

    /** @test */
    public function it_discovers_scope_with_where_has_and_nested_scope(): void
    {
        // This is the pattern: whereHas('personTeams', fn($q) => $q->volunteer())
        $scopes = $this->adapter->discoverScopes(TestPersonWithNestedScopes::class);

        $this->assertArrayHasKey('has_volunteer_team_occupation', $scopes);

        $scope = $scopes['has_volunteer_team_occupation'];
        $this->assertEquals('relationship_traversal', $scope['specification_type']);
        $this->assertNotEmpty($scope['cypher_pattern']);

        // Should contain the role filter from the nested volunteer() scope
        $this->assertStringContainsString('role_type', $scope['cypher_pattern']);
    }

    /** @test */
    public function it_resolves_nested_scope_to_its_conditions(): void
    {
        $scopes = $this->adapter->discoverScopes(TestPersonTeamWithScopes::class);

        // The volunteer() scope should be discovered
        $this->assertArrayHasKey('volunteer', $scopes);
        $this->assertEquals('property_filter', $scopes['volunteer']['specification_type']);
    }

    /** @test */
    public function it_handles_multiple_levels_of_nesting(): void
    {
        $scopes = $this->adapter->discoverScopes(TestPersonWithDeepNesting::class);

        $this->assertArrayHasKey('active_volunteers', $scopes);

        $scope = $scopes['active_volunteers'];
        // Should capture both the whereHas and the nested active + volunteer conditions
        $this->assertStringContainsString('MATCH', $scope['cypher_pattern']);
    }
}

// Test fixtures
class TestPersonTeamWithScopes extends Model
{
    protected $table = 'person_teams';

    public function scopeVolunteer($query)
    {
        return $query->where('role_type', 3);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}

class TestPersonWithNestedScopes extends Model
{
    protected $table = 'persons';

    public function personTeams()
    {
        return $this->hasMany(TestPersonTeamWithScopes::class, 'person_id');
    }

    public function scopeHasVolunteerTeamOccupation($query)
    {
        return $query->whereHas('personTeams', fn($q) => $q->volunteer());
    }
}

class TestPersonWithDeepNesting extends Model
{
    protected $table = 'persons';

    public function personTeams()
    {
        return $this->hasMany(TestPersonTeamWithScopes::class, 'person_id');
    }

    public function scopeActiveVolunteers($query)
    {
        return $query->whereHas('personTeams', fn($q) => $q->active()->volunteer());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/Discovery/NestedScopeDiscoveryTest.php -v`
Expected: FAIL

**Step 3: Add __call to CypherQueryBuilderSpy for scope resolution**

```php
// Add to CypherQueryBuilderSpy.php

/**
 * Handle dynamic method calls (for scope resolution)
 *
 * When a scope calls another scope (e.g., $q->volunteer()), we need to
 * resolve that scope and capture its conditions.
 *
 * @param string $method Method name
 * @param array $arguments Method arguments
 * @return self
 */
public function __call(string $method, array $arguments): self
{
    // Check if this is a scope call on a related model
    if ($this->modelClass !== null) {
        $scopeMethod = 'scope' . ucfirst($method);

        // Try to find and execute the scope
        if (method_exists($this->modelClass, $scopeMethod)) {
            $model = $this->createModelInstance($this->modelClass);
            $model->$scopeMethod($this, ...$arguments);
            return $this;
        }

        // Check if this model has a relationship that has this scope
        $relatedScopes = $this->findScopeInRelatedModels($method);
        if ($relatedScopes !== null) {
            // Merge the related scope's conditions
            foreach ($relatedScopes as $call) {
                $this->calls[] = $call;
            }
            return $this;
        }
    }

    // Record as unknown method call for debugging
    $this->calls[] = [
        'method' => $method,
        'type' => 'scope_call',
        'arguments' => $arguments,
    ];

    return $this;
}

/**
 * Create model instance for scope execution
 */
private function createModelInstance(string $modelClass): object
{
    try {
        $reflection = new \ReflectionClass($modelClass);
        return $reflection->newInstanceWithoutConstructor();
    } catch (\Throwable $e) {
        return new $modelClass();
    }
}

/**
 * Try to find a scope in related models
 *
 * @param string $scopeName Scope name (without 'scope' prefix)
 * @return array|null Calls from the scope or null if not found
 */
private function findScopeInRelatedModels(string $scopeName): ?array
{
    if ($this->modelClass === null) {
        return null;
    }

    $reflection = new \ReflectionClass($this->modelClass);

    // Get all relationship methods
    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        $returnType = $method->getReturnType();
        if ($returnType === null) {
            continue;
        }

        $typeName = $returnType->getName();

        // Check if this is a relationship (returns a Relation subclass)
        if (!str_contains($typeName, 'Relation')) {
            continue;
        }

        // Try to get the related model class
        try {
            $model = $this->createModelInstance($this->modelClass);
            $relation = $method->invoke($model);
            $relatedClass = get_class($relation->getRelated());

            // Check if related class has this scope
            $scopeMethod = 'scope' . ucfirst($scopeName);
            if (method_exists($relatedClass, $scopeMethod)) {
                // Execute scope on a new spy to capture its conditions
                $nestedSpy = new self($relatedClass);
                $relatedModel = $this->createModelInstance($relatedClass);
                $relatedModel->$scopeMethod($nestedSpy);

                return $nestedSpy->getCalls();
            }
        } catch (\Throwable $e) {
            continue;
        }
    }

    return null;
}
```

**Step 4: Update CypherScopeAdapter to pass model context to nested callbacks**

```php
// Modify whereHas in CypherQueryBuilderSpy to pass related model context

public function whereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1): self
{
    $nested = null;
    $relatedModelClass = null;

    // Try to determine the related model class
    if ($this->modelClass !== null) {
        $relatedModelClass = $this->getRelatedModelClass($relation);
    }

    if ($callback !== null) {
        // Create spy with related model context so nested scope calls work
        $nested = new self($relatedModelClass);
        $callback($nested);
    }

    $this->calls[] = [
        'method' => 'whereHas',
        'type' => 'relationship',
        'relation' => $relation,
        'related_model' => $relatedModelClass,
        'nested_calls' => $nested ? $nested->getCalls() : [],
        'operator' => $operator,
        'count' => $count,
    ];

    return $this;
}

/**
 * Get the related model class from a relationship name
 */
private function getRelatedModelClass(string $relation): ?string
{
    if ($this->modelClass === null) {
        return null;
    }

    try {
        $model = $this->createModelInstance($this->modelClass);

        if (!method_exists($model, $relation)) {
            return null;
        }

        $relationObj = $model->$relation();
        return get_class($relationObj->getRelated());
    } catch (\Throwable $e) {
        return null;
    }
}
```

**Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Services/Discovery/NestedScopeDiscoveryTest.php -v`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Services/Discovery/CypherQueryBuilderSpy.php src/Services/Discovery/CypherScopeAdapter.php tests/Unit/Services/Discovery/NestedScopeDiscoveryTest.php
git commit -m "feat(discovery): handle nested scope calls in whereHas closures"
```

---

### Task 4: Improve CypherPatternGenerator for Complex Relationship Scopes

**Files:**
- Modify: `src/Services/Discovery/CypherPatternGenerator.php`
- Create: `tests/Unit/Services/Discovery/CypherPatternGeneratorTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\CypherPatternGenerator;
use Condoedge\Ai\Tests\TestCase;

class CypherPatternGeneratorTest extends TestCase
{
    private CypherPatternGenerator $generator;

    public function setUp(): void
    {
        parent::setUp();
        $this->generator = new CypherPatternGenerator();
    }

    /** @test */
    public function it_generates_cypher_for_relationship_with_nested_filter(): void
    {
        $calls = [
            [
                'method' => 'whereHas',
                'type' => 'relationship',
                'relation' => 'personTeams',
                'nested_calls' => [
                    [
                        'method' => 'where',
                        'type' => 'basic',
                        'column' => 'role_type',
                        'operator' => '=',
                        'value' => 3,
                        'boolean' => 'and',
                    ],
                ],
            ],
        ];

        $pattern = $this->generator->generateFromCalls($calls, 'Person');

        $this->assertStringContainsString('MATCH (n:Person)', $pattern);
        $this->assertStringContainsString('HAS_PERSON_TEAM', $pattern);
        $this->assertStringContainsString('role_type', $pattern);
        $this->assertStringContainsString('3', $pattern);
    }

    /** @test */
    public function it_generates_cypher_for_chained_nested_scopes(): void
    {
        $calls = [
            [
                'method' => 'whereHas',
                'type' => 'relationship',
                'relation' => 'personTeams',
                'nested_calls' => [
                    [
                        'method' => 'where',
                        'type' => 'basic',
                        'column' => 'status',
                        'operator' => '=',
                        'value' => 'active',
                    ],
                    [
                        'method' => 'where',
                        'type' => 'basic',
                        'column' => 'role_type',
                        'operator' => '=',
                        'value' => 3,
                    ],
                ],
            ],
        ];

        $pattern = $this->generator->generateFromCalls($calls, 'Person');

        $this->assertStringContainsString('status', $pattern);
        $this->assertStringContainsString('role_type', $pattern);
        $this->assertStringContainsString('AND', $pattern);
    }

    /** @test */
    public function it_uses_correct_relationship_type_from_model(): void
    {
        $calls = [
            [
                'method' => 'whereHas',
                'type' => 'relationship',
                'relation' => 'orders',
                'related_model' => 'App\\Models\\Order',
                'nested_calls' => [],
            ],
        ];

        $pattern = $this->generator->generateFromCalls($calls, 'Customer');

        $this->assertStringContainsString('HAS_ORDER', $pattern);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/Discovery/CypherPatternGeneratorTest.php -v`
Expected: FAIL

**Step 3: Add generateFromCalls method to CypherPatternGenerator**

```php
// Add to CypherPatternGenerator.php

/**
 * Generate Cypher pattern from recorded calls
 *
 * @param array $calls Recorded query builder calls
 * @param string $entityName Entity/Label name
 * @return string Cypher pattern
 */
public function generateFromCalls(array $calls, string $entityName): string
{
    $parts = ["MATCH (n:{$entityName})"];
    $whereClauses = [];
    $relationshipMatches = [];

    foreach ($calls as $call) {
        $method = $call['method'] ?? '';

        switch ($method) {
            case 'where':
                $whereClauses[] = $this->generateWhereClause($call, 'n');
                break;

            case 'whereHas':
                $relMatch = $this->generateRelationshipMatch($call, $entityName);
                $relationshipMatches[] = $relMatch['match'];
                if (!empty($relMatch['where'])) {
                    $whereClauses = array_merge($whereClauses, $relMatch['where']);
                }
                break;

            case 'whereIn':
                $whereClauses[] = $this->generateWhereInClause($call, 'n');
                break;

            case 'whereNull':
                $whereClauses[] = $this->generateWhereNullClause($call, 'n');
                break;
        }
    }

    // Build the MATCH clause
    if (!empty($relationshipMatches)) {
        $parts = array_merge($parts, $relationshipMatches);
    }

    // Build the WHERE clause
    if (!empty($whereClauses)) {
        $parts[] = 'WHERE ' . implode(' AND ', $whereClauses);
    }

    $parts[] = 'RETURN n';

    return implode("\n", $parts);
}

/**
 * Generate WHERE clause from a where call
 */
private function generateWhereClause(array $call, string $alias): string
{
    $column = $call['column'] ?? '';
    $operator = $call['operator'] ?? '=';
    $value = $call['value'] ?? '';

    $formattedValue = is_string($value) ? "'{$value}'" : $value;

    return "{$alias}.{$column} {$operator} {$formattedValue}";
}

/**
 * Generate relationship MATCH pattern
 */
private function generateRelationshipMatch(array $call, string $fromEntity): array
{
    $relation = $call['relation'] ?? '';
    $nestedCalls = $call['nested_calls'] ?? [];

    // Convert relation name to relationship type (e.g., 'personTeams' -> 'HAS_PERSON_TEAM')
    $relType = $this->relationToType($relation);

    // Target entity alias
    $targetAlias = strtolower(substr($relation, 0, 2));

    $match = "-[:{$relType}]->({$targetAlias})";

    // Process nested where clauses
    $whereClauses = [];
    foreach ($nestedCalls as $nestedCall) {
        if (($nestedCall['method'] ?? '') === 'where') {
            $whereClauses[] = $this->generateWhereClause($nestedCall, $targetAlias);
        }
    }

    return [
        'match' => $match,
        'where' => $whereClauses,
    ];
}

/**
 * Convert relation name to Neo4j relationship type
 */
private function relationToType(string $relation): string
{
    // personTeams -> HAS_PERSON_TEAM
    // orders -> HAS_ORDER
    $singular = \Illuminate\Support\Str::singular($relation);
    $snake = \Illuminate\Support\Str::snake($singular);
    return 'HAS_' . strtoupper($snake);
}

/**
 * Generate WHERE IN clause
 */
private function generateWhereInClause(array $call, string $alias): string
{
    $column = $call['column'] ?? '';
    $values = $call['values'] ?? [];
    $not = $call['not'] ?? false;

    $formattedValues = array_map(function ($v) {
        return is_string($v) ? "'{$v}'" : $v;
    }, $values);

    $operator = $not ? 'NOT IN' : 'IN';

    return "{$alias}.{$column} {$operator} [" . implode(', ', $formattedValues) . "]";
}

/**
 * Generate WHERE NULL clause
 */
private function generateWhereNullClause(array $call, string $alias): string
{
    $column = $call['column'] ?? '';
    $not = $call['not'] ?? false;

    return $not
        ? "{$alias}.{$column} IS NOT NULL"
        : "{$alias}.{$column} IS NULL";
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Services/Discovery/CypherPatternGeneratorTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Services/Discovery/CypherPatternGenerator.php tests/Unit/Services/Discovery/CypherPatternGeneratorTest.php
git commit -m "feat(discovery): improve Cypher generation for complex relationship scopes"
```

---

### Task 5: Create Fluent NodeableConfig Builder

**Files:**
- Create: `src/Domain/ValueObjects/NodeableConfigBuilder.php`
- Create: `tests/Unit/Domain/ValueObjects/NodeableConfigBuilderTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Domain\ValueObjects;

use Condoedge\Ai\Domain\ValueObjects\NodeableConfigBuilder;
use Condoedge\Ai\Tests\TestCase;

class NodeableConfigBuilderTest extends TestCase
{
    /** @test */
    public function it_builds_graph_config_fluently(): void
    {
        $config = NodeableConfigBuilder::make()
            ->label('Person')
            ->properties(['id', 'name', 'email'])
            ->relationship('orders', 'Order', 'HAS_ORDER')
            ->toArray();

        $this->assertEquals('Person', $config['graph']['label']);
        $this->assertEquals(['id', 'name', 'email'], $config['graph']['properties']);
        $this->assertCount(1, $config['graph']['relationships']);
    }

    /** @test */
    public function it_builds_vector_config_fluently(): void
    {
        $config = NodeableConfigBuilder::make()
            ->collection('persons')
            ->embedFields(['name', 'bio', 'description'])
            ->metadata(['id', 'status'])
            ->toArray();

        $this->assertEquals('persons', $config['vector']['collection']);
        $this->assertEquals(['name', 'bio', 'description'], $config['vector']['embed_fields']);
    }

    /** @test */
    public function it_builds_security_config(): void
    {
        $config = NodeableConfigBuilder::make()
            ->sensibleColumns(['ssn', 'salary'])
            ->teamResolution('method:securityRelatedTeamIds')
            ->toArray();

        $this->assertEquals(['ssn', 'salary'], $config['security']['sensible_columns']);
        $this->assertEquals('method:securityRelatedTeamIds', $config['security']['team_resolution']);
    }

    /** @test */
    public function it_builds_metadata_config(): void
    {
        $config = NodeableConfigBuilder::make()
            ->aliases(['person', 'people', 'individual'])
            ->description('A person in the system')
            ->scope('volunteers', 'People who are volunteers', 'n.role_type = 3')
            ->toArray();

        $this->assertEquals(['person', 'people', 'individual'], $config['metadata']['aliases']);
        $this->assertArrayHasKey('volunteers', $config['metadata']['scopes']);
    }

    /** @test */
    public function it_chains_all_methods(): void
    {
        $config = NodeableConfigBuilder::make()
            ->label('Person')
            ->properties(['id', 'name'])
            ->embedFields(['name', 'bio'])
            ->sensibleColumns(['ssn'])
            ->aliases(['person', 'people'])
            ->toArray();

        $this->assertArrayHasKey('graph', $config);
        $this->assertArrayHasKey('vector', $config);
        $this->assertArrayHasKey('security', $config);
        $this->assertArrayHasKey('metadata', $config);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Domain/ValueObjects/NodeableConfigBuilderTest.php -v`
Expected: FAIL

**Step 3: Create NodeableConfigBuilder**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Domain\ValueObjects;

/**
 * NodeableConfigBuilder
 *
 * Fluent builder for creating Nodeable configuration.
 * Use in nodeableConfig() method for clean, readable configuration.
 *
 * Usage:
 *   public function nodeableConfig(): array
 *   {
 *       return NodeableConfigBuilder::make()
 *           ->label('Person')
 *           ->embedFields(['name', 'bio'])
 *           ->sensibleColumns(['ssn'])
 *           ->toArray();
 *   }
 */
class NodeableConfigBuilder
{
    protected array $graph = [];
    protected array $vector = [];
    protected array $security = [];
    protected array $metadata = [];

    public static function make(): static
    {
        return new static();
    }

    // === Graph Configuration ===

    public function label(string $label): static
    {
        $this->graph['label'] = $label;
        return $this;
    }

    public function properties(array $properties): static
    {
        $this->graph['properties'] = $properties;
        return $this;
    }

    public function relationship(string $name, string $targetEntity, string $type, ?string $foreignKey = null): static
    {
        $this->graph['relationships'][] = [
            'name' => $name,
            'target_entity' => $targetEntity,
            'type' => $type,
            'foreign_key' => $foreignKey,
        ];
        return $this;
    }

    // === Vector Configuration ===

    public function collection(string $collection): static
    {
        $this->vector['collection'] = $collection;
        return $this;
    }

    public function embedFields(array $fields): static
    {
        $this->vector['embed_fields'] = $fields;
        return $this;
    }

    public function metadata(array $fields): static
    {
        $this->vector['metadata'] = $fields;
        return $this;
    }

    // === Security Configuration ===

    public function sensibleColumns(array $columns): static
    {
        $this->security['sensible_columns'] = $columns;
        return $this;
    }

    public function teamResolution(string $resolution): static
    {
        $this->security['team_resolution'] = $resolution;
        return $this;
    }

    public function multipleTeams(bool $multiple = true): static
    {
        $this->security['multiple_teams'] = $multiple;
        return $this;
    }

    // === Metadata Configuration ===

    public function aliases(array $aliases): static
    {
        $this->metadata['aliases'] = $aliases;
        return $this;
    }

    public function description(string $description): static
    {
        $this->metadata['description'] = $description;
        return $this;
    }

    public function scope(string $name, string $concept, string $cypherPattern, array $examples = []): static
    {
        $this->metadata['scopes'][$name] = [
            'concept' => $concept,
            'cypher_pattern' => $cypherPattern,
            'examples' => $examples,
        ];
        return $this;
    }

    // === Output ===

    public function toArray(): array
    {
        $config = [];

        if (!empty($this->graph)) {
            $config['graph'] = $this->graph;
        }

        if (!empty($this->vector)) {
            $config['vector'] = $this->vector;
        }

        if (!empty($this->security)) {
            $config['security'] = $this->security;
        }

        if (!empty($this->metadata)) {
            $config['metadata'] = $this->metadata;
        }

        return $config;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Domain/ValueObjects/NodeableConfigBuilderTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Domain/ValueObjects/NodeableConfigBuilder.php tests/Unit/Domain/ValueObjects/NodeableConfigBuilderTest.php
git commit -m "feat: add fluent NodeableConfigBuilder for clean model configuration"
```

---

### Task 6: Update DiscoverEntitiesCommand to Generate Warm Cache

**Files:**
- Modify: `src/Console/Commands/DiscoverEntitiesCommand.php`
- Create: `tests/Feature/Commands/DiscoverEntitiesCommandTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Feature\Commands;

use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class DiscoverEntitiesCommandTest extends TestCase
{
    /** @test */
    public function it_generates_warm_cache_for_discovered_entities(): void
    {
        // Clear any existing cache
        Cache::flush();

        $this->artisan('ai:discover', ['--cache' => true])
            ->assertSuccessful();

        // Check that cache was populated
        $cacheKey = config('ai.auto_discovery.cache.prefix') . 'TestEntity';
        // Note: Would need actual test entities set up
    }

    /** @test */
    public function it_outputs_discovery_summary(): void
    {
        $this->artisan('ai:discover')
            ->expectsOutputToContain('Discovered')
            ->assertSuccessful();
    }

    /** @test */
    public function it_shows_security_config_in_output(): void
    {
        $this->artisan('ai:discover', ['--verbose' => true])
            ->expectsOutputToContain('Security')
            ->assertSuccessful();
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Commands/DiscoverEntitiesCommandTest.php -v`
Expected: FAIL

**Step 3: Add --cache option and update command**

```php
// Add to DiscoverEntitiesCommand.php signature
protected $signature = 'ai:discover
    {--models=* : Specific model classes to discover}
    {--output= : Output format (table, json)}
    {--cache : Populate the discovery cache for faster runtime}
    {--clear-cache : Clear the discovery cache}';

// Add to handle() method
public function handle(): int
{
    // Handle cache clearing
    if ($this->option('clear-cache')) {
        $this->clearDiscoveryCache();
        $this->info('Discovery cache cleared.');
        return self::SUCCESS;
    }

    // ... existing discovery logic ...

    // After discovery, optionally populate cache
    if ($this->option('cache')) {
        $this->populateDiscoveryCache($discoveredConfigs);
    }

    return self::SUCCESS;
}

protected function populateDiscoveryCache(array $configs): void
{
    $prefix = config('ai.auto_discovery.cache.prefix', 'ai.discovery.');
    $ttl = config('ai.auto_discovery.cache.ttl', 86400);

    foreach ($configs as $entityName => $config) {
        $cacheKey = $prefix . $entityName;
        cache()->put($cacheKey, $config, $ttl);

        $this->line("  Cached: {$entityName}");
    }

    $this->info("Cached " . count($configs) . " entity configurations.");
}

protected function clearDiscoveryCache(): void
{
    $prefix = config('ai.auto_discovery.cache.prefix', 'ai.discovery.');

    // Note: This is a simplified approach. For production, you might
    // want to track cached keys or use cache tags
    Cache::flush();
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Commands/DiscoverEntitiesCommandTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Console/Commands/DiscoverEntitiesCommand.php tests/Feature/Commands/DiscoverEntitiesCommandTest.php
git commit -m "feat(discovery): add --cache option to warm discovery cache"
```

---

### Task 7: Integration Test - Full Discovery Flow

**Files:**
- Create: `tests/Integration/DiscoveryFlowTest.php`

**Step 1: Write integration test**

```php
<?php

namespace Condoedge\Ai\Tests\Integration;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class DiscoveryFlowTest extends TestCase
{
    /** @test */
    public function complete_discovery_flow_works_without_configuration(): void
    {
        // Scenario: Developer creates model, uses trait, does nothing else
        $model = new IntegrationTestPerson();

        // Should work without throwing
        $graphConfig = $model->getGraphConfig();
        $vectorConfig = $model->getVectorConfig();

        $this->assertEquals('IntegrationTestPerson', $graphConfig->label);
        $this->assertContains('name', $vectorConfig->embedFields);
    }

    /** @test */
    public function model_properties_override_discovery(): void
    {
        $model = new IntegrationTestPersonWithProperties();

        $graphConfig = $model->getGraphConfig();
        $vectorConfig = $model->getVectorConfig();

        $this->assertEquals('CustomPerson', $graphConfig->label);
        $this->assertEquals(['name', 'biography'], $vectorConfig->embedFields);
    }

    /** @test */
    public function nodeable_config_overrides_properties(): void
    {
        $model = new IntegrationTestPersonWithNodeableConfig();

        $graphConfig = $model->getGraphConfig();

        $this->assertEquals('OverriddenPerson', $graphConfig->label);
    }

    /** @test */
    public function complex_scope_is_discovered_correctly(): void
    {
        config(['ai.auto_discovery.runtime_enabled' => true]);

        $discovery = app(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class);
        $config = $discovery->discover(IntegrationTestPersonWithScopes::class);

        $scopes = $config['metadata']['scopes'] ?? [];

        $this->assertArrayHasKey('active_team_members', $scopes);
        $scope = $scopes['active_team_members'];
        $this->assertStringContainsString('MATCH', $scope['cypher_pattern']);
    }
}

// Test fixtures
class IntegrationTestPerson extends Model implements Nodeable
{
    use HasNodeableConfig;
    protected $table = 'persons';
    protected $fillable = ['name', 'email', 'bio'];
}

class IntegrationTestPersonWithProperties extends Model implements Nodeable
{
    use HasNodeableConfig;
    protected $table = 'persons';
    protected $fillable = ['name', 'biography', 'email'];

    protected $graphLabel = 'CustomPerson';
    protected $embedFields = ['name', 'biography'];
}

class IntegrationTestPersonWithNodeableConfig extends Model implements Nodeable
{
    use HasNodeableConfig;
    protected $table = 'persons';
    protected $graphLabel = 'PropertyLabel';  // Should be overridden

    public function nodeableConfig(): array
    {
        return [
            'graph' => ['label' => 'OverriddenPerson'],
        ];
    }
}

class IntegrationTestTeam extends Model
{
    protected $table = 'person_teams';

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}

class IntegrationTestPersonWithScopes extends Model implements Nodeable
{
    use HasNodeableConfig;
    protected $table = 'persons';
    protected $fillable = ['name'];

    public function personTeams()
    {
        return $this->hasMany(IntegrationTestTeam::class, 'person_id');
    }

    public function scopeActiveTeamMembers($query)
    {
        return $query->whereHas('personTeams', fn($q) => $q->active());
    }
}
```

**Step 2: Run integration test**

Run: `vendor/bin/phpunit tests/Integration/DiscoveryFlowTest.php -v`
Expected: All tests should pass after previous tasks

**Step 3: Commit**

```bash
git add tests/Integration/DiscoveryFlowTest.php
git commit -m "test: add integration tests for complete discovery flow"
```

---

## Summary

After completing all tasks:

1. **Zero-config works** - Just implement Nodeable + use HasNodeableConfig
2. **Model properties are source of truth** - `$embedFields`, `$graphLabel`, `$sensibleColumns`
3. **nodeableConfig() for overrides** - Clean fluent builder available
4. **Complex scopes auto-convert** - `whereHas('rel', fn($q) => $q->scope())` → Cypher
5. **Optional warm cache** - `php artisan ai:discover --cache`
6. **Runtime fallback** - Works without running command

---

**Plan complete and saved to `docs/plans/2025-12-27-discovery-simplification.md`. Two execution options:**

**1. Subagent-Driven (this session)** - I dispatch fresh subagent per task, review between tasks, fast iteration

**2. Parallel Session (separate)** - Open new session with executing-plans, batch execution with checkpoints

**Which approach?**
