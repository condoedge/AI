# Configuration Reference

Complete reference for all AI system configuration options.

---

## Configuration Files

- `config/ai.php` – Main AI system configuration
- `config/entities.php` – Entity mapping definitions
- `.env` – Environment-specific settings

---

## Environment Variables

### Neo4j Configuration

```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-secure-password
NEO4J_DATABASE=neo4j
NEO4J_ENABLED=true
```

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `NEO4J_URI` | string | bolt://localhost:7687 | Neo4j connection URI |
| `NEO4J_USERNAME` | string | neo4j | Database username |
| `NEO4J_PASSWORD` | string | — | Database password |
| `NEO4J_DATABASE` | string | neo4j | Database name |
| `NEO4J_ENABLED` | bool | true | Enable/disable Neo4j |

---

### Qdrant Configuration

```env
QDRANT_HOST=localhost
QDRANT_PORT=6333
QDRANT_API_KEY=
QDRANT_TIMEOUT=30
QDRANT_ENABLED=true
```

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `QDRANT_HOST` | string | localhost | Qdrant hostname |
| `QDRANT_PORT` | int | 6333 | Qdrant port |
| `QDRANT_API_KEY` | string | null | API key (cloud only) |
| `QDRANT_TIMEOUT` | int | 30 | Request timeout (seconds) |
| `QDRANT_ENABLED` | bool | true | Enable/disable Qdrant |

---

### LLM Provider Configuration

```env
AI_LLM_PROVIDER=openai

# OpenAI
OPENAI_API_KEY=sk-your-key-here
OPENAI_MODEL=gpt-4o
OPENAI_TEMPERATURE=0.3
OPENAI_MAX_TOKENS=2000

# Anthropic
ANTHROPIC_API_KEY=sk-ant-your-key-here
ANTHROPIC_MODEL=claude-3-5-sonnet-20241022
ANTHROPIC_TEMPERATURE=0.3
ANTHROPIC_MAX_TOKENS=4000
```

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `AI_LLM_PROVIDER` | string | openai | `openai` or `anthropic` |
| `OPENAI_API_KEY` | string | — | OpenAI API key |
| `OPENAI_MODEL` | string | gpt-4o | Chat model name |
| `OPENAI_TEMPERATURE` | float | 0.3 | Creativity dial |
| `OPENAI_MAX_TOKENS` | int | 2000 | Max response length |
| `ANTHROPIC_API_KEY` | string | — | Anthropic API key |
| `ANTHROPIC_MODEL` | string | claude-3-5-sonnet-20241022 | Chat model name |
| `ANTHROPIC_TEMPERATURE` | float | 0.3 | Creativity dial |
| `ANTHROPIC_MAX_TOKENS` | int | 2000 | Max response length |

---

### Embedding Provider Configuration

```env
AI_EMBEDDING_PROVIDER=openai
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
```

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `AI_EMBEDDING_PROVIDER` | string | openai | `openai` or `anthropic` |
| `OPENAI_EMBEDDING_MODEL` | string | text-embedding-3-small | Embedding model |

---

### Query & RAG Settings

```env
AI_MAX_RESULTS=100
AI_QUERY_TIMEOUT=30
AI_CACHE_TTL=3600

AI_VECTOR_SEARCH_LIMIT=5
AI_SIMILARITY_THRESHOLD=0.7
AI_INCLUDE_SCHEMA=true
AI_INCLUDE_EXAMPLES=true
```

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `AI_MAX_RESULTS` | int | 100 | Max query results |
| `AI_QUERY_TIMEOUT` | int | 30 | Query timeout (seconds) |
| `AI_CACHE_TTL` | int | 3600 | Cache duration (seconds) |
| `AI_VECTOR_SEARCH_LIMIT` | int | 5 | Similar queries retrieved |
| `AI_SIMILARITY_THRESHOLD` | float | 0.7 | Min similarity score |
| `AI_INCLUDE_SCHEMA` | bool | true | Include graph schema in RAG |
| `AI_INCLUDE_EXAMPLES` | bool | true | Include example entities |

---

### Documentation Settings

```env
AI_DOCS_ENABLED=true
AI_DOCS_PREFIX=ai-docs
```

| Variable | Type | Default | Description |
| --- | --- | --- | --- |
| `AI_DOCS_ENABLED` | bool | true | Enable documentation routes |
| `AI_DOCS_PREFIX` | string | ai-docs | URL prefix for docs |

---

## Entity Configuration (`config/entities.php`)

```php
return [
    'Customer' => [
        'graph' => [
            'label' => 'Customer',
            'properties' => ['id', 'name', 'email', 'status'],
            'relationships' => [
                [
                    'type' => 'PURCHASED',
                    'target_label' => 'Order',
                    'foreign_key' => 'order_id',
                    'properties' => [
                        'purchased_at' => 'created_at',
                    ],
                ],
            ],
        ],
        'vector' => [
            'collection' => 'customers',
            'embed_fields' => ['name', 'description', 'notes'],
            'metadata' => ['id', 'email', 'status', 'created_at'],
        ],
    ],
];
```

### Graph Options

```php
'graph' => [
    'label' => 'NodeLabel',
    'properties' => ['id', 'name', 'email'],
    'relationships' => [
        [
            'type' => 'RELATIONSHIP_TYPE',
            'target_label' => 'TargetNode',
            'foreign_key' => 'target_id',
            'properties' => ['edge_prop' => 'source_field'],
        ],
    ],
];
```

### Vector Options

```php
'vector' => [
    'collection' => 'collection_name',
    'embed_fields' => ['name', 'description', 'content'],
    'metadata' => ['id', 'status', 'created_at'],
];
```

---

## Publishing Commands

```bash
php artisan vendor:publish --tag=ai-config
php artisan vendor:publish --tag=ai-entities
```

---

## Programmatic Access

```php
$provider = config('ai.llm.default');
$model = config('ai.llm.openai.model');
$timeout = config('ai.qdrant.timeout', 30);
```

Override at runtime by passing a config array when constructing `AiManager` or by adjusting bound interfaces inside a service provider.

---

## Best Practices

- Keep secrets in `.env`, never in Git.
- Warm discovery cache via `php artisan ai:discover:cache` before deploys.
- Use queues for auto-sync under heavy load (`AI_AUTO_SYNC_QUEUE=true`).
- Review `AI_SIMILARITY_THRESHOLD` whenever you ingest new entity types (0.65–0.8 works well for most corpora).

See also: [Installing & Verifying the Stack](/docs/{{version}}/foundations/installing) · [Troubleshooting & Diagnostics](/docs/{{version}}/foundations/troubleshooting)
