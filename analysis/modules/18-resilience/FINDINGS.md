# Module 18: RESILIENCE - Findings

> **Status:** COMPLETED

## Executive Summary

The resilience module implements three classic fault-tolerance patterns: Circuit Breaker, Rate Limiter, and Retry Policy. All three are correctly implemented and properly integrated with the Neo4j graph store and query services. The patterns work together to provide layered protection against cascading failures.

---

## Component Analysis

### 1. CircuitBreaker (`src/Services/Resilience/CircuitBreaker.php`)

**Purpose:** Prevents cascading failures by failing fast when a service is unhealthy.

**State Machine:**
```
CLOSED (normal) --[failures >= threshold]--> OPEN (reject all)
OPEN --[recovery timeout elapsed]--> HALF_OPEN (test requests)
HALF_OPEN --[success >= threshold]--> CLOSED
HALF_OPEN --[single failure]--> OPEN
```

**State Persistence:**
- Uses Laravel Cache for distributed state across workers
- Cache keys: `circuit_breaker.{name}.{state|failure_count|last_failure_time|opened_at}`
- TTL: 3600 seconds (1 hour)
- Atomic operations via `Cache::increment()` / `Cache::decrement()` for thread safety

**Configuration Parameters:**
| Parameter | Default | Description |
|-----------|---------|-------------|
| `name` | required | Unique identifier for the circuit |
| `failureThreshold` | 5 | Failures before opening |
| `recoveryTimeoutSeconds` | 60 | Seconds before testing recovery |
| `successThreshold` | 2 | Successes in half-open to close |

**Integration:** Used in `Neo4jStore` with `failureThreshold: 5, recoveryTimeoutSeconds: 30`

---

### 2. RateLimiter (`src/Services/Resilience/RateLimiter.php`)

**Purpose:** Prevents API exhaustion and DoS via request throttling.

**Algorithm:** Sliding window counter using Laravel Cache.

**Rate Limiting Triggers:**
1. **LLM API calls** (`QueryGenerator`): Uses `RateLimiter::forLlm()` with `waitAndAttempt(10)` - blocks up to 10 seconds waiting for slot
2. **Query execution** (`QueryExecutor`): Uses `attempt()` - immediate rejection if limit exceeded

**Configuration:**
| Context | Max Requests | Window | Behavior |
|---------|--------------|--------|----------|
| LLM API | `config('ai.rate_limits.llm_requests_per_minute', 60)` | 60s | Blocking wait |
| Query Executor | `config('ai.rate_limits.queries_per_minute', 30)` | 60s | Immediate reject |

**Methods:**
- `attempt()`: Non-blocking, returns `false` if rate limited
- `waitAndAttempt($maxWait)`: Blocking, polls every 100ms until slot available or timeout

---

### 3. RetryPolicy (`src/Services/Resilience/RetryPolicy.php`)

**Purpose:** Handles transient failures with exponential backoff.

**Algorithm:** Exponential backoff with jitter
```
delay = min(baseDelay * 2^(attempt-1) * (1 +/- jitter), maxDelay)
```

**Configuration Presets:**
| Preset | Max Attempts | Base Delay | Max Delay | Jitter | Use Case |
|--------|--------------|------------|-----------|--------|----------|
| `forApiCalls()` | 3 | 200ms | 2000ms | 0.2 | External API calls |
| `forDatabaseOperations()` | 5 | 50ms | 1000ms | 0.1 | Database queries |
| `forNetworkRequests()` | 3 | 500ms | 10000ms | 0.3 | Network requests |

**Features:**
- Configurable retryable exception types (empty = retry all)
- `onRetry` callback for logging/metrics
- Jitter prevents thundering herd problem

**Integration:** Used in `Neo4jStore` with `forDatabaseOperations()` preset

---

## Integration Pattern

The `Neo4jStore.executeQuery()` uses the resilience components in a layered approach:

```php
// Layer 1: Circuit breaker wraps everything
return $this->circuitBreaker->call(function () {
    // Layer 2: Retry policy handles transient failures
    return $this->retryPolicy->execute(function () {
        // Layer 3: Actual HTTP request to Neo4j
        return $this->performHttpRequest($cypher, $parameters);
    });
});
```

This layering ensures:
1. Circuit breaker fails fast when Neo4j is completely down
2. Retry policy handles temporary network blips
3. Each retry counts toward circuit breaker failure threshold

---

## Issues Found

| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| R-001 | Low | RateLimiter race condition | Lines 44-56: `get` then `put` is not atomic | Use `Cache::increment()` with initial value or Lua script for atomicity |
| R-002 | Info | No metrics/telemetry integration | No events dispatched for monitoring | Add event dispatching for observability |
| R-003 | Info | Hardcoded cache TTL | `CACHE_TTL = 3600` constant | Consider making configurable |

### R-001 Details (Race Condition)

The RateLimiter has a TOCTOU (time-of-check-time-of-use) race condition:

```php
$current = (int) Cache::get($this->key, 0);  // Check
if ($current >= $this->maxRequests) {        // Decision
    return false;
}
Cache::put($this->key, $current + 1, ...);   // Use
```

Between the `get` and `put`, another process could increment the counter, potentially allowing more requests than `maxRequests`.

**Impact:** Low - only affects high-concurrency scenarios and results in slightly more requests than intended, not a security issue.

**Fix:** Use atomic increment:
```php
$current = Cache::increment($this->key);
if ($current === 1) {
    Cache::put($this->key, 1, $this->windowSeconds); // Set TTL on first request
}
if ($current > $this->maxRequests) {
    return false;
}
return true;
```

---

## Verification Results

| Check | Status | Notes |
|-------|--------|-------|
| Circuit breaker state machine correct | PASS | All transitions properly implemented |
| Cache persistence for distributed state | PASS | Uses Laravel Cache with appropriate TTL |
| Retry backoff calculation correct | PASS | Exponential with jitter, capped at maxDelay |
| Rate limiter integrates with config | PASS | Reads from `ai.rate_limits.*` config |
| Exception handling propagates correctly | PASS | CircuitBreakerOpenException thrown when open |
| Thread safety (mostly) | PARTIAL | CircuitBreaker uses atomic ops; RateLimiter has minor race condition |
