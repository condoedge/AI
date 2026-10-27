<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Admin;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Models\AiTaskEvent;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;
use Condoedge\Ai\Services\Tasks\TaskStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only analytics over the assistant's conversations for the admin
 * "AI conversations & feedback" page.
 *
 * VISIBLE CORPUS (every method): conversations that are not soft-deleted (the owner
 * deleted them) and not produced by the eval harness (metadata.eval = true). Dates are
 * MESSAGE dates: a conversation is "in the range" when one of its messages is, and
 * feedback (stored on the answer as metadata.feedback = positive | negative, with
 * metadata.feedback_at and, after a 👎, an optional metadata.feedback_comment) is dated by its answer.
 *
 * Users / teams come from the host tables (config ai.admin.users_table — default the
 * auth user model's table —, ai.admin.user_team_column = current_team_id,
 * ai.admin.teams_table = teams, ai.admin.team_name_column = name); the team of a
 * conversation is its team_id, else its user's current team. A host without those
 * tables / columns still works: the names are just empty.
 *
 * Access control is NOT here — callers are gated by ABILITY (see the admin Kompo
 * components and the route).
 */
class ConversationInsights
{
    /** The Gate ability that guards the admin page; denied unless the host grants it. */
    public const ABILITY = 'viewAiConversations';

    public const FEEDBACK_FILTERS = ['all', 'positive', 'negative'];
    public const CONVERSATION_FEEDBACK_FILTERS = ['', 'any', 'positive', 'negative', 'none'];
    public const ANSWER_FILTERS = ['', 'regenerated', 'no_sources', 'slow'];

    private ConnectionInterface $db;
    private InsightsSql $sql;
    private string $messages;
    private string $conversations;

    /** @var array<string, bool> schema probes, per instance */
    private array $schema = [];

    public function __construct(?ConnectionInterface $connection = null)
    {
        $this->db = $connection ?? DB::connection((new AiMessage())->getConnectionName());
        $this->sql = new InsightsSql($this->db->getDriverName());
        $this->messages = (new AiMessage())->getTable();
        $this->conversations = (new AiConversation())->getTable();
    }

    // =========================================================================
    // KPIs — one aggregate query + one percentile query
    // =========================================================================

    /**
     * @return array{
     *   conversations:int, questions:int, active_users:int, answers:int,
     *   positive:int, negative:int, rated:int, satisfaction_pct:?float, rated_pct:?float,
     *   errors:int, error_pct:?float, tokens:int, llm_calls:int, traced_answers:int,
     *   latency_samples:int, latency_median_ms:?float, latency_p90_ms:?float
     * }
     */
    public function kpis(InsightsRange $range): array
    {
        $fb = $this->sql->feedback('m');
        $tokens = $this->sql->num('m.metadata', '$.trace.usage.total.total');
        $calls = $this->sql->num('m.metadata', '$.trace.usage.total.calls');
        $isError = $this->sql->isError('m');

        $row = $this->messagesInRange($range)
            ->selectRaw(implode(', ', [
                'COUNT(DISTINCT m.conversation_id) AS conversations',
                "SUM(CASE WHEN m.role = 'user' THEN 1 ELSE 0 END) AS questions",
                "COUNT(DISTINCT CASE WHEN m.role = 'user' THEN c.user_id END) AS active_users",
                "SUM(CASE WHEN m.role = 'assistant' THEN 1 ELSE 0 END) AS answers",
                "SUM(CASE WHEN m.role = 'assistant' AND {$fb} = 'positive' THEN 1 ELSE 0 END) AS positive",
                "SUM(CASE WHEN m.role = 'assistant' AND {$fb} = 'negative' THEN 1 ELSE 0 END) AS negative",
                "SUM(CASE WHEN m.role = 'assistant' AND {$isError} THEN 1 ELSE 0 END) AS errors",
                "SUM(CASE WHEN m.role = 'assistant' THEN COALESCE({$tokens}, 0) ELSE 0 END) AS tokens",
                "SUM(CASE WHEN m.role = 'assistant' THEN COALESCE({$calls}, 0) ELSE 0 END) AS llm_calls",
                "SUM(CASE WHEN m.role = 'assistant' AND {$tokens} IS NOT NULL THEN 1 ELSE 0 END) AS traced_answers",
            ]))
            ->first();

        $int = static fn ($v): int => (int) round((float) ($v ?? 0));
        $answers = $int($row->answers ?? 0);
        $positive = $int($row->positive ?? 0);
        $negative = $int($row->negative ?? 0);
        $rated = $positive + $negative;
        $errors = $int($row->errors ?? 0);

        $latency = $this->latencyPercentiles($range);

        return [
            'conversations' => $int($row->conversations ?? 0),
            'questions' => $int($row->questions ?? 0),
            'active_users' => $int($row->active_users ?? 0),
            'answers' => $answers,
            'positive' => $positive,
            'negative' => $negative,
            'rated' => $rated,
            'satisfaction_pct' => $rated > 0 ? round(100 * $positive / $rated, 1) : null,
            'rated_pct' => $answers > 0 ? round(100 * $rated / $answers, 1) : null,
            'errors' => $errors,
            'error_pct' => $answers > 0 ? round(100 * $errors / $answers, 1) : null,
            'tokens' => $int($row->tokens ?? 0),
            'llm_calls' => $int($row->llm_calls ?? 0),
            'traced_answers' => $int($row->traced_answers ?? 0),
        ] + $latency;
    }

