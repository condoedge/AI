<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GroupedSearchInterface;
use Condoedge\Ai\Contracts\UpdatesPayloadInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\QdrantChunkStore;
use Condoedge\Ai\Support\FileLink;
use Condoedge\Ai\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * The chunk store and the link of an uploaded file to its record:
 *  - the link a chunk carries (`metadata['link']`) is stored as the point's
 *    top-level `owner` / `ancestors` / `dedupe` keys and read back the same way;
 *  - a search can be narrowed to a SCOPE (a focus record and its ancestors);
 *  - a search that asks for distinct passages returns ONE candidate per
 *    (normalised text, owner), grouped inside the vector search — before any
 *    limit — with a deterministic representative (the lowest file id);
 *  - existing chunks are linked by a payload-only write.
 */
class QdrantChunkStoreFileLinkTest extends TestCase
{
    private const OUT_OF_SCOPE = [['key' => 'map.scope', 'match' => ['value' => 'out']]];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function vectors(string ...$interfaces): MockInterface
    {
        $vectors = Mockery::mock(VectorStoreInterface::class, ...$interfaces);
        $vectors->shouldReceive('collectionExists')->andReturn(true);
        $vectors->shouldReceive('ensurePayloadIndex')->andReturn(true)->byDefault();

        return $vectors;
    }

    private function store(MockInterface $vectors): QdrantChunkStore
    {
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embed')->andReturn(array_fill(0, 4, 0.1));

        return new QdrantChunkStore($vectors, $embedder, 'file_chunks', 4);
    }

    private function chunk(int $fileId, int $index, string $content, ?array $link = null): FileChunk
    {
        return new FileChunk($fileId, 'declaration.pdf', $content, array_fill(0, 4, 0.1), $index, 3, 0, 10, $link === null ? [] : ['link' => $link]);
    }

    private function point(int $fileId, int $index, string $content, ?string $owner, float $score = 0.9, bool $linked = true): array
    {
        $payload = [
            'file_id' => $fileId,
            'file_name' => 'declaration.pdf',
            'content' => $content,
            'chunk_index' => $index,
            'total_chunks' => 3,
            'start_position' => 0,
            'end_position' => 10,
            'metadata' => ['article_no' => '5'],
        ];
        if ($linked) {
            $payload += FileLink::payload(['owner' => $owner, 'ancestors' => $owner === null ? [] : ['union:14']], $content);
        }

        return ['id' => "p{$fileId}-{$index}", 'score' => $score, 'payload' => $payload];
    }

    /** @test */
    public function the_link_is_stored_as_top_level_payload_keys_beside_the_metadata(): void
    {
        $stored = null;
        $vectors = $this->vectors();
        $vectors->shouldReceive('upsert')->once()->andReturnUsing(function ($collection, $points) use (&$stored) {
            $stored = $points;
            return true;
        });

        $link = FileLink::payload(['owner' => 'unit:309', 'ancestors' => ['union:14']], 'Article 5');
        $this->store($vectors)->storeChunks([$this->chunk(7, 0, 'Article 5', $link)]);

        $payload = $stored[0]['payload'];
        $this->assertSame('unit:309', $payload['owner']);
        $this->assertSame(['union:14'], $payload['ancestors']);
        $this->assertSame($link['dedupe'], $payload['dedupe']);
        $this->assertArrayNotHasKey('link', $payload['metadata']);
    }

    /** @test */
    public function a_chunk_without_a_link_gets_no_owner_keys(): void
    {
        $stored = null;
        $vectors = $this->vectors();
        $vectors->shouldReceive('upsert')->once()->andReturnUsing(function ($collection, $points) use (&$stored) {
            $stored = $points;
            return true;
        });

        $this->store($vectors)->storeChunks([$this->chunk(7, 0, 'Article 5')], 'documentation_chunks');

        $this->assertSame([], array_intersect(['owner', 'ancestors', 'dedupe'], array_keys($stored[0]['payload'])));
    }

    /** @test */
    public function a_search_result_carries_the_link_of_its_point(): void
    {
        $vectors = $this->vectors();
        $vectors->shouldReceive('search')->once()->andReturn([$this->point(7, 0, 'Article 5', 'unit:309')]);

        $results = $this->store($vectors)->searchByContent('destination', 5);

        $this->assertSame(
            FileLink::payload(['owner' => 'unit:309', 'ancestors' => ['union:14']], 'Article 5'),
            FileLink::ofChunk($results[0]['chunk'])
        );
        $this->assertSame('5', $results[0]['chunk']->metadata['article_no']);
    }

