<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\QdrantChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The `audiences` filter keeps the chunks written for one of the asker's audiences and
 * the chunks written for everyone (no audience); without it nothing changes.
 */
class QdrantChunkStoreAudienceFilterTest extends TestCase
{
    private const CLAUSE = ['should' => [
        ['key' => 'metadata.audience', 'match' => ['any' => ['staff', 'buyer']]],
        ['is_empty' => ['key' => 'metadata.audience']],
    ]];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function store(array &$captured, array $scrolled = []): QdrantChunkStore
    {
        $vectors = Mockery::mock(VectorStoreInterface::class);
        $vectors->shouldReceive('collectionExists')->andReturn(true);
        $vectors->shouldReceive('ensurePayloadIndex')->andReturn(true);
        $vectors->shouldReceive('count')->andReturnUsing(function ($collection, $filter) use (&$captured) {
            $captured['count'] = $filter;

            return 1;
        });
        $vectors->shouldReceive('search')->andReturnUsing(function ($collection, $vector, $limit, $filter) use (&$captured) {
            $captured['search'] = $filter;

            return [];
        });
        $vectors->shouldReceive('scroll')->andReturn($scrolled);
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embed')->andReturn(array_fill(0, 4, 0.1));

        return new QdrantChunkStore($vectors, $embedder, 'documentation_chunks', 4);
    }

    /** @test */
    public function a_content_search_keeps_the_askers_audiences_and_the_common_documents(): void
    {
        $captured = [];
        $this->store($captured)->searchByContent('refund', 10, ['audiences' => ['staff', 'buyer']]);

        $this->assertContains(self::CLAUSE, $captured['search']['must']);
    }

    /** @test */
    public function no_audience_reads_only_the_common_documents(): void
    {
        $captured = [];
        $this->store($captured)->searchByContent('refund', 10, ['audiences' => []]);

        $this->assertContains(['is_empty' => ['key' => 'metadata.audience']], $captured['search']['must']);
    }

    /** @test */
    public function a_content_search_can_be_restricted_to_articles_by_slug(): void
    {
        $captured = [];
        $this->store($captured)->searchByContent('refund', 10, ['slugs' => ['rooms', 'catering']]);

        $this->assertContains(['key' => 'metadata.slug', 'match' => ['any' => ['rooms', 'catering']]], $captured['search']['must']);
    }

    /** @test */
    public function without_the_filter_the_search_is_unchanged(): void
    {
        $captured = [];
        $this->store($captured)->searchByContent('refund', 10, []);

        $this->assertArrayNotHasKey('must', $captured['search']);
    }

    /** @test */
    public function the_filename_fallback_applies_the_same_rule(): void
    {
        $point = fn (string $name, ?string $audience) => ['id' => $name, 'score' => 1.0, 'payload' => [
            'file_id' => 'physical:' . $name, 'file_name' => $name, 'content' => 'x', 'chunk_index' => 0, 'total_chunks' => 1,
            'start_position' => 0, 'end_position' => 1, 'metadata' => $audience !== null ? ['audience' => [$audience]] : [],
        ]];
        $vectors = Mockery::mock(VectorStoreInterface::class);
        $vectors->shouldReceive('collectionExists')->andReturn(true);
        $vectors->shouldReceive('ensurePayloadIndex')->andReturn(true);
        $vectors->shouldReceive('count')->andThrow(new \RuntimeException('no file_name index'));
        $vectors->shouldReceive('scroll')->andReturn([
            $point('billets-staff.md', 'staff'), $point('billets-participant.md', 'participant'), $point('billets-common.md', null),
        ]);
        $store = new QdrantChunkStore($vectors, Mockery::mock(EmbeddingProviderInterface::class), 'documentation_chunks', 4);

        $names = array_map(fn ($r) => $r['chunk']->fileName, $store->searchByFilename('billets', 10, ['audiences' => ['staff']]));

        sort($names);
        $this->assertSame(['billets-common.md', 'billets-staff.md'], $names);
    }

    /** @test */
    public function a_filename_search_is_filtered_too(): void
    {
        $captured = [];
        $this->store($captured)->searchByFilename('billets.md', 10, ['audiences' => ['staff', 'buyer']]);

        $this->assertContains(self::CLAUSE, $captured['search']['must']);
    }
}
