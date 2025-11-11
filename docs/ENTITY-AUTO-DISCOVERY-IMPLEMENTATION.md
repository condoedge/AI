# Entity Auto-Discovery: Implementation Roadmap

## Overview

This document provides a detailed implementation plan for the Entity Auto-Discovery system, including file structure, interfaces, and integration points.

---

## File Structure

```
src/
├── Services/
│   ├── EntityAutoDiscovery.php          # Core discovery service
│   ├── ConfigCache.php                   # Caching layer
│   └── Discovery/
│       ├── PropertyDiscoverer.php        # Discovers properties from model
│       ├── RelationshipDiscoverer.php    # Discovers relationships
│       ├── ScopeDiscoverer.php           # Discovers and parses scopes
│       ├── AliasGenerator.php            # Generates semantic aliases
│       └── EmbedFieldDetector.php        # Detects text fields for embedding
├── Domain/
│   ├── NodeableConfig.php                # Fluent config builder
│   └── ValueObjects/
│       └── DiscoveredConfig.php          # DTO for discovered config
├── Console/Commands/
│   ├── DiscoverEntityCommand.php         # ai:discover
│   ├── CacheDiscoveryCommand.php         # ai:discover:cache
│   ├── ClearDiscoveryCacheCommand.php    # ai:discover:clear
│   └── CompareConfigCommand.php          # ai:discover:compare
└── config/
    └── ai.php                            # Add discovery config section
```

---

## Implementation Phases

### Phase 1: Core Discovery (Week 1)

**Goal:** Basic auto-discovery working for simple entities

**Tasks:**
1. Create `EntityAutoDiscovery` service
2. Implement property discovery
3. Implement basic relationship discovery (belongsTo)
4. Create `DiscoveredConfig` DTO
5. Write unit tests

**Acceptance Criteria:**
- Can discover properties from `$fillable`, `$casts`
- Can discover `belongsTo` relationships
- Returns `DiscoveredConfig` object
- 100% test coverage

**Files:**
- `src/Services/EntityAutoDiscovery.php`
- `src/Domain/ValueObjects/DiscoveredConfig.php`
- `tests/Unit/Services/EntityAutoDiscoveryTest.php`

---

### Phase 2: Fluent Builder (Week 1)

**Goal:** Create fluent API for overriding config

**Tasks:**
1. Create `NodeableConfig` builder class
2. Implement fluent methods for graph config
3. Implement fluent methods for vector config
4. Implement fluent methods for metadata
5. Write unit tests

**Acceptance Criteria:**
- Can start with `discover()` or `blank()`
- Supports all override operations
- Can build final config arrays
- 100% test coverage

**Files:**
- `src/Domain/NodeableConfig.php`
- `tests/Unit/Domain/NodeableConfigTest.php`

---

### Phase 3: Trait Integration (Week 1)

**Goal:** Integrate auto-discovery into `HasNodeableConfig`

**Tasks:**
1. Update `HasNodeableConfig` trait
2. Add fallback chain (method → config → discovery)
3. Maintain backward compatibility
4. Write integration tests

**Acceptance Criteria:**
- Existing config files still work
- Auto-discovery works for new models
- `nodeableConfig()` method overrides work
- All existing tests pass

**Files:**
- `src/Domain/Traits/HasNodeableConfig.php`
- `tests/Integration/HasNodeableConfigTest.php`

---

### Phase 4: Advanced Discovery (Week 2)

**Goal:** Add scope, alias, and embed field discovery

**Tasks:**
1. Implement `ScopeDiscoverer` (parse simple scopes)
2. Implement `AliasGenerator` (generate from table name)
3. Implement `EmbedFieldDetector` (detect text fields)
4. Write unit tests for each

**Acceptance Criteria:**
- Can parse simple `where()` scopes
- Generates semantic aliases from table name
- Detects text-like fields for embedding
- 100% test coverage

**Files:**
- `src/Services/Discovery/ScopeDiscoverer.php`
- `src/Services/Discovery/AliasGenerator.php`
- `src/Services/Discovery/EmbedFieldDetector.php`
- `tests/Unit/Services/Discovery/*Test.php`

---

### Phase 5: Caching Layer (Week 2)

**Goal:** Add caching to avoid expensive introspection

**Tasks:**
1. Create `ConfigCache` service
2. Integrate with `EntityAutoDiscovery`
3. Add cache configuration
4. Write cache tests

