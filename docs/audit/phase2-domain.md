# Phase 2: File-by-File Review - Domain Layer

**Audit Date:** 2025-12-30
**Directory:** `src/Domain/`
**Total Files:** 7 (2 contracts, 1 trait, 4 value objects)

## Review Checklist

| File | Reviewed | Implementations/Users | Usage Count | Status |
|------|----------|----------------------|-------------|--------|
| Nodeable.php | Yes | 0 direct (8 via trait) | 89+ | Referenced |
| Searchable.php | Yes | 0 direct | 2 | Never referenced |
| HasNodeableConfig.php | Yes | 0 in package | 2 mentions | Partially referenced |
| GraphConfig.php | Yes | N/A (Value Object) | 6 files | Referenced |
| NodeableConfig.php | Yes | N/A (Value Object) | 7 files | Referenced |
| RelationshipConfig.php | Yes | N/A (Value Object) | 3 files | Referenced |
| VectorConfig.php | Yes | N/A (Value Object) | 7 files | Referenced |

---

## Detailed Reviews

### Contracts

---

### Nodeable.php
**Path:** `src/Domain/Contracts/Nodeable.php`

**1. What it does:**
Core domain interface defining the contract for any entity that should be stored in Neo4j (graph database) and/or Qdrant (vector database). This is the fundamental abstraction for entities in the AI/knowledge graph system.

**2. Inputs (methods and parameters):**
- `getGraphConfig(): GraphConfig` - Returns Neo4j configuration for this entity
- `getVectorConfig(): VectorConfig` - Returns Qdrant configuration for this entity (throws LogicException if not vectorizable)
- `getId(): string|int` - Returns the unique identifier for the entity
- `toArray(): array` - Converts entity to associative array for storage

**3. Outputs (return types):**
- `GraphConfig` - Configuration value object for graph storage
- `VectorConfig` - Configuration value object for vector storage
- `string|int` - Entity identifier
- `array` - Entity data as key-value pairs

**4. Dependencies:**
```php
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
```

**5. Reference status:**
- **Direct Implementations:** None found in package (intended to be implemented by client models)
- **Indirect via Trait:** `HasNodeableConfig` provides default implementation
- **Usage locations:** 21 files (89+ occurrences)
  - DataIngestionService.php (core consumer - type hints on all methods)
  - DataIngestionServiceInterface.php (interface definition)
  - AiManager.php (facade methods)
  - Jobs: IngestEntityJob, SyncEntityJob, RemoveEntityJob
  - Commands: IngestEntitiesCommand, SyncRelationshipsCommand, DiscoverEntitiesCommand
  - Services: EntityAutoDiscovery, InheritanceResolver, AliasGenerator
  - Observers: RelatedModelSyncObserver
  - Facades: AI.php (method annotations)
- **Status:** Referenced

**6. Notes/Anomalies:**
- Core domain interface - foundational to the entire system
- No implementations within the package itself (by design - client models implement it)
- Well-documented with usage examples showing two approaches:
  1. Manual method implementation for custom logic
  2. Using `HasNodeableConfig` trait for config-file-based configuration
- The `getVectorConfig()` method can throw `LogicException` for entities that don't need vector search

---

### Searchable.php
**Path:** `src/Domain/Contracts/Searchable.php`

**1. What it does:**
A marker interface for entities that support semantic vector search. Provides documentation that an entity is vectorizable. This is explicitly stated as optional - mainly used for type hinting and documentation.

**2. Inputs (methods and parameters):**
- `getVectorConfig(): VectorConfig` - Get vector configuration for semantic search

**3. Outputs (return types):**
- `VectorConfig` - Configuration for vector storage

**4. Dependencies:**
```php
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
```

**5. Reference status:**
- **Implementations found:** NONE (grep: `implements Searchable` = 0 results)
- **Usage locations:** Only self-definition and docblock mention in Nodeable.php
- **Status:** Never referenced

**6. Notes/Anomalies:**
- **ANOMALY:** Interface is defined but NEVER implemented or used anywhere
- The interface is explicitly documented as "optional" and "mainly used for type hinting"
- The `Nodeable` interface already includes `getVectorConfig()`, making this interface potentially redundant
- The docblock suggests this was intended for entities that need vector search but don't need full graph storage, but this use case doesn't appear to be exercised
- **RECOMMENDATION:** Consider removing this unused interface or documenting a clear use case

---

### Traits

---

### HasNodeableConfig.php
**Path:** `src/Domain/Traits/HasNodeableConfig.php`

**1. What it does:**
A comprehensive trait that provides default implementation of the `Nodeable` interface. Enables automatic loading of entity configuration from config files and provides automatic synchronization hooks for Neo4j and Qdrant on model create, update, and delete events.

