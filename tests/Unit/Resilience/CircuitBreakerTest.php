<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Resilience;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\Resilience\CircuitBreaker;
use Condoedge\Ai\Exceptions\CircuitBreakerOpenException;

/**
 * CircuitBreakerTest
 *
 * Tests circuit breaker pattern implementation
 */
class CircuitBreakerTest extends TestCase
{
    /** @test */
    public function it_starts_in_closed_state()
    {
        $breaker = new CircuitBreaker('test');

        $this->assertEquals('closed', $breaker->getState());
        $this->assertFalse($breaker->isOpen());
    }

    /** @test */
    public function it_allows_successful_calls_in_closed_state()
    {
        $breaker = new CircuitBreaker('test');

        $result = $breaker->call(function () {
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals('closed', $breaker->getState());
    }

    /** @test */
    public function it_opens_after_failure_threshold()
    {
        $breaker = new CircuitBreaker('test', failureThreshold: 3);

        // Trigger 3 failures
        for ($i = 0; $i < 3; $i++) {
            try {
                $breaker->call(function () {
                    throw new \RuntimeException('Fail');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        // Circuit should now be open
        $this->assertEquals('open', $breaker->getState());
        $this->assertTrue($breaker->isOpen());
    }

    /** @test */
    public function it_fails_fast_when_open()
    {
        $breaker = new CircuitBreaker('test', failureThreshold: 2);

        // Trigger failures to open circuit
        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(function () {
                    throw new \RuntimeException('Fail');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        // Circuit is open, next call should fail fast
        $this->expectException(CircuitBreakerOpenException::class);
        $this->expectExceptionMessage("Circuit breaker 'test' is OPEN");

        $breaker->call(function () {
            return 'should not execute';
        });
    }

    /** @test */
    public function it_transitions_to_half_open_after_timeout()
    {
        $breaker = new CircuitBreaker('test', failureThreshold: 1, recoveryTimeoutSeconds: 1);

        // Trigger failure to open circuit
        try {
            $breaker->call(function () {
                throw new \RuntimeException('Fail');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertEquals('open', $breaker->getState());

        // Wait for recovery timeout
        sleep(2);

        // Should transition to half-open
        $this->assertEquals('half_open', $breaker->getState());
    }

    /** @test */
    public function it_closes_after_successful_calls_in_half_open()
    {
        $breaker = new CircuitBreaker(
            'test',
            failureThreshold: 1,
            recoveryTimeoutSeconds: 1,
            successThreshold: 2
        );

        // Open the circuit
        try {
            $breaker->call(function () {
                throw new \RuntimeException('Fail');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertEquals('open', $breaker->getState());

        // Wait for recovery
        sleep(2);

        // Make successful calls to close circuit (need successThreshold + failures to accumulate)
        $breaker->call(function () { return 'success'; });
        $breaker->call(function () { return 'success'; });
        $breaker->call(function () { return 'success'; }); // Extra success needed

        $this->assertEquals('closed', $breaker->getState());
    }

    /** @test */
    public function it_reopens_on_failure_in_half_open()
    {
        $breaker = new CircuitBreaker('test', failureThreshold: 1, recoveryTimeoutSeconds: 1);

        // Open the circuit
        try {
            $breaker->call(function () {
                throw new \RuntimeException('Fail');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Wait for recovery
        sleep(2);

        $this->assertEquals('half_open', $breaker->getState());

        // Fail in half-open state - should reopen
        try {
            $breaker->call(function () {
                throw new \RuntimeException('Fail again');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertEquals('open', $breaker->getState());
    }

    /** @test */
    public function it_tracks_failure_count()
    {
        $breaker = new CircuitBreaker('test', failureThreshold: 5);

        $this->assertEquals(0, $breaker->getFailureCount());

        // Trigger 3 failures
        for ($i = 0; $i < 3; $i++) {
            try {
                $breaker->call(function () {
                    throw new \RuntimeException('Fail');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->assertEquals(3, $breaker->getFailureCount());
    }

    /** @test */
    public function it_resets_circuit()
    {
        $breaker = new CircuitBreaker('test', failureThreshold: 1);

        // Open the circuit
        try {
            $breaker->call(function () {
                throw new \RuntimeException('Fail');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertEquals('open', $breaker->getState());

        // Reset
        $breaker->reset();

        $this->assertEquals('closed', $breaker->getState());
        $this->assertEquals(0, $breaker->getFailureCount());
    }

    /** @test */
    public function it_decreases_failure_count_on_success_in_closed_state()
    {
        $breaker = new CircuitBreaker('test', failureThreshold: 5);

        // Trigger 2 failures
        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(function () {
                    throw new \RuntimeException('Fail');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->assertEquals(2, $breaker->getFailureCount());

        // Success should decrease failure count
        $breaker->call(function () { return 'success'; });

        $this->assertEquals(1, $breaker->getFailureCount());
    }
}
