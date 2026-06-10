# 04 — Production Runbook

How to take a configured host of `condoedge/ai` (reference host: CondoEdge) to production, and operate it once it is there. Everything below is derived from current source; every load-bearing claim carries a `file:line` citation. Where the source cannot answer a question, the gap is marked explicitly instead of guessed at.

Deep-dive companions in `docs/audit/`: `00-overview.md` (C4 architecture) + the 17 layer docs in `docs/audit/layers/`, `91-contracts-and-abstraction.md` (contracts), `94-action-and-extensibility-abstraction.md` / `94b-native-tool-calling-design.md` (action system, native tool-calling), `94c-polymorphic-edge-sync-design.md` (polymorphic edges), `95-condoedge-domain-map.md` / `96-condoedge-recommended-questions.md` (CondoEdge specifics), `99-roadmap.md` + `EXECUTION-SUMMARY.md` (what was fixed and how it was verified).

---

## 1. Infrastructure checklist

### PHP

- **Runtime: PHP `^8.1` with `ext-curl` and `ext-json`** (`composer.json:17-19`). All outbound HTTP — Qdrant, OpenAI, Anthropic — is raw cURL; there is no Guzzle dependency (`src/VectorStore/QdrantStore.php:304-328`, `src/LlmProviders/AbstractHttpLlmProvider.php`).
- Contributor caveat: the dev harness pins `orchestra/testbench ^9.15` + `phpunit ^10` (`composer.json:30-31`), and Testbench 9 targets Laravel 11 (PHP ≥ 8.2). Production only needs `^8.1`; anyone running the test suite effectively needs 8.2+.
- Bundled extractors (no system packages needed): `phpoffice/phpword ^1.4` for DOCX, `smalot/pdfparser ^2.12` for text-layer PDFs (`composer.json:23-24`).

### Neo4j

- **Version: Neo4j 5.x.** Two version floors are implied directly by the package's Cypher:
  - `elementId(n)` in the node-merge query (`src/GraphStore/BoltNeo4jStore.php:78`) — requires Neo4j ≥ 5.0.
  - Generated `NOT EXISTS {(...)}` existential subqueries (`src/Services/Discovery/CypherPatternGenerator.php:220`) — requires ≥ 4.4, subsumed by the 5.0 floor above.
  - The dev stack runs `neo4j:5-community` (`docker-compose.yml:29`); the in-package infra doc uses `neo4j:5.15` (`resources/docs/1.0/foundations/infrastructure.md:14`). Provision 5.x in prod.
- **Edition:** nothing in the package's generated or hand-written Cypher requires Enterprise. Note that the config supports a non-default database name (`NEO4J_DATABASE`, appended as `?database=` to the Bolt URI, `src/GraphStore/BoltNeo4jStore.php:58-63`) — multi-database is an Enterprise feature in Neo4j itself, so keep the default `neo4j` database on Community.
- **APOC: the package itself needs none.** A search for `apoc.` across `src/` returns nothing (the only repo hits are the dev `docker-compose.yml`, a security test, and docs). **However, one CondoEdge config scope calls APOC:** `Unit.ordered_by_name` (CondoEdge `config/ai-entities/condo.php:2399`) calls `apoc.text.regexGroups` (`:2402`) — documented as an unresolved suspect in `docs/audit/96-condoedge-recommended-questions.md:109`. If the production Neo4j lacks APOC, any question that triggers that scope is a **hard query error, not zero rows**. The dev compose preloads APOC (`docker-compose.yml:43`), so this works in dev and detonates only in prod. Before go-live: install APOC on the CondoEdge Neo4j **or** remove/rewrite that scope.
- **Transport:** the URI scheme selects the driver — `bolt://`, `neo4j://`, `neo4j+s://`, `neo4j+ssc://` use the Bolt client (`laudis/neo4j-php-client ^3.4`); `http://`/`https://` use the legacy HTTP API on :7474 (`src/GraphStore/Neo4jStoreFactory.php:41-85`). If the laudis class is missing, the factory logs a warning and falls back to HTTP (`Neo4jStoreFactory.php:64-75`).
- `NEO4J_ENABLED` is a **dead flag** — no code reads it; the store connects regardless (`config/ai.php:339`, no consumer in `src/`).

### Qdrant

- REST API over **raw cURL at a hardcoded `http://{host}:{port}`** (`src/VectorStore/QdrantStore.php:30`). Standard endpoints: `/collections`, `/points/scroll`, `/points/search`; collections are created on demand with cosine distance plus a best-effort full-text payload index on `file_name` (`QdrantStore.php:33-56`). The optional API key is sent as an `api-key` header (`QdrantStore.php:313-315`).
- **No version pin exists in the client code** — any Qdrant serving the standard v1 REST collections/points API works; the dev stack uses `qdrant/qdrant:latest` (`docker-compose.yml:7`). Exact minimum version is an open question (see end of doc).
- **TLS is unsupported**: because the base URL is hardcoded `http://`, Qdrant Cloud (https-only) is effectively unusable and an API key travels cleartext. Deploy Qdrant on a private network segment with the app.
- `QDRANT_ENABLED` is a **dead flag**, same as `NEO4J_ENABLED` (`config/ai.php:358`).

### Outbound HTTPS (LLM providers)

Egress allowlist needs:

- `https://api.openai.com/v1/chat/completions` (`src/LlmProviders/OpenAiLlmProvider.php:25`) and `https://api.openai.com/v1/embeddings` (`src/EmbeddingProviders/OpenAiEmbeddingProvider.php:23`) — required with default config (OpenAI is the default for both LLM and embeddings).
- `https://api.anthropic.com/v1/messages` with `anthropic-version: 2023-06-01` (`src/LlmProviders/AnthropicLlmProvider.php:28-29,53`) — only when `AI_LLM_PROVIDER=anthropic`. **Even then OpenAI egress is still required**: `AnthropicEmbeddingProvider` is an explicit placeholder that always throws when asked to embed (`src/EmbeddingProviders/AnthropicEmbeddingProvider.php:11-21,82-100`).

### OCR / extractor binaries (file pipeline)

Needed only if scanned (image-only) PDFs must be ingested; PDFs with a native text layer extract via the bundled `smalot/pdfparser` first pass (`src/Services/Extractors/OcrPdfParser.php:13-16,26`):

- **Tesseract** binary — located via `TESSERACT_PATH`, then Windows Program Files candidates, then `where`/`command -v` (`OcrPdfParser.php:294-320`). Default OCR language is **`fra` (French)** (`OcrPdfParser.php:244`) — English-only deployments must set `OCR_LANG`.
- **Ghostscript or the imagick extension** as the PDF rasterizer — resolved via `GHOSTSCRIPT_PATH`/`GS_PROG` or `gswin64c`/`gswin32c` discovery, imagick fallback; neither present means `RuntimeException: No PDF rasterizer available`, thrown only when a scanned page actually needs rasterizing (`OcrPdfParser.php:80-118,477-495`).

Without these binaries only scanned-PDF ingestion fails; DOCX, TXT, MD, and text-layer PDFs are unaffected.

### Queue / scheduler

