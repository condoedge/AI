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
 * Depends only on interfaces, injects all dependencies via the constructor, and is
 * resilient to partial failures (one store failing doesn't break the other).
 */
class DataIngestionService implements DataIngestionServiceInterface
{
    private readonly GraphPropertyMapper $propertyMapper;
    private readonly VectorPayloadBuilder $payloadBuilder;
    private readonly RelationshipSyncer $relationshipSyncer;

    /**
     * @param VectorStoreInterface $vectorStore Vector database (Qdrant, Pinecone, etc.)
     * @param GraphStoreInterface $graphStore Graph database (Neo4j, ArangoDB, etc.)
     * @param EmbeddingProviderInterface $embeddingProvider Embedding service (OpenAI, Anthropic, etc.)
     * @param GraphPropertyMapper|null $propertyMapper Neo4j property extraction/sanitization
     * @param VectorPayloadBuilder|null $payloadBuilder Embed-text + payload + collection ensure
     * @param RelationshipSyncer|null $relationshipSyncer Per-entity graph relationship logic
     */
    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly GraphStoreInterface $graphStore,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        ?GraphPropertyMapper $propertyMapper = null,
        ?VectorPayloadBuilder $payloadBuilder = null,
        ?RelationshipSyncer $relationshipSyncer = null
    ) {
        $this->propertyMapper = $propertyMapper ?? new GraphPropertyMapper();
        $this->payloadBuilder = $payloadBuilder ?? new VectorPayloadBuilder($this->propertyMapper);
        $this->relationshipSyncer = $relationshipSyncer ?? new RelationshipSyncer($this->graphStore);
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

            // Relationships require the node to exist first.
            $relationshipsCreated = $this->relationshipSyncer->createRelationships($entity, $graphConfig);

            try {
                // Phase 2: Ingest into vector store
                $vectorConfig = $entity->getVectorConfig();
                $this->ingestToVector($entity, $vectorConfig);
                $vectorSuccess = true;

                return [
                    'graph_stored' => true,
                    'vector_stored' => true,
                    'relationships_created' => $relationshipsCreated,
                    'errors' => [],
                ];

            } catch (\Exception $vectorError) {
                Log::warning('Vector store failed, rolling back graph insert', SensitiveDataSanitizer::forLogging([
                    'entity_id' => $entityId,
                    'entity_class' => get_class($entity),
                    'error' => $vectorError->getMessage(),
                ]));

                try {
                    // Compensating transaction: delete the orphaned graph node.
                    $this->graphStore->deleteNode($graphConfig->label, $entityId);

                } catch (\Exception $rollbackError) {
                    Log::critical('CRITICAL: Rollback failed, data inconsistency detected', SensitiveDataSanitizer::forLogging([
                        'entity_id' => $entityId,
                        'entity_class' => get_class($entity),
                        'graph_success' => true,
                        'vector_success' => false,
                        'rolled_back' => false,
                        'vector_error' => $vectorError->getMessage(),
                        'rollback_error' => $rollbackError->getMessage(),
                    ]));

                    throw new \RuntimeException(
                        "CRITICAL DATA INCONSISTENCY: Entity {$entityId} exists in Neo4j but not in Qdrant. " .
                        "Rollback failed. Manual cleanup required. " .
                        "Neo4j label: {$graphConfig->label}, Entity ID: {$entityId}",
                        0,
                        $rollbackError
                    );
                }

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
            throw $e;

        } catch (\Exception $graphError) {
            // Graph store failed before vector was attempted — nothing to roll back.
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

        foreach ($entities as $index => $entity) {
            try {
                $this->validateEntity($entity);
            } catch (\InvalidArgumentException $e) {
                $summary['failed']++;
                $summary['errors'][$index] = $e->getMessage();
                unset($entities[$index]);
            }
        }

        $entities = array_values($entities);

        if (empty($entities)) {
            return $summary;
        }

        // Skip entities already present in the graph store.
        $entityIds = array_map(fn($e) => $e->getId(), $entities);
        $alreadyIngestedIntoGraphsIds = $this->graphStore->query(
            "MATCH (n) WHERE n.id IN \$ids RETURN n.id AS id",
            ['ids' => $entityIds]
        );
        $filteredGraphEntities = collect($entities)->reject(fn($e) => in_array($e->getId(), $alreadyIngestedIntoGraphsIds))->all();

        $graphResults = $this->batchIngestToGraph($filteredGraphEntities);

        // Vectors are upserted, so re-ingesting an existing entity is safe (no pre-filter).
        $vectorResults = $this->batchIngestToVector($entities);

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

        // Take snapshot for potential rollback (sanitize for Neo4j compatibility)
        $entitySnapshot = array_map(
            fn ($v) => $this->propertyMapper->sanitizeForGraph($v),
            $entity->toArray()
        );
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

                return true;

            } catch (\Exception $vectorError) {
                Log::warning('Vector deletion failed, restoring graph node', SensitiveDataSanitizer::forLogging([
                    'entity_id' => $entityId,
                    'entity_class' => get_class($entity),
                    'error' => $vectorError->getMessage(),
                ]));

                try {
                    // Compensating transaction: recreate the node and its relationships.
                    $this->graphStore->createNode($graphConfig->label, $entitySnapshot);
                    $this->relationshipSyncer->createRelationships($entity, $graphConfig);

                } catch (\Exception $restoreError) {
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
            throw $e;

        } catch (\Exception $graphError) {
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

        try {
            $graphConfig = $entity->getGraphConfig();
            if ($graphExists) {
                $this->updateGraph($entity, $graphConfig);
                $status['action'] = 'updated';
            } else {
                $this->ingestToGraph($entity, $graphConfig);
                $this->relationshipSyncer->createRelationships($entity, $graphConfig);
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
        $properties = $this->propertyMapper->extractProperties($data, $config->properties, $entity);
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
        $properties = $this->propertyMapper->extractProperties($data, $config->properties, $entity);

        // Don't include ID in update properties (it's used for matching)
        unset($properties['id']);

        $this->graphStore->updateNode(
            $config->label,
            $entity->getId(),
            $properties
        );
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
        $this->payloadBuilder->ensureCollectionExists($this->vectorStore, $config);

        $data = $entity->toArray();

        // Embed text excludes sensible columns.
        $textToEmbed = $this->payloadBuilder->buildEmbedTextFor($entity, $data, $config);

        if (empty($textToEmbed)) {
            throw new \RuntimeException(
                'Cannot generate embedding: no text found in embed fields'
            );
        }

        $vector = $this->embeddingProvider->embed($textToEmbed);

        // Point payload includes security/owner metadata.
        $point = $this->payloadBuilder->buildPoint($entity, $data, $config, $vector);

        $this->vectorStore->upsert(
            collection: $config->collection,
            points: [$point]
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

        foreach ($entities as $index => $entity) {
            try {
                $graphConfig = $entity->getGraphConfig();
                $this->ingestToGraph($entity, $graphConfig);
                $relationshipsCreated = $this->relationshipSyncer->createRelationships($entity, $graphConfig);

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
            $entitiesByCollection = $this->groupEntitiesByCollection($entities);

            foreach ($entitiesByCollection as $collection => $groupedEntities) {
                $this->batchIngestToVectorCollection($collection, $groupedEntities, $results);
            }
        } catch (\Exception $e) {
            // Fall back to individual processing if batch processing fails.
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

        foreach ($entitiesWithIndexes as $indexData) {
            $index = $indexData['index'];
            $entity = $indexData['entity'];
            $config = $indexData['config'];

            $data = $entity->toArray();
            $textToEmbed = $this->payloadBuilder->buildEmbedText($data, $config->embedFields);

            if (empty($textToEmbed)) {
                $results[$index] = [
                    'success' => false,
                    'error' => ['Vector: No text found in embed fields'],
                ];
                continue;
            }

            $textsToEmbed[$index] = $textToEmbed;
            $metadata = $this->propertyMapper->extractProperties($data, $config->metadata);
            $metadata['id'] = $entity->getId();
            $metadataByIndex[$index] = [
                'entity' => $entity,
                'metadata' => $metadata,
            ];
        }

        if (empty($textsToEmbed)) {
            return;
        }

        $embeddings = $this->embeddingProvider->embedBatch(array_values($textsToEmbed));

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

        try {
            $this->vectorStore->upsert($collection, $points);
        } catch (\Exception $e) {
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
                // Skip entities without a vector config.
                continue;
            }
        }

        return $grouped;
    }

    /**
     * {@inheritDoc}
     */
    public function syncRelationships(array $entities): array
    {
        return $this->relationshipSyncer->syncRelationships($entities);
    }
}
