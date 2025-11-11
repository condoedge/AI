<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig;

/**
 * DataIngestionService
 *
 * Handles ingestion of Nodeable entities into both graph (Neo4j) and vector (Qdrant) stores.
 *
 * This service implements a modular, decoupled architecture:
 * - Depends ONLY on interfaces, not concrete implementations
 * - All dependencies injected via constructor
 * - Can be tested in isolation with mocks
 * - Resilient to partial failures (one store failing doesn't break the other)
 *
 * Architecture Principles:
 * 1. Interface-based dependency injection
 * 2. Separation of concerns (graph vs vector operations)
 * 3. Comprehensive error handling
 * 4. Detailed status reporting
 *
 * Example Usage:
 * ```php
 * $service = new DataIngestionService(
 *     vectorStore: new QdrantStore($config),
 *     graphStore: new Neo4jStore($config),
 *     embeddingProvider: new OpenAiEmbeddingProvider($config)
 * );
 *
 * $customer = Customer::find(1); // implements Nodeable
 * $status = $service->ingest($customer);
 * ```
 */
class DataIngestionService implements DataIngestionServiceInterface
{
    /**
     * @param VectorStoreInterface $vectorStore Vector database (Qdrant, Pinecone, etc.)
     * @param GraphStoreInterface $graphStore Graph database (Neo4j, ArangoDB, etc.)
     * @param EmbeddingProviderInterface $embeddingProvider Embedding service (OpenAI, Anthropic, etc.)
     */
    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly GraphStoreInterface $graphStore,
        private readonly EmbeddingProviderInterface $embeddingProvider
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function ingest(Nodeable $entity): array
    {
        $this->validateEntity($entity);

        $status = [
            'graph_stored' => false,
            'vector_stored' => false,
            'relationships_created' => 0,
            'errors' => [],
        ];

        // 1. Ingest into graph store
        try {
            $graphConfig = $entity->getGraphConfig();
            $this->ingestToGraph($entity, $graphConfig);
            $status['graph_stored'] = true;

            // Create relationships after node is created
            $status['relationships_created'] = $this->createRelationships($entity, $graphConfig);
        } catch (\Exception $e) {
            $status['errors'][] = 'Graph: ' . $e->getMessage();
        }

        // 2. Ingest into vector store
        try {
            $vectorConfig = $entity->getVectorConfig();
            $this->ingestToVector($entity, $vectorConfig);
            $status['vector_stored'] = true;
        } catch (\Exception $e) {
            $status['errors'][] = 'Vector: ' . $e->getMessage();
        }

        return $status;
    }

