<?php

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\Services\PromptSections\ConversationContextSection;
use Condoedge\Ai\Tests\TestCase;

class ConversationContextSectionTest extends TestCase
{
    private ConversationContextSection $section;

    public function setUp(): void
    {
        parent::setUp();
        $this->section = new ConversationContextSection();
    }

    /** @test */
    public function it_has_correct_name_and_priority(): void
    {
        $this->assertEquals('conversation_context', $this->section->getName());
        $this->assertEquals(55, $this->section->getPriority()); // After similar_queries (50)
    }

    /** @test */
    public function it_should_not_include_when_no_context(): void
    {
        $result = $this->section->shouldInclude('question', [], []);
        $this->assertFalse($result);

        $result = $this->section->shouldInclude('question', ['conversation_context' => []], []);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_should_include_when_context_has_data(): void
    {
        $context = [
            'conversation_context' => [
                'focused_entity' => 'Customer',
                'recent_exchanges' => [['question' => 'test']],
            ],
        ];

        $result = $this->section->shouldInclude('question', $context, []);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_formats_context_with_focused_entity(): void
    {
        $context = [
            'conversation_context' => [
                'focused_entity' => 'Customer',
                'last_query_type' => 'count',
                'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
            ],
        ];

        $output = $this->section->format('show top 5', $context);

        $this->assertStringContainsString('## Conversation Context', $output);
        $this->assertStringContainsString('Customer', $output);
        $this->assertStringContainsString('count', $output);
        $this->assertStringContainsString('MATCH (c:Customer)', $output);
    }

    /** @test */
    public function it_formats_recent_exchanges(): void
    {
        $context = [
            'conversation_context' => [
                'focused_entity' => 'Customer',
                'recent_exchanges' => [
                    [
                        'question' => 'How many customers?',
                        'answer_summary' => 'There are 150 customers.',
                    ],
                ],
            ],
        ];

        $output = $this->section->format('and in Sales?', $context);

        $this->assertStringContainsString('How many customers?', $output);
        $this->assertStringContainsString('150 customers', $output);
    }

    /** @test */
    public function it_includes_follow_up_hint_in_instructions(): void
    {
        $context = [
            'conversation_context' => [
                'focused_entity' => 'Customer',
                'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
            ],
        ];

        $output = $this->section->format('and in Sales?', $context);

        $this->assertStringContainsString('follow-up', strtolower($output));
    }

    /** @test */
    public function it_includes_insights_in_prompt(): void
    {
        $context = [
            'conversation_context' => [
                'last_insights' => ['Customer growth is 15%', 'Most customers are in NYC'],
                'focused_entity' => 'Customer',
            ],
        ];

        $result = $this->section->format('follow up question', $context, []);

        $this->assertStringContainsString('Previous Insights', $result);
        $this->assertStringContainsString('Customer growth is 15%', $result);
        $this->assertStringContainsString('Most customers are in NYC', $result);
    }

    /** @test */
    public function it_includes_truncation_warning_in_prompt(): void
    {
        $context = [
            'conversation_context' => [
                'last_execution_stats' => [
                    'was_truncated' => true,
                    'rows_returned' => 100,
                    'rows_available' => 500,
                ],
                'focused_entity' => 'Order',
            ],
        ];

        $result = $this->section->format('follow up question', $context, []);

        $this->assertStringContainsString('truncated', $result);
        $this->assertStringContainsString('100', $result);
        $this->assertStringContainsString('500', $result);
    }

    /** @test */
    public function it_formats_available_actions_in_output(): void
    {
        $section = new ConversationContextSection();

        $context = [
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_available_actions' => [
                    'Person' => [
                        ['action_key' => 'profile', 'label' => 'View Profile'],
                        ['action_key' => 'edit', 'label' => 'Edit Person'],
                    ],
                ],
            ],
        ];

        $output = $section->format('what actions can I do?', $context);

        $this->assertStringContainsString('Available Actions', $output);
        $this->assertStringContainsString('View Profile', $output);
        $this->assertStringContainsString('Edit Person', $output);
        $this->assertStringContainsString('profile', $output);
    }
}
