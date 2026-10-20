<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Resilience\RateLimiter;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;

/**
 * Owner request 2026-10-06: a rate limit must not surface as "something went wrong".
 * A turn takes its single charge UP FRONT (before any LLM call) and, when the budget is
 * full, waits in line for the window to reopen; only a wait longer than the bound is
 * refused — with the exact seconds to tell the user.
 */
class QueryExecutorAdmissionTest extends TestCase
{
    private $graph;
    /** @var list<int> */
    private array $slept = [];

    public function setUp(): void
    {
        parent::setUp();
        config(['ai.security.enabled' => false, 'cache.default' => 'array']);
        Cache::flush();
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->graph = Mockery::mock(GraphStoreInterface::class);
        $this->slept = [];
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function executor(int $perUser, int $global): QueryExecutor
    {
        return new QueryExecutor(
            $this->graph,
            ['default_limit' => 100, 'read_only_mode' => true],
            new RateLimiter('adm_user', $perUser, 60),
            new RateLimiter('adm_global', $global, 60)
        );
    }

    /** Sleeping advances the clock, exactly like real time would. */
    private function sleeper(): callable
    {
        return function (int $seconds): void {
            $this->slept[] = $seconds;
            Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
        };
    }

    private function turn(int $userId, string $batch): array
    {
        return ['user' => (object) ['id' => $userId], 'rate_limit_batch' => $batch];
    }

    /** @test */
    public function a_turn_inside_the_budget_is_admitted_at_once_and_its_executions_are_free(): void
    {
        $executor = $this->executor(perUser: 1, global: 10);
        $this->graph->shouldReceive('query')->andReturn([]);

        $result = $executor->admit($this->turn(13, 'turn:a'), 20, $this->sleeper());

        $this->assertSame(['admitted' => true, 'waited_seconds' => 0, 'retry_after' => 0], $result);
        $this->assertSame([], $this->slept);
        // The single charge is spent: the turn's own executions do not pay again…
        $executor->execute('MATCH (n:Customer) RETURN n LIMIT 1', [], $this->turn(13, 'turn:a'));
        $executor->execute('MATCH (n:Customer) RETURN n LIMIT 2', [], $this->turn(13, 'turn:a'));
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function a_full_budget_makes_the_turn_wait_in_line_until_the_window_reopens(): void
    {
        $executor = $this->executor(perUser: 1, global: 10);
        $executor->admit($this->turn(13, 'turn:a'), 20, $this->sleeper());

        Carbon::setTestNow('2026-10-06 10:00:45'); // 15 s left in the user's window
        $result = $executor->admit($this->turn(13, 'turn:b'), 20, $this->sleeper());

        $this->assertTrue($result['admitted']);
        $this->assertSame(15, $result['waited_seconds']);
        $this->assertSame([15], $this->slept, 'it slept exactly until the window reopened');
    }

    /** @test */
    public function a_wait_longer_than_the_bound_is_refused_with_the_exact_seconds_and_consumes_nothing(): void
    {
        $executor = $this->executor(perUser: 1, global: 10);
        $executor->admit($this->turn(13, 'turn:a'), 20, $this->sleeper());

        Carbon::setTestNow('2026-10-06 10:00:15'); // 45 s left
        $result = $executor->admit($this->turn(13, 'turn:b'), 20, $this->sleeper());

        $this->assertSame(['admitted' => false, 'waited_seconds' => 0, 'retry_after' => 45], $result);
        $this->assertSame([], $this->slept, 'never sleeps for a wait it will not complete');

        // Nothing was consumed by the refusal: once the window reopens the turn gets in.
        Carbon::setTestNow('2026-10-06 10:01:00');
        $this->assertTrue($executor->admit($this->turn(13, 'turn:b'), 20, $this->sleeper())['admitted']);
    }

    /** @test */
    public function the_global_cap_makes_everyone_wait_but_another_users_own_budget_is_untouched(): void
    {
        $executor = $this->executor(perUser: 5, global: 1);
        $executor->admit($this->turn(13, 'turn:a'), 20, $this->sleeper());

        Carbon::setTestNow('2026-10-06 10:00:50'); // global window: 10 s left
        $result = $executor->admit($this->turn(14, 'turn:b'), 20, $this->sleeper());

        $this->assertTrue($result['admitted']);
        $this->assertSame(10, $result['waited_seconds']);
    }
}
