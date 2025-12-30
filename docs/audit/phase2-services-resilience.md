# Phase 2 Audit: Resilience Services

**Date:** 2024-12-30
**Task:** 30 - Resilience Services Review
**Path:** `src/Services/Resilience/`

## Overview

The Resilience services implement fault tolerance patterns for external service calls. Three services provide circuit breaker, retry, and rate limiting functionality.

## Files Reviewed

| File | Lines | Pattern | State Storage |
|------|-------|---------|---------------|
| CircuitBreaker.php | 351 | Circuit Breaker | Laravel Cache |
| RateLimiter.php | 99 | Token Bucket Rate Limiter | Laravel Cache |
| RetryPolicy.php | 184 | Exponential Backoff Retry | Stateless |

---

## 1. CircuitBreaker.php

### Purpose
Implements the circuit breaker pattern to prevent cascading failures when external services become unavailable.

### Pattern Implementation
**Three-state machine:**
- **CLOSED**: Normal operation, requests pass through
- **OPEN**: Failures exceeded threshold, all requests fail fast with `CircuitBreakerOpenException`
- **HALF_OPEN**: Recovery testing phase, limited requests allowed

### Configuration Options
```php
public function __construct(
    private readonly string $name,              // Circuit identifier
    private readonly int $failureThreshold = 5, // Failures before opening
    private readonly int $recoveryTimeoutSeconds = 60, // Time before recovery attempt
    private readonly int $successThreshold = 2  // Successes in half-open to close
)
```

### State Management
- Uses Laravel `Cache` facade for distributed state across workers
- Cache keys: `circuit_breaker.{name}.{state|failure_count|last_failure_time|opened_at}`
- TTL: 3600 seconds (1 hour)
- Atomic operations via `Cache::increment/decrement` for thread safety
- Local state synced from cache on each operation

### Public Methods
| Method | Purpose | Used |
|--------|---------|------|
| `call(callable)` | Execute operation with circuit protection | YES |
| `getState()` | Get current state (closed/open/half_open) | NO |
| `getFailureCount()` | Get consecutive failure count | NO |
| `isOpen()` | Check if circuit is open | NO |
| `reset()` | Manually reset to closed state | NO |

### Where Used
**Neo4jStore.php (lines 52, 452-468):**
```php
$this->circuitBreaker = new CircuitBreaker('neo4j', failureThreshold: 5, recoveryTimeoutSeconds: 30);

// In executeCypher():
return $this->circuitBreaker->call(function () use ($cypher, $parameters) {
    return $this->retryPolicy->execute(
        operation: function () use ($cypher, $parameters) {
            return $this->performHttpRequest($cypher, $parameters);
        },
        // ...
    );
});
```

### Notes/Anomalies
1. **Good**: Thread-safe with atomic cache operations
2. **Good**: Logs state transitions with context
3. **Issue**: `syncToCache()` method exists but is never called (dead code)
4. **Issue**: `getState()`, `getFailureCount()`, `isOpen()`, `reset()` are public but not used anywhere
5. **Issue**: `getCachedLastFailureTime()` stores time but is only used in `syncFromCache()`

---

## 2. RateLimiter.php

### Purpose
Implements sliding window rate limiting for API calls using a simple counter approach.

### Pattern Implementation
**Simple Counter Rate Limiter:**
- Counts requests within a time window
- Rejects requests when count exceeds limit
- Uses cache TTL as the sliding window

### Configuration Options
```php
public function __construct(
    string $key,                    // Unique identifier
    int $maxRequests = 60,          // Requests per window
    int $windowSeconds = 60         // Window duration
)
```

### State Management
- Uses Laravel `Cache` facade
- Cache key: `rate_limiter.{key}`
- TTL equals window duration
- Simple increment pattern (non-atomic read-then-write in `attempt()`)

