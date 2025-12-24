# Environment Variables

Complete reference for all environment variables.

---

## Quick Reference

Most important settings for getting started:

```env
# Project Context
APP_NAME="My Application"
AI_PROJECT_DESCRIPTION="Description of your application"
AI_PROJECT_DOMAIN=e-commerce

# LLM Provider
AI_LLM_PROVIDER=openai
OPENAI_API_KEY=sk-your-key-here

# Databases
NEO4J_URI=bolt://localhost:7687
NEO4J_PASSWORD=your-password
QDRANT_HOST=localhost
```

---

## Project Configuration

```env
# Application name (used in responses)
APP_NAME="My E-Commerce Platform"

# Project description for LLM context
AI_PROJECT_DESCRIPTION="E-commerce platform managing products, orders, and customers"

# Domain type for better query understanding
AI_PROJECT_DOMAIN=e-commerce
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `APP_NAME` | string | Laravel Application | Application name |
| `AI_PROJECT_DESCRIPTION` | string | — | Project description for LLM |
| `AI_PROJECT_DOMAIN` | string | general | Domain type (e-commerce, crm, etc.) |

**Domains:** `general`, `e-commerce`, `crm`, `healthcare`, `finance`, `education`

---

## Neo4j Configuration

```env
NEO4J_ENABLED=true
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-secure-password
NEO4J_DATABASE=neo4j
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `NEO4J_ENABLED` | bool | true | Enable Neo4j integration |
| `NEO4J_URI` | string | bolt://localhost:7687 | Connection URI |
| `NEO4J_USERNAME` | string | neo4j | Database username |
| `NEO4J_PASSWORD` | string | — | Database password |
| `NEO4J_DATABASE` | string | neo4j | Database name |

---

## Qdrant Configuration

