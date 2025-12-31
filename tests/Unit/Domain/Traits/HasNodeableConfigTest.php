<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Domain\Traits;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Mockery;

/**
 * HasNodeableConfig Trait Test
 *
 * Tests the fallback chain for entity configuration:
 * 1. nodeableConfig() method
 * 2. config/entities.php
 * 3. Auto-discovery
 *
 * @package Condoedge\Ai\Tests\Unit\Domain\Traits
 */
class HasNodeableConfigTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_uses_nodeable_config_method_as_highest_priority()
    {
        // Create a test model with nodeableConfig() method
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];

            public function nodeableConfig(): array
            {
                return [
                    'graph' => [
                        'label' => 'TestNode',
                        'properties' => ['id', 'name'],
                        'relationships' => [],
                    ],
                    'vector' => [
                        'collection' => 'test_collection',
                        'embed_fields' => ['name'],
                        'metadata' => ['id'],
                    ],
                ];
            }
        };

        // Get graph config
        $graphConfig = $model->getGraphConfig();
        $this->assertInstanceOf(GraphConfig::class, $graphConfig);
        $this->assertEquals('TestNode', $graphConfig->label);

        // Get vector config
        $vectorConfig = $model->getVectorConfig();
        $this->assertInstanceOf(VectorConfig::class, $vectorConfig);
        $this->assertEquals('test_collection', $vectorConfig->collection);
    }

    /** @test */
    public function it_uses_nodeable_config_builder_from_method()
    {
        // Create a test model returning NodeableConfig builder
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];

            public function nodeableConfig(): NodeableConfig
            {
                return NodeableConfig::for(static::class)
                    ->label('BuilderNode')
                    ->properties('id', 'name')
                    ->collection('builder_collection')
                    ->embedFields('name');
            }
        };

        // Get graph config
        $graphConfig = $model->getGraphConfig();
        $this->assertEquals('BuilderNode', $graphConfig->label);

        // Get vector config
        $vectorConfig = $model->getVectorConfig();
        $this->assertEquals('builder_collection', $vectorConfig->collection);
    }

    /** @test */
    public function it_falls_back_to_config_entities_php_with_full_class_name()
    {
        // Create model without nodeableConfig() method
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];
        };

        // Manually set the class name in config (use actual anonymous class name)
        $className = get_class($model);

        // Set config using Laravel's Config facade (array syntax)
        $entities = config('entities', []);
        $entities[$className] = [
            'graph' => [
                'label' => 'ConfigNode',
                'properties' => ['id', 'name'],
                'relationships' => [],
            ],
        ];
        Config::set('entities', $entities);

        // Get graph config
        $graphConfig = $model->getGraphConfig();
        $this->assertEquals('ConfigNode', $graphConfig->label);
    }

    /** @test */
    public function it_falls_back_to_config_entities_php_with_short_name()
    {
        // Create model without nodeableConfig() method
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];

            protected function getConfigKey(): string
            {
                return 'TestModel';
            }
        };

        // Set config with short name
        Config::set('entities.TestModel', [
            'graph' => [
                'label' => 'ShortNameNode',
                'properties' => ['id', 'name'],
                'relationships' => [],
            ],
        ]);

        // Get graph config
        $graphConfig = $model->getGraphConfig();
        $this->assertEquals('ShortNameNode', $graphConfig->label);
    }

    /** @test */
    public function it_throws_runtime_exception_when_no_config_or_nodeableconfig_method()
    {
        // Create model without config - runtime auto-discovery is no longer supported
        // Models must either implement nodeableConfig() or be registered in config/entities.php
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name', 'email'];
        };

        // Should throw RuntimeException since there's no config
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No configuration found');
        $model->getGraphConfig();
    }

    /** @test */
    public function it_throws_exception_when_no_config_available()
    {
        Config::set('entities', []);

        // Create model without config
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];
        };

        // Get graph config - should throw RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No configuration found');
        $model->getGraphConfig();
    }

    /** @test */
    public function it_throws_exception_when_vector_config_not_configured()
    {
        // Create model with only graph config
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];

            public function nodeableConfig(): array
            {
                return [
                    'graph' => [
                        'label' => 'TestNode',
                        'properties' => ['id', 'name'],
                        'relationships' => [],
                    ],
                    // No vector config
                ];
            }
        };

        // Get vector config - should throw LogicException
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not vectorizable');
        $model->getVectorConfig();
    }

    /** @test */
    public function it_respects_config_priority_order()
    {
        // Set up config file
        Config::set('entities.TestModel', [
            'graph' => [
                'label' => 'ConfigNode',
                'properties' => ['id'],
                'relationships' => [],
            ],
        ]);

        // Mock auto-discovery (should not be called)
        $discoveryMock = Mockery::mock(EntityAutoDiscovery::class);
        $discoveryMock->shouldNotReceive('discover');
        $this->app->instance(EntityAutoDiscovery::class, $discoveryMock);

        // Create model with nodeableConfig() method
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];

            protected function getConfigKey(): string
            {
                return 'TestModel';
            }

            public function nodeableConfig(): array
            {
                return [
                    'graph' => [
                        'label' => 'MethodNode',
                        'properties' => ['id', 'name'],
                        'relationships' => [],
                    ],
                ];
            }
        };

        // Should use nodeableConfig() method (highest priority)
        $graphConfig = $model->getGraphConfig();
        $this->assertEquals('MethodNode', $graphConfig->label);
    }

    /** @test */
    public function it_caches_resolved_config()
    {
        // Create model with nodeableConfig() - test that config is cached
        $callCount = 0;
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];
            public static int $nodeableConfigCallCount = 0;

            public function nodeableConfig(): array
            {
                self::$nodeableConfigCallCount++;
                return [
                    'graph' => [
                        'label' => 'CachedNode',
                        'properties' => ['id', 'name'],
                        'relationships' => [],
                    ],
                ];
            }
        };

        // Call getGraphConfig() multiple times
        $graphConfig1 = $model->getGraphConfig();
        $graphConfig2 = $model->getGraphConfig();

        // Both should return same result
        $this->assertEquals('CachedNode', $graphConfig1->label);
        $this->assertEquals('CachedNode', $graphConfig2->label);
        // nodeableConfig should be called only once due to caching
        $this->assertEquals(1, $model::$nodeableConfigCallCount);
    }
}
