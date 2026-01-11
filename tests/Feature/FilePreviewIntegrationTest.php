<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

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
 * - Visible links have data-action-slot attributes
 * - Hidden proxies have data-action-proxy attributes
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
     * File citations in staged response should produce slot/proxy pairs
     */
    public function test_file_citations_produce_slot_proxy_pairs_in_staged_response(): void
    {
        // Simulate AI response with file citations
        $content = 'According to [1] and [2], the data shows...';
        $files = [
            ['id' => 10, 'name' => 'report.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
            ['id' => 11, 'name' => 'data.csv', 'morphable_type' => 'file', 'mime_type' => 'text/csv'],
        ];

        $result = $this->processor->processForDirectRendering($content, ['files' => $files]);

        // Verify elements are created for citations
        $this->assertCount(2, $result['elements']);
        $this->assertTrue($result['has_links']);

        // Verify citation metadata for proxy creation
        $this->assertCount(2, $result['file_citations']);
        $this->assertEquals('file-citation-1', $result['file_citations'][0]['slot']);
        $this->assertEquals(10, $result['file_citations'][0]['id']);
        $this->assertEquals('file', $result['file_citations'][0]['type']);
        $this->assertEquals('application/pdf', $result['file_citations'][0]['mime']);

        $this->assertEquals('file-citation-2', $result['file_citations'][1]['slot']);
        $this->assertEquals(11, $result['file_citations'][1]['id']);
        $this->assertEquals('file', $result['file_citations'][1]['type']);
        $this->assertEquals('text/csv', $result['file_citations'][1]['mime']);
    }

    /**
     * @test
     * Visible link elements should have data-action-slot attribute
     */
    public function test_visible_link_elements_have_data_action_slot_attribute(): void
    {
        $content = 'See [1] for details.';
        $files = [
            ['id' => 42, 'name' => 'manual.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];

        $result = $this->processor->processForDirectRendering($content, ['files' => $files]);

        $this->assertCount(1, $result['elements']);

        // Check element has data-action-slot attribute
        $element = $result['elements'][0];
        $attrs = $element->config['attrs'] ?? [];

        $this->assertArrayHasKey('data-action-slot', $attrs);
        $this->assertEquals('file-citation-1', $attrs['data-action-slot']);
    }

    /**
     * @test
     * Citation metadata provides correct info for proxy creation
     */
    public function test_citation_metadata_provides_correct_proxy_info(): void
    {
        $content = 'Refer to [1], [2], and [3] in the documentation.';
        $files = [
            ['id' => 100, 'name' => 'doc1.pdf', 'morphable_type' => 'attachment', 'mime_type' => 'application/pdf'],
            ['id' => 101, 'name' => 'doc2.xlsx', 'morphable_type' => 'file', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['id' => 102, 'name' => 'image.png', 'morphable_type' => 'file', 'mime_type' => 'image/png'],
        ];

        $result = $this->processor->processForDirectRendering($content, ['files' => $files]);

        // Verify we have 3 citation metadata entries
        $this->assertCount(3, $result['file_citations']);

        // Each should have slot, id, type, and mime
        foreach ($result['file_citations'] as $index => $citation) {
            $expectedSlot = 'file-citation-' . ($index + 1);
            $this->assertEquals($expectedSlot, $citation['slot']);
            $this->assertArrayHasKey('id', $citation);
            $this->assertArrayHasKey('type', $citation);
            $this->assertArrayHasKey('mime', $citation);
        }

        // Verify specific values
        $this->assertEquals('attachment', $result['file_citations'][0]['type']);
        $this->assertEquals('file', $result['file_citations'][1]['type']);
        $this->assertEquals('image/png', $result['file_citations'][2]['mime']);
    }

    /**
     * @test
     * No citations means no proxies or elements created
     */
    public function test_no_proxies_created_when_no_file_citations(): void
    {
        $content = 'This message has no citations.';
        $files = []; // No files

        $result = $this->processor->processForDirectRendering($content, ['files' => $files]);

        // Should have no file citation elements
        $this->assertEmpty($result['file_citations']);

        // Content should be unchanged (no citation markers to strip)
        $this->assertEquals('This message has no citations.', $result['content']);
    }

    /**
     * @test
     * Citations without matching files are skipped
     */
    public function test_citations_without_matching_files_are_skipped(): void
    {
        $content = 'See [1], [5], and [10] for details.';
        $files = [
            ['id' => 42, 'name' => 'only-one.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];

        $result = $this->processor->processForDirectRendering($content, ['files' => $files]);

        // Only [1] has a matching file
        $this->assertCount(1, $result['elements']);
        $this->assertCount(1, $result['file_citations']);
        $this->assertEquals('file-citation-1', $result['file_citations'][0]['slot']);
    }

    /**
     * @test
     * Duplicate citations are deduplicated
     */
    public function test_duplicate_citations_are_deduplicated(): void
    {
        $content = 'See [1] and also check [1] again.';
        $files = [
            ['id' => 42, 'name' => 'report.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];

        $result = $this->processor->processForDirectRendering($content, ['files' => $files]);

        // Should only have one element despite two [1] citations
        $this->assertCount(1, $result['elements']);
        $this->assertCount(1, $result['file_citations']);
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
        $this->assertEquals('According to , the report shows  important findings.', $result['content']);
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

    /**
     * @test
     * Full flow: message with citations produces correct slot/proxy structure
     */
    public function test_full_flow_message_citations_produce_slot_proxy_structure(): void
    {
        // Create a message with file references
        $message = new AiMessage([
            'role' => 'assistant',
            'content' => 'According to [1] and [2], the data shows significant trends.',
            'metadata' => [
                'sources' => [
                    ['id' => 10, 'name' => 'report.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
                    ['id' => 11, 'name' => 'data.csv', 'morphable_type' => 'file', 'mime_type' => 'text/csv'],
                ],
            ],
        ]);

        // Get files from message (as done in MessagesQuery)
        $files = $message->hasFileReferences() ? $message->getReferencedFiles() : [];

        // Process content (as done in MessagesQuery and HasStagedMessageRendering)
        $result = $this->processor->processForDirectRendering($message->content, ['files' => $files]);

        // Verify the complete structure needed for JS wiring
        $this->assertTrue($result['has_links']);

        // Visible elements with slots
        $this->assertCount(2, $result['elements']);
        foreach ($result['elements'] as $index => $element) {
            $attrs = $element->config['attrs'] ?? [];
            $this->assertArrayHasKey('data-action-slot', $attrs);
        }

        // Proxy metadata with matching slots
        $this->assertCount(2, $result['file_citations']);
        foreach ($result['file_citations'] as $index => $citation) {
            $expectedSlot = 'file-citation-' . ($index + 1);
            $this->assertEquals($expectedSlot, $citation['slot']);

            // Verify proxy can be created with this metadata
            $this->assertArrayHasKey('id', $citation);
            $this->assertArrayHasKey('type', $citation);
            $this->assertArrayHasKey('mime', $citation);
        }

        // Content is clean (markers stripped)
        $this->assertStringNotContainsString('[1]', $result['content']);
        $this->assertStringNotContainsString('[2]', $result['content']);
    }

    /**
     * @test
     * Slot naming convention matches between handler and metadata
     */
    public function test_slot_naming_convention_is_consistent(): void
    {
        $content = 'See [1] and [2].';
        $files = [
            ['id' => 1, 'name' => 'a.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
            ['id' => 2, 'name' => 'b.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];

        $result = $this->processor->processForDirectRendering($content, ['files' => $files]);

        // Get slots from elements
        $elementSlots = [];
        foreach ($result['elements'] as $element) {
            $elementSlots[] = $element->config['attrs']['data-action-slot'] ?? null;
        }

        // Get slots from metadata
        $metadataSlots = array_column($result['file_citations'], 'slot');

        // They should match
        $this->assertEquals($elementSlots, $metadataSlots);
    }

    /**
     * @test
     * Multiple processing calls reset state correctly
     */
    public function test_multiple_processing_calls_reset_state(): void
    {
        // First call
        $result1 = $this->processor->processForDirectRendering('[1]', [
            'files' => [['id' => 1, 'name' => 'a.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf']],
        ]);

        // Second call
        $result2 = $this->processor->processForDirectRendering('[1] and [2]', [
            'files' => [
                ['id' => 2, 'name' => 'b.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
                ['id' => 3, 'name' => 'c.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
            ],
        ]);

        // Second result should not contain first result's metadata
        $this->assertCount(1, $result1['file_citations']);
        $this->assertCount(2, $result2['file_citations']);

        // IDs should be from second call only
        $this->assertEquals(2, $result2['file_citations'][0]['id']);
        $this->assertEquals(3, $result2['file_citations'][1]['id']);
    }
}
