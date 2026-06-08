# Execution Roadmap

This is the sequenced execution plan for the follow-up implementation workflow, synthesized from the 17-lane audit pool and filtered through the adversarial verification verdicts.

**Inclusion rule.** Only findings stamped `CONFIRMED` or `NEEDS_NUANCE` appear here. Anything refuted (`FALSE_POSITIVE`) is excluded and listed at the bottom so we don't accidentally re-litigate it. Where verification re-scoped a finding (`NEEDS_NUANCE`), this roadmap implements the *verified* version and flags the part of the original claim that was overstated, so we don't ship the inflated fix.

**Scope guardrail.** Edits are confined to `kompo/ai` and its CondoEdge consumer. Sibling packages (`../Sisc`, `condoedge/utils`, `kompo/auth`) are **read-only**. Findings that surface coupling to those packages (e.g. L12-1's `kompo/auth` dependency) must be solved by adding fallbacks *inside* `kompo/ai`, never by editing the sibling.

---

## Principles

These hold for every item below. If a proposed change violates one, the change is wrong.

- **Single path.** One canonical way to do each thing. The chat turn enters through `Presenter -> UseCase -> ChatTurnResult`. Retrieval returns one shape (`PipelineContext`). File-context fan-out lives in one service. Query (de)parameterization, error mapping, follow-up detection, entity-id resolution, and the `entity://` link grammar each have exactly one home. Where the audit found 2-3 copies of a behavior, collapse to one — do not "harmonize" the copies.
- **No dead code.** A method, class, config key, command, or interface with no production caller is deleted or wired — never kept "for future use" or alive only by its own test. A tested-but-unreachable branch is dead code with a green checkmark. The facade `@method` block and public docs are matched to the real surface, not hand-synced.
- **Right-sized abstraction.** Interfaces exist where something is genuinely swapped or mocked, not speculatively. Delete the 3-palette/2-factory theming tower and `EntityAccessCheckerInterface` (zero impls). Add interfaces only where a downstream host must inject (`QueryUnderstanding`, `ContextSelector`, `SemanticIndexer`). Narrow `mixed`/loose-array boundaries to typed DTOs at extension points.
- **Model-agnostic.** No hardcoded English word-lists for semantic decisions (possessive regex, follow-up patterns, stopword lists, entity/query-type regex). No tenant domain baked into prompts or code (condo/copropriété/French examples, `bnc_`/`payable_` column prefixes, `App\Models\User`). Identity, role-notes, domain hints, and aliases come from config or the LLM, not string literals.
- **Fail-closed.** Security defaults deny. The default auth adapter must not fatal when `kompo/auth` is absent — it must deny. `can_download` defaults false. Tenant scoping is proven off a known entity, parenthesized, and applied to every node, not inferred from a regex over an LLM-authored string.
- **Token-lean prompts.** Every section earns its tokens. No duplicated OUTPUT-FORMAT rules, no always-on instruction blocks whose referent data is absent, no user-email PII shipped to third-party LLMs by default, no `JSON_PRETTY_PRINT` doubling result payloads, no full edge-vocabulary re-attached after relevance filtering.
- **No version-referencing comments.** Strip migration-narrative docblocks and archaeology: `mirrors AiManager`, `matches AiChatService.php:90-94`, `fixing the old ordering bug`, `X was removed`, `NOTE FOR THE MAIN SESSION`, `B2/B3/FILE-2` audit tags, `// Success!`. Comments describe current behavior and intent, never the diff or deleted code.

---

## P0 — correctness / security / ships-broken

`CONFIRMED` or re-scoped `NEEDS_NUANCE` only. Each is broken on a live path, an exploitable/latent security gap, or a public-API method that detonates on its documented signature.

| item | layer | files | why | acceptance check |
|---|---|---|---|---|
| **P0-1. `AI::syncRelationships()` missing method** | L0 (L0-1) | `src/Services/AiManager.php`, `src/Observers/RelatedModelSyncObserver.php:156` | Observer (wired when `sync_triggers` set) calls `AI::syncRelationships([$parent])`; `AiManager` has no such method and no `__call`. Related-model sync is non-functional. **Verified nuance:** the observer wraps the call in try/catch+log, so it fails *silently* (stale graph), not a host crash. Still ships-broken. | Add `syncRelationships()` pass-through on `AiManager` -> `$this->ingestion`, or resolve `DataIngestionServiceInterface` directly in the observer. New test drives the observer sync path and asserts relationships are linked (existing test only checks config loading). |
| **P0-2. Tenant filter gated by a regex that misses unlabeled primaries** | L8 (L8-1) | `src/Services/QueryExecutor.php:633-660,733-750` | `applySecurityFilter` only injects the team filter when `extractPrimaryEntity()`'s regex finds an inline `(alias:Label)`. A label-free primary (`MATCH (n {id:$x}) RETURN n`) yields `entity=null` and runs **unfiltered** under security-enabled/deny — cross-tenant read. **Verified nuance:** narrower than claimed — the bypass needs a query with *no* `:Label` token at all; alias-without-inline-label is still caught by the fallback regex. Still P0. | Scope off the known entity threaded via `PipelineContext`/QU, not a regex over generated Cypher. If no entity can be proven, treat as sensitive and fail closed (deny). Test: label-free query under deny throws/returns empty, never unfiltered rows. |
| **P0-3. Team filter AND-appended onto an OR head WHERE without parens** | L8 (L8-14) | `src/Services/QueryExecutor.php:850-862` | `WHERE a OR b` + ` AND team` parses as `a OR (b AND team)` — the team filter never constrains the `a` branch. Tenant leak on OR predicates. | Wrap the existing head: `WHERE (<existing>) AND <team>`. Add OR-predicate tests proving both branches are constrained. Land with P0-2 (same scoping pass). |
| **P0-4. Default auth adapter fatals without `kompo/auth`** | L12 (L12-1) | `src/Auth/KompoAuthAdapter.php:71,84`, `src/AiServiceProvider.php:711-713` | The only `AiAuthAdapterInterface` binding is `new KompoAuthAdapter()`. `isEnabled()` calls global `globalSecurityBypass()` (line 71) and `checkIfPermissionsExist()` calls `Permission::findByKey()` (line 84); neither symbol exists here and `kompo/auth` is not in `composer.json`. `isEnabled()` runs at the front of every secured query, so standalone installs fatal on first check. **Verified nuance:** line 101 is already `class_exists`-guarded; fix 71/84 only. | Ship a fail-closed `NullAuthAdapter` (deny) as the default binding; bind `KompoAuthAdapter` only when `kompo/auth` is detected (`function_exists`/`class_exists` with deny fallback). Add a no-kompo-auth boot test (the existing `KompoAuthAdapterTest` itself fatals standalone — confirms the gap). |
| **P0-5. `searchSimilar(array)` TypeError on documented facade** | L0/L4 (L4-5) | `src/Services/AiManager.php:186` | `AiManager::searchSimilar(string,array)` forwards the array to `ContextRetriever::searchSimilar(string,string $collection,...)`; under `strict_types` a hard TypeError. Docs call `AI::searchSimilar($q, ['collection'=>...,'limit'=>...])`. **Verified nuance:** dead internally (no `src/` caller) — a latent public-contract P0 that detonates on the documented signature, not a self-crash. | Unpack `$options['collection'] ?? 'questions'` / `$options['limit'] ?? 5` in `AiManager`; add a test on the documented signature; sync the `@method` block. |
| **P0-6. `getExampleEntities(array)` -> string-typed contract TypeError** | L0/L4 (L0-2, L4-6 — same bug) | `src/Services/AiManager.php:206-208` | `AiManager::getExampleEntities(array $labels)` calls `ContextRetriever::getExampleEntities(string $label)`; array→string under `strict_types` TypeErrors before the impl runs `empty()`/`isValidLabel()`. Same latent-public-contract profile as P0-5. | Loop labels calling the singular string API (or delete — no internal caller). Fix with P0-5 in one pass; align `@method` cardinality. |
| **P0-7. `link_hover` magic accessor silently returns null** | L2 (L2-3) | `src/Kompo/Modals/ChatSettingsModal.php:174`, `src/Kompo/Traits/HasMethodsAsProperties.php` | `__get` maps `link_hover`->`linkHover()`; no component/trait defines it (only the theme objects do), so `method_exists` is false and magic returns null — reset link renders with no hover class. **Verified nuance:** impact is cosmetic (one link); true severity P2/P3. Kept here only because it *proves the magic layer fails silently*, the trigger for deleting `HasMethodsAsProperties` (P1-F3 / P2-A). | Call `$this->theme()->linkHover()` directly. Folded into the `HasMethodsAsProperties` deletion. |
| **P0-8. `ai:setup` calls a non-existent command** | L14 (L14-2) | `src/Console/Commands/SetupCommand.php:95`, `src/Console/Commands/ValidateConfigCommand.php:22` | `SetupCommand` calls `ai:validate-config`; the real signature is `ai:config:validate`. `$this->call(...)` throws `CommandNotFoundException`, so `ai:setup` crashes before any ingestion. Docs repeat the wrong name. | Rename the signature to `ai:validate-config` (matches docs/intent) **or** fix the call site + the two doc pages. Add an `ai:setup --dry-run` smoke test. |
| **P0-9. Cross-collection score normalization boosts weak docs past the relevance gate** | L5 (L5-5) | `src/Services/FileSearchService.php:219-238`, `src/Services/Context/FileContextProvider.php:181` | Per-collection min-max normalization forces a single/all-equal collection to `1.0`; the provider then threshold-cuts on that normalized score, so a weak physical doc always clears `min_score=0.4` while strong DB matches squash to 0. Multi-collection path is on by default (`documentation_chunks`). | Stop surfacing normalized scores as relevance. Threshold on **raw** scores; if merging collections, fuse by RRF. Test: a weak lone-collection match does not clear the gate; a strong cross-collection match is not squashed. |
| **P0-10. Default `accessibleBy` scope is a team-grain OR-filter shipped as the secure default** | L5 (L5-1, L5-2) | `src/Models/Plugins/FileAccessScopePlugin.php:26-51`, `src/Services/Context/FileAccessResolver.php:71` | With `security_enabled=true` by default, any host that does not override `scopeAccessibleBy` authorizes files via `user_id OR team_id` with no per-file/visibility policy — every team file the AI retrieves is exposed to every team member. `access_scope` config is documented but never read (`accessibleBy` is hardcoded). | Do not ship a team-grain authz default masquerading as secure. Fail closed when no host scope is configured, or honor `config('ai.file_context.access_scope')` dynamically and document the built-in scope as team-grain/unsafe. Test: unconfigured host returns nothing (or documented-unsafe), and a set `access_scope` is actually invoked. |
| **P0-11. Save events double-dispatch ingestion work** | L6 (L6-4) | `src/Models/Plugins/FileProcessingPlugin.php:36-58` | `created` + `saved` both dispatch `handleFileCreated`, and `saved` re-runs create/update handlers, so one save processes the file twice (duplicate OCR/embedding/Neo4j ingest + create/process race). Deterministic `getVectorId` dedups the final vector, so it's wasted compute + race, not corruption. | Drive everything from the branching `saved` listener (already covers `wasRecentlyCreated` and `isDirty('path')`); delete the `created`/`updated` listeners. Test: one create -> exactly one processing dispatch. |
| **P0-12. `executeCount` builds malformed Cypher; breaks pagination** | L8 (L8-2) | `src/Services/QueryExecutor.php:213-218` | Fallback `WITH * MATCH {query} RETURN count(*)` is invalid for `OPTIONAL MATCH`/`CALL`/leading-`WITH`. `executePaginated` always calls `executeCount` first, so paginating any non-simple query throws. **Verified nuance:** reachable only via the public `AI::executePaginated()` facade today, not the internal RAG path. | Wrap the original as `CALL { <subquery> } RETURN count(*)` (retain read-only enforcement) or refuse to count non-simple shapes. Test `OPTIONAL`/`CALL`/`WITH` counts. |
| **P0-13. Approval-card i18n keys don't exist** | L11 (L11-5) | `src/Kompo/Actions/ApprovalCard.php:54,67` | `__('ai.actions.confirm') ?: 'Confirm'` relies on the key being undefined, but JSON translation of a missing key returns the key string itself (truthy), so `?:` never fires and the button renders the literal `ai.actions.confirm`. `MessagesQuery.php:629` `ai.actions.failed` shares the defect (guarded by a summary fallback). | Add `ai.actions.*` keys to `resources/lang/en.json`/`fr.json` (near-miss: `ai.common.cancel` exists but code calls `ai.actions.cancel`). Don't depend on missing-key fall-through. Test rendered label text. |

> **Deliberately not P0 (verified down from the original):** query *timeout* enforcement (L8-3) and read-only-as-regex (L8-4) are real defects but were verified as architectural hardening, not ships-broken; they live in P1-G / P2-B respectively. The standalone `RateLimiter` race (L12-3) is P1-G2 (resilience), not P0, because it degrades a guard rather than breaking a live correctness path.

---

## P1 — architecture / quality / UX

Giant-file decompositions, single-path collapses, action-system completion, page-awareness + dedicated screen, prompt token diet, reranker/hybrid wiring, and resilience gaps. This is the "10/10" work.

### P1-A. Giant-file decompositions (split, don't rewrite)

| item | layer | files | split into |
|---|---|---|---|
| **P1-A1. `AiManager` (1118 lines)** | L0 | `src/Services/AiManager.php` | A thin `AiOrchestrator` (`answerQuestion` + the genuinely-used ingest/context/response/file methods) + **delete** the ~15 dead service pass-throughs (L0-4) and the 2 dead pipeline entries `ask()`/`askQuestion()` (L0-3); move query (de)parameterization into an identity-aware `QueryParameterizer` (P1-B4). |
| **P1-A2. `ContextRetriever` (1686 lines, ~13 responsibilities)** | L4 | `src/Services/ContextRetriever.php` | `ContextRetriever` (thin orchestrator) + `SimilarQuerySearcher` + `SchemaProjector` + `EntityMetadataBuilder` + `ContextQualityAssessor` + `TokenBudgeter`. **Delete** the dead budget/confidence/minimal/stats cluster (L4-7); standardize on the single `PipelineContext` return. |
| **P1-A3. `QueryExecutor` (863) + `QueryGenerator` (670)** | L8 | `src/Services/QueryExecutor.php`, `src/Services/QueryGenerator.php` | Executor -> `QueryExecutor` (run + read-only/rate-limit guards) + `SecurityFilterInjector` (entity/alias extraction + team splice — the home of P0-2/P0-3, ideally AST-based) + `ResultFormatter`. Generator -> `QueryGenerator` (LLM retry/validate loop) + `CypherValidator` + (only if templates survive L8-12) `TemplateMatcher`. |
| **P1-A4. `MessagesQuery` (736)** | L2 | `src/Kompo/MessagesQuery.php` | `MessagesQuery` (list/render) + `MessageBubbleRenderer` + `RichDataRenderer` + `ConversationActions` (pin/feedback/archive/delete routed through the presenter — P1-B5). |
| **P1-A5. `DataIngestionService` (1197)** | L16 | `src/Services/DataIngestionService.php` | `DataIngestionService` (orchestration) + `GraphPropertyMapper` (extract/sanitize) + `VectorPayloadBuilder` (embed-text + payload + collection ensure) + `RelationshipSyncer`. The two shared collaborators close the batch-vs-single divergence (P1-B7). |
| **P1-A6. `ResponseGenerator` (648)** | L9 | `src/Services/ResponseGenerator.php` | Keep role-split `generate()`/`buildPrompts()`; extract the ~180-LOC analytics into opt-in `InsightExtractor` + `VisualizationSuggester`; **delete** dead `generateEmptyResponse` (or wire it — P1-D3), `buildPrompt`, `summarize` (also remove from interface); trim the stale docblock. |
| **P1-A7. `OcrPdfParser` (625)** | L6 | `src/Services/Extractors/OcrPdfParser.php` | `OcrPdfParser` (orchestration) + `PdfRasterizer` (Ghostscript/Imagick) + `TesseractRunner` (OCR + TSV confidence) + `OcrTextCleaner`. |

`HasInternalModules` (488), `SchemaInspector` (~703), and `RelationshipDiscoverer` (~707) are **not** split — they are cohesive; trim docblocks only.

### P1-B. Single-path collapses (kill the second/third copy)

| item | layer | files | action |
|---|---|---|---|
| **P1-B1. One file-context fan-out service** | L0/L15 (L0-8, L15-8) | `AiManager.php:795-840`, `Console/Commands/TestQuestionCommand.php:138-182`, `Services/Eval/PipelineEvalDriver.php:208-248` | Extract one `FileContextRetriever` owning the multi-query build/loop/merge/usort; inject into the manager, the test command, and the eval driver so eval/test measure production. Strip the `mirrors AiManager` comment. |
| **P1-B2. One chat-turn path; delete dead wrappers** | L1 (L1-1, L1-2, L1-7) | `Services/Chat/SendMessageService.php`, `RegenerateMessageService.php`, `AiChatService.php:89-154` | **Delete** `SendMessageService` (unbound, test-only) and `RegenerateMessageService` (its `regenerateFromAssistantMessage` walk-back already runs live in `MessagesQuery::regenerate`). Migrate `TestConversationCommand` off `AiChatService::askWithConversation`, then delete the legacy methods + the dead `regenerateResponse` path. Remove the provider singleton + deferred-list entries. |
| **P1-B3. One locale-safe error mapper** | L1 (L1-13) | `Kompo/Presenters/ChatPresenter.php`, `UseCases/AskQuestionUseCase.php:220-237`, `AiChatService.php:200-218` | Make `ChatPresenter::friendlyError` (by exception *type*) the single source. `runTurn` produces a translation **key**, not English prose. Delete both substring mappers (one live + locale-broken, one dead). |
| **P1-B4. One query (de)parameterization identity** | L0 (L0-7) | `AiManager.php:982-1012`, `PromptSections/SimilarQueriesSection.php:55` | **Re-scoped to P2-severity architecture (verified):** not a cross-user leak (`auth()->login()` is set in CLI; no other user's row data is injected). Thread the resolved actor from `$options['user']` into (de)parameterize, eliminating the static `auth()->id()`/`currentTeamId()` fallback, and fix the genuine CLI `currentTeamId()===null` team-axis gap. Make the methods instance methods (lands with the `QueryParameterizer` extraction in P1-A1). |
| **P1-B5. Route all conversation mutations through the presenter** | L2 (L2-10) | `Kompo/MessagesQuery.php:516-534` | Pin/feedback/archive/delete mutate the DB inline with `where('user_id', auth()->id())`. Route through `ChatPresenter`/use cases for consistent authorization and testability (lands with P1-A4 `ConversationActions`). |
| **P1-B6. Consolidate the 3 indexers / dead collections** | L4 (L4-2, L4-3, L4-4) | `Services/SemanticIndexer.php`, `SemanticContextSelector.php`, `ScopeSemanticMatcher.php`, `SemanticMatcher.php` | **Delete** `SemanticMatcher` (zero non-test callers — *not* the live `ScopeSemanticMatcher`) and its binding. **Drop** the write-only `semantic_scopes`/`semantic_templates` collections + config keys (keep `semantic_entities`: read by the `findRelevantEntityLabels` fallback). Dedupe scope/entity embedding while preserving each consumer's payload/ID schema — **do not** naively collapse to one collection (verified: divergent shapes). |
| **P1-B7. One ingestion implementation (batch == single)** | L16 (L16-1, L16-4, L16-8) | `Services/BatchDataIngestionService.php`, `DataIngestionService.php` | Back both paths with the shared `VectorPayloadBuilder` + `GraphPropertyMapper` (P1-A5) so they produce identical payloads (sensible-column stripping + owner/security metadata) and identical graph sanitization. Fix the *third* leaky path `batchIngestToVectorCollection` too. Rebind `DataIngestionServiceInterface` so the batch service decorates the callers that reach it, or fold batch logic behind a flag. |
| **P1-B8. Centralize the citation `[N]` story** | L10 (L10-6, L10-7, L10-8, L10-5) | `Services/Response/FileCitationHandler.php`, `UI/SafeMarkdownRenderer.php:99`, `Response/ResponseFileEnricher.php:40` | The `[N]` strip deletes *any* bracketed integer from prose (`[2024]`, `[3]`, `[404]`) — real data loss. Introduce one `Citation` value object owning the pattern + 1-index parse + "resolves to a real file?" check. Strip `[N]` only when it maps to a context file; pick one citation owner (chips **or** sup-styling) and delete the dead branch. The `data-action-proxy` JS wiring exists but is fed by nothing — finish it or delete both halves. |

### P1-C. Action system completion (the extensibility centerpiece)

| item | layer | files | action |
|---|---|---|---|
| **P1-C1. Native tool-calling path** | L10/L11 (L10-15) | `Services/Response/ActionLinkHandler.php`, `Services/Actions/*` | Invocation rides on brittle LLM-authored markdown-regex (`[text](entity://Type/id/key?query)`); one stray space/encoded char silently drops the action. Expose the registry's `key`/`description`/`inputSchema` as provider tool/function definitions so capable models invoke structurally; keep markdown links as a legacy fallback. (Also fixes the LLM being told only key+label+aliases — description/inputSchema dropped in `EntityAutoDiscovery::getEntityActions`.) |
| **P1-C2. One real mutating reference action + booted-app approval test** | L11 (L11-1) | config example + `tests/` | No built-in or shipped config returns `requiresApproval()===true` (only a test `SpyAction`), so the headline write-gate is unproven end-to-end. Ship one mutating reference action (or a config example with `requires_approval=>true` + an `authorize` closure) and a booted-app feature test for `ApprovalCard -> approveAiAction -> handle`. |
| **P1-C3. Harden `PendingActionToken`** | L11 (L11-6) | `Services/Actions/PendingActionToken.php` | Add a max-age TTL check in `decode()`, reject `userId===null` in authenticated contexts, and add single-use protection (nonce/consumption flag) before `handle()`. A non-idempotent "charge $450" token can currently be replayed indefinitely; the `authorize()` re-check does not stop replay. **Verified nuance:** the authenticated flow normally binds a non-null user, so the null-binding bypass mainly bites unauthenticated/test contexts — TTL + single-use are the load-bearing fixes. |
| **P1-C4. One action-metadata source of truth** | L11 (L11-2, L11-3) | `PromptSections/EntityActionAwarenessSection.php:28-68`, `Services/Actions/AiActionRegistry.php` | The query-gen prompt reads raw `config('ai.entity_actions'/'generic_actions')` and never learns the built-ins (`export_excel`, `render_table`, …) exist, so "export this to excel" can mis-route to Cypher. Route `EntityActionAwarenessSection` through `AiActionRegistry::relevantActions()` — currently **dead** — making it the single entry point. |
| **P1-C5. Typed `AiActionResult` + Kompo presenter** | L11 (L11-7, L11-8, L11-4) | `Services/Actions/Results/AiActionResult.php`, `BuiltIn/*`, `Contracts/AiActionInterface.php` | `renderable:mixed` forces every consumer to type-sniff `is_array(...)&&isset(...)` (exactly how `presentation()` became dead). Narrow `handle()` to return `AiActionResult` with a typed `kind` enum (`element|table|export|pending|error`); move Kompo element construction into one presenter keyed off `kind`, decoupling action logic from Kompo. **Delete** dead `presentation()` from the interface. |

### P1-D. Retrieval precision: reranker + hybrid + abstention

| item | layer | files | action |
|---|---|---|---|
| **P1-D1. Wire the reranker into the graph/RAG path** | L4/L13 (L4-8, L13-5, L13-4) | `Services/ContextRetriever.php:313-394`, `Services/Context/FileContextProvider.php:333-387` | `LlmReranker` (genuinely well-built) is wired only into file search. Route similar-query and semantic-selection candidates through `RerankerInterface` (over-fetch -> rerank -> cut). Inject `RerankerInterface` into `FileContextProvider` and always call `rerank()` (contract guarantees safe fallback) — delete the duplicated `rerank_enabled` re-check and the hand-rolled `resolveReranker()` (the bound `NullReranker` is currently dead-by-construction). |
| **P1-D2. Hybrid retrieval (sparse + RRF)** | L4 (L4-8) | retrieval path | Add BM25/sparse over entity names/aliases/scope text fused with dense via Reciprocal Rank Fusion. Also the correct fix for the cross-collection score problem behind P0-9. |
| **P1-D3. Knowledge-query abstention** | L3 (L3-5) | `AiManager.php:850-858` | When `questionType=knowledge_query` and no file context was found, the result is "answered from documents" with confidence `1.0` regardless — confident, ungrounded answers. Gate on non-empty `fileContext['relevant_files']`; if empty, fall back to hybrid/Cypher or emit an explicit no-source abstention. Wire/cut `generateEmptyResponse` (P1-A6) here. |
| **P1-D4. Eliminate hardcoded-string fallbacks in metadata** | L4 (L4-9, L4-10) | `ContextRetriever.php:706-803`, `ScopeSemanticMatcher.php` | The `getEntityMetadata` fallback does `stripos`/`strpos` substring matching (violates no-hardcoded-semantics) and uses the *raw* question instead of the QU rewrite. Make semantic selection the single path; any fallback must be embed-based with a degraded flag (needs an index-populated guard so a cold collection doesn't silently return empty). Remove the literal stopword list; pass `$retrievalQuery` to the fallback. |

### P1-E. Prompt token diet & role-split correctness

| item | layer | files | action |
|---|---|---|---|
| **P1-E1. De-dup QueryRules vs TaskInstructions; fix positional refs** | L7 (L7-1, L7-2) | `PromptSections/QueryRulesSection.php`, `TaskInstructionsSection.php` | `QueryRulesSection` is now `system`-role but its rules say "the schema above"/"DETECTED ENTITIES section" — content that lives in the later USER message. Rewrite position-independently (name the blocks). Collapse `TaskInstructions` to its unique signal (NO-QUERY escape hatch + "CYPHER QUERY:" cue); drop items that echo `QueryRules`. |
| **P1-E2. Gate always-on instruction blocks on their referent data** | L7 (L7-3, L7-15, L7-16) | `ConversationContextSection.php:142`, `EntityActionAwarenessSection.php:104`, `SchemaSection.php:24` | Emit the conversation-instruction bullets, the action `Example:` block, and the schema block only when the data they reference exists (recent exchanges, non-empty `last_result_sample`, non-empty `graphSchema`). Override `shouldInclude()` for the empty-schema case instead of narrating "No schema information available." |
| **P1-E3. PII-minimization** | L7 (L7-7) | `PromptSections/CurrentUserContextSection.php:27` | User name + **email** + team are sent to the third-party LLM for every authenticated query. Gate email behind a config flag (default off) or include it only when `qu->isPossessive`/ownership is in play (user ID + team ID already cover ownership Cypher). |
| **P1-E4. Single role-split build entrypoint** | L7/L9 (L7-13, L9-7) | `SemanticPromptBuilder.php:328-347`, `ResponseGenerator.php:333-338` | Both `buildPrompt()` (single-string) entrypoints flatten the system/user split and survive only in integration tests, so those tests no longer exercise the real role separation. Drop them from the live classes (concatenate in the test if needed); migrate tests to `buildPrompts()`. Note `summarize` removal must also touch `ResponseGeneratorInterface`. |
| **P1-E5. Harden `spotlight()` + close the response injection gap** | L7/L9 (L7-8, L9-2) | `PromptSections/BasePromptSection.php:85`, `ResponseSections/ResponseConversationContextSection.php:68,79` | `spotlight()` escapes only the exact literal `</tag>` (whitespace/case/opening-tag variants pass through). Make escaping case-insensitive + whitespace-tolerant for open and close tags; share one helper across query and response sides. Wrap carried-over file snippets / `last_result_sample` via `spotlight(...,'retrieved_document')` (currently injected raw, bypassing rule 5); relocate the `Instructions:` line outside the wrapper rather than deleting it. |
| **P1-E6. Compact result payloads; cap edge vocabulary** | L9/L4 (L9-13, L4-14) | `ResultsDataSection.php:44`, `ContextRetriever.php:341` | Use compact `json_encode` for the model (pretty only in logs). Stop re-attaching the full `all_relationship_types` after relevance-filtering the schema. Also drop the duplicated language rule (system + user) at `GuidelinesSection.php:116` (L9-5). |
| **P1-E7. Config-drive the role note; relevance-gate PatternLibrary** | L7 (L7-14, L7-6) | `SemanticPromptBuilder.php:291`, `PatternLibrarySection.php` | Move the hardcoded English "Internally, you translate…" role note into config. Make `PatternLibrarySection` emit only patterns matching detected scopes (it currently dumps every pattern, inert by default), or drop it in favor of `DetectedScopes`' concrete patterns. |

### P1-F. UX surfaces & page-awareness

| item | layer | files | action |
|---|---|---|---|
| **P1-F1. Page-context injection** | L2 (L2-1) | `Kompo/AiChatPanel.php`, `AiChatFloating.php`, `Inputs/AskQuestionInput.php` | Add a `page_context` prop threaded through `AskQuestionInput` into the prompt-section pipeline so the assistant knows which page/record it was opened from. Core agentic capability currently absent (`page_context` = 0 hits). |
| **P1-F2. Dedicated screen + drawer surface** | L2 (L2-2) | `config/ai.php:898`, new surfaces | `AiChatDrawer`/`AiChatModal` are referenced in config/docs but don't exist. Ship `AiChatDrawer` (slide-over) + a routed full-screen surface, or delete the dead references. Extract a shared base layout so surfaces are thin shells. |
| **P1-F3. Single animation/typing renderer** | L2 (L2-4, L2-5, L2-11, L2-12) | `AiChatPanel.php`, `Components/TypingIndicator.php`, `MessagesQuery.php:334`, `resources/js/chat-message-injector.js`, `Traits/HasMethodsAsProperties.php` | The dots/wave/pulse/brain HTML exists in **four** diverging places (already differ: gradient vs solid). Centralize one renderer keyed by style, consumed by server render + JS via data attributes. **Delete** the never-instantiated `TypingIndicator` and the silent-null `HasMethodsAsProperties` (root cause of P0-7). Stop string-replacing HTML into JS (`js()` PHP-as-JS templating). |
| **P1-F4. Honor or remove inert settings** | L2 (L2-6, L2-7) | `MessagesQuery.php:238`, `config/ai.php:932,946` | `enable_markdown` and `show_typing_indicator` are documented toggles never read (and `show_typing_indicator` doesn't even match the resolver key `show_typing`). Gate the behaviors on real settings methods, or delete the keys + docs. |
| **P1-F5. Format-aware export + error mapping** | L1 (L1-4, L1-9) | `routes/web.php:7`, `Http/Controllers/ConversationController.php`, exporters | Add `{type?}` to the export route + a UI format picker (use `canExportExcel`/`canExportPdf` to hide unavailable formats), or drop the unreachable PDF/Xlsx conversation wrappers. Add exception->HTTP mapping (`ModelNotFound`->404, `RuntimeException`/`InvalidArgument`->422 surfacing the "install dompdf" degradation message). **Do not delete `ResultSetExporter`** — it is live via the export actions. |

### P1-G. Resilience & provider parity

| item | layer | files | action |
|---|---|---|---|
| **P1-G1. Resilience on the LLM path + server-side query timeout** | L12/L13/L8 (L12-13, L13-11, L8-3) | `Services/QueryGenerator.php:118`, `LlmProviders/*`, `Services/QueryExecutor.php`, `GraphStore/*` | The LLM path has only a rate limiter; no circuit breaker, no retry — the most failure-prone external dependency is the least protected. Wrap LLM calls in `CircuitBreaker('llm')` + `RetryPolicy::forNetworkRequests()` (retry only transient HTTP); capture the streamed error body (currently discarded -> opaque "HTTP 500: "). Separately, push the query timeout into the driver (Bolt `tx_timeout` / per-request `CURLOPT_TIMEOUT`) so `QueryTimeoutException` reflects a real server-side guard, not post-hoc wall-clock. Add a `RetryingGraphStore` decorator so Bolt + HTTP get resilience uniformly. |
| **P1-G2. Atomic fixed-window rate limiter** | L12 (L12-3) | `Services/Resilience/RateLimiter.php` | `get`-then-`put` is a non-atomic read-modify-write: concurrent workers both pass the gate. Use atomic `Cache::increment` + `Cache::add` first-hit TTL (the package's own `CircuitBreaker` already does atomic increment). Return a Retry-After signal. **Verified nuance:** the "infinite TTL slide" sub-claim is wrong (the cap-rejection path returns before the re-stamp); fix the race + reset semantics, not an infinite lock. Document the required shared/atomic cache driver for breaker + limiter. |
| **P1-G3. Anthropic `chatJson` JSON-mode/fence handling + shared base** | L13 (L13-2, L13-12, L13-15) | `LlmProviders/AnthropicLlmProvider.php:109-126`, `OpenAiLlmProvider.php` | Anthropic has no `response_format`; `chatJson` `json_decode`s raw output and throws on fenced/prose-wrapped JSON, degrading every `chatJson` consumer (QU, reranker, judge, enhancer) when anthropic is selected. Strip fences / extract the first balanced-brace block, or use tool-use/prefill. Extract `AbstractHttpLlmProvider` (shared curl, SSE, JSON-system injection, error mapping) to kill the ~70% copy-paste and the divergent system-prompt precedence; add a fenced-response parity test. |
| **P1-G4. Per-role LLM selection actually used** | L13 (L13-1) | `AiServiceProvider.php:291,316` | `QueryGenerator`/`ResponseGenerator` are built with the default provider, never `forRole()`, so the advertised `query_generation`/`response_generation` role keys are inert. Inject `LlmResolver->forRole(...)` into the two generators, or delete the dead keys + docblock. |

---

## P2 — cleanup

Dead code, misleading comments, inert config keys, and command sprawl. Low-risk and mostly mechanical; batch after the structural work so deletions don't collide with the splits.

### P2-A. Dead code / dead surface to delete

| item | layer | files |
|---|---|---|
| `provides()` dead (provider not deferrable) + out of sync | L0 (L0-6) | `src/AiServiceProvider.php:909-970` — note `ChunkStoreInterface` **is** present (don't cite it as missing) |
| `EntityAccessCheckerInterface` — zero impls/bindings/callers (delete) | L12 (L12-2) | `src/Contracts/EntityAccessCheckerInterface.php` |
| `filterSensitiveResults()` — security-named no-op on the live path | L0 (L0-5) | `src/Services/AiManager.php:1101-1117` (remove call + method until `AiAccessGate` exists) |
| `sanitize()` regex butcher + `AI::sanitizeQuery` false safety net | L8 (L8-8) | `QueryGenerator.php:383`, `AiManager.php:514` |
| Dead transaction API (begin/commit/rollback/queryInTransaction) | L8 (L8-6) | `GraphStore/BoltNeo4jStore.php:264-358`, `Neo4jStore.php`, interface (delete or wire ingestion to it) |
| `cancel()` unused + broken `$queryId` contract — **keep** `explain()`/`test()` (facade callers exist; verified *not* fully dead) | L8 (L8-7) | `Contracts/QueryExecutorInterface.php` |
| `getChunk`/`getChunkCount` dead; both ignore `?collection` | L6 (L6-6) | `ChunkStoreInterface.php`, `QdrantChunkStore.php:428-473` |
| `extractMetadata`/`getStats` (+ per-extractor `extractMetadata`, double PDF parse) | L6 (L6-7) | `FileExtractorRegistry.php`, all extractors |
| Neo4j half of `FileSearchService` (graph methods + facade) dead; `filterByMetadata` is a **no-op** (`property_exists` short-circuits — *not* "drops everything") | L5 (L5-6) | `FileSearchService.php:343-456` (split into optional `FileGraphSearch` or delete) |
| Access-audit log (`canAccessFile`/`AiFileAccessLog`) — no production caller | L5 (L5-3, L5-15) | `FileAccessResolver.php:194-242`, `Models/AiFileAccessLog.php`, migration, `log_access` config (wire into live `filterAccessibleFileIds` or delete) |
| `FileContextProvider::buildFileReference` orphaned (test-only) | L5 (L5-7) | `FileContextProvider.php:250-268` |
| Dead options/follow-up/`enhanceQueryWithContext`/`isFollowUpQuestion` in `FileContextProvider` | L5 (L5-4, L5-8) | forward options from `getFileContext`, or delete; keep `ReferenceResolver` as the single follow-up authority |
| `PatternLibrary` instantiate/validate/format machinery (test-only; also `getPatternNames`/`hasPattern`) | L7 (L7-5) | `PatternLibrary.php:82-252` (shrink to `getAllPatterns()`/`loadPatterns()`) |
| `minimal()`/`simple()` builder factories (doc-only) | L7 (L7-4) | `SemanticPromptBuilder.php:359-390` |
| `QueryInfoSection` unregistered + schema-leak footgun (scrub from doc examples too) | L9 (L9-6) | `ResponseSections/QueryInfoSection.php` (delete) |
| `intent` computed/validated/logged but drives nothing | L3 (L3-3) | `QueryUnderstandingService.php` (wire into query shape, or drop from DTO + prompt enum) |
| Hardcoded English possessive regex on the empty/fallback path | L3 (L3-7) | `DTOs/QueryUnderstandingResult.php:37-40` — **verified nuance:** it is the no-LLM fallback's only source of `isPossessive` (which *is* consumed); removing it regresses the QU-disabled path — replace deliberately |
| Dead discovery methods | L14 (L14-9, L14-10) | `shouldDiscover` + `discoverWithTypes`/`detectDiscriminatorInRelation`/`clearAllCaches`/`getDiscriminatorFields`/`isDiscriminatorField` (test-only `getSpy`/`getGenerator` are DI plumbing — keep distinction in mind) |
| `RetryPolicy` test-only presets | L12 (L12-11) | keep `forDatabaseOperations`; the LLM path now needs `forNetworkRequests` (P1-G1); drop/relocate the truly unused preset |
| Dead theming tower | L16 (L16-11, L16-12) | collapse to one factory + injectable preference source + `ai.ui.presets`; keep `ConfigTheme`, delete `GreenTheme`, convert `IndigoTheme` to a config preset; drop unused `isComplete`/`getMissingColors` |
| Speculative single-method traits / BC wrappers | L2 (L2-13) | `HasConversationCreation::createConversation` wrapper; fold single-method traits into a base chat component |
| `action_links` built-and-discarded + lying docblock | L10 (L10-1) | `ResponseEntityEnricher.php` — delete `buildActionLinks`; keep live `has_actions`/`available_actions`/`entity_type`. **Verified severity downgrade:** P2, not P1 |
| Injected `EntityAutoDiscovery $discovery` never read in `ActionLinkHandler` | L10 (L10-10) | `Services/Response/ActionLinkHandler.php:42,45` |

### P2-B. Duplication to collapse into one helper

| item | layer | files |
|---|---|---|
| `extractEntityId` copied in 3 classes (stale default missing `uuid`) | L10 (L10-9) | extract one `EntityIdResolver`. **Verified nuance:** `mergeConfigFrom` keeps `uuid` live at runtime, so the "uuid rows silently lose links" rationale is wrong — fix as cleanup, drop the data-loss framing |
| `entity://` link grammar hand-built in 2 drifted places | L10 (L10-2) | extract one `EntityActionLinkFormatter` |
| `PHYSICAL_PREFIX` string copy-pasted across resolver/enricher/indexer/migration | L5 (L5-11) | hoist to one shared constant |
| `extractProperties`/`sanitizeForGraph` copied batch<->single | L16 (L16-8) | shared via `GraphPropertyMapper` (P1-A5) |
| `on_permission_unexistent` deny/bypass policy in two homes | L8/L12 (L8-5, L12-4) | one `SecurityFailurePolicy::decide(reason)`. **Verified nuance:** P2 not P1 — both default to deny (no live hole); the warning log is in *one* place, not two |
| Divergent write/dangerous keyword lists (executor missing `DROP`) | L8 (L8-4) | one canonical keyword list shared by `validate()` and the executor read-only gate. **Verified nuance:** real fix is physical read-only enforcement (P1-G1 territory); `DROP` is schema-only, so this is defence-in-depth, P2 |
| Deprecated `arrayToCypherProps` + throwing `CypherSanitizer` variants (test-only) | L8 (L8-10) | collapse to the single `escape*`/`validateIdentifierSafe` path production uses |

### P2-C. Inert / mismatched config keys & broken hints

| item | layer | files |
|---|---|---|
| QU `confidence_threshold` advertised gate is dead config; QU always runs | L3 (L3-1) | implement the gate (needs a pipeline reorder — semantic score doesn't exist when QU runs) **or** delete the key + env + lying docblock |
| `ai.discovery.*` block inert; code reads `ai.auto_discovery.*` (role_mappings always `[]`) | L14 (L14-3) | unify on one prefix; the impactful read site is `TraversalScopeGenerator:333` |
| Entities config split across `entities` vs `ai.entities` (eval reads the empty stub) | L14 (L14-1) | one source of truth; make eval read `config('entities')` or `mergeConfigFrom('entities')`. **Verified nuance:** ~3 real read sites (not ~14); only diverges when `ai.php` isn't published |
| `eval.generation.*` + `eval.offline_default` knobs read nowhere | L15 (L15-5, L15-6) | honor in `QuestionSetGenerator`/`handle()` or delete |
| `access_scope` documented but never invoked | L5 (L5-2) | folded into P0-10 |
| `processed_at` shipped column never written (whereNull no-op) | L6 (L6-8) | write it on success, or batch via content-hash and drop the column |
| Diagnose prints wrong API-key env vars; `IndexSemanticCommand` points at non-existent `ai:test-semantic`; `IndexContextCommand` reads wrong relationships key | L14 (L14-14, L14-11, L14-15) | fix the strings |
| OCR knobs are env-var-only; `pageLimit` unreachable from callers | L6 (L6-10) | thread page-limit/thresholds through `processFile` options; move `OCR_*` into `config/ai.php` |

### P2-D. Command sprawl & model-agnostic leaks

| item | layer | files |
|---|---|---|
| Three overlapping index commands; scopes embedded 2-3x in `ai:setup` | L14 (L14-12) | collapse `ai:index-scopes` into `ai:index-context --scopes-only`; document the authoritative collection family; drop the redundant scope pass |
| Unreachable split `config/ai-entities/` write path | L14 (L14-4) | add `ai:discover --split` or delete the split + backup branch |
| `ai:test-question` re-implements the pipeline (feeds enhancer ground truth) | L14 (L14-5) | drive through the orchestrator (`answerQuestion`) with a `--debug` decorator |
| Hardcoded `App\Models\User` in `TestConversationCommand` | L14 (L14-13) | resolve from `config('auth.providers.users.model')` like siblings |
| Hardcoded CondoEdge `BUSINESS_TERMS` dictionary + `bnc_`/`payable_` prefixes | L14 (L14-6, L14-7) | move to config (`ai.discovery.alias_mappings`/`exclude_properties`) / derive from lang + LLM; ship empty defaults |
| Hardcoded multilingual regex / substring label matching in `EntityExtractor` + naive `+s` pluralization in `ReferenceResolver` | L5 (L5-9, L5-10) | drive from QU output / `Str::plural`; word-boundary matching |
| `search_tags` prompt: unenforced "EXACTLY 10" + baked-in condo/French example | L3 (L3-2) | ask for "5-10 tags"; derive domain/language from config, not the prompt literal; cap/dedupe post-LLM |
| Byte/char mixing + oversized-unit bailout in chunker | L6 (L6-2, L6-3) | `mb_*` everywhere; recurse only the oversized unit, keep boundaries for the rest. **Verified nuance:** the sentence path rescues many cases — reword the "whole doc collapses" claim |
| Fabricated chunk start/end offsets under overlap | L6 (L6-1) | real `mb_strpos` from a moving cursor, or drop the fields. **Verified nuance:** latent — no citation/highlight consumer exists today |
| No embedding/chunk count alignment check | L6 (L6-12) | assert `count(embeddings)===count(chunks)`; reject empty/wrong-dim vectors before upsert |
| `_owner_id` write-only half-built ACL | L16 (L16-2) | enforce owner filtering in the query layer + write in both paths, or remove |
| MySQL-only `JSON_EXTRACT` in `AiConversation` pinned scope | L16 (L16-18) | portable `whereJsonContains`, or document the MySQL requirement |
| Per-entity `auto_sync` looked up by short key vs FQCN-keyed config | L16 (L16-6) | reuse `ResolvesEntityConfigKey` |
| `remove()` fetches `getVectorConfig()` unconditionally; graph-only delete throws before node removal | L16 (L16-7) | try/catch like `sync()` does (covers the batch-remove path too) |
| `ingestBatch` index-misalignment after `reject()` (mis-reports status) | L16 (L16-5) | key results by entity id; add the all-already-ingested test (note the latent id-flattening bug that currently masks it) |
| Vector dimension from config vs provider; `dimensions` read but never sent to the API | L16/L13 (L16-14, L13-3) | use `embeddingProvider->getDimensions()` in both paths; send/validate `dimensions` for 3-series |
| Over-redacting 40-char AWS regex clobbers SHA-1/base64/IDs in logs | L12 (L12-6) | drop the bare rule or anchor to AWS context; keep AKIA. **Verified nuance:** in-repo IDs are MD5 (32-char, unaffected) — real harm is log fidelity, P2 not P1 |
| Hardcoded English/emoji strings escaping i18n; truncation with no "showing N of M" | L2 (L2-17, L2-18) | move to `ai.*` keys; add a "view all" affordance |
| Qdrant `http`-only (no TLS) + internal `config()` fallback | L13 (L13-9) | add a scheme/https config; drop the internal `config()` fallback (L13-8 blind auto-create is a **P3 DRY nit** — no distance config exists, cosine is the universal default) |
| `MemoizedEmbeddingProvider` unbounded cache + singleton lifetime contradicts per-request docblock | L13 (L13-13) | bound the cache / reset per request (Octane) or use `scoped()`; fix the docblock |
| `intent`/`InputSanitizer` naming: `sanitize()`/`process()`/`addPattern()` dead, only `analyze()` runs | L12 (L12-5) | rename to `PromptInjectionDetector` + delete the dead sanitize API, or actually sanitize before the prompt; fix the docs |
| Migration-narrative / archaeology docblocks across the codebase | L0/L1/L4/L5/L6/L9/L15 (L0-10, L1-10, L4-15, L5-13, L6-14, L9-10, L15-15, …) | trim to one-line intent; strip audit tags and `// Success!` |
| Bloated/drifted facade `@method` mirror + 130-line `FileSearch`/`AI` docblocks | L0/L5 (L0-10, L5-14) | match to the real live surface |
| Stale primary UI doc documenting a nonexistent fluent API | L2 (L2-15) | rewrite against the real `AiChatPanel`/`AiChatFloating`/settings/theme contracts |
| `MESSAGES_PANEL_ID` docblock references a nonexistent constant | L2 (L2-14) | fix to `MessagesQuery::ID`. **Verified nuance:** docblock-only; ships fine, fatals only for a copy-paster |

### P2-E. Health / observability / eval-attribution

| item | layer | files |
|---|---|---|
| Health LLM check is config-only + flips 503 on a missing key | L0 (L0-11) | cheap real LLM ping or soft-warn; make the three checks symmetric; consider `/live` vs `/ready` |
| Orchestrator uses `\Log` + `now()` instead of injected `AiLogger` | L0 (L0-12) | inject the existing `AiLogger` singleton; propagate trace id into queued ingest/sync jobs |
| Eval attribution mislabels query-gen failures as "understanding"; offline rows lose "judge-skipped" label; offline neutral makes generation never beat prompt | L15 (L15-1, L15-3, L15-11) | label query-gen `retrieval` (or add a sub-reason); treat null verdict as judge-skipped; differentiate the two neutral scores. **Verified nuance (L15-1):** `AiManager::generateQuery` swallows the throw, so the mislabel only bites on the injected-facade test path — real-world severity closer to P2 |
| Pin a distinct `eval_judge` model; warn when judge provider == generation provider | L15 (L15-7) | mitigate LLM self-preference bias in the reference-free judge |

---

## Sequencing

Execute in batches. Batches are ordered so later work builds on earlier extractions; within a batch the lettered groups touch disjoint file sets and can run as **parallel worktrees** without conflicting.

### Batch 0 — Security & ships-broken P0s (land first)
Small, high-urgency. Two sub-groups share files, so coordinate within them but keep the whole batch ahead of all refactors.
- **0a (QueryExecutor security + count):** P0-2 + P0-3 + P0-12 together — all live in `QueryExecutor.php` and all concern the scoping/count surgery; do them as one change that introduces the `SecurityFilterInjector` seam (pre-work for P1-A3).
- **0b (fail-closed defaults):** P0-4 (`NullAuthAdapter`) + P0-10 (file-access default) — disjoint from 0a.
- **0c (fully disjoint isolated files):** P0-1, P0-5, P0-6 (`AiManager` facade), P0-7 (link_hover), P0-8 (`ai:setup`), P0-11 (double-dispatch), P0-13 (i18n keys). Safe to parallelize.
- **0d (retrieval correctness):** P0-9 (score normalization) — `FileSearchService` + `FileContextProvider`; isolated.

> Rationale: P0-2/P0-3 must precede the `QueryExecutor` split (P1-A3) so the split inherits a *correct* injector. P0-4/P0-10 establish the fail-closed baseline the rest of the security work assumes.

### Batch 1 — Shared seams & single-path collapses (enables the splits)
Build the collaborators the giant-file splits depend on, and delete the dead second/third copies so the splits move less code.
- **1a:** P1-B1 `FileContextRetriever` (unblocks the AiManager / TestQuestion / Eval-driver alignment).
- **1b:** P1-B2 + P1-B3 chat-path collapse + single error mapper (both in `Services/Chat/*` + `ChatPresenter`; disjoint from 1a).
- **1c:** P1-B7 + P1-A5 ingestion — build `VectorPayloadBuilder`/`GraphPropertyMapper`, unify batch/single, then split `DataIngestionService`.
- **1d:** P1-B6 indexer consolidation + `SemanticMatcher` deletion (disjoint storage files).
- **1e:** P1-B8 `Citation` value object + strip fix (disjoint response/UI files).

### Batch 2 — Giant-file decompositions (depend on Batch 0/1 seams)
Each split in its own worktree; they touch different files.
- P1-A1 `AiManager` (after 1a/1c so the extracted methods already have homes), P1-A2 `ContextRetriever`, P1-A3 `QueryExecutor`/`QueryGenerator` (after 0a), P1-A4 `MessagesQuery` (carries P1-B5), P1-A6 `ResponseGenerator`, P1-A7 `OcrPdfParser`.

### Batch 3 — Capability work (independent feature tracks, parallel)
- **3a Action system:** P1-C1…C5 — one worktree, sequenced internally C4 -> C2 -> C3 -> C5 -> C1.
- **3b Retrieval precision:** P1-D1…D4 — builds on Batch 2's `ContextRetriever` split.
- **3c Prompt diet:** P1-E1…E7 — disjoint prompt-section files; highly parallelizable.
- **3d UX:** P1-F1…F5 — page-context, surfaces, animation renderer, inert settings, export; builds on Batch 2's `MessagesQuery` split.
- **3e Resilience/providers:** P1-G1…G4 — `AbstractHttpLlmProvider`, LLM circuit-breaker/retry + server-side timeout, atomic limiter, per-role selection.

### Batch 4 — Cleanup sweep (last, after the structure settles)
All of P2, grouped so deletions don't fight the splits:
- **4a:** P2-A dead-code deletions (run after Batch 2 so we're not deleting from files mid-split).
- **4b:** P2-B duplication-to-helper collapses.
- **4c:** P2-C / P2-D config keys, command sprawl, model-agnostic leaks, comment/doc trims.
- **4d:** P2-E health / observability / eval-attribution.

> Hard cross-batch ordering constraints: `0a -> P1-A3`; `1c -> P1-A1/P1-A5`; `1a -> P1-B1 consumers`; `Batch 2 -> Batch 4 deletions`. Everything else within a batch is parallel-safe by file-disjointness.

---

## Out of scope / explicitly NOT doing

Anti-overengineering guardrails. These were either refuted by verification, or are tempting gold-plating the audit explicitly warned against.

**Refuted findings — do not "fix" (verified `FALSE_POSITIVE`):**
- **L2-9** `fileReferenceCard` "array-vs-model punning bug" — the model is the *required* arg to `getPreviewButton`; nothing misbehaves. At most a cosmetic var-shadowing nit; **do not** rework the data shape.
- **L5-6 (bug half)** `filterByMetadata` does **not** "drop everything" — `property_exists` short-circuits and rows fall through to `return true` (it's a no-op filter). The *dead-code* half is real (P2-A); the inverted bug rationale is not.
- **L11-12** `RenderTableAction` header union — deriving headers from the capped rows is **correct**; the proposed "full-set" fix would emit phantom empty columns. No change (a one-line doc comment at most).
- **L12-7** `checkIfPermissionsExist` does **not** run DB lookups during bypass — `isEnabled()` short-circuits first. P3 stylistic nit only; folded loosely into P0-4, no behavior fix.
- **L12-10** jitter is **not** truncated to near-zero — full magnitude is preserved; only integer granularity is coarse. Not worth a change.
- **L13-8** Qdrant blind auto-create is **not** a config-bypass/distance-drift bug — no distance config exists and cosine is the universal default. Treat only as a P3 DRY nit (single creation path), not a correctness fix.
- **L16-9** `toArray()` dropping `primary_light_gradient` is **inert** — no caller serializes themes via `toArray()` and the only consumer (`themeLightGradient`) is itself dead. P3 hygiene at most.

**Explicitly NOT building (scope / anti-overengineering):**
- No edits to sibling packages (`../Sisc`, `condoedge/utils`, `kompo/auth`). All `kompo/auth` coupling is solved with in-package fail-closed fallbacks (P0-4).
- No second vector-store adapter, no agentic multi-step planner, no streaming/async turn engine, no eval gold-label/ensemble-judge harness — these are valid "next-level" ideas in the lane additions but are net-new surface, not 10/10-of-current-scope. Defer.
- No `PipelineContext` rewrite into a deep DTO hierarchy beyond the typed `FileContext`/`ConversationContext` sub-DTOs needed to stop stage-stomping (L0-9). Keep it a typed bag.
- Do not "harmonize" duplicated copies (error mappers, fan-out loops, indexers, link grammars) — **collapse to one**. Harmonizing preserves the drift surface the audit is trying to eliminate.
- Do not implement the QU `confidence_threshold` gate as a quick guard — it requires a pipeline reorder (semantic score doesn't exist when QU runs). Either do the reorder deliberately or delete the knob (P2-C); no half-measure.
- Do not add speculative interfaces. New interfaces only for `QueryUnderstanding`, `ContextSelector`/`ScopeMatcher`/`SemanticIndexer` (genuine host-injection points). `EntityAccessCheckerInterface` is deleted, not kept "for future node-level ACL."
- Do not retire the Eloquent->Cypher discovery transpiler in this pass (L14-16) — it is fragile but live and the LLM-only replacement is a larger redesign; fix the `extractFilterFromCalls` key bug (P2-C/D) and move on.
- Keep prompts model-agnostic and lean: no new always-on instruction blocks, no domain examples baked into literals, no PII by default. A new section must justify its tokens against the 30-50% removable-overhead target.
