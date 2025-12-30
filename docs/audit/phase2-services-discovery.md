# Phase 2 Audit: Discovery Services

**Date:** 2025-12-30
**Directory:** `src/Services/Discovery/`
**Files Reviewed:** 11

## Overview

The Discovery services provide automatic entity configuration discovery for the AI/RAG system. They introspect Eloquent models to generate Neo4j graph and Qdrant vector configurations without manual setup. This is the core of the "write models, get AI" philosophy.

## Architecture Summary

```
EntityAutoDiscovery (orchestrator)
    |
    +-- SchemaInspector (database hints)
    +-- CypherScopeAdapter (Eloquent scopes -> Cypher)
    |       |
    |       +-- CypherQueryBuilderSpy (captures calls)
    |       +-- CypherPatternGenerator (generates Cypher)
    +-- PropertyDiscoverer (model properties)
    +-- RelationshipDiscoverer (Eloquent relationships)
    +-- AliasGenerator (semantic aliases)
    +-- EmbedFieldDetector (vector embed fields)
    +-- TraversalScopeGenerator (role-based traversals)
    +-- InheritanceResolver (parent/child models)
```

---

## Service Analysis

### 1. AliasGenerator.php

**Purpose:** Generates semantic aliases from model and table names for better natural language query matching.

**What it discovers/generates:**
- Singular/plural inflections (customer/customers)
- Business term synonyms (customer -> client, buyer, patron)
- Collection names for vector storage
- Neo4j node labels

**Key Methods:**
| Method | Purpose | Status |
|--------|---------|--------|
| `generate()` | Generate all aliases for a model | USED |
| `generateLabel()` | Generate Neo4j node label | USED |
| `generateCollectionName()` | Generate Qdrant collection name | USED |
| `inflections()` | Generate singular/plural forms | Internal |
| `businessTerms()` | Get business synonyms | Internal |
| `normalizeWord()` | Remove prefixes/suffixes | Internal |
| `resolveModel()` | Convert class to instance | Internal |

**Dependencies:**
- `Illuminate\Support\Str` (inflection)
- No external service dependencies

**Where Used:**
- `EntityAutoDiscovery` - via constructor injection
- `AiServiceProvider` - registered as singleton
- Tests - `EntityAutoDiscoveryTest`

**Constants:**
- `BUSINESS_TERMS` - Maps entity names to synonyms (19 mappings)

**Notes/Anomalies:**
- Well-designed standalone utility
- Business terms could be configurable vs. hardcoded
- No unused methods

---

### 2. CypherPatternGenerator.php

**Purpose:** Converts recorded query builder spy calls into Neo4j Cypher WHERE clause patterns.

**What it discovers/generates:**
- Cypher conditions from Eloquent `where()` calls
- Full Cypher MATCH queries from relationship structures

**Key Methods:**
| Method | Purpose | Status |
|--------|---------|--------|
| `generate()` | Generate conditions from recorded calls | USED |
| `generateFullQuery()` | Generate complete Cypher query | USED |
| `generateCondition()` | Generate single condition | Internal |
| `generateWhere()` | Handle basic where | Internal |
| `generateWhereIn()` | Handle whereIn | Internal |
| `generateWhereNull()` | Handle whereNull | Internal |
| `generateWhereHas()` | Handle relationship queries | Internal |
| `generateWhereDate()` | Handle date comparisons | Internal |
| `generateWhereTime()` | Handle time comparisons | Internal |
| `generateWhereBetween()` | Handle range queries | Internal |
| `generateWhereColumn()` | Handle column comparisons | Internal |
| `convertOperator()` | Map SQL to Cypher operators | Internal |
| `formatValue()` | Format values for Cypher | Internal |
| `escapeString()` | Escape strings safely | Internal |
| `relationshipNameToType()` | Convert relation name to type | Internal |
| `combineConditions()` | Combine with AND/OR | Internal |

**Dependencies:**
- None (standalone)

