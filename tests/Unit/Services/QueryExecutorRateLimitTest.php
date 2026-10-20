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

        // These tests exercise rate-limiting mechanics rather than multi-tenant
        // filtering. Disable the team-security layer so the (now fail-closed)
        // security posture doesn't short-circuit them before the rate-limit
        // branches run. Security behaviour is covered by
        // QueryExecutorSecurityTest / AiSecurityServiceTest.
        config()->set('ai.security.enabled', false);

        $this->mockGraph = Mockery::mock(GraphStoreInterface::class);
        $this->mockRateLimiter = Mockery::mock(RateLimiter::class);
        $this->config = [
            'default_timeout' => 30,
            'default_limit' => 100,
            'read_only_mode' => true,
            'default_format' => 'table',
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

    /**
     * @test Eval p15: the budget was ONE global counter — a few users (or two eval runs)
     * locked each other out. Each acting user counts against their own budget; an
     * execution with no user counts against the shared one.
     */
    public function the_budget_is_counted_per_acting_user(): void
    {
        $this->mockRateLimiter->shouldReceive('attempt')->once()->with('u:13')->andReturn(false);

        try {
            $this->executor->execute('MATCH (n:Customer) RETURN n LIMIT 10', [], ['user' => (object) ['id' => 13]]);
            $this->fail('expected the rate-limit refusal');
        } catch (QueryExecutionException $e) {
            $this->assertStringContainsString('Rate limit exceeded', $e->getMessage());
        }

        $this->mockRateLimiter->shouldReceive('attempt')->once()->with('')->andReturn(false);
        $this->expectException(QueryExecutionException::class);
        $this->executor->execute('MATCH (n:Customer) RETURN n LIMIT 10');
    }

    /**
     * @test Owner decision 2026-10-06: per-user budgets PLUS a global cap (all users together),
     * so a burst across many users cannot flood the graph either.
     */
    public function a_global_cap_applies_on_top_of_the_per_user_budget(): void
    {
        $global = Mockery::mock(RateLimiter::class);
        $executor = new QueryExecutor($this->mockGraph, $this->config, $this->mockRateLimiter, $global);

        // The user is inside their own budget, the global cap is exhausted -> refused.
        $this->mockRateLimiter->shouldReceive('attempt')->once()->with('u:13')->andReturn(true);
        $global->shouldReceive('attempt')->once()->withNoArgs()->andReturn(false);

        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('Rate limit exceeded for query execution');
        $executor->execute('MATCH (n:Customer) RETURN n LIMIT 10', [], ['user' => (object) ['id' => 13]]);
    }

    /** @test A user already over their own budget never consumes the global cap. */
    public function a_user_over_budget_does_not_consume_the_global_cap(): void
    {
        $global = Mockery::mock(RateLimiter::class);
        $executor = new QueryExecutor($this->mockGraph, $this->config, $this->mockRateLimiter, $global);

        $this->mockRateLimiter->shouldReceive('attempt')->once()->with('u:13')->andReturn(false);
        $global->shouldNotReceive('attempt');

        $this->expectException(QueryExecutionException::class);
        $executor->execute('MATCH (n:Customer) RETURN n LIMIT 10', [], ['user' => (object) ['id' => 13]]);
    }

    /** @test One turn (a rate_limit_batch) charges the user budget AND the global cap once. */
    public function a_batch_charges_both_budgets_once(): void
    {
        $global = Mockery::mock(RateLimiter::class);
        $executor = new QueryExecutor($this->mockGraph, $this->config, $this->mockRateLimiter, $global);

        $this->mockRateLimiter->shouldReceive('attempt')->once()->with('u:13')->andReturn(true);
        $global->shouldReceive('attempt')->once()->andReturn(true);
        $this->mockGraph->shouldReceive('query')->andReturn([]);

        $options = ['user' => (object) ['id' => 13], 'rate_limit_batch' => 'turn:abc'];
        $executor->execute('MATCH (n:Customer) RETURN n LIMIT 10', [], $options);
        $executor->execute('MATCH (n:Customer) RETURN n LIMIT 5', [], $options);
        $this->addToAssertionCount(1);
    }

    /** @test The default global cap comes from config; 0 disables it. */
    public function the_global_cap_is_configurable_and_can_be_disabled(): void
    {
        config(['ai.rate_limits.global_queries_per_minute' => 0, 'cache.default' => 'array']);
        $executor = new QueryExecutor($this->mockGraph, $this->config, $this->mockRateLimiter);

        // No global limiter is consulted: 3 allowed user charges -> 3 executions.
        $this->mockRateLimiter->shouldReceive('attempt')->times(3)->andReturn(true);
        $this->mockGraph->shouldReceive('query')->andReturn([]);
        for ($i = 0; $i < 3; $i++) {
            $executor->execute('MATCH (n:Customer) RETURN n LIMIT 10');
        }

        config(['ai.rate_limits.global_queries_per_minute' => 2]);
        \Illuminate\Support\Facades\Cache::flush();
        $capped = new QueryExecutor($this->mockGraph, $this->config, $this->mockRateLimiter);
        $this->mockRateLimiter->shouldReceive('attempt')->andReturn(true);
        $capped->execute('MATCH (n:Customer) RETURN n LIMIT 10');
        $capped->execute('MATCH (n:Customer) RETURN n LIMIT 10');
        $this->expectException(QueryExecutionException::class);
        $capped->execute('MATCH (n:Customer) RETURN n LIMIT 10');
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

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data);
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
        $this->assertTrue($result1->success);

        // Second query succeeds
        $result2 = $this->executor->execute('MATCH (n:Product) RETURN n LIMIT 10');
        $this->assertTrue($result2->success);

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

        $this->assertTrue($result->success);
        $this->assertEmpty($result->data);
        $this->assertSame([], $result->errors);
        $this->assertEquals('NO QUERY', $result->metadata['context']);
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

        $this->assertTrue($result->success);
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
