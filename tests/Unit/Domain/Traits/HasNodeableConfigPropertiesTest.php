<?php

namespace Condoedge\Ai\Tests\Unit\Domain\Traits;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class HasNodeableConfigPropertiesTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Enable runtime discovery for these tests
        config(['ai.auto_discovery.runtime_enabled' => true]);
        config(['ai.auto_discovery.enabled' => true]);
    }

    /** @test */
    public function it_reads_embed_fields_from_model_property(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'persons';
            protected $fillable = ['name', 'bio', 'email'];
            protected array $embedFields = ['name', 'bio'];
        };

        $vectorConfig = $model->getVectorConfig();

        $this->assertEquals(['name', 'bio'], $vectorConfig->embedFields);
    }

    /** @test */
    public function it_reads_graph_label_from_model_property(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'people';
            protected $fillable = ['name'];  // Need at least one property
            protected string $graphLabel = 'Person';
        };

        $graphConfig = $model->getGraphConfig();

        $this->assertEquals('Person', $graphConfig->label);
    }

    /** @test */
    public function it_reads_sensible_columns_from_model_property(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'persons';
            protected array $sensibleColumns = ['ssn', 'salary'];

            // Need this for the test to work with resolveConfig
            public function getResolvedConfig(): array
            {
                return $this->resolveConfig();
            }
        };

        $config = $model->getResolvedConfig();

        $this->assertEquals(['ssn', 'salary'], $config['security']['sensible_columns'] ?? []);
    }

    /** @test */
    public function it_merges_model_properties_with_nodeable_config(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'persons';
            protected $fillable = ['name', 'bio'];
            protected array $embedFields = ['name', 'bio'];  // Model property

            public function nodeableConfig(): array
            {
                return [
                    'graph' => [
                        'label' => 'CustomLabel',
                        'properties' => ['id', 'name', 'bio'],
                    ],
                ];
            }
        };

        $graphConfig = $model->getGraphConfig();
        $vectorConfig = $model->getVectorConfig();

        $this->assertEquals('CustomLabel', $graphConfig->label);  // From nodeableConfig
        $this->assertEquals(['name', 'bio'], $vectorConfig->embedFields);  // From property
    }

    /** @test */
    public function it_uses_class_name_as_default_label(): void
    {
        $model = new ModelWithoutLabel();

        $graphConfig = $model->getGraphConfig();

        // Should default to class name
        $this->assertEquals('ModelWithoutLabel', $graphConfig->label);
    }

    /** @test */
    public function it_reads_graph_relationships_from_model_property(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'orders';
            protected array $graphRelationships = [
                [
                    'name' => 'customer',
                    'type' => 'BELONGS_TO',
                    'target_entity' => 'Customer',
                    'foreign_key' => 'customer_id',
                ],
            ];

            public function getResolvedConfig(): array
            {
                return $this->resolveConfig();
            }
        };

        $config = $model->getResolvedConfig();
        $relationships = $config['graph']['relationships'] ?? [];

        $this->assertNotEmpty($relationships);
        $this->assertEquals('BELONGS_TO', $relationships[0]['type']);
    }

    /** @test */
    public function it_reads_nodeable_aliases_from_model_property(): void
    {
        // Disable runtime discovery so we only get model properties
        config(['ai.auto_discovery.runtime_enabled' => false]);

        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'persons';
            protected $fillable = ['name'];
            protected array $nodeableAliases = ['person', 'people', 'individual'];

            public function getResolvedConfig(): array
            {
                return $this->resolveConfig();
            }
        };

        $config = $model->getResolvedConfig();

        $this->assertEquals(
            ['person', 'people', 'individual'],
            $config['metadata']['aliases'] ?? []
        );
    }
}

// Test fixture for class name detection
class ModelWithoutLabel extends Model implements Nodeable
{
    use HasNodeableConfig;
    protected $table = 'test_models';
    protected $fillable = ['name'];
}
