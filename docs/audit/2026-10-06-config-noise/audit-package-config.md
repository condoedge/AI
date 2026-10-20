# Audit: package config (`config/ai.php`, `config/entities.php`) vs. code and vs. the CondoEdge override

Read-only audit, 2026-10-06. No project file edited, no git writes, no phpunit/artisan.
Paths: package = `C:\Users\jkend\Documents\Projects\kompo\ai`, host = `C:\Users\jkend\Documents\Projects\kompo\CondoEdge`, eval = `C:\Users\jkend\Documents\Projects\kompo\ai-eval`.

Method: a tokenizer script flattened both configs into key paths with line ranges (231 leaves / 93 groups / 50 top-level keys in the package; 20 top-level keys in the host). Every `'ai.*'` string literal in `ai/src`, `ai-eval/src`, `ai/routes` was extracted (433 distinct), then each package path was matched against (a) a direct literal read, (b) a parent array read whole and indexed in the consumer (`$this->config['x']`), (c) dynamic concatenation (`'ai.llm.' . $provider`, `"ai.chat.{$key}"`, `'ai.modules.' . class_basename()`), (d) the env name. A path was only called DEAD after (a)-(d) all came back empty.

## 1. Totals

| Fact | Value |
|---|---|
| `ai/config/ai.php` | 1812 lines = **1184 comment-only (65%)**, 165 blank, 463 code |
| `CondoEdge/config/ai.php` | 1496 lines = 643 comment-only (43%), 77 blank, 776 code (mostly the 5 registries) |
| Package leaves | 231 |
| DEAD leaves (package) | **10** |
| NO-OP leaves (package) | **11** |
| LIVE leaves (package) | **210** (12 flagged questionable, section 6) |
| REDUNDANT-IN-HOST (host value == package default) | **0** — the host is already a genuine thin override |
| HOST-ONLY / ORPHAN (host sets, package never reads) | **2 keys** (+2 NO-OP leaves, +45 NO-OP `aliases` lines) |
| Read by code but not declared in package config | 1 (`ai.query_execution.collapse_fan_out`, `src/Services/AiManager.php:1920`) |

Lines removable, by category:

| Category | Where | Lines |
|---|---|---|
| DEAD keys | `ai/config/ai.php` | ~29 |
| NO-OP keys (+ the stale header that describes them) | `ai/config/ai.php` | ~38 |
| Stale / history comments | `ai/config/ai.php` | ~46 |
| Orphan + no-op keys | `CondoEdge/config/ai.php` | ~19 safe, +45 (`aliases`) needs-check |
| Unreferenced lang keys | `resources/lang/en.json` + `fr.json` | 46 (23 keys x 2) |
| Dead / orphan env names | `ai/.env.example` 12, `ai/phpunit.xml` 2, `CondoEdge/.env` 10 | 24 |
| **Evidence-backed total** | | **~200 lines safe, ~250 including needs-check items** |
| Optional: compact the 23 comment blocks >= 14 lines (547 lines, accurate but documentation-in-config) | `ai/config/ai.php` | up to ~430 more (a judgment call, not dead) |

## 2. Ranked candidates (highest confidence + biggest noise first)

