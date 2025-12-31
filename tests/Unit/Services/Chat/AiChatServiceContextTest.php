<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Orchestra\Testbench\TestCase;

class AiChatServiceContextTest extends TestCase
{
    private AiChatService $service;

    /**
     * Don't load the full AI service provider to avoid API key requirements
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up AI config
        $app['config']->set('ai.response_generation.default_style', 'friendly');
        $app['config']->set('ai.chat.show_metrics', false);
        $app['config']->set('ai.chat.max_history_messages', 10);
        $app['config']->set('app.name', 'Test App');
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new AiChatService();
    }

    /** @test */
    public function it_has_ask_with_conversation_method(): void
    {
        $this->assertTrue(
            method_exists($this->service, 'askWithConversation'),
            'AiChatService should have askWithConversation method'
        );
    }

    /** @test */
    public function it_has_context_manager_property(): void
    {
        // Test that getContextManager returns a ConversationContextManager
        $manager = $this->service->getContextManager();

        $this->assertInstanceOf(ConversationContextManager::class, $manager);
    }

    /** @test */
    public function get_schema_for_context_returns_schema_from_options(): void
    {
        $customSchema = ['labels' => ['CustomEntity', 'AnotherEntity']];

        // Use reflection to test protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getSchemaForContext');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, ['schema' => $customSchema]);

        $this->assertEquals($customSchema, $result);
    }

    /** @test */
    public function context_manager_is_lazy_loaded(): void
    {
        // Create two services
        $service1 = new AiChatService();
        $service2 = new AiChatService();

        // Get context managers
        $manager1 = $service1->getContextManager();
        $manager2 = $service2->getContextManager();

        // They should be different instances (not shared)
        $this->assertNotSame($manager1, $manager2);

        // But calling again on same service returns same instance
        $manager1Again = $service1->getContextManager();
        $this->assertSame($manager1, $manager1Again);
    }
}
