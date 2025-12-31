<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Mockery;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\Resilience\RateLimiter;
use Condoedge\Ai\Exceptions\QueryExecutionException;

class QueryExecutorRateLimitTest extends TestCase
{
    private QueryExecutor $executor;
    private GraphStoreInterface $mockGraph;
    private RateLimiter $mockRateLimiter;
    private array $config;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockGraph = Mockery::mock(GraphStoreInterface::class);
        $this->mockRateLimiter = Mockery::mock(RateLimiter::class);
        $this->config = [
            'default_timeout' => 30,
            'default_limit' => 100,
            'max_limit' => 1000,
            'read_only_mode' => true,
            'default_format' => 'table',
            'enable_explain' => true,
            'log_slow_queries' => false,
            'slow_query_threshold_ms' => 1000,
        ];

        $this->executor = new QueryExecutor(
            $this->mockGraph,
            $this->config,
            $this->mockRateLimiter
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Rate Limiting Tests
    // =========================================================================

    /** @test */
    public function it_enforces_rate_limit_on_query_execution(): void
    {
        // Configure rate limiter to reject the request
        $this->mockRateLimiter->shouldReceive('attempt')
            ->once()
            ->andReturn(false);

        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('Rate limit exceeded for query execution');

        $this->executor->execute('MATCH (n:Customer) RETURN n LIMIT 10');
    }

    /** @test */
    public function it_allows_queries_within_rate_limit(): void
    {
        // Configure rate limiter to allow the request
        $this->mockRateLimiter->shouldReceive('attempt')
            ->once()
            ->andReturn(true);

        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andReturn([
                ['n' => ['id' => 1, 'name' => 'Alice']],
            ]);

        $result = $this->executor->execute('MATCH (n:Customer) RETURN n LIMIT 10');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']);
    }

    /** @test */
    public function it_enforces_rate_limit_on_multiple_queries(): void
    {
        // First two queries succeed, third is rate limited
        $this->mockRateLimiter->shouldReceive('attempt')
            ->times(3)
            ->andReturn(true, true, false);

        $this->mockGraph->shouldReceive('query')
            ->twice()
            ->andReturn([]);

        // First query succeeds
        $result1 = $this->executor->execute('MATCH (n:Customer) RETURN n LIMIT 10');
        $this->assertTrue($result1['success']);

        // Second query succeeds
        $result2 = $this->executor->execute('MATCH (n:Product) RETURN n LIMIT 10');
        $this->assertTrue($result2['success']);

        // Third query should be rate limited
        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->executor->execute('MATCH (n:Order) RETURN n LIMIT 10');
    }

    /** @test */
    public function it_skips_rate_limit_check_for_empty_queries(): void
    {
        // Empty queries should return early without checking rate limit
        // (since they don't actually execute against the database)
        $this->mockRateLimiter->shouldNotReceive('attempt');

        $result = $this->executor->execute('');

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['data']);
        $this->assertEquals('NO QUERY', $result['context']);
    }

    /** @test */
    public function it_enforces_rate_limit_on_executeCount(): void
    {
        // Rate limiter should be called for executeCount as well
        $this->mockRateLimiter->shouldReceive('attempt')
            ->once()
            ->andReturn(false);

        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->executor->executeCount('MATCH (n:Customer) RETURN n');
    }

    /** @test */
    public function it_enforces_rate_limit_on_executePaginated(): void
    {
        // Paginated queries make multiple execute calls, rate limit on first
        $this->mockRateLimiter->shouldReceive('attempt')
            ->once()
            ->andReturn(false);

        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->executor->executePaginated('MATCH (n:Customer) RETURN n', 1, 20);
    }

    /** @test */
    public function it_creates_default_rate_limiter_when_none_provided(): void
    {
        // Create executor without explicit rate limiter
        $executor = new QueryExecutor(
            $this->mockGraph,
            $this->config
        );

        // Should work with default rate limiter (will use cache)
        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andReturn([]);

        $result = $executor->execute('MATCH (n:Customer) RETURN n LIMIT 10');

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function it_includes_retry_message_in_rate_limit_exception(): void
    {
        $this->mockRateLimiter->shouldReceive('attempt')
            ->once()
            ->andReturn(false);

        try {
            $this->executor->execute('MATCH (n:Customer) RETURN n LIMIT 10');
            $this->fail('Expected QueryExecutionException was not thrown');
        } catch (QueryExecutionException $e) {
            $this->assertStringContainsString('Rate limit exceeded', $e->getMessage());
            $this->assertStringContainsString('Please wait', $e->getMessage());
        }
    }
}
