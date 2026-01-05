<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Mockery;

/**
 * Unit Tests for FileSearchService
 *
 * These tests verify the FileSearchService's ability to:
 * - Search files by filename
 * - Group chunks by file ID
 * - Load File models and optionally relationships
 *
 * All dependencies are mocked - NO real database calls are made.
 */
class FileSearchServiceTest extends TestCase
{
    private $chunkStore;
    private $graphStore;
    private FileSearchService $searchService;

    public function setUp(): void
    {
        parent::setUp();

        $this->chunkStore = Mockery::mock(ChunkStoreInterface::class);
        $this->graphStore = Mockery::mock(GraphStoreInterface::class);

        $this->searchService = new FileSearchService(
            $this->chunkStore,
            $this->graphStore
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper to create a mock File model
     */
    private function createMockFile(int $id, string $name): object
    {
        $file = new class extends Model {
            protected $guarded = [];
            public $timestamps = false;
            protected $table = 'files';
        };
        $file->id = $id;
        $file->name = $name;
        return $file;
    }

    /**
     * Helper to set up FileModel mock for a test
     *
     * @param array $files Array of [id => name] pairs
     */
    private function mockFileModel(array $files): void
    {
        $mockFiles = [];
        foreach ($files as $id => $name) {
            $mockFiles[] = $this->createMockFile($id, $name);
        }
        $collection = collect($mockFiles)->keyBy('id');

        // Create a mock builder that returns our files
        $mockBuilder = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $mockBuilder->shouldReceive('get')
            ->andReturn($collection);

        // Create a mock model that returns the builder
        $mockModel = Mockery::mock(Model::class)->makePartial();
        $mockModel->shouldReceive('whereIn')
            ->andReturn($mockBuilder);

        // Bind the file-model key
        $this->app->instance('file-model', $mockModel);
    }

    // =========================================================================
    // searchByFilename() Method Tests
    // =========================================================================

    public function test_search_by_filename_returns_matching_files(): void
    {
        // Mock chunk store - 3 args are passed (filename, limit*2, filters)
        $this->chunkStore->expects('searchByFilename')
            ->once()
            ->with('report.pdf', 20, []) // limit (10) * 2, empty filters
            ->andReturn([
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'report.pdf',
                        content: 'Content',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 1,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 1.0,
                ],
            ]);

        $this->mockFileModel([1 => 'report.pdf']);

        $results = $this->searchService->searchByFilename('report.pdf');

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['file_id']);
        $this->assertEquals(1.0, $results[0]['score']);
    }

    public function test_search_by_filename_returns_empty_when_no_matches(): void
    {
        // Mock chunk store returning empty
        $this->chunkStore->expects('searchByFilename')
            ->once()
            ->with('nonexistent.pdf', 20, [])
            ->andReturn([]);

        $results = $this->searchService->searchByFilename('nonexistent.pdf');

        $this->assertEmpty($results);
    }

