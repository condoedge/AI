<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Tasks;

use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\DTOs\Tasks\TaskField;
use Condoedge\Ai\DTOs\Tasks\TaskLimits;
use Condoedge\Ai\DTOs\Tasks\TaskOutcome;
use Condoedge\Ai\DTOs\Tasks\TaskSummary;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Models\AiTaskEvent;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;
use Condoedge\Ai\Services\Tasks\TaskPlanner;
use Condoedge\Ai\Services\Tasks\TaskStepCaller;
use Condoedge\Ai\Services\Tasks\TaskStore;
use Condoedge\Ai\Tests\Fixtures\ScriptedJsonLlm;
use Condoedge\Ai\Tests\Fixtures\Tasks\BookRoomTask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase;

class TaskOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private SpyBookRoomTask $definition;
    private ScriptedJsonLlm $llm;
    private object $user;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('ai.tasks', ['enabled' => true, 'definitions' => [BookRoomTask::class]]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['translator']->addJsonPath(__DIR__ . '/../../../resources/lang');
        $this->definition = new SpyBookRoomTask();
        $this->app->instance(BookRoomTask::class, $this->definition);
        $this->user = (object) ['id' => 7, 'current_team_id' => 3];
    }

    private static function reply(array $updates = [], string $question = '', bool $cancel = false, ?string $asksAbout = null, bool $aboutTask = true): array
    {
        return ['updates' => array_map(fn ($value, $field) => ['field' => $field, 'value' => $value], $updates, array_keys($updates)),
            'question' => $question, 'choices' => [], 'explanation' => null, 'wants_to_cancel' => $cancel, 'asks_about' => $asksAbout,
            'about_task' => $aboutTask];
    }

    /** A started task whose required fields are filled: its second turn gets the group offer. */
    private function offered(array $offerReplies): array
    {
        $orchestrator = $this->orchestrator([
            self::reply([], 'Which room, day and how many?'),
            self::reply(['room' => 'large', 'day' => '2026-03-03', 'attendees' => 4]),
            self::reply([], 'Catering? And a title?'),
            self::reply(['catering' => false, 'title' => 'Board']),
            self::reply([], 'Anything else to set now: equipment, payment, extras?'),
            ...$offerReplies,
        ]);
        $task = $this->started($orchestrator);
        $this->turn($orchestrator, $task, 'large, March 3, four of us');
        $offer = $this->turn($orchestrator, $task, 'no food, call it Board');

        return [$orchestrator, $task, $offer];
    }

    /** @return array<string, int> the audited group answers, `_group:<key>` => 1|0 */
    private function groupAnswers(AiTask $task): array
    {
        return AiTaskEvent::where('task_id', $task->id)->where('kind', 'extracted')->where('field', 'like', '_group:%')
            ->orderBy('id')->pluck('value_after', 'field')->all();
    }

    private const MANUAL_LINK = ['url' => 'https://example.test/rooms/new', 'label' => 'ai.tasks.manual-form'];

    private function orchestrator(array $replies): TaskOrchestrator
    {
        $this->llm = new ScriptedJsonLlm($replies);

        return new TaskOrchestrator(new TaskStore(), new TaskPlanner(), new TaskStepCaller($this->llm));
    }

    private function conversation(): AiConversation
    {
        return AiConversation::create(['status' => 'active', 'user_id' => 7, 'team_id' => 3]);
    }

    private function turn(TaskOrchestrator $orchestrator, AiTask $task, string $message): array
    {
        return $orchestrator->handleTurn($task->fresh(), $message, ['user' => $this->user]);
    }

    /** A turn whose message is stored first, as the chat stores a task answer. */
    private function said(TaskOrchestrator $orchestrator, AiTask $task, string $message): array
    {
        AiConversation::find($task->conversation_id)->addMessage('user', $message, ['metadata' => ['task' => ['uuid' => $task->uuid]]]);

        return $this->turn($orchestrator, $task, $message);
    }

    /** @return list<string> */
    private function kinds(AiTask $task): array
    {
        return AiTaskEvent::where('task_id', $task->id)->orderBy('id')->pluck('kind')->all();
    }

    private function started(TaskOrchestrator $orchestrator, ?AiConversation $conversation = null): AiTask
    {
        $response = $orchestrator->start($this->user, $conversation ?? $this->conversation(), 'book_room');

        return AiTask::where('uuid', $response['metadata']['task']['uuid'])->firstOrFail();
    }

    /** A task with every required field filled, reviewing, with its confirm token. */
    private function reviewing(TaskOrchestrator $orchestrator, int $attendees = 4): array
    {
        $task = $this->started($orchestrator);
        $store = new TaskStore();
        $store->save($task, $task->draft()->with('room', 'large')->with('day', '2026-03-03')->with('attendees', $attendees)->with('catering', 0)
            ->with('title', 'Board')->withDeclinedGroup('equipment')->withDeclinedGroup('payment')->withDeclinedGroup('extras'));
        $response = $this->turn($orchestrator, $task, 'that is all');

        return [$task->fresh(), $response['metadata']['task']['token']];
    }

    /** @test */
    public function start_refuses_a_user_the_definition_does_not_allow(): void
    {
        $this->definition->startable = false;

        try {
            $this->orchestrator([])->start($this->user, $this->conversation(), 'book_room');
            $this->fail('start must check startableBy');
        } catch (AuthorizationException) {
        }
        $this->assertSame(0, AiTask::count());
    }

    /** @test */
    public function start_opens_a_collecting_task_and_asks_the_first_fields(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Which room, which day and how many people?')]);

        $response = $orchestrator->start($this->user, $this->conversation(), 'book_room');

        $task = AiTask::firstOrFail();
        $this->assertSame('Which room, which day and how many people?', $response['answer']);
        $this->assertSame($task->uuid, $response['metadata']['task']['uuid']);
        $this->assertSame(AiTask::STATUS_COLLECTING, $task->status);
        $this->assertSame([7, 3, 1], [$task->user_id, $task->team_id, $task->llm_calls]);
        $this->assertSame(['started', 'asked'], $this->kinds($task));
        $this->assertSame(['room', 'day', 'attendees'], AiTaskEvent::where('kind', 'asked')->first()->payload['fields']);
        $this->assertStringContainsString('MESSAGE: none yet', $this->llm->userMessage(0));
    }

    /** @test */
    public function claims_returns_the_active_task_of_the_conversation_only(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply([], 'Q?')]);
        $conversation = $this->conversation();
        $task = $this->started($orchestrator, $conversation);
        $this->started($orchestrator);

        $this->assertTrue($orchestrator->claims($conversation)->is($task));
        config(['ai.tasks.definitions' => []]);
        $this->assertNull($orchestrator->claims($conversation), 'a task whose definition is gone no longer takes turns');
        config(['ai.tasks.definitions' => [BookRoomTask::class]]);
        $orchestrator->cancel($task, ['user' => $this->user]);
        $this->assertNull($orchestrator->claims($conversation));
    }

    /** @test */
    public function an_undeclared_field_is_dropped_and_audited(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Room?'), self::reply(['room' => 'large', 'discount' => 0], 'Which day?')]);
        $task = $this->started($orchestrator);

        $response = $this->turn($orchestrator, $task, 'large room, and no discount please');

        $this->assertSame(['room' => 'large'], $task->fresh()->draft()->values);
        $this->assertSame('Which day?', $response['answer']);
        $rejected = AiTaskEvent::where('kind', 'rejected_value')->sole();
        $this->assertSame(['discount', 'provided', 'undeclared'], [$rejected->field, $rejected->value_after, $rejected->payload['reason']]);
        $extracted = AiTaskEvent::where('kind', 'extracted')->sole();
        $this->assertSame(['room', null, 'large'], [$extracted->field, $extracted->value_before, $extracted->value_after]);
        $this->assertSame(['started', 'asked', 'answered', 'extracted', 'rejected_value', 'asked'], $this->kinds($task));
    }

    /** @test */
    public function an_invalid_value_is_rejected_and_audited(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(['attendees' => 50], 'How many people, at most 20?')]);
        $task = $this->started($orchestrator);

        $this->turn($orchestrator, $task, 'fifty of us');

        $this->assertSame([], $task->fresh()->draft()->values);
        $rejected = AiTaskEvent::where('kind', 'rejected_value')->sole();
        $this->assertSame(['attendees', 50, 'invalid'], [$rejected->field, $rejected->value_after, $rejected->payload['reason']]);
        $this->assertSame(1, $task->fresh()->version, 'nothing changed in the draft');
    }

    /** @test */
    public function wanting_to_cancel_cancels_the_task(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(['room' => 'large'], '', true)]);
        $task = $this->started($orchestrator);

        $response = $this->turn($orchestrator, $task, 'forget it');

        $this->assertSame(AiTask::STATUS_CANCELLED, $task->fresh()->status);
        $this->assertSame('All right, I stopped. Nothing was created.', $response['answer']);
        $this->assertSame(['started', 'asked', 'answered', 'cancelled'], $this->kinds($task));
        $this->assertSame([], $task->fresh()->draft()->values, 'a cancelling turn writes no value');
    }

    /** @test */
    public function a_failed_step_repeats_the_last_question_once(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Which room?'), new \RuntimeException('down'), new \RuntimeException('down')]);
        $task = $this->started($orchestrator);

        $this->turn($orchestrator, $task, 'hmm');
        $response = $this->turn($orchestrator, $task, 'hmm');

        $this->assertSame("I didn't quite understand. Could you rephrase?\n\nWhich room?", $response['answer']);
        $this->assertSame(AiTask::STATUS_COLLECTING, $task->fresh()->status);
    }

    /** @test */
    public function a_full_run_reviews_confirms_executes_once_and_keeps_a_valid_chain(): void
    {
        [$orchestrator, $task] = $this->offered([
            self::reply(['_groups' => ['equipment'], 'projector' => false, 'whiteboard' => true, 'cables' => false]),
        ]);

        $response = $this->turn($orchestrator, $task, 'the equipment: no projector, a whiteboard, no cables');

        $task->refresh();
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->status);
        $this->assertSame(['equipment'], $task->draft()->groups);
        $this->assertSame(['payment', 'extras'], $task->draft()->declined);
        $this->assertSame(['room' => 'large', 'day' => '2026-03-03', 'attendees' => 4, 'catering' => 0, 'title' => 'Board',
            'projector' => 0, 'whiteboard' => 1, 'cables' => 0], $task->draft()->values);
        $this->assertSame('Here is the summary. Check it, then confirm or cancel.', $response['answer']);
        $this->assertSame([3, 6], [$task->turns, $task->llm_calls]);

        $token = $response['metadata']['task']['token'];
        $done = $orchestrator->confirm($task, $token, ['user' => $this->user]);

        $this->assertSame(AiTask::STATUS_DONE, $task->fresh()->status);
        $this->assertSame(['booking' => $task->uuid], $done['metadata']['task']['outcome']['data']);
        $this->assertSame(1, $this->definition->executed);
        $this->assertTrue((new TaskStore())->verifyChain($task));
        foreach (['started', 'asked', 'answered', 'extracted', 'validated', 'summary_shown', 'token_issued', 'confirmed', 'executed'] as $kind) {
            $this->assertContains($kind, $this->kinds($task));
        }
        $this->assertSame(['_group:equipment' => 1, '_group:payment' => 0, '_group:extras' => 0], $this->groupAnswers($task));
    }

    /** @test */
    public function one_offer_lists_every_open_group_with_its_fields_and_offers_them_as_chips(): void
    {
        [$orchestrator, $task, $offer] = $this->offered([self::reply()]);
        $this->turn($orchestrator, $task, 'hmm');

        $prompt = $this->llm->userMessage(5);
        $this->assertStringContainsString("FIELDS TO ASK:\n- _groups (list): " . __('ai.tasks.groups') . '.', $prompt);
        $this->assertStringContainsString('Options: equipment: book.group-equipment; payment: book.group-payment; extras: book.group-extras; none: ' . __('ai.tasks.groups-none') . '.', $prompt);
        $this->assertStringNotContainsString('- projector', $this->llm->userMessage(4), 'asking reads nothing: no other field');
        $this->assertSame(1, substr_count($prompt, '- _groups'), 'the offer is not listed twice');
        $others = substr($prompt, (int) strpos($prompt, 'OTHER FIELDS'));
        foreach (['- projector (bool)', '- whiteboard (bool)', '- cables (bool)', '- deposit (percent)', '- notes (text)'] as $spec) {
            $this->assertStringContainsString($spec, $others, 'a value given with the answer can be kept');
        }
        $this->assertStringNotContainsString('- screen_size', $prompt, 'only the fields that apply now');
        $this->assertSame([
            ['label' => 'book.group-equipment', 'field' => '_groups', 'value' => ['equipment']],
            ['label' => 'book.group-payment', 'field' => '_groups', 'value' => ['payment']],
            ['label' => 'book.group-extras', 'field' => '_groups', 'value' => ['extras']],
            ['label' => __('ai.tasks.groups-none'), 'field' => '_groups', 'value' => ['none']],
        ], $offer['metadata']['task']['choices']);
    }

    /** @test */
    public function the_offer_question_is_about_the_groups_and_a_question_about_a_field_no_longer_asked_is_not_reused(): void
    {
        [$orchestrator, $task] = $this->offered([
            self::reply(['_groups' => ['equipment'], 'projector' => true], 'Any notes?', asksAbout: 'notes'),
            self::reply([], 'A whiteboard? Which screen size?'),
        ]);

        $this->assertStringContainsString('Your question asks about this field only, offering its options', $this->llm->userMessage(4));

        $response = $this->turn($orchestrator, $task, 'the equipment, with a projector');

        $this->assertSame('A whiteboard? Which screen size?', $response['answer']);
    }

    /** @test */
    public function a_value_equal_to_its_suggestion_on_the_offer_is_kept_and_accepts_its_group(): void
    {
        $this->definition->suggestCables = true;
        [$orchestrator, $task] = $this->offered([self::reply(['_groups' => ['payment'], 'deposit' => 20, 'cables' => true])]);

        $this->turn($orchestrator, $task, 'the payment, a 20% deposit, and yes to cables');

        $draft = $task->fresh()->draft();
        $this->assertSame(1, $draft->get('cables'));
        $this->assertSame(['equipment', 'payment'], $draft->groups);
        $this->assertSame(['extras'], $draft->declined);
    }

    /** @test */
    public function no_to_the_offer_declines_every_group_in_one_turn(): void
    {
        // A real model leaves an empty answer out of its updates; "none" is an option it does give.
        [$orchestrator, $task] = $this->offered([self::reply(['_groups' => ['none']])]);

        $this->turn($orchestrator, $task, 'no, later');

        $task->refresh();
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->status);
        $this->assertSame(['equipment', 'payment', 'extras'], $task->draft()->declined);
        $this->assertSame(['_group:equipment' => 0, '_group:payment' => 0, '_group:extras' => 0], $this->groupAnswers($task));
        $this->assertSame([3, 6], [$task->turns, $task->llm_calls], 'nothing left to ask: the summary needs no call');
    }

    /** @test */
    public function a_value_given_with_the_acceptance_is_kept_and_accepts_its_group(): void
    {
        [$orchestrator, $task] = $this->offered([self::reply(['_groups' => ['payment'], 'deposit' => 20, 'room' => 'small'])]);

        $this->turn($orchestrator, $task, 'yes, a 20% deposit');

        $task->refresh();
        $this->assertSame(['payment'], $task->draft()->groups);
        $this->assertSame(['equipment', 'extras'], $task->draft()->declined);
        $this->assertSame(20, $task->draft()->get('deposit'));
        $this->assertSame('large', $task->draft()->get('room'), 'a field outside the offer is dropped');
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->status);
    }

    /** @test */
    public function a_group_field_answered_on_the_offer_accepts_its_group_declines_the_others_and_reviews(): void
    {
        [$orchestrator, $task] = $this->offered([self::reply(['notes' => 'Bring water'])]);

        $this->turn($orchestrator, $task, 'just a note: bring water');

        $task->refresh();
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->status, 'the offer is not made again');
        $this->assertSame(['extras'], $task->draft()->groups);
        $this->assertSame(['equipment', 'payment'], $task->draft()->declined);
        $this->assertSame(['_group:equipment' => 0, '_group:payment' => 0, '_group:extras' => 1], $this->groupAnswers($task));
        $this->assertSame([3, 6], [$task->turns, $task->llm_calls]);
    }

    /** @test */
    public function a_value_given_early_for_a_later_groups_field_is_kept_and_that_group_is_not_offered(): void
    {
        $orchestrator = $this->orchestrator([
            self::reply([], 'Which room, day and how many?'),
            self::reply(['room' => 'large', 'day' => '2026-03-03', 'attendees' => 4, 'notes' => 'Bring water', 'deposit' => 80]),
            self::reply([], 'Catering? And a title?'),
            self::reply(['catering' => false, 'title' => 'Board']),
            self::reply([], 'Anything else: equipment or payment?'),
        ]);
        $task = $this->started($orchestrator);

        $this->turn($orchestrator, $task, 'large, March 3, four of us; note: bring water; an 80% deposit');

        $prompt = $this->llm->userMessage(1);
        $this->assertStringContainsString("OTHER FIELDS (not asked now: keep a value the MESSAGE gives for one; never ask about them):\n- catering (bool)", $prompt);
        $this->assertStringContainsString('- notes (text): book.notes.', $prompt);
        $this->assertStringNotContainsString('- screen_size', $prompt, 'only the fields that apply now');
        $this->assertSame(['room', 'day', 'attendees', 'catering', 'title', 'projector', 'deposit', 'whiteboard', 'cables', 'notes', '_groups'],
            $this->llm->calls[1]['options']['json_schema']['schema']['properties']['updates']['items']['properties']['field']['enum']);
        $this->assertSame(['room', 'day', 'attendees', null],
            $this->llm->calls[1]['options']['json_schema']['schema']['properties']['asks_about']['enum'], 'a question is only about an asked field');
        $draft = $task->fresh()->draft();
        $this->assertSame(['Bring water', ['extras']], [$draft->get('notes'), $draft->groups]);
        $this->assertFalse($draft->has('deposit'), 'an early value is checked like any other');

        $offer = $this->turn($orchestrator, $task, 'no food, call it Board');

        $this->assertSame([['equipment'], ['payment'], ['none']], array_column($offer['metadata']['task']['choices'], 'value'), 'the answered group is not offered');
        $this->assertSame(['_groups'], AiTaskEvent::where('task_id', $task->id)->where('kind', 'asked')->orderByDesc('id')->first()->payload['fields']);
        $this->assertSame('Bring water', $task->fresh()->draft()->get('notes'));
    }

    /** @test */
    public function a_value_for_a_field_that_applies_only_once_another_answer_is_in_is_read_from_the_users_messages(): void
    {
        $orchestrator = $this->orchestrator([
            self::reply([], 'Which room, day and how many?'),
            self::reply(['room' => 'large', 'day' => '2026-03-03', 'attendees' => 4]),
            self::reply([], 'Catering? And a title?'),
            self::reply(['catering' => true, 'title' => 'Board']),
            self::reply(['catering_budget' => 200, 'attendees' => 9], 'And how much of it vegan?'),
            self::reply([], 'What share of the food should be vegan?', asksAbout: 'vegan_share'),
        ]);
        $task = $this->started($orchestrator);
        $this->said($orchestrator, $task, 'large, March 3, four of us');
        AiConversation::find($task->conversation_id)->addMessage('user', 'not about this task');

        $response = $this->said($orchestrator, $task, 'catering please, a 200 budget; call it Board');

        $reread = $this->llm->userMessage(4);
        $this->assertStringContainsString("EARLIER MESSAGES (the user's earlier answers in this task; take from them only values for: catering_budget):", $reread);
        $this->assertStringContainsString("<user_message>\nlarge, March 3, four of us\n</user_message>", $reread);
        $this->assertStringContainsString("<user_message>\ncatering please, a 200 budget; call it Board\n</user_message>", $reread);
        $this->assertSame(1, substr_count($reread, 'catering please'), 'the message being answered is read once');
        $this->assertStringNotContainsString('not about this task', $reread);
        $this->assertStringContainsString('take from them only values for: vegan_share)', $this->llm->userMessage(5), 'the share came into play with the budget: read for it too');
        $task->refresh();
        $this->assertSame([200, 4], [$task->draft()->get('catering_budget'), $task->draft()->get('attendees')], 'a value already read is never taken again');
        $this->assertSame('What share of the food should be vegan?', $response['answer']);
        $budget = AiTaskEvent::where('task_id', $task->id)->where('kind', 'extracted')->where('field', 'catering_budget')->sole();
        $this->assertSame(['earlier' => 1], $budget->payload);
        $this->assertSame([2, 6], [$task->turns, $task->llm_calls]);
    }

    /** @test */
    public function after_the_groups_were_declined_naming_one_asks_its_fields(): void
    {
        [$orchestrator, $task] = $this->offered([self::reply(['_groups' => ['none']]), self::reply(['_groups' => ['payment']]), self::reply([], 'Which deposit?')]);
        $this->turn($orchestrator, $task, 'no, later');

        $response = $this->turn($orchestrator, $task, 'actually, I want to set the payment too');

        $this->assertStringContainsString('- _groups (list): ' . __('ai.tasks.groups') . '. About: only when the user asks to set one of these optional settings too', $this->llm->userMessage(6));
        $this->assertStringContainsString('Options: equipment: book.group-equipment; payment: book.group-payment; extras: book.group-extras.', $this->llm->userMessage(6));
        $task->refresh();
        $this->assertSame(AiTask::STATUS_COLLECTING, $task->status);
        $this->assertSame([['payment'], ['equipment', 'extras']], [$task->draft()->groups, $task->draft()->declined]);
        $this->assertSame('Which deposit?', $response['answer']);
    }

    /** @test */
    public function after_the_groups_were_declined_a_value_for_one_of_their_fields_is_kept_and_reviewed(): void
    {
        [$orchestrator, $task] = $this->offered([self::reply(['_groups' => ['none']]), self::reply(['deposit' => 20])]);
        $first = $this->turn($orchestrator, $task, 'no, later');

        $response = $this->turn($orchestrator, $task, 'add a 20% deposit');

        $task->refresh();
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->status);
        $this->assertSame([20, ['payment'], ['equipment', 'extras']], [$task->draft()->get('deposit'), $task->draft()->groups, $task->draft()->declined]);
        $this->assertNotSame($first['metadata']['task']['token'], $response['metadata']['task']['token']);
    }

    /** @test */
    public function a_chip_posts_its_value_and_code_maps_it_without_reading_the_answer(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Which room?', asksAbout: 'room'), self::reply([], 'Which day?')]);
        $start = $orchestrator->start($this->user, $this->conversation(), 'book_room');
        $task = AiTask::sole();

        $this->assertSame([
            ['label' => 'book.room.small', 'field' => 'room', 'value' => 'small'],
            ['label' => 'book.room.large', 'field' => 'room', 'value' => 'large'],
        ], $start['metadata']['task']['choices'], 'labels, never the raw values, are shown');

        $response = $orchestrator->choose($task, 'room', 'large', ['user' => $this->user, 'version' => $task->version]);

        $task->refresh();
        $this->assertSame('large', $task->draft()->get('room'));
        $this->assertSame('book.room.large', $response['question'], 'the label stands for the user\'s answer');
        $this->assertSame('Which day?', $response['answer']);
        $this->assertSame([1, 2], [$task->turns, $task->llm_calls], 'one call: the next question');
        $this->assertStringContainsString('MESSAGE: none yet', $this->llm->userMessage(1));
    }

    /** @test */
    public function a_chip_for_a_field_not_asked_now_an_invalid_value_or_an_old_version_is_refused(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Which room?', asksAbout: 'room')]);
        $orchestrator->start($this->user, $this->conversation(), 'book_room');
        $task = AiTask::sole();

        foreach ([['title', 'Board', $task->version], ['room', 'huge', $task->version], ['room', 'large', $task->version + 1]] as [$field, $value, $version]) {
            $response = $orchestrator->choose($task, $field, $value, ['user' => $this->user, 'version' => $version]);
            $this->assertSame(__('ai.tasks.stale'), $response['answer']);
        }
        $this->assertSame([], $task->fresh()->draft()->values);
        $this->assertSame(0, $task->fresh()->turns);
    }

    /** @test */
    public function a_confirm_with_a_stale_version_is_refused(): void
    {
        [$task, $token] = $this->reviewing($this->orchestrator([self::reply([], 'Q?'), self::reply()]));
        (new TaskStore())->save($task, $task->draft()->with('attendees', 9));

        $response = $this->orchestrator([])->confirm($task->fresh(), $token, ['user' => $this->user]);

        $this->assertSame('This summary has changed since it was shown. Review the new one.', $response['answer']);
        $this->assertSame(0, $this->definition->executed);
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->fresh()->status);
    }

    /** @test */
    public function a_second_confirm_returns_the_stored_outcome_without_executing_again(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task, $token] = $this->reviewing($orchestrator);

        $first = $orchestrator->confirm($task, $token, ['user' => $this->user]);
        $second = $orchestrator->confirm($task->fresh(), $token, ['user' => $this->user]);

        $this->assertSame(1, $this->definition->executed);
        $this->assertSame($first['metadata']['task']['outcome'], $second['metadata']['task']['outcome']);
        $this->assertSame(1, AiTaskEvent::where('task_id', $task->id)->where('kind', 'executed')->count());
    }

    /** @test */
    public function a_task_past_its_lifetime_expires_without_a_model_call(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?')]);
        $task = $this->started($orchestrator);
        $this->travel(86401)->seconds();

        $response = $this->turn($orchestrator, $task, 'large');

        $this->assertSame(AiTask::STATUS_EXPIRED, $task->fresh()->status);
        $this->assertCount(1, $this->llm->calls);
        $this->assertSame(['started', 'asked', 'limit_hit', 'expired'], $this->kinds($task));
        $this->assertSame('ttl', AiTaskEvent::where('kind', 'limit_hit')->sole()->payload['limit']);
        $this->assertStringContainsString('went on too long', $response['answer']);
        $this->assertSame(self::MANUAL_LINK, $response['metadata']['task']['link']);
    }

    /** @test */
    public function the_turn_limit_stops_the_task(): void
    {
        $this->definition->limits = new TaskLimits(maxTurns: 1);
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(['room' => 'large'], 'Day?')]);
        $task = $this->started($orchestrator);

        $this->turn($orchestrator, $task, 'large');
        $response = $this->turn($orchestrator, $task, 'March 3');

        $this->assertSame(AiTask::STATUS_EXPIRED, $task->fresh()->status);
        $this->assertSame('turns', AiTaskEvent::where('kind', 'limit_hit')->sole()->payload['limit']);
        $this->assertCount(2, $this->llm->calls);
        $this->assertStringContainsString('a limit was reached', $response['answer']);
        $this->assertSame(self::MANUAL_LINK, $response['metadata']['task']['link']);
    }

    /** @test */
    public function the_model_call_limit_stops_the_task(): void
    {
        $this->definition->limits = new TaskLimits(maxLlmCalls: 1);
        $orchestrator = $this->orchestrator([self::reply([], 'Q?')]);
        $task = $this->started($orchestrator);

        $this->turn($orchestrator, $task, 'large');

        $this->assertSame(AiTask::STATUS_EXPIRED, $task->fresh()->status);
        $this->assertSame('llm_calls', AiTaskEvent::where('kind', 'limit_hit')->sole()->payload['limit']);
        $this->assertCount(1, $this->llm->calls);

        $this->definition->manualUrl = null;
        $this->assertArrayNotHasKey('link', $this->turn($orchestrator, $task, 'again')['metadata']['task'], 'no manual path, no link');
    }

    /** @test */
    public function the_per_user_daily_limit_refuses_the_confirm(): void
    {
        $this->definition->limits = new TaskLimits(maxExecutedPerUserPerDay: 1);
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task, $token] = $this->reviewing($orchestrator);
        $this->doneTask(userId: 7, teamId: 99);

        $response = $orchestrator->confirm($task, $token, ['user' => $this->user]);

        $this->assertSame(0, $this->definition->executed);
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->fresh()->status);
        $this->assertSame('per_user_per_day', AiTaskEvent::where('task_id', $task->id)->where('kind', 'limit_hit')->sole()->payload['limit']);
        $this->assertStringContainsString("today's limit", $response['answer']);
        $this->assertSame(self::MANUAL_LINK, $response['metadata']['task']['link']);

        Log::spy();
        $tasks = AiTask::count();
        $refused = $orchestrator->start($this->user, $this->conversation(), 'book_room');
        $this->assertSame($tasks, AiTask::count(), 'no new task is opened past the limit');
        $this->assertStringContainsString("today's limit", $refused['answer']);
        $this->assertSame(['limit_hit' => 'per_user_per_day', 'link' => self::MANUAL_LINK], $refused['metadata']['task'],
            'no task row to audit: the refusal rides the answer');
        Log::shouldHaveReceived('info')->once()->with('AI task start refused: limit hit', [
            'definition' => 'book_room', 'user_id' => 7, 'team_id' => 3, 'limit' => 'per_user_per_day',
        ]);
    }

    /** @test */
    public function the_per_team_daily_limit_refuses_the_confirm_and_older_runs_do_not_count(): void
    {
        $this->definition->limits = new TaskLimits(maxExecutedPerTeamPerDay: 1);
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(), self::reply([], 'Q?'), self::reply()]);
        $this->doneTask(userId: 8, teamId: 3, hoursAgo: 25);
        [$task, $token] = $this->reviewing($orchestrator);
        $orchestrator->confirm($task, $token, ['user' => $this->user]);
        $this->assertSame(1, $this->definition->executed, 'a run older than 24 h does not count');

        $this->definition->limits = new TaskLimits(maxExecutedPerTeamPerDay: 2);
        [$other, $otherToken] = $this->reviewing($orchestrator);
        $this->doneTask(userId: 8, teamId: 3);
        $orchestrator->confirm($other, $otherToken, ['user' => $this->user]);

        $this->assertSame(1, $this->definition->executed);
        $this->assertSame('per_team_per_day', AiTaskEvent::where('task_id', $other->id)->where('kind', 'limit_hit')->sole()->payload['limit']);
    }

    /** @test */
    public function a_definition_that_throws_fails_the_task_and_its_writes_are_rolled_back(): void
    {
        $this->definition->throws = true;
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task, $token] = $this->reviewing($orchestrator);
        $conversations = AiConversation::count();

        $response = $orchestrator->confirm($task, $token, ['user' => $this->user]);

        $this->assertSame(AiTask::STATUS_FAILED, $task->fresh()->status);
        $this->assertSame($conversations, AiConversation::count(), 'the half write was rolled back');
        $this->assertSame(\RuntimeException::class, AiTaskEvent::where('task_id', $task->id)->where('kind', 'failed')->sole()->payload['error']);
        $this->assertSame('It could not be completed. Nothing was saved.', $response['answer']);
        $this->assertTrue((new TaskStore())->verifyChain($task));
    }

    /** @test */
    public function confirm_checks_again_who_may_start_and_the_whole_draft(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(), self::reply([], 'Q?'), self::reply()]);
        [$task, $token] = $this->reviewing($orchestrator);
        $this->definition->errors = ['attendees' => 'Too many people for that room.'];

        $response = $orchestrator->confirm($task, $token, ['user' => $this->user]);

        $this->assertSame(AiTask::STATUS_COLLECTING, $task->fresh()->status);
        $this->assertFalse($task->fresh()->draft()->has('attendees'));
        $this->assertStringContainsString('Too many people for that room.', $response['answer']);

        $this->definition->errors = [];
        [$other, $otherToken] = $this->reviewing($orchestrator);
        $this->definition->startable = false;
        $refused = $orchestrator->confirm($other, $otherToken, ['user' => $this->user]);

        $this->assertSame(AiTask::STATUS_CANCELLED, $other->fresh()->status);
        $this->assertSame('You can no longer do this. Nothing was created.', $refused['answer']);
        $this->assertSame(0, $this->definition->executed);
    }

    /** @test */
    public function personal_data_never_reaches_the_audit(): void
    {
        $this->definition->withEmail = true;
        $orchestrator = $this->orchestrator([
            self::reply([], 'Q?'),
            self::reply(['email' => 'jane@example.test', 'room' => 'small'], 'Day?'),
        ]);
        $task = $this->started($orchestrator);

        $this->turn($orchestrator, $task, 'jane@example.test, small room');

        $this->assertSame('jane@example.test', $task->fresh()->draft()->get('email'));
        $events = AiTaskEvent::where('task_id', $task->id)->get()->toJson();
        $this->assertStringNotContainsString('jane@example.test', $events);
        $this->assertSame('provided', AiTaskEvent::where('field', 'email')->sole()->value_after);
    }

    /** @test */
    public function reopen_takes_a_reviewing_task_back_to_that_field_and_kills_the_shown_token(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(), self::reply([], 'How many people?')]);
        [$task, $token] = $this->reviewing($orchestrator);
        $turns = $task->turns;

        $response = $orchestrator->reopen($task, 'attendees', ['user' => $this->user]);

        $task->refresh();
        $this->assertSame(AiTask::STATUS_COLLECTING, $task->status);
        $this->assertFalse($task->draft()->has('attendees'));
        $this->assertSame('large', $task->draft()->get('room'), 'the other answers stay');
        $this->assertSame($turns, $task->turns, 'a reopen is not a turn');
        $this->assertSame('How many people?', $response['answer']);
        $this->assertSame(['attendees'], AiTaskEvent::where('kind', 'asked')->orderByDesc('id')->first()->payload['fields']);
        $reopened = AiTaskEvent::where('task_id', $task->id)->where('kind', 'reopened')->sole();
        $this->assertSame(['attendees', 4], [$reopened->field, $reopened->value_before]);

        $refused = $orchestrator->confirm($task, $token, ['user' => $this->user]);
        $this->assertSame(__('ai.tasks.not-waiting'), $refused['answer']);
        $this->assertSame(0, $this->definition->executed);
        $this->assertSame('not_waiting', AiTaskEvent::where('task_id', $task->id)->where('kind', 'confirm_refused')->sole()->payload['reason']);
        $this->assertTrue((new TaskStore())->verifyChain($task));
    }

    /** @test */
    public function reopen_refuses_a_field_the_task_does_not_hold_or_a_task_not_in_review(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task] = $this->reviewing($orchestrator);

        $orchestrator->reopen($task, 'deposit', ['user' => $this->user]);
        $orchestrator->reopen($task, 'nope', ['user' => $this->user]);
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->fresh()->status);

        $orchestrator->cancel($task, ['user' => $this->user]);
        $response = $orchestrator->reopen($task->fresh(), 'room', ['user' => $this->user]);
        $this->assertSame(AiTask::STATUS_CANCELLED, $task->fresh()->status);
        $this->assertSame(__('ai.tasks.not-waiting'), $response['answer']);
        $this->assertSame(0, AiTaskEvent::where('kind', 'reopened')->count());
    }

    /** @test */
    public function start_on_a_conversation_with_an_active_task_resumes_it(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Which room?')]);
        $conversation = $this->conversation();
        $task = $this->started($orchestrator, $conversation);

        $response = $orchestrator->start($this->user, $conversation, 'book_room');

        $this->assertSame(1, AiTask::count());
        $this->assertSame($task->uuid, $response['metadata']['task']['uuid']);
        $this->assertSame('Which room?', $response['answer']);
        $this->assertCount(1, $this->llm->calls, 'resuming asks the model nothing');
    }

    /** @test */
    public function resuming_a_task_in_review_shows_its_summary_with_a_token_that_confirms(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task] = $this->reviewing($orchestrator);
        $conversation = AiConversation::findOrFail($task->conversation_id);

        $response = $orchestrator->start($this->user, $conversation, 'book_room');

        $this->assertSame(__('ai.tasks.review'), $response['answer']);
        $orchestrator->confirm($task->fresh(), $response['metadata']['task']['token'], ['user' => $this->user]);
        $this->assertSame(1, $this->definition->executed);
    }

    /** @test */
    public function a_refused_confirm_is_audited_with_the_token_fingerprint_and_the_reason(): void
    {
        [$task, $token] = $this->reviewing($this->orchestrator([self::reply([], 'Q?'), self::reply()]));
        $orchestrator = $this->orchestrator([]);

        $orchestrator->confirm($task, 'not-a-token', ['user' => $this->user]);
        $orchestrator->confirm($task, $token, ['user' => (object) ['id' => 8, 'current_team_id' => 3]]);
        (new TaskStore())->save($task->fresh(), $task->fresh()->draft()->with('attendees', 9));
        $orchestrator->confirm($task->fresh(), $token, ['user' => $this->user]);

        $refusals = AiTaskEvent::where('task_id', $task->id)->where('kind', 'confirm_refused')->orderBy('id')->get();
        $this->assertSame(['invalid', 'foreign', 'stale'], $refusals->map(fn ($event) => $event->payload['reason'])->all());
        $this->assertSame(substr(hash('sha256', $token), 0, 16), $refusals[2]->payload['token']);
        $this->assertSame([7, 8, 7], $refusals->pluck('actor_user_id')->all());
        $this->assertSame(0, $this->definition->executed);
        $this->assertTrue((new TaskStore())->verifyChain($task));
    }

    /** @test */
    public function a_confirm_posted_for_another_version_than_the_current_one_is_refused(): void
    {
        [$task, $token] = $this->reviewing($this->orchestrator([self::reply([], 'Q?'), self::reply()]));

        $response = $this->orchestrator([])->confirm($task, $token, ['user' => $this->user, 'version' => $task->version - 1]);

        $this->assertSame(__('ai.tasks.stale'), $response['answer']);
        $this->assertSame(0, $this->definition->executed);
        $this->assertSame('stale', AiTaskEvent::where('kind', 'confirm_refused')->sole()->payload['reason']);
    }

    /** @test */
    public function progress_counts_the_answered_fields_and_the_ones_still_planned(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?')]);
        $task = $this->started($orchestrator);
        (new TaskStore())->save($task, $task->draft()->with('room', 'large')->with('day', '2026-03-03'));

        $this->assertSame(['step' => 3, 'of' => 5], $orchestrator->progress($task->fresh(), $this->user));
    }

    /** A summary with a money row, a legal row and a plain row. */
    private function riskyRows(): void
    {
        $row = fn (string $field, string $risk) => ['field' => $field, 'label' => $field, 'value' => 'x', 'risk' => $risk, 'source' => 'your choice'];
        $this->definition->rows = [$row('attendees', 'money'), $row('day', 'legal'), $row('title', 'none')];
    }

    /** @test */
    public function a_confirm_missing_a_money_or_legal_acknowledgement_is_refused_audited_and_shows_the_summary_again(): void
    {
        $this->riskyRows();
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task, $token] = $this->reviewing($orchestrator);

        $response = $orchestrator->confirm($task, $token, ['user' => $this->user, 'acknowledged' => ['attendees', 'title']]);

        $this->assertSame(0, $this->definition->executed);
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->fresh()->status);
        $refused = AiTaskEvent::where('task_id', $task->id)->where('kind', 'confirm_refused')->sole();
        $this->assertSame(['unacknowledged', ['day']], [$refused->payload['reason'], $refused->payload['missing']]);
        $this->assertStringContainsString(__('ai.tasks.acknowledge-first'), $response['answer']);
        $this->assertCount(3, $response['metadata']['task']['summary']);
        $orchestrator->confirm($task->fresh(), $response['metadata']['task']['token'], ['user' => $this->user]);
        $this->assertSame(0, $this->definition->executed, 'no acknowledgement at all is refused too');
    }

    /** @test */
    public function a_confirm_with_every_money_and_legal_row_acknowledged_proceeds_and_records_them(): void
    {
        $this->riskyRows();
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task, $token] = $this->reviewing($orchestrator);

        $orchestrator->confirm($task, $token, ['user' => $this->user, 'acknowledged' => ['day', 'title', 'attendees', 'nope']]);

        $this->assertSame(1, $this->definition->executed);
        $confirmed = AiTaskEvent::where('task_id', $task->id)->where('kind', 'confirmed')->sole();
        $this->assertSame(['attendees', 'day'], $confirmed->payload['acknowledged'], 'only money and legal rows are recorded');
    }

    /** @test */
    public function an_outward_row_must_be_acknowledged(): void
    {
        $this->definition->rows = [
            ['field' => 'title', 'label' => 'title', 'value' => 'Board', 'risk' => 'none', 'source' => 'your choice'],
            ['field' => '_notice', 'label' => 'book.notice', 'value' => 'The attendees get an invitation.', 'risk' => 'outward', 'source' => ''],
        ];
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task, $token] = $this->reviewing($orchestrator);

        $response = $orchestrator->confirm($task, $token, ['user' => $this->user]);

        $this->assertSame(0, $this->definition->executed);
        $refused = AiTaskEvent::where('task_id', $task->id)->where('kind', 'confirm_refused')->sole();
        $this->assertSame(['unacknowledged', ['_notice']], [$refused->payload['reason'], $refused->payload['missing']]);

        $orchestrator->confirm($task->fresh(), $response['metadata']['task']['token'], ['user' => $this->user, 'acknowledged' => ['_notice']]);
        $this->assertSame(AiTask::STATUS_DONE, $task->fresh()->status);
    }

    /** @test */
    public function a_turn_whose_task_was_confirmed_meanwhile_answers_the_outcome_and_issues_no_new_token(): void
    {
        $meanwhile = null;
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(), function () use (&$meanwhile) {
            $meanwhile();

            return self::reply();
        }]);
        [$task, $token] = $this->reviewing($orchestrator);
        $meanwhile = fn () => $orchestrator->confirm($task->fresh(), $token, ['user' => $this->user]);

        $response = $this->turn($orchestrator, $task, 'ok');

        $this->assertSame(AiTask::STATUS_DONE, $task->fresh()->status);
        $this->assertSame(['booking' => $task->uuid], $response['metadata']['task']['outcome']['data']);
        $this->assertSame(1, AiTaskEvent::where('task_id', $task->id)->where('kind', 'token_issued')->count());
        $orchestrator->confirm($task->fresh(), $token, ['user' => $this->user]);
        $this->assertSame(1, $this->definition->executed);
    }

    /** @test */
    public function a_change_whose_task_was_confirmed_meanwhile_leaves_it_done(): void
    {
        $meanwhile = null;
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(), function () use (&$meanwhile) {
            $meanwhile();

            return self::reply([], 'How many people?');
        }]);
        [$task, $token] = $this->reviewing($orchestrator);
        $meanwhile = fn () => $orchestrator->confirm($task->fresh(), $token, ['user' => $this->user]);

        $response = $orchestrator->reopen($task, 'attendees', ['user' => $this->user]);

        $this->assertSame(AiTask::STATUS_DONE, $task->fresh()->status);
        $this->assertSame(4, $task->fresh()->draft()->get('attendees'));
        $this->assertSame(['booking' => $task->uuid], $response['metadata']['task']['outcome']['data']);
        $this->assertSame(0, AiTaskEvent::where('task_id', $task->id)->where('kind', 'reopened')->count());
        $this->assertSame(1, $this->definition->executed);
    }

    /** @test */
    public function a_turn_whose_task_was_cancelled_meanwhile_leaves_it_cancelled(): void
    {
        $meanwhile = null;
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), function () use (&$meanwhile) {
            $meanwhile();

            return self::reply([], 'Which day?');
        }]);
        $task = $this->started($orchestrator);
        $meanwhile = fn () => $orchestrator->cancel($task->fresh(), ['user' => $this->user]);

        $response = $this->turn($orchestrator, $task, 'large');

        $this->assertSame(AiTask::STATUS_CANCELLED, $task->fresh()->status);
        $this->assertSame(__('ai.tasks.not-waiting'), $response['answer']);
    }

    /** @test */
    public function a_confirm_while_another_task_of_the_user_executes_waits_for_it_and_counts_it(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $this->definition->limits = new TaskLimits(maxExecutedPerUserPerDay: 1);
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(), self::reply([], 'Q?'), self::reply()]);
        [$first, $firstToken] = $this->reviewing($orchestrator);
        [$second, $secondToken] = $this->reviewing($orchestrator);
        $during = null;
        $this->definition->duringExecute = function () use ($orchestrator, $second, $secondToken, &$during) {
            $during = $orchestrator->confirm($second->fresh(), $secondToken, ['user' => $this->user]);
        };

        $orchestrator->confirm($first, $firstToken, ['user' => $this->user]);

        $this->assertSame(1, $this->definition->executed, 'the second confirm cannot pass the limit the first one is using up');
        $this->assertSame(__('ai.tasks.busy'), $during['answer']);
        $this->assertSame(AiTask::STATUS_REVIEWING, $second->fresh()->status);

        $after = $orchestrator->confirm($second->fresh(), $secondToken, ['user' => $this->user]);
        $this->assertSame('per_user_per_day', $after['metadata']['task']['limit_hit']);
        $this->assertSame(1, $this->definition->executed);
    }

    /** @test */
    public function a_second_start_on_the_same_conversation_while_the_first_opens_its_task_opens_none(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $orchestrator = $this->orchestrator([self::reply([], 'Which room?'), self::reply([], 'Which room?')]);
        $conversation = $this->conversation();
        $refused = null;
        $this->definition->duringLimits = function () use ($orchestrator, $conversation, &$refused) {
            try {
                $orchestrator->start($this->user, $conversation, 'book_room');
            } catch (LockTimeoutException $e) {
                $refused = $e;
            }
        };

        $orchestrator->start($this->user, $conversation, 'book_room');

        $this->assertInstanceOf(LockTimeoutException::class, $refused, 'the second start waits for the first');
        $this->assertSame(1, AiTask::where('conversation_id', $conversation->id)->count());
    }

    /** @test */
    public function a_change_that_makes_another_value_invalid_drops_it_and_asks_it_again(): void
    {
        $this->definition->attendeesMax = fn (TaskDraft $draft) => $draft->get('room') === 'small' ? 5 : null;
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(), self::reply([], 'Which room?'),
            self::reply(['room' => 'small']), self::reply([], 'How many people, at most 5?')]);
        [$task] = $this->reviewing($orchestrator, attendees: 12);
        $orchestrator->reopen($task, 'room', ['user' => $this->user]);

        $response = $this->turn($orchestrator, $task, 'the small one');

        $draft = $task->fresh()->draft();
        $this->assertSame('small', $draft->get('room'));
        $this->assertFalse($draft->has('attendees'));
        $this->assertSame('How many people, at most 5?', $response['answer']);
        $pruned = AiTaskEvent::where('task_id', $task->id)->where('kind', 'extracted')->where('field', 'attendees')->sole();
        $this->assertSame([12, 1], [$pruned->value_before, $pruned->payload['pruned']]);
    }

    /** @test */
    public function confirm_sends_a_value_no_longer_valid_back_to_collecting_on_that_field(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task, $token] = $this->reviewing($orchestrator);
        $this->definition->attendeesMax = fn () => 3;

        $response = $orchestrator->confirm($task, $token, ['user' => $this->user]);

        $this->assertSame(0, $this->definition->executed);
        $this->assertSame(AiTask::STATUS_COLLECTING, $task->fresh()->status);
        $this->assertFalse($task->fresh()->draft()->has('attendees'));
        $this->assertStringContainsString('book.attendees', $response['answer']);
        $this->assertTrue((new TaskStore())->verifyChain($task));
    }

    /** @test */
    public function an_invented_field_is_audited_without_its_value(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Room?'), self::reply(['room' => 'large', 'nickname' => 'jane@example.test'], 'Which day?')]);
        $task = $this->started($orchestrator);

        $this->turn($orchestrator, $task, 'large room, I am jane@example.test');

        $rejected = AiTaskEvent::where('kind', 'rejected_value')->sole();
        $this->assertSame(['nickname', 'provided'], [$rejected->field, $rejected->value_after]);
    }

    /** @test */
    public function an_execute_failure_logs_the_exception_class_never_its_message(): void
    {
        Log::spy();
        $this->definition->throws = true;
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task, $token] = $this->reviewing($orchestrator);

        $orchestrator->confirm($task, $token, ['user' => $this->user]);

        Log::shouldHaveReceived('error', ['AI task execute failed', ['task' => $task->uuid, 'exception' => \RuntimeException::class, 'code' => 0]])->once();
    }

    /** @test */
    public function a_message_about_something_else_leaves_the_task_waiting_and_answers_its_pending_question(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Which room, day and how many?'), self::reply([], 'Which room?', aboutTask: false)]);
        $task = $this->started($orchestrator);
        $before = $task->fresh();

        $response = $this->turn($orchestrator, $task, 'Can guests park on site?');

        $after = $task->fresh();
        $this->assertTrue($response['metadata']['task']['side_question']);
        $this->assertSame('Which room, day and how many?', $response['answer'], 'the question the task still waits on');
        $this->assertSame([AiTask::STATUS_COLLECTING, $before->version, [], $before->turns], [$after->status, $after->version, $after->draft()->values, $after->turns]);
        $this->assertSame($before->llm_calls + 1, $after->llm_calls, 'the step call is the only one, and counted');
        $this->assertSame(['side_question' => 1], array_intersect_key(AiTaskEvent::where('kind', 'answered')->sole()->payload, ['side_question' => 1]));
        $this->assertTrue((new TaskStore())->verifyChain($task));
    }

    /** @test */
    public function a_value_or_a_cancel_wins_over_a_message_read_as_about_something_else(): void
    {
        $orchestrator = $this->orchestrator([
            self::reply([], 'Which room?'),
            self::reply(['room' => 'large'], 'Which day?', asksAbout: 'day', aboutTask: false),
            self::reply([], '', cancel: true, aboutTask: false),
        ]);
        $task = $this->started($orchestrator);

        $answered = $this->turn($orchestrator, $task, 'large, and can guests park?');
        $cancelled = $this->turn($orchestrator, $task, 'forget it, what time is it?');

        $this->assertArrayNotHasKey('side_question', $answered['metadata']['task']);
        $this->assertSame('Which day?', $answered['answer']);
        $this->assertSame(AiTask::STATUS_CANCELLED, $cancelled['metadata']['task']['status']);
    }

    /** @test */
    public function a_message_about_something_else_during_review_keeps_the_summary_and_its_token(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(), self::reply([], '', aboutTask: false)]);
        [$task, $token] = $this->reviewing($orchestrator);

        $response = $this->turn($orchestrator, $task, 'By the way, who cleans the room?');

        $this->assertTrue($response['metadata']['task']['side_question']);
        $this->assertSame(__('ai.tasks.still-reviewing'), $response['answer']);
        $this->assertNotSame('ai.tasks.still-reviewing', $response['answer']);
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->fresh()->status);
        $this->assertSame(AiTask::STATUS_DONE, $orchestrator->confirm($task->fresh(), $token, ['user' => $this->user])['metadata']['task']['status']);
    }

    /** @test */
    public function a_start_from_a_reply_to_an_offer_reads_that_reply_and_records_why(): void
    {
        $orchestrator = $this->orchestrator([self::reply(['room' => 'large'], 'Which day, and how many of you?', asksAbout: 'day', aboutTask: false)]);

        $response = $orchestrator->start($this->user, $this->conversation(), 'book_room', ['message' => 'The large room please', 'reason' => 'offer_reply']);

        $task = AiTask::sole();
        $this->assertSame(['room' => 'large'], $task->draft()->values);
        $this->assertSame('Which day, and how many of you?', $response['answer']);
        $this->assertArrayNotHasKey('side_question', $response['metadata']['task'], 'the offer reply is an answer to the task');
        $this->assertSame([1, 1], [$task->turns, $task->llm_calls]);
        $this->assertSame(['definition' => 'book_room', 'reason' => 'offer_reply'], AiTaskEvent::where('kind', 'started')->sole()->payload);
        $this->assertStringContainsString("MESSAGE:
<user_message>
The large room please", $this->llm->userMessage(0));
        $this->assertStringContainsString('OTHER FIELDS', $this->llm->userMessage(0), 'a value given ahead is kept');
    }

    private function doneTask(int $userId, int $teamId, int $hoursAgo = 1): void
    {
        $conversation = $this->conversation();
        $task = (new TaskStore())->create('book_room', $conversation->id, $userId, $teamId, new TaskLimits());
        (new TaskStore())->record($task, ['status' => AiTask::STATUS_DONE, 'executed_at' => now()->subHours($hoursAgo)]);
    }
}

