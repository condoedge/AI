<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Tasks;

use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Models\AiTaskEvent;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;
use Condoedge\Ai\Services\Tasks\TaskPlanner;
use Condoedge\Ai\Services\Tasks\TaskStepCaller;
use Condoedge\Ai\Services\Tasks\TaskStore;
use Condoedge\Ai\Tests\Fixtures\ScriptedJsonLlm;
use Condoedge\Ai\Tests\Fixtures\Tasks\BookRoomTask;
use Condoedge\Ai\Tests\Fixtures\Tasks\EditBookingTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * A task that edits a record (TaskEditsRecordInterface): the draft starts bound to the record,
 * a group's other fields keep the record's values, the step prompt shows them, and only the
 * changed rows reach the confirm card.
 */
class EditRecordTaskTest extends TestCase
{
    use RefreshDatabase;

    private EditBookingTask $definition;
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
        $app['config']->set('ai.tasks', ['enabled' => true, 'definitions' => [EditBookingTask::class, BookRoomTask::class]]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['translator']->addJsonPath(__DIR__ . '/../../../resources/lang');
        $this->definition = new EditBookingTask();
        $this->app->instance(EditBookingTask::class, $this->definition);
        $this->user = (object) ['id' => 7, 'current_team_id' => 3];
    }

    private static function reply(array $updates = [], string $question = ''): array
    {
        return ['updates' => array_map(fn ($value, $field) => ['field' => $field, 'value' => $value], $updates, array_keys($updates)),
            'question' => $question, 'choices' => [], 'explanation' => null, 'wants_to_cancel' => false, 'asks_about' => null];
    }

    private function orchestrator(array $replies): TaskOrchestrator
    {
        $this->llm = new ScriptedJsonLlm($replies);

        return new TaskOrchestrator(new TaskStore(), new TaskPlanner(), new TaskStepCaller($this->llm));
    }

    private function started(TaskOrchestrator $orchestrator, string $key = 'edit_booking'): AiTask
    {
        $conversation = AiConversation::create(['status' => 'active', 'user_id' => 7, 'team_id' => 3]);
        $response = $orchestrator->start($this->user, $conversation, $key);

        return AiTask::where('uuid', $response['metadata']['task']['uuid'])->firstOrFail();
    }

    private function turn(TaskOrchestrator $orchestrator, AiTask $task, string $message): array
    {
        return $orchestrator->handleTurn($task->fresh(), $message, ['user' => $this->user]);
    }

    /** A started task whose draft is set to these values and groups, then one turn that answers nothing new. */
    private function answered(TaskOrchestrator $orchestrator, array $values, array $groups = [], array $declined = ['billing', 'dates']): array
    {
        $task = $this->started($orchestrator);
        (new TaskStore())->save($task, new TaskDraft($values, $groups, $task->version, $declined));

        return [$task, $this->turn($orchestrator, $task, 'that is all')];
    }

    private const SAME = ['booking' => 'b1', 'room' => 'small', 'day' => '2026-03-03', 'email' => 'jane@example.test'];

    /** @return list<string> */
    private function kinds(AiTask $task): array
    {
        return AiTaskEvent::where('task_id', $task->id)->orderBy('id')->pluck('kind')->all();
    }

    /** @test */
    public function the_draft_starts_with_the_initial_values_audited_as_prefilled(): void
    {
        $task = $this->started($this->orchestrator([self::reply([], 'Which room, day and email?')]));

        $this->assertSame(['booking' => 'b1'], $task->draft()->values);
        $this->assertSame(['started', 'extracted', 'asked'], $this->kinds($task));
        $prefilled = AiTaskEvent::where('task_id', $task->id)->where('kind', 'extracted')->sole();
        $this->assertSame(['booking', null, 'b1', ['prefilled' => 1]], [$prefilled->field, $prefilled->value_before, $prefilled->value_after, $prefilled->payload]);
        $this->assertSame(['room', 'day', 'email'], AiTaskEvent::where('task_id', $task->id)->where('kind', 'asked')->sole()->payload['fields']);
        $this->assertTrue((new TaskStore())->verifyChain($task));
    }

    /** @test */
    public function an_invalid_initial_value_is_not_kept(): void
    {
        $this->definition->initial = ['booking' => 'b9', 'room' => 'huge', 'ghost' => 1, 'day' => '2026-04-01'];

        $task = $this->started($this->orchestrator([self::reply([], 'Which booking?')]));

        $this->assertSame(['day' => '2026-04-01'], $task->draft()->values);
        $this->assertSame(['day'], AiTaskEvent::where('task_id', $task->id)->where('kind', 'extracted')->pluck('field')->all());
    }

