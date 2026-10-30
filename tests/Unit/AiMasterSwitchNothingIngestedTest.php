<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Jobs\BuildDocumentMapJob;
use Condoedge\Ai\Jobs\IngestEntityJob;
use Condoedge\Ai\Jobs\ProcessFileJob;
use Condoedge\Ai\Jobs\RemoveEntityJob;
use Condoedge\Ai\Jobs\SyncEntityJob;
use Condoedge\Ai\Models\Plugins\FileProcessingPlugin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * AI_ENABLED=false: NOTHING is ingested. Every service that leaves the process is a mock
 * that fails the test when called, whoever swallows the failure on the way: saving a
 * Nodeable, running a command, running a job already on the queue, a file's events.
 */
class AiMasterSwitchNothingIngestedTest extends AiMasterSwitchTestCase
{
    private const DISABLED_LINE = 'AI is disabled (AI_ENABLED=false): nothing was done';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('ai.enabled', false);
        // A host that turned the features on explicitly: the master wins.
        $app['config']->set('ai.auto_sync.enabled', true);
        $app['config']->set('ai.auto_sync.queue', false);
        $app['config']->set('ai.file_processing.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('master_switch_records', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();
        });

        foreach ([
            GraphStoreInterface::class,
            VectorStoreInterface::class,
            EmbeddingProviderInterface::class,
            LlmProviderInterface::class,
            ChunkStoreInterface::class,
            FileProcessorInterface::class,
        ] as $contract) {
            $mock = $this->createMock($contract);
            $mock->expects($this->never())->method($this->anything());
            $this->app->instance($contract, $mock);
        }

        MasterSwitchFile::flushEventListeners();
    }

    protected function tearDown(): void
    {
        MasterSwitchFile::flushEventListeners();
        parent::tearDown();
    }

    /** @test */
    public function saving_a_nodeable_reaches_nothing_whatever_the_model_says(): void
    {
        Queue::fake();

        foreach ([MasterSwitchNode::class, MasterSwitchQueuedNode::class] as $class) {
            $node = $class::create(['name' => 'first']);
            $node->update(['name' => 'second']);
            DB::transaction(fn () => $class::create(['name' => 'in a transaction']));
            $node->delete();
        }

        Queue::assertNothingPushed();
        $this->assertSame(2, MasterSwitchNode::count(), 'the saves themselves are unaffected');
    }

    /**
     * @test
     * @dataProvider writingCommands
     */
    public function a_command_says_it_did_nothing_and_succeeds(string $command, array $arguments = []): void
    {
        $exit = Artisan::call($command, $arguments);

        $this->assertStringContainsString(self::DISABLED_LINE, Artisan::output());
        $this->assertSame(0, $exit);
    }

    public static function writingCommands(): array
    {
        return [
            'ai:ingest' => ['ai:ingest'],
            'ai:ingest --docs' => ['ai:ingest', ['--docs' => true]],
            'ai:process-files' => ['ai:process-files'],
            'ai:documents:map' => ['ai:documents:map'],
            'ai:files:link' => ['ai:files:link'],
            'ai:index-context' => ['ai:index-context'],
            'ai:index-scopes' => ['ai:index-scopes'],
            'ai:index-semantic' => ['ai:index-semantic'],
            'ai:sync-relationships' => ['ai:sync-relationships'],
            'ai:discover' => ['ai:discover'],
            'ai:enhance' => ['ai:enhance'],
            'ai:setup' => ['ai:setup'],
            // Reports on connections: it would open them.
            'ai:diagnose' => ['ai:diagnose'],
        ];
    }

    /** @test */
    public function validating_the_config_keeps_working_and_reaches_nothing(): void
    {
        Artisan::call('ai:validate-config');

        $this->assertStringNotContainsString(self::DISABLED_LINE, Artisan::output());
    }

    /** @test */
    public function a_job_already_on_the_queue_does_nothing(): void
    {
        $node = MasterSwitchNode::create(['name' => 'queued earlier']);

        foreach ([
            new IngestEntityJob($node),
            new SyncEntityJob($node),
            new RemoveEntityJob($node),
            new ProcessFileJob((object) ['id' => 1, 'name' => 'a.txt', 'path' => 'a.txt']),
            new BuildDocumentMapJob(1),
        ] as $job) {
            $this->app->call([$job, 'handle']);
        }

        $this->addToAssertionCount(1); // the mocks verify it
    }

    /** @test */
    public function a_files_events_queue_nothing(): void
    {
        (new FileProcessingPlugin(MasterSwitchFile::class))->onBoot();
        Queue::fake();

        $file = MasterSwitchFile::create(['name' => 'a.txt', 'path' => 'a.txt', 'size' => 10]);
        $file->update(['path' => 'b.txt']);
        $file->delete();

        Queue::assertNothingPushed();
    }

    /** @test */
    public function a_files_processing_already_on_the_queue_does_nothing(): void
    {
        (new FileProcessingPlugin(MasterSwitchFile::class))->onBoot();
        Queue::fake();

        // Queued while the assistant was on.
        config(['ai.enabled' => true]);
        $file = MasterSwitchFile::create(['name' => 'a.txt', 'path' => 'a.txt', 'size' => 10]);
        $file->update(['path' => 'b.txt']);
        $file->delete();
        config(['ai.enabled' => false]);

        $queued = 0;
        Queue::assertPushed(CallQueuedClosure::class, function (CallQueuedClosure $job) use (&$queued) {
            $job->handle($this->app);
            $queued++;

            return true;
        });

        $this->assertSame(3, $queued, 'a create, a replacement and a delete');
    }

    /** @test */
    public function the_facades_ingestion_methods_do_nothing_and_say_so(): void
    {
        $node = MasterSwitchNode::create(['name' => 'direct']);
        $nodes = [$node, MasterSwitchNode::create(['name' => 'direct too'])];

        $this->assertSame(['graph_stored' => false, 'relationships_created' => 0, 'errors' => []], AI::ingest($node));
        $this->assertSame(['action' => 'skipped', 'graph_synced' => false, 'errors' => []], AI::sync($node));
        $this->assertFalse(AI::remove($node));
        $this->assertSame(
            ['total' => 2, 'succeeded' => 0, 'partially_succeeded' => 0, 'failed' => 0, 'errors' => []],
            AI::ingestBatch($nodes)
        );
    }
}

class MasterSwitchNode extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $table = 'master_switch_records';
    protected $fillable = ['name'];
    protected $aiEagerLoadRelationships = false;
    // The model asks for it: it does not get it.
    protected $aiAutoSync = true;

    public function nodeableConfig(): array
    {
        return ['graph' => ['label' => 'MasterSwitchThing', 'properties' => ['id', 'name'], 'relationships' => []]];
    }
}

class MasterSwitchQueuedNode extends MasterSwitchNode
{
    protected $aiSyncQueue = true;
}

class MasterSwitchFile extends MasterSwitchNode
{
    protected $fillable = ['name', 'path', 'size'];
    protected $aiAutoSync = false;
}
