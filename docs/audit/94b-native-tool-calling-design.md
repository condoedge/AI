# Native Provider Tool-Calling (P1-C1) — Final Design

This is the execution-ready design for roadmap item **P1-C1**: expose registered AI actions as native provider tool/function definitions as the primary invocation channel, demoting the markdown `action://` / `entity://` link grammar to the legacy fallback — exactly the directive in `94-action-and-extensibility-abstraction.md:110-112` and `92-rag-research.md:24` ("adapt a returned `tool_call` into the same `(action, params, ctx)` triple. Keep markdown as fallback.").

It is the synthesis of a three-design panel with three independent judge verdicts. The winning skeleton (unanimous, 3/3) is the **ActionInvocation choke point** design: both transports — native tool calls and the markdown regex — converge on one canonical value object before touching the shared dispatch/token/approve pipeline. Grafted from the runner-ups, where judges explicitly praised them: **no tools on abstention turns** and **deterministic mid-loop composition** (Design 0); the **default-off kill switch** (Design 2); **persist filtered params only, never raw model args** (Design 0). Every judge-flagged weakness is dispositioned in §9 — fixed in the design or accepted with a rationale; none silently dropped. Every `file:line` below was re-verified against current source while writing this document.

**Inviolable invariant:** a mutating action NEVER executes server-side before explicit user approval — regardless of which transport proposed it.

---

## 0. Ground truth (what exists today, verified)

| Concern | Where | Fact |
|---|---|---|
| Provider contract | `src/Contracts/LlmProviderInterface.php:21` | `chat(array $messages, array $options): string` — text in, text out; zero tool awareness anywhere in the contract |
| Shared HTTP base | `src/LlmProviders/AbstractHttpLlmProvider.php:57-62, 133-136` | `chat()` = `sendRequest(shapeRequest(...))` → `extractTextResponse(...)`; every request rides circuit breaker + retry (`withResilience`, `:275-280`); the breaker is named `'llm'` (`:287`) and shared across providers; retry covers only `TransientLlmException` (`:304-310`); provider hooks are `shapeRequest` (`:548`) and `extractTextResponse` (`:555`) |
| OpenAI shaping | `src/LlmProviders/OpenAiLlmProvider.php:44-62` | Optional keys pass through (`response_format`, `:53-55`); **`extractTextResponse` throws when `choices[0].message.content` is missing (`:66-68`) — a tool-calls-only response has `content: null`, so today's `chat()` cannot survive a tool call** |
| Anthropic shaping | `src/LlmProviders/AnthropicLlmProvider.php:50-86` | System prompt hoisted to the `system` field (`:56-66`); **`extractTextResponse` reads only `content[0]['text']` (`:88-95`) — a `tool_use` block at index 0 throws today** |
| Answer generation | `src/Services/ResponseGenerator.php:153` | One single-shot `$this->llm->complete(...)` for grounded answers; prompts assembled by role-split sections (`buildPrompts`, `:198-226`); abstention path `generateEmptyResponse` (`:236-297`) carries a markdown actions hint (`:305-330`) |
| Action advertisement | `src/Services/ResponseSections/ResponseEntityActionsSection.php:71-133` | Teaches the link grammar + per-row ID enumeration (`:95-105`) + generic block (`:110-122`) + CRITICAL ID rules (`:124-130`); suppressed for terse styles (`:55-57`); metadata source `EntityAutoDiscovery::getEntityActions()` drops `description()`/`inputSchema()` (`src/Services/Discovery/EntityAutoDiscovery.php:764-776`) |
| Link parse-back | `src/Services/Response/ActionLinkHandler.php:34-37` | Two regexes parse links out of persisted content at every render (wired via `ContentLinkProcessor`, `src/Kompo/Support/MessageBubbleRenderer.php:95-102`, built in `src/Kompo/MessagesQuery.php:446-459`); per-link dedupe already exists (`:56-67`) |
| Validate→gate→execute | `src/Services/Actions/ActionDispatcher.php:111-126` | `dispatch()`: `filterParams` (`:113`) → render-time `authorize()` (`:117`) → `mutates()` ? **defer** (`:121-122`, mints signed token at `:157-179`, never calls `handle()`) : execute (`:125` → `run()` `:187-196`) |
| Approval round-trip | `src/Kompo/Actions/ApprovalCard.php:64` → `src/Kompo/MessagesQuery.php:383-399` → `ActionDispatcher::approve()` (`:208-253`) | HMAC verify + TTL fail-closed (`PendingActionToken.php:141-146`) → null-user rejection (`ActionDispatcher.php:222-224`) → ownership (`:226-228`) → **fresh `authorize()`** (`:239`) → **single-use nonce** (`:246-248`) → `handle()` |
| Schema metadata | `src/Contracts/AiActionInterface.php:53-54` | `inputSchema()` is already documented: "This IS the provider tool/function schema — not a second description of params"; `key()` is "also the native tool name" (`:28-32`) |
| Param hygiene | `src/Services/Actions/AiActionRegistry.php:275-296` | `filterParams()` DROPS undeclared params, casts declared ones (`castValue`, `:301-310`), applies defaults |
| Mutating reference action | `src/Services/Actions/ModelMethodMutationAction.php:108-111, 120-123, 136-144` | Declares `entity_id` in `inputSchema()` so it rides the token; hard `mutates()=true`; fail-closed `authorize()` re-resolving the target row; the dispatcher seeds the link-path id under the same key (`ActionDispatcher.php:139-147`) |
| Per-role LLM | `src/AiServiceProvider.php:246-251` | `ResponseGenerator` is built from `LlmResolver::forRole('response_generation')` (`:248`) — possibly a different provider/model than the default |
| Relevance seam | `AiActionRegistry.php:243-262` | `relevantActions(?$focusedEntity, $resultTypes)` exists and is flagged "dead in production — wire it or delete it" (`94-action-and-extensibility-abstraction.md:120`) |
| Non-streaming chat | `src/Kompo/Presenters/ChatPresenter.php:33-36` | Chat send is non-streaming (optimistic placeholder + DB re-render), so a multi-round tool loop does not break streaming UX |

