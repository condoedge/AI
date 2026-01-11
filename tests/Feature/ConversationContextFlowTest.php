<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Tests\TestCase;

/**
 * Integration tests for the full conversation context flow.
 *
 * Tests end-to-end verification that all context flows correctly
 * through multiple conversation turns, including:
 * - Entity tracking and focus
 * - Follow-up question resolution
 * - File reference preservation
 * - Insights and template detection
 * - Prompt context building
 */
class ConversationContextFlowTest extends TestCase
{
    private ConversationContextManager $manager;

    public function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabase();

        // Disable mass assignment protection for tests
        AiConversation::unguard();
        AiMessage::unguard();

        $this->manager = new ConversationContextManager(
            new EntityExtractor(),
            new ReferenceResolver()
        );
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
    public function it_preserves_context_across_multiple_turns(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order', 'Product']];

        // Turn 1: Initial question
        $result1 = $this->manager->processQuestion($conversation, 'How many customers do we have?', $schema);
        $this->assertEquals('Customer', $result1['focused_entity']);

        // Simulate recording response
        $this->manager->recordResponse(
            $conversation,
            'There are 150 customers.',
            'MATCH (c:Customer) RETURN count(c)',
            [
                'data' => [['count' => 150]],
                'insights' => ['Customer count is stable'],
                'detected_template' => 'count',
            ]
        );

        $conversation->refresh();
        $snapshot1 = $conversation->context_snapshot;

        // Verify Turn 1 context stored
        $this->assertEquals('Customer', $snapshot1['focused_entity']);
        $this->assertEquals('count', $snapshot1['last_detected_template']);
        $this->assertNotEmpty($snapshot1['last_insights']);

        // Turn 2: Follow-up question
        $result2 = $this->manager->processQuestion($conversation, 'Which of those are active?', $schema);
        $this->assertTrue($result2['is_follow_up']);
        $this->assertEquals('Customer', $result2['resolved_entity']);

        // Record Turn 2 response (no new insights)
        $this->manager->recordResponse(
            $conversation,
            'There are 120 active customers.',
            'MATCH (c:Customer) WHERE c.active = true RETURN count(c)',
            ['data' => [['count' => 120]]]
        );

        $conversation->refresh();
        $snapshot2 = $conversation->context_snapshot;

        // Previous insights should still be there (not overwritten)
        $this->assertNotEmpty($snapshot2['last_insights']);
        $this->assertEquals('Customer', $snapshot2['focused_entity']);

        // Turn 3: Another follow-up
        $result3 = $this->manager->processQuestion($conversation, 'Show me the top 5', $schema);
        $this->assertTrue($result3['is_follow_up']);
    }

    /** @test */
    public function it_preserves_file_references_across_turns(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        // Turn 1: Response with file reference
        $this->manager->recordResponse(
            $conversation,
            'Found this in the quarterly report [1]',
            null,
            [
                'data' => [],
                'referenced_files' => [
                    ['id' => 1, 'name' => 'report.pdf', 'snippet' => 'Q1 sales...'],
                ],
            ]
        );

        $conversation->refresh();
        $this->assertNotEmpty($conversation->context_snapshot['last_referenced_files']);

        // Turn 2: Response without file reference
        $this->manager->recordResponse(
            $conversation,
            'The total is 150.',
            'MATCH (s:Sale) RETURN sum(s.amount)',
            ['data' => [['sum' => 150]]]
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        // File references should still be preserved
        $this->assertNotEmpty($snapshot['last_referenced_files']);
        $this->assertEquals('report.pdf', $snapshot['last_referenced_files'][0]['name']);
    }

    /** @test */
    public function it_builds_rich_prompt_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer']];

        // Process question and record response
        $this->manager->processQuestion($conversation, 'How many customers?', $schema);
        $this->manager->recordResponse(
            $conversation,
            'There are 150 customers.',
            'MATCH (c:Customer) RETURN count(c)',
            [
                'data' => [['count' => 150]],
                'insights' => ['Steady growth'],
                'stats' => ['execution_time_ms' => 45, 'was_truncated' => false],
                'detected_template' => 'count',
                'query_type' => 'aggregation',
            ]
        );

        // Build prompt context
        $promptContext = $this->manager->buildPromptContext($conversation);

        // Verify all context is available
        $this->assertEquals('Customer', $promptContext['focused_entity']);
        $this->assertNotNull($promptContext['last_cypher_query']);
        $this->assertEquals(['Steady growth'], $promptContext['last_insights']);
        $this->assertEquals('count', $promptContext['last_detected_template']);
        $this->assertArrayHasKey('last_execution_stats', $promptContext);
    }

