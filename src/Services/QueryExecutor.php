<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Exceptions\QueryTimeoutException;
use Condoedge\Ai\Exceptions\ReadOnlyViolationException;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Models\AiQueryLog;
use Condoedge\Ai\Services\Resilience\RateLimiter;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Services\Security\CypherCanonicalizer;
use Condoedge\Ai\Services\Security\CypherLexer;
use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\CypherStructuralValidator;
use Condoedge\Ai\Services\Security\ScopeAcceptanceGate;
use Condoedge\Ai\Services\Security\SecurityFilterInjector;
use Condoedge\Ai\Services\Security\SensitiveLabelCoverageGate;
use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Services\Security\Scope\FilterClause;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\Scope\Scope;
use Condoedge\Ai\Services\Security\Scope\ScopePlan;
use Illuminate\Support\Facades\Log;

/**
 * Query Executor Service
 *
 * Executes Cypher queries against Neo4j with safety measures,
 * timeout protection, result formatting, and comprehensive error handling.
 *
 * @package Condoedge\Ai\Services
 */
class QueryExecutor implements QueryExecutorInterface
{
    /**
     * Write/dangerous operation keywords.
     *
     * Single-sourced from CypherValidator so the read-only gate rejects every
     * keyword the validator considers a write — the two lists cannot diverge.
     */
    private array $writeKeywords = CypherValidator::DANGEROUS_KEYWORDS;

    /**
     * Rate limiter instance
     */
    protected RateLimiter $rateLimiter;

    /**
     * The cap on ALL users together (null = disabled). Charged after the acting
     * user's own budget, so a user already over budget never consumes it.
     */
    protected ?RateLimiter $globalRateLimiter = null;

    /**
     * Batch keys that already paid their (single) rate-limiter charge on this
     * executor instance. See {@see attemptRateLimit()} — request-scoped by
     * construction (the array lives on the instance, never in cache).
     *
     * @var array<string, true>
     */
    protected array $chargedRateLimitBatches = [];

    /**
     * Constructor
     *
     * @param GraphStoreInterface $graphStore Graph database interface
     * @param array $config Configuration options
     * @param RateLimiter|null $rateLimiter Optional per-user limiter (creates default if null)
     * @param RateLimiter|null $globalRateLimiter Optional global cap (default from
     *        ai.rate_limits.global_queries_per_minute; 0 / null disables it)
     */
    public function __construct(
        private readonly GraphStoreInterface $graphStore,
        private readonly array $config = [],
        ?RateLimiter $rateLimiter = null,
        ?RateLimiter $globalRateLimiter = null
    ) {
        $this->rateLimiter = $rateLimiter ?? new RateLimiter(
            key: 'query_executor',
            maxRequests: (int) config('ai.rate_limits.queries_per_minute', 30),
            windowSeconds: 60
        );

        $globalMax = (int) config('ai.rate_limits.global_queries_per_minute', 300);
        $this->globalRateLimiter = $globalRateLimiter ?? ($globalMax > 0
            ? new RateLimiter(key: 'query_executor_global', maxRequests: $globalMax, windowSeconds: 60)
            : null);
    }

