<?php

declare(strict_types=1);

namespace Condoedge\Ai\LlmProviders;

use Condoedge\Ai\Contracts\ToolCallingLlmInterface;
use Condoedge\Ai\DTOs\LlmToolCall;
use Condoedge\Ai\DTOs\LlmToolTurn;
use Condoedge\Ai\Services\Actions\ActionToolSpec;
use Exception;

/**
 * Anthropic LLM Provider for the Claude Messages API.
 *
 * Request shaping and response extraction only; the cURL transport, streaming
 * loop, JSON extraction (the Messages API has no native JSON mode, so model
 * output may be fenced/prose-wrapped), error mapping and resilience live in the
 * shared base. Native tool turns (chatWithTools) reuse the same sendRequest()
 * path, so every loop round rides the shared circuit breaker + transient-only
 * retry; tool results go back as tool_result blocks inside a USER message,
 * Anthropic's required shape.
 *
 * @package Condoedge\Ai\LlmProviders
 */
class AnthropicLlmProvider extends AbstractHttpLlmProvider implements ToolCallingLlmInterface
{
    private const API_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';
    private const MAX_TOKENS = 200000;
    private const CHARS_PER_TOKEN = 3.5;

    public function getProvider(): string
    {
        return 'anthropic';
    }

    protected function displayName(): string
    {
        return 'Anthropic';
    }

    protected function endpoint(): string
    {
        return self::API_ENDPOINT;
    }

    protected function authHeaders(): array
    {
        return [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::API_VERSION,
        ];
    }

    protected function shapeRequest(array $messages, array $options): array
    {
        // Anthropic requires the system prompt in a separate field.
        $systemPrompt = null;
        $chatMessages = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'system') {
                $systemPrompt = $message['content'];
            } else {
                $chatMessages[] = $message;
            }
        }

        if (!empty($chatMessages) && $chatMessages[0]['role'] !== 'user') {
            throw new Exception('Anthropic API requires first message to be user role');
        }

        $request = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? $this->maxResponseTokens,
            'messages' => $chatMessages,
            'temperature' => $options['temperature'] ?? $this->temperature,
        ];

        if ($systemPrompt !== null) {
            $request['system'] = $systemPrompt;
        } elseif (isset($options['system'])) {
            $request['system'] = $options['system'];
        }

        if (isset($options['stream']) && $options['stream']) {
            $request['stream'] = true;
        }

        if (isset($options['tools'])) {
            $request['tools'] = $options['tools'];
        }

        return $request;
    }

    protected function extractTextResponse(array $response): string
    {
        $text = $this->joinTextBlocks($response['content'] ?? []);

        if ($text === null) {
            throw new Exception('Anthropic API response missing content text field');
        }

        return $text;
    }

    public function chatWithTools(array $messages, array $toolSpecs, array $options = []): LlmToolTurn
    {
        if ($toolSpecs !== []) {
            $options['tools'] = array_map(static fn (ActionToolSpec $spec) => [
                'name' => $spec->name,
                'description' => $spec->description,
                'input_schema' => $spec->parameters,
            ], array_values($toolSpecs));
        }

        $response = $this->sendRequest($this->shapeRequest($messages, $options), false);

        return $this->extractToolTurn($response);
    }

    public function formatToolResults(LlmToolTurn $turn, array $results): array
    {
        $blocks = [];

        foreach ($results as $callId => $result) {
            $block = [
                'type' => 'tool_result',
                'tool_use_id' => (string) $callId,
                'content' => is_array($result) ? (string) ($result['content'] ?? '') : (string) $result,
            ];

            if (is_array($result) && !empty($result['is_error'])) {
                $block['is_error'] = true;
            }

            $blocks[] = $block;
        }

        // tool_result blocks must ride a USER message immediately after the assistant
        // tool_use turn; the system-hoist loop in shapeRequest() leaves both untouched.
        return [
            $turn->rawAssistantMessage,
            ['role' => 'user', 'content' => $blocks],
        ];
    }

    private function extractToolTurn(array $response): LlmToolTurn
    {
        $content = (array) ($response['content'] ?? []);

        $toolCalls = [];
        foreach ($content as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            $input = $block['input'] ?? [];

            $toolCalls[] = new LlmToolCall(
                id: (string) ($block['id'] ?? ''),
                name: (string) ($block['name'] ?? ''),
                args: is_array($input) ? $input : [],
                argsError: is_array($input) ? null : 'Tool input is not a JSON object',
            );
        }

        return new LlmToolTurn(
            text: $this->joinTextBlocks($content),
            toolCalls: $toolCalls,
            rawAssistantMessage: ['role' => 'assistant', 'content' => $content],
            stopReason: $this->mapStopReason($response['stop_reason'] ?? null),
        );
    }

    /**
     * Collect every text block in API order — text and tool_use blocks interleave at ANY
     * index, so this must never assume content[0]. Null when no text block exists. A typeless
     * block carrying a 'text' key is treated as text.
     *
     * @param array<int, mixed> $content
     */
    private function joinTextBlocks(array $content): ?string
    {
        $parts = [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? 'text') === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * Normalize to the cross-provider vocabulary LlmToolTurn documents (the OpenAI terms).
     */
    private function mapStopReason(mixed $stopReason): string
    {
        return match ($stopReason) {
            'end_turn', 'stop_sequence' => 'stop',
            'tool_use' => 'tool_calls',
            'max_tokens' => 'length',
            default => (string) ($stopReason ?? ''),
        };
    }

    protected function parseStreamChunk(array $chunk): ?string
    {
        if (($chunk['type'] ?? null) === 'content_block_delta') {
            return $chunk['delta']['text'] ?? null;
        }

        return null;
    }

    protected function supportsNativeJsonMode(): bool
    {
        return false;
    }

    protected function defaultModel(): string
    {
        return self::DEFAULT_MODEL;
    }

    protected function maxTokensLimit(): int
    {
        return self::MAX_TOKENS;
    }

    protected function charsPerToken(): float
    {
        return self::CHARS_PER_TOKEN;
    }
}