**Where Used:**
- `CypherScopeAdapter` - via constructor injection
- `InheritanceResolver` - direct instantiation
- `AiServiceProvider` - registered as singleton
- Tests - `CypherPatternGeneratorTest`, `CypherScopeAdapterTest`

**Constants:**
- `OPERATOR_MAP` - SQL to Cypher operator mappings

**Notes/Anomalies:**
- Comprehensive SQL to Cypher translation
- Handles nested where clauses
- No unused public methods

---

### 3. CypherQueryBuilderSpy.php

**Purpose:** Query builder spy that records Eloquent method calls instead of executing SQL. Acts as a test double for the real query builder.

**What it discovers/generates:**
- Records all query builder method calls
- Captures nested closure callbacks
- Resolves model scopes dynamically

**Key Methods:**
| Method | Purpose | Status |
|--------|---------|--------|
| `where()` | Record where clauses | USED |
| `orWhere()` | Record OR conditions | USED |
| `whereIn()` | Record IN clauses | USED |
| `whereNotIn()` | Record NOT IN clauses | Available |
| `whereNull()` | Record NULL checks | USED |
| `whereNotNull()` | Record NOT NULL | Available |
| `whereHas()` | Record relationship exists | USED |
| `whereDoesntHave()` | Record relationship missing | Available |
| `whereDate()` | Record date comparison | USED |
| `whereTime()` | Record time comparison | Available |
| `whereBetween()` | Record range queries | Available |
| `whereNotBetween()` | Record not between | Available |
| `whereColumn()` | Record column comparison | Available |
| `getCalls()` | Get recorded calls | USED |
| `getModelClass()` | Get model context | Available |
| `hasCalls()` | Check if calls recorded | USED |
| `clearCalls()` | Reset recorded calls | Available |
| `countCalls()` | Count recorded calls | Available |
| `__call()` | Handle scope method calls | USED |

**Dependencies:**
- `Closure` (for nested where handling)

**Where Used:**
- `CypherScopeAdapter` - via constructor injection
- `InheritanceResolver` - direct instantiation
- `AiServiceProvider` - registered as bind (not singleton)
- Tests - `CypherQueryBuilderSpyTest`, `CypherScopeAdapterTest`

**Notes/Anomalies:**
- **IMPORTANT:** Registered as `bind` not `singleton` (fresh instance each use)
- `__call()` magic method enables scope resolution
- Some methods are available but may not be actively used (whereNotBetween, countCalls)

---

### 4. CypherScopeAdapter.php

**Purpose:** Discovers Eloquent scopes in models and converts them to Cypher patterns for the RAG system.

**What it discovers/generates:**
- All scope methods from a model class
- Cypher patterns from scope execution
- Relationship traversal specifications
- Example queries for each scope

**Key Methods:**
| Method | Purpose | Status |
|--------|---------|--------|
| `discoverScopes()` | Discover all scopes in model | USED |
| `parseScope()` | Parse single scope to Cypher | USED |
| `extractScopeMethods()` | Find scope* methods via reflection | Internal |
| `executeScopeWithSpy()` | Run scope with spy capture | Internal |
| `determineScopeType()` | Categorize scope (filter/traversal) | Internal |
| `generatePropertyFilterScope()` | Generate property filter metadata | Internal |
| `generateRelationshipScope()` | Generate relationship metadata | Internal |
| `generateGenericScope()` | Fallback scope format | Internal |
| `parseRelationshipStructure()` | Extract relationship details | Internal |
| `extractFilterFromCalls()` | Get filter key/values | Internal |
| `extractRoleValueFromCalls()` | Get role discriminator values | Internal |
| `generateConcept()` | Human-readable concept | Internal |
| `generateExamples()` | Generate example questions | Internal |
| `getSpy()` | Get spy instance | Available |
| `getGenerator()` | Get generator instance | Available |

**Dependencies:**
- `CypherQueryBuilderSpy`
- `CypherPatternGenerator`
- `ReflectionClass`, `ReflectionMethod`

