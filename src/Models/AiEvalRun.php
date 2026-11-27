<?php

namespace Condoedge\Ai\Models;

use Condoedge\Ai\Eval\EvalSuites;
use Condoedge\Ai\Eval\AnswerAuditor;
use Condoedge\Ai\Jobs\AuditAiEvalRun;
use Condoedge\Ai\Jobs\RunAiEvalCase;
use Illuminate\Database\Eloquent\Model;
use Composer\InstalledVersions;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Support\Facades\DB;

class AiEvalRun extends Model
{
    use MassPrunable;

    public const STATUS_QUEUED = 1;
    public const STATUS_RUNNING = 2;
    public const STATUS_DONE = 3;
    public const STATUS_FAILED = 4;
    public const STATUS_CANCELLED = 5;

    public const KEEP_DAYS = 30;

    // A conversation's job is killed after 15 minutes: past 20 with no answer, nothing is working on the run.
    public const STALLED_AFTER_MINUTES = 20;
    public const UNCLAIMED_AFTER_MINUTES = 2;

    protected $casts = [
        'fixture' => 'array',
        'definition' => 'array',
        'tested' => 'array',
        'last_case_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /* RELATIONSHIPS */
    public function results()
    {
        return $this->hasMany(AiEvalResult::class);
    }

    public function startedBy()
    {
        return $this->belongsTo(\Condoedge\Ai\Eval\EvalPanel::userModel(), 'started_by');
    }

    public function rerunOf()
    {
        return $this->belongsTo(static::class, 'rerun_of_id');
    }

    /* SCOPES */
    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(static::KEEP_DAYS));
    }

    /* CALCULATED FIELDS */
    public function isFinished()
    {
        return in_array($this->status, [static::STATUS_DONE, static::STATUS_FAILED, static::STATUS_CANCELLED]);
    }

    public function isWaitingForWorker()
    {
        return $this->status == static::STATUS_QUEUED && $this->created_at->lt(now()->subMinutes(static::UNCLAIMED_AFTER_MINUTES));
    }

    public function isStalled()
    {
        return $this->status == static::STATUS_RUNNING && $this->lastActivityAt()?->lt(now()->subMinutes(static::STALLED_AFTER_MINUTES));
    }

    public function lastActivityAt()
    {
        return $this->last_case_at ?: $this->started_at;
    }

    public function secondsLeft(): ?int
    {
        if ($this->isFinished() || !$this->cases_done || !$this->started_at || !$this->last_case_at) {
            return null;
        }

        $perCase = $this->started_at->diffInSeconds($this->last_case_at) / $this->cases_done;

        return (int) round($perCase * ($this->cases_total - $this->cases_done));
    }

    /** Finished runs of the same suite, tag and ids: the only ones a score compares with. */
    public function comparableRuns()
    {
        return static::where('suite', $this->suite)
            ->where('id', '!=', $this->id)
            ->where('status', static::STATUS_DONE)
            ->when($this->tag, fn ($q) => $q->where('tag', $this->tag), fn ($q) => $q->whereNull('tag'))
            ->when($this->repeat, fn ($q) => $q->where('repeat', $this->repeat), fn ($q) => $q->whereNull('repeat'))
            ->latest('id')
            ->get()
            ->filter(fn ($run) => ($run->fixture ?? []) == ($this->fixture ?? []) && $run->definition == $this->definition)
            ->values();
    }

    /** The conversations of this run, each as many times as the run asks (its own count, else the suite's): its own when it carries them. */
    public function cases(): array
    {
        return $this->definition
            ? EvalSuites::casesOf($this->definition, $this->fixture ?? [], $this->tag, $this->repeat)
            : EvalSuites::cases($this->suite, $this->fixture ?? [], $this->tag, $this->repeat);
    }

    public function deletable()
    {
        return \Condoedge\Ai\Eval\EvalPanel::allows() && $this->isFinished();
    }

    public function passRate()
    {
        $graded = $this->turns_passed + $this->turns_failed;

        return $graded ? $this->turns_passed / $graded : null;
    }

    public function medianLatencyMs()
    {
        return $this->results()->whereNull('error')->whereNull('label')->pluck('latency_ms')->median();
    }

    /** @return array<string, int> failed answers per cause (AnswerGrader::cause), the most frequent first */
    public function causeCounts(): array
    {
        return $this->results()->whereNull('label')->get(['trace'])
            ->map(fn ($result) => $result->trace['cause'] ?? null)->filter()
            ->countBy()->sortDesc()->all();
    }

    /** Answers the provider or the infrastructure spoiled (TurnLabel): not graded, to run again. */
    public function unmeasuredCount(): int
    {
        return $this->results()->whereNotNull('label')->count();
    }

    /* ACTIONS */
    public static function prepare(string $suite, array $fixture = [], ?string $tag = null, ?int $startedBy = null, ?string $engine = null, ?int $rerunOfId = null, ?int $repeat = null, ?array $definition = null): static
    {
        $run = new static();
        $run->suite = $suite;
        $run->definition = $definition;
        $run->tag = $tag ?: null;
        $run->engine = $engine ?: null;
        $run->repeat = $repeat ?: null;
        $run->fixture = $fixture;
        $run->tested = static::testedNow();
        $run->rerun_of_id = $rerunOfId;
        $run->cases_total = count($run->cases());
        $run->status = $run->cases_total ? static::STATUS_QUEUED : static::STATUS_DONE;
        $run->finished_at = $run->cases_total ? null : now();
        $run->started_by = $startedBy;
        $run->save();

        return $run;
    }

    /** What answers the questions right now, so two runs can be told apart by what changed between them. */
    public static function testedNow(): array
    {
        $head = @file_get_contents(base_path('.git/HEAD'));
        $commit = $head && str_starts_with($head, 'ref: ') ? @file_get_contents(base_path('.git/'.trim(substr($head, 5)))) : $head;

        return [
            'commit' => $commit ? substr(trim($commit), 0, 8) : null,
            'package' => static::packageVersion(),
            'model' => config('ai.llm_roles.response_generation.model'),
            // A role on another model than the answer's changes answers too.
            'models' => collect((array) config('ai.llm_roles', []))->map(fn ($role) => is_array($role) ? ($role['model'] ?? null) : null)->filter()->all(),
            // The assistant's settings and the entities it reads: an edit there changes answers as surely as a commit.
            'config' => substr(sha1((string) json_encode([config('ai'), config('entities')], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, 8),
        ];
    }

    /** The name a pasted conversation's run goes by in the history: its first question. */
    public static function conversationTitle(array $cases): string
    {
        return 'conversation · '.\Illuminate\Support\Str::limit((string) ($cases[0]['case']['turns'][0]['ask'] ?? ''), 80);
    }

    /**
     * The code of the assistant that answers. A package linked from its own folder (a path
     * repository, as in development) is named by its git commit and its files changed since:
     * composer's version there stays the one of the last install, whatever the code is now.
     */
    public static function packageVersion(): ?string
    {
        $path = realpath(base_path('vendor/condoedge/ai'));

        if ($path && is_dir($path.'/.git')) {
            $head = @exec('git -C '.escapeshellarg($path).' rev-parse --short=8 HEAD 2>'.(PHP_OS_FAMILY == 'Windows' ? 'NUL' : '/dev/null'));
            @exec('git -C '.escapeshellarg($path).' status --porcelain 2>'.(PHP_OS_FAMILY == 'Windows' ? 'NUL' : '/dev/null'), $changed);

            if ($head) {
                return $head.(count($changed) ? ' +'.count($changed) : '');
            }
        }

        return InstalledVersions::isInstalled('condoedge/ai') ? InstalledVersions::getPrettyVersion('condoedge/ai') : null;
    }

    public static function start(string $suite, array $fixture = [], ?string $tag = null, ?int $startedBy = null, ?string $engine = null, ?int $rerunOfId = null, ?int $repeat = null, ?array $definition = null): static
    {
        $run = static::prepare($suite, $fixture, $tag, $startedBy, $engine, $rerunOfId, $repeat, $definition);

        if (!$run->isFinished()) {
            RunAiEvalCase::dispatch($run->id);
        }

        return $run;
    }

    public function rerun(?int $startedBy = null): static
    {
        return static::start($this->suite, $this->fixture ?? [], $this->tag, $startedBy, $this->engine, $this->id, $this->repeat, $this->definition);
    }

    /** Picks the run up at the conversation it stopped on; that conversation's partial answers are asked again. */
    public function resume()
    {
        if ($this->isFinished()) {
            return;
        }

        $next = $this->cases()[$this->cases_done] ?? null;

        if ($next) {
            $this->results()->where('case_id', $next['case']['id'])->where('repetition', $next['repetition'])->delete();
        }

        $this->last_case_at = now();
        $this->save();

        RunAiEvalCase::dispatch($this->id, $this->cases_done);
    }

    public function cancel()
    {
        if ($this->isFinished()) {
            return;
        }

        $this->status = static::STATUS_CANCELLED;
        $this->finished_at = now();
        $this->save();
    }

    public function recordCaseDone(int $passed, int $failed, int $ungraded)
    {
        static::whereKey($this->id)->update([
            'cases_done' => DB::raw('cases_done + 1'),
            'turns_passed' => DB::raw('turns_passed + '.$passed),
            'turns_failed' => DB::raw('turns_failed + '.$failed),
            'turns_ungraded' => DB::raw('turns_ungraded + '.$ungraded),
            'last_case_at' => now(),
        ]);

        $this->refresh();

        if ($this->cases_done >= $this->cases_total && !$this->isFinished()) {
            $this->status = static::STATUS_DONE;
            $this->finished_at = now();
            $this->save();

            AuditAiEvalRun::dispatch($this->id);
        }
    }

    /** Everything a reader outside the application needs to check the run again: each question, its answer, what was expected and how it was reached. */
    public function toExport(): array
    {
        return [
            'how_to_read' => 'A test run of Maryanne, the assistant of CondoEdge (condominium management, Quebec). Each result is one question asked on behalf of a real role. '
                .'"checks" compare the answer with values the application computed or its reference sheets state: "expected" is the truth for this environment. '
                .'"audit" holds marks from 1 to 5 given by a second model; "trace" says how the answer was reached. Amounts are Canadian dollars.',
            'run' => [
                'id' => $this->id,
                'suite' => $this->suite,
                'tag' => $this->tag,
                'tries_per_question' => $this->repeat,
                'engine' => $this->engine,
                'answered_by' => $this->tested,
                'ids_used' => $this->fixture,
                'status' => (int) $this->status,
                'started_at' => $this->started_at?->toIso8601String(),
                'finished_at' => $this->finished_at?->toIso8601String(),
                'conversations' => ['done' => $this->cases_done, 'total' => $this->cases_total],
                'answers' => ['passed' => $this->turns_passed, 'failed' => $this->turns_failed, 'ungraded' => $this->turns_ungraded],
                'audit' => $this->auditSummary(),
            ],
            'results' => $this->results()->orderBy('id')->get()->map(fn ($result) => [
                'conversation' => $result->case_id,
                'question_number' => $result->turn,
                'try' => $result->repetition,
                'asked_by' => $result->role,
                'question' => $result->question,
                'answer' => $result->answer,
                'error' => $result->error,
                'automatic_verdict' => $result->label ? 'not measured ('.$result->label.'): run it again' : ($result->passed === null ? 'not graded' : ($result->passed ? 'passed' : 'failed')),
                'checks' => $result->checks,
                'audit' => $result->audit,
                'seconds' => $result->latency_ms ? round($result->latency_ms / 1000, 1) : null,
                'trace' => $result->trace,
            ])->all(),
        ];
    }

    /** Asks for the answers not audited yet to be read again; the ones already marked keep their marks. */
    public function audit()
    {
        if ($this->isFinished()) {
            AuditAiEvalRun::dispatch($this->id);
        }
    }

    /** @return array{audited: int, total: int, accuracy: ?float, usability: ?float, security: ?float} */
    public function auditSummary(): array
    {
        $audits = $this->results()->whereNull('label')->get(['audit'])->pluck('audit');
        $marked = $audits->filter();

        return ['audited' => $marked->count(), 'total' => $audits->count()] + collect(AnswerAuditor::CRITERIA)
            ->mapWithKeys(fn ($criterion) => [$criterion => $marked->isEmpty() ? null : round($marked->avg(fn ($audit) => $audit[$criterion]['score']), 1)])->all();
    }
}
