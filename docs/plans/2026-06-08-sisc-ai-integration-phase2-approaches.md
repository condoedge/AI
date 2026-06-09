# SISC AI Integration — Phase 2: Integration Approaches & Recommendation

Date: 2026-06-08
Status: Design / decision doc (no code changes)
Companion to: `2026-06-08-sisc-ai-integration-phase1-domain-map.md`

> Scope note: This is a design-only deliverable. Nothing in SISC or in the package
> `src/**` / `config/**` was modified. All claims below were re-verified against the
> live tree (file:line citations) before writing — see "Evidence base" at the end.

---

## 0. What changed vs. the Phase-1 exploration (verified corrections)

Two Phase-1 statements were sharpened by direct verification; they **strengthen** the
"activate the package" thesis rather than weaken it:

1. **The old `ai-panel` truly is dead** — outside `config/ai-panel.php` and
   `config/ai-query-patterns.php` themselves, the only `openai` reference in SISC is the
   `composer.json` require of `openai-php/client` (line 32). No `app/` code uses it. So the
   OLD panel replaces nothing functional. CONFIRMED.

2. **BUT the NEW package is already half-wired into the dashboard.** `app/Kompo/Dashboard/DashboardView.php`
   imports `Condoedge\Ai\Kompo\AiChatModal` (line 9, the *modern package* modal) and has a
   live `openAiPanel()` method returning `new AiChatModal()` (lines 111-114). The trigger
   button is **commented out** at line 73:
   `// $canShowAiBtn ? _Button2('lilhelper.ask-me')->selfGet('openAiPanel')->inModal() : null`
   gated by `$canShowAiBtn = isSuperAdmin() || isImpersonated()` (line 64).

   Implication: someone already started the modern integration and stopped because the
   package isn't required in composer (so `AiChatModal`'s dependencies don't resolve / no
   `config/ai.php` exists). The integration is closer to "finish the started wiring" than
   "design from scratch." This lowers the activation risk of every approach below.

3. **The package ships a full bootstrap toolchain**, which collapses the single biggest
   cost driver (Phase-1 noted "no entities config exists in SISC"):
   `ai:discover` (`DiscoverEntitiesCommand`) → `ai:enhance` (`EnhanceConfigCommand`, the
   LLM-reads-your-models step) → `ai:validate` (`ValidateConfigCommand`) → `ai:setup`
   (`SetupCommand`: ingest → sync → index, `--discover`/`--fresh`/`--dry-run`). The entities
   config is **generated**, not hand-authored. `config/ai.php:1152` does
   `'entities' => require __DIR__ . '/entities.php'`.

These three facts are the spine of the recommendation.

---

## 1. The decision that actually matters

Everyone frames this as "which AI do we keep." That framing is wrong. The old `ai-panel`
is a two-commit, never-wired prototype that is **forbidden by design** from doing the one
thing the brief asks for (it lists `create/update/delete/save/destroy/insert/truncate` as
forbidden tokens). There is no real "keep the old one" option — there is only "how
ambitious is the new one on day one." So the three approaches below are **not** "old vs
new"; they are **three activation envelopes for the new package**, distinguished by *how
much write capability ships first* and *how much of the package surface we light up*.

The real risks to reason about are therefore not "migration from old code" (there is almost
none) but:

- **R1 — Operational dependency weight.** The package pulls in Neo4j/Cypher (graph store),
  a vector store, an embeddings/LLM provider, and a multi-stage ingest pipeline. Lighting
  all of it up is an infra commitment, not a config flip.
- **R2 — A write-capable assistant on production registration data.** Approving/creating
  inscriptions through chat touches money (`PENDING_PAYMENT`, `CompletePaymentUseCase`) and
  minors (scouts). The blast radius of a wrong mutation is real.
- **R3 — Team-scope leakage.** SISC's entire data model is `ScopedToTeam`. If the AI's read
  path doesn't inherit that scoping, it is a cross-tenant data-exfil bug wearing a chatbot.
- **R4 — Glue rot.** Any SISC-specific code we add (action wiring, authorizers, entity
  config) is code the package team doesn't own and the SISC team will forget. Minimize it.

---

## 2. Approach A — Full replacement: delete old panel, activate package end-to-end (read + write + graph)

