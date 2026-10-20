<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\ResponseSections\FileContextSection;
use Condoedge\Ai\Services\ResponseSections\ResponseConversationContextSection;
use Condoedge\Ai\Tests\TestCase;

class ResponseConversationContextSectionTest extends TestCase
{
    /**
     * Build a PipelineContext carrying the given conversation context payload.
     */
    private function contextWith(array $conversationContext): PipelineContext
    {
        $context = new PipelineContext();
        $context->conversationContext = $conversationContext;

        return $context;
    }

    /** @test */
    public function it_includes_file_download_urls_in_prompt(): void
    {
        $section = new ResponseConversationContextSection();

        $context = $this->contextWith([
            'last_referenced_files' => [
                [
                    'ref' => 1,
                    'id' => 123,
                    'name' => 'report.pdf',
                    'snippet' => 'Quarterly sales report...',
                    'download_url' => '/files/123/download',
                    'can_download' => true,
                ],
            ],
        ]);

        $result = $section->format($context, []);

        $this->assertStringContainsString('report.pdf', $result);
        $this->assertStringContainsString('/files/123/download', $result);
    }

    /** @test */
    public function it_does_not_show_download_url_when_cannot_download(): void
    {
        $section = new ResponseConversationContextSection();

        $context = $this->contextWith([
            'last_referenced_files' => [
                [
                    'ref' => 1,
                    'id' => 456,
                    'name' => 'restricted.pdf',
                    'snippet' => 'Restricted content...',
                    'download_url' => '/files/456/download',
                    'can_download' => false,
                ],
            ],
        ]);

        $result = $section->format($context, []);

        $this->assertStringContainsString('restricted.pdf', $result);
        $this->assertStringNotContainsString('/files/456/download', $result);
    }

    /** @test */
    public function it_does_not_show_download_url_when_url_is_missing(): void
    {
        $section = new ResponseConversationContextSection();

        $context = $this->contextWith([
            'last_referenced_files' => [
                [
                    'ref' => 1,
                    'id' => 789,
                    'name' => 'legacy.pdf',
                    'snippet' => 'Legacy content...',
                    'can_download' => true,
                    // No download_url provided
                ],
            ],
        ]);

        $result = $section->format($context, []);

        $this->assertStringContainsString('legacy.pdf', $result);
        $this->assertStringNotContainsString('Download:', $result);
    }

    public function test_should_include_when_conversation_context_has_referenced_files(): void
    {
        $section = new ResponseConversationContextSection();

        $context = $this->contextWith([
            'last_referenced_files' => [
                ['name' => 'doc.pdf', 'snippet' => 'Content here'],
            ],
        ]);

        $this->assertTrue($section->shouldInclude($context, []));
    }

    public function test_should_not_include_when_no_conversation_context(): void
    {
        $section = new ResponseConversationContextSection();

        $this->assertFalse($section->shouldInclude(new PipelineContext(), []));
        $this->assertFalse($section->shouldInclude($this->contextWith([]), []));
    }

    /**
     * Task 1.4: previous-turn files are rendered WITHOUT [N] markers (their old
     * numbers collide with the current turn's [1], [2]…). Was: asserted '[1]'/'[2]'
     * were present — that asserted the collision itself.
     */
    public function test_format_includes_previous_files_without_numeric_markers(): void
    {
        $section = new ResponseConversationContextSection();

        $context = $this->contextWith([
            'last_referenced_files' => [
                ['name' => 'policy.pdf', 'id' => 1, 'snippet' => 'Policy content...'],
                ['ref' => 2, 'name' => 'guide.md', 'id' => 2, 'snippet' => 'Guide content...', 'heading' => 'Art. 1039'],
            ],
        ]);

        $result = $section->format($context, []);

        $this->assertDoesNotMatchRegularExpression('/\[\d+\]/', $result);
        // The name and the section are document content: in the fence, with the excerpt.
        $this->assertStringContainsString("- previously cited (ID: 1)\n<retrieved_document source=previous_turn>\nFile: policy.pdf\n\nPolicy content...", $result);
        $this->assertStringContainsString("- previously cited (ID: 2)\n<retrieved_document source=previous_turn>\nFile: guide.md\n§ Art. 1039\n\nGuide content...", $result);
    }

    public function test_previous_file_snippet_is_capped_and_spotlighted(): void
    {
        config(['ai.response_generation.previous_snippet_chars' => 400]);
        $section = new ResponseConversationContextSection();
        $long = str_repeat('a', 390) . 'CUT_MARKER_BEYOND_400' . str_repeat('b', 5000);

        $result = $section->format($this->contextWith([
            'last_referenced_files' => [['ref' => 1, 'id' => 9, 'name' => 'law.pdf', 'snippet' => $long]],
        ]), []);

        $this->assertStringNotContainsString('CUT_MARKER_BEYOND_400', $result);
        $this->assertStringNotContainsString(str_repeat('b', 50), $result);
        $this->assertStringContainsString('<retrieved_document', $result);
        $this->assertStringContainsString('</retrieved_document>', $result);
        $this->assertStringNotContainsString('```', $result);
    }

