<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;

/**
 * NodeableConfig Builder Demo
 *
 * This example demonstrates how to use the fluent NodeableConfig builder
 * to create entity configurations that can be used interchangeably with
 * manual array configurations.
 */

echo "=================================================================\n";
echo "NodeableConfig Builder Demo\n";
echo "=================================================================\n\n";

// =========================================================================
// EXAMPLE 1: Simple Entity Configuration
// =========================================================================

echo "EXAMPLE 1: Simple Entity Configuration\n";
echo "-----------------------------------------------------------------\n";

$simpleConfig = NodeableConfig::for('Customer')
    ->label('Customer')
    ->properties('id', 'name', 'email', 'status')
    ->collection('customers')
    ->embedFields('name', 'email')
    ->aliases('customer', 'client', 'buyer')
    ->description('Customer entity representing buyers')
    ->toArray();

echo "Built configuration:\n";
print_r($simpleConfig);
echo "\n";

// =========================================================================
// EXAMPLE 2: Complex Entity with Relationships
// =========================================================================

echo "EXAMPLE 2: Complex Entity with Relationships\n";
echo "-----------------------------------------------------------------\n";

$complexConfig = NodeableConfig::for('Order')
    ->label('Order')
    ->properties('id', 'total', 'status', 'notes', 'created_at')
    ->relationship('PLACED_BY', 'Customer', 'customer_id')
    ->relationship('CONTAINS', 'OrderItem')
    ->collection('orders')
    ->embedFields('notes', 'description')
    ->vectorMetadata('id', 'status', 'total')
    ->aliases('order', 'orders', 'purchase', 'sale')
    ->description('Customer orders and purchases')
    ->scope('pending', [
        'description' => 'Orders awaiting processing',
        'filter' => ['status' => 'pending'],
        'cypher_pattern' => "status = 'pending'",
        'examples' => [
            'Show pending orders',
            'How many orders are pending?',
        ],
    ])
    ->scope('high_value', [
        'description' => 'Orders with high total value',
        'filter' => [],
        'cypher_pattern' => 'total > 1000',
        'examples' => ['Show high value orders', 'Orders over $1000'],
    ])
    ->commonProperties([
        'id' => 'Unique order identifier',
        'total' => 'Total order amount in currency',
        'status' => 'Order status: pending, processing, completed, cancelled',
        'created_at' => 'When the order was placed',
    ])
    ->autoSync([
        'create' => true,
        'update' => true,
        'delete' => true,
    ])
    ->toArray();

echo "Built configuration:\n";
print_r($complexConfig);
echo "\n";

// =========================================================================
// EXAMPLE 3: Graph-Only Entity (No Vector Search)
// =========================================================================

echo "EXAMPLE 3: Graph-Only Entity (No Vector Search)\n";
echo "-----------------------------------------------------------------\n";

$graphOnlyConfig = NodeableConfig::for('Team')
    ->label('Team')
    ->properties('id', 'name', 'department', 'created_at')
    ->relationship('HAS_MANAGER', 'Person', 'manager_id')
    ->aliases('team', 'teams', 'group')
    ->description('Organizational teams')
    ->toArray();

echo "Built configuration:\n";
print_r($graphOnlyConfig);
echo "\n";

// =========================================================================
// EXAMPLE 4: Interchangeability with Array Config
// =========================================================================

echo "EXAMPLE 4: Interchangeability Test\n";
echo "-----------------------------------------------------------------\n";

// Manual array config
$manualConfig = [
    'graph' => [
        'label' => 'Product',
        'properties' => ['id', 'name', 'price'],
        'relationships' => [
            [
                'type' => 'IN_CATEGORY',
                'target_label' => 'Category',
                'foreign_key' => 'category_id',
            ],
        ],
    ],
    'vector' => [
        'collection' => 'products',
        'embed_fields' => ['name', 'description'],
        'metadata' => ['id', 'price'],
    ],
    'metadata' => [
        'aliases' => ['product', 'item'],
        'description' => 'Product entity',
    ],
];

// Builder config
$builderConfig = NodeableConfig::for('Product')
    ->label('Product')
    ->properties('id', 'name', 'price')
    ->relationship('IN_CATEGORY', 'Category', 'category_id')
    ->collection('products')
    ->embedFields('name', 'description')
    ->vectorMetadata('id', 'price')
    ->aliases('product', 'item')
    ->description('Product entity')
    ->toArray();

echo "Manual config:\n";
print_r($manualConfig);
echo "\nBuilder config:\n";
print_r($builderConfig);
echo "\nConfigs are identical: " . ($manualConfig === $builderConfig ? 'YES ✓' : 'NO ✗') . "\n\n";

// =========================================================================
// EXAMPLE 5: Using in config/entities.php
// =========================================================================

echo "EXAMPLE 5: Usage in config/entities.php\n";
echo "-----------------------------------------------------------------\n";

$entitiesConfig = [
    // Traditional array approach
    'Customer' => [
        'graph' => [
            'label' => 'Customer',
            'properties' => ['id', 'name'],
        ],
    ],

    // Builder approach - produces identical output
    'Order' => NodeableConfig::for('Order')
        ->label('Order')
        ->properties('id', 'total', 'status')
        ->relationship('PLACED_BY', 'Customer', 'customer_id')
        ->toArray(),

    // Mix and match!
    'Product' => NodeableConfig::for('Product')
        ->label('Product')
        ->properties('id', 'name', 'price')
        ->collection('products')
        ->embedFields('name', 'description')
        ->toArray(),
];

