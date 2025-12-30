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

---

# Phase 2 Audit: ContextRetriever Service

**Task:** 20
**File:** `src/Services/ContextRetriever.php`
**Interface:** `src/Contracts/ContextRetrieverInterface.php`
**Date:** 2025-12-30

---

## 1. Overview

`ContextRetriever` implements Retrieval-Augmented Generation (RAG) by combining multiple context sources to support natural language query generation. It aggregates similar past questions (from vector search), graph schema information, and example entities.

**Location:** `src/Services/ContextRetriever.php`
**Lines:** 1323
**Namespace:** `Condoedge\Ai\Services`

---

## 2. Constructor Dependencies

```php
public function __construct(
    private readonly VectorStoreInterface $vectorStore,
    private readonly GraphStoreInterface $graphStore,
    private readonly EmbeddingProviderInterface $embeddingProvider,
    ?array $entityConfigs = null,
    ?ScopeSemanticMatcher $scopeMatcher = null,
    ?SemanticContextSelector $contextSelector = null
)
```

| Dependency | Type | Purpose |
|------------|------|---------|
| `$vectorStore` | `VectorStoreInterface` | Vector database for similarity search |
| `$graphStore` | `GraphStoreInterface` | Graph database for schema/entity queries |
| `$embeddingProvider` | `EmbeddingProviderInterface` | Text-to-vector embedding service |
| `$entityConfigs` | `?array` | Optional entity configurations (defaults to config/entities.php) |
| `$scopeMatcher` | `?ScopeSemanticMatcher` | Optional semantic matcher for scope detection |
| `$contextSelector` | `?SemanticContextSelector` | Optional semantic context selector for intelligent filtering |

**Instantiation:** `AiServiceProvider` line 207-216

---

## 3. Public Methods

### 3.1 Core Interface Methods (from `ContextRetrieverInterface`)

| Method | Signature | Purpose |
|--------|-----------|---------|
| `retrieveContext` | `(string $question, array $options = []): array` | Main RAG context retrieval |
| `searchSimilar` | `(string $question, string $collection = 'questions', int $limit = 5): array` | Vector similarity search |
| `getGraphSchema` | `(): array` | Get graph database schema |
| `getExampleEntities` | `(string $label, int $limit = 3): array` | Get sample entities by label |

### 3.2 Extended Methods (not in interface)

| Method | Signature | Purpose |
|--------|-----------|---------|
| `setScopeMatcher` | `(ScopeSemanticMatcher $matcher): self` | Set semantic matcher |
| `setContextSelector` | `(SemanticContextSelector $selector): self` | Set context selector |
| `getEntityMetadata` | `(string $question): array` | Detect entities/scopes in question |
| `getAllEntityMetadata` | `(): array` | Get all entity metadata |
| `getMinimalContext` | `(string $question, array $options = []): array` | Token-efficient minimal context |
| `getContextWithStats` | `(string $question, array $options = []): array` | Context with token estimates |
| `getContextStats` | `(array $context): array` | Calculate context statistics |
| `getContextWithBudget` | `(string $question, int $maxTokens, array $options = []): array` | Budget-aware context |
| `getContextConfidence` | `(string $question, array $options = []): array` | Get confidence scores |
| `getRelationshipWeight` | `(string $relationshipType): float` | Get relationship importance |
| `filterRelationshipsByImportance` | `(array $relationships, float $threshold = 0.5): array` | Filter by weight |

---

## 4. Data Flow

### 4.1 Primary Flow: `retrieveContext()`

```
User Question
    ↓
[1] Semantic Context Selection (if enabled)
    → SemanticContextSelector::selectRelevantContext()
    → Returns: entities, relationships, scopes with relevance scores
    ↓
[2] Vector Similarity Search
    → embeddingProvider->embed(question)
    → vectorStore->search(collection, embedding, limit)
    → Returns: similar_queries with scores
    ↓
[3] Graph Schema Retrieval (if includeSchema=true)
    → graphStore->getSchema()
    → Filter by semantic relevance (if available)
    → Returns: labels, relationships, properties
    ↓
[4] Example Entity Retrieval (if includeExamples=true)
    → For each relevant label: graphStore->query(MATCH (n:Label)...)
    → Returns: relevant_entities grouped by label
    ↓
[5] Entity Metadata Detection
    → Semantic matching OR string-based detection
    → Returns: detected_entities, entity_metadata, detected_scopes
    ↓
Combined Context Array
```