**Where Used:**
- `EntityAutoDiscovery` - via constructor injection
- `AiServiceProvider` - registered as singleton
- `DetectedScopesSection` - uses scope output format
- Tests - `CypherScopeAdapterTest`, `NestedScopeDiscoveryTest`, stress tests

**Notes/Anomalies:**
- `getSpy()` and `getGenerator()` are public but may be unused outside tests
- Sophisticated scope introspection via reflection
- Handles both property filters and relationship traversals

---

### 5. EmbedFieldDetector.php

**Purpose:** Detects which model fields are suitable for vector embeddings by analyzing column types and names.

**What it discovers/generates:**
- List of text fields suitable for embedding
- Excludes IDs, foreign keys, system fields, sensitive data

**Key Methods:**
| Method | Purpose | Status |
|--------|---------|--------|
| `detect()` | Detect embeddable fields | USED |
| `shouldEmbed()` | Check if column should embed | Internal |
| `matchesTextPattern()` | Match text field patterns | Internal |
| `resolveModel()` | Convert class to instance | Internal |

**Dependencies:**
- `SchemaInspector` - for column type detection

**Where Used:**
- `EntityAutoDiscovery` - via constructor injection
- `AiServiceProvider` - registered as singleton
- Tests - `EntityAutoDiscoveryTest`

**Constants:**
- `TEXT_FIELD_PATTERNS` - Names that indicate embeddable text (14 patterns)
- `EXCLUDE_PATTERNS` - Names to exclude (11 patterns)

**Notes/Anomalies:**
- Clean, focused single responsibility
- Pattern lists could be configurable
- No unused methods

---

### 6. EntityAutoDiscovery.php (ORCHESTRATOR)

**Purpose:** Main orchestrator that ties together all discovery components to provide complete auto-discovery functionality.

**What it discovers/generates:**
- Complete entity configuration (graph + vector + security + metadata)
- All Nodeable models in application
- Inheritance resolution for shared tables

**Key Methods:**
| Method | Purpose | Status |
|--------|---------|--------|
| `discover()` | Discover complete config for model | USED |
| `discoverAll()` | Discover all Nodeable models | USED |
| `findNodeableModels()` | Find models implementing Nodeable | USED |
| `discoverGraph()` | Discover Neo4j configuration | USED |
| `discoverVector()` | Discover Qdrant configuration | USED |
| `discoverMetadata()` | Discover aliases, scopes, etc. | USED |
| `discoverAndMerge()` | Discover and merge with manual config | Available |
| `shouldDiscover()` | Check if model should be discovered | Available |
| `getClassFromFile()` | Extract class name from PHP file | Internal |
| `discoverTraversalScopes()` | Generate traversal scopes | Internal |
| `discoverSecurityConfig()` | Detect team resolution patterns | Internal |
| `getSensibleColumns()` | Get protected columns | Internal |
| `getModelProperty()` | Read via reflection | Internal |
| `hasColumn()` | Check if column exists | Internal |
| `safeDiscovery()` | Execute with transaction rollback | Internal |
| `deepMerge()` | Merge configurations | Internal |

**Dependencies:**
- All other discovery services (7 total)
- `Symfony\Component\Finder\Finder`
- Laravel facades: `DB`, `Event`, `File`

**Where Used:**
- `DiscoverEntitiesCommand` - CLI command injection
- `AiServiceProvider` - registered as singleton
- Tests - `EntityAutoDiscoveryTest`, `TeamResolutionDiscoveryTest`, `SensibleColumnsDiscoveryTest`, stress tests

**Notes/Anomalies:**
- **Safe Discovery:** Runs in transaction that always rolls back
- **Event Isolation:** Disables model events during discovery
- `discoverAndMerge()` and `shouldDiscover()` may be underutilized
- Handles inheritance deduplication automatically
- Security config discovery for team resolution

---

### 7. InheritanceResolver.php

**Purpose:** Detects when multiple Nodeable models share the same database table (inheritance) and consolidates them.

