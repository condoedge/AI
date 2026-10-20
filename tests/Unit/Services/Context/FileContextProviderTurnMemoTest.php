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
 * The angles of ONE file-search fan-out share a per-turn memo.
 *
 * Measured live 2026-10-06 (7 angles, one knowledge turn): every angle resolved
 * the same user's file access twice (the vector-search pre-filter, then the
 * post-filter gate — ~0.2 s each on the host's access scope) and re-read the whole
 * file once per passage to stitch its neighbours: ~3 s of a ~9 s stage answering
 * the same questions again. With the memo (FileContextProvider::TURN_MEMO, an
 * ArrayObject the caller creates per fan-out) each answer is asked for once.
 *
 * The gate itself is unchanged: every file id still goes through the access
 * resolver before it can be returned — once per turn instead of once per angle —
 * and the memo is keyed by the user it was resolved for.
 */
class FileContextProviderTurnMemoTest extends TestCase
{
    /** @var list<array<int|string>> File-id lists the post-filter gate was asked about. */
    private array $gateCalls = [];

    private int $accessibleIdCalls = 0;

    private int $fileReads = 0;

    /** @var array<string, list<int>> angle => DB file ids its search returns */
    private array $hits = [];

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.file_context.min_relevance_score', 0.4);
        $app['config']->set('ai.file_context.max_references', 5);
        $app['config']->set('ai.file_context.snippet_length', 6000);
        $app['config']->set('ai.file_context.context_window', 2);
        $app['config']->set('ai.file_context.security_enabled', true);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function chunk(int $fileId, int $index): FileChunk
    {
        return new FileChunk(
            fileId: $fileId,
            fileName: "file-{$fileId}.pdf",
            content: "passage {$index} of file {$fileId}",
            embedding: [],
            chunkIndex: $index,
            totalChunks: 9,
            startPosition: 0,
            endPosition: 10,
            metadata: []
        );
    }

    /** Files 7 and 9 are the user's; file 8 is not. */
    private function provider(): FileContextProvider
    {
        $this->gateCalls = [];
        $this->accessibleIdCalls = $this->fileReads = 0;

        $search = Mockery::mock(FileSearchService::class);
        $search->shouldReceive('searchByContent')->andReturnUsing(function (string $query): array {
            return array_map(fn (int $fileId): array => [
                'file_id' => $fileId,
                'score' => 0.8 - $fileId / 100,
                'chunk_count' => 1,
                'best_chunk' => $this->chunk($fileId, 5),
                'chunks' => [$this->chunk($fileId, 5)],
                'match_type' => 'content',
            ], $this->hits[$query] ?? []);
        });

        $access = Mockery::mock(FileAccessResolverInterface::class);
        $access->shouldReceive('shouldEnforceSecurity')->andReturn(true);
        $access->shouldReceive('isPhysicalFile')->andReturn(false);
        $access->shouldReceive('getAccessibleFileIds')->andReturnUsing(function ($user): array {
            $this->accessibleIdCalls++;

            return $user->id === 1 ? [7, 9] : [8];
        });
        $access->shouldReceive('filterAccessibleFileIds')->andReturnUsing(function (array $ids, $user): array {
            $this->gateCalls[] = array_values($ids);

            return array_values(array_intersect($ids, $user->id === 1 ? [7, 9] : [8]));
        });

        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn([]);

        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('getFileChunks')->andReturnUsing(function ($fileId): array {
            $this->fileReads++;

            return array_map(fn (int $index): FileChunk => $this->chunk((int) $fileId, $index), range(3, 7));
        });

        return new FileContextProvider($search, $access, $filenames, null, $store);
    }

    private function user(int $id): object
    {
        return (object) ['id' => $id];
    }

    /** @test */
    public function the_angles_of_one_fan_out_resolve_the_users_access_and_read_each_file_once(): void
    {
        $this->hits = ['angle a' => [7], 'angle b' => [7], 'angle c' => [7]];
        $provider = $this->provider();
        $user = $this->user(1);
        $memo = new \ArrayObject();

        foreach (['angle a', 'angle b', 'angle c'] as $angle) {
            $files = $provider->searchRelevantFiles($angle, $user, [FileContextProvider::TURN_MEMO => $memo]);
            $this->assertSame([7], array_column($files, 'file_id'));
            $this->assertStringContainsString('passage 3 of file 7', $files[0]['snippet'], 'the window is still stitched');
        }

        $this->assertSame(1, $this->accessibleIdCalls, 'the pre-filter id list is resolved once');
        $this->assertSame([[7]], $this->gateCalls, 'the gate is asked about a file once');
        $this->assertSame(1, $this->fileReads, 'the file is read once for its neighbours');
    }

    /** @test */
    public function a_file_first_seen_by_a_later_angle_still_goes_through_the_gate(): void
    {
        $this->hits = ['angle a' => [7], 'angle b' => [7, 8, 9]];
        $provider = $this->provider();
        $user = $this->user(1);
        $memo = new \ArrayObject();

        $provider->searchRelevantFiles('angle a', $user, [FileContextProvider::TURN_MEMO => $memo]);
        $files = $provider->searchRelevantFiles('angle b', $user, [FileContextProvider::TURN_MEMO => $memo]);

        $this->assertSame([[7], [8, 9]], $this->gateCalls, 'only the files not judged yet are asked about');
        $this->assertEqualsCanonicalizing([7, 9], array_column($files, 'file_id'), "another user's file never comes back");
    }

    /** @test */
    public function a_denied_file_stays_denied_for_the_rest_of_the_turn(): void
    {
        $this->hits = ['angle a' => [8], 'angle b' => [8]];
        $provider = $this->provider();
        $user = $this->user(1);
        $memo = new \ArrayObject();

        $this->assertSame([], $provider->searchRelevantFiles('angle a', $user, [FileContextProvider::TURN_MEMO => $memo]));
        $this->assertSame([], $provider->searchRelevantFiles('angle b', $user, [FileContextProvider::TURN_MEMO => $memo]));
        $this->assertSame([[8]], $this->gateCalls);
    }

    /** @test */
    public function without_a_memo_every_search_resolves_access_itself(): void
    {
        $this->hits = ['angle a' => [7], 'angle b' => [7]];
        $provider = $this->provider();
        $user = $this->user(1);

        $provider->searchRelevantFiles('angle a', $user);
        $provider->searchRelevantFiles('angle b', $user);

        $this->assertSame(2, $this->accessibleIdCalls);
        $this->assertSame([[7], [7]], $this->gateCalls);
        $this->assertSame(2, $this->fileReads);
    }

    /** @test */
    public function a_memo_never_carries_one_users_access_to_another(): void
    {
        $this->hits = ['angle a' => [7, 8]];
        $provider = $this->provider();
        $memo = new \ArrayObject();

        $mine = $provider->searchRelevantFiles('angle a', $this->user(1), [FileContextProvider::TURN_MEMO => $memo]);
        $theirs = $provider->searchRelevantFiles('angle a', $this->user(2), [FileContextProvider::TURN_MEMO => $memo]);

        $this->assertSame([7], array_column($mine, 'file_id'));
        $this->assertSame([8], array_column($theirs, 'file_id'));
        $this->assertSame(2, $this->accessibleIdCalls);
        $this->assertCount(2, $this->gateCalls);
    }
}
