# Contracts & Abstraction Review

> Synthesis of 17 lane audits + adversarial verification, focused on the package's
> abstraction seams: the 23 interfaces in `src/Contracts/`, the parallel `RerankerInterface`
> seam, the two god-classes (`ContextRetriever` 1686L, `AiManager` 1118L), and the three
> extensibility surfaces that decide whether this package is reusable across CondoEdge,
> ActiviteDeFinancement, and Sisc: **actions**, **page-context**, and **identity/persona**.
>
> Scope rule: a finding is reported as solid only if verification did **not** refute it.
> Items the verifier downgraded are flagged inline as `NEEDS NUANCE`; refuted ones are
> labelled `FALSE POSITIVE` and excluded from recommendations.

The one-line verdict: **the package's abstraction *vocabulary* is better than its abstraction
*discipline*.** The instinct for *where* to put seams is good; the discipline on *typing* them
and *deleting* dead ones is not. The single most-requested "any project" seam — the agentic
action system — already exists and is genuinely well-designed; the work there is hardening and
de-duplicating, not designing from scratch.

---

## Contract inventory

23 interfaces in `src/Contracts/` plus `RerankerInterface` in `Services/Retrieval`. The
dominant defect across them is **"stringly/array-typed contracts sitting next to typed DTOs
that already exist"** — six interfaces return `array` and the caller then hand-rebuilds a DTO
from string keys. A handful of interfaces are dead or leaky.

