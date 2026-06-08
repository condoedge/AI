# Action & Extensibility Abstraction (any project)

This is a design roll-up for the agentic-action centerpiece (L11) and the chat-surface/page-context UX gap (L2-1/L2-2). It exists to settle one question before the execution phase touches `src/Services/Actions/*`: **how does a host project — CondoEdge, Sisc, ActiviteDeFinancement, or any future tenant — teach the assistant to *do* something, and get a write-approval gate for free, with host-side code only?**

The answer is not "invent a new registry." The ecosystem already has exactly one extensibility idiom, and the AI package already lives inside it for ingestion. The action system diverged from it and over-modeled presentation. This document specifies the alignment, the minimal contract, the project-agnostic proof, and the surface/page-context layer the contract plugs into. It is opinionated on purpose: the items below are the shape the implementation should take, cross-referenced to roadmap **P1-C** (action completion) and **P1-F** (surfaces & page-awareness).

---

## The house idiom — how condoedge/utils Plugins work, and why the AI action system should align, not diverge

The whole ecosystem extends behavior through **one** pattern, expressed in two parallel forms. Both resolve through `Condoedge\Utils\Kompo\Plugins\Base\PluginsManager`, which does exactly one job: walk the class hierarchy and merge each level's `getGlobalPlugins()` into a deduped list (`PluginsManager.php:7-22`).

**Component plugins** — `HasComponentPlugins` trait + `ComponentPlugin` base. A Kompo component opts into behavior by listing plugin class-strings in a `$plugins` property or via `static::setPlugins([...])` (`HasComponentPlugins.php:142-150`). The trait's lifecycle hooks (`booted()`→`onBoot`, `afterKompoAction()`→`onAfterKompoAction`, `js()`, `authorize()`) iterate every resolved plugin and dispatch *if the method exists* (`HasComponentPlugins.php:35-133`). The load-bearing trick is **method injection**: a plugin declares `managableMethods()` returning method names, and the host's `__call` routes any unknown call to whichever plugin owns it (`HasComponentPlugins.php:93-106`). `ExportPlugin` is the canonical example — it grafts `exportToExcel`/`exportToExcelViaEmail` onto *any* `Query` that lists it, without the Query knowing exports exist.

**Model plugins** — `HasModelPlugins` trait + `ModelPlugin` base, same `PluginsManager`. Same shape: `static::$globalPlugins`, `setPlugins`/`excludePlugins`/`getPlugins` (`HasModelPlugins.php:150-188`), `bootHasModelPlugins()` → each plugin's `onBoot()` (`HasModelPlugins.php:50-58`), `__call` → `managableMethods` (`HasModelPlugins.php:211-223`), plus model-specific hooks (`getAttribute`, `getRelationshipFromMethod`, `newCollection`).

**The AI package already lives in this idiom.** `FileProcessingPlugin extends ModelPlugin`; its `onBoot()` wires the host File model's Eloquent events (`created`/`updated`/`saved`/`deleting`) to Neo4j+Qdrant sync (`FileProcessingPlugin.php:29-66`). `FileAccessScopePlugin` is the same shape. So the established, *understood* contract in this repo is already: **"a host extends behavior by registering a plugin class-string that gets booted and can graft methods."**

**Where actions diverged.** `AiActionRegistry` re-invented a second, parallel registry/lifecycle that reuses none of this. It hand-rolls `registerGeneric/registerEntity/getGeneric/getEntity`, built-in seeding (`AiActionRegistry.php:58-103`), and config merging — the exact "merge global + local, resolve class-strings, dedupe" job `PluginsManager` already performs and that the rest of the codebase recognizes. The practical cost: a host **cannot** say `AiChatPanel::setPlugins([...])` or drop an action class-string in config the way it registers every other extension; registration is bespoke service-provider code (`AiServiceProvider::registerActionServices()` only binds two singletons — `AiServiceProvider.php:452-456` — there is no class-string ingestion path), and `config('ai.entity_actions')`/`generic_actions` still only document **closures** wrapped by `ConfigAiAction`.