### 4.2 Context Output Structure

```php
[
    'similar_queries' => [
        ['question' => '...', 'query' => '...', 'score' => 0.89, 'metadata' => [...]],
    ],
    'graph_schema' => [
        'labels' => ['Team', 'Person'],
        'relationships' => ['MEMBER_OF'],
        'properties' => ['Team' => ['id', 'name'], 'Person' => ['id', 'email']],
        'propertyKeys' => ['id', 'name', 'email'],
    ],
    'relevant_entities' => [
        'Team' => [['id' => 1, 'name' => 'Alpha']],
    ],
    'entity_metadata' => [
        'detected_entities' => ['App\\Models\\Person'],
        'entity_metadata' => ['App\\Models\\Person' => [...]],
        'detected_scopes' => ['volunteers' => ['entity' => 'Person', ...]],
    ],
    'errors' => [],
    'selection_info' => ['method' => 'semantic', 'entities_selected' => 3],
]
```

---

## 5. Validation and Error Handling

### 5.1 Input Validation
- Empty question check: `InvalidArgumentException` if empty
- Label validation: `isValidLabel()` prevents Cypher injection (alphanumeric + underscore only)

### 5.2 Graceful Degradation
Each retrieval step is wrapped in try-catch:
- Vector search failure → logs error, returns empty `similar_queries`
- Schema retrieval failure → logs error, returns empty `graph_schema`
- Example entity failure → logs error per label, continues with others
- Semantic selection failure → falls back to string-based detection

### 5.3 Error Collection
Non-fatal errors are collected in `$context['errors']` array for caller inspection.

---

## 6. Security Analysis

### 6.1 Cypher Injection Prevention
```php
private function isValidLabel(string $label): bool
{
    return preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $label) === 1;
}
```
Labels are validated before use in Cypher queries. Backtick quoting is used: `MATCH (n:\`{$label}\`)`.

### 6.2 Parameterized Queries
The `getExampleEntities()` method uses parameterized queries: `LIMIT $limit` instead of string interpolation.

### 6.3 Score Threshold
Similarity search supports `scoreThreshold` to filter low-quality matches.

---

## 7. Notes and Anomalies

### 7.1 Large File Size (1323 lines)
The file contains extensive documentation and multiple context retrieval strategies. Consider splitting:
- Core retrieval methods
- Semantic selection methods
- Statistics/budget methods
- Token estimation utilities

### 7.2 Unused Public Methods
Methods not found in production usage:
- `getMinimalContext()` - Token-efficient mode not used
- `getContextWithStats()` - Statistics feature not used
- `getContextWithBudget()` - Budget-aware mode not used
- `getContextConfidence()` - Confidence scoring not used
- `getRelationshipWeight()` / `filterRelationshipsByImportance()` - Not used

### 7.3 Dual Detection Strategy
The `getEntityMetadata()` method has both:
- Semantic matching (via `ScopeSemanticMatcher`)
- String-based fallback (case-insensitive string search)

This creates redundancy but provides graceful degradation.

### 7.4 Token Estimation Accuracy
The `estimateTokens()` method uses a rough 4 chars/token approximation:
```php
$baseTokens = (int) ceil(strlen($text) / 4);
```
This may be inaccurate for non-English text or technical content.

---

# Phase 2 Audit: QueryExecutor Service

**Task:** 20
**File:** `src/Services/QueryExecutor.php`
**Interface:** `src/Contracts/QueryExecutorInterface.php`
**Date:** 2025-12-30

---

## 1. Overview

`QueryExecutor` executes Cypher queries against Neo4j with safety measures, timeout protection, result formatting, and comprehensive error handling. It enforces read-only mode by default.

**Location:** `src/Services/QueryExecutor.php`
**Lines:** 425
**Namespace:** `Condoedge\Ai\Services`

---

## 2. Constructor Dependencies

```php
public function __construct(
    private readonly GraphStoreInterface $graphStore,
    private readonly array $config = []
)
```

| Dependency | Type | Purpose |
|------------|------|---------|
| `$graphStore` | `GraphStoreInterface` | Graph database interface |
| `$config` | `array` | Configuration options |

