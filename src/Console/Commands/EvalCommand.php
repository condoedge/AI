<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\DTOs\Eval\EvalReport;
use Condoedge\Ai\DTOs\Eval\EvalRow;
use Condoedge\Ai\Services\Eval\AttributionService;
use Condoedge\Ai\Services\Eval\JudgeService;
use Condoedge\Ai\Services\Eval\PipelineEvalDriver;
use Condoedge\Ai\Services\Eval\QuestionSetGenerator;
use Condoedge\Ai\Services\LlmResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * ai:eval — AI-Driven Closed-Q&A Eval Harness with Per-Stage Failure Attribution.
 *
 * Generates (or loads) a label-free question set FROM the project config, drives
 * each question through the real pipeline via {@see PipelineEvalDriver}, grades
 * the answer with a reference-free LLM judge ({@see JudgeService}), then attributes
 * failure to exactly one weakest stage with {@see AttributionService}.
 *
 * Output: a per-stage SCORECARD (understanding/retrieval/prompt/generation, each
 * 0-1), a "weakest stage" headline, and a JSON report. Exit code is non-zero when
 * any stage average falls below its configured threshold.
 *
 * Everything degrades gracefully: with no LLM/DB/stores reachable the run still
 * completes (judge skipped, stages attributed from deterministic signals).
 *
 * Usage:
 *   php artisan ai:eval --generate
 *   php artisan ai:eval --suite=tests/eval/condo.json --report=storage/eval.json
 *   php artisan ai:eval --from-logs --limit=20 --tags=data_query
 *   php artisan ai:eval --offline   (no judge, signals-only)
 */
class EvalCommand extends Command
{
    protected $signature = 'ai:eval
                            {--suite= : Path to a JSON suite ({"turns":[{"question":"...","tags":[...]}]})}
                            {--generate : Generate the question set from config (default when no suite)}
                            {--from-logs : Build the set from past AiQueryLog questions}
                            {--limit= : Max questions to evaluate}
                            {--tags= : Comma-separated tags to filter questions}
                            {--report= : Path to write the JSON report (default: config ai.eval.report_path)}
                            {--offline : Skip the LLM judge & response generation (signals-only)}
                            {--user= : User ID to simulate (default: first user)}';

    protected $description = 'Run the closed-Q&A eval harness with per-stage failure attribution';

