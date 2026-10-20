<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Services\Extractors\MarkdownExtractor;
use Condoedge\Ai\Services\Extractors\TextExtractor;
use Condoedge\Ai\Services\FileExtractorRegistry;
use Condoedge\Ai\Services\FileProcessor;
use Condoedge\Ai\Services\SemanticChunker;
use Condoedge\Ai\Support\DocumentMap;
use Condoedge\Ai\Tests\Fixtures\InMemoryMapChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Mockery;

/**
 * A re-ingest REPLACES a file's points — and with them the map written beside
 * them after the first ingest: every gist paid for, and the scope that hides the
 * parts that do not govern the application. The record of a chunk names the text
 * it was written from, so a chunk whose text (and place in the document) did not
 * change takes its record along; only what changed is left to be mapped again.
 */
class FileProcessorDocumentMapCarryOverTest extends TestCase
{
    private const FILE = 'physical:docs/guide.md';

    private const COLLECTION = 'documentation_chunks';

    private string $dir;

    private InMemoryMapChunkStore $store;

    public function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        Bus::fake();

        $this->dir = sys_get_temp_dir() . '/docmap_carry_' . uniqid();
        mkdir($this->dir);
        $this->write('A contact may be invited to the portal by an administrator, who chooses what the contact can see.');
        $this->store = new InMemoryMapChunkStore();
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $access): void
    {
        file_put_contents(
            $this->dir . '/guide.md',
            "# Guide\n\n## Contacts\nA contact is a person recorded in the directory of the building, with a name and an address.\n\n"
            . "## Access\n{$access}\n"
        );
    }

    private function processor(): FileProcessor
    {
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embedBatch')->andReturnUsing(fn (array $texts) => array_map(fn () => [0.1, 0.2], $texts));

        $registry = new FileExtractorRegistry();
        $registry->registerMany([new TextExtractor(), new MarkdownExtractor()]);

        return new FileProcessor($registry, new SemanticChunker(), $embedder, $this->store);
    }

    private function file(): object
    {
        return (object) ['id' => self::FILE, 'name' => 'guide.md', 'path' => $this->dir . '/guide.md'];
    }

    /** Ingest once and give every content chunk the record a map build would write. */
    private function ingestedAndMapped(array $options = ['min_chunk_chars' => 20]): void
    {
        $result = $this->processor()->processFile($this->file(), ['collection' => self::COLLECTION] + $options);
        $this->assertTrue($result->success, (string) $result->error);

        foreach ($this->store->getFileChunks(self::FILE, self::COLLECTION) as $chunk) {
            if (DocumentMap::isPreamble($chunk)) {
                continue;
            }
            $this->store->setChunkMap($chunk, [
                'v' => DocumentMap::VERSION,
                'hash' => DocumentMap::hash($chunk->content),
                'gist' => "Gist of chunk {$chunk->chunkIndex}.",
                'scope' => 'out',
                'scope_ref' => 'ref',
                'scope_why' => 'Another regime.',
            ], self::COLLECTION);
        }
        $this->assertGreaterThanOrEqual(1, DocumentMap::state($this->store->getFileChunks(self::FILE, self::COLLECTION))['mapped']);
    }

    /** @return array<int, array<string, mixed>|null> chunk_index => stored record */
    private function records(): array
    {
        $records = [];
        foreach ($this->store->getFileChunks(self::FILE, self::COLLECTION) as $chunk) {
            if (!DocumentMap::isPreamble($chunk)) {
                $records[$chunk->chunkIndex] = DocumentMap::recordOf($chunk);
            }
        }

        return $records;
    }

    /** @test */
    public function a_forced_reingest_of_unchanged_text_keeps_every_gist_and_the_scope(): void
    {
        Log::spy();
        $this->ingestedAndMapped();
        $before = $this->records();

        $result = $this->processor()->processFile($this->file(), ['collection' => self::COLLECTION, 'force' => true, 'min_chunk_chars' => 20]);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame($before, $this->records(), 'gist, scope, the description it was judged against and the reason: all carried');
        $this->assertTrue(DocumentMap::isUsable($this->store->getFileChunks(self::FILE, self::COLLECTION)));
        $this->assertSame('out', DocumentMap::scopeOf($this->store->getFileChunks(self::FILE, self::COLLECTION)[1]), 'the part stays hidden');
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context = []): bool => str_contains($message, 'Document map carried over')
                && $context['file_id'] === self::FILE && $context['carried'] === count($before) && $context['dropped'] === 0
        )->once();
    }

    /** @test */
    public function only_the_chunk_whose_text_changed_loses_its_record(): void
    {
        Log::spy();
        $this->ingestedAndMapped();
        $before = $this->records();

        $this->write('A contact may be invited by any member of the board, who chooses what the contact can see.');
        $this->processor()->processFile($this->file(), ['collection' => self::COLLECTION, 'force' => true, 'min_chunk_chars' => 20]);

        $after = $this->records();
        $kept = array_filter($after);
        $this->assertCount(count($before) - 1, $kept, 'the contacts passage keeps its record');
        $this->assertContains(null, $after, 'the edited passage is left to be mapped again');
        $this->assertSame(1, DocumentMap::state($this->store->getFileChunks(self::FILE, self::COLLECTION))['pending']);
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context = []): bool => str_contains($message, 'Document map carried over')
                && $context['carried'] === count($before) - 1 && $context['dropped'] === 1
        )->once();
    }

    /** @test */
    public function reprocessing_a_file_carries_the_map_too(): void
    {
        // reprocessFile() chunks with the default options: so does the first ingest here.
        $this->ingestedAndMapped([]);
        $before = $this->records();
        $this->assertNotSame([], $before);

        $result = $this->processor()->reprocessFile($this->file(), self::COLLECTION);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(array_map(static fn (array $r): string => $r['gist'], $before), array_map(static fn (?array $r): ?string => $r['gist'] ?? null, $this->records()));
    }

    /** @test */
    public function nothing_is_carried_when_the_feature_is_switched_off(): void
    {
        $this->ingestedAndMapped();
        config(['ai.file_context.document_map.enabled' => false]);

        $this->processor()->processFile($this->file(), ['collection' => self::COLLECTION, 'force' => true, 'min_chunk_chars' => 20]);

        $this->assertSame([], array_filter($this->records()));
    }

    /** @test */
    public function a_first_ingest_and_a_store_that_cannot_be_read_are_ingested_as_before(): void
    {
        $result = $this->processor()->processFile($this->file(), ['collection' => self::COLLECTION, 'min_chunk_chars' => 20]);
        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame([], array_filter($this->records()));

        // The read of the old chunks fails: the ingest goes on without a carry.
        $this->store = new class extends InMemoryMapChunkStore {
            public function getFileChunks(int|string $fileId, ?string $collection = null): array
            {
                throw new \RuntimeException('vector store down');
            }
        };
        $result = $this->processor()->processFile($this->file(), ['collection' => self::COLLECTION, 'force' => true, 'min_chunk_chars' => 20]);
        $this->assertTrue($result->success, (string) $result->error);
    }
}
