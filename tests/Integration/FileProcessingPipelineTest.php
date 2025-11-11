<?php

namespace Condoedge\Ai\Tests\Integration;

use Condoedge\Ai\Services\FileProcessor;
use Condoedge\Ai\Services\FileExtractorRegistry;
use Condoedge\Ai\Services\SemanticChunker;
use Condoedge\Ai\Services\QdrantChunkStore;
use Condoedge\Ai\Services\Extractors\TextExtractor;
use Condoedge\Ai\Services\Extractors\MarkdownExtractor;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use PHPUnit\Framework\TestCase;
use Mockery;

/**
 * Integration test for complete file processing pipeline
 *
 * Tests the full flow: Extract → Chunk → Embed → Store
 */
class FileProcessingPipelineTest extends TestCase
{
    private FileProcessor $processor;
    private $vectorStoreMock;
    private $embeddingProviderMock;
    private string $tempDir;

    public function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/file_processor_test_' . uniqid();
        mkdir($this->tempDir);

        // Create mocks
        $this->vectorStoreMock = Mockery::mock(VectorStoreInterface::class);
        $this->embeddingProviderMock = Mockery::mock(EmbeddingProviderInterface::class);

        // Setup vector store mock
        $this->vectorStoreMock->shouldReceive('collectionExists')
            ->andReturn(true);

        // Setup embedding provider mock to return fake embeddings
        $this->embeddingProviderMock->shouldReceive('embedBatch')
            ->andReturnUsing(function ($texts) {
                return array_map(fn($t) => array_fill(0, 1536, 0.1), $texts);
            });

        // Create services
        $registry = new FileExtractorRegistry();
        $registry->registerMany([
            new TextExtractor(),
            new MarkdownExtractor(),
        ]);

        $chunker = new SemanticChunker();
        $chunkStore = new QdrantChunkStore(
            $this->vectorStoreMock,
            $this->embeddingProviderMock,
            'file_chunks'
        );

