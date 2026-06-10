<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

/**
 * The outcome of one ToolLoopRunner run: the closing prose, the schema-filtered invocations
 * to persist in the assistant message metadata (re-dispatched fresh at every render — never
 * tokens, never results), and the telemetry the round-cap tuning rides on.
 */
final class ToolLoopResult
{
    /**
     * @param ActionInvocation[] $invocations
     * @param array{channel: string, rounds: int, tool_calls: int, degraded: bool} $telemetry
     *        rounds = completed LLM turns (including a forced close); tool_calls = tool calls
     *        received from the model; degraded = the answer was composed deterministically
     *        after a mid-loop failure instead of by the model.
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $invocations,
        public readonly array $telemetry,
    ) {
    }
}
