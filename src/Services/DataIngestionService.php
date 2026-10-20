<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig;
use Condoedge\Ai\Exceptions\DataConsistencyException;
use Condoedge\Ai\Services\Schema\GraphSchemaCache;
use Condoedge\Ai\Services\Security\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;

/**
 * DataIngestionService
 *
 * Ingests Nodeable entities into the graph store (Neo4j): the node and its relationships.
 * Records are NOT embedded: no code searches a per-entity vector collection, so ingest and
 * auto-sync make no embedding call and no vector-store write.
 *
 * Depends only on interfaces and injects all dependencies via the constructor.
 */
class DataIngestionService implements DataIngestionServiceInterface
{
    private readonly GraphPropertyMapper $propertyMapper;
    private readonly RelationshipSyncer $relationshipSyncer;

    /**
     * @param GraphStoreInterface $graphStore Graph database (Neo4j, ArangoDB, etc.)
     * @param GraphPropertyMapper|null $propertyMapper Neo4j property extraction/sanitization
     * @param RelationshipSyncer|null $relationshipSyncer Per-entity graph relationship logic
     */
    public function __construct(
        private readonly GraphStoreInterface $graphStore,
        ?GraphPropertyMapper $propertyMapper = null,
        ?RelationshipSyncer $relationshipSyncer = null
    ) {
        $this->propertyMapper = $propertyMapper ?? new GraphPropertyMapper();
        $this->relationshipSyncer = $relationshipSyncer ?? new RelationshipSyncer($this->graphStore);
    }

    /**
     * {@inheritDoc}
     *
     * Writes the graph node, then its relationships (they require the node to exist).
     *
     * @throws DataConsistencyException If the graph store fails
     */
    public function ingest(Nodeable $entity): array
    {
        $this->validateEntity($entity);

        $entityId = $entity->getId();

        try {
            $graphConfig = $entity->getGraphConfig();
            $this->ingestToGraph($entity, $graphConfig);

            // Relationships require the node to exist first.
            $relationshipsCreated = $this->relationshipSyncer->createRelationships($entity, $graphConfig);

            return [
                'graph_stored' => true,
                'relationships_created' => $relationshipsCreated,
                'errors' => [],
            ];
        } catch (\Exception $graphError) {
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

        foreach ($entities as $index => $entity) {
            $entityId = $entity->getId();
            // An entity skipped above is already in the graph: nothing left to write for it.
            $alreadyStored = !array_key_exists($index, $filteredGraphEntities);

            if ($alreadyStored || ($graphResults[$index]['success'] ?? false)) {
                $summary['succeeded']++;
            } else {
                $summary['failed']++;
                $summary['errors'][$entityId] = $graphResults[$index]['error'] ?? [];
            }
        }

        return $summary;
    }

    /**
     * {@inheritDoc}
     *
     * Deletes the graph node (with its relationships).
     *
     * @throws DataConsistencyException If the graph store fails
     */
    public function remove(Nodeable $entity): bool
    {
        $this->validateEntity($entity);

        $entityId = $entity->getId();
        $graphConfig = $entity->getGraphConfig();

        try {
            if (!$this->graphStore->deleteNode($graphConfig->label, $entityId)) {
                throw new \RuntimeException("Failed to delete node from graph store");
            }

            return true;
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
        // Single mapping point: configured + computed (graph.computed/with) + cast properties.
        $properties = $this->propertyMapper->mapNodeProperties($entity, $config);
        $properties['id'] = $entity->getId();

        $this->graphStore->createNode($config->label, $properties);
        // Auto-sync never runs the command flush (review I-2): drop the cached schema
        // only when this write introduced a label it does not know (one cache read).
        (new GraphSchemaCache())->flushIfUnknownLabel($config->label);
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
        $properties = $this->propertyMapper->mapNodeProperties($entity, $config);

        // Don't include ID in update properties (it's used for matching)
        unset($properties['id']);

        $this->graphStore->updateNode(
            $config->label,
            $entity->getId(),
            $properties
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

        // graph.with relations: one eager-load per model class, not one per entity.
        $this->propertyMapper->eagerLoadGraphRelations($entities);

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
     * {@inheritDoc}
     */
    public function syncRelationships(array $entities): array
    {
        return $this->relationshipSyncer->syncRelationships($entities);
    }
}
