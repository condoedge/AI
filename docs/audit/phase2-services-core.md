# Phase 2 Audit: AiManager (Core Service)

**Task:** 19
**File:** `src/Services/AiManager.php`
**Related:** `src/Facades/AI.php`
**Date:** 2025-12-30

---

## 1. Overview

`AiManager` is the central orchestration service for the AI package. It provides a unified API for all AI-related operations by coordinating multiple specialized services. The class follows Laravel best practices with full dependency injection, making it testable and flexible.

**Location:** `src/Services/AiManager.php`
**Lines:** 795
**Namespace:** `Condoedge\Ai\Services`

---

## 2. Constructor Dependencies

The `AiManager` receives 8 dependencies via constructor injection:

| Dependency | Interface | Purpose |
|------------|-----------|---------|
| `$ingestion` | `DataIngestionServiceInterface` | Entity ingestion to graph/vector stores |
| `$context` | `ContextRetrieverInterface` | RAG context retrieval |
| `$embedding` | `EmbeddingProviderInterface` | Vector embedding generation |
| `$llm` | `LlmProviderInterface` | Language model interactions |
| `$queryGenerator` | `QueryGeneratorInterface` | Cypher query generation from natural language |
| `$queryExecutor` | `QueryExecutorInterface` | Cypher query execution |
| `$responseGenerator` | `ResponseGeneratorInterface` | Natural language response generation |
| `$vectorStore` | `VectorStoreInterface` | Vector database operations |

**Instantiation:** Bound in `AiServiceProvider::registerAiManager()` (line 330)

```php
$this->app->singleton('ai', function ($app) {
    return new AiManager(
        $app->make(DataIngestionServiceInterface::class),
        $app->make(ContextRetrieverInterface::class),
        $app->make(EmbeddingProviderInterface::class),
        $app->make(LlmProviderInterface::class),
        $app->make(QueryGeneratorInterface::class),
        $app->make(QueryExecutorInterface::class),
        $app->make(ResponseGeneratorInterface::class),
        $app->make(VectorStoreInterface::class)
    );
});
```

---

## 3. Public Methods and Their Purposes

### 3.1 Data Ingestion Methods

| Method | Signature | Purpose | Delegates To |
|--------|-----------|---------|--------------|
| `ingest` | `(Nodeable $entity): array` | Ingest entity into graph/vector stores | `$ingestion->ingest()` |
| `ingestBatch` | `(array $entities): array` | Batch ingest multiple entities | `$ingestion->ingestBatch()` |
| `remove` | `(Nodeable $entity): bool` | Remove entity from stores | `$ingestion->remove()` |
| `sync` | `(Nodeable $entity): array` | Update or create entity | `$ingestion->sync()` |

### 3.2 Context Retrieval (RAG) Methods

| Method | Signature | Purpose | Delegates To |
|--------|-----------|---------|--------------|
| `retrieveContext` | `(string $question, array $options = []): array` | Get RAG context for question | `$context->retrieveContext()` |
| `searchSimilar` | `(string $question, array $options = []): array` | Vector similarity search | `$context->searchSimilar()` |
| `getSchema` | `(): array` | Get graph database schema | `$context->getGraphSchema()` |
| `getExampleEntities` | `(array $labels, int $limit = 3): array` | Get example entities by label | `$context->getExampleEntities()` |
| `storeQuery` | `(string $question, string $cypherQuery, array $metadata = [], string $collection = 'questions'): array` | Store question-query pair for RAG | Custom implementation |

### 3.3 Embedding Methods

| Method | Signature | Purpose | Delegates To |
|--------|-----------|---------|--------------|
| `embed` | `(string $text): array` | Generate embedding for text | `$embedding->embed()` |
| `embedBatch` | `(array $texts): array` | Batch embed multiple texts | `$embedding->embedBatch()` |
| `getEmbeddingDimensions` | `(): int` | Get vector dimensions | `$embedding->getDimensions()` |
| `getEmbeddingModel` | `(): string` | Get embedding model name | `$embedding->getModel()` |

