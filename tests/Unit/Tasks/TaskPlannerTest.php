<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Tasks;

use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\DTOs\Tasks\TaskField;
use Condoedge\Ai\Services\Tasks\TaskPlanner;
use Condoedge\Ai\Tests\Fixtures\Tasks\BookRoomTask;
use Condoedge\Ai\Tests\Fixtures\Tasks\EditBookingTask;
use Orchestra\Testbench\TestCase;

class TaskPlannerTest extends TestCase
{
    private function next(array $values, array $groups = []): array
    {
        $fields = (new TaskPlanner())->next(new BookRoomTask(), null, new TaskDraft($values, $groups));

        return array_map(fn (TaskField $field) => $field->key, $fields);
    }

    /** @test */
    public function it_asks_the_first_three_missing_required_fields_in_order(): void
    {
        $this->assertSame(['room', 'day', 'attendees'], $this->next([]));
        $this->assertSame(['day', 'attendees', 'catering'], $this->next(['room' => 'small']));
    }

    /** @test */
    public function a_conditional_field_is_asked_only_while_its_condition_holds(): void
    {
        $base = ['room' => 'small', 'day' => '2026-03-03', 'attendees' => 4];

        $this->assertSame(['catering', 'title'], $this->next($base));
        $this->assertSame(['title'], $this->next($base + ['catering' => 0]));
        $this->assertSame(['catering_budget', 'title'], $this->next($base + ['catering' => 1]));
        $this->assertSame(['vegan_share', 'title'], $this->next($base + ['catering' => 1, 'catering_budget' => 100]));
    }

    /** @test */
    public function optional_groups_are_asked_only_when_accepted_and_by_theme(): void
    {
        $done = ['room' => 'small', 'day' => '2026-03-03', 'attendees' => 4, 'catering' => 0, 'title' => 'Board'];

        $this->assertSame([], $this->next($done));
        $this->assertSame(['deposit'], $this->next($done, ['payment']));
        $this->assertSame(['projector', 'whiteboard', 'cables'], $this->next($done, ['equipment', 'payment']));
        $this->assertSame(['screen_size', 'cables'], $this->next($done + ['projector' => 1, 'whiteboard' => 0], ['equipment', 'payment']));
        $this->assertSame(['deposit'], $this->next($done + ['projector' => 0, 'whiteboard' => 0, 'cables' => 1], ['equipment', 'payment']));
    }

    /** @test */
    public function a_step_never_mixes_required_fields_with_a_group_or_two_groups(): void
    {
        $almost = ['room' => 'small', 'day' => '2026-03-03', 'attendees' => 4, 'catering' => 0];

        $this->assertSame(['title'], $this->next($almost, ['equipment']));
        $this->assertSame(['notes'], $this->next($almost + ['title' => 'Board', 'deposit' => 10], ['payment', 'extras']));
    }

    /** @test */
    public function the_full_plan_lists_required_then_accepted_groups_by_theme(): void
    {
        $plan = (new TaskPlanner())->plan(new BookRoomTask(), null, new TaskDraft(['catering' => 1, 'projector' => 1], ['payment', 'equipment']));

        $this->assertSame(
            ['room', 'day', 'attendees', 'catering_budget', 'title', 'whiteboard', 'screen_size', 'cables', 'deposit'],
            array_map(fn (TaskField $field) => $field->key, $plan),
        );
    }

    /** @test */
    public function pruning_removes_values_whose_condition_stopped_holding_until_stable(): void
    {
        $draft = new TaskDraft(['catering' => 0, 'catering_budget' => 100, 'vegan_share' => 20, 'room' => 'small'], ['equipment'], 3);

        [$pruned, $removed] = (new TaskPlanner())->prune(new BookRoomTask(), null, $draft);

        $this->assertSame(['catering' => 0, 'room' => 'small'], $pruned->values);
        $this->assertSame(['catering_budget', 'vegan_share'], $removed);
        $this->assertSame(['equipment'], $pruned->groups);
        $this->assertSame(3, $pruned->version);
        $this->assertSame(100, $draft->get('catering_budget'), 'the input draft is untouched');
    }

    /** @test */
    public function pruning_removes_values_for_undeclared_fields_and_keeps_a_valid_draft_as_is(): void
    {
        $planner = new TaskPlanner();

        [$pruned, $removed] = $planner->prune(new BookRoomTask(), null, new TaskDraft(['room' => 'small', 'rate' => 0]));
        $this->assertSame(['room' => 'small'], $pruned->values);
        $this->assertSame(['rate'], $removed);

        $valid = new TaskDraft(['catering' => 1, 'catering_budget' => 100]);
        [$same, $none] = $planner->prune(new BookRoomTask(), null, $valid);
        $this->assertSame($valid->values, $same->values);
        $this->assertSame([], $none);
    }

    /** @test */
    public function pruning_removes_a_value_its_field_no_longer_accepts(): void
    {
        [$pruned, $removed] = (new TaskPlanner())->prune(new BookRoomTask(), null, new TaskDraft(['room' => 'huge', 'attendees' => 50, 'day' => '2026-03-03']));

        $this->assertSame(['day' => '2026-03-03'], $pruned->values);
        $this->assertSame(['room', 'attendees'], $removed);
    }

    /** @test */
    public function every_open_group_is_offered_at_once_after_the_required_fields(): void
    {
        $planner = new TaskPlanner();
        $offer = fn (array $values, array $groups = [], array $declined = []) => $planner->groupsToOffer(new BookRoomTask(), null, new TaskDraft($values, $groups, 1, $declined));
        $done = ['room' => 'small', 'day' => '2026-03-03', 'attendees' => 4, 'catering' => 0, 'title' => 'Board'];

        $this->assertSame([], $offer(['room' => 'small']), 'not while required fields are missing');
        $this->assertSame(['equipment', 'payment', 'extras'], $offer($done), 'in field order');
        $this->assertSame([], $offer($done, ['equipment']), 'not while an accepted group is still to ask');
        $equipped = $done + ['projector' => 0, 'whiteboard' => 0, 'cables' => 0];
        $this->assertSame(['payment', 'extras'], $offer($equipped, ['equipment']));
        $this->assertSame(['extras'], $offer($equipped, ['equipment'], ['payment']));
        $this->assertSame([], $offer($equipped, ['equipment'], ['payment', 'extras']));
    }

    /** @test */
    public function a_group_with_nothing_left_to_ask_is_not_offered(): void
    {
        $done = ['room' => 'small', 'day' => '2026-03-03', 'attendees' => 4, 'catering' => 0, 'title' => 'Board',
            'projector' => 0, 'whiteboard' => 1, 'cables' => 1];

        $this->assertSame(['payment', 'extras'], (new TaskPlanner())->groupsToOffer(new BookRoomTask(), null, new TaskDraft($done)));
    }

    /** @test */
    public function an_edit_tasks_draft_that_holds_a_change_offers_no_group(): void
    {
        $planner = new TaskPlanner();
        $definition = new EditBookingTask();
        $same = ['booking' => 'b1', 'room' => 'small', 'day' => '2026-03-03', 'email' => 'jane@example.test'];
        $changed = new TaskDraft(['room' => 'large'] + $same);

        $this->assertSame(['billing', 'dates'], $planner->groupsToOffer($definition, null, new TaskDraft($same)), 'nothing changed yet: the offer asks what to change');
        $this->assertFalse($planner->changes($definition, null, new TaskDraft($same)));
        $this->assertTrue($planner->changes($definition, null, $changed));
        $this->assertSame([], $planner->groupsToOffer($definition, null, $changed), 'the change is said: no other groups are offered');
    }
}
