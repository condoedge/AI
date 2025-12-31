<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Resilience;

use Condoedge\Ai\Exceptions\CircuitBreakerOpenException;
use Illuminate\Support\Facades\Cache;

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
        // Sync from cache to get the latest shared state
        $this->syncFromCache();

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
        // Use atomic increment/decrement for thread safety
        if ($this->state === self::STATE_HALF_OPEN) {
            // In half-open state, track successes to potentially close circuit
            $this->failureCount = Cache::decrement($this->cacheKey('failure_count'));

            if ($this->failureCount <= -$this->successThreshold) {
                // Enough successes - close the circuit
                $this->transitionTo(self::STATE_CLOSED);
                $this->failureCount = 0;
                $this->setCachedFailureCount(0);
            }
        } else if ($this->state === self::STATE_CLOSED) {
            // In closed state, reset failure count on success
            $currentCount = $this->getCachedFailureCount();
            if ($currentCount > 0) {
                $this->failureCount = Cache::decrement($this->cacheKey('failure_count'));
            } else {
                $this->failureCount = 0;
            }
        }
    }

    /**
     * Record a failed operation
     */
    private function recordFailure(): void
    {
        // Use atomic increment for thread safety
        $this->failureCount = Cache::increment($this->cacheKey('failure_count'));
        $this->lastFailureTime = time();
        $this->setCachedLastFailureTime($this->lastFailureTime);

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
            // Use cached openedAt for cross-process consistency
            $openedAt = $this->openedAt ?? $this->getCachedOpenedAt() ?? $now;
            $timeSinceOpened = $now - $openedAt;

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

        // Update cached state immediately
        $this->setCachedState($newState);

        if ($newState === self::STATE_OPEN) {
            $this->openedAt = time();
            $this->setCachedOpenedAt($this->openedAt);
        } else if ($newState === self::STATE_CLOSED) {
            $this->openedAt = null;
            $this->failureCount = 0;
            $this->setCachedOpenedAt(null);
            $this->setCachedFailureCount(0);
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
        $this->syncFromCache();
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
        return $this->getCachedFailureCount();
    }

    /**
     * Check if circuit is open
     *
     * @return bool True if circuit is open
     */
    public function isOpen(): bool
    {
        $this->syncFromCache();
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

        // Clear all cached state
        $this->setCachedState(self::STATE_CLOSED);
        $this->setCachedFailureCount(0);
        $this->setCachedLastFailureTime(null);
        $this->setCachedOpenedAt(null);
    }

    /**
     * Cache TTL in seconds (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * Get the cached circuit state
     */
    private function getCachedState(): string
    {
        return Cache::get($this->cacheKey('state'), self::STATE_CLOSED);
    }

    /**
     * Set the cached circuit state
     */
    private function setCachedState(string $state): void
    {
        Cache::put($this->cacheKey('state'), $state, self::CACHE_TTL);
    }

    /**
     * Get the cached failure count
     */
    private function getCachedFailureCount(): int
    {
        return (int) Cache::get($this->cacheKey('failure_count'), 0);
    }

    /**
     * Set the cached failure count
     */
    private function setCachedFailureCount(int $count): void
    {
        Cache::put($this->cacheKey('failure_count'), $count, self::CACHE_TTL);
    }

    /**
     * Get the cached last failure time
     */
    private function getCachedLastFailureTime(): ?int
    {
        return Cache::get($this->cacheKey('last_failure_time'));
    }

    /**
     * Set the cached last failure time
     */
    private function setCachedLastFailureTime(?int $timestamp): void
    {
        if ($timestamp === null) {
            Cache::forget($this->cacheKey('last_failure_time'));
        } else {
            Cache::put($this->cacheKey('last_failure_time'), $timestamp, self::CACHE_TTL);
        }
    }

    /**
     * Get the cached opened at timestamp
     */
    private function getCachedOpenedAt(): ?int
    {
        return Cache::get($this->cacheKey('opened_at'));
    }

    /**
     * Set the cached opened at timestamp
     */
    private function setCachedOpenedAt(?int $timestamp): void
    {
        if ($timestamp === null) {
            Cache::forget($this->cacheKey('opened_at'));
        } else {
            Cache::put($this->cacheKey('opened_at'), $timestamp, self::CACHE_TTL);
        }
    }

    /**
     * Generate a cache key for this circuit breaker
     */
    private function cacheKey(string $suffix): string
    {
        return "circuit_breaker.{$this->name}.{$suffix}";
    }

    /**
     * Sync local state from cache
     * Call this to ensure local properties reflect cached state
     */
    private function syncFromCache(): void
    {
        $this->state = $this->getCachedState();
        $this->failureCount = $this->getCachedFailureCount();
        $this->lastFailureTime = $this->getCachedLastFailureTime();
        $this->openedAt = $this->getCachedOpenedAt();
    }

}
