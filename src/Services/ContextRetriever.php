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
use Condoedge\Ai\Services\Retrieval\ContextQualityAssessor;
use Condoedge\Ai\Services\Retrieval\CalculationMatcher;
use Condoedge\Ai\Services\Retrieval\EntityMetadataBuilder;
use Condoedge\Ai\Services\Retrieval\EntitySelectionPruner;
use Condoedge\Ai\Services\Retrieval\SchemaProjector;
use Condoedge\Ai\Services\Retrieval\SimilarQuerySearcher;
use Condoedge\Ai\Services\Schema\GraphSchemaCache;
use Condoedge\Ai\Services\Schema\PropertyTypeSampler;
use Condoedge\Ai\Services\Schema\SchemaCardBuilder;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\GuardedPipelineObserver;
use Condoedge\Ai\Services\Response\PiiMasker;

/**
 * ContextRetriever
 *
 * Thin orchestrator for Retrieval-Augmented Generation (RAG). For a question it
 * aggregates similar past Q->Cypher pairs, a relevance-filtered slice of the
 * graph schema, and the metadata/scopes of the selected
 * entities into a single PipelineContext.
 *
 * Each responsibility is owned by a focused collaborator:
 * - SimilarQuerySearcher  — vector similarity search for past questions
 * - SchemaProjector       — schema relevance filtering + entity-label fallback
 * - EntityMetadataBuilder — detected entity/scope metadata (semantic + fallback)
 * - ContextQualityAssessor— completeness scoring
 *
 * @package Condoedge\Ai\Services
 */
class ContextRetriever implements ContextRetrieverInterface
{
    use ResolvesEntityConfigKey;

    private const DEFAULT_COLLECTION = 'questions';
    private const DEFAULT_LIMIT = 5;
    /**
     * Fallback minimum cosine similarity for few-shot (similar-query) examples when
     * ai.query_generation.example_min_similarity is absent. Was 0.0 — ANY
     * similarity counted, so unrelated stored queries were served as examples.
     */
    private const DEFAULT_SCORE_THRESHOLD = 0.80;

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

    /** Caches the store schema (Task 5.3): ingest-scoped data, fetched once per TTL. */
    private GraphSchemaCache $schemaCache;

