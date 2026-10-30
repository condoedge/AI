<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Feature;

use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Response\FileCitationHandler;
use Orchestra\Testbench\TestCase;

/**
 * Tests for file citation slot/proxy pattern in MessagesQuery.
 *
 * Verifies that ContentLinkProcessor returns the metadata needed for
 * MessagesQuery to create:
 * - Citation markers leave the text
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

    /** @test Hosts whose users cannot open the cited files turn the citation links and reference cards off. */
    public function no_citation_link_or_reference_card_when_file_references_are_off(): void
    {
        config(['ai.chat.show_file_references' => false]);
        $files = [['id' => 42, 'name' => 'doc.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf']];

        $result = app(ContentLinkProcessor::class)->processForDirectRendering('Based on [1], here is the answer.', ['files' => $files]);
        $renderer = new \Condoedge\Ai\Kompo\Support\RichDataRenderer(
            \Mockery::mock(\Condoedge\Ai\Services\UI\ChatThemeInterface::class),
            \Mockery::mock(\Condoedge\Ai\Services\Settings\ChatSettingsInterface::class),
        );

        $this->assertEmpty($result['elements']);
        $this->assertNull($renderer->renderFileReferences($files));
    }
}
