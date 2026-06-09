# SISC AI Integration — Phase 1: Evidence-Backed Domain Map

Scope: read-only exploration of the SISC app (`C:/Users/jkend/Documents/Projects/kompo/SISC`) and the
symlinked `condoedge/ai` package, to map the surfaces the modern RAG + approval-gated-action assistant
must plug into. No SISC or package code was modified. All paths absolute; line numbers verified.

---

## 0. Headline findings (the load-bearing facts)

1. **The OLD `ai-panel` is a dead config-only shell.** `config/ai-panel.php` (694 B) and
   `config/ai-query-patterns.php` (10.7 KB) exist, but **no PHP/blade in the entire SISC tree (incl.
   `vendor/`) reads `config('ai-panel')`, `config('ai-query-patterns')`, or uses OpenAI** — verified by
   exhaustive `Select-String` over `*.php`/`*.blade.php`. Git shows only two commits touching them:
   `3448bc14 "Temp prototype of ai panel"` then `2e1acdb0 "Apply PHP-CS-Fixer rules"`. The translator
   class / panel component named in the brief was **never committed or was removed**. There is nothing
   to "migrate" — only two orphan config files to delete. `composer.json` still carries
   `openai-php/client: ^0.16.1` (line 32) purely for that dead prototype.

2. **`condoedge/ai` is present in `vendor/` but not required.** Its `composer.json` DOES auto-register
   `Condoedge\Ai\AiServiceProvider` via `extra.laravel.providers`. The ONLY reason the assistant is
   inert in SISC is that **`condoedge/ai` is absent from SISC `composer.json`'s require list** (compare
   the 12 other `condoedge/*` packages at lines 11-22), so Laravel package-discovery never picks up the
   provider. SISC uses the legacy `config/app.php` `providers` array (no `bootstrap/providers.php`).
   → The integration is **composer-require + config-publish + a thin host config**, not custom provider
   wiring.

3. **SISC already has the clean reuse seams the package's `ModelMethodMutationAction` was designed for.**
   Inscriptions/Events expose **bound service contracts and UseCases** (a formal state machine, a
   register-by-admin use case, an approval use case) — all registered in `AppServiceProvider`. The AI
   action layer wraps these EXISTING methods; it never needs a raw write. This is the single most
   important fact for "create/manage events & inscriptions through the assistant."

4. **Team scoping is uniform and matches the package's security model.** The domain models the assistant
   will touch (`Inscription`, `Event`) implement `Kompo\Auth\Contracts\Security\ScopedToTeam` with
   `BelongsToOneTeam`/`BelongsToTeamTrait`. The `User` extends `Kompo\Auth\Models\User` with
   `hasAccessToTeam()`, `getTeamsIdsWithPermission()`, `currentTeamRole`, `isSuperAdmin()`. Authorizers
   for AI actions can reuse these 1:1.

---

## 1. Core domain models + the registration write path

SISC domain = **Inscriptions / Events** (scout program registrations). Models split:
`app/Models/Events`, `app/Models/Inscriptions`, `app/Models/Crm` (Person), `app/Models/Teams`. Many base
classes live in the `condoedge/crm` package and are subclassed in SISC.

### 1.1 Event (the "program/event" model)
- `app/Models/Events/Event.php:49` — `class Event extends KompoEvent (Condoedge\Crm\Models\Event)
  implements Searchable, CalendarRelationContract, ScopedToTeam`.
- Status: `status` cast to `EventStatusType` (db-backed); enum `app/Models/Events/EventStatusEnum.php`
  (`READY=1, CLOSED=5, IN_PROGRESS=10, MISSING_INFO=15`, `:44 getStatusFromEvent()`).
- Type polymorphism: `event_type` → `EventTypeEnum`; per-type subclasses in
  `app/Models/Events/MainTypes/` (`ActivityEvent`, `CampEvent`, `TrainingEvent`, `WeeklyMeetingEvent`,
  `YearlyRegistrationEvent`) resolved at `Event.php:207 newFromBuilder()`. Each type carries a
  `creationHandler()` and `formSteps()` (`Event.php:393`). **Event creation is wizard/multi-step
  driven**, not a single setter — relevant for an AI "create event" action (see §4).
