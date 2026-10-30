<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Feature;

use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Response\FileCitationHandler;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Integration tests for file citation click flow.
 *
 * Verifies the slot/proxy pattern produces correct structure for JS wiring:
 * - Citation markers leave the text
 * - Slot IDs match between visible links and hidden proxies
 */
class FilePreviewIntegrationTest extends TestCase
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

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     * Content is stripped of citation markers
     */
    public function test_content_is_stripped_of_citation_markers(): void
    {
        $content = 'According to [1], the report shows [2] important findings.';
        $files = [
            ['id' => 10, 'name' => 'report.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
            ['id' => 11, 'name' => 'data.csv', 'morphable_type' => 'file', 'mime_type' => 'text/csv'],
        ];

        $result = $this->processor->processForDirectRendering($content, ['files' => $files]);

        // Citation markers should be stripped from content
        $this->assertEquals('According to, the report shows important findings.', $result['content']);
    }

    /**
     * @test
     * AiMessage model provides file references for processing
     */
    public function test_ai_message_provides_file_references(): void
    {
        $message = new AiMessage([
            'role' => 'assistant',
            'content' => 'See [1] for details.',
            'metadata' => [
                'sources' => [
                    ['id' => 42, 'name' => 'report.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
                ],
            ],
        ]);

        $this->assertTrue($message->hasFileReferences());
        $files = $message->getReferencedFiles();

        $this->assertCount(1, $files);
        $this->assertEquals(42, $files[0]['id']);
        $this->assertEquals('report.pdf', $files[0]['name']);
    }

    /**
     * @test
     * AiMessage model supports legacy referenced_files key
     */
    public function test_ai_message_supports_legacy_referenced_files_key(): void
    {
        $message = new AiMessage([
            'role' => 'assistant',
            'content' => 'See [1] for details.',
            'metadata' => [
                'referenced_files' => [
                    ['id' => 99, 'name' => 'legacy.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
                ],
            ],
        ]);

        $this->assertTrue($message->hasFileReferences());
        $files = $message->getReferencedFiles();

        $this->assertCount(1, $files);
        $this->assertEquals(99, $files[0]['id']);
    }
}