**Config Options:**
- `default_timeout` (default: 30) - Query timeout in seconds
- `default_limit` (default: 100) - Max results to return
- `read_only_mode` (default: true) - Enforce read-only queries
- `default_format` (default: 'table') - Result format
- `slow_query_threshold_ms` (default: 1000) - Slow query logging threshold
- `log_slow_queries` (default: true) - Enable slow query logging
- `enable_explain` (default: true) - Allow EXPLAIN queries
- `max_limit` (default: 1000) - Maximum pagination limit

**Instantiation:** `AiServiceProvider` line 251-256

---

## 3. Public Methods

| Method | Signature | Purpose |
|--------|-----------|---------|
| `execute` | `(string $cypherQuery, array $parameters = [], array $options = []): array` | Execute query with safety checks |
| `executeCount` | `(string $cypherQuery, array $parameters = [], array $options = []): int` | Return count only |
| `executePaginated` | `(string $cypherQuery, int $page = 1, int $perPage = 20, ...): array` | Paginated execution |
| `explain` | `(string $cypherQuery, array $parameters = []): array` | Show execution plan |
| `test` | `(string $cypherQuery): bool` | Dry run validation |
| `cancel` | `(string $queryId): bool` | Cancel running query |

---

## 4. Data Flow

### 4.1 Primary Flow: `execute()`

```
Cypher Query Input
    ↓
[1] Empty Query Check
    → Returns success=true with empty data if blank
    ↓
[2] Read-Only Validation (if read_only=true)
    → containsWriteOperations() check
    → Throws ReadOnlyViolationException if write detected
    ↓
[3] Auto-Add LIMIT (if not present)
    → Appends "LIMIT {$limit}" to query
    ↓
[4] Execute Query
    → graphStore->query($cypherQuery, $parameters)
    ↓
[5] Format Results
    → formatAsTable() | formatAsGraph() | formatAsJson()
    ↓
[6] Collect Statistics
    → execution_time_ms, rows_returned
    ↓
[7] Slow Query Check
    → Log if exceeds threshold
    ↓
Result Array
```

### 4.2 Output Structure

```php
[
    'success' => true,
    'data' => [...formatted results...],
    'stats' => [
        'execution_time_ms' => 45.2,
        'rows_returned' => 10,
        'database_hits' => null,
        'rows_scanned' => null,
    ],
    'metadata' => [
        'format' => 'table',
        'read_only' => true,
        'timeout' => 30,
    ],
    'errors' => [],
]
```

---

## 5. Security Analysis - CRITICAL