### R1. `ai.entities` — DEAD in package, ORPHAN in host. SAFE (package) / NEEDS-CHECK (host)
- Package: `config/ai.php:1442-1450` (`'entities' => require __DIR__ . '/entities.php'`). Host: `CondoEdge/config/ai.php:716-727`.
- Evidence: `rg "ai\.entities['\"\.]"` over `ai/src`, `ai/tests`, `ai-eval`, `CondoEdge/app`, `CondoEdge/config` returns **only 5 doc hits**. Every reader uses the top-level `config('entities')`: `src/AiServiceProvider.php:406`, `src/Domain/Traits/ResolvesEntityConfigKey.php:24`, `src/Domain/Traits/HasNodeableConfig.php:116,471`, `src/Services/ContextRetriever.php:723`, `src/Services/Discovery/EntityLabelIndex.php:77`, `src/Services/Security/SensitiveLabelCoverageGate.php:339`, `src/Services/Security/TeamPathResolver.php:163`, and five console commands.
- Cost of keeping it: the host loads all 16 `config/ai-entities/*.php` files a second time into `ai.entities` on every boot (duplicate memory; doubles that part of a cached config).
- Also dead as a consequence: the `'entities'` entry in `ConfigMerger::REPLACE_WHOLE` (`src/Support/ConfigMerger.php:16`), asserted by `tests/Unit/Support/ConfigMergerTest.php:30-32`.
- Docs are wrong because of it: `config('ai.entities')` at `resources/docs/1.0/advanced/semantic-matching.md:291`, `advanced/scopes.md:380`, `reference/interfaces.md:469,486,518`.
- Check that settles the host line: `ai-eval/src/Services/BuildFingerprint.php:27,37` hashes the whole `config('ai')` into `config_sha1`. Today entity edits change that fingerprint only through `ai.entities`. Removing the host line shifts `config_sha1` once and makes entity edits invisible to it. Decide whether the fingerprint should hash `config('entities')` explicitly first.

### R2. `ai.scope_matching.threshold` — DEAD, and the host tunes it. SAFE
- Package `config/ai.php:1064-1065` (0.70); host `CondoEdge/config/ai.php:536-538` (0.75, with a comment claiming "fewer but more relevant scopes shown to LLM").
- Evidence: no literal read; `ScopeSemanticMatcher` receives the array (`src/AiServiceProvider.php:414`) but only indexes `collection` and `dimension` (`src/Services/ScopeSemanticMatcher.php:38,56,131,132`). Its threshold is a method parameter, supplied from a different key: `config('ai.semantic_matching.scope_threshold', 0.7)` at `src/Services/Retrieval/EntityMetadataBuilder.php:98`. `rg "config\['threshold'\]"` over src = 0.
- Consequence: the host's 0.75 has never applied; the effective value is 0.7. `scope_matching.top_k` IS live (`src/Services/SemanticContextSelector.php:244`), so keep that line.
- Env `AI_SCOPE_MATCHING_THRESHOLD` dies with it (3 hits in `resources/docs/1.0/foundations/configuration.md`).

### R3. `ai.semantic_matching` block — 4 DEAD + 6 NO-OP leaves, 52 lines for 2 live keys
- `config/ai.php:755-806`. Live: only `scope_threshold` (804) and `max_scopes` (805), read at `src/Services/Retrieval/EntityMetadataBuilder.php:98-99`.
- DEAD (SAFE): `collections.entities|scopes|templates` (793-797) — names are hardcoded constants at `src/Services/SemanticIndexer.php:30-32`; `cache_embeddings` (800) — `rg "cache_embeddings"` over src = 0.
- NO-OP: `enabled` (778), `fallback_to_exact` (781), `thresholds.*` x4 (785-790) — the only reader is the `--status` printout at `src/Console/Commands/IndexSemanticCommand.php:185-197`. Nothing branches on them. The config's own comment at 802-803 admits this.
- The header (755-775) promises behaviour that does not exist ("Falls back to exact matching if disabled", template matching).
- Check: remove the keys together with the 13-line status print, otherwise `--status` prints "Disabled" (`$config['enabled'] ?? false`). Env names that die: `AI_SEMANTIC_MATCHING` (set in `CondoEdge/.env:172`), `AI_FALLBACK_EXACT_MATCH`, `AI_SEMANTIC_THRESHOLD_ENTITY|SCOPE|TEMPLATE|LABEL`, `AI_SEMANTIC_CACHE_EMBEDDINGS`; 8 hits in `resources/docs/1.0/advanced/semantic-matching.md`.

