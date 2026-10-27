<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\QdrantChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The first ingest into a new collection: a collection that does not exist yet holds no
 * chunks (the store creates it with the first chunks), it is not an error.
 */
class QdrantChunkStoreFirstIngestTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function a_missing_collection_holds_no_chunks(): void
    {
        $vectors = Mockery::mock(VectorStoreInterface::class);
        $vectors->shouldReceive('collectionExists')->with('help_articles')->andReturn(false);
        $vectors->shouldNotReceive('count');

        $store = new QdrantChunkStore($vectors, Mockery::mock(EmbeddingProviderInterface::class), 'file_chunks', 4);

        $this->assertFalse($store->hasFileChunks('physical:aide/a.md', 'help_articles'));
    }

    /** @test */
    public function an_existing_collection_is_counted_as_before(): void
    {
        $vectors = Mockery::mock(VectorStoreInterface::class);
        $vectors->shouldReceive('collectionExists')->andReturn(true);
        $vectors->shouldReceive('count')->with('help_articles', ['file_id' => 'physical:aide/a.md'])->andReturn(3);

        $store = new QdrantChunkStore($vectors, Mockery::mock(EmbeddingProviderInterface::class), 'file_chunks', 4);

        $this->assertTrue($store->hasFileChunks('physical:aide/a.md', 'help_articles'));
    }
}
