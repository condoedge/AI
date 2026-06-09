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
use Condoedge\Ai\Services\Retrieval\ContextQualityAssessor;
use Condoedge\Ai\Services\Retrieval\EntityMetadataBuilder;
use Condoedge\Ai\Services\Retrieval\HybridFuser;
use Condoedge\Ai\Services\Retrieval\NullReranker;
use Condoedge\Ai\Services\Retrieval\RerankerInterface;
use Condoedge\Ai\Services\Retrieval\SchemaProjector;
use Condoedge\Ai\Services\Retrieval\SimilarQuerySearcher;
use Condoedge\Ai\Services\Retrieval\TokenBudgeter;
use Condoedge\Ai\Support\AiLogger;

/**
 * ContextRetriever
 *
 * Thin orchestrator for Retrieval-Augmented Generation (RAG). For a question it
 * aggregates similar past Q->Cypher pairs, a relevance-filtered slice of the
 * graph schema, sample entities, and the metadata/scopes of the selected
 * entities into a single PipelineContext.
 *
 * Each responsibility is owned by a focused collaborator:
 * - SimilarQuerySearcher  — vector similarity search for past questions
 * - SchemaProjector       — schema relevance filtering + entity-label fallback
 * - EntityMetadataBuilder — detected entity/scope metadata (semantic + fallback)
 * - ContextQualityAssessor— completeness scoring
 * - TokenBudgeter         — token estimation / stats / confidence math
 *
 * @package Condoedge\Ai\Services
 */
class ContextRetriever implements ContextRetrieverInterface
{
    use ResolvesEntityConfigKey;

    private const DEFAULT_COLLECTION = 'questions';
    private const DEFAULT_LIMIT = 5;
    private const DEFAULT_EXAMPLES_PER_LABEL = 2;
    private const DEFAULT_SCORE_THRESHOLD = 0.0;

    /**
     * Over-fetch multiplier for the rerank funnel: fetch limit*factor candidates,
     * rerank that wider pool, then cut to limit. Reranking a too-small pool is pure
     * cost, so over-fetch is the precondition. Bounded by RERANK_POOL_CAP.
     */
    private const RERANK_OVERFETCH_FACTOR = 4;

    /**
     * Hard cap on the over-fetched candidate pool sent to the reranker, keeping the
     * (single) scoring call bounded regardless of the requested limit.
     */
    private const RERANK_POOL_CAP = 40;

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

    private SimilarQuerySearcher $similarQuerySearcher;
    private SchemaProjector $schemaProjector;
    private EntityMetadataBuilder $metadataBuilder;
    private ContextQualityAssessor $qualityAssessor;
    private TokenBudgeter $tokenBudgeter;

    /**
     * Precision reranker applied to the over-fetched similar-query / semantic-selection
     * pools before the final cut. Defaults to the container-bound reranker (NullReranker
     * when none is bound), so reranking is always a single path and the default behavior
     * is identical to "no reranker".
     */
    private RerankerInterface $reranker;

    /**
     * Hybrid (dense + sparse/lexical) fuser. Used to fold a BM25-style lexical signal
     * over entity names/aliases/scope text into the dense semantic ranking via RRF.
     * Pure/deterministic; only applied when hybrid retrieval is config-enabled.
     */
    private HybridFuser $hybridFuser;

    /**
     * Whether the metadata builder was supplied externally. When false, it is
     * rebuilt from local state if the scope matcher changes (setScopeMatcher).
     */
    private bool $metadataBuilderInjected;

