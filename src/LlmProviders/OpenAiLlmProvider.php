<?php

declare(strict_types=1);

namespace Condoedge\Ai\LlmProviders;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Exception;

/**
 * OpenAI LLM Provider
 *
 * Independent implementation for OpenAI's chat completion API.
 * Uses GPT-4 and later models with 128K context window.
 *
 * @package Condoedge\Ai\LlmProviders
 */
class OpenAiLlmProvider implements LlmProviderInterface
{
    /**
     * OpenAI API endpoint
     */
    private const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * Default model
     */
    private const DEFAULT_MODEL = 'gpt-4o';

    /**
     * Maximum context window tokens
     */
    private const MAX_TOKENS = 128000;

    /**
     * Token estimation ratio (characters per token)
     */
    private const CHARS_PER_TOKEN = 4;

    /**
     * API key for authentication
     */
    private string $apiKey;

    /**
     * Model identifier
     */
    private string $model;

    /**
     * Default temperature
     */
    private float $temperature;

    /**
     * Default max tokens for responses
     */
    private int $maxResponseTokens;

    /**
     * Constructor
     *
     * @param array $config Configuration array from config('ai.llm.openai')
     *                      Expected keys: api_key, model, temperature, max_tokens
     * @throws Exception If required configuration is missing
     */
    public function __construct(array $config)
    {
        // Validate required configuration
        if (empty($config['api_key'])) {
            throw new Exception('OpenAI API key is required');
        }

        $this->apiKey = $config['api_key'];
        $this->model = $config['model'] ?? self::DEFAULT_MODEL;
        $this->temperature = $config['temperature'] ?? 0.3;
        $this->maxResponseTokens = $config['max_tokens'] ?? 2000;
    }

    /**
     * Send a chat message and get a response
     *
     * @param array $messages Array of messages: [['role' => 'user'|'system'|'assistant', 'content' => '...']]
     * @param array $options Optional parameters (temperature, max_tokens, etc.)
     * @return string Response text
     * @throws Exception If request fails
     */
    public function chat(array $messages, array $options = []): string
    {
        $requestData = $this->buildChatRequest($messages, $options);
        $response = $this->sendRequest($requestData);

        return $this->extractTextResponse($response);
    }

