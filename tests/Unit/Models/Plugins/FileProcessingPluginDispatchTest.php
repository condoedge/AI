<?php

namespace Condoedge\Ai\Tests\Unit\Models\Plugins;

use Condoedge\Ai\Models\Plugins\FileProcessingPlugin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

/**
 * Regression test for double-dispatched ingestion on a single file save.
 *
 * onBoot must drive everything from one branching 'saved' listener so that a
 * single save enqueues exactly one processing dispatch. Previously a separate
 * 'created' listener (and an 'updated' listener) ran alongside 'saved', so a
 * create enqueued the ingestion path twice (duplicate OCR/embedding/Neo4j
 * ingest), and a path-change update enqueued it twice as well.
 *
 * Each event listener wraps its work in dispatch(Closure), so counting the
 * pushed CallQueuedClosure jobs measures the dispatch fan-out directly at the
 * enqueue boundary.
 */
class FileProcessingPluginDispatchTest extends TestCase
{
    /**
     * Don't load the full AI service provider to avoid boot issues
     * (mirrors the sibling FileProcessingPluginTest).
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->app['db']->connection()->getSchemaBuilder()->create('dispatch_test_files', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();
        });

        // A previous test may have registered listeners on this class.
        DispatchTestFile::flushEventListeners();

        (new FileProcessingPlugin(DispatchTestFile::class))->onBoot();
    }

    public function tearDown(): void
    {
        DispatchTestFile::flushEventListeners();
        parent::tearDown();
    }

    /**
     * Count the CallQueuedClosure jobs the plugin enqueued.
     */
    private function pushedClosureCount(): int
    {
        $count = 0;

        Queue::assertPushed(CallQueuedClosure::class, function () use (&$count) {
            $count++;

            return true;
        });

        return $count;
    }

    /** @test */
    public function a_single_create_enqueues_exactly_one_processing_dispatch()
    {
        Queue::fake();

        DispatchTestFile::create([
            'name' => 'report.pdf',
            'path' => '/tmp/report.pdf',
            'size' => 1024,
        ]);

        $this->assertSame(
            1,
            $this->pushedClosureCount(),
            'A single create must enqueue exactly one processing dispatch, not two.'
        );
    }

    /** @test */
    public function a_path_change_update_enqueues_exactly_one_processing_dispatch()
    {
        $file = DispatchTestFile::create([
            'name' => 'report.pdf',
            'path' => '/tmp/report.pdf',
            'size' => 1024,
        ]);

        Queue::fake();

        $file->path = '/tmp/report-v2.pdf';
        $file->save();

        $this->assertSame(
            1,
            $this->pushedClosureCount(),
            'A path-change update must enqueue exactly one processing dispatch, not two.'
        );
    }

    /** @test */
    public function a_non_path_update_enqueues_nothing()
    {
        $file = DispatchTestFile::create([
            'name' => 'report.pdf',
            'path' => '/tmp/report.pdf',
            'size' => 1024,
        ]);

        Queue::fake();

        $file->name = 'renamed.pdf';
        $file->save();

        // What a save did is decided when the model is saved (a queued job gets a
        // re-fetched model that is neither new nor dirty): a save that changes
        // neither the stored file nor the record it is filed on queues no job.
        Queue::assertNothingPushed();
    }
}

/**
 * Minimal Eloquent model so onBoot can register real model events.
 */
class DispatchTestFile extends Model
{
    protected $table = 'dispatch_test_files';
    protected $guarded = [];
    public $timestamps = true;
}
