<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\ResponseSections\FileContextSection;
use PHPUnit\Framework\TestCase;

/**
 * Verifies file-citation INSTRUCTIONS are style-aware.
 *
 * For 'minimal'/'concise' (GuidelinesSection: "ONLY the direct answer, no
 * explanation"), the "[N] citation marker" directives are suppressed so they
 * stop contradicting the requested style. The file CONTENT is always kept so
 * the model can still ground its answer.
 *
 * Pure-PHP: builds a PipelineContext with file context directly, no container.
 */
class FileContextSectionStyleTest extends TestCase
{
    private function contextWithFiles(): PipelineContext
    {
        $context = new PipelineContext();
        $context->fileContext = [
            'relevant_files' => [
                ['filename' => 'policy.pdf', 'snippet' => 'Policy content here', 'relevance' => 0.95],
                ['filename' => 'guide.md', 'snippet' => 'Guide content here', 'relevance' => 0.80],
            ],
        ];

        return $context;
    }

    /**
     * @test
     * @dataProvider terseStyles
     */
    public function it_suppresses_citation_instructions_for_terse_styles(string $style): void
    {
        $section = new FileContextSection();

        $output = $section->format($this->contextWithFiles(), ['style' => $style]);

        // Instruction lines about [N] markers must be gone...
        $this->assertStringNotContainsString('citation marker', $output);
        $this->assertStringNotContainsString('include the citation marker like [1] or [2]', $output);

        // ...but the file CONTENT (and its [1]/[2] reference headers) is still provided.
        $this->assertStringContainsString('policy.pdf', $output);
        $this->assertStringContainsString('Policy content here', $output);
    }

    /**
     * @test
     * @dataProvider richStyles
     */
    public function it_keeps_citation_instructions_for_rich_styles(string $style): void
    {
        $section = new FileContextSection();

        $output = $section->format($this->contextWithFiles(), ['style' => $style]);

        $this->assertStringContainsString('Use citation markers [N]', $output);
        $this->assertStringContainsString('include the citation marker like [1] or [2]', $output);
        $this->assertStringContainsString('policy.pdf', $output);
    }

    public static function terseStyles(): array
    {
        return [
            'minimal' => ['minimal'],
            'concise' => ['concise'],
        ];
    }

    public static function richStyles(): array
    {
        return [
            'friendly' => ['friendly'],
            'detailed' => ['detailed'],
            'technical' => ['technical'],
        ];
    }
}
