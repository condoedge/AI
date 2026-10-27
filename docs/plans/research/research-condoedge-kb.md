# CondoEdge knowledge base: research report

Paths are relative to `C:\Users\jkend\Documents\Projects\kompo\CondoEdge` unless noted. Coolecto refs are under `...\ActiviteDeFinancement`.
Branch `dev`; `config/ai.php` has uncommitted edits (a new `domain` block, lines ~246-326). Read-only research, nothing modified.

## 0. Coolecto reference format (what we mirror)

- Article: frontmatter `titre`, `public`, `pages: [route names]`, `chemin`, `mots_cles`, `voir_aussi`, then a French body with « labels » (`resources/ai-help/aide/organisateur/adhesions-liste-des-membres.md:1-9`; `acheteur/achat-code-promo.md:1-9`).
- Coolecto file counts: organisateur 320, acheteur 57, participant 27, fournisseur 54 (= 458).
- Frontmatter mapping, `config/ai.php:193-202` (Coolecto): `physical_paths => ['resources/ai-help/aide/**/*.md']`, `physical_collection => 'help_articles'`, `corpus_languages => ['fr']`, `frontmatter.keys => title=>titre, audience=>public, breadcrumb=>chemin, keywords=>mots_cles, related=>voir_aussi`.
- Audiences, `config/ai.php:~68-120` (Coolecto): `ai.audiences.resolver = AudienceResolver::class` and `map[audience] = [documents => [...audiences readable], insider_voice, prompt (with {role}), example_questions]`. The organiser audience also reads the buyer folder.
- `app/Ai/AudienceResolver.php`: `resolve($user)` returns `ResolvedAudience(key, role)` from the user's mode (scanner, participant, customer, supplier, else organiser). Returning null means no audience.
- Package side (`vendor/condoedge/ai` is a symlink to `...\kompo\ai`): `config/ai.php:113-130` of the package says documents with no `audience` are read by everyone, and "without a resolver nothing is filtered". The frontmatter reader (`src/Support/Frontmatter.php`) reads title, audience, pages, breadcrumb, keywords, related, slug (default = file name).
- Package `navigation.pages` (route, label, description, audiences, authorize) is what Coolecto uses for the buttons under answers. CondoEdge does not use it (see section 3).

## 1. Who uses CondoEdge and how role/context is determined

### 1.1 The role model: ONE role per user per team (Membership), no "mode" switch

There is no Coolecto-style `isInParticipantMode()` concept. The role is a string on the `Membership`/`TeamRole` row `(user_id, team_id, role)`, read by `HasRoles::getRole()` (`app/Models/Traits/HasRoles.php:38-52`). It aborts 403 "your accesses have been revoked" if the row is missing.

Roles are defined in `app/Rbac/Role.php`. French labels are in `resources/lang/fr/roles.php`.

| Role key | FR label (`resources/lang/fr/roles.php`) | Who it is | Source |
|---|---|---|---|
| `super-admin` | Super administrateur (`:14-15`) | CondoEdge staff, all permissions `*` | `Role.php:60-65` |
| `lead` | Gestionnaire en chef (`:10-11`) | Chief manager / owner of the team | `Role.php:83-96` |
| `manager` | Gestionnaire (`:18-19`) | Union manager | `Role.php:97-109` |
| `accountant` | Comptable (`:3-4`) | Accounting only | `Role.php:110-120` |
| `assistant` | Adjoint.e administratif (`:6-7`) | Administrative assistant | `Role.php:121-131` |
| `concierge` | Concierge (`:8-9`) | "read-only access to its unions' data" | `Role.php:132-139` |
| `contact` | Copropriétaire ou locataire (`:20-21`) | Owners, tenants AND board members | `Role.php:140-146` |
| `supplier` | Fournisseur (`:16-17`) | Supplier account (essentially a stub, see 1.6) | `Role.php:66-71` |

Registered into Jetstream at `app/Providers/JetstreamServiceProvider.php:49-67`.

Route middleware tiers (`Role.php:10-48`):
- `lead` = super-admin|lead
- `manager` = lead|manager
- `administration` = manager|assistant
- `permanent` = administration|accountant
- `team` = permanent|concierge (any staff)
- `team-owner` = personal-team owner, used for the subscription pages

Permissions per role are in `app/Rbac/Permission.php`.
- Manager-only (`:14-38`): `announcement:create/update/delete` (`:~190`), `union:create/delete`, `reservation:manage`, `supplier:invite`, tags.
- Permanent (`:51-77`): `union:update`, `contact:invite`, `boardMember:create`, `supplier:create/update`, `file:update/delete`.
- Team (`:79-89`): `thread/channel:viewAny`, `supplier:viewAny`.
- Contact (`:91-97`): only `vehicle:delete`, `pet:delete`.
- Finance: permanent gets invoice/bill/transaction (`:121-136`); accountant gets fund/budget/conciliation (`:138-147`).
- Gestion: meetings/motions/votes/projects/quotes (`:163-191`).
- Maintenance: `:200-220`.

### 1.2 The hidden sub-roles

- **Board member (administrateur du syndicat)**: NOT a role. It is a `contact` Membership plus a row in `board_members` for the CURRENT union's latest year.
  - `HasRoles::isBoard()` (`:54-61`) = the user is in `currentUnion()->currentBoard()` (max year).
  - `isBoardMember()` = `isContact() && isBoard()` (`:177-180`); `isMereContact()` = `isContact() && !isBoard()` (`:172-175`).
  - Board positions in `app/Models/Contact/BoardMember.php:14-34` (president 5, VP 10, secretary 15, treasurer 20, member 25).
  - So a person can be a board member in union A and a plain owner in union B. It depends on `current_union_id`.
