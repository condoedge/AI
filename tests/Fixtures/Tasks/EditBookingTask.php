<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures\Tasks;

use Condoedge\Ai\Contracts\TaskDefinitionInterface;
use Condoedge\Ai\Contracts\TaskEditsRecordInterface;
use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\DTOs\Tasks\TaskField;
use Condoedge\Ai\DTOs\Tasks\TaskKnowledge;
use Condoedge\Ai\DTOs\Tasks\TaskLimits;
use Condoedge\Ai\DTOs\Tasks\TaskOutcome;
use Condoedge\Ai\DTOs\Tasks\TaskSummary;
use Condoedge\Ai\Models\AiTask;

/**
 * A domain-neutral task that edits a record, for the edit-record tests: change an in-memory booking.
 */
class EditBookingTask implements TaskDefinitionInterface, TaskEditsRecordInterface
{
    public array $bookings = ['b1' => [
        'room' => 'small', 'day' => '2026-03-03', 'email' => 'jane@example.test', 'price' => 120, 'start_day' => '2026-03-01', 'end_day' => '2026-03-05',
    ]];
    public array $initial = ['booking' => 'b1'];
    public int $executed = 0;

    public function key(): string
    {
        return 'edit_booking';
    }

    public function label(): string
    {
        return 'Change a booking';
    }

    public function startableBy(mixed $user): bool
    {
        return $user !== null;
    }

    public function fields(mixed $user, TaskDraft $draft): array
    {
        return [
            new TaskField('booking', 'book.booking', 'enum', options: array_combine(array_keys($this->bookings), array_keys($this->bookings))),
            new TaskField('room', 'book.room', 'enum', options: ['small' => 'book.room.small', 'large' => 'book.room.large']),
            new TaskField('day', 'book.day', 'date'),
            new TaskField('email', 'book.email', 'text', pii: 1),
            new TaskField('price', 'book.price', 'money', risk: 'money', group: 'billing'),
            new TaskField('start_day', 'book.start-day', 'date', group: 'dates'),
            new TaskField('end_day', 'book.end-day', 'date', group: 'dates'),
        ];
    }

    public function groupLabel(string $group): string
    {
        return 'book.group-' . $group;
    }

    public function knowledge(): TaskKnowledge
    {
        return new TaskKnowledge();
    }

    public function validate(mixed $user, TaskDraft $draft): array
    {
        return [];
    }

    public function summary(mixed $user, TaskDraft $draft): TaskSummary
    {
        $current = $this->currentValues($user, $draft);
        $rows = [];
        foreach ($this->fields($user, $draft) as $field) {
            if ($draft->has($field->key)) {
                $rows[] = ['field' => $field->key, 'label' => $field->labelKey, 'value' => (string) $draft->get($field->key), 'risk' => $field->risk,
                    'source' => 'your choice'] + (isset($current[$field->key]) ? ['before' => (string) $current[$field->key]] : []);
            }
        }
        $rows[] = ['field' => '_notice', 'label' => 'book.notice', 'value' => 'The attendees are told of the change.', 'risk' => 'outward', 'source' => ''];

        return new TaskSummary($rows);
    }

    public function execute(mixed $user, TaskDraft $draft, AiTask $run): TaskOutcome
    {
        $this->executed++;
        $id = $draft->get('booking');
        $this->bookings[$id] = [...$this->bookings[$id], ...array_diff_key($draft->values, ['booking' => 1])];

        return TaskOutcome::success(['booking' => $id]);
    }

    public function limits(): TaskLimits
    {
        return new TaskLimits();
    }

    public function manualUrl(mixed $user): ?string
    {
        return 'https://example.test/bookings';
    }

    public function initialValues(mixed $user): array
    {
        return $this->initial;
    }

    public function currentValues(mixed $user, TaskDraft $draft): array
    {
        return $this->bookings[$draft->get('booking')] ?? [];
    }
}
