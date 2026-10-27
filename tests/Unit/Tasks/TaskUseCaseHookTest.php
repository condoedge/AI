<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Tasks;

use Condoedge\Ai\AiServiceProvider;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\Tasks\TaskLimits;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Models\AiTaskEvent;
use Condoedge\Ai\Services\Tasks\TaskOffer;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;
use Condoedge\Ai\Services\Tasks\TaskStepCaller;
use Condoedge\Ai\Services\Tasks\TaskStore;
use Condoedge\Ai\Tests\Fixtures\ScriptedJsonLlm;
use Condoedge\Ai\Tests\Fixtures\Tasks\BookRoomTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * The use case hands a turn to the task orchestrator only when tasks are on, a definition
 * is registered and the conversation has an active task; otherwise the turn is untouched.
 * An answer offering a task says so; the next message may start it; a message about
 * something else during a task gets the pipeline's answer and the task's pending question.
 */
class TaskUseCaseHookTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('entities', []);
        $app['config']->set('ai.trace.enabled', false);
        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai', ['api_key' => 'sk-test', 'model' => 'gpt-4o']);
        $app['config']->set('ai.embedding.openai', ['api_key' => 'sk-test', 'model' => 'text-embedding-3-small', 'dimensions' => 1536]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function conversationWithTask(): AiConversation
    {
        $conversation = AiConversation::create(['user_id' => 7, 'team_id' => 3]);
        (new TaskStore())->create('book_room', $conversation->id, 7, 3, new TaskLimits());

        return $conversation;
    }

    private function ask(AiConversation $conversation, string $question = 'The large room'): \Condoedge\Ai\Services\Chat\Results\ChatTurnResult
    {
        return $this->app->make(AskQuestionUseCase::class)->execute(new AskQuestionInput(
            question: $question,
            conversation: $conversation,
            style: 'friendly',
            user: (object) ['id' => 7, 'current_team_id' => 3],
        ));
    }

    private function pipeline(int $times): void
    {
        $ai = Mockery::mock();
        $this->app->instance('ai', $ai);
        AI::swap($ai);
        $ai->shouldReceive('answerQuestion')->times($times)->andReturn(['answer' => 'Pipeline answer.', 'cypher' => null, 'data' => [], 'referenced_files' => []]);
    }

    /** @return array<string, array> config => expected orchestrator */
    public static function unconfigured(): array
    {
        return [
            'tasks off' => [['enabled' => false, 'definitions' => [BookRoomTask::class]]],
            'no definition' => [['enabled' => true, 'definitions' => []]],
            'no tasks block' => [null],
        ];
    }

    /**
     * @test
     * @dataProvider unconfigured
     */
    public function unconfigured_the_hook_is_inert_and_the_turn_unchanged(?array $tasks): void
    {
        config(['ai.tasks' => $tasks]);
        $this->pipeline(1);
        $conversation = $this->conversationWithTask();

        $result = $this->ask($conversation);

        $this->assertSame('Pipeline answer.', $result->answer);
        $this->assertSame(['referenced_files' => [], 'regenerated' => false], (array) $result->assistantMessage->fresh()->metadata);
        $this->assertSame(0, AiTask::sole()->turns);
        $this->assertNull($conversation->messages()->where('role', 'user')->sole()->metadata);
    }

    /** @test */
    public function configured_an_active_task_takes_the_turn_after_admission(): void
    {
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        $this->pipeline(0);
        $this->app->instance(TaskStepCaller::class, new TaskStepCaller(new ScriptedJsonLlm([[
            'updates' => [['field' => 'room', 'value' => 'large']], 'question' => 'Which day?', 'choices' => [],
            'explanation' => null, 'wants_to_cancel' => false, 'asks_about' => 'day',
        ]])));
        $executor = Mockery::mock(QueryExecutor::class);
        $executor->shouldReceive('admit')->once()->withArgs(fn (array $options) => $options['user']->id === 7
            && str_starts_with($options['rate_limit_batch'], 'task:'))->andReturn(['admitted' => true, 'waited_seconds' => 0, 'retry_after' => 0]);
        $this->app->instance(QueryExecutorInterface::class, $executor);

        $result = $this->ask($this->conversationWithTask());

        $this->assertSame('Which day?', $result->answer);
        $task = AiTask::sole();
        $this->assertSame(['room' => 'large'], $task->draft()->values);
        $this->assertSame($task->uuid, $result->assistantMessage->fresh()->metadata['task']['uuid']);
        $answer = $result->assistantMessage->conversation->messages()->where('role', 'user')->sole();
        $this->assertSame(['uuid' => $task->uuid], $answer->metadata['task'], 'the user message of a task turn is marked too');
    }

    /** @test */
    public function configured_a_busy_budget_refuses_the_task_turn_before_any_model_call(): void
    {
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        $this->pipeline(0);
        $this->app->instance(TaskStepCaller::class, new TaskStepCaller(new ScriptedJsonLlm([])));
        $executor = Mockery::mock(QueryExecutor::class);
        $executor->shouldReceive('admit')->once()->andReturn(['admitted' => false, 'waited_seconds' => 0, 'retry_after' => 12]);
        $this->app->instance(QueryExecutorInterface::class, $executor);

        $result = $this->ask($this->conversationWithTask());

        $this->assertStringContainsString('12', $result->answer);
        $this->assertSame(0, AiTask::sole()->turns);
    }

    /** @test */
    public function configured_a_conversation_without_an_active_task_runs_the_pipeline(): void
    {
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        $this->pipeline(1);

        $result = $this->ask(AiConversation::create(['user_id' => 7]));

        $this->assertSame('Pipeline answer.', $result->answer);
    }

    private function admitted(): void
    {
        $executor = Mockery::mock(QueryExecutor::class);
        $executor->shouldReceive('admit')->andReturn(['admitted' => true, 'waited_seconds' => 0, 'retry_after' => 0]);
        $this->app->instance(QueryExecutorInterface::class, $executor);
    }

    private function offerLlm(array $replies): ScriptedJsonLlm
    {
        $llm = new ScriptedJsonLlm($replies);
        $this->app->instance(TaskOffer::class, new TaskOffer($llm));

        return $llm;
    }

    private function stepLlm(array $replies): ScriptedJsonLlm
    {
        $llm = new ScriptedJsonLlm($replies);
        $this->app->instance(TaskStepCaller::class, new TaskStepCaller($llm));

        return $llm;
    }

    private static function startChip(): array
    {
        return ['key' => 'start_task', 'entity_type' => null, 'entity_id' => null, 'params' => ['key' => 'book_room'], 'text' => 'book.start'];
    }

    /** A conversation whose last answer offered the task. */
    private function offeredConversation(): AiConversation
    {
        $conversation = AiConversation::create(['user_id' => 7, 'team_id' => 3]);
        $conversation->addMessage('user', 'How do I book a room?');
        $conversation->addMessage('assistant', 'From the rooms page.', ['metadata' => ['invocations' => [self::startChip()]]]);

        return $conversation;
    }

    private static function step(array $updates = [], string $question = '', bool $cancel = false, bool $aboutTask = true): array
    {
        return ['updates' => $updates, 'question' => $question, 'choices' => [], 'explanation' => null, 'wants_to_cancel' => $cancel,
            'asks_about' => null, 'about_task' => $aboutTask];
    }

    /** @test */
    public function an_answer_with_a_start_chip_says_it_can_do_it_here_without_a_model_call(): void
    {
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        $ai = Mockery::mock();
        AI::swap($ai);
        $ai->shouldReceive('answerQuestion')->once()->andReturn(['answer' => 'From the rooms page.', 'cypher' => null, 'data' => [], 'referenced_files' => [],
            'metadata' => ['response' => ['invocations' => [self::startChip()]]]]);
        $offer = $this->offerLlm([]);

        $result = $this->ask(AiConversation::create(['user_id' => 7]), 'How do I book a room?');

        $expected = "From the rooms page.\n\n" . __('ai.tasks.offer', ['task' => 'book.start']);
        $this->assertNotSame('ai.tasks.offer', __('ai.tasks.offer'));
        $this->assertSame($expected, $result->answer);
        $this->assertSame($expected, $result->assistantMessage->fresh()->content);
        $this->assertSame([], $offer->calls);
    }

    /** @test */
    public function the_offer_line_is_written_in_the_questions_language_not_the_apps(): void
    {
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        app()->setLocale('fr');
        $ai = Mockery::mock();
        AI::swap($ai);
        $ai->shouldReceive('answerQuestion')->once()->andReturn(['answer' => 'From the rooms page.', 'cypher' => null, 'data' => [], 'referenced_files' => [],
            'metadata' => ['question_language' => 'en', 'response' => ['invocations' => [self::startChip()]]]]);
        $this->offerLlm([]);

        $result = $this->ask(AiConversation::create(['user_id' => 7]), 'How do I book a room?');

        $this->assertNotSame(__('ai.tasks.offer', [], 'en'), __('ai.tasks.offer', [], 'fr'));
        $this->assertSame("From the rooms page.\n\n" . __('ai.tasks.offer', ['task' => 'book.start'], 'en'), $result->answer);
    }

    /** @test */
    public function a_reply_taking_up_the_offer_starts_the_task_with_the_value_it_gives(): void
    {
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        $this->pipeline(0);
        $this->admitted();
        $offer = $this->offerLlm([['task' => 'book_room']]);
        $step = $this->stepLlm([self::step([['field' => 'room', 'value' => 'large']], 'Which day?')]);

        $result = $this->ask($this->offeredConversation(), 'The large room');

        $task = AiTask::sole();
        $this->assertSame('Which day?', $result->answer);
        $this->assertSame(['room' => 'large'], $task->draft()->values);
        $this->assertSame([1, 1], [count($offer->calls), count($step->calls)]);
        $this->assertSame('offer_reply', AiTaskEvent::where('task_id', $task->id)->where('kind', 'started')->sole()->payload['reason']);
        $this->assertSame($task->uuid, $result->assistantMessage->fresh()->metadata['task']['uuid']);
        $reply = $result->assistantMessage->conversation->messages()->where('role', 'user')->orderByDesc('id')->first();
        $this->assertSame(['uuid' => $task->uuid], $reply->metadata['task'], "the reply is the task's first answer");
    }

    /** @return array<string, array> */
    public static function notTakenUp(): array
    {
        return [
            'a question' => [['task' => null]],
            'a failed call' => [new \RuntimeException('provider down')],
        ];
    }

    /**
     * @test
     * @dataProvider notTakenUp
     */
    public function a_reply_not_taking_up_the_offer_runs_the_pipeline(array|\Throwable $reply): void
    {
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        $this->pipeline(1);
        $offer = $this->offerLlm([$reply]);

        $result = $this->ask($this->offeredConversation(), 'How much is the large room?');

        $this->assertSame('Pipeline answer.', $result->answer);
        $this->assertSame(0, AiTask::count());
        $this->assertCount(1, $offer->calls);
    }

    /** @test */
    public function only_the_message_right_after_an_offer_makes_the_offer_call(): void
    {
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        $this->pipeline(2);
        $offer = $this->offerLlm([]);
        $later = $this->offeredConversation();
        $later->addMessage('user', 'How much is it?');
        $later->addMessage('assistant', 'It is free.');

        $this->ask($later, 'The large room');
        $this->ask(AiConversation::create(['user_id' => 7]), 'The large room');

        $this->assertSame([], $offer->calls);
        $this->assertSame(0, AiTask::count());
    }

    /** @test */
    public function a_question_during_a_task_gets_the_pipelines_answer_then_the_tasks_pending_question(): void
    {
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        $this->admitted();
        $this->stepLlm([self::step([], 'Which room?', aboutTask: false)]);
        $ai = Mockery::mock();
        AI::swap($ai);
        $ai->shouldReceive('answerQuestion')->once()->withArgs(fn (string $question) => $question === 'Can guests park on site?')
            ->andReturn(['answer' => 'Yes, behind the building.', 'cypher' => null, 'data' => [], 'referenced_files' => [],
                'metadata' => ['response' => ['invocations' => [self::startChip()]]]]);
        $conversation = $this->conversationWithTask();

        $result = $this->ask($conversation, 'Can guests park on site?');

        $task = AiTask::sole();
        $pending = __('ai.tasks.ask-fields', ['fields' => 'book.room, book.day, book.attendees']);
        $this->assertSame("Yes, behind the building.\n\n" . $pending, $result->answer, 'no offer line while a task runs');
        $this->assertSame([AiTask::STATUS_COLLECTING, [], 1], [$task->status, $task->draft()->values, $task->version]);
        $metadata = $result->assistantMessage->fresh()->metadata;
        $this->assertTrue($metadata['task']['side_question']);
        $this->assertSame($task->uuid, $metadata['task']['uuid']);
        $this->assertSame([self::startChip()], $metadata['invocations'], 'the answer keeps its own chips');
    }

    /** @test */
    public function a_typed_cancel_during_a_task_still_cancels_it(): void
    {
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        $this->pipeline(0);
        $this->admitted();
        $this->stepLlm([self::step([], '', cancel: true, aboutTask: false)]);

        $result = $this->ask($this->conversationWithTask(), 'cancel');

        $this->assertSame(AiTask::STATUS_CANCELLED, AiTask::sole()->status);
        $this->assertSame(__('ai.tasks.cancelled'), $result->answer);
    }

    /** @test */
    public function the_orchestrator_is_given_to_the_use_case_only_when_configured(): void
    {
        $tasks = fn () => (new \ReflectionProperty(AskQuestionUseCase::class, 'tasks'))->getValue($this->app->make(AskQuestionUseCase::class));

        config(['ai.tasks' => ['enabled' => false, 'definitions' => [BookRoomTask::class]]]);
        $this->assertNull($tasks());

        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        $this->app->instance(TaskStepCaller::class, new TaskStepCaller(new ScriptedJsonLlm([])));
        $this->assertInstanceOf(TaskOrchestrator::class, $tasks());
    }

    /** @test */
    public function the_offer_call_uses_its_role_else_the_task_step_role_else_the_small_understanding_model(): void
    {
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class]]]);
        $offers = fn () => (new \ReflectionProperty(AskQuestionUseCase::class, 'offers'))->getValue($this->app->make(AskQuestionUseCase::class));
        $model = function () use ($offers) {
            $this->app->forgetInstance(TaskOffer::class);
            $this->app->forgetInstance(\Condoedge\Ai\Services\LlmResolver::class);

            return (new \ReflectionProperty(TaskOffer::class, 'llm'))->getValue($offers())->getModel();
        };

        config(['ai.llm_roles' => ['query_understanding' => ['model' => 'small-model']]]);
        $this->assertSame('small-model', $model());
        config(['ai.llm_roles' => ['query_understanding' => ['model' => 'small-model'], 'task_orchestrator' => ['model' => 'task-model']]]);
        $this->assertSame('task-model', $model());
        config(['ai.llm_roles' => ['task_orchestrator' => ['model' => 'task-model'], 'task_offer' => ['model' => 'offer-model']]]);
        $this->assertSame('offer-model', $model());

        config(['ai.tasks.enabled' => false]);
        $this->assertNull($offers());
    }

    /** @test */
    public function the_step_call_uses_the_task_orchestrator_role_else_the_small_understanding_model(): void
    {
        $model = fn () => (new \ReflectionProperty(TaskStepCaller::class, 'llm'))->getValue($this->app->make(TaskStepCaller::class))->getModel();

        config(['ai.llm_roles' => ['query_understanding' => ['model' => 'small-model']]]);
        $this->assertSame('small-model', $model());

        $this->app->forgetInstance(TaskStepCaller::class);
        $this->app->forgetInstance(\Condoedge\Ai\Services\LlmResolver::class);
        config(['ai.llm_roles' => ['query_understanding' => ['model' => 'small-model'], 'task_orchestrator' => ['model' => 'task-model']]]);
        $this->assertSame('task-model', $model());
    }
}
