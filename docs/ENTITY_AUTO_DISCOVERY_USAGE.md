# Entity Auto-Discovery Usage Guide

Complete guide to using the EntityAutoDiscovery service for automatic configuration discovery from Eloquent models.

## Overview

The EntityAutoDiscovery service automatically introspects your Eloquent models to generate complete entity configurations without manual setup. It discovers:

- **Graph Configuration**: Node labels, properties, and relationships
- **Vector Configuration**: Collection names, embed fields, and metadata
- **Semantic Metadata**: Aliases, descriptions, scopes, and property descriptions

## Quick Start

### Basic Usage

```php
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

$discovery = app(EntityAutoDiscovery::class);

// Discover complete configuration
$config = $discovery->discover(Customer::class);

// Use the configuration
$graphConfig = $config['graph'];
$vectorConfig = $config['vector'];
$metadata = $config['metadata'];
```

### Partial Discovery

Discover only specific parts:

```php
// Only graph configuration (Neo4j)
$graphConfig = $discovery->discoverGraph(Customer::class);

// Only vector configuration (Qdrant)
$vectorConfig = $discovery->discoverVector(Customer::class);

// Only metadata (aliases, scopes, descriptions)
$metadata = $discovery->discoverMetadata(Customer::class);
```

## Discovery Components

### 1. PropertyDiscoverer

Discovers properties from model attributes:

```php
$propertyDiscoverer = app(PropertyDiscoverer::class);
$properties = $propertyDiscoverer->discover(Customer::class);
// ['id', 'name', 'email', 'status', 'created_at', 'updated_at']
```

**Discovery Sources:**
- `$fillable` attributes
- `$casts` attributes
- `$dates` attributes
- Database schema (indexed columns)
- Foreign key columns

**Excluded Properties:**
- Passwords and secrets
- Remember tokens
- Hidden fields

### 2. RelationshipDiscoverer

Discovers relationships from Eloquent methods:

```php
$relationshipDiscoverer = app(RelationshipDiscoverer::class);
$relationships = $relationshipDiscoverer->discover(Customer::class);
// [
//     ['type' => 'ORDERS', 'target_label' => 'Order', 'inverse' => true],
//     ...
// ]
```

**Supported Relationships:**
- `belongsTo` - Outbound with foreign key
- `hasMany` - Inbound relationships
- `hasOne` - Inbound relationships
- `belongsToMany` - Pivot relationships

**Enhancement:**
- Automatically discovers foreign keys from database schema
- Infers relationships from `*_id` columns

### 3. AliasGenerator

Generates semantic aliases for better query matching:

```php
$aliasGenerator = app(AliasGenerator::class);
$aliases = $aliasGenerator->generate(Customer::class);
// ['customer', 'customers', 'client', 'clients', 'buyer', 'patron', 'account']
```

**Generated Aliases:**
- Singular and plural forms
- Common business terms (customer → client, buyer)
- Variations (underscore, space, StudlyCase)

**Business Term Mappings:**
- Customer → client, buyer, patron
- Order → purchase, sale, transaction
- Product → item, good, merchandise
- (See `AliasGenerator::BUSINESS_TERMS` for full list)

### 4. EmbedFieldDetector

Detects fields suitable for vector embeddings:

```php
$embedFieldDetector = app(EmbedFieldDetector::class);
$embedFields = $embedFieldDetector->detect(Customer::class);
// ['bio', 'notes', 'description']
```

**Detection Criteria:**
- Text column types (text, longtext, mediumtext)
- String columns with text-like names
- Excludes IDs, foreign keys, timestamps

**Text Field Patterns:**
- description, bio, notes, content
- body, summary, details, comment
- message, text, remarks, about

### 5. CypherScopeAdapter

Discovers Eloquent scopes and converts to Cypher:

```php
$scopeAdapter = app(CypherScopeAdapter::class);
$scopes = $scopeAdapter->discoverScopes(Customer::class);
// [
//     'active' => [
//         'specification_type' => 'property_filter',
//         'concept' => 'Customers with Active status',
//         'cypher_pattern' => "n.status = 'active'",
//         'filter' => ['status' => 'active'],
//         'examples' => [...]
//     ]
// ]
```

