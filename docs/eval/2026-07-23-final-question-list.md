# Final Recommended Question List — 2026-07-23

Synthesis of: Survey A (pages + page_context wiring), Survey B (data reality), Survey C (eval
evidence, iter-9/iter-10 + gold suite). Pinned persona: **Julie Rousseau, LEAD, team 3 Novo
Gestion** (523 users incl. residents, multi-union portfolio).

**Data reality (Survey B, verified 2026-07-23):** zero receivables and zero bills due anywhere;
latest meeting 2023-12-14 (none upcoming); THREE distinct units named "310"; **130 open / 10
urgent team-3 tasks — the standout live data source**; boards resolve per-union at max-year;
Julie owns no unit personally.

**SHIP RULE (absolute):** no question ships to a rendered config surface until its probe in
`tests/eval/recommended-probes.json` measures green (deterministic gates pass + standard graded
≥ 0.7). Questions already proven by gold-suite evidence (2 held runs) are ship-ready now; every
other question is marked `PENDING-PROBE` and stays commented out in the config arrays below.

---

## 1. GLOBAL welcome questions (`ai.chat.example_questions`) — exactly 4

Replaces the current resident-persona list, of which 4 of 6 entries are known-fail shapes for
this dataset ("How much do I owe right now?" — Julie owns no unit + empty-result composer trap;
"When is the next meeting?" — none exist + board/meeting pipeline only hotfixed today; "Who is
on the condo board this year?" — board t0 F 0.05, hotfix unverified; "Book an amenity" —
unproven action shape).

### Q1 — Team roster
- **EN:** `Who are the members of my team and what are their roles?`
- **FR:** `Qui sont les membres de mon équipe et quels sont leurs rôles?`
- **Value:** highest cross-role utility (lead, staff, board): the people directory is the first
  thing every persona needs; exercises the suite's flagship recovered shape.
- **Contract:** sample table (names + roles) + EXACT stated total AND shown-count/remainder
  ("10 of the 523 total members"), insider voice ("your team"), one concrete deepen-door
  (export / emails / last login). Gates: `min_rows`, `discloses_total`.
- **Status:** **PROVEN** — team-roster t0 P 0.85, resilience t0 P 0.85, held 2 runs. FR variant
  also proven (french t0 P 0.85). SHIP NOW.

### Q2 — Supplier payables (honest zero)
- **EN:** `How much do we currently owe our suppliers?`
- **FR:** `Combien devons-nous actuellement à nos fournisseurs?`
- **Value:** the money question a manager actually asks daily; on this dataset it demonstrates
  the system's most valuable trust behavior — an affirmative, verifiable zero instead of
  fabrication.
- **Contract:** due-amount semantics (not invoiced totals); affirmative grounded zero naming
  what was checked; insider "we owe"; per-supplier breakdown offered as the deepen-door.
  Gates: none deterministic (zero-row answer) — standard-graded.
- **Status:** **PROVEN** — supplier P 0.85, held 3+ runs, the suite's most stable pass. FR
  variant PENDING-PROBE (only roster/export FR flows are proven). SHIP EN NOW.

### Q3 — Open & urgent tasks (the live-data standout)
- **EN:** `Which of our tasks are open right now, and which ones are urgent?`
- **FR:** `Quelles tâches sont ouvertes en ce moment, et lesquelles sont urgentes?`
- **Value:** the ONLY question in the set whose true answer is rich live data (130 open / 10
  urgent for team 3) rather than an honest zero or a static roster — this is where the AI shows
  daily operational value. Cross-role: leads triage, staff execute, board oversees.
- **Contract (inherits the proven roster + honest-total shape):** sample table of open tasks +
  EXACT stated total (130 as of probe date) + urgent breakdown (10) + shown-count/remainder
  disclosure; scoped strictly to team 3; deepen-doors (by assignee / by union / export).
  Gates: `min_rows: 1`, `discloses_total: true`.
- **Status:** **PENDING-PROBE** — zero eval coverage exists for the Task entity (a genuine gap:
  the standout live source has never been measured). Nearest proven shape is roster+total, so
  green is plausible, but the ship rule holds: commented out until its probe measures green.