Two load-bearing semantics constrain everything below:

1. **Tokens are minted at render, not at generation.** Every re-render re-dispatches and mints a fresh token, so an `ApprovalCard` in an old conversation self-heals past the TTL (`config/ai.php:1211`). The native path must therefore persist *action references*, never tokens.
2. **The approval gate is already transport-agnostic.** Nothing in `dispatch()`/`approve()` knows the invocation came from a regex. This design changes how an invocation *arrives* and provably changes nothing about how it is *gated*.

---

## 1. The design in one paragraph

Both transports produce one canonical value object, `ActionInvocation`, before anything executes: native tool calls via `fromToolCall()`, markdown regex captures via `fromLinkMatch()`. Inside `ResponseGenerator::generate()` a single gate decides the channel; in native mode a bounded `ToolLoopRunner` lets the model call registered actions as real tools — read-only calls execute through the existing `ActionDispatcher::dispatch()` so the model grounds its prose on real results; mutating calls hit the dispatcher's `mutates()` gate and are **deferred, never executed**, the loop-time token discarded. The turn's invocations are persisted (schema-filtered params only) in the assistant message metadata; at every render they are re-dispatched through the same dispatcher — fresh `authorize()`, fresh token, fresh TTL — and rendered through one shared presenter with fingerprint dedupe against parsed markdown links. The confirm round-trip (`approveAiAction` → `approve()`: HMAC, TTL, user binding, fresh authorize, single-use nonce) is byte-for-byte untouched. Fallback for tool-less providers is today's markdown path, byte-identical.

---

## 2. Architecture

### 2.1 The tool loop, including the deferred-approval handshake

```
ResponseGenerator::generate()
  │
  ├── channel = ActionToolGate::channel(llm, toolSpecs, options)   ← THE one decision point (§4)
  │
  ├── MARKDOWN ─► today's complete() path, byte-identical
  │
  └── NATIVE ──► ToolLoopRunner  (≤ tool_rounds, default 2)
        │
        │   chatWithTools(messages, toolSpecs) ─► LlmToolTurn{text, toolCalls[]}
        │      ├─ no tool calls ─────────────────► turn.text = answer, done
        │      └─ each call ─► ActionInvocation::fromToolCall()
        │             │
        │             ▼
        │      ActionDispatcher::dispatch()          ← SAME door as the link path
        │             ├─ read-only ─► handle() ran ─► tool_result: summaryForLlm
        │             └─ mutates() ─► Pending: handle() NEVER called,
        │                    │        loop-time token DISCARDED
        │                    ├─ tool_result: "proposed, NOT performed,
        │                    │               user must confirm — never claim done"
        │                    └─ invocation recorded (FILTERED params only)
        │
        ▼
  {answer, invocations[]} ─► AskQuestionUseCase persists message metadata
        │
        ▼  every render
  MessageBubbleRenderer ─► ActionInvocation::fromArray() ─► dispatch() AGAIN
        │     (fresh authorize() · FRESH token · fresh TTL · fingerprint-deduped
        │      against parsed markdown links · memoized per request)
        └─ Pending ─► ApprovalCard ── Confirm ──► MessagesQuery::approveAiAction
                                                        │
                                                        ▼
                                           ActionDispatcher::approve()  — UNCHANGED
                                           HMAC ► TTL ► user binding ► fresh
                                           authorize() ► single-use nonce ► handle()
```

### 2.2 `ActionInvocation` — the canonical unit

```php
final class ActionInvocation                   // src/Services/Actions/ActionInvocation.php (new)
{
    public function __construct(
        public readonly string $key,           // action key (registry key, snake_case)
        public readonly ?string $entityType,   // null for generic actions
        public readonly int|string|null $entityId,
        public readonly array $params,
        public readonly string $text,          // display label the LLM chose
    ) {}

    public static function fromToolCall(LlmToolCall $call): self;   // native transport
    public static function fromLinkMatch(array $match): self;       // markdown transport (regex groups)

    /** Copy with params reduced to the action's schema — the ONLY form ever persisted. */
    public function filtered(AiActionInterface $action, AiActionRegistry $registry): self;

    public function toArray(): array;                               // persisted in message metadata
    public static function fromArray(mixed $a): ?self;              // null on malformed (fail-closed render)
    public function fingerprint(): string;                          // sha1(key|type|id|ksort-canonical params)
}
```

Everything downstream of an `ActionInvocation` — registry resolve, `filterParams`, `authorize`, defer-or-run, `AiActionResult`, `ApprovalCard`, `approve()` — is the existing single pipeline, untouched. The markdown regexes' capture groups feed `fromLinkMatch()`, so even the fallback's parse output becomes the canonical type.

**Persistence hygiene:** the loop records `fromToolCall($call)->filtered($action, $registry)` — raw model args never reach the database. Render-time `dispatch()` re-filters (idempotent, `AiActionRegistry.php:275-296`) and re-authorizes, so the persisted record is data, never authority.

### 2.3 Render-time token minting is kept

The native path persists the *invocation*, not a token and not a result. At render, `dispatch()` runs `authorize()` fresh and mints a fresh signed `PendingActionToken` exactly as the link path does (`ActionDispatcher.php:157-179`). Consequences: zero new approval code; the args the user approves are inside the HMAC-signed payload (`PendingActionToken.php:97-105`), minted server-side from server-persisted, schema-filtered metadata; a conversation re-rendered next week mints a token whose TTL starts then.

