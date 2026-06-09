# SISC ↔ condoedge/ai Integration Plan

Date: 2026-06-08
Status: DESIGN ONLY (no SISC or package code changed by this document)
Author: exploration + design pass

Scope of this doc: how to make the modern `condoedge/ai` package "take a worthy
place" in SISC (Inscriptions/Events domain) with **minimal SISC-specific glue**,
including the ability to **manage events/inscriptions through the assistant** via the
package's existing approval-gated `ModelMethodMutationAction`. It also validates how
abstract the package really is (the key deliverable: PACKAGE-ABSTRACTION GAPS).

---

## 0. Evidence baseline (what is actually true today)

Package (`C:/Users/jkend/Documents/Projects/kompo/ai`):
- `config/ai.php` is fully model-agnostic: `identity` (name/persona/non_disclosure),
  `project` (name/description/domain/business_rules), `entity_actions`,
  `generic_actions`, `actions` (+ `token_ttl`), `model_namespaces` default `['App\Models']`.
- `config/entities.php` is an all-comment template; **no entities defined**.
- `src/Services/Actions/ModelMethodMutationAction.php` — the ONE reusable
  approval-gated write. Wraps an EXISTING domain method. `mutates()` is hard-`true`,
  so the dispatcher defers it behind a signed `PendingActionToken` + a fresh
  server-side `authorize()` pass. Constructor: `key, label, finder|class, mutator,
  authorizer, aliases, description, inputSchema, entityType, summary`.
- Action registry (`AiActionRegistry::resolveConfigEntry`) accepts a ready-made
  `AiActionInterface` instance dropped straight into `entity_actions`/`generic_actions`
  config and returns it as-is (typed `mutates()`/`authorize()` survive).
- Chat surfaces: `AiChatModal`, `AiChatDrawer`, `AiChatScreen` (routed `ai.chat.screen`),
  `AiChatPanel`, and `AiChatFloating` (FAB; surface chosen by `config('ai.chat.surface')`).
  All accept a single array prop `page_context` rebuilt into the `PageContext` DTO
  (`route, entity_type, entity_id, filters, label`). Page context is a SOFT PRIOR only.
- **No CondoEdge domain hardcoding in `src/`** (grep for condo/unit/reservation/tenant
  hit only `vendor/condoedge/ai` asset paths and doc-comment examples). Package is
  genuinely reusable.

SISC (`C:/Users/jkend/Documents/Projects/kompo/SISC`):
- `condoedge/ai` is **symlinked into `vendor/condoedge/ai`** but is **NOT** in
  `composer.json` require, **NOT** in `vendor/composer/installed.json`, **NOT** in
  `autoload_psr4.php`, and `AiServiceProvider` is registered nowhere. So the package's
  classes do not even autoload today.
- `app/Kompo/Dashboard/DashboardView.php` already `use`s `Condoedge\Ai\Kompo\AiChatModal`
  and has `openAiPanel(){ return new AiChatModal(); }`, but the trigger button is
  **commented out** and gated to `isSuperAdmin() || isImpersonated()`.
- Old AI stack: `config/ai-panel.php` (direct OpenAI gpt-3.5-turbo, READ-ONLY query
  translator, forbids create/update/delete) + `config/ai-query-patterns.php`. The only
  live reference to anything "ai-panel" is the commented DashboardView button. It is
  effectively orphaned.
- Domain: `App\Models\Inscriptions\Inscription` (extends `Condoedge\Crm\Models\Inscription`),
  `App\Models\Events\Event` (extends `Condoedge\Crm\Models\Event`), `App\Models\Crm\Person`.
- Registration orchestration: `App\Services\Inscriptions\InscriptionServiceContract`
  (`createForPerson`, `getOrCreatePending`, `approve`, `reject`, `cancel`) and
  `App\Services\Inscriptions\UseCases\ApproveInscriptionUseCase::approveAndSendEmail(Inscription): ApprovalResult`.
- Authorization is **kompo-auth**, not Laravel policies: `checkAuthPermission($key,
  PermissionTypeEnum::WRITE, $teamId)` / element macro `checkAuthWrite($key, specificTeamId:)`.
  Real permission keys: `register_a_scout`, `reviewing_camps`, `invite_to_team`,
  `move_member_between_teams`, `add_new_team` (from `CreateNeededPermissionsCommand` and UI).
