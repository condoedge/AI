# Phase 2: Learning Services Review

## Overview

This document reviews the Learning services, evaluating their purpose, implementation, integration status, and usage within the main AI pipeline.

---

## 1. QueryLearner Service

**File:** `src/Services/Learning/QueryLearner.php`

### Purpose and Functionality

The `QueryLearner` service implements a query learning system that:
1. Learns from successful queries stored in `AiQueryLog`
2. Stores learned query patterns in a vector store collection (`learned_queries`)
3. Provides semantic similarity search to find previously learned queries

The concept is to build a knowledge base of successful question-to-Cypher mappings that can be reused for similar future questions.

### Methods

| Method | Purpose | Used? |
|--------|---------|-------|
| `learnFromLogs(int $minConfidence = 80, int $limit = 100): array` | Process successful queries from logs and add to learned collection | Only via CLI |
| `addLearnedQuery(string $question, string $cypherQuery, array $metadata = []): void` | Add a single query to the learned collection | Internal only |
| `findSimilarLearnedQuery(string $question, float $threshold = 0.85): ?array` | Search for similar learned queries | Not used |
| `isAlreadyLearned(string $question): bool` | Check if similar query already exists (threshold 0.95) | Internal only |

### Constructor Dependencies

```php
public function __construct(
    private VectorStoreInterface $vectorStore,
    private EmbeddingProviderInterface $embeddingProvider
) {}
```

### Data Source

**Primary:** `AiQueryLog` Eloquent model

The `learnFromLogs()` method queries:
```php
AiQueryLog::where('status', 'success')
    ->where('confidence_score', '>=', $minConfidence / 100)
    ->whereNotNull('cypher_query')
    ->orderByDesc('created_at')
    ->limit($limit)
    ->get();
```

### Storage Mechanism

- **Vector Collection:** `learned_queries` (constant `COLLECTION`)
- **Storage Format:** Vector embedding + payload containing:
  - `question`: Original natural language question
  - `cypher_query`: The successful Cypher query
  - `template`: Template used (if any)
  - `confidence`: Original confidence score
  - `learned_from_log_id`: Source log entry ID
  - `learned_at`: ISO 8601 timestamp

### Integration Status: PARTIALLY INTEGRATED (EFFECTIVELY DEAD)

**Critical Finding:** QueryLearner is registered but **completely non-functional**:

1. **Not registered in service provider** - Not bound in `AiServiceProvider.php`
2. **CLI command exists** - `LearnFromLogsCommand` (`ai:learn`) can invoke it
3. **Never called in pipeline** - `findSimilarLearnedQuery()` is not used by `QueryGenerator` or any other service
4. **No data to learn from** - `AiQueryLog` is never populated (see Task 24 findings)

**Search Results:**
```
grep -r "QueryLearner" src/
# Found in:
# - src/Services/Learning/QueryLearner.php (self)
# - src/Console/Commands/LearnFromLogsCommand.php (CLI command)
```

```
grep -r "findSimilarLearnedQuery" src/
# Only found in QueryLearner.php itself
```

```
grep -r "AiQueryLog::(logSuccess|logFailure|create)" src/
# No matches - log methods exist but are never called
```

---

## 2. LearnFromLogsCommand

**File:** `src/Console/Commands/LearnFromLogsCommand.php`

### Purpose

Artisan command to manually trigger learning from query logs.

### Signature

```bash
php artisan ai:learn [--min-confidence=80] [--limit=100]
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--min-confidence` | 80 | Minimum confidence score (percentage) to learn from |
| `--limit` | 100 | Maximum queries to process |

### Registration Status: REGISTERED

The command is registered in `AiServiceProvider::boot()`:
```php
$this->commands([
    // ...
    \Condoedge\Ai\Console\Commands\LearnFromLogsCommand::class,
]);
```

### Practical Status: NON-FUNCTIONAL

Since `AiQueryLog` is never populated, running this command will always return:
```
Processed: 0
Learned: 0
Skipped (already known): 0
```

---

## Summary

### Integration Status Table

