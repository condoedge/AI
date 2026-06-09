<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\ResponseSections\FileContextSection;
use PHPUnit\Framework\TestCase;

class FileContextSectionTest extends TestCase
{
    private function contextWithFileContext(array $fileContext): PipelineContext
    {
        $context = new PipelineContext();
        $context->fileContext = $fileContext;

        return $context;
    }

    public function test_should_include_when_file_context_has_relevant_files(): void
    {
        $section = new FileContextSection();

        $context = $this->contextWithFileContext([
            'relevant_files' => [
                ['filename' => 'doc.pdf', 'snippet' => 'Content here'],
            ],
        ]);

        $this->assertTrue($section->shouldInclude($context, []));
    }

    public function test_should_not_include_when_no_file_context(): void
    {
        $section = new FileContextSection();

        // Empty PipelineContext: fileContext defaults to [].
        $this->assertFalse($section->shouldInclude(new PipelineContext(), []));
        // fileContext present but with no relevant_files.
        $this->assertFalse($section->shouldInclude($this->contextWithFileContext([]), []));
    }

    public function test_format_includes_file_content_with_citation_numbers(): void
    {
        $section = new FileContextSection();

        $context = $this->contextWithFileContext([
            'relevant_files' => [
                ['filename' => 'policy.pdf', 'snippet' => 'Policy content...', 'relevance' => 0.95],
                ['filename' => 'guide.md', 'snippet' => 'Guide content...', 'relevance' => 0.8],
            ],
        ]);

        // 'friendly' is a citation-enabled style, keeping the [N] markers under test.
        $result = $section->format($context, ['style' => 'friendly']);

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
