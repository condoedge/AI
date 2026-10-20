<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Resilience;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RateLimiter
 *
 * Fixed-window rate limiting (optionally per scope) for query execution and API calls.
 * Uses Laravel Cache for distributed rate limiting across workers.
 */
class RateLimiter
{
    private string $key;
    private int $maxRequests;
    private int $windowSeconds;

    /**
     * @param string $key Unique identifier for this rate limiter
     * @param int $maxRequests Maximum requests allowed in the window
     * @param int $windowSeconds Time window in seconds
     */
    public function __construct(
        string $key,
        int $maxRequests = 60,
        int $windowSeconds = 60
    ) {
        $this->key = "rate_limiter.{$key}";
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Attempt to acquire a slot for a request.
     *
     * FIXED WINDOW: the window opens at its first hit and closes $windowSeconds later.
     * (Until 2026-10-06 every hit rewrote the counter with a fresh ttl, so the window
     * never closed under steady traffic — "30 per minute" meant "30 until a full minute
     * of silence", and an eval turn every 20 s accumulated to a self-inflicted refusal.)
     * The window start rides in the value itself, so no cache driver's ttl handling on
     * increment matters.
     *
     * @param string $scope Optional sub-budget (e.g. "u:13" for one user): each scope
     *                      counts on its own; '' is the limiter's shared budget.
     * @return bool True if request is allowed, false if rate limited
     */
    public function attempt(string $scope = ''): bool
    {
        $key = $scope === '' ? $this->key : $this->key . '.' . $scope;
        $now = \Illuminate\Support\Carbon::now()->getTimestamp();

        $state = Cache::get($key);
        $startedAt = is_array($state) ? (int) ($state['started_at'] ?? 0) : 0;
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        if ($startedAt === 0 || $now - $startedAt >= $this->windowSeconds) {
            $startedAt = $now;
            $count = 0;
        }

        if ($count >= $this->maxRequests) {
            Log::warning("Rate limit exceeded", [
                'key' => $key,
                'current' => $count,
                'max' => $this->maxRequests,
            ]);
            return false;
        }

        // Expire with the window (never later): the remaining seconds, not a fresh ttl.
        $remaining = max(1, $this->windowSeconds - ($now - $startedAt));
        Cache::put($key, ['started_at' => $startedAt, 'count' => $count + 1], $remaining);

        return true;
    }

    /**
     * Seconds until a slot is available in this scope's window; 0 = available now.
     * Read-only (consumes nothing) — lets a caller wait in line or tell the user
     * exactly how long, instead of failing the request.
     */
    public function retryAfter(string $scope = ''): int
    {
        $key = $scope === '' ? $this->key : $this->key . '.' . $scope;
        $now = \Illuminate\Support\Carbon::now()->getTimestamp();

        $state = Cache::get($key);
        if (!is_array($state)) {
            return 0;
        }
        $elapsed = $now - (int) ($state['started_at'] ?? 0);
        if ($elapsed >= $this->windowSeconds || (int) ($state['count'] ?? 0) < $this->maxRequests) {
            return 0;
        }

        return max(1, $this->windowSeconds - $elapsed);
    }

    /**
     * Wait until a slot is available, then proceed
     *
     * @param int $maxWaitSeconds Maximum time to wait
     * @return bool True if slot acquired, false if timeout
     */
    public function waitAndAttempt(int $maxWaitSeconds = 30): bool
    {
        $startTime = time();

        while (time() - $startTime < $maxWaitSeconds) {
            if ($this->attempt()) {
                return true;
            }
            usleep(100000); // Wait 100ms between attempts
        }

        return false;
    }

    /**
     * Create a rate limiter for LLM API calls
     */
    public static function forLlm(): self
    {
        return new self(
            key: 'llm_api',
            maxRequests: (int) config('ai.rate_limits.llm_requests_per_minute', 60),
            windowSeconds: 60
        );
    }
}