    /**
     * {@inheritDoc}
     */
    public function ingestBatch(array $entities): array
    {
        $summary = [
            'total' => count($entities),
            'succeeded' => 0,
            'partially_succeeded' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if (empty($entities)) {
            return $summary;
        }

        // Validate all entities first
        foreach ($entities as $index => $entity) {
            try {
                $this->validateEntity($entity);
            } catch (\InvalidArgumentException $e) {
                $summary['failed']++;
                $summary['errors'][$index] = $e->getMessage();
                unset($entities[$index]);
            }
        }

        // Re-index array after validation
        $entities = array_values($entities);

        if (empty($entities)) {
            return $summary;
        }

        // Process batch ingestion
        $graphResults = $this->batchIngestToGraph($entities);
        $vectorResults = $this->batchIngestToVector($entities);

        // Aggregate results
        foreach ($entities as $index => $entity) {
            $entityId = $entity->getId();
            $graphSuccess = $graphResults[$index]['success'] ?? false;
            $vectorSuccess = $vectorResults[$index]['success'] ?? false;

            if ($graphSuccess && $vectorSuccess) {
                $summary['succeeded']++;
            } elseif ($graphSuccess || $vectorSuccess) {
                $summary['partially_succeeded']++;
                $summary['errors'][$entityId] = array_merge(
                    $graphResults[$index]['error'] ?? [],
                    $vectorResults[$index]['error'] ?? []
                );
            } else {
                $summary['failed']++;
                $summary['errors'][$entityId] = array_merge(
                    $graphResults[$index]['error'] ?? [],
                    $vectorResults[$index]['error'] ?? []
                );
            }
        }

        return $summary;
    }

    /**
     * {@inheritDoc}
     */
    public function remove(Nodeable $entity): bool
    {
        $this->validateEntity($entity);

        $graphSuccess = false;
        $vectorSuccess = false;

        // Remove from graph store
        try {
            $graphConfig = $entity->getGraphConfig();
            $graphSuccess = $this->graphStore->deleteNode(
                $graphConfig->label,
                $entity->getId()
            );
        } catch (\Exception $e) {
            // Log but continue - we still want to try removing from vector store
        }

        // Remove from vector store
        try {
            $vectorConfig = $entity->getVectorConfig();
            $vectorSuccess = $this->vectorStore->deletePoints(
                $vectorConfig->collection,
                [$entity->getId()]
            );
        } catch (\Exception $e) {
            // Log but continue
        }

        // Success if removed from at least one store
        return $graphSuccess || $vectorSuccess;
    }

    /**
     * {@inheritDoc}
     */
    public function sync(Nodeable $entity): array
    {
        $this->validateEntity($entity);

        $status = [
            'action' => 'unknown',
            'graph_synced' => false,
            'vector_synced' => false,
            'errors' => [],
        ];

        // Check if entity exists in graph store
        $graphExists = false;
        try {
            $graphConfig = $entity->getGraphConfig();
            $graphExists = $this->graphStore->nodeExists(
                $graphConfig->label,
                $entity->getId()
            );
        } catch (\Exception $e) {
            $status['errors'][] = 'Graph check: ' . $e->getMessage();
        }

        // Sync to graph store
        try {
            $graphConfig = $entity->getGraphConfig();
            if ($graphExists) {
                $this->updateGraph($entity, $graphConfig);
                $status['action'] = 'updated';
            } else {
                $this->ingestToGraph($entity, $graphConfig);
                $this->createRelationships($entity, $graphConfig);
                $status['action'] = 'created';
            }
            $status['graph_synced'] = true;
        } catch (\Exception $e) {
            $status['errors'][] = 'Graph: ' . $e->getMessage();
        }

        // Sync to vector store (upsert always works for vector stores)
        try {
            $vectorConfig = $entity->getVectorConfig();
            $this->ingestToVector($entity, $vectorConfig);
            $status['vector_synced'] = true;
        } catch (\Exception $e) {
            $status['errors'][] = 'Vector: ' . $e->getMessage();
        }

        return $status;
    }

    /**
     * Validate that entity implements Nodeable interface
     *
     * @param mixed $entity Entity to validate
     * @throws \InvalidArgumentException If entity is not Nodeable
     */
    private function validateEntity($entity): void
    {
        if (!$entity instanceof Nodeable) {
            throw new \InvalidArgumentException(
                'Entity must implement Nodeable interface'
            );
        }
    }

    /**
     * Ingest a single entity into the graph store
     *
     * @param Nodeable $entity Entity to ingest
     * @param GraphConfig $config Graph configuration
     * @throws \Exception If ingestion fails
     */
    private function ingestToGraph(Nodeable $entity, GraphConfig $config): void
    {
        $data = $entity->toArray();
        $properties = $this->extractProperties($data, $config->properties);

        // Always include entity ID
        $properties['id'] = $entity->getId();

        $this->graphStore->createNode($config->label, $properties);
    }

    /**
     * Update an existing entity in the graph store
     *
     * @param Nodeable $entity Entity to update
     * @param GraphConfig $config Graph configuration
     * @throws \Exception If update fails
     */
    private function updateGraph(Nodeable $entity, GraphConfig $config): void
    {
        $data = $entity->toArray();
        $properties = $this->extractProperties($data, $config->properties);

        // Don't include ID in update properties (it's used for matching)
        unset($properties['id']);

        $this->graphStore->updateNode(
            $config->label,
            $entity->getId(),
            $properties
        );
    }

    /**
     * Create relationships for an entity
     *
     * @param Nodeable $entity Source entity
     * @param GraphConfig $config Graph configuration
     * @return int Number of relationships created
     * @throws \Exception If relationship creation fails
     */
    private function createRelationships(Nodeable $entity, GraphConfig $config): int
    {
        $data = $entity->toArray();
        $count = 0;

        foreach ($config->relationships as $relationshipConfig) {
            $foreignKeyValue = $data[$relationshipConfig->foreignKey] ?? null;

            // Skip if foreign key is not set or is null
            if ($foreignKeyValue === null) {
                continue;
            }

            // Extract relationship properties if defined
            $relationshipProperties = [];
            if ($relationshipConfig->hasProperties()) {
                foreach ($relationshipConfig->properties as $key => $sourceField) {
                    if (isset($data[$sourceField])) {
                        $relationshipProperties[$key] = $data[$sourceField];
                    }
                }
            }

            $this->graphStore->createRelationship(
                fromLabel: $config->label,
                fromId: $entity->getId(),
                toLabel: $relationshipConfig->targetLabel,
                toId: $foreignKeyValue,
                type: $relationshipConfig->type,
                properties: $relationshipProperties
            );

            $count++;
        }

        return $count;
    }

    /**
     * Ingest a single entity into the vector store
     *
     * @param Nodeable $entity Entity to ingest
     * @param VectorConfig $config Vector configuration
     * @throws \Exception If ingestion fails
     */
    private function ingestToVector(Nodeable $entity, VectorConfig $config): void
    {
        $data = $entity->toArray();

        // Build text to embed from specified fields
        $textToEmbed = $this->buildEmbedText($data, $config->embedFields);

        if (empty($textToEmbed)) {
            throw new \RuntimeException(
                'Cannot generate embedding: no text found in embed fields'
            );
        }

        // Generate embedding
        $vector = $this->embeddingProvider->embed($textToEmbed);

        // Extract metadata
        $metadata = $this->extractProperties($data, $config->metadata);

        // Always include entity ID in metadata
        $metadata['id'] = $entity->getId();

        // Store in vector database
        $this->vectorStore->upsert(
            collection: $config->collection,
            points: [[
                'id' => $entity->getId(),
                'vector' => $vector,
                'payload' => $metadata,
            ]]
        );
    }

    /**
     * Batch ingest entities into graph store
     *
     * @param array $entities Array of Nodeable entities
     * @return array Results indexed by entity position
     */
    private function batchIngestToGraph(array $entities): array
    {
        $results = [];

        // Note: Could use transactions here for atomicity
        // $transaction = $this->graphStore->beginTransaction();

        foreach ($entities as $index => $entity) {
            try {
                $graphConfig = $entity->getGraphConfig();
                $this->ingestToGraph($entity, $graphConfig);
                $relationshipsCreated = $this->createRelationships($entity, $graphConfig);

                $results[$index] = [
                    'success' => true,
                    'relationships_created' => $relationshipsCreated,
                ];
            } catch (\Exception $e) {
                $results[$index] = [
                    'success' => false,
                    'error' => ['Graph: ' . $e->getMessage()],
                ];
            }
        }

        // $this->graphStore->commit($transaction);

        return $results;
    }

    /**
     * Batch ingest entities into vector store
     *
     * @param array $entities Array of Nodeable entities
     * @return array Results indexed by entity position
     */
    private function batchIngestToVector(array $entities): array
    {
        $results = [];

        try {
            // Group entities by collection
            $entitiesByCollection = $this->groupEntitiesByCollection($entities);

            foreach ($entitiesByCollection as $collection => $groupedEntities) {
                $this->batchIngestToVectorCollection($collection, $groupedEntities, $results);
            }
        } catch (\Exception $e) {
            // If batch processing fails, fall back to individual processing
            foreach ($entities as $index => $entity) {
                try {
                    $vectorConfig = $entity->getVectorConfig();
                    $this->ingestToVector($entity, $vectorConfig);
                    $results[$index] = ['success' => true];
                } catch (\Exception $individualError) {
                    $results[$index] = [
                        'success' => false,
                        'error' => ['Vector: ' . $individualError->getMessage()],
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Batch ingest entities to a specific vector collection
     *
     * @param string $collection Collection name
     * @param array $entitiesWithIndexes Entities with their original indexes
     * @param array &$results Results array to populate
     */
    private function batchIngestToVectorCollection(
        string $collection,
        array $entitiesWithIndexes,
        array &$results
    ): void {
        $textsToEmbed = [];
        $metadataByIndex = [];

        // Prepare texts and metadata
        foreach ($entitiesWithIndexes as $indexData) {
            $index = $indexData['index'];
            $entity = $indexData['entity'];
            $config = $indexData['config'];

            $data = $entity->toArray();
            $textToEmbed = $this->buildEmbedText($data, $config->embedFields);

            if (empty($textToEmbed)) {
                $results[$index] = [
                    'success' => false,
                    'error' => ['Vector: No text found in embed fields'],
                ];
                continue;
            }

            $textsToEmbed[$index] = $textToEmbed;
            $metadata = $this->extractProperties($data, $config->metadata);
            $metadata['id'] = $entity->getId();
            $metadataByIndex[$index] = [
                'entity' => $entity,
                'metadata' => $metadata,
            ];
        }

        if (empty($textsToEmbed)) {
            return;
        }

        // Batch generate embeddings
        $embeddings = $this->embeddingProvider->embedBatch(array_values($textsToEmbed));

        // Build points for batch upsert
        $points = [];
        $embeddingIndex = 0;
        foreach (array_keys($textsToEmbed) as $originalIndex) {
            $entityData = $metadataByIndex[$originalIndex];
            $points[] = [
                'id' => $entityData['entity']->getId(),
                'vector' => $embeddings[$embeddingIndex],
                'payload' => $entityData['metadata'],
            ];
            $results[$originalIndex] = ['success' => true];
            $embeddingIndex++;
        }

        // Batch upsert to vector store
        try {
            $this->vectorStore->upsert($collection, $points);
        } catch (\Exception $e) {
            // Mark all as failed if batch upsert fails
            foreach (array_keys($textsToEmbed) as $originalIndex) {
                $results[$originalIndex] = [
                    'success' => false,
                    'error' => ['Vector: ' . $e->getMessage()],
                ];
            }
        }
    }

    /**
     * Group entities by their vector collection
     *
     * @param array $entities Array of Nodeable entities
     * @return array Entities grouped by collection name
     */
    private function groupEntitiesByCollection(array $entities): array
    {
        $grouped = [];

        foreach ($entities as $index => $entity) {
            try {
                $config = $entity->getVectorConfig();
                $collection = $config->collection;

                $grouped[$collection][] = [
                    'index' => $index,
                    'entity' => $entity,
                    'config' => $config,
                ];
            } catch (\Exception $e) {
                // Skip entities that don't have vector config
                continue;
            }
        }

        return $grouped;
    }

    /**
     * Build text to embed from entity data and field list
     *
     * @param array $data Entity data
     * @param array $fields Field names to concatenate
     * @return string Concatenated text
     */
    private function buildEmbedText(array $data, array $fields): string
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
     * Extract properties from entity data
     *
     * @param array $data Entity data
     * @param array $propertyNames Property names to extract
     * @return array Extracted properties
     */
    private function extractProperties(array $data, array $propertyNames): array
    {
        $properties = [];

        foreach ($propertyNames as $propertyName) {
            if (array_key_exists($propertyName, $data)) {
                $value = $data[$propertyName];

                // Convert objects to strings or arrays
                if (is_object($value)) {
                    if (method_exists($value, 'toArray')) {
                        $value = $value->toArray();
                    } elseif (method_exists($value, '__toString')) {
                        $value = (string) $value;
                    } else {
                        // Skip objects that can't be converted
                        continue;
                    }
                }

                $properties[$propertyName] = $value;
            }
        }

        return $properties;
    }
}
