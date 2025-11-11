# CypherScope Adapter

## Overview

The **CypherScope Adapter** is a powerful translation system that automatically converts Eloquent-style query scopes into Neo4j Cypher patterns. This allows developers to write familiar Laravel code while the RAG system automatically generates optimized graph queries.

## Architecture

### Flow

```
Developer writes Eloquent scope
         ↓
CypherScopeAdapter discovers scopes
         ↓
CypherQueryBuilderSpy records method calls
         ↓
CypherPatternGenerator converts to Cypher
         ↓
Output feeds into RAG system for query generation
```

### Components

1. **CypherScopeAdapter** - Main orchestrator that discovers and parses scopes
2. **CypherQueryBuilderSpy** - Spy pattern that records query builder calls
3. **CypherPatternGenerator** - Converts recorded calls to Cypher syntax

## Quick Start

### 1. Write Eloquent Scopes

```php
class Customer extends Model implements Nodeable
{
    /**
     * Scope: Active customers
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: High-value customers
     */
    public function scopeHighValue($query)
    {
        return $query->where('lifetime_value', '>', 5000);
    }

    /**
     * Scope: Customers with completed orders
     */
    public function scopeWithCompletedOrders($query)
    {
        return $query->whereHas('orders', function($q) {
            $q->where('status', 'completed');
        });
    }
}
```

### 2. Discover Scopes

```php
use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;

$adapter = new CypherScopeAdapter();
$scopes = $adapter->discoverScopes(Customer::class);

// Returns:
[
    'active' => [
        'specification_type' => 'property_filter',
        'concept' => 'Customers with Active status',
        'cypher_pattern' => "n.status = 'active'",
        'filter' => ['status' => 'active'],
        'examples' => [
            'Show active customers',
            'List active customers',
            ...
        ],
    ],
    'high_value' => [...],
    'with_completed_orders' => [...],
]
```

### 3. Integrate with Entity Config

```php
// config/entities.php

return [
    'Customer' => [
        'graph' => [...],
        'vector' => [...],
        'metadata' => [
            'scopes' => $scopes, // Auto-generated from CypherScopeAdapter
        ],
    ],
];
```

## Supported Query Builder Methods

### Basic Where Clauses

| Method | Example | Cypher Output |
|--------|---------|---------------|
| `where()` | `where('status', 'active')` | `n.status = 'active'` |
| `where()` with operator | `where('total', '>', 100)` | `n.total > 100` |
| `orWhere()` | `orWhere('status', 'pending')` | `OR n.status = 'pending'` |

### In/Not In

| Method | Example | Cypher Output |
|--------|---------|---------------|
| `whereIn()` | `whereIn('status', ['active', 'pending'])` | `n.status IN ['active', 'pending']` |
| `whereNotIn()` | `whereNotIn('status', ['cancelled'])` | `n.status NOT IN ['cancelled']` |

### Null Checks

| Method | Example | Cypher Output |
|--------|---------|---------------|
| `whereNull()` | `whereNull('deleted_at')` | `n.deleted_at IS NULL` |
| `whereNotNull()` | `whereNotNull('email')` | `n.email IS NOT NULL` |

### Date/Time

| Method | Example | Cypher Output |
|--------|---------|---------------|
| `whereDate()` | `whereDate('created_at', '>=', '2024-01-01')` | `date(n.created_at) >= date('2024-01-01')` |
| `whereTime()` | `whereTime('created_at', '>', '12:00')` | `time(n.created_at) > time('12:00')` |

### Between

| Method | Example | Cypher Output |
|--------|---------|---------------|
| `whereBetween()` | `whereBetween('total', [100, 500])` | `n.total >= 100 AND n.total <= 500` |
| `whereNotBetween()` | `whereNotBetween('total', [100, 500])` | `NOT (n.total >= 100 AND n.total <= 500)` |

### Column Comparison

