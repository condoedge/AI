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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
 * One lifecycle: a task is live while its status is collecting or reviewing, its definition is
 * registered and its lifetime (expires_at) is ahead. Every entry point passes the same gate first
 * (not live: "not waiting"; past its lifetime: expired now); claims() takes only a live task, and
 * expireStale() ends the conversation's tasks past their lifetime, so the message after one goes to
 * the pipeline. A start of another task while one runs asks whether to continue it or stop it
 * (replaced silently while it holds no answer); a start of the same task resumes it, and cancels
 * the same task left active in another conversation of the user (superseded). A step the model
 * could not answer changes nothing and counts no turn (its call only when billed).
 *
 * Every step reads the user's message for the fields asked now and for every other field in
 * play, so a value given ahead (a later group's, a required field not asked yet) is kept, checked
 * like any other, and a typed change to an answer applies like one (audited with the value it
 * replaces; in review, a new summary and token follow); a value for an optional group's field accepts that group. When an answer brings
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

    /** The conversation's live task: active, its definition still registered, within its lifetime. Reads only. */
    public function claims(AiConversation $conversation): ?AiTask
    {
        return $this->active($conversation)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();
    }

    /**
     * Ends the conversation's active tasks past their lifetime (`limit_hit` ttl, then `expired`), so a message after
     * one goes to the pipeline; a task that moved on meanwhile is left alone.
     *
     * @return list<AiTask> the tasks it expired, oldest first
     */
    public function expireStale(AiConversation $conversation, mixed $user = null): array
    {
        $expired = [];
        foreach ($this->active($conversation)->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('id')->get() as $task) {
            try {
                $expired[] = $this->commit($task, $task->version, $task->status, null, ['status' => AiTask::STATUS_EXPIRED], self::expiry('ttl'), $user);
            } catch (TaskVersionConflictException) {
            }
        }

        return $expired;
    }

    /** The conversation's tasks in an active status whose definition is registered. */
    private function active(AiConversation $conversation): \Illuminate\Database\Eloquent\Builder
    {
        return AiTask::where('conversation_id', $conversation->getKey())
            ->whereIn('status', self::ACTIVE)
            ->whereIn('definition_key', array_keys(self::registered()));
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
     * Opens the task, or answers for the conversation's live one: the same task resumes; another one is replaced
     * when the start names it (`replace`) or it holds no answer yet, else the answer asks whether to continue it
     * or stop it and start this one (metadata.task.switch). A task past its lifetime is expired first, and the
     * same task left active in another conversation of the user is cancelled (`superseded`).
     *
     * @param array{observer?: mixed, message?: string, reason?: string, groups?: list<string>, replace?: string} $context
     *        message: read by the first step as an answer; groups: optional groups accepted from the start (one no
     *        declared field has is ignored); replace: the uuid of the conversation's live task to stop for this one
     * @throws AuthorizationException when the definition does not let this user start it
     */
    public function start(mixed $user, AiConversation $conversation, string $key, array $context = []): array
    {
        $definition = $this->definition($key) ?? throw new \InvalidArgumentException("No task definition [{$key}].");
        if (!$definition->startableBy($user)) {
            throw new AuthorizationException();
        }
        $groups = $this->startGroups($definition, $user, $context['groups'] ?? []);
        $replace = is_string($context['replace'] ?? null) ? $context['replace'] : null;
        // Two quick starts on one conversation: the second waits, then resumes the first's task.
        $resumed = false;
        $started = ['definition' => $key] + (is_string($context['reason'] ?? null) ? ['reason' => $context['reason']] : [])
            + ($groups !== [] ? ['groups' => $groups] : []);
        $opened = Cache::lock('ai-task-start:' . $conversation->getKey(), self::LOCK_TTL_SECONDS)->block(self::LOCK_WAIT_SECONDS, function () use ($definition, $user, $conversation, $key, $started, $groups, $replace, &$resumed) {
            $this->expireStale($conversation, $user);
            $active = $this->claims($conversation);
            if ($active?->definition_key === $key) {
                $resumed = true;

                return $active;
            }
            if ($active !== null && !$this->replaceable($active, $user, $replace)) {
                return $this->switchOffer($active, $definition, $groups);
            }
            $teamId = self::teamOf($user) ?? (is_numeric($conversation->team_id) ? (int) $conversation->team_id : null);
            if (($limit = $this->dailyLimitHit($definition, $user, $teamId)) !== null) {
                // No task row to audit: the refusal is logged and rides the stored answer's metadata.
                Log::info('AI task start refused: limit hit', ['definition' => $key, 'user_id' => self::userKey($user), 'team_id' => $teamId, 'limit' => $limit]);

                return $this->response('', __('ai.tasks.daily-limit-reached'), null, ['limit_hit' => $limit] + $this->manualLink($definition, $user));
            }
            if ($active !== null && !$this->end($active, 'replaced', $user) && ($still = $this->claims($conversation)) !== null) {
                // The task moved on meanwhile and still runs: the choice is asked again on where it now stands.
                return $this->switchOffer($still, $definition, $groups);
            }
            $this->supersede($user, $conversation, $key);
            $task = $this->store->create($key, (int) $conversation->getKey(), (int) self::userKey($user), $teamId, $definition->limits());
            [$initial, $prefilled] = $this->initialDraft($definition, $user, $groups);

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
            $task = $this->commit($task, $draft->version, $task->status, null, ['llm_calls' => self::billed($step), 'tokens' => $step->tokens], [$this->askedEvent($fields, $step)], $user);
        } catch (TaskVersionConflictException) {
            return $this->current($task, '');
        }

        return $this->response('', $step->question, $task, ['choices' => $this->choices($fields, $step), 'asks_about' => $step->asksAbout]);
    }

    /**
     * The groups a start names that some declared field has, in the order named, once each.
     *
     * @return list<string>
     */
    private function startGroups(TaskDefinitionInterface $definition, mixed $user, mixed $groups): array
    {
        if (!is_array($groups) || $groups === []) {
            return [];
        }
        $declared = array_map(fn (TaskField $field) => $field->group, $definition->fields($user, new TaskDraft()));

        return array_values(array_unique(array_filter($groups, fn (mixed $group) => is_string($group) && in_array($group, $declared, true))));
    }

    /** Whether a start of another task may stop this one: the user's, and named by the start or holding no answer yet. */
    private function replaceable(AiTask $active, mixed $user, ?string $replace): bool
    {
        return (string) $active->user_id === (string) self::userKey($user) && ($replace === $active->uuid || $active->turns === 0);
    }

    /**
     * The answer to another task's start while this one runs: it is in progress; the chips (metadata.task.switch)
     * continue it, or stop it and start the other one with the start's groups.
     *
     * @param list<string> $groups
     */
    private function switchOffer(AiTask $active, TaskDefinitionInterface $other, array $groups): array
    {
        $label = $this->definition($active->definition_key)?->label() ?? $active->definition_key;

        return $this->response('', __('ai.tasks.other-in-progress', ['task' => __($label)]), $active,
            ['switch' => ['task' => $other->key(), 'groups' => $groups, 'replace' => $active->uuid]]);
    }

    /** A new start of a task cancels the same task the user left active in another conversation. */
    private function supersede(mixed $user, AiConversation $conversation, string $key): void
    {
        $userId = self::userKey($user);
        if ($userId === null) {
            return;
        }
        $left = AiTask::where('user_id', $userId)->where('definition_key', $key)->whereIn('status', self::ACTIVE)
            ->where('conversation_id', '!=', $conversation->getKey())->orderBy('id')->get();
        foreach ($left as $task) {
            $this->end($task, 'superseded', $user);
        }
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
        $draft = $task->draft();

        try {
            return $this->gate($task, $definition, self::ACTIVE, $user, $message)
                ?? $this->limitStop($task, $definition, $draft, $message, $user)
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
        try {
            if ($stop = $this->gate($task, $definition, [AiTask::STATUS_COLLECTING], $user)) {
                return $stop;
            }
        } catch (TaskVersionConflictException) {
            return $this->current($task, '');
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
     * In order: the token and the task (status, version, team, lifetime), who may start it, the draft
     * (pruned and validated, before any summary is built), the card (unchanged since it was shown, else
     * it is shown again), the ticks, the daily limits, then the write.
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
            if ($stop = $this->gate($task, $definition, [AiTask::STATUS_REVIEWING], $user)) {
                return $stop;
            }
            if (!$definition->startableBy($user)) {
                $this->store->record($task, ['status' => AiTask::STATUS_CANCELLED]);
                $this->store->append($task, 'cancelled', $this->actor($user, ['payload' => ['reason' => 'not_startable']]));

                return $this->response('', __('ai.tasks.not-allowed'), $task);
            }
            // The draft is checked before any summary is built: summary() may read records validate() refuses.
            $draft = $task->draft();
            [, $stale] = $this->planner->prune($definition, $user, $draft);
            $errors = $definition->validate($user, $draft);
            if ($errors !== [] || $stale !== []) {
                return $this->reopenInvalid($task, $definition, $user, $errors, $stale);
            }
            $summary = $this->summaryOf($definition, $user, $draft);
            if ($this->cardChanged($task, $summary)) {
                // A value on the card changed meanwhile (the record, a colleague's edit): the new card replaces the old one.
                $this->store->append($task, 'confirm_refused', $this->actor($user, ['payload' => [
                    'token' => self::fingerprint($token), 'reason' => 'changed',
                ]]));

                return $this->review($task, $definition, $user, $draft, $draft, [], [], '', __('ai.tasks.changed-since') . "\n\n", $summary, replace: true);
            }
            $required = array_column($summary->rowsToConfirm(), 'field');
            $acknowledged = array_values(array_intersect($required, (array) ($context['acknowledged'] ?? [])));
            if (($missing = array_values(array_diff($required, $acknowledged))) !== []) {
                $this->store->append($task, 'confirm_refused', $this->actor($user, ['payload' => [
                    'token' => self::fingerprint($token), 'reason' => 'unacknowledged', 'missing' => $missing,
                ]]));

                return $this->review($task, $definition, $user, $draft, $draft, [], [], '', __('ai.tasks.acknowledge-first') . "\n\n", $summary);
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

            return $this->execute($task, $definition, $user, $summary);
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
     * Only a changeable row (as the card shown marked it) reopens; any other is refused as such.
     *
     * @param array{user?: mixed, observer?: mixed} $context
     */
    public function reopen(AiTask $task, string $field, array $context = []): array
    {
        $user = $context['user'] ?? null;
        $definition = $this->definition($task->definition_key);
        $task->refresh();
        try {
            if ($stop = $this->gate($task, $definition, [AiTask::STATUS_REVIEWING], $user)) {
                return $stop;
            }
        } catch (TaskVersionConflictException) {
            return $this->current($task, '');
        }
        $draft = $task->draft();
        $declared = $this->declared($definition, $user, $draft);
        if (self::offCard($this->lastShown($task), $field) || !self::changeable($declared, $draft, ['field' => $field])) {
            return $this->response('', __('ai.tasks.not-changeable'), $task);
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
                'llm_calls' => $task->llm_calls + self::billed($step),
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

    /**
     * `start` of the task the conversation already runs: its last question with its chips, or, once its answers
     * are checked again (pruned and validated, before any summary is built), its summary; answers that no longer
     * pass are asked again.
     *
     * @throws TaskVersionConflictException when the task moved on meanwhile
     */
    private function resume(AiTask $task, mixed $user): array
    {
        $definition = $this->definition($task->definition_key);
        $draft = $task->draft();
        if ($task->status !== AiTask::STATUS_REVIEWING) {
            [$question, $choices] = $this->pending($task, $this->fieldsToAsk($definition, $user, $draft));

            return $this->response('', $question, $task, ['choices' => $choices]);
        }
        [, $stale] = $this->planner->prune($definition, $user, $draft);
        $errors = $definition->validate($user, $draft);
        if ($errors === [] && $stale === []) {
            return $this->review($task, $definition, $user, $draft, $draft, [], [], '', '');
        }

        return DB::transaction(function () use ($task, $definition, $user, $errors, $stale) {
            $locked = $this->store->loadForUpdate($task->uuid);
            if ($locked === null || $locked->status !== AiTask::STATUS_REVIEWING || $locked->version !== $task->version) {
                throw new TaskVersionConflictException("Task [{$task->uuid}] moved on while it resumed.");
            }

            return $this->reopenInvalid($locked, $definition, $user, $errors, $stale);
        });
    }

    /** The turn or call limit the task has hit, as the response that stops it; null while within them (its lifetime is the gate's). */
    private function limitStop(AiTask $task, TaskDefinitionInterface $definition, TaskDraft $draft, string $message, mixed $user): ?array
    {
        $limits = $definition->limits();

        return match (true) {
            $task->turns >= $limits->maxTurns =>$this->stop($task, $draft, $message, 'turns', __('ai.tasks.limit-reached'), $user, $definition),
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
            others: $this->otherFields($definition, $user, $draft, $asked, $this->lastShown($task)));
        if ($step->failed) {
            return $this->unavailable($task, $draft, $asked, $step, $message, $user);
        }
        $counters = ['turns' => $task->turns + 1, 'llm_calls' => $task->llm_calls + 1, 'tokens' => $task->tokens + $step->tokens];
        $events = [['answered', ['payload' => ['fields' => self::keys($asked)]]]];

        if ($step->wantsToCancel) {
            $events[] = ['cancelled', ['payload' => ['reason' => 'user']]];
            $task = $this->commit($task, $draft->version, $task->status, null, [...$counters, 'status' => AiTask::STATUS_CANCELLED], $events, $user);

            return $this->response($message, __('ai.tasks.cancelled'), $task);
        }
        if ($aside && !$step->aboutTask && $step->values === [] && $step->rejected === []) {
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
        [$pending] = $this->pending($task, $asked);
        $task = $this->commit($task, $draft->version, $task->status, null, ['llm_calls' => $task->llm_calls + 1, 'tokens' => $task->tokens + $step->tokens],
            [['answered', ['payload' => ['fields' => self::keys($asked), 'side_question' => 1]]]], $user);

        return $this->response($message, $pending, $task, ['side_question' => true, 'choices' => []]);
    }

    /**
     * A step the model could not answer (an outage): the task stays as it is, no turn is counted, and the call
     * only when it was billed. The answer says so, then repeats what the task waits on, with its choice chips.
     *
     * @param list<TaskField> $asked
     */
    private function unavailable(AiTask $task, TaskDraft $draft, array $asked, TaskStep $step, string $message, mixed $user): array
    {
        [$pending, $choices] = $this->pending($task, $asked);
        $task = $this->commit($task, $draft->version, $task->status, null, ['llm_calls' => $task->llm_calls + self::billed($step), 'tokens' => $task->tokens + $step->tokens],
            [['answered', ['payload' => ['fields' => self::keys($asked), 'unavailable' => 1]]]], $user);

        return $this->response($message, __('ai.tasks.unavailable') . "\n\n" . $pending, $task, ['choices' => $choices]);
    }

    /**
     * What the task waits on: the summary to confirm, else its last question (the asked fields named, when none was
     * phrased) with that question's code-built chips.
     *
     * @param list<TaskField> $asked the fields asked now
     * @return array{0: string, 1: list<string|array{label: string, field: string, value: mixed}>}
     */
    private function pending(AiTask $task, array $asked): array
    {
        if ($task->status === AiTask::STATUS_REVIEWING) {
            return [__('ai.tasks.still-reviewing'), []];
        }
        [$question, $about] = $this->lastAsked($task);
        if ($question === '') {
            $question = __('ai.tasks.ask-fields', ['fields' => implode(', ', array_map(fn (TaskField $field) => __($field->labelKey), $asked))]);
        }

        return [$question, $this->choices($asked, new TaskStep(asksAbout: $about))];
    }

    /** A step call counts as a model call unless it failed unbilled (an outage costs no tokens). */
    private static function billed(TaskStep $step): int
    {
        return $step->failed && $step->tokens <= 0 ? 0 : 1;
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
        // An answer given again is no correction: nothing to apply or audit.
        $values = array_filter($values, fn (mixed $value, string|int $key) => !(is_string($key) && $draft->has($key) && TaskPlanner::same($draft->get($key), $value)),
            ARRAY_FILTER_USE_BOTH);
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
     * The fields a step reads besides the asked ones: every field in play without a value; every answer the
     * user could change on the card (a correction typed in the message: `changeable`, and not a row the last
     * card shown kept from changing); and, unless the offer is asked, `_groups` for the optional groups not
     * accepted, so the user can ask for one by name.
     *
     * @param list<TaskField> $asked
     * @param ?array $shown the payload of the last card shown (lastShown), [] when none; null when the step reads no
     *                      message (a re-read of the earlier ones), so no answer is listed for a change
     * @return list<TaskField>
     */
    private function otherFields(TaskDefinitionInterface $definition, mixed $user, TaskDraft $draft, array $asked, ?array $shown = null): array
    {
        $askedKeys = self::keys($asked);
        $declared = $this->declared($definition, $user, $draft);
        $others = array_values(array_filter($declared, fn (TaskField $field) => !in_array($field->key, $askedKeys, true) && $field->applies($draft, $user)
            && (!$draft->has($field->key) || ($shown !== null && self::changeable($declared, $draft, ['field' => $field->key]) && !self::offCard($shown, $field->key)))));
        if (in_array(self::GROUPS_FIELD, $askedKeys, true)) {
            return $others;
        }
        $open = [];
        foreach ($others as $field) {
            if ($field->group !== null && !$draft->has($field->key) && !in_array($field->group, $draft->groups, true)) {
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
                // Errors on no answer of the draft leave nothing to ask again: the manual page is the way on.
                return $errors === []
                    ? $this->review($task, $definition, $user, $base, $draft, $counters, $events, $message, $notice)
                    : $this->respondAfter($task, $base, $draft, $counters, $events, $user, $message,
                        $notice . __('ai.tasks.check-again') . "\n" . implode("\n", $errors), new TaskStep(), $fields, $this->manualLink($definition, $user));
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
            $counters['llm_calls'] += self::billed($step);
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

    /**
     * @param list<TaskField> $fields the fields the answer asks
     * @param array $extra more of metadata.task (a manual page's `link`)
     */
    private function respondAfter(AiTask $task, TaskDraft $base, TaskDraft $draft, array $counters, array $events, mixed $user,
        string $message, string $answer, TaskStep $step, array $fields, array $extra = []): array
    {
        $task = $this->commit($task, $base->version, $task->status, self::changed($base, $draft), [...$counters, 'status' => AiTask::STATUS_COLLECTING], $events, $user);

        return $this->response($message, $answer, $task, ['choices' => $this->choices($fields, $step), 'asks_about' => $step->asksAbout] + $extra);
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

    /**
     * Shows the summary with a new confirm token. Each row says whether "Modifier" may reopen it
     * (`changeable`), and the audit keeps a keyed hash of the rows (no value), which the confirm
     * compares with the card it would confirm.
     *
     * @param ?TaskSummary $summary the summary already built for this draft, if any
     * @param bool $replace whether the card replaces one still shown: the version moves on, so the old card and its token no longer confirm
     * @param array $extra more of metadata.task (a manual page's `link`)
     */
    private function review(AiTask $task, TaskDefinitionInterface $definition, mixed $user, TaskDraft $base, TaskDraft $draft, array $counters,
        array $events, string $message, string $notice, ?TaskSummary $summary = null, bool $replace = false, array $extra = []): array
    {
        $summary ??= $this->summaryOf($definition, $user, $draft);
        $declared = $this->declared($definition, $user, $draft);
        $rows = array_map(fn (mixed $row) => is_array($row) ? [...$row, 'changeable' => self::changeable($declared, $draft, $row)] : $row, $summary->rows);
        $token = null;
        $task = DB::transaction(function () use ($task, $base, $draft, $counters, $events, $user, $summary, $rows, $replace, &$token) {
            $task = $this->commit($task, $base->version, $task->status, $replace ? $draft : self::changed($base, $draft), [...$counters, 'status' => AiTask::STATUS_REVIEWING], $events, $user);
            $token = PendingActionToken::issueForTask('task:' . $task->definition_key, self::userKey($user), $task)->encode();
            $this->store->append($task, 'summary_shown', $this->actor($user, ['payload' => [
                'fields' => array_column($rows, 'field'),
                'changeable' => array_column(array_filter($rows, fn (mixed $row) => $row['changeable'] ?? false), 'field'),
                'rows_hash' => self::rowsHash($summary->rows),
            ]]));
            $this->store->append($task, 'token_issued', $this->actor($user, ['payload' => ['token' => self::fingerprint($token), 'version' => $task->version]]));

            return $task;
        });

        return $this->response($message, $notice . __('ai.tasks.review'), $task, ['summary' => $rows, 'token' => $token] + $extra);
    }

    /**
     * Whether "Modifier" on a summary row can ask its field again: the host's `changeable` (true when it gives
     * none), a declared field the draft holds, and, on a choice, another option to pick.
     *
     * @param array<string, TaskField> $declared
     */
    private static function changeable(array $declared, TaskDraft $draft, array $row): bool
    {
        $key = $row['field'] ?? null;
        $field = is_string($key) ? ($declared[$key] ?? null) : null;

        return (bool) ($row['changeable'] ?? true) && $field !== null && $draft->has($key)
            && !($field->type === 'enum' && count($field->options) <= 1);
    }

    /**
     * Whether the card shown has a row for the field that it kept from changing: a row keeps what the host said of
     * it; a field without a row (or a card stored before rows said so) is judged alone.
     */
    private static function offCard(array $shown, string $field): bool
    {
        return is_array($shown['changeable'] ?? null) && in_array($field, (array) ($shown['fields'] ?? []), true) && !in_array($field, $shown['changeable'], true);
    }

    /** Whether the card the confirm would act on differs from the one last shown (a card shown before the hash was kept counts as the same). */
    private function cardChanged(AiTask $task, TaskSummary $summary): bool
    {
        $shown = $this->lastShown($task)['rows_hash'] ?? null;

        return is_string($shown) && !hash_equals($shown, self::rowsHash($summary->rows));
    }

    /** The payload of the task's last summary_shown event: the card in review. */
    private function lastShown(AiTask $task): array
    {
        $payload = AiTaskEvent::where('task_id', $task->getKey())->where('kind', 'summary_shown')->orderByDesc('id')->value('payload');

        return is_array($payload) ? $payload : [];
    }

    /** A summary's rows keyed with the app key, truncated like a token's fingerprint: the audit never holds a value of the card. */
    private static function rowsHash(array $rows): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };
        $json = json_encode($canonical($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);

        return substr(hash_hmac('sha256', (string) $json, (string) config('app.key')), 0, 16);
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
     * The draft a task starts with: the groups the start accepted (recorded by `started`) and, on an edit task,
     * the initial values its fields accept, each audited as prefilled. Null when it starts empty.
     *
     * @param list<string> $groups
     * @return array{0: ?TaskDraft, 1: list<array{0: string, 1: array}>}
     */
    private function initialDraft(TaskDefinitionInterface $definition, mixed $user, array $groups = []): array
    {
        $draft = new TaskDraft();
        $fields = $definition instanceof TaskEditsRecordInterface ? $this->declared($definition, $user, $draft) : [];
        foreach ($groups as $group) {
            $draft = $draft->withGroup($group);
        }
        if (!$definition instanceof TaskEditsRecordInterface) {
            return [$groups === [] ? null : $draft, []];
        }
        $events = [];
        foreach ($definition->initialValues($user) as $key => $value) {
            if (isset($fields[$key]) && $fields[$key]->isValid($value)) {
                $draft = $draft->with($key, $fields[$key]->type === 'bool' ? (int) $value : $value);
                $events[] = ['extracted', ['field' => $key, 'value_after' => self::audited($fields[$key], $value), 'payload' => ['prefilled' => 1]]];
            }
        }

        return [$events === [] && $groups === [] ? null : $draft, $events];
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
     * Validation failed at confirm or the write refused answers, or values no longer apply or fit their
     * field: they are removed and the task collects again. When none of the errors names an answer the
     * draft holds, nothing can be asked again: the answer offers the manual page.
     *
     * @param list<string> $stale the keys TaskPlanner::prune removes
     * @param array $audit more of the `validated` event's payload
     */
    private function reopenInvalid(AiTask $task, TaskDefinitionInterface $definition, mixed $user, array $errors, array $stale, array $audit = []): array
    {
        $draft = $task->draft();
        $fields = $this->declared($definition, $user, $draft);
        $invalid = array_values(array_filter(array_keys($errors), fn ($key) => is_string($key) && $draft->has($key)));
        $this->store->save($task, $draft->without([...array_filter(array_keys($errors), 'is_string'), ...$stale]), ['status' => AiTask::STATUS_COLLECTING]);
        foreach ($stale as $key) {
            $this->store->append($task, 'extracted', $this->actor($user, ['field' => $key, 'value_before' => self::audited($fields[$key] ?? null, $draft->get($key)), 'payload' => ['pruned' => 1]]));
        }
        $this->store->append($task, 'validated', $this->actor($user, ['payload' => ['errors' => $errors] + $audit]));
        $labels = array_map(fn (string $key) => isset($fields[$key]) ? __($fields[$key]->labelKey) : $key, $stale);

        return $this->response('', __('ai.tasks.check-again') . "\n" . implode("\n", [...array_values($errors), ...$labels]), $task,
            $invalid === [] && $stale === [] ? $this->manualLink($definition, $user) : []);
    }

    /**
     * The single write. A refusal (ValidationException, or a 4xx HttpException with a message) is not a
     * crash: the answers it names are asked again with its reasons, else the summary comes again with
     * them and the manual page's link. Any other throwable fails the task for good, with that link.
     */
    private function execute(AiTask $task, TaskDefinitionInterface $definition, mixed $user, TaskSummary $summary): array
    {
        try {
            $outcome = DB::transaction(fn () => $definition->execute($user, $task->draft(), $task));
        } catch (\Throwable $e) {
            if (($reasons = self::refusal($e)) !== null) {
                return $this->refused($task, $definition, $user, $reasons, $summary);
            }
            // The message may hold the written values.
            Log::error('AI task execute failed', ['task' => $task->uuid, 'exception' => get_class($e), 'code' => $e->getCode()]);
            $outcome = TaskOutcome::failure(__('ai.tasks.failed'));
            $link = $this->manualLink($definition, $user)['link'] ?? null;
            $outcome = $link === null ? $outcome : $outcome->withLink($link['url'], $link['label']);
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

    /**
     * The reasons a write refused with, as plain text: a ValidationException's first message per key, or a
     * 4xx HttpException's message. Null for anything else (a crash), or a refusal that gives no reason.
     *
     * @return ?array<string|int, string>
     */
    private static function refusal(\Throwable $e): ?array
    {
        $text = fn (mixed $message) => is_string($message) ? trim(strip_tags($message)) : '';
        if ($e instanceof ValidationException) {
            $reasons = array_filter(array_map(fn ($messages) => $text(is_array($messages) ? (reset($messages) ?: '') : $messages), $e->errors()), fn ($reason) => $reason !== '');

            return $reasons === [] ? null : $reasons;
        }
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 && $text($e->getMessage()) !== '') {
            return [$text($e->getMessage())];
        }

        return null;
    }

    /**
     * A refused write (its transaction rolled back): reasons on answers the draft holds (by the key before
     * its first `.`) send those answers back to be asked again; else the summary comes again with the reasons
     * and the manual page's link. Audited as `validated` at execute.
     *
     * @param array<string|int, string> $reasons
     * @param TaskSummary $summary the card the confirm checked, unchanged by the rolled-back write
     */
    private function refused(AiTask $task, TaskDefinitionInterface $definition, mixed $user, array $reasons, TaskSummary $summary): array
    {
        $draft = $task->draft();
        $onAnswers = [];
        $others = [];
        foreach ($reasons as $key => $reason) {
            $field = is_string($key) ? explode('.', $key, 2)[0] : null;
            if ($field !== null && $draft->has($field)) {
                $onAnswers[$field] ??= $reason;
            } else {
                $others[$key] = $reason;
            }
        }
        if ($onAnswers !== []) {
            return $this->reopenInvalid($task, $definition, $user, $onAnswers + $others, [], ['at' => 'execute']);
        }

        return $this->review($task, $definition, $user, $draft, $draft, [], [['validated', ['payload' => ['errors' => $reasons, 'at' => 'execute']]]], '',
            implode("\n", $reasons) . "\n\n", $summary, extra: $this->manualLink($definition, $user));
    }

    /** A limit ends the task: `limit_hit`, then `expired`. */
    private function stop(AiTask $task, TaskDraft $draft, string $message, string $limit, string $answer, mixed $user,
        TaskDefinitionInterface $definition): array
    {
        $task = $this->commit($task, $draft->version, $task->status, null, ['status' => AiTask::STATUS_EXPIRED], self::expiry($limit), $user);

        return $this->response($message, $answer, $task, ['limit_hit' => $limit] + $this->manualLink($definition, $user));
    }

    /** @return list<array{0: string, 1: array}> the events of a task a limit ends */
    private static function expiry(string $limit): array
    {
        return [['limit_hit', ['payload' => ['limit' => $limit]]], ['expired', []]];
    }

    /**
     * The lifecycle's one gate, run by every entry point before it acts: the answer that stops it when the task is not
     * alive for that action (its definition gone, or not in a status the action acts on: not waiting; past its
     * lifetime: it expires now, with the manual page's link), else null.
     *
     * @param list<string> $statuses
     * @throws TaskVersionConflictException when the task moved on while it expires
     */
    private function gate(AiTask $task, ?TaskDefinitionInterface $definition, array $statuses, mixed $user, string $message = ''): ?array
    {
        if ($definition === null || !in_array($task->status, $statuses, true)) {
            return $this->response($message, __('ai.tasks.not-waiting'), $task);
        }
        if ($task->expires_at !== null && $task->expires_at->isPast()) {
            return $this->stop($task, $task->draft(), $message, 'ttl', __('ai.tasks.expired'), $user, $definition);
        }

        return null;
    }

    /**
     * Cancels an active task the user leaves for another start (`replaced`, `superseded`); false when it moved on meanwhile.
     */
    private function end(AiTask $task, string $reason, mixed $user): bool
    {
        try {
            $this->commit($task, $task->version, $task->status, null, ['status' => AiTask::STATUS_CANCELLED], [['cancelled', ['payload' => ['reason' => $reason]]]], $user);

            return true;
        } catch (TaskVersionConflictException) {
            return false;
        }
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
        return $this->lastAsked($task)[0];
    }

    /** @return array{0: string, 1: ?string} the last question phrased and the field it was about ('' and null when none) */
    private function lastAsked(AiTask $task): array
    {
        $asked = AiTaskEvent::where('task_id', $task->getKey())->where('kind', 'asked')->orderByDesc('id')->limit(10)->get();
        foreach ($asked as $event) {
            if (is_string($event->payload['question'] ?? null) && $event->payload['question'] !== '') {
                return [$event->payload['question'], is_string($event->payload['asks_about'] ?? null) ? $event->payload['asks_about'] : null];
            }
        }

        return ['', null];
    }

    /** A failed step repeats the last question: it is not recorded as a new one. The field a question is about rides along, for its chips. */
    private function askedEvent(array $fields, TaskStep $step): array
    {
        return ['asked', ['payload' => ['fields' => self::keys($fields), 'question' => $step->failed ? null : $step->question]
            + (!$step->failed && $step->asksAbout !== null ? ['asks_about' => $step->asksAbout] : [])]];
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