    /** @test */
    public function a_scope_narrows_the_search_to_the_focus_its_ancestors_and_ownerless_chunks(): void
    {
        $captured = null;
        $vectors = $this->vectors();
        $vectors->shouldReceive('search')->once()->andReturnUsing(function ($collection, $vector, $limit, $filter) use (&$captured) {
            $captured = $filter;
            return [];
        });

        $this->store($vectors)->searchByContent('pets', 10, [
            'file_ids' => [7],
            'scope' => ['focus' => 'unit:310', 'ancestors' => ['union:14']],
        ]);

        $this->assertSame([
            'must' => [
                // The ACL stays, beside the scope — never replaced by it.
                ['should' => [
                    ['key' => 'file_id', 'match' => ['any' => [7]]],
                    ['key' => 'file_id', 'match' => ['any' => ['7']]],
                ]],
                ['should' => [
                    // its own document, or a document of a record that owns it …
                    ['key' => 'owner', 'match' => ['any' => ['unit:310', 'union:14']]],
                    // … or a document filed on a record it owns …
                    ['key' => 'ancestors', 'match' => ['value' => 'unit:310']],
                    // … or a document of no record at all.
                    ['is_empty' => ['key' => 'owner']],
                ]],
            ],
            'must_not' => self::OUT_OF_SCOPE,
        ], $captured);
    }

    /** @test */
    public function no_scope_means_no_scope_condition(): void
    {
        $captured = null;
        $vectors = $this->vectors();
        $vectors->shouldReceive('search')->once()->andReturnUsing(function ($collection, $vector, $limit, $filter) use (&$captured) {
            $captured = $filter;
            return [];
        });

        $this->store($vectors)->searchByContent('pets', 10, ['scope' => null]);

        $this->assertSame(['must_not' => self::OUT_OF_SCOPE], $captured);
    }

    /** @test */
    public function distinct_search_groups_inside_the_vector_search_and_returns_the_lowest_file_id_of_each_group(): void
    {
        $five = FileLink::dedupeKey('Article 5', 'unit:309');
        $six = FileLink::dedupeKey('Article 6', 'unit:309');

        $vectors = $this->vectors(GroupedSearchInterface::class);
        // Every point of the collection carries the key: grouping is safe.
        $vectors->shouldReceive('count')->once()
            ->with('file_chunks', ['must' => [['is_empty' => ['key' => 'dedupe']]]])
            ->andReturn(0);
        // The plain search holds ONE passage twice (copies share a vector) …
        $vectors->shouldReceive('search')->once()->andReturn([
            $this->point(21, 4, 'Article 5', 'unit:309', 0.9),
            $this->point(22, 4, 'Article 5', 'unit:309', 0.9),
        ]);
        // … so the search is made again, grouped by the key.
        $vectors->shouldReceive('searchGroups')->once()
            ->withArgs(fn ($collection, $vector, $groupBy, $limit) => $collection === 'file_chunks' && $groupBy === 'dedupe' && $limit === 2)
            ->andReturn([
                // The vector search returns ANY copy of a group (identical vectors tie).
                ['key' => $five, 'hits' => [$this->point(21, 4, 'Article 5', 'unit:309', 0.9)]],
                ['key' => $six, 'hits' => [$this->point(22, 5, 'Article 6', 'unit:309', 0.8)]],
            ]);
        $representativesFilter = null;
        $vectors->shouldReceive('scrollPage')->once()->andReturnUsing(function ($collection, $filter, $limit, $withPayload) use (&$representativesFilter, $five, $six) {
            $representativesFilter = [$filter, $withPayload];
            $points = [];
            foreach ([21, 20, 22] as $fileId) {
                foreach ([[4, 'Article 5'], [5, 'Article 6']] as [$index, $text]) {
                    $point = $this->point($fileId, $index, $text, 'unit:309');
                    unset($point['payload']['content']);
                    $points[] = $point;
                }
            }

            return ['points' => $points, 'next' => null];
        });

        $results = $this->store($vectors)->searchByContent('destination', 2, ['file_ids' => [20, 21, 22], 'distinct' => true]);

        $this->assertSame([[20, 4, 'Article 5', 2], [20, 5, 'Article 6', 2]], array_map(
            static fn (array $r): array => [$r['chunk']->fileId, $r['chunk']->chunkIndex, $r['chunk']->content, $r['duplicates']],
            $results
        ));
        $this->assertSame([0.9, 0.8], array_column($results, 'score'));
        // The copies are looked for under the SAME filter as the search (ACL included).
        $this->assertSame(['key' => 'dedupe', 'match' => ['any' => [$five, $six]]], end($representativesFilter[0]['must']));
        $this->assertSame(['key' => 'file_id', 'match' => ['any' => [20, 21, 22]]], $representativesFilter[0]['must'][0]['should'][0]);
        $this->assertSame(['exclude' => ['content']], $representativesFilter[1]);
    }

