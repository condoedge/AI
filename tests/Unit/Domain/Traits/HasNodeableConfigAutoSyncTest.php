<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Domain\Traits;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Jobs\IngestEntityJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * A Nodeable model with NO entity config (not in config/entities.php, no nodeableConfig())
 * is simply not part of the AI domain: saving it must be a silent skip (one debug line per
 * class), not a Log::error with a full stack trace on every save.
 *
 * A configured model's sync leaves the database only once its write is committed: at once
 * outside a transaction, after the commit inside one, never for a rolled-back write.
 */
class HasNodeableConfigAutoSyncTest extends TestCase
{
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
        $app['config']->set('entities', []);
        $app['config']->set('ai.auto_sync.enabled', true);
        $app['config']->set('ai.auto_sync.queue', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('autosync_test_models', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Swap the AI facade with a mock whose ingest/sync behave like the real ingestion
     * service: they resolve the model's graph config (which throws when unconfigured).
     */
    private function mockAi(): \Mockery\MockInterface
    {
        $mock = Mockery::mock();
        $this->app->instance('ai', $mock);
        AI::swap($mock);

        return $mock;
    }

    /** @test */
    public function saving_an_unconfigured_nodeable_is_a_silent_skip(): void
    {
        $ai = $this->mockAi();
        $ai->shouldReceive('ingest')->andReturnUsing(fn ($m) => $m->getGraphConfig())->never();
        $ai->shouldReceive('sync')->andReturnUsing(fn ($m) => $m->getGraphConfig())->never();

        Log::spy();

        $model = AutoSyncUnconfiguredModel::create(['name' => 'first']);
        $model->update(['name' => 'second']);
        AutoSyncUnconfiguredModel::create(['name' => 'third']);

        Log::shouldNotHaveReceived('error');
        // One debug line per class, not one per save.
        Log::shouldHaveReceived('debug')->once();
        $this->assertSame(2, AutoSyncUnconfiguredModel::count(), 'saves themselves are unaffected');
    }

    /** @test */
    public function saving_a_configured_nodeable_still_auto_syncs(): void
    {
        $ai = $this->mockAi();
        $ai->shouldReceive('ingest')->once()->andReturn([]);

        Log::spy();

        AutoSyncConfiguredModel::create(['name' => 'configured']);

        Log::shouldNotHaveReceived('error');
        $this->assertSame(1, AutoSyncConfiguredModel::count());
    }

    /** @test */
    public function outside_a_transaction_the_sync_runs_at_once(): void
    {
        $ingested = 0;
        $this->mockAi()->shouldReceive('ingest')->andReturnUsing(function () use (&$ingested) {
            $ingested++;

            return [];
        });

        AutoSyncConfiguredModel::create(['name' => 'now']);

        $this->assertSame(1, $ingested);
    }

    /** @test */
    public function inside_a_transaction_the_sync_waits_for_the_commit(): void
    {
        $ingested = 0;
        $this->mockAi()->shouldReceive('ingest')->andReturnUsing(function () use (&$ingested) {
            $ingested++;

            return [];
        });
        $during = null;

        DB::transaction(function () use (&$ingested, &$during) {
            AutoSyncConfiguredModel::create(['name' => 'later']);
            $during = $ingested;
        });

        $this->assertSame(0, $during, 'nothing leaves the database before the commit');
        $this->assertSame(1, $ingested);
    }

    /** @test */
    public function a_rolled_back_transaction_syncs_nothing(): void
    {
        $this->mockAi()->shouldReceive('ingest')->never();

        try {
            DB::transaction(function () {
                AutoSyncConfiguredModel::create(['name' => 'gone']);
                throw new \RuntimeException('the write is refused');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, AutoSyncConfiguredModel::count());
    }

    /** @test */
    public function a_rolled_back_savepoint_inside_a_committed_transaction_syncs_nothing_of_it(): void
    {
        $names = [];
        $this->mockAi()->shouldReceive('ingest')->andReturnUsing(function ($model) use (&$names) {
            $names[] = $model->name;

            return [];
        });

        DB::transaction(function () {
            AutoSyncConfiguredModel::create(['name' => 'kept']);
            try {
                DB::transaction(function () {
                    AutoSyncConfiguredModel::create(['name' => 'undone']);
                    throw new \RuntimeException('the inner write is refused');
                });
            } catch (\RuntimeException) {
            }
        });

        $this->assertSame(['kept'], $names);
    }

    /** @test */
    public function a_queued_sync_is_pushed_only_once_the_transaction_commits(): void
    {
        config(['ai.auto_sync.queue' => true]);
        Queue::fake();

        try {
            DB::transaction(function () {
                AutoSyncConfiguredModel::create(['name' => 'gone']);
                throw new \RuntimeException('the write is refused');
            });
        } catch (\RuntimeException) {
        }
        Queue::assertNothingPushed();

        $pushedDuring = null;
        DB::transaction(function () use (&$pushedDuring) {
            AutoSyncConfiguredModel::create(['name' => 'kept']);
            $pushedDuring = Queue::pushed(IngestEntityJob::class)->count();
        });

        $this->assertSame(0, $pushedDuring);
        Queue::assertPushed(IngestEntityJob::class, 1);
    }

    /** @test */
    public function with_after_commit_off_the_sync_runs_inside_the_transaction_as_before(): void
    {
        config(['ai.auto_sync.after_commit' => false]);
        $ingested = 0;
        $this->mockAi()->shouldReceive('ingest')->andReturnUsing(function () use (&$ingested) {
            $ingested++;

            return [];
        });

        try {
            DB::transaction(function () {
                AutoSyncConfiguredModel::create(['name' => 'gone']);
                throw new \RuntimeException('the write is refused');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, $ingested);
    }
}

class AutoSyncUnconfiguredModel extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $table = 'autosync_test_models';
    protected $fillable = ['name'];
    protected $aiEagerLoadRelationships = false;
}

class AutoSyncConfiguredModel extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $table = 'autosync_test_models';
    protected $fillable = ['name'];
    protected $aiEagerLoadRelationships = false;

    public function nodeableConfig(): array
    {
        return [
            'graph' => ['label' => 'AutoSyncThing', 'properties' => ['id', 'name'], 'relationships' => []],
        ];
    }
}
