<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Digest;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Services\Digest\AttentionSourceRegistry;
use Condoedge\Ai\Services\Digest\DigestRunner;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Resilience\RateLimiter;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * G2/L5 item F — digest self-rate-limit fix (iteration-11 verdict: digest t2
 * printed "could not be checked" for two sources because a 9-source digest run
 * plus companion counts starved the SHARED query_executor limiter, 30/min).
 *
 * MECHANISM PINNED HERE: a digest run is ONE budgeted batch. The DigestRunner
 * mints a fresh, unguessable `rate_limit_batch` key per run and threads it into
 * every source / fallback / companion-count execution; QueryExecutor charges
 * the shared limiter ONCE per batch key (first execution pays a slot, the rest
 * of the batch rides it). Normal turns carry no batch key and stay charged
 * per query — the DoS posture for generated queries is unchanged.
 */
class DigestRateLimitBatchTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function executorWith(RateLimiter $limiter, GraphStoreInterface $graph): QueryExecutor
    {
        // Security layer off: these tests exercise rate-limit mechanics only
        // (same posture as QueryExecutorRateLimitTest).
        config()->set('ai.security.enabled', false);

        return new QueryExecutor($graph, [
            'default_timeout' => 30,
            'default_limit' => 100,
            'read_only_mode' => true,
            'default_format' => 'table',
            'log_slow_queries' => false,
        ], $limiter);
    }

    // =====================================================================
    // Executor: one limiter charge per batch key
    // =====================================================================

    /** @test */
    public function executions_sharing_a_batch_key_charge_the_limiter_exactly_once(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('attempt')->once()->andReturn(true);

        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('query')->times(5)->andReturn([]);

        $executor = $this->executorWith($limiter, $graph);

        for ($i = 0; $i < 5; $i++) {
            $result = $executor->execute(
                'MATCH (n:Task) RETURN n',
                [],
                ['rate_limit_batch' => 'digest:abc123']
            );
            $this->assertTrue($result->success);
        }
    }

    /** @test */
    public function a_batch_whose_first_charge_is_rejected_still_fails_closed(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('attempt')->once()->andReturn(false);

        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldNotReceive('query');

        $executor = $this->executorWith($limiter, $graph);

        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $executor->execute('MATCH (n:Task) RETURN n', [], ['rate_limit_batch' => 'digest:abc123']);
    }

    /** @test */
    public function executions_without_a_batch_key_stay_charged_per_query(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('attempt')->times(2)->andReturn(true, false);

        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('query')->once()->andReturn([]);

        $executor = $this->executorWith($limiter, $graph);

        $this->assertTrue($executor->execute('MATCH (a:A) RETURN a')->success);

        $this->expectException(QueryExecutionException::class);
        $executor->execute('MATCH (b:B) RETURN b');
    }

    /** @test */
    public function different_batch_keys_each_pay_their_own_charge(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('attempt')->times(2)->andReturn(true, true);

        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('query')->times(2)->andReturn([]);

        $executor = $this->executorWith($limiter, $graph);

        $this->assertTrue($executor->execute('MATCH (a:A) RETURN a', [], ['rate_limit_batch' => 'digest:one'])->success);
        $this->assertTrue($executor->execute('MATCH (b:B) RETURN b', [], ['rate_limit_batch' => 'digest:two'])->success);
    }

    /** @test */
    public function execute_count_rides_the_same_batch_charge(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('attempt')->once()->andReturn(true);

        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('query')->times(2)->andReturn([['total' => 42]]);

        $executor = $this->executorWith($limiter, $graph);

        $executor->execute('MATCH (n:Task) RETURN n', [], ['rate_limit_batch' => 'digest:abc']);
        $count = $executor->executeCount('MATCH (n:Task) RETURN n', [], ['rate_limit_batch' => 'digest:abc']);

        $this->assertSame(42, $count);
    }

    // =====================================================================
    // Runner: every digest execution shares ONE freshly-minted batch key
    // =====================================================================

    /** @test */
    public function the_runner_threads_one_fresh_batch_key_into_every_source_and_count(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $batchKeys = [];

        $executor->shouldReceive('execute')
            ->times(3)
            ->andReturnUsing(function ($cypher, $params, $options) use (&$batchKeys) {
                $batchKeys[] = $options['rate_limit_batch'] ?? null;
                // First source cap-hits (5 rows at cap 5) to force a companion count.
                if (str_contains($cypher, ':Task')) {
                    return new QueryExecutionResult(true, array_map(fn ($i) => ['id' => $i], range(1, 5)), [], [], []);
                }
                return new QueryExecutionResult(true, [['id' => 1]], [], [], []);
            });
        $executor->shouldReceive('executeCount')
            ->once()
            ->andReturnUsing(function ($cypher, $params, $options) use (&$batchKeys) {
                $batchKeys[] = $options['rate_limit_batch'] ?? null;
                return 130;
            });

        $runner = new DigestRunner($executor, new AttentionSourceRegistry([
            'open_tasks' => ['label' => 'Tasks', 'cypher' => 'MATCH (t:Task) RETURN t.id AS id'],
            'bills' => ['label' => 'Bills', 'cypher' => 'MATCH (b:Bill) RETURN b.id AS id'],
            'threads' => ['label' => 'Threads', 'cypher' => 'MATCH (t:Thread) RETURN t.id AS id'],
        ], ['max_sources' => 9, 'default_row_cap' => 5]));

        // A poisoned caller-supplied batch key must be REPLACED, never trusted.
        $runner->run(['user' => (object) ['id' => 13], 'rate_limit_batch' => 'attacker-chosen']);

        $this->assertCount(4, $batchKeys, 'every execution (3 sources + 1 count) must carry the batch key');
        $this->assertNotContains(null, $batchKeys, 'no digest execution may run without the batch key');
        $this->assertNotContains('attacker-chosen', $batchKeys, 'caller-supplied batch keys are stripped');
        $this->assertCount(1, array_unique($batchKeys), 'one digest run = ONE shared batch key');
        $this->assertStringStartsWith('digest:', (string) $batchKeys[0]);
    }
}