**One-liner:** Remove the dead `ai-panel`/`openai` artifacts, require `condoedge/ai`, run the
discover→enhance→setup pipeline, wire `entity_actions['Inscription']` (and later `Event`)
with `ModelMethodMutationAction`s over the already-bound services, mount the chat surface
broadly. The assistant reads AND writes from day one.

**Concrete shape:**
- composer: `+ condoedge/ai`, `- openai-php/client`; delete `config/ai-panel.php` +
  `config/ai-query-patterns.php`; finish the dashboard button (uncomment line 73).
- `php artisan vendor:publish` config → `ai:discover --enhance` over Event/Inscription/Person
  → `ai:validate` → `ai:setup` (stands up graph + vector + index).
- Host glue (the only SISC-owned code): a small `config/ai.php` override or a service
  provider that registers `entity_actions['Inscription'] => ['approve_inscription' => new
  ModelMethodMutationAction(finder: Inscription::class, mutator: fn($i,$in)=>app(InscriptionServiceContract::class)->approve($i), authorizer: fn($u,$i)=> $u?->hasAccessToTeam($i->team_id) && $u->hasPermission('approve_inscription'))]`,
  plus `reject/cancel`, plus a register-scout action over `RegisterPersonByAdminUseCase`.

**Advantages:**
- Maximum capability immediately; directly satisfies the brief ("create/manage
  events/inscriptions through the assistant").
- Smallest *conceptual* glue: writes reuse the exact bound services
  (`InscriptionServiceContract:95`, `InscriptionStateMachineContract:97`,
  `ReinscriptionServiceContract:101`, `EventsServiceContract:137`) the human UI already
  calls. The `InscriptionStateMachine` `lockForUpdate` transaction makes status mutations
  safe by construction.
- The write pattern is *already approval-gated by the package*: `ModelMethodMutationAction::mutates()`
  is hard-`true`, so `ActionDispatcher` defers behind a signed `PendingActionToken`, re-runs
  `authorize()` at render AND again on confirm, and single-uses the nonce so "charge $450"
  can't fire twice. We don't build the safety; we configure into it.

**Adversarial attack:**
- **R1 in full force.** You're standing up Neo4j + vector + embeddings + ingest in one
  jump, on an app that has zero AI infra today. If the graph isn't populated or the
  embeddings provider is misconfigured, the chat is dead-on-arrival AND you've shipped a
  write surface into prod. High coupling between "infra works" and "marquee feature works."
- **R2 maximal.** Day-one write on inscriptions = day-one chance for a scoping/authorizer
  bug to approve or cancel the wrong child's registration. The approval card mitigates
  *accidental* execution, but a *mis-resolved target* (wrong `entity_id` seeded) still gets
  approved by a human who trusts the card.
- **R4 risk:** the register-scout action is NOT a one-line method wrap — it assembles a
  `RegisterScoutDTO` (multi-slot: parent + child + unit + role) and runs a multi-entity
  transaction. That's real SISC glue with real failure modes (partial slot fill,
  ambiguous person match) that the generic `ModelMethodMutationAction` doesn't model well.
- Big-bang cutover means no clean rollback story if the LLM hallucinates a plausible-but-wrong
  action link.

**Improved A:** Keep the end-state but **stage the writes**: ship A with reads + only the
*idempotent, reversible* status mutations (`approve`, `reject` — both state-machine-guarded
and individually reversible by an admin), and explicitly DEFER `register-scout` /
`cancel`/payment to a second wave. This keeps "full activation" but removes the riskiest
write (multi-slot creation touching minors) from the day-one blast radius. It also keeps the
infra bet — which is unavoidable for *any* package activation — paired with the lowest-risk
write surface.

---

## 3. Approach B — Package alongside the old panel (coexistence / feature-flagged dual run)

**One-liner:** Require the package, but leave `ai-panel.php`/`openai-php/client` in place;
run the new chat behind a flag/permission (reuse the existing `isSuperAdmin() || isImpersonated()`
gate from `DashboardView:64`) while "keeping the old one as fallback."

**Advantages (the case for it):**
- Feels low-risk: "we didn't delete anything."
- The super-admin/impersonation gate already exists, so a flagged pilot is one uncomment away.
- Lets you A/B the new assistant for a few internal users before broad rollout.