**The alignment principle (this is the design):** keep `ActionDispatcher`'s validate→authorize→defer→approve *flow* (it is correct and well-tested — `ActionDispatcher.php:67-180`), but make the **registry a plugin-style class-string registry** so a host registers an action the same way it registers any other behavior:

```php
// config/ai.php — the house way: a flat array of AiAction class-strings.
'actions' => [
    \App\Ai\Actions\CreateEventAction::class,
    \App\Ai\Actions\ExportRollCallAction::class,
],
```

The registry instantiates each on boot exactly as `bootHasModelPlugins()` instantiates plugins (`new $class()` → it's already an `AiActionInterface`), keeping the existing closure/`ConfigAiAction` path as the legacy fast lane. This is roadmap item **P1-C** + L11 "what to add" #7. No new framework — just point the existing registry at the existing idiom's registration shape.

---

## The canonical action contract

The contract is sound in spirit but over-modeled at the return boundary and under-exposed at the invocation boundary. Here is the minimal version the execution phase should converge on.

### The interface

```php
interface AiAction
{
    /** Stable machine key — also the native tool name. snake_case. */
    public function key(): string;

    /** Advertised to the LLM (tool description AND markdown-fallback awareness). */
    public function description(): string;

    /**
     * JSON-schema parameter spec. This IS the provider tool/function schema —
     * not a second, parallel description of params.
     * [] = takes no params. Anything not declared here is DROPPED on the way in.
     *
     * @return array<string, array{type:string, required?:bool, default?:mixed, label?:string}>
     */
    public function inputSchema(): array;

    /** true => state-mutating => approval gate + fresh server-side re-authorize. */
    public function mutates(): bool;

    /** Server-side gate. Re-checked at render time AND again before a mutating handle(). */
    public function authorize(mixed $user, array $input): bool;

    /** Execute. Typed in (filtered $input + context) AND typed out (ActionResult). */
    public function handle(array $input, ActionContext $ctx): ActionResult;
}
```

Notes that matter:
- `label()`/`aliases()` survive only as **optional** metadata on a base class (used by the markdown-fallback awareness prompt), not as required interface methods — they carry no semantics the LLM needs once `description` + `inputSchema` are exposed as a real tool.
- `mutates()` **replaces** `requiresApproval()` in name to make the gate's trigger unambiguous: a write mutates; the dispatcher derives "needs approval" from it. (Keep the old method name as a thin alias on the base for one release if churn matters — see Migration.)
- `ActionContext` is a small readonly DTO carrying the acting **user** + **team** + render context (`entity_id`, `entity_type`, `text`, `result_set`), so `handle()`/`authorize()` reliably receive identity instead of falling back to a stale static `auth()` in CLI/queue/eval (closes the loose-`$context`-array threading; aligns with the P1-B4 identity work).

### The typed result — delete `renderable: mixed`

Today `AiActionResult::$renderable` is `mixed` (`AiActionResult.php:21`) and `handle(): mixed` (`AiActionInterface.php:91`). The value is a Kompo element **OR** an `AiActionResult` **OR** a `['pending'=>true,...]` map **OR** a `['type'=>'table',...]` map **OR** an `['content','mime',...]` export payload — discriminated by ad-hoc array-sniffing in `ActionLinkHandler::renderResult` (`ActionLinkHandler.php:139-153`, `is_array($renderable) && !empty($renderable['pending'])`) and again in `MessagesQuery`. That `mixed` boundary is the root cause of every consumer re-implementing type detection and drifting (L11-7). Replace it with a `kind` enum so consumers switch on a *field*, not a shape:

```php
enum ActionKind { case Element; case Table; case Export; case Pending; case Error; }

final class ActionResult
{
    public function __construct(
        public readonly ActionKind $kind,
        public readonly mixed $payload = null,        // intent + data, NOT a Kompo element
        public readonly string $summaryForLlm = '',
    ) {}

    public static function element(mixed $payload, string $s = ''): self  // intent: render an element
    public static function table(array $rows, string $s = ''): self
    public static function export(string $content, string $mime, string $filename, string $s = ''): self
    public static function pending(string $token, string $confirmMessage, string $s = ''): self
    public static function error(string $s): self
}
```

`handle()` returns **data + intent**, never a Kompo element. **One Kompo presenter** keyed on `ActionKind` builds `_Link`/`_Rows`/`ApprovalCard`/download — so `handle()` and the built-ins stop calling `function_exists('_Link')` (L11-8), and the action layer becomes reusable in a non-Kompo host. This is **P1-C5**.

### The approval gate (keep the flow, harden the token)

The dispatcher's flow is already right and stays unchanged in shape:
1. `filterParams()` strips undeclared params against `inputSchema()` (`AiActionRegistry.php:223-244`).
2. `authorize()` at render time (`ActionDispatcher.php:73`).
3. If `mutates()`: **defer** — mint a `PendingActionToken`, return `ActionResult::pending($token, $confirm)` (`ActionDispatcher.php:77-115`).
4. The presenter renders an `ApprovalCard`; confirm round-trips to `MessagesQuery::approveAiAction` → `ActionDispatcher::approve()`, which decodes the token, re-checks ownership, **re-runs `authorize()`**, then `handle()` (`ActionDispatcher.php:148-180`).

Harden before any writer ships (**P1-C3**, L11-6): TTL check in `decode()`, single-use nonce before `handle()`, reject `userId===null` in authenticated contexts. And add the missing i18n keys so the confirm/cancel buttons don't render the literal `ai.actions.confirm` (P0-13/L11-5).

### Native tool-calling exposure (the primary invocation channel)

Today invocation rides entirely on markdown regex: `ActionLinkHandler` parses `[text](action://key?x=1)` / `[text](entity://Type/id/key?...)` out of free LLM prose via `ACTION_PATTERN`/`ENTITY_PATTERN` (`ActionLinkHandler.php:34-37`). The interface **already carries a JSON-schema tool spec** (`key`+`description`+`inputSchema`) yet there is zero provider tool-calling wiring, and the LLM is told even less than that — `EntityActionAwarenessSection` advertises only aliases/labels (`EntityActionAwarenessSection.php:41-77`), dropping `description` and `inputSchema`. So the model is asked to emit a precise URL grammar it was never fully told. The design: **emit `inputSchema()` as provider tool/function definitions as the primary channel; keep markdown links as the legacy fallback** for models without tool support (**P1-C1**). The registry becomes the single metadata source both the tool-spec and the awareness prompt read from (**P1-C4**).

### DELETE as overengineering — call these out by name

- **`presentation(): string`** (`AiActionInterface.php:64`, `ConfigAiAction.php:87-94`). Read by **nothing** in production — the dispatcher detects deferral via the `['pending'=>...]` array shape (`ActionDispatcher.php:77`), not `presentation()==='approval'`. Pure ceremony every action must implement. **Delete from the interface** (L11-4). The presenter's `ActionKind` enum is the real discriminator.
- **`renderable: mixed` / `handle(): mixed`** — the `mixed` return (`AiActionResult.php:21`, `AiActionInterface.php:91`). Replace with the typed `ActionResult { ActionKind $kind, ... }` above (L11-7).
- **Markdown-regex as the *primary* invocation channel** — `ENTITY_PATTERN`/`ACTION_PATTERN` round-trip (`ActionLinkHandler.php:34-37`). Demote to fallback behind native tool-calling (L11-1 "what to add" #1). One stray space or encoded char silently drops the action today.
- **`ConfigAiAction::invokeWithArity()` + per-render `ReflectionFunction`** (`ConfigAiAction.php:166-180`) — reflection on every render to juggle three legacy closure arities (`fn($id,$text)` / `fn($text)` / `+$input`). Collapse to **one** typed closure signature `fn(ActionContext $ctx): ActionResult` for new config actions; keep a single legacy branch (L11-9).
- **`relevantActions()`** (`AiActionRegistry.php:191-210`) — dead in production, only its own test calls it (L11-3). Either make it the single entry point the awareness section + response sections call (preferred — it's the natural seam for P1-C4) or delete it. Do not keep it dead.

**Harden, don't delete:** the `PendingActionToken` (add TTL + single-use + null-user rejection, L11-6) and the i18n keys (L11-5). **Wire, don't delete:** route `EntityActionAwarenessSection` through the registry so the query-gen and response sides agree on what actions exist (L11-2).

---

## Proof it is project-agnostic

The contract is only worth shipping if a host can register a real mutating action with **host-side code only — no core edit**. Two real creation flows from sibling apps prove it. The package today ships **zero** actions that return `mutates()===true` (only a test `SpyAction`), so this is also the missing end-to-end proof the centerpiece needs (L11-1 / **P1-C2**).

### Sisc — "create event" (class-string registration)

The real flow: a staff member opens the event wizard (`EventFormStep0::middleForm`), which lists only `EventTypeEnum` cases passing `canBeListedInEventWizard()` **and** `auth()->user()->hasPermission(<relatedModel>, PermissionTypeEnum::WRITE)` — authorization is a per-event-type WRITE permission. Picking a type routes into `EventCreationHandler::getSteps()`, where `EventFormStepBasicInfo` collects `name_ev`, `start_date`, `end_date`, `event_location_type`, `location_id` with real validation (`end_date` `after:start_date`; `location_id` required-if physical). The base form stamps `team_id = currentTeamId()`; `EventCreationHandler::afterSave` spawns sub-event dates.

Registered as an AI action, **host-side**, with `CreateEventAction implements AiAction` added to `config('ai.actions')`:

```php
final class CreateEventAction implements AiAction
{
    public function key(): string { return 'create_event'; }
    public function description(): string { return 'Create a new event (camp, activity, …).'; }
    public function inputSchema(): array {
        return [
            'event_type'  => ['type' => 'string', 'required' => true],
            'name_ev'     => ['type' => 'string', 'required' => true],
            'start_date'  => ['type' => 'string', 'required' => true],
            'end_date'    => ['type' => 'string', 'required' => true],
            'location_id' => ['type' => 'int',    'required' => false],
        ];
    }
    public function mutates(): bool { return true; }                       // => approval gate, free.

    public function authorize(mixed $user, array $input): bool {           // EXACT wizard gate, reused.
        return $user?->hasPermission(
            EventTypeEnum::from($input['event_type'])->relatedModel(),
            PermissionTypeEnum::WRITE
        ) ?? false;
    }

    public function handle(array $input, ActionContext $ctx): ActionResult {
        $event = new Event($input);
        $event->team_id = $ctx->teamId;                                    // identity from context, not static auth().
        $event->save();
        EventCreationHandler::afterSave($event, $input);
        return ActionResult::element(['route' => 'events.show', 'id' => $event->id],
            "Created event \"{$event->name_ev}\".");
    }
}
```

The **write/approval flow**: because `mutates()===true`, the dispatcher never executes on render — it mints a token and returns `ActionResult::pending(...)`. The presenter renders an `ApprovalCard` summarizing the parsed inputs ("Create *Camp* event 'Summer 2026', Jul 1–Aug 1?"). Confirm → `approveAiAction` → fresh `authorize()` → `handle()`. The approval card **is** the wizard's review step. **Zero core edits** — one host class + one config line.

### ActiviteDeFinancement — "create campaign" (config-closure registration)

The real flow: a user walks an onboarding wizard persisting answers onto an `Onboarding` model; the terminal `StepFinalOnboardingForm::handle()` collects `campaign_name` (`required`), then `Onboarding::createCampaignFromResults()` calls `User::newBaseCampaign()` — which stamps `user_id`, `team_id = current_team_id`, `owner_team_id`, language, rate, `enabled=0` — then maps onboarding answers to columns and saves. Authorization is **implicit** today (it binds to the acting user's current team; no explicit permission check), so the adapter adds one.

Registered **config-only**, no class, via the legacy `ConfigAiAction` fast lane (proves the cheap path still works against the new contract):

```php
// config/ai.php
'generic_actions' => [
    'create_campaign' => [
        'label' => __('campaign.create'),
        'requires_approval' => true,                                       // => mutates() === true
        'input_schema' => [
            'campaign_name'    => ['type' => 'string', 'required' => true],
            'campaign_type'    => ['type' => 'string', 'required' => true],
            'participant_goal' => ['type' => 'int',    'required' => false],
        ],
        'authorize' => fn ($user, $input) =>
            $user?->current_team_id && $user->can('create', Campaign::class),
        'action' => function (ActionContext $ctx) {                        // one typed closure signature
            $c = auth()->user()->newBaseCampaign();                        // host primitive, team-scoped
            $c->name             = $ctx->input['campaign_name'];
            $c->campaign_type_id = $ctx->input['campaign_type'];
            $c->participant_goal = $ctx->input['participant_goal'] ?? null;
            $c->save();
            return ActionResult::element(['route' => 'campaign.dashboard', 'id' => $c->id],
                "Created campaign \"{$c->name}\".");
        },
    ],
],
```

`ConfigAiAction` reads `requires_approval`/`authorize`/`input_schema` (already supported — `ConfigAiAction.php:96-118`) and the dispatcher defers behind the token + `ApprovalCard` showing the proposed name/type before any write — the explicit gate AF lacks in code but gets implicitly through its multi-step wizard. Again: **host config only, no core edit.**

**What both prove, and what the package still owes them.** Both adapters are host-only. For that to hold, the package must ship (all already on the roadmap): class-string auto-registration in `config('ai.actions')` (P1-C); one proven, hardened write-gate + booted-app `ApprovalCard → approveAiAction → handle` test + the `ai.actions.*` lang keys (P1-C2/C3, P0-13); the `ActionContext` carrying user+team so host `authorize()`/`handle()` never fall back to stale static `auth()`; and the typed `ActionResult`+`ActionKind` so a returned model surfaces as a link/redirect without each host re-sniffing array shapes (P1-C5).

---

## Page-context awareness & surfaces

An agentic assistant must know **which page/record it was opened from**. Today that is entirely absent — `page_context` is 0 hits across the pipeline (L2-1). This is the soft prior that lets "open this unit's modal" or "create an event *here*" resolve a concrete anchor for the action contract above.

### The `page_context` thread (P1-F1)

One readonly DTO, threaded through the **existing** seam — no new pipeline argument shapes:

```php
final class PageContext
{
    public function __construct(
        public readonly ?string $route = null,           // 'units.show'
        public readonly ?string $entityType = null,      // 'Unit'
        public readonly int|string|null $entityId = null,
        public readonly array $filters = [],             // whitelisted: ['status' => 'active']
        public readonly ?string $label = null,           // 'Unit 402'
    ) {}
    public static function fromArray(array $a): self
    public function toArray(): array
}
```

Threading (each step reuses an existing pass-through; every call site keeps working with `pageContext = null`):
1. **Host → prop.** A host page passes one **array** prop (arrays cross the Kompo prop boundary; the object would not survive serialization): `new AiChatFloating(null, ['page_context' => ['route' => Route::currentRouteName(), 'entity_type' => 'Unit', 'entity_id' => $unit->id, 'label' => $unit->display, 'filters' => ['status' => 'active']]])`.
2. The surface `created()` reads `$this->prop('page_context')` → `PageContext::fromArray(...)`, forwards it to `ChatMessageForm` like `conversation_id`.
3. `ChatMessageForm::sendMessage()` → `ChatPresenter::handleSend(..., $pageContext)` → `new AskQuestionInput(..., pageContext: $pageContext)` — add one nullable promoted field, default `null` (backward-compatible).
4. `AskQuestionUseCase::runTurn` already calls `AI::answerQuestion($q, [...])`; add `'page_context' => $input->pageContext?->toArray()`.
5. `AiManager::answerQuestion` already merges `conversation_context` onto `PipelineContext`; add the symmetric two lines for a new typed `PipelineContext->pageContext` property (default `[]`).

**One conditional prompt section.** `PageContextSection extends BasePromptSection` (role `user`, priority ~16, just before `current_user_context`). `shouldInclude()` returns `!empty($context->pageContext)` — **zero tokens when absent**. `format()` emits 3–5 lines ("User is viewing: {label} (type {entityType}, id {entityId}) on screen {route}. Active filters: …"), wrapping host strings via `spotlight(...,'page_context')`. This is the only prompt change; it rides the existing priority-sorted pipeline, no builder edits.

**Page context is a soft prior, never a hard filter** (so it never hides cross-record answers):
- **Entity selection:** when `entityType` matches the catalog, seed it into `PipelineContext->relevantEntities`/the QU hint so entity-selection ranks it first — the same mechanism `conversation_context.focused_entity` already uses. Gives pronoun/possessive resolution a concrete anchor ("this unit").
- **Retrieval:** append `label` + `filters` values as one extra query angle in the existing file-context fan-out (it already merges multiple phrasings) — boosts recall for the current record without excluding others.
- **Example questions:** the welcome screen prefers a per-type override `config("ai.chat.page_examples.{$entityType}")` falling back to the global list, so "Unit 402" shows "Show this unit's payment history." Config-only, no LLM call.

### Surface abstraction (P1-F2)

Today `AiChatModal`/`AiChatDrawer` are referenced in config/docs but only `AiChatPanel` + `AiChatFloating` exist (L2-2). Extract the panel body (sidebar + `MessagesQuery` + `js()`) into **one** `AbstractChatSurface extends Form` carrying the shared traits and the `page_context` prop. The surfaces become near-empty shells differing only in *chrome*:

| Surface | Shell role |
|---|---|
| `AiChatFloating` | FAB → opens the chosen surface (`->inModal()` / drawer) |
| `AiChatModal` | `AbstractChatSurface` in a modal (today's `AiChatPanel`; keep as alias so nothing breaks) |
| `AiChatDrawer` | same body in a slide-over (`->inDrawer()`) |
| `AiChatScreen` | routed full-page (`Route::get('/ai/chat', AiChatScreen::class)`) |

The floating button picks its target from `config('ai.chat.surface', 'modal')` (`modal|drawer|screen`) via a small `match` in `openChat()`. This makes the dead config/doc names real, with the body defined **once**.

**Anti-overengineering guardrail:** no `PageContextResolverInterface`, no per-surface config tower. The whole layer is: one readonly DTO, one nullable field added to `AskQuestionInput` + `PipelineContext`, one conditional section, one shared base, one `surface` config key. Purely additive.

---

## Migration from today — minimal churn

The flow and the dispatcher do **not** change. The churn is concentrated at the contract boundary and the registry's registration surface, and it is small. Sequenced (this is the internal order under roadmap **P1-C**, which itself sequences C4 → C2 → C3 → C5 → C1):

1. **P1-C4 — one metadata source.** Point `EntityActionAwarenessSection` at the registry instead of raw `config('ai.entity_actions')`/`generic_actions` (`EntityActionAwarenessSection.php:28-68`); make `relevantActions()` (`AiActionRegistry.php:191-210`) the entry point it calls (or delete it). Now both prompt sides and the future tool-spec read one source. Closes L11-2/L11-3.
2. **P1-C2 — class-string registration + first real writer.** Teach the registry to ingest `config('ai.actions')` class-strings the plugin way (`new $class()` on boot, beside `registerBuiltIns()` at `AiActionRegistry.php:58-71`). Ship the one mutating reference action (the Sisc `CreateEventAction` pattern above) + a booted-app approval round-trip test. Closes L11-1.
3. **P1-C3 — harden the token.** TTL + single-use nonce + reject `userId===null` in `PendingActionToken`; add the `ai.actions.*` lang keys (also P0-13). Closes L11-6/L11-5.
4. **P1-C5 — typed result + presenter.** Introduce `ActionKind` + `ActionResult` factories; move `_Link/_Rows/ApprovalCard`/download construction into **one** presenter keyed on `kind`; narrow `handle(): ActionResult`; **delete `presentation()`** from the interface and `ConfigAiAction`. Update `ActionLinkHandler::renderResult` (`ActionLinkHandler.php:139-153`) to switch on `kind`, not array shape. Collapse `ConfigAiAction::invokeWithArity` (`ConfigAiAction.php:166-180`) to the single `fn(ActionContext $ctx): ActionResult` signature + one legacy branch. Closes L11-4/L11-7/L11-8/L11-9.
5. **P1-C1 — native tool-calling.** Emit `inputSchema()` as provider tool/function definitions as the primary channel; keep `ActionLinkHandler`'s `ENTITY_PATTERN`/`ACTION_PATTERN` (`ActionLinkHandler.php:34-37`) as the fallback for tool-less models. Closes the L11 "what to add" #1 gap.

**Backward compatibility during the cut:** keep `requiresApproval()` as a one-release alias of `mutates()` on the base action class; keep `ConfigAiAction` reading the existing `requires_approval`/`authorize`/`input_schema`/`action` keys so every shipped closure config keeps working untouched. The `entity_actions`/`generic_actions` closure config and the markdown-link fallback both survive — nothing a host wrote breaks. The page-context/surface work (**P1-F1/F2**) lands independently and additively (every call site defaults `pageContext = null`), so it can run in parallel without touching the action cut.

**Net deletions this migration carries:** `presentation()` (interface + `ConfigAiAction` + `AbstractBuiltInAction`), `renderable: mixed` (→ typed `ActionResult`), `invokeWithArity` arity-juggling, and the demotion of markdown-regex from primary to fallback. **Net additions:** `ActionKind` enum, `ActionContext` DTO, `PageContext` DTO + section, one Kompo presenter, `AbstractChatSurface` + `AiChatDrawer`/`AiChatScreen`, class-string registration in `config('ai.actions')`, hardened `PendingActionToken`, and the `ai.actions.*` lang keys.

---

### Reference files

- Contract & dispatch: `src/Contracts/AiActionInterface.php`, `src/Services/Actions/ActionDispatcher.php`, `src/Services/Actions/AiActionRegistry.php`, `src/Services/Actions/ConfigAiAction.php`, `src/Services/Actions/Results/AiActionResult.php`, `src/Services/Actions/PendingActionToken.php`, `src/Kompo/Actions/ApprovalCard.php`, `src/Services/Response/ActionLinkHandler.php`, `src/Services/PromptSections/EntityActionAwarenessSection.php`, `src/AiServiceProvider.php:452-456`.
- House idiom (read-only siblings): `condoedge/utils/src/Kompo/Plugins/Base/PluginsManager.php`, `.../HasComponentPlugins.php`, `condoedge/utils/src/Models/Traits/HasModelPlugins.php`, `condoedge/utils/src/Kompo/Plugins/ExportPlugin.php`; in-package: `src/Models/Plugins/FileProcessingPlugin.php`.
- Surfaces & page-context: `src/Kompo/AiChatPanel.php`, `src/Kompo/AiChatFloating.php`, `src/Services/Chat/Inputs/AskQuestionInput.php`, `config/ai.php` (`chat.surface`, `chat.page_examples`, `actions`).
- Roadmap cross-refs: `docs/audit/99-roadmap.md` P1-C (C1–C5), P1-F (F1–F2); layer detail: `docs/audit/layers/11-agentic-actions.md`, `docs/audit/layers/02-kompo-ui-ux.md`.