**Acceptance Criteria:**
- Discovery results cached per entity
- Configurable TTL
- Can clear cache per entity or all
- Cache disabled in testing environment

**Files:**
- `src/Services/ConfigCache.php`
- `config/ai.php` (add discovery section)
- `tests/Unit/Services/ConfigCacheTest.php`

---

### Phase 6: CLI Commands (Week 2)

**Goal:** Provide CLI tools for discovery and migration

**Tasks:**
1. Create `DiscoverEntityCommand` (preview config)
2. Create `CacheDiscoveryCommand` (warm cache)
3. Create `ClearDiscoveryCacheCommand` (clear cache)
4. Create `CompareConfigCommand` (compare with config file)
5. Write command tests

**Acceptance Criteria:**
- `ai:discover` shows discovered config
- `ai:discover:cache` warms cache
- `ai:discover:clear` clears cache
- `ai:discover:compare` highlights differences
- All commands have help text

**Files:**
- `src/Console/Commands/DiscoverEntityCommand.php`
- `src/Console/Commands/CacheDiscoveryCommand.php`
- `src/Console/Commands/ClearDiscoveryCacheCommand.php`
- `src/Console/Commands/CompareConfigCommand.php`
- `tests/Unit/Console/Commands/*Test.php`

---

### Phase 7: Documentation & Examples (Week 3)

**Goal:** Complete documentation for users

**Tasks:**
1. Update README with auto-discovery examples
2. Create migration guide
3. Add inline documentation
4. Update getting started guide
5. Create video walkthrough (optional)

**Acceptance Criteria:**
- README shows auto-discovery as primary method
- Migration guide covers all scenarios
- All classes have PHPDoc
- Getting started updated

**Files:**
- `README.md`
- `docs/ENTITY-AUTO-DISCOVERY-MIGRATION.md`
- All source files (PHPDoc)

---

### Phase 8: Testing & Refinement (Week 3)

**Goal:** Ensure production readiness

**Tasks:**
1. Integration tests with real fixtures
2. Performance testing (introspection overhead)
3. Edge case testing
4. Documentation review
5. Code review

**Acceptance Criteria:**
- All tests passing
- Performance acceptable (<100ms per discovery)
- Edge cases handled gracefully
- Documentation complete
- Code reviewed

---

## Technical Specifications

### 1. EntityAutoDiscovery Service

**Responsibilities:**
- Introspect Eloquent models
- Generate `DiscoveredConfig` objects
- Cache results

**Key Methods:**

```php
class EntityAutoDiscovery
{
    /**
     * Discover full configuration for entity
     */
    public function discover(Model $entity): DiscoveredConfig;

    /**
     * Discover graph configuration only
     */
    public function discoverGraphConfig(Model $entity): GraphConfig;

    /**
     * Discover vector configuration only
     */
    public function discoverVectorConfig(Model $entity): ?VectorConfig;

    /**
     * Discover metadata only
     */
    public function discoverMetadata(Model $entity): array;

    /**
     * Clear cached discovery for entity
     */
    public function clearCache(Model $entity): void;
}
```

**Dependencies:**
- `ConfigCache`
- `PropertyDiscoverer`
- `RelationshipDiscoverer`
- `ScopeDiscoverer`
- `AliasGenerator`
- `EmbedFieldDetector`

---

### 2. PropertyDiscoverer

**Responsibilities:**
- Extract properties from model attributes
- Handle `$fillable`, `$casts`, `$dates`
- Exclude hidden and sensitive fields

**Key Methods:**

```php
class PropertyDiscoverer
{
    /**
     * Discover properties from model
     */
    public function discover(Model $entity): array;

    /**
     * Check if property should be included
     */
    private function shouldInclude(string $property, Model $entity): bool;

    /**
     * Get properties from $fillable
     */
    private function fromFillable(Model $entity): array;

    /**
     * Get properties from $casts
     */
    private function fromCasts(Model $entity): array;

    /**
     * Get properties from $dates
     */
    private function fromDates(Model $entity): array;
}
```

---

### 3. RelationshipDiscoverer

**Responsibilities:**
- Introspect relationship methods
- Build `RelationshipConfig` objects
- Filter by relationship type

**Key Methods:**

```php
class RelationshipDiscoverer
{
    /**
     * Discover relationships from model
     */
    public function discover(Model $entity): array;

    /**
     * Get all relationship methods
     */
    private function getRelationshipMethods(Model $entity): array;

    /**
     * Build relationship config from method
     */
    private function buildConfig(
        Model $entity,
        string $methodName,
        Relation $relation
    ): ?RelationshipConfig;

    /**
     * Check if relationship should be auto-discovered
     */
    private function shouldDiscover(Relation $relation): bool;
}
```

