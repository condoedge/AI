<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Tasks;

use Condoedge\Ai\DTOs\Tasks\TaskLimits;
use Condoedge\Ai\Exceptions\TaskVersionConflictException;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Models\AiTaskEvent;
use Condoedge\Ai\Services\Tasks\TaskStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;

class TaskStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    private function newTask(TaskStore $store): AiTask
    {
        $conversation = AiConversation::create(['status' => 'active']);

        return $store->create('book_room', $conversation->id, 7, 3, new TaskLimits(ttlSeconds: 3600));
    }

    /** @test */
    public function it_creates_a_collecting_task_at_version_one_with_its_expiry(): void
    {
        $this->travelTo('2026-10-23 10:00:00');
        $task = $this->newTask(new TaskStore());

        $this->assertNotEmpty($task->uuid);
        $this->assertSame(AiTask::STATUS_COLLECTING, $task->status);
        $this->assertSame(1, $task->version);
        $this->assertSame([], $task->draft()->values);
        $this->assertSame(1, $task->draft()->version);
        $this->assertSame('2026-10-23 11:00:00', $task->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $task->turns);
    }

    /** @test */
    public function it_loads_a_task_by_uuid_for_update(): void
    {
        $store = new TaskStore();
        $task = $this->newTask($store);

        $loaded = DB::transaction(fn () => $store->loadForUpdate($task->uuid));

        $this->assertTrue($loaded->is($task));
        $this->assertNull(DB::transaction(fn () => $store->loadForUpdate('missing')));
    }

    /** @test */
    public function a_save_bumps_the_version_and_persists_draft_and_attributes(): void
    {
        $store = new TaskStore();
        $task = $this->newTask($store);

        $draft = $store->save($task, $task->draft()->with('room', 'small')->withGroup('equipment'), ['turns' => 1, 'status' => AiTask::STATUS_REVIEWING]);

        $this->assertSame(2, $draft->version);
        $fresh = $task->fresh();
        $this->assertSame(2, $fresh->version);
        $this->assertSame(1, $fresh->turns);
        $this->assertSame(AiTask::STATUS_REVIEWING, $fresh->status);
        $this->assertSame(['room' => 'small'], $fresh->draft()->values);
        $this->assertSame(['equipment'], $fresh->draft()->groups);
        $this->assertSame(2, $task->version, 'the in-memory task follows the save');
    }

    /** @test */
    public function a_save_from_a_stale_version_fails_and_changes_nothing(): void
    {
        $store = new TaskStore();
        $task = $this->newTask($store);
        $stale = AiTask::find($task->id);

        $store->save($task, $task->draft()->with('room', 'small'), ['turns' => 1]);

        try {
            $store->save($stale, $stale->draft()->with('room', 'large'), ['turns' => 9]);
            $this->fail('a stale save must be refused');
        } catch (TaskVersionConflictException) {
        }

        $fresh = $task->fresh();
        $this->assertSame(2, $fresh->version);
        $this->assertSame(1, $fresh->turns);
        $this->assertSame(['room' => 'small'], $fresh->draft()->values);
    }

    /** @test */
    public function events_form_a_hash_chain_per_task(): void
    {
        $store = new TaskStore();
        $task = $this->newTask($store);
        $other = $this->newTask($store);

        $first = $store->append($task, 'started', ['actor_user_id' => 7, 'ip' => '10.0.0.1']);
        $store->append($other, 'started');
        $second = $store->append($task, 'extracted', ['field' => 'room', 'value_before' => null, 'value_after' => 'small', 'payload' => ['raw' => ['b' => 1, 'a' => 'é']]]);

        $this->assertNull($first->prev_hash);
        $this->assertSame($first->hash, $second->prev_hash);
        $this->assertSame(64, strlen($second->hash));
        $this->assertTrue($store->verifyChain($task));
        $this->assertTrue($store->verifyChain($other));
    }

    /** @test */
    public function an_unknown_event_kind_is_refused(): void
    {
        $store = new TaskStore();
        $task = $this->newTask($store);

        $this->expectException(\InvalidArgumentException::class);
        $store->append($task, 'tweaked');
    }

    /** @test */
    public function events_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $store = new TaskStore();
        $event = $store->append($this->newTask($store), 'started');

        try {
            $event->update(['kind' => 'cancelled']);
            $this->fail('an event must not be updated');
        } catch (\LogicException) {
        }

        $this->expectException(\LogicException::class);
        $event->delete();
    }

    /** @test */
    public function the_chain_detects_an_edited_event(): void
    {
        [$store, $task, $events] = $this->chainOfThree();

        DB::table('ai_task_events')->where('id', $events[1]->id)->update(['value_after' => json_encode('large')]);

        $this->assertFalse($store->verifyChain($task));
    }

    /** @test */
    public function the_chain_detects_an_edited_event_whose_hash_was_recomputed(): void
    {
        [$store, $task, $events] = $this->chainOfThree();

        DB::table('ai_task_events')->where('id', $events[1]->id)->update(['kind' => 'rejected_value', 'hash' => str_repeat('a', 64)]);

        $this->assertFalse($store->verifyChain($task));
    }

    /** @test */
    public function the_chain_detects_a_deleted_event(): void
    {
        [$store, $task, $events] = $this->chainOfThree();

        DB::table('ai_task_events')->where('id', $events[1]->id)->delete();

        $this->assertFalse($store->verifyChain($task));
    }

    /** @test */
    public function the_chain_detects_a_deleted_newest_event(): void
    {
        [$store, $task, $events] = $this->chainOfThree();

        $this->assertSame($events[2]->hash, $task->fresh()->last_event_hash);
        DB::table('ai_task_events')->where('id', $events[2]->id)->delete();

        $this->assertFalse($store->verifyChain($task));
    }

    /** @test */
    public function record_writes_counters_and_status_without_bumping_the_version(): void
    {
        $store = new TaskStore();
        $task = $this->newTask($store);
        $draft = $store->save($task, $task->draft()->with('room', 'small'));

        $store->record($task, ['turns' => 3, 'llm_calls' => 4, 'tokens' => 900, 'status' => AiTask::STATUS_REVIEWING]);

        $fresh = $task->fresh();
        $this->assertSame(2, $fresh->version);
        $this->assertSame([3, 4, 900, AiTask::STATUS_REVIEWING], [$fresh->turns, $fresh->llm_calls, $fresh->tokens, $fresh->status]);
        $this->assertSame(3, $task->turns, 'the in-memory task follows the write');
        $this->assertSame(2, $draft->version);
    }

    /** @test */
    public function record_refuses_the_draft_and_the_version(): void
    {
        $store = new TaskStore();
        $task = $this->newTask($store);

        foreach (['draft' => ['values' => []], 'version' => 9] as $column => $value) {
            try {
                $store->record($task, [$column => $value]);
                $this->fail("record must refuse [{$column}]");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame(1, $task->fresh()->version);
    }

    /** @return array{TaskStore, AiTask, list<AiTaskEvent>} */
    private function chainOfThree(): array
    {
        $store = new TaskStore();
        $task = $this->newTask($store);
        $events = [
            $store->append($task, 'started', ['actor_user_id' => 7]),
            $store->append($task, 'extracted', ['field' => 'room', 'value_after' => 'small', 'payload' => ['amount' => 12.5]]),
            $store->append($task, 'asked', ['payload' => ['fields' => ['day']]]),
        ];
        $this->assertTrue($store->verifyChain($task));

        return [$store, $task, $events];
    }
}