    /** @test */
    public function it_tracks_entity_focus_through_conversation(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order', 'Product']];

        // Question 1: Focus on Customer
        $result1 = $this->manager->processQuestion($conversation, 'Show me all customers', $schema);
        $this->assertEquals('Customer', $result1['focused_entity']);
        $this->assertFalse($result1['is_follow_up']);

        $this->manager->recordResponse(
            $conversation,
            'Here are all customers...',
            'MATCH (c:Customer) RETURN c LIMIT 100',
            ['data' => [['name' => 'John'], ['name' => 'Jane']]]
        );

        // Question 2: Focus shifts to Order - using "those" which is a supported pattern
        $result2 = $this->manager->processQuestion($conversation, 'Show me those who have orders', $schema);
        $this->assertTrue($result2['is_follow_up']);
        // "those" refers back to customers, and "orders" is mentioned
        $this->assertEquals('Order', $result2['focused_entity']);

        $this->manager->recordResponse(
            $conversation,
            'Here are the orders...',
            'MATCH (c:Customer)-[:PLACED]->(o:Order) RETURN o LIMIT 100',
            ['data' => [['orderId' => 1], ['orderId' => 2]]]
        );

        // Verify context tracks all mentioned entities
        $conversation->refresh();
        $mentionedEntities = $conversation->getMentionedEntities();
        $this->assertContains('Customer', $mentionedEntities);
        $this->assertContains('Order', $mentionedEntities);
    }

    /** @test */
    public function it_resolves_pronouns_in_follow_up_questions(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order', 'Product']];

        // Initial question about customers
        $this->manager->processQuestion($conversation, 'Show me customers from USA', $schema);
        $this->manager->recordResponse(
            $conversation,
            'Here are customers from USA...',
            "MATCH (c:Customer) WHERE c.country = 'USA' RETURN c",
            ['data' => [['name' => 'John', 'country' => 'USA']]]
        );

        $conversation->refresh();

        // Follow-up with pronoun "those"
        $result = $this->manager->processQuestion($conversation, 'How many of those are active?', $schema);

        $this->assertTrue($result['is_follow_up']);
        $this->assertEquals('Customer', $result['resolved_entity']);
        // The enriched question should replace "those" with the entity
        $this->assertStringContainsString('customer', strtolower($result['enriched_question']));
    }

    /** @test */
    public function it_tracks_query_templates_and_types(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer']];

        // Count query
        $this->manager->processQuestion($conversation, 'How many customers?', $schema);
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

        // List query
        $this->manager->processQuestion($conversation, 'Show me all customers', $schema);
        $this->manager->recordResponse(
            $conversation,
            'Here are the customers...',
            'MATCH (c:Customer) RETURN c LIMIT 100',
            [
                'data' => [['name' => 'John']],
                'detected_template' => 'list',
                'query_type' => 'retrieval',
            ]
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        $this->assertEquals('list', $snapshot['last_detected_template']);
        $this->assertEquals('retrieval', $snapshot['last_query_type']);
    }

    /** @test */
    public function it_preserves_cypher_query_in_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer']];

        $cypherQuery = "MATCH (c:Customer) WHERE c.status = 'active' RETURN c.name, c.email LIMIT 50";

        $this->manager->processQuestion($conversation, 'Show active customers', $schema);
        $this->manager->recordResponse(
            $conversation,
            'Here are the active customers...',
            $cypherQuery,
            ['data' => [['name' => 'John', 'email' => 'john@example.com']]]
        );

        $conversation->refresh();

        // Verify stored in context snapshot
        $this->assertEquals($cypherQuery, $conversation->context_snapshot['last_cypher_query']);

        // Verify available in prompt context
        $promptContext = $this->manager->buildPromptContext($conversation);
        $this->assertEquals($cypherQuery, $promptContext['last_cypher_query']);
    }

