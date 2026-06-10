# Architecture walkthrough — one request, end to end

This is the centerpiece doc: what happens between a user typing a question into the chat
and clickable, grounded answer bubbles appearing — plus the offline ingestion side that
makes retrieval possible. Each stage section gives: what it does and why, the key classes,
the input/output contract, how a host extends it, and where its tests live.

The single typed bag carried between stages is `PipelineContext`
(`src/DTOs/PipelineContext.php`): each stage stamps its field (`qu`, `semanticSelection`,
`graphSchema`, `fileContext`, `queryResult`, `executionResult`, ...) and downstream stages
read only that. The full pipeline is orchestrated by `AiManager::answerQuestion()`
(`src/Services/AiManager.php:450-488`).

## The pipeline

```
 user asks (Kompo surface: floating button -> modal/drawer/screen)
   |
   v
 chat turn orchestration ------ security gate FIRST, conversation context, DB transaction
   |                            (ChatPresenter -> AskQuestionUseCase -> AI::answerQuestion)
   v
 query understanding (QU) ----- one fast LLM call: entity types, names, intent,
   |                            question type, rewritten search query
   v
 context retrieval ------------ vector (similar Q->Cypher, semantic entity selection)
   |                            + graph (relevance-filtered schema, sample rows)
   |                            + files (multi-angle chunk search, ACL-filtered snippets)
   v
 scope detection -------------- question phrasing -> named entity scopes (cypher_pattern)
   |
   v
 query generation ------------- deterministic template match, else LLM-written Cypher
   |                            with validate/retry loop (read-only enforced)
   v
 security filter injection ---- structural analysis -> team filters spliced in;
   |                            unprovable placement = query refused (fail closed)
   v
 execution -------------------- Neo4j, timeouts, rate limit, result formatting, query log
   |
   v
 response generation ---------- priority-ordered prompt sections, grounding on rows+files,
   |                            abstention when there is nothing to ground on
   v
 actions ---------------------- markdown action:// links  OR  native tool loop;
   |                            mutating actions -> signed token -> approval card
   v
 rendering -------------------- bubbles, rich data tables, file citation cards,
                                approval cards, exports
```

## Stage 0 — Entry: chat surfaces and turn orchestration

**What/why.** Captures the question, decides whether the turn may run at all, threads
conversation memory, and packages the result. No RAG logic lives here — it is the front
door and the bookkeeper, kept separate so the heavy pipeline stays UI-agnostic.

**Key classes.**
- `src/Kompo/AiChatFloating.php` — the host-mounted button; opens the surface configured by
  `ai.chat.surface` (modal/drawer/screen) and forwards its `page_context` prop.
- `src/Kompo/Presenters/ChatPresenter.php` — the single Kompo↔application seam; one
  `handleX()` per UI action, exception-type-based friendly errors.
- `src/Services/Chat/UseCases/AskQuestionUseCase.php` — the one turn orchestration path:
  security gate as STEP 0 (`:47`, via `src/Services/Chat/Support/ChatSecurityGuard.php`),
  whole turn in a `DB::transaction`, conversation context via
  `src/Services/Context/ConversationContextManager.php`, then `AI::answerQuestion(...)`
  (`:132`) and persistence of both messages.

**Contract.** In: `AskQuestionInput` (conversation, question, style, user, page context).
Out: immutable `ChatTurnResult` (answer, data, suggestions, sources, cypher, the persisted
assistant `AiMessage`, and a `degraded` flag set when there was no grounding — `:176-178`).

**Host extension.** Mount `new AiChatFloating(null, ['page_context' => [...]])` in any Kompo
view; swap the whole chat service via `ai.chat.service` (config/ai.php:1028); configure
surface, welcome copy, `example_questions`, and per-page `page_examples` under `ai.chat`.

**Tests.** `tests/Unit/Services/Chat/UseCases/`, `tests/Unit/Kompo/Presenters/`,
`tests/Unit/Kompo/MessagesQueryTest.php`. Deep dive: `docs/audit/layers/01-chat-application.md`,
`layers/02-kompo-ui-ux.md`.

## Stage 1 — Query understanding (QU)