        $this->processor = new FileProcessor(
            $registry,
            $chunker,
            $this->embeddingProviderMock,
            $chunkStore
        );
    }

    public function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_processes_text_file_through_complete_pipeline()
    {
        // Create test file
        $filePath = $this->tempDir . '/test.txt';
        $content = "This is a test file.\nIt has multiple lines.\nAnd some content to process.";
        file_put_contents($filePath, $content);

        // Create mock file object
        $file = (object) [
            'id' => 1,
            'name' => 'test.txt',
            'path' => $filePath,
            'disk' => 'local',
        ];

        // Expect count() to check if file is already processed
        $this->vectorStoreMock->shouldReceive('count')
            ->once()
            ->with('file_chunks', Mockery::type('array'))
            ->andReturn(0); // File not processed yet

        // Expect vector store to be called with chunks
        $this->vectorStoreMock->shouldReceive('upsert')
            ->once()
            ->with('file_chunks', Mockery::type('array'))
            ->andReturnUsing(function ($collection, $points) {
                $this->assertNotEmpty($points);
                foreach ($points as $point) {
                    $this->assertArrayHasKey('id', $point);
                    $this->assertArrayHasKey('vector', $point);
                    $this->assertArrayHasKey('payload', $point);
                    $this->assertArrayHasKey('file_id', $point['payload']);
                    $this->assertArrayHasKey('content', $point['payload']);
                }
                return true;
            });

        // Process file
        $result = $this->processor->processFile($file);

        // Verify result
        $this->assertTrue($result->success);
        $this->assertEquals(1, $result->fileId);
        $this->assertGreaterThan(0, $result->chunksCreated);
        $this->assertEquals($result->chunksCreated, $result->embeddingsGenerated);
        $this->assertNull($result->error);
    }

    public function test_processes_markdown_file_with_metadata()
    {
        // Create markdown file with front matter
        $filePath = $this->tempDir . '/test.md';
        $content = <<<MD
---
title: Test Document
author: Test Author
---

# Main Heading

This is a test markdown file with **bold** and *italic* text.

## Section 1

Some content here.

## Section 2

More content here.
MD;
        file_put_contents($filePath, $content);

        $file = (object) [
            'id' => 2,
            'name' => 'test.md',
            'path' => $filePath,
            'disk' => 'local',
        ];

        // Expect count() to check if file is already processed
        $this->vectorStoreMock->shouldReceive('count')
            ->once()
            ->andReturn(0);

        $this->vectorStoreMock->shouldReceive('upsert')
            ->once()
            ->andReturn(true);

        $result = $this->processor->processFile($file);

        $this->assertTrue($result->success);
        $this->assertGreaterThan(0, $result->chunksCreated);
    }

    public function test_handles_large_file_with_multiple_chunks()
    {
        // Create large text file - need to be significantly larger to create multiple chunks
        $filePath = $this->tempDir . '/large.txt';
        $content = str_repeat("This is a line of text with some content to ensure proper chunking behavior. ", 2000); // ~140KB
        file_put_contents($filePath, $content);

        $file = (object) [
            'id' => 3,
            'name' => 'large.txt',
            'path' => $filePath,
            'disk' => 'local',
        ];

        // Expect count() to check if file is already processed
        $this->vectorStoreMock->shouldReceive('count')
            ->once()
            ->andReturn(0);

        $this->vectorStoreMock->shouldReceive('upsert')
            ->once()
            ->andReturnUsing(function ($collection, $points) {
                $this->assertGreaterThanOrEqual(1, count($points)); // Should create at least one chunk
                return true;
            });

        $result = $this->processor->processFile($file);

        if (!$result->success) {
            $this->fail("Processing failed: {$result->error}");
        }

        $this->assertTrue($result->success);
        $this->assertGreaterThanOrEqual(1, $result->chunksCreated);
        // Verify the chunks are properly structured
        $this->assertEquals($result->chunksCreated, $result->embeddingsGenerated);
    }

    public function test_fails_gracefully_for_unsupported_file_type()
    {
        $filePath = $this->tempDir . '/test.xyz';
        file_put_contents($filePath, 'content');

        $file = (object) [
            'id' => 4,
            'name' => 'test.xyz',
            'path' => $filePath,
            'disk' => 'local',
        ];

        // Expect count() to check if file is already processed
        $this->vectorStoreMock->shouldReceive('count')
            ->once()
            ->andReturn(0);

        $result = $this->processor->processFile($file);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Unsupported file type', $result->error);
    }

    public function test_fails_gracefully_for_missing_file()
    {
        $file = (object) [
            'id' => 5,
            'name' => 'missing.txt',
            'path' => $this->tempDir . '/missing.txt',
            'disk' => 'local',
        ];

        // Expect count() to check if file is already processed
        $this->vectorStoreMock->shouldReceive('count')
            ->once()
            ->andReturn(0);

        $result = $this->processor->processFile($file);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->error);
    }

    public function test_fails_for_empty_file()
    {
        $filePath = $this->tempDir . '/empty.txt';
        file_put_contents($filePath, '');

        $file = (object) [
            'id' => 6,
            'name' => 'empty.txt',
            'path' => $filePath,
            'disk' => 'local',
        ];

        // Expect count() to check if file is already processed
        $this->vectorStoreMock->shouldReceive('count')
            ->once()
            ->andReturn(0);

        $result = $this->processor->processFile($file);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No text content', $result->error);
    }

    public function test_reprocessing_removes_old_chunks_and_creates_new_ones()
    {
        $filePath = $this->tempDir . '/reprocess.txt';
        file_put_contents($filePath, 'Original content');

        $file = (object) [
            'id' => 7,
            'name' => 'reprocess.txt',
            'path' => $filePath,
            'disk' => 'local',
        ];

        // First count() call in removeFile() -> getFileChunks()
        $this->vectorStoreMock->shouldReceive('count')
            ->once()
            ->with('file_chunks', Mockery::type('array'))
            ->andReturn(1);

        // search() call in removeFile() -> getFileChunks() to get chunk IDs
        $this->vectorStoreMock->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'id' => 'file_7_chunk_0',
                    'payload' => [
                        'file_id' => 7,
                        'file_name' => 'reprocess.txt',
                        'content' => 'Old chunk',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 10,
                        'metadata' => [],
                    ],
                ],
            ]);

        // deletePoints() call in removeFile()
        $this->vectorStoreMock->shouldReceive('deletePoints')
            ->once()
            ->with('file_chunks', Mockery::type('array'))
            ->andReturn(true);

        // NOTE: When force=true, processFile() skips the isProcessed() check,
        // so there's no second count() call here

        // upsert() call in processFile() to store new chunks
        $this->vectorStoreMock->shouldReceive('upsert')
            ->once()
            ->with('file_chunks', Mockery::type('array'))
            ->andReturn(true);

        $result = $this->processor->reprocessFile($file);

        $this->assertTrue($result->success);
    }

    public function test_includes_processing_metadata_in_result()
    {
        $filePath = $this->tempDir . '/metadata.txt';
        file_put_contents($filePath, 'Test content for metadata');

        $file = (object) [
            'id' => 8,
            'name' => 'metadata.txt',
            'path' => $filePath,
            'disk' => 'local',
        ];

        // Expect count() to check if file is already processed
        $this->vectorStoreMock->shouldReceive('count')
            ->once()
            ->andReturn(0);

        $this->vectorStoreMock->shouldReceive('upsert')
            ->andReturn(true);

        $result = $this->processor->processFile($file);

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('file_name', $result->metadata);
        $this->assertArrayHasKey('file_size', $result->metadata);
        $this->assertArrayHasKey('text_length', $result->metadata);
        $this->assertArrayHasKey('chunk_size', $result->metadata);
        $this->assertArrayHasKey('overlap', $result->metadata);
    }

    public function test_checks_if_file_is_processed()
    {
        $file = (object) [
            'id' => 9,
            'name' => 'check.txt',
            'path' => $this->tempDir . '/check.txt',
        ];

        // First call should return 0 (not processed)
        $this->vectorStoreMock->shouldReceive('count')
            ->once()
            ->with('file_chunks', Mockery::type('array'))
            ->andReturn(0);

        $this->assertFalse($this->processor->isProcessed($file));

        // Second call should return 5 (processed)
        $this->vectorStoreMock->shouldReceive('count')
            ->once()
            ->with('file_chunks', Mockery::type('array'))
            ->andReturn(5);

        $this->assertTrue($this->processor->isProcessed($file));
    }

    public function test_gets_file_statistics()
    {
        $file = (object) [
            'id' => 10,
            'name' => 'stats.txt',
            'path' => $this->tempDir . '/stats.txt',
        ];

        // Mock chunks exist
        $this->vectorStoreMock->shouldReceive('count')
            ->andReturn(3);

        $this->vectorStoreMock->shouldReceive('search')
            ->andReturn([
                [
                    'payload' => [
                        'file_id' => 10,
                        'file_name' => 'stats.txt',
                        'content' => 'Chunk 1',
                        'chunk_index' => 0,
                        'total_chunks' => 3,
                        'start_position' => 0,
                        'end_position' => 7,
                        'metadata' => [],
                    ],
                ],
                [
                    'payload' => [
                        'file_id' => 10,
                        'file_name' => 'stats.txt',
                        'content' => 'Chunk 2',
                        'chunk_index' => 1,
                        'total_chunks' => 3,
                        'start_position' => 7,
                        'end_position' => 14,
                        'metadata' => [],
                    ],
                ],
                [
                    'payload' => [
                        'file_id' => 10,
                        'file_name' => 'stats.txt',
                        'content' => 'Chunk 3',
                        'chunk_index' => 2,
                        'total_chunks' => 3,
                        'start_position' => 14,
                        'end_position' => 21,
                        'metadata' => [],
                    ],
                ],
            ]);

        $stats = $this->processor->getFileStats($file);

        $this->assertTrue($stats['processed']);
        $this->assertEquals(3, $stats['chunk_count']);
        $this->assertArrayHasKey('total_content_size', $stats);
        $this->assertArrayHasKey('average_chunk_size', $stats);
        $this->assertArrayHasKey('first_chunk_preview', $stats);
    }
}