**What it discovers/generates:**
- Parent/child model relationships
- Merged aliases from child models
- Scopes from child models
- Global scopes from child models with Cypher patterns

**Key Methods:**
| Method | Purpose | Status |
|--------|---------|--------|
| `resolve()` | Resolve inheritance and deduplicate | USED |
| `mergeInheritanceInfo()` | Merge child info into parent config | USED |
| `groupByTable()` | Group models by database table | Internal |
| `resolveHierarchy()` | Find parent vs children | Internal |
| `extractAliases()` | Get aliases from child class names | Internal |
| `extractScopes()` | Get scopes from child models | Internal |
| `extractGlobalScopes()` | Get and convert global scopes | Internal |
| `executeScopeAndGenerateCypher()` | Run closure and generate pattern | Internal |
| `extractScopeFromBooted()` | Extract scopes from booted() | Internal |

**Dependencies:**
- `CypherQueryBuilderSpy` - direct instantiation
- `CypherPatternGenerator` - direct instantiation

**Where Used:**
- `EntityAutoDiscovery` - via constructor injection
- `AiServiceProvider` - registered as singleton
- Tests - `InheritanceResolverTest`

**Notes/Anomalies:**
- Sophisticated inheritance detection
- Converts global scope closures to Cypher patterns
- Creates internal instances of spy and generator (not injected)
- All public methods are used

---

### 8. PropertyDiscoverer.php

**Purpose:** Discovers properties from Eloquent model attributes and enhances them with database schema information.

**What it discovers/generates:**
- All model properties (fillable, casts, dates, attributes)
- Property types from schema
- Property descriptions (auto-generated)

**Key Methods:**
| Method | Purpose | Status |
|--------|---------|--------|
| `discover()` | Discover all properties | USED |
| `discoverWithTypes()` | Discover with type information | Available |
| `discoverDescriptions()` | Generate property descriptions | USED |
| `fromModelAttributes()` | Extract from model metadata | Internal |
| `fromModelMetadataProperty()` | Extract from $metadataColumns | Internal |
| `enhanceWithSchema()` | Add schema-discovered properties | Internal |
| `filterExcluded()` | Remove sensitive properties | Internal |
| `ensureEssentialFields()` | Include pk and timestamps | Internal |
| `generateDescription()` | Generate human-readable desc | Internal |

**Dependencies:**
- `SchemaInspector` - for column types and indexes

**Where Used:**
- `EntityAutoDiscovery` - via constructor injection
- `AiServiceProvider` - registered as singleton
- Tests - `EntityAutoDiscoveryTest`, stress tests

**Constants:**
- `EXCLUDED_PROPERTIES` - Sensitive fields to exclude (5 entries)

**Notes/Anomalies:**
- `discoverWithTypes()` is public but may be underutilized
- Uses global `getPrivateProperty()` helper (potential issue)
- Good handling of sensitive field exclusion

---

### 9. RelationshipDiscoverer.php

**Purpose:** Discovers Eloquent relationships from model methods and converts them to Neo4j relationship format.

**What it discovers/generates:**
- All relationship methods via reflection
- Neo4j relationship configurations
- Bidirectional relationships (forward and inverse)
- Discriminator field detection in related models

**Key Methods:**
| Method | Purpose | Status |
|--------|---------|--------|
| `discover()` | Discover all relationships | USED |
| `discoverBidirectional()` | Discover with inverse relationships | USED |
| `detectDiscriminatorInRelation()` | Find discriminator fields | Available |
| `fromEloquentMethods()` | Extract via reflection | Internal |
| `enhanceWithForeignKeys()` | Add FK-inferred relationships | Internal |
| `shouldSkipMethod()` | Filter non-relationship methods | Internal |
| `isRelation()` | Check if method returns Relation | Internal |
| `convertToNeo4jRelationship()` | Convert to Neo4j format | Internal |
| `enhanceWithDiscriminatorInfo()` | Add discriminator metadata | Internal |
| `generateRelationshipName()` | Create unique name | Internal |
| `generateInverseName()` | Create inverse name | Internal |
| `createInverseRelationship()` | Build inverse config | Internal |