**What/why.** One fast, low-temperature LLM call turns the raw question into machine-usable
hints *before* any retrieval: entity types, person names, data concepts, a rewritten search
query, keyword tags, a clarified question, possessive flag ("my X"), intent (count/list/...),
question type (data/knowledge/hybrid), and per-corpus-language translations for cross-lingual
document retrieval. Without it, entity selection and file search work off raw user phrasing.

**Key classes.**
- `src/Services/QueryUnderstandingService.php` — `analyze(question, entityCatalog)` (`:42`).
- `src/DTOs/QueryUnderstandingResult.php` — the structured output, stamped on
  `PipelineContext->qu`.

**Contract.** In: question + a compact catalog built from `config/entities.php`. Out:
`QueryUnderstandingResult`; on failure the stage degrades to `QueryUnderstandingResult::empty()`
rather than aborting the turn (`src/Services/ContextRetriever.php:263-270`).

**Host extension.** Gate with `AI_QUERY_UNDERSTANDING_ENABLED` (config/ai.php:795); tune
tokens/temperature under `ai.query_understanding`; point the stage at a cheaper model via
`ai.llm_roles.query_understanding` (resolved by `src/Services/LlmResolver.php`). Richer
entity descriptions/aliases in the catalog directly improve QU output.

**Tests.** `tests/Unit/Services/QueryUnderstandingCorpusTranslationTest.php`,
`QueryUnderstandingEntityTypeValidationTest.php`. Deep dive: `layers/03-query-understanding.md`.

## Stage 2 — Context retrieval (vector + graph + files)

**What/why.** Decides *which slice of the domain* the prompts will carry, so the LLM sees
"Person + volunteers scope + PersonTeam relationship" instead of the whole schema — token
efficiency and precision. Runs in two parts: entity/graph context inside
`ContextRetriever::retrieveContext` (`src/Services/ContextRetriever.php:228`), then file
context inside `AiManager::retrieveAndSetFileContext` (`src/Services/AiManager.php:493`).

**Key classes.**
- `src/Services/ContextRetriever.php` — thin orchestrator: QU (STEP 1, `:251`), semantic
  selection (STEP 2, `:272`), then focused collaborators in `src/Services/Retrieval/`
  (`SimilarQuerySearcher` for past Q→Cypher pairs, `SchemaProjector` for the
  relevance-filtered schema, `EntityMetadataBuilder` for detected entity/scope metadata,
  `TokenBudgeter`; optional `HybridFuser`/`LlmReranker` behind `ai.rag.hybrid_enabled` /
  `rerank_enabled`, both default off).
- `src/Services/SemanticContextSelector.php` — vector lookup against the `context_index`
  collection (built by `ai:index-context`); soft-seeds the conversation's focused entity and
  the `page_context` entity type so follow-ups and "this unit" resolve
  (`src/Services/ContextRetriever.php:302-326`); expands via `user_entity_paths` on
  possessive questions (`:627-637` of the selector).
- `src/Services/Context/FileContextProvider.php` + `src/Services/FileSearchService.php` —
  multi-angle file-chunk search (QU search query, raw question, clarified question, tags,
  corpus translations, page-context angle), per-file merge, and a recall-floor retry when no
  document clears the confident threshold (`src/Services/AiManager.php:516-610`).
- `src/Services/Context/FileAccessResolver.php` — filters candidate files to what the user
  may see (ACL), before anything reaches the prompt.

**Contract.** In: question + options (`conversation_context`, `page_context`, `user`). Out:
`PipelineContext` with `qu`, `semanticSelection`, similar queries, `graphSchema`, examples,
entity metadata, and `fileContext` (`relevant_files` with snippets + relevance). Partial
failures degrade gracefully and are recorded as context errors.

**Host extension.** Tune `ai.semantic_context`, `ai.rag`, `ai.file_context`
(`min_relevance_score`, `recall_floor`, `corpus_languages`, `file_model`); define
`user_entity_paths` for "my X" questions; rebuild indexes after catalog changes:

```bash
php artisan ai:index-context --all
php artisan ai:index-semantic --rebuild
```