    /** @test */
    public function it_stores_and_retrieves_result_samples(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Product']];

        $sampleData = [
            ['id' => 1, 'name' => 'Product A', 'price' => 100],
            ['id' => 2, 'name' => 'Product B', 'price' => 200],
            ['id' => 3, 'name' => 'Product C', 'price' => 150],
            ['id' => 4, 'name' => 'Product D', 'price' => 175],
            ['id' => 5, 'name' => 'Product E', 'price' => 125],
        ];

        $this->manager->processQuestion($conversation, 'Show me all products', $schema);
        $this->manager->recordResponse(
            $conversation,
            'Here are all products...',
            'MATCH (p:Product) RETURN p LIMIT 100',
            ['data' => $sampleData]
        );

        $conversation->refresh();

        // Should store only first 3 as sample
        $this->assertCount(3, $conversation->context_snapshot['last_result_sample']);
        $this->assertEquals('Product A', $conversation->context_snapshot['last_result_sample'][0]['name']);

        // Should store full count
        $this->assertEquals(5, $conversation->context_snapshot['last_result_count']);
    }

    /** @test */
    public function it_extracts_entity_filter_from_cypher(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer']];

        $this->manager->processQuestion($conversation, 'Show customers from USA', $schema);
        $this->manager->recordResponse(
            $conversation,
            'Here are customers from USA...',
            "MATCH (c:Customer) WHERE c.country = 'USA' AND c.status = 'active' RETURN c",
            ['data' => [['name' => 'John']]]
        );

        $conversation->refresh();

        // Should extract the WHERE clause conditions
        $filter = $conversation->context_snapshot['focused_entity_filter'];
        $this->assertNotNull($filter);
        $this->assertStringContainsString("c.country = 'USA'", $filter);
        $this->assertStringContainsString("c.status = 'active'", $filter);
    }