| Method | Example | Cypher Output |
|--------|---------|---------------|
| `whereColumn()` | `whereColumn('start_date', '<', 'end_date')` | `n.start_date < n.end_date` |

### Relationships

| Method | Example | Cypher Output |
|--------|---------|---------------|
| `whereHas()` | `whereHas('orders')` | `MATCH (n:Customer)-[:HAS_ORDERS]->(o:Order) RETURN DISTINCT n` |
| `whereHas()` with conditions | `whereHas('orders', fn($q) => $q->where('status', 'completed'))` | `MATCH (n:Customer)-[:HAS_ORDERS]->(o:Order) WHERE o.status = 'completed' RETURN DISTINCT n` |
| `whereDoesntHave()` | `whereDoesntHave('orders')` | `NOT EXISTS {(MATCH (n)-[:HAS_ORDERS]->(o))}` |

## Operator Conversion

| Eloquent | Cypher | Status |
|----------|--------|--------|
| `=` | `=` | ✅ |
| `>` | `>` | ✅ |
| `<` | `<` | ✅ |
| `>=` | `>=` | ✅ |
| `<=` | `<=` | ✅ |
| `!=` / `<>` | `<>` | ✅ |
| `LIKE` | `CONTAINS` | ✅ |
| `IN` | `IN` | ✅ |
| `IS NULL` | `IS NULL` | ✅ |
| `IS NOT NULL` | `IS NOT NULL` | ✅ |

## Examples

### Example 1: Simple Property Filter

**Eloquent Scope:**
```php
public function scopeActive($query)
{
    return $query->where('status', 'active');
}
```

**Generated Metadata:**
```php
[
    'specification_type' => 'property_filter',
    'concept' => 'Customers with Active status',
    'cypher_pattern' => "n.status = 'active'",
    'filter' => ['status' => 'active'],
    'examples' => [
        'Show active customers',
        'List active customers',
        'Find all active customers',
        'How many active customers are there?',
    ],
]
```

### Example 2: Multiple Conditions

**Eloquent Scope:**
```php
public function scopeVip($query)
{
    return $query->where('status', 'active')
                 ->where('lifetime_value', '>=', 5000);
}
```

**Generated Metadata:**
```php
[
    'specification_type' => 'property_filter',
    'concept' => 'Customers with Vip status',
    'cypher_pattern' => "n.status = 'active' AND n.lifetime_value >= 5000",
    'filter' => [],
    'examples' => [
        'Show vip customers',
        'List vip customers',
        'Find all vip customers',
        'How many vip customers are there?',
    ],
]
```

### Example 3: Relationship Traversal

**Eloquent Scope:**
```php
public function scopeWithCompletedOrders($query)
{
    return $query->whereHas('orders', function($q) {
        $q->where('status', 'completed');
    });
}
```

**Generated Metadata:**
```php
[
    'specification_type' => 'relationship_traversal',
    'concept' => 'Customers that are With Completed Orders',
    'cypher_pattern' => "MATCH (n:Customer)-[:HAS_ORDERS]->(o:Order) WHERE o.status = 'completed' RETURN DISTINCT n",
    'parsed_structure' => [
        'entity' => 'Customer',
        'relationships' => [
            [
                'type' => 'HAS_ORDERS',
                'target' => 'Order',
            ],
        ],
        'conditions' => [
            [
                'entity' => 'o',
                'field' => 'status',
                'op' => '=',
                'value' => 'completed',
            ],
        ],
    ],
    'examples' => [
        'Show with completed orders customers',
        'List with completed orders customers',
        'Find all with completed orders customers',
        'How many with completed orders customers are there?',
    ],
]
```

### Example 4: Complex Filtering

**Eloquent Scope:**
```php
public function scopePremium($query)
{
    return $query->where('status', 'active')
                 ->where('subscription_tier', 'premium')
                 ->whereHas('orders', function($q) {
                     $q->where('total', '>', 1000);
                 })
                 ->whereNotNull('email');
}
```

