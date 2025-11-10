<?php

declare(strict_types=1);

namespace AiSystem\Services;

use AiSystem\Contracts\QueryExecutorInterface;
use AiSystem\Contracts\GraphStoreInterface;
use AiSystem\Exceptions\QueryExecutionException;
use AiSystem\Exceptions\QueryTimeoutException;
use AiSystem\Exceptions\ReadOnlyViolationException;

/**
 * Query Executor Service
 *
 * Executes Cypher queries against Neo4j with safety measures,
 * timeout protection, result formatting, and comprehensive error handling.
 *
 * @package AiSystem\Services
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
     * Constructor
     *
     * @param GraphStoreInterface $graphStore Graph database interface
     * @param array $config Configuration options
     */
    public function __construct(
        private readonly GraphStoreInterface $graphStore,
        private readonly array $config = []
    ) {
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

        // Pre-execution validation
        if (empty(trim($cypherQuery))) {
            throw new QueryExecutionException('Query cannot be empty');
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
}
