# Module 16: DISCOVERY_SYSTEM - Documentation Updates

> **Status:** COMPLETE
> **Date:** 2026-01-03

## Recommended Documentation Additions

### 1. Auto-Discovery Configuration Guide

Add to main documentation:

```markdown
## Auto-Discovery System

The Kompo AI package can automatically discover your Eloquent models and convert them
to Neo4j/Qdrant configurations.

### Enabling Auto-Discovery

```php
// config/ai.php
return [
    'auto_discovery' => [
        'enabled' => true,
        'excluded_models' => [
            App\Models\AuditLog::class,
            App\Models\Session::class,
        ],
        'role_mappings' => [
            'PersonTeam' => [
                'role_type' => [
                    3 => 'volunteers',
                    4 => 'scouts',
                ],
            ],
        ],
    ],
    'schema_cache_ttl' => 3600, // 1 hour
];
```

### Models Must Implement Nodeable

Only models implementing `Condoedge\Ai\Domain\Contracts\Nodeable` are discovered:

```php
use Condoedge\Ai\Domain\Contracts\Nodeable;

class Customer extends Model implements Nodeable
{
    // ...
}
```
```

### 2. Cypher Pattern Generation Documentation

Document the Eloquent-to-Cypher conversion rules:

```markdown
## Scope Conversion Reference

The discovery system converts Eloquent scopes to Cypher patterns:

### Supported Scope Methods

| Eloquent Method | Cypher Output | Notes |
|-----------------|---------------|-------|
| `where($col, $val)` | `n.col = 'val'` | |
| `where($col, '>', $val)` | `n.col > val` | |
| `whereIn($col, [...])` | `n.col IN [...]` | |
| `whereNull($col)` | `n.col IS NULL` | |
| `whereBetween($col, [a,b])` | `n.col >= a AND n.col <= b` | |
| `where($col, 'like', '%x%')` | `n.col CONTAINS 'x'` | See LIKE notes |
| `whereHas('relation')` | `MATCH (n)-[:RELATION]->(r)` | |

### LIKE Pattern Conversion

LIKE patterns are converted to CONTAINS which means:
- `%search%` -> `CONTAINS 'search'` (correct)
- `search%` -> `CONTAINS 'search'` (loses "starts with" meaning)
- `%search` -> `CONTAINS 'search'` (loses "ends with" meaning)

For precise prefix/suffix matching, use explicit Cypher patterns in config.
```

### 3. Inheritance Resolution Documentation

```markdown
## Model Inheritance

When multiple models share the same database table (single-table inheritance),
the discovery system:

1. **Identifies the parent model** - The model that others extend
2. **Merges child aliases** - Child class names become aliases on parent
3. **Extracts child scopes** - Scopes defined in children are added to parent
4. **Captures global scopes** - Child global scopes become available on parent

### Example

```php
// Parent model
class Person extends Model implements Nodeable
{
    protected $table = 'persons';
}

// Child with global scope
class Volunteer extends Person
{
    protected static function booted()
    {
        static::addGlobalScope('volunteer', function ($q) {
            $q->whereHas('teams', fn($q) => $q->where('role_type', 3));
        });
    }
}
```

Result: Only `Person` entity is created, but with:
- Aliases: `['person', 'persons', 'volunteer', 'volunteers']`
- Scopes: `['volunteer' => ['cypher_pattern' => '...']]`
```

### 4. Security Discovery Documentation

```markdown
## Security Configuration Discovery

The system detects team-based security patterns:

### Detected Patterns

1. **securityRelatedTeamIds() method** - For multi-team entities
2. **TEAM_ID_COLUMN property** - Explicit team column
3. **team_id column** - Conventional column name
4. **scopeSecurityForTeams() scope** - Query scope for filtering

### Sensible Columns

Mark columns that should be protected:

```php
class User extends Model
{
    protected array $sensibleColumns = [
        'ssn',
        'bank_account',
        'salary',
    ];
}
```

These columns are:
- Excluded from vector embeddings
- Flagged in security configuration
```

### 5. Schema Caching Documentation

```markdown
## Schema Caching

Database schema information is cached to improve performance:

### Configuration

```php
// config/ai.php
'schema_cache_ttl' => 3600, // Seconds (1 hour default)
```

### Clearing Cache

```php
// Clear specific table
$inspector = app(SchemaInspector::class);
$inspector->clearCache('users');

// Clear all
$inspector->clearAllCaches();
```

### When to Clear

- After migrations
- After adding columns
- After modifying indexes
```

## API Documentation Additions

### EntityAutoDiscovery

```php
/**
 * Discover complete configuration for a model
 *
 * @param string|Model $model Model class name or instance
 * @return array{
 *   graph: array{label: string, properties: array, relationships: array},
 *   vector: array{collection: string, embed_fields: array, metadata: array},
 *   security: array{team_resolution: ?string, sensible_columns: array},
 *   metadata: array{aliases: array, scopes: array, description: string}
 * }
 */
public function discover(string|Model $model): array
```

### SchemaInspector

```php
/**
 * Get foreign key columns from a table
 *
 * Supports: MySQL, PostgreSQL, SQLite
 *
 * @param string $table Table name
 * @return array<string, array{table: string, column: string}>
 */
public function getForeignKeys(string $table): array
```

## Inline Code Documentation Suggestions

### SchemaInspector.php

Add note about SQLite PRAGMA:
```php
/**
 * Get foreign keys for SQLite
 *
 * NOTE: SQLite PRAGMA commands don't support parameter binding.
 * Table name is validated via sanitizeTableName() which:
 * - Only allows alphanumeric, underscore, hyphen
 * - Limits length to 64 characters
 * - Throws InvalidArgumentException for invalid names
 */
```

### CypherPatternGenerator.php

Add note about LIKE conversion:
```php
/**
 * Convert LIKE pattern to CONTAINS pattern
 *
 * WARNING: This conversion loses precision for:
 * - 'search%' (starts with) -> CONTAINS 'search'
 * - '%search' (ends with) -> CONTAINS 'search'
 *
 * For exact prefix/suffix matching, use manual Cypher configuration.
 */
```

### PropertyDiscoverer.php

Fix undefined function:
```php
// Line 204: getPrivateProperty is undefined
// Either import it or use reflection directly:
$reflection = new \ReflectionProperty($model, 'metadataColumns');
$reflection->setAccessible(true);
$metadataColumns = $reflection->getValue($model);
```