- Models live in nested namespaces (`App\Models\Inscriptions\…`), not flat `App\Models\…`.

---

## 1. Recommended approach (and why)

**Install the package as a first-class composer dependency, then drive ALL SISC
glue through the two published config files (`config/ai.php` + `config/entities.php`)
and a tiny `app/Ai/` glue folder of closures — write NO new package code and NO new
SISC service code.** Mutations are exposed exclusively by wrapping SISC's *existing*
domain methods (`ApproveInscriptionUseCase`, `InscriptionService`) in the package's
`ModelMethodMutationAction`, whose `authorize()` callback delegates to kompo-auth's
`checkAuthPermission`. This is recommended because (a) the package is already
model-agnostic with zero CondoEdge hardcoding, so the entire integration is
configuration + thin closures; (b) routing every write through an existing,
already-authorized SISC method means the assistant can never bypass app rules, and the
package's signed-token + re-`authorize()` gate is inherited for free; (c) it keeps the
old read-only ai-panel decommissionable without risk because the new stack supersedes it.

---

## 2. Files checklist (grouped by layer)

### Layer A — Composer / provider wiring (the actual blocker)
- [ ] `SISC/composer.json` — add `"condoedge/ai": "*"` to `require` and a `path`
      repository pointing at `../ai` (mirroring how the symlink already resolves), so the
      package autoloads and `AiServiceProvider` is discovered (it is in the package's
      `extra.laravel.providers`). Run `composer update condoedge/ai`.
      *Until this lands, `DashboardView`'s `use Condoedge\Ai\Kompo\AiChatModal` is a
      latent fatal — the class does not autoload.*
- [ ] Provision env / `config/ai.php` provider deps that the service provider binds
      eagerly: vector store (Qdrant), graph store (Neo4j), embedding + LLM providers.
      For a phased rollout, the chat/action surface can run with file_context disabled
      and a single LLM provider; graph/vector are only needed once entities are ingested.

