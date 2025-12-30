# Phase 2: File-by-File Review - Exceptions

**Audit Date:** 2025-12-30
**Directory:** `src/Exceptions/`
**Total Files:** 9 Exception classes

## Review Checklist

| File | Reviewed | Thrown | Caught | Status |
|------|----------|--------|--------|--------|
| CircuitBreakerOpenException.php | Yes | 1 | 0 | Thrown, caught via \Exception |
| CypherInjectionException.php | Yes | 4 | 0 | Thrown, caught via \InvalidArgumentException |
| DataConsistencyException.php | Yes | 4 | 2 | ACTIVE |
| QueryExecutionException.php | Yes | 4 | 0 | Thrown, caught via \Exception |
| QueryGenerationException.php | Yes | 2 | 0 | Thrown, caught via \Exception |
| QueryTimeoutException.php | Yes | 1 | 0 | Thrown, caught via \Exception |
| QueryValidationException.php | Yes | 0 | 0 | **DEAD CODE** |
| ReadOnlyViolationException.php | Yes | 1 | 0 | Thrown, caught via \Exception |
| UnsafeQueryException.php | Yes | 0 | 0 | **DEAD CODE** |

---

## Exception Hierarchy

```
\Exception
  \InvalidArgumentException
    CypherInjectionException
    QueryValidationException
  \RuntimeException
    CircuitBreakerOpenException
    DataConsistencyException
    QueryExecutionException
    QueryGenerationException
    QueryTimeoutException
    ReadOnlyViolationException
    UnsafeQueryException
```

---

## Detailed Reviews

### CircuitBreakerOpenException.php
**Path:** `src/Exceptions/CircuitBreakerOpenException.php`

**1. What it represents:**
Exception thrown when a circuit breaker is open and rejects requests. Used in the resilience pattern to fail-fast when a dependent service is unavailable.

**2. Inherits from:** `\RuntimeException`

**3. Custom properties/methods:** None (empty class body)

**4. Where thrown:**
| File | Line | Context |
|------|------|---------|
| `src/Services/Resilience/CircuitBreaker.php` | 71 | When circuit state is `STATE_OPEN`, fails fast with message identifying which circuit breaker is open |

**5. Where caught:**
- **Not explicitly caught** - caught by generic `catch (\Exception $e)` handlers
- `CircuitBreaker::execute()` line 84 catches `\Exception` and re-throws after recording failure
- Callers of `CircuitBreaker::execute()` use generic exception handlers

**6. Notes/Anomalies:**
- Properly used for circuit breaker pattern fail-fast behavior
- No specific catch blocks - relies on parent class catching
- Message includes circuit breaker name for debugging: `"Circuit breaker '{$this->name}' is OPEN. Service unavailable."`

---

### CypherInjectionException.php
**Path:** `src/Exceptions/CypherInjectionException.php`

**1. What it represents:**
Exception thrown when potential Cypher injection is detected. Security exception that prevents malicious Cypher query construction.

**2. Inherits from:** `\InvalidArgumentException`

**3. Custom properties/methods:** None (empty class body)

**4. Where thrown:**
| File | Line | Context |
|------|------|---------|
| `src/GraphStore/CypherSanitizer.php` | 93 | Empty identifier validation |
| `src/GraphStore/CypherSanitizer.php` | 100 | Identifier exceeds max length (128 chars) |
| `src/GraphStore/CypherSanitizer.php` | 107 | Identifier fails pattern match (alphanumeric + underscores only) |
| `src/GraphStore/CypherSanitizer.php` | 115 | Identifier is a reserved Cypher keyword |

**5. Where caught:**
- **Not explicitly caught** - caught by generic `catch (\InvalidArgumentException $e)` handlers
- `DataIngestionService.php` line 213 catches `\InvalidArgumentException` which includes this

**6. Notes/Anomalies:**
- Security-focused exception - critical for preventing Cypher injection attacks
- All throws are in `CypherSanitizer::validateIdentifier()` private method
- Provides detailed error messages indicating why validation failed
- Well-designed validation chain: empty -> length -> pattern -> reserved keywords

---

### DataConsistencyException.php
**Path:** `src/Exceptions/DataConsistencyException.php`

**1. What it represents:**
Exception thrown when data consistency cannot be maintained across dual stores (Neo4j + Qdrant). Indicates an operation partially succeeded in one store but failed in another, with optional rollback information.

**2. Inherits from:** `\RuntimeException`

**3. Custom properties/methods:**
```php
private array $context = [];

public function __construct(
    string $message,
    array $context = [],
    int $code = 0,
    ?\Throwable $previous = null
)

public function getContext(): array    // Returns: entity_id, graph_success, vector_success, operation
public function wasRolledBack(): bool  // Returns $this->context['rolled_back'] ?? false
```