Legend: **Sound** (keep) · **Leaky** (abstraction promises more than it delivers, or leaks an
implementation detail) · **Over-abstracted** (ceremony exceeding value) · **Redundant**
(duplicate/dead) · **Missing** (a seam that should exist but doesn't).

| Interface | Implementers | Depended on by | Verdict | Why |
|---|---|---|---|---|
| `AiActionInterface` | `AbstractBuiltInAction` (+5 built-ins), `ConfigAiAction`; test `SpyAction` | `ActionDispatcher`, `AiActionRegistry`, `ActionLinkHandler` | **Leaky / over-abstracted** | Strong model-agnostic shape, but `presentation()` is **dead ceremony** (read nowhere; deferral is decided by sniffing `['pending'=>true]`, not `presentation()==='approval'` — L11-4 CONFIRMED) and `handle(): mixed` forces every consumer to type-sniff the return (L11-7 CONFIRMED). Approval half has **zero real producers** (L11-1 CONFIRMED). |
| `AiAuthAdapterInterface` | `KompoAuthAdapter` (only binding) | `AiSecurityService`, `AiConversationPolicy` (should) | **Sound** | The one genuinely good abstraction in the security layer. Defect is the *binding* — `KompoAuthAdapter` hard-depends on undeclared `kompo/auth` (`globalSecurityBypass()`/`Permission`) and fatals standalone (L12-1 CONFIRMED P0) — not the contract. Needs a `NullAuthAdapter` fail-closed default. |
| `ChunkStoreInterface` | `QdrantChunkStore` | `FileProcessor` | **Leaky** | `getChunk()`/`getChunkCount()` are **dead**; `getChunkCount()` is the only method missing the `?collection` param every sibling has — would silently query the wrong collection if revived (L6-6 CONFIRMED). |
| `ContentLinkHandlerInterface` | `ActionLinkHandler`, `FileCitationHandler` (via `AbstractContentLinkHandler`) | `ContentLinkProcessor` | **Leaky** | Too thin to express strip-only / metadata-emitting handlers, so `ContentLinkProcessor` special-cases `FileCitationHandler` **by concrete type**, defeating the extension point (L10-13 CONFIRMED). Needs `rendersInline()` / `metadata()`. |
| `ContextRetrieverInterface` | `ContextRetriever` (1686L) | `AiManager`, `PipelineEvalDriver` | **Leaky** | `retrieveContext()` correctly returns `PipelineContext`, but the docblock still claims `@return array` (L4-15 CONFIRMED) and `searchSimilar(string $collection)` / `getExampleEntities(string $label)` disagree with the `AiManager` facade that passes arrays — a **strict_types TypeError** on the documented public path (L4-5 / L4-6 CONFIRMED; latent — no internal caller). |
| `DataIngestionServiceInterface` | `DataIngestionService`, `BatchDataIngestionService` | `AiManager`, model events, observers, ingest commands | **Sound (contract) / leaky (impls)** | Well-documented. But the interface is bound to the **plain** service for most callers while the "batch decorator" only decorates single-entity methods and re-implements `ingestBatch`/`syncRelationships`, so the impls diverge on PII stripping + owner metadata (L16-1 / L16-4 CONFIRMED). Impl reads `getAttribute()`/`$sensibleColumns` not on the `Nodeable` contract (L16-3 CONFIRMED). |
| `EmbeddingProviderInterface` | OpenAi, Anthropic (throwing stub), `MemoizedEmbeddingProvider` | `FileProcessor`, `QdrantChunkStore`, ingestion, indexers | **Leaky** | `getMaxLength()` has no callers and is **unit-ambiguous** (OpenAI returns 8191 *tokens*, Anthropic 200000, both docblocked "characters" — L13-6 CONFIRMED). Right shape otherwise; the Anthropic impl is a config-selectable throwing placeholder (L13-7 CONFIRMED). |
| `EntityAccessCheckerInterface` | **none** | **none** | **Redundant — DELETE** | Zero impls/bindings/callers (verified). Superseded by `FileAccessResolverInterface::canAccessFile` (different signature). Advertises node-level access control that **does not exist** — filtering is team-grain only (L12-2 CONFIRMED). |
| `FileAccessResolverInterface` | `FileAccessResolver` | `FileContextProvider` | **Sound (contract)** | Right-sized seam. The risk is the impl/default scope, not the contract: ignores `access_scope` config (L5-2), dead audit-log path (L5-3), and the shipped default `accessibleBy` scope is a team-grain OR-filter — a per-team leak for any host without a custom scope (L5-1 CONFIRMED P0). |
| `FileChunkerInterface` | `SemanticChunker` | `FileProcessor` | **Sound** | Small, focused, right-sized. |
| `FileContextEnricherInterface` | `DefaultFileContextEnricher` (pass-through) | `FileContextProvider` | **Sound** | Correct host-extension seam with a no-op default. Nothing to change. |
| `FileExtractorInterface` | Pdf/Text/Docx/Markdown extractors | `FileExtractorRegistry` | **Leaky** | `extractMetadata()` is dead (no consumer; `PdfExtractor` re-parses the file twice for output nobody reads — L6-7 CONFIRMED). Drop it or route metadata into `FileChunk::metadata`. |
| `FileProcessorInterface` | `FileProcessor` | plugin, `ProcessFileJob`, commands | **Leaky** | Docblock advertises an `async` option `processFile()` never reads — the plugin owns async (L6-16 CONFIRMED). Remove from the doc. |
| `GraphStoreInterface` | `BoltNeo4jStore`, `Neo4jStore` (HTTP) | `QueryExecutor`, ingestion, discovery | **Leaky + over-abstracted** | Doc claims portability to "ArangoDB/OrientDB" while `query(string $cypher)` + `queryInTransaction` are **Cypher-specific** — no non-Cypher store can back it (L8-18 CONFIRMED). The 4 transaction methods are **dead** in `src/` (~250 LOC across two divergent impls; ingestion does manual compensation — L8-6 CONFIRMED). |
| `LlmProviderInterface` | `OpenAiLlmProvider`, `AnthropicLlmProvider` | generators, QU, reranker, judge, enhancer | **Sound** | Right-sized. `countTokens` is facade-only but cheap. The ~70% copy-paste is in the *impls*, not the contract (L13-12) — extract an `AbstractHttpLlmProvider`. |
| `PdfParserInterface` | `OcrPdfParser`, `PdfExtractor` path | extractor | **Sound** | Minimal; `pageLimit` is unreachable from callers (L6-10) but the contract is fine. |
| `PromptSectionInterface` (extends `SectionModuleInterface`) | `BasePromptSection` + ~17 sections | `SemanticPromptBuilder` / `HasInternalModules` | **Sound** | Clean basis of the priority-ordered, role-split section pipeline. Stale priority docblock (omits current_user 17, name_resolution 18, file_context 45, … — L7 note). |
| `QueryExecutorInterface` | `QueryExecutor` (863L) | `AiManager` | **Leaky** | Returns `array` instead of the existing `QueryExecutionResult` DTO (L8-11). `cancel()` is dead (references `dbms.killQuery` with a `$queryId` nothing produces); `explain()`/`test()` are thin facade-only surface (L8-7 NEEDS NUANCE — they *do* have AiManager pass-through callers, so "zero src callers" was overstated). |
| `QueryGeneratorInterface` | `QueryGenerator` (670L) | `AiManager` | **Leaky** | Returns `array` not `QueryGenerationResult` (L8-11). `sanitize()` is a regex butcher exposed via the facade as a false safety net, unused in the real path (L8-8 CONFIRMED). |
| `ResponseGeneratorInterface` | `ResponseGenerator` (648L) | `AiManager` | **Leaky** | Returns `array` not a DTO. `generateEmptyResponse()` is **dead** — abstention never fired (L9-1 CONFIRMED); `summarize()` is unused-but-published API (L9-7 NEEDS NUANCE — deletion must also touch the interface); `suggestVisualizations(array,string)` is non-nullable in the contract but nullable in impl/caller (L9-3 CONFIRMED). |
| `ResponseSectionInterface` | `BaseResponseSection` + ~18 sections | `ResponseGenerator` | **Sound** | Clean; mirrors the prompt-section seam. Role is a bare magic string compared `=== 'system'` (L9-11) — wants a `PromptRole` enum. |
| `SectionModuleInterface` | base of the section interfaces | section pipelines | **Sound** | Minimal, correct; `role()` is the basis of the system/user split. |
| `VectorStoreInterface` | `QdrantStore` | retriever, indexers, chunk store | **Sound** | Comprehensive (16 methods), cohesive. Could later split a read-only sub-interface. Impl leaks (no TLS, blind auto-create — L13-8/9) are impl bugs, not contract defects. |
| `RerankerInterface` *(Services/Retrieval)* | `LlmReranker`, `NullReranker` | `FileContextProvider` only | **Sound — best-specified contract in the package** | Documents a never-throw + input-order-fallback guarantee, both impls honor it. The defect is **wiring**: reaches only file search, not the graph/RAG path (L13-5 / L4-8 CONFIRMED), and the on/off decision is duplicated three times (L13-4 CONFIRMED). |

### Cross-cutting contract patterns

1. **The array-return tax (biggest single theme).** `QueryGenerator`, `QueryExecutor`,
   `ResponseGenerator`, and `ContextRetriever::searchSimilar` all return `array` while
   `QueryGenerationResult`, `QueryExecutionResult`, and `PipelineContext` already exist as
   readonly DTOs. `AiManager` then **hand-rebuilds** the DTOs from phpdoc'd string keys
   (`AiManager.php:877-890`). Two representations of the same data, kept in sync by hand,
   already drifting. **Make the interfaces return the DTOs and delete the array contract.**

2. **Dead/ceremony surface.** `EntityAccessCheckerInterface` (whole interface),
   `GraphStoreInterface` transaction quartet, `ChunkStoreInterface::getChunk*`,
   `FileExtractorInterface::extractMetadata`, `AiActionInterface::presentation()`,
   `ResponseGeneratorInterface::generateEmptyResponse/summarize`, and
   `QueryExecutorInterface::cancel` are all interface members nothing in `src/` exercises.
   They *imply capabilities that don't exist* (node-level ACL, transactional ingestion,
   abstention) — worse than mere dead code.

