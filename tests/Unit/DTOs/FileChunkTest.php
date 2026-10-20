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

        // The vector ID is now a deterministic UUIDv5 (NAMESPACE_URL) derived
        // from "file={id};chunk={index}", so the same chunk always maps to the
        // same point ID in the vector store.
        $expected = \Ramsey\Uuid\Uuid::uuid5(
            \Ramsey\Uuid\Uuid::NAMESPACE_URL,
            'file=123;chunk=5'
        )->toString();

        $this->assertSame($expected, $chunk->getVectorId());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $chunk->getVectorId(),
            'getVectorId() must return a UUIDv5 string'
        );
    }

    public function test_vector_id_is_deterministic_for_same_chunk()
    {
        $args = [
            'fileId' => 7,
            'fileName' => 'doc.pdf',
            'content' => 'Test',
            'embedding' => [],
            'chunkIndex' => 2,
            'totalChunks' => 4,
            'startPosition' => 0,
            'endPosition' => 4,
        ];

        $a = new FileChunk(...$args);
        $b = new FileChunk(...$args);

        // Same (fileId, chunkIndex) -> same point ID (idempotent re-ingestion).
        $this->assertSame($a->getVectorId(), $b->getVectorId());

        // Different chunk index -> different point ID (no collisions).
        $c = new FileChunk(...[...$args, 'chunkIndex' => 3]);
        $this->assertNotSame($a->getVectorId(), $c->getVectorId());
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

    public function test_content_length_counts_characters_not_bytes()
    {
        // 10 accented characters = 20 bytes in UTF-8. Content length must report
        // characters (consistent with the chunker's character-based budgets),
        // not raw byte length.
        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'café.txt',
            content: str_repeat('é', 10),
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 10
        );

        $this->assertEquals(10, $chunk->getContentLength());
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