---

## 3. Contract changes (exact)

### 3.1 Tool spec derivation — no new action metadata, anywhere

The tool definition is a pure projection of what every action already declares (`key/label/aliases/description/inputSchema/mutates`), produced by one factory:

```php
final class ActionToolSpec                     // src/Services/Actions/ActionToolSpec.php (new)
{
    /** Provider-neutral: name, description, JSON-Schema parameters, mutates flag. */
    public static function fromAction(AiActionInterface $action, ?string $entityType = null): self;
}
```

| Source | Tool field |
|---|---|
| `key()` (generic) / `{EntityType}__{key()}` (entity) | `name`, sanitized to `[a-zA-Z0-9_-]{1,64}` (both providers enforce this charset); a post-sanitization collision is skipped + logged, never silently shadowed |
| `description()` + `aliases()` as "user may say: …" + (iff `mutates()`) the fixed suffix *"REQUIRES USER APPROVAL: calling this only PROPOSES the action — the user must confirm it on a card before anything happens. Never claim it was performed."* | `description` |
| `inputSchema()` — types mapped exactly as `castValue()` enumerates them (`AiActionRegistry.php:301-310`): `int/integer→integer`, `float/double→number`, `bool/boolean→boolean`, `array→array`, else `string`; `label`→property `description`; `default`→`default`; `required:true`→`required[]`; `additionalProperties:false` | `parameters` |
| Entity actions: `entity_id` injected as a required property with description *"ID of the {EntityType} from the results shown — never invented."* | `ModelMethodMutationAction` already declares it (`:108-111`); for read-only entity actions with empty schemas (`AbstractBuiltInAction.php:29-32`, `ConfigAiAction.php:78-85`) the factory injects it into the **tool spec only** and the loop routes it into the dispatch *context*, mirroring `seedEntityId` (`ActionDispatcher.php:139-147`), so `filterParams` semantics are unchanged |

**Anti-drift guarantee (falsifiable):** for every action, every property in the emitted tool `parameters` must survive the `filterParams()` round-trip and vice versa — modulo the context-routed `entity_id`. Asserted in test T1 over all five built-ins + a `ModelMethodMutationAction` + a `ConfigAiAction` (whose closure defaults — empty schema `ConfigAiAction.php:78-85`, non-mutating `:87-94` — yield a valid zero-param tool for free). That property *is* the "no hand-maintained duplicate schemas" claim.

### 3.2 Provider contract — a capability interface, not a fattened `LlmProviderInterface`

`LlmProviderInterface` is public API hosts may implement; adding methods is a BC break. Instead:

```php
interface ToolCallingLlmInterface              // src/Contracts/ToolCallingLlmInterface.php (new)
{
    /** One turn: messages + neutral tool specs → typed turn (text and/or tool calls). */
    public function chatWithTools(array $messages, array $toolSpecs, array $options = []): LlmToolTurn;

    /** Provider-shaped messages to append for the next round (echo of the assistant turn + tool results). */
    public function formatToolResults(LlmToolTurn $turn, array $results): array;
}

final class LlmToolCall { public string $id; public string $name; public array $args; public ?string $argsError; }
final class LlmToolTurn { public ?string $text; /** @var LlmToolCall[] */ public array $toolCalls;
                          public mixed $rawAssistantMessage; public string $stopReason; }
```

There is **no `supportsTools()` method** — `instanceof ToolCallingLlmInterface` is the entire capability check (a redundant boolean beside the type was judge-flagged ceremony and is dropped). The interface lands on the **concrete** providers, not the abstract base, so a future tool-less provider extends `AbstractHttpLlmProvider` without falsely claiming support.

