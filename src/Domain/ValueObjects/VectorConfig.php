<?php

namespace AiSystem\Domain\ValueObjects;

/**
 * VectorConfig - Configuration for Qdrant vector storage
 *
 * Defines how an entity's text data should be embedded and stored in Qdrant:
 * - Collection name
 * - Which fields to embed (convert to vectors)
 * - Metadata to store alongside vectors
 */
class VectorConfig
{
    /**
     * @param string $collection Qdrant collection name (e.g., "customers", "people")
     * @param array $embedFields Fields to combine and embed (e.g., ['name', 'description'])
     * @param array $metadata Additional fields to store as searchable metadata (e.g., ['id', 'email'])
     */
    public function __construct(
        public readonly string $collection,
        public readonly array $embedFields,
        public readonly array $metadata = []
    ) {
        if (empty($this->collection)) {
            throw new \InvalidArgumentException('Vector collection name cannot be empty');
        }

        if (empty($this->embedFields)) {
            throw new \InvalidArgumentException('Embed fields cannot be empty');
        }
    }

    /**
     * Create from array configuration
     *
     * @param array $config ['collection' => '...', 'embed_fields' => [...], 'metadata' => [...]]
     * @return self
     */
    public static function fromArray(array $config): self
    {
        return new self(
            collection: $config['collection'],
            embedFields: $config['embed_fields'] ?? $config['embedFields'] ?? [],
            metadata: $config['metadata'] ?? []
        );
    }

    /**
     * Get the separator for combining embed fields
     */
    public function getSeparator(): string
    {
        return ' ';
    }
}
