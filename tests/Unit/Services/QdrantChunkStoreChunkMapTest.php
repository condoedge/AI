<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ChunkMapStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\UpdatesPayloadInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\QdrantChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * The document map of a chunk: written onto the chunk's existing point as an
 * additive top-level `map` payload key (setChunkMap) and read back as
 * `$chunk->metadata['map']` on every path that builds a FileChunk from a point.
 * A chunk that was never mapped reads exactly as before.
 */
class QdrantChunkStoreChunkMapTest extends TestCase
{
    private const MAP = ['v' => 1, 'hash' => 'h1', 'gist' => 'g', 'kind' => 'k', 'scope' => 'out', 'part' => null, 'built_at' => 1700000000];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function embedder(): EmbeddingProviderInterface
    {
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embed')->andReturn(array_fill(0, 4, 0.1));

        return $embedder;
    }

    /** A vector store that can also update payloads in place. */
    private function updatingVectors(): MockInterface
    {
        $vectors = Mockery::mock(implode(', ', [VectorStoreInterface::class, UpdatesPayloadInterface::class]));
        $vectors->shouldReceive('collectionExists')->andReturn(true);
        $vectors->shouldReceive('ensurePayloadIndex')->andReturn(true);

        return $vectors;
    }

    /** A vector store without the payload-update capability. */
    private function plainVectors(): MockInterface
    {
        $vectors = Mockery::mock(VectorStoreInterface::class);
        $vectors->shouldReceive('collectionExists')->andReturn(true);
        $vectors->shouldReceive('ensurePayloadIndex')->andReturn(true);

        return $vectors;
    }

    private function store(MockInterface $vectors): QdrantChunkStore
    {
        return new QdrantChunkStore($vectors, $this->embedder(), 'file_chunks', 4);
    }

    private function chunk(int $index = 3): FileChunk
    {
        return new FileChunk(
            fileId: 42,
            fileName: 'rules.pdf',
            content: 'content',
            embedding: [],
            chunkIndex: $index,
            totalChunks: 9,
            startPosition: 0,
            endPosition: 7
        );
    }

    /** @return array<string,mixed> A stored point payload, optionally with extra top-level keys. */
    private function payload(array $extra = [], array $metadata = ['page' => 2]): array
    {
        return [
            'file_id' => 42,
            'file_name' => 'rules.pdf',
            'content' => 'content',
            'chunk_index' => 3,
            'total_chunks' => 9,
            'start_position' => 0,
            'end_position' => 7,
            'metadata' => $metadata,
        ] + $extra;
    }

    // ---------------------------------------------------------------------
    // setChunkMap()
    // ---------------------------------------------------------------------

    /** @test */
    public function the_store_advertises_the_chunk_map_capability(): void
    {
        $this->assertInstanceOf(ChunkMapStoreInterface::class, $this->store($this->plainVectors()));
    }

    /** @test */
    public function it_writes_the_map_under_a_top_level_key_on_the_chunks_point(): void
    {
        $chunk = $this->chunk();
        $vectors = $this->updatingVectors();
        $vectors->shouldReceive('setPayload')
            ->once()
            ->with('file_chunks', ['map' => self::MAP], [$chunk->getVectorId()])
            ->andReturn(true);

        $this->assertTrue($this->store($vectors)->setChunkMap($chunk, self::MAP));
    }

    /** @test */
    public function it_writes_to_the_collection_override_when_one_is_given(): void
    {
        $chunk = $this->chunk();
        $vectors = $this->updatingVectors();
        $vectors->shouldReceive('setPayload')
            ->once()
            ->with('documentation_chunks', ['map' => self::MAP], [$chunk->getVectorId()])
            ->andReturn(true);

        $this->assertTrue($this->store($vectors)->setChunkMap($chunk, self::MAP, 'documentation_chunks'));
    }

    /** @test */
    public function it_reports_false_when_the_vector_store_cannot_update_payloads(): void
    {
        $this->assertFalse($this->store($this->plainVectors())->setChunkMap($this->chunk(), self::MAP));
    }

    /** @test */
    public function it_reports_false_when_the_write_is_not_confirmed(): void
    {
        $vectors = $this->updatingVectors();
        $vectors->shouldReceive('setPayload')->once()->andReturn(false);

        $this->assertFalse($this->store($vectors)->setChunkMap($this->chunk(), self::MAP));
    }