- **Owner vs tenant**: also NOT a role. Both are `contact`. The difference is the `contact_unit.link_id` (`app/Models/Contact/ContactUnit.php:22-25, 106-112`):
  - owner-occupying (1)
  - owner-not-occupying (2)
  - renter-renting (3)
  - renter-occupying (4)
  - `User::isOwnerOf($unitId)` (`HasRoles.php:142-145`) gates owner-only features (e.g. the "Débits bancaires" payments menu: `SidebarContact.php:19,63-65`).
  - Co-owners: several `contact_unit` rows (several owners) per unit; the UI has "Copropriétaires" and "Locataires" cards (`app/View/Contact/UnitOwnersCards.php`, `UnitRentersCards.php`).
- **Property manager vs self-managed syndicate**: `teams.team_type` (`app/Models/Team.php:93-95`):
  - 1 = autogérée (self-managed)
  - 2 = gestionnaire (management company)
  - 3 = ambassadeur
  - Used for wording only. `isAutoGeree()` (`Team.php:308`) switches the button label "contact your syndicate" vs "contact your manager" (`SidebarCommon.php:131`).
  - In a self-managed team the board members probably ARE the lead/manager users. **Open question**: confirm with the owner how self-managed teams assign roles.
- **Employees**: staff roles above (`lead`, `manager`, `accountant`, `assistant`, `concierge`). Invited by email from "Réglages de l'équipe" (`teams.show`); see `TeamInvitationForm`, `RoleManagementModal`, `TeamMembersQuery` in `app/Kompo/Teams/`. `User::relevantRoles()` (`HasRoles.php:127-139`) lists the assignable roles; `contact` is excluded from team invitations.
- **Accountants**: the `accountant` role (staff). Accountant = finance-only; sidebar still shows the manager menus, but `permanent()` routes are open to them.

### 1.3 How context is determined

- **Team** = `users.current_team_id`; helpers `currentTeam()`, `currentTeamId()` (`app/Kompo/Helpers/utilities.php:~118-140`). Switch via the navbar team switcher (`app/View/Menus/NavbarCommon.php:30-62`, `Team::switchToOtherTeamAndUnion`).
- **Union (copropriété)** = `users.current_union_id`; `currentUnion()` (`utilities.php:86-96`); the sidebar union switcher is `SidebarCommon::unionSwitcher` (`:169-188`, `UnionSwitcher.php`). Staff see all unions of the team, residents only their linked unions (`userUnions()`, `utilities.php:142`).
- **Hierarchy**: Team → Union (+ child unions when `Union::LEVEL_HORIZONTAL`, `Union.php:80-81,355-358`, "Copropriétés liées") → Building → Unit → ContactUnit ↔ Contact (↔ User via `contacts.user_id`).
- **Home route**: `User::homeRoute()` = `union.view` for contacts, `dashboard` for everyone else (`app/Models/User.php:330-333`). After login `LoginRedirectController`.
- **Modules per team**, `Team.php:515-528` (any team created before 2026-06-17 gets all three):
  - `has_gestion`: meetings, tasks, inbox, announcements, projects, quotes
  - `has_finance`: finance menu and reports
  - `has_maintenance`: components, maintenances, reserve fund
  - Middleware `has-gestion`, `has-finance`, `has-maintenance`, `has-maintenance-or-finance`, `has-plan` (`app/Http/Kernel.php:70-76`)
  - `HasRoles::hasPermission` also returns false for finance/gestion/maintenance permissions if the module is off (`HasRoles.php:112-125`)
  - So articles must be conditional on modules, e.g. the sidebar shows no Finance link without `has_finance`.
- **Subscription**: `has-plan` redirects to `plans.pick` (team owner) or `trial.expired` (`app/Http/Middleware/UserHasActivePlanSubscription.php`).
- **Per-union visibility toggles** residents hit:
  - `unions.access_level` (VISIBILITY_ALL 3 / BOARD 2 / MANAGERS 1, `app/Kompo/Helpers/visibility.php:4-6`): an owner sees a "Union in maintenance" page when the level is board or managers only (`UnionView.php:49-54`).
  - `finance_visible` (`FinanceVisibleForm.php`): hides the Finances tab for owners and board (`UnionView.php:274`, `UnitView.php:54,368`).
  - `reservable_items` shows the resources card (`UnionView.php:358`).
- **Invitation-only for residents**: no self-signup for owners. Manager sends an email via `ContactInvitationForm` (needs `contact:invite`) → signed route `contact-invitations.register` (`ContactRegisterForm`, sets role `contact`, lands on `union.view`, `:~75-95`). Suppliers: `supplier-invitations.register` (role `supplier`). Staff: `team-invitations.accept`. Self-service signup only creates a new syndicate team (`register.new-team`; `PreRegisterForm.php`).
- **Impersonation** (super-admin) exists (`Route::impersonate()`, `SidebarCommon.php:201-208`). Not for the KB.

### 1.4 What each persona sees (menus)

