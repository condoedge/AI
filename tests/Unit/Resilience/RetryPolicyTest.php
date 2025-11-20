<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Resilience;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\Resilience\RetryPolicy;

/**
 * RetryPolicyTest
 *
 * Tests retry logic with exponential backoff
 */
class RetryPolicyTest extends TestCase
{
    /** @test */
    public function it_succeeds_on_first_attempt()
    {
        $policy = new RetryPolicy(maxAttempts: 3);
        $callCount = 0;

        $result = $policy->execute(function () use (&$callCount) {
            $callCount++;
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(1, $callCount);
    }

    /** @test */
    public function it_retries_on_failure_and_eventually_succeeds()
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 10);
        $callCount = 0;

        $result = $policy->execute(function () use (&$callCount) {
            $callCount++;

            if ($callCount < 3) {
                throw new \RuntimeException('Temporary failure');
            }

            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $callCount);
    }

    /** @test */
    public function it_throws_exception_after_max_retries()
    {
        $policy = new RetryPolicy(maxAttempts: 2, baseDelayMs: 10);
        $callCount = 0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Always fails');

        $policy->execute(function () use (&$callCount) {
            $callCount++;
            throw new \RuntimeException('Always fails');
        });
    }

    /** @test */
    public function it_calls_onRetry_callback()
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 10);
        $retryCallbacks = [];

        try {
            $policy->execute(
                operation: function () {
                    throw new \RuntimeException('Fail');
                },
                onRetry: function (\Exception $e, int $attempt, int $delay) use (&$retryCallbacks) {
                    $retryCallbacks[] = [
                        'attempt' => $attempt,
                        'delay' => $delay,
                        'message' => $e->getMessage(),
                    ];
                }
            );
        } catch (\RuntimeException $e) {
            // Expected to fail
        }

        // Should have called onRetry twice (attempts 1 and 2 before final failure on 3)
        $this->assertCount(2, $retryCallbacks);
        $this->assertEquals(1, $retryCallbacks[0]['attempt']);
        $this->assertEquals(2, $retryCallbacks[1]['attempt']);
    }

    /** @test */
    public function it_applies_exponential_backoff()
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 100, jitter: 0);
        $delays = [];

        try {
            $policy->execute(
                operation: function () {
                    throw new \RuntimeException('Fail');
                },
                onRetry: function (\Exception $e, int $attempt, int $delay) use (&$delays) {
                    $delays[] = $delay;
                }
            );
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Exponential backoff: 100ms, 200ms
        $this->assertEquals(100, $delays[0]);
        $this->assertEquals(200, $delays[1]);
    }

    /** @test */
    public function it_respects_max_delay()
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 1000,
            maxDelayMs: 2000,
            jitter: 0
        );
        $delays = [];

        try {
            $policy->execute(
                operation: function () {
                    throw new \RuntimeException('Fail');
                },
                onRetry: function (\Exception $e, int $attempt, int $delay) use (&$delays) {
                    $delays[] = $delay;
                }
            );
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Should cap at maxDelay (2000ms)
        foreach ($delays as $delay) {
            $this->assertLessThanOrEqual(2000, $delay);
        }
    }

    /** @test */
    public function it_creates_api_call_policy()
    {
        $policy = RetryPolicy::forApiCalls();
        $this->assertInstanceOf(RetryPolicy::class, $policy);
    }

    /** @test */
    public function it_creates_database_policy()
    {
        $policy = RetryPolicy::forDatabaseOperations();
        $this->assertInstanceOf(RetryPolicy::class, $policy);
    }

    /** @test */
    public function it_creates_network_policy()
    {
        $policy = RetryPolicy::forNetworkRequests();
        $this->assertInstanceOf(RetryPolicy::class, $policy);
    }
}
