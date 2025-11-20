<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Resilience;

use Condoedge\Ai\Exceptions\CircuitBreakerOpenException;

/**
 * CircuitBreaker
 *
 * Implements the circuit breaker pattern to prevent cascading failures.
 *
 * States:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: Failures exceeded threshold, all requests fail fast
 * - HALF_OPEN: Testing if service recovered, limited requests allowed
 *
 * Usage:
 * ```php
 * $breaker = new CircuitBreaker('neo4j', failureThreshold: 5, recoveryTimeout: 60);
 * $result = $breaker->call(function() {
 *     return $this->neo4jQuery();
 * });
 * ```
 */
class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private ?int $lastFailureTime = null;
    private ?int $openedAt = null;

    /**
     * @param string $name Circuit breaker name (for logging/identification)
     * @param int $failureThreshold Number of failures before opening circuit
     * @param int $recoveryTimeoutSeconds Seconds to wait before attempting recovery
     * @param int $successThreshold Number of successes in half-open state to close circuit
     */
    public function __construct(
        private readonly string $name,
        private readonly int $failureThreshold = 5,
        private readonly int $recoveryTimeoutSeconds = 60,
        private readonly int $successThreshold = 2
    ) {
    }

    /**
     * Execute a callable protected by the circuit breaker
     *
     * @param callable $operation The operation to execute
     * @return mixed The result of the operation
     * @throws CircuitBreakerOpenException If circuit is open
     * @throws \Exception The exception from the operation if it fails
     */
    public function call(callable $operation): mixed
    {
        // Check if circuit should transition states
        $this->updateState();

        // If circuit is open, fail fast
        if ($this->state === self::STATE_OPEN) {
            throw new CircuitBreakerOpenException(
                "Circuit breaker '{$this->name}' is OPEN. Service unavailable."
            );
        }

        try {
            $result = $operation();

            // Operation succeeded - record success
            $this->recordSuccess();

            return $result;

        } catch (\Exception $e) {
            // Operation failed - record failure
            $this->recordFailure();

            // Re-throw the exception
            throw $e;
        }
    }

    /**
     * Record a successful operation
     */
    private function recordSuccess(): void
    {
        if ($this->state === self::STATE_HALF_OPEN) {
            // In half-open state, track successes to potentially close circuit
            $this->failureCount--;

            if ($this->failureCount <= -$this->successThreshold) {
                // Enough successes - close the circuit
                $this->transitionTo(self::STATE_CLOSED);
                $this->failureCount = 0;
            }
        } else if ($this->state === self::STATE_CLOSED) {
            // In closed state, reset failure count on success
            $this->failureCount = max(0, $this->failureCount - 1);
        }
    }

    /**
     * Record a failed operation
     */
    private function recordFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = time();

        // Check if we should open the circuit
        if ($this->state === self::STATE_CLOSED && $this->failureCount >= $this->failureThreshold) {
            $this->transitionTo(self::STATE_OPEN);
        } else if ($this->state === self::STATE_HALF_OPEN) {
            // Single failure in half-open state reopens circuit
            $this->transitionTo(self::STATE_OPEN);
        }
    }

    /**
     * Update circuit state based on timeouts
     */
    private function updateState(): void
    {
        if ($this->state === self::STATE_OPEN) {
            $now = time();
            $timeSinceOpened = $now - ($this->openedAt ?? $now);

            // Check if recovery timeout has elapsed
            if ($timeSinceOpened >= $this->recoveryTimeoutSeconds) {
                $this->transitionTo(self::STATE_HALF_OPEN);
            }
        }
    }

    /**
     * Transition to a new state
     *
     * @param string $newState The state to transition to
     */
    private function transitionTo(string $newState): void
    {
        $oldState = $this->state;
        $this->state = $newState;

        if ($newState === self::STATE_OPEN) {
            $this->openedAt = time();
        } else if ($newState === self::STATE_CLOSED) {
            $this->openedAt = null;
            $this->failureCount = 0;
        }

        // Log state transition
        \Illuminate\Support\Facades\Log::info(
            "Circuit breaker '{$this->name}' transitioned: {$oldState} -> {$newState}",
            [
                'circuit' => $this->name,
                'from_state' => $oldState,
                'to_state' => $newState,
                'failure_count' => $this->failureCount,
            ]
        );
    }

    /**
     * Get current circuit state
     *
     * @return string Current state (closed, open, half_open)
     */
    public function getState(): string
    {
        $this->updateState();
        return $this->state;
    }

    /**
     * Get current failure count
     *
     * @return int Number of consecutive failures
     */
    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    /**
     * Check if circuit is open
     *
     * @return bool True if circuit is open
     */
    public function isOpen(): bool
    {
        $this->updateState();
        return $this->state === self::STATE_OPEN;
    }

    /**
     * Manually reset the circuit breaker
     */
    public function reset(): void
    {
        $this->transitionTo(self::STATE_CLOSED);
        $this->failureCount = 0;
        $this->lastFailureTime = null;
        $this->openedAt = null;
    }
}
