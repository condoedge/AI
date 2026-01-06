<?php

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

class ConversationContextManagerTest extends TestCase
{
    use RefreshDatabase;

    private ConversationContextManager $manager;

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
    }

    protected function defineDatabaseMigrations(): void
    {
        // Run migrations for the AI tables
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->manager = new ConversationContextManager(
            new EntityExtractor(),
            new ReferenceResolver()
        );
    }

    /** @test */
    public function it_processes_question_and_updates_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order']];

        $result = $this->manager->processQuestion(
            $conversation,
            'How many customers do we have?',
            $schema
        );

        $this->assertEquals('Customer', $result['focused_entity']);
        $this->assertEquals('count', $result['query_type']);

        // Context should be updated on conversation
        $conversation->refresh();
        $this->assertEquals('Customer', $conversation->getFocusedEntity());
    }

    /** @test */
    public function it_resolves_follow_up_questions(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'focused_entity' => 'Customer',
                'last_query_type' => 'count',
            ],
        ]);

        // Add a previous message with cypher
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'There are 150 customers.',
            'cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ]);

        $result = $this->manager->processQuestion(
            $conversation,
            'and those in Sales team?',
            ['labels' => ['Customer', 'Team']]
        );

        $this->assertTrue($result['is_follow_up']);
        $this->assertEquals('Customer', $result['resolved_entity']);
        $this->assertStringContainsString('Customer', $result['enriched_question']);
    }

    /** @test */
    public function it_records_response_and_updates_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->manager->recordResponse(
            $conversation,
            'There are 150 customers.',
            'MATCH (c:Customer) RETURN count(c) as count',
            ['data' => [['count' => 150]]]
        );

        $conversation->refresh();

        $this->assertEquals('Customer', $conversation->getFocusedEntity());
        $this->assertContains('Customer', $conversation->getMentionedEntities());
    }

    /** @test */
    public function it_builds_context_for_prompt(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'focused_entity' => 'Customer',
                'mentioned_entities' => ['Customer', 'Order'],
                'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
                'last_result_count' => 150,
            ],
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'How many customers?',
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => '150 customers',
            'cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ]);

        $promptContext = $this->manager->buildPromptContext($conversation);

        $this->assertEquals('Customer', $promptContext['focused_entity']);
        $this->assertNotNull($promptContext['last_cypher_query']);
        $this->assertArrayHasKey('recent_exchanges', $promptContext);
    }

    /** @test */
    public function it_limits_conversation_history(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        // Create 20 messages
        for ($i = 0; $i < 20; $i++) {
            $conversation->messages()->create([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        $promptContext = $this->manager->buildPromptContext($conversation, maxHistory: 5);

        $this->assertCount(5, $promptContext['recent_exchanges']);
    }

    /** @test */
    public function it_stores_detected_template_in_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->manager->recordResponse(
            $conversation,
            'There are 150 customers.',
            'MATCH (c:Customer) RETURN count(c)',
            [
                'data' => [['count' => 150]],
                'detected_template' => 'count',
                'query_type' => 'aggregation',
            ]
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        $this->assertEquals('count', $snapshot['last_detected_template']);
        $this->assertEquals('aggregation', $snapshot['last_query_type']);
    }

    /** @test */
    public function it_persists_available_actions_in_context_snapshot(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'title' => 'Test',
        ]);

        $queryResult = [
            'data' => [
                ['id' => '123', 'name' => 'John', '_label' => 'Person'],
            ],
            'available_actions' => [
                'Person' => [
                    ['action_key' => 'profile', 'label' => 'View Profile'],
                    ['action_key' => 'edit', 'label' => 'Edit'],
                ],
            ],
        ];

        $this->manager->recordResponse(
            $conversation,
            'Here is John.',
            'MATCH (p:Person) RETURN p',
            $queryResult
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        $this->assertArrayHasKey('last_available_actions', $snapshot);
        $this->assertArrayHasKey('Person', $snapshot['last_available_actions']);
        $this->assertCount(2, $snapshot['last_available_actions']['Person']);
    }

    /** @test */
    public function it_preserves_actions_when_new_response_has_none(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'title' => 'Test',
            'context_snapshot' => [
                'last_available_actions' => [
                    'Person' => [['action_key' => 'profile', 'label' => 'View Profile']],
                ],
            ],
        ]);

        // New response has no actions
        $this->manager->recordResponse(
            $conversation,
            'Count is 5.',
            'MATCH (p:Person) RETURN count(p)',
            ['data' => [['count' => 5]]]
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        // Previous actions should be preserved
        $this->assertArrayHasKey('last_available_actions', $snapshot);
        $this->assertArrayHasKey('Person', $snapshot['last_available_actions']);
    }
}
