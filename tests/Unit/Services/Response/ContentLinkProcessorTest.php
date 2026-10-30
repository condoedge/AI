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
    public function citation_markers_leave_the_text_and_make_no_element(): void
    {
        $files = [['id' => 101, 'name' => 'report.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf']];

        $result = $this->processor->processForDirectRendering('Based on [1] and [2], the answer is clear.', ['files' => $files]);

        $this->assertSame('Based on and, the answer is clear.', $result['content']);
        $this->assertSame([], $result['elements']);
        $this->assertFalse($result['has_links']);
    }
}