**Tests.** `tests/Unit/Services/ContextRetrieverTest.php` (+ `...RetrievalQueryTest`,
`...ConfidenceTest`), `tests/Unit/Services/Retrieval/`, `tests/Unit/Services/Context/`,
`tests/Unit/Services/AiManagerFileContextTest.php`, `AiManagerPageContextTest.php`,
`AiManagerCorpusLanguageAnglesTest.php`. Deep dives: `layers/04-retrieval-semantic-context.md`,
`layers/05-file-retrieval-access.md`.

## Stage 3 — Scope detection

**What/why.** Maps question phrasing to the catalog's named scopes ("pending invoices" →
`Invoice.pending` with `cypher_pattern: "status = 'pending'"`), so the query generator gets a
proven filter fragment instead of inventing one. Matching is semantic (embeddings), never a
hardcoded word list.

**Key classes.**
- `src/Services/ScopeSemanticMatcher.php` — searches the `scope_examples` Qdrant collection
  (`:27`), thresholds under `ai.scope_matching` (config/ai.php:821-827).
- `src/Services/Retrieval/EntityMetadataBuilder.php` — folds detected scopes into the
  selected-entity metadata that the prompt sections read (`:22` takes the matcher).
- `src/Services/PromptSections/DetectedScopesSection.php` — surfaces them to the query LLM.

**Contract.** In: the (rewritten) question + selected entities. Out: matched scopes with
scores on the entity metadata / `SelectedScope` DTOs inside `PipelineContext`.

**Host extension.** Add scopes to entities in `config/entities.php` (concept,
`cypher_pattern`, 3–5 example questions in each corpus language — `ai:enhance` generates
these), then re-index:

```bash
php artisan ai:index-scopes --force
```

**Tests.** `tests/Unit/Services/Retrieval/EntityMetadataBuilderTest.php`,
`tests/Unit/Services/EntityMetadataTest.php`. Deep dive: `layers/04-retrieval-semantic-context.md`.

## Stage 4 — Query generation

**What/why.** Turns the clarified question + retrieved context into one read-only Cypher
query. A deterministic template path runs first (no LLM cost, no variance); otherwise the
LLM writes Cypher inside a validate/retry loop. Generation aims for *usefulness* — the hard
security boundary is the next stage, which re-analyzes everything from scratch
(`src/Services/QueryGenerator.php:55-59`).

**Key classes.**
- `src/Services/QueryGenerator.php` — owns the LLM retry/validate loop and prompt assembly.
- `src/Services/TemplateMatcher.php` — the LLM-free template path (used when confidence ≥
  `ai.query_generation.template_confidence_threshold`, default 0.8).
- `src/Services/SemanticPromptBuilder.php` + `src/Services/PromptSections/` — the
  priority-ordered sections composing the query prompt (schema, detected entities/scopes,
  similar queries, conversation/page context, tenant-scoping rules, user context...).
- `src/Services/CypherValidator.php` — syntax/safety validation (canonical write-keyword
  list); `src/Services/Security/CypherScopeAnalyzer.php` teaches the LLM to regenerate
  inside the tenant-scopeable subset.

**Contract.** In: question + enriched `PipelineContext`. Out: `{cypher, explanation,
confidence, warnings, metadata}` → stamped as `QueryGenerationResult`. Knowledge questions
with retrieved documents skip this stage entirely (`src/Services/AiManager.php:738-752`).

**Host extension.** Compose/replace prompt sections via `ai.query_generator_sections`
(config/ai.php:1318-1338 — note it contains a closure at :1333, so `config:cache` breaks on
the default file); add patterns via `config/ai-patterns.php` (`ai.query_patterns`); tune
limits/retries under `ai.query_generation`; per-stage model via
`ai.llm_roles.query_generation`.

**Tests.** `tests/Unit/Services/QueryGeneratorTest.php` (+ `...SecurityTest`,
`...SubsetTest`, `...RoleSeparationTest`), `tests/Unit/Services/TemplateMatcherTest.php`,
`tests/Unit/Services/PromptSections/`. Deep dives: `layers/07-prompt-assembly-query.md`,
`layers/08-query-gen-exec-graph.md`.

## Stage 5 — Security filter injection

**What/why.** The tenant-isolation boundary. LLM-written Cypher is never trusted: before
execution the query is lexed and structurally analyzed, every binding of a sensitive label
gets a team filter spliced in at recorded byte offsets, and anything that cannot be *proven*
correctly scoped is refused. This is invariant #1 (fail closed) made concrete.

