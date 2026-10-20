<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Extractors\MarkdownExtractor;
use Condoedge\Ai\Services\Extractors\TextExtractor;
use Condoedge\Ai\Services\FileExtractorRegistry;
use Condoedge\Ai\Services\FileProcessor;
use Condoedge\Ai\Services\SemanticChunker;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7.1 — FileProcessor stores the structured chunk metadata (heading path,
 * article, preamble flag, page) and EMBEDS the article-prefixed text while the
 * stored content stays the raw passage. A forced re-ingest replaces the file's
 * points so stale chunks of the old (more numerous) split never linger.
 */
class FileProcessorStructuredChunkTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/p7a_fp_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        Mockery::close();
        parent::tearDown();
    }

    private function processor(ChunkStoreInterface $store, EmbeddingProviderInterface $embedder): FileProcessor
    {
        $registry = new FileExtractorRegistry();
        $registry->registerMany([new TextExtractor(), new MarkdownExtractor()]);

        return new FileProcessor($registry, new SemanticChunker(), $embedder, $store);
    }

    private function law(): string
    {
        return "# Civil Code — Co-ownership\n\n## CHAPTER III\n### DIVISION IV — RIGHTS AND OBLIGATIONS OF CO-OWNERS\n"
            . "**1068.** Every co-owner may, within five years from the day of registration of the declaration, apply to the court for a revision of the relative value of the fractions.\n"
            . "*1991, c. 64, a. 1068.*\n"
            . "**1069.** A person who acquires a fraction of an immovable under divided co-ownership is bound to pay all common expenses due with respect to that fraction, with interest, at the time of the acquisition.\n"
            . "*1991, c. 64, a. 1069.*\n"
            . "**1070.** Among the registers of the co-ownership, the syndicate keeps at the disposal of the co-owners a register containing the name and mailing address of each co-owner.\n";
    }

    /** @test */
    public function it_embeds_the_prefixed_text_and_stores_raw_content_with_article_metadata(): void
    {
        $path = $this->dir . '/law.md';
        file_put_contents($path, $this->law());

        $embedded = [];
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embedBatch')->once()->andReturnUsing(function (array $texts) use (&$embedded) {
            $embedded = $texts;
            return array_map(fn () => [0.1, 0.2], $texts);
        });

        $stored = [];
        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('hasFileChunks')->andReturn(false);
        $store->shouldReceive('storeChunks')->once()->andReturnUsing(function (array $chunks) use (&$stored) {
            $stored = $chunks;
            return true;
        });

        $result = $this->processor($store, $embedder)->processFile(
            (object) ['id' => 'physical:laws/law.md', 'name' => 'law.md', 'path' => $path],
            ['min_chunk_chars' => 50]
        );

        $this->assertTrue($result->success, (string) $result->error);

        $byArticle = [];
        foreach ($stored as $i => $chunk) {
            $this->assertInstanceOf(FileChunk::class, $chunk);
            $byArticle[$chunk->metadata['article_no'] ?? 'none-' . $i] = [$chunk, $embedded[$i]];
        }

        [$chunk, $embedText] = $byArticle['1069'];
        $this->assertStringStartsWith('**1069.** A person who acquires', $chunk->content);
        $this->assertStringStartsWith('Civil Code — Co-ownership > CHAPTER III > DIVISION IV — RIGHTS AND OBLIGATIONS OF CO-OWNERS — art. 1069:', $embedText);
        $this->assertSame(['1069'], $chunk->metadata['articles']);
        $this->assertSame('DIVISION IV — RIGHTS AND OBLIGATIONS OF CO-OWNERS', $chunk->metadata['section_title']);
        $this->assertFalse($chunk->metadata['is_preamble']);
        $this->assertNull($chunk->metadata['page']);

        $this->assertTrue($stored[0]->metadata['is_preamble'], 'title/heading-only chunk is preamble');
        $this->assertSame(
            $chunk->content,
            mb_substr($this->law(), $chunk->startPosition, $chunk->endPosition - $chunk->startPosition),
            'offsets still point at the raw passage in the source'
        );
    }

    /**
     * The document's own title — the first top-level heading of its first chunk —
     * is stamped on EVERY chunk at processing time, so a passage deep in the
     * document names its document without a read of the first chunk.
     *
     * @test
     */
    public function every_chunk_is_stamped_with_the_documents_own_title(): void
    {
        $path = $this->dir . '/law.md';
        file_put_contents($path, $this->law());

        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embedBatch')->andReturnUsing(fn (array $t) => array_map(fn () => [0.1], $t));
        $stored = [];
        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('hasFileChunks')->andReturn(false);
        $store->shouldReceive('storeChunks')->andReturnUsing(function (array $chunks) use (&$stored) {
            $stored = $chunks;
            return true;
        });

        $result = $this->processor($store, $embedder)->processFile(
            (object) ['id' => 'physical:laws/law.md', 'name' => 'law.md', 'path' => $path],
            ['min_chunk_chars' => 50]
        );

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertGreaterThan(2, count($stored));
        foreach ($stored as $chunk) {
            $this->assertSame('Civil Code — Co-ownership', $chunk->metadata[\Condoedge\Ai\Support\DocumentTitle::METADATA_KEY]);
        }
    }

    /** @test */
    public function a_document_without_a_top_level_heading_is_stamped_with_no_title(): void
    {
        $path = $this->dir . '/notes.txt';
        file_put_contents($path, "MINUTES OF THE MEETING\n\nArticle 1 Purpose. The declaration governs the building.\nArticle 2 Destination. The immovable is residential.\nArticle 3 Pets. Small pets are allowed.");

        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embedBatch')->andReturnUsing(fn (array $t) => array_map(fn () => [0.1], $t));
        $stored = [];
        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('hasFileChunks')->andReturn(false);
        $store->shouldReceive('storeChunks')->andReturnUsing(function (array $chunks) use (&$stored) {
            $stored = $chunks;
            return true;
        });

        $result = $this->processor($store, $embedder)->processFile((object) ['id' => 9, 'name' => 'notes.txt', 'path' => $path], ['min_chunk_chars' => 10]);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertNotSame([], $stored);
        foreach ($stored as $chunk) {
            $this->assertArrayNotHasKey(\Condoedge\Ai\Support\DocumentTitle::METADATA_KEY, $chunk->metadata);
        }
    }

    /** @test */
    public function form_feed_page_breaks_give_each_chunk_its_page(): void
    {
        // The PDF parser joins pages with a form feed; the fake PDF extractor below
        // returns exactly that shape.
        $path = $this->dir . '/decl.pdf';
        $text = "Article 1 Purpose. The declaration governs the building and its common portions for every co-owner.\n\f\n"
            . "Article 2 Destination. The immovable is residential; no commercial use of private portions is allowed.\n\f\n"
            . "Article 3 Pets. Small pets are allowed provided they cause no nuisance to the other occupants.";
        file_put_contents($path, 'binary');
        $pdf = new class($text) implements \Condoedge\Ai\Contracts\FileExtractorInterface {
            public function __construct(private string $text) {}
            public function extract(string $filePath): string { return $this->text; }
            public function supports(string $extension): bool { return strtolower($extension) === 'pdf'; }
            public function getSupportedExtensions(): array { return ['pdf']; }
            public function extractMetadata(string $filePath): array { return []; }
        };

        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embedBatch')->andReturnUsing(fn (array $t) => array_map(fn () => [0.1], $t));
        $stored = [];
        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('hasFileChunks')->andReturn(false);
        $store->shouldReceive('storeChunks')->andReturnUsing(function (array $chunks) use (&$stored) {
            $stored = $chunks;
            return true;
        });

        $registry = new FileExtractorRegistry();
        $registry->registerMany([$pdf]);
        $processor = new FileProcessor($registry, new SemanticChunker(), $embedder, $store);

        $result = $processor->processFile((object) ['id' => 9, 'name' => 'decl.pdf', 'path' => $path], ['min_chunk_chars' => 10]);

        $this->assertTrue($result->success, (string) $result->error);
        $pages = [];
        foreach ($stored as $chunk) {
            $pages[$chunk->metadata['article_no']] = $chunk->metadata['page'];
            $this->assertStringNotContainsString("\f", $chunk->content);
        }
        $this->assertSame(['1' => 1, '2' => 2, '3' => 3], $pages);
    }

    /** @test */
    public function a_forced_reingest_replaces_the_files_points_so_stale_chunks_do_not_linger(): void
    {
        $path = $this->dir . '/law.md';
        file_put_contents($path, $this->law());

        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embedBatch')->andReturnUsing(fn (array $t) => array_map(fn () => [0.1], $t));

        $calls = [];
        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldNotReceive('hasFileChunks');
        $store->shouldReceive('deleteFileChunks')->once()->with('physical:laws/law.md', 'documentation_chunks')
            ->andReturnUsing(function () use (&$calls) {
                $calls[] = 'delete';
                return true;
            });
        $store->shouldReceive('storeChunks')->once()->with(Mockery::type('array'), 'documentation_chunks')
            ->andReturnUsing(function () use (&$calls) {
                $calls[] = 'store';
                return true;
            });

        $result = $this->processor($store, $embedder)->processFile(
            (object) ['id' => 'physical:laws/law.md', 'name' => 'law.md', 'path' => $path],
            ['force' => true, 'collection' => 'documentation_chunks']
        );

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(['delete', 'store'], $calls, 'old points are removed (after embedding succeeded) before the new split is stored');
    }
}