3. **Leaky portability claims.** `GraphStoreInterface` says Arango/Orient but is Cypher-only.
   Harmless until someone trusts it; narrow the doc to "Cypher graph stores" or split a
   `CypherExecutorInterface` from the generic CRUD.

4. **What's genuinely good.** `RerankerInterface`, `AiAuthAdapterInterface`,
   `FileContextEnricherInterface`, `FileAccessResolverInterface`, `FileChunkerInterface`, and
   the `PromptSection`/`ResponseSection`/`SectionModule` family are right-sized, swappable
   seams.

---

## Coupling & cohesion

Two god-classes dominate the coupling map. Both are "everything that touches a phase lives in
one file" classes that started cohesive and accreted responsibilities. The shared pattern: a
thin public method that is the *real* path, surrounded by dead/duplicate/parallel-shape
machinery.

### `ContextRetriever` — 1686 lines, ~13 responsibilities (primary split target)

Holds QU orchestration, semantic-selection merge, similar-query search, three schema-filter
variants, example fetch, **two** metadata builders (one doing forbidden `stripos` substring
matching — L4-9 CONFIRMED), quality scoring, token estimation, budget logic, confidence math,
and relationship weighting. ~450 lines is a **dead "legacy array" cluster** with no production
callers that returns a different shape than `PipelineContext` (`getMinimalContext`,
`getContextWithStats/Budget`, `getContextConfidence`, `filterRelationshipsByImportance`,
`getAllEntityMetadata`, `computeRetrievalConfidence` — L4-7 CONFIRMED). ~10 inline
`config('ai...')` reads make it impossible to construct without the container (L4-16).

