<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Console\Commands\IngestEntitiesCommand;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\BatchDataIngestionService;
use Condoedge\Ai\Services\Context\PhysicalFileIndexer;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * PROJECT files are global and live in their own collection; UPLOADED files are
 * linked to a record and live in theirs. Nothing but configuration kept the two
 * apart: with `ai.file_context.physical_collection` empty or equal to the uploads
 * collection, `ai:ingest --docs` wrote the project files among the uploads —
 * unlinked, unscoped, beside every tenant's documents. It now refuses to run.
 */
class IngestDocsCollectionGuardTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array{0: int, 1: string} exit code, output */
    private function runDocs(mixed $physicalCollection): array
    {
        config(['ai.file_processing.collection' => 'file_chunks']);
        config(['ai.file_context.physical_collection' => $physicalCollection]);

        // Nothing may be discovered or processed when the guard refuses.
        $indexer = Mockery::mock(PhysicalFileIndexer::class);
        $indexer->shouldReceive('createFileObjects')->andReturn([])->byDefault();
        $this->app->instance(PhysicalFileIndexer::class, $indexer);
        $processor = Mockery::mock(FileProcessorInterface::class);
        $processor->shouldNotReceive('processFile');
        $this->app->instance(FileProcessorInterface::class, $processor);

        $command = new IngestEntitiesCommand(
            Mockery::mock(BatchDataIngestionService::class),
            Mockery::mock(GraphStoreInterface::class)
        );
        $command->setLaravel($this->app);
        $buffer = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $handle = new \ReflectionMethod($command, 'handleDocsIngestion');
        $handle->setAccessible(true);

        return [(int) $handle->invoke($command), $buffer->fetch()];
    }

    /** @test */
    public function it_refuses_a_physical_collection_equal_to_the_uploads_collection(): void
    {
        [$code, $output] = $this->runDocs('file_chunks');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('ai.file_context.physical_collection', $output);
        $this->assertStringContainsString('file_chunks', $output);
    }

    /**
     * @test
     * @dataProvider emptyCollections
     */
    public function it_refuses_an_empty_physical_collection(mixed $empty): void
    {
        // An empty name falls back to the chunk store's bound collection: the uploads.
        [$code, $output] = $this->runDocs($empty);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('ai.file_context.physical_collection', $output);
    }

    public static function emptyCollections(): array
    {
        return ['empty string' => [''], 'blank' => ['  '], 'not a string' => [false]];
    }

    /** @test */
    public function a_collection_of_its_own_passes_the_guard(): void
    {
        [$code, $output] = $this->runDocs('documentation_chunks');

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('physical_collection', $output);
    }
}
