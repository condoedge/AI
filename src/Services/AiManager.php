<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\Actions\Attach\ActionAttacher;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Digest\DigestRunner;
use Condoedge\Ai\Services\Query\EmptyResultInspector;
use Condoedge\Ai\Services\Query\FanOutCollapse;
use Condoedge\Ai\Services\Query\PreviousResultExport;
use Condoedge\Ai\Services\Query\RecordParentEnricher;
use Condoedge\Ai\Services\Response\ConversationalReplyComposer;
use Condoedge\Ai\Services\Response\DigestResponseComposer;
use Condoedge\Ai\Services\Response\ExportResponseComposer;
use Condoedge\Ai\Services\Response\ResponseFileEnricher;
use Condoedge\Ai\Services\Response\ResponseEntityEnricher;
use Condoedge\Ai\Services\Retrieval\DocumentProbeWriter;
use Condoedge\Ai\Services\Retrieval\PassageSelector;
use Condoedge\Ai\Services\Schema\GraphSchemaCache;
use Condoedge\Ai\Services\Understanding\ClarityChecker;
use Condoedge\Ai\Support\Audiences;
use Condoedge\Ai\Support\PipelineObserverScope;

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

    /**
     * Vote groups of the document-search angles (see searchAngles()): every plain
     * angle is a group of its own; the language renderings of one probe slot share
     * the group ANGLE_PROBE_PREFIX . <slot number>.
     */
    private const ANGLE_SEARCH_QUERY = 'search_query';
    private const ANGLE_QUESTION = 'question';
    private const ANGLE_CLARIFIED = 'clarified_question';
    private const ANGLE_TAGS = 'search_tags';
    private const ANGLE_PAGE = 'page_context';
    private const ANGLE_PROBE_PREFIX = 'probe_';

    /** How many of a file's passages, best first, the stage trace keeps per angle. */
    private const TRACED_CHUNKS_PER_ANGLE = 5;

    /** Two fused vote weights within this are the same vote (float summation order). */
    private const EQUAL_VOTE_EPSILON = 1e-12;

    private readonly QueryParameterizer $queryParameterizer;

    private readonly ExportResponseComposer $exportComposer;

    private readonly DigestRunner $digestRunner;

    private readonly DigestResponseComposer $digestComposer;

    /** The ONE composition call of a conversational turn (small talk, questions about the assistant). */
    private readonly ConversationalReplyComposer $conversationalComposer;

    /** Code-attached links/actions (actions plan B1–B4): the AI only narrates. */
    private readonly ActionAttacher $actionAttacher;

    /** What an empty result means (scope, ownership count, host fallback) — built on first use. */
    private ?EmptyResultInspector $emptyResultInspector = null;

    /** Names the parents of a single-record result (the project of a task) — built on first use. */
    private ?RecordParentEnricher $recordParentEnricher = null;

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
     * @param ActionAttacher|null $actionAttacher Code-attached links/actions
     * @param DocumentProbeWriter|null $probeWriter Focused document-search probe call (one small LLM
     *        call, only on turns where document search runs); null = no probes
     * @param ClarityChecker|null $clarityChecker Focused "is this question a fork?" call (one small
     *        LLM call, only on a first message that names no subject); null = never asks
     * @param ConversationalReplyComposer|null $conversationalComposer The one composition call of a
     *        conversational turn; null = one on the injected LLM
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
        ?DigestResponseComposer $digestComposer = null,
        ?ActionAttacher $actionAttacher = null,
        private readonly ?DocumentProbeWriter $probeWriter = null,
        private readonly ?ClarityChecker $clarityChecker = null,
        private readonly ?PassageSelector $passageSelector = null,
        private readonly ?\Condoedge\Ai\Services\Retrieval\DocumentMapTurn $documentMapTurn = null,
        private readonly ?\Condoedge\Ai\Services\Retrieval\DocumentScopeResolver $documentScopeResolver = null,
        private readonly ?\Condoedge\Ai\Services\Retrieval\DocumentOwnerLabels $documentOwnerLabels = null,
        ?ConversationalReplyComposer $conversationalComposer = null
    ) {
        $this->conversationalComposer = $conversationalComposer ?? new ConversationalReplyComposer($llm);
        $this->actionAttacher = $actionAttacher ?? new ActionAttacher();
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
     * Ingest an entity into the graph store
     *
     * @param Nodeable $entity Entity to ingest (must implement Nodeable)
     * @return array Status array with keys: graph_stored, relationships_created, errors
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
     * Remove an entity from the graph
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
     * @return array Status array with keys: action, graph_synced, errors
     */
    public function sync(Nodeable $entity): array
    {
        return $this->ingestion->sync($entity);
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
     * @param array $options Optional parameters (collection, limit, ...)
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
     * Drop the cached graph schema and every cached per-label property sample
     * (Task 5.3). Called by the ingest/index/setup commands after they change the
     * graph; otherwise the cache expires after ai.schema_cache_ttl seconds.
     */
    public function flushSchemaCache(): void
    {
        (new GraphSchemaCache())->flush();
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
     * array contract. No side effects: few-shot examples are stored only after
     * a successful execution (storeProvenExample()).
     */
    private function generateQueryResult(string $question, ?PipelineContext $context = null, array $options = []): QueryGenerationResult
    {
        try {
            if ($context === null) {
                $context = $this->context->retrieveContext($question, $options);
            }

            // No few-shot side effect here: an example is stored only once it is
            // PROVEN by execution — see storeProvenExample() (Task 1.9).
            return $this->withRecordIds($this->queryGenerator->generate($question, $context, $options));

        } catch (\Throwable $e) {
            \Log::error('Error generating query: ' . $e->getMessage(), [
                'question' => $question,
                'exception' => get_class($e),
            ]);
            PipelineObserverScope::for($context)->stageError('query_generation', $e);

            return new QueryGenerationResult(
                cypher: null,
                explanation: '',
                confidence: 0.0,
                warnings: [$e->getMessage()],
                metadata: ['error' => true, 'error_message' => $e->getMessage()],
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
        // that hit the executor cap gets the TRUE total stamped. anchor_entity_types (optional) = the QU
        // target entity types, so the distinct-anchor count picks the same
        // variable production picks; it is consumed here, never sent to the executor.
        $targetLabels = is_array($options['anchor_entity_types'] ?? null) ? $options['anchor_entity_types'] : [];
        unset($options['anchor_entity_types']);
        $execution = $this->executeWithTrueTotal($cypherQuery, $parameters, $options, $targetLabels);

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
     *
     * true_total is ALWAYS the ROW total — completeness (export complete flag),
     * truncation disclosure and "first X of N" all reason in rows. On a fan-out
     * join (members×roles) the DISTINCT count of the anchor entity is stamped
     * SEPARATELY (anchor_total / anchor_variable / anchor_label) and is used only
     * for "how many <entities>" wording in the response prompt.
     *
     * @param array<int, string> $targetLabels QU target entity types (anchor preference)
     */
    private function executeWithTrueTotal(?string $cypherQuery, array $parameters, array $options, array $targetLabels = []): QueryExecutionResult
    {
        $execution = $this->queryExecutor->execute($cypherQuery, $parameters, $options);

        $total = $this->companionTrueTotal($cypherQuery, $parameters, $options, count($execution->data));
        if ($total === null) {
            return $execution;
        }

        $stamp = ['true_total' => $total];
        $anchor = $this->companionAnchorTotal((string) $cypherQuery, $parameters, $options, $targetLabels);
        if ($anchor !== null) {
            $stamp += $anchor;
        }

        return new QueryExecutionResult(
            success: $execution->success,
            data: $execution->data,
            stats: $execution->stats + $stamp,
            metadata: $execution->metadata + $stamp,
            errors: $execution->errors,
        );
    }

    /**
     * The DISTINCT count of the anchor entity of a fan-out result, or null when
     * no anchor is derivable or the count fails (wording then stays row-based).
     * Runs through the SAME scoped execute() path (scoping, rate limit, provenance).
     *
     * @param array<int, string> $targetLabels
     * @return array{anchor_total: int, anchor_variable: string, anchor_label: string|null, anchor_first_item: bool}|null
     */
    private function companionAnchorTotal(string $cypherQuery, array $parameters, array $options, array $targetLabels): ?array
    {
        $inner = preg_replace('/\bLIMIT\s+\d+\s*;?\s*$/i', '', trim($cypherQuery)) ?? trim($cypherQuery);
        $anchor = $this->distinctAnchorCountQuery($inner, $targetLabels);
        if ($anchor === null) {
            return null;
        }

        try {
            $count = $this->queryExecutor->execute($anchor['query'], $parameters, $options)->data[0]['total'] ?? null;
        } catch (\Throwable $e) {
            \Log::warning('Companion anchor count failed — keeping row-based wording: ' . $e->getMessage());
            return null;
        }

        if (!is_numeric($count)) {
            return null;
        }

        return [
            'anchor_total' => (int) $count,
            'anchor_variable' => $anchor['variable'],
            'anchor_label' => $anchor['label'],
            'anchor_first_item' => $anchor['first_item'],
        ];
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
            // ROW total — the distinct-entity count of a fan-out is stamped
            // separately (companionAnchorTotal); every completeness/truncation
            // consumer of true_total reasons in rows.
            $total = $this->queryExecutor->executeCount($inner, $parameters, $options);
        } catch (\Throwable $e) {
            \Log::warning('Companion count for capped result failed — keeping "more may exist" behavior: ' . $e->getMessage());
            return null;
        }

        return $total >= $rowsOut ? $total : null;
    }

    /**
     * The DISTINCT-anchor count query for a fan-out result, or null when the
     * anchor is not safely derivable (no anchor count is stamped).
     *
     * Derivable = no UNION; the final RETURN is a top-level, non-DISTINCT,
     * non-aggregating projection; and the MATCH / OPTIONAL MATCH patterns bind
     * more than one node variable (a single-variable MATCH cannot fan out — the
     * row count already is the entity count). Only pattern-bound node variables
     * count: function arguments (count(r), size(t)), WHERE predicates and UNWIND
     * scalars never do. The query up to that RETURN is kept verbatim and the
     * projection (plus its ORDER BY/SKIP/LIMIT) is replaced by
     * `RETURN count(DISTINCT v) AS total`. Purely structural — no vocabulary.
     *
     * ANCHOR CHOICE (known limit): only projected items of the form `v` / `v.prop`
     * whose `v` is a pattern-bound node are candidates. When the FIRST RETURN item
     * is such a reference, its variable is the anchor. Otherwise (first item is an
     * expression) the first candidate whose label is a QU target entity type wins,
     * else the first candidate. That is a heuristic — the grain the question is
     * about is not always the first column (e.g. `RETURN c.number, p.amount` for a
     * payments list would count customers) — so the counted label is always stamped
     * (anchor_label) and the prompt names it, and the prompt line is emitted only
     * on a real fan-out (anchor_total < rows).
     *
     * @param array<int, string> $targetLabels
     * @return array{query: string, variable: string, label: string|null, first_item: bool}|null
     */
    private function distinctAnchorCountQuery(string $cypher, array $targetLabels = []): ?array
    {
        $anchor = $this->projectionAnchor($cypher, $targetLabels);
        if ($anchor === null || $anchor['node_variables'] < 2) {
            return null;
        }

        return [
            'query' => $anchor['head'] . " RETURN count(DISTINCT {$anchor['variable']}) AS total",
            'variable' => $anchor['variable'],
            'label' => $anchor['label'],
            // Whether the anchor is the FIRST RETURN item (the reliable rule) rather
            // than the QU-type / first-projected tie-break heuristic. Only a
            // first-item anchor may drive the per-anchor collapse (collapseFanOut).
            'first_item' => $anchor['first_item'],
        ];
    }

    /**
     * The anchor node variable of a query's final RETURN (see
     * distinctAnchorCountQuery for the rule), with its label, the canonical head
     * before that RETURN and how many pattern node variables the head binds —
     * or null when the projection has no such anchor. Works on the canonical
     * (comment-stripped) text with strings and backtick identifiers masked, so a
     * comment or quoted word never moves the final RETURN (review S1).
     *
     * @param array<int, string> $targetLabels
     * @return array{head: string, variable: string, label: string|null, first_item: bool, node_variables: int}|null
     */
    private function projectionAnchor(string $cypher, array $targetLabels = []): ?array
    {
        $cypher = FanOutCollapse::canonical($cypher);
        if ($cypher === null) {
            return null;
        }
        $masked = FanOutCollapse::mask($cypher);

        // The UNION clause only — never a label or alias that merely holds the word
        // (same rule as RecordIdProjection; a host label of that name used to make
        // every list scoped by it skip the anchor count).
        if (FanOutCollapse::hasUnionClause($cypher)) {
            return null;
        }

        // The final RETURN must be top-level (not inside a CALL { } subquery).
        $returnPos = FanOutCollapse::finalReturnOffset($masked);
        if ($returnPos === null) {
            return null;
        }
        $head = substr($cypher, 0, $returnPos);
        $maskedHead = substr($masked, 0, $returnPos);

        $maskedProjection = substr($masked, $returnPos + strlen('RETURN'));
        $end = preg_match('/(?<![.\w$])\b(ORDER\s+BY|SKIP|LIMIT)\b/i', $maskedProjection, $clause, PREG_OFFSET_CAPTURE) === 1
            ? (int) $clause[0][1]
            : strlen($maskedProjection);
        $projection = trim(substr($cypher, $returnPos + strlen('RETURN'), $end));
        $maskedProjection = trim(substr($maskedProjection, 0, $end));

        if (preg_match('/^DISTINCT\b/i', $projection) === 1
            || preg_match('/\b(count|sum|avg|min|max|collect|stDev|stDevP|percentileCont|percentileDisc)\s*\(/i', $maskedProjection) === 1) {
            return null;
        }

        // Node variables bound in MATCH / OPTIONAL MATCH patterns only: each
        // pattern segment runs from MATCH to the next clause keyword, so WHERE
        // predicates, WITH/RETURN function arguments and UNWIND never contribute.
        // Segments come from the masked head; labels are read from the canonical
        // head at the same offsets (a backticked label keeps its name).
        $nodeLabels = [];
        preg_match_all(
            '/\bMATCH\b(.*?)(?=\b(?:WHERE|WITH|RETURN|UNWIND|CALL|OPTIONAL|MATCH|ORDER|SKIP|LIMIT|MERGE|CREATE)\b|$)/is',
            $maskedHead,
            $segments,
            PREG_OFFSET_CAPTURE
        );
        foreach ($segments[1] as [$maskedSegment, $offset]) {
            $segment = substr($head, (int) $offset, strlen($maskedSegment));
            preg_match_all('/\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*(?::\s*`?([A-Za-z_][A-Za-z0-9_]*)`?)?[^()]*\)/', $segment, $nodes, PREG_SET_ORDER);
            foreach ($nodes as $node) {
                if (!array_key_exists($node[1], $nodeLabels) || ($nodeLabels[$node[1]] === null && !empty($node[2]))) {
                    $nodeLabels[$node[1]] = !empty($node[2]) ? $node[2] : null;
                }
            }
        }

        if ($nodeLabels === []) {
            return null;
        }

        // Projected node variables, in projection order.
        $items = FanOutCollapse::splitTopLevel($projection);
        $projected = [];
        foreach ($items as $item) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)(\.[A-Za-z_][A-Za-z0-9_]*)?(\s+AS\s+\S+)?\s*$/i', $item, $m) === 1
                && array_key_exists($m[1], $nodeLabels)) {
                $projected[] = $m[1];
            }
        }

        $firstItem = trim($items[0] ?? '');
        $firstVar = preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $firstItem, $fm) === 1 ? $fm[1] : null;

        if ($projected === []) {
            return null;
        }

        // The RETURN projection is authoritative: when its FIRST item is a plain
        // node reference (`v` / `v.prop`), that variable is the grain the rows are
        // about. Live roster (Task 1.27 f): QU types [Team, TeamRole] used to win
        // over `RETURN u.name AS member_name, tr.role` → "523 distinct roles".
        // QU target types only break the tie AMONG projected variables when the
        // first item is an expression; otherwise the first projected variable.
        $anchor = null;
        $firstItemAnchor = false;
        if ($firstVar !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?(\s+AS\s+\S+)?$/i', $firstItem) === 1
            && in_array($firstVar, $projected, true)) {
            $anchor = $firstVar;
            $firstItemAnchor = true;
        }
        if ($anchor === null) {
            foreach ($projected as $variable) {
                if ($nodeLabels[$variable] !== null && in_array($nodeLabels[$variable], $targetLabels, true)) {
                    $anchor = $variable;
                    break;
                }
            }
        }
        $anchor ??= $projected[0];

        return [
            'head' => rtrim($head),
            'variable' => $anchor,
            'label' => $nodeLabels[$anchor],
            'first_item' => $firstItemAnchor,
            'node_variables' => count($nodeLabels),
        ];
    }

    // =========================================================================
    // Response Generation Methods
    // =========================================================================

    /**
     * Generate natural language response from pipeline context
     *
     * @param PipelineContext $context Pipeline context with question, query result, execution data
     * @param array $options Generation options
     * @return array Response with answer, format, metadata
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
     * Observation (Phase 4.1): options['observer'] (a PipelineObserverInterface;
     * default = the container binding, a null observer) receives every stage of
     * the branch this turn takes, plus the verbatim LLM prompts and token usage.
     * It is wrapped in GuardedPipelineObserver (a throwing observer can never
     * break or change the turn) and rides PipelineContext::$observer and the
     * ambient PipelineObserverScope. It is removed from $options here; only
     * retrieveContext() receives it as options['observer'], and ContextRetriever
     * reads and strips it before anything else sees its options.
     *
     * @param string $question Natural language question
     * @param array $options Options for all stages
     * @return array Complete response with natural language answer
     */
    public function answerQuestion(string $question, array $options = []): array
    {
        $observer = \Condoedge\Ai\Support\GuardedPipelineObserver::wrap(
            ($options['observer'] ?? null) instanceof PipelineObserverInterface
                ? $options['observer']
                : (app()->bound(PipelineObserverInterface::class)
                    ? app(PipelineObserverInterface::class)
                    : new \Condoedge\Ai\Support\NullPipelineObserver())
        );
        unset($options['observer']);

        return PipelineObserverScope::run(
            $observer,
            fn (): array => $this->runAnswerPipeline($question, $options, $observer)
        );
    }

    /**
     * Admission of one turn against the rate limits (per user + global cap). Returns null
     * when the turn may run — immediately, or after waiting in line up to
     * ai.rate_limits.max_wait_seconds — else the ready-made "busy" answer.
     *
     * The busy answer costs no LLM call, says how long until a slot frees and that the
     * question can simply be sent again; it is a normal assistant message (status
     * `rate_limited`), so the chat's regenerate / resend affordances work on it.
     *
     * @return array<string, mixed>|null
     */
    private function admitTurn(string $question, array $options, PipelineObserverInterface $observer): ?array
    {
        // Only the concrete executor knows the budgets (a decorated / mocked executor
        // simply charges at execution time, as before).
        if (!$this->queryExecutor instanceof QueryExecutor) {
            return null;
        }

        $admission = $this->queryExecutor->admit($options, max(0, (int) config('ai.rate_limits.max_wait_seconds', 20)));
        if ($admission['waited_seconds'] > 0 || !$admission['admitted']) {
            $this->emitStage($observer, 'admission', $admission);
        }
        if ($admission['admitted']) {
            return null;
        }

        $seconds = max(1, (int) $admission['retry_after']);
        $locale = is_string($options['question_language'] ?? null) && $options['question_language'] !== ''
            ? $options['question_language']
            : null;

        return [
            'question' => $question,
            'answer' => trans('ai.error.busy_try_again', ['seconds' => $seconds], $locale),
            'cypher' => null,
            'data' => [],
            'stats' => [],
            'referenced_files' => [],
            'metadata' => [
                'query' => ['source' => 'admission'],
                'execution' => [],
                'response' => ['status' => 'rate_limited', 'retry_after' => $seconds, 'error' => true],
                'scope_clarified' => false,
                'security_filter_applied' => false,
            ],
        ];
    }

    /**
     * The answerQuestion() body, run inside the turn's observer scope.
     */
    private function runAnswerPipeline(string $question, array $options, PipelineObserverInterface $observer): array
    {
        // The stage a failure escaping to the catch-all below is attributed to.
        $stage = 'understanding';

        // ONE rate-limit charge per turn: a turn's main query, its companion counts, the
        // fan-out collapse re-run, export re-runs and repairs are one user action (same
        // principle as the digest's per-run batch). Charged per execution, a single turn
        // cost up to ~8 of the 30/min budget and eval p15 voided turns on its own limiter.
        if (!is_string($options['rate_limit_batch'] ?? null) || $options['rate_limit_batch'] === '') {
            $options['rate_limit_batch'] = 'turn:' . bin2hex(random_bytes(8));
        }

        // ADMISSION (owner request 2026-10-06): the turn takes that single charge NOW —
        // before any LLM call is paid for — and waits in line when the budget is full.
        // Only a wait longer than the bound is refused, with the exact seconds, never as
        // "something went wrong".
        $refusal = $this->admitTurn($question, $options, $observer);
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            // The retriever stops after Query Understanding when the reading names nothing
            // to look up (see the conversational turn below) — on this request only.
            $ctx = $this->observeStage(
                $observer,
                'understanding',
                fn (): PipelineContext => $this->context->retrieveContext(
                    $question,
                    $options + ['observer' => $observer, ContextRetriever::DEFER_WHEN_NOTHING_NAMED => true]
                ),
                fn (PipelineContext $c): array => $this->understandingTrace($c)
            );
            $ctx = $this->withTurnContext($ctx, $observer, $options);

            // CONVERSATIONAL TURN (2026-10-21): a greeting, thanks, small talk or a question
            // about the assistant itself. Precondition (structure only, no word list): QU's
            // reading names nothing to look up, so the retriever built no context
            // ($ctx->retrievalDeferred). Verdict: the conversational composer judges the
            // message and replies in ONE call — nothing is queried: no clarity check,
            // document search, query generation, execution, follow-up writer or record
            // enrichment. Declined (the message asks for something): the context is
            // retrieved in full on the SAME reading and the turn runs exactly as before. A
            // failed call takes the generic error path below.
            if ($ctx->retrievalDeferred) {
                $stage = 'composition';
                $conversational = $this->observeStage(
                    $observer,
                    ConversationalReplyComposer::STAGE,
                    fn (): ?array => $this->conversationalComposer->compose($question, $ctx, $options),
                    fn (?array $r): array => ['conversational' => $r !== null]
                );
                if ($conversational !== null) {
                    $this->emitStage($observer, 'composition', $this->compositionTrace('conversational', $conversational));

                    return $conversational;
                }

                $stage = 'understanding';
                $qu = $ctx->qu;
                $ctx = $this->observeStage(
                    $observer,
                    'context_retrieval',
                    fn (): PipelineContext => $this->context->retrieveContext(
                        $question,
                        $options + ['observer' => $observer, ContextRetriever::UNDERSTOOD => $qu]
                    ),
                    fn (PipelineContext $c): array => $this->understandingTrace($c)
                );
                $ctx = $this->withTurnContext($ctx, $observer, $options);
            }

            // "Explain this page" — read-only page narration. When the user asks what the
            // page is / what they can do AND the host configured a description for it, answer
            // from that description (grounded on the page + route) WITHOUT running a graph
            // query, so it never reaches the empty-result abstention path. Returns null when
            // the feature is unconfigured or the intent does not match — the turn is then a
            // normal, unchanged query.
            $stage = 'page_narration';
            $pageNarration = $this->pageNarrationResponse($ctx, $question, $options);
            if ($pageNarration !== null) {
                return $pageNarration;
            }

            // "What needs my attention?" — the deterministic multi-source digest
            // (design 2026-07-23). Owns the turn ONLY when QU judged it a broad
            // digest ask (even if also flagged a follow-up) AND the host configured attention
            // sources. Returning here skips file search AND query generation by
            // construction — the config-declared source walk replaces both.
            $stage = 'digest';
            $digest = $this->digestResponse($ctx, $question, $options);
            if ($digest !== null) {
                return $digest;
            }

            // GENUINE AMBIGUITY (item C): a first message that asks for data and
            // names no subject may be a real fork. The focused clarity call
            // judges it (only when its structural preconditions hold — most turns
            // make no call); on a fork, skip file search and query generation and
            // ask the ONE short clarifying question instead of force-resolving a
            // guessed reading. Guarded: never on follow-up/digest turns,
            // config-gated, and a failed call falls back to the normal pipeline.
            $stage = 'clarify';
            $this->checkClarity($ctx, $question);
            $clarify = $this->clarifyResponse($ctx, $question, $options);
            if ($clarify !== null) {
                return $clarify;
            }

            $stage = 'file_context';
            $ctx = $this->retrieveAndSetFileContext($ctx, $question, $options);

            $stage = 'query_generation';
            $ctx = $this->generateAndExecute($ctx, $question, $options);

            $stage = 'composition';
            // Follow-up question chips of a document-grounded answer: ONE small focused call
            // AFTER composition (its own 'follow_ups' stage), merged into the turn's chips.
            // Decoration only — never throws; data turns and abstentions make no call.
            // Then its page buttons, picked from the pages setup offers (ai.navigation.pages).
            $response = $this->actionAttacher->withNavigation(
                $this->actionAttacher->withAnswerFollowUps($this->buildFinalResponse($question, $ctx, $options), $ctx, $options),
                $ctx,
                $options
            );
            // Text the caller adds under the answer follows the question's language, as the answer does.
            if ($ctx->qu->language !== '') {
                $response['metadata']['question_language'] = $ctx->qu->language;
            }

            return $response;

        } catch (\Throwable $e) {
            \Log::error('Error answering question: ' . $e->getMessage());
            $observer->stageError($stage, $e);

            // LANGUAGE POLICY (live failure 2026-07-23: French canned error to an
            // English question escaped through THIS catch): when the pipeline got
            // far enough for QU to detect the question language, thread it — the
            // canned error must follow the question's language, not the app locale.
            $errorOptions = $options;
            if (isset($ctx) && $ctx->qu->language !== '') {
                $errorOptions['question_language'] = $ctx->qu->language;
            }
            $errorResponse = $this->observeStage(
                $observer,
                'composition',
                fn (): array => $this->responseGenerator->generateErrorResponse($question, $e, $errorOptions),
                fn (array $r): array => $this->compositionTrace('error', $r)
            );

            return [
                'question' => $question,
                'answer' => $errorResponse['answer'],
                'cypher' => null,
                'data' => [],
                'stats' => [],
                'referenced_files' => [],
                'metadata' => (is_array($errorResponse['metadata'] ?? null) ? $errorResponse['metadata'] : [])
                    + (isset($ctx) ? $this->quUnavailableMetadata($ctx) : []),
            ];
        }
    }

    /**
     * Retrieve file context using clarified question for better semantic matching,
     * observed as the 'file_context' stage (skip reason, angles, retrieved refs).
     */
    private function retrieveAndSetFileContext(PipelineContext $ctx, string $question, array $options): PipelineContext
    {
        $trace = [];

        return $this->observeStage(
            $ctx->observer,
            'file_context',
            function () use ($ctx, $question, $options, &$trace): PipelineContext {
                return $this->fanOutFileContext($ctx, $question, $options, $trace);
            },
            function (PipelineContext $c) use (&$trace): array {
                return $trace + $this->fileContextTrace($c);
            }
        );
    }

    /**
     * The file-context fan-out itself. $trace collects observation-only facts
     * (skip reason, angles, recall-floor rescue) for the stage event.
     */
    private function fanOutFileContext(PipelineContext $ctx, string $question, array $options, array &$trace): PipelineContext
    {
        if (!config('ai.file_context.enabled', true)) {
            $trace['skipped'] = 'disabled';
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
                $trace['enumeration'] = true;
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
        if (!$isFileListing && $this->shouldSkipFileSearchFor($ctx, $user)) {
            $trace['skipped'] = 'question_type';
            return $ctx;
        }

        // Document search runs for this turn: NOW (and only now) pay for the focused
        // probe call. A skipped data turn, a file listing and a disabled file search
        // all returned above without it.
        //
        // DOCUMENT MAP TURN (ai.file_context.document_map): when the documents carry a
        // map, the plain angles search alone and ONE reader call made after the search
        // replaces the probe call and the passage selection ({@see self::mapTurnPlanned()},
        // {@see \Condoedge\Ai\Services\Retrieval\DocumentMapTurn}). Whenever that turns
        // out not to apply — no admitted file with a map, a borderline rescue, no
        // file at all, a failed reader, a turn that returns to covered ground — this
        // method runs again as it always did ($options['_no_document_map']), probes
        // and selection included. That second pass does NOT search again what the
        // first one found: both share one memo of the searches made this turn
        // ($options['_fan_out_memo']), so it pays only for the probe angles.
        $mapTurn = $this->mapTurnPlanned($ctx, $isFileListing, $options);
        $probes = $mapTurn ? [] : $this->writeSearchProbes($ctx, $question, $isFileListing, $trace);

        $fanOutMemo = ($options['_fan_out_memo'] ?? null) instanceof \ArrayObject ? $options['_fan_out_memo'] : new \ArrayObject();
        $options['_fan_out_memo'] = $fanOutMemo;
        // One search per distinct (angle, kind) this turn, whichever pass asks for it.
        $searchOnce = function (string $kind, string $query, \Closure $search) use ($fanOutMemo): array {
            $key = $kind . ':' . $query;
            if (!$fanOutMemo->offsetExists($key)) {
                $fanOutMemo[$key] = $search();
            }

            return $fanOutMemo[$key];
        };

        // The search angles, each with the VOTE GROUP it belongs to in the
        // cross-angle fusion below (see searchAngles()). All angles are embedded in
        // one batch; those embeddings also tell which probes only restate another
        // probe or the question — such a probe is not searched at all.
        $angles = $this->searchAngles($ctx, $question, $probes, $this->pageContextQueryAngle($options['page_context'] ?? []));
        $angles = $this->withoutDuplicateProbes(
            $angles,
            $this->embedAnglesInOneBatch(array_column($angles, 'text')),
            $trace
        );
        $queries = array_column($angles, 'text');
        $trace['angles'] = $queries;
        $trace['angle_groups'] = array_column($angles, 'group');

        // Different phrasings find different files AND surface different chunks of
        // the SAME file. Collect every per-angle result per file so the merge can
        // both rank the file by its strongest angle and pick the most useful snippet
        // across angles (not just the highest-cosine one, which is often a generic
        // cover/preamble chunk).
        // FOLLOW-UP ANGLE (Phase 2.B): the user's VERBATIM wording is the one
        // angle that can carry an unresolved referent ("Et celles-là ?"); QU's
        // rewrites (search query / clarified question / translations) are
        // self-contained. When QU judged the turn a follow-up, that angle is
        // searched with the conversation focus (FileContextProvider prefixes
        // it) — driven by QU's followUpReference, never a pronoun word list.
        $followUpOptions = $ctx->qu->followUpReference && !empty($ctx->conversationContext)
            ? ['conversation_context' => $ctx->conversationContext, 'follow_up_reference' => true]
            : [];
        $angleOptions = static fn (string $angle): array => $angle === $question ? $followUpOptions : [];

        // Each angle may return up to the per-file passage budget of DISTINCT
        // passages of one file (Phase 7: one article per chunk, so the runner-up
        // article of a file is often as relevant as its best one).
        $maxChunksPerFile = max(1, (int) config('ai.file_context.max_chunks_per_file', 5));
        // Every search of this fan-out is for the same user over the same files:
        // they share one memo, so the user's file access and each file's chunks are
        // resolved once for the turn, not once per angle. It dies with this call.
        // (A second pass of this method — the document-map fallback — keeps the first's.)
        $fanOutMemo['turn_memo'] ??= new \ArrayObject();
        $turnMemo = [FileContextProvider::TURN_MEMO => $fanOutMemo['turn_memo']];

        // THE QUESTION'S SCOPE — the record it is about, from structural signals
        // (page record, conversation record, previously cited files, the host's
        // current scope: DocumentScopeResolver). Resolved once per turn and left
        // in the turn memo, where every search of the turn finds the same one:
        // UPLOADED files are searched inside it (beside the ACL), project files
        // never are. Unknown: nothing is narrowed and the uploaded passages are
        // told apart by the record they belong to (withScopeLabels()).
        if (!$fanOutMemo['turn_memo']->offsetExists(FileContextProvider::SCOPE)) {
            $fanOutMemo['turn_memo'][FileContextProvider::SCOPE] = $this->documentScopeResolver !== null
                ? $this->documentScopeResolver->resolve(
                    is_array($options['page_context'] ?? null) ? $options['page_context'] : [],
                    is_array($ctx->conversationContext) ? $ctx->conversationContext : [],
                    $user
                )
                : \Condoedge\Ai\Services\Retrieval\DocumentScope::unknown();
        }
        /** @var \Condoedge\Ai\Services\Retrieval\DocumentScope $scope */
        $scope = $fanOutMemo['turn_memo'][FileContextProvider::SCOPE];
        $trace['scope'] = $scope->toArray();
        // … and says which chunks each snippet is made of, so passages of one file
        // whose windows overlap can be joined (withoutOverlappingPassages()).
        $passageOptions = ['chunks_per_file' => $maxChunksPerFile, FileContextProvider::PASSAGE_CHUNKS => true] + $turnMemo;

        $perFileResults = [];
        $angleChunkScores = [];
        $angleBallots = [];
        $questionPassage = [];
        $admittedByAngle = [];
        $admittingGroups = [];
        $anyAngleSucceeded = false;
        foreach ($angles as $angleNumber => $angle) {
            $searchQuery = $angle['text'];
            $fileContext = $searchOnce('angle', $searchQuery, fn (): array => $this->retrieveFileContext(
                $searchQuery,
                $user,
                $angleOptions($searchQuery) + $passageOptions
            ));
            // A successful retrieval (even an empty one) always carries 'file_count';
            // retrieveFileContext returns a bare [] only when the provider threw. This
            // lets the recall net below distinguish "searched, found nothing" (worth a
            // retry at a lower floor) from "retrieval errored" (a retry would re-throw).
            if (isset($fileContext['file_count'])) {
                $anyAngleSucceeded = true;
            }
            $seenThisAngle = [];
            $preferred = [];
            $picks = [];
            foreach ($fileContext['relevant_files'] ?? [] as $file) {
                $fileId = $file['file_id'] ?? $file['filename'] ?? '';
                $perFileResults[$fileId][] = $file;
                $admittedByAngle[$angleNumber][$fileId] = true;
                $admittingGroups[$fileId][$angle['group']] = true;
                if (isset($file['chunk_index'])) {
                    $picks[$fileId][] = (int) $file['chunk_index'];
                }

                // The references come best first, so a file's first reference is
                // the passage of it this angle prefers. The user's OWN wording keeps
                // that passage whatever the other angles vote (mergeFileAngleResults).
                if (!array_key_exists((string) $fileId, $preferred)) {
                    $preferred[(string) $fileId] = isset($file['chunk_index']) ? [(int) $file['chunk_index']] : [];
                    if ($angle['group'] === self::ANGLE_QUESTION && isset($file['chunk_index'])) {
                        $questionPassage[$fileId] = (int) $file['chunk_index'];
                    }
                }

                // This angle's ranking of the file's chunks (once per angle), filed
                // under the angle's vote group.
                if (!isset($seenThisAngle[$fileId]) && is_array($file['chunk_scores'] ?? null)) {
                    $angleChunkScores[$fileId][] = ['group' => $angle['group'], 'scores' => $file['chunk_scores']];
                    $seenThisAngle[$fileId] = true;

                    $ranking = $file['chunk_scores'];
                    arsort($ranking);
                    $preferred[(string) $fileId] = array_slice(
                        array_map('intval', array_keys($ranking)),
                        0,
                        self::TRACED_CHUNKS_PER_ANGLE
                    );
                }
            }
            // This angle's BALLOT per file: its own distinct passages of the file,
            // best first, under its vote group (branch coherence weighs them).
            foreach ($picks as $fileId => $filePicks) {
                $angleBallots[$fileId][] = ['group' => $angle['group'], 'picks' => $filePicks];
            }
            // Observation only: which passages each angle preferred, per file — what
            // a retrieval regression is traced with (eval p16 had only the outcome).
            $trace['angle_chunks'][] = $preferred;
        }

        // EXACT-ARTICLE angle (Phase 7.2): when the question (or QU's rewrite of it)
        // names an article by number ("art. 269", "270.1."), add one angle that
        // filters the chunks by their article metadata. It is a structural match
        // the user asked for by number, so no relevance floor applies; the merge
        // below makes the named article lead its file. Numeric-reference grammar
        // only (ArticleLocator), never a vocabulary.
        $articleNumbers = [];
        foreach ([$question, $ctx->qu->searchQuery, $ctx->qu->clarifiedQuestion] as $text) {
            $articleNumbers = [...$articleNumbers, ...\Condoedge\Ai\Support\ArticleLocator::referencedArticles((string) $text)];
        }
        $articleNumbers = array_values(array_unique($articleNumbers));
        if ($articleNumbers !== []) {
            $trace['article_numbers'] = $articleNumbers;
            $fileContext = $searchOnce('articles', implode(',', $articleNumbers), fn (): array => $this->retrieveFileContext($question, $user, [
                'article_numbers' => $articleNumbers,
                'min_score' => 0.0,
            ] + $passageOptions));
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

        // What the previous turn already cited (file id => chunk indexes): on a
        // follow-up, new evidence wins a tie against a passage the user has read.
        $previouslyCited = \Condoedge\Ai\Services\Retrieval\DeepeningContract::citedChunks($ctx->conversationContext);

        // A VOTE FROM UNDER THE FLOOR (eval p16–p21 tear-down). An angle that admitted
        // no passage of an admitted file still votes in that file's fusion, for the
        // ONE chunk it ranks first there — the ballot it casts when its best match
        // just clears the floor. The floor decides what an angle admits, not whether
        // it has a say: a cosine is relative to the wording that produced it. The
        // user's plain words scored their best passage 0.38; QU's free-text rewrite
        // of them 0.37 in one run and 0.41 in the next, on a byte-identical prompt —
        // so floor-bound ballots silenced the question altogether and made the
        // passage ORDER follow whichever rewrite QU happened to write (the passage
        // every plain phrasing ranks first led in some runs, came third in others).
        // Which passage a phrasing ranks first does not depend on that scale.
        //
        // The vote makes no passage a candidate. And it is that one chunk, not the
        // angle's whole ranking: with every ranked chunk a vote, the fusion counts in
        // how many rankings a passage appears at all, and the passage the probes'
        // hypothesis prefers led whatever the question ranked first (measured: 9 of
        // 9 replayed runs; the live answers anchored on it).
        //
        // Only the angles silent on an admitted file search again (their ranking
        // without the floor; the query embedding is memoized) — a turn whose every
        // angle admits its files costs nothing. ai.file_context.vote_below_floor.
        $votesBelowFloor = [];
        if ($perFileResults !== [] && config('ai.file_context.vote_below_floor', true)) {
            $window = max(0, (int) config('ai.file_context.context_window', 2));
            foreach ($angles as $angleNumber => $angle) {
                $silentOn = array_diff_key($perFileResults, $admittedByAngle[$angleNumber] ?? []);
                if ($silentOn === []) {
                    continue;
                }
                $rankings = $searchOnce('ranking', $angle['text'], fn (): array => $this->rankFileChunks(
                    $angle['text'],
                    $user,
                    $angleOptions($angle['text']) + $turnMemo
                ));
                foreach (array_keys($silentOn) as $fileId) {
                    $scores = $rankings[$fileId] ?? null;
                    if (!is_array($scores) || $scores === []) {
                        continue;
                    }
                    arsort($scores);
                    $first = array_slice($scores, 0, 1, true);
                    $angleChunkScores[$fileId][] = ['group' => $angle['group'], 'scores' => $first];
                    if (!isset($admittingGroups[$fileId][$angle['group']])) {
                        $votesBelowFloor[$fileId][$angle['group']] = true;
                    }

                    // EVIDENCE FLOOR from under the relevance floor: the passage the
                    // user's own wording RANKS first in the file keeps a slot too —
                    // provided another angle admitted it (or the passage whose window
                    // holds it). It protects a candidate, never creates one.
                    if ($angle['group'] === self::ANGLE_QUESTION && !isset($questionPassage[$fileId])) {
                        $passage = $this->candidateHoldingFirstRanked($first, $perFileResults[$fileId], $window);
                        if ($passage !== null) {
                            $questionPassage[$fileId] = $passage;
                        }
                    }
                }
            }
        }
        // Observation only: per file, the vote groups that admitted no passage of it
        // and voted for their first-ranked chunk.
        foreach ($votesBelowFloor as $fileId => $groups) {
            $trace['votes_below_floor'][$fileId] = array_keys($groups);
        }

        $allFiles = [];
        foreach ($perFileResults as $fileId => $results) {
            $rankings = $angleChunkScores[$fileId] ?? [];
            // BRANCH COHERENCE: passages of a branch of the document that only
            // looks like the one the question is about are not candidates at all
            // (the evidence floor then applies inside the kept branch).
            $results = $this->keepOneBranch($fileId, $results, $angleBallots[$fileId] ?? [], $rankings, $questionPassage, $trace);
            $overlap = [];
            $allFiles[$fileId] = $this->mergeFileAngleResults(
                $results,
                $maxChunksPerFile,
                $this->fuseAngleChunkRanks($rankings),
                $questionPassage[$fileId] ?? null,
                $previouslyCited[(string) $fileId] ?? [],
                $angleBallots[$fileId] ?? [],
                $overlap
            );
            // Observation only: which passages were joined, what took a freed slot.
            if ($overlap !== []) {
                $trace['passage_overlap'][$fileId] = $overlap;
            }
            // Which angles stand behind each passage (the user's own wording, or only a
            // rewrite of it): consumers that must not build on drift read this.
            $allFiles[$fileId] = $this->withAngleSupport($allFiles[$fileId], $angleBallots[$fileId] ?? []);
        }
        if ($questionPassage !== []) {
            $trace['question_passages'] = $questionPassage;
        }

        // Recall safety net: no angle cleared the confident relevance threshold, so the
        // multi-angle merge is empty. Rather than dead-end into a generic, ungrounded
        // answer, retry the SAME angles once at a lower floor and admit the strongest
        // borderline document(s). This fires ONLY when the result would otherwise be
        // empty (so it can never dilute a confident retrieval); the response layer still
        // grounds or abstains, and a borderline-but-on-topic source is strictly more
        // useful to ground on than nothing. Disabled when the floor is not below the
        // confident threshold.
        if ($mapTurn && empty($allFiles)) {
            // No confident passage on the plain angles: not a map turn. Today's path —
            // its probes, then its recall net — decides what this turn finds.
            return $this->withoutDocumentMap($ctx, $question, $options, $trace, 'no_files');
        }

        if (empty($allFiles) && $anyAngleSucceeded) {
            $confident = (float) config('ai.file_context.min_relevance_score', 0.4);
            $floor = (float) config('ai.file_context.recall_floor', 0.3);
            $cap = max(1, (int) config('ai.file_context.recall_limit', 2));

            if ($floor > 0.0 && $floor < $confident) {
                $trace['recall_floor'] = $floor;
                $recallPerFile = [];
                foreach ($queries as $searchQuery) {
                    $fileContext = $this->retrieveFileContext($searchQuery, $user, [
                        'min_score' => $floor,
                        'limit' => $cap,
                    ] + $angleOptions($searchQuery) + $turnMemo);
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
                    uasort($allFiles, fn($a, $b) => \Condoedge\Ai\Services\Retrieval\FileReferenceOrder::compare($a[0], $b[0]));
                    $allFiles = array_slice($allFiles, 0, $cap, true);
                }
            }
        }

        if (!empty($allFiles)) {
            // PREAMBLE (Phase 7.1b): a file whose every reference is a title / TOC /
            // heading-only chunk is kept only when no file matched with content.
            $withContent = array_filter($allFiles, static fn (array $refs) => empty($refs[0]['is_preamble']));
            if ($withContent !== []) {
                $allFiles = $withContent;
            }

            // Flatten the per-file reference lists, rank across files AND chunks,
            // and bound the final list by max_references overall so multi-chunk
            // grounding never inflates the prompt beyond the configured budget.
            // Same rule as FileContextProvider: relevance DESC, priority_score only
            // as a tiebreak. Exact-article matches (the user named the article) lead, like a
            // named file would.
            $maxReferences = max(1, (int) config('ai.file_context.max_references', 5));
            // PASSAGE SELECTION: what the search selected stays; one small call may
            // name up to max_added candidates it ranked too low that a correct answer
            // still needs (see withSelectedPassages()).
            $analysis = null;
            if ($mapTurn) {
                // DOCUMENT MAP TURN: the reader reads the question against the map of
                // the admitted documents and names the passages a complete answer
                // relies on. GATE: only when a governing pick is NOT among these
                // passages do the picks lead and the answer get its analysis block;
                // otherwise the passages are exactly the plain search's (measured:
                // an analysis on a question the search already answered made the
                // composer build claims on neighbouring rules).
                $files = $this->rankReferencesWithinBudget($allFiles, $maxReferences, true);

                // A FOLLOW-UP Query Understanding did not call one ("tell me more
                // about X" stands on its own as a sentence): the passage the question's
                // OWN wording ranks first is ground the previous answer covered.
                // Decided here, before the reader is paid for.
                if ($this->questionReturnsToCoveredGround($ctx, $files, $questionPassage)) {
                    return $this->withoutDocumentMap($ctx, $question, $options, $trace, 'follow_up');
                }

                // WHOSE DOCUMENT each passage is must be known BEFORE the reading: the
                // map says it per document, and picks of one role put a record's own
                // document before a general one. (The same labels are set again below,
                // on the final list — from the turn's memo, at no second lookup.)
                $unusedRoots = [];
                $unusedTrace = [];
                $labelled = $this->withScopeLabels($files, $scope, $ctx, $options, $unusedRoots, $unusedTrace, $fanOutMemo);

                $mapped = $this->documentMapTurn->apply($question, (string) $ctx->qu->clarifiedQuestion, $user, $labelled, [
                    // Every file the search admitted — not only those the reference
                    // budget kept: the reader runs only when all of them are mapped.
                    'admitted_files' => array_keys($allFiles),
                    'turn_memo' => $turnMemo[FileContextProvider::TURN_MEMO],
                    'angle_options' => $angleOptions,
                    'question_passages' => $questionPassage,
                    'observer' => $ctx->observer,
                ]);
                if ($mapped === null) {
                    return $this->withoutDocumentMap(
                        $ctx,
                        $question,
                        $options,
                        $trace,
                        (string) ($this->documentMapTurn->lastTrace()['fallback'] ?? 'not_applicable')
                    );
                }
                // OPEN means APPLIED: the picks lead and the composer gets the block —
                // never one without the other, so the trace cannot claim an open gate
                // that changed nothing.
                $trace['document_map'] = $mapped['trace'] ?? [];
                $open = is_array($mapped['analysis'] ?? null);
                $analysis = $open ? $mapped['analysis'] : null;
                $files = \Condoedge\Ai\Services\Retrieval\DocumentMapTurn::withoutStructure($open ? $mapped['files'] : $files);
            } else {
                $selects = $this->selectsPassages($ctx, $trace);
                $files = $this->rankReferencesWithinBudget($allFiles, $maxReferences, $selects);
                if ($selects) {
                    $files = $this->withSelectedPassages($ctx, $question, $user, $angles, $files, $angleOptions, $turnMemo, $previouslyCited, $trace);
                }
            }

            // WHOSE DOCUMENT each uploaded passage is, in words (never an id) — and,
            // with an unknown scope, the records the uploaded passages belong to.
            $scopeRoots = [];
            $files = $this->withScopeLabels($files, $scope, $ctx, $options, $scopeRoots, $trace, $fanOutMemo);

            $ctx->fileContext = ($analysis !== null ? ['analysis' => $analysis] : []) + ($scopeRoots !== [] ? ['scope_roots' => $scopeRoots] : [])
                // The language the citations' scope line is said in (ResponseFileEnricher).
                + ($ctx->qu->language !== '' ? ['scope_locale' => $ctx->qu->language] : []) + [
                'relevant_files' => $files,
                'file_count' => count($files),
                'has_physical' => collect($files)->contains('source', 'physical'),
                'has_database' => collect($files)->contains(fn($f) => ($f['source'] ?? '') !== 'physical'),
            ];

            // Observation only: the deepening contract the knowledge prompt will
            // state for these passages (FileContextSection computes the same one,
            // and states none beside a question analysis).
            $deepening = $analysis !== null ? null : \Condoedge\Ai\Services\Retrieval\DeepeningContract::between(
                (array) ($ctx->conversationContext['last_referenced_files'] ?? []),
                $files,
                $ctx->qu->followUpReference
            );
            if ($deepening !== null) {
                $trace['deepening'] = $deepening;
            }
        } elseif ($scope->isKnown()) {
            // Nothing at all was found — and the search of the uploads was not
            // widened beyond the question's record to find something.
            $trace['scope'] = $scope->toArray() + ['uploaded_passages' => 0, 'uploads' => 'none_in_scope'];
        }

        return $ctx;
    }

    /**
     * Whether this turn is PLANNED as a document-map turn (decided before any search,
     * so that no probe call is paid for it): the reader is wired and both switches
     * are on, the turn is a document question of its own — not a file listing, not
     * an ask the application answers (QU navigation_target), not a turn Query
     * Understanding failed on — and at least one stored chunk carries a gist at all.
     *
     * DECIDED PER TURN, never once per conversation. A FOLLOW-UP keeps probes +
     * selection (measured 2026-10-08: the reader does not name the article a
     * deepening answer needs). A turn is a follow-up when:
     *  - Query Understanding judged it one (follow_up_reference) — decided here; or
     *  - the question returns to ground the previous answer covered — known only
     *    after the search: {@see self::questionReturnsToCoveredGround()}.
     * A NEW question asked later in a conversation is neither: that an earlier
     * answer cited passages says nothing about this one, and it is read like a
     * first question.
     *
     * Whether the files this turn ADMITS are mapped is also only known after the
     * search: {@see self::withoutDocumentMap()}.
     */
    private function mapTurnPlanned(PipelineContext $ctx, bool $isFileListing, array $options): bool
    {
        if ($this->documentMapTurn === null
            || $this->fileContextProvider === null
            || !empty($options['_no_document_map'])
            || !config('ai.file_context.document_map.enabled', true)
            || !config('ai.file_context.document_map.reader.enabled', true)
            || $isFileListing
            || $ctx->qu->failed
            || $ctx->qu->followUpReference
            || trim((string) $ctx->qu->navigationTarget) !== '') {
            return false;
        }

        return $this->anyDocumentMapStored();
    }

    /**
     * Whether the QUESTION returns to ground the previous answer covered: the
     * passage its own wording ranks first ($questionPassage, the evidence floor of
     * the merge) is a chunk the previous answer cited, or defines an article it
     * cited ({@see \Condoedge\Ai\Services\Retrieval\DeepeningContract::coveredGround()}).
     *
     * Why the question's first-ranked passage and not "some passage of the turn"
     * (the deepening contract's own test). Measured live 2026-10-07, both after an
     * overview of the user's rights and duties:
     *  - "Tell me more about my duty to pay the annual fees" — Query
     *    Understanding does not call it a follow-up (2 of 2); its own wording ranks
     *    first the article on contributions, which the overview had cited;
     *  - "Can I cancel an order after it has shipped?" — a new
     *    question; its own wording ranks first the article on that, which the
     *    overview had not cited, while another of its passages overlaps what the
     *    overview cited. Judged on that overlap, the benchmark question lost its
     *    reader on every later turn of a conversation.
     * Structural: chunk indexes and article numbers only.
     *
     * @param list<array> $files This turn's passages, in prompt order.
     * @param array<int|string, int> $questionPassage File id => the chunk the question ranks first there.
     */
    private function questionReturnsToCoveredGround(PipelineContext $ctx, array $files, array $questionPassage): bool
    {
        $previous = (array) ($ctx->conversationContext['last_referenced_files'] ?? []);
        if ($previous === [] || $questionPassage === []) {
            return false;
        }

        foreach ($files as $file) {
            $fileId = $file['file_id'] ?? null;
            if (!is_scalar($fileId) || !is_numeric($file['chunk_index'] ?? null)
                || !is_numeric($questionPassage[$fileId] ?? null)
                || (int) $questionPassage[$fileId] !== (int) $file['chunk_index']) {
                continue;
            }

            // The first such passage of the list is the question's own.
            return \Condoedge\Ai\Services\Retrieval\DeepeningContract::coveredGround($previous, $file);
        }

        return false;
    }

    /**
     * Whether any chunk of the document collections carries a GIST (one filtered
     * count per collection, remembered for a minute): a host that never built a map
     * pays nothing and keeps today's path on every turn. A chunk that only records
     * a failed attempt is not a map. False on any failure. The map builder forgets
     * the remembered answer after a run that wrote a gist
     * ({@see \Condoedge\Ai\Support\DocumentMap::ANY_STORED_CACHE_KEY}).
     */
    private function anyDocumentMapStored(): bool
    {
        try {
            return (bool) \Illuminate\Support\Facades\Cache::remember(\Condoedge\Ai\Support\DocumentMap::ANY_STORED_CACHE_KEY, 60, function (): bool {
                $collections = array_unique(array_filter([
                    (string) config('ai.file_context.physical_collection', 'documentation_chunks'),
                    (string) config('ai.file_processing.collection', 'file_chunks'),
                ]));
                foreach ($collections as $collection) {
                    if ($this->vectorStore->collectionExists($collection)
                        && $this->vectorStore->count($collection, ['must_not' => [['is_empty' => ['key' => 'map.gist']]]]) > 0) {
                        return true;
                    }
                }

                return false;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The turn was planned as a document-map turn and is not one after all ($reason:
     * no confident file, no admitted file with a usable map, a failed reader, a turn
     * that returns to covered ground…): run the fan-out again exactly as it runs
     * without the feature — probes written, passage selection on.
     *
     * NOT A SECOND SEARCH of what the first pass found: $options carries the
     * first pass's memo (`_fan_out_memo`: every angle's result, the rankings, the
     * files' chunks), so this pass pays for the probe call and the probe angles
     * only, and gives exactly what the turn gives on a host without the feature.
     * $trace starts over and keeps the reason.
     */
    /**
     * WHOSE DOCUMENT each passage is — and what the trace says about the scope.
     *
     * Each UPLOADED passage carries the key of the record its file is filed on
     * (`owner`) and of the records that own it (`ancestors`). Keys are never
     * shown: each distinct one is turned into the record's kind and name by code
     * ({@see \Condoedge\Ai\Services\Retrieval\DocumentOwnerLabels}: one scoped
     * lookup per key, the turn's own authority) and the passage gets its
     * `scope_label` — {kind, name} of its owner and, when it has one, of its
     * least specific ancestor (`root_kind`, `root_name`: the customer of an order).
     * A project passage gets nothing: the prompt marks it general by its source.
     *
     * UNKNOWN SCOPE: when the uploaded passages belong to MORE THAN ONE top-level
     * record, those records are handed to the prompt ($roots) — the answer keeps
     * them apart instead of merging two customers' rules into one.
     *
     * Trace (observation only): how many uploaded passages the turn shows; that
     * a KNOWN scope found none (`uploads: none_in_scope` — the search was not
     * widened to another record's documents); the top-level records of an
     * unknown scope; the passages that stand for several copies.
     *
     * @param array<int, mixed> $files
     * @param list<array{kind: string, name: string}> $roots filled for an unknown scope over several records
     * @return array<int, mixed>
     */
    private function withScopeLabels(
        array $files,
        \Condoedge\Ai\Services\Retrieval\DocumentScope $scope,
        PipelineContext $ctx,
        array $options,
        array &$roots,
        array &$trace,
        ?\ArrayObject $memo = null
    ): array {
        $keys = [];
        $rootKeys = [];
        $uploaded = 0;
        $copies = [];
        foreach ($files as $file) {
            if (!is_array($file) || ($file['source'] ?? null) === 'physical' || !empty($file['listing_only'])) {
                continue;
            }
            $uploaded++;
            if ((int) ($file['duplicates'] ?? 0) > 0) {
                $copies[] = ['file' => $file['file_id'] ?? null, 'chunk_index' => $file['chunk_index'] ?? null, 'duplicates' => (int) $file['duplicates']];
            }
            $owner = is_string($file['owner'] ?? null) && $file['owner'] !== '' ? $file['owner'] : null;
            if ($owner === null) {
                continue;
            }
            // Its own record, and the other records the same text is filed on.
            foreach (array_merge([['owner' => $owner, 'ancestors' => $file['ancestors'] ?? []]], (array) ($file['co_owners'] ?? [])) as $link) {
                $linked = is_array($link) && is_string($link['owner'] ?? null) && $link['owner'] !== '' ? $link['owner'] : null;
                if ($linked === null) {
                    continue;
                }
                $root = \Condoedge\Ai\Support\FileLink::root(['owner' => $linked, 'ancestors' => $link['ancestors'] ?? []]);
                $keys[$linked] = true;
                if ($root !== null) {
                    $keys[$root] = true;
                    $rootKeys[$root] = true;
                }
            }
        }

        $trace['scope'] = (is_array($trace['scope'] ?? null) ? $trace['scope'] : $scope->toArray()) + ['uploaded_passages' => $uploaded];
        if ($scope->isKnown() && $uploaded === 0) {
            $trace['scope']['uploads'] = 'none_in_scope';
        }
        if (!$scope->isKnown() && $rootKeys !== []) {
            $trace['scope']['roots'] = array_map('strval', array_keys($rootKeys));
        }
        if ($copies !== []) {
            $trace['duplicates'] = $copies;
        }

        if ($keys === [] || $this->documentOwnerLabels === null) {
            return $files;
        }

        // One lookup per record and turn ($memo: the fan-out's): the map turn asks
        // for the labels before its reading, the final list after it.
        $locale = $ctx->qu->language !== '' ? $ctx->qu->language : null;
        $known = $memo !== null && is_array($memo['owner_labels'] ?? null) ? $memo['owner_labels'] : [];
        $missing = array_values(array_diff(array_map('strval', array_keys($keys)), array_keys($known)));
        if ($missing !== []) {
            $found = $this->documentOwnerLabels->labels($missing, $options, $locale);
            foreach ($missing as $key) {
                $known[$key] = $found[$key] ?? null; // null: looked up, cannot be named
            }
            if ($memo !== null) {
                $memo['owner_labels'] = $known;
            }
        }
        $labels = array_filter(array_intersect_key($known, $keys));

        foreach ($files as $position => $file) {
            $owner = is_array($file) && ($file['source'] ?? null) !== 'physical' ? ($file['owner'] ?? null) : null;
            if (!is_string($owner) || !isset($labels[$owner])) {
                continue;
            }
            $label = $labels[$owner];
            $root = \Condoedge\Ai\Support\FileLink::root(['owner' => $owner, 'ancestors' => $file['ancestors'] ?? []]);
            if ($root !== null && $root !== $owner && isset($labels[$root])) {
                $label += ['root_kind' => $labels[$root]['kind'], 'root_name' => $labels[$root]['name']];
            }
            // The other records the same text is filed on, each by name.
            $also = [];
            foreach ((array) ($file['co_owners'] ?? []) as $link) {
                $other = is_array($link) ? ($link['owner'] ?? null) : null;
                if (is_string($other) && isset($labels[$other])) {
                    $also[] = $labels[$other];
                }
            }
            if ($also !== []) {
                $label['also'] = $also;
            }
            $files[$position]['scope_label'] = $label;
        }

        if (!$scope->isKnown() && count($rootKeys) > 1) {
            foreach (array_keys($rootKeys) as $root) {
                if (isset($labels[$root])) {
                    $roots[] = $labels[$root];
                }
            }
            // Every record named, or none: a partial list would misstate the mix.
            if (count($roots) !== count($rootKeys)) {
                $roots = [];
            }
        }

        return $files;
    }

    private function withoutDocumentMap(PipelineContext $ctx, string $question, array $options, array &$trace, string $reason): PipelineContext
    {
        $trace = ['document_map' => ['fallback' => $reason]];

        return $this->fanOutFileContext($ctx, $question, ['_no_document_map' => true] + $options, $trace);
    }

    /**
     * Embed every fan-out angle in ONE provider call instead of one round-trip per
     * angle (measured live 2026-10-06: ~0.6-2 s per angle, sequential — most of the
     * file_context stage). The angles are all known before the first search; the
     * request-scoped embedding memo then serves each angle's search from memory.
     *
     * Only when the injected provider IS that memo — with any other provider
     * nothing would reuse the batch and it would only add a round-trip. Best
     * effort: on any failure each angle embeds itself as before. (The follow-up
     * angle FileContextProvider prefixes with the conversation focus is a
     * different text and simply embeds on its own.)
     *
     * The vectors come back (one per angle, same order) because they also tell
     * which probes merely restate another angle (withoutDuplicateProbes()).
     *
     * @param list<string> $angles
     * @return list<list<float>> One embedding per angle; [] when not batched or the batch failed.
     */
    private function embedAnglesInOneBatch(array $angles): array
    {
        if (count($angles) < 2
            || $this->fileContextProvider === null
            || !$this->embedding instanceof \Condoedge\Ai\EmbeddingProviders\MemoizedEmbeddingProvider) {
            return [];
        }

        try {
            $vectors = array_values($this->embedding->embedBatch($angles));

            return count($vectors) === count($angles) ? $vectors : [];
        } catch (\Throwable) {
            // Each angle's own search embeds (and reports) as it always did.
            return [];
        }
    }

    /**
     * The turn's document-search angles, in search order, each with its VOTE GROUP.
     *
     * The cross-angle fusion (fuseAngleChunkRanks()) counts one vote per GROUP, not
     * per angle, so that the probes ADD breadth without outvoting the evidence
     * (eval p16: four probes stating one hypothesis were four of six votes and
     * evicted the passage the plain wording found):
     *  - QU's subject rewrite, the user's own wording and QU's clarified question
     *    are one group each — always searched;
     *  - the probes are grouped by SLOT: the n-th probe of every corpus language is
     *    the same line of attack written in each language, so those renderings are
     *    one vote together;
     *  - QU's joined tags search only when no probe was written this turn
     *    (unconfigured host, skipped or failed probe call) — the probes are that
     *    keyword paraphrase done in the documents' wording;
     *  - the page context is one more soft-prior angle.
     *
     * An angle text appears once (its first group; the user's verbatim wording
     * always keeps the question group).
     *
     * @param array<string, list<string>> $probes This turn's written probes (language => probes).
     * @return list<array{text: string, group: string, probe: bool}>
     */
    private function searchAngles(PipelineContext $ctx, string $question, array $probes, string $pageAngle): array
    {
        $angles = [];
        $index = [];
        $add = static function (mixed $text, string $group, bool $probe = false) use (&$angles, &$index): void {
            if (!is_string($text) || trim($text) === '' || $text === '0') {
                return;
            }
            $key = md5($text);
            if (isset($index[$key])) {
                if ($group === self::ANGLE_QUESTION) {
                    $angles[$index[$key]]['group'] = $group;
                }

                return;
            }
            $index[$key] = count($angles);
            $angles[] = ['text' => $text, 'group' => $group, 'probe' => $probe];
        };

        $add($ctx->qu->searchQuery, self::ANGLE_SEARCH_QUERY);
        $add($question, self::ANGLE_QUESTION);
        $add($ctx->qu->clarifiedQuestion, self::ANGLE_CLARIFIED);
        if ($probes === [] && !empty($ctx->qu->searchTags)) {
            $add(implode(' ', $ctx->qu->searchTags), self::ANGLE_TAGS);
        }

        foreach ($this->probeSlots($ctx, $probes) as $number => $renderings) {
            foreach ($renderings as $rendering) {
                $add($rendering, self::ANGLE_PROBE_PREFIX . ($number + 1), true);
            }
        }

        // Page context (soft prior): the record's label + visible filter values boost
        // recall for the page the chat was opened from WITHOUT excluding any other record.
        $add($pageAngle, self::ANGLE_PAGE);

        return $angles;
    }

    /**
     * The probes by SLOT: slot n holds the n-th probe of every corpus language
     * (the same line of attack, once per language).
     *
     * From this turn's probe call when it wrote any. Probes that reached the QU
     * result some other way keep the one grouping their two fields carry: the
     * per-language probes are one slot, every further probe a slot of its own.
     *
     * @param array<string, list<string>> $probes
     * @return list<list<string>>
     */
    private function probeSlots(PipelineContext $ctx, array $probes): array
    {
        $slots = [];
        if ($probes !== []) {
            foreach ($probes as $languageProbes) {
                foreach (array_values((array) $languageProbes) as $number => $probe) {
                    $slots[$number][] = $probe;
                }
            }
            ksort($slots);

            return array_values($slots);
        }

        if ($ctx->qu->corpusTranslations !== []) {
            $slots[] = array_values($ctx->qu->corpusTranslations);
        }
        foreach ($ctx->qu->registerExpansions as $expansion) {
            $slots[] = [$expansion];
        }

        return $slots;
    }

    /**
     * Drop the probe angles that only restate another angle, judged by the cosine
     * similarity of the angle embeddings (already computed in one batch) against
     * ai.file_context.probe_duplicate_similarity — structural, no vocabulary:
     *  - a probe SLOT that nearly duplicates an earlier kept slot (any pair of
     *    their renderings) is dropped whole: it is the same line of attack again;
     *  - a probe RENDERING that nearly duplicates the user's question or its
     *    clarified form is dropped: that search already runs. Its other-language
     *    renderings stay — they are what reaches documents in those languages.
     *
     * A dropped probe is not searched, so it costs no search and casts no vote.
     * Nothing is dropped without embeddings (provider without the request memo,
     * failed batch) or when the threshold is outside (0, 1].
     *
     * @param list<array{text: string, group: string, probe: bool}> $angles
     * @param list<list<float>> $vectors One embedding per angle (same order), or [].
     * @return list<array{text: string, group: string, probe: bool}>
     */
    private function withoutDuplicateProbes(array $angles, array $vectors, array &$trace): array
    {
        $threshold = (float) config('ai.file_context.probe_duplicate_similarity', 0.8);
        if ($vectors === [] || count($vectors) !== count($angles) || $threshold <= 0.0 || $threshold > 1.0) {
            return $angles;
        }

        $slots = [];
        $asked = [];
        foreach ($angles as $i => $angle) {
            if ($angle['probe']) {
                $slots[$angle['group']][] = $i;
            } elseif (in_array($angle['group'], [self::ANGLE_QUESTION, self::ANGLE_CLARIFIED], true)) {
                $asked[] = $i;
            }
        }
        if ($slots === []) {
            return $angles;
        }

        $closest = function (array $these, array $those) use ($vectors): float {
            $max = 0.0;
            foreach ($these as $i) {
                foreach ($those as $j) {
                    $max = max($max, $this->cosineSimilarity($vectors[$i], $vectors[$j]));
                }
            }

            return $max;
        };

        $dropped = [];
        $kept = [];
        foreach ($slots as $indexes) {
            $similarity = $closest($indexes, $kept);
            if ($similarity >= $threshold) {
                foreach ($indexes as $i) {
                    $dropped[$i] = ['duplicate_of' => 'probe', 'similarity' => $similarity];
                }
                continue;
            }
            $kept = [...$kept, ...$indexes];
        }
        foreach ($kept as $i) {
            $similarity = $closest([$i], $asked);
            if ($similarity >= $threshold) {
                $dropped[$i] = ['duplicate_of' => 'question', 'similarity' => $similarity];
            }
        }

        if ($dropped === []) {
            return $angles;
        }

        ksort($dropped);
        foreach ($dropped as $i => $why) {
            $trace['probes_dropped'][] = [
                'angle' => $angles[$i]['text'],
                'duplicate_of' => $why['duplicate_of'],
                'similarity' => round($why['similarity'], 3),
            ];
        }

        return array_values(array_diff_key($angles, $dropped));
    }

    /**
     * Cosine similarity of two embeddings (0.0 when either is empty or zero).
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $length = min(count($a), count($b));
        for ($i = 0; $i < $length; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }

        return $normA > 0.0 && $normB > 0.0 ? $dot / sqrt($normA * $normB) : 0.0;
    }

    /**
     * Write the turn's document-search probes (ONE small focused LLM call —
     * DocumentProbeWriter) and put them on the QU result's two probe slots; the
     * fan-out turns them into search angles (searchAngles()).
     *
     * Called only once document search is certain to run. No call at all when the
     * host configured neither corpus languages nor a register, on a file-listing
     * turn (a request for the LIST of files has no passage to probe for), when
     * QU itself failed (the same provider would be asked again behind the same
     * outage), or when QU judged the turn an ask the APPLICATION answers — its
     * navigation_target is set (eval p16-B: an app how-to rewritten in the corpus
     * register became law-style probes, and three of five passages were the law).
     * The plain angles search such a turn, as they did before probes existed.
     * The question goes in verbatim together with QU's context-resolved form, so
     * a follow-up is probed for what it is about.
     *
     * Never throws and never blocks the turn: a failed call leaves the QU result
     * untouched and the search runs on its other angles. $trace records the
     * outcome for the file_context stage event (observation only).
     *
     * @return array<string, list<string>> The probes written this turn (language
     *         => probes), also put on $ctx->qu; [] when none.
     */
    private function writeSearchProbes(PipelineContext $ctx, string $question, bool $isFileListing, array &$trace): array
    {
        if ($this->probeWriter === null
            || $this->fileContextProvider === null
            || $isFileListing
            || $ctx->qu->failed
            || !$this->probeWriter->isConfigured()) {
            return [];
        }

        if (trim((string) $ctx->qu->navigationTarget) !== '') {
            $trace['search_probes'] = ['skipped' => 'navigation_target'];

            return [];
        }

        $start = hrtime(true);
        $standalone = trim($ctx->qu->clarifiedQuestion) !== '' ? $ctx->qu->clarifiedQuestion : $ctx->qu->searchQuery;
        $probes = $this->probeWriter->write($question, $standalone, $ctx->qu->language, $ctx->observer);

        $trace['search_probes'] = [
            'count' => array_sum(array_map('count', $probes)),
            'ms' => $this->elapsedMs($start),
        ];

        if ($probes === []) {
            return [];
        }

        $ctx->qu = $ctx->qu->withDocumentProbes($probes);

        return $probes;
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
    private function shouldSkipFileSearchFor(PipelineContext $ctx, mixed $user): bool
    {
        // Docs only: the documents are every turn's only source.
        if (!$this->queryGenerationEnabled($user)) {
            return false;
        }

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
     * Whether this turn's passages go through the passage selection: a selector is
     * wired and enabled (ai.file_context.passage_selection), the turn's passages
     * come from a confident search (not the recall-floor rescue, whose borderline
     * documents are not a selection to build on), Query Understanding worked, and
     * the ask is not one the APPLICATION answers (QU navigation_target — a how-to
     * turn keeps its how-to document and gets no law passage added). File-listing
     * and data-only turns never reach this point.
     */
    private function selectsPassages(PipelineContext $ctx, array $trace): bool
    {
        return $this->passageSelector !== null
            && $this->fileContextProvider !== null
            && (bool) config('ai.file_context.passage_selection.enabled', true)
            && (int) config('ai.file_context.passage_selection.max_added', 1) >= 1
            && !$ctx->qu->failed
            && trim((string) $ctx->qu->navigationTarget) === ''
            && !isset($trace['recall_floor']);
    }

    /**
     * PASSAGE SELECTION (eval p16–p21 tear-down): add the passages a correct answer
     * needs that the search could not admit.
     *
     * The search admits a passage on cosine similarity. The article stating the
     * general rule a specific question falls under can sit far from every phrasing
     * of that question (0.28 for the question, rank 7–75 for its rewrites and
     * probes) — an inference gap no floor, vote or re-embedding closed. So after
     * the passages are chosen, ONE small call ({@see PassageSelector}) reads the
     * question, those passages and a pool of candidates ranked too low, and names
     * the candidates still needed. The model reports; this code verifies and decides:
     *
     *  - WHAT IS SELECTED STAYS, in its order: consensus, the evidence floor, branch
     *    coherence and the budget are untouched. The call can only add (asked to
     *    choose the whole set, the model took the wrong part of the document on a
     *    broad question and dropped passages the answer needed — measured).
     *  - THE POOL: the chunks the user's own wording and QU's rewrites of it rank
     *    best without the relevance floor (reciprocal-rank fusion, one vote per
     *    group; the probes are left out — they are what selected the passages),
     *    minus every chunk a selected passage already holds, at most pool_size.
     *    A candidate must lie in a selected file, inside the smallest section of it
     *    that contains all its selected passages (an addition never opens another
     *    part of the document than the search settled on), and on a follow-up turn
     *    must not be a passage the previous answer cited (new material only).
     *  - THE ADDITIONS: at most max_added, each a candidate of the pool, each once.
     *    The addition is the one chunk the model named (not its window). A chunk
     *    right before or after a chunk of a selected passage is joined to that
     *    passage, in reading order; another becomes a passage after the selected
     *    ones — beyond max_references by at most max_added.
     *
     * No call when the pool holds no more candidates than may be added (nothing to
     * choose between). Any failure — no pool, provider error, unusable reply —
     * leaves the selected passages as they are. $trace['passage_selection'] records
     * the pool, the picks, what was added and how, and the call's tokens.
     *
     * @param list<array{text: string, group: string, probe: bool}> $angles
     * @param list<array> $files The selected passages, with `heading_path` and `snippet_chunks`.
     * @param array<int|string, list<int>> $previouslyCited File id => chunks the previous answer cited.
     * @return list<array> The passages for the prompt (structure keys stripped).
     */
    private function withSelectedPassages(
        PipelineContext $ctx,
        string $question,
        mixed $user,
        array $angles,
        array $files,
        \Closure $angleOptions,
        array $turnMemo,
        array $previouslyCited,
        array &$trace
    ): array {
        $strip = static function (array $files): array {
            foreach ($files as &$file) {
                unset($file['heading_path'], $file['snippet_chunks']);
            }

            return array_values($files);
        };

        $maxAdded = max(1, (int) config('ai.file_context.passage_selection.max_added', 1));
        $poolSize = max(1, (int) config('ai.file_context.passage_selection.pool_size', 20));
        $window = max(0, (int) config('ai.file_context.context_window', 2));
        $report = ['pool' => 0];
        $start = hrtime(true);

        try {
            // What the selected passages hold, and the section of each file they span.
            $covered = [];
            $section = [];
            $sources = [];
            foreach ($files as $file) {
                $fileId = $file['file_id'] ?? $file['filename'] ?? '';
                $sources[$fileId] ??= basename((string) ($file['filename'] ?? $fileId));
                $chunks = $this->snippetChunksOf($file);
                $indexes = $chunks !== null
                    ? array_keys($chunks)
                    : (isset($file['chunk_index']) ? range(max(0, (int) $file['chunk_index'] - $window), (int) $file['chunk_index'] + $window) : []);
                foreach ($indexes as $index) {
                    $covered[$fileId][(int) $index] = true;
                }
                $path = is_array($file['heading_path'] ?? null) ? array_values($file['heading_path']) : [];
                if (!array_key_exists($fileId, $section)) {
                    $section[$fileId] = $path;
                    continue;
                }
                $common = [];
                foreach ($section[$fileId] as $level => $heading) {
                    if (($path[$level] ?? null) !== $heading) {
                        break;
                    }
                    $common[] = $heading;
                }
                $section[$fileId] = $common;
            }

            // The pool: floor-less rankings of the question and QU's rewrites, fused.
            $followUp = $ctx->qu->followUpReference;
            $k = max(1, (int) config('ai.file_context.angle_fusion_k', 60));
            $bestRanks = [];
            $pool = [];
            foreach ($angles as $angle) {
                if ($angle['probe']) {
                    continue;
                }
                $ranked = $this->fileContextProvider->rankedChunks($angle['text'], $user, $angleOptions($angle['text']) + $turnMemo);
                $flat = [];
                foreach ($ranked as $fileId => $entry) {
                    foreach ((array) ($entry['chunks'] ?? []) as $index => $chunk) {
                        $key = $fileId . "\u{1F}" . $index;
                        $flat[$key] = (float) ($chunk['score'] ?? 0.0);
                        $pool[$key] ??= ['file_id' => $fileId, 'chunk_index' => (int) $index, 'result' => $entry['result'] ?? []] + $chunk;
                    }
                }
                arsort($flat);
                $rank = 0;
                foreach ($flat as $key => $score) {
                    $rank++;
                    $bestRanks[$angle['group']][$key] = min($bestRanks[$angle['group']][$key] ?? PHP_INT_MAX, $rank);
                }
            }
            $fused = [];
            foreach ($bestRanks as $ranks) {
                foreach ($ranks as $key => $rank) {
                    $fused[$key] = ($fused[$key] ?? 0.0) + 1.0 / ($k + $rank);
                }
            }
            arsort($fused);

            $candidates = [];
            foreach (array_keys($fused) as $key) {
                $candidate = $pool[$key];
                $fileId = $candidate['file_id'];
                $index = $candidate['chunk_index'];
                $path = array_values((array) ($candidate['heading_path'] ?? []));
                if (!isset($covered[$fileId])
                    || isset($covered[$fileId][$index])
                    || trim((string) ($candidate['content'] ?? '')) === ''
                    || array_slice($path, 0, count($section[$fileId] ?? [])) !== ($section[$fileId] ?? [])
                    || ($followUp && in_array($index, $previouslyCited[(string) $fileId] ?? [], true))) {
                    continue;
                }
                $candidates[] = $candidate;
                if (count($candidates) >= $poolSize) {
                    break;
                }
            }
            $report['pool'] = count($candidates);
            if (count($candidates) <= $maxAdded) {
                $report['skipped'] = 'small_pool';
                $trace['passage_selection'] = $report;

                return $strip($files);
            }

            // Shown in reading order: neighbours stay together and the order does not
            // depend on which rewrite ranked what.
            $fileOrder = array_flip(array_keys($covered));
            usort($candidates, static fn (array $a, array $b): int
                => [$fileOrder[$a['file_id']] ?? 0, $a['chunk_index']] <=> [$fileOrder[$b['file_id']] ?? 0, $b['chunk_index']]);
            $report['candidates'] = array_map(
                static fn (array $c): array => ['file' => $c['file_id'], 'chunk_index' => $c['chunk_index']],
                $candidates
            );

            $selection = $this->passageSelector->select(
                $question,
                (string) $ctx->qu->clarifiedQuestion,
                array_map(fn (array $f): array => [
                    'heading' => (string) ($f['heading'] ?? ''),
                    'parts' => array_values($this->snippetChunksOf($f) ?? [(string) ($f['snippet'] ?? '')]),
                ], $files),
                array_map(static fn (array $c): array => [
                    'source' => (string) ($sources[$c['file_id']] ?? ''),
                    'heading' => (string) ($c['heading'] ?? ''),
                    'text' => (string) $c['content'],
                ], $candidates),
                $maxAdded,
                $ctx->observer
            );
            $report['ms'] = $this->elapsedMs($start);
            if ($selection === null) {
                $report['fallback'] = 'no_report';
                $trace['passage_selection'] = $report;

                return $strip($files);
            }
            $report['unknown_ids'] = $selection['unknown'];
            if (is_array($selection['usage'] ?? null)) {
                $report['tokens'] = array_intersect_key($selection['usage'], ['input' => 1, 'output' => 1, 'total' => 1]);
            }

            $report['picked'] = [];
            $report['added'] = [];
            foreach ($selection['picked'] as $position) {
                $candidate = $candidates[$position] ?? null;
                if ($candidate === null) {
                    continue;
                }
                $fileId = $candidate['file_id'];
                $index = $candidate['chunk_index'];
                $report['picked'][] = ['file' => $fileId, 'chunk_index' => $index];
                if (isset($covered[$fileId][$index])) {
                    continue; // an earlier addition already brought it in
                }

                $passage = $this->fileContextProvider->passageAt(
                    (array) $candidate['result'],
                    $index,
                    $user,
                    [FileContextProvider::PASSAGE_CHUNKS => true, 'question' => $question] + $turnMemo
                );
                $stitched = $passage !== null ? $this->snippetChunksOf($passage) : null;
                if ($passage === null || $stitched === null) {
                    continue;
                }
                // The addition is the ONE chunk the model named, not its ±window: the
                // neighbours were not judged, and a whole window per addition cost
                // 1.6–3.2k characters of prompt for one article (measured). So no
                // chunk a selected passage holds can be printed a second time either.
                if (!isset($stitched[$index])) {
                    continue;
                }
                $chunks = [$index => $stitched[$index]];

                // Joined to the selected passage it continues (the chunk right before
                // or after one of its chunks), else a passage of its own.
                $into = null;
                foreach ($files as $n => $file) {
                    $held = ($file['file_id'] ?? $file['filename'] ?? '') === $fileId ? $this->snippetChunksOf($file) : null;
                    if ($held !== null && (isset($held[$index - 1]) || isset($held[$index + 1]))) {
                        $into = $n;
                        break;
                    }
                }
                if ($into !== null) {
                    $joined = $this->snippetChunksOf($files[$into]) + $chunks;
                    ksort($joined);
                    $files[$into]['snippet_chunks'] = $joined;
                    $files[$into]['snippet'] = implode(FileContextProvider::SNIPPET_SEPARATOR, $joined);
                    $report['added'][] = ['file' => $fileId, 'chunk_index' => $index, 'as' => 'joined', 'into' => $files[$into]['chunk_index'] ?? null];
                } else {
                    unset($passage['chunk_scores']);
                    $passage['snippet_chunks'] = $chunks;
                    $passage['snippet'] = implode(FileContextProvider::SNIPPET_SEPARATOR, $chunks);
                    // No search angle stands behind it: consumers that must not build
                    // on drift (follow-up chips) read these two facts.
                    $files[] = ['angle_groups' => [], 'question_supported' => false] + $passage;
                    $report['added'][] = ['file' => $fileId, 'chunk_index' => $index, 'as' => 'passage'];
                }
                foreach (array_keys($chunks) as $held) {
                    $covered[$fileId][(int) $held] = true;
                }
            }
        } catch (\Throwable $e) {
            // Selection only adds: whatever went wrong, the selected passages stand.
            $report['fallback'] = 'error';
            PipelineObserverScope::current()->stageError('file_context', $e);
        }

        $trace['passage_selection'] = $report;

        return $strip($files);
    }

    /**
     * How one angle ranks the chunks of every file it reaches, without the relevance
     * floor (FileContextProvider::chunkRankings()): file id => chunk_index => score.
     * Empty when there is no provider or the lookup fails — the angle then simply
     * casts no vote from under the floor.
     *
     * @return array<int|string, array<int|string, float>>
     */
    private function rankFileChunks(string $angle, mixed $user, array $options): array
    {
        if ($this->fileContextProvider === null) {
            return [];
        }

        try {
            $rankings = $this->fileContextProvider->chunkRankings($angle, $user, $options);

            return is_array($rankings) ? $rankings : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The candidate passage of a file that holds the chunk one angle ranks FIRST
     * there: that chunk itself when some angle admitted it as a passage, else the
     * nearest admitted passage whose snippet window (± $window chunks) contains it;
     * null when no candidate holds it. Structural: chunk indexes only.
     *
     * @param array<int|string, float> $scores  The angle's ranking: chunk_index => similarity.
     * @param list<array>              $results The file's admitted references, from every angle.
     */
    private function candidateHoldingFirstRanked(array $scores, array $results, int $window): ?int
    {
        if ($scores === []) {
            return null;
        }
        arsort($scores);
        $first = (int) array_key_first($scores);

        $holder = null;
        foreach ($results as $reference) {
            if (!isset($reference['chunk_index']) || !empty($reference['is_preamble'])) {
                continue;
            }
            $candidate = (int) $reference['chunk_index'];
            $distance = abs($candidate - $first);
            if ($distance === 0) {
                return $candidate;
            }
            if ($distance <= $window
                && ($holder === null || $distance < abs($holder - $first) || ($distance === abs($holder - $first) && $candidate < $holder))) {
                $holder = $candidate;
            }
        }

        return $holder;
    }

    /**
     * Reciprocal-rank fusion of the angles' rankings of ONE file's chunks, one
     * vote per angle GROUP (searchAngles()): chunk_index => Σ_groups 1 / (k + the
     * chunk's BEST rank among the group's angles). A group of one angle is that
     * angle's own ranking; the language renderings of one probe slot vote
     * together, so writing a probe in two languages does not double its say.
     * Ranks are WITHIN the file (which passage of this document each phrasing
     * prefers), so — unlike the per-angle, file-level fusion_score of Task 1.29 —
     * it never compares files or angles' raw scales. k =
     * ai.file_context.angle_fusion_k (60, the standard RRF constant).
     *
     * @param list<array{group: string, scores: array<int|string,float>}> $angleRankings
     *        One chunk_index => score map per angle, with the angle's vote group.
     * @return array<int,float>
     */
    private function fuseAngleChunkRanks(array $angleRankings): array
    {
        $bestRanks = [];
        foreach ($angleRankings as $ranking) {
            $scores = $ranking['scores'];
            arsort($scores);
            $rank = 0;
            foreach ($scores as $index => $score) {
                $rank++;
                $group = $ranking['group'];
                $bestRanks[$group][(int) $index] = min($bestRanks[$group][(int) $index] ?? PHP_INT_MAX, $rank);
            }
        }

        if (count($bestRanks) < 2) {
            return []; // a single vote has no consensus to add over its own order
        }

        $k = max(1, (int) config('ai.file_context.angle_fusion_k', 60));
        $fused = [];
        foreach ($bestRanks as $ranks) {
            foreach ($ranks as $index => $rank) {
                $fused[$index] = ($fused[$index] ?? 0.0) + 1.0 / ($k + $rank);
            }
        }

        return $fused;
    }

    /**
     * BRANCH COHERENCE (eval p17-B rights t0): drop the candidate passages of a
     * branch of the document that only LOOKS like the one the question is about.
     *
     * A structured document can title a section the same way in two of its parts;
     * the plain question embeds closest to that title, so passages of both parts
     * are candidates — and the evidence floor pinned the wrong part's passage (it
     * was the question angle's best match). When the file's candidates sit in
     * different branches of its heading tree AND those branches hold a same-titled
     * section, only the branch carrying the most vote weight keeps its passages:
     * every vote group casts one vote, split between the branches the way its
     * angles' own passages are ($ballots —
     * {@see \Condoedge\Ai\Services\Retrieval\PassageBranchCoherence}; the share of
     * the vote it needs is ai.file_context.branch_coherence_share, 0 = off).
     *
     * The question angle's best passage is then re-read INSIDE the kept branch, so
     * the evidence floor can never re-admit a dropped passage. An article the user
     * named by number is never dropped: that is a match they asked for.
     *
     * Untouched: a file without heading paths, candidates of one branch, branches
     * whose section titles do not collide (a question may span two parts) and a
     * split vote.
     *
     * @param list<array> $results The file's references from every angle.
     * @param list<array{group: string, picks: list<int>}> $ballots Per angle: its vote group and
     *        its passages of the file (chunk indexes), best first.
     * @param list<array{group: string, scores: array<int|string,float>}> $angleRankings
     * @param array<int|string, int> $questionPassage File id => the question angle's best chunk (updated).
     * @return list<array> The references that stay candidates.
     */
    private function keepOneBranch(int|string $fileId, array $results, array $ballots, array $angleRankings, array &$questionPassage, array &$trace): array
    {
        $minShare = (float) config(
            'ai.file_context.branch_coherence_share',
            \Condoedge\Ai\Services\Retrieval\PassageBranchCoherence::DEFAULT_MIN_SHARE
        );
        if ($minShare <= 0.0 || $minShare > 1.0 || $ballots === []) {
            return $results;
        }

        $paths = [];
        $named = [];
        foreach ($results as $reference) {
            if (!isset($reference['chunk_index'])) {
                continue;
            }
            $index = (int) $reference['chunk_index'];
            if (!empty($reference['article_match'])) {
                $named[$index] = true;
            }
            if (is_array($reference['heading_path'] ?? null) && $reference['heading_path'] !== []) {
                $paths[$index] ??= $reference['heading_path'];
            }
        }
        if (count($paths) < 2) {
            return $results;
        }

        $verdict = \Condoedge\Ai\Services\Retrieval\PassageBranchCoherence::resolve($paths, $ballots, $minShare);
        if ($verdict === null) {
            return $results;
        }

        $dropped = array_fill_keys(array_diff($verdict['dropped'], array_keys($named)), true);
        $kept = array_values(array_filter(
            $results,
            static fn (array $reference): bool => !isset($reference['chunk_index']) || !isset($dropped[(int) $reference['chunk_index']])
        ));
        if ($dropped === [] || $kept === []) {
            return $results;
        }

        // EVIDENCE FLOOR inside the kept branch: the question angle's best passage
        // among the candidates that stay — its own next passage, else the
        // best-ranked one of its full ranking that another angle made a candidate.
        if (isset($questionPassage[$fileId], $dropped[$questionPassage[$fileId]])) {
            unset($questionPassage[$fileId]);
            $candidates = array_fill_keys(array_map('intval', array_column($kept, 'chunk_index')), true);
            $orders = [];
            foreach ($ballots as $ballot) {
                if ($ballot['group'] === self::ANGLE_QUESTION) {
                    $orders[] = $ballot['picks'];
                }
            }
            foreach ($angleRankings as $ranking) {
                if ($ranking['group'] === self::ANGLE_QUESTION) {
                    $scores = $ranking['scores'];
                    arsort($scores);
                    $orders[] = array_keys($scores);
                }
            }
            foreach ($orders as $order) {
                foreach ($order as $index) {
                    if (isset($candidates[(int) $index])) {
                        $questionPassage[$fileId] = (int) $index;
                        break 2;
                    }
                }
            }
        }

        // Observation only: which branch stayed, which went and on what vote.
        $droppedIndexes = array_keys($dropped);
        sort($droppedIndexes);
        $trace['branch_coherence'][$fileId] = ['dropped' => $droppedIndexes] + $verdict;

        return $kept;
    }

    /**
     * The final reference list: every file's passages ranked together and cut to
     * the overall budget (ai.file_context.max_references).
     *
     * Ranking is the shared rule (FileReferenceOrder: relevance
     * DESC, priority_score as a tiebreak), exact-article matches leading like a
     * named file would. That ranking decides the PLACES of the list and how many
     * of them each file gets. WHICH of a file's passages take its places, and in
     * what sequence, is the file's own merge order (mergeFileAngleResults():
     * consensus across the angles) — a passage's cosine depends on the angle that
     * surfaced it, and a probe written in the documents' wording scores every
     * passage higher than the user's plain words do, so cutting and listing a
     * file by cosine kept the probes' picks first and put (or cut) last the
     * passage all the angles agreed on. Across files nothing changes: a file's
     * first place is that of its strongest angle. The passage holding the question
     * angle's best match survives the cut of its file (evidence floor).
     *
     * @param array<int|string, list<array>> $allFiles File id => its references in merge order.
     * @return list<array>
     */
    private function rankReferencesWithinBudget(array $allFiles, int $maxReferences, bool $keepStructure = false): array
    {
        // Flat list in each file's merge order (a named article first, as it leads
        // the final list too). The per-angle chunk-score maps served the merge;
        // they are not context.
        $references = [];
        $owner = [];
        $nthOfFile = [];
        foreach (array_values($allFiles) as $file => $fileReferences) {
            $named = array_filter($fileReferences, static fn (array $f) => !empty($f['article_match']));
            $rest = array_filter($fileReferences, static fn (array $f) => empty($f['article_match']));
            foreach ([...array_values($named), ...array_values($rest)] as $nth => $reference) {
                unset($reference['chunk_scores']);
                // The heading levels and the chunk map are structure for the passage
                // selection that follows; it strips them when it is done.
                if (!$keepStructure) {
                    unset($reference['heading_path'], $reference['snippet_chunks']);
                }
                $references[] = $reference;
                $owner[] = $file;
                $nthOfFile[] = $nth;
            }
        }

        $order = array_keys($references);
        usort($order, static function (int $a, int $b) use ($references): int {
            $byName = empty($references[$a]['article_match']) <=> empty($references[$b]['article_match']);
            if ($byName !== 0) {
                return $byName;
            }
            $byScore = \Condoedge\Ai\Services\Retrieval\FileReferenceOrder::compare($references[$a], $references[$b]);

            return $byScore !== 0 ? $byScore : $a <=> $b;
        });

        if (count($order) > $maxReferences) {
            // ONE SLOT PER KIND OF DOCUMENT: when the candidates come from a general
            // document and from a record's own, the budget keeps at least one passage
            // of each (ReferenceSlots — live, a record's own document took all five
            // slots and the general law's article on the very point never reached
            // the answer). A named article is never the one given up.
            $top = \Condoedge\Ai\Services\Retrieval\ReferenceSlots::reserveOnePerKind(
                array_slice($order, 0, $maxReferences),
                $order,
                array_map(static fn (array $reference): ?string => \Condoedge\Ai\Services\Retrieval\ReferenceSlots::kindOf($reference), $references),
                array_map(static fn (array $reference): bool => !empty($reference['article_match']), $references)
            );
            $slots = array_count_values(array_map(static fn (int $i): int => $owner[$i], $top));
            // A file fills its slots in merge order; the passage holding the question
            // angle's best match (marked by the merge) is never the one cut — it
            // takes the file's last slot instead (a file with one slot keeps its
            // primary).
            $keeps = static function (int $i) use ($references, $owner, $nthOfFile, $slots): bool {
                $fileSlots = $slots[$owner[$i]] ?? 0;
                if ($nthOfFile[$i] < $fileSlots - 1) {
                    return true;
                }
                $marked = null;
                foreach ($owner as $j => $file) {
                    if ($file === $owner[$i] && !empty($references[$j]['question_passage']) && $nthOfFile[$j] >= $fileSlots) {
                        $marked = $j;
                        break;
                    }
                }

                return $fileSlots >= 2 && $marked !== null
                    ? $i === $marked
                    : $nthOfFile[$i] < $fileSlots;
            };
            $order = array_filter($order, $keeps);
        }
        $order = array_values($order);

        // Within the places the ranking gave a file, its passages follow its merge
        // order ($references lists every file in that order).
        $places = [];
        foreach ($order as $place => $i) {
            $places[$owner[$i]][] = $place;
        }
        $ranked = [];
        foreach ($places as $filePlaces) {
            $members = array_map(static fn (int $place): int => $order[$place], $filePlaces);
            sort($members);
            foreach ($filePlaces as $nth => $place) {
                $ranked[$place] = $references[$members[$nth]];
                unset($ranked[$place]['question_passage']); // served the cut; not context
            }
        }
        ksort($ranked);

        return array_values($ranked);
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
     * Phase 7 (structure-aware chunks, one article each): when the angles report
     * their per-file chunk rankings ($fused — reciprocal-rank fusion of every
     * angle's ranking of this file's chunks), the primary and the extras are
     * chosen by that CONSENSUS (ties: the shared order) instead of one angle's
     * cosine. Many articles of one law score within a few hundredths of each
     * other; the passage every phrasing ranks near the top is the answer, the
     * one a single phrasing happens to score highest often is not.
     *
     * EVIDENCE FLOOR (eval p16): the passage of this file that the user's OWN
     * wording ranked first ($questionPassage) always keeps a slot: when no kept
     * snippet window holds it, it replaces the weakest consensus extra. The other
     * angles are rewrites of the question (QU's, the probe writer's): they add
     * passages, they do not get to vote the user's own best match out. The
     * consensus still decides the ORDER — the floor keeps a passage, it does not
     * promote it. With a budget of one passage the consensus primary stands
     * alone.
     *
     * NEW EVIDENCE ON A FOLLOW-UP (eval p17-B rights t1): between two passages the
     * angles support EQUALLY, the one the previous turn did not cite comes first
     * ($previouslyCited) — a slot is not spent on a passage the user has already
     * read while equally supported new evidence waits. Only ever a tiebreak: a
     * passage the angles prefer keeps its place whether or not it was cited.
     *
     * @param array<int,array> $results         The same file's reference from each angle.
     * @param int              $maxChunks       Max DISTINCT chunks of this file to emit (>=1).
     * @param array<int,float> $fused           chunk_index => fused cross-angle rank score.
     * @param int|null         $questionPassage chunk_index the question angle ranked first in this file.
     * NO CHUNK TWICE (eval p18-B rights t0): the kept passages whose snippets share
     * a chunk are joined into one passage and the slots that frees are filled in
     * consensus order, supported passages first — see {@see withoutOverlappingPassages()}
     * ($ballots: the angles' own picks of this file; $overlap receives what was done).
     *
     * @param list<int>        $previouslyCited chunk indexes of this file the previous turn's answer cited.
     * @param list<array{group: string, picks: list<int>}> $ballots Per angle: its vote group and its
     *        passages of this file (chunk indexes), best first.
     * @param array<string, mixed>|null $overlap Receives the overlap report (trace only); [] when nothing overlapped.
     * @return array<int,array> Reference list: primary first, then extra chunks.
     */
    private function mergeFileAngleResults(array $results, int $maxChunks = 1, array $fused = [], ?int $questionPassage = null, array $previouslyCited = [], array $ballots = [], ?array &$overlap = null): array
    {
        $overlap = [];

        // A rank-fusion score is relative to ONE angle's result lists and is
        // file-level; it never ranks across angles or among a file's chunks
        // (Task 1.29). FileContextProvider does not emit it; drop any stray one.
        foreach ($results as &$angleResult) {
            unset($angleResult['fusion_score']);
        }
        unset($angleResult);

        // PREAMBLE (Phase 7.1b): a title / TOC / heading-only chunk never sets the
        // file's relevance or snippet while any angle reached real content of it.
        $content = array_values(array_filter($results, static fn (array $r) => empty($r['is_preamble'])));
        if ($content !== []) {
            $results = $content;
        }

        $maxRelevance = 0.0;
        foreach ($results as $r) {
            $maxRelevance = max($maxRelevance, (float) ($r['relevance'] ?? 0));
        }

        // Angles whose best chunk is close enough to the strongest to be considered
        // equally on-topic; among these the deepest (past-cover) snippet wins.
        // That depth rule is a PROXY for "not the cover/preamble chunk". Chunks
        // from the structure-aware chunker carry the real signal (is_preamble —
        // preamble refs were already set aside above), so for them the primary is
        // simply the most relevant passage (Phase 7): the proxy would only demote
        // the better article in favour of a later one.
        $structural = array_filter($results, static fn (array $r) => array_key_exists('is_preamble', $r)) !== [];
        // New evidence first among EQUALLY supported passages (follow-up turns).
        $cited = array_fill_keys(array_map('intval', $previouslyCited), true);
        $newFirst = static fn (array $a, array $b): int
            => isset($cited[(int) ($a['chunk_index'] ?? -1)]) <=> isset($cited[(int) ($b['chunk_index'] ?? -1)]);
        $order = $fused !== []
            ? static function (array $a, array $b) use ($fused, $newFirst): int {
                $voteA = $fused[(int) ($a['chunk_index'] ?? 0)] ?? 0.0;
                $voteB = $fused[(int) ($b['chunk_index'] ?? 0)] ?? 0.0;
                // The same ranks from the same number of groups, summed in another order.
                if (abs($voteA - $voteB) > self::EQUAL_VOTE_EPSILON) {
                    return $voteB <=> $voteA;
                }

                return $newFirst($a, $b) ?: \Condoedge\Ai\Services\Retrieval\FileReferenceOrder::compare($a, $b);
            }
            : static fn (array $a, array $b): int
                => \Condoedge\Ai\Services\Retrieval\FileReferenceOrder::compare($a, $b) ?: $newFirst($a, $b);
        $margin = self::SNIPPET_RELEVANCE_MARGIN;
        $best = null;
        if ($structural) {
            $ordered = $results;
            usort($ordered, $order);
            $best = $ordered[0];
        }
        foreach ($structural ? [] : $results as $r) {
            if ((float) ($r['relevance'] ?? 0) < $maxRelevance - $margin) {
                continue;
            }
            if ($best === null
                || (int) ($r['chunk_index'] ?? 0) > (int) ($best['chunk_index'] ?? 0)) {
                $best = $r;
            }
        }

        // EXACT-ARTICLE (Phase 7.2): the article the user named by number is the
        // file's primary passage, whatever its cosine.
        $named = array_values(array_filter($results, static fn (array $r) => !empty($r['article_match'])));
        if ($named !== []) {
            $best = \Condoedge\Ai\Services\Retrieval\FileReferenceOrder::sort($named)[0];
        }

        // Fallback: if nothing qualified (shouldn't happen), keep the first result.
        $primary = $best ?? $results[0];

        // The file's ranking strength is its strongest angle, independent of which
        // angle's snippet was chosen. The snippet chunk's OWN cosine is kept as
        // chunk_relevance so traces/diagnostics never report the file's max as
        // that chunk's similarity.
        $primary['chunk_relevance'] = (float) ($primary['relevance'] ?? 0);
        $primary['relevance'] = $maxRelevance;

        // (No rank fusion here: fusion_score is per-angle and file-level and never
        // leaves FileContextProvider — Task 1.29.)

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
                || \Condoedge\Ai\Services\Retrieval\FileReferenceOrder::compare($r, $byChunk[$chunkIndex]) < 0) {
                $byChunk[$chunkIndex] = $r;
            }
        }

        // The question angle's own best passage, when it is a distinct passage of
        // the file (outside the primary's window, over the confident threshold).
        $questionReference = $questionPassage !== null ? ($byChunk[$questionPassage] ?? null) : null;

        // Rank the extras by the shared rule — or by cross-angle consensus when
        // the angles reported their chunk rankings (see above).
        $byChunk = array_values($byChunk);
        usort($byChunk, $order);

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

        // EVIDENCE FLOOR: the question angle's best passage keeps a slot. When no
        // kept snippet window holds it, it takes the place of the weakest consensus
        // extra (it lies outside every kept window, so it overlaps none). Whichever
        // extra holds it is marked, so the overall budget cut keeps it too
        // (rankReferencesWithinBudget()); the primary needs no mark.
        if ($questionPassage !== null) {
            $holder = null;
            foreach ($selectedIndexes as $position => $selectedIndex) {
                if (abs($questionPassage - $selectedIndex) <= $window) {
                    $holder = $position;
                    break;
                }
            }
            if ($holder === null && $questionReference !== null) {
                if (count($merged) >= $maxChunks) {
                    array_pop($merged);
                }
                $merged[] = $questionReference;
                $holder = count($merged) - 1;
            }
            if ($holder !== null && $holder > 0) {
                $merged[$holder]['question_passage'] = true;
            }
        }

        return $this->withoutOverlappingPassages($merged, $byChunk, $ballots, $overlap);
    }

    /** Vote groups that must have picked a passage for it to go FIRST to a slot freed by a merge. */
    private const MIN_SUPPORTING_VOTE_GROUPS = 2;

    /**
     * NO CHUNK TWICE (eval p18-B rights t0). The window rule keeps two passages of a
     * file when their chunks are more than context_window apart — but every snippet
     * is its chunk ± context_window, so passages three or four chunks apart still
     * SHARE chunks: chunks 58, 61 and 64 printed art. 264–265 in [1] and [2] and
     * art. 267–268 in [2] and [3], a fifth of the prompt's passage text, and the
     * answer repeated exactly those articles.
     *
     * 1. MERGE. Kept passages whose snippets share a chunk (transitively) become ONE
     *    passage: their chunks, each once, in reading order — "art. 262–269" — at
     *    the place of the best-ranked of them, counted as one slot. What a snippet is
     *    made of is the provider's own word (the reference's `snippet_chunks` map,
     *    FileContextProvider::PASSAGE_CHUNKS), never an assumption about the window;
     *    a reference without that map cannot be joined chunk by chunk, so such a
     *    list is returned untouched.
     *
     * 2. FILL, IN CONSENSUS ORDER. Each merge frees a slot. A freed slot goes to a
     *    candidate that shares NO chunk with a kept passage (an overlapping one would
     *    only chain the merged passage on, past what the budget admitted):
     *     - first to the candidates at least MIN_SUPPORTING_VOTE_GROUPS vote groups
     *       made one of their own passages ($ballots: a pick of the group inside the
     *       candidate's window), in the file's consensus order;
     *     - then, for a slot that would otherwise stay EMPTY, to the next candidates
     *       in consensus order whatever their support. Holding freed slots to two
     *       groups left them empty while the first candidate was the passage the
     *       answer needed (eval p19-B rights t0: the contingency-fund passage, the
     *       pick of one probe in both corpus languages — one group — was refused, two
     *       slots stayed empty, and a passage of the same single-group support that
     *       the budget had admitted kept its place and was never cited). The budget
     *       admits extras by consensus alone; a freed slot now gets no stricter rule.
     *    Nothing is displaced: the kept passages stay, and no passage is added beyond
     *    the slots the merges freed.
     *
     * Structural only: chunk indexes and the angles' own picks — no vocabulary.
     *
     * @param list<array> $kept       The file's kept passages, in merge order (primary first).
     * @param list<array> $candidates Every confident extra candidate of the file, in consensus order.
     * @param list<array{group: string, picks: list<int>}> $ballots
     * @param array<string, mixed>|null $report Receives {merged: list<list<int>>, filled?: list<int>,
     *        single_group?: list<int>, unsupported?: list<int>} when passages were joined (trace
     *        only): what took a freed slot, which of those one vote group alone stands
     *        behind, and the single-group candidates no slot was left for.
     * @return list<array>
     */
    private function withoutOverlappingPassages(array $kept, array $candidates, array $ballots, ?array &$report = null): array
    {
        $report = [];
        if (count($kept) < 2) {
            return $kept;
        }

        /** @var list<array{reference: array, chunks: array<int, string>, members: list<int>}> $passages */
        $passages = [];
        $joined = false;
        foreach ($kept as $reference) {
            $chunks = $this->snippetChunksOf($reference);
            if ($chunks === null) {
                return $kept; // this list cannot be joined chunk by chunk
            }

            $into = null;
            foreach ($passages as $position => $passage) {
                if (array_intersect_key($passage['chunks'], $chunks) === []) {
                    continue;
                }
                $joined = true;
                if ($into === null) {
                    $into = $position;
                    $passages[$into]['chunks'] += $chunks;
                    $passages[$into]['members'][] = (int) ($reference['chunk_index'] ?? 0);
                    // The evidence-floor mark follows the passage that now holds it
                    // (the primary needs none).
                    if (!empty($reference['question_passage']) && $into > 0) {
                        $passages[$into]['reference']['question_passage'] = true;
                    }
                    continue;
                }
                // The reference bridges two kept passages: they are one passage too.
                $passages[$into]['chunks'] += $passage['chunks'];
                $passages[$into]['members'] = [...$passages[$into]['members'], ...$passage['members']];
                unset($passages[$position]);
            }
            if ($into === null) {
                $passages[] = ['reference' => $reference, 'chunks' => $chunks, 'members' => [(int) ($reference['chunk_index'] ?? 0)]];
            }
            $passages = array_values($passages);
        }

        if (!$joined) {
            return $kept;
        }

        // FILL the freed slots: the supported candidates first, then — for a slot that
        // would stay empty — the others, each time in the file's consensus order.
        $supported = function (array $chunks) use ($ballots): bool {
            $groups = [];
            foreach ($ballots as $ballot) {
                foreach ((array) ($ballot['picks'] ?? []) as $pick) {
                    if (isset($chunks[(int) $pick])) {
                        $groups[(string) ($ballot['group'] ?? '')] = true;
                        break;
                    }
                }
            }

            return count($groups) >= self::MIN_SUPPORTING_VOTE_GROUPS;
        };

        $filled = [];
        $singleGroup = [];
        $unsupported = [];
        foreach ([true, false] as $supportedPass) {
            foreach ($candidates as $candidate) {
                $chunks = $this->snippetChunksOf($candidate);
                if ($chunks === null) {
                    continue;
                }
                foreach ($passages as $passage) {
                    if (array_intersect_key($passage['chunks'], $chunks) !== []) {
                        continue 2; // kept already, or it would chain a kept passage on
                    }
                }

                $index = (int) ($candidate['chunk_index'] ?? 0);
                $isSupported = $supported($chunks);
                if ($supportedPass && !$isSupported) {
                    continue; // its turn comes when no supported passage claims the slot
                }
                if (count($passages) >= count($kept)) {
                    // No slot is left for it. (A supported one needs no mention: the
                    // trace names what one angle alone stood behind.)
                    if (!$supportedPass && !$isSupported) {
                        $unsupported[] = $index;
                    }
                    continue;
                }

                $passages[] = ['reference' => $candidate, 'chunks' => $chunks, 'members' => [$index]];
                $filled[] = $index;
                if (!$isSupported) {
                    $singleGroup[] = $index;
                }
            }
        }

        $references = [];
        $mergedMembers = [];
        foreach ($passages as $passage) {
            $reference = $passage['reference'];
            if (count($passage['members']) > 1) {
                ksort($passage['chunks']);
                $reference['snippet'] = implode(FileContextProvider::SNIPPET_SEPARATOR, $passage['chunks']);
                $reference['snippet_chunks'] = $passage['chunks'];
                $mergedMembers[] = $passage['members'];
            }
            $references[] = $reference;
        }

        $report = ['merged' => $mergedMembers]
            + ($filled !== [] ? ['filled' => $filled] : [])
            + ($singleGroup !== [] ? ['single_group' => $singleGroup] : [])
            + ($unsupported !== [] ? ['unsupported' => $unsupported] : []);

        return $references;
    }

    /**
     * Stamp each passage of one file with the search angles that stand behind it:
     *  - `angle_groups`: every vote group one of whose OWN picks of the file the passage
     *    holds — its chunk, or a chunk of its stitched window — in fan-out order;
     *  - `question_supported`: the user's own wording (the question angle) is one of them.
     *
     * A passage the question itself found is about what was asked; one that only a rewrite
     * found (QU's search query or clarified question, a document probe) may be where that
     * rewrite drifted. Nothing in retrieval changes — the facts ride the references for
     * consumers that must not build on drift (the follow-up question chips,
     * {@see \Condoedge\Ai\Services\Response\FollowUpWriter}). Structural: the angles' own
     * ballots, no score threshold and no vocabulary.
     *
     * @param list<array> $references The file's final references (mergeFileAngleResults()).
     * @param list<array{group: string, picks: list<int>}> $ballots Per angle: its vote group and its
     *        passages of the file (chunk indexes).
     * @return list<array>
     */
    private function withAngleSupport(array $references, array $ballots): array
    {
        foreach ($references as $position => $reference) {
            if (!isset($reference['chunk_index'])) {
                continue;
            }

            $held = array_map('intval', array_keys($this->snippetChunksOf($reference) ?? []));
            $held[] = (int) $reference['chunk_index'];

            $groups = [];
            foreach ($ballots as $ballot) {
                if (array_intersect(array_map('intval', $ballot['picks']), $held) !== []) {
                    $groups[$ballot['group']] = true;
                }
            }

            $references[$position]['angle_groups'] = array_keys($groups);
            $references[$position]['question_supported'] = isset($groups[self::ANGLE_QUESTION]);
        }

        return $references;
    }

    /**
     * The chunks a reference's snippet is made of (chunk_index => text), as the
     * provider reported them; null when it did not.
     *
     * @return array<int, string>|null
     */
    private function snippetChunksOf(array $reference): ?array
    {
        $chunks = $reference['snippet_chunks'] ?? null;

        return is_array($chunks) && $chunks !== [] ? $chunks : null;
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
        // An ask QU judged to be about ANOTHER page — the user's own team or profile
        // (navigation_target) — is not a request to describe the page the chat was
        // opened from. "Where can I add new members?" came back intent=page_explanation
        // with navigation_target=own_team on 4 of 8 stored runs (eval p17–p23 resilience
        // t2) and on every run under the strict reply schema: narrating the current page
        // would answer it with the wrong page. Structured QU signals only.
        if (trim((string) $ctx->qu->navigationTarget) !== '') {
            return null;
        }

        $description = $this->pageDescriptionFor($options['page_context'] ?? []);
        if ($description === null) {
            return null;
        }

        $ctx->pageDescription = $description;
        $this->emitStage($ctx->observer, 'page_narration', [
            'description_key' => \Condoedge\Ai\DTOs\PageContext::fromArray($options['page_context'] ?? [])->descriptionKey(),
        ]);

        $responseResult = $this->observeStage(
            $ctx->observer,
            'composition',
            fn (): array => $this->generateResponse($ctx, $options),
            fn (array $r): array => $this->compositionTrace('page_narration', $r)
        );

        return [
            'question' => $question,
            'answer' => $responseResult['answer'],
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
     * Fires only when (a) QU judged the turn a BROAD digest ask (this wins even
     * when QU also flagged followUpReference) and (b) the host configured at
     * least one enabled ai.attention_sources entry. Every source executes through
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
        // digestRequest WINS over followUpReference (Task 1.27 a): with the
        // conversation block, QU can flag a broad attention ask made after a
        // single-topic turn ("Give me an excel of everything needing my
        // attention") as a follow-up too; letting that veto the digest exported
        // the previous 1-row result. The digest composer owns a combined
        // export ask (export_followup_offered).
        if (!$ctx->qu->digestRequest) {
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

        $sources = $this->observeStage(
            $ctx->observer,
            'digest',
            fn (): array => $this->digestRunner->run($execOptions),
            fn (array $run): array => [
                'sources_run' => count($run),
                'sources_failed' => array_values(array_map(
                    static fn ($s): string => $s->key,
                    array_filter($run, static fn ($s): bool => $s->error !== null)
                )),
                'skipped_sources' => $registry->skippedSourceKeys(),
                'rows' => array_sum(array_map(static fn ($s): int => $s->rowCount, $run)),
            ]
        );
        if ($sources === []) {
            return null;
        }

        // B4: section links, per-row record chips and the export chip — attached by CODE
        // (the model dropped configured section links in 3/3 measured digest runs).
        try {
            $attached = $this->actionAttacher->forDigest($ctx, $sources, $options);
        } catch (\Throwable $e) {
            \Log::warning('Digest action attachment failed — continuing without chips: ' . $e->getMessage(), ['exception' => $e]);
            $attached = AttachedActions::none();
        }

        return $this->observeStage(
            $ctx->observer,
            'composition',
            fn (): array => $this->digestComposer->compose(
                $question,
                $ctx,
                $sources,
                $registry->skippedSourceKeys(),
                $ctx->qu->exportFormat,
                $options,
                $attached
            ),
            fn (array $r): array => $this->compositionTrace('digest', $r)
        );
    }

    /**
     * Judge whether this turn is a genuine fork (ONE small focused LLM call —
     * ClarityChecker) and, when it is, put the readings and the clarifying
     * question on the QU result, where the clarify branch below reads them.
     *
     * Most turns make no call: the checker's structural preconditions require a
     * first message (no conversation, no page) that asks for data and whose text
     * names no kind of record and no person. What QU guessed about such a
     * question — an owner, an entity type, a rewritten question — is not
     * consulted, and once a fork is found none of it is followed: the turn ends
     * in the clarify branch.
     *
     * Never throws and never blocks the turn: a failed call leaves the QU result
     * untouched. The 'clarity_check' stage is emitted only when the call was
     * made (observation only: its outcome, never the question's words).
     */
    private function checkClarity(PipelineContext $ctx, string $question): void
    {
        if ($this->clarityChecker === null
            || $ctx->qu->needsClarification
            || $this->clarityChecker->blockedBy($question, $ctx->qu, $ctx->conversationContext, $ctx->pageContext) !== null) {
            return;
        }

        $start = hrtime(true);
        $verdict = $this->clarityChecker->check($question, $ctx->qu->language, $ctx->observer);

        $this->emitStage($ctx->observer, ClarityChecker::STAGE, [
            'ask' => $verdict['ask'],
            'reason' => $verdict['reason'],
            'readings' => count($verdict['readings']),
        ], $this->elapsedMs($start));

        if ($verdict['ask']) {
            $ctx->qu = $ctx->qu->withClarification($verdict['readings'], $verdict['question']);
        }
    }

    /**
     * Answer a genuinely-ambiguous turn with its ONE clarifying question, or
     * null to keep the normal path.
     *
     * Fires only when (a) ai.understanding.clarify_enabled (default true),
     * (b) the QU result needs clarification (set by checkClarity() above from
     * the focused clarity call), (c) the turn is NEITHER a follow-up reference
     * NOR a digest ask (both re-guarded here — defence in depth beyond the
     * checker's preconditions and the result's own guard), and (d) there is a
     * usable clarifying question — or, failing that, candidate readings, which
     * are offered under the lang-key fallback in the question's language
     * (Task 1.22). The answer is otherwise the clarifying question VERBATIM —
     * composed in the question's language by the clarity call, so this branch
     * itself makes no LLM call and skips file search + query generation
     * entirely. The candidate readings ride response metadata for graders/UI.
     *
     * @return array<string, mixed>|null
     */
    private function clarifyResponse(PipelineContext $ctx, string $question, array $options): ?array
    {
        if (!config('ai.understanding.clarify_enabled', true)) {
            return null;
        }

        // Structured guard (Task 1.22): a QU-judged follow-up operates on the
        // previous answer (a recorded result, or the previous exchange) — it is
        // never a clarify turn, even when the LLM over-flags ambiguity. Only
        // structured QU signals are consulted here, never the question text.
        if (!$ctx->qu->needsClarification || $ctx->qu->followUpReference || $ctx->qu->digestRequest) {
            return null;
        }

        // Second structured guard (Task 1.22 review): an EXPORT ask while a
        // previous result is RECORDED never clarifies — the LLM can miss the
        // follow-up flag and over-flag ambiguity on a pronoun export (live
        // 2026-09-29: "exporte-la en Excel" after a roster). The turn proceeds
        // as a follow-up on the recorded result, so buildFinalResponse sources
        // the export from it. Structured signals only: QU exportFormat + the
        // snapshot's recorded cypher / row count.
        $recorded = $ctx->conversationContext;
        if ($ctx->qu->exportFormat !== null
            && (!empty($recorded['last_cypher_query']) || (int) ($recorded['last_result_count'] ?? 0) > 0)) {
            $ctx->qu = $ctx->qu->asFollowUpOnPreviousResult();

            return null;
        }

        $clarifyingQuestion = trim($ctx->qu->clarifyingQuestion);
        $usedFallback = false;
        if ($clarifyingQuestion === '') {
            $clarifyingQuestion = $this->fallbackClarifyingQuestion($ctx);
            $usedFallback = true;
        }
        if ($clarifyingQuestion === '') {
            // Fail-safe: nothing to ask about (no question AND no readings) —
            // run the normal pipeline rather than dead-ending the turn.
            return null;
        }

        $this->emitStage($ctx->observer, 'clarify', [
            'candidate_readings' => count($ctx->qu->candidateReadings),
            'fallback_question' => $usedFallback,
        ]);

        $response = [
            'question' => $question,
            'answer' => $clarifyingQuestion,
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
                // No subject: the question is unresolved, and what QU wrote for it is a
                // guess (live: "balance associated with my account" for "Show me the
                // balance."). Nothing of that guess labels the turn or reaches the next one.
                'subject' => null,
            ],
        ];
        // Each candidate reading is a follow-up question chip, attached by code (no LLM).
        $response['metadata']['response'] = $this->actionAttacher->forClarification($ctx, $options)
            ->applyTo($response['metadata']['response']);
        // Zero-LLM composition: the clarifying question verbatim.
        $this->emitStage($ctx->observer, 'composition', $this->compositionTrace('clarify', $response));

        return $response;
    }

    /**
     * The clarifying question when a genuine fork was found with its candidate
     * readings but no usable clarifying question: a package lang key rendered in
     * the QUESTION's language (QU-detected; app locale only when QU reported
     * none), listing the readings (already in the question's language). ''
     * when there are no readings to offer — the caller then keeps the normal
     * pipeline.
     */
    private function fallbackClarifyingQuestion(PipelineContext $ctx): string
    {
        $readings = array_values(array_filter(
            array_map(static fn($r): string => is_string($r) ? trim($r) : '', $ctx->qu->candidateReadings),
            static fn(string $r): bool => $r !== ''
        ));
        if ($readings === []) {
            return '';
        }

        $locale = $ctx->qu->language !== '' ? $ctx->qu->language : null;
        $lines = array_map(static fn(string $r): string => "- {$r}", $readings);

        return trans('ai.clarify.fallback_question', [], $locale) . "\n" . implode("\n", $lines);
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
            $this->emitStage($ctx->observer, 'file_listing', ['files' => count($ctx->fileContext['relevant_files'])]);
            return $ctx;
        }

        // Docs only (ai.query_generation.enabled = false, or an audience that reads no data): no query.
        if (!$this->queryGenerationEnabled($options['user'] ?? null)) {
            $ctx->queryResult = $hasFileContext
                ? new QueryGenerationResult(
                    explanation: 'Docs only — answered from documents.',
                    confidence: 1.0,
                    metadata: ['source' => 'file_context', 'question_type' => $ctx->qu->questionType],
                )
                : new QueryGenerationResult(
                    explanation: 'Docs only — no document found.',
                    metadata: ['generation_skipped' => 'docs_only'],
                );
            $ctx->executionResult = QueryExecutionResult::empty();
            $this->emitStage($ctx->observer, $hasFileContext ? 'knowledge_query' : 'docs_only', [
                'files' => count($ctx->fileContext['relevant_files'] ?? []),
            ]);

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
            // Doc-only turn: query generation + execution skipped by design.
            $this->emitStage($ctx->observer, 'knowledge_query', ['files' => count($ctx->fileContext['relevant_files'])]);
            return $ctx;
        }

        // EXPORT OF THE RECORDED RESULT (eval p18: B resilience t3, B french t1, A pdf t1).
        // "Ok, can you export that members list to excel?" asks for what was shown, as
        // it is: the file is the recorded query re-executed through the scoped executor
        // up to the document row limit (previousResultExportRows, in composition). Query
        // generation used to run anyway — 6-8k input tokens and ~2 s — and its query was
        // then discarded, because a fresh query only wins when it ADDS columns. When QU's
        // own output proves nothing new is asked ({@see PreviousResultExport}: same
        // subject as the recorded result — or a re-labelled one whose named attributes
        // are all carried, eval p20-A roster t1 — and no property concept it does not
        // already carry), generation and execution are skipped. Anything else — an
        // attribute the recorded rows lack — generates as before. Disable with
        // ai.export.skip_generation_for_recorded_result=false.
        if ((bool) config('ai.export.skip_generation_for_recorded_result', true)
            && PreviousResultExport::asksNothingNew($ctx->qu, is_array($ctx->conversationContext) ? $ctx->conversationContext : [])) {
            $ctx->queryResult = new QueryGenerationResult(
                explanation: 'Export of the recorded result — no new query needed.',
                confidence: 1.0,
                metadata: ['generation_skipped' => 'recorded_result_export'],
            );
            $ctx->executionResult = QueryExecutionResult::empty();
            $this->emitStage($ctx->observer, 'recorded_result_export', [
                'generation_skipped' => true,
                'format' => $ctx->qu->exportFormat,
                'recorded_rows' => (int) ($ctx->conversationContext['last_result_count'] ?? 0),
            ]);

            return $ctx;
        }

        $queryResult = $this->observeStage(
            $ctx->observer,
            'query_generation',
            fn (): QueryGenerationResult => $this->generateQueryResult($question, $ctx, $options),
            fn (QueryGenerationResult $r): array => $this->generationTrace($r)
        );

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
        //
        // An execution EXCEPTION (Neo4j SyntaxError, rate limit, deny-mode scoping)
        // is recorded as a failed result instead of escaping to answerQuestion's
        // catch-all: composition then renders the localized error through the ONE
        // error branch in buildFinalResponse (question language, never empty)
        // instead of an empty answer (live eval 2026-09-29: rights t2 answered '').
        //
        // REPAIR (Task 5.5): a Neo4j STATEMENT error (syntax / semantic) of the
        // generated query gets ONE regeneration fed with the error text and the
        // generator's own draft, then runs through the same scoped executor.
        // Timeouts, safe_stop, security / rate-limit / connection failures are
        // never retried (ExecutionErrorClassifier).
        $ctx->observer->stageStarted('execution');
        $executionStart = hrtime(true);
        [$executionResult, $failure] = $this->runExecution($queryResult->cypher, $execOptions, $ctx, $question, $queryResult->parameters);
        $repairable = $failure !== null
            && $queryResult->hasCypher()
            && (bool) config('ai.query_generation.repair_on_execution_error', true)
            && ExecutionErrorClassifier::isRepairable($failure);
        $ctx->observer->stageFinished(
            'execution',
            $this->executionTrace($executionResult) + ['repair_attempted' => $repairable, 'repair_succeeded' => false],
            $this->elapsedMs($executionStart)
        );

        if ($repairable) {
            [$queryResult, $executionResult] = $this->repairAfterExecutionError(
                $ctx, $question, $options, $execOptions, $queryResult, $executionResult, $failure
            );
        }

        $this->storeProvenExample($question, $ctx, $queryResult, $executionResult, $options);

        // Eval p11 D2: a capped list whose companion counts PROVE a fan-out is
        // re-run one row per anchor record (after the few-shot store: the
        // generator's own query is the example, not the rewrite).
        [$queryResult, $executionResult] = $this->collapseFanOut($ctx, $queryResult, $executionResult, $execOptions);

        // Eval p16 A#3 / B#1: an EMPTY result is explained from the query that ran —
        // its scope, the ownership count behind an own-data empty, or the host's
        // labeled fallback rows (after the few-shot store: a fallback is never an example).
        [$queryResult, $executionResult] = $this->resolveEmptyResult($ctx, $queryResult, $executionResult, $execOptions);

        // Eval p18-B digest t1: ONE record is shown with the parent it belongs to (the
        // group of a meeting), whatever the generator happened to project.
        $executionResult = $this->enrichSingleRecord($ctx, $queryResult, $executionResult, $execOptions);

        $executionResult = $this->enrichResultsWithEntityActions($executionResult);

        $ctx->queryResult = $queryResult;
        $ctx->executionResult = $executionResult;

        return $ctx;
    }

    /**
     * FAN-OUT COLLAPSE (eval p11 D2). A capped list joining a one-to-many entity
     * returns one row per JOINED record: the live roster+contacts query showed
     * "100 of 1354" for 523 members, its 100-row window holding ~40 members (and
     * an OPTIONAL User over TeamRoles disclosed 525 for 523 members). The
     * companion counts already PROVE it: true_total (rows) > anchor_total
     * (count DISTINCT anchor). The query is then re-run through the SAME scoped
     * executor rewritten to one row per anchor (FanOutCollapse::perAnchorQuery:
     * MATCH/WHERE verbatim, joined columns collect(DISTINCT …) joined "a; b"),
     * and its population IS anchor_total — no extra count. Display, disclosure,
     * conversation record (last_cypher_query) and exports all reason in records.
     *
     * CHOICE (deterministic, no LLM repair round): the rewrite is structural and
     * lossless for the joined values; a repair round would cost an LLM call per
     * fan-out turn and could still return a fanned-out query. It applies ONLY
     * when it is reliable:
     *  - the counts prove the fan-out (cap hit, anchor_total < true_total);
     *  - the anchor is the FIRST RETURN item (not the tie-break heuristic);
     *  - the anchor is not pinned to one value (u.name = '310': the rows are the
     *    joined records of that one anchor, e.g. its payments);
     *  - QU's intent is not count / aggregate / compare;
     *  - the shape is rewritable (FanOutCollapse returns null otherwise);
     *  - the re-run result IS one row per anchor (FanOutCollapse::isCollapsed —
     *    list cells, distinct anchor values) before anything is stamped.
     * Each collected cell is capped at ai.query_execution.collapse_cell_limit
     * values (default 10; a longer one ends with "…", fan_out_cells_truncated).
     * Known limit: a list whose subject is NOT the first column (`RETURN
     * c.number, p.amount` for a payments list) would be grouped per customer; the
     * pin rule covers the common single-customer case. Disable with
     * ai.query_execution.collapse_fan_out=false. Any failure keeps the original.
     *
     * @return array{0: QueryGenerationResult, 1: QueryExecutionResult}
     */
    private function collapseFanOut(
        PipelineContext $ctx,
        QueryGenerationResult $queryResult,
        QueryExecutionResult $executionResult,
        array $execOptions
    ): array {
        $unchanged = [$queryResult, $executionResult];
        $meta = $executionResult->metadata;
        $rowTotal = $meta['true_total'] ?? null;
        $anchorTotal = $meta['anchor_total'] ?? null;
        $anchor = $meta['anchor_variable'] ?? null;

        if (!(bool) config('ai.query_execution.collapse_fan_out', true)
            || !$queryResult->hasCypher()
            || !$executionResult->success || !empty($executionResult->errors) || $executionResult->data === []
            || !is_numeric($rowTotal) || !is_numeric($anchorTotal) || (int) $anchorTotal >= (int) $rowTotal
            || !is_string($anchor) || ($meta['anchor_first_item'] ?? false) !== true
            || in_array($ctx->qu->intent, ['count', 'aggregate', 'compare'], true)) {
            return $unchanged;
        }

        $cypher = (string) $queryResult->cypher;
        if (FanOutCollapse::isPinned($cypher, $anchor)) {
            return $unchanged;
        }
        $plan = FanOutCollapse::plan($cypher, $anchor, $this->collapseCellLimit());
        if ($plan === null) {
            return $unchanged;
        }
        $collapsedCypher = $plan['query'];

        $start = hrtime(true);
        try {
            $collapsed = $this->queryExecutor->execute($collapsedCypher, $queryResult->parameters, $execOptions);
        } catch (\Throwable $e) {
            \Log::warning('Fan-out collapse re-run failed — keeping the joined rows: ' . $e->getMessage());
            $this->emitStage($ctx->observer, 'fan_out_collapse', ['collapsed' => false, 'error' => $e->getMessage()], $this->elapsedMs($start));
            return $unchanged;
        }

        if (!$collapsed->success || !empty($collapsed->errors) || $collapsed->data === []
            || ($collapsed->metadata['safe_stop'] ?? false) === true) {
            $this->emitStage($ctx->observer, 'fan_out_collapse', ['collapsed' => false], $this->elapsedMs($start));
            return $unchanged;
        }

        // Review S1: never stamp a population on rows that are still fanned out
        // (a rewrite the database read differently, a collected node/map member):
        // the re-run must BE one row per anchor, with list cells.
        if (!FanOutCollapse::isCollapsed($collapsed->data, $plan)) {
            $this->emitStage($ctx->observer, 'fan_out_collapse', ['collapsed' => false, 'reason' => 'not_collapsed', 'cypher' => $collapsedCypher], $this->elapsedMs($start));
            return $unchanged;
        }

        $truncatedCells = 0;
        $collapsedRows = FanOutCollapse::joinLists($collapsed->data, $plan['cell_limit'], $truncatedCells);

        $population = max((int) $anchorTotal, count($collapsed->data));
        $stamp = [
            'true_total' => $population,
            'anchor_total' => $population,
            'anchor_variable' => $anchor,
            'anchor_label' => $meta['anchor_label'] ?? null,
            'anchor_first_item' => true,
            'fan_out_collapsed' => [
                'row_total' => (int) $rowTotal,
                'anchor_total' => (int) $anchorTotal,
                'anchor_label' => $meta['anchor_label'] ?? null,
            ],
        ];
        if ($truncatedCells > 0) {
            // Cells capped at ai.query_execution.collapse_cell_limit values (each
            // shown with a trailing FanOutCollapse::TRUNCATION_MARKER).
            $stamp['fan_out_cells_truncated'] = ['cells' => $truncatedCells, 'cell_limit' => $plan['cell_limit']];
        }

        $this->emitStage($ctx->observer, 'fan_out_collapse', [
            'collapsed' => true,
            'row_total' => (int) $rowTotal,
            'anchor_total' => (int) $anchorTotal,
            'anchor_label' => $meta['anchor_label'] ?? null,
            'row_count' => count($collapsed->data),
            'cells_truncated' => $truncatedCells,
            'cypher' => $collapsedCypher,
        ], $this->elapsedMs($start));

        return [
            new QueryGenerationResult(
                cypher: $collapsedCypher,
                explanation: $queryResult->explanation,
                confidence: $queryResult->confidence,
                warnings: $queryResult->warnings,
                metadata: $queryResult->metadata + ['fan_out_collapsed' => true, 'pre_collapse_cypher' => $cypher],
                parameters: $queryResult->parameters,
            ),
            new QueryExecutionResult(
                success: true,
                data: $collapsedRows,
                stats: array_merge($collapsed->stats, $stamp),
                metadata: array_merge($collapsed->metadata, $stamp),
                errors: [],
            ),
        ];
    }

    /**
     * EMPTY RESULT (eval p16 A#3 "When is our next meeting?", B#1 "So how much is my
     * contribution this year?"). A generated query that ran and matched NOTHING
     * reached the abstention with only Query Understanding's guesses about it. Here
     * CODE establishes what the emptiness means, from the executed query and host
     * config, through the same scoped executor and the turn's own options (identity,
     * provenance, the turn's rate_limit_batch — no extra charge) and with ZERO LLM
     * calls ({@see EmptyResultInspector}):
     *
     *  - SCOPE (always): 'own' when the query is anchored on the user (it binds a
     *    user-bound node of ai.user_entity_paths, or the user's own node, to this
     *    user's id), 'team' when it was tenant-scoped without such an anchor. Stamped
     *    as execution metadata `empty_scope`: the abstention states THAT scope — never
     *    QU's possessive flag ("no meetings linked to you" after a team-wide query) —
     *    and the action attacher reads it for the own-data door.
     *
     *  - OWN-DATA query: ONE count of the ownership path the query traverses (does
     *    the user own ANY record?) → metadata `ownership_check`
     *    {entity, display_name, count}, stated by the abstention as the reason.
     *    The same holds for the ONE-ROW shape of an empty own-data result — a global
     *    aggregate over nothing (`RETURN sum(…)` → 0, live 2026-10-06: "your
     *    contribution is $0.00" for a user who owns no record): when the count proves
     *    there was nothing to total, the row is not served as a figure — the result
     *    becomes that empty own-data result (the row kept as metadata
     *    `aggregate_over_nothing`). A team-wide zero total, or an owner's real zero,
     *    is never touched.
     *
     *  - any other query: the labeled FALLBACK the host declared for the query's
     *    entity in ai.attention_sources — only when exactly ONE source declares that
     *    entity, and only after that source's own query confirmed there is nothing
     *    (so its label, e.g. "… — none scheduled", is proven). Its rows REPLACE the
     *    empty result and take the normal data path (table, record chips), stamped
     *    `empty_fallback` {source, label, fallback_label, entity, action_key} so the
     *    answer plan frames them as fallback rows, never as what was asked
     *    (DataAnswerPlan), and the source's page is attached (ActionAttacher). The
     *    fallback query becomes the turn's query: a follow-up export re-runs what
     *    was shown. Never for an own-data query (the source is team-wide — its rows
     *    are not the user's own) nor for an export ask (a file titled by the question
     *    must not carry other rows).
     *
     * The query's entity is its RETURN anchor ({@see projectionAnchor()}), else its
     * first node label — structure, no vocabulary. Anything uncertain (no source,
     * several sources, a failed / safe-stopped / throwing check) leaves the honest
     * empty untouched. Observed as the 'empty_result_check' stage. Disable with
     * ai.query_execution.empty_result_checks=false.
     *
     * @return array{0: QueryGenerationResult, 1: QueryExecutionResult}
     */
    private function resolveEmptyResult(
        PipelineContext $ctx,
        QueryGenerationResult $queryResult,
        QueryExecutionResult $executionResult,
        array $execOptions
    ): array {
        $unchanged = [$queryResult, $executionResult];

        if (!(bool) config('ai.query_execution.empty_result_checks', true)
            || !$queryResult->hasCypher()
            || !$executionResult->success || !empty($executionResult->errors)
            || ($executionResult->metadata['safe_stop'] ?? false) === true) {
            return $unchanged;
        }

        $start = hrtime(true);
        $cypher = (string) $queryResult->cypher;
        $ownership = null;
        $fallback = null;

        try {
            $inspector = $this->emptyResultInspector ??= new EmptyResultInspector($this->queryExecutor, $this->digestRunner->registry());

            // The one-row shape of "nothing": a global aggregate over no match (a total of 0).
            $overNothing = $executionResult->data !== [] && $inspector->isAggregateOverNothing($cypher, $executionResult->data);
            if ($executionResult->data !== [] && !$overNothing) {
                return $unchanged;
            }

            $inspector->takeExecutions();
            $userId = $this->pipelineUserId($execOptions);
            $scope = $inspector->scope($cypher, $executionResult, $userId);

            if ($scope === EmptyResultInspector::SCOPE_OWN) {
                $ownership = $inspector->ownershipCheck($cypher, $userId, $execOptions);
            } elseif (!$overNothing && $ctx->qu->exportFormat === null) {
                $fallback = $inspector->sourceFallback($this->queryEntityLabel($cypher, $ctx->qu->entityTypes), $execOptions);
            }
            $executions = $inspector->takeExecutions();
        } catch (\Throwable $e) {
            \Log::warning('Empty-result check failed — keeping the plain empty result: ' . $e->getMessage());

            return $unchanged;
        }

        // A zero total is second-guessed ONLY when it is the user's own and the ownership
        // count PROVES there was nothing to total (they own no such record): then it is not
        // a figure ("your contribution is $0.00") but an empty own-data result with its
        // reason. Any other zero total — the team's, or an owner's real $0 — is the answer.
        $vacuous = $overNothing && $scope === EmptyResultInspector::SCOPE_OWN && ($ownership['count'] ?? null) === 0;

        if (($scope === null && $executions === 0) || ($overNothing && !$vacuous && $executions === 0)) {
            return $unchanged; // nothing established, nothing ran
        }

        // The fallback query as the turn records it: WITH the cap its rows were fetched with
        // (host source queries carry no LIMIT — the executor appends the cap), so a follow-up
        // export re-runs exactly the rows shown, never the whole history behind them.
        $fallbackCypher = $fallback !== null
            ? rtrim(trim($fallback['cypher']), ';') . ' LIMIT ' . $fallback['limit']
            : null;

        $this->emitStage($ctx->observer, 'empty_result_check', array_filter([
            'scope' => $scope,
            'ownership_check' => $ownership,
            'aggregate_over_nothing' => $overNothing ? $vacuous : null,
            'fallback_source' => $fallback['source'] ?? null,
            'fallback_rows' => $fallback !== null ? count($fallback['execution']->data) : null,
            'cypher' => $fallbackCypher,
        ], static fn ($value): bool => $value !== null) + ['executions' => $executions], $this->elapsedMs($start));

        if ($overNothing && !$vacuous) {
            return $unchanged; // a real zero: the data answer stands, unstamped
        }

        if ($fallback === null) {
            $stamp = array_filter(
                [
                    'empty_scope' => $scope,
                    'ownership_check' => $ownership,
                    // The row that was not served as data (trace / grader evidence).
                    'aggregate_over_nothing' => $vacuous ? array_values($executionResult->data) : null,
                ],
                static fn ($value): bool => $value !== null
            );

            return [
                $queryResult,
                new QueryExecutionResult(
                    success: $executionResult->success,
                    data: [],
                    stats: $executionResult->stats,
                    metadata: $executionResult->metadata + $stamp,
                    errors: $executionResult->errors,
                ),
            ];
        }

        /** @var QueryExecutionResult $rows */
        $rows = $fallback['execution'];
        $stamp = ['empty_fallback' => [
            'source' => $fallback['source'],
            'label' => $fallback['label'],
            'fallback_label' => $fallback['fallback_label'],
            'entity' => $fallback['entity'],
            // The source's configured page: attached as the fallback rows' door (ActionAttacher).
            'action_key' => $fallback['action_key'],
        ]];

        return [
            new QueryGenerationResult(
                cypher: $fallbackCypher,
                explanation: $queryResult->explanation,
                confidence: $queryResult->confidence,
                warnings: $queryResult->warnings,
                metadata: $queryResult->metadata + ['empty_fallback' => $fallback['source'], 'pre_fallback_cypher' => $cypher],
                // A host-config query: it binds none of the generated query's `$pii_…` parameters.
                parameters: [],
            ),
            new QueryExecutionResult(
                success: true,
                data: $rows->data,
                stats: $rows->stats,
                metadata: array_merge($rows->metadata, $stamp),
                errors: [],
            ),
        ];
    }

    /**
     * SINGLE RECORD (eval p18-B digest t1, "When was our last meeting then?" — 0.55 in
     * four runs). A one-record lookup showed only what the generator happened to
     * project: the group a meeting belongs to in 1 run of 4, so the answer could not
     * say which group met, and with two displayable columns the
     * code-rendered record block never fired. No prompt line fixed it.
     *
     * When the turn's result is exactly ONE row that is a record of a configured
     * entity and carries that record's id, the row gains the display name of each
     * many-to-one parent the entity config declares ({@see RecordParentEnricher}: one
     * query by id through the same scoped executor and the turn's own options —
     * tenant scoping, read-only, the turn's rate_limit_batch — zero LLM calls) — and,
     * from the same query, the record's own DETAIL fields its entity declares
     * (`metadata.detail_properties`: a meeting's place) that the query did not project.
     * The added columns (`place`, `group_name`) are ordinary result columns from then on:
     * the answer plan, the record block, the chips and the conversation's result sample
     * see them.
     *
     * The turn's QUERY is unchanged (a follow-up extends / re-runs what the generator
     * wrote). Never for a list, an aggregate, an empty result or a collapsed fan-out
     * row. Anything uncertain — no resolvable id, no parent to name, a failed / blocked
     * / empty lookup — leaves the row as returned. Stamped as execution metadata
     * `record_enriched` {entity, columns, details?}; observed as the 'record_enrichment' stage
     * whenever the lookup ran. Disable with ai.query_execution.enrich_single_record=false.
     *
     * The same holds wherever a single record is re-materialized for a FILE: an export
     * of the recorded result re-executes the recorded query (previousResultExportRows)
     * and names the record's parents again, so the file carries what the chat showed.
     */
    private function enrichSingleRecord(
        PipelineContext $ctx,
        QueryGenerationResult $queryResult,
        QueryExecutionResult $executionResult,
        array $execOptions
    ): QueryExecutionResult {
        if (!$queryResult->hasCypher()
            || !$executionResult->success || !empty($executionResult->errors)
            || ($executionResult->metadata['safe_stop'] ?? false) === true
            || isset($executionResult->metadata['fan_out_collapsed'])) {
            return $executionResult;
        }

        $enriched = $this->singleRecordWithParents($ctx, (string) $queryResult->cypher, $executionResult->data, $execOptions);
        if ($enriched === null) {
            return $executionResult;
        }

        return new QueryExecutionResult(
            success: $executionResult->success,
            data: $enriched['rows'],
            stats: $executionResult->stats,
            metadata: $executionResult->metadata + ['record_enriched' => [
                'entity' => $enriched['entity'],
                'columns' => $enriched['columns'],
            ] + ($enriched['details'] !== [] ? ['details' => $enriched['details']] : [])],
            errors: $executionResult->errors,
        );
    }

    /**
     * The ONE row of $rows with the names of its record's parents added
     * ({@see enrichSingleRecord()}), or null when nothing was added. Runs the lookup
     * through the scoped executor with $execOptions and emits the 'record_enrichment'
     * stage whenever it ran. Never throws.
     *
     * @param array<int, mixed> $rows the rows $cypher returned
     * @return array{rows: array<int, mixed>, entity: string, columns: array<string, string>, details: list<string>}|null
     */
    private function singleRecordWithParents(PipelineContext $ctx, string $cypher, array $rows, array $execOptions): ?array
    {
        if (count($rows) !== 1 || !(bool) config('ai.query_execution.enrich_single_record', true)) {
            return null;
        }

        $start = hrtime(true);
        try {
            $enricher = $this->recordParentEnricher ??= new RecordParentEnricher($this->queryExecutor);
            $enricher->takeExecutions();
            $enriched = $enricher->enrich($cypher, $rows, $execOptions, $ctx->qu->entityTypes, $ctx->qu->language !== '' ? $ctx->qu->language : null);
            $ran = $enricher->takeExecutions() > 0;
        } catch (\Throwable $e) {
            \Log::warning('Single-record enrichment failed — keeping the row as returned: ' . $e->getMessage());

            return null;
        }

        if ($enriched === null) {
            if ($ran) {
                $this->emitStage($ctx->observer, 'record_enrichment', ['enriched' => false], $this->elapsedMs($start));
            }

            return null;
        }

        $this->emitStage($ctx->observer, 'record_enrichment', [
            'enriched' => true,
            'entity' => $enriched['entity'],
            'columns' => $enriched['columns'],
        ] + ($enriched['details'] !== [] ? ['details' => $enriched['details']] : []) + [
            'security_filter_applied' => ($enriched['execution']->metadata['security_filter_applied'] ?? false) === true,
            'cypher' => $enriched['cypher'],
        ], $this->elapsedMs($start));

        return [
            'rows' => [array_key_first($rows) => $enriched['row']],
            'entity' => $enriched['entity'],
            'columns' => $enriched['columns'],
            'details' => $enriched['details'],
        ];
    }

    /**
     * The entity a query is ABOUT, structurally: the label of its RETURN anchor
     * ({@see projectionAnchor()}), else — a projection with no plain node reference
     * (aggregates) — its first labelled node. Null when it names no label.
     *
     * @param array<int, string> $targetLabels QU target entity types (anchor tie-break)
     */
    private function queryEntityLabel(string $cypher, array $targetLabels): ?string
    {
        $label = $this->projectionAnchor($cypher, $targetLabels)['label'] ?? null;
        if (is_string($label) && $label !== '') {
            return $label;
        }

        $masked = FanOutCollapse::mask(FanOutCollapse::canonical($cypher) ?? $cypher);

        return preg_match('/\(\s*[A-Za-z_]?\w*\s*:\s*([A-Za-z_]\w*)/', $masked, $m) === 1 ? $m[1] : null;
    }

    /**
     * The id of the pipeline actor (options['user'], else the ambient identity) — the
     * same resolution the stored-query parameterizer uses. Null when there is none.
     */
    private function pipelineUserId(array $options): int|string|null
    {
        foreach ($this->parameterizerFor($options)->resolveParameters() as $parameter) {
            if (($parameter['field'] ?? null) === 'user_id') {
                $value = $parameter['value'] ?? null;

                return is_int($value) || (is_string($value) && $value !== '') ? $value : null;
            }
        }

        return null;
    }

    /**
     * Max values shown per collapsed cell (review I2: a member with 300 payments
     * must not become one unbounded cell). The query fetches one more, so the
     * cell can say it was truncated.
     */
    private function collapseCellLimit(): int
    {
        return max(1, (int) config('ai.query_execution.collapse_cell_limit', 10));
    }

    /**
     * Execute a generated query; an execution EXCEPTION becomes a failed result
     * (returned with the exception, which the repair decision inspects).
     *
     * @return array{0: QueryExecutionResult, 1: ?\Throwable}
     */
    private function runExecution(?string $cypher, array $execOptions, PipelineContext $ctx, string $question, array $parameters = []): array
    {
        try {
            // P-1: `$pii_…` parameters bound from PII tokens (real values, never inlined).
            return [$this->executeWithTrueTotal($cypher, $parameters, $execOptions, $ctx->qu->entityTypes), null];
        } catch (\Throwable $e) {
            \Log::error('Error executing query: ' . $e->getMessage(), ['question' => $question]);
            $ctx->observer->stageError('execution', $e);

            return [
                new QueryExecutionResult(
                    success: false,
                    metadata: ['execution_error' => get_class($e)],
                    errors: [$e->getMessage()],
                ),
                $e,
            ];
        }
    }

    /**
     * ONE regeneration after a repairable execution error (Task 5.5): the generator
     * receives its OWN failed draft + the Neo4j error (never the scoped rewrite),
     * gets a single LLM attempt (the draft is re-validated, schema-checked and
     * subset-checked as usual), and the new draft runs through the SAME scoped
     * executor. Observed as a second query_generation + execution stage pair; the
     * execution stage carries repair_attempted / repair_succeeded.
     *
     * Falls back to the ORIGINAL failure (existing error answer) when regeneration
     * yields nothing new; to the second failure when the repaired query fails too.
     *
     * @return array{0: QueryGenerationResult, 1: QueryExecutionResult}
     */
    private function repairAfterExecutionError(
        PipelineContext $ctx,
        string $question,
        array $options,
        array $execOptions,
        QueryGenerationResult $failedQuery,
        QueryExecutionResult $failedExecution,
        \Throwable $failure
    ): array {
        $repairOptions = array_merge($options, [
            'repair' => [
                'cypher' => (string) $failedQuery->cypher,
                'error' => ExecutionErrorClassifier::feedback($failure),
            ],
            'max_retries' => 1,
        ]);

        $regenerated = $this->observeStage(
            $ctx->observer,
            'query_generation',
            fn (): QueryGenerationResult => $this->generateQueryResult($question, $ctx, $repairOptions),
            fn (QueryGenerationResult $r): array => $this->generationTrace($r) + ['execution_repair' => true]
        );

        $ctx->observer->stageStarted('execution');
        $start = hrtime(true);

        if (!$regenerated->hasCypher() || trim((string) $regenerated->cypher) === trim((string) $failedQuery->cypher)) {
            // Nothing new to run: keep the original failure.
            $ctx->observer->stageFinished(
                'execution',
                $this->executionTrace($failedExecution) + ['repair_attempted' => true, 'repair_succeeded' => false, 'repair_skipped' => true],
                $this->elapsedMs($start)
            );

            return [$failedQuery, $failedExecution];
        }

        [$result, $secondFailure] = $this->runExecution($regenerated->cypher, $execOptions, $ctx, $question, $regenerated->parameters);
        $succeeded = $secondFailure === null && $result->success && empty($result->errors);

        $ctx->observer->stageFinished(
            'execution',
            $this->executionTrace($result) + ['repair_attempted' => true, 'repair_succeeded' => $succeeded],
            $this->elapsedMs($start)
        );

        return [$regenerated, $result];
    }

    /**
     * Store the question->cypher pair as a reusable few-shot example ONLY when the
     * query is proven: it executed without errors, was not safe-stopped by the
     * scoping layer, and returned at least one row (Task 1.9). Storing at
     * generation time let erroring / 0-row / safe-stopped queries become examples
     * that later generations copied (the July 2026 poisoning).
     *
     * Kept exclusions:
     *  - store_examples=false (eval harness): eval runs must not write synthetic
     *    examples into the shared vector store.
     *  - context-dependent turns (QU followUpReference: "convert it to an excel")
     *    are NEVER stored — their question text is meaningless outside this
     *    conversation and the cypher reflects the previous turn's referent.
     *  - user-specific values are parameterized before storing, keyed to the real
     *    pipeline actor ($options['user']) so user A's query never leaks to user B.
     */
    private function storeProvenExample(
        string $question,
        PipelineContext $ctx,
        QueryGenerationResult $queryResult,
        QueryExecutionResult $executionResult,
        array $options
    ): void {
        $proven = $queryResult->hasCypher()
            && $executionResult->success
            && empty($executionResult->errors)
            && ($executionResult->metadata['safe_stop'] ?? false) !== true
            && count($executionResult->data) > 0;

        if (!$proven || $ctx->qu->followUpReference || ($options['store_examples'] ?? true) === false) {
            return;
        }

        // Fix 9: the record-id projection is stored (ids help future generations) unless it
        // met an ambiguous variable — then the generator's own cypher is the example.
        $cypher = ($queryResult->metadata['record_ids_ambiguous'] ?? false) === true
            && is_string($queryResult->metadata['generated_cypher'] ?? null)
            ? $queryResult->metadata['generated_cypher']
            : $queryResult->cypher;

        $this->storeQuery(
            question: $question,
            cypherQuery: $this->parameterizerFor($options)->parameterize($cypher),
            metadata: [
                'confidence' => $queryResult->confidence,
                'generated_at' => now()->toIso8601String(),
            ]
        );
    }

    /** False in docs-only mode (ai.query_generation.enabled) and for an asker whose audience lists no data entity. */
    private function queryGenerationEnabled(mixed $user): bool
    {
        return (bool) config('ai.query_generation.enabled', true) && Audiences::dataEntities($user) !== [];
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
     */
    private function hasAugmentationAnchor(PipelineContext $ctx): bool
    {
        if (!$ctx->qu->followUpReference || $ctx->qu->exportFormat !== null) {
            return false;
        }

        $anchor = $ctx->conversationContext['last_cypher_query'] ?? null;

        return is_string($anchor) && trim($anchor) !== '';
    }

    /**
     * Build the final response array from the pipeline context, observed as the
     * turn's 'composition' stage (data carries the branch that composed it).
     */
    private function buildFinalResponse(string $question, PipelineContext $ctx, array $options): array
    {
        $branch = 'data';

        return $this->observeStage(
            $ctx->observer,
            'composition',
            function () use ($question, $ctx, $options, &$branch): array {
                return $this->composeFinalResponse($question, $ctx, $options, $branch);
            },
            function (array $r) use (&$branch): array {
                return $this->compositionTrace($branch, $r);
            }
        );
    }

    /**
     * The final-response composition itself. $branch receives the composing
     * branch name (observation only).
     */
    private function composeFinalResponse(string $question, PipelineContext $ctx, array $options, string &$branch): array
    {
        $hasFileContext = !empty($ctx->fileContext['relevant_files'] ?? []);
        $hasData = !empty($ctx->executionResult->data);
        $exportIntent = $this->resolveExportIntent($ctx);

        // Actions plan B1–B4: CODE decides the answer's links/actions BEFORE composition,
        // so the narrator is told which chips exist and may reference rows with
        // {{record:N}}. An export turn's action is its download chip — nothing else.
        $ctx->attachedActions = $exportIntent === null ? $this->attachActions($ctx, $options) : AttachedActions::none();

        // An empty result has two very different causes that must NOT be conflated:
        //   1. The query ran and genuinely returned zero rows  -> abstain ("no results").
        //   2. The pipeline could not run at all — the LLM/query layer was unavailable
        //      (circuit breaker OPEN, generation exhausted its retries) or execution
        //      errored. Reporting THAT as "no results were found for your question"
        //      falsely tells the user their data does not exist when the service is
        //      simply down. It must surface as an honest, transient error instead.
        $generationFailed = ($ctx->queryResult->metadata['error'] ?? false) === true;
        $executionFailed = !empty($ctx->executionResult->errors);
        $safeStopped = ($ctx->executionResult->metadata['safe_stop'] ?? false) === true;

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

        // FRESH-QUERY ENRICHMENT (Task 1.27 g, live roster-excel t1): a follow-up
        // export that ADDS fields ("an excel with those members and their contact
        // info") runs a fresh query carrying the previous columns PLUS the new
        // ones. When this turn's query succeeded with rows and its column set is
        // a STRICT SUPERSET of the previous result's, it is the richer version of
        // the same data — export it (current_result). Otherwise the previous result
        // stays authoritative (a re-anchored query shares no/fewer columns).
        // "Requested fields" = the fresh query's columns when it is the same grain
        // (shares a column with the export source): the composer discloses any of
        // them missing from the file instead of presenting "Fields included" as
        // complete.
        // SAME GRAIN = shared column NAMES *and* VALUES (Task 1.28 I1): aliases are
        // LLM-chosen and generic (name, email), so a re-anchored Team query
        // [name, email, phone] is a name-superset of a members list [name, email].
        // The previous rows' shared-column values must reappear in the fresh rows.
        //
        // FAN-OUT TOLERANCE (eval p11 D1): the live extension added a one-to-many
        // column (523 members → 1354 member×contact rows). Its 100-row window held
        // ~40 members, so ">= 90% of the previous 100 members contained" could
        // never pass: the fresh result was discarded and the requested field never
        // disclosed. Now:
        //  (b) SAME POPULATION also proves the grain: the fresh result's record
        //      count (anchor_total, else true_total) equals the previous result's
        //      (total_rows or anchor_total), with at least one shared value tuple
        //      in common (never a coincidental count of unrelated rows);
        //  (a) the exported fresh rows are collapsed by the shared (previous)
        //      columns — one row per previous record, the added columns' distinct
        //      values joined "a; b" (exportCurrentRows);
        //  (d) a previous result kept for an export whose fresh query EXTENDS it
        //      (strict column superset, overlapping values) still discloses the
        //      requested fields it lacks.
        $currentUsable = !$generationFailed && !$executionFailed && $hasData && $currentQueryRan;
        $currentColumns = $currentUsable ? $this->resultColumns($ctx->executionResult->data) : [];
        $preferCurrent = false;
        $requestedFields = [];
        /** @var array<int, string> $grainColumns the previous result's grain the fresh rows collapse to */
        $grainColumns = [];
        if ($previous !== null && $currentColumns !== []) {
            $previousColumns = $this->resultColumns($previous['rows']);
            $shared = array_values(array_intersect($currentColumns, $previousColumns));
            $extends = $shared !== []
                && array_diff($previousColumns, $currentColumns) === []
                && count($currentColumns) > count($previousColumns);
            $sameGrain = $shared !== []
                && ($this->sharedValuesContained($previous['rows'], $ctx->executionResult->data, $shared)
                    || $this->samePopulation(
                        $previous,
                        $ctx->executionResult->metadata,
                        $ctx->executionResult->data,
                        $shared,
                        is_string($ctx->conversationContext['last_cypher_query'] ?? null) ? $ctx->conversationContext['last_cypher_query'] : null,
                        $ctx->queryResult->hasCypher() ? (string) $ctx->queryResult->cypher : null,
                    ));
            $preferCurrent = $sameGrain && $extends;
            if ($preferCurrent) {
                $grainColumns = $shared;
            }
            // Other records (gold t8: tasks shown, then "export the members list"): the request is about this result.
            if ($shared === [] && $this->otherAnchor($ctx, $previous)) {
                $preferCurrent = true;
            }
            if ($sameGrain || ($extends && $this->sharedValuesOverlap($previous['rows'], $ctx->executionResult->data, $shared))) {
                $requestedFields = $currentColumns;
            }
        } elseif ($currentColumns !== []) {
            $requestedFields = $currentColumns;
        } elseif ($previous !== null && !$currentUsable && $ctx->queryResult->hasCypher()) {
            // FAILED EXTENSION (eval p12-1001-A): the fresh query that was to ADD
            // fields failed (twice: "Variable `t` not defined") or returned no rows,
            // so the previous result is exported — and "Fields included: Member
            // Name, Role" read as the complete ask. The requested fields are the
            // failed query's own RETURN columns, structurally (FanOutCollapse::
            // returnColumns), when it EXTENDS the previous result (keeps every
            // previous column, adds at least one); a re-anchored query (fewer /
            // other columns) says nothing about what the user asked to add.
            // QU property_concepts are NOT used: they are concepts ("contact"),
            // not column names, and matching them to columns is semantic.
            $failedColumns = array_values(array_unique(array_filter(array_map(
                static fn (string $c): string => strtolower(trim($c)),
                FanOutCollapse::returnColumns((string) $ctx->queryResult->cypher)
            ), static fn (string $c): bool => $c !== '')));
            $previousColumns = $this->resultColumns($previous['rows']);
            if ($previousColumns !== [] && array_diff($previousColumns, $failedColumns) === []
                && count($failedColumns) > count($previousColumns)) {
                $requestedFields = $failedColumns;
            }
        }

        // Observation only: which export source composed the turn (null = not an export).
        $exportSource = null;
        $exportRows = 0;
        /** @var array<int, mixed> The exported rows (observation only: first 10 ride the export stage). */
        $exportedRows = [];
        $chainStart = hrtime(true);

        if ($exportIntent !== null && $previous !== null && !$preferCurrent
            && ($refersToPrevious || (!$generationFailed && !$executionFailed && !$hasData))) {
            // Export-of-previous: QU says the request refers back to what was
            // just shown (or no fresh data exists) — the recorded result is the
            // authoritative source, not this turn's (possibly re-anchored) query
            // output. Reachable even when this turn's generation/execution
            // failed — but ONLY under QU's follow-up judgment: the new query was
            // never needed, so its failure must not turn into a canned error.
            [$exportSource, $exportRows, $exportedRows] = ['previous_result', count($previous['rows']), $previous['rows']];
            // The query each column set came from (eval p17-B: the file's headers name what
            // a column HOLDS — TableRenderer::headerProjections): the exported rows are the
            // RECORDED query's, the requested fields are this turn's fresh query's.
            $responseResult = $this->exportComposer->generateExportDocumentResponse(
                $question,
                $exportIntent,
                $previous['rows'],
                'previous_result',
                $this->exportSourceMeta($ctx, $previous['meta'] + [
                    'requested_fields' => $requestedFields,
                    'cypher' => is_string($ctx->conversationContext['last_cypher_query'] ?? null) ? $ctx->conversationContext['last_cypher_query'] : null,
                    'requested_cypher' => $ctx->queryResult->hasCypher() ? (string) $ctx->queryResult->cypher : null,
                ], 'previous_result')
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
                // Eval p11 D1c/D1a: re-run past the display LIMIT up to
                // ai.export.document_row_limit when more rows are PROVEN to exist,
                // and collapse a fan-out to the previous result's grain.
                ['rows' => $currentRows, 'meta' => $meta] = $this->exportCurrentRows($question, $ctx, $options, $grainColumns);
                $meta['requested_fields'] = $requestedFields;
                // This turn's own query: its projections head the file's columns (p17-B).
                $meta['cypher'] = $ctx->queryResult->hasCypher() ? (string) $ctx->queryResult->cypher : null;
                [$exportSource, $exportRows, $exportedRows] = ['current_result', count($currentRows), $currentRows];
                $responseResult = $this->exportComposer->generateExportDocumentResponse(
                    $question,
                    $exportIntent,
                    $currentRows,
                    'current_result',
                    $this->exportSourceMeta($ctx, $meta, 'current_result')
                );
            } elseif ($identityRows = $this->exportComposer->identityExportRows($ctx, $options)) {
                [$exportSource, $exportRows, $exportedRows] = ['current_identity', count($identityRows), $identityRows];
                $responseResult = $this->exportComposer->generateExportDocumentResponse(
                    $question,
                    $exportIntent,
                    $identityRows,
                    'current_identity',
                    $this->exportSourceMeta($ctx, ['complete' => true, 'total_rows' => count($identityRows)], 'current_identity')
                );
            } elseif (!$hasFileContext) {
                $exportSource = 'clarification';
                $responseResult = $this->exportComposer->generateExportClarificationResponse(
                    $question,
                    $exportIntent,
                    $ctx->qu->language !== '' ? $ctx->qu->language : null
                );
            } else {
                $exportSource = 'documents';
                $responseResult = $this->generateResponse($ctx, $options);
            }
        } elseif (!$hasData && ($executionFailed || $safeStopped) && $hasFileContext
            && $ctx->qu->questionType !== 'data_query') {
            // PARTIAL ANSWER (review Focus-2): a MIXED question (QU did not class it
            // a pure data_query) whose data part failed or was safe-stopped, with
            // documents retrieved. The documents still answer their half through
            // the normal LLM path — ResultsDataSection tells the model the data
            // part is UNAVAILABLE (never "no data") — and a fixed, localized
            // sentence is appended: that deterministic line, not the prompt, is
            // what guarantees the user is told the data part is missing.
            $branch = 'partial_data';
            $responseResult = $this->generateResponse($ctx, $options);
            $responseResult['answer'] = rtrim((string) ($responseResult['answer'] ?? ''))
                . "\n\n"
                . trans(
                    $safeStopped ? 'ai.error.data_part_blocked' : 'ai.error.data_part_failed',
                    [],
                    $ctx->qu->language !== '' ? $ctx->qu->language : null
                );
            $responseResult['metadata'] = ['status' => $safeStopped ? 'blocked_by_scoping' : 'partial_data_failed']
                + ($responseResult['metadata'] ?? []);
        } elseif (!$hasData && $safeStopped) {
            // SCOPING SAFE-STOP (Task 1.6): the security layer refused to run the
            // query (no valid scoped rewrite) and the executor returned a clean
            // empty result. That is NOT "no data" — rendering the empty-result
            // template here would be a silent wrong answer. Say so honestly, in
            // the QUESTION's language. Reached for a pure data_query or when no
            // documents were retrieved (mixed turns with documents: branch above).
            $branch = 'blocked_by_scoping';
            $responseResult = [
                'answer' => trans(
                    'ai.error.scoping_blocked',
                    [],
                    $ctx->qu->language !== '' ? $ctx->qu->language : null
                ),
                'format' => $options['format'] ?? 'text',
                'metadata' => ['status' => 'blocked_by_scoping'],
            ];
        } elseif (!$hasData && ($executionFailed || ($generationFailed && !$hasFileContext))) {
            // A generation failure WITH documents still answers from the documents
            // (the else branch). An EXECUTION failure reaches here only for a pure
            // data_query or with no documents (mixed turns with documents: the
            // partial-answer branch above) — the canned error is the honest answer.
            // Language policy: an error answer must follow the QUESTION's language,
            // not the app locale — thread the QU-detected language into the
            // error-response options (no in-package language detection lists).
            $branch = 'error';
            $responseResult = $this->responseGenerator->generateErrorResponse(
                $question,
                $this->infrastructureError($ctx),
                $options + ($ctx->qu->language !== '' ? ['question_language' => $ctx->qu->language] : [])
            );
        } elseif (!$hasData && !$hasFileContext && $ctx->qu->failed) {
            // FAILED UNDERSTANDING (eval p11 D3): the QU call itself failed (provider
            // / network error — the provider layer already retried transient
            // failures), so the turn ran on the neutral fallback: no export judgment,
            // no follow-up judgment, no entity focus. An EMPTY outcome built on that
            // is not evidence that nothing exists — answering "No results were found
            // for your request to create a PDF" blamed the user's data for an outage.
            // Say it is a temporary problem instead. A fallback that DID find rows or
            // documents still answers from them (the branches below).
            // Branch 'error': the eval harness labels it from the recorded
            // understanding stage error (infra / stage-error), never as an abstention.
            $branch = 'error';
            $responseResult = [
                'answer' => trans(
                    'ai.error.understanding_unavailable',
                    [],
                    $ctx->qu->language !== '' ? $ctx->qu->language : null
                ),
                'format' => $options['format'] ?? 'text',
                'metadata' => ['status' => 'understanding_failed', 'error' => true],
            ];
        } elseif (!$hasData && !$hasFileContext) {
            // Abstain explicitly when there is nothing to ground an answer on: no rows
            // from the query AND no document context. This is the single no-source path
            // (rather than letting the LLM narrate over an empty result and risk
            // fabricating). generateResponse() is reserved for grounded answers.
            $branch = 'empty_result';
            $emptyScope = $ctx->executionResult->metadata['empty_scope'] ?? null;
            $emptyScope = is_string($emptyScope) ? $emptyScope : null;
            $responseResult = $this->responseGenerator->generateEmptyResponse(
                $question,
                $ctx->queryResult->cypher,
                // Thread the conversation context through: "make a PDF of that" follow-ups
                // reference the PREVIOUS turn and would otherwise dead-end in "no results".
                // question_language: the abstention path renders canned strings (the
                // LLM-failure fallback) — they must follow the QUESTION's language, not
                // the app locale (same policy as generateErrorResponse).
                // checked_*: what the empty query looked for (QU structure), so the
                // abstention states the scope instead of a bare "no results".
                // attached_actions: the chips code attaches (list / create / identity) —
                // the abstention names them instead of offering links of its own.
                $options
                    + [
                        'conversation_context' => $ctx->conversationContext,
                        'attached_actions' => $ctx->attachedActions,
                        // QU judged a navigation ask (own profile / team page): answered by
                        // pointing at the attached page, never "no records were found".
                        'navigation_target' => $ctx->qu->navigationTarget,
                    ]
                    + ($ctx->qu->language !== '' ? ['question_language' => $ctx->qu->language] : [])
                    + [
                        'checked_subject' => $ctx->qu->searchQuery,
                        'checked_entity_types' => $ctx->qu->entityTypes,
                        // The SCOPE of the query that RAN (resolveEmptyResult: 'own' = anchored
                        // on the user, 'team' = tenant-scoped, null = unknown / no query) —
                        // never QU's possessive flag: "our next meeting" ran a team-wide query
                        // and was answered "no meetings linked to you" (eval p16-A).
                        'checked_scope' => $emptyScope,
                        'checked_own_data' => $emptyScope === EmptyResultInspector::SCOPE_OWN,
                        // WHY an own-data result is empty: the ownership count (eval p16-B
                        // rights t2 — "you have no record linked", stated as a fact).
                        'ownership_check' => is_array($ctx->executionResult->metadata['ownership_check'] ?? null)
                            ? $ctx->executionResult->metadata['ownership_check']
                            : null,
                    ]
                    // Docs only: nothing was looked up — no document answers, no record was checked.
                    + (($ctx->queryResult->metadata['generation_skipped'] ?? null) === 'docs_only' ? ['docs_only' => true] : []),
            );
        } else {
            $source = $ctx->queryResult->metadata['source'] ?? null;
            $branch = match (true) {
                $source === 'file_context' && ($ctx->queryResult->metadata['intent'] ?? null) === 'file_listing' => 'file_listing',
                $source === 'file_context' => 'knowledge_query',
                default => 'data',
            };
            $responseResult = $this->generateResponse($ctx, $options);
        }

        if ($exportSource === null && in_array($branch, ['data', 'knowledge_query', 'file_listing', 'empty_result', 'partial_data'], true)) {
            $responseResult = $this->withAttachedActions($responseResult, $ctx->attachedActions);
        }

        if ($exportSource !== null) {
            $branch = 'export';
            $this->emitStage($ctx->observer, 'export', [
                'export_source' => $exportSource,
                'format' => $exportIntent['format'] ?? null,
                'rows' => $exportRows,
                // In-memory observers only: MessageTraceObserver never persists exported rows.
                'sample' => array_slice(array_values($exportedRows), 0, 10),
            ], $this->elapsedMs($chainStart));
        }

        if (!empty($ctx->fileContext)) {
            $responseResult = $this->enrichResponseWithFiles($responseResult, $ctx->fileContext, $options);
        }

        $answer = $responseResult['answer'];
        // The turn's own execution — or, for an export of the recorded result, the scoped
        // re-execution that produced the file's rows (a turn that skipped generation ran
        // no query of its own).
        $securityFilterApplied = ($ctx->executionResult->metadata['security_filter_applied'] ?? false)
            || ($exportSource === 'previous_result' && ($previous['scoped'] ?? false) === true);
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
            'cypher' => $ctx->queryResult->cypher,
            // P-1: the `$pii_…` parameters the cypher references (real values, the
            // user's own data). Recorded in the conversation snapshot so a follow-up
            // that re-executes or EXTENDS this query can bind them again.
            'cypher_parameters' => $ctx->queryResult->parameters,
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
                // The localized subject_label (eval p12-B), so that later export is
                // titled in the language of the turn that produced the rows;
                // search_query only when QU gave no label.
                'subject' => $ctx->qu->displaySubject() !== '' ? $ctx->qu->displaySubject() : null,
                // QU's entity types + intent of THIS turn (Phase 2.B): the
                // conversation snapshot's focus / last_query_type on turns that
                // executed no cypher come from these — never from regex over
                // the question text.
                'entity_types' => $ctx->qu->entityTypes,
                'intent' => $ctx->qu->intent !== 'other' ? $ctx->qu->intent : null,
            ] + $this->quUnavailableMetadata($ctx),
        ];
    }

    /**
     * When the QU call FAILED, its export judgment is UNKNOWN — not "no export
     * ask". Stamped so evals / callers can tell the two apart (there is no
     * word-list fallback to guess it; Phase 2.B).
     *
     * @return array<string, string>
     */
    private function quUnavailableMetadata(PipelineContext $ctx): array
    {
        return $ctx->qu->failed ? ['export_intent_unavailable' => 'qu_failed'] : [];
    }

    /**
     * Resolve the export intent for this turn — QU ONLY (Phase 2.B).
     *
     * The QU LLM call judges, semantically, whether the user asked to receive
     * the information as a downloadable file and in which format
     * (QueryUnderstandingResult::$exportFormat; parseExportFormat already maps
     * the xlsx/xls format-code aliases). The former EN/FR verb + format regex
     * fallback (ExportResponseComposer::detectExportIntent) was deleted: a turn
     * whose QU call failed answers in chat instead of guessing a file ask from
     * word lists.
     *
     * @return array{format: string}|null
     */
    private function resolveExportIntent(PipelineContext $ctx): ?array
    {
        return $ctx->qu->exportFormat !== null ? ['format' => $ctx->qu->exportFormat] : null;
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
     * @return array{rows: array<int, array<string, mixed>>, meta: array{complete: bool, total_rows: int}, scoped?: bool}
     *         scoped: the tenant filter ran on the re-execution (absent for the sample fallback)
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

                // P-1: a recorded query bound PII tokens as `$pii_…` parameters. Only
                // keys of that exact shape are honoured (review #5): the snapshot is
                // stored data, so nothing else (e.g. a forged `ai_team_ids`) may ride
                // into the re-execution or its companion counts.
                $recordedParams = array_filter(
                    is_array($conversation['last_cypher_parameters'] ?? null) ? $conversation['last_cypher_parameters'] : [],
                    static fn ($value, $key): bool => is_string($key)
                        && preg_match('/^pii_[a-f0-9]{12}$/', $key) === 1
                        && is_string($value),
                    ARRAY_FILTER_USE_BOTH
                );
                // Eval p11 D1c: the recorded query carries the DISPLAY cap
                // (`LIMIT 100`, or the executor's default) — the file was "100 of
                // 525". Re-run it without that cap, up to
                // ai.export.document_row_limit. A LIMIT below the display cap is
                // the user's ask ("top 5") and is kept, as is a page (SKIP); an
                // ordered list at the cap keeps its ORDER BY, only the cap widens.
                $executorCap = (int) ($options['limit'] ?? config('ai.query_execution.default_limit', 100));
                $documentLimit = $this->exportDocumentRowLimit($ctx);
                $uncapped = $documentLimit > $executorCap ? $this->withoutDisplayLimit($cypher, $executorCap, $documentLimit) : null;
                if ($uncapped !== null) {
                    $cypher = $uncapped;
                    $execOptions['limit'] = $documentLimit;
                    $executorCap = $documentLimit;
                }

                $result = $this->queryExecutor->execute($cypher, $recordedParams, $execOptions);

                if (empty($result->errors) && !empty($result->data)) {
                    // Same executor-cap honesty as the cold path, upgraded by the
                    // companion count: on a cap-hit re-execution the TRUE total is
                    // established via executeCount (same scoped options); when it
                    // succeeds, total_rows is the real number and complete is
                    // computed honestly. On failure the true total stays unknown —
                    // never claim complete.
                    // A recorded per-anchor (collapsed) query returns list columns.
                    $rows = FanOutCollapse::joinLists($result->data, FanOutCollapse::cellLimitOf($cypher));
                    // A single recorded record is exported as the chat showed it: with the
                    // names of the parents it belongs to (enrichSingleRecord()).
                    $rows = $this->singleRecordWithParents($ctx, $cypher, $rows, $execOptions)['rows'] ?? $rows;
                    $rowsOut = count($rows);
                    // Whether the tenant filter ran on this re-execution: the turn's
                    // security_filter_applied when it executed no query of its own.
                    $scoped = ($result->metadata['security_filter_applied'] ?? false) === true;
                    $trueTotal = $this->companionTrueTotal($cypher, $recordedParams, $execOptions, $rowsOut);
                    if ($trueTotal !== null) {
                        // The distinct-anchor count rides along (p11 D1b): a fresh
                        // extension is the same population when its record count
                        // equals this one (samePopulation).
                        $anchor = $this->companionAnchorTotal($cypher, $recordedParams, $execOptions, $ctx->qu->entityTypes) ?? [];

                        return [
                            'rows' => $rows,
                            'meta' => ['complete' => $trueTotal <= $rowsOut, 'total_rows' => $trueTotal]
                                + array_intersect_key($anchor, ['anchor_total' => true, 'anchor_label' => true]),
                            'scoped' => $scoped,
                        ];
                    }

                    return [
                        'rows' => $rows,
                        'meta' => ['complete' => $rowsOut < $executorCap, 'total_rows' => max($rowsOut, $recordedCount)],
                        'scoped' => $scoped,
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
     * Normalized column keys of a result (union over rows, lower-cased, in first-
     * seen order), excluding ResponseEntityEnricher::DECORATION_KEYS (enrichment
     * keys + `_label`) — decorations, not query columns.
     *
     * @param array<int, mixed> $rows
     * @return array<int, string>
     */
    private function resultColumns(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $key) {
                $normalized = strtolower(trim((string) $key));
                if ($normalized !== '' && !in_array($normalized, ResponseEntityEnricher::DECORATION_KEYS, true)) {
                    $columns[$normalized] = true;
                }
            }
        }

        return array_keys($columns);
    }

    /** The export row limit for THIS turn's format (ExportResponseComposer::documentRowLimit). */
    private function exportDocumentRowLimit(PipelineContext $ctx): int
    {
        return ExportResponseComposer::documentRowLimit($this->resolveExportIntent($ctx)['format'] ?? null);
    }

    /**
     * The query without its DISPLAY cap and with an EXPLICIT `LIMIT
     * $documentLimit`, or null when it must not be widened
     * (FanOutCollapse::uncappedQuery). A trailing `LIMIT n` with n >= the
     * executor's display cap IS the cap (the generator's habitual LIMIT 100) and
     * is replaced, even after ORDER BY (ordering kept); a smaller LIMIT is the
     * user's own top-N, SKIP is a page (review S3) → null. The document
     * LIMIT is always written (review I1): the executor only appends its own
     * when no `LIMIT` word appears anywhere in the text.
     */
    private function withoutDisplayLimit(string $cypher, int $displayCap, int $documentLimit): ?string
    {
        return FanOutCollapse::uncappedQuery($cypher, $displayCap, $documentLimit);
    }

    /**
     * The rows (and honest completeness meta) a CURRENT-result export writes.
     *
     * (c) When the companion count PROVED more rows exist than the display window
     * holds, the turn's query is re-run without its display LIMIT, up to
     * ai.export.document_row_limit, through the same scoped executor (eval p11:
     * the file held 100 of 523). Any failure keeps the display rows.
     * (a) $grainColumns (the previous result's columns this export extends): rows
     * are collapsed to one per previous record, added columns' values joined —
     * keyed on the anchor's record id column, never on displayed values alone
     * (two members sharing a name are two records); without that id, or when the
     * rows were already collapsed per anchor at execution, no regrouping. A
     * capped fetch may cut the LAST record's joined rows, so that group is
     * dropped rather than exported partial.
     *
     * @param array<int, string> $grainColumns
     * @return array{rows: array<int, mixed>, meta: array{complete: bool, total_rows: int}}
     */
    private function exportCurrentRows(string $question, PipelineContext $ctx, array $options, array $grainColumns): array
    {
        $rows = $ctx->executionResult->data;
        $rowsOut = count($rows);
        $executorCap = (int) ($options['limit'] ?? config('ai.query_execution.default_limit', 100));
        $trueTotal = $ctx->executionResult->metadata['true_total'] ?? null;
        $anchorTotal = $ctx->executionResult->metadata['anchor_total'] ?? null;
        $cap = $executorCap;

        $documentLimit = $this->exportDocumentRowLimit($ctx);
        if (is_numeric($trueTotal) && (int) $trueTotal > $rowsOut && $documentLimit > $rowsOut && $ctx->queryResult->hasCypher()) {
            $uncapped = $this->withoutDisplayLimit((string) $ctx->queryResult->cypher, $executorCap, $documentLimit);
            if ($uncapped !== null) {
                try {
                    // Same identity/provenance threading as generateAndExecute().
                    $rerun = $this->queryExecutor->execute($uncapped, $ctx->queryResult->parameters, array_merge($options + [
                        'original_question' => $question,
                        'conversation_id' => $options['conversation_id'] ?? null,
                    ], ['limit' => $documentLimit]));
                    if ($rerun->success && empty($rerun->errors) && count($rerun->data) > $rowsOut) {
                        $rows = FanOutCollapse::joinLists($rerun->data, FanOutCollapse::cellLimitOf($uncapped));
                        $cap = $documentLimit;
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Export re-run up to the document row limit failed — exporting the display rows: ' . $e->getMessage());
                }
            }
        }

        $fetched = count($rows);
        $capHit = $fetched >= $cap;
        $meta = is_numeric($trueTotal) && (int) $trueTotal >= $fetched
            ? ['complete' => (int) $trueTotal <= $fetched, 'total_rows' => (int) $trueTotal]
            : ['complete' => !$capHit, 'total_rows' => $fetched];

        // Review I4: grouping by DISPLAYED values merged distinct records that
        // share them (two "John Smith, owner" → one row with both people's
        // contacts). Rows already collapsed per anchor at execution are one row
        // per record — never regrouped. Otherwise only the anchor's record id
        // (RecordIdProjection's `<label>_id`) may key the grouping; without it
        // the rows are exported as fetched (one per joined record).
        $identityColumn = $this->anchorIdColumn($ctx, $rows);
        if ($grainColumns !== [] && !isset($ctx->executionResult->metadata['fan_out_collapsed']) && $identityColumn !== null) {
            $collapsed = FanOutCollapse::collapseByColumns($rows, $grainColumns, $identityColumn);
            if (count($collapsed) < $fetched) {
                if (!$meta['complete'] && count($collapsed) > 1) {
                    array_pop($collapsed);
                }
                $meta = $meta['complete']
                    ? ['complete' => true, 'total_rows' => count($collapsed)]
                    : ['complete' => false, 'total_rows' => is_numeric($anchorTotal) && (int) $anchorTotal >= count($collapsed) ? (int) $anchorTotal : count($collapsed)];
                $rows = $collapsed;
            }
        }

        return ['rows' => $rows, 'meta' => $meta];
    }

    /**
     * The normalized id column of the current result's ANCHOR record
     * (`<snake label>_id`, as RecordIdProjection projects it) when every row
     * carries it, else null. The anchor label is the companion count's, else the
     * one the query's own final RETURN names.
     *
     * @param array<int, mixed> $rows
     */
    private function anchorIdColumn(PipelineContext $ctx, array $rows): ?string
    {
        $label = $ctx->executionResult->metadata['anchor_label'] ?? null;
        if (!is_string($label) || $label === '') {
            $label = $ctx->queryResult->hasCypher()
                ? ($this->projectionAnchor((string) $ctx->queryResult->cypher, $ctx->qu->entityTypes)['label'] ?? null)
                : null;
        }
        if (!is_string($label) || $label === '' || $rows === []) {
            return null;
        }

        $column = FanOutCollapse::idColumnFor($label);
        foreach ($rows as $row) {
            $keys = is_array($row) ? array_map(static fn ($k): string => strtolower(trim((string) $k)), array_keys($row)) : [];
            if (!in_array($column, $keys, true)) {
                return null;
            }
        }

        return $column;
    }

    /**
     * SAME POPULATION (eval p11 D1b): the fresh result's record count — its
     * distinct-anchor count, else its row total — equals the previous result's
     * (row total or distinct-anchor count), the anchors carry the SAME label, and
     * the windows share a NON-TRIVIAL overlap of non-empty shared value tuples
     * (SAME_POPULATION_MIN_OVERLAP rows, or half the previous window when it is
     * smaller), so an unrelated query whose count happens to match never passes.
     * Truncated windows of one population need not contain each other; equal
     * populations do not depend on the window.
     *
     * Review I4: a label is REQUIRED on both sides — the companion count's, else
     * the anchor the query's own final RETURN names ($previousCypher /
     * $freshCypher); unknown on either side → not proven. An all-empty shared
     * tuple (null names) never counts as an overlap.
     *
     * @param array{rows: array<int, mixed>, meta: array<string, mixed>} $previous
     * @param array<string, mixed> $freshMeta
     * @param array<int, mixed> $freshRows
     * @param array<int, string> $sharedColumns
     */
    private function samePopulation(
        array $previous,
        array $freshMeta,
        array $freshRows,
        array $sharedColumns,
        ?string $previousCypher = null,
        ?string $freshCypher = null
    ): bool {
        $fresh = $freshMeta['anchor_total'] ?? $freshMeta['true_total'] ?? null;
        if (!is_numeric($fresh) || (int) $fresh <= 0) {
            return false;
        }

        $previousTotals = array_map('intval', array_filter(
            [$previous['meta']['total_rows'] ?? null, $previous['meta']['anchor_total'] ?? null],
            'is_numeric'
        ));
        if (!in_array((int) $fresh, $previousTotals, true)) {
            return false;
        }

        $freshLabel = $freshMeta['anchor_label'] ?? null;
        if ((!is_string($freshLabel) || $freshLabel === '') && $freshCypher !== null) {
            $freshLabel = $this->projectionAnchor($freshCypher)['label'] ?? null;
        }
        $previousLabel = $previous['meta']['anchor_label'] ?? null;
        if ((!is_string($previousLabel) || $previousLabel === '') && $previousCypher !== null) {
            $previousLabel = $this->projectionAnchor($previousCypher)['label'] ?? null;
        }
        if (!is_string($freshLabel) || !is_string($previousLabel) || $freshLabel === '' || $freshLabel !== $previousLabel) {
            return false;
        }

        if ($sharedColumns === []) {
            return false;
        }
        ['total' => $total, 'contained' => $contained] = $this->containedShare($previous['rows'], $freshRows, $sharedColumns);

        return $total > 0
            && ($contained >= min(self::SAME_POPULATION_MIN_OVERLAP, $total) || $contained / $total >= 0.5);
    }

    /** Non-empty shared tuples two windows of one population must have in common. */
    private const SAME_POPULATION_MIN_OVERLAP = 5;

    /**
     * Whether the request is about other records than the previous result: this turn's result anchors on
     * another record type (companion count or final RETURN) AND the entity types QU read in the request
     * leave the previous one out. "Export it" names nothing, so a re-anchored query never wins on its own.
     *
     * @param array{rows: array<int, mixed>, meta: array<string, mixed>} $previous
     */
    private function otherAnchor(PipelineContext $ctx, array $previous): bool
    {
        if ($ctx->qu->entityTypes === []) {
            return false;
        }
        $fresh = $ctx->executionResult->metadata['anchor_label'] ?? null;
        if ((!is_string($fresh) || $fresh === '') && $ctx->queryResult->hasCypher()) {
            $fresh = $this->projectionAnchor((string) $ctx->queryResult->cypher)['label'] ?? null;
        }
        $last = $ctx->conversationContext['last_cypher_query'] ?? null;
        $before = $previous['meta']['anchor_label'] ?? null;
        if ((!is_string($before) || $before === '') && is_string($last)) {
            $before = $this->projectionAnchor($last)['label'] ?? null;
        }

        return is_string($fresh) && is_string($before) && $fresh !== '' && $before !== '' && $fresh !== $before
            && !in_array($before, $ctx->qu->entityTypes, true);
    }

    /**
     * Whether at least one previous row's non-empty shared-column value tuple
     * appears among the current rows' tuples (same normalization as
     * sharedValuesContained). Disclosure only (requested fields), never a source
     * decision.
     *
     * @param array<int, mixed> $previousRows
     * @param array<int, mixed> $currentRows
     * @param array<int, string> $sharedColumns
     */
    private function sharedValuesOverlap(array $previousRows, array $currentRows, array $sharedColumns): bool
    {
        return $sharedColumns !== [] && $this->containedShare($previousRows, $currentRows, $sharedColumns)['contained'] > 0;
    }

    /** Share of previous rows whose shared-column values must reappear in the fresh rows. */
    private const SAME_GRAIN_MIN_CONTAINED = 0.9;

    /**
     * Whether at least SAME_GRAIN_MIN_CONTAINED of the previous rows' value tuples
     * on the shared columns appear among the current rows' tuples. Values are
     * compared as trimmed, case-insensitive strings; column keys are matched
     * after the same normalization resultColumns() applies.
     *
     * @param array<int, mixed> $previousRows
     * @param array<int, mixed> $currentRows
     * @param array<int, string> $sharedColumns normalized column keys
     */
    private function sharedValuesContained(array $previousRows, array $currentRows, array $sharedColumns): bool
    {
        ['total' => $total, 'contained' => $contained] = $this->containedShare($previousRows, $currentRows, $sharedColumns);

        return $total > 0 && $contained / $total >= self::SAME_GRAIN_MIN_CONTAINED;
    }

    /**
     * How many previous rows (of how many) have their shared-column value tuple
     * among the current rows' tuples. A tuple whose shared values are ALL empty
     * (null / blank) identifies nothing and is ignored on both sides (review I4:
     * "" == "" made two unrelated null-name windows overlap trivially).
     *
     * @param array<int, mixed> $previousRows
     * @param array<int, mixed> $currentRows
     * @param array<int, string> $sharedColumns normalized column keys
     * @return array{total: int, contained: int}
     */
    private function containedShare(array $previousRows, array $currentRows, array $sharedColumns): array
    {
        $tuple = function (array $row) use ($sharedColumns): ?string {
            $normalized = [];
            foreach ($row as $key => $value) {
                $normalized[strtolower(trim((string) $key))] = $value;
            }
            $parts = [];
            foreach ($sharedColumns as $column) {
                $value = $normalized[$column] ?? null;
                $value = is_scalar($value) || $value === null
                    ? (string) $value
                    : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
                $parts[] = mb_strtolower(trim($value));
            }

            return implode('', $parts) === '' ? null : implode("\x1F", $parts);
        };

        $current = [];
        foreach ($currentRows as $row) {
            if (is_array($row) && ($key = $tuple($row)) !== null) {
                $current[$key] = true;
            }
        }

        $total = 0;
        $contained = 0;
        foreach ($previousRows as $row) {
            $key = is_array($row) ? $tuple($row) : null;
            if ($key === null) {
                continue;
            }
            $total++;
            if (isset($current[$key])) {
                $contained++;
            }
        }

        return ['total' => $total, 'contained' => $contained];
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
        // QU subject — its localized subject_label (user-visible, the question's
        // language; eval p12-B), search_query only when QU gave no label.
        if ($source === 'previous_result') {
            $subject = $conversation['last_subject'] ?? null;
        } else {
            $subject = $ctx->qu->displaySubject() !== '' ? $ctx->qu->displaySubject() : null;
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
            // Observation only: "retrieval errored" must not look like "no documents".
            PipelineObserverScope::current()->stageError('file_context', $e);
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

        // A follow-up on the DATA result the user was just shown ("give me that as an
        // Excel") operates on those records, never on the document list — structural
        // signals only (QU follow_up_reference + a recorded result). Eval p10-A: both
        // export follow-ups came back intent=file_listing, enumeration answered with
        // 50 files and no query ran.
        if ($ctx->qu->followUpReference && !empty($ctx->conversationContext['last_result_sample'] ?? [])) {
            return false;
        }

        // Asking to RECEIVE something as a file is not asking which files one has; QU stamps both
        // file_listing (live 2026-10-26: every new export request), and the listing hid the documents.
        if ($ctx->qu->exportFormat !== null) {
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
            PipelineObserverScope::current()->stageError('file_context', $e);
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

        // Enrichment only decorates rows with action chips — a failure here (live:
        // an int `type` column hit a strict-typed lookup) must never end the turn.
        // Log and continue with the un-enriched rows. Covers every call site
        // (main query and digest sources).
        try {
            $data = $this->responseEntityEnricher->enrichEntityResults($executionResult->data);
        } catch (\Throwable $e) {
            \Log::warning('Entity-action enrichment failed — continuing with un-enriched rows: ' . $e->getMessage(), [
                'exception' => $e, // the object, so the log keeps the stack trace
            ]);
            return $executionResult;
        }

        return new QueryExecutionResult(
            success: $executionResult->success,
            data: $data,
            stats: $executionResult->stats,
            metadata: $executionResult->metadata,
            errors: $executionResult->errors,
        );
    }

    /**
     * B1: a generated query whose final RETURN uses records without their id gets the id
     * projected (RecordIdProjection — structural, MATCH/WHERE untouched), so code can attach
     * each record's link. Labels = the entities that have actions. Never breaks a turn.
     */
    private function withRecordIds(QueryGenerationResult $result): QueryGenerationResult
    {
        if (!$result->hasCypher() || !config('ai.attached_actions.project_record_ids', true)) {
            return $result;
        }

        try {
            $labels = app(\Condoedge\Ai\Services\Actions\AiActionRegistry::class)->entityLabels();
            ['cypher' => $cypher, 'ambiguous' => $ambiguous] = (new \Condoedge\Ai\Services\Actions\Attach\RecordIdProjection($labels))
                ->project((string) $result->cypher);
        } catch (\Throwable $e) {
            return $result;
        }

        if ($cypher === $result->cypher) {
            return $result;
        }

        // An AMBIGUOUS projection (a record variable left without its id because two labels
        // were in scope) runs, but the few-shot store keeps the generator's original
        // (storeProvenExample) — only clean projections teach future generations.
        return new QueryGenerationResult(
            cypher: $cypher,
            explanation: $result->explanation,
            confidence: $result->confidence,
            warnings: $result->warnings,
            metadata: $result->metadata + ['record_ids_projected' => true]
                + ($ambiguous ? ['record_ids_ambiguous' => true, 'generated_cypher' => $result->cypher] : []),
            parameters: $result->parameters,
        );
    }

    /**
     * The turn's code-attached links/actions (B1–B3). Decoration only: a failure is
     * logged and the turn continues with no chips.
     */
    private function attachActions(PipelineContext $ctx, array $options): AttachedActions
    {
        try {
            return $this->actionAttacher->forTurn($ctx, $options);
        } catch (\Throwable $e) {
            \Log::warning('Action attachment failed — continuing without chips: ' . $e->getMessage(), ['exception' => $e]);

            return AttachedActions::none();
        }
    }

    /**
     * Persist the attached chips on the response (metadata.invocations — what
     * AskQuestionUseCase stores on the assistant message and MessageBubbleRenderer
     * re-dispatches with a FRESH authorize at every render). Placeholder substitution
     * and link filtering already ran on the NARRATIVE inside the composer — never here,
     * on the finished answer, where a table cell's record content would be touched.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function withAttachedActions(array $response, ?AttachedActions $attached): array
    {
        if ($attached === null || $attached->isEmpty()) {
            return $response;
        }

        // The ONE assembly every branch shares (data, knowledge, digest, clarification).
        $response['metadata'] = $attached->applyTo(is_array($response['metadata'] ?? null) ? $response['metadata'] : []);

        return $response;
    }

    // =========================================================================
    // Pipeline observation (Phase 4.1) — observation only, never read back
    // =========================================================================

    /**
     * Run $fn as one observed stage: stageStarted, then stageFinished with the
     * summary of the result and the wall time. A throwing stage finishes with
     * ['failed' => true] and the exception propagates UNCHANGED — the catch site
     * that owns recovery emits stageError (so each error is reported once).
     */
    private function observeStage(PipelineObserverInterface $observer, string $stage, callable $fn, ?callable $summarize = null): mixed
    {
        $observer->stageStarted($stage);
        $start = hrtime(true);

        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $observer->stageFinished($stage, ['failed' => true], $this->elapsedMs($start));
            throw $e;
        }

        $data = [];
        if ($summarize !== null) {
            try {
                $data = $summarize($result);
            } catch (\Throwable $e) {
                // A summary bug must never cost the turn.
                $data = ['summary_error' => $e->getMessage()];
            }
        }
        $observer->stageFinished($stage, $data, $this->elapsedMs($start));

        return $result;
    }

    /**
     * The retrieved context with this turn's observer, conversation and page context.
     */
    private function withTurnContext(PipelineContext $ctx, PipelineObserverInterface $observer, array $options): PipelineContext
    {
        $ctx->observer = $observer;

        if (!empty($options['conversation_context'])) {
            $ctx->conversationContext = $options['conversation_context'];
        }

        // Page context is a soft prior — which page/record the chat was opened from.
        // Symmetric to conversation_context; default-empty keeps the prompt section
        // silent and adds no retrieval/entity-selection bias when absent.
        if (!empty($options['page_context'])) {
            $ctx->pageContext = $options['page_context'];
        }

        return $ctx;
    }

    /**
     * An already-decided (instantaneous) stage: started + finished.
     */
    private function emitStage(PipelineObserverInterface $observer, string $stage, array $data = [], float $ms = 0.0): void
    {
        $observer->stageStarted($stage);
        $observer->stageFinished($stage, $data, $ms);
    }

    private function elapsedMs(int|float $start): float
    {
        return round((hrtime(true) - $start) / 1e6, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function understandingTrace(PipelineContext $ctx): array
    {
        $trace = [
            'qu' => $ctx->qu->toArray(),
            'selected_entities' => array_values((array) ($ctx->selectionInfo['selected_entities'] ?? [])),
            'selection_method' => $ctx->selectionInfo['method'] ?? null,
            // Task 5.10/5.11: why each label is in the prompt, and which calculation(s) matched.
            'selection_tiers' => (array) ($ctx->selectionInfo['selection_tiers'] ?? []),
            'matched_calculations' => array_keys((array) ($ctx->selectionInfo['calculations']['matched'] ?? [])),
            'similar_queries' => count($ctx->similarQueries),
            'quality' => $ctx->quality,
            'errors' => $ctx->errors,
        ];
        // Silent degradations of this turn so far (semantic selection → keyword
        // matching, entity labels → full schema), with the store's own reason.
        $fallbacks = PipelineObserverScope::fallbacks();
        if ($fallbacks !== []) {
            $trace['fallbacks'] = $fallbacks;
        }

        return $trace;
    }

    /**
     * Compact view of the retrieved references: id, chunk, scores, source.
     *
     * @return array<string, mixed>
     */
    private function fileContextTrace(PipelineContext $ctx): array
    {
        $files = [];
        foreach ((array) ($ctx->fileContext['relevant_files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $entry = [
                'id' => $file['file_id'] ?? $file['filename'] ?? null,
                'chunk_index' => isset($file['chunk_index']) ? (int) $file['chunk_index'] : null,
                'relevance' => is_numeric($file['relevance'] ?? null) ? (float) $file['relevance'] : null,
                'source' => $file['source'] ?? null,
            ];
            if (is_numeric($file['chunk_relevance'] ?? null)) {
                $entry['chunk_relevance'] = (float) $file['chunk_relevance'];
            }
            if (is_array($file['audience'] ?? null)) {
                $entry['audience'] = $file['audience'];
            }
            // Which search angles picked the passage (the user's wording among them or not).
            if (is_array($file['angle_groups'] ?? null)) {
                $entry['angle_groups'] = array_values($file['angle_groups']);
            }
            // The grounding text itself (in-memory observers only — the persisted
            // MessageTraceObserver strips it: document content never lands in the DB trace).
            $entry['snippet'] = (string) ($file['snippet'] ?? '');
            $files[] = $entry;
        }

        return ['file_count' => count($files), 'files' => $files];
    }

    /**
     * @return array<string, mixed>
     */
    private function generationTrace(QueryGenerationResult $result): array
    {
        $metadata = $result->metadata;

        $trace = [
            'cypher' => $result->cypher,
            'confidence' => $result->confidence,
            'attempts' => (int) ($metadata['retry_count'] ?? 0) + 1,
            'template' => $metadata['template_used'] ?? null,
            'warnings' => $result->warnings,
            // Task 5.4: what the final draft still gets wrong against the live graph
            // schema (after in-budget repairs), and how many schema repairs ran.
            'schema_violations' => array_values((array) ($metadata['schema_violations'] ?? [])),
            'repair_attempts' => (int) ($metadata['repair_attempts'] ?? 0),
            // Eval p22-1001-B: predicates that mixed OR with AND at one level — those
            // sent back for parentheses (a repair fired), and those the final draft
            // still has (repair budget spent; the draft ran as written).
            'precedence_repaired' => array_values((array) ($metadata['precedence_repaired'] ?? [])),
            'precedence_violations' => array_values((array) ($metadata['precedence_violations'] ?? [])),
        ];
        if (($metadata['error'] ?? false) === true) {
            $trace['error'] = (string) ($metadata['error_message'] ?? 'generation failed');
        }

        return $trace;
    }

    /**
     * @return array<string, mixed>
     */
    private function executionTrace(QueryExecutionResult $result): array
    {
        return [
            'success' => $result->success,
            'row_count' => count($result->data),
            'true_total' => $result->metadata['true_total'] ?? null,
            'anchor_total' => $result->metadata['anchor_total'] ?? null,
            'safe_stop' => ($result->metadata['safe_stop'] ?? false) === true,
            'security_filter_applied' => ($result->metadata['security_filter_applied'] ?? false) === true,
            'errors' => array_values(array_map(
                static fn ($e): string => is_array($e) ? (string) ($e['message'] ?? json_encode($e)) : (string) $e,
                $result->errors
            )),
        ];
    }

    /**
     * @param array<string, mixed> $response A final response or a raw generator response.
     * @return array<string, mixed>
     */
    private function compositionTrace(string $branch, array $response): array
    {
        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $status = $metadata['response']['status'] ?? $metadata['status'] ?? null;

        return [
            'branch' => $branch,
            'status' => is_string($status) ? $status : null,
            'answer_length' => mb_strlen((string) ($response['answer'] ?? '')),
            'referenced_files' => count((array) ($response['referenced_files'] ?? [])),
        ] + (is_string($metadata['export_intent_unavailable'] ?? null)
            ? ['export_intent_unavailable' => $metadata['export_intent_unavailable']]
            : [])
            // Phase 8-B: the one validation pass (violation types first/final, retry, patches).
            + (is_array($metadata['validation'] ?? null) ? ['validation' => $metadata['validation']] : []);
    }

    // =========================================================================
    // Security Methods
    // =========================================================================
}
