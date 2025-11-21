# Infrastructure Playbook

This guide bundles the copy-paste commands, health checks, and monitoring tips you need to keep Neo4j, Qdrant, and the documentation portal running in every environment.

## Neo4j

### Docker (Recommended)

```bash
docker run -d \
  --name neo4j \
  -p 7474:7474 -p 7687:7687 \
  -e NEO4J_AUTH=neo4j/change-me \
  neo4j:5.15
```

### Health Checks

| Command | Purpose |
| --- | --- |
| `curl http://localhost:7474` | Confirms HTTP endpoint and login splash |
| `cypher-shell -u neo4j -p change-me "RETURN 1"` | Verifies Bolt connectivity |
| `docker logs neo4j | tail -n 20` | Check for heap/GC warnings |

### Hardening Tips

- Set `NEO4J_dbms_security_procedures_unrestricted=apoc.*` only if you rely on APOC utilities.
- Back up via `neo4j-admin database dump neo4j` before major upgrades.
- Cap Bolt query timeout with `AI_QUERY_TIMEOUT` to avoid runaway Cypher.

## Qdrant

### Docker

```bash
docker run -d \
  --name qdrant \
  -p 6333:6333 \
  -p 6334:6334 \
  -v qdrant_data:/qdrant/storage \
  qdrant/qdrant:latest
```

### Health Checks

| Endpoint | Description |
| --- | --- |
| `GET http://localhost:6333/healthz` | Must return `{ "status": "ok" }` |
| `GET http://localhost:6333/collections` | Lists collections created by ingestion |
| `POST /collections/<name>/points/search` | Smoke-test vector search (see Internals → Storage Guide) |

### Performance Tips

- Enable on-disk payloads for large metadata sets (`.config.storage = OnDisk`).
- Keep `AI_VECTOR_SEARCH_LIMIT` modest (3-8) to limit payload sizes.
- Batch embeddings (`AI::ingestBatch`) to reduce HTTP chatter.

## Documentation Portal (LaRecipe)

- Route prefix defaults to `/ai-docs`; override via `AI_DOCS_PREFIX`.
- Middleware array defaults to `['web']`. Add `auth` to protect internal docs.
- Every markdown file inside `resources/docs/1.0` is indexed automatically by `binarytorch/larecipe`.
- Rebuild docs tree on deploy with `php artisan larecipe:generate` when `AI_DOCS_GENERATE_ON_DEPLOY=true`.

## Observability Checklist

| Signal | Tooling |
| --- | --- |
| Neo4j metrics | `:sysinfo` in Neo4j Browser or `GET /metrics` |
| Qdrant metrics | `GET /metrics` (Prometheus format) |
| Laravel logs | `storage/logs/laravel.log` (look for AI namespace) |
| Queue health | `php artisan queue:failed` & Horizon if enabled |
| Docs portal | Add Pingdom/Synthetic check on `/{AI_DOCS_PREFIX}` |

## Backup Strategy

1. **Neo4j** – Nightly `neo4j-admin database backup`, retained for 7 days.
2. **Qdrant** – Snapshot volume (`qdrant_data`) or use Qdrant Cloud snapshots.
3. **Configuration** – Commit `config/ai.php`, `config/entities.php`, and scripts.
4. **Docs** – `resources/docs/1.0` already in Git; no extra step required.

With infra solidified, proceed to the [Usage track](/docs/{{version}}/usage) or fine-tune settings in the [Configuration Reference](/docs/{{version}}/foundations/configuration).
