<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\QdrantChunkStore;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Mockery;

/**
 * Unit Tests for QdrantChunkStore
 *
 * These tests verify the QdrantChunkStore's ability to:
 * - Store file chunks with embeddings
 * - Search by content similarity
 * - Search by filename (exact and partial match)
 *
 * All dependencies are mocked - NO real database calls are made.
 */
class QdrantChunkStoreTest extends TestCase
{
    private $mockVectorStore;
    private $mockEmbeddingProvider;
    private QdrantChunkStore $chunkStore;
    private string $testCollection = 'test_file_chunks';

    public function setUp(): void
    {
        parent::setUp();

        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockEmbeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        // Mock collection existence check
        $this->mockVectorStore
            ->shouldReceive('collectionExists')
            ->with($this->testCollection)
            ->andReturn(true);

        $this->chunkStore = new QdrantChunkStore(
            $this->mockVectorStore,
            $this->mockEmbeddingProvider,
            $this->testCollection,
            1536
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // searchByFilename() Method Tests
    // =========================================================================

    public function test_search_by_filename_finds_exact_match(): void
    {
        // Setup: mock the vector store to return a chunk with matching filename
        $this->mockVectorStore
            ->shouldReceive('count')
            ->once()
            ->with($this->testCollection, Mockery::on(function ($filter) {
                return isset($filter['must']) &&
                       $filter['must'][0]['key'] === 'file_name' &&
                       $filter['must'][0]['match']['text'] === 'bariloche.txt';
            }))
            ->andReturn(1);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with(
                $this->testCollection,
                Mockery::type('array'),
                Mockery::type('int'), // Limit is calculated dynamically
                Mockery::on(function ($filter) {
                    return isset($filter['must']) &&
                           $filter['must'][0]['key'] === 'file_name' &&
                           $filter['must'][0]['match']['text'] === 'bariloche.txt';
                }),
                0.0
            )
            ->andReturn([
                [
                    'id' => 'file_123_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 123,
                        'file_name' => 'bariloche.txt',
                        'content' => 'Some content about travel',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 100,
                        'metadata' => [],
                    ],
                ],
            ]);

        // Act
        $results = $this->chunkStore->searchByFilename('bariloche.txt', 10);

        // Assert
        $this->assertCount(1, $results);
        $this->assertEquals(123, $results[0]['chunk']->fileId);
        $this->assertEquals('bariloche.txt', $results[0]['chunk']->fileName);
        $this->assertEquals(1.0, $results[0]['score']); // Exact match score
        $this->assertEquals('exact', $results[0]['match_type']);
    }

    public function test_search_by_filename_partial_match(): void
    {
        // Setup: mock the vector store to return a chunk with filename containing search term
        $this->mockVectorStore
            ->shouldReceive('count')
            ->once()
            ->andReturn(1);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'id' => 'file_456_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 456,
                        'file_name' => 'quarterly_report_2024.pdf',
                        'content' => 'Financial data',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 50,
                        'metadata' => [],
                    ],
                ],
            ]);

        // Act: partial match should work
        $results = $this->chunkStore->searchByFilename('quarterly_report', 10);

        // Assert
        $this->assertCount(1, $results);
        $this->assertEquals(456, $results[0]['chunk']->fileId);
        $this->assertEquals('quarterly_report_2024.pdf', $results[0]['chunk']->fileName);
        $this->assertEquals(0.85, $results[0]['score']); // Partial match score
        $this->assertEquals('partial', $results[0]['match_type']);
    }

    public function test_search_by_filename_returns_empty_for_no_match(): void
    {
        // Setup: mock the vector store to return no results
        $this->mockVectorStore
            ->shouldReceive('count')
            ->once()
            ->andReturn(0);

        // Act
        $results = $this->chunkStore->searchByFilename('nonexistent.xyz', 10);

        // Assert
        $this->assertEmpty($results);
    }

    public function test_search_by_filename_groups_by_file_id(): void
    {
        // Setup: return multiple chunks from the same file
        $this->mockVectorStore
            ->shouldReceive('count')
            ->once()
            ->andReturn(3);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'id' => 'file_100_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 100,
                        'file_name' => 'report.pdf',
                        'content' => 'Content chunk 0',
                        'chunk_index' => 0,
                        'total_chunks' => 3,
                        'start_position' => 0,
                        'end_position' => 100,
                        'metadata' => [],
                    ],
                ],
                [
                    'id' => 'file_100_chunk_1',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 100,
                        'file_name' => 'report.pdf',
                        'content' => 'Content chunk 1',
                        'chunk_index' => 1,
                        'total_chunks' => 3,
                        'start_position' => 100,
                        'end_position' => 200,
                        'metadata' => [],
                    ],
                ],
                [
                    'id' => 'file_100_chunk_2',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 100,
                        'file_name' => 'report.pdf',
                        'content' => 'Content chunk 2',
                        'chunk_index' => 2,
                        'total_chunks' => 3,
                        'start_position' => 200,
                        'end_position' => 300,
                        'metadata' => [],
                    ],
                ],
            ]);

        // Act
        $results = $this->chunkStore->searchByFilename('report.pdf', 10);

        // Assert: should return only unique files (1 result)
        $this->assertCount(1, $results);
        $this->assertEquals(100, $results[0]['chunk']->fileId);
    }

    public function test_search_by_filename_with_file_id_filter(): void
    {
        // Setup: mock with file_id filter
        $this->mockVectorStore
            ->shouldReceive('count')
            ->once()
            ->with($this->testCollection, Mockery::on(function ($filter) {
                return isset($filter['must']) &&
                       count($filter['must']) === 2 &&
                       $filter['must'][0]['key'] === 'file_name' &&
                       $filter['must'][1]['key'] === 'file_id' &&
                       $filter['must'][1]['match']['value'] === 123;
            }))
            ->andReturn(1);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'id' => 'file_123_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 123,
                        'file_name' => 'document.pdf',
                        'content' => 'Content',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 50,
                        'metadata' => [],
                    ],
                ],
            ]);

        // Act
        $results = $this->chunkStore->searchByFilename('document.pdf', 10, ['file_id' => 123]);

        // Assert
        $this->assertCount(1, $results);
        $this->assertEquals(123, $results[0]['chunk']->fileId);
    }

    public function test_search_by_filename_sorts_exact_matches_first(): void
    {
        // Setup: return both exact and partial matches
        $this->mockVectorStore
            ->shouldReceive('count')
            ->once()
            ->andReturn(2);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'id' => 'file_200_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 200,
                        'file_name' => 'budget_report.xlsx', // Partial match
                        'content' => 'Budget data',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 50,
                        'metadata' => [],
                    ],
                ],
                [
                    'id' => 'file_201_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 201,
                        'file_name' => 'report', // Exact match
                        'content' => 'Report content',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 50,
                        'metadata' => [],
                    ],
                ],
            ]);

        // Act
        $results = $this->chunkStore->searchByFilename('report', 10);

        // Assert: exact match should be first
        $this->assertCount(2, $results);
        $this->assertEquals('report', $results[0]['chunk']->fileName);
        $this->assertEquals(1.0, $results[0]['score']);
        $this->assertEquals('budget_report.xlsx', $results[1]['chunk']->fileName);
        $this->assertEquals(0.85, $results[1]['score']);
    }

    public function test_search_by_filename_respects_limit(): void
    {
        // Setup: return more results than limit
        $this->mockVectorStore
            ->shouldReceive('count')
            ->once()
            ->andReturn(5);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'id' => 'file_1_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 1,
                        'file_name' => 'doc1.pdf',
                        'content' => 'Content 1',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 50,
                        'metadata' => [],
                    ],
                ],
                [
                    'id' => 'file_2_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 2,
                        'file_name' => 'doc2.pdf',
                        'content' => 'Content 2',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 50,
                        'metadata' => [],
                    ],
                ],
                [
                    'id' => 'file_3_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 3,
                        'file_name' => 'doc3.pdf',
                        'content' => 'Content 3',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 50,
                        'metadata' => [],
                    ],
                ],
            ]);

        // Act: request only 2 results
        $results = $this->chunkStore->searchByFilename('doc', 2);

        // Assert: should respect the limit
        $this->assertCount(2, $results);
    }

    public function test_search_by_filename_case_insensitive_exact_match(): void
    {
        // Setup: return a result where case differs
        $this->mockVectorStore
            ->shouldReceive('count')
            ->once()
            ->andReturn(1);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'id' => 'file_300_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 300,
                        'file_name' => 'README.txt',
                        'content' => 'Read me content',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 50,
                        'metadata' => [],
                    ],
                ],
            ]);

        // Act: search with different case
        $results = $this->chunkStore->searchByFilename('readme.txt', 10);

        // Assert: should still be considered an exact match (case-insensitive)
        $this->assertCount(1, $results);
        $this->assertEquals(1.0, $results[0]['score']);
        $this->assertEquals('exact', $results[0]['match_type']);
    }

    public function test_search_by_filename_returns_empty_for_empty_string(): void
    {
        // Act: search with empty string should return empty without any database calls
        $results = $this->chunkStore->searchByFilename('', 10);

        // Assert: should return empty array immediately
        $this->assertEmpty($results);
    }

    public function test_search_by_filename_returns_empty_for_whitespace_only(): void
    {
        // Act: search with whitespace-only string should return empty without any database calls
        $results = $this->chunkStore->searchByFilename('   ', 10);

        // Assert: should return empty array immediately
        $this->assertEmpty($results);
    }

    public function test_search_by_filename_caps_request_limit(): void
    {
        // Setup: simulate a scenario where totalCount is very high (e.g., 10000 matching chunks)
        // The limit calculation should cap the request to prevent memory issues
        $this->mockVectorStore
            ->shouldReceive('count')
            ->once()
            ->andReturn(10000); // High count

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with(
                $this->testCollection,
                Mockery::type('array'),
                100, // Should be capped at min(max(5*3=15, 100), 10000, 500) = 100
                Mockery::type('array'),
                0.0
            )
            ->andReturn([
                [
                    'id' => 'file_1_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 1,
                        'file_name' => 'report.pdf',
                        'content' => 'Content',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 50,
                        'metadata' => [],
                    ],
                ],
            ]);

        // Act: request only 5 results, but with high totalCount
        $results = $this->chunkStore->searchByFilename('report', 5);

        // Assert: should return results without memory issues
        $this->assertCount(1, $results);
    }

    public function test_search_by_filename_caps_large_limit(): void
    {
        // With limit = 1000, should still cap at 500, not 3000
        // Test that $requestLimit = min(max(1000*3, 100), totalCount, 500) = 500
        $this->mockVectorStore
            ->shouldReceive('count')
            ->once()
            ->andReturn(10000); // High count

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with(
                $this->testCollection,
                Mockery::type('array'),
                500, // Should be capped at 500, not 3000 (1000*3)
                Mockery::type('array'),
                0.0
            )
            ->andReturn([
                [
                    'id' => 'file_1_chunk_0',
                    'score' => 0.0,
                    'payload' => [
                        'file_id' => 1,
                        'file_name' => 'report.pdf',
                        'content' => 'Content',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 50,
                        'metadata' => [],
                    ],
                ],
            ]);

        // Act: request 1000 results - without cap would request 3000
        $results = $this->chunkStore->searchByFilename('report', 1000);

        // Assert: should return results with request capped at 500
        $this->assertCount(1, $results);
    }

    // =========================================================================
    // storeChunk() Method Tests
    // =========================================================================

    public function test_store_chunk_successfully(): void
    {
        $chunk = new FileChunk(
            fileId: 123,
            fileName: 'test.pdf',
            content: 'Test content',
            embedding: array_fill(0, 1536, 0.1),
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 100,
            metadata: []
        );

        $this->mockVectorStore
            ->shouldReceive('upsert')
            ->once()
            ->with($this->testCollection, Mockery::type('array'))
            ->andReturn(true);

        $result = $this->chunkStore->storeChunk($chunk);

        $this->assertTrue($result);
    }

    // =========================================================================
    // searchByContent() Method Tests
    // =========================================================================

    public function test_search_by_content_returns_matching_chunks(): void
    {
        $query = 'test query';
        $queryEmbedding = array_fill(0, 1536, 0.1);

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->with($query)
            ->andReturn($queryEmbedding);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->with(
                $this->testCollection,
                $queryEmbedding,
                10,
                [],
                0.0
            )
            ->andReturn([
                [
                    'id' => 'file_1_chunk_0',
                    'score' => 0.95,
                    'payload' => [
                        'file_id' => 1,
                        'file_name' => 'result.pdf',
                        'content' => 'Matching content',
                        'chunk_index' => 0,
                        'total_chunks' => 1,
                        'start_position' => 0,
                        'end_position' => 50,
                        'metadata' => [],
                    ],
                ],
            ]);

        $results = $this->chunkStore->searchByContent($query, 10);

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['chunk']->fileId);
        $this->assertEquals(0.95, $results[0]['score']);
    }
}