    /**
     * The answers of the range per asker audience (metadata.audience.key; null = unresolved or
     * unstamped, listed last), in one grouped query.
     *
     * @return list<array{audience:?string, conversations:int, answers:int, positive:int, negative:int,
     *   no_sources:int, no_source_pct:?float, latency_avg_ms:?float}>
     */
    public function audienceKpis(InsightsRange $range): array
    {
        $key = $this->sql->audienceKey('m');
        $fb = $this->sql->feedback('m');
        $noSources = $this->sql->noSources('m');
        $latency = $this->sql->latency('m');

        $rows = $this->messagesInRange($range)
            ->where('m.role', 'assistant')
            ->selectRaw(implode("\n    , ", [
                "{$key} AS audience",
                'COUNT(DISTINCT m.conversation_id) AS conversations',
                'COUNT(*) AS answers',
                "SUM(CASE WHEN {$fb} = 'positive' THEN 1 ELSE 0 END) AS positive",
                "SUM(CASE WHEN {$fb} = 'negative' THEN 1 ELSE 0 END) AS negative",
                "SUM(CASE WHEN {$noSources} THEN 1 ELSE 0 END) AS no_sources",
                "AVG({$latency}) AS latency_avg_ms",
            ]))
            ->groupByRaw($key)
            ->get();

        $int = static fn ($v): int => (int) round((float) ($v ?? 0));

        return $rows
            ->map(fn ($row) => [
                'audience' => is_string($row->audience) && $row->audience !== '' ? $row->audience : null,
                'conversations' => $int($row->conversations),
                'answers' => $int($row->answers),
                'positive' => $int($row->positive),
                'negative' => $int($row->negative),
                'no_sources' => $int($row->no_sources),
                'no_source_pct' => $int($row->answers) > 0 ? round(100 * $int($row->no_sources) / $int($row->answers), 1) : null,
                'latency_avg_ms' => $row->latency_avg_ms !== null ? round((float) $row->latency_avg_ms, 1) : null,
            ])
            ->sortBy(fn (array $row) => [$row['audience'] === null ? 1 : 0, (string) $row['audience']])
            ->values()
            ->all();
    }

    /** An answer above this latency (ms) is slow: config ai.admin.slow_answer_ms. */
    public static function slowAnswerMs(): int
    {
        return max(1, (int) config('ai.admin.slow_answer_ms', 20000));
    }

