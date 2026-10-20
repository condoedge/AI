<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Illuminate\Database\Eloquent\Model;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * Phase 7 retrieval over structure-aware chunks:
 *  - 7.3  ACL as a vector-store PRE-filter: the user's accessible DB file ids are a
 *         file_id MatchAny filter on the DB-file collection, so 50 foreign chunks
 *         can no longer fill the top-k and push the user's own file out. The
 *         physical (docs) collection is never ACL-filtered.
 *  - 7.2  exact-article angle: article numbers become a payload filter.
 *  - 7.1b preamble chunks never set a file's relevance / best chunk, and a
 *         preamble-only file is dropped unless nothing else matched.
 *  - references carry the chunk's article / heading / passage.
 */
class StructuredRetrievalTest extends TestCase
{
    private const DOCS = 'documentation_chunks';

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.file_context.min_relevance_score', 0.4);
        $app['config']->set('ai.file_context.max_references', 5);
        $app['config']->set('ai.file_context.snippet_length', 2000);
        $app['config']->set('ai.file_context.security_enabled', true);
        $app['config']->set('ai.file_context.physical_collection', self::DOCS);
        $app['config']->set('ai.file_processing.collection', 'file_chunks');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $builder = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $builder->shouldReceive('get')->andReturn(collect([]));
        $model = Mockery::mock(Model::class)->makePartial();
        $model->shouldReceive('whereIn')->andReturn($builder);
        $this->app->instance('file-model', $model);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private static function chunk(int|string $fileId, int $index, string $content, array $metadata = []): FileChunk
    {
        return new FileChunk(
            fileId: $fileId,
            fileName: is_string($fileId) ? basename($fileId) : "file-{$fileId}.pdf",
            content: $content,
            embedding: [],
            chunkIndex: $index,
            totalChunks: 200,
            startPosition: 0,
            endPosition: mb_strlen($content),
            metadata: $metadata,
        );
    }

