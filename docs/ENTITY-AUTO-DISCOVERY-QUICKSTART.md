# Entity Auto-Discovery - Quick Start Guide

## 5-Minute Setup

### Step 1: Add Trait (2 minutes)

```php
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'status'];
}
```

**That's it!** Everything else is auto-discovered.

### Step 2: Preview Config (1 minute)

```bash
php artisan ai:discover Customer
```

### Step 3: Test Ingestion (2 minutes)

```php
$customer = Customer::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'status' => 'active',
]);

// Auto-synced to Neo4j + Qdrant!
```

---

## What Gets Auto-Discovered?

| Feature | Source | Example |
|---------|--------|---------|
| **Label** | Class name | `Customer` |
| **Properties** | `$fillable`, `$casts` | `['id', 'name', 'email', ...]` |
| **Relationships** | `belongsTo()` methods | `BELONGS_TO_TEAM` |
| **Collection** | `$table` | `customers` |
| **Embed Fields** | Text-like fields | `['name', 'email']` |
| **Metadata** | All other fields | `['id', 'status', 'created_at']` |
| **Aliases** | Table name | `['customer', 'customers', 'client']` |
| **Scopes** | `scopeX()` methods | `active`, `vip` |

---

## Common Patterns

### Pattern 1: Zero Config (Most Common)

```php
class Product extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'price', 'description'];
}

// Auto-discovered:
// - Label: "Product"
// - Properties: ['id', 'name', 'price', 'description', 'created_at', 'updated_at']
// - Collection: "products"
// - Embed fields: ['name', 'description']
```

### Pattern 2: With Relationships

```php
class Order extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['customer_id', 'total', 'status'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}

// Auto-discovered:
// - Relationship: Order -[BELONGS_TO_CUSTOMER]-> Customer
```

### Pattern 3: With Scopes

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'status'];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeVip($query)
    {
        return $query->where('type', 'vip');
    }
}

// Auto-discovered scopes:
// - active: status = 'active'
// - vip: type = 'vip'
```

### Pattern 4: With Overrides

```php
class Person extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['first_name', 'last_name', 'email'];

    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->addAlias('member')
            ->embedFields(['first_name', 'last_name', 'email']); // Override
    }
}
```

---

## CLI Commands Cheat Sheet

```bash
# Preview auto-discovered config
php artisan ai:discover Customer

# Compare with config file
php artisan ai:discover:compare Customer

# Cache discovery results (all entities)
php artisan ai:discover:cache

# Cache single entity
php artisan ai:discover:cache Customer

# Clear cache (all entities)
php artisan ai:discover:clear

# Clear cache for single entity
php artisan ai:discover:clear Customer
```

---

## Override Examples

### Override Embed Fields

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->embedFields(['name', 'bio', 'notes']);
}
```

### Add Custom Alias

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addAlias('subscriber')
        ->addAlias('member');
}
```

### Add Custom Relationship

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addRelationship(
            type: 'HAS_ROLE',
            targetLabel: 'Role',
            foreignKey: 'role_id'
        );
}
```

### Disable Vector Store

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->disableVectorStore(); // Graph only
}
```

### Add Complex Scope

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addScope('high_value', [
            'description' => 'High value customers',
            'cypher_pattern' => 'lifetime_value > 10000',
            'examples' => ['Show high value customers'],
        ]);
}
```

---

## Troubleshooting

### Issue: Missing Property

**Symptom:** Property not appearing in Neo4j

**Solution:** Add to `$fillable`:
```php
protected $fillable = ['name', 'email', 'missing_field'];
```

### Issue: Wrong Embed Fields

**Symptom:** Incorrect fields being embedded

**Solution:** Override embed fields:
```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->embedFields(['name', 'description']);
}
```

### Issue: Relationship Not Discovered

**Symptom:** Relationship missing from graph

**Solution:** Check relationship type (only `belongsTo` auto-discovered):
```php
// This works
public function customer() {
    return $this->belongsTo(Customer::class);
}

// This doesn't (add manually if needed)
public function orders() {
    return $this->hasMany(Order::class);
}
```

### Issue: Scope Not Parsed

**Symptom:** Scope present but no filter/cypher

**Solution:** Ensure simple `where()` clause or add manually:
```php
// Simple (auto-parsed)
public function scopeActive($query) {
    return $query->where('status', 'active');
}

// Complex (add manually via nodeableConfig)
public function scopeComplex($query) {
    return $query->whereHas('orders', ...);
}
```

---

## Configuration

### Enable/Disable Auto-Discovery

```php
// config/ai.php
'discovery' => [
    'enabled' => env('AI_DISCOVERY_ENABLED', true),
],

// .env
AI_DISCOVERY_ENABLED=true
```

### Adjust Cache Settings

```php
// config/ai.php
'discovery' => [
    'cache' => [
        'enabled' => true,
        'ttl' => 3600, // 1 hour
        'driver' => 'file',
    ],
],
```

### Customize Embed Field Patterns

```php
// config/ai.php
'discovery' => [
    'embed_fields' => [
        'patterns' => [
            'name', 'title', 'description', 'notes',
            'custom_field', // Add your patterns
        ],
    ],
],
```

---

## Migration from Config File

### Step 1: Preview Difference

```bash
php artisan ai:discover:compare Customer
```

### Step 2: Remove from Config (if matching)

```php
// config/entities.php
return [
    // Remove 'Customer' entry
];
```

### Step 3: Add Overrides (if different)

```php
// Only if auto-discovery differs from config
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addAlias('custom_alias'); // Only override what's different
}
```

### Step 4: Test

```bash
php artisan test
php artisan tinker
>>> Customer::first()->getGraphConfig()
```