- Team scoping: `scopeForTeam` (`:674`), `scopeForTeamAndChildren` (`:690`), `baseSearchQuery()`
  audience-filtered (`:991`). Useful read scopes: `upcoming` (`:603`), `active` (`:641`), `future`
  (`:648`), `validated` (`:636`), `notDraft` (`:626`), `isTemplate`/`isNotTemplate`.
- Capacity / registration window: `getAvailablePlaces()` (`:529`), `isWithinRegistrationWindow()`
  (`:518`), `confirmedInscriptions()` (referenced `:531,:555`), `event_max_members`.
- Bound service: `App\Services\Events\EventsServiceContract` (bound `AppServiceProvider:137`) — currently
  minimal (`getAllChildrenEvents($templateId)` only). Also `PersonEventServiceContract`
  (`AppServiceProvider:142`) for event attendance.

### 1.2 Inscription (the "registration" model) — PRIMARY action target
- SISC class: `app/Models/Inscriptions/Inscription.php:17` — `extends Condoedge\Crm\Models\Inscription
  implements InvoiceableInterface`. Holds higher-level helpers (`acceptInscription()` `:116`,
  `getForCurrentRegistration()` `:145`, wizard handler resolution `:62`).
- Base class: `vendor/condoedge/crm/src/Models/Inscription.php:21` — `extends Model implements
  ScopedToTeam; use BelongsToOneTeam, BelongsToPersonTrait, BelongsToTeamTrait`. Key columns:
  `person_id`, `inscribed_by`, `team_id`, `event_id`, `role_id`, `related_inscription_id`, `status`,
  `type`, `qr_inscription`. Relations: `person()`, `inscribedBy()`, `event()`, `role()`,
  `relatedInscriptions()`/`parentInscription()`. Read scopes: `awaitingApproval` (FILLED) `:79`,
  `pending` `:84`, `approved` `:109`, `confirmed` `:99`, `forScoutYear` `:104`, `relatedToEmail` `:114`.
- Status enum: `vendor/condoedge/crm/src/Models/InscriptionStatusEnum.php` —
  `CANCELED=0, CREATED=1, FILLED=2, APPROVED=5, REJECTED=6, PENDING_PAYMENT=9,
  COMPLETED_SUCCESSFULLY=10, TT=15`; helpers `accepted()`, `completed()`, `getInsideStatuses()`.
- Type enum: `App\Kompo\Inscriptions\InscriptionTypeEnum` (BY_PARENT default `:73`/`:255`; plus SCOUT,
  VOLUNTEER, TRIAL_BY_PARENT, etc.). `basedInInscriptionForOtherPerson()` distinguishes parent-registers-
  child from self-registration (drives the `inscribed_by` vs `person_id` column choice).

### 1.3 The registration WRITE path — the reuse target for AI actions
This is the most important sub-map. SISC has a **clean service/usecase layer**, all bound in
`app/Providers/AppServiceProvider.php` (verified line numbers):

| Contract (bound) | Impl | AppServiceProvider |
| --- | --- | --- |
| `InscriptionServiceContract` | `InscriptionService` | line 95 |
| `InscriptionStateMachineContract` | `InscriptionStateMachine` | line 97 |
| `InscriptionMembershipServiceContract` | `InscriptionMembershipService` | line 99 |
| `ReinscriptionServiceContract` | `ReinscriptionService` | line 101 |
| `InscriptionPaymentServiceContract` | `InscriptionPaymentService` | line 131 |
| `EventsServiceContract` | `EventsService` | line 137 |
| `PersonEventServiceContract` | `PersonEventService` | line 142 |

**`InscriptionServiceContract`** (`app/Services/Inscriptions/InscriptionServiceContract.php`) is a
near-perfect AI action surface — small, documented, transactional:
- Creation: `createForPerson(type, personId, ?teamId, ?eventId, ?inscribedById, ?roleId): Inscription`
  (`:12`); `createRelated(parentInscription, childPersonId)` (`:14`); `getOrCreatePending(...)` (`:16`).
