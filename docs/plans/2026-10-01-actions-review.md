# Kompo AI — Actions Review & Improvement List (2026-10-01)

Source: 3 parallel read-only reviews (system + zero-config, usefulness from eval traces, professional patterns),
judged against the founding vision: near-zero-config plug-in, keep the intelligence, genuinely useful, remove the
unnecessary. Owner's example: *"provide the link of the user profile when asking related, or actions to do — as an extra."*
Working notes: session scratchpad `actrev/{system,usefulness,professional}.md` (+ per-run dumps).

## 0. Headline

- **Only ~40% of answers give the user the right link** (11 of 27 turns in the latest two eval runs). Every
  record-specific question (unit, union, meeting, supplier, task) produced a wrong, invented or placeholder link in at least
  one of six runs. The only reliably good links are `team_settings` and the export download chips.
- **Root cause is architectural, not prompt wording:** the package already computes the correct, *authorized* per-row
  links in code (`ResponseEntityEnricher::buildActionLinks`) — then hands them to the LLM, which re-types them as
  `entity://Type/ID/...` links. Every invented id (`entity://Union/3`, `Meeting/1903`, `Unit/159` for an ambiguous "310")
  came from that hand-off. Professional assistants (Dynamics 365 record cards, Zendesk action buttons, ChatGPT Apps
  widgets) do the opposite: **code attaches cards/chips from structured data; the model only narrates.**
- **Two HIGH security findings in the host's action gating** (CondoEdge, but the package must make this hard to get wrong):
  residents can likely approve their *own* reservations and close *staff* tasks through the chat.
- **"Link to my profile" cannot be answered today** — the route exists (`profile.show`), no action points to it.
- Heavy config: 21 entity actions + 28 generic actions hand-written as closures (blocks `config:cache`); ~70 lines of
  hand-copied role checks; alias word lists (violates the no-hardcoded-semantics rule).

## 1. Target design (one paragraph)

**Code decides which actions to attach; the LLM never types a URL or an id.** After the query runs, the pipeline already
knows the rows, their entity labels and the user's permissions. From that, code builds: (a) **record chips/cards** for the
records in the answer (view page, statement, approval page), (b) **next-step chips** chosen by answer *shape* (empty →
list page + create; one record → its page + its main action; truncated list → list page + export; money total → the
report; how-to → the page the cited doc describes), (c) **self/identity links** (my profile, my team) when the question
is about the user. The model can reference a record inline with a placeholder (`{{record:N}}`, like the existing
`{{table}}`), and code substitutes the real link. Mutations stay opt-in and always go through an approval card that names
the target. Links are derived with near-zero config from Laravel routes + Kompo page models + policies, with declarative
(closure-free) overrides.

## 2. Ranked improvement list

Effort: S ≤ 1 day, M 1–3 days, L > 3 days. "Config" = impact on what a host must write.

### P0 — security (do first)

