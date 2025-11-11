<?php

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\FileExtractorRegistry;
use Condoedge\Ai\Services\Extractors\TextExtractor;
use Condoedge\Ai\Services\Extractors\MarkdownExtractor;
use Condoedge\Ai\Contracts\FileExtractorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileExtractorRegistry
 */
class FileExtractorRegistryTest extends TestCase
{
    private FileExtractorRegistry $registry;

    public function setUp(): void
    {
        parent::setUp();
        $this->registry = new FileExtractorRegistry();
    }

    public function test_registers_single_extractor()
    {
        $extractor = new TextExtractor();

        $this->registry->register($extractor);

        $this->assertTrue($this->registry->supports('txt'));
        $this->assertTrue($this->registry->supports('text'));
        $this->assertTrue($this->registry->supports('log'));
    }

    public function test_registers_multiple_extractors()
    {
        $textExtractor = new TextExtractor();
        $mdExtractor = new MarkdownExtractor();

        $this->registry->registerMany([$textExtractor, $mdExtractor]);

        $this->assertTrue($this->registry->supports('txt'));
        $this->assertTrue($this->registry->supports('md'));
        $this->assertTrue($this->registry->supports('markdown'));
    }

    public function test_gets_extractor_for_extension()
    {
        $extractor = new TextExtractor();
        $this->registry->register($extractor);

        $retrieved = $this->registry->getExtractor('txt');

        $this->assertInstanceOf(FileExtractorInterface::class, $retrieved);
        $this->assertSame($extractor, $retrieved);
    }

    public function test_returns_null_for_unsupported_extension()
    {
        $extractor = $this->registry->getExtractor('unsupported');

        $this->assertNull($extractor);
    }

    public function test_supports_checks_case_insensitively()
    {
        $this->registry->register(new TextExtractor());

        $this->assertTrue($this->registry->supports('TXT'));
        $this->assertTrue($this->registry->supports('Txt'));
        $this->assertTrue($this->registry->supports('txt'));
    }

    public function test_gets_all_supported_extensions()
    {
        $this->registry->registerMany([
            new TextExtractor(),
            new MarkdownExtractor(),
        ]);

        $extensions = $this->registry->getSupportedExtensions();

        $this->assertIsArray($extensions);
        $this->assertContains('txt', $extensions);
        $this->assertContains('md', $extensions);
        $this->assertContains('markdown', $extensions);
    }

    public function test_extracts_text_using_appropriate_extractor()
    {
        $this->registry->register(new TextExtractor());

        // Create a temporary test file
        $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.txt';
        file_put_contents($tempFile, 'Test content');

        try {
            $content = $this->registry->extract($tempFile);

            $this->assertEquals('Test content', $content);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_throws_exception_for_unsupported_file_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported file type');

        $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.xyz';
        touch($tempFile);

        try {
            $this->registry->extract($tempFile);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_extracts_metadata_using_appropriate_extractor()
    {
        $this->registry->register(new TextExtractor());

        $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.txt';
        file_put_contents($tempFile, 'Hello World');

        try {
            $metadata = $this->registry->extractMetadata($tempFile);

            $this->assertIsArray($metadata);
            $this->assertArrayHasKey('file_size', $metadata);
            $this->assertArrayHasKey('line_count', $metadata);
            $this->assertArrayHasKey('word_count', $metadata);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_throws_exception_for_metadata_on_unsupported_type()
    {
        $this->expectException(\InvalidArgumentException::class);

        $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.xyz';
        touch($tempFile);

        try {
            $this->registry->extractMetadata($tempFile);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_gets_statistics()
    {
        $this->registry->registerMany([
            new TextExtractor(),
            new MarkdownExtractor(),
        ]);

        $stats = $this->registry->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_extractors', $stats);
        $this->assertArrayHasKey('supported_extensions', $stats);
        $this->assertArrayHasKey('extensions', $stats);
        $this->assertArrayHasKey('extractors', $stats);

        $this->assertEquals(2, $stats['total_extractors']);
        $this->assertIsArray($stats['extensions']);
        $this->assertContains('txt', $stats['extensions']);
        $this->assertContains('md', $stats['extensions']);
    }

    public function test_overwrites_extractor_for_same_extension()
    {
        $extractor1 = new TextExtractor();
        $extractor2 = new TextExtractor();

        $this->registry->register($extractor1);
        $this->registry->register($extractor2);

        $retrieved = $this->registry->getExtractor('txt');

        // Should be the second extractor
        $this->assertSame($extractor2, $retrieved);
    }

    public function test_skips_non_extractor_objects_in_register_many()
    {
        $this->registry->registerMany([
            new TextExtractor(),
            'not an extractor',
            new MarkdownExtractor(),
        ]);

        // Should only register the valid extractors
        $this->assertTrue($this->registry->supports('txt'));
        $this->assertTrue($this->registry->supports('md'));
    }
}
