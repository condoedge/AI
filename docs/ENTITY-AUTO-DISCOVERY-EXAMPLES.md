# Entity Auto-Discovery: Before & After Examples

## Table of Contents

1. [Simple CRUD Entity](#simple-crud-entity)
2. [Entity with Relationships](#entity-with-relationships)
3. [Entity with Scopes](#entity-with-scopes)
4. [Complex Entity with Custom Config](#complex-entity-with-custom-config)
5. [Graph-Only Entity](#graph-only-entity)
6. [File/Document Entity](#filedocument-entity)
7. [Migration Examples](#migration-examples)

---

## Simple CRUD Entity

### Before (Current System)

```php
// app/Models/Customer.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'status',
        'lifetime_value',
    ];

    protected $casts = [
        'lifetime_value' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
```

```php
// config/entities.php
return [
    'Customer' => [
        'graph' => [
            'label' => 'Customer',
            'properties' => [
                'id',
                'name',
                'email',
                'phone',
                'address',
                'city',
                'country',
                'status',
                'lifetime_value',
                'created_at',
                'updated_at',
            ],
            'relationships' => [],
        ],
        'vector' => [
            'collection' => 'customers',
            'embed_fields' => ['name', 'email', 'address'],
            'metadata' => ['id', 'email', 'status', 'country', 'city'],
        ],
        'metadata' => [
            'aliases' => ['customer', 'customers', 'client', 'clients'],
            'description' => 'Customer records in the system',
        ],
    ],
];
```

**Problems:**
- 11 properties duplicated from `$fillable`
- Manual maintenance if fields change
- 58 lines of configuration for simple entity
- Easy to forget updating config when model changes

### After (Auto-Discovery)

```php
// app/Models/Customer.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'status',
        'lifetime_value',
    ];

    protected $casts = [
        'lifetime_value' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
```

**That's it! No config file needed.**

**Auto-discovered:**
- Label: `Customer`
- Properties: `['id', 'name', 'email', 'phone', 'address', 'city', 'country', 'status', 'lifetime_value', 'created_at', 'updated_at']`
- Collection: `customers`
- Embed fields: `['name', 'email', 'address']` (text-like fields)
- Metadata: `['id', 'phone', 'city', 'country', 'status', 'lifetime_value']`
- Aliases: `['customer', 'customers', 'client', 'clients']`

**Benefits:**
- 58 lines → 0 lines of config
- Single source of truth (Eloquent model)
- Automatic updates when model changes

---

## Entity with Relationships

### Before (Current System)

```php
// app/Models/Order.php
class Order extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = [
        'customer_id',
        'order_number',
        'total',
        'status',
        'order_date',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'order_date' => 'date',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
```

```php
// config/entities.php
'Order' => [
    'graph' => [
        'label' => 'Order',
        'properties' => [
            'id',
            'customer_id',
            'order_number',
            'total',
            'status',
            'order_date',
            'created_at',
            'updated_at',
        ],
        'relationships' => [
            [
                'type' => 'PLACED_BY',
                'target_label' => 'Customer',
                'foreign_key' => 'customer_id', // Duplicates belongsTo!
            ],
        ],
    ],
    'vector' => [
        'collection' => 'orders',
        'embed_fields' => ['order_number'],
        'metadata' => ['id', 'customer_id', 'status', 'total', 'order_date'],
    ],
],
```

**Problems:**
- Relationship duplicates `belongsTo` definition
- If you change foreign key, must update 2 places
- If you add relationships, must add to config

### After (Auto-Discovery)

```php
// app/Models/Order.php
class Order extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = [
        'customer_id',
        'order_number',
        'total',
        'status',
        'order_date',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'order_date' => 'date',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // Optional: Customize relationship type
    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->addRelationship(
                type: 'CONTAINS', // Custom relationship for items
                targetLabel: 'OrderItem',
                foreignKey: 'order_id'
            );
    }
}
```

**Auto-discovered:**
- Relationship: `Order -[BELONGS_TO_CUSTOMER]-> Customer` (from `belongsTo()`)
- Foreign key: `customer_id` (from Eloquent relationship)
- Target label: `Customer` (from related model)

**Optional override:**
- Added `CONTAINS` relationship for items
- `hasMany` relationships not auto-discovered (inverse of `belongsTo`)

---

## Entity with Scopes

### Before (Current System)

```php
// app/Models/Customer.php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'status', 'type'];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeVip($query)
    {
        return $query->where('type', 'vip');
    }

    public function scopePremium($query)
    {
        return $query->where('type', 'premium');
    }
}
```

```php
// config/entities.php
'Customer' => [
    // ... graph and vector config ...
    'metadata' => [
        'scopes' => [
            'active' => [
                'description' => 'Active customers',
                'filter' => ['status' => 'active'],
                'cypher_pattern' => "status = 'active'",
                'examples' => [
                    'Show active customers',
                    'List active clients',
                ],
            ],
            'vip' => [
                'description' => 'VIP customers',
                'filter' => ['type' => 'vip'],
                'cypher_pattern' => "type = 'vip'",
                'examples' => [
                    'Show VIP customers',
                    'List VIP clients',
                ],
            ],
            'premium' => [
                'description' => 'Premium customers',
                'filter' => ['type' => 'premium'],
                'cypher_pattern' => "type = 'premium'",
                'examples' => [
                    'Show premium customers',
                    'List premium clients',
                ],
            ],
        ],
    ],
],
```

**Problems:**
- Scopes defined in model AND config
- Cypher pattern duplicates where clause
- Must manually add examples
- Easy to forget updating config when scope changes

### After (Auto-Discovery)

```php
// app/Models/Customer.php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'status', 'type'];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeVip($query)
    {
        return $query->where('type', 'vip');
    }

    public function scopePremium($query)
    {
        return $query->where('type', 'premium');
    }
}
```

**Auto-discovered scopes:**
```php
[
    'active' => [
        'description' => 'Active customers',
        'filter' => ['status' => 'active'],
        'cypher_pattern' => "status = 'active'",
        'examples' => [
            'Show active customers',
            'List active customer',
            'How many active customers?',
            'Find active customer',
        ],
    ],
    'vip' => [
        'description' => 'Vip customers',
        'filter' => ['type' => 'vip'],
        'cypher_pattern' => "type = 'vip'",
        'examples' => [
            'Show vip customers',
            'List vip customer',
            'How many vip customers?',
            'Find vip customer',
        ],
    ],
    'premium' => [
        'description' => 'Premium customers',
        'filter' => ['type' => 'premium'],
        'cypher_pattern' => "type = 'premium'",
        'examples' => [
            'Show premium customers',
            'List premium customer',
            'How many premium customers?',
            'Find premium customer',
        ],
    ],
]
```

**Auto-parsed from scope methods:**
- Simple `where('field', 'value')` clauses automatically converted
- Cypher patterns generated automatically
- Examples generated from scope name + entity name

---

## Complex Entity with Custom Config

### Before (Current System)

```php
// app/Models/Person.php
class Person extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['first_name', 'last_name', 'email', 'type', 'status', 'team_id'];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
```

```php
// config/entities.php - 150+ lines!
'Person' => [
    'graph' => [
        'label' => 'Person',
        'properties' => ['id', 'first_name', 'last_name', 'email', 'type', 'status', 'team_id', 'created_at'],
        'relationships' => [
            [
                'type' => 'BELONGS_TO_TEAM',
                'target_label' => 'Team',
                'foreign_key' => 'team_id',
            ],
            [
                'type' => 'HAS_ROLE',
                'target_label' => 'PersonTeam',
                'foreign_key' => 'person_id',
            ],
        ],
    ],
    'vector' => [
        'collection' => 'people',
        'embed_fields' => ['first_name', 'last_name', 'email'],
        'metadata' => ['id', 'email', 'type', 'status'],
    ],
    'metadata' => [
        'aliases' => ['person', 'people', 'user', 'users', 'member', 'members'],
        'description' => 'Represents people in the system',
        'scopes' => [
            'volunteers' => [
                'specification_type' => 'relationship_traversal',
                'concept' => 'People who volunteer on teams',
                'relationship_spec' => [
                    'start_entity' => 'Person',
                    'path' => [
                        [
                            'relationship' => 'HAS_ROLE',
                            'target_entity' => 'PersonTeam',
                            'direction' => 'outgoing',
                        ],
                    ],
                    'filter' => [
                        'entity' => 'PersonTeam',
                        'property' => 'role_type',
                        'operator' => 'equals',
                        'value' => 'volunteer',
                    ],
                ],
            ],
        ],
    ],
],
```

### After (Auto-Discovery + Selective Overrides)

```php
// app/Models/Person.php
class Person extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['first_name', 'last_name', 'email', 'type', 'status', 'team_id'];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Only override what auto-discovery can't handle
    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this) // Start with auto-discovery
            ->addAlias('member') // Add custom alias
            ->addRelationship( // Add complex relationship
                type: 'HAS_ROLE',
                targetLabel: 'PersonTeam',
                foreignKey: 'person_id'
            )
            ->addScope('volunteers', [ // Add complex scope
                'specification_type' => 'relationship_traversal',
                'concept' => 'People who volunteer on teams',
                'relationship_spec' => [
                    'start_entity' => 'Person',
                    'path' => [
                        [
                            'relationship' => 'HAS_ROLE',
                            'target_entity' => 'PersonTeam',
                            'direction' => 'outgoing',
                        ],
                    ],
                    'filter' => [
                        'entity' => 'PersonTeam',
                        'property' => 'role_type',
                        'operator' => 'equals',
                        'value' => 'volunteer',
                    ],
                ],
            ]);
    }
}
```

**Auto-discovered:**
- All basic properties, relationships, aliases
- Simple scopes (scopeActive)

**Manual overrides:**
- Complex relationship (`HAS_ROLE`)
- Complex scope (`volunteers` with traversal)
- Custom alias (`member`)

**Benefits:**
- 150+ lines → 30 lines
- Only configure what's special
- Auto-discovery handles the boring stuff

---

## Graph-Only Entity

### Before (Current System)

```php
// app/Models/Team.php
class Team extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'description'];
}
```

```php
// config/entities.php
'Team' => [
    'graph' => [
        'label' => 'Team',
        'properties' => ['id', 'name', 'description', 'created_at'],
        'relationships' => [],
    ],
    // No 'vector' key = not searchable
],
```

### After (Auto-Discovery)

```php
// app/Models/Team.php
class Team extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'description'];

    // Optional: Explicitly disable vector store
    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->disableVectorStore();
    }
}
```

**Auto-discovered:**
- Graph config: Yes
- Vector config: No (or explicitly disabled)

---

## File/Document Entity

### Before (Current System)

```php
// app/Models/File.php
class File extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = [
        'name',
        'original_name',
        'size',
        'extension',
        'mime_type',
        'path',
        'disk',
        'user_id',
        'team_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
```

```php
// config/entities.php - 80+ lines
'File' => [
    'graph' => [
        'label' => 'File',
        'properties' => [
            'id',
            'name',
            'original_name',
            'size',
            'extension',
            'mime_type',
            'path',
            'disk',
            'user_id',
            'team_id',
            'created_at',
        ],
        'relationships' => [
            [
                'type' => 'UPLOADED_BY',
                'target_label' => 'User',
                'foreign_key' => 'user_id',
            ],
            [
                'type' => 'BELONGS_TO_TEAM',
                'target_label' => 'Team',
                'foreign_key' => 'team_id',
            ],
        ],
    ],
    'metadata' => [
        'aliases' => ['file', 'files', 'document', 'documents', 'attachment'],
        'scopes' => [
            'documents' => [
                'filter' => ['extension' => ['pdf', 'docx', 'txt']],
            ],
            'images' => [
                'filter' => ['mime_type' => 'starts_with:image/'],
            ],
        ],
    ],
],
```

### After (Auto-Discovery)

```php
// app/Models/File.php
class File extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = [
        'name',
        'original_name',
        'size',
        'extension',
        'mime_type',
        'path',
        'disk',
        'user_id',
        'team_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeDocuments($query)
    {
        return $query->whereIn('extension', ['pdf', 'docx', 'txt', 'md']);
    }

    public function scopeImages($query)
    {
        return $query->where('mime_type', 'like', 'image/%');
    }

    // Optional: Customize relationship names
    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->addAlias('document')
            ->addAlias('attachment');
    }
}
```

**Auto-discovered:**
- Properties: All from `$fillable`
- Relationships: `BELONGS_TO_USER`, `BELONGS_TO_TEAM` (from `belongsTo()`)
- Scopes: `documents`, `images` (from `scope*()` methods)
- Aliases: `file`, `files` (from table name)

**Manual overrides:**
- Additional aliases: `document`, `attachment`

---

## Migration Examples

### Example 1: Simple Entity Migration

**Step 1: Review current config**
```bash
php artisan ai:discover:compare Customer
```

Output:
```
Comparing Customer configuration:

Config File                 Auto-Discovered            Status
-------------------------   ------------------------   -------
Label: Customer             Customer                   ✓ Match
Properties: 11              11                         ✓ Match
Relationships: 0            0                          ✓ Match
Embed Fields: 3             3                          ✓ Match
Aliases: 4                  4                          ✓ Match

✓ Auto-discovery matches config file perfectly!
Safe to remove from config/entities.php
```

**Step 2: Remove from config file**
```php
// config/entities.php
return [
    // Remove 'Customer' => [...],
];
```

**Step 3: Test**
```bash
php artisan tinker
>>> $customer = Customer::first();
>>> $customer->getGraphConfig();
=> GraphConfig { label: "Customer", properties: [...] }
```

Done! Zero code changes to model.

---

### Example 2: Entity with Differences

**Step 1: Review differences**
```bash
php artisan ai:discover:compare Order
```

Output:
```
Comparing Order configuration:

Config File                 Auto-Discovered            Difference
-------------------------   ------------------------   ----------
Relationships: 1            1                          ⚠ Differ
  Config: PLACED_BY
  Discovered: BELONGS_TO_CUSTOMER

Recommendation: Override relationship type in nodeableConfig()
```

**Step 2: Add override for custom relationship name**
```php
class Order extends Model implements Nodeable
{
    // ... existing code ...

    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->addRelationship(
                type: 'PLACED_BY', // Keep original name
                targetLabel: 'Customer',
                foreignKey: 'customer_id'
            );
    }
}
```

**Step 3: Remove from config file**
```php
// config/entities.php
return [
    // Remove 'Order' => [...],
];
```

---

### Example 3: Complex Entity Migration

**Step 1: Review config**
```bash
php artisan ai:discover:compare Person
```

Output:
```
Complex entity with 3 custom scopes and 2 relationship traversals.
Auto-discovery can handle 70% of the config.

Recommendation: Migrate in steps:
1. Move basic config to auto-discovery
2. Override only complex scopes
3. Test incrementally
```

**Step 2: Migrate incrementally**

```php
// app/Models/Person.php

// Step 1: Add basic auto-discovery (comment out config file)
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this);
}

// Test: php artisan tinker
// >>> Person::first()->getGraphConfig()
// Verify basic config works

// Step 2: Add custom aliases
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addAlias('member')
        ->addAlias('individual');
}

// Test again

// Step 3: Add complex relationships
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addAlias('member')
        ->addRelationship(
            type: 'HAS_ROLE',
            targetLabel: 'PersonTeam',
            foreignKey: 'person_id'
        );
}

// Test again

// Step 4: Add complex scopes (copy from config file)
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addAlias('member')
        ->addRelationship(...)
        ->addScope('volunteers', [...]); // Copy from config
}

// Final test
```

**Step 3: Remove from config file once all tests pass**

---

### Example 4: Gradual Team Migration

**Strategy:** Migrate entities one at a time, test each one.

**Week 1: Simple entities**
- Customer ✓
- Team ✓
- Category ✓

**Week 2: Entities with relationships**
- Order ✓ (requires relationship override)
- Product ✓
- OrderItem ✓

**Week 3: Complex entities**
- Person ✓ (requires custom scopes)
- File ✓

**Week 4: Remove config file**
- All entities migrated
- Delete `config/entities.php` (or keep for reference)
- Update documentation

---

## Testing Auto-Discovery

### Test 1: Preview Config

```bash
php artisan ai:discover Customer
```

### Test 2: Compare with Existing

```bash
php artisan ai:discover:compare Customer
```

### Test 3: Manual Test

```php
// tests/Feature/AutoDiscoveryTest.php
public function test_customer_auto_discovery()
{
    $customer = Customer::factory()->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    $graphConfig = $customer->getGraphConfig();

    $this->assertEquals('Customer', $graphConfig->label);
    $this->assertContains('name', $graphConfig->properties);
    $this->assertContains('email', $graphConfig->properties);

    $vectorConfig = $customer->getVectorConfig();

    $this->assertEquals('customers', $vectorConfig->collection);
    $this->assertContains('name', $vectorConfig->embedFields);
}
```

---

## Common Patterns

### Pattern 1: Override Embed Fields

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->embedFields(['name', 'description', 'custom_field']);
}
```

### Pattern 2: Add Custom Relationship Type

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addRelationship(
            type: 'PURCHASED', // Custom type
            targetLabel: 'Product',
            foreignKey: 'product_id'
        );
}
```

### Pattern 3: Disable Vector Store

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->disableVectorStore(); // Graph only
}
```

### Pattern 4: Add Custom Aliases

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addAlias('subscriber')
        ->addAlias('member');
}
```

### Pattern 5: Mix Auto-Discovery with Custom Scopes

```php
public function scopeActive($query)
{
    return $query->where('status', 'active');
}

public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        // scopeActive is auto-discovered
        ->addScope('high_value', [ // Add complex scope manually
            'cypher_pattern' => 'lifetime_value > 10000',
            'examples' => ['Show high value customers'],
        ]);
}
```

---

## Tips & Best Practices

### Tip 1: Start Simple

Begin with auto-discovery, add overrides only when needed:

```php
// Start with this
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this);
}

// Add overrides incrementally
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addAlias('custom_alias'); // Only add when needed
}
```

### Tip 2: Use Scopes for Business Logic

Define scopes in your model, they'll be auto-discovered:

```php
public function scopeActive($query)
{
    return $query->where('status', 'active');
}

// Automatically becomes a semantic scope!
```

### Tip 3: Leverage $fillable and $casts

Auto-discovery uses these, so keep them updated:

```php
protected $fillable = ['name', 'email', 'description']; // Auto-discovered
protected $casts = ['description' => 'text']; // Marked as embed field
```

### Tip 4: Preview Before Migrating

Always preview auto-discovered config:

```bash
php artisan ai:discover Customer
```

### Tip 5: Test After Migration

Write tests to verify auto-discovery works:

```php
public function test_customer_config()
{
    $customer = new Customer();
    $config = $customer->getGraphConfig();

    $this->assertEquals('Customer', $config->label);
    // Assert expected properties, relationships, etc.
}
```

---

## Troubleshooting

### Issue: Missing Properties

**Problem:** Some properties not auto-discovered

**Solution:** Add to `$fillable` or `$casts`:

```php
protected $fillable = ['name', 'email', 'custom_field']; // Add custom_field
```

### Issue: Wrong Embed Fields

**Problem:** Auto-discovery chose wrong fields for embedding

**Solution:** Override embed fields:

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->embedFields(['name', 'description']); // Explicit list
}
```

### Issue: Relationship Not Discovered

**Problem:** Relationship not appearing in graph config

**Solution:** Check relationship type (only `belongsTo` auto-discovered):

```php
// This is auto-discovered
public function customer()
{
    return $this->belongsTo(Customer::class);
}

// This is NOT auto-discovered (inverse)
public function orders()
{
    return $this->hasMany(Order::class);
}

// Manually add if needed
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addRelationship(
            type: 'HAS_ORDER',
            targetLabel: 'Order',
            foreignKey: 'customer_id'
        );
}
```

### Issue: Scope Not Working

**Problem:** Eloquent scope not converting to semantic scope

**Solution:** Check scope is parseable (simple where clause):

```php
// This works (simple where)
public function scopeActive($query)
{
    return $query->where('status', 'active');
}

// This doesn't (complex logic)
public function scopeComplex($query)
{
    return $query->whereHas('orders', function($q) {
        $q->where('total', '>', 1000);
    });
}

// Manually add complex scope
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addScope('complex', [
            'description' => 'Complex filtering',
            'cypher_pattern' => '...',
        ]);
}
```

---

## Summary

Auto-discovery eliminates 80% of configuration:

- **Zero config** for simple CRUD entities
- **Minimal overrides** for custom needs
- **Single source of truth** (Eloquent model)
- **Automatic updates** when model changes
- **Gradual migration** from existing config

Use `nodeableConfig()` only when you need to:
- Customize relationship types
- Add complex scopes
- Override embed fields
- Add custom aliases
- Disable features

Otherwise, just use the trait and let auto-discovery do the work!