    /**
     * An in-memory chunk store that behaves like Qdrant: it applies the payload
     * filters it is given (file_ids MatchAny, article_numbers MatchAny on
     * metadata.articles), then returns the top-$limit by score.
     *
     * @param array<string,list<array{chunk: FileChunk, score: float}>> $byCollection
     */
    private function fakeStore(array $byCollection, array &$seenFilters): ChunkStoreInterface
    {
        return new class($byCollection, $seenFilters) implements ChunkStoreInterface {
            public function __construct(private array $byCollection, private array &$seen) {}

            public function searchByContent(string $query, int $limit = 10, array $filters = [], ?string $collection = null): array
            {
                $key = $collection ?? 'file_chunks';
                $this->seen[$key][] = $filters;
                $rows = array_values(array_filter($this->byCollection[$key] ?? [], function (array $r) use ($filters) {
                    if (isset($filters['file_ids']) && !in_array($r['chunk']->fileId, $filters['file_ids'], false)) {
                        return false;
                    }
                    if (isset($filters['article_numbers'])
                        && array_intersect($filters['article_numbers'], $r['chunk']->metadata['articles'] ?? []) === []) {
                        return false;
                    }
                    return $r['score'] >= ($filters['min_score'] ?? 0.0);
                }));
                usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);

                return array_slice($rows, 0, $limit);
            }

            public function storeChunk(FileChunk $chunk, ?string $collection = null): bool { return true; }
            public function storeChunks(array $chunks, ?string $collection = null): bool { return true; }
            public function getFileChunks(int|string $fileId, ?string $collection = null): array { return []; }
            public function deleteFileChunks(int|string $fileId, ?string $collection = null): bool { return true; }
            public function getChunkCount(array $filters = []): int { return 0; }
            public function hasFileChunks(int|string $fileId, ?string $collection = null): bool { return false; }
            public function getChunk(string $vectorId): ?FileChunk { return null; }
            public function searchByFilename(string $filename, int $limit = 10, array $filters = [], ?string $collection = null): array { return []; }
        };
    }

    private function provider(ChunkStoreInterface $store, array $accessibleIds): FileContextProvider
    {
        $resolver = Mockery::mock(FileAccessResolverInterface::class);
        $resolver->shouldReceive('shouldEnforceSecurity')->andReturn(true);
        $resolver->shouldReceive('isPhysicalFile')->andReturnUsing(fn ($id) => is_string($id) && str_starts_with($id, 'physical:'));
        $resolver->shouldReceive('getAccessibleFileIds')->andReturn($accessibleIds);
        $resolver->shouldReceive('filterAccessibleFileIds')->andReturnUsing(
            fn ($ids) => array_values(array_filter($ids, fn ($id) => (is_string($id) && str_starts_with($id, 'physical:')) || in_array($id, $accessibleIds, false)))
        );

        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn([]);

        $search = new FileSearchService($store, Mockery::mock(GraphStoreInterface::class), [self::DOCS]);

        return new FileContextProvider($search, $resolver, $filenames);
    }

    /** @test */
    public function the_users_own_file_ranks_even_when_fifty_foreign_similar_chunks_exist(): void
    {
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = ['chunk' => self::chunk(1000 + $i, 0, "Foreign declaration {$i}: pets are allowed."), 'score' => 0.9 - $i * 0.001];
        }
        $rows[] = ['chunk' => self::chunk(7, 3, 'Our declaration: pets are allowed on leash.'), 'score' => 0.6];

        $seen = [];
        $context = $this->provider($this->fakeStore(['file_chunks' => $rows], $seen), [7])
            ->getFileContext('Are pets allowed?', (object) ['id' => 13]);

        $this->assertSame([7], array_column($context['relevant_files'], 'file_id'));
        // The ACL set reached the DB-file collection as a pre-filter...
        $this->assertSame([7], $seen['file_chunks'][0]['file_ids']);
        // ...and the physical corpus stays searchable by everyone (never ACL-filtered).
        $this->assertArrayNotHasKey('file_ids', $seen[self::DOCS][0]);
    }

    /** @test */
    public function a_user_with_no_accessible_db_files_still_gets_the_physical_corpus(): void
    {
        $seen = [];
        $store = $this->fakeStore([
            'file_chunks' => [['chunk' => self::chunk(1001, 0, 'Foreign file.'), 'score' => 0.9]],
            self::DOCS => [['chunk' => self::chunk('physical:laws/ccq.md', 66, '**1069.** A person who acquires a fraction…', ['articles' => ['1069'], 'article_no' => '1069']), 'score' => 0.7]],
        ], $seen);

        $context = $this->provider($store, [])->getFileContext('buyer liable for arrears?', (object) ['id' => 13]);

        $this->assertSame(['physical:laws/ccq.md'], array_column($context['relevant_files'], 'file_id'));
    }

    /** @test */
    public function the_article_angle_filters_on_article_numbers_and_admits_the_exact_article(): void
    {
        $seen = [];
        $store = $this->fakeStore([
            self::DOCS => [
                ['chunk' => self::chunk('physical:laws/ccq.md', 63, '**1068.** Every co-owner may…', ['articles' => ['1068'], 'article_no' => '1068']), 'score' => 0.8],
                ['chunk' => self::chunk('physical:laws/ccq.md', 66, '**1069.** A person who acquires a fraction…', ['articles' => ['1069'], 'article_no' => '1069', 'section_title' => 'DIVISION IV']), 'score' => 0.31],
            ],
        ], $seen);

        $context = $this->provider($store, [7])->getFileContext(
            'What does article 1069 say?',
            (object) ['id' => 13],
            ['article_numbers' => ['1069'], 'min_score' => 0.0]
        );

        $this->assertSame(['1069'], $seen[self::DOCS][0]['article_numbers']);
        $this->assertCount(1, $context['relevant_files']);
        $ref = $context['relevant_files'][0];
        $this->assertSame(66, $ref['chunk_index']);
        $this->assertSame('1069', $ref['article_no']);
        $this->assertTrue($ref['article_match']);
    }

    /** @test */
    public function preamble_chunks_never_set_a_files_relevance_or_best_chunk(): void
    {
        $seen = [];
        $store = $this->fakeStore([
            self::DOCS => [
                ['chunk' => self::chunk('physical:laws/ccq.md', 0, "# Civil Code of Québec — Co-ownership\n> Source", ['articles' => [], 'is_preamble' => true]), 'score' => 0.72],
                ['chunk' => self::chunk('physical:laws/ccq.md', 66, '**1069.** A person who acquires a fraction…', ['articles' => ['1069'], 'article_no' => '1069', 'is_preamble' => false, 'heading_path' => ['DIVISION IV'], 'section_title' => 'DIVISION IV']), 'score' => 0.55],
            ],
        ], $seen);

        $ref = $this->provider($store, [])->getFileContext('Quebec Civil Code buyer arrears', (object) ['id' => 13])['relevant_files'][0];

        $this->assertSame(66, $ref['chunk_index']);
        $this->assertEqualsWithDelta(0.55, $ref['relevance'], 1e-9);
        $this->assertFalse($ref['is_preamble']);
        $this->assertSame('DIVISION IV', $ref['heading']);
        $this->assertSame('**1069.** A person who acquires a fraction…', $ref['passage']);
    }

    /** @test */
    public function an_angle_can_surface_several_distinct_passages_of_one_file_when_asked(): void
    {
        // One article per chunk: the runner-up article of the same file (0.425 vs
        // 0.427) used to be structurally lost — one reference per file per angle.
        $ccq = 'physical:laws/ccq.md';
        $rows = [
            ['chunk' => self::chunk($ccq, 61, '**1066.** No co-owner may interfere…', ['articles' => ['1066'], 'article_no' => '1066', 'is_preamble' => false]), 'score' => 0.427],
            ['chunk' => self::chunk($ccq, 38, '**1045.** Partitions or walls…', ['articles' => ['1045'], 'article_no' => '1045', 'is_preamble' => false]), 'score' => 0.425],
            ['chunk' => self::chunk($ccq, 60, '**1064.1.** Each co-owner shall…', ['articles' => ['1064.1'], 'article_no' => '1064.1', 'is_preamble' => false]), 'score' => 0.41],
            ['chunk' => self::chunk($ccq, 112, '**1097.** Three-quarters…', ['articles' => ['1097'], 'article_no' => '1097', 'is_preamble' => false]), 'score' => 0.35],
        ];

        $seen = [];
        $default = $this->provider($this->fakeStore([self::DOCS => $rows], $seen), [])
            ->getFileContext('modify a wall', (object) ['id' => 13]);
        $this->assertSame([61], array_column($default['relevant_files'], 'chunk_index'), 'default stays one reference per file');

        $multi = $this->provider($this->fakeStore([self::DOCS => $rows], $seen), [])
            ->getFileContext('modify a wall', (object) ['id' => 13], ['chunks_per_file' => 3]);

        // 60 is inside 61's ±2 window (already in its snippet); 112 is under the threshold.
        $this->assertSame([61, 38], array_column($multi['relevant_files'], 'chunk_index'));
        $this->assertSame('1045', $multi['relevant_files'][1]['article_no']);
        $this->assertEqualsWithDelta(0.425, $multi['relevant_files'][1]['relevance'], 1e-9);
        $this->assertStringContainsString('**1045.**', $multi['relevant_files'][1]['snippet']);
    }

    /** @test */
    public function a_preamble_only_file_is_dropped_when_anything_else_matches_but_kept_when_alone(): void
    {
        $title = ['chunk' => self::chunk('physical:laws/ccq.md', 0, '# Civil Code of Québec', ['articles' => [], 'is_preamble' => true]), 'score' => 0.8];
        $howTo = ['chunk' => self::chunk('physical:docs/team.md', 1, '## Adding a new member', ['articles' => [], 'is_preamble' => false]), 'score' => 0.5];

        $seen = [];
        $both = $this->provider($this->fakeStore([self::DOCS => [$title, $howTo]], $seen), [])
            ->getFileContext('add member', (object) ['id' => 13]);
        $this->assertSame(['physical:docs/team.md'], array_column($both['relevant_files'], 'file_id'));

        $alone = $this->provider($this->fakeStore([self::DOCS => [$title]], $seen), [])
            ->getFileContext('civil code', (object) ['id' => 13]);
        $this->assertSame(['physical:laws/ccq.md'], array_column($alone['relevant_files'], 'file_id'));
        $this->assertTrue($alone['relevant_files'][0]['is_preamble']);
    }

    /**
     * @test D4 (eval p16-B rights t1; p14 rights t0): "DIVISION II — RIGHTS AND OBLIGATIONS OF
     * CO-OWNERS" heads a division of BOTH the undivided chapter and the divided chapter. The
     * reference carried only that last heading, so the indivision passage read as condo law.
     * It now carries the chunk's full heading path — and the passage header the model reads
     * shows it, with the article attribution untouched.
     */
    public function two_passages_with_the_same_leaf_heading_are_told_apart_by_their_chapter(): void
    {
        $ccq = 'physical:laws/ccq.md';
        $leaf = 'DIVISION II — RIGHTS AND OBLIGATIONS OF CO-OWNERS';
        $undivided = ['MODALITIES OF OWNERSHIP', 'CHAPTER II — UNDIVIDED CO-OWNERSHIP', $leaf];
        $divided = ['MODALITIES OF OWNERSHIP', 'CHAPTER III — DIVIDED CO-OWNERSHIP OF IMMOVABLES', $leaf];
        $rows = [
            ['chunk' => self::chunk($ccq, 59, '**1064.** Each co-owner contributes to the common expenses in proportion to the relative value of his fraction.', [
                'articles' => ['1064'], 'article_no' => '1064', 'is_preamble' => false, 'heading_path' => $divided, 'section_title' => $leaf,
            ]), 'score' => 0.71],
            ['chunk' => self::chunk($ccq, 11, '**1019.** The undivided co-owners are liable proportionately to their shares for the costs of administration and the other common charges related to the undivided property.', [
                'articles' => ['1019'], 'article_no' => '1019', 'is_preamble' => false, 'heading_path' => $undivided, 'section_title' => $leaf,
            ]), 'score' => 0.69],
        ];

        // One chunk per snippet: without a window store the snippet would stitch both chunks.
        config(['ai.file_context.snippet_chunks' => 1]);

        $seen = [];
        $context = $this->provider($this->fakeStore([self::DOCS => $rows], $seen), [])
            ->getFileContext('duty to contribute to common expenses', (object) ['id' => 13], ['chunks_per_file' => 3]);

        $refs = $context['relevant_files'];
        $this->assertSame([59, 11], array_column($refs, 'chunk_index'));
        $this->assertSame('MODALITIES OF OWNERSHIP › CHAPTER III — DIVIDED CO-OWNERSHIP OF IMMOVABLES › ' . $leaf, $refs[0]['heading']);
        $this->assertSame('MODALITIES OF OWNERSHIP › CHAPTER II — UNDIVIDED CO-OWNERSHIP › ' . $leaf, $refs[1]['heading']);
        $this->assertNotSame($refs[0]['heading'], $refs[1]['heading']);
        // The path's levels ride along for the cross-angle merge (branch coherence, eval p17).
        $this->assertSame($divided, $refs[0]['heading_path']);
        $this->assertSame($undivided, $refs[1]['heading_path']);

        // What the composition model reads: the article label on each passage's header
        // line, and the full path as the "§" line inside the passage's fence (the
        // path is document content — it is never printed on the header).
        $pipeline = new \Condoedge\Ai\DTOs\PipelineContext();
        $pipeline->fileContext = $context;
        $prompt = (new \Condoedge\Ai\Services\ResponseSections\FileContextSection())->format($pipeline, ['style' => 'friendly']);
        $headers = array_values(array_filter(explode("\n", $prompt), static fn (string $line) => str_starts_with($line, '[')));
        $sections = array_values(array_filter(explode("\n", $prompt), static fn (string $line) => str_starts_with($line, '§ ')));

        $this->assertCount(2, $headers);
        // …followed by whose document it is (2026-10-08: a project file is a general document).
        $this->assertStringContainsString(' — art. 1064 — ', $headers[0]);
        $this->assertStringContainsString(' — art. 1019 — ', $headers[1]);
        $this->assertStringEndsWith('GENERAL document, applies to everyone', $headers[0]);
        $this->assertSame([
            '§ MODALITIES OF OWNERSHIP › CHAPTER III — DIVIDED CO-OWNERSHIP OF IMMOVABLES › ' . $leaf,
            '§ MODALITIES OF OWNERSHIP › CHAPTER II — UNDIVIDED CO-OWNERSHIP › ' . $leaf,
        ], $sections);

        // Article attribution is read from the passage / chunk metadata, never from the header text.
        $this->assertSame([1064], \Condoedge\Ai\Support\ArticleLocator::forReference($refs[0]));
        $this->assertSame([1019], \Condoedge\Ai\Support\ArticleLocator::forReference($refs[1]));
    }

    /** @test A legacy chunk without a heading path keeps its section title as the heading. */
    public function a_chunk_without_a_heading_path_keeps_its_section_title(): void
    {
        $seen = [];
        $store = $this->fakeStore([self::DOCS => [
            ['chunk' => self::chunk('physical:laws/ccq.md', 66, '**1069.** A person who acquires a fraction…', ['articles' => ['1069'], 'article_no' => '1069', 'is_preamble' => false, 'section_title' => 'DIVISION IV']), 'score' => 0.55],
        ]], $seen);

        $ref = $this->provider($store, [])->getFileContext('buyer arrears', (object) ['id' => 13])['relevant_files'][0];

        $this->assertSame('DIVISION IV', $ref['heading']);
        $this->assertArrayNotHasKey('heading_path', $ref, 'no path, no branch: branch coherence leaves the file alone');
    }
}
