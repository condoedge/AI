<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Query Executor Interface
 *
 * Executes Cypher queries against Neo4j with safety measures,
 * timeout protection, and comprehensive error handling.
 */
interface QueryExecutorInterface
{
    /**
     * Execute a Cypher query
     *
     * @param string $cypherQuery Validated Cypher query to execute
     * @param array $parameters Query parameters (for parameterized queries)
     * @param array $options Execution options:
     *                       - timeout: Max execution time in seconds (default: 30)
     *                       - limit: Max results to return (default: 100)
     *                       - read_only: Enforce read-only mode (default: true)
     *                       - format: Result format: 'graph', 'table', 'json' (default: 'table')
     *                       - include_stats: Include execution statistics (default: true)
     * @return array Execution result with keys:
     *               - success: Boolean indicating if query succeeded
     *               - data: Query results in requested format
     *               - stats: Execution statistics (rows returned, time, etc.)
     *               - metadata: Additional metadata
     *               - errors: Array of errors if any
     * @throws \RuntimeException If query execution fails
     * @throws \Exception If query exceeds timeout
     */
    public function execute(string $cypherQuery, array $parameters = [], array $options = []): array;

    /**
     * Execute query and return count only (optimization)
     *
     * @param string $cypherQuery Query to execute
     * @param array $parameters Query parameters
     * @param array $options Execution options
     * @return int Count of results
     */
    public function executeCount(string $cypherQuery, array $parameters = [], array $options = []): int;

    /**
     * Execute query with pagination
     *
     * @param string $cypherQuery Query to execute
     * @param int $page Page number (1-indexed)
     * @param int $perPage Results per page
     * @param array $parameters Query parameters
     * @param array $options Execution options
     * @return array Paginated results with keys:
     *               - data: Current page results
     *               - pagination: Pagination metadata (current_page, per_page, total, last_page)
     *               - stats: Execution statistics
     */
    public function executePaginated(
        string $cypherQuery,
        int $page = 1,
        int $perPage = 20,
        array $parameters = [],
        array $options = []
    ): array;

    /**
     * Explain a query (show execution plan without running)
     *
     * @param string $cypherQuery Query to explain
     * @param array $parameters Query parameters
     * @return array Execution plan details
     */
    public function explain(string $cypherQuery, array $parameters = []): array;

    /**
     * Test if a query is valid (dry run)
     *
     * @param string $cypherQuery Query to test
     * @return bool True if query is valid
     */
    public function test(string $cypherQuery): bool;

    /**
     * Cancel a running query
     *
     * @param string $queryId Query identifier
     * @return bool True if cancelled successfully
     */
    public function cancel(string $queryId): bool;
}
