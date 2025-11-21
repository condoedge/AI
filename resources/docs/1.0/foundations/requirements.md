# Requirements & Compatibility

Use this checklist to make sure your workstation or deployment target can run the AI Text-to-Query system with full functionality.

## Runtime Matrix

| Layer | Minimum | Recommended |
| --- | --- | --- |
| PHP | 8.1 | 8.3 with JIT disabled (more predictable latency) |
| Laravel | 9.x | 10.x or 11.x with auto-discovery |
| Database Drivers | `ext-curl`, `ext-json`, `ext-mbstring`, `ext-openssl` | Same |
| Composer | 2.5 | Latest stable |
| Neo4j | 4.4 | 5.15 LTS with APOC |
| Qdrant | 1.0 | 1.8+ with on-disk persistence |
| Nodeable Models | Any Eloquent model | Models implementing `Nodeable` + `HasNodeableConfig` |

## Network & Ports

| Service | Default Port | Notes |
| --- | --- | --- |
| Neo4j HTTP | 7474 | Used for browser/UI |
| Neo4j Bolt | 7687 | Used by the package driver |
| Qdrant HTTP | 6333 | REST + dashboard |
| Qdrant gRPC | 6334 | Optional but faster batches |
| LaRecipe Docs | `/{AI_DOCS_PREFIX}` | Defaults to `/ai-docs` |

## Credentials You Need

- **Neo4j user/pass** with write access to the configured database.
- **Qdrant API key** (only when targeting managed/cloud deployments).
- **LLM provider keys**: OpenAI `sk-...` or Anthropic `sk-ant-...`.
- **Optional**: Custom embedding provider key if you swap the default.

## Environment Variables To Set Early

```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=change-me
QDRANT_HOST=localhost
QDRANT_PORT=6333
AI_LLM_PROVIDER=openai
AI_EMBEDDING_PROVIDER=openai
OPENAI_API_KEY=sk-your-key
AI_DOCS_PREFIX=ai-docs
```

## Resource Planning

- **Memory**: 2 GB free RAM for Neo4j, 1 GB for Qdrant, 512 MB for PHP worker.
- **Storage**: SSD strongly recommended. Allocate 3× data volume for Qdrant snapshots plus Neo4j transaction logs.
- **CPU**: 2 vCPUs for local dev, 4+ vCPUs for shared environments to keep embeddings + graph commits snappy.

## Optional But Helpful

- Docker Desktop (for running the provided `docker-compose` file).
- Make or Taskfile for repeatable ingestion scripts.
- Postman/Insomnia for poking Qdrant/Neo4j health endpoints.
- Graph visualization plugin (Neo4j Bloom or Arrows) when demoing results.

Once every item is checked, move on to [Installing & Verifying the Stack](/docs/{{version}}/foundations/installing).