### 3.4 LLM Methods

| Method | Signature | Purpose | Delegates To |
|--------|-----------|---------|--------------|
| `chat` | `(string\|array $input, array $options = []): string` | Chat completion | `$llm->chat()` |
| `chatJson` | `(string\|array $input, array $options = []): object\|array` | Chat with JSON response | `$llm->chatJson()` |
| `complete` | `(string $prompt, ?string $systemPrompt = null, array $options = []): string` | Simple completion | `$llm->complete()` |
| `stream` | `(array $messages, callable $callback, array $options = []): void` | Streaming chat | `$llm->stream()` |
| `getLlmModel` | `(): string` | Get LLM model name | `$llm->getModel()` |
| `getLlmProvider` | `(): string` | Get LLM provider name | `$llm->getProvider()` |
| `getLlmMaxTokens` | `(): int` | Get max context tokens | `$llm->getMaxTokens()` |
| `countTokens` | `(string $text): int` | Estimate token count | `$llm->countTokens()` |

### 3.5 Query Generation Methods

| Method | Signature | Purpose | Delegates To |
|--------|-----------|---------|--------------|
| `generateQuery` | `(string $question, array $context = [], array $options = []): array` | Generate Cypher from question | `$queryGenerator->generate()` + auto-stores query |
| `validateQuery` | `(string $cypherQuery, array $options = []): array` | Validate Cypher query | `$queryGenerator->validate()` |
| `sanitizeQuery` | `(string $cypherQuery): string` | Remove dangerous operations | `$queryGenerator->sanitize()` |
| `getQueryTemplates` | `(): array` | Get available templates | `$queryGenerator->getTemplates()` |
| `detectQueryTemplate` | `(string $question): ?string` | Detect matching template | `$queryGenerator->detectTemplate()` |
| `askQuestion` | `(string $question, array $options = []): array` | Question -> Context -> Query | Orchestrates multiple services |

### 3.6 Query Execution Methods

| Method | Signature | Purpose | Delegates To |
|--------|-----------|---------|--------------|
| `executeQuery` | `(string $cypherQuery, array $parameters = [], array $options = []): array` | Execute Cypher query | `$queryExecutor->execute()` |
| `executeCount` | `(string $cypherQuery, array $parameters = [], array $options = []): int` | Execute and return count | `$queryExecutor->executeCount()` |
| `executePaginated` | `(string $cypherQuery, int $page = 1, int $perPage = 20, array $parameters = [], array $options = []): array` | Paginated execution | `$queryExecutor->executePaginated()` |
| `explainQuery` | `(string $cypherQuery, array $parameters = []): array` | Get execution plan | `$queryExecutor->explain()` |
| `testQuery` | `(string $cypherQuery): bool` | Dry run validation | `$queryExecutor->test()` |
| `ask` | `(string $question, array $options = []): array` | Full pipeline: Question -> Query -> Execute | Orchestrates multiple services |

### 3.7 Response Generation Methods

| Method | Signature | Purpose | Delegates To |
|--------|-----------|---------|--------------|
| `generateResponse` | `(string $originalQuestion, array $queryResult, string $cypherQuery, array $options = []): array` | Generate NL response | `$responseGenerator->generate()` |
| `extractInsights` | `(array $queryResult): array` | Extract data insights | `$responseGenerator->extractInsights()` |
| `suggestVisualizations` | `(array $queryResult, string $cypherQuery): array` | Suggest chart types | `$responseGenerator->suggestVisualizations()` |
| `answerQuestion` | `(string $question, array $options = []): array` | Complete pipeline with NL answer | Orchestrates all services |

### 3.8 Protected Methods (File Context)

