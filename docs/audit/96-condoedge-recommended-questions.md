# CondoEdge — Recommended Example Questions (verified set)

> Companion to `docs/audit/CondoEdge-10of10-config-plan.md`. Every question below was
> derived from that plan and then **re-verified** against host source — not taken on faith.
> Last full re-verification of every line citation: **2026-06-10** (the config and view
> files moved substantially; do not trust older snapshots of this document).

## 1. The hard rule, and how verdicts were derived

**Hard rule: a question is recommended ONLY if it returns real results** — its graph labels
have catalog entries + Nodeable models, its relationship types match the **materialized**
edges (catalog FK-declared outgoing type only; `inverse_type`/`direction` are never synced —
`ai/src/Services/RelationshipSyncer.php:50-127`), its properties are in the catalog
`graph.properties` list (only those sync — `GraphPropertyMapper.php:29-56`), and any action
link resolves to a registered `entity_actions`/`generic_actions` key whose route exists with
correct params and whose `authorize` tier matches the persona. Anything aspirational,
edge-name-drifted, or dependent on unregistered actions went to **Rejected** (§3).

Verdicts:

- **OK** — answerable today (config landed; data assumed ingested per §4).
- **AFTER-PLAN** — historical: `answerable_after_this_plan` rows whose page-suggestion
  surface needed the plan's §7 `AiChatFloating` mount and/or a new `page_examples` key.
  **As of 2026-06-10 no rows remain AFTER-PLAN**: the mounts landed (ProjectPage.php:165,
  MaintenancePage.php:130, BudgetView.php:100 — alongside the earlier MeetingPage.php:71,
  SupplierPage.php:76, UnitView.php:131, UnionView.php:138, InvoiceForm.php:188, and the
  context-less FABs at DashboardView.php:36 / OnboardingView.php:85) and the
  Project/Maintenance/Budget `page_examples` keys exist (ai.php:1067/1073/1078).
- Caveats are footnotes `[f#]` (defined after the tables). Three plan claims were
  **corrected** during verification and are reflected here (vehicles.form / contacts.form /
  announcements.form etc. are NOT role-middleware-gated — the config `authorize` closure is
  the sole gate; see verifier route report).

Citation conventions: `ai.php:NNN` = `CondoEdge/config/ai.php`; `web.php:NNN` =
`CondoEdge/routes/web.php`; `billing.php`/`calendar.php`/`condo.php`/`contact.php`/
`finance.php`/`general.php`/`meetings.php`/`supplier.php` =
`CondoEdge/config/ai-entities/*.php`; package paths are prefixed `ai/`.

**Possessive-path fixes — APPLIED** (the three dead `user_entity_paths` documented in
earlier revisions of this table are fixed in current config; each carries an inline `FIX`
comment at the cited location):

| Path (live, fixed) | Former bug | Fix applied |
|---|---|---|
| `user_entity_paths.Announcement` (ai.php:759-766) | hop was `[:BELONGS_TO_UNION]` — real edge is `[:UNION]` (condo.php:2046-2052) → 0 rows | hop is now `[:UNION]` (ai.php:761) |
| `user_entity_paths.Meeting` (ai.php:772-779) | same dead hop | hop is now `[:UNION]` (ai.php:774); property form `n.union_id = u.union_id` documented as equivalent |
| `user_entity_paths.Reservation` (ai.php:786-791) | `n.user_id` is never synced (not in graph.properties, condo.php:1216-1226) | traverses `(n)-[:USER]->(:User {id:$userId})` / `[:CONTACT]` (ai.php:788). Companion fix: the Reservation `for_auth_user` metadata scope (condo.php:1300) had the same dead `n.user_id = $userId` pattern — now `EXISTS { MATCH (n)-[:USER]->(:User {id: $userId}) }` |

### Entity-config Cypher sweep — fixes applied + remaining suspects (2026-06-10)

Every `cypher_pattern` and embedded traversal hint in `CondoEdge/config/ai-entities/*.php`
was re-derived against `graph.properties`, the FK-declared relationships (the only edges
`RelationshipSyncer.php:50-127` materializes), and — where noted — the live dev Neo4j.
**246 fragments checked across 16 files; 46 proven-dead patterns fixed in place; 7 suspects
flagged — the 3 strictly-safe ones were resolved in place later the same night (2026-06-10,
marked RESOLVED below), leaving 4 open** (unfixable by Cypher rewrite, or unprovable
without APOC/production data).

| File | Checked | Fixed | Suspects |
|---|---|---|---|
| billing.php | 6 | 3 | 1 |
| calendar.php | 8 | 2 | 2 |
| condo.php | 38 | 22 | 1 |
| contact.php | 15 | 1 | 0 |
| finance.php | 65 | 13 | 2 (both resolved 2026-06-10) |
| general.php | 29 | 2 | 0 |
| supplier.php | 36 | 3 | 1 (resolved 2026-06-10) |
| task.php, meetings.php, messaging.php, discussions.php, imports.php, eft.php, filemanager.php, phone.php, settings.php | 49 | 0 | 0 |

Fix classes (all verified in place at their cited lines):

