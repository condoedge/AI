<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Extension of LlmProviderInterface for providers that support tool/function calling.
 *
 * Providers implementing this interface can execute multi-turn tool calling loops
 * where the LLM decides which tools to call and processes their results.
 */
interface ToolAwareLlmProviderInterface extends LlmProviderInterface
{
    /**
     * Send a chat message with tool definitions and get a response that may include tool calls.
     *
     * Uses the OpenAI-compatible function calling format.
     * The response may contain tool_calls that need to be executed and fed back.
     *
     * @param array $messages Array of messages (OpenAI format)
     * @param array $tools Array of tool definitions in OpenAI function calling format
     * @param array $options Optional parameters (temperature, max_tokens, etc.)
     * @return array Raw API response including possible tool_calls
     * @throws \Exception If request fails
     */
    public function chatWithTools(array $messages, array $tools, array $options = []): array;

    /**
     * Whether this provider supports tool/function calling.
     *
     * @return bool
     */
    public function supportsTools(): bool;
}
