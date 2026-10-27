<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Tasks;

use Condoedge\Ai\Contracts\TaskDefinitionInterface;
use Condoedge\Ai\Contracts\TaskEditsRecordInterface;
use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\DTOs\Tasks\TaskField;

/**
 * Decides, in code, which fields a task asks next: first the required fields that are
 * missing and apply, in the definition's order; then the fields of the optional groups
 * the user accepted, one theme at a time. The open groups are offered once nothing is left
 * to ask, except on a task that edits a record whose draft already holds the user's change.
 * No I/O of its own.
 */
class TaskPlanner
{
    public const MAX_STEP_FIELDS = 3;

    /**
     * Up to three fields of the plan's head, never mixing the required fields with a group or two groups.
     *
     * @return list<TaskField>
     */
    public function next(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft): array
    {
        $step = [];
        foreach ($this->plan($definition, $user, $draft) as $field) {
            if (count($step) === self::MAX_STEP_FIELDS || ($step !== [] && $field->group !== $step[0]->group)) {
                break;
            }
            $step[] = $field;
        }

        return $step;
    }

    /**
     * Every field still to ask: required ones in order, then accepted groups in the order they first appear.
     *
     * @return list<TaskField>
     */
    public function plan(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft): array
    {
        $required = [];
        $grouped = [];
        foreach ($definition->fields($user, $draft) as $field) {
            $accepted = $field->group !== null && in_array($field->group, $draft->groups, true);
            if ($accepted) {
                $grouped[$field->group] ??= [];   // a theme keeps the place of its first field, asked or not
            }
            if ($draft->has($field->key) || !$field->applies($draft, $user)) {
                continue;
            }
            if ($field->group === null) {
                $required[] = $field;
            } elseif ($accepted) {
                $grouped[$field->group][] = $field;
            }
        }

        return array_merge($required, ...array_values($grouped));
    }

    /**
     * The optional groups to offer together: none while anything is left to ask or once an edit
     * task's draft holds a change (the user said what they came to change); else every group, in
     * field order, the user neither accepted nor declined that still has a field to ask.
     *
     * @return list<string>
     */
    public function groupsToOffer(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft): array
    {
        if ($this->plan($definition, $user, $draft) !== [] || $this->changes($definition, $user, $draft)) {
            return [];
        }
        $groups = [];
        foreach ($definition->fields($user, $draft) as $field) {
            if ($field->group !== null && !$draft->hasAnswered($field->group) && !$draft->has($field->key) && $field->applies($draft, $user)) {
                $groups[$field->group] = true;
            }
        }

        return array_keys($groups);
    }

    /** Whether an edit task's draft holds a value other than the record's or its initial ones; never for other tasks. */
    public function changes(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft): bool
    {
        if (!$definition instanceof TaskEditsRecordInterface) {
            return false;
        }
        $kept = $definition->currentValues($user, $draft) + $definition->initialValues($user);
        foreach ($definition->fields($user, $draft) as $field) {
            if ($draft->has($field->key) && (!array_key_exists($field->key, $kept) || !self::same($kept[$field->key], $draft->get($field->key)))) {
                return true;
            }
        }

        return false;
    }

    /** Values compared as strings, arrays as JSON. */
    public static function same(mixed $a, mixed $b): bool
    {
        $text = static fn (mixed $value): string => is_array($value) ? (string) json_encode($value) : (string) (is_bool($value) ? (int) $value : $value);

        return $text($a) === $text($b);
    }

    /**
     * Removes the values of fields that are not declared, whose condition no longer holds or that
     * their field no longer accepts, until stable (a removal can turn off another field's condition).
     *
     * @return array{0: TaskDraft, 1: list<string>} the new draft and the removed keys, for the audit
     */
    public function prune(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft): array
    {
        $removed = [];
        do {
            $applicable = [];
            foreach ($definition->fields($user, $draft) as $field) {
                if ($field->applies($draft, $user) && (!$draft->has($field->key) || $field->isValid($draft->get($field->key)))) {
                    $applicable[$field->key] = true;
                }
            }
            $stale = array_keys(array_diff_key($draft->values, $applicable));
            $draft = $draft->without($stale);
            $removed = [...$removed, ...$stale];
        } while ($stale !== []);

        return [$draft, $removed];
    }
}
