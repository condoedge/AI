<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Integration;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Tests\Fixtures\TestCustomer;
use Condoedge\Ai\Tests\Fixtures\TestOrder;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Discovery\PropertyDiscoverer;
use Condoedge\Ai\Services\Discovery\RelationshipDiscoverer;
use Condoedge\Ai\Services\Discovery\AliasGenerator;
use Condoedge\Ai\Services\Discovery\EmbedFieldDetector;

/**
 * Entity Auto-Discovery Integration Test
 *
 * Tests the complete auto-discovery functionality with real models.
 */
class EntityAutoDiscoveryTest extends TestCase
{
    private EntityAutoDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();

        // Get discovery service from container
        $this->discovery = $this->app->make(EntityAutoDiscovery::class);
    }

    /** @test */
    public function it_discovers_complete_configuration_for_customer()
    {
        $config = $this->discovery->discover(TestCustomer::class);

        // Assert graph configuration
        $this->assertArrayHasKey('graph', $config);
        $this->assertEquals('Customer', $config['graph']['label']);
        $this->assertContains('id', $config['graph']['properties']);
        $this->assertContains('name', $config['graph']['properties']);
        $this->assertContains('email', $config['graph']['properties']);

        // Assert vector configuration
        $this->assertArrayHasKey('vector', $config);
        $this->assertEquals('test_customers', $config['vector']['collection']);
        $this->assertIsArray($config['vector']['embed_fields']);
        $this->assertIsArray($config['vector']['metadata']);

        // Assert metadata
        $this->assertArrayHasKey('metadata', $config);
        $this->assertIsArray($config['metadata']['aliases']);
        $this->assertContains('customer', $config['metadata']['aliases']);
        $this->assertContains('customers', $config['metadata']['aliases']);
    }

    /** @test */
    public function it_discovers_relationships_for_customer()
    {
        $config = $this->discovery->discoverGraph(TestCustomer::class);

        $relationships = $config['relationships'];
        $this->assertNotEmpty($relationships);

        // Find orders relationship
        $ordersRel = collect($relationships)->first(function ($rel) {
            return strtolower($rel['target_label']) === 'order';
        });

        $this->assertNotNull($ordersRel, 'Should discover orders relationship');
    }

    /** @test */
    public function it_discovers_properties_correctly()
    {
        $propertyDiscoverer = $this->app->make(PropertyDiscoverer::class);
        $properties = $propertyDiscoverer->discover(TestCustomer::class);

        $this->assertContains('id', $properties);
        $this->assertContains('name', $properties);
        $this->assertContains('email', $properties);
        $this->assertContains('status', $properties);
        $this->assertContains('created_at', $properties);
        $this->assertContains('updated_at', $properties);
    }

    /** @test */
    public function it_generates_aliases_with_business_terms()
    {
        $aliasGenerator = $this->app->make(AliasGenerator::class);
        $aliases = $aliasGenerator->generate(TestCustomer::class);

        $this->assertContains('customer', $aliases);
        $this->assertContains('customers', $aliases);
        $this->assertContains('client', $aliases);
    }

    /** @test */
    public function it_discovers_scopes()
    {
        $config = $this->discovery->discoverMetadata(TestCustomer::class);

        $scopes = $config['scopes'];
        $this->assertIsArray($scopes);

        // Check if active scope was discovered
        if (isset($scopes['active'])) {
            $this->assertArrayHasKey('cypher_pattern', $scopes['active']);
        }
    }

    /** @test */
    public function it_merges_manual_configuration()
    {
        $manualConfig = [
            'metadata' => [
                'aliases' => ['special_customer'],
                'description' => 'Custom description',
            ],
        ];

        $merged = $this->discovery->discoverAndMerge(TestCustomer::class, $manualConfig);

        $this->assertEquals('Custom description', $merged['metadata']['description']);
        $this->assertContains('special_customer', $merged['metadata']['aliases']);
    }
}