### R4. Unreferenced lang keys — 23 keys x 2 files = 46 lines. SAFE
- `resources/lang/en.json` and `fr.json`, same line numbers in both: 4, 7, 111, 115, 117-129, 195, 197-200, 206.
- Keys: `ai.common.save`, `ai.common.download`, `ai.edit.delete-message`, `ai.edit.regenerating`, `ai.file.*` (13: title, document, no-content, preview-unavailable, download, open-new-tab, type-image/pdf/spreadsheet/excel/code/text/markdown), `ai.chat.error-message`, `ai.prompt.user_asked|executed_query|no_results|explain_no_results`, `ai.digest.truncation_footer`.
- Evidence: not in the set of all `ai.x.y` literals in `ai/src`, `ai-eval/src`, `ai/resources/js`, `ai/routes`, `CondoEdge/app`, `CondoEdge/resources/views`; `rg "ai\.file\.|truncation_footer|delete-message|error-message"` = 0; 0 hits in `ai/tests`.
- Excluded on purpose (dynamic keys, LIVE): `ai.themes.*` (`src/Kompo/Modals/ChatSettingsModal.php:124`), `ai.actions.my_profile|my_team` (`src/Services/Actions/BuiltIn/IdentityLinkAction.php:64`), `ai.internal-file` (`src/Kompo/Support/RichDataRenderer.php:135`).
- en/fr are in sync (231 keys each, no one-sided keys).

### R5. Host action `aliases` — NO-OP at runtime, 45 lines. NEEDS-CHECK
- 45 `'aliases' => [...]` lines in `CondoEdge/config/ai.php` (17 in `entity_actions`, 28 in `generic_actions`, first at 761), documented in the package at `config/ai.php:1557-1565,1579,1584,1611,1629,1642,1647` as "aliases for natural language matching".
- Evidence: `ConfigAiAction::aliases()` (`src/Services/Actions/ConfigAiAction.php:60-66`) is called only from `src/Services/Discovery/EntityAutoDiscovery.php:793,824,854`. Of those getters, `getGenericActions` and `getAuthorizedGenericActions` have 0 callers; `getAuthorizedEntityActions` feeds `src/Services/Response/ResponseEntityEnricher.php:81`, which reads only `action_key` and `label` (146, 150, 176). No alias use in `AiActionRegistry`, `ActionAdvertisement`, `ActionAttacher`. `EntityActionAwarenessSection.php:20-21` states "the generator no longer needs the alias catalog".
- Check: `aliases()` is part of `AiActionInterface` (`src/Contracts/AiActionInterface.php:44`) and asserted in about 10 tests under `tests/Unit/Services/Actions`. Decide whether aliases are meant to return (keep) or are finished (drop the host lines and correct the package comment). Dropping host lines alone is behaviour-neutral.

### R6. Single-line DEAD keys in the package. SAFE
| Key | `config/ai.php` | Evidence |
|---|---|---|
| `graph.neo4j.enabled` (`NEO4J_ENABLED`) | 373 | Stores index only uri/username/database/password(_encrypted): `src/GraphStore/BoltNeo4jStore.php:46-48`, `Neo4jStore.php:41-43`, `Neo4jCredentialsTrait.php:37-40`. `rg "\['enabled'\]" src/GraphStore` = 0; `rg "neo4j\.enabled"` = 0 |
| `vector.qdrant.enabled` (`QDRANT_ENABLED`) | 392 | `src/VectorStore/QdrantStore.php:25-28` reads host/port/api_key/timeout only |
| `response_generation.temperature` (`AI_RESPONSE_TEMPERATURE`) | 644 | `src/Services/ResponseGenerator.php:157,872` use `$options['temperature'] ?? 0.3`; no caller passes it from config. Hardcoded value equals the default, so removal is behaviour-neutral |
| `semantic_context.enabled` (`AI_SEMANTIC_CONTEXT_ENABLED`) | 829-830 | `SemanticContextSelector` indexes only `collection`, `dimension` (`:51,69,342,343`); `rg "semantic_context" src \| rg -i enabled` = 0. The comment "falls back to full context if false" is false |