    public function handle(
        PipelineEvalDriver $driver,
        AttributionService $attribution
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

        // ---- Load / generate the question set ----
        try {
            $questions = $this->loadQuestions($offline);
        } catch (\Throwable $e) {
            $this->error('Failed to build question set: ' . $e->getMessage());
            return self::FAILURE;
        }

        $questions = $this->filterAndLimit($questions);

        if (empty($questions)) {
            $this->warn('No questions to evaluate. Configure ai.entities / ai.chat.example_questions, '
                . 'or pass --suite / --from-logs.');
            return self::SUCCESS;
        }

        $this->info('Questions: ' . count($questions) . ($offline ? ' (offline / signals-only)' : ''));
        $this->newLine();

        $judge = $offline ? null : $this->makeJudge($evalConfig);

        // ---- Drive each question through the pipeline ----
        $rows = [];
        $bar = $this->output->createProgressBar(count($questions));
        $bar->start();

        foreach ($questions as $q) {
            $rows[] = $this->evaluateOne($q, $user, $driver, $judge, $attribution, $offline);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // ---- Aggregate + report ----
        $thresholds = (array) ($evalConfig['stage_thresholds'] ?? []);
        $report = $attribution->aggregate($rows, $thresholds, [
            'user_id' => $user?->id,
            'offline' => $offline,
            'question_count' => count($questions),
        ]);

        $this->printScorecard($report);
        $this->writeReport($report, $evalConfig);

        // ---- Exit code: fail if any stage avg below threshold ----
        if (!$report->passed()) {
            $this->error('FAIL — stage(s) below threshold: ' . implode(', ', $report->failedStages));
            return self::FAILURE;
        }

        $this->info('PASS — all stage averages met their thresholds.');
        return self::SUCCESS;
    }

    /**
     * Evaluate a single question: drive → (gate) → judge → attribute.
     *
     * @param array{question: string, tags: string[]} $q
     */
    private function evaluateOne(
        array $q,
        mixed $user,
        PipelineEvalDriver $driver,
        ?JudgeService $judge,
        AttributionService $attribution,
        bool $offline
    ): EvalRow {
        try {
            $row = $driver->run($q['question'], $user, $q['tags'] ?? [], $offline);
        } catch (\Throwable $e) {
            // Driver already traps per stage; this is a last-resort guard.
            $row = new EvalRow(question: $q['question'], tags: $q['tags'] ?? []);
            $row->verdictLabel = 'stage-error';
            $row->erroredStage = 'retrieval';
            $row->error = $e->getMessage();
            return $attribution->attribute($row);
        }

        // GENERATION GATE / stage-error already short-circuit judging.
        $shouldJudge = $judge !== null
            && !$offline
            && !in_array($row->verdictLabel, ['context-not-retrieved', 'stage-error'], true)
            && $row->answer !== '';

        if ($shouldJudge) {
            $row->verdict = $judge->judge($row->question, $row->answer, $driver->judgeContext($row));
        }

        return $attribution->attribute($row);
    }

    /**
     * Build the question set from --suite / --from-logs / --generate (default).
     *
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function loadQuestions(bool $offline): array
    {
        if ($this->option('suite')) {
            return $this->loadSuite((string) $this->option('suite'));
        }

        if ($this->option('from-logs')) {
            return $this->loadFromLogs();
        }

        // Default + --generate: build from config.
        return $this->makeGenerator()->generate($offline, null);
    }

    /**
     * Load a {"turns":[{"question":"...","tags":[...]}]} suite (TestConversation schema).
     *
     * @return array<int, array{question: string, tags: string[]}>
     */
    private function loadSuite(string $path): array
    {
        if (!File::exists($path)) {
            throw new \RuntimeException("Suite file not found: {$path}");
        }

        $data = json_decode(File::get($path), true);
        if (!is_array($data) || empty($data['turns'])) {
            throw new \RuntimeException('Invalid suite. Expected {"turns":[{"question":"..."}]}');
        }

        $out = [];
        foreach ($data['turns'] as $turn) {
            $question = trim((string) ($turn['question'] ?? ''));
            if ($question === '') {
                continue;
            }
            $out[] = ['question' => $question, 'tags' => (array) ($turn['tags'] ?? ['suite'])];
        }
        return $out;
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

    private function makeGenerator(): QuestionSetGenerator
    {
        return new QuestionSetGenerator(
            llmResolver: $this->resolveOptional(LlmResolver::class),
            config: [
                'entities' => (array) config('ai.entities', []),
                'example_questions' => (array) config('ai.chat.example_questions', []),
                'business_rules' => (array) config('ai.project.business_rules', []),
                'physical_paths' => (array) config('ai.file_context.physical_paths', []),
            ]
        );
    }

    private function makeJudge(array $evalConfig): JudgeService
    {
        return new JudgeService(
            llmResolver: $this->resolveOptional(LlmResolver::class),
            config: [
                'role' => $evalConfig['judge']['role'] ?? 'eval_judge',
                'temperature' => $evalConfig['judge']['temperature'] ?? 0.0,
                'max_tokens' => $evalConfig['judge']['max_tokens'] ?? 800,
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
        $this->line("Rows evaluated: {$report->totalRows()}" . ($report->offline ? '  (judge skipped / offline)' : ''));

        if ($report->weakestStage !== null) {
            $weak = $report->weakestStage;
            $score = number_format($report->scorecard[$weak] ?? 0.0, 2);
            $this->warn("Weakest stage: {$weak} (avg {$score}, blamed in {$report->blameCounts[$weak]} row(s))");
        }

        // Show a few representative failing rows.
        $failing = array_filter(
            $report->rows,
            fn (EvalRow $r) => in_array($r->verdictLabel, ['failed', 'context-not-retrieved', 'stage-error'], true)
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