### 5.1 Write Operation Detection
```php
private array $writeKeywords = [
    'CREATE', 'DELETE', 'REMOVE', 'MERGE', 'SET', 'DETACH'
];

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

**SECURITY CONCERN:** The check uses simple regex word boundary matching. This could potentially be bypassed with:
- Unicode characters (e.g., `CREA​TE` with zero-width space)
- Comments (e.g., `CREATE /* hidden */`)

**Recommendation:** Consider using a Cypher parser for more robust detection.

### 5.2 Query ID Injection in cancel()
```php
public function cancel(string $queryId): bool
{
    $killQuery = "CALL dbms.killQuery('{$queryId}')";
    $this->graphStore->query($killQuery);
    // ...
}
```

**SECURITY CONCERN:** The `$queryId` is directly interpolated into the query string without validation. This could allow Cypher injection if the queryId comes from user input.

**Recommendation:** Validate queryId format or use parameterized query.

### 5.3 Empty Query Handling
Empty queries return success without execution - this is documented behavior but could mask bugs.

---

## 6. Error Handling

### 6.1 Custom Exceptions
- `QueryExecutionException` - General execution failures
- `QueryTimeoutException` - Timeout exceeded
- `ReadOnlyViolationException` - Write operation in read-only mode

### 6.2 Timeout Detection
```php
$executionTime = round((microtime(true) - $startTime) * 1000, 2);
if ($executionTime >= ($timeout * 1000)) {
    throw new QueryTimeoutException(...);
}
```

Note: This is post-hoc detection. The actual query may not be killed if Neo4j doesn't enforce the timeout.

---

## 7. Notes and Anomalies

### 7.1 Unused Methods
- `executePaginated()` - Not called in production (found via grep)
- `explain()` - Not called in production
- `test()` - Not called in production
- `cancel()` - Not called in production

### 7.2 executeCount() Query Construction Issue
```php
$countQuery = "WITH * MATCH {$cypherQuery} RETURN count(*) as total";
```
This construction assumes the input query can be embedded in a WITH clause, which may not work for all query structures. The fallback regex is better:
```php
if (preg_match('/^(MATCH\s+.+?)\s+RETURN/i', $cypherQuery, $matches)) {
    $countQuery = $matches[1] . " RETURN count(*) as total";
}
```

### 7.3 Statistics Incomplete
```php
'database_hits' => null, // Would need Neo4j profile info
'rows_scanned' => null,  // Would need Neo4j profile info
```
These fields are always null. Consider using PROFILE instead of EXPLAIN to get actual statistics.

---

# Phase 2 Audit: QueryGenerator Service

**Task:** 20
**File:** `src/Services/QueryGenerator.php`
**Interface:** `src/Contracts/QueryGeneratorInterface.php`
**Date:** 2025-12-30

---

## 1. Overview

`QueryGenerator` transforms natural language questions into safe, valid Cypher queries using LLM and context from RAG. It includes template matching, retry logic, and comprehensive validation.

**Location:** `src/Services/QueryGenerator.php`
**Lines:** 528
**Namespace:** `Condoedge\Ai\Services`

---

## 2. Constructor Dependencies

```php
public function __construct(
    private readonly LlmProviderInterface $llm,
    private readonly GraphStoreInterface $graphStore,
    private readonly array $config = [],
    ?SemanticPromptBuilder $promptBuilder = null
)
```

| Dependency | Type | Purpose |
|------------|------|---------|
| `$llm` | `LlmProviderInterface` | LLM provider for query generation |
| `$graphStore` | `GraphStoreInterface` | Graph store for schema access |
| `$config` | `array` | Configuration options |
| `$promptBuilder` | `?SemanticPromptBuilder` | Prompt builder (auto-created if null) |

Additional internal dependencies:
- `$rateLimiter` - `RateLimiter::forLlm()` for API rate limiting

**Instantiation:** `AiServiceProvider` line 238-245

---

## 3. Public Methods

| Method | Signature | Purpose |
|--------|-----------|---------|
| `generate` | `(string $question, array $context, array $options = []): array` | Generate Cypher from question |
| `validate` | `(string $cypherQuery, array $options = []): array` | Validate query syntax/safety |
| `sanitize` | `(string $cypherQuery): string` | Remove dangerous operations |
| `getTemplates` | `(): array` | Get available templates |
| `detectTemplate` | `(string $question): ?string` | Match question to template |

---

## 4. Data Flow

### 4.1 Primary Flow: `generate()`

```
Question + Context
    ↓
[1] Template Detection (if enabled)
    → detectTemplate() matches regex patterns
    → If match: generateFromTemplate() - skip LLM
    ↓
[2] LLM Generation Loop (up to maxRetries)
    ↓
    [2a] Build Prompt
        → SemanticPromptBuilder::buildPrompt()
        → Adds error context if retry
    ↓
    [2b] Rate Limit Check
        → RateLimiter::waitAndAttempt(10)
        → Throws if rate limited
    ↓
    [2c] Call LLM
        → llm->complete(prompt, null, {temperature, max_tokens})
    ↓
    [2d] Handle "NO QUERY REQUIRED"
        → Returns empty cypher with confidence=1.0
    ↓
    [2e] Extract Cypher
        → Remove markdown code blocks
    ↓
    [2f] Validate
        → validate() checks syntax/safety
    ↓
    [2g] Success or Retry
        → If valid: return result
        → If invalid: retry with error context
    ↓
[3] All Retries Failed
    → Throw QueryGenerationException