- Four `ShouldQueue` jobs exist: `src/Jobs/IngestEntityJob.php:22`, `ProcessFileJob.php:21`, `RemoveEntityJob.php:22`, `SyncEntityJob.php:22`. They are used **only** when `AI_AUTO_SYNC_QUEUE` or `AI_FILE_QUEUE` is turned on (both default `false`, `config/ai.php:194,858`). With the defaults, **every Nodeable model save and every file upload performs embedding + Neo4j + Qdrant writes inline in the web request**. For production, turn both queue flags on and run a worker — or accept the inline latency knowingly.
- **There is no scheduler registration anywhere** (no `Schedule::` usage in `src/` — verified by search). Ingestion and maintenance are command-driven (`ai:ingest`, `ai:sync-relationships`, etc.); any cadence is the host's cron to define (§4).

### Shared cache (recommended)

The LLM circuit breaker needs a shared, atomic cache driver (redis/memcached) for cross-process state; on array/file drivers it degrades to per-process state and each PHP worker trips independently (`config/ai.php:533-535`).

### Host database

Migrations auto-load from the package — they are not published and there is no publish tag for them (`src/AiServiceProvider.php:645`). They create `ai_conversations`, `ai_messages`, `ai_query_logs`, `ai_user_settings`, `ai_file_access_logs`, and add a guarded, nullable, indexed `processed_at` column to the **host's** `files` table (no-op if the table is missing, `database/migrations/add_processed_at_to_files_table.php:11-17`).

---

## 2. Environment variables

Conventions: **(secret)** = store in the secret manager, never in plaintext config. "Prod" column: **Yes** = must be set for a working production deployment; **Cond.** = required under the stated condition; **No** = optional tuning with a safe default. All keys resolve through `config/ai.php` unless marked `getenv` (read directly from the process environment — see the `config:cache` caveat in §3).

### Connections & providers (the must-get-right set)

| Variable | Purpose | Default | Prod |
|---|---|---|---|
| `NEO4J_URI` | Bolt/HTTP URI; scheme selects driver (`config/ai.php:341`; `Neo4jStoreFactory.php:41-85`) | `bolt://localhost:7687` | **Yes** |
| `NEO4J_USERNAME` | Neo4j user (`config/ai.php:342`) | `neo4j` | **Yes** |
| `NEO4J_PASSWORD` (secret) | Neo4j password (`config/ai.php:343`) | `neo4j_password` (dev placeholder) | **Yes** (unless encrypted variant set) |
| `NEO4J_PASSWORD_ENCRYPTED` (secret) | Laravel-encrypted password, takes precedence (`config/ai.php:344`; `src/GraphStore/Neo4jCredentialsTrait.php:35-61`) | null | Alternative to plain |
| `NEO4J_DATABASE` | Database name; non-default appended as `?database=` (`config/ai.php:345`; `BoltNeo4jStore.php:58-63`) | `neo4j` | No |
| `QDRANT_HOST` | Qdrant host (`config/ai.php:359`) | `localhost` | **Yes** |
| `QDRANT_PORT` | Qdrant REST port (`config/ai.php:360`) | `6333` | Cond. (non-default) |
| `QDRANT_API_KEY` (secret) | `api-key` header; travels **cleartext** over the hardcoded http transport (`config/ai.php:361`; `QdrantStore.php:30,313-315`) | null | No |
| `QDRANT_TIMEOUT` | cURL timeout, seconds (`config/ai.php:362`) | `30` | No |
| `AI_LLM_PROVIDER` | `openai` or `anthropic`; anything else throws at container resolution (`config/ai.php:372`; `AiServiceProvider.php:153-160`) | `openai` | No |
| `OPENAI_API_KEY` (secret) | LLM **and** embeddings key; empty key throws `API key is required` on **first use**, not at boot (`config/ai.php:375,463`; `AbstractHttpLlmProvider.php:43-45`; `OpenAiEmbeddingProvider.php:85-87`) | none | **Yes** (default providers) |
| `ANTHROPIC_API_KEY` (secret) | Anthropic key; same lazy constructor throw (`config/ai.php:382`) | none | Cond. (`AI_LLM_PROVIDER=anthropic`) |
| `AI_EMBEDDING_PROVIDER` | Embedding provider; **leave `openai`** — `anthropic` is a placeholder that always throws on use (`config/ai.php:457`; `AnthropicEmbeddingProvider.php:11-21`) | `openai` | No (do not change) |
| `AI_GRAPH_STORE` | Only `neo4j` is supported; else RuntimeException (`config/ai.php:336`; `AiServiceProvider.php:131-137`) | `neo4j` | No |
| `AI_VECTOR_STORE` | Only `qdrant` is supported; else RuntimeException (`config/ai.php:355`; `AiServiceProvider.php:121-127`) | `qdrant` | No |
| `NEO4J_ENABLED` / `QDRANT_ENABLED` | **Dead flags — read by no code** (`config/ai.php:339,358`) | `true` | Ignore |

### Models & token budgets

| Variable | Purpose | Default | Prod |
|---|---|---|---|
| `OPENAI_MODEL` | Chat model (`config/ai.php:376`) | `gpt-4o` | No |
| `OPENAI_TEMPERATURE` / `OPENAI_MAX_TOKENS` | Response sampling/budget (`config/ai.php:377-378`) | `0.3` / `2000` | No |
| `ANTHROPIC_MODEL` | Chat model (`config/ai.php:383`) | `claude-3-5-sonnet-20241022` | No |
| `ANTHROPIC_TEMPERATURE` / `ANTHROPIC_MAX_TOKENS` | (`config/ai.php:384-385`) | `0.3` / `2000` | No |
| `OPENAI_EMBEDDING_MODEL` | Embedding model, 1536 dims (`config/ai.php:464-465`) | `text-embedding-3-small` | No |
| `ANTHROPIC_EMBEDDING_MODEL` | Unused placeholder (`config/ai.php:470`) | — | Ignore |
| `AI_EMBEDDING_VECTOR_SIZE` | Override provider dimensions (`config/ai.php:460`) | null (= provider, 1536) | No |

### Security & tenancy

| Variable | Purpose | Default | Prod |
|---|---|---|---|
| `AI_SECURITY_ENABLED` | Team-based query filtering master switch. Keep `true` for multi-tenant; with `NullAuthAdapter` (no kompo/auth), `true` denies all secured queries — fail closed (`config/ai.php:244`; `src/Auth/NullAuthAdapter.php:39-42`) | `true` | **Yes — keep `true`** |
| `AI_ON_PERMISSION_UNEXISTENT` | `deny` (fail closed) or `bypass` (fail open, rollout escape hatch, logs warning) (`config/ai.php:269`) | `deny` | **Yes — leave `deny`** |
| `AI_SECURITY_LOG_CHANNEL` | Security log channel; **the host must define this channel** or security logging errors (`config/ai.php:283`) | `ai-security` | **Yes** (define channel) |
| `AI_ACCESS_DEFAULT_THRESHOLD` | Access-control default threshold (`config/ai.php:223`) | `5` | No |
| `AI_ALLOW_WRITE_OPS` | Allow write Cypher generation (`config/ai.php:483`) | `false` | **Keep `false`** |
| `AI_EXEC_READ_ONLY` | Read-only execution mode (`config/ai.php:503`) | `true` | **Keep `true`** |
| `AI_PROMPT_INCLUDE_USER_EMAIL` | Ship user email to the LLM (PII) (`config/ai.php:94`) | `false` | **Keep `false`** unless required |
| `AI_FILE_SECURITY_ENABLED` | File ACL gate; note audit P0-10 — the default scope is team-grain and `access_scope` is never read (`config/ai.php:886`; `docs/audit/99-roadmap.md:40`) | `true` | **Yes** (and see §6) |
| `AI_FILE_USE_USER_FILTER` / `AI_FILE_USE_TEAM_FILTER` | Fallback file filters (`config/ai.php:1002,1005`) | `true` / `true` | No |
| `AI_FILE_ACCESS_LOG` | Audit trail into `ai_file_access_logs` (`config/ai.php:1014`) | `true` | Keep `true` |