| Method | Signature | Purpose |
|--------|-----------|---------|
| `retrieveFileContext` | `(string $question, mixed $user): array` | Get file context for question |
| `enrichResponseWithFiles` | `(array $response, array $fileContext, array $options): array` | Add file references to response |

---

## 4. Facade Method Annotations vs Implementations

### @method Annotations in AI.php (37 total)

| Annotation | Exists in AiManager | Match |
|------------|---------------------|-------|
| `ingest(Nodeable $entity)` | Yes | OK |
| `ingestBatch(array $entities)` | Yes | OK |
| `remove(Nodeable $entity)` | Yes | OK |
| `sync(Nodeable $entity)` | Yes | OK |
| `retrieveContext(string $question, array $options = [])` | Yes | OK |
| `searchSimilar(string $question, array $options = [])` | Yes | OK |
| `getSchema()` | Yes | OK |
| `getExampleEntities(array $labels, int $limit = 3)` | Yes | OK |
| `storeQuery(string $question, string $cypherQuery, array $metadata = [], string $collection = 'questions')` | Yes | OK |
| `embed(string $text)` | Yes | OK |
| `embedBatch(array $texts)` | Yes | OK |
| `getEmbeddingDimensions()` | Yes | OK |
| `getEmbeddingModel()` | Yes | OK |
| `chat(string\|array $input, array $options = [])` | Yes | OK |
| `chatJson(string\|array $input, array $options = [])` | Yes | OK |
| `complete(string $prompt, string\|null $systemPrompt = null, array $options = [])` | Yes | OK |
| `stream(array $messages, callable $callback, array $options = [])` | Yes | OK |
| `getLlmModel()` | Yes | OK |
| `getLlmProvider()` | Yes | OK |
| `getLlmMaxTokens()` | Yes | OK |
| `countTokens(string $text)` | Yes | OK |
| `generateQuery(string $question, array $context = [], array $options = [])` | Yes | OK |
| `validateQuery(string $cypherQuery, array $options = [])` | Yes | OK |
| `sanitizeQuery(string $cypherQuery)` | Yes | OK |
| `getQueryTemplates()` | Yes | OK |
| `detectQueryTemplate(string $question)` | Yes | OK |
| `askQuestion(string $question, array $options = [])` | Yes | OK |
| `executeQuery(string $cypherQuery, array $parameters = [], array $options = [])` | Yes | OK |
| `executeCount(string $cypherQuery, array $parameters = [], array $options = [])` | Yes | OK |
| `executePaginated(string $cypherQuery, int $page = 1, int $perPage = 20, array $parameters = [], array $options = [])` | Yes | OK |
| `explainQuery(string $cypherQuery, array $parameters = [])` | Yes | OK |
| `testQuery(string $cypherQuery)` | Yes | OK |
| `ask(string $question, array $options = [])` | Yes | OK |
| `generateResponse(string $originalQuestion, array $queryResult, string $cypherQuery, array $options = [])` | Yes | OK |
| `extractInsights(array $queryResult)` | Yes | OK |
| `suggestVisualizations(array $queryResult, string $cypherQuery)` | Yes | OK |
| `answerQuestion(string $question, array $options = [])` | Yes | OK |

**Result:** All 37 facade annotations have corresponding implementations. No discrepancies.

---

## 5. Method Usage Analysis

### 5.1 Actively Used Methods (Found in src/ or tests/)

| Method | Usage Location(s) |
|--------|-------------------|
| `ingest` | `Jobs/IngestEntityJob`, `Domain/Traits/HasNodeableConfig`, `Models/Plugins/FileProcessingPlugin`, Tests |
| `ingestBatch` | Documentation only (no production code usage) |
| `remove` | `Jobs/RemoveEntityJob`, `Domain/Traits/HasNodeableConfig`, `Models/Plugins/FileProcessingPlugin` |
| `sync` | `Jobs/SyncEntityJob`, `Domain/Traits/HasNodeableConfig`, `Models/Plugins/FileProcessingPlugin` |
| `retrieveContext` | Tests |
| `searchSimilar` | Documentation only |
| `getSchema` | `Services/Chat/AiChatService` |
| `generateQuery` | Tests |
| `validateQuery` | Tests |
| `executeQuery` | Tests |
| `answerQuestion` | `Services/Chat/AiChatService` (main chat flow), Tests |
| `generateResponse` | Tests |
| `extractInsights` | Tests |
| `suggestVisualizations` | Tests |