The env names also sit in `ai/.env.example:8,15`, `ai/phpunit.xml` (2 lines) and `CondoEdge/.env:126,133`.

### R7. Host `llm_roles.query_understanding.temperature` / `max_tokens` — NO-OP. SAFE
- `CondoEdge/config/ai.php:365-366` (`0.0`, `500`).
- Evidence: the role provider's only call passes both per call (`src/Services/QueryUnderstandingService.php:117,138`), and per-call options win (`src/LlmProviders/OpenAiLlmProvider.php:49-50`). The budget comes from `ai.understanding.max_tokens` = 1200 (`src/AiServiceProvider.php:444`).
- The `500` is the exact value the package comment at `config/ai.php:965-969` says truncated QU JSON, so it is misleading as well as inert.

### R8. Package NO-OP keys reachable only through uncalled public API. NEEDS-CHECK
| Key | `config/ai.php` | Evidence | Check |
|---|---|---|---|
| `chat.show_typing` (`AI_CHAT_SHOW_TYPING`) | 1393 | `showTyping()` has 0 consumers outside `src/Services/Settings`; `'show_typing'` appears nowhere else in src or `CondoEdge/app` | Confirm no other host calls `cfg('show_typing')`; then drop key + accessor + interface method |
| `query_execution.max_limit` | 550 | Read only in `executePaginated()` (`src/Services/QueryExecutor.php:356`); 0 callers in src, ai-eval, CondoEdge/app | Public method on `QueryExecutorInterface` — grep other hosts |
| `query_execution.enable_explain` | 553 | Read only in `explain()` (`QueryExecutor.php:394`), reached only via `test()` (`:426`), which has 0 callers | Same |
| `query_generation.max_complexity` | 522 | Produces only a warning string (`src/Services/CypherValidator.php:74-75`); never blocks or retries | Decide whether the warning is surfaced anywhere useful |
| `query_generation.templates` | 523-524 | Indexed into `semantic_templates` (`IndexSemanticCommand.php:122-127`), a collection nothing searches (only `semantic_entities` is searched, `src/Services/Retrieval/SchemaProjector.php:351`) | Confirm no host searches that collection |

### R9. Stale / history comments in `config/ai.php` — ~46 lines
- 1500-1502: "native tool-calling keys ... were removed 2026-10-01" (removed-feature history).
- 940: "QU output budget moved to ai.understanding.max_tokens (Task 1.17)"; 965-969: history of the 500 truncation + legacy env fallback.
- 1799-1802: "QueryInfoSection removed from defaults ..."; 1808: "StatisticsSection folded into ..."; 1776 trailing "replaces SchemaSection + RelationshipsSection + DetectedEntitiesSection".
- 646-654: `previous_snippet_chars` incident narrative + `TODO(Phase 7)`; 1219-1223: "(The old 1200 cut ...)"; 1372-1378: "The default is PROVISIONAL ...".
- 808-827: semantic-context header (marketing + the false "falls back" claim).
- 914-917: "See CondoEdge config for examples" — a host name inside a white-label package.
- 12 lines carry internal tracker tags (`Task 5.x`, `Phase 8 batch A/B`, `item C`, `plan B5`): 205, 302, 531, 535, 538, 662, 706, 871, 957, 965, 1673, 1807.

Comments that contradict the code (fix rather than delete):
- 1421-1425: says built-in default theme is `indigo` and default factory is `ConfigChatThemeFactory`; the values are `green` (1434) and `UserChatThemeFactory` (1431).
- 1270-1272: "Set to null to allow all files". With a null scope the code falls through to the user/team fallback filter (`src/Services/Context/FileAccessResolver.php:71-84,186-187`); it does not allow all files. Security-relevant wording.
- 1661-1668: the calculations example uses `calc_due_amount`, which project memory records as a dead column.
- Host `CondoEdge/config/ai.php:1487-1489`: "one digest turn can consume up to ~2x max_sources attempts" contradicts the package's batched single charge (`config/ai.php:569-573`).