    /**
     * Send a chat message and get a JSON response
     *
     * @param array $messages Array of messages
     * @param array $options Optional parameters
     * @return object|array Decoded JSON response
     * @throws Exception If request fails or response is not valid JSON
     */
    public function chatJson(array $messages, array $options = []): object|array
    {
        // Ensure JSON response format is requested
        $options['response_format'] = ['type' => 'json_object'];

        // Add JSON instruction to system message
        $messages = $this->ensureJsonSystemMessage($messages);

        $requestData = $this->buildChatRequest($messages, $options);
        $response = $this->sendRequest($requestData);

        $text = $this->extractTextResponse($response);

        // Validate and decode JSON
        $decoded = json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('OpenAI response is not valid JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Send a simple prompt and get a response
     *
     * @param string $prompt User prompt
     * @param string|null $systemPrompt Optional system message
     * @param array $options Optional parameters
     * @return string Response text
     * @throws Exception If request fails
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
     * Stream a chat response (for real-time UI)
     *
     * @param array $messages Array of messages
     * @param callable $callback Function to call with each chunk
     * @param array $options Optional parameters
     * @return void
     * @throws Exception If request fails
     */
    public function stream(array $messages, callable $callback, array $options = []): void
    {
        $options['stream'] = true;
        $requestData = $this->buildChatRequest($messages, $options);

        $this->sendStreamingRequest($requestData, $callback);
    }

    /**
     * Get the model name being used
     *
     * @return string Model identifier
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the provider name
     *
     * @return string Provider name
     */
    public function getProvider(): string
    {
        return 'openai';
    }

    /**
     * Get the maximum context length (tokens)
     *
     * @return int Maximum tokens
     */
    public function getMaxTokens(): int
    {
        return self::MAX_TOKENS;
    }

    /**
     * Count tokens in a text (approximate)
     *
     * @param string $text Text to count
     * @return int Estimated token count
     */
    public function countTokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Build chat completion request payload
     *
     * @param array $messages Messages array
     * @param array $options Additional options
     * @return array Request payload
     */
    private function buildChatRequest(array $messages, array $options): array
    {
        $request = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? $this->temperature,
            'max_tokens' => $options['max_tokens'] ?? $this->maxResponseTokens,
        ];

        // Add response format if specified
        if (isset($options['response_format'])) {
            $request['response_format'] = $options['response_format'];
        }

        // Add streaming flag if specified
        if (isset($options['stream'])) {
            $request['stream'] = $options['stream'];
        }

        return $request;
    }

    /**
     * Send HTTP request to OpenAI API
     *
     * @param array $requestData Request payload
     * @return array Decoded response
     * @throws Exception If request fails
     */
    private function sendRequest(array $requestData): array
    {
        $ch = curl_init(self::API_ENDPOINT);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('OpenAI API request failed: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('OpenAI API returned invalid JSON: ' . json_last_error_msg());
        }

        if ($httpCode !== 200) {
            $this->handleErrorResponse($httpCode, $decoded);
        }

        return $decoded;
    }

    /**
     * Send streaming HTTP request to OpenAI API
     *
     * @param array $requestData Request payload
     * @param callable $callback Callback for each chunk
     * @return void
     * @throws Exception If request fails
     */
    private function sendStreamingRequest(array $requestData, callable $callback): void
    {
        $ch = curl_init(self::API_ENDPOINT);

        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => function ($curl, $data) use ($callback, &$buffer) {
                $buffer .= $data;
                $lines = explode("\n", $buffer);

                // Keep the last incomplete line in buffer
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
            throw new Exception('OpenAI streaming request failed with HTTP ' . $httpCode . ': ' . $curlError);
        }
    }

    /**
     * Extract text response from API response
     *
     * @param array $response API response
     * @return string Response text
     * @throws Exception If response format is invalid
     */
    private function extractTextResponse(array $response): string
    {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new Exception('OpenAI API response missing content field');
        }

        return $response['choices'][0]['message']['content'];
    }

    /**
     * Ensure JSON instruction is in system message
     *
     * @param array $messages Messages array
     * @return array Modified messages
     */
    private function ensureJsonSystemMessage(array $messages): array
    {
        $jsonInstruction = 'Respond with valid JSON only.';

        // Check if there's already a system message
        foreach ($messages as &$message) {
            if ($message['role'] === 'system') {
                // Append JSON instruction if not already present
                if (strpos($message['content'], $jsonInstruction) === false) {
                    $message['content'] .= ' ' . $jsonInstruction;
                }
                return $messages;
            }
        }

        // No system message found, add one at the beginning
        array_unshift($messages, [
            'role' => 'system',
            'content' => $jsonInstruction,
        ]);

        return $messages;
    }

    /**
     * Handle error responses from OpenAI API
     *
     * @param int $httpCode HTTP status code
     * @param array $response Response data
     * @throws Exception Always throws with appropriate error message
     */
    private function handleErrorResponse(int $httpCode, array $response): void
    {
        $errorMessage = $response['error']['message'] ?? 'Unknown error';

        switch ($httpCode) {
            case 401:
                throw new Exception('OpenAI API authentication failed: Invalid API key');
            case 429:
                throw new Exception('OpenAI API rate limit exceeded. Please try again later.');
            case 400:
                throw new Exception('OpenAI API bad request: ' . $errorMessage);
            case 500:
            case 503:
                throw new Exception('OpenAI API server error: ' . $errorMessage);
            default:
                throw new Exception('OpenAI API request failed (HTTP ' . $httpCode . '): ' . $errorMessage);
        }
    }
}