### Q4 — App how-to (add members)
- **EN:** `Where can I add new members to my team?`
- **FR:** `Où puis-je ajouter de nouveaux membres à mon équipe?`
- **Value:** onboarding/self-serve shape every new user hits; the suite's single highest score;
  shows the assistant knows the app itself, not just the data.
- **Contract:** documentation-grounded steps with citation, ending in the CORRECT action link
  (`team_settings`) — never hedged generic UI guesses. Gate: `cites_document: true`.
- **Status:** **PROVEN** — app-nav P 0.95, held and rising. FR variant PENDING-PROBE. SHIP EN NOW.

**Strong alternate (bench):** `What does the law say about who pays for repairs to common
portions?` — law-overview shape proven (rights t0 P 0.75, held). Kept on the bench because the
brief for the global four is live-data-first; swap in for Q3 if the tasks probe fails.

---

## 2. PER-PAGE `page_examples` — keyed by the REAL `entity_type` strings

**Reality check first (Survey A — UPDATED 2026-07-23, suggestion-wiring pass):** the
welcome-suggestion render block is now **LIVE**: `MessagesQuery::exampleQuestionsBlock()`
(shared by `welcomeState()` AND `emptyState()`) calls `resolveExampleQuestions()`, so
`page_examples` render both on a fresh empty conversation and on first-open/no-conversation.
Clicks reuse the typed-send chain (SuggestionSubmit fills the composer + clicks send —
optimistic bubble first, then the answer). The UI gate is gone; shipping a question is now
gated ONLY on its probe going green in the host config (which stays commented until then).

The 8 wired keys (each has a live `AiChatFloating` mount emitting exactly this string, and
clicked suggestions DO carry page_context, so "this X" binds to the mounted record):

### `Union` (Condo/UnionView, `union.view`)
1. `Who is on this union's board of directors this year?` — **PROVISIONAL**: board shape was
   F 0.05 and hotfixed TODAY; do not ship before iter-11 confirms (floor 0.35, target ≥ 0.50).
   Data note: boards = per-union max-year, so "this year" resolves per union.
2. `When was this union's last meeting, and what was decided?` — phrased "last" not "next"
   (latest meeting anywhere is 2023-12-14; "next meeting" is a known-fail/dead-data shape).
   PENDING-PROBE.
3. `What open tasks concern this union right now?` — live tasks angle, page-scoped. PENDING-PROBE.

### `Unit` (Condo/UnitView, `unit.view`)
Page binding makes these homonym-safe: entity_id pins ONE unit, sidestepping the bare-"310"
prose collapse that fails globally (balance t0 F 0.35).
1. `What's this unit's outstanding balance?` — true answer is a verifiable zero; inherits the
   empty-result composer-template risk until G3 lands. PENDING-PROBE (expect flaky pre-G3).
2. `Who are the current owners of this unit, and how do I reach them?` — PENDING-PROBE.
3. `What open tasks concern this unit?` — live tasks angle. PENDING-PROBE.

### `Meeting` (Meetings/MeetingPage, `meetings.page`)
1. `Summarize this meeting's agenda and motions` — PENDING-PROBE.
2. `What were the vote results for each motion?` — PENDING-PROBE.
3. `How many voters were present and what voting power was represented?` — PENDING-PROBE.
   (The former quorum/majority LAW question is dropped: precise-threshold law questions are a
   known-fail family — ¾-vote misstated as "majority" — until G5.)

### `Invoice` (Finance/InvoiceForm, `invoices.form`, guarded)
1. `Is this invoice paid? What's the total, balance and due date?` — PENDING-PROBE.
2. `Break down this invoice's line items` — PENDING-PROBE.

### `Supplier` (Supplier/SupplierPage, `suppliers.page`, guarded)
1. `How much do we currently owe this supplier?` — page-scoped variant of the suite's most
   stable proven shape (supplier P 0.85); the closest-to-proven page question. PENDING-PROBE
   (scoped variant unmeasured).
2. `What maintenance work is this supplier assigned to?` — relationship verified in config
   comments (the old "open projects" question was removed as unfilterable). PENDING-PROBE.
