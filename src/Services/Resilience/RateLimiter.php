<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Resilience;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RateLimiter
 *
 * Implements token bucket rate limiting for API calls.
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
     * Attempt to acquire a slot for a request
     *
     * @return bool True if request is allowed, false if rate limited
     */
    public function attempt(): bool
    {
        $current = (int) Cache::get($this->key, 0);

        if ($current >= $this->maxRequests) {
            Log::warning("Rate limit exceeded", [
                'key' => $this->key,
                'current' => $current,
                'max' => $this->maxRequests,
            ]);
            return false;
        }

        Cache::put($this->key, $current + 1, $this->windowSeconds);
        return true;
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