- Data: `linkPerson()`, `setSelectedTeam(inscription, teamId, ?Event)` (`:21`).
- **Transitions: `markFilled()`, `approve()`, `reject(?reason)`, `cancel()`, `setConfirmedStatus()`**
  (`:24-42`) — each documented with its side effects (e.g. `cancel()` also terminates the in-flight
  PersonTeam; `:30-40`).
- Queries: `isPaid()`, `hasPendingPayment()`.

**`InscriptionStateMachine`** (`app/Services/Inscriptions/InscriptionStateMachine.php`) enforces a formal
transition graph (`:25-50`) inside a `lockForUpdate` transaction (`:62-82`), throws
`InvalidInscriptionTransition`, and fires `InscriptionTransitioned`. Legal edges:
`NEW→CREATED`; `CREATED→{FILLED,APPROVED,CANCELED}`; `FILLED→{APPROVED,REJECTED,CANCELED}`;
`APPROVED→{PENDING_PAYMENT,COMPLETED_SUCCESSFULLY,CANCELED}`; `PENDING_PAYMENT→{COMPLETED,CANCELED}`;
REJECTED/COMPLETED/CANCELED/TT terminal. → Any AI status mutation routed through `approve/reject/cancel`
**cannot produce an illegal state**; the guard lives below the action.

**Admin "register a person" use case** — the canonical structured entry point:
- `app/Services/Inscriptions/UseCases/RegisterPersonByAdminUseCase.php:23 registerScout(RegisterScoutDTO):
  RegistrationResult` — find-or-create parent + child, link them, create BY_PARENT parent inscription +
  related child inscription, apply fields, `markFilled` both — all in one `DB::transaction` (`:25`).
- Input DTO: `app/Services/Inscriptions/Results/RegisterScoutDTO.php` (readonly: parentData, childData,
  teamId, linkTypeId, optional existing ids, eventId, residence/consent fields). **This DTO is the exact
  shape an AI "register a scout" action would assemble** from slot-filled conversation.
- UI that calls it today: `app/Kompo/Inscriptions/RegisterScoutDrawer.php` — a Kompo `Form` with
  `permissionKey = 'register_a_scout'` (`:23`), `handle()` (`:32`) → `InscriptionRegistrationPresenter::
  handleRegisterScout($dto)`. It resolves the unit's current Yearly-Registration event via
  `Team::getCurrentYearlyRegistrationEvent()` (`:45`) and validates age vs branch (`:206`). **This drawer
  is the human analogue of the AI action**: same DTO, same presenter, same permission key.
- Other use cases (same folder, reusable): `ApproveInscriptionUseCase`, `InviteVolunteerToTeamUseCase`,
  `CompletePaymentUseCase`, `ApproveBackgroundCheckUseCase`.
- Reinscription (renewal): `ReinscriptionServiceContract::startReinscription(parent, child)` invoked from
  `app/Kompo/Inscriptions/Traits/InscriptionsActionsTrait.php:16`.

Base-model convenience writes also exist (callable directly):
`Inscription::createForMainPerson()` (crm `:248`), `acceptInscription()` (crm `:328`),
`cancelInscription()` (crm `:334`, terminates PersonTeam), `setSelectedTeam()` (crm `:474`),
`confirmInscriptionFilled()` (crm `:468`). Prefer the SISC service layer over these for the side-effect
guarantees and event firing.

### 1.4 Person / Participant
- `app/Models/Crm/Person.php` (39 KB) `extends` base `vendor/condoedge/crm/src/Models/Person.php`. The
  "participant/registrant" entity. `User hasOne Person` via `user_id`. Find-or-create goes through
  `App\Services\Crm\PersonContactServiceContract` (bound `AppServiceProvider:92`,
  `createContactPerson()`, `linkContact()` — used by `RegisterPersonByAdminUseCase:29,34,37`).
- `Condoedge\Crm\Facades\PersonModel::find/findOrFail` is the canonical resolver (used throughout).

---

## 2. The OLD ai-panel — what it is, where it's wired, whether it's used

