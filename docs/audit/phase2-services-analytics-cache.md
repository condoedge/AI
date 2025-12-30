# Phase 2: Analytics & Cache Services Review

## Overview

This document reviews the Analytics and Cache services, evaluating their purpose, implementation, integration status, and usage within the main pipeline.

---

## 1. QueryAnalytics Service

**File:** `src/Services/Analytics/QueryAnalytics.php`

### Purpose and Functionality

The `QueryAnalytics` service provides statistical analysis and reporting on AI query activity. It is designed to generate analytics dashboards and identify patterns in query success/failure rates.

### Methods

| Method | Purpose | Used? |
|--------|---------|-------|
| `getSuccessRate(int $days = 7): float` | Calculate success rate percentage over a time period | No |
| `getAverageExecutionTime(int $days = 7): float` | Average execution time for successful queries | No |
| `getMostFailedQuestions(int $limit = 10): array` | Identify frequently failing questions | No |
| `getTemplateUsage(int $days = 7): array` | Track which templates are used most | No |
| `getDashboardStats(): array` | Aggregate dashboard statistics | No |

### Data Tracked

All analytics are derived from the `ai_query_logs` table:
- `question`: The user's natural language question
- `status`: success, failed, timeout, rejected
- `execution_time_ms`: Query execution duration
- `template_used`: Which pattern/template was matched
- `created_at`: Timestamp for time-based filtering

### Storage Mechanism

- **Primary:** `ai_query_logs` MySQL/PostgreSQL table via `AiQueryLog` Eloquent model
- **Migration:** `database/migrations/2025_01_01_000002_create_ai_query_logs_table.php`

### Integration Status: NOT INTEGRATED

**Critical Finding:** The `QueryAnalytics` service is **completely orphaned**:

1. **No container registration** - Not registered in `AiServiceProvider.php`
2. **No usage anywhere** - No imports or instantiations in the codebase
3. **No logging implementation** - `AiQueryLog::logSuccess()` and `AiQueryLog::logFailure()` exist but are never called
4. **Tests exist** but only test the service in isolation

**Search Results:**
```
grep -r "QueryAnalytics" src/
# Only found in:
# - src/Services/Analytics/QueryAnalytics.php (self-reference)
```

### Notes/Anomalies

1. **Dead code** - The entire service and its supporting model methods are unused
2. **SQL compatibility issue** - Uses MySQL-style `CASE WHEN` in raw SQL which may not be portable
3. **Missing data source** - Since `AiQueryLog` is never populated, all analytics return 0 or empty arrays
4. **Test file exists** - `tests/Unit/Services/Analytics/QueryAnalyticsTest.php` tests the service but masks the integration issue

---

## 2. QueryResultCache Service

**File:** `src/Services/Cache/QueryResultCache.php`

### Purpose and Functionality

The `QueryResultCache` service provides caching for:
1. **Context results** - Cached retrieval context for questions
2. **Query results** - Cached Cypher queries with metadata

This is designed to avoid redundant LLM calls and context retrieval for repeated or similar questions.

### Methods

| Method | Purpose | Used? |
|--------|---------|-------|
| `cacheContext(string $question, array $context): void` | Store context for a question | No |
| `getContext(string $question): ?array` | Retrieve cached context | No |
| `cacheQuery(string $question, string $cypher, array $metadata = []): void` | Store generated query | No |
| `getQuery(string $question): ?array` | Retrieve cached query | No |
| `invalidate(string $question): void` | Clear cache for a question | No |
| `flush(): void` | Clear all caches (stub - not implemented) | No |
| `buildKey(string $question, string $type): string` | Generate cache key | Internal |
| `normalizeQuestion(string $question): string` | Normalize question for key matching | Internal |

### Data Cached

1. **Context Data:**
   - Similar queries
   - Matched templates
   - Entity configs
   - Scope information

2. **Query Data:**
   - Generated Cypher query
   - Metadata (confidence, template used)
   - Cache timestamp

