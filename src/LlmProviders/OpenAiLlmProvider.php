<?php

declare(strict_types=1);

namespace Condoedge\Ai\LlmProviders;

use Exception;

/**
 * OpenAI LLM Provider for the chat completion API.
 *
 * Request shaping and response extraction only; the cURL transport, streaming
 * loop, JSON extraction, error mapping and resilience live in the shared base.
 *
 * @package Condoedge\Ai\LlmProviders
 */
class OpenAiLlmProvider extends AbstractHttpLlmProvider
{
    private const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const DEFAULT_MODEL = 'gpt-4o';
    private const MAX_TOKENS = 128000;
    private const CHARS_PER_TOKEN = 4;

    public function getProvider(): string
    {
        return 'openai';
    }

    protected function displayName(): string
    {
        return 'OpenAI';
    }

    protected function endpoint(): string
    {
        return self::API_ENDPOINT;
    }

    protected function authHeaders(): array
    {
        return ['Authorization: Bearer ' . $this->apiKey];
    }

    protected function shapeRequest(array $messages, array $options): array
    {
        $request = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? $this->temperature,
            'max_tokens' => $options['max_tokens'] ?? $this->maxResponseTokens,
        ];

        if (isset($options['response_format'])) {
            $request['response_format'] = $options['response_format'];
        }

        if (isset($options['stream'])) {
            $request['stream'] = $options['stream'];
        }

        return $request;
    }

    protected function extractTextResponse(array $response): string
    {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new Exception('OpenAI API response missing content field');
        }

        return $response['choices'][0]['message']['content'];
    }

    protected function parseStreamChunk(array $chunk): ?string
    {
        return $chunk['choices'][0]['delta']['content'] ?? null;
    }

    protected function supportsNativeJsonMode(): bool
    {
        return true;
    }

    protected function jsonModeOptions(): array
    {
        return ['response_format' => ['type' => 'json_object']];
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
