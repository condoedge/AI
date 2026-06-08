<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Eval;

use Condoedge\Ai\DTOs\Eval\EvalRow;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Facades\AI;

/**
 * Reusable driver that walks ONE question through the real AI pipeline,
 * stage by stage, and returns a populated {@see EvalRow}.
 *
 * This generalises the stage-walk in TestQuestionCommand (context retrieval →
 * file search → query generation → execution → response) so both the test
 * command and the eval harness share one path.
 *
 * Two safety features make it eval-friendly:
 *
 *  1. PER-STAGE try/catch — a failure in any stage is attributed to THAT stage
 *     (verdictLabel = 'stage-error', erroredStage set) and the walk stops,
 *     instead of throwing. Offline stores (Qdrant/Neo4j/LLM down) therefore
 *     never crash the run.
 *
 *  2. GENERATION GATE — after retrieval + file search, if NOTHING was retrieved
 *     (empty selected_entities AND empty relevant_files AND no cypher) the row
 *     is marked 'context-not-retrieved' and the driver STOPS before generating
 *     a response or spending judge tokens.
 *
 * It does not call the judge — that is the harness's job; the driver only
 * gathers the cheap deterministic signals + the answer.
 */
class PipelineEvalDriver
{
    /**
     * @param object|null $aiFacade Optional AI manager (defaults to the AI facade).
     *                              Injectable for testing.
     */
    public function __construct(private readonly mixed $aiFacade = null) {}

    /**
     * Walk a single question through the pipeline.
     *
     * @param string     $question
     * @param mixed      $user      Authenticated user (already logged in by caller).
     * @param string[]   $tags      Tags to carry onto the row.
     * @param bool       $offline   When true, skip response generation (no LLM answer).
     */
    public function run(string $question, mixed $user, array $tags = [], bool $offline = false): EvalRow
    {
        $row = new EvalRow(question: $question, tags: $tags);

        // ---- Stage 1: Context retrieval (understanding + retrieval signals) ----
        $context = $this->stage($row, 'retrieval', function () use ($question) {
            return $this->ai()->retrieveContext($question);
        });

        if ($row->verdictLabel === 'stage-error') {
            return $row;
        }

        /** @var PipelineContext $context */
        $this->captureContextSignals($row, $context);

        // ---- Stage 1b: File search ----
        $fileContext = [];
        $start = microtime(true);
        try {
            $fileContext = $this->retrieveFiles($context, $question, $user, $offline);
        } catch (\Throwable $e) {
            // File search failures are non-fatal: degrade to "no files".
            $context->addError('file search: ' . $e->getMessage());
        }
        $row->timings['files'] = (int) round((microtime(true) - $start) * 1000);
        $row->relevantFiles = $fileContext['relevant_files'] ?? [];

        // ---- Stage 2: Query generation ----
        $isKnowledge = $context->qu->questionType === 'knowledge_query';
        $queryResult = ['cypher' => null, 'confidence' => 0, 'warnings' => [], 'explanation' => '', 'metadata' => []];

        if (!$isKnowledge) {
            $queryResult = $this->stage($row, 'understanding', function () use ($question, $context, $user) {
                return $this->ai()->generateQuery($question, $context, ['user' => $user]);
            }) ?? $queryResult;

            if ($row->verdictLabel === 'stage-error') {
                return $row;
            }
        }

        $row->cypher = $queryResult['cypher'] ?? null;

        // ---- GENERATION GATE: nothing retrieved → stop before spending judge tokens ----
        if ($row->contextIsEmpty()) {
            $row->verdictLabel = 'context-not-retrieved';
            $row->blameReason = 'No entities selected, no files found and no cypher generated.';
            return $row;
        }

        // ---- Stage 3: Query execution ----
        $executionResult = ['data' => [], 'metadata' => [], 'errors' => [], 'stats' => []];
        if (!empty($row->cypher)) {
            $executionResult = $this->stage($row, 'retrieval', function () use ($row) {
                return $this->ai()->executeQuery($row->cypher);
            }) ?? $executionResult;

            if ($row->verdictLabel === 'stage-error') {
                return $row;
            }
        }
        $row->rowCount = count($executionResult['data'] ?? []);
        $row->dataSample = array_slice($executionResult['data'] ?? [], 0, 10);

        // Offline: do not generate an answer (no LLM). Signals are enough for
        // deterministic attribution.
        if ($offline) {
            $row->verdictLabel = 'judge-skipped';
            return $row;
        }

        // ---- Stage 4: Response generation (prompt + generation) ----
        $answer = $this->stage($row, 'prompt', function () use ($question, $fileContext, $queryResult, $executionResult, $user) {
            $ctx = new PipelineContext();
            $ctx->question = $question;
            $ctx->fileContext = $fileContext;
            $ctx->queryResult = new QueryGenerationResult(
                cypher: $queryResult['cypher'] ?? null,
                explanation: $queryResult['explanation'] ?? '',
                confidence: (float) ($queryResult['confidence'] ?? 0.0),
                warnings: $queryResult['warnings'] ?? [],
                metadata: $queryResult['metadata'] ?? [],
            );
            $ctx->executionResult = new QueryExecutionResult(
                success: !empty($executionResult['data']) || empty($executionResult['errors'] ?? []),
                data: $executionResult['data'] ?? [],
                stats: $executionResult['stats'] ?? [],
                metadata: $executionResult['metadata'] ?? [],
                errors: $executionResult['errors'] ?? [],
            );

            $response = $this->ai()->generateResponse($ctx, ['user' => $user]);
            return $response['answer'] ?? '';
        });

        if ($row->verdictLabel === 'stage-error') {
            return $row;
        }

        $row->answer = (string) ($answer ?? '');

        return $row;
    }

