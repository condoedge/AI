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
 * The passage selection of AiManager shows a reader the chunks the search ranked
 * under the relevance floor and may keep one of them. The provider gives it both
 * halves: rankedChunks() — the ranked chunks with their text and heading — and
 * passageAt() — the same reference a search would emit for one of those chunks.
 */
class FileContextProviderPassageAtTest extends TestCase
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

    private function chunk(int $index): FileChunk
    {
        return new FileChunk(
            fileId: 7,
            fileName: 'law.md',
            content: "**10{$index}.** Article {$index}.",
            embedding: [],
            chunkIndex: $index,
            totalChunks: 90,
            startPosition: 0,
            endPosition: 10,
            metadata: ['article_no' => '10' . $index, 'heading_path' => ['PART ONE', 'Division ' . intdiv($index, 10)], 'is_preamble' => false]
        );
    }

    /** @param list<int|string> $granted */
    private function provider(array $scores, array $granted = [7]): FileContextProvider
    {
        arsort($scores);
        $best = (int) array_key_first($scores);

        $search = Mockery::mock(FileSearchService::class);
        $search->shouldReceive('searchByContent')->andReturn([[
            'file_id' => 7,
            'score' => $scores[$best],
            'chunk_count' => count($scores),
            'best_chunk' => $this->chunk($best),
            'chunks' => array_map(fn (int $index): FileChunk => $this->chunk($index), array_keys($scores)),
            'chunk_scores' => $scores,
            'match_type' => 'content',
        ]]);

        $access = Mockery::mock(FileAccessResolverInterface::class);
        $access->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $access->shouldReceive('isPhysicalFile')->andReturn(true);
        $access->shouldReceive('filterAccessibleFileIds')->andReturnUsing(
            static fn (array $ids): array => array_values(array_intersect($ids, $granted))
        );
        $access->shouldReceive('getAccessibleFileIds')->andReturn($granted);

        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn([]);

        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('getFileChunks')->andReturn(array_map(fn (int $index): FileChunk => $this->chunk($index), range(50, 70)));

        return new FileContextProvider($search, $access, $filenames, null, $store);
    }

    /** @test */
    public function ranked_chunks_carry_their_text_and_heading_best_first_whatever_their_score(): void
    {
        $ranked = $this->provider([61 => 0.36, 58 => 0.28, 64 => 0.44])->rankedChunks('may I do this', null);

        $this->assertSame([64, 61, 58], array_keys($ranked[7]['chunks']));
        $this->assertSame('**1058.** Article 58.', $ranked[7]['chunks'][58]['content']);
        $this->assertSame(['PART ONE', 'Division 5'], $ranked[7]['chunks'][58]['heading_path']);
        $this->assertEqualsWithDelta(0.28, $ranked[7]['chunks'][58]['score'], 1e-9);
        $this->assertSame(7, $ranked[7]['result']['file_id']);
    }

    /** @test */
    public function a_passage_can_be_built_at_a_chunk_the_floor_did_not_admit(): void
    {
        $provider = $this->provider([61 => 0.66, 58 => 0.28]);
        $ranked = $provider->rankedChunks('may I do this', null);

        $passage = $provider->passageAt($ranked[7]['result'], 58, null, [FileContextProvider::PASSAGE_CHUNKS => true]);

        $this->assertSame(58, $passage['chunk_index']);
        $this->assertEqualsWithDelta(0.28, $passage['relevance'], 1e-9, 'its own similarity, not the best chunk of the file');
        $this->assertSame([56, 57, 58, 59, 60], array_keys($passage['snippet_chunks']), 'the chunk and its ±2 neighbours, as in any search');
        $this->assertSame(implode(FileContextProvider::SNIPPET_SEPARATOR, $passage['snippet_chunks']), $passage['snippet']);
        $this->assertSame(['PART ONE', 'Division 5'], $passage['heading_path']);
        $this->assertArrayNotHasKey('chunk_scores', $passage);
    }

    /** @test */
    public function no_passage_for_a_chunk_that_was_not_ranked(): void
    {
        $provider = $this->provider([61 => 0.66]);
        $ranked = $provider->rankedChunks('may I do this', null);

        $this->assertNull($provider->passageAt($ranked[7]['result'], 58, null));
    }

    /** @test */
    public function no_passage_of_a_file_the_access_gate_refuses(): void
    {
        $open = $this->provider([58 => 0.28]);
        $result = $open->rankedChunks('may I do this', null)[7]['result'];

        $closed = $this->provider([58 => 0.28], granted: []);

        $this->assertSame([], $closed->rankedChunks('may I do this', null));
        $this->assertNull($closed->passageAt($result, 58, null), 'a result obtained elsewhere does not open the file');
    }
}
