<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Mockery;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Exceptions\QueryTimeoutException;
use Condoedge\Ai\Exceptions\ReadOnlyViolationException;

class QueryExecutorTest extends TestCase
{
    private QueryExecutor $executor;
    private GraphStoreInterface $mockGraph;
    private array $config;

    public function setUp(): void
    {
        parent::setUp();

        // These tests exercise execution mechanics (read-only detection, limit
        // injection, formatting, timeouts) rather than multi-tenant filtering.
        // Disable the team-security layer so the (now fail-closed) security
        // posture doesn't short-circuit them. Security behaviour is covered by
        // QueryExecutorSecurityTest / AiSecurityServiceTest.
        config()->set('ai.security.enabled', false);

        $this->mockGraph = Mockery::mock(GraphStoreInterface::class);
        $this->config = [
            'default_timeout' => 30,
            'default_limit' => 100,
            'max_limit' => 1000,
            'read_only_mode' => true,
            'default_format' => 'table',
            'enable_explain' => true,
            'log_slow_queries' => false, // Disable logging in tests
            'slow_query_threshold_ms' => 1000,
        ];

        $this->executor = new QueryExecutor(
            $this->mockGraph,
            $this->config
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Execution Tests
    // =========================================================================

    public function test_execute_simple_query(): void
    {
        $query = 'MATCH (n:Customer) RETURN n LIMIT 10';
        $mockResults = [
            ['n' => ['id' => 1, 'name' => 'Alice']],
            ['n' => ['id' => 2, 'name' => 'Bob']],
        ];

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->with($query, [])
            ->andReturn($mockResults);

        $result = $this->executor->execute($query);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);
        $this->assertArrayHasKey('stats', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertEmpty($result['errors']);
    }

    public function test_execute_adds_limit_if_missing(): void
    {
        $query = 'MATCH (n:Customer) RETURN n';

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($q) {
                return str_contains($q, 'LIMIT');
            }), [])
            ->andReturn([]);

        $this->executor->execute($query);
    }

    public function test_execute_throws_exception_for_empty_query(): void
    {
        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('Query cannot be empty');

        $this->executor->execute('');
    }

    public function test_execute_respects_timeout_option(): void
    {
        $query = 'MATCH (n:Customer) RETURN n LIMIT 10';

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andReturn([]);

        $result = $this->executor->execute($query, [], ['timeout' => 60]);

        $this->assertEquals(60, $result['metadata']['timeout']);
    }