- **What it declares** (`config/ai-panel.php`): direct OpenAI (`openai_api_key`, `openai_model` default
  `gpt-3.5-turbo`), `max_rows=100`, and a READ-ONLY security posture —
  `allowed_operations: [select, where, whereIn, whereHas, with, orderBy, limit]`,
  `forbidden_operations: [create, update, delete, save, destroy, insert, truncate]`,
  `enforce_team_scopes: true`, `require_limit: true`, plus query logging. I.e. a **team-scoped, read-only
  Eloquent-query translator** — explicitly forbidding the create/manage capability the new goal requires.
- **Pattern library** (`config/ai-query-patterns.php`): ~10 KB of hardcoded keyword→suggestion buckets
  (bilingual EN/ES) for `user_specific`, `team_data`, `event_data`, `age_analysis`, `temporal_queries`,
  `statistical_analysis`, `geographical_queries`, `status_analysis`. (Note: ES strings, and "scouts/
  volunteers" vocabulary — looks copied from another scouting codebase, not SISC-tuned.)
- **Where it's wired / surfaced: NOWHERE.** No component class, route, blade, or service references these
  configs anywhere in SISC (verified incl. `vendor/`). No OpenAI usage anywhere under `app/`. The "Ai"
  filename matches are all false positives (Tr**ai**ning, M**ai**l, etc.).
- **Actively used? No.** Two-commit "Temp prototype", config-only, zero call sites. **Migration impact =
  delete two config files + drop `openai-php/client` from composer.** It replaces nothing functional; the
  only thing worth salvaging is the *intent* (team-scoped, query logging) which the new package already
  does better (model-agnostic RAG, structured query gen, action approval gate).

---

## 3. Auth / user / team scope (mirror for package security expectations)

- **User**: `app/Models/User.php:21` — `class User extends Kompo\Auth\Models\User implements Taskable,
  …, HasLocalePreference; use SiscRolesSpecificTrait, HasUserSettings, HasOnePersonTrait`.
  - `person()` hasOne via `user_id` (`:64`).
  - Team/permission API (inherited from Kompo Auth, used in SISC): `currentTeamRole`, `activeTeamRoles()`
    (`:135`), `hasAccessToTeam()` / `hasTeam()` (`:355-357`), `getTeamsIdsWithPermission('…')` (`:253`),
    `isSuperAdmin()` (`:131`), `getHighestAccessibleTeamLevel()` (`:129`).
  - Locale: `preferredLocale()` (`:365`) → user setting → person locale (assistant should honor for
    bilingual EN/FR responses).
- **Team**: `app/Models/Teams/Team.php` (28 KB) — `isMainLevel()` (`:191`), `isGroupLevel()` (`:201`),
  `isUnitLevel()` (`:206`), `teams()` children (`:87`), `events()` (`:97`),
  `getCurrentYearlyRegistrationEvent()` (`:306`), `getAllChildrenRawSolution()` (used in
  `Event::scopeForTeamAndChildren`). Hierarchy levels: MAIN=1 … GROUP … UNIT=4 (lower number = higher).
- **"Current scope" resolution**: global helpers `currentTeam()`, `currentTeamId()` (used pervasively,
  e.g. `RegisterScoutDrawer:29`, `Event:674`). `Inscription`/`Event` auto-scope via `ScopedToTeam` +
  `BelongsToOneTeam`; relations use `throughAuthorizedRelation()` (crm Inscription `:49,:55`) so reads are
  already permission-filtered.
- **Page the user is viewing** (`app/Kompo/Auth/AccountFormPage.php`): a thin settings `Form`
  (`extends Condoedge\Utils\Kompo\Common\Form`, `model = User`, `created(){ $this->model(auth()->user());
  }`) that renders `settingRow(...)` cards. It is a natural **mount point** for an assistant settings
  row / surface toggle, and a clean example of the host's Kompo `Form` idiom the chat surfaces should
  match. Not load-bearing for the action layer, but the most likely "where the user is exploring".

**Mapping to the package's `ModelMethodMutationAction` (`src/Services/Actions/ModelMethodMutationAction.php`):**
the action takes `finder($id)`, `mutator($model, $input)` (the EXISTING domain method), and
`authorizer($user, $model, $input)` (server-side gate, re-run at render AND before execute; `mutates()`
is hard-`true` so it's always deferred behind a signed `PendingActionToken`). SISC fills these as:
- `finder:` `\App\Models\Inscriptions\Inscription::class` (or `Event::class`) → `::find($id)`.
- `mutator:` `fn($insc,$in) => app(InscriptionServiceContract::class)->approve($insc)` (or `reject` /
  `cancel` / `setSelectedTeam`).
- `authorizer:` `fn($user,$insc,$in) => $user?->hasAccessToTeam($insc->team_id) && /* permission */`,
  mirroring `RegisterScoutDrawer::$permissionKey = 'register_a_scout'` and the Kompo `->can()` idiom the
  package's own example uses (`config/ai.php:1251`). The state machine below the service guarantees
  illegal transitions fail closed even if the authorizer is loose.

---

## 4. High-value assistant capabilities for SISC users (domain-specific)

Ranked by value × low glue cost. "Read" = needs only entities/schema config + RAG. "Write" = needs a
`ModelMethodMutationAction` (or generic action) over an existing service method, approval-gated.

**A. Answer questions (Read — highest immediate ROI, lowest risk)**
1. Inscriptions: "How many pending (FILLED/awaiting-approval) inscriptions for my unit?", "Who hasn't
   paid?" — backed by `Inscription` scopes `awaitingApproval/pending/approved` + `isPaid()`. Team-scoped
   automatically.
2. Events: "What events are coming up?", "How many spots left in the camp?", "Which events are missing
   info / not validated?" — `Event` scopes `upcoming/active/future/validated/notDraft`,
   `getAvailablePlaces()`, `EventStatusEnum`.
3. Person/registrant: "Show me <child>'s registration status", "Does <person> have an approved background
   check?" — `Inscription::relatedToEmail`, `Person`, `BackgroundCheckStatusEnum`.
4. Roll-ups: per-unit/group registration counts, scout-year comparisons (`forScoutYear`), capacity vs
   confirmed. This is exactly what the dead ai-panel *tried* to do, now done safely + model-agnostic.

**B. Create / register through the assistant (Write — the differentiating capability)**
5. **"Register <child> with parent <parent> in <unit>"** → assemble `RegisterScoutDTO` →
   `RegisterPersonByAdminUseCase::registerScout()`. Slot-filling maps to DTO fields; the YR-event and
   age/branch validation already live in the existing path. This is the single highest-value write — it
   replaces the multi-section `RegisterScoutDrawer` with a conversational flow, gated by
   `register_a_scout` permission + an approval card.
6. **Renew / reinscribe** → `ReinscriptionServiceContract::startReinscription(parent, child)`.
7. Volunteer onboarding → `InviteVolunteerToTeamUseCase` / `InviteToTeamForm` equivalent.

**C. Manage inscription status (Write — approval-gated, state-machine-guarded)**
8. **Approve / reject / cancel an inscription** → `InscriptionServiceContract::approve|reject|cancel`,
   each a one-line `ModelMethodMutationAction` mutator. The `InscriptionStateMachine` makes these safe by
   construction. "Approve this pending inscription" with the inscription as the page-context anchor is the
   marquee agentic demo.
9. Mark/confirm payment-complete → `CompletePaymentUseCase` / `markAsPaid()`.

**D. Event management (Write — higher glue cost, do later)**
10. Create / edit an event: NOT a single setter — gated behind `EventTypeEnum::creationHandler()` +
    `formSteps()` wizard and `beforeSave/afterSave` status hooks (`Event.php:227-244`). An AI "create
    event" action should either (a) wrap a future `EventsService::createFromType($type, $attrs)` facade
    method, or (b) hand off to the existing wizard with prefilled props, rather than poking columns. Flag
    as a Phase-2/3 item needing a thin service method, not a raw mutation.

**Why users would prefer the AI over the app (SISC-specific):**
- The app's registration is a long multi-section drawer (`RegisterScoutDrawer` ~ parent + scout + rel +
  unit + family + consents). Conversational slot-filling + a single approval card is dramatically faster
  for admins doing many registrations.
- Approval/cancel today require navigating pending lists per unit; "approve the pending inscription for
  <name>" with page-context anchoring collapses that to one turn.
- Cross-cutting reads ("how full is each unit's YR event", "who's unpaid this scout-year") span scopes
  the UI shows only piecemeal.
- Page-awareness (`page_context`, package `PageContextSection`, priority ~16) lets "approve this" / "add
  a scout here" resolve the concrete anchor when opened from an inscription/event/unit screen.

---

## 5. Integration seams summary (for Phase 2 design)

| Need | SISC provides (evidence) | Package expects |
| --- | --- | --- |
| Activate package | `condoedge/ai` in `vendor/` + auto-provider in its composer | **add to SISC `composer.json` require** + publish `config/ai.php` |
| Read layer (entities/schema) | `Event`, `Inscription`, `Person`, `Team` models + scopes | `config/ai.php` entities/discovery config (none in SISC yet) |
| Register-a-scout (write) | `RegisterPersonByAdminUseCase` + `RegisterScoutDTO` | generic/structured action assembling DTO |
| Status mutations (write) | `InscriptionServiceContract::approve/reject/cancel` + state machine | `ModelMethodMutationAction` (one per verb) under `entity_actions['Inscription']` |
| Authorization | `User::hasAccessToTeam/getTeamsIdsWithPermission`, `permissionKey` idiom | `authorizer($user,$model,$input)` closures |
| Team scoping | `ScopedToTeam` + `currentTeamId()`/`throughAuthorizedRelation()` | tenant scoping already assumed by package |
| Chat surface mount | `Condoedge\Utils\Kompo\Common\Form` pages (e.g. `AccountFormPage`, dashboards) | `AiChatFloating(null, ['page_context'=>…])`, `chat.surface` modal/drawer/screen |
| Remove legacy | delete `config/ai-panel.php`, `config/ai-query-patterns.php`; drop `openai-php/client` | n/a |

### Key file references (absolute)
- `C:/Users/jkend/Documents/Projects/kompo/SISC/app/Services/Inscriptions/InscriptionServiceContract.php`
- `C:/Users/jkend/Documents/Projects/kompo/SISC/app/Services/Inscriptions/InscriptionStateMachine.php`
- `C:/Users/jkend/Documents/Projects/kompo/SISC/app/Services/Inscriptions/UseCases/RegisterPersonByAdminUseCase.php`
- `C:/Users/jkend/Documents/Projects/kompo/SISC/app/Services/Inscriptions/Results/RegisterScoutDTO.php`
- `C:/Users/jkend/Documents/Projects/kompo/SISC/app/Kompo/Inscriptions/RegisterScoutDrawer.php`
- `C:/Users/jkend/Documents/Projects/kompo/SISC/app/Models/Inscriptions/Inscription.php`
- `C:/Users/jkend/Documents/Projects/kompo/SISC/vendor/condoedge/crm/src/Models/Inscription.php`
- `C:/Users/jkend/Documents/Projects/kompo/SISC/vendor/condoedge/crm/src/Models/InscriptionStatusEnum.php`
- `C:/Users/jkend/Documents/Projects/kompo/SISC/app/Models/Events/Event.php`
- `C:/Users/jkend/Documents/Projects/kompo/SISC/app/Models/Events/EventStatusEnum.php`
- `C:/Users/jkend/Documents/Projects/kompo/SISC/app/Models/User.php`
- `C:/Users/jkend/Documents/Projects/kompo/SISC/app/Providers/AppServiceProvider.php` (bindings 95-142)
- `C:/Users/jkend/Documents/Projects/kompo/SISC/config/ai-panel.php` + `config/ai-query-patterns.php` (DEAD)
- `C:/Users/jkend/Documents/Projects/kompo/ai/src/Services/Actions/ModelMethodMutationAction.php`
- `C:/Users/jkend/Documents/Projects/kompo/ai/config/ai.php` (entity_actions 1224, chat surface 1015)