**Proposed decomposition** (target: `ContextRetriever` < 200L orchestrator):

| New collaborator | Owns | Replaces |
|---|---|---|
| `SimilarQuerySearcher` | embed → vector search → (de)parameterize few-shot pairs | inline similar-query code |
| `SchemaProjector` | `filterSchemaByRelevance` / `filterSchemaByLabels` / `findRelevantEntityLabels` | 3 schema-filter variants |
| `EntityMetadataBuilder` | `buildMetadataFromSemanticSelection` as the **single** path | the two builders + the `stripos` fallback (delete it or make it embed-based with a degraded flag) |
| `ContextQualityAssessor` | quality scoring + calibrated confidence | quality/confidence math |
| `RetrievalConfig` (typed VO) | thresholds/weights/top_k resolved once | scattered `config()` reads |

Delete the dead budget/stats/minimal cluster outright (or extract a `TokenBudgeter` behind an
interface and actually wire `computeRetrievalConfidence` into a rephrase/abstain loop).

### `AiManager` — 1118 lines, ~40–50% dead facade pass-throughs (split target)

The genuinely-used core is `answerQuestion()` + `generateAndExecute` /
`retrieveAndSetFileContext` / `buildFinalResponse`. Around it sits:

- **~15 facade pass-throughs with zero internal callers** (`searchSimilar`, `chatJson`,
  `stream`, `sanitizeQuery`, `executeCount/Paginated`, `extractInsights`,
  `suggestVisualizations`, …) — consumers hit the underlying services directly. *The lane's
  "~50%" figure was walked back to ~40% by the verifier — substantial either way* (L0-4
  CONFIRMED).
- **2 dead duplicate pipeline entries** `ask()` / `askQuestion()` that have drifted from
  `answerQuestion()` and omit file/conversation context (L0-3 CONFIRMED) — still on the public
  facade, so they're a usable footgun.
- A **missing method that breaks a feature**: `AI::syncRelationships()` is called by the sync
  observer but doesn't exist on `AiManager` (L0-1 CONFIRMED; it fails silently inside the
  observer's catch, so related-model sync is quietly broken rather than hard-crashing).
- A **type-mismatch wrapper** `getExampleEntities(array)` → string contract (L0-2 CONFIRMED).
- A **security-named no-op** `filterSensitiveResults()` on the hot path (L0-5 CONFIRMED).
- **Identity coupling**: query (de)parameterization reads `auth()->id()`/`currentTeamId()`
  statically instead of the threaded `$options['user']` (L0-7 NEEDS NUANCE — real CLI/team-id
  coupling, but the verifier refuted the "cross-user leak / auth null in CLI" framing since
  `auth()->login()` is called; reclassify **P2 architecture**, not P1 security).

**Proposed decomposition:**

