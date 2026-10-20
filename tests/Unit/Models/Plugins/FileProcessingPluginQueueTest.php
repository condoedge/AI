<?php

namespace Condoedge\Ai\Tests\Unit\Models\Plugins;

use Condoedge\Ai\Contracts\ChunkLinkStoreInterface;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileOwnerResolverInterface;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\DTOs\ProcessingResult;
use Condoedge\Ai\Models\Plugins\FileProcessingPlugin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * The upload hook on a REAL queue.
 *
 * The hook queues its work. A queued job does not carry the model: it carries
 * its identifier, and the worker fetches the row again — a model that is neither
 * "recently created" nor dirty. The hook used to decide what to do INSIDE the
 * job from those two flags, so on any queue but `sync` neither branch ran and a
 * new upload was never indexed. What happened is now decided when the model is
 * saved and travels with the job.
 *
 * Every job here goes through serialize() / unserialize() before it runs, as it
 * does on a database / redis queue.
 */
class FileProcessingPluginQueueTest extends TestCase
{
    /** @var list<array{0: string, 1: mixed}> what the processor / store were asked to do */
    private array $calls = [];

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('ai.file_processing.enabled', true);
        $app['config']->set('ai.file_processing.fail_silently', false);
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->app['db']->connection()->getSchemaBuilder()->create('queue_test_files', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('parent_type')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('union_id')->nullable();
            $table->timestamps();
        });

        $processor = Mockery::mock(FileProcessorInterface::class);
        $processor->shouldReceive('supportsFileType')->andReturn(true);
        $processor->shouldReceive('isProcessed')->andReturn(true);
        $processor->shouldReceive('processFile')->andReturnUsing(function (object $file) {
            $this->calls[] = ['process', $file->id];

            return ProcessingResult::success($file->id, 1, 1, 0.0);
        });
        $processor->shouldReceive('reprocessFile')->andReturnUsing(function (object $file) {
            $this->calls[] = ['reprocess', $file->id];

            return ProcessingResult::success($file->id, 1, 1, 0.0);
        });
        $this->app->instance(FileProcessorInterface::class, $processor);

        $store = Mockery::mock(ChunkStoreInterface::class, ChunkLinkStoreInterface::class);
        $store->shouldReceive('linkFileChunks')->andReturnUsing(function ($fileId, array $owner) {
            $this->calls[] = ['link', [$fileId, $owner]];

            return ['points' => 1, 'changed' => 1];
        });
        $this->app->instance(ChunkStoreInterface::class, $store);

        $resolver = new class implements FileOwnerResolverInterface {
            public function ownerOf(object $file): array
            {
                return ['owner' => $file->parent_id ? "{$file->parent_type}:{$file->parent_id}" : null, 'ancestors' => $file->union_id ? ["union:{$file->union_id}"] : []];
            }

            /** The columns whose change moves the file to another record. */
            public function linkColumns(object $file): array
            {
                return ['parent_type', 'parent_id', 'union_id'];
            }
        };
        $this->app->instance(FileOwnerResolverInterface::class, $resolver);

        QueueTestFile::flushEventListeners();
        (new FileProcessingPlugin(QueueTestFile::class))->onBoot();
    }

    public function tearDown(): void
    {
        QueueTestFile::flushEventListeners();
        Mockery::close();
        parent::tearDown();
    }

    /** Run every queued closure the way a worker does: from its serialized form. */
    private function workQueue(): int
    {
        $jobs = [];
        Queue::assertPushed(CallQueuedClosure::class, function (CallQueuedClosure $job) use (&$jobs) {
            $jobs[] = $job;

            return true;
        });

        foreach ($jobs as $job) {
            /** @var CallQueuedClosure $revived */
            $revived = unserialize(serialize($job));
            $revived->handle($this->app);
        }

        return count($jobs);
    }

    /** @test */
    public function a_new_upload_is_indexed_when_the_job_runs_on_a_worker(): void
    {
        Queue::fake();
        $file = QueueTestFile::create(['name' => 'declaration.pdf', 'path' => '/tmp/declaration.pdf', 'size' => 1024]);

        $this->assertSame(1, $this->workQueue());
        $this->assertSame([['process', $file->id]], $this->calls);
    }

    /** @test */
    public function a_replaced_file_is_reprocessed_when_the_job_runs_on_a_worker(): void
    {
        $file = QueueTestFile::create(['name' => 'declaration.pdf', 'path' => '/tmp/declaration.pdf', 'size' => 1024]);
        $file = QueueTestFile::find($file->id);

        Queue::fake();
        $this->calls = [];
        $file->path = '/tmp/declaration-v2.pdf';
        $file->save();

        $this->workQueue();
        $this->assertSame([['reprocess', $file->id]], $this->calls);
    }

    /** @test */
    public function a_file_moved_to_another_record_has_its_chunks_re_keyed_without_reprocessing(): void
    {
        $file = QueueTestFile::create(['name' => 'declaration.pdf', 'path' => '/tmp/declaration.pdf', 'size' => 1024, 'parent_type' => 'unit', 'parent_id' => 309, 'union_id' => 14]);
        $file = QueueTestFile::find($file->id);

        Queue::fake();
        $this->calls = [];
        $file->parent_id = 310;
        $file->save();

        $this->workQueue();
        $this->assertSame([['link', [$file->id, ['owner' => 'unit:310', 'ancestors' => ['union:14']]]]], $this->calls);
    }

    /** @test */
    public function a_save_that_changes_neither_the_file_nor_its_record_queues_nothing(): void
    {
        $file = QueueTestFile::create(['name' => 'declaration.pdf', 'path' => '/tmp/declaration.pdf', 'size' => 1024]);
        $file = QueueTestFile::find($file->id);

        Queue::fake();
        $this->calls = [];
        $file->name = 'renamed.pdf';
        $file->save();

        Queue::assertNothingPushed();
        $this->assertSame([], $this->calls);
    }
}

class QueueTestFile extends Model
{
    protected $table = 'queue_test_files';
    protected $guarded = [];
    public $timestamps = true;
}
