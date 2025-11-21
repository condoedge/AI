# Core Components Guide

This document maps each major component in the AI Text-to-Query system, the contract it implements, and how it collaborates with the rest of the stack. Treat it as a field guide when you need to dive into the codebase.

## Layered View

| Layer | Purpose | Key Classes |
| --- | --- | --- |
| Domain | Business contracts + value objects | `Nodeable`, `GraphConfig`, `VectorConfig`, `RelationshipConfig`, `HasNodeableConfig` |
| Services | Orchestration of AI workflows | `DataIngestionService`, `ContextRetriever`, `QueryGenerator`, `QueryExecutor`, `ResponseGenerator` |
| Providers | External infrastructure adapters | `Neo4jStore`, `QdrantStore`, `OpenAiLlmProvider`, `OpenAiEmbeddingProvider`, Anthropic counterparts |
| API Surface | Developer-friendly entry points | `AiManager`, `Condoedge\Ai\Facades\AI` |
| Support | Resilience/security helpers | `RetryPolicy`, `CircuitBreaker`, `SensitiveDataSanitizer`, `CypherSanitizer` |

## Auto-Discovery Stack

### `EntityAutoDiscovery`
- Reflects on Eloquent models to build initial `NodeableConfig` instances.
- Discovers properties from `$fillable`, `$casts`, `$dates`, and custom `$metadataColumns` arrays.
- Inspects `belongsTo` methods to infer relationships.
- Converts `scopeX()` methods to semantic aliases + Cypher-ready scope metadata.
- Caches results via `ConfigCache` (1-hour TTL by default) keyed by the FQCN.

### `CypherScopeAdapter`
- Uses the **Spy Pattern** to capture Eloquent builder calls without executing queries.
- Translates recorded calls (`where`, `whereBetween`, `whereHas`, etc.) into Cypher fragments recognized by the prompt builder.

### `NodeableConfig` Builder
- Fluent API to override discovery (`NodeableConfig::discover($model)->embedFields([...])`).
- Exposes `toGraphConfig()` / `toVectorConfig()` for strongly typed handoffs into ingestion services.

## Dual-Storage Coordination

### `DataIngestionService`
Responsible for keeping Neo4j and Qdrant in sync.

Flow per entity:
1. Resolve `GraphConfig` + `VectorConfig` from the Nodeable instance.
2. Generate embeddings (batch-friendly when using `ingestBatch`).
3. Persist node + relationships via `GraphStoreInterface` implementation (Neo4j).
4. Upsert vector payload to Qdrant via `VectorStoreInterface`.
5. Aggregate per-store status, retry transient failures, and run compensating transactions when one store fails.

### `GraphStoreInterface` / `Neo4jStore`
- HTTP/Bolt hybrid client with retry + circuit breaker baked in.
- Provides schema discovery (`getSchema()`), example entity queries, and generic Cypher execution.

### `VectorStoreInterface` / `QdrantStore`
- REST client for collection management, vector upserts, semantic search, and metadata-only scrolling.
- Supports payload filters and item-to-item recommendations used by the RAG layer.

## RAG & Query Generation

### `ContextRetriever`
- Embeds incoming questions via the configured embedding provider.
- Queries Qdrant for similar prompts + historical Cypher outputs.
- Retrieves graph schema + example nodes from Neo4j to ground the LLM.
- Produces a context payload consumed by `QueryGenerator` and by end users when debugging.

### `QueryGenerator`
- Pattern detection: matches the question to pre-defined templates in `config/ai-patterns.php` before falling back to free-form LLM prompts.
- Prompt assembly: merges system rules, graph schema, semantic examples, and optional guards (limit enforcement, reserved keyword ban).
- Validation loop: re-prompts up to 3 times if the generated Cypher fails linting or references unknown labels/props.

### `QueryExecutor`
- Executes validated Cypher against Neo4j with strict timeouts and result limits.
- Supports different output shapes (table, graph data, JSON) depending on consumer needs.

### `ResponseGenerator`
- Uses the configured LLM to turn raw rows into natural language summaries.
- Adds optional insights (outliers, averages, recommended visualizations) so UI layers can display more than raw data.

## API Surface

### `AiManager`
- Injectable orchestrator that wires together ingestion, context retrieval, embeddings, and LLM providers.
- Preferred entry point for service classes/controllers due to explicit dependencies.

### `AI` Facade
- Convenience layer for rapid prototyping or when working inside Blade views, Livewire components, or artisan commands.
- Facade methods proxy directly to `AiManager`, so swapping implementations remains simple.

## Support Systems

### Resilience Utilities
- `RetryPolicy`: Exponential backoff with jitter for network calls.
- `CircuitBreaker`: Failure threshold tracking (defaults: 5 failures, 30s cool-down) that prevents cascading outages.

### Security Utilities
- `CypherSanitizer`: Validates labels, relationship types, and property keys using strict regex + reserved keyword lists.
- `SensitiveDataSanitizer`: Redacts API keys/tokens from logs (`"***REDACTED***"`).

## Event-Driven Auto-Sync

The `HasNodeableConfig` trait registers model observers for `created`, `updated`, and `deleted` events. Each observer delegates to `autoSyncToAi`, which queues jobs (`IngestEntityJob`, `SyncEntityJob`, `RemoveEntityJob`) when `AI_AUTO_SYNC_QUEUE=true`.

You can disable sync per model by defining `$aiAutoSync = false` or per operation via `config('ai.auto_sync.operations.delete')`, etc.

---

Armed with this mental model, review the [Data & Control Flows](/docs/{{version}}/internals/data-flows) to see how these components behave in sequence.