### Public Methods
| Method | Purpose | Used |
|--------|---------|------|
| `attempt()` | Try to acquire a slot | YES (via waitAndAttempt) |
| `waitAndAttempt(int $maxWaitSeconds)` | Wait up to N seconds for slot | YES |
| `remaining()` | Get remaining requests in window | NO |
| `forLlm()` | Factory for LLM API rate limiter | YES |

### Where Used
**QueryGenerator.php (lines 86, 108, 146):**
```php
private RateLimiter $rateLimiter;
$this->rateLimiter = RateLimiter::forLlm();

// In generate():
if (!$this->rateLimiter->waitAndAttempt(10)) {
    throw new QueryGenerationException('Rate limit exceeded for LLM API');
}
```

### Notes/Anomalies
1. **Issue**: `attempt()` has race condition - reads count, checks, then writes (should use `Cache::add` or atomic lock)
2. **Issue**: `remaining()` method is never used
3. **Missing**: No factory methods for Qdrant or Neo4j rate limiting
4. **Missing**: No exponential backoff in `waitAndAttempt()` - uses fixed 100ms polling

---

## 3. RetryPolicy.php

### Purpose
Implements retry logic with exponential backoff and jitter for transient failures.

### Pattern Implementation
**Exponential Backoff with Jitter:**
- Base delay doubles each attempt: `baseDelay * 2^(attempt-1)`
- Jitter randomizes delay within a range to prevent thundering herd
- Capped at maximum delay

### Configuration Options
```php
public function __construct(
    private readonly int $maxAttempts = 3,        // Maximum retry attempts
    private readonly int $baseDelayMs = 100,      // Base delay in milliseconds
    private readonly int $maxDelayMs = 5000,      // Maximum delay cap
    private readonly float $jitter = 0.1,         // Jitter factor (0-1)
    private readonly array $retryableExceptions = [] // Exception classes to retry
)
```

### State Management
- Stateless - all state is local to the `execute()` call

### Public Methods
| Method | Purpose | Used |
|--------|---------|------|
| `execute(callable, ?callable)` | Execute with retry logic | YES |
| `forApiCalls()` | Factory: 3 attempts, 200ms base, 2s max | NO |
| `forDatabaseOperations()` | Factory: 5 attempts, 50ms base, 1s max | YES |
| `forNetworkRequests()` | Factory: 3 attempts, 500ms base, 10s max | NO |

### Where Used
**Neo4jStore.php (lines 51, 454-466):**
```php
$this->retryPolicy = RetryPolicy::forDatabaseOperations();

// In executeCypher():
return $this->retryPolicy->execute(
    operation: function () use ($cypher, $parameters) {
        return $this->performHttpRequest($cypher, $parameters);
    },
    onRetry: function (\Exception $e, int $attempt, int $delay) use ($cypher) {
        Log::warning('Neo4j request failed, retrying', [...]);
    }
);
```

### Notes/Anomalies
1. **Good**: Well-implemented exponential backoff with jitter
2. **Good**: Supports `onRetry` callback for logging
3. **Issue**: `forApiCalls()` factory method is unused
4. **Issue**: `forNetworkRequests()` factory method is unused
5. **Issue**: `retryableExceptions` parameter defaults to empty, meaning ALL exceptions trigger retries

---

## Integration Analysis

### Services WITH Resilience Patterns

| Service | CircuitBreaker | RetryPolicy | RateLimiter |
|---------|----------------|-------------|-------------|
| Neo4jStore | YES | YES | NO |
| QueryGenerator | NO | NO | YES |

### Services WITHOUT Resilience Patterns (External Calls)

| Service | External API | Missing Patterns |
|---------|--------------|------------------|
| **QdrantStore** | Qdrant HTTP API | CircuitBreaker, RetryPolicy |
| **OpenAiLlmProvider** | OpenAI Chat API | CircuitBreaker, RetryPolicy, RateLimiter |
| **AnthropicLlmProvider** | Anthropic Claude API | CircuitBreaker, RetryPolicy, RateLimiter |
| **OpenAiEmbeddingProvider** | OpenAI Embeddings API | CircuitBreaker, RetryPolicy, RateLimiter |

