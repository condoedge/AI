<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\QdrantChunkStore;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Mockery;

/**
 * Tests for the filename-search fallback (Task B1).
 *
 * When the file_name full-text payload index is missing, the indexed
 * match:{text} path throws. searchByFilename must degrade to scroll + a PHP
 * case-insensitive substring match instead of propagating the exception.
 */
class QdrantChunkStoreFilenameFallbackTest extends TestCase
{
    private $mockVectorStore;
    private $mockEmbeddingProvider;
    private QdrantChunkStore $chunkStore;
    private string $testCollection = 'test_file_chunks';

    public function setUp(): void
    {
        parent::setUp();

        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockEmbeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        $this->mockVectorStore
            ->shouldReceive('collectionExists')
            ->with($this->testCollection)
            ->andReturn(true);

        $this->mockVectorStore
            ->shouldReceive('ensurePayloadIndex')
            ->byDefault()
            ->andReturn(true);

        $this->chunkStore = new QdrantChunkStore(
            $this->mockVectorStore,
            $this->mockEmbeddingProvider,
            $this->testCollection,
            1536
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_falls_back_to_scroll_when_indexed_count_throws(): void
    {
        // Simulate a missing payload index: count() throws.
        $this->mockVectorStore
            ->shouldReceive('count')
            ->andThrow(new \RuntimeException('Index required but not found for file_name'));

        // Scroll returns a mixed set; only matching file_names should survive.
        $this->mockVectorStore
            ->shouldReceive('scroll')
            ->once()
            ->andReturn([
                ['id' => 'p1', 'payload' => $this->payload(1, 'bariloche.txt', 'Trip notes')],
                ['id' => 'p2', 'payload' => $this->payload(2, 'unrelated.pdf', 'Other')],
            ]);

        $results = $this->chunkStore->searchByFilename('bariloche.txt', 10);

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['chunk']->fileId);
        $this->assertEquals('bariloche.txt', $results[0]['chunk']->fileName);
        $this->assertEquals(1.0, $results[0]['score']); // exact (case-insensitive)
        $this->assertEquals('exact', $results[0]['match_type']);
    }

    public function test_fallback_does_case_insensitive_substring_match(): void
    {
        $this->mockVectorStore
            ->shouldReceive('count')
            ->andThrow(new \RuntimeException('missing index'));

        $this->mockVectorStore
            ->shouldReceive('scroll')
            ->once()
            ->andReturn([
                ['id' => 'p1', 'payload' => $this->payload(10, 'Quarterly_Report_2024.pdf', 'Finance')],
                ['id' => 'p2', 'payload' => $this->payload(11, 'notes.md', 'Misc')],
            ]);

        // Lowercase partial term should still match the mixed-case filename.
        $results = $this->chunkStore->searchByFilename('quarterly_report', 10);

        $this->assertCount(1, $results);
        $this->assertEquals(10, $results[0]['chunk']->fileId);
        $this->assertEquals(0.85, $results[0]['score']); // partial match
        $this->assertEquals('partial', $results[0]['match_type']);
    }

    public function test_fallback_returns_empty_when_scroll_also_fails(): void
    {
        $this->mockVectorStore
            ->shouldReceive('count')
            ->andThrow(new \RuntimeException('missing index'));

        $this->mockVectorStore
            ->shouldReceive('scroll')
            ->once()
            ->andThrow(new \RuntimeException('qdrant down'));

        // Must never propagate — returns empty so callers degrade gracefully.
        $results = $this->chunkStore->searchByFilename('anything.txt', 10);

        $this->assertEmpty($results);
    }

    public function test_no_fallback_when_indexed_path_works(): void
    {
        // Indexed path works: count + search are used, scroll is never called.
        $this->mockVectorStore
            ->shouldReceive('count')
            ->once()
            ->andReturn(1);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([
                ['id' => 'p1', 'score' => 0.0, 'payload' => $this->payload(5, 'report.pdf', 'x')],
            ]);

        $this->mockVectorStore->shouldReceive('scroll')->never();

        $results = $this->chunkStore->searchByFilename('report.pdf', 10);

        $this->assertCount(1, $results);
        $this->assertEquals(5, $results[0]['chunk']->fileId);
    }

    private function payload(int $fileId, string $fileName, string $content): array
    {
        return [
            'file_id' => $fileId,
            'file_name' => $fileName,
            'content' => $content,
            'chunk_index' => 0,
            'total_chunks' => 1,
            'start_position' => 0,
            'end_position' => strlen($content),
            'metadata' => [],
        ];
    }
}
