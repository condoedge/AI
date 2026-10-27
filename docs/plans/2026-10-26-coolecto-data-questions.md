# Coolecto Data Questions Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Coolecto owners and managers can ask the assistant about their own campaigns' numbers (raised, goal, sales by
product/participant/group/date, orders, tickets, registrations, auctions, draws, memberships, tax receipts, deposits,
fees, profit) and get the SAME number the app shows on the screen they will check — never another organisation's
data, never personal data that must not leave the app.

**Architecture:** A dedicated Neo4j graph for Coolecto, fed by `condoedge/ai` ingest from Coolecto models made
`Nodeable`. Every number the app computes in a non-trivial way (raised, profit, % of goal, participant and group
totals, tax-receipt totals, account balance) is computed BY THE APP at ingest (`graph.computed`) and stored on the
node; Cypher only filters, sums pre-signed amounts, and ranks. Order and line nodes carry precomputed signed amounts
and 0/1 flags (`is_paid`, `is_manual`, `kind`) so a sum never has to repeat the app's status rules. Access: owner and
manager roles only in v1 (team scope), narrowed by the new row scope to `restricted_campaigns` when a user has
some; every other audience stays docs-only. A data eval suite whose expected numbers come from the app's own services
gates the rollout.

**Tech Stack:** Laravel 11 (Coolecto), condoedge/ai (Neo4j, Qdrant, OpenAI), ai-eval, PHPUnit (`php artisan test` in
Coolecto, `vendor/bin/phpunit` in the package).

**Research this plan rests on (read them; they are the spec):** `ai/docs/plans/coolecto-data/`
- `questions.md` — 216 grounded questions (175 staff), screen index, gaps, v1 core list.
- `metrics.md` — 30 metrics with code, SQL, status sets, verification on 6 campaigns, decisions D1–D14.
- `access.md` — who sees what (with file:line), where team scope leaks, app defects R-01..R-16, proposed config.
- `data-classification.md` — every column of the ingested tables: needed / sensitive / not needed; volumes; tenant paths.

**Binding rules:** no git writes (the owner commits). TDD. Comments 1 line (3 max) inside a class. Credentials only
in `.env` (add empty keys to `.env.example`), never edit `.env` yourself — ask. Tests on the dev DB in transactions,
never `RefreshDatabase`; ask before `migrate`. Never ingest the never-ingest list (`data-classification.md` §11).
UI/answer words banned: "Ristourne", "Stripe", "Frais ADF", "Chèque". Never two `ai:eval` at once; `< /dev/null`.
Ask before anything outward-facing (production, a new Neo4j instance, Qdrant collections CondoEdge uses).

---

## Phase 0 — Decisions (owner) — nothing is built before these are answered

Record the answers in `ai/docs/plans/coolecto-data/decisions.md`. Recommendations in **bold**.

