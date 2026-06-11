<?php

declare(strict_types=1);

namespace Condoedge\Ai\EmbeddingProviders;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use RuntimeException;

/**
 * OpenAI Embedding Provider
 *
 * Implements text embedding generation using OpenAI's Embeddings API.
 * Supports both single and batch embedding operations for efficient processing.
 *
 * @see https://platform.openai.com/docs/guides/embeddings
 */
class OpenAiEmbeddingProvider implements EmbeddingProviderInterface
{
    /**
     * OpenAI API endpoint for embeddings
     */
    private const API_ENDPOINT = 'https://api.openai.com/v1/embeddings';

    /**
     * Default timeout for API requests (in seconds)
     */
    private const REQUEST_TIMEOUT = 30;

    /**
     * API Key for authentication
     */
    private string $apiKey;

    /**
     * Model identifier (e.g., 'text-embedding-3-small')
     */
    private string $model;

    /**
     * Vector dimensionality
     */
    private int $dimensions;

    /**
     * Total attempts (1 try + retries) for a transient embedding failure.
     */
    private int $maxAttempts;

    /**
     * Base backoff delay in milliseconds (doubled per retry).
     */
    private int $retryBaseDelayMs;

    /**
     * Create a new OpenAI embedding provider
     *
     * @param array $config Configuration array with keys:
     *                      - api_key: OpenAI API key (required)
     *                      - model: Model name (default: text-embedding-3-small)
     *                      - dimensions: Vector dimensions (default: 1536)
     *                      - max_retries: Total attempts on transient failures (default: 3)
     *                      - retry_base_delay_ms: Base backoff in ms, doubled per retry (default: 200)
     * @throws RuntimeException If required configuration is missing
     */
    public function __construct(array $config)
    {
        $this->validateConfig($config);

        $this->apiKey = $config['api_key'];
        $this->model = $config['model'] ?? 'text-embedding-3-small';
        $this->dimensions = $config['dimensions'] ?? 1536;
        $this->maxAttempts = max(1, (int) ($config['max_retries'] ?? 3));
        $this->retryBaseDelayMs = max(0, (int) ($config['retry_base_delay_ms'] ?? 200));
    }

    /**
     * Validate configuration array
     *
     * @param array $config Configuration to validate
     * @throws RuntimeException If validation fails
     */
    private function validateConfig(array $config): void
    {
        if (empty($config['api_key'])) {
            throw new RuntimeException('OpenAI API key is required');
        }

        if (!is_string($config['api_key'])) {
            throw new RuntimeException('OpenAI API key must be a string');
        }
    }

    /**
     * Generate embedding for a single text
     *
     * @param string $text Text to embed
     * @return array Vector representation (array of floats)
     * @throws RuntimeException If embedding generation fails
     */
    public function embed(string $text): array
    {
        if (empty($text)) {
            throw new RuntimeException('Text cannot be empty');
        }

        $response = $this->makeApiRequest(['input' => $text]);

        if (!isset($response['data'][0]['embedding'])) {
            throw new RuntimeException('Invalid API response: missing embedding data');
        }

        return $response['data'][0]['embedding'];
    }

    /**
     * Generate embeddings for multiple texts (batch operation)
     *
     * More efficient than calling embed() multiple times as it uses a single API call.
     *
     * @param array $texts Array of texts to embed
     * @return array Array of vectors, indexed same as input texts
     * @throws RuntimeException If embedding generation fails
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            throw new RuntimeException('Texts array cannot be empty');
        }

        foreach ($texts as $index => $text) {
            if (!is_string($text)) {
                throw new RuntimeException("Text at index {$index} must be a string");
            }
            if (empty($text)) {
                throw new RuntimeException("Text at index {$index} cannot be empty");
            }
        }

        $avgLength = array_sum(array_map('strlen', $texts)) / count($texts);
        $batchSize = $avgLength > 500 ? 50 : 200;
        $chunks = array_chunk($texts, $batchSize);
        $allEmbeddings = [];

        foreach ($chunks as $chunk) {
            $response = $this->makeApiRequest(['input' => array_values($chunk)]);

            if (!isset($response['data']) || !is_array($response['data'])) {
                throw new RuntimeException('Invalid API response: missing data array');
            }

            // Sort by index to match input order within this chunk
            usort($response['data'], fn($a, $b) => $a['index'] <=> $b['index']);

            foreach ($response['data'] as $item) {
                if (!isset($item['embedding'])) {
                    throw new RuntimeException('Invalid API response: missing embedding in data item');
                }
                $allEmbeddings[] = $item['embedding'];
            }
        }

        return $allEmbeddings;
    }

    /**
     * Get the dimensionality of the embeddings
     *
     * @return int Vector size
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
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
     * Get the maximum text length this provider can handle
     *
     * For text-embedding-3-small, the limit is 8191 tokens.
     * We return this as a conservative character estimate.
     *
     * @return int Maximum characters
     */
    public function getMaxLength(): int
    {
        return 8191;
    }

