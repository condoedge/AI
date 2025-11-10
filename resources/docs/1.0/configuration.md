# Configuration Reference

Complete reference for all AI system configuration options.

---

## Configuration Files

- `config/ai.php` - Main AI system configuration
- `config/entities.php` - Entity mapping definitions
- `.env` - Environment-specific settings

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
|----------|------|---------|-------------|
| `NEO4J_URI` | string | bolt://localhost:7687 | Neo4j connection URI |
| `NEO4J_USERNAME` | string | neo4j | Database username |
| `NEO4J_PASSWORD` | string | - | Database password |
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
|----------|------|---------|-------------|
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
|----------|------|---------|-------------|
| `AI_LLM_PROVIDER` | string | openai | openai or anthropic |
| `OPENAI_API_KEY` | string | - | OpenAI API key |
| `OPENAI_MODEL` | string | gpt-4o | Model name |
| `OPENAI_TEMPERATURE` | float | 0.3 | Creativity (0.0-2.0) |
| `OPENAI_MAX_TOKENS` | int | 2000 | Max response length |
| `ANTHROPIC_API_KEY` | string | - | Anthropic API key |
| `ANTHROPIC_MODEL` | string | claude-3-5-sonnet-20241022 | Model name |

---

### Embedding Provider Configuration

```env
AI_EMBEDDING_PROVIDER=openai
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_EMBEDDING_PROVIDER` | string | openai | openai or anthropic |
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
|----------|------|---------|-------------|
| `AI_MAX_RESULTS` | int | 100 | Max query results |
| `AI_QUERY_TIMEOUT` | int | 30 | Query timeout (seconds) |
| `AI_CACHE_TTL` | int | 3600 | Cache duration (seconds) |
| `AI_VECTOR_SEARCH_LIMIT` | int | 5 | Similar queries to retrieve |
| `AI_SIMILARITY_THRESHOLD` | float | 0.7 | Min similarity score |
| `AI_INCLUDE_SCHEMA` | bool | true | Include schema in RAG |
| `AI_INCLUDE_EXAMPLES` | bool | true | Include examples in RAG |

---

### Documentation Settings

```env
AI_DOCS_ENABLED=true
AI_DOCS_PREFIX=ai-docs
```

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `AI_DOCS_ENABLED` | bool | true | Enable documentation routes |
| `AI_DOCS_PREFIX` | string | ai-docs | URL prefix for docs |

---

## Entity Configuration

Define in `config/entities.php`:

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
                        'purchased_at' => 'created_at'
                    ]
                ]
            ]
        ],
        'vector' => [
            'collection' => 'customers',
            'embed_fields' => ['name', 'description', 'notes'],
            'metadata' => ['id', 'email', 'status', 'created_at']
        ]
    ],

    'Team' => [
        'graph' => [
            'label' => 'Team',
            'properties' => ['id', 'name', 'size'],
            'relationships' => []
        ],
        'vector' => [
            'collection' => 'teams',
            'embed_fields' => ['name', 'description'],
            'metadata' => ['id', 'name', 'size']
        ]
    ]
];
```

---

## Graph Configuration Options

```php
'graph' => [
    'label' => 'NodeLabel',              // Neo4j node label
    'properties' => [                     // Properties to store
        'id',
        'name',
        'email'
    ],
    'relationships' => [                  // Relationships to create
        [
            'type' => 'RELATIONSHIP_TYPE',
            'target_label' => 'TargetNode',
            'foreign_key' => 'target_id',
            'properties' => [              // Optional
                'edge_prop' => 'source_field'
            ]
        ]
    ]
]
```

---

## Vector Configuration Options

```php
'vector' => [
    'collection' => 'collection_name',   // Qdrant collection
    'embed_fields' => [                   // Fields to embed
        'name',
        'description',
        'content'
    ],
    'metadata' => [                       // Metadata to store
        'id',
        'status',
        'created_at'
    ]
]
```

---

## Publishing Configuration

```bash
# Publish main configuration
php artisan vendor:publish --tag=ai-config

# Publish entity configuration
php artisan vendor:publish --tag=ai-entities
```

---

## Programmatic Configuration

### Override in Code

```php
use AiSystem\Facades\AI;

$ai = new AI([
    'embedding_provider' => 'anthropic',
    'llm_provider' => 'openai',
    'vector_store' => 'qdrant',
    'graph_store' => 'neo4j'
]);
```

### Access Configuration

```php
// Get config value
$provider = config('ai.llm.default');

// Get nested value
$model = config('ai.llm.openai.model');

// With default
$timeout = config('ai.qdrant.timeout', 30);
```

---

## Best Practices

### Security

- Never commit API keys to version control
- Use `.env` for sensitive data
- Rotate keys regularly
- Use separate keys for dev/prod

### Performance

- Increase `QDRANT_TIMEOUT` for slow operations
- Cache frequently accessed embeddings
- Use batch operations when possible
- Tune `AI_VECTOR_SEARCH_LIMIT` based on needs

### RAG Tuning

- Adjust `AI_SIMILARITY_THRESHOLD` (0.6-0.9)
- Lower threshold = more results, less relevant
- Higher threshold = fewer results, more relevant

---

See also: [Getting Started](/docs/{{version}}/getting-started) | [Troubleshooting](/docs/{{version}}/troubleshooting)
