<?php

namespace Condoedge\Ai\Contracts;

/**
 * EmbeddingProviderInterface
 *
 * Abstraction for text embedding services (OpenAI, Anthropic, Cohere, local models, etc.)
 * Converts text into vector representations for semantic search.
 */
interface EmbeddingProviderInterface
{
    /**
     * Generate embedding for a single text
     *
     * @param string $text Text to embed
     * @return array Vector representation (array of floats)
     * @throws \Exception If embedding generation fails
     */
    public function embed(string $text): array;

    /**
     * Generate embeddings for multiple texts (batch operation)
     *
     * More efficient than calling embed() multiple times
     *
     * @param array $texts Array of texts to embed
     * @return array Array of vectors, indexed same as input texts
     * @throws \Exception If embedding generation fails
     */
    public function embedBatch(array $texts): array;

    /**
     * Get the dimensionality of the embeddings
     *
     * @return int Vector size (e.g., 1536 for OpenAI text-embedding-3-small)
     */
    public function getDimensions(): int;

    /**
     * Get the model name being used
     *
     * @return string Model identifier
     */
    public function getModel(): string;

    /**
     * Get the maximum text length this provider can handle
     *
     * @return int Maximum characters/tokens
     */
    public function getMaxLength(): int;
}
