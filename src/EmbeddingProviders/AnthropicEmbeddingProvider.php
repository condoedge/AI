<?php

declare(strict_types=1);

namespace AiSystem\EmbeddingProviders;

use AiSystem\Contracts\EmbeddingProviderInterface;
use RuntimeException;

/**
 * Anthropic Embedding Provider (Placeholder)
 *
 * This is a placeholder implementation for future Anthropic embeddings support.
 * Anthropic does not currently offer a public embeddings API, but this class
 * is provided to maintain consistency with the provider architecture.
 *
 * When Anthropic releases an embeddings API, this class can be updated with
 * the actual implementation following the same patterns as OpenAiEmbeddingProvider.
 *
 * @see https://docs.anthropic.com/claude/docs (for future updates)
 */
class AnthropicEmbeddingProvider implements EmbeddingProviderInterface
{
    /**
     * Model identifier
     */
    private string $model;

    /**
     * Vector dimensionality (hypothetical)
     */
    private int $dimensions;

    /**
     * Maximum text length (based on Claude context window)
     */
    private int $maxLength;

    /**
     * Create a new Anthropic embedding provider
     *
     * @param array $config Configuration array with keys:
     *                      - api_key: Anthropic API key (stored for future use)
     *                      - model: Model name (default: claude-3-5-sonnet-20241022)
     *                      - dimensions: Vector dimensions (default: 1024)
     * @throws RuntimeException If required configuration is missing
     */
    public function __construct(array $config)
    {
        $this->validateConfig($config);

        // Store config for future implementation
        // Currently not used since API doesn't exist yet
        $this->model = $config['model'] ?? 'claude-3-5-sonnet-20241022';
        $this->dimensions = $config['dimensions'] ?? 1024;
        $this->maxLength = 200000; // Claude's context window
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
            throw new RuntimeException('Anthropic API key is required');
        }

        if (!is_string($config['api_key'])) {
            throw new RuntimeException('Anthropic API key must be a string');
        }
    }

    /**
     * Generate embedding for a single text
     *
     * @param string $text Text to embed
     * @return array Vector representation (array of floats)
     * @throws RuntimeException Always throws - not yet supported
     */
    public function embed(string $text): array
    {
        throw new RuntimeException(
            'Anthropic embeddings not yet supported. Use OpenAI or another provider.'
        );
    }

    /**
     * Generate embeddings for multiple texts (batch operation)
     *
     * @param array $texts Array of texts to embed
     * @return array Array of vectors, indexed same as input texts
     * @throws RuntimeException Always throws - not yet supported
     */
    public function embedBatch(array $texts): array
    {
        throw new RuntimeException(
            'Anthropic embeddings not yet supported. Use OpenAI or another provider.'
        );
    }

    /**
     * Get the dimensionality of the embeddings
     *
     * Returns the hypothetical dimensions that would be used if/when
     * Anthropic releases an embeddings API.
     *
     * @return int Vector size (hypothetical: 1024)
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Get the model name being used
     *
     * Returns the model identifier that would be used if/when
     * Anthropic releases an embeddings API.
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
     * Based on Claude's context window of 200,000 tokens.
     * This is hypothetical until Anthropic releases embeddings.
     *
     * @return int Maximum characters (200,000)
     */
    public function getMaxLength(): int
    {
        return $this->maxLength;
    }
}
