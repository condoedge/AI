<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Response\ResponseFileEnricher;
use Condoedge\Ai\Services\Response\ResponseEntityEnricher;

/**
 * AI Manager - thin orchestrator over the AI pipeline services.
 *
 * Owns the single canonical chat entry point (answerQuestion) plus the
 * ingest/context/response/file/embedding/llm delegations. All dependencies are
 * injected via constructor, keeping the class fully testable and aligned with
 * Laravel's container.
 *
 * @package Condoedge\Ai\Services
 */
class AiManager
{
    /**
     * Relevance window (raw-cosine) within which two angles' results for the same
     * file are treated as equally on-topic when choosing which angle's snippet to
     * keep. Within the window the deeper (past-cover) snippet is preferred so the
     * operative clause is not displaced by a marginally-higher-scoring cover chunk.
     */
    private const SNIPPET_RELEVANCE_MARGIN = 0.12;

    private readonly QueryParameterizer $queryParameterizer;

    /**
     * @param DataIngestionServiceInterface $ingestion Data ingestion service
     * @param ContextRetrieverInterface $context Context retrieval (RAG) service
     * @param EmbeddingProviderInterface $embedding Embedding generation service
     * @param LlmProviderInterface $llm Language model service
     * @param QueryGeneratorInterface $queryGenerator Query generation service
     * @param QueryExecutorInterface $queryExecutor Query execution service
     * @param ResponseGeneratorInterface $responseGenerator Response generation service
     * @param VectorStoreInterface $vectorStore Vector store for query storage
     * @param FileContextProvider|null $fileContextProvider File context provider for file-based RAG
     * @param ResponseFileEnricher|null $responseFileEnricher Response enricher for file references
     * @param ResponseEntityEnricher|null $responseEntityEnricher Response enricher for entity actions
     * @param QueryParameterizer|null $queryParameterizer Identity-aware (de)parameterizer for stored queries
     */
    public function __construct(
        private readonly DataIngestionServiceInterface $ingestion,
        private readonly ContextRetrieverInterface $context,
        private readonly EmbeddingProviderInterface $embedding,
        private readonly LlmProviderInterface $llm,
        private readonly QueryGeneratorInterface $queryGenerator,
        private readonly QueryExecutorInterface $queryExecutor,
        private readonly ResponseGeneratorInterface $responseGenerator,
        private readonly VectorStoreInterface $vectorStore,
        private readonly ?FileContextProvider $fileContextProvider = null,
        private readonly ?ResponseFileEnricher $responseFileEnricher = null,
        private readonly ?ResponseEntityEnricher $responseEntityEnricher = null,
        ?QueryParameterizer $queryParameterizer = null
    ) {
        $this->queryParameterizer = $queryParameterizer ?? new QueryParameterizer();
    }

    // =========================================================================
    // Data Ingestion Methods
    // =========================================================================

    /**
     * Ingest an entity into both graph and vector stores
     *
     * @param Nodeable $entity Entity to ingest (must implement Nodeable)
     * @return array Status array with keys: graph_stored, vector_stored, relationships_created, errors
     */
    public function ingest(Nodeable $entity): array
    {
        return $this->ingestion->ingest($entity);
    }

    /**
     * Ingest multiple entities in batch (more efficient)
     *
     * @param array $entities Array of Nodeable entities
     * @return array Summary with keys: total, succeeded, partially_succeeded, failed, errors
     */
    public function ingestBatch(array $entities): array
    {
        return $this->ingestion->ingestBatch($entities);
    }

    /**
     * Remove an entity from both stores
     *
     * @param Nodeable $entity Entity to remove
     * @return bool True if removed successfully
     */
    public function remove(Nodeable $entity): bool
    {
        return $this->ingestion->remove($entity);
    }

    /**
     * Sync an entity (update if exists, create if not)
     *
     * @param Nodeable $entity Entity to sync
     * @return array Status array with keys: action, graph_synced, vector_synced, errors
     */
    public function sync(Nodeable $entity): array
    {
        return $this->ingestion->sync($entity);
    }

    /**
     * Reconcile missing relationships for entities whose targets may not have
     * existed at initial ingestion time.
     *
     * Delegates to the ingestion service. Used by RelatedModelSyncObserver to
     * keep the graph consistent when pivot/related models change.
     *
     * @param array $entities Array of Nodeable entities to sync relationships for
     * @return array Reconciliation summary (see DataIngestionServiceInterface::syncRelationships)
     */
    public function syncRelationships(array $entities): array
    {
        return $this->ingestion->syncRelationships($entities);
    }