| New collaborator | Owns |
|---|---|
| `AiOrchestrator` (thin) | `answerQuestion()` + the 5-step pipeline; the *single* canonical entry |
| `QueryParameterizer` (identity-aware) | (de)parameterization, fed a `PipelineIdentity` from `$options['user']` |
| `FileContextRetriever` | the multi-query file fan-out **currently triplicated** across `AiManager`, `TestQuestionCommand`, and `PipelineEvalDriver` (L0-8 / L15-8 CONFIRMED) so eval/test measure production |
| *(delete)* | the ~15 dead pass-throughs and 2 dead pipeline entries; low-level consumers inject the specific interface instead of a 40-method god-facade |

### Other coupling hotspots (smaller, real)

- **`QueryExecutor` (863L)** — split a `SecurityFilterInjector` (entity/alias extraction +
  team-filter splice) and a `ResultFormatter` out of the runner. The injector is where the two
  **P0 tenant-scoping bugs** live: regex-based primary-entity detection misses label-free
  primaries (L8-1 CONFIRMED) and `AND`-appending onto an `OR` head WHERE without parens leaks
  across the OR branch (L8-14 CONFIRMED). The right fix is AST/known-entity scoping, not regex.
- **`MessagesQuery` (736L)** and **`ResponseGenerator`/`QueryGenerator`** are giant and SRP-
  violating the same way (rendering + actions + rich-data; generation + dead analytics engine).
  Covered in their lane docs; the abstraction lesson is identical — extract the genuinely-used
  core, delete the parallel dead shape.
- **`PipelineContext` god-DTO** — 13 public-mutable fields, 7 loosely-typed arrays mutated in
  place by every stage, no invariants; `fileContext` is sometimes a flat list, sometimes
  `{relevant_files,…}` (L0-9). Introduce `FileContext`/`ConversationContext` sub-DTOs and
  intent-revealing setters so stages can't stomp arbitrary fields.

---

## The extensibility seams that matter for "any project"

This section decides whether the package is genuinely reusable. The goal: a host (CondoEdge,
ActiviteDeFinancement, Sisc) adds capabilities **with config + a class, no service-provider
surgery.** Today the foundations exist for actions, but two of the three seams (page-context,
persona) are partial or missing, and the action seam runs through a brittle markdown-regex
grammar instead of structured tool-calling.

### (a) The agentic ACTION system

**Current state (verified):** The action layer *is* wired end-to-end and is the cleanest
extensibility surface in the package. `AiActionInterface` → `AiActionRegistry` →
`ActionDispatcher` (validate → authorize → run-or-defer) → `PendingActionToken` (HMAC-signed)
→ `ApprovalCard` → `MessagesQuery::approveAiAction` is a real round-trip with a genuine
**second server-side `authorize()` pass** before any mutating `handle()` runs (confirmed by
reading `ActionDispatcher::approve()`). That design is sound and worth keeping.

**But the centerpiece is unproven and brittle:**

- **Invocation rides on LLM-authored markdown regex** (`[text](entity://Type/id/key?query)`),
  not native tool-calling. One stray space / encoded char / nested paren silently fails into a
  try/catch (L10-15 / L11 CONFIRMED). The model is also told only `key + label + aliases` —
  `description` and `inputSchema` are dropped before reaching the prompt.
- **The write-approval gate has zero real producers** — no built-in or shipped-config action
  returns `requiresApproval()===true` (only a test `SpyAction`), and there is no booted-app
  feature test of the `ApprovalCard → approve → handle` round-trip (L11-1 CONFIRMED). The
  headline value prop is theoretical; hosts have no mutating action to copy.
- **`PendingActionToken` has no expiry, no single-use, and `belongsTo(null)` matches anyone**
  (L11-6 CONFIRMED) — a non-idempotent "charge $450" can be replayed.
- **Two disjoint metadata paths**: the response side reads the registry (built-ins visible),
  but `EntityActionAwarenessSection` reads **raw config**, so the query generator is never told
  `export_excel`/`render_table` exist and "export this to excel" can mis-route to Cypher
  (L11-2 CONFIRMED). `relevantActions()` is dead (L11-3), `presentation()` is dead ceremony
  (L11-4), `handle(): mixed` forces consumer type-sniffing (L11-7).
- **The approval card's i18n keys don't exist** → the Confirm button renders the literal key
  string `ai.actions.confirm` (L11-5 CONFIRMED).