    /**
     * Median and p90 of the answer latency (nearest-rank: the smallest value whose rank
     * reaches p·n), in one window-function query.
     *
     * @return array{latency_samples:int, latency_median_ms:?float, latency_p90_ms:?float}
     */
    private function latencyPercentiles(InsightsRange $range): array
    {
        $latency = $this->sql->latency('m');

        $ranked = $this->messagesInRange($range)
            ->where('m.role', 'assistant')
            ->whereRaw("{$latency} IS NOT NULL")
            ->selectRaw("{$latency} AS lat, ROW_NUMBER() OVER (ORDER BY {$latency}) AS rn, COUNT(*) OVER () AS cnt");

        $row = $this->db->query()->fromSub($ranked, 'x')
            ->selectRaw('MAX(cnt) AS n, MIN(CASE WHEN rn * 2 >= cnt THEN lat END) AS p50, MIN(CASE WHEN rn * 10 >= cnt * 9 THEN lat END) AS p90')
            ->first();

        $n = (int) ($row->n ?? 0);

        return [
            'latency_samples' => $n,
            'latency_median_ms' => $n > 0 && $row->p50 !== null ? round((float) $row->p50, 1) : null,
            'latency_p90_ms' => $n > 0 && $row->p90 !== null ? round((float) $row->p90, 1) : null,
        ];
    }

    // =========================================================================
    // Lists
    // =========================================================================

    /**
     * Rated answers in the range, negative first then newest, each with the user
     * question that preceded it in its conversation.
     *
     * Row: id, content, metadata (json string), execution_time_ms, created_at, feedback,
     * question, conversation_uuid, conversation_title, user_name, team_name.
     */
    public function feedbackQuery(InsightsRange $range, string $feedback = 'all'): Builder
    {
        $fb = $this->sql->feedback('m');
        $feedback = in_array($feedback, self::FEEDBACK_FILTERS, true) ? $feedback : 'all';

        $question = $this->db->table("{$this->messages} as q")
            ->selectRaw('SUBSTR(q.content, 1, 600)')
            ->whereColumn('q.conversation_id', 'm.conversation_id')
            ->where('q.role', 'user')
            ->whereColumn('q.id', '<', 'm.id')
            ->orderByDesc('q.id')
            ->limit(1);

        $query = $this->messagesInRange($range);
        $this->joinPeople($query);

        return $query
            ->where('m.role', 'assistant')
            ->when(
                $feedback === 'all',
                fn (Builder $q) => $q->whereRaw("{$fb} IN ('positive', 'negative')"),
                fn (Builder $q) => $q->whereRaw("{$fb} = ?", [$feedback]),
            )
            ->select([
                'm.id', 'm.content', 'm.metadata', 'm.execution_time_ms', 'm.created_at',
                'c.uuid as conversation_uuid', 'c.title as conversation_title',
            ])
            ->selectRaw("{$fb} AS feedback")
            ->selectSub($question, 'question')
            ->addSelect($this->peopleColumns())
            ->orderByRaw("CASE WHEN {$fb} = 'negative' THEN 0 ELSE 1 END")
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id');
    }

