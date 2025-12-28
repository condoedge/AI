<?php

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Tests\TestCase;

class ServiceProviderRegistrationTest extends TestCase
{
    /** @test */
    public function it_registers_entity_extractor(): void
    {
        $extractor = app(EntityExtractor::class);
        $this->assertInstanceOf(EntityExtractor::class, $extractor);
    }

    /** @test */
    public function it_registers_reference_resolver(): void
    {
        $resolver = app(ReferenceResolver::class);
        $this->assertInstanceOf(ReferenceResolver::class, $resolver);
    }

    /** @test */
    public function it_registers_conversation_context_manager(): void
    {
        $manager = app(ConversationContextManager::class);
        $this->assertInstanceOf(ConversationContextManager::class, $manager);
    }

    /** @test */
    public function it_uses_singleton_for_context_manager(): void
    {
        $manager1 = app(ConversationContextManager::class);
        $manager2 = app(ConversationContextManager::class);
        $this->assertSame($manager1, $manager2);
    }

    /** @test */
    public function it_uses_singleton_for_entity_extractor(): void
    {
        $extractor1 = app(EntityExtractor::class);
        $extractor2 = app(EntityExtractor::class);
        $this->assertSame($extractor1, $extractor2);
    }

    /** @test */
    public function it_uses_singleton_for_reference_resolver(): void
    {
        $resolver1 = app(ReferenceResolver::class);
        $resolver2 = app(ReferenceResolver::class);
        $this->assertSame($resolver1, $resolver2);
    }

    /** @test */
    public function it_injects_dependencies_into_context_manager(): void
    {
        $manager = app(ConversationContextManager::class);

        // Use reflection to verify dependencies were injected
        $reflection = new \ReflectionClass($manager);

        $extractorProperty = $reflection->getProperty('entityExtractor');
        $extractorProperty->setAccessible(true);
        $extractor = $extractorProperty->getValue($manager);

        $resolverProperty = $reflection->getProperty('referenceResolver');
        $resolverProperty->setAccessible(true);
        $resolver = $resolverProperty->getValue($manager);

        $this->assertInstanceOf(EntityExtractor::class, $extractor);
        $this->assertInstanceOf(ReferenceResolver::class, $resolver);
    }
}