    /**
     * Task 1.27 (b) / 1.28, rights t1: a 400- and then a 1500-char cap kept only
     * the START of each chunk (the least relevant articles) and dropped the cited
     * ones a deepening follow-up needed. The cap is config, default 6000 — the
     * same as file_context.snippet_length, i.e. the whole chunk.
     */
    public function test_previous_snippet_cap_defaults_to_6000_chars(): void
    {
        config(['ai.response_generation.previous_snippet_chars' => null]);
        $long = str_repeat('a', 5000) . 'KEPT_WITHIN_6000' . str_repeat('b', 1000) . 'CUT_BEYOND_6000' . str_repeat('c', 3000);

        $result = (new ResponseConversationContextSection())->format($this->contextWith([
            'last_referenced_files' => [['id' => 9, 'name' => 'law.pdf', 'snippet' => $long]],
        ]), []);

        $this->assertStringContainsString('KEPT_WITHIN_6000', $result);
        $this->assertStringNotContainsString('CUT_BEYOND_6000', $result);
        $this->assertDoesNotMatchRegularExpression('/\[\d+\]/', $result, 'previous-turn files stay unnumbered');
        $this->assertStringContainsString('<retrieved_document', $result);
    }

    public function test_previous_snippet_cap_honors_config(): void
    {
        config(['ai.response_generation.previous_snippet_chars' => 50]);
        $long = str_repeat('a', 40) . 'CUT_BEYOND_50' . str_repeat('b', 100);

        $result = (new ResponseConversationContextSection())->format($this->contextWith([
            'last_referenced_files' => [['id' => 9, 'name' => 'law.pdf', 'snippet' => $long]],
        ]), []);

        $this->assertStringNotContainsString('CUT_BEYOND_50', $result);
        $this->assertStringContainsString(str_repeat('a', 40), $result);
    }

    public function test_prompt_with_previous_and_current_ref_1_contains_exactly_one_citation_1(): void
    {
        $context = $this->contextWith([
            'last_referenced_files' => [
                ['ref' => 1, 'id' => 7, 'name' => 'old-rules.pdf', 'snippet' => 'Old rules text'],
            ],
        ]);
        $context->fileContext = [
            'relevant_files' => [
                ['file_id' => 8, 'filename' => 'civil-code.pdf', 'snippet' => 'Current law text', 'relevance' => 0.8],
            ],
        ];

        $prompt = (new FileContextSection())->format($context, ['style' => 'friendly'])
            . (new ResponseConversationContextSection())->format($context, ['style' => 'friendly']);

        // Exactly ONE source is numbered [1]: the current passage. (Phase 8-B: the
        // citation instruction now names the real choice "[1]" — the same source,
        // never a competing one; the previous-turn file stays unnumbered.)
        $this->assertSame(1, preg_match_all('/^\[1\]( |$)/m', $prompt), "Exactly one source labelled [1]:\n" . $prompt);
        $this->assertStringContainsString("[1] (relevance: 80%)\n\n<retrieved_document id=1>\nFile: civil-code.pdf\n", $prompt);
        $this->assertDoesNotMatchRegularExpression('/old-rules\.pdf[^\n]*\[1\]|\[1\][^\n]*old-rules\.pdf/', $prompt);
    }

    public function test_get_name_returns_conversation_context(): void
    {
        $section = new ResponseConversationContextSection();
        $this->assertEquals('conversation_context', $section->getName());
    }

    public function test_get_priority_returns_appropriate_value(): void
    {
        $section = new ResponseConversationContextSection();
        // Should be after FileContextSection (40) but before ResultsData (50)
        $this->assertEquals(45, $section->getPriority());
    }

    public function test_format_includes_previous_result_sample(): void
    {
        $section = new ResponseConversationContextSection();

        $context = $this->contextWith([
            'last_result_sample' => [
                ['id' => 1, 'name' => 'Item 1'],
                ['id' => 2, 'name' => 'Item 2'],
            ],
        ]);

        $result = $section->format($context, []);

        $this->assertStringContainsString('Previous Query Results Sample', $result);
        $this->assertStringContainsString('Item 1', $result);
    }

    public function test_format_includes_focused_entity(): void
    {
        $section = new ResponseConversationContextSection();

        $context = $this->contextWith([
            'focused_entity' => 'Project XYZ',
            'focused_entity_filter' => 'project_id = 123',
        ]);

        $result = $section->format($context, []);

        $this->assertStringContainsString('Current Focus:', $result);
        $this->assertStringContainsString('Project XYZ', $result);
        $this->assertStringContainsString('project_id = 123', $result);
    }
}