### Missing Resilience Coverage

1. **QdrantStore**: Makes direct cURL requests without any resilience:
   - No retry on transient failures
   - No circuit breaker for Qdrant outages
   - Will cascade failures to callers

2. **OpenAiLlmProvider**: Makes direct cURL requests without resilience:
   - Handles 429 errors but doesn't implement backoff
   - No circuit breaker for API outages
   - No rate limiting at provider level

3. **AnthropicLlmProvider**: Same issues as OpenAiLlmProvider

4. **OpenAiEmbeddingProvider**: Same issues as LLM providers

---

## Unused Methods Summary

| Class | Method | Reason |
|-------|--------|--------|
| CircuitBreaker | `getState()` | Diagnostic method, no callers |
| CircuitBreaker | `getFailureCount()` | Diagnostic method, no callers |
| CircuitBreaker | `isOpen()` | Could be used for health checks |
| CircuitBreaker | `reset()` | Manual override, no admin interface |
| CircuitBreaker | `syncToCache()` | Dead code - never called |
| RateLimiter | `remaining()` | Could be exposed via API |
| RetryPolicy | `forApiCalls()` | Unused factory |
| RetryPolicy | `forNetworkRequests()` | Unused factory |

---

## Exception Handling

### CircuitBreakerOpenException
**Location:** `src/Exceptions/CircuitBreakerOpenException.php`
```php
class CircuitBreakerOpenException extends \RuntimeException {}
```
- Simple marker exception
- Extends RuntimeException
- No additional properties or methods

---

## Architecture Diagram

```
                    +-----------------+
                    |  QueryGenerator |
                    +--------+--------+
                             |
                    +--------v--------+
                    |   RateLimiter   |
                    | (LLM rate limit)|
                    +--------+--------+
                             |
                    +--------v--------+
                    |  LlmProvider    |  <-- MISSING: CircuitBreaker, Retry
                    | (OpenAI/Claude) |
                    +-----------------+

                    +-----------------+
                    |    Neo4jStore   |
                    +--------+--------+
                             |
                    +--------v--------+
                    | CircuitBreaker  |
                    |   ('neo4j')     |
                    +--------+--------+
                             |
                    +--------v--------+
                    |   RetryPolicy   |
                    | (forDatabase)   |
                    +--------+--------+
                             |
                    +--------v--------+
                    | performHttpReq  |
                    +-----------------+

                    +-----------------+
                    |   QdrantStore   |  <-- MISSING: All resilience
                    +--------+--------+
                             |
                    +--------v--------+
                    |  Direct cURL    |
                    +-----------------+
```

---

## Recommendations

### High Priority
1. **Add resilience to QdrantStore**: Apply same pattern as Neo4jStore (CircuitBreaker + RetryPolicy)
2. **Add resilience to LLM providers**: Wrap API calls with CircuitBreaker and RetryPolicy
3. **Add resilience to Embedding providers**: Same as LLM providers
4. **Fix RateLimiter race condition**: Use `Cache::lock()` or atomic operations

### Medium Priority
1. Remove unused `syncToCache()` method from CircuitBreaker
2. Create admin endpoint to expose circuit states and allow manual resets
3. Add factory methods for missing service types in RateLimiter
4. Consider using `retryableExceptions` to limit retry scope (e.g., only network errors)

### Low Priority
1. Expose `remaining()` rate limit info in API responses
2. Add metrics/telemetry for circuit breaker transitions
3. Consider implementing bulkhead pattern for complete resilience

---

## Summary

The resilience services are well-implemented with proper patterns (circuit breaker, exponential backoff, rate limiting). However, they are **inconsistently applied** across the codebase:

- **Neo4jStore**: Fully protected with CircuitBreaker + RetryPolicy
- **QueryGenerator**: Only RateLimiter applied to LLM calls
- **QdrantStore, LLM Providers, Embedding Providers**: No resilience patterns applied

This creates a significant gap where external service failures (Qdrant, OpenAI, Anthropic) can cascade through the system without protection.
