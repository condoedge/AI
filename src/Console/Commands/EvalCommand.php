<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\DTOs\Eval\EvalReport;
use Condoedge\Ai\DTOs\Eval\EvalRow;
use Condoedge\Ai\Services\Eval\AttributionService;
use Condoedge\Ai\Services\Eval\ConversationEvalRunner;
use Condoedge\Ai\Services\Eval\EvalSuiteLoader;
use Condoedge\Ai\Services\Eval\ExpectationGate;
use Condoedge\Ai\Services\Eval\FreshEvalStateCleaner;
use Condoedge\Ai\Services\Eval\PipelineEvalDriver;
use Condoedge\Ai\Services\Eval\QuestionSetGenerator;
use Condoedge\Ai\Services\LlmResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * ai:eval — AI-Driven Closed-Q&A Eval Harness with Per-Stage Failure Attribution.
 *
 * Generates (or loads) a label-free question set FROM the project config, drives
 * each question through the real pipeline via {@see PipelineEvalDriver}, then
 * attributes failure to exactly one weakest stage with {@see AttributionService}
 * from deterministic per-stage signals. Answer grading is done out-of-process by
 * an agent, not by an in-command LLM judge.
 *
 * Output: a per-stage SCORECARD (understanding/retrieval/prompt/generation, each
 * 0-1), a "weakest stage" headline, and a JSON report. Exit code is non-zero when
 * any stage average falls below its configured threshold.
 *
 * Everything degrades gracefully: with no LLM/DB/stores reachable the run still
 * completes (stages attributed from deterministic signals).
 *
 * MULTI-TURN SUITES: --suite accepts {"conversations":[{"id","role","turns":[
 * {"ask","expect","standard"}]}]} (legacy flat {"turns":[...]} = ONE conversation).
 * Suite conversations run their turns IN ORDER through the PRODUCTION conversation
 * threading (ConversationContextManager + AiConversation, via ConversationEvalRunner),
 * with deterministic fact-gates from each turn's expect block. Any gate failure
 * makes the command exit non-zero.
 *
 * Usage:
 *   php artisan ai:eval --generate
 *   php artisan ai:eval --suite=tests/eval/condo.json --report=storage/eval.json
 *   php artisan ai:eval --from-logs --limit=20 --tags=data_query
 *   php artisan ai:eval --offline   (skip response generation, signals-only)
 *   php artisan ai:eval --suite=... --fresh   (clear AI caches + eval conversations first)
 */