**Generated Cypher:**
```cypher
MATCH (n:Customer)-[:HAS_ORDERS]->(o:Order)
WHERE n.status = 'active'
  AND n.subscription_tier = 'premium'
  AND o.total > 1000
  AND n.email IS NOT NULL
RETURN DISTINCT n
```

## API Reference

### CypherScopeAdapter

#### `discoverScopes(string $modelClass): array`

Discovers all scopes in a model and returns them as entity metadata.

**Parameters:**
- `$modelClass` - Fully qualified model class name

**Returns:**
- Array of scope metadata keyed by scope name

**Example:**
```php
$adapter = new CypherScopeAdapter();
$scopes = $adapter->discoverScopes(Customer::class);
```

#### `parseScope(string $modelClass, string $scopeName, ?ReflectionMethod $method = null): ?array`

Parses a single scope and returns its metadata.

**Parameters:**
- `$modelClass` - Fully qualified model class name
- `$scopeName` - Scope name (without 'scope' prefix, in snake_case)
- `$method` - Optional ReflectionMethod (auto-detected if null)

**Returns:**
- Scope metadata array or null if parsing fails

**Example:**
```php
$scopeData = $adapter->parseScope(Customer::class, 'active');
```

### CypherQueryBuilderSpy

#### `where($column, $operator = null, $value = null, string $boolean = 'and'): self`

Records a where clause.

#### `whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): self`

Records a whereIn clause.

#### `whereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1): self`

Records a relationship existence check.

#### `getCalls(): array`

Returns all recorded method calls.

### CypherPatternGenerator

#### `generate(array $calls, string $nodeVar = 'n'): string`

Generates Cypher pattern from recorded calls.

**Parameters:**
- `$calls` - Array of recorded query builder calls
- `$nodeVar` - Node variable name (default: 'n')

**Returns:**
- Cypher pattern string

**Example:**
```php
$generator = new CypherPatternGenerator();
$pattern = $generator->generate($spy->getCalls());
```

#### `generateFullQuery(array $structure): string`

Generates complete Cypher query from parsed relationship structure.

**Parameters:**
- `$structure` - Parsed relationship structure with entity, relationships, and conditions

**Returns:**
- Complete Cypher query string

## Integration Guide

### Step 1: Add Scopes to Your Models

```php
class Product extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function scopeInStock($query)
    {
        return $query->where('quantity', '>', 0);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeWithReviews($query)
    {
        return $query->whereHas('reviews');
    }
}
```

### Step 2: Auto-Populate Entity Config

```php
// In your setup/migration/seeder

use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;

$adapter = new CypherScopeAdapter();
$models = [Product::class, Customer::class, Order::class];

$entityConfig = [];

foreach ($models as $modelClass) {
    $scopes = $adapter->discoverScopes($modelClass);
    $entityName = class_basename($modelClass);

    $entityConfig[$entityName] = [
        'metadata' => [
            'scopes' => $scopes,
        ],
    ];
}

// Write to config/entities.php
file_put_contents(
    config_path('entities.php'),
    "<?php\n\nreturn " . var_export($entityConfig, true) . ";\n"
);
```

### Step 3: Use in RAG Pipeline

The discovered scopes are automatically available to the RAG system for semantic query generation:

```php
// User asks: "Show me featured products that are in stock"

// RAG system detects:
// - Entity: Product
// - Scopes: featured, in_stock
// - Generates Cypher using combined patterns
```

## Best Practices

### 1. Descriptive Scope Names

✅ **Good:**
```php
public function scopeActive($query) { ... }
public function scopeHighValue($query) { ... }
public function scopeWithRecentOrders($query) { ... }
```

❌ **Bad:**
```php
public function scopeA($query) { ... }
public function scopeFilter1($query) { ... }
public function scopeX($query) { ... }
```

### 2. Keep Scopes Simple

Each scope should represent a single, clear business concept.

