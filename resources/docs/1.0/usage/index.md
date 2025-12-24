# Usage & Extension

This track walks you through consuming the package (AI facade, ingestion workflows, context retrieval) and extending it with your own models, scopes, and integration layers.

## Audience

- Product engineers embedding natural-language querying into apps
- Backend teams maintaining ingestion pipelines
- Developers curious about extending providers, hooks, or UI adapters

## Highlights

- **Quick Start** – Ship your first natural-language question in ~5 minutes with auto-sync.
- **Auto-Sync** – Entities automatically sync to Neo4j + Qdrant on model events (no manual intervention).
- **Smart Ingestion** – Order-independent entity ingestion with relationship reconciliation.
- **File Processing** – Semantic search across PDF, DOCX, TXT files with automatic chunking and embedding.
- **Project Context** – Configure your business domain for smarter LLM query generation.
- **API Surfaces** – AI facade, `AiManager`, and granular services for ingestion, context, embeddings, and LLM I/O.
- **Batch Commands** – `ai:discover`, `ai:ingest`, `ai:sync-relationships`, `ai:process-files` for setup and maintenance.
- **Extensibility** – Override auto-discovery, customize prompt builders, add query patterns, inject custom providers, or plug into Laravel jobs/queues.
- **Quality Gates** – Testing recipes, observability hooks, and troubleshooting flows focused on runtime behavior.

## Reading Path

1. [Quick Start Scenarios](/docs/{{version}}/usage/quick-start)
2. [Simple Usage (AI Facade)](/docs/{{version}}/usage/simple-usage)
3. [Advanced Usage (Direct Services)](/docs/{{version}}/usage/advanced-usage)
4. [Data Ingestion API](/docs/{{version}}/usage/data-ingestion)
5. [Context Retrieval & RAG](/docs/{{version}}/usage/context-retrieval)
6. [Embeddings & LLM Providers](/docs/{{version}}/usage/embeddings) · [/llm](/docs/{{version}}/usage/llm)
7. [Laravel Integration Points](/docs/{{version}}/usage/laravel-integration)
8. [Extending the Package](/docs/{{version}}/usage/extending) – Query patterns, prompt builders, providers
9. [Testing Playbook](/docs/{{version}}/usage/testing) & [Examples](/docs/{{version}}/usage/examples)

Need to see how everything is built internally? Jump to the [Internals & Architecture track](/docs/{{version}}/internals).
