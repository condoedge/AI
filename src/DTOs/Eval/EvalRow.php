<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Eval;

/**
 * The full per-question result of one pipeline walk.
 *
 * Produced by {@see \Condoedge\Ai\Services\Eval\PipelineEvalDriver}. It carries
 * cheap deterministic pipeline signals (used by the attribution ruleset even
 * when the LLM judge is offline), the generated answer, the judge verdict and
 * the final per-stage scorecard + blame.
 *
 * Stages graded: understanding | retrieval | prompt | generation.
 */
class EvalRow
{
    public function __construct(
        /** The (label-free) question that was asked. */
        public string $question = '',
        /** @var string[] Tags attached to the question (entity/scope/file/example...). */
        public array $tags = [],

        // ---- Cheap pipeline signals (always available, even offline) ----
        /** @var string[] Entity labels selected by retrieval. */
        public array $selectedEntities = [],
        /** @var array<int, mixed> Relevant files found by file search. */
        public array $relevantFiles = [],
        /** The Cypher generated (null/empty when none). */
        public ?string $cypher = null,
        /** Rows returned by query execution. */
        public int $rowCount = 0,
        /** Small sample of execution rows (for the judge's grounding check). @var array<int, mixed> */
        public array $dataSample = [],
        /** Context-quality score normalised 0-1 (from PipelineContext->quality). */
        public float $contextQuality = 0.0,
        /** @var string[] Entity types the QU step predicted. */
        public array $quEntityTypes = [],
        /** QU question type: data_query|knowledge_query|hybrid. */
        public string $questionType = 'hybrid',
        /** The final natural-language answer (empty when gated/failed). */
        public string $answer = '',

        // ---- Verdict + outcome ----
        /** LLM-judge verdict (may be skipped). */
        public ?JudgeVerdict $verdict = null,
        /**
         * Per-stage scores, each 0-1, keyed:
         * understanding|retrieval|prompt|generation.
         * @var array<string, float>
         */
        public array $stageScores = [],
        /** The single stage blamed as weakest, or null when the row passed. */
        public ?string $weakestStage = null,
        /** Human-readable blame reason. */
        public string $blameReason = '',
        /**
         * Terminal verdict for the row:
         * passed | context-not-retrieved | stage-error | judge-skipped | failed.
         */
        public string $verdictLabel = 'passed',
        /** Which stage threw (when verdictLabel = stage-error). */
        public ?string $erroredStage = null,
        /** Error message when a stage threw. */
        public string $error = '',
        /** Per-stage timing in ms. @var array<string, int> */
        public array $timings = [],
    ) {}

    /**
     * True when retrieval + file search returned nothing actionable.
     * This is the GENERATION GATE condition: empty selected_entities AND
     * empty relevant_files AND no cypher → never worth spending judge tokens.
     */
    public function contextIsEmpty(): bool
    {
        return empty($this->selectedEntities)
            && empty($this->relevantFiles)
            && empty($this->cypher);
    }

    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'tags' => $this->tags,
            'signals' => [
                'selected_entities' => $this->selectedEntities,
                'relevant_files' => count($this->relevantFiles),
                'cypher' => $this->cypher,
                'row_count' => $this->rowCount,
                'context_quality' => $this->contextQuality,
                'qu_entity_types' => $this->quEntityTypes,
                'question_type' => $this->questionType,
            ],
            'answer' => $this->answer,
            'verdict' => $this->verdict?->toArray(),
            'stage_scores' => $this->stageScores,
            'weakest_stage' => $this->weakestStage,
            'blame_reason' => $this->blameReason,
            'verdict_label' => $this->verdictLabel,
            'errored_stage' => $this->erroredStage,
            'error' => $this->error,
            'timings' => $this->timings,
        ];
    }
}
