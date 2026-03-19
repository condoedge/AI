<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Domain\Traits\ResolvesEntityConfigKey;
use Condoedge\Ai\Support\AiLogger;

/**
 * SemanticContextSelector
 *
 * Intelligently selects which context to include in prompts based on semantic
 * relevance to the user's question. This reduces token consumption by only
 * including relevant entities, relationships, and schema information.
 *
 * Instead of sending all entity metadata (which can be thousands of tokens),
 * this service uses vector similarity to determine:
 * 1. Which entities are relevant to the question
 * 2. Which relationships matter for this query
 * 3. Which schema properties are needed
 *
 * Example:
 * - Question: "How many volunteers do we have?"
 * - Returns: Only Person entity with volunteers scope, PersonTeam relationship
 * - Excludes: Order, Invoice, Product entities (not relevant)
 */
class SemanticContextSelector
{
    use ResolvesEntityConfigKey;

    private const DEFAULT_COLLECTION_NAME = 'context_index';
    private const DEFAULT_THRESHOLD = 0.65;
    private const DEFAULT_TOP_K = 10;

    private string $collectionName;

    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly AiLogger $logger,
        private readonly array $config = []
    ) {
        $this->collectionName = $config['collection'] ?? self::DEFAULT_COLLECTION_NAME;
    }

    /**
     * Select relevant context components for a question
     *
     * @param string $question User's question
     * @param array $entityConfigs All entity configurations
     * @param array $options Selection options
     * @return array Selected context with relevance scores
     */
    public function selectRelevantContext(
        string $question,
        array $entityConfigs,
        array $options = []
    ): array {
        $threshold = $options['threshold'] ?? self::DEFAULT_THRESHOLD;
        $topK = $options['top_k'] ?? self::DEFAULT_TOP_K;
        $collectionName = $this->config['collection'] ?? $this->collectionName;

        // Check if semantic index exists
        if (!$this->indexExists($collectionName)) {
            // Fall back to keyword-based selection
            $this->logger->fallback('semantic_selector', 'vector_search', 'keyword_matching', 'semantic index not available');
            return $this->keywordBasedSelection($question, $entityConfigs);
        }

        // Embed the question
        $questionEmbedding = $this->embeddingProvider->embed($question);

        // Search for relevant context pieces
        $results = $this->vectorStore->search(
            $collectionName,
            $questionEmbedding,
            $topK * 2, // Get extra for filtering
            []
        );

        $this->logger->debug('semantic_selector', 'vector search completed', [
            'result_count' => count($results),
            'threshold' => $threshold,
        ]);

        // Group results by type and deduplicate
        $selectedEntities = [];
        $selectedRelationships = [];
        $selectedScopes = [];
        $seenEntities = [];
        $seenRelationships = [];

        foreach ($results as $result) {
            // Normalize: Qdrant returns 'payload', but code expects 'metadata'
            if (!isset($result['metadata']) && isset($result['payload'])) {
                $result['metadata'] = $result['payload'];
            }

            $score = $result['score'] ?? 0;

            if ($score < $threshold) {
                $this->logger->debug('semantic_selector', 'result below threshold, rejected', [
                    'type' => $result['metadata']['type'] ?? 'unknown',
                    'entity' => $result['metadata']['entity'] ?? $result['metadata']['relationship_key'] ?? 'unknown',
                    'score' => $score,
                    'threshold' => $threshold,
                ]);
                continue;
            }

            $type = $result['metadata']['type'] ?? 'unknown';
            $entityName = $result['metadata']['entity'] ?? null;

            switch ($type) {
                case 'entity':
                    if ($entityName && !isset($seenEntities[$entityName])) {
                        $seenEntities[$entityName] = true;
                        $selectedEntities[$entityName] = [
                            'score' => $score,
                            'matched_text' => $result['metadata']['text'] ?? '',
                            'config' => $entityConfigs[$entityName] ?? null,
                        ];
                    }
                    break;

                case 'relationship':
                    $relKey = $result['metadata']['relationship_key'] ?? '';
                    if ($relKey && !isset($seenRelationships[$relKey])) {
                        $seenRelationships[$relKey] = true;
                        $selectedRelationships[$relKey] = [
                            'score' => $score,
                            'from_entity' => $result['metadata']['from_entity'] ?? '',
                            'to_entity' => $result['metadata']['to_entity'] ?? '',
                            'type' => $result['metadata']['relationship_type'] ?? '',
                            'foreign_key' => $result['metadata']['foreign_key'] ?? null,
                            'direction' => $result['metadata']['direction'] ?? 'outgoing',
                        ];

                        // Also include related entities
                        $fromEntity = $result['metadata']['from_entity'] ?? null;
                        $toEntity = $result['metadata']['to_entity'] ?? null;

                        if ($fromEntity && !isset($seenEntities[$fromEntity])) {
                            $fullName = $this->findFullEntityName($fromEntity, $entityConfigs);
                            if ($fullName) {
                                $seenEntities[$fullName] = true;
                                $selectedEntities[$fullName] = [
                                    'score' => $score * 0.8, // Lower score for indirect match
                                    'matched_text' => "Related via {$relKey}",
                                    'config' => $entityConfigs[$fullName] ?? null,
                                ];
                            }
                        }

                        if ($toEntity && !isset($seenEntities[$toEntity])) {
                            $fullName = $this->findFullEntityName($toEntity, $entityConfigs);
                            if ($fullName) {
                                $seenEntities[$fullName] = true;
                                $selectedEntities[$fullName] = [
                                    'score' => $score * 0.8,
                                    'matched_text' => "Related via {$relKey}",
                                    'config' => $entityConfigs[$fullName] ?? null,
                                ];
                            }
                        }
                    }
                    break;

                case 'scope':
                    $scopeKey = $result['metadata']['scope_key'] ?? '';
                    if ($scopeKey && $entityName) {
                        $selectedScopes[$scopeKey] = [
                            'score' => $score,
                            'entity' => $entityName,
                            'matched_text' => $result['metadata']['text'] ?? '',
                        ];

                        // Ensure the entity is included
                        $fullName = $this->findFullEntityName($entityName, $entityConfigs);
                        if ($fullName && !isset($seenEntities[$fullName])) {
                            $seenEntities[$fullName] = true;
                            $selectedEntities[$fullName] = [
                                'score' => $score,
                                'matched_text' => "Has scope {$scopeKey}",
                                'config' => $entityConfigs[$fullName] ?? null,
                            ];
                        }
                    }
                    break;

                case 'property':
                    $propertyName = $result['metadata']['property'] ?? null;
                    if ($entityName && $propertyName) {
                        $fullName = $this->findFullEntityName($entityName, $entityConfigs);
                        if ($fullName && !isset($seenEntities[$fullName])) {
                            $seenEntities[$fullName] = true;
                            $selectedEntities[$fullName] = [
                                'score' => $score * 0.9,
                                'matched_text' => "Has property {$propertyName}",
                                'config' => $entityConfigs[$fullName] ?? null,
                            ];
                        }
                    }
                    break;
            }
        }

        // Expand selected entities through their configured relationships
        $this->expandEntitiesThroughRelationships(
            $selectedEntities,
            $selectedRelationships,
            $seenEntities,
            $entityConfigs,
            $threshold
        );

        // Sort by score
        uasort($selectedEntities, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        uasort($selectedRelationships, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        uasort($selectedScopes, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        $this->logger->debug('semantic_selector', 'final selection summary', [
            'entities' => array_map(fn($e) => $e['score'], $selectedEntities),
            'relationships' => array_keys($selectedRelationships),
            'scopes' => array_map(fn($s) => $s['score'], $selectedScopes),
        ]);

        return [
            'entities' => $selectedEntities,
            'relationships' => $selectedRelationships,
            'scopes' => $selectedScopes,
            'selection_method' => 'semantic',
        ];
    }

    /**
     * Index all context for semantic search
     *
     * Creates embeddings for:
     * - Entity labels and descriptions
     * - Entity aliases
     * - Relationship types and descriptions
     * - Property names and descriptions
     * - Scope names, examples, and concepts
     *
     * @param array $entityConfigs All entity configurations
     * @return array Index statistics
     */
    public function indexContext(array $entityConfigs): array
    {
        $collectionName = $this->config['collection'] ?? $this->collectionName;
        $dimension = $this->config['dimension'] ?? 1536;

        $this->logger->info('semantic_selector', 'starting context indexing', [
            'collection' => $collectionName,
            'entity_count' => count($entityConfigs),
        ]);

        // Ensure collection exists
        $this->vectorStore->ensureCollection($collectionName, $dimension);

        // Build all points in memory first (does not touch the target collection)
        $points = [];
        $errors = [];

        foreach ($entityConfigs as $entityName => $config) {
            // Index entity description
            $points = array_merge($points, $this->createEntityPoints($entityName, $config));

            // Index relationships
            $points = array_merge($points, $this->createRelationshipPoints($entityName, $config));

            // Index properties
            $points = array_merge($points, $this->createPropertyPoints($entityName, $config));

            // Index scopes
            $points = array_merge($points, $this->createScopePoints($entityName, $config));
        }

        // Embed and build upsert payload BEFORE touching the target collection.
        // If embedBatch fails, the existing index remains intact.
        if (!empty($points)) {
            $this->logger->info('semantic_selector', 'embedding points (target collection untouched until success)', [
                'points_count' => count($points),
            ]);

            try {
                $texts = array_column($points, 'text');
                $embeddings = $this->embeddingProvider->embedBatch($texts);

                $upsertPoints = [];
                foreach ($points as $i => $point) {
                    $upsertPoints[] = [
                        'id' => $point['id'],
                        'vector' => $embeddings[$i],
                        'metadata' => $point['metadata'],
                    ];
                }

                // All embedding succeeded — now safe to replace the target collection
                $this->logger->info('semantic_selector', 'embedding complete, replacing target collection', [
                    'collection' => $collectionName,
                    'points_to_upsert' => count($upsertPoints),
                ]);

                $this->vectorStore->deleteAll($collectionName);

                $chunkSize = 200;
                foreach (array_chunk($upsertPoints, $chunkSize) as $chunk) {
                    $this->vectorStore->upsertBatch($collectionName, $chunk);
                }

                $this->logger->info('semantic_selector', 'context indexing completed successfully', [
                    'points_indexed' => count($points),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('semantic_selector', 'context indexing failed, existing index preserved', [
                    'error' => $e->getMessage(),
                    'points_attempted' => count($points),
                ]);
                throw $e;
            }
        }

        return [
            'collection' => $collectionName,
            'indexed' => count($points),
            'errors' => $errors,
        ];
    }

    /**
     * Create points for entity indexing
     */
    private function createEntityPoints(string $entityName, array $config): array
    {
        $points = [];
        $metadata = $config['metadata'] ?? [];
        $shortName = class_basename($entityName);

        // Index entity label
        $text = $shortName;
        if (!empty($metadata['description'])) {
            $text .= ' - ' . $metadata['description'];
        }

        $points[] = [
            'id' => md5("entity:{$entityName}"),
            'text' => $text,
            'metadata' => [
                'type' => 'entity',
                'entity' => $entityName,
                'text' => $text,
            ],
        ];

        // Index aliases
        foreach ($metadata['aliases'] ?? [] as $alias) {
            $aliasText = "{$alias} ({$shortName})";
            $points[] = [
                'id' => md5("alias:{$entityName}:{$alias}"),
                'text' => $aliasText,
                'metadata' => [
                    'type' => 'entity',
                    'entity' => $entityName,
                    'text' => $aliasText,
                ],
            ];
        }

        return $points;
    }

    /**
     * Create points for relationship indexing
     */
    private function createRelationshipPoints(string $entityName, array $config): array
    {
        $points = [];
        $relationships = $config['relationships'] ?? [];
        $shortName = class_basename($entityName);

        foreach ($relationships as $relName => $relConfig) {
            $relType = $relConfig['type'] ?? $relName;
            $targetEntity = $relConfig['target'] ?? 'Unknown';
            $targetShort = class_basename($targetEntity);
            $foreignKey = $relConfig['foreign_key'] ?? null;
            $direction = $relConfig['direction'] ?? 'outgoing';

            $text = "{$shortName} {$relType} {$targetShort}";
            if (!empty($relConfig['description'])) {
                $text .= ' - ' . $relConfig['description'];
            }
            if ($foreignKey) {
                $text .= " (via {$foreignKey})";
            }

            $relKey = "{$shortName}-{$relType}-{$targetShort}";

            $points[] = [
                'id' => md5("rel:{$relKey}"),
                'text' => $text,
                'metadata' => [
                    'type' => 'relationship',
                    'relationship_key' => $relKey,
                    'from_entity' => $entityName,
                    'to_entity' => $targetEntity,
                    'relationship_type' => $relType,
                    'foreign_key' => $foreignKey,
                    'direction' => $direction,
                    'text' => $text,
                ],
            ];
        }

        return $points;
    }

    /**
     * Create points for property indexing
     */
    private function createPropertyPoints(string $entityName, array $config): array
    {
        $points = [];
        $graphConfig = $config['graph'] ?? [];
        $properties = $graphConfig['properties'] ?? [];
        $propertyDescriptions = $config['metadata']['property_descriptions'] ?? [];
        $shortName = class_basename($entityName);

        foreach ($properties as $property) {
            $text = "{$shortName}.{$property}";
            if (!empty($propertyDescriptions[$property])) {
                $text .= ' - ' . $propertyDescriptions[$property];
            }

            $points[] = [
                'id' => md5("prop:{$entityName}:{$property}"),
                'text' => $text,
                'metadata' => [
                    'type' => 'property',
                    'entity' => $entityName,
                    'property' => $property,
                    'text' => $text,
                ],
            ];
        }

        return $points;
    }

    /**
     * Create points for scope indexing
     */
    private function createScopePoints(string $entityName, array $config): array
    {
        $points = [];
        $scopes = $config['metadata']['scopes'] ?? [];
        $shortName = class_basename($entityName);

        foreach ($scopes as $scopeName => $scopeConfig) {
            if (is_numeric($scopeName)) {
                continue;
            }

            // Index scope concept
            if (!empty($scopeConfig['concept'])) {
                $points[] = [
                    'id' => md5("scope:{$entityName}:{$scopeName}:concept"),
                    'text' => $scopeConfig['concept'],
                    'metadata' => [
                        'type' => 'scope',
                        'entity' => $entityName,
                        'scope_key' => $scopeName,
                        'text' => $scopeConfig['concept'],
                    ],
                ];
            }

            // Index scope examples
            foreach ($scopeConfig['examples'] ?? [] as $example) {
                $points[] = [
                    'id' => md5("scope:{$entityName}:{$scopeName}:{$example}"),
                    'text' => $example,
                    'metadata' => [
                        'type' => 'scope',
                        'entity' => $entityName,
                        'scope_key' => $scopeName,
                        'text' => $example,
                    ],
                ];
            }
        }

        return $points;
    }

    /**
     * Keyword-based selection fallback
     */
    private function keywordBasedSelection(string $question, array $entityConfigs): array
    {
        $questionLower = strtolower($question);
        $selectedEntities = [];
        $selectedRelationships = [];
        $selectedScopes = [];

        foreach ($entityConfigs as $entityName => $config) {
            $metadata = $config['metadata'] ?? [];
            $shortName = class_basename($entityName);
            $isRelevant = false;

            // Check entity name
            if (stripos($question, $shortName) !== false) {
                $isRelevant = true;
            }

            // Check aliases
            foreach ($metadata['aliases'] ?? [] as $alias) {
                if (stripos($question, $alias) !== false) {
                    $isRelevant = true;
                    break;
                }
            }

            // Check scope names
            foreach ($metadata['scopes'] ?? [] as $scopeName => $scopeConfig) {
                if (is_numeric($scopeName)) {
                    continue;
                }

                if (stripos($question, $scopeName) !== false) {
                    $isRelevant = true;
                    $selectedScopes[$scopeName] = [
                        'score' => 0.5,
                        'entity' => $shortName,
                        'matched_text' => $scopeName,
                    ];
                }
            }

            if ($isRelevant) {
                $selectedEntities[$entityName] = [
                    'score' => 0.5,
                    'matched_text' => 'keyword match',
                    'config' => $config,
                ];

                // Include relationships for this entity
                foreach ($config['relationships'] ?? [] as $relName => $relConfig) {
                    $relType = $relConfig['type'] ?? $relName;
                    $targetEntity = $relConfig['target'] ?? 'Unknown';
                    $targetShort = class_basename($targetEntity);
                    $relKey = "{$shortName}-{$relType}-{$targetShort}";

                    $selectedRelationships[$relKey] = [
                        'score' => 0.4,
                        'from_entity' => $shortName,
                        'to_entity' => $targetShort,
                        'type' => $relType,
                        'foreign_key' => $relConfig['foreign_key'] ?? null,
                        'direction' => $relConfig['direction'] ?? 'outgoing',
                    ];
                }
            }
        }

        return [
            'entities' => $selectedEntities,
            'relationships' => $selectedRelationships,
            'scopes' => $selectedScopes,
            'selection_method' => 'keyword',
        ];
    }

    /**
     * For each selected entity, check its configured relationships and auto-include
     * connected entities that meet a relaxed threshold.
     */
    private function expandEntitiesThroughRelationships(
        array &$selectedEntities,
        array &$selectedRelationships,
        array &$seenEntities,
        array $entityConfigs,
        float $threshold
    ): void {
        $newEntities = [];

        foreach ($selectedEntities as $entityName => $entityInfo) {
            $config = $entityConfigs[$entityName] ?? null;
            if (!$config) continue;

            $relationships = $config['graph']['relationships'] ?? [];
            foreach ($relationships as $relConfig) {
                $targetEntity = $relConfig['target_label'] ?? null;
                if (!$targetEntity || isset($seenEntities[$targetEntity])) continue;

                $score = ($entityInfo['score'] ?? 0) * 0.6;
                if ($score >= $threshold * 0.8) {
                    $seenEntities[$targetEntity] = true;
                    $newEntities[$targetEntity] = [
                        'score' => $score,
                        'matched_text' => "Connected to " . class_basename($entityName) . " via " . ($relConfig['type'] ?? 'relationship'),
                        'config' => $entityConfigs[$targetEntity] ?? null,
                    ];

                    $this->logger->debug('semantic_selector', 'entity_expanded', [
                        'from' => class_basename($entityName),
                        'to' => class_basename($targetEntity),
                        'via' => $relConfig['type'] ?? '?',
                        'score' => round($score, 4),
                    ]);
                }
            }
        }

        $selectedEntities = array_merge($selectedEntities, $newEntities);
    }

    /**
     * Check if index exists
     */
    private function indexExists(string $collectionName): bool
    {
        try {
            $info = $this->vectorStore->getCollectionInfo($collectionName);
            return ($info['points_count'] ?? 0) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

}
