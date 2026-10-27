# Coolecto data access: who may see which rows

**What this is.** An access matrix for a data assistant that answers Coolecto questions. It mirrors the app's own rules, and every rule cites the file and line it comes from.
- **Companion:** `questions.md`, whose "Who" column points here.
- **Paths** are relative to `C:\Users\jkend\Documents\Projects\kompo\ActiviteDeFinancement`.
- **Sources:** everything comes from reading the code on 2026-10-26, plus aggregate-only counts on the local `financement` DB. *(inferred)* marks conclusions that were not traced end to end.

**Two caveats up front.**
1. **The app has very few policies.** Of the `app/Policies` classes, only `OrderPolicy::manage/refund/dispute` (`OrderPolicy.php:63-76`), the `delete` methods and `TeamPolicy` say anything. `CampaignPolicy`, `RecipientPolicy` and `GroupPolicy` `view*` are empty stubs, and only `Team` is registered in `AuthServiceProvider.php:15-17`. Row visibility is enforced instead by **which campaign/team a screen queries** (`currentCampaignId()`, `currentTeamId()`, `currentTeamCampaigns()`), and role visibility mostly by **the menu**. Several screens are reachable by URL by roles the menu hides them from (§6).
2. **Mirroring the app literally would also mirror its holes.** For each cell, this document gives what the app *does* and, where they differ, what it *intends* (menu plus help articles). **The assistant should follow the intended rule, the stricter of the two.**

---

## 1. Who is asking (resolution order)

The resolution order mirrors the sidebar's own precedence (`app/Kompo/Menus/DashboardSidebar.php:137-236`):

| Order | Condition | Audience / role | Evidence |
|---|---|---|---|
| 1 | not logged in; signed `participant.dashboard` / `group.dashboard` URL | **participant / group (no account)**, scoped to the signed id | `routes/web.php:78-80`; `ValidateSignature` |
| 1b | not logged in, otherwise | **guest buyer**: no data | 200,318 of 201,742 orders have `user_id` NULL (local DB) |
| 2 | `isScanner()` (`current_role='scanner'`) | **staff-scanner** | `HasTeamsTrait.php:78-83`; sidebar `:137-143`; `DashboardView.php:84` |
| 3 | no `current_team_id` | nothing | `DashboardSidebar.php:145-150` |
| 4 | `isInParticipantMode()` (mode 3 + a recipient seat or a managed group) | **participant / captain** | `User.php:559-569`; sidebar `:152-169` |
| 5 | `isInCustomerMode()` (mode 2 + linked customers) | **buyer (logged in)** | `User.php:532-541`; sidebar `:171-190` |
| 6 | `currentSupplierId()` (mode 4 + the current team has a `suppliers` row) | **supplier**, with a sub-role from `current_role` | `utilities.php:599-608`; `User.php:543-548`; sidebar `:192-236` |
| 7 | otherwise | **organiser**, with a sub-role from `users.current_role` | `HasTeamsTrait.php:55-90` |

**Organiser sub-roles.** The UI names come from *compte-membres-et-roles*.

| `current_role` | UI name | Predicates | DB count (`team_user`) |
|---|---|---|---|
| owner | Administrateur en chef | `isManager`, `isEditor`, `canSeeEmails` | 4,072 |
| manager | Administrateur | `isManager`, `isEditor`, `canSeeEmails` | 302 |
| editor | Membre éditeur | `isEditor`, `canSeeEmails` | 42 |
| basic | Membre | `isMember` | 32 |
| scanner | Scanneur de billet | `isScanner` | 10 |
| agent | Représentant | `isAgent`; acts inside a child team. 44 of 46 rows have a `from_team_id` | 46 |
| admin / e-mail list | super-admin | `isAdmin()`; `Gate::before` grants everything | `HasTeamsTrait.php:42-59`; `AuthServiceProvider.php:26-33` |

- Predicates: `HasTeamsTrait.php:61-90`, `TeamRole.php:84-99`, `User.php:354-357`.
- **`isAdmin()` and the `role:admin` middleware disagree.** `isAdmin()` is a hard-coded e-mail list or `current_role=='admin'`, while `role:admin` requires `current_team_id === 1` (`RoleMiddleware.php:20-25`). The two sets differ, so admin access should stay out of the assistant's data scope.
- **Mode vs role are independent.** `ProfileMiddleware` checks only `activated_mode_id`, and a null mode passes as team (`ProfileMiddleware.php:24-32`). The `role:owner|manager` middleware checks only `current_role` (`RoleMiddleware.php:34-40`).
- **One account, several profiles.** A user can hold several teams plus a donor profile plus a participant profile (`User.php:576-601`), but only the active one counts. The assistant must resolve the audience **per request** from the same fields, never cache it per user.