**2. Inputs (parameters and configuration):**

**Model Properties (optional overrides):**
- `$aiAutoSync` - bool - Disable auto-sync for this model
- `$aiSyncQueue` - bool - Queue sync operations instead of synchronous
- `$aiEagerLoadRelationships` - bool - Enable/disable relationship eager loading
- `$aiSyncRelationships` - array - Specific relationships to load before sync
- `$embedFields` - array - Fields to embed in vectors
- `$graphLabel` - string - Custom Neo4j node label
- `$graphRelationships` - array - Relationship configurations
- `$sensibleColumns` - array - Sensitive field names to exclude
- `$nodeableAliases` - array - Entity aliases for semantic matching

**Configuration Sources (priority order):**
1. `nodeableConfig()` method on model (highest priority)
2. `config/entities.php` (generated by `php artisan ai:discover`)
3. Model properties merged on top

**3. Outputs (return types):**
- `getGraphConfig(): GraphConfig` - Returns configuration for Neo4j
- `getVectorConfig(): VectorConfig` - Returns configuration for Qdrant (throws LogicException if not configured)
- `getId(): string|int` - Returns entity identifier
- `toArray(): array` - Returns entity data as array

**4. Dependencies:**
```php
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Facades\AI;
use Illuminate\Support\Facades\Log;
```

**Internal Job Classes Referenced:**
- `\Condoedge\Ai\Jobs\IngestEntityJob::class`
- `\Condoedge\Ai\Jobs\SyncEntityJob::class`
- `\Condoedge\Ai\Jobs\RemoveEntityJob::class`

**Internal Service Referenced:**
- `\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class`

**5. Reference status:**
- **Usage in package:** Only self-reference and documentation example
- **External Usage:** Intended to be used by client models (e.g., `class Customer implements Nodeable { use HasNodeableConfig; }`)
- **Mentioned in:** SyncRelationshipsCommand.php (as requirement for models)
- **Status:** Partially referenced (documentation/examples only within package)

**6. Notes/Anomalies:**
- **Very comprehensive trait** - 563 lines with extensive functionality
- **Boot method:** Registers Eloquent model event listeners (created, updated, deleted) for auto-sync
- **Configuration Resolution:** Multi-source with priority chain
- **Queue Support:** Can dispatch jobs for async processing
- **Error Handling:** Configurable fail silently or throw behavior
- **Deep Merge:** Includes protection against circular references (max depth 10)
- **Relationship Inference:** Can automatically detect relationships from GraphConfig
- **ANOMALY:** References `NodeableConfig` class but only checks `instanceof` - the import is implicit via full path
- **ANOMALY:** The `getConfigPath()` method has a hardcoded fallback path that may not exist
- **NOTE:** The trait is designed for client models - no models within this package use it

---

### Value Objects

---

### GraphConfig.php
**Path:** `src/Domain/ValueObjects/GraphConfig.php`

**1. What it does:**
Immutable value object that defines how an entity should be stored in Neo4j. Encapsulates the node label, properties to store, and relationships to other nodes.

**2. Inputs (constructor parameters):**
```php
public function __construct(
    public readonly string $label,           // Node label (e.g., "Customer", "Order")
    public readonly array $properties,       // Property names to store
    public readonly array $relationships = [] // RelationshipConfig array
)
```

**Static Factory:**
- `fromArray(array $config): self` - Creates instance from array configuration
  - Expected keys: `label`, `properties`, `relationships`

**3. Outputs (return types):**
- `getRelationships(): RelationshipConfig[]` - Returns all relationship configurations
- `hasRelationship(string $foreignKey): bool` - Checks if relationship exists for foreign key
- `getRelationship(string $foreignKey): ?RelationshipConfig` - Gets specific relationship by foreign key

**4. Dependencies:**
```php
// Uses RelationshipConfig internally
use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig; // (implicit)
```

**5. Reference status:**
- **Usage locations:** 6 files
  - Nodeable.php (return type)
  - HasNodeableConfig.php (creates and returns)
  - DataIngestionService.php (consumes methods)
  - DataIngestionServiceInterface.php (type hint)
  - NodeableConfig.php (creates from builder)
- **Status:** Referenced

**6. Notes/Anomalies:**
- Well-designed immutable value object with readonly properties (PHP 8.1+)
- Validates inputs in constructor:
  - Label cannot be empty
  - Properties cannot be empty
  - All relationships must be RelationshipConfig instances
- Factory method properly converts array relationship configs to RelationshipConfig objects

---

### NodeableConfig.php
**Path:** `src/Domain/ValueObjects/NodeableConfig.php`

