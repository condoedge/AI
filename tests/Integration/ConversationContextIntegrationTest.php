<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Integration;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\PromptSections\ConversationContextSection;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use Condoedge\Ai\Tests\TestCase;

/**
 * Integration tests for Conversation Context functionality
 *
 * Tests the full integration of:
 * - ConversationContextSection in prompt building
 * - ConversationContextManager conversation flow
 * - AiConversation context tracking
 */
class ConversationContextIntegrationTest extends TestCase
{
    protected ConversationContextManager $contextManager;
    protected SemanticPromptBuilder $promptBuilder;

    public function setUp(): void
    {
        parent::setUp();

        // Set up database schema for conversation models
        $this->setUpDatabase();

        // Disable mass assignment protection for tests
        AiConversation::unguard();
        AiMessage::unguard();

        // Create context manager
        $this->contextManager = new ConversationContextManager(new EntityExtractor());

        // Create prompt builder
        $this->promptBuilder = new SemanticPromptBuilder();
    }

    public function tearDown(): void
    {
        AiConversation::reguard();
        AiMessage::reguard();
        parent::tearDown();
    }

    /**
     * Set up the database schema for conversation models
     */
    protected function setUpDatabase(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('ai_conversations', function ($table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('title')->nullable();
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->json('context_snapshot')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('ai_messages', function ($table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->string('role');
            $table->text('content');
            $table->json('response_data')->nullable();
            $table->json('context_used')->nullable();
            $table->text('cypher_query')->nullable();
            $table->integer('execution_time_ms')->nullable();
            $table->float('confidence_score')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /** @test */
    public function it_includes_conversation_context_section_in_registered_sections(): void
    {
        // Get the registered query generator sections from config
        $sections = config('ai.query_generator_sections', []);

        // Convert class names to check for our section
        $sectionClasses = array_map(function ($section) {
            if (is_string($section)) {
                return $section;
            }
            return null;
        }, $sections);

        $this->assertContains(
            ConversationContextSection::class,
            $sectionClasses,
            'ConversationContextSection should be registered in query_generator_sections'
        );
    }

    /** @test */
    public function it_includes_conversation_context_in_prompt(): void
    {
        // Build prompt with conversation context carried on a real PipelineContext.
        $context = new \Condoedge\Ai\DTOs\PipelineContext();
        $context->graphSchema = [
            'labels' => ['Customer', 'Order', 'Product'],
            'relationships' => ['PURCHASED', 'ORDERED'],
        ];
        $context->conversationContext = [
            'focused_entity' => 'Customer',
            'mentioned_entities' => ['Customer', 'Order'],
            'last_query_type' => 'list',
            'recent_exchanges' => [
                [
                    'question' => 'Show me all customers',
                    'answer_summary' => 'Here are all customers...',
                ],
            ],
        ];

        ['user' => $user] = $this->promptBuilder->buildPrompts(
            'Show me those who ordered last month',
            $context,
            false
        );

        // The conversation context block (user-role) is present.
        $this->assertStringContainsString(
            'Conversation Context',
            $user,
            'Prompt should include the Conversation Context section'
        );

        // Focused entity is in the prompt.
        $this->assertStringContainsString('Customer', $user, 'Prompt should include the focused entity');

        // Recent exchange content is in the prompt.
        $this->assertStringContainsString(
            'Show me all customers',
            $user,
            'Prompt should include recent exchange content'
        );
    }

    /** @test */
    public function it_does_not_include_conversation_context_when_empty(): void
    {
        // Build prompt without any conversation context.
        $context = new \Condoedge\Ai\DTOs\PipelineContext();
        $context->graphSchema = [
            'labels' => ['Customer', 'Order'],
            'relationships' => ['PURCHASED'],
        ];

        ['user' => $user] = $this->promptBuilder->buildPrompts(
            'How many customers do we have?',
            $context,
            false
        );

        // The Conversation Context block is omitted when there is no conversation context.
        $this->assertStringNotContainsString(
            'Conversation Context',
            $user,
            'Prompt should not include Conversation Context when context is empty'
        );
    }

    /** @test */
    public function full_conversation_flow_works(): void
    {
        // Create a conversation
        $conversation = AiConversation::create([
            'uuid' => 'test-uuid-' . uniqid(),
            'user_id' => 1,
            'status' => 'active',
        ]);

        // First question (no pre-pipeline analysis: the snapshot is written only
        // after the turn executed — Phase 2.B)
        $this->assertNull($conversation->getFocusedEntity());

        // Add message to conversation
        $conversation->addMessage('user', 'Show me all customers');
        $conversation->addMessage('assistant', 'Here are all customers...', [
            'cypher_query' => 'MATCH (c:Customer) RETURN c',
        ]);

        // Record the response
        $this->contextManager->recordResponse(
            $conversation,
            'Here are all customers...',
            'MATCH (c:Customer) RETURN c',
            ['data' => [['name' => 'John'], ['name' => 'Jane']]]
        );

        // Verify context was updated
        $conversation->refresh();
        $this->assertEquals('Customer', $conversation->getFocusedEntity());
        $this->assertEquals('MATCH (c:Customer) RETURN c', $conversation->context_snapshot['last_cypher_query'] ?? null);
        $this->assertContains('Customer', $conversation->getMentionedEntities());

        // Second question ("Show me those who ordered last month"): the prompt
        // context it receives is what the previous turn RECORDED. No substring
        // guess ("ordered" ⊃ "Order") shifts the focus before QU runs.
        $promptContext = $this->contextManager->buildPromptContext($conversation);

        $this->assertArrayHasKey('focused_entity', $promptContext);
        $this->assertArrayHasKey('mentioned_entities', $promptContext);
        $this->assertArrayHasKey('last_cypher_query', $promptContext);
        $this->assertArrayHasKey('recent_exchanges', $promptContext);
        $this->assertEquals('Customer', $promptContext['focused_entity']);

        // The second turn's executed query moves the focus.
        $this->contextManager->recordResponse(
            $conversation,
            'Here are the orders...',
            'MATCH (c:Customer)-[:PURCHASED]->(o:Order) RETURN o',
            ['data' => [['id' => 1]]]
        );
        $conversation->refresh();
        $this->assertEquals('Customer', $conversation->getFocusedEntity(), 'first label of the executed cypher');
        $this->assertContains('Order', $conversation->getMentionedEntities());
    }

    /** @test */
    public function conversation_context_section_has_correct_priority(): void
    {
        $section = new ConversationContextSection();

        // Priority should be 55 (after SimilarQueriesSection at 50, before DetectedScopesSection at 65)
        $priority = $section->getPriority();

        $this->assertEquals(55, $priority, 'ConversationContextSection should have priority 55');
    }

    /** @test */
    public function conversation_context_section_only_includes_when_context_present(): void
    {
        $section = new ConversationContextSection();

        // Without conversation context.
        $this->assertFalse(
            $section->shouldInclude('Test question', $this->pipelineContextWith([]), [])
        );

        // With empty conversation context.
        $this->assertFalse(
            $section->shouldInclude('Test question', $this->pipelineContextWith([]), [])
        );

        // With focused_entity.
        $this->assertTrue(
            $section->shouldInclude('Test question', $this->pipelineContextWith(['focused_entity' => 'Customer']), [])
        );

        // With recent_exchanges.
        $this->assertTrue(
            $section->shouldInclude(
                'Test question',
                $this->pipelineContextWith(['recent_exchanges' => [['question' => 'test']]]),
                []
            )
        );
    }

    /** @test */
    public function conversation_context_section_formats_correctly(): void
    {
        $section = new ConversationContextSection();

        $context = $this->pipelineContextWith([
            'focused_entity' => 'Customer',
            'last_query_type' => 'list',
            'mentioned_entities' => ['Customer', 'Order'],
            'recent_exchanges' => [
                [
                    'question' => 'Show me all customers',
                    'answer_summary' => 'Here are the customers',
                ],
            ],
        ]);

        $formatted = $section->format('Show me those who ordered', $context, []);

        // Header.
        $this->assertStringContainsString('Conversation Context', $formatted);

        // Focused entity.
        $this->assertStringContainsString('Current Focus:', $formatted);
        $this->assertStringContainsString('Customer', $formatted);

        // Recent exchanges.
        $this->assertStringContainsString('Recent Conversation:', $formatted);
        $this->assertStringContainsString('Show me all customers', $formatted);

        // Mentioned entities.
        $this->assertStringContainsString('Entities discussed:', $formatted);
    }

    /**
     * Build a PipelineContext carrying the given conversation context array.
     */
    private function pipelineContextWith(array $conversationContext): \Condoedge\Ai\DTOs\PipelineContext
    {
        $context = new \Condoedge\Ai\DTOs\PipelineContext();
        $context->conversationContext = $conversationContext;

        return $context;
    }

    /** @test */
    public function ai_conversation_model_tracks_context_correctly(): void
    {
        $conversation = AiConversation::create([
            'uuid' => 'test-uuid-' . uniqid(),
            'user_id' => 1,
            'status' => 'active',
        ]);

        // Initially no context
        $this->assertNull($conversation->getFocusedEntity());
        $this->assertEmpty($conversation->getMentionedEntities());

        // Update context snapshot
        $conversation->updateContextSnapshot([
            'focused_entity' => 'Order',
            'mentioned_entities' => ['Order', 'Customer'],
            'last_query_type' => 'count',
        ]);

        $conversation->refresh();

        $this->assertEquals('Order', $conversation->getFocusedEntity());
        $this->assertEquals(['Order', 'Customer'], $conversation->getMentionedEntities());
        $this->assertEquals('count', $conversation->context_snapshot['last_query_type'] ?? null);

        // Add message with cypher query
        $message = $conversation->addMessage('assistant', 'There are 42 orders', [
            'cypher_query' => 'MATCH (o:Order) RETURN count(o)',
        ]);

        $this->assertEquals('MATCH (o:Order) RETURN count(o)', $message->fresh()->cypher_query);
    }
}