    /** @test */
    public function it_reports_false_instead_of_throwing_when_the_write_fails(): void
    {
        $vectors = $this->updatingVectors();
        $vectors->shouldReceive('setPayload')->once()->andThrow(new \RuntimeException('Qdrant returned error 404'));

        $this->assertFalse($this->store($vectors)->setChunkMap($this->chunk(), self::MAP));
    }

    // ---------------------------------------------------------------------
    // storeChunks(): a record carried over a re-ingest
    // ---------------------------------------------------------------------

    /** @test */
    public function a_chunk_stored_with_a_map_gets_it_under_the_same_top_level_key_it_is_read_from(): void
    {
        $record = ['v' => 1, 'hash' => 'h1', 'gist' => 'g', 'scope' => 'out'];
        $mapped = new FileChunk(42, 'rules.pdf', 'content', array_fill(0, 4, 0.1), 3, 9, 0, 7, ['page' => 2, 'map' => $record]);
        $plain = new FileChunk(42, 'rules.pdf', 'other', array_fill(0, 4, 0.1), 4, 9, 7, 12, ['page' => 2]);

        $stored = null;
        $vectors = $this->plainVectors();
        $vectors->shouldReceive('upsert')->once()->andReturnUsing(function (string $collection, array $points) use (&$stored) {
            $stored = $points;

            return true;
        });

        $this->assertTrue($this->store($vectors)->storeChunks([$mapped, $plain]));

        $this->assertSame($record, $stored[0]['payload']['map'], 'where the scope filter and every reader look for it');
        $this->assertSame(['page' => 2], $stored[0]['payload']['metadata'], 'not a second copy inside the metadata');
        $this->assertArrayNotHasKey('map', $stored[1]['payload'], 'a chunk without a record is stored as before');
        $this->assertSame(['page' => 2], $stored[1]['payload']['metadata']);
    }

    // ---------------------------------------------------------------------
    // Hydration
    // ---------------------------------------------------------------------

    /** @test */
    public function content_search_exposes_the_map_and_keeps_the_stored_metadata(): void
    {
        $vectors = $this->plainVectors();
        $vectors->shouldReceive('search')->once()->andReturn([
            ['score' => 0.9, 'payload' => $this->payload(['map' => self::MAP])],
        ]);

        $results = $this->store($vectors)->searchByContent('pets', 10);

        $this->assertSame(['page' => 2, 'map' => self::MAP], $results[0]['chunk']->metadata);
    }

    /** @test */
    public function content_search_leaves_an_unmapped_chunk_unchanged(): void
    {
        $vectors = $this->plainVectors();
        $vectors->shouldReceive('search')->once()->andReturn([
            ['score' => 0.9, 'payload' => $this->payload()],
        ]);

        $results = $this->store($vectors)->searchByContent('pets', 10);

        $this->assertSame(['page' => 2], $results[0]['chunk']->metadata);
    }

    /** @test */
    public function a_map_that_is_not_an_array_is_ignored(): void
    {
        $vectors = $this->plainVectors();
        $vectors->shouldReceive('search')->once()->andReturn([
            ['score' => 0.9, 'payload' => $this->payload(['map' => 'corrupt'])],
        ]);

        $results = $this->store($vectors)->searchByContent('pets', 10);

        $this->assertSame(['page' => 2], $results[0]['chunk']->metadata);
    }

    /** @test */
    public function file_chunks_expose_the_map_per_chunk(): void
    {
        $vectors = $this->plainVectors();
        $vectors->shouldReceive('count')->once()->andReturn(2);
        $vectors->shouldReceive('search')->once()->andReturn([
            ['payload' => ['chunk_index' => 4] + $this->payload()],
            ['payload' => $this->payload(['map' => self::MAP])],
        ]);

        $chunks = $this->store($vectors)->getFileChunks(42);

        $this->assertSame(3, $chunks[0]->chunkIndex);
        $this->assertSame(['page' => 2, 'map' => self::MAP], $chunks[0]->metadata);
        $this->assertSame(4, $chunks[1]->chunkIndex);
        $this->assertSame(['page' => 2], $chunks[1]->metadata);
    }