| Component | Registered | Data Source | Called in Pipeline | Status |
|-----------|------------|-------------|-------------------|--------|
| QueryLearner | No | AiQueryLog | No | DEAD CODE |
| LearnFromLogsCommand | Yes | Via QueryLearner | N/A (CLI) | NON-FUNCTIONAL |
| `findSimilarLearnedQuery()` | N/A | learned_queries | Never | ORPHANED METHOD |

### Dependency Chain (Broken)

```
QueryGenerator
    |
    +-- (should call) --> QueryLearner::findSimilarLearnedQuery()
                              |
                              +-- (reads from) --> learned_queries collection
                                                       |
                                                       +-- (populated by) --> QueryLearner::learnFromLogs()
                                                                                   |
                                                                                   +-- (reads from) --> AiQueryLog
                                                                                                            |
                                                                                                            +-- (populated by) --> NOTHING
```

### Upstream Dependencies

| Service | Depends On | Would Be Called From |
|---------|------------|---------------------|
| QueryLearner | VectorStoreInterface, EmbeddingProviderInterface, AiQueryLog | QueryGenerator (for lookup), CLI (for learning) |

### Critical Issues

1. **AiQueryLog is never populated** (confirmed from Task 24)
   - `AiQueryLog::logSuccess()` and `AiQueryLog::logFailure()` exist but are never called
   - This means QueryLearner has zero data to learn from
   - Running `ai:learn` command is futile

2. **Learned queries are never used**
   - `findSimilarLearnedQuery()` is a public method but never called
   - `QueryGenerator` does not check for similar learned queries before generating new ones
   - The entire learning-reuse loop is incomplete

3. **Service not registered**
   - QueryLearner is not bound in the service container
   - Cannot be resolved via dependency injection (except manually in the CLI command)

4. **Orphaned API method**
   - `addLearnedQuery()` is public but only called internally
   - Could be used for manual teaching but interface is not exposed

### Notes/Anomalies

1. **Circular design flaw** - Learning depends on logs, but logs are never written, so nothing is ever learned
2. **High similarity threshold** - `isAlreadyLearned()` uses 0.95 threshold (very strict), may cause near-duplicates
3. **No pruning mechanism** - No way to remove or update learned queries
4. **No validation** - Learned Cypher queries are stored without schema validation
5. **ID generation** - Uses `md5(question)` as ID, which can cause collisions for different questions

### Recommendations

1. **Integrate AiQueryLog logging first:**
   - Add logging in `AiChatService::chat()` or `QueryExecutor::execute()`
   - Must capture: question, cypher_query, confidence_score, status
   - This enables both QueryAnalytics AND QueryLearner

2. **Register QueryLearner:**
   ```php
   // In AiServiceProvider::register()
   $this->app->singleton(\Condoedge\Ai\Services\Learning\QueryLearner::class, function ($app) {
       return new \Condoedge\Ai\Services\Learning\QueryLearner(
           $app->make(VectorStoreInterface::class),
           $app->make(EmbeddingProviderInterface::class)
       );
   });
   ```

3. **Integrate into QueryGenerator:**
   ```php
   // Before generating a new query
   $learned = $this->queryLearner->findSimilarLearnedQuery($question);
   if ($learned && $learned['score'] >= 0.90) {
       return $learned['cypher_query']; // Reuse learned query
   }
   ```

4. **Add management commands:**
   - `ai:learn:list` - View learned queries
   - `ai:learn:remove {id}` - Remove a learned query
   - `ai:learn:validate` - Check learned queries against current schema

5. **Consider alternative data sources:**
   - Allow manual teaching via admin UI
   - Import from curated examples
   - Don't solely depend on AiQueryLog

---

## Files Reviewed

- `src/Services/Learning/QueryLearner.php`
- `src/Console/Commands/LearnFromLogsCommand.php`
- `src/Models/AiQueryLog.php`
- `src/AiServiceProvider.php`
- `docs/audit/phase2-services-analytics-cache.md` (cross-reference for AiQueryLog findings)

---

## Conclusion

**The QueryLearner service is effectively dead code.** While the implementation is sound and the concept is valuable, it cannot function because:

1. Its data source (`AiQueryLog`) is never populated
2. Its output (`findSimilarLearnedQuery`) is never consumed

This represents a complete feature that was built but never integrated into the pipeline. Activating it requires fixing the upstream logging issue first, then wiring the lookup into `QueryGenerator`.
