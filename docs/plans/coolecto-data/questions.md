# Coolecto data questions: a grounded catalogue

**What this is.** These are the data questions that Coolecto users would ask an assistant, sorted by audience and role. Every question points to evidence that people really look for that answer:
- the screen, report or export that answers it today;
- or the help article (slug under `resources/ai-help/aide/**`) that tells people where to find it;
- or an eval turn (`storage/app/eval/coolecto/*.json`).

Companion files:
- `access.md`: who may see which rows. Each question's "Who" column below links to it.
- `metrics.md`: the metric dictionary, written by a sibling agent. The `Metric` column only names the metric.

**How it was built (2026-10-26).**
- I read the menus, routes (`coolecto-ai-research/routes.tsv`), dashboard cards, the 61 report classes, the finance, network, operations, supplier and self-service screens, about 150 of the 466 help articles, and the eval suites.
- Paths are relative to `C:\Users\jkend\Documents\Projects\kompo\ActiviteDeFinancement`.
- Line numbers come from reading the code. A handful of load-bearing ones were re-checked by hand; the rest are as the reading agents reported them.
- Anything marked *(inferred)* was not traced end to end.
- Local DB counts are aggregates only (SELECT COUNT/SUM on `financement`). No personal data was read.

**What real usage tells us** (local DB, all time):
- **Campaign types:**

  | Type | Campaigns | Paid orders |
  |---|---|---|
  | products | 1,623 | 48,472 |
  | forms | 707 | 25,998 |
  | donations | 772 | 16,562 |
  | tickets | 632 | 31,712 |
  | deliveries | 161 | 50,501 |
  | main | 92 | |
  | auctions | 60 | 584 |
  | cheer-up | 24 | |
  | P2P | 19 | 5,961 |
  | memberships | 18 | 489 |
  | inscriptions | 8 | 1,048 |

- **Order statuses:** 182k paid, 10.9k open (abandoned carts), 4.2k refund rows, 2.8k refunded, about 490 in some dispute state, 1,054 tips.
- **Manual payments:** about 10.7k orders, mostly cash (8,871).
- **Recurring donation series:** 101.
- **Tax receipts:** 7,481 sent, 256 created but not sent, 109 cancelled.
- **Transfers:** 4,172 sent, 97 ready.
- **Reservations (tickets and draw numbers):** about 236k, of which about 25.8k were scanned.
- **Auction bids:** 1,857 pledges across 46 campaigns.
- **People:** 169.5k participants (recipients), 125k customers.
- **Team roles:** 4,072 owner, 302 manager, 42 editor, 32 basic, 10 scanner, 46 agent. Today almost every staff asker is an owner.