---

## 2. Scope primitives (the row rules the app uses)

| Id | Rule | Definition | Evidence |
|---|---|---|---|
| T | **Team** | rows whose campaign has `campaigns.team_id = users.current_team_id` | `utilities.php:553-559`; `Order::scopeByTeam` (`Order.php:1463-1466`) |
| TR | **Team, restricted campaigns** | T, narrowed to `users.restricted_campaigns[team_id]` plus the campaigns the user created, when that list is non-empty | `currentTeamCampaigns()` (`utilities.php:673-686`); `HasRestrictedCampaignsTrait.php:14-30,65-68` |
| C | **Current campaign** | `users.current_campaign_id`. It is re-forced into TR only when the `currentCampaign` relation is read (`User.php:127-144`). `switch.campaign` checks TR (`User.php:419-431`; `HomeController.php:12-19`) | `utilities.php:581-586` |
| RC | **Related campaigns** | C plus its sub-campaigns (`main_campaigns` pivot) and its form/survey campaigns (`survey_campaign_id`), recursively. **There is no team filter** | `Services/Campaigns/CampaignCteRetriever.php:62-114`; used by `forCampaignAndRelated` (`Models/Traits/BelongsToCampaignTrait.php:43-54`) and `CampaignStatsService::getTotalSales` (`:157-173`) |
| NET | **Network** | the current team plus every descendant via `teams.parent_team_id`. Some screens use direct children only (`Team::getReportTeamIds`, `Team.php:638-641`; `AccountStatementTable.php:38-43`) | `Team::getAllChildrenRawSolution` / `descendantTeamIds` (`Team.php:1185-1212`) |
| AG | **Agent** | a user switched into a descendant team gets a `team_user` row `role='agent'`, `from_team_id = <parent>`. They then act with team scope T in that child. The agent-specific campaign filter is **commented out** | `HasTeamsTrait.php:133-155,172-178`; `utilities.php:684`; `Campaign::scopeLimitForCurrentAgent` (`Campaign.php:2295-2302`, unused) |
| SUP | **Supplier** | rows in **other teams'** campaigns reached through `products → deliverables.supplier_id = <my supplier>` (production uses `products.supplier_id` instead) | `Market/SupplierClientsQuery.php:314-321`; `Campaign::getRelatedTeamIds` (`Campaign.php:822-827`); `Services/Supplier/ProductionMatrixService.php:192-221` |
| LC | **Linked customers** | `customers` rows of **any team** whose `LOWER(email)` equals the user's e-mail **or** whose `phone` equals `users.phone_number` | `Models/Traits/HasLinkedCustomers.php:12-30`; `currentCustomersIds()` (`utilities.php:640-645`) |
| OWN-R | **Own participant seat** | `recipients.user_id = user` for the campaign (`User::getRecipientForCampaign`, `User.php:685-688`), or the recipient id in a signed URL | `P2PParticipantDashboard.php:56-62` |
| OWN-G | **Own group** | the group whose leader's e-mail equals the user's (`Group::scopeManagedBy`, `Group.php:339-342`), the captain's seat group, or the group id in a signed URL | `P2PGroupDashboard.php:42-48` |
| SIG | **Signed link** | possession of the URL is the credential (`ValidateSignature`): `participant.dashboard`, `group.dashboard`, `public.receipt`, `guest.scan`, `transfer.preview` | `routes.tsv` |

**Local DB facts behind these rules** (aggregates only):
- 139 users have a `restricted_campaigns` value, and about 42 have a non-empty list for some team: 12 managers, 9 owners, 12 editors, 9 basic.
- 20 parent teams; 357 teams have a parent.
- 11 agent-type teams (`account_type=3`); 66 supplier teams.
- `main_campaigns` has 187 pairs; **2 cross teams**. Survey sub-campaigns: 570, **3 cross teams**.
- `campaigns.owner_team_id` is set on 2,424 campaigns and differs from `team_id` on 76.
- Supplier products appear in **1,182 campaigns** of other teams.
- `transfers`:
  - 781 have `destination_team_id` = the **parent** of the campaign's team (deposits centralised at the network);
  - 613 have `team_id` = a **supplier** team on a client campaign.
- `tax_receipts.team_id` always matches the order's campaign team (0 of 7,846 differ).
- `customers.team_id` differs from the order's campaign team on 23 of 185,668 orders.
- `groups.team_id` differs from its campaign's team on 28 of 13,409.
- `recipients.team_id` differs on 322 rows.

---

## 3. Role capabilities (what each role is shown)