**Recommended canonical design** — minimal contract, structured invocation, hardened gate:

```php
interface AiAction
{
    public function key(): string;                 // stable machine key
    public function label(): string;               // human label (LLM + UI)
    public function description(): string;          // advertised to the LLM
    public function aliases(): array;               // NL trigger phrases
    public function inputSchema(): array;           // typed param schema (filters params)
    public function mutates(): bool;                // replaces requiresApproval() — clearer intent
    public function authorize(mixed $user, array $input, array $context): bool;
    public function handle(array $input, ActionContext $context): AiActionResult; // typed, not mixed
}
```

Changes from today, each tied to a verified finding:

1. **Native tool-calling path.** Expose the registry's `key/description/inputSchema` as provider
   tool/function definitions (OpenAI tools, Anthropic tool-use) so capable models invoke actions
   *structurally*. Keep the markdown-link grammar only as a legacy fallback. This eliminates the
   entire class of L10-15/L11 regex-fragility findings and fixes the "LLM never sees
   description/schema" gap.
2. **Drop `presentation()`** (dead — L11-4) and **narrow `handle()` to `AiActionResult` with a
   typed `kind` enum** (`element|table|export|pending|error`) so consumers switch on a field,
   not `is_array(...) && isset(...)` (L11-7). Move Kompo element construction into one presenter
   keyed off `kind`, decoupling action logic from Kompo so actions are reusable in non-Kompo
   hosts (L11-8).
3. **Rename `requiresApproval()` → `mutates()`** and make the registry the **single metadata
   source** every prompt/enrichment consumer reads (closes L11-2/L11-3). Wire that one source
   into both the query-side awareness section and the response-side enricher.
4. **Harden `PendingActionToken`**: TTL in `decode()`, single-use nonce, reject `userId===null`
   in authenticated contexts (closes L11-6).
5. **Config-only registration ergonomics**: a documented `config('ai.actions')` array of action
   class-strings the registry auto-registers, so:
   - CondoEdge: `ExportFileAction::class`, `OpenFileAction::class` (read-only, inline)
   - ActiviteDeFinancement: `CreateCampaignAction::class` with `mutates()===true` + an
     `authorize()` closure → automatically routed through the approval gate
   - Sisc: `CreateEventAction::class` with `mutates()===true`

   …all with **no service-provider code**. Ship **one real mutating reference action** + a
   booted-app feature test (closes L11-1) so hosts have a write-action to copy.

**Over-abstracted / to remove here:** `presentation()` (dead), `relevantActions()` (dead),
`handle(): mixed` (replace with typed result), and the dual registry-vs-raw-config metadata read
(collapse to registry).

### (b) Page-context awareness (assistant knows which screen it was opened from)

**Current state (verified):** **Missing.** There is no `page_context` anywhere in the pipeline;
`AiChatPanel`/`AiChatFloating`'s documented `context` prop is never read (L2-1 CONFIRMED),
`AskQuestionInput` has no slot for it (confirmed by reading the DTO — its fields are
`question/conversation/style/user/allowAnonymous` only), and `PipelineContext` has no field. The
assistant cannot know it was opened from "Unit #402's ledger" — a core agentic gap.

**Recommended minimal design** — thread a typed, host-supplied context object end to end:

```php
final class PageContext
{
    public function __construct(
        public readonly ?string $route = null,      // e.g. 'units.show'
        public readonly ?string $entityType = null, // e.g. 'Unit'
        public readonly mixed   $entityId = null,    // e.g. 402
        public readonly array   $attributes = [],    // small, whitelisted, NON-PII screen state
    ) {}
}
```

Threading (each hop already exists; only the field is new):

1. `AiChatPanel`/`AiChatFloating` accept a `page_context` prop (host fills it from the screen).
2. `ChatMessageForm` → `ChatPresenter::handleSend` → **add `PageContext` to `AskQuestionInput`.**
3. `AskQuestionUseCase` → `AiManager::answerQuestion` options → onto a new
   **`PipelineContext::$pageContext`** field.