**1. What it does:**
Fluent builder for entity configuration. Provides a developer-friendly API to construct entity configurations that produce the same array structure as manual config arrays. Can be used interchangeably with array-based configuration.

**2. Inputs (builder methods):**

**Graph Configuration:**
- `label(string $label): self` - Set Neo4j node label
- `properties(string|array ...$properties): self` - Set properties to store
- `relationship(string $type, string $targetLabel, ?string $foreignKey = null, array $relationshipProperties = []): self` - Add a relationship

**Vector Configuration:**
- `collection(string $collection): self` - Set Qdrant collection name
- `embedFields(string|array ...$fields): self` - Set fields to embed
- `vectorMetadata(string|array ...$fields): self` - Set metadata fields

**Metadata Configuration:**
- `aliases(string|array ...$aliases): self` - Set aliases for semantic matching
- `addAlias(string|array ...$aliases): self` - Add aliases without replacing
- `description(string $description): self` - Set entity description
- `scope(string $name, array|Closure $config): self` - Add semantic scope
- `commonProperties(array $properties): self` - Add property descriptions

**Auto-Sync Configuration:**
- `autoSync(bool|array $config): self` - Enable/configure auto-sync

**Factory Methods:**
- `for(string $modelClass): self` - Create builder for model class
- `fromArray(array $config): self` - Create from existing array
- `discover(Model $model): self` - Auto-discover from model instance

**3. Outputs (return types):**
- `toArray(): array` - Convert to array configuration
- `getModelClass(): ?string` - Get target model class
- `toGraphConfig(): GraphConfig` - Create GraphConfig from builder
- `toVectorConfig(): VectorConfig` - Create VectorConfig from builder
- `hasGraphConfig(): bool` - Check if graph config is set
- `hasVectorConfig(): bool` - Check if vector config is set
- `hasMetadata(): bool` - Check if metadata is set

**4. Dependencies:**
```php
use Closure;
use Illuminate\Database\Eloquent\Model;
```

**Internal Service Referenced:**
- `\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class` (for discover method)

**5. Reference status:**
- **Usage locations:** 7 files
  - HasNodeableConfig.php (instanceof check for nodeableConfig() return)
  - Jobs: IngestEntityJob, SyncEntityJob, RemoveEntityJob (not directly but via Nodeable)
  - Nodeable.php (mentioned in docblock)
- **Status:** Referenced

**6. Notes/Anomalies:**
- Well-designed fluent builder pattern
- Private constructor enforces use of factory methods
- Supports variadic arguments with array flattening
- The `discover()` method has graceful degradation if EntityAutoDiscovery service is not bound
- **NOTE:** Primarily intended for use in model's `nodeableConfig()` method

---

### RelationshipConfig.php
**Path:** `src/Domain/ValueObjects/RelationshipConfig.php`

**1. What it does:**
Immutable value object that defines how entities are connected in the Neo4j graph. Encapsulates relationship type, target node label, foreign key field, and optional relationship properties.

**2. Inputs (constructor parameters):**
```php
public function __construct(
    public readonly string $type,        // Relationship type (e.g., "MEMBER_OF", "PURCHASED")
    public readonly string $targetLabel, // Target node label (e.g., "Team", "Order")
    public readonly string $foreignKey,  // Foreign key field (e.g., "team_id")
    public readonly array $properties = [] // Additional properties on the relationship
)
```

**Static Factory:**
- `fromArray(array $config): ?self` - Creates instance from array, returns null if no foreign key
  - Supports both snake_case and camelCase keys: `foreign_key`/`foreignKey`, `target_label`/`targetLabel`

**3. Outputs (return types):**
- `hasProperties(): bool` - Check if relationship has additional properties

**4. Dependencies:**
```php
// No external dependencies
```

**5. Reference status:**
- **Usage locations:** 3 files
  - GraphConfig.php (type validation and factory)
  - DataIngestionService.php (consumes in createRelationships method)
  - Self-definition
- **Status:** Referenced

**6. Notes/Anomalies:**
- Well-designed immutable value object
- Validates inputs in constructor:
  - Type cannot be empty
  - Target label cannot be empty
  - Foreign key cannot be empty
- **ANOMALY:** `fromArray()` returns `null` instead of throwing exception when foreign key is missing
  - This is intentional to allow filtering invalid relationships silently
  - Used by GraphConfig::fromArray() which filters with array_filter()
- Supports both naming conventions (snake_case and camelCase)

---

### VectorConfig.php
**Path:** `src/Domain/ValueObjects/VectorConfig.php`

**1. What it does:**
Immutable value object that defines how an entity's text data should be embedded and stored in Qdrant. Encapsulates the collection name, fields to embed, and metadata to store alongside vectors.