```env
QDRANT_ENABLED=true
QDRANT_HOST=localhost
QDRANT_PORT=6333
QDRANT_API_KEY=
QDRANT_TIMEOUT=30
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `QDRANT_ENABLED` | bool | true | Enable Qdrant integration |
| `QDRANT_HOST` | string | localhost | Qdrant hostname |
| `QDRANT_PORT` | int | 6333 | Qdrant port |
| `QDRANT_API_KEY` | string | null | API key (cloud only) |
| `QDRANT_TIMEOUT` | int | 30 | Request timeout (seconds) |

---

## LLM Provider Configuration

### OpenAI

```env
AI_LLM_PROVIDER=openai
OPENAI_API_KEY=sk-your-key-here
OPENAI_MODEL=gpt-4o
OPENAI_TEMPERATURE=0.3
OPENAI_MAX_TOKENS=2000
```

### Anthropic

```env
AI_LLM_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-your-key-here
ANTHROPIC_MODEL=claude-3-5-sonnet-20241022
ANTHROPIC_TEMPERATURE=0.3
ANTHROPIC_MAX_TOKENS=2000
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_LLM_PROVIDER` | string | openai | Provider: `openai` or `anthropic` |
| `OPENAI_API_KEY` | string | — | OpenAI API key |
| `OPENAI_MODEL` | string | gpt-4o | OpenAI model name |
| `OPENAI_TEMPERATURE` | float | 0.3 | Response creativity (0-1) |
| `OPENAI_MAX_TOKENS` | int | 2000 | Maximum response tokens |
| `ANTHROPIC_API_KEY` | string | — | Anthropic API key |
| `ANTHROPIC_MODEL` | string | claude-3-5-sonnet-20241022 | Anthropic model |
| `ANTHROPIC_TEMPERATURE` | float | 0.3 | Response creativity (0-1) |
| `ANTHROPIC_MAX_TOKENS` | int | 2000 | Maximum response tokens |

---

## Embedding Configuration

```env
AI_EMBEDDING_PROVIDER=openai
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_EMBEDDING_PROVIDER` | string | openai | Embedding provider |
| `OPENAI_EMBEDDING_MODEL` | string | text-embedding-3-small | Embedding model |

---

## Auto-Discovery Configuration

```env
AI_AUTO_DISCOVERY_ENABLED=true
AI_AUTO_DISCOVERY_RUNTIME=false
AI_AUTO_DISCOVERY_CACHE=true
AI_AUTO_DISCOVERY_CACHE_TTL=3600
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_AUTO_DISCOVERY_ENABLED` | bool | true | Enable `ai:discover` command |
| `AI_AUTO_DISCOVERY_RUNTIME` | bool | **false** | Runtime discovery (SLOW!) |
| `AI_AUTO_DISCOVERY_CACHE` | bool | true | Cache discovered configs |
| `AI_AUTO_DISCOVERY_CACHE_TTL` | int | 3600 | Cache TTL (seconds) |

**Warning:** Keep `AI_AUTO_DISCOVERY_RUNTIME=false` in production.

---

## Auto-Sync Configuration

```env
AI_AUTO_SYNC_ENABLED=true
AI_AUTO_SYNC_QUEUE=false
AI_AUTO_SYNC_QUEUE_CONNECTION=redis
AI_AUTO_SYNC_QUEUE_NAME=default
AI_AUTO_SYNC_CREATE=true
AI_AUTO_SYNC_UPDATE=true
AI_AUTO_SYNC_DELETE=true
AI_AUTO_SYNC_FAIL_SILENTLY=true
AI_AUTO_SYNC_LOG_ERRORS=true
AI_AUTO_SYNC_EAGER_LOAD=true
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_AUTO_SYNC_ENABLED` | bool | true | Enable auto-sync on model events |
| `AI_AUTO_SYNC_QUEUE` | bool | false | Queue sync operations |
| `AI_AUTO_SYNC_QUEUE_CONNECTION` | string | null | Queue connection |
| `AI_AUTO_SYNC_QUEUE_NAME` | string | default | Queue name |
| `AI_AUTO_SYNC_CREATE` | bool | true | Sync on create |
| `AI_AUTO_SYNC_UPDATE` | bool | true | Sync on update |
| `AI_AUTO_SYNC_DELETE` | bool | true | Remove on delete |
| `AI_AUTO_SYNC_FAIL_SILENTLY` | bool | true | Don't throw exceptions |
| `AI_AUTO_SYNC_LOG_ERRORS` | bool | true | Log sync errors |
| `AI_AUTO_SYNC_EAGER_LOAD` | bool | true | Eager load relationships |

---

## Query Generation

```env
AI_QUERY_DEFAULT_LIMIT=100
AI_QUERY_MAX_LIMIT=1000
AI_ALLOW_WRITE_OPS=false
AI_QUERY_MAX_RETRIES=3
AI_QUERY_TEMPERATURE=0.1
AI_QUERY_MAX_COMPLEXITY=100
AI_ENABLE_TEMPLATES=true
AI_TEMPLATE_THRESHOLD=0.8
AI_QUERY_TIMEOUT=30
AI_CACHE_TTL=3600
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_QUERY_DEFAULT_LIMIT` | int | 100 | Default result limit |
| `AI_QUERY_MAX_LIMIT` | int | 1000 | Maximum result limit |
| `AI_ALLOW_WRITE_OPS` | bool | false | Allow write operations |
| `AI_QUERY_MAX_RETRIES` | int | 3 | Query retry attempts |
| `AI_QUERY_TEMPERATURE` | float | 0.1 | Query generation temperature |
| `AI_QUERY_TIMEOUT` | int | 30 | Query timeout (seconds) |
| `AI_CACHE_TTL` | int | 3600 | Cache duration (seconds) |

---

## Query Execution

```env
AI_EXEC_TIMEOUT=30
AI_EXEC_MAX_TIMEOUT=120
AI_EXEC_DEFAULT_LIMIT=100
AI_EXEC_MAX_LIMIT=1000
AI_EXEC_READ_ONLY=true
AI_EXEC_FORMAT=table
AI_EXEC_ENABLE_EXPLAIN=true
AI_EXEC_LOG_SLOW=true
AI_EXEC_SLOW_THRESHOLD=1000
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_EXEC_TIMEOUT` | int | 30 | Execution timeout (seconds) |
| `AI_EXEC_MAX_TIMEOUT` | int | 120 | Max timeout |
| `AI_EXEC_READ_ONLY` | bool | true | Read-only mode |
| `AI_EXEC_LOG_SLOW` | bool | true | Log slow queries |
| `AI_EXEC_SLOW_THRESHOLD` | int | 1000 | Slow query threshold (ms) |

---

## Response Generation

```env
AI_RESPONSE_FORMAT=text
AI_RESPONSE_STYLE=friendly
AI_RESPONSE_MAX_LENGTH=100
AI_RESPONSE_TEMPERATURE=0.3
AI_RESPONSE_INSIGHTS=true
AI_RESPONSE_VIZ=true
AI_RESPONSE_SUMMARIZE_THRESHOLD=10
AI_RESPONSE_HIDE_TECHNICAL=true
AI_RESPONSE_HIDE_STATS=true
AI_RESPONSE_HIDE_PROJECT=true
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_RESPONSE_FORMAT` | string | text | Format: `text`, `markdown`, `json` |
| `AI_RESPONSE_STYLE` | string | friendly | Style: `minimal`, `concise`, `friendly`, `detailed`, `technical` |
| `AI_RESPONSE_MAX_LENGTH` | int | 100 | Max response length (words) |
| `AI_RESPONSE_HIDE_TECHNICAL` | bool | true | Hide query/database references |
| `AI_RESPONSE_HIDE_STATS` | bool | true | Hide execution time |
| `AI_RESPONSE_HIDE_PROJECT` | bool | true | Hide project name |

---

## RAG (Context Retrieval)

```env
AI_VECTOR_SEARCH_LIMIT=5
AI_SIMILARITY_THRESHOLD=0.7
AI_INCLUDE_SCHEMA=true
AI_INCLUDE_EXAMPLES=true
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_VECTOR_SEARCH_LIMIT` | int | 5 | Similar queries to retrieve |
| `AI_SIMILARITY_THRESHOLD` | float | 0.7 | Minimum similarity score |
| `AI_INCLUDE_SCHEMA` | bool | true | Include graph schema |
| `AI_INCLUDE_EXAMPLES` | bool | true | Include example entities |

---

## Semantic Matching

```env
AI_SEMANTIC_MATCHING=true
AI_FALLBACK_EXACT_MATCH=true
AI_SEMANTIC_THRESHOLD_ENTITY=0.75
AI_SEMANTIC_THRESHOLD_SCOPE=0.70
AI_SEMANTIC_THRESHOLD_TEMPLATE=0.65
AI_SEMANTIC_THRESHOLD_LABEL=0.70
AI_SEMANTIC_CACHE_EMBEDDINGS=true
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_SEMANTIC_MATCHING` | bool | true | Enable semantic matching |
| `AI_FALLBACK_EXACT_MATCH` | bool | true | Fallback to exact matching |
| `AI_SEMANTIC_THRESHOLD_ENTITY` | float | 0.75 | Entity detection threshold |
| `AI_SEMANTIC_THRESHOLD_SCOPE` | float | 0.70 | Scope detection threshold |
| `AI_SEMANTIC_CACHE_EMBEDDINGS` | bool | true | Cache embeddings in memory |

---

## Semantic Context Selection

```env
AI_SEMANTIC_CONTEXT_ENABLED=true
AI_SEMANTIC_CONTEXT_COLLECTION=context_index
AI_SEMANTIC_CONTEXT_THRESHOLD=0.65
AI_SEMANTIC_CONTEXT_TOP_K=10
AI_SEMANTIC_CONTEXT_DIMENSION=1536
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_SEMANTIC_CONTEXT_ENABLED` | bool | true | Enable semantic context selection |
| `AI_SEMANTIC_CONTEXT_COLLECTION` | string | context_index | Qdrant collection name |
| `AI_SEMANTIC_CONTEXT_THRESHOLD` | float | 0.65 | Similarity threshold |
| `AI_SEMANTIC_CONTEXT_TOP_K` | int | 10 | Max context items |
| `AI_SEMANTIC_CONTEXT_DIMENSION` | int | 1536 | Vector dimensions |

---

## File Processing

```env
AI_FILE_PROCESSING_ENABLED=true
AI_FILE_COLLECTION=file_chunks
AI_FILE_CHUNK_SIZE=1000
AI_FILE_CHUNK_OVERLAP=200
AI_FILE_PRESERVE_SENTENCES=true
AI_FILE_QUEUE=false
AI_FILE_QUEUE_THRESHOLD=5242880
AI_FILE_FAIL_SILENTLY=true
AI_FILE_SEARCH_LIMIT=10
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_FILE_PROCESSING_ENABLED` | bool | true | Enable file processing |
| `AI_FILE_COLLECTION` | string | file_chunks | Qdrant collection |
| `AI_FILE_CHUNK_SIZE` | int | 1000 | Chunk size (chars) |
| `AI_FILE_CHUNK_OVERLAP` | int | 200 | Chunk overlap (chars) |
| `AI_FILE_QUEUE` | bool | false | Queue file processing |
| `AI_FILE_SEARCH_LIMIT` | int | 10 | Default search limit |

---

## Documentation

```env
AI_DOCS_ENABLED=true
AI_DOCS_PREFIX=ai-docs
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_DOCS_ENABLED` | bool | true | Enable documentation routes |
| `AI_DOCS_PREFIX` | string | ai-docs | URL prefix |

---

## Example .env File

```env
# ===========================================
# AI System Configuration
# ===========================================