    /**
     * Conversations with at least one message in the range.
     *
     * Filters: user (name / email contains), team (id), feedback ('' | any | positive |
     * negative | none), answers ('' | regenerated | no_sources | slow: an answer of the
     * conversation is), text (title or any message contains; LIKE wildcards literal), tasks
     * ('1': ran a task; only while ai.tasks is configured).
     * Row: id, uuid, title, created_at, user_name, team_name, messages_count,
     * positive_count, negative_count, last_activity.
     */
    public function conversationsQuery(InsightsRange $range, array $filters = []): Builder
    {
        $fb = $this->sql->feedback('mm');
        $query = $this->visibleConversations();
        $this->joinPeople($query);

        $query->whereExists(fn (Builder $q) => $q->from("{$this->messages} as mr")
            ->selectRaw('1')
            ->whereColumn('mr.conversation_id', 'c.id')
            ->whereBetween('mr.created_at', [$range->fromString(), $range->toString()]));

        $user = trim((string) ($filters['user'] ?? ''));
        if ($user !== '' && $this->hasUsers()) {
            $like = $this->likePattern($user);
            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw("u.{$this->userNameColumn()} LIKE ? ESCAPE '!'", [$like]);
                if ($this->hasColumn($this->usersTable(), 'email')) {
                    $q->orWhereRaw("u.email LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }

        $team = $filters['team'] ?? null;
        if (is_numeric($team) && (int) $team > 0) {
            $query->whereRaw($this->teamIdExpression() . ' = ?', [(int) $team]);
        }

        $feedbackFilter = (string) ($filters['feedback'] ?? '');
        $rated = fn (array $values) => fn (Builder $q) => $q->from("{$this->messages} as mm")
            ->selectRaw('1')
            ->whereColumn('mm.conversation_id', 'c.id')
            ->whereIn($this->db->raw($fb), $values);
        match ($feedbackFilter) {
            'any' => $query->whereExists($rated(['positive', 'negative'])),
            'positive' => $query->whereExists($rated(['positive'])),
            'negative' => $query->whereExists($rated(['negative'])),
            'none' => $query->whereNotExists($rated(['positive', 'negative'])),
            default => null,
        };

        $answered = fn (string $condition, array $bindings = []) => fn (Builder $q) => $q->from("{$this->messages} as ma")
            ->selectRaw('1')
            ->whereColumn('ma.conversation_id', 'c.id')
            ->where('ma.role', 'assistant')
            ->whereRaw($condition, $bindings);
        match ((string) ($filters['answers'] ?? '')) {
            'regenerated' => $query->whereExists($answered($this->sql->regenerated('ma'))),
            'no_sources' => $query->whereExists($answered($this->sql->noSources('ma'))),
            'slow' => $query->whereExists($answered($this->sql->latency('ma') . ' > ?', [self::slowAnswerMs()])),
            default => null,
        };

        if (($filters['tasks'] ?? '') === '1' && TaskOrchestrator::configured()) {
            $query->whereExists(fn (Builder $q) => $q->from((new AiTask())->getTable() . ' as tk')
                ->selectRaw('1')
                ->whereColumn('tk.conversation_id', 'c.id'));
        }

        $text = trim((string) ($filters['text'] ?? ''));
        if ($text !== '') {
            $like = $this->likePattern($text);
            $query->where(fn (Builder $q) => $q
                ->whereRaw("c.title LIKE ? ESCAPE '!'", [$like])
                ->orWhereExists(fn (Builder $s) => $s->from("{$this->messages} as mt")
                    ->selectRaw('1')
                    ->whereColumn('mt.conversation_id', 'c.id')
                    ->whereRaw("mt.content LIKE ? ESCAPE '!'", [$like])));
        }

        $count = fn (?array $values = null) => $this->db->table("{$this->messages} as mm")
            ->selectRaw('COUNT(*)')
            ->whereColumn('mm.conversation_id', 'c.id')
            ->when($values !== null, fn (Builder $q) => $q->whereIn($this->db->raw($fb), $values));

        return $query
            ->select(['c.id', 'c.uuid', 'c.title', 'c.created_at'])
            ->addSelect($this->peopleColumns())
            ->selectSub($count(), 'messages_count')
            ->selectSub($count(['positive']), 'positive_count')
            ->selectSub($count(['negative']), 'negative_count')
            ->selectSub(
                $this->db->table("{$this->messages} as ml")->selectRaw('MAX(ml.created_at)')->whereColumn('ml.conversation_id', 'c.id'),
                'last_activity'
            )
            ->orderByRaw('COALESCE(c.last_message_at, c.created_at) DESC')
            ->orderByDesc('c.id');
    }

    /** @return array<int, string> team id => name, for the teams of visible conversations */
    public function teamOptions(): array
    {
        if (!$this->hasTeams()) {
            return [];
        }

        $teamId = $this->teamIdExpression();
        $query = $this->visibleConversations();
        $this->joinPeople($query);

        return $query
            ->whereRaw("{$teamId} IS NOT NULL")
            ->selectRaw("{$teamId} AS team_id, MAX(t.{$this->teamNameColumn()}) AS team_name")
            ->groupBy($this->db->raw($teamId))
            ->orderByRaw("MAX(t.{$this->teamNameColumn()})")
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->team_id => (string) ($r->team_name ?? ('#' . $r->team_id))])
            ->all();
    }

    /**
     * One visible conversation by its uuid (never by id), with its messages in order.
     *
     * @return array{conversation: object, messages: Collection<int, AiMessage>}|null
     */
    public function transcript(string $uuid): ?array
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $uuid) !== 1) {
            return null;
        }

        $query = $this->visibleConversations();
        $this->joinPeople($query);
        $conversation = $query
            ->where('c.uuid', $uuid)
            ->select(['c.id', 'c.uuid', 'c.title', 'c.created_at'])
            ->addSelect($this->peopleColumns())
            ->first();

        if (!$conversation) {
            return null;
        }

        $messages = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'metadata', 'execution_time_ms', 'created_at']);

        return ['conversation' => $conversation, 'messages' => $messages];
    }

    /**
     * The tasks a conversation ran, oldest first, each with its audit events and whether
     * their hash chain still holds. Empty while ai.tasks is not configured.
     *
     * @return list<array{task: AiTask, events: Collection<int, AiTaskEvent>, chain_ok: bool}>
     */
    public function taskTimeline(int $conversationId): array
    {
        if (!TaskOrchestrator::configured()) {
            return [];
        }

        $store = new TaskStore();

        return AiTask::where('conversation_id', $conversationId)->orderBy('id')->get()
            ->map(fn (AiTask $task) => [
                'task' => $task,
                'events' => $task->events()->orderBy('id')->get(),
                'chain_ok' => $store->verifyChain($task),
            ])->all();
    }

    // =========================================================================
    // Trace summary (display only)
    // =========================================================================

    /**
     * A compact, display-safe summary of one answer's metadata.
     *
     * @param array<string, mixed>|string|null $metadata
     * @return array{route:?string, llm_calls:?int, tokens:?int, latency_ms:?float, is_error:bool, errors:list<string>, files:list<string>}
     */
    public static function traceSummary(array|string|null $metadata, int|float|null $executionTimeMs): array
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $metadata = is_array($metadata) ? $metadata : [];
        $trace = is_array($metadata['trace'] ?? null) ? $metadata['trace'] : [];

        $route = null;
        foreach ((array) ($trace['stages'] ?? []) as $stage) {
            $branch = is_array($stage) ? ($stage['data']['branch'] ?? null) : null;
            if (is_string($branch) && $branch !== '') {
                $route = $branch;
            }
        }

        $total = $trace['usage']['total'] ?? null;
        $latency = $trace['total_ms'] ?? null;
        $latency = is_numeric($latency) ? (float) $latency : ($executionTimeMs !== null ? (float) $executionTimeMs : null);

        $errors = [];
        foreach ((array) ($trace['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $errors[] = trim(((string) ($error['stage'] ?? '?')) . ': ' . mb_substr((string) ($error['message'] ?? ($error['class'] ?? '')), 0, 160));
            }
        }

        $files = [];
        $sources = $metadata['sources'] ?? $metadata['referenced_files'] ?? [];
        foreach (is_array($sources) ? $sources : [] as $file) {
            $name = is_array($file) ? ($file['name'] ?? $file['title'] ?? null) : null;
            if (is_string($name) && $name !== '' && !in_array($name, $files, true)) {
                $files[] = $name;
            }
        }

        $flag = $metadata['error'] ?? false;

        return [
            'route' => $route,
            'llm_calls' => is_array($total) && is_numeric($total['calls'] ?? null) ? (int) $total['calls'] : null,
            'tokens' => is_array($total) && is_numeric($total['total'] ?? null) ? (int) $total['total'] : null,
            'latency_ms' => $latency,
            'is_error' => $flag === true || $flag === 1 || $flag === '1' || $flag === 'true' || $route === 'error',
            'errors' => $errors,
            'files' => $files,
        ];
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** ai_messages m JOIN visible ai_conversations c, messages dated in the range. */
    private function messagesInRange(InsightsRange $range): Builder
    {
        return $this->db->table("{$this->messages} as m")
            ->join("{$this->conversations} as c", 'c.id', '=', 'm.conversation_id')
            ->whereNull('c.deleted_at')
            ->whereRaw('NOT ' . $this->sql->isEvalConversation('c'))
            ->whereBetween('m.created_at', [$range->fromString(), $range->toString()]);
    }

    private function visibleConversations(): Builder
    {
        return $this->db->table("{$this->conversations} as c")
            ->whereNull('c.deleted_at')
            ->whereRaw('NOT ' . $this->sql->isEvalConversation('c'));
    }

    private function joinPeople(Builder $query): void
    {
        if ($this->hasUsers()) {
            $query->leftJoin("{$this->usersTable()} as u", 'u.id', '=', 'c.user_id');
        }
        if ($this->hasTeams()) {
            $query->leftJoin("{$this->teamsTable()} as t", fn ($join) => $join->on('t.id', '=', $this->db->raw($this->teamIdExpression())));
        }
    }

    /** @return list<\Illuminate\Contracts\Database\Query\Expression> */
    private function peopleColumns(): array
    {
        return [
            $this->db->raw($this->hasUsers() ? "u.{$this->userNameColumn()} AS user_name" : 'NULL AS user_name'),
            $this->db->raw($this->hasTeams() ? "t.{$this->teamNameColumn()} AS team_name" : 'NULL AS team_name'),
            $this->db->raw($this->teamIdExpression() . ' AS team_id'),
        ];
    }

    private function teamIdExpression(): string
    {
        return $this->hasUsers() && $this->hasColumn($this->usersTable(), $this->userTeamColumn())
            ? "COALESCE(c.team_id, u.{$this->userTeamColumn()})"
            : 'c.team_id';
    }

    private function likePattern(string $value): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($value, 0, 200)) . '%';
    }

    private function usersTable(): string
    {
        $configured = config('ai.admin.users_table');
        if (is_string($configured) && $configured !== '') {
            return $this->identifier($configured);
        }

        $model = config('auth.providers.users.model');

        try {
            return is_string($model) && class_exists($model) ? $this->identifier((new $model())->getTable()) : 'users';
        } catch (\Throwable) {
            return 'users';
        }
    }

    private function userNameColumn(): string
    {
        return $this->identifier((string) config('ai.admin.user_name_column', 'name'));
    }

    private function userTeamColumn(): string
    {
        return $this->identifier((string) config('ai.admin.user_team_column', 'current_team_id'));
    }

    private function teamsTable(): string
    {
        return $this->identifier((string) config('ai.admin.teams_table', 'teams'));
    }

    private function teamNameColumn(): string
    {
        return $this->identifier((string) config('ai.admin.team_name_column', 'name'));
    }

    private function hasUsers(): bool
    {
        return $this->hasColumn($this->usersTable(), $this->userNameColumn());
    }

    private function hasTeams(): bool
    {
        return $this->hasColumn($this->teamsTable(), $this->teamNameColumn());
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if (!array_key_exists($key, $this->schema)) {
            try {
                $schema = Schema::connection($this->db->getName());
                $this->schema[$key] = $schema->hasTable($table) && $schema->hasColumn($table, $column);
            } catch (\Throwable) {
                $this->schema[$key] = false;
            }
        }

        return $this->schema[$key];
    }

    /** Config-provided table / column names are identifiers only — never SQL. */
    private function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid identifier in ai.admin config: {$name}");
        }

        return $name;
    }
}
