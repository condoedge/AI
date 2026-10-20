<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Resilience;

use Condoedge\Ai\Services\Resilience\RateLimiter;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Eval p15 (2026-10-06): turns voided with "Rate limit exceeded for query execution".
 * The limiter rewrote its counter with a FRESH ttl on every hit, so "30 per minute" was
 * really "30 until a full minute of silence" — steady traffic (an eval turn every 20 s, or
 * a few users chatting) accumulated forever. The window is fixed from its first hit.
 */
class RateLimiterTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function the_window_starts_at_the_first_hit_and_is_not_extended_by_later_hits(): void
    {
        Carbon::setTestNow('2026-10-06 10:00:00');
        $limiter = new RateLimiter('t_window', maxRequests: 3, windowSeconds: 60);

        $this->assertTrue($limiter->attempt());
        Carbon::setTestNow('2026-10-06 10:00:30');
        $this->assertTrue($limiter->attempt());
        Carbon::setTestNow('2026-10-06 10:00:50');
        $this->assertTrue($limiter->attempt());
        $this->assertFalse($limiter->attempt(), 'the 4th hit inside the window is refused');

        // 65 s after the FIRST hit the window is over, although the last hit was 15 s ago.
        Carbon::setTestNow('2026-10-06 10:01:05');
        $this->assertTrue($limiter->attempt());
    }

    /** @test */
    public function refused_hits_do_not_prolong_the_window(): void
    {
        Carbon::setTestNow('2026-10-06 10:00:00');
        $limiter = new RateLimiter('t_refused', maxRequests: 1, windowSeconds: 60);

        $this->assertTrue($limiter->attempt());
        Carbon::setTestNow('2026-10-06 10:00:40');
        $this->assertFalse($limiter->attempt());
        Carbon::setTestNow('2026-10-06 10:01:01');
        $this->assertTrue($limiter->attempt());
    }

    /** @test The caller can know HOW LONG until a slot frees - to wait in line or tell the user. */
    public function retry_after_reports_the_seconds_left_in_a_full_window_without_consuming(): void
    {
        Carbon::setTestNow('2026-10-06 10:00:00');
        $limiter = new RateLimiter('t_retry', maxRequests: 2, windowSeconds: 60);

        $this->assertSame(0, $limiter->retryAfter(), 'nothing charged yet');
        $limiter->attempt();
        $this->assertSame(0, $limiter->retryAfter(), 'a slot is still free');
        $limiter->attempt();

        Carbon::setTestNow('2026-10-06 10:00:45');
        $this->assertSame(15, $limiter->retryAfter());
        $this->assertSame(15, $limiter->retryAfter(), 'reading it consumes nothing');
        $this->assertSame(0, $limiter->retryAfter('u:2'), 'another scope is unaffected');

        Carbon::setTestNow('2026-10-06 10:01:00');
        $this->assertSame(0, $limiter->retryAfter());
        $this->assertTrue($limiter->attempt());
    }

    /** @test */
    public function each_scope_has_its_own_budget(): void
    {
        $limiter = new RateLimiter('t_scope', maxRequests: 2, windowSeconds: 60);

        $this->assertTrue($limiter->attempt('u:1'));
        $this->assertTrue($limiter->attempt('u:1'));
        $this->assertFalse($limiter->attempt('u:1'), 'user 1 is out of budget');
        $this->assertTrue($limiter->attempt('u:2'), 'user 2 is unaffected');
        $this->assertTrue($limiter->attempt(), 'the unscoped budget is separate too');
    }
}
