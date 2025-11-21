# Resilience & Security

This chapter documents the defensive tooling baked into the package so you can reason about failure modes before changing code.

## Injection Protection

### Cypher Sanitization

- All labels, relationship types, and property keys pass through `CypherSanitizer::validate*`.
- Allowed pattern: `/^[a-zA-Z_][a-zA-Z0-9_]*$/`.
- Reserved keywords (MATCH, DELETE, DROP, etc.) are blocked before reaching Neo4j.
- Identifiers are wrapped in backticks as a final safety net.

### Schema Inspector Safeguards

When auto-discovery hits SQLite/MySQL schema tables it parameterizes identifiers and maintains a denylist of suspicious patterns. This prevents malicious PRAGMA statements from being executed when user input leaks into discovery routines.

## Data Consistency Guarantees

### Compensating Transactions

```
Neo4j write success → Qdrant write failure → Neo4j rollback
Qdrant delete success → Neo4j delete failure → Qdrant restore
```

- Implemented inside `DataIngestionService`.
- Each store reports success/failure independently; status arrays always indicate which side failed.

### Retry & Circuit Breaker

| Feature | Default | Notes |
| --- | --- | --- |
| `RetryPolicy` attempts | 3–5 | Exponential backoff with jitter |
| `CircuitBreaker` threshold | 5 failures | Resets after 30s cool-down |
| Timeout | 30s | Governed by `AI_QUERY_TIMEOUT` |

Retries wrap outbound HTTP/Bolt calls (Neo4j, Qdrant, LLM). Circuit breakers short-circuit repeated failures to avoid queue pileups.

## Sensitive Data Sanitization

`SensitiveDataSanitizer` scrubs:
- OpenAI keys (`sk-...`), Anthropic keys (`sk-ant-...`), AWS credentials.
- Bearer tokens, passwords, database DSNs.
- Stack traces (absolute paths trimmed) and nested arrays.

Use it directly when logging custom payloads:

```php
Log::error('AI ingest failed', SensitiveDataSanitizer::forLogging([
    'api_key' => config('ai.llm.openai.api_key'),
    'exception' => $e,
]));
```

## Resource Guards

- **Auto-discovery** limits recursion depth (5 for relationships, 10 for config merges) to avoid runaway reflection.
- **Query generation** enforces a hard `LIMIT` (defaults to 100) and rejects destructive keywords when `allow_write_operations=false`.
- **Context retrieval** clamps similarity limits and payload sizes to protect Qdrant.

## Testing Coverage

| Suite | Purpose |
| --- | --- |
| `tests/Unit/Services/Resilience` | Retry + circuit breaker behaviors |
| `tests/Unit/Services/Security` | Sanitizer + injection guard scenarios |
| `tests/Unit/StressTests` | Adversarial inputs, high-volume ingestion |
| `tests/Integration/DualStorageCoordinationTest` | Rollback paths |

Run `composer test` before touching resilience code and add coverage whenever you introduce new guardrails.

## Operational Playbook

1. **Alerts** – Trigger on repeated failures from either store. Example Prometheus rule: `sum(rate(ai_ingest_failures_total[5m])) > 3`.
2. **Circuit State Metrics** – Expose breaker state via logs or metrics so observability dashboards can show OPEN/HALF_OPEN transitions.
3. **Docs Portal** – Surface this page inside `/ai-docs` so on-call engineers have a single reference when debugging outages.

Need a refresher on the moving pieces? Revisit the [Core Components Guide](/docs/{{version}}/internals/components).
