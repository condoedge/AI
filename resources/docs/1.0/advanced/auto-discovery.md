# Auto-Discovery

Automatically generate entity configurations from your Eloquent models.

---

## Overview

Auto-discovery analyzes your Laravel models and generates configuration for:

- **Neo4j**: Node labels, properties, relationships
- **Qdrant**: Collections, embed fields, metadata
- **Metadata**: Aliases, scopes, descriptions

This saves time and ensures configurations stay in sync with your models.

---

## Quick Start

### 1. Make Models Nodeable

```php
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;
}
```

### 2. Run Discovery

```bash
php artisan ai:discover
```

### 3. Review Generated Config

```php
// config/entities.php (generated)
return [
    'App\\Models\\Customer' => [
        'graph' => [
            'label' => 'Customer',
            'properties' => ['id', 'name', 'email', 'status'],
            'relationships' => [...],
        ],
        'vector' => [
            'collection' => 'customers',
            'embed_fields' => ['name', 'email'],
            'metadata' => ['id', 'name', 'status'],
        ],
        'metadata' => [
            'aliases' => ['customer', 'client'],
            'scopes' => [...],
        ],
    ],
];
```

---

## Discovery Command

```bash
# Discover all Nodeable models
php artisan ai:discover

# Preview without writing
php artisan ai:discover --dry-run

# Discover specific model
php artisan ai:discover --model="App\Models\Customer"

# Force overwrite existing config
php artisan ai:discover --force

# Verbose output
php artisan ai:discover -v
```

### Command Output

```
🔍 Discovering Nodeable entities...

Found 5 Nodeable model(s)

Discovering: App\Models\Customer
  ✓ Label: Customer
  ✓ Properties: 8 discovered
  ✓ Relationships: 2 discovered
  ✓ Scopes: 3 discovered
  ✓ Aliases: 4 generated

Discovering: App\Models\Order
  ✓ Label: Order
  ✓ Properties: 6 discovered
  ✓ Relationships: 3 discovered

✓ Configuration written to config/entities.php
✓ Discovered 5 entities
```

---

## What Gets Discovered

### Properties

Discovered from:
- `$fillable` array
- Database table columns
- Model casts

**Excluded automatically:**
- `password`, `remember_token`, `api_token`
- Fields ending in `_token`, `_secret`
- Custom exclusions in config

```php
// config/ai.php
'discovery' => [
    'exclude_properties' => [
        'internal_notes',
        'admin_flag',
    ],
],
```

### Relationships

Discovered from Eloquent relationship methods:

| Eloquent Method | Generated Relationship |
|-----------------|----------------------|
| `hasMany()` | `HAS_*` outgoing |
| `hasOne()` | `HAS_*` outgoing |
| `belongsTo()` | `BELONGS_TO` outgoing |
| `belongsToMany()` | `*` both directions |

**Bidirectional discovery:**

Relationships are discovered in both directions:

```php
// Customer hasMany Order
// Generates:
// - Customer -[HAS_ORDER]-> Order
// - Order -[PLACED_BY]-> Customer (inverse)
```

### Scopes

Discovered from Laravel query scopes:

```php
// In your model
public function scopeActive($query) {
    return $query->where('status', 'active');
}

public function scopePremium($query) {
    return $query->whereIn('tier', ['gold', 'platinum']);
}
```

**Generated:**

```php
'scopes' => [
    'active' => [
        'name' => 'active',
        'cypher_pattern' => "n.status = 'active'",
        'description' => 'Filter active customers',
    ],
    'premium' => [
        'name' => 'premium',
        'cypher_pattern' => "n.tier IN ['gold', 'platinum']",
        'description' => 'Filter premium customers',
    ],
],
```

### Nested Scope Discovery

Complex scopes with `whereHas` and nested scope calls are automatically resolved:

```php
// In Person model
public function scopeHasVolunteerTeamOccupation($query)
{
    return $query->whereHas('personTeams', fn ($q) => $q->volunteer());
}

// In PersonTeam model
public function scopeVolunteer($query)
{
    return $query->where('role_type', 3);
}
```

