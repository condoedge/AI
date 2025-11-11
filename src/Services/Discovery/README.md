# Discovery Services

The Discovery namespace contains services for automatically detecting and converting Eloquent patterns to Cypher patterns for the RAG system.

## Components

### CypherScopeAdapter

Main orchestrator that discovers Eloquent scopes in models and converts them to Cypher patterns.

**Key Methods:**
- `discoverScopes(string $modelClass): array` - Discover all scopes in a model
- `parseScope(string $modelClass, string $scopeName): array` - Parse a specific scope

**Usage:**
```php
$adapter = new CypherScopeAdapter();
$scopes = $adapter->discoverScopes(Customer::class);
```

### CypherQueryBuilderSpy

A spy implementation of Laravel's query builder that records method calls instead of executing SQL.

**Key Methods:**
- `where()`, `whereIn()`, `whereNull()`, etc. - All standard query builder methods
- `getCalls(): array` - Retrieve all recorded calls
- `hasCalls(): bool` - Check if any calls were recorded

**Usage:**
```php
$spy = new CypherQueryBuilderSpy();
$spy->where('status', 'active')->whereIn('country', ['US', 'CA']);
$calls = $spy->getCalls();
```

### CypherPatternGenerator

Converts recorded query builder calls into Neo4j Cypher patterns.

**Key Methods:**
- `generate(array $calls, string $nodeVar = 'n'): string` - Generate Cypher pattern
- `generateFullQuery(array $structure): string` - Generate complete query from structure

**Usage:**
```php
$generator = new CypherPatternGenerator();
$pattern = $generator->generate($spy->getCalls());
// Returns: "n.status = 'active' AND n.country IN ['US', 'CA']"
```

## Architecture

```
┌─────────────────────┐
│   Eloquent Model    │
│  with Scopes        │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│ CypherScopeAdapter  │
│  - Discovers scopes │
│  - Executes with spy│
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  QueryBuilderSpy    │
│  - Records calls    │
│  - Captures intent  │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│ PatternGenerator    │
│  - Converts to      │
│    Cypher syntax    │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│   Entity Config     │
│  config/entities.php│
└─────────────────────┘
```

## Supported Eloquent Methods

### Basic Operations
- `where($column, $operator, $value)`
- `orWhere($column, $operator, $value)`
- `whereIn($column, array $values)`
- `whereNotIn($column, array $values)`
- `whereNull($column)`
- `whereNotNull($column)`

### Advanced Operations
- `whereDate($column, $operator, $value)`
- `whereTime($column, $operator, $value)`
- `whereBetween($column, array $values)`
- `whereNotBetween($column, array $values)`
- `whereColumn($first, $operator, $second)`

### Relationships
- `whereHas($relation, ?Closure $callback)`
- `whereDoesntHave($relation, ?Closure $callback)`

## Examples

### Example 1: Simple Scope Discovery

```php
class Customer extends Model
{
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}

$adapter = new CypherScopeAdapter();
$scopes = $adapter->discoverScopes(Customer::class);

// Result:
[
    'active' => [
        'specification_type' => 'property_filter',
        'cypher_pattern' => "n.status = 'active'",
        'filter' => ['status' => 'active'],
        'examples' => ['Show active customers', ...],
    ]
]
```

### Example 2: Relationship Scope

```php
class Customer extends Model
{
    public function scopeWithOrders($query)
    {
        return $query->whereHas('orders', function($q) {
            $q->where('status', 'completed');
        });
    }
}

$adapter = new CypherScopeAdapter();
$scopeData = $adapter->parseScope(Customer::class, 'with_orders');

// Result:
[
    'specification_type' => 'relationship_traversal',
    'cypher_pattern' => "MATCH (n:Customer)-[:HAS_ORDERS]->(o:Order) WHERE o.status = 'completed' RETURN DISTINCT n",
    'parsed_structure' => [
        'entity' => 'Customer',
        'relationships' => [...],
        'conditions' => [...],
    ],
]
```

### Example 3: Complex Filtering

```php
class Customer extends Model
{
    public function scopeVip($query)
    {
        return $query->where('status', 'active')
                     ->where('lifetime_value', '>=', 5000)
                     ->whereNotNull('email');
    }
}

$scopeData = $adapter->parseScope(Customer::class, 'vip');

// Generates:
// "n.status = 'active' AND n.lifetime_value >= 5000 AND n.email IS NOT NULL"
```

## Testing

### Unit Tests

Run all discovery tests:
```bash
./vendor/bin/phpunit tests/Unit/Services/Discovery
```

Run specific test file:
```bash
./vendor/bin/phpunit tests/Unit/Services/Discovery/CypherScopeAdapterTest.php
```

### Demo Script

Run the comprehensive demo:
```bash
php examples/CypherScopeAdapterDemo.php
```

### Manual Testing

```php
require 'vendor/autoload.php';

use Condoedge\Ai\Services\Discovery\CypherQueryBuilderSpy;
use Condoedge\Ai\Services\Discovery\CypherPatternGenerator;

// Test spy
$spy = new CypherQueryBuilderSpy();
$spy->where('status', 'active')
    ->where('total', '>', 100);

// Test generator
$generator = new CypherPatternGenerator();
$pattern = $generator->generate($spy->getCalls());

echo "Pattern: " . $pattern . "\n";
// Output: n.status = 'active' AND n.total > 100
```

## Integration

### With Entity Configuration

```php
// In your bootstrap/service provider:

use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;

$adapter = new CypherScopeAdapter();

// Discover scopes for all models
$models = [Customer::class, Order::class, Product::class];
$config = [];

foreach ($models as $model) {
    $scopes = $adapter->discoverScopes($model);
    $entityName = class_basename($model);

    $config[$entityName] = [
        'metadata' => [
            'scopes' => $scopes,
        ],
    ];
}

// Merge with existing entity config
config(['entities' => array_merge(config('entities', []), $config)]);
```

### With RAG Pipeline

The discovered scopes automatically integrate with the RAG system:

1. User asks: "Show me active customers"
2. RAG detects entity: Customer
3. RAG detects scope: active
4. Retrieves Cypher pattern: `n.status = 'active'`
5. Generates final query using pattern

## Performance Tips

1. **Cache Discovery Results** - Discovery uses reflection which can be slow
2. **Run at Build Time** - Generate configs during deployment, not runtime
3. **Selective Discovery** - Only discover scopes for models that need it
4. **Use Simple Scopes** - Complex scopes may generate suboptimal Cypher

## Limitations

- Nested closures beyond one level not supported
- Raw SQL methods (`whereRaw`, etc.) cannot be converted
- Scope parameters with complex logic may not work
- Assumes standard Eloquent relationship naming conventions

## Documentation

- Full Documentation: `docs/CYPHER_SCOPE_ADAPTER.md`
- Examples: `examples/CypherScopeAdapterDemo.php`
- Tests: `tests/Unit/Services/Discovery/`

## Future Improvements

- [ ] Scope parameter support
- [ ] Custom relationship type mapping
- [ ] Support for more Eloquent methods
- [ ] Automatic relationship detection
- [ ] Visual scope builder
- [ ] Performance profiling tools

## Contributing

When adding new query builder methods:

1. Add method to `CypherQueryBuilderSpy`
2. Add generation logic to `CypherPatternGenerator`
3. Add operator mapping if needed
4. Write unit tests
5. Update documentation

## License

MIT License - See LICENSE file for details