```

### 4.2 Output Structure

```php
[
    'cypher' => 'MATCH (n:Team) RETURN n LIMIT 100',
    'explanation' => 'Lists all teams...',
    'confidence' => 0.8,
    'warnings' => ['Query missing LIMIT clause'],
    'metadata' => [
        'template_used' => null,
        'retry_count' => 0,
        'complexity' => 10,
    ],
]
```

---

## 5. Prompt Building

### 5.1 Delegation to SemanticPromptBuilder
The `buildPrompt()` method delegates entirely to `SemanticPromptBuilder`:
```php
private function buildPrompt(string $question, array $context, bool $allowWrite, ?string $previousError): string
{
    $prompt = $this->promptBuilder->buildPrompt($question, $context, $allowWrite);

    if ($previousError) {
        $prompt .= "\n\nPrevious attempt failed with error: {$previousError}\n";
        $prompt .= "Please fix the error and regenerate the query.\n\n";
        $prompt .= "CYPHER QUERY:";
    }

    return $prompt;
}
```

### 5.2 SemanticPromptBuilder Sections (in priority order)
1. project_context (10): Project name, description
2. generic_context (15): Additional context
3. schema (20): Database schema
4. relationships (30): Relationship types
5. example_entities (40): Sample data
6. similar_queries (50): Past successful queries
7. detected_entities (60): Entities in question
8. detected_scopes (70): Scopes/filters detected
9. pattern_library (80): Query patterns
10. query_rules (90): Generation rules
11. current_user (95): User context
12. question (100): The question
13. task_instructions (110): Final instructions

---

## 6. Validation

### 6.1 Dangerous Keywords
```php
private array $dangerousKeywords = [
    'DELETE', 'REMOVE', 'DROP', 'CREATE', 'MERGE', 'SET', 'DETACH'
];
```

### 6.2 Validation Checks
- Forbidden keywords (unless `allow_write=true`)
- Missing LIMIT clause (warning)
- Missing MATCH or RETURN clause (error)
- Complexity threshold (warning if exceeded)

### 6.3 Complexity Scoring
```php
$complexity = 0;
$complexity += substr_count(strtoupper($cypher), 'MATCH') * 10;
$complexity += substr_count(strtoupper($cypher), 'WHERE') * 5;
$complexity += substr_count($cypher, ']-') * 8;  // Joins
$complexity += preg_match_all('/\b(count|sum|avg|max|min)\b/i', $cypher) * 3;
```

---

## 7. Query Templates

Six built-in templates for common patterns:

| Template | Pattern | Example |
|----------|---------|---------|
| `list_all` | `/^(show\|list\|get\|display\|find)\s+all\s+(\w+)/i` | "Show all customers" |
| `count` | `/^(how many\|count\|number of)\s+(\w+)/i` | "How many orders?" |
| `find_by_property` | `/^find\s+(\w+)\s+(with\|where\|having)\s+(\w+)...` | "Find customers with email..." |
| `relationship_query` | `/^(show\|find\|get)\s+(\w+)\s+(connected to\|related to\|linked to)...` | "Show customers connected to orders" |
| `aggregation` | `/^(sum\|total\|average\|avg\|max\|min)\s+(.+)/i` | "Average order total" |
| `filtering` | `/^(\w+)\s+where\s+(.+)/i` | "Customers where age > 30" |

---

## 8. Rate Limiting

Uses `RateLimiter::forLlm()`:
```php
if (!$this->rateLimiter->waitAndAttempt(10)) {
    throw new QueryGenerationException('Rate limit exceeded for LLM API');
}
```

Configuration: `ai.rate_limits.llm_requests_per_minute` (default: 60)

---

## 9. Notes and Anomalies

### 9.1 Unused Private Method
```php
private function hasSemanticScopes(array $context): bool
```
This method checks for semantic scopes but is never called anywhere in the class.

### 9.2 Template System Underutilized
Templates provide fast, deterministic queries but:
- Only 6 patterns covered
- Most queries go through LLM
- `getTemplates()` and `detectTemplate()` not used externally

### 9.3 Explanation Generation Costs Extra LLM Call
When `explain=true`, generates explanation via separate LLM call:
```php
private function generateExplanation(string $cypher, string $question): string
{
    $prompt = "Explain this Cypher query in simple terms...";
    return $this->llm->complete($prompt, null, ['temperature' => 0.3, 'max_tokens' => 150]);
}
```
This doubles token usage for explained queries.

### 9.4 Confidence Calculation Simplistic
```php
private function calculateConfidence(string $cypher, array $context): float
{
    $confidence = 0.5; // Base confidence
    // +0.1 for each schema label found
    // +0.1 if has LIMIT
    // Capped at 1.0
}
```
Maximum achievable confidence is ~0.9 even for perfect queries.

---

# Phase 2 Audit: ResponseGenerator Service

**Task:** 20
**File:** `src/Services/ResponseGenerator.php`
**Interface:** `src/Contracts/ResponseGeneratorInterface.php`
**Date:** 2025-12-30

---

## 1. Overview

`ResponseGenerator` transforms raw database query results into human-readable natural language explanations using LLM. It uses the `HasInternalModules` trait for extensible prompt composition.

**Location:** `src/Services/ResponseGenerator.php`
**Lines:** 628
**Namespace:** `Condoedge\Ai\Services`

---

## 2. Constructor Dependencies

```php
public function __construct(
    private readonly LlmProviderInterface $llm,
    private readonly array $config = []
)
```

| Dependency | Type | Purpose |
|------------|------|---------|
| `$llm` | `LlmProviderInterface` | LLM provider for response generation |
| `$config` | `array` | Configuration options |

Uses `HasInternalModules` trait with config key: `ai.response_generator_sections`

**Instantiation:** `AiServiceProvider` line 262-266

---

## 3. Public Methods

### 3.1 Core Generation Methods

| Method | Signature | Purpose |
|--------|-----------|---------|
| `generate` | `(string $originalQuestion, array $queryResult, string $cypherQuery, array $options = []): array` | Main response generation |
| `generateEmptyResponse` | `(string $originalQuestion, string $cypherQuery, array $options = []): array` | Handle empty results |
| `generateErrorResponse` | `(string $originalQuestion, \Throwable $error, array $options = []): array` | User-friendly errors |

### 3.2 Analysis Methods

| Method | Signature | Purpose |
|--------|-----------|---------|
| `extractInsights` | `(array $queryResult): array` | Extract patterns, outliers |
| `suggestVisualizations` | `(array $queryResult, string $cypherQuery): array` | Suggest chart types |
| `summarize` | `(array $queryResult, int $maxItems = 10): array` | Truncate large results |

### 3.3 Configuration Methods

| Method | Signature | Purpose |
|--------|-----------|---------|
| `setProjectContext` | `(array $context): self` | Set project context |
| `setSystemPrompt` | `(string $prompt): self` | Custom system prompt |
| `addGuideline` | `(string $guideline): self` | Add formatting guideline |
| `setMaxDataItems` | `(int $max): self` | Limit data in prompt |

### 3.4 Module Pipeline Methods (from HasInternalModules)

| Method | Signature | Purpose |
|--------|-----------|---------|
| `buildPrompt` | `(array $context, array $options = []): string` | Build prompt from modules |
| `addModule` | `($section): self` | Add response section |
| `removeModule` | `(string $name): self` | Remove section |
| `replaceModule` | `(string $name, $section): self` | Replace section |

---

## 4. Data Flow

### 4.1 Primary Flow: `generate()`

```
Question + Query Result + Cypher Query
    ↓