    /** @test */
    public function accepting_a_group_prefills_its_other_fields_from_the_record(): void
    {
        $orchestrator = $this->orchestrator([
            self::reply([], 'Which room, day and email?'),
            self::reply(['room' => 'small', 'day' => '2026-03-03', 'email' => 'jane@example.test']),
            self::reply([], 'What do you want to change: billing, dates?'),
            self::reply(['end_day' => '2026-03-12']),
        ]);
        $task = $this->started($orchestrator);
        $this->turn($orchestrator, $task, 'same room, same day, same email');

        $response = $this->turn($orchestrator, $task, 'push the end back to March 12');

        $task->refresh();
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->status, 'no question about the start day, no other group offered');
        $this->assertSame(['2026-03-01', '2026-03-12'], [$task->draft()->get('start_day'), $task->draft()->get('end_day')]);
        $prefilled = AiTaskEvent::where('task_id', $task->id)->where('kind', 'extracted')->where('field', 'start_day')->sole();
        $this->assertSame(['2026-03-01', ['prefilled' => 1]], [$prefilled->value_after, $prefilled->payload]);
        $this->assertSame([['dates'], ['billing']], [$task->draft()->groups, $task->draft()->declined]);
        $this->assertNull($task->draft()->get('price'), 'a declined group is not prefilled');
        $this->assertSame(['booking', 'end_day', '_notice'], array_column($response['metadata']['task']['summary'], 'field'));
        $this->assertCount(4, $this->llm->calls);
    }

    /** @test */
    public function a_change_given_with_the_first_answer_goes_to_review_without_offering_the_groups(): void
    {
        $orchestrator = $this->orchestrator([
            self::reply([], 'Which room, day and email?'),
            self::reply(['room' => 'small', 'day' => '2026-03-03', 'email' => 'jane@example.test', 'price' => 150]),
        ]);
        $task = $this->started($orchestrator);

        $response = $this->turn($orchestrator, $task, 'same room, day and email; the price goes to 150');

        $task->refresh();
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->status);
        $this->assertSame([150, ['billing'], []], [$task->draft()->get('price'), $task->draft()->groups, $task->draft()->declined]);
        $this->assertSame(['booking', 'price', '_notice'], array_column($response['metadata']['task']['summary'], 'field'));
        $this->assertCount(2, $this->llm->calls);
    }

    /** @test */
    public function the_step_prompt_shows_current_values_but_never_a_pii_one(): void
    {
        $this->started($this->orchestrator([self::reply([], 'Which room?')]));

        $prompt = $this->llm->userMessage(0);
        $this->assertStringContainsString('- room (enum): book.room. Options: small: book.room.small; large: book.room.large. Currently: small.', $prompt);
        $this->assertStringContainsString('- day (date): book.day. Currently: 2026-03-03.', $prompt);
        $this->assertStringContainsString("- email (text): book.email.\n", $prompt);
        $this->assertStringNotContainsString('jane@example.test', $prompt);
    }

    /** @test */
    public function the_summary_shows_only_changed_rows_and_the_card_shows_before(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);

        [$task, $response] = $this->answered($orchestrator, ['room' => 'large'] + self::SAME);

        $rows = $response['metadata']['task']['summary'];
        $this->assertSame(['booking', 'room', '_notice'], array_column($rows, 'field'));
        $this->assertSame(['large', 'small'], [$rows[1]['value'], $rows[1]['before']]);
        $shown = AiTaskEvent::where('task_id', $task->id)->where('kind', 'summary_shown')->sole();
        $this->assertSame(['booking', 'room', '_notice'], $shown->payload['fields']);
    }

    /** @test */
    public function an_unchanged_money_row_is_neither_shown_nor_required_at_confirm(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task, $response] = $this->answered($orchestrator, ['room' => 'large', 'price' => 120] + self::SAME, ['billing'], ['dates']);

        $this->assertNotContains('price', array_column($response['metadata']['task']['summary'], 'field'));

        $orchestrator->confirm($task->fresh(), $response['metadata']['task']['token'], ['user' => $this->user, 'acknowledged' => ['_notice']]);

        $this->assertSame(AiTask::STATUS_DONE, $task->fresh()->status);
        $this->assertSame(['_notice'], AiTaskEvent::where('task_id', $task->id)->where('kind', 'confirmed')->sole()->payload['acknowledged']);
        $this->assertSame('large', $this->definition->bookings['b1']['room']);
    }

    /** @test */
    public function nothing_changed_answers_so_and_offers_the_groups_again(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply(), self::reply([], 'Billing or dates?')]);

        [$task, $response] = $this->answered($orchestrator, self::SAME);

        $task->refresh();
        $this->assertSame(AiTask::STATUS_COLLECTING, $task->status);
        $this->assertSame([[], []], [$task->draft()->groups, $task->draft()->declined]);
        $this->assertSame(__('ai.tasks.nothing-to-change') . "\n\nBilling or dates?", $response['answer']);
        $this->assertArrayNotHasKey('token', $response['metadata']['task']);
        $this->assertSame(['_groups', '_groups', '_groups'], array_column($response['metadata']['task']['choices'], 'field'), 'the offer chips again');
        $this->assertNotContains('token_issued', $this->kinds($task));
        $this->assertSame(0, $this->definition->executed);
    }

    /** @test */
    public function a_record_changed_between_the_summary_and_the_confirm_shows_the_summary_again_with_the_new_value(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task, $response] = $this->answered($orchestrator, ['room' => 'large'] + self::SAME);
        $this->definition->bookings['b1']['day'] = '2026-03-04';

        $again = $orchestrator->confirm($task->fresh(), $response['metadata']['task']['token'], ['user' => $this->user, 'acknowledged' => ['_notice']]);

        $this->assertSame(0, $this->definition->executed, 'no write over a value changed meanwhile');
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->fresh()->status);
        $this->assertStringContainsString(__('ai.tasks.changed-since'), $again['answer']);
        $rows = array_column($again['metadata']['task']['summary'], null, 'field');
        $this->assertSame(['2026-03-03', '2026-03-04'], [$rows['day']['value'], $rows['day']['before']], 'the new "was" is shown');

        $orchestrator->confirm($task->fresh(), $again['metadata']['task']['token'], ['user' => $this->user, 'acknowledged' => ['_notice']]);
        $this->assertSame(1, $this->definition->executed);
    }

    /** @test */
    public function the_bound_record_and_the_hosts_own_rows_are_not_changeable(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Q?'), self::reply()]);
        [$task, $response] = $this->answered($orchestrator, ['room' => 'large'] + self::SAME);

        $this->assertSame(['booking' => false, 'room' => true, '_notice' => false],
            array_column($response['metadata']['task']['summary'], 'changeable', 'field'), 'a single option leaves nothing to change to');
        $refused = $orchestrator->reopen($task->fresh(), 'booking', ['user' => $this->user]);
        $this->assertSame(__('ai.tasks.not-changeable'), $refused['answer']);
        $this->assertSame('b1', $task->fresh()->draft()->get('booking'));
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->fresh()->status);
    }

    /** @test */
    public function a_start_with_a_group_asks_its_fields_first_and_offers_no_other_once_it_holds_a_change(): void
    {
        $this->definition->initial = self::SAME;
        $orchestrator = $this->orchestrator([self::reply([], 'Which start and end days?'), self::reply(['end_day' => '2026-03-12'])]);
        $conversation = AiConversation::create(['status' => 'active', 'user_id' => 7, 'team_id' => 3]);

        $orchestrator->start($this->user, $conversation, 'edit_booking', ['groups' => ['dates', 'nope']]);

        $task = AiTask::sole();
        $this->assertSame(['start_day', 'end_day'], AiTaskEvent::where('task_id', $task->id)->where('kind', 'asked')->sole()->payload['fields']);
        $this->assertSame(['definition' => 'edit_booking', 'groups' => ['dates']], AiTaskEvent::where('task_id', $task->id)->where('kind', 'started')->sole()->payload);

        $response = $this->turn($orchestrator, $task, 'push the end back to March 12');

        $task->refresh();
        $this->assertSame(AiTask::STATUS_REVIEWING, $task->status, 'no group offer');
        $this->assertSame([['dates'], []], [$task->draft()->groups, $task->draft()->declined]);
        $this->assertSame(['booking', 'end_day', '_notice'], array_column($response['metadata']['task']['summary'], 'field'));
        $this->assertCount(2, $this->llm->calls);
    }

    /** @test */
    public function a_definition_without_the_contract_is_unchanged(): void
    {
        $orchestrator = $this->orchestrator([self::reply([], 'Which room, day and how many?'), self::reply()]);
        $task = $this->started($orchestrator, 'book_room');
        $this->assertSame([], $task->draft()->values);
        (new TaskStore())->save($task, (new TaskDraft(['room' => 'large', 'day' => '2026-03-03', 'attendees' => 4, 'catering' => 0, 'title' => 'Board'],
            [], $task->version, ['equipment', 'payment', 'extras'])));

        $response = $this->turn($orchestrator, $task, 'that is all');
        $orchestrator->confirm($task->fresh(), $response['metadata']['task']['token'], ['user' => $this->user]);

        $this->assertSame(['started', 'asked', 'answered', 'validated', 'summary_shown', 'token_issued', 'confirmed', 'executed'], $this->kinds($task));
        $this->assertSame(2, $task->fresh()->version, 'the start saved no draft: only the test did');
    }
}
