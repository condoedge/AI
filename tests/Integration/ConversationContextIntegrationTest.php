<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Integration;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Services\PatternLibrary;
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
        $this->contextManager = new ConversationContextManager(
            new EntityExtractor(),
            new ReferenceResolver()
        );

        // Create prompt builder
        $this->promptBuilder = new SemanticPromptBuilder(new PatternLibrary());
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
        // Build prompt with conversation_context
        $conversationContext = [
            'focused_entity' => 'Customer',
            'mentioned_entities' => ['Customer', 'Order'],
            'last_query_type' => 'list',
            'recent_exchanges' => [
                [
                    'user' => ['content' => 'Show me all customers'],
                    'assistant' => [
                        'content' => 'Here are all customers...',
                        'cypher_query' => 'MATCH (c:Customer) RETURN c',
                    ],
                ],
            ],
        ];

        $context = [
            'schema' => [
                'labels' => ['Customer', 'Order', 'Product'],
                'relationships' => ['PURCHASED', 'ORDERED'],
            ],
            'conversation_context' => $conversationContext,
        ];

        $prompt = $this->promptBuilder->buildPrompt(
            'Show me those who ordered last month',
            $context,
            false
        );

        // Assert CONVERSATION CONTEXT header is in prompt
        $this->assertStringContainsString(
            'CONVERSATION CONTEXT',
            $prompt,
            'Prompt should include CONVERSATION CONTEXT section'
        );

        // Assert focused entity is in prompt
        $this->assertStringContainsString(
            'Customer',
            $prompt,
            'Prompt should include the focused entity'
        );

        // Assert recent exchange content is in prompt
        $this->assertStringContainsString(
            'Show me all customers',
            $prompt,
            'Prompt should include recent exchange content'
        );
    }

    /** @test */
    public function it_does_not_include_conversation_context_when_empty(): void
    {
        // Build prompt without conversation_context
        $context = [
            'schema' => [
                'labels' => ['Customer', 'Order'],
                'relationships' => ['PURCHASED'],
            ],
        ];

        $prompt = $this->promptBuilder->buildPrompt(
            'How many customers do we have?',
            $context,
            false
        );

        // Assert CONVERSATION CONTEXT is NOT in prompt when context is empty
        $this->assertStringNotContainsString(
            'CONVERSATION CONTEXT',
            $prompt,
            'Prompt should not include CONVERSATION CONTEXT when context is empty'
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

        $schema = [
            'labels' => ['Customer', 'Order', 'Product'],
            'relationships' => ['PURCHASED', 'ORDERED'],
        ];

        // First question
        $result1 = $this->contextManager->processQuestion(
            $conversation,
            'Show me all customers',
            $schema
        );

        $this->assertFalse($result1['is_follow_up']);
        $this->assertEquals('Customer', $result1['focused_entity']);

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
        $this->assertEquals('MATCH (c:Customer) RETURN c', $conversation->getLastCypherQuery());
        $this->assertContains('Customer', $conversation->getMentionedEntities());

        // Second question (follow-up) - "ordered" contains "Order" so focus shifts
        $result2 = $this->contextManager->processQuestion(
            $conversation,
            'Show me those who ordered last month',
            $schema
        );

        $this->assertTrue($result2['is_follow_up']);

        // Build prompt context
        $promptContext = $this->contextManager->buildPromptContext($conversation);

        $this->assertArrayHasKey('focused_entity', $promptContext);
        $this->assertArrayHasKey('mentioned_entities', $promptContext);
        $this->assertArrayHasKey('last_cypher_query', $promptContext);
        $this->assertArrayHasKey('recent_exchanges', $promptContext);
        // Focus shifts to Order because "ordered" is detected as Order entity
        $this->assertEquals('Order', $promptContext['focused_entity']);
    }

    /** @test */
    public function conversation_context_section_has_correct_priority(): void
    {
        $section = new ConversationContextSection();

        // Priority should be 55 (after SimilarQueriesSection at 50, before DetectedEntitiesSection at 60)
        $priority = $section->getPriority();

        $this->assertEquals(55, $priority, 'ConversationContextSection should have priority 55');
    }

    /** @test */
    public function conversation_context_section_only_includes_when_context_present(): void
    {
        $section = new ConversationContextSection();

        // Without conversation_context
        $shouldIncludeEmpty = $section->shouldInclude(
            'Test question',
            [],
            []
        );
        $this->assertFalse($shouldIncludeEmpty);

        // With empty conversation_context
        $shouldIncludeEmptyContext = $section->shouldInclude(
            'Test question',
            ['conversation_context' => []],
            []
        );
        $this->assertFalse($shouldIncludeEmptyContext);

        // With focused_entity
        $shouldIncludeWithFocus = $section->shouldInclude(
            'Test question',
            ['conversation_context' => ['focused_entity' => 'Customer']],
            []
        );
        $this->assertTrue($shouldIncludeWithFocus);

        // With recent_exchanges
        $shouldIncludeWithExchanges = $section->shouldInclude(
            'Test question',
            ['conversation_context' => ['recent_exchanges' => [['user' => ['content' => 'test']]]]],
            []
        );
        $this->assertTrue($shouldIncludeWithExchanges);
    }

    /** @test */
    public function conversation_context_section_formats_correctly(): void
    {
        $section = new ConversationContextSection();

        $context = [
            'conversation_context' => [
                'focused_entity' => 'Customer',
                'last_query_type' => 'list',
                'mentioned_entities' => ['Customer', 'Order'],
                'is_follow_up' => true,
                'recent_exchanges' => [
                    [
                        'user' => ['content' => 'Show me all customers'],
                        'assistant' => [
                            'content' => 'Here are the customers',
                            'cypher_query' => 'MATCH (c:Customer) RETURN c',
                        ],
                    ],
                ],
            ],
        ];

        $formatted = $section->format('Show me those who ordered', $context, []);

        // Check header
        $this->assertStringContainsString('CONVERSATION CONTEXT', $formatted);

        // Check focused entity
        $this->assertStringContainsString('Current Focus:', $formatted);
        $this->assertStringContainsString('Customer', $formatted);

        // Check recent exchanges
        $this->assertStringContainsString('Recent Conversation:', $formatted);
        $this->assertStringContainsString('Show me all customers', $formatted);

        // Check mentioned entities
        $this->assertStringContainsString('Entities discussed:', $formatted);

        // Check follow-up note
        $this->assertStringContainsString('continuation of the previous conversation', $formatted);
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
        $this->assertNull($conversation->getLastCypherQuery());

        // Update context snapshot
        $conversation->updateContextSnapshot([
            'focused_entity' => 'Order',
            'mentioned_entities' => ['Order', 'Customer'],
            'last_query_type' => 'count',
        ]);

        $conversation->refresh();

        $this->assertEquals('Order', $conversation->getFocusedEntity());
        $this->assertEquals(['Order', 'Customer'], $conversation->getMentionedEntities());
        $this->assertEquals('count', $conversation->getLastQueryType());

        // Add message with cypher query
        $conversation->addMessage('assistant', 'There are 42 orders', [
            'cypher_query' => 'MATCH (o:Order) RETURN count(o)',
        ]);

        $this->assertEquals('MATCH (o:Order) RETURN count(o)', $conversation->getLastCypherQuery());
    }
}
