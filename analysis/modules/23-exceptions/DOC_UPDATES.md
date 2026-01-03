# Module 23: EXCEPTIONS - Documentation Updates

> **Status:** COMPLETED

## Overview

This module analyzed all 9 exception classes in the `src/Exceptions/` directory.

## Existing Documentation

The following exception is already documented:

1. **DataConsistencyException** - Documented in `resources/docs/1.0/usage/data-ingestion.md:481` with a catch example

2. **QueryGenerationException** - Documented in `resources/docs/1.0/reference/facades.md:459-464` with usage example

## Recommended Documentation Updates

### 1. Exception Reference Page

Consider creating `resources/docs/1.0/reference/exceptions.md` with:

```markdown
# Exceptions Reference

## CircuitBreakerOpenException

Thrown when the circuit breaker is open and rejecting requests.

**Extends:** `\RuntimeException`

**Thrown by:**
- `CircuitBreaker::call()` when circuit is open

**Example:**
try {
    $result = $circuitBreaker->call($operation);
} catch (CircuitBreakerOpenException $e) {
    // Handle circuit open - use fallback or queue for later
}

## CypherInjectionException

Thrown when potential Cypher injection is detected in queries.

**Extends:** `\InvalidArgumentException`

**Thrown by:**
- `CypherSanitizer::sanitizeLabel()` - Invalid label characters
- `CypherSanitizer::sanitizeProperties()` - SQL/Cypher injection detected
- `CypherSanitizer::validateNoMultipleStatements()` - Multiple statements detected

**Example:**
try {
    $sanitized = $sanitizer->sanitize($query);
} catch (CypherInjectionException $e) {
    // Log security event and reject query
    Log::warning('Cypher injection attempt', ['query' => $query]);
}

## DataConsistencyException

Thrown when data consistency cannot be maintained across dual stores (Neo4j + Qdrant).

**Extends:** `\RuntimeException`

**Additional Methods:**
- `getContext(): array` - Returns context with entity_id, graph_success, vector_success, operation
- `wasRolledBack(): bool` - Returns true if rollback was successful

**Thrown by:**
- `DataIngestionService::ingestEntity()` - Store sync failures
- `DataIngestionService::updateEntity()` - Store sync failures

## QueryExecutionException

Thrown when a Cypher query fails to execute properly.

**Extends:** `\RuntimeException`

**Thrown by:**
- `QueryExecutor::execute()` - Execution failures
- `QueryExecutor::explain()` - EXPLAIN failures
- `QueryExecutor::executeRateLimited()` - Rate limit exceeded

## QueryGenerationException

Thrown when query generation fails after retries.

**Extends:** `\RuntimeException`

**Thrown by:**
- `QueryGenerator::generate()` - After max retries exceeded
- `QueryGenerator::generate()` - Rate limit exceeded

## QueryTimeoutException

Thrown when a query exceeds the maximum allowed execution time.

**Extends:** `\RuntimeException`

**Thrown by:**
- `QueryExecutor::execute()` - Query timeout exceeded

## ReadOnlyViolationException

Thrown when a query attempts write operations in read-only mode.

**Extends:** `\RuntimeException`

**Thrown by:**
- `QueryExecutor::execute()` - Write operation detected in read-only mode
```

### 2. Update QueryGeneratorTest.php

Remove unused import:

```diff
- use Condoedge\Ai\Exceptions\QueryValidationException;
```

### 3. Consider Removing Unused Exceptions

The following exceptions are defined but never thrown:

- `src/Exceptions/QueryValidationException.php`
- `src/Exceptions/UnsafeQueryException.php`

Either:
1. Remove them if not needed
2. Implement their usage in appropriate places

### 4. Add @throws Annotations

Add PHPDoc `@throws` annotations to methods that throw exceptions:

```php
/**
 * Execute a Cypher query.
 *
 * @throws QueryExecutionException When query execution fails
 * @throws QueryTimeoutException When query exceeds timeout
 * @throws ReadOnlyViolationException When write operation attempted in read-only mode
 */
public function execute(string $query, array $params = []): array
```

## Files Requiring Updates

| File | Update Required |
|------|-----------------|
| `tests/Unit/Services/QueryGeneratorTest.php` | Remove unused `QueryValidationException` import |
| `src/Exceptions/QueryValidationException.php` | Consider removing (unused) |
| `src/Exceptions/UnsafeQueryException.php` | Consider removing (unused) |
| `resources/docs/1.0/reference/` | Add exceptions.md reference page |
