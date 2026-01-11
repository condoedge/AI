<?php

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Response\FileCitationHandler;
use Condoedge\Ai\Tests\TestCase;

class ContentLinkProcessorTest extends TestCase
{
    private ContentLinkProcessor $processor;

    public function setUp(): void
    {
        parent::setUp();
        $this->processor = new ContentLinkProcessor(
            new ActionLinkHandler(),
            new FileCitationHandler()
        );
    }

    /** @test */
    public function it_returns_file_citation_metadata_for_direct_rendering(): void
    {
        $content = 'Based on [1] and [2], the answer is clear.';
        $files = [
            ['id' => 101, 'name' => 'report.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
            ['id' => 102, 'name' => 'data.xlsx', 'morphable_type' => 'file', 'mime_type' => 'application/xlsx'],
        ];

        $result = $this->processor->processForDirectRendering($content, ['files' => $files]);

        $this->assertArrayHasKey('file_citations', $result);
        $this->assertCount(2, $result['file_citations']);

        $this->assertEquals([
            'slot' => 'file-citation-1',
            'id' => 101,
            'type' => 'file',
            'mime' => 'application/pdf',
        ], $result['file_citations'][0]);

        $this->assertEquals([
            'slot' => 'file-citation-2',
            'id' => 102,
            'type' => 'file',
            'mime' => 'application/xlsx',
        ], $result['file_citations'][1]);
    }

    /** @test */
    public function it_returns_empty_array_when_no_citations(): void
    {
        $content = 'No citations here.';

        $result = $this->processor->processForDirectRendering($content, ['files' => []]);

        $this->assertArrayHasKey('file_citations', $result);
        $this->assertEmpty($result['file_citations']);
    }

    /** @test */
    public function it_returns_empty_array_when_citations_but_no_files(): void
    {
        $content = 'Based on [1] the answer is clear.';

        $result = $this->processor->processForDirectRendering($content, ['files' => []]);

        $this->assertArrayHasKey('file_citations', $result);
        $this->assertEmpty($result['file_citations']);
    }

    /** @test */
    public function it_handles_citation_with_missing_file_gracefully(): void
    {
        $content = 'Based on [1], [2], and [3] the answer is clear.';
        $files = [
            ['id' => 101, 'name' => 'report.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
            // File 2 exists
            ['id' => 102, 'name' => 'data.xlsx', 'morphable_type' => 'file', 'mime_type' => 'application/xlsx'],
            // File 3 is missing - no third element in array
        ];

        $result = $this->processor->processForDirectRendering($content, ['files' => $files]);

        $this->assertArrayHasKey('file_citations', $result);
        // Only 2 citations should have metadata, [3] should be skipped
        $this->assertCount(2, $result['file_citations']);
    }
}
