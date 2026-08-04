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
use Condoedge\Ai\Services\Digest\DigestRunner;
use Condoedge\Ai\Services\Response\DigestResponseComposer;
use Condoedge\Ai\Services\Response\ExportResponseComposer;
use Condoedge\Ai\Services\Response\ResponseFileEnricher;
use Condoedge\Ai\Services\Response\ResponseEntityEnricher;

/**
 * AI Manager - thin orchestrator over the AI pipeline services.
 *
 * Owns the single canonical chat entry point (answerQuestion) plus the
 * ingest/context/response/file/embedding/llm delegations, fanning each stage out
 * to a dedicated collaborator (context retrieval, query gen/exec, response
 * generation, file/entity enrichment, export composition). All dependencies are
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

    private readonly ExportResponseComposer $exportComposer;

    private readonly DigestRunner $digestRunner;

    private readonly DigestResponseComposer $digestComposer;

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
     * @param ExportResponseComposer|null $exportComposer Export/document response composition
     * @param DigestRunner|null $digestRunner Deterministic attention-digest source walk
     * @param DigestResponseComposer|null $digestComposer Digest final-response composition (one LLM call)
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
        ?QueryParameterizer $queryParameterizer = null,
        ?ExportResponseComposer $exportComposer = null,
        ?DigestRunner $digestRunner = null,
        ?DigestResponseComposer $digestComposer = null
    ) {
        $this->queryParameterizer = $queryParameterizer ?? new QueryParameterizer();
        $this->exportComposer = $exportComposer ?? new ExportResponseComposer();
        // Defaults reuse the already-injected executor/LLM, so the digest's
        // security invariant (only path to the graph = the pipeline executor)
        // holds by construction even without container wiring.
        $this->digestRunner = $digestRunner ?? new DigestRunner($queryExecutor);
        $this->digestComposer = $digestComposer ?? new DigestResponseComposer($llm);
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
     * Get graph database schema
     *
     * @return array Schema with keys: labels, relationships, properties
     */
    public function getSchema(): array
    {
        return $this->context->getGraphSchema();
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

    /**
     * Clear ALL stored question-query few-shot examples from the vector store.
     *
     * Targeted invalidation API for the collection {@see storeQuery()} writes to:
     * stored examples anchor future generations (SIMILAR QUERIES few-shot block),
     * so examples captured from old/bad interactions can keep reproducing a bad
     * query verbatim. Used by the eval harness's --fresh (FreshEvalStateCleaner)
     * and available to hosts that want to purge a poisoned example store.
     *
     * The collection itself is kept (deleteAll, not deleteCollection) so the next
     * storeQuery() write needs no re-creation and concurrent readers never see a
     * missing collection.
     *
     * @param string $collection Collection name (default: 'questions' — the same
     *                           default storeQuery() writes to)
     * @return array Result with keys: success, deleted (points removed), errors
     */
    public function clearStoredQueries(string $collection = 'questions'): array
    {
        try {
            if (!$this->vectorStore->collectionExists($collection)) {
                return ['success' => true, 'deleted' => 0, 'errors' => []];
            }

            $count = $this->vectorStore->count($collection);
            $success = $this->vectorStore->deleteAll($collection);

            return [
                'success' => $success,
                'deleted' => $success ? $count : 0,
                'errors' => $success ? [] : ["Vector store refused to clear collection '{$collection}'."],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'deleted' => 0, 'errors' => [$e->getMessage()]];
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
        $generation = $this->generateQueryResult($question, $context, $options);

        return [
            'cypher' => $generation->cypher,
            'explanation' => $generation->explanation,
            'confidence' => $generation->confidence,
            'warnings' => $generation->warnings,
            'metadata' => $generation->metadata,
        ];
    }

    /**
     * Generate a query and return the typed DTO produced by the generator.
     *
     * The pipeline consumes this directly so no result DTO is rebuilt from
     * string keys; the public {@see generateQuery()} wraps it for the facade's
     * array contract. Storing the parameterized query as a reusable example is
     * the only side effect.
     */
    private function generateQueryResult(string $question, ?PipelineContext $context = null, array $options = []): QueryGenerationResult
    {
        try {
            if ($context === null) {
                $context = $this->context->retrieveContext($question, $options);
            }

            $generation = $this->queryGenerator->generate($question, $context, $options);

            // store_examples=false (eval harness) suppresses the few-shot side effect:
            // eval runs must not write synthetic question->cypher examples into the
            // shared vector store (they would leak into production RAG retrieval and
            // make successive eval runs depend on each other). Default true = the
            // unchanged production behavior.
            // Few-shot hygiene: context-dependent turns ("convert it to an excel",
            // "and as a PDF?") are NEVER stored — their question text is meaningless
            // outside this conversation and the paired cypher reflects the previous
            // turn's referent, so storing them poisons future generations that
            // retrieve them as "similar" examples.
            $contextDependent = $context->qu->followUpReference ?? false;

            if ($generation->hasCypher() && !$contextDependent && ($options['store_examples'] ?? true) !== false) {
                // Parameterize user-specific values before storing as reusable examples.
                // This prevents user A's query from leaking data to user B. Identity is
                // threaded from $options['user'] so CLI/queue/eval/impersonation key the
                // stored example to the real pipeline actor, not the ambient session.
                $storedCypher = $this->parameterizerFor($options)->parameterize($generation->cypher);

                $this->storeQuery(
                    question: $question,
                    cypherQuery: $storedCypher,
                    metadata: [
                        'confidence' => $generation->confidence,
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

            $metadata = ['error' => true, 'error_message' => $e->getMessage()];
            // Surface the last attempt's verbatim prompts (capture mode only) so the
            // eval trace is not empty exactly for the failed generations a grader
            // most needs to inspect.
            if ($e instanceof \Condoedge\Ai\Exceptions\CarriesPrompts && $e->getPrompts() !== null) {
                $metadata['prompts'] = $e->getPrompts();
            }

            return new QueryGenerationResult(
                cypher: null,
                explanation: '',
                confidence: 0.0,
                warnings: [$e->getMessage()],
                metadata: $metadata,
            );
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
        // Same companion-count honesty as the answerQuestion pipeline: a result
        // that hit the executor cap gets the TRUE total stamped (the eval driver
        // executes through this seam, so its response prompts see the same
        // true_total production sees).
        $execution = $this->executeWithTrueTotal($cypherQuery, $parameters, $options);

        return [
            'success' => $execution->success,
            'data' => $execution->data,
            'stats' => $execution->stats,
            'metadata' => $execution->metadata,
            'errors' => $execution->errors,
        ];
    }

    /**
     * Execute a query and, when it returns EXACTLY the executor cap (the silent
     * LIMIT), establish the population's TRUE total via a companion
     * QueryExecutor::executeCount with the SAME scoped options — stamped into the
     * result's stats+metadata as true_total. On count failure the result is
     * returned unchanged, keeping the existing "more may exist" hedging behavior.
     */
    private function executeWithTrueTotal(?string $cypherQuery, array $parameters, array $options): QueryExecutionResult
    {
        $execution = $this->queryExecutor->execute($cypherQuery, $parameters, $options);

        $total = $this->companionTrueTotal($cypherQuery, $parameters, $options, count($execution->data));
        if ($total === null) {
            return $execution;
        }

        return new QueryExecutionResult(
            success: $execution->success,
            data: $execution->data,
            stats: $execution->stats + ['true_total' => $total],
            metadata: $execution->metadata + ['true_total' => $total],
            errors: $execution->errors,
        );
    }

    /**
     * The TRUE total of a capped result, or null when it is not knowable.
     *
     * Fires ONLY when the row count equals the executor cap ($options['limit'] or
     * ai.query_execution.default_limit) — below the cap the retrieved count IS the
     * total, and a companion count would be a wasted round-trip. The count runs
     * through QueryExecutor::executeCount with the SAME options, so team scoping
     * and provenance apply identically; only a TRAILING "LIMIT n" is stripped from
     * the query text (the executor appends/relies on it for the data fetch — the
     * count must see the uncapped population). A count failure, or a count below
     * the rows actually retrieved (inconsistent, untrustworthy), returns null and
     * the caller keeps today's "more may exist" behavior.
     */
    private function companionTrueTotal(?string $cypherQuery, array $parameters, array $options, int $rowsOut): ?int
    {
        if ($rowsOut <= 0) {
            return null;
        }

        $cap = (int) ($options['limit'] ?? config('ai.query_execution.default_limit', 100));
        if ($cap <= 0 || $rowsOut !== $cap) {
            return null;
        }

        $inner = trim((string) $cypherQuery);
        if ($inner === '') {
            return null;
        }

        $inner = preg_replace('/\bLIMIT\s+\d+\s*;?\s*$/i', '', $inner) ?? $inner;
        if (trim($inner) === '') {
            return null;
        }

        try {
            $total = $this->queryExecutor->executeCount($inner, $parameters, $options);
        } catch (\Throwable $e) {
            \Log::warning('Companion count for capped result failed — keeping "more may exist" behavior: ' . $e->getMessage());
            return null;
        }

        return $total >= $rowsOut ? $total : null;
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

            // "Explain this page" — read-only page narration. When the user asks what the
            // page is / what they can do AND the host configured a description for it, answer
            // from that description (grounded on the page + route) WITHOUT running a graph
            // query, so it never reaches the empty-result abstention path. Returns null when
            // the feature is unconfigured or the intent does not match — the turn is then a
            // normal, unchanged query.
            $pageNarration = $this->pageNarrationResponse($ctx, $question, $options);
            if ($pageNarration !== null) {
                return $pageNarration;
            }

            // "What needs my attention?" — the deterministic multi-source digest
            // (design 2026-07-23). Owns the turn ONLY when QU judged it a broad
            // digest ask (never a follow-up) AND the host configured attention
            // sources. Returning here skips file search AND query generation by
            // construction — the config-declared source walk replaces both.
            $digest = $this->digestResponse($ctx, $question, $options);
            if ($digest !== null) {
                return $digest;
            }

            // GENUINE AMBIGUITY (item C): QU judged the question a real fork
            // (needs_clarification + a usable clarifying question, produced in
            // the SAME QU call). Skip file search and query generation and ask
            // the ONE short clarifying question instead of force-resolving a
            // guessed reading. Guarded: never on follow-up/digest turns,
            // config-gated, and a missing question falls back to the normal
            // pipeline. Zero additional LLM calls.
            $clarify = $this->clarifyResponse($ctx, $question, $options);
            if ($clarify !== null) {
                return $clarify;
            }

            $ctx = $this->retrieveAndSetFileContext($ctx, $question, $options);

            $ctx = $this->generateAndExecute($ctx, $question, $options);

            return $this->buildFinalResponse($question, $ctx, $options);

        } catch (\Throwable $e) {
            \Log::error('Error answering question: ' . $e->getMessage());

            // LANGUAGE POLICY (live failure 2026-07-23: French canned error to an
            // English question escaped through THIS catch): when the pipeline got
            // far enough for QU to detect the question language, thread it — the
            // canned error must follow the question's language, not the app locale.
            $errorOptions = $options;
            if (isset($ctx) && $ctx->qu->language !== '') {
                $errorOptions['question_language'] = $ctx->qu->language;
            }
            $errorResponse = $this->responseGenerator->generateErrorResponse($question, $e, $errorOptions);

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
        $isFileListing = $this->isFileListingIntent($ctx);
        if ($isFileListing) {
            $enumeration = $this->retrieveFileEnumerationContext($user);
            if (!empty($enumeration['relevant_files'] ?? [])) {
                $ctx->fileContext = $enumeration;
                return $ctx;
            }
            // No enumerable files (or enumeration disabled/unavailable): fall through
            // to normal retrieval rather than asserting an empty list.
        }

        // Latency/noise guard: a pure data query gains nothing from document
        // retrieval — skip the entire fan-out (and, structurally, the recall
        // floor below, which can therefore never admit borderline chunks into a
        // skipped turn). Driven by the EXISTING QU question_type signal matched
        // against a config-driven set — never phrase matching. The explicit
        // file-listing intent above takes precedence — INCLUDING its degraded
        // path: when enumeration yielded nothing, the promised fall-through to
        // semantic retrieval must not be blocked because QU also stamped the
        // listing turn with a skippable question_type (e.g. data_query).
        if (!$isFileListing && $this->shouldSkipFileSearchFor($ctx)) {
            return $ctx;
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

        // Lay->domain-register angles: when the host configured a corpus register
        // (ai.file_context.corpus_register), the QU layer emits 1-3 reformulations
        // of the question in the documents' own terminology (produced in the SAME
        // QU LLM call). Add each as a SEPARATE fan-out angle so casual wording
        // ("tear down a wall") still lands on the operative domain passages
        // ("alteration of a private portion"). Empty when no register is
        // configured, so the fan-out is unchanged by default.
        foreach ($ctx->qu->registerExpansions as $expansion) {
            if (is_string($expansion) && trim($expansion) !== '') {
                $queries[] = $expansion;
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

        // Per file: a LIST of references — the primary merged reference first,
        // then up to (max_chunks_per_file - 1) additional DISTINCT chunks of the
        // same file that independently cleared the confident relevance threshold
        // on some angle. Multiple passages of the right document ground deepening
        // questions far better than a single window.
        $maxChunksPerFile = max(1, (int) config('ai.file_context.max_chunks_per_file', 3));

        $allFiles = [];
        foreach ($perFileResults as $fileId => $results) {
            $allFiles[$fileId] = $this->mergeFileAngleResults($results, $maxChunksPerFile);
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
                    // Recall-floor rescues stay single-reference per file: extra
                    // chunks require the CONFIDENT threshold, which borderline
                    // recall hits by definition do not clear.
                    $allFiles[$fileId] = $this->mergeFileAngleResults($results);
                }
                // Keep only the strongest few borderline docs so a recall rescue never
                // floods the prompt with weak, off-topic sources. Each value is a list
                // of references whose primary (strongest) reference leads.
                if (count($allFiles) > $cap) {
                    uasort($allFiles, fn($a, $b) => ($b[0]['priority_score'] ?? $b[0]['relevance'] ?? 0) <=> ($a[0]['priority_score'] ?? $a[0]['relevance'] ?? 0));
                    $allFiles = array_slice($allFiles, 0, $cap, true);
                }
            }
        }

        if (!empty($allFiles)) {
            // Flatten the per-file reference lists, rank across files AND chunks,
            // and bound the final list by max_references overall so multi-chunk
            // grounding never inflates the prompt beyond the configured budget.
            $files = array_merge(...array_values($allFiles));
            usort($files, fn($a, $b) => ($b['priority_score'] ?? $b['relevance'] ?? 0) <=> ($a['priority_score'] ?? $a['relevance'] ?? 0));
            $maxReferences = max(1, (int) config('ai.file_context.max_references', 5));
            $files = array_slice($files, 0, $maxReferences);

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
     * Whether file search should be skipped entirely for this turn.
     *
     * Config-driven (ai.file_context.skip_for_question_types, default
     * ['data_query']) and keyed on the EXISTING QU question_type signal — never
     * phrase matching. Empty/non-array config disables the skip so hosts can
     * always force file search back on. Called BEFORE the fan-out and the
     * recall floor, so a skipped turn can never receive chunks at all.
     */
    private function shouldSkipFileSearchFor(PipelineContext $ctx): bool
    {
        $skipTypes = config('ai.file_context.skip_for_question_types', ['data_query']);
        if (!is_array($skipTypes) || $skipTypes === []) {
            return false;
        }

        $questionType = strtolower(trim((string) ($ctx->qu->questionType ?? '')));
        if ($questionType === '') {
            return false;
        }

        $normalized = array_map(
            fn ($t) => strtolower(trim((string) $t)),
            array_filter($skipTypes, 'is_string')
        );

        return in_array($questionType, $normalized, true);
    }

    /**
     * Merge the per-angle results for ONE file into a ranked reference list.
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
     *   - PRIMARY reference (always first): rank the file by its STRONGEST angle
     *     (max relevance), so a genuinely relevant document is not demoted; choose
     *     its SNIPPET from the most informative angle — among the angles whose
     *     relevance is within a small margin of that max, prefer the one whose
     *     best chunk sits DEEPEST in the document (largest chunk_index).
     *     Cover/preamble/table-of-contents chunks are by construction the
     *     lowest-index chunks, so a deeper best chunk of comparable relevance is
     *     the substantive content the question is actually about.
     *   - ADDITIONAL chunks (when $maxChunks > 1): other DISTINCT chunks of the
     *     same file, each of which independently cleared the CONFIDENT relevance
     *     threshold on some angle, ordered by their own relevance. One chunk per
     *     turn structurally starves deepening questions; late-document passages
     *     of the right file are exactly what "tell me more" needs.
     *     Sub-threshold chunks are never promoted this way (noise guard), chunks
     *     whose window-expanded snippet would overlap an already-selected
     *     reference's snippet (within ±context_window chunk indices) are treated
     *     as duplicates rather than distinct passages, and the caller still
     *     bounds the final list by max_references overall.
     *
     * @param array<int,array> $results   The same file's reference from each angle.
     * @param int              $maxChunks Max DISTINCT chunks of this file to emit (>=1).
     * @return array<int,array> Reference list: primary first, then extra chunks.
     */
    private function mergeFileAngleResults(array $results, int $maxChunks = 1): array
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
        $primary = $best ?? $results[0];

        // The file's ranking strength is its strongest angle, independent of which
        // angle's snippet was chosen.
        $primary['relevance'] = $maxRelevance;

        $merged = [$primary];
        if ($maxChunks <= 1) {
            return $merged;
        }

        // Deduplicate the remaining angles by snippet WINDOW, not exact chunk_index:
        // every reference's snippet is window-expanded ±context_window chunks around
        // its chunk (FileContextProvider), so a chunk within window distance of an
        // already-selected reference ships text that reference's snippet already
        // contains — a near-duplicate that burns a max_references slot and prompt
        // tokens. Structural rule: an extra chunk must sit OUTSIDE the primary's
        // and every other selected chunk's window. Admit only chunks that cleared
        // the confident threshold on their own angle, keeping each window's
        // strongest showing.
        $confident = (float) config('ai.file_context.min_relevance_score', 0.4);
        $window = max(0, (int) config('ai.file_context.context_window', 2));
        $primaryIndex = (int) ($primary['chunk_index'] ?? 0);

        $byChunk = [];
        foreach ($results as $r) {
            $chunkIndex = (int) ($r['chunk_index'] ?? 0);
            if (abs($chunkIndex - $primaryIndex) <= $window) {
                continue; // already covered by the primary's window-expanded snippet
            }
            if ((float) ($r['relevance'] ?? 0) < $confident) {
                continue;
            }
            if (!isset($byChunk[$chunkIndex])
                || (float) ($r['relevance'] ?? 0) > (float) ($byChunk[$chunkIndex]['relevance'] ?? 0)) {
                $byChunk[$chunkIndex] = $r;
            }
        }

        $byChunk = array_values($byChunk);
        usort($byChunk, fn($a, $b) => ($b['relevance'] ?? 0) <=> ($a['relevance'] ?? 0));

        $selectedIndexes = [$primaryIndex];
        foreach ($byChunk as $extra) {
            if (count($merged) >= $maxChunks) {
                break;
            }
            $extraIndex = (int) ($extra['chunk_index'] ?? 0);
            foreach ($selectedIndexes as $selectedIndex) {
                if (abs($extraIndex - $selectedIndex) <= $window) {
                    continue 2; // overlaps an already-selected snippet window
                }
            }
            $merged[] = $extra;
            $selectedIndexes[] = $extraIndex;
        }

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
     * Answer a page-explanation turn as read-only narration, or null to keep the normal path.
     *
     * Fires only when (a) the QU intent is one of the configured page-explanation intents
     * AND (b) the host configured a description for the current page context. The narration
     * is produced by the grounded response path (generateResponse) over the host description
     * — never a Cypher query, never the empty-result abstention — so an "Explain this page"
     * question can never dead-end in "no results". Returns null (feature off / no match) so
     * every ordinary turn runs unchanged.
     *
     * @return array<string,mixed>|null
     */
    private function pageNarrationResponse(PipelineContext $ctx, string $question, array $options): ?array
    {
        if (!$this->isPageExplanationIntent($ctx)) {
            return null;
        }

        $description = $this->pageDescriptionFor($options['page_context'] ?? []);
        if ($description === null) {
            return null;
        }

        $ctx->pageDescription = $description;

        $responseResult = $this->generateResponse($ctx, $options);

        return [
            'question' => $question,
            'answer' => $responseResult['answer'],
            'insights' => $responseResult['insights'],
            'visualizations' => $responseResult['visualizations'],
            'cypher' => null,
            'data' => [],
            'stats' => [],
            'referenced_files' => $responseResult['referenced_files'] ?? [],
            'metadata' => [
                'query' => ['source' => 'page_description', 'intent' => 'page_explanation'],
                'execution' => [],
                'response' => ($responseResult['metadata'] ?? []) + ['intent' => 'page_explanation'],
                'scope_clarified' => false,
                'security_filter_applied' => false,
            ],
        ];
    }

    /**
     * Whether the QU intent classifies this turn as a page-explanation request.
     *
     * Model-agnostic: read from the QU result's intent, matched against a config-driven
     * set (default ['page_explanation']) — never a hardcoded phrase — mirroring the
     * file-listing intent gate.
     */
    private function isPageExplanationIntent(PipelineContext $ctx): bool
    {
        $intent = strtolower(trim((string) ($ctx->qu->intent ?? '')));
        if ($intent === '') {
            return false;
        }

        $intents = config('ai.chat.page_explanation_intents', ['page_explanation']);
        if (!is_array($intents)) {
            return false;
        }

        $normalized = array_map(
            fn ($i) => strtolower(trim((string) $i)),
            array_filter($intents, 'is_string')
        );

        return in_array($intent, $normalized, true);
    }

    /**
     * Resolve the host-provided description for the current page, or null when none.
     *
     * Looks up config('ai.chat.page_descriptions') by the page context's description key
     * (entity_type, falling back to the route). Empty config = feature off (null), so the
     * default behavior is unchanged.
     *
     * @param array<string,mixed> $pageContext
     */
    private function pageDescriptionFor(array $pageContext): ?string
    {
        if (empty($pageContext)) {
            return null;
        }

        $descriptions = config('ai.chat.page_descriptions', []);
        if (!is_array($descriptions) || $descriptions === []) {
            return null;
        }

        $key = \Condoedge\Ai\DTOs\PageContext::fromArray($pageContext)->descriptionKey();
        if ($key === null) {
            return null;
        }

        $description = $descriptions[$key] ?? null;

        return is_string($description) && trim($description) !== '' ? trim($description) : null;
    }

    /**
     * Answer a QU-judged digest turn ("what needs my attention?") with the
     * deterministic multi-source walk, or null to keep the normal path.
     *
     * Fires only when (a) QU judged the turn a BROAD digest ask, (b) it is NOT
     * a follow-up on a previous result, and (c) the host configured at least
     * one enabled ai.attention_sources entry. Every source executes through
     * QueryExecutorInterface::execute with the SAME options as a normal turn
     * (tenant scoping / read-only / rate limit identical — the security
     * invariant); the runner additionally strips any skip_security key. LLM
     * budget: the composer makes exactly ONE call (QU + composition = 2 per
     * digest turn).
     *
     * Combined digest+export ask (amendment B1): the export format is threaded
     * to the composer, which acknowledges the ask as the turn's single
     * deepening offer — the data write-back below makes the follow-up
     * "export that" work on the digest rows.
     *
     * @return array<string, mixed>|null
     */
    private function digestResponse(PipelineContext $ctx, string $question, array $options): ?array
    {
        if (!$ctx->qu->digestRequest || $ctx->qu->followUpReference) {
            return null;
        }

        $registry = $this->digestRunner->registry();
        if (!$registry->hasEnabled()) {
            return null;
        }

        // EXACTLY the option threading generateAndExecute uses: identity for
        // team filtering + provenance for execution logging.
        $execOptions = $options + [
            'original_question' => $question,
            'conversation_id' => $options['conversation_id'] ?? null,
        ];

        $sources = $this->digestRunner->run($execOptions);
        if ($sources === []) {
            return null;
        }

        // Entity-action enrichment through the SAME seam normal-turn rows use,
        // so per-row entity:// links come for free in composition.
        foreach ($sources as $i => $source) {
            if ($source->entity !== null && $source->rows !== []) {
                $enriched = $this->enrichResultsWithEntityActions(new QueryExecutionResult(
                    success: true,
                    data: $source->rows,
                    stats: [],
                    metadata: [],
                    errors: [],
                ));
                $sources[$i] = $source->withRows($enriched->data);
            }
        }

        return $this->digestComposer->compose(
            $question,
            $ctx,
            $sources,
            $registry->skippedSourceKeys(),
            $ctx->qu->exportFormat,
            $options
        );
    }

    /**
     * Answer a QU-judged genuinely-ambiguous turn with the ONE clarifying
     * question QU composed, or null to keep the normal path.
     *
     * Fires only when (a) ai.understanding.clarify_enabled (default true),
     * (b) QU flagged needs_clarification, (c) the turn is NEITHER a follow-up
     * reference NOR a digest ask (both re-guarded here — defence in depth
     * beyond the QU parse guard), and (d) QU produced a usable clarifying
     * question. The answer is QU's clarifying_question VERBATIM — composed in
     * the question's language inside the same single QU call, so this branch
     * makes ZERO LLM calls and skips file search + query generation entirely.
     * The candidate readings ride response metadata for graders/UI.
     *
     * @return array<string, mixed>|null
     */
    private function clarifyResponse(PipelineContext $ctx, string $question, array $options): ?array
    {
        if (!config('ai.understanding.clarify_enabled', true)) {
            return null;
        }

        if (!$ctx->qu->needsClarification || $ctx->qu->followUpReference || $ctx->qu->digestRequest) {
            return null;
        }

        $clarifyingQuestion = trim($ctx->qu->clarifyingQuestion);
        if ($clarifyingQuestion === '') {
            // Fail-safe: without a usable question we cannot clarify — run the
            // normal pipeline rather than dead-ending the turn.
            return null;
        }

        return [
            'question' => $question,
            'answer' => $clarifyingQuestion,
            'insights' => [],
            'visualizations' => [],
            'cypher' => null,
            'data' => [],
            'stats' => [],
            'referenced_files' => [],
            'metadata' => [
                'query' => ['source' => 'clarification'],
                'execution' => [],
                'response' => [
                    'clarification' => [
                        'candidate_readings' => $ctx->qu->candidateReadings,
                    ],
                ],
                'scope_clarified' => false,
                'security_filter_applied' => false,
                'subject' => $ctx->qu->searchQuery !== '' ? $ctx->qu->searchQuery : null,
            ],
        ];
    }

    /**
     * Public seam over the clarify branch for the eval harness.
     *
     * WHY: a clarify turn must be owned by the EXACT branch production runs
     * inside answerQuestion() (after the digest check, before file search and
     * query generation) — same seam pattern as composeDigestResponse. Returns
     * null when the turn does not clarify.
     *
     * @return array<string, mixed>|null
     */
    public function composeClarifyResponse(PipelineContext $ctx, string $question, array $options = []): ?array
    {
        return $this->clarifyResponse($ctx, $question, $options);
    }

    /**
     * Public seam over the digest branch for the eval harness.
     *
     * WHY: the eval driver walks the pipeline stage-by-stage, but a digest turn
     * must be owned by the EXACT branch production runs inside answerQuestion()
     * (before file search and query generation) — otherwise digest suites would
     * grade the normal single-query pipeline and response_metadata.digest would
     * never appear in the trace. Delegates to the same private method
     * answerQuestion() uses; no behavior fork.
     *
     * @return array<string, mixed>|null Null when the turn is not a digest.
     */
    public function composeDigestResponse(PipelineContext $ctx, string $question, array $options = []): ?array
    {
        return $this->digestResponse($ctx, $question, $options);
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
        //
        // AUGMENTATION ANCHOR EXCEPTION (item B — iteration-11 sentinel red): a
        // QU-flagged follow-up that OPERATES ON a recorded data result must
        // never take this skip. Observed live: "add their last login to it"
        // came back entity_types=[] / knowledge_query, the skip answered from
        // documents, and the turn ended cypher=null / 0 rows. When the
        // conversation snapshot records the anchor (last_cypher_query), query
        // generation runs and EXTENDS the prior query (the anchor reaches the
        // generator via ConversationContextSection). Export follow-ups keep
        // their previous-result export route (see hasAugmentationAnchor).
        if ($ctx->qu->questionType === 'knowledge_query' && $hasFileContext
            && !$this->hasAugmentationAnchor($ctx)) {
            $ctx->queryResult = new QueryGenerationResult(
                explanation: 'Knowledge query — answered from documents.',
                confidence: 1.0,
                metadata: ['source' => 'file_context', 'question_type' => 'knowledge_query'],
            );
            $ctx->executionResult = QueryExecutionResult::empty();
            return $ctx;
        }

        $queryResult = $this->generateQueryResult($question, $ctx, $options);

        // Thread pipeline identity/provenance into execution so the executor can
        // (a) team-filter against the real user ($options['user']) rather than
        // auth()->user(), and (b) log the real question instead of 'N/A'.
        $execOptions = $options + [
            'original_question' => $question,
            'conversation_id' => $options['conversation_id'] ?? null,
        ];

        // Companion-count honesty: a cap-hit execution gets the TRUE total stamped
        // (stats+metadata true_total) so the response prompt, the truncation guard
        // and export sourceMeta all disclose the real population size.
        $executionResult = $this->executeWithTrueTotal($queryResult->cypher, [], $execOptions);
        $executionResult = $this->enrichResultsWithEntityActions($executionResult);

        $ctx->queryResult = $queryResult;
        $ctx->executionResult = $executionResult;

        return $ctx;
    }

    /**
     * Whether this turn carries the AUGMENTATION ANCHOR: a QU-judged follow-up
     * on a previous DATA result whose query the conversation snapshot recorded.
     *
     * The deterministic carry mechanism (item B, iteration-11 verdict): for
     * these turns the previous turn's entity types (last_entity_types) are
     * re-seeded into semantic selection by ContextRetriever, the recorded
     * cypher (last_cypher_query) reaches the generator via
     * ConversationContextSection with an explicit EXTEND instruction, and the
     * knowledge-skip in generateAndExecute() is bypassed so generation actually
     * runs instead of dead-ending in a documents-only answer with cypher=null.
     *
     * Export follow-ups (exportFormat set) are EXCLUDED: the previous-result
     * export path in buildFinalResponse() already owns them by re-executing
     * the recorded cypher — forcing fresh generation there would only re-open
     * the anaphora-misresolution risk it was built to avoid.
     *
     * PUBLIC because the eval driver must take the exact same branch
     * production takes (same seam pattern as composeDigestResponse).
     */
    public function hasAugmentationAnchor(PipelineContext $ctx): bool
    {
        if (!$ctx->qu->followUpReference || $ctx->qu->exportFormat !== null) {
            return false;
        }

        $anchor = $ctx->conversationContext['last_cypher_query'] ?? null;

        return is_string($anchor) && trim($anchor) !== '';
    }

    /**
     * Public seam over the production file-context fan-out for the eval harness.
     *
     * WHY: the eval driver walks the pipeline stage-by-stage, but its file-search
     * stage must be the EXACT retrieval production runs inside answerQuestion() —
     * a re-implemented subset would grade the suite against a weaker retrieval
     * (no corpus-translation cross-lingual angles, no recall-floor safety net,
     * no file-listing enumeration path, no page-context angle, naive max-relevance
     * merge instead of snippet-depth selection). Delegates to the same private
     * method answerQuestion() uses; no behavior fork.
     */
    public function retrieveFileContextInto(PipelineContext $ctx, string $question, array $options = []): PipelineContext
    {
        return $this->retrieveAndSetFileContext($ctx, $question, $options);
    }

    /**
     * Public seam over entity-action enrichment for the eval harness.
     *
     * WHY: production generateAndExecute() enriches execution rows with
     * has_actions / available_actions / entity_type BEFORE response composition
     * and before the rows reach the conversation write-back. The eval driver must
     * apply the same enrichment so the response prompt and the persisted
     * last_result_sample match what production emits. Delegates to the same
     * protected method generateAndExecute() uses; no behavior fork.
     */
    public function enrichExecutionResult(QueryExecutionResult $executionResult): QueryExecutionResult
    {
        return $this->enrichResultsWithEntityActions($executionResult);
    }

    /**
     * Public seam over the final-response composition (export detection, previous-
     * result export follow-ups, abstention, infra-error surfacing, scope prefix,
     * file enrichment).
     *
     * WHY: the eval harness walks the pipeline stage-by-stage for per-stage
     * attribution, but its FINAL turn output must come from the exact same
     * composition production runs inside answerQuestion() — a bespoke eval copy
     * would silently diverge (e.g. never exercise the export path), and
     * conversation-state bugs like a missing last_result_sample would be papered
     * over instead of reproduced. Delegates to the same private method
     * answerQuestion() uses; no behavior fork.
     */
    public function composeResponse(string $question, PipelineContext $ctx, array $options = []): array
    {
        return $this->buildFinalResponse($question, $ctx, $options);
    }

    /**
     * Build the final response array from the pipeline context.
     */
    private function buildFinalResponse(string $question, PipelineContext $ctx, array $options): array
    {
        $hasFileContext = !empty($ctx->fileContext['relevant_files'] ?? []);
        $hasData = !empty($ctx->executionResult->data);
        $exportIntent = $this->resolveExportIntent($ctx, $question);

        // An empty result has two very different causes that must NOT be conflated:
        //   1. The query ran and genuinely returned zero rows  -> abstain ("no results").
        //   2. The pipeline could not run at all — the LLM/query layer was unavailable
        //      (circuit breaker OPEN, generation exhausted its retries) or execution
        //      errored. Reporting THAT as "no results were found for your question"
        //      falsely tells the user their data does not exist when the service is
        //      simply down. It must surface as an honest, transient error instead.
        $generationFailed = ($ctx->queryResult->metadata['error'] ?? false) === true;
        $executionFailed = !empty($ctx->executionResult->errors);

        // Whether THIS turn ran its own data query. Authoritative for export
        // sourcing: when the current question generated a query that returned
        // zero rows, the honest outcome is "no rows matched" — falling back to
        // the PREVIOUS turn's (unrelated) result would export wrong data under
        // the new question's title. Previous/identity fallbacks are reserved
        // for pure export-of-previous turns ("give me that as an Excel") where
        // query generation intentionally produced nothing — OR turns QU judged
        // to OPERATE ON the previous result (followUpReference: "excel of it"),
        // where a freshly generated query is anaphora-suspect: the generator may
        // have re-anchored on a stale conversation focus (observed live: "excel
        // of it" after a members list exported the TEAM row instead).
        $currentQueryRan = trim((string) $ctx->queryResult->cypher) !== '';
        $refersToPrevious = $ctx->qu->followUpReference;

        // Resolve the previous-result rows ONCE (re-executes the recorded cypher).
        // For QU-judged follow-up references this is attempted EVEN when this
        // turn's generation/execution failed: an export-of-previous does not need
        // the new query at all, so its failure must not block the export (live
        // failure 2026-07-23: a TPM-failed follow-up export answered with a canned
        // error although the recorded roster was intact). Non-follow-up exports
        // keep requiring a healthy pipeline — serving previous rows there risks
        // exporting unrelated data under a new title.
        $previous = null;
        if ($exportIntent !== null
            && ($refersToPrevious || (!$generationFailed && !$executionFailed && !$currentQueryRan))) {
            $candidate = $this->previousResultExportRows($question, $ctx, $options);
            $previous = !empty($candidate['rows']) ? $candidate : null;
        }

        if ($exportIntent !== null && $previous !== null
            && ($refersToPrevious || (!$generationFailed && !$executionFailed && !$hasData))) {
            // Export-of-previous: QU says the request refers back to what was
            // just shown (or no fresh data exists) — the recorded result is the
            // authoritative source, not this turn's (possibly re-anchored) query
            // output. Reachable even when this turn's generation/execution
            // failed — but ONLY under QU's follow-up judgment: the new query was
            // never needed, so its failure must not turn into a canned error.
            $responseResult = $this->exportComposer->generateExportDocumentResponse(
                $question,
                $exportIntent,
                $previous['rows'],
                'previous_result',
                $this->exportSourceMeta($ctx, $previous['meta'], 'previous_result')
            );
        } elseif ($exportIntent !== null && !$generationFailed && !$executionFailed
            && ($hasData || !$currentQueryRan)) {
            if ($hasData) {
                // Cold ask: export the FULL execution result set (bounded downstream by
                // ai.export.document_row_limit) — never the 10-row display sample.
                // Completeness honesty: when the companion count established the TRUE
                // total (true_total stamped by executeWithTrueTotal on a cap-hit
                // execution), total_rows carries the real number and complete is
                // computed honestly against it. Without it, completeness is only
                // claimable BELOW the executor's silent row cap: at the cap the true
                // total is unknown, so complete=false lets the composer disclose
                // "more may exist".
                $rowsOut = count($ctx->executionResult->data);
                $executorCap = (int) ($options['limit'] ?? config('ai.query_execution.default_limit', 100));
                $trueTotal = $ctx->executionResult->metadata['true_total'] ?? null;
                $meta = is_numeric($trueTotal) && (int) $trueTotal >= $rowsOut
                    ? ['complete' => (int) $trueTotal <= $rowsOut, 'total_rows' => (int) $trueTotal]
                    : ['complete' => $rowsOut < $executorCap, 'total_rows' => $rowsOut];
                $responseResult = $this->exportComposer->generateExportDocumentResponse(
                    $question,
                    $exportIntent,
                    $ctx->executionResult->data,
                    'current_result',
                    $this->exportSourceMeta($ctx, $meta, 'current_result')
                );
            } elseif ($identityRows = $this->exportComposer->identityExportRows($ctx, $options)) {
                $responseResult = $this->exportComposer->generateExportDocumentResponse(
                    $question,
                    $exportIntent,
                    $identityRows,
                    'current_identity',
                    $this->exportSourceMeta($ctx, ['complete' => true, 'total_rows' => count($identityRows)], 'current_identity')
                );
            } elseif (!$hasFileContext) {
                $responseResult = $this->exportComposer->generateExportClarificationResponse(
                    $question,
                    $exportIntent,
                    $ctx->qu->language !== '' ? $ctx->qu->language : null
                );
            } else {
                $responseResult = $this->generateResponse($ctx, $options);
            }
        } elseif (($generationFailed || $executionFailed) && !$hasData && !$hasFileContext) {
            // Language policy: an error answer must follow the QUESTION's language,
            // not the app locale — thread the QU-detected language into the
            // error-response options (no in-package language detection lists).
            $responseResult = $this->responseGenerator->generateErrorResponse(
                $question,
                $this->infrastructureError($ctx),
                $options + ($ctx->qu->language !== '' ? ['question_language' => $ctx->qu->language] : [])
            );
        } elseif (!$hasData && !$hasFileContext) {
            // Abstain explicitly when there is nothing to ground an answer on: no rows
            // from the query AND no document context. This is the single no-source path
            // (rather than letting the LLM narrate over an empty result and risk
            // fabricating). generateResponse() is reserved for grounded answers.
            $responseResult = $this->responseGenerator->generateEmptyResponse(
                $question,
                $ctx->queryResult->cypher,
                // Thread the conversation context through: "make a PDF of that" follow-ups
                // reference the PREVIOUS turn and would otherwise dead-end in "no results".
                // question_language: the abstention path renders canned strings (insights,
                // LLM-failure fallback) — they must follow the QUESTION's language, not
                // the app locale (same policy as generateErrorResponse).
                $options
                    + ['conversation_context' => $ctx->conversationContext]
                    + ($ctx->qu->language !== '' ? ['question_language' => $ctx->qu->language] : []),
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
                // QU's distilled subject of THIS turn ("team members details").
                // Persisted into the conversation snapshot (last_subject) so a
                // later subject-less follow-up ("Ok, give me the excel") can
                // title its export by what the data IS, not by echoing itself.
                'subject' => $ctx->qu->searchQuery !== '' ? $ctx->qu->searchQuery : null,
            ],
        ];
    }

    /**
     * Resolve the export intent for this turn — QU FIRST, deterministic regex as
     * FALLBACK only.
     *
     * The QU LLM call already judges, semantically, whether the user asked to
     * receive the information as a downloadable file and in which format
     * (QueryUnderstandingResult::$exportFormat). That judgment wins: phrase-list
     * matching can never keep up with real phrasings ("Can I get an excel...").
     * ExportResponseComposer::detectExportIntent remains as the deterministic
     * safety net for turns where QU produced nothing (e.g. its call failed).
     *
     * @return array{format: string}|null
     */
    private function resolveExportIntent(PipelineContext $ctx, string $question): ?array
    {
        if ($ctx->qu->exportFormat !== null) {
            return ['format' => $ctx->qu->exportFormat];
        }

        return $this->exportComposer->detectExportIntent($question);
    }

    /**
     * Rows for a follow-up export ("give me that as an Excel"): re-EXECUTE the
     * previous turn's recorded cypher through the standard QueryExecutorInterface
     * path so the export carries the FULL result set — for ordinary turns the
     * conversation snapshot only persists a 10-row display sample
     * (ConversationContextManager::recordResponse stores array_slice(data, 0, 10)),
     * which silently dropped rows from exports. Digest turns record no cypher but
     * persist their full display-capped row set as the sample, so the sample
     * fallback below IS complete for them.
     *
     * Tenant scoping is preserved BY CONSTRUCTION: the re-execution goes through
     * $this->queryExecutor->execute() with the SAME $options (including the real
     * $options['user']) and provenance keys as the normal pipeline execution in
     * generateAndExecute() — never a raw/unscoped execution path.
     *
     * Falls back to the recorded sample WITH honest sample metadata (complete
     * computed against the recorded total row count) when the cypher is absent
     * or re-execution fails, so the composer can disclose "showing X of N".
     *
     * @return array{rows: array<int, array<string, mixed>>, meta: array{complete: bool, total_rows: int}}
     */
    private function previousResultExportRows(string $question, PipelineContext $ctx, array $options): array
    {
        // PROVENANCE: last_cypher_query is EXECUTED below, so it is read ONLY from
        // the pipeline-stamped context ($ctx->conversationContext, which production
        // fills from the server-side AiConversation snapshot) — never from raw
        // $options, which a host may thread from client-influenced input. Display
        // sampling (previousResultSample) keeps its options fallback: those rows
        // are rendered, not executed.
        $conversation = is_array($ctx->conversationContext) ? $ctx->conversationContext : [];

        $sample = $this->exportComposer->previousResultSample($ctx, $options);
        $recordedCount = max((int) ($conversation['last_result_count'] ?? 0), count($sample));

        $cypher = $conversation['last_cypher_query'] ?? null;
        if (is_string($cypher) && trim($cypher) !== '') {
            try {
                // Same option threading as generateAndExecute(): identity for team
                // filtering + provenance for execution logging.
                $execOptions = $options + [
                    'original_question' => $question,
                    'conversation_id' => $options['conversation_id'] ?? null,
                ];

                $result = $this->queryExecutor->execute($cypher, [], $execOptions);

                if (empty($result->errors) && !empty($result->data)) {
                    // Same executor-cap honesty as the cold path, upgraded by the
                    // companion count: on a cap-hit re-execution the TRUE total is
                    // established via executeCount (same scoped options); when it
                    // succeeds, total_rows is the real number and complete is
                    // computed honestly. On failure the true total stays unknown —
                    // never claim complete.
                    $rowsOut = count($result->data);
                    $executorCap = (int) ($options['limit'] ?? config('ai.query_execution.default_limit', 100));
                    $trueTotal = $this->companionTrueTotal($cypher, [], $execOptions, $rowsOut);
                    if ($trueTotal !== null) {
                        return [
                            'rows' => $result->data,
                            'meta' => ['complete' => $trueTotal <= $rowsOut, 'total_rows' => $trueTotal],
                        ];
                    }

                    return [
                        'rows' => $result->data,
                        'meta' => ['complete' => $rowsOut < $executorCap, 'total_rows' => max($rowsOut, $recordedCount)],
                    ];
                }
            } catch (\Throwable $e) {
                \Log::warning('Previous-result export re-execution failed, falling back to sample: ' . $e->getMessage());
            }
        }

        // Sample-only export: completeness is COMPUTED, never assumed false. A
        // digest turn persists its full (already display-capped) row set as the
        // sample and records no cypher — that is what makes the B1 promise
        // ("the file with everything shown here") keepable on the follow-up
        // "export that". An ordinary 10-row display sample of a larger result
        // still reports complete=false and discloses "showing X of N".
        return [
            'rows' => $sample,
            'meta' => [
                'complete' => $recordedCount > 0 && count($sample) >= $recordedCount,
                'total_rows' => $recordedCount,
            ],
        ];
    }

    /**
     * Source metadata threaded into export composition: completeness/total for
     * honest "showing X of Y" disclosure, the focused entity so the document
     * title can carry its subject, and the QU-detected question language so the
     * copy follows the language policy (config-free, no phrase matching).
     *
     * @param array{complete?: bool, total_rows?: int} $meta
     * @return array<string, mixed>
     */
    private function exportSourceMeta(PipelineContext $ctx, array $meta, string $source = 'current_result'): array
    {
        $conversation = is_array($ctx->conversationContext) ? $ctx->conversationContext : [];

        // Entity for the document title: source-aware precedence. A PREVIOUS-result
        // export is labeled by the conversation's recorded focus (that is what the
        // rows ARE); a CURRENT-turn export is labeled by THIS turn's QU entity —
        // the conversation focus is one turn stale and would mislabel a cold export
        // ("Team — ...unpaid invoices") after an unrelated prior turn.
        if ($source === 'previous_result') {
            $entity = $conversation['focused_entity'] ?? null;
            if (!is_string($entity) || trim($entity) === '') {
                $entity = $ctx->qu->entityTypes[0] ?? null;
            }
        } else {
            $entity = $ctx->qu->entityTypes[0] ?? null;
            if (!is_string($entity) || trim($entity) === '') {
                $entity = $conversation['focused_entity'] ?? null;
            }
        }

        // Subject for the title/filename. A PREVIOUS-result export is titled by
        // the PREVIOUS turn's recorded subject (what the exported data IS) —
        // never this turn's wording ("Ok, give me the excel" must not become
        // User_Ok_give_me_the_excel.xlsx). Current-turn exports use this turn's
        // QU subject.
        if ($source === 'previous_result') {
            $subject = $conversation['last_subject'] ?? null;
        } else {
            $subject = $ctx->qu->searchQuery !== '' ? $ctx->qu->searchQuery : null;
        }

        return $meta + [
            'entity' => is_string($entity) && trim($entity) !== '' ? $entity : null,
            'language' => $ctx->qu->language !== '' ? $ctx->qu->language : null,
            'subject' => is_string($subject) && trim($subject) !== '' ? trim($subject) : null,
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
    protected function enrichResultsWithEntityActions(QueryExecutionResult $executionResult): QueryExecutionResult
    {
        if ($this->responseEntityEnricher === null) {
            return $executionResult;
        }

        if (empty($executionResult->data)) {
            return $executionResult;
        }

        return new QueryExecutionResult(
            success: $executionResult->success,
            data: $this->responseEntityEnricher->enrichEntityResults($executionResult->data),
            stats: $executionResult->stats,
            metadata: $executionResult->metadata,
            errors: $executionResult->errors,
        );
    }

    // =========================================================================
    // Security Methods
    // =========================================================================
}
