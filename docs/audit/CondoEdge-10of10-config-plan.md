# CondoEdge AI — 10/10 Configuration Plan

> Goal: make the `condoedge/ai` assistant a top-level, genuinely-useful AI for CondoEdge's
> END USERS (residents, board members, property managers/staff) — one they prefer over
> clicking through the app.
>
> Host app: `C:/Users/jkend/Documents/Projects/kompo/CondoEdge` (Laravel + Kompo) consuming
> package `condoedge/ai` at `C:/Users/jkend/Documents/Projects/kompo/ai`.
>
> File to enrich (the ONLY file you edit to implement this plan): **`C:/Users/jkend/Documents/Projects/kompo/CondoEdge/config/ai.php`**
> plus a handful of host Kompo view classes to mount the chat surface with `page_context`.
>
> **Be additive. Never remove working config.** Every route/component/relationship below was
> verified against the host source — citations are inline (`web.php:NNN`, class path, or
> entity catalog).

---

## Domain & personas (brief)

### The models (graph `label` = the `entity://Type/...` key the AI emits; all implement `Nodeable`)

| Concept | Class (graph **label**) | Key relationships used below |
|---|---|---|
| Condo association / syndicate | `App\Models\Condo\Union` (**Union**) | incoming `UNITS`, `MEETINGS`, `RESOURCES`, `ANNOUNCEMENTS`, `BOARD_MEMBERS`; `TEAM`→Team |
| Physical building | `App\Models\Condo\Building` (**Building**) | `BELONGS_TO_UNION`→Union; `HAS_UNITS`→Unit |
| Unit / lot | `App\Models\Condo\Unit` (**Unit**) | `BELONGS_TO_UNION`→Union; pivot `contact_unit`→Contact; `HAS_VEHICLES`,`HAS_PETS`,`HAS_ACCESSES`,`HAS_EXTRAS` |
| Person / contact | `App\Models\Contact\Contact` (**Contact**) | `user_id`→User; `contactUnits`→ContactUnit; `units` (belongsToMany via `contact_unit`) |
| Owner/renter link (pivot) | `App\Models\Contact\ContactUnit` (**ContactUnit**) | `CONTACT`→Contact, `UNIT`→Unit; `link_id` (1 owner-occ, 2 owner-not-occ, 3 renter, 4 occupant), `sold_at` |
| Board membership | `App\Models\Contact\BoardMember` (**BoardMember**) | `BELONGS_TO_UNION`→Union; →Contact; `year`, role |
| Document / file | `App\Models\File` (**File**) | morph `parent` (Unit/Union/…); ACL via `accessibleBy($user)` — already wired |
| Receivable / contribution | `App\Models\Finance\Invoice` (**Invoice**) | **polymorphic** `CUSTOMER` (customer_type/customer_id → unit\|contact\|union); `BELONGS_TO_UNION` |
| Supplier bill (payable) | `App\Models\Finance\Bill` (**Bill**) | `BELONGS_TO_UNION`→Union (staff-side) |
| Budget / accounts | `App\Models\Finance\{Budget,Account,Transaction}` | union/team scoped (staff) |
| Meeting (AGM/board) | `App\Models\Meetings\Meeting` (**Meeting**) | `BELONGS_TO_UNION`→Union; `Motion`,`Vote` |
| Announcement / notice | `App\Models\Condo\Announcement` (**Announcement**) | `BELONGS_TO_UNION`,`BELONGS_TO_UNIT`; `AnnouncementRead` |
| Shared amenity | `App\Models\Condo\Resource` (**Resource**) | `BELONGS_TO_UNION`→Union; `HAS_RESERVATIONS`→Reservation |
| Booking | `App\Models\Condo\Reservation` (**Reservation**) | `RESOURCE`→Resource, `USER`→User, `CONTACT`→Contact |
| Parking / storage | `App\Models\Condo\Extra` (**Extra**) | `UNIT`→Unit; `type_id` (1 parking, 2 storage) |
| Vehicle | `App\Models\Condo\Vehicle` (**Vehicle**) | `UNIT`→Unit (outgoing; inverse `HAS_VEHICLES`) |
| Pet | `App\Models\Condo\Pet` (**Pet**) | `UNIT`→Unit (outgoing; inverse `HAS_PETS`) |
| Access device | `App\Models\Condo\Access` (**Access**) | `UNIT`→Unit; inverse `HAS_ACCESSES` |
| Supplier / project / maintenance | `App\Models\Supplier\{Supplier,Project,Maintenance}` | union/team scoped |

### Roles (from `App\Models\Traits\HasRoles`) and route tiers (verified in `routes/web.php`)

- **Resident / Owner** — a `Contact` with no team role (`isMereContact()`). Lands on `union.view`.
- **Board member** — a Contact with a `BoardMember` record; frequently has `Role::team()` read access.
- **Property manager / staff** — `Role::team|permanent|lead|teamOwner|superAdmin`. Lands on `dashboard`.

Route tiers (the group middleware that gates each route — this is what decides whether an action
is safe to offer a resident):

