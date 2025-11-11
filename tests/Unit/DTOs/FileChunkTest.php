<?php

namespace Condoedge\Ai\Tests\Unit\DTOs;

use Condoedge\Ai\DTOs\FileChunk;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileChunk DTO
 */
class FileChunkTest extends TestCase
{
    public function test_creates_file_chunk_with_all_properties()
    {
        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'test.pdf',
            content: 'This is test content',
            embedding: [0.1, 0.2, 0.3],
            chunkIndex: 0,
            totalChunks: 5,
            startPosition: 0,
            endPosition: 20,
            metadata: ['page' => 1]
        );

        $this->assertEquals(1, $chunk->fileId);
        $this->assertEquals('test.pdf', $chunk->fileName);
        $this->assertEquals('This is test content', $chunk->content);
        $this->assertEquals([0.1, 0.2, 0.3], $chunk->embedding);
        $this->assertEquals(0, $chunk->chunkIndex);
        $this->assertEquals(5, $chunk->totalChunks);
        $this->assertEquals(0, $chunk->startPosition);
        $this->assertEquals(20, $chunk->endPosition);
        $this->assertEquals(['page' => 1], $chunk->metadata);
    }

    public function test_creates_from_array()
    {
        $data = [
            'file_id' => 1,
            'file_name' => 'test.pdf',
            'content' => 'Test content',
            'embedding' => [0.1, 0.2],
            'chunk_index' => 0,
            'total_chunks' => 3,
            'start_position' => 0,
            'end_position' => 12,
            'metadata' => ['page' => 1],
        ];

        $chunk = FileChunk::fromArray($data);

        $this->assertEquals(1, $chunk->fileId);
        $this->assertEquals('test.pdf', $chunk->fileName);
        $this->assertEquals('Test content', $chunk->content);
    }

    public function test_converts_to_array()
    {
        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'test.pdf',
            content: 'Test',
            embedding: [0.1],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 4
        );

        $array = $chunk->toArray();

        $this->assertArrayHasKey('file_id', $array);
        $this->assertArrayHasKey('file_name', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('embedding', $array);
        $this->assertEquals(1, $array['file_id']);
        $this->assertEquals('test.pdf', $array['file_name']);
    }

    public function test_generates_correct_vector_id()
    {
        $chunk = new FileChunk(
            fileId: 123,
            fileName: 'test.pdf',
            content: 'Test',
            embedding: [],
            chunkIndex: 5,
            totalChunks: 10,
            startPosition: 0,
            endPosition: 4
        );

        $this->assertEquals('file_123_chunk_5', $chunk->getVectorId());
    }

    public function test_calculates_content_length()
    {
        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'test.pdf',
            content: 'Hello World',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 11
        );

        $this->assertEquals(11, $chunk->getContentLength());
    }

    public function test_identifies_first_chunk()
    {
        $firstChunk = new FileChunk(
            fileId: 1,
            fileName: 'test.pdf',
            content: 'Test',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 3,
            startPosition: 0,
            endPosition: 4
        );

        $secondChunk = new FileChunk(
            fileId: 1,
            fileName: 'test.pdf',
            content: 'Test',
            embedding: [],
            chunkIndex: 1,
            totalChunks: 3,
            startPosition: 4,
            endPosition: 8
        );

        $this->assertTrue($firstChunk->isFirstChunk());
        $this->assertFalse($secondChunk->isFirstChunk());
    }

    public function test_identifies_last_chunk()
    {
        $lastChunk = new FileChunk(
            fileId: 1,
            fileName: 'test.pdf',
            content: 'Test',
            embedding: [],
            chunkIndex: 2,
            totalChunks: 3,
            startPosition: 8,
            endPosition: 12
        );

        $middleChunk = new FileChunk(
            fileId: 1,
            fileName: 'test.pdf',
            content: 'Test',
            embedding: [],
            chunkIndex: 1,
            totalChunks: 3,
            startPosition: 4,
            endPosition: 8
        );

        $this->assertTrue($lastChunk->isLastChunk());
        $this->assertFalse($middleChunk->isLastChunk());
    }

    public function test_handles_empty_metadata()
    {
        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'test.pdf',
            content: 'Test',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 4
        );

        $this->assertEquals([], $chunk->metadata);
    }
}
