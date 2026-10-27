<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Admin;

use Carbon\Carbon;
use Condoedge\Ai\AiServiceProvider;
use Condoedge\Ai\DTOs\Tasks\TaskLimits;
use Condoedge\Ai\Kompo\Admin\AdminConversationsTable;
use Condoedge\Ai\Kompo\Admin\AdminTranscriptModal;
use Condoedge\Ai\Services\Admin\ConversationInsights;
use Condoedge\Ai\Services\Admin\InsightsRange;
use Condoedge\Ai\Services\Tasks\TaskStore;
use Condoedge\Ai\Tests\Fixtures\AdminInsightsSeed;
use Condoedge\Ai\Tests\Fixtures\AdminTestUser;
use Condoedge\Ai\Tests\Fixtures\Tasks\BookRoomTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;

/**
 * The task side of the admin conversations page: a transcript shows each task's audit
 * timeline and whether its hash chain holds; the conversations table filters on tasks.
 */
class AdminTaskTimelineTest extends TestCase
{
    use AdminInsightsSeed;

    private AdminTestUser $admin;

    protected function getPackageProviders($app): array
    {
        return [\Kompo\KompoServiceProvider::class, AiServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('auth.providers.users.model', AdminTestUser::class);
        $app['config']->set('ai.tasks', ['enabled' => true, 'definitions' => [BookRoomTask::class]]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__ . '/../../../vendor/condoedge/utils/src/Helpers/elements/*.php') ?: [] as $file) {
            require_once $file;
        }
        $this->createHostTables();
        $this->seedInsights();
        $this->admin = AdminTestUser::create(['id' => 50, 'name' => 'Super Admin', 'email' => 'admin@example.com']);
        Gate::define(ConversationInsights::ABILITY, fn ($user) => (int) $user->id === 50);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A task on conversation A with a value change, a personal value and a refused confirm. */
    private function taskOnA(): \Condoedge\Ai\Models\AiTask
    {
        $store = new TaskStore();
        $task = $store->create('book_room', $this->conversations['A']->id, 1, 10, new TaskLimits());
        $store->append($task, 'started', ['actor_user_id' => 1]);
        $store->append($task, 'extracted', ['actor_user_id' => 1, 'field' => 'room', 'value_before' => 'small', 'value_after' => 'large']);
        $store->append($task, 'extracted', ['actor_user_id' => 1, 'field' => 'email', 'value_after' => 'provided']);
        $store->append($task, 'confirm_refused', ['actor_user_id' => 1, 'payload' => ['reason' => 'stale', 'token' => 'abc']]);

        return $task;
    }

    private function transcript(): string
    {
        $modal = (new AdminTranscriptModal(['conversation' => $this->conversations['A']->uuid]))->bootForDisplay();

        return (string) json_encode($modal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @test */
    public function the_transcript_shows_the_task_timeline_and_that_its_chain_holds(): void
    {
        $this->taskOnA();

        $json = $this->transcript();

        $this->assertStringContainsString('book_room', $json);
        foreach (['started', 'extracted', 'confirm_refused'] as $kind) {
            $this->assertStringContainsString($kind, $json);
        }
        $this->assertStringContainsString('room: small → large', $json);
        $this->assertStringContainsString('email: → ' . __('ai.admin.task.provided'), $json);
        $this->assertStringContainsString(__('ai.admin.task.actor', ['id' => 1]), $json);
        $this->assertStringContainsString(__('ai.admin.task.chain-ok'), $json);
        $this->assertStringNotContainsString(__('ai.admin.task.chain-broken'), $json);
    }

    /** @test */
    public function an_edited_event_shows_the_chain_broken(): void
    {
        $task = $this->taskOnA();
        DB::table('ai_task_events')->where('task_id', $task->id)->where('field', 'room')->update(['value_after' => json_encode('small')]);

        $this->assertStringContainsString(__('ai.admin.task.chain-broken'), $this->transcript());
    }

    /** @test */
    public function the_conversations_table_filters_on_tasks(): void
    {
        $this->taskOnA();
        $range = InsightsRange::fromInput(30, null, null);

        $titles = app(ConversationInsights::class)->conversationsQuery($range, ['tasks' => '1'])->get()->pluck('title')->all();
        $this->assertSame(['Units question'], $titles);
        $this->assertCount(3, app(ConversationInsights::class)->conversationsQuery($range, ['tasks' => ''])->get());

        $table = (string) json_encode((new AdminConversationsTable(['days' => 30]))->bootForDisplay());
        $this->assertStringContainsString(__('ai.admin.filter.tasks'), $table);
        config(['ai.tasks.enabled' => false]);
        $table = (string) json_encode((new AdminConversationsTable(['days' => 30]))->bootForDisplay());
        $this->assertStringNotContainsString(__('ai.admin.filter.tasks'), $table);
    }

    /** @test */
    public function the_tasks_and_negative_feedback_filters_together_list_the_task_conversations_with_a_thumbs_down(): void
    {
        $this->taskOnA();
        $onB = (new TaskStore())->create('book_room', $this->conversations['B']->id, 2, 20, new TaskLimits());
        $range = InsightsRange::fromInput(30, null, null);
        $titles = fn () => app(ConversationInsights::class)
            ->conversationsQuery($range, ['tasks' => '1', 'feedback' => 'negative'])->get()->pluck('title')->all();

        $this->assertSame(['Units question'], $titles(), 'a task conversation without a 👎 is left out');

        $this->message('B', 'assistant', 'Room booked.', Carbon::now()->subDays(4), ['task' => ['uuid' => $onB->uuid, 'status' => 'done'], 'feedback' => 'negative']);

        $this->assertEqualsCanonicalizing(['Units question', 'Bills'], $titles());
    }
}