**Auto-generated Cypher:**

```php
'scopes' => [
    'hasVolunteerTeamOccupation' => [
        'name' => 'hasVolunteerTeamOccupation',
        'type' => 'traversal',
        'cypher_pattern' => "EXISTS((n)-[:MEMBER_OF]->(:PersonTeam {role_type: 3}))",
        'description' => 'Filter persons with volunteer team occupation',
    ],
],
```

**Supported nested patterns:**

| Laravel Pattern | Cypher Result |
|-----------------|---------------|
| `whereHas('rel', fn($q) => $q->scope())` | `EXISTS((n)-[:REL]->(:Target {field: value}))` |
| `whereHas('rel', fn($q) => $q->where('x', 'y'))` | `EXISTS((n)-[:REL]->(:Target {x: 'y'}))` |
| `whereHas('rel', fn($q) => $q->whereIn('x', [...]))` | `EXISTS((n)-[:REL]->(:Target) WHERE x IN [...])` |

### Traversal Scopes

Auto-generated from relationship discriminator fields.

**Configure role mappings:**

```php
// config/ai.php
'discovery' => [
    'role_mappings' => [
        'PersonTeam' => [
            'role_type' => [
                3 => 'volunteers',
                4 => 'scouts',
            ],
        ],
        'OrderItem' => [
            'item_type' => [
                'product' => 'product_items',
                'service' => 'service_items',
            ],
        ],
    ],
],
```

**Generated traversal scopes:**

```php
// On Person entity
'scopes' => [
    'volunteers' => [
        'type' => 'traversal',
        'cypher_pattern' => "EXISTS((n)-[:MEMBER_OF]->(:PersonTeam {role_type: 3}))",
    ],
],
```

### Aliases

Auto-generated from:
- Table name (singular and plural)
- Class name variations
- Common synonyms

**Custom alias mappings:**

```php
// config/ai.php
'discovery' => [
    'alias_mappings' => [
        'customers' => ['client', 'buyer', 'account'],
        'orders' => ['purchase', 'transaction', 'sale'],
    ],
],
```

### Embed Fields

Automatically selected:
- Text fields (name, description, title, etc.)
- Non-sensitive string fields
- Fields likely to be searched

**Prioritized:**
1. `name`, `title`
2. `description`, `summary`
3. `email`, `company`
4. Other string fields

### Metadata Fields

Automatically selected:
- Primary key (`id`)
- Status/type fields
- Date fields
- Foreign keys

---

## Customizing Discovery

### Exclude Properties

```php
'discovery' => [
    'exclude_properties' => [
        'internal_notes',
        'admin_only_field',
        'legacy_*',  // Wildcard
    ],
],
```

### Custom Alias Mappings

```php
'discovery' => [
    'alias_mappings' => [
        'customers' => ['client', 'buyer', 'account', 'purchaser'],
        'orders' => ['purchase', 'transaction', 'sale', 'booking'],
        'products' => ['item', 'sku', 'merchandise'],
    ],
],
```

### Role Mappings for Traversal Scopes

```php
'discovery' => [
    'role_mappings' => [
        // Model name => [field => [value => scope_name]]
        'PersonTeam' => [
            'role_type' => [
                3 => 'volunteers',
                4 => 'scouts',
                5 => 'parents',
            ],
        ],
        'OrderItem' => [
            'item_type' => [
                'product' => 'product_items',
                'service' => 'service_items',
            ],
            'status' => [
                'pending' => 'pending_items',
                'shipped' => 'shipped_items',
            ],
        ],
    ],
],
```

### What to Discover

```php
'discovery' => [
    'properties' => true,
    'relationships' => true,
    'scopes' => true,
    'aliases' => true,
    'embed_fields' => true,
],
```

---

## Discovery is Required

**Important:** There is no runtime auto-discovery. You must run the discovery command to generate configuration.