### Resilience, rate limits & timeouts

| Variable | Purpose | Default | Prod |
|---|---|---|---|
| `AI_LLM_CB_FAILURE_THRESHOLD` | Failures before the `llm` breaker opens (`config/ai.php:540`) | `5` | No |
| `AI_LLM_CB_RECOVERY_TIMEOUT` | Seconds before half-open probe (`config/ai.php:541`) | `30` | No |
| `AI_LLM_CB_SUCCESS_THRESHOLD` | Successes in half-open to close (`config/ai.php:542`) | `2` | No |
| `AI_LLM_RETRY_MAX_ATTEMPTS` | Transient-only retries (429/5xx/network) (`config/ai.php:545`) | `3` | No |
| `AI_LLM_RETRY_BASE_DELAY_MS` / `AI_LLM_RETRY_MAX_DELAY_MS` / `AI_LLM_RETRY_JITTER` | Backoff shape (`config/ai.php:546-548`) | `500` / `10000` / `0.3` | No |
| `AI_QUERIES_PER_MINUTE` | Query rate cap, DoS guard (`config/ai.php:520`) | `30` | No |
| `AI_LLM_REQUESTS_PER_MINUTE` | LLM call rate cap (`config/ai.php:521`) | `60` | No |
| `AI_QUERY_TIMEOUT` | Query generation timeout, seconds (`config/ai.php:489`) | `30` | No |
| `AI_EXEC_TIMEOUT` / `AI_EXEC_MAX_TIMEOUT` | Query execution timeout / ceiling (`config/ai.php:499-500`) | `30` / `120` | No |
| `AI_EXEC_LOG_SLOW` / `AI_EXEC_SLOW_THRESHOLD` | Slow-query logging / ms threshold (`config/ai.php:506-507`) | `true` / `1000` | No |
| `AI_CACHE_TTL` | Query-gen cache TTL, seconds (`config/ai.php:490`) | `3600` | No |

### Auto-sync & queues

| Variable | Purpose | Default | Prod |
|---|---|---|---|
| `AI_AUTO_SYNC_ENABLED` | Sync Nodeable models to Neo4j/Qdrant on create/update/delete. `true` = every model save touches Neo4j + Qdrant + the embedding API **inline** unless queued (`config/ai.php:191`) | `true` | Decide deliberately |
| `AI_AUTO_SYNC_QUEUE` | Queue sync ops — needs a worker when `true` (`config/ai.php:194`) | `false` | **Recommended `true`** |
| `AI_AUTO_SYNC_QUEUE_CONNECTION` / `AI_AUTO_SYNC_QUEUE_NAME` | Queue routing (`config/ai.php:197,200`) | null / `default` | No |
| `AI_AUTO_SYNC_CREATE` / `_UPDATE` / `_DELETE` | Per-operation switches (`config/ai.php:204-206`) | all `true` | No |
| `AI_AUTO_SYNC_FAIL_SILENTLY` / `AI_AUTO_SYNC_LOG_ERRORS` | Error behavior (`config/ai.php:210-211`) | `true` / `true` | No |
| `AI_AUTO_SYNC_EAGER_LOAD` | Eager-load relationships before sync (`config/ai.php:214`) | `true` | No |
| `AI_AUTO_DISCOVERY_ENABLED` | Master switch for `ai:discover` introspection (`config/ai.php:137`) | `true` | No |

### File pipeline

| Variable | Purpose | Default | Prod |
|---|---|---|---|
| `AI_FILE_PROCESSING_ENABLED` | File ingestion master switch (`config/ai.php:843`) | `true` | No |
| `AI_FILE_MODEL` | **Must point at the host's real File model** — CondoEdge uses the condoedge/utils FileModel (`config/ai.php:991`) | `App\Models\File` | **Yes** (verify) |
| `AI_FILE_COLLECTION` | Primary Qdrant collection for DB files (`config/ai.php:846`) | `file_chunks` | No |
| `AI_FILE_CHUNK_SIZE` / `AI_FILE_CHUNK_OVERLAP` | Chunking, chars (`config/ai.php:852-853`) | `1000` / `200` | No |
| `AI_FILE_PRESERVE_SENTENCES` / `_PARAGRAPHS` | Chunk boundary care (`config/ai.php:854-855`) | `true` / `true` | No |
| `AI_FILE_QUEUE` / `_QUEUE_CONNECTION` / `_QUEUE_NAME` | Queue file processing — needs a worker (`config/ai.php:858-860`) | `false` / null / `default` | **Recommended `true`** |
| `AI_FILE_QUEUE_THRESHOLD` | Bytes above which to queue (`config/ai.php:861`) | `5242880` (5 MB) | No |
| `AI_FILE_FAIL_SILENTLY` / `AI_FILE_LOG_ERRORS` | Error behavior (`config/ai.php:864-865`) | `true` / `true` | No |
| `AI_FILE_SEARCH_LIMIT` / `AI_FILE_MIN_SCORE` | Search defaults (`config/ai.php:868-869`) | `10` / `0.0` | No |
| `AI_FILE_CONTEXT_ENABLED` | File context in responses (`config/ai.php:883`) | `true` | No |
| `AI_DOCS_BASE_PATH` | Root for `physical_paths` globs (`ai:ingest --docs`) (`config/ai.php:899`) | `base_path()` | No |
| `AI_FILE_MIN_RELEVANCE` | Relevance gate; lower (~0.3) for cross-lingual corpora (`config/ai.php:920`) | `0.4` | No |
| `AI_FILE_RECALL_FLOOR` / `AI_FILE_RECALL_LIMIT` | Recall safety net (`config/ai.php:928,931`) | `0.3` / `2` | No |
| `AI_FILE_SNIPPET_LENGTH` / `AI_FILE_SNIPPET_CHUNKS` | Snippet shaping (`config/ai.php:947,952`) | `1200` / `3` | No |
| `AI_FILE_CONTEXT_WINDOW` | ±N adjacent chunks stitched in (`config/ai.php:963`) | `2` | No |
| `AI_FILE_ENUMERATION_ENABLED` / `_MAX` | "which files can I access" intent (`config/ai.php:970,977`) | `true` / `50` | No |

### OCR tuning — `getenv`, NOT config (see §3 `config:cache` caveat)

