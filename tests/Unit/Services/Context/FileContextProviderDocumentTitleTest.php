<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Support\DocumentTitle;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * Every reference carries its document's OWN TITLE (`document_title`): the first
 * top-level heading of the file's first chunk, read at question time off the
 * chunk list the context window already reads — one read of a file per turn,
 * whatever the number of angles and passages. A document without such a heading
 * carries no title.
 *
 * Eval p27 (tear-down): the composer saw only `File: CCQ_Co-ownership_EN.md` and
 * the answer said "the co-ownership laws"; the file itself opens with
 * `# Civil Code of Québec — Co-ownership`.
 */
class FileContextProviderDocumentTitleTest extends TestCase
{
    private const LAW = 'physical:laws/ccq.md';

    private const SCAN = 9;

    /** @var array<string, int> file id => reads of its chunk list */
    private array $fileReads = [];

    /** @var array<string, list<int>> angle => chunk index of the hit, per file */
    private array $hits = [];

    /** @var array<string, mixed> metadata every chunk of the law carries */
    private array $lawMetadata = [];

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.file_context.min_relevance_score', 0.4);
        $app['config']->set('ai.file_context.max_references', 5);
        $app['config']->set('ai.file_context.snippet_length', 6000);
        $app['config']->set('ai.file_context.snippet_chunks', 3);
        $app['config']->set('ai.file_context.context_window', 2);
        $app['config']->set('ai.file_context.security_enabled', false);
        $app['config']->set('ai.file_context.document_map.enabled', false);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function chunk(int|string $fileId, int $index): FileChunk
    {
        if ($fileId === self::LAW) {
            $content = $index === 0
                ? "# Civil Code of Québec — Co-ownership\n\n> Source: Légis Québec - CCQ-1991\n\n---\n\n# TITLE THREE\n# MODALITIES OF OWNERSHIP"
                : "**10{$index}.** Article {$index}.";

            return new FileChunk($fileId, 'ccq.md', $content, [], $index, 10, 0, 10, $this->lawMetadata + ['heading_path' => ['MODALITIES OF OWNERSHIP'], 'is_preamble' => $index === 0]);
        }

        // A scanned upload: no markdown structure at all.
        return new FileChunk($fileId, 'scan-0042.pdf', $index === 0 ? "DECLARATION OF CO-OWNERSHIP\nBEFORE Mtre Tremblay" : "Clause {$index} of the declaration.", [], $index, 10, 0, 10, []);
    }

    private function provider(bool $withStore = true): FileContextProvider
    {
        $this->fileReads = [];

        $search = Mockery::mock(FileSearchService::class);
        $search->shouldReceive('searchByContent')->andReturnUsing(function (string $query): array {
            $results = [];
            foreach ($this->hits[$query] ?? [] as $fileId => $indices) {
                $fileId = $fileId === self::SCAN ? self::SCAN : (string) $fileId;
                $scores = [];
                foreach (array_values($indices) as $rank => $index) {
                    $scores[$index] = 0.8 - $rank / 10;
                }
                $results[] = [
                    'file_id' => $fileId,
                    'collection' => $fileId === self::LAW ? 'documentation_chunks' : '__primary__',
                    'score' => 0.8,
                    'chunk_count' => count($indices),
                    'best_chunk' => $this->chunk($fileId, $indices[0]),
                    'chunks' => array_map(fn (int $index): FileChunk => $this->chunk($fileId, $index), $indices),
                    'chunk_scores' => $scores,
                    'match_type' => 'content',
                ];
            }

            return $results;
        });

        $access = Mockery::mock(FileAccessResolverInterface::class);
        $access->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $access->shouldReceive('isPhysicalFile')->andReturnUsing(static fn ($fileId): bool => $fileId === self::LAW);
        $access->shouldReceive('filterAccessibleFileIds')->andReturnUsing(static fn (array $ids): array => array_values($ids));
        $access->shouldReceive('getAccessibleFileIds')->andReturn([self::SCAN]);

        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn([]);

        $store = null;
        if ($withStore) {
            $store = Mockery::mock(ChunkStoreInterface::class);
            $store->shouldReceive('getFileChunks')->andReturnUsing(function ($fileId, $collection = null): array {
                $this->fileReads[(string) $fileId] = ($this->fileReads[(string) $fileId] ?? 0) + 1;

                // Stored order is not reading order.
                return array_map(fn (int $index): FileChunk => $this->chunk($fileId, $index), [5, 4, 9, 0, 1, 2, 3, 6, 7, 8]);
            });
        }

        return new FileContextProvider($search, $access, $filenames, null, $store);
    }

