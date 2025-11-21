# Data & Control Flows

Understanding the order of operations makes it easier to debug issues or add instrumentation. This chapter summarizes the most important flows with Mermaid diagrams you can tweak or paste into monitoring docs.

## Question → Answer Pipeline

```mermaid
sequenceDiagram
    participant User
    participant Controller as Laravel Controller
    participant AI as AiManager / Facade
    participant Context as ContextRetriever
    participant LLM as QueryGenerator + LLM
    participant Neo4j
    participant Response as ResponseGenerator

    User->>Controller: HTTP request / GraphQL / CLI
    Controller->>AI: chat($messages)
    AI->>Context: retrieveContext(question)
    Context->>Qdrant: vector search (similar queries)
    Context->>Neo4j: schema + sample nodes
    Context--)AI: context bundle
    AI->>LLM: generate Cypher (prompt + context)
    LLM--)AI: validated Cypher query
    AI->>Neo4j: execute query (QueryExecutor)
    Neo4j--)AI: rows + stats
    AI->>Response: generate natural language answer
    Response--)Controller: insights + suggested viz
    Controller--)User: JSON / HTML / streaming chunks
```

Key safeguards:
- Context retrieval tolerates partial failures (e.g., Qdrant offline) and still proceeds with available data.
- Query validation enforces read-only patterns unless explicitly overridden.
- Query execution enforces `AI_MAX_RESULTS` and `AI_QUERY_TIMEOUT`.

## Entity Ingestion Flow

```mermaid
flowchart LR
    A[Laravel Model Event] --> B{Auto Sync Enabled?}
    B -- No --> STOP
    B -- Yes --> C[Extract NodeableConfig]
    C --> D[DataIngestionService]
    D -->|Generate Embedding| E[Embedding Provider]
    D -->|Store Graph| F[Neo4jStore]
    D -->|Store Vector| G[QdrantStore]
    F --> H{Success?}
    G --> I{Success?}
    H -- No --> R[Rollback Neo4j]
    I -- No --> S[Rollback Qdrant]
    H -- Yes --> J[Status.graph = true]
    I -- Yes --> K[Status.vector = true]
    J & K --> L[Return status array]
```

Notes:
- `ingestBatch` groups embedding/API calls to reduce latency and ensures a single rollback covers the batch.
- Compensating transactions run synchronously to keep both stores consistent.

## Auto-Discovery Flow

```mermaid
stateDiagram-v2
    [*] --> Start
    Start --> CacheCheck: ConfigCache::remember
    CacheCheck --> Discovered: hit
    CacheCheck --> Reflect: miss
    Reflect --> Properties: fillable/casts/dates
    Properties --> Relationships: belongsTo()
    Relationships --> Scopes: scopeX() + CypherSpy
    Scopes --> EmbedFields: heuristics
    EmbedFields --> Merge: merge explicit config overrides
    Merge --> CacheWrite
    CacheWrite --> Discovered
```

- Cache keys follow `ai:discovery:{EntityClass}`.
- You can pre-warm everything with `php artisan ai:discover:cache`.

## Context Retrieval Flow

```mermaid
graph TD
    Q[Question] --> E[EmbeddingProvider]
    E --> V[Qdrant Search]
    V --> SQ[Similar Questions]
    Q -->|labels| N[Neo4j Schema]
    N --> Schema
    N --> Examples[Example Entities]
    Schema & Examples & SQ --> Combine[ContextAssembler]
    Combine --> Output{{Context Array}}
```

Each context payload contains:
- `similar_queries` – question text + top matching Cypher
- `graph_schema` – labels, relationships, indexed properties
- `example_entities` – sanitized snapshots per label
- `scopes` – discovered semantic filters

## Error Reporting Flow

1. Any exception during ingestion or querying is wrapped with a contextual array:
   ```php
   [
       'operation' => 'ingest',
       'entity' => Customer::class,
       'graph_stored' => false,
       'vector_stored' => true,
       'errors' => [...],
   ]
   ```
2. If `fail_silently=false`, the exception bubbles up. Otherwise it is logged via Laravel’s logger (with secrets automatically redacted).
3. LaRecipe docs can embed these patterns so on-call engineers know what to expect.

Review the [Resilience & Security chapter](/docs/{{version}}/internals/resilience) for deeper coverage of retries, circuit breakers, and sanitization.
