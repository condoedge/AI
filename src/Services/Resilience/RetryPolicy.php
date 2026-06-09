<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Resilience;

/**
 * RetryPolicy
 *
 * Implements retry logic with exponential backoff for transient failures.
 *
 * Usage:
 * ```php
 * $policy = new RetryPolicy(maxAttempts: 3, baseDelay: 100);
 * $result = $policy->execute(function() {
 *     return $this->apiCall();
 * });
 * ```
 */
class RetryPolicy
{
    /**
     * @param int $maxAttempts Maximum number of retry attempts
     * @param int $baseDelayMs Base delay in milliseconds (will be multiplied exponentially)
     * @param int $maxDelayMs Maximum delay in milliseconds to prevent excessive waits
     * @param float $jitter Jitter factor (0-1) to randomize delays and prevent thundering herd
     * @param array $retryableExceptions List of exception class names that should trigger retries
     */
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 100,
        private readonly int $maxDelayMs = 5000,
        private readonly float $jitter = 0.1,
        private readonly array $retryableExceptions = []
    ) {
    }

    /**
     * Execute a callable with retry logic
     *
     * @param callable $operation The operation to execute
     * @param callable|null $onRetry Optional callback executed before each retry
     * @return mixed The result of the operation
     * @throws \Exception The last exception if all retries fail
     */
    public function execute(callable $operation, ?callable $onRetry = null): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            try {
                return $operation();

            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;

                if (!$this->shouldRetry($e, $attempt)) {
                    throw $e;
                }

                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }

                $delay = $this->calculateDelay($attempt);

                if ($onRetry !== null) {
                    $onRetry($e, $attempt, $delay);
                }

                usleep($delay * 1000); // Convert ms to microseconds
            }
        }

        // Unreachable in practice; throw the last exception as a final guard.
        throw $lastException ?? new \RuntimeException('Retry failed with no exception');
    }

    /**
     * Determine if an exception should trigger a retry
     *
     * @param \Exception $exception The exception that was thrown
     * @param int $attempt Current attempt number
     * @return bool True if should retry, false otherwise
     */
    private function shouldRetry(\Exception $exception, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        if (!empty($this->retryableExceptions)) {
            foreach ($this->retryableExceptions as $exceptionClass) {
                if ($exception instanceof $exceptionClass) {
                    return true;
                }
            }
            return false;
        }

        // Default: retry on all exceptions (can be overridden by specifying retryableExceptions)
        return true;
    }

    /**
     * Calculate delay for current attempt using exponential backoff with jitter
     *
     * Formula: delay = min(baseDelay * 2^attempt * (1 ± jitter), maxDelay)
     *
     * @param int $attempt Current attempt number (1-indexed)
     * @return int Delay in milliseconds
     */
    private function calculateDelay(int $attempt): int
    {
        // Exponential backoff: baseDelay * 2^(attempt-1)
        $exponentialDelay = $this->baseDelayMs * (2 ** ($attempt - 1));

        // Apply jitter to prevent thundering herd
        $jitterRange = $exponentialDelay * $this->jitter;
        $jitterAmount = mt_rand(
            (int) -$jitterRange,
            (int) $jitterRange
        );

        $delay = $exponentialDelay + $jitterAmount;

        return min((int) $delay, $this->maxDelayMs);
    }

    /**
     * Create a retry policy for API calls (3 attempts, moderate backoff)
     *
     * @return self
     */
    public static function forApiCalls(): self
    {
        return new self(
            maxAttempts: 3,
            baseDelayMs: 200,
            maxDelayMs: 2000,
            jitter: 0.2
        );
    }

    /**
     * Create a retry policy for database operations (5 attempts, faster backoff)
     *
     * @return self
     */
    public static function forDatabaseOperations(): self
    {
        return new self(
            maxAttempts: 5,
            baseDelayMs: 50,
            maxDelayMs: 1000,
            jitter: 0.1
        );
    }

    /**
     * Create a retry policy for network requests (3 attempts, longer backoff)
     *
     * @return self
     */
    public static function forNetworkRequests(): self
    {
        return new self(
            maxAttempts: 3,
            baseDelayMs: 500,
            maxDelayMs: 10000,
            jitter: 0.3
        );
    }
}
