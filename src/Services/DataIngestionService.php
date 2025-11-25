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
use Condoedge\Ai\Exceptions\DataConsistencyException;
use Condoedge\Ai\Services\Security\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;

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
     *
     * Implements compensating transactions to ensure data consistency across dual stores.
     * If one store fails, the successful operation is rolled back to prevent orphaned data.
     *
     * Transaction Flow:
     * 1. Attempt graph insert (with relationships)
     * 2. If successful, attempt vector insert
     * 3. If vector fails, rollback graph insert
     * 4. If both succeed, return success
     * 5. If graph fails initially, don't attempt vector
     *
     * @throws DataConsistencyException If operation fails but rollback succeeds
     * @throws \Exception If operation and rollback both fail (requires manual intervention)
     */
    public function ingest(Nodeable $entity): array
    {
        $this->validateEntity($entity);

        $entityId = $entity->getId();
        $graphSuccess = false;
        $vectorSuccess = false;
        $relationshipsCreated = 0;

        try {
            // Phase 1: Ingest into graph store
            $graphConfig = $entity->getGraphConfig();
            $this->ingestToGraph($entity, $graphConfig);
            $graphSuccess = true;

            // Create relationships after node is created
            $relationshipsCreated = $this->createRelationships($entity, $graphConfig);

            try {
                // Phase 2: Ingest into vector store
                $vectorConfig = $entity->getVectorConfig();
                $this->ingestToVector($entity, $vectorConfig);
                $vectorSuccess = true;

                // Both succeeded - return success
                return [
                    'graph_stored' => true,
                    'vector_stored' => true,
                    'relationships_created' => $relationshipsCreated,
                    'errors' => [],
                ];

            } catch (\Exception $vectorError) {
                // Vector store failed - ROLLBACK graph insert
                Log::warning('Vector store failed, rolling back graph insert', SensitiveDataSanitizer::forLogging([
                    'entity_id' => $entityId,
                    'entity_class' => get_class($entity),
                    'error' => $vectorError->getMessage(),
                ]));

                try {
                    // Compensating transaction: Delete from graph
                    $this->graphStore->deleteNode($graphConfig->label, $entityId);

                } catch (\Exception $rollbackError) {
                    // Rollback FAILED - critical data inconsistency
                    Log::critical('CRITICAL: Rollback failed, data inconsistency detected', SensitiveDataSanitizer::forLogging([
                        'entity_id' => $entityId,
                        'entity_class' => get_class($entity),
                        'graph_success' => true,
                        'vector_success' => false,
                        'rolled_back' => false,
                        'vector_error' => $vectorError->getMessage(),
                        'rollback_error' => $rollbackError->getMessage(),
                    ]));

                    // This requires manual intervention
                    throw new \RuntimeException(
                        "CRITICAL DATA INCONSISTENCY: Entity {$entityId} exists in Neo4j but not in Qdrant. " .
                        "Rollback failed. Manual cleanup required. " .
                        "Neo4j label: {$graphConfig->label}, Entity ID: {$entityId}",
                        0,
                        $rollbackError
                    );
                }

                // Rollback successful - throw consistency exception
                throw new DataConsistencyException(
                    "Vector store failed, rolled back graph insert for entity {$entityId}",
                    [
                        'entity_id' => $entityId,
                        'entity_class' => get_class($entity),
                        'graph_success' => true,
                        'vector_success' => false,
                        'rolled_back' => true,
                        'operation' => 'ingest',
                    ],
                    0,
                    $vectorError
                );
            }

        } catch (DataConsistencyException $e) {
            // Re-throw consistency exceptions (rollback was successful)
            throw $e;

        } catch (\Exception $graphError) {
            // Graph store failed initially - don't attempt vector store
            Log::error('Graph store failed during ingest', SensitiveDataSanitizer::forLogging([
                'entity_id' => $entityId,
                'entity_class' => get_class($entity),
                'error' => $graphError->getMessage(),
            ]));

            throw new DataConsistencyException(
                "Graph store failed for entity {$entityId}: " . $graphError->getMessage(),
                [
                    'entity_id' => $entityId,
                    'entity_class' => get_class($entity),
                    'graph_success' => false,
                    'vector_success' => false,
                    'rolled_back' => false, // Nothing to rollback
                    'operation' => 'ingest',
                ],
                0,
                $graphError
            );
        }
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
     *
     * Implements compensating transactions for removal operations.
     * If one store fails after the other succeeds, attempts to restore the deleted entity.
     *
     * Transaction Flow:
     * 1. Snapshot entity data before deletion
     * 2. Attempt graph deletion
     * 3. If successful, attempt vector deletion
     * 4. If vector fails, restore graph node from snapshot
     * 5. If both succeed, return true
     *
     * @throws DataConsistencyException If operation fails but compensation succeeds
     * @throws \RuntimeException If operation and compensation both fail
     */
    public function remove(Nodeable $entity): bool
    {
        $this->validateEntity($entity);

        $entityId = $entity->getId();
        $graphConfig = $entity->getGraphConfig();
        $vectorConfig = $entity->getVectorConfig();

        // Take snapshot for potential rollback
        $entitySnapshot = $entity->toArray();
        $graphSuccess = false;
        $vectorSuccess = false;

        try {
            // Phase 1: Remove from graph store
            $graphSuccess = $this->graphStore->deleteNode(
                $graphConfig->label,
                $entityId
            );

            if (!$graphSuccess) {
                throw new \RuntimeException("Failed to delete node from graph store");
            }

            try {
                // Phase 2: Remove from vector store
                $vectorSuccess = $this->vectorStore->deletePoints(
                    $vectorConfig->collection,
                    [$entityId]
                );

                if (!$vectorSuccess) {
                    throw new \RuntimeException("Failed to delete points from vector store");
                }

                // Both succeeded
                return true;

            } catch (\Exception $vectorError) {
                // Vector deletion failed - RESTORE graph node
                Log::warning('Vector deletion failed, restoring graph node', SensitiveDataSanitizer::forLogging([
                    'entity_id' => $entityId,
                    'entity_class' => get_class($entity),
                    'error' => $vectorError->getMessage(),
                ]));

                try {
                    // Compensating transaction: Recreate node
                    $this->graphStore->createNode($graphConfig->label, $entitySnapshot);

                    // Restore relationships if they existed
                    $this->createRelationships($entity, $graphConfig);

                } catch (\Exception $restoreError) {
                    // Restoration FAILED - critical inconsistency
                    Log::critical('CRITICAL: Failed to restore graph node after vector deletion failure', SensitiveDataSanitizer::forLogging([
                        'entity_id' => $entityId,
                        'entity_class' => get_class($entity),
                        'graph_success' => true,
                        'vector_success' => false,
                        'rolled_back' => false,
                        'vector_error' => $vectorError->getMessage(),
                        'restore_error' => $restoreError->getMessage(),
                    ]));

                    throw new \RuntimeException(
                        "CRITICAL DATA INCONSISTENCY: Entity {$entityId} deleted from Neo4j but not Qdrant. " .
                        "Restoration failed. Manual recovery required.",
                        0,
                        $restoreError
                    );
                }

                // Restoration successful - throw consistency exception
                throw new DataConsistencyException(
                    "Vector deletion failed, restored graph node for entity {$entityId}",
                    [
                        'entity_id' => $entityId,
                        'entity_class' => get_class($entity),
                        'graph_success' => true,
                        'vector_success' => false,
                        'rolled_back' => true,
                        'operation' => 'remove',
                    ],
                    0,
                    $vectorError
                );
            }

        } catch (DataConsistencyException $e) {
            // Re-throw consistency exceptions
            throw $e;

        } catch (\Exception $graphError) {
            // Graph deletion failed initially
            Log::error('Graph deletion failed', SensitiveDataSanitizer::forLogging([
                'entity_id' => $entityId,
                'entity_class' => get_class($entity),
                'error' => $graphError->getMessage(),
            ]));

            throw new DataConsistencyException(
                "Graph deletion failed for entity {$entityId}: " . $graphError->getMessage(),
                [
                    'entity_id' => $entityId,
                    'entity_class' => get_class($entity),
                    'graph_success' => false,
                    'vector_success' => false,
                    'rolled_back' => false,
                    'operation' => 'remove',
                ],
                0,
                $graphError
            );
        }
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
     * Only creates relationships where the target node exists in Neo4j.
     * Silently skips relationships to non-existent targets to allow
     * flexible ingestion order (e.g., Users before Persons).
     *
     * Use syncRelationships() or 'php artisan ai:sync-relationships'
     * to reconcile missing relationships after all entities are ingested.
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
        $skipped = 0;

        foreach ($config->relationships as $relationshipConfig) {
            $foreignKeyValue = $data[$relationshipConfig->foreignKey] ?? null;

            // Skip if foreign key is not set or is null
            if ($foreignKeyValue === null) {
                continue;
            }

            // Check if target node exists before creating relationship
            try {
                $targetExists = $this->graphStore->nodeExists(
                    $relationshipConfig->targetLabel,
                    $foreignKeyValue
                );

                if (!$targetExists) {
                    Log::debug("Skipping relationship: target node not found", [
                        'from_label' => $config->label,
                        'from_id' => $entity->getId(),
                        'to_label' => $relationshipConfig->targetLabel,
                        'to_id' => $foreignKeyValue,
                        'relationship_type' => $relationshipConfig->type,
                        'message' => 'Target node will be created later. Run ai:sync-relationships after bulk ingestion.',
                    ]);
                    $skipped++;
                    continue;
                }
            } catch (\Exception $e) {
                Log::warning("Failed to check if target node exists, skipping relationship", [
                    'from_label' => $config->label,
                    'from_id' => $entity->getId(),
                    'to_label' => $relationshipConfig->targetLabel,
                    'to_id' => $foreignKeyValue,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
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

            try {
                // Check if relationship already exists to avoid duplicates
                $relationshipExists = $this->graphStore->relationshipExists(
                    fromLabel: $config->label,
                    fromId: $entity->getId(),
                    toLabel: $relationshipConfig->targetLabel,
                    toId: $foreignKeyValue,
                    type: $relationshipConfig->type
                );

                if ($relationshipExists) {
                    Log::debug("Relationship already exists, skipping", [
                        'from_label' => $config->label,
                        'from_id' => $entity->getId(),
                        'to_label' => $relationshipConfig->targetLabel,
                        'to_id' => $foreignKeyValue,
                        'type' => $relationshipConfig->type,
                    ]);
                    continue; // Already exists, don't create duplicate
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
            } catch (\Exception $e) {
                Log::warning("Failed to create relationship", [
                    'from_label' => $config->label,
                    'from_id' => $entity->getId(),
                    'to_label' => $relationshipConfig->targetLabel,
                    'to_id' => $foreignKeyValue,
                    'type' => $relationshipConfig->type,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        if ($skipped > 0) {
            Log::info("Relationships created with some skipped", [
                'entity_class' => get_class($entity),
                'entity_id' => $entity->getId(),
                'created' => $count,
                'skipped' => $skipped,
                'message' => 'Run "php artisan ai:sync-relationships" to create missing relationships',
            ]);
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
        // Ensure collection exists before ingesting
        $this->ensureCollectionExists($config);

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

    /**
     * {@inheritDoc}
     */
    public function syncRelationships(array $entities): array
    {
        $summary = [
            'total_entities' => count($entities),
            'total_relationships_checked' => 0,
            'relationships_created' => 0,
            'relationships_skipped' => 0,
            'relationships_failed' => 0,
            'errors' => [],
        ];

        if (empty($entities)) {
            return $summary;
        }

        Log::info("Starting relationship synchronization", [
            'total_entities' => count($entities),
        ]);

        foreach ($entities as $entity) {
            try {
                $this->validateEntity($entity);

                $entityId = $entity->getId();
                $graphConfig = $entity->getGraphConfig();
                $data = $entity->toArray();

                // Check if source node exists
                $sourceExists = $this->graphStore->nodeExists($graphConfig->label, $entityId);
                if (!$sourceExists) {
                    Log::warning("Source node does not exist, skipping relationships", [
                        'entity_class' => get_class($entity),
                        'entity_id' => $entityId,
                        'label' => $graphConfig->label,
                    ]);
                    continue;
                }

                // Process each configured relationship
                foreach ($graphConfig->relationships as $relationshipConfig) {
                    $summary['total_relationships_checked']++;

                    $foreignKeyValue = $data[$relationshipConfig->foreignKey] ?? null;

                    // Skip if foreign key is not set
                    if ($foreignKeyValue === null) {
                        continue;
                    }

                    try {
                        // Check if target node exists
                        $targetExists = $this->graphStore->nodeExists(
                            $relationshipConfig->targetLabel,
                            $foreignKeyValue
                        );

                        if (!$targetExists) {
                            Log::debug("Target node still doesn't exist", [
                                'from_label' => $graphConfig->label,
                                'from_id' => $entityId,
                                'to_label' => $relationshipConfig->targetLabel,
                                'to_id' => $foreignKeyValue,
                            ]);
                            $summary['relationships_failed']++;
                            continue;
                        }

                        // Check if relationship already exists
                        $relationshipExists = $this->graphStore->relationshipExists(
                            fromLabel: $graphConfig->label,
                            fromId: $entityId,
                            toLabel: $relationshipConfig->targetLabel,
                            toId: $foreignKeyValue,
                            type: $relationshipConfig->type
                        );

                        if ($relationshipExists) {
                            Log::debug("Relationship already exists", [
                                'from_label' => $graphConfig->label,
                                'from_id' => $entityId,
                                'to_label' => $relationshipConfig->targetLabel,
                                'to_id' => $foreignKeyValue,
                                'type' => $relationshipConfig->type,
                            ]);
                            $summary['relationships_skipped']++;
                            continue;
                        }

                        // Extract relationship properties
                        $relationshipProperties = [];
                        if ($relationshipConfig->hasProperties()) {
                            foreach ($relationshipConfig->properties as $key => $sourceField) {
                                if (isset($data[$sourceField])) {
                                    $relationshipProperties[$key] = $data[$sourceField];
                                }
                            }
                        }

                        // Create the relationship
                        $this->graphStore->createRelationship(
                            fromLabel: $graphConfig->label,
                            fromId: $entityId,
                            toLabel: $relationshipConfig->targetLabel,
                            toId: $foreignKeyValue,
                            type: $relationshipConfig->type,
                            properties: $relationshipProperties
                        );

                        Log::info("Relationship created during sync", [
                            'from_label' => $graphConfig->label,
                            'from_id' => $entityId,
                            'to_label' => $relationshipConfig->targetLabel,
                            'to_id' => $foreignKeyValue,
                            'type' => $relationshipConfig->type,
                        ]);

                        $summary['relationships_created']++;

                    } catch (\Exception $e) {
                        Log::error("Failed to sync relationship", [
                            'from_label' => $graphConfig->label,
                            'from_id' => $entityId,
                            'to_label' => $relationshipConfig->targetLabel,
                            'to_id' => $foreignKeyValue,
                            'type' => $relationshipConfig->type,
                            'error' => $e->getMessage(),
                        ]);

                        $summary['relationships_failed']++;
                        $summary['errors'][$entityId][] = sprintf(
                            "Failed to create %s relationship to %s:%s - %s",
                            $relationshipConfig->type,
                            $relationshipConfig->targetLabel,
                            $foreignKeyValue,
                            $e->getMessage()
                        );
                    }
                }

            } catch (\Exception $e) {
                Log::error("Failed to sync relationships for entity", [
                    'entity_class' => get_class($entity),
                    'entity_id' => $entity->getId(),
                    'error' => $e->getMessage(),
                ]);

                $summary['errors'][$entity->getId()][] = $e->getMessage();
            }
        }

        Log::info("Relationship synchronization completed", $summary);

        return $summary;
    }

    /**
     * Ensure Qdrant collection exists, create if missing
     *
     * Creates collection lazily on first use with proper vector dimensions
     * and distance metric. Subsequent calls are cached to avoid redundant checks.
     *
     * @param VectorConfig $config Vector configuration with collection name
     * @return void
     */
    private function ensureCollectionExists(VectorConfig $config): void
    {
        static $checkedCollections = [];

        $collectionName = $config->collection;

        // Skip if already checked in this request
        if (isset($checkedCollections[$collectionName])) {
            return;
        }

        try {
            // Check if collection exists
            if (!$this->vectorStore->collectionExists($collectionName)) {
                // Create collection with configured dimensions
                $vectorSize = config('ai.embeddings.dimensions', 1536);
                $distance = config('ai.embeddings.distance', 'Cosine');

                Log::info("Creating Qdrant collection: {$collectionName}", [
                    'vector_size' => $vectorSize,
                    'distance' => $distance,
                ]);

                $this->vectorStore->createCollection(
                    name: $collectionName,
                    vectorSize: $vectorSize,
                    distance: $distance
                );

                Log::info("Qdrant collection created successfully: {$collectionName}");
            }

            // Mark as checked
            $checkedCollections[$collectionName] = true;

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
}