| Capability | owner | manager | editor | basic | scanner | agent (in child) | Evidence |
|---|---|---|---|---|---|---|---|
| Campaign menu: dashboard, products, communications, reports, QR, promote | ✓ | ✓ | ✓ | ✓ | ✗ (menu hidden; URLs open) | ✓ | `DashboardSidebar.php:137-143,318-349` |
| Create a campaign | ✓ | ✓ | ✓ | ✗ | ✗ | ✓ | `:314-316` |
| Participants page | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | `:336`; `CampaignPageRecipients.php:17-19` (403) |
| Finances (transactions, statement, tax receipts, bank) | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | `:353-359`; routes `role:owner\|manager` (`routes/web.php:385`) except `campaign.transactions` |
| "Mon organisme" (contacts, members, recurring, settings) | ✓ | ✓ | ✗ | ✗ | ✗ | ✓ (section shown) | `:369-393`; `team.customers` etc. are `role:owner\|manager`, so the agent is refused by the routes |
| Network pages (overview, organisations, network campaigns, registrations, calendar, deposits, requests) | parent team | parent team | ✗ | ✗ | ✗ | ✓ | `:371-380`; `authorizeBoot` = `hasChilds() && (isManager \|\| isAgent)`: `ParentTeamOverviewPage.php:31-34`, `NetworkCampaignsPage.php:10-13`, `NetworkRegistrationsTable.php:25-28`, `TeamRequestsPage.php:15-17`, `NetworkCalendarPage.php:45-47`, `TeamChildOrganizationsTable.php:38-41` |
| Order actions (refund, resend, link) | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | `CampaignOrderPreview.php:41,112`; `OrderPolicy.php:63-76` |
| Unmasked e-mails (`canSeeEmails`) | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | `User.php:354-357`. Applied **only** through `display_email`/`emails_html` (`HasDisplayEmailAttribute.php:7-24`); most screens print raw `email` |
| Bank account | ✓ | if `let_managers_manage_bank_account` | ✗ | ✗ | ✗ | ✗ | `User.php:344-352` |
| Supplier "Se faire payer" (payouts, statistics, bank) | ✓ | ✓ | ✗ | ✗ | ✗ | — | `DashboardSidebar.php:229-234`; but `role:supplier` only checks `currentTeam->isSupplier()` (`RoleMiddleware.php:27-31`), so the routes are open to any role of a supplier team |

The help articles confirm the intent:
- *commandes-liste-des-transactions*, *rapports-releve-de-compte*: Finances is for chef/admin only.
- *compte-membres-et-roles*: basic is read-only; scanner can only scan QR codes.
- *billets-ajouter-un-scanneur-benevole*: a guest scanner sees no sales or organisation data.
- *banque-qui-peut-gerer-le-compte*: the bank account is the chief's, plus managers only when allowed.

---

## 4. Entity × audience matrix (rows)

**Cell legend** (scope ids from §2):
- `—` means nothing.
- "(menu)" means the app shows it in navigation.
- "(URL)" means it is reachable but not linked, i.e. a gap.
- **bold** marks the recommended rule for the assistant.

### 4.1 Organiser roles

