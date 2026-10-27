<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Tasks;

/**
 * What one step call of a task gave, after code checked it: the valid values of the asked
 * fields, the refused ones (for the audit), the model's next question, and whether the
 * message was about the task at all ($aboutTask false: a question about something else).
 */
final class TaskStep
{
    /**
     * @param array<string, mixed> $values
     * @param list<array{field: mixed, value: mixed, reason: string}> $rejected reason: undeclared|invalid
     * @param list<string> $choices
     */
    public function __construct(
        public readonly array $values = [],
        public readonly array $rejected = [],
        public readonly string $question = '',
        public readonly array $choices = [],
        public readonly ?string $explanation = null,
        public readonly bool $wantsToCancel = false,
        public readonly ?string $asksAbout = null,
        public readonly bool $failed = false,
        public readonly int $tokens = 0,
        public readonly bool $aboutTask = true,
    ) {
    }
}
