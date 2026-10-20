<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\QdrantChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * Document search leaves out the chunks a document map marked `scope = out`
 * (a part of the document that does not govern the application's users), unless
 * the caller names an article by number, opts in explicitly, or the document map
 * is switched off. Lookups of one file's own chunks (windows, deletes, the map
 * builder itself) and inventory counts are never narrowed.
 */
class QdrantChunkStoreScopeFilterTest extends TestCase
{
    private const OUT_OF_SCOPE = [['key' => 'map.scope', 'match' => ['value' => 'out']]];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function vectors(): MockInterface
    {
        $vectors = Mockery::mock(VectorStoreInterface::class);
        $vectors->shouldReceive('collectionExists')->andReturn(true);
        $vectors->shouldReceive('ensurePayloadIndex')->andReturn(true);

        return $vectors;
    }

    private function store(MockInterface $vectors): QdrantChunkStore
    {
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embed')->andReturn(array_fill(0, 4, 0.1));

        return new QdrantChunkStore($vectors, $embedder, 'file_chunks', 4);
    }

    /** The filter a content search hands to the vector store for the given filters. */
    private function contentSearchFilter(array $filters): array
    {
        $captured = null;
        $vectors = $this->vectors();
        $vectors->shouldReceive('search')->once()->andReturnUsing(function ($collection, $vector, $limit, $filter) use (&$captured) {
            $captured = $filter;
            return [];
        });

        $this->store($vectors)->searchByContent('pets', 10, $filters);

        return $captured;
    }

    /** @test */
    public function content_search_excludes_out_of_scope_chunks_by_default(): void
    {
        $this->assertSame(['must_not' => self::OUT_OF_SCOPE], $this->contentSearchFilter([]));
    }

    /** @test */
    public function the_exclusion_is_combined_with_the_acl_file_id_filter(): void
    {
        $this->assertSame([
            'must' => [[
                'should' => [
                    ['key' => 'file_id', 'match' => ['any' => [7, 12]]],
                    ['key' => 'file_id', 'match' => ['any' => ['7', '12']]],
                ],
            ]],
            'must_not' => self::OUT_OF_SCOPE,
        ], $this->contentSearchFilter(['file_ids' => [7, '12']]));
    }

    /** @test */
    public function the_exclusion_is_combined_with_a_single_file_filter(): void
    {
        $this->assertSame([
            'must' => [['key' => 'file_id', 'match' => ['value' => 42]]],
            'must_not' => self::OUT_OF_SCOPE,
        ], $this->contentSearchFilter(['file_id' => 42]));
    }

    /** @test */
    public function an_article_named_by_number_is_returned_regardless_of_scope(): void
    {
        $this->assertSame([
            'must' => [['key' => 'metadata.articles', 'match' => ['any' => ['1069']]]],
        ], $this->contentSearchFilter(['article_numbers' => ['1069']]));
    }

    /** @test */
    public function an_article_named_by_number_keeps_the_acl_filter_but_not_the_exclusion(): void
    {
        $filter = $this->contentSearchFilter(['file_ids' => [7], 'article_numbers' => ['1069']]);

        $this->assertArrayNotHasKey('must_not', $filter);
        $this->assertCount(2, $filter['must']);
    }

    /** @test */
    public function an_empty_article_list_does_not_lift_the_exclusion(): void
    {
        $this->assertSame(['must_not' => self::OUT_OF_SCOPE], $this->contentSearchFilter(['article_numbers' => []]));
    }

    /** @test */
    public function a_caller_can_opt_in_to_out_of_scope_chunks(): void
    {
        $this->assertSame([], $this->contentSearchFilter(['include_out_of_scope' => true]));
    }

    /** @test */
    public function opting_in_keeps_the_acl_filter(): void
    {
        $this->assertSame([
            'must' => [['key' => 'file_id', 'match' => ['value' => 42]]],
        ], $this->contentSearchFilter(['file_id' => 42, 'include_out_of_scope' => true]));
    }

    /** @test */
    public function a_falsy_opt_in_keeps_the_exclusion(): void
    {
        $this->assertSame(['must_not' => self::OUT_OF_SCOPE], $this->contentSearchFilter(['include_out_of_scope' => false]));
    }

    /** @test */
    public function nothing_is_excluded_when_the_document_map_is_disabled(): void
    {
        config(['ai.file_context.document_map.enabled' => false]);

        $this->assertSame([], $this->contentSearchFilter([]));
    }

    /** @test */
    public function fetching_a_files_chunks_still_sees_out_of_scope_chunks(): void
    {
        $byFile = ['must' => [['key' => 'file_id', 'match' => ['value' => 42]]]];

        $vectors = $this->vectors();
        $vectors->shouldReceive('count')->once()->with('file_chunks', $byFile)->andReturn(1);
        $vectors->shouldReceive('search')->once()
            ->with('file_chunks', Mockery::type('array'), 1, $byFile, 0.0)
            ->andReturn([]);

        $this->assertSame([], $this->store($vectors)->getFileChunks(42));
    }

    /** @test */
    public function checking_a_file_for_chunks_still_sees_out_of_scope_chunks(): void
    {
        $vectors = $this->vectors();
        $vectors->shouldReceive('count')->once()->with('file_chunks', ['file_id' => 42])->andReturn(1);

        $this->assertTrue($this->store($vectors)->hasFileChunks(42));
    }

    /** @test */
    public function the_chunk_count_still_counts_out_of_scope_chunks(): void
    {
        $vectors = $this->vectors();
        $vectors->shouldReceive('count')->once()->with('file_chunks', [])->andReturn(5);

        $this->assertSame(5, $this->store($vectors)->getChunkCount());
    }

    /** @test */
    public function the_chunk_count_for_one_file_still_counts_out_of_scope_chunks(): void
    {
        $vectors = $this->vectors();
        $vectors->shouldReceive('count')->once()
            ->with('file_chunks', ['must' => [['key' => 'file_id', 'match' => ['value' => 42]]]])
            ->andReturn(3);

        $this->assertSame(3, $this->store($vectors)->getChunkCount(['file_id' => 42]));
    }

    /** @test */
    public function filename_search_is_not_narrowed_by_scope(): void
    {
        $byName = ['must' => [['key' => 'file_name', 'match' => ['text' => 'rules.pdf']]]];

        $vectors = $this->vectors();
        $vectors->shouldReceive('count')->once()->with('file_chunks', $byName)->andReturn(0);

        $this->assertSame([], $this->store($vectors)->searchByFilename('rules.pdf', 10));
    }
}
