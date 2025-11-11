# NodeableConfig Builder

A fluent API for building entity configurations that outputs the exact same array format as manual configuration.

## Overview

The `NodeableConfig` builder provides a chainable, type-safe way to create entity configurations. It produces arrays identical to manual configuration, ensuring complete interchangeability between the two approaches.

## Key Features

✓ **Fluent API** - Chain methods for readable, maintainable configs
✓ **Interchangeable** - Produces identical output to array configs
✓ **Type-Safe** - IDE autocomplete and type checking
✓ **Flexible** - Mix arrays and builder in same config file
✓ **Auto-Discovery Ready** - Stub for future automatic configuration
✓ **Conversion** - Direct conversion to GraphConfig/VectorConfig objects

## Basic Usage

### Simple Entity

```php
use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;

$config = NodeableConfig::for(Customer::class)
    ->label('Customer')
    ->properties('id', 'name', 'email')
    ->collection('customers')
    ->embedFields('name', 'email')
    ->aliases('customer', 'client')
    ->description('Customer entity')
    ->toArray();
```

### In config/entities.php

```php
return [
    // Traditional array approach
    'Customer' => [
        'graph' => ['label' => 'Customer', 'properties' => ['id', 'name']],
    ],

    // Builder approach - produces IDENTICAL output
    'Order' => NodeableConfig::for(Order::class)
        ->label('Order')
        ->properties('id', 'total', 'status')
        ->relationship('PLACED_BY', 'Customer', 'customer_id')
        ->collection('orders')
        ->embedFields('notes')
        ->toArray(),
];
```

## Factory Methods

### `NodeableConfig::for(string $modelClass)`

Create a new builder for a specific model class.

```php
$config = NodeableConfig::for('App\Models\Customer');
```

### `NodeableConfig::fromArray(array $config)`

Create a builder from existing array configuration.

```php
$existing = ['graph' => ['label' => 'Customer', 'properties' => ['id']]];
$builder = NodeableConfig::fromArray($existing);
```

### `NodeableConfig::discover(Model $model)`

Auto-discover configuration from a model (stub for future implementation).

```php
$config = NodeableConfig::discover($customer)
    ->aliases('custom_alias')  // Override discovered values
    ->toArray();
```

## Graph Configuration

### `label(string $label)`

Set the Neo4j node label.

```php
$builder->label('Customer');
```

### `properties(string|array ...$properties)`

Set properties to store in Neo4j. Accepts multiple arguments or arrays.

```php
// Multiple arguments
$builder->properties('id', 'name', 'email');

// Single array
$builder->properties(['id', 'name', 'email']);

// Mixed
$builder->properties(['id', 'name'], 'email', 'status');
```

### `relationship(string $type, string $targetLabel, ?string $foreignKey = null, array $properties = [])`

Add a relationship to another entity.

```php
// Basic relationship
$builder->relationship('PURCHASED', 'Order', 'order_id');

// Without foreign key
$builder->relationship('HAS_ROLE', 'PersonTeam');

// With relationship properties
$builder->relationship('MEMBER_OF', 'Team', 'team_id', [
    'since' => 'joined_at',
    'role' => 'member_role'
]);
```

Multiple relationships can be added by calling the method multiple times:

```php
$builder
    ->relationship('MEMBER_OF', 'Team', 'team_id')
    ->relationship('REPORTS_TO', 'Person', 'manager_id');
```

## Vector Configuration

### `collection(string $collection)`

Set the Qdrant collection name.

```php
$builder->collection('customers');
```

### `embedFields(string|array ...$fields)`

Set fields to embed for vector search.

```php
// Multiple arguments
$builder->embedFields('name', 'description', 'bio');

// Array
$builder->embedFields(['name', 'description']);
```

### `vectorMetadata(string|array ...$fields)`

Set metadata fields for vector storage.

```php
$builder->vectorMetadata('id', 'status', 'created_at');
```