[1] Empty Result Check
    → If empty (and not 'NO QUERY'): generateEmptyResponse()
    ↓
[2] Prepare Context
    → question, cypher, data, stats
    ↓
[3] Build Prompt (via module pipeline)
    → Process sections in priority order
    → Each section adds its content
    ↓
[4] LLM Call
    → llm->complete(prompt, null, {temperature, max_tokens})
    ↓
[5] Extract Insights
    → Count results
    → Calculate statistics for numeric data
    → Detect property patterns
    ↓
[6] Suggest Visualizations
    → Detect count queries → 'number'
    → Detect relationships → 'graph'
    → Detect multi-row → 'table'
    → Detect aggregations → 'bar-chart'
    → Detect time data → 'line-chart'
    ↓
Response Array
```

### 4.2 Output Structure

```php
[
    'answer' => 'Based on the data, there are 42 active teams...',
    'insights' => [
        'Found 42 results',
        'Average value: 15.5',
        'Results contain 3 properties: id, name, status',
    ],
    'visualizations' => [
        ['type' => 'table', 'rationale' => 'Multiple results...'],
        ['type' => 'bar-chart', 'rationale' => 'Aggregated data...'],
    ],
    'format' => 'text',
    'metadata' => [
        'style' => 'detailed',
        'result_count' => 42,
        'summarized' => true,
    ],
]
```

---

## 5. Prompt Building Pipeline

### 5.1 Default Sections (priority order)

| Priority | Section | Purpose |
|----------|---------|---------|
| 10 | `system` | System prompt setting LLM role |
| 15 | `security_restrictions` | Security/privacy guidelines |
| 20 | `project_context` | Project name/description |
| 30 | `question` | Original user question |
| 40 | `query_info` | Cypher query executed |
| 50 | `data` | Results data |
| 60 | `statistics` | Execution statistics |
| 70 | `guidelines` | Response formatting rules |
| 80 | `task` | Final task instructions |

### 5.2 Module Processing (via HasInternalModules)

```php
$this->processModules(
    beforeCallbackProcess: function($callback) use (&$prompt, $context, $options) {
        $prompt .= $callback($context, $options);
    },
    moduleProcess: function(ResponseSectionInterface $section) use (&$prompt, $context, $options) {
        if ($section->shouldInclude($context, $options)) {
            $prompt .= $section->format($context, $options);
        }
    },
    afterCallbackProcess: function($callback) use (&$prompt, $context, $options) {
        $prompt .= $callback($context, $options);
    }
);
```

---

## 6. Visualization Suggestions

### 6.1 Detection Logic

| Condition | Visualization | Rationale |
|-----------|---------------|-----------|
| `count(*)` in query or `count` key | `number` | KPI card |
| `MATCH ... -[` in query | `graph` | Graph visualization |
| Multi-row, multi-property | `table` | Tabular display |
| `GROUP BY` or multi-row count | `bar-chart` | Aggregated data |
| Time keys (date, timestamp, etc.) | `line-chart` | Time series |
| Fallback | `table` | General purpose |

---

## 7. Error Response Handling

### 7.1 Error Response Structure
```php
[
    'answer' => "I encountered an issue while trying to answer...",
    'insights' => ['Error occurred during query execution'],
    'visualizations' => [],
    'format' => $format,
    'metadata' => [
        'error' => true,
        'error_type' => 'Condoedge\\Ai\\Exceptions\\QueryTimeoutException',
        'error_message' => '...' // Only if include_details=true
    ],
]
```

### 7.2 Error-Specific Guidance
- Timeout → "The query took too long..."
- Syntax → "There was an issue with the generated query..."
- Other → "Please try rephrasing..."

---

## 8. Notes and Anomalies

### 8.1 Token Calculation
```php
private function calculateMaxTokens(int $maxWords): int
{
    return (int) ceil($maxWords / 0.75);
}
```
Uses 0.75 words per token ratio, which is reasonable for English.

### 8.2 Empty Response Fallback
If LLM call fails in `generateEmptyResponse()`, a hardcoded fallback is used:
```php
return [
    'answer' => "No results were found for your question...",
    'metadata' => [..., 'fallback' => true],
];
```

### 8.3 No Rate Limiting
Unlike `QueryGenerator`, `ResponseGenerator` does not use rate limiting for LLM calls. Consider adding for consistency.

### 8.4 Summarization Simplistic
```php
public function summarize(array $queryResult, int $maxItems = 10): array
{
    if (count($queryResult) <= $maxItems) {
        return $queryResult;
    }
    return array_slice($queryResult, 0, $maxItems);
}
```
Simple truncation without intelligent summarization.

### 8.5 Statistics Detection Limited
- `isNumericData()` only checks first row
- `hasTimeComponent()` only checks key names, not values
- No detection of categorical data, percentages, etc.

---

# Data Flow Between Services (Complete Pipeline)

## Full Question-to-Answer Pipeline

```
User Question
    ↓
┌─────────────────────────────────────────────────────────────────────┐
│                         AiManager::answerQuestion()                  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  [1] CONTEXT RETRIEVAL                                              │
│      ContextRetriever::retrieveContext()                            │
│      → Vector similarity search (similar_queries)                   │
│      → Graph schema (labels, relationships, properties)             │
│      → Example entities (relevant_entities)                         │
│      → Entity metadata (detected_entities, detected_scopes)         │
│                                                                     │
│  [2] QUERY GENERATION                                               │
│      QueryGenerator::generate()                                     │
│      → Template matching (fast path)                                │
│      → OR LLM generation with SemanticPromptBuilder                 │
│      → Validation and retry loop                                    │
│      → Returns: cypher, confidence, warnings                        │
│                                                                     │
│  [3] QUERY EXECUTION                                                │
│      QueryExecutor::execute()                                       │
│      → Read-only validation                                         │
│      → Auto-add LIMIT                                               │
│      → Execute against Neo4j                                        │
│      → Format results (table/graph/json)                            │
│      → Returns: data, stats, metadata                               │
│                                                                     │
│  [4] RESPONSE GENERATION                                            │
│      ResponseGenerator::generate()                                  │
│      → Build prompt from modules                                    │
│      → LLM call for natural language                                │
│      → Extract insights                                             │
│      → Suggest visualizations                                       │
│      → Returns: answer, insights, visualizations                    │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
    ↓
Complete Response to User
```

## Service Dependencies Graph

```
                    ┌──────────────────┐
                    │    AiManager     │
                    └────────┬─────────┘
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
        ▼                    ▼                    ▼
┌───────────────┐   ┌───────────────┐   ┌───────────────┐
│ContextRetriever│   │QueryGenerator │   │ResponseGenerator│
└───────┬───────┘   └───────┬───────┘   └───────┬───────┘
        │                   │                   │
        │         ┌─────────┴─────────┐         │
        │         │                   │         │
        ▼         ▼                   ▼         ▼
┌─────────────────────┐       ┌───────────────────┐
│    GraphStore       │       │   LlmProvider     │
│     (Neo4j)         │       │   (OpenAI/etc)    │
└─────────────────────┘       └───────────────────┘
        ▲
        │
┌───────┴───────┐
│ QueryExecutor │
└───────────────┘

External Services:
┌─────────────────┐
│  VectorStore    │ ← ContextRetriever
│   (Qdrant)      │
└─────────────────┘

┌─────────────────┐
│EmbeddingProvider│ ← ContextRetriever
│   (OpenAI)      │
└─────────────────┘
```

---

# Summary: Context & Query Services

## Service Comparison

| Aspect | ContextRetriever | QueryExecutor | QueryGenerator | ResponseGenerator |
|--------|------------------|---------------|----------------|-------------------|
| **Lines** | 1323 | 425 | 528 | 628 |
| **Dependencies** | 3 interfaces + 3 optional | 1 interface + config | 3 + optional | 1 interface + config |
| **Uses LLM** | No | No | Yes | Yes |
| **Uses Graph** | Yes (schema, entities) | Yes (queries) | Yes (schema) | No |
| **Uses Vector** | Yes (similarity) | No | No | No |
| **Rate Limited** | No | No | Yes | No (ISSUE) |
| **Modular** | No | No | Via SemanticPromptBuilder | Yes (HasInternalModules) |

## Security Concerns

| Severity | Service | Issue | Recommendation |
|----------|---------|-------|----------------|
| **MEDIUM** | QueryExecutor | `cancel()` method has queryId injection risk | Validate queryId format |
| **LOW** | QueryExecutor | Simple regex for write detection could be bypassed | Use Cypher parser |
| **LOW** | ContextRetriever | Label validation could be stricter | Add length limits |

## Dead/Unused Code

| Service | Methods Not Used in Production |
|---------|-------------------------------|
| ContextRetriever | `getMinimalContext`, `getContextWithStats`, `getContextWithBudget`, `getContextConfidence`, `getRelationshipWeight`, `filterRelationshipsByImportance` |
| QueryExecutor | `executePaginated`, `explain`, `test`, `cancel` |
| QueryGenerator | `getTemplates`, `detectTemplate`, `hasSemanticScopes` (private) |
| ResponseGenerator | All methods actively used |

## Recommendations

### High Priority
1. **Add rate limiting to ResponseGenerator** - consistency with QueryGenerator
2. **Fix QueryExecutor::cancel() injection risk** - validate queryId input
3. **Add tests for unused methods** - confirm they work if needed

### Medium Priority
4. **Consider deprecating unused methods** - reduce API surface
5. **Extract ContextRetriever statistics methods** - separate concern
6. **Enhance QueryGenerator templates** - more patterns for fast path

### Low Priority
7. **Improve token estimation** - use actual tokenizer
8. **Add PROFILE support** - get real query statistics
9. **Enhance visualization suggestions** - more chart types
