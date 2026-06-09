<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Services\GraphPropertyMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * VectorPayloadBuilder
 *
 * Single source of truth for building the text-to-embed and the Qdrant payload
 * for a Nodeable entity, and for lazily ensuring the target collection exists.
 *
 * Both the single (DataIngestionService) and batch (BatchDataIngestionService)
 * ingestion paths use this collaborator so that the embedded text and stored
 * payload are byte-identical regardless of entry point, including sensible-column
 * stripping and the owner/security metadata.
 */
class VectorPayloadBuilder
{
    public function __construct(
        private readonly GraphPropertyMapper $propertyMapper = new GraphPropertyMapper(),
    ) {
    }

    /**
     * Build the text to embed for an entity, excluding sensible columns.
     *
     * @param Nodeable $entity Entity being ingested
     * @param array $data Entity data (from Nodeable::toArray())
     * @param VectorConfig $config Vector configuration
     * @return string Concatenated embed text (may be empty)
     */
    public function buildEmbedTextFor(Nodeable $entity, array $data, VectorConfig $config): string
    {
        $sensibleColumns = $this->getSensibleColumns($entity);
        $safeEmbedFields = array_diff($config->embedFields, $sensibleColumns);

        return $this->buildEmbedText($data, $safeEmbedFields);
    }

    /**
     * Build text to embed from entity data and a field list.
     *
     * @param array $data Entity data
     * @param array $fields Field names to concatenate
     * @return string Concatenated text
     */
    public function buildEmbedText(array $data, array $fields): string
    {
        $parts = [];

        foreach ($fields as $field) {
            $value = $data[$field] ?? null;

            if ($value !== null && $value !== '') {
                // Convert arrays and objects to strings
                if (is_array($value)) {
                    $value = implode(' ', array_filter($value, 'is_scalar'));
                } elseif (is_object($value)) {
                    $value = method_exists($value, '__toString')
                        ? (string) $value
                        : json_encode($value);
                }

                $parts[] = $value;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Build the Qdrant payload (metadata) for an entity.
     *
     * Includes the configured metadata fields plus the entity id and
     * security/owner metadata so that single and batch paths agree.
     *
     * @param Nodeable $entity Entity being ingested
     * @param array $data Entity data (from Nodeable::toArray())
     * @param VectorConfig $config Vector configuration
     * @return array Payload metadata
     */
    public function buildPayload(Nodeable $entity, array $data, VectorConfig $config): array
    {
        $metadata = $this->propertyMapper->extractProperties($data, $config->metadata);
        $metadata['id'] = $entity->getId();

        // Security metadata.
        $metadata['_entity_type'] = class_basename($entity);
        $metadata['_entity_class'] = get_class($entity);

        $ownerId = $this->getOwnerId($entity);
        if ($ownerId) {
            $metadata['_owner_id'] = $ownerId;
        }

        return $metadata;
    }

    /**
     * Build a complete Qdrant point (id + vector + payload) for an entity.
     *
     * @param Nodeable $entity Entity being ingested
     * @param array $data Entity data
     * @param VectorConfig $config Vector configuration
     * @param array $vector Embedding vector
     * @return array Point ready for VectorStoreInterface::upsert()
     */
    public function buildPoint(Nodeable $entity, array $data, VectorConfig $config, array $vector): array
    {
        return [
            'id' => $entity->getId(),
            'vector' => $vector,
            'payload' => $this->buildPayload($entity, $data, $config),
        ];
    }

    /**
     * Ensure the target collection exists, creating it lazily if missing.
     *
     * Uses Laravel Cache with a short TTL to avoid memory leaks in
     * long-running processes (Octane, Swoole, queue workers) and to handle
     * external collection changes.
     *
     * @param VectorStoreInterface $vectorStore Vector store
     * @param VectorConfig $config Vector configuration with collection name
     * @return void
     */
    public function ensureCollectionExists(VectorStoreInterface $vectorStore, VectorConfig $config): void
    {
        $collectionName = $config->collection;
        $cacheKey = "ai.vector_collection.exists.{$collectionName}";

        if (Cache::has($cacheKey)) {
            return;
        }

        try {
            if (!$vectorStore->collectionExists($collectionName)) {
                $vectorSize = config('ai.embedding.' . config('ai.embedding.default', 'openai') . '.dimensions', 1536);
                $distance = 'Cosine'; // Standard distance metric

                Log::info("Creating Qdrant collection: {$collectionName}", [
                    'vector_size' => $vectorSize,
                    'distance' => $distance,
                ]);

                $vectorStore->createCollection(
                    name: $collectionName,
                    vectorSize: $vectorSize,
                    distance: $distance
                );

                Log::info("Qdrant collection created successfully: {$collectionName}");
            }

            Cache::put($cacheKey, true, 300);

        } catch (\Throwable $e) {
            Log::error("Failed to ensure collection exists: {$collectionName}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \RuntimeException(
                "Failed to create Qdrant collection '{$collectionName}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Resolve the owner id for an entity, if available.
     *
     * @param Nodeable $entity Entity to inspect
     * @return mixed Owner id or null
     */
    public function getOwnerId(Nodeable $entity): mixed
    {
        if (method_exists($entity, 'getAttribute')) {
            return $entity->getAttribute('user_id') ?? $entity->getAttribute('owner_id');
        }

        return null;
    }

    /**
     * Get sensible columns from an entity (columns to exclude from embedding).
     *
     * @param Nodeable $entity Entity to check
     * @return array List of sensible column names
     */
    public function getSensibleColumns(Nodeable $entity): array
    {
        if (property_exists($entity, 'sensibleColumns')) {
            $reflection = new \ReflectionProperty($entity, 'sensibleColumns');
            $reflection->setAccessible(true);
            return $reflection->getValue($entity) ?? [];
        }

        return [];
    }
}
