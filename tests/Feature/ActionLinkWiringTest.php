<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;
use Kompo\Link;
use Mockery;

class ActionLinkWiringTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Set bootFlag to false so Query constructor doesn't try to boot
        // This allows testing methods in isolation without full Kompo boot
        $this->app->instance('bootFlag', false);
    }

    /** @test */
    public function it_creates_matching_class_patterns_for_wiring(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->with('Person', 'profile')
            ->andReturn(fn($id) => (new Link('View Profile'))->href("/person/{$id}"));
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(null);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        $query = new MessagesQuery();

        // Test processActionLinks produces correct visible element class
        $processMethod = new \ReflectionMethod($query, 'processActionLinks');
        $processMethod->setAccessible(true);

        $html = $processMethod->invoke($query, '[John](entity://Person/123/profile)');

        // Visible span should have js-action-entity-Person-123-profile (no -proxy)
        $this->assertStringContainsString('js-action-entity-Person-123-profile', $html);
        $this->assertStringNotContainsString('js-action-entity-Person-123-profile-proxy', $html);

        // Test extractActionLinkProxies produces correct proxy element class
        $extractMethod = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $extractMethod->setAccessible(true);

        $proxies = $extractMethod->invoke($query, '[John](entity://Person/123/profile)');

        $this->assertCount(1, $proxies);

        // Get the rendered proxy HTML
        $proxyElement = $proxies[0];

        // The proxy should have the -proxy suffix class
        // Check the element's class contains the proxy pattern (using public $class property)
        $elementClasses = $proxyElement->class ?? '';
        $this->assertStringContainsString('js-action-entity-Person-123-profile-proxy', $elementClasses);
        $this->assertStringContainsString('hidden', $elementClasses);
    }

    /** @test */
    public function it_handles_multiple_action_links_with_unique_classes(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id) => (new Link('View'))->href("/view/{$id}"));
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(null);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        $query = new MessagesQuery();

        $extractMethod = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $extractMethod->setAccessible(true);

        $content = '[John](entity://Person/123/profile) and [Jane](entity://Person/456/profile)';
        $proxies = $extractMethod->invoke($query, $content);

        // Should have 2 unique proxies
        $this->assertCount(2, $proxies);

        // Each should have unique class (using public $class property)
        $classes = array_map(fn($p) => $p->class ?? '', $proxies);
        $this->assertStringContainsString('Person-123-profile-proxy', $classes[0]);
        $this->assertStringContainsString('Person-456-profile-proxy', $classes[1]);
    }

    /** @test */
    public function it_deduplicates_same_action_links(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id) => (new Link('View'))->href("/view/{$id}"));
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(null);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        $query = new MessagesQuery();

        $extractMethod = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $extractMethod->setAccessible(true);

        // Same link twice
        $content = '[John](entity://Person/123/profile) [John Again](entity://Person/123/profile)';
        $proxies = $extractMethod->invoke($query, $content);

        // Should deduplicate to 1 proxy
        $this->assertCount(1, $proxies);
    }
}
