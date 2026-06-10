<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\DTOs\LlmToolTurn;

/**
 * Native tool-calling capability for an LLM provider.
 *
 * Deliberately separate from LlmProviderInterface (public API hosts implement — adding methods
 * there is a BC break) and implemented on CONCRETE providers, never on the abstract HTTP base,
 * so `instanceof ToolCallingLlmInterface` IS the entire capability check: a future tool-less
 * provider extends the base without falsely claiming support.
 *
 * Message-history ownership: rawAssistantMessage and formatToolResults() output are
 * provider-shaped and opaque. A tool loop is bound to exactly one provider instance, appends
 * these entries to the history untouched, and only ever passes them back into chatWithTools()
 * on that same instance.
 */
interface ToolCallingLlmInterface
{
    /**
     * One turn: messages + neutral tool specs → typed turn (text and/or tool calls).
     *
     * @param array $messages  Chat messages; may include opaque entries previously returned
     *                         by formatToolResults() on this same instance.
     * @param \Condoedge\Ai\Services\Actions\ActionToolSpec[] $toolSpecs  Empty set attaches no tools.
     * @param array $options   Provider options (temperature, max_tokens, tool_choice, ...).
     */
    public function chatWithTools(array $messages, array $toolSpecs, array $options = []): LlmToolTurn;

    /**
     * Provider-shaped messages to append to the history for the next round: the echo of the
     * assistant turn followed by one result per tool call.
     *
     * @param array<string, string|array{content: string, is_error?: bool}> $results
     *        Keyed by tool call id; the array form flags a result as an error where the
     *        provider's wire format can express that.
     * @return array Messages to append, in order.
     */
    public function formatToolResults(LlmToolTurn $turn, array $results): array;
}
