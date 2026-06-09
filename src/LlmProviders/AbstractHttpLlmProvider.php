<?php

declare(strict_types=1);

namespace Condoedge\Ai\LlmProviders;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\Resilience\CircuitBreaker;
use Condoedge\Ai\Services\Resilience\RetryPolicy;
use Exception;

/**
 * Shared HTTP base for LLM providers.
 *
 * Owns the wire-level concerns that every HTTP LLM provider needs:
 *   - cURL request / SSE streaming loop
 *   - JSON-instruction injection into the system message
 *   - extraction of JSON from fenced / prose-wrapped model output
 *   - HTTP error mapping (transient vs permanent)
 *   - resilience: a circuit breaker + retry policy that retries ONLY transient
 *     HTTP failures (429 / 5xx / network), leaving permanent errors to surface
 *     immediately.
 *
 * Concrete providers supply only the provider-specific request shaping,
 * response extraction, endpoint and auth headers via the abstract hooks.
 */
abstract class AbstractHttpLlmProvider implements LlmProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected float $temperature;
    protected int $maxResponseTokens;

    private ?CircuitBreaker $circuitBreaker = null;
    private ?RetryPolicy $retryPolicy = null;

    /**
     * @param array $config Provider config (api_key, model, temperature, max_tokens)
     * @throws Exception If the API key is missing
     */
    public function __construct(array $config)
    {
        if (empty($config['api_key'])) {
            throw new Exception($this->displayName() . ' API key is required');
        }

        $this->apiKey = $config['api_key'];
        $this->model = $config['model'] ?? $this->defaultModel();
        $this->temperature = (float) ($config['temperature'] ?? 0.3);
        $this->maxResponseTokens = (int) ($config['max_tokens'] ?? 2000);
    }

    // =========================================================================
    // Public LLM contract (shared)
    // =========================================================================

    public function chat(array $messages, array $options = []): string
    {
        $response = $this->sendRequest($this->shapeRequest($messages, $options), false);

        return $this->extractTextResponse($response);
    }

    public function chatJson(array $messages, array $options = []): object|array
    {
        $messages = $this->ensureJsonSystemMessage($messages);

        if ($this->supportsNativeJsonMode()) {
            $options = array_merge($options, $this->jsonModeOptions());
        }

        $response = $this->sendRequest($this->shapeRequest($messages, $options), false);
        $text = $this->extractTextResponse($response);

        // Models routinely wrap JSON in ```json fences or a prose preface even
        // when asked not to; isolate the JSON payload before decoding.
        $json = $this->extractJsonFromText($text);

        $decoded = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(
                $this->displayName() . ' response is not valid JSON: ' . json_last_error_msg()
            );
        }

        return $decoded;
    }

    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        $messages = [];

        if ($systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $this->chat($messages, $options);
    }

    public function stream(array $messages, callable $callback, array $options = []): void
    {
        $options['stream'] = true;
        $this->sendStreamingRequest($this->shapeRequest($messages, $options), $callback);
    }

    public function getMaxTokens(): int
    {
        return $this->maxTokensLimit();
    }

    public function countTokens(string $text): int
    {
        return (int) ceil(strlen($text) / $this->charsPerToken());
    }

    public function getModel(): string
    {
        return $this->model;
    }

    // =========================================================================
    // HTTP + resilience (shared)
    // =========================================================================

    /**
     * Send a non-streaming request, wrapped in the circuit breaker + retry
     * policy. Only transient failures are retried.
     *
     * @throws Exception
     */
    protected function sendRequest(array $requestData, bool $streaming): array
    {
        return $this->withResilience(fn () => $this->performRequest($requestData, $streaming));
    }

    /**
     * Perform a single (non-resilient) HTTP request and decode the response.
     *
     * @throws TransientLlmException On retryable HTTP / network failures
     * @throws Exception            On permanent failures
     */
    private function performRequest(array $requestData, bool $streaming): array
    {
        $ch = curl_init($this->endpoint());

        $headers = array_merge(['Content-Type: application/json'], $this->authHeaders());
        if ($streaming) {
            $headers[] = 'Accept: text/event-stream';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->requestTimeoutSeconds(),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            // Network-level failure (DNS, connection reset, timeout) is transient.
            throw new TransientLlmException(
                $this->displayName() . ' API request failed: ' . $curlError
            );
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(
                $this->displayName() . ' API returned invalid JSON: ' . json_last_error_msg()
            );
        }

        if ($httpCode !== 200) {
            $this->raiseHttpError($httpCode, $decoded);
        }

        return $decoded;
    }

    /**
     * Send a streaming request. The streaming body itself is not retried (chunks
     * may already have been delivered), but the connection is still guarded by
     * the circuit breaker and a transient HTTP status surfaces the captured body.
     *
     * @throws Exception
     */
    protected function sendStreamingRequest(array $requestData, callable $callback): void
    {
        $this->withResilience(function () use ($requestData, $callback) {
            $this->performStreamingRequest($requestData, $callback);
            return true;
        });
    }

    private function performStreamingRequest(array $requestData, callable $callback): void
    {
        $ch = curl_init($this->endpoint());

        $buffer = '';
        $body = '';

        $headers = array_merge(
            ['Content-Type: application/json', 'Accept: text/event-stream'],
            $this->authHeaders()
        );

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->streamingTimeoutSeconds(),
            CURLOPT_WRITEFUNCTION => function ($curl, $data) use ($callback, &$buffer, &$body) {
                // Capture the full body so a non-200 response is not opaque.
                $body .= $data;

                $buffer .= $data;
                $lines = explode("\n", $buffer);

                // Keep the last (possibly incomplete) line in the buffer.
                $buffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '' || strpos($line, 'event:') === 0 || $line === 'data: [DONE]') {
                        continue;
                    }

                    if (strpos($line, 'data: ') === 0) {
                        $chunk = json_decode(substr($line, 6), true);

                        if (is_array($chunk)) {
                            $text = $this->parseStreamChunk($chunk);
                            if ($text !== null && $text !== '') {
                                $callback($text);
                            }
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

        if ($httpCode === 0) {
            throw new TransientLlmException(
                $this->displayName() . ' streaming request failed: ' . $curlError
            );
        }

        if ($httpCode !== 200) {
            // Reuse the standard error mapping against the captured body.
            $decoded = json_decode($body, true);
            $this->raiseHttpError($httpCode, is_array($decoded) ? $decoded : []);
        }
    }

    /**
     * Run an operation through the circuit breaker + retry policy.
     *
     * @throws Exception
     */
    private function withResilience(callable $operation): mixed
    {
        return $this->breaker()->call(function () use ($operation) {
            return $this->retry()->execute($operation);
        });
    }

    private function breaker(): CircuitBreaker
    {
        if ($this->circuitBreaker === null) {
            $cfg = $this->resilienceConfig();
            $this->circuitBreaker = new CircuitBreaker(
                name: 'llm',
                failureThreshold: (int) ($cfg['circuit_breaker']['failure_threshold'] ?? 5),
                recoveryTimeoutSeconds: (int) ($cfg['circuit_breaker']['recovery_timeout_seconds'] ?? 30),
                successThreshold: (int) ($cfg['circuit_breaker']['success_threshold'] ?? 2)
            );
        }

        return $this->circuitBreaker;
    }

    private function retry(): RetryPolicy
    {
        if ($this->retryPolicy === null) {
            $cfg = $this->resilienceConfig();

            // Defaults mirror RetryPolicy::forNetworkRequests() but retry ONLY
            // transient failures so permanent errors (400/401/...) bubble up.
            $this->retryPolicy = new RetryPolicy(
                maxAttempts: (int) ($cfg['retry']['max_attempts'] ?? 3),
                baseDelayMs: (int) ($cfg['retry']['base_delay_ms'] ?? 500),
                maxDelayMs: (int) ($cfg['retry']['max_delay_ms'] ?? 10000),
                jitter: (float) ($cfg['retry']['jitter'] ?? 0.3),
                retryableExceptions: [TransientLlmException::class]
            );
        }

        return $this->retryPolicy;
    }

    private function resilienceConfig(): array
    {
        if (function_exists('app') && app()->bound('config')) {
            return (array) config('ai.resilience.llm', []);
        }

        return [];
    }

    // =========================================================================
    // Error mapping (shared)
    // =========================================================================

    /**
     * Throw the correct exception type for a non-200 response. Transient
     * statuses (429 / 5xx) become TransientLlmException (retryable); everything
     * else becomes a permanent Exception via handleErrorResponse().
     *
     * @throws Exception
     */
    private function raiseHttpError(int $httpCode, array $decoded): void
    {
        if ($this->isTransientStatus($httpCode)) {
            $message = $decoded['error']['message'] ?? 'transient error';
            throw new TransientLlmException(
                $this->displayName() . " API transient failure (HTTP {$httpCode}): {$message}"
            );
        }

        $this->handleErrorResponse($httpCode, $decoded);
    }

    /**
     * Whether an HTTP status is transient and therefore safe to retry.
     */
    protected function isTransientStatus(int $httpCode): bool
    {
        return $httpCode === 429 || $httpCode >= 500;
    }

    /**
     * Map a permanent (non-transient) error response to an Exception.
     *
     * @throws Exception Always throws.
     */
    protected function handleErrorResponse(int $httpCode, array $response): void
    {
        $errorMessage = $response['error']['message'] ?? 'Unknown error';
        $provider = $this->displayName();

        switch ($httpCode) {
            case 401:
                throw new Exception($provider . ' API authentication failed: Invalid API key');
            case 400:
                throw new Exception($provider . ' API bad request: ' . $errorMessage);
            default:
                throw new Exception(
                    $provider . ' API request failed (HTTP ' . $httpCode . '): ' . $errorMessage
                );
        }
    }

    // =========================================================================
    // JSON helpers (shared)
    // =========================================================================

    /**
     * Append a "respond with JSON only" instruction to (or create) the system
     * message so providers without a native JSON mode still get JSON.
     */
    protected function ensureJsonSystemMessage(array $messages): array
    {
        $jsonInstruction = 'Respond with valid JSON only.';

        foreach ($messages as &$message) {
            if (($message['role'] ?? null) === 'system') {
                if (strpos($message['content'], $jsonInstruction) === false) {
                    $message['content'] .= ' ' . $jsonInstruction;
                }
                return $messages;
            }
        }
        unset($message);

        array_unshift($messages, [
            'role' => 'system',
            'content' => $jsonInstruction,
        ]);

        return $messages;
    }

    /**
     * Isolate the JSON payload from model output. Strips ```json / ``` fences and,
     * failing that, extracts the first balanced {...} or [...] block (string- and
     * escape-aware). Returns the original trimmed text if nothing better is found,
     * so the caller's json_decode error message stays meaningful.
     */
    protected function extractJsonFromText(string $text): string
    {
        $trimmed = trim($text);

        // 1) Fenced code block: ```json ... ``` or ``` ... ```
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }

        // 2) Already a bare JSON document.
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $balanced = $this->firstBalancedBlock($trimmed);
            if ($balanced !== null) {
                return $balanced;
            }
            return $trimmed;
        }

        // 3) Prose-wrapped: find the first balanced block starting at { or [.
        $balanced = $this->firstBalancedBlock($trimmed);
        if ($balanced !== null) {
            return $balanced;
        }

        return $trimmed;
    }

    /**
     * Return the first balanced {...} or [...] block in $text, accounting for
     * braces/brackets that appear inside JSON strings. Null if none is found.
     */
    private function firstBalancedBlock(string $text): ?string
    {
        $length = strlen($text);
        $start = null;
        $open = null;
        $close = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '{' || $char === '[') {
                $start = $i;
                $open = $char;
                $close = $char === '{' ? '}' : ']';
                break;
            }
        }

        if ($start === null) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    // =========================================================================
    // Tunable defaults (overridable)
    // =========================================================================

    protected function requestTimeoutSeconds(): int
    {
        return 60;
    }

    protected function streamingTimeoutSeconds(): int
    {
        return 120;
    }

    /**
     * Human-facing provider label used in error messages (e.g. "OpenAI").
     * Defaults to the machine provider name; concrete providers may override
     * for proper capitalization.
     */
    protected function displayName(): string
    {
        return $this->getProvider();
    }

    /**
     * Options merged into chatJson when the provider has a native JSON mode.
     */
    protected function jsonModeOptions(): array
    {
        return [];
    }

    // =========================================================================
    // Provider-specific hooks
    // =========================================================================

    abstract protected function endpoint(): string;

    /**
     * @return array<int,string> Provider-specific HTTP headers (auth, version).
     */
    abstract protected function authHeaders(): array;

    /**
     * Build the provider-specific request payload from messages + options.
     */
    abstract protected function shapeRequest(array $messages, array $options): array;

    /**
     * Pull the assistant text out of a decoded non-streaming response.
     *
     * @throws Exception If the response shape is invalid.
     */
    abstract protected function extractTextResponse(array $response): string;

    /**
     * Extract the text delta from a single decoded SSE chunk, or null.
     */
    abstract protected function parseStreamChunk(array $chunk): ?string;

    /**
     * Whether the provider has a first-class JSON response mode.
     */
    abstract protected function supportsNativeJsonMode(): bool;

    abstract protected function defaultModel(): string;

    abstract protected function maxTokensLimit(): int;

    abstract protected function charsPerToken(): float;
}
