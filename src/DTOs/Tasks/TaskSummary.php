<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Tasks;

/**
 * The confirm card of a task, built by code from the draft (never by the model).
 */
final class TaskSummary
{
    /** The risks whose rows the user ticks on the card, which also badges them. */
    public const RISKS_TO_CONFIRM = ['money', 'legal', 'outward'];

    /**
     * @param list<array{field: string, label: string, value: string, risk: string, source: string}> $rows
     */
    public function __construct(
        public readonly array $rows,
    ) {
    }

    /** Money, legal and outward (sent or published) rows must be confirmed explicitly on the card. */
    public function rowsToConfirm(): array
    {
        return array_values(array_filter($this->rows, fn (array $row) => in_array($row['risk'], self::RISKS_TO_CONFIRM, true)));
    }
}
