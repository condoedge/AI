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
        $this->assertStringNotContainsString('[N] citation marker exactly as numbered above', $output);

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

        // Phase 8-B (8.2b): the instructions name the REAL passage numbers — the
        // generic "[N]" grammar was echoed back verbatim ("([N] 2)" shipped live).
        $this->assertStringContainsString('Cite these passages by their number in square brackets — [1] or [2]', $output);
        $this->assertStringContainsString('cite the passage it came from as [1] or [2], exactly as numbered above', $output);
        $this->assertStringNotContainsString('[N]', $output);
        $this->assertStringContainsString('policy.pdf', $output);
    }

    /**
     * Phase 8-B (8.2b): each passage carries its OWN chunk score (the multi-angle
     * merge's chunk_relevance) and the article numbers its snippet holds — live,
     * every passage of one file showed the file's best score.
     *
     * @test
     */
    public function each_passage_shows_its_own_chunk_score_and_article_locator(): void
    {
        $context = new PipelineContext();
        $context->fileContext = ['relevant_files' => [
            // Live CCQ format: bold clause numbers, sub-articles, in-text cross-references.
            ['filename' => 'CCQ.md', 'snippet' => "**1068.** The register, see article 2650.\n**1069.** The buyer is bound.\n1070.1. Consult.", 'relevance' => 0.50, 'chunk_relevance' => 0.31],
            ['filename' => 'CCQ.md', 'snippet' => "Article 1097 — three-quarters…", 'relevance' => 0.44, 'heading' => 'Co-ownership'],
            ['filename' => 'guide.md', 'snippet' => '1. Click here. 2. Save.', 'relevance' => 0.40],
        ]];

        $output = (new FileContextSection())->format($context, ['style' => 'friendly']);

        // The header line holds what code knows (number, score, article numbers); the
        // file name and the section are document content, inside the passage's fence.
        $this->assertStringContainsString("[1] (relevance: 31%) — art. 1068, 1069, 1070\n\n<retrieved_document id=1>\nFile: CCQ.md\n\n**1068.**", $output, 'the cross-reference 2650 is not this passage\'s article');
        $this->assertStringContainsString("[2] (relevance: 44%) — art. 1097\n\n<retrieved_document id=2>\nFile: CCQ.md\n§ Co-ownership\n\nArticle 1097", $output);
        $this->assertStringContainsString("[3] (relevance: 40%)\n\n<retrieved_document id=3>\nFile: guide.md\n\n1. Click here.", $output, 'a numbered list is not an article');
        $this->assertStringContainsString('[1], [2] or [3]', $output);
    }

    /**
     * Continuation chunks (no line-start number) are labelled from the Phase 7
     * chunk metadata article_no / article_range (shared ArticleLocator).
     *
     * @test
     */
    public function a_continuation_chunk_is_labelled_from_its_chunk_metadata(): void
    {
        $context = new PipelineContext();
        $context->fileContext = ['relevant_files' => [
            ['filename' => 'CCQ.md', 'snippet' => '…with interest, at the time of the acquisition.', 'relevance' => 0.5, 'article_no' => 1069],
            ['filename' => 'CCQ.md', 'snippet' => '…and the financial statements.', 'relevance' => 0.4, 'metadata' => ['article_range' => '1070-1071']],
        ]];

        $output = (new FileContextSection())->format($context, ['style' => 'friendly']);

        $this->assertStringContainsString("[1] (relevance: 50%) — art. 1069\n", $output);
        $this->assertStringContainsString("[2] (relevance: 40%) — art. 1070, 1071\n", $output);
    }

    /**
     * Task 1.12: the host enricher's authority_note (e.g. "binding law" vs
     * "app how-to guide") must reach the RESPONSE prompt, next to its file line —
     * it was only rendered in the query-generation section.
     *
     * @test
     */
    public function it_renders_the_authority_note_next_to_the_file_line(): void
    {
        $context = new PipelineContext();
        $context->fileContext = ['relevant_files' => [
            ['filename' => 'civil-code.pdf', 'snippet' => 'Art. 1039', 'relevance' => 0.80, 'authority_note' => 'Quebec law — binding'],
            ['filename' => 'guide.md', 'snippet' => 'Click here', 'relevance' => 0.70, 'priority_tier' => 'how-to'],
        ]];

        $output = (new FileContextSection())->format($context, ['style' => 'friendly']);

        // The note is the HOST's text (trusted): on the header line, beside the number.
        $this->assertStringContainsString("[1] — Quebec law — binding (relevance: 80%) — art. 1039\n\n<retrieved_document id=1>\nFile: civil-code.pdf\n", $output);
        $this->assertStringContainsString("[2] (how-to) (relevance: 70%)\n\n<retrieved_document id=2>\nFile: guide.md\n", $output);
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
