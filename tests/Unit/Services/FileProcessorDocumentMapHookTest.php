<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Jobs\BuildDocumentMapJob;
use Condoedge\Ai\Services\Extractors\MarkdownExtractor;
use Condoedge\Ai\Services\Extractors\TextExtractor;
use Condoedge\Ai\Services\FileExtractorRegistry;
use Condoedge\Ai\Services\FileProcessor;
use Condoedge\Ai\Services\SemanticChunker;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Mockery;

/**
 * After a successful ingest the document map is built by a QUEUED job — never
 * inline (about 2.4 s per passage would block the upload) and never at the cost
 * of the ingest: whatever happens while dispatching, the ProcessingResult is the
 * one the ingest earned.
 */
class FileProcessorDocumentMapHookTest extends TestCase
{
    private string $dir;

    public function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/docmap_fp_' . uniqid();
        mkdir($this->dir);
        file_put_contents(
            $this->dir . '/guide.md',
            "# Guide\n\n## Contacts\nA contact is a person recorded in the directory of the building, with a name and an address.\n\n"
            . "## Access\nA contact may be invited to the portal by an administrator, who chooses what the contact can see.\n"
        );
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function processor(bool $stored = true): FileProcessor
    {
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embedBatch')->andReturnUsing(fn (array $texts) => array_map(fn () => [0.1, 0.2], $texts));

        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('hasFileChunks')->andReturn(false);
        $store->shouldReceive('storeChunks')->andReturn($stored);

        $registry = new FileExtractorRegistry();
        $registry->registerMany([new TextExtractor(), new MarkdownExtractor()]);

        return new FileProcessor($registry, new SemanticChunker(), $embedder, $store);
    }

    private function file(): object
    {
        return (object) ['id' => 'physical:docs/guide.md', 'name' => 'guide.md', 'path' => $this->dir . '/guide.md'];
    }

    /** @test */
    public function it_queues_the_map_of_the_file_after_a_successful_ingest(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();

        $result = $this->processor()->processFile($this->file(), ['collection' => 'documentation_chunks', 'min_chunk_chars' => 20]);

        $this->assertTrue($result->success, (string) $result->error);
        Bus::assertDispatched(
            BuildDocumentMapJob::class,
            fn (BuildDocumentMapJob $job) => $job->fileId === 'physical:docs/guide.md' && $job->collection === 'documentation_chunks'
        );
        Bus::assertDispatchedTimes(BuildDocumentMapJob::class, 1);
    }

    /** @test */
    public function an_uploaded_file_is_not_mapped_until_the_host_switches_uploads_on(): void
    {
        config(['queue.default' => 'database', 'ai.file_context.physical_collection' => 'documentation_chunks']);
        Bus::fake();

        // No collection (the store's own) and any collection that is not the host's
        // documents: files users upload.
        $this->processor()->processFile($this->file(), ['min_chunk_chars' => 20]);
        $this->processor()->processFile($this->file(), ['collection' => 'file_chunks', 'min_chunk_chars' => 20]);
        Bus::assertNotDispatched(BuildDocumentMapJob::class);

        config(['ai.file_context.document_map.uploads' => true]);
        $this->processor()->processFile($this->file(), ['min_chunk_chars' => 20]);
        Bus::assertDispatched(BuildDocumentMapJob::class, fn (BuildDocumentMapJob $job) => $job->collection === null);
    }

    /** @test */
    public function with_a_sync_queue_nothing_is_dispatched(): void
    {
        config(['queue.default' => 'sync']);
        Bus::fake();

        $result = $this->processor()->processFile($this->file(), ['collection' => 'documentation_chunks', 'min_chunk_chars' => 20]);

        $this->assertTrue($result->success, (string) $result->error);
        Bus::assertNotDispatched(BuildDocumentMapJob::class);
    }

    /** @test */
    public function a_connection_of_another_name_that_runs_inline_is_a_sync_queue_too(): void
    {
        config(['queue.default' => 'inline', 'queue.connections.inline' => ['driver' => 'sync']]);
        Bus::fake();

        $this->processor()->processFile($this->file(), ['collection' => 'documentation_chunks', 'min_chunk_chars' => 20]);

        Bus::assertNotDispatched(BuildDocumentMapJob::class);
    }

    /** @test */
    public function the_map_can_be_switched_off(): void
    {
        config(['queue.default' => 'database', 'ai.file_context.document_map.enabled' => false]);
        Bus::fake();

        $result = $this->processor()->processFile($this->file(), ['collection' => 'documentation_chunks', 'min_chunk_chars' => 20]);

        $this->assertTrue($result->success, (string) $result->error);
        Bus::assertNotDispatched(BuildDocumentMapJob::class);
    }

    /** @test */
    public function nothing_is_dispatched_when_the_chunks_were_not_stored(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();

        $result = $this->processor(stored: false)->processFile($this->file(), ['collection' => 'documentation_chunks', 'min_chunk_chars' => 20]);

        $this->assertFalse($result->success);
        Bus::assertNotDispatched(BuildDocumentMapJob::class);
    }

    /** @test */
    public function a_dispatch_that_throws_does_not_change_the_result_of_the_ingest(): void
    {
        config(['queue.default' => 'database']);
        $attempts = 0;
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function () use (&$attempts) {
            $attempts++;
            throw new \RuntimeException('queue connection refused');
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $result = $this->processor()->processFile($this->file(), ['collection' => 'documentation_chunks', 'min_chunk_chars' => 20]);

        $this->assertSame(1, $attempts, 'the dispatch was attempted');
        $this->assertTrue($result->success, (string) $result->error);
        $this->assertGreaterThan(0, $result->chunksCreated);
        $this->assertNull($result->error);
    }
}