**Adversarial attack — this approach is mostly a fiction:**
- **There is no "old panel" to run alongside.** Its translator/controller was never
  committed (Phase-1: two-commit temp prototype; verified: `openai` appears in *no* `app/`
  code). "Coexistence" means coexisting with two config files and an unused composer
  dependency. You'd be flag-gating the NEW package against *nothing*. The "fallback" is
  imaginary.
- **It keeps `openai-php/client` and the two dead configs in the tree**, which is exactly
  the maintenance debt (R4) we want to retire. Coexistence *preserves* rot under the banner
  of caution.
- **Two "AI" surfaces invite confusion**: the dashboard already has a method literally named
  `openAiPanel()` that opens the *new* modal — leaving the old `ai-panel` config around
  guarantees a future dev wires the old name to the wrong thing.
- It doesn't actually reduce R1/R2/R3 — the new package still needs full infra and still
  writes; you've just added bookkeeping.

**Improved B:** The *only* salvageable idea in B is **flag-gated rollout of the new package**
(not coexistence with the old). That is: do Approach A's activation, but keep the chat
surface behind the existing `isSuperAdmin() || isImpersonated()` gate (or a dedicated
permission) for an internal pilot, and STILL delete the old artifacts immediately. Once
you make that improvement, B collapses into "A with a rollout flag" — which is the sensible
default and is folded into the recommendation. There is no defensible version of B that
retains the old panel.

---

## 4. Approach C — Minimal / read-only first ("graph-as-translator" parity, then grow)

**One-liner:** Activate the package but configure it **read-only** (`ai.read_only_mode`/
`QueryExecutor` defaults are already read-only: `read_only_mode` default `true`,
`writeKeywords` = `CREATE/DELETE/REMOVE/MERGE/SET/DETACH` rejected). Ship NO `entity_actions`
writes at all on day one — the assistant answers questions (pending/unpaid inscriptions,
spots-left, registration status) and renders read-only `entity://` links (view profile,
open inscription). Reach functional parity with what the old panel *aspired* to be (a safe
read translator), but on the modern, team-scoped, model-agnostic stack. Add writes later as
a deliberate Phase-3.

**Advantages:**
- **Lowest R2:** zero write surface day one. The worst failure is a wrong-but-harmless
  answer, not a wrong mutation.
- **Directly addresses R3 with the package's real machinery:** `QueryExecutor` runs through
  `CypherScopeAnalyzer` + `SecurityFilterInjector` + `AiSecurityService` + rate limiting +
  read-only enforcement — i.e. team scoping is enforced at the query layer, not hoped for.
  A read-only pilot is the cheapest way to *prove* scoping holds before trusting it with writes.
- **Smallest SISC glue:** essentially zero host-owned action code — just config from
  `ai:discover --enhance`, plus mounting the (already-imported) `AiChatModal`.
- Honors the existing half-wired dashboard hook with the least new code: uncomment line 73,
  require the package, run setup. Done.
- Clean, low-stakes way to absorb R1 (infra) decoupled from R2 (writes): if Neo4j/embeddings
  misbehave during bring-up, nothing in prod can be mutated.

**Adversarial attack:**
- **Doesn't meet the brief.** The explicit goal is create/manage events/inscriptions through
  the assistant. A read-only assistant is a nicer search box, not the "worthy place" the
  brief wants. Shipping C and stopping = under-delivering.
- **Still pays the full R1 infra cost** (graph + vector + ingest) for a fraction of the
  payoff. The expensive part of activation is the pipeline, not the action wiring — so C
  buys the cheap half and skips the differentiated half.
- **"We'll add writes later" is where roadmaps go to die.** Without a committed Phase-3, C
  risks becoming the new permanent half-built AI (history rhyming with the old panel).
- The read parity it reaches is *also* reachable by simpler means (the existing Kompo query
  pages), so the ROI of standing up a graph DB *only* for read is questionable in isolation.

**Improved C:** Bind C to a **dated, scoped Phase-3 write milestone** so it's a *first
stage*, not a destination — specifically, C → then add exactly the two reversible status
writes (`approve`/`reject`) from Improved-A. With that commitment, C stops being "the safe
cop-out" and becomes "the verified on-ramp to A."

