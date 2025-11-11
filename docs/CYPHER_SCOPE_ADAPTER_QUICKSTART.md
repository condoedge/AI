# CypherScope Adapter - Quick Start Guide

## 5-Minute Setup

### Step 1: Add Scopes to Your Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    // Your existing model code...

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
     * Scope: Customers with orders
     */
    public function scopeWithOrders($query)
    {
        return $query->whereHas('orders', function($q) {
            $q->where('status', 'completed');
        });
    }
}
```

### Step 2: Discover Scopes

```php
use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;

$adapter = new CypherScopeAdapter();
$scopes = $adapter->discoverScopes(Customer::class);

// Result:
[
    'active' => [
        'specification_type' => 'property_filter',
        'concept' => 'Customers with Active status',
        'cypher_pattern' => "n.status = 'active'",
        'filter' => ['status' => 'active'],
        'examples' => ['Show active customers', 'List active customers', ...],
    ],
    'high_value' => [...],
    'with_orders' => [...],
]
```

### Step 3: Add to Entity Config

```php
// config/entities.php

return [
    'Customer' => [
        'graph' => [
            'label' => 'Customer',
            'properties' => ['id', 'name', 'email', 'status', 'lifetime_value'],
        ],
        'metadata' => [
            'aliases' => ['customer', 'customers', 'client'],
            'scopes' => $adapter->discoverScopes(Customer::class), // ← Auto-generated!
        ],
    ],
];
```

### Step 4: Use in Queries

Now when users ask:
- "Show me active customers" → Uses `active` scope
- "Find high-value customers" → Uses `high_value` scope
- "List customers with orders" → Uses `with_orders` scope

The RAG system automatically converts these to Cypher!

## Common Patterns

### Pattern 1: Simple Filter
```php
public function scopeActive($query)
{
    return $query->where('status', 'active');
}
// → n.status = 'active'
```

### Pattern 2: Comparison
```php
public function scopeExpensive($query)
{
    return $query->where('price', '>', 1000);
}
// → n.price > 1000
```

### Pattern 3: Multiple Conditions
```php
public function scopeVip($query)
{
    return $query->where('status', 'active')
                 ->where('lifetime_value', '>=', 5000);
}
// → n.status = 'active' AND n.lifetime_value >= 5000
```

### Pattern 4: IN Clause
```php
public function scopeInRegions($query, array $regions)
{
    return $query->whereIn('region', $regions);
}
// → n.region IN ['US', 'CA', 'UK']
```

### Pattern 5: Null Check
```php
public function scopeWithEmail($query)
{
    return $query->whereNotNull('email');
}
// → n.email IS NOT NULL
```

### Pattern 6: Relationship
```php
public function scopeWithOrders($query)
{
    return $query->whereHas('orders');
}
// → MATCH (n:Customer)-[:HAS_ORDERS]->(o:Order) RETURN DISTINCT n
```

### Pattern 7: Relationship with Condition
```php
public function scopeWithCompletedOrders($query)
{
    return $query->whereHas('orders', function($q) {
        $q->where('status', 'completed');
    });
}
// → MATCH (n:Customer)-[:HAS_ORDERS]->(o:Order) WHERE o.status = 'completed' RETURN DISTINCT n
```

## Supported Methods

✅ `where()`
✅ `orWhere()`
✅ `whereIn()` / `whereNotIn()`
✅ `whereNull()` / `whereNotNull()`
✅ `whereHas()` / `whereDoesntHave()`
✅ `whereDate()` / `whereTime()`
✅ `whereBetween()` / `whereNotBetween()`
✅ `whereColumn()`

## Run the Demo

See it in action:
```bash
php examples/CypherScopeAdapterDemo.php
```

## Full Documentation

- **Complete Guide:** `docs/CYPHER_SCOPE_ADAPTER.md`
- **Implementation Details:** `docs/CYPHER_SCOPE_ADAPTER_IMPLEMENTATION.md`
- **API Reference:** `src/Services/Discovery/README.md`

## Troubleshooting

### Scope Not Discovered?
1. Make sure method name starts with `scope` (e.g., `scopeActive`)
2. Method must be `public`
3. First parameter must be `$query`

### Wrong Cypher Generated?
1. Check if the Eloquent method is supported (see list above)
2. Test with the spy manually:
   ```php
   $spy = new CypherQueryBuilderSpy();
   $model->scopeYourScope($spy);
   dd($spy->getCalls());
   ```

### Need Custom Cypher?
Override in entity config:
```php
'scopes' => [
    'custom' => [
        'cypher_pattern' => 'YOUR CUSTOM CYPHER HERE',
    ],
]
```

## Next Steps

1. ✅ Add scopes to your models
2. ✅ Run discovery
3. ✅ Add to entity config
4. ✅ Test with RAG system
5. ✅ Monitor and refine

That's it! You're now using automatic Eloquent→Cypher translation.