    /** @test */
    public function identical_text_under_different_owners_stays_two_candidates(): void
    {
        $a = FileLink::dedupeKey('Article 5', 'unit:309');
        $b = FileLink::dedupeKey('Article 5', 'unit:310');
        $this->assertNotSame($a, $b);

        // Two different keys: the plain search already holds distinct passages,
        // nothing is grouped and nothing more is asked of the store.
        $vectors = $this->vectors(GroupedSearchInterface::class);
        $vectors->shouldReceive('search')->once()->andReturn([
            $this->point(20, 4, 'Article 5', 'unit:309'),
            $this->point(30, 4, 'Article 5', 'unit:310'),
        ]);
        $vectors->shouldNotReceive('searchGroups');
        $vectors->shouldNotReceive('scrollPage');
        $vectors->shouldNotReceive('count');

        $results = $this->store($vectors)->searchByContent('destination', 5, ['distinct' => true]);

        $this->assertSame([20, 30], array_map(static fn (array $r) => $r['chunk']->fileId, $results));
        $this->assertSame([0, 0], array_column($results, 'duplicates'));
    }

    /** @test */
    public function the_representatives_are_looked_up_once_per_turn_memo(): void
    {
        $five = FileLink::dedupeKey('Article 5', 'unit:309');
        $vectors = $this->vectors(GroupedSearchInterface::class);
        $vectors->shouldReceive('count')->andReturn(0);
        // The plain probe is paid ONCE per turn: it showed that this search holds
        // copies, so the second angle goes straight to the grouped search
        // (measured live: 10 wasted plain searches of ~25 ms per document turn).
        $vectors->shouldReceive('search')->once()->andReturn([
            $this->point(21, 4, 'Article 5', 'unit:309'),
            $this->point(20, 4, 'Article 5', 'unit:309'),
        ]);
        $vectors->shouldReceive('searchGroups')->twice()->andReturn([
            ['key' => $five, 'hits' => [$this->point(21, 4, 'Article 5', 'unit:309')]],
        ]);
        $vectors->shouldReceive('scrollPage')->once()->andReturn(['points' => [
            $this->point(20, 4, 'Article 5', 'unit:309'),
            $this->point(21, 4, 'Article 5', 'unit:309'),
        ], 'next' => null]);

        $store = $this->store($vectors);
        $memo = new \ArrayObject();
        $first = $store->searchByContent('angle one', 5, ['distinct' => true, 'memo' => $memo]);
        $second = $store->searchByContent('angle two', 5, ['distinct' => true, 'memo' => $memo]);

        $this->assertSame(20, $first[0]['chunk']->fileId);
        $this->assertSame(20, $second[0]['chunk']->fileId);
    }