### 5.2 Methods Never Called via AI:: Facade (Potential Dead Code)

| Method | Status | Notes |
|--------|--------|-------|
| `getQueryTemplates()` | **NOT USED** | No calls found in src/ or tests/ |
| `detectQueryTemplate()` | **NOT USED** | No calls found in src/ or tests/ |
| `testQuery()` | **NOT USED** | No calls found in src/ or tests/ |
| `getExampleEntities()` | Internal only | Called by ContextRetriever internally |
| `getEmbeddingDimensions()` | Internal only | Used in storeQuery() |
| `getEmbeddingModel()` | **NOT USED** | No external calls found |
| `getLlmModel()` | **NOT USED** | No external calls found |
| `getLlmProvider()` | **NOT USED** | No external calls found |
| `getLlmMaxTokens()` | **NOT USED** | No external calls found |
| `countTokens()` | **NOT USED** | No external calls found |
| `stream()` | **NOT USED** | No external calls found |
| `executeCount()` | Internal only | Used by QueryExecutor::executePaginated() |
| `executePaginated()` | **NOT USED** | No external calls found |
| `explainQuery()` | **NOT USED** | No external calls found |
| `ask()` | **NOT USED** | Full pipeline exists but not called |
| `askQuestion()` | **NOT USED** | Superseded by answerQuestion() |
| `storeQuery()` | Internal only | Auto-called by generateQuery() |
| `embed()` | Documentation only | No production usage |
| `embedBatch()` | Documentation only | No production usage |
| `sanitizeQuery()` | Documentation only | No production usage |
| `searchSimilar()` | Documentation only | No production usage |
| `chat()` | Documentation only | No direct facade usage in production |
| `chatJson()` | Documentation only | No direct facade usage in production |
| `complete()` | Documentation only | No direct facade usage in production |
| `ingestBatch()` | Documentation only | No production usage |

---

## 6. Service Orchestration Patterns

### 6.1 Simple Delegation Pattern
Most methods simply delegate to their respective service:

```php
public function ingest(Nodeable $entity): array
{
    return $this->ingestion->ingest($entity);
}
```

### 6.2 Context Auto-Retrieval Pattern
Query generation auto-retrieves context if not provided:

```php
public function generateQuery(string $question, array $context = [], array $options = []): array
{
    if (empty($context)) {
        $context = $this->retrieveContext($question);
    }

    $generation = $this->queryGenerator->generate($question, $context, $options);

    // Auto-store successful queries for future RAG
    if (isset($generation['cypher']) && $generation['cypher']) {
        $this->storeQuery($question, $generation['cypher'], [...]);
    }

    return $generation;
}
```

### 6.3 Full Pipeline Pattern
The `answerQuestion()` method orchestrates the complete flow:

```
Question -> retrieveContext() -> retrieveFileContext()
         -> generateQuery() -> executeQuery()
         -> generateResponse() -> enrichResponseWithFiles()
         -> Return complete answer
```

### 6.4 Error Handling Pattern
The `answerQuestion()` method includes comprehensive error handling:

```php
try {
    // Full pipeline...
} catch (\Throwable $e) {
    \Log::error('Error answering question: ' . $e->getMessage());
    $errorResponse = $this->responseGenerator->generateErrorResponse(...);
    return [...error response...];
}
```

---

## 7. Context Handling Patterns

### 7.1 Conversation Context
Passed through options and merged with RAG context:

```php
if (!empty($options['conversation_context'])) {
    $context['conversation_context'] = $options['conversation_context'];
}
```