---

### 4. ScopeDiscoverer

**Responsibilities:**
- Find scope methods
- Parse simple where clauses
- Generate Cypher patterns

**Key Methods:**

```php
class ScopeDiscoverer
{
    /**
     * Discover scopes from model
     */
    public function discover(Model $entity): array;

    /**
     * Get all scope methods
     */
    private function getScopeMethods(Model $entity): array;

    /**
     * Parse scope method to extract filter
     */
    private function parseScope(ReflectionMethod $method): ?array;

    /**
     * Parse simple where clause
     */
    private function parseWhere(string $sourceCode): ?array;

    /**
     * Generate Cypher pattern from filter
     */
    private function buildCypherPattern(array $filter): string;

    /**
     * Generate example queries
     */
    private function generateExamples(Model $entity, string $scopeName): array;
}
```

---

### 5. AliasGenerator

**Responsibilities:**
- Generate aliases from table name
- Add domain-specific aliases
- Handle pluralization

**Key Methods:**

```php
class AliasGenerator
{
    /**
     * Generate aliases for entity
     */
    public function generate(Model $entity): array;

    /**
     * Get domain aliases for table
     */
    private function getDomainAliases(string $table): array;

    /**
     * Singularize word
     */
    private function singularize(string $word): string;

    /**
     * Pluralize word
     */
    private function pluralize(string $word): string;
}
```

---

### 6. EmbedFieldDetector

**Responsibilities:**
- Detect text-like fields
- Exclude IDs, dates, numeric fields
- Use heuristics and casts

**Key Methods:**

```php
class EmbedFieldDetector
{
    /**
     * Detect fields to embed
     */
    public function detect(Model $entity, array $properties): array;

    /**
     * Check if field is text-like
     */
    private function isTextField(string $field, Model $entity): bool;

    /**
     * Check if field matches text patterns
     */
    private function matchesTextPattern(string $field): bool;

    /**
     * Check if field is cast as text
     */
    private function isCastAsText(string $field, Model $entity): bool;

    /**
     * Check if field should be excluded
     */
    private function shouldExclude(string $field): bool;
}
```

---

### 7. NodeableConfig (Fluent Builder)

**Key Methods:**

```php
class NodeableConfig
{
    // Factory methods
    public static function discover(Model $entity): self;
    public static function blank(): self;

    // Graph config
    public function label(string $label): self;
    public function properties(array $properties): self;
    public function addProperty(string $property): self;
    public function removeProperty(string $property): self;
    public function addRelationship(...): self;

    // Vector config
    public function collection(string $collection): self;
    public function embedFields(array $fields): self;
    public function addEmbedField(string $field): self;
    public function metadata(array $metadata): self;

    // Metadata
    public function aliases(array $aliases): self;
    public function addAlias(string $alias): self;
    public function description(string $description): self;
    public function addScope(string $name, array $config): self;

    // Disable features
    public function disableVectorStore(): self;
    public function disableAutoDiscovery(): self;

    // Build final config
    public function build(): array;
    public function toGraphConfig(): GraphConfig;
    public function toVectorConfig(): ?VectorConfig;
}
```

---

### 8. ConfigCache Service

**Key Methods:**

```php
class ConfigCache
{
    /**
     * Remember discovery result
     */
    public function remember(string $key, callable $callback): mixed;

    /**
     * Forget cached discovery
     */
    public function forget(string $key): void;

    /**
     * Flush all discovery cache
     */
    public function flush(): void;

    /**
     * Forget entity discovery
     */
    public function forgetEntity(string $entityClass): void;

    /**
     * Get cache key for entity
     */
    private function getCacheKey(string $entityClass): string;
}
```

---

## Configuration Schema

### config/ai.php