    /** @test */
    public function without_a_turn_memo_every_search_probes_plainly_first(): void
    {
        $five = FileLink::dedupeKey('Article 5', 'unit:309');
        $vectors = $this->vectors(GroupedSearchInterface::class);
        $vectors->shouldReceive('count')->andReturn(0);
        // Nothing remembers what an earlier search saw: the library without copies
        // must keep paying one plain search, never a grouped one.
        $vectors->shouldReceive('search')->twice()->andReturn([
            $this->point(21, 4, 'Article 5', 'unit:309'),
            $this->point(20, 4, 'Article 5', 'unit:309'),
        ]);
        $vectors->shouldReceive('searchGroups')->twice()->andReturn([
            ['key' => $five, 'hits' => [$this->point(21, 4, 'Article 5', 'unit:309')]],
        ]);
        $vectors->shouldReceive('scrollPage')->twice()->andReturn(['points' => [
            $this->point(20, 4, 'Article 5', 'unit:309'),
        ], 'next' => null]);

        $store = $this->store($vectors);
        $store->searchByContent('angle one', 5, ['distinct' => true]);
        $store->searchByContent('angle two', 5, ['distinct' => true]);
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function a_turn_whose_first_search_held_no_copies_keeps_probing_plainly(): void
    {
        $vectors = $this->vectors(GroupedSearchInterface::class);
        $vectors->shouldReceive('search')->twice()->andReturn([
            $this->point(20, 4, 'Article 5', 'unit:309'),
            $this->point(20, 5, 'Article 6', 'unit:309'),
        ]);
        $vectors->shouldNotReceive('searchGroups');
        $vectors->shouldNotReceive('scrollPage');

        $store = $this->store($vectors);
        $memo = new \ArrayObject();
        $store->searchByContent('angle one', 5, ['distinct' => true, 'memo' => $memo]);
        $results = $store->searchByContent('angle two', 5, ['distinct' => true, 'memo' => $memo]);

        $this->assertCount(2, $results);
    }

    /** @test */
    public function a_collection_with_unlinked_points_is_searched_plainly_and_collapsed_afterwards(): void
    {
        $vectors = $this->vectors(GroupedSearchInterface::class);
        // Some points were stored before the keys existed: a grouped search would
        // silently drop them, so the search over-fetches and collapses by text.
        $vectors->shouldReceive('count')->andReturn(3);
        $vectors->shouldNotReceive('searchGroups');
        $limits = [];
        $vectors->shouldReceive('search')->twice()->andReturnUsing(function ($collection, $vector, $limit) use (&$limits) {
            $limits[] = $limit;

            return [
                $this->point(22, 4, 'Article 5', null, 0.9, false),
                $this->point(20, 4, "Article  5\n", null, 0.9, false),
                $this->point(21, 4, 'Article 5', null, 0.9, false),
                $this->point(20, 5, 'Article 6', null, 0.7, false),
            ];
        });

        $results = $this->store($vectors)->searchByContent('destination', 2, ['distinct' => true]);

        $this->assertSame(2, $limits[0], 'the plain search, as asked');
        $this->assertGreaterThan(2, $limits[1], 'then over-fetched: the copies must not use up the limit');
        $this->assertSame([[20, 4, 2], [20, 5, 0]], array_map(
            static fn (array $r): array => [$r['chunk']->fileId, $r['chunk']->chunkIndex, $r['duplicates']],
            $results
        ));
    }

    /** @test */
    public function linking_a_file_writes_the_three_keys_as_payload_only(): void
    {
        $vectors = $this->vectors(UpdatesPayloadInterface::class);
        $vectors->shouldReceive('count')->andReturn(2);
        $vectors->shouldReceive('search')->andReturn([
            $this->point(7, 0, 'Article 5', null, 0.0, false),
            // Already linked to the same owner: nothing to write for it.
            $this->point(7, 1, 'Article 6', 'unit:309'),
        ]);
        $vectors->shouldNotReceive('upsert');
        $vectors->shouldNotReceive('deletePoints');
        $writes = [];
        $vectors->shouldReceive('setPayload')->once()->andReturnUsing(function ($collection, $payload, $ids) use (&$writes) {
            $writes[] = [$collection, $payload, $ids];
            return true;
        });

        $result = $this->store($vectors)->linkFileChunks(7, ['owner' => 'unit:309', 'ancestors' => ['union:14']]);

        $this->assertSame(['points' => 2, 'changed' => 1], $result);
        $this->assertSame('file_chunks', $writes[0][0]);
        $this->assertSame(FileLink::payload(['owner' => 'unit:309', 'ancestors' => ['union:14']], 'Article 5'), $writes[0][1]);
        $this->assertSame([$this->chunk(7, 0, 'Article 5')->getVectorId()], $writes[0][2]);
    }

    /** @test */
    public function a_dry_run_reports_without_writing(): void
    {
        $vectors = $this->vectors(UpdatesPayloadInterface::class);
        $vectors->shouldReceive('count')->andReturn(1);
        $vectors->shouldReceive('search')->andReturn([$this->point(7, 0, 'Article 5', null, 0.0, false)]);
        $vectors->shouldNotReceive('setPayload');

        $this->assertSame(
            ['points' => 1, 'changed' => 1],
            $this->store($vectors)->linkFileChunks(7, ['owner' => null, 'ancestors' => []], null, true)
        );
    }
}
