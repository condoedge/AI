# Kompo AI — Simplify & Strengthen Proposal (2026-09-29)

Source: 5 parallel read-only reviews (orchestration core, query pipeline, response/files/actions,
ingestion/discovery/config, eval harness + eval history), spot-verified in code by the main session.
Judged against the founding vision: **near-zero-config plug-in, keep the answer-quality internals,
catch the right files and describe them, keep more conversation context, remove the unnecessary.**

## 0. Headline

- Last valid measurement: **iter-11, mean 0.569 (13/24 fail)**; peak iter-9 0.612; iter-12 (clarify, anchor-carry)
  built but **never measured** (OpenAI quota). Run-to-run noise ≈ ±0.02 mean, ±0.40 per turn.
- The package is ~60K lines of src. Roughly **8–10K lines can go with no measured loss**, plus ~2.5K of eval
  harness can move to a dev-only package, plus **~600 of 1,904 host config lines** are verbatim package copies.
- The biggest single zero-config problem is mechanical: `AiServiceProvider:115 mergeConfigFrom` is a
  **shallow** merge, so CondoEdge had to copy the whole package config — 199 of 214 shared leaves are
  byte-identical, and **21 newer package keys silently never reach CondoEdge** (reranker/hybrid toggles,
  truncation_honesty, recall_floor, length_guidance, conversation_limit...).
- The remaining quality failures are **missing mechanisms, not missing prose**: no Cypher schema validation,
  unverified few-shot store, article-blind chunking, QU never sees the conversation, one-deep memory.

## 1. Target shape (install story)

```
composer require kompo/ai
# add Nodeable + HasNodeableConfig to the models the AI may see (already the opt-in list)
php artisan ai:install      # discover → lint → ingest (typed nodes, edges, docs) → index → integrity report
```

Minimal host surface:
- `.env`: LLM + embedding keys, Neo4j + Qdrant URIs.
- One tenant resolver (default `$user->current_team_id`; override via `ai.auth.team_resolver`).
- `ai.project.{name,description}`, `identity.name`.
- Optional domain layer: descriptions/aliases overrides, calculations (as verified examples), actions,
  attention sources, `physical_paths` (default `resources/ai-docs/**`).

Mechanics:
- Deep-merge config (associative arrays recursive, lists replaced whole). Host config = domain data only.
- Discovery writes a **machine-owned cache** (`bootstrap/cache/ai-entities.php`, regenerated on deploy);
  host keeps a thin overrides layer. Today `ai:discover` overwrites `config/entities.php` and the 16
  ai-entities files (~11.5K lines) are hand-maintained drift.
- Auto-derive what the schema already knows: relationship direction, property types, enum values,
  null coverage, homonym (non-unique name) detection → replaces ~4 of 9 host business_rules.
- Humans only for: business-approved calculations, persona/anchoring policy, domain_scope guardrail,
  team_path confirmation (security).

## 2. Phase 0 — verified bugs & free wins (small, no re-ingest)

| # | Fix | Where | Why |
|---|-----|-------|-----|
| 1 | Deep config merge (list-safe) | AiServiceProvider:115 | unlocks every package default in the host; ~20 LOC |
| 2 | `snippet_length` default 1200→6000 | package config/ai.php:1104 | ±2-chunk window can't fit — measured multi-chunk win silently off outside CondoEdge |
| 3 | Citation lookup by stored ref, not position | Response/FileCitationHandler.php (`$citationNumber - 1`) | `[2]` cited before `[1]` links the wrong file |
| 4 | Current/previous-turn `[N]` namespace collision | ResponseConversationContextSection | same number → two files |
| 5 | `MessagesQuery:601` reads `metadata[...]` but value lives in `context_snapshot` | Kompo/MessagesQuery.php | fallback always empty |
| 6 | Wire `ai.chat.max_history_messages` into `buildPromptContext` | AskQuestionUseCase / ConversationContextManager:246 | hardcoded 5 msgs × 300 chars |
| 7 | `safe_stop` must not read as "no data" | QueryExecutor:115-123, 877 | chat path never reads safe_stop → silent wrong answer |
| 8 | Stop sampling example nodes without tenant filter into the LLM prompt | ContextRetriever:587 (ExampleEntitiesSection) | cross-tenant PII sent to provider |
| 9 | `/api/ai/health` unauthenticated + raw exception text | HealthController:24,33 | exposure |
| 10 | Few-shot store: only store after success with rows>0; similarity threshold ~0.8 (now 0.0) | AiManager:390-405, ContextRetriever:49 | root of the poisoning incident |
| 11 | TemplateMatcher throws on unknown label outside retry | QueryGenerator:128-137 / TemplateMatcher:144 | "How many residents?" hard-fails |
| 12 | Reranker writes no score; order is re-sorted away | FileContextProvider:284-288, AiManager:822 | reranker can't work even when on |
| 13 | Host file priority (+1.0 team, +0.5 law) is the final sort key | CondoEdgeFileContextEnricher / FileContextProvider | 0.41 team file beats 0.8 CCQ; CCQ beats how-to docs on app questions → use as tiebreak |
| 14 | `DB::transaction` held open for whole LLM turn | AskQuestionUseCase:51,83 | holds locks for seconds; never rolls back anyway |
| 15 | ai:ingest prints every error as "Unknown error" | IngestEntitiesCommand:271 | errors are strings |
| 16 | Unconfigured Nodeable models (64/137) log full-trace errors on every save | auto_sync | skip/exclude |
| 17 | QU `max_tokens` 500 for ~15 fields; truncation → silently empty | QueryUnderstandingService:50,67-74 | |