| # | Improvement | Evidence | How (professional) | Effort |
|---|---|---|---|---|
| A1 | **Fix reservation approve/reject gating** | Gated on `ReservationPolicy::update`, which lets the reserving contact pass; no pending-state check → a rejected reservation can be flipped to approved. UI only shows these to `reservation:manage` + `isPending()` | Gate mutations on a dedicated ability matching the UI (`reservation:manage`) + a state precondition (`isPending()`); the package's mutation action should require an explicit `ability` + optional `precondition` method and refuse role-only/`update` defaults | S |
| A2 | **Verify + fix close_task gating** | `TaskPolicy::update` passes for anyone whose `current_team_id` = task team, incl. residents; tasks UI is staff-only; mutations skip the advertisement filter → offered to everyone | Same as A1 (dedicated ability); verify with a resident account first; never advertise mutations the user can't approve | S |
| A3 | **Per-record authorization for every action** | `ConfigAiAction` has an empty input schema → `authorize($user, [])` never sees the id; every "open" link is role-gated only | `Gate::allows('view', $model)` per row (load visible ids in one tenant-scoped query; deny when no policy / exception); fall back to the target route's own middleware | M |
| A4 | **Approval tokens bound to tenant + message + record version; reject nonce-less tokens; mark markdown-path approvals resolved** | Token = HMAC + 900s TTL + nonce + user (good); missing team/message/version binding; `claimNonce('')` passes once; markdown-path cards reappear on reload → the same mutation can be confirmed twice | Add claims (team, message id, record `updated_at` hash); resolved-state per proposal stored on the message; card names the target ("Approve reservation #123 — Salle commune, 12 oct.") | M |
| A5 | **Delete the unsafe/dead built-ins** `link`, `open_modal`, `render_table` | They never render (presenter returns raw arrays), yet are advertised to both LLMs every turn; `link?url=` accepts ANY URL; `open_modal` lets the LLM pick a method/form (`editUnit` doesn't exist) | Delete (skeptic check); deterministic tables already replaced render_table (Phase 8-A) | S |

### P0 — usefulness core (biggest value)

| # | Improvement | Evidence | How | Effort |
|---|---|---|---|---|
| B1 | **Record chips attached by code** (never typed by the LLM) | 7 wrong + 4 missing link turns of 27; all invented ids came from LLM-typed links | From result rows with an id + `_label`: attach the entity's primary page as a chip labelled with its display name + disambiguator (e.g. "Unit 310 — Carré Soho Phase 3"); cap 5 then "+N more → list page"; store as message invocations (already rendered with authorization); always project the id in generated queries (query rule) | M |
| B2 | **`my_profile` + `my_team` identity links** (owner's example) | "Link to my profile" unanswerable; team-info answer showed her own email but no profile link | Built-in generic actions: Jetstream `profile.show` / host-configurable route; attached when QU `isPossessive` or the answer concerns the current user (self-reference already detected in 8-A/8-B) | S |
| B3 | **Next-step chips by answer shape** (replace "let me know" prose offers) | Prose offers ("Would you like to proceed?", "reply 'export that'"); the follow-up suggestion chips exist in the UI but nothing creates them | Deterministic rules on (QU intent, row count, truncation, money total, file-grounded how-to) → ≤ 3 chips; wire the existing suggestion chips or delete them | M |
| B4 | **Digest links by code** | Model dropped configured section links in 3/3 digest runs | Each digest section renders its configured link + one chip per row (task, unit, thread); "export that" becomes a real chip | S |
| B5 | **Stop teaching link grammar to the LLM** | `ResponseEntityActionsSection` (271 lines) + most of `EntityActionAwarenessSection` (280) teach `entity://` syntax and list ~33 actions every turn | After B1–B4: delete/shrink both; keep only a one-line "actions are attached automatically; you may reference a record with {{record:N}}"; AnswerValidator link check stays as a backstop | S |

### P1 — zero-config + coverage

| # | Improvement | Evidence | How | Effort |
|---|---|---|---|---|
| C1 | **Auto-derived "view" action per entity** | 21 hand-written closures; many Kompo pages already declare `public $model = X::class` with one `{id}` (UnitView, SupplierPage, InvoiceForm, ContactForm, EventForm, ProjectPage…) | Resolution order: model method (`HasAiUrl::aiUrl()`) → PHP attribute `#[AiLink(route:…, param:…)]` → discovery-time scan of routes whose Kompo page binds that model (cached in the discovery cache, never guessed at runtime) → config override/opt-out | M |
| C2 | **Declarative (closure-free) action config** | Closures block `config:cache`; `ConfigAiAction::invokeLegacy` uses reflection only because of closures | `['route' => 'invoices.stage', 'param' => 'id', 'ability' => 'view']` and `#[AiMutation(ability: 'manage', precondition: 'isPending')]` on model methods; delete invokeLegacy | M |
| C3 | **Permission from the route's own middleware** for list/section pages | 26 of 28 generic actions are a bare `href(route)` + copied role checks (~70 lines) | Derive allowed parameterless routes from the user's middleware/sidebar; `ai:discover-actions` proposes entries for review | M |
| C4 | **Fill the page gaps** | Missing: task page, invoice/bill *stage* pages (real record pages; current links open edit forms), inbox thread, create links (meeting/task/supplier/contact), team stats, payables-per-supplier report, meeting agenda PDF, board page, pending-reviews queue; union view ignores the id | Mostly free once C1/C3 land; fix the union action (generic, not entity) | S–M |
| C5 | **Relevance filter** — offer only actions that apply to this turn | ~33 links listed every turn → wrong picks (`my_statement` to a user with no unit, app `docs` for a legal question) | Conditions on structured signals (user owns a unit; question_type; entity labels in result) — reuse the native tool path's relevance filter | S |
| C6 | **Delete dead action plumbing** | `EntityAutoDiscovery::getEntityActionResolver/getGenericActionResolver` (0 callers), `getEntityActions/getGenericActions` (tests only), `requiresApproval()` aliases (tests only), 4 copies of the action catalog, union view/manage as entity actions | Skeptic check, delete; one catalog source in the registry | S |
| C7 | **Exports via signed download links** | Every old message re-runs its links on each render; Excel/PDF are rebuilt and embedded as base64 each time | Generate once, store, serve via a signed temporary route | M |

### P2 — "do it" actions (after P0/P1 are measured)

| # | Improvement | How | Effort |
|---|---|---|---|
| D1 | Approval-gated one-click actions wrapping existing app methods: accept bill (`Bill::startAccepting`), send bill for approval, approve draft invoice, prefilled reminder/invoice form, email a unit's owners, create meeting | `#[AiMutation]` on the model method → existing `ModelMethodMutationAction` + approval card (with diff/preview + record-version check from A4) | L |
| D2 | Audit log: one row per proposal / approval / execution (actor, tenant, target, input, before/after, outcome) + export log | New table + dispatcher hooks | M |
| D3 | Support hand-off chip (prefilled ticket linked to the conversation) when the assistant can't answer | Generic action + config | S–M |
| D4 | Native tool loop: decide by measurement (plan Phase 10) — if kept, use only for proposing mutations, never for links | A/B per plan 10.1–10.2 | M |
| D5 | Bulk/background actions with a status card; multi-step workflows only if demand appears | Queue + status card | L |

## 3. Remove list (subject to the usual skeptic check)

`BuiltIn/LinkAction`, `BuiltIn/OpenModalAction`, `BuiltIn/RenderTableAction` (A5) · `ResponseEntityActionsSection` and most
of `EntityActionAwarenessSection` (B5) · `EntityAutoDiscovery` action methods + `requiresApproval()` aliases (C6) ·
`ConfigAiAction::invokeLegacy` once config is declarative (C2) · CondoEdge alias word lists and hand-copied role checks (C2/C3)
· per-row `action_links` hand-off to the LLM (replaced by B1) · native tool loop frozen until measured (D4).

## 4. Measurement

Per the owner's rule, verdicts come from **manual grading of eval traces**. Add an **action rubric** to each gold turn's
standard: *right action present* (recall), *no wrong action* (precision), *no invented link* (hard), *every link authorized*
(hard), *≤ caps* (noise). Structural signals in ai-eval (metadata only, not verdicts): links rendered, ids valid for their
label, prose offers detected. In production: chip click-through, 403/404 after click, approve vs cancel rate (from the turn
trace).

## 5. Sequencing

A1–A2 immediately (small, high risk) → A5 + B5-prep → B1 + B2 (+ eval rubric) → B3 + B4 → A3 + A4 → C1–C7 → measure →
D-items. Estimated: P0 ≈ 5–8 dev-days, P1 ≈ 6–9, P2 ≈ 2–4 weeks.

## 7. Progress log

- 2026-10-01 **A1/A2 FIXED + VERIFIED** (scratchpad a12/): BEFORE — resident could approve/reject own reservations
  (incl. flipping rejected→approved), close/complete team tasks; NEW find: any staff (incl. concierge) could close tasks of
  ANOTHER team by id. AFTER — all false; manager on pending reservation / open own-team task still true. Host: `$hasPermission`
  + `$inCurrentTeam` helpers, `$mutation` requires permission+tenancy+ability+precondition. Package: ModelMethodMutationAction
  explicit permission/ability/precondition (generic-CRUD-only → fail closed, not advertised, logged, no throw); precondition at
  proposal + approval + inside handle(); single advertisement rule (ActionAdvertisement) on all paths; nonce-less tokens rejected.
  Actions 143 green. Host residual (non-AI): TaskPolicy/ReservationPolicy::update broad.
- 2026-10-01 **A1/A2 CLOSED** after adversarial review (no bypass) + hardening: handle() = transaction + lockForUpdate +
  precondition on the locked row (rollback on throw); row-level gate MANDATORY (authorizer or non-generic ability; `rowScoped:
  false` opt-out); handle() acts only on the token's input id; unfiltered EntityAutoDiscovery::getEntityActions removed; doc:
  multi-server single-use approval needs a shared atomic cache (redis/database). Host: `$hasPermission` now uses the membership
  ROLE on the live current team (Jetstream team-OWNER wildcard no longer grants anything — teams.create is enabled, so any user
  could have owned a team). Actions 157 green.
- 2026-10-01 **D4 DONE — native tool loop DELETED** (owner decision): 9 src files (ToolLoopRunner, ActionToolGate,
  ActionToolSpec, GraphQueryTool, ToolLoopResult, ActionToolChannel, ToolCallingLlmInterface, LlmToolTurn/Call DTOs) + provider
  chatWithTools/tool shaping + ResponseGenerator channel branching; −1,629 src / −3,447 test lines; no references in ai-eval or
  CondoEdge. KEPT: message `metadata.invocations` persist + re-render-with-fresh-authorize seam (reserved for B1 code-attached
  chips; nothing produces invocations right now). All dirs green; ai-eval 97 green. ResponseGenerator ctor now (llm, config).
- 2026-10-01 **B1–B5 CORE DONE** ("code attaches links/actions; the AI only narrates"). New `src/Services/Actions/Attach/`:
  `ActionAttacher` (B1 record chips: primary read action per record, name + homonym disambiguator, cap 5 + "+N more" → list;
  B2 `my_profile`/`my_team` built-ins (`IdentityLinkAction`, routes `profile.show`/`teams.show`, host alias
  `attached_actions.identity.team.action = team_settings`); B3 next steps by shape (empty → list+create, one record → other
  read actions, truncated → list + Excel only when the retrieval was complete, money → report else list); B4 digest section
  chips + round-robin row chips + export chip), `RecordResolver` (structural row→record), `AttachedActions` (persisted as
  `metadata.response.invocations`; `{{record:N}}` substitution), `RecordIdProjection` (appends `v.id AS <label>_id` to the
  final RETURN for record variables — the QueryRules line alone was ignored live). B5: `ResponseEntityActionsSection` +
  link-grammar tests DELETED → `AttachedActionsSection` (one line + chip labels + `{{record:N}}`); EntityActionAwarenessSection
  shrunk to keys+labels (no aliases, no previous-result ids); empty-path action hint, GuidelinesSection link/closer rules,
  digest "Section link"/Links column/"export that" offer removed; dead follow-up `suggestions` path deleted (next steps are
  invocation chips). Host: generic actions annotated `entity`+`kind`, persona no longer teaches export links. All dirs green;
  ai-eval 97 green. Live 9-question check: scratchpad `actB/live-final.json` (+ `live-digest2.json`).
- 2026-10-01 **B1–B5 review fixes**: primary-action memo keyed per user + reset per call (singleton safety);
  `NarrativeLinkFilter` strips every model-written link (action/entity/relative/js/images; external only when quoted from a
  cited passage; mailto/tel only for row values) on the ResponseGenerator, empty-result and digest paths, BEFORE
  `{{record:N}}` substitution, which now runs on the narrative before unmask/table; RecordIdProjection tracks scope across
  WITH (re-bound variables get their current label; two labels / multi-label nodes / aliases / comprehension vars skipped,
  ambiguity flagged → the proven example stores the generator's original cypher); clause keywords never match `u.limit`,
  backtick names or comments; host-named identity action denied → no built-in fallback (CondoEdge residents pass
  TeamPolicy::view on teams.show, so the fallback was a real back door); own-row profile only when it is the only row or
  the question is possessive; QU `ownProfile` structured flag replaces "possessive + no entity"; no team chip for a
  missing/soft-deleted current team. All dirs + ai-eval green.
- 2026-10-01 **Profile link restored (owner's example)**: QU emits `navigation_target` (own_profile | own_team | null —
  LLM judgment in the same call, any language; never in entity_types/retrieval); ActionAttacher attaches My profile / the
  configured team action (no fallback); an empty or query-less turn with that target answers by pointing at the attached
  link. The interim `ownProfile` flag was removed. Live: EN + FR "my profile" → chip + one-sentence answer.

- 2026-10-01 **B1–B5 CLOSED** after security review + fixes: NarrativeLinkFilter strips every LLM-written action/entity/
  relative/javascript/image link (http only if verbatim in a cited passage; mailto/tel only if in rows) — prompt-injection
  test passes; per-user attacher cache; RecordIdProjection scope-aware (rebinding/ambiguity skipped), clause keywords and
  comments/backticks masked; no identity fallback when the host's action is denied (old fallback would have given residents
  teams.show = members/invitations page); substitution before unmask/table; QU `navigation_target` (own_profile|own_team) —
  owner's example "link to my profile" works EN + FR. All dirs + ai-eval green. Full eval p9-1001 RUNNING.

## 6. Owner decisions (2026-10-01)

1. **A1/A2 security fix: NOW** (verify with a resident account; dedicated ability + pending precondition; package guardrails).
2. **ADOPTED: "code attaches links/actions, the AI only narrates"** (B1/B5; `{{record:N}}` placeholder for inline references).
3. **D1 first mutations: "Email a unit's owners" and "Create meeting / create task"** (approval-gated, prefilled).
4. **Native tool loop: DELETE NOW** (skeptic-check callers to avoid breakage; no A/B).

## 6b. Original open questions

1. Fix the two HIGH gating issues now (CondoEdge config + package guardrails)? *(recommended: yes, immediately)*
2. Adopt "code attaches actions, LLM only narrates" as the rule (B1/B5) — this removes link-syntax teaching from prompts.
3. Which "do it" mutations matter most to your users (D1 order)?
4. Native tool loop: keep frozen until the Phase-10 A/B, or delete now?