```php
return [
    // ... existing config ...

    /*
    |--------------------------------------------------------------------------
    | Entity Auto-Discovery
    |--------------------------------------------------------------------------
    */
    'discovery' => [
        // Enable auto-discovery
        'enabled' => env('AI_DISCOVERY_ENABLED', true),

        // Cache settings
        'cache' => [
            'enabled' => env('AI_DISCOVERY_CACHE', true),
            'ttl' => env('AI_DISCOVERY_CACHE_TTL', 3600),
            'driver' => env('AI_DISCOVERY_CACHE_DRIVER', 'file'),
        ],

        // Property discovery
        'properties' => [
            'include_fillable' => true,
            'include_casts' => true,
            'include_dates' => true,
            'include_timestamps' => true,
            'exclude_hidden' => true,
            'exclude_patterns' => ['password', 'token', 'secret'],
        ],

        // Relationship discovery
        'relationships' => [
            'types' => ['belongsTo', 'morphTo'],
            'exclude_methods' => ['user', 'creator', 'updater'],
        ],

        // Scope discovery
        'scopes' => [
            'enabled' => true,
            'exclude_patterns' => [],
        ],

        // Embed field detection
        'embed_fields' => [
            'patterns' => [
                'name', 'title', 'description', 'notes', 'content',
                'body', 'bio', 'summary', 'details', 'comment',
                'message', 'text',
            ],
            'casts' => ['string', 'text'],
            'exclude_patterns' => ['_id', '_at', '_date'],
        ],

        // Alias generation
        'aliases' => [
            'enabled' => true,
            'domain_aliases' => [
                'customers' => ['client', 'clients'],
                'people' => ['user', 'users', 'individual', 'member'],
                'orders' => ['purchase', 'sale'],
                'products' => ['item', 'items'],
            ],
        ],
    ],
];
```

---

## Database Schema Changes

**None required.** Auto-discovery works with existing database schema.

---

## Service Provider Registration

### AiServiceProvider

```php
public function register(): void
{
    // ... existing registrations ...

    // Register discovery services
    $this->app->singleton(ConfigCache::class);
    $this->app->singleton(EntityAutoDiscovery::class);

    // Register discovery sub-services
    $this->app->singleton(PropertyDiscoverer::class);
    $this->app->singleton(RelationshipDiscoverer::class);
    $this->app->singleton(ScopeDiscoverer::class);
    $this->app->singleton(AliasGenerator::class);
    $this->app->singleton(EmbedFieldDetector::class);
}

public function boot(): void
{
    // ... existing boot ...

    // Register commands
    if ($this->app->runningInConsole()) {
        $this->commands([
            DiscoverEntityCommand::class,
            CacheDiscoveryCommand::class,
            ClearDiscoveryCacheCommand::class,
            CompareConfigCommand::class,
        ]);
    }
}
```

---

## Testing Strategy

### Unit Tests (75% coverage target)

**EntityAutoDiscoveryTest**
- Test property discovery from $fillable
- Test property discovery from $casts
- Test property discovery from $dates
- Test relationship discovery (belongsTo)
- Test relationship discovery (morphTo)
- Test exclusion of hasMany/hasOne
- Test scope discovery
- Test alias generation
- Test embed field detection
- Test caching behavior

**NodeableConfigTest**
- Test discover() factory
- Test blank() factory
- Test fluent property methods
- Test fluent relationship methods
- Test fluent vector methods
- Test fluent metadata methods
- Test override merging
- Test add/remove operations
- Test conversion to GraphConfig
- Test conversion to VectorConfig

**PropertyDiscovererTest**
- Test fillable extraction
- Test casts extraction
- Test dates extraction
- Test timestamp inclusion
- Test hidden exclusion
- Test password exclusion

**RelationshipDiscovererTest**
- Test belongsTo discovery
- Test morphTo discovery
- Test hasMany exclusion
- Test method filtering
- Test foreign key extraction
- Test target label generation

**ScopeDiscovererTest**
- Test scope method detection
- Test simple where parsing
- Test complex scope skipping
- Test Cypher generation
- Test example generation

**AliasGeneratorTest**
- Test table name singularization
- Test table name pluralization
- Test domain alias addition
- Test unique alias list

**EmbedFieldDetectorTest**
- Test text pattern matching
- Test cast detection
- Test exclusion patterns
- Test ID exclusion
- Test date exclusion

**ConfigCacheTest**
- Test cache remember
- Test cache forget
- Test cache flush
- Test entity-specific clearing
- Test TTL expiration

### Integration Tests (20% coverage target)

**HasNodeableConfigTest**
- Test auto-discovery fallback
- Test config file fallback
- Test nodeableConfig() override
- Test fallback chain priority
- Test backward compatibility

**FullDiscoveryTest**
- Test Customer discovery (simple)
- Test Order discovery (relationships)
- Test Person discovery (scopes)
- Test File discovery (metadata)

### Feature Tests (5% coverage target)