| Variable | Purpose | Default | Prod |
|---|---|---|---|
| `OCR_LANG` | Tesseract language — **defaults to French** (`OcrPdfParser.php:244`) | `fra` | **Yes** for non-French corpora |
| `TESSERACT_PATH` | Explicit tesseract executable (`OcrPdfParser.php:296-316`) | auto-discover | Cond. (OCR in use) |
| `GHOSTSCRIPT_PATH` / `GS_PROG` | Ghostscript executable (`OcrPdfParser.php:491`) | auto-discover / imagick | Cond. (OCR in use) |
| `OCR_PAGE_LIMIT` | OCR page cap, 0 = unlimited (`OcrPdfParser.php:167`) | `0` | No |
| `OCR_DIRECT_TEXT_MIN_WORDS` / `_MIN_LETTERS` | Native-text threshold to skip OCR (`OcrPdfParser.php:197-198`) | `45` / `180` | No |
| `OCR_MIN_WORD_CONF` / `OCR_MIN_LINE_CONF` | OCR confidence floors (`OcrPdfParser.php:341-342`) | `58` / `70` | No |

### RAG / semantic tuning

| Variable | Purpose | Default | Prod |
|---|---|---|---|
| `AI_VECTOR_SEARCH_LIMIT` / `AI_SIMILARITY_THRESHOLD` | Vector search defaults (`config/ai.php:593-594`) | `5` / `0.7` | No |
| `AI_INCLUDE_SCHEMA` / `AI_INCLUDE_EXAMPLES` | Prompt content switches (`config/ai.php:595-596`) | `true` / `true` | No |
| `AI_RAG_RERANK_ENABLED` | LlmReranker (extra LLM cost/query) vs NullReranker (`config/ai.php:603`; `AiServiceProvider.php:353-361`) | `false` | No |
| `AI_RAG_HYBRID_ENABLED` | BM25+dense RRF fusion for exact name/ID recall (`config/ai.php:609`) | `false` | No |
| `AI_SEMANTIC_MATCHING` | Semantic entity/scope detection; needs `ai:index-semantic --rebuild` after enabling (`config/ai.php:671`) | `true` | No |
| `AI_FALLBACK_EXACT_MATCH` | Exact-match fallback (`config/ai.php:674`) | `true` | No |
| `AI_SEMANTIC_THRESHOLD_ENTITY` / `_SCOPE` / `_TEMPLATE` / `_LABEL` | Detection thresholds (`config/ai.php:679-682`) | `0.75`/`0.70`/`0.65`/`0.70` | No |
| `AI_SEMANTIC_CACHE_EMBEDDINGS` | In-memory embedding memoization (`config/ai.php:693`) | `true` | No |
| `AI_SEMANTIC_CONTEXT_ENABLED` | Semantic context selection (token reduction); needs `ai:index-context` (`config/ai.php:718`) | `true` | No |
| `AI_SEMANTIC_CONTEXT_COLLECTION` / `_THRESHOLD` / `_TOP_K` / `_DIMENSION` | Context index knobs; dimension must match embeddings (`config/ai.php:721-731`) | `context_index`/`0.45`/`10`/`1536` | No |
| `AI_SEMANTIC_CONTEXT_CONVERSATION_FOCUS_SCORE` / `_PAGE_CONTEXT_FOCUS_SCORE` / `_QU_ENTITY_SCORE` | Soft priors (`config/ai.php:735-744`) | `0.55`/`0.55`/`0.8` | No |
| `AI_QUERY_UNDERSTANDING_ENABLED` | QU pre-processing — an extra LLM round-trip on **every** question (`config/ai.php:795`) | `true` | No |
| `AI_QUERY_UNDERSTANDING_MAX_TOKENS` / `_TEMPERATURE` | QU knobs (`config/ai.php:798,801`) | `500` / `0.0` | No |
| `AI_SCOPE_MATCHING_COLLECTION` / `_THRESHOLD` / `_TOP_K` | Scope matcher (`config/ai.php:821-827`) | `scope_examples`/`0.70`/`5` | No |

### Query generation / execution shaping

| Variable | Purpose | Default | Prod |
|---|---|---|---|
| `AI_QUERY_DEFAULT_LIMIT` / `AI_QUERY_MAX_LIMIT` | Row limits at generation (`config/ai.php:481-482`) | `100` / `1000` | No |
| `AI_QUERY_MAX_RETRIES` / `AI_QUERY_TEMPERATURE` / `AI_QUERY_MAX_COMPLEXITY` | Generation knobs (`config/ai.php:484-486`) | `3` / `0.1` / `100` | No |
| `AI_ENABLE_TEMPLATES` / `AI_TEMPLATE_THRESHOLD` | Query templates (`config/ai.php:487-488`) | `true` / `0.8` | No |
| `AI_EXEC_DEFAULT_LIMIT` / `AI_EXEC_MAX_LIMIT` | Row limits at execution (`config/ai.php:501-502`) | `100` / `1000` | No |
| `AI_EXEC_FORMAT` / `AI_EXEC_ENABLE_EXPLAIN` | Output format / EXPLAIN (`config/ai.php:504-505`) | `table` / `true` | No |

### Actions & native tool-calling

| Variable | Purpose | Default | Prod |
|---|---|---|---|
| `AI_ACTION_TOKEN_TTL` | Max age (s) of the signed PendingActionToken for deferred mutating-action approval; expired tokens rejected on decode; single-use via nonce (`config/ai.php:1223`, docs `:1199-1207`) | `900` | No |
| `AI_ACTION_NATIVE_TOOLS` | **The native tool-calling kill switch** — see §7 (`config/ai.php:1224`; `ActionToolGate.php:86-108`) | `false` | Staged (§7) |
| `AI_ACTION_TOOL_ROUNDS` | Max tool-enabled LLM rounds per turn, then one forced no-tools close (`config/ai.php:1225`) | `2` | No |
| `AI_ACTION_MAX_TOOL_CALLS` | Max action dispatches per turn (`config/ai.php:1226`) | `5` | No |

### Response style, chat UX, branding, observability, eval

