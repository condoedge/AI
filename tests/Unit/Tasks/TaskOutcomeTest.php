<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Tasks;

use Condoedge\Ai\DTOs\Tasks\TaskOutcome;
use PHPUnit\Framework\TestCase;

class TaskOutcomeTest extends TestCase
{
    /** @test */
    public function follow_ups_survive_the_round_trip(): void
    {
        $done = TaskOutcome::success(['id' => 7], 'Booked.')
            ->withLink('https://example.test/bookings/7', 'bookings.open')
            ->withFollowUps([
                ['url' => 'https://example.test/rooms', 'label' => 'rooms.open'],
                ['task' => 'book_room', 'label' => 'book.start'],
                ['url' => 'https://example.test/no-label'],
                ['task' => 7, 'label' => 'x'],
                'not an item',
            ]);

        $this->assertSame([
            ['url' => 'https://example.test/rooms', 'label' => 'rooms.open'],
            ['task' => 'book_room', 'label' => 'book.start'],
        ], $done->followUps, 'only well-formed items are kept');
        $this->assertSame(['url' => 'https://example.test/bookings/7', 'label' => 'bookings.open'], $done->link);
        $this->assertEquals($done, TaskOutcome::fromArray($done->toArray()));
        $this->assertSame([], TaskOutcome::fromArray(['succeeded' => true])->followUps, 'an outcome stored before follow-ups has none');
    }

    /** @test */
    public function a_task_follow_up_keeps_the_groups_it_starts_at(): void
    {
        $done = TaskOutcome::success()->withFollowUps([
            ['task' => 'book_room', 'label' => 'book.payment', 'groups' => ['payment', 7, 'extras']],
            ['task' => 'book_room', 'label' => 'book.start', 'groups' => 'payment'],
            ['url' => 'https://example.test/rooms', 'label' => 'rooms.open', 'groups' => ['payment']],
        ]);

        $this->assertSame([
            ['task' => 'book_room', 'label' => 'book.payment', 'groups' => ['payment', 'extras']],
            ['task' => 'book_room', 'label' => 'book.start'],
            ['url' => 'https://example.test/rooms', 'label' => 'rooms.open'],
        ], $done->followUps);
        $this->assertEquals($done, TaskOutcome::fromArray($done->toArray()));
    }
}