**Key classes (all under `src/Services/Security/`).**
- `CypherScopeAnalyzer.php` — produces a `ScopePlan` (which bindings exist, where filters
  can be placed) from the raw query.
- `AiSecurityService.php` — resolves the per-entity team filter through the host's
  `AiAuthAdapterInterface`; emits a bound `$ai_team_ids` parameter, never inline literals
  (`:36-49`); missing permission metadata follows `ai.security.on_permission_unexistent`
  (default deny, `:58-60`). `TeamPathResolver.php` walks graph paths for entities without a
  direct `team_id`.
- `SecurityFilterInjector.php` — splices the filters structurally and returns `null` on any
  residual ambiguity or invalid rewrite, so the caller refuses to run the query (`:36-40`).
- `ScopeAcceptanceGate.php` / `SensitiveLabelCoverageGate.php` — decide whether the rewrite
  fully covers every sensitive binding.

All of it is invoked from inside the executor (`QueryExecutor::applySecurityFilter`,
`src/Services/QueryExecutor.php:652`, injection at `:827`), not from generation — so no
generation bug can skip it.

**Contract.** In: candidate Cypher + the authenticated user. Out: a rewritten, fully scoped
query + bound parameters, or a `SecurityException`/refusal. With `AI_SECURITY_ENABLED=true`
and no auth adapter (standalone host), `NullAuthAdapter` yields an empty team set and every
secured query is denied (`src/Auth/NullAuthAdapter.php:23-54`).

**Host extension.** Bind a custom `AiAuthAdapterInterface` (auto-binds `KompoAuthAdapter`
when kompo/auth is detected, `src/AiServiceProvider.php:614-621`); declare team paths and
sensitive labels in entity config; knobs under `ai.security`.

**Tests.** `tests/Unit/Services/Security/` — note `TenantScopingInvariantTest.php` and
`TenantScopingLeakTest.php` pin the boundary — plus `tests/Unit/Security/CypherInjectionTest.php`.
Deep dives: `layers/12-security-resilience.md`, `docs/audit/AST-tenant-scoping-design.md`.

## Stage 6 — Execution

**What/why.** Runs the scoped query against Neo4j with belt-and-braces safety: read-only
enforcement (write-keyword rejection, `ai.query_execution.read_only_mode` default true),
rate limiting, timeouts, result formatting, and an audit row per query.

**Key classes.**
- `src/Services/QueryExecutor.php` — the orchestrating executor (security injection lives
  here too, see stage 5).
- `src/GraphStore/BoltNeo4jStore.php` (+ `Neo4jStoreFactory.php`, scheme-selected) — the
  driver; requires Neo4j 5.x (`elementId()` at `:78`).
- `src/Models/AiQueryLog.php` — per-query log (also feeds `ai:eval --from-logs`).
- `src/Services/Resilience/RateLimiter.php` — `ai.rate_limits.queries_per_minute`.

**Contract.** In: scoped Cypher + parameters + options (user, original question,
conversation id). Out: `QueryExecutionResult` (data rows, stats, errors); rows are then
enriched with entity actions and sensitive-data-filtered before the response stage
(`src/Services/AiManager.php:764-766`).

**Host extension.** `ai.query_execution` knobs (timeouts, limits, slow-query logging);
`NEO4J_*` env for the store. Verify wiring with:

```bash
php artisan ai:diagnose
php artisan ai:test-question "How many residents are there?" --debug
```

**Tests.** `tests/Unit/Services/QueryExecutorTest.php` (+ `...SecurityTest`,
`...TimeoutTest`, `...RateLimitTest`), `tests/Integration/GraphStore/`. Deep dive:
`layers/08-query-gen-exec-graph.md`.

## Stage 7 — Response generation (sections, grounding, abstention)

**What/why.** Turns rows + file snippets into a natural-language answer the user can trust.
The prompt is composed from priority-ordered, role-split sections — identity and security
policy in the system role, the untrusted question and data in the user role — so policy can
never be overridden by user text (`src/Services/ResponseGenerator.php:20-37`).

**Key classes.**
- `src/Services/ResponseGenerator.php` — `generate()` assembles `{system, user}` prompts and
  calls the LLM; consults the action gate once per turn (stage 8).