- **OpenAI**: `shapeRequest` (`OpenAiLlmProvider.php:44-62`) gains `tools` + `tool_choice` pass-through exactly like `response_format` (`:53-55`); a new extractor reads `choices[0].message.tool_calls[].function.{name,arguments}` — `arguments` is a JSON **string**; undecodable JSON populates `argsError`, never throws — and tolerates `content: null`, deliberately bypassing `extractTextResponse()`'s missing-content throw (`:66-68`). `formatToolResults` emits the verbatim assistant message followed by `role:'tool'` results keyed by call id.
- **Anthropic**: `shapeRequest` (`AnthropicLlmProvider.php:50-86`) gains `tools` with `input_schema`; extraction iterates ALL `content[]` blocks collecting `text` + `tool_use {id,name,input}` and maps `stop_reason` — which also fixes the latent `content[0]['text']` fragility (`:88-95`) for plain chat. `formatToolResults` emits the assistant content-block message followed by a **user** message of `tool_result` blocks (Anthropic's required shape); system hoisting (`:56-66`) is preserved.

Transport, breaker, and retry are reused untouched — `chatWithTools` is one more caller of `sendRequest()` (`AbstractHttpLlmProvider.php:133-136`), so every loop round is automatically guarded by the shared `'llm'` breaker and transient-only retry. Streaming stays text-only and out of scope (chat send is non-streaming anyway, `ChatPresenter.php:33-36`).

### 3.3 Message-history ownership (the opacity rule, stated)

`rawAssistantMessage` and `formatToolResults()` output are **provider-shaped and opaque to the loop**. The rules, explicit because a judge flagged them as underspecified:

1. A `ToolLoopRunner` run is bound to exactly one provider instance; appended history entries are only ever passed back into `chatWithTools` on that same instance.
2. The loop never inspects, trims, or rewrites appended entries — it only appends.
3. Each provider's `shapeRequest` must pass already-provider-shaped entries through unchanged (OpenAI: they are already wire messages; Anthropic: the system-hoist loop at `:56-62` leaves non-system entries untouched, which is why this works today).

This is a deliberate trade: the typed alternative is a cross-provider message AST — the overbuild the panel scored the heaviest design down for. The opacity is confined to the loop; nothing above `ResponseGenerator` ever sees these shapes.

---

## 4. The single capability gate

One decision point, computed once at the top of `ResponseGenerator::generate()` (replacing only the call at `:153` when native):

```php
enum ActionToolChannel { case Native; case Markdown; case None; }

// ActionToolGate::channel(LlmProviderInterface $llm, array $toolSpecs, array $options): ActionToolChannel
None     iff terse style                       // today's suppression rule, ResponseEntityActionsSection.php:55-57
Native   iff config('ai.actions.native_tools', false)
          && $llm instanceof ToolCallingLlmInterface
          && $toolSpecs !== []
Markdown otherwise
```

- The gate reads the **resolved** `response_generation` provider (`AiServiceProvider.php:248`), so per-role provider swaps flip it correctly.
- The channel rides `$sectionOptions` (`ResponseGenerator.php:137-141`); `ResponseEntityActionsSection::shouldInclude()` gains exactly one condition — include only when channel is `Markdown` — so the model is never simultaneously taught the link grammar *and* given tools. The full block is suppressed in native mode, including the per-row ID enumeration (`:95-105`): IDs ride the tool specs' `entity_id` description and the result data the model already sees, not a second prompt grammar.
- **`generateEmptyResponse()` never consults the gate.** The abstention path (`AiManager.php:809-818` → `ResponseGenerator.php:236-297`) attaches **no tools** — a model with nothing to ground on is not handed levers (graft from Design 0, fixing a judge-flagged exposure). It keeps the existing markdown hint (`:305-330`), whose mutating targets still defer behind approval via the shared pipeline.
- When `native_tools` is on but the resolved provider lacks the interface, the gate logs one warning per request — per-environment capability drift (a tools-capable default with a tool-less `response_generation` role, the config-drift failure shape of the live-eval incident) degrades loudly, not silently.
- The query-generation side (`EntityActionAwarenessSection`) is **unchanged**: query gen must know "profile link" is an action, not a column, on both channels (`EntityActionAwarenessSection.php:18-21, 70-74`).

---

## 5. The tool loop

`ToolLoopRunner` (`src/Services/Actions/ToolLoopRunner.php`, ~150 lines), owned and invoked only by `ResponseGenerator`. Messages start as the same `buildPrompts()` output (`:145`) split into system + user roles. Per round:

1. `chatWithTools(messages, toolSpecs)` → `LlmToolTurn`. No tool calls → done; `$turn->text` is the answer.
2. For each call (capped at `max_tool_calls`, deduped on `fingerprint()` within the turn): `ActionInvocation::fromToolCall()` → resolve via `registry->getEntity()/getGeneric()` (the same lookups the link handler does, `ActionLinkHandler.php:102, 122`) → **`ActionDispatcher::dispatch()`** (`:111-126`). The loop contains **no** `mutates()` branching and **no** `handle()` call of its own — the structural guarantee, enforced by a source-level tripwire test.
3. Map the `AiActionResult` by `ActionKind` (`src/Services/Actions/ActionKind.php:21-28`):

| Kind | Executed? | tool_result content (what the LLM is told) | Recorded for render? |
|---|---|---|---|
| `Element`/`Table`/`Export` | yes — `dispatch()` ran it, same as render time | `summaryForLlm` (already the ID-free LLM line, `AiActionResult.php:25`); Table also feeds headers/rows so the prose is grounded | yes — filtered invocation persisted; re-dispatched at render to build the visual |
| `Pending` | **NO — `handle()` was never called** (`ActionDispatcher.php:121-122` deferred) | `{"status":"pending_approval","detail":"Proposed: <label>. A confirmation card is shown to the user. The action has NOT been performed and will only run if the user confirms. Do not state or imply that it happened."}` | yes — filtered invocation persisted; render produces the `ApprovalCard` |
| `Error` (incl. `authorize()` denial, `:117-119`) | no | `is_error: "<summaryForLlm>"` so the model answers honestly ("you are not authorized to…") | no |

4. Append `formatToolResults(...)`; next round. Caps: `ai.actions.tool_rounds` (default 2) and `ai.actions.max_tool_calls` (default 5). On round exhaustion, one final no-tools call (`tool_choice:'none'` / tools omitted) forces closing prose.

The generation-time `Pending` token is discarded (§2.3), but the generation-time `dispatch()` still earns its keep: `authorize()` runs *inside the turn*, so an unauthorized proposal dies there and the LLM is told why — instead of the user seeing a card that errors on confirm.

**Mid-loop failure policy** (grafts Design 0's deterministic composition onto Design 1's no-fallback rule — they compose, because deterministic composition makes zero LLM calls):

- Each round is individually wrapped by retry + breaker; there is **no loop-level retry** (a replay would re-execute read-only tools).
- Failure on round 1 with nothing accumulated → propagate into the existing wrap (`ResponseGenerator.php:176-182`) → `answerQuestion`'s catch (`AiManager.php:472-487`) → honest infrastructure error. **Never** fall back to the markdown channel mid-loop — that is one more LLM call against a possibly-OPEN breaker (`CircuitBreaker.php:68-72`), the exact 429-cascade failure documented from the live-eval incident.
- Failure on round ≥2 with accumulated text/results → compose the answer **deterministically** from the partial text + the artifacts' `summaryForLlm` lines, zero further LLM calls. Already-recorded invocations persist with the turn, so a deferred approval survives an LLM outage (tokens are render-minted; nothing is lost).

**Telemetry:** the loop writes `['tool_calling' => ['channel', 'rounds', 'tool_calls', 'degraded']]` into the response metadata (riding `ResponseGenerator`'s return into `buildFinalResponse`'s `metadata.response`, `AiManager.php:839-855`), so the round-cap default can be tuned on production evidence instead of argued about.

---

## 6. Rendering persisted invocations

The render hook was the thinnest part of the winning design; here it is fully specified.

**Where:** `MessageBubbleRenderer::assistantBubble()` — immediately after the existing `contentLinkProcessor->processForDirectRendering(...)` call (`MessageBubbleRenderer.php:95-102`), with the same context array (`result_set`, `user`).

**What:**

1. Read `invocations` from the assistant message metadata (persisted by `AskQuestionUseCase` beside `response_data`/`suggestions`, `src/Services/Chat/UseCases/AskQuestionUseCase.php:159-165`). `ActionInvocation::fromArray()` returns null on anything malformed — a corrupt record renders nothing (fail-closed).
2. **Dedupe:** parsed markdown links render first (existing behavior, including their own per-link dedupe, `ActionLinkHandler.php:56-67`) and their fingerprints are collected; a persisted invocation whose `fingerprint()` is already present is skipped. This is the only mechanism any panel design offered against a history-contaminated model emitting `action://` links during a native turn; a system-prompt line ("never write action:// or entity:// links") backs it up.
3. **Resolution markers:** the renderer passes the message id into the ApprovalCard confirm wiring (the `$confirmWiring` callable `ApprovalCard.php:37` exists for exactly this); on a successful `approveAiAction` (`MessagesQuery.php:383-399`) the handler writes `resolved_invocations[fingerprint] = 'approved'` into the message metadata. Resolved invocations skip dispatch on later renders and show a static done-note. Declines remain client-side (`ApprovalCard.php:67-69`) and re-offer on re-render — identical to today's markdown semantics, accepted as inherited.
4. **Per-request memo:** dispatch results are memoized by fingerprint on the renderer instance — which is already built once per request (`MessagesQuery.php:446-459`) — so a thread rendering the same invocation in N bubbles pays `authorize()` (and `ModelMethodMutationAction`'s `find()`, `:136-144`) once per request, not N times. This + markers is the designed answer to the judge-flagged render-cost amplification; the per-render re-dispatch itself is kept deliberately, because it is what keeps authorization and TTL fresh.
5. **Presentation:** one shared `ActionResultPresenter`, extracted from `ActionLinkHandler::renderResult()` + export-failure helpers (`:142-189`); the link handler keeps a one-line delegation. Both transports render `AiActionResult`s through the same lines: `Pending` → `ApprovalCard` (non-Kompo array form `ApprovalCard.php:45-52` keeps it unit-testable), export failures stay visibly honest, other errors fail closed.
6. **Narrate-then-deny race:** the assistant's prose may promise a confirmation card that render-time `authorize()` now denies (permission lost, row deleted). For a persisted **mutating** invocation whose render-time dispatch returns `Error`, the presenter renders a neutral "this action is no longer available" notice instead of nothing — so the bubble does not show a promise next to a void. The race itself is inherent to deferred approval with fresh re-authorization and is accepted; the notice is the designed mitigation, and the Pending tool_result wording (§5) keeps the prose conditional rather than assertive.

---

## 7. Failure modes

| Failure | Behavior | Why safe |
|---|---|---|
| Unknown/forged tool name (`delete_all_users`) | Registry lookup null → `is_error` tool_result "Unknown action", loop continues | Tool names are hints; the registry is the authority — same trust model as unknown link keys rendering nothing (`ActionLinkHandler.php:102-104`). No execution path exists for an unregistered name |
| Malformed args (OpenAI `arguments` not valid JSON) | `argsError` set by the provider, never thrown mid-extract → corrective tool_result, one natural retry within the round cap | Never reaches `dispatch()` |
| Decodable-but-dirty args (extra keys, wrong types, hallucinated values) | `filterParams` drops unknown keys, casts declared (`AiActionRegistry.php:275-310`); missing-required fails closed inside the action (`ModelMethodMutationAction::authorize` returns false on an unresolvable target, `:136-144`) | Args are never trusted raw — identical semantics to a hand-built link query string today |
| Hallucinated `entity_id` | Tool spec instructs ids come from results; the real gate is `authorize()` against the *resolved row* at dispatch and **again** at approve (`ActionDispatcher.php:239`) | Two independent server-side passes over the actual target |
| Tool call during abstention / empty context | Impossible by construction — `generateEmptyResponse()` never consults the gate (§4); `render_table`/`export_*` on an empty result set already degrade gracefully (`RenderTableAction.php:50-52`) | The abstention path keeps its markdown hint, fully gated downstream |
| Model emits markdown links during a native turn (imitating its own history) | Render-side fingerprint dedupe across parsed links + persisted invocations; system-prompt prohibition | Both render sources converge on one presenter; an exact duplicate renders once. Near-duplicates: accepted, §9 |
| Provider error mid-loop, round 1 | Propagates → `ResponseGenerator.php:176-182` → `AiManager.php:472-487` honest error. No markdown fallback (no extra call against a possibly-open breaker) | Already-executed loop work was read-only; the loop token was discarded — abandoning mutates nothing |
| Provider error mid-loop, round ≥2 | Deterministic composition from partial text + `summaryForLlm` lines, zero further LLM calls; invocations persist | A deferred approval survives an LLM outage |
| Circuit breaker OPEN | `CircuitBreakerOpenException` (`CircuitBreaker.php:68-72`) — same funnel as above. The loop multiplies pressure on the shared `'llm'` breaker (`AbstractHttpLlmProvider.php:287`); the round cap (2) bounds one user turn at ≤3 calls against the failure threshold of 5 | Fail-fast, loud, never disguised as "no results" — the buildFinalResponse rule at `AiManager.php:800-808` |
| Model never stops calling tools | Caps exhausted → one final forced no-tools call for closing prose; if that fails too, deterministic composition | Bounded cost, deterministic exit |
| Action lies about `mutates()` (declares false, writes) | Executes during the loop and on every re-render, LLM-initiated | **Accepted** (§9): the render path already executes read-only actions on every re-render — the loop adds occurrences of an existing trust class, not a new one; `AiActionInterface.php:66-70` is the contract |
| Stale/replayed/foreign approval | Re-render mints fresh tokens; replay after confirm dies on the nonce (`:246-248`); never-confirmed dies on TTL (`PendingActionToken.php:141-146`); lost permission dies on fresh `authorize()` (`:239`); userless token rejected for authed approver (`:222-224`) | Existing, tested code — zero native-path edits (`tests/Unit/Services/Actions/ApprovalFlowTest.php`, `tests/Feature/ActionApprovalRoundTripTest.php`) |

---

## 8. Token-gating policy

A tool spec costs ~60–150 prompt tokens, paid on **every** round. Deterministic, structural rules only (no keyword/semantic lists):

1. **Relevance-scoped, never global:** the tool set is built from `relevantActions($focusedEntity, $resultTypes)` (`AiActionRegistry.php:243-262`) — entity tools only for types actually in focus/results; this finally gives the audit-flagged dead seam (`94-…md:120`) its production caller and closes P1-C4's "registry as single metadata source" on the response side.
2. **Abstention turns: zero tools** (§4). **Terse styles: channel `None`** — mirrors today's suppression (`ResponseEntityActionsSection.php:55-57`).
3. **Knowledge/document turns** (`AiManager.php:743-751`): generic tools only — entity tools require entities in scope, which knowledge queries don't have. Typically the 5 built-ins (`AiActionRegistry.php:123-132`) ≈ 350–600 tokens.
4. **Empty tool set ⇒ not Native:** no `tools: []` requests, no loop — a plain `complete()` exactly as today.
5. **No double-advertising:** native mode suppresses the entire `ResponseEntityActionsSection` block — grammar prose, per-row ID enumeration, CRITICAL rules (`:71-133`) — several hundred tokens reclaimed, often making native *cheaper* per round than the markdown prompt it replaces. The real overhead is the extra round-trip when a tool is actually used.
6. **Not built:** an LLM pre-classifier for "will this turn act" — a second semantic judgment that costs more than it saves at ≤~10 tools; `llm_roles` already lets hosts pin `response_generation` to a cheap model (`config/ai.php:405-411`). Anthropic prompt caching of the tools block: deferred until telemetry says the spend matters.

Both channels' real token numbers ride the §5 telemetry; the eval harness (`config/ai.php:432-449`) grades answers, not action episodes, so cap/exposure tuning is explicitly a telemetry-driven follow-up, not a launch gate.

---

## 9. Judge findings — dispositions

Every weakness the three judges flagged against the winning design, plus the panel-level findings that shaped the grafts. **Fixed** = changed in this design; **Accepted** = kept, with the rationale.

| # | Judge finding | Disposition |
|---|---|---|
| 1 | Generic tools handed to the abstention path — ungrounded model given levers | **Fixed** — abstention never consults the gate; zero tools on empty-context turns (§4, Design 0 graft) |
| 2 | Render-time re-dispatch = O(actions) `authorize()` DB hits per render; mitigation "sketched but not designed" | **Fixed** — designed: per-request memo on the once-per-request renderer + approved-resolution markers that skip dispatch entirely (§6.3–6.4). Per-render freshness itself is kept on purpose |
| 3 | Raw model args persisted in message metadata | **Fixed** — only `filtered()` invocations are ever persisted (§2.2, Design 0 hygiene) |
| 4 | `supportsTools()` redundant beside `instanceof` | **Fixed** — method dropped; the type is the capability (§3.2) |
| 5 | `formatToolResults()` mixing provider-shaped messages into the history underspecified for Anthropic | **Fixed** — message-history ownership rules stated explicitly, per provider (§3.3) |
| 6 | The ~30-line renderer hook was the thinnest part of the plan | **Fixed** — §6 specifies wiring point, fail-closed parse, dedupe order, memo, markers, shared presenter, unavailable-notice |
| 7 | Fingerprint dedupe catches only exact-equal invocations; a link and a tool call with slightly different args render twice | **Accepted** — near-duplicates with differing args are semantically distinct proposals; suppressing either hides information, and both remain fully gated. Exact-match covers the dominant case (the model copying its own link verbatim) |
| 8 | `rawAssistantMessage: mixed` is an opacity wart | **Accepted** — the typed alternative is a cross-provider message AST, the overbuild the panel scored the heaviest design down for; opacity is confined to the loop and governed by §3.3 |
| 9 | "No dual-path rot" is a discipline claim guarded by exactly two tripwires | **Accepted** — the two tripwires (schema round-trip property T1; no-`handle()` source assertion T5h) cover the two reachable rot vectors, schema drift and gate bypass; everything else converges structurally on `ActionInvocation` → `dispatch()`, where there is no second copy to forget |
| 10 | Ships both providers — more scope than strictly needed | **Accepted** — the panel scored the Anthropic punt as the worse failure (silent per-environment capability drift; normalization retrofit under two live implementations), and the Anthropic work also fixes the live `content[0]` extraction fragility (`AnthropicLlmProvider.php:88-95`) as a shared win. The §4 drift warning covers the residual asymmetry risk |
| 11 | Touches more verified code than the minimal alternative (link handler rerouted, Anthropic extraction rewrite) | **Accepted** — bounded churn, regression-pinned: golden byte-identical fallback test (T7), presenter parity test (T8), plain-text Anthropic regression (T4) |
| 12 | Round cap of 2 is an unvalidated guess; real workflows may need chains | **Accepted, instrumented** — caps are config; the loop emits rounds/calls/degraded telemetry (§5) so the number is revised on evidence, and cap exhaustion degrades to a forced-text close, never an error |
| 13 | (Panel-level, Design 0/2) Read-only actions execute in-loop on the `mutates()` honor system | **Accepted** — the render path already executes read-only actions on every re-render through the same `dispatch()`; the loop adds occurrences of an existing trust class, not a new class. `AiActionInterface.php:66-70` is the contract; runtime write-guard instrumentation is out of scope |
| 14 | (Panel-level, Design 0) Assistant prose can narrate an approval that render-time re-dispatch then denies | **Mitigated + accepted** — conditional Pending wording (§5) + the "no longer available" notice (§6.6); the race is inherent to fresh re-authorization and is the correct trade |

Rejected runner-up mechanisms, for the record: the **second artifact channel without dedupe** (Design 0's `proposed_actions` beside live links — recreated here *with* the dedupe that design lacked); the **normalized cross-provider message AST** (Design 0 — overbuild); the **mutation-offer-as-markdown-link round-trip** (Design 2 — re-enters the brittle grammar the audit demotes at `94-…md:118`, flattens structured args to query strings, and creates a `linkFor()`↔regex sync invariant).

---

## 10. Test-first implementation plan (ordered)

Each task is red-then-green; run per-file: `php -d opcache.enable_cli=0 vendor/bin/phpunit <file> --no-coverage`. All provider tests are offline (shaped request arrays / canned decoded responses, the existing pure-unit style of `tests/Unit/Services/Actions/PendingActionTokenTest.php`).

1. **T1 — `tests/Unit/Services/Actions/ActionToolSpecTest.php` → `ActionToolSpec`.** Type mapping ↔ `castValue()` parity; `required[]`; defaults; `label`→description; mutating suffix iff `mutates()`; `{EntityType}__{key}` naming + charset sanitization + collision-skip; `entity_id` injection; `ConfigAiAction` closure entry yields a valid zero-param tool; **the round-trip property: every advertised parameter survives `filterParams()` and vice versa**, over all 5 built-ins + `ModelMethodMutationAction` + `ConfigAiAction`.
2. **T2 — `tests/Unit/Services/Actions/ActionInvocationTest.php` → `ActionInvocation`.** `fromToolCall`/`fromLinkMatch`/`toArray`/`fromArray` round-trips; `fromArray` null on malformed; `filtered()` drops undeclared params (same fixtures as `tests/Unit/Services/Actions/ActionLinkHandlerParamsTest.php` to pin cross-channel parity); `fingerprint()` equality across transports for the same logical action.
3. **T3 — `tests/Unit/LlmProviders/OpenAiToolTurnTest.php` → `ToolCallingLlmInterface` + DTOs + OpenAI implementation.** `tools`/`tool_choice` in the shaped request; extraction of text-only / tool-calls-only with `content:null` / mixed / parallel calls; malformed `arguments` JSON → `argsError`, no exception; `formatToolResults` message shape.
4. **T4 — `tests/Unit/LlmProviders/AnthropicToolTurnTest.php` → Anthropic implementation.** `tools` with `input_schema`; full `content[]` block walk (text + `tool_use` mixed, `tool_use`-first); `stop_reason` mapping; `tool_result` blocks as a user message; system hoisting preserved; **regression: plain-text-only responses still extract** (guards the `:88-95` rewrite).
5. **T5 — `tests/Unit/Services/Actions/ToolLoopRunnerTest.php` → `ToolLoopRunner`** (scripted fake provider). (a) read-only call executes via dispatcher, `summaryForLlm` fed back; (b) **THE invariant: a spy mutating action's `handle()` is never invoked — pending invocation recorded with filtered params, tool_result says not-performed**; (c) unauthorized → `is_error`, nothing recorded; (d) unknown tool → `is_error`, loop survives; (e) malformed args → bounded retry; (f) caps + forced no-tools close; (g) round-1 failure propagates, no markdown fallback call; round-2 failure → deterministic compose, invocations intact; (h) **tripwire: runner source contains no `->handle(` and no `mutates(`**; (i) telemetry fields populated.
6. **T6 — `tests/Unit/Services/Actions/ToolApprovalParityTest.php`** (bridge; mostly existing code). Loop-recorded invocation → render-time dispatch mints a fresh token → `approve()` executes exactly once; replay blocked by nonce; tampered/expired/foreign-user rejected — mirrors `ApprovalFlowTest` to prove parity, not new behavior.
7. **T7 — `tests/Unit/Services/ResponseGeneratorToolChannelTest.php` → `ActionToolGate` + section gating + `generate()` wiring.** Native iff config+capable+non-empty specs; `ResponseEntityActionsSection` excluded when Native, included when Markdown; terse → None; abstention path attaches no tools and is unchanged; empty specs → Markdown; **non-capable provider → byte-identical golden prompt + single `complete()` call** (the fallback regression lock); capability-drift warning logged.
8. **T8 — `tests/Unit/Services/Response/ActionResultPresenterTest.php` → extract `ActionResultPresenter`** from `ActionLinkHandler::renderResult()` (`:142-189`). Output identical to the pre-extraction handler for every `ActionKind` (Pending → `ApprovalCard` array form, honest export failure, fail-closed errors); handler delegates. Run `tests/Unit/Services/Actions/ActionLinkHandlerExportTest.php` as the regression pin.
9. **T9 — `tests/Unit/Kompo/MessageInvocationRenderTest.php` → renderer hook.** Persisted invocation renders through the shared presenter; malformed metadata renders nothing; fingerprint dedupe between a parsed link and an equal invocation yields one element; per-request memo dispatches once for N bubbles; approved marker skips dispatch and shows the done-note; denied mutating invocation shows the unavailable-notice.
10. **T10 — `tests/Feature/NativeToolActionRoundTripTest.php`** (booted app, analogue of `tests/Feature/ActionApprovalRoundTripTest.php`). Scripted provider proposes a `ModelMethodMutationAction` call → **no DB mutation**; assistant message persisted with the invocation; bubble renders the card; `approveAiAction` mutates exactly once; second confirm blocked; resolution marker written.
11. **T11 — config + wiring.** Add `native_tools` (default **false**), `tool_rounds` (2), `max_tool_calls` (5) to the existing `ai.actions` array (`config/ai.php:1209-1212`); register `ActionToolGate`/`ToolLoopRunner` in the provider; document the keys in the config block comment.
12. **T12 — regression sweep**, per-file: `tests/Unit/Kompo/MessagesQueryActionLinksTest.php`, `tests/Unit/Services/Actions/MutationActionLinkFlowTest.php`, `tests/Unit/Services/Actions/ApprovalFlowTest.php`, `tests/Unit/Services/ResponseGeneratorRoleSeparationTest.php`, `tests/Unit/Services/ResponseSections/ResponseEntityActionsSectionStyleTest.php`, `tests/Unit/LlmProviders/OpenAiLlmProviderTest.php`, `tests/Unit/LlmProviders/AnthropicLlmProviderTest.php`, `tests/Feature/ActionApprovalRoundTripTest.php`.

---

## 11. Deletions, simplifications, net delta

**Simplified now:**
- `ActionLinkHandler::renderResult/isExportAction/renderExportFailure` (`:142-189`) move into the shared `ActionResultPresenter`; the handler shrinks to parse-and-delegate (~60 lines out of the fallback path) — the one refactor that *removes* code from the fallback rather than adding beside it.
- `AnthropicLlmProvider::extractTextResponse`'s `content[0]` fragility (`:88-95`) is replaced by block-iterating extraction shared with the tool turn.
- `AiActionRegistry::relevantActions()` (`:243-262`) goes from dead code to the single advertisement source for both channels — closing the audit's "wire it or delete it" (`94-…md:120`).
- `EntityAutoDiscovery::getEntityActions/getGenericActions` (`:764-776, :794-806`) lose their response-side consumer whenever the channel is Native; flagged deprecation candidates for when the markdown fallback retires.

**Kept deliberately (the fallback transport):** the two regexes (`ActionLinkHandler.php:34-37`), `ResponseEntityActionsSection` (channel-gated), `emptyResponseActionsHint` (`ResponseGenerator.php:305-330`). They cannot be deleted while any configured `response_generation` provider lacks tool support *and* while persisted conversations contain `action://` links that re-render — which is exactly why no validation, authorization, or presentation logic lives in them.

**Added:** `ActionToolSpec` (~90), `ActionInvocation` (~70), `ToolCallingLlmInterface` + 2 DTOs (~70), provider shaping/extraction (~70 × 2), `ToolLoopRunner` (~150), `ActionToolGate` (~40), renderer hook + memo/markers (~60). **Net: roughly +480 LOC against ~−80 of consolidation, plus one new concept (the loop).** Complexity goes up in this cut; what it buys is structured-args fidelity (no query-string flattening), elimination of the silent regex-drop failure class for tool-capable providers (`94-…md:118`), real tool feedback to the model, and a trust core whose complexity delta is exactly zero — `ActionDispatcher`, `PendingActionToken`, `ApprovalCard`, `approveAiAction` ship this feature with no edits.

---

### Reference files

- New: `src/Services/Actions/ActionInvocation.php`, `src/Services/Actions/ActionToolSpec.php`, `src/Services/Actions/ToolLoopRunner.php`, `src/Services/Actions/ActionToolGate.php`, `src/Contracts/ToolCallingLlmInterface.php`, `src/DTOs/LlmToolTurn.php`, `src/DTOs/LlmToolCall.php`, `src/Services/Response/ActionResultPresenter.php`.
- Touched: `src/LlmProviders/OpenAiLlmProvider.php`, `src/LlmProviders/AnthropicLlmProvider.php`, `src/Services/ResponseGenerator.php`, `src/Services/ResponseSections/ResponseEntityActionsSection.php`, `src/Services/Response/ActionLinkHandler.php`, `src/Kompo/Support/MessageBubbleRenderer.php`, `src/Kompo/MessagesQuery.php`, `src/Services/Chat/UseCases/AskQuestionUseCase.php`, `config/ai.php`.
- Untouched on purpose: `src/Services/Actions/ActionDispatcher.php`, `src/Services/Actions/PendingActionToken.php`, `src/Services/Actions/AiActionRegistry.php` (gains only callers), `src/Kompo/Actions/ApprovalCard.php`, `src/Services/PromptSections/EntityActionAwarenessSection.php`.
- Cross-refs: `docs/audit/94-action-and-extensibility-abstraction.md` (P1-C1/C4/C5), `docs/audit/92-rag-research.md:24`, `docs/audit/99-roadmap.md` P1-C.
