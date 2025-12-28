<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Files;

use Condoedge\Ai\Services\Context\FileAccessResolver;
use Condoedge\Ai\Services\Files\PhysicalFileIndexer;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;

class PhysicalFileIndexerTest extends TestCase
{
    private PhysicalFileIndexer $indexer;
    private string $tempDir;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup will be done in setUp method with temp directory
    }

    public function setUp(): void
    {
        parent::setUp();

        // Create temp directory for test files
        $this->tempDir = sys_get_temp_dir() . '/physical_file_indexer_test_' . uniqid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }

        // Create nested directory structure
        mkdir($this->tempDir . '/docs', 0777, true);
        mkdir($this->tempDir . '/docs/api', 0777, true);
        mkdir($this->tempDir . '/docs/guides', 0777, true);

        // Create test files
        file_put_contents($this->tempDir . '/docs/readme.md', '# Readme');
        file_put_contents($this->tempDir . '/docs/api/endpoints.md', '# API Endpoints');
        file_put_contents($this->tempDir . '/docs/api/auth.mdx', '# Authentication');
        file_put_contents($this->tempDir . '/docs/guides/getting-started.md', '# Getting Started');
        file_put_contents($this->tempDir . '/docs/guides/advanced.txt', 'Advanced Guide');
        file_put_contents($this->tempDir . '/docs/data.json', '{}'); // Unsupported extension

        // Set up config
        config([
            'ai.file_context.base_path' => $this->tempDir,
            'ai.file_context.physical_paths' => [
                'docs/**/*.md',
                'docs/**/*.mdx',
            ],
            'ai.file_context.supported_extensions' => ['md', 'mdx', 'txt', 'rst'],
        ]);

        $this->indexer = new PhysicalFileIndexer();
    }

    public function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }

        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object !== '.' && $object !== '..') {
                    $path = $dir . '/' . $object;
                    if (is_dir($path)) {
                        $this->recursiveDelete($path);
                    } else {
                        unlink($path);
                    }
                }
            }
            rmdir($dir);
        }
    }

    // =========================================================================
    // discoverFiles tests
    // =========================================================================

    /** @test */
    public function it_discovers_files_from_glob_patterns(): void
    {
        $files = $this->indexer->discoverFiles(['docs/**/*.md']);

        $this->assertIsArray($files);
        $this->assertCount(3, $files);

        // Should contain absolute paths (normalize for comparison)
        $normalizedTempDir = str_replace('\\', '/', $this->tempDir);
        $this->assertStringContainsString($normalizedTempDir, $files[0]);
    }

    /** @test */
    public function it_discovers_files_using_config_patterns_when_no_patterns_provided(): void
    {
        // Config has docs/**/*.md and docs/**/*.mdx
        $files = $this->indexer->discoverFiles();

        $this->assertIsArray($files);
        $this->assertCount(4, $files); // 3 .md + 1 .mdx
    }

    /** @test */
    public function it_returns_absolute_file_paths(): void
    {
        $files = $this->indexer->discoverFiles(['docs/readme.md']);

        $this->assertCount(1, $files);
        // Normalize paths for cross-platform comparison
        $expectedPath = str_replace('\\', '/', $this->tempDir) . '/docs/readme.md';
        $this->assertEquals($expectedPath, $files[0]);
    }

    /** @test */
    public function it_handles_recursive_patterns(): void
    {
        $files = $this->indexer->discoverFiles(['docs/**/*.md']);

        // Should find readme.md, endpoints.md, and getting-started.md
        $this->assertCount(3, $files);

        $filenames = array_map(fn($f) => basename($f), $files);
        $this->assertContains('readme.md', $filenames);
        $this->assertContains('endpoints.md', $filenames);
        $this->assertContains('getting-started.md', $filenames);
    }

    /** @test */
    public function it_filters_by_supported_extensions(): void
    {
        // Request all files but should be filtered to supported extensions
        $files = $this->indexer->discoverFiles(['docs/**/*.*']);

        // Should not include .json files
        $filenames = array_map(fn($f) => basename($f), $files);
        $this->assertNotContains('data.json', $filenames);
    }

    /** @test */
    public function it_returns_empty_array_when_no_files_match(): void
    {
        $files = $this->indexer->discoverFiles(['nonexistent/**/*.md']);

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    /** @test */
    public function it_handles_multiple_patterns(): void
    {
        $files = $this->indexer->discoverFiles([
            'docs/readme.md',
            'docs/api/**/*.md',
        ]);

        $this->assertCount(2, $files);

        $filenames = array_map(fn($f) => basename($f), $files);
        $this->assertContains('readme.md', $filenames);
        $this->assertContains('endpoints.md', $filenames);
    }

    /** @test */
    public function it_removes_duplicate_files_from_multiple_patterns(): void
    {
        $files = $this->indexer->discoverFiles([
            'docs/**/*.md',
            'docs/readme.md', // Duplicate
        ]);

        // Should have unique files only
        $this->assertCount(count(array_unique($files)), $files);
    }

    // =========================================================================
    // generateFileId tests
    // =========================================================================

    /** @test */
    public function it_generates_file_id_with_physical_prefix(): void
    {
        $path = $this->tempDir . '/docs/readme.md';

        $id = $this->indexer->generateFileId($path);

        $this->assertStringStartsWith(FileAccessResolver::PHYSICAL_PREFIX, $id);
    }

    /** @test */
    public function it_generates_file_id_with_relative_path(): void
    {
        $path = $this->tempDir . '/docs/api/endpoints.md';

        $id = $this->indexer->generateFileId($path);

        $this->assertEquals('physical:docs/api/endpoints.md', $id);
    }

    /** @test */
    public function it_normalizes_path_separators_in_file_id(): void
    {
        // Test with backslashes (Windows-style)
        $path = $this->tempDir . '\\docs\\readme.md';

        $id = $this->indexer->generateFileId($path);

        // Should use forward slashes in the ID
        $this->assertEquals('physical:docs/readme.md', $id);
    }

    // =========================================================================
    // createFileObject tests
    // =========================================================================

    /** @test */
    public function it_creates_file_object_with_required_properties(): void
    {
        $path = $this->tempDir . '/docs/readme.md';

        $file = $this->indexer->createFileObject($path);

        $this->assertIsObject($file);
        $this->assertTrue(property_exists($file, 'id'));
        $this->assertTrue(property_exists($file, 'name'));
        $this->assertTrue(property_exists($file, 'path'));
        $this->assertTrue(property_exists($file, 'extension'));
        $this->assertTrue(property_exists($file, 'size'));
        $this->assertTrue(property_exists($file, 'is_physical'));
    }

    /** @test */
    public function it_creates_file_object_with_correct_id(): void
    {
        $path = $this->tempDir . '/docs/readme.md';

        $file = $this->indexer->createFileObject($path);

        $this->assertEquals('physical:docs/readme.md', $file->id);
    }

    /** @test */
    public function it_creates_file_object_with_correct_name(): void
    {
        $path = $this->tempDir . '/docs/api/endpoints.md';

        $file = $this->indexer->createFileObject($path);

        $this->assertEquals('endpoints.md', $file->name);
    }

    /** @test */
    public function it_creates_file_object_with_absolute_path(): void
    {
        $path = $this->tempDir . '/docs/readme.md';

        $file = $this->indexer->createFileObject($path);

        $this->assertEquals($path, $file->path);
    }

    /** @test */
    public function it_creates_file_object_with_correct_extension(): void
    {
        $path = $this->tempDir . '/docs/api/auth.mdx';

        $file = $this->indexer->createFileObject($path);

        $this->assertEquals('mdx', $file->extension);
    }

    /** @test */
    public function it_creates_file_object_with_correct_size(): void
    {
        $path = $this->tempDir . '/docs/readme.md';

        $file = $this->indexer->createFileObject($path);

        $this->assertEquals(filesize($path), $file->size);
    }

    /** @test */
    public function it_creates_file_object_marked_as_physical(): void
    {
        $path = $this->tempDir . '/docs/readme.md';

        $file = $this->indexer->createFileObject($path);

        $this->assertTrue($file->is_physical);
    }

    // =========================================================================
    // createFileObjects tests
    // =========================================================================

    /** @test */
    public function it_creates_file_objects_for_discovered_files(): void
    {
        $files = $this->indexer->createFileObjects(['docs/**/*.md']);

        $this->assertIsArray($files);
        $this->assertCount(3, $files);

        foreach ($files as $file) {
            $this->assertIsObject($file);
            $this->assertTrue(property_exists($file, 'id'));
            $this->assertTrue($file->is_physical);
        }
    }

    /** @test */
    public function it_creates_file_objects_using_config_patterns_when_null(): void
    {
        $files = $this->indexer->createFileObjects();

        $this->assertIsArray($files);
        $this->assertCount(4, $files); // 3 .md + 1 .mdx from config patterns
    }

    /** @test */
    public function it_returns_empty_array_when_no_files_discovered(): void
    {
        $files = $this->indexer->createFileObjects(['nonexistent/**/*.md']);

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    /** @test */
    public function it_handles_empty_patterns_array(): void
    {
        $files = $this->indexer->discoverFiles([]);

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    /** @test */
    public function it_handles_special_characters_in_filenames(): void
    {
        // Create file with special characters
        $specialPath = $this->tempDir . '/docs/file with spaces.md';
        file_put_contents($specialPath, '# File with spaces');

        $files = $this->indexer->discoverFiles(['docs/**/*.md']);

        $filenames = array_map(fn($f) => basename($f), $files);
        $this->assertContains('file with spaces.md', $filenames);
    }

    /** @test */
    public function it_uses_forward_slashes_in_relative_paths(): void
    {
        $path = $this->tempDir . '/docs/api/endpoints.md';

        $id = $this->indexer->generateFileId($path);

        // Should use forward slashes regardless of OS
        $this->assertStringNotContainsString('\\', $id);
        $this->assertEquals('physical:docs/api/endpoints.md', $id);
    }
}
