<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Support\DocumentMap;
use Condoedge\Ai\Tests\Fixtures\InMemoryMapChunkStore;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * The ±context_window around a hit is read from the WHOLE file, past the search
 * filter that hides the parts of a document judged out of the application's
 * scope. A hit on the first article of the governing part therefore stitched
 * the last article of the part before it into a snippet labelled with the
 * governing part (live: art. 1037 of the undivided chapter under a Chapter III
 * header). A neighbour of an out-of-scope part is never stitched around an
 * in-scope hit.
 *
 * The document of these tests (chunk => part):
 *   0 title · 1–2 Part A (in) · 3 heading of Part B · 4–6 Part B (out) ·
 *   7 heading of Part C · 8–10 Part C (in)
 */
class FileContextProviderScopeWindowTest extends TestCase
{
    private const FILE = 'physical:laws/code.md';

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
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return list<FileChunk> */
    private function chunks(): array
    {
        $layout = [
            0 => [['CODE'], true, null],
            1 => [['CODE', 'PART A', 'S1'], false, 'in'],
            2 => [['CODE', 'PART A', 'S1'], false, 'in'],
            3 => [['CODE', 'PART B'], true, null],
            4 => [['CODE', 'PART B', 'S1'], false, 'out'],
            5 => [['CODE', 'PART B', 'S1'], false, 'out'],
            6 => [['CODE', 'PART B', 'S2'], false, 'out'],
            7 => [['CODE', 'PART C'], true, null],
            8 => [['CODE', 'PART C', 'S1'], false, 'in'],
            9 => [['CODE', 'PART C', 'S1'], false, 'in'],
            10 => [['CODE', 'PART C', 'S1'], false, 'in'],
        ];

        $chunks = [];
        foreach ($layout as $index => [$path, $preamble, $scope]) {
            $content = $preamble ? '## ' . end($path) : "Text of chunk {$index}.";
            $metadata = ['heading_path' => $path, 'is_preamble' => $preamble];
            if ($scope !== null) {
                $metadata['map'] = ['v' => DocumentMap::VERSION, 'hash' => DocumentMap::hash($content), 'gist' => "Gist {$index}.", 'scope' => $scope];
            }
            $chunks[] = new FileChunk(self::FILE, 'code.md', $content, [], $index, count($layout), 0, mb_strlen($content), $metadata);
        }

        return $chunks;
    }

    /** The chunks the snippet of a hit on $centre is stitched from. */
    private function stitched(int $centre, ?array $chunks = null, array $options = []): array
    {
        $chunks ??= $this->chunks();
        $store = new InMemoryMapChunkStore();
        $store->put(self::FILE, $chunks, 'documentation_chunks');

        $search = Mockery::mock(FileSearchService::class);
        $search->shouldReceive('searchByContent')->andReturn([[
            'file_id' => self::FILE,
            'score' => 0.7,
            'chunk_count' => 1,
            'best_chunk' => $chunks[$centre],
            'chunks' => [$chunks[$centre]],
            'chunk_scores' => [$centre => 0.7],
            'match_type' => 'content',
            'collection' => 'documentation_chunks',
        ]]);

        $access = Mockery::mock(FileAccessResolverInterface::class);
        $access->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $access->shouldReceive('isPhysicalFile')->andReturn(true);
        $access->shouldReceive('filterAccessibleFileIds')->andReturnUsing(static fn (array $ids): array => $ids);
        $access->shouldReceive('getAccessibleFileIds')->andReturn([self::FILE]);

        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn([]);

        $provider = new FileContextProvider($search, $access, $filenames, null, $store);
        $references = $provider->searchRelevantFiles('a question', null, $options + [FileContextProvider::PASSAGE_CHUNKS => true]);

        return array_keys($references[0]['snippet_chunks']);
    }

    /** @test */
    public function a_hit_on_the_first_passage_of_a_governing_part_does_not_pull_in_the_part_before_it(): void
    {
        // ±2 around chunk 8 is 6–10: chunk 6 belongs to Part B, judged out.
        $this->assertSame([7, 8, 9, 10], $this->stitched(8));
    }

    /** @test */
    public function nor_does_a_hit_on_the_last_passage_before_an_out_of_scope_part_pull_in_its_heading_or_its_text(): void
    {
        // ±2 around chunk 2 is 0–4: chunk 3 is the heading of Part B, chunk 4 its first passage.
        $this->assertSame([0, 1, 2], $this->stitched(2));
    }

    /** @test */
    public function a_passage_the_user_asked_for_by_number_keeps_its_own_surroundings(): void
    {
        // The hit itself is out of scope (only the exact-article angle returns one):
        // the user asked for THAT article, its neighbours are what it is read with.
        $this->assertSame([3, 4, 5, 6, 7], $this->stitched(5, null, ['article_numbers' => ['5']]));
    }

    /** @test */
    public function a_chunk_without_a_record_of_its_own_follows_its_part_and_a_file_without_scope_is_untouched(): void
    {
        $chunks = $this->chunks();
        // Chunk 6 was edited since its record was written.
        $chunks[6] = new FileChunk(self::FILE, 'code.md', 'Text of chunk 6, edited.', [], 6, 11, 0, 24, $chunks[6]->metadata);
        // Chunks 4 and 5 still say the part is out: the edited chunk has no word of its own.
        $this->assertSame([7, 8, 9, 10], $this->stitched(8, $chunks), 'its part is out by its other chunks');

        // A file with no scope at all windows as it always did.
        $plain = array_map(static fn (FileChunk $c): FileChunk => new FileChunk(
            $c->fileId, $c->fileName, $c->content, [], $c->chunkIndex, $c->totalChunks, 0, 0, array_diff_key($c->metadata, ['map' => true])
        ), $this->chunks());
        $this->assertSame([6, 7, 8, 9, 10], $this->stitched(8, $plain));
    }

    /** @test */
    public function with_the_document_map_switched_off_the_window_is_the_whole_neighbourhood(): void
    {
        config(['ai.file_context.document_map.enabled' => false]);

        $this->assertSame([6, 7, 8, 9, 10], $this->stitched(8));
    }
}
