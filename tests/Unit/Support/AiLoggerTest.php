<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/**
 * AiLoggerTest
 *
 * Tests the AiLogger utility class covering:
 * - Trace ID generation and uniqueness
 * - Debug logging respecting config flag
 * - Fallback logging as warning level
 * - Timer start/stop and elapsed calculation
 * - Info, warning, and error log delegation
 */
class AiLoggerTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Default: debug disabled
        $app['config']->set('ai.debug', false);
        $app['config']->set('ai.log_channel', 'stack');
    }

    /** @test */
    public function startTrace_generates_unique_trace_id(): void
    {
        $logger = new AiLogger();

        // Allow the log call that startTrace makes
        Log::shouldReceive('channel->info')->once();

        $traceId = $logger->startTrace('test');

        $this->assertNotNull($traceId);
        $this->assertIsString($traceId);
        $this->assertSame($traceId, $logger->getTraceId());
    }

    /** @test */
    public function startTrace_generates_different_ids_on_subsequent_calls(): void
    {
        $logger = new AiLogger();

        Log::shouldReceive('channel->info')->twice();

        $first = $logger->startTrace('test1');
        $second = $logger->startTrace('test2');

        $this->assertNotEquals($first, $second);
        $this->assertSame($second, $logger->getTraceId());
    }

    /** @test */
    public function getTraceId_returns_null_before_startTrace(): void
    {
        $logger = new AiLogger();

        $this->assertNull($logger->getTraceId());
    }

    /** @test */
    public function debug_is_noop_when_debug_disabled(): void
    {
        config(['ai.debug' => false]);

        $logger = new AiLogger();

        Log::shouldReceive('channel->debug')->never();
        Log::shouldReceive('channel')->never();

        $logger->debug('test', 'should not log');
    }

    /** @test */
    public function debug_logs_when_debug_enabled(): void
    {
        config(['ai.debug' => true]);

        $logger = new AiLogger();

        Log::shouldReceive('channel->debug')->once();

        $logger->debug('test', 'should log');
    }

    /** @test */
    public function info_logs_at_info_level(): void
    {
        $logger = new AiLogger();

        Log::shouldReceive('channel->info')->once();

        $logger->info('service', 'info message');
    }

    /** @test */
    public function warning_logs_at_warning_level(): void
    {
        $logger = new AiLogger();

        Log::shouldReceive('channel->warning')->once();

        $logger->warning('service', 'warning message');
    }

    /** @test */
    public function error_logs_at_error_level(): void
    {
        $logger = new AiLogger();

        Log::shouldReceive('channel->error')->once();

        $logger->error('service', 'error message');
    }

    /** @test */
    public function fallback_logs_as_warning(): void
    {
        $logger = new AiLogger();

        Log::shouldReceive('channel->warning')->once();

        $logger->fallback('test', 'from', 'to', 'reason');
    }

    /** @test */
    public function timer_returns_elapsed_ms(): void
    {
        $logger = new AiLogger();

        $logger->startTimer('op');
        usleep(10000); // 10ms
        $elapsed = $logger->stopTimer('op');

        $this->assertIsFloat($elapsed);
        $this->assertGreaterThan(0, $elapsed);
    }

    /** @test */
    public function stopTimer_without_start_returns_near_zero(): void
    {
        $logger = new AiLogger();

        // Calling stopTimer without startTimer should still return a float
        // (it falls back to microtime(true) as the start)
        $elapsed = $logger->stopTimer('nonexistent');

        $this->assertIsFloat($elapsed);
        $this->assertGreaterThanOrEqual(0, $elapsed);
    }

    /** @test */
    public function multiple_timers_can_run_independently(): void
    {
        $logger = new AiLogger();

        $logger->startTimer('fast');
        $logger->startTimer('slow');
        usleep(5000); // 5ms

        $fastElapsed = $logger->stopTimer('fast');
        usleep(5000); // another 5ms

        $slowElapsed = $logger->stopTimer('slow');

        $this->assertGreaterThan(0, $fastElapsed);
        $this->assertGreaterThan(0, $slowElapsed);
        // Slow timer ran longer, so it should have a larger elapsed time
        $this->assertGreaterThan($fastElapsed, $slowElapsed);
    }

    /** @test */
    public function it_constructs_when_no_log_channel_is_configured(): void
    {
        // A bare host (or Testbench) may leave both keys unset. The logger is
        // resolved eagerly during command registration, so a TypeError at
        // construction would crash every console command.
        config()->set('ai.log_channel', null);
        config()->set('logging.default', null);

        $logger = new AiLogger();

        $this->assertInstanceOf(AiLogger::class, $logger);
    }
}