- `src/Services/ResponseSections/` — the sections: `IdentitySection`,
  `PrivacyAndSecurityGuidelinesSection`, `OriginalQuestionSection`, `ResultsDataSection`,
  `FileContextSection`, `ResponseEntityActionsSection`, `ResponseGuardrailsSection`, ...
- `src/Services/AssistantIdentity.php` — white-label name/persona/language policy.

**Grounding and abstention.** Knowledge questions skip Cypher *only when documents were
actually retrieved* — the documents are the grounding; with nothing retrieved the turn falls
through to the query path so an empty result becomes an explicit abstention, never a
confident ungrounded answer (`src/Services/AiManager.php:738-752`). Empty results route to
`generateEmptyResponse` (`src/Services/ResponseGenerator.php:322`), which keeps the
system-role policy (so even abstention never self-identifies the provider) and deliberately
omits the executed query (schema must not leak). Upstream, the chat layer marks such turns
`degraded` (`AskQuestionUseCase.php:176-178`).

**Contract.** In: `PipelineContext` + options (style, format). Out: `{answer, insights,
visualizations, format, metadata}` (metadata carries tool invocations on native-channel
turns).

**Host extension.** `ai.response_generator_sections` + the `HasInternalModules` API
(`addModule`/`replaceModule`/`extendAfter`) for custom sections implementing
`src/Contracts/ResponseSectionInterface.php`; styles and visibility knobs under
`ai.response_generation`; identity under `ai.identity`.

**Tests.** `tests/Unit/Services/ResponseGeneratorTest.php` (+ `...RoleSeparationTest`),
`tests/Unit/Services/ResponseSections/`, `tests/Unit/Services/AssistantIdentityTest.php`.
Deep dive: `layers/09-response-identity.md`.

## Stage 8 — Actions: two transports, one door, deferred approval