| Entity (table) | owner / manager | editor | basic | scanner | agent (in a child) | parent-team manager (network view) |
|---|---|---|---|---|---|---|
| **Campaign** (`campaigns`) | **TR**. All screens list `currentTeamCampaigns()` (`AllCampaignsTable.php:20-23`, `utilities.php:689-694`) | **TR** | **TR** | **C only** (scan target; `QrCodeConfirmationModal.php:29-39`) | **T of the child** (agent filter commented out, `utilities.php:684`) | **NET**, approved campaigns, aggregate (`TeamChildCampaignsTable.php:20-33`; `ParentTeamOverviewPage.php:38-40`) |
| **Order** (`orders`; no `team_id`, scoped via `campaign_id`) | **TR via campaign**. Transactions use C (`CampaignTransactions.php:34,62`); team-wide screens use T (`Order::scopeByTeam`) | menu: none. App: C via `campaign.transactions` (URL, no role gate) and reports. **Aggregates + participant-level only** | **Aggregates only** (app: R4 lists buyers with e-mail and phone, URL) | **Counts for C** (ticket orders) | **Aggregates for the child** | **NET aggregates**: Σ `final_amount` per child/campaign (`ParentTeamOverviewPage.php:138-145`; `Order.php:1489`) |
| **OrderDetail** (`order_details`) | as Order | as Order | as Order | — | as Order | NET aggregates |
| **Product** (`products`; global scopes on variants) | **TR via campaign** | TR | TR | — | T child | NET via campaign |
| **Recipient = participant** (`recipients`; `team_id` + `campaign_id`; minors) | **TR via campaign** (`RecipientPolicy::delete` checks both `campaign.team_id` and `team_id`, `RecipientPolicy.php:71-74`) | **TR** (participants page, `isEditor`) | **names + sales via reports only**; no contact data | — | **names + sales**; no contact data (`canSeeEmails` false) | **counts only** (`TeamCampaignsTable.php:55-64`). App also lists names: N7 registrations (`NetworkRegistrationsTable.php:100-102`) |
| **Group** (`groups`) | **TR via campaign** (`GroupPolicy.php:71-74`) | TR | TR | — | T child | NET counts |
| **Customer = buyer/donor** (`customers`, `team_id`) | **T** (team-wide, **not** narrowed by restrictions: `Customer.php:901-927`; `CustomersTable.php:24-40`) | **none outside a campaign**. Buyer names per campaign appear in reports, but Contacts is manager-only | **none** (masked by intent) | — | **none** (`team.customers` is `role:owner\|manager`) | **none**, except `ChildTeamContactsTable` when the parent has `can_edit_child` (`ChildTeamContactsTable.php:15-23`) |
| **Donor** (`donors`, invitation e-mails per campaign) | **TR** | TR (unmasked) | masked | — | masked | — |
| **TaxReceipt** (`tax_receipts`, `team_id`) | **T** (`TaxReceiptsTable.php:34`; restrictions ignored). **Recommend TR** | **none** (Finances) | **none** (app: `print.tax-receipts` has `auth` only, `TaxReceiptsPrintableQuery.php:28-31`) | — | none | — |
| **Transfer = deposit** (`transfers`: `team_id`, `destination_team_id`, `campaign_id`) | **destination_team_id = T, or team_id = T on own campaigns, billable to the team** (`AccountStatementService.php:463-480`). Excludes the supplier transfers that share the campaign | none | none | — | none | **destination_team_id ∈ NET** (`NetworkDepositsPage.php:29,63-92`). App: no gate on that page |
| **BillItem / invoice** | **T** (`AccountStatementInvoicesTable`) | none | none | — | none | NET (`ChildTeamInvoicesTable`, `userHasPermissionsToTeam`) |
| **Reservation = ticket / draw number** (`reservations`, `campaign_id`) | **TR via campaign** | TR | TR (counts; names by URL) | **C, counts only** (intent); app: guest list O2 by URL | T child | — |
| **Scanner session** (`scanner_ticket_sessions`) | TR | TR | — | **own session** | — | — |
| **Pledge / PledgeAmount = bids and pledges** (`pledges`, `campaign_id`) | **TR via campaign** | TR | **lot-level aggregates** (app shows bidder names, `DashboardEncanView`) | — | T child | — |
| **Drawing / Prize / Draw / DrawLog** | **TR via campaign** | TR | **prizes and claim status**, winner names hidden | — | T child | — |
| **Membership** (membership-type `orders`) | **T** (`Customer::hasMembership`, `Customer.php:298-310`; manager route) | none | none | — | none | — |
| **Recurring gift** (orders with `recurrence>0`) | **T** (`Order::recurringDonations()->byTeam()`, `Order.php:1417-1424,1463`) | none | none | — | none | — |
| **Answer = form/survey answers** (`answers`; no tenant key; via order/customer/recipient) | **TR via the order's campaign**. **Aggregates per choice by default; raw free text only on explicit request** (health data) | aggregates | aggregates | — | aggregates | — |
| **Voucher** (`vouchers`, `campaign_id`) | **TR via campaign** | TR | TR (code + usage) | — | T child | — |
| **InstallmentPlan / OrderSchedule** | **TR via `landing_campaign_id`** (`InscriptionSchedulesQuery.php:29-36`) | TR | counts | — | T child | NET via N7 |
| **Envoi / EnvoiList = communications sent** | **TR via communication campaign** (`EnvoiList::forCampaign`, `:173`) | TR | **counts and rates** (per-recipient rows masked) | — | counts | — |
| **SmsSending** | **TR via campaign** (`SmsSending::forCampaign`) | TR | counts | — | counts | — |
| **Delivery** (supplier deliveries to the org) | **T** (`Delivery::forTeam`, `ToTeamOrderDeliveriesTable.php:19`) | T | T | — | T child | — |
| **Team** (`teams`) | own team | own | own | own | child | **NET** (names, status, collected, responsible) |
| **TeamRole / users** | own team members (`TeamMembersList`). **Names + roles; e-mails only for owner** (invite rights, `TeamPolicy.php:72-121`) | names | names | — | names | child members via `TeamChildUsersTable` |

### 4.2 Later audiences