class EvalCommand extends Command
{
    protected $signature = 'ai:eval
                            {--suite= : Path to a JSON suite, "conversations" schema or legacy flat "turns" schema}
                            {--generate : Generate the question set from config (default when no suite)}
                            {--from-logs : Build the set from past AiQueryLog questions}
                            {--limit= : Max questions (flat mode) / conversations (suite mode) to evaluate}
                            {--iterations=1 : Repeat the selected question set N times (flat mode only)}
                            {--tags= : Comma-separated tags to filter questions (flat mode only)}
                            {--report= : Path to write the JSON report (default: config ai.eval.report_path)}
                            {--offline : Skip response generation (signals-only)}
                            {--fresh : Clear AI-owned caches + the pinned user\'s eval conversation state first}
                            {--throttle=0 : Milliseconds to sleep before each turn — pace the run inside the LLM provider\'s TPM budget (a full-speed suite saturates it and 429s contaminate the measurement)}
                            {--user= : User ID to simulate (default: first user)}';

    protected $description = 'Run the closed-Q&A eval harness with per-stage failure attribution';

    public function handle(
        PipelineEvalDriver $driver,
        AttributionService $attribution,
        ConversationEvalRunner $conversationRunner,
        FreshEvalStateCleaner $freshCleaner
    ): int {
        $evalConfig = (array) config('ai.eval', []);
        $offline = (bool) $this->option('offline');

        $this->info('AI Eval Harness');
        $this->info(str_repeat('=', 50));

        // ---- Resolve user (CLI has no web session; warn but proceed) ----
        $user = $this->resolveUser();
        if ($user === null) {
            $this->warn('No user found — running without an authenticated user. Security filters may differ.');
        } else {
            $this->info("User: {$user->name} (ID: {$user->id})");
            auth()->login($user);
        }

        // ---- --fresh: clear AI-owned caches + eval conversation state ----
        if ((bool) $this->option('fresh')) {
            $this->info('--fresh: clearing AI-owned state...');
            foreach ($freshCleaner->clean($user) as $line) {
                $this->line('  - ' . $line);
            }
            $this->newLine();
        }

        // ---- Suite mode: multi-turn conversations through production threading ----
        if ($this->option('suite')) {
            return $this->runSuiteMode($conversationRunner, $attribution, $user, $offline, $evalConfig);
        }

        // ---- Flat mode: independent single questions (generated / from-logs) ----
        try {
            $questions = $this->loadQuestions($offline);
        } catch (\Throwable $e) {
            $this->error('Failed to build question set: ' . $e->getMessage());
            return self::FAILURE;
        }

        $questions = $this->filterAndLimit($questions);
        $baseQuestionCount = count($questions);
        $iterations = max(1, (int) ($this->option('iterations') ?: 1));
        $questions = $this->repeatQuestions($questions, $iterations);

        if (empty($questions)) {
            $this->warn('No questions to evaluate. Configure ai.entities / ai.chat.example_questions, '
                . 'or pass --suite / --from-logs.');
            return self::SUCCESS;
        }

        $iterationText = $iterations > 1
            ? " ({$baseQuestionCount} question(s) x {$iterations} iteration(s))"
            : '';
        $this->info('Questions: ' . count($questions) . $iterationText . ($offline ? ' (offline / signals-only)' : ''));
        $this->newLine();

        // ---- Drive each question through the pipeline ----
        $rows = [];
        $bar = $this->output->createProgressBar(count($questions));
        $bar->start();

        $throttleMs = max(0, (int) $this->option('throttle'));
        foreach ($questions as $q) {
            // Same pacing as suite mode: keep the run inside the provider's TPM budget.
            if ($throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
            $rows[] = $this->evaluateOne($q, $user, $driver, $attribution, $offline);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // ---- Aggregate + report ----
        $thresholds = (array) ($evalConfig['stage_thresholds'] ?? []);
        $report = $attribution->aggregate($rows, $thresholds, [
            'user_id' => $user?->id,
            'offline' => $offline,
            'mode' => 'flat',
            'question_count' => count($questions),
            'base_question_count' => $baseQuestionCount,
            'iterations' => $iterations,
        ]);

        $this->printScorecard($report);
        $this->writeReport($report, $evalConfig);

        return $this->finish($report);
    }

    /**
     * Suite mode: each conversation's turns run IN ORDER through the production
     * conversation threading (ConversationEvalRunner); fact-gates from the suite's
     * expect blocks are evaluated per turn; any gate failure fails the run.
     */
    private function runSuiteMode(
        ConversationEvalRunner $runner,
        AttributionService $attribution,
        mixed $user,
        bool $offline,
        array $evalConfig
    ): int {
        try {
            $conversations = (new EvalSuiteLoader())->load((string) $this->option('suite'));
        } catch (\Throwable $e) {
            $this->error('Failed to load suite: ' . $e->getMessage());
            return self::FAILURE;
        }

        $limit = (int) ($this->option('limit') ?: 0);
        if ($limit > 0) {
            $conversations = array_slice($conversations, 0, $limit);
        }
        if ($this->option('tags') || (int) ($this->option('iterations') ?: 1) > 1) {
            $this->warn('--tags / --iterations apply to flat mode only and are ignored for suites.');
        }

        // --offline skips response generation (execution still runs), so:
        //  - payload gates (produces_file, cites_document:true,
        //    promised_columns_nonempty:true) are STRUCTURALLY impossible to pass
        //    and will red-gate;
        //  - answer gates (discloses_total:true, no_self_contact:true) run against
        //    an EMPTY answer: they red-gate when rows were withheld, but otherwise
        //    pass only VACUOUSLY (condition never fired — no fact verified), so an
        //    offline green on them is NOT evidence of compliance;
        //  - disambiguation_column_present on an EXPORT turn (produces_file
        //    declared) has only the export payload as evidence — never produced
        //    offline — so it too can only pass VACUOUSLY (data turns still get a
        //    real check: execution runs and yields their result sample).
        // Warn up front so an offline report is never misread either way.
        if ($offline) {
            $impossible = 0;
            $vacuousOnly = 0;
            foreach ($conversations as $conversation) {
                foreach ($conversation['turns'] as $turn) {
                    $expect = (array) ($turn['expect'] ?? []);
                    if (isset($expect['produces_file'])
                        || (($expect['cites_document'] ?? null) === true)
                        || (($expect['promised_columns_nonempty'] ?? null) === true)) {
                        $impossible++;
                    }
                    if ((($expect['discloses_total'] ?? null) === true)
                        || (($expect['no_self_contact'] ?? null) === true)
                        || ((($expect['disambiguation_column_present'] ?? null) === true)
                            && isset($expect['produces_file']))) {
                        $vacuousOnly++;
                    }
                }
            }
            if ($impossible > 0) {
                $this->warn(sprintf(
                    '--offline skips response generation: %d turn(s) declare payload gates '
                    . '(produces_file / cites_document / promised_columns_nonempty) that CANNOT '
                    . 'pass offline and will red-gate. Run without --offline to evaluate them.',
                    $impossible
                ));
            }
            if ($vacuousOnly > 0) {
                $this->warn(sprintf(
                    '--offline generates no answer or export payload: %d turn(s) declare gates '
                    . '(discloses_total / no_self_contact / disambiguation_column_present on '
                    . 'export turns) that can only red-gate or pass VACUOUSLY offline — a green '
                    . 'on them verifies nothing. Run without --offline to evaluate them.',
                    $vacuousOnly
                ));
            }
        }

        $turnCount = array_sum(array_map(fn ($c) => count($c['turns']), $conversations));
        $this->info(sprintf(
            'Conversations: %d (%d turn(s))%s',
            count($conversations),
            $turnCount,
            $offline ? ' (offline / signals-only)' : ''
        ));
        $this->newLine();

        $rows = [];
        $bar = $this->output->createProgressBar(count($conversations));
        $bar->start();

        foreach ($conversations as $conversation) {
            try {
                $rows = array_merge($rows, $runner->run($conversation, $user, $offline, throttleMs: max(0, (int) $this->option('throttle'))));
            } catch (\Throwable $e) {
                // A conversation that cannot even start (e.g. no DB for AiConversation)
                // becomes one stage-error row per turn — honest, and the run continues.
                // Fact-gates are STILL evaluated against the (empty) row so the
                // "any gate failure => non-zero exit" contract holds even for turns
                // that never ran — a skipped expect block must never look like PASS.
                $gate = new ExpectationGate();
                foreach (array_values($conversation['turns']) as $turnIndex => $turn) {
                    $row = new EvalRow(question: (string) $turn['ask'], tags: (array) ($turn['tags'] ?? []));
                    $row->conversationId = $conversation['id'];
                    $row->turnIndex = $turnIndex;
                    $row->role = $conversation['role'] ?? null;
                    $row->standard = $turn['standard'] ?? null;
                    $row->expect = (array) ($turn['expect'] ?? []);
                    $row->verdictLabel = 'stage-error';
                    $row->erroredStage = 'retrieval';
                    $row->erroredStep = 'conversation_start';
                    $row->error = 'Conversation could not run: ' . $e->getMessage();
                    $row->gates = $gate->evaluate($row->expect, $row);
                    $rows[] = $attribution->attribute($row);
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $thresholds = (array) ($evalConfig['stage_thresholds'] ?? []);
        $report = $attribution->aggregate($rows, $thresholds, [
            'user_id' => $user?->id,
            'offline' => $offline,
            'mode' => 'conversations',
            'conversation_count' => count($conversations),
            'question_count' => count($rows),
        ]);

        $this->printScorecard($report);
        $this->writeReport($report, $evalConfig);

        return $this->finish($report);
    }

    /**
     * Shared exit decision: stage thresholds AND fact-gates are both hard
     * requirements — either failing makes the command exit non-zero.
     */
    private function finish(EvalReport $report): int
    {
        $gateFailures = $report->gateFailures();
        if (!empty($gateFailures)) {
            $this->error('FAIL — ' . count($gateFailures) . ' fact-gate failure(s):');
            foreach ($gateFailures as $failure) {
                $this->line(sprintf(
                    '  [%s turn %d] gate %s: %s',
                    $failure['conversation_id'] ?? '-',
                    $failure['turn_index'],
                    $failure['gate'],
                    $failure['detail']
                ));
            }
        }

        if (!empty($report->failedStages)) {
            $this->error('FAIL — stage(s) below threshold: ' . implode(', ', $report->failedStages));
        }

        if (!$report->passed()) {
            return self::FAILURE;
        }

        $this->info('PASS — all stage averages met their thresholds and all fact-gates passed.');
        return self::SUCCESS;
    }

    /**
     * Evaluate a single question: drive → attribute.
     *
     * @param array{question: string, tags: string[]} $q
     */
    private function evaluateOne(
        array $q,
        mixed $user,
        PipelineEvalDriver $driver,
        AttributionService $attribution,
        bool $offline
    ): EvalRow {
        try {
            // capture_prompts: flat online runs make real LLM calls — their verbatim
            // prompts must reach the trace exactly as suite turns' do, otherwise a
            // grader cannot tell "capture off" from "no LLM call".
            $row = $driver->run($q['question'], $user, $q['tags'] ?? [], $offline, null, [
                'capture_prompts' => true,
            ]);
        } catch (\Throwable $e) {
            // Driver already traps per stage; this is a last-resort guard.
            $row = new EvalRow(question: $q['question'], tags: $q['tags'] ?? []);
            $row->verdictLabel = 'stage-error';
            $row->erroredStage = 'retrieval';
            $row->erroredStep = 'driver';
            $row->error = $e->getMessage();
            return $attribution->attribute($row);
        }

        // grading is done out-of-process by an agent, not an in-command LLM judge.
        // The row carries deterministic per-stage signals; attribution scores from those.

        return $attribution->attribute($row);
    }

    /**
     * Build the FLAT question set from --from-logs / --generate (default).
     * Suites never reach here: --suite is handled by runSuiteMode() so its turns
     * thread as conversations instead of being flattened into independent
     * questions (the old flattening made conversation bugs invisible).
     *
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function loadQuestions(bool $offline): array
    {
        if ($this->option('from-logs')) {
            return $this->loadFromLogs();
        }

        // Default + --generate: build from config.
        return $this->makeGenerator()->generate($offline, null);
    }

    /**
     * Build the set from previously-logged questions (graceful when no DB/table).
     *
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function loadFromLogs(): array
    {
        try {
            $logs = \Condoedge\Ai\Models\AiQueryLog::query()
                ->whereNotNull('question')
                ->where('question', '!=', '')
                ->where('question', '!=', 'N/A')
                ->latest()
                ->limit((int) ($this->option('limit') ?: 100))
                ->pluck('question')
                ->unique()
                ->values();
        } catch (\Throwable $e) {
            $this->warn('Could not read AiQueryLog (' . $e->getMessage() . ') — falling back to generated set.');
            return $this->makeGenerator()->generate((bool) $this->option('offline'), null);
        }

        return $logs->map(fn ($question) => [
            'question' => (string) $question,
            'tags' => ['from_logs'],
        ])->all();
    }

    /**
     * @param array<int, array{question: string, tags: string[]}> $questions
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function filterAndLimit(array $questions): array
    {
        $tags = array_filter(array_map('trim', explode(',', (string) $this->option('tags'))));
        if (!empty($tags)) {
            $questions = array_values(array_filter(
                $questions,
                fn ($q) => !empty(array_intersect($tags, $q['tags'] ?? []))
            ));
        }

        $limit = (int) ($this->option('limit') ?: 0);
        if ($limit > 0) {
            $questions = array_slice($questions, 0, $limit);
        }

        return $questions;
    }

    /**
     * @param array<int, array{question: string, tags: string[]}> $questions
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function repeatQuestions(array $questions, int $iterations): array
    {
        if ($iterations <= 1 || empty($questions)) {
            return $questions;
        }

        $out = [];
        for ($i = 1; $i <= $iterations; $i++) {
            foreach ($questions as $question) {
                $question['tags'] = array_values(array_unique(array_merge(
                    (array) ($question['tags'] ?? []),
                    ['iteration:' . $i]
                )));
                $out[] = $question;
            }
        }

        return $out;
    }

    private function makeGenerator(): QuestionSetGenerator
    {
        return new QuestionSetGenerator(
            llmResolver: $this->resolveOptional(LlmResolver::class),
            config: [
                'entities' => (array) config('ai.entities', []),
                'example_questions' => (array) config('ai.chat.example_questions', []),
                'page_examples' => (array) config('ai.chat.page_examples', []),
                'business_rules' => (array) config('ai.project.business_rules', []),
                'physical_paths' => (array) config('ai.file_context.physical_paths', []),
            ]
        );
    }

    private function resolveUser(): mixed
    {
        $userId = $this->option('user');
        $userClass = config('auth.providers.users.model', 'App\\Models\\User');

        try {
            if (!class_exists($userClass)) {
                return null;
            }
            return $userId ? $userClass::find($userId) : $userClass::first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve a binding, returning null instead of throwing when unavailable.
     */
    private function resolveOptional(string $abstract): mixed
    {
        try {
            return app($abstract);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function printScorecard(EvalReport $report): void
    {
        $this->info('SCORECARD (per stage, 0-1)');
        $this->line(str_repeat('-', 50));

        $thresholds = $report->thresholds;
        $rows = [];
        foreach (AttributionService::STAGES as $stage) {
            $score = $report->scorecard[$stage] ?? 0.0;
            $min = $thresholds[$stage] ?? null;
            $status = $min === null ? '-' : ($score >= (float) $min ? 'OK' : 'LOW');
            $rows[] = [
                ucfirst($stage),
                number_format($score, 2),
                $min === null ? '-' : number_format((float) $min, 2),
                $status,
                $report->blameCounts[$stage] ?? 0,
            ];
        }

        $this->table(['Stage', 'Avg', 'Min', 'Status', 'Blamed'], $rows);

        $this->newLine();
        $this->line("Rows evaluated: {$report->totalRows()}" . ($report->offline ? '  (offline / response generation skipped)' : ''));

        if ($report->weakestStage !== null) {
            $weak = $report->weakestStage;
            $score = number_format($report->scorecard[$weak] ?? 0.0, 2);
            $this->warn("Weakest stage: {$weak} (avg {$score}, blamed in {$report->blameCounts[$weak]} row(s))");
        }

        // Show a few representative failing rows.
        $failing = array_filter(
            $report->rows,
            fn (EvalRow $r) => in_array($r->verdictLabel, ['failed', 'context-not-retrieved', 'stage-error', 'gate-failed'], true)
        );
        if (!empty($failing)) {
            $this->newLine();
            $this->comment('Sample attributions:');
            foreach (array_slice(array_values($failing), 0, 5) as $r) {
                $this->line(sprintf(
                    "  [%s → %s] %s",
                    $r->verdictLabel,
                    $r->weakestStage ?? '-',
                    $this->truncate($r->question, 60)
                ));
                $this->line('      ' . $this->truncate($r->blameReason, 110));
            }
        }
        $this->newLine();
    }

    private function writeReport(EvalReport $report, array $evalConfig): void
    {
        $path = $this->option('report') ?: ($evalConfig['report_path'] ?? null);
        if (empty($path)) {
            return;
        }

        try {
            $dir = dirname($path);
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            File::put($path, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("Report written: {$path}");
        } catch (\Throwable $e) {
            $this->warn('Could not write report: ' . $e->getMessage());
        }
    }

    private function truncate(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return strlen($text) > $max ? substr($text, 0, $max) . '...' : $text;
    }
}