`HasRoles::relevantSidebar()/relevantNavbar()` (`:224-248`): super-admin → `SidebarAdmin`; contact → `SidebarContact` + `NavbarContact`; supplier → `SidebarSupplier` + `NavbarSupplier`; else `SidebarManager` + `NavbarManager`.

**Staff** (`app/View/Menus/SidebarManager.php:16-49`, shared pieces in `SidebarCommon.php`). Section "Gestion" (`menus.management`):
- Tableau de bord (`dashboard`)
- Copropriété (`union.view` or `union.new`)
- Unités (`units.catalog`; submenus Unités types `blueprints.query` and Modèles `service.models` need maintenance)
- Agenda: Calendrier (`calendar`), Réunions (`meetings`, needs gestion), Visites (`visits`)
- Maintenance (`menus.services`, needs gestion): Fournisseurs (`suppliers.table`), Demandes de devis (`quote-requests.management`), Projets (`projects.view`), Composantes (`components.view`), Entretiens (`maintenances.view`), Fonds de prévoyance (`reserve-fund.dashboard`)
- Finance (only `isPermanent()`, i.e. not concierge; and `has_finance`; links to `finance.setup` until `finance_setup` is true), `SidebarCommon.php:250-281`: Comptes (`chart-of-accounts`), Budgets (`budget`), Transactions (`transactions`), Recevables (`invoices.table`) with Âge de compte (`report.invoices-receivables`), État de compte (`report.unit-statement`), fichiers EFT (`eft-files.table`); Payables (`bills.table`) with Balance de vérif. (`report.bills-per-supplier`), état de compte fournisseur, EFT payables, factures récurrentes (`bills-recurring.table`), Fournisseurs; Conciliations bancaires (`conciliations.table`); Réglages finances (`banking.settings`); Rapports finance (13 reports, `:342-362`)
- Contacts (`contacts.table`), Fichiers (`files-manager`)
- Rapports (permanent only; `SidebarCommon.php:310-340`): Registre des copropriétaires, Registre des unités, Registre d'urgence, Fractions, Plan comptable, Avis de cotisation, plus the finance submenu
- Paramètres (`settings`)
- Support section: "Support" (`support.choice` modal), "Voir le tutoriel" (`SidebarCommon.php:126-167`)

