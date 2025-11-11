<?php

namespace Condoedge\Ai\Contracts;

/**
 * LlmProviderInterface
 *
 * Abstraction for Large Language Model providers (OpenAI, Anthropic, etc.)
 * Handles text generation, chat completions, and structured outputs.
 */
interface LlmProviderInterface
{
    /**
     * Send a chat message and get a response
     *
     * @param array $messages Array of messages: [['role' => 'user'|'system'|'assistant', 'content' => '...']]
     * @param array $options Optional parameters (temperature, max_tokens, etc.)
     * @return string Response text
     * @throws \Exception If request fails
     */
    public function chat(array $messages, array $options = []): string;

    /**
     * Send a chat message and get a JSON response
     *
     * Useful for structured outputs like query generation
     *
     * @param array $messages Array of messages
     * @param array $options Optional parameters
     * @return object|array Decoded JSON response
     * @throws \Exception If request fails or response is not valid JSON
     */
    public function chatJson(array $messages, array $options = []): object|array;

    /**
     * Send a simple prompt and get a response
     *
     * Convenience method for single-turn conversations
     *
     * @param string $prompt User prompt
     * @param string|null $systemPrompt Optional system message
     * @param array $options Optional parameters
     * @return string Response text
     * @throws \Exception If request fails
     */
    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string;

    /**
     * Stream a chat response (for real-time UI)
     *
     * @param array $messages Array of messages
     * @param callable $callback Function to call with each chunk
     * @param array $options Optional parameters
     * @return void
     * @throws \Exception If request fails
     */
    public function stream(array $messages, callable $callback, array $options = []): void;

    /**
     * Get the model name being used
     *
     * @return string Model identifier
     */
    public function getModel(): string;

    /**
     * Get the provider name
     *
     * @return string Provider name (e.g., 'openai', 'anthropic')
     */
    public function getProvider(): string;

    /**
     * Get the maximum context length (tokens)
     *
     * @return int Maximum tokens
     */
    public function getMaxTokens(): int;

    /**
     * Count tokens in a text (approximate)
     *
     * @param string $text Text to count
     * @return int Estimated token count
     */
    public function countTokens(string $text): int;
}
