<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Tasks;

use Condoedge\Ai\DTOs\Tasks\TaskLimits;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\PendingActionToken;
use Condoedge\Ai\Services\Tasks\TaskStore;
use Condoedge\Ai\Tests\Unit\Services\Actions\SpyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

class TaskTokenClaimsTest extends TestCase
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
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    private function task(?int $teamId = 3): AiTask
    {
        $conversation = AiConversation::create(['status' => 'active']);

        return (new TaskStore())->create('book_room', $conversation->id, 7, $teamId, new TaskLimits());
    }

    private function dispatcher(SpyAction $action): ActionDispatcher
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric($action);

        return new ActionDispatcher($registry);
    }

    /** @test */
    public function a_token_without_task_claims_keeps_todays_payload_and_approval(): void
    {
        $token = PendingActionToken::issue('charge', null, ['x' => 1], 7);

        $this->assertSame(['k', 'e', 'i', 'u', 't', 'n'], array_keys($token->toPayload()));
        $decoded = PendingActionToken::decode($token->encode());
        $this->assertNull($decoded->taskUuid);
        $this->assertNull($decoded->taskVersion);

        $action = new SpyAction('charge', requiresApproval: true, authorized: true);
        $this->assertFalse($this->dispatcher($action)->approve($token->encode(), ['user_id' => 7])->isError);
        $this->assertTrue($action->handled);
    }

    /** @test */
    public function a_task_token_carries_the_task_its_draft_version_and_team(): void
    {
        $task = $this->task();

        $decoded = PendingActionToken::decode(PendingActionToken::issueForTask('task:book_room', 7, $task)->encode());

        $this->assertSame($task->uuid, $decoded->taskUuid);
        $this->assertSame(1, $decoded->taskVersion);
        $this->assertSame(3, $decoded->teamId);
        $this->assertTrue($decoded->bindsCurrent($task));
    }

    /** @test */
    public function approve_refuses_a_task_token_its_registry_holds_no_action_for(): void
    {
        $token = PendingActionToken::issueForTask('task:book_room', 7, $this->task())->encode();

        $action = new SpyAction('charge', requiresApproval: true, authorized: true);

        $this->assertTrue($this->dispatcher($action)->approve($token, ['user_id' => 7])->isError, 'only TaskOrchestrator::confirm runs a task token');
        $this->assertFalse($action->handled);
    }
}