    /**
     * Create context retriever with injected dependencies.
     *
     * Collaborators default to internally-constructed instances built from the
     * stores/provider/logger already held, so the service-provider binding works
     * unchanged.
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
        ?SemanticContextSelector $contextSelector = null,
        ?SimilarQuerySearcher $similarQuerySearcher = null,
        ?SchemaProjector $schemaProjector = null,
        ?EntityMetadataBuilder $metadataBuilder = null,
        ?ContextQualityAssessor $qualityAssessor = null,
        ?TokenBudgeter $tokenBudgeter = null,
        ?RerankerInterface $reranker = null,
        ?HybridFuser $hybridFuser = null,
    ) {
        $this->entityConfigs = $entityConfigs ?? $this->loadEntityConfigs();
        $this->scopeMatcher = $scopeMatcher;
        $this->contextSelector = $contextSelector;

        $this->similarQuerySearcher = $similarQuerySearcher
            ?? new SimilarQuerySearcher($this->vectorStore, $this->embeddingProvider, $this->logger);
        $this->schemaProjector = $schemaProjector
            ?? new SchemaProjector($this->vectorStore, $this->embeddingProvider, $this->logger, $this->entityConfigs);
        $this->metadataBuilderInjected = $metadataBuilder !== null;
        $this->metadataBuilder = $metadataBuilder
            ?? new EntityMetadataBuilder($this->entityConfigs, $this->scopeMatcher);
        $this->qualityAssessor = $qualityAssessor ?? new ContextQualityAssessor($this->logger);
        $this->tokenBudgeter = $tokenBudgeter ?? new TokenBudgeter($this->entityConfigs);
        $this->reranker = $reranker ?? $this->resolveBoundReranker();
        $this->hybridFuser = $hybridFuser ?? new HybridFuser();
    }

    /**
     * Resolve the container-bound reranker, defaulting to a NullReranker when no
     * concrete RerankerInterface is bound (safe before DI wiring exists, e.g. in
     * pure unit tests). Mirrors FileContextProvider so reranking is a single,
     * uniform path across file and graph/RAG retrieval.
     */
    private function resolveBoundReranker(): RerankerInterface
    {
        try {
            if (function_exists('app') && app()->bound(RerankerInterface::class)) {
                $resolved = app(RerankerInterface::class);
                if ($resolved instanceof RerankerInterface) {
                    return $resolved;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the null reranker.
        }

        return new NullReranker();
    }

    /**
     * Set the scope semantic matcher.
     */
    public function setScopeMatcher(ScopeSemanticMatcher $scopeMatcher): self
    {
        $this->scopeMatcher = $scopeMatcher;

        // Keep the auto-built metadata builder consistent with the new matcher.
        if (!$this->metadataBuilderInjected) {
            $this->metadataBuilder = new EntityMetadataBuilder($this->entityConfigs, $this->scopeMatcher);
        }

        return $this;
    }

    /**
     * Set the semantic context selector.
     */
    public function setContextSelector(SemanticContextSelector $contextSelector): self
    {
        $this->contextSelector = $contextSelector;
        return $this;
    }

    /**
     * Set the Query Understanding service.
     */
    public function setQueryUnderstanding(QueryUnderstandingService $qu): self
    {
        $this->queryUnderstanding = $qu;
        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * Aggregates query understanding, semantic selection, similar queries, a
     * relevance-filtered schema, example entities and entity metadata into a
     * single PipelineContext, degrading gracefully on partial failures.
     *
     * @param string $question Natural language question from user
     * @param array $options Configuration options (collection, limit, includeSchema,
     *                       includeExamples, examplesPerLabel, scoreThreshold,
     *                       useSemanticSelection, conversation_context, page_context)
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
        // Stamp the question the context was built for, so the grounded response path
        // (OriginalQuestionSection) sees what was actually asked instead of narrating
        // blindly over rows. Previously only the eval/test drivers set this.
        $ctx->question = $question;

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

        // Page context is a SOFT PRIOR (never a hard filter): when the page/record the chat was
        // opened from names an entity type, seed it into selection the same way the conversation's
        // focused entity is seeded, so pronoun/possessive resolution ("this unit") has an anchor.
        $pageEntityType = $options['page_context']['entity_type'] ?? null;

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

                // Soft-include the page's entity type (same mechanism as the conversation focus).
                // Soft: only added when it matches the catalog and isn't already selected — it
                // ranks the current record's entity, it never excludes others.
                if ($pageEntityType) {
                    $pageFullName = $this->findFullEntityName($pageEntityType, $this->entityConfigs);
                    if ($pageFullName && !isset($ctx->semanticSelection->entities[$pageFullName])) {
                        $ctx->semanticSelection->entities[$pageFullName] = new SelectedEntity(
                            score: config('ai.semantic_context.page_context_focus_score', 0.55),
                            matchedText: "Page context: '{$pageEntityType}'",
                            config: $this->entityConfigs[$pageFullName] ?? null,
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

                // Hybrid retrieval: fold a sparse/lexical signal (BM25-ish over entity
                // names/aliases/scope text) into the dense semantic ranking via RRF. The
                // dense ranking is the current (score-sorted) entity order; the sparse
                // ranking surfaces exact name/alias/acronym matches the embedding can miss.
                // Config-gated and behavior-preserving when off: the entity map is left
                // exactly as assembled above.
                $ctx->semanticSelection->entities = $this->applyHybridFusion(
                    $retrievalQuery,
                    $ctx->semanticSelection->entities
                );

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

        // STEP 3: Similar queries (over-fetch -> rerank -> cut)
        //
        // Over-fetch a wider pool (limit*factor, capped) so the genuinely-best Q->Cypher
        // example is present even when raw cosine ranks it below the top-$limit, then route
        // the pool through the reranker and cut to $limit. The reranker contract guarantees
        // it never throws and degrades to input order, and the default binding is a
        // NullReranker, so when reranking is disabled this reduces to the prior top-$limit.
        try {
            $poolSize = $this->rerankPoolSize($limit);
            $candidates = $this->similarQuerySearcher->search($retrievalQuery, $collection, $poolSize, $scoreThreshold);
            $ctx->similarQueries = $this->reranker->rerank(
                $retrievalQuery,
                $candidates,
                static fn(array $c): string => trim(($c['question'] ?? '') . ' ' . ($c['query'] ?? '')),
                $limit
            );
        } catch (\Exception $e) {
            $this->logger->error('context_retriever', 'Vector search failed: ' . $e->getMessage());
            $ctx->addError('Vector search failed: ' . $e->getMessage());
        }

        // STEP 4: Graph schema
        if ($includeSchema) {
            try {
                $fullSchema = $this->getGraphSchema();

                if (!empty($ctx->semanticSelection->entities)) {
                    $ctx->graphSchema = $this->schemaProjector->filterByRelevance($fullSchema, $ctx->semanticSelection);
                } else {
                    $relevantLabels = $this->schemaProjector->findRelevantEntityLabels($retrievalQuery);
                    if (!empty($relevantLabels)) {
                        $ctx->graphSchema = $this->schemaProjector->filterByLabels($fullSchema, $relevantLabels);
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
                $ctx->entityMetadata = $this->metadataBuilder->fromSemanticSelection($question, $ctx->semanticSelection);
            } else {
                $ctx->entityMetadata = $this->getEntityMetadata($question);
            }
        } catch (\Exception $e) {
            $this->logger->error('context_retriever', 'Entity metadata retrieval failed: ' . $e->getMessage());
            $ctx->addError('Entity metadata retrieval failed: ' . $e->getMessage());
        }

        // STEP 7: Quality assessment
        $ctx->quality = $this->qualityAssessor->assess($ctx);

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
     * {@inheritDoc}
     *
     * Semantic similarity search over past questions for few-shot grounding.
     * Results are formatted as question/query/score and sorted by similarity.
     *
     * @return array<int, array{question: string, query: string, score: float}>
     *
     * @throws \RuntimeException If embedding generation or vector search fails.
     */
    public function searchSimilar(
        string $question,
        string $collection = 'questions',
        int $limit = 5
    ): array {
        return $this->similarQuerySearcher->search($question, $collection, $limit, 0.0);
    }

    /**
     * {@inheritDoc}
     *
     * Retrieves and normalizes the graph schema: node labels, relationship types,
     * a flat property-key list, and per-label properties from entity configs.
     *
     * @return array{labels: array, relationships: array, properties: array, propertyKeys: array}
     *
     * @throws \RuntimeException If schema retrieval fails
     */
    public function getGraphSchema(): array
    {
        $schema = $this->graphStore->getSchema();

        $propertiesByLabel = $this->getPropertiesByLabel();

        // Normalize schema structure to a consistent format.
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
     * Retrieves sample entities of a label so the LLM sees concrete property
     * names, value shapes, and formats. Uses a backtick-quoted Cypher MATCH and
     * validates the label to prevent injection.
     *
     * @return array Sample entities as associative arrays
     *
     * @throws \InvalidArgumentException If label is empty or invalid
     * @throws \RuntimeException If query execution fails
     */
    public function getExampleEntities(string $label, int $limit = 3): array
    {
        if (empty($label)) {
            throw new \InvalidArgumentException('Label cannot be empty');
        }

        // Validate label name to prevent Cypher injection.
        if (!$this->isValidLabel($label)) {
            throw new \InvalidArgumentException(
                'Invalid label name: must contain only alphanumeric characters and underscores'
            );
        }

        // Order by size(keys(n)) to prioritize nodes with more properties for richer
        // context. Backtick-quote the label for safety (already validated above).
        $cypher = "MATCH (n:`{$label}`) WITH n, size(keys(n)) AS keyCount ORDER BY keyCount DESC RETURN n LIMIT \$limit";

        try {
            $results = $this->graphStore->query($cypher, ['limit' => $limit]);

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
     * Get entity metadata (detected entities, scopes, metadata) for a question.
     *
     * Delegates to EntityMetadataBuilder's question-based path (scope-matcher +
     * string fallback).
     */
    public function getEntityMetadata(string $question): array
    {
        return $this->metadataBuilder->fromQuestion($question);
    }

    /**
     * Get metadata for all configured entities that declare it.
     *
     * @return array All entity metadata indexed by entity name
     */
    public function getAllEntityMetadata(): array
    {
        return $this->metadataBuilder->all();
    }

    /**
     * Load entity configurations from config file.
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
     * Prefers the Query-Understanding rewrite (searchQuery) when produced;
     * otherwise the raw user question.
     */
    private function retrievalQuery(PipelineContext $ctx, string $question): string
    {
        return $ctx->qu->searchQuery !== '' ? $ctx->qu->searchQuery : $question;
    }

    /**
     * Size of the candidate pool to over-fetch before reranking.
     *
     * Over-fetch (limit*factor, capped) is the precondition for a useful rerank —
     * reranking a pool no wider than the final cut cannot recover a missed result.
     * It is therefore gated on reranking actually being enabled: when reranking is
     * off (the default NullReranker path), the pool equals $limit exactly, so the
     * underlying vector search and its results are unchanged from the pre-rerank
     * behavior.
     */
    private function rerankPoolSize(int $limit): int
    {
        if (!$this->isRerankEnabled() || $limit <= 0) {
            return $limit;
        }

        return min($limit * self::RERANK_OVERFETCH_FACTOR, self::RERANK_POOL_CAP);
    }

    /**
     * Whether second-stage reranking is enabled. Mirrors the file-search gate so
     * graph/RAG reranking turns on with the same single config switch.
     */
    private function isRerankEnabled(): bool
    {
        return function_exists('config') && (bool) config('ai.rag.rerank_enabled', false);
    }

    /**
     * Whether hybrid (dense + sparse/lexical) retrieval fusion is enabled.
     */
    private function isHybridEnabled(): bool
    {
        return function_exists('config') && (bool) config('ai.rag.hybrid_enabled', false);
    }

    /**
     * Re-order the selected-entity map by fusing the dense semantic ranking (the
     * incoming order) with a sparse/lexical ranking over each entity's name, aliases
     * and scope phrases, using Reciprocal Rank Fusion.
     *
     * Returns the SAME entities (no add/drop) in a fused order. When hybrid retrieval
     * is disabled, there are fewer than two entities to order, or no entity shares a
     * lexical term with the query, the input map is returned unchanged so the default
     * path is byte-identical.
     *
     * @param array<string, SelectedEntity> $entities Selected entities keyed by FQCN.
     * @return array<string, SelectedEntity>
     */
    private function applyHybridFusion(string $query, array $entities): array
    {
        if (!$this->isHybridEnabled() || count($entities) < 2) {
            return $entities;
        }

        $denseRanking = array_keys($entities);

        $documents = [];
        foreach ($entities as $fullName => $entity) {
            $documents[$fullName] = $this->lexicalTextForEntity($fullName, $entity);
        }

        $sparseRanking = $this->hybridFuser->lexicalRanking($query, $documents);

        // No lexical evidence at all — keep the dense order untouched.
        if (empty($sparseRanking)) {
            return $entities;
        }

        $fusedOrder = $this->hybridFuser->fuse($denseRanking, $sparseRanking);

        $reordered = [];
        foreach ($fusedOrder as $fullName) {
            if (isset($entities[$fullName])) {
                $reordered[$fullName] = $entities[$fullName];
            }
        }

        // Safety net: append anything the fuser somehow omitted, preserving its order.
        foreach ($entities as $fullName => $entity) {
            if (!isset($reordered[$fullName])) {
                $reordered[$fullName] = $entity;
            }
        }

        $this->logger->debug('context_retriever', 'hybrid_fusion_applied', [
            'entity_count' => count($entities),
            'lexical_matches' => count($sparseRanking),
        ]);

        return $reordered;
    }

    /**
     * Assemble the sparse/lexical text for an entity: its label plus configured
     * aliases and scope terminology. This is the lexical field the BM25-ish scorer
     * matches the query against — exactly the surface forms (names, aliases, codes)
     * a paraphrase embedding tends to under-weight.
     */
    private function lexicalTextForEntity(string $fullName, SelectedEntity $entity): string
    {
        $config = $entity->config ?? $this->entityConfigs[$fullName] ?? [];
        $metadata = $config['metadata'] ?? [];

        $parts = [class_basename($fullName)];

        foreach (($metadata['aliases'] ?? []) as $alias) {
            if (is_string($alias)) {
                $parts[] = $alias;
            }
        }

        foreach (array_keys($metadata['scopes'] ?? []) as $scopeName) {
            if (is_string($scopeName)) {
                $parts[] = $scopeName;
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Retrieve example entities for multiple labels with graceful degradation:
     * individual label failures don't stop retrieval for other labels.
     *
     * @param array $labels Array of node labels to retrieve examples for
     * @param int $limit Number of examples per label
     * @param array &$errors Reference to errors array for collecting failures
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

                if (!empty($examples)) {
                    $entities[$label] = $examples;
                }
            } catch (\Exception $e) {
                // Individual label failures are acceptable — don't fail the whole retrieval.
                $this->logger->error('context_retriever', "Example retrieval for label '{$label}' failed: " . $e->getMessage());
                $errors[] = "Example retrieval for label '{$label}' failed: " . $e->getMessage();
            }
        }

        return $entities;
    }

    /**
     * Get properties grouped by label from entity configurations.
     *
     * @return array Properties grouped by label: ['Customer' => ['id', 'name'], ...]
     */
    private function getPropertiesByLabel(): array
    {
        $propertiesByLabel = [];

        foreach ($this->entityConfigs as $entityName => $config) {
            $graphConfig = $config['graph'] ?? [];
            $label = $graphConfig['label'] ?? $entityName;

            $properties = $graphConfig['properties'] ?? [];

            if (!empty($properties)) {
                $propertiesByLabel[$label] = $properties;
            }
        }

        return $propertiesByLabel;
    }

    /**
     * Validate label name to prevent Cypher injection.
     *
     * Label names must start with a letter or underscore and contain only
     * alphanumeric characters, underscores, and hyphens.
     */
    private function isValidLabel(string $label): bool
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $label) === 1;
    }

    /**
     * Get the importance weight for a relationship type.
     */
    public function getRelationshipWeight(string $relationshipType): float
    {
        $weights = config('ai.relationship_weights', []);
        return $weights[$relationshipType] ?? $weights['default'] ?? 0.5;
    }

    /**
     * Filter relationships by minimum importance threshold.
     */
    public function filterRelationshipsByImportance(array $relationships, float $threshold = 0.5): array
    {
        return array_filter($relationships, function ($rel) use ($threshold) {
            $type = is_array($rel) ? ($rel['type'] ?? '') : $rel;
            return $this->getRelationshipWeight($type) >= $threshold;
        });
    }

    /**
     * Compute a retrieval-confidence signal from a top-k score distribution.
     *
     * Pure, side-effect-free read of the score distribution; does not change
     * retrieval behavior. Delegates to TokenBudgeter.
     *
     * @param array<int|float> $scores Top-k relevance scores (any order).
     * @param int              $k      How many of the top scores to consider (default 5).
     * @return array{overall: float, strength: float, margin: float, top_score: float, considered: int}
     */
    public function computeRetrievalConfidence(array $scores, int $k = 5): array
    {
        return $this->tokenBudgeter->confidence($scores, $k);
    }
}
