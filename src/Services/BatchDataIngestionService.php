<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\GraphStore\CypherSanitizer;
use Condoedge\Ai\Support\AiLogger;
use Illuminate\Support\Facades\Log;

class BatchDataIngestionService implements DataIngestionServiceInterface
{
    public function __construct(
        private readonly DataIngestionServiceInterface $inner,
        private readonly VectorStoreInterface $vectorStore,
        private readonly GraphStoreInterface $graphStore,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly AiLogger $logger,
    ) {}

    // =========================================================================
    // Delegated single-entity methods (unchanged behavior)
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

        // Validate all entities first
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

        // Phase 1: Batch graph ingestion (nodes only, skip relationships)
        $this->logger->startTimer('batch_graph');
        $graphResults = $this->batchCreateNodes($valid);
        $graphMs = $this->logger->stopTimer('batch_graph');
        $this->logger->info('batch_ingestion', 'graph_phase_complete', [
            'duration_ms' => $graphMs,
            'entities' => count($valid),
        ]);

        // Phase 2: Batch vector ingestion (embeddings)
        $this->logger->startTimer('batch_vector');
        $vectorResults = $this->batchIngestToVector($valid);
        $vectorMs = $this->logger->stopTimer('batch_vector');
        $this->logger->info('batch_ingestion', 'vector_phase_complete', [
            'duration_ms' => $vectorMs,
            'entities' => count($valid),
        ]);

        // Aggregate results
        foreach ($valid as $index => $entity) {
            $graphOk = $graphResults[$index] ?? false;
            $vectorOk = $vectorResults[$index] ?? false;

            if ($graphOk && $vectorOk) {
                $summary['succeeded']++;
            } elseif ($graphOk || $vectorOk) {
                $summary['partially_succeeded']++;
                if (!$graphOk) $summary['errors'][$index] = 'Graph ingestion failed';
                if (!$vectorOk) $summary['errors'][$index] = 'Vector ingestion failed';
            } else {
                $summary['failed']++;
                $summary['errors'][$index] = 'Both graph and vector ingestion failed';
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

                    $groupKey = $graphConfig->label . '|' . $relConfig->targetLabel . '|' . $relConfig->type;
                    if (!isset($groups[$groupKey])) {
                        $groups[$groupKey] = [
                            'fromLabel' => $graphConfig->label,
                            'toLabel' => $relConfig->targetLabel,
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

        // Group entities by label
        foreach ($entities as $index => $entity) {
            try {
                $graphConfig = $entity->getGraphConfig();
                $data = $entity->toArray();
                $properties = $this->extractProperties($data, $graphConfig->properties);
                $properties['id'] = $entity->getId();

                // Sanitize all values for Neo4j
                $properties = array_map(fn($v) => $this->sanitizeForGraph($v), $properties);

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

    /**
     * Batch ingest to vector store — reuses embedding batching from original service.
     * Returns array indexed like $entities with true/false.
     */
    private function batchIngestToVector(array $entities): array
    {
        $results = [];

        // Group by collection
        $byCollection = [];
        foreach ($entities as $index => $entity) {
            try {
                $vectorConfig = $entity->getVectorConfig();
                $collection = $vectorConfig->collection;
                if (!isset($byCollection[$collection])) {
                    $byCollection[$collection] = [];
                }
                $byCollection[$collection][] = ['index' => $index, 'entity' => $entity, 'config' => $vectorConfig];
            } catch (\LogicException $e) {
                // Entity not vectorizable — that's ok, mark as success (graph-only entity)
                $results[$index] = true;
            } catch (\Exception $e) {
                $results[$index] = false;
            }
        }

        foreach ($byCollection as $collection => $items) {
            // Ensure collection exists before upserting
            $dimension = $this->embeddingProvider->getDimensions();
            $this->vectorStore->ensureCollection($collection, $dimension);
            try {
                // Build texts to embed
                $texts = [];
                $metadata = [];
                $indexes = [];

                foreach ($items as $item) {
                    $entity = $item['entity'];
                    $config = $item['config'];
                    $data = $entity->toArray();

                    $textParts = [];
                    foreach ($config->embedFields as $field) {
                        $value = $data[$field] ?? '';
                        if (is_array($value)) {
                            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                        }
                        if (!empty($value)) {
                            $textParts[] = (string) $value;
                        }
                    }

                    $textToEmbed = implode(' ', $textParts);
                    if (empty(trim($textToEmbed))) {
                        // No text to embed — graph-only entity, mark as success
                        $results[$item['index']] = true;
                        $this->logger->debug('batch_ingestion', 'skip_empty_embed', [
                            'entity' => get_class($entity),
                            'id' => $entity->getId(),
                        ]);
                        continue;
                    }

                    $texts[] = $textToEmbed;
                    $indexes[] = $item['index'];

                    // Build metadata
                    $meta = ['id' => $entity->getId()];
                    foreach ($config->metadata as $field) {
                        if (isset($data[$field])) {
                            $meta[$field] = is_array($data[$field])
                                ? json_encode($data[$field], JSON_UNESCAPED_UNICODE)
                                : $data[$field];
                        }
                    }
                    $meta['_entity_type'] = class_basename($entity);
                    $meta['_entity_class'] = get_class($entity);
                    $metadata[] = $meta;
                }

                if (empty($texts)) continue;

                // Batch embed
                $this->logger->info('batch_ingestion', 'embedding_start', [
                    'collection' => $collection,
                    'count' => count($texts),
                ]);

                $embedStart = microtime(true);
                $vectors = $this->embeddingProvider->embedBatch($texts);
                $embedMs = round((microtime(true) - $embedStart) * 1000, 2);

                $this->logger->info('batch_ingestion', 'embedding_complete', [
                    'collection' => $collection,
                    'count' => count($texts),
                    'duration_ms' => $embedMs,
                    'per_entity_ms' => round($embedMs / count($texts), 2),
                ]);

                // Build points and upsert in chunks (Qdrant has a 32MB payload limit)
                $points = [];
                foreach ($vectors as $i => $vector) {
                    $points[] = [
                        'id' => $metadata[$i]['id'],
                        'vector' => $vector,
                        'payload' => $metadata[$i],
                    ];
                }

                $upsertChunkSize = 500;
                foreach (array_chunk($points, $upsertChunkSize) as $chunk) {
                    $this->vectorStore->upsert($collection, $chunk);
                }

                // Mark all successful
                foreach ($indexes as $idx) {
                    $results[$idx] = true;
                }
            } catch (\Exception $e) {
                $this->logger->error('batch_ingestion', 'batch_vector_failed', [
                    'collection' => $collection,
                    'count' => count($items),
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
                foreach ($items as $item) {
                    if (!isset($results[$item['index']])) {
                        $results[$item['index']] = false;
                    }
                }
            }
        }

        return $results;
    }

    // =========================================================================
    // Pure helper functions (copied from DataIngestionService since they're private)
    // =========================================================================

    private function extractProperties(array $data, array $propertyNames): array
    {
        $properties = [];
        foreach ($propertyNames as $propertyName) {
            if (array_key_exists($propertyName, $data)) {
                $value = $data[$propertyName];
                if (is_object($value)) {
                    if (method_exists($value, 'toArray')) {
                        $value = $value->toArray();
                    } elseif (method_exists($value, '__toString')) {
                        $value = (string) $value;
                    } else {
                        continue;
                    }
                }
                $value = $this->sanitizeForGraph($value);
                $properties[$propertyName] = $value;
            }
        }
        return $properties;
    }

    private function sanitizeForGraph(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }
        return $value;
    }
}