| Variable | Purpose | Default | Prod |
|---|---|---|---|
| `AI_RESPONSE_FORMAT` / `AI_RESPONSE_STYLE` | `text|markdown|json`; style `minimal|concise|friendly|detailed|technical` — **minimal/concise suppress ALL action channels** (`config/ai.php:573-574`; `ActionToolGate.php:36,88-90`) | `text` / `friendly` | No |
| `AI_RESPONSE_MAX_LENGTH` / `AI_RESPONSE_TEMPERATURE` | Words / sampling (`config/ai.php:575-576`) | `500` / `0.3` | No |
| `AI_RESPONSE_INSIGHTS` / `AI_RESPONSE_VIZ` / `AI_RESPONSE_SUMMARIZE_THRESHOLD` | Enrichment (`config/ai.php:577-579`) | `true`/`true`/`10` | No |
| `AI_RESPONSE_HIDE_TECHNICAL` / `_HIDE_STATS` / `_HIDE_PROJECT` | Detail hiding (`config/ai.php:582-584`) | all `true` | No |
| `APP_NAME` | Branding + identity fallback (`config/ai.php:25,57`) | `Laravel Application` | No |
| `AI_PROJECT_DESCRIPTION` / `AI_PROJECT_DOMAIN` | LLM business context (`config/ai.php:26-27`) | generic / `general` | Recommended |
| `AI_ASSISTANT_NAME` / `AI_ASSISTANT_PERSONA` / `AI_ASSISTANT_LANGUAGE_POLICY` | White-label identity (`config/ai.php:57-66`) | derived/grounded-helper/reply-in-user-language | No |
| `AI_PROMPT_QUERY_ROLE_NOTE` | Internal translate-to-query note (`config/ai.php:96-101`) | built-in | No |
| `AI_DEBUG` | Verbose pipeline logging — **keep `false` in prod** (`config/ai.php:112`) | `false` | Keep `false` |
| `AI_LOG_CHANNEL` | AiLogger channel (`config/ai.php:113`; §5) | null (default channel) | Recommended |
| `AI_CHAT_SERVICE` | Chat service swap point (`config/ai.php:1028`) | `AiChatService::class` | No |
| `AI_CHAT_SURFACE` | What the FAB opens: `modal|drawer|screen` (`config/ai.php:1034`; `AiChatFloating.php:43-47`) | `modal` | No |
| `AI_CHAT_WELCOME_TITLE` / `AI_CHAT_WELCOME_MESSAGE` | Welcome copy (`config/ai.php:1038-1039`) | `AI Assistant` / generic | No |
| `AI_CHAT_PERSIST_HISTORY` / `AI_CHAT_MAX_MESSAGES` / `AI_CHAT_SESSION_PREFIX` | History (`config/ai.php:1059-1061`) | `true`/`50`/`ai_chat_history` | No |
| `AI_CHAT_MAX_HISTORY_MESSAGES` | Messages sent to the LLM (`config/ai.php:1064`) | `10` | No |
| `AI_CHAT_SYSTEM_PROMPT` | Override auto-generated system prompt (`config/ai.php:1065`) | null | No |
| `AI_CHAT_SHOW_TIMESTAMPS` / `_AVATARS` / `_TYPING` / `_SUGGESTIONS` / `_METRICS` | UI toggles (`config/ai.php:1068-1073`) | `false`/`true`/`true`/`true`/`false` | No |
| `AI_CHAT_MAX_SUGGESTIONS` / `AI_CHAT_INPUT_PLACEHOLDER` / `AI_CHAT_AUTO_FOCUS` | UI (`config/ai.php:1072-1077`) | `3`/i18n key/`true` | No |
| `AI_CHAT_ENABLE_COPY` / `_FEEDBACK` / `_REGENERATE` / `_EDIT` / `_MARKDOWN` / `_CODE_HIGHLIGHT` | Message affordances (`config/ai.php:1080-1085`) | `true`/`false`/`true`/`true`/`true`/`true` | No |
| `AI_CHAT_RESPONSE_STYLE` | Chat-specific style (`config/ai.php:1086`) | `friendly` | No |
| `AI_CHAT_FLOATING_POSITION` / `_OFFSET_X` / `_OFFSET_Y` / `_SIZE` / `_STYLE` | FAB chrome (`config/ai.php:1090-1094`) | `bottom-right`/`24`/`24`/`lg`/`gradient` | No |
| `AI_CHAT_MODAL_SIZE` / `AI_CHAT_MODAL_FULLSCREEN` | Modal chrome (`config/ai.php:1099-1100`) | `xl` / `false` | No |
| `AI_CHAT_DRAWER_POSITION` / `AI_CHAT_DRAWER_WIDTH` | Drawer chrome (`config/ai.php:1105-1106`) | `right` / `400px` | No |
| `AI_UI_FACTORY` / `AI_UI_THEME` | Theme factory / theme (`config/ai.php:1127,1130`) | `UserChatThemeFactory::class` / `green` | No |
| `AI_EVAL_THRESHOLD_UNDERSTANDING` / `_RETRIEVAL` / `_PROMPT` / `_GENERATION` | `ai:eval` stage pass gates (`config/ai.php:434-437`) | all `0.6` | No (eval only) |
| `AI_EVAL_ROW_PASS_THRESHOLD` / `AI_EVAL_JUDGE_TEMPERATURE` / `AI_EVAL_JUDGE_MAX_TOKENS` / `AI_EVAL_REPORT_PATH` | Eval knobs (`config/ai.php:440-448`) | `0.6`/`0.0`/`800`/`storage/app/ai-eval-report.json` | No |

---

## 3. Install sequence

### 3.1 Code, config, migrations

```bash
composer install --no-dev --optimize-autoloader
```

The service provider auto-registers via the composer `extra.laravel.providers` block (`composer.json:33-39`). Then publish what production needs:

```bash
# Recommended: the config file, so host overrides are explicit and diffable
php artisan vendor:publish --tag=ai-config
```

Optional tags: `ai-assets` (the chat scroll/injection JS is inlined from the package at render time, `src/Kompo/AbstractChatSurface.php:187-188`, and nothing in `src/` loads the published CSS — publishing is not required for the chat to work), `ai-lang` (JSON translations are also auto-loaded at boot, so publishing is cosmetic, `src/AiServiceProvider.php:647,663-665`), `ai-docs` (the LaRecipe documentation site), `ai-entities` (normally **generated** by `ai:discover`, not published). Tag definitions: `src/AiServiceProvider.php:649-671`.

```bash
php artisan migrate
```

Migrations load straight from the package (`AiServiceProvider.php:645`); routes likewise (`:642-643`). No publish step exists or is needed for either.

### 3.2 The `config:cache` caveat — verified

