<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Tasks;

use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\DTOs\Tasks\TaskLimits;
use Condoedge\Ai\DTOs\Tasks\TaskOutcome;
use Condoedge\Ai\DTOs\Tasks\TaskSummary;
use PHPUnit\Framework\TestCase;

class TaskValueObjectsTest extends TestCase
{
    /** @test */
    public function a_draft_is_immutable_and_keeps_its_version(): void
    {
        $draft = new TaskDraft(['room' => 'small'], ['equipment'], 4);
        $next = $draft->with('day', '2026-03-03')->without(['room'])->withGroup('payment')->withGroup('payment');

        $this->assertSame(['room' => 'small'], $draft->values);
        $this->assertSame(['day' => '2026-03-03'], $next->values);
        $this->assertSame(['equipment', 'payment'], $next->groups);
        $this->assertSame(4, $next->version);
        $this->assertTrue($next->has('day'));
        $this->assertFalse($next->has('room'));
        $this->assertSame('x', $next->get('room', 'x'));
    }

    /** @test */
    public function a_draft_round_trips_through_its_stored_shape(): void
    {
        $draft = new TaskDraft(['room' => 'small'], ['equipment'], 2);

        $this->assertSame(['values' => ['room' => 'small'], 'groups' => ['equipment'], 'declined' => []], $draft->toArray());
        $this->assertEquals($draft, TaskDraft::fromArray($draft->toArray(), 2));
        $declined = $draft->withDeclinedGroup('payment');
        $this->assertEquals($declined, TaskDraft::fromArray($declined->toArray(), 2));
        $this->assertEquals(new TaskDraft([], [], 3), TaskDraft::fromArray(null, 3));
    }

    /** @test */
    public function a_draft_keeps_declined_groups_apart_from_accepted_ones(): void
    {
        $draft = (new TaskDraft(['room' => 'small'], ['equipment'], 2))->withDeclinedGroup('payment')->withDeclinedGroup('payment');
        $next = $draft->with('day', '2026-03-03')->without(['room'])->withGroup('extras')->atVersion(3);

        $this->assertSame(['payment'], $draft->declined);
        $this->assertSame(['equipment'], $draft->groups);
        $this->assertSame(['payment'], $next->declined);
        $this->assertSame(['equipment', 'extras'], $next->groups);
        $this->assertTrue($next->hasAnswered('payment'));
        $this->assertTrue($next->hasAnswered('equipment'));
        $this->assertFalse($next->hasAnswered('other'));
    }

    /** @test */
    public function limits_default_to_the_plan_numbers(): void
    {
        $limits = new TaskLimits();

        $this->assertSame(40, $limits->maxTurns);
        $this->assertSame(86400, $limits->ttlSeconds);
        $this->assertSame(60, $limits->maxLlmCalls);
        $this->assertSame(3, $limits->maxExecutedPerUserPerDay);
        $this->assertSame(10, $limits->maxExecutedPerTeamPerDay);
    }

    /** @test */
    public function an_outcome_round_trips_through_its_stored_shape(): void
    {
        $done = TaskOutcome::success(['id' => 7], 'Booked.');
        $failed = TaskOutcome::failure('Room taken.');

        $this->assertTrue($done->succeeded);
        $this->assertFalse($failed->succeeded);
        $this->assertEquals($done, TaskOutcome::fromArray($done->toArray()));
        $this->assertEquals($failed, TaskOutcome::fromArray($failed->toArray()));
    }

    /** @test */
    public function an_outcome_link_round_trips_with_its_label_key(): void
    {
        $done = TaskOutcome::success(['id' => 7], 'Booked.')->withLink('https://example.test/bookings/7', 'bookings.open');

        $this->assertSame(['url' => 'https://example.test/bookings/7', 'label' => 'bookings.open'], $done->link);
        $this->assertSame(['id' => 7], $done->data);
        $this->assertEquals($done, TaskOutcome::fromArray($done->toArray()));
        $this->assertSame([], TaskOutcome::success()->link);
    }

    /** @test */
    public function a_summary_lists_the_rows_needing_explicit_confirmation(): void
    {
        $summary = new TaskSummary([
            ['field' => 'room', 'label' => 'Room', 'value' => 'Small', 'risk' => 'none', 'source' => 'user'],
            ['field' => 'deposit', 'label' => 'Deposit', 'value' => '10 %', 'risk' => 'money', 'source' => 'recommended'],
        ]);

        $this->assertSame(['deposit'], array_column($summary->rowsToConfirm(), 'field'));
    }
}
