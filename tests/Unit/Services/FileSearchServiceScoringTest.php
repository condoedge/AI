<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Illuminate\Database\Eloquent\Model;
use Mockery;

/**
 * Best-chunk scoring tests for FileSearchService::searchByContent (Task B3).
 *
 * A file must be scored by its BEST (max) chunk, not the average, so a file with
 * one strongly relevant chunk and several weak ones is not dropped by the
 * relevance threshold. The average is preserved as `avg_score` metadata.
 */
class FileSearchServiceScoringTest extends TestCase
{
    private $chunkStore;
    private $graphStore;
    private FileSearchService $searchService;

    public function setUp(): void
    {
        parent::setUp();

        $this->chunkStore = Mockery::mock(ChunkStoreInterface::class);
        $this->graphStore = Mockery::mock(GraphStoreInterface::class);

        $this->searchService = new FileSearchService($this->chunkStore, $this->graphStore);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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

    private function mockFileModel(array $files): void
    {
        $mockFiles = [];
        foreach ($files as $id => $name) {
            $mockFiles[] = $this->createMockFile($id, $name);
        }
        $collection = collect($mockFiles)->keyBy('id');

        $mockBuilder = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $mockBuilder->shouldReceive('get')->andReturn($collection);

        $mockModel = Mockery::mock(Model::class)->makePartial();
        $mockModel->shouldReceive('whereIn')->andReturn($mockBuilder);

        $this->app->instance('file-model', $mockModel);
    }

    private function chunkResult(int $fileId, int $chunkIndex, float $score): array
    {
        return [
            'chunk' => new FileChunk(
                fileId: $fileId,
                fileName: "file{$fileId}.pdf",
                content: "Chunk {$chunkIndex} content",
                embedding: [],
                chunkIndex: $chunkIndex,
                totalChunks: 4,
                startPosition: $chunkIndex * 100,
                endPosition: ($chunkIndex + 1) * 100,
                metadata: []
            ),
            'score' => $score,
        ];
    }

    /** @test */
    public function file_is_scored_by_best_chunk_not_average(): void
    {
        // Chunks [0.8, 0.2, 0.2, 0.2]: avg ~= 0.35 (would be dropped at 0.4),
        // but max = 0.8 must survive.
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                $this->chunkResult(1, 0, 0.8),
                $this->chunkResult(1, 1, 0.2),
                $this->chunkResult(1, 2, 0.2),
                $this->chunkResult(1, 3, 0.2),
            ]);

        $this->mockFileModel([1 => 'file1.pdf']);

        $results = $this->searchService->searchByContent('query', ['min_score' => 0.4]);

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['file_id']);

        // Score is the best chunk (0.8), comfortably above the 0.4 threshold.
        $this->assertEqualsWithDelta(0.8, $results[0]['score'], 0.0001);
        $this->assertGreaterThanOrEqual(0.4, $results[0]['score']);

        // Average is preserved as metadata (~0.35) and is NOT what gates the file.
        $this->assertArrayHasKey('avg_score', $results[0]);
        $this->assertEqualsWithDelta(0.35, $results[0]['avg_score'], 0.0001);
    }

    /** @test */
    public function min_score_is_forwarded_into_the_chunk_store_filter(): void
    {
        // The relevance threshold must reach the chunk store so weak chunks are
        // cut early in Qdrant.
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with(
                'query',
                Mockery::type('int'),
                Mockery::on(fn ($filters) => ($filters['min_score'] ?? null) === 0.6)
            )
            ->andReturn([
                $this->chunkResult(2, 0, 0.9),
            ]);

        $this->mockFileModel([2 => 'file2.pdf']);

        $results = $this->searchService->searchByContent('query', ['min_score' => 0.6]);

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results[0]['file_id']);
    }

    /** @test */
    public function best_chunk_preview_is_the_highest_scoring_chunk(): void
    {
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                $this->chunkResult(1, 0, 0.3),
                $this->chunkResult(1, 1, 0.95), // best
                $this->chunkResult(1, 2, 0.4),
            ]);

        $this->mockFileModel([1 => 'file1.pdf']);

        $results = $this->searchService->searchByContent('query');

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['best_chunk']->chunkIndex);
        $this->assertEqualsWithDelta(0.95, $results[0]['score'], 0.0001);
    }
}