    /** Per-label property types for the schema cards (Task 5.2), cached per label. */
    private PropertyTypeSampler $propertyTypeSampler;

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
        ?GraphSchemaCache $schemaCache = null,
        ?PropertyTypeSampler $propertyTypeSampler = null,
    ) {
        $this->schemaCache = $schemaCache ?? new GraphSchemaCache();
        $this->propertyTypeSampler = $propertyTypeSampler
            ?? new PropertyTypeSampler($this->graphStore, $this->schemaCache);
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
     * relevance-filtered schema and entity metadata into a
     * single PipelineContext, degrading gracefully on partial failures.
     *
     * @param string $question Natural language question from user
     * @param array $options Configuration options (collection, limit, includeSchema,
     *                       scoreThreshold, useSemanticSelection, conversation_context,
     *                       page_context)
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
        $scoreThreshold = is_numeric($options['scoreThreshold'] ?? null)
            ? (float) $options['scoreThreshold']
            : $this->exampleMinSimilarity();
        $useSemanticSelection = $options['useSemanticSelection'] ?? true;

        $ctx = new PipelineContext();
        // Stamp the question the context was built for, so the grounded response path
        // (OriginalQuestionSection) sees what was actually asked instead of narrating
        // blindly over rows. Previously only the eval/test drivers set this.
        $ctx->question = $question;
        // The turn's observer (AiManager::answerQuestion threads it here) rides the
        // context so every downstream LLM stage can report its verbatim prompts.
        // Read-and-strip: no collaborator below ever receives the observer object.
        if (($options['observer'] ?? null) instanceof PipelineObserverInterface) {
            $ctx->observer = GuardedPipelineObserver::wrap($options['observer']);
        }
        unset($options['observer']);

        // P-1 (Phase 8-A review): the turn's ONE PII token map is created BEFORE any
        // LLM sees the conversation. Previous answers carry the real-value table and
        // the sample carries real rows; QU, query generation and the response all
        // receive them masked with this same map (labels = the previous turn's types,
        // so entity-config sensible_columns apply too).
        $turnConversation = is_array($options['conversation_context'] ?? null) ? $options['conversation_context'] : [];
        $ctx->piiMasker = PiiMasker::forConversation($turnConversation);

        // STEP 1: Query Understanding (always runs if available)
        if ($this->queryUnderstanding !== null) {
            try {
                $entityCatalog = QueryUnderstandingService::buildEntityCatalog($this->entityConfigs);
                // QU judges follow_up_reference against the conversation
                // (Task 1.22) — it must actually receive it.
                $conversationContext = is_array($options['conversation_context'] ?? null)
                    ? $options['conversation_context']
                    : [];
                $ctx->qu = $this->queryUnderstanding->analyze(
                    $question,
                    $entityCatalog,
                    $ctx->piiMasker->maskConversation($conversationContext),
                    PipelineObserverScope::for($ctx)
                );

                $this->logger->info('context_retriever', 'Query understanding complete', [
                    'entity_types' => $ctx->qu->entityTypes,
                    'question_type' => $ctx->qu->questionType,
                    'intent' => $ctx->qu->intent,
                    'is_possessive' => $ctx->qu->isPossessive,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('context_retriever', 'Query understanding failed: ' . $e->getMessage());
                $ctx->addError('Query understanding failed: ' . $e->getMessage());
                $ctx->qu = QueryUnderstandingResult::failed();
            }
        } else {
            $ctx->qu = QueryUnderstandingResult::empty($question);
        }

        // A CONVERSATIONAL turn (small talk, a question about the assistant itself — QU
        // said so and named nothing to look up) is answered from the assistant's identity
        // alone: no entity selection, few-shot search, schema or metadata is built for it
        // (each an embedding / vector / graph call the turn never reads).
        if ($ctx->qu->isConversational()) {
            $this->logger->stopTimer('total');
            $this->logger->info('context_retriever', 'Conversational turn: no context retrieved');

            return $ctx;
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

        // Full graph schema, fetched at most once per turn (cached per TTL) and shared by
        // selection pruning (live patterns) and STEP 4. A failure is remembered and
        // re-thrown to STEP 4, which reports it — never fetched twice.
        $fullSchema = null;
        $schemaFailure = null;
        $loadSchema = function () use (&$fullSchema, &$schemaFailure): array {
            if ($schemaFailure !== null) {
                throw $schemaFailure;
            }
            try {
                return $fullSchema ??= $this->getGraphSchema();
            } catch (\Exception $e) {
                $schemaFailure = $e;
                throw $e;
            }
        };

        // What the question is about (Task 5.10 seed tier), in priority order:
        // QU entity types, the augmentation anchor, the page entity, the focus carry.
        $seeds = [];
        $pageSeed = [];
        $focusSeed = [];

        if ($useSemanticSelection && $this->contextSelector !== null) {
            try {
                $ctx->semanticSelection = $this->contextSelector->selectRelevantContext(
                    $retrievalQuery,
                    $this->entityConfigs,
                    [
                        'threshold' => config('ai.semantic_context.threshold', 0.65),
                        'top_k' => config('ai.semantic_context.top_k', 10),
                        // Personal possession only: the selector expands the user's own ownership
                        // paths; a collective "our …" stays on the team scope.
                        'is_possessive' => $ctx->qu->isPersonalPossessive(),
                        'query_understanding' => [
                            'entity_types' => $ctx->qu->entityTypes,
                            'is_possessive' => $ctx->qu->isPersonalPossessive(),
                        ],
                    ]
                );

                // FOCUS (Phase 2.B): this turn's focus is QU's entity types
                // (entityTypes[0] first; all merged below at qu_entity_score).
                // The conversation's focused entity — recorded AFTER the previous
                // turn executed, never guessed from this question's text — is the
                // fallback carry: soft-included only when QU named no entity type
                // ("and the ones in Sales?"), so an explicit new subject is never
                // diluted by the previous turn's entity.
                if ($conversationFocusedEntity && $ctx->qu->entityTypes === []) {
                    $focusFullName = $this->findFullEntityName($conversationFocusedEntity, $this->entityConfigs);
                    if ($focusFullName) {
                        $focusSeed[$this->labelFor($focusFullName)] = "Conversation focus: '{$conversationFocusedEntity}'";
                    }
                    if ($focusFullName && !isset($ctx->semanticSelection->entities[$focusFullName])) {
                        $ctx->semanticSelection->entities[$focusFullName] = new SelectedEntity(
                            score: config('ai.semantic_context.conversation_focus_score', 0.55),
                            matchedText: "Conversation focus: '{$conversationFocusedEntity}'",
                            config: $this->entityConfigs[$focusFullName] ?? null,
                            source: SelectedEntity::SOURCE_SEED,
                        );
                    }
                }

                // Soft-include the page's entity type (same mechanism as the conversation focus).
                // Soft: only added when it matches the catalog and isn't already selected — it
                // ranks the current record's entity, it never excludes others.
                if ($pageEntityType) {
                    $pageFullName = $this->findFullEntityName($pageEntityType, $this->entityConfigs);
                    if ($pageFullName) {
                        $pageSeed[$this->labelFor($pageFullName)] = "Page context: '{$pageEntityType}'";
                    }
                    if ($pageFullName && !isset($ctx->semanticSelection->entities[$pageFullName])) {
                        $ctx->semanticSelection->entities[$pageFullName] = new SelectedEntity(
                            score: config('ai.semantic_context.page_context_focus_score', 0.55),
                            matchedText: "Page context: '{$pageEntityType}'",
                            config: $this->entityConfigs[$pageFullName] ?? null,
                            source: SelectedEntity::SOURCE_SEED,
                        );
                    }
                }

                // Merge QU entity_types into semantic selection
                foreach ($ctx->qu->entityTypes as $label) {
                    $fullName = $this->findFullEntityName($label, $this->entityConfigs);
                    if ($fullName) {
                        $seeds[$this->labelFor($fullName)] ??= "Query Understanding: '{$label}'";
                    }
                    if ($fullName && !isset($ctx->semanticSelection->entities[$fullName])) {
                        $ctx->semanticSelection->entities[$fullName] = new SelectedEntity(
                            score: config('ai.semantic_context.qu_entity_score', 0.8),
                            matchedText: "Query Understanding: '{$label}'",
                            config: $this->entityConfigs[$fullName] ?? null,
                            source: SelectedEntity::SOURCE_SEED,
                        );
                    }
                }

                // AUGMENTATION ANCHOR (item B — iteration-11 sentinel red): a
                // QU-flagged follow-up (followUpReference) that operates on a
                // recorded DATA result always re-seeds the PREVIOUS turn's entity
                // types (last_entity_types; focused_entity as fallback) at the
                // same strength QU entity types get — IN ADDITION to whatever
                // entity types QU produced this turn (none, as in "add their last
                // login to it" → entity_types=[], or new ones). Already-selected
                // entities are left as they are. Gated on the recorded anchor
                // (last_cypher_query), so follow-ups without a data anchor are
                // untouched.
                $conversationContext = $options['conversation_context'] ?? [];
                if ($ctx->qu->followUpReference
                    && trim((string) ($conversationContext['last_cypher_query'] ?? '')) !== '') {
                    $anchorLabels = (array) ($conversationContext['last_entity_types'] ?? []);
                    if ($anchorLabels === [] && $conversationFocusedEntity) {
                        $anchorLabels = [$conversationFocusedEntity];
                    }
                    foreach ($anchorLabels as $label) {
                        if (!is_string($label) || $label === '') {
                            continue;
                        }
                        $fullName = $this->findFullEntityName($label, $this->entityConfigs);
                        if ($fullName) {
                            $seeds[$this->labelFor($fullName)] ??= "Augmentation anchor (previous turn): '{$label}'";
                        }
                        if ($fullName && !isset($ctx->semanticSelection->entities[$fullName])) {
                            $ctx->semanticSelection->entities[$fullName] = new SelectedEntity(
                                score: config('ai.semantic_context.anchor_entity_score', 0.8),
                                matchedText: "Augmentation anchor (previous turn): '{$label}'",
                                config: $this->entityConfigs[$fullName] ?? null,
                                source: SelectedEntity::SOURCE_SEED,
                            );
                        }
                    }
                }

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

                // CALCULATIONS (Task 5.11): which canonical metric(s) the question asks
                // for, by embedding similarity — rendered alone by CalculationsSection,
                // and their labels seed the selection below.
                $calculationLabels = $this->matchCalculations($ctx, $question, $retrievalQuery);

                // PRUNING (Task 5.10): replace the vector union with the tiered,
                // structurally-closed label set. Needs the live patterns, so the
                // (cached) schema is loaded here whenever the schema is wanted at all.
                if ($this->pruningEnabled()) {
                    $schemaForPruning = null;
                    if ($includeSchema) {
                        try {
                            $schemaForPruning = $loadSchema();
                        } catch (\Exception $e) {
                            // STEP 4 reports the failure; prune on configured joins only.
                        }
                    }
                    $this->applyPruning($ctx, $seeds + $pageSeed + $focusSeed, $schemaForPruning, $calculationLabels);
                }

                $this->logger->info('context_retriever', 'Semantic context selected', [
                    'method' => $ctx->semanticSelection->selectionMethod,
                    'entity_count' => count($ctx->semanticSelection->entities),
                    'scope_count' => count($ctx->semanticSelection->scopes),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('context_retriever', 'Semantic context selection failed: ' . $e->getMessage());
                $this->logger->fallback('context_retriever', 'semantic_selection', 'full_schema', $e->getMessage());
                $ctx->addError('Semantic context selection failed: ' . $e->getMessage());

                // The vector selection failed, but Query Understanding still named the
                // question's entity types: prune from those seeds instead of falling back
                // to the legacy label search (live: 17 labels shipped on a store timeout).
                $this->pruneFromSeedsOnly($ctx, $loadSchema, $includeSchema);
            }
        }

        // STEP 3: Similar queries (the top-$limit by similarity)
        try {
            $candidates = $this->similarQuerySearcher->search($retrievalQuery, $collection, $limit, $scoreThreshold);
            $ctx->similarQueries = array_slice(array_values($candidates), 0, max(0, $limit));
        } catch (\Exception $e) {
            $this->logger->error('context_retriever', 'Vector search failed: ' . $e->getMessage());
            $ctx->addError('Vector search failed: ' . $e->getMessage());
        }

        // STEP 4: Graph schema
        if ($includeSchema) {
            try {
                $fullSchema = $loadSchema();
                $pruned = ($ctx->selectionInfo['pruned'] ?? false) === true;

                if ($pruned) {
                    // The pruner already decided every label (identity included).
                    $ctx->graphSchema = $this->schemaProjector->projectLabels(
                        $fullSchema,
                        (array) $ctx->selectionInfo['selected_entities']
                    );

                    // Never ship an empty schema block: the pruned labels projecting to
                    // nothing (unknown to the graph at projection time) falls back to the
                    // relevance filter, then to the full schema.
                    if (empty($ctx->graphSchema['labels'])) {
                        $this->logger->fallback('context_retriever', 'pruned_projection', 'relevance_filter', 'pruned labels absent from the graph schema');
                        $ctx->selectionInfo['projection_fallback'] = true;
                        $ctx->graphSchema = $this->schemaProjector->filterByRelevance($fullSchema, $ctx->semanticSelection);
                        if (empty($ctx->graphSchema['labels'])) {
                            $ctx->graphSchema = $fullSchema;
                        }
                    }
                } elseif (!empty($ctx->semanticSelection->entities)) {
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

                $identityLabels = $pruned ? [] : $this->identityEntityLabels();
                if (!empty($identityLabels)) {
                    $ctx->graphSchema = $this->schemaProjector->includeLabels(
                        $ctx->graphSchema,
                        $fullSchema,
                        $identityLabels
                    );
                    $this->annotateIdentityLabels($ctx, $fullSchema, $identityLabels);
                }

                $ctx->graphSchema['all_relationship_types'] = $fullSchema['relationships'] ?? [];
                // null = the pattern scan failed (unknown) — kept null, never coerced to
                // [] ("no edges"), so the cards fall back to configured edges (review I-1).
                $ctx->graphSchema['all_relationship_patterns'] = $fullSchema['relationshipPatterns'] ?? null;
                // Full-graph label / property-key sets for CypherSchemaValidator (Task 5.4):
                // the projected 'labels' are a per-question selection, so existence checks
                // need the whole graph's vocabulary.
                $ctx->graphSchema['all_labels'] = $fullSchema['labels'] ?? null;
                $ctx->graphSchema['all_property_keys'] = $fullSchema['propertyKeys'] ?? null;
                // Property value types, probed ONLY for the labels that will get a
                // schema card (first MAX_CARDS in selection order — review I-3); cached
                // per label, a failed label just renders untyped.
                $ctx->graphSchema['property_types'] = $this->propertyTypeSampler->forLabels(
                    SchemaCardBuilder::cardLabels(
                        (array) ($ctx->graphSchema['labels'] ?? []),
                        (array) ($ctx->selectionInfo['selected_entities'] ?? [])
                    )
                );
            } catch (\Exception $e) {
                $this->logger->error('context_retriever', 'Schema retrieval failed: ' . $e->getMessage());
                $ctx->addError('Schema retrieval failed: ' . $e->getMessage());
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
        return $this->similarQuerySearcher->search($question, $collection, $limit, $this->exampleMinSimilarity());
    }

    /**
     * The minimum similarity a few-shot example must reach:
     * ai.query_generation.example_min_similarity when NUMERIC (an empty env value
     * like AI_EXAMPLE_MIN_SIMILARITY="" must not silently become 0.0), else the
     * 0.80 fallback.
     */
    private function exampleMinSimilarity(): float
    {
        $configured = config('ai.query_generation.example_min_similarity');

        return is_numeric($configured) ? (float) $configured : self::DEFAULT_SCORE_THRESHOLD;
    }

    /**
     * {@inheritDoc}
     *
     * Retrieves and normalizes the graph schema: node labels, relationship types,
     * exact relationship patterns, a flat property-key list, and per-label properties
     * from entity configs.
     *
     * @return array{labels: array, relationships: array, properties: array, propertyKeys: array}
     *
     * @throws \RuntimeException If schema retrieval fails
     */
    public function getGraphSchema(): array
    {
        // Cached (Task 5.3): the schema only changes on ingest, which flushes it.
        $schema = $this->schemaCache->remember(fn () => $this->graphStore->getSchema());

        $propertiesByLabel = $this->getPropertiesByLabel();

        // Normalize schema structure to a consistent format.
        return [
            'labels' => $schema['labels'] ?? [],
            'relationships' => $schema['relationshipTypes'] ?? $schema['relationships'] ?? [],
            // null = unknown (pattern scan failed / store gave none) — never [] (review I-1).
            'relationshipPatterns' => $schema['relationshipPatterns'] ?? $schema['relationship_patterns'] ?? null,
            'properties' => $propertiesByLabel, // Now structured by label
            'propertyKeys' => $schema['propertyKeys'] ?? $schema['properties'] ?? [], // Keep flat list too
        ];
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
     * Match the configured canonical calculations against the question (raw + QU
     * rewrite) and record the result in selectionInfo['calculations']; returns the
     * matched calculations' graph labels. Off / nothing configured: [] and no record.
     *
     * @return string[]
     */
    private function matchCalculations(PipelineContext $ctx, string $question, string $retrievalQuery): array
    {
        $calculations = function_exists('config') ? config('ai.calculations', []) : [];
        if (!is_array($calculations) || $calculations === []
            || !(bool) config('ai.calculation_matching.enabled', true)) {
            return [];
        }

        $matched = CalculationMatcher::fromConfig($this->embeddingProvider, $this->logger)
            ->match($calculations, [$question, $retrievalQuery]);
        if ($matched === null) {
            return []; // could not judge: CalculationsSection falls back to label overlap
        }

        $ctx->selectionInfo['calculations'] = ['matched' => $matched];

        $labels = [];
        foreach (array_keys($matched) as $key) {
            foreach (CalculationMatcher::labelsOf((array) $calculations[$key]) as $label) {
                $labels[$label] = true;
            }
        }

        // The metric the question asks for is the strongest structured signal of the
        // answer's shape: detected concepts (scopes) on OTHER entities are dropped — live
        // q4, three "receivable ACCOUNTS" concepts out-voted the receivables formula.
        if ($labels !== []) {
            $keep = array_change_key_case($labels, CASE_LOWER);
            $ctx->semanticSelection->scopes = array_filter(
                $ctx->semanticSelection->scopes,
                function ($scope) use ($keep): bool {
                    $fullName = $this->findFullEntityName((string) $scope->entity, $this->entityConfigs);
                    $label = $fullName !== null ? $this->labelFor($fullName) : class_basename((string) $scope->entity);

                    return isset($keep[strtolower($label)]);
                }
            );
        }

        return array_keys($labels);
    }

    /**
     * Seed-only pruning after a failed vector selection: the QU entity types are the
     * seeds (no scopes, no vector hits). Leaves the selection empty — the legacy
     * fallback — when pruning is off, QU named nothing, or pruning itself fails.
     */
    private function pruneFromSeedsOnly(PipelineContext $ctx, \Closure $loadSchema, bool $includeSchema): void
    {
        if (!$this->pruningEnabled() || $ctx->qu->entityTypes === []) {
            return;
        }

        $seeds = [];
        foreach ($ctx->qu->entityTypes as $label) {
            $fullName = is_string($label) ? $this->findFullEntityName($label, $this->entityConfigs) : null;
            if ($fullName !== null) {
                $seeds[$this->labelFor($fullName)] ??= "Query Understanding: '{$label}'";
            }
        }
        if ($seeds === []) {
            return;
        }

        try {
            $schema = null;
            if ($includeSchema) {
                try {
                    $schema = $loadSchema();
                } catch (\Exception $e) {
                    // STEP 4 reports the failure.
                }
            }

            $ctx->semanticSelection = new SemanticSelectionResult(selectionMethod: 'seed_only');
            $ctx->selectionInfo = ['method' => 'seed_only', 'selected_entities' => [], 'qu_used' => true];
            $this->applyPruning($ctx, $seeds, $schema);

            if (($ctx->selectionInfo['pruned'] ?? false) !== true) {
                $ctx->semanticSelection = new SemanticSelectionResult();
                $ctx->selectionInfo = [];
            }
        } catch (\Throwable $e) {
            $this->logger->error('context_retriever', 'Seed-only pruning failed: ' . $e->getMessage());
            $ctx->semanticSelection = new SemanticSelectionResult();
            $ctx->selectionInfo = [];
        }
    }

    /**
     * Whether entity-selection pruning (Task 5.10) replaces the vector union.
     */
    private function pruningEnabled(): bool
    {
        return !function_exists('config') || (bool) config('ai.semantic_context.pruning', true);
    }

    /**
     * The graph label of an entity config key (graph.label, else the class basename).
     */
    private function labelFor(string $configKey): string
    {
        $label = $this->entityConfigs[$configKey]['graph']['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : class_basename($configKey);
    }

    /**
     * Replace the assembled selection with the {@see EntitySelectionPruner} result:
     * the selection map (FQCN-keyed, real configs), the relationships whose ends
     * both survive, and selectionInfo (ordered labels, tiers, reasons, identity).
     * Leaves the selection untouched when the pruner finds nothing to keep.
     *
     * @param array<string, string> $seeds label => reason
     */
    private function applyPruning(PipelineContext $ctx, array $seeds, ?array $schema, array $calculationLabels = []): void
    {
        $selection = $ctx->semanticSelection;

        $scopeLabels = [];
        foreach ($selection->scopes as $scope) {
            $fullName = $this->findFullEntityName((string) $scope->entity, $this->entityConfigs);
            $scopeLabels[] = $fullName !== null ? $this->labelFor($fullName) : class_basename((string) $scope->entity);
        }

        $vectorHits = [];
        foreach ($selection->entities as $key => $entity) {
            if (in_array($entity->source, [SelectedEntity::SOURCE_VECTOR, SelectedEntity::SOURCE_KEYWORD], true)) {
                $label = isset($this->entityConfigs[$key]) ? $this->labelFor((string) $key) : class_basename((string) $key);
                $vectorHits[$label] = max($entity->score, $vectorHits[$label] ?? -INF);
            }
        }

        $pruned = EntitySelectionPruner::fromConfig($this->entityConfigs)->prune(
            seeds: $seeds,
            scopeLabels: array_values(array_unique($scopeLabels)),
            vectorHits: $vectorHits,
            // Two signals, not one (eval p17): the identity anchors serve personal AND
            // collective possession (the Team anchor of "we owe…"); the ownership-path
            // labels are how the asker's OWN records are reached, so personal only.
            isPossessive: $ctx->qu->isPersonalPossessive() || $ctx->qu->isCollectivePossessive(),
            schemaLabels: $schema !== null ? (array) ($schema['labels'] ?? []) : null,
            patterns: $schema !== null ? ($schema['relationshipPatterns'] ?? null) : null,
            calculationLabels: $calculationLabels,
            ownershipPaths: $ctx->qu->isPersonalPossessive(),
        );

        if ($pruned->isEmpty()) {
            return;
        }

        $existingByLabel = [];
        foreach ($selection->entities as $key => $entity) {
            $label = isset($this->entityConfigs[$key]) ? $this->labelFor((string) $key) : class_basename((string) $key);
            $existingByLabel[$label] ??= $entity;
        }

        $entities = [];
        foreach ($pruned->labels as $label) {
            $key = $this->findFullEntityName($label, $this->entityConfigs) ?? $label;
            $existing = $existingByLabel[$label] ?? null;
            $tier = $pruned->tiers[$label] ?? EntitySelectionPruner::TIER_SEED;
            $entities[$key] = new SelectedEntity(
                score: $existing?->score ?? ($tier === EntitySelectionPruner::TIER_SEED ? 0.8 : 0.6),
                matchedText: $pruned->reasons[$label] ?? $tier,
                config: $this->entityConfigs[$key] ?? $existing?->config,
                source: in_array($tier, [EntitySelectionPruner::TIER_SEED, EntitySelectionPruner::TIER_VECTOR, EntitySelectionPruner::TIER_SCOPE], true)
                    ? ($existing?->source ?? SelectedEntity::SOURCE_SEED)
                    : SelectedEntity::SOURCE_PRUNER,
            );
        }
        $selection->entities = $entities;

        $kept = array_fill_keys($pruned->labels, true);
        $selection->relationships = array_filter(
            $selection->relationships,
            fn ($relationship) => $relationship->fromEntity !== '' && $relationship->toEntity !== ''
                && isset($kept[class_basename($relationship->fromEntity)], $kept[class_basename($relationship->toEntity)])
        );

        $ctx->selectionInfo = array_merge($ctx->selectionInfo, [
            'selected_entities' => $pruned->labels,
            'entities_selected' => count($pruned->labels),
            'relationships_selected' => count($selection->relationships),
            'pruned' => true,
            'selection_tiers' => $pruned->tiers,
            'selection_reasons' => $pruned->reasons,
            'identity_entities' => $pruned->identityLabels,
            'pruned_by_cap' => $pruned->dropped,
        ]);
    }

    /**
     * Labels that should stay in the filtered graph schema as identity anchors.
     *
     * Host apps can override this list when their auth/team concepts use different
     * node labels. An empty array disables the behavior.
     *
     * @return string[]
     */
    private function identityEntityLabels(): array
    {
        $labels = function_exists('config')
            ? config('ai.semantic_context.always_include_identity_labels', ['User', 'Team'])
            : ['User', 'Team'];

        if (!is_array($labels)) {
            return [];
        }

        $normalized = [];
        foreach ($labels as $label) {
            if (!is_string($label)) {
                continue;
            }

            $label = trim($label);
            if ($label !== '') {
                $normalized[] = $label;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Mark identity labels that actually exist in the graph schema. This makes
     * audit/debug output explicit: these were forced as current identity anchors,
     * not selected by semantic similarity.
     *
     * @param string[] $identityLabels
     */
    private function annotateIdentityLabels(PipelineContext $ctx, array $fullSchema, array $identityLabels): void
    {
        $available = array_fill_keys($fullSchema['labels'] ?? [], true);
        $included = [];

        foreach ($identityLabels as $label) {
            if (isset($available[$label])) {
                $included[] = $label;
            }
        }

        if (empty($included)) {
            return;
        }

        $selected = $ctx->selectionInfo['selected_entities'] ?? [];
        $ctx->selectionInfo['selected_entities'] = array_values(array_unique(array_merge($selected, $included)));
        $ctx->selectionInfo['identity_entities'] = array_values(array_unique($included));
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

            // graph.computed values are written as real node properties at ingest.
            foreach (array_keys((array) ($graphConfig['computed'] ?? [])) as $computed) {
                if (is_string($computed) && !in_array($computed, $properties, true)) {
                    $properties[] = $computed;
                }
            }

            if (!empty($properties)) {
                $propertiesByLabel[$label] = $properties;
            }
        }

        return $propertiesByLabel;
    }
}
