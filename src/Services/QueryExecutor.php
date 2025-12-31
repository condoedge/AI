<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Exceptions\QueryTimeoutException;
use Condoedge\Ai\Exceptions\ReadOnlyViolationException;
use Condoedge\Ai\Models\AiQueryLog;
use Condoedge\Ai\Services\Resilience\RateLimiter;
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
    public function execute(string $cypherQuery, array $parameters = [], array $options = []): array
    {
        // Merge options with defaults
        $timeout = $options['timeout'] ?? $this->config['default_timeout'] ?? 30;
        $limit = $options['limit'] ?? $this->config['default_limit'] ?? 100;
        $readOnly = $options['read_only'] ?? $this->config['read_only_mode'] ?? true;
        $format = $options['format'] ?? $this->config['default_format'] ?? 'table';
        $includeStats = $options['include_stats'] ?? true;

        // Some cases we'll send an empty cypher query because there's nothing to run, just a direct answers, so we don't throw a direct error
        if (empty(trim($cypherQuery))) {
            return [
                'success' => true,
                'data' => [],
                'stats' => [],
                'metadata' => [],
                'context' => 'NO QUERY',
            ];
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
                question: $options['original_question'] ?? null,
                cypherQuery: $cypherQuery,
                executionTimeMs: $executionTime,
                resultCount: count($formattedData),
                templateUsed: $options['template'] ?? null,
                confidenceScore: $options['confidence_score'] ?? null,
                contextStats: $options['context_stats'] ?? null,
                metadata: $options['metadata'] ?? null
            );

            return [
                'success' => true,
                'data' => $formattedData,
                'stats' => $stats,
                'metadata' => [
                    'format' => $format,
                    'read_only' => $readOnly,
                    'timeout' => $timeout,
                ],
                'errors' => [],
            ];

        } catch (\Exception $e) {
            // Check if timeout
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // Log failed query execution (non-blocking)
            $this->logQueryFailure(
                question: $options['original_question'] ?? null,
                cypherQuery: $cypherQuery,
                error: $e->getMessage(),
                templateUsed: $options['template'] ?? null,
                metadata: $options['metadata'] ?? null
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
    public function executeCount(string $cypherQuery, array $parameters = [], array $options = []): int
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
        string $cypherQuery,
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
    public function explain(string $cypherQuery, array $parameters = []): array
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
    public function test(string $cypherQuery): bool
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
    public function cancel(string $queryId): bool
    {
        try {
            // Neo4j specific: CALL dbms.listQueries() and dbms.killQuery()
            $killQuery = "CALL dbms.killQuery('{$queryId}')";
            $this->graphStore->query($killQuery);
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
     * @param string|null $question Original natural language question
     * @param string $cypherQuery The executed Cypher query
     * @param float $executionTimeMs Query execution time in milliseconds
     * @param int $resultCount Number of results returned
     * @param string|null $templateUsed Template identifier if applicable
     * @param float|null $confidenceScore AI confidence score if available
     * @param array|null $contextStats Context retrieval statistics
     * @param array|null $metadata Additional metadata
     */
    protected function logQuerySuccess(
        ?string $question,
        string $cypherQuery,
        float $executionTimeMs,
        int $resultCount,
        ?string $templateUsed = null,
        ?float $confidenceScore = null,
        ?array $contextStats = null,
        ?array $metadata = null
    ): void {
        try {
            AiQueryLog::logSuccess([
                'user_id' => auth()->id(),
                'team_id' => $this->getCurrentTeamId(),
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
     * @param string|null $question Original natural language question
     * @param string $cypherQuery The attempted Cypher query
     * @param string $error Error message from the failure
     * @param string|null $templateUsed Template identifier if applicable
     * @param array|null $metadata Additional metadata
     */
    protected function logQueryFailure(
        ?string $question,
        string $cypherQuery,
        string $error,
        ?string $templateUsed = null,
        ?array $metadata = null
    ): void {
        try {
            AiQueryLog::logFailure([
                'user_id' => auth()->id(),
                'team_id' => $this->getCurrentTeamId(),
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
}