| Entity | Buyer (customer mode) | Participant (seat / signed) | Captain (group) | Supplier |
|---|---|---|---|---|
| Campaign | campaigns of own orders (name only) | own seat's campaign (+ main/sub siblings, `ParticipantDashboard.php:43`) | own group's campaign | **campaigns carrying my deliverables** (SUP), name, dates and status |
| Order | **LC**, paid only (`CustomerOrdersTable.php:30-37`). **Recommend email-matched only, not phone** | orders linked to **own recipient**, with the donor shown as `public_donor_name` (respect `donor_be_anonymous` / `donor_amount_anonymous`) | orders of **own group** (`P2PGroupOrdersTable.php:50`), anonymity respected | **only lines of my products** (`forSupplier`), aggregated per campaign/date. No buyer identity except home-delivery orders (`SupplierOrdersDeliveredToDonor`) |
| OrderDetail | own | own recipient's | own group's | my products |
| Recipient | — | self | **own group members**: names, status, totals (`P2PCaptainMembers.php:126`) | participant names on picking slips by group (`SupplierOrdersGroups`). **Recommend counts only** |
| Group | — | own group: total, rank. Others' totals only if the leaderboard is public | own; other teams' totals/rank via the leaderboard | group names on slips |
| Customer | own (LC) | — | — | — |
| TaxReceipt | **LC** (`CustomerTaxReceiptsTable.php:26-32`) | — | — | — |
| Transfer | — | — | — | **`team_id` = my supplier team** (`SupplierTransfersPerDateTable.php:23-31`; `SupplierAccountStatementService.php:96-102,238-249`) |
| Reservation | own tickets (receipt hub) | — | — | — |
| Pledge | own bids | — | — | — |
| Leaderboard | public campaign page | names only if `showsLeaderboardNames()` (`RendersLeaderboardRows.php:49,97`) | same | — |
| Client teams | — | — | — | **SUP**: client team name, campaign state, my sales. App also exports client owners' and **all client staff's** contact data (`SupplierClientUsersSheet.php:45-53`): **recommend not** |

---

## 5. Columns: what each role sees (PII)

| Column(s) | owner/manager | editor | basic / agent / scanner | participant | supplier | Evidence and note |
|---|---|---|---|---|---|---|
| Buyer e-mail (`customers.email`, `orders.email`) | ✓ | ✓ | **masked** where the app uses `display_email`. **Raw** in R4 `OrdersByCustomers`, R12, R13, R17, the guest list, `campaign.transactions` Excel (`CampaignTransactions.php:129`), network registrations | ✗ (masked) | ✗ | `User.php:354`. **Recommend:** e-mails only to `canSeeEmails` and never in aggregate answers |
| Buyer phone, address | ✓ (Contacts, M) | report-level | ✗ (app: R4, R12, R17 by URL) | ✗ | home delivery only | — |
| Buyer name | ✓ | ✓ | per order in reports | `public_donor_name` only. **App bug:** `ParticipantDashboard`'s `donor_label` ignores `donor_be_anonymous` (`Order.php:407-421`) | ✗ | respect `donor_be_anonymous` everywhere (`Order.php:1288-1296,362`) |
| Donation amount when `donor_amount_anonymous` | ✓ | ✓ | ✓ | **hidden** (app shows it on the P2P latest-gifts card) | — | `P2PGroupOrdersTable.php:97` hides it; the P2P portal does not |
| Participant contact (e-mails, phone, address) | ✓ | ✓ | **✗** | self | ✗ | `Recipient.php:514-519,1426` |
| **Minors' data**: `date_of_birth`, `gender_id`, `school`, `school_level`, `file_number`, `declared_grade`, `personal_pic` | **never in answers** (exports only) | never | never | never | never | `RecipientsExport.php:65-139`, `InscriptionStudentsExport.php:120`, `P2pRecipientsList`. **Exclude from ingestion** |
| Form answers (`answers.content`, free text, possibly health) | per-choice aggregates; raw text on explicit request | same | aggregates | own answers | ✗ | R13 |
| IP addresses (orders, pledges, guest scanners) | **never** | never | never | never | never | `DrawTicketTransactions.php:92-136`; `DashboardScannerUsersQuery.php:71` |
| Card data, Stripe ids, `transfers.interac_password`, `suppliers.shipstation_password`, bank numbers | **never** (the last 4 bank digits are in F2) | never | never | never | never | `AccountStatementNew.php:199-207`. Exclude from ingestion |
| Fees, costs, profit | ✓ | dashboard profit only (D4, R18 by URL) | dashboard profit only | own profit share | own costs and fees | Finances is manager-only by intent |
| Team members' e-mails | owner | — | — (app: `org.manage` shows them to any role, `TeamMembersList.php:42`) | — | — (app exports client staff, see 4.2) | — |
| Words banned in the UI | — | — | — | — | — | answers must not say "Stripe", "Ristourne", "Frais ADF", "Chèque" (CLAUDE.md). Two screens still do: `TeamCampaignsTable.php:55-64`, `SupplierTransfersPerDateTable.php:49,61` |

