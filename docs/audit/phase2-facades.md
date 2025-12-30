# Phase 2: Facades Audit

## Overview

This document audits the Laravel Facades in `src/Facades/`. Facades provide static proxy access to services registered in the Laravel service container.

---

## Facade Summary

| Facade | Accessor Key | Underlying Service | Usage Count |
|--------|--------------|-------------------|-------------|
| AI | `ai` | `AiManager` | 50+ (active) |
| FileSearch | `file-search` | `FileSearchService` | 0 (unused) |

---

## AI Facade

**File:** `C:\Users\jkend\Documents\Projects\kompo\ai\src\Facades\AI.php`

### Service Resolution

```php
protected static function getFacadeAccessor(): string
{
    return 'ai';
}
```

### Service Provider Registration

**Location:** `AiServiceProvider.php` (lines 329-343)

```php
// Register AI Manager as singleton
$this->app->singleton('ai', function ($app) {
    return new AiManager(
        ingestion: $app->make(DataIngestionServiceInterface::class),
        context: $app->make(ContextRetrieverInterface::class),
        embedding: $app->make(EmbeddingProviderInterface::class),
        llm: $app->make(LlmProviderInterface::class),
        queryGenerator: $app->make(QueryGeneratorInterface::class),
        queryExecutor: $app->make(QueryExecutorInterface::class),
        responseGenerator: $app->make(ResponseGeneratorInterface::class),
        vectorStore: $app->make(VectorStoreInterface::class)
    );
});

// Alias for dependency injection
$this->app->alias('ai', AiManager::class);
```

### Exposed Methods (via AiManager)

**Data Ingestion:**
- `ingest(Nodeable $entity): array`
- `ingestBatch(array $entities): array`
- `remove(Nodeable $entity): bool`
- `sync(Nodeable $entity): array`

**Context Retrieval (RAG):**
- `retrieveContext(string $question, array $options = []): array`
- `searchSimilar(string $question, array $options = []): array`
- `getSchema(): array`
- `getExampleEntities(array $labels, int $limit = 3): array`
- `storeQuery(string $question, string $cypherQuery, array $metadata = [], string $collection = 'questions'): array`

**Embeddings:**
- `embed(string $text): array`
- `embedBatch(array $texts): array`
- `getEmbeddingDimensions(): int`
- `getEmbeddingModel(): string`

**LLM Chat:**
- `chat(string|array $input, array $options = []): string`
- `chatJson(string|array $input, array $options = []): object|array`
- `complete(string $prompt, ?string $systemPrompt = null, array $options = []): string`
- `stream(array $messages, callable $callback, array $options = []): void`
- `getLlmModel(): string`
- `getLlmProvider(): string`
- `getLlmMaxTokens(): int`
- `countTokens(string $text): int`

**Query Generation:**
- `generateQuery(string $question, array $context = [], array $options = []): array`
- `validateQuery(string $cypherQuery, array $options = []): array`
- `sanitizeQuery(string $cypherQuery): string`
- `getQueryTemplates(): array`
- `detectQueryTemplate(string $question): ?string`
- `askQuestion(string $question, array $options = []): array`

**Query Execution:**
- `executeQuery(string $cypherQuery, array $parameters = [], array $options = []): array`
- `executeCount(string $cypherQuery, array $parameters = [], array $options = []): int`
- `executePaginated(string $cypherQuery, int $page = 1, int $perPage = 20, ...): array`
- `explainQuery(string $cypherQuery, array $parameters = []): array`
- `testQuery(string $cypherQuery): bool`
- `ask(string $question, array $options = []): array`

**Response Generation:**
- `generateResponse(string $originalQuestion, array $queryResult, string $cypherQuery, array $options = []): array`
- `extractInsights(array $queryResult): array`
- `suggestVisualizations(array $queryResult, string $cypherQuery): array`
- `answerQuestion(string $question, array $options = []): array`

### Usage Locations

**Production Code (src/):**

| File | Line(s) | Method(s) Used |
|------|---------|----------------|
| `Jobs/SyncEntityJob.php` | 66 | `AI::sync()` |
| `Jobs/IngestEntityJob.php` | 66 | `AI::ingest()` |
| `Jobs/RemoveEntityJob.php` | 66 | `AI::remove()` |
| `Domain/Traits/HasNodeableConfig.php` | 51, 65, 76 | `AI::ingest()`, `AI::sync()`, `AI::remove()` |
| `Services/Chat/AiChatService.php` | 85, 134, 216, 324 | `AI::answerQuestion()`, `AI::getSchema()` |
| `Models/Plugins/FileProcessingPlugin.php` | 119, 121, 133 | `AI::ingest()`, `AI::sync()`, `AI::remove()` |
| `Observers/RelatedModelSyncObserver.php` | 156 | `AI::syncRelationships()` |

