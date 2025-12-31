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
}