**EndToEndTest**
- Test full ingestion pipeline with auto-discovery
- Test Neo4j storage with auto-discovered config
- Test Qdrant storage with auto-discovered config
- Test relationship creation with auto-discovered config

---

## Performance Benchmarks

### Target Metrics

- **Discovery Time:** <100ms per entity (first time)
- **Cached Retrieval:** <5ms per entity
- **Memory Usage:** <10MB for discovery service
- **Cache Size:** <1KB per entity config

### Optimization Strategies

1. **Cache Aggressively**
   - Cache discovery results (1 hour TTL)
   - Cache reflection results
   - Use file cache in production

2. **Lazy Loading**
   - Only discover when config is accessed
   - Don't discover on model boot

3. **Batch Discovery**
   - `ai:discover:cache` command for warming
   - Run during deployment

4. **Smart Introspection**
   - Only introspect public methods
   - Skip inherited methods
   - Filter by method signature

---

## Migration Path

### For New Projects

**Step 1:** Install package
```bash
composer require condoedge/ai
```

**Step 2:** Add trait to models
```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;
}
```

**Step 3:** Done! Auto-discovery handles the rest.

### For Existing Projects

**Step 1:** Update package
```bash
composer update condoedge/ai
```

**Step 2:** Review auto-discovered config
```bash
php artisan ai:discover Customer
php artisan ai:discover:compare Customer
```

**Step 3:** Migrate entity by entity
```php
// Remove from config/entities.php
// Add nodeableConfig() only if needed
```

**Step 4:** Test thoroughly
```bash
php artisan test
```

**Step 5:** Clear cache and redeploy
```bash
php artisan ai:discover:cache
```

---

## Risk Assessment

### High Risk

**Risk:** Breaking changes for existing projects
**Mitigation:**
- Maintain backward compatibility
- Config file takes precedence over discovery
- Extensive testing with existing fixtures

### Medium Risk

**Risk:** Performance impact from introspection
**Mitigation:**
- Aggressive caching
- Lazy loading
- Performance benchmarks
- Cache warming commands

### Low Risk

**Risk:** Incorrect auto-discovery
**Mitigation:**
- Comprehensive unit tests
- Compare commands for validation
- Easy override with nodeableConfig()

---

## Success Criteria

### Must Have

- [ ] Auto-discovery works for simple entities (zero config)
- [ ] Backward compatibility maintained
- [ ] All existing tests pass
- [ ] Performance acceptable (<100ms discovery)
- [ ] Documentation complete

### Should Have

- [ ] Scope parsing works for simple cases
- [ ] Relationship discovery for belongsTo
- [ ] Alias generation from table names
- [ ] CLI commands for preview/compare
- [ ] Migration guide complete

### Nice to Have

- [ ] Complex scope parsing
- [ ] IDE integration
- [ ] Web UI for config preview
- [ ] Automatic scope example generation

---

## Timeline

**Week 1:**
- Core discovery service
- Fluent builder
- Trait integration

**Week 2:**
- Advanced discovery (scopes, aliases)
- Caching layer
- CLI commands

**Week 3:**
- Documentation
- Testing & refinement
- Code review

**Total:** 3 weeks for complete implementation

---

## Maintenance Plan

### Regular Tasks

**Weekly:**
- Review discovery accuracy metrics
- Monitor cache hit rates
- Check for user issues

**Monthly:**
- Update domain aliases based on usage
- Review and improve scope parsing
- Performance optimization

**Quarterly:**
- Major feature additions
- Breaking changes (if needed)
- Documentation updates

### Monitoring

**Metrics to Track:**
- Discovery cache hit rate
- Average discovery time
- Config override frequency
- CLI command usage

**Alerts:**
- Discovery time >200ms
- Cache hit rate <80%
- High error rates

---

## Future Roadmap

### Phase 2 (3-6 months)

- [ ] Advanced scope parsing (whereHas, complex queries)
- [ ] Polymorphic relationship handling
- [ ] belongsToMany with pivot config
- [ ] ML-based field type detection

### Phase 3 (6-12 months)

- [ ] Visual config builder (web UI)
- [ ] IDE plugin for PHPStorm
- [ ] Automatic schema migration
- [ ] Performance profiling tools

---

## Conclusion

This implementation roadmap provides a clear path to delivering Entity Auto-Discovery in 3 weeks, with:

- **Week 1:** Core functionality
- **Week 2:** Advanced features
- **Week 3:** Polish and documentation

The phased approach ensures incremental delivery, comprehensive testing, and production readiness while maintaining backward compatibility.