```bash
php artisan ai:discover
```

This generates `config/entities.php` which is the **source of truth** for entity configuration.

**Benefits:**
- Fast at runtime (no analysis needed)
- Review and customize before deploying
- Version controlled
- Consistent behavior across environments

**Workflow:**
1. Add `Nodeable` interface to your models
2. Run `php artisan ai:discover`
3. Review and customize `config/entities.php`
4. Commit to version control
5. Re-run after model changes

---

## Configuration Resolution Flow

The `config/entities.php` file is the **source of truth**:

```
┌────────────────────────────────────────────────────────────┐
│  Configuration Resolution (HasNodeableConfig trait)        │
├────────────────────────────────────────────────────────────┤
│                                                            │
│  1. nodeableConfig() defined?                              │
│     └──> Yes: Use it directly (full override)              │
│     └──> No: Continue to step 2                            │
│                                                            │
│  2. Check config/entities.php                              │
│     └──> Has config: Use it as base                        │
│     └──> Empty: Throw RuntimeException                     │
│                                                            │
│  3. Merge model properties on top                          │
│     └──> $embedFields, $graphLabel, $sensibleColumns       │
│     └──> $nodeableAliases, $graphRelationships             │
│                                                            │
│  4. Ensure minimum defaults                                │
│     └──> Label from class name                             │
│     └──> Properties from $fillable                         │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

**Note:** If no configuration is found (neither `nodeableConfig()` nor `config/entities.php`), a `RuntimeException` is thrown with instructions to run `php artisan ai:discover`.

### Model Properties (Quick Tweaks)

Use model properties to customize without editing `entities.php`:

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'company'];

    // These merge ON TOP of entities.php config
    protected array $embedFields = ['name', 'company'];
    protected string $graphLabel = 'Customer';
    protected array $sensibleColumns = ['ssn'];
    protected array $nodeableAliases = ['customer', 'client'];
}
```

### Full Override with nodeableConfig()

For complete control, override everything:

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function nodeableConfig(): NodeableConfig
    {
        // This overrides everything - entities.php is ignored
        return NodeableConfig::for(static::class)
            ->label('Customer')
            ->properties('id', 'name', 'email')
            ->aliases('customer', 'client', 'vip');
    }
}
```

---

## Workflow

### Initial Setup

```bash
# 1. Add Nodeable to your models
# 2. Configure custom mappings if needed
# 3. Run discovery
php artisan ai:discover

# 4. Review generated config
cat config/entities.php

# 5. Customize as needed
# 6. Ingest existing data
php artisan ai:ingest
```

### After Model Changes

```bash
# Re-run discovery
php artisan ai:discover

# Review changes
git diff config/entities.php

# Re-index if needed
php artisan ai:index-semantic --rebuild
```

### CI/CD Pipeline

```yaml
# In your deployment script
- name: Discover entities
  run: php artisan ai:discover --force

- name: Rebuild indexes
  run: php artisan ai:index-semantic --rebuild
```

---

## Troubleshooting

### Model Not Discovered

1. Check implements `Nodeable` interface
2. Check uses `HasNodeableConfig` trait
3. Check model is in scanned namespace

### Wrong Properties Discovered

1. Add to `exclude_properties` config
2. Or override with `nodeableConfig()` method

### Missing Relationships

1. Ensure Eloquent methods are public
2. Check relationship method names
3. Run with `-v` for verbose output

### Scopes Not Detected

1. Ensure scopes are public methods
2. Method must start with `scope`
3. Check scope can be converted to Cypher

---

## Related Documentation

- [Entity Configuration](/docs/{{version}}/configuration/entities) - Manual configuration
- [Scopes & Business Logic](/docs/{{version}}/advanced/scopes) - Scope details
- [Quick Start](/docs/{{version}}/usage/quick-start) - Getting started
- [Data Ingestion](/docs/{{version}}/usage/data-ingestion) - Ingesting data
