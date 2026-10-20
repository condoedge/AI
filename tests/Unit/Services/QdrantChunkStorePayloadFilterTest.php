<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\QdrantChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Phase 7.2 / 7.3 — the chunk store translates the structural retrieval filters
 * into Qdrant payload conditions: the ACL file-id set (MatchAny; integer and
 * string ids are separate Qdrant value types, so both forms are matched) and the
 * exact-article numbers (MatchAny on metadata.articles).
 */
class QdrantChunkStorePayloadFilterTest extends TestCase
{
    private function storeCapturing(?array &$captured): QdrantChunkStore
    {
        $vectors = Mockery::mock(VectorStoreInterface::class);
        $vectors->shouldReceive('collectionExists')->andReturn(true);
        $vectors->shouldReceive('ensurePayloadIndex')->andReturn(true);
        $vectors->shouldReceive('search')->once()->andReturnUsing(function ($collection, $vector, $limit, $filter) use (&$captured) {
            $captured = $filter;
            return [];
        });

        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embed')->andReturn(array_fill(0, 4, 0.1));

        return new QdrantChunkStore($vectors, $embedder, 'file_chunks', 4);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function the_acl_id_set_becomes_a_match_any_on_file_id_for_both_id_types(): void
    {
        $captured = null;
        // include_out_of_scope: keep this test about the ACL condition alone (the
        // document-map scope clause is covered by QdrantChunkStoreScopeFilterTest).
        $this->storeCapturing($captured)->searchByContent('pets', 10, ['file_ids' => [7, '12'], 'include_out_of_scope' => true]);

        $this->assertSame(['must' => [[
            'should' => [
                ['key' => 'file_id', 'match' => ['any' => [7, 12]]],
                ['key' => 'file_id', 'match' => ['any' => ['7', '12']]],
            ],
        ]]], $captured);
    }

    /** @test */
    public function the_article_numbers_become_a_match_any_on_metadata_articles(): void
    {
        $captured = null;
        $this->storeCapturing($captured)->searchByContent('art 1069', 10, ['article_numbers' => ['1069', '1070.1']]);

        $this->assertSame(['must' => [
            ['key' => 'metadata.articles', 'match' => ['any' => ['1069', '1070.1']]],
        ]], $captured);
    }
}
