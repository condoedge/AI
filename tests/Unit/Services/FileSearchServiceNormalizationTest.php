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
 * Cross-collection score normalization + composite file-id tests for
 * FileSearchService::searchByContent (P1 retrieval precision).
 *
 * Two failure modes are covered:
 *  1. Bare-id COLLISION: a physical doc and a DB file that share the same bare
 *     numeric id must NOT be merged into one group when they come from different
 *     collections (composite, collection-qualified identity).
 *  2. Cross-collection SCORE-SCALE mismatch: raw cosine scores from different
 *     collections live on different scales; min-max normalization per collection
 *     makes them comparable so a strong match in one collection is not buried
 *     under a mediocre match in another.
 */
class FileSearchServiceNormalizationTest extends TestCase
{
    private $chunkStore;
    private $graphStore;
    private FileSearchService $searchService;

    public function setUp(): void
    {
        parent::setUp();

        $this->chunkStore = Mockery::mock(ChunkStoreInterface::class);
        $this->graphStore = Mockery::mock(GraphStoreInterface::class);

        // Union an additional collection so normalization + composite ids engage.
        $this->searchService = new FileSearchService(
            $this->chunkStore,
            $this->graphStore,
            ['documentation_chunks']
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createMockFile(int|string $id, string $name): object
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
     * @param array<int|string,string> $files id => name
     */
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

    private function chunkResult(int|string $fileId, string $fileName, int $chunkIndex, float $score): array
    {
        return [
            'chunk' => new FileChunk(
                fileId: $fileId,
                fileName: $fileName,
                content: "Content for {$fileName} chunk {$chunkIndex}",
                embedding: [],
                chunkIndex: $chunkIndex,
                totalChunks: 2,
                startPosition: $chunkIndex * 100,
                endPosition: ($chunkIndex + 1) * 100,
                metadata: []
            ),
            'score' => $score,
        ];
    }

    /** @test */
    public function colliding_bare_ids_from_different_collections_are_not_merged(): void
    {
        // PRIMARY collection: DB file id 5.
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array')) // 3 args = primary
            ->andReturn([
                $this->chunkResult(5, 'db_report.pdf', 0, 0.90),
            ]);

        // ADDITIONAL collection 'documentation_chunks': physical doc that ALSO has
        // bare id 5 (the collision the composite id must prevent).
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array'), 'documentation_chunks')
            ->andReturn([
                $this->chunkResult(5, 'manual.md', 0, 0.30),
            ]);

        $this->mockFileModel([5 => 'db_report.pdf']);

        $results = $this->searchService->searchByContent('query', ['limit' => 10]);

        // Without composite ids these two would collapse into ONE group; they must
        // remain TWO distinct results despite sharing bare id 5.
        $this->assertCount(2, $results);

        // Both carry the original (bare) file id for downstream loading...
        $this->assertEquals([5, 5], array_column($results, 'file_id'));

        // ...but are distinguished by a collection-qualified composite id.
        $composites = array_column($results, 'composite_id');
        $this->assertCount(2, array_unique($composites));
    }

    /** @test */
    public function scores_are_min_max_normalized_per_collection_before_merging(): void
    {
        // PRIMARY band is HIGH-cosine [0.70 .. 0.90]; the worst normalizes to 0.0,
        // the best to 1.0.
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array'))
            ->andReturn([
                $this->chunkResult(1, 'db_high.pdf', 0, 0.90), // -> 1.0
                $this->chunkResult(2, 'db_low.pdf', 0, 0.70),  // -> 0.0
            ]);

        // ADDITIONAL band is LOW-cosine [0.20 .. 0.40] on a different scale. Its
        // best (0.40) normalizes to 1.0 — comparable with the primary's best,
        // even though its raw score (0.40) is below the primary's WORST (0.70).
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array'), 'documentation_chunks')
            ->andReturn([
                $this->chunkResult(101, 'doc_best.md', 0, 0.40),  // -> 1.0
                $this->chunkResult(102, 'doc_worst.md', 0, 0.20), // -> 0.0
            ]);

        $this->mockFileModel([
            1 => 'db_high.pdf',
            2 => 'db_low.pdf',
            101 => 'doc_best.md',
            102 => 'doc_worst.md',
        ]);

        $results = $this->searchService->searchByContent('query', ['limit' => 10]);

        $this->assertCount(4, $results);

        $byFile = collect($results)->keyBy('file_id');

        // Per-collection min-max: top of each band -> 1.0, bottom -> 0.0.
        $this->assertEqualsWithDelta(1.0, $byFile[1]['score'], 0.0001);   // primary best
        $this->assertEqualsWithDelta(0.0, $byFile[2]['score'], 0.0001);   // primary worst
        $this->assertEqualsWithDelta(1.0, $byFile[101]['score'], 0.0001); // additional best
        $this->assertEqualsWithDelta(0.0, $byFile[102]['score'], 0.0001); // additional worst

        // The doc collection's strong match (raw 0.40) now ranks ABOVE the
        // primary's weaker match (raw 0.70) — the precise mismatch normalization
        // is meant to fix. Top two results are the two normalized 1.0s.
        $topTwo = array_slice(array_column($results, 'file_id'), 0, 2);
        sort($topTwo);
        $this->assertEquals([1, 101], $topTwo);
    }

    /** @test */
    public function single_collection_search_leaves_scores_unnormalized(): void
    {
        // Only the primary collection returns results; the additional collection
        // is empty. With a single contributing source there is nothing to make
        // comparable, so absolute cosine scores must pass through UNCHANGED
        // (downstream min_score thresholds depend on this).
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array'))
            ->andReturn([
                $this->chunkResult(7, 'only.pdf', 0, 0.83),
                $this->chunkResult(8, 'other.pdf', 0, 0.61),
            ]);

        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array'), 'documentation_chunks')
            ->andReturn([]); // empty additional collection

        $this->mockFileModel([7 => 'only.pdf', 8 => 'other.pdf']);

        $results = $this->searchService->searchByContent('query', ['limit' => 10]);

        $byFile = collect($results)->keyBy('file_id');

        // Raw scores preserved (NOT rescaled to 1.0/0.0).
        $this->assertEqualsWithDelta(0.83, $byFile[7]['score'], 0.0001);
        $this->assertEqualsWithDelta(0.61, $byFile[8]['score'], 0.0001);
    }
}