/** The fixture with switches the orchestrator tests flip. */
class SpyBookRoomTask extends BookRoomTask
{
    public int $executed = 0;
    public bool $throws = false;
    public bool $startable = true;
    public bool $withEmail = false;
    public bool $suggestCables = false;
    public array $errors = [];
    public ?TaskLimits $limits = null;
    public array $rows = [];
    public ?string $manualUrl = 'https://example.test/rooms/new';
    public ?\Closure $attendeesMax = null;
    public ?\Closure $duringExecute = null;
    public ?\Closure $duringLimits = null;

    public function manualUrl(mixed $user): ?string
    {
        return $this->manualUrl;
    }

    public function summary(mixed $user, TaskDraft $draft): TaskSummary
    {
        return new TaskSummary($this->rows);
    }

    public function startableBy(mixed $user): bool
    {
        return $this->startable && parent::startableBy($user);
    }

    public function fields(mixed $user, TaskDraft $draft): array
    {
        $fields = parent::fields($user, $draft);

        if ($this->suggestCables) {
            $fields = array_map(fn (TaskField $field) => $field->key === 'cables'
                ? new TaskField('cables', 'book.cables', 'bool', default: fn () => true, group: 'equipment') : $field, $fields);
        }

        $max = $this->attendeesMax ? ($this->attendeesMax)($draft) : null;
        if ($max !== null) {
            $fields = array_map(fn (TaskField $field) => $field->key === 'attendees'
                ? new TaskField('attendees', 'book.attendees', 'int', rules: ['min:1', 'max:' . $max]) : $field, $fields);
        }

        return $this->withEmail ? [new TaskField('email', 'book.email', 'text', pii: 1), ...$fields] : $fields;
    }

    public function validate(mixed $user, TaskDraft $draft): array
    {
        return $this->errors;
    }

    public function execute(mixed $user, TaskDraft $draft, AiTask $run): TaskOutcome
    {
        $this->executed++;
        if ($during = $this->duringExecute) {
            $this->duringExecute = null;
            $during();
        }
        if ($this->throws) {
            AiConversation::create(['status' => 'active']);
            throw new \RuntimeException('the host write failed half way');
        }

        return parent::execute($user, $draft, $run);
    }

    public function limits(): TaskLimits
    {
        if ($during = $this->duringLimits) {
            $this->duringLimits = null;
            $during();
        }

        return $this->limits ?? parent::limits();
    }
}