**4. Where thrown:**
| File | Line | Context |
|------|------|---------|
| `src/Services/DataIngestionService.php` | 149 | Vector store failed after graph succeeded, rollback successful |
| `src/Services/DataIngestionService.php` | 176 | Graph store failed initially during ingest |
| `src/Services/DataIngestionService.php` | 357 | Vector deletion failed after graph deletion, restoration successful |
| `src/Services/DataIngestionService.php` | 384 | Graph deletion failed initially during remove |

**5. Where caught:**
| File | Line | Context |
|------|------|---------|
| `src/Services/DataIngestionService.php` | 164 | Re-throws after rollback was successful |
| `src/Services/DataIngestionService.php` | 372 | Re-throws after restoration was successful |

**6. Notes/Anomalies:**
- **Best designed exception** in the codebase - includes rich context
- Context array tracks: `entity_id`, `entity_class`, `graph_success`, `vector_success`, `rolled_back`, `operation`
- Proper catch-and-rethrow pattern to maintain exception flow after rollback
- Used for both `ingest` and `remove` operations
- `wasRolledBack()` method allows callers to determine if manual intervention is needed

---

### QueryExecutionException.php
**Path:** `src/Exceptions/QueryExecutionException.php`

**1. What it represents:**
Exception thrown when a Cypher query fails to execute properly, including syntax errors, connection issues, or other runtime errors.

**2. Inherits from:** `\RuntimeException`

**3. Custom properties/methods:** None (empty class body)

**4. Where thrown:**
| File | Line | Context |
|------|------|---------|
| `src/Services/QueryExecutor.php` | 134 | General query execution failure (not timeout) |
| `src/Services/QueryExecutor.php` | 164 | Count query execution failure |
| `src/Services/QueryExecutor.php` | 235 | EXPLAIN disabled in configuration |
| `src/Services/QueryExecutor.php` | 248 | EXPLAIN query execution failure |

**5. Where caught:**
- **Not explicitly caught** - caught by generic `catch (\Exception $e)` handlers
- `QueryExecutor.php` lines 124, 163, 247, 268, 286 all catch `\Exception`

**6. Notes/Anomalies:**
- All throws wrap original exception using `$e` as `$previous`
- Provides context-aware messages: "Query execution failed:", "Count execution failed:", "EXPLAIN failed:"
- Line 235 is different - throws directly for configuration error (no wrapping)

---

### QueryGenerationException.php
**Path:** `src/Exceptions/QueryGenerationException.php`

**1. What it represents:**
Exception thrown when query generation fails after retries or encounters unrecoverable errors during the LLM-based query generation process.

**2. Inherits from:** `\RuntimeException`

**3. Custom properties/methods:** None (empty class body)

**4. Where thrown:**
| File | Line | Context |
|------|------|---------|
| `src/Services/QueryGenerator.php` | 147 | Rate limit exceeded for LLM API |
| `src/Services/QueryGenerator.php` | 202 | All retry attempts exhausted, includes retry count and last error |

**5. Where caught:**
- **Not explicitly caught** - caught by generic `catch (\Exception $e)` handlers
- `QueryGenerator.php` line 195 catches `\Exception` for retry loop, line 420 for other operations

**6. Notes/Anomalies:**
- Used in retry loop - line 147 is within retry block and gets caught by line 195's `\Exception` handler
- Line 202 throws after exhausting max retries (default 3), includes helpful message with attempt count and last error
- Rate limit checking via `$this->rateLimiter->waitAndAttempt(10)` with 10 second wait

---

### QueryTimeoutException.php
**Path:** `src/Exceptions/QueryTimeoutException.php`

**1. What it represents:**
Exception thrown when a query exceeds the maximum allowed execution time. Helps prevent long-running queries from blocking the system.

**2. Inherits from:** `\RuntimeException`

**3. Custom properties/methods:** None (empty class body)

**4. Where thrown:**
| File | Line | Context |
|------|------|---------|
| `src/Services/QueryExecutor.php` | 128 | Query execution time >= timeout (after catching generic exception) |

**5. Where caught:**
- **Not explicitly caught** - caught by generic `catch (\Exception $e)` handlers

**6. Notes/Anomalies:**
- Thrown inside a `catch (\Exception $e)` block that checks if timeout was exceeded
- Detection logic: `$executionTime >= ($timeout * 1000)` where timeout is in seconds, execution time in milliseconds
- More specific than `QueryExecutionException` - takes priority when timeout detected
- Message includes the timeout value: `"Query exceeded timeout of {$timeout} seconds"`

---

### QueryValidationException.php
**Path:** `src/Exceptions/QueryValidationException.php`

**1. What it represents:**
Exception thrown when a Cypher query fails validation due to syntax errors, invalid references, or other structural issues.

**2. Inherits from:** `\InvalidArgumentException`

**3. Custom properties/methods:** None (empty class body)

**4. Where thrown:**
**NEVER THROWN** - No `throw new QueryValidationException` statements found in codebase.

**5. Where caught:**
**NEVER CAUGHT** - No `catch (QueryValidationException` statements found in codebase.