4. A new conditional `PageContextSection` (prompt-side) emits, only when present, a small
   spotlighted block: "The user is viewing **Unit 402**." This biases entity selection and
   resolves "this unit" / "here" deixis that QU currently can't.

**Guardrails (so it stays safe + reusable):** `PageContext::$attributes` must be a small,
host-whitelisted, **non-PII** map — it rides into a third-party LLM prompt. Do **not** let hosts
dump arbitrary model state. This is also the natural place to fix the existing PII leak
(`CurrentUserContextSection` emits user email by default — L7-7 CONFIRMED) by gating identity
fields behind `ai.prompt.include_user_pii` (default off).

**Over-abstracted / to remove:** nothing exists yet — build it minimal. Resist making
`PageContext` a generic bag; the four typed fields cover deixis resolution and entity biasing,
which is the entire job.

### (c) Project identity / persona

**Current state (verified): the best-shaped seam of the three.** `AssistantIdentity` is a clean,
fully config-driven renderer (`name`, `project`, `persona`, `nonDisclosure`, `languagePolicy`)
with `{name}`/`{project}` interpolation, consumed by every prompt path through the SYSTEM role
(L7/L9 confirm identity rides the real system message and the old "complete-with-null-system"
bug is fixed). Nothing here is condo- or provider-specific. (Confirmed by reading
`AssistantIdentity` directly.)

**Minor gaps, not redesigns:**