---

## 6. Where team scope alone leaks, or disagrees with the app

These are the cases where a graph query filtered only by `team_id = current_team_id` would be wrong. Each item says which way it goes wrong.

1. **Restricted campaigns (TR ⊂ T). Team scope leaks.**
   - About 42 users are narrowed, including 12 managers and 9 owners.
   - These screens use T and ignore the restriction: `TaxReceiptsTable` (`:34,78`), `CustomersTable` (`Customer.php:901-927`), `SalesOfTeamTable` (`:73-78`), `TeamRecurringDonationsTable`, `TeamCampaignsMembers`.
   - **Rule:** every campaign-hopping entity takes TR. Team-level entities with no campaign (Customer, Membership, Recurring) should be filtered to rows having **an order in a TR campaign** when the user is restricted (*inferred* intent).
2. **Orders, order lines, answers, reservations, bids and vouchers have no `team_id`.** They must hop through `campaign_id → campaigns.team_id` (`OrderPolicy.php:63-75`). Answers hop through order, customer or recipient.
3. **Related campaigns cross teams (RC). The app counts rows of other teams.**
   - `forCampaignAndRelated` / `CampaignCteRetriever` pull sub-campaigns and survey campaigns with no team filter (`CampaignCteRetriever.php:62-114`). This feeds `total_sales`, `unique_orders` and the main-campaign `pct_goal` (`Campaign.php:593-601,738-742`).
   - Locally, 2 `main_campaigns` pairs and 3 survey links cross teams.
   - **Rule:** compute "raised" over RC ∩ T. Flag the mismatch with the app's figure rather than include another team's rows.
4. **Campaign ownership vs team.** `owner_team_id` differs from `team_id` on 76 campaigns (agent-created, `User.php:520`; `CampaignCopyTrait.php:62`). **The tenant key is `team_id`. Never `owner_team_id`.**
5. **Network (NET).**
   - Parent pages aggregate descendants, but depth differs: all levels for overview, deposits and organisations; direct children only for `AccountStatementTable` (`:38-43`), `getReportTeamIds` (`Team.php:638-641`) and `SalesOfTeamTable` (`:52-56`).
   - Parent managers see child **totals**, not child buyers.
   - **Rule:** a parent manager or agent gets NET aggregates (Campaign, Order and Transfer sums per child). No row-level Customer/Recipient of children, except Contacts when `can_edit_child` (`ChildTeamContactsTable.php:15-23`).
6. **Transfers have two team keys.**
   - 781 child-campaign transfers are deposited to the parent (`destination_team_id`).
   - 613 transfers on client campaigns belong to a supplier team (`team_id` = supplier).
   - **Rule:** an org sees transfers where `destination_team_id = T` (plus `team_id = T` on own campaigns, excluding supplier-team transfers). A child whose money goes to the parent sees the transfer as "deposited to the network", not to itself (*inferred* from `AccountStatementTable.php:68`).
7. **Agents.**
   - An agent acting in a child sees that child's whole team scope; there is no `owner_team_id` filter (`utilities.php:684` commented).
   - `switchTeam()` grants an agent role on **any descendant**, checking neither the caller's role in the parent nor `can_edit_child` (`HasTeamsTrait.php:137-143,172-178`). It is reachable from `TeamChildOrganizationsTable::switchToTeam` (`:159-165`), which is gated only by manager-or-agent (`:38-41`), and from the sidebar profile switch (`DashboardSidebar.php:514-530`), where the posted id is not validated against the offered options (*inferred*).
   - **Rule:** the assistant resolves an agent strictly as staff of the *current* child. It never widens to siblings.
8. **Suppliers are cross-team by design.** Supplier rows live in client teams' campaigns.
   - **Rule:** supplier answers are filtered by `deliverables.supplier_id = my supplier` on order lines (`Order::scopeForSupplier`, `Order.php:1478`). Never by `current_team_id`.
   - Two app gaps the assistant must not copy:
     - `SupplierOrdersCustomer` has no supplier filter.
     - `supplier.orders.report/{campaign_id}`, `supplier.client-deliveries/{team_id}` and `supplier.sales-report` do not check that the id belongs to the supplier's clients (`SupplierClientDeliveriesQuery.php:21`, `SupplierSalesTable.php:37-39,115-120`).