    /**
     * Execute a Cypher query
     *
     * @param string $cypherQuery Validated Cypher query to execute
     * @param array $parameters Query parameters
     * @param array $options Execution options
     * @return QueryExecutionResult Execution result
     * @throws QueryExecutionException If query execution fails
     * @throws QueryTimeoutException If query exceeds timeout
     * @throws ReadOnlyViolationException If write operation in read-only mode
     */
    public function execute(?string $cypherQuery, array $parameters = [], array $options = []): QueryExecutionResult
    {
        $timeout = $options['timeout'] ?? $this->config['default_timeout'] ?? 30;
        $limit = $options['limit'] ?? $this->config['default_limit'] ?? 100;
        $readOnly = $options['read_only'] ?? $this->config['read_only_mode'] ?? true;
        $format = $options['format'] ?? $this->config['default_format'] ?? 'table';
        $includeStats = $options['include_stats'] ?? true;

        // Some cases we'll send an empty cypher query because there's nothing to run, just a direct answers, so we don't throw a direct error
        if (empty(trim($cypherQuery ?? ''))) {
            return new QueryExecutionResult(
                success: true,
                data: [],
                stats: [],
                metadata: ['context' => 'NO QUERY'],
                errors: [],
            );
        }

        $securityResult = $this->applySecurityFilter($cypherQuery, $options);

        // Structural safe-stop: the security layer could not produce a valid scoped
        // rewrite. Return a clean empty result instead of running anything — fail
        // closed without a crash, and without shipping a malformed query to Neo4j.
        if (($securityResult['safe_stop'] ?? false) === true) {
            return new QueryExecutionResult(
                success: true,
                data: [],
                stats: [],
                metadata: ['security_filter_applied' => false, 'safe_stop' => true],
                errors: [],
            );
        }

        $cypherQuery = $securityResult['query'];
        $securityFilterApplied = $securityResult['filter_applied'];

        // Merge bound team-filter parameters (e.g. $ai_team_ids) into the query
        // parameters. SECURITY-DERIVED KEYS WIN (Task 1.26c): a caller passing its
        // own `ai_team_ids` (e.g. via executeQuery(…, $parameters)) previously
        // overrode the security value and widened the tenant scope. The reserved
        // team-ids key is never caller-controlled — dropped even when the
        // security layer set none (unfiltered / bypass paths).
        unset($parameters[TeamPathResolver::TEAM_IDS_PARAM]);
        if (!empty($securityResult['parameters'])) {
            $parameters = array_merge($parameters, $securityResult['parameters']);
        }

        // SECURITY: Enforce rate limiting to prevent DoS via expensive query flooding.
        // Batched executions (options['rate_limit_batch'] — e.g. one attention-digest
        // run) charge the shared limiter ONCE per batch; see attemptRateLimit().
        if (!$this->attemptRateLimit($options)) {
            throw new QueryExecutionException(
                'Rate limit exceeded for query execution. Please wait before trying again.'
            );
        }

        if ($readOnly && $this->containsWriteOperations($cypherQuery)) {
            throw new ReadOnlyViolationException(
                'Write operations not allowed in read-only mode'
            );
        }

        // Apply a default limit when the query doesn't specify one.
        if (!preg_match('/\bLIMIT\b/i', $cypherQuery)) {
            $cypherQuery .= " LIMIT {$limit}";
        }

        $startTime = microtime(true);

        try {
            $rawResults = $this->graphStore->query($cypherQuery, $parameters);

            // Enforce the configured wall-clock timeout on the success path too.
            // The GraphStore interface carries no per-query timeout, so a slow
            // backend can return AFTER the budget elapsed; surface that as a
            // timeout reflecting the configured limit rather than silently
            // returning stale/expensive results.
            $elapsedMs = (microtime(true) - $startTime) * 1000;
            if ($elapsedMs >= ($timeout * 1000)) {
                throw new QueryTimeoutException(
                    "Query exceeded timeout of {$timeout} seconds"
                );
            }

            $formattedData = match ($format) {
                'graph' => $this->formatAsGraph($rawResults),
                'json' => $this->formatAsJson($rawResults),
                default => $this->formatAsTable($rawResults),
            };

            $stats = $includeStats ? $this->collectStatistics($rawResults, $startTime) : [];

            $executionTime = $stats['execution_time_ms'] ?? 0;
            $slowThreshold = $this->config['slow_query_threshold_ms'] ?? 1000;

            if ($this->config['log_slow_queries'] ?? true) {
                if ($executionTime > $slowThreshold) {
                    error_log("Slow query detected ({$executionTime}ms): {$cypherQuery}");
                }
            }

            // Log successful query execution (non-blocking)
            $this->logQuerySuccess(
                question: $options['original_question'] ?? 'N/A',
                cypherQuery: $cypherQuery,
                executionTimeMs: $executionTime,
                resultCount: count($formattedData),
                templateUsed: $options['template'] ?? null,
                confidenceScore: $options['confidence_score'] ?? null,
                contextStats: $options['context_stats'] ?? null,
                metadata: $options['metadata'] ?? null,
                conversationId: $options['conversation_id'] ?? null
            );

            return new QueryExecutionResult(
                success: true,
                data: $formattedData,
                stats: $stats,
                metadata: [
                    'format' => $format,
                    'read_only' => $readOnly,
                    'timeout' => $timeout,
                    'security_filter_applied' => $securityFilterApplied,
                ],
                errors: [],
            );

        } catch (QueryTimeoutException $e) {
            // Wall-clock budget exceeded on the success path — surface directly
            // (the message already reflects the configured timeout).
            throw $e;
        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // Log failed query execution (non-blocking)
            $this->logQueryFailure(
                question: $options['original_question'] ?? 'N/A',
                cypherQuery: $cypherQuery,
                error: $e->getMessage(),
                templateUsed: $options['template'] ?? null,
                metadata: $options['metadata'] ?? null,
                conversationId: $options['conversation_id'] ?? null
            );

            if ($executionTime >= ($timeout * 1000)) {
                throw new QueryTimeoutException(
                    "Query exceeded timeout of {$timeout} seconds"
                );
            }

            throw new QueryExecutionException(
                "Query execution failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Acquire a rate-limiter slot for this execution.
     *
     * DIGEST SELF-RATE-LIMIT FIX (iteration-11 verdict, item F): a single
     * attention-digest turn runs up to max_sources (9) source queries plus
     * companion counts through the SHARED query_executor limiter (default
     * 30/min) — two back-to-back digest turns starved it live and printed
     * "could not be checked" for real sources. A digest run is ONE user-visible
     * action, so it now pays ONE limiter charge: the runner mints a fresh
     * `rate_limit_batch` key per run, the FIRST execution carrying it consumes
     * a normal slot, and every subsequent execution with the SAME key on this
     * executor instance rides that charge.
     *
     * CHOICE (documented): one-charge-per-batch on the SHARED limiter was
     * chosen over a digest-specific limiter key because (a) a digest still
     * consumes real budget from the same global window — the DoS accounting
     * stays whole rather than moving to a second, separately-tunable pool, and
     * (b) it needs no new config surface. Rate limiting for normal turns is
     * UNCHANGED: no batch key → one charge per query, exactly as before.
     *
     * Scope/safety: the memo is per-executor-instance (request-scoped, never
     * cached), the key is server-minted (DigestRunner strips any caller-supplied
     * value and generates its own random key), and a rejected first charge still
     * fails the whole batch closed.
     *
     * @param array $options Execution options (reads 'rate_limit_batch').
     */
    protected function attemptRateLimit(array $options): bool
    {
        $batch = $options['rate_limit_batch'] ?? null;
        $scope = $this->rateLimitScope($options);

        if (!is_string($batch) || $batch === '') {
            return $this->charge($scope);
        }

        if (isset($this->chargedRateLimitBatches[$batch])) {
            return true;
        }

        if (!$this->charge($scope)) {
            return false;
        }

        // Bounded: a long-lived worker (Octane, queue, the eval command) mints one key
        // per turn / digest run — keep only the recent ones.
        if (count($this->chargedRateLimitBatches) >= 256) {
            $this->chargedRateLimitBatches = array_slice($this->chargedRateLimitBatches, -128, null, true);
        }
        $this->chargedRateLimitBatches[$batch] = true;

        return true;
    }

    /**
     * ADMISSION for one user action (a chat turn): take the turn's single rate-limit
     * charge up front — before any LLM call is paid for — and, when the budget is full,
     * WAIT IN LINE for the window to reopen instead of failing, as long as the wait fits
     * $maxWaitSeconds. The caller's later executions carry the same rate_limit_batch and
     * are free.
     *
     * Never throws. When the wait would exceed the bound nothing is consumed and
     * `retry_after` says how many seconds until a slot frees, so the caller can tell the
     * user exactly that.
     *
     * @param array<string, mixed> $options       must carry 'rate_limit_batch'; 'user' scopes the budget
     * @param callable(int): void|null $sleep     test seam (defaults to sleep())
     * @return array{admitted: bool, waited_seconds: int, retry_after: int}
     */
    public function admit(array $options, int $maxWaitSeconds = 20, ?callable $sleep = null): array
    {
        $sleep ??= static function (int $seconds): void {
            sleep($seconds);
        };
        $scope = $this->rateLimitScope($options);
        $waited = 0;

        // Bounded loop: at most a few window reopenings fit in any sane $maxWaitSeconds.
        for ($i = 0; $i < 10; $i++) {
            $wait = max(
                $this->rateLimiter->retryAfter($scope),
                $this->globalRateLimiter?->retryAfter() ?? 0
            );

            if ($wait === 0) {
                if ($this->attemptRateLimit($options)) {
                    return ['admitted' => true, 'waited_seconds' => $waited, 'retry_after' => 0];
                }
                $wait = 1; // lost the slot to a concurrent request: re-check shortly
            }

            if ($waited + $wait > max(0, $maxWaitSeconds)) {
                return ['admitted' => false, 'waited_seconds' => $waited, 'retry_after' => $wait];
            }

            $sleep($wait);
            $waited += $wait;
        }

        return ['admitted' => false, 'waited_seconds' => $waited, 'retry_after' => 1];
    }

    /**
     * One charge: the acting user's own budget first, then the global cap (all users
     * together). A user already over their own budget is refused before the global cap
     * is touched; when the global cap refuses, the user's slot stays spent for the window
     * (fail-safe direction: never under-count).
     */
    private function charge(string $scope): bool
    {
        if (!$this->rateLimiter->attempt($scope)) {
            return false;
        }

        return $this->globalRateLimiter === null || $this->globalRateLimiter->attempt();
    }

    /**
     * The limiter budget this execution counts against: the acting user's own
     * ("u:<id>"), so one user's activity never locks every other user out (the budget
     * was one global counter until 2026-10-06). '' (the shared budget) when no user is
     * threaded — console / system executions.
     */
    private function rateLimitScope(array $options): string
    {
        $user = $options['user'] ?? null;
        if (!is_object($user)) {
            return '';
        }
        $id = method_exists($user, 'getKey') ? $user->getKey() : ($user->id ?? null);

        return is_scalar($id) && (string) $id !== '' ? 'u:' . $id : '';
    }

    /**
     * Execute query and return count only
     *
     * @param string $cypherQuery Query to execute
     * @param array $parameters Query parameters
     * @param array $options Execution options
     * @return int Count of results
     */
    public function executeCount(?string $cypherQuery, array $parameters = [], array $options = []): int
    {
        $inner = trim($cypherQuery ?? '');

        if ($inner === '') {
            return 0;
        }

        // Wrap the original query as a subquery and count the rows it yields.
        // Textual rewriting (e.g. prepending "WITH * MATCH") cannot express every
        // shape: OPTIONAL MATCH, CALL ... YIELD, leading WITH, and UNION queries
        // are not simple "MATCH ... RETURN" forms and would produce illegal
        // Cypher. A CALL subquery counts any read-only query's result rows
        // regardless of its internal shape, and leaves the inner clauses intact
        // so execute()'s read-only and team-security rewriting still apply to the
        // real MATCH inside the braces.
        $countQuery = "CALL { {$inner} } RETURN count(*) AS total";

        try {
            $result = $this->execute($countQuery, $parameters, $options);
            return (int) ($result->data[0]['total'] ?? 0);
        } catch (\Exception $e) {
            throw new QueryExecutionException(
                "Count execution failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Check if query contains write operations
     *
     * @param string $query Query to check
     * @return bool True if contains write operations
     */
    private function containsWriteOperations(string $query): bool
    {
        foreach ($this->writeKeywords as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $query)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Format results as table (array of rows)
     *
     * @param array $rawResults Raw Neo4j results
     * @return array Formatted table data
     */
    private function formatAsTable(array $rawResults): array
    {
        if (empty($rawResults)) {
            return [];
        }

        return array_map(function ($row) {
            return $this->flattenRow($row);
        }, $rawResults);
    }

    /**
     * Format results as graph (nodes and relationships)
     *
     * @param array $rawResults Raw Neo4j results
     * @return array Graph structure with nodes and relationships
     */
    private function formatAsGraph(array $rawResults): array
    {
        $nodes = [];
        $relationships = [];

        foreach ($rawResults as $row) {
            $this->extractNodesAndRelationships($row, $nodes, $relationships);
        }

        return [
            'nodes' => array_values($nodes),
            'relationships' => array_values($relationships),
        ];
    }

    /**
     * Format results as JSON (structured data)
     *
     * @param array $rawResults Raw Neo4j results
     * @return array JSON-compatible structure
     */
    private function formatAsJson(array $rawResults): array
    {
        return json_decode(json_encode($rawResults), true);
    }

    /**
     * Flatten a result row for table format
     *
     * @param array $row Result row
     * @return array Flattened row
     */
    private function flattenRow(array $row): array
    {
        $flattened = [];

        foreach ($row as $key => $value) {
            if (is_array($value) && isset($value['properties'])) {
                // This is a node, extract properties
                $flattened[$key] = $value['properties'];
            } elseif (is_array($value) && isset($value['type'])) {
                // This is a relationship
                $flattened[$key] = [
                    'type' => $value['type'],
                    'properties' => $value['properties'] ?? [],
                ];
            } else {
                $flattened[$key] = $value;
            }
        }

        return $flattened;
    }

    /**
     * Extract nodes and relationships from row
     *
     * @param array $row Result row
     * @param array &$nodes Nodes array (passed by reference)
     * @param array &$relationships Relationships array (passed by reference)
     */
    private function extractNodesAndRelationships(array $row, array &$nodes, array &$relationships): void
    {
        foreach ($row as $value) {
            if (is_array($value)) {
                if (isset($value['id']) && isset($value['labels'])) {
                    // This is a node
                    $nodes[$value['id']] = $value;
                } elseif (isset($value['type']) && isset($value['start']) && isset($value['end'])) {
                    // This is a relationship
                    $relationships[] = $value;
                }
            }
        }
    }

    /**
     * Collect execution statistics
     *
     * @param array $rawResponse Raw response from Neo4j
     * @param float $startTime Execution start time
     * @return array Statistics
     */
    private function collectStatistics(array $rawResponse, float $startTime): array
    {
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        return [
            'execution_time_ms' => $executionTime,
            'rows_returned' => is_array($rawResponse) ? count($rawResponse) : 0,
            'database_hits' => null, // Would need Neo4j profile info
            'rows_scanned' => null,  // Would need Neo4j profile info
        ];
    }

    /**
     * Log successful query execution to AiQueryLog
     *
     * This method is non-blocking - any logging failures are caught and logged
     * to the application log without interrupting the main execution flow.
     *
     * @param string $question Original natural language question
     * @param string $cypherQuery The executed Cypher query
     * @param float $executionTimeMs Query execution time in milliseconds
     * @param int $resultCount Number of results returned
     * @param string|null $templateUsed Template identifier if applicable
     * @param float|null $confidenceScore AI confidence score if available
     * @param array|null $contextStats Context retrieval statistics
     * @param array|null $metadata Additional metadata
     * @param int|null $conversationId Conversation ID if available
     */
    protected function logQuerySuccess(
        string $question,
        string $cypherQuery,
        float $executionTimeMs,
        int $resultCount,
        ?string $templateUsed = null,
        ?float $confidenceScore = null,
        ?array $contextStats = null,
        ?array $metadata = null,
        ?int $conversationId = null
    ): void {
        try {
            AiQueryLog::logSuccess([
                'user_id' => auth()->id(),
                'team_id' => $this->getCurrentTeamId(),
                'conversation_id' => $conversationId,
                'question' => $question,
                'cypher_query' => $cypherQuery,
                'execution_time_ms' => $executionTimeMs,
                'result_count' => $resultCount,
                'template_used' => $templateUsed,
                'confidence_score' => $confidenceScore,
                'context_stats' => $contextStats,
                'metadata' => $metadata,
            ]);
        } catch (\Exception $e) {
            // Non-blocking: log warning but don't interrupt query execution
            Log::warning('Failed to log successful query to AiQueryLog', [
                'error' => $e->getMessage(),
                'query' => substr($cypherQuery, 0, 200),
            ]);
        }
    }

    /**
     * Log failed query execution to AiQueryLog
     *
     * This method is non-blocking - any logging failures are caught and logged
     * to the application log without interrupting the main execution flow.
     *
     * @param string $question Original natural language question
     * @param string $cypherQuery The attempted Cypher query
     * @param string $error Error message from the failure
     * @param string|null $templateUsed Template identifier if applicable
     * @param array|null $metadata Additional metadata
     * @param int|null $conversationId Conversation ID if available
     */
    protected function logQueryFailure(
        string $question,
        ?string $cypherQuery,
        string $error,
        ?string $templateUsed = null,
        ?array $metadata = null,
        ?int $conversationId = null
    ): void {
        try {
            AiQueryLog::logFailure([
                'user_id' => auth()->id(),
                'team_id' => $this->getCurrentTeamId(),
                'conversation_id' => $conversationId,
                'question' => $question,
                'cypher_query' => $cypherQuery,
                'template_used' => $templateUsed,
                'metadata' => $metadata,
            ], $error);
        } catch (\Exception $e) {
            // Non-blocking: log warning but don't interrupt error handling
            Log::warning('Failed to log query failure to AiQueryLog', [
                'error' => $e->getMessage(),
                'original_error' => substr($error, 0, 200),
                'query' => substr($cypherQuery, 0, 200),
            ]);
        }
    }

    /**
     * Get the current team ID if available
     *
     * @return int|null Current team ID or null
     */
    protected function getCurrentTeamId(): ?int
    {
        // Try common patterns for getting team ID
        if (function_exists('currentTeamId')) {
            return currentTeamId();
        }

        $user = auth()->user();
        if ($user && method_exists($user, 'currentTeam')) {
            // Property access, not the method: a belongsTo currentTeam() returns the relation,
            // whose ->id is undefined and throws. The property resolves the related model.
            return $user->currentTeam?->id;
        }

        if ($user && property_exists($user, 'current_team_id')) {
            return $user->current_team_id;
        }

        return null;
    }

    /**
     * Apply security filtering to Cypher query.
     *
     * FAIL-CLOSED posture (config-gated): when security is enabled and
     * `ai.security.on_permission_unexistent === 'deny'`, any situation where a
     * sensitive entity is identified but the team filter cannot be applied
     * (no resolvable user, or the filter cannot be safely injected into the
     * query shape) raises a SecurityException instead of running the raw,
     * cross-tenant query. When set to 'bypass', the legacy fail-OPEN behaviour
     * is preserved but a warning is logged.
     *
     * @param string $cypherQuery The query to filter
     * @param array $options Execution options
     * @return array{query: string, filter_applied: bool, parameters: array}
     * @throws SecurityException If access denied / cannot be safely filtered
     */
    private function applySecurityFilter(string $cypherQuery, array $options): array
    {
        $unfiltered = ['query' => $cypherQuery, 'filter_applied' => false, 'parameters' => []];

        // NOTE (Task 1.26c): the former `$options['skip_security']` bypass was
        // REMOVED — nothing in the package or in CondoEdge set it, and an options
        // array built by pipeline code must never be able to switch tenant
        // scoping off. Security is disabled only via config('ai.security.enabled').

        // Get security service
        $securityService = app(AiSecurityService::class);

        // Check if security is enabled
        if (!config('ai.security.enabled', true)) {
            return $unfiltered;
        }

        // CANONICALIZATION (Task 1.26b) — runs BEFORE any analysis, in ONE place.
        // Rejects whitespace/control/format characters Neo4j reads differently
        // from the security lexer, and strips every comment. From here on EVERY
        // step — analysis, coverage gate, acceptance gate, injection, validation
        // AND the returned (executed) text, including the unfiltered/bypass paths
        // — uses ONLY the canonical text, so what runs is byte-identical to what
        // was analyzed (the `// … \r UNION ALL …` desync ran unscoped before).
        $canonical = $this->cypherCanonicalizer()->canonicalize($cypherQuery);
        if ($canonical['query'] === null) {
            return $this->safeStopNoResult('(unanalyzed)', (string) $canonical['reason']);
        }
        $cypherQuery = $canonical['query'];
        $unfiltered = ['query' => $cypherQuery, 'filter_applied' => false, 'parameters' => []];

        // Resolve the acting user: prefer the user threaded through the pipeline
        // ($options['user']) over auth()->user(), because the real user is null
        // or wrong on CLI / queue / regeneration / impersonation paths.
        $user = $options['user'] ?? auth()->user();

        // STRUCTURAL ANALYSIS (safe-by-construction): recognize the scopeable subset
        // and enumerate EVERY tenant-sensitive node binding on its own live alias.
        // This replaces the former regex entity/alias detection. injectTeamFilter()
        // is retained only as a thin shim for direct-call unit tests; the execution
        // path never uses it.
        $plan = $this->scopeAnalyzer()->analyze($cypherQuery);
        $primaryBinding = $plan->primaryBinding();
        $primaryEntity = $primaryBinding?->label ?? '(unlabeled)';

        // ANALYZER-INDEPENDENT COVERAGE GATE (fail-closed invariant). The analyzer
        // cannot see sensitive labels nested inside CALL{} subqueries or behind a
        // label-free relationship hop, so trusting it alone leaks: it reports "no
        // sensitive binding" and the query runs UNSCOPED. This lexical scan finds
        // every tenant-sensitive label the query references at ANY depth and proves
        // the analyzer enumerated each one. A sensitive label the analyzer missed (a
        // coverage gap) means the query CANNOT be proven scoped → safe-stop. This is
        // unconditional: a query touching tenant data that we cannot prove is fully
        // scoped never runs unfiltered, regardless of the on_permission_unexistent
        // bypass switch (which only governs genuinely non-sensitive shapes below).
        $coverage = $this->sensitiveLabelCoverageGate();
        if ($coverage->referencesSensitiveLabel($cypherQuery)
            && (!$plan->isScopeable || !$coverage->planCoversSensitiveBindings($cypherQuery, $plan))) {
            return $this->safeStopNoResult($primaryEntity, 'sensitive_label_coverage_gap');
        }

        if (!$user) {
            // No resolvable user. If the query binds (or would bind) any sensitive
            // node — whether scopeable or an un-anchorable/label-free shape — fail
            // closed (or bypass with a warning). A pure scalar/introspection query
            // (no node access) is not subject to scoping.
            if ($plan->hasSensitiveBindings() || $plan->accessesNodes) {
                return $this->failClosedOrBypass($cypherQuery, $primaryEntity, 'no_resolvable_user');
            }

            return $unfiltered;
        }

        // Out of the scopeable subset: a node-accessing query we cannot PROVE we can
        // tenant-scope must never run unfiltered → fail closed (or bypass loudly).
        if (!$plan->isScopeable) {
            if ($plan->accessesNodes) {
                return $this->failClosedOrBypass($cypherQuery, $primaryEntity, $plan->reason ?? 'not_scopeable');
            }

            // Scopeable-shape-irrelevant but node-free (e.g. an unusual scalar form);
            // nothing tenant-sensitive to scope.
            return $unfiltered;
        }

        // In-subset but binds no tenant-sensitive node (e.g. RETURN 1, CALL db.labels()).
        if (!$plan->hasSensitiveBindings()) {
            return $unfiltered;
        }

        // PURE aggregate COUNT (the canonical query's only RETURN projects nothing
        // but count(…) — no row data) over a single sensitive entity, for a user
        // with global-count permission: preserve the legacy team-filter skip.
        // A `count(` anywhere else (WITH head, literal, beside row data) never
        // qualifies — see isCountQuery() (security review 2026-10-01, P2).
        if ($this->isCountQuery($cypherQuery) && $primaryBinding !== null) {
            $distinctLabels = $this->distinctSensitiveLabels($plan);
            if (count($distinctLabels) === 1
                && $securityService->shouldSkipTeamFilterForCount($user, $primaryBinding->label)) {
                return $unfiltered;
            }
        }

        // Resolve each binding's team filter from AiSecurityService EXACTLY ONCE
        // (memoized by label|alias). The same resolution feeds BOTH the acceptance
        // gate (which verifies the generator already emitted the scope) and the
        // fallback injector (which splices it when the generator did not), so deny
        // is enforced once and getTeamFilterWithParameters is not called twice per
        // binding. UNCHANGED contract: it still throws SecurityException on deny and
        // still returns the bound $ai_team_ids parameter.
        $mergedParameters = [];
        $anyFilter = false;
        $resolvedCache = [];

        $resolveFor = function (NodeBinding $binding) use ($securityService, $user, &$resolvedCache): array {
            $key = $binding->label . '|' . $binding->alias;
            if (!array_key_exists($key, $resolvedCache)) {
                $resolvedCache[$key] = $securityService->getTeamFilterWithParameters($user, $binding->label, $binding->alias);
            }
            return $resolvedCache[$key];
        };

        $filterFor = function (NodeBinding $binding) use ($resolveFor, &$mergedParameters, &$anyFilter): ?FilterClause {
            $resolved = $resolveFor($binding);
            $filter = $resolved['filter'] ?? null;

            if (!$filter) {
                // 'bypass' for a missing team_path on THIS binding: no filter. Other
                // bindings are still scoped.
                return null;
            }

            $anyFilter = true;
            $mergedParameters = array_merge($mergedParameters, $resolved['parameters'] ?? []);

            $kind = preg_match('/\bMATCH\b/i', $filter) ? 'match' : 'where';

            return new FilterClause($kind, $filter, $resolved['parameters'] ?? []);
        };

        // The gate's REQUIRED per-binding rule: the team-ID-free resolver TEMPLATE
        // (single source of truth) for any binding that AiSecurityService says needs
        // a filter. A binding whose resolved filter is null (bypass / missing
        // team_path) needs no predicate → return null, and the gate treats it as
        // satisfied. When the security service says a binding IS filtered but the
        // resolver cannot produce a verifiable template ('unverifiable'), the gate
        // refuses to accept it as-is → falls back to the injector (which applies the
        // resolved filter directly).
        $teamPathResolver = $this->teamPathResolver();
        $ruleFor = function (NodeBinding $binding) use ($resolveFor, $teamPathResolver): ?array {
            $resolved = $resolveFor($binding);
            if (empty($resolved['filter'])) {
                return null; // not tenant-scoped for this binding
            }

            $rule = $teamPathResolver->ruleFor($binding->label, $binding->alias);

            // Filtered, but no verifiable template (the resolver and the security
            // service disagree, or the path is non-templatable here): never accept
            // as self-scoped — let the injector apply the resolved filter instead.
            return $rule ?? ['type' => 'unverifiable', 'kind' => 'where', 'alias' => $binding->alias, 'column' => null, 'filter' => $resolved['filter']];
        };

        // PRIMARY — generator-emits-scope: accept the query AS-IS when every
        // sensitive binding already carries its resolver-correct $ai_team_ids
        // predicate on its own live alias, the query is structurally valid (incl.
        // UNION-column consistency), and the per-binding security resolution
        // (deny/params) succeeds. The gate is the positive precondition the
        // invariant test re-verifies on output; an accepted query satisfies it by
        // construction.
        if ($this->scopeAcceptanceGate()->isFullySelfScoped($cypherQuery, $plan, $ruleFor)
            && $this->structuralValidator()->isValid($cypherQuery)) {
            // Run $filterFor over every binding so deny is enforced and the bound
            // $ai_team_ids parameters are collected for the accepted query.
            foreach ($plan->scopes as $scope) {
                foreach ($scope->sensitiveBindings as $binding) {
                    $filterFor($binding);
                }
            }

            if (!$anyFilter) {
                // Every binding resolved to "no filter" (bypass / missing team_path).
                return $unfiltered;
            }

            return ['query' => $cypherQuery, 'filter_applied' => true, 'parameters' => $mergedParameters];
        }

        // FALLBACK — at least one binding was not self-scoped (omission, wrong alias,
        // wrong path-kind, inlined ids, mismatched UNION columns). Splice the
        // missing scope. inject() scopes every still-unscoped binding AND self-
        // validates (returns null on any malformed rewrite, incl. UNION-column).
        $injected = $this->securityFilterInjector()->inject($cypherQuery, $plan, $filterFor);

        if ($injected === null) {
            // The plan was scopeable but a binding could not be safely rewritten, or
            // the rewrite was not structurally valid (defence-in-depth gate). Never
            // run it unfiltered AND never ship a malformed query to Neo4j. Degrade
            // gracefully to an empty result so the caller answers "no data" instead
            // of crashing on a raw SyntaxError. This is a structural safety stop, not
            // an authz denial, so it is unconditional (not subject to 'bypass').
            return $this->safeStopNoResult($primaryEntity, 'gate_and_injector_failed');
        }

        if (!$anyFilter) {
            // Every binding resolved to "no filter" (bypass / missing team_path) —
            // legacy behaviour ran the query unfiltered.
            return $unfiltered;
        }

        return ['query' => $injected, 'filter_applied' => true, 'parameters' => $mergedParameters];
    }

    /** Distinct tenant-sensitive labels present across all scopes. */
    private function distinctSensitiveLabels(\Condoedge\Ai\Services\Security\Scope\ScopePlan $plan): array
    {
        $labels = [];
        foreach ($plan->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $binding) {
                $labels[$binding->label] = true;
            }
        }
        return array_keys($labels);
    }

    private function scopeAnalyzer(): CypherScopeAnalyzer
    {
        return app(CypherScopeAnalyzer::class);
    }

    private function securityFilterInjector(): SecurityFilterInjector
    {
        return app(SecurityFilterInjector::class);
    }

    private function scopeAcceptanceGate(): ScopeAcceptanceGate
    {
        return app(ScopeAcceptanceGate::class);
    }

    private function structuralValidator(): CypherStructuralValidator
    {
        return app(CypherStructuralValidator::class);
    }

    private function teamPathResolver(): TeamPathResolver
    {
        return app(TeamPathResolver::class);
    }

    private function sensitiveLabelCoverageGate(): SensitiveLabelCoverageGate
    {
        return app(SensitiveLabelCoverageGate::class);
    }

    private function cypherCanonicalizer(): CypherCanonicalizer
    {
        return app(CypherCanonicalizer::class);
    }

    /**
     * Decide what to do when a sensitive entity cannot be team-filtered.
     *
     * 'deny'   → throw SecurityException (fail closed).
     * 'bypass' → return the unfiltered query but log a warning (fail open).
     *
     * @return array{query: string, filter_applied: bool, parameters: array}
     * @throws SecurityException
     */
    private function failClosedOrBypass(string $cypherQuery, string $entity, string $reason): array
    {
        $action = config('ai.security.on_permission_unexistent', 'deny');

        if ($action === 'deny') {
            Log::channel(config('ai.security.log_channel', 'ai-security'))->warning(
                'AI query blocked: sensitive entity could not be team-filtered',
                ['entity' => $entity, 'reason' => $reason, 'action' => 'deny']
            );

            throw new SecurityException(
                "Cannot verify permissions for {$entity} data"
            );
        }

        // 'bypass' — preserve legacy fail-open behaviour, but make it loud.
        Log::channel(config('ai.security.log_channel', 'ai-security'))->warning(
            'AI query running UNFILTERED for sensitive entity (security bypass)',
            ['entity' => $entity, 'reason' => $reason, 'action' => 'bypass']
        );

        return ['query' => $cypherQuery, 'filter_applied' => false, 'parameters' => []];
    }

    /**
     * Graceful fail-closed for a STRUCTURAL stop: a scopeable, permitted query whose
     * tenant-scoped rewrite could not be produced safely / validly. Unlike a permission
     * denial this must neither throw (it would surface as a crash) nor run unfiltered
     * (it would leak). It signals execute() to short-circuit to an empty success
     * result so the user gets an honest "no data", and a malformed query never reaches
     * Neo4j.
     *
     * @return array{query: string, filter_applied: bool, parameters: array, safe_stop: bool}
     */
    private function safeStopNoResult(string $entity, string $reason): array
    {
        Log::channel(config('ai.security.log_channel', 'ai-security'))->warning(
            'AI query stopped: tenant-scoped rewrite could not be produced safely; returning no result',
            ['entity' => $entity, 'reason' => $reason, 'action' => 'safe_stop']
        );

        return ['query' => '', 'filter_applied' => false, 'parameters' => [], 'safe_stop' => true];
    }

    /**
     * Whether the query is a PURE aggregate count: its single top-level RETURN
     * projects nothing but `count(…)` items (each optionally `AS alias`), so the
     * result carries no row data. Only then may the global-count permission skip
     * the team filter.
     *
     * Token-based on the canonical text (security review 2026-10-01, P2): the
     * former `/\bCOUNT\s*\(/i` matched ANYWHERE — a `count(` in a WITH head, a
     * string literal, or beside row data in the RETURN — and the whole row
     * query then ran with NO tenant filter for a global-count user. Strings are
     * opaque lexer literals and comments are no tokens; a UNION (another branch
     * may return rows), a second top-level RETURN, or any non-count projection
     * item → not a count query → filtered normally.
     */
    private function isCountQuery(string $query): bool
    {
        $lexed = (new CypherLexer())->tokenize($query);
        if ($lexed['error'] !== null) {
            return false;
        }

        /** @var list<\Condoedge\Ai\Services\Security\Token> $tokens */
        $tokens = $lexed['tokens'];
        $n = count($tokens);

        $returnIdx = null;
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!$t->isTopLevel() || $t->type !== CypherLexer::T_WORD || $t->keywordAsIdentifier) {
                continue;
            }
            if ($t->isWord('UNION')) {
                return false;
            }
            if ($t->isWord('RETURN')) {
                if ($returnIdx !== null) {
                    return false;
                }
                $returnIdx = $i;
            }
        }
        if ($returnIdx === null) {
            return false;
        }

        $i = $returnIdx + 1;
        if ($i < $n && $tokens[$i]->isWord('DISTINCT')) {
            $i++;
        }

        // Each projection item: count ( <balanced> ) [AS <word>], comma-separated.
        $items = 0;
        while (true) {
            if ($i + 1 >= $n
                || !$tokens[$i]->isWord('count') || !$tokens[$i]->isTopLevel()
                || !$tokens[$i + 1]->isPunct('(') || !$tokens[$i + 1]->isTopLevel()) {
                return false;
            }
            // Skip to the matching top-level ')'.
            $close = null;
            for ($j = $i + 2; $j < $n; $j++) {
                if ($tokens[$j]->isPunct(')') && $tokens[$j]->isTopLevel()) {
                    $close = $j;
                    break;
                }
            }
            if ($close === null) {
                return false;
            }
            $i = $close + 1;
            $items++;

            if ($i < $n && $tokens[$i]->isWord('AS')) {
                if ($i + 1 >= $n || $tokens[$i + 1]->type !== CypherLexer::T_WORD) {
                    return false;
                }
                $i += 2;
            }

            if ($i < $n && $tokens[$i]->isPunct(',') && $tokens[$i]->isTopLevel()) {
                $i++;
                continue;
            }
            break;
        }

        // Only result modifiers (ORDER BY / SKIP / LIMIT) or a trailing ';' may follow.
        if ($i >= $n || $tokens[$i]->isPunct(';')) {
            return $items > 0;
        }
        $next = $tokens[$i];

        return $items > 0
            && $next->type === CypherLexer::T_WORD
            && !$next->keywordAsIdentifier
            && ($next->isWord('ORDER') || $next->isWord('SKIP') || $next->isWord('LIMIT'));
    }

    /**
     * LEGACY SHIM — retained for direct-call unit tests only.
     *
     * Delegates to the structural {@see CypherScopeAnalyzer} + {@see
     * SecurityFilterInjector} that the real execution path uses. To preserve the
     * legacy single-filter-string contract (one predicate string, applied to the
     * PRIMARY binding of each part/UNION branch), it scopes only the FIRST
     * sensitive binding of each scope with the supplied $filter and returns null
     * for any shape the analyzer cannot prove scopeable.
     *
     * @return string|null The rewritten query, or null if the shape cannot be
     *                     safely filtered (caller then fails closed).
     */
    private function injectTeamFilter(string $query, string $filter): ?string
    {
        $plan = $this->scopeAnalyzer()->analyze($query);

        if (!$plan->isScopeable || !$plan->hasSensitiveBindings()) {
            return null;
        }

        // Legacy behaviour: one filter string applied to the primary binding of
        // each scope. Reduce every scope to its first sensitive binding.
        $reducedScopes = [];
        foreach ($plan->scopes as $scope) {
            if (empty($scope->sensitiveBindings)) {
                $reducedScopes[] = $scope;
                continue;
            }
            $reducedScopes[] = new Scope(
                sensitiveBindings: [$scope->sensitiveBindings[0]],
                anchorOffset: $scope->anchorOffset,
                whereOffset: $scope->whereOffset,
                whereEndOffset: $scope->whereEndOffset,
                whereHasTopLevelOr: $scope->whereHasTopLevelOr
            );
        }

        $reducedPlan = new ScopePlan(
            $plan->isScopeable,
            $reducedScopes,
            $plan->literalSpans,
            $plan->accessesNodes,
            $plan->reason
        );

        $kind = preg_match('/\bMATCH\b/i', $filter) ? 'match' : 'where';
        $filterFor = static fn(NodeBinding $b): FilterClause => new FilterClause($kind, $filter, []);

        return $this->securityFilterInjector()->inject($query, $reducedPlan, $filterFor);
    }
}
