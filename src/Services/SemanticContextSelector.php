<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Domain\Traits\ResolvesEntityConfigKey;
use Condoedge\Ai\DTOs\SelectedEntity;
use Condoedge\Ai\DTOs\SelectedRelationship;
use Condoedge\Ai\DTOs\SelectedScope;
use Condoedge\Ai\DTOs\SemanticSelectionResult;
use Condoedge\Ai\DTOs\VectorSearchResult;
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
    ): SemanticSelectionResult {
        $threshold = $options['threshold'] ?? self::DEFAULT_THRESHOLD;
        $topK = $options['top_k'] ?? self::DEFAULT_TOP_K;
        $collectionName = $this->config['collection'] ?? $this->collectionName;

        // Fall back to keyword-based selection when the semantic index is unavailable.
        if (!$this->indexExists($collectionName)) {
            $this->logger->fallback('semantic_selector', 'vector_search', 'keyword_matching', 'semantic index not available');
            return $this->keywordBasedSelection($question, $entityConfigs);
        }

        $questionEmbedding = $this->embeddingProvider->embed($question);

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

        $selectedEntities = [];
        $selectedRelationships = [];
        $selectedScopes = [];
        $seenEntities = [];
        $seenRelationships = [];

        foreach ($results as $rawResult) {
            $result = new VectorSearchResult($rawResult);

            if ($result->score < $threshold) {
                $this->logger->debug('semantic_selector', 'result below threshold, rejected', [
                    'type' => $result->type,
                    'entity' => $result->entityName ?? $result->relationshipKey,
                    'score' => $result->score,
                    'threshold' => $threshold,
                ]);
                continue;
            }

            switch ($result->type) {
                case 'entity':
                    if ($result->entityName && !isset($seenEntities[$result->entityName])) {
                        $seenEntities[$result->entityName] = true;
                        $selectedEntities[$result->entityName] = new SelectedEntity(
                            score: $result->score,
                            matchedText: $result->text,
                            config: $entityConfigs[$result->entityName] ?? null,
                        );
                    }
                    break;

                case 'relationship':
                    if ($result->relationshipKey && !isset($seenRelationships[$result->relationshipKey])) {
                        $seenRelationships[$result->relationshipKey] = true;
                        $selectedRelationships[$result->relationshipKey] = new SelectedRelationship(
                            score: $result->score,
                            fromEntity: $result->fromEntity,
                            toEntity: $result->toEntity,
                            type: $result->relationshipType,
                            foreignKey: $result->foreignKey,
                            direction: $result->direction,
                        );

                        // Also include related entities
                        foreach ([$result->fromEntity, $result->toEntity] as $relatedEntity) {
                            if ($relatedEntity && !isset($seenEntities[$relatedEntity])) {
                                $fullName = $this->findFullEntityName($relatedEntity, $entityConfigs);
                                if ($fullName) {
                                    $seenEntities[$fullName] = true;
                                    $selectedEntities[$fullName] = new SelectedEntity(
                                        score: $result->score * 0.8,
                                        matchedText: "Related via {$result->relationshipKey}",
                                        config: $entityConfigs[$fullName] ?? null,
                                    );
                                }
                            }
                        }
                    }
                    break;

                case 'scope':
                    if ($result->scopeKey && $result->entityName) {
                        $selectedScopes[$result->scopeKey] = new SelectedScope(
                            score: $result->score,
                            entity: $result->entityName,
                            matchedText: $result->text,
                        );

                        $fullName = $this->findFullEntityName($result->entityName, $entityConfigs);
                        if ($fullName && !isset($seenEntities[$fullName])) {
                            $seenEntities[$fullName] = true;
                            $selectedEntities[$fullName] = new SelectedEntity(
                                score: $result->score,
                                matchedText: "Has scope {$result->scopeKey}",
                                config: $entityConfigs[$fullName] ?? null,
                            );
                        }
                    }
                    break;

                case 'property':
                    if ($result->entityName && $result->property) {
                        $fullName = $this->findFullEntityName($result->entityName, $entityConfigs);
                        if ($fullName && !isset($seenEntities[$fullName])) {
                            $seenEntities[$fullName] = true;
                            $selectedEntities[$fullName] = new SelectedEntity(
                                score: $result->score * 0.9,
                                matchedText: "Has property {$result->property}",
                                config: $entityConfigs[$fullName] ?? null,
                            );
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

        // Expand through user entity paths when possessive language detected (from QU or fallback)
        $isPossessive = $options['query_understanding']['is_possessive']
            ?? $options['is_possessive']
            ?? false;
        if ($isPossessive) {
            $this->expandThroughUserEntityPaths(
                $selectedEntities,
                $selectedRelationships,
                $seenEntities,
                $entityConfigs
            );
        }

        uasort($selectedEntities, fn($a, $b) => ($b->score) <=> ($a->score));
        uasort($selectedRelationships, fn($a, $b) => ($b->score) <=> ($a->score));
        uasort($selectedScopes, fn($a, $b) => ($b->score) <=> ($a->score));

        $this->logger->debug('semantic_selector', 'final selection summary', [
            'entities' => array_map(fn($e) => $e->score, $selectedEntities),
            'relationships' => array_keys($selectedRelationships),
            'scopes' => array_map(fn($s) => $s->score, $selectedScopes),
        ]);

        // Limit scopes to top 3 by score to avoid overwhelming the LLM
        $maxScopes = (int) config('ai.scope_matching.top_k', 3);
        $selectedScopes = array_slice($selectedScopes, 0, $maxScopes, true);

        return new SemanticSelectionResult(
            entities: $selectedEntities,
            relationships: $selectedRelationships,
            scopes: $selectedScopes,
            selectionMethod: 'semantic',
        );
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

        // Index entity-level examples (question-style text that matches queries better)
        foreach ($metadata['examples'] ?? [] as $example) {
            $points[] = [
                'id' => md5("entity_example:{$entityName}:{$example}"),
                'text' => $example,
                'metadata' => [
                    'type' => 'entity',
                    'entity' => $entityName,
                    'text' => $example,
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
    private function keywordBasedSelection(string $question, array $entityConfigs): SemanticSelectionResult
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
                    $selectedScopes[$scopeName] = new SelectedScope(
                        score: 0.5,
                        entity: $shortName,
                        matchedText: $scopeName,
                    );
                }
            }

            if ($isRelevant) {
                $selectedEntities[$entityName] = new SelectedEntity(
                    score: 0.5,
                    matchedText: 'keyword match',
                    config: $config,
                );

                // Include relationships for this entity
                foreach ($config['relationships'] ?? [] as $relName => $relConfig) {
                    $relType = $relConfig['type'] ?? $relName;
                    $targetEntity = $relConfig['target'] ?? 'Unknown';
                    $targetShort = class_basename($targetEntity);
                    $relKey = "{$shortName}-{$relType}-{$targetShort}";

                    $selectedRelationships[$relKey] = new SelectedRelationship(
                        score: 0.4,
                        fromEntity: $shortName,
                        toEntity: $targetShort,
                        type: $relType,
                        foreignKey: $relConfig['foreign_key'] ?? null,
                        direction: $relConfig['direction'] ?? 'outgoing',
                    );
                }
            }
        }

        return new SemanticSelectionResult(
            entities: $selectedEntities,
            relationships: $selectedRelationships,
            scopes: $selectedScopes,
            selectionMethod: 'keyword',
        );
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

                $score = $entityInfo->score * 0.6;
                if ($score >= $threshold * 0.8) {
                    $seenEntities[$targetEntity] = true;
                    $newEntities[$targetEntity] = new SelectedEntity(
                        score: $score,
                        matchedText: "Connected to " . class_basename($entityName) . " via " . ($relConfig['type'] ?? 'relationship'),
                        config: $entityConfigs[$targetEntity] ?? null,
                    );

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
     * Expand context with entities from user_entity_paths when possessive language is detected.
     * This ensures that when a user asks "my unit", the Contact, ContactUnit entities
     * needed for the traversal are included in the context.
     */
    private function expandThroughUserEntityPaths(
        array &$selectedEntities,
        array &$selectedRelationships,
        array &$seenEntities,
        array $entityConfigs
    ): void {
        $paths = config('ai.user_entity_paths', []);

        if (empty($paths)) {
            return;
        }

        // For each selected entity, check if there's a user entity path for it
        $entitiesToAdd = [];
        foreach ($selectedEntities as $entityName => $entityInfo) {
            $shortName = class_basename($entityName);

            if (!isset($paths[$shortName])) {
                continue;
            }

            $pathConfig = $paths[$shortName];
            $pathEntities = $pathConfig['entities'] ?? [];
            $pathRelationships = $pathConfig['relationships'] ?? [];

            // Add all entities from the path
            foreach ($pathEntities as $pathEntityLabel) {
                if (isset($seenEntities[$pathEntityLabel])) {
                    continue;
                }

                $fullName = $this->findFullEntityName($pathEntityLabel, $entityConfigs);
                if (!$fullName) {
                    continue;
                }

                $seenEntities[$pathEntityLabel] = true;
                $seenEntities[$fullName] = true;
                $entitiesToAdd[$fullName] = new SelectedEntity(
                    score: $entityInfo->score,
                    matchedText: "User ownership path to {$shortName}",
                    config: $entityConfigs[$fullName] ?? null,
                );

                $this->logger->debug('semantic_selector', 'entity_added_via_user_path', [
                    'entity' => $pathEntityLabel,
                    'target' => $shortName,
                ]);
            }

            // Add relationships from the path
            foreach ($pathRelationships as $relType) {
                $relKey = "user_path_{$relType}";
                if (!isset($selectedRelationships[$relKey])) {
                    $selectedRelationships[$relKey] = new SelectedRelationship(
                        score: $entityInfo->score,
                        fromEntity: '',
                        toEntity: '',
                        type: $relType,
                        direction: 'outgoing',
                    );
                }
            }
        }

        $selectedEntities = array_merge($selectedEntities, $entitiesToAdd);
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