- **FK-column property filters that never sync** — `n.user_id/union_id/unit_id/team_id = $param`
  where the column is not in `graph.properties` → rewritten to
  `EXISTS { MATCH (n)-[:EDGE]->(:Target {id: $param}) }` over the FK-declared edge. The bulk
  of condo.php's 22 (Access, Announcement, AnnouncementRead, Building, Entrance, Event,
  Extra, Instruction, Note, Pet, UnionContract, Unit→, Vehicle, Visit scopes).
- **Never-materialized edge names** — patterns/hints over edges no FK declaration creates:
  `HAS_READ` (Announcement.unread, condo.php:249 — was a `NOT EXISTS` anti-join over a
  never-existing edge, so it silently reported EVERY announcement unread; now a correct
  anti-join via `BELONGS_TO_ANNOUNCEMENT`/`BELONGS_TO_USER`), `RESERVES`
  (Resource.never_reserved, condo.php:1455 — same silent-100%-match class, now `[:RESOURCE]`),
  `HAS_RESERVATIONS` + phantom `start_time/end_time` props (Resource.booked_during,
  condo.php:1440), `(ba)-[:BELONGS_TO_USER]->` on BillApproval (finance.php:1111, real edge
  `USER`), `User→VisitDetail` direction hint (calendar.php:101, real edge
  `(VisitDetail)-[:BELONGS_TO_USER]->(User)`).
- **Type mismatches** — `accounts.code` syncs as a STRING but 7 Account scopes compared it
  to integer literals (`n.code = 2410` → `"2410"`), and `accounts.enabled` syncs as an
  integer but 4 scopes compared `= true` (→ `= 1`). All 11+1 fixed (finance.php:234-460).
- **Non-Cypher / pseudo-Cypher** — Java temporal `date().plusMonths(1)`
  (calendar.php:120, proven a SyntaxError by live EXPLAIN; now `date.truncate`-based),
  prose `n.year = MAX(year) per union` (contact.php:96, now a property anti-join matching
  the Eloquent joinSub semantics), unbalanced `( … ]` delimiters + vacuous `CONTAINS ''`
  in File/Supplier/SupplierContact search scopes (general.php:422,434;
  supplier.php:2179,2191→pattern now at its block under 2190, 2413) — rewritten to valid,
  `$search`-parameterized forms with companion `filter` entries.
- **Polymorphic guidance** — Price `PRICEABLE` hints (billing.php:381,432,484) steered the
  generator at a `_polymorphic`-target edge that never syncs; rewritten to the endorsed
  property-join form (`p.priceable_type = 'service' AND p.priceable_id = s.id` — only
  Service is priceable).

**Suspects** flagged by the sweep (do not build questions on the open ones until resolved;
the three marked RESOLVED were fixed in place 2026-06-10, `php -l` clean on both files):

