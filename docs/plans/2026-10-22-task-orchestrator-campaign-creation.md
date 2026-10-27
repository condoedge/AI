# Task orchestrator (first task: create a Coolecto campaign by conversation) — architecture, design and execution plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Phase 0 needs the
> owner's answers before any code.

**Goal:** the assistant can run a *task*: a multi-turn, stateful, audited conversation that ends in one confirmed write. First
task: an organiser creates a campaign. Coco asks questions, recommends the type and its settings (more settings than today's
onboarding), explains its choice from the help articles, shows a code-built summary, and creates the campaign only after an
explicit confirmation. Hard limits, full audit trail, nothing goes live by itself.

**Architecture (recommended, §3):** a host-agnostic `TaskOrchestrator` in condoedge/ai takes over a turn at the use-case level
when the conversation has an active task. The host registers task definitions (a contract + a config registry, the package's
existing extension convention). Each turn is ONE schema-bound LLM call that extracts field values and phrases the next question;
**code** validates every value, chooses what to ask next, builds the summary, and performs the write through the host's existing
domain path inside a transaction. State and audit live in two new tables.

**Tech stack:** Laravel 10/11, Kompo chat UI, condoedge/ai (AskQuestionUseCase, ActionDispatcher/PendingActionToken,
LlmResolver strict JSON schema, FileSearch with audiences), Coolecto domain (`Onboarding::createCampaignFromResults`,
`CampaignCopyTrait`, settings cards' rules).

**Inputs:** `research-ai-extension-points.md` and `research-campaign-creation.md` (scratchpad, 2026-10-22). Binding owner
principles: package abstract (host supplies the domain); access is harness logic; code decides, model narrates; buttons/writes
are declared in setup; simple, not dumb; extension points = contract + config registry + callbacks; zero CondoEdge regression
proven by cassette replay (the hook must be inert when no task is configured).

---

## 1. Requirements (from the owner's request)
1. A real **orchestrator**, not a feature bolted on: clean classes, injected into the package the best way after comparing options.
2. Campaign creation **as automated as possible**: Coco asks many questions, decides with the user the best type and settings,
   covering **more settings than today's onboarding**.
3. **Auditable**, with **clear limits and restrictions**.
4. **Articles** to ground the recommendations (the current KB explains *how*, not *which type fits*).
5. Each step designed and planned fully.

## 2. Facts that constrain the design (research)
- Turn entry: `AskQuestionUseCase::runTurn` → `AI::answerQuestion` (line 247) is the single dispatch; gate, trace, audience stamp
  and message persistence wrap it. ai-eval drives this same use case.
- No task state exists; `ai_conversations.metadata` is read-modify-written by pin/feedback (race); regenerate/edit **delete**
  trailing messages; no conversation lock.
- The write path exists: `AiActionInterface::mutates()` → `PendingActionToken` (HMAC, user-bound, 900 s, single-use nonce) →
  `approve()` re-authorizes. Gaps: generic confirm text, token not bound to team/draft version (review A4), **no audit table**
  (review D2), cancel is client-side only.
- LLM: `forRole()` + `chatJson(json_schema)` strict on OpenAI; tool-calling was removed on purpose; focused-call pattern =
  `ClarityChecker` (one call, `promptBuilt`, `TokenUsage::report` in `finally`, never throws). The per-minute LLM limiter covers
  query generation only.
- UI is message-shaped: chips = typed messages; no in-bubble form or summary card; buttons post to `MessagesQuery` methods.
- QU intents are a closed, snapshot-guarded enum: starting a task must not go through QU.
- Coolecto: one creation method `Onboarding::createCampaignFromResults()` (answers JSON → columns), **no transaction**, guards are
  explicit calls (`TeamLockGuard::assertCanCreateCampaign`, `enforcedMainCampaign`, `Supplier::blocksNewCampaigns`); settings
  cards have **no service layer** (rules live in each Kompo form); campaigns are born `enabled = 0` and go live by themselves only
  when every critical/important setup card is done; bank/Stripe do not block go-live.
- Coolecto authorization gap: route `new-campaign` has no role check (Member/Scanner hidden only in UI); `CampaignPolicy::create`
  is empty.
- Help gap: no "which type fits", no per-type consequences (tax receipts, fees, defaults, dates, participants), network rules
  not explained as such; raffle is staff-only; membership is disabled at creation.

## 3. Architecture options (compared)

| | Option | How | For | Against | Verdict |
|---|---|---|---|---|---|
| A | **Use-case takeover + task registry (recommended)** | `AskQuestionUseCase` asks `TaskOrchestrator::claims($conversation)`; if a task is active, the orchestrator handles the turn and returns the standard response array; else the turn runs unchanged | Reuses gate, trace, audience, persistence, error mapping; one `if` in one place; eval reaches it; AiManager (5.8k lines) untouched; inert when no task is configured | Must call rate admission itself; `recordResponse` overwrites `last_answer_summary` (harmless) | **Chosen** |
| B | AiManager early branch | new branch before `understanding` | sits with other branches; gets admission | grows the monolith; AiManager has no conversation; harder to keep state | no |
| C | AiManager after QU | branch at ~971 | can read QU language | pays a QU call per task turn; QU output meaningless for "oui, le 3 mars" | no |
| D | UI-only wizard with AI "recommendation" | a Kompo form; the AI only suggests | simplest | not conversational, no "decide with you", duplicates onboarding UI | no (keep as fallback link) |
| E | Agent with tools (LLM loop calling write tools) | function calling | flexible | removed on purpose (2026-10-01); unauditable step order; injection can drive writes | rejected |
| F | Host-only implementation in Coolecto | everything in `app/` | fastest | violates "inject into the package"; no reuse for CondoEdge tasks (meetings, announcements) | rejected |

**How a task starts (sub-options):** (1) explicit chip attached by code — chosen; (2) a small classifier role on every turn —
rejected for v1 (extra call per turn, cassette impact); (3) QU intent — rejected (closed enum, prompt guards).
The chip "Créer ma campagne avec Coco" is attached by code when the navigation choice offers `new_campaign` or on the welcome
screen for audiences whose definition is startable (`TaskDefinition::startableBy($user)`).

**Where state lives (sub-options):** conversation `metadata` (race, rolled back by nothing) / snapshot (rewritten every turn) /
**dedicated table (chosen)**: versioned row, lockable, survives regenerate, auditable.

**How the write is confirmed (sub-options):** reuse `ActionDispatcher` + `PendingActionToken` (chosen, extended with optional
binding claims `task`, `v`, `team`) vs a separate confirm path (duplicates signing/nonce/re-authorize). The confirm card is a new
renderer showing the code-built summary.

## 4. Design

### 4.1 Package contracts (`Condoedge\Ai\Contracts\Tasks\…`)
```php
interface TaskDefinition
{
    public function key(): string;                                   // 'create_campaign'
    public function startableBy(mixed $user): bool;                 // role + team checks, server side
    /** @return list<TaskField> ordered; each knows its type, enum, rules, condition, risk, default */
    public function fields(mixed $user, TaskDraft $draft): array;
    /** grounding: which article audiences / slugs the step call may cite */
    public function knowledge(): TaskKnowledge;
    /** code-side checks over the whole draft (cross-field, domain guards); [] = valid */
    public function validate(mixed $user, TaskDraft $draft): array;
    /** rows for the confirm card, built by code from the draft */
    public function summary(mixed $user, TaskDraft $draft): TaskSummary;
    /** the single write; runs inside the orchestrator's transaction + lock; idempotent by task uuid */
    public function execute(mixed $user, TaskDraft $draft, TaskRun $run): TaskOutcome;
    public function limits(): TaskLimits;                           // turns, ttl, per-user/day, per-team/day
}
```
`TaskField` is a value object: `key`, `label_key`, `type` (`enum|bool|int|money|date|text|percent`), `options` (enum values with
label keys), `rules` (Laravel rules), `required_when` (callback over the draft), `risk` (`none|visible|money|legal`), `default`
(callback), `ask_hint` (what the question is about, for the model). Callbacks are `[Class, method]` pairs (cache-safe config).
Registered in config: `ai.tasks.definitions = [App\Ai\Tasks\CreateCampaignTask::class]`, `ai.tasks.enabled` (bool, default
false). No definition → nothing changes (CondoEdge).

### 4.2 Package services
- `Tasks\TaskOrchestrator`: `claims(AiConversation): ?TaskRun`, `start(user, conversation, key)`, `handleTurn(run, message,
  context)`, `confirm(run, token)`, `cancel(run)`. A state machine:
  `collecting → reviewing → (confirmed → executing → done | failed) | cancelled | expired`.
- `Tasks\TaskStepCaller` (focused-call pattern): one `chatJson` with schema
  `{updates: [{field, value}], question: string, choices: [string], explanation: string|null, wants_to_cancel: bool,
    asks_about: string|null}`; temperature 0; role `task_orchestrator`; `promptBuilt` + `TokenUsage::report`; never throws
  (failure → "je n'ai pas compris, pouvez-vous reformuler?" + same question).
  Prompt: identity, the asker's audience block, the field specs **of the next 1–3 fields only** (code picks them), current draft
  (non-PII fields only), grounding passages (FileSearch restricted to `knowledge()` audiences/slugs, top 4), the user message in a
  spotlight fence. The model may only propose values for listed fields; code drops anything else.
- `Tasks\TaskPlanner` (pure code): next fields = first required-and-missing in order whose `required_when` holds, then optional
  fields grouped by theme the user accepted to review ("Voulez-vous aussi régler les reçus / les frais / le don supplémentaire?").
- `Tasks\TaskStore` + migrations:
  - `ai_tasks`: `id, uuid, definition_key, conversation_id, user_id, team_id, status, draft json, version int, turns int,
    llm_calls int, tokens int, expires_at, confirmed_at, executed_at, outcome json, created_at, updated_at`;
    indexes `(conversation_id,status)`, `(user_id,created_at)`, `(team_id,created_at)`.
  - `ai_task_events` (append-only audit): `id, task_id, at, actor_user_id, ip, kind` (`started|asked|answered|extracted|
    rejected_value|validated|summary_shown|token_issued|confirmed|executed|failed|cancelled|expired|limit_hit`)`, field, value_before,
    value_after, payload json (model output, validation errors, token fingerprint), hash, prev_hash` (hash chain → tamper-evident).
- `PendingActionToken`: optional claims `task` (uuid), `v` (draft version), `tm` (team id); `approve()` rejects when the task's
  current version/team differ (closes review A4 for tasks).
- `AskQuestionUseCase`: before line 247, `if ($run = $this->tasks?->claims($conversation)) { $response = $this->tasks->handleTurn(...); }`
  `$this->tasks` is null unless `ai.tasks.enabled` and at least one definition is registered → byte-identical turns elsewhere.
  Admission: call `QueryExecutor::admit` with the same budget as a normal turn.
- UI (`MessagesQuery` trait `HandlesTasks`, `MessageBubbleRenderer` hook): choice chips (existing follow-up chips), a
  **TaskSummaryCard** (code-built rows, risk badges on money/legal rows, "Modifier" chips per row), Confirm (posts
  `confirmTask(uuid, version, token)`), Cancel (server-side `cancelTask(uuid)`), a progress line ("Étape 4 sur ~9").
  Regenerate/edit are hidden on task messages.
- Admin: `AdminTranscriptModal` shows the task timeline from `ai_task_events`; `AiConversationsAdminPage` filter "tâches".

### 4.3 Limits and restrictions (defaults; Phase 0 confirms the numbers)
- Who: `startableBy` = server-side `campaignCreator` (not Member, not Scanner) **and** the team is not suspended; re-checked at
  confirm. Coolecto also fixes the route gap (`new-campaign` gets the same server-side check) — independent of the AI.
- Per task: max 40 turns, TTL 24 h, max 60 LLM calls; per user: max 3 executed creations / 24 h via Coco; per team: 10 / 24 h.
  Hitting a limit → `limit_hit` event + plain message + link to the manual form.
- What the task may set: only the declared fields (whitelist). Never: `rate`, `pay_fixed_fees`, `enabled`, Stripe/bank data,
  communications sending, participant import, products' prices beyond suggested defaults, raffle/draw (staff-only), membership
  (disabled at creation), anything under a network rule that forces it (read-only, explained).
- Money/legal fields (`risk = money|legal`: cover fees, profit share, added donation, receipts/tax receipts, goal) are shown with
  a badge on the summary and **must** be confirmed explicitly in the same card.
- After execution the campaign stays `enabled = 0`; going live remains the human setup band (bank, dates, products, participants…).
- Prompt-injection: the user text is fenced; values are code-validated; the gate still runs on every typed answer.
- No write outside `execute`; `execute` runs in `DB::transaction` + `lockForUpdate` on the task row; idempotent by `uuid`
  (a second confirm of a done task returns the same outcome).

### 4.4 Coolecto task definition (`app/Ai/Tasks/CreateCampaignTask.php`)
- Fields v1 (order; conditions from `Step2OnboardingForm` & per-type steps, research §2.2): organisation context (read-only:
  org type, network rules), goal of the fundraiser (free text → helps the recommendation, not stored), **campaign type**
  (enum limited by `TeamLockGuard` + role: no raffle unless staff, no membership), type-specific choices (meal type, ticketing
  type, products origin/supplier, results type, multiple dates, participants mode `has_recipients`, profit share, added donation,
  show goal), **name**, then optional groups the user can accept: dates (start/end + type-specific rules), goal amount + type,
  donation options, cover fees (+ default toggle), receipts/tax receipts (only if team `hasTaxReceiptInfo`), contact email,
  reason (Product/Donation), leaderboard display (if participants).
- `execute`: builds an `Onboarding` with the answers (same keys the wizard writes, so `createCampaignFromResults` stays the one
  creation path), wraps it in a transaction with the guards it already calls, then applies post-creation settings through a new
  host service `App\Services\Campaigns\CampaignSettingsWriter` whose rules are **extracted from the settings card classes**
  (each card's `rules()` becomes a static the card and the writer share — no duplicated validation), and calls
  `handleAfterStartEndDatesAreSet()` when dates are set. Records `campaign_created_by_ai` on `tracked_events`.
- `summary`: rows per field with label, value (translated), risk badge, source ("recommandé par Coco" / "votre choix" / "imposé
  par votre réseau").

### 4.5 Articles (new, `resources/ai-help/aide/organisateur/`)
Designed as part of this feature (owner-reviewed in English summaries; French bodies; same frontmatter):
1. `choisir-le-type-de-campagne` — decision guide: situation → type (with Coolecto's own vocabulary).
2. `comparer-dons-pair-a-pair-incitation` · 3. `comparer-repas-benefice-et-billets` · 4. `comparer-formulaire-et-inscriptions` ·
5. `comparer-encan-et-autres` (raffle = with the Coolecto team).
6. `consequences-par-type-recus-d-impot` (which types issue tax receipts; team prerequisites).
7. `consequences-par-type-frais-et-don-supplementaire` (cover fees, tips-only, added donation availability).
8. `valeurs-par-defaut-a-la-creation` (defaults set silently per type).
9. `regles-de-reseau-a-la-creation` (approval, budget number, forced cover fees, captured transfers).
10. `creer-sa-campagne-avec-coco` (what Coco can and cannot do, limits, that the campaign is not live until the setup band is done).
Each fact cites code (file:line) in an internal review sheet so the KB owner can verify; no fee number is stated unless the
article on fees states it.

## 5. Phases (each = design check → TDD build → measured eval → log)

### Phase 0 — owner decisions (no code)
Confirm: (1) option A + explicit chip start; (2) limits numbers §4.3; (3) v1 types (all offered types except raffle and
membership?); (4) settings groups beyond onboarding in v1; (5) fix of the `new-campaign` route gap (separate small PR);
(6) whether a Member may *start* a task that ends in "demandez à un administrateur" (recommended: no, the chip is not shown).
**Answered 2026-10-23:** (1) option A + chip start; (2) limits as in §4.3; (3) all types the team can create except raffle and
membership; (4) all optional groups of §4.4; (5) done first, separately: `Step1InternalForm::authorizeBoot()` aborts 403 for
Member/Scanner (`tests/Feature/Onboarding/NewCampaignAccessTest.php`); (6) no, Members and Scanners never see the chip.

### Phase 1 — package core (no UI), TDD
Tasks: contracts + value objects (`TaskField`, `TaskDraft` with version, `TaskSummary`, `TaskOutcome`, `TaskLimits`);
migrations `ai_tasks`, `ai_task_events` (hash chain); `TaskStore` (lockable, optimistic version); `TaskPlanner` (pure);
`TaskStepCaller` (ScriptedJsonLlm tests: drops undeclared fields, invalid values rejected by code and audited, cancel flag);
`TaskOrchestrator` state machine (every transition audited; TTL/turn/call limits; idempotent execute); token binding claims;
use-case hook inert when unconfigured. Gates: package suite green; **CondoEdge cassette replay 0** (no definition configured);
prompt snapshot added for the task step prompt.

### Phase 2 — chat UI
`HandlesTasks` trait on `MessagesQuery` (`startTask`, `confirmTask`, `cancelTask`, all scoped to `$this->conversation` and the
task's current version), `TaskSummaryCard` renderer, chips, progress line, hidden regenerate/edit on task messages, admin
timeline. Tests: Kompo render tests; headless check of a full scripted run in Coolecto.

### Phase 3 — Coolecto definition and domain service
`CreateCampaignTask`, `CampaignSettingsWriter` with rules extracted from cards (refactor each card to read its rules from the
shared static; card tests stay green), guards called in `execute`, route-gap fix, `tracked_events` record. Tests on the dev DB in
transactions (memory: never RefreshDatabase): each type end-to-end through `execute` produces the same columns as the manual
wizard for the same answers (golden comparison with `createCampaignFromResults`).

### Phase 4 — articles
Write the 10 articles (agents with a strict contract like the Coolecto KB; labels verified against `resources/lang/fr`), owner
review via English summary sheet, ingest, retrieval eval (each recommendation question retrieves its article).

### Phase 5 — evals
New suite `storage/app/eval/coolecto/tasks-*.json`: scripted multi-turn users (organiser personas: school product sale, sport
P2P, gala tickets, donations-only, inscriptions), adversarial (Member tries; "mets les frais à 0 %"; injection in an answer;
asks for raffle; tries to confirm an old summary; two tabs confirm twice), limits. New ai-eval gates: `task_status_is`,
`task_field_equals`, `no_execute_without_confirm`, `audit_complete` (every transition has an event, hash chain valid).
Measure turns-to-completion, tokens, latency per persona; read every transcript.

### Phase 6 — rollout
`ai.tasks.enabled` per environment + allowlist of team ids for a pilot; admin KPI "campagnes créées avec Coco"; feedback loop
items (👎 on task turns) go to the weekly triage (`coolecto-feedback-loop.md`).

## 6. Risks
- **Wrong write** → whitelist fields, code validation shared with the cards, code-built summary, explicit confirm with version-
  bound token, transaction, campaign born offline.
- **Prompt injection** → fences, enum-bound schema, code drops undeclared fields, gate on every answer, audit of rejected values.
- **Drift between AI path and manual wizard** → same `Onboarding` answers + same creation method + golden comparison tests.
- **CondoEdge regression** → hook inert unless configured; replay 0 at every package step.
- **Cost/latency** → one call per turn on a mini model where possible (`task_orchestrator` role), grounding top-4 passages.
