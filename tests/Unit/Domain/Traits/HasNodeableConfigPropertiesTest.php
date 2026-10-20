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

    }

    /** @test */
    public function it_reads_graph_label_from_model_property(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'people';
            protected $fillable = ['name'];
            protected string $graphLabel = 'Person';  // This overrides the nodeableConfig label

            public function nodeableConfig(): array
            {
                return [
                    'graph' => [
                        'label' => 'DefaultLabel',  // Will be overridden by $graphLabel
                        'properties' => ['id', 'name'],
                    ],
                ];
            }
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

            public function nodeableConfig(): array
            {
                return [
                    'graph' => [
                        'label' => 'Person',
                        'properties' => ['id', 'name', 'ssn', 'salary'],
                    ],
                ];
            }

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
            protected array $nodeableAliases = ['person', 'people'];  // Model property

            public function nodeableConfig(): array
            {
                return [
                    'graph' => [
                        'label' => 'CustomLabel',
                        'properties' => ['id', 'name', 'bio'],
                    ],
                ];
            }

            public function getResolvedConfig(): array
            {
                return $this->resolveConfig();
            }
        };

        $graphConfig = $model->getGraphConfig();
        $config = $model->getResolvedConfig();

        $this->assertEquals('CustomLabel', $graphConfig->label);  // From nodeableConfig
        $this->assertEquals(['person', 'people'], $config['metadata']['aliases'] ?? []);  // From property
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

            public function nodeableConfig(): array
            {
                return [
                    'graph' => [
                        'label' => 'Order',
                        'properties' => ['id', 'customer_id', 'total'],
                    ],
                ];
            }

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
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'persons';
            protected $fillable = ['name'];
            protected array $nodeableAliases = ['person', 'people', 'individual'];

            public function nodeableConfig(): array
            {
                return [
                    'graph' => [
                        'label' => 'Person',
                        'properties' => ['id', 'name'],
                    ],
                ];
            }

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

    /** @test */
    public function it_throws_exception_when_no_config_available(): void
    {
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;
            protected $table = 'test_models';
            protected $fillable = ['name'];
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No configuration found');
        $model->getGraphConfig();
    }
}

// Test fixture for class name detection
class ModelWithoutLabel extends Model implements Nodeable
{
    use HasNodeableConfig;
    protected $table = 'test_models';
    protected $fillable = ['name'];

    // Must provide base config
    public function nodeableConfig(): array
    {
        return [
            'graph' => [
                // Label intentionally omitted - will default to class name
                'properties' => ['id', 'name'],
            ],
        ];
    }
}