    /** @test */
    public function a_reference_carries_the_first_top_level_heading_of_its_documents_first_chunk(): void
    {
        $this->hits = ['q' => [self::LAW => [6], self::SCAN => [5]]];

        $files = $this->provider()->searchRelevantFiles('q', null);
        $byName = array_column($files, null, 'filename');

        $this->assertSame('Civil Code of Québec — Co-ownership', $byName['ccq.md']['document_title']);
        $this->assertStringNotContainsString('Civil Code', $byName['ccq.md']['snippet'], 'the passage itself is deep in the document');
        $this->assertArrayNotHasKey('document_title', $byName['scan-0042.pdf'], 'no top-level heading: no title, nothing guessed from a first line');
    }

    /** @test */
    public function the_title_costs_no_read_of_its_own_each_file_is_read_once_per_turn(): void
    {
        $this->hits = [
            'angle a' => [self::LAW => [6, 2], self::SCAN => [5]],
            'angle b' => [self::LAW => [3], self::SCAN => [7]],
            'angle c' => [self::LAW => [8, 6]],
        ];
        $provider = $this->provider();
        $memo = new \ArrayObject();
        $options = [FileContextProvider::TURN_MEMO => $memo, 'chunks_per_file' => 2];

        $references = [];
        foreach (array_keys($this->hits) as $angle) {
            array_push($references, ...$provider->searchRelevantFiles($angle, null, $options));
        }
        // …and a passage selected under the floor, later in the same turn.
        $ranked = $provider->rankedChunks('angle a', null, $options);
        $references[] = $provider->passageAt($ranked[self::LAW]['result'], 2, null, $options);

        $law = array_values(array_filter($references, static fn (array $reference): bool => $reference['file_id'] === self::LAW));
        $this->assertGreaterThanOrEqual(4, count($law), 'several passages of the one file');
        foreach ($law as $reference) {
            $this->assertSame('Civil Code of Québec — Co-ownership', $reference['document_title']);
        }
        foreach (array_filter($references, static fn (array $reference): bool => $reference['file_id'] === self::SCAN) as $reference) {
            $this->assertArrayNotHasKey('document_title', $reference);
        }

        $this->assertSame([self::LAW => 1, (string) self::SCAN => 1], $this->fileReads, 'one read of each file for the whole turn');
    }

    /** @test */
    public function without_a_turn_memo_the_title_adds_no_read_to_the_windows_own(): void
    {
        $this->hits = ['q' => [self::LAW => [6]]];
        $provider = $this->provider();

        $files = $provider->searchRelevantFiles('q', null);

        $this->assertSame('Civil Code of Québec — Co-ownership', $files[0]['document_title']);
        $this->assertSame([self::LAW => 1], $this->fileReads);
    }

    /** @test */
    public function a_title_stored_at_processing_time_is_used_as_it_is_and_needs_no_chunk_list(): void
    {
        $this->lawMetadata = [DocumentTitle::METADATA_KEY => 'Civil Code of Québec — Co-ownership (stored)'];
        $this->hits = ['q' => [self::LAW => [6]]];
        config(['ai.file_context.context_window' => 0]);

        $files = $this->provider()->searchRelevantFiles('q', null);

        $this->assertSame('Civil Code of Québec — Co-ownership (stored)', $files[0]['document_title']);
        $this->assertSame([], $this->fileReads, 'the window is off: the store is never asked');
    }

    /** @test */
    public function with_the_window_off_and_nothing_stored_the_title_is_only_what_the_search_itself_returned(): void
    {
        config(['ai.file_context.context_window' => 0]);
        $provider = $this->provider();

        // The hit is deep in the document: its first chunk is not at hand, and the
        // store is not asked for it (the window's contract: no read when it is off).
        $this->hits = ['q' => [self::LAW => [6]]];
        $this->assertArrayNotHasKey('document_title', $provider->searchRelevantFiles('q', null)[0]);

        // The search returned the first chunk itself: the title is read off it.
        $this->hits = ['q' => [self::LAW => [6, 0]]];
        $this->assertSame('Civil Code of Québec — Co-ownership', $provider->searchRelevantFiles('q', null)[0]['document_title']);

        $this->assertSame([], $this->fileReads);
    }
}
