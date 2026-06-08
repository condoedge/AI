<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Domain\Traits\ResolvesEntityConfigKey;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\DTOs\SelectedEntity;
use Condoedge\Ai\DTOs\SemanticSelectionResult;
use Condoedge\Ai\Support\AiLogger;

/**
 * ContextRetriever
 *
 * Implements Retrieval-Augmented Generation (RAG) by combining multiple
 * context sources to support natural language query generation.
 *
 * This service provides rich context for LLMs by aggregating:
 * - Similar past questions from vector search (few-shot learning)
 * - Graph database schema information (structural understanding)
 * - Example entities for concrete data context
 *
 * **Semantic Context Selection:**
 * When semantic context is enabled (via SemanticContextSelector), the service
 * uses vector similarity to determine which entities, relationships, and scopes
 * are relevant to the question. This significantly reduces token consumption
 * by only including relevant context instead of all available metadata.
 *
 * Architecture Principles:
 * 1. Interface-based dependency injection
 * 2. Separation of concerns (vector vs graph operations)
 * 3. Graceful degradation on partial failures
 * 4. Comprehensive error handling and status reporting
 * 5. Semantic-first context selection for token efficiency
 *
 * Example Usage:
 * ```php
 * $retriever = new ContextRetriever(
 *     vectorStore: new QdrantStore($config),
 *     graphStore: new Neo4jStore($config),
 *     embeddingProvider: new OpenAiEmbeddingProvider($config)
 * );
 *
 * $context = $retriever->retrieveContext(
 *     "Show teams with most active members",
 *     ['limit' => 10, 'includeSchema' => true]
 * );
 *
 * // Returns:
 * [
 *     'similar_queries' => [
 *         ['question' => 'List all teams', 'query' => 'MATCH (t:Team)...', 'score' => 0.89],
 *     ],
 *     'graph_schema' => [
 *         'labels' => ['Team', 'Person'],
 *         'relationships' => ['MEMBER_OF'],
 *         'properties' => ['id', 'name', 'created_at']
 *     ],
 *     'relevant_entities' => [
 *         'Team' => [['id' => 1, 'name' => 'Alpha Team']],
 *         'Person' => [['id' => 1, 'name' => 'John Doe']]
 *     ]
 * ]
 * ```
 *
 * @package Condoedge\Ai\Services
 */
class ContextRetriever implements ContextRetrieverInterface
{
    use ResolvesEntityConfigKey;

    /**
     * Default options for context retrieval
     */
    private const DEFAULT_COLLECTION = 'questions';
    private const DEFAULT_LIMIT = 5;
    private const DEFAULT_EXAMPLES_PER_LABEL = 2;
    private const DEFAULT_SCORE_THRESHOLD = 0.0;

    /**
     * Entity configurations loaded from config/entities.php
     */
    private array $entityConfigs = [];

    /**
     * Semantic matcher for scope detection
     */
    private ?ScopeSemanticMatcher $scopeMatcher = null;

    /**
     * Semantic context selector for intelligent context selection
     */
    private ?SemanticContextSelector $contextSelector = null;

    /**
     * Query understanding service for pre-processing questions
     */
    private ?QueryUnderstandingService $queryUnderstanding = null;