**Staff navbar** (`NavbarManager.php:10-79`):
- "+" quick-add menu: Nouvelle tâche, Ajouter un contact, Ajouter un fournisseur, Ajouter un locataire (only on unit page), Initier une vente, Créer une facture fournisseur
- Tâches dropdown (`tasks-kanban`), messages (`inbox`), discussions (`discussions`), help "?" button (`knowledge.articles` drawer), avatar menu (Profile, Outlook `new-inbox`, Gmail `gmail-inbox`, Réglages de l'équipe `teams.show`, Gérer des équipes `teams-unions-manage`, team switcher, language switch FR/EN)
- Not in the sidebar: Tasks, Inbox, Discussions and the dashboard live in the navbar, dashboard and "+" menu.

**Owner/tenant ("Copropriétaire ou locataire")** (`SidebarContact.php:26-77`):
- Union link or `union.view`; "Votre unité X" → `unit.view`; "Vos unités" (collapsed list when several)
- "Débits bancaires" (`banks-units.view`, owners of a union with `ppa` only)
- Agenda (Calendrier, Réunions if gestion, Visites), Maintenance submenu (Fournisseurs only for non-board: board-gated items are hidden, `SidebarCommon.php:216-232`), Contacts (`contacts.table`)
- Support: the yellow "Contacter votre gestionnaire/syndicat" button → `manager-contact.form`; "Wizard" → `owner-review` (marked TEMP in code, `SidebarCommon.php:135-138`); Support; tutorial
- Navbar: `NavbarContact.php`: discussions only for board; help; profile; team switcher.
- Reports menu hidden for plain contacts (`SidebarContact.php:81-88`).

**Board member** = contact sidebar PLUS: Unités catalog, Unités types and Modèles (if maintenance), Soumissions/Projets/Entretiens/Fonds de prévoyance, Payables (`SidebarContact.php:67,70`), Rapports (the 4 `reportsBoardMembers`: owners/unit/emergency registries, quote-part), discussions icon, a Finances tab on the union (if `finance_visible`), Tasks card on the union page.

**Supplier**: `SidebarSupplier.php`: links `users`, `union`, `banking` do not match any named route (hardcoded paths). Supplier home is `dashboard` which sits under `Role::team()`, so a supplier presumably gets 403. **Open question**: is the supplier portal live? If not, skip the audience.

**Super-admin**: `SidebarAdmin.php` (impersonation, logs, mail debug, subscriptions, AI conversations admin). Exclude from the KB.

### 1.5 Suggested audiences for CondoEdge KB (proposal, to validate)

Not a 1:1 copy of Coolecto's; derived from the above:
1. `coproprietaire`: owner or tenant (role `contact`, not board). Optionally split `locataire` (renter links 3/4), but they share all screens, with fewer rights.
2. `administrateur`: board member (`isBoardMember()`).
3. `gestionnaire`: staff (`lead`, `manager`, `assistant`, `accountant`, `concierge`), with a `{role}` note in the prompt (concierge is read-only, no finance).
4. `fournisseur`: only if the supplier portal is real.
The resolver is cheap, modelled on `HasRoles` (`isSupplier`/`isBoardMember`/`isContact`; do NOT call `getRole()` without a membership row: it `abort(403)`s, see `config/ai.php:15-18`'s own warning).

## 2. Functional areas → routes → komponents

All routes: `routes/web.php` (671 lines, ~300 route/helper lines). Layout `layouts.dashboard`, group `auth, verified, has-plan` (`web.php:181-185`). Komponents: `app/View/<Area>/`.

Auth gates written as `Role::x()` = role middleware; "board OK" means a contact can reach it, no role middleware.

| Area | Route names (path) | Komponent files | Who |
|---|---|---|---|
| **Auth / onboarding** | `login`, `register`, `register.new-team`, `team-invitations.accept`, `contact-invitations.register`, `supplier-invitations.register` (`web.php:35-54`) | `app/View/Auth/*` (8) | public/signed |
| **Dashboard (staff home)** | `dashboard` (`:272`), `onboarding`, `onboarding.contacts` | `View/Dashboard/DashboardView`, `OnboardingView`, `ActivityList`, `DayAgendaList`, `TaskStatsChart`, `MaryanneNotificationsList` | `Role::team()` |
| **Union (copropriété)** | `union.view` (`:204`), `union.manager` (`:302`, `Role::permanent`), `union.stats`, `team.stats`, `unions.catalog` (child unions), `buildings.form`, `child-unions.form`, `union-unit-add-view` (`Role::lead`) | `View/Condo/UnionView` (staff tabs Aperçu/Gouvernance/Immeuble/Documents/Notes `:79-83`; owner tabs Aperçu/Gouvernance/Immeuble/Documents/Finances/Échanges `:281-286`), `UnionManager` (wizard tabs: infos de base, contacts, immeubles, infos quantitatives, ajouter unités/fractions `:30-90`), `UnionBuilding*`, `UnionChildUnion*`, `UnionStats`, `TeamStats` | all / permanent / lead |
| **Units registry** | `units.catalog` (`:198`), `units.table`, `unit.view` (`:200`), `unit.sale.form`, `registries.form`, `vehicles.form`, `accesses.form`, `extras.form`, `extras-quote.form` | `UnitsCatalog`, `UnitsTable`, `UnitView` (staff tabs Aperçu/Finances/Occupants/Registre/Documents `:79-83`; owner tabs Aperçu/Finances/Mon unité/Mon registre/Documents/Échanges `:367-372`), `UnitSaleForm`, `UnitRegistries*`, `Vehicle*`, `Pet*`, `Access*`, `Extra*`, `UnitOwnerReviewModal` (yearly "dossier review" wizard, `owner-review` `:608`) | staff + contacts (own units) |
| **Owners/tenants/contacts** | `contacts.catalog`, `contacts.table` (`:245-246`), `contacts.form`, `contact-unit.form`, `contact-unit-new.form`, `contact-directories`, `addresses`, `board-members.form` (`Role::team`), `reviews.*` | `View/Contact/*` (37): `ContactsCatalog/Table/Form`, `ContactUnitForm`, `BoardMembersForm/List`, `ContactInvitationForm`, `ContactMultipleInvitationForm`, `OnboardingContact*` | staff; contacts see the directory (SidebarContact) |
| **Finance: receivables** | `invoices.table` (`receivables/{budget_id?}` `:313`), `invoices.form`, `invoices-credit.form`, `invoices.stage`, `payment-entry.form`, `payment-entries.form` (`:630-631`) | `View/Finance/Invoice*` (`InvoicesTable`, `InvoiceForm`, `InvoiceCreditForm`, `InvoiceStageForm`), `Payment*Form`, `PaymentReceivablesApplyForm`, `PaymentPrepay*` | `permanent`+has-finance |
| **Finance: payables** | `bills.table` (`payables` `:250`, has-finance only, so the board reaches it), `bills.form`, `bills.stage`, `bills-recurring.table/form`, `bills-credit.form`, `bill-from-file.form`, `bill-from-services.form` | `BillsTable`, `BillForm`, `BillStageForm`, `BillsRecurring*`, `BillCreditForm`, `BillsApprovalTable`, `BillApprovalNoteForm`, `UnionApproval*` | permanent (+ board table) |
| **Finance: budgets/funds** | `budget` (`:320`), `budget.view`, `budget-estimate.view`, `budget-preview.view`, `funds.form`, `contribution.*` forms (`:621-628`), `print.contribution*` | `BudgetsTable`, `BudgetView`, `Budget*` (~25: details, contributions estimate/preview/adjustments, approval/denial), `Fund*`, `Contribution*` | permanent |
| **Finance: accounting/GL** | `chart-of-accounts`, `account.form`, `account-balance.form`, `transactions` (`all-transactions`), `transactions.form`, `finance.setup`, `initial-balances`, `banking.settings`, `approvals.settings`, `taxes.form`, `tax-account.form` | `ChartOfAccounts*`, `Account*`, `Transaction*`, `FinanceInitialSetupView`, `FinanceSettingsView`, `EndOfYear*`, `FiscalYearForm`, `Tax*`, `Bank*`, `InterfundTransferModal`, `LateInterestModal`, `ResetFinancialDataForm` | permanent |
| **Finance: banking/PPA/EFT** | `banks-units.view`, `bank-units.form`, `bank-unit-single.form`, `banks.form`, `conciliations.table`, `conciliation.page`, `eft-files.table`, `eft-payable.table`, `eft-file.download` | `BanksUnitsView`, `BankUnit*`, `BankReconci*`, `AutoPayments*Form`, `BncUnionPpaForm`, `OtonomUnionPpaKeyForm`, `app/Kompo/Eft/*`, `app/Ppa/Bnc.php`, `Otonom.php` | owners (own pre-authorized debit), permanent |
| **Finance: reports** | `report.*` (`:259-262`, `:400-433`): `financial-statements`, `financial-statements-monthly`, `finstat-assessment-notice`, `bills-per-supplier`, `supplier-statement`, `invoices-receivables`, `trial-balance`, `account-transactions`, `gl-account-lines`, `account-conciliations`, `profit-loss`, `balance-sheet`, `cash-flow`, `sales-tax`, `senior-tax-credit`, `contributions-excel`, `unit-statement` (contacts can use with their unit), `accounting-charter`, `assessment-notice` | `View/Finance/Reports/*` (45 files), `View/Reports/*` (16) | permanent; `unit-statement` also contacts |
| **Registries (reports)** | `report.owners-registry`, `report.unit-registry`, `report.emergency-registry`, `report.quote-part` (`:259-262`, no role middleware: board + staff) | `View/Reports/OwnersRegistryReport` (+Excel export builder), `UnitRegistryReport`, `EmergencyRegistryReport`, `QuotePartReport` | board + staff |
| **Meetings / assemblies** | `meetings` (`:238`), `meetings.page` (`meeting/{id}/{stage?}`, stages `overview, agenda, motions, votes, during`, `MeetingPage.php:23`), `meetings.form`, `schedules.form`, `motions.form`, `meeting-proxy.form`, `meeting-notice.template`, signed: `meeting.confirm-presence/absence/proxy` (`:38-40`), printouts `report.meeting-agenda/motions/print` (`:445-447`) | `View/Meetings/*` (31): `MeetingsQuery`, `MeetingPage`, `Meeting*Page`, `MeetingForm`, `ScheduleForm`, `MotionForm`, `MeetingProxyForm`, `Motion*Votes*`, `MeetingAttendancePercent`, `MeetingConfirm*Page` | has-gestion; list/page open to residents, forms `Role::team` |
| **Announcements** | `announcements.form` (`:531`, modal; no role middleware, gated by policy `announcement:create`, manager/lead only) | `View/Condo/AnnouncementForm` (residents get the read-only view and a "Next" unread loop), `AnnouncementsCard` on the union Overview tab (`UnionView.php:338`) | create: manager/lead; read: all (gestion module) |
| **Reservations / amenities** | `reservations.form` (`make-reservation` `:206`), `resources.form`, `reservation-times.form` (`:609`) | `View/Condo/ReservationForm`, `ReservationTimeForm`, `ResourceForm`, `ResourcesListCard` (shown when `reservable_items`), `ReservationPolicy` | residents create; staff approve (`reservation:manage`) |
| **Calendar / visits** | `calendar`, `visits`, `visits.form`, `events.form` (`:194,242,539-540`) | `View/Calendar/*` (10): `CalendarMainView`, `Visit*`, `EventForm` | all; forms `Role::team` |
| **Tasks** | `tasks-kanban`, `tasks-manager` (`:280-281`, has-gestion, `Role::team`), `tasks.form` / `unit.tasks.form` / `union.tasks.form` (`unitUnionObjectRoutes`, `routes.php:17-27`); residents (board) see a Tasks card on union/unit pages | `View/Task/*` (9): `TasksKanban`, `TasksManager`, `TaskForm`, `TaskDetailForm`, `TasksCard` | staff; board read via card |
| **Files / documents** | `files-manager` (`:275`, `Role::team`), `files-manager-new`, `file-info.form`, `file-upload.modal`, `files.display/download`, preview routes (`:516-641`); owners see a Documents tab (`FilesListCard`) per union and unit | `View/File/*` (24): `FilesMainView`, `FileManagerNew*`, `FileInfo*`, `FilesListCard`, `ImagePreview`, `PdfPreview`; `AdminFolderManagement*` (super-admin) | staff full; residents via tabs |
| **Inbox / messaging** | `inbox` (`:279`), `new.thread`, `thread-groups`, `message-reply/forward/draft`, `inbox.message`, `threads.form` (+unit/union variants), `new-inbox` (Outlook), `gmail-inbox`, `message-templates`, `message-signatures`, `manager-contact.form` (resident to manager) | `View/Messaging/*` (28), `app/Kompo/MsGraph/*` (10), `GoogleApi/*` (3), `ThreadsCard` (the "Échanges" tab) | staff inbox; residents: `manager-contact` + Échanges tab |
| **Discussions (board forum)** | `discussions`, `discussions.archive/trash`, `channel`, `channel-subjects`, `channel-settings`, `discussion-reply`, `project-discussion` (`:195, 587-591`) | `View/Discussions/*` (11) | staff and board (navbar icon) |
| **Suppliers / projects / quotes / maintenance** | `suppliers.table` (`:210`, has-maintenance-or-finance), `suppliers.page`, `suppliers.form`, `supplier.invitation`, `projects.view/page`, `quote-requests.management`, `maintenances.view/page/calendar`, `components.view`, `reserve-fund.dashboard` (`:208-240`) | `View/Supplier/*` (72) | staff; board for projects/quotes/maintenance |
| **Settings** | `settings` (`:268`, a Blade view: `resources/views/settings/show.blade.php` includes subscription, mailbox, linked emails, message forwards, reviewers, manager questions, team tags, templates, signatures, reactivate unions); `teams.show` (Réglages de l'équipe), `teams-unions-manage`, `settings.templates`, `settings.signatures`, `profile.show`, `plans.pick` (`team-owner`) | `View/Settings/*` (25), `app/Kompo/Teams/*` (13), `app/Kompo/Billing/*` (17); `resources/views/teams/*`, `profile/*` | staff (`Role::team`); profile for all |
| **Support / help / tutorial** | `support.choice`, `support.form`, `tutorial.view.*`, `docs` ("coming soon" placeholder), `help` (placeholder), `knowledge.articles` (CMS drawer, section 3) | `View/Messaging/Support*`, `View/Home/*` | all |
| **Super-admin only (exclude)** | `admin-*`, `union.import*`, `mail-debug`, `mobile-debug`, `admin.transfer-union`, `ai.admin.conversations` (`:341-381`) | `View/Admin/*` (30), `View/Imports/*` (3) | super-admin |

Estimated menu → komponent facts for a few key owner screens: `SidebarCommon.php:103-124` (units), `:210-233` (maintenance), `:240-248` (agenda).

### 2.1 UI language and translation files

- App locale `fr`, fallback `fr` (`config/app.php:83,96`). English exists; navbar `_Locales()` toggle (`NavbarCommon.php:178-181`). Code and keys are English-first, French labels are what users see. The KB should be written in French (Québec) and quote the FR labels; since an English UI also exists, consider `corpus_languages => ['fr','en']` (the host already sets this for the law corpus, `config/ai.php:~647`).
- Translation files: `resources/lang/fr/*.php` and `resources/lang/en/*.php` (menus.php 89 lines, condo.php 639, finance.php 853, meetings.php 126, messaging.php 136, settings.php 108, supplier.php 321, file.php 130, task.php 42, roles.php 22, onboarding.php 244, etc.), plus `resources/lang/fr.json` (598 lines) and `en.json` for literal strings, plus `*.babel` source files (`resources/lang/CondoEdge.babel`, `CondoEdge2026.babel`, `babel.babel`, `babel-dossier.babel`).
- **Trap for labels**: the FR label often differs from the key name, e.g. `menus.payables-per-vendors` = "Balance de vérif.", `menus.services` = "Maintenance", `menus.invoices-receivables` = "Âge de compte", `menus.receivables` = "Recevables", `menus.payments-menu` = "Débits bancaires", `menus.quote-part` = "Fractions", `menus.reserve-fund-dashboard` = "Fonds de prévoyance" (`resources/lang/fr/menus.php`). Authors must read the `fr` file rather than guess from the key; some labels are raw English literals in the code ("Clients", "Wizard", "Documentation", `SidebarSupplier.php`).
- Existing vocabulary to reuse: `config/ai.php:~270-300` `domain.aliases` (union = copropriété/syndicat; unit = unité/fraction/lot; contact = propriétaire/locataire; invoice = facture/cotisation; meeting = réunion/assemblée; fund = fonds de prévoyance...).
- Persona already called "Maryanne" (`config/ai.php` `identity.name` = 'Maryanne Assistant', `:406-408`; lang `maryanne.php`; onboarding bubbles `OnboardingView::maryanneBubble`).

## 3. CondoEdge's current AI setup (what is ingested today)

### 3.1 `config/ai.php` (1697 lines)

- Thin override over `vendor/condoedge/ai/config/ai.php` (deep merge; header comment `:182-199`).
- **`file_context`** (`:631-668`):
  - `physical_paths` = `['public/laws/**/*.md', 'docs/ai-help/**/*.md']` (`:634-642`); ingested via `ai:ingest --docs`
  - `corpus_languages` = `['fr','en']` (`:647`)
  - `scope.current = ['union' => 'current_union_id']` (`:652-654`): uploaded files are linked to the record they are filed on and searched within the current union
  - `corpus_register` (`:660-667`): CCQ vocabulary
  - NOT set (package defaults apply): `physical_collection` (= `documentation_chunks`, package `config/ai.php:1290`), `frontmatter.keys` (= `[]`, so default English keys `title`, `audience`, `pages`, `breadcrumb`, `keywords`, `related`), `supported_extensions` (`md, mdx, txt, rst`).
- **Collections**: no host override. Physical docs go to Qdrant `documentation_chunks` (package default); user-uploaded DB files go to the package's `file_chunks`. The `File` entity is exposed in the entity graph (`config/ai-entities/condo.php`, many `target_label => 'File'`; `filemanager.php`). **The user's uploaded by-laws/declarations ARE ingested as per-record file chunks** (comment `config/ai.php:~641-655`: "declarations are French"). The corpus in the physical collection is separate.
- **`audiences`**: NOT configured, so no resolver, no filtering; everyone reads every physical doc (`config/ai.php` has no `audiences` key; package default `:123-126`). **`navigation.pages`**: not configured; instead `generic_actions` (`:1150-1469`) and `entity_actions` (`:827`) with authorize closures from `$staffOnly` / `$permanentOnly` / `$boardOrStaff` (`:27-76`) mirror the role middleware. The route names in `generic_actions` comments cite stale web.php line numbers, and `suppliers.catalog` is referenced but only exists as `switchToRouteName` in `SuppliersTable.php:7`, so verify names with `php artisan route:list` before putting them in `pages:`.
- **Other**: `project.description` (`:210`), `guardrails.domain_scope` (`:388-396`), `identity.persona` (`:406-434`), `chat.example_questions` (`:~700-712`), `page_examples` (`:~728-780`, all commented), `user_entity_paths` (`:484-...`), `attention_sources`, `export`, `health`. The assistant is a DATA+docs assistant (Neo4j graph and Qdrant), unlike Coolecto's docs-only "Coco".
- Chat surfaces (`AiChatFloating`): `UnionView.php:293` (owner view), `UnitView`, `DashboardView.php:52`, `OnboardingView`, `BudgetView`, `InvoiceForm`, `MeetingPage`, `MaintenancePage`, `ProjectPage`, `SupplierPage`. So residents already reach the chat from their union page.
- Law corpus: `public/laws/CCQ_Co-ownership_EN.md` (56 KB, English CCQ text). Do not duplicate.

### 3.2 Existing docs in the repo

- `docs/ai-help/*.md` (6 files, 119 lines total): `contacts.md` (19), `daily-tools.md` (23), `finances.md` (20), `meetings.md` (14), `team-and-members.md` (23), `units-and-unions.md` (20). They are DRAFT, English, no frontmatter, 1 file per topic, grounded in route names (`docs/ai-help/team-and-members.md:3`: "DRAFT (2026-07-23) ... Owner review pending"). They ARE ingested today through `physical_paths`. Their wording is staff-oriented and refers to routes and component names (e.g. "component: TeamInvitationForm"), which a KB should not do. **Collision risk**: if the new KB goes elsewhere (e.g. `resources/ai-help/aide/**`) both are retrieved; plan to replace or delete `docs/ai-help` and update `physical_paths`.
- Other `docs/`: `docs/plans/*` (AI design plans), `docs/entity-quality-checklist.md`, `docs/subscription-source-of-truth-migration.md`: engineering, not ingested.
- **In-app DB knowledge base ("wiki")**: the navbar help "?" (`NavbarCommon.php:129-132`) opens `knowledge.articles` in a drawer, served by the CMS/page-editor package (`routes/web.php:3-4,183`, `vendor/condoedge/cms/src/Services/KnowledgeService.php`; articles associated to route names via `PageModel::knowledgeAssociatedToRoute`, `is_visible`). Editor `knowledge.editor` and raw list `knowledge.list` are super-admin (`web.php:343-344`, `SidebarAdmin.php:161`). Content is in the DB (not visible from the repo). **Open question**: how many such articles exist and are they worth migrating? The AI does not read them (not in `physical_paths`). The `pages` frontmatter would map one-to-one to this route association.
- `docs` (`DocumentationView`) and `help` (`HelpView`) routes are placeholders "coming soon" (`app/View/Home/*.php`); the sidebar link is commented out (`SidebarCommon.php:142`).
- **Public marketing content (not user help)**: 32 blog Blade articles `resources/views/articles/*.blade.php`, metadata `config/articles.php` (30 slugs), 4 pillar guides `config/pillars.php`, 6 tools `config/outils.php`, downloadable checklists (`web.php:117-155`); lang `marketing*.php`. Public SEO pages, on a different audience; do not ingest, but they are useful for tone and legal vocabulary (carnet d'entretien, fonds de prévoyance...).
- In-app tutorials: `tutorial.view.owner-union`, `owner-unit`, `user-dashboard` (`routes/web.php:599-601`, `UserSettingsController`), intro.js (`resources/sass/_Introjs.scss`); onboarding strings in `resources/lang/fr/onboarding.php` (244 lines): useful source for wording.
- `README.md` and `CLAUDE.md` (project overview). A parallel report `ERP_Finance_Testing_Report.md` exists at the root (finance testing).

## 4. Size estimate

Counting rule: one article per task/screen, as Coolecto (its komponent count was not measured here). Komponent-file counts by area (CondoEdge): Finance 169 (+45 report files `View/Finance/Reports`), Supplier/maintenance 72, Condo 66, Contact 37, Meetings 31, Admin 30 (exclude), Messaging 28, Settings 25, File 24, Reports 16, Billing 17, MsGraph/Google 13, Teams 13, Discussions 11, Calendar 10, Task 9, Auth 8, Dashboard 6 (~600 total with Admin).

Rough estimate per proposed audience (needs owner validation):

| Audience | Scope | Est. articles |
|---|---|---|
| Copropriétaire / locataire | invitation and signup, union page (6 tabs), unit page (6 tabs), registry/dossier review wizard, vehicles/pets/accesses, reservations, announcements, calendar, meetings (confirm presence/absence/proxy, votes), documents, "contact your manager", Échanges, statement and fees (if finance visible), pre-authorized debit ("Débits bancaires"), profile/2FA/password/locale, directory | 50-70 |
| Administrateur (board) | everything above plus: units catalog, owners/unit/emergency registries and Fractions reports, Finances tab, payables, projects/quotes/maintenance/reserve fund, tasks card, discussions, board and meeting oversight | +30-45 |
| Gestionnaire / staff | team and members (invite, roles), union setup wizard, units/contacts/invitations/sale, finance (setup, GL, invoices, payments, budgets and contributions, funds, taxes, bills and approvals, recurring, EFT/PPA, reconciliation, year-end, ~17 reports), meetings (create to minutes), announcements, tasks, inbox (Outlook/Gmail, templates, signatures), files/folders, suppliers/projects/quotes/maintenance/components/reserve fund, settings (tags, templates, reviewers, manager questions, reactivate unions), subscription, support | 250-350 |
| Fournisseur | only if live; sidebar is hardcoded and likely broken (1.4) | 0-15 |

Total about 350-470 articles. Comparable to Coolecto's 458, but about 70 percent staff-side with the highest risk (finance accuracy). Suggest phasing: residents + board first (about 100, lowest risk, highest volume of questions), then staff by module (gestion, finance, maintenance), checking module flags in each article.

Module conditionality: each staff article needs a precondition line ("si le module Finance est actif" / finance setup done) because menus disappear otherwise (`SidebarCommon.php:250-258`).

## 5. Sensitive content: never put in articles

- **PII / real data**: no real names, emails, phones, addresses, unit numbers tied to people, owner lists, emergency contacts (`EmergencyRegistryReport`, `ContactDirectory` with emergency flag `ContactType::TYPE_EMERGENCY`), vehicle plates, pet info, door/intercom/access codes and keys (`AccessForm`, `IntercomCard`, `AccessesUnitList`, `Access.php`, `AccessType.php`), insurance policy numbers (`insurer_name_un`, `insurance_number_un`, `UnionView.php:415-420`). Use obviously fake examples.
- **Banking/payment secrets**: bank institution/transit/account number (`Bank.php:36,44-60`, `BankForm`, `BankUnitsForm`), PPA/EFT provider keys and merchant ids (`OtonomUnionPpaKeyForm`, `BncUnionPpaForm`, `app/Ppa/Bnc.php`, `Otonom.php`, `app/Kompo/Eft/*`, `Condoedge\Eft`), EFT file formats and download route internals (`eft-file.download`), Stripe/Cashier subscription internals (`Team subscription` billing models `app/Models/Billing/*`, `TeamSubscription`), Microsoft Graph / Google OAuth tokens (`MicrosoftSsoController`, `change-outlook-token`, `reset-outlook-token`). Describe how a user enters their own data, never values or key names beyond the UI label.
- **Finance internals**: chart-of-accounts codes beyond UI labels, `finance_setup`/ledger tables, double-entry mechanics, reset tool (`ResetFinancialDataForm`), data-fix routes (`web.php:661-669`), the `invoices.team_id` NULL quirk and other data-reality notes in `config/ai.php:240-243`. State what the screen does, not the schema.
- **Security / admin**: super-admin and impersonation (`Route::impersonate()`, `SidebarAdmin`), `log-viewer`, `mail-debug`, `mobile-debug`, `phpinfo`, `admin-*`, `union.import*`, `admin.transfer-union`, `files.cleaning`, Telescope, the AI admin panel (`ai.admin.conversations`), role-middleware design, signed-URL mechanics (`team-invitations.accept`, `meeting.confirm-*`). Do not document how to bypass `access_level` / `finance_visible` or the "accesses revoked" logic (`HasRoles.php:43-46`).
- **Role-leak risk**: audiences must not see each other's content. Staff-only screens (finance, inbox, files manager, settings) must carry `public: gestionnaire` so a resident's question cannot retrieve them. Without `ai.audiences` configured today, filtering does not exist, so wiring a resolver is a prerequisite (`vendor/condoedge/ai` `config/ai.php:113-130`: filtering is "enforced by the document search"). Also tenants vs owners: tenants must never read owner-only finance/PPA articles (`isOwnerOf`, `SidebarContact.php:19`).
- **Legal advice**: articles describe the software, not law interpretation; the CCQ text is already in `public/laws`; avoid contradicting it and avoid legal conclusions ("la loi vous oblige à...").
- **Draft/unvalidated features**: the "Wizard" link is marked TEMP (`SidebarCommon.php:135`), `report.yealink-phone`/`call-log` are commented out, `docs`/`help` are placeholders, `ppa_testing` route is marked REMOVE; do not document.

## 6. Open questions

1. Is the supplier role used in production? Is there a working supplier portal (sidebar paths `users`, `union`, `banking` have no named routes; home route is `dashboard` under `Role::team()`).
2. Self-managed (`TEAM_AUTOGEREE`) syndicates: which roles do board members get? Is "administrateur" always a `contact` + `board_members` row, or do some also have `lead`/`manager` roles? Affects the audience mapping for the "syndicat autogéré" case.
3. Should the tenant (locataire) be a separate audience from the owner? Same screens, less access (no PPA, no finance, no owner-review wizard); the flag is `contact_unit.link_id`, so the resolver can tell.
4. Replace or keep `docs/ai-help/*.md` (6 EN draft files, ingested now)? New KB location and `physical_paths`, `physical_collection`, `frontmatter.keys`, `corpus_languages` (FR-only like Coolecto, or FR+EN since the UI is bilingual).
5. Contents of the DB wiki articles behind the help "?" drawer: reuse as sources or ignore.
6. The AI assistant here also queries data (Neo4j); should KB articles be reachable from every chat surface, and does the owner want the Coolecto-style `ai.navigation.pages` buttons (needs verified route names)?
7. Which modules/plans does a typical client have (gestion/finance/maintenance flags)? Do we write one article variant or conditional notes?
8. Language of the CCQ corpus (English only today) vs a French KB: retrieval is cross-lingual per the `corpus_languages` setting, but check eval.
9. Pending changes: `config/ai.php` has uncommitted modifications on `dev`, so coordinate before editing the same file.
