<?php

declare(strict_types=1);

namespace Condoedge\Ai\LlmProviders;

use Exception;

/**
 * Anthropic LLM Provider for the Claude Messages API.
 *
 * Request shaping and response extraction only; the cURL transport, streaming
 * loop, JSON extraction (the Messages API has no native JSON mode, so model
 * output may be fenced/prose-wrapped), error mapping and resilience live in the
 * shared base.
 *
 * @package Condoedge\Ai\LlmProviders
 */
class AnthropicLlmProvider extends AbstractHttpLlmProvider
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

    /**
     * Collect every text block in API order — text blocks may sit at ANY index, so this
     * must never assume content[0]. Null when no text block exists. A typeless
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
     * Anthropic messages: usage.input_tokens / output_tokens (no total on the wire).
     */
    protected function extractUsage(array $response): ?array
    {
        $usage = $response['usage'] ?? null;
        if (!is_array($usage) || !isset($usage['input_tokens'], $usage['output_tokens'])) {
            return null;
        }

        $input = (int) $usage['input_tokens'];
        $output = (int) $usage['output_tokens'];

        return ['input' => $input, 'output' => $output, 'total' => $input + $output];
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
