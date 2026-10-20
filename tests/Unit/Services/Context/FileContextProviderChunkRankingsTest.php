<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * Eval p16-p21 tear-down: the user's own wording scored its best passage 0.38 —
 * under the 0.40 relevance floor — so that angle returned nothing and cast no vote
 * when the fan-out chose a file's passages, and QU's rewrite voted only in the runs
 * where it happened to score 0.41 instead of 0.37.
 *
 * The floor decides what an angle ADMITS. How it RANKS a file's chunks needs no
 * floor: chunkRankings() returns file id => chunk_index => this angle's similarity,
 * for every accessible file among the angle's nearest chunks, whatever their score.
 */
class FileContextProviderChunkRankingsTest extends TestCase
{
    /** @var list<array<string, mixed>> The options of every content search, in order. */
    private array $searches = [];

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.file_context.min_relevance_score', 0.4);
        $app['config']->set('ai.file_context.max_references', 5);
        $app['config']->set('ai.file_context.context_window', 2);
        $app['config']->set('ai.file_context.security_enabled', false);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function chunk(int|string $fileId, int $index): FileChunk
    {
        return new FileChunk(
            fileId: $fileId,
            fileName: "file-{$fileId}.md",
            content: "Article {$index}.",
            embedding: [],
            chunkIndex: $index,
            totalChunks: 120,
            startPosition: 0,
            endPosition: 10,
            metadata: []
        );
    }

    /**
     * @param array<int|string, array<int, float>> $ranking file id => chunk index => this angle's score
     * @param list<int|string>|null $granted The files the access gate lets through (null = all).
     */
    private function provider(array $ranking, ?array $granted = null, bool $rankSearchFails = false): FileContextProvider
    {
        $search = Mockery::mock(FileSearchService::class);
        $search->shouldReceive('searchByContent')->andReturnUsing(function (string $query, array $options) use ($ranking, $rankSearchFails) {
            $this->searches[] = $options;
            $floor = (float) ($options['min_score'] ?? 0.0);
            if ($floor <= 0.0 && $rankSearchFails) {
                throw new \RuntimeException('vector store timeout');
            }

            $results = [];
            foreach ($ranking as $fileId => $scores) {
                $scores = array_filter($scores, static fn (float $score): bool => $score >= $floor);
                if ($scores === []) {
                    continue;
                }
                arsort($scores);
                $best = (int) array_key_first($scores);
                $results[] = [
                    'file_id' => $fileId,
                    'score' => $scores[$best],
                    'chunk_count' => count($scores),
                    'best_chunk' => $this->chunk($fileId, $best),
                    'chunks' => array_map(fn (int $index): FileChunk => $this->chunk($fileId, $index), array_keys($scores)),
                    'chunk_scores' => $scores,
                    'match_type' => 'content',
                ];
            }

            return $results;
        });

        $access = Mockery::mock(FileAccessResolverInterface::class);
        $access->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $access->shouldReceive('isPhysicalFile')->andReturn(true);
        $access->shouldReceive('filterAccessibleFileIds')->andReturnUsing(
            static fn (array $ids): array => $granted === null ? $ids : array_values(array_intersect($ids, $granted))
        );
        $access->shouldReceive('getAccessibleFileIds')->andReturn($granted ?? array_keys($ranking));

        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn([]);

        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('getFileChunks')->andReturn([]);

        return new FileContextProvider($search, $access, $filenames, null, $store);
    }

    /** @test */
    public function an_angle_under_the_floor_still_reports_how_it_ranks_the_files_chunks(): void
    {
        $provider = $this->provider([7 => [38 => 0.38, 113 => 0.34, 37 => 0.33]]);

        $this->assertSame([], $provider->getFileContext('can I tear down this wall', null, [])['relevant_files'], 'nothing cleared the floor: no passage is admitted');
        $this->assertSame([7 => [38 => 0.38, 113 => 0.34, 37 => 0.33]], $provider->chunkRankings('can I tear down this wall', null));
    }

    /** @test */
    public function the_ranking_is_the_full_one_while_the_passages_stay_floor_bound(): void
    {
        $provider = $this->provider([7 => [61 => 0.36, 38 => 0.44, 37 => 0.39, 113 => 0.41]]);
        $context = $provider->getFileContext('rules for modifying a wall', null, ['chunks_per_file' => 5]);

        $this->assertSame([38, 113], array_column($context['relevant_files'], 'chunk_index'));
        $this->assertEqualsCanonicalizing([38, 113], array_keys($context['relevant_files'][0]['chunk_scores']), 'a reference keeps its floor-bound scores');
        $this->assertSame([38, 113, 37, 61], array_keys($provider->chunkRankings('rules for modifying a wall', null)[7]), 'best first');
    }

    /** @test */
    public function the_ranking_search_is_the_same_search_without_the_floor(): void
    {
        $provider = $this->provider([7 => [38 => 0.38]]);
        $provider->getFileContext('q', null, []);
        $provider->chunkRankings('q', null);

        $this->assertCount(2, $this->searches);
        [$passages, $ranks] = $this->searches;
        $this->assertEqualsWithDelta(0.4, (float) $passages['min_score'], 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $ranks['min_score'], 1e-9);
        $this->assertSame($passages['limit'], $ranks['limit']);
    }

    /** @test */
    public function a_file_the_access_gate_refuses_is_not_ranked(): void
    {
        $rankings = $this->provider([7 => [38 => 0.38], 9 => [4 => 0.39]], granted: [7])->chunkRankings('q', null);

        $this->assertSame([7], array_keys($rankings));
    }

    /** @test */
    public function a_search_alone_fetches_no_ranking(): void
    {
        $context = $this->provider([7 => [38 => 0.44]])->getFileContext('q', null, []);

        $this->assertSame(['relevant_files', 'file_count', 'has_physical', 'has_database'], array_keys($context));
        $this->assertCount(1, $this->searches, 'one content search: the ranking is a separate, optional lookup');
    }

    /** @test */
    public function a_failed_ranking_search_never_throws(): void
    {
        $provider = $this->provider([7 => [38 => 0.44]], rankSearchFails: true);

        $this->assertSame([], $provider->chunkRankings('q', null));
        $this->assertSame([38], array_column($provider->getFileContext('q', null, [])['relevant_files'], 'chunk_index'));
    }
}
