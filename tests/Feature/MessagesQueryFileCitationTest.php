<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Response\FileCitationHandler;
use Orchestra\Testbench\TestCase;

/**
 * Tests for file citation slot/proxy pattern in MessagesQuery.
 *
 * Verifies that ContentLinkProcessor returns the metadata needed for
 * MessagesQuery to create:
 * - Visible links with data-action-slot attributes
 * - Hidden proxy elements with data-action-proxy attributes
 *
 * The actual MessagesQuery integration uses this metadata to add proxy
 * elements inline after the link elements.
 */
class MessagesQueryFileCitationTest extends TestCase
{
    /**
     * Don't load the full AI service provider to avoid API key requirements.
     * Only load Kompo for the helper functions.
     */
    protected function getPackageProviders($app): array
    {
        return [
            \Kompo\KompoServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set app key for encryption (required by Kompo)
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // Manually register ContentLinkProcessor with handlers (normally done by AiServiceProvider)
        // Only FileCitationHandler is needed for these tests
        $app->singleton(FileCitationHandler::class);
        $app->singleton(ContentLinkProcessor::class, function ($container) {
            // Only register FileCitationHandler - ActionLinkHandler has complex dependencies
            return new ContentLinkProcessor(
                null, // Skip ActionLinkHandler
                $container->make(FileCitationHandler::class)
            );
        });
    }

    /**
     * @test
     * Test that ContentLinkProcessor returns correct file_citations metadata
     * which MessagesQuery uses to create proxy elements.
     */
    public function content_processor_returns_file_citations_metadata(): void
    {
        $processor = app(ContentLinkProcessor::class);

        $content = 'Based on [1], here is the answer.';
        $files = [
            ['id' => 42, 'name' => 'doc.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];

        $result = $processor->processForDirectRendering($content, ['files' => $files]);

        // Should have file_citations metadata for proxy creation
        $this->assertArrayHasKey('file_citations', $result);
        $this->assertCount(1, $result['file_citations']);
        $this->assertEquals('file-citation-1', $result['file_citations'][0]['slot']);
        $this->assertEquals(42, $result['file_citations'][0]['id']);
        $this->assertEquals('file', $result['file_citations'][0]['type']);
        $this->assertEquals('application/pdf', $result['file_citations'][0]['mime']);
    }

    /**
     * @test
     */
    public function content_processor_returns_multiple_citations_metadata(): void
    {
        $processor = app(ContentLinkProcessor::class);

        $content = 'See [1] and [2] for details.';
        $files = [
            ['id' => 1, 'name' => 'first.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
            ['id' => 2, 'name' => 'second.xlsx', 'morphable_type' => 'file', 'mime_type' => 'application/xlsx'],
        ];

        $result = $processor->processForDirectRendering($content, ['files' => $files]);

        // Should have metadata for both citations
        $this->assertCount(2, $result['file_citations']);

        // First citation
        $this->assertEquals('file-citation-1', $result['file_citations'][0]['slot']);
        $this->assertEquals(1, $result['file_citations'][0]['id']);

        // Second citation
        $this->assertEquals('file-citation-2', $result['file_citations'][1]['slot']);
        $this->assertEquals(2, $result['file_citations'][1]['id']);
    }

    /**
     * @test
     */
    public function content_processor_returns_empty_citations_when_no_files(): void
    {
        $processor = app(ContentLinkProcessor::class);

        $content = 'This is a simple response without file citations.';

        $result = $processor->processForDirectRendering($content, ['files' => []]);

        // Should have empty file_citations array
        $this->assertArrayHasKey('file_citations', $result);
        $this->assertEmpty($result['file_citations']);
    }

    /**
     * @test
     * Test that visible citation links have data-action-slot attribute.
     */
    public function content_processor_creates_link_elements_with_slot_attribute(): void
    {
        $processor = app(ContentLinkProcessor::class);

        $content = 'See [1] for info.';
        $files = [
            ['id' => 10, 'name' => 'test.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];

        $result = $processor->processForDirectRendering($content, ['files' => $files]);

        // Should have elements with data-action-slot
        $this->assertNotEmpty($result['elements']);

        // Check JSON contains slot attribute
        $json = json_encode($result['elements']);
        $this->assertStringContainsString('data-action-slot', $json);
        $this->assertStringContainsString('file-citation-1', $json);
    }
}
