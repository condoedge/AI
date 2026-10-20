<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Illuminate\Database\Eloquent\Model;
use Mockery;

/**
 * Cross-collection relevance tests for FileSearchService::searchByContent and the
 * threshold gate in FileContextProvider (L5-5 / P0-9).
 *
 * The relevance gate and the displayed relevance must be RAW cosine similarity,
 * never a rank-rescaled value:
 *  - A weak match from a lone (or all-equal) collection must keep its weak raw
 *    score, so it is cut by an absolute min_relevance_score gate instead of being
 *    rescaled to 1.0 and waved through.
 *  - A strong match must keep its raw score, not be squashed toward 0 by another
 *    collection's score spread.
 * When several collections are merged, ordering is done by Reciprocal Rank Fusion
 * (RRF) — a rank-based fuse that needs no comparable score scale — while each
 * result's raw similarity is preserved for thresholding and display.
 *
 * Composite, collection-qualified identities still keep a physical doc and a DB
 * file that share a bare numeric id from being merged.
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

        // Union an additional collection so cross-collection merge + composite ids engage.
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
    public function a_lone_weak_collection_match_keeps_its_raw_score_and_is_not_boosted(): void
    {
        // PRIMARY: one strong DB match.
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array'))
            ->andReturn([
                $this->chunkResult(1, 'db_strong.pdf', 0, 0.90),
            ]);

        // ADDITIONAL: a single WEAK doc match (raw 0.30, below the 0.4 gate). Being
        // the lone/all-equal member of its collection it would, under min-max
        // normalization, be rescaled to 1.0 and wrongly clear the gate. The raw
        // score must be preserved instead.
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array'), 'documentation_chunks')
            ->andReturn([
                $this->chunkResult(101, 'doc_weak.md', 0, 0.30),
            ]);

        $this->mockFileModel([1 => 'db_strong.pdf', 101 => 'doc_weak.md']);

        $results = $this->searchService->searchByContent('query', ['limit' => 10]);

        $byFile = collect($results)->keyBy('file_id');

        // Raw similarity is preserved for BOTH — the gate operates on real cosine.
        $this->assertEqualsWithDelta(0.90, $byFile[1]['score'], 0.0001);
        $this->assertEqualsWithDelta(0.30, $byFile[101]['score'], 0.0001);

        // The weak doc is NOT boosted to 1.0.
        $this->assertLessThan(0.4, $byFile[101]['score']);
    }

    /** @test */
    public function a_strong_match_is_not_squashed_to_zero_by_another_collections_spread(): void
    {
        // PRIMARY: a strong match (0.85) and a weaker one (0.55). Under per-collection
        // min-max the 0.55 would be forced to 0.0 — losing its real similarity.
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array'))
            ->andReturn([
                $this->chunkResult(1, 'db_best.pdf', 0, 0.85),
                $this->chunkResult(2, 'db_mid.pdf', 0, 0.55),
            ]);

        // ADDITIONAL: a low-scale doc collection.
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array'), 'documentation_chunks')
            ->andReturn([
                $this->chunkResult(101, 'doc_a.md', 0, 0.30),
                $this->chunkResult(102, 'doc_b.md', 0, 0.20),
            ]);

        $this->mockFileModel([
            1 => 'db_best.pdf',
            2 => 'db_mid.pdf',
            101 => 'doc_a.md',
            102 => 'doc_b.md',
        ]);

        $results = $this->searchService->searchByContent('query', ['limit' => 10]);

        $byFile = collect($results)->keyBy('file_id');

        // Every raw similarity survives — none is collapsed to 0.0/1.0.
        $this->assertEqualsWithDelta(0.85, $byFile[1]['score'], 0.0001);
        $this->assertEqualsWithDelta(0.55, $byFile[2]['score'], 0.0001);  // NOT squashed to 0.0
        $this->assertEqualsWithDelta(0.30, $byFile[101]['score'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $byFile[102]['score'], 0.0001);

        // The primary's weaker match (0.55) still clears a 0.4 gate; the doc matches
        // (0.30/0.20) do not — exactly because the score is raw cosine, not a rank.
        $this->assertGreaterThan(0.4, $byFile[2]['score']);
        $this->assertLessThan(0.4, $byFile[101]['score']);
    }

    /** @test */
    public function cross_collection_merge_orders_by_reciprocal_rank_fusion(): void
    {
        // PRIMARY: two DB matches on a HIGH cosine scale.
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array'))
            ->andReturn([
                $this->chunkResult(1, 'db_rank1.pdf', 0, 0.90), // collection rank 1
                $this->chunkResult(2, 'db_rank2.pdf', 0, 0.70), // collection rank 2
            ]);

        // ADDITIONAL: two doc matches on a LOWER cosine scale.
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with('query', Mockery::type('int'), Mockery::type('array'), 'documentation_chunks')
            ->andReturn([
                $this->chunkResult(101, 'doc_rank1.md', 0, 0.40), // collection rank 1
                $this->chunkResult(102, 'doc_rank2.md', 0, 0.20), // collection rank 2
            ]);

        $this->mockFileModel([
            1 => 'db_rank1.pdf',
            2 => 'db_rank2.pdf',
            101 => 'doc_rank1.md',
            102 => 'doc_rank2.md',
        ]);

        $results = $this->searchService->searchByContent('query', ['limit' => 10]);

        $this->assertCount(4, $results);

        // Each result carries an RRF fusion score used for cross-collection ordering,
        // distinct from its preserved raw similarity.
        foreach ($results as $r) {
            $this->assertArrayHasKey('fusion_score', $r);
        }

        // RRF fuses by rank, not raw scale: each collection's rank-1 doc must sort
        // ABOVE each collection's rank-2 doc. The two rank-1 docs (file 1 and 101)
        // are the top two results even though doc rank-1 raw score (0.40) is far
        // below the primary's rank-2 raw score (0.70).
        $order = array_column($results, 'file_id');
        $topTwo = array_slice($order, 0, 2);
        sort($topTwo);
        $this->assertEquals([1, 101], $topTwo);

        // Within each collection the rank-1 doc outranks the rank-2 doc by fusion.
        $byFile = collect($results)->keyBy('file_id');
        $this->assertGreaterThan($byFile[2]['fusion_score'], $byFile[1]['fusion_score']);
        $this->assertGreaterThan($byFile[102]['fusion_score'], $byFile[101]['fusion_score']);

        // Raw similarity is still the reported relevance for each file.
        $this->assertEqualsWithDelta(0.90, $byFile[1]['score'], 0.0001);
        $this->assertEqualsWithDelta(0.40, $byFile[101]['score'], 0.0001);
    }

    /** @test */
    public function single_collection_search_leaves_scores_unnormalized(): void
    {
        // Only the primary collection returns results; the additional collection
        // is empty. A single contributing source needs no fusion, so absolute
        // cosine scores pass through UNCHANGED (downstream min_score thresholds
        // depend on this).
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

    /**
     * End-to-end gate test through FileContextProvider over a REAL FileSearchService.
     *
     * Proves the absolute min_relevance_score gate now cuts a weak lone-collection
     * doc (raw 0.30) while keeping a strong cross-collection match (raw 0.90) —
     * the precise regression L5-5 caused when the gate ran on rank-normalized scores.
     *
     * @test
     */
    public function provider_threshold_gate_cuts_weak_lone_collection_doc_on_raw_score(): void
    {
        config()->set('ai.file_context.min_relevance_score', 0.4);
        config()->set('ai.file_context.max_references', 5);
        config()->set('ai.file_context.snippet_length', 200);

        // PRIMARY: strong DB match.
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('int'), Mockery::type('array'))
            ->andReturn([
                $this->chunkResult(1, 'db_strong.pdf', 0, 0.90),
            ]);

        // ADDITIONAL: lone weak doc match (raw 0.30, below the 0.4 gate).
        $this->chunkStore
            ->shouldReceive('searchByContent')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('int'), Mockery::type('array'), 'documentation_chunks')
            ->andReturn([
                $this->chunkResult(101, 'doc_weak.md', 0, 0.30),
            ]);

        $this->mockFileModel([1 => 'db_strong.pdf', 101 => 'doc_weak.md']);

        // Access resolver: security off, both ids pass; no filename extraction.
        $accessResolver = Mockery::mock(FileAccessResolverInterface::class);
        $accessResolver->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $accessResolver->shouldReceive('isPhysicalFile')->andReturn(false);
        $accessResolver->shouldReceive('filterAccessibleFileIds')->andReturn([1, 101]);

        $filenameExtractor = Mockery::mock(FilenameExtractor::class);
        $filenameExtractor->shouldReceive('extract')->andReturn([]);

        $provider = new FileContextProvider(
            $this->searchService,
            $accessResolver,
            $filenameExtractor
        );

        $results = $provider->searchRelevantFiles('query', null);

        // Only the strong match survives the raw 0.4 gate; the weak doc is cut.
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['file_id']);
        $this->assertEqualsWithDelta(0.90, $results[0]['relevance'], 0.0001);
    }
}