**What/why.** Lets the assistant *do* things — export, link to a record, propose a mutation
— with host-side, config-only effort. Two transports exist; both converge on the same
canonical value object and the same dispatch pipeline, so authorization and approval
semantics cannot diverge (invariant #3, single path; invariant #2, approval gate).

**Key classes (under `src/Services/Actions/` unless noted).**
- `AiActionRegistry.php` — resolves keys to `AiActionInterface` implementations registered
  via `ai.actions` (class-strings), `ai.entity_actions`, `ai.generic_actions`
  (config/ai.php:1183-1316).
- `ActionToolGate.php` — THE channel decision, once per turn: `None` for terse styles,
  `Native` iff `ai.actions.native_tools` is on AND the provider implements
  `ToolCallingLlmInterface` AND the relevance-scoped tool set is non-empty, else `Markdown`
  (loud warning on capability drift). Abstention turns never consult it (`:12-31`).
- `ToolLoopRunner.php` — the bounded native loop (≤ `ai.actions.tool_rounds`, default 2;
  ≤ `max_tool_calls` dispatches, default 5; one forced no-tools call closes prose).
- `ActionInvocation.php` — the canonical `(key, entityType, entityId, params, text)` unit
  both transports produce; only schema-filtered params are ever persisted.
- `ActionDispatcher.php` — the single door: filter params → fresh `authorize()` → read-only
  actions run `handle()` immediately; `mutates()` actions are *deferred* behind a signed
  `PendingActionToken.php` and returned as `ActionKind::Pending` (`:11-27`).

**The deferred-approval handshake.** The authoritative sequence diagram is
`docs/audit/94b-native-tool-calling-design.md` §2.1 — read it rather than this summary. The
essentials: in the native loop a mutating tool call **never executes**; the model receives a
tool result saying "proposed, NOT performed, user must confirm", the loop-time token is
discarded, and the filtered invocation is persisted on the assistant message
(`AskQuestionUseCase.php:146-153`). At **every render**, the invocation is re-dispatched —
fresh `authorize()`, fresh token, fresh TTL — and a Pending result becomes an
`ApprovalCard` (`src/Kompo/Actions/ApprovalCard.php`). The user's Confirm round-trips to
`MessagesQuery::approveAiAction` (`src/Kompo/MessagesQuery.php:395`) →
`ActionDispatcher::approve()`: HMAC → TTL → user binding → fresh `authorize()` → single-use
nonce → only then `handle()`. The markdown transport reaches the identical gate through its
link parser. **A mutating action never executes server-side before explicit user approval,
regardless of transport.**

**Contract.** In: the turn's `PipelineContext` + registry. Out: executed read-only results
(tables/exports/links as `ActionKind`-typed `AiActionResult`s) and persisted pending
invocations awaiting approval.

**Host extension.** Implement `AiActionInterface` (key, schema, `authorize()`, `mutates()`,
`handle()`) and list the class in `ai.actions`; or declare `entity_actions`/`generic_actions`
in config (`ModelMethodMutationAction` gives approval-gated model-method writes with zero new
code). Flip `AI_ACTION_NATIVE_TOOLS=true` only with a tool-capable provider.

**Tests.** `tests/Unit/Services/Actions/` (`ApprovalFlowTest`, `PendingActionTokenTest`,
`ToolLoopRunnerTest`, `ToolApprovalParityTest`, `ActionInvocationTest`),
`tests/Feature/ActionApprovalRoundTripTest.php`,
`tests/Feature/NativeToolActionRoundTripTest.php`. Deep dives: `layers/11-agentic-actions.md`,
`docs/audit/94-action-and-extensibility-abstraction.md`, `94b-native-tool-calling-design.md`.

## Stage 9 — Rendering: bubbles, cards, exports

**What/why.** The last mile: persisted messages become Kompo elements. Rendering is
re-entrant by design — every render re-derives clickable elements from persisted data and
re-authorizes, so a stale message can never grant stale authority.

**Key classes.**
- `src/Kompo/MessagesQuery.php` — message list, welcome/example questions, feedback /
  regenerate / edit endpoints, and the approval endpoint (`:395`).
- `src/Kompo/Support/MessageBubbleRenderer.php` — user/assistant bubbles + per-message
  action bar; memoizes per-request dispatch of persisted invocations by fingerprint.
- `src/Services/Response/ContentLinkProcessor.php` + `ActionLinkHandler.php` +
  `FileCitationHandler.php` — strip `action://` / `entity://` links and `[N]` citations out
  of the prose and hand them to handlers; `ActionResultPresenter.php` maps each
  `ActionKind` to the right Kompo element (chip, table, download, approval card).
- `src/Kompo/Support/RichDataRenderer.php` — tables/metrics/lists for row data;
  `src/Services/Chat/Exporter/` — conversation export (Markdown/PDF/Xlsx) behind
  `ExportConversationUseCase`.

**Contract.** In: persisted `AiMessage` rows (+ metadata: invocations, referenced files,
suggestions). Out: Kompo element trees. Theming comes from `ai.ui.factory` (default
`UserChatThemeFactory`, per-user DB settings, config/ai.php:1127).

**Host extension.** The scroll/injection JS is inlined from the package at render time
(`src/Kompo/AbstractChatSurface.php:187-188`), so `vendor:publish --tag=ai-assets` is
optional. Custom link types implement `src/Contracts/ContentLinkHandlerInterface.php`;
theming via `ai.ui.theme` / a custom theme factory.

**Tests.** `tests/Unit/Kompo/` (`MessageInvocationRenderTest`, `MessagesQueryActionLinksTest`),
`tests/Unit/Services/Response/`. Deep dives: `layers/02-kompo-ui-ux.md`,
`layers/10-response-enrichment-links.md`.

## The ingestion side — populating what retrieval reads

Everything above assumes Neo4j and Qdrant are already populated. That happens offline (setup
commands) and continuously (auto-sync on model events, `ai.auto_sync`, on by default and
**inline** unless `AI_AUTO_SYNC_QUEUE=true` — see `src/Jobs/`).

```
 entity catalog (config/entities.php, from ai:discover)
   |
   |   Nodeable model save / ai:ingest
   v
 DataIngestionService / BatchDataIngestionService
   |-- GraphPropertyMapper  --> Neo4j node (graph.properties only)
   |-- RelationshipSyncer   --> Neo4j edges (FK-declared only; ai:sync-relationships reconciles)
   |-- VectorPayloadBuilder + EmbeddingProvider --> Qdrant entity embedding
   |
 files (upload via FileProcessingPlugin, or ai:process-files batch)
   |-- FileExtractorRegistry: DocxExtractor / OcrPdfParser (pdfparser, then Tesseract OCR) / txt / md
   |-- SemanticChunker  --> overlapping chunks
   |-- QdrantChunkStore --> 'file_chunks' collection
   |
 physical docs (ai:ingest --docs) --> PhysicalFileIndexer --> 'documentation_chunks'
   |
 semantic indexes: ai:index-semantic / ai:index-context / ai:index-scopes
```

**Key classes.** `src/Services/DataIngestionService.php` /
`BatchDataIngestionService.php`; `src/Services/GraphPropertyMapper.php`;
`src/Services/RelationshipSyncer.php`; `src/Services/FileProcessor.php` +
`src/Services/FileExtractorRegistry.php` + `src/Services/Extractors/`;
`src/Services/SemanticChunker.php`; `src/Services/QdrantChunkStore.php`;
`src/Services/Files/PhysicalFileIndexer.php`; `src/Models/Plugins/FileProcessingPlugin.php`
(auto-attached to the host's FileModel, `src/AiServiceProvider.php:696-705`);
`src/Observers/RelatedModelSyncObserver.php`.

**The two sync laws — every config author must know these.**

1. **Only `graph.properties` sync.** A model column that is not listed in the entity's
   `graph.properties` never reaches its Neo4j node, no matter what `toArray()` returns:
   `GraphPropertyMapper::extractProperties` iterates only the configured property names
   (`src/Services/GraphPropertyMapper.php:29-56`), and `GraphConfig` rejects an empty list
   outright (`src/Domain/ValueObjects/GraphConfig.php:28-31`). If a question needs a column,
   put it in the catalog and re-ingest.
2. **Only FK-declared outgoing edge types materialize.** Edges are created exclusively from
   the catalog's relationship entries — foreign key + target label + type
   (`src/Services/RelationshipSyncer.php:44-77`). Anything not declared produces no edge;
   polymorphic (`morphTo`) relations get the sentinel `_polymorphic` target and are silently
   skipped on every row (`docs/audit/94c-polymorphic-edge-sync-design.md`). Bulk-ingest
   ordering gaps (target node not yet created) are reconciled afterwards:

   ```bash
   php artisan ai:sync-relationships
   ```

**The standard setup sequence.** (Run the steps individually — `ai:setup` currently dies at
its ingest step because it calls the unregistered name `ai:ingest-entities`,
`src/Console/Commands/SetupCommand.php:101` vs `IngestEntitiesCommand.php:36`; see doc 03 §11.)

```bash
php artisan ai:discover --enhance     # write/refresh config/entities.php
php artisan ai:validate-config
php artisan ai:ingest --fresh         # entities -> Neo4j nodes/edges + Qdrant embeddings
php artisan ai:sync-relationships     # reconcile edges skipped during bulk ingest
php artisan ai:index-context && php artisan ai:index-semantic && php artisan ai:index-scopes
php artisan ai:process-files          # embed existing database files
php artisan ai:diagnose               # verify config, Neo4j, Qdrant, LLM, embeddings
```

**Tests.** `tests/Unit/Services/DataIngestionServiceTest.php` (+ `...SecurityTest`),
`BatchDataIngestionServiceTest.php`, `GraphPropertyMapperTest.php`,
`RelationshipSyncerTest.php`, `tests/Unit/Services/Files/`, `tests/Integration/
FileProcessingPipelineTest.php`, `DualStorageCoordinationTest.php`. Deep dives:
`layers/06-file-ingestion.md`, `layers/14-discovery-config-commands.md`,
`layers/16-crosscutting.md`.

## Where to go next

- Shape of the whole system: `docs/audit/00-overview.md` (C4), then the relevant
  `docs/audit/layers/NN-*.md` for any box you touch.
- Quality bar before you call anything done: `docs/audit/10-of-10-acceptance-rubric.md`.
- Run a single test file (the only supported invocation — full suite segfaults):

  ```bash
  php -d opcache.enable_cli=0 vendor/bin/phpunit tests/Unit/Services/QueryGeneratorTest.php --no-coverage
  ```