## 3. REMOVE (ranked safest first; all verified zero/test-only callers or never measured)

**Zero-risk dead code / config**
- `config/ai-patterns.php` (460) + `query_patterns` block (~150) + PatternLibrary + PatternLibrarySection —
  **empty at runtime in CondoEdge** (host `__DIR__` has no ai-patterns.php); every measured eval ran without it.
- ~71 dead config keys (~20% of package leaves): `access_control.*`, `auto_discovery.*` sub-keys, host `discovery`
  block, `rag.{vector_search_limit,similarity_threshold,include_*}`, `file_processing.{chunk_size,...}` (real value is a
  constant), `query_generation.{default_limit,max_limit,template_confidence_threshold,timeout,cache_ttl}`,
  `response_generation.{temperature,include_insights,include_visualizations,summarize_threshold}`, `semantic_matching.*`,
  ~24 `chat.*` (incl. name drift welcome.title vs welcome_title). Also define the keys read-but-undefined
  (`schema_cache_ttl`, `export.redact_rows`, `security.enforce_scopeable_subset`...).
- Package `config/entities.php` (525 lines of generic examples) → empty stub.
- ~600 host config lines identical to package defaults (after deep merge).
- Dead chat services: SendMessageService, RegenerateMessageService, most of AiChatService (~440).
- Tutorial (AiTutorialComposer + plan/step, ~455), AnthropicEmbeddingProvider placeholder (141),
  unused HasInternalModules extension hooks.
- Unused FileSearchService methods: searchByMetadata, hybridSearch, getRelatedFiles, getFilesByUser/Team (~240).
- Snapshot fields production never writes (last_insights/visualizations/execution_stats/available_actions/
  detected_template) + their prompt branches + 7 AiConversation getters.
- `extractInsights` / `suggestVisualizations` (ResponseGenerator:1111-1282, ~180) — averages ID columns and feeds noise
  into the next turn's prompt.
- `semantic_scopes` / `semantic_templates` collections (written, never read) + IndexScopesCommand merged into one
  `ai:index` (today ai:setup embeds scopes twice) (~300).
- DataIngestionService batch path (~250, buggy label-less `MATCH (n) WHERE n.id IN`), RelatedModelSyncObserver (173,
  sync_triggers empty everywhere), 3 near-identical jobs → 1 `SyncEntityJob($op)`.
- NodeableConfig + model-property config path (437) — no CondoEdge model uses it.

**Low risk — hardcoded word lists the QU LLM already covers** (violates the no-word-lists rule)
- ReferenceResolver (161; `resolve()` is verbatim by contract, outputs unconsumed), EntityExtractor regex path
  (substring "unit" ⊂ "community"; forces a focused_entity into selection), TemplateMatcher (190, English-only),
  `keywordBasedSelection`, `calculateConfidence`, FileContextProvider:935 EN-only follow-up list,
  ExportResponseComposer `detectExportIntent`/`questionLanguage` (~80). Also removes a live Neo4j schema read per turn.

**Medium risk — needs one measured run**
- Three hand-copied pipelines (AiManager::answerQuestion ↔ PipelineEvalDriver ↔ TestQuestionCommand; runTurn ↔
  ConversationEvalRunner) — **already drifted**. Replace with a `PipelineObserver` hook on production; eval drives
  AskQuestionUseCase. −700–900 LOC, and 6 AiManager methods stop being public-for-eval.
- Schema rendered 3–4× (SchemaSection + RelationshipsSection 243 + DetectedEntitiesSection + ExampleEntitiesSection)
  → one generated schema card per selected label (est. −40–60% schema tokens; query-gen prompts were ~17K).
- Query-side FileContextSection (5×6000 chars of doc text + citation rules sent to the Cypher writer; also hard-codes
  "building rules override general laws" — legally wrong for mandatory CCQ) → filenames only. Also unblocks running
  file search in parallel with query generation.
