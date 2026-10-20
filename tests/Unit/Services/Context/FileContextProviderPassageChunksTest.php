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
 * Eval p18-B rights t0: two kept passages of one file whose ±2 windows overlapped
 * printed the same articles twice. To merge such passages the fan-out needs to know
 * WHICH chunks a snippet is made of — a reference can carry that map
 * (FileContextProvider::PASSAGE_CHUNKS), asked for by the caller that merges.
 */
class FileContextProviderPassageChunksTest extends TestCase
{
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

    private function chunk(int $index, ?string $content = null): FileChunk
    {
        return new FileChunk(
            fileId: 7,
            fileName: 'law.md',
            content: $content ?? "**10{$index}.** Article {$index}.",
            embedding: [],
            chunkIndex: $index,
            totalChunks: 90,
            startPosition: 0,
            endPosition: 10,
            metadata: ['article_no' => '10' . $index]
        );
    }

    /**
     * @param array<int, float> $scores chunk index => this angle's score (the best one leads)
     * @param array<int, string> $contents overrides of a chunk's text
     */
    private function provider(array $scores, array $contents = []): FileContextProvider
    {
        arsort($scores);
        $best = (int) array_key_first($scores);
        $chunk = fn (int $index): FileChunk => $this->chunk($index, $contents[$index] ?? null);

        $search = Mockery::mock(FileSearchService::class);
        $search->shouldReceive('searchByContent')->andReturn([[
            'file_id' => 7,
            'score' => $scores[$best],
            'chunk_count' => count($scores),
            'best_chunk' => $chunk($best),
            'chunks' => array_map($chunk, array_keys($scores)),
            'chunk_scores' => $scores,
            'match_type' => 'content',
        ]]);

        $access = Mockery::mock(FileAccessResolverInterface::class);
        $access->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $access->shouldReceive('isPhysicalFile')->andReturn(true);
        $access->shouldReceive('filterAccessibleFileIds')->andReturnUsing(static fn (array $ids): array => $ids);
        $access->shouldReceive('getAccessibleFileIds')->andReturn([7]);

        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn([]);

        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('getFileChunks')->andReturn(array_map($chunk, range(50, 70)));

        return new FileContextProvider($search, $access, $filenames, null, $store);
    }

    /** @test */
    public function a_reference_can_carry_the_chunks_its_snippet_is_made_of(): void
    {
        $files = $this->provider([58 => 0.62, 61 => 0.59])->searchRelevantFiles('rights', null, [
            'chunks_per_file' => 2,
            FileContextProvider::PASSAGE_CHUNKS => true,
        ]);

        $this->assertSame([58, 61], array_column($files, 'chunk_index'));
        [$first, $second] = $files;

        $this->assertSame([56, 57, 58, 59, 60], array_keys($first['snippet_chunks']), 'the hit and its ±2 neighbours, in reading order');
        $this->assertSame([59, 60, 61, 62, 63], array_keys($second['snippet_chunks']));
        $this->assertSame('**1058.** Article 58.', $first['snippet_chunks'][58]);

        // The map IS the snippet: its chunks joined by the provider's separator.
        $this->assertSame(implode(FileContextProvider::SNIPPET_SEPARATOR, $first['snippet_chunks']), $first['snippet']);
        $this->assertSame(implode(FileContextProvider::SNIPPET_SEPARATOR, $second['snippet_chunks']), $second['snippet']);
    }

    /** @test */
    public function the_map_holds_only_what_the_length_budget_admitted(): void
    {
        config(['ai.file_context.snippet_length' => 60]);

        $files = $this->provider([58 => 0.62])->searchRelevantFiles('rights', null, [FileContextProvider::PASSAGE_CHUNKS => true]);

        // 21 chars per chunk + 3 per separator: the hit and one neighbour fit in 60.
        $this->assertSame([56, 58], array_keys($files[0]['snippet_chunks']));
        $this->assertSame(implode(FileContextProvider::SNIPPET_SEPARATOR, $files[0]['snippet_chunks']), $files[0]['snippet']);
    }

    /** @test */
    public function without_the_option_a_reference_carries_no_chunk_map(): void
    {
        $files = $this->provider([58 => 0.62])->searchRelevantFiles('rights', null, []);

        $this->assertArrayNotHasKey('snippet_chunks', $files[0]);
        $this->assertStringContainsString('**1058.** Article 58.', $files[0]['snippet']);
    }
}
