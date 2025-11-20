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
        $entities = config('ai.entities', []);
        $entities[$className] = [
            'graph' => [
                'label' => 'ConfigNode',
                'properties' => ['id', 'name'],
                'relationships' => [],
            ],
        ];
        Config::set('ai.entities', $entities);

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
        Config::set('ai.entities.TestModel', [
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
    public function it_uses_auto_discovery_as_fallback()
    {
        // Mock auto-discovery service
        $discoveryMock = Mockery::mock(EntityAutoDiscovery::class);
        $discoveryMock->shouldReceive('discover')
            ->once()
            ->andReturn([
                'graph' => [
                    'label' => 'DiscoveredNode',
                    'properties' => ['id', 'name', 'email'],
                    'relationships' => [],
                ],
                'vector' => [
                    'collection' => 'discovered_collection',
                    'embed_fields' => ['name'],
                    'metadata' => ['id', 'email'],
                ],
            ]);

        // Bind mock to container
        $this->app->instance(EntityAutoDiscovery::class, $discoveryMock);

        // Enable auto-discovery
        Config::set('ai.auto_discovery.enabled', true);

        // Create model without config
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name', 'email'];
        };

        // Get graph config - should use auto-discovery
        $graphConfig = $model->getGraphConfig();
        $this->assertEquals('DiscoveredNode', $graphConfig->label);

        // Get vector config
        $vectorConfig = $model->getVectorConfig();
        $this->assertEquals('discovered_collection', $vectorConfig->collection);
    }

    /** @test */
    public function it_allows_customizing_discovery()
    {
        // Mock auto-discovery service
        $discoveryMock = Mockery::mock(EntityAutoDiscovery::class);
        $discoveryMock->shouldReceive('discover')
            ->once()
            ->andReturn([
                'graph' => [
                    'label' => 'DiscoveredNode',
                    'properties' => ['id', 'name'],
                    'relationships' => [],
                ],
                'metadata' => [
                    'aliases' => ['test', 'model'],
                ],
            ]);

        // Bind mock to container
        $this->app->instance(EntityAutoDiscovery::class, $discoveryMock);

        // Enable auto-discovery
        Config::set('ai.auto_discovery.enabled', true);

        // Create model with customizeDiscovery() method
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];

            public function customizeDiscovery(NodeableConfig $config): NodeableConfig
            {
                return $config->addAlias('custom_alias');
            }
        };

        // Get config - should use auto-discovery + customization
        $graphConfig = $model->getGraphConfig();
        $this->assertEquals('DiscoveredNode', $graphConfig->label);
    }

    /** @test */
    public function it_returns_empty_config_when_auto_discovery_disabled()
    {
        // Disable auto-discovery
        Config::set('ai.auto_discovery.enabled', false);
        Config::set('ai.entities', []);

        // Create model without config
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];
        };

        // Get graph config - should throw exception due to empty properties
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Graph properties cannot be empty');
        $graphConfig = $model->getGraphConfig();
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
        Config::set('ai.entities.TestModel', [
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
    public function it_caches_auto_discovery_results()
    {
        // Mock auto-discovery service - should only be called once
        $discoveryMock = Mockery::mock(EntityAutoDiscovery::class);
        $discoveryMock->shouldReceive('discover')
            ->once() // Only once due to caching
            ->andReturn([
                'graph' => [
                    'label' => 'CachedNode',
                    'properties' => ['id', 'name'],
                    'relationships' => [],
                ],
            ]);

        // Bind mock to container
        $this->app->instance(EntityAutoDiscovery::class, $discoveryMock);

        // Enable auto-discovery
        Config::set('ai.auto_discovery.enabled', true);

        // Create model without config
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];
        };

        // Call getGraphConfig() multiple times
        $graphConfig1 = $model->getGraphConfig();
        $graphConfig2 = $model->getGraphConfig();

        // Both should return same result (from cache)
        $this->assertEquals('CachedNode', $graphConfig1->label);
        $this->assertEquals('CachedNode', $graphConfig2->label);
    }

    /** @test */
    public function it_handles_missing_auto_discovery_service_gracefully()
    {
        // Mock auto-discovery to return empty array (simulating service failure)
        $discoveryMock = Mockery::mock(EntityAutoDiscovery::class);
        $discoveryMock->shouldReceive('discover')
            ->once()
            ->andReturn([]);

        $this->app->instance(EntityAutoDiscovery::class, $discoveryMock);

        Config::set('ai.auto_discovery.enabled', true);
        Config::set('ai.entities', []);

        // Create model without config
        $model = new class extends Model implements Nodeable {
            use HasNodeableConfig;

            protected $table = 'test_models';
            protected $fillable = ['name'];
        };

        // Should throw exception due to empty properties when auto-discovery returns empty array
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Graph properties cannot be empty');
        $graphConfig = $model->getGraphConfig();
    }
}