---

## 5. Head-to-head

| Dimension | A (full read+write) | B (coexist w/ old) | C (read-only first) |
|---|---|---|---|
| Meets brief (create/manage via AI) | Yes, day 1 | Same as A + debt | Not until Phase-3 |
| Migration cost (old → new) | ~nil (delete 2 configs + 1 dep) | Highest (keeps debt) | ~nil (same delete) |
| Infra cost (R1: graph+vector+ingest) | Full | Full | Full (unavoidable) |
| Write blast radius (R2) | High day 1 | High day 1 | None day 1 |
| Team-scope proof (R3) | Trusted, not yet proven | Same | **Proven before any write** |
| SISC-owned glue (R4) | Moderate (action wiring + DTO) | Moderate + dead code | Minimal |
| Risk to running app | Medium | Medium-high (confusion) | Low |
| "Worthy place" payoff | Highest | Highest | Deferred |

Key observations from the table:
- **B is dominated.** It has C's-or-worse risk profile, A's infra cost, *plus* retained dead
  code. Its only real content ("flag-gated rollout") belongs to A anyway. Reject B outright.
- **A and C share the same migration cost and the same unavoidable infra cost.** They differ
  only on *when writes ship* and *whether scoping is proven first*. That's the whole decision.

---

## 6. Recommendation — **A, sequenced through a short C-shaped first stage** (decisive)

Adopt **Approach A's end-state** (full read + write, package as the single AI surface,
old artifacts deleted), but **reach it through one short read-only verification stage**
borrowed from C, and ship behind the rollout flag salvaged from B. Concretely:

**Stage 0 — Retire & require (days).**
Delete `config/ai-panel.php`, `config/ai-query-patterns.php`; drop `openai-php/client`;
`composer require condoedge/ai` (auto-registers `Condoedge\Ai\AiServiceProvider` via its
`composer.json extra.laravel.providers`, lines 34-36 — no manual provider wiring);
`vendor:publish` config. Net: removes dead weight, resolves the dependency that's currently
blocking the already-imported `AiChatModal`.

**Stage 1 — Read-only pilot, scoping PROVEN (the C on-ramp).**
`ai:discover --enhance` over Event/Inscription/Person → `ai:validate` → `ai:setup`. Keep
`read_only_mode` (package default), ship NO `entity_actions` writes. Mount the chat behind
the **existing** `isSuperAdmin() || isImpersonated()` gate (`DashboardView:64`) — uncomment
line 73. Exit criteria: verify `QueryExecutor`'s `CypherScopeAnalyzer`/`SecurityFilterInjector`
actually team-scopes results for a non-super-admin pilot user (R3 must be evidenced, not
assumed) and that no read crosses team boundaries.

**Stage 2 — Reversible status writes (A, low blast radius).**
Add ONLY `entity_actions['Inscription']` → `approve` and `reject` as
`ModelMethodMutationAction`s over `InscriptionServiceContract` (state-machine-guarded,
individually admin-reversible). Authorizer = `$u?->hasAccessToTeam($i->team_id) && $u->hasPermission(<key>)`
re-resolving the exact row from the seeded `entity_id`. This is the package's intended sweet
spot and the marquee agentic demo, with the smallest dangerous footprint.

**Stage 3 — The differentiator: register & manage (full A).**
Add the register-scout action over `RegisterPersonByAdminUseCase`/`RegisterScoutDTO`
(conversational slot-fill → one approval card, replacing the long `RegisterScoutDrawer`),
renewals via `ReinscriptionServiceContract`, payment via `CompletePaymentUseCase`, then
event create/edit via a thin `EventsService` facade (NOT raw column mutation — it must
respect the `EventTypeEnum` wizard + save hooks). Broaden the chat surface (page-aware
`page_context` on inscription/event pages) and widen the rollout flag.

**Why this and not pure A or pure C:**

1. **It meets the brief** (ends at full create/manage — pure C does not) **without betting
   the riskiest write on an unproven scoping layer** (pure A does). The one thing standing
   between "nice search box" and "data-exfil bug" is R3, and the cheapest proof of R3 is a
   read-only stage. So you get A's ambition with C's evidence.