**Dependencies:**
- `SchemaInspector` - optional for FK hints
- `TraversalScopeGenerator` - for discriminator detection
- Various Eloquent Relation classes

**Where Used:**
- `EntityAutoDiscovery` - via constructor injection
- `AiServiceProvider` - registered as singleton
- Tests - `EntityAutoDiscoveryTest`, stress tests

**Notes/Anomalies:**
- **Recursion Protection:** Uses `discoveryStack` and `MAX_STACK_DEPTH = 5`
- `detectDiscriminatorInRelation()` is public but may be unused
- Handles BelongsTo, HasMany, HasOne, BelongsToMany
- MorphTo relationships are skipped (documented)

---

### 10. SchemaInspector.php

**Purpose:** Extracts strategic hints from Laravel database schema for auto-discovery.

**What it discovers/generates:**
- Foreign key columns and their targets
- Text columns suitable for embeddings
- Indexed columns (likely important)
- All column types

**Key Methods:**
| Method | Purpose | Status |
|--------|---------|--------|
| `getForeignKeys()` | Get foreign key mappings | USED |
| `getTextColumns()` | Get text-type columns | USED |
| `getIndexedColumns()` | Get indexed columns | USED |
| `getColumnTypes()` | Get all column types | USED |
| `clearCache()` | Clear cache for table | Available |
| `clearAllCaches()` | Clear all cached data | Available |
| `getForeignKeyConstraints()` | DB-specific FK detection | Internal |
| `getMysqlForeignKeys()` | MySQL FK query | Internal |
| `getPostgresForeignKeys()` | PostgreSQL FK query | Internal |
| `getSqliteForeignKeys()` | SQLite FK query | Internal |
| `getTableIndexes()` | Get indexes | Internal |
| `getMysqlIndexes()` | MySQL index query | Internal |
| `getPostgresIndexes()` | PostgreSQL index query | Internal |
| `getSqliteIndexes()` | SQLite index query | Internal |
| `getAllTables()` | Get all table names | Internal |
| `getMysqlTables()` | MySQL tables query | Internal |
| `getPostgresTables()` | PostgreSQL tables query | Internal |
| `getSqliteTables()` | SQLite tables query | Internal |
| `sanitizeTableName()` | Prevent SQL injection | Internal |
| `pluralize()` | Simple pluralization | Internal |
| `cached()` | Cache helper | Internal |

**Dependencies:**
- `Illuminate\Support\Facades\DB`
- `Illuminate\Support\Facades\Schema`
- `Illuminate\Support\Facades\Cache`

**Where Used:**
- `PropertyDiscoverer` - via constructor injection
- `RelationshipDiscoverer` - via constructor injection
- `EmbedFieldDetector` - via constructor injection
- `EntityAutoDiscovery` - via constructor injection
- `AiServiceProvider` - registered as singleton
- Tests - `SchemaInspectorTest`

**Constants:**
- `CACHE_TTL = 3600` - 1 hour cache
- `TEXT_COLUMN_PATTERNS` - Text column name patterns (11)
- `TEXT_COLUMN_TYPES` - Text column types (4)

**Notes/Anomalies:**
- **Multi-Database Support:** MySQL, PostgreSQL, SQLite
- **Security:** `sanitizeTableName()` prevents SQL injection in PRAGMA
- **Caching:** Results cached 1 hour per table
- `clearCache()` and `clearAllCaches()` are public but may be rarely used
- FK detection uses both constraints AND `*_id` naming convention

---

### 11. TraversalScopeGenerator.php

**Purpose:** Generates traversal scopes on source entities based on relationships to target entities with discriminator fields.

**What it discovers/generates:**
- Traversal scopes (e.g., "volunteers" from PersonTeam.role_type)
- Cypher patterns for discriminator-based filtering
- Example queries for generated scopes