- **`php artisan config:cache` currently fails on any host of this package.** `config/ai.php:1333` places a Closure in `query_generator_sections` (`fn(SemanticPromptBuilder $b) => new PatternLibrarySection(...)`); closures cannot be serialized, and the package config is merged into the cached set whether or not you published it. Until that entry is replaced with a class-string section, do not add `config:cache` to the deploy pipeline. The same constraint applies to closure-based `entity_actions`/`generic_actions` the config documents.
- **No `env()` calls exist in `src/` outside config** (verified by search across `src/**/*.php`), so once the closure is fixed, config caching is otherwise safe.
- **`getenv()` is a separate trap:** the OCR knobs (`OCR_*`, `TESSERACT_PATH`, `GHOSTSCRIPT_PATH`/`GS_PROG`, `src/Services/Extractors/OcrPdfParser.php:167-491`) read the **process environment directly**. With a cached config Laravel does not load `.env` at all, so these values must be real machine/process environment variables (FPM pool config, systemd unit, container env) — `.env` entries alone will silently vanish. (`PendingActionToken`'s `getenv('APP_KEY')` at `:225` is only a unit-test fallback after `config('app.key')` — not a concern.)

### 3.3 Smoke test before any ingestion

```bash
php artisan ai:diagnose
```

Checks config (`entities.php` presence, `ai.llm.default`), Neo4j, Qdrant, the LLM API, and the embedding provider; non-zero exit on any failure (`src/Console/Commands/DiagnoseCommand.php:32-57`). This matters because the LLM/embedding singletons are **lazy** — a missing API key throws on first use, not at boot, so without this step the first failure is a user-visible chat error.

### 3.4 Ingestion bootstrap — order matters

Run in this order. The reason for the ordering is the skip-missing-target edge sync: during bulk ingest, `RelationshipSyncer` checks whether the target node exists and **skips the edge when it does not**, logging "Run ai:sync-relationships after bulk ingestion" (`src/Services/RelationshipSyncer.php:58-76`). Reference data (e.g. Users) ingested after its dependents leaves dangling FK edges that only the reconcile pass creates.

```bash
# 1. Entity catalog (skip on CondoEdge — curated configs already exist
#    in CondoEdge/config/ai-entities/*.php; see docs/audit/94c §6.1)
php artisan ai:discover            # add --enhance for LLM-generated descriptions/scopes

# 2. Validate the catalog (non-zero exit on structural errors)
php artisan ai:validate-config

# 3. Entity nodes + relationships + per-entity embeddings, in one bulk pass
php artisan ai:ingest --fresh      # --fresh only on first bootstrap: wipes both stores
                                   # add --docs to index ai.file_context.physical_paths

# 4. Reconcile relationships skipped because targets did not exist yet
php artisan ai:sync-relationships

# 5. Auxiliary embedding indexes (semantic context, fuzzy matching, scopes)
php artisan ai:index-context
php artisan ai:index-semantic
php artisan ai:index-scopes

# 6. Extract/chunk/embed existing database files
php artisan ai:process-files
```

Command sources: `IngestEntitiesCommand.php:36-42`, `SyncRelationshipsCommand.php:40-44`, `IndexContextCommand.php:27-30`, `IndexSemanticCommand.php:45-50`, `IndexScopesCommand.php:22-23`, `ProcessFilesCommand.php:38-43`.

> **Do not rely on `ai:setup` for the ingest step.** The orchestrator's third step calls `ai:ingest-entities` (`src/Console/Commands/SetupCommand.php:101`), but the registered command name is `ai:ingest` (`IngestEntitiesCommand.php:36`) — `$this->call()` on an unregistered name throws `CommandNotFoundException`, so a full `ai:setup` run dies at that step. This is the same defect class as the already-fixed P0-8 (`docs/audit/99-roadmap.md:38`), one line further down. `ai:setup --skip-ingest` (validate + index + files only) works. Until the call site is fixed, run the steps individually as above.

### 3.5 Verifying each step

| After | Verify |
|---|---|
| `migrate` | `ai_conversations`, `ai_messages`, `ai_query_logs`, `ai_user_settings`, `ai_file_access_logs` tables exist; host `files` table has `processed_at` |
| `ai:diagnose` | exit code 0; all five checks pass |
| `ai:ingest` | Neo4j: `MATCH (n) RETURN labels(n)[0] AS l, count(*) ORDER BY count(*) DESC` shows your entity labels with plausible counts. Qdrant: `GET http://{host}:6333/collections` lists the entity collections |
| `ai:sync-relationships` | command output reports created/skipped counts; re-running reports 0 created (idempotent — existing edges are skipped) |
| index commands | `ai:index-semantic --check` reports index status; Qdrant lists `context_index`, `scope_examples`, `semantic_entities`/`semantic_scopes`/`semantic_templates` |
| `ai:process-files` | `file_chunks` collection exists; `files.processed_at` populated; re-run processes 0 files |
| end-to-end | `php artisan ai:test-question "How many units are in my union?" --debug` — note its own warning that `currentTeamId()` is null in CLI, so team-security filters may differ from web (`TestQuestionCommand.php:44-47`). Then `GET /api/ai/health` returns 200 |

---

## 4. Re-ingestion & maintenance

### When config changes require a re-sync

| Change | Required action |
|---|---|
| Entity added/removed, properties or relationships changed in `config/entities.php` (or CondoEdge's `config/ai-entities/*`) | `ai:ingest --model=...` for the affected model (or full), then `ai:sync-relationships` |
| Descriptions, aliases, scope wording changed | `ai:index-context --force`, `ai:index-scopes --force`, `ai:index-semantic --rebuild` — the embedded text changed, so the vectors are stale |
| `AI_SEMANTIC_MATCHING` turned on | `ai:index-semantic --rebuild` (`config/ai.php:671` note) |
| Embedding model or vector size changed | full re-embed: `ai:ingest --fresh`, all three index commands, `ai:process-files --force` — old vectors are in a different space |
| File chunking knobs changed (`AI_FILE_CHUNK_*`) | `ai:process-files --force` |

### Safe re-run semantics

- **`ai:ingest` is an upsert** — node writes are `MERGE ... SET` (`src/GraphStore/BoltNeo4jStore.php:78`), so re-running converges rather than duplicating. `--from=` resumes a crashed bulk run; `--chunk=` tunes batch size. `--fresh` is the destructive flag — never in routine maintenance.
- **`ai:sync-relationships` is the reconcile path** — creates missing edges, skips existing ones (`SyncRelationshipsCommand.php:40-44`). Safe to run any time, any number of times.
- **`ai:process-files` skips anything with `processed_at` set** unless `--force` (`ProcessFilesCommand.php:38-43`).
- **`ai:enhance` writes config after each entity** (crash-resilient, `EnhanceConfigCommand.php:30-34`) — but it is an LLM-heavy setup-time tool; run it in a working copy and review the diff, not against prod config in place.

### Suggested cadence

Nothing in the package schedules itself (§1) — these are host cron recommendations, not source-mandated:

- **Nightly `ai:sync-relationships`**: auto-sync (`AI_AUTO_SYNC_*`) keeps day-to-day data fresh, but it fails silently by default (`config/ai.php:210`) and creation-order races leave skipped edges; the nightly pass converges them.
- **`ai:eval` after any entity-config change and before releases** — it exits non-zero when a stage average falls below `ai.eval.stage_thresholds` (`EvalCommand.php:106-119`), so it can gate a deploy. `--offline` runs signals-only without LLM judge cost.
- **`ai:diagnose` as a deploy-time post-check**, every deploy.

---

## 5. Resilience & monitoring

### The LLM circuit breaker

- Every LLM request (both providers — they share `AbstractHttpLlmProvider`) runs through a circuit breaker named **`llm`** wrapping a transient-only retry (`AbstractHttpLlmProvider.php:275-294`).
- Thresholds (config defaults): **5 failures to open, 30 s recovery timeout, 2 successes in half-open to close** (`config/ai.php:540-542`). Retry: 3 attempts, 500 ms base / 10 s max delay, 0.3 jitter, retrying **only** `TransientLlmException` (429 / 5xx / network) so permanent errors (400/401) fail fast (`AbstractHttpLlmProvider.php:304-310`; `config/ai.php:545-548`).
- The HTTP-API graph store has its own `neo4j` breaker (`src/GraphStore/Neo4jStore.php:55`); the Bolt store (the production transport) relies on the driver — a known asymmetry (`docs/audit/93-dead-code-and-duplication.md`, D-12).

**What OPEN looks like.** The breaker throws `CircuitBreakerOpenException` with the message `Circuit breaker 'llm' is OPEN. Service unavailable.` (`src/Services/Resilience/CircuitBreaker.php:68-72`). The chat UI maps it to the generic error translation (`src/Kompo/Presenters/ChatPresenter.php:163-169`), so users just see "something went wrong" on **every** question.

**The documented incident shape:** a run of provider 429s (e.g. an OpenAI quota event) opens the breaker, after which **all questions fail until the recovery timeout** — which looks exactly like a logic regression and is not one. Before debugging the pipeline, grep the logs for the `Circuit breaker 'llm' is OPEN` message and for 429 responses preceding it. (This is the live-eval incident referenced in `docs/audit/94b-native-tool-calling-design.md:225`; the tool loop is designed to never make "one more call against a possibly-OPEN breaker" for the same reason.)

**Operational requirement:** cross-process breaker state needs a shared atomic cache driver — redis or memcached. On array/file cache drivers each PHP worker keeps its own breaker, tripping and recovering independently (`config/ai.php:533-535`).

### Query timeouts

Generation timeout `AI_QUERY_TIMEOUT` (30 s, `config/ai.php:489`); execution timeout `AI_EXEC_TIMEOUT` (30 s, ceiling `AI_EXEC_MAX_TIMEOUT` 120 s, `config/ai.php:499-500`). Timeouts surface to the user as the dedicated "took too long" message (`ChatPresenter.php:156-158`). Slow queries log above `AI_EXEC_SLOW_THRESHOLD` (1000 ms, `config/ai.php:506-507`).

### Native tool-calling telemetry

When the native channel runs, the response metadata carries:

```
metadata.tool_calling = { channel: string, rounds: int, tool_calls: int, degraded: bool }
```

(`src/Services/Actions/ToolLoopResult.php:16`, attached at `src/Services/ResponseGenerator.php:213`; filtered invocations ride alongside as `metadata.invocations`, `:214-217`). Watch `rounds` against the `tool_rounds` cap (2) and `tool_calls` against `max_tool_calls` (5) to tune the caps on evidence.

**Capability-drift warning:** if `AI_ACTION_NATIVE_TOOLS` is on but the resolved `response_generation` provider does not implement `ToolCallingLlmInterface`, the gate degrades to the markdown channel and logs one warning per turn: *"ai.actions.native_tools is enabled but the resolved response_generation provider does not support tool calling…"* (`src/Services/Actions/ActionToolGate.php:96-99,155-166`). Alert on that string — it means a per-environment config drift (e.g. a tool-less per-role model override) silently downgraded the feature everywhere it fires.

### Logging (AiLogger)

All pipeline services log through `Condoedge\Ai\Support\AiLogger` (`src/Support/AiLogger.php`):

- Channel: `ai.log_channel` (`AI_LOG_CHANNEL`), falling back to `logging.default` (`AiLogger.php:20`). Point it at a dedicated channel in prod so AI traffic is separable.
- Every entry carries `ai_trace_id` (a ULID minted per pipeline run) and `ai_service`, prefixed `[AI] {service}: {message}` (`AiLogger.php:23-28,75-83`) — grep one trace ID to follow a single question through the pipeline.
- `debug`-level lines are emitted only when `AI_DEBUG=true` (`AiLogger.php:62-68`) — keep it off in prod; it logs prompts and scores.
- **Separately**, security events use the `ai-security` channel (`AI_SECURITY_LOG_CHANNEL`, `config/ai.php:283`) — the host's `config/logging.php` must define that channel or security logging itself errors.

### Health probe

`GET /api/ai/health` reports neo4j/qdrant/llm status, 200 healthy / 503 unhealthy (`routes/api.php:7-9`; `src/Http/Controllers/HealthController.php:14-49`). **It is unauthenticated and returns raw connection error strings in the JSON** (`HealthController.php:24,33`) — point the load-balancer/uptime checker at it, but block it from the public internet (see §6).

---

## 6. Security checklist before go-live

Work through every line; each has a verification step.

1. **Auth adapter is the one you think it is.** The provider binds `KompoAuthAdapter` only when kompo/auth is detected (`function_exists('globalSecurityBypass') || class_exists(Kompo\Auth\Models\Teams\Permission)`); otherwise `NullAuthAdapter` (`src/AiServiceProvider.php:614-621`). The semantics differ completely:
   - **KompoAuthAdapter** (CondoEdge's case): real team/permission resolution — queries are filtered to the user's permitted teams.
   - **NullAuthAdapter**: returns **zero** teams but reports `checkIfPermissionsExist() = true`, deliberately keeping `AiSecurityService` on the filtering path so the empty team set **denies every secured entity query** — fail closed, not fail open (`src/Auth/NullAuthAdapter.php:23-54`). A standalone host that has not bound its own `AiAuthAdapterInterface` will answer nothing on secured entities; that is correct behavior, not a bug. Do not "fix" it with `AI_ON_PERMISSION_UNEXISTENT=bypass` in prod.
   - Verify: `app(Condoedge\Ai\Contracts\AiAuthAdapterInterface::class)` in tinker resolves to the expected class.
2. **Tenant scoping verified.** `AI_SECURITY_ENABLED=true`, `AI_ON_PERMISSION_UNEXISTENT=deny` (both defaults). The reference test for the team-filter injection is `tests/Unit/Services/QueryExecutorSecurityTest.php` (covers `injectTeamFilter` / primary-entity extraction); run it per-file:
   ```bash
   php -d opcache.enable_cli=0 vendor/bin/phpunit tests/Unit/Services/QueryExecutorSecurityTest.php --no-coverage
   ```
   Then spot-check live: `ai:test-question "..." --debug` and confirm the generated Cypher carries the `$ai_team_ids` predicates (the `TenantScopingRulesSection` prompts for them, `config/ai.php:1335`) — remembering the CLI null-team caveat (`TestQuestionCommand.php:44-47`).
3. **File ACL scope.** Audit finding P0-10 (`docs/audit/99-roadmap.md:40`): the default `accessibleBy` scope is a team-grain `user_id OR team_id` filter, and `ai.file_context.access_scope` is documented but **never read**. Any host needing per-file ACLs — CondoEdge does — must override `scopeAccessibleBy` on its File model. Verify `AI_FILE_MODEL` points at the host's real File model (`config/ai.php:991`) and that the override exists; CondoEdge's file ACL is intentionally flat/team-based, so confirm that matches the product's expectations. Keep `AI_FILE_ACCESS_LOG=true` for the audit trail.
4. **Action authorize gates per persona.** Every action's `authorize()` runs at dispatch, is **re-checked server-side at render time**, and re-runs again on confirm of a deferred token (`src/Services/Actions/ActionDispatcher.php:13-25,117-118,239-240`). Walk the registered actions (`ai.actions` / `ai.entity_actions` / `ai.generic_actions`, `config/ai.php:1183-1316`) and confirm each `authorize()` enforces the intended persona (resident vs board vs staff — the persona question sets in `docs/audit/96-condoedge-recommended-questions.md` are the test script). Mutating actions are never run inline — always deferred behind the signed token (`config/ai.php:1199-1206`).
5. **Approval token TTL.** `AI_ACTION_TOKEN_TTL` default 900 s (`config/ai.php:1223`); expired tokens are rejected on decode and the nonce makes each token single-use (nonce lifetime = TTL + 60 s, `src/Services/Actions/PendingActionToken.php:197-200`). 15 minutes is a sane default; shorten for high-risk actions.
6. **PII flag.** `AI_PROMPT_INCLUDE_USER_EMAIL` stays `false` (default) — the user's email is not shipped to the LLM (`config/ai.php:94`).
7. **Write paths stay closed.** `AI_ALLOW_WRITE_OPS=false`, `AI_EXEC_READ_ONLY=true` (defaults, `config/ai.php:483,503`). Note the read-only gate is keyword/regex-based — physical protection is a least-privilege Neo4j user (the audit's recommendation, `docs/audit/93-dead-code-and-duplication.md` SP-4); give the app a reader role plus only what ingest needs, or separate credentials for ingest vs query.
8. **Network posture.** Qdrant reachable only on the private network (plain-HTTP transport, §1); `/api/ai/health` blocked externally or wrapped by the host (unauthenticated + leaks error detail, `HealthController.php:24,33`); `ai-security` log channel defined.

---

## 7. Rollout of native tool-calling

The feature ships **dark**: `AI_ACTION_NATIVE_TOOLS` defaults to `false` (`config/ai.php:1224`), and with it off the action layer uses the pre-existing markdown link grammar byte-identically (`src/Services/Actions/ActionToolGate.php:92-94` — the markdown branch is hit before any spec is built). Full design: `docs/audit/94b-native-tool-calling-design.md`.

The gate requires three things to go native on a turn, cheapest-first (`ActionToolGate.php:86-108`): kill switch on → resolved `response_generation` provider implements `ToolCallingLlmInterface` → relevance-scoped tool set non-empty. Mutating actions keep the **same** signed-token approval gate on both channels (`config/ai.php:1210-1215`), so flipping the switch changes the transport, not the safety model.

Staged plan (stages 1–3 are operational recommendation built on the source's kill switch + telemetry; only stage 0 and the switch itself are in-repo facts):

1. **Stage 0 — off everywhere (current state).** Markdown channel; zero behavioral delta; the gate never even walks the action registry (`ActionToolGate.php:25-27`).
2. **Stage 1 — staging, with real provider keys.**
   ```bash
   AI_ACTION_NATIVE_TOOLS=true
   ```
   Watch for two weeks of real traffic:
   - `metadata.tool_calling` — `channel` should be `native` on action-relevant turns; `degraded=true` or persistent `markdown` means the capability/spec conditions are failing; `rounds` near the cap (2) or `tool_calls` near the cap (5) means the budgets need evidence-based tuning (`ToolLoopResult.php:16`; `config/ai.php:1225-1226`).
   - The capability-drift warning string in logs (`ActionToolGate.php:158-161`) — it must not fire; if it does, a per-role model override resolved to a tool-less provider.
   - Run the round-trip and parity suites per-file: `tests/Feature/NativeToolActionRoundTripTest.php`, `tests/Unit/Services/Actions/ToolLoopRunnerTest.php`, `tests/Unit/Services/Actions/ToolApprovalParityTest.php`, `tests/Unit/Services/ResponseGeneratorToolChannelTest.php`.
3. **Stage 2 — production host flip.** Same env var on the prod host. Rollback is removing the variable — no code change, no cache rebuild beyond config reload.
4. **Stage 3 — package default flip** (`config/ai.php:1224` default `true`), only after prod telemetry is clean across hosts. No in-repo document commits to criteria or timing for this stage — treat it as a deliberate maintainer decision, not an automatic follow-on.

---

## 8. Known operational limitations

Honest list — these are facts of the current source, not roadmap promises.

1. **No streaming on chat send.** The providers implement `stream()` (`src/Contracts/LlmProviderInterface.php:57`; `AbstractHttpLlmProvider.php:102-105`), but **nothing in `src/` calls it** (verified: zero `->stream(` call sites). Chat send is a blocking request: the UI shows an optimistic JS placeholder, then re-renders the messages panel from the DB (`src/Kompo/Presenters/ChatPresenter.php:33-36`). Operationally: each in-flight question holds a PHP worker for the full pipeline duration (QU round-trip + generation + Neo4j + response LLM call) — size FPM workers and HTTP timeouts accordingly.
2. **Full-suite phpunit segfaults; per-file is canonical.** The `--testsuite` run segfaults; the repo convention is per-file invocation (`docs/audit/EXECUTION-SUMMARY.md:7`):
   ```bash
   php -d opcache.enable_cli=0 vendor/bin/phpunit <file> --no-coverage
   ```
   The composer `test` script (`composer.json:41`) runs the full suite and will crash — do not wire it into CI as-is.
3. **Polymorphic edges are not materialized.** Edges targeting `_polymorphic` never sync, so e.g. Price nodes are edge-isolated and `Price.for_team` cannot work (`docs/audit/96-condoedge-recommended-questions.md:106`). The current workaround is property-join patterns written into the CondoEdge config — `p.priceable_type = 'service' AND p.priceable_id = s.id` guidance in `CondoEdge/config/ai-entities/billing.php:381,432,484` (`docs/audit/94c-polymorphic-edge-sync-design.md:45`). The real fix (sync-layer morph resolution) is designed in `94c` but not implemented.
4. **Modal-hosted pages cannot mount the floating chat.** `AiChatFloating` renders a viewport-fixed FAB (`fixed bottom-10 right-10 z-50`, `src/Kompo/AiChatFloating.php:31`); mounted inside a host modal it escapes the modal box and stacks on the underlying page's own FAB with conflicting `page_context`s. Documented with the CondoEdge Contact case in `docs/audit/96-condoedge-recommended-questions.md` [f11]. Until the package grows a non-floating in-modal affordance, modal-only entities get no page-context chat — their questions work as typed questions in any staff chat.
5. **`ai:setup` is broken at the ingest step** — calls unregistered `ai:ingest-entities` (`SetupCommand.php:101` vs `IngestEntitiesCommand.php:36`); run the bootstrap commands individually (§3.4).
6. **`config:cache` fails** until the closure at `config/ai.php:1333` is replaced (§3.2).
7. **Anthropic embeddings do not exist** — `AI_EMBEDDING_PROVIDER=anthropic` validates the key then always throws on `embed()`/`embedBatch()` (`AnthropicEmbeddingProvider.php:82-100`). OpenAI remains required for embeddings even on Anthropic-LLM deployments.
8. **OCR defaults to French** (`OCR_LANG` default `fra`, `OcrPdfParser.php:244`).
9. **Auto-sync writes inline by default** — every Nodeable save and file upload performs embedding + Neo4j + Qdrant work in the web request until `AI_AUTO_SYNC_QUEUE` / `AI_FILE_QUEUE` are enabled with a worker (§1). FileModel plugins attach automatically once condoedge/utils' facade resolves (`AiServiceProvider.php:696-705`), so file uploads start embedding the moment the package is installed.
10. **`NEO4J_ENABLED` / `QDRANT_ENABLED` disable nothing** — no code reads them (`config/ai.php:339,358`).

---

## Open questions

- **Qdrant minimum version:** the client pins nothing and uses only standard v1 REST endpoints (`QdrantStore.php`); the exact oldest compatible Qdrant release cannot be determined from source.
- **APOC on the deployed CondoEdge Neo4j:** unverified (`docs/audit/96-condoedge-recommended-questions.md:109`); required only by the `Unit.ordered_by_name` scope (`condo.php:2399`) — resolve by installing APOC or removing the scope.
- **Native tool-calling stage 3 (package default flip):** no in-repo document defines criteria or timing; stages 1–3 of §7 are operational recommendation layered on the source's kill switch and telemetry.
- **`ai:setup`'s `ai:ingest-entities` call:** presumed a defect (same class as fixed P0-8); whether the intended fix is renaming the command or the call site is not decided anywhere in source.