## 3. Deleted-feature leftovers

| Feature | Config | Lang | Docs / other |
|---|---|---|---|
| Native tool loop | Only the history note at `config/ai.php:1500-1502`; no keys | none | 8 mentions across `docs/onboarding/00..04` (all correctly say "removed"); `04-production-runbook.md:212,414-416` |
| JudgeService | none | none | none (`rg "JudgeService\|ai\.judge\|AI_JUDGE"` = 0) |
| ai-patterns / PatternLibrary | none | none | **Stale**: `README.md:164,589`; `docs/onboarding/01-architecture-walkthrough.md:204`; `docs/onboarding/04-production-runbook.md:272` |
| Regex follow-up layer | none | none | one code comment, `ai-eval/src/Services/TurnTraceBuilder.php:247` |
| Suggestions path | `chat.show_suggestions` / `max_suggestions` are LIVE for welcome suggestions (`src/Kompo/Support/WelcomeSuggestions.php`) | `ai.settings.follow-up-suggestions*` still referenced (`ChatSettingsModal.php:70`) — the label says "follow-up" for what is now a welcome-screen toggle | — |
| ResponseEntityActionsSection | none | none | one code comment, `src/Services/ResponseSections/AttachedActionsSection.php:12` |
| relationship_weights | none | none | none |
| Reranker | **Not deleted.** `rag.rerank_enabled` (`config/ai.php:737`) is LIVE: `src/AiServiceProvider.php:372`, `src/Services/ContextRetriever.php:774`; `LlmReranker`, `NullReranker`, `RerankerInterface`, `HybridFuser` all exist under `src/Services/Retrieval` | — | — |

