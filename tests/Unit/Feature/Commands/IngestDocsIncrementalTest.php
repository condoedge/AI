<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Feature\Commands;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\DTOs\ProcessingResult;
use Condoedge\Ai\Tests\Fixtures\InMemoryMapChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * `ai:ingest --docs` is re-runnable: unchanged files are skips (exit 0), and --prune
 * removes the stored project files whose file is gone from disk.
 */
class IngestDocsIncrementalTest extends TestCase
{
    private string $dir;

    /** @var list<array> */
    private array $options = [];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('ai.file_context.physical_collection', 'test_docs');
        $app['config']->set('ai.file_context.supported_extensions', ['md']);
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ai_docs_incremental_' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/kept.md', "# Kept\n\nStill here.");
        config(['ai.file_context.base_path' => $this->dir, 'ai.file_context.physical_paths' => ['*.md']]);

        $processor = Mockery::mock(FileProcessorInterface::class);
        $processor->shouldReceive('processFile')->andReturnUsing(function ($file, array $options) {
            $this->options[] = $options;

            return ProcessingResult::success($file->id, 0, 0, 0.0, ['skipped' => true, 'reason' => 'unchanged']);
        });
        $this->app->instance(FileProcessorInterface::class, $processor);
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

    private function storeHolding(array $fileIds): InMemoryMapChunkStore
    {
        $store = new class extends InMemoryMapChunkStore {
            public function storedFileIds(?string $collection = null): array
            {
                return array_keys($this->files[$collection ?? self::DEFAULT_COLLECTION] ?? []);
            }
        };
        foreach ($fileIds as $id) {
            $store->put($id, [new \Condoedge\Ai\DTOs\FileChunk($id, 'x.md', 'x', [0.1], 0, 1, 0, 1, [])], 'test_docs');
        }
        $this->app->instance(ChunkStoreInterface::class, $store);

        return $store;
    }

    /** @test */
    public function unchanged_files_are_skips_and_the_run_succeeds(): void
    {
        $this->artisan('ai:ingest --docs')->expectsOutputToContain('Skipped: 1')->assertSuccessful();

        $this->assertTrue($this->options[0]['skip_unchanged']);
    }

    /** @test */
    public function prune_removes_the_project_files_gone_from_disk_and_nothing_else(): void
    {
        $store = $this->storeHolding(['physical:kept.md', 'physical:deleted.md', 42]);

        $this->artisan('ai:ingest --docs --prune')->expectsOutputToContain('Pruned: 1')->assertSuccessful();

        $this->assertNotSame([], $store->getFileChunks('physical:kept.md', 'test_docs'));
        $this->assertSame([], $store->getFileChunks('physical:deleted.md', 'test_docs'));
        $this->assertNotSame([], $store->getFileChunks(42, 'test_docs'), 'not a project file: never pruned');
    }

    /** @test */
    public function without_prune_nothing_is_removed(): void
    {
        $store = $this->storeHolding(['physical:kept.md', 'physical:deleted.md']);

        $this->artisan('ai:ingest --docs')->assertSuccessful();

        $this->assertNotSame([], $store->getFileChunks('physical:deleted.md', 'test_docs'));
    }
}