**Test Code (tests/):**

| File | Line(s) | Method(s) Used |
|------|---------|----------------|
| `Feature/AiSystemFeatureTest.php` | 102, 108, 119, 157, 182, 211, 234, 255, 281, 305, 336, 359, 378, 380, 387, 403, 433, 471, 493, 513 | Multiple methods |

### Notes

1. **Well-documented:** Comprehensive PHPDoc with usage examples, testing guidance, and architecture notes
2. **Heavily used:** Primary interface for AI functionality throughout the application
3. **Testing support:** Properly supports Laravel's facade mocking (`AI::shouldReceive()`)
4. **Method annotations:** All 37+ methods documented with `@method` annotations

---

## FileSearch Facade

**File:** `C:\Users\jkend\Documents\Projects\kompo\ai\src\Facades\FileSearch.php`

### Service Resolution

```php
protected static function getFacadeAccessor(): string
{
    return 'file-search';
}
```

### Service Provider Registration

**Location:** `AiServiceProvider.php` (lines 314-323)

```php
// Register File Search Service
$this->app->singleton('file-search', function ($app) {
    return new FileSearchService(
        chunkStore: $app->make(ChunkStoreInterface::class),
        graphStore: $app->make(GraphStoreInterface::class)
    );
});

// Alias for dependency injection
$this->app->alias('file-search', FileSearchService::class);
```

### Exposed Methods (via FileSearchService)

**Content Search:**
- `searchByContent(string $query, array $options = []): array`

**Metadata Search:**
- `searchByMetadata(array $criteria, int $limit = 10): array`

**Hybrid Search:**
- `hybridSearch(string $contentQuery, array $metadataFilters = [], array $options = []): array`

**Relationship Traversal:**
- `getRelatedFiles(File $file, ?string $relationshipType = null, int $limit = 10): array`
- `getFilesByUser(int $userId, int $limit = 10): array`
- `getFilesByTeam(int $teamId, int $limit = 10): array`

### Usage Locations

**Production Code (src/):** NONE

**Test Code (tests/):** NONE

### Notes

1. **Well-documented:** Comprehensive PHPDoc with usage examples
2. **UNUSED:** No actual usage found in production or test code
3. **Candidate for removal or deprecation:** Consider whether this facade is needed

---

## Anomalies and Recommendations

### Critical Issues

1. **FileSearch Facade is Unused**
   - The `FileSearch` facade is defined but never used anywhere in the codebase
   - The underlying `FileSearchService` is properly registered but accessed only via DI
   - **Recommendation:** Either:
     - Remove the facade if direct DI is preferred
     - Add usage documentation and examples
     - Integrate into existing features that could benefit from file search

### Minor Issues

1. **AI::syncRelationships() referenced but not defined**
   - In `Observers/RelatedModelSyncObserver.php:156`, there's a call to `AI::syncRelationships()`
   - This method is not visible in the AiManager class or the facade annotations
   - **Status:** May be inherited from DataIngestionService or needs investigation

### Observations

1. **Consistent Pattern:** Both facades follow Laravel best practices
   - Singleton registration
   - Aliases for DI support
   - Comprehensive documentation
   - Testing support via mocking

2. **Documentation Quality:** Both facades have excellent PHPDoc blocks with:
   - Usage examples
   - Testing examples
   - Architecture notes
   - `@see` references to underlying services

3. **Method Annotations:** AI facade documents 37+ methods via `@method` annotations for IDE support

---

## Summary Statistics

| Metric | AI Facade | FileSearch Facade |
|--------|-----------|-------------------|
| Accessor Key | `ai` | `file-search` |
| Underlying Service | AiManager | FileSearchService |
| Dependencies Injected | 8 | 2 |
| Methods Exposed | 37+ | 6 |
| Production Usage | 13+ locations | 0 |
| Test Usage | 20+ calls | 0 |
| Documentation | Excellent | Excellent |
| Status | Active | **Unused** |

---

*Generated: 2024-12-30*
*Task: Phase 2 - Facades Review (Task 9)*
