<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures\Tasks;

use Condoedge\Ai\Contracts\TaskDefinitionInterface;
use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\DTOs\Tasks\TaskField;
use Condoedge\Ai\DTOs\Tasks\TaskKnowledge;
use Condoedge\Ai\DTOs\Tasks\TaskLimits;
use Condoedge\Ai\DTOs\Tasks\TaskOutcome;
use Condoedge\Ai\DTOs\Tasks\TaskSummary;
use Condoedge\Ai\Models\AiTask;

/**
 * A domain-neutral task for the task tests: book a meeting room.
 */
class BookRoomTask implements TaskDefinitionInterface
{
    public function key(): string
    {
        return 'book_room';
    }

    public function label(): string
    {
        return 'book.start';
    }

    public function startableBy(mixed $user): bool
    {
        return $user !== null;
    }

    public function fields(mixed $user, TaskDraft $draft): array
    {
        return [
            new TaskField('room', 'book.room', 'enum', options: ['small' => 'book.room.small', 'large' => 'book.room.large']),
            new TaskField('day', 'book.day', 'date'),
            new TaskField('attendees', 'book.attendees', 'int', rules: ['min:1', 'max:20']),
            new TaskField('catering', 'book.catering', 'bool'),
            new TaskField('catering_budget', 'book.catering-budget', 'money', requiredWhen: [self::class, 'wantsCatering'], risk: 'money'),
            new TaskField('vegan_share', 'book.vegan-share', 'percent', requiredWhen: [self::class, 'hasCateringBudget']),
            new TaskField('title', 'book.title', 'text', default: [self::class, 'defaultTitle']),
            new TaskField('projector', 'book.projector', 'bool', group: 'equipment'),
            new TaskField('deposit', 'book.deposit', 'percent', rules: ['numeric', 'max:50'], risk: 'money', group: 'payment'),
            new TaskField('whiteboard', 'book.whiteboard', 'bool', group: 'equipment'),
            new TaskField('screen_size', 'book.screen-size', 'int', requiredWhen: [self::class, 'wantsProjector'], group: 'equipment'),
            new TaskField('cables', 'book.cables', 'bool', group: 'equipment'),
            new TaskField('notes', 'book.notes', 'text', group: 'extras'),
        ];
    }

    public function groupLabel(string $group): string
    {
        return 'book.group-' . $group;
    }

    public function knowledge(): TaskKnowledge
    {
        return new TaskKnowledge(audiences: ['staff'], slugs: ['rooms']);
    }

    public function validate(mixed $user, TaskDraft $draft): array
    {
        return [];
    }

    public function summary(mixed $user, TaskDraft $draft): TaskSummary
    {
        return new TaskSummary([]);
    }

    public function execute(mixed $user, TaskDraft $draft, AiTask $run): TaskOutcome
    {
        return TaskOutcome::success(['booking' => $run->uuid]);
    }

    public function limits(): TaskLimits
    {
        return new TaskLimits();
    }

    public function manualUrl(mixed $user): ?string
    {
        return 'https://example.test/rooms/new';
    }

    public function wantsCatering(TaskDraft $draft): bool
    {
        return (bool) $draft->get('catering');
    }

    public function hasCateringBudget(TaskDraft $draft): bool
    {
        return $draft->has('catering_budget');
    }

    public function wantsProjector(TaskDraft $draft): bool
    {
        return (bool) $draft->get('projector');
    }

    public function defaultTitle(TaskDraft $draft): string
    {
        return 'Meeting in the ' . $draft->get('room', 'small') . ' room';
    }
}