2. **The migration cost is identical for A and C** (delete 2 configs + 1 dep), and the infra
   cost is unavoidable for any activation — so sequencing costs *nothing extra*; it only
   reorders work to front-load the safety proof.

3. **It respects the verified reality on the ground:** the dashboard is already wired to the
   *new* `AiChatModal` and gated to super-admins. Stage 1 is literally "uncomment line 73 and
   make the dependency resolve." We are finishing started work, not greenfielding.

4. **It keeps SISC-owned glue minimal and on the package's intended seam.** Writes are
   `ModelMethodMutationAction`s over services SISC *already binds and the human UI already
   calls* — no new write code, no raw queries, approval + re-authorize + single-use for free.
   The only genuinely SISC-specific glue (the multi-slot register-scout DTO assembly) is
   deferred to Stage 3, where it gets dedicated attention rather than riding in on day one.

**What I am explicitly rejecting:**
- **Keeping the old `ai-panel`** in any form — it's dead, forbidden from writing, and a
  confusion magnet. Delete it.
- **Approach B** — dominated; its only good idea (flag-gated rollout) is absorbed into the
  staging above.
- **Stopping at C** — under-delivers against the brief; allowed ONLY as the Stage-1 on-ramp
  with Stage 2-3 committed and dated.

**The single biggest risk to watch (be skeptical):** R3, team-scope enforcement in the read
path. Everything downstream (trusting the AI with writes) rests on it. Stage 1's exit
criteria exist specifically to convert that from an assumption into evidence. If Stage 1
cannot demonstrate per-team scoping, **do not proceed to Stage 2** — fix scoping first.

---

## Evidence base (re-verified 2026-06-08, file:line)

Package (`C:/Users/jkend/Documents/Projects/kompo/ai`):
- `src/Services/Actions/ModelMethodMutationAction.php:35-188` — approval-gated wrap of an
  existing domain method; `mutates()` hard-true (`:120`); `authorize()` re-resolves the
  exact row from seeded `entity_id` and fails closed (`:136-144`).
- `src/Services/Actions/ActionDispatcher.php:111-253` — render-time authorize → defer w/
  signed `PendingActionToken` → `approve()` re-authorizes + single-use nonce (`:243-248`).
- `src/Services/QueryExecutor.php:37-81` — `writeKeywords` rejected; `read_only_mode`
  default true; routes through `CypherScopeAnalyzer`/`SecurityFilterInjector`/
  `AiSecurityService`/`RateLimiter` (imports `:16-21`).
- `src/Kompo/AiChatFloating.php:57-69` — `page_context`-aware surface (modal/drawer/screen).
- `config/ai.php:1152` `entities => require entities.php`; `:1224-1257` `entity_actions`
  accepts a ready-made `AiActionInterface` (documents the `ModelMethodMutationAction` wire);
  `:1192-1201` action token TTL/config.
- `composer.json:34-36` — auto-registers `Condoedge\Ai\AiServiceProvider`.
- Bootstrap toolchain: `src/Console/Commands/{DiscoverEntities,EnhanceConfig,Validate
  Config,Setup,IngestEntities,SyncRelationships}Command.php`; `SetupCommand.php:11-32`
  (ingest→sync→index, `--discover/--fresh/--dry-run`).

SISC (`C:/Users/jkend/Documents/Projects/kompo/SISC`):
- `app/Providers/AppServiceProvider.php` — binds `PersonContactServiceContract:92`,
  `InscriptionServiceContract:95`, `InscriptionStateMachineContract:97`,
  `ReinscriptionServiceContract:101`, `EventsServiceContract:137`,
  `PersonEventServiceContract:142`, plus payment/membership services.
- `app/Kompo/Dashboard/DashboardView.php:9` imports `Condoedge\Ai\Kompo\AiChatModal`;
  `:64` `$canShowAiBtn = isSuperAdmin() || isImpersonated()`; `:73` commented-out trigger
  button; `:111-114` live `openAiPanel()` returning the package modal.
- `composer.json:32` `openai-php/client` (only `openai` ref outside the dead configs);
  `condoedge/ai` is NOT in require. `config/ai.php` does not exist; `config/ai-panel.php`
  and `config/ai-query-patterns.php` exist and are referenced nowhere in `app/`.
