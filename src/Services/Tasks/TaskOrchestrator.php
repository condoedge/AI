<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Tasks;

use Condoedge\Ai\Contracts\TaskDefinitionInterface;
use Condoedge\Ai\Contracts\TaskEditsRecordInterface;
use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\DTOs\Tasks\TaskField;
use Condoedge\Ai\DTOs\Tasks\TaskOutcome;
use Condoedge\Ai\DTOs\Tasks\TaskStep;
use Condoedge\Ai\DTOs\Tasks\TaskSummary;
use Condoedge\Ai\Exceptions\TaskVersionConflictException;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Models\AiTaskEvent;
use Condoedge\Ai\Services\Actions\PendingActionToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs the tasks the host registers (ai.tasks.definitions) as a state machine:
 *
 *   collecting → reviewing → (confirmed → executing → done | failed) | cancelled | expired
 *
 * Code decides what to ask (TaskPlanner), validates every value, builds the summary and
 * performs the single write (`execute`) only from `confirm`, under the task's row lock and
 * a transaction; the model only reads answers and phrases questions (TaskStepCaller).
 * Every transition and every asked, answered, extracted or refused value is an audit event.
 *
 * Every step reads the user's message for the fields asked now and for every other field in
 * play, so a value given ahead (a later group's, a required field not asked yet) is kept, checked
 * like any other; a value for an optional group's field accepts that group. When an answer brings
 * fields into play (a condition now holds, the definition declares new fields), the next question
 * comes from a step that re-reads the user's earlier messages of the task for those fields only.
 *
 * The optional groups are offered together once nothing is left to ask, as the synthetic list
 * field `_groups` (the groups the user names); never on an edit task whose draft already holds a
 * change (TaskPlanner::groupsToOffer). Any answer that names groups or gives a group a value
 * answers the offer: those groups are accepted, the others declined, and the task moves on
 * without offering them again. A declined group comes back only when the user asks for it, by
 * naming it (`_groups` is then read as an other field) or by giving one of its fields a value.
 *
 * Choices on an enum, bool or list question are code-built chips: the option's label, posting
 * its value to `choose`, which applies it without reading an answer.
 *
 * A message the step reads as about something else (`about_task` false, no value, no cancel)
 * leaves the task as it is: no draft change, no status change, no turn counted (its step call
 * is). The response carries `side_question` and the task's pending question as its answer, for
 * the chat to put under its own answer to the message. A value or a cancel always wins.
 *
 * A start may carry the user's message (a reply to the answer that offered the task, TaskOffer):
 * its first step reads that message as an answer, so a value given there is kept, and `started`
 * records the reason.
 *
 * Every entry point returns the chat's standard response array; `metadata.task` carries
 * the task's uuid, status, version, the question's choices and, when reviewing, the
 * summary rows and the confirm token. A limit adds `limit_hit` and the manual page's `link`
 * (alone when the start was refused and no task exists).
 */
class TaskOrchestrator
{
    public const ACTIVE = [AiTask::STATUS_COLLECTING, AiTask::STATUS_REVIEWING];
    public const GROUPS_FIELD = '_groups';
    public const NO_GROUP = 'none';
    private const CHOICE_TYPES = ['enum', 'bool', 'list'];
    /** What the audit stores in place of a personal-data value. */
    public const PROVIDED = 'provided';
    /** How long a start or a confirm waits for the one before it, and how long its lock may live. */
    private const LOCK_WAIT_SECONDS = 10;
    private const LOCK_TTL_SECONDS = 120;
    /** How many times one answer's new fields are looked for in the earlier messages, and how much of them is re-read. */
    private const MAX_REREADS = 3;
    private const MAX_REREAD_MESSAGES = 12;
    private const REREAD_CHARS = 1500;

    public function __construct(
        private readonly TaskStore $store,
        private readonly TaskPlanner $planner,
        private readonly TaskStepCaller $caller,
    ) {
    }

    /** On only with ai.tasks.enabled and at least one registered definition. */
    public static function configured(): bool
    {
        return (bool) config('ai.tasks.enabled', false) && array_filter((array) config('ai.tasks.definitions', [])) !== [];
    }

    public function definition(string $key): ?TaskDefinitionInterface
    {
        return self::registered()[$key] ?? null;
    }

    /** The conversation's active task, if its definition is still registered. */
    public function claims(AiConversation $conversation): ?AiTask
    {
        return AiTask::where('conversation_id', $conversation->getKey())
            ->whereIn('status', self::ACTIVE)
            ->whereIn('definition_key', array_keys(self::registered()))
            ->latest('id')
            ->first();
    }

    /** @return array<string, TaskDefinitionInterface> the registered definitions, by key */
    public static function registered(): array
    {
        $definitions = [];
        foreach ((array) config('ai.tasks.definitions', []) as $class) {
            $definition = is_string($class) && class_exists($class) ? app($class) : null;
            if ($definition instanceof TaskDefinitionInterface) {
                $definitions[$definition->key()] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @param array{observer?: mixed, message?: string, reason?: string} $context message: read by the first step as an answer
     * @throws AuthorizationException when the definition does not let this user start it
     */
    public function start(mixed $user, AiConversation $conversation, string $key, array $context = []): array
    {
        $definition = $this->definition($key) ?? throw new \InvalidArgumentException("No task definition [{$key}].");
        if (!$definition->startableBy($user)) {
            throw new AuthorizationException();
        }
        // Two quick starts on one conversation: the second waits, then resumes the first's task.
        $resumed = false;
        $started = ['definition' => $key] + (is_string($context['reason'] ?? null) ? ['reason' => $context['reason']] : []);
        $opened = Cache::lock('ai-task-start:' . $conversation->getKey(), self::LOCK_TTL_SECONDS)->block(self::LOCK_WAIT_SECONDS, function () use ($definition, $user, $conversation, $key, $started, &$resumed) {
            if ($active = $this->claims($conversation)) {
                $resumed = true;

                return $active;
            }
            $teamId = self::teamOf($user) ?? (is_numeric($conversation->team_id) ? (int) $conversation->team_id : null);
            if (($limit = $this->dailyLimitHit($definition, $user, $teamId)) !== null) {
                // No task row to audit: the refusal is logged and rides the stored answer's metadata.
                Log::info('AI task start refused: limit hit', ['definition' => $key, 'user_id' => self::userKey($user), 'team_id' => $teamId, 'limit' => $limit]);

                return $this->response('', __('ai.tasks.daily-limit-reached'), null, ['limit_hit' => $limit] + $this->manualLink($definition, $user));
            }
            $task = $this->store->create($key, (int) $conversation->getKey(), (int) self::userKey($user), $teamId, $definition->limits());
            [$initial, $prefilled] = $this->initialDraft($definition, $user);

            return $this->commit($task, $task->version, $task->status, $initial, [], [['started', ['payload' => $started]], ...$prefilled], $user);
        });
        if (is_array($opened)) {
            return $opened;
        }
        if ($resumed) {
            try {
                return $this->resume($opened, $user);
            } catch (TaskVersionConflictException) {
                return $this->current($opened, '');
            }
        }

        $task = $opened;
        $draft = $task->draft();
        if (is_string($message = $context['message'] ?? null) && trim($message) !== '') {
            try {
                return $this->turn($task, $definition, $user, $draft, $message, $context['observer'] ?? null, aside: false);
            } catch (TaskVersionConflictException) {
                return $this->current($task, $message);
            }
        }
        $fields = $this->fieldsToAsk($definition, $user, $draft);
        $step = $this->caller->step($definition, $user, $draft, $fields, null, '', $context['observer'] ?? null);
        try {
            $task = $this->commit($task, $draft->version, $task->status, null, ['llm_calls' => 1, 'tokens' => $step->tokens], [$this->askedEvent($fields, $step)], $user);
        } catch (TaskVersionConflictException) {
            return $this->current($task, '');
        }

        return $this->response('', $step->question, $task, ['choices' => $this->choices($fields, $step), 'asks_about' => $step->asksAbout]);
    }

    /**
     * One user message of an active task.
     *
     * @param array{user?: mixed, observer?: mixed} $context
     */
    public function handleTurn(AiTask $task, string $message, array $context = []): array
    {
        $user = $context['user'] ?? null;
        $observer = $context['observer'] ?? null;
        $task->refresh();
        $definition = $this->definition($task->definition_key);
        if ($definition === null || !in_array($task->status, self::ACTIVE, true)) {
            return $this->response($message, __('ai.tasks.not-waiting'), $task);
        }
        $draft = $task->draft();

        try {
            return $this->limitStop($task, $definition, $draft, $message, $user)
                ?? $this->turn($task, $definition, $user, $draft, $message, $observer);
        } catch (TaskVersionConflictException) {
            return $this->current($task, $message);
        }
    }

    /**
     * A chip of the current question: its value goes into the draft as the model's would, and its
     * label stands for the user's message. Refused unless the field is asked now and the task is
     * still at the version the chip was shown with.
     *
     * @param array{user?: mixed, version?: int, observer?: mixed} $context
     */
    public function choose(AiTask $task, string $field, mixed $value, array $context = []): array
    {
        $user = $context['user'] ?? null;
        $task->refresh();
        $definition = $this->definition($task->definition_key);
        if ($definition === null || $task->status !== AiTask::STATUS_COLLECTING) {
            return $this->response('', __('ai.tasks.not-waiting'), $task);
        }
        $draft = $task->draft();
        $asked = $this->fieldsToAsk($definition, $user, $draft);
        $target = array_values(array_filter($asked, fn (TaskField $asked) => $asked->key === $field))[0] ?? null;
        if ($target === null || !in_array($target->type, self::CHOICE_TYPES, true) || !$target->isValid($value)
            || (int) ($context['version'] ?? -1) !== $task->version) {
            return $this->response('', __('ai.tasks.stale'), $task);
        }
        $label = self::choiceLabel($target, $value);

        try {
            if ($stop = $this->limitStop($task, $definition, $draft, $label, $user)) {
                return $stop;
            }
            $step = new TaskStep(values: [$field => $target->type === 'bool' ? (int) $value : $value]);
            $counters = ['turns' => $task->turns + 1, 'llm_calls' => $task->llm_calls, 'tokens' => $task->tokens];
            [$next, $events] = $this->applied($definition, $user, $draft, $asked, $step, [['answered', ['payload' => ['fields' => self::keys($asked), 'chosen' => $field]]]]);

            return $this->advance($task, $definition, $user, $draft, $next, $counters, $events, $label, '', $asked, $step, $context['observer'] ?? null,
                $this->cameIntoPlay($definition, $user, $draft, $next));
        } catch (TaskVersionConflictException) {
            return $this->current($task, $label);
        }
    }

    /**
     * Executes the task once the user confirmed the summary its token was issued for and ticked
     * each of its money and legal rows. Idempotent by uuid: a done (or failed) task returns its outcome.
     *
     * @param array{user?: mixed, version?: int, acknowledged?: list<string>} $context
     */
    public function confirm(AiTask $task, string $token, array $context = []): array
    {
        $user = $context['user'] ?? null;
        $decoded = PendingActionToken::decode($token);
        $definition = $this->definition($task->definition_key);
        if ($decoded === null || $definition === null) {
            return $this->refuseConfirm($task, $token, 'invalid', $user);
        }
        if ($decoded->taskUuid !== $task->uuid || !$decoded->belongsTo(self::userKey($user)) || (string) $task->user_id !== (string) self::userKey($user)) {
            return $this->refuseConfirm($task, $token, 'foreign', $user);
        }

        try {
            return $this->serialised($definition, $user, $task->team_id, fn () => $this->confirmLocked($task, $token, $decoded, $definition, $user, $context));
        } catch (LockTimeoutException) {
            return $this->refuseConfirm($task, $token, 'busy', $user, __('ai.tasks.busy'));
        }
    }

    /** confirm() once no other confirm of the same user or team is counting the daily limits. */
    private function confirmLocked(AiTask $task, string $token, PendingActionToken $decoded, TaskDefinitionInterface $definition, mixed $user, array $context): array
    {
        return DB::transaction(function () use ($task, $token, $decoded, $definition, $user, $context) {
            $task = $this->store->loadForUpdate($task->uuid);
            if (in_array($task->status, [AiTask::STATUS_DONE, AiTask::STATUS_FAILED], true) && $task->outcome !== null) {
                return $this->outcomeResponse($task, TaskOutcome::fromArray($task->outcome));
            }
            if ($task->status !== AiTask::STATUS_REVIEWING) {
                $this->store->append($task, 'confirm_refused', $this->actor($user, ['payload' => [
                    'token' => self::fingerprint($token), 'reason' => 'not_waiting',
                ]]));

                return $this->response('', __('ai.tasks.not-waiting'), $task);
            }
            $team = self::teamOf($user);
            $posted = $context['version'] ?? null;
            if (!$decoded->bindsCurrent($task) || ($team !== null && (string) $team !== (string) $task->team_id)
                || ($posted !== null && (int) $posted !== $task->version)) {
                return $this->refuseConfirm($task, $token, 'stale', $user);
            }
            if ($task->expires_at !== null && $task->expires_at->isPast()) {
                return $this->stop($task, $task->draft(), '', 'ttl', __('ai.tasks.expired'), $user, $definition);
            }
            $draft = $task->draft();
            $required = array_column($this->summaryOf($definition, $user, $draft)->rowsToConfirm(), 'field');
            $acknowledged = array_values(array_intersect($required, (array) ($context['acknowledged'] ?? [])));
            if (($missing = array_values(array_diff($required, $acknowledged))) !== []) {
                $this->store->append($task, 'confirm_refused', $this->actor($user, ['payload' => [
                    'token' => self::fingerprint($token), 'reason' => 'unacknowledged', 'missing' => $missing,
                ]]));

                return $this->review($task, $definition, $user, $draft, $draft, [], [], '', __('ai.tasks.acknowledge-first') . "\n\n");
            }
            if (!$definition->startableBy($user)) {
                $this->store->record($task, ['status' => AiTask::STATUS_CANCELLED]);
                $this->store->append($task, 'cancelled', $this->actor($user, ['payload' => ['reason' => 'not_startable']]));

                return $this->response('', __('ai.tasks.not-allowed'), $task);
            }
            [, $stale] = $this->planner->prune($definition, $user, $draft);
            $errors = $definition->validate($user, $draft);
            if ($errors !== [] || $stale !== []) {
                return $this->reopenInvalid($task, $definition, $user, $errors, $stale);
            }
            if (($limit = $this->dailyLimitHit($definition, $user, $task->team_id)) !== null) {
                $this->store->append($task, 'limit_hit', $this->actor($user, ['payload' => ['limit' => $limit]]));

                return $this->response('', __('ai.tasks.daily-limit-reached'), $task, ['limit_hit' => $limit] + $this->manualLink($definition, $user));
            }

            $this->store->record($task, ['status' => AiTask::STATUS_CONFIRMED, 'confirmed_at' => now()]);
            $this->store->append($task, 'confirmed', $this->actor($user, ['payload' => [
                'token' => self::fingerprint($token), 'version' => $task->version, 'acknowledged' => $acknowledged,
            ]]));
            $this->store->record($task, ['status' => AiTask::STATUS_EXECUTING]);

            return $this->execute($task, $definition, $user);
        });
    }

    /** @param array{user?: mixed} $context */
    public function cancel(AiTask $task, array $context = []): array
    {
        $user = $context['user'] ?? null;

        return DB::transaction(function () use ($task, $user) {
            $task = $this->store->loadForUpdate($task->uuid);
            if (!in_array($task->status, self::ACTIVE, true)) {
                return $this->response('', __('ai.tasks.not-waiting'), $task);
            }
            $this->store->record($task, ['status' => AiTask::STATUS_CANCELLED]);
            $this->store->append($task, 'cancelled', $this->actor($user, ['payload' => ['reason' => 'user']]));

            return $this->response('', __('ai.tasks.cancelled'), $task);
        });
    }

    /**
     * "Modifier" on a summary row: the task leaves review and asks that field again. Removing
     * the value bumps the draft version, so the token shown with the summary no longer confirms.
     *
     * @param array{user?: mixed, observer?: mixed} $context
     */
    public function reopen(AiTask $task, string $field, array $context = []): array
    {
        $user = $context['user'] ?? null;
        $definition = $this->definition($task->definition_key);
        $task->refresh();
        $draft = $task->draft();
        $declared = $definition === null ? [] : $this->declared($definition, $user, $draft);
        if ($task->status !== AiTask::STATUS_REVIEWING || !isset($declared[$field]) || !$draft->has($field)) {
            return $this->response('', __('ai.tasks.not-waiting'), $task);
        }
        if ($task->llm_calls >= $definition->limits()->maxLlmCalls) {
            return $this->stop($task, $draft, '', 'llm_calls', __('ai.tasks.limit-reached'), $user, $definition);
        }

        try {
            $next = $draft->without([$field]);
            $fields = $this->fieldsToAsk($definition, $user, $next);
            $step = $this->caller->step($definition, $user, $next, $fields, null, '', $context['observer'] ?? null);
            $task = $this->commit($task, $draft->version, AiTask::STATUS_REVIEWING, $next, [
                'status' => AiTask::STATUS_COLLECTING,
                'llm_calls' => $task->llm_calls + 1,
                'tokens' => $task->tokens + $step->tokens,
            ], [
                ['reopened', ['field' => $field, 'value_before' => self::audited($declared[$field], $draft->get($field))]],
                $this->askedEvent($fields, $step),
            ], $user);
        } catch (TaskVersionConflictException) {
            return $this->current($task, '');
        }

        return $this->response('', $step->question, $task, ['choices' => $this->choices($fields, $step), 'asks_about' => $step->asksAbout]);
    }

    /**
     * Where a collecting task stands: the fields answered so far, plus one, out of those answered
     * plus those still planned (approximate: a group the user accepts later adds fields).
     *
     * @return array{step: int, of: int}
     */
    public function progress(AiTask $task, mixed $user): array
    {
        $definition = $this->definition($task->definition_key);
        if ($definition === null) {
            return ['step' => 1, 'of' => 1];
        }
        $draft = $task->draft();
        $answered = 0;
        foreach ($definition->fields($user, $draft) as $field) {
            $answered += $draft->has($field->key) && $field->applies($draft, $user) ? 1 : 0;
        }

        return ['step' => $answered + 1, 'of' => $answered + max(1, count($this->planner->plan($definition, $user, $draft)))];
    }

    /** `start` on a conversation that already runs a task: its last question, or its summary again. */
    private function resume(AiTask $task, mixed $user): array
    {
        if ($task->status === AiTask::STATUS_REVIEWING) {
            $draft = $task->draft();

            return $this->review($task, $this->definition($task->definition_key), $user, $draft, $draft, [], [], '', '');
        }

        return $this->response('', $this->lastQuestion($task), $task, ['choices' => []]);
    }

    /** The TTL, turn or call limit the task has hit, as the response that stops it; null while within them. */
    private function limitStop(AiTask $task, TaskDefinitionInterface $definition, TaskDraft $draft, string $message, mixed $user): ?array
    {
        $limits = $definition->limits();

        return match (true) {
            $task->expires_at !== null && $task->expires_at->isPast() => $this->stop($task, $draft, $message, 'ttl', __('ai.tasks.expired'), $user, $definition),
            $task->turns >= $limits->maxTurns => $this->stop($task, $draft, $message, 'turns', __('ai.tasks.limit-reached'), $user, $definition),
            $task->llm_calls >= $limits->maxLlmCalls => $this->stop($task, $draft, $message, 'llm_calls', __('ai.tasks.limit-reached'), $user, $definition),
            default => null,
        };
    }

    /** A confirm that cannot go on: audited with the token's fingerprint; nothing else changes. */
    private function refuseConfirm(AiTask $task, string $token, string $reason, mixed $user, ?string $answer = null): array
    {
        DB::transaction(fn () => $this->store->append(
            $this->store->loadForUpdate($task->uuid),
            'confirm_refused',
            $this->actor($user, ['payload' => ['token' => self::fingerprint($token), 'reason' => $reason]]),
        ));

        return $this->response('', $answer ?? __('ai.tasks.stale'), $task);
    }

    /** A request that lost the task to another one answers with where the task now stands. */
    private function current(AiTask $task, string $message): array
    {
        $task = $task->fresh() ?? $task;
        if (in_array($task->status, [AiTask::STATUS_DONE, AiTask::STATUS_FAILED], true) && $task->outcome !== null) {
            return $this->outcomeResponse($task, TaskOutcome::fromArray($task->outcome), $message);
        }

        return $this->response($message, __(in_array($task->status, self::ACTIVE, true) ? 'ai.tasks.stale' : 'ai.tasks.not-waiting'), $task);
    }

    /**
     * Runs a confirm while no other confirm of this definition by the same user or team runs, so the
     * daily limits count the one before it. Taken outside the transaction: a waiter must read its commit.
     *
     * @throws LockTimeoutException
     */
    private function serialised(TaskDefinitionInterface $definition, mixed $user, int|string|null $teamId, \Closure $run): mixed
    {
        $locks = [];
        try {
            foreach (array_filter(['user:' . self::userKey($user), $teamId === null ? null : 'team:' . $teamId]) as $scope) {
                $lock = Cache::lock('ai-task-confirm:' . $definition->key() . ':' . $scope, self::LOCK_TTL_SECONDS);
                $lock->block(self::LOCK_WAIT_SECONDS);
                $locks[] = $lock;
            }

            return $run();
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /** @param bool $aside whether a message about something else leaves the task waiting (not on a start) */
    private function turn(AiTask $task, TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft, string $message, mixed $observer,
        bool $aside = true): array
    {
        $asked = $this->fieldsToAsk($definition, $user, $draft);
        $step = $this->caller->step($definition, $user, $draft, $asked, $message, $this->lastQuestion($task), $observer,
            others: $this->otherFields($definition, $user, $draft, $asked));
        $counters = ['turns' => $task->turns + 1, 'llm_calls' => $task->llm_calls + 1, 'tokens' => $task->tokens + $step->tokens];
        $events = [['answered', ['payload' => ['fields' => self::keys($asked)]]]];

        if ($step->wantsToCancel) {
            $events[] = ['cancelled', ['payload' => ['reason' => 'user']]];
            $task = $this->commit($task, $draft->version, $task->status, null, [...$counters, 'status' => AiTask::STATUS_CANCELLED], $events, $user);

            return $this->response($message, __('ai.tasks.cancelled'), $task);
        }
        if ($aside && !$step->aboutTask && $step->values === [] && $step->rejected === [] && !$step->failed) {
            return $this->aside($task, $draft, $asked, $step, $message, $user);
        }

        [$next, $events] = $this->applied($definition, $user, $draft, $asked, $step, $events);
        $notice = $step->explanation !== null ? $step->explanation . "\n\n" : '';

        return $this->advance($task, $definition, $user, $draft, $next, $counters, $events, $message, $notice, $asked, $step, $observer,
            $this->cameIntoPlay($definition, $user, $draft, $next));
    }

    /**
     * A message about something else: only the step call is counted and audited; the answer is the question
     * the task still waits on (the summary, when reviewing), for the chat to put under its own answer.
     *
     * @param list<TaskField> $asked
     */
    private function aside(AiTask $task, TaskDraft $draft, array $asked, TaskStep $step, string $message, mixed $user): array
    {
        $pending = $task->status === AiTask::STATUS_REVIEWING ? __('ai.tasks.still-reviewing') : $this->lastQuestion($task);
        if ($pending === '') {
            $pending = __('ai.tasks.ask-fields', ['fields' => implode(', ', array_map(fn (TaskField $field) => __($field->labelKey), $asked))]);
        }
        $task = $this->commit($task, $draft->version, $task->status, null, ['llm_calls' => $task->llm_calls + 1, 'tokens' => $task->tokens + $step->tokens],
            [['answered', ['payload' => ['fields' => self::keys($asked), 'side_question' => 1]]]], $user);

        return $this->response($message, $pending, $task, ['side_question' => true, 'choices' => []]);
    }

    /**
     * The draft with the step's values, the groups they answer and the stale values pruned, plus their events.
     *
     * @param list<TaskField> $asked
     * @param ?list<string> $readFor null when the step read the user's message; else the step re-read the earlier
     *                               messages and only values for these keys are kept
     * @return array{0: TaskDraft, 1: list<array{0: string, 1: array}>}
     */
    private function applied(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft, array $asked, TaskStep $step, array $events,
        ?array $readFor = null): array
    {
        $fields = $this->declared($definition, $user, $draft);
        $next = $draft;
        $offer = array_values(array_filter($asked, fn (TaskField $field) => $field->key === self::GROUPS_FIELD))[0] ?? null;
        $values = $step->values;
        $refused = $step->rejected;
        foreach ($readFor === null ? [] : array_diff_key($values, array_flip($readFor)) as $key => $value) {
            unset($values[$key]);
            $refused[] = ['field' => $key, 'value' => $value, 'reason' => 'already_read'];
        }
        $named = $values[self::GROUPS_FIELD] ?? null;
        unset($values[self::GROUPS_FIELD]);
        $given = [];
        foreach ($values as $key => $value) {
            $events[] = ['extracted', [
                'field' => $key,
                'value_before' => self::audited($fields[$key] ?? null, $draft->get($key)),
                'value_after' => self::audited($fields[$key] ?? null, $value),
            ] + ($readFor === null ? [] : ['payload' => ['earlier' => 1]])];
            $next = $next->with($key, $value);
            if (($fields[$key]->group ?? null) !== null) {
                $given[] = $fields[$key]->group;
            }
        }
        $accepted = array_values(array_unique(array_diff([...($named ?? []), ...$given], [self::NO_GROUP])));
        if ($offer !== null && $readFor === null && ($named !== null || $given !== [])) {
            // Any answer to the offer answers it whole: the groups it leaves out are declined.
            foreach (array_diff(array_map('strval', array_keys($offer->options)), [self::NO_GROUP]) as $group) {
                $next = in_array($group, $accepted, true) ? $next->withGroup($group) : $next->withDeclinedGroup($group);
                $events[] = ['extracted', ['field' => '_group:' . $group, 'value_after' => (int) in_array($group, $accepted, true)]];
            }
        }
        foreach ($accepted as $group) {
            if (!in_array($group, $next->groups, true)) {
                $next = $next->withGroup($group);
                $events[] = ['extracted', ['field' => '_group:' . $group, 'value_after' => 1]];
            }
        }
        if ($definition instanceof TaskEditsRecordInterface) {
            [$next, $events] = $this->keptFromRecord($definition, $user, $next, $fields, array_keys($values), $events);
        }
        foreach ($refused as $refusal) {
            $field = is_string($refusal['field']) ? ($fields[$refusal['field']] ?? null) : null;
            $events[] = ['rejected_value', [
                'field' => is_scalar($refusal['field']) ? mb_substr((string) $refusal['field'], 0, 190) : null,
                'value_after' => self::audited($field, $refusal['value']),
                'payload' => ['reason' => $refusal['reason']],
            ]];
        }

        [$next, $removed] = $this->planner->prune($definition, $user, $next);
        foreach ($removed as $key) {
            $events[] = ['extracted', ['field' => $key, 'value_before' => self::audited($fields[$key] ?? null, $draft->get($key)), 'payload' => ['pruned' => 1]]];
        }

        return [$next, $events];
    }

    /**
     * The fields the step's values brought into play and left unanswered: they apply to the new
     * draft and did not to the old one (or were not declared then).
     *
     * @return list<string>
     */
    private function cameIntoPlay(TaskDefinitionInterface $definition, mixed $user, TaskDraft $before, TaskDraft $after): array
    {
        return array_values(array_diff($this->inPlay($definition, $user, $after), $this->inPlay($definition, $user, $before), array_keys($after->values)));
    }

    /** @return list<string> the keys of the declared fields that apply to the draft */
    private function inPlay(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft): array
    {
        return array_values(array_map(fn (TaskField $field) => $field->key,
            array_filter($definition->fields($user, $draft), fn (TaskField $field) => $field->applies($draft, $user))));
    }

    /**
     * The fields a step reads besides the asked ones: every field in play without a value, and, unless the
     * offer is asked, `_groups` for the optional groups not accepted, so the user can ask for one by name.
     *
     * @param list<TaskField> $asked
     * @return list<TaskField>
     */
    private function otherFields(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft, array $asked): array
    {
        $askedKeys = self::keys($asked);
        $others = array_values(array_filter($definition->fields($user, $draft), fn (TaskField $field) => !in_array($field->key, $askedKeys, true)
            && !$draft->has($field->key) && $field->applies($draft, $user)));
        if (in_array(self::GROUPS_FIELD, $askedKeys, true)) {
            return $others;
        }
        $open = [];
        foreach ($others as $field) {
            if ($field->group !== null && !in_array($field->group, $draft->groups, true)) {
                $open[$field->group] = $definition->groupLabel($field->group);
            }
        }

        return $open === [] ? $others : [...$others, new TaskField(self::GROUPS_FIELD, 'ai.tasks.groups', 'list', options: $open,
            askHint: 'only when the user asks to set one of these optional settings too without giving its value: the ones they name, as option values')];
    }

    /**
     * The user's messages of this task, oldest first, ending with the one being answered (stored or not yet).
     *
     * @return list<string>
     */
    private function userMessages(AiTask $task, string $current): array
    {
        // Not bounded by the task's creation: the reply that started it from an offer came just before.
        $stored = AiMessage::where('conversation_id', $task->conversation_id)->where('role', 'user')
            ->orderByDesc('id')->limit(self::MAX_REREAD_MESSAGES)->get()
            ->filter(fn (AiMessage $message) => ($message->metadata['task']['uuid'] ?? null) === $task->uuid)
            ->reverse()->pluck('content')->map(fn ($text) => mb_substr((string) $text, 0, self::REREAD_CHARS))->values()->all();
        if ($stored !== [] && end($stored) === $current) {
            array_pop($stored);
        }

        return trim($current) === '' ? $stored : [...$stored, mb_substr($current, 0, self::REREAD_CHARS)];
    }

    /**
     * After the draft changed: asks the next fields (reusing the model's question when it is still about
     * them), offers the next group, or validates and moves to review. Fields the answer brought into play
     * are first looked for in the user's messages of the task: the asking step re-reads them, and what it
     * finds is applied before anything is asked (again, while that brings more fields into play).
     *
     * @param list<TaskField> $asked
     * @param list<string> $fresh the keys the answer brought into play
     */
    private function advance(AiTask $task, TaskDefinitionInterface $definition, mixed $user, TaskDraft $base, TaskDraft $draft, array $counters,
        array $events, string $message, string $notice, array $asked, TaskStep $step, mixed $observer, array $fresh = [], int $rereads = 0): array
    {
        $fresh = $rereads < self::MAX_REREADS ? $fresh : [];
        $fields = $this->fieldsToAsk($definition, $user, $draft);
        if ($fields === []) {
            $errors = $definition->validate($user, $draft);
            $events[] = ['validated', ['payload' => ['errors' => $errors]]];
            $invalid = array_values(array_filter(array_keys($errors), fn ($key) => is_string($key) && $draft->has($key)));
            if ($errors === [] && $definition instanceof TaskEditsRecordInterface && !$this->planner->changes($definition, $user, $draft)) {
                // Nothing to confirm: the group offer comes again.
                $draft = new TaskDraft($draft->values, [], $draft->version);
                $notice .= __('ai.tasks.nothing-to-change') . "\n\n";
                $fields = $this->fieldsToAsk($definition, $user, $draft);
                if ($fields === []) {
                    return $this->respondAfter($task, $base, $draft, $counters, $events, $user, $message, $notice, new TaskStep(), $fields);
                }
            } elseif ($errors === [] || $invalid === []) {
                return $errors === []
                    ? $this->review($task, $definition, $user, $base, $draft, $counters, $events, $message, $notice)
                    : $this->respondAfter($task, $base, $draft, $counters, $events, $user, $message,
                        $notice . __('ai.tasks.check-again') . "\n" . implode("\n", $errors), new TaskStep(), $fields);
            } else {
                $draft = $draft->without($invalid);
                $notice .= __('ai.tasks.check-again') . "\n" . implode("\n", $errors) . "\n\n";
                $fields = $this->fieldsToAsk($definition, $user, $draft);
            }
        }

        // The model's question still fits while it is about a field still to ask and nothing new is to look for.
        $reuse = $fresh === [] && $step->question !== '' && array_intersect(self::signatures($fields), self::signatures($asked)) !== []
            && ($step->asksAbout === null || in_array($step->asksAbout, self::keys($fields), true));
        if (!$reuse) {
            if ($counters['llm_calls'] >= $definition->limits()->maxLlmCalls) {
                $task = $this->commit($task, $base->version, $task->status, self::changed($base, $draft), $counters, $events, $user);

                return $this->stop($task, $task->draft(), $message, 'llm_calls', __('ai.tasks.limit-reached'), $user, $definition);
            }
            $step = $fresh === []
                ? $this->caller->step($definition, $user, $draft, $fields, null, '', $observer)
                : $this->caller->step($definition, $user, $draft, $fields, null, '', $observer, others: $this->otherFields($definition, $user, $draft, $fields),
                    earlier: $this->userMessages($task, $message), readFor: $fresh);
            $counters['llm_calls']++;
            $counters['tokens'] += $step->tokens;
            if ($fresh !== [] && array_intersect_key($step->values, array_flip($fresh)) !== []) {
                [$next, $events] = $this->applied($definition, $user, $draft, $fields, $step, $events, $fresh);

                return $this->advance($task, $definition, $user, $base, $next, $counters, $events, $message, $notice, $fields, $step, $observer,
                    $this->cameIntoPlay($definition, $user, $draft, $next), $rereads + 1);
            }
        }
        $events[] = $this->askedEvent($fields, $step);

        return $this->respondAfter($task, $base, $draft, $counters, $events, $user, $message, $notice . $step->question, $step, $fields);
    }

    /** @param list<TaskField> $fields the fields the answer asks */
    private function respondAfter(AiTask $task, TaskDraft $base, TaskDraft $draft, array $counters, array $events, mixed $user,
        string $message, string $answer, TaskStep $step, array $fields): array
    {
        $task = $this->commit($task, $base->version, $task->status, self::changed($base, $draft), [...$counters, 'status' => AiTask::STATUS_COLLECTING], $events, $user);

        return $this->response($message, $answer, $task, ['choices' => $this->choices($fields, $step), 'asks_about' => $step->asksAbout]);
    }

    /**
     * The answer chips: on the group offer, or on an enum, bool or list field the question is about,
     * one per option by label (`{label, field, value}`); else the model's suggestions, as text.
     *
     * @param list<TaskField> $fields
     * @return list<string|array{label: string, field: string, value: mixed}>
     */
    private function choices(array $fields, TaskStep $step): array
    {
        $byKey = array_column(array_map(fn (TaskField $field) => [$field->key, $field], $fields), 1, 0);
        $about = $byKey[self::GROUPS_FIELD] ?? $byKey[(string) $step->asksAbout] ?? (count($fields) === 1 ? $fields[0] : null);
        if ($about === null || !in_array($about->type, self::CHOICE_TYPES, true)) {
            return $step->choices;
        }
        $values = match ($about->type) {
            'bool' => [true, false],
            'list' => array_map(fn ($key) => [$key], array_keys($about->options)),
            default => array_keys($about->options),
        };

        return array_map(fn ($value) => ['label' => self::choiceLabel($about, $value), 'field' => $about->key, 'value' => $value], $values);
    }

    private static function choiceLabel(TaskField $field, mixed $value): string
    {
        return match ($field->type) {
            'bool' => __($value ? 'ai.tasks.yes' : 'ai.tasks.no'),
            'list' => implode(', ', array_map(fn ($key) => __((string) $field->options[$key]), $value)),
            default => __((string) $field->options[$value]),
        };
    }

    private function review(AiTask $task, TaskDefinitionInterface $definition, mixed $user, TaskDraft $base, TaskDraft $draft, array $counters,
        array $events, string $message, string $notice): array
    {
        $summary = $this->summaryOf($definition, $user, $draft);
        $token = null;
        $task = DB::transaction(function () use ($task, $base, $draft, $counters, $events, $user, $summary, &$token) {
            $task = $this->commit($task, $base->version, $task->status, self::changed($base, $draft), [...$counters, 'status' => AiTask::STATUS_REVIEWING], $events, $user);
            $token = PendingActionToken::issueForTask('task:' . $task->definition_key, self::userKey($user), $task)->encode();
            $this->store->append($task, 'summary_shown', $this->actor($user, ['payload' => ['fields' => array_column($summary->rows, 'field')]]));
            $this->store->append($task, 'token_issued', $this->actor($user, ['payload' => ['token' => self::fingerprint($token), 'version' => $task->version]]));

            return $task;
        });

        return $this->response($message, $notice . __('ai.tasks.review'), $task, ['summary' => $summary->rows, 'token' => $token]);
    }

    /** The definition's summary; for an edit task, without the rows whose value is the record's, so never a required tick. */
    private function summaryOf(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft): TaskSummary
    {
        $summary = $definition->summary($user, $draft);
        if (!$definition instanceof TaskEditsRecordInterface) {
            return $summary;
        }
        $current = $definition->currentValues($user, $draft);

        return new TaskSummary(array_values(array_filter($summary->rows, fn (array $row) => !(is_string($row['field'] ?? null)
            && array_key_exists($row['field'], $current) && $draft->has($row['field']) && TaskPlanner::same($current[$row['field']], $draft->get($row['field']))))));
    }

    /**
     * An edit task's draft starts with the initial values its fields accept, each audited as prefilled.
     *
     * @return array{0: ?TaskDraft, 1: list<array{0: string, 1: array}>}
     */
    private function initialDraft(TaskDefinitionInterface $definition, mixed $user): array
    {
        if (!$definition instanceof TaskEditsRecordInterface) {
            return [null, []];
        }
        $draft = new TaskDraft();
        $fields = $this->declared($definition, $user, $draft);
        $events = [];
        foreach ($definition->initialValues($user) as $key => $value) {
            if (isset($fields[$key]) && $fields[$key]->isValid($value)) {
                $draft = $draft->with($key, $fields[$key]->type === 'bool' ? (int) $value : $value);
                $events[] = ['extracted', ['field' => $key, 'value_after' => self::audited($fields[$key], $value), 'payload' => ['prefilled' => 1]]];
            }
        }

        return [$events === [] ? null : $draft, $events];
    }

    /**
     * The other fields of an accepted group one of whose fields was just answered keep the record's values, audited
     * as prefilled: "push the end back a week" keeps the start.
     *
     * @param array<string, TaskField> $fields
     * @param list<string|int> $answered
     * @return array{0: TaskDraft, 1: list<array{0: string, 1: array}>}
     */
    private function keptFromRecord(TaskDefinitionInterface&TaskEditsRecordInterface $definition, mixed $user, TaskDraft $draft, array $fields,
        array $answered, array $events): array
    {
        $groups = array_intersect(array_filter(array_map(fn ($key) => $fields[$key]->group ?? null, $answered)), $draft->groups);
        if ($groups === []) {
            return [$draft, $events];
        }
        $current = $definition->currentValues($user, $draft);
        foreach ($this->declared($definition, $user, $draft) as $key => $field) {
            if (in_array($field->group, $groups, true) && !$draft->has($key) && $field->applies($draft, $user)
                && array_key_exists($key, $current) && $field->isValid($current[$key])) {
                $draft = $draft->with($key, $field->type === 'bool' ? (int) $current[$key] : $current[$key]);
                $events[] = ['extracted', ['field' => $key, 'value_after' => self::audited($field, $current[$key]), 'payload' => ['prefilled' => 1]]];
            }
        }

        return [$draft, $events];
    }

    /**
     * Validation failed at confirm, or values no longer apply or fit their field: they are removed and
     * the task collects again.
     *
     * @param list<string> $stale the keys TaskPlanner::prune removes
     */
    private function reopenInvalid(AiTask $task, TaskDefinitionInterface $definition, mixed $user, array $errors, array $stale): array
    {
        $draft = $task->draft();
        $fields = $this->declared($definition, $user, $draft);
        $this->store->save($task, $draft->without([...array_filter(array_keys($errors), 'is_string'), ...$stale]), ['status' => AiTask::STATUS_COLLECTING]);
        foreach ($stale as $key) {
            $this->store->append($task, 'extracted', $this->actor($user, ['field' => $key, 'value_before' => self::audited($fields[$key] ?? null, $draft->get($key)), 'payload' => ['pruned' => 1]]));
        }
        $this->store->append($task, 'validated', $this->actor($user, ['payload' => ['errors' => $errors]]));
        $labels = array_map(fn (string $key) => isset($fields[$key]) ? __($fields[$key]->labelKey) : $key, $stale);

        return $this->response('', __('ai.tasks.check-again') . "\n" . implode("\n", [...array_values($errors), ...$labels]), $task);
    }

    private function execute(AiTask $task, TaskDefinitionInterface $definition, mixed $user): array
    {
        try {
            $outcome = DB::transaction(fn () => $definition->execute($user, $task->draft(), $task));
        } catch (\Throwable $e) {
            // The message may hold the written values.
            Log::error('AI task execute failed', ['task' => $task->uuid, 'exception' => get_class($e), 'code' => $e->getCode()]);
            $outcome = TaskOutcome::failure(__('ai.tasks.failed'));
            $this->store->record($task, ['status' => AiTask::STATUS_FAILED, 'outcome' => $outcome->toArray()]);
            $this->store->append($task, 'failed', $this->actor($user, ['payload' => ['error' => get_class($e)]]));

            return $this->outcomeResponse($task, $outcome);
        }

        $done = $outcome->succeeded;
        $this->store->record($task, [
            'status' => $done ? AiTask::STATUS_DONE : AiTask::STATUS_FAILED,
            'outcome' => $outcome->toArray(),
        ] + ($done ? ['executed_at' => now()] : []));
        $this->store->append($task, $done ? 'executed' : 'failed', $this->actor($user, ['payload' => $outcome->toArray()]));

        return $this->outcomeResponse($task, $outcome);
    }

    /** A limit ends the task: `limit_hit`, then `expired`. */
    private function stop(AiTask $task, TaskDraft $draft, string $message, string $limit, string $answer, mixed $user,
        TaskDefinitionInterface $definition): array
    {
        $task = $this->commit($task, $draft->version, $task->status, null, ['status' => AiTask::STATUS_EXPIRED], [
            ['limit_hit', ['payload' => ['limit' => $limit]]],
            ['expired', []],
        ], $user);

        return $this->response($message, $answer, $task, ['limit_hit' => $limit] + $this->manualLink($definition, $user));
    }

    /** The link a limit message offers to the definition's manual page, as the outcome link: `{url, label}`. */
    private function manualLink(TaskDefinitionInterface $definition, mixed $user): array
    {
        $url = $definition->manualUrl($user);

        return $url === null ? [] : ['link' => ['url' => $url, 'label' => 'ai.tasks.manual-form']];
    }

    /**
     * Writes a turn under the row lock: the draft (bumping its version) only when it changed,
     * the counters and status, then the events in order.
     *
     * @param string $status the status the caller read and acted on
     * @param list<array{0: string, 1: array}> $events
     * @throws TaskVersionConflictException when another request changed the draft or the status meanwhile
     */
    private function commit(AiTask $task, int $version, string $status, ?TaskDraft $draft, array $attributes, array $events, mixed $user): AiTask
    {
        return DB::transaction(function () use ($task, $version, $status, $draft, $attributes, $events, $user) {
            $locked = $this->store->loadForUpdate($task->uuid);
            // A confirm or cancel changes the status, not the version.
            if ($locked === null || $locked->version !== $version || $locked->status !== $status) {
                throw new TaskVersionConflictException("Task [{$task->uuid}] moved on from {$status} at version {$version}.");
            }
            if ($draft !== null) {
                $this->store->save($locked, $draft->atVersion($version), $attributes);
            } elseif ($attributes !== []) {
                $this->store->record($locked, $attributes);
            }
            foreach ($events as [$kind, $data]) {
                $this->store->append($locked, $kind, $this->actor($user, $data));
            }

            return $locked;
        });
    }

    /**
     * The fields asked now: the planner's next fields, else the offer of the open groups (their fields
     * are read as other fields, so a value given with the answer is kept).
     *
     * @return list<TaskField>
     */
    private function fieldsToAsk(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft): array
    {
        $next = $this->planner->next($definition, $user, $draft);
        $groups = $next === [] ? $this->planner->groupsToOffer($definition, $user, $draft) : [];
        if ($groups === []) {
            return $next;
        }
        $options = array_combine($groups, array_map(fn (string $group) => $definition->groupLabel($group), $groups)) + [self::NO_GROUP => 'ai.tasks.groups-none'];

        return [new TaskField(self::GROUPS_FIELD, 'ai.tasks.groups', 'list', options: $options,
            askHint: 'the optional settings the user wants to set now, as option values; ' . self::NO_GROUP . ' when they want none of them (no, later, nothing). '
                . 'Your question asks about this field only, offering its options')];
    }

    /** @return array<string, TaskField> */
    private function declared(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft): array
    {
        $fields = [];
        foreach ($definition->fields($user, $draft) as $field) {
            $fields[$field->key] = $field;
        }

        return $fields;
    }

    private function dailyLimitHit(TaskDefinitionInterface $definition, mixed $user, int|string|null $teamId): ?string
    {
        $limits = $definition->limits();
        $done = fn () => AiTask::where('definition_key', $definition->key())
            ->where('status', AiTask::STATUS_DONE)
            ->where('executed_at', '>=', now()->subDay());

        if ($done()->where('user_id', self::userKey($user))->count() >= $limits->maxExecutedPerUserPerDay) {
            return 'per_user_per_day';
        }
        if ($teamId !== null && $done()->where('team_id', $teamId)->count() >= $limits->maxExecutedPerTeamPerDay) {
            return 'per_team_per_day';
        }

        return null;
    }

    private function lastQuestion(AiTask $task): string
    {
        $asked = AiTaskEvent::where('task_id', $task->getKey())->where('kind', 'asked')->orderByDesc('id')->limit(10)->get();
        foreach ($asked as $event) {
            if (is_string($event->payload['question'] ?? null) && $event->payload['question'] !== '') {
                return $event->payload['question'];
            }
        }

        return '';
    }

    /** A failed step repeats the last question: it is not recorded as a new one. */
    private function askedEvent(array $fields, TaskStep $step): array
    {
        return ['asked', ['payload' => ['fields' => self::keys($fields), 'question' => $step->failed ? null : $step->question]]];
    }

    private function actor(mixed $user, array $data): array
    {
        $id = self::userKey($user);

        return ['actor_user_id' => is_numeric($id) ? (int) $id : null] + $data;
    }

    private function outcomeResponse(AiTask $task, TaskOutcome $outcome, string $message = ''): array
    {
        $answer = $outcome->message ?? __($outcome->succeeded ? 'ai.tasks.done' : 'ai.tasks.failed');

        return $this->response($message, $answer, $task, ['outcome' => $outcome->toArray()]);
    }

    /** The chat's standard response array, with the task's state under metadata.task. */
    private function response(string $question, string $answer, ?AiTask $task, array $extra = []): array
    {
        return [
            'question' => $question,
            'answer' => trim($answer),
            'cypher' => null,
            'data' => [],
            'stats' => [],
            'referenced_files' => [],
            'metadata' => [
                'query' => ['source' => 'task'],
                'execution' => [],
                'response' => [],
                'scope_clarified' => false,
                'security_filter_applied' => false,
                'subject' => null,
                'task' => $task === null ? ($extra === [] ? null : $extra) : [
                    'uuid' => $task->uuid,
                    'definition' => $task->definition_key,
                    'status' => $task->status,
                    'version' => $task->version,
                ] + $extra,
            ],
        ];
    }

    private static function changed(TaskDraft $base, TaskDraft $draft): ?TaskDraft
    {
        return $draft->toArray() === $base->toArray() ? null : $draft;
    }

    /** A personal-data field's value, or one of a field the definition does not declare, is audited as provided, never stored. */
    private static function audited(?TaskField $field, mixed $value): mixed
    {
        return ($field === null || $field->pii) && $value !== null ? self::PROVIDED : $value;
    }

    /** @param list<TaskField> $fields */
    private static function keys(array $fields): array
    {
        return array_map(static fn (TaskField $field) => $field->key, $fields);
    }

    /** A group question is told apart by its group, carried as the synthetic field's label. */
    private static function signatures(array $fields): array
    {
        return array_map(static fn (TaskField $field) => $field->key . '|' . $field->labelKey, $fields);
    }

    private static function fingerprint(string $token): string
    {
        return substr(hash('sha256', $token), 0, 16);
    }

    private static function userKey(mixed $user): int|string|null
    {
        if (!is_object($user)) {
            return null;
        }
        $id = method_exists($user, 'getKey') ? $user->getKey() : ($user->id ?? null);

        return is_int($id) || is_string($id) ? $id : null;
    }

    private static function teamOf(mixed $user): ?int
    {
        $team = is_object($user) ? ($user->current_team_id ?? null) : null;

        return is_numeric($team) ? (int) $team : null;
    }
}
