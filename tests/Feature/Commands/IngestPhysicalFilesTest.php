<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature\Commands;

use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\DTOs\ProcessingResult;
use Condoedge\Ai\Services\Files\PhysicalFileIndexer;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Mockery;

class IngestPhysicalFilesTest extends TestCase
{
    private string $tempDir;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Set up file context configuration
        $app['config']->set('ai.file_context.enabled', true);
        $app['config']->set('ai.file_context.physical_collection', 'test_docs');
        $app['config']->set('ai.file_context.supported_extensions', ['md', 'mdx', 'txt']);

        // Set up minimal API keys for testing
        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
    }

    public function setUp(): void
    {
        parent::setUp();

        // Create temp directory with test files
        $this->tempDir = sys_get_temp_dir() . '/ai_ingest_test_' . uniqid();

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }

        // Create a test file
        file_put_contents($this->tempDir . '/test.md', '# Test Document

This is test content for the AI ingestion command.');

        // Create nested directory with another file
        mkdir($this->tempDir . '/docs', 0777, true);
        file_put_contents($this->tempDir . '/docs/guide.md', '# Guide

This is a guide document.');

        // Configure the paths
        config([
            'ai.file_context.base_path' => $this->tempDir,
            'ai.file_context.physical_paths' => ['**/*.md'],
        ]);
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

    /** @test */
    public function it_has_docs_option(): void
    {
        $this->artisan('ai:ingest --help')
            ->expectsOutputToContain('--docs');
    }

    /** @test */
    public function it_has_dry_run_option(): void
    {
        $this->artisan('ai:ingest --help')
            ->expectsOutputToContain('--dry-run');
    }

    /** @test */
    public function it_discovers_physical_files_with_docs_flag_dry_run(): void
    {
        // Test with dry-run to avoid needing to mock file processing
        $this->artisan('ai:ingest --docs --dry-run')
            ->expectsOutputToContain('Discovering physical documentation files')
            ->expectsOutputToContain('Found 2 files')
            ->assertSuccessful();
    }

    /** @test */
    public function dry_run_shows_files_without_actually_indexing(): void
    {
        // The dry run should show a table with the files
        $this->artisan('ai:ingest --docs --dry-run')
            ->expectsOutputToContain('test.md')
            ->expectsOutputToContain('guide.md')
            ->assertSuccessful();
    }

    /** @test */
    public function it_shows_warning_when_no_files_found(): void
    {
        // Configure with a pattern that matches nothing
        config(['ai.file_context.physical_paths' => ['nonexistent/**/*.xyz']]);

        $this->artisan('ai:ingest --docs')
            ->expectsOutputToContain('No files found matching configured patterns')
            ->assertSuccessful();
    }

    /** @test */
    public function it_processes_files_with_file_processor(): void
    {
        // Mock the FileProcessor to verify it gets called
        $mockProcessor = Mockery::mock(FileProcessorInterface::class);

        // Create a successful result using the correct constructor
        $successResult = ProcessingResult::success(
            fileId: 1,
            chunksCreated: 1,
            embeddingsGenerated: 1,
            processingTimeSeconds: 0.1
        );

        $mockProcessor->shouldReceive('processFile')
            ->times(2) // Two files in temp dir
            ->andReturn($successResult);

        $this->app->instance(FileProcessorInterface::class, $mockProcessor);

        $this->artisan('ai:ingest --docs')
            ->expectsOutputToContain('Success: 2')
            ->assertSuccessful();
    }

    /** @test */
    public function it_shows_progress_during_indexing(): void
    {
        // Mock the FileProcessor
        $mockProcessor = Mockery::mock(FileProcessorInterface::class);

        $successResult = ProcessingResult::success(
            fileId: 1,
            chunksCreated: 1,
            embeddingsGenerated: 1,
            processingTimeSeconds: 0.1
        );

        $mockProcessor->shouldReceive('processFile')
            ->andReturn($successResult);

        $this->app->instance(FileProcessorInterface::class, $mockProcessor);

        $this->artisan('ai:ingest --docs')
            ->expectsOutputToContain('Indexing complete')
            ->assertSuccessful();
    }

    /** @test */
    public function it_uses_configured_collection_name(): void
    {
        config(['ai.file_context.physical_collection' => 'custom_docs_collection']);

        $mockProcessor = Mockery::mock(FileProcessorInterface::class);

        $successResult = ProcessingResult::success(
            fileId: 1,
            chunksCreated: 1,
            embeddingsGenerated: 1,
            processingTimeSeconds: 0.1
        );

        // Verify the collection option is passed
        $mockProcessor->shouldReceive('processFile')
            ->withArgs(function ($file, $options) {
                return isset($options['collection']) && $options['collection'] === 'custom_docs_collection';
            })
            ->andReturn($successResult);

        $this->app->instance(FileProcessorInterface::class, $mockProcessor);

        $this->artisan('ai:ingest --docs')
            ->assertSuccessful();
    }

    /** @test */
    public function it_handles_processing_failures_gracefully(): void
    {
        $mockProcessor = Mockery::mock(FileProcessorInterface::class);

        // First file succeeds, second fails
        $successResult = ProcessingResult::success(
            fileId: 1,
            chunksCreated: 1,
            embeddingsGenerated: 1,
            processingTimeSeconds: 0.1
        );

        $failResult = ProcessingResult::failure(
            fileId: 2,
            error: 'Processing failed',
            processingTimeSeconds: 0.1
        );

        $mockProcessor->shouldReceive('processFile')
            ->andReturn($successResult, $failResult);

        $this->app->instance(FileProcessorInterface::class, $mockProcessor);

        // Should still complete but show mixed results
        $this->artisan('ai:ingest --docs')
            ->expectsOutputToContain('Success: 1')
            ->expectsOutputToContain('Failed: 1');
    }

    /** @test */
    public function it_tracks_skipped_files(): void
    {
        $mockProcessor = Mockery::mock(FileProcessorInterface::class);

        // Files with 0 chunks created but success=true can be considered skipped
        // (e.g., already processed files)
        $skippedResult = ProcessingResult::success(
            fileId: 1,
            chunksCreated: 0,
            embeddingsGenerated: 0,
            processingTimeSeconds: 0.01,
            metadata: ['skipped' => true, 'reason' => 'already processed']
        );

        $mockProcessor->shouldReceive('processFile')
            ->andReturn($skippedResult);

        $this->app->instance(FileProcessorInterface::class, $mockProcessor);

        $this->artisan('ai:ingest --docs')
            ->expectsOutputToContain('Skipped:')
            ->assertSuccessful();
    }
}