- One hardcoded English role note ("Internally, you translate the user's questions into a graph
  query language…") is concatenated after the config identity in the query-side SYSTEM message —
  the one spot the model-agnostic identity gets a non-localized literal (L7-14). Move it to
  `ai.identity.query_role_note`.
- `AssistantIdentity` is **all static**. For per-tenant personas (CondoEdge vs
  ActiviteDeFinancement in the same deployment) it should become an injectable
  `IdentityProviderInterface` resolving from a request/tenant scope, not global `config()`. Low
  priority — config-driven is fine for the common single-tenant case.

**Over-abstracted / to remove:** nothing. This seam is right-sized; leave it almost as-is.

---

## What to ADD vs what to DELETE

### ADD

1. **DTO-first contracts.** `QueryGeneratorInterface::generate → QueryGenerationResult`,
   `QueryExecutorInterface::execute → QueryExecutionResult`,
   `ResponseGeneratorInterface::generate → (a Response DTO)`,
   `ContextRetrieverInterface` docblock → `PipelineContext`. Delete the loose-array contracts;
   stop hand-rebuilding DTOs in `AiManager`. (L8-11, L4-15, L9-3)
2. **A typed `AiAction` with structured tool-calling** (design above): drop `presentation()`,
   `handle(): AiActionResult` with a `kind` enum, `mutates()`, registry as single metadata
   source, `config('ai.actions')` auto-registration, **one real mutating reference action +
   feature test**, and a hardened `PendingActionToken` (TTL + single-use + non-null binding).
   (L11-1/2/3/4/5/6/7/8, L10-15)
3. **`PageContext` DTO + `PipelineContext::$pageContext` + `PageContextSection`**, threaded
   `AiChatPanel → AskQuestionInput → AiManager`, with a non-PII whitelist guardrail. (L2-1)
4. **A fail-closed `NullAuthAdapter`** as the default `AiAuthAdapterInterface` binding; bind
   `KompoAuthAdapter` only when `kompo/auth` is detected; add a no-kompo-auth boot test.
   (L12-1 P0)
5. **`rendersInline()` / `metadata()` on `ContentLinkHandlerInterface`** so handlers plug in
   without `ContentLinkProcessor` special-casing concrete types. (L10-13)
6. **A `PromptRole` enum** for section `role()` so the system/user boundary is type-checked (a
   typo can't silently demote identity/security to the user role). (L9-11)
7. **`RerankerInterface` wired into the graph/RAG path** (over-fetch → rerank → cut), with the
   on/off decision in exactly one place (always call `rerank()`; the contract guarantees a safe
   fallback). (L13-4/5, L4-8)
8. **A `PipelineIdentity` actor object** threaded from `$options['user']` into
   (de)parameterization and the executor, eliminating every static
   `auth()->id()/currentTeamId()` so CLI/queue/impersonation/eval behave correctly.
   (L0-7, L8-15)
9. **Typed `FileContext`/`ConversationContext` sub-DTOs** with one canonical `fileContext`
   shape + a builder, so stages can't stomp arbitrary fields on `PipelineContext`. (L0-9)
10. **A single `FileContextRetriever` service** owning the multi-query fan-out, injected into
    `AiManager`, `TestQuestionCommand`, and `PipelineEvalDriver` so eval measures production.
    (L0-8, L15-8)

### DELETE

1. **`EntityAccessCheckerInterface`** — whole interface; zero impls/callers/bindings; implies
   node-level ACL that doesn't exist. (L12-2)
2. **`GraphStoreInterface` transaction quartet** (`beginTransaction`/`commit`/`rollback`/
   `queryInTransaction`) from the interface and both stores — ~250 LOC of dead, divergent,
   false transactional-safety — OR actually wire ingestion to use it. (L8-6)
3. **`ChunkStoreInterface::getChunk` + `getChunkCount`** — dead, and `getChunkCount` is
   collection-unsafe. (L6-6)
4. **`FileExtractorInterface::extractMetadata`** (+ per-extractor impls; `PdfExtractor`'s
   double-parse) — dead; or route metadata into `FileChunk::metadata`. (L6-7)
5. **`AiActionInterface::presentation()`** and **`AiActionRegistry::relevantActions()`** — dead
   ceremony. (L11-4, L11-3)
6. **`ResponseGeneratorInterface::generateEmptyResponse`** (+ its 4 orphaned lang keys) and the
   dead `QueryInfoSection` (schema-leak footgun, still recommended in published config examples)
   — wire abstention or delete. (L9-1, L9-6)
7. **`QueryExecutorInterface::cancel`** (dead, broken `$queryId` contract) and
   **`QueryGenerator::sanitize` + `AiManager::sanitizeQuery`** (regex butcher, false safety net)
   — rely on `validate()` + the executor read-only gate as the single path. (L8-7, L8-8)
8. **The 2 dead pipeline entries `AiManager::ask()/askQuestion()`** and the **~15 dead facade
   pass-throughs** — keep only the genuinely-used surface; consumers inject the specific
   interface for the rest. (L0-3, L0-4)
9. **`AiManager::filterSensitiveResults()`** — security-named no-op on the live path; remove
   until `AiAccessGate` exists. (L0-5)
10. **`HasMethodsAsProperties` trait + the silent-null magic accessor** (root cause of the
    `link_hover` null bug) and the dead `TypingIndicator` class — call explicit `theme*()`
    methods; centralize one animation renderer. (L2-12/L2-3, L2-4)

### Do NOT touch (verified good)

`RerankerInterface` and its impls, `AiAuthAdapterInterface` (the contract), `AssistantIdentity`
(persona seam), `FileContextEnricherInterface`, `FileAccessResolverInterface` (the contract),
`FileChunkerInterface`, the `PromptSection`/`ResponseSection`/`SectionModule` family,
`ChatSecurityGuard`, `ChatPresenter`, `TeamPathResolver`, and `LlmReranker`. These are the
package's good bones — the work is typing the weak contracts, deleting the dead surface, and
finishing the three extensibility seams (structured actions, page-context, injectable persona).

### Verification caveats carried forward

- **L0-7** (identity in (de)parameterization) — keep as **P2 architecture**, not P1 security;
  the verifier refuted the cross-user-leak / CLI-null framing (`auth()->login()` is called).
- **L8-7** (`explain/test/cancel` "zero callers") — only `cancel()` is truly dead; `explain()`
  and `test()` have `AiManager` facade pass-through callers. Scope the deletion to `cancel()`.
- **L9-7** (`summarize()`) — unused-but-published API, so removal must also touch
  `ResponseGeneratorInterface`, not just the impl.
- **L2-9** (`fileReferenceCard` "punning array into model") — **FALSE POSITIVE**: passing the
  model to `getPreviewButton` is correct; at most a P3 param-shadowing nit. Excluded from the
  recommendations above.