3. `What's this supplier's contact info?` — external contact is legitimate here (the
   no-self-contact rule concerns the user's OWN team). PENDING-PROBE.

### `Project` (Supplier/ProjectPage, `projects.page`)
Project has NO status property — never phrase "open/active projects".
1. `Summarize this project: what is it and which union is it for?` — PENDING-PROBE.
2. `List the quote requests for this project and the suppliers involved` — PENDING-PROBE.

### `Maintenance` (Supplier/MaintenancePage, `maintenances.page`)
1. `What does this maintenance cover and when does it run?` — PENDING-PROBE.
2. `Which supplier handles this maintenance, and how do I reach them?` — PENDING-PROBE.

### `Budget` (Finance/BudgetView, `budget.view`)
1. `Is this budget approved, and what are its line items?` — PENDING-PROBE.
2. `How many contribution invoices were generated from this budget, and for what total?` —
   relationships verified (BudgetDetail-BUDGET->Budget; Invoice BUDGET rel). PENDING-PROBE.

### Wiring-gap pages (NO mount today — questions listed for when mounts land, not for config now)
- **`Contact`** — key exists in config but is permanently dead: only surface is a Modal (FAB
  escapes the modal box). Needs a non-modal Contact surface before its list can ever fire.
- **Bare mounts** (`dashboard`, `onboarding`): no entity_type → they fall back to the global
  `example_questions`, which is correct behavior — the global four above serve them.
- **High-value unmounted list pages** (from Survey A): TasksManager (`tasks-manager` — the
  live-data standout has NO chat mount), MeetingsQuery, InvoicesTable, BillsTable,
  ContactsCatalog, UnitsCatalog, UnionsCatalog, SuppliersTable, BudgetsTable, FilesMainView,
  TransactionsTable, AdminDashboardView. Mounting TasksManager is the single biggest wiring win
  given the dataset.
- `page_descriptions` does not exist in CondoEdge config — "Explain this page" is OFF app-wide.

---

## 3. CONFIG-READY PHP ARRAYS

**Apply AFTER the digest workflow releases `config/ai.php` — that file is currently held by the
in-flight digest work; do not touch it until that workflow lands and releases it.** Target file:
`CondoEdge/config/ai.php` (`ai.chat.*`). Entries commented with `// PENDING-PROBE` stay
commented until their probe in `recommended-probes.json` measures green, per the ship rule.

```php
// ai.chat.example_questions — pinned-persona-validated 2026-07-23 (docs/eval/2026-07-23-final-question-list.md)
// Replaces the resident-default list: 4 of its 6 entries are known-fail shapes on this dataset
// (no receivables, no upcoming meetings, board pipeline hotfix unverified, Julie owns no unit).
'example_questions' => [
    'Who are the members of my team and what are their roles?',   // PROVEN team-roster t0 0.85 x2 (FR variant also proven)
    'How much do we currently owe our suppliers?',                // PROVEN supplier 0.85 x3+ (honest verifiable zero)
    // 'Which of our tasks are open right now, and which ones are urgent?', // PENDING-PROBE probe:global-tasks-open — the live-data standout (130 open / 10 urgent); uncomment when green
    'Where can I add new members to my team?',                    // PROVEN app-nav 0.95 (team_settings link)
    // Bench alternate if global-tasks-open fails:
    // 'What does the law say about who pays for repairs to common portions?', // PROVEN rights t0 0.75 (law overview shape)
],
```

```php
// ai.chat.page_examples — keyed by the EXACT entity_type strings the 8 live AiChatFloating
// mounts emit (Survey A, verified 2026-07-23). NB: this list renders NOWHERE until the welcome
// block at kompo/ai src/Kompo/MessagesQuery.php:242-255 is re-enabled — re-enabling is itself
// gated on the page probes measuring green. ALL entries below are PENDING-PROBE (no page-scoped
// eval evidence exists yet; see validation plan) — the array is staged, not shipped.
'page_examples' => [
    'Union' => [
        // 'Who is on this union\'s board of directors this year?',        // PENDING-PROBE probe:union-board — PROVISIONAL: board hotfix unverified (iter-11 floor 0.35)
        // 'When was this union\'s last meeting, and what was decided?',   // PENDING-PROBE probe:union-last-meeting ("last" not "next": no upcoming meetings exist)
        // 'What open tasks concern this union right now?',                // PENDING-PROBE probe:union-open-tasks
    ],
    'Unit' => [
        // 'What\'s this unit\'s outstanding balance?',                    // PENDING-PROBE probe:unit-balance — honest zero; composer-template risk until G3
        // 'Who are the current owners of this unit, and how do I reach them?', // PENDING-PROBE probe:unit-owners
        // 'What open tasks concern this unit?',                           // PENDING-PROBE probe:unit-open-tasks
    ],
    'Meeting' => [
        // 'Summarize this meeting\'s agenda and motions',                 // PENDING-PROBE probe:meeting-summary
        // 'What were the vote results for each motion?',                  // PENDING-PROBE probe:meeting-votes
        // 'How many voters were present and what voting power was represented?', // PENDING-PROBE probe:meeting-quorum-data
    ],
    'Invoice' => [
        // 'Is this invoice paid? What\'s the total, balance and due date?', // PENDING-PROBE probe:invoice-status
        // 'Break down this invoice\'s line items',                        // PENDING-PROBE probe:invoice-lines
    ],
    'Supplier' => [
        // 'How much do we currently owe this supplier?',                  // PENDING-PROBE probe:supplier-owed-scoped (page-scoped variant of the PROVEN payables shape)
        // 'What maintenance work is this supplier assigned to?',          // PENDING-PROBE probe:supplier-maintenance
        // 'What\'s this supplier\'s contact info?',                       // PENDING-PROBE probe:supplier-contact
    ],
    'Project' => [ // NO status property — never phrase 'open/active projects'
        // 'Summarize this project: what is it and which union is it for?', // PENDING-PROBE probe:project-summary
        // 'List the quote requests for this project and the suppliers involved', // PENDING-PROBE probe:project-quotes
    ],
    'Maintenance' => [
        // 'What does this maintenance cover and when does it run?',       // PENDING-PROBE probe:maintenance-summary
        // 'Which supplier handles this maintenance, and how do I reach them?', // PENDING-PROBE probe:maintenance-supplier
    ],
    'Budget' => [
        // 'Is this budget approved, and what are its line items?',        // PENDING-PROBE probe:budget-status
        // 'How many contribution invoices were generated from this budget, and for what total?', // PENDING-PROBE probe:budget-invoices
    ],
    // 'Contact' => [...] — key is permanently dead (only surface is a Modal; FAB escapes it).
    // Do not populate until a non-modal Contact surface exists.
],
```

---

## 4. VALIDATION PLAN — `tests/eval/recommended-probes.json`

**RULE (stated for the record): no question ships to config until its probe measures green.**
Green = every deterministic `expect` gate passes AND the out-of-process standard grade is ≥ 0.7.

**Runner:** `cd ../CondoEdge && php artisan ai:eval --suite=tests/eval/recommended-probes.json
--user=13 --fresh --throttle=20000` (same pinned user 13 / Julie / team 3 and TPM pacing as the
gold suite).

**Schema — flat single-turn, loader-compatible:** each probe is a conversation with exactly ONE
turn (`EvalSuiteLoader` accepts `ask`/`question`, `expect`, `standard`; single-turn keeps every
probe independent — no context carry-over to confound the measurement). Two extension fields:
`page_context` (per-turn) and `status` (bookkeeping, ignored by the runner).

**BLOCKER for page-scoped probes:** `PipelineEvalDriver` already accepts
`options['page_context']` (src/Services/Eval/PipelineEvalDriver.php:78,116-117), but
`EvalSuiteLoader` / `ConversationEvalRunner` do NOT read a `page_context` field from suite JSON
— it is silently dropped. Until that forward lands (a ~2-line loader/runner change, NOT made in
this read-only pass), page probes execute context-less and measure the wrong thing. Run the 8
global probes now; hold the 20 page probes on the loader fix.

```jsonc
{
  "meta": {
    "pinned_user": { "id": 13, "team_id": 3, "role": "lead" },
    "purpose": "Probe every recommended question (docs/eval/2026-07-23-final-question-list.md) before it ships to config. status: proven-by-gold = evidence already exists (probe is a regression sentinel); needs-probe-run = never measured, must be green before uncommenting in config.",
    "dataset_reality": "zero receivables/bills-due; latest meeting 2023-12-14; three units named '310'; 130 open / 10 urgent team-3 tasks (counts drift — re-verify via read-only tinker before grading exact numbers); boards per-union max-year; Julie owns no unit.",
    "blocker": "page_context field requires EvalSuiteLoader/ConversationEvalRunner forwarding to PipelineEvalDriver options['page_context'] — page probes are on hold until then."
  },
  "conversations": [

    { "id": "global-roster", "status": "proven-by-gold(team-roster t0 0.85 x2)", "turns": [
      { "ask": "Who are the members of my team and what are their roles?",
        "expect": { "min_rows": 1, "discloses_total": true },
        "standard": "Sample table names+roles scoped to team 3 only; EXACT total (523) AND shown-count/remainder stated; insider voice; one concrete deepen-door (export/emails/last login). Partial list presented as complete = fail." } ] },

    { "id": "global-supplier-owed", "status": "proven-by-gold(supplier 0.85 x3+)", "turns": [
      { "ask": "How much do we currently owe our suppliers?",
        "expect": {},
        "standard": "Due-amount semantics (not invoiced totals); affirmative grounded ZERO naming what was checked (bills, due amounts); insider 'we owe'; offers per-supplier breakdown. Any owed amount > 0 is fabrication = fail." } ] },

    { "id": "global-tasks-open", "status": "needs-probe-run (GATES CONFIG ENTRY Q3)", "turns": [
      { "ask": "Which of our tasks are open right now, and which ones are urgent?",
        "expect": { "min_rows": 1, "discloses_total": true },
        "standard": "Open tasks for team 3 only; EXACT open total (130 at spec time — re-verify count read-only before grading) AND urgent count (10) stated; sample rows + remainder disclosure; deepen-doors (by assignee/union, export). Inherits the roster+honest-total contract." } ] },

    { "id": "global-app-nav", "status": "proven-by-gold(app-nav 0.95)", "turns": [
      { "ask": "Where can I add new members to my team?",
        "expect": { "cites_document": true },
        "standard": "Documentation-grounded steps with citation, ending in the CORRECT team_settings action link; no hedged generic UI guesses." } ] },

    { "id": "global-roster-fr", "status": "proven-by-gold(french t0 0.85)", "turns": [
      { "ask": "Qui sont les membres de mon équipe et quels sont leurs rôles?",
        "expect": { "min_rows": 1, "discloses_total": true },
        "standard": "Same contract as global-roster, ENTIRE answer in French (accent-free composer copy acceptable)." } ] },

    { "id": "global-supplier-owed-fr", "status": "needs-probe-run", "turns": [
      { "ask": "Combien devons-nous actuellement à nos fournisseurs?",
        "expect": {},
        "standard": "Same contract as global-supplier-owed, entirely in French." } ] },

    { "id": "global-tasks-open-fr", "status": "needs-probe-run", "turns": [
      { "ask": "Quelles tâches sont ouvertes en ce moment, et lesquelles sont urgentes?",
        "expect": { "min_rows": 1, "discloses_total": true },
        "standard": "Same contract as global-tasks-open, entirely in French." } ] },

    { "id": "global-app-nav-fr", "status": "needs-probe-run", "turns": [
      { "ask": "Où puis-je ajouter de nouveaux membres à mon équipe?",
        "expect": { "cites_document": true },
        "standard": "Same contract as global-app-nav, entirely in French." } ] },

    { "id": "bench-law-repairs", "status": "proven-by-gold(rights t0 0.75)", "turns": [
      { "ask": "What does the law say about who pays for repairs to common portions?",
        "expect": { "cites_document": true },
        "standard": "Concise structured CCQ-grounded overview with real citations; each point a pullable thread; no exhaustive dump, no 'consult your syndicate' self-referral, no invented article numbers." } ] },

    // ---- PAGE-SCOPED PROBES (ALL needs-probe-run; ON HOLD until loader forwards page_context) ----
    // page_context values use REAL ids resolved read-only at probe-authoring time via tinker
    // (e.g. a team-3 union id for Union, one of the three '310' unit ids for Unit, the
    // 2023-12-14 meeting id for Meeting, a supplier with bills for Supplier, etc.).

    { "id": "union-board", "status": "needs-probe-run; PROVISIONAL (board hotfix 2026-07-23 unverified — require iter-11 board t0 >= 0.50 first)",
      "turns": [ { "ask": "Who is on this union's board of directors this year?",
        "page_context": { "entity_type": "Union", "entity_id": "<team3-union-id>", "route": "union.view" },
        "expect": { "min_rows": 1 },
        "standard": "Board of THIS union at its per-union max-year; names + roles; no cross-union bleed; no bare apology." } ] },

    { "id": "union-last-meeting", "status": "needs-probe-run",
      "turns": [ { "ask": "When was this union's last meeting, and what was decided?",
        "page_context": { "entity_type": "Union", "entity_id": "<team3-union-id>", "route": "union.view" },
        "expect": { "min_rows": 1 },
        "standard": "Latest meeting of THIS union with date (dataset max 2023-12-14) + decisions/motions summary; honest 'none recorded' acceptable for a union with no meetings; never invents an upcoming meeting." } ] },

    { "id": "union-open-tasks", "status": "needs-probe-run",
      "turns": [ { "ask": "What open tasks concern this union right now?",
        "page_context": { "entity_type": "Union", "entity_id": "<team3-union-id>", "route": "union.view" },
        "expect": { "discloses_total": true },
        "standard": "Open tasks scoped to THIS union; exact count + sample + remainder; honest zero acceptable if this union truly has none (state what was checked)." } ] },

    { "id": "unit-balance", "status": "needs-probe-run; composer-trap risk until G3 (true answer is a verifiable zero)",
      "turns": [ { "ask": "What's this unit's outstanding balance?",
        "page_context": { "entity_type": "Unit", "entity_id": "<one-310-unit-id>", "route": "unit.view" },
        "expect": { "min_rows": 1, "disambiguation_column_present": true },
        "standard": "Due-amount semantics for EXACTLY the mounted unit (page binding — no homonym collapse possible); honest verifiable zero (unit identified, 0 open invoices); manager voice." } ] },

    { "id": "unit-owners", "status": "needs-probe-run",
      "turns": [ { "ask": "Who are the current owners of this unit, and how do I reach them?",
        "page_context": { "entity_type": "Unit", "entity_id": "<one-310-unit-id>", "route": "unit.view" },
        "expect": { "min_rows": 1 },
        "standard": "Current owners of the mounted unit with attainable contact fields (email; NO phone property exists — must be neither promised nor blamed)." } ] },

    { "id": "unit-open-tasks", "status": "needs-probe-run",
      "turns": [ { "ask": "What open tasks concern this unit?",
        "page_context": { "entity_type": "Unit", "entity_id": "<one-310-unit-id>", "route": "unit.view" },
        "expect": {},
        "standard": "Open tasks linked to the mounted unit; honest zero with what-was-checked if none." } ] },

    { "id": "meeting-summary", "status": "needs-probe-run",
      "turns": [ { "ask": "Summarize this meeting's agenda and motions",
        "page_context": { "entity_type": "Meeting", "entity_id": "<2023-12-14-meeting-id>", "route": "meetings.page" },
        "expect": { "min_rows": 1 },
        "standard": "Agenda + motions of the mounted meeting only; concise, expandable (per-motion doors)." } ] },

    { "id": "meeting-votes", "status": "needs-probe-run",
      "turns": [ { "ask": "What were the vote results for each motion?",
        "page_context": { "entity_type": "Meeting", "entity_id": "<2023-12-14-meeting-id>", "route": "meetings.page" },
        "expect": { "min_rows": 1 },
        "standard": "Per-motion vote results, grounded; honest 'not recorded' where absent — never invented tallies." } ] },

    { "id": "meeting-quorum-data", "status": "needs-probe-run",
      "turns": [ { "ask": "How many voters were present and what voting power was represented?",
        "page_context": { "entity_type": "Meeting", "entity_id": "<2023-12-14-meeting-id>", "route": "meetings.page" },
        "expect": { "min_rows": 1 },
        "standard": "Presence count + voting power from the mounted meeting's data (DATA question — no law-threshold claims, the precise-threshold law family is a known fail until G5)." } ] },

    { "id": "invoice-status", "status": "needs-probe-run",
      "turns": [ { "ask": "Is this invoice paid? What's the total, balance and due date?",
        "page_context": { "entity_type": "Invoice", "entity_id": "<real-invoice-id>", "route": "invoices.form" },
        "expect": { "min_rows": 1 },
        "standard": "Status/total/balance/due-date of the mounted invoice; balance uses due-amount semantics (zero-due dataset: paid or no-balance answers are the truthful outcome)." } ] },

    { "id": "invoice-lines", "status": "needs-probe-run",
      "turns": [ { "ask": "Break down this invoice's line items",
        "page_context": { "entity_type": "Invoice", "entity_id": "<real-invoice-id>", "route": "invoices.form" },
        "expect": { "min_rows": 1 },
        "standard": "Line items of the mounted invoice with amounts; sums consistent with the invoice total." } ] },

    { "id": "supplier-owed-scoped", "status": "needs-probe-run (page-scoped variant of PROVEN payables shape)",
      "turns": [ { "ask": "How much do we currently owe this supplier?",
        "page_context": { "entity_type": "Supplier", "entity_id": "<real-supplier-id>", "route": "suppliers.page" },
        "expect": {},
        "standard": "Due-amount semantics scoped to the mounted supplier; affirmative grounded zero naming what was checked; insider 'we owe'." } ] },

    { "id": "supplier-maintenance", "status": "needs-probe-run",
      "turns": [ { "ask": "What maintenance work is this supplier assigned to?",
        "page_context": { "entity_type": "Supplier", "entity_id": "<real-supplier-id>", "route": "suppliers.page" },
        "expect": {},
        "standard": "Maintenances assigned to the mounted supplier (verified relationship); honest none if empty." } ] },

    { "id": "supplier-contact", "status": "needs-probe-run",
      "turns": [ { "ask": "What's this supplier's contact info?",
        "page_context": { "entity_type": "Supplier", "entity_id": "<real-supplier-id>", "route": "suppliers.page" },
        "expect": { "min_rows": 1 },
        "standard": "Contact fields of the mounted supplier (external party — outsider contact phrasing is CORRECT here, unlike own-team questions)." } ] },

    { "id": "project-summary", "status": "needs-probe-run",
      "turns": [ { "ask": "Summarize this project: what is it and which union is it for?",
        "page_context": { "entity_type": "Project", "entity_id": "<real-project-id>", "route": "projects.page" },
        "expect": { "min_rows": 1 },
        "standard": "Mounted project's description + owning union; NO status claims (Project has no status property)." } ] },

    { "id": "project-quotes", "status": "needs-probe-run",
      "turns": [ { "ask": "List the quote requests for this project and the suppliers involved",
        "page_context": { "entity_type": "Project", "entity_id": "<real-project-id>", "route": "projects.page" },
        "expect": {},
        "standard": "Quote requests of the mounted project with their suppliers (Project->QuoteRequest->WorkOrder path); honest none if empty." } ] },

    { "id": "maintenance-summary", "status": "needs-probe-run",
      "turns": [ { "ask": "What does this maintenance cover and when does it run?",
        "page_context": { "entity_type": "Maintenance", "entity_id": "<real-maintenance-id>", "route": "maintenances.page" },
        "expect": { "min_rows": 1 },
        "standard": "Scope + schedule/recurrence of the mounted maintenance, grounded." } ] },

    { "id": "maintenance-supplier", "status": "needs-probe-run",
      "turns": [ { "ask": "Which supplier handles this maintenance, and how do I reach them?",
        "page_context": { "entity_type": "Maintenance", "entity_id": "<real-maintenance-id>", "route": "maintenances.page" },
        "expect": { "min_rows": 1 },
        "standard": "Assigned supplier + its contact info (external party — contact phrasing correct)." } ] },

    { "id": "budget-status", "status": "needs-probe-run",
      "turns": [ { "ask": "Is this budget approved, and what are its line items?",
        "page_context": { "entity_type": "Budget", "entity_id": "<real-budget-id>", "route": "budget.view" },
        "expect": { "min_rows": 1 },
        "standard": "Approval state + BudgetDetail line items of the mounted budget (verified rels)." } ] },

    { "id": "budget-invoices", "status": "needs-probe-run",
      "turns": [ { "ask": "How many contribution invoices were generated from this budget, and for what total?",
        "page_context": { "entity_type": "Budget", "entity_id": "<real-budget-id>", "route": "budget.view" },
        "expect": { "discloses_total": true },
        "standard": "Count + summed total of invoices linked via the Invoice BUDGET rel; honest zero acceptable with what-was-checked." } ] }
  ]
}
```

**Probe sequencing:** (1) run the 8 global + 1 bench probes now (page_context-free — runnable
today); (2) land the loader page_context forward; (3) resolve the `<placeholder>` ids via
read-only tinker; (4) run the 20 page probes; (5) uncomment green entries in config. (Step 6
of the original plan — re-enabling the welcome render block — is DONE as of 2026-07-23: the
block is live via `MessagesQuery::exampleQuestionsBlock()`, so config content is the only
remaining gate.) Proven-by-gold probes double as regression sentinels on every future run.

---

## 5. Belief changes / surprises from the full exploration

1. **The currently-shipped welcome list is majority-broken for the real pinned persona.** 4 of
   6 entries in `example_questions` today are known-fail or dead-data shapes for Julie ("How
   much do I owe" — she owns no unit + composer trap; "next meeting" — none exist; "condo board"
   — F 0.05 pipeline, hotfixed only today; amenity booking — unproven). The list was written for
   a resident persona the dataset's lead user doesn't match.
2. **The entire per-page suggestions feature WAS UI-dead** *(RESOLVED 2026-07-23)* — the
   welcome render block was commented out and `resolveExampleQuestions()` had no caller, so
   the carefully-keyed CondoEdge `page_examples` config had never rendered for anyone. Now
   fixed: `MessagesQuery::exampleQuestionsBlock()` renders it in both `welcomeState()` and
   `emptyState()`, and clicks go through the typed-send chain with page_context threaded
   (ChatMessageForm -> ChatPresenter::handleSend). The keying itself was always perfect
   (all 8 mount strings match config keys exactly); the remaining gate is probe-green config.
3. **Page-scoped questions can't currently be proven at all**: the eval loader/runner drop any
   `page_context` from suite JSON even though `PipelineEvalDriver` accepts it. The ship rule is
   therefore unsatisfiable for every page question until a small loader change lands — a gap
   nobody had surfaced before this synthesis.
4. **The standout live data source has zero coverage everywhere.** Tasks (130 open / 10 urgent
   for team 3) are the richest live answers the dataset can give, yet: no eval question ever
   probed Task, no `page_examples.Task` key exists, and TasksManager has no chat mount. The
   most valuable question in the final list is the one that has never been measured.
5. **Honest-zero is bimodal, not a solved behavior.** The payables zero is the suite's most
   stable pass (0.85 x3+), while structurally identical zeros (rights t2 law-bridge,
   owner-arrears) die on the "No results were found" composer template. Whether a correct zero
   survives depends on which code path renders it — so zero-answer questions were chosen only
   where the proven path carries them, until G3.
6. **Homonym danger inverts with page context.** Bare "unit 310" prose still fails (composer
   collapses three units), but a Unit-page mount pins entity_id and sidesteps the whole family —
   making unit questions SAFER as page suggestions than as global ones. Wiring, not phrasing, is
   the fix.
7. **Two silent context-droppers constrain rollout:** the `Contact` page_examples key is
   permanently unreachable (modal-only surface, FAB escapes), and flipping `ai.chat.surface` to
   `'screen'` would silently drop page_context app-wide (config comment) — the per-page feature
   only exists under modal/drawer.