### 7.2 File Context
Conditionally retrieved based on config:

```php
if (config('ai.file_context.enabled', true)) {
    $fileContext = $this->retrieveFileContext($question, $options['user'] ?? null);
    if (!empty($fileContext)) {
        $context['file_context'] = $fileContext;
    }
}
```

### 7.3 Context Sources Merged
The full context includes:
- `similar_queries` - From vector store RAG
- `graph_schema` - From graph database
- `relevant_entities` - From graph database
- `conversation_context` - From chat history
- `file_context` - From file search system

---

## 8. Notes and Anomalies

### 8.1 Potential Dead Code
The following methods are documented and annotated but never used in production:
- `getQueryTemplates()`, `detectQueryTemplate()`, `testQuery()` - Template system not integrated
- `stream()` - Streaming not implemented in chat UI
- `ask()`, `askQuestion()` - Superseded by `answerQuestion()`
- `executePaginated()`, `explainQuery()` - Advanced execution features not used
- LLM info methods (`getLlmModel()`, `getLlmProvider()`, etc.) - Debug utilities not called

### 8.2 Redundant Pipeline Methods
Three similar methods exist for the question-to-answer pipeline:
1. `askQuestion()` - Returns context + query (no execution)
2. `ask()` - Returns context + query + data (no NL response)
3. `answerQuestion()` - Complete pipeline with NL response

Only `answerQuestion()` is used in production. Consider deprecating `ask()` and `askQuestion()`.

### 8.3 storeQuery() Auto-Storage
The `storeQuery()` method is called automatically by `generateQuery()` for successful queries. This means:
- External calls to `AI::storeQuery()` are redundant when using `generateQuery()`
- Only needed for manually storing curated question-query pairs

### 8.4 Type Inconsistency
The facade annotation for `embed()` shows `@method static array embed(string $text)` but the method returns a vector (array of floats). Documentation could be clearer about this being a float array.

### 8.5 ChatMessageForm Uses AiChatServiceInterface
The `ChatMessageForm` imports `AiManager` but actually uses `AiChatServiceInterface::askWithHistory()`. The import appears to be unused or leftover from refactoring.

### 8.6 Protected Methods for File Context
The `retrieveFileContext()` and `enrichResponseWithFiles()` methods are protected and use `app()` to resolve dependencies. This could be refactored to use constructor injection for consistency.

---

## 9. Recommendations

### 9.1 Consider Deprecating
- `ask()` - Use `answerQuestion()` instead
- `askQuestion()` - Use `answerQuestion()` instead

### 9.2 Potentially Remove or Document as Internal
- `getQueryTemplates()` - If template system not in use
- `detectQueryTemplate()` - If template system not in use
- `testQuery()` - Useful but unused

### 9.3 Add Tests For
Methods with no test coverage via facade:
- `stream()` - Streaming functionality
- `executePaginated()` - Pagination
- `explainQuery()` - Query explanation
- LLM info methods

### 9.4 Code Quality
- Remove unused `AiManager` import from `ChatMessageForm.php`
- Consider injecting `FileContextProvider` and `ResponseFileEnricher` via constructor

---

## 10. Summary

| Category | Count |
|----------|-------|
| **Total Public Methods** | 35 |
| **Methods with Facade Annotation** | 37 |
| **Facade/Implementation Match** | 100% |
| **Actively Used in Production** | ~12 |
| **Documentation-Only Usage** | ~10 |
| **Potentially Dead Code** | ~13 |
| **Constructor Dependencies** | 8 |
| **Protected Methods** | 2 |

The `AiManager` is well-designed and follows Laravel best practices. However, a significant portion of its API surface appears to be unused in production, suggesting either:
1. Features planned but not yet integrated
2. API designed for extensibility/future use
3. Candidates for deprecation

The core functionality (ingestion, context retrieval, answer generation) is well-integrated and actively used.
