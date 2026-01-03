# Module 06: QUERY_EXECUTION - Findings

> **Status:** COMPLETED
> **File Analyzed:** `src/Services/QueryExecutor.php` (587 lines)
> **Date:** 2026-01-03

## Architecture Overview

The QueryExecutor is the central service for executing Cypher queries against Neo4j. It implements `QueryExecutorInterface` and provides:

1. **Query Execution** - execute(), executeCount(), executePaginated()
2. **Safety Controls** - Rate limiting, read-only enforcement, result limits
3. **Query Analysis** - explain(), test()
4. **Query Management** - cancel()
5. **Logging** - AiQueryLog integration for success/failure tracking

### Key Dependencies
- `GraphStoreInterface` - Abstract database layer
- `RateLimiter` - Token bucket rate limiting via Laravel Cache
- `AiQueryLog` - Query execution audit logging

## How Cypher Queries Are Executed

### Execute Flow (lines 68-186)
```
1. Merge options with defaults (timeout=30s, limit=100, read_only=true)
2. Handle empty queries (return NO QUERY context)
3. Enforce rate limiting via RateLimiter::attempt()
4. Check for write operations if read_only mode
5. Append LIMIT if not present in query
6. Execute via GraphStoreInterface::query()
7. Format results (table/graph/json)
8. Collect statistics and check for slow queries
9. Log to AiQueryLog (non-blocking)
10. Return structured response
```

### Result Formats
- **table** (default): Flattened array of rows with node properties extracted
- **graph**: Separated nodes and relationships structure
- **json**: Raw JSON-encoded results

## Rate Limiting Implementation

### RateLimiter (src/Services/Resilience/RateLimiter.php)
- Uses token bucket pattern
- Laravel Cache backend for distributed rate limiting
- Default: 30 queries per minute (configurable via `ai.rate_limits.queries_per_minute`)
- Key: `rate_limiter.query_executor`

### Integration (lines 88-93)
```php
if (!$this->rateLimiter->attempt()) {
    throw new QueryExecutionException(
        'Rate limit exceeded for query execution.'
    );
}
```

### Issue: Rate Limit Scope
The rate limiter uses a global key (`query_executor`), not per-user. This means:
- All users share the same rate limit
- One user could exhaust the limit for all users
- No per-user or per-tenant isolation

## Timeout Handling

### Current Implementation (lines 159-177)
```php
$executionTime = round((microtime(true) - $startTime) * 1000, 2);
if ($executionTime >= ($timeout * 1000)) {
    throw new QueryTimeoutException(
        "Query exceeded timeout of {$timeout} seconds"
    );
}
```

### Critical Issue: Timeout Detection Only
The timeout is detected AFTER the query returns, not enforced during execution:
- Query runs to completion on Neo4j
- Only then is execution time compared to timeout
- Long-running queries still consume resources
- This is NOT true timeout protection

### Missing: Neo4j Transaction Timeout
The GraphStoreInterface::query() does not pass timeout to Neo4j. True timeout would require:
- Neo4j connection timeout configuration
- Transaction timeout in Cypher (e.g., `dbms.transaction.timeout`)
- Use of `cancel()` method with query ID

## Read-Only Enforcement

### Write Keyword Detection (lines 29-31, 345-353)
```php
private array $writeKeywords = [
    'CREATE', 'DELETE', 'REMOVE', 'MERGE', 'SET', 'DETACH'
];
```

### Check Method
```php
private function containsWriteOperations(string $query): bool
{
    foreach ($this->writeKeywords as $keyword) {
        if (preg_match('/\b' . $keyword . '\b/i', $query)) {
            return true;
        }
    }
    return false;
}
```

### Bypass Concerns
1. **CALL Procedures**: `CALL dbms.* YIELD` procedures can modify data
2. **FOREACH**: Can contain write operations inside
3. **LOAD CSV**: Can trigger writes with CREATE inside
4. **APOC Procedures**: Many APOC procs can write data

### Missing Keywords
- `DROP` (constraints, indexes)
- `ALTER`
- `LOAD CSV` with write clauses

## Pagination Support

### executePaginated() (lines 228-269)
```php
public function executePaginated(
    string $cypherQuery,
    int $page = 1,
    int $perPage = 20,
    array $parameters = [],
    array $options = []
): array
```

### Features
- Page validation: `max(1, $page)`
- Per-page limit: `min($perPage, config max_limit ?? 1000)`
- Automatic total count via executeCount()
- SKIP/LIMIT injection: Removes existing LIMIT, adds `SKIP {offset} LIMIT {perPage}`

### Pagination Metadata
```php
'pagination' => [
    'current_page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'last_page' => ceil($total / $perPage),
    'from' => $offset + 1,
    'to' => min($offset + $perPage, $total),
]
```

## Issues Found

| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| QE-01 | HIGH | Timeout not enforced - only detected after query completes | Lines 159-177: timeout check is post-execution | Implement Neo4j transaction timeout via connection config or async execution with cancellation |
| QE-02 | MEDIUM | Rate limit is global, not per-user | RateLimiter uses fixed key `query_executor` | Add user/tenant ID to rate limiter key for per-user limits |
| QE-03 | MEDIUM | Read-only check misses CALL procedures | Line 345-353: Only checks 6 keywords | Add CALL to write keywords or implement whitelist for safe procedures |
| QE-04 | LOW | No CypherSanitizer integration | Grep shows no sanitizer exists | Create sanitizer or validate queries before execution |
| QE-05 | LOW | executeCount() has fragile regex parsing | Line 202: Simple regex for MATCH extraction | Consider AST-based query transformation |
| QE-06 | INFO | Slow query logging uses error_log() | Line 130: Not using Laravel Log facade | Change to Log::warning() for consistency |
| QE-07 | INFO | Cancel method requires external query ID | Line 325-337: cancel() needs queryId | Document how to obtain query IDs for cancellation |

## Security Analysis

### Injection Protection
**Parameterized Queries:** The system supports parameterized queries via `$parameters` argument, but:
- Parameters are passed through to GraphStoreInterface::query()
- No validation that parameters are actually used
- Caller could still concatenate values into $cypherQuery

**Recommendation:** Add query analysis to detect non-parameterized dynamic values.

### Read-Only Bypass Vectors
1. **Option Override:** Caller can set `options['read_only'] = false`
2. **Stored Procedures:** `CALL db.* YIELD` can access admin functions
3. **APOC Triggers:** If enabled, could execute writes

### Rate Limit DoS
With global rate limiting, an attacker could:
1. Send 30 rapid requests (exhaust limit)
2. Legitimate users blocked for up to 60 seconds
3. Repeat indefinitely

## Query Logging

### Success Logging (lines 489-521)
Logs to AiQueryLog:
- user_id, team_id, conversation_id
- question, cypher_query, template_used
- confidence_score, execution_time_ms, result_count
- context_stats, metadata

### Failure Logging (lines 536-562)
Logs to AiQueryLog:
- Same identifiers
- error_message included
- status = 'failed'

### Non-Blocking Design
Both logging methods are wrapped in try-catch with Log::warning() fallback, ensuring query execution isn't interrupted by logging failures.

## Code Quality

### Strengths
- Clear separation of concerns
- Interface-driven design (QueryExecutorInterface)
- Comprehensive error handling with typed exceptions
- Non-blocking logging
- Multiple result formats supported

### Areas for Improvement
- Timeout enforcement is passive, not active
- Rate limiting scope too broad
- Write detection is incomplete
- No query sanitization layer