9. **Buyers are cross-team by design (LC).** The match is on e-mail **or phone**, and nothing shows the phone number is verified (`HasLinkedCustomers.php:19-21`). **Rule:** e-mail match only for the assistant, until phone verification is confirmed.
10. **Participants and captains.**
    - A participant sees only OWN-R; a captain sees OWN-G.
    - **App gaps not to copy:**
      - `P2PGroupDashboard` accepts any captain of the same campaign for any group: `asGroupOwner` checks the viewer's own group, not `{id}` (`P2PGroupDashboard.php:43,48`; `Recipient.php:1197-1204`).
      - `P2PParticipantDashboard` and `P2PGroupDashboard` accept **any role** of the organising team (`currentTeamId() == campaign.team_id`, `P2PParticipantDashboard.php:56-62`, `P2PGroupDashboard.php:42`).
11. **Masking is not a scope.** `canSeeEmails` follows the viewer's **current-team** role, not their relation to the row. A participant who also owns a team, viewing with that team current, sees donor e-mails (*inferred*). The assistant should decide e-mail visibility from the audience it resolved (§1), not from `canSeeEmails` alone.

---

## 7. App defects found while mapping (relevant because the assistant must not mirror them)

Reported for the owner. None were exploited. The ids are referenced from `questions.md`.