- StatisticsSection (duplicates ResultsDataSection), duplicated UNION rules, date rule contradicting host calcs.
- Three guards with three stacked full-prompt retries + "re-assert" step (ResponseGenerator:212-307) → one
  validation pass, ≤1 combined retry, then deterministic patches (~330→150; keep the truncation + self-reference checks,
  both measured load-bearing).
- Three table renderers (RenderTableAction, ExportResponseComposer::markdownTable, ResultSetExporter::toMatrix) → one;
  Excel export stops round-tripping rows→markdown→cells.
- Eval harness (~2.5K + 12 test files) → dev-only `kompo/ai-eval`; AttributionService 471→~100 (soft blame lands on
  "prompt" 12/13 rows and scored a void run as normal); drop flat mode + QuestionSetGenerator (344).

**Owner decision (never measured, off by default)**
- Native tool loop (ToolLoopRunner, ActionToolGate, ActionToolSpec, GraphQueryTool, ... ~1,100) — off in package + host.
- Digest (~730) — measured 0.40–0.65, surfaced junk, self-rate-limits at 9 queries. Freeze as opt-in or remove.
- Reranker + hybrid/RRF (~540) — never measured; A/B once (cheap via retrieval-only eval) or delete.

## 4. KEEP (measured load-bearing — must survive any simplification)

L1 response-prompt rebuild (identity block, length guidance) · few-shot purge + anti-copy · canonical calculation
Cypher + toFloat · `$ai_team_ids` binding + fail-closed coverage gate · scoping injector incl. OPTIONAL MATCH fix
(until §5.C lands) · generation retry loop · truncation-honesty guard + `executeWithTrueTotal` companion count
(best round of the campaign) · self-reference guard · G6 homonym disambiguation column · augmentation anchor ·
preserve-on-empty snapshot · export via re-executing the recorded Cypher · QU fan-out with register expansion +
cross-lingual angles · multi-chunk windows · skip file search for data_query (23s→13s) · recall floor ·
FileAccessResolver · spotlighting · entity trim to 73 · single QU call · ChatPresenter + UseCases ·
CircuitBreaker/RetryPolicy · MemoizedEmbeddingProvider · PDF/OCR/Docx extractors (add tests).

## 5. ADD (ranked by expected impact on final answers)

### A. Right files + file info (vision #3)
1. **Structure-aware chunking** — split on headings and article markers (`**1069.**`), one article per chunk;
   store `{heading_path, article_no, page}` in metadata (today `metadata: []`, FileProcessor:333) and prefix the heading
   into embedded text. Today CCQ is cut into blind 1500-char slices — the likely cause of art. 1069 never retrieved in
   8 runs and wrong-section answers. ~120 LOC + re-ingest docs.
2. **Lexical + exact match for file chunks** — BM25 (HybridFuser exists, only wired for entities) + exact article-number
   match, fused by RRF. ~60 LOC.
3. **ACL as a Qdrant pre-filter** (accessible file_ids `MatchAny`) instead of over-fetch-then-drop. ~30 LOC.
4. **File cards** — at ingest, one cheap LLM call per file: `{title, summary, outline, doc type, language}`, plus the
   FileModel facts already loaded then dropped (uploader, team, dates, mime, size). Render
   `[N] name — uploaded by X on D — type — summary — §heading`; group multi-chunk hits as `[N.a]/[N.b]`. Answers
   "who uploaded X / what's in it", and **replaces hand-written `corpus_register` and the host enricher tiers**.
5. **Sticky file scope** — on QU `followUpReference`, add a retrieval angle filtered to previously cited file_ids and
   expand around the chunk indices cited last time.

### B. More conversation context (vision #4)
6. **Turn ledger** — per assistant message store `{subject, entity_types, cypher, row_count, ≤10 sample rows,
   cited files+chunks, export_format}`. Prompt: newest turn full, older N (config, default 6) compact, rolling summary
   beyond. Snapshot becomes a view of the ledger (no double storage). QU returns `refers_to_turn` → anchors and
   exports pick the right turn instead of always the last ("the list from two questions ago").
7. **Give QU the conversation** — `analyze()` receives only the question yet is asked to judge follow-ups and
   clarification "from the conversation context" (QueryUnderstandingService:142,153-156,262). Pass the compact ledger.
   Directly targets the unsolved follow-up/anchor-loss family and lets the regex layer go.
8. Regenerate must pass `page_context` (dropped today, AskQuestionUseCase:69-90).

### C. Correct queries
9. **Schema pre-validation + repair loop (G4, never built)** — label exists, `(from)-[TYPE]->(to)` exists in that
   direction, property exists on label; optionally `EXPLAIN` (QueryExecutor::explain exists, never called). Failures feed
   the existing retry. Targets invented `:CUSTOMER` edges / nonexistent `a.balance`. ~250 LOC.
