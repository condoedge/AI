# Module 22: INFRASTRUCTURE - Documentation Updates

> **Status:** COMPLETE

## Documentation Needs Identified

### 1. AiServiceProvider Decomposition Guide (NEW)

**Priority:** HIGH
**Location:** `docs/architecture/service-providers.md`

Document the recommended decomposition of `AiServiceProvider` into specialized sub-providers:

```markdown
## Service Provider Architecture

The AI package uses a modular service provider architecture:

### AiCoreServiceProvider
- Vector/Graph store interfaces
- Embedding/LLM provider interfaces
- AiManager facade binding
- DataIngestionService
- ContextRetriever

### AiDiscoveryServiceProvider
- SchemaInspector
- EntityAutoDiscovery
- CypherScopeAdapter
- Property/Relationship discoverers

### AiSemanticServiceProvider
- SemanticMatcher
- SemanticIndexer
- ScopeSemanticMatcher
- SemanticContextSelector

### AiFileServiceProvider
- FileProcessor
- FileChunker
- ChunkStore
- FileSearchService
- FileContextProvider

### AiChatServiceProvider
- AiChatService
- ConversationContextManager
- ChatThemeFactory
- ChatSettings
```

---

### 2. Job Queue Configuration Guide (UPDATE)

**Priority:** MEDIUM
**Location:** `docs/operations/queue-configuration.md`

Add documentation for:

```markdown
## AI Job Queue Configuration

### Queue Names
Configure dedicated queues for AI operations:

```php
// config/ai.php
'queue' => [
    'entity_sync' => 'ai-sync',      // IngestEntityJob, SyncEntityJob, RemoveEntityJob
    'file_processing' => 'ai-files', // ProcessFileJob
],
```

### Job Characteristics

| Job | Timeout | Retries | Queue |
|-----|---------|---------|-------|
| IngestEntityJob | 120s | 3 | ai-sync |
| SyncEntityJob | 120s | 3 | ai-sync |
| RemoveEntityJob | 120s | 3 | ai-sync |
| ProcessFileJob | 300s | 3 | ai-files |

### Horizon Configuration

```php
'environments' => [
    'production' => [
        'ai-sync' => [
            'connection' => 'redis',
            'queue' => ['ai-sync'],
            'balance' => 'auto',
            'processes' => 3,
            'tries' => 3,
        ],
        'ai-files' => [
            'connection' => 'redis',
            'queue' => ['ai-files'],
            'balance' => 'simple',
            'processes' => 2,
            'tries' => 3,
            'timeout' => 300,
        ],
    ],
],
```
```

---

### 3. Sync Trigger Configuration (NEW)

**Priority:** MEDIUM
**Location:** `docs/configuration/sync-triggers.md`

Document the `RelatedModelSyncObserver` configuration:

```markdown
## Automatic Entity Sync on Related Model Changes

Configure automatic re-sync of parent entities when related models change.

### Configuration

```php
// config/ai.php
'sync_triggers' => [
    'Person' => [
        'on_related' => ['PersonTeam', 'PersonRole'],
        'foreign_key' => 'person_id',
    ],
    'Team' => [
        'on_related' => ['PersonTeam'],
        'foreign_key' => 'team_id',
    ],
],
```

### How It Works

1. When a model listed in `on_related` is created/updated/deleted
2. The observer finds the parent using `foreign_key`
3. Parent entity relationships are synced to Neo4j

### Model Namespace Resolution

By default, searches in `App\Models`. Configure additional namespaces:

```php
'model_namespaces' => [
    'App\\Models',
    'App\\Domain\\Models',
],
```
```

---

### 4. Health Check Endpoint (UPDATE)

**Priority:** LOW
**Location:** `docs/api/health.md`

```markdown
## Health Check Endpoint

### Request
`GET /api/ai/health`

### Response (200 OK)
```json
{
    "status": "healthy",
    "services": {
        "neo4j": {"status": "healthy"},
        "qdrant": {"status": "healthy"},
        "llm": {"status": "configured"}
    },
    "timestamp": "2024-01-15T10:30:00+00:00"
}
```

### Response (503 Service Unavailable)
```json
{
    "status": "unhealthy",
    "services": {
        "neo4j": {"status": "unhealthy", "error": "Connection refused"},
        "qdrant": {"status": "healthy"},
        "llm": {"status": "not_configured"}
    },
    "timestamp": "2024-01-15T10:30:00+00:00"
}
```

### Service Checks

| Service | Check Method | Healthy Criteria |
|---------|--------------|------------------|
| Neo4j | `getSchema()` | No exception |
| Qdrant | `listCollections()` | No exception |
| LLM | Config check | API key configured |
```