    /** @test */
    public function filename_search_exposes_the_map(): void
    {
        $vectors = $this->plainVectors();
        $vectors->shouldReceive('count')->once()->andReturn(1);
        $vectors->shouldReceive('search')->once()->andReturn([
            ['payload' => $this->payload(['map' => self::MAP])],
        ]);

        $results = $this->store($vectors)->searchByFilename('rules.pdf', 10);

        $this->assertSame(['page' => 2, 'map' => self::MAP], $results[0]['chunk']->metadata);
    }

    /** @test */
    public function filename_scroll_fallback_exposes_the_map(): void
    {
        $vectors = $this->plainVectors();
        $vectors->shouldReceive('count')->once()->andThrow(new \RuntimeException('no text index'));
        $vectors->shouldReceive('scroll')->once()->andReturn([
            ['payload' => $this->payload(['map' => self::MAP])],
        ]);

        $results = $this->store($vectors)->searchByFilename('rules.pdf', 10);

        $this->assertSame(['page' => 2, 'map' => self::MAP], $results[0]['chunk']->metadata);
    }

    /** @test */
    public function a_single_chunk_lookup_exposes_the_map(): void
    {
        $vectors = $this->plainVectors();
        $vectors->shouldReceive('getPoint')->once()->andReturn([
            'payload' => $this->payload(['map' => self::MAP], []),
        ]);

        $chunk = $this->store($vectors)->getChunk('some-id');

        $this->assertSame(['map' => self::MAP], $chunk->metadata);
    }

    // -------------------------------------------- lookup by the text's hash

    /** @test */
    public function stored_maps_are_looked_up_by_text_hash_reading_the_map_key_only(): void
    {
        $vectors = Mockery::mock(implode(', ', [VectorStoreInterface::class, \Condoedge\Ai\Contracts\GroupedSearchInterface::class]));
        $calls = [];
        $vectors->shouldReceive('scrollPage')->andReturnUsing(function ($collection, $filter, $limit, $withPayload, $offset = null) use (&$calls) {
            $calls[] = [$collection, $filter, $withPayload];
            if (count($calls) === 1) {
                return ['points' => [
                    ['id' => 'a', 'payload' => ['map' => ['v' => 1, 'hash' => 'h1', 'gist' => 'one']]],
                    ['id' => 'b', 'payload' => ['map' => ['v' => 1, 'hash' => 'h1', 'gist' => 'one']]],
                    ['id' => 'c', 'payload' => ['map' => 'not a record']],
                ], 'next' => 'c'];
            }

            return ['points' => [
                ['id' => 'd', 'payload' => ['map' => ['v' => 1, 'hash' => 'h2', 'failed' => 'rejected', 'attempts' => 1]]],
            ], 'next' => null];
        });

        $found = $this->store($vectors)->findChunkMaps(['h1', 'h2', 'h3'], 'file_chunks');

        $this->assertSame(['h1', 'h2'], array_keys($found));
        $this->assertSame('one', $found['h1'][0]['gist']);
        $this->assertSame('rejected', $found['h2'][0]['failed']);
        $this->assertCount(2, $calls, 'paged until nothing is left to find');
        $this->assertSame('file_chunks', $calls[0][0]);
        $this->assertSame(['must' => [['key' => 'map.hash', 'match' => ['any' => ['h1', 'h2', 'h3']]]]], $calls[0][1]);
        $this->assertSame(['include' => ['map']], $calls[0][2], 'neither the text nor any other key leaves the store');
        // The texts whose GIST was found are not asked for again: 36 copies of a
        // document are not read 36 times over.
        $this->assertSame(['must' => [['key' => 'map.hash', 'match' => ['any' => ['h2', 'h3']]]]], $calls[1][1]);
    }

    /** @test */
    public function a_lookup_of_nothing_or_on_a_store_that_cannot_page_finds_nothing(): void
    {
        $vectors = $this->plainVectors();
        $vectors->shouldNotReceive('scroll');

        $this->assertSame([], $this->store($vectors)->findChunkMaps(['h1']));
        $this->assertSame([], $this->store($vectors)->findChunkMaps([]));
    }

    /** @test */
    public function a_single_chunk_lookup_leaves_an_unmapped_chunk_unchanged(): void
    {
        $vectors = $this->plainVectors();
        $vectors->shouldReceive('getPoint')->once()->andReturn(['payload' => $this->payload()]);

        $this->assertSame(['page' => 2], $this->store($vectors)->getChunk('some-id')->metadata);
    }
}