**2. Inputs (constructor parameters):**
```php
public function __construct(
    public readonly string $collection,   // Qdrant collection name (e.g., "customers")
    public readonly array $embedFields,   // Fields to combine and embed
    public readonly array $metadata = []  // Additional fields as searchable metadata
)
```

**Static Factory:**
- `fromArray(array $config): self` - Creates instance from array configuration
  - Supports both snake_case and camelCase: `embed_fields`/`embedFields`

**3. Outputs (return types):**
- `getSeparator(): string` - Get separator for combining embed fields (returns ' ' - space)

**4. Dependencies:**
```php
// No external dependencies
```

**5. Reference status:**
- **Usage locations:** 7 files
  - Nodeable.php (return type)
  - Searchable.php (return type)
  - HasNodeableConfig.php (creates and returns)
  - DataIngestionService.php (consumes)
  - DataIngestionServiceInterface.php (type hint)
  - NodeableConfig.php (creates from builder)
- **Status:** Referenced

**6. Notes/Anomalies:**
- Well-designed immutable value object
- Validates inputs in constructor:
  - Collection name cannot be empty
  - Embed fields cannot be empty
- The `getSeparator()` method returns hardcoded space - could be configurable
- Supports both naming conventions for flexibility

---

## Summary Statistics

| Metric | Value |
|--------|-------|
| Total Files | 7 |
| Contracts | 2 |
| Traits | 1 |
| Value Objects | 4 |
| Fully Referenced | 5 |
| Partially Referenced | 1 (HasNodeableConfig - example only) |
| Never Referenced | 1 (Searchable - completely unused) |

## Key Findings

### Well-Designed Components

1. **Nodeable Interface** - Core abstraction with clear contract, well-documented usage patterns
2. **Value Objects (GraphConfig, VectorConfig, RelationshipConfig)** - Immutable, validated, with factory methods
3. **NodeableConfig Builder** - Excellent fluent API for configuration construction
4. **HasNodeableConfig Trait** - Comprehensive implementation with queue support, error handling, and flexible configuration

### Concerns/Anomalies

1. **Searchable Interface - DEAD CODE**
   - Defined but never implemented or used anywhere in the codebase
   - Redundant with Nodeable interface which already has `getVectorConfig()`
   - **Recommendation:** Remove or document a clear use case

2. **HasNodeableConfig Trait - No Internal Usage**
   - The trait is only referenced in examples/documentation within the package
   - No models in the package itself use it
   - This is by design (intended for client models) but should be documented

3. **RelationshipConfig::fromArray() Returns Null**
   - Returns null instead of throwing exception for invalid config
   - Relies on caller to filter nulls (GraphConfig does this)
   - Could be surprising behavior

4. **Hardcoded Paths in HasNodeableConfig**
   - `getConfigPath()` has hardcoded fallback path that may not exist
   - Line 531: `return __DIR__ . '/../../../config/entities.php';`

### Architecture Observations

1. **Domain-Driven Design**: Clear separation between contracts (interfaces), traits (behavior), and value objects (data)

2. **Immutability**: All value objects use readonly properties (PHP 8.1+), preventing accidental mutation

3. **Configuration Flexibility**: Multiple ways to configure entities:
   - Manual interface implementation
   - Trait with config files
   - Fluent builder pattern
   - Model property conventions

4. **Event-Driven Sync**: HasNodeableConfig hooks into Eloquent model events for automatic synchronization

5. **Queue Support**: Sync operations can be queued for better performance

### Dependency Graph

```
Nodeable (interface)
    |
    +-- uses --> GraphConfig (value object)
    |                |
    |                +-- uses --> RelationshipConfig (value object)
    |
    +-- uses --> VectorConfig (value object)
    |
    +-- implemented by --> HasNodeableConfig (trait)
                              |
                              +-- uses --> GraphConfig
                              +-- uses --> VectorConfig
                              +-- uses --> NodeableConfig (checks instanceof)
                              +-- uses --> AI facade
                              +-- uses --> Job classes

Searchable (interface) - ORPHANED
    |
    +-- uses --> VectorConfig

NodeableConfig (builder)
    |
    +-- produces --> GraphConfig
    +-- produces --> VectorConfig
    +-- uses --> EntityAutoDiscovery service
```

### Missing Implementation Pattern

The Domain layer defines contracts but implements nothing directly in the package. This is intentional:
- `Nodeable` - implemented by client models
- `Searchable` - intended for client models (but unused)
- `HasNodeableConfig` - used by client models to implement `Nodeable`

This pattern keeps the package flexible but means testing must occur in integration contexts.