**Key Methods:**
| Method | Purpose | Status |
|--------|---------|--------|
| `generateFromRelationship()` | Generate scopes from relationship | USED |
| `detectDiscriminatorFields()` | Find discriminator columns | USED |
| `getDiscriminatorFields()` | Get known discriminator names | Available |
| `isDiscriminatorField()` | Check if field is discriminator | Available |
| `getRoleMappings()` | Get configured role mappings | USED |
| `generateScope()` | Generate single scope config | Internal |
| `generateCypherPattern()` | Generate Cypher pattern | Internal |
| `inferDirection()` | Infer relationship direction | Internal |
| `formatValue()` | Format values for Cypher | Internal |
| `generateConcept()` | Generate concept description | Internal |
| `generateExamples()` | Generate example questions | Internal |
| `extractRoleValue()` | Get human-readable role | Internal |
| `getDefaultMappings()` | Get default role mappings | Internal |

**Dependencies:**
- `Illuminate\Support\Str`

**Where Used:**
- `EntityAutoDiscovery` - via constructor injection
- `RelationshipDiscoverer` - via constructor injection
- `AiServiceProvider` - registered as singleton

**Constants:**
- `DISCRIMINATOR_FIELDS` - Known discriminator field names (6)

**Notes/Anomalies:**
- `getDiscriminatorFields()` and `isDiscriminatorField()` are public but may be test-only
- Requires explicit role mappings in configuration
- `getDefaultMappings()` returns empty array (documented as requiring explicit config)

---

## Service Dependency Graph

```
EntityAutoDiscovery (main entry point)
    |
    +-- SchemaInspector
    |       [Used by: PropertyDiscoverer, RelationshipDiscoverer, EmbedFieldDetector]
    |
    +-- CypherScopeAdapter
    |       +-- CypherQueryBuilderSpy
    |       +-- CypherPatternGenerator
    |
    +-- RelationshipDiscoverer
    |       +-- SchemaInspector (optional)
    |       +-- TraversalScopeGenerator
    |
    +-- PropertyDiscoverer
    |       +-- SchemaInspector
    |
    +-- AliasGenerator (no dependencies)
    |
    +-- EmbedFieldDetector
    |       +-- SchemaInspector
    |
    +-- TraversalScopeGenerator (no service dependencies)
    |
    +-- InheritanceResolver
            +-- CypherQueryBuilderSpy (creates internally)
            +-- CypherPatternGenerator (creates internally)
```

---

## CLI Command Integration

### DiscoverEntitiesCommand (`ai:discover`)

**Uses:** `EntityAutoDiscovery` via constructor injection

**Options:**
- `--model=` - Specific model to discover
- `--force` - Overwrite existing config
- `--dry-run` - Preview without writing

**Output:** Generates `config/entities.php`

---

## Service Provider Registration

All discovery services registered in `registerDiscoveryServices()`:

| Service | Registration Type |
|---------|-------------------|
| SchemaInspector | singleton |
| CypherQueryBuilderSpy | **bind** (new each time) |
| CypherPatternGenerator | singleton |
| CypherScopeAdapter | singleton |
| PropertyDiscoverer | singleton |
| RelationshipDiscoverer | singleton |
| AliasGenerator | singleton |
| EmbedFieldDetector | singleton |
| TraversalScopeGenerator | singleton |
| InheritanceResolver | singleton |
| EntityAutoDiscovery | singleton |

---

## Unused/Underutilized Methods

### Potentially Unused Public Methods:

| Service | Method | Notes |
|---------|--------|-------|
| CypherQueryBuilderSpy | `whereNotIn()` | Available but not tested |
| CypherQueryBuilderSpy | `whereNotNull()` | Available but not tested |
| CypherQueryBuilderSpy | `whereDoesntHave()` | Available but not tested |
| CypherQueryBuilderSpy | `whereTime()` | Available but not tested |
| CypherQueryBuilderSpy | `whereBetween()` | Available but not tested |
| CypherQueryBuilderSpy | `whereNotBetween()` | Available but not tested |
| CypherQueryBuilderSpy | `whereColumn()` | Available but not tested |
| CypherQueryBuilderSpy | `getModelClass()` | Utility |
| CypherQueryBuilderSpy | `clearCalls()` | Utility |
| CypherQueryBuilderSpy | `countCalls()` | Utility |
| CypherScopeAdapter | `getSpy()` | Test helper |
| CypherScopeAdapter | `getGenerator()` | Test helper |
| PropertyDiscoverer | `discoverWithTypes()` | May be unused |
| RelationshipDiscoverer | `detectDiscriminatorInRelation()` | May be unused |
| SchemaInspector | `clearCache()` | Utility |
| SchemaInspector | `clearAllCaches()` | Utility |
| TraversalScopeGenerator | `getDiscriminatorFields()` | Test helper |
| TraversalScopeGenerator | `isDiscriminatorField()` | Test helper |
| EntityAutoDiscovery | `discoverAndMerge()` | May be unused |
| EntityAutoDiscovery | `shouldDiscover()` | May be unused |

---

## Notes and Anomalies

### Design Issues

1. **InheritanceResolver creates internal instances**
   - Creates `CypherQueryBuilderSpy` and `CypherPatternGenerator` internally instead of injecting
   - Breaks dependency injection pattern

2. **Global helper usage in PropertyDiscoverer**
   - Uses `getPrivateProperty()` global helper
   - Should use reflection directly

3. **Hardcoded business terms in AliasGenerator**
   - Could be configurable via config file

4. **Empty default mappings in TraversalScopeGenerator**
   - `getDefaultMappings()` returns empty - requires explicit config

### Strengths

1. **Safe Discovery execution**
   - Runs in transaction that always rolls back
   - Disables model events during introspection
   - Prevents side effects

2. **Multi-database support**
   - SchemaInspector works with MySQL, PostgreSQL, SQLite
   - Proper SQL injection prevention for SQLite PRAGMA

3. **Recursion protection**
   - RelationshipDiscoverer has stack depth limit (5)
   - Prevents infinite loops in circular relationships

4. **Intelligent caching**
   - SchemaInspector caches results for 1 hour
   - Configurable via `ai.schema_cache_ttl`

5. **Comprehensive scope conversion**
   - Converts Eloquent scopes to Cypher patterns
   - Handles nested scopes and relationships
   - Generates example queries automatically

---

## Integration Points

### Used By External Services

| External Service | Uses |
|------------------|------|
| DiscoverEntitiesCommand | EntityAutoDiscovery |
| DetectedScopesSection | Scope output format |
| HasNodeableConfigTest | EntityAutoDiscovery |

### Uses External Services

| Discovery Service | External Dependency |
|-------------------|---------------------|
| SchemaInspector | Laravel DB, Schema, Cache facades |
| EntityAutoDiscovery | Laravel DB, Event facades, Finder |

---

## Recommendations

1. **Refactor InheritanceResolver** - Inject dependencies instead of creating internally

2. **Replace global helper** - PropertyDiscoverer should use reflection directly

3. **Make patterns configurable** - Business terms, discriminator fields, text patterns

4. **Add tests for unused methods** - CypherQueryBuilderSpy has many untested methods

5. **Document role mappings** - TraversalScopeGenerator requires explicit config but lacks documentation

6. **Consider removing unused public methods** - Or add tests to verify they work

---

## Summary

The Discovery services form a well-architected subsystem for automatic entity configuration. The main orchestrator (`EntityAutoDiscovery`) coordinates 8 specialized discoverers to introspect Eloquent models and generate Neo4j graph and Qdrant vector configurations.

**Key Statistics:**
- 11 service files
- ~2,500 lines of code
- All services registered in container
- All services used by CLI command
- ~20 potentially underutilized public methods

**Quality Assessment:** HIGH
- Clear separation of concerns
- Comprehensive scope-to-Cypher conversion
- Safe execution with transaction rollback
- Multi-database support
- Good caching strategy