---

### 5. Facade Usage Guide (UPDATE)

**Priority:** LOW
**Location:** `docs/usage/facades.md`

Ensure documentation covers both facades:

```markdown
## Available Facades

### AI Facade
Primary facade for all AI operations.

```php
use Condoedge\Ai\Facades\AI;

// Ingestion
AI::ingest($entity);
AI::ingestBatch($entities);
AI::sync($entity);
AI::remove($entity);

// Context & Search
AI::retrieveContext("Show all teams");
AI::searchSimilar("team query");

// LLM Operations
AI::chat("What is 2+2?");
AI::chatJson("Generate JSON...");
AI::stream($messages, $callback);

// Query Pipeline
AI::generateQuery("Show customers");
AI::executeQuery($cypher);
AI::answerQuestion("How many users?");
```

### FileSearch Facade
Specialized facade for file search operations.

```php
use Condoedge\Ai\Facades\FileSearch;

// Content search (semantic)
FileSearch::searchByContent("configuration guide", [
    'limit' => 5,
    'file_types' => ['pdf', 'md'],
]);

// Metadata search (graph)
FileSearch::searchByMetadata([
    'extension' => 'pdf',
    'user_id' => 123,
]);

// Hybrid search
FileSearch::hybridSearch(
    contentQuery: "database setup",
    metadataFilters: ['team_id' => 1]
);
```
```

---

### 6. Binding Reference (NEW)

**Priority:** LOW
**Location:** `docs/architecture/container-bindings.md`

Create a reference of all container bindings for developers who need DI:

```markdown
## Container Bindings Reference

### Interface Bindings

| Interface | Concrete | Use Case |
|-----------|----------|----------|
| `VectorStoreInterface` | `QdrantStore` | Vector similarity search |
| `GraphStoreInterface` | `Neo4jStore` | Graph database operations |
| `EmbeddingProviderInterface` | `OpenAiEmbeddingProvider` | Text embeddings |
| `LlmProviderInterface` | `OpenAiLlmProvider` | LLM completions |
| `DataIngestionServiceInterface` | `DataIngestionService` | Entity ingestion |
| `ContextRetrieverInterface` | `ContextRetriever` | RAG context retrieval |
| `QueryGeneratorInterface` | `QueryGenerator` | NL to Cypher |
| `QueryExecutorInterface` | `QueryExecutor` | Cypher execution |
| `ResponseGeneratorInterface` | `ResponseGenerator` | Answer generation |
| `FileProcessorInterface` | `FileProcessor` | File processing |
| `FileChunkerInterface` | `SemanticChunker` | Document chunking |
| `ChunkStoreInterface` | `QdrantChunkStore` | Chunk storage |
| `AiChatServiceInterface` | `AiChatService` | Chat management |
| `ChatThemeFactoryInterface` | `ConfigChatThemeFactory` | UI theming |
| `ChatSettingsInterface` | `UserChatSettings` | User settings |
| `FileAccessResolverInterface` | `FileAccessResolver` | File access control |

### String Bindings

| Key | Class | Facade |
|-----|-------|--------|
| `'ai'` | `AiManager` | `AI::` |
| `'file-search'` | `FileSearchService` | `FileSearch::` |
| `'chat-theme-factory'` | `ChatThemeFactoryInterface` | - |
```

---

## Summary of Documentation Tasks

| Doc Update | Priority | Effort | Status |
|------------|----------|--------|--------|
| Service Provider Decomposition Guide | HIGH | 2 hours | Pending |
| Job Queue Configuration | MEDIUM | 1 hour | Pending |
| Sync Trigger Configuration | MEDIUM | 1 hour | Pending |
| Health Check Endpoint | LOW | 30 min | Pending |
| Facade Usage Guide | LOW | 30 min | Pending |
| Container Bindings Reference | LOW | 1 hour | Pending |

**Total Estimated Effort:** 6-7 hours