    // =========================================================================
    // Context Retrieval (RAG) Methods
    // =========================================================================

    /**
     * Retrieve context for a question (RAG)
     *
     * Combines vector similarity search with graph schema discovery
     * to provide rich context for LLM query generation.
     *
     * @param string $question The question to get context for
     * @param array $options Optional parameters (collection, limit, includeSchema, includeExamples, ...)
     * @return PipelineContext Typed context object containing all pipeline data
     */
    public function retrieveContext(string $question, array $options = []): PipelineContext
    {
        return $this->context->retrieveContext($question, $options);
    }

    /**
     * Search for similar questions using vector similarity
     *
     * @param string $question Question to search for
     * @param array $options Optional parameters (collection, limit, scoreThreshold)
     * @return array Array of similar queries with scores
     */
    public function searchSimilar(string $question, array $options = []): array
    {
        return $this->context->searchSimilar(
            $question,
            $options['collection'] ?? 'questions',
            $options['limit'] ?? 5
        );
    }

    /**
     * Get graph database schema
     *
     * @return array Schema with keys: labels, relationships, properties
     */
    public function getSchema(): array
    {
        return $this->context->getGraphSchema();
    }

    /**
     * Get example entities for specified labels
     *
     * @param array $labels Array of label names
     * @param int $limit Number of examples per label (default: 3)
     * @return array Entities grouped by label
     */
    public function getExampleEntities(array $labels, int $limit = 3): array
    {
        // The retriever fetches examples per label; fan out and key by label.
        $entitiesByLabel = [];
        foreach ($labels as $label) {
            $entitiesByLabel[$label] = $this->context->getExampleEntities($label, $limit);
        }

        return $entitiesByLabel;
    }

    /**
     * Store a question-query pair in the vector store for future RAG retrieval
     *
     * The question is embedded and stored with its query in the 'questions' collection,
     * building a knowledge base of question-query pairs used for few-shot learning in
     * future query generation.
     *
     * @param string $question Natural language question
     * @param string|null $cypherQuery The Cypher query that answers the question
     * @param array $metadata Optional metadata to store with the query
     * @param string $collection Collection name (default: 'questions')
     * @return array Result with keys: success, point_id, errors
     */
    public function storeQuery(
        string $question,
        ?string $cypherQuery,
        array $metadata = [],
        string $collection = 'questions'
    ): array {
        try {
            if (!$this->vectorStore->collectionExists($collection)) {
                $vectorSize = $this->embedding->getDimensions();
                $this->vectorStore->createCollection($collection, $vectorSize, 'cosine');
            }

            $embedding = $this->embedding->embed($question);

            $pointId = md5($question . $cypherQuery);

            $payload = array_merge([
                'question' => $question,
                'cypher_query' => $cypherQuery,
                'created_at' => now()->toIso8601String(),
            ], $metadata);

            $success = $this->vectorStore->upsert($collection, [
                [
                    'id' => $pointId,
                    'vector' => $embedding,
                    'payload' => $payload,
                ],
            ]);

            return [
                'success' => $success,
                'point_id' => $pointId,
                'errors' => [],
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'point_id' => null,
                'errors' => [$e->getMessage()],
            ];
        }
    }

    // =========================================================================
    // Embedding Methods
    // =========================================================================

    /**
     * Generate embedding for a text
     *
     * @param string $text Text to embed
     * @return array Vector representation (array of floats)
     */
    public function embed(string $text): array
    {
        return $this->embedding->embed($text);
    }

    // =========================================================================
    // LLM Methods
    // =========================================================================

    /**
     * Chat with the LLM
     *
     * Accepts either a simple string or an array of messages.
     *
     * @param string|array $input Simple string or array of messages
     * @param array $options Optional parameters (temperature, max_tokens, etc.)
     * @return string LLM response
     */
    public function chat(string|array $input, array $options = []): string
    {
        if (is_string($input)) {
            $input = [['role' => 'user', 'content' => $input]];
        }

        return $this->llm->chat($input, $options);
    }