### Layer B — SISC `config/ai.php` (publish, then edit) — IDENTITY + PROJECT, not model-named
- [ ] `php artisan vendor:publish` the package `ai.php` to `SISC/config/ai.php`.
- [ ] `identity.name` = e.g. "SISC Assistant" (project-branded, NEVER the LLM model).
- [ ] `identity.persona` = registration/events helper voice (see snippet below).
- [ ] Keep `identity.non_disclosure` as shipped (model/vendor non-disclosure).
- [ ] `project.name` / `project.description` / `project.domain` = "scouting registrations
      / events & inscriptions". `project.business_rules` = a handful of true SISC rules
      (e.g. "An inscription belongs to a team and an event"; "An inscription is approved
      via the accept-inscription flow"; "Inscriptions have a status lifecycle:
      CREATED → FILLED → APPROVED → COMPLETED_SUCCESSFULLY").
- [ ] `model_namespaces` — add `App\Models\Inscriptions`, `App\Models\Events`,
      `App\Models\Crm` (SISC nests models; the default flat `App\Models` misses them).
- [ ] `chat.surface` — choose `modal` (matches the existing DashboardView intent).
- [ ] `entity_actions` — the SISC mutations (Layer D).
- [ ] `generic_actions` — navigation links (Layer D).
- [ ] `file_context` — **leave disabled / minimal**. Per the brief SISC has no document
      corpus to RAG over; skip File entity + chunk ingestion for v1.

### Layer C — SISC `config/entities.php` (publish, then edit) — DOMAIN MODELS + embed_fields
Generate a first cut with `php artisan ai:discover` (after Layer A), then hand-curate.
Define at minimum:
- [ ] `Person` — `embed_fields: ['first_name','last_name']`; metadata aliases
      ['person','people','member','scout','volunteer','parent']; common_properties for
      `email_identity`, `first_name`, `last_name`.
- [ ] `Event` — `embed_fields: ['name','description']` (Event has name/description; many
      label-casts e.g. `team_id`, `location_id`); aliases ['event','events','camp',
      'activity','program']; scopes for `status` (EventStatusEnum) and `event_type`;
      graph relationship `BELONGS_TO_TEAM` (team_id).
- [ ] `Inscription` — `embed_fields: []` or minimal (mostly relational/status, little free
      text); aliases ['inscription','registration','enrolment','signup']; common_properties
      for `status`, `type`, `team_id`, `event_id`, `person_id`; graph relationships
      `FOR_PERSON` (person_id), `IN_EVENT` (event_id), `IN_TEAM` (team_id).
- [ ] `Team` — graph-only (no vector); aliases ['team','teams','unit','group','branch'].
Each SISC domain model that you want queryable must `implements Nodeable` + `use
HasNodeableConfig` (the trait reads `config/entities.php`). This is a **one-line trait add
per model** — borderline "package src change-adjacent" but it is host-model code, allowed.

### Layer D — SISC actions (`app/Ai/` glue + config entries)
Mutations (entity_actions): ready-made `ModelMethodMutationAction` instances placed in
`config/ai.php` `entity_actions`. Recommended set, all wrapping EXISTING methods:
- [ ] `Inscription.approve_inscription` → wraps `ApproveInscriptionUseCase::approveAndSendEmail`
      (the worked example, §3). HIGH VALUE, single-id, clean authorizer.
- [ ] `Inscription.reject_inscription` → wraps `InscriptionServiceContract::reject($insc, $reason)`
      (`reason` as a declared `inputSchema` param).
- [ ] `Inscription.cancel_inscription` → wraps `InscriptionServiceContract::cancel($insc)`.
Navigation (generic_actions / entity_actions, READ-only closures — no approval needed):
- [ ] `generic_actions.register_a_scout` → opens `RegisterScoutDrawer` (the existing
      `register_a_scout`-gated drawer) via `_Link()->selfGet(...)->inDrawer()`. This is the
      pragmatic answer to "create through the assistant" for the multi-field CREATE flow:
      the assistant *navigates the user into the existing wizard* rather than reconstructing
      a 20-field DTO (see §4 gap).
- [ ] `entity_actions.Inscription.view` / `Event.view` → `_Link($text)->href(route(...))`.
- [ ] `entity_actions.Event.registration_dashboard` → link to the event's registration board.

### Layer E — Chat mount points + page_context shape
- [ ] **DashboardView** (`app/Kompo/Dashboard/DashboardView.php`): uncomment the
      `_Button2('lilhelper.ask-me')->selfGet('openAiPanel')->inModal()`; keep `openAiPanel()`
      returning `new AiChatModal(...)`. Decide whether to keep the `isSuperAdmin() ||
      isImpersonated()` gate for a staged rollout (recommended for v1) or open to all admins.
- [ ] **Event detail / registration dashboard view** — mount `AiChatFloating` (FAB) OR an
      `AiChatModal` button, passing `page_context`:
      ```php
      ['route' => 'event.show', 'entity_type' => 'Event',
       'entity_id' => $event->id, 'label' => $event->name,
       'filters' => ['team_id' => $event->team_id]]
      ```
- [ ] **Inscription detail / PendingInscriptionsList** — same FAB pattern with
      `entity_type => 'Inscription', entity_id => $inscription->id`, so "approve this one"
      resolves the focused record without the user repeating the id.
- [ ] Optional: register the FAB once in the app shell layout for global availability;
      page-aware hosts override its `page_context` prop.

### Layer F — Old ai-panel coexistence/migration
- [ ] **Decision: deprecate, do not delete (v1); remove in a follow-up.** The old
      `config/ai-panel.php` + `config/ai-query-patterns.php` are read-only and orphaned
      (only the commented DashboardView button referenced them). Coexistence is safe because
      they share no service bindings with the new package. Steps:
      1. v1: leave the files in place but route the DashboardView "ask-me" button to the new
         `AiChatModal` (it already does). Add a short note in `config/ai-panel.php` marking it
         superseded.
      2. v2 (after the new stack is validated in prod): delete `ai-panel.php`,
         `ai-query-patterns.php`, and any `AiPanel*`/translator classes; grep confirms the
         blast radius is the single DashboardView reference.
   Migration of `ai-query-patterns.php` content: fold any genuinely useful business phrasings
   into `config/entities.php` `metadata.scopes[].examples`, not into the new query patterns
   wholesale (the new pattern library is graph/Cypher-shaped, not Eloquent-shaped).

---

## 3. Concrete example — ONE high-value SISC mutation

**Approve an inscription**, wrapping the real `ApproveInscriptionUseCase::approveAndSendEmail`
(returns an `ApprovalResult`, Kompo-free — ideal for a mutator). Authorizer delegates to
kompo-auth's team-scoped `register_a_scout`/`reviewing_camps` write check.

Place this instance in `SISC/config/ai.php` under `entity_actions`:

```php
// config/ai.php  (SISC)
use App\Models\Inscriptions\Inscription;
use App\Kompo\Inscriptions\Presenters\InscriptionApprovalPresenter;
use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

'entity_actions' => [
    'Inscription' => [
        'approve_inscription' => new ModelMethodMutationAction(
            key: 'approve_inscription',
            label: 'Approve inscription',

            // finder: resolve the exact row the action will touch. A class-string is
            // resolved via ::find($id) by the package; a closure lets us eager-load.
            finder: fn ($id) => Inscription::with(['person', 'team', 'event', 'invoice'])->find($id),

            // mutator: the ONLY write site — the EXISTING approval orchestration.
            // Runs only AFTER approval + a fresh authorize() pass.
            mutator: function (Inscription $inscription, array $input) {
                $result = app(InscriptionApprovalPresenter::class)
                    ->handleApproveAndSendEmail($inscription); // wraps ApproveInscriptionUseCase
                // (Presenter returns a Kompo response on success; the UseCase itself
                //  returns ApprovalResult. If you want a pure-domain mutator, call
                //  app(ApproveInscriptionUseCase::class)->approveAndSendEmail($inscription)
                //  directly and let it throw on failure.)
            },

            // authorizer: SERVER-SIDE gate, re-run at render AND right before the write.
            // Team-scoped kompo-auth WRITE check against the inscription's own team.
            authorizer: fn ($user, Inscription $inscription, array $input): bool =>
                $user !== null
                && $inscription->status?->value !== \Condoedge\Crm\Models\InscriptionStatusEnum::COMPLETED_SUCCESSFULLY->value
                && checkAuthPermission(
                    'register_a_scout',                 // real SISC permission key
                    PermissionTypeEnum::WRITE,
                    $inscription->team_id               // specific team scope
                ),

            aliases: ['approve inscription', 'accept inscription', 'approve registration', 'accept this registration'],
            description: 'Approve a pending inscription and send the confirmation email.',
            entityType: 'Inscription',
            summary: 'The inscription was approved and the confirmation email was sent.',
        ),
    ],
],
```

Why this one: single existing-record id (fits the `entity://Inscription/{id}/approve_inscription`
link + token round-trip exactly), the mutator wraps a transactional method the UI already
calls (`InscriptionAnswerForm::acceptInscription`), and the authorizer reuses the exact
kompo-auth key (`register_a_scout`, team-scoped) the existing UI uses on the same surface
(`PendingInscriptionsList` → `checkAuth('register_a_scout', specificTeamId: team_id)`).

---

## 4. PACKAGE-ABSTRACTION GAPS (the validation deliverable)

The package is **substantially clean and reusable** — evidence: zero CondoEdge domain
literals in `src/`; mutation/action/identity/entities are all config-driven; chat surfaces
take a generic `page_context` array. SISC needs essentially no package code. The honest gaps:

1. **CREATE/multi-field mutations are not a first-class pattern (the real gap).**
   `ModelMethodMutationAction` is architecturally built around an **existing record id**:
   `finder($id)` + the `entity://Type/{id}/key` path + the id riding the approval token so
   `authorize()` can re-resolve the row. A genuine CREATE (e.g. "register a brand-new scout
   to the fall camp") has **no pre-existing id** and a large structured payload
   (`RegisterScoutDTO` has ~20 fields). There is no package abstraction for "collect typed
   params for a NEW entity, validate, then create." Two viable paths:
   - **v1 (recommended, no package change):** expose creation as a *navigation* action that
     opens the existing `RegisterScoutDrawer` wizard. The assistant routes the user into the
     real, already-validated UI. Honest and low-risk.
   - **v2 (package enhancement):** add a sibling `ModelMethodCreateAction` (or a `requiresId
     => false` mode) that takes only `inputSchema` (no finder), runs `authorize($user, null,
     $input)`, and calls a `creator($input)` callable — reusing the same signed-token gate.
     This is the one concrete abstraction the package is missing for "manage events/inscriptions."

2. **Authorizer signature assumes a model instance.** `ModelMethodMutationAction::authorize`
   is `(user, model, input)` and fails closed when the model can't be resolved. SISC's
   `checkAuthPermission` is global/team-keyed, not model-keyed, so it composes fine — but a
   CREATE action (gap #1) needs an authorizer that runs with `model = null`. Today
   `authorize()` returns false when the finder yields null, which would block any create.
   Tie-in with #1.

3. **Model namespace default is flat `App\Models`.** SISC nests
   (`App\Models\Inscriptions\Inscription`). `model_namespaces` is config-overridable so this
   is a config fix, not a code gap — but `ai:discover` and short-name → FQCN resolution
   (`ResolvesEntityConfigKey`) should be spot-checked against the nested layout.

4. **No kompo-auth bridge helper.** Every SISC authorizer closure will repeat
   `checkAuthPermission($key, WRITE, $teamId)`. Not a blocker, but a tiny optional package
   convenience (a `PermissionAuthorizer` value object the host configures with a key) would
   reduce boilerplate. Cleanly host-side for now.

5. **Mutator/Presenter return-type mismatch (host-side, noted for clarity).** SISC
   Presenters return Kompo responses; UseCases return `Result` objects. The mutator should
   call the **UseCase** (domain) layer, not the Presenter (Kompo) layer, so the action stays
   UI-framework-agnostic. This is a host wiring choice, not a package gap.

Verdict: the package is **ready for read + single-record-mutation SISC use today** with
config-only glue. The **one** package-level enhancement worth doing for the full "manage
events/inscriptions" brief is a **create-mode action** (gap #1/#2). Everything else is config.

---

## 5. Ordered, low-risk execution sequence

1. **Make it load.** Add `condoedge/ai` to `SISC/composer.json` (path repo → `../ai`),
   `composer update condoedge/ai`, confirm `AiServiceProvider` boots and the (currently
   latent) `DashboardView` import resolves. No behavior change yet.
2. **Publish + brand config.** Publish `ai.php` + `entities.php`. Fill `identity` + `project`
   (Layer B). Add nested `model_namespaces`. Leave actions/entities empty. Pick `chat.surface
   => modal`.
3. **Stand up read-only chat behind a flag.** Uncomment the DashboardView "ask-me" button,
   keep the `isSuperAdmin()||isImpersonated()` gate. Verify a plain "what events do we have?"
   round-trips (needs entities + ingestion; until then the chat answers generically). This
   proves the surface end-to-end with zero write risk.
4. **Define entities + ingest.** Curate `config/entities.php` for Person/Event/Inscription/Team,
   add `Nodeable`+`HasNodeableConfig` to those 4 models, run discovery + ingestion. Validate
   natural-language *reads* over the real graph.
5. **Add ONE mutation.** Wire `Inscription.approve_inscription` (§3). Test the full
   approval-card round-trip: render → signed token → confirm → fresh `authorize()` → write.
   Confirm denial when the user lacks `register_a_scout` on that team, and idempotency
   (already-COMPLETED inscription is refused by the authorizer).
6. **Add the rest of the read-safe mutations** (`reject`, `cancel`) + navigation actions
   (`view`, `registration_dashboard`, `register_a_scout` drawer-open).
7. **Page-aware mounts.** Add the FAB / button + `page_context` to Event and Inscription
   detail views so "approve this one" / "show registrations for this event" work in-context.
8. **(Optional, package change) create-mode action.** If product wants true in-chat creation
   beyond the wizard-open, implement `ModelMethodCreateAction` in the package (gap #1) and add
   a `register_scout` create action — separate PR, gated behind its own permission.
9. **Decommission old ai-panel.** Mark `ai-panel.php`/`ai-query-patterns.php` deprecated (v1);
   delete them + translator classes once the new stack is validated in prod (v2). Migrate any
   useful phrasings into `entities.php` scope examples.
10. **Widen rollout.** Relax the superadmin gate to the appropriate permission once confident.

Risk ordering rationale: steps 1–4 add **zero write capability**; the first write (step 5)
is the single best-understood, already-authorized method behind the package's own
double-gate; CREATE (the one package gap) is deferred to last and isolated.
