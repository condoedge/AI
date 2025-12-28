<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\AiChatMessage;
use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

class AiChatServiceContextTest extends TestCase
{
    use RefreshDatabase;

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
        // Set up in-memory SQLite database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set up AI config
        $app['config']->set('ai.response_generation.default_style', 'friendly');
        $app['config']->set('ai.chat.show_metrics', false);
        $app['config']->set('ai.chat.max_history_messages', 10);
        $app['config']->set('app.name', 'Test App');
    }

    protected function defineDatabaseMigrations(): void
    {
        // Run migrations for the AI tables
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
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
    public function it_has_prepare_question_with_context_method(): void
    {
        $this->assertTrue(
            method_exists($this->service, 'prepareQuestionWithContext'),
            'AiChatService should have prepareQuestionWithContext method'
        );
    }

    /** @test */
    public function prepare_question_with_context_enriches_follow_up_questions(): void
    {
        // Set up a conversation with context
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'focused_entity' => 'Customer',
                'mentioned_entities' => ['Customer'],
                'last_query_type' => 'count',
            ],
        ]);

        // Add a previous message
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'There are 150 customers.',
            'cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ]);

        $schema = ['labels' => ['Customer', 'Order', 'Product']];

        // Test follow-up question enrichment
        $result = $this->service->prepareQuestionWithContext(
            'Show me those in the Sales team',
            $conversation,
            $schema
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('enriched_question', $result);
        $this->assertArrayHasKey('is_follow_up', $result);
        $this->assertTrue($result['is_follow_up']);
        // The enriched question should reference Customer since that's the context
        // Note: ReferenceResolver uses lowercase entity names when replacing pronouns
        $this->assertStringContainsStringIgnoringCase('customer', $result['enriched_question']);
    }

    /** @test */
    public function prepare_question_with_context_returns_original_for_non_follow_up(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order']];

        $question = 'How many orders do we have?';
        $result = $this->service->prepareQuestionWithContext(
            $question,
            $conversation,
            $schema
        );

        $this->assertFalse($result['is_follow_up']);
        $this->assertEquals($question, $result['enriched_question']);
    }

    /** @test */
    public function it_has_context_manager_property(): void
    {
        // Test that getContextManager returns a ConversationContextManager
        $manager = $this->service->getContextManager();

        $this->assertInstanceOf(ConversationContextManager::class, $manager);
    }

    /** @test */
    public function prepare_question_with_context_returns_required_keys(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order']];

        $result = $this->service->prepareQuestionWithContext(
            'How many customers?',
            $conversation,
            $schema
        );

        $this->assertArrayHasKey('is_follow_up', $result);
        $this->assertArrayHasKey('enriched_question', $result);
        $this->assertArrayHasKey('focused_entity', $result);
        $this->assertArrayHasKey('query_type', $result);
        $this->assertArrayHasKey('context', $result);
    }

    /** @test */
    public function prepare_question_with_context_detects_entity_focus(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order', 'Product']];

        $result = $this->service->prepareQuestionWithContext(
            'How many customers do we have?',
            $conversation,
            $schema
        );

        $this->assertEquals('Customer', $result['focused_entity']);
        $this->assertEquals('count', $result['query_type']);
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
