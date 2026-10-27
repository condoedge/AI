<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\DTOs\Tasks\TaskSummary;
use Condoedge\Ai\Kompo\Actions\TaskSummaryCard;
use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Kompo\Support\WelcomeSuggestions;
use Condoedge\Ai\Kompo\Presenters\ChatPresenter;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Models\AiTaskEvent;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\Settings\UserChatSettings;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;
use Condoedge\Ai\Services\Tasks\TaskPlanner;
use Condoedge\Ai\Services\Tasks\TaskStepCaller;
use Condoedge\Ai\Services\Tasks\TaskStore;
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Services\UI\ConfigChatThemeFactory;
use Condoedge\Ai\Tests\Fixtures\ScriptedJsonLlm;
use Condoedge\Ai\Tests\Fixtures\Tasks\BookRoomTask;
use Condoedge\Ai\Tests\Fixtures\Tasks\EditBookingTask;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The chat side of a task: the start chip, the question's choices and progress, the
 * summary card and its Confirm / Cancel / Change round-trips on MessagesQuery.
 */
class TaskChatUiTest extends TestCase
{
    use RefreshDatabase;

    private UiBookRoomTask $definition;
    private ScriptedJsonLlm $llm;

    protected function getPackageProviders($app): array
    {
        return [\Kompo\KompoServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('entities', []);
        $app['config']->set('ai.tasks', ['enabled' => true, 'definitions' => [BookRoomTask::class]]);
        $app['config']->set('ai.chat.example_questions', []);
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
        $icons = public_path('icons');
        if (!is_dir($icons)) {
            mkdir($icons, 0777, true);
        }
        $sources = implode('', array_map('file_get_contents', glob(__DIR__ . '/../../../src/Kompo/{*,*/*}.php', GLOB_BRACE) ?: []));
        preg_match_all("/_Sax\('([a-z0-9-]+)'/", $sources, $used);
        foreach (array_unique($used[1]) as $icon) {
            if (!is_file($icons . '/' . $icon . '.svg')) {
                file_put_contents($icons . '/' . $icon . '.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
            }
        }
        $this->app['translator']->addJsonPath(__DIR__ . '/../../../resources/lang');

        $this->app->singleton(ChatThemeFactoryInterface::class, fn () => new ConfigChatThemeFactory());
        $this->app->singleton(ChatThemeInterface::class, fn ($app) => $app->make(ChatThemeFactoryInterface::class)->create());
        $this->app->singleton(ChatSettingsInterface::class, fn () => new UserChatSettings());
        $this->app->instance(ContentLinkProcessor::class, new ContentLinkProcessor());
        $service = Mockery::mock(AiChatServiceInterface::class);
        $service->shouldReceive('isAvailable')->andReturn(true);
        $this->app->instance(AiChatServiceInterface::class, $service);

        $this->definition = new UiBookRoomTask();
        $this->app->instance(BookRoomTask::class, $this->definition);
        $this->actingAs(new TaskUiUser(7, 3));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private static function reply(array $updates = [], string $question = '', array $choices = [], ?string $asksAbout = null): array
    {
        return ['updates' => array_map(fn ($value, $field) => ['field' => $field, 'value' => $value], $updates, array_keys($updates)),
            'question' => $question, 'choices' => $choices, 'explanation' => null, 'wants_to_cancel' => false, 'asks_about' => $asksAbout];
    }

    private function scripted(array $replies): TaskOrchestrator
    {
        $this->llm = new ScriptedJsonLlm($replies);
        $orchestrator = new TaskOrchestrator(new TaskStore(), new TaskPlanner(), new TaskStepCaller($this->llm));
        $this->app->instance(TaskOrchestrator::class, $orchestrator);

        return $orchestrator;
    }

    private function conversation(int $userId = 7): AiConversation
    {
        return AiConversation::create(['status' => 'active', 'user_id' => $userId, 'team_id' => 3]);
    }

    /** The panel as a Kompo AJAX call boots it, on the given conversation. */
    private function panel(?AiConversation $conversation): MessagesQuery
    {
        $query = new MessagesQuery(['conversation_id' => $conversation?->id]);
        $query->bootForAction();

        return $query;
    }

    private function displayed(AiConversation $conversation): string
    {
        $query = (new MessagesQuery(['conversation_id' => $conversation->id]))->bootForDisplay();

        return (string) json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function rendered(AiMessage $message): string
    {
        $query = new MessagesQuery(['conversation_id' => $message->conversation_id]);
        $query->bootForAction();
        // As inside a Query's card rendering: the summary card is a Komponent of its own.
        $this->app->instance('bootFlag', true);
        $bubble = $query->render($message->fresh());
        $this->app->instance('bootFlag', false);

        return (string) json_encode($bubble, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The summary card as a Kompo AJAX call (Confirm, Cancel, Change) boots it. */
    private function card(AiMessage $message, ?int $conversationId = null): TaskSummaryCard
    {
        $card = new TaskSummaryCard(null, ['conversation_id' => $conversationId ?? $message->conversation_id, 'message_id' => $message->id]);
        $card->bootForAction();

        return $card;
    }

    private function latest(AiConversation $conversation): AiMessage
    {
        return $conversation->messages()->orderByDesc('id')->first();
    }

    /** Stores an orchestrator response as the use case does. */
    private function answer(AiConversation $conversation, array $response): AiMessage
    {
        return $conversation->addMessage('assistant', $response['answer'], ['metadata' => ['task' => $response['metadata']['task']]]);
    }

    /** A task in review with a catering budget (a money row): its message, task and token. */
    private function reviewing(AiConversation $conversation): array
    {
        $orchestrator = $this->scripted([self::reply([], 'Which room?'), self::reply()]);
        $start = $orchestrator->start(auth()->user(), $conversation, 'book_room');
        $task = AiTask::where('uuid', $start['metadata']['task']['uuid'])->firstOrFail();
        (new TaskStore())->save($task, $task->draft()->with('room', 'large')->with('day', '2026-03-03')->with('attendees', 4)
            ->with('catering', 1)->with('catering_budget', 200)->with('vegan_share', 10)->with('title', 'Board')
            ->withDeclinedGroup('equipment')->withDeclinedGroup('payment')->withDeclinedGroup('extras'));
        $response = $orchestrator->handleTurn($task->fresh(), 'that is all', ['user' => auth()->user()]);

        return [$this->answer($conversation, $response), $task->fresh(), $response['metadata']['task']['token']];
    }

    /** @test */
    public function the_start_chip_shows_only_when_tasks_are_on_and_the_user_may_start(): void
    {
        $this->scripted([]);
        $conversation = $this->conversation();

        $this->assertStringContainsString('Book a room with the assistant', $this->displayed($conversation));

        $this->definition->startable = false;
        $this->assertStringNotContainsString('Book a room with the assistant', $this->displayed($conversation));

        $this->definition->startable = true;
        config(['ai.tasks.enabled' => false]);
        $this->assertStringNotContainsString('Book a room with the assistant', $this->displayed($conversation));
    }

    /** @test */
    public function the_welcome_shows_only_the_listed_tasks_when_a_list_is_set(): void
    {
        $this->scripted([]);
        config(['ai.tasks.definitions' => [BookRoomTask::class, EditBookingTask::class], 'ai.tasks.welcome' => ['edit_booking', 'no_such_task']]);

        $json = $this->displayed($this->conversation());

        $this->assertStringContainsString('Change a booking', $json);
        $this->assertStringNotContainsString('Book a room with the assistant', $json);
        $this->assertSame(['book_room', 'edit_booking'], array_keys(WelcomeSuggestions::tasks(auth()->user())), 'the answer prompt still knows every startable task');
    }

    /** @test */
    public function an_empty_welcome_list_shows_every_startable_task(): void
    {
        $this->scripted([]);
        config(['ai.tasks.definitions' => [BookRoomTask::class, EditBookingTask::class], 'ai.tasks.welcome' => []]);

        $json = $this->displayed($this->conversation());

        $this->assertStringContainsString('Change a booking', $json);
        $this->assertStringContainsString('Book a room with the assistant', $json);
    }

    /** @test */
    public function the_chip_starts_the_task_and_its_first_question_lands_in_the_thread(): void
    {
        $this->scripted([self::reply([], 'Which room?', ['Small room', 'Large room'])]);
        $conversation = $this->conversation();

        $this->panel($conversation)->startTask('book_room');

        $task = AiTask::sole();
        $message = $conversation->messages()->sole();
        $this->assertSame([$conversation->id, 7], [$task->conversation_id, $task->user_id]);
        $this->assertSame(['assistant', 'Which room?'], [$message->role, $message->content]);
        $this->assertSame($task->uuid, $message->metadata['task']['uuid']);

        $json = $this->rendered($message);
        $this->assertStringContainsString('Small room', $json, 'the choices render as chips');
        $this->assertStringContainsString('Step 1 of ~5', $json);
    }

    /** @test */
    public function the_start_chip_of_an_answer_posts_to_the_welcome_chip_handler_and_starts_the_task(): void
    {
        $this->scripted([self::reply([], 'Which room?')]);
        $conversation = $this->conversation();
        $chip = new ActionInvocation('start_task', null, null, ['key' => 'book_room'], 'Book a room with the assistant');
        $answer = $conversation->addMessage('assistant', 'Rooms are booked on the rooms page.', ['metadata' => ['invocations' => [$chip->toArray()]]]);

        $json = $this->rendered($answer);
        $this->assertStringContainsString('Book a room with the assistant', $json);
        $this->assertStringContainsString('"ajaxPayload":{"key":"book_room"},"X-Kompo-Action":"self-method"', $json, 'posts like the welcome chip');

        $this->definition->startable = false;
        $this->assertStringNotContainsString('Book a room with the assistant', $this->rendered($answer), 'refused at render too');
        $this->definition->startable = true;

        $this->panel($conversation)->startTask('book_room');
        $this->assertSame([$conversation->id, 'book_room'], [AiTask::sole()->conversation_id, AiTask::sole()->definition_key]);
        $this->assertSame('Which room?', $this->latest($conversation)->content);
    }

    /** @test */
    public function a_choice_chip_shows_the_label_and_posts_the_value(): void
    {
        $this->scripted([self::reply([], 'Which room?', ['1', '2'], 'room'), self::reply([], 'Which day?')]);
        $conversation = $this->conversation();
        $this->panel($conversation)->startTask('book_room');
        $task = AiTask::sole();

        $json = $this->rendered($conversation->messages()->sole());
        $this->assertStringContainsString('"book.room.large"', $json);
        $this->assertStringNotContainsString('"1"', $json, 'the field labels replace the model choices');
        $this->assertStringContainsString('"ajaxPayload":{"uuid":"' . $task->uuid . '","version":1,"field":"room","value":"\\"large\\""}', $json);

        $this->panel($conversation)->chooseTask($task->uuid, $task->version, 'room', json_encode('large'));

        $this->assertSame('large', $task->fresh()->draft()->get('room'));
        $messages = $conversation->messages()->orderBy('id')->get();
        $this->assertSame([['assistant', 'Which room?'], ['user', 'book.room.large'], ['assistant', 'Which day?']],
            $messages->map(fn ($message) => [$message->role, $message->content])->all());
        $this->assertSame($task->uuid, $messages[1]->metadata['task']['uuid'], 'no edit on the chosen answer');
    }

    /** @test */
    public function the_chip_is_refused_to_a_user_the_definition_does_not_let_start(): void
    {
        $this->scripted([]);
        $this->definition->startable = false;

        try {
            $this->panel($this->conversation())->startTask('book_room');
            $this->fail('startTask must refuse');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, AiTask::count());
    }

    /** @test */
    public function a_question_the_task_moved_past_shows_no_choices_or_progress(): void
    {
        $orchestrator = $this->scripted([self::reply([], 'Which room?', ['Small room', 'Large room']), self::reply(['room' => 'large'], 'Which day?')]);
        $conversation = $this->conversation();
        $this->panel($conversation)->startTask('book_room');
        $first = $conversation->messages()->sole();

        $orchestrator->handleTurn(AiTask::sole(), 'large', ['user' => auth()->user()]);

        $json = $this->rendered($first);
        $this->assertStringNotContainsString('Small room', $json);
        $this->assertStringNotContainsString('Step 1', $json);
    }

    /** @test */
    public function the_summary_card_has_a_change_per_row_a_badge_on_money_rows_and_confirm_cancel(): void
    {
        [$message] = $this->reviewing($this->conversation());

        $json = $this->rendered($message);

        $this->assertSame(7, substr_count($json, '"Change"'), 'one Change per summary row');
        $this->assertSame(1, substr_count($json, '"Money"'), 'the catering budget is the only money row');
        $this->assertSame(1, substr_count($json, '"' . __('ai.tasks.acknowledge') . '"'), 'one acknowledgement, on the money row only');
        $this->assertStringContainsString('"name":"ack_catering_budget"', $json);
        $this->assertStringContainsString('Board', $json);
        $this->assertStringContainsString('"Confirm"', $json);
        $this->assertStringContainsString('"Cancel"', $json);
    }

    /** @test */
    public function the_card_badges_and_asks_to_tick_an_outward_row(): void
    {
        $this->definition->extraRows = [['field' => '_notice', 'label' => 'book.notice', 'value' => 'The attendees get an invitation.', 'risk' => 'outward', 'source' => '']];
        [$message] = $this->reviewing($this->conversation());

        $json = $this->rendered($message);

        $this->assertSame(1, substr_count($json, '"Sent or published"'));
        $this->assertStringContainsString('"name":"ack__notice"', $json);
        $this->assertSame(2, substr_count($json, '"' . __('ai.tasks.acknowledge') . '"'), 'the money row and the outward row');
    }

    /** @test */
    public function the_card_shows_the_value_a_row_replaces(): void
    {
        $this->definition->extraRows = [['field' => 'price', 'label' => 'book.price', 'value' => '150', 'risk' => 'money', 'source' => '', 'before' => '120']];
        [$message] = $this->reviewing($this->conversation());

        $json = $this->rendered($message);

        $this->assertStringContainsString('"was: 120"', $json);
        $this->assertSame(1, substr_count($json, 'was: '), 'only the row that names what it replaces');
    }

    /** @test */
    public function confirm_with_a_stale_version_is_refused_and_audited(): void
    {
        $conversation = $this->conversation();
        [$message, $task, $token] = $this->reviewing($conversation);
        (new TaskStore())->save($task, $task->draft()->with('attendees', 9));

        $this->panel($conversation)->confirmTask($task->uuid, $task->version, $token);

        $this->assertSame(0, $this->definition->executed);
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->fresh()->status);
        $this->assertSame('stale', AiTaskEvent::where('task_id', $task->id)->where('kind', 'confirm_refused')->sole()->payload['reason']);
        $this->assertSame(__('ai.tasks.stale'), $conversation->messages()->orderByDesc('id')->first()->content);
        $this->assertStringNotContainsString('"Confirm"', $this->rendered($message), 'the old card no longer offers confirm');
    }

    /** @test */
    public function confirm_cancel_and_change_go_through_on_the_task_of_this_conversation(): void
    {
        $conversation = $this->conversation();
        [, $task, $token] = $this->reviewing($conversation);

        $this->scripted([self::reply([], 'How many people?')]);
        $this->panel($conversation)->reopenTask($task->uuid, 'attendees');
        $this->assertSame(AiTask::STATUS_COLLECTING, $task->fresh()->status);
        $this->assertSame('How many people?', $conversation->messages()->orderByDesc('id')->first()->content);

        $this->panel($conversation)->cancelTask($task->uuid);
        $this->assertSame(AiTask::STATUS_CANCELLED, $task->fresh()->status);

        [$summary, $other, $otherToken] = $this->reviewing($this->conversation());
        request()->merge(['ack_catering_budget' => 1]);
        $this->card($summary)->confirmTask($other->uuid, $other->version, $otherToken);
        $this->assertSame(1, $this->definition->executed);
        $this->assertSame(AiTask::STATUS_DONE, $other->fresh()->status);
    }

    /** @test */
    public function the_card_confirms_only_once_every_money_row_is_ticked(): void
    {
        $conversation = $this->conversation();
        [$summary, $task, $token] = $this->reviewing($conversation);

        request()->merge(['ack_catering_budget' => 0]);
        $this->card($summary)->confirmTask($task->uuid, $task->version, $token);

        $this->assertSame(0, $this->definition->executed);
        $again = $this->latest($conversation);
        $this->assertStringContainsString(__('ai.tasks.acknowledge-first'), $again->content);
        $this->assertSame(['catering_budget'], AiTaskEvent::where('kind', 'confirm_refused')->sole()->payload['missing']);
        $this->assertStringContainsString('"Confirm"', $this->rendered($again), 'the summary is shown again');

        request()->merge(['ack_catering_budget' => 1]);
        $this->card($again)->confirmTask($task->uuid, $task->version, $again->metadata['task']['token']);

        $this->assertSame(1, $this->definition->executed);
        $this->assertSame(['catering_budget'], AiTaskEvent::where('kind', 'confirmed')->sole()->payload['acknowledged']);
    }

    /** @test */
    public function a_limit_answer_shows_the_manual_form_as_a_button_even_without_a_task(): void
    {
        $conversation = $this->conversation();
        $message = $conversation->addMessage('assistant', __('ai.tasks.daily-limit-reached'), ['metadata' => ['task' => [
            'limit_hit' => 'per_user_per_day', 'link' => ['url' => 'https://example.test/rooms/new', 'label' => 'ai.tasks.manual-form'],
        ]]]);

        $json = $this->rendered($message);
        $this->assertStringContainsString('"href":"https://example.test/rooms/new"', $json);
        $this->assertStringContainsString('"' . __('ai.tasks.manual-form') . '"', $json);
        $this->assertNotSame('ai.tasks.manual-form', __('ai.tasks.manual-form'), 'the label is translated');
    }

    /** @test */
    public function a_done_task_shows_the_link_of_its_outcome_as_a_button(): void
    {
        $conversation = $this->conversation();
        [$summary, $task, $token] = $this->reviewing($conversation);
        $this->definition->link = ['https://example.test/bookings/1', 'Open the booking'];

        request()->merge(['ack_catering_budget' => 1]);
        $this->card($summary)->confirmTask($task->uuid, $task->version, $token);

        $json = $this->rendered($this->latest($conversation));
        $this->assertStringContainsString('"href":"https://example.test/bookings/1"', $json);
        $this->assertStringContainsString('"Open the booking"', $json);
        $this->assertStringNotContainsString('https://example.test/bookings/1', $this->rendered($summary), 'only the outcome carries it');
    }

    /** @test */
    public function a_repeated_confirm_returns_the_follow_ups(): void
    {
        [, $task, $token] = $this->reviewing($this->conversation());
        $this->definition->followUps = [['url' => 'https://example.test/rooms', 'label' => 'Rooms']];
        $orchestrator = app(TaskOrchestrator::class);
        $context = ['user' => auth()->user(), 'acknowledged' => ['catering_budget']];

        $first = $orchestrator->confirm($task, $token, $context);
        $second = $orchestrator->confirm($task->fresh(), $token, $context);

        $this->assertSame(1, $this->definition->executed);
        $this->assertSame([['url' => 'https://example.test/rooms', 'label' => 'Rooms']], $second['metadata']['task']['outcome']['follow_ups']);
        $this->assertSame($first['metadata']['task']['outcome'], $second['metadata']['task']['outcome']);
    }

    /** @test */
    public function a_url_follow_up_renders_as_a_link_chip(): void
    {
        $conversation = $this->conversation();
        [$summary, $task, $token] = $this->reviewing($conversation);
        $this->definition->link = ['https://example.test/bookings/1', 'Open the booking'];
        $this->definition->followUps = [['url' => 'https://example.test/rooms', 'label' => 'ai.tasks.manual-form']];

        request()->merge(['ack_catering_budget' => 1]);
        $this->card($summary)->confirmTask($task->uuid, $task->version, $token);

        $json = $this->rendered($this->latest($conversation));
        $this->assertStringContainsString('"href":"https://example.test/rooms"', $json);
        $this->assertStringContainsString('"' . __('ai.tasks.manual-form') . '"', $json, 'the label is translated');
        $this->assertLessThan(strpos($json, 'https://example.test/rooms'), strpos($json, 'https://example.test/bookings/1'), 'after the outcome link');
    }

    /** @test */
    public function a_task_follow_up_renders_only_while_startable(): void
    {
        $message = $this->outcomeMessage([['task' => 'book_room', 'label' => 'Book another room']]);

        $json = $this->rendered($message);
        $this->assertStringContainsString('"Book another room"', $json);
        $this->assertStringContainsString('"ajaxPayload":{"key":"book_room"},"X-Kompo-Action":"self-method"', $json, 'posts like the start chip');

        $this->definition->startable = false;
        $this->assertStringNotContainsString('Book another room', $this->rendered($message));
    }

    /** @test */
    public function a_task_follow_up_of_an_unregistered_definition_is_skipped(): void
    {
        $message = $this->outcomeMessage([['task' => 'no_such_task', 'label' => 'Ghost task'], ['url' => 'https://example.test/rooms', 'label' => 'Rooms']]);

        $json = $this->rendered($message);
        $this->assertStringNotContainsString('Ghost task', $json);
        $this->assertStringContainsString('"href":"https://example.test/rooms"', $json);
    }

    /** A done task's outcome message carrying these follow-ups. */
    private function outcomeMessage(array $followUps): AiMessage
    {
        $this->scripted([]);
        $outcome = \Condoedge\Ai\DTOs\Tasks\TaskOutcome::success()->withFollowUps($followUps);

        return $this->conversation()->addMessage('assistant', 'Done.', ['metadata' => ['task' => [
            'uuid' => 'task-1', 'definition' => 'book_room', 'status' => AiTask::STATUS_DONE, 'version' => 2, 'outcome' => $outcome->toArray(),
        ]]]);
    }

    /** @test */
    public function a_thumbs_down_on_the_summary_or_the_outcome_is_recorded_and_keeps_the_task(): void
    {
        config(['ai.chat.enable_feedback' => true]);
        $conversation = $this->conversation();
        [$summary, $task, $token] = $this->reviewing($conversation);
        request()->merge(['ack_catering_budget' => 1]);
        $this->card($summary)->confirmTask($task->uuid, $task->version, $token);
        $outcome = $this->latest($conversation);

        foreach (['summary' => $summary, 'outcome' => $outcome] as $kind => $message) {
            $this->assertStringContainsString('"' . __('ai.messages.not-helpful') . '"', $this->rendered($message), "the {$kind} offers 👎");
            $this->panel($conversation)->feedback($message->id, 'negative');

            $metadata = $message->fresh()->metadata;
            $this->assertSame('negative', $metadata['feedback'], $kind);
            $this->assertSame($task->uuid, $metadata['task']['uuid'], "the {$kind} is still a task message");
        }
    }

    /** @test */
    public function cancel_change_or_confirm_of_a_task_from_another_conversation_is_refused(): void
    {
        [, $task, $token] = $this->reviewing($this->conversation());
        $elsewhere = $this->panel($this->conversation());

        foreach ([
            fn () => $elsewhere->cancelTask($task->uuid),
            fn () => $elsewhere->reopenTask($task->uuid, 'attendees'),
            fn () => $elsewhere->confirmTask($task->uuid, $task->version, $token),
        ] as $call) {
            try {
                $call();
                $this->fail('a task of another conversation must be refused');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->fresh()->status);
        $this->assertSame(0, $this->definition->executed);

        $theirs = $this->conversation(8);
        try {
            $this->card($this->latest(AiConversation::find($task->conversation_id)), $theirs->id)->confirmTask($task->uuid, $task->version, $token);
            $this->fail('a card on a conversation of another user must be refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /** @test */
    public function task_messages_offer_no_regenerate_and_task_answers_no_edit(): void
    {
        $conversation = $this->conversation();
        [$message] = $this->reviewing($conversation);
        $taskAnswer = $conversation->addMessage('user', 'large', ['metadata' => ['task' => ['uuid' => 'x']]]);
        $plainQuestion = $conversation->addMessage('user', 'How many units?');
        $plainAnswer = $conversation->addMessage('assistant', 'Twelve.');

        $this->assertStringNotContainsString('"Regenerate"', $this->rendered($message));
        $this->assertStringContainsString('"Regenerate"', $this->rendered($plainAnswer));
        $this->assertStringNotContainsString('"Edit"', $this->rendered($taskAnswer));
        $this->assertStringContainsString('"Edit"', $this->rendered($plainQuestion));

        $presenter = Mockery::mock(ChatPresenter::class);
        $presenter->shouldNotReceive('handleRegenerate');
        $this->app->instance(ChatPresenter::class, $presenter);
        $this->panel($conversation)->regenerate($message->id);
    }
}

/** The fixture with a start label switch and code-built summary rows. */
class UiBookRoomTask extends BookRoomTask
{
    public int $executed = 0;
    public bool $startable = true;
    public ?array $link = null;
    public array $extraRows = [];
    public array $followUps = [];

    public function label(): string
    {
        return 'Book a room with the assistant';
    }

    public function startableBy(mixed $user): bool
    {
        return $this->startable && parent::startableBy($user);
    }

    public function summary(mixed $user, TaskDraft $draft): TaskSummary
    {
        $rows = [];
        foreach ($this->fields($user, $draft) as $field) {
            if ($draft->has($field->key)) {
                $rows[] = ['field' => $field->key, 'label' => $field->labelKey, 'value' => (string) $draft->get($field->key),
                    'risk' => $field->risk, 'source' => 'your choice'];
            }
        }

        return new TaskSummary([...$rows, ...$this->extraRows]);
    }

    public function execute(mixed $user, TaskDraft $draft, AiTask $run): \Condoedge\Ai\DTOs\Tasks\TaskOutcome
    {
        $this->executed++;
        $outcome = parent::execute($user, $draft, $run);

        $outcome = $this->link === null ? $outcome : $outcome->withLink(...$this->link);

        return $this->followUps === [] ? $outcome : $outcome->withFollowUps($this->followUps);
    }
}

class TaskUiUser implements Authenticatable
{
    public function __construct(public int $id, public int $current_team_id)
    {
    }

    public function getKey(): int { return $this->id; }
    public function getAuthIdentifierName() { return 'id'; }
    public function getAuthIdentifier() { return $this->id; }
    public function getAuthPassword() { return ''; }
    public function getAuthPasswordName() { return 'password'; }
    public function getRememberToken() { return null; }
    public function setRememberToken($value) {}
    public function getRememberTokenName() { return 'remember_token'; }
}