    /**
     * Create context retriever with injected dependencies
     *
     * All dependencies are interfaces to ensure:
     * - Service can work with any vector store implementation (Qdrant, Pinecone, etc.)
     * - Service can work with any graph store implementation (Neo4j, ArangoDB, etc.)
     * - Service can work with any embedding provider (OpenAI, Anthropic, etc.)
     * - Service is fully testable with mocks/stubs
     *
     * @param VectorStoreInterface $vectorStore Vector database for similarity search
     * @param GraphStoreInterface $graphStore Graph database for schema/entity queries
     * @param EmbeddingProviderInterface $embeddingProvider Text-to-vector embedding service
     * @param AiLogger $logger Logger for tracing and diagnostics
     * @param array|null $entityConfigs Optional entity configurations (defaults to config/entities.php)
     * @param ScopeSemanticMatcher|null $scopeMatcher Optional semantic matcher for scope detection
     * @param SemanticContextSelector|null $contextSelector Optional semantic context selector
     */
    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly GraphStoreInterface $graphStore,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly AiLogger $logger,
        ?array $entityConfigs = null,
        ?ScopeSemanticMatcher $scopeMatcher = null,
        ?SemanticContextSelector $contextSelector = null
    ) {
        // Load entity configs from config file or use provided configs
        $this->entityConfigs = $entityConfigs ?? $this->loadEntityConfigs();
        $this->scopeMatcher = $scopeMatcher;
        $this->contextSelector = $contextSelector;
    }

    /**
     * Set the scope semantic matcher
     *
     * @param ScopeSemanticMatcher $scopeMatcher
     * @return self
     */
    public function setScopeMatcher(ScopeSemanticMatcher $scopeMatcher): self
    {
        $this->scopeMatcher = $scopeMatcher;
        return $this;
    }

    /**
     * Set the semantic context selector
     *
     * @param SemanticContextSelector $contextSelector
     * @return self
     */
    public function setContextSelector(SemanticContextSelector $contextSelector): self
    {
        $this->contextSelector = $contextSelector;
        return $this;
    }

    /**
     * Set the query understanding service
     *
     * @param QueryUnderstandingService $qu
     * @return self
     */
    public function setQueryUnderstanding(QueryUnderstandingService $qu): self
    {
        $this->queryUnderstanding = $qu;
        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * Retrieves comprehensive context by aggregating multiple sources:
     * 1. Vector similarity search for related past queries
     * 2. Graph schema information (labels, relationships, properties)
     * 3. Example entities to provide concrete data context
     *
     * The service implements graceful degradation: if one source fails,
     * others still provide partial context. Errors are collected but
     * non-fatal, allowing the caller to decide how to proceed.
     *
     * @param string $question Natural language question from user
     * @param array $options Configuration options:
     *                       - collection: Vector collection name (default: 'questions')
     *                       - limit: Max similar queries to retrieve (default: 5)
     *                       - includeSchema: Include graph schema (default: true)
     *                       - includeExamples: Include sample entities (default: true)
     *                       - examplesPerLabel: Number of examples per label (default: 2)
     *                       - scoreThreshold: Minimum similarity score (default: 0.0)
     *                       - useSemanticSelection: Use semantic context selection (default: true)
     *
     * @return PipelineContext Typed context object containing all pipeline data
     */
    public function retrieveContext(string $question, array $options = []): PipelineContext
    {
        $this->logger->startTrace('context_retrieval');
        $this->logger->startTimer('total');

        if (empty(trim($question))) {
            throw new \InvalidArgumentException('Question cannot be empty');
        }

        $collection = $options['collection'] ?? self::DEFAULT_COLLECTION;
        $limit = $options['limit'] ?? self::DEFAULT_LIMIT;
        $includeSchema = $options['includeSchema'] ?? true;
        $includeExamples = $options['includeExamples'] ?? true;
        $examplesPerLabel = $options['examplesPerLabel'] ?? self::DEFAULT_EXAMPLES_PER_LABEL;
        $scoreThreshold = $options['scoreThreshold'] ?? self::DEFAULT_SCORE_THRESHOLD;
        $useSemanticSelection = $options['useSemanticSelection'] ?? true;

        $ctx = new PipelineContext();

        // STEP 1: Query Understanding (always runs if available)
        if ($this->queryUnderstanding !== null) {
            try {
                $entityCatalog = QueryUnderstandingService::buildEntityCatalog($this->entityConfigs);
                $ctx->qu = $this->queryUnderstanding->analyze($question, $entityCatalog);

                $this->logger->info('context_retriever', 'Query understanding complete', [
                    'entity_types' => $ctx->qu->entityTypes,
                    'question_type' => $ctx->qu->questionType,
                    'intent' => $ctx->qu->intent,
                    'is_possessive' => $ctx->qu->isPossessive,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('context_retriever', 'Query understanding failed: ' . $e->getMessage());
                $ctx->addError('Query understanding failed: ' . $e->getMessage());
                $ctx->qu = QueryUnderstandingResult::empty($question);
            }
        } else {
            $ctx->qu = QueryUnderstandingResult::empty($question);
        }

        // STEP 2: Semantic context selection
        $conversationFocusedEntity = $options['conversation_context']['focused_entity'] ?? null;

        // Use the Query-Understanding rewrite (when produced) as the retrieval query
        // for ALL graph/entity grounding — not just file search. The normalized
        // searchQuery expands abbreviations and strips filler, improving recall on
        // entity selection, similar-query search, and the entity-label fallback.
        $retrievalQuery = $this->retrievalQuery($ctx, $question);

        if ($useSemanticSelection && $this->contextSelector !== null) {
            try {
                $ctx->semanticSelection = $this->contextSelector->selectRelevantContext(
                    $retrievalQuery,
                    $this->entityConfigs,
                    [
                        'threshold' => config('ai.semantic_context.threshold', 0.65),
                        'top_k' => config('ai.semantic_context.top_k', 10),
                        'is_possessive' => $ctx->qu->isPossessive,
                        'query_understanding' => [
                            'entity_types' => $ctx->qu->entityTypes,
                            'is_possessive' => $ctx->qu->isPossessive,
                        ],
                    ]
                );

                // Force-include conversation's focused entity for follow-ups
                if ($conversationFocusedEntity) {
                    $focusFullName = $this->findFullEntityName($conversationFocusedEntity, $this->entityConfigs);
                    if ($focusFullName && !isset($ctx->semanticSelection->entities[$focusFullName])) {
                        $ctx->semanticSelection->entities[$focusFullName] = new SelectedEntity(
                            score: config('ai.semantic_context.conversation_focus_score', 0.55),
                            matchedText: "Conversation focus: '{$conversationFocusedEntity}'",
                            config: $this->entityConfigs[$focusFullName] ?? null,
                        );
                    }
                }

                // Merge QU entity_types into semantic selection
                foreach ($ctx->qu->entityTypes as $label) {
                    $fullName = $this->findFullEntityName($label, $this->entityConfigs);
                    if ($fullName && !isset($ctx->semanticSelection->entities[$fullName])) {
                        $ctx->semanticSelection->entities[$fullName] = new SelectedEntity(
                            score: config('ai.semantic_context.qu_entity_score', 0.8),
                            matchedText: "Query Understanding: '{$label}'",
                            config: $this->entityConfigs[$fullName] ?? null,
                        );
                    }
                }

                // Build selection info
                $selectedEntities = array_map(
                    fn($entityName) => class_basename($entityName),
                    array_keys($ctx->semanticSelection->entities)
                );
                $ctx->selectionInfo = [
                    'method' => $ctx->semanticSelection->selectionMethod,
                    'selected_entities' => $selectedEntities,
                    'entities_selected' => count($ctx->semanticSelection->entities),
                    'relationships_selected' => count($ctx->semanticSelection->relationships),
                    'scopes_selected' => count($ctx->semanticSelection->scopes),
                    'qu_used' => true,
                ];

                $this->logger->info('context_retriever', 'Semantic context selected', [
                    'method' => $ctx->semanticSelection->selectionMethod,
                    'entity_count' => count($ctx->semanticSelection->entities),
                    'scope_count' => count($ctx->semanticSelection->scopes),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('context_retriever', 'Semantic context selection failed: ' . $e->getMessage());
                $this->logger->fallback('context_retriever', 'semantic_selection', 'full_schema', $e->getMessage());
                $ctx->addError('Semantic context selection failed: ' . $e->getMessage());
            }
        }

        // STEP 3: Similar queries
        try {
            $ctx->similarQueries = $this->searchSimilarQueries($retrievalQuery, $collection, $limit, $scoreThreshold);
        } catch (\Exception $e) {
            $this->logger->error('context_retriever', 'Vector search failed: ' . $e->getMessage());
            $ctx->addError('Vector search failed: ' . $e->getMessage());
        }

        // STEP 4: Graph schema
        if ($includeSchema) {
            try {
                $fullSchema = $this->getGraphSchema();

                if (!empty($ctx->semanticSelection->entities)) {
                    $ctx->graphSchema = $this->filterSchemaByRelevance($fullSchema, $ctx->semanticSelection);
                } else {
                    $relevantLabels = $this->findRelevantEntityLabels($retrievalQuery);
                    if (!empty($relevantLabels)) {
                        $ctx->graphSchema = $this->filterSchemaByLabels($fullSchema, $relevantLabels);
                        $ctx->selectionInfo = [
                            'method' => 'semantic_entities_fallback',
                            'selected_entities' => $relevantLabels,
                            'entities_selected' => count($relevantLabels),
                        ];
                    } else {
                        $ctx->graphSchema = $fullSchema;
                    }
                }
                $ctx->graphSchema['all_relationship_types'] = $fullSchema['relationships'] ?? [];
            } catch (\Exception $e) {
                $this->logger->error('context_retriever', 'Schema retrieval failed: ' . $e->getMessage());
                $ctx->addError('Schema retrieval failed: ' . $e->getMessage());
            }
        }

        // STEP 5: Example entities
        if ($includeExamples) {
            $labelsToQuery = [];

            if (!empty($ctx->semanticSelection->entities)) {
                foreach ($ctx->semanticSelection->entities as $entityName => $entityInfo) {
                    $labelsToQuery[] = class_basename($entityName);
                }
            } elseif (!empty($ctx->graphSchema['labels'])) {
                $labelsToQuery = $ctx->graphSchema['labels'];
            }

            if (!empty($labelsToQuery)) {
                $ctx->relevantEntities = $this->retrieveExampleEntities(
                    $labelsToQuery,
                    $examplesPerLabel,
                    $ctx->errors
                );
            }
        }

        // STEP 6: Entity metadata
        try {
            if (!empty($ctx->semanticSelection->entities)) {
                $ctx->entityMetadata = $this->buildMetadataFromSemanticSelection($question, $ctx->semanticSelection);
            } else {
                $ctx->entityMetadata = $this->getEntityMetadata($question);
            }
        } catch (\Exception $e) {
            $this->logger->error('context_retriever', 'Entity metadata retrieval failed: ' . $e->getMessage());
            $ctx->addError('Entity metadata retrieval failed: ' . $e->getMessage());
        }

        // STEP 7: Quality assessment
        $ctx->quality = $this->assessContextQuality($ctx);

        $totalMs = $this->logger->stopTimer('total');
        $this->logger->info('context_retriever', 'Context retrieval complete', [
            'duration_ms' => $totalMs,
            'has_similar_queries' => !empty($ctx->similarQueries),
            'has_schema' => !empty($ctx->graphSchema),
            'has_metadata' => !empty($ctx->entityMetadata),
            'errors_count' => count($ctx->errors),
        ]);

        return $ctx;
    }

    /**
     * Filter schema based on semantic relevance
     *
     * Only includes labels, relationships, and properties that are relevant
     * to the question based on semantic matching.
     *
     * @param array $fullSchema Complete graph schema
     * @param SemanticSelectionResult $semanticContext Semantic selection results
     * @return array Filtered schema
     */
    private function filterSchemaByRelevance(array $fullSchema, SemanticSelectionResult $semanticContext): array
    {
        $relevantLabels = [];
        $relevantRelationships = [];
        $relevantProperties = [];
        $relevantPropertyKeys = [];

        // Extract relevant labels from selected entities
        foreach ($semanticContext->entities as $entityName => $entityInfo) {
            $shortName = class_basename($entityName);
            $relevantLabels[] = $shortName;

            // Include properties for this entity
            if (isset($fullSchema['properties'][$shortName])) {
                $relevantProperties[$shortName] = $fullSchema['properties'][$shortName];
                // Also add to property keys list for flat access
                foreach ($fullSchema['properties'][$shortName] as $prop) {
                    $relevantPropertyKeys[] = $prop;
                }
            }
        }

        // Extract relevant relationships
        foreach ($semanticContext->relationships as $relKey => $relInfo) {
            $relevantRelationships[] = $relInfo->type ?: $relKey;

            // Ensure connected entities are included
            if (!empty($relInfo->fromEntity) && !in_array($relInfo->fromEntity, $relevantLabels)) {
                $relevantLabels[] = $relInfo->fromEntity;
                // Also include properties for connected entities
                if (isset($fullSchema['properties'][$relInfo->fromEntity])) {
                    $relevantProperties[$relInfo->fromEntity] = $fullSchema['properties'][$relInfo->fromEntity];
                    foreach ($fullSchema['properties'][$relInfo->fromEntity] as $prop) {
                        $relevantPropertyKeys[] = $prop;
                    }
                }
            }
            if (!empty($relInfo->toEntity) && !in_array($relInfo->toEntity, $relevantLabels)) {
                $relevantLabels[] = $relInfo->toEntity;
                // Also include properties for connected entities
                if (isset($fullSchema['properties'][$relInfo->toEntity])) {
                    $relevantProperties[$relInfo->toEntity] = $fullSchema['properties'][$relInfo->toEntity];
                    foreach ($fullSchema['properties'][$relInfo->toEntity] as $prop) {
                        $relevantPropertyKeys[] = $prop;
                    }
                }
            }
        }

        // Filter to only include relevant items
        $filteredLabels = array_values(array_intersect(
            $fullSchema['labels'] ?? [],
            $relevantLabels
        ));

        $filteredRelationships = !empty($relevantRelationships)
            ? array_values(array_intersect(
                $fullSchema['relationships'] ?? [],
                $relevantRelationships
            ))
            : [];

        // Deduplicate property keys
        $relevantPropertyKeys = array_values(array_unique($relevantPropertyKeys));

        return [
            'labels' => $filteredLabels,
            'relationships' => $filteredRelationships,
            'properties' => $relevantProperties,
            'propertyKeys' => $relevantPropertyKeys,
        ];
    }

    /**
     * Build metadata from semantic selection results
     *
     * @param string $question User's question
     * @param SemanticSelectionResult $semanticContext Semantic selection results
     * @return array Entity metadata
     */
    private function buildMetadataFromSemanticSelection(string $question, SemanticSelectionResult $semanticContext): array
    {
        $detectedEntities = [];
        $detectedScopes = [];
        $entityMetadata = [];

        // Process selected entities
        foreach ($semanticContext->entities as $entityName => $entityInfo) {
            $detectedEntities[] = $entityName;

            if (!empty($entityInfo->config['metadata'])) {
                $entityMetadata[$entityName] = $entityInfo->config['metadata'];
            } elseif (isset($this->entityConfigs[$entityName]['metadata'])) {
                $entityMetadata[$entityName] = $this->entityConfigs[$entityName]['metadata'];
            }
        }

        // Process scopes: first from semantic selection, then entity-aware matching.
        // Semantic selection scopes come from the context_index (cross-entity),
        // but we also need to check scopes on the SELECTED entities specifically,
        // matching the question against their scope examples for higher accuracy.

        // Step 1: Semantic selection scopes (from context_index)
        foreach ($semanticContext->scopes as $scopeName => $scopeInfo) {
            $entityName = $scopeInfo->entity;
            $fullEntityName = $this->findFullEntityName($entityName, $this->entityConfigs);

            $scopeConfig = [];
            if ($fullEntityName && isset($this->entityConfigs[$fullEntityName]['metadata']['scopes'][$scopeName])) {
                $scopeConfig = $this->entityConfigs[$fullEntityName]['metadata']['scopes'][$scopeName];
            }

            $detectedScopes[$scopeName] = $this->buildScopeData(
                $scopeName,
                array_merge($scopeConfig, [
                    'match_score' => $scopeInfo->score,
                    'match_type' => 'semantic',
                    'matched_example' => $scopeInfo->matchedText,
                ]),
                $fullEntityName ?: $entityName
            );

            if ($fullEntityName && !in_array($fullEntityName, $detectedEntities)) {
                $detectedEntities[] = $fullEntityName;
                if (isset($this->entityConfigs[$fullEntityName]['metadata'])) {
                    $entityMetadata[$fullEntityName] = $this->entityConfigs[$fullEntityName]['metadata'];
                }
            }
        }

        // Note: Entity-aware scope matching was removed to avoid hardcoded stop word lists.
        // Scope matching relies on the semantic context_index and scope_examples collections.
        // The QU layer helps by identifying entity types, which filters scopes to relevant entities.

        return [
            'detected_entities' => array_values(array_unique($detectedEntities)),
            'entity_metadata' => $entityMetadata,
            'detected_scopes' => $detectedScopes,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Performs semantic similarity search to find previously answered
     * questions similar to the input question. This enables few-shot
     * learning where the LLM can see examples of how similar questions
     * were answered.
     *
     * Process:
     * 1. Convert question text to embedding vector
     * 2. Search vector store for similar embeddings
     * 3. Format results with question, query, score, metadata
     *
     * Results are sorted by similarity score (highest first).
     *
     * @param string $question Question to search for
     * @param string $collection Vector collection to search (default: 'questions')
     * @param int $limit Maximum number of results (default: 5)
     *
     * @return array Similar questions with structure:
     *               [
     *                   [
     *                       'question' => 'Original question text',
     *                       'query' => 'Associated Cypher query',
     *                       'score' => 0.89, // Similarity score (0-1)
     *                       'metadata' => [...] // Additional payload data
     *                   ],
     *                   ...
     *               ]
     *
     * @throws \RuntimeException If embedding generation fails
     * @throws \RuntimeException If vector search fails
     */
    public function searchSimilar(
        string $question,
        string $collection = 'questions',
        int $limit = 5
    ): array {
        return $this->searchSimilarQueries($question, $collection, $limit, 0.0);
    }

    /**
     * {@inheritDoc}
     *
     * Retrieves graph database schema information including:
     * - Node labels (entity types like Team, Person, Customer)
     * - Relationship types (connections like MEMBER_OF, PURCHASED)
     * - Property keys (attributes like id, name, email)
     *
     * This structural information helps LLMs understand what data
     * structures are available when generating queries.
     *
     * @return array Schema structure with keys:
     *               - labels: Array of node label strings
     *               - relationships: Array of relationship type strings
     *               - properties: Array of property key strings
     *
     * @throws \RuntimeException If schema retrieval fails
     */
    public function getGraphSchema(): array
    {
        $schema = $this->graphStore->getSchema();

        // Get properties grouped by label from entity configs
        $propertiesByLabel = $this->getPropertiesByLabel();

        // Normalize schema structure to consistent format
        return [
            'labels' => $schema['labels'] ?? [],
            'relationships' => $schema['relationshipTypes'] ?? $schema['relationships'] ?? [],
            'properties' => $propertiesByLabel, // Now structured by label
            'propertyKeys' => $schema['propertyKeys'] ?? $schema['properties'] ?? [], // Keep flat list too
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Retrieves sample entities of a specific label to show the LLM
     * what actual data looks like. This provides concrete context about:
     * - What properties entities actually have
     * - What values look like (strings, numbers, dates, etc.)
     * - Data structure and format patterns
     *
     * Uses Cypher MATCH query to retrieve random sample entities.
     *
     * @param string $label Entity label/type to get examples for
     * @param int $limit Maximum number of examples (default: 3)
     *
     * @return array Sample entities as associative arrays:
     *               [
     *                   ['id' => 1, 'name' => 'Alpha Team', 'created_at' => '2024-01-15'],
     *                   ['id' => 2, 'name' => 'Beta Team', 'created_at' => '2024-02-20'],
     *               ]
     *
     * @throws \InvalidArgumentException If label is empty or invalid
     * @throws \RuntimeException If query execution fails
     */
    public function getExampleEntities(string $label, int $limit = 3): array
    {
        // Validate label
        if (empty($label)) {
            throw new \InvalidArgumentException('Label cannot be empty');
        }

        // Validate label name (prevent Cypher injection)
        if (!$this->isValidLabel($label)) {
            throw new \InvalidArgumentException(
                'Invalid label name: must contain only alphanumeric characters and underscores'
            );
        }

        // Build Cypher query to retrieve sample entities. (Using size(keys(n)) to
        // prioritize nodes with more properties for richer context.)
        // Using backtick quoting for label name safety
        $cypher = "MATCH (n:`{$label}`) WITH n, size(keys(n)) AS keyCount ORDER BY keyCount DESC RETURN n LIMIT \$limit";

        try {
            $results = $this->graphStore->query($cypher, ['limit' => $limit]);

            // Extract node properties from query results
            return array_map(function ($row) {
                return $row['n'] ?? [];
            }, $results);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to retrieve example entities for label '{$label}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get entity metadata for relevant entities detected in the question
     *
     * This method detects which entities are mentioned in the user's question
     * and returns their semantic metadata including scopes, aliases, and
     * property descriptions. This helps the LLM understand domain terminology.
     *
     * Detection Strategy:
     * 1. Check for entity labels (Person, Order, Team)
     * 2. Check for entity aliases (people, users, customers)
     * 3. Check for scope terms (volunteers, pending orders)
     *
     * @param string $question Natural language question from user
     *
     * @return array Metadata for detected entities with structure:
     *               [
     *                   'detected_entities' => ['Person', 'Order'],
     *                   'entity_metadata' => [
     *                       'Person' => [...full metadata...],
     *                       'Order' => [...full metadata...]
     *                   ],
     *                   'detected_scopes' => [
     *                       'volunteers' => ['entity' => 'Person', 'scope' => 'volunteers', ...]
     *                   ]
     *               ]
     */
    public function getEntityMetadata(string $question): array
    {
        $questionLower = strtolower($question);
        $detectedEntities = [];
        $detectedScopes = [];
        $entityMetadata = [];

        // Use semantic matching if available
        if ($this->scopeMatcher !== null) {
            $semanticScopes = $this->scopeMatcher->findMatchingScopes(
                $question,
                $this->entityConfigs,
                config('ai.semantic_matching.scope_threshold', 0.7),
                config('ai.semantic_matching.max_scopes', 5)
            );

            foreach ($semanticScopes as $scopeName => $scopeData) {
                $entityName = $scopeData['entity'] ?? '';

                // Find the full entity name (with namespace) if needed
                $fullEntityName = $this->findFullEntityName($entityName, $this->entityConfigs);

                if ($fullEntityName) {
                    $detectedEntities[] = $fullEntityName;
                    $entityMetadata[$fullEntityName] = $this->entityConfigs[$fullEntityName]['metadata'] ?? [];
                }

                $detectedScopes[$scopeName] = $this->buildScopeData($scopeName, $scopeData, $fullEntityName ?: $entityName);
            }
        }

        // Also do string-based detection for entities (semantic matching is for scopes)
        foreach ($this->entityConfigs as $entityName => $config) {
            $metadata = $config['metadata'] ?? null;

            // Skip entities without metadata
            if (!$metadata) {
                continue;
            }

            // Skip if already detected via semantic matching
            if (in_array($entityName, $detectedEntities)) {
                continue;
            }

            $isDetected = false;

            // Check if entity label is mentioned
            $shortName = class_basename($entityName);
            if (stripos($question, $shortName) !== false) {
                $isDetected = true;
            }

            // Check if any aliases are mentioned
            if (!$isDetected && !empty($metadata['aliases'])) {
                foreach ($metadata['aliases'] as $alias) {
                    if (strpos($questionLower, strtolower($alias)) !== false) {
                        $isDetected = true;
                        break;
                    }
                }
            }

            // String-based scope detection (fallback when semantic matcher not available or didn't find)
            if (!empty($metadata['scopes']) && $this->scopeMatcher === null) {
                foreach ($metadata['scopes'] as $scopeName => $scopeConfig) {
                    // Skip numeric keys (malformed config)
                    if (is_numeric($scopeName)) {
                        continue;
                    }

                    if (strpos($questionLower, strtolower($scopeName)) !== false) {
                        $isDetected = true;

                        // Only add if not already detected semantically
                        if (!isset($detectedScopes[$scopeName])) {
                            $detectedScopes[$scopeName] = $this->buildScopeData($scopeName, $scopeConfig, $entityName);
                        }
                    }
                }
            }

            // If entity was detected, include its full metadata
            if ($isDetected) {
                $detectedEntities[] = $entityName;
                $entityMetadata[$entityName] = $metadata;
            }
        }

        // Remove duplicates from detected entities
        $detectedEntities = array_unique($detectedEntities);

        return [
            'detected_entities' => array_values($detectedEntities),
            'entity_metadata' => $entityMetadata,
            'detected_scopes' => $detectedScopes,
        ];
    }

    /**
     * Build standardized scope data array
     *
     * @param string $scopeName Scope name
     * @param array $scopeConfig Scope configuration
     * @param string $entityName Entity name
     * @return array Standardized scope data
     */
    private function buildScopeData(string $scopeName, array $scopeConfig, string $entityName): array
    {
        return [
            'entity' => class_basename($entityName),
            'scope' => $scopeName,
            'description' => $scopeConfig['description'] ?? '',
            'specification_type' => $scopeConfig['specification_type'] ?? 'property_filter',
            'concept' => $scopeConfig['concept'] ?? ($scopeConfig['description'] ?? ''),
            'relationship_spec' => $scopeConfig['relationship_spec'] ?? null,
            'parsed_structure' => $scopeConfig['parsed_structure'] ?? null,
            'filter' => $scopeConfig['filter'] ?? null,
            'pattern' => $scopeConfig['pattern'] ?? null,
            'pattern_params' => $scopeConfig['pattern_params'] ?? null,
            'business_rules' => $scopeConfig['business_rules'] ?? [],
            'examples' => $scopeConfig['examples'] ?? [],
            'role_value' => $scopeConfig['role_value'] ?? null,
            'cypher_pattern' => $scopeConfig['cypher_pattern'] ?? '',
            // Semantic match metadata (if available)
            'match_score' => $scopeConfig['match_score'] ?? null,
            'match_type' => $scopeConfig['match_type'] ?? 'string',
            'matched_example' => $scopeConfig['matched_example'] ?? null,
        ];
    }

    /**
     * Get all available entity metadata
     *
     * Returns metadata for all configured entities, useful for providing
     * comprehensive context to the LLM about available business terms.
     *
     * @return array All entity metadata indexed by entity name
     */
    public function getAllEntityMetadata(): array
    {
        $allMetadata = [];

        foreach ($this->entityConfigs as $entityName => $config) {
            if (isset($config['metadata'])) {
                $allMetadata[$entityName] = $config['metadata'];
            }
        }

        return $allMetadata;
    }

    /**
     * Load entity configurations from config file
     *
     * @return array Entity configurations
     */
    private function loadEntityConfigs(): array
    {
        // Try to load from Laravel config if available
        if (function_exists('config')) {
            $configs = config('entities');
            if ($configs !== null) {
                return $configs;
            }
        }

        // Fallback: load directly from file
        $configPath = __DIR__ . '/../../config/entities.php';
        if (file_exists($configPath)) {
            return require $configPath;
        }

        return [];
    }

    /**
     * Resolve the query string to embed for graph/data grounding.
     *
     * Prefers the Query-Understanding rewrite (searchQuery) when it was produced;
     * otherwise falls back to the raw user question. This is the single source of
     * truth so entity selection, similar-query search, and the entity-label
     * fallback all benefit from the normalized rewrite consistently.
     *
     * @param PipelineContext $ctx      Pipeline context (carries qu->searchQuery)
     * @param string          $question Raw user question (fallback)
     * @return string Query string to embed
     */
    private function retrievalQuery(PipelineContext $ctx, string $question): string
    {
        return $ctx->qu->searchQuery !== '' ? $ctx->qu->searchQuery : $question;
    }

    /**
     * Search for similar queries with score threshold filtering
     *
     * Internal method that adds score threshold support to similarity search.
     * This allows filtering out low-quality matches.
     *
     * @param string $question Question to search for
     * @param string $collection Vector collection to search
     * @param int $limit Maximum results to return
     * @param float $scoreThreshold Minimum similarity score (0.0 - 1.0)
     *
     * @return array Similar questions with scores above threshold
     *
     * @throws \RuntimeException If embedding generation fails
     * @throws \RuntimeException If vector search fails
     */
    private function searchSimilarQueries(
        string $question,
        string $collection,
        int $limit,
        float $scoreThreshold
    ): array {
        // Generate embedding for the input question
        try {
            $embedding = $this->embeddingProvider->embed($question);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Failed to generate embedding for question: ' . $e->getMessage(),
                0,
                $e
            );
        }

        // Validate embedding
        if (empty($embedding)) {
            throw new \RuntimeException('Embedding provider returned empty vector');
        }

        // Search vector store for similar embeddings
        try {
            $results = $this->vectorStore->search(
                $collection,
                $embedding,
                $limit,
                [],
                $scoreThreshold
            );
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Vector store search failed: ' . $e->getMessage(),
                0,
                $e
            );
        }

        // Format results for consistent structure, filtering out empty entries
        return array_values(array_filter(array_map(function ($result) {
            $question = $result['payload']['question'] ?? $result['metadata']['question'] ?? '';
            $query = $result['payload']['cypher_query'] ?? $result['payload']['query']
                ?? $result['metadata']['cypher_query'] ?? $result['metadata']['query'] ?? '';

            if (empty(trim($question)) || empty(trim($query))) {
                $this->logger->debug('context_retriever', 'similar_query_skipped_empty', [
                    'has_question' => !empty(trim($question)),
                    'has_query' => !empty(trim($query)),
                ]);
                return null;
            }

            return [
                'question' => $question,
                'query' => $query,
                'score' => $result['score'] ?? 0.0,
            ];
        }, $results)));
    }

    /**
     * Assess the quality of retrieved context
     *
     * Evaluates the completeness of the context by checking for the presence
     * of key components: similar queries, schema, detected entities, and
     * absence of errors.
     *
     * @param PipelineContext $context The retrieved context to assess
     * @return array Quality assessment with level, score, max, and details
     */
    private function assessContextQuality(PipelineContext $context): array
    {
        $score = 0;
        $max = 4;
        $details = [];

        if (!empty($context->similarQueries)) {
            $score++;
            $details[] = 'has_similar_queries';
        }
        if (!empty($context->graphSchema['labels'] ?? [])) {
            $score++;
            $details[] = 'has_schema';
        }
        if (!empty($context->entityMetadata['detected_entities'] ?? [])) {
            $score++;
            $details[] = 'has_detected_entities';
        }
        if (empty($context->errors)) {
            $score++;
            $details[] = 'no_errors';
        }

        $level = match(true) {
            $score === $max => 'high',
            $score >= 2 => 'medium',
            default => 'low',
        };

        $this->logger->info('context_retriever', "context_quality: {$level}", [
            'score' => "{$score}/{$max}",
            'details' => $details,
            'errors' => $context->errors,
        ]);

        return ['level' => $level, 'score' => $score, 'max' => $max, 'details' => $details];
    }

    /**
     * Retrieve example entities for multiple labels
     *
     * Iterates through labels and retrieves sample entities for each.
     * Implements graceful degradation: individual label failures don't
     * stop retrieval for other labels.
     *
     * @param array $labels Array of node labels to retrieve examples for
     * @param int $limit Number of examples per label
     * @param array &$errors Reference to errors array for collecting failures
     *
     * @return array Example entities grouped by label:
     *               [
     *                   'Team' => [['id' => 1, 'name' => 'Alpha']],
     *                   'Person' => [['id' => 1, 'name' => 'John']]
     *               ]
     */
    private function retrieveExampleEntities(
        array $labels,
        int $limit,
        array &$errors
    ): array {
        $entities = [];

        foreach ($labels as $label) {
            try {
                $examples = $this->getExampleEntities($label, $limit);

                // Only include if examples were found
                if (!empty($examples)) {
                    $entities[$label] = $examples;
                }
            } catch (\Exception $e) {
                // Continue on error - don't fail entire context retrieval
                // Individual label failures are acceptable
                $this->logger->error('context_retriever', "Example retrieval for label '{$label}' failed: " . $e->getMessage());
                $errors[] = "Example retrieval for label '{$label}' failed: " . $e->getMessage();
            }
        }

        return $entities;
    }

    /**
     * Get properties grouped by label from entity configurations
     *
     * Extracts property lists from entity configs and groups them by label.
     *
     * @return array Properties grouped by label: ['Customer' => ['id', 'name'], ...]
     */
    private function getPropertiesByLabel(): array
    {
        $propertiesByLabel = [];

        foreach ($this->entityConfigs as $entityName => $config) {
            // Get graph config
            $graphConfig = $config['graph'] ?? [];
            $label = $graphConfig['label'] ?? $entityName;

            // Get properties for this label
            $properties = $graphConfig['properties'] ?? [];

            if (!empty($properties)) {
                $propertiesByLabel[$label] = $properties;
            }
        }

        return $propertiesByLabel;
    }

    /**
     * Find relevant entity labels using the semantic_entities Qdrant collection.
     * Used as a fallback when context_index is unavailable.
     */
    private function findRelevantEntityLabels(string $question): array
    {
        try {
            $embedding = $this->embeddingProvider->embed($question);
            $results = $this->vectorStore->search('semantic_entities', $embedding, 10);

            $labels = [];
            foreach ($results as $result) {
                $raw = $result['payload']['label'] ?? $result['payload']['entity']
                    ?? $result['metadata']['label'] ?? $result['metadata']['entity'] ?? null;
                if (!$raw) {
                    continue;
                }
                // Normalize: if FQCN (App\Models\Team), extract short name (Team)
                $label = class_basename($raw);
                if (!in_array($label, $labels)) {
                    $labels[] = $label;
                }
            }

            // Also include related entities from the same category
            $labels = $this->expandWithRelatedLabels($labels);

            return array_slice($labels, 0, 15); // Cap at 15 entities
        } catch (\Exception $e) {
            $this->logger->error('context_retriever', 'findRelevantEntityLabels failed: ' . $e->getMessage());
            $this->logger->fallback('context_retriever', 'semantic_entity_labels', 'full_schema', $e->getMessage());
            return []; // Fall back to full schema
        }
    }

    /**
     * Expand matched labels with closely related entities (same category).
     */
    private function expandWithRelatedLabels(array $labels): array
    {
        $categoryMap = $this->getCategoryMap();
        $expanded = $labels;

        foreach ($labels as $label) {
            foreach ($categoryMap as $category => $categoryLabels) {
                if (in_array($label, $categoryLabels)) {
                    foreach ($categoryLabels as $relatedLabel) {
                        if (!in_array($relatedLabel, $expanded)) {
                            $expanded[] = $relatedLabel;
                        }
                    }
                    break;
                }
            }
        }

        return $expanded;
    }

    /**
     * Get category map from entity configs (derived from FQCN namespace).
     */
    private function getCategoryMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        foreach ($this->entityConfigs as $fqcn => $config) {
            $parts = explode('\\', $fqcn);
            $category = (count($parts) > 3) ? $parts[2] : 'General';
            $label = $config['graph']['label'] ?? class_basename($fqcn);
            $map[$category][] = $label;
        }

        return $map;
    }

    /**
     * Filter schema to only include specific labels.
     */
    private function filterSchemaByLabels(array $schema, array $labels): array
    {
        $filtered = $schema;

        // Filter properties to only include matched labels
        if (!empty($filtered['properties'])) {
            $filtered['properties'] = array_filter(
                $filtered['properties'],
                fn($label) => in_array($label, $labels),
                ARRAY_FILTER_USE_KEY
            );
        }

        // Filter labels list
        if (!empty($filtered['labels'])) {
            $filtered['labels'] = array_values(array_filter(
                $filtered['labels'],
                fn($label) => in_array($label, $labels)
            ));
        }

        return $filtered;
    }

    /**
     * Validate label name to prevent Cypher injection
     *
     * Label names should only contain alphanumeric characters and underscores.
     * This prevents malicious input from executing arbitrary Cypher code.
     *
     * @param string $label Label name to validate
     *
     * @return bool True if valid, false otherwise
     */
    private function isValidLabel(string $label): bool
    {
        // Allow alphanumeric characters, underscores, and hyphens
        // Start with letter or underscore
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $label) === 1;
    }

    /**
     * Get minimal context for a question
     *
     * Returns only the absolute minimum context needed to answer the question.
     * This is the most aggressive filtering and should be used when token
     * conservation is critical.
     *
     * @param string $question Natural language question from user
     * @param array $options Configuration options
     * @return array Minimal context structure
     */
    public function getMinimalContext(string $question, array $options = []): array
    {
        // Validate input
        if (empty(trim($question))) {
            throw new \InvalidArgumentException('Question cannot be empty');
        }

        // Force semantic selection and aggressive filtering
        $options = array_merge($options, [
            'useSemanticSelection' => true,
            'includeExamples' => false, // No examples by default
            'limit' => $options['limit'] ?? 3, // Only top 3 similar queries
            'scoreThreshold' => $options['scoreThreshold'] ?? 0.75, // Higher threshold
        ]);

        $context = [
            'similar_queries' => [],
            'graph_schema' => [],
            'entity_metadata' => [],
            'errors' => [],
            'selection_info' => [],
        ];

        // Try semantic context selection first (required for minimal context)
        /** @var SemanticSelectionResult|null $semanticContext */
        $semanticContext = null;
        if ($this->contextSelector !== null) {
            try {
                $semanticContext = $this->contextSelector->selectRelevantContext(
                    $question,
                    $this->entityConfigs,
                    [
                        'threshold' => config('ai.semantic_context.threshold', 0.65),
                        'top_k' => 5, // Limit to top 5 most relevant
                    ]
                );
                // Extract selected entity labels (short names)
                $selectedEntities = array_map(
                    fn($entityName) => class_basename($entityName),
                    array_keys($semanticContext->entities)
                );
                $context['selection_info'] = [
                    'method' => 'semantic',
                    'mode' => 'minimal',
                    'selected_entities' => $selectedEntities,
                    'entities_selected' => count($semanticContext->entities),
                    'relationships_selected' => count($semanticContext->relationships),
                ];
            } catch (\Exception $e) {
                $this->logger->error('context_retriever', 'Semantic context selection failed (minimal): ' . $e->getMessage());
                $context['errors'][] = 'Semantic context selection failed: ' . $e->getMessage();
                // For minimal context, we fail fast if semantic selection isn't available
                return $context;
            }
        } else {
            $context['errors'][] = 'Semantic context selector not available - minimal context requires semantic selection';
            return $context;
        }

        // Get only the most similar queries
        try {
            $context['similar_queries'] = $this->searchSimilarQueries(
                $question,
                $options['collection'] ?? self::DEFAULT_COLLECTION,
                $options['limit'],
                $options['scoreThreshold']
            );
        } catch (\Exception $e) {
            $this->logger->error('context_retriever', 'Vector search failed (minimal): ' . $e->getMessage());
            $context['errors'][] = 'Vector search failed: ' . $e->getMessage();
        }

        // Get minimal schema (only relevant entities and relationships)
        if (!empty($semanticContext->entities)) {
            try {
                $fullSchema = $this->getGraphSchema();
                $context['graph_schema'] = $this->filterSchemaByRelevance($fullSchema, $semanticContext);
            } catch (\Exception $e) {
                $this->logger->error('context_retriever', 'Schema retrieval failed (minimal): ' . $e->getMessage());
                $context['errors'][] = 'Schema retrieval failed: ' . $e->getMessage();
            }
        }

        // Get entity metadata for detected entities only
        try {
            if (!empty($semanticContext->entities)) {
                $context['entity_metadata'] = $this->buildMetadataFromSemanticSelection(
                    $question,
                    $semanticContext
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('context_retriever', 'Entity metadata retrieval failed (minimal): ' . $e->getMessage());
            $context['errors'][] = 'Entity metadata retrieval failed: ' . $e->getMessage();
        }

        return $context;
    }

    /**
     * Get context with size estimation
     *
     * Returns context along with approximate token usage statistics.
     * This helps monitor and optimize token consumption.
     *
     * @param string $question Natural language question from user
     * @param array $options Configuration options
     * @return array Context with size statistics
     */
    public function getContextWithStats(string $question, array $options = []): array
    {
        $context = $this->retrieveContext($question, $options);
        $stats = $this->getContextStats($context);

        return [
            'context' => $context,
            'stats' => $stats,
        ];
    }

    /**
     * Get context statistics
     *
     * Analyzes the context and returns statistics about what was included/excluded
     * and approximate token usage.
     *
     * @param PipelineContext|array $context Context from retrieveContext (PipelineContext) or legacy array
     * @return array Statistics about the context
     */
    public function getContextStats(PipelineContext|array $context): array
    {
        $stats = [
            'token_estimate' => 0,
            'breakdown' => [],
            'items_included' => [],
            'items_excluded' => [],
            'compression_ratio' => 0,
        ];

        // Support both PipelineContext and legacy array access
        $similarQueries = $context instanceof PipelineContext ? $context->similarQueries : ($context['similar_queries'] ?? []);
        $graphSchema = $context instanceof PipelineContext ? $context->graphSchema : ($context['graph_schema'] ?? []);
        $entityMetadata = $context instanceof PipelineContext ? $context->entityMetadata : ($context['entity_metadata'] ?? []);
        $relevantEntities = $context instanceof PipelineContext ? $context->relevantEntities : ($context['relevant_entities'] ?? []);
        $selectionInfo = $context instanceof PipelineContext ? $context->selectionInfo : ($context['selection_info'] ?? []);

        // Estimate tokens for similar queries
        $similarQueriesTokens = 0;
        foreach ($similarQueries as $query) {
            $similarQueriesTokens += $this->estimateTokens($query['question'] ?? '');
            $similarQueriesTokens += $this->estimateTokens($query['query'] ?? '');
        }
        $stats['breakdown']['similar_queries'] = $similarQueriesTokens;
        $stats['items_included']['similar_queries'] = count($similarQueries);

        // Estimate tokens for schema
        $schemaTokens = 0;
        $schemaTokens += $this->estimateTokens(implode(', ', $graphSchema['labels'] ?? []));
        $schemaTokens += $this->estimateTokens(implode(', ', $graphSchema['relationships'] ?? []));
        $schemaTokens += $this->estimateTokens(implode(', ', $graphSchema['propertyKeys'] ?? []));
        $stats['breakdown']['graph_schema'] = $schemaTokens;
        $stats['items_included']['schema_labels'] = count($graphSchema['labels'] ?? []);
        $stats['items_included']['schema_relationships'] = count($graphSchema['relationships'] ?? []);
        $stats['items_included']['schema_properties'] = count($graphSchema['propertyKeys'] ?? []);

        // Estimate tokens for entity metadata
        $metadataTokens = 0;
        foreach ($entityMetadata['entity_metadata'] ?? [] as $entityMeta) {
            $metadataTokens += $this->estimateTokens(json_encode($entityMeta));
        }
        $stats['breakdown']['entity_metadata'] = $metadataTokens;
        $stats['items_included']['detected_entities'] = count($entityMetadata['detected_entities'] ?? []);
        $stats['items_included']['detected_scopes'] = count($entityMetadata['detected_scopes'] ?? []);

        // Estimate tokens for example entities
        $examplesTokens = 0;
        foreach ($relevantEntities as $label => $entities) {
            foreach ($entities as $entity) {
                $examplesTokens += $this->estimateTokens(json_encode($entity));
            }
        }
        $stats['breakdown']['example_entities'] = $examplesTokens;
        $stats['items_included']['example_entities'] = array_sum(array_map('count', $relevantEntities));

        // Total token estimate
        $stats['token_estimate'] = array_sum($stats['breakdown']);

        // Calculate compression ratio if semantic selection was used
        if (!empty($selectionInfo) && ($selectionInfo['method'] ?? '') === 'semantic') {
            // Estimate full context size (all entities)
            $totalEntities = count($this->entityConfigs);
            $selectedEntities = $selectionInfo['entities_selected'] ?? 1;
            $stats['compression_ratio'] = $totalEntities > 0
                ? round((1 - ($selectedEntities / $totalEntities)) * 100, 2)
                : 0;
        }

        return $stats;
    }

    /**
     * Estimate token count for a text string
     *
     * This is a rough approximation. For accurate token counting,
     * you should use the actual tokenizer for your LLM.
     *
     * Rule of thumb: ~4 characters per token for English text
     *
     * @param string $text Text to estimate
     * @return int Approximate token count
     */
    private function estimateTokens(string $text): int
    {
        if (empty($text)) {
            return 0;
        }

        // Rough approximation: 4 characters per token
        // Add extra for JSON structure overhead
        $baseTokens = (int) ceil(strlen($text) / 4);

        // If it looks like JSON, add overhead for structure
        if (str_starts_with(trim($text), '{') || str_starts_with(trim($text), '[')) {
            $baseTokens = (int) ceil($baseTokens * 1.2);
        }

        return $baseTokens;
    }

    /**
     * Get token budget-aware context
     *
     * Retrieves context while respecting a maximum token budget.
     * Automatically adjusts what's included to stay within the budget.
     *
     * @param string $question Natural language question from user
     * @param int $maxTokens Maximum tokens to use for context
     * @param array $options Configuration options
     * @return array Context that fits within token budget
     */
    public function getContextWithBudget(string $question, int $maxTokens, array $options = []): array
    {
        // Start with minimal context
        $context = $this->getMinimalContext($question, $options);
        $stats = $this->getContextStats($context);

        // If minimal context fits, we can add more
        if ($stats['token_estimate'] < $maxTokens * 0.5) {
            // We have room for examples
            $options['includeExamples'] = true;
            $options['examplesPerLabel'] = 1; // Start with 1 example per label

            $enhancedContext = $this->retrieveContext($question, $options);
            $enhancedStats = $this->getContextStats($enhancedContext);

            // If enhanced context still fits, use it
            if ($enhancedStats['token_estimate'] <= $maxTokens) {
                return [
                    'context' => $enhancedContext,
                    'stats' => $enhancedStats,
                    'budget' => [
                        'max' => $maxTokens,
                        'used' => $enhancedStats['token_estimate'],
                        'remaining' => $maxTokens - $enhancedStats['token_estimate'],
                    ],
                ];
            }
        }

        // Return minimal context if we can't fit more
        return [
            'context' => $context,
            'stats' => $stats,
            'budget' => [
                'max' => $maxTokens,
                'used' => $stats['token_estimate'],
                'remaining' => $maxTokens - $stats['token_estimate'],
            ],
        ];
    }

    /**
     * Get confidence scores for context selection
     *
     * Returns confidence scores for the selected context components.
     * This helps assess the quality of semantic matching.
     *
     * @param string $question Natural language question from user
     * @param array $options Configuration options
     * @return array Confidence scores for selected components
     */
    public function getContextConfidence(string $question, array $options = []): array
    {
        $confidence = [
            'overall' => 0.0,
            'components' => [],
        ];

        // Get semantic context selection
        if ($this->contextSelector !== null) {
            try {
                $semanticContext = $this->contextSelector->selectRelevantContext(
                    $question,
                    $this->entityConfigs,
                    [
                        'threshold' => config('ai.semantic_context.threshold', 0.65),
                        'top_k' => config('ai.semantic_context.top_k', 10),
                    ]
                );

                // Calculate confidence for entities
                $entityScores = [];
                foreach ($semanticContext->entities as $entityName => $entityInfo) {
                    $score = $entityInfo->score;
                    $entityScores[] = $score;
                    $confidence['components']['entities'][$entityName] = $score;
                }

                // Calculate confidence for relationships
                $relationshipScores = [];
                foreach ($semanticContext->relationships as $relKey => $relInfo) {
                    $score = $relInfo->score;
                    $relationshipScores[] = $score;
                    $confidence['components']['relationships'][$relKey] = $score;
                }

                // Calculate confidence for scopes
                $scopeScores = [];
                foreach ($semanticContext->scopes as $scopeName => $scopeInfo) {
                    $score = $scopeInfo->score;
                    $scopeScores[] = $score;
                    $confidence['components']['scopes'][$scopeName] = $score;
                }

                // Calculate overall confidence (average of all scores)
                $allScores = array_merge($entityScores, $relationshipScores, $scopeScores);
                $confidence['overall'] = !empty($allScores)
                    ? round(array_sum($allScores) / count($allScores), 3)
                    : 0.0;

                // Add selection method
                $confidence['selection_method'] = 'semantic';

            } catch (\Exception $e) {
                $this->logger->error('context_retriever', 'Context confidence calculation failed: ' . $e->getMessage());
                $confidence['error'] = $e->getMessage();
                $confidence['selection_method'] = 'failed';
            }
        } else {
            // Fallback confidence is lower
            $confidence['overall'] = 0.5;
            $confidence['selection_method'] = 'keyword';
        }

        return $confidence;
    }

    /**
     * Get the importance weight for a relationship type
     *
     * @param string $relationshipType The relationship type name
     * @return float Weight between 0.0 and 1.0
     */
    public function getRelationshipWeight(string $relationshipType): float
    {
        $weights = config('ai.relationship_weights', []);
        return $weights[$relationshipType] ?? $weights['default'] ?? 0.5;
    }

    /**
     * Filter relationships by minimum importance threshold
     *
     * @param array $relationships Array of relationships with 'type' key
     * @param float $threshold Minimum weight threshold (0.0 - 1.0)
     * @return array Filtered relationships
     */
    public function filterRelationshipsByImportance(array $relationships, float $threshold = 0.5): array
    {
        return array_filter($relationships, function ($rel) use ($threshold) {
            $type = is_array($rel) ? ($rel['type'] ?? '') : $rel;
            return $this->getRelationshipWeight($type) >= $threshold;
        });
    }

    /**
     * Compute a real retrieval-confidence signal from a top-k score distribution.
     *
     * This is a pure, side-effect-free read of the score distribution intended to
     * feed a FUTURE rephrase/abstention loop. It does NOT change retrieval
     * behavior, thresholds, or what is returned by retrieveContext() — callers opt
     * in by passing the scores they already have (e.g. similar-query scores, file
     * relevance scores, or semantic-selection scores).
     *
     * Confidence blends two cheap, well-understood signals from the top-k scores:
     *  - strength: the mean of the top-k scores (are matches strong at all?);
     *  - margin:   the gap between the best and the median (is the top match
     *              clearly separated, or is everything a flat, ambiguous tie?).
     *
     * Both are already in/near [0, 1] for cosine-style scores; the result is
     * clamped to [0, 1]. An empty distribution yields 0.0.
     *
     * @param array<int|float> $scores Top-k relevance scores (any order).
     * @param int              $k      How many of the top scores to consider (default 5).
     * @return array{
     *     overall: float,
     *     strength: float,
     *     margin: float,
     *     top_score: float,
     *     considered: int
     * }
     */
    public function computeRetrievalConfidence(array $scores, int $k = 5): array
    {
        $numeric = array_values(array_filter(
            array_map(fn ($s) => is_numeric($s) ? (float) $s : null, $scores),
            fn ($s) => $s !== null
        ));

        if (empty($numeric)) {
            return [
                'overall' => 0.0,
                'strength' => 0.0,
                'margin' => 0.0,
                'top_score' => 0.0,
                'considered' => 0,
            ];
        }

        rsort($numeric);
        $topK = array_slice($numeric, 0, max(1, $k));
        $count = count($topK);

        $top = $topK[0];
        $strength = array_sum($topK) / $count;

        // Median of the considered window.
        $mid = (int) floor(($count - 1) / 2);
        $median = ($count % 2 === 1)
            ? $topK[$mid]
            : (($topK[$mid] + $topK[$mid + 1]) / 2.0);

        // Separation of the best match from the bulk. Flat distributions (best ~=
        // median) signal ambiguity; a clear leader signals confidence.
        $margin = max(0.0, $top - $median);

        // Blend strength (weighted higher) with margin, then clamp.
        $overall = (0.7 * $strength) + (0.3 * $margin);
        $overall = max(0.0, min(1.0, $overall));

        return [
            'overall' => round($overall, 4),
            'strength' => round($strength, 4),
            'margin' => round($margin, 4),
            'top_score' => round($top, 4),
            'considered' => $count,
        ];
    }
}