echo "Entities configuration:\n";
print_r($entitiesConfig);
echo "\n";

// =========================================================================
// EXAMPLE 6: Converting to Value Objects
// =========================================================================

echo "EXAMPLE 6: Converting to Value Objects\n";
echo "-----------------------------------------------------------------\n";

$builder = NodeableConfig::for('Customer')
    ->label('Customer')
    ->properties('id', 'name', 'email')
    ->collection('customers')
    ->embedFields('name', 'email');

// Convert to GraphConfig
$graphConfig = $builder->toGraphConfig();
echo "GraphConfig:\n";
echo "  Label: {$graphConfig->label}\n";
echo "  Properties: " . implode(', ', $graphConfig->properties) . "\n\n";

// Convert to VectorConfig
$vectorConfig = $builder->toVectorConfig();
echo "VectorConfig:\n";
echo "  Collection: {$vectorConfig->collection}\n";
echo "  Embed Fields: " . implode(', ', $vectorConfig->embedFields) . "\n\n";

// =========================================================================
// EXAMPLE 7: Complex Relationship-Based Scope
// =========================================================================

echo "EXAMPLE 7: Complex Relationship-Based Scope\n";
echo "-----------------------------------------------------------------\n";

$personConfig = NodeableConfig::for('Person')
    ->label('Person')
    ->properties('id', 'first_name', 'last_name', 'email', 'status')
    ->relationship('HAS_ROLE', 'PersonTeam')
    ->relationship('MEMBER_OF', 'Team', 'team_id')
    ->collection('people')
    ->embedFields('first_name', 'last_name', 'bio')
    ->vectorMetadata('id', 'email', 'status')
    ->aliases('person', 'people', 'user', 'member', 'individual')
    ->description('Individuals in the system')
    ->scope('active', [
        'specification_type' => 'property_filter',
        'concept' => 'People who are currently active',
        'filter' => [
            'property' => 'status',
            'operator' => 'equals',
            'value' => 'active',
        ],
        'business_rules' => [
            'Active people can access the system',
        ],
        'examples' => ['Show active people', 'List active members'],
    ])
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
            'return_distinct' => true,
        ],
        'business_rules' => [
            'A person is a volunteer if they have at least one volunteer role',
            'The volunteer role is stored in PersonTeam.role_type',
        ],
        'examples' => [
            'Show me all volunteers',
            'How many volunteers do we have?',
        ],
    ])
    ->commonProperties([
        'id' => 'Unique identifier for the person',
        'first_name' => 'Person\'s first name',
        'last_name' => 'Person\'s last name',
        'email' => 'Email address',
        'status' => 'Current status: active, inactive, suspended',
    ])
    ->toArray();

echo "Person configuration with relationship-based scopes:\n";
echo "Number of scopes: " . count($personConfig['metadata']['scopes']) . "\n";
echo "Scopes: " . implode(', ', array_keys($personConfig['metadata']['scopes'])) . "\n\n";

// =========================================================================
// EXAMPLE 8: Using Closures for Scopes
// =========================================================================

echo "EXAMPLE 8: Using Closures for Scopes\n";
echo "-----------------------------------------------------------------\n";

$configWithClosure = NodeableConfig::for('Order')
    ->label('Order')
    ->properties('id', 'status')
    ->scope('pending', fn() => [
        'description' => 'Orders awaiting processing',
        'filter' => ['status' => 'pending'],
    ])
    ->scope('completed', fn() => [
        'description' => 'Orders that have been fulfilled',
        'filter' => ['status' => 'completed'],
    ])
    ->toArray();

echo "Configuration with closure-based scopes:\n";
print_r($configWithClosure['metadata']['scopes']);
echo "\n";

// =========================================================================
// EXAMPLE 9: Check Configuration State
// =========================================================================

echo "EXAMPLE 9: Check Configuration State\n";
echo "-----------------------------------------------------------------\n";

$builder = NodeableConfig::for('Customer')
    ->label('Customer')
    ->properties('id', 'name');

echo "Has graph config: " . ($builder->hasGraphConfig() ? 'YES' : 'NO') . "\n";
echo "Has vector config: " . ($builder->hasVectorConfig() ? 'NO' : 'YES') . "\n";
echo "Has metadata: " . ($builder->hasMetadata() ? 'YES' : 'NO') . "\n";

$builder->collection('customers')->embedFields('name');
echo "\nAfter adding vector config:\n";
echo "Has vector config: " . ($builder->hasVectorConfig() ? 'YES' : 'NO') . "\n";

$builder->aliases('customer', 'client');
echo "\nAfter adding metadata:\n";
echo "Has metadata: " . ($builder->hasMetadata() ? 'YES' : 'NO') . "\n\n";

// =========================================================================
// EXAMPLE 10: Relationship with Properties
// =========================================================================

echo "EXAMPLE 10: Relationship with Properties\n";
echo "-----------------------------------------------------------------\n";

$configWithRelProperties = NodeableConfig::for('Person')
    ->label('Person')
    ->properties('id', 'name')
    ->relationship('MEMBER_OF', 'Team', 'team_id', ['since' => 'joined_at', 'role' => 'member_role'])
    ->toArray();

echo "Relationship with properties:\n";
print_r($configWithRelProperties['graph']['relationships'][0]);
echo "\n";

echo "=================================================================\n";
echo "Demo Complete!\n";
echo "=================================================================\n";
