<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Exceptions\QueryTimeoutException;
use Condoedge\Ai\Exceptions\ReadOnlyViolationException;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Models\AiQueryLog;
use Condoedge\Ai\Services\Resilience\RateLimiter;
use Condoedge\Ai\Services\Security\AiSecurityService;
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
     * Write operation keywords
     */
    private array $writeKeywords = [
        'CREATE', 'DELETE', 'REMOVE', 'MERGE', 'SET', 'DETACH'
    ];

    /**
     * Rate limiter instance
     */
    protected RateLimiter $rateLimiter;

    /**
     * Constructor
     *
     * @param GraphStoreInterface $graphStore Graph database interface
     * @param array $config Configuration options
     * @param RateLimiter|null $rateLimiter Optional rate limiter (creates default if null)
     */
    public function __construct(
        private readonly GraphStoreInterface $graphStore,
        private readonly array $config = [],
        ?RateLimiter $rateLimiter = null
    ) {
        $this->rateLimiter = $rateLimiter ?? new RateLimiter(
            key: 'query_executor',
            maxRequests: (int) config('ai.rate_limits.queries_per_minute', 30),
            windowSeconds: 60
        );
    }

    /**
     * Execute a Cypher query
     *
     * @param string $cypherQuery Validated Cypher query to execute
     * @param array $parameters Query parameters
     * @param array $options Execution options
     * @return array Execution result
     * @throws QueryExecutionException If query execution fails
     * @throws QueryTimeoutException If query exceeds timeout
     * @throws ReadOnlyViolationException If write operation in read-only mode
     */
    public function execute(?string $cypherQuery, array $parameters = [], array $options = []): array
    {
        // Merge options with defaults
        $timeout = $options['timeout'] ?? $this->config['default_timeout'] ?? 30;
        $limit = $options['limit'] ?? $this->config['default_limit'] ?? 100;
        $readOnly = $options['read_only'] ?? $this->config['read_only_mode'] ?? true;
        $format = $options['format'] ?? $this->config['default_format'] ?? 'table';
        $includeStats = $options['include_stats'] ?? true;

        // Some cases we'll send an empty cypher query because there's nothing to run, just a direct answers, so we don't throw a direct error
        if (empty(trim($cypherQuery ?? ''))) {
            return [
                'success' => true,
                'data' => [],
                'stats' => [],
                'metadata' => [],
                'context' => 'NO QUERY',
            ];
        }

        // Apply security filtering if enabled
        $securityResult = $this->applySecurityFilter($cypherQuery, $options);
        $cypherQuery = $securityResult['query'];
        $securityFilterApplied = $securityResult['filter_applied'];

        // Merge bound team-filter parameters (e.g. $ai_team_ids) into the query
        // parameters. Caller-supplied keys win to avoid silent clobbering.
        if (!empty($securityResult['parameters'])) {
            $parameters = array_merge($securityResult['parameters'], $parameters);
        }

        // SECURITY: Enforce rate limiting to prevent DoS via expensive query flooding
        if (!$this->rateLimiter->attempt()) {
            throw new QueryExecutionException(
                'Rate limit exceeded for query execution. Please wait before trying again.'
            );
        }

        // Check read-only mode
        if ($readOnly && $this->containsWriteOperations($cypherQuery)) {
            throw new ReadOnlyViolationException(
                'Write operations not allowed in read-only mode'
            );
        }

        // Apply limit if not present
        if (!preg_match('/\bLIMIT\b/i', $cypherQuery)) {
            $cypherQuery .= " LIMIT {$limit}";
        }

        // Track execution time
        $startTime = microtime(true);

        try {
            // Execute query
            $rawResults = $this->graphStore->query($cypherQuery, $parameters);

            // Format results
            $formattedData = match ($format) {
                'graph' => $this->formatAsGraph($rawResults),
                'json' => $this->formatAsJson($rawResults),
                default => $this->formatAsTable($rawResults),
            };

            // Collect statistics
            $stats = $includeStats ? $this->collectStatistics($rawResults, $startTime) : [];

            // Check for slow query
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

            return [
                'success' => true,
                'data' => $formattedData,
                'stats' => $stats,
                'metadata' => [
                    'format' => $format,
                    'read_only' => $readOnly,
                    'timeout' => $timeout,
                    'security_filter_applied' => $securityFilterApplied,
                ],
                'errors' => [],
            ];

        } catch (\Exception $e) {
            // Check if timeout
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

            // Other execution errors
            throw new QueryExecutionException(
                "Query execution failed: " . $e->getMessage(),
                0,
                $e
            );
        }
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
        // Wrap query in count
        $countQuery = "WITH * MATCH {$cypherQuery} RETURN count(*) as total";

        // Try to extract just the MATCH part for cleaner count query
        if (preg_match('/^(MATCH\s+.+?)\s+RETURN/i', $cypherQuery, $matches)) {
            $countQuery = $matches[1] . " RETURN count(*) as total";
        }

        try {
            $result = $this->execute($countQuery, $parameters, $options);
            return (int) ($result['data'][0]['total'] ?? 0);
        } catch (\Exception $e) {
            throw new QueryExecutionException(
                "Count execution failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Execute query with pagination
     *
     * @param string $cypherQuery Query to execute
     * @param int $page Page number (1-indexed)
     * @param int $perPage Results per page
     * @param array $parameters Query parameters
     * @param array $options Execution options
     * @return array Paginated results
     */
    public function executePaginated(
        ?string $cypherQuery,
        int $page = 1,
        int $perPage = 20,
        array $parameters = [],
        array $options = []
    ): array {
        // Validate pagination parameters
        $page = max(1, $page);
        $perPage = min($perPage, $this->config['max_limit'] ?? 1000);
        $perPage = max(1, $perPage);

        // Calculate offset
        $offset = ($page - 1) * $perPage;

        // Get total count first
        $total = $this->executeCount($cypherQuery, $parameters, $options);

        // Add pagination to query
        $paginatedQuery = preg_replace('/\bLIMIT\s+\d+/i', '', $cypherQuery);
        $paginatedQuery .= " SKIP {$offset} LIMIT {$perPage}";

        // Execute paginated query
        $result = $this->execute($paginatedQuery, $parameters, $options);

        // Add pagination metadata
        $lastPage = (int) ceil($total / $perPage);

        return [
            'data' => $result['data'],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
            'stats' => $result['stats'],
            'metadata' => $result['metadata'],
        ];
    }

    /**
     * Explain a query (show execution plan)
     *
     * @param string $cypherQuery Query to explain
     * @param array $parameters Query parameters
     * @return array Execution plan details
     */
    public function explain(?string $cypherQuery, array $parameters = []): array
    {
        if (!($this->config['enable_explain'] ?? true)) {
            throw new QueryExecutionException('EXPLAIN is disabled in configuration');
        }

        try {
            $explainQuery = "EXPLAIN " . $cypherQuery;
            $result = $this->graphStore->query($explainQuery, $parameters);

            return [
                'plan' => $result['plan'] ?? [],
                'estimated_rows' => $result['estimated_rows'] ?? null,
                'query' => $cypherQuery,
            ];
        } catch (\Exception $e) {
            throw new QueryExecutionException(
                "EXPLAIN failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Test if a query is valid (dry run)
     *
     * @param string $cypherQuery Query to test
     * @return bool True if query is valid
     */
    public function test(?string $cypherQuery): bool
    {
        try {
            // Use EXPLAIN to validate without executing
            $this->explain($cypherQuery);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Cancel a running query
     *
     * @param string $queryId Query identifier
     * @return bool True if cancelled successfully
     */
    public function cancel(?string $queryId): bool
    {
        try {
            // Neo4j specific: CALL dbms.listQueries() and dbms.killQuery()
            // Use parameterized query to prevent injection
            $killQuery = "CALL dbms.killQuery(\$queryId)";
            $this->graphStore->query($killQuery, ['queryId' => $queryId]);
            return true;
        } catch (\Exception $e) {
            // Query might have already finished or ID invalid
            return false;
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
            return $user->currentTeam()?->id;
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
        // Skip if security filtering disabled in options
        if (($options['skip_security'] ?? false) === true) {
            return ['query' => $cypherQuery, 'filter_applied' => false, 'parameters' => []];
        }

        // Get security service
        $securityService = app(AiSecurityService::class);

        // Check if security is enabled
        if (!config('ai.security.enabled', true)) {
            return ['query' => $cypherQuery, 'filter_applied' => false, 'parameters' => []];
        }

        // Resolve the acting user: prefer the user threaded through the pipeline
        // ($options['user']) over auth()->user(), because the real user is null
        // or wrong on CLI / queue / regeneration / impersonation paths.
        $user = $options['user'] ?? auth()->user();

        // Extract entity from query first — only a parseable, sensitive entity
        // triggers the fail-closed branch (e.g. `RETURN 1` / `CALL db.labels()`
        // have no entity and are not team-scoped).
        $entity = $this->extractPrimaryEntity($cypherQuery);

        if (!$user) {
            // No resolvable user. If there is a sensitive entity, this is a
            // security-relevant gap → fail closed (or bypass with a warning).
            if ($entity !== null) {
                return $this->failClosedOrBypass(
                    $cypherQuery,
                    $entity,
                    'no_resolvable_user'
                );
            }

            return ['query' => $cypherQuery, 'filter_applied' => false, 'parameters' => []];
        }

        if (!$entity) {
            // No entity to scope (e.g. label introspection / scalar return).
            return ['query' => $cypherQuery, 'filter_applied' => false, 'parameters' => []];
        }

        $alias = $this->extractEntityAlias($cypherQuery, $entity);

        // Check for COUNT query with global permission
        if ($this->isCountQuery($cypherQuery)) {
            if ($securityService->shouldSkipTeamFilterForCount($user, $entity)) {
                return ['query' => $cypherQuery, 'filter_applied' => false, 'parameters' => []];
            }
        }

        // Get team filter. AiSecurityService already throws SecurityException
        // for missing permissions / missing team_path under 'deny'.
        $teamFiltered = $securityService->getTeamFilterWithParameters($user, $entity, $alias);
        $teamFilter = $teamFiltered['filter'] ?? null;
        $parameters = $teamFiltered['parameters'] ?? [];

        if ($teamFilter) {
            $injected = $this->injectTeamFilter($cypherQuery, $teamFilter);

            if ($injected === null) {
                // A sensitive entity needs filtering but the query shape can't be
                // safely rewritten → never run it unfiltered. Fail closed/bypass.
                return $this->failClosedOrBypass(
                    $cypherQuery,
                    $entity,
                    'injection_not_applicable'
                );
            }

            return ['query' => $injected, 'filter_applied' => true, 'parameters' => $parameters];
        }

        return ['query' => $cypherQuery, 'filter_applied' => false, 'parameters' => []];
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
     * Extract primary entity (label) from Cypher query.
     * Prioritizes the entity in the RETURN clause, falls back to first MATCH entity.
     */
    private function extractPrimaryEntity(string $query): ?string
    {
        // First, try to find the entity being RETURNED (this is the entity the user wants)
        // Pattern: RETURN alias -> find what label that alias maps to
        if (preg_match('/\bRETURN\s+(?:DISTINCT\s+)?(\w+)/i', $query, $returnMatch)) {
            $returnAlias = $returnMatch[1];
            // Find the label for this alias: (alias:Label) or (alias:`Label`) or (alias:Label {props})
            if (preg_match('/\(\s*' . preg_quote($returnAlias, '/') . '\s*:\s*`?(\w+)`?\s*[{)]/i', $query, $labelMatch)) {
                return $labelMatch[1];
            }
        }

        // Fallback: match first (alias:Entity) pattern — handles backtick-quoted labels too
        if (preg_match('/\(\s*\w+\s*:\s*`?(\w+)`?\s*[{)]/i', $query, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extract the alias used for the primary entity.
     * e.g., MATCH (i:Invoice) -> 'i'
     */
    private function extractEntityAlias(string $query, string $entity): string
    {
        // Match (alias:Entity) or (alias:`Entity`) or (alias:Entity {props})
        if (preg_match('/\(\s*(\w+)\s*:\s*`?' . preg_quote($entity, '/') . '`?\s*[{)]/i', $query, $matches)) {
            return $matches[1];
        }
        return strtolower(substr($entity, 0, 1));
    }

    /**
     * Check if query is a COUNT query.
     */
    private function isCountQuery(string $query): bool
    {
        return (bool) preg_match('/\bCOUNT\s*\(/i', $query);
    }

    /**
     * Inject the team filter into a Cypher query, scoping the PRIMARY MATCH of
     * every UNION branch.
     *
     * The filter MUST land on the primary MATCH — i.e. before the first
     * `WITH`/`RETURN`/`ORDER` that follows it — because a `WITH` may project the
     * entity variable away (`MATCH (i:Invoice) WITH i.amount AS amount ...`),
     * after which the predicate could no longer reference it. Injecting at the
     * *last* WHERE (the previous behaviour) silently mis-scoped WITH-chains and
     * UNION branches.
     *
     * For `UNION` / `UNION ALL` queries, each branch is an independent query
     * with its own scope, so the filter is applied to every branch.
     *
     * @return string|null The rewritten query, or null if the shape cannot be
     *                     safely filtered (caller then fails closed).
     */
    private function injectTeamFilter(string $query, string $filter): ?string
    {
        // Split on UNION / UNION ALL, keeping the separators so we can rebuild.
        $parts = preg_split(
            '/(\bUNION\s+ALL\b|\bUNION\b)/i',
            $query,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        $rebuilt = '';
        foreach ($parts as $part) {
            // Separators (UNION / UNION ALL) pass through untouched.
            if (preg_match('/^\s*UNION(\s+ALL)?\s*$/i', $part)) {
                $rebuilt .= $part;
                continue;
            }

            // Empty fragments (can occur at split boundaries) pass through.
            if (trim($part) === '') {
                $rebuilt .= $part;
                continue;
            }

            $branch = $this->injectTeamFilterIntoBranch($part, $filter);
            if ($branch === null) {
                return null; // a branch could not be filtered → fail closed
            }
            $rebuilt .= $branch;
        }

        return $rebuilt;
    }

    /**
     * Inject the team filter into a single (UNION-free) query branch, on its
     * PRIMARY MATCH (before the first WITH/RETURN/ORDER anchor).
     *
     * @return string|null Rewritten branch, or null if no safe anchor exists.
     */
    private function injectTeamFilterIntoBranch(string $branch, string $filter): ?string
    {
        // Locate the first clause that closes the primary MATCH scope. The team
        // predicate must be inserted BEFORE it so the entity variable is still
        // bound. WITH is included so we filter before the variable is projected.
        if (!preg_match('/\b(WITH|RETURN|ORDER)\b/i', $branch, $m, PREG_OFFSET_CAPTURE)) {
            // No RETURN/WITH/ORDER anchor in this branch — cannot safely splice.
            return null;
        }

        $anchorPos = $m[0][1];
        $head = substr($branch, 0, $anchorPos); // up to (not incl.) the anchor
        $tail = substr($branch, $anchorPos);    // the anchor + rest

        // MATCH-based filter (relationship / parent traversal): splice the whole
        // MATCH ... WHERE block in before the anchor.
        if (preg_match('/\bMATCH\b/i', $filter)) {
            return rtrim($head) . ' ' . trim($filter) . ' ' . $tail;
        }

        // Simple WHERE filter (direct column).
        $condition = preg_replace('/^\s*WHERE\s+/i', '', trim($filter));

        // If the primary-MATCH scope (the head) already has its own WHERE, merge
        // with AND; otherwise add a new WHERE. We only consider a WHERE that
        // lives in the head (before the anchor) — a WHERE after RETURN belongs to
        // a different scope.
        if (preg_match('/\bWHERE\b/i', $head)) {
            return rtrim($head) . ' AND ' . $condition . ' ' . $tail;
        }

        return rtrim($head) . ' WHERE ' . $condition . ' ' . $tail;
    }
}
