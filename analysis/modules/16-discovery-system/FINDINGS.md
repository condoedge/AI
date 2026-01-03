# Module 16: DISCOVERY_SYSTEM - Findings

> **Status:** COMPLETE
> **Date:** 2026-01-03

## Architecture Overview

The Discovery System is a comprehensive auto-discovery framework that introspects Laravel Eloquent models and generates configuration for Neo4j graph database and Qdrant vector storage. It converts Eloquent scopes to Cypher patterns and provides complete entity metadata extraction.

### Discovery Flow (Laravel Models to Cypher)

```
1. EntityAutoDiscovery.discoverAll()
   |
   +-> findNodeableModels()           # Find all Nodeable models in app/Models
   |
   +-> InheritanceResolver.resolve()  # Dedupe models sharing same table
   |
   +-> For each model:
       |
       +-> safeDiscovery() wrapper    # Transaction + events disabled
           |
           +-> discoverGraph()
           |   +-> AliasGenerator.generateLabel()
           |   +-> PropertyDiscoverer.discover()
           |   +-> RelationshipDiscoverer.discoverBidirectional()
           |
           +-> discoverVector()
           |   +-> AliasGenerator.generateCollectionName()
           |   +-> EmbedFieldDetector.detect()
           |   +-> PropertyDiscoverer.discover() for metadata
           |
           +-> discoverSecurityConfig()
           |   +-> Team resolution detection
           |   +-> Sensible columns extraction
           |
           +-> discoverMetadata()
               +-> AliasGenerator.generate()
               +-> CypherScopeAdapter.discoverScopes()
               |   +-> CypherQueryBuilderSpy (captures calls)
               |   +-> CypherPatternGenerator (converts to Cypher)
               +-> TraversalScopeGenerator.generateFromRelationship()
```

### Scope to Cypher Conversion Pipeline

```
Eloquent Scope Method
        |
        v
CypherScopeAdapter.parseScope()
        |
        v
CypherQueryBuilderSpy (mimics Query Builder)
        |  - Records: where, whereIn, whereHas, etc.
        v
CypherPatternGenerator.generate()
        |  - Converts operators (LIKE -> CONTAINS)
        |  - Handles nested conditions
        |  - Generates relationship patterns
        v
Cypher WHERE clause or MATCH pattern
```

## Component Summary

| Component | Purpose | Lines |
|-----------|---------|-------|
| `EntityAutoDiscovery` | Main orchestrator, coordinates all discoverers | 694 |
| `SchemaInspector` | Database schema introspection (FK, indexes, columns) | 703 |
| `AliasGenerator` | Generates semantic aliases and labels | 261 |
| `CypherPatternGenerator` | Converts query builder calls to Cypher | 516 |
| `CypherQueryBuilderSpy` | Mock query builder to capture scope calls | 481 |
| `CypherScopeAdapter` | Discovers and parses Eloquent scopes | 579 |
| `EmbedFieldDetector` | Identifies fields suitable for embeddings | 186 |
| `InheritanceResolver` | Handles model inheritance (same table) | 453 |
| `PropertyDiscoverer` | Discovers model properties | 395 |
| `RelationshipDiscoverer` | Discovers Eloquent relationships | 594 |
| `TraversalScopeGenerator` | Generates traversal scopes from discriminators | 356 |

## Issues Found

| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| DS-001 | LOW | Undefined helper function `getPrivateProperty` | `PropertyDiscoverer.php:204` uses `getPrivateProperty()` which is not defined in this class or imported | Define or import the helper function, or use reflection directly |
| DS-002 | LOW | LIKE to CONTAINS conversion may lose semantics | `CypherPatternGenerator.php:361-378` - LIKE with leading % becomes simple CONTAINS | Document that `%search%` becomes `CONTAINS 'search'` but `%search` (ends with) loses precision |
| DS-003 | INFO | Hard-coded role name list for examples | `CypherScopeAdapter.php:474,509` - Hard-coded list of role names | Consider making role names configurable |
| DS-004 | LOW | SQLite PRAGMA queries use string interpolation | `SchemaInspector.php:365,507,516` - PRAGMA requires interpolation | Properly sanitized (sanitizeTableName validates regex), but document this exception |
| DS-005 | INFO | Magic __call may mask errors | `CypherQueryBuilderSpy.php:449-480` - Unknown methods fall back to recording | Consider logging unresolved scope calls for debugging |
| DS-006 | LOW | Table name null check inconsistency | `AliasGenerator.php:249` checks `!== null` but `getTable()` throws, never returns null | Remove unnecessary null check or catch exception consistently |
| DS-007 | INFO | Recursion depth limit may be too low | `RelationshipDiscoverer.php:46` - MAX_STACK_DEPTH = 5 | May need adjustment for deeply nested model hierarchies |

## Strengths

1. **Safe Discovery Pattern**: `EntityAutoDiscovery.safeDiscovery()` wraps all operations in:
   - Database transaction (auto-rollback)
   - Disabled model events
   - Disabled event dispatcher

2. **SQL Injection Protection**: `SchemaInspector.sanitizeTableName()` properly validates table names for SQLite PRAGMA queries with:
   - Regex validation (alphanumeric, underscore, hyphen only)
   - Length limit (64 chars)

3. **Recursion Protection**: Multiple guards in place:
   - `RelationshipDiscoverer`: Stack tracking + MAX_STACK_DEPTH
   - `EntityAutoDiscovery.deepMerge()`: maxDepth parameter (default 10)
   - `CypherPatternGenerator`: Handles nested conditions safely

4. **Comprehensive Scope Conversion**: The spy pattern elegantly captures Eloquent scope intent without executing SQL, supporting:
   - Basic where clauses
   - whereIn, whereNull, whereBetween
   - whereHas (relationship traversal)
   - whereDate, whereTime
   - Nested closures
   - Dynamic scope resolution via __call

5. **Multi-Database Support**: `SchemaInspector` supports MySQL, PostgreSQL, and SQLite with proper query syntax for each.

6. **Inheritance Resolution**: `InheritanceResolver` properly handles Laravel's single-table inheritance patterns:
   - Detects parent-child relationships
   - Merges child aliases into parent
   - Extracts global scopes from children

7. **Bidirectional Relationships**: `RelationshipDiscoverer` generates both forward and inverse relationships automatically.

## Cypher Pattern Accuracy

The `CypherPatternGenerator` produces valid Cypher with the following mappings:

| Eloquent | Cypher |
|----------|--------|
| `where('status', 'active')` | `n.status = 'active'` |
| `where('age', '>', 18)` | `n.age > 18` |
| `whereIn('status', ['a','b'])` | `n.status IN ['a', 'b']` |
| `whereNull('deleted_at')` | `n.deleted_at IS NULL` |
| `whereNotNull('email')` | `n.email IS NOT NULL` |
| `whereBetween('age', [18, 65])` | `n.age >= 18 AND n.age <= 65` |
| `where('name', 'like', '%john%')` | `n.name CONTAINS 'john'` |
| `whereHas('orders')` | `MATCH (n)-[:HAS_ORDERS]->(o)` |
| `orWhere(...)` | Proper OR logic with precedence |

## Security Considerations

1. **No Cypher Injection**: Values are properly escaped via `escapeString()` (single quotes doubled)
2. **Sensitive Field Exclusion**: `PropertyDiscoverer` excludes password/secret fields
3. **Sensible Column Detection**: `EntityAutoDiscovery` reads `$sensibleColumns` from models
4. **Team Security Discovery**: Detects team resolution patterns for row-level security

## Test Coverage Recommendations

1. Test circular model relationships handling
2. Test deeply nested scope conditions
3. Test LIKE patterns with special characters
4. Test models without tables (abstract models)
5. Test inheritance with 3+ levels of hierarchy
