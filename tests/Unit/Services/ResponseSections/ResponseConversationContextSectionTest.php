<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\Services\ResponseSections\ResponseConversationContextSection;
use PHPUnit\Framework\TestCase;

class ResponseConversationContextSectionTest extends TestCase
{
    /** @test */
    public function it_includes_file_download_urls_in_prompt(): void
    {
        $section = new ResponseConversationContextSection();

        $context = [
            'conversation_context' => [
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
            ],
        ];

        $result = $section->format($context, []);

        $this->assertStringContainsString('report.pdf', $result);
        $this->assertStringContainsString('/files/123/download', $result);
    }

    /** @test */
    public function it_does_not_show_download_url_when_cannot_download(): void
    {
        $section = new ResponseConversationContextSection();

        $context = [
            'conversation_context' => [
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
            ],
        ];

        $result = $section->format($context, []);

        $this->assertStringContainsString('restricted.pdf', $result);
        $this->assertStringNotContainsString('/files/456/download', $result);
    }

    /** @test */
    public function it_does_not_show_download_url_when_url_is_missing(): void
    {
        $section = new ResponseConversationContextSection();

        $context = [
            'conversation_context' => [
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
            ],
        ];

        $result = $section->format($context, []);

        $this->assertStringContainsString('legacy.pdf', $result);
        $this->assertStringNotContainsString('Download:', $result);
    }

    public function test_should_include_when_conversation_context_has_referenced_files(): void
    {
        $section = new ResponseConversationContextSection();

        $context = [
            'conversation_context' => [
                'last_referenced_files' => [
                    ['name' => 'doc.pdf', 'snippet' => 'Content here'],
                ],
            ],
        ];

        $this->assertTrue($section->shouldInclude($context, []));
    }

    public function test_should_not_include_when_no_conversation_context(): void
    {
        $section = new ResponseConversationContextSection();

        $this->assertFalse($section->shouldInclude([], []));
        $this->assertFalse($section->shouldInclude(['conversation_context' => []], []));
    }

    public function test_format_includes_file_content_with_ref_numbers(): void
    {
        $section = new ResponseConversationContextSection();

        $context = [
            'conversation_context' => [
                'last_referenced_files' => [
                    ['name' => 'policy.pdf', 'id' => 1, 'snippet' => 'Policy content...'],
                    ['name' => 'guide.md', 'id' => 2, 'snippet' => 'Guide content...'],
                ],
            ],
        ];

        $result = $section->format($context, []);

        $this->assertStringContainsString('[1]', $result);
        $this->assertStringContainsString('policy.pdf', $result);
        $this->assertStringContainsString('[2]', $result);
        $this->assertStringContainsString('guide.md', $result);
        $this->assertStringContainsString('Policy content...', $result);
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

        $context = [
            'conversation_context' => [
                'last_result_sample' => [
                    ['id' => 1, 'name' => 'Item 1'],
                    ['id' => 2, 'name' => 'Item 2'],
                ],
            ],
        ];

        $result = $section->format($context, []);

        $this->assertStringContainsString('Previous Query Results Sample', $result);
        $this->assertStringContainsString('Item 1', $result);
    }

    public function test_format_includes_focused_entity(): void
    {
        $section = new ResponseConversationContextSection();

        $context = [
            'conversation_context' => [
                'focused_entity' => 'Project XYZ',
                'focused_entity_filter' => 'project_id = 123',
            ],
        ];

        $result = $section->format($context, []);

        $this->assertStringContainsString('Current Focus:', $result);
        $this->assertStringContainsString('Project XYZ', $result);
        $this->assertStringContainsString('project_id = 123', $result);
    }
}