    /**
     * Make an API request to OpenAI's embeddings endpoint
     *
     * @param array $payload Request payload
     * @return array Decoded JSON response
     * @throws RuntimeException If request fails
     */
    private function makeApiRequest(array $payload): array
    {
        $payload['model'] = $this->model;

        $jsonBody = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($jsonBody === false) {
            throw new RuntimeException(
                'Failed to encode embedding payload as JSON: ' . json_last_error_msg()
            );
        }

        // The embedding endpoint gates ALL retrieval (vector search, semantic selection,
        // entity labels, file grounding). A single transient SSL/network blip or 429
        // would otherwise silently drop documents from the answer, so retry transient
        // failures with exponential backoff — the same resilience the chat path has.
        // Permanent errors (bad key, malformed input) are surfaced immediately.
        $attempt = 0;
        while (true) {
            $attempt++;

            ['code' => $httpCode, 'body' => $response, 'error' => $curlError] = $this->executeCurl($jsonBody);

            // Transport-level failure (curl returned false): transient — retry if possible.
            if ($response === false) {
                if ($attempt < $this->maxAttempts) {
                    $this->backoff($attempt);
                    continue;
                }
                throw new RuntimeException("Network error: {$curlError}");
            }

            if ($httpCode === 200) {
                $decoded = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Failed to decode API response: ' . json_last_error_msg());
                }
                return $decoded;
            }

            // Throttling / server-side transient errors: retry with backoff.
            if (in_array($httpCode, [429, 500, 502, 503, 504], true) && $attempt < $this->maxAttempts) {
                $this->backoff($attempt);
                continue;
            }

            // Permanent error, or retries exhausted: surface it (always throws).
            $this->handleApiError($response, $httpCode);
        }
    }

    /**
     * Perform the raw HTTP POST to the embeddings endpoint.
     *
     * Isolated as a seam so the transport can be substituted in tests (the retry/error
     * policy in makeApiRequest is then unit-testable without hitting the network).
     *
     * @return array{code:int, body:string|false, error:string}
     */
    protected function executeCurl(string $jsonBody): array
    {
        $ch = curl_init(self::API_ENDPOINT);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => $response, 'error' => $curlError];
    }

    /**
     * Sleep with exponential backoff before the next retry attempt.
     */
    private function backoff(int $attempt): void
    {
        if ($this->retryBaseDelayMs <= 0) {
            return;
        }

        $delayMs = min($this->retryBaseDelayMs * (2 ** ($attempt - 1)), 5000);
        usleep($delayMs * 1000);
    }

    /**
     * Handle API error responses
     *
     * @param string $response Raw response body
     * @param int $httpCode HTTP status code
     * @throws RuntimeException Always throws with appropriate error message
     */
    private function handleApiError(string $response, int $httpCode): void
    {
        $errorMessage = $response;
        $decoded = json_decode($response, true);
        if (isset($decoded['error']['message'])) {
            $errorMessage = $decoded['error']['message'];
        }

        if ($httpCode === 401) {
            throw new RuntimeException("Invalid API key: {$errorMessage}");
        }

        if ($httpCode === 429) {
            throw new RuntimeException("Rate limit exceeded: {$errorMessage}");
        }

        if ($httpCode === 400) {
            throw new RuntimeException("Bad request: {$errorMessage}");
        }

        if ($httpCode === 500) {
            throw new RuntimeException("OpenAI server error: {$errorMessage}");
        }

        if ($httpCode === 503) {
            throw new RuntimeException("OpenAI service unavailable: {$errorMessage}");
        }

        throw new RuntimeException("API error (HTTP {$httpCode}): {$errorMessage}");
    }
}