# Project
APP_NAME="My E-Commerce Platform"
AI_PROJECT_DESCRIPTION="E-commerce platform with customers, orders, and products"
AI_PROJECT_DOMAIN=e-commerce

# LLM (choose one)
AI_LLM_PROVIDER=openai
OPENAI_API_KEY=sk-your-key-here
OPENAI_MODEL=gpt-4o

# Embeddings
AI_EMBEDDING_PROVIDER=openai
OPENAI_EMBEDDING_MODEL=text-embedding-3-small

# Neo4j
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-secure-password

# Qdrant
QDRANT_HOST=localhost
QDRANT_PORT=6333

# Discovery (keep runtime false in production)
AI_AUTO_DISCOVERY_RUNTIME=false

# Auto-sync
AI_AUTO_SYNC_ENABLED=true
AI_AUTO_SYNC_QUEUE=false

# Response style
AI_RESPONSE_STYLE=friendly

# Semantic features
AI_SEMANTIC_MATCHING=true
AI_SEMANTIC_CONTEXT_ENABLED=true
```

---

## Related Documentation

- [Entity Configuration](/docs/{{version}}/configuration/entities) - Configure entities
- [Response Styles](/docs/{{version}}/configuration/response-styles) - Response configuration
- [Basic Configuration](/docs/{{version}}/foundations/configuration) - Full configuration guide