    public function test_search_by_filename_groups_chunks_by_file_id(): void
    {
        // Return multiple chunks from same file
        $this->chunkStore->expects('searchByFilename')
            ->once()
            ->with('report.pdf', 20, [])
            ->andReturn([
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'report.pdf',
                        content: 'Content chunk 0',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 2,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 1.0,
                ],
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'report.pdf',
                        content: 'Content chunk 1',
                        embedding: [],
                        chunkIndex: 1,
                        totalChunks: 2,
                        startPosition: 100,
                        endPosition: 200,
                        metadata: []
                    ),
                    'score' => 0.9,
                ],
            ]);

        $this->mockFileModel([1 => 'report.pdf']);

        $results = $this->searchService->searchByFilename('report.pdf');

        // Should only have one result (grouped by file_id)
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['file_id']);
        // First chunk should be kept as best_chunk (score 1.0)
        $this->assertEquals(0, $results[0]['best_chunk']->chunkIndex);
    }

    public function test_search_by_filename_respects_limit_option(): void
    {
        $this->chunkStore->expects('searchByFilename')
            ->once()
            ->with('doc', 10, []) // limit (5) * 2, empty filters
            ->andReturn([
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'doc1.pdf',
                        content: 'Content',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 1,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 1.0,
                ],
                [
                    'chunk' => new FileChunk(
                        fileId: 2,
                        fileName: 'doc2.pdf',
                        content: 'Content',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 1,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 0.9,
                ],
                [
                    'chunk' => new FileChunk(
                        fileId: 3,
                        fileName: 'doc3.pdf',
                        content: 'Content',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 1,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 0.8,
                ],
            ]);

        $this->mockFileModel([
            1 => 'doc1.pdf',
            2 => 'doc2.pdf',
            3 => 'doc3.pdf',
        ]);

        $results = $this->searchService->searchByFilename('doc', ['limit' => 5]);

        // Should have 3 results (all fit under limit of 5)
        $this->assertCount(3, $results);
    }

    public function test_search_by_filename_sorts_by_score_descending(): void
    {
        $this->chunkStore->expects('searchByFilename')
            ->once()
            ->with('score', 20, [])
            ->andReturn([
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'low_score.pdf',
                        content: 'Content',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 1,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 0.5,
                ],
                [
                    'chunk' => new FileChunk(
                        fileId: 2,
                        fileName: 'high_score.pdf',
                        content: 'Content',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 1,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 1.0,
                ],
            ]);

        $this->mockFileModel([
            1 => 'low_score.pdf',
            2 => 'high_score.pdf',
        ]);

        $results = $this->searchService->searchByFilename('score');

        // Higher score should be first
        $this->assertEquals(2, $results[0]['file_id']);
        $this->assertEquals(1.0, $results[0]['score']);
        $this->assertEquals(1, $results[1]['file_id']);
        $this->assertEquals(0.5, $results[1]['score']);
    }

    public function test_search_by_filename_includes_relationships_when_requested(): void
    {
        $this->chunkStore->expects('searchByFilename')
            ->once()
            ->with('report.pdf', 20, [])
            ->andReturn([
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'report.pdf',
                        content: 'Content',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 1,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 1.0,
                ],
            ]);

        $this->mockFileModel([1 => 'report.pdf']);

        // Expect graph store query for relationships
        $this->graphStore->expects('query')
            ->once()
            ->andReturn([
                ['type' => 'UPLOADED_BY', 'labels' => ['User'], 'properties' => ['id' => 1]],
            ]);

        $results = $this->searchService->searchByFilename('report.pdf', [
            'include_relationships' => true,
        ]);

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('relationships', $results[0]);
        $this->assertCount(1, $results[0]['relationships']);
    }

    public function test_search_by_filename_includes_chunk_count(): void
    {
        $this->chunkStore->expects('searchByFilename')
            ->once()
            ->with('report.pdf', 20, [])
            ->andReturn([
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'report.pdf',
                        content: 'Content',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 1,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 1.0,
                ],
            ]);

        $this->mockFileModel([1 => 'report.pdf']);

        $results = $this->searchService->searchByFilename('report.pdf');

        $this->assertArrayHasKey('chunk_count', $results[0]);
        $this->assertEquals(1, $results[0]['chunk_count']);
    }

    public function test_search_by_filename_includes_chunks_array(): void
    {
        $this->chunkStore->expects('searchByFilename')
            ->once()
            ->with('report.pdf', 20, [])
            ->andReturn([
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'report.pdf',
                        content: 'Content',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 1,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 1.0,
                ],
            ]);

        $this->mockFileModel([1 => 'report.pdf']);

        $results = $this->searchService->searchByFilename('report.pdf');

        $this->assertArrayHasKey('chunks', $results[0]);
        $this->assertCount(1, $results[0]['chunks']);
        $this->assertInstanceOf(FileChunk::class, $results[0]['chunks'][0]);
    }

    public function test_search_by_filename_aggregates_all_chunks_for_same_file(): void
    {
        // Return multiple chunks from same file - verify ALL chunks are collected
        $this->chunkStore->expects('searchByFilename')
            ->once()
            ->with('report.pdf', 20, [])
            ->andReturn([
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'report.pdf',
                        content: 'Content chunk 0',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 3,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 0.8,
                ],
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'report.pdf',
                        content: 'Content chunk 1',
                        embedding: [],
                        chunkIndex: 1,
                        totalChunks: 3,
                        startPosition: 100,
                        endPosition: 200,
                        metadata: []
                    ),
                    'score' => 1.0, // Best score
                ],
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'report.pdf',
                        content: 'Content chunk 2',
                        embedding: [],
                        chunkIndex: 2,
                        totalChunks: 3,
                        startPosition: 200,
                        endPosition: 300,
                        metadata: []
                    ),
                    'score' => 0.7,
                ],
            ]);

        $this->mockFileModel([1 => 'report.pdf']);

        $results = $this->searchService->searchByFilename('report.pdf');

        // Should have one result (grouped by file_id)
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['file_id']);

        // Verify ALL chunks are collected (not just first)
        $this->assertEquals(3, $results[0]['chunk_count']);
        $this->assertCount(3, $results[0]['chunks']);

        // Best chunk should be the one with highest score (chunk 1)
        $this->assertEquals(1, $results[0]['best_chunk']->chunkIndex);
        $this->assertEquals(1.0, $results[0]['score']);
    }

    public function test_search_by_filename_passes_file_types_filter(): void
    {
        $this->chunkStore->expects('searchByFilename')
            ->once()
            ->with('report', 20, ['file_types' => ['pdf', 'docx']])
            ->andReturn([
                [
                    'chunk' => new FileChunk(
                        fileId: 1,
                        fileName: 'report.pdf',
                        content: 'Content',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 1,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 1.0,
                ],
            ]);

        $this->mockFileModel([1 => 'report.pdf']);

        $results = $this->searchService->searchByFilename('report', [
            'file_types' => ['pdf', 'docx'],
        ]);

        $this->assertCount(1, $results);
    }

    public function test_search_by_filename_passes_file_id_filter(): void
    {
        $this->chunkStore->expects('searchByFilename')
            ->once()
            ->with('report', 20, ['file_id' => 42])
            ->andReturn([
                [
                    'chunk' => new FileChunk(
                        fileId: 42,
                        fileName: 'report.pdf',
                        content: 'Content',
                        embedding: [],
                        chunkIndex: 0,
                        totalChunks: 1,
                        startPosition: 0,
                        endPosition: 100,
                        metadata: []
                    ),
                    'score' => 1.0,
                ],
            ]);

        $this->mockFileModel([42 => 'report.pdf']);

        $results = $this->searchService->searchByFilename('report', [
            'file_id' => 42,
        ]);

        $this->assertCount(1, $results);
        $this->assertEquals(42, $results[0]['file_id']);
    }
}