| Id | Defect | Evidence | Impact for the assistant |
|---|---|---|---|
| R-01 | **No report checks a role.** Basic members and scanners reach buyer e-mails, phones and addresses (R4, R12, R17), customer IPs (draw transactions) and finance figures (end-info) by URL | `app/Kompo/Reports/*` (only `ParticipantsWithoutSaleTable.php:55` checks a role); route group `profile:team\|supplier` (`routes/web.php:275`) | follow the menu intent: basic and scanner get aggregates only |
| R-02 | Ticketing guest list, scan log and detail modal (buyer e-mail, phone) open to scanners by URL; `reEntryOrder` / `markAsUnscanned` take any order id | `Ticketing/GuestsListQuery.php:71-75,152-162` | scanner: counts only |
| R-03 | **Any campaign's account statement by id.** `campaign_id` from the URL is loaded with no team or restriction check | `Finance/AccountStatementNew.php:34-37`; `AccountStatementService.php:59-69` | finance scope must be TR, computed server-side |
| R-04 | **The draw runs on a page view.** Opening `drawing.winners` with no draw yet runs `DrawingProcedure::drawPrizes()`, with no role gate | `Draw/DrawWinners.php:22-30` | never link a non-manager to it; never fetch it |
| R-05 | Unscoped ids in Kompo methods (args come from the client: `vendor/kompo/kompo/src/Core/DependencyResolver.php:22-30`) | `CampaignOrdersTransactions.php:188-204`; `CampaignTransactions.php:243`; `TaxReceiptsTableUtils.php:33-51`; `TaxReceiptsCancelForm.php:23`; `CustomerMergeModal.php:26`; `TeamCampaignsMembers.php:75-112`; `TeamRecurringDonationsTable.php:69-79`; `AllCampaignsTable.php:80-91` (toggles **any** campaign's visibility); `TeamCampaignsTable.php:185-198` (`approveCampaign`); `MailingListForm.php:21-23`; `SmsSendingList.php:72-80`; `CampaignListsTable.php:188-255`; `CampaignVouchers.php:79-87`; `PledgesPerCustomer.php:104-112`; `ParticipantTransactionsTable.php:84-90` (any order preview from a public signed page) | none directly (read-only assistant), but the actions catalogue must check scope itself |
| R-06 | `org.*` routes are `auth` only (`routes/kompo/teams.php:5-30`). With no component gate: `org.deposits` (`NetworkDepositsPage`), `org.manage` (member e-mails), `org.campaigns`, `org.deliveries`, `org.mailing`. Network gate without a role check: `org.child-campaigns`, `org.child-team` (`TeamCampaignsTable.php:35-38`, `ChildTeamPage.php:28`) | as cited | network money: manager or agent of a parent only |
| R-07 | `campaign.transactions` has no route role gate (menu: managers), and its Excel prints the raw buyer e-mail | `CampaignTransactions.php:125-131` | order lists: manager only |
| R-08 | Restricted campaigns ignored on team-wide finance screens | §6 item 1 | TR everywhere |
| R-09 | Donor anonymity ignored: `ParticipantDashboard` shows `donor_label` (name, company, e-mail), and the P2P latest-gifts card shows amounts marked `donor_amount_anonymous` | `ParticipantTransactionsTable.php:47-82`; `Order.php:407-421`; P2P portal | participants get `public_donor_name`; amount hidden when flagged |
| R-10 | A captain can open another team's captain dashboard (incl. donor names, Excel) | `P2PGroupDashboard.php:43-48` | OWN-G strictly |
| R-11 | Linked customers matched by phone | `HasLinkedCustomers.php:19-21` | e-mail match only |
| R-12 | Supplier ids not checked against clients; the client export ships all client-team users' contact data | `SupplierClientDeliveriesQuery.php:21`; `SupplierSalesTable.php:37-39`; `SupplierOrdersReport` via `CampaignOrdersBase.php:37-38`; `SupplierClientUsersSheet.php:45-53` | SUP filter on lines; no client staff PII |
| R-13 | **SQL injection**: `request('campaign_date_id')` is concatenated into raw SQL; the schema `financement.` is hard-coded | `Reports/CampaignVenueTicketsReport.php:21-46` | report to owner; unrelated to the AI |
| R-14 | Agent creation through `switchTeam` checks neither the parent role nor `can_edit_child` | `HasTeamsTrait.php:137-143,172-178`; `TeamChildOrganizationsTable.php:159-165` | resolve agents per current child only |
| R-15 | Registrations (minors' DOB, parents' contacts, form answers, Excel) open to every team role; attendance is the only editor-gated piece | `Inscriptions/CampaignPageInscriptions.php` (base `CampaignPageBase.php:12-15`); `InscriptionStudentsExport.php:120-182`; `AttendanceListController.php:23` | minors' fields excluded |
| R-16 | Tax receipt print and edit: `print.tax-receipts` has `auth` only; the buyer page renders send, edit and cancel actions on receipts | `TaxReceiptsPrintableQuery.php:28-31`; `TaxReceiptForm`; `CustomerTaxReceiptsTable` | buyers: read own receipts only |

---

## 8. Proposed configuration for condoedge/ai (*proposal*, to validate)

This uses the package vocabulary from `ai/docs/plans/2026-10-26-row-scope.md`: `team_path`, `row_scope_path`, `RowScopeResolverInterface`, and `audiences.map.<key>.data_entities`.

- **Team ids** (`$ai_team_ids`):
  - organiser: `[current_team_id]`;
  - parent manager or agent on network questions: `[current_team_id] + descendantTeamIds()`, **aggregate entities only**;
  - supplier: none (row scope below);
  - buyer and participant: none (row scope).
- **`team_path`** per entity (Cypher hop to `Team.id`):
  - `Campaign.team_id`
  - `Order → Campaign.team_id`
  - `OrderDetail → Order → Campaign.team_id`
  - `Product → Campaign`
  - `Recipient → Campaign` (not `recipients.team_id`: 322 rows differ)
  - `Group → Campaign` (28 differ)
  - `Customer.team_id`
  - `TaxReceipt.team_id`
  - `Transfer.destination_team_id`
  - `Reservation → Campaign`
  - `Pledge → Campaign`
  - `Voucher → Campaign`
  - `Answer → Order → Campaign`
  - `EnvoiList → Envoi → Communication → Campaign`
- **Row scope** (`RowScopeResolverInterface`):

  | Asker | `row_scope_path` / ids |
  |---|---|
  | restricted user | campaign ids = `getRestrictedCampaignIdsForTeam(current_team_id)` (`HasRestrictedCampaignsTrait.php:14-23`); path: Campaign id |
  | scanner | campaign ids = `[current_campaign_id]`; entities: Reservation and Campaign counts only |
  | supplier | supplier id; path `OrderDetail → Product → Deliverable.supplier_id` |
  | buyer | customer ids = `linkedCustomers` by e-mail only |
  | participant | recipient id |
  | captain | group id |

- **`data_entities` per audience:**

  | Audience | Entities |
  |---|---|
  | owner / manager | all of §4.1 except Minors/IP/secret columns |
  | editor | not TaxReceipt, Transfer, BillItem, Membership, Recurring, Customer-level contact |
  | basic / agent | Campaign, Product, Group, aggregates of Order/OrderDetail/Reservation/Pledge/Draw/Envoi; Recipient names without contact |
  | scanner | Campaign (current), Reservation counts |
  | parent manager / agent network | Team (children), Campaign, Order and Transfer aggregates |
  | buyer | own Order, TaxReceipt, Reservation, Pledge |
  | participant / captain | own Recipient or Group, their Orders (public donor name), leaderboard totals |
  | supplier | OrderDetail (my products), Campaign (clients' names and dates), Delivery, Transfer (`team_id` = mine) |

- **Never ingest:**
  - `recipients.date_of_birth`, `gender_id`, `school*`, `file_number`, `declared_grade`, `personal_pic`;
  - `answers.content` free text (ingest choice ids only, unless the owner decides otherwise);
  - every `ip`, card and Stripe column;
  - `transfers.interac_password`, `suppliers.shipstation_password`, bank numbers;
  - `teams.tax_number`.
