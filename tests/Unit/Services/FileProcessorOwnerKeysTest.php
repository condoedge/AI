<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\FileOwnerResolverInterface;
use Condoedge\Ai\Services\Extractors\MarkdownExtractor;
use Condoedge\Ai\Services\FileExtractorRegistry;
use Condoedge\Ai\Services\FileProcessor;
use Condoedge\Ai\Services\SemanticChunker;
use Condoedge\Ai\Support\FileLink;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Every chunk of an UPLOADED file is stored with the link to the record the file
 * is filed on (owner, ancestors, dedupe key) — resolved once per file. A PROJECT
 * file (the physical collection, a `physical:` id) gets none: it is global.
 */
class FileProcessorOwnerKeysTest extends TestCase
{
    private string $dir;

    /** @var list<\Condoedge\Ai\DTOs\FileChunk> */
    private array $stored = [];

    private int $resolved = 0;

    public function setUp(): void
    {
        parent::setUp();
        config(['ai.file_context.document_map.enabled' => false]);
        config(['ai.file_context.physical_collection' => 'documentation_chunks']);

        $this->dir = sys_get_temp_dir() . '/owner_fp_' . uniqid();
        mkdir($this->dir);
        file_put_contents(
            $this->dir . '/rules.md',
            "# Rules\n\n## Pets\nA co-owner may keep one animal in a private portion, provided it disturbs no other occupant of the building.\n\n"
            . "## Noise\nThe tranquillity of the building must at no time be disturbed by an occupant, a guest or a member of staff.\n"
        );
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

    private function processor(?array $owner): FileProcessor
    {
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embedBatch')->andReturnUsing(fn (array $texts) => array_map(fn () => [0.1, 0.2], $texts));

        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('hasFileChunks')->andReturn(false);
        $store->shouldReceive('storeChunks')->andReturnUsing(function (array $chunks) {
            $this->stored = $chunks;

            return true;
        });

        $registry = new FileExtractorRegistry();
        $registry->register(new MarkdownExtractor());

        $resolver = $owner === null ? null : new class($owner, $this->resolved) implements FileOwnerResolverInterface {
            public function __construct(private array $owner, private int &$calls)
            {
            }

            public function ownerOf(object $file): array
            {
                $this->calls++;

                return $this->owner;
            }
        };

        return new FileProcessor($registry, new SemanticChunker(), $embedder, $store, $resolver);
    }

    private function file(int|string $id = 7): object
    {
        return (object) ['id' => $id, 'name' => 'rules.md', 'path' => $this->dir . '/rules.md', 'parent_type' => 'unit', 'parent_id' => 309];
    }

    /** @test */
    public function every_chunk_of_an_uploaded_file_carries_its_owner_its_ancestors_and_a_dedupe_key(): void
    {
        $result = $this->processor(['owner' => 'unit:309', 'ancestors' => ['union:14']])->processFile($this->file());

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertNotSame([], $this->stored);
        foreach ($this->stored as $chunk) {
            $this->assertSame(
                FileLink::payload(['owner' => 'unit:309', 'ancestors' => ['union:14']], $chunk->content),
                FileLink::ofChunk($chunk)
            );
        }
        $this->assertSame(1, $this->resolved, 'one lookup per file, not per chunk');
    }

    /** @test */
    public function an_uploaded_file_of_no_record_is_stored_with_a_null_owner_and_still_a_dedupe_key(): void
    {
        $this->processor(['owner' => null, 'ancestors' => []])->processFile($this->file());

        $link = FileLink::ofChunk($this->stored[0]);
        $this->assertNull($link['owner']);
        $this->assertSame([], $link['ancestors']);
        $this->assertSame(FileLink::dedupeKey($this->stored[0]->content, null), $link['dedupe']);
    }

    /** @test */
    public function a_project_file_gets_no_keys(): void
    {
        $processor = $this->processor(['owner' => 'unit:309', 'ancestors' => []]);

        $processor->processFile($this->file('physical:docs/rules.md'), ['collection' => 'documentation_chunks']);
        $this->assertNull(FileLink::ofChunk($this->stored[0]));

        // … whatever collection a `physical:` id is sent to.
        $processor->processFile($this->file('physical:docs/rules.md'));
        $this->assertNull(FileLink::ofChunk($this->stored[0]));

        $this->assertSame(0, $this->resolved);
    }

    /** @test */
    public function without_a_resolver_an_upload_is_stored_as_a_file_of_no_record(): void
    {
        $this->processor(null)->processFile($this->file());

        $this->assertSame(
            FileLink::payload(['owner' => null, 'ancestors' => []], $this->stored[0]->content),
            FileLink::ofChunk($this->stored[0])
        );
    }

    /** @test */
    public function an_owner_that_cannot_be_resolved_fails_the_processing_instead_of_storing_an_unlinked_file(): void
    {
        $embedder = Mockery::mock(EmbeddingProviderInterface::class);
        $embedder->shouldReceive('embedBatch')->andReturnUsing(fn (array $texts) => array_map(fn () => [0.1, 0.2], $texts));
        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('hasFileChunks')->andReturn(false);
        $store->shouldNotReceive('storeChunks');
        $registry = new FileExtractorRegistry();
        $registry->register(new MarkdownExtractor());
        $resolver = Mockery::mock(FileOwnerResolverInterface::class);
        $resolver->shouldReceive('ownerOf')->andThrow(new \RuntimeException('database is gone'));

        $result = (new FileProcessor($registry, new SemanticChunker(), $embedder, $store, $resolver))->processFile($this->file());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('database is gone', (string) $result->error);
    }
}