    public function test_execute_respects_limit_option(): void
    {
        $query = 'MATCH (n:Customer) RETURN n';

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($q) {
                return str_contains($q, 'LIMIT 50');
            }), [])
            ->andReturn([]);

        $this->executor->execute($query, [], ['limit' => 50]);
    }

    public function test_execute_formats_results_as_table(): void
    {
        $query = 'MATCH (n:Customer) RETURN n LIMIT 10';
        $mockResults = [
            ['n' => ['id' => 1, 'properties' => ['name' => 'Alice']]],
        ];

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andReturn($mockResults);

        $result = $this->executor->execute($query, [], ['format' => 'table']);

        $this->assertEquals('table', $result['metadata']['format']);
        $this->assertIsArray($result['data']);
    }

    public function test_execute_collects_statistics(): void
    {
        $query = 'MATCH (n:Customer) RETURN n LIMIT 10';

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andReturn([['n' => ['id' => 1]]]);

        $result = $this->executor->execute($query);

        $this->assertArrayHasKey('execution_time_ms', $result['stats']);
        $this->assertArrayHasKey('rows_returned', $result['stats']);
        $this->assertGreaterThanOrEqual(0, $result['stats']['execution_time_ms']);
        $this->assertEquals(1, $result['stats']['rows_returned']);
    }

    // =========================================================================
    // Read-Only Mode Tests
    // =========================================================================

    public function test_execute_blocks_write_in_readonly_mode(): void
    {
        $this->expectException(ReadOnlyViolationException::class);
        $this->expectExceptionMessage('Write operations not allowed');

        $query = 'MATCH (n:Customer) DELETE n';

        $this->executor->execute($query, [], ['read_only' => true]);
    }

    public function test_execute_allows_write_when_disabled(): void
    {
        $query = 'CREATE (n:Customer {name: "test"})';

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andReturn([]);

        $result = $this->executor->execute($query, [], ['read_only' => false]);

        $this->assertTrue($result['success']);
    }

    public function test_execute_detects_delete(): void
    {
        $this->expectException(ReadOnlyViolationException::class);

        $this->executor->execute('MATCH (n) DELETE n');
    }

    public function test_execute_detects_create(): void
    {
        $this->expectException(ReadOnlyViolationException::class);

        $this->executor->execute('CREATE (n:Test)');
    }

    public function test_execute_detects_merge(): void
    {
        $this->expectException(ReadOnlyViolationException::class);

        $this->executor->execute('MERGE (n:Test {id: 1})');
    }

    public function test_execute_detects_set(): void
    {
        $this->expectException(ReadOnlyViolationException::class);

        $this->executor->execute('MATCH (n:Test) SET n.value = 1');
    }

    // =========================================================================
    // Count Tests
    // =========================================================================

    public function test_executeCount_returns_count(): void
    {
        $query = 'MATCH (n:Customer) RETURN n';
        $countQuery = 'MATCH (n:Customer) RETURN count(*) as total';

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($q) use ($countQuery) {
                return str_contains($q, 'count(*)');
            }), [])
            ->andReturn([['total' => 42]]);

        $count = $this->executor->executeCount($query);

        $this->assertEquals(42, $count);
    }

    /**
     * Build a closure that captures the Cypher query the graph store is asked
     * to run, so the test can assert the generated count query is well-formed.
     */
    private function captureQuery(?string &$captured): \Closure
    {
        return function ($q) use (&$captured) {
            $captured = $q;
            return true;
        };
    }

    /**
     * Asserts a generated count query is structurally valid Cypher: it wraps the
     * original query in a CALL subquery (so any shape stays legal) and the
     * count(*) aggregation is the single top-level RETURN that follows it.
     */
    private function assertWellFormedCountQuery(string $query, string $inner): void
    {
        // The old wrapper prepended "WITH * MATCH" in front of an inner clause,
        // producing impossible adjacencies like "MATCH OPTIONAL MATCH",
        // "MATCH CALL" or "MATCH WITH". None of those may appear.
        $this->assertDoesNotMatchRegularExpression(
            '/\bMATCH\s+(OPTIONAL\b|CALL\b|WITH\b)/i',
            $query,
            "Count query is malformed (illegal clause after MATCH): {$query}"
        );

        // The original query must survive intact inside a CALL subquery, so any
        // shape (OPTIONAL MATCH / CALL / leading WITH / UNION) stays valid.
        $this->assertStringContainsString(
            "CALL { {$inner} }",
            $query,
            "Count query must wrap the original in a CALL subquery: {$query}"
        );

        // The aggregation must be the OUTER clause, after the subquery's closing
        // brace — never a second RETURN stacked at the top level.
        $this->assertMatchesRegularExpression(
            '/\}\s*RETURN\s+count\(\*\)/i',
            $query,
            "Count query must aggregate with count(*) outside the subquery: {$query}"
        );

        // Exactly one top-level RETURN (the aggregation): the inner query's own
        // RETURN lives inside the braces and must not be double-counted here.
        $outer = preg_replace('/CALL\s*\{.*\}/is', '', $query);
        $this->assertSame(
            1,
            preg_match_all('/\bRETURN\b/i', $outer),
            "Count query must have exactly one top-level RETURN: {$query}"
        );
    }

    public function test_executeCount_wraps_optional_match_as_valid_cypher(): void
    {
        $captured = null;

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->with(Mockery::on($this->captureQuery($captured)), [])
            ->andReturn([['total' => 7]]);

        $inner = 'OPTIONAL MATCH (n:Customer) RETURN n';
        $count = $this->executor->executeCount($inner);

        $this->assertEquals(7, $count);
        $this->assertNotNull($captured);
        $this->assertWellFormedCountQuery($captured, $inner);
    }

    public function test_executeCount_wraps_call_query_as_valid_cypher(): void
    {
        $captured = null;

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->with(Mockery::on($this->captureQuery($captured)), [])
            ->andReturn([['total' => 3]]);

        $inner = 'CALL db.labels() YIELD label RETURN label';
        $count = $this->executor->executeCount($inner);

        $this->assertEquals(3, $count);
        $this->assertNotNull($captured);
        $this->assertWellFormedCountQuery($captured, $inner);
    }

    // =========================================================================
    // Pagination Tests
    // =========================================================================

    public function test_executePaginated_returns_paginated_results(): void
    {
        // First call for count
        $this->mockGraph->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($q) {
                return str_contains($q, 'count(*)');
            }), [])
            ->andReturn([['total' => 100]]);

        // Second call for actual data
        $this->mockGraph->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($q) {
                return str_contains($q, 'SKIP 20') && str_contains($q, 'LIMIT 20');
            }), [])
            ->andReturn([['n' => ['id' => 1]]]);

        $result = $this->executor->executePaginated(
            'MATCH (n:Customer) RETURN n',
            page: 2,
            perPage: 20
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertEquals(2, $result['pagination']['current_page']);
        $this->assertEquals(20, $result['pagination']['per_page']);
        $this->assertEquals(100, $result['pagination']['total']);
        $this->assertEquals(5, $result['pagination']['last_page']);
    }

    public function test_executePaginated_validates_page_number(): void
    {
        $this->mockGraph->shouldReceive('query')
            ->twice()
            ->andReturn([['total' => 10]], []);

        $result = $this->executor->executePaginated(
            'MATCH (n:Customer) RETURN n',
            page: -1,
            perPage: 10
        );

        // Should default to page 1
        $this->assertEquals(1, $result['pagination']['current_page']);
    }

    // =========================================================================
    // Explain Tests
    // =========================================================================

    public function test_explain_returns_execution_plan(): void
    {
        $query = 'MATCH (n:Customer) RETURN n';
        $mockPlan = ['plan' => 'some plan data'];

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->with('EXPLAIN ' . $query, [])
            ->andReturn($mockPlan);

        $result = $this->executor->explain($query);

        $this->assertArrayHasKey('plan', $result);
        $this->assertArrayHasKey('query', $result);
        $this->assertEquals($query, $result['query']);
    }

    public function test_explain_throws_when_disabled(): void
    {
        $executor = new QueryExecutor($this->mockGraph, ['enable_explain' => false]);

        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('EXPLAIN is disabled');

        $executor->explain('MATCH (n) RETURN n');
    }

    // =========================================================================
    // Test Query Tests
    // =========================================================================

    public function test_test_returns_true_for_valid_query(): void
    {
        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andReturn(['plan' => []]);

        $isValid = $this->executor->test('MATCH (n:Customer) RETURN n');

        $this->assertTrue($isValid);
    }

    public function test_test_returns_false_for_invalid_query(): void
    {
        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andThrow(new \Exception('Invalid query'));

        $isValid = $this->executor->test('INVALID QUERY');

        $this->assertFalse($isValid);
    }

    // =========================================================================
    // Error Handling Tests
    // =========================================================================

    public function test_execute_wraps_exceptions(): void
    {
        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andThrow(new \Exception('Database error'));

        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('Query execution failed');

        $this->executor->execute('MATCH (n) RETURN n LIMIT 10');
    }

    // =========================================================================
    // Parameter Tests
    // =========================================================================

    public function test_execute_passes_parameters(): void
    {
        $query = 'MATCH (n:Customer {id: $id}) RETURN n LIMIT 10';
        $params = ['id' => 123];

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->with($query, $params)
            ->andReturn([]);

        $this->executor->execute($query, $params);
    }
}
