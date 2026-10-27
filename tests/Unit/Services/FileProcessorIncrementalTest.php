<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Extractors\MarkdownExtractor;
use Condoedge\Ai\Services\Extractors\TextExtractor;
use Condoedge\Ai\Services\FileExtractorRegistry;
use Condoedge\Ai\Services\FileProcessor;
use Condoedge\Ai\Services\SemanticChunker;
use Condoedge\Ai\Tests\Fixtures\InMemoryMapChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Mockery;

/**
 * Re-running the docs ingest (`skip_unchanged`) pays only for what changed: a file
 * whose bytes are the ones stored is skipped as a success, a changed one is replaced.
 * Chunks stored before hashes existed count as unchanged (no re-embedding on upgrade).
 */
class FileProcessorIncrementalTest extends TestCase
{
    private const FILE = 'physical:docs/guide.md';
    private const COLLECTION = 'documentation_chunks';

    private string $dir;

    private InMemoryMapChunkStore $store;

    private int $embedded = 0;

    public function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        Bus::fake();
        $this->dir = sys_get_temp_dir() . '/incremental_' . uniqid();
        mkdir($this->dir);
        $this->store = new InMemoryMapChunkStore();
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        Mockery::close();
        parent::tearDown();
    }

    private function ingest(string $markdown, array $options = ['skip_unchanged' => true]): \Condoedge\Ai\DTOs\ProcessingResult
    {
        file_put_contents($this->dir . '/guide.md', $markdown);
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embedBatch')->andReturnUsing(function (array $texts) {
            $this->embedded += count($texts);

            return array_map(fn () => [0.1, 0.2], $texts);
        });
        $registry = new FileExtractorRegistry();
        $registry->registerMany([new TextExtractor(), new MarkdownExtractor()]);

        return (new FileProcessor($registry, new SemanticChunker(), $embedder, $this->store))->processFile(
            (object) ['id' => self::FILE, 'name' => 'guide.md', 'path' => $this->dir . '/guide.md'],
            ['collection' => self::COLLECTION, 'min_chunk_chars' => 20] + $options
        );
    }

    private const V1 = "# Guide\n\n## Contacts\nA contact is a person recorded in the directory, with a name and an address.";
    private const V2 = "# Guide\n\n## Contacts\nA contact is a person recorded in the directory, with a name, an address and a phone.";

    /** @test */
    public function an_unchanged_file_is_skipped_as_a_success(): void
    {
        $this->assertTrue($this->ingest(self::V1)->success);
        $embedded = $this->embedded;
        $hash = $this->store->getFileChunks(self::FILE, self::COLLECTION)[0]->metadata['source_hash'];
        $this->assertSame(sha1(self::V1), $hash);

        $again = $this->ingest(self::V1);

        $this->assertTrue($again->success);
        $this->assertSame(0, $again->chunksCreated, 'the command counts it as skipped');
        $this->assertSame($embedded, $this->embedded, 'nothing embedded again');
    }

    /** @test */
    public function a_file_whose_line_endings_changed_is_unchanged(): void
    {
        $this->ingest(self::V1);
        $embedded = $this->embedded;

        $again = $this->ingest(str_replace("\n", "\r\n", self::V1));

        $this->assertSame(0, $again->chunksCreated, 'a git checkout with autocrlf rewrites line endings only');
        $this->assertSame($embedded, $this->embedded);
    }

    /** @test */
    public function a_changed_file_is_replaced(): void
    {
        $this->ingest(self::V1);
        $result = $this->ingest(self::V2);

        $this->assertTrue($result->success);
        $this->assertGreaterThan(0, $result->chunksCreated);
        $chunks = $this->store->getFileChunks(self::FILE, self::COLLECTION);
        $this->assertStringContainsString('a phone', implode(' ', array_map(fn (FileChunk $c) => $c->content, $chunks)));
        $this->assertSame(sha1(self::V2), $chunks[0]->metadata['source_hash']);
    }

    /** @test */
    public function chunks_stored_before_hashes_count_as_unchanged(): void
    {
        $this->store->put(self::FILE, [new FileChunk(self::FILE, 'guide.md', 'old text', [0.1, 0.2], 0, 1, 0, 8, [])], self::COLLECTION);

        $result = $this->ingest(self::V2);

        $this->assertSame(0, $result->chunksCreated);
        $this->assertSame(0, $this->embedded);
        $this->assertSame('old text', $this->store->getFileChunks(self::FILE, self::COLLECTION)[0]->content, '--fresh re-embeds them once');
    }

    /** @test */
    public function without_the_option_a_processed_file_is_refused_as_before(): void
    {
        $this->ingest(self::V1, []);

        $this->assertFalse($this->ingest(self::V1, [])->success);
        $this->assertArrayNotHasKey('source_hash', $this->store->getFileChunks(self::FILE, self::COLLECTION)[0]->metadata);
    }
}
