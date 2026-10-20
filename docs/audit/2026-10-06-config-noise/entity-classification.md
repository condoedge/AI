# Entity classification — CondoEdge `config/ai-entities/*.php` (73 entities)

Read-only analysis, 2026-10-06. No project file, git state, graph or database was modified. Host = `C:\Users\jkend\Documents\Projects\kompo\CondoEdge`, package = `C:\Users\jkend\Documents\Projects\kompo\ai`. Builds on `docs/audit/2026-10-06-config-noise/audit-entity-configs.md` (called "the audit" below).

## 1. Verdict

- **69 of the 73 entities are askable (A).** Each has a page or component in the product and at least one question a manager, board member or owner could realistically ask. That includes 12 of the 14 entities the audit lists as never selected.
- **1 entity is structural-only (B):** `EmailAccount`.
- **0 entities are provably dead (C).** Three are "unsure, recommended C" and need one owner answer each: `MessageForward`, `Folder`, `AccountBalance`.
- **The "could not be asked about" records were already removed from config on 2026-07-23 — but their nodes were not.** 51 labels, 406,399 nodes (55% of the graph) are still there. This is where the real reduction is.
- Two of those 51 look wrongly trimmed, because a realistic question needs them: `Address` (no other source for the address of a building, union or contact) and `Distribution` (the only record of who received an email).

