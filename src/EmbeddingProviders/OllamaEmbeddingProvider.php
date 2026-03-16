<?php

declare(strict_types=1);

namespace Condoedge\Ai\EmbeddingProviders;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use RuntimeException;

/**
 * Ollama Embedding Provider — 100% local embeddings.
 *
 * Uses Ollama's native embedding API with models like nomic-embed-text.
 */
class OllamaEmbeddingProvider implements EmbeddingProviderInterface
{
    private const REQUEST_TIMEOUT = 60;

    private string $baseUrl;
    private string $model;
    private int $dimensions;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'http://localhost:11434', '/');
        $this->model = $config['model'] ?? 'nomic-embed-text';
        $this->dimensions = (int) ($config['dimensions'] ?? 768);
    }

    /**
     * {@inheritdoc}
     */
    public function embed(string $text): array
    {
        if (empty($text)) {
            throw new RuntimeException('Text cannot be empty');
        }

        $response = $this->makeApiRequest(['input' => $text]);

        // Ollama native embed API returns embeddings directly
        if (isset($response['embeddings'][0])) {
            return $response['embeddings'][0];
        }

        // OpenAI-compatible format fallback
        if (isset($response['data'][0]['embedding'])) {
            return $response['data'][0]['embedding'];
        }

        throw new RuntimeException('Invalid Ollama embedding response: missing embedding data');
    }

    /**
     * {@inheritdoc}
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            throw new RuntimeException('Texts array cannot be empty');
        }

        foreach ($texts as $index => $text) {
            if (!is_string($text) || empty($text)) {
                throw new RuntimeException("Text at index {$index} must be a non-empty string");
            }
        }

        $response = $this->makeApiRequest(['input' => $texts]);

        // Ollama native format
        if (isset($response['embeddings']) && is_array($response['embeddings'])) {
            return $response['embeddings'];
        }

        // OpenAI-compatible format fallback
        if (isset($response['data']) && is_array($response['data'])) {
            usort($response['data'], fn($a, $b) => $a['index'] <=> $b['index']);

            return array_map(
                fn($item) => $item['embedding'] ?? throw new RuntimeException('Missing embedding in response'),
                $response['data']
            );
        }

        throw new RuntimeException('Invalid Ollama batch embedding response');
    }

    /**
     * {@inheritdoc}
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
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
    public function getMaxLength(): int
    {
        // nomic-embed-text supports 8192 tokens
        return 8192;
    }

    private function makeApiRequest(array $payload): array
    {
        $payload['model'] = $this->model;

        // Use Ollama native embed endpoint
        $endpoint = $this->baseUrl . '/api/embed';
        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            if (strpos($curlError, 'Connection refused') !== false || strpos($curlError, 'Failed to connect') !== false) {
                throw new RuntimeException('Ollama is not running. Start it with: ollama serve');
            }
            throw new RuntimeException("Ollama embedding request failed: {$curlError}");
        }

        if ($httpCode !== 200) {
            $this->handleApiError($response, $httpCode);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode Ollama response: ' . json_last_error_msg());
        }

        return $decoded;
    }

    private function handleApiError(string $response, int $httpCode): void
    {
        $decoded = json_decode($response, true);
        $errorMessage = $decoded['error'] ?? $response;

        if (is_array($errorMessage)) {
            $errorMessage = json_encode($errorMessage);
        }

        if (stripos($errorMessage, 'not found') !== false) {
            throw new RuntimeException(
                "Embedding model '{$this->model}' not found. Pull it with: ollama pull {$this->model}"
            );
        }

        if ($httpCode === 400) {
            throw new RuntimeException("Ollama embedding bad request: {$errorMessage}");
        }

        throw new RuntimeException("Ollama embedding error (HTTP {$httpCode}): {$errorMessage}");
    }
}
