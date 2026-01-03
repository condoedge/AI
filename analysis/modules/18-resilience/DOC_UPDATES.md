# Module 18: RESILIENCE - Documentation Updates

> **Status:** COMPLETED

## Existing Documentation Review

No dedicated documentation exists for the resilience module. The inline PHPDoc in each class is thorough with usage examples.

---

## Recommended Documentation Additions

### 1. Configuration Reference (add to main docs)

```markdown
## Resilience Configuration

### Rate Limits (`config/ai.php`)

```php
'rate_limits' => [
    'llm_requests_per_minute' => env('AI_LLM_RATE_LIMIT', 60),
    'queries_per_minute' => env('AI_QUERY_RATE_LIMIT', 30),
],
```

### Circuit Breaker Settings

Currently hardcoded in integration points:
- Neo4j: `failureThreshold: 5, recoveryTimeoutSeconds: 30`

Consider moving to config for production tuning.
```

### 2. Operations Runbook Entry

```markdown
## Resilience Monitoring

### Circuit Breaker States

Monitor log entries matching: `Circuit breaker '*' transitioned:`

**CLOSED -> OPEN**: Service experiencing failures. Check:
- Neo4j connectivity
- Network issues
- Resource exhaustion

**OPEN -> HALF_OPEN**: Recovery timeout elapsed, system testing connectivity.

**HALF_OPEN -> CLOSED**: Service recovered.

**HALF_OPEN -> OPEN**: Recovery test failed, back to protection mode.

### Manual Circuit Breaker Reset

```php
// In Tinker or maintenance script
$breaker = new \Condoedge\Ai\Services\Resilience\CircuitBreaker('neo4j');
$breaker->reset();
```

### Rate Limit Exceeded

Log pattern: `Rate limit exceeded` with `key` context.

Resolution:
1. Check if legitimate traffic spike
2. Adjust `ai.rate_limits.*` config values
3. Consider scaling if sustained
```

### 3. Developer Guide Entry

```markdown
## Using Resilience Patterns

### Protecting New External Calls

```php
use Condoedge\Ai\Services\Resilience\{CircuitBreaker, RetryPolicy, RateLimiter};

class MyExternalService
{
    private CircuitBreaker $circuitBreaker;
    private RetryPolicy $retryPolicy;
    private RateLimiter $rateLimiter;

    public function __construct()
    {
        $this->circuitBreaker = new CircuitBreaker(
            name: 'my-service',
            failureThreshold: 3,
            recoveryTimeoutSeconds: 60
        );
        $this->retryPolicy = RetryPolicy::forNetworkRequests();
        $this->rateLimiter = new RateLimiter('my-service', maxRequests: 100, windowSeconds: 60);
    }

    public function call(): mixed
    {
        if (!$this->rateLimiter->attempt()) {
            throw new RateLimitExceededException();
        }

        return $this->circuitBreaker->call(function () {
            return $this->retryPolicy->execute(function () {
                return $this->httpClient->get('/api/endpoint');
            });
        });
    }
}
```

### Best Practices

1. **Layer order matters**: Circuit breaker outside, retry inside
2. **Configure thresholds per service**: Database = fast recovery; external API = longer
3. **Use appropriate retry presets**: Don't retry API calls 5 times with long delays
4. **Log on state transitions**: Already built-in, monitor in production
```

---

## PHPDoc Improvements (Optional)

The inline documentation is good. Minor suggestions:

### CircuitBreaker.php - Add @throws to class docblock
```php
/**
 * @throws CircuitBreakerOpenException When circuit is open and rejects request
 */
```

### RateLimiter.php - Document config keys
```php
/**
 * Default rate limits read from config:
 * - ai.rate_limits.llm_requests_per_minute
 * - ai.rate_limits.queries_per_minute
 */
```

---

## Files Requiring Updates

| File | Priority | Update Needed |
|------|----------|---------------|
| (new) `docs/resilience.md` | Medium | Create operations guide |
| `config/ai.php` | Low | Document rate limit keys |
| README.md | Low | Add resilience section reference |
