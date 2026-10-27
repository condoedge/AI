<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Tasks;

use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\DTOs\Tasks\TaskLimits;
use Condoedge\Ai\Exceptions\TaskVersionConflictException;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Models\AiTaskEvent;
use Illuminate\Support\Str;

/**
 * Persists task runs (optimistic version, row lock) and their append-only audit events.
 *
 * Each event's hash is sha256(prev_hash . canonical JSON of its other fields), prev_hash
 * being the hash of the task's previous event, and the task row keeps the newest hash
 * (`last_event_hash`), so editing or removing any event breaks the chain. Append, save
 * and record under the task's row lock (`loadForUpdate`).
 */
class TaskStore
{
    private const HASHED = ['task_id', 'at', 'actor_user_id', 'ip', 'kind', 'field', 'value_before', 'value_after', 'payload'];

    public function create(string $definitionKey, int $conversationId, int $userId, ?int $teamId, TaskLimits $limits): AiTask
    {
        return AiTask::create([
            'uuid' => (string) Str::uuid(),
            'definition_key' => $definitionKey,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'team_id' => $teamId,
            'status' => AiTask::STATUS_COLLECTING,
            'draft' => (new TaskDraft())->toArray(),
            'version' => 1,
            'expires_at' => now()->addSeconds($limits->ttlSeconds),
        ])->refresh();
    }

    /** Call inside a DB transaction: the lock holds until it commits. */
    public function loadForUpdate(string $uuid): ?AiTask
    {
        return AiTask::where('uuid', $uuid)->lockForUpdate()->first();
    }

    /**
     * Writes the draft and the given columns only if the row is still at the draft's version.
     *
     * @param array<string, mixed> $attributes columns of ai_tasks other than draft and version
     * @return TaskDraft the saved draft at its new version
     * @throws TaskVersionConflictException when the row moved on; nothing is written
     */
    public function save(AiTask $task, TaskDraft $draft, array $attributes = []): TaskDraft
    {
        $changes = [...$attributes, 'draft' => $draft->toArray(), 'version' => $draft->version + 1];
        $columns = (new AiTask())->forceFill($changes)->getAttributes();

        $saved = AiTask::whereKey($task->getKey())->where('version', $draft->version)->update($columns);
        if ($saved === 0) {
            throw new TaskVersionConflictException("Task [{$task->uuid}] is no longer at version {$draft->version}.");
        }
        $task->refresh();

        return $draft->atVersion($task->version);
    }

    /**
     * Writes counters, status and timestamps without bumping `version`: the version names the
     * draft a confirm token was issued for, so only a draft change may make that token stale.
     *
     * @param array<string, mixed> $attributes columns of ai_tasks other than draft and version
     */
    public function record(AiTask $task, array $attributes): void
    {
        if (array_intersect_key($attributes, array_flip(['draft', 'version', 'last_event_hash'])) !== []) {
            throw new \InvalidArgumentException('Only save() changes the draft and its version.');
        }
        $columns = (new AiTask())->forceFill($attributes)->getAttributes();
        AiTask::whereKey($task->getKey())->update($columns);
        $task->forceFill($attributes)->syncOriginal();
    }

    /**
     * @param array{actor_user_id?: ?int, ip?: ?string, field?: ?string, value_before?: mixed, value_after?: mixed, payload?: ?array} $data
     */
    public function append(AiTask $task, string $kind, array $data = []): AiTaskEvent
    {
        if (!in_array($kind, AiTaskEvent::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown task event kind [{$kind}].");
        }

        $event = new AiTaskEvent([
            ...array_intersect_key($data, array_flip(['actor_user_id', 'ip', 'field', 'value_before', 'value_after', 'payload'])),
            'task_id' => $task->getKey(),
            'at' => now(),
            'kind' => $kind,
        ]);
        $event->prev_hash = AiTaskEvent::where('task_id', $task->getKey())->orderByDesc('id')->value('hash');
        $event->hash = $this->hashOf($event);
        $event->save();
        AiTask::whereKey($task->getKey())->update(['last_event_hash' => $event->hash]);
        $task->forceFill(['last_event_hash' => $event->hash])->syncOriginal();

        return $event;
    }

    /** False when any event of the task was edited or removed, the newest one included. */
    public function verifyChain(AiTask $task): bool
    {
        $previous = null;
        foreach (AiTaskEvent::where('task_id', $task->getKey())->orderBy('id')->cursor() as $event) {
            if ($event->prev_hash !== $previous || !hash_equals($this->hashOf($event), (string) $event->hash)) {
                return false;
            }
            $previous = $event->hash;
        }

        return $previous === AiTask::whereKey($task->getKey())->value('last_event_hash');
    }

    private function hashOf(AiTaskEvent $event): string
    {
        $fields = [];
        foreach (self::HASHED as $name) {
            $fields[$name] = $event->{$name};
        }
        $fields['at'] = $event->at?->format('Y-m-d H:i:s');

        return hash('sha256', ($event->prev_hash ?? '') . json_encode(
            self::canonical($fields),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    /** Sorts object keys recursively, since a JSON column may return them in another order. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
