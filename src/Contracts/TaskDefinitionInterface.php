<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\DTOs\Tasks\TaskField;
use Condoedge\Ai\DTOs\Tasks\TaskKnowledge;
use Condoedge\Ai\DTOs\Tasks\TaskLimits;
use Condoedge\Ai\DTOs\Tasks\TaskOutcome;
use Condoedge\Ai\DTOs\Tasks\TaskSummary;
use Condoedge\Ai\Models\AiTask;

/**
 * A task the assistant can run with a user: a multi-turn conversation that fills a draft
 * field by field and ends in one confirmed write. The host supplies the domain; the
 * package's orchestrator asks, validates, audits and confirms.
 */
interface TaskDefinitionInterface
{
    public function key(): string;

    /** The start chip's text: a translation key or plain text. */
    public function label(): string;

    /** Server-side role and team checks; re-checked at confirm. */
    public function startableBy(mixed $user): bool;

    /** @return list<TaskField> in the order they are asked */
    public function fields(mixed $user, TaskDraft $draft): array;

    /** The name of an optional group (TaskField::$group) on its offer chip: a translation key or plain text. */
    public function groupLabel(string $group): string;

    /** Which article audiences and slugs the step call may cite. */
    public function knowledge(): TaskKnowledge;

    /** @return array<string, string> cross-field and domain errors by field key; [] when valid */
    public function validate(mixed $user, TaskDraft $draft): array;

    /** The confirm card's rows, built by code: label and source are keys or text, value is shown as given (already translated). */
    public function summary(mixed $user, TaskDraft $draft): TaskSummary;

    /** The single write; runs inside the orchestrator's transaction and lock, idempotent by the run's uuid. */
    public function execute(mixed $user, TaskDraft $draft, AiTask $run): TaskOutcome;

    public function limits(): TaskLimits;

    /** Where the user does the same thing without the assistant, linked from every limit message; null when there is no such page. */
    public function manualUrl(mixed $user): ?string;
}