- **Resident-reachable** = the `has-plan` group (`web.php:109`, NO role middleware): `unit.view` (124),
  `union.view` (128), `reservations.form` (130), `support.form` (541), `report.unit-statement` (399 — it
  lives under `report.` prefix which is **NOT** inside a `Role::permanent` group, see caveat #1), `calendar` (118),
  `docs`/`help` (113/114), `meetings.page` (152), `suppliers.page` (135), `projects.page` (137), `maintenances.page` (142).
- **Staff-gated** = inside `Role::team()` (243/448/473/488/532/545), `Role::permanent()` (275/392/402/435),
  `Role::lead()` (518), `Role::superAdmin()` (315/647): `dashboard`, `contacts.form` (472, under team group),
  `invoices.form` (281, permanent), `bills.form` (285, permanent), `budget.view` (292, permanent),
  `transactions.form` (300, permanent), `union.manager` (277, permanent), `announcements.form` (525, team),
  `vehicles.form` (528, team), `accesses.form` (529, team), `file-info.form` (510, team), `union.stats` (165, team).

> **Implication:** offering a staff-gated route as an action to a resident 403s. Gate every staff
> action with the `'authorize'` closure (see §"The exact config to add").

### The 3 personas' top tasks (the questions the assistant must nail)

- **Resident:** "What's my balance / how much do I owe?", "Show my account statement", "Where are my
  building's bylaws?", "When is the next meeting?", "Book an amenity", "Submit a support request",
  "Show my unit / my vehicles / my pets", "What announcements are there for my building?", "Who is on the board?"
- **Board member:** "Who is behind on payments?", "What's our budget / reserve fund?", "Show the financial
  statements", "What bills are pending approval?", "Agenda & vote results for the next meeting", "Owners
  registry", "Open maintenance / work orders", "Our suppliers".
- **Manager / staff:** "Create a unit / owner / invoice / bill / announcement / meeting", "This month's
  delinquencies", "Open unit 310 / find contact Y", "Open the union settings", "Add a vehicle/access to a unit".

---

## What exists vs what's missing

Verified against `CondoEdge/config/ai.php`, `config/ai-entities/*.php`, `routes/web.php`, and the package
grammar (`ai/src/Services/Response/ActionLinkHandler.php`, `ai/src/Services/Actions/ConfigAiAction.php`).

| Config area | Current state (host) | Concrete additions needed |
|---|---|---|
| **`user_entity_paths`** | 5 paths defined (Unit, Contact, Invoice, Vehicle, Pet). **Two are buggy:** `Invoice` uses a non-existent `INVOICED_TO_UNIT` rel (Invoice is polymorphic `CUSTOMER`); `Pet` uses `BELONGS_TO_UNIT` but Pet→Unit is rel type `UNIT`. | **Fix** Invoice (polymorphic `customer_type/customer_id`) and Pet (`<-[:UNIT]-`). **Add** Announcement, Meeting, Reservation, Access, BoardMember, Extra paths. |
| **`entity_actions`** | **EMPTY** (only commented `Person` example). Biggest gap. | Add per-label view/open links for Unit, Contact, Union, Meeting, Supplier, Project, Maintenance, Announcement, File, Invoice, Bill, Budget, Transaction, plus the unit child-add actions (vehicle/access). |
| **`generic_actions`** | **EMPTY** (commented examples only). | Add resident-safe nav (calendar, reservations, support, my_statement) + staff nav/creates (new_invoice, new_bill, new_announcement, new_meeting, add_unit, reports), each staff one `authorize`-gated. |
| **`discovery.role_mappings`** | **EMPTY** (commented PersonTeam example). | Optional polish: map `BoardMember` role + `ContactUnit.link_id` to scope names. Lowest priority. |
| **`chat.example_questions`** | Generic placeholders ("How many records do we have?"). | Replace with the resident top questions (default surface persona). |
| **`chat.page_examples`** | **NOT PRESENT** in host (host `chat` block predates the key). | Add the whole block keyed by graph label: Unit, Union, Meeting, Contact, Invoice, Supplier. |
| **entity descriptions** (`ai-entities/*.php`) | **RICH & COMPLETE** (133 entities, all with descriptions/aliases/scopes). **Non-gap.** | None — do not touch. |
| **`chat.surface` / surface placement** | Host `chat` block is the OLD shape (`theme`/`primary_color`, no `surface`). Floating chat mounted only on `DashboardView` + `OnboardingView`, **without** `page_context`. | Add `chat.surface => 'drawer'`; mount `AiChatFloating` with `page_context` on UnitView, UnionView, MeetingPage, SupplierPage, ProjectPage, MaintenancePage, BudgetView. |
| **`identity`** | **NOT PRESENT** in host (package default `"{APP_NAME} Assistant"`). | Add `identity.name => 'CondoEdge Assistant'` (+ persona/non_disclosure) for branding. |
| **AI-ingestion plugins / `file_model`** | `file_model => App\Models\File`, `access_scope => accessibleBy`, `physical_paths => public/laws/**/*.md` — all valid. **Non-gap** for files. | See §AI-readiness for which *models* still need Nodeable/sync. |
| **`security.on_permission_unexistent`** | `bypass` (fail-OPEN). Package default is `deny`. | Flag for review — for multi-tenant condo data prefer `deny` (call-out only; do not silently flip without owner sign-off). |

---

## The exact config to add

All blocks below go into **`C:/Users/jkend/Documents/Projects/kompo/CondoEdge/config/ai.php`**.

### Verified grammar (so every closure resolves)

- **Entity link:** AI emits `[text](entity://<GraphLabel>/<id>/<action_key>)`. Closure signature is the
  legacy positional `fn($id, $text = 'Default')` → returns a Kompo element. (`ConfigAiAction::invokeLegacy`
  passes `[$entityId, $text, $input]`, slicing to the closure's declared arity — so a 2-arg closure gets
  `$id, $text`.) `<GraphLabel>` MUST equal the catalog `label` (e.g. `Unit`, not the FQCN).
- **Generic link:** AI emits `[text](action://<action_key>)`. Closure signature `fn($text = 'Default')`.
- Each entry: `['action' => fn(...), 'aliases' => [...], 'label' => '...']`; optional `'authorize' => fn($user, $input): bool`,
  `'requires_approval' => true`, `'input_schema' => [...]`.
- **Kompo idiom (house style verified in host views):** full-page Forms use `->href('route.name', ['id' => $id])`;
  Kompo **Modals** (ContactForm, FileInfoForm, VehicleForm, AccessForm, AnnouncementForm) use
  `->get('route.name', [...])->inModal()`. Use `route()` or route-name-first — both resolve; the host house
  style is route-name-first (`->href('unit.view', ['id' => $id])`).

> **CAVEATS that MUST be encoded (verified in host source — a naive wiring 404s/403s):**
> 1. **`report.unit-statement`** param is `customer_id` and the real value is a **polymorphic token**
>    `'unit|||'.$unitId` (verified `app/View/Condo/UnitFinanceHistoryList.php:46` and
>    `InvoicesReceivablesReport.php:263`; resolved by `findOrFailMorphModel`). A raw unit id is WRONG.
> 2. **`vehicles.form`** / **`accesses.form`** are `{unit_id}/{id?}` — the FIRST param is `unit_id`
>    (`web.php:528-529`). An `entity://Unit/{id}/add_vehicle` closure receives the **unit** id as `$id`,
>    so it maps cleanly to `['unit_id' => $id]` (NEW vehicle, no vehicle id). Do **not** register these
>    under `entity://Vehicle/{id}/edit` — that closure only gets the vehicle id and cannot supply `unit_id`.
> 3. Staff-gated routes (`invoices.form`, `bills.form`, `budget.view`, `transactions.form`, `union.manager`,
>    `announcements.form`, `vehicles.form`, `accesses.form`, `file-info.form`, `contacts.form`, reports) must
>    carry an `'authorize'` closure so resident chats never emit a 403 link.

#### A resident/staff gate helper (put near the top of the returned array, or inline the closures)

```php
// Reusable authorize gates. CondoEdge roles come from App\Models\Traits\HasRoles.
// Staff = team/permanent/lead/owner/superadmin context. Residents (mere contacts) are excluded.
$staffOnly  = fn ($user, array $input = []) => $user && method_exists($user, 'isContact') ? !$user->isMereContact() : (bool) optional($user)->isTeamMember();
// NOTE: confirm the exact predicate against App\Models\User during implementation
// (e.g. $user->hasPermanentTeamRole() / Gate::allows(...)); the route middleware tiers in
// routes/web.php are the source of truth for which routes are staff-only.
```

> Implementation note: the precise role predicate (`isMereContact`, `getRole`, `hasPermanentTeamRole`)
> should be confirmed against `app/Models/User.php` + `HasRoles` at write time. The *intent* is fixed:
> resident-safe actions have NO `authorize`; staff actions gate on "not a mere contact".

### 1. `entity_actions` (replace the empty array)

```php
'entity_actions' => [

    // ---------- RESIDENT-SAFE ----------
    'Unit' => [
        'view' => [
            // web.php:124  units/{id}/view -> App\View\Condo\UnitView (full page)
            'action' => fn ($id, $text = 'Open unit') => _Link($text)->href('unit.view', ['id' => $id]),
            'aliases' => ['open unit', 'view unit', 'unit page', 'go to unit', 'open lot'],
            'label' => 'Open unit',
        ],
        'statement' => [
            // web.php:399 report/unit-statement/{customer_id?}.  customer_id is a POLYMORPHIC token
            // 'unit|||<id>' (verified UnitFinanceHistoryList.php:46). Build it here — raw id is wrong.
            'action' => fn ($id, $text = 'View account statement') =>
                _Link($text)->href('report.unit-statement', ['customer_id' => 'unit|||' . $id])->inNewTab(),
            'aliases' => ['account statement', 'my statement', 'unit statement', 'balance statement', 'releve de compte'],
            'label' => 'Account statement',
        ],
        'add_vehicle' => [
            // web.php:528 vehicles/{unit_id}/{id?} (VehicleForm is a Modal). $id here = UNIT id.
            'action' => fn ($id, $text = 'Register a vehicle') =>
                _Link($text)->get('vehicles.form', ['unit_id' => $id])->inModal(),
            'aliases' => ['add vehicle', 'register vehicle', 'register my car', 'new vehicle'],
            'label' => 'Register a vehicle',
            'authorize' => $staffOnly, // vehicles.form is under Role::team() (web.php:532) — staff/board only
        ],
        'add_access' => [
            // web.php:529 accesses/{unit_id}/{id?} (AccessForm is a Modal). $id = UNIT id.
            'action' => fn ($id, $text = 'Add access device') =>
                _Link($text)->get('accesses.form', ['unit_id' => $id])->inModal(),
            'aliases' => ['add access', 'add key', 'add fob', 'new access device'],
            'label' => 'Add access device',
            'authorize' => $staffOnly, // accesses.form under Role::team()
        ],
    ],

    'Union' => [
        'view' => [
            // web.php:128 union/view -> UnionView (no param; current-union context)
            'action' => fn ($id, $text = 'Open my building') => _Link($text)->href('union.view'),
            'aliases' => ['open building', 'my building', 'my condo', 'open union', 'syndicate page'],
            'label' => 'Open building',
        ],
        'manage' => [
            // web.php:277 union/manage/{id?} -> UnionManager (Role::permanent)
            'action' => fn ($id, $text = 'Manage union') => _Link($text)->href('union.manager', ['id' => $id]),
            'aliases' => ['manage union', 'union settings', 'edit union', 'union administration'],
            'label' => 'Manage union',
            'authorize' => $staffOnly,
        ],
    ],

    'Meeting' => [
        'view' => [
            // web.php:152 meeting/{id}/{stage?} -> MeetingPage (full page)
            'action' => fn ($id, $text = 'Open meeting') => _Link($text)->href('meetings.page', ['id' => $id]),
            'aliases' => ['open meeting', 'view meeting', 'meeting page', 'agenda', 'minutes', 'AGM'],
            'label' => 'Open meeting',
        ],
    ],

    'Supplier' => [
        'view' => [
            // web.php:135 supplier/{id?} -> SupplierPage
            'action' => fn ($id, $text = 'Open supplier') => _Link($text)->href('suppliers.page', ['id' => $id]),
            'aliases' => ['open supplier', 'view supplier', 'supplier page', 'vendor'],
            'label' => 'Open supplier',
        ],
    ],
    'Project' => [
        'view' => [
            // web.php:137 project/{id} -> ProjectPage
            'action' => fn ($id, $text = 'Open project') => _Link($text)->href('projects.page', ['id' => $id]),
            'aliases' => ['open project', 'view project', 'project page', 'work project'],
            'label' => 'Open project',
        ],
    ],
    'Maintenance' => [
        'view' => [
            // web.php:142 maintenance/{id} -> MaintenancePage
            'action' => fn ($id, $text = 'Open maintenance') => _Link($text)->href('maintenances.page', ['id' => $id]),
            'aliases' => ['open maintenance', 'view maintenance', 'work order', 'maintenance task'],
            'label' => 'Open maintenance',
        ],
    ],

    // ---------- STAFF-GATED ----------
    'Contact' => [
        'view' => [
            // web.php:472 contact/{id?} -> ContactForm (Modal, under Role::team() group at 473)
            'action' => fn ($id, $text = 'Open contact') => _Link($text)->get('contacts.form', ['id' => $id])->inModal(),
            'aliases' => ['open contact', 'view contact', 'edit contact', 'contact profile', 'owner profile'],
            'label' => 'Open contact',
            'authorize' => $staffOnly,
        ],
    ],
    'Invoice' => [
        'edit' => [
            // web.php:281 receivables/edit/{id?} -> InvoiceForm (Role::permanent)
            'action' => fn ($id, $text = 'Open invoice') => _Link($text)->href('invoices.form', ['id' => $id]),
            'aliases' => ['open invoice', 'edit invoice', 'receivable', 'view invoice'],
            'label' => 'Open invoice',
            'authorize' => $staffOnly,
        ],
    ],
    'Bill' => [
        'edit' => [
            // web.php:285 payables/edit/{id?} -> BillForm (Role::permanent)
            'action' => fn ($id, $text = 'Open bill') => _Link($text)->href('bills.form', ['id' => $id]),
            'aliases' => ['open bill', 'edit bill', 'payable', 'supplier bill'],
            'label' => 'Open bill',
            'authorize' => $staffOnly,
        ],
    ],
    'Budget' => [
        'view' => [
            // web.php:292 budget-edit/{id} -> BudgetView (Role::permanent)
            'action' => fn ($id, $text = 'Open budget') => _Link($text)->href('budget.view', ['id' => $id]),
            'aliases' => ['open budget', 'view budget', 'budget page'],
            'label' => 'Open budget',
            'authorize' => $staffOnly,
        ],
    ],
    'Transaction' => [
        'edit' => [
            // web.php:300 transactions/edit/{id?} -> TransactionForm (Role::permanent)
            'action' => fn ($id, $text = 'Open transaction') => _Link($text)->href('transactions.form', ['id' => $id]),
            'aliases' => ['open transaction', 'view transaction', 'ledger entry'],
            'label' => 'Open transaction',
            'authorize' => $staffOnly,
        ],
    ],
    'Announcement' => [
        'edit' => [
            // web.php:525 announcements/{id?} -> AnnouncementForm (Modal, Role::team)
            'action' => fn ($id, $text = 'Open announcement') => _Link($text)->get('announcements.form', ['id' => $id])->inModal(),
            'aliases' => ['open announcement', 'edit announcement', 'notice'],
            'label' => 'Open announcement',
            'authorize' => $staffOnly,
        ],
    ],
    'File' => [
        'info' => [
            // web.php:510 file-info/{id} -> FileInfoForm (Modal, Role::team)
            'action' => fn ($id, $text = 'File details') => _Link($text)->get('file-info.form', ['id' => $id])->inModal(),
            'aliases' => ['file info', 'file details', 'about this file', 'document details'],
            'label' => 'File details',
            'authorize' => $staffOnly,
        ],
    ],
],
```

> Note on the resident "my documents" case: residents reach files via retrieval + the bylaws corpus
> (`physical_paths`), not via `file-info.form` (which is team-gated). Keep `File.info` staff-gated; for
> residents the answer is the file content/snippet itself, which the package already returns.

### 2. `generic_actions` (replace the empty array)

```php
'generic_actions' => [

    // ---------- RESIDENT-SAFE (has-plan group, no role middleware) ----------
    'calendar' => [
        // web.php:118
        'action' => fn ($text = 'Open calendar') => _Link($text)->href('calendar'),
        'aliases' => ['calendar', 'open calendar', 'events calendar', 'agenda'],
        'label' => 'Calendar',
    ],
    'make_reservation' => [
        // web.php:130 make-reservation -> ReservationForm
        'action' => fn ($text = 'Make a reservation') => _Link($text)->href('reservations.form'),
        'aliases' => ['make reservation', 'book amenity', 'reserve', 'book the pool', 'book party room', 'reservation'],
        'label' => 'Make a reservation',
    ],
    'support' => [
        // web.php:541 support-form -> SupportForm
        'action' => fn ($text = 'Contact support') => _Link($text)->href('support.form'),
        'aliases' => ['support', 'contact support', 'help request', 'report a problem', 'maintenance request', 'submit a request'],
        'label' => 'Contact support',
    ],
    'my_statement' => [
        // resident-safe entry to the account statement landing (no id -> SidebarCommon.php:228 uses bare route)
        'action' => fn ($text = 'My account statement') => _Link($text)->href('report.unit-statement')->inNewTab(),
        'aliases' => ['my statement', 'account statement', 'my balance', 'releve', 'how much do I owe'],
        'label' => 'My account statement',
    ],
    'meetings' => [
        // web.php — meetings query/list (confirm exact name 'meetings' at write time; MeetingsQuery route)
        'action' => fn ($text = 'View meetings') => _Link($text)->href('meetings'),
        'aliases' => ['meetings', 'all meetings', 'meeting list', 'AGMs'],
        'label' => 'Meetings',
    ],
    'docs' => [
        // web.php:113
        'action' => fn ($text = 'Documentation') => _Link($text)->href('docs'),
        'aliases' => ['docs', 'documentation', 'how-to', 'guide'],
        'label' => 'Documentation',
    ],

    // ---------- STAFF-GATED (each authorize-gated) ----------
    'new_invoice' => [
        // web.php:281 invoices.form with no id = create
        'action' => fn ($text = 'New invoice') => _Link($text)->href('invoices.form'),
        'aliases' => ['new invoice', 'create invoice', 'add receivable', 'bill a unit'],
        'label' => 'New invoice',
        'authorize' => $staffOnly,
    ],
    'new_bill' => [
        // web.php:285 bills.form no id = create
        'action' => fn ($text = 'New bill') => _Link($text)->href('bills.form'),
        'aliases' => ['new bill', 'create bill', 'add payable', 'enter a bill'],
        'label' => 'New bill',
        'authorize' => $staffOnly,
    ],
    'new_announcement' => [
        // web.php:525 announcements.form no id = create (Modal)
        'action' => fn ($text = 'New announcement') => _Link($text)->get('announcements.form')->inModal(),
        'aliases' => ['new announcement', 'create announcement', 'send a notice', 'post announcement'],
        'label' => 'New announcement',
        'authorize' => $staffOnly,
    ],
    'union_stats' => [
        // web.php:165 union/stats -> UnionStats (Role::team)
        'action' => fn ($text = 'Union stats') => _Link($text)->href('union.stats'),
        'aliases' => ['union stats', 'building dashboard', 'union dashboard', 'statistics'],
        'label' => 'Union stats',
        'authorize' => $staffOnly,
    ],
    'contacts' => [
        // web.php:472/ contacts.catalog (confirm name; under Role::team)
        'action' => fn ($text = 'Contacts') => _Link($text)->href('contacts.catalog'),
        'aliases' => ['contacts', 'owners list', 'all contacts', 'directory'],
        'label' => 'Contacts',
        'authorize' => $staffOnly,
    ],
    'units' => [
        // web.php:122 units.catalog (has-plan group, but list is staff-oriented — gate to be safe)
        'action' => fn ($text = 'Units') => _Link($text)->href('units.catalog'),
        'aliases' => ['units', 'all units', 'units list', 'lots'],
        'label' => 'Units',
        'authorize' => $staffOnly,
    ],
],
```

> **Verify-at-write-time route NAMES** (these were referenced by the Understand outputs but not all
> pinned to a `web.php` line in this pass): `meetings`, `contacts.catalog`, `units.catalog`,
> `report.invoices-receivables`, `report.owners-registry`, `report.balance-sheet`, `report.profit-loss`,
> `chart-of-accounts`, `tasks-manager`, `files-manager`, `inbox`, `dashboard`, `budget`,
> `transactions` (list). Add report/staff generic actions for these once each name is confirmed present —
> all are reported to exist but only the ones cited with a `web.php:NNN` above are verified in THIS pass.

### 3. `user_entity_paths` (additive + the two bug-fixes)

Keep `Unit`, `Contact`, `Vehicle` (all correct). **Fix** `Invoice` and `Pet`. **Add** the rest.
All anchor on the verified spine `(:Contact {user_id: $userId})<-[:CONTACT]-(:ContactUnit)-[:UNIT]->(:Unit)`
(business rule, `config/ai.php:33`).

```php
'user_entity_paths' => [

    // --- UNCHANGED (correct) ---
    'Unit' => [ /* keep existing */ ],
    'Contact' => [ /* keep existing */ ],
    'Vehicle' => [ /* keep existing — (u:Unit)<-[:UNIT]-(n:Vehicle) is correct */ ],

    // --- FIX 1: Invoice is POLYMORPHIC (no INVOICED_TO_UNIT rel). Match customer_type/customer_id. ---
    'Invoice' => [
        'description' => "User's invoices. Invoice has a polymorphic CUSTOMER (customer_type/customer_id). "
            . "Match invoices whose customer is one of the user's units (customer_type='unit') OR the user's "
            . "own contact (customer_type='contact').",
        'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) '
            . 'WHERE cu.sold_at IS NULL '
            . 'MATCH (n:Invoice) WHERE (n.customer_type = "unit" AND n.customer_id = u.id) '
            . 'OR (n.customer_type = "contact" AND n.customer_id = c.id)',
        'entities' => ['User', 'Contact', 'ContactUnit', 'Unit', 'Invoice'],
        'relationships' => ['CONTACT', 'UNIT', 'CUSTOMER'],
    ],

    // --- FIX 2: Pet→Unit is rel type UNIT (inverse HAS_PETS), NOT BELONGS_TO_UNIT ---
    'Pet' => [
        'description' => "User's pets via their units. Pet has outgoing UNIT to Unit (inverse HAS_PETS).",
        'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit)<-[:UNIT]-(n:Pet) '
            . 'WHERE cu.sold_at IS NULL',
        'entities' => ['User', 'Contact', 'ContactUnit', 'Unit', 'Pet'],
        'relationships' => ['CONTACT', 'UNIT'],
    ],

    // --- NEW ---
    'Access' => [
        'description' => "User's access devices (keys/fobs) via their units. Access has outgoing UNIT to Unit (inverse HAS_ACCESSES).",
        'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit)<-[:UNIT]-(n:Access) '
            . 'WHERE cu.sold_at IS NULL',
        'entities' => ['User', 'Contact', 'ContactUnit', 'Unit', 'Access'],
        'relationships' => ['CONTACT', 'UNIT'],
    ],
    'Extra' => [
        'description' => "User's parking/storage extras via their units. Extra has outgoing UNIT to Unit. type_id 1=parking, 2=storage.",
        'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit)<-[:UNIT]-(n:Extra) '
            . 'WHERE cu.sold_at IS NULL',
        'entities' => ['User', 'Contact', 'ContactUnit', 'Unit', 'Extra'],
        'relationships' => ['CONTACT', 'UNIT'],
    ],
    'Announcement' => [
        'description' => "Announcements for the user's units/building. Announcement BELONGS_TO_UNIT and/or BELONGS_TO_UNION.",
        'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit)-[:BELONGS_TO_UNION]->(un:Union) '
            . 'WHERE cu.sold_at IS NULL '
            . 'MATCH (n:Announcement) WHERE (n)-[:BELONGS_TO_UNION]->(un) OR (n)-[:BELONGS_TO_UNIT]->(u)',
        'entities' => ['User', 'Contact', 'ContactUnit', 'Unit', 'Union', 'Announcement'],
        'relationships' => ['CONTACT', 'UNIT', 'BELONGS_TO_UNION', 'BELONGS_TO_UNIT'],
    ],
    'Meeting' => [
        'description' => "Meetings (AGM/board) for the user's building. Meeting BELONGS_TO_UNION the user's union.",
        'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit)-[:BELONGS_TO_UNION]->(un:Union) '
            . 'WHERE cu.sold_at IS NULL '
            . 'MATCH (n:Meeting)-[:BELONGS_TO_UNION]->(un)',
        'entities' => ['User', 'Contact', 'ContactUnit', 'Unit', 'Union', 'Meeting'],
        'relationships' => ['CONTACT', 'UNIT', 'BELONGS_TO_UNION'],
    ],
    'Reservation' => [
        'description' => "User's amenity reservations. Reservation has USER (user_id) and/or CONTACT.",
        'cypher' => 'MATCH (n:Reservation) WHERE n.user_id = $userId',
        'entities' => ['User', 'Reservation'],
        'relationships' => ['USER'],
    ],
    'BoardMember' => [
        'description' => "Whether the user is on a board and their role. BoardMember links to the user's Contact.",
        'cypher' => 'MATCH (c:Contact {user_id: $userId}) MATCH (n:BoardMember) '
            . 'WHERE (n)-[:CONTACT]->(c) OR (n)-[:BELONGS_TO_CONTACT]->(c)',
        'entities' => ['User', 'Contact', 'BoardMember'],
        'relationships' => ['CONTACT', 'BELONGS_TO_CONTACT'],
    ],
],
```

> **Confirm-at-write-time** the exact rel direction/type for `Access`, `Extra`, `Announcement`,
> `BoardMember` against `config/ai-entities/condo.php` + `contact.php` (the catalogs are the source of
> truth — the Vehicle/Pet bugs prove the existing config drifted from them). The Pet/Vehicle/Invoice
> fixes above are already reconciled against the catalogs.

### 4. `chat.example_questions` (replace the placeholders — resident default persona)

```php
'example_questions' => [
    'How much do I owe?',
    'When is the next meeting?',
    "Where are my building's bylaws?",
    'Book an amenity reservation',
    'Who is on the condo board this year?',
    'Show the announcements for my building',
],
```

### 5. `chat.page_examples` (ADD this key to the `chat` block — keyed by graph label)

```php
'page_examples' => [
    'Unit' => [
        "What's this unit's balance?",
        'Who are the owners of this unit?',
        "List this unit's parking, storage and vehicles",
        "Show this unit's account statement",
    ],
    'Union' => [
        'When is the next meeting?',
        'Show recent announcements',
        'Who is on the board this year?',
        'What amenities can I reserve?',
    ],
    'Meeting' => [
        'Summarize the agenda and motions',
        'What were the vote results?',
        'Who is attending?',
    ],
    'Contact' => [
        'What units does this contact own?',
        'Is this contact a board member?',
        "Show this contact's balance",
    ],
    'Invoice' => [
        "Explain this invoice's line items",
        'Is this invoice paid?',
        'What is the due date and amount?',
    ],
    'Supplier' => [
        "Show this supplier's open projects",
        "What's this supplier's contact info?",
    ],
],
```

### 6. `chat.surface` + `identity`

```php
// inside the 'chat' block:
'surface' => env('AI_CHAT_SURFACE', 'drawer'), // slide-over keeps the user in-context

// top-level (add a new 'identity' block — package interpolates {name}/{project}):
'identity' => [
    'name' => env('AI_ASSISTANT_NAME', 'CondoEdge Assistant'),
    'persona' => env('AI_ASSISTANT_PERSONA',
        'You are the CondoEdge Assistant, a helpful assistant for condominium residents, '
        . 'board members and property managers. You answer questions about units, fees, meetings, '
        . 'documents, amenities and building life, and you offer one-click links to the right page.'),
    // keep the package default non_disclosure / language_policy unless overriding.
],
```

### 7. Surface placement — which pages get the chat with what `page_context`

Mount `Condoedge\Ai\Kompo\AiChatFloating` with a `page_context` prop on each record page. The package
forwards `page_context` to the opened surface (`AiChatFloating::openChat()` reads `$this->prop('page_context')`),
and `page_examples[entity_type]` then drives the welcome suggestions. `entity_type` MUST be the graph label.

| Host view class (under `CondoEdge/app/View/...`) | Mount with |
|---|---|
| `Condo/UnitView.php` | `new AiChatFloating(null, ['page_context' => ['route' => 'unit.view', 'entity_type' => 'Unit', 'entity_id' => $this->model->id]])` |
| `Condo/UnionView.php` | `['page_context' => ['entity_type' => 'Union']]` (no id; single-union context) — **best resident default** |
| `Meetings/MeetingPage.php` | `['page_context' => ['entity_type' => 'Meeting', 'entity_id' => $this->model->id]]` |
| `Supplier/SupplierPage.php` | `['page_context' => ['entity_type' => 'Supplier', 'entity_id' => $id]]` |
| `Supplier/ProjectPage.php` | `['page_context' => ['entity_type' => 'Project', 'entity_id' => $id]]` |
| `Supplier/MaintenancePage.php` | `['page_context' => ['entity_type' => 'Maintenance', 'entity_id' => $id]]` |
| `Finance/BudgetView.php` | `['page_context' => ['entity_type' => 'Budget', 'entity_id' => $id]]` |

> The floating button is currently mounted only on `Dashboard/DashboardView.php` (line ~36) and
> `OnboardingView.php`, both WITHOUT `page_context`. Adding it to `UnionView` (residents' landing) and the
> record pages above is the highest-value surface change. Confirm the exact `AiChatFloating` constructor/props
> arity at write time (Kompo Form components take `($store = null, $params = [])`).

---

## AI-readiness

**Files:** already wired and valid — no change needed.
- `file_model => App\Models\File` (exists), `access_scope => 'accessibleBy'` (the File model's ACL scope),
  `fallback_filters` (user + team) ON, `physical_paths => ['public/laws/**/*.md']` (bylaws/legal corpus
  ingested via `php artisan ai:ingest --docs`). Residents get file *content* via retrieval + the bylaws corpus.

**Models/retrieval:** `model_namespaces => ['App\Models']` and ~137 models implement `Nodeable`/use
`HasNodeableConfig`, so the domain is broadly ingestible. To make every action target above actually
*retrievable* (so the AI can FIND the row before it can link to it), verify each of these labels is
Nodeable AND synced to Neo4j/Qdrant:

- **Must be Nodeable + synced** for the new paths/actions to resolve: `Unit`, `Union`, `Contact`,
  `ContactUnit`, `Invoice`, `Bill`, `Meeting`, `Announcement`, `Resource`, `Reservation`, `Extra`,
  `Vehicle`, `Pet`, `Access`, `BoardMember`, `Supplier`, `Project`, `Maintenance`, `Budget`, `Transaction`, `File`.
- **Verify sync coverage:** run `php artisan ai:ingest` (batch) and confirm node counts per label in Neo4j.
  Any label the AI is asked about but that has 0 nodes = retrieval returns nothing and no action link is offered.
- **FileProcessingPlugin / file_model:** the single `file_model` is `App\Models\File`; if any other model
  carries user-facing documents (e.g. meeting minutes stored elsewhere), they must morph into `File` or be
  registered — confirm `File::accessibleBy` covers minutes/insurance/bylaws scopes for the 3 personas.
- **Polymorphic Invoice:** ensure the synced `Invoice` nodes carry `customer_type`/`customer_id` properties
  (the fixed `user_entity_paths['Invoice']` depends on them); if the sync emits a `CUSTOMER` rel instead,
  switch the cypher to traverse that rel.

---

## Iteration checklist (verify after configuring)

1. **Config loads:** `php artisan config:clear && php artisan tinker --execute="dd(array_keys(config('ai.entity_actions')), array_keys(config('ai.generic_actions')))"` — no fatal, all expected keys present.
2. **Routes resolve:** for every action closure, `route('<name>', <args>)` resolves without `RouteNotFoundException`. Spot-check `unit.view`, `report.unit-statement` (with `customer_id => 'unit|||1'`), `meetings.page`, `vehicles.form` (with `unit_id`).
3. **Statement token:** confirm `report.unit-statement` opens the right unit's statement when given `'unit|||<id>'` (NOT a raw id).
4. **Resident never sees 403 links:** log in as a mere contact; ask questions that would surface staff actions (e.g. "open invoice 5") — confirm the `authorize` gate suppresses `invoices.form`/`contacts.form`/etc. links.
5. **`entity://` label match:** AI emits `entity://Unit/<id>/view` (label, not FQCN) and the link renders. Check `ActionLinkHandler` resolves it (registry `getEntity('Unit','view')` non-null).
6. **`my X` paths:** ask "show my fees" (Invoice — verify the polymorphic match returns the user's unit invoices), "my pets" (Pet — verify the `UNIT` rel fix returns rows), "my keys" (Access), "my reservations", "am I on the board" (BoardMember).
7. **Page examples:** open the chat from `UnitView` → welcome shows the `Unit` page_examples; from `UnionView` → `Union` examples; default elsewhere → global `example_questions`.
8. **Surface:** floating button opens a **drawer** (per `chat.surface`), and `page_context` is forwarded (inspect the opened `AiChatDrawer` prop).
9. **Identity:** assistant introduces itself as "CondoEdge Assistant" and never discloses the underlying model/vendor.
10. **Retrieval before action:** for each action label, confirm the AI can first FIND the entity (Neo4j has nodes for that label) — otherwise the action never gets a chance to render.
11. **Security review (flag, do not silently flip):** decide on `security.on_permission_unexistent` (`bypass` today = fail-open). For multi-tenant condo data, prefer `deny` after confirming permission records exist for the AI permission chain (`{Entity}.AiRetrieving`).
12. **Regression:** the 5 original `user_entity_paths` and the untouched entity catalogs still behave; no existing config removed.

---

### Source-of-truth files (all absolute)

- Host config to enrich: `C:/Users/jkend/Documents/Projects/kompo/CondoEdge/config/ai.php`
- Host routes (route names + param arity + role tiers): `C:/Users/jkend/Documents/Projects/kompo/CondoEdge/routes/web.php`
- Host entity catalogs (graph labels + relationships): `C:/Users/jkend/Documents/Projects/kompo/CondoEdge/config/ai-entities/*.php`
- `report.unit-statement` encoding proof: `C:/Users/jkend/Documents/Projects/kompo/CondoEdge/app/View/Condo/UnitFinanceHistoryList.php:46`, `.../app/View/Finance/Reports/InvoicesReceivablesReport.php:263`
- Package grammar: `C:/Users/jkend/Documents/Projects/kompo/ai/src/Services/Response/ActionLinkHandler.php`, `C:/Users/jkend/Documents/Projects/kompo/ai/src/Services/Actions/ConfigAiAction.php`
- Package surface/page_context: `C:/Users/jkend/Documents/Projects/kompo/ai/src/Kompo/AiChatFloating.php`
- Package config schema (entity/generic actions, user_entity_paths, surface, page_examples): `C:/Users/jkend/Documents/Projects/kompo/ai/config/ai.php`
