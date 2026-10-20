<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Console\Commands\LinkFilesCommand;
use Condoedge\Ai\Contracts\ChunkLinkStoreInterface;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileOwnerResolverInterface;
use Condoedge\Ai\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `ai:files:link` — the backfill of the owner keys onto chunks stored before they
 * existed: per stored file, one owner lookup and a payload-only write. It reports
 * the files linked, those of no record and those whose File row is gone, and
 * never touches the project collection.
 */
class LinkFilesCommandTest extends TestCase
{
    /** @var array<int, array{0: int|string, 1: array, 2: ?string, 3: bool}> */
    private array $linked = [];

    public function setUp(): void
    {
        parent::setUp();
        config(['ai.file_processing.collection' => 'file_chunks']);
        config(['ai.file_context.physical_collection' => 'documentation_chunks']);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function store(array $storedIds = [7, 8, 9]): MockInterface
    {
        $store = Mockery::mock(ChunkStoreInterface::class, ChunkLinkStoreInterface::class);
        $store->shouldReceive('storedFileIds')->andReturn($storedIds)->byDefault();
        $store->shouldReceive('ensureLinkIndexes')->byDefault();
        $store->shouldReceive('linkFileChunks')->andReturnUsing(function ($fileId, $owner, $collection, $dryRun) {
            $this->linked[] = [$fileId, $owner, $collection, $dryRun];

            return ['points' => 10, 'changed' => $fileId === 8 ? 0 : 10];
        })->byDefault();

        return $store;
    }

    /** @return array{0: int, 1: string} */
    private function runCommand(MockInterface $store, array $input = []): array
    {
        $resolver = Mockery::mock(FileOwnerResolverInterface::class);
        $resolver->shouldReceive('ownerOf')->andReturnUsing(fn (object $file): array => $file->id === 7
            ? ['owner' => 'unit:309', 'ancestors' => ['union:14']]
            : ['owner' => null, 'ancestors' => []]);

        $command = new LinkFilesCommand($store, $resolver);
        // File rows 7 and 8 exist; 9 was removed from the database.
        $command->useFileLoader(static fn (int|string $id): ?object => in_array($id, [7, 8], true) ? (object) ['id' => $id] : null);
        $command->setLaravel($this->app);

        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput($input), $output);

        return [$code, $output->fetch()];
    }

    /** @test */
    public function it_links_every_stored_file_and_reports_what_it_found(): void
    {
        $store = $this->store();
        $store->shouldReceive('ensureLinkIndexes')->once()->with('file_chunks');

        [$code, $output] = $this->runCommand($store);

        $this->assertSame(0, $code);
        $this->assertSame([
            [7, ['owner' => 'unit:309', 'ancestors' => ['union:14']], 'file_chunks', false],
            [8, ['owner' => null, 'ancestors' => []], 'file_chunks', false],
            // No File row: nothing to resolve an owner from — a file of no record.
            [9, ['owner' => null, 'ancestors' => []], 'file_chunks', false],
        ], $this->linked);

        $this->assertMatchesRegularExpression('/Files\s+\|?\s*3\b/', $output);
        $this->assertMatchesRegularExpression('/Linked to a record\s+\|?\s*1\b/', $output);
        $this->assertMatchesRegularExpression('/Without an owner\s+\|?\s*1\b/', $output);
        $this->assertMatchesRegularExpression('/File row no longer exists\s+\|?\s*1\b/', $output);
        $this->assertMatchesRegularExpression('/Points\s+\|?\s*30\b/', $output);
        $this->assertMatchesRegularExpression('/Points written\s+\|?\s*20\b/', $output);
    }

    /** @test */
    public function a_dry_run_writes_nothing_and_creates_no_index(): void
    {
        $store = $this->store();
        $store->shouldNotReceive('ensureLinkIndexes');

        [$code] = $this->runCommand($store, ['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertSame([true, true, true], array_column($this->linked, 3));
    }

    /** @test */
    public function one_file_can_be_linked_on_its_own(): void
    {
        $store = $this->store();
        $store->shouldNotReceive('storedFileIds');

        $this->runCommand($store, ['--file' => ['7'], '--no-index' => true]);

        $this->assertSame([7], array_column($this->linked, 0));
    }

    /** @test */
    public function it_refuses_the_project_collection(): void
    {
        $store = $this->store();
        $store->shouldNotReceive('linkFileChunks');
        $store->shouldNotReceive('ensureLinkIndexes');

        [$code, $output] = $this->runCommand($store, ['--collection' => 'documentation_chunks']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('documentation_chunks', $output);
    }

    /** @test */
    public function a_project_file_found_among_the_uploads_is_left_alone(): void
    {
        $store = $this->store([7, 'physical:docs/guide.md']);

        [$code, $output] = $this->runCommand($store, ['--no-index' => true]);

        $this->assertSame(0, $code);
        $this->assertSame([7], array_column($this->linked, 0));
        $this->assertMatchesRegularExpression('/Project files skipped\s+\|?\s*1\b/', $output);
    }
}