`docs/onboarding/04-production-runbook.md:272` deserves a specific mention: it states `config:cache` "currently fails on any host" because `config/ai.php:1333` holds a `PatternLibrarySection` closure. Line 1333 is now a comment and the package config contains no closure at all, so that "verified" caveat is false for the package (the host's own action closures are a separate matter).

## 4. Env variables

- Package config declares 187 env names. 49 appear in no doc (`resources/docs`, `docs/onboarding`, `README.md`, `.env.example`) — mostly the newer knobs: all `AI_TRACE_*`, `AI_RESPONSE_VALIDATE_*`, `AI_RESPONSE_CITATION_ENFORCEMENT`, `AI_RESPONSE_DETERMINISTIC_TABLES`, `AI_SEMANTIC_CONTEXT_PRUNING|MAX_LABELS|CLOSURE_DEPTH|…`, `AI_CALCULATION_MATCHING_*`, `AI_UNDERSTANDING_*`, `AI_EXPORT_*`, `AI_CHUNK_*`, `AI_DOMAIN_SCOPE`, `AI_MASK_IDENTIFYING_FIELDS`.
- 52 env names are documented but declared and read nowhere (not in package config, src, ai-eval, host config). Hits per file: `docs/onboarding/04-production-runbook.md` 23, `resources/docs/1.0/foundations/configuration.md` 16, `configuration/environment.md` 12, `ai/.env.example` 9, `foundations/installing.md` 7, `foundations/infrastructure.md` 4, plus 11 more files. Examples: `AI_CHAT_MODAL_SIZE`, `AI_CHAT_DRAWER_WIDTH`, `AI_FILE_CHUNK_SIZE`, `AI_FILE_CHUNK_OVERLAP`, `AI_QUERY_TIMEOUT`, `AI_MAX_RESULTS`, `AI_CACHE_TTL`, `AI_SIMILARITY_THRESHOLD`, `AI_INCLUDE_SCHEMA`, `AI_AUTO_DISCOVERY_RUNTIME`, `NEO4J_HOST`, `NEO4J_PORT`, `NEO4J_USER`, `ANTHROPIC_EMBEDDING_MODEL`. (`AI_DOCS_ENABLED` / `AI_DOCS_PREFIX` are NOT stale — `config/larecipe.php:3,21` reads them.)
- `ai/.env.example`: lines 8 and 15 are dead (`NEO4J_ENABLED`, `QDRANT_ENABLED`); lines 40-49 ("Query Settings" + "RAG Settings": `AI_MAX_RESULTS`, `AI_QUERY_TIMEOUT`, `AI_CACHE_TTL`, `AI_VECTOR_SEARCH_LIMIT`, `AI_SIMILARITY_THRESHOLD`, `AI_INCLUDE_SCHEMA`, `AI_INCLUDE_EXAMPLES`) have no reader in `ai/src`, `ai/config`, `ai-eval`, `CondoEdge/config`, `CondoEdge/app`.
- `CondoEdge/.env` (names only were inspected, never values): 3 dead (`NEO4J_ENABLED` :126, `QDRANT_ENABLED` :133, `AI_SEMANTIC_MATCHING` :172) + the same 7 orphans. `AI_PANEL_*` (4 names) are also unknown to the package; not traced.
- Legacy fallback still honoured: `AI_QUERY_UNDERSTANDING_MAX_TOKENS` inside `understanding.max_tokens` (`config/ai.php:974`).

## 5. Host override summary (`CondoEdge/config/ai.php`)

- REDUNDANT-IN-HOST: none. Every non-registry host leaf differs from the package default (debug, project.*, identity.*, guardrails, llm_roles, currency_symbol, scope_matching, file_context.physical_paths/corpus_*, health.middleware, export limits, chat.example_questions/page_examples, ui.theme, attached_actions.identity.team.action, digest.max_sources).
- ORPHAN: `entities` (716-727, R1), `scope_matching.threshold` (536-538, R2).
- NO-OP: `llm_roles.query_understanding.temperature|max_tokens` (365-366, R7); 45 `aliases` lines (R5).
- Near-redundant, keep: `llm_roles.*.provider => 'openai'` (363, 370) equals the default provider but pins the role's model family; `scope_matching.top_k => 3` (541) differs from the package default 5.
- All registry attribute names used by the host are consumed by package code (`authorize`, `kind`, `entity`, `label`, `action`, `cypher`, `description`, `formula`, `notes`, `action_key`, `fallback_cypher`, `fallback_label`, `limit`, `enabled`, `entities`, `relationships`) except `aliases`.

## 6. LIVE but questionable (12 of 210)

- `graph.default`, `vector.default`, `embedding.default` — switches with exactly one valid value (`match` throws otherwise, `src/AiServiceProvider.php:129-144`).
- `rag.rerank_enabled`, `rag.hybrid_enabled`, `rag.token_budget` — live, default off, never enabled by the host.
- `semantic_context.dimension` (843) — a third place for the vector size, alongside `embedding.openai.dimensions` and `embedding.vector_size`; the selector ignores the other two (`SemanticContextSelector.php:343`).
- `security.scope_prefix` — only matters when `clarify_scope` is on (default false); the code default for `clarify_scope` is `true` (`src/Services/AiManager.php:2555`) while the config says `false`.
- `chat.input_placeholder` — its default value is the translation key string itself (`config/ai.php:1404`); it works only because Kompo translates placeholders.
- `modules`, `query_parameters` — empty extension points; no host uses them.
- `semantic_matching.scope_threshold|max_scopes` vs `scope_matching.threshold|top_k` — two near-identically named groups; one pair live in each (R2/R3).

## 7. `config/entities.php` (16 lines)

LIVE only as the publish stub (`src/AiServiceProvider.php:674-675`, tag `ai-entities`). Its `require` at `config/ai.php:1450` feeds the dead `ai.entities` key (R1). Its comment references are accurate. Nothing to remove in the file itself.