**6. Notes/Anomalies:**
- **DEAD CODE** - Exception is defined but never used
- Similar purpose to `CypherInjectionException` and the validation logic in `QueryGenerator::validate()`
- `QueryGenerator::validate()` returns `['valid' => false, 'errors' => [...]]` instead of throwing
- Consider using this exception in `QueryGenerator::validate()` or removing it

---

### ReadOnlyViolationException.php
**Path:** `src/Exceptions/ReadOnlyViolationException.php`

**1. What it represents:**
Exception thrown when a query attempts to perform write operations (CREATE, DELETE, MERGE, SET) while in read-only mode.

**2. Inherits from:** `\RuntimeException`

**3. Custom properties/methods:** None (empty class body)

**4. Where thrown:**
| File | Line | Context |
|------|------|---------|
| `src/Services/QueryExecutor.php` | 75 | Query contains write operations but `$readOnly` is true |

**5. Where caught:**
- **Not explicitly caught** - caught by generic `catch (\Exception $e)` handlers

**6. Notes/Anomalies:**
- Thrown before query execution (fail-fast validation)
- Check uses `$this->containsWriteOperations($cypherQuery)` helper method
- Message: `'Write operations not allowed in read-only mode'`
- Important security control to prevent unintended data modifications

---

### UnsafeQueryException.php
**Path:** `src/Exceptions/UnsafeQueryException.php`

**1. What it represents:**
Exception thrown when a query contains dangerous operations like DELETE, DROP, or other operations that could modify or destroy data.

**2. Inherits from:** `\RuntimeException`

**3. Custom properties/methods:** None (empty class body)

**4. Where thrown:**
**NEVER THROWN** - No `throw new UnsafeQueryException` statements found in codebase.

**5. Where caught:**
**NEVER CAUGHT** - No `catch (UnsafeQueryException` statements found in codebase.

**6. Notes/Anomalies:**
- **DEAD CODE** - Exception is defined but never used
- Purpose overlaps with `ReadOnlyViolationException` for write operations
- May have been intended for more severe destructive operations (DROP, DELETE ALL)
- `QueryGenerator::validate()` checks for dangerous patterns but doesn't throw this exception
- Consider using this in validation logic or removing it

---

## Summary

### Dead Code (Never Thrown or Caught)

| Exception | Recommendation |
|-----------|----------------|
| `QueryValidationException` | Either use in `QueryGenerator::validate()` instead of returning errors array, or remove |
| `UnsafeQueryException` | Either use for destructive operation detection in validation, or remove |

### Catch Pattern Analysis

| Pattern | Count | Notes |
|---------|-------|-------|
| Explicit catch by exception type | 2 | Only `DataConsistencyException` is explicitly caught |
| Caught via parent class | 7 | Most exceptions caught by `\Exception` or `\Throwable` handlers |
| Never caught | 2 | Dead code exceptions |

### Design Recommendations

1. **QueryValidationException**: Consider throwing this instead of returning validation error arrays in `QueryGenerator::validate()`. This would make error handling more consistent with exception patterns.

2. **UnsafeQueryException**: Either implement detection of truly dangerous queries (like `DETACH DELETE`, `DROP`, etc.) that should be blocked even for write-enabled queries, or remove this exception.

3. **DataConsistencyException**: This is an excellent example of a well-designed exception with context. Consider applying similar patterns to other exceptions that could benefit from structured context (e.g., `QueryExecutionException` could include the failed query).

4. **Explicit Catching**: Consider adding explicit catch blocks for `ReadOnlyViolationException`, `QueryTimeoutException`, and `CircuitBreakerOpenException` in appropriate places to provide better error messages to users.

### Exception Usage Flow

```
User Query
    |
    v
QueryGenerator
    |-- QueryGenerationException (rate limit, retries exhausted)
    |
    v
QueryExecutor
    |-- ReadOnlyViolationException (write in read-only mode)
    |-- QueryTimeoutException (execution too slow)
    |-- QueryExecutionException (general failure)
    |
    v
CircuitBreaker (wraps external calls)
    |-- CircuitBreakerOpenException (service unavailable)
    |
    v
CypherSanitizer (input validation)
    |-- CypherInjectionException (malicious input detected)
    |
    v
DataIngestionService (dual-store operations)
    |-- DataConsistencyException (Neo4j/Qdrant sync failure)
```

---

## Files Reviewed

- `src/Exceptions/CircuitBreakerOpenException.php`
- `src/Exceptions/CypherInjectionException.php`
- `src/Exceptions/DataConsistencyException.php`
- `src/Exceptions/QueryExecutionException.php`
- `src/Exceptions/QueryGenerationException.php`
- `src/Exceptions/QueryTimeoutException.php`
- `src/Exceptions/QueryValidationException.php`
- `src/Exceptions/ReadOnlyViolationException.php`
- `src/Exceptions/UnsafeQueryException.php`
