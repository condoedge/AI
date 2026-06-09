<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Mockery;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Exceptions\QueryTimeoutException;

/**
 * Wall-clock timeout enforcement for QueryExecutor::execute (Track G - G1).
 *
 * The configured timeout must be applied on the SUCCESS path too: a query that
 * returns results but took longer than the budget is surfaced as a
 * QueryTimeoutException reflecting the configured limit, not silently returned.
 */
class QueryExecutorTimeoutTest extends TestCase
{
    private GraphStoreInterface $mockGraph;

    public function setUp(): void
    {
        parent::setUp();
        config()->set('ai.security.enabled', false);
        $this->mockGraph = Mockery::mock(GraphStoreInterface::class);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_successful_but_slow_query_raises_timeout_reflecting_configured_limit(): void
    {
        // A timeout budget of 0 seconds: any wall-clock elapsed time exceeds it,
        // so a successful query that "ran" must be reported as timed out.
        $executor = new QueryExecutor($this->mockGraph, [
            'default_timeout' => 0,
            'default_limit' => 100,
            'read_only_mode' => true,
            'default_format' => 'table',
            'log_slow_queries' => false,
        ]);

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andReturnUsing(function () {
                // Simulate a slow query that nonetheless succeeds.
                usleep(5000);
                return [['n' => ['id' => 1]]];
            });

        $this->expectException(QueryTimeoutException::class);
        $this->expectExceptionMessage('0 seconds');

        $executor->execute('MATCH (n:Customer) RETURN n LIMIT 5');
    }

    public function test_fast_query_within_timeout_returns_normally(): void
    {
        $executor = new QueryExecutor($this->mockGraph, [
            'default_timeout' => 30,
            'default_limit' => 100,
            'read_only_mode' => true,
            'default_format' => 'table',
            'log_slow_queries' => false,
        ]);

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andReturn([['n' => ['id' => 1]]]);

        $result = $executor->execute('MATCH (n:Customer) RETURN n LIMIT 5');

        $this->assertTrue($result['success']);
        $this->assertSame(30, $result['metadata']['timeout']);
    }
}
