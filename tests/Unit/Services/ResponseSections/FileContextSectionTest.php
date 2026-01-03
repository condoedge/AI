<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\Services\ResponseSections\FileContextSection;
use PHPUnit\Framework\TestCase;

class FileContextSectionTest extends TestCase
{
    public function test_should_include_when_file_context_has_relevant_files(): void
    {
        $section = new FileContextSection();

        $context = [
            'file_context' => [
                'relevant_files' => [
                    ['filename' => 'doc.pdf', 'snippet' => 'Content here'],
                ],
            ],
        ];

        $this->assertTrue($section->shouldInclude($context, []));
    }

    public function test_should_not_include_when_no_file_context(): void
    {
        $section = new FileContextSection();

        $this->assertFalse($section->shouldInclude([], []));
        $this->assertFalse($section->shouldInclude(['file_context' => []], []));
    }

    public function test_format_includes_file_content_with_citation_numbers(): void
    {
        $section = new FileContextSection();

        $context = [
            'file_context' => [
                'relevant_files' => [
                    ['filename' => 'policy.pdf', 'snippet' => 'Policy content...', 'relevance' => 0.95],
                    ['filename' => 'guide.md', 'snippet' => 'Guide content...', 'relevance' => 0.8],
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

    public function test_get_name_returns_file_context(): void
    {
        $section = new FileContextSection();
        $this->assertEquals('file_context', $section->getName());
    }

    public function test_get_priority_returns_appropriate_value(): void
    {
        $section = new FileContextSection();
        // Should be between query_info (40) and data (50)
        $this->assertEquals(45, $section->getPriority());
    }
}