    /** @test */
    public function it_handles_conversation_without_cypher_queries(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        // Response without Cypher (e.g., file-based response)
        $this->manager->recordResponse(
            $conversation,
            'Based on the uploaded document, here is the summary...',
            null, // No Cypher query
            [
                'data' => [],
                'referenced_files' => [
                    ['id' => 1, 'name' => 'document.pdf', 'snippet' => 'Summary content...'],
                ],
            ]
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        // Should still track files
        $this->assertNotEmpty($snapshot['last_referenced_files']);

        // Cypher-related fields should be null/empty
        $this->assertNull($snapshot['last_cypher_query'] ?? null);
    }

    /** @test */
    public function it_merges_context_updates_without_overwriting(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order']];

        // First update: Set Customer focus with insights
        $this->manager->processQuestion($conversation, 'How many customers?', $schema);
        $this->manager->recordResponse(
            $conversation,
            'There are 150 customers.',
            'MATCH (c:Customer) RETURN count(c)',
            [
                'data' => [['count' => 150]],
                'insights' => ['Customer base is growing'],
                'referenced_files' => [
                    ['id' => 1, 'name' => 'report.pdf', 'snippet' => 'Customer analysis...'],
                ],
            ]
        );

        $conversation->refresh();
        $snapshot1 = $conversation->context_snapshot;
        $this->assertEquals('Customer', $snapshot1['focused_entity']);
        $this->assertNotEmpty($snapshot1['last_insights']);
        $this->assertNotEmpty($snapshot1['last_referenced_files']);

        // Second update: New query without insights or files
        $this->manager->processQuestion($conversation, 'Show their orders', $schema);
        $this->manager->recordResponse(
            $conversation,
            'Here are the orders...',
            'MATCH (c:Customer)-[:PLACED]->(o:Order) RETURN o',
            [
                'data' => [['orderId' => 1]],
                // No insights, no referenced_files
            ]
        );

        $conversation->refresh();
        $snapshot2 = $conversation->context_snapshot;

        // Focus should update to Order (new entity)
        $this->assertEquals('Order', $snapshot2['focused_entity']);

        // Cypher query should update
        $this->assertStringContainsString('Order', $snapshot2['last_cypher_query']);

        // Previous insights should be preserved (not overwritten with empty)
        $this->assertNotEmpty($snapshot2['last_insights']);

        // Previous file references should be preserved
        $this->assertNotEmpty($snapshot2['last_referenced_files']);
        $this->assertEquals('report.pdf', $snapshot2['last_referenced_files'][0]['name']);
    }

    /** @test */
    public function it_tracks_relationships_from_cypher_queries(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order']];

        $this->manager->processQuestion($conversation, 'Show customers with orders', $schema);
        $this->manager->recordResponse(
            $conversation,
            'Here are customers with their orders...',
            'MATCH (c:Customer)-[:PLACED]->(o:Order) RETURN c, o LIMIT 50',
            ['data' => [['customer' => 'John', 'order' => '12345']]]
        );

        $conversation->refresh();

        // Should extract relationship from Cypher
        $relationships = $conversation->context_snapshot['last_relationships'] ?? [];
        $this->assertContains('PLACED', $relationships);
    }

    /** @test */
    public function it_builds_complete_prompt_context_for_follow_ups(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order']];

        // Build up conversation context
        $this->manager->processQuestion($conversation, 'How many active customers?', $schema);
        $conversation->addMessage('user', 'How many active customers?');
        $this->manager->recordResponse(
            $conversation,
            'There are 120 active customers.',
            "MATCH (c:Customer) WHERE c.status = 'active' RETURN count(c)",
            [
                'data' => [['count' => 120]],
                'insights' => ['Strong customer retention'],
                'detected_template' => 'count',
                'query_type' => 'aggregation',
                'referenced_files' => [
                    ['id' => 1, 'name' => 'analytics.pdf', 'snippet' => 'Customer metrics...'],
                ],
            ]
        );
        $conversation->addMessage('assistant', 'There are 120 active customers.', [
            'cypher_query' => "MATCH (c:Customer) WHERE c.status = 'active' RETURN count(c)",
        ]);

        $conversation->refresh();

        // Build prompt context
        $promptContext = $this->manager->buildPromptContext($conversation);

        // Verify complete context structure
        $this->assertEquals('Customer', $promptContext['focused_entity']);
        $this->assertEquals("c.status = 'active'", $promptContext['focused_entity_filter']);
        $this->assertEquals(['Strong customer retention'], $promptContext['last_insights']);
        $this->assertEquals('count', $promptContext['last_detected_template']);
        $this->assertEquals('aggregation', $promptContext['last_query_type']);
        $this->assertNotEmpty($promptContext['last_referenced_files']);
        $this->assertArrayHasKey('recent_exchanges', $promptContext);
        $this->assertContains('Customer', $promptContext['mentioned_entities']);
    }

    /** @test */
    public function it_handles_implicit_follow_up_patterns(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order', 'Product']];

        // Initial query
        $this->manager->processQuestion($conversation, 'Show me all customers', $schema);
        $this->manager->recordResponse(
            $conversation,
            'Here are all customers...',
            'MATCH (c:Customer) RETURN c LIMIT 100',
            ['data' => array_fill(0, 50, ['name' => 'Customer'])]
        );

        $conversation->refresh();

        // Implicit follow-up patterns
        // Note: The ReferenceResolver only detects patterns like "filter the..."
        // or those/them/these pronouns, or "top/first/last N" patterns
        $implicitFollowUps = [
            'top 5 by revenue' => true,       // Matches "^(top|first|last)\s+\d+"
            'and their orders' => true,        // Matches "^and\s+"
            'what about in Canada?' => true,   // Matches "^what about\s+"
            'show me those' => true,           // Matches "those"
            'filter the results' => true,      // Matches "^filter\s+...the\s+"
        ];

        foreach ($implicitFollowUps as $question => $shouldBeFollowUp) {
            $result = $this->manager->processQuestion($conversation, $question, $schema);
            $this->assertEquals(
                $shouldBeFollowUp,
                $result['is_follow_up'],
                "Expected '$question' to be " . ($shouldBeFollowUp ? 'a follow-up' : 'not a follow-up')
            );
        }
    }
}
