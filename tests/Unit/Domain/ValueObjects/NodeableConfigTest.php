<?php

namespace Condoedge\Ai\Tests\Unit\Domain\ValueObjects;

use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class NodeableConfigTest extends TestCase
{
    // =========================================================================
    // Factory Method Tests
    // =========================================================================

    public function test_can_create_for_model_class()
    {
        $config = NodeableConfig::for('App\Models\Customer');

        $this->assertInstanceOf(NodeableConfig::class, $config);
        $this->assertEquals('App\Models\Customer', $config->getModelClass());
    }

    public function test_can_create_from_array()
    {
        $array = [
            'graph' => [
                'label' => 'Customer',
                'properties' => ['id', 'name'],
            ],
            'vector' => [
                'collection' => 'customers',
                'embed_fields' => ['name'],
            ],
        ];

        $config = NodeableConfig::fromArray($array);

        $this->assertInstanceOf(NodeableConfig::class, $config);
        $this->assertEquals($array, $config->toArray());
    }

    public function test_can_discover_from_model()
    {
        // Create an anonymous model class for testing
        $model = new class extends Model {
            protected $table = 'random_models'; // No table
            protected $fillable = ['name', 'email'];
            protected $casts = ['active' => 'boolean'];
        };

        $config = NodeableConfig::discover($model);

        $this->assertInstanceOf(NodeableConfig::class, $config);
        // Auto-discovery is a stub for now, just verify it returns a builder
    }

    // =========================================================================
    // Graph Configuration Tests
    // =========================================================================

    public function test_can_set_label()
    {
        $config = NodeableConfig::for('Customer')
            ->label('Customer')
            ->toArray();

        $this->assertEquals('Customer', $config['graph']['label']);
    }

    public function test_can_set_properties_with_multiple_arguments()
    {
        $config = NodeableConfig::for('Customer')
            ->properties('id', 'name', 'email')
            ->toArray();

        $this->assertEquals(['id', 'name', 'email'], $config['graph']['properties']);
    }

    public function test_can_set_properties_with_array()
    {
        $config = NodeableConfig::for('Customer')
            ->properties(['id', 'name', 'email'])
            ->toArray();

        $this->assertEquals(['id', 'name', 'email'], $config['graph']['properties']);
    }

    public function test_can_set_properties_with_mixed_arguments()
    {
        $config = NodeableConfig::for('Customer')
            ->properties(['id', 'name'], 'email', 'status')
            ->toArray();

        $this->assertEquals(['id', 'name', 'email', 'status'], $config['graph']['properties']);
    }

    public function test_can_add_relationship()
    {
        $config = NodeableConfig::for('Customer')
            ->relationship('PURCHASED', 'Order', 'order_id')
            ->toArray();

        $this->assertCount(1, $config['graph']['relationships']);
        $this->assertEquals([
            'type' => 'PURCHASED',
            'target_label' => 'Order',
            'foreign_key' => 'order_id',
        ], $config['graph']['relationships'][0]);
    }

    public function test_can_add_multiple_relationships()
    {
        $config = NodeableConfig::for('Person')
            ->relationship('MEMBER_OF', 'Team', 'team_id')
            ->relationship('REPORTS_TO', 'Person', 'manager_id')
            ->toArray();

        $this->assertCount(2, $config['graph']['relationships']);
        $this->assertEquals('MEMBER_OF', $config['graph']['relationships'][0]['type']);
        $this->assertEquals('REPORTS_TO', $config['graph']['relationships'][1]['type']);
    }

    public function test_can_add_relationship_without_foreign_key()
    {
        $config = NodeableConfig::for('Person')
            ->relationship('HAS_ROLE', 'PersonTeam')
            ->toArray();

        $this->assertCount(1, $config['graph']['relationships']);
        $this->assertEquals([
            'type' => 'HAS_ROLE',
            'target_label' => 'PersonTeam',
        ], $config['graph']['relationships'][0]);
    }

    public function test_can_add_relationship_with_properties()
    {
        $config = NodeableConfig::for('Person')
            ->relationship('MEMBER_OF', 'Team', 'team_id', ['since' => 'joined_at'])
            ->toArray();

        $this->assertEquals([
            'type' => 'MEMBER_OF',
            'target_label' => 'Team',
            'foreign_key' => 'team_id',
            'properties' => ['since' => 'joined_at'],
        ], $config['graph']['relationships'][0]);
    }

    // =========================================================================
    // Vector Configuration Tests
    // =========================================================================

    public function test_can_set_collection()
    {
        $config = NodeableConfig::for('Customer')
            ->collection('customers')
            ->toArray();

        $this->assertEquals('customers', $config['vector']['collection']);
    }

    public function test_can_set_embed_fields_with_multiple_arguments()
    {
        $config = NodeableConfig::for('Customer')
            ->embedFields('name', 'description', 'bio')
            ->toArray();

        $this->assertEquals(['name', 'description', 'bio'], $config['vector']['embed_fields']);
    }

    public function test_can_set_embed_fields_with_array()
    {
        $config = NodeableConfig::for('Customer')
            ->embedFields(['name', 'description'])
            ->toArray();

        $this->assertEquals(['name', 'description'], $config['vector']['embed_fields']);
    }

    public function test_can_set_vector_metadata()
    {
        $config = NodeableConfig::for('Customer')
            ->vectorMetadata('id', 'status', 'created_at')
            ->toArray();

        $this->assertEquals(['id', 'status', 'created_at'], $config['vector']['metadata']);
    }

    // =========================================================================
    // Metadata Configuration Tests
    // =========================================================================

    public function test_can_set_aliases_with_multiple_arguments()
    {
        $config = NodeableConfig::for('Customer')
            ->aliases('customer', 'client', 'buyer')
            ->toArray();

        $this->assertEquals(['customer', 'client', 'buyer'], $config['metadata']['aliases']);
    }

    public function test_can_set_aliases_with_array()
    {
        $config = NodeableConfig::for('Customer')
            ->aliases(['customer', 'client'])
            ->toArray();

        $this->assertEquals(['customer', 'client'], $config['metadata']['aliases']);
    }

    public function test_can_set_description()
    {
        $config = NodeableConfig::for('Customer')
            ->description('Customer entity representing buyers')
            ->toArray();

        $this->assertEquals('Customer entity representing buyers', $config['metadata']['description']);
    }

    public function test_can_add_scope_with_array()
    {
        $config = NodeableConfig::for('Order')
            ->scope('pending', [
                'description' => 'Orders awaiting processing',
                'filter' => ['status' => 'pending'],
            ])
            ->toArray();

        $this->assertArrayHasKey('pending', $config['metadata']['scopes']);
        $this->assertEquals('Orders awaiting processing', $config['metadata']['scopes']['pending']['description']);
    }

    public function test_can_add_scope_with_closure()
    {
        $config = NodeableConfig::for('Order')
            ->scope('pending', fn() => [
                'description' => 'Orders awaiting processing',
                'filter' => ['status' => 'pending'],
            ])
            ->toArray();

        $this->assertArrayHasKey('pending', $config['metadata']['scopes']);
        $this->assertEquals('Orders awaiting processing', $config['metadata']['scopes']['pending']['description']);
    }

    public function test_can_add_multiple_scopes()
    {
        $config = NodeableConfig::for('Order')
            ->scope('pending', ['filter' => ['status' => 'pending']])
            ->scope('completed', ['filter' => ['status' => 'completed']])
            ->toArray();

        $this->assertCount(2, $config['metadata']['scopes']);
        $this->assertArrayHasKey('pending', $config['metadata']['scopes']);
        $this->assertArrayHasKey('completed', $config['metadata']['scopes']);
    }

    public function test_can_set_common_properties()
    {
        $config = NodeableConfig::for('Customer')
            ->commonProperties([
                'id' => 'Unique identifier',
                'name' => 'Customer name',
                'email' => 'Email address',
            ])
            ->toArray();

        $this->assertEquals([
            'id' => 'Unique identifier',
            'name' => 'Customer name',
            'email' => 'Email address',
        ], $config['metadata']['common_properties']);
    }

    // =========================================================================
    // Auto-Sync Configuration Tests
    // =========================================================================

    public function test_can_enable_auto_sync()
    {
        $config = NodeableConfig::for('Customer')
            ->autoSync(true)
            ->toArray();

        $this->assertTrue($config['auto_sync']);
    }

    public function test_can_disable_auto_sync()
    {
        $config = NodeableConfig::for('Customer')
            ->autoSync(false)
            ->toArray();

        $this->assertFalse($config['auto_sync']);
    }

    public function test_can_configure_auto_sync_with_array()
    {
        $config = NodeableConfig::for('Customer')
            ->autoSync([
                'create' => true,
                'update' => true,
                'delete' => false,
            ])
            ->toArray();

        $this->assertEquals([
            'create' => true,
            'update' => true,
            'delete' => false,
        ], $config['auto_sync']);
    }

    // =========================================================================
    // Method Chaining Tests
    // =========================================================================

    public function test_all_methods_are_chainable()
    {
        $config = NodeableConfig::for('Customer')
            ->label('Customer')
            ->properties('id', 'name')
            ->relationship('PURCHASED', 'Order', 'order_id')
            ->collection('customers')
            ->embedFields('name')
            ->vectorMetadata('id')
            ->aliases('customer')
            ->description('Customer entity')
            ->scope('active', ['filter' => ['status' => 'active']])
            ->commonProperties(['id' => 'Identifier'])
            ->autoSync(true);

        $this->assertInstanceOf(NodeableConfig::class, $config);
    }

    // =========================================================================
    // Output Format Tests - CRITICAL: Must match array format exactly!
    // =========================================================================

    public function test_builder_produces_identical_array_to_manual_config()
    {
        // Manual array config
        $manualConfig = [
            'graph' => [
                'label' => 'Customer',
                'properties' => ['id', 'name', 'email'],
                'relationships' => [
                    [
                        'type' => 'PURCHASED',
                        'target_label' => 'Order',
                        'foreign_key' => 'order_id',
                    ],
                ],
            ],
            'vector' => [
                'collection' => 'customers',
                'embed_fields' => ['name', 'email'],
                'metadata' => ['id', 'status'],
            ],
            'metadata' => [
                'aliases' => ['customer', 'client'],
                'description' => 'Customer entity',
            ],
        ];

        // Builder config
        $builderConfig = NodeableConfig::for('Customer')
            ->label('Customer')
            ->properties('id', 'name', 'email')
            ->relationship('PURCHASED', 'Order', 'order_id')
            ->collection('customers')
            ->embedFields('name', 'email')
            ->vectorMetadata('id', 'status')
            ->aliases('customer', 'client')
            ->description('Customer entity')
            ->toArray();

        // They must be IDENTICAL
        $this->assertEquals($manualConfig, $builderConfig);
    }

    public function test_builder_can_be_used_in_entities_config()
    {
        // This is how it would be used in config/entities.php
        $entitiesConfig = [
            'Customer' => NodeableConfig::for('Customer')
                ->label('Customer')
                ->properties('id', 'name')
                ->collection('customers')
                ->embedFields('name')
                ->toArray(),
        ];

        $this->assertIsArray($entitiesConfig['Customer']);
        $this->assertEquals('Customer', $entitiesConfig['Customer']['graph']['label']);
    }

    // =========================================================================
    // Integration with Existing Config Classes Tests
    // =========================================================================

    public function test_can_convert_to_graph_config()
    {
        $builder = NodeableConfig::for('Customer')
            ->label('Customer')
            ->properties('id', 'name');

        $graphConfig = $builder->toGraphConfig();

        $this->assertInstanceOf(GraphConfig::class, $graphConfig);
        $this->assertEquals('Customer', $graphConfig->label);
        $this->assertEquals(['id', 'name'], $graphConfig->properties);
    }

    public function test_can_convert_to_vector_config()
    {
        $builder = NodeableConfig::for('Customer')
            ->collection('customers')
            ->embedFields('name', 'description');

        $vectorConfig = $builder->toVectorConfig();

        $this->assertInstanceOf(VectorConfig::class, $vectorConfig);
        $this->assertEquals('customers', $vectorConfig->collection);
        $this->assertEquals(['name', 'description'], $vectorConfig->embedFields);
    }

    public function test_throws_exception_when_converting_without_graph_config()
    {
        $builder = NodeableConfig::for('Customer');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Graph configuration not set');

        $builder->toGraphConfig();
    }

    public function test_throws_exception_when_converting_without_vector_config()
    {
        $builder = NodeableConfig::for('Customer');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Vector configuration not set');

        $builder->toVectorConfig();
    }

    public function test_can_check_if_graph_config_is_set()
    {
        $builder = NodeableConfig::for('Customer');
        $this->assertFalse($builder->hasGraphConfig());

        $builder->label('Customer')->properties('id');
        $this->assertTrue($builder->hasGraphConfig());
    }

    public function test_can_check_if_vector_config_is_set()
    {
        $builder = NodeableConfig::for('Customer');
        $this->assertFalse($builder->hasVectorConfig());

        $builder->collection('customers')->embedFields('name');
        $this->assertTrue($builder->hasVectorConfig());
    }

    public function test_can_check_if_metadata_is_set()
    {
        $builder = NodeableConfig::for('Customer');
        $this->assertFalse($builder->hasMetadata());

        $builder->aliases('customer');
        $this->assertTrue($builder->hasMetadata());
    }

    // =========================================================================
    // Complex Configuration Tests
    // =========================================================================

    public function test_can_build_complex_entity_config()
    {
        $config = NodeableConfig::for('Person')
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
                ],
            ])
            ->commonProperties([
                'id' => 'Unique identifier for the person',
                'first_name' => 'Person\'s first name',
                'last_name' => 'Person\'s last name',
                'email' => 'Email address',
                'status' => 'Current status: active, inactive, suspended',
            ])
            ->autoSync([
                'create' => true,
                'update' => true,
                'delete' => false,
            ])
            ->toArray();

        // Verify structure
        $this->assertArrayHasKey('graph', $config);
        $this->assertArrayHasKey('vector', $config);
        $this->assertArrayHasKey('metadata', $config);
        $this->assertArrayHasKey('auto_sync', $config);

        // Verify graph
        $this->assertEquals('Person', $config['graph']['label']);
        $this->assertCount(5, $config['graph']['properties']);
        $this->assertCount(2, $config['graph']['relationships']);

        // Verify vector
        $this->assertEquals('people', $config['vector']['collection']);
        $this->assertCount(3, $config['vector']['embed_fields']);
        $this->assertCount(3, $config['vector']['metadata']);

        // Verify metadata
        $this->assertCount(4, $config['metadata']['aliases']);
        $this->assertEquals('Individuals in the system', $config['metadata']['description']);
        $this->assertCount(2, $config['metadata']['scopes']);
        $this->assertCount(5, $config['metadata']['common_properties']);

        // Verify auto_sync
        $this->assertTrue($config['auto_sync']['create']);
        $this->assertTrue($config['auto_sync']['update']);
        $this->assertFalse($config['auto_sync']['delete']);
    }

    // =========================================================================
    // Auto-Discovery Override Tests
    // =========================================================================

    public function test_can_override_discovered_config()
    {
        // Simulate discovering a model then overriding specific parts
        $model = new class extends Model {
            protected $table = 'random_models'; // No table
            protected $fillable = ['name', 'email'];
            protected $casts = ['active' => 'boolean'];
        };

        $config = NodeableConfig::discover($model)
            ->aliases('custom_alias') // Override just aliases
            ->description('Custom description') // Override description
            ->toArray();

        $this->assertEquals(['custom_alias'], $config['metadata']['aliases']);
        $this->assertEquals('Custom description', $config['metadata']['description']);
    }

    // =========================================================================
    // Edge Cases Tests
    // =========================================================================

    public function test_empty_builder_returns_empty_array()
    {
        $config = NodeableConfig::for('Customer')->toArray();

        $this->assertEmpty($config);
    }

    public function test_can_build_graph_only_config()
    {
        $config = NodeableConfig::for('Team')
            ->label('Team')
            ->properties('id', 'name')
            ->toArray();

        $this->assertArrayHasKey('graph', $config);
        $this->assertArrayNotHasKey('vector', $config);
        $this->assertArrayNotHasKey('metadata', $config);
    }

    public function test_can_build_vector_only_config()
    {
        $config = NodeableConfig::for('Document')
            ->collection('documents')
            ->embedFields('content')
            ->toArray();

        $this->assertArrayHasKey('vector', $config);
        $this->assertArrayNotHasKey('graph', $config);
    }

    public function test_properties_replace_previous_properties()
    {
        $config = NodeableConfig::for('Customer')
            ->properties('id', 'name')
            ->properties('id', 'email') // Replaces previous
            ->toArray();

        $this->assertEquals(['id', 'email'], $config['graph']['properties']);
    }

    public function test_relationships_are_additive()
    {
        $config = NodeableConfig::for('Customer')
            ->relationship('PURCHASED', 'Order', 'order_id')
            ->relationship('MEMBER_OF', 'Team', 'team_id')
            ->toArray();

        $this->assertCount(2, $config['graph']['relationships']);
    }
}
