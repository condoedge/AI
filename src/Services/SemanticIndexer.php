<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Illuminate\Support\Facades\Log;

/**
 * SemanticIndexer
 *
 * Builds and maintains vector store indexes for semantic matching.
 * Indexes entity names, scopes, and templates with their embeddings
 * to enable fast semantic search.
 *
 * Collections Created:
 * - semantic_entities: Entity names, aliases, descriptions
 * - semantic_scopes: Scope names, descriptions, concepts
 * - semantic_templates: Query template descriptions
 *
 * Usage:
 * ```php
 * $indexer = new SemanticIndexer($embedding, $vectorStore, $entityConfigs);
 *
 * // Index everything
 * $indexer->rebuildIndexes();
 *
 * // Index specific type
 * $indexer->indexEntities();
 * $indexer->indexScopes();
 * $indexer->indexTemplates($templates);
 * ```
 *
 * @package Condoedge\Ai\Services
 */
class SemanticIndexer
{
    /**
     * Collection names
     */
    private const COLLECTION_ENTITIES = 'semantic_entities';
    private const COLLECTION_SCOPES = 'semantic_scopes';
    private const COLLECTION_TEMPLATES = 'semantic_templates';

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
     * Rebuild all semantic indexes
     *
     * This will:
     * 1. Delete existing collections
     * 2. Create new collections
     * 3. Index all entities, scopes, and templates
     *
     * @param array|null $templates Optional query templates to index
     * @return array Summary of indexing operations
     */
    public function rebuildIndexes(?array $templates = null): array
    {
        $results = [
            'entities' => null,
            'scopes' => null,
            'templates' => null,
            'errors' => [],
        ];

        try {
            // Index entities
            $results['entities'] = $this->indexEntities(rebuild: true);
        } catch (\Exception $e) {
            $results['errors'][] = "Entities indexing failed: {$e->getMessage()}";
            Log::error('Failed to index entities', ['error' => $e->getMessage()]);
        }

        try {
            // Index scopes
            $results['scopes'] = $this->indexScopes(rebuild: true);
        } catch (\Exception $e) {
            $results['errors'][] = "Scopes indexing failed: {$e->getMessage()}";
            Log::error('Failed to index scopes', ['error' => $e->getMessage()]);
        }

        if ($templates) {
            try {
                // Index templates
                $results['templates'] = $this->indexTemplates($templates, rebuild: true);
            } catch (\Exception $e) {
                $results['errors'][] = "Templates indexing failed: {$e->getMessage()}";
                Log::error('Failed to index templates', ['error' => $e->getMessage()]);
            }
        }

        return $results;
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

            // Index aliases
            if (!empty($metadata['aliases'])) {
                foreach ($metadata['aliases'] as $alias) {
                    $points[] = [
                        'id' => ++$pointId,
                        'text' => $alias,
                        'entity' => $entityName,
                        'type' => 'alias',
                        'description' => $metadata['description'] ?? null,
                    ];
                }
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
     * Index all scopes with their names, descriptions, and concepts
     *
     * Creates/updates the semantic_scopes collection with scope information
     * from all entities.
     *
     * @param bool $rebuild If true, delete and recreate collection
     * @return array Indexing summary
     */
    public function indexScopes(bool $rebuild = false): array
    {
        $collection = self::COLLECTION_SCOPES;

        // Prepare collection
        if ($rebuild) {
            $this->recreateCollection($collection);
        } elseif (!$this->vectorStore->collectionExists($collection)) {
            $this->createCollection($collection);
        }

        // Gather all scope texts to index
        $points = [];
        $pointId = 0;
        $scopeCount = 0;

        foreach ($this->entityConfigs as $entityName => $config) {
            $metadata = $config['metadata'] ?? [];

            if (empty($metadata['scopes'])) {
                continue;
            }

            foreach ($metadata['scopes'] as $scopeName => $scopeConfig) {
                $scopeCount++;

                // Index scope name
                $points[] = [
                    'id' => ++$pointId,
                    'text' => $scopeName,
                    'entity' => $entityName,
                    'scope' => $scopeName,
                    'type' => 'name',
                    'description' => $scopeConfig['description'] ?? null,
                    'concept' => $scopeConfig['concept'] ?? null,
                ];

                // Index description
                if (!empty($scopeConfig['description'])) {
                    $points[] = [
                        'id' => ++$pointId,
                        'text' => $scopeConfig['description'],
                        'entity' => $entityName,
                        'scope' => $scopeName,
                        'type' => 'description',
                        'description' => $scopeConfig['description'],
                        'concept' => $scopeConfig['concept'] ?? null,
                    ];
                }

                // Index concept
                if (!empty($scopeConfig['concept'])) {
                    $points[] = [
                        'id' => ++$pointId,
                        'text' => $scopeConfig['concept'],
                        'entity' => $entityName,
                        'scope' => $scopeName,
                        'type' => 'concept',
                        'description' => $scopeConfig['description'] ?? null,
                        'concept' => $scopeConfig['concept'],
                    ];
                }
            }
        }

        // Batch insert
        $inserted = $this->indexPoints($collection, $points);

        return [
            'collection' => $collection,
            'total_scopes' => $scopeCount,
            'total_points' => count($points),
            'inserted' => $inserted,
        ];
    }

    /**
     * Index query templates with their descriptions and patterns
     *
     * Creates/updates the semantic_templates collection with template information.
     *
     * @param array $templates Query templates configuration
     * @param bool $rebuild If true, delete and recreate collection
     * @return array Indexing summary
     */
    public function indexTemplates(array $templates, bool $rebuild = false): array
    {
        $collection = self::COLLECTION_TEMPLATES;

        // Prepare collection
        if ($rebuild) {
            $this->recreateCollection($collection);
        } elseif (!$this->vectorStore->collectionExists($collection)) {
            $this->createCollection($collection);
        }

        // Gather template texts to index
        $points = [];
        $pointId = 0;

        foreach ($templates as $templateName => $templateConfig) {
            // Index template description (primary search text)
            if (!empty($templateConfig['description'])) {
                $points[] = [
                    'id' => ++$pointId,
                    'text' => $templateConfig['description'],
                    'template' => $templateName,
                    'type' => 'description',
                    'pattern' => $templateConfig['pattern'] ?? null,
                    'cypher' => $templateConfig['cypher'] ?? null,
                ];
            }

            // Index example queries if available
            if (!empty($templateConfig['examples'])) {
                foreach ($templateConfig['examples'] as $example) {
                    $points[] = [
                        'id' => ++$pointId,
                        'text' => $example,
                        'template' => $templateName,
                        'type' => 'example',
                        'pattern' => $templateConfig['pattern'] ?? null,
                    ];
                }
            }
        }

        // Batch insert
        $inserted = $this->indexPoints($collection, $points);

        return [
            'collection' => $collection,
            'total_templates' => count($templates),
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
        // Delete if exists
        if ($this->vectorStore->collectionExists($collection)) {
            $this->vectorStore->deleteCollection($collection);
        }

        // Create new
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
            'scopes' => self::COLLECTION_SCOPES,
            'templates' => self::COLLECTION_TEMPLATES,
        ];
    }

    /**
     * Check if all collections exist
     *
     * @return array Status of each collection
     */
    public function checkCollections(): array
    {
        return [
            'entities' => $this->vectorStore->collectionExists(self::COLLECTION_ENTITIES),
            'scopes' => $this->vectorStore->collectionExists(self::COLLECTION_SCOPES),
            'templates' => $this->vectorStore->collectionExists(self::COLLECTION_TEMPLATES),
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
