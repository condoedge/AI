<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * Tests for MessagesQuery action link proxy extraction error handling.
 *
 * These tests verify that extractActionLinkProxies() gracefully handles
 * resolver exceptions and logs failures appropriately.
 */
class MessagesQueryActionLinksTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Don't load the full AI service provider to avoid API key requirements
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    /** @test */
    public function it_handles_resolver_exceptions_gracefully(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(function ($id) {
                throw new \RuntimeException('Resolver failed');
            });
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(null);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        // Create testable subclass that bypasses Kompo initialization
        $query = new TestableMessagesQueryForActionLinks();

        // Use reflection to test protected method
        $method = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $method->setAccessible(true);

        $content = '[John](entity://Person/123/profile)';

        // Should not throw, should return empty array
        $proxies = $method->invoke($query, $content);

        $this->assertIsArray($proxies);
        $this->assertEmpty($proxies);
    }

    /** @test */
    public function it_logs_resolver_failures(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(function ($id) {
                throw new \RuntimeException('Test error');
            });
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(null);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        \Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Action link resolver failed')
                    && $context['entity_type'] === 'Person'
                    && $context['entity_id'] === '123';
            });

        $query = new TestableMessagesQueryForActionLinks();
        $method = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $method->setAccessible(true);

        $method->invoke($query, '[John](entity://Person/123/profile)');

        // Mockery will verify the Log::warning expectation
        $this->assertTrue(true);
    }

    /** @test */
    public function it_handles_generic_action_resolver_exceptions_gracefully(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(null);
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(function () {
                throw new \RuntimeException('Generic resolver failed');
            });

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        // Create testable subclass
        $query = new TestableMessagesQueryForActionLinks();
        $method = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $method->setAccessible(true);

        $content = '[Create Report](action://create-report)';

        // Should not throw, should return empty array
        $proxies = $method->invoke($query, $content);

        $this->assertIsArray($proxies);
        $this->assertEmpty($proxies);
    }

    /** @test */
    public function it_logs_generic_action_resolver_failures(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(null);
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(function () {
                throw new \RuntimeException('Generic test error');
            });

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        \Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Action link resolver failed')
                    && $context['action_key'] === 'create-report';
            });

        $query = new TestableMessagesQueryForActionLinks();
        $method = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $method->setAccessible(true);

        $method->invoke($query, '[Create Report](action://create-report)');

        // Mockery will verify the Log::warning expectation
        $this->assertTrue(true);
    }

    /** @test */
    public function it_continues_processing_other_links_after_failure(): void
    {
        // Create a mock element that will be returned by the second resolver
        $mockElement = Mockery::mock();
        $mockElement->shouldReceive('class')
            ->with(Mockery::type('string'))
            ->andReturnSelf();

        $callCount = 0;
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturnUsing(function ($entityType, $actionKey) use (&$callCount, $mockElement) {
                $callCount++;
                if ($callCount === 1) {
                    // First resolver throws
                    return function ($id) {
                        throw new \RuntimeException('First resolver failed');
                    };
                }
                // Second resolver returns valid element
                return function ($id) use ($mockElement) {
                    return $mockElement;
                };
            });
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(null);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        // Suppress the expected log warning
        \Log::shouldReceive('warning')->once();

        $query = new TestableMessagesQueryForActionLinks();
        $method = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $method->setAccessible(true);

        // Two entity links - first should fail, second should succeed
        $content = '[John](entity://Person/123/profile) and [Jane](entity://Person/456/profile)';

        $proxies = $method->invoke($query, $content);

        $this->assertIsArray($proxies);
        // Second resolver should have succeeded, so we should have 1 proxy
        $this->assertCount(1, $proxies);
    }
}

/**
 * Testable subclass that bypasses Kompo initialization for action link tests.
 */
class TestableMessagesQueryForActionLinks extends MessagesQuery
{
    public function __construct()
    {
        // Don't call parent constructor to avoid Kompo boot
    }

    public function settings(): ChatSettingsInterface
    {
        return Mockery::mock(ChatSettingsInterface::class);
    }
}
