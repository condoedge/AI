# Module 06: QUERY_EXECUTION - Documentation Updates

> **Status:** COMPLETED
> **Date:** 2026-01-03

## Required Changes

| Doc Path | Change Type | Description |
|----------|-------------|-------------|
| docs/architecture/query-execution.md | CREATE | Document QueryExecutor flow, options, and security controls |
| docs/security/read-only-enforcement.md | CREATE | Document read-only limitations and bypass vectors |
| docs/configuration/rate-limiting.md | UPDATE | Add per-user rate limiting recommendation |
| docs/troubleshooting/slow-queries.md | CREATE | Document slow query detection and timeout behavior |

## Detailed Changes

### 1. Query Execution Architecture (NEW)

**Path:** `docs/architecture/query-execution.md`

**Content to Add:**
```markdown
# Query Execution

## Overview
The QueryExecutor service handles all Cypher query execution with safety controls.

## Execution Options
| Option | Default | Description |
|--------|---------|-------------|
| timeout | 30 | Max execution time in seconds (detection only) |
| limit | 100 | Max results returned |
| read_only | true | Block write operations |
| format | 'table' | Result format: table/graph/json |
| include_stats | true | Include execution statistics |

## Important Limitation: Timeout Behavior
The timeout setting is NOT actively enforced. It only DETECTS if a query
exceeded the timeout after it completes. Long-running queries will still
consume full execution time on Neo4j.

For true timeout protection, configure Neo4j transaction timeout:
- `dbms.transaction.timeout` in neo4j.conf
- Connection timeout in database driver

## Read-Only Mode
Read-only mode blocks these keywords: CREATE, DELETE, REMOVE, MERGE, SET, DETACH

**Known Gaps:**
- CALL procedures (may execute writes)
- FOREACH with write clauses
- APOC procedures that modify data
- DROP, ALTER operations
```

### 2. Rate Limiting Configuration (UPDATE)

**Path:** `docs/configuration/rate-limiting.md`

**Add Section:**
```markdown
## Query Execution Rate Limits

### Current Behavior (Global)
Rate limiting is applied globally across all users:
- Config: `ai.rate_limits.queries_per_minute` (default: 30)
- Key: `rate_limiter.query_executor`

### Recommended: Per-User Rate Limiting
To prevent one user from exhausting limits for all users, consider:

1. Modify RateLimiter instantiation in QueryExecutor:
   ```php
   $this->rateLimiter = new RateLimiter(
       key: 'query_executor_user_' . auth()->id(),
       maxRequests: config('ai.rate_limits.queries_per_minute', 30),
       windowSeconds: 60
   );
   ```

2. Or implement tiered limits:
   - Guest users: 10/minute
   - Authenticated: 30/minute
   - Premium: 100/minute
```

### 3. Security: Read-Only Enforcement (NEW)

**Path:** `docs/security/read-only-enforcement.md`

**Content:**
```markdown
# Read-Only Enforcement

## How It Works
QueryExecutor checks for write operation keywords before execution.

## Detected Write Keywords
- CREATE
- DELETE
- REMOVE
- MERGE
- SET
- DETACH

## Known Bypass Vectors

### 1. CALL Procedures
```cypher
CALL dbms.createDatabase('malicious')
CALL apoc.trigger.add('name', 'CREATE (:Node)', {})
```

### 2. FOREACH with Writes
```cypher
MATCH (n) FOREACH (x IN range(1,5) | CREATE (:Node))
```

### 3. LOAD CSV with Writes
```cypher
LOAD CSV FROM 'file:///data.csv' AS row CREATE (:Node {name: row[0]})
```

## Recommendations
1. Add CALL to blocked keywords list
2. Implement whitelist for safe read-only procedures
3. Use Neo4j's built-in read-only user/role if available
4. Consider Neo4j Fabric for enforced read routing
```

### 4. Slow Query Troubleshooting (NEW)

**Path:** `docs/troubleshooting/slow-queries.md`

**Content:**
```markdown
# Slow Query Detection and Troubleshooting

## Detection
Queries exceeding the slow query threshold are logged:
- Config: `ai.slow_query_threshold_ms` (default: 1000ms)
- Logged via: `error_log()` (note: not Laravel Log facade)

## Viewing Slow Queries
Check PHP error log for messages:
```
Slow query detected (1523ms): MATCH (u:User)-[:PURCHASED]->...
```

## Common Causes
1. Missing indexes on filtered properties
2. Unbounded variable-length paths: `(a)-[*]->(b)`
3. Large OPTIONAL MATCH results
4. Cartesian products from unconnected patterns

## Solutions
1. Add indexes: `CREATE INDEX FOR (n:Label) ON (n.property)`
2. Limit path length: `(a)-[*1..3]->(b)`
3. Use EXPLAIN to analyze query plan
4. Apply LIMIT earlier in query

## Using EXPLAIN
```php
$executor = app(QueryExecutorInterface::class);
$plan = $executor->explain('MATCH (u:User) RETURN u LIMIT 10');
```

Returns execution plan with estimated rows.
```

## API Documentation Updates

### QueryExecutorInterface PHPDoc
The interface is well-documented. No updates needed.

### QueryExecutor PHPDoc Additions

Add to class docblock:
```php
/**
 * @warning Timeout is detected post-execution, not actively enforced.
 * @warning Read-only check may miss CALL procedures and FOREACH writes.
 * @see RateLimiter for rate limiting implementation
 */
```

## Configuration Reference

### Required Config Keys
```php
// config/ai.php
return [
    'rate_limits' => [
        'queries_per_minute' => 30,  // Global query rate limit
    ],
    'query_executor' => [
        'default_timeout' => 30,           // Seconds (detection only)
        'default_limit' => 100,            // Max results
        'max_limit' => 1000,               // Pagination max per_page
        'read_only_mode' => true,          // Default read-only
        'default_format' => 'table',       // Result format
        'slow_query_threshold_ms' => 1000, // Slow query log threshold
        'log_slow_queries' => true,        // Enable slow query logging
        'enable_explain' => true,          // Allow EXPLAIN queries
    ],
];
```