**Discovered Scopes:**
- `scopeActive()` → active scope
- `scopeHighValue()` → high_value scope
- `scopeWithOrders()` → with_orders scope (relationship)

## Merging with Manual Configuration

Override or enhance discovered configuration:

```php
$manualConfig = [
    'metadata' => [
        'aliases' => ['premium_customer', 'vip'],
        'description' => 'High-value customer entity',
    ],
    'vector' => [
        'embed_fields' => ['name', 'bio', 'custom_field'],
    ],
];

$merged = $discovery->discoverAndMerge(Customer::class, $manualConfig);
```

**Merge Behavior:**
- Manual config takes precedence
- Arrays are deeply merged
- Use this to add custom scopes or override defaults

## Integration with NodeableConfig

Use auto-discovery with the fluent builder:

```php
use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;

// Auto-discover and build
$config = NodeableConfig::discover($customer);

// Or manually build and merge
$autoConfig = $discovery->discover(Customer::class);
$config = NodeableConfig::fromArray($autoConfig)
    ->scope('vip', ['...']) // Add custom scope
    ->aliases('premium_customer') // Add alias
    ->toArray();
```

## Performance Considerations

### Caching

Schema inspection results are cached (1 hour TTL):

```php
// Clear cache for specific table
$schema = app(SchemaInspector::class);
$schema->clearCache('customers');

// Clear all caches
$schema->clearAllCaches();
```

### Conditional Discovery

Check if discovery should run:

```php
if ($discovery->shouldDiscover(Customer::class)) {
    $config = $discovery->discover(Customer::class);
}
```

**Discovery Requirements:**
- Model must implement `Nodeable` interface
- Auto-discovery must be enabled globally
- Model must not be in excluded list

## Best Practices

1. **Start with Auto-Discovery**
   - Let the system discover everything first
   - Review the output
   - Override only what's needed

2. **Use Merge for Customization**
   - Keep auto-discovered base
   - Layer manual config on top
   - Easier to maintain

3. **Add Meaningful Scopes**
   - Auto-discovery finds scopes automatically
   - Add business-meaningful scope names
   - Document with clear descriptions

4. **Cache Wisely**
   - Schema rarely changes
   - Cache is automatic
   - Clear after migrations

5. **Test Your Configuration**
   - Run discovery on dev/staging first
   - Verify all relationships found
   - Check scope parsing works

## Examples

See `examples/EntityAutoDiscoveryDemo.php` for complete working examples.

## Troubleshooting

### No Relationships Discovered

**Problem**: Relationships not found

**Solutions**:
- Ensure relationship methods are public
- Check methods return Relation instances
- Verify foreign keys exist in database
- Add schema hints with foreign key constraints

### Wrong Properties Included

**Problem**: Sensitive fields in properties

**Solutions**:
- Add to `EXCLUDED_PROPERTIES` constant
- Use manual config to override
- Mark as `$hidden` in model

### Scopes Not Discovered

**Problem**: Scopes missing from metadata

**Solutions**:
- Ensure scope methods start with `scope`
- Check scope uses query builder methods
- Verify scope doesn't require parameters
- Test scope can execute without errors

### Embed Fields Empty

**Problem**: No fields detected for embedding

**Solutions**:
- Check text column types in database
- Verify column names match patterns
- Add custom text field patterns
- Manually specify in vector config

## Testing

Run the integration tests:

```bash
./vendor/bin/phpunit tests/Integration/EntityAutoDiscoveryTest.php
```

## See Also

- [Entity Metadata Quickstart](./ENTITY_METADATA_QUICKSTART.md)
- [Relationship Scopes](./RELATIONSHIP_SCOPES_QUICKSTART.md)
- [Semantic Metadata Redesign](./SEMANTIC_METADATA_REDESIGN.md)
- [File Processing Design](./FILE_PROCESSING_DESIGN.md)