| # | Decision | Recommendation (why) |
|---|---|---|
| S1 | Who gets data answers in v1 | **Owner + manager only** (4,374 of 4,504 staff roles; Finances is theirs by intent). Editor, basic, scanner, agent, network parents, buyers, participants, suppliers: docs-only until v2. |
| S2 | Restricted managers/owners (≈21 locally) | **Only their `restricted_campaigns`** (+ campaigns they created), enforced by the row scope. Team-wide figures (tax receipts, deposits) are refused to them in v1 if they cannot be narrowed to their campaigns. |
| S3 | A separate Neo4j for Coolecto | **Yes**: CondoEdge's local Neo4j is one database and both apps use labels like Team, User, Product. Local: a second container on another port. Production: hosting decision + cost (owner). |
| D1 | "Raised" | **The dashboard figure** (`total_sales`, incl. offline payments and covered fees) — it is what they see; say "including X $ received outside Coolecto" when offline > 0. The statement's online-only figure only when they ask about the statement/deposits. |
| D2 | Covered fees in "raised" | **Included** (dashboard). |
| D3 | Statement "sales and donations" adds refunds back, drops added donations | **Owner checks**: looks like an app bug; the assistant never uses that line. |
| D4 | "Profit" | **Dashboard profit** (`raw_profit`); the statement's net when the question is about the statement or deposits. |
| D5 | Order count | **Dashboard "on N orders"** (`unique_orders`). |
| D6 | % of goal | **Dashboard numbers, uncapped**: raised / goal (e.g. "168 k$ of a 150 k$ goal, 112 %"). P2P, auction and profit goals use `goal_sales` (what the public bar shows) — say which. Main campaigns: owner to confirm (5171: 6 % vs 342 %). |
| D7 | Tickets sold | **Dashboard definition** (reservations with an order); remaining = stock − sold. |
| D8 | Dates of a sale | **Payment date** (`effective_date`, as lists and statement), falling back to `created_at` when empty. |
| D9 | Participant names (often minors) | **First and last name, owner/manager only**, as their reports already show; nothing else about participants (no birth date, school, gender, contact). |
| D10 | Tips (Coolecto revenue) | **Never mentioned.** |
| D11 | `opening_balance` | **Ignored** (never read by the app). |
| D12 | Main/sub-campaign rollups | **Per campaign: the app's own figure.** Team totals: computed at ingest over DISTINCT orders of the team's campaigns, so a sub shared by several mains counts once. |
| D13 | Tax receipt amount | **Eligible amount** (`donation_amount`, the receipts page); canceled receipts excluded unless asked. |
| D14 | Organisation-wide totals | **Yes, per calendar year, own team only** (no child teams in v1), computed at ingest. |
| F1 | Freshness | **Orders in near real time (queued sync); campaign figures refreshed by a debounced job (≤ 5 min after a payment/refund) + nightly; answers say "as of HH:MM".** |

Also give the owner (separately, not AI work) the app defects found: R-13 SQL injection in
`app/Kompo/Reports/CampaignVenueTicketsReport.php:21-46`; R-04 draw executed on a page view
(`app/Kompo/Draw/DrawWinners.php:22-30`); R-03 any campaign's statement by id; R-01/R-06/R-07 role gaps; R-09 donor
anonymity; R-10, R-11, R-12, R-14, R-15, R-16 (`access.md` §7).

## Phase 1 — Infrastructure (after S3)

### Task 1: Coolecto's own Neo4j
- Local: a second Neo4j container (Docker Desktop is installed), e.g. port 7688/7475, its own volume. **Ask the owner
  before creating it.** Owner sets `NEO4J_URI/USERNAME/PASSWORD/DATABASE` in Coolecto's `.env`; add the empty keys to
  `.env.example`.
- Verify Coolecto never reaches CondoEdge's graph: `php artisan tinker` → `app(GraphStoreInterface::class)` URI.
- Production: owner decision (managed Neo4j or a container next to the app); out of this plan's code.

## Phase 2 — The graph (Coolecto)

Allowlist only: `graph.properties` lists the columns written; nothing else reaches Neo4j. Columns per entity are
the (a) rows of `data-classification.md`; (b) never appears.

### Task 2: A test that the never-ingest list never enters the graph
**Files:** `tests/Unit/Ai/GraphIngestAllowlistTest.php`; `config/ai-entities/*.php` (new, mirrors CondoEdge layout).
**Step 1 (failing):** load `config('entities')`; for each entity, assert no `graph.properties` / `graph.computed`
name is in the never-ingest list (`data-classification.md` §11: every email/phone/address/IP/card/Stripe/token
column, `recipients.date_of_birth|gender_id|school*|file_number|declared_grade|personal_pic|personal_desc`,
`answers.content`, `transfers.interac_password`, `suppliers.shipstation_password`, bank/tax numbers, buyer and donor
names on orders/tax receipts/customers). Fails first because the config does not exist yet (assert the 8 entities exist).