---

## Testing Auto-Discovery

### Unit Test Example

```php
public function test_customer_auto_discovery()
{
    $customer = new Customer();

    $graphConfig = $customer->getGraphConfig();
    $this->assertEquals('Customer', $graphConfig->label);
    $this->assertContains('name', $graphConfig->properties);
    $this->assertContains('email', $graphConfig->properties);

    $vectorConfig = $customer->getVectorConfig();
    $this->assertEquals('customers', $vectorConfig->collection);
    $this->assertContains('name', $vectorConfig->embedFields);
}
```

### Integration Test Example

```php
public function test_customer_ingestion_with_auto_discovery()
{
    $customer = Customer::factory()->create([
        'name' => 'Test Customer',
        'email' => 'test@example.com',
    ]);

    // Should auto-sync to Neo4j + Qdrant
    $this->assertDatabaseHas('test_customers', [
        'id' => $customer->id,
    ]);

    // Verify in Neo4j
    $node = app(GraphStoreInterface::class)->findNode('Customer', $customer->id);
    $this->assertNotNull($node);
    $this->assertEquals('Test Customer', $node['name']);
}
```

---

## Best Practices

### 1. Keep $fillable Updated

Auto-discovery relies on `$fillable`, so keep it current:

```php
// Good
protected $fillable = ['name', 'email', 'status', 'new_field'];

// Bad (outdated)
protected $fillable = ['name', 'email']; // Missing status, new_field
```

### 2. Use Descriptive Scope Names

Scope names become semantic terms:

```php
// Good
public function scopeActive($query) { ... }
public function scopePremium($query) { ... }

// Less clear
public function scopeA($query) { ... }
public function scopeX($query) { ... }
```

### 3. Leverage Eloquent Scopes

Define scopes in model, they'll auto-discover:

```php
// Instead of defining in config...
public function scopeActive($query) {
    return $query->where('status', 'active');
}

// ...let auto-discovery handle it
```

### 4. Use nodeableConfig() Sparingly

Only override when necessary:

```php
// Good (only override what's needed)
public function nodeableConfig(): NodeableConfig {
    return NodeableConfig::discover($this)
        ->addAlias('custom'); // Only this is different
}

// Overkill (don't override everything)
public function nodeableConfig(): NodeableConfig {
    return NodeableConfig::blank()
        ->label('Customer') // Same as auto-discovered
        ->properties([...]); // Same as auto-discovered
}
```

### 5. Preview Before Deploying

Always preview auto-discovered config:

```bash
php artisan ai:discover Customer
```

### 6. Warm Cache on Deployment

Add to deployment script:

```bash
php artisan ai:discover:cache
```

---

## Performance Tips

### 1. Enable Caching

```php
// config/ai.php
'discovery' => [
    'cache' => ['enabled' => true, 'ttl' => 3600],
],
```

### 2. Warm Cache During Deployment

```bash
php artisan ai:discover:cache
```

### 3. Use Cache Tags (if supported)

```php
// config/ai.php
'discovery' => [
    'cache' => ['driver' => 'redis'], // Supports tags
],
```

---

## Common Gotchas

### Gotcha 1: HasMany Not Auto-Discovered

```php
// This is NOT auto-discovered
public function orders() {
    return $this->hasMany(Order::class);
}

// Why? It's the inverse of belongsTo
// The relationship is discovered from Order->customer()
```

### Gotcha 2: Complex Scopes Need Manual Config

```php
// This is NOT auto-parsed
public function scopeHighValue($query) {
    return $query->where('total', '>', 1000)
                 ->whereHas('orders', ...);
}

// Solution: Add manually via nodeableConfig()
```

### Gotcha 3: $hidden Properties Excluded

```php
protected $hidden = ['password', 'secret'];

// 'password' and 'secret' will NOT be in properties
// This is intentional for security
```

---

## Next Steps

1. **Add trait** to your models
2. **Preview config** with `ai:discover`
3. **Test ingestion** with sample data
4. **Add overrides** only if needed
5. **Deploy** with cache warming

Happy auto-discovering!

---

## Quick Reference Card

```
┌─────────────────────────────────────────────────────────────┐
│                 ENTITY AUTO-DISCOVERY                       │
├─────────────────────────────────────────────────────────────┤
│ SETUP                                                       │
│   class Model implements Nodeable {                         │
│       use HasNodeableConfig;                                │
│   }                                                          │
│                                                             │
│ DISCOVER                                                    │
│   php artisan ai:discover Model                            │
│                                                             │
│ OVERRIDE                                                    │
│   public function nodeableConfig() {                        │
│       return NodeableConfig::discover($this)                │
│           ->addAlias('custom');                             │
│   }                                                          │
│                                                             │
│ COMMANDS                                                    │
│   ai:discover Model        Preview config                  │
│   ai:discover:compare      Compare with file               │
│   ai:discover:cache        Warm cache                      │
│   ai:discover:clear        Clear cache                     │
│                                                             │
│ AUTO-DISCOVERED FROM                                        │
│   $fillable        → Properties                             │
│   $casts           → Properties + Embed fields              │
│   $table           → Collection + Aliases                   │
│   belongsTo()      → Relationships                          │
│   scopeX()         → Semantic scopes                        │
│                                                             │
│ OVERRIDE METHODS                                            │
│   ->embedFields([...])         Set embed fields            │
│   ->addAlias(...)              Add alias                   │
│   ->addRelationship(...)       Add relationship            │
│   ->addScope(...)              Add scope                   │
│   ->disableVectorStore()       Disable Qdrant              │
└─────────────────────────────────────────────────────────────┘
```

---

Print this card and keep it at your desk for quick reference!