✅ **Good:**
```php
public function scopeActive($query)
{
    return $query->where('status', 'active');
}

public function scopePremium($query)
{
    return $query->where('subscription_tier', 'premium');
}
```

❌ **Bad:**
```php
public function scopeComplexFilter($query)
{
    return $query->where('status', 'active')
                 ->orWhere('status', 'pending')
                 ->whereHas('subscriptions', function($q) {
                     $q->where('tier', 'premium')
                       ->orWhere('tier', 'gold')
                       ->whereHas('payments', function($q2) {
                           $q2->where('status', 'paid');
                       });
                 });
}
```

### 3. Document Business Logic

```php
/**
 * Scope: VIP Customers
 *
 * VIP customers are active customers with:
 * - Lifetime value > $5000
 * - At least one completed order in the last 90 days
 */
public function scopeVip($query)
{
    return $query->where('status', 'active')
                 ->where('lifetime_value', '>', 5000)
                 ->whereHas('orders', function($q) {
                     $q->where('status', 'completed')
                       ->whereDate('created_at', '>=', now()->subDays(90));
                 });
}
```

### 4. Use Chainable Scopes

```php
// Instead of one complex scope:
public function scopeActiveVipCustomers($query) { ... }

// Create composable scopes:
public function scopeActive($query) { ... }
public function scopeVip($query) { ... }

// Use:
Customer::active()->vip()->get();
```

## Troubleshooting

### Scope Not Detected

**Problem:** Scope method exists but isn't discovered.

**Solutions:**
1. Ensure method name starts with `scope` (case-sensitive)
2. Method must be public
3. Method must be defined in the model class (not inherited)
4. First parameter must be `$query`

### Incorrect Cypher Pattern

**Problem:** Generated Cypher doesn't match expectations.

**Solutions:**
1. Check that Eloquent methods are supported (see table above)
2. Test scope in isolation with spy:
   ```php
   $spy = new CypherQueryBuilderSpy();
   $model->scopeYourScope($spy);
   dd($spy->getCalls());
   ```
3. Manually override in entity config if needed

### Relationship Names

**Problem:** Relationship names don't match Neo4j labels.

**Default Behavior:**
- `orders` → `HAS_ORDERS`
- `userRoles` → `HAS_USER_ROLES`

**Custom Mapping:**
Override in entity config:
```php
'scopes' => [
    'with_orders' => [
        'cypher_pattern' => "MATCH (n:Customer)-[:PLACED]->(o:Order) ...",
    ],
]
```

## Performance Considerations

1. **Discovery is Cached** - Run discovery once during deployment, not on every request
2. **Reflection is Expensive** - Use caching strategies for production
3. **Complex Scopes** - Break into simpler scopes for better Cypher optimization

## Testing

See `examples/CypherScopeAdapterDemo.php` for a comprehensive demonstration of all features.

Run the demo:
```bash
php examples/CypherScopeAdapterDemo.php
```

Run unit tests:
```bash
./vendor/bin/phpunit tests/Unit/Services/Discovery
```

## Limitations

1. **Closure Scopes** - Nested closures in whereHas are limited to one level
2. **Raw Queries** - `whereRaw()`, `DB::raw()` cannot be converted
3. **Subqueries** - Complex subqueries may not convert correctly
4. **Custom Methods** - Only standard Eloquent methods are supported

## Future Enhancements

- [ ] Support for `whereExists()` and subqueries
- [ ] Custom operator mappings
- [ ] Scope parameter handling
- [ ] Automatic relationship type detection from model methods
- [ ] Integration with Laravel Scout for vector search
- [ ] Visual scope editor/tester

## License

MIT License - See LICENSE file for details

## Contributing

Contributions welcome! Please see CONTRIBUTING.md for guidelines.

## Support

- Documentation: `docs/`
- Examples: `examples/CypherScopeAdapterDemo.php`
- Issues: GitHub Issues
- Discussions: GitHub Discussions
