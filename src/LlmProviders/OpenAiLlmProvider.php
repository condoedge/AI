<?php

declare(strict_types=1);

namespace Condoedge\Ai\LlmProviders;

use Condoedge\Ai\Contracts\ToolCallingLlmInterface;
use Condoedge\Ai\DTOs\LlmToolCall;
use Condoedge\Ai\DTOs\LlmToolTurn;
use Condoedge\Ai\Services\Actions\ActionToolSpec;
use Exception;

/**
 * OpenAI LLM Provider for the chat completion API.
 *
 * Request shaping and response extraction only; the cURL transport, streaming
 * loop, JSON extraction, error mapping and resilience live in the shared base.
 * Native tool turns (chatWithTools) reuse the same sendRequest() path, so every
 * loop round rides the shared circuit breaker + transient-only retry.
 *
 * @package Condoedge\Ai\LlmProviders
 */
class OpenAiLlmProvider extends AbstractHttpLlmProvider implements ToolCallingLlmInterface
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

        if (isset($options['tools'])) {
            $request['tools'] = $options['tools'];
        }

        if (isset($options['tool_choice'])) {
            $request['tool_choice'] = $options['tool_choice'];
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

    public function chatWithTools(array $messages, array $toolSpecs, array $options = []): LlmToolTurn
    {
        if ($toolSpecs !== []) {
            $options['tools'] = array_map(static fn (ActionToolSpec $spec) => [
                'type' => 'function',
                'function' => [
                    'name' => $spec->name,
                    'description' => $spec->description,
                    'parameters' => $spec->parameters,
                ],
            ], array_values($toolSpecs));
        }

        $response = $this->sendRequest($this->shapeRequest($messages, $options), false);

        return $this->extractToolTurn($response);
    }

    public function formatToolResults(LlmToolTurn $turn, array $results): array
    {
        $messages = [$turn->rawAssistantMessage];

        foreach ($results as $callId => $result) {
            // OpenAI has no error flag on role:tool messages — error-ness rides in the content.
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => (string) $callId,
                'content' => is_array($result) ? (string) ($result['content'] ?? '') : (string) $result,
            ];
        }

        return $messages;
    }

    private function extractToolTurn(array $response): LlmToolTurn
    {
        $message = $response['choices'][0]['message'] ?? [];

        $toolCalls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $rawCall) {
            if (!is_array($rawCall) || ($rawCall['type'] ?? 'function') !== 'function') {
                continue;
            }

            [$args, $argsError] = $this->decodeToolArguments($rawCall['function']['arguments'] ?? '');

            $toolCalls[] = new LlmToolCall(
                id: (string) ($rawCall['id'] ?? ''),
                name: (string) ($rawCall['function']['name'] ?? ''),
                args: $args,
                argsError: $argsError,
            );
        }

        // A tool-calls-only response carries content:null — expected here, unlike
        // extractTextResponse(), which rightly rejects it for plain chat.
        $content = $message['content'] ?? null;

        return new LlmToolTurn(
            text: is_string($content) ? $content : null,
            toolCalls: $toolCalls,
            rawAssistantMessage: $message,
            stopReason: (string) ($response['choices'][0]['finish_reason'] ?? ''),
        );
    }

    /**
     * The wire `arguments` field is a JSON STRING; an undecodable one must become argsError —
     * a corrective tool_result for the model — never a thrown turn.
     *
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function decodeToolArguments(mixed $raw): array
    {
        if (is_array($raw)) {
            return [$raw, null];
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [[], null];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [[], 'Tool arguments are not valid JSON: ' . json_last_error_msg()];
        }

        // A decoded LIST ([1,2]) is valid JSON but not an args object; [] alone decodes
        // ambiguously (empty object and empty list are the same) and means no args.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return [[], 'Tool arguments must be a JSON object'];
        }

        return [$decoded, null];
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