| Where | Why it is a suspect, not a fix |
|---|---|
| `Price.for_team` (billing.php:437) | Requires `(Team)<-[:BELONGS_TO_TEAM]-(Price)`, but Price declares no such edge and its only edge (`PRICEABLE`) targets `_polymorphic`, which never syncs; Price nodes are edge-isolated and no property-join reaches Team. Needs a sync-layer change (resolve the morph) or scope removal. |
| VisitDetail `HAS_FILES` hints (calendar.php:46) | Declared FK `parent_id` lives on the File side of the morph, so the edge never syncs (0 live edges; File's `PARENT` edge targets `_polymorphic`). Property-join workaround unverifiable (no VisitDetail-parented files in the dev graph). Real fix is sync-layer polymorphic resolution. |
| VisitDetail team scoping (calendar.php:151, team_path at :80) | The MaintenanceDate→Maintenance→Team chain is correctly declared, but live data has 0 `BELONGS_TO_MAINTENANCE_DATE` edges — `maintenance_date_id` is null on all 42 dev VisitDetails. Needs production data to decide whether the column is ever populated. |
| `Unit.ordered_by_name` (condo.php:2391) | Calls `apoc.text.regexGroups` — APOC availability on the deployed Neo4j unverified (absence is a hard error, not 0 rows); and even with APOC the sort does not implement the stated "short numbers first" intent. |
| `Fund.is_usable` (finance.php:2249) — **RESOLVED 2026-06-10** | Was: `n.is_disabled = 0` silently excluded NULL — the column is a nullable tinyint with no default/backfill (migration 2025_05_22_000001), and the Eloquent scope treats NULL as usable (`Fund.php:86-91`, `where('is_disabled', 0)->orWhereNull`). Applied fix: pattern is now `(n.is_disabled = 0 OR n.is_disabled IS NULL)` (finance.php:2249). |
| `Bill.for_supplier` (finance.php:1015) — **RESOLVED 2026-06-10** | Was: a pattern-expression form (`(n)-[:SUPPLIER]->(s:Supplier) WHERE s.id = $supplierId`) that is invalid Cypher when injected verbatim into a WHERE clause (`DetectedScopesSection.php:205-206`); the edge itself is verified materialized. Applied fix: now the in-file convention form `EXISTS { MATCH (n)-[:SUPPLIER]->(:Supplier {id: $supplierId}) }` (finance.php:1015). |
| `Supplier.visibility_all` (supplier.php:2204) — **RESOLVED 2026-06-10** | Was: `cypher_pattern` was the empty string — the scope silently applied NO filter (over-matched across teams vs the Laravel scope's team-or-null semantics, `Supplier.php:273-278`). Applied fix: `specification_type` is now `parametric` with pattern `(n.team_id = $teamId OR n.team_id IS NULL)` and a `'team_id' => ''` filter entry (supplier.php:2204-2217), following the file's `for_team` convention (supplier.php:2244). |

---

## 2. Recommended questions by persona

### Resident

| Question | Context | Answer shape | Depends on | Verdict |
|---|---|---|---|---|
| How much do I owe right now? | global | currency total (sum `Invoice.calc_due_amount`) + statement link | L: Invoice, Contact, ContactUnit, Unit · R: CONTACT, UNIT + polymorphic `customer_type/customer_id` property match (no CUSTOMER edge — target `_polymorphic`) · P: `user_entity_paths.Invoice` (ai.php:715-723) | OK |
| Show my units and everyone registered to them | global | unit list + co-registered contacts (link_id roles) + open-unit link | L: Unit, Contact, ContactUnit · R: CONTACT, UNIT · P: paths.Unit (ai.php:699-704), `Unit.view` (ai.php:1220) | OK |
| What vehicles, pets and parking spots are registered to my unit? | global | grouped list (`Extra.type_id` 1=parking 2=storage) | L: Vehicle, Pet, Extra, Unit · R: UNIT (each child's declared FK edge) · P: paths.Vehicle/.Pet/.Extra (ai.php:724/732/748) | OK |
| When is the next meeting and where is it held? | global | single record (name, start_date, place) + open-meeting link | L: Meeting · R: BELONGS_TO_UNION (meetings.php:72-75) + team scoping · A: `Meeting.view` (ai.php:1272) | OK [f1] |
| What does the law say about who pays for repairs to common portions? | global | grounded prose + CCQ article citation | corpus `public/laws/CCQ_Co-ownership_EN.md` via `file_context.physical_paths` (ai.php:889-891); `documentation_chunks` populated | OK [f2] |
| Book an amenity (pool, party room...) | global | action link to reservation form (+ optional Resource list) | A: `generic_actions.make_reservation` (ai.php:1500) → `reservations.form` (web.php:130, has-plan, no role gate) | OK [f3] |
| Submit a support request | global | action link | A: `generic_actions.support` (ai.php:1506) → `support.form` (web.php:541, no role gate) | OK |
| Show my account statement | global | action link (new tab) | A: `generic_actions.my_statement` (ai.php:1512) / `Unit.statement` with `'unit\|\|\|'.$id` token (ai.php:1225-1233) → `report.unit-statement` (web.php:399) | OK |
| Who is on the condo board this year? | global | names + decoded `bm_role` | L: BoardMember, Contact · R: BELONGS_TO_CONTACT, BELONGS_TO_UNION · S: `current_board_members` scope (contact.php:93; its formerly prose pseudo-Cypher pattern was fixed in the 2026-06-10 sweep) | OK |
| When is the next meeting? | page:Union | single record + link | L: Meeting, Union · R: BELONGS_TO_UNION · mount UnionView.php:138 + `page_examples.Union` (ai.php:1032); the formerly broken possessive Unit→Union hop is also fixed (ai.php:774) | OK |
| Show recent announcements for this building | page:Union | list ordered by created_at | L: Announcement, Union · R: BELONGS_TO_UNION (condo.php:165+, decl at 183-188) | OK [f4] |
| What amenities can I reserve here, and what are the booking rules? | page:Union | resource list w/ rules + reservation link | L: Resource, Union · R: BELONGS_TO_UNION · props `min_res_time/max_res_time/max_people/auto_approve` (condo.php:1326+) · A: `make_reservation` | OK |
| Who is on the board this year? | page:Union | names + roles | L: BoardMember, Contact, Union · S: `for_union` + `current_board_members` scopes | OK |

### Board member

Board members hold Membership.role `'contact'` (board membership is a `board_members`
pivot attach, NOT a Jetstream role) — the `$staffOnly`/`$permanentOnly` gates check
`hasAnyRole` over the staff role sets (ai.php:23-47; rationale comment ai.php:5-22), so
every gated action link is suppressed for them. All board questions are deliberately
**data-only** (or use the few ungated actions like `Meeting.view`); see §3 for the
staff-link phrasings rejected for this persona.

| Question | Context | Answer shape | Depends on | Verdict |
|---|---|---|---|---|
| What is the total amount currently owed to the union? | global | currency total, optional overdue/not-yet-due split (`due_at`) | L: Invoice · team scoping (`Invoice.team_id`) · aggregate `calc_due_amount > 0` | OK [f5] |
| List overdue invoices — past due date with a balance outstanding | global | table (number, due date, outstanding) | L: Invoice · filter `due_at < now AND calc_due_amount > 0` (no staff link — correct for board) | OK |
| Which units are behind on their contributions? | global | unit names + outstanding totals | L: Invoice, Unit · polymorphic property join `i.customer_type='unit' AND i.customer_id=u.id` (documented in paths.Invoice + finance.php CUSTOMER comment) | OK [f6] |
| How much do we currently owe our suppliers? | global | total + per-supplier breakdown | L: Bill, Supplier · R: SUPPLIER (FK supplier_id, finance.php) · `Bill.calc_due_amount` (finance.php:862+) | OK |
| Summarize the motions and vote results of our most recent meeting | global | per-motion summary + tallies (`Vote.value`) + open-meeting link | L: Meeting, Motion, Vote, Voter · R: BELONGS_TO_MEETING (meetings.php:252-255), BELONGS_TO_VOTER, `Vote.motion_id` · A: `Meeting.view` (ai.php:1272, ungated) | OK |
| What does the Civil Code require of the board regarding the contingency (reserve) fund? | global | grounded prose + CCQ citation | CCQ corpus via `documentation_chunks` | OK [f2] |
| List the registry of owners by unit | global | table unit → owner name(s) | L: ContactUnit, Contact, Unit · R: CONTACT, UNIT · S: `owners` (link_id IN [1,2]) + `current` (contact.php:722) — data only; registry ACTION is staff-only | OK |
| How many units are in this union? | page:Union | single count | L: Unit, Union · R: **UNION** (Unit's declared FK edge, condo.php:2046-2052 — NOT BELONGS_TO_UNION) | OK [f7] |
| What quorum and majority does the law require for this type of decision? | page:Meeting | grounded prose + CCQ citation, vs `Meeting.pass_score` | CCQ corpus · `Meeting.type_id/pass_score` for context | OK [f2] |
| List this supplier's bills and their status | page:Supplier | table (bill_number, status, calc_due_amount, due_at) | L: Bill, Supplier · R: SUPPLIER — data only for board (Bill.edit is `$permanentOnly`) | OK |
| How much do we currently owe this supplier? | page:Supplier | currency total (sum calc_due_amount) | L: Bill, Supplier · R: SUPPLIER | OK |

### Staff

| Question | Context | Answer shape | Depends on | Verdict |
|---|---|---|---|---|
| Open unit 310 | global | single record + open-unit link | L: Unit · exact-name business rule (ai.php:116-117) · A: `Unit.view` (ai.php:1220) | OK |
| How many units do we manage, and how many are rented vs owner-occupied? | global | counts + link_id-bucket breakdown | L: Unit, ContactUnit · R: UNIT · S: `owners`/`renters` scopes (contact.php) | OK |
| Create a new invoice | global | action link | A: `generic_actions.new_invoice` (ai.php:1540, `$permanentOnly`) → `invoices.form` (web.php:281, Role::permanent) | OK |
| Post an announcement to the building | global | modal action link | A: `generic_actions.new_announcement` (ai.php:1554, `$staffOnly`) → `announcements.form` (web.php:525) | OK |
| Add a vehicle to unit 310 | global | modal link pre-bound to unit | A: `Unit.add_vehicle` (ai.php:1234-1241) → `vehicles.form` `{unit_id}/{id?}` (web.php:528) — unit_id-first trap correctly encoded | OK [f8] |
| Who owes money this month? Open the receivables report | global | aggregate + report link (new tab) | L: Invoice · A: `report_receivables` (ai.php:1606, `$permanentOnly`) → `report.invoices-receivables` (web.php:411, Role::permanent) | OK |
| Open the owners registry report (you can export it to Excel from there) | global | report link; export button lives on the page (OwnersRegistryReport.php:21) | A: `report_owners_registry` (ai.php:1613, `$staffOnly`) | OK [f9] |
| Approve pending reservation requests | global | pending list + policy-gated, approval-deferred approve/reject cards | L: Reservation, Resource · R: RESOURCE, USER · A: `approve_reservation`/`reject_reservation` (ai.php:1318-1339, ModelMethodMutationAction + ReservationPolicy::update) | OK [f10] |
| What notice does the law require before calling the annual general meeting? | global | grounded prose + CCQ citation | CCQ corpus via `documentation_chunks` | OK [f2] |
| Register a vehicle or add an access fob for this unit | page:Unit | modal links pre-bound to unit_id | A: `Unit.add_vehicle` (ungated) + `Unit.add_access` (`$staffOnly`, ai.php:1242-1251; AccessesUnitList.php:32 hides adds from contacts) | OK |
| What units does this contact own or rent? | page:Contact | unit list with decoded link_id role | L: Contact, ContactUnit, Unit · R: CONTACT, UNIT · `page_examples.Contact` exists (ai.php:1044) but NO mount passes `entity_type=Contact` | OK [f11] |
| Is this contact a board member, and in what role? | page:Contact | yes/no + bm_role + union + year | L: BoardMember, Contact · R: BELONGS_TO_CONTACT | OK [f11] |
| What's this contact's outstanding balance? | page:Contact | total, optional contact-billed vs unit-billed split | L: Invoice, Contact, Unit · polymorphic property join | OK [f6][f11] |
| Open this contact's profile | page:Contact | modal action link | A: `Contact.view` (ai.php:1393-1398, `$staffOnly`) — the authorize closure is the SOLE gate: `contacts.form` (web.php:472) is auth-only, NOT role-gated | OK [f11] |
| Is this invoice paid? What's the total, balance and due date? | page:Invoice | record detail (status, calc_total_amount, calc_due_amount, due_at, invoiced_at) | L: Invoice · mount InvoiceForm.php:188 (id-guarded) · `page_examples.Invoice` (ai.php:1050) · route is Role::permanent → staff audience by construction | OK |
| Break down this invoice's line items | page:Invoice | line-item table | L: InvoiceDetail, Invoice · R: **INVOICE** (InvoiceDetail's declared FK edge, finance.php:2733+ — not BELONGS_TO_INVOICE) | OK |
| Who is the customer on this invoice — open their unit or contact | page:Invoice | customer id + matching open link | polymorphic `customer_type/customer_id` resolution · A: `Unit.view` / `Contact.view` | OK [f6] |
| Create another invoice | page:Invoice | action link | A: `new_invoice` (`$permanentOnly`) | OK |
| How has this supplier been rated? | page:Supplier | ratings list / average | L: SupplierRating, Supplier · R: BELONGS_TO_SUPPLIER (supplier.php:2506) | OK |
| List the quote requests for this project and the suppliers involved | page:Project | table of quote requests + supplier names | L: QuoteRequest, Project, WorkOrder, Supplier · R: PROJECT (QuoteRequest's FK edge, supplier.php:1590-1594) + 2-hop via WorkOrder — **QUOTE_REQUEST** (supplier.php:2638-2642) + **BELONGS_TO_SUPPLIER** (supplier.php:2661-2665); `BELONGS_TO_QUOTE_REQUEST` is only an inverse name and never materializes · mount ProjectPage.php:165 + `page_examples.Project` (ai.php:1067) | OK [f6] |
| What files are attached to this project? | page:Project | file list + staff-gated `File.info` links | File `parent_type/parent_id` property match (HAS_FILES morph cannot materialize) · accessibleBy ACL (ai.php:943-948) · mount ProjectPage.php:165 | OK [f12] |
| Show the upcoming scheduled dates for this maintenance | page:Maintenance | date list | L: MaintenanceDate, Maintenance · R: **MAINTENANCE** (MaintenanceDate's declared FK edge, supplier.php:1000-1004 — NOT "BELONGS_TO_MAINTENANCE"; that name does not exist in the catalog) · mount MaintenancePage.php:130 + `page_examples.Maintenance` (ai.php:1073) | OK |
| Is this budget approved, and what are its line items? | page:Budget | status + detail-line table | L: Budget, BudgetDetail · `approved` scope (finance.php:1599) · R: BUDGET (BudgetDetail's FK edge, finance.php:1673-1677) · mount BudgetView.php:100 + `page_examples.Budget` (ai.php:1078) | OK [f13] |
| How many contribution invoices were generated from this budget, and for what total? | page:Budget | count + currency total | L: Invoice, Budget · R: BUDGET (Invoice's declared FK edge, finance.php:2490) · mount BudgetView.php:100 | OK |
| Open this budget (the contributions Excel export is on that page) | page:Budget | action link | A: `Budget.view` (ai.php:1424-1429, `$permanentOnly`) → `budget.view`; export in BudgetView.php:46 | OK [f9] |

### All personas (page-scoped)

| Question | Context | Answer shape | Depends on | Verdict |
|---|---|---|---|---|
| What's this unit's outstanding balance? | page:Unit | currency total for this unit | polymorphic property join · mount UnitView.php:131 · `page_examples.Unit` (ai.php:1026) · served by MessagesQuery.php:265-277 | OK |
| Who are the current owners of this unit? | page:Unit | names + role (owner-occupying or not) | L: ContactUnit, Contact, Unit · R: CONTACT, UNIT · `owners`+`current` scopes | OK |
| List this unit's parking, storage, vehicles and pets | page:Unit | grouped registry list | L: Extra, Vehicle, Pet, Unit · R: UNIT (each child) | OK |
| Open this unit's account statement | page:Unit | action link (new tab) | A: `Unit.statement` (ai.php:1225-1233, builds `'unit\|\|\|<id>'` token) | OK |
| Summarize this meeting's agenda and motions | page:Meeting | structured summary (schedule items + motions) | L: Meeting, Motion, Schedule · R: BELONGS_TO_MEETING · mount MeetingPage.php:71 · `page_examples.Meeting` (ai.php:1038) | OK |
| What were the vote results for each motion? | page:Meeting | per-motion tally of `Vote.value` | L: Motion, Vote · `Vote.motion_id` property + BELONGS_TO_VOTER (meetings.php:424+) | OK |
| How many voters were present and what voting power was represented? | page:Meeting | counts: present + sum voting_power/ratio vs `pass_score` | L: Voter, Meeting · R: BELONGS_TO_MEETING · props `present/voting_power/ratio` verified — replaces vague "Who is attending?" | OK |
| What's this supplier's contact info? | page:Supplier | record detail (email, telephone, website, address — supplier.php:1935+) | L: Supplier · mount SupplierPage.php:76 (id-guarded) · `page_examples.Supplier` (ai.php:1060) | OK |
| What maintenance work is this supplier assigned to? | page:Supplier | maintenance list (name, start_date, recurrence) + open links | L: Maintenance, Supplier · R: BELONGS_TO_SUPPLIER (supplier.php:642-646) · A: `Maintenance.view` (ai.php:1299, ungated) — REPLACES broken "open projects" example (§3) | OK |
| Summarize this project — what is it and which union is it for? | page:Project | record detail (name, description, union) | L: Project, Union · R: BELONGS_TO_UNION · mount ProjectPage.php:165 + `page_examples.Project` (ai.php:1067-1072) | OK [f14] |
| Open this project's page | page:Project | action link | A: `Project.view` (ai.php:1290, ungated; `projects.page` in has-plan group) | OK |
| What does this maintenance cover and when does it run? | page:Maintenance | record detail (name, description, start_date, recurrence_number/period — supplier.php:548+) | mount MaintenancePage.php:130 + `page_examples.Maintenance` (ai.php:1073-1077) | OK |
| Which supplier handles this maintenance, and how do I reach them? | page:Maintenance | supplier name + phone/email + open-supplier link | R: BELONGS_TO_SUPPLIER · A: `Supplier.view` (ungated, ai.php:1281-1285) | OK |

### Footnotes (caveats)

- **[f1]** FIXED: `user_entity_paths.Meeting` (ai.php:772-779) now traverses the real
  `[:UNION]` Unit→Union hop (ai.php:774; edge declared condo.php:2046-2052). Possessive
  "my building" phrasing is unblocked — add it to the `ai:eval` suite before promoting it
  into any suggestion list.
- **[f2]** Requires `documentation_chunks` populated from the CCQ corpus — see §4.
  `corpus_languages ['fr','en']` (ai.php:913) covers FR questions over the single EN file.
- **[f3]** Navigation only: no create-reservation mutation is registered (only
  approve/reject, ai.php:1318-1339). The AI links to the form; it does not book.
- **[f4]** FIXED: the same Unit→Union hop on `user_entity_paths.Announcement` now uses
  `[:UNION]` (ai.php:761). Possessive "my announcements" phrasing is unblocked (it remains
  OUT of the welcome list — see §3); eval before promoting.
- **[f5]** Board read access rides on `security.on_permission_unexistent => 'bypass'`
  (ai.php:305). If flipped to `deny`, board needs `{Entity}.AiRetrieving` permission records.
- **[f6]** Generation-fragile (polymorphic property join / multi-hop) — the join is
  documented in prompt-visible places but is the likeliest miss; add to the `ai:eval` suite.
- **[f7]** Catalog self-inconsistency: Unit declares `[:UNION]`; the Union entity describes
  an incoming `UNITS` rel with inverse `BELONGS_TO_UNION`. The real edge is `[:UNION]` — if
  the generator emits `BELONGS_TO_UNION` it returns 0. Eval + consider a business_rule
  documenting `(u:Unit)-[:UNION]->(un:Union)`.
- **[f8]** Correction to the plan: `vehicles.form` (web.php:528) carries NO role middleware
  (the Role::team group at web.php:532 only wraps events/visits forms) and the landed action
  has no `authorize` — it also works for residents on their own unit. Product decision pending.
- **[f9]** No direct export-to-Excel AiAction exists anywhere (only a commented example,
  ai/config/ai.php:1195). "Export" questions must be phrased as navigation to the page
  carrying the export button.
- **[f10]** The Reservation `USER` edge only materializes if User nodes exist at ingest
  time (RelationshipSyncer.php:60-76 skips missing targets) — ingest Users first or run
  `ai:sync-relationships` after (§4).
- **[f11]** page:Contact suggestions still cannot fire: `page_examples.Contact` exists
  (ai.php:1044) but NO surface mounts `AiChatFloating` with `entity_type=Contact` — and the
  gap is **not cleanly closable in the current host structure**. The only single-contact
  surface is `ContactForm` (`contacts.form`, web.php:472), which extends Modal
  (ContactForm.php:9) and is opened `->inModal()` from all four call sites (UnitSaleForm.php:70,
  ContactsQuery.php:116, NavbarManager.php:23, NavbarSearchResults.php:66) — no page
  navigation to it exists. `AiChatFloating` renders a viewport-fixed FAB
  (`fixed bottom-10 right-10 z-50`, ai/src/Kompo/AiChatFloating.php:31): mounted inside the
  modal it would escape the modal box and stack on the underlying page's own FAB (e.g.
  DashboardView.php:36 or UnitView.php:131) with conflicting `page_context`s. Closing it
  requires (a) a routed full-page Contact view, (b) a non-floating in-modal chat affordance
  in the package, or (c) package support for threading modal context into the page-level
  FAB. Until then the four Contact questions are valid as **typed** questions in any staff chat.
- **[f12]** Verify synced File nodes carry `parent_type`/`parent_id` properties after
  ingest before surfacing this question.
- **[f13]** RESOLVED: `BudgetDetail`'s declared FK edge is `(BudgetDetail)-[:BUDGET]->(Budget)`
  (fk budget_id, finance.php:1673-1677). The Budget-side `HAS_BUDGET_DETAILS` is only the
  inverse name — the generator must emit `[:BUDGET]` (same naming-drift class as Unit→Union
  [f7]); keep it in the eval suite.
- **[f14]** Project has NO `status` property (supplier.php:1407+) — never phrase as
  "open/active projects".

---

## 3. REJECTED questions (the skeptical evidence)

These look attractive and were all considered; each fails the hard rule. The three formerly
**shipped** config entries flagged here have since been removed from config (status noted
per row).

| Rejected question | Why it fails |
|---|---|
| Show this supplier's open projects — **REMOVED from config** (removal note at ai.php:1056-1059) | Doubly broken: Project has NO relationship to Supplier (supplier.php:1407+ rels are TASK/DISCUSSION/HAS_QUOTE_REQUESTS/HAS_FILES/BELONGS_TO_TEAM/BELONGS_TO_UNION/BELONGS_TO_USER; supplier only reachable via QuoteRequest→WorkOrder 2-hop) AND Project has no `status` property, so "open" is unfilterable. Produced empty/hallucinated answers. Replaced in `page_examples.Supplier` with the verified supplier-maintenances question. |
| Where are my building's bylaws / declaration of co-ownership? — **REMOVED from config** (no longer in `example_questions`, current list ai.php:1008-1015) | needs_ingestion: the physical corpus contains exactly ONE file — `public/laws/CCQ_Co-ownership_EN.md` (provincial Civil Code), no building-specific bylaws/declarations. Per-union documents would have to exist as DB Files under `accessibleBy`; no sync evidence. The law-grounded phrasing ("What does the law say about...") ships instead. |
| Show the announcements for my building (possessive) — **REMOVED from config** (not in ai.php:1008-1015) | The original failure — `user_entity_paths.Announcement`/`Meeting` traversing a dead `[:BELONGS_TO_UNION]` Unit→Union hop — has since been FIXED (`[:UNION]`, ai.php:761/774; condo.php:2046-2052). The phrasing is now answerable but stays out of the welcome list pending `ai:eval` coverage of the possessive paths ([f1]/[f4]). |
| Export all transactions / owners registry to Excel (one-click in chat) | No data-export AiAction exists in package or host (only conversation-to-markdown, ai/routes/web.php:8-9). Excel exports are in-page Kompo buttons (TransactionsTable.php:59, OwnersRegistryReport.php:21, BudgetView.php:46, TrialBalanceReport.php:83). The pipeline can only navigate to those pages. |
| Open the receivables report / open the budget (suggested to BOARD) | `$staffOnly`/`$permanentOnly` check `hasAnyRole` over the staff role sets (ai.php:23-47) and board members hold Membership.role `'contact'` (ai.php:12-13) — every gated link is suppressed for them. Suggesting these to board yields link-less, disappointing answers. Staff set only. |
| Create a meeting / Add a unit | No registered action: `generic_actions` has no `new_meeting`/`add_unit` key (full landed list verified, ai.php:1488-1678); `meetings.form` (web.php:489) is inside a Role::team group anyway. Aspirational until registered. |
| Book the pool for Saturday at 2pm (end-to-end in chat) | No create-reservation mutation registered — only approve/reject (ai.php:1318-1339). Suggest the navigation phrasing ("Book an amenity") instead. |
| Pay my fees / pay this invoice online | No payment action of any kind registered; no resident payment route verified. Pure aspiration. |
| What's the status of my support ticket? | Support requests become staff-side Tasks; no `user_entity_paths.Task` (ai.php:698-800 list verified — keys are Unit, Contact, Invoice, Vehicle, Pet, Access, Extra, Announcement, Meeting, Reservation, BoardMember) and Task actions are policy-gated staff mutations. A resident gets nothing retrievable that is theirs. |
| Open contact John Smith (suggested to RESIDENTS) | `Contact.view` is `$staffOnly` (PII gate, ai.php:1393-1398) — no link for residents; worse, Contact name-search may surface other residents' identifying fields (ai.php:267-269). Staff-only question. |
| What is our reserve fund balance? | Unverifiable mapping: no scope, business rule, or property encodes "reserve fund" semantics over Fund/AccountBalance. High hallucination risk. Point board to the CCQ contingency-fund law question, staff to `report_balance_sheet`. (NB: the `Fund.is_usable` suspect from the §1 sweep was resolved 2026-06-10; the "reserve fund" semantics gap itself remains.) |
| Which pets exceed the building's weight limit? | No verified weight property on Pet; no bylaws-vs-data join capability. Rule questions and registry lists work separately; the comparison is unsupported. |
| Show me this month's delinquency trend vs last month | Time-series comparison needs multi-query orchestration the single-Cypher pipeline doesn't do. Keep suggestions to single-aggregate shapes. |
| Who has NOT read the latest announcement? | Two of the three original blockers are fixed: the `Announcement.unread` anti-join is now correct (condo.php:249, traverses BELONGS_TO_ANNOUNCEMENT/BELONGS_TO_USER) and the Unit→Union edge naming is fixed. Remaining blocker: AnnouncementRead ingestion coverage is unverified — a 0-node AnnouncementRead label makes everyone "unread". Not suggestion-grade until §4 node counts confirm coverage. |
| Summarize the minutes of the last AGM | needs_ingestion: minutes are DB File attachments (Meeting HAS_FILES morph, meetings.php:50-53) with no evidence they are processed into `file_chunks` (FileProcessingPlugin coverage unverified; plan §AI-readiness flags exactly this). The structured motions/votes summary covers the need with graph data. |

---

## 4. needs_ingestion — labels that must have nodes before questions go live

Hard rule restated: **0 nodes for a label = silent empty answers** for every question that
depends on it. After ingesting, confirm per-label node counts in Neo4j (plan checklist #10).

### Commands (verified signatures)

```bash
# 1. Bulk-ingest all Nodeable entities into Neo4j + Qdrant
php artisan ai:ingest            # options: --model= --from= --fresh --chunk=2000 --dry-run
                                 # (ai/src/Console/Commands/IngestEntitiesCommand.php:36-42)

# 2. Physical doc corpus -> documentation_chunks (REQUIRED for all 4 law/CCQ questions)
php artisan ai:ingest --docs

# 3. Reconcile edges whose targets were missing at ingest time
#    (REQUIRED for Reservation-[:USER]->User if Users ingested after Reservations)
php artisan ai:sync-relationships   # (ai/src/Console/Commands/SyncRelationshipsCommand.php:40-44)

# 4. (Only if attachment-grounded questions are ever added) DB File contents -> file_chunks
php artisan ai:process-files
```

### Label → question-family map

| Label group | Labels | Questions blocked if 0 nodes |
|---|---|---|
| Resident spine | Contact, ContactUnit, Unit, Union, **User** | every "my ..." question; User additionally gates the Reservation USER edge [f10] |
| Unit registry | Vehicle, Pet, Extra, Access | vehicles/pets/parking lists, add-vehicle/fob context |
| Finance AR/AP | Invoice, InvoiceDetail, Bill, Supplier | all balance/overdue/supplier-owed questions, invoice page questions |
| Budget | Budget, BudgetDetail | both Budget page data questions |
| Meetings | Meeting, Motion, Vote, Voter, Schedule | next-meeting, motions/votes, voters-present questions |
| Community | Announcement, Resource, Reservation, BoardMember | announcements, amenities/rules, approve-reservations, board-roster |
| Supplier ops | Maintenance, MaintenanceDate, Project, QuoteRequest, WorkOrder, SupplierRating | supplier/maintenance/project page questions |
| Files | File (verify `parent_type`/`parent_id` props post-ingest [f12]) | "files attached to this project" |
| Doc corpus (not a label) | `documentation_chunks` from `public/laws/CCQ_Co-ownership_EN.md` (ai.php:889-891) | all 4 law-grounded (CCQ) questions |

Ordering caveat: edges are only created when target nodes already exist
(RelationshipSyncer.php:60-76) — ingest in full, then run `ai:sync-relationships` once to
close any gaps. All labels above were verified Nodeable + catalogued (no dead labels).

---

## 5. Maintenance — where these questions live and how to evolve them

**Where (both in `CondoEdge/config/ai.php`):**

- **Global welcome** → `chat.example_questions` (ai.php:1008-1015). Consumer:
  `UserChatSettings::exampleQuestions()` (ai/src/Services/Settings/UserChatSettings.php:97-100);
  priority chain is user-DB `AiUserSetting` → `session('ai_chat_settings')` → config → `[]`.
  Also feeds the eval harness (ai/src/Console/Commands/EvalCommand.php:265).
- **Page-scoped** → `chat.page_examples.{GraphLabel}` (ai.php:1025-1085). SOLE consumer:
  `MessagesQuery::resolveExampleQuestions()` (ai/src/Kompo/MessagesQuery.php:265-277) — raw
  config lookup, **exact case-sensitive graph label** key, falls back to the global list.
  Entity catalogs (`config/ai-entities/*.php`) have NO per-entity question mechanism — never
  put questions there.

**Rendering gotchas (affect QA and phrasing):**

- Welcome suggestions render only on an **empty conversation** (MessagesQuery.php:93-99);
  a user with a prior conversation must click "+" to see them. Test that way.
- **Clicked suggestions do NOT carry page_context** (MessagesQuery.php:352-357 passes 4 args
  to `handleSend`; pageContext defaults null at ChatPresenter.php:66) — only typed messages
  do. Phrase page examples as self-contained as possible, or get sign-off on the 1-line
  package fix (pass `$this->pageContext` as the 5th arg in `askSuggestion`).
- `AI_CHAT_SURFACE=screen` silently drops page_context (AiChatFloating.php:44) — keep
  `drawer`/`modal` on record pages.

**Evolution checklist for any new question:**

1. Labels: every label it touches has nodes (re-check §4 after schema/model changes).
2. Relationships: match the **catalog-declared FK outgoing type** exactly (the
   `UNION` vs `BELONGS_TO_UNION` drift class — never trust inverse names in descriptions;
   same class: `MAINTENANCE` not "BELONGS_TO_MAINTENANCE", `QUOTE_REQUEST` not
   "BELONGS_TO_QUOTE_REQUEST", `BUDGET` not "HAS_BUDGET_DETAILS").
3. Actions: the action key is registered in `entity_actions`/`generic_actions` AND its
   `authorize` tier matches the persona (board/residents hold Membership.role `'contact'` =
   no `$staffOnly`/`$permanentOnly` links).
4. Polymorphic targets (Invoice CUSTOMER, File parent): property match only — the edge
   never materializes.
5. Generation-fragile shapes ([f6]/[f7]) go into the `ai:eval` suite alongside the config
   change.
6. Page questions need an `AiChatFloating` mount with the right `entity_type` + a matching
   `page_examples` key. Every current key (ai.php:1026-1085) has a live mount —
   UnitView.php:131, UnionView.php:138, MeetingPage.php:71, InvoiceForm.php:188,
   SupplierPage.php:76, ProjectPage.php:165, MaintenancePage.php:130, BudgetView.php:100
   (context-less FABs: DashboardView.php:36, OnboardingView.php:85) — EXCEPT **Contact**,
   whose only surface is a Modal and structurally cannot host the fixed-position FAB ([f11]).
7. New scope patterns must be valid, executable Cypher over synced properties/edges — the
   2026-06-10 sweep (§1) catalogs every failure class to avoid; re-run it after any
   `ai:discover` regeneration.
