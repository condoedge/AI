<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\GraphStore\CypherSanitizer;
use Condoedge\Ai\Services\GraphPropertyMapper;
use Condoedge\Ai\Support\AiLogger;
use Illuminate\Support\Facades\Log;

class BatchDataIngestionService implements DataIngestionServiceInterface
{
    private readonly GraphPropertyMapper $propertyMapper;
    private readonly PolymorphicTargetResolver $polymorphicResolver;

    public function __construct(
        private readonly DataIngestionServiceInterface $inner,
        private readonly GraphStoreInterface $graphStore,
        private readonly AiLogger $logger,
        ?GraphPropertyMapper $propertyMapper = null,
        ?PolymorphicTargetResolver $polymorphicResolver = null,
    ) {
        // Share the SAME graph-mapping logic as the single ingestion path so
        // single and batch produce identical sanitization.
        $this->propertyMapper = $propertyMapper ?? new GraphPropertyMapper();
        $this->polymorphicResolver = $polymorphicResolver ?? new PolymorphicTargetResolver();
    }

    // =========================================================================
    // Delegated single-entity methods
    // =========================================================================

    public function ingest(Nodeable $entity): array
    {
        return $this->inner->ingest($entity);
    }

    public function sync(Nodeable $entity): array
    {
        return $this->inner->sync($entity);
    }

    public function remove(Nodeable $entity): bool
    {
        return $this->inner->remove($entity);
    }

    // =========================================================================
    // Batch-optimized methods
    // =========================================================================

    public function ingestBatch(array $entities): array
    {
        $this->logger->startTimer('batch_ingest');
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

        $valid = [];
        foreach ($entities as $index => $entity) {
            if (!$entity instanceof Nodeable) {
                $summary['failed']++;
                $summary['errors'][$index] = 'Entity must implement Nodeable interface';
                continue;
            }
            $valid[$index] = $entity;
        }

        if (empty($valid)) {
            return $summary;
        }

        // Batch graph ingestion (nodes only, skip relationships)
        $this->logger->startTimer('batch_graph');
        $graphResults = $this->batchCreateNodes($valid);
        $graphMs = $this->logger->stopTimer('batch_graph');
        $this->logger->info('batch_ingestion', 'graph_phase_complete', [
            'duration_ms' => $graphMs,
            'entities' => count($valid),
        ]);

        // Aggregate results (records are not embedded: the graph write is the ingest)
        foreach ($valid as $index => $entity) {
            if ($graphResults[$index] ?? false) {
                $summary['succeeded']++;
            } else {
                $summary['failed']++;
                $summary['errors'][$index] = 'Graph ingestion failed';
            }
        }

        $elapsed = $this->logger->stopTimer('batch_ingest');
        $this->logger->info('batch_ingestion', 'batch_complete', [
            'total' => $summary['total'],
            'succeeded' => $summary['succeeded'],
            'partially_succeeded' => $summary['partially_succeeded'] ?? 0,
            'failed' => $summary['failed'],
            'duration_ms' => $elapsed,
            'errors_sample' => array_slice($summary['errors'], 0, 5),
        ]);

        return $summary;
    }

    public function syncRelationships(array $entities): array
    {
        $this->logger->startTimer('batch_sync_rels');
        $summary = [
            'total_entities' => count($entities),
            'total_relationships_checked' => 0,
            'relationships_created' => 0,
            'relationships_skipped' => 0,
            'relationships_failed' => 0,
            'relationships_unresolved_morphs' => 0,
            'errors' => [],
        ];

        if (empty($entities)) {
            return $summary;
        }

        // Collect all relationship pairs, grouped by (fromLabel, toLabel, type)
        $groups = [];

        foreach ($entities as $entity) {
            if (!$entity instanceof Nodeable) continue;

            try {
                $graphConfig = $entity->getGraphConfig();
                $data = $entity->toArray();

                foreach ($graphConfig->relationships as $relConfig) {
                    $foreignKeyValue = $data[$relConfig->foreignKey] ?? null;
                    if ($foreignKeyValue === null) continue;

                    $summary['total_relationships_checked']++;

                    // Polymorphic targets: resolve the concrete label per row,
                    // so one config fans out into one UNWIND group per distinct
                    // resolved label present in the batch.
                    $targetLabel = $relConfig->targetLabel;
                    if ($relConfig->isPolymorphic()) {
                        $targetLabel = $this->polymorphicResolver->resolveLabel(
                            $data[$relConfig->morphTypeColumn()] ?? null
                        );

                        if ($targetLabel === null) {
                            $summary['relationships_unresolved_morphs']++;
                            continue;
                        }
                    }

                    $groupKey = $graphConfig->label . '|' . $targetLabel . '|' . $relConfig->type;
                    if (!isset($groups[$groupKey])) {
                        $groups[$groupKey] = [
                            'fromLabel' => $graphConfig->label,
                            'toLabel' => $targetLabel,
                            'type' => $relConfig->type,
                            'pairs' => [],
                        ];
                    }

                    // Extract relationship properties if configured
                    $relProps = [];
                    if ($relConfig->hasProperties()) {
                        foreach ($relConfig->properties as $key => $sourceField) {
                            if (isset($data[$sourceField])) {
                                $relProps[$key] = $data[$sourceField];
                            }
                        }
                    }

                    $groups[$groupKey]['pairs'][] = [
                        'fromId' => $entity->getId(),
                        'toId' => $foreignKeyValue,
                        'properties' => $relProps,
                    ];
                }
            } catch (\Exception $e) {
                $summary['errors'][] = get_class($entity) . '#' . $entity->getId() . ': ' . $e->getMessage();
            }
        }

        // Fire one UNWIND query per group
        foreach ($groups as $groupKey => $group) {
            $created = $this->batchMergeRelationships($group);
            $summary['relationships_created'] += $created;
        }

        $elapsed = $this->logger->stopTimer('batch_sync_rels');
        $this->logger->info('batch_ingestion', 'relationships_synced', [
            'groups' => count($groups),
            'total_pairs' => $summary['total_relationships_checked'],
            'created' => $summary['relationships_created'],
            'duration_ms' => $elapsed,
        ]);

        return $summary;
    }

