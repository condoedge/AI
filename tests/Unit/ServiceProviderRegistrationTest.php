<?php

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\Services\Context\EntityExtractor;
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
    public function it_injects_dependencies_into_context_manager(): void
    {
        $manager = app(ConversationContextManager::class);

        // Use reflection to verify dependencies were injected
        $reflection = new \ReflectionClass($manager);

        $extractorProperty = $reflection->getProperty('entityExtractor');
        $extractorProperty->setAccessible(true);
        $extractor = $extractorProperty->getValue($manager);

        $this->assertInstanceOf(EntityExtractor::class, $extractor);
        $this->assertFalse($reflection->hasProperty('referenceResolver'), 'the regex ReferenceResolver is gone (Phase 2.B)');
    }
}