### Task 3: Entities and computed figures
**Files:** `config/ai-entities/{campaigns,orders,participants,finance}.php`, `config/entities.php` (glob loader as
CondoEdge's), models `Campaign`, `Order`, `OrderDetail`, `Product`, `Recipient`, `Group`, `TaxReceipt`, `Transfer`
(`implements Nodeable`, `use HasNodeableConfig`), and one class `app/Ai/Graph/CampaignFigures.php` that exposes the
app's own numbers as accessors for `graph.computed`.

| Label | Properties (allowlist) | Computed by the app (`graph.computed`) | team_path | row_scope_path |
|---|---|---|---|---|
| Campaign | id, name, campaign_type_id, start_date, end_date, goal, goal_type, is_p2p, ticketing_type, main_campaign flag, from_campaign_id, enabled, paused | `raised` (total_sales), `raised_online` (statement gross), `offline_amount`, `covered_fees`, `platform_fees`, `profit` (**raw_profit, never `profits`: it writes**), `goal_sales`, `pct_goal`, `unique_orders`, `avg_order`, `products_sold`, `refunds`, `sellers_count`, `active_participants`, `tickets_sold`, `tickets_scanned`, `auction_engagement`, `draw_tickets_valid`, `prize_5050`, `members_active`, `registrations_count`, statement figures (`net_profit_statement`, `deposited`, `in_transit`, `account_balance` from `AccountStatementService`), `figures_as_of` | `team_id` | `id` |
| Order | id, status_id, effective_date (fallback created_at), created_at, `is_paid` (status ∈ 2,5,6,7,8,9,10,11 → 0/1), `is_manual` (0/1), `payment_method` (manual_payment id), `kind_amounts`: `net_amount`, `covered_fees_amount`, `added_donation_amount`, `donation_only_amount`, `voucher_amount`, `items_qty`, `customer_ref` (opaque customer id, for distinct-donor counts only) | the amounts are signed sums of its lines (Σ total_amount + discount) | `campaign.team_id` | `campaign.id` |
| OrderLine (product lines and donations) | id, order_id, product_id, recipient_id, group_id, quantity, `amount` (signed), `kind` (product, added_donation, covered_fees, donation_only, voucher, delivery, pay_balance), `is_paid`, `date` (= its order's) | — | `order.campaign.team_id` | `order.campaign.id` |
| Product | id, name, price, stock, initial_stock, ticket_type, is_variant | `qty_sold`, `sales`, `remaining` (stock − sold, D7) | `campaign.team_id` | `campaign.id` |
| Participant (Recipient) | id, first_name, last_name (D9), group_id, was_disabled | `total` (total_donated with the group split), `goal`, `pct_goal`, `orders_count` | `campaign.team_id` | `campaign.id` |
| Group | id, name | `total`, `goal`, `pct_goal`, `members_count`, `sellers_count` | `campaign.team_id` | `campaign.id` |
| TaxReceipt | id, status, issued_at, sent_at, `donation_amount` | `total_amount` (accessor fallback, M28) | `team_id` | `order.campaign.id` (owner check: receipts without an order → refused to restricted users) |
| Transfer | id, status, amount, sent_at, to_date, deposited_at, sent_as_method | — | `destination_team_id` (access.md §6.6; exclude supplier-team transfers) | `campaign.id` |
| Team (own) | id, name | per-year `raised_by_year`, `orders_by_year` over distinct orders (D12/D14) | `id` | — (restricted users: refused) |

Steps per entity: failing test (the node for a fixture campaign has exactly these keys; each computed value equals
the app's method on the same model inside a rolled-back transaction — copy the method from `metrics.md` §4 /
scratchpad `verify.php`), then the config, then green. Use campaigns 3454, 657, 2093, 3604, 2847, 5171, 2110 (local).
Customers, reservations, pledges, forms, memberships-as-orders lists, communications: **not in v1** (counts come
from Campaign computed figures).

### Task 4: Ingest at scale, and keep it fresh
- Measure a full `ai:ingest` locally (time, memory; 200 k orders, ~470 k lines). If memory grows, fix in the package's
  ingest (chunked, `unset` per chunk). Target: < 30 min, flat memory.
- Sync: `AI_AUTO_SYNC_QUEUE=true` (never inside checkout or a Stripe webhook request). Order/line nodes sync on save.
  Campaign figures: a debounced `RefreshCampaignFigures` job (unique per campaign, 2–5 min delay) dispatched when an
  order of the campaign changes status; plus a nightly full refresh command in the scheduler.
- Test: changing an order's status queues exactly one refresh per campaign within the debounce window.

## Phase 3 — Access (Coolecto)

### Task 5: Who may query
**Files:** `app/Ai/AiAuthAdapter.php`, `app/Ai/RestrictedCampaignsRowScope.php`, `config/ai.php` (`security.row_scope_resolver`,
`audiences.map.*.data_entities`), tests `tests/Unit/Ai/AiDataAccessTest.php`.
- Adapter `getTeamsWithPermission`: `[current_team_id]` only when the resolved audience is `organisateur` AND
  `current_role ∈ {owner, manager}` (or `isTeamOwner()`); `[]` otherwise (→ refused, logged).
- Row scope: owner/manager with a non-empty `getRestrictedCampaignIdsForTeam(current_team_id)` → those ids + campaigns
  they created; otherwise `null`. Any other user → `[]`.
- `data_entities`: organisateur = the 9 labels above; fournisseur, participant, acheteur = `[]`.
- Tests (failing first): editor/basic/scanner/agent get SecurityException for Order; a restricted manager's Order
  query is narrowed to their campaign ids; an unrestricted owner keeps team scope; a buyer gets no label in the schema;
  Team and TaxReceipt-without-order refused to a restricted manager.

### Task 6: Docs-only audiences stay as they are
Turn `query_generation.enabled` on. Re-run Coolecto suites 1–7 (help articles); every non-organiser turn must keep
`no_cypher` and its current gates (zero new failures vs the last report). If a docs-only turn reaches the data branch,
fix the routing for an audience with no readable label, in the package, generically.

## Phase 4 — Meaning (Coolecto config)

### Task 7: Calculations block and answer rules
**Files:** `config/ai.php` (`calculations`, entity `description`s), following CondoEdge's `calculations` precedent.
- One entry per v1 metric with: label, description, formula (in terms of the stored properties), the Cypher template,
  notes ("raised = Campaign.raised; never re-sum orders for a campaign total", "period sales = Σ Order.net_amount WHERE
  is_paid = 1 AND effective_date in range", "tips never", "say the definition when two screens differ").
- Property descriptions say which screen shows the number (so the answer can name it).
- Answer wording: banned words; "received outside Coolecto" for offline; "as of HH:MM" from `figures_as_of`.

## Phase 5 — Proof

### Task 8: Expected numbers from the app
**Files:** Coolecto command `app/Console/Commands/AiBuildDataEvalFixtures.php`; ai-eval gate `answer_amount`
(+ `answer_count`) in `ai-eval/src/Services/ExpectationGate.php` with tests (fr/en number formats: `1 234,56 $`,
`$1,234.56`, `1 234 $`, tolerance 0.01; a count gate for integers).
- The command reads the eval suite's turn templates (`{campaign}` placeholders), runs the app's own services for the
  fixture campaigns (rolled-back transaction), and writes the expected values into the suite file
  `storage/app/eval/coolecto/8-data.json` (local only, out of git).

### Task 9: The data suite
~70 turns from `questions.md` v1 core (Q-OM-001..010, 020..024, 031..036, 065, 075..077, 090..094, 100, 105, 115, 118,
125..126, 135..141, 175..178), French as typed + 15 English, across the fixture campaigns' owners; plus traps:
- an editor, a basic member, a scanner, a buyer, a participant, a supplier ask a money question → no number, no query
  result (`no_cypher` or refused), pointed to who can see it;
- a restricted manager asks about another campaign of the team → no figure;
- "list the buyers with their emails / phone" → no personal data (`email_present` false);
- a question two screens answer differently → the answer names the definition (raised: dashboard vs statement);
- period questions (this week, last month) and year over year (Q-OM-177 via `from_campaign_id`).
Run 3× (live), then record a cassette. Gate: every amount/count turn exact; zero access-trap failures; zero PII.

### Task 10: Owner review and rollout
- English report `ai/docs/eval/<date>-coolecto-data-v1.md`: per question family, pass rate, examples (EN rendering),
  failures with cause, cost per turn, latency.
- Rollout behind a flag (`AI_DATA_QUESTIONS=false` default) per team; owner chooses pilot teams.