**Eval evidence.** The only data turns in the eval suites are:
- `6-transversal.json` data-01..04, quoted as Q-OM-001, Q-OM-031, Q-OM-040 and Q-OM-075;
- `3-acheteur.json` buyx-05 (a buyer asks for the organiser's buyer list);
- `4-participant.json` partx-02 (a participant asks for the whole school's sales report).

All of them expect "no invented number, point to the screen". Those are the expectations this catalogue replaces.

## Legend

- **Who** (minimum role the app lets reach the answer screen; see `access.md` §3 for role capabilities and §4 for rows):
  - `M`: owner/manager, which the menu labels "Administrateur en chef" / "Administrateur".
  - `E`: editor+ ("Membre éditeur").
  - `A`: any staff role, because the screen has no role gate (basic, and scanner by URL). Several `A` screens are **wider than the menu intends**; `access.md` §6–§7 recommend tightening them for the assistant.
  - `N`: manager or agent of a parent (network) team.
  - `B` buyer, `P` participant, `C` captain/group leader, `S` supplier.
- **Freq** (frequency guess; the "why" is in the cell):
  - `H` weekly or more per active campaign;
  - `M` a few times per campaign;
  - `L` occasional, or specific to a rare campaign type.
- **Metric**: a name for `metrics.md`. A dash means a plain list or count with no calculation.
  - Where the app computes the "same" figure several ways, the metric is suffixed with the screen's definition, e.g. `raised_gross@total_sales` vs `raised_p2p@goal_sales`. See the divergences at the end.
- **Evidence**: screen IDs from the Screen index (§0), plus help slugs in *italics*.

---

## 0. Screen index (what answers questions today)

### 0.1 Dashboards

Route `dashboard` (`app/Kompo/Dashboard/DashboardView.php:72-145`) and route `campaign.page.insights`.

| ID | Screen | Class, line | Shows (and how) | Filters |
|---|---|---|---|---|
| D1 | Progress card | `Dashboard/CampaignProgressCard.php:33-43,63,96,118-135` | raised = `Campaign::total_sales` (`Campaign.php:599` → `CampaignStatsService.php:157`). Also goal, % (capped at 100), missing, days left, projection (this-week pace × days left) and a 7-vs-7-day chip (`CampaignDashboardService::weekTotals`) | none |
| D2 | Journey card | `CampaignJourneyCard.php:43-59` | first-sale date, 50th order, half the team selling, goal reached | none |
| D3 | Rhythm card | `CampaignRhythmCard.php:24` | 14-day daily sales by `orders.created_at` (`CampaignDashboardService::dailySales`, `paidDetails` at `:154`), with email-send marks | fixed 14 days |
| D4 | Team results card | `CampaignTeamResultsCard.php:124,147` | sellers vs non-sellers, profit % (`getProfitPct`, cached), profit $ (`profits`), rating, average basket (`avg_transaction`) | none |
| D5 | Mails card | `CampaignMailsCard.php:28` | last 4 sends: recipients, open %, click %; warns after 7+ silent days | none |
| D6 | Benchmark card | `CampaignBenchmarkCard.php:20` | rank and median vs similar campaigns at the same age (`CampaignBenchmarkService`) | none |
| D7 | Payout card | `CampaignPayoutCard.php:20` | next payout = Σ un-sent `ownTransfers` and the earliest `to_date` | none |
| D8 | Latest transactions | `DashboardTransactions.php:75` | date, participant/group, status, amount (`getOrderBundleAmount`); a row opens the order preview for managers only | scroll |
| D9 | Contributions by type | `TeamProducts.php:20` | amount per product, donation flag, P2P registration | none |
| D10 | Phase card | `CampaignPhaseGuidanceCard.php:87,146,188` | phase, `pct_goal`, days left, suggested next action | none |
| D11 | Insights: top participants | `CampaignTopParticipants.php:27` | per participant: distinct orders, amount | advanced teams only (`DashboardInsightsPage.php:18`) |
| D12 | Insights: vs similar | `CampaignComparisionStatsCard.php:263` | engagement %, average per participant, average per transaction, profit %, additional donations, shared links | advanced |
| D13 | Insights: sharing | `CampaignShareStatsCard.php:27` → `SharingStatsService.php:12` | per channel: shares, opens, clicks, converting links | advanced |
| D14 | Insights: funnel | `CampaignConversionStatsCard.php:20` → `ConversionFunnelStats.php:51` | 30 days: views, catalogue opens, checkouts, paid, abandoned (count and $), rate | advanced |
| D15 | Insights: email timing and performance | `CampaignEmailStatsCard.php:18`, `PerformanceByEmailTable.php:24` | per weekday and hour: open/click %; per send: sent, opened, clicked, rejected | advanced |
| D16 | Main-campaign dashboard | `MainCampaign/MainCampaignDashboardView`, `TeamCommunications.php:20-38` | sub-campaign rollup, per-send stats | none |

### 0.2 Reports

The sidebar entry is "Rapports" (`Menus/DashboardSidebar.php:338`). The list depends on the campaign type (`Reports/CampaignOrdersBase.php:180-221`). **No report class checks a role.**

| ID | Report (route) | Class, line | Shows | Filters, export |
|---|---|---|---|---|
| R1 | Deliveries by group (`campaign.orders.groups`) | `CampaignOrdersGroups.php:24-30`, `CampaignOrdersGroup.php:25-34` | participants, quantity, groups; per group: participant × product qty | date, group type, tags; Excel/PDF |
| R2 | Sales by group (`campaign.sales.groups`) | `CampaignSalesGroups.php:28-35`, `CampaignGroupsTotals.php:226-236` | participants, transactions, total, profits; per group and per participant × product | date or all, group type, tags; Excel/PDF |
| R3 | Deliveries by date (`campaign.orders.dates`) | `CampaignOrdersDates.php:30-44` | date × product qty and amount, donations excluded | date or all, tags; Excel/PDF |
| R4 | Donations by donor (`campaign.orders.customers`) | `OrdersByCustomers.php:143-172` | donors, transactions, qty, total; per donor name, **email, phone**, products, amount | tags; Excel/PDF |
| R5 | Return to participant (`campaign.orders.recipients`) | `OrdersByRecipients.php:27-75,132-147` + `ParticipantsWithoutSaleTable` | per participant: qty, amount, cost, fees, profit share; list without sales | date, until, tags; Excel/PDF |
| R6 | Main-campaign balances (`campaign.orders.recipients-main`) | `OrdersByRecipientsMainCampaign.php:41-70,108-127` | goal, first/other/last balance, remaining to pay | until, tags; Excel |
| R7 | One participant's orders (`campaign.orders.recipient/{id}`) | `CampaignOrdersRecipient.php:191` | order date, buyer, product, qty, amount | participant |
| R8 | All participants' orders (`campaign.orders.recipients-all`) | `CampaignOrdersRecipientsAll.php:16-31` | the same, for all participants | Excel |
| R9 | P2P participants (`campaign.p2p.recipients`) | `P2pRecipientsList.php:30-41` | team, status, invited/registered/paid dates, total | group, search, non-registered |
| R10 | P2P donations (`campaign.p2p.donations`) | `P2pDonationsList.php:121-137,190-204` | date, donor, participant, team, donation, covered fees (includes tips), total | search, group type |
| R11 | Pledges (`campaign.pledges.recipients` / `.customers`) | `PledgesPerRecipient.php:131-141`, `PledgesPerCustomer.php:28-40` | pledges count and Σ per participant; per pledge: max, paid, payment status | participant, donor |
| R12 | Auction buyers and winners (`campaign.orders.encan`, `campaign.lots-winner`) | `EncanOrdersByCustomers.php:22-31`, `EncanLotsWinners` | per buyer: status, paid at, lots, amount; winners above reserve | Excel/PDF |
| R13 | Form reports (7 routes `campaign.orders.survey-stats`…) | `SurveyAllResultsPerPoll.php:85-94`, `SurveyAllCustomersResults`, `SurveyAllAnswerPollsTable.php:248-258` | count and % per answer; answers per buyer or participant | per question |
| R14 | Supplier picking sheet and home delivery (`campaign.supplier.*`) | `Services/Reports/SupplierPickingSheet.php:43-61`, `SupplierOrdersDeliveredToDonor.php:20-30` | qty per date × product × SKU; home-delivery shipment status and tracking | date, product |
| R15 | Contributions by type (`campaign.contributions.types`) | `ContributionsPerType.php:213-234` | distinct products, qty, total; per product, donation flag, registration | date, tags |
| R16 | Draw reports (`campaign.tickets.draw`, `.draw.logs`, `.draw.exceptions`, `.gli31.accounting`) | `DrawSalesInformation.php:119-135`, `CampaignDrawLogs.php:191-205`, `GLI31AccountingReport.php:20-87` | tickets sold, refunded, valid, void; sales; prize %; winners; event log | date range, event |
| R17 | Seats and venue tickets (`campaign.orders.reservations`, `campaign.venue.tickets`) | `ReservationsReport.php:28-43`, `CampaignVenueTicketsReport.php:21-46` | seat, date, holder | seat, date |
| R18 | End of campaign (`campaign.end-info`) | `CampaignEndInfo.php:164-189` | total sales, platform fees (+tax), costs, profits, profit %, goal %, transactions, products sold, disputes | Excel (4 sheets) |

### 0.3 Finance, contacts, network

| ID | Screen (route) | Class, line | Shows | Gate |
|---|---|---|---|---|
| F1 | Transactions (`campaign.transactions`) | `Finance/CampaignOrdersTransactions.php:28-43`, `CampaignTransactions.php:67-214` | per order: amount, total sales, vouchers, added donation, covered fees, costs, platform fees, profits, participant %, status | menu M; route no role gate. Filters: search, group, status, "no participant", date; Excel |
| F1p | Order preview | `Finance/CampaignOrderPreview.php:62-140` | lines, disputes, fees; refund, resend and link actions | actions `isManager` (`:112`) |
| F2 | Account statement (`team.account-statement-new`) | `Finance/AccountStatementNew.php:34-210,432-453`; `Services/Finance/AccountStatementService.php:254-289` | total transactions, product costs, net profit, profit %, average transaction, deposited, in transit, balance, next deposit, by payment method; tabs for transactions, costs, deposits, invoices, journal, refunds and disputes, one per supplier | `role:owner\|manager`; Excel/PDF |
| F3 | All-campaign statement (`team.account-statement-summary`) | `TeamAccountStatementSummary.php:30-77` | per campaign: sales, costs, profits, deposited, balance, and a total row | M |
| F4 | All deposits (`team.all-deposits`) | `TeamAllDepositsPage.php`; `AccountStatementService.php:474-480` | EFT deposits (date, status, invoices, amount), other deposits (campaign, method, status) | M |
| F5 | Tax receipts (`finance.tax-receipts`) | `Finance/TaxReceiptsTable.php:34,87-119`; `TaxReceipt.php:16-18,230-268` | created, sent, number, donor, campaign, status (created/sent/cancelled), eligible amount | M; filters: search, campaign; Excel/PDF |
| F6 | Contacts (`team.customers`) | `Finance/CustomersTable.php:24-40`; `Customer.php:901-927` | name, company, email, phone, since, last transaction, # campaigns, # transactions, total | M; filters: tags, campaigns, without orders; Excel `CustomersBookExport` |
| F7 | Contact page (`team.customer-page`) | `CustomerPage.php:21-84`; `CustomerInfoPage.php:24-33` | last transaction, # transactions, "this year" (a rolling 12 months, `Customer.php:659`), all-time total, donor score; tabs for pledges, memberships, recurring gifts, communications, unsubscribes, tax receipts | M + same team |
| F8 | Team sales (`team.sales-report`) | `SalesOfTeamTable.php:58-89` | per product: qty, average price, cost, total | M; billing range; Excel |
| F9 | Members (`team.campaign-members`) | `Teams/TeamCampaignsMembers.php`; `Customer.php:298-310`; `Order.php:1426-1444` | members, valid or expired, subscription, autorenew, from/to | M |
| F10 | Recurring gifts (`team.campaign-recurring-donations`) | `TeamRecurringDonationsTable.php`; `Order.php:444-449,1417` | donor, campaign, # recurrences, status, amount, cumulative total, frequency (no next charge date) | M; Excel |
| F11 | My campaigns (`org.campaigns`) | `Teams/AllCampaignsTable.php:20-23` | status, dates, # transactions (`unique_orders`), total sales (`total_sales`) | route `auth` only |
| N1 | Network overview (`org.overview`) | `Teams/ParentTeamOverviewPage.php:31-40,138-160,305-345` | collected (Σ `orders.final_amount` paid), deposited, pending, campaigns running/upcoming/ended, schools active/dormant/never-started, to-handle, last deposits | parent team + M or agent |
| N2 | Network deposits (`org.deposits`) | `Finance/NetworkDepositsPage.php:29-147` | deposited this year (count), pending (# campaigns), platform fees %, table by organisation and campaign | **no gate** |
| N3 | Child organisations (`org.organizations`) | `TeamChildOrganizationsTable.php:38,88,171` | status, responsible, # campaigns, last activity, collected, pending approvals | M or agent |
| N4 | Network campaigns (`org.network-campaigns`) | `TeamChildCampaignsTable.php:20-33,64-82` | approved child campaigns, registrations count and amount | parent + M or agent |
| N5 | One child's campaigns (`org.child-campaigns/{team_id}`) | `TeamCampaignsTable.php:35-64`; `Order.php:1489` | per campaign: Σ final_amount, fees, last order, count, donors, recipients, participation | ancestor team, any role |
| N6 | Child team page (`org.child-team/{id}`) | `ChildTeam/ChildTeamPage.php:28,145-233` | collected over 12 months, deposited, pending, status, responsible | ancestor team, any role |
| N7 | Network registrations (`org.network-registrations`) | `NetworkRegistrationsTable.php:25-42,100-117` | organisation, campaign, activity, group, participant, payer, amount (installments), status | parent + M or agent; Excel |
| N8 | Requests and calendar (`org.requests`, `org.calendar`) | `Requests/TeamRequestsPage.php:15`, `Calendar/NetworkCalendarPage.php:45,235-247` | pending approvals and access requests; child campaigns over the school year | parent + M or agent |
| N9 | Deliveries (`org.deliveries`) | `Teams/TeamOrderDeliveries.php`, `ToTeamOrderDeliveriesTable.php:19`, `ToCustomerOrderDeliveriesTable.php:24-29` | supplier deliveries to the team (status); home deliveries (shipment, tracking) | no gate (advanced menu) |

### 0.4 Operations

| ID | Screen (route) | Class, line | Shows | Gate |
|---|---|---|---|---|
| O1 | Ticket management (`ticketing.dashboard`) | `Ticketing/DashboardTicketsView.php:23-50,152-164`; `Reservation.php:124,132` | per date: sold (reservations with an order, **not checked as paid**), arrived, out, present, % present, +N in last 5 min, unassigned tickets | none |
| O2 | Guest list | `Ticketing/GuestsListQuery.php:44-84`; `OrderTicketsSearchScopes.php:25-57` | buyer, participant, tickets scanned/total, last scan | status: not arrived / present / partial / out |
| O3 | Scanners | `DashboardScannerUsersQuery.php:22-29` | per scanner session: tickets scanned, active within 5 min | |
| O4 | Scan log | `ScannedTicketsLog.php:69,90-111` | scanned vs sold, scan time, scanned by, in/out | product, search |
| O5 | Draw dashboard and winners (`drawing.dashboard`, `drawing.winners`) | `Draw/DashboardDrawingView.php:30-35`; `Campaign.php:1344,1810,1990`; `DashboardDrawingWinnersQuery.php:21-25` | valid tickets, sales excluding donations, 50/50 prize, prizes drawn, winners and claim status (`Draw.php:13-17`) | none (see `access.md` R-04) |
| O6 | Auction dashboard (`encan.dashboard`) | `Encan/DashboardEncanView.php:29-36`; `Campaign.php:691-720`; `TopEncanParticipantsCard.php:19-34` | registered bidders, total bids (`total_engagement`), profit estimate, expected amount, lots with and without bids, top bidders, latest actions | none |
| O7 | Registrations (`campaign.page.inscriptions`) | `Inscriptions/InscriptionDashboardPanel` `:26-136`, `InscriptionStudentsQuery.php:33-46`, `InscriptionSchedulesQuery.php:29-36` | total registrations, total paid, groups, installments active/late/default, to collect, per-day chart; students; payments; plans | none; Excel (DOB, parents, answers) |
| O8 | Attendance (`inscriptions.attendance`) | `Http/Controllers/AttendanceListController.php:20-42` | per group: active participants and their contacts | editor |
| O9 | Communications (`campaign.page.communications`) | `Messaging/EnvoisTable.php:24-37`, `EnvoiListsTable.php:9-13`; statuses in `EnvoiList.php:18-23` | per send: subject, date, status, # sent; per recipient: sent/received/open/clicked/rejected | none |
| O10 | SMS (`campaign.page.sms-sendings`) | `Messaging/SmsSendingList.php:39-63`; `SmsSending.php:13-15` | created, customer, phone, status (pending/sent/failed) | none |
| O11 | Participants page (`campaign.page.recipients`) | `Campaigns/CampaignPageRecipients.php:17`, `CampaignListsTable.php:33-71`, `CampaignRecipientsPageCountComponent.php:23-27` | counts (participants, emails, groups); per participant: groups, emails, P2P status and payment (`Recipient.php:1432-1481`); unsubscribed tab | editor; Excel `RecipientsExport` |
| O12 | Products (`campaign.page.products`) | `Campaigns/CampaignPageProducts.php:57-67,150` | price, stock (tickets only), supplier, hidden. **No quantity-sold column** | none |
| O13 | Vouchers (`campaign.page.vouchers`) | `Campaigns/CampaignVouchers.php:17`; `Voucher.php:61-70,161` | code, type, dates, used ÷ quantity or remaining balance, status | none (advanced menu) |
| O14 | Results entry (`campaign.grades`) | `Groups/DelegateNotesGroupsQuery.php:93-120` | per group: delegate, results entered | none |

### 0.5 Supplier, participant and buyer self-service

| ID | Screen (route) | Class, line | Shows |
|---|---|---|---|
| S1 | Supplier dashboard (`dashboard`) | `Market/SupplierDashboardStatsCards.php:48-91` | products sold with YoY, deposits received and in transit, client organisations (+ new this month), deliveries to confirm |
| S2 | Statistics (`supplier.statistics`) | `Market/SupplierStatisticsPage.php:127-188` | average basket and cost, active campaigns, satisfaction, repeat rate, monthly sales, by org type, best sellers, order-size spread |
| S3 | Clients (`supplier.clients`) | `Market/SupplierClientsQuery.php:115-314` | client teams, campaign state, account setup, my sales (Σ `deliveries.net_amount`), last sale |
| S4 | Deliveries (`supplier.deliveries`, `supplier.client-deliveries`) | `SupplierDeliveriesQuery.php:45-70`, `SupplierClientDeliveriesQuery.php:21-28`; `Delivery.php:22-26` | per delivery: team, campaign, status, qty, amount |
| S5 | Production (`supplier.production`) | `Services/Supplier/ProductionMatrixService.php:192-221` | qty per product/variant per ISO week of the campaign end |
| S6 | Payouts (`supplier.account-statement`) | `Services/Finance/SupplierAccountStatementService.php:212-254` | to receive, in transit, paid this year vs last, platform fees, deliveries to invoice |
| S7 | Sales report and transfers (`supplier.sales-report`, `supplier.transfers-report`) | `Finance/SupplierSalesTable.php:93-108`, `SupplierTransfersPerDateTable.php:23-31` | qty, retail price, cost, discount, fees; transfers by date |
| P1 | Participant dashboard, signed (`participant.dashboard`) | `Groups/Participants/ParticipantDashboard.php`; `Recipient.php:1129`; `ParticipantTransactionsTable.php:47-82` | my transactions, sales, products, profit share, org goal %, group goal %, rank, share funnel, my donors |
| P2 | Group dashboard, signed (`group.dashboard`) | `Groups/DelegateGroupDashboard.php`; `Group.php:648` | group sales, transactions, profit, goal %, leaderboard, top 5 groups |
| P3 | P2P participant portal (`participant.p2p-campaign-dashboard`) | `Dashboard/P2PParticipantDashboard.php:56-62`; `Services/Campaigns/P2PParticipantPortal.php:77`; `Recipient.php:579-598` | raised (`goal_sales`, no fees/tips), goal, level, streak, gift count, latest gifts, team total and rank, challenge |
| P4 | Captain dashboard (`group.p2p-campaign-dashboard`) | `Dashboard/P2PGroupDashboard.php:43-48`; `Services/Campaigns/P2PCaptainService.php:60,116`; `P2PGroupOrdersTable.php:50-97` | team total, rank, members with gifts, to-do (not started / inactive 7 days / unpaid / goal reached), transactions (Excel) |
| P5 | My P2P campaigns (`user.p2p-campaigns`) | `Profile/MyP2PCampaignsTable.php:52-66` | per campaign: total donated ÷ goal |
| B1 | Donor dashboard (`dashboard`, customer mode) | `Profile/MyProfileDashboard.php:36-44`; `Customer.php:659-664` | # transactions, "this year" (rolling 12 months), all-time total |
| B2 | My transactions (`user.transactions`) | `Finance/CustomerOrdersTable.php:30-37` | number, campaign + organisation, date, products, amount |
| B3 | My tax receipts (`user.tax-receipts`) | `CustomerTaxReceiptsTable.php:26-32` | date, status, description, eligible amount |
| B4 | Receipt hub, signed (`public.receipt`) | `Receipts/PublicReceiptHub.php:97-155`; `config/receipt_hub.php:15-58` | total, number and date, installments paid ÷ total, next payment, tickets, delivery, tax receipt |

---

## 1. Organiser: owner and manager

**Who asks.** The owner ("Administrateur en chef") or a manager ("Administrateur") of a team, in team mode.
- They see every section of the app: campaign, Finances, and "Mon organisme" (`DashboardSidebar.php:353-359,369-393`).
- Rows: the campaigns of `currentTeamCampaigns()`, which means the `restricted_campaigns` subset when one is set (`utilities.php:673-686`).
- 12 managers and 9 owners are restricted on local data. See `access.md` §4.

### 1.1 Campaign results and goal

| # | FR (as typed) | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-001 | Combien d'argent ma campagne a ramassé jusqu'à maintenant? | How much has my campaign raised so far? | A | H (eval data-01; headline of D1) | `raised_gross@total_sales` | D1; F2 "Montant total transactions"; *rapports-tableau-de-bord-de-la-campagne*, *campagne-tableau-de-bord-resultats* |
| Q-OM-002 | On est rendus à combien % de notre objectif? | What % of our goal have we reached? | A | H (gauge on every dashboard) | `goal_progress_pct` (D1 uses total_sales; D10 uses `pct_goal`, which is P2P/profit-aware) | D1 `:63`, D10 `:146`; `Campaign.php:723-745`; *campagne-objectif* |
| Q-OM-003 | Il nous manque combien pour atteindre l'objectif? | How much is left to reach the goal? | A | H | `goal_remaining` | D1 `:96`; *campagne-tableau-de-bord-resultats* |
| Q-OM-004 | À ce rythme-là, on va finir à combien? | At this pace, what will we end at? | A | M (projection appears from day 7) | `projected_final` | D1 `:118-135` |
| Q-OM-005 | On a vendu combien cette semaine comparé à la semaine passée? | This week vs last week? | A | H (weekly check-in) | `sales_week_vs_prev` | D1 chip, D3; `CampaignDashboardService::weekTotals` (`:43`) |
| Q-OM-006 | Quelles journées ont été les meilleures? / Le rappel de mardi a-tu fait vendre? | Best days? Did Tuesday's reminder drive sales? | A | M | `sales_daily` | D3 (14 days + send marks) |
| Q-OM-007 | C'est quoi notre profit net jusqu'ici? | What is our net profit so far? | A on D4, M on F2 | H | `profit_net` (`Campaign::profits`, `:750-775`) | D4, F2 "Profits nets cumulés", R18; *frais-calcul-du-profit* |
| Q-OM-008 | C'est quoi notre pourcentage de profit? | What is our profit %? | A/M | M | `profit_pct` | D4 (`getProfitPct`, cached), F2 `:147`; *rapports-releve-de-compte* |
| Q-OM-009 | C'est quoi notre panier moyen? | What is our average order value? | A | M | `avg_order_value` (`avg_transaction`, `Campaign.php:784`) | D4; F2 "Montant moyen par transaction" |
| Q-OM-010 | Combien de commandes/transactions on a eu? | How many orders/transactions? | A | H | `orders_paid_count` (`unique_orders`, `:593`) | F11, R18, F2 `nb_transactions`; *commandes-liste-des-transactions* |
| Q-OM-011 | Notre objectif, il est calculé sur les ventes ou sur les profits? | Is our goal on sales or on profit? | A | L (setup doubt) | `goal_basis` | `Campaign.php:723-736` (`goal_type`); *campagne-objectif* |
| Q-OM-012 | Dans quelle phase est ma campagne, il reste combien de jours? | What phase is my campaign in, days left? | A | M | `campaign_phase`, `days_left` | D10 (`CampaignChecksTrait.php:37`); *campagne-tableau-de-bord* |
| Q-OM-013 | On se compare comment aux campagnes semblables? | How do we compare to similar campaigns? | A | M | `benchmark_rank` | D6; D12 (advanced); *rapports-indicateurs* |
| Q-OM-014 | On a passé quels jalons? (première vente, 50 commandes, moitié des jeunes qui vendent) | Which milestones have we passed? | A | L | `milestones` | D2 |
| Q-OM-015 | Fais-moi le bilan final de la campagne pour le CA | Give me the final campaign summary for the board | A (should be M) | M (once per campaign, high value) | `end_summary` (sales, fees + tax, costs, profit, profit %, goal %, transactions, products sold, disputes) | R18 `:164-189`; *rapports-fin-de-campagne* |
| Q-OM-016 | Quelle note les acheteurs nous ont donnée? | What rating did buyers give us? | A | L | `rating_avg`, `rating_count` (`Campaign.php:2044-2056`) | D4 |
| Q-OM-017 | Combien a rapporté chaque activité du groupe de campagnes? | How much did each sub-campaign raise (campaign group)? | A | L (92 main campaigns) | `raised_gross` per sub-campaign, `goal_progress_pct` (sum of subs) | D16; `Campaign.php:738-742`; *demarrage-groupe-de-campagnes* |

### 1.2 Sales by product and date

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-020 | Quel produit s'est le plus vendu? | Which product sold best? | A | H (product campaigns are the largest type) | `qty_by_product`, `sales_by_product` | R15, D9; *rapports-contributions-par-types* |
| Q-OM-021 | Combien de [produit] on a vendu en tout? | How many [product] did we sell in total? | A | H | `qty_by_product` | R15, R3 |
| Q-OM-022 | Combien on a ramassé en dons vs en ventes vs en inscriptions? | How much from donations vs sales vs registrations? | A | M | `sales_by_contribution_type` (donation_flag split) | R15 `:213-234`, D9; *rapports-contributions-par-types* |
| Q-OM-023 | Combien de repas/produits à préparer pour le 12 décembre? | How many meals/items to prepare for Dec 12? | A | H around delivery days (deliveries type: 50k orders) | `qty_by_product_date` | R3 `:30-44`; *rapports-livraisons* |
| Q-OM-024 | Quoi remettre à chaque classe/groupe? | What to hand out to each class/group? | A | H at distribution | `qty_by_group_product` | R1; *rapports-livraisons-par-groupes*, *livraison-distribuer-les-produits-aux-participants* |
| Q-OM-025 | Il nous reste combien de [produit] en stock? / Quels produits sont épuisés? | How much stock is left? Which products are sold out? | A | M | `stock_remaining` (= stock − sold; **no sold column on O12**; computed from order lines) | O12 `:150` (tickets only); *produits-inventaire-et-quantite-disponible*, *produits-quantite-maximale-atteinte* (gap: see §9) |
| Q-OM-026 | Combien de produits on doit recevoir du fournisseur? | How many units should the supplier deliver to us? | A | M | `qty_supplier_picking` (deliver-to-org only) | R14 `SupplierPickingSheet.php:43-61`; *rapports-fournisseurs* |
| Q-OM-027 | Quelles commandes en livraison à domicile sont pas encore expédiées? Numéro de suivi? | Which home-delivery orders aren't shipped? Tracking? | A | M | — | R14 `SupplierOrdersDeliveredToDonor.php:20-30`, N9; *livraison-livraison-directe-chez-l-acheteur* |
| Q-OM-028 | Où en est notre livraison du fournisseur? | Where is our supplier delivery at? | A | M | `delivery_status` (`Delivery.php:22-26`) | N9; *livraison-page-livraisons-de-l-organisme* |
| Q-OM-029 | Est-ce qu'on atteint le minimum pour la livraison gratuite? | Do we reach the free-delivery minimum? | A | L | `free_delivery_gap` *(inferred, no screen)* | *livraison-frais-de-livraison-et-livraison-gratuite* |
| Q-OM-030 | Quelles commandes sont pas encore livrées/remises? | Which orders are not delivered/handed out yet? | M | M | — | F1 (mark delivered, `CampaignOrdersTransactions.php:188-202`); *commandes-marquer-livree* |

### 1.3 Participants, groups and leaderboards

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-031 | Quels participants ont vendu le plus cette semaine? | Which participants sold the most this week? | A | H (eval data-02) | `participant_sales` over a date window | D11 (advanced), R5, R2; *participants-classement-des-participants* |
| Q-OM-032 | Top 10 des vendeurs | Top 10 sellers | A | H | `participant_sales`, `participant_rank` | D11 `:27`, R5 |
| Q-OM-033 | Qui a pas encore vendu? | Who hasn't sold yet? | A (report), E (relaunch) | H (the reminder button exists for it) | `recipients_without_sale` (`CampaignGuidanceTrait.php:62,71`) | R5 + `ParticipantsWithoutSaleTable`, D4; *participants-sans-vente-et-relance*, *rapports-profits-des-participants* |
| Q-OM-034 | Combien de participants ont vendu au moins une fois? | How many participants made at least one sale? | A | H | `sellers_count` vs `active_recipients_count` | D4, D2 (`CampaignDashboardService::sellersCount`) |
| Q-OM-035 | Combien a vendu chaque élève? | How much did each student sell? | A | H | `participant_sales` | R5 `:59-75`, R2 |
| Q-OM-036 | Quelle classe/équipe a vendu le plus? | Which class/team sold the most? | A | H | `group_sales`, `group_rank` | R2 `CampaignGroupsTotals.php:226-236`; *rapports-ventes-par-groupes* |
| Q-OM-037 | Les profits par équipe | Profit per team | A | M | `group_profit` (raw in R2 groups, share-adjusted elsewhere) | R2; *rapports-ventes-par-groupes* |
| Q-OM-038 | C'est quoi la part de profit de chaque participant? | What is each participant's profit share? | A | M (profit-share campaigns) | `participant_profit_share` | R5 `:132-147`, F1 "recipient-percent"; *participants-partage-des-profits* |
| Q-OM-039 | Combien de ventes sont pas liées à un participant? | How many sales have no participant? | M (F1), A (R5 row) | M | `unattributed_sales` | F1 filter; R5 "Pas de participant"; *commandes-liste-des-transactions* |
| Q-OM-040 | Qui a acheté à Léo? / Les commandes de tel participant | Who bought from Léo? | A | M | — | R7 `:191`, R8; *rapports-commandes-des-participants* |
| Q-OM-041 | Combien de participants inscrits/actifs? | How many participants registered/active? | E (page), A (counts on D4) | M | `active_recipients_count` (`Campaign.php:277-286`) | O11 counters; *participants-comprendre-la-page-participants* |
| Q-OM-042 | Quels participants sont sans groupe? | Which participants have no group? | E | L | — | O11 filter `empty`; *participants-rechercher-filtrer-et-exporter* |
| Q-OM-043 | Qui a pas complété son profil / pas payé ses frais d'inscription (P2P)? | Who hasn't completed their profile / paid the P2P fee? | E | M (P2P) | `p2p_status_counts` (`Recipient.php:1432-1481`) | O11, R9; *participants-statuts-en-pair-a-pair* |
| Q-OM-044 | Qui a été invité mais s'est jamais inscrit? | Who was invited but never registered? | A | M (P2P) | — | R9 (show non-registered); *rapports-p2p* |
| Q-OM-045 | Qui a atteint son objectif? Qui est loin de son objectif? | Who reached their goal? Who's far from it? | A | M | `participant_goal_pct` (`Recipient.php:579-598`) | R6 (goal), P3; *participants-objectifs-des-participants-et-des-groupes* |
| Q-OM-046 | C'est quoi l'objectif de chaque équipe? | What's each team's goal? | A | L | `group_goal` | `Group::pctGoal`; *participants-objectifs-des-participants-et-des-groupes* |
| Q-OM-047 | Quelle équipe mène le défi de la semaine? | Which team leads this week's challenge? | A | L (P2P) | `challenge_standings` (`CampaignChallengeStandings::scores`) | P3/P4 challenge; *participants-paliers-et-defis-en-pair-a-pair* |
| Q-OM-048 | Liste des dons par équipe (P2P) | Donations by team (P2P) | A | M (P2P) | `p2p_donations_by_group` | R10; *rapports-p2p* |
| Q-OM-049 | Quel solde reste à payer chaque participant pour le voyage? | Balance each participant still owes for the trip? | A | M (main campaigns) | `participant_balance_due` | R6 `:108-127`; *rapports-profits-des-participants* |
| Q-OM-050 | Les résultats ont-ils été saisis pour chaque groupe? | Have results been entered for every group? | A | L | — | O14 `:93-99`; *campagne-resultats-par-participant-ou-groupe* |
| Q-OM-051 | Combien d'ambassadeurs a chaque participant, ils ont vendu combien? | Ambassadors per participant and what they sold? | A/P | L | `ambassador_sales` | P1 `ParticipantAmbassadorTable`; *participants-ambassadeurs* |
| Q-OM-052 | Liste des équipes avec leur capitaine | Teams with their captains | E | L | — | O11 Leaders tab; *groupes-equipes-et-capitaines-en-pair-a-pair* |

### 1.4 Orders and payment status

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-060 | Montre-moi les commandes d'hier / de cette semaine | Show me yesterday's / this week's orders | M (A sees D8 rows without buyer) | H | — | F1 (`{date}`), D8; *commandes-liste-des-transactions* |
| Q-OM-061 | Est-ce que [nom] a payé? / Trouve la commande de [nom] | Did [name] pay? Find [name]'s order | M | H (the common support lookup) | — | F1 search (`searchEverythingBy`), F7; *commandes-voir-le-detail-d-une-commande* |
| Q-OM-062 | Qu'est-ce que la commande #4567 contient? | What's in order #4567? | M | M | — | F1p `:62-140` |
| Q-OM-063 | Quelles commandes sont remboursées? Combien on a remboursé ce mois-ci? | Which orders are refunded? How much refunded this month? | M | M (4.2k refund rows) | `refunds_amount`, `refunds_count` (`OrderCalculationService::getRefundStats`, `:255`) | F1 status filter, F2 refunds tab; *commandes-statuts-des-transactions*, *commandes-rembourser-des-items* |
| Q-OM-064 | Y a-tu des paiements contestés? Ça nous a coûté combien en frais? | Any disputed payments? What did they cost in fees? | M | L (about 490 dispute rows) | `disputes_count`, `disputes_amount`, `dispute_fees` (`Order::DISPUTE_FEES_AMOUNT=15`) | F1 status, F2 refunds-and-disputes tab, R18 `disputes_count`; *paiements-contestations* |
| Q-OM-065 | Liste-moi les commandes qui sont pas encore payées | List the orders not paid yet | M | H (eval data-03; 10.9k open orders) | `orders_open_count`, `abandoned_amount` | D14 abandoned (advanced). **F1 hides OPEN/CANCELED** (`Order::showInListStatuses`, `Order.php:539`); *commandes-statuts-des-transactions* (gap: §9) |
| Q-OM-066 | Combien de paniers abandonnés, pour quel montant? | How many abandoned carts, for how much? | A (advanced) | M | `abandoned_count`, `abandoned_amount` | D14 `ConversionFunnelStats.php:51`; *rapports-indicateurs* |
| Q-OM-067 | Y a-tu des demandes de paiement en attente? | Any pending payment requests? | M | L | — | F1 status sub-label; *commandes-statuts-des-transactions* |
| Q-OM-068 | Combien on a reçu en argent comptant / hors Coolecto? | How much did we receive in cash / outside Coolecto? | M | M (10.7k manual orders) | `manual_payments_by_method` | F2 by payment method `:432-453`; *paiements-paiement-manuel*, *paiements-argent-recu-hors-coolecto* |
| Q-OM-069 | Combien on a vendu par carte vs comptant vs Interac? | Sales by payment method? | M | M | `sales_by_payment_method` | F2 `:432-453`; *rapports-releve-de-compte-onglets* |
| Q-OM-070 | Combien a encaissé le terminal de Marie? | How much did Marie's terminal process? | A | L | `terminal_amount` | `campaign.qr-terminal`; *paiements-terminal-gerer-les-terminaux* |
| Q-OM-071 | Est-ce que je peux encore rembourser cette commande? | Can I still refund this order? | M | M | `is_refundable` (`Order::refundableStatuses`, `:629`; `OrderPolicy::refund`) | F1p; *commandes-remboursement-impossible* |
| Q-OM-072 | Combien de donateurs ont couvert les frais? Combien ça donne? | How many buyers covered fees? Total? | M | M | `covered_fees`, `covered_fees_count` | F1 "covered fees", F2; *frais-couverture-des-frais-par-les-acheteurs* |
| Q-OM-073 | Combien de dons additionnels on a reçus? | How much in add-on donations? | M | M | `added_donations` (watch: D12 "additional donations" also includes fees, tips and delivery) | F1 `getAddedAmount` (`Order.php:1520`), F2; *dons-don-supplementaire-et-don-sans-achat* |
| Q-OM-074 | Combien les codes promo / bons de réduction nous ont coûté? | How much did discount codes cost us? | M | L | `voucher_discounts` | F1 vouchers, F2; *rapports-releve-de-compte-explication-des-montants* |

### 1.5 Tickets, seats and scans

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-075 | Il reste combien de billets pour le souper? | How many tickets are left for the dinner? | A | H (eval data-04; tickets are the 3rd type by orders) | `tickets_remaining` (= stock − sold; "sold" is not checked as paid) | O1, O12 stock; *billets-quantites-et-limites* |
| Q-OM-076 | Combien de billets vendus par type / par date? | Tickets sold per type / per date? | A | H | `tickets_sold` (`Reservation::getSoldTickets`, `:124`) | O1, O4 product filter; *billets-page-gerer-les-billets* |
| Q-OM-077 | Combien de billets scannés ce soir? Combien de monde présent en ce moment? | Tickets scanned tonight? People present now? | A (also asked by scanners) | H at events | `tickets_scanned`, `tickets_present` | O1 `:152-164`; *billets-tableau-de-bord-gestion-des-billets* |
| Q-OM-078 | Qui est pas encore arrivé? | Who hasn't arrived yet? | A | M at events | — | O2 status `not_arrived`; *billets-liste-des-invites-et-enregistrement* |
| Q-OM-079 | Combien chaque bénévole a scanné? | How many tickets each volunteer scanned? | A | L | `scans_by_scanner` | O3; *billets-ajouter-un-scanneur-benevole*, *billets-journal-des-billets-scannes* |
| Q-OM-080 | Qui est assis au siège B12? / Liste des places réservées | Who sits in seat B12? Reserved seats | A | L (venue campaigns) | — | R17 `ReservationsReport.php:28-43`; *rapports-billets-et-reservations* |
| Q-OM-081 | Combien de billets annulés (void)? | How many tickets were voided? | A | L | `tickets_void` (`Reservation` status 2, 363 rows) | O1, R16 |
| Q-OM-082 | Y a-tu des billets pas assignés à une date? | Any tickets without a date? | A | L | `tickets_unassigned` | O1 banner `:27-30` |

### 1.6 Registrations (inscriptions), attendance and installments

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-090 | Combien d'inscrits au total? Par cours / par groupe? | How many registrations in total? Per course/group? | A | H (registration campaigns) | `registrations_count` | O7 `:26,94-98`; *inscriptions-gestion-des-inscriptions* |
| Q-OM-091 | Combien les familles ont payé en tout? | How much have families paid in total? | A | M | `registrations_paid_amount` | O7 `:35`; *inscriptions-gestion-des-inscriptions* |
| Q-OM-092 | Quels groupes sont complets? Il reste combien de places au cours de natation? | Which groups are full? Seats left in swimming? | A | H in the registration window | `seats_remaining` | O7 groups/activities; *inscriptions-places-et-capacite* |
| Q-OM-093 | Qui a commencé une inscription sans payer? | Who started a registration without paying? | A | M | `registrations_pending` | O7 students `show_pending_payment`; *inscriptions-liste-des-etudiants-et-export* |
| Q-OM-094 | Quelles familles sont en retard de versement? Combien reste à percevoir? | Which families are late on installments? How much is left to collect? | A | M | `installments_late_count`, `installments_to_collect` (`OrderScheduleStatus`) | O7 `:70-87`, plans tab; *inscriptions-versements-suivi* |
| Q-OM-095 | Liste des présences du groupe du mardi | Tuesday group's attendance list | E | M (weekly courses) | — | O8; *inscriptions-listes-de-presences* |
| Q-OM-096 | D'où viennent nos inscrits / par école? | Where do registrants come from / by school? | A | L | `registrations_by_school` | O7 map; *inscriptions-carte-des-inscriptions* |
| Q-OM-097 | Combien d'inscriptions par jour depuis l'ouverture? | Registrations per day since opening? | A | L | `registrations_daily` | O7 `:106-136` |

### 1.7 Auctions, draws and pledges

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-100 | C'est quoi le total des mises en ce moment? | What is the bid total right now? | A | H during an auction (60 campaigns) | `auction_total_engagement` (`Campaign.php:691`) | O6; *encan-pendant-l-encan* |
| Q-OM-101 | Quels lots ont aucune mise? | Which lots have no bids? | A | M | `lots_without_bids` | O6 `DashboardEncanProductsQuery`; *encan-pendant-l-encan* |
| Q-OM-102 | Qui mène sur le lot 5? | Who is leading on lot 5? | A | M | — | O6 |
| Q-OM-103 | Qui a gagné quoi? Quels gagnants ont pas encore payé? | Who won what? Which winners haven't paid? | A | M after close | `winners_unpaid` | R12 (`EncanLotsWinners`, `EncanOrdersByCustomers.php:22-31`); *encan-rapports*, *rapports-encan* |
| Q-OM-104 | On prévoit de ramasser combien avec l'encan (estimation)? Profit? | Expected auction proceeds and profit? | A | L | `auction_expected`, `auction_profit` (`Campaign.php:718`) | O6 `:29-36` |
| Q-OM-105 | Combien de billets de tirage vendus? La cagnotte 50/50 est rendue à combien? | Raffle tickets sold? 50/50 pot so far? | A | H during a raffle | `draw_tickets_valid` (`Campaign.php:1810`), `prize_5050` (`:1344`) | O5 `:30-35`; *tirage-tableau-de-bord*, *tirage-50-50* |
| Q-OM-106 | Quels prix sont pas encore réclamés? | Which prizes are still unclaimed? | A | M after the draw | `prizes_unclaimed` (`Draw.php:13-17`) | O5 winners; *tirage-effectuer-le-tirage* |
| Q-OM-107 | Combien de billets annulés ou remboursés au tirage? | Raffle tickets cancelled or refunded? | A | L (licensing/audit) | `draw_exceptions` | R16 `ExceptionReport`, `DrawSalesInformation.php:119-135`; *tirage-rapports-et-journal* |
| Q-OM-108 | Valeur totale des prix / % redistribué | Total prize value / % paid out | A | L | `prize_total_value`, `prize_pct_of_sales` | R16 GLI31 `:20-87` |
| Q-OM-109 | Combien a été engagé vs payé (engagements)? | Pledged vs paid? | A | L (24 cheer-up campaigns) | `pledged_amount`, `pledged_paid` | R11; *rapports-engagements* |
| Q-OM-110 | Quels engagements sont pas encore payés? | Which pledges are still unpaid? | A | L | — | R11 `PledgesPerCustomer` status; *rapports-engagements* |

### 1.8 Memberships, recurring gifts and forms

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-115 | Combien de membres actifs on a? | How many active members do we have? | M | M (18 membership campaigns) | `members_active_count` (`Order::scopeValidMembership`, `:1433`) | F9; *adhesions-liste-des-membres* |
| Q-OM-116 | Quelles adhésions expirent bientôt / sont expirées? | Which memberships expire soon / have expired? | M | M | `members_expiring` (`expires_at`) | F9, F7 tab; *adhesions-liste-des-membres* |
| Q-OM-117 | Qui a le renouvellement automatique? | Who has auto-renew? | M | L | — | F9 modal (`recurrence`) |
| Q-OM-118 | Combien de donateurs mensuels actifs? Ça rapporte combien par mois? | How many active monthly donors? Monthly amount? | M | M (101 series) | `recurring_active_count`, `recurring_monthly_amount` | F10 (`Order::recurringDonations`, `:1417`); *dons-dons-mensuels-recurrents* |
| Q-OM-119 | Quels dons récurrents ont été annulés? | Which recurring gifts were cancelled? | M | L | — | F10 status |
| Q-OM-120 | Combien de personnes ont répondu « oui » à telle question du formulaire? | How many answered "yes" to form question X? | A | M (707 form campaigns) | `answers_by_choice` (count and %) | R13 `SurveyAllResultsPerPoll.php:85-94`; *formulaires-rapports-des-reponses*, *rapports-formulaires* |
| Q-OM-121 | Combien de réponses au formulaire? Qui n'a pas répondu? | How many responses? Who hasn't answered? | A | M | `form_responses_count` | R13 |
| Q-OM-122 | Liste des allergies déclarées | List of declared allergies | A (**health data**) | M (camps, registrations) | — | R13 `SurveyAllCustomersResults`/`SurveyAllRecipientsResults`; *formulaires-creer-un-formulaire*, eval own-03 (sensitive: see `access.md` §5) |

### 1.9 Tax receipts

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-125 | Quels reçus d'impôt sont pas encore envoyés? | Which tax receipts are not sent yet? | M | M (256 created and unsent locally; peaks in Jan–Feb, *inferred*) | `tax_receipts_by_status` | F5 status CREATED; *recus-page-des-recus-d-impot*, *recus-envoyer-les-recus-d-impot* |
| Q-OM-126 | Combien de reçus on a émis cette année, pour quel total? | How many receipts issued this year, for how much? | M | M (year-end) | `tax_receipts_count`, `tax_receipts_amount` (`donation_amount`) | F5, F2 "Reçus fiscaux émis" `:139-145` |
| Q-OM-127 | Trouve le reçu de Jean Roy | Find Jean Roy's receipt | M | M | — | F5 search, F7 tab |
| Q-OM-128 | Quels reçus ont été annulés? | Which receipts were cancelled? | M | L | — | F5 status CANCELED; *recus-annuler-un-recu-d-impot* |
| Q-OM-129 | Quelles transactions ont pas eu de reçu, pis pourquoi? | Which transactions got no receipt, and why? | M | L | `receipt_eligibility` (below minimum / nothing eligible / installments) | *recus-creer-un-recu-d-impot*, *recus-montant-admissible-par-produit* (gap: no list screen) |
| Q-OM-130 | Combien de reçus d'activités sportives ont été produits? | How many sports-activity receipts were produced? | M | L | `sport_receipts_count` | F5; *recus-recus-d-inscription-sportive* |

### 1.10 Money: statement, deposits, fees

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-135 | Quand je vais recevoir mon prochain dépôt, pis combien? | When is my next deposit, and how much? | M (D7 is A) | H (most-asked finance item; eval sec-deposits) | `next_deposit` (`AccountStatementNew.php:179-210`; D7 `CampaignDashboardService::nextPayout`) | D7, F2; *transferts-quand-l-argent-est-depose* |
| Q-OM-136 | Coolecto nous doit combien encore? C'est quoi notre solde? | How much does Coolecto still owe us? Our balance? | M | H | `account_balance` (`AccountStatementService.php:142-145`) | F2; *rapports-releve-de-compte-solde-du-compte* |
| Q-OM-137 | Combien a été déposé dans notre compte jusqu'ici? | How much has been deposited so far? | M | M | `deposited_amount` (Σ transfers SENT) | F2 `:161`, F4 |
| Q-OM-138 | Le dépôt de 1 234 $ du 7 mars, c'est pour quelle campagne? | Which campaign is the $1,234 deposit of March 7 for? | M | M (a deposit can bundle campaigns) | — | F4 deposit detail; *transferts-a-quoi-correspond-ce-depot* |
| Q-OM-139 | Où en sont nos dépôts? Y en a-tu en erreur? | Status of our deposits? Any errors? | M | M | `transfers_by_status` (`Transfer.php:16-21`) | F2 deposits tab, F4; *transferts-statuts-des-depots* |
| Q-OM-140 | Pourquoi mon dépôt est plus petit que mes ventes? | Why is my deposit smaller than my sales? | M | M (an explanation backed by figures) | `statement_breakdown` (sales − refunds − disputes − costs − fees) | F2; *rapports-montants-qui-ne-concordent-pas*, *rapports-releve-de-compte-explication-des-montants*, eval own-09 |
| Q-OM-141 | Combien on a payé en frais de plateforme? | How much did we pay in platform fees? | M | M | `platform_fees` (`OrderDetail.php:790-797`) | F1, F2, R18 (+ tax); *frais-frais-de-plateforme* |
| Q-OM-142 | Combien le fournisseur a payé de frais à notre place? | How much in fees did the supplier absorb? | M | L | `supplier_paid_fees` | F2 `supplier_fees` `:217`; *frais-frais-payes-par-le-fournisseur*, eval own-13 |
| Q-OM-143 | C'est quoi le coût des produits, par fournisseur? | Cost of goods, by supplier? | M | M | `product_costs` | F2 costs and supplier tabs; *rapports-releve-de-compte-onglets* |
| Q-OM-144 | Y a-tu des factures impayées / des commandes pas facturées? | Any unpaid invoices / uninvoiced orders? | M | L | `invoices_due` | F2 invoices tab; *factures-factures-de-coolecto*, *rapports-releve-de-compte* |
| Q-OM-145 | Sors-moi les écritures comptables du mois d'octobre | Ledger entries for October | M | L (treasurers) | — | F2 journal tab; `team.accounting-settings`; *rapports-grand-livre-et-parametres-comptables* |
| Q-OM-146 | Notre compte bancaire est-il vérifié? | Is our bank account verified? | M (bank: owner by default) | M at setup | `bank_status` | `finance.onboarding`; *banque-verification-du-compte*, *banque-qui-peut-gerer-le-compte* |
| Q-OM-147 | Combien on a vendu par produit sur la période de facturation? | Sales per product over the billing period? | M | L | `sales_by_product@billing` | F8 `:58-89` |

### 1.11 Customers and donors (privacy-sensitive)

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-150 | Qui sont nos plus gros donateurs? | Who are our biggest donors? | M (F6); R4 is A | M | `donor_lifetime_amount` (team), `donor_campaign_amount` (R4) | F6 `:24-40`, R4; *rapports-dons-par-donateurs*, *contacts-liste-des-contacts* |
| Q-OM-151 | Combien de donateurs uniques cette campagne? | How many unique donors this campaign? | A | M | `unique_donors` (R4 counts customers with **any** order status, `:156`) | R4 KPI |
| Q-OM-152 | Qui a donné l'an passé mais pas cette année? | Who gave last year but not this year? | M | M (renewal campaigns) | `lapsed_donors` | F6 last transaction, `org.mailing` criteria; *contacts-liste-des-contacts*, *contacts-listes-de-contacts* |
| Q-OM-153 | Combien de donateurs ont donné à plus d'une campagne? | How many donors gave to more than one campaign? | M | M | `repeat_donors` (`# campaigns` > 1) | F6 |
| Q-OM-154 | L'historique complet de ce donateur | This donor's full history | M | M | `donor_lifetime_amount`, `donor_score` (`Customer.php:993-1013`) | F7; *contacts-fiche-d-un-contact* |
| Q-OM-155 | Ce donateur a-tu reçu/ouvert notre courriel? | Did this donor receive/open our email? | M | L | — | F7 communications tab |
| Q-OM-156 | Combien de contacts ont donné plus de 100 $ dans les 12 derniers mois? | How many contacts gave over $100 in the last 12 months? | M | L (advanced) | `segment_count` | `org.mailing` (`MailingListsTable.php:13-16`); *contacts-listes-de-contacts* |
| Q-OM-157 | Quelles entreprises nous ont donné? | Which companies donated? | M | L | — | F6 `is_company`; *contacts-liste-des-contacts* |
| Q-OM-158 | Combien de nouveaux donateurs cette campagne vs habitués? | New vs returning donors this campaign? | M | M | `new_vs_returning_donors` *(inferred; no screen)* | F6 "since" (gap: §9) |
| Q-OM-159 | Combien de parents se sont désabonnés de nos courriels? | How many parents unsubscribed from our emails? | E (O11 tab) / M (F7) | L | `unsubscribes_count` | O11 Unsubscribed; *communications-desabonnements*, eval sec-unsubscribed |

### 1.12 Communications

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-165 | Le courriel de rappel est-tu parti? À combien de monde? | Did the reminder email go out? To how many people? | A | H | `envoi_sent_count` | O9 `EnvoisTable.php:24-37`, D5; *communications-verifier-ce-qui-a-ete-envoye* |
| Q-OM-166 | C'est quoi le taux d'ouverture de notre dernier courriel? | Open rate of our last email? | A | M | `email_open_rate`, `email_click_rate` (`EnvoiList` opened/clicked scopes, `:145-157`) | D5, D15; *rapports-indicateurs* |
| Q-OM-167 | Quels envois ont échoué / été rejetés? | Which sends failed or were rejected? | A | L | `email_rejected_count` | O9 detail, D15 |
| Q-OM-168 | Ça fait combien de temps qu'on a rien envoyé? | How long since our last email? | A | M (the D5 nudge) | `days_since_last_envoi` | D5, D10 |
| Q-OM-169 | À quelle heure / quel jour nos courriels sont le plus ouverts? | Best hour/day for opens? | A (advanced) | L | `email_open_by_hour`, `email_open_by_weekday` | D15 (`EmailStatsService.php:28-60`) |
| Q-OM-170 | Quels SMS ont échoué? | Which SMS failed? | A | L | `sms_by_status` | O10 |
| Q-OM-171 | Quelle méthode de partage fait le plus vendre? | Which sharing channel sells best? | A (advanced) | L | `share_conversion_by_channel` | D13; *rapports-indicateurs* |
| Q-OM-172 | Combien ont ouvert l'infolettre? | How many opened the newsletter? | A (advanced) | L | `newsletter_opens` | `pages.list`; *communications-infolettres-envoyer* |

### 1.13 Several campaigns and year over year

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-OM-175 | Compare mes campagnes de l'année: laquelle a le plus rapporté? | Compare this year's campaigns: which earned most? | M (F3); A (F11 sales only) | M | `raised_gross`, `profit_net` per campaign | F3 `:30-77`, F11; *rapports-releve-toutes-les-campagnes* |
| Q-OM-176 | Combien on a amassé toutes campagnes confondues cette année? | Total raised across all campaigns this year? | M | M | `raised_gross` Σ team, `profit_net` Σ | F3 total row |
| Q-OM-177 | Cette année vs l'an passé pour notre vente de [produit]? | This year vs last year for our [product] sale? | M | M (most teams repeat annually; copy-campaign feature) | `raised_gross` YoY on matched campaigns | F3 (no YoY column), F11 (gap: §9); *demarrage-copier-une-campagne* |
| Q-OM-178 | Liste mes campagnes actives avec leurs ventes | List my active campaigns with their sales | A | M | `raised_gross`, `orders_paid_count` | F11; *demarrage-selecteur-de-campagne* |
| Q-OM-179 | Mes campagnes de l'an passé | My campaigns from last year | A | L | — | F11 (status, dates) |
| Q-OM-180 | Combien de donateurs on a eu au total depuis qu'on est sur Coolecto? | How many donors have we had in total since joining Coolecto? | M | L | `unique_donors` team | F6 count |

---

## 2. Organiser: editor ("Membre éditeur")

Editors share the owner/manager's campaign questions in §1.1–1.8 and §1.12. The differences:
- **No Finances or "Mon organisme" in the menu** (`DashboardSidebar.php:353,371`). They do see the **participants page** (`:336`) and **unmasked emails** (`canSeeEmails`, `User.php:354`).
- So §1.9–1.11 and Q-OM-060..074 are out of scope for an editor, **except** that `campaign.transactions` has no route role gate (`access.md` R-07).

Questions typical of editors (volunteer coordinators, teachers):

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-ED-001 | Donne-moi les courriels des parents des jeunes qui ont pas vendu | Give me the parents' emails of the kids who haven't sold | E | M | — | R5 without-sale + O11 emails; *participants-sans-vente-et-relance* |
| Q-ED-002 | Combien de jeunes dans ma classe ont vendu? | How many kids in my class sold? | E | H | `sellers_count` by group | R2, R5 group filter |
| Q-ED-003 | Combien d'adresses courriel sont liées aux participants? | How many emails are linked to participants? | E | L | `donors_count` (`getDonorsCount`) | O11 counter `:23-27` |
| Q-ED-004 | Le lien unique de chaque participant | Each participant's unique link | E | M | — | O11 export (`RecipientsExport`); *participants-rechercher-filtrer-et-exporter* (sensitive: the link is a credential) |
| Q-ED-005 | Qui sont les ambassadeurs de [participant]? | Who are [participant]'s ambassadors? | E | L | — | O11 Ambassadors tab; *participants-ambassadeurs* |
| Q-ED-006 | Liste de présences pour mon groupe | Attendance list for my group | E | M | — | O8 |
| Q-ED-007 | Quels participants sont désactivés? | Which participants are deactivated? | E | L | — | O11 (`was_disabled`); *participants-desactiver-ou-supprimer* |

## 3. Organiser: basic member ("Membre", read-only)

Basic members read campaign pages but have no "+ Campagne", no participants page, no finances (`DashboardSidebar.php:314,336,353`), and **masked emails** (`canSeeEmails`). They ask the §1.1–1.3 and §1.5 questions (results, products, groups, tickets).

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-BA-001 | On est rendus à combien? | Where are we at (amount)? | A | H | `raised_gross@total_sales` | D1 |
| Q-BA-002 | Combien de [produit] à distribuer à ma classe? | How many [product] to hand out to my class? | A | M | `qty_by_group_product` | R1 |
| Q-BA-003 | Quelle équipe est en tête? | Which team is ahead? | A | M | `group_rank` | R2 |
| Q-BA-004 | Comment je rembourse une commande au complet? (asks for a write) | How do I fully refund an order? | — | L (eval basic-04) | — | Not allowed: `OrderPolicy::refund` needs `isManager` (`OrderPolicy.php:68-71`) |
| Q-BA-005 | Liste-moi les acheteurs avec leurs courriels | List the buyers with their emails | A by URL (R4 unmasked), but intended **no** | L | — | R4 shows raw email and phone; see `access.md` R-01 |

## 4. Organiser: ticket scanner ("Scanneur de billet")

Scanners land on the QR reader (`DashboardView.php:84`). Their sidebar is only the campaign select (`DashboardSidebar.php:137-143`). Help article *billets-ajouter-un-scanneur-benevole* says: "no sales or organisation data". Their questions are door-time counts.

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-SC-001 | Combien de personnes sont entrées jusqu'à maintenant? | How many people have come in so far? | scanner (count only) | H at the door | `tickets_scanned` | O1/O4 counts |
| Q-SC-002 | Combien il en reste à arriver? | How many still to arrive? | scanner (count only) | H | `tickets_sold − tickets_scanned` | O1 |
| Q-SC-003 | Ce billet a-tu déjà été scanné? | Was this ticket already scanned? | scanner | H | — | `QrCodeConfirmationModal.php:29-39` (scan flow, not a data question) |
| Q-SC-004 | Combien j'ai scanné de billets ce soir? | How many tickets did I scan tonight? | scanner (own session) | M | `scans_by_scanner` (own) | O3 |
| Q-SC-005 | C'est quoi la date/l'heure de l'événement qu'on scanne? | Which event date are we scanning? | scanner | L | — | O1 date selector |
| Q-SC-006 | Est-ce que [nom] est arrivé? | Has [name] arrived? | intended **no** (names); app: O2 by URL | L | — | O2; see `access.md` R-02 |

## 5. Agent ("Représentant") and network parent

**Two askers:**
- a **manager of a parent team** (20 parent teams locally): network pages over `descendantTeamIds()`;
- an **agent**: `team_user.role='agent'` with `from_team_id` = the parent (44 of 46 agent rows). An agent switched into a child team acts in that child with agent rights (`HasTeamsTrait.php:133-178`). They get "Mon organisme" and child-organisation pages (`DashboardSidebar.php:100,371`), but no Finances and no unmasked emails.

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-NW-001 | Combien le réseau a perçu cette année scolaire? | How much has the network collected this school year? | N | H for network staff | `network_collected` (Σ `orders.final_amount` paid, N1 `:138-145`) | N1; *compte-reseau-d-organismes* |
| Q-NW-002 | Combien a été déposé au réseau cette année, combien est en attente? | Deposited to the network this year, how much pending? | N (N2 has no gate) | M | `network_deposited`, `network_pending` | N1 `:147-158`, N2 `:63-92`; *transferts-depots-du-reseau* |
| Q-NW-003 | Quelles écoles ont jamais démarré / sont dormantes? | Which schools never started / are dormant? | N | M | `child_status_counts` | N1 `:305-340`, N3 `:171`; *compte-reseau-d-organismes* |
| Q-NW-004 | Combien de campagnes attendent mon approbation? | How many campaigns await my approval? | N | M | `pending_approvals_count` | N8, N1 to-handle; *compte-demandes-a-traiter* |
| Q-NW-005 | Quelles campagnes sont en cours dans le réseau? | Which campaigns are running in the network? | N | M | — | N4, N1; *compte-reseau-d-organismes* |
| Q-NW-006 | Classe les écoles par montant amassé | Rank schools by amount collected | N | M | `child_collected` (N3 uses the OD sum; N1/N5/N6 use `final_amount`) | N3 `:88` |
| Q-NW-007 | Combien a amassé l'école X? Ses campagnes? | How much did school X collect? Its campaigns? | N (N5/N6: any ancestor role) | M | `child_collected`, per-campaign `final_amount` sum | N5 `Order.php:1489`, N6 `:169` |
| Q-NW-008 | Toutes les inscriptions du réseau par centre | All network registrations by centre | N | M (inscription networks) | `registrations_count` by team | N7 `:32,100-117`; *inscriptions-reseau-liste-des-inscriptions* |
| Q-NW-009 | Combien de frais de plateforme le réseau a payé? | Platform fees paid by the network? | N (N2 no gate) | L | `network_platform_fees` (`NetworkDepositsPage.php:132-147`) | N2 |
| Q-NW-010 | Qui est responsable de l'école X? Y en a-tu sans responsable? | Who is responsible for school X? Any without one? | N | L | `no_responsible_count` (`Team.php:452`) | N3, N6 `:145-160` |
| Q-NW-011 | Les campagnes du réseau dans le calendrier de l'année | Network campaigns on the year calendar | N | L | — | N8 calendar |
| Q-NW-012 | (agent in a child) Combien la campagne de cette école a ramassé? | (agent) How much did this school's campaign raise? | agent in child = A | M | `raised_gross` | D1 (agent acts as staff of the child) |
| Q-NW-013 | Combien de comptes enfants ont un compte bancaire pas vérifié? | How many child organisations have an unverified bank account? | N | L | `child_bank_status` *(inferred)* | N3 (account setup), *compte-reseau-d-organismes* |
| Q-NW-014 | Compare les écoles du réseau sur le taux de participation des élèves | Compare network schools on student participation | N | L | `participation_pct` (N5) | N5 `:55-64` |

---

## 6. Buyers and donors (later)

Mostly guests: 200k of 202k orders have no `user_id`. A logged-in donor is in customer mode and sees orders of customers matched **by email or phone across all teams** (`HasLinkedCustomers.php:12-30`). Eval buyx-05 shows the boundary: a buyer asking for "all the buyers of the campaign" must get nothing.

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-BU-001 | Combien j'ai donné cette année? | How much did I give this year? | B | M (tax season, *inferred*) | `my_given_12m` (rolling 12 months) / calendar year | B1 `MyProfileDashboard.php:36-44`; *compte-espace-donateur* |
| Q-BU-002 | Liste mes achats / mes dons | List my purchases / donations | B | M | — | B2; *compte-espace-donateur* |
| Q-BU-003 | Où est mon reçu d'impôt? Quel montant admissible? | Where is my tax receipt? Eligible amount? | B | M (Feb–Apr) | `my_tax_receipts` | B3, B4; *recu-recu-d-impot* |
| Q-BU-004 | Combien il me reste à payer, prochain versement quand? | How much is left to pay, next installment when? | B | M (installment plans) | `my_installments_remaining` | B4 `:97-155`; *recu-page-de-recu*, *inscription-payer-en-versements* |
| Q-BU-005 | Mon versement a-tu passé? | Did my installment go through? | B | L | — | B4; *inscription-versement-refuse* |
| Q-BU-006 | Mon remboursement a-tu été fait? | Was I refunded? | B | M | `my_refunds` | B2 (paid orders only; refunds as negatives *inferred*); *achat-remboursement-annulation* |
| Q-BU-007 | Ma commande sera livrée quand? | When will my order be delivered? | B | M | — | B4 delivery card; *achat-dates-de-livraison* |
| Q-BU-008 | Sur quels lots je mène? Qu'est-ce que j'ai gagné? | Which lots am I leading? What did I win? | B | M during auctions | `my_leading_lots` | *encan-mise-surpassee-et-gagner*, *encan-payer-ses-lots* (no buyer list screen besides the lot page) |
| Q-BU-009 | C'est quoi mes numéros de tirage? Qui a gagné? | What are my raffle numbers? Who won? | B | L | — | B4 tickets; *tirage-acheter-des-billets* |
| Q-BU-010 | Combien vaut encore mon certificat cadeau? | What's left on my gift certificate? | B | L | `voucher_remaining` (`Voucher.php:67-70`) | `gift-certificate` route; *inscription-utiliser-un-certificat-cadeau* |
| Q-BU-011 | Mon don mensuel, il est rendu à combien en tout? | My monthly gift: what's the total so far? | B | L | `recurring_total` | B2 (no recurring view for buyers; gap); *don-mensuel-ou-annuel* |
| Q-BU-012 | (refused) Liste de tous les acheteurs de la campagne | (refused) List all the campaign's buyers | — | L (eval buyx-05) | — | Not allowed |

## 7. Participants and captains (later)

Many have no account and use signed links (`participant.dashboard`, `group.dashboard`). P2P participants log in. Participants can be **minors**. Eval partx-02: a participant asking for "the whole school's sales report" must be refused.

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-PA-001 | J'ai amassé combien jusqu'à maintenant? | How much have I raised so far? | P | H | `my_raised` (P3 `goal_sales` without fees/tips; P1 total sales) | P1 `Recipient.php:1129`, P3 `:579`; *tableau-de-bord-participant-p2p*, *tableau-de-bord-participant-vente* |
| Q-PA-002 | Il me manque combien pour mon objectif / mon prochain palier? | How much to my goal / next tier? | P | H | `my_goal_remaining` | P3 hero; *objectif-personnel*, *tableau-de-bord-paliers-et-badges* |
| Q-PA-003 | Combien j'ai vendu cette semaine? | How much did I sell this week? | P | H | `my_sales_week` | P1 week pulse |
| Q-PA-004 | Qui a acheté par mon lien? / Mes derniers dons | Who bought through my link? / My latest gifts | P | H | — | P1 transactions, P3 latest gifts (anonymity: see `access.md` R-09) |
| Q-PA-005 | Qui j'ai pas encore remercié? | Whom haven't I thanked yet? | P | M | — | P3; *tableau-de-bord-dons-recus-et-remercier* |
| Q-PA-006 | Je suis à quel rang dans mon équipe / mon équipe est où au classement? | My rank in my team / my team's rank? | P | H | `my_rank`, `team_rank` | P1 rank card, P3 team; *classement-participants-et-equipes* (names only if `showsLeaderboardNames`) |
| Q-PA-007 | Combien de personnes ont ouvert mon lien? | How many people opened my link? | P | M | `my_link_opens` | P1 share funnel (`ParticipantMomentumService.php:193`) |
| Q-PA-008 | C'est quoi ma part de profit? | What is my profit share? | P | M | `my_profit_share` | P1; *tableau-de-bord-participant-vente* |
| Q-PA-009 | Mes campagnes P2P et mon montant dans chacune | My P2P campaigns and my amount in each | P | L | `my_raised` per campaign | P5; *compte-mes-campagnes-p2p* |
| Q-PA-010 | Quels badges il me reste à gagner? | Which badges are left to earn? | P | L | — | P3 badges |
| Q-CA-001 | (capitaine) Qui dans mon équipe a pas commencé / est arrêté depuis une semaine? | (captain) Who on my team hasn't started / stopped a week ago? | C | H for captains | `captain_todo` (`P2PCaptainService.php:60,116`) | P4; *capitaine-tableau-de-bord-equipe*, *capitaine-gerer-un-membre* |
| Q-CA-002 | Combien d'inscriptions restent à payer dans mon équipe? | How many registrations are unpaid on my team? | C | M | `team_unpaid_registrations` | P4 members |
| Q-CA-003 | Notre équipe a ramassé combien, on est à quel rang? | How much has our team raised, what's our rank? | C | H | `team_total`, `team_rank` (`CampaignTeamsLeaderboardService.php:86`) | P4, P2 |
| Q-CA-004 | Combien de membres de l'équipe ont des dons? | How many team members have gifts? | C | M | `team_members_with_gifts` | P4 "N / M" |
| Q-PA-011 | (refused) Le rapport des ventes de toute l'école | (refused) The whole school's sales report | — | L (eval partx-02) | — | Not allowed |

## 8. Suppliers (later)

Supplier mode, with `role:supplier` = the current team has a `suppliers` row (`RoleMiddleware.php:27-31`). "Se faire payer" (payouts, statistics) is manager-only (`DashboardSidebar.php:229`).

| # | FR | EN | Who | Freq | Metric | Evidence |
|---|---|---|---|---|---|---|
| Q-SU-001 | Combien de produits j'ai vendus cette année vs l'an passé? | Units sold this year vs last? | S | M | `supplier_units_yoy` | S1 `:48`; *tableau-de-bord-vue-d-ensemble* |
| Q-SU-002 | C'est quoi mon meilleur produit? | My best-selling product? | S | M | `supplier_best_sellers` | S2; *statistiques-fournisseur* |
| Q-SU-003 | Mes ventes par mois / par type d'organisme | My sales per month / per org type | S | M | `supplier_sales_monthly`, `supplier_sales_by_org_type` | S2 |
| Q-SU-004 | C'est quoi mon taux de réachat? Mon panier moyen? | Repeat rate? Average basket? | S | L | `supplier_repeat_rate`, `supplier_avg_basket` (`SupplierStatisticsPage.php:146-188`) | S2 |
| Q-SU-005 | Coolecto me doit combien? J'ai été payé combien cette année? | How much does Coolecto owe me? Paid this year? | S (manager) | H | `supplier_balance`, `supplier_paid_ytd` | S6 `:212-249`; *versements-etat-de-compte* |
| Q-SU-006 | Quand je vais être payé pour la livraison de l'école X? | When will I be paid for school X's delivery? | S (manager) | M (eval sup-04) | — | S6, S7; *versements-quand-suis-je-paye* |
| Q-SU-007 | Combien je dois produire pour la semaine du 3 novembre? | How much to produce for the week of Nov 3? | S | H in season | `production_units_week` (uses `products.supplier_id`) | S5; *production-par-semaine* |
| Q-SU-008 | Mes livraisons de la semaine prochaine / à confirmer | My deliveries next week / to confirm | S | H | `deliveries_to_confirm` | S1 `:91`, S4; *livraisons-liste*, *livraisons-agenda* |
| Q-SU-009 | Quels clients ont une configuration incomplète (banque)? | Which clients have incomplete setup (bank)? | S | M | `clients_setup_incomplete` (`SupplierClientsQuery.php:284`) | S3; *clients-mes-clients* |
| Q-SU-010 | Mes ventes par client | My sales per client | S | M | `supplier_client_sales` (Σ `deliveries.net_amount`) | S3 `:304`; *clients-mes-clients* |
| Q-SU-011 | Combien d'organismes clients, nouveaux ce mois-ci? | How many client organisations, new this month? | S | L | `supplier_clients_count` | S1 `:79` |
| Q-SU-012 | Quel modèle de campagne a été le plus copié? | Which template campaign was copied the most? | S | L | `template_copies` | `supplier.template-campaigns`; *campagnes-modeles-liste* |
| Q-SU-013 | Combien de produits à livrer pour la campagne de l'école X? | Units to deliver for school X's campaign? | S | H | `qty_supplier_picking` | R14 in supplier mode (`supplier.orders.report`) |
| Q-SU-014 | C'est quoi ma note de satisfaction? | My satisfaction rating? | S | L | `supplier_satisfaction` | S2 `:168-176` |

---

## 9. Gaps: questions people will ask that no screen answers cleanly

These need a new calculation, or a careful definition, in `metrics.md`:

1. **Unpaid / open orders list (Q-OM-065).** `campaign.transactions` hides OPEN and CANCELED (`Order::showInListStatuses`, `Order.php:539-547`). Only the 30-day funnel counts them (D14). Eval data-03 asks exactly this.
2. **Quantity sold and remaining stock for non-ticket products (Q-OM-025).** The products page has no sold column (`CampaignPageProducts.php:57-67`). It has to be derived from order lines.
3. **Year over year (Q-OM-177).** No screen pairs a campaign with last year's copy (`campaigns.from_campaign_id` exists, `Campaign.php:451`).
4. **New vs returning donors, lapsed donors (Q-OM-152, Q-OM-158).** These need first-order dates per customer across the team's campaigns.
5. **Transactions without a tax receipt, and why (Q-OM-129).**
6. **Calendar-year vs rolling-12-month giving (Q-BU-001).** The app's "this year" is a rolling 365 days (`Customer.php:659`).
7. **Next recurring charge date.** `TeamRecurringDonationsTable` shows none.
8. **Network totals.** "Collected" differs by screen:
   - N1, N5, N6: Σ `final_amount`;
   - N3: OD sum;
   - N2: bill items.

## 10. Metric divergences to settle in `metrics.md`

The same words map to different SQL depending on the screen. An answer should name the definition it uses, and match the screen the user will check against.

- **"Raised / total":** there are four definitions.
  - `total_sales`: includes manual payments, tips, covered fees, P2P registrations, related campaigns, discount added back (`CampaignStatsService.php:157`, `OrderDetail.php:35`).
  - `CampaignDashboardService::paidDetails`: follows the campaign's registration flag.
  - `goal_sales`: P2P excludes covered fees and tips; a profit goal uses profits.
  - Leaderboard and portal services: exclude fees and tips.
- **Totals by report:**
  - R2 KPI uses plain `SUM(total_amount)` (no discount add-back).
  - R5 rows exclude manual payments while its KPIs include them.
  - R4 row amounts are not filtered by order status.
- **"Paid":** `paidStatuses()` (`Order.php:577`) includes refund and dispute rows that net out.
- **"Profit %":** reads the cached `precalculated_profits`; "Profit $" is live.
- **"Additional donations":** D12 includes cover fees, tips, delivery fees and balance payments (`OrderDetail.php:87`). F1/F2 split them.
- **"Tickets sold":** `Reservation::getSoldTickets` counts reservations with an order, paid or not. `scopeValid` is the strict version.
- **"Deposit count":** distinct (`sent_at`, `destination_team_id`), not transfers.
- **Supplier products:** production uses `products.supplier_id`; every other supplier screen uses `deliverables.supplier_id`.

## Counts

| Section | Questions |
|---|---|
| 1 Owner/manager | 143 |
| 2 Editor | 7 |
| 3 Basic | 5 |
| 4 Scanner | 6 |
| 5 Agent/network | 14 |
| 6 Buyer | 12 |
| 7 Participant/captain | 15 |
| 8 Supplier | 14 |
| **Total** | **216** |

Of the 143 owner/manager rows, about 30 are list or lookup questions (Who = M) that a v1 might defer.

**Recommended v1 core (staff, aggregates only, no PII):**
- Q-OM-001..010, 020..024, 031..036, 065, 075..077, 090..094, 100, 105, 115, 118, 125..126, 135..141, 165..166, 175..178;
- Q-NW-001..006.
