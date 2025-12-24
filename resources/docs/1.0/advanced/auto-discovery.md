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
'auto_discovery' => [
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

### Traversal Scopes

Auto-generated from relationship discriminator fields.

**Configure role mappings:**

```php
// config/ai.php
'auto_discovery' => [
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
'auto_discovery' => [
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
'auto_discovery' => [
    'exclude_properties' => [
        'internal_notes',
        'admin_only_field',
        'legacy_*',  // Wildcard
    ],
],
```

### Custom Alias Mappings

```php
'auto_discovery' => [
    'alias_mappings' => [
        'customers' => ['client', 'buyer', 'account', 'purchaser'],
        'orders' => ['purchase', 'transaction', 'sale', 'booking'],
        'products' => ['item', 'sku', 'merchandise'],
    ],
],
```

### Role Mappings for Traversal Scopes

```php
'auto_discovery' => [
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
'auto_discovery' => [
    'discover' => [
        'properties' => true,
        'relationships' => true,
        'scopes' => true,
        'aliases' => true,
        'embed_fields' => true,
    ],
],
```

---

## Runtime vs Command Discovery

### Command Discovery (Recommended)

```bash
php artisan ai:discover
```

- Generates `config/entities.php`
- Fast at runtime (no analysis)
- Review before deploying
- Version controlled

### Runtime Discovery

```env
AI_AUTO_DISCOVERY_RUNTIME=true  # NOT recommended for production
```

- Analyzes models on every request
- **SLOW** - impacts performance
- Only for development/testing
- Not cached by default

**Use runtime discovery for:**
- Initial prototyping
- Development environment
- Learning the system

**Never use in production!**

---

## Discovery Priority

Entity configuration is resolved in order:

1. **`nodeableConfig()` method** (highest)
2. **`config/entities.php`** (middle)
3. **Runtime discovery** (lowest, if enabled)

### Override Specific Entities

Use `nodeableConfig()` to override discovered config:

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function nodeableConfig(): NodeableConfig
    {
        // This overrides auto-discovered config
        return NodeableConfig::for(static::class)
            ->label('Customer')
            ->properties('id', 'name', 'email')  // Custom selection
            ->aliases('customer', 'client', 'vip');  // Custom aliases
    }
}
```

---

## Caching

Discovery results can be cached:

```env
AI_AUTO_DISCOVERY_CACHE=true
AI_AUTO_DISCOVERY_CACHE_TTL=3600  # 1 hour
```

### Clear Cache

```bash
php artisan cache:clear
# or
php artisan ai:discover --force
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