| Class | Entities | Config lines today | Lines removed if applied |
|---|---|---|---|
| A — askable, keep | 69 | 11,067 | 0 from this classification (the audit's 1,633 inert lines sit mostly inside these) |
| B — structural only | 1 | 97 | 12 |
| C — dead, confirmed | 0 | 0 | 0 |
| ? — recommended C, owner must confirm | 3 | 283 | 283 entity lines + 9 file-header lines + 12 lines elsewhere = 304 |
| Orphan graph labels (no config) | 51 | 0 | about 300 lines of relationship entries that point at them; 406,399 nodes |

## 2. Three facts that shape the classification

Read from package code and checked against the live setup.

1. **Stripping aliases, scopes and description does not hide an entity.** The Query Understanding catalog lists every configured entity by label, with or without aliases and description (`src/Services/QueryUnderstandingService.php:189-212`, `:651-672`), and the label stays a valid `entity_type`. A stripped entity can still be picked by its name alone — `AccountBalance` is picked for "balance" questions today. Making B real needs a small package change: a per-entity flag that `buildEntityCatalog` skips. Without it, B is cosmetic.
2. **A structural-only entity only gets a schema card if it sits between two selected entities or on a tenant path.** Cards for non-subjects come from the tenant tier and the closure tier, which adds the intermediate labels on the shortest join path between seeds (`src/Services/Retrieval/EntitySelectionPruner.php:18-50`). A leaf (for example `BillApproval` under `Bill`) is never an intermediate, so stripping it makes it unreachable. For a leaf, B equals C. This is why B has one member.
3. **A label with nodes but no config is denied, not leaked.** `ai.security.on_missing_team_path` and `on_permission_unexistent` are both `deny` in the effective config. Verified in tinker as user 13: `getTeamFilterWithParameters` throws `SecurityException: Cannot verify permissions for … data` for `BudgetDetailQuote`, `Address`, `Tag`, `Distribution`, `ContactType`. So removing a config while leaving the nodes produces a label the model can be shown but is forbidden to query.

Removal mechanics, same for every C and every orphan:
- `ai:ingest` iterates `array_keys(config('entities'))` only (`src/Console/Commands/IngestEntitiesCommand.php:354-357`), and relationship sync does `MATCH` on both ends before `MERGE` (`src/Services/BatchDataIngestionService.php:353-355`). **Deleted nodes of an unconfigured label are never re-created**, not even as stubs.
- Auto-sync skips unconfigured models for create, update and delete (`src/Domain/Traits/HasNodeableConfig.php:106-112`). Nodes left behind go stale: `FundQuote` has 420 nodes for 215 live rows.
- `ai:ingest --fresh` wipes every node (`IngestEntitiesCommand.php:365-402`), so a fresh ingest would also clear the orphans.

## 3. The 73 entities

Columns: lines = entity block length; nodes = local dev graph (MySQL live rows shown when the two differ in a way that matters). "Structural" lists what depends on the entity: `hop` = it is on another entity's `security.team_path`; `in` = other entities declare a relationship to it; `ai.php` = sections of the host `config/ai.php` that name it. Routes are from `routes/web.php`; components are under `app/View` unless a path is given.

### calendar.php, condo.php

| Label | file:lines | lines | nodes | What it is · where the app shows it | Who asks · example | Structural | Class |
|---|---|---|---|---|---|---|---|
| VisitDetail | calendar.php:8-153 | 146 | 42 | Result line of a site visit (OK / inaccessible / problem / urgent) · `Calendar/VisitDetailForm`, `VisitDetailsList` inside `visits.form` | manager, owner · "Which units were inaccessible at the last inspection?" | in: Visit | A |
| Access | condo.php:9-117 | 109 | 251 | Key, fob or remote of a unit · `accesses.form`, `Condo/AccessesUnitList` | manager, owner · "How many fobs does unit 204 have?" | ai.php user_entity_paths | A |
| Announcement | condo.php:118-270 | 153 | 10 | Notice to residents · `announcements.form`, `Condo/AnnouncementsCard` | all · "What are the latest announcements?" | ai.php user_entity_paths, entity_actions, generic_actions | A |
| Building | condo.php:271-399 | 129 | 92 | Building of a syndicate · `buildings.form`, `Condo/UnionBuildingQuery` | manager, board · "How many buildings does Les Terrasses have?" | in: Extra, Union, Unit | A |
| Event | condo.php:400-521 | 122 | 50 | Calendar event · `events.form`, `calendar` | all · "What events are planned this month?" | — | A |
| Extra | condo.php:522-656 | 135 | 2,241 | Parking space or storage locker of a unit · `extras.form`, `Condo/UnitExtrasQuery` | manager, owner · "Which parking space belongs to unit 310?" | ai.php user_entity_paths | A |
| Instruction | condo.php:657-745 | 89 | 0 (0 rows) | Building instruction or guideline document · `Condo/InstructionForm`, `InstructionsUnionList` | owner, manager · "What are the moving-in instructions?" | — | A (empty in dev) |
| Note | condo.php:746-876 | 131 | 269 | Internal note on a union or unit · `unitUnionObjectRoutes('notes')`, `Condo/NotesListCard` | manager · "Are there notes on unit 204?" | — | A |
| Pet | condo.php:877-964 | 88 | 0 (0 rows) | Pet registered to a unit · `Condo/PetForm`, `PetsUnitList` | manager, owner · "Which units have a dog?" | — | A (empty in dev) |
| Reservation | condo.php:965-1075 | 111 | 0 (8 rows, all soft-deleted) | Booking of a shared amenity · `reservations.form`, `reservation-times.form`, calendar | owner, manager · "Who booked the party room on Saturday?" | ai.php entity_actions ×5, generic_actions | A |
| Resource | condo.php:1076-1263 | 188 | 6 | Bookable amenity · `resources.form`, `Condo/ResourcesListCard` | all · "What can I reserve?" | hop: Reservation; ai.php user_entity_paths | A |
| Union | condo.php:1264-1665 | 402 | 89 | Syndicate of co-ownership · `union.view`, `union.manager` | all · "When does our fiscal year start?" | hop for 33 entities; ai.php in 7 sections | A |
| UnionContract | condo.php:1666-1759 | 94 | 0 (0 rows) | Management contract of a syndicate · `Condo/UnionContractForm`, `UnionContractsList` | board, manager · "When does our management contract end?" | — (PII columns) | A (empty in dev) |
| Unit | condo.php:1760-2215 | 456 | 1,877 | Condo unit · `units.catalog`, `unit.view` | all · "What does unit 310 owe?" | hop: Extra, Pet, Vehicle, ContactUnit; ai.php calculations | A |
| Vehicle | condo.php:2216-2301 | 86 | 379 | Vehicle registered to a unit · `vehicles.form`, `Condo/VehiclesUnitList` | manager, owner · "Whose car is the grey Civic?" | ai.php user_entity_paths | A |
| Visit | condo.php:2302-2441 | 140 | 49 | Site visit or inspection · `visits`, `visits.form` | manager, owner · "When is the next inspection?" | in: VisitDetail | A |

### contact.php, discussions.php, eft.php, filemanager.php

| Label | file:lines | lines | nodes | What it is · where the app shows it | Who asks · example | Structural | Class |
|---|---|---|---|---|---|---|---|
| BoardMember | contact.php:9-139 | 131 | 292 | Board seat for a year · `board-members.form`, `Contact/BoardMembersList` | all · "Who is the president of the board?" | ai.php user_entity_paths | A |
| Contact | contact.php:140-453 | 314 | 3,887 | Owner, tenant or occupant · `contacts.catalog`, `contacts.form` | all · "Who lives in unit 302?" | hop: ContactDirectory; ai.php in 6 sections | A |
| ContactDirectory | contact.php:454-582 | 129 | 7,727 | Phone, email or web entry of a contact · `contact-directories` | manager · "What is the phone number of the owner of 302?" | in: Contact | A |
| ContactUnit | contact.php:584-735 | 152 | 2,927 | Link contact ↔ unit with role (owner-occupant, non-occupant owner, tenant, occupant) and dates · `contact-unit.form`, `Condo/UnitHistorySalesQuery` | manager, board · "Who owns unit 310? Who are the tenants?" (the user says owner or tenant, never "contact unit") | ai.php user_entity_paths (17 mentions), calculations (`owners_behind`), a project rule | A — a pivot, but its scopes (`current`, `owners`, `renters`) and description carry the owner/tenant meaning; do not strip |
| Channel | discussions.php:9-131 | 123 | 138 | Discussion channel · `discussions`, `channel-settings` | manager, board · "Which discussion channels does the board have?" | hop: Discussion | A |
| Discussion | discussions.php:132-255 | 124 | 3,103 | Forum post or reply · `discussions`, `discussion-reply`, `project-discussion` | manager, board · "What was said about the roof in the discussions?" | in: Channel, Maintenance, Project | A |
| EftFile | eft.php:9-136 | 128 | 0 (0 rows) | Pre-authorized debit / payables batch sent to the bank · `eft-files.table`, `eft-payable.table` (sidebar `Menus/SidebarCommon.php:229, 248`), `eft-file.download` | manager, accountant · "Was October's pre-authorized debit file accepted by the bank?" | — (PII: `return_accountno`) | A (empty in dev) |
| Folder | filemanager.php:9-126 | 118 | 0 (0 rows) | Folder of the new file manager · route `files-manager-new` exists but no menu links to it; `admin-folder-management` is super-admin only | nobody today; a manager once it ships · "What is in the Insurance folder?" | in: File (pivot, never synced); points at `FileFolder`, absent from the graph | **? → C** |

### finance.php

| Label | file:lines | lines | nodes | What it is · where the app shows it | Who asks · example | Structural | Class |
|---|---|---|---|---|---|---|---|
| Account | finance.php:9-509 | 501 | 36,595 | General-ledger account · `chart-of-accounts`, `account.form` | manager, board · "What is the balance of the operating bank account?" | hop: AccountBalance, Conciliation, Entry | A |
| AccountBalance | finance.php:510-584 | 75 | 1,386 | Opening balance of an account at a start date, entered during finance setup · `initial-balances`, `account-balance.form` | accountant only · "What opening balance was entered for account 1100?" | leaf (in: Account); one ai.php project rule (`ai.php:236`) | **? → C** — every observed use is a mis-route for "balance" questions (§4) |
| Bank | finance.php:585-750 | 166 | 166 | Bank account · `banks.form`, `banking.settings`, `banks-units.view` | manager · "Which bank accounts does the syndicate have?" | in: Union, Unit, Account, Supplier (PII columns) | A |
| Bill | finance.php:751-951 | 201 | 558 | Supplier bill · `bills.table`, `bills.form` | manager, board · "Which bills are unpaid?" | hop: BillApproval, BillDetail; ai.php in 5 sections | A |
| BillApproval | finance.php:952-1038 | 87 | 0 (0 rows) | One person's approval of one bill · `Finance/BillsApprovalTable`, `Bill::billApprovals` | board, manager · "Who approved the Hydro bill?" | leaf | A (empty in dev) |
| BillDetail | finance.php:1039-1146 | 108 | 766 | Bill line: expense account, quantity, price · `Finance/BillDetailsTable` in `bills.form` | manager, board · "How much did we spend on snow removal?" | only path from Bill to Account | A |
| Budget | finance.php:1147-1280 | 134 | 46 | Annual budget · `budget`, `budget.view` | board, manager · "Is the 2026 budget approved?" | hop: BudgetDetail; ai.php 3 sections | A |
| BudgetDetail | finance.php:1281-1405 | 125 | 818 | Budget line: amount per account and fund · `Finance/BudgetDetailForm` in `budget.view` | board, manager · "How much is budgeted for insurance?" | the budget amounts exist only here | A |
| Conciliation | finance.php:1406-1493 | 88 | 121 | Bank reconciliation · `conciliations.table` (sidebar `:232`), `conciliation.page`, `report.account-conciliations` | manager, accountant · "Is August's bank reconciliation done?" | leaf | A |
| Entry | finance.php:1494-1638 | 145 | 12,329 | Journal line; a payment is an entry with a payment method · `payment-entry.form`, `Finance/TransactionEntriesTable`, `report.account-transactions` | manager · "Which payments came in by cheque this month?" | in: Account, Transaction | A |
| Fund | finance.php:1639-1777 | 139 | 384 | Operating, contingency, insurance… fund · `funds.form`, `Finance/FundsTable` | board, owner · "How much is in the contingency fund?" | in: Union, Account, BudgetDetail, InvoiceDetail | A |
| Invoice | finance.php:1778-2073 | 296 | 6,006 | Condo-fee invoice · `invoices.table`, `invoices.form` | all · "Which invoices are overdue?" | hop: InvoiceDetail; ai.php in 7 sections | A |
| InvoiceDetail | finance.php:2074-2198 | 125 | 15,706 | Invoice line: fund, account, unit · `Finance/InvoiceDetailsTable` in `invoices.form` | owner, manager · "What is included in my October contribution?" | only path from Invoice to Fund | A |
| Tax | finance.php:2199-2293 | 95 | 320 | Sales-tax definition and rate · `taxes.form`, `tax-account.form`, `report.sales-tax` | accountant · "Which taxes are set up, at what rate?" | in: Account, BillDetail, InvoiceDetail, Team | A (weak — tax amounts live in the trimmed `*DetailTax` labels) |
| Transaction | finance.php:2294-2538 | 245 | 4,505 | Accounting transaction · `transactions`, `transactions.form` | manager · "Show last month's bank transfers" | in: Union, Unit, Bill, Entry; ai.php 2 sections | A |
| UnionApproval | finance.php:2539-2621 | 83 | 0 (0 rows) | Approval bracket: N approvals for bills between two amounts · `approvals.settings` → `Finance/UnionApprovalsTable` | board, manager · "How many approvals does a $5,000 bill need?" | leaf | A (empty in dev) |

### general.php, meetings.php

| Label | file:lines | lines | nodes | What it is · where the app shows it | Who asks · example | Structural | Class |
|---|---|---|---|---|---|---|---|
| Activity | general.php:9-131 | 123 | 15,226 | Team activity feed entry · `Dashboard/ActivityList` ("team daily activity") | manager · "What did the team do today?" | — | A (log-like) |
| File | general.php:132-411 | 280 | 8,286 | Stored document · `files-manager`, `file-info.form` | all · "Where is the insurance certificate?" | in: 18 entities; ai.php entity_actions, scope_matching | A |
| TeamRole | general.php:412-518 | 107 | 1,490 | Team membership and role (`team_user`) · `Kompo/Teams/AllMyTeamsQuery`, `RoleManagementModal` | manager · "Who are the managers in my team?" | none declared; 184 logged queries use it | A |
| Notification | general.php:519-691 | 173 | 6,557 | In-app alert or reminder · `Dashboard/MaryanneNotificationsList`, `notifications.remind` | all · "Do I have unread notifications?" | — | A (log-like) |
| Recurrence | general.php:692-830 | 139 | 15 | Recurring-bill schedule · `bills-recurring.table` (sidebar `:249`), `bills-recurring.form` | manager · "Which bills recur every month?" | — | A |
| Review | general.php:831-974 | 144 | 17 | Contact change waiting for staff review · `reviews.*` routes, `Dashboard/MaryanneNotificationsList` | manager · "Which contact changes are waiting for review?" | ai.php attention_sources | A |
| Team | general.php:975-1140 | 166 | 28 | Management company or self-managed team · `settings`, `teams-unions-manage` | all · "Which syndicates does my team manage?" | identity label; in: 24 entities | A |
| TeamInvitation | general.php:1141-1216 | 76 | 4 | Pending invitation to join a team · `Kompo/Teams/TeamInvitationsList`, `team-invitations.accept` | manager · "Who has not accepted their invitation?" | — | A (weak) |
| User | general.php:1217-1375 | 159 | 1,370 | Platform user · `settings` | all · "Who is on my team?" | identity label; hop: MessageForward; ai.php 4 sections | A |
| Meeting | meetings.php:8-229 | 222 | 103 | AGM or board meeting · `meetings`, `meetings.page` | all · "When is the next AGM?" | hop: Motion, Schedule, Vote, Voter; ai.php 6 sections | A |
| Motion | meetings.php:230-317 | 88 | 248 | Motion put to a vote · `motions.form`, `Meetings/MeetingMotionsPage` | board, owner · "Which motions passed at the last AGM?" | hop: Vote | A |
| Schedule | meetings.php:318-421 | 104 | 875 | Agenda item with minutes · `schedules.form` | all · "What is on the agenda?" | in: Meeting, Motion | A |
| Vote | meetings.php:422-507 | 86 | 1,827 | One ballot on one motion · `Meetings/MotionVotesList` | manager, board · "How many voted against motion 4?" | leaf | A |
| Voter | meetings.php:508-617 | 110 | 2,015 | Attendance and proxy record · `Meetings/MeetingVotesPage`, `meeting-proxy.form`, `meeting.confirm-presence` | manager, board · "Who attended? Who gave a proxy?" | in: Unit, Meeting, Vote | A |

### messaging.php, phone.php

| Label | file:lines | lines | nodes | What it is · where the app shows it | Who asks · example | Structural | Class |
|---|---|---|---|---|---|---|---|
| Attachment | messaging.php:9-90 | 82 | 53,511 | File attached to an email · shown inside messages, `attachment-save.form` | manager · "Which email had the plumber's quote attached?" | leaf (in: Message; PII) | A (log-like; 3 aliases collide with File) |
| EmailAccount | messaging.php:91-187 | 97 | 14,147 | E-mail identity of a user, contact, supplier or union · no page; only the inbox mailbox selector (`vendor/condoedge/messaging/src/Kompo/CustomInbox/EmailAccountInboxOption.php`) | nobody names it; "my emails" is asked of Thread or Message | ai.php `user_entity_paths.Thread` (cypher and entity list, `ai.php:511-519`); sender join `Message.sender_id`; in: Contact, Team, User, Message, Supplier, SupplierContact | **B** |
| Message | messaging.php:188-325 | 138 | 63,500 | Email message · `inbox.message`, `message-reply.form` | manager · "What did the insurer answer last week?" | ai.php user_entity_paths; in: Review, Attachment, Thread | A |
| MessageForward | messaging.php:326-415 | 90 | 4 (7 rows) | A user's out-of-office auto-reply and forwarding rule · settings page (`resources/views/settings/message-forwards-form.blade.php` → `Settings/MessageForwardOutOfOfficeForm`) | manager, marginal · "Who is out of office this week?" | none | **? → C** |
| Thread | messaging.php:416-570 | 155 | 44,686 | Email conversation · `inbox` | all · "Which threads are flagged?" | hop: Message; ai.php 3 sections | A |
| CallLog | phone.php:9-125 | 117 | 4,428 | Call from the IP-phone integration · `report.calllog` (menu link commented out, `Menus/SidebarCommon.php:284`), `phone.incomingcall` | manager · "Who called about unit 204 last week?" | in: Contact (PII) | A |

### supplier.php, task.php

| Label | file:lines | lines | nodes | What it is · where the app shows it | Who asks · example | Structural | Class |
|---|---|---|---|---|---|---|---|
| Component | supplier.php:9-176 | 168 | 455 | Building component (roof, elevator…) with lifetime and warranty · `components.view` (sidebar `:191`) | manager, board · "When was the roof installed and how long should it last?" | in: Union, Maintenance | A |
| Maintenance | supplier.php:177-448 | 272 | 118 | Maintenance plan item · `maintenances.view`, `maintenances.page` | manager, board · "Which maintenances are planned this spring?" | hop: VisitDetail, MaintenanceContract, MaintenanceDate, MaintenanceDetail; ai.php 3 sections | A |
| MaintenanceContract | supplier.php:449-612 | 164 | 56 | Service contract for a maintenance · `Supplier/MaintenanceContractForm`, `MaintenanceContractsList` | manager, board · "Which maintenance contracts end this year?" | ai.php attention_sources (disabled) | A |
| MaintenanceDate | supplier.php:613-737 | 125 | 565 | Scheduled or completed maintenance date · `maintenance-date.form`, `maintenances.calendar` | manager, owner · "When is the next elevator maintenance?" | hop: VisitDetail; ai.php attention_sources, generic_actions | A |
| MaintenanceDetail | supplier.php:738-840 | 103 | 54 | Note logged on a maintenance · `Supplier/MaintenanceDetailForm`, `MaintenanceDetailsList` | manager · "What was noted at the last generator maintenance?" | leaf | A (weak) |
| Project | supplier.php:841-1009 | 169 | 49 | Capital project · `projects.view`, `projects.page` | board, manager · "Where is the roof project at?" | hop: QuoteRequest, WorkOrder; ai.php chat, entity_actions | A |
| QuoteRequest | supplier.php:1010-1137 | 128 | 37 | Request for quotes · `quote-requests.management` (sidebar `:187`) | manager, board · "How many quotes did we get for the windows?" | hop: WorkOrder; ai.php attention_sources | A |
| Supplier | supplier.php:1138-1500 | 363 | 1,091 | Supplier · `suppliers.catalog`, `suppliers.page` | manager · "Who is our elevator supplier?" | hop: SupplierRating; ai.php 5 sections | A |
| SupplierContact | supplier.php:1501-1692 | 192 | 237 | Contact person at a supplier · `Supplier/SupplierContactForm`, `SupplierContactsList`, `supplier-contact.thread.to` | manager · "Who is our contact at Plomberie X?" | in: Team, Supplier | A |
| SupplierRating | supplier.php:1693-1823 | 131 | 0 (0 rows) | Rating of a supplier · `Supplier/SupplierRatingForm`, `SupplierRatingSummaryList`, `admin-suppliers-ratings` | manager, board · "How is this supplier rated?" | leaf | A (empty in dev) |
| WorkOrder | supplier.php:1824-1983 | 160 | 117 | Work order / supplier quote · `Supplier/WorkOrdersTable`, `WorkOrderForm` | manager · "Which work orders are waiting for a quote?" | ai.php attention_sources | A |
| Task | task.php:9-273 | 265 | 2,082 | Task · `tasks-manager`, `tasks-kanban` | manager · "Which tasks are urgent?" | hop: TaskDetail; ai.php 3 sections | A |
| TaskDetail | task.php:274-413 | 140 | 6,163 | Comment or follow-up on a task, with reminder · `Task/TaskDetailForm`, `TaskDetailsList`, calendar | manager · "What is the latest update on the roof-leak task?" | ai.php entity_actions ×3 | A |

Notes on the table:
- Eight config labels have no nodes and no live MySQL rows locally (`Instruction`, `Pet`, `UnionContract`, `EftFile`, `Folder`, `BillApproval`, `UnionApproval`, `SupplierRating`); `Reservation` has 8 rows, all soft-deleted. Eight of the nine (all but `Folder`) have a working page and a realistic question, so they stay A. Empty dev data says nothing about production.
- "A" means the entity stays. It does not mean every line stays: for `BillDetail`, `InvoiceDetail`, `BudgetDetail`, `BillApproval`, `UnionApproval`, `MaintenanceDate`, `MaintenanceDetail` and `AccountBalance` all six aliases are spellings of the label itself, so the entity has no natural-language synonym in config (audit §3.7).
- The three most log-like A entities are `Attachment`, `Notification` and `Activity`. They stay A on the "a realistic question exists" rule; see decision 10.

## 4. B, C and ? in detail

### B — EmailAccount (`messaging.php:91-187`, 97 lines, 14,147 nodes)

- Why not A: it is an address record. Nobody asks about "email accounts"; the eval never selected it and the log holds one query.
- Why not C: `ai.php:511-519` (`user_entity_paths.Thread`) matches `(ea:EmailAccount)` to find "my threads", and a message's sender is `Message.sender_id = EmailAccount.id`. Thirteen relationship entries in six other entities target it (Contact, Team, User, Supplier ×4, SupplierContact ×4, Message ×2).
- Keys to strip: `metadata.aliases` (`messaging.php:159-169`, 11 lines) and `metadata.description` (`:170`, 1 line). `metadata.scopes` (`:171`) is already `[]`. **12 lines.**
- Keep: `graph` (`:92-129`), `security` (`:150-157`, `team_path => 'team_id'`), `common_properties` (`:172-185`).
- The `vector` block (`:130-149`, 20 lines) cannot go until the package change in audit §3.4.
- Caveat: because of fact 1, stripping alone leaves `- EmailAccount` in the QU catalog. The clean form is a per-entity flag in the package; with the flag, the description can stay for the card. Re-run `ai:index-context` afterwards — the aliases are embedded there.

### ? → C candidates

| Entity | Lines | Nodes to delete | Also remove | Would `ai:ingest` re-create the nodes? | What the owner must answer |
|---|---|---|---|---|---|
| MessageForward | `messaging.php:326-415` (90) | 4 | — | No, once the config is gone | Should the assistant answer "who is out of office / who covers X's emails"? |
| Folder | `filemanager.php:9-126` (118; the whole 127-line file) | 0 | `general.php:165-169`, File → Folder pivot (5 lines) | No | Is the folder-based file manager (`files-manager-new`) going to customers soon? |
| AccountBalance | `finance.php:510-584` (75) | 1,386 | `finance.php:61-66`, Account → AccountBalance (6 lines); `ai.php:236` (1 line) | No | Do accountants need opening-balance questions answered? |

- **MessageForward.** A personal setting, 7 rows in MySQL. Its tenant rule rides `user.current_team_id`, which changes when a user switches team.
- **Folder.** No data, no menu entry, and its only link to `File` is a pivot that is never synced. Restore from git when the feature ships.
- **AccountBalance.** Setup data shown on one page. The label name attracts "balance" and "owe" questions:
  - In the p9–p14 eval, QU picked it 19 times and it got 19 cards; none of those turns' queries used it (audit §3.11; recorded as a failure in `2026-09-30-phase1b.md:33`).
  - In `ai_query_logs`, the queries that do use it answer "Show me the balance." (18 rows), "What's this unit's balance?" (3) and "Show this unit's account statement" (4), plus 6 test rows. All are about a unit's or syndicate's balance, which opening balances of ledger accounts cannot answer. No logged question is about opening balances.
  - It is a leaf, so B is not an option.

If all three are confirmed: 283 entity lines, 9 header lines of `filemanager.php`, and 12 lines in other files; 1,390 nodes; three fewer entries in the QU catalog. Drop the matching per-entity Qdrant collections too (not inspected here).

## 5. The 51 graph labels with no config

All 51 model classes still `implement Nodeable`, so only the config entry is missing. Total 406,399 nodes of 738,895.

**Do they still surface?** Yes.
- `BudgetDetailQuote` has a full schema card (properties and edges) in `storage/eval/p13-1001-B.json`.
- `Blueprint` and `Servicing` appear in label lists recorded for a turn in `p11-1001-A.json`; the audit (§1.2) reports them as carded through the closure tier. I did not re-derive which list it is.
- `InvoiceItem`, `ContactType` and `AccountName` are printed as edge targets or in notes on the `InvoiceDetail`, `ContactDirectory` and `Account` cards.
- 44 relationship entries in the 73 configs still target a label with no config (the audit counted 37 with a different bucketing). 16 of them carry a foreign key, so ingest keeps creating edges into the stale nodes: `ContactDirectory → ContactType` 7,727 edges, `CallLog → PhoneConfiguration` 4,428, `InvoiceDetail → InvoiceItem` 991, `BillDetail → BillItem` 751.
- None is named in `config/ai.php`.

Any query that binds one of them is refused (fact 3). They cost prompt space and can only produce a denied query.

| Label | Nodes | What it is | Live edges to configured labels | Config entries pointing at it | Recommendation |
|---|---|---|---|---|---|
| Distribution | 193,980 | Recipient row: message ↔ email account | Message 171,986; EmailAccount 193,734 | EmailAccount, Message (no FK) | **Decision 3** — the only record of who received an email |
| MessageRead | 86,357 | Read receipt | none | — | delete |
| ThreadBox | 49,082 | Per-user archive / trash flag | none | Thread (no FK) | delete |
| TaskRead | 19,305 | Read receipt | none | TaskDetail (no FK) | delete |
| Tag | 19,149 | Tag on suppliers, files, threads… | none (pivot never synced) | 12 entities, 11 via pivot | delete; **decision 9** |
| DiscussionRead | 11,773 | Read receipt | Discussion 11,773; User 11,764 | Discussion (no FK) | delete |
| BudgetDetailQuote | 5,575 | Per-unit share of a budget line | BudgetDetail 5,575; Unit 5,575 | BudgetDetail (no FK) | delete — it sits on the shortest BudgetDetail ↔ Unit path, which is why it gets a card |
| Relation | 4,225 | Generic link between two records | none | Bill (FK `parent1_id`) | delete |
| AccountName | 3,981 | Chart-of-accounts template name | Team 2,389; Tax 20 | — (named in a note on `Account.an_id`) | delete |
| UserSetting | 2,840 | Per-user UI setting | none | — | delete |
| ImportRow | 2,267 | Row of a data import | Import 2,267 | — | delete |
| InboundEmail | 1,249 | Raw inbound mail | none | — | delete |
| Address | 1,243 | Postal address of a contact, supplier, building, union or team | Contact 545; Supplier 514; Building 88; Union 85; Team 5; Review 21 | Building, Union, Contact, Team, Supplier ×2 (all with FK) | **Decision 2 — restore.** `Building`, `Union` and `Contact` have no address property; `Team.team_address` is null on every node |
| InvoiceItem | 1,236 | Reusable invoice item | InvoiceDetail 991; Account 1,236; Union 1,236 | InvoiceDetail (FK) | delete |
| BillItem | 604 | Reusable bill item | BillDetail 751; Account 603; Union 603 | BillDetail (FK) | delete |
| TaskLink | 527 | Link task ↔ record | none | Task (no FK) | delete |
| FundQuote | 420 (215 live rows) | Per-unit share of a fund | Unit 394; Fund 254 | Fund (no FK) | delete — already stale |
| Rgcq | 398 | Reference account mapping | none | — | delete |
| DiscussionBox | 288 | Per-user discussion flag | User 287; Discussion 249 | — | delete |
| Constituent | 235 | Blueprint room component | none | — | delete |
| BillDetailTax | 191 | Tax amount of a bill line | BillDetail 191; Tax 191 | BillDetail (no FK) | delete — but see the note under the table |
| BillItemTax | 183 | Tax of a bill item | BillItem 183; Tax 183 | — | delete |
| Servicing | 171 | Maintenance template | Maintenance 6 | Maintenance (FK) | delete |
| Newsletter | 125 | Public-site newsletter sign-up | none | — | delete — emails of non-customers, no tenant |
| FundDate | 117 | Fund schedule date | Fund 117 | Fund (no FK) | delete |
| Member | 114 | Channel membership | Channel 114; User 112 | Channel (no FK) | delete |
| ServiceElement | 99 | Maintenance template element | Component 5 | Component (FK) | delete |
| AnnouncementRead | 84 | Read receipt | Announcement 84; User 84 | Announcement (no FK) | delete |
| Import | 68 | Data import run | Team 68; User 68; Union 57; File 65 | — | delete |
| Material | 65 | Blueprint material | none | — | delete |
| Element | 55 | Blueprint element | none | — | delete |
| Question | 52 | Manager questionnaire question | none | — | delete |
| QuestionUser | 45 | Questionnaire answer | none | — | delete |
| Room | 44 | Blueprint room | none | — | delete |
| ComponentModel | 36 | Component template | none | — | delete |
| Template | 35 | Message template | none | — | delete |
| MaintenanceModel | 29 | Maintenance template | none | — | delete |
| Signature | 27 | Email signature | none | User (no FK) | delete |
| PlanService | 16 | CondoEdge subscription plan line | Service 16; Plan 16 | — | delete |
| Price | 16 | Subscription price | Service 15 | — | delete |
| Service | 14 | Subscription service | — | — | delete |
| InvoiceDetailTax | 13 | Tax amount of an invoice line | InvoiceDetail 13; Tax 13 | InvoiceDetail (no FK) | delete |
| InvoiceItemTax | 13 | Tax of an invoice item | InvoiceItem 13; Tax 13 | — | delete |
| Blueprint | 13 | Original-unit blueprint (`blueprints.query`) | none | — | delete |
| PaymentMethod | 11 | A customer's payment method for the CondoEdge subscription | User 11 | — | delete — billing data of the SaaS itself |
| ContactType | 8 | Lookup: kind of contact entry | ContactDirectory 7,727 | ContactDirectory (FK) | delete — replaced by the `contact_type_id` legend (`contact.php:575`) |
| ChargerType | 6 | Lookup: EV charger type | none | Extra (FK) | delete once `Extra.charger_type_id` has a legend |
| Plan | 5 | Subscription plan | — | — | delete |
| PlanUser | 5 | A user's subscription | User 5; Plan 5; PaymentMethod 5 | — | delete |
| AccessType | 3 | Lookup: kind of access device | Access 251 | Access (FK) | delete once `Access.access_type_id` has a legend |
| PhoneConfiguration | 2 | IP-phone configuration | CallLog 4,428 | CallLog (FK) | delete |

- **Recommendation: delete the nodes of 49 labels (211,176 nodes)** and settle `Address` and `Distribution` by decisions 2 and 3.
- When the nodes go, remove the 44 relationship entries that point at them. Otherwise the 16 with a foreign key turn into "fk (no edge, join with WHERE)" hints to a label that no longer exists.
- Tax amounts: with `BillDetailTax` and `InvoiceDetailTax` trimmed, "how much GST did we pay?" has no source in the graph. `Tax` only answers rate questions.
- Not checked: whether stale per-entity Qdrant collections exist for these labels.

## 6. Decisions for the owner

1. Delete the nodes of the 49 orphan labels that nobody asks about (211,176 nodes), together with the relationship entries that point at them (44 in total, less the 8 for `Address` and `Distribution` if decisions 2 and 3 keep those)? Recommended: yes.
2. Restore `Address` as an askable entity, so the assistant can give the address of a building, syndicate or owner? Recommended: yes. If no, delete its 1,243 nodes and 6 relationship entries.
3. Should the assistant answer "who received this email / who was copied"? If yes, `Distribution` comes back as a structural-only entity (193,980 nodes stay). If no, delete it.
4. Should it answer "who is out of office / who covers X's emails"? If no, `MessageForward` is C. Recommended: no.
5. Will the folder-based file manager reach customers soon? If no, `Folder` is C until it does. Recommended: remove now.
6. Do accountants need opening-balance questions? If no, `AccountBalance` is C. Recommended: no — this also removes a known mis-route for "balance" questions.
7. Accept a small package change (a per-entity "not a subject" flag, skipped by the QU catalog) so `EmailAccount` — and `Distribution` if restored — can be truly structural-only? Recommended: yes; without it B saves 12 lines and changes nothing.
8. If production shows 0 rows for an entity whose feature exists (`EftFile`, `Pet`, `Instruction`, `UnionContract`, `SupplierRating`, `BillApproval`, `UnionApproval`, `Reservation`), should it be removed until used? Recommended: no — an empty entity lets the assistant say "none recorded".
9. Should "suppliers or files by tag" (for example "find me a plumber") be answerable? If yes, that is new work: tag pivots are not ingested and the 19,149 `Tag` nodes have no edges. If no, the 12 `Tag` relationship entries go with decision 1.
10. Keep `Attachment` (53,511 nodes), `Activity` (15,226) and `Notification` (6,557) as askable? Recommended: yes. They are the only remaining candidates if you want a harder cut.

## 7. Method and limits

- Config facts (line ranges, aliases, scopes, relationships, team paths, ai.php mentions) come from requiring the 13 non-empty config files in a scratch PHP script and parsing the source by indentation. The 73 ranges match the audit's.
- Node counts, the 54 live relationship patterns that touch an orphan label, and MySQL row counts come from two read-only tinker runs.
- UI evidence: `routes/web.php` read in full, `app/View/Menus/SidebarCommon.php` for menu links, and a scan of `app/View`, `app/Kompo`, `app/Http`, `resources/views` and the eft and messaging vendor packages for model references.
- Security behaviour for unconfigured labels: read from `TeamPathResolver`, `AiSecurityService` and `QueryExecutor`, then confirmed with one tinker call as user 13.
- Not done: no production data seen, no eval run, no config rendered through the real card builder, Qdrant not inspected. Askability is a domain judgement; the examples are questions I consider realistic, not questions observed in logs.