    /**
     * Build the context payload handed to the judge (data + files + cypher).
     */
    public function judgeContext(EvalRow $row): array
    {
        return [
            'data' => $row->dataSample,
            'files' => $row->relevantFiles,
            'cypher' => $row->cypher,
        ];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Run a pipeline stage, timing it and trapping failures to that stage.
     * Returns the callback result, or null if it threw (verdictLabel updated).
     */
    private function stage(EvalRow $row, string $stage, callable $fn): mixed
    {
        $start = microtime(true);
        try {
            $result = $fn();
            $row->timings[$stage] = ($row->timings[$stage] ?? 0) + (int) round((microtime(true) - $start) * 1000);
            return $result;
        } catch (\Throwable $e) {
            $row->timings[$stage] = ($row->timings[$stage] ?? 0) + (int) round((microtime(true) - $start) * 1000);
            $row->verdictLabel = 'stage-error';
            $row->erroredStage = $stage;
            $row->error = $e->getMessage();
            return null;
        }
    }

    private function captureContextSignals(EvalRow $row, PipelineContext $context): void
    {
        $row->selectedEntities = $context->selectionInfo['selected_entities'] ?? [];
        $row->quEntityTypes = $context->qu->entityTypes ?? [];
        $row->questionType = $context->qu->questionType ?: 'hybrid';

        // Normalise context quality to 0-1.
        $score = (float) ($context->quality['score'] ?? 0);
        $max = (float) ($context->quality['max'] ?? 0);
        $row->contextQuality = $max > 0 ? round(min(1.0, $score / $max), 4) : 0.0;
    }

    /**
     * Retrieve + merge file context across QU phrasings (mirrors AiManager).
     */
    private function retrieveFiles(PipelineContext $context, string $question, mixed $user, bool $offline): array
    {
        if (!config('ai.file_context.enabled', true)) {
            return [];
        }

        $queries = array_filter(array_unique([
            $context->qu->searchQuery,
            $question,
            $context->qu->clarifiedQuestion,
        ]));

        if (!empty($context->qu->searchTags)) {
            $queries[] = implode(' ', $context->qu->searchTags);
        }

        $allFiles = [];
        foreach ($queries as $searchQuery) {
            $result = $this->ai()->retrieveFileContext($searchQuery, $user);
            foreach ($result['relevant_files'] ?? [] as $file) {
                $fileId = $file['file_id'] ?? $file['filename'] ?? '';
                if (!isset($allFiles[$fileId]) || ($file['relevance'] ?? 0) > ($allFiles[$fileId]['relevance'] ?? 0)) {
                    $allFiles[$fileId] = $file;
                }
            }
        }

        if (empty($allFiles)) {
            return [];
        }

        $files = array_values($allFiles);
        usort($files, fn ($a, $b) => ($b['priority_score'] ?? $b['relevance'] ?? 0) <=> ($a['priority_score'] ?? $a['relevance'] ?? 0));

        return [
            'relevant_files' => $files,
            'file_count' => count($files),
            'has_physical' => collect($files)->contains('source', 'physical'),
            'has_database' => collect($files)->contains(fn ($f) => ($f['source'] ?? '') !== 'physical'),
        ];
    }

    /**
     * The AI manager/facade to drive the pipeline with.
     */
    private function ai(): mixed
    {
        return $this->aiFacade ?? AI::getFacadeRoot();
    }
}
