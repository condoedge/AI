<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Support\AliasNormalizer;

/**
 * SemanticIndexer
 *
 * Builds and maintains the vector store index for semantic entity matching.
 * Indexes entity names, aliases and descriptions with their embeddings
 * (read by SchemaProjector's entity-label fallback).
 *
 * Collection created:
 * - semantic_entities: Entity names, aliases, descriptions
 *
 * @package Condoedge\Ai\Services
 */
class SemanticIndexer
{
    /**
     * Collection name
     */
    private const COLLECTION_ENTITIES = 'semantic_entities';

    /**
     * Create semantic indexer instance
     *
     * @param EmbeddingProviderInterface $embedding Embedding provider
     * @param VectorStoreInterface $vectorStore Vector store
     * @param array $entityConfigs Entity configurations
     */
    public function __construct(
        private readonly EmbeddingProviderInterface $embedding,
        private readonly VectorStoreInterface $vectorStore,
        private array $entityConfigs = []
    ) {
    }

    /**
     * Index all entities with their names, aliases, and descriptions
     *
     * Creates/updates the semantic_entities collection with:
     * - Entity name as primary text
     * - All aliases as separate entries
     * - Description for context
     *
     * @param bool $rebuild If true, delete and recreate collection
     * @return array Indexing summary
     */
    public function indexEntities(bool $rebuild = false): array
    {
        $collection = self::COLLECTION_ENTITIES;

        // Prepare collection
        if ($rebuild) {
            $this->recreateCollection($collection);
        } elseif (!$this->vectorStore->collectionExists($collection)) {
            $this->createCollection($collection);
        }

        // Gather all entity texts to index
        $points = [];
        $pointId = 0;

        foreach ($this->entityConfigs as $entityName => $config) {
            $metadata = $config['metadata'] ?? [];

            // Index entity name
            $points[] = [
                'id' => ++$pointId,
                'text' => $entityName,
                'entity' => $entityName,
                'type' => 'name',
                'description' => $metadata['description'] ?? null,
            ];

            // Index the words for this entity once each: every name it goes by,
            // written as words ("visit detail"), then its real synonyms. Derivable
            // spellings (plural, accent-less, snake_case) are not embedded again.
            $words = array_unique(array_merge(
                array_map([AliasNormalizer::class, 'phrase'], EntityLabelIndex::namesOf((string) $entityName, $config)),
                EntityLabelIndex::synonymsOf((string) $entityName, $config)
            ));
            foreach ($words as $alias) {
                $points[] = [
                    'id' => ++$pointId,
                    'text' => $alias,
                    'entity' => $entityName,
                    'type' => 'alias',
                    'description' => $metadata['description'] ?? null,
                ];
            }

            // Index description as searchable text
            if (!empty($metadata['description'])) {
                $points[] = [
                    'id' => ++$pointId,
                    'text' => $metadata['description'],
                    'entity' => $entityName,
                    'type' => 'description',
                    'description' => $metadata['description'],
                ];
            }
        }

        // Batch insert
        $inserted = $this->indexPoints($collection, $points);

        return [
            'collection' => $collection,
            'total_entities' => count($this->entityConfigs),
            'total_points' => count($points),
            'inserted' => $inserted,
        ];
    }

    /**
     * Index points (texts) into a collection
     *
     * @param string $collection Collection name
     * @param array $points Array of points to index
     * @return int Number of points successfully inserted
     */
    private function indexPoints(string $collection, array $points): int
    {
        if (empty($points)) {
            return 0;
        }

        // Extract texts for batch embedding
        $texts = array_column($points, 'text');

        // Generate embeddings in batch
        $embeddings = $this->embedding->embedBatch($texts);

        // Prepare vector store points
        $vectorPoints = [];
        foreach ($points as $index => $point) {
            $text = $point['text'];
            unset($point['text']); // Don't duplicate in payload

            $vectorPoints[] = [
                'id' => $point['id'],
                'vector' => $embeddings[$index],
                'payload' => array_merge($point, ['text' => $text]), // Keep text in payload
            ];
        }

        // Upsert in batches of 100
        $batchSize = 100;
        $inserted = 0;

        for ($i = 0; $i < count($vectorPoints); $i += $batchSize) {
            $batch = array_slice($vectorPoints, $i, $batchSize);

            if ($this->vectorStore->upsert($collection, $batch)) {
                $inserted += count($batch);
            }
        }

        return $inserted;
    }

    /**
     * Create a new collection
     *
     * @param string $collection Collection name
     * @return bool Success status
     */
    private function createCollection(string $collection): bool
    {
        $vectorSize = $this->embedding->getDimensions();

        return $this->vectorStore->createCollection(
            name: $collection,
            vectorSize: $vectorSize,
            distance: 'cosine'
        );
    }

    /**
     * Delete and recreate a collection
     *
     * @param string $collection Collection name
     * @return bool Success status
     */
    private function recreateCollection(string $collection): bool
    {
        if ($this->vectorStore->collectionExists($collection)) {
            $this->vectorStore->deleteCollection($collection);
        }

        return $this->createCollection($collection);
    }

    /**
     * Get collection names
     *
     * @return array Array of collection names
     */
    public static function getCollectionNames(): array
    {
        return [
            'entities' => self::COLLECTION_ENTITIES,
        ];
    }

    /**
     * Check if the collection exists
     *
     * @return array Status of each collection
     */
    public function checkCollections(): array
    {
        return [
            'entities' => $this->vectorStore->collectionExists(self::COLLECTION_ENTITIES),
        ];
    }

    /**
     * Set entity configurations
     *
     * @param array $entityConfigs Entity configurations
     * @return void
     */
    public function setEntityConfigs(array $entityConfigs): void
    {
        $this->entityConfigs = $entityConfigs;
    }
}