10. **Repair on execution error** — regenerate once with the Neo4j error text instead of a generic error answer.
11. **Structured generation output** `{needs_query, cypher}` via `chatJson` — replaces exact-match
    `'NO QUERY REQUIRED'` (QueryGenerator:216).
12. **Verified few-shot library** seeded from host `calculations` + eval-passed pairs (formulas carried results; prose didn't).
13. **Typed ingestion** — coerce DECIMAL/int/date at ingest (`PropertyDiscoverer::discoverWithTypes` and
    `SchemaInspector::getColumnTypes` exist, never called). Kills the toFloat shims and the "dates are strings" prompt
    rule. ~60 LOC + re-ingest.
14. **Edge refresh on update** — today edges are only MERGEd, never removed → reassigned units keep old edges forever.
15. **Tenant scoping v2** (security-critical, after 9): run the acceptance gate inside the generation retry loop with
    per-binding errors; write `ai_team_id` onto tenant-sensitive nodes at ingest so every rule is a plain WHERE; only
    then retire the AST injector (424 LOC) — keep the fail-closed coverage gate.

### D. Composition
16. **Deterministic tables** — code renders the table from all rows; LLM writes only the narrative. Removes the 10-row
    sample ceiling (roster "90 of 100 dropped" family).
17. **Structured composition** `{answer_md, cited:[{ref,chunk}], rows_used}` + code checks: each cited `[N]` exists AND
    the quoted article number/figure appears in that chunk; every number in the answer exists in rows/snippets/true_total
    (StatedNumber already parses). Today citation enforcement only checks marker presence and `[2024]` matches.
18. **Entity-link lint** — reject non-numeric IDs; stop teaching literal `entity://X/ID/…` (ResponseEntityActionsSection:107,147).
19. **Stream the final ResponseGenerator call** (`LlmProviderInterface::stream()` exists, no caller) — large perceived-latency win.
20. **Parallel file-angle searches** + one `embedBatch` call (currently sequential fan-out before generation).
21. **Cache `getSchema()`** until next ingest (4 Neo4j queries incl. full-graph aggregation, run twice per turn).

### E. Operability & measurement
22. **Production turn trace** in `ai_messages.metadata.trace` (same observer as the eval) — live failures are diagnosable.
23. **Token usage + cost per turn** — providers discard `usage` everywhere today; add a per-turn budget guard.
24. **`ai:doctor`** — MySQL vs Neo4j counts, dangling FKs, orphan nodes, index config-hash staleness; **real config lint**
    in CI (columns exist, target labels exist, team_path resolves, known specification_types — host uses 13 variants).
25. Eval: quota preflight inside the command; build fingerprint (git SHA + config/prompt hash) in every report;
    `--samples=N`; gates `not_error_response` (would have voided iter-12a automatically), `no_placeholder_links`,
    `answer_language_matches`, `expected_branch`, `cited_articles_in_chunk`; retrieval-only eval (recall@k/MRR on gold
    question→article pairs — the cheap way to A/B reranker/hybrid/chunking); record/replay LLM provider for CI;
    run `suite-long` before touching memory settings.

## 6. Sequencing (one lever per measured run — existing discipline)

0. **Unblock measurement**: top up OpenAI; run iter-12 (built, unmeasured) as the new baseline. Add `not_error_response`
   + fingerprint first so a void run can't be scored again.
1. Phase 0 table (§2) — mostly bugs; one run to confirm no regressions.
2. Zero-risk removals (§3 first block) + deep merge + host config shrink. Tests + one run.
3. Files: chunking + metadata + lexical/RRF + ACL pre-filter (re-ingest docs) → retrieval-only eval + suite.
4. Query correctness: schema validation + repair + structured generation + verified few-shots.
5. Conversation: turn ledger + QU sees conversation → remove regex layer → run `suite-long`.
6. Composition: single validation pass + deterministic tables + structured composition + link lint + streaming.
7. File cards + sticky file scope.
8. Typed ingestion + edge refresh + `ai_team_id` (one full re-ingest, needs sign-off) → scoping v2 → retire injector.
9. Install story: discovery cache + `ai:install` + `ai:doctor`; move eval harness to dev package.

## 7. Decisions needed from the owner

1. Full re-ingest (typed properties, edge refresh, `ai_team_id`, article chunking) — pending since June (W7).
2. Native tool loop: delete or keep dormant?
3. Digest: remove, freeze as opt-in, or invest?
4. Reranker/hybrid: A/B via retrieval-only eval, or delete?
5. Move eval harness to a dev-only package?
