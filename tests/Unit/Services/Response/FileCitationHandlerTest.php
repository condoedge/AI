<?php

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\FileCitationHandler;
use Condoedge\Ai\Tests\TestCase;

class FileCitationHandlerTest extends TestCase
{
    private FileCitationHandler $handler;

    public function setUp(): void
    {
        parent::setUp();
        $this->handler = new FileCitationHandler();
    }

    public function test_getCitationMetadata_returns_metadata_after_createElements()
    {
        $content = 'See [1] and [2] for details.';
        $files = [
            ['id' => 42, 'name' => 'doc.pdf', 'morphable_type' => 'attachment', 'mime_type' => 'application/pdf'],
            ['id' => 43, 'name' => 'img.png', 'morphable_type' => 'file', 'mime_type' => 'image/png'],
        ];

        $this->handler->createElements($content, ['files' => $files]);
        $metadata = $this->handler->getCitationMetadata();

        $this->assertCount(2, $metadata);

        $this->assertEquals('file-citation-1', $metadata[0]['slot']);
        $this->assertEquals(42, $metadata[0]['id']);
        $this->assertEquals('attachment', $metadata[0]['type']);
        $this->assertEquals('application/pdf', $metadata[0]['mime']);

        $this->assertEquals('file-citation-2', $metadata[1]['slot']);
        $this->assertEquals(43, $metadata[1]['id']);
    }

    public function test_getCitationMetadata_handles_missing_files()
    {
        $content = 'See [1] and [5] for details.';
        $files = [
            ['id' => 42, 'name' => 'doc.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];

        $this->handler->createElements($content, ['files' => $files]);
        $metadata = $this->handler->getCitationMetadata();

        $this->assertCount(1, $metadata);
        $this->assertEquals('file-citation-1', $metadata[0]['slot']);
    }

    public function test_created_elements_have_data_action_slot_attribute()
    {
        $content = 'See [1] for details.';
        $files = [
            ['id' => 42, 'name' => 'doc.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];

        $elements = $this->handler->createElements($content, ['files' => $files]);

        $this->assertCount(1, $elements);

        // Check the element's config for data-action-slot attribute
        // Kompo stores attributes in config['attrs']
        $element = $elements[0];
        $attrs = $element->config['attrs'] ?? [];
        $this->assertArrayHasKey('data-action-slot', $attrs);
        $this->assertEquals('file-citation-1', $attrs['data-action-slot']);
    }

    public function test_created_elements_do_not_have_selfGet_binding()
    {
        $content = 'See [1] for details.';
        $files = [
            ['id' => 42, 'name' => 'doc.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];

        $elements = $this->handler->createElements($content, ['files' => $files]);

        $this->assertNotEmpty($elements, 'Expected at least one element to be created');

        $element = $elements[0];

        // Should NOT have interactions property with selfGet binding
        // Check that no vlAjax or interaction config exists that would indicate selfGet was used
        $interactions = $element->config['interactions'] ?? [];
        foreach ($interactions as $interaction) {
            $this->assertNotContains('viewFile', $interaction['action']['method'] ?? '');
        }
    }
}