    // =========================================================================
    // Batch Neo4j operations
    // =========================================================================

    /**
     * Batch create nodes using UNWIND, grouped by label.
     * Returns array indexed like $entities with true/false per entity.
     */
    private function batchCreateNodes(array $entities): array
    {
        $results = [];
        $byLabel = [];

        // graph.with relations: one eager-load per model class (a no-op when the
        // ai:ingest chunk query already applied ->with()).
        $this->propertyMapper->eagerLoadGraphRelations($entities);

        // Group entities by label
        foreach ($entities as $index => $entity) {
            try {
                $graphConfig = $entity->getGraphConfig();
                // Single mapping point: configured + computed + cast properties.
                $properties = $this->propertyMapper->mapNodeProperties($entity, $graphConfig);
                $properties['id'] = $entity->getId();

                // Sanitize all values for Neo4j (id is added after extraction)
                $properties = array_map(fn($v) => $this->propertyMapper->sanitizeForGraph($v), $properties);

                $label = $graphConfig->label;
                if (!isset($byLabel[$label])) {
                    $byLabel[$label] = [];
                }
                $byLabel[$label][] = ['index' => $index, 'properties' => $properties];
            } catch (\Exception $e) {
                $results[$index] = false;
                $this->logger->error('batch_ingestion', 'entity_prep_failed', [
                    'entity' => get_class($entity),
                    'id' => $entity->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // One UNWIND query per label
        foreach ($byLabel as $label => $items) {
            $safeLabel = CypherSanitizer::escapeLabel($label);
            $batch = array_map(fn($item) => $item['properties'], $items);

            $cypher = "UNWIND \$batch AS row MERGE (n:{$safeLabel} {id: row.id}) SET n = row RETURN row.id AS id";

            try {
                $this->logger->debug('batch_ingestion', 'batch_merge_nodes', [
                    'label' => $label,
                    'count' => count($batch),
                ]);

                $startMs = microtime(true);
                $this->graphStore->query($cypher, ['batch' => $batch]);
                $queryMs = round((microtime(true) - $startMs) * 1000, 2);

                $this->logger->info('batch_ingestion', 'nodes_merged', [
                    'label' => $label,
                    'count' => count($batch),
                    'duration_ms' => $queryMs,
                    'per_entity_ms' => round($queryMs / count($batch), 2),
                ]);

                // Mark all items in this label as successful
                foreach ($items as $item) {
                    $results[$item['index']] = true;
                }
            } catch (\Exception $e) {
                $this->logger->error('batch_ingestion', 'batch_merge_failed', [
                    'label' => $label,
                    'count' => count($batch),
                    'error' => $e->getMessage(),
                ]);

                // Mark all items in this batch as failed
                foreach ($items as $item) {
                    $results[$item['index']] = false;
                }
            }
        }

        return $results;
    }

    /**
     * Batch MERGE relationships using UNWIND for one (fromLabel, toLabel, type) group.
     * Returns count of relationships processed.
     */
    private function batchMergeRelationships(array $group): int
    {
        $safeFromLabel = CypherSanitizer::escapeLabel($group['fromLabel']);
        $safeToLabel = CypherSanitizer::escapeLabel($group['toLabel']);
        $safeType = CypherSanitizer::escapeRelationshipType($group['type']);

        $hasProperties = false;
        foreach ($group['pairs'] as $pair) {
            if (!empty($pair['properties'])) {
                $hasProperties = true;
                break;
            }
        }

        $cypher = "
            UNWIND \$pairs AS pair
            MATCH (from:{$safeFromLabel} {id: pair.fromId})
            MATCH (to:{$safeToLabel} {id: pair.toId})
            MERGE (from)-[r:{$safeType}]->(to)
        ";

        if ($hasProperties) {
            $cypher .= "SET r += pair.properties\n";
        }

        $cypher .= "RETURN count(r) AS total";

        // Process in chunks of 500 to avoid huge transactions
        $chunkSize = 500;
        $total = 0;

        foreach (array_chunk($group['pairs'], $chunkSize) as $chunk) {
            try {
                $result = $this->graphStore->query($cypher, ['pairs' => $chunk]);
                $total += $result[0]['total'] ?? count($chunk);

                $this->logger->debug('batch_ingestion', 'batch_merge_relationships', [
                    'type' => $group['type'],
                    'from' => $group['fromLabel'],
                    'to' => $group['toLabel'],
                    'chunk_size' => count($chunk),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('batch_ingestion', 'batch_rel_merge_failed', [
                    'type' => $group['type'],
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $total;
    }
}
