<?php

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
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

    /**
     * Build a PipelineContext carrying the given conversation context array.
     */
    private function contextWith(array $conversationContext): PipelineContext
    {
        $context = new PipelineContext();
        $context->conversationContext = $conversationContext;

        return $context;
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
        $result = $this->section->shouldInclude('question', $this->contextWith([]), []);
        $this->assertFalse($result);

        // An empty conversation_context payload must still resolve to "do not include".
        $result = $this->section->shouldInclude('question', $this->contextWith([]), []);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_should_include_when_context_has_data(): void
    {
        $context = $this->contextWith([
            'focused_entity' => 'Customer',
            'recent_exchanges' => [['question' => 'test']],
        ]);

        $result = $this->section->shouldInclude('question', $context, []);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_formats_context_with_focused_entity(): void
    {
        $context = $this->contextWith([
            'focused_entity' => 'Customer',
            'last_query_type' => 'count',
            'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ]);

        $output = $this->section->format('show top 5', $context);

        $this->assertStringContainsString('## Conversation Context', $output);
        $this->assertStringContainsString('Customer', $output);
        $this->assertStringContainsString('count', $output);
        $this->assertStringContainsString('MATCH (c:Customer)', $output);
    }

    /** @test */
    public function it_formats_recent_exchanges(): void
    {
        $context = $this->contextWith([
            'focused_entity' => 'Customer',
            'recent_exchanges' => [
                [
                    'question' => 'How many customers?',
                    'answer_summary' => 'There are 150 customers.',
                ],
            ],
        ]);

        $output = $this->section->format('and in Sales?', $context);

        $this->assertStringContainsString('How many customers?', $output);
        $this->assertStringContainsString('150 customers', $output);
    }

    /** @test */
    public function it_includes_follow_up_hint_in_instructions(): void
    {
        $context = $this->contextWith([
            'focused_entity' => 'Customer',
            'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ]);

        $output = $this->section->format('and in Sales?', $context);

        // The instructions block must teach the model to handle follow-up questions
        // that build on the previous query (e.g. "what about X?" / "and the Y?").
        $this->assertStringContainsString('Instructions for using conversation context', $output);
        $this->assertStringContainsString("what about X?", $output);
        $this->assertStringContainsString('modify the previous query', $output);
    }

    /** @test */
    public function it_omits_query_followup_bullets_when_there_is_no_previous_query(): void
    {
        // Only files were referenced — there is no previous query/result/filter, so the
        // pronoun/aggregation/"what about X" guidance has no referent and must be omitted.
        $context = $this->contextWith([
            'last_referenced_files' => [
                ['name' => 'budget.pdf', 'id' => 7, 'snippet' => 'totals'],
            ],
        ]);

        $output = $this->section->format('summarize that file', $context);

        // The query-oriented bullets are gone (no previous query/result/filter)...
        $this->assertStringNotContainsString('modify the previous query', $output);
        $this->assertStringNotContainsString("'those', 'them'", $output);
        $this->assertStringNotContainsString('DIFFERENT topic/entity', $output);

        // ...but the file bullet (which has a referent) and the file reference remain.
        $this->assertStringContainsString("'the file', 'that file'", $output);
        $this->assertStringContainsString('budget.pdf', $output);
    }

    /** @test */
    public function it_omits_the_whole_instructions_block_when_nothing_to_follow_up_on(): void
    {
        // last_insights is real data worth showing, but it implies no previous
        // query/result/filter/focus/files — so the follow-up instructions block is omitted.
        $context = $this->contextWith([
            'last_insights' => ['Revenue grew 12%'],
        ]);

        $output = $this->section->format('ok', $context);

        $this->assertStringContainsString('Revenue grew 12%', $output);
        $this->assertStringNotContainsString('Instructions for using conversation context', $output);
    }

    /** @test */
    public function it_includes_the_file_bullet_only_when_files_were_referenced(): void
    {
        // A previous query exists (so the instructions block appears) but no files were
        // referenced — the "the file"/"that file" bullet must NOT appear.
        $context = $this->contextWith([
            'focused_entity' => 'Customer',
            'last_cypher_query' => 'MATCH (c:Customer) RETURN c',
        ]);

        $output = $this->section->format('and the totals?', $context);

        $this->assertStringContainsString('Instructions for using conversation context', $output);
        $this->assertStringNotContainsString("'the file', 'that file'", $output);
    }

    /** @test */
    public function it_includes_insights_in_prompt(): void
    {
        $context = $this->contextWith([
            'last_insights' => ['Customer growth is 15%', 'Most customers are in NYC'],
            'focused_entity' => 'Customer',
        ]);

        $result = $this->section->format('follow up question', $context, []);

        $this->assertStringContainsString('Previous Insights', $result);
        $this->assertStringContainsString('Customer growth is 15%', $result);
        $this->assertStringContainsString('Most customers are in NYC', $result);
    }

    /** @test */
    public function it_includes_truncation_warning_in_prompt(): void
    {
        $context = $this->contextWith([
            'last_execution_stats' => [
                'was_truncated' => true,
                'rows_returned' => 100,
                'rows_available' => 500,
            ],
            'focused_entity' => 'Order',
        ]);

        $result = $this->section->format('follow up question', $context, []);

        $this->assertStringContainsString('truncated', $result);
        $this->assertStringContainsString('100', $result);
        $this->assertStringContainsString('500', $result);
    }

    /** @test */
    public function it_formats_available_actions_in_output(): void
    {
        $section = new ConversationContextSection();

        $context = $this->contextWith([
            'focused_entity' => 'Person',
            'last_available_actions' => [
                'Person' => [
                    ['action_key' => 'profile', 'label' => 'View Profile'],
                    ['action_key' => 'edit', 'label' => 'Edit Person'],
                ],
            ],
        ]);

        $output = $section->format('what actions can I do?', $context);

        $this->assertStringContainsString('Available Actions', $output);
        $this->assertStringContainsString('View Profile', $output);
        $this->assertStringContainsString('Edit Person', $output);
        $this->assertStringContainsString('profile', $output);
    }
}