    /**
     * Simple completion (prompt + optional system message)
     *
     * @param string $prompt User prompt
     * @param string|null $systemPrompt Optional system message
     * @param array $options Optional parameters
     * @return string LLM response
     */
    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        return $this->llm->complete($prompt, $systemPrompt, $options);
    }

    // =========================================================================
    // Query Generation Methods
    // =========================================================================

    /**
     * Generate a Cypher query from natural language question
     *
     * @param string $question Natural language question
     * @param PipelineContext|null $context Pipeline context (use retrieveContext() to get this)
     * @param array $options Optional parameters (temperature, max_retries, allow_write, explain, user)
     * @return array Result with keys: cypher, explanation, confidence, warnings, metadata
     */
    public function generateQuery(string $question, ?PipelineContext $context = null, array $options = []): array
    {
        try {
            if ($context === null) {
                $context = $this->context->retrieveContext($question, $options);
            }

            $generation = $this->queryGenerator->generate($question, $context, $options);

            if (isset($generation['cypher']) && $generation['cypher']) {
                // Parameterize user-specific values before storing as reusable examples.
                // This prevents user A's query from leaking data to user B. Identity is
                // threaded from $options['user'] so CLI/queue/eval/impersonation key the
                // stored example to the real pipeline actor, not the ambient session.
                $storedCypher = $this->parameterizerFor($options)->parameterize($generation['cypher']);

                $this->storeQuery(
                    question: $question,
                    cypherQuery: $storedCypher,
                    metadata: [
                        'confidence' => $generation['confidence'],
                        'generated_at' => now()->toIso8601String(),
                    ]
                );
            }

            return $generation;

        } catch (\Throwable $e) {
            \Log::error('Error generating query: ' . $e->getMessage(), [
                'question' => $question,
                'exception' => get_class($e),
            ]);

            return [
                'cypher' => null,
                'explanation' => null,
                'confidence' => 0,
                'warnings' => [$e->getMessage()],
                'metadata' => ['error' => true, 'error_message' => $e->getMessage()],
            ];
        }
    }

    /**
     * Validate a Cypher query for syntax and safety
     *
     * @param string|null $cypherQuery Query to validate
     * @param array $options Validation options (allow_write, max_complexity)
     * @return array Validation result with keys: valid, errors, warnings, complexity, is_read_only
     */
    public function validateQuery(?string $cypherQuery, array $options = []): array
    {
        return $this->queryGenerator->validate($cypherQuery, $options);
    }

    // =========================================================================
    // Query Execution Methods
    // =========================================================================

    /**
     * Execute a Cypher query
     *
     * @param string|null $cypherQuery Cypher query to execute
     * @param array $parameters Query parameters
     * @param array $options Execution options (timeout, limit, read_only, format, include_stats)
     * @return array Execution result with keys: success, data, stats, metadata, errors
     */
    public function executeQuery(?string $cypherQuery, array $parameters = [], array $options = []): array
    {
        return $this->queryExecutor->execute($cypherQuery, $parameters, $options);
    }

    // =========================================================================
    // Response Generation Methods
    // =========================================================================

    /**
     * Generate natural language response from pipeline context
     *
     * @param PipelineContext $context Pipeline context with question, query result, execution data
     * @param array $options Generation options
     * @return array Response with answer, insights, visualizations
     */
    public function generateResponse(
        PipelineContext $context,
        array $options = []
    ): array {
        return $this->responseGenerator->generate($context, $options);
    }

    /**
     * Extract insights from query results
     *
     * @param array $queryResult Query results to analyze
     * @return array Array of insights
     */
    public function extractInsights(array $queryResult): array
    {
        return $this->responseGenerator->extractInsights($queryResult);
    }

    /**
     * Suggest visualizations for query results
     *
     * @param array $queryResult Query results
     * @param string|null $cypherQuery Original query
     * @return array Suggested visualization types
     */
    public function suggestVisualizations(array $queryResult, ?string $cypherQuery): array
    {
        return $this->responseGenerator->suggestVisualizations($queryResult, $cypherQuery);
    }

    /**
     * Complete pipeline: Question → Context → Query → Execute → Respond
     *
     * Steps:
     * 1. Retrieve context (returns PipelineContext DTO with QU + semantic selection)
     * 2. Merge conversation context if provided
     * 3. Retrieve file context using clarified question for better matching
     * 4. Generate and execute Cypher query (or skip for knowledge-only questions)
     * 5. Build and return final response
     *
     * @param string $question Natural language question
     * @param array $options Options for all stages
     * @return array Complete response with natural language answer
     */
    public function answerQuestion(string $question, array $options = []): array
    {
        try {
            $ctx = $this->context->retrieveContext($question, $options);

            if (!empty($options['conversation_context'])) {
                $ctx->conversationContext = $options['conversation_context'];
            }

            // Page context is a soft prior — which page/record the chat was opened from.
            // Symmetric to conversation_context; default-empty keeps the prompt section
            // silent and adds no retrieval/entity-selection bias when absent.
            if (!empty($options['page_context'])) {
                $ctx->pageContext = $options['page_context'];
            }

            $ctx = $this->retrieveAndSetFileContext($ctx, $question, $options);

            $ctx = $this->generateAndExecute($ctx, $question, $options);

            return $this->buildFinalResponse($question, $ctx, $options);

        } catch (\Throwable $e) {
            \Log::error('Error answering question: ' . $e->getMessage());
            $errorResponse = $this->responseGenerator->generateErrorResponse($question, $e, $options);

            return [
                'question' => $question,
                'answer' => $errorResponse['answer'],
                'insights' => $errorResponse['insights'],
                'visualizations' => $errorResponse['visualizations'],
                'cypher' => null,
                'data' => [],
                'stats' => [],
                'referenced_files' => [],
                'metadata' => $errorResponse['metadata'],
            ];
        }
    }

    /**
     * Retrieve file context using clarified question for better semantic matching.
     */
    private function retrieveAndSetFileContext(PipelineContext $ctx, string $question, array $options): PipelineContext
    {
        if (!config('ai.file_context.enabled', true)) {
            return $ctx;
        }

        $user = $options['user'] ?? null;

        // File-listing intent ("which files do I have access to?"): list the user's
        // accessible files (ACL-respecting) instead of semantic retrieval. Derived
        // from the QU intent (config-driven, model-agnostic) — never a hardcoded
        // English phrase — so it activates the moment QU classifies a listing intent.
        if ($this->isFileListingIntent($ctx)) {
            $enumeration = $this->retrieveFileEnumerationContext($user);
            if (!empty($enumeration['relevant_files'] ?? [])) {
                $ctx->fileContext = $enumeration;
                return $ctx;
            }
            // No enumerable files (or enumeration disabled/unavailable): fall through
            // to normal retrieval rather than asserting an empty list.
        }

        // Build search queries from QU output — multiple angles for better recall
        $queries = array_filter(array_unique([
            $ctx->qu->searchQuery,
            $question,
            $ctx->qu->clarifiedQuestion,
        ]));

        if (!empty($ctx->qu->searchTags)) {
            $queries[] = implode(' ', $ctx->qu->searchTags);
        }

        // Cross-lingual angles: when the corpus is multilingual, the QU layer emits
        // the question translated into each configured corpus language (one pure
        // single-language phrasing per language). Add each as a SEPARATE fan-out
        // angle so a question asked in one language still retrieves documents
        // written in another. Empty when no corpus languages are configured, so
        // the fan-out is unchanged for monolingual corpora.
        foreach ($ctx->qu->corpusTranslations as $translation) {
            if (is_string($translation) && trim($translation) !== '') {
                $queries[] = $translation;
            }
        }

        // Page context as one extra retrieval angle (soft prior): the record's label + visible
        // filter values boost recall for the page the chat was opened from WITHOUT excluding any
        // other record (it is just an additional phrasing merged into the fan-out below).
        $pageAngle = $this->pageContextQueryAngle($options['page_context'] ?? []);
        if ($pageAngle !== '') {
            $queries[] = $pageAngle;
        }

        $queries = array_filter(array_unique($queries));

        // Different phrasings find different files AND surface different chunks of
        // the SAME file. Collect every per-angle result per file so the merge can
        // both rank the file by its strongest angle and pick the most useful snippet
        // across angles (not just the highest-cosine one, which is often a generic
        // cover/preamble chunk).
        $perFileResults = [];
        $anyAngleSucceeded = false;
        foreach ($queries as $searchQuery) {
            $fileContext = $this->retrieveFileContext($searchQuery, $user);
            // A successful retrieval (even an empty one) always carries 'file_count';
            // retrieveFileContext returns a bare [] only when the provider threw. This
            // lets the recall net below distinguish "searched, found nothing" (worth a
            // retry at a lower floor) from "retrieval errored" (a retry would re-throw).
            if (isset($fileContext['file_count'])) {
                $anyAngleSucceeded = true;
            }
            foreach ($fileContext['relevant_files'] ?? [] as $file) {
                $fileId = $file['file_id'] ?? $file['filename'] ?? '';
                $perFileResults[$fileId][] = $file;
            }
        }

        $allFiles = [];
        foreach ($perFileResults as $fileId => $results) {
            $allFiles[$fileId] = $this->mergeFileAngleResults($results);
        }

        // Recall safety net: no angle cleared the confident relevance threshold, so the
        // multi-angle merge is empty. Rather than dead-end into a generic, ungrounded
        // answer, retry the SAME angles once at a lower floor and admit the strongest
        // borderline document(s). This fires ONLY when the result would otherwise be
        // empty (so it can never dilute a confident retrieval); the response layer still
        // grounds or abstains, and a borderline-but-on-topic source is strictly more
        // useful to ground on than nothing. Disabled when the floor is not below the
        // confident threshold.
        if (empty($allFiles) && $anyAngleSucceeded) {
            $confident = (float) config('ai.file_context.min_relevance_score', 0.4);
            $floor = (float) config('ai.file_context.recall_floor', 0.3);
            $cap = max(1, (int) config('ai.file_context.recall_limit', 2));

            if ($floor > 0.0 && $floor < $confident) {
                $recallPerFile = [];
                foreach ($queries as $searchQuery) {
                    $fileContext = $this->retrieveFileContext($searchQuery, $user, [
                        'min_score' => $floor,
                        'limit' => $cap,
                    ]);
                    foreach ($fileContext['relevant_files'] ?? [] as $file) {
                        $fileId = $file['file_id'] ?? $file['filename'] ?? '';
                        $recallPerFile[$fileId][] = $file;
                    }
                }
                foreach ($recallPerFile as $fileId => $results) {
                    $allFiles[$fileId] = $this->mergeFileAngleResults($results);
                }
                // Keep only the strongest few borderline docs so a recall rescue never
                // floods the prompt with weak, off-topic sources.
                if (count($allFiles) > $cap) {
                    uasort($allFiles, fn($a, $b) => ($b['priority_score'] ?? $b['relevance'] ?? 0) <=> ($a['priority_score'] ?? $a['relevance'] ?? 0));
                    $allFiles = array_slice($allFiles, 0, $cap, true);
                }
            }
        }

        if (!empty($allFiles)) {
            $files = array_values($allFiles);
            usort($files, fn($a, $b) => ($b['priority_score'] ?? $b['relevance'] ?? 0) <=> ($a['priority_score'] ?? $a['relevance'] ?? 0));

            $ctx->fileContext = [
                'relevant_files' => $files,
                'file_count' => count($files),
                'has_physical' => collect($files)->contains('source', 'physical'),
                'has_database' => collect($files)->contains(fn($f) => ($f['source'] ?? '') !== 'physical'),
            ];
        }

        return $ctx;
    }

    /**
     * Merge the per-angle results for ONE file into a single reference.
     *
     * Each fan-out angle phrases the question differently, so the same file is
     * scored against several probes — and crucially, different probes surface
     * different best chunks of that file. A literal probe often ranks a generic
     * cover/preamble chunk (always near the start of the document) highest, while a
     * concept-rich probe ranks the operative clause (deep in the document) highest
     * but at a slightly lower raw cosine. Keeping only the max-relevance result
     * therefore throws away the operative snippet in favour of the cover page.
     *
     * Resolution (model-agnostic, structural — no semantic word lists):
     *   - Rank the file by its STRONGEST angle (max relevance), so a genuinely
     *     relevant document is not demoted.
     *   - Choose the SNIPPET from the most informative angle: among the angles
     *     whose relevance is within a small margin of that max, prefer the one
     *     whose best chunk sits DEEPEST in the document (largest chunk_index).
     *     Cover/preamble/table-of-contents chunks are by construction the
     *     lowest-index chunks, so a deeper best chunk of comparable relevance is
     *     the substantive content the question is actually about.
     *
     * @param array<int,array> $results The same file's reference from each angle.
     * @return array The merged reference (max relevance for ranking, best snippet).
     */
    private function mergeFileAngleResults(array $results): array
    {
        $maxRelevance = 0.0;
        foreach ($results as $r) {
            $maxRelevance = max($maxRelevance, (float) ($r['relevance'] ?? 0));
        }

        // Angles whose best chunk is close enough to the strongest to be considered
        // equally on-topic; among these the deepest (past-cover) snippet wins.
        $margin = self::SNIPPET_RELEVANCE_MARGIN;
        $best = null;
        foreach ($results as $r) {
            if ((float) ($r['relevance'] ?? 0) < $maxRelevance - $margin) {
                continue;
            }
            if ($best === null
                || (int) ($r['chunk_index'] ?? 0) > (int) ($best['chunk_index'] ?? 0)) {
                $best = $r;
            }
        }

        // Fallback: if nothing qualified (shouldn't happen), keep the first result.
        $merged = $best ?? $results[0];

        // The file's ranking strength is its strongest angle, independent of which
        // angle's snippet was chosen.
        $merged['relevance'] = $maxRelevance;

        return $merged;
    }

    /**
     * Build one extra file-search query angle from page context: the record label plus the
     * visible filter values. Returns '' when there is nothing to add, so the fan-out is
     * unchanged for non-page-aware chats.
     *
     * @param array<string,mixed> $pageContext
     */
    private function pageContextQueryAngle(array $pageContext): string
    {
        if (empty($pageContext)) {
            return '';
        }

        $parts = [];

        if (!empty($pageContext['label'])) {
            $parts[] = (string) $pageContext['label'];
        }

        $filters = $pageContext['filters'] ?? [];
        if (is_array($filters)) {
            foreach ($filters as $value) {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $parts[] = (string) $v;
                    }
                } elseif ($value !== null && $value !== '' && !is_bool($value)) {
                    $parts[] = (string) $value;
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Generate Cypher query and execute it, or skip for knowledge-only questions.
     */
    private function generateAndExecute(PipelineContext $ctx, string $question, array $options): PipelineContext
    {
        $hasFileContext = !empty($ctx->fileContext['relevant_files'] ?? []);
        $isEnumeration = !empty($ctx->fileContext['enumeration'] ?? false);

        // File-enumeration intent: the user's accessible file list IS the answer.
        // Skip Cypher and ground the response on that ACL-filtered list rather than
        // running a graph query that would not produce the listing.
        if ($isEnumeration && $hasFileContext) {
            $ctx->queryResult = new QueryGenerationResult(
                explanation: 'File listing — answered from the user\'s accessible files.',
                confidence: 1.0,
                metadata: ['source' => 'file_context', 'intent' => 'file_listing'],
            );
            $ctx->executionResult = QueryExecutionResult::empty();
            return $ctx;
        }

        // Skip Cypher for knowledge queries ONLY when documents were actually
        // retrieved — those documents are the grounding. With no document context
        // we must NOT claim "answered from documents" at confidence 1.0; fall
        // through to the normal query path so an empty result becomes an explicit
        // no-source abstention rather than a confident, ungrounded answer.
        if ($ctx->qu->questionType === 'knowledge_query' && $hasFileContext) {
            $ctx->queryResult = new QueryGenerationResult(
                explanation: 'Knowledge query — answered from documents.',
                confidence: 1.0,
                metadata: ['source' => 'file_context', 'question_type' => 'knowledge_query'],
            );
            $ctx->executionResult = QueryExecutionResult::empty();
            return $ctx;
        }

        $queryResult = $this->generateQuery($question, $ctx, $options);

        // Thread pipeline identity/provenance into execution so the executor can
        // (a) team-filter against the real user ($options['user']) rather than
        // auth()->user(), and (b) log the real question instead of 'N/A'.
        $execOptions = $options + [
            'original_question' => $question,
            'conversation_id' => $options['conversation_id'] ?? null,
        ];

        $executionResult = $this->executeQuery($queryResult['cypher'], [], $execOptions);
        $executionResult = $this->enrichResultsWithEntityActions($executionResult);
        $executionResult = $this->filterSensitiveResults($executionResult, $options['user'] ?? null);

        $ctx->queryResult = new QueryGenerationResult(
            cypher: $queryResult['cypher'] ?? null,
            explanation: $queryResult['explanation'] ?? '',
            confidence: (float) ($queryResult['confidence'] ?? 0.0),
            warnings: $queryResult['warnings'] ?? [],
            metadata: $queryResult['metadata'] ?? [],
        );
        $ctx->executionResult = new QueryExecutionResult(
            success: !empty($executionResult['data']) || empty($executionResult['errors']),
            data: $executionResult['data'] ?? [],
            stats: $executionResult['stats'] ?? [],
            metadata: $executionResult['metadata'] ?? [],
            errors: $executionResult['errors'] ?? [],
        );

        return $ctx;
    }

    /**
     * Build the final response array from the pipeline context.
     */
    private function buildFinalResponse(string $question, PipelineContext $ctx, array $options): array
    {
        $hasFileContext = !empty($ctx->fileContext['relevant_files'] ?? []);
        $hasData = !empty($ctx->executionResult->data);

        // An empty result has two very different causes that must NOT be conflated:
        //   1. The query ran and genuinely returned zero rows  -> abstain ("no results").
        //   2. The pipeline could not run at all — the LLM/query layer was unavailable
        //      (circuit breaker OPEN, generation exhausted its retries) or execution
        //      errored. Reporting THAT as "no results were found for your question"
        //      falsely tells the user their data does not exist when the service is
        //      simply down. It must surface as an honest, transient error instead.
        $generationFailed = ($ctx->queryResult->metadata['error'] ?? false) === true;
        $executionFailed = !empty($ctx->executionResult->errors);

        if (($generationFailed || $executionFailed) && !$hasData && !$hasFileContext) {
            $responseResult = $this->responseGenerator->generateErrorResponse(
                $question,
                $this->infrastructureError($ctx),
                $options
            );
        } elseif (!$hasData && !$hasFileContext) {
            // Abstain explicitly when there is nothing to ground an answer on: no rows
            // from the query AND no document context. This is the single no-source path
            // (rather than letting the LLM narrate over an empty result and risk
            // fabricating). generateResponse() is reserved for grounded answers.
            $responseResult = $this->responseGenerator->generateEmptyResponse(
                $question,
                $ctx->queryResult->cypher,
                $options
            );
        } else {
            $responseResult = $this->generateResponse($ctx, $options);
        }

        if (!empty($ctx->fileContext)) {
            $responseResult = $this->enrichResponseWithFiles($responseResult, $ctx->fileContext, $options);
        }

        $answer = $responseResult['answer'];
        $securityFilterApplied = $ctx->executionResult->metadata['security_filter_applied'] ?? false;
        $scopeClarified = false;

        if ($securityFilterApplied && config('ai.security.clarify_scope', true)) {
            $prefix = config('ai.security.scope_prefix', 'Based on your permissions, ');
            if (!str_starts_with($answer, $prefix)) {
                $answer = $prefix . lcfirst($answer);
                $scopeClarified = true;
            }
        }

        return [
            'question' => $question,
            'answer' => $answer,
            'insights' => $responseResult['insights'],
            'visualizations' => $responseResult['visualizations'],
            'cypher' => $ctx->queryResult->cypher,
            'data' => $ctx->executionResult->data,
            'stats' => $ctx->executionResult->stats,
            'referenced_files' => $responseResult['referenced_files'] ?? [],
            'metadata' => [
                'query' => $ctx->queryResult->metadata,
                'execution' => $ctx->executionResult->metadata,
                'response' => $responseResult['metadata'],
                'scope_clarified' => $scopeClarified,
                'security_filter_applied' => $securityFilterApplied,
            ],
        ];
    }

    /**
     * Build a Throwable describing the infrastructure failure recorded in the pipeline
     * context, so generateErrorResponse surfaces an honest, transient error. Prefers the
     * recorded generation error message, then the first execution error, then a generic
     * "temporarily unavailable". The message only drives error-type guidance selection —
     * it is never shown verbatim to the user.
     */
    private function infrastructureError(PipelineContext $ctx): \Throwable
    {
        $message = $ctx->queryResult->metadata['error_message'] ?? null;

        if (!is_string($message) || $message === '') {
            $firstError = $ctx->executionResult->errors[0] ?? null;
            $message = is_array($firstError) ? ($firstError['message'] ?? null) : (is_string($firstError) ? $firstError : null);
        }

        return new \RuntimeException(
            is_string($message) && $message !== '' ? $message : 'The assistant service is temporarily unavailable.'
        );
    }

    // =========================================================================
    // Query Parameterization (security: prevent user ID leakage in stored queries)
    // =========================================================================

    /**
     * Build the parameterizer bound to the pipeline actor for this request.
     *
     * When a user is threaded through $options the stored/served examples are
     * keyed to that real identity; otherwise the default injected parameterizer
     * (ambient auth() identity) is used.
     */
    private function parameterizerFor(array $options): QueryParameterizer
    {
        if (array_key_exists('user', $options)) {
            return QueryParameterizer::forUser($options['user']);
        }

        return $this->queryParameterizer;
    }

    /**
     * Replace placeholders with actual current user values when serving similar queries.
     *
     * Kept as a static entry point for SimilarQueriesSection, which renders stored
     * example queries with the ambient current actor's values.
     */
    public static function deparameterizeCypher(string $cypher): string
    {
        return (new QueryParameterizer())->deparameterize($cypher);
    }

    // =========================================================================
    // File Context Methods
    // =========================================================================

    /**
     * Retrieve file context for the question.
     *
     * @param string $question The question to retrieve file context for
     * @param mixed $user The user to check access for
     * @param array $options Retrieval overrides forwarded to the provider (e.g. min_score, limit)
     * @return array File context array or empty if unavailable
     */
    public function retrieveFileContext(string $question, mixed $user, array $options = []): array
    {
        if ($this->fileContextProvider === null) {
            return [];
        }

        try {
            return $this->fileContextProvider->getFileContext($question, $user, $options);
        } catch (\Throwable $e) {
            \Log::warning('Failed to retrieve file context: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Whether the question is a file-listing intent ("which files can I access?").
     *
     * Model-agnostic: read from the QU result's intent, matched against a
     * config-driven set of enumeration intents (default ['file_listing']) — never
     * a hardcoded English phrase. Gated by file_context.enumeration.enabled so the
     * capability is opt-out per host.
     */
    private function isFileListingIntent(PipelineContext $ctx): bool
    {
        if (!config('ai.file_context.enumeration.enabled', true)) {
            return false;
        }

        $intent = strtolower(trim((string) ($ctx->qu->intent ?? '')));
        if ($intent === '') {
            return false;
        }

        $enumerationIntents = config('ai.file_context.enumeration.intents', ['file_listing']);
        if (!is_array($enumerationIntents)) {
            return false;
        }

        $normalized = array_map(
            fn ($i) => strtolower(trim((string) $i)),
            array_filter($enumerationIntents, 'is_string')
        );

        return in_array($intent, $normalized, true);
    }

    /**
     * Retrieve the ACL-respecting file-enumeration context for the user.
     *
     * @param mixed $user The user to enumerate accessible files for
     * @return array File enumeration context, or empty on failure/no provider
     */
    private function retrieveFileEnumerationContext(mixed $user): array
    {
        if ($this->fileContextProvider === null) {
            return [];
        }

        try {
            return $this->fileContextProvider->getFileEnumerationContext($user);
        } catch (\Throwable $e) {
            \Log::warning('Failed to enumerate accessible files: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Enrich response with file references.
     *
     * @param array $response The response array to enrich
     * @param array $fileContext The file context containing relevant files
     * @param array $options Additional options for enrichment
     * @return array The enriched response array
     */
    protected function enrichResponseWithFiles(array $response, array $fileContext, array $options): array
    {
        if ($this->responseFileEnricher === null) {
            return $response;
        }

        return $this->responseFileEnricher->enrichResponse($response, $fileContext, $options);
    }

    /**
     * Enrich query results with entity action metadata
     *
     * Adds 'has_actions', 'available_actions', and 'entity_type' to each result
     * that has entity actions configured.
     *
     * @param array $executionResult The query execution result (contains 'data' key)
     * @return array The execution result with enriched data
     */
    protected function enrichResultsWithEntityActions(array $executionResult): array
    {
        if ($this->responseEntityEnricher === null) {
            return $executionResult;
        }

        if (empty($executionResult['data'])) {
            return $executionResult;
        }

        $executionResult['data'] = $this->responseEntityEnricher->enrichEntityResults(
            $executionResult['data']
        );

        return $executionResult;
    }

    // =========================================================================
    // Security Methods
    // =========================================================================

    /**
     * Filter sensitive data from query results based on user access level
     *
     * Note: Security filtering is now handled at the Eloquent level via HasSecurity.
     * This method is kept as a placeholder for future AiAccessGate integration
     * that will provide AI-specific permission checks.
     *
     * @param array $executionResult Query execution result
     * @param mixed $user User for access checking
     * @return array Filtered execution result
     */
    protected function filterSensitiveResults(array $executionResult, mixed $user): array
    {
        // Security is now enforced at the Eloquent fetch level via HasSecurity
        // This method is a no-op pending AiAccessGate implementation
        return $executionResult;
    }
}
