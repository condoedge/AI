<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Services\Context\FileAccessResolver;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Files\PhysicalFileIndexer;
use Condoedge\Ai\Services\Response\ResponseFileEnricher;
use Condoedge\Ai\Tests\TestCase;

/**
 * Tests for file context service registrations in AiServiceProvider
 */
class ServiceProviderFileContextTest extends TestCase
{
    /**
     * Define environment setup with mock API keys for testing.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Set mock API keys for testing service resolution
        $app['config']->set('ai.embedding.openai.api_key', 'test-openai-key');
        $app['config']->set('ai.llm.openai.api_key', 'test-openai-key');
    }

    /** @test */
    public function it_resolves_file_access_resolver_interface_to_file_access_resolver(): void
    {
        $resolver = app(FileAccessResolverInterface::class);

        $this->assertInstanceOf(FileAccessResolver::class, $resolver);
    }

    /** @test */
    public function it_registers_file_access_resolver_as_singleton(): void
    {
        $resolver1 = app(FileAccessResolver::class);
        $resolver2 = app(FileAccessResolver::class);

        $this->assertSame($resolver1, $resolver2);
    }

    /** @test */
    public function it_uses_same_instance_for_interface_and_concrete(): void
    {
        $fromInterface = app(FileAccessResolverInterface::class);
        $fromConcrete = app(FileAccessResolver::class);

        $this->assertSame($fromInterface, $fromConcrete);
    }

    /** @test */
    public function it_registers_file_context_provider(): void
    {
        $provider = app(FileContextProvider::class);

        $this->assertInstanceOf(FileContextProvider::class, $provider);
    }

    /** @test */
    public function it_registers_file_context_provider_as_singleton(): void
    {
        $provider1 = app(FileContextProvider::class);
        $provider2 = app(FileContextProvider::class);

        $this->assertSame($provider1, $provider2);
    }

    /** @test */
    public function it_registers_physical_file_indexer(): void
    {
        $indexer = app(PhysicalFileIndexer::class);

        $this->assertInstanceOf(PhysicalFileIndexer::class, $indexer);
    }

    /** @test */
    public function it_registers_physical_file_indexer_as_singleton(): void
    {
        $indexer1 = app(PhysicalFileIndexer::class);
        $indexer2 = app(PhysicalFileIndexer::class);

        $this->assertSame($indexer1, $indexer2);
    }

    /** @test */
    public function it_registers_response_file_enricher(): void
    {
        $enricher = app(ResponseFileEnricher::class);

        $this->assertInstanceOf(ResponseFileEnricher::class, $enricher);
    }

    /** @test */
    public function it_registers_response_file_enricher_as_singleton(): void
    {
        $enricher1 = app(ResponseFileEnricher::class);
        $enricher2 = app(ResponseFileEnricher::class);

        $this->assertSame($enricher1, $enricher2);
    }

    /** @test */
    public function it_injects_correct_dependencies_into_file_context_provider(): void
    {
        $provider = app(FileContextProvider::class);

        // Use reflection to verify dependencies were injected
        $reflection = new \ReflectionClass($provider);

        $searchServiceProperty = $reflection->getProperty('searchService');
        $searchServiceProperty->setAccessible(true);
        $searchService = $searchServiceProperty->getValue($provider);

        $accessResolverProperty = $reflection->getProperty('accessResolver');
        $accessResolverProperty->setAccessible(true);
        $accessResolver = $accessResolverProperty->getValue($provider);

        $this->assertInstanceOf(
            \Condoedge\Ai\Services\FileSearchService::class,
            $searchService
        );
        $this->assertInstanceOf(
            FileAccessResolverInterface::class,
            $accessResolver
        );
    }

    /** @test */
    public function it_includes_file_context_services_in_provides(): void
    {
        $provider = app()->getProvider(\Condoedge\Ai\AiServiceProvider::class);
        $provides = $provider->provides();

        $expectedServices = [
            FileAccessResolverInterface::class,
            FileAccessResolver::class,
            FileContextProvider::class,
            PhysicalFileIndexer::class,
            ResponseFileEnricher::class,
        ];

        foreach ($expectedServices as $service) {
            $this->assertContains(
                $service,
                $provides,
                "Service {$service} should be in provides() array"
            );
        }
    }
}
