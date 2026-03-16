<?php

declare(strict_types=1);

namespace Condoedge\Ai\LlmProviders;

use Condoedge\Ai\Contracts\ToolAwareLlmProviderInterface;
use Exception;

/**
 * Ollama LLM Provider — 100% local, no internet dependency.
 *
 * Uses Ollama's OpenAI-compatible API for chat completions and tool calling.
 * Requires Ollama running locally (default: http://localhost:11434).
 */
class OllamaLlmProvider implements ToolAwareLlmProviderInterface
{
    private const DEFAULT_MODEL = 'llama3.1:8b';
    private const DEFAULT_MAX_TOKENS = 128000;
    private const CHARS_PER_TOKEN = 4;

    private string $baseUrl;
    private string $model;
    private float $temperature;
    private int $maxResponseTokens;
    private int $contextWindow;
    private int $timeout;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'http://localhost:11434', '/');
        $this->model = $config['model'] ?? self::DEFAULT_MODEL;
        $this->temperature = (float) ($config['temperature'] ?? 0.3);
        $this->maxResponseTokens = (int) ($config['max_tokens'] ?? 4096);
        $this->contextWindow = (int) ($config['context_window'] ?? self::DEFAULT_MAX_TOKENS);
        $this->timeout = (int) ($config['timeout'] ?? 180);
    }

    /**
     * {@inheritdoc}
     */
    public function chat(array $messages, array $options = []): string
    {
        $requestData = $this->buildChatRequest($messages, $options);
        $response = $this->sendRequest($requestData);

        return $this->extractTextResponse($response);
    }

    /**
     * {@inheritdoc}
     */
    public function chatJson(array $messages, array $options = []): object|array
    {
        $options['response_format'] = ['type' => 'json_object'];
        $messages = $this->ensureJsonSystemMessage($messages);

        $requestData = $this->buildChatRequest($messages, $options);
        $response = $this->sendRequest($requestData);

        $text = $this->extractTextResponse($response);

        $decoded = json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ollama response is not valid JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * {@inheritdoc}
     */
    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        $messages = [];

        if ($systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $this->chat($messages, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function stream(array $messages, callable $callback, array $options = []): void
    {
        $options['stream'] = true;
        $requestData = $this->buildChatRequest($messages, $options);

        $this->sendStreamingRequest($requestData, $callback);
    }

    /**
     * {@inheritdoc}
     */
    public function chatWithTools(array $messages, array $tools, array $options = []): array
    {
        $requestData = $this->buildChatRequest($messages, $options);
        $requestData['tools'] = $tools;

        return $this->sendRequest($requestData);
    }

    /**
     * {@inheritdoc}
     */
    public function supportsTools(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * {@inheritdoc}
     */
    public function getProvider(): string
    {
        return 'ollama';
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxTokens(): int
    {
        return $this->contextWindow;
    }

    /**
     * {@inheritdoc}
     */
    public function countTokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Check if Ollama is reachable.
     */
    public function isReachable(): bool
    {
        try {
            $ch = curl_init($this->baseUrl . '/api/tags');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $response !== false && $httpCode === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * List available models on the Ollama instance.
     */
    public function listModels(): array
    {
        $ch = curl_init($this->baseUrl . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return [];
        }

        $decoded = json_decode($response, true);

        return array_column($decoded['models'] ?? [], 'name');
    }

    private function buildChatRequest(array $messages, array $options): array
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

    private function sendRequest(array $requestData): array
    {
        $endpoint = $this->baseUrl . '/v1/chat/completions';
        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            if (strpos($curlError, 'Connection refused') !== false || strpos($curlError, 'Failed to connect') !== false) {
                throw new Exception(
                    'Ollama is not running. Start it with: ollama serve'
                );
            }
            throw new Exception('Ollama API request failed: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ollama API returned invalid JSON: ' . json_last_error_msg());
        }

        if ($httpCode !== 200) {
            $this->handleErrorResponse($httpCode, $decoded);
        }

        return $decoded;
    }

    private function sendStreamingRequest(array $requestData, callable $callback): void
    {
        $endpoint = $this->baseUrl . '/v1/chat/completions';
        $ch = curl_init($endpoint);

        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function ($curl, $data) use ($callback, &$buffer) {
                $buffer .= $data;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '' || $line === 'data: [DONE]') {
                        continue;
                    }

                    if (strpos($line, 'data: ') === 0) {
                        $json = substr($line, 6);
                        $chunk = json_decode($json, true);

                        if (isset($chunk['choices'][0]['delta']['content'])) {
                            $callback($chunk['choices'][0]['delta']['content']);
                        }
                    }
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Ollama streaming request failed with HTTP ' . $httpCode . ': ' . $curlError);
        }
    }

    private function extractTextResponse(array $response): string
    {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new Exception('Ollama API response missing content field');
        }

        return $response['choices'][0]['message']['content'];
    }

    private function ensureJsonSystemMessage(array $messages): array
    {
        $jsonInstruction = 'Respond with valid JSON only.';

        foreach ($messages as &$message) {
            if ($message['role'] === 'system') {
                if (strpos($message['content'], $jsonInstruction) === false) {
                    $message['content'] .= ' ' . $jsonInstruction;
                }
                return $messages;
            }
        }

        array_unshift($messages, [
            'role' => 'system',
            'content' => $jsonInstruction,
        ]);

        return $messages;
    }

    private function handleErrorResponse(int $httpCode, array $response): void
    {
        $errorMessage = $response['error']['message'] ?? $response['error'] ?? 'Unknown error';

        if (is_array($errorMessage)) {
            $errorMessage = json_encode($errorMessage);
        }

        // Ollama-specific: model not found
        if (stripos($errorMessage, 'not found') !== false || stripos($errorMessage, 'no such model') !== false) {
            throw new Exception(
                "Model '{$this->model}' not found in Ollama. Pull it with: ollama pull {$this->model}"
            );
        }

        switch ($httpCode) {
            case 400:
                throw new Exception('Ollama bad request: ' . $errorMessage);
            case 404:
                throw new Exception("Ollama model not found: {$this->model}. Pull it with: ollama pull {$this->model}");
            case 500:
            case 503:
                throw new Exception('Ollama server error: ' . $errorMessage);
            default:
                throw new Exception('Ollama API request failed (HTTP ' . $httpCode . '): ' . $errorMessage);
        }
    }
}
