<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\ResponseSections\FileContextSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * File-listing (enumeration) contexts are ACL listings, not retrieval: the
 * response prompt must render them as names/types only — no fabricated
 * "relevance: N%" lines, no empty snippet spotlights — with an explicit
 * instruction that the listing is NOT grounding for content claims. Regular
 * (retrieved) file contexts keep the grounding rendering unchanged.
 */
class FileContextSectionEnumerationTest extends TestCase
{
    private function enumerationContext(): PipelineContext
    {
        $context = new PipelineContext();
        $context->fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 10,
                    'filename' => 'invoice-0042.pdf',
                    'type' => 'pdf',
                    'snippet' => '',
                    'relevance' => null,
                    'listing_only' => true,
                    'chunk_index' => 0,
                    'source' => 'database',
                    'match_type' => 'enumeration',
                ],
                [
                    'file_id' => 11,
                    'filename' => 'holiday.mov',
                    'type' => 'mov',
                    'snippet' => '',
                    'relevance' => null,
                    'listing_only' => true,
                    'chunk_index' => 0,
                    'source' => 'database',
                    'match_type' => 'enumeration',
                ],
            ],
            'file_count' => 2,
            'has_physical' => false,
            'has_database' => true,
            'enumeration' => true,
        ];

        return $context;
    }

    /** @test */
    public function enumeration_contexts_render_as_a_listing_without_relevance_or_snippets(): void
    {
        $output = (new FileContextSection())->format($this->enumerationContext(), ['style' => 'friendly']);

        // Names and types are listed with [N] markers...
        $this->assertStringContainsString('[1] invoice-0042.pdf (pdf)', $output);
        $this->assertStringContainsString('[2] holiday.mov (mov)', $output);

        // ...but NO fabricated relevance percentage and NO content spotlight.
        $this->assertStringNotContainsString('relevance:', strtolower($output));
        $this->assertStringNotContainsString('%', $output);
        $this->assertStringNotContainsString('retrieved_document', $output);

        // The listing-not-grounding instruction is explicit.
        $this->assertStringContainsString('NOT document content', $output);
    }

    /** @test */
    public function retrieved_contexts_keep_the_grounding_rendering(): void
    {
        $context = new PipelineContext();
        $context->fileContext = [
            'relevant_files' => [[
                'file_id' => 1,
                'filename' => 'CCQ_Co-ownership_EN.md',
                'snippet' => 'A co-owner is bound to contribute to the common expenses.',
                'relevance' => 0.72,
                'chunk_index' => 12,
                'source' => 'physical',
            ]],
            'file_count' => 1,
            'has_physical' => true,
            'has_database' => false,
        ];

        $output = (new FileContextSection())->format($context, ['style' => 'friendly']);

        $this->assertStringContainsString('relevance: 72%', $output);
        $this->assertStringContainsString('A co-owner is bound to contribute', $output);
        $this->assertStringNotContainsString('LISTING ONLY', $output);
    }
}