## Metadata Configuration

### `aliases(string|array ...$aliases)`

Set aliases for semantic matching.

```php
// Multiple arguments
$builder->aliases('customer', 'client', 'buyer');

// Array
$builder->aliases(['customer', 'client']);
```

### `description(string $description)`

Set entity description.

```php
$builder->description('Customer entity representing buyers');
```

### `scope(string $name, array|Closure $config)`

Add a semantic scope.

```php
// With array
$builder->scope('pending', [
    'description' => 'Orders awaiting processing',
    'filter' => ['status' => 'pending'],
]);

// With closure
$builder->scope('pending', fn() => [
    'description' => 'Orders awaiting processing',
    'filter' => ['status' => 'pending'],
]);

// Multiple scopes
$builder
    ->scope('pending', ['filter' => ['status' => 'pending']])
    ->scope('completed', ['filter' => ['status' => 'completed']]);
```

### `commonProperties(array $properties)`

Add property descriptions.

```php
$builder->commonProperties([
    'id' => 'Unique identifier',
    'name' => 'Customer name',
    'email' => 'Email address',
]);
```

## Auto-Sync Configuration

### `autoSync(bool|array $config)`

Enable or configure auto-sync.

```php
// Enable all
$builder->autoSync(true);

// Disable all
$builder->autoSync(false);

// Granular control
$builder->autoSync([
    'create' => true,
    'update' => true,
    'delete' => false,
]);
```

## Output Methods

### `toArray(): array`

Convert builder to array configuration. **This produces the EXACT same array structure as manual config.**

```php
$array = $builder->toArray();
```

### `toGraphConfig(): GraphConfig`

Convert to GraphConfig object.

```php
$graphConfig = $builder->toGraphConfig();
// Throws LogicException if graph config not set
```

### `toVectorConfig(): VectorConfig`

Convert to VectorConfig object.

```php
$vectorConfig = $builder->toVectorConfig();
// Throws LogicException if vector config not set
```

### State Checking

```php
$builder->hasGraphConfig();   // bool
$builder->hasVectorConfig();  // bool
$builder->hasMetadata();      // bool
$builder->getModelClass();    // ?string
```

## Complete Examples

### Complex Entity with Relationships

```php
$config = NodeableConfig::for(Person::class)
    ->label('Person')
    ->properties('id', 'first_name', 'last_name', 'email', 'status')
    ->relationship('MEMBER_OF', 'Team', 'team_id')
    ->relationship('HAS_ROLE', 'PersonTeam')
    ->collection('people')
    ->embedFields('first_name', 'last_name', 'bio')
    ->vectorMetadata('id', 'email', 'status')
    ->aliases('person', 'people', 'user', 'member')
    ->description('Individuals in the system')
    ->scope('active', [
        'specification_type' => 'property_filter',
        'concept' => 'People who are currently active',
        'filter' => [
            'property' => 'status',
            'operator' => 'equals',
            'value' => 'active',
        ],
    ])
    ->commonProperties([
        'id' => 'Unique identifier for the person',
        'email' => 'Email address',
        'status' => 'Current status: active, inactive, suspended',
    ])
    ->autoSync(true)
    ->toArray();
```

### Graph-Only Entity

```php
$config = NodeableConfig::for(Team::class)
    ->label('Team')
    ->properties('id', 'name', 'department')
    ->relationship('HAS_MANAGER', 'Person', 'manager_id')
    ->toArray();
```

### Relationship-Based Scope

```php
$config = NodeableConfig::for(Person::class)
    ->label('Person')
    ->properties('id', 'name')
    ->relationship('HAS_ROLE', 'PersonTeam')
    ->scope('volunteers', [
        'specification_type' => 'relationship_traversal',
        'concept' => 'People who volunteer their time on teams',
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
    ])
    ->toArray();
```

## Interchangeability Guarantee

The builder produces **IDENTICAL** output to manual array configuration:

```php
// Manual array config
$manualConfig = [
    'graph' => ['label' => 'Customer', 'properties' => ['id', 'name']],
    'vector' => ['collection' => 'customers', 'embed_fields' => ['name']],
];

// Builder config
$builderConfig = NodeableConfig::for(Customer::class)
    ->label('Customer')
    ->properties('id', 'name')
    ->collection('customers')
    ->embedFields('name')
    ->toArray();

// They are IDENTICAL
assert($manualConfig === $builderConfig); // true
```

This guarantee means:
- ✓ Can be used in `config/entities.php` directly
- ✓ Can mix and match with array configs
- ✓ Works with all existing code expecting arrays
- ✓ No migration needed for existing configs

## When to Use

### Use the Builder When:
- ✓ Creating new entity configurations
- ✓ You want IDE autocomplete and type safety
- ✓ You prefer fluent, chainable APIs
- ✓ Building complex configs programmatically
- ✓ You want better refactoring support

### Use Arrays When:
- ✓ You already have working array configs
- ✓ You prefer traditional PHP arrays
- ✓ Config is very simple (few lines)
- ✓ You're copying from documentation examples

### Mix and Match:
```php
return [
    'Simple' => ['graph' => ['label' => 'Simple', 'properties' => ['id']]],

    'Complex' => NodeableConfig::for(Complex::class)
        ->label('Complex')
        ->properties('id', 'name')
        ->relationship('RELATES_TO', 'Other', 'other_id')
        ->collection('complex')
        ->embedFields('name', 'description')
        ->scope('active', ['filter' => ['status' => 'active']])
        ->toArray(),
];
```

## Implementation Notes

### Method Chaining
All configuration methods return `$this` for method chaining:

```php
$config = NodeableConfig::for(Customer::class)
    ->label('Customer')        // returns $this
    ->properties('id', 'name') // returns $this
    ->collection('customers')  // returns $this
    ->toArray();               // returns array
```

### Internal Structure
The builder maintains an internal array matching the entities config format:

```php
[
    'graph' => [...],
    'vector' => [...],
    'metadata' => [...],
    'auto_sync' => [...],
]
```

Each method modifies the appropriate section of this array.

### Properties vs Relationships
- `properties()` **replaces** previous properties
- `relationship()` **adds** to existing relationships

```php
$builder
    ->properties('id', 'name')
    ->properties('id', 'email');  // Replaces: ['id', 'email']

$builder
    ->relationship('TYPE1', 'Target1', 'fk1')
    ->relationship('TYPE2', 'Target2', 'fk2'); // Adds: 2 relationships
```

## Testing

Comprehensive test suite with 42 tests covering:
- ✓ Factory methods
- ✓ All configuration methods
- ✓ Method chaining
- ✓ Output format verification
- ✓ Integration with GraphConfig/VectorConfig
- ✓ Interchangeability with arrays
- ✓ Edge cases

Run tests:
```bash
./vendor/bin/phpunit tests/Unit/Domain/ValueObjects/NodeableConfigTest.php
```

## See Also

- **Examples**: `examples/NodeableConfigBuilderDemo.php`
- **Config Structure**: `config/entities.php`
- **Related Classes**:
  - `GraphConfig` - Graph storage configuration
  - `VectorConfig` - Vector storage configuration
  - `RelationshipConfig` - Relationship configuration
  - `HasNodeableConfig` - Trait for models

## Future Enhancements

The `discover()` method is a stub for future auto-discovery:
- Auto-detect properties from `$fillable`, `$casts`, `$dates`
- Auto-detect relationships from model methods
- Auto-detect scopes from `scopeXxx()` methods
- Auto-generate aliases from table names

This will enable:
```php
// Future: Auto-discover everything, override only what's needed
NodeableConfig::discover($customer)
    ->aliases('preferred_alias')  // Override just aliases
    ->toArray();
```
