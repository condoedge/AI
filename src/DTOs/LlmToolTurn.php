<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

/**
 * One assistant turn from a tool-capable provider: prose and/or native tool calls.
 *
 * $rawAssistantMessage is the provider-shaped assistant message to echo back verbatim on the
 * next round (ToolCallingLlmInterface::formatToolResults()). It is opaque above the provider:
 * only ever valid when passed back into the SAME provider instance that produced it, never
 * inspected or rewritten by callers.
 *
 * $stopReason is normalized to the OpenAI vocabulary — 'stop', 'tool_calls', 'length' —
 * with anything unrecognized passed through as received.
 */
final class LlmToolTurn
{
    /**
     * @param LlmToolCall[] $toolCalls
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $toolCalls,
        public readonly mixed $rawAssistantMessage,
        public readonly string $stopReason,
    ) {
    }
}
