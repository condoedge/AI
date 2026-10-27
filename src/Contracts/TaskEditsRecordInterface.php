<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\DTOs\Tasks\TaskDraft;

/**
 * A task that changes an existing record: the draft starts bound to it and only changed values reach the confirm card.
 *
 * Optional, next to TaskDefinitionInterface. The orchestrator prefills the draft with `initialValues()`, fills
 * the other fields of a group from `currentValues()` once one of them is answered, shows the step prompt each
 * field's current value (never a `pii` one), and drops the summary rows whose value is the record's.
 */
interface TaskEditsRecordInterface
{
    /** Values the draft starts with, e.g. which record; keys are declared fields. */
    public function initialValues(mixed $user): array;

    /** The record's current values by field key; [] while no record is chosen. */
    public function currentValues(mixed $user, TaskDraft $draft): array;
}
