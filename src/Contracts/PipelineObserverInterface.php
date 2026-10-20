<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * The single observation seam of the PRODUCTION answer pipeline
 * (AiManager::answerQuestion and the services it drives).
 *
 * Observation only: an observer must never change pipeline behavior, and the
 * pipeline never reads anything back from it. Implementations must be cheap
 * and must not throw — the default binding is {@see \Condoedge\Ai\Support\NullPipelineObserver}.
 *
 * Stage vocabulary (emitted by the package):
 *   understanding, file_context, query_generation, execution, knowledge_query,
 *   file_listing, page_narration, digest, clarify, export, composition,
 *   search_probes (prompt, usage and recovered errors of
 *   the document-search probe call made inside file_context; its outcome and
 *   wall time ride the file_context stage data), clarity_check (the focused
 *   "is this question a fork?" call: prompt, usage, recovered errors and a
 *   stage with its outcome — emitted ONLY on the turns where the call is made);
 *   the chat use case adds security_gate, conversation_context and pipeline.
 *
 * Every turn that produces an answer ends with a `composition` stage whose data
 * carries the `branch` that owned the turn.
 */
interface PipelineObserverInterface
{
    /**
     * A stage began. $data is optional, small, and never a deep copy of large arrays.
     */
    public function stageStarted(string $stage, array $data = []): void;

    /**
     * A stage ended (successfully or not — a failed stage carries 'failed' => true).
     *
     * @param float $ms Wall time of the stage in milliseconds.
     */
    public function stageFinished(string $stage, array $data = [], float $ms = 0.0): void;

    /**
     * The VERBATIM chat messages a stage is about to send to an LLM
     * ([['role' => ..., 'content' => ...], ...]). Emitted once per LLM call.
     */
    public function promptBuilt(string $stage, array $messages): void;

    /**
     * A stage raised (or recorded) an error. Emitted whether or not the
     * pipeline recovered from it.
     */
    public function stageError(string $stage, \Throwable $e): void;

    /**
     * Token usage of ONE LLM call made by a stage, normalized as
     * ['input' => int, 'output' => int, 'total' => int, 'model' => string].
     */
    public function usage(string $stage, array $usage): void;
}