### Storage Mechanism

- **Primary:** Laravel Cache facade (Redis, file, array - driver-agnostic)
- **Key Format:** `{prefix}{type}.{md5(normalized_question)}`
- **Default TTL:** 3600 seconds (1 hour)
- **Config:** `config('ai.cache.prefix')`, `config('ai.cache.ttl')` - **NOT DEFINED in config/ai.php**

### Integration Status: NOT INTEGRATED

**Critical Finding:** The `QueryResultCache` service is **completely orphaned**:

1. **No container registration** - Not registered in `AiServiceProvider.php`
2. **No usage anywhere** - Not called from `ContextRetriever`, `QueryGenerator`, or any other service
3. **Config missing** - `ai.cache.prefix` and `ai.cache.ttl` are not defined in config/ai.php
4. **Tests exist** but only test the service in isolation

**Search Results:**
```
grep -r "QueryResultCache" src/
# Only found in:
# - src/Services/Cache/QueryResultCache.php (self-reference)
```

### Notes/Anomalies

1. **Dead code** - The entire service is unused
2. **Missing config** - References `ai.cache.prefix` and `ai.cache.ttl` which don't exist in config
3. **Incomplete flush()** - The `flush()` method is a stub with a comment, not implemented
4. **Question normalization** - Good implementation for cache key matching, but never utilized
5. **Test file exists** - `tests/Unit/Services/Cache/QueryResultCacheTest.php` tests the service

---

## Summary

### Integration Status Table

| Service | Registered | Called | Config | Tests | Status |
|---------|------------|--------|--------|-------|--------|
| QueryAnalytics | No | No | N/A | Yes | DEAD CODE |
| QueryResultCache | No | No | Missing | Yes | DEAD CODE |

### Upstream Dependencies

| Service | Depends On | Would Be Called From |
|---------|------------|---------------------|
| QueryAnalytics | AiQueryLog model | Dashboard/Admin UI, Monitoring |
| QueryResultCache | Laravel Cache | ContextRetriever, QueryGenerator |

### Critical Issues

1. **AiQueryLog never populated** - The `logSuccess()` and `logFailure()` static methods exist but are never called anywhere in the pipeline. This means:
   - No query history is being recorded
   - QueryAnalytics has no data to analyze
   - QueryLearner (which reads from AiQueryLog) also has no data

2. **QueryResultCache never used** - Context retrieval and query generation are performed on every request without caching, leading to:
   - Repeated LLM API calls for identical questions
   - Repeated vector searches
   - Higher costs and latency

3. **Config not defined** - `ai.cache.*` keys referenced but not present in config/ai.php

### Recommendations

1. **Integrate AiQueryLog logging:**
   - Add logging in `AiChatService::chat()` or `QueryExecutor::execute()`
   - Log success/failure with execution metrics
   - This enables QueryAnalytics AND QueryLearner

2. **Integrate QueryResultCache:**
   - Register as singleton in AiServiceProvider
   - Inject into ContextRetriever and QueryGenerator
   - Add cache check before context retrieval
   - Add cache check before query generation

3. **Add missing config:**
   ```php
   // config/ai.php
   'cache' => [
       'enabled' => env('AI_CACHE_ENABLED', true),
       'prefix' => env('AI_CACHE_PREFIX', 'ai.query.'),
       'ttl' => env('AI_CACHE_TTL', 3600),
   ],
   ```

4. **Implement flush() method** in QueryResultCache using cache tags or manual key tracking

---

## Files Reviewed

- `src/Services/Analytics/QueryAnalytics.php`
- `src/Services/Cache/QueryResultCache.php`
- `src/Models/AiQueryLog.php`
- `src/AiServiceProvider.php`
- `database/migrations/2025_01_01_000002_create_ai_query_logs_table.php`
- `config/ai.php`
- `tests/Unit/Services/Analytics/QueryAnalyticsTest.php`
- `tests/Unit/Services/Cache/QueryResultCacheTest.php`
