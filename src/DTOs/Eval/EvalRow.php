<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Eval;

/**
 * The full per-question result of one pipeline walk.
 *
 * Produced by {@see \Condoedge\Ai\Services\Eval\PipelineEvalDriver}. It carries
 * cheap deterministic pipeline signals (used by the attribution ruleset), the
 * generated answer, and the final per-stage scorecard + blame. Answer grading
 * is done out-of-process by an agent, not by an in-process LLM.
 *
 * Stages graded: understanding | retrieval | prompt | generation.
 *
 * TRACE: the row additionally carries a per-turn trace (conversation identity,
 * the verbatim grading standard, QU output, retrieved chunks, the exact LLM
 * prompts when capture was requested, gates) so an out-of-process agent can
 * grade the turn from the JSON report alone, WITHOUT re-running the pipeline.
 */
class EvalRow
{
    // ---- Conversation / trace fields (multi-turn suites) ----

    /** Suite conversation id this row belongs to (null for flat single questions). */
    public ?string $conversationId = null;

    /** 0-based position of this turn within its conversation. */
    public int $turnIndex = 0;

    /** The role the suite declared for the conversation (e.g. "manager"). */
    public ?string $role = null;

    /** VERBATIM grading standard from the suite — the out-of-process rubric. */
    public ?string $standard = null;

    /**
     * The question the pipeline was ACTUALLY run with after conversation-context
     * enrichment (reference resolution etc.). $question always carries the suite's
     * verbatim ask so the grader can audit the enrichment itself; null when the
     * turn ran un-enriched (flat mode / enrichment produced no change).
     */
    public ?string $enrichedQuestion = null;

    /** Raw expect map from the suite (already validated by the loader). @var array<string, mixed> */
    public array $expect = [];

    /** Per-gate results: [{gate, expected, actual, passed, detail}]. @var array<int, array<string, mixed>> */
    public array $gates = [];

    /** QU output snapshot (entity types, intent, search query...). @var array<string, mixed> */
    public array $understanding = [];

    /** Retrieved chunk citations: [{id, score, snippet}]. @var array<int, array<string, mixed>> */
    public array $retrievedChunks = [];

    /**
     * VERBATIM LLM prompts, keyed query_generation / response_generation, each
     * {system, user}. Populated ONLY when capture_prompts was requested — never
     * on production payloads. Captured on FAILED stages too (the generators
     * attach them to their failure exceptions). HONEST LIMITATION: on the native
     * tool-calling path the response_generation pair is the INITIAL exchange only
     * (marked channel=native_tool_loop); tool-loop rounds are summarized in
     * response_metadata.tool_calling / invocations, not captured verbatim.
     * @var array<string, array<string, string>>
     */
    public array $prompts = [];

    /**
     * Non-fatal pipeline errors that degraded the turn without stopping it
     * (e.g. a swallowed file-search failure), so an empty retrieved_chunks is
     * distinguishable from "no relevant documents existed".
     * @var string[]
     */
    public array $contextErrors = [];

    /** File citations the FINAL response carried. @var array<int, mixed> */
    public array $referencedFiles = [];

    /** Response-level metadata (export markers etc.) from the final composition. @var array<string, mixed> */
    public array $responseMetadata = [];

    /**
     * Evidence of what conversation context this turn RECEIVED (e.g.
     * last_result_sample_count) — the previous_result_available gate's input.
     * @var array<string, mixed>
     */
    public array $conversationContextMeta = [];

    /**
     * The pinned eval user's email — the no_self_contact gate's identity input
     * (structural: literal case-insensitive presence in the answer). Null when
     * the run has no pinned user. The suite's meta.pinned_user carries the same
     * value for graders; this field is what the in-process gate reads.
     */
    public ?string $pinnedUserEmail = null;

    /** Wall-clock duration of the whole turn in ms. */
    public int $totalDurationMs = 0;

    /**
     * TRANSIENT: full execution rows, carried only so the runner can feed the
     * PRODUCTION conversation write-back (ConversationContextManager::recordResponse
     * receives the full data exactly like AskQuestionUseCase passes it). NEVER
     * serialized — the report only ever carries $dataSample.
     * @var array<int, mixed>
     */
    public array $executionData = [];

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
        /** Small sample of execution rows (deterministic context signal). @var array<int, mixed> */
        public array $dataSample = [],
        /** Context-quality score normalised 0-1 (from PipelineContext->quality). */
        public float $contextQuality = 0.0,
        /** @var string[] Entity types the QU step predicted. */
        public array $quEntityTypes = [],
        /** QU question type: data_query|knowledge_query|hybrid. */
        public string $questionType = 'hybrid',
        /** The final natural-language answer (empty when gated/failed). */
        public string $answer = '',

        // ---- Outcome ----
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
         * Terminal outcome label for the row:
         * passed | context-not-retrieved | stage-error | failed | gate-failed.
         * (gate-failed = a deterministic fact-gate from the suite's expect block
         * failed, regardless of how the stages scored.)
         */
        public string $verdictLabel = 'passed',
        /** Which ATTRIBUTION stage is blamed for the throw (when verdictLabel = stage-error). */
        public ?string $erroredStage = null,
        /**
         * The fine-grained pipeline step that actually threw:
         * context_retrieval | query_generation | query_execution |
         * response_composition | conversation_start | driver. Distinguishes e.g.
         * a Neo4j execution failure from a vector-retrieval failure, both of
         * which attribute to the 'retrieval' stage.
         */
        public ?string $erroredStep = null,
        /** Error message when a stage threw. */
        public string $error = '',
        /** Per-stage timing in ms. @var array<string, int> */
        public array $timings = [],
    ) {}

    /**
     * True when retrieval + file search returned nothing actionable.
     * This is the GENERATION GATE condition: empty selected_entities AND
     * empty relevant_files AND no cypher → never worth generating a response.
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
            'stage_scores' => $this->stageScores,
            'weakest_stage' => $this->weakestStage,
            'blame_reason' => $this->blameReason,
            'verdict_label' => $this->verdictLabel,
            'errored_stage' => $this->erroredStage,
            'errored_step' => $this->erroredStep,
            'error' => $this->error,
            'timings' => $this->timings,
            'gates' => $this->gates,
            // Everything an out-of-process grading agent needs to grade this turn
            // WITHOUT re-running it. $executionData is deliberately absent (transient);
            // data_sample is its serialized evidence (first 10 rows) so answers can be
            // checked against what the pipeline actually retrieved.
            'trace' => [
                'conversation_id' => $this->conversationId,
                'turn_index' => $this->turnIndex,
                'role' => $this->role,
                'standard' => $this->standard,
                'expect' => $this->expect,
                'enriched_question' => $this->enrichedQuestion,
                'understanding' => $this->understanding,
                'retrieved_chunks' => $this->retrievedChunks,
                'context_errors' => $this->contextErrors,
                'cypher' => $this->cypher,
                'data_sample' => $this->dataSample,
                'prompts' => $this->prompts,
                'answer' => $this->answer,
                'referenced_files' => $this->referencedFiles,
                'response_metadata' => $this->responseMetadata,
                'conversation_context_received' => $this->conversationContextMeta,
                'pinned_user_email' => $this->pinnedUserEmail,
                'timings_ms' => $this->timings,
                'total_duration_ms' => $this->totalDurationMs,
            ],
        ];
    }

    /**
     * True when any evaluated fact-gate failed. Structural check only — the
     * gates themselves never inspect meaning.
     */
    public function hasGateFailure(): bool
    {
        foreach ($this->gates as $gate) {
            if (($gate['passed'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }
}
