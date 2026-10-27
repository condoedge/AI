# Coolecto metric dictionary (for AI data questions over a Neo4j graph)

Research date: 2026-10-26. Read-only study of `ActiviteDeFinancement` (branch `feat/ai-integration`).
Every path below is relative to `C:\Users\jkend\Documents\Projects\kompo\ActiviteDeFinancement\`.
DB facts come from SELECT-only aggregates on the local `financement` DB (copy of prod data).
Every app method was run inside a transaction that was rolled back.
"Inferred" marks a statement I did not see in code or data.

The main risk this document addresses is **one word, several numbers**. "Raised", "profit", "orders" and "% of goal" each have 2 to 4 different definitions in the app, depending on the screen. Section 3 lists them. Section 6 lists what the owner must decide.

---

## 1. Ground rules shared by every money metric

### 1.1 The line amount
Money is summed on **order lines** (`order_details`), not on `orders.final_amount`:

```
line_amount = order_details.total_amount + IFNULL(order_details.discount_amount, 0)
```
`OrderDetail::SQL_SINGLE_AMOUNT` / `SQL_SUM_TOTAL_AMOUNT` (app/Models/OrderDetail.php:34-35), `OrderDetail::getTotalAmount()` (OrderDetail.php:785).
`discount_amount` is filled on only 69 of 501,434 lines. A few call sites sum `total_amount` alone; they are flagged where it matters (participant totals, draw report, refund amounts).

### 1.2 Order statuses (`orders.status_id`, app/Models/Order.php:46-59)

| id | const | meaning | rows (local) | sum final_amount |
|---|---|---|---|---|
| 1 | OPEN | cart / not paid | 10,912 | null |
| 2 | PAID | paid | 182,062 | 12,742,999.98 |
| 3 | REJECTED | payment refused | 47 | 0 |
| 4 | CANCELED | abandoned | 151 | null |
| 5 | REFUND | **negative** refund order (`refunded_id` → original) | 4,219 | −152,410.23 |
| 6 | REFUNDED | original order that was refunded (fully or partly) | 2,822 | 268,351.46 |
| 7 | DISPUTE | **negative** dispute order (`disputed_id` → original) | 189 | −10,691.47 |
| 8 | IN_DISPUTE | original order being disputed | 165 | 8,965.99 |
| 9 | DISPUTE_WON | original, dispute won | 31 | 2,202.10 |
| 10 | DISPUTE_LOST | original, dispute lost | 14 | 469.76 |
| 11 | DISPUTE_REVERSAL | **positive** reversal order after a won dispute | 92 | 4,989.43 |
| 20 | TIPS | separate order holding a tip to Coolecto | 1,054 | 5,206.08 |
| 21 | TIP_REFUND | negative tip refund | 5 | −44.55 |
| 30 | ON_HOLD | on hold | 1 | null |

Status sets the code uses (all in Order.php):

| set | statuses | file:line | used by |
|---|---|---|---|
| `paidStatuses()` / `scopePaid` | 2,5,6,7,8,9,10,11 | 577, 1278 | **every money total** (net of refunds/disputes because 5/7 are negative) |
| `collectedMoneyStatuses()` / `scopeCollected` | 2,6,7,8,9,10,11 (no 5) | 591, 1298 | `unique_orders` (paid ∩ collected) |
| `showInListStatuses()` | 2,6,8,9,10 | 539 | transactions page rows, account-statement tab |
| `receiptableStatuses()` | 2,6,9 | 550 | receipts |
| `refundableStatuses()` | 2,6,8,9 | 629 | refund action |
| `disputeStatuses()` | 8,9,10 | 604 | dispute counters |
| `scopeForDisputeCalculation` | 7,9,10 | 1315 | account-statement "disputes" |
| `scopeAreTips` | 20,21 | 1336 | tips |

**How refunds and disputes are recorded (ledger model).** Nothing is deleted or rewritten. The original order goes to 6 (refunded) or 8/9/10 (dispute), and its lines keep their positive amounts. A refund line that is fully refunded gets `valid=2`; a disputed line gets `valid=3`. A **new negative order** is created (status 5 or 7) with negative copies of the lines (`valid=1`). A won dispute adds a positive reversal order (11).
Code: app/Models/Finance/PerformsRefundsTrait.php:83-112; OrderDetail.php:1080-1117 (refund), 1119-1131 (full refund marks valid=2), 1203-1224 (dispute: valid=3, plus a $15 fee spread on the dispute lines, `Order::DISPUTE_FEES_AMOUNT`, Order.php:83), 1321-1355 (negative copies).
So summing lines over `paidStatuses` gives the **net** amount. Summing only status 2 misses the refunded originals (6), and summing 2+6 without 5 ignores the refunds.

### 1.3 Line validity (`order_details.valid`, OrderDetail.php:18-21, 77-85)
1 paid, 2 refunded, 3 disputed, 4 partial refund (legacy). `countsInTotal` = 1,2,3,4 (OrderDetail.php:470). `scopeValid` = 1 only (463).
**Trap: `valid` is set when the cart line is built, not when it is paid** (`addInfosProducts` sets `valid = product && quantity`, OrderDetail.php:972). Local data: lines with `valid=1` exist on OPEN (24 lines, 4,730.00), REJECTED (152 lines, 35,481.00) and CANCELED (46 lines, 66,922.50) orders.
**Never filter on `valid` without also filtering the order status.** `total_sales` does **not** filter `valid` at all (see M1). That is harmless today: lines with valid 0/null on paid orders sum to 0.00.

### 1.4 Line kinds (`order_details.donation_flag`, OrderDetail.php:23-30)

| flag | const | meaning | lines | sum |
|---|---|---|---|---|
| NULL | — | a "real" line: product, ticket, auction item, registration, **and the donation itself in a Donation campaign** (`scopeIsReal`, 485) | 466,286 | 12,696,852.05 |
| 1 | DONATION_ADDED | donation added on top of a purchase | 10,807 | 101,183.87 |
| 2 | DONATION_COVER_FEES | buyer covers the fees | 17,723 | 52,623.24 |
| 3 | DONATION_ONLY | "donate without buying" on a sale campaign | 2,864 | 323,767.45 |
| 4 | DONATION_TIPS | tip to Coolecto (moved to a status-20 order at payment, Order.php:2685-2733) | 1,058 | 5,203.08 |
| 5 | DONATION_DELIVERY_FEE | delivery fee | 28 | 353.73 |
| 6 | DONATION_VOUCHER | voucher / gift certificate / promo (negative) | 2,073 | −144,431.92 |
| 7 | DONATION_PAY_BALANCE | participant pays a balance (negative here) | 595 | −16,402.10 |
| 8 | DONATION_REPLACEMENT | replacement order line | 0 | — |

Groupings: `additionalDonationFlags()` = 1,2,3,4,5,7 (87); `taxDeductibleDonationFlags()` = 1,2,3 (99); `scopeExcludeCoveredFeesAndTips` drops 2 and 4 (724).

### 1.5 "Related campaigns": which campaigns a total spans
Three different scopes are used:

- **R(C), related, used by `total_sales` and most dashboard figures.** C, plus its sub-campaigns (table `main_campaigns`, recursive up to level 10), plus "survey campaigns" in **both** directions (`campaigns.survey_campaign_id`). Built by a recursive CTE in app/Services/Campaigns/CampaignCteRetriever.php:62-115, bound in AppServiceProvider.php:34-37, cached 15 s (line 136).
- **R′(C), related without surveys.** `excludeSurvey=true`, used by `unique_orders` (Campaign.php:593-597).
- **B(C), billed.** C plus the one campaign whose `survey_campaign_id = C` (`getBilledCampaignIds`, Campaign.php:571-578; `surveyCampaign()` hasOne, app/Models/Traits/CampaignExperienceTrait.php:31-34). **Sub-campaigns are excluded.** Used by `OrderCalculationService::setCampaign` (app/Services/OrderCalculationService.php:65-72), so by the account statement, participant/group totals, leaderboards and the dashboard rhythm.
- **{C} only.** Used by `covered_fees` (paidForAllPeriods(id), Campaign.php:643-648), `paidOrdersCount`, `total_refunds` on a non-main campaign, tax-receipt scopes, tickets, and the delivery report.

Local: 570 campaigns of type 4 (form) are attached as survey campaigns to a parent. The `main_campaigns` table has 187 links, and a sub can belong to several mains: campaign 3454 is a sub of 5 mains (4002-4006). **Summing mains double-counts.**

### 1.6 Manual payments
`orders.manual_payment` is **not a boolean**. It holds the offline payment method id (`Order::METHOD_*`, Order.php:71-80): 3 cash = 8,871 orders, 6 other = 921, 5 transfer = 384, 4 cheque = 344, 7 DAS = 59, 10 in kind = 41, 1 = 24, 2 = 18, 8 = 13, 9 = 2. "Manual" means `manual_payment IS NOT NULL` (`scopeNotManual`, Order.php:1372).
`OrderCalculationService` **excludes manual payments by default** (OrderCalculationService.php:112-114). Each caller opts in with `includeManualPayments()`. Which metric includes them is listed per metric below.

### 1.7 Soft deletes
`orders`, `order_details`, `campaigns`, `products`, `recipients`, `groups`, `tax_receipts`, `transfers`, `reservations` have **no `deleted_at`**: rows are hard-deleted. Base model app/Models/Model.php → vendor/condoedge/utils/src/Models/ModelBase.php, no SoftDeletes.
Only open/canceled orders are deletable (`deletableStatuses`, Order.php:613).
Soft deletes exist on `customers` (11,658 deleted), `pledges` (55), `answers` (57), `answer_polls` (3,101), `polls` (5,735) (SoftDeletes in Customer.php, Pledge.php; deleted_at columns).
Orphans: 69 order lines have no order; 319 recipients point to a missing campaign.

### 1.8 Dates
- `Order::TIME_SCOPE_DATE = 'effective_date'` (Order.php:44). It is set at payment (`finalizeAfterPayment`, Order.php:2597). It is used by the transactions list, its date filter (app/Kompo/Finance/CampaignTransactions.php:62-64), account-statement periods (app/Services/Finance/AccountStatementService.php:337) and leaderboard periods (CampaignTeamsLeaderboardService.php:96-97).
- **The dashboard "rhythm"/week chip uses `orders.created_at`** (app/Services/Campaigns/CampaignDashboardService.php:29-60).
- Local: 774 paid-status orders have a NULL `effective_date`, and 13,642 of 181,290 status-2 orders have `DATE(created_at) <> DATE(effective_date)`. A "sales in September" answer therefore differs by up to 7.5 % of orders depending on the column.
- Refund orders carry their own `effective_date` (= refund day, PerformsRefundsTrait.php:96). A period total nets the refund in the month it happened, not in the month of the sale.

### 1.9 Currency
Single currency. `currencies` has one row, `CAD`. `orders.currency_id` and `campaigns.currency_id` are NULL everywhere; `transfers.currency_id` = 1 on 1,998 rows and NULL on the rest. The display helper is `currency()` (app/Kompo/Helpers/utilities.php:37). No conversion is ever needed. Treat everything as CAD.

### 1.10 Replacement orders
`orders.replacement_order_id`, `order_details.replacement_od_id` and `donation_flag=8` are all 0 locally (app/Models/Traits/OrderReplacementRequestTrait.php). There is no data to define a rule on. Treat them as normal paid orders if they appear (inferred).

### 1.11 Fee mechanics (who pays what)
- `orders.fees` is the card fee charged to the org: `2.9 % × final_amount + 0.30` (`calculateFeesOfficial`, Order.php:2780-2792), unless the payment processor supplied the value (Order.php:2600-2602).
- `orders.real_fees` is Stripe's actual fee (fallback 2.33 % + 0.20).
- `order_details.item_fees` / `real_item_fees` split those two per line: the variable part pro rata to the line amount, the 0.30 pro rata to the absolute amount (`setOrderDetailsRealFees`, Order.php:2735-2762, with a rounding fix at 2764-2778).
- `order_details.coolecto_amount` is the **Coolecto commission** = `od_rate × line_amount`. `od_rate` = the campaign's effective rate: `campaigns.rate`, or 0 when the campaign is tips-only (`setCoolectoAmount`, OrderDetail.php:924-940; `getEffectiveRate`, Campaign.php:1329-1332). Rates seen locally: 0.03 on 3,270 campaigns, 0 on 394, 0.05 on 205.
- On tip lines, `coolecto_amount = amount − real_item_fees` and `included_in_balance = 0` (OrderDetail.php:942-947).
- `order_details.fees_team_id` is the team that bears the line's fees: the campaign team, or a **supplier's team** when the supplier covers the platform fees. A line has `supplier_responsability=1` when its fees team ≠ campaign team (Order.php:2749-2751; `Product::feesCoveredByTeamId`, Product.php:478-487: `deliverable.covered_by_me && supplier.cover_platform_fees`; OrderDetail.php:974-977).
- `order_details.included_in_balance` = `line_amount − total_cost − balance_fees`. It is 0 for covered-fee lines unless the participant pays the fees (`fees_paid_by_recipients`) (OrderDetail.php:931-938, 949-957). Used for participant balances (M24).
- Campaign switches:
  - `campaigns.cover_fees`: 1 = buyer may cover the fees, 2 = **tips-only mode** (platform rate 0, the buyer tips Coolecto), Campaign.php:109-110, 1309-1327.
  - `forced_cover_fees`: the buyer must cover.
  - `pay_fixed_fees`: the buyer pays a fixed amount, e.g. 0.30; copied from a supplier, User.php:521; `isPayFixedFees`, Campaign.php:1314.
  - A parent team with `disable_cover_fees_for_child_teams` makes both cover columns read 0 (Campaign.php:529-552). **The raw column can disagree with what the app uses.**
  - `hasAnyCoverFees()` = cover_fees ∨ forced ∨ fixed (Campaign.php:1299-1302).
  - The covered amount is the line with flag 2, created by `createCoverFeesOrderDetail` (Order.php:2455-2470; quadratic formula 2505-2520). `orders.covered_fees` (1 fees, 2 tips; Order.php:66-67) records the buyer's choice.
  - **`campaigns.fees_covered_by_supplier` is a dead column** ("Disconnected column to delete", database/migrations/migrated/2022_10_28_113253_add_fee_coverage_to_campaigns.php:14). 17 rows filled, never read.

---

## 2. Metric definitions

Notation: `PAID = (2,5,6,7,8,9,10,11)`, `amt = od.total_amount + IFNULL(od.discount_amount,0)`, `od JOIN orders o ON o.id = od.order_id`.

### M1. Amount raised: campaign dashboard ("Où on en est" / "raised", gauge)
- **Shown**: dashboard progress card. `$this->raised = $this->campaign->total_sales` (app/Kompo/Dashboard/CampaignProgressCard.php:41). The same value appears in the team-results card (CampaignTeamResultsCard.php:62), the campaign export (TeamCampaignsExport.php:60), the end report (Reports/CampaignEndInfo.php:52), the mobile API (`totalAmount`, ApiCampaignController.php:128) and the `{campaign_total_sales}` mail variable (MessageContentReplacer.php:281).
- **Code**: `Campaign::getTotalSalesAttribute` (Campaign.php:599-602) → `CampaignStatsService::getTotalSales` (app/Services/Campaigns/CampaignStatsService.php:157-173) → `OrderCalculationService` with `setCampaignBuilder` (R(C)) and `includeManualPayments()` (OrderCalculationService.php:83-96, 99-137).
- **SQL**:
  ```sql
  WITH RECURSIVE rel(cid, sid, lvl) AS (
      SELECT id, survey_campaign_id, 1 FROM campaigns WHERE id = :C
      UNION ALL SELECT c.id, c.survey_campaign_id, rel.lvl + 1 FROM rel JOIN main_campaigns mc ON mc.main_campaign_id = rel.cid JOIN campaigns c ON c.id = mc.sub_campaign_id WHERE rel.lvl < 10
      UNION ALL SELECT c.id, c.survey_campaign_id, rel.lvl + 1 FROM rel JOIN campaigns c ON c.survey_campaign_id = rel.cid WHERE rel.lvl < 10
      UNION ALL SELECT c.id, c.survey_campaign_id, rel.lvl + 1 FROM rel JOIN campaigns c ON c.id = rel.sid WHERE rel.lvl < 10 AND rel.sid IS NOT NULL
  )
  SELECT
      SUM(od.total_amount + IFNULL(od.discount_amount, 0)) AS total_sales
  FROM order_details od
  JOIN orders o ON o.id = od.order_id
  WHERE o.campaign_id IN (SELECT DISTINCT cid FROM rel)
      AND o.status_id IN (2, 5, 6, 7, 8, 9, 10, 11)
  ```
- **Includes**: manual payments, covered fees (flag 2), added donations, vouchers (negative), refunds and disputes (net), sub-campaigns and survey campaigns. **Excludes**: tips (they live on status-20 orders), open/canceled/rejected carts. No `valid` filter, no date filter.
- **Multi-campaign trap**: given several campaigns (a builder), `CampaignStatsService` returns the **average** per root campaign, not the sum (`calculateAverageOverGroups`, CampaignStatsService.php:269-287). No app method returns "team total raised"; a team total is the sum of per-campaign `total_sales`, which double-counts subs shared by several mains (§1.5).

### M2. Gross collected / "Total des transactions": account statement
- **Shown**: `team.account-statement-new`, stat `total_transactions` (AccountStatementService.php:262).
- **Code**: `performCalculations` (AccountStatementService.php:76-112) → `odQueryForCampaign(C)` (scope B(C), **no manual payments**) → `getGrossCollected` / `applyBillingSelect` (OrderCalculationService.php:155-171; OrderDetail.php:419-430).
- **SQL**: `SELECT SUM(amt) FROM od JOIN o WHERE o.campaign_id IN B(C) AND o.status_id IN PAID AND o.manual_payment IS NULL`.
- **Difference with M1** = manual payments + sub-campaign sales + surveys that point the other way. Verified on 3 campaigns: M1 − M2 = exactly the manual-payment total (§4).
- Manual payments are shown on their own line: `manual_payments` = the same query with `manual_payment IS NOT NULL` (`getManualPayments`, OrderCalculationService.php:235-240).

### M3. "Ventes et dons" (sales_and_donations): account statement
`coolectoSales − coveredFees − vouchers − addedDonations − justDonates − refunds − disputes` (AccountStatementService.php:156-159). Because refunds and disputes are negative, subtracting them **adds them back**. The figure is therefore product sales **before** refunds, plus tips, delivery fees and pay-balance lines, and **without** added donations even though it is labelled "and donations". Owner check needed (§6 D3).

### M4. Donations vs sales
- **Additional donations, dashboard stats service**: sum of flags 1,2,3,4,5,7 over R(C), manual included (`getAdditionalDonationsAmount`, CampaignStatsService.php:222-239).
- **Additional donations, account statement**: flags 1+3 only, over B(C), no manual (AccountStatementService.php:96-98, 258).
- **non_product_sales**: all non-NULL flags incl. vouchers (negative) and pay-balance, over R(C), lines `valid IN (1..4)`, paid (Campaign.php:650-655). Can be negative: P2P 3604 = −36,914.23.
- **Products / sales only**: `getTotalSalesWithoutDonations` = lines with flag NULL on paid orders of C (Campaign.php:1990-1994), used for 50/50 draws.
- **Trap**: in a **Donation campaign (type 2), the donation itself is a flag-NULL product line** (`generateDonationProduct`, Campaign.php:2653). In campaign 657, `non_product_sales` = 81.45, which is only the covered fees, and `products_sold` = 73 is the number of donations. "How much was donated" in a donation campaign is therefore `total_sales − covered fees`, not a flag sum.
- **Tax-deductible donation lines**: flags 1,2,3, or any line in an "always receiptable" campaign (P2P, membership, pure donation, pledge, or a form flagged valid for taxes; CampaignTypesTrait.php:217-221), or `products.tax_receipt_amount × qty` (`OrderDetail::getTaxAmount`, 812-831).

### M5. Covered fees
- **Campaign attribute** `covered_fees`: flag 2 over **C only**, paid, **no manual** (Campaign.php:643-648; PerformsBillCalculationsTrait.php:18-24). SQL: `SUM(amt) … o.campaign_id = C AND status IN PAID AND manual_payment IS NULL AND od.donation_flag = 2`.
- **Account statement** `covered_fees`: flag 2 over B(C), no manual (AccountStatementService.php:97). P2P 3604 shows 7,285.77 here vs 7,282.81 for the attribute, because B(C) includes the survey child.
- **Per order (transactions page)**: `Order::getCoverFees()` = flag-2 lines of the order bundle, **no valid filter** (Order.php:1531-1537; CampaignTransactions.php:193-196).

### M6. Platform fees ("Frais de plateforme")
- **Dashboard / profit**: `Campaign::platform_fees` → `CampaignStatsService::getPlatformFeesAmountSum` (CampaignStatsService.php:241-259).
  ```sql
  SUM(CASE WHEN oc.cover_fees = 2 THEN 0
           WHEN od.fees_team_id = root.team_id THEN IFNULL(od.item_fees,0) + IFNULL(od.coolecto_amount,0)
           ELSE 0 END)
  -- over R(C), status IN PAID, o.manual_payment IS NULL; oc = the order's campaign, root = C
  ```
  Only fees borne by the org's team count. Lines whose fees a supplier covers are excluded. Tips-only campaigns show 0.
- **Account statement `platform_fees`**: `SUM(item_fees + coolecto_amount)` over B(C), no manual, **all fee teams** (OrderDetail.php:423). `fees_paid_by_team` is the same filtered on `fees_team_id = campaign.team_id` (424). `supplier_fees` = platform − team fees (AccountStatementService.php:217-220).
- **Per order**: `getPlatformFeesFor(team)` = 0 for manual, else `item_fees + coolecto_amount` of lines with that fees team (Order.php:798-805; OrderDetail.php:790-797).
- **Coolecto commission alone ("Coolecto amount")**: `SUM(od.coolecto_amount)` (`coolecto_fees` in applyBillingSelect, OrderDetail.php:426). The UI never shows it alone; it is folded into "platform fees".
- **Card fees alone**: `SUM(item_fees)`. Participant view: `getStripeFeesForRecipient` (Campaign.php:1005-1010). The group view uses `orders.fees` (995-1003): two different sources.
- **Disputes** add $15 per dispute to `item_fees` (OrderDetail.php:1212).

### M7. Product costs
- **Dashboard / account statement**: `SQL_OUR_SUPPLIERS_COSTS` = `IF(supplier_responsability = 1, IFNULL(total_cost, 0), 0)` (OrderDetail.php:41). Only costs of lines whose **supplier bears the fees** (Coolecto supplier catalogue) count. `Campaign::costs` uses lines `valid IN (1..4)` over R(C) (Campaign.php:669-673); the account statement uses B(C), no manual, no valid filter.
- **Transactions page and participant profit**: **all** `total_cost` (Order.php:1545-1548; Recipient.php:1178).
- `total_cost = quantity × products.unit_cost`, plus a supplier share when delivered to the donor (OrderDetail.php:980-989).
- `calculateFinalCost` sets cost to 0 for auctions (Campaign.php:1891-1898).
All 5 verified campaigns have 0 supplier-retained costs. The two definitions diverge on Coolecto-supplier product sales (82,207 lines with `supplier_responsability=1`).

### M8. Profit
Four different numbers are labelled "profit":
1. **Dashboard "Profits" figure** (CampaignTeamResultsCard.php:63-70) = `Campaign::profits` (Campaign.php:763-774) = `raw_profit` (750-761):
   `total_sales − calculateFinalCost(costs[M7 supplier-retained]) − platform_fees[M6 dashboard]`.
   For a 50/50 draw: `(total_sales − non_product_sales)/2 + non_product_sales` replaces total_sales.
   **Side effect: reading `profits` WRITES `campaigns.precalculated_profits`** (Campaign.php:766-771). An ingest must read `raw_profit` instead.
   **Mixed bases**: manual payments are in the sales but not in the fees, and covered fees are in the sales.
2. **Profit %** = `precalculated_profits / (total_sales − covered_fees if hasAnyCoverFees else total_sales)` (Campaign.php:796-803). The cached column is filled on 1,191 campaigns and can be stale.
3. **Account statement "net profits"** = `M2 − product_costs − fees_paid_by_team` (AccountStatementService.php:137-140, 202-205). No manual payments.
   Example 3454: dashboard 163,535.50 vs statement 66,190.61; the difference is 97,344.89 of manual payments.
   `profit_percentage` = net/M2 (147-154).
4. **Transactions page per order** = bundle `final_amount − platform fees(team) − all costs` (CampaignTransactions.php:208-214).
5. **Participant profit** (participant dashboard, `GOAL_TYPE_PROFIT` goals) = `(totalSales + coveredFees − costs(all) − coolecto_amount(team) − item_fees(team)) × profit_share% + shareable profits` (Recipient.php:1172-1187; Campaign.php:1871-1878, 1996-2007).

### M9. Profit share
- `campaigns.profit_share` is a varchar percent. 431 rows filled, about 337 non-zero.
- `recipient_share_amount` = `raw_profit × profit_share/100` (Campaign.php:776-782).
- Per order on the transactions page: `(order amount + covered fees − fees − costs) × profit_share/100` (CampaignTransactions.php:179-186).
- `recipients.shares_profits` (0 rows locally) feeds `getShareableProfits`.

### M10. Tips (to Coolecto)
- At payment, tip lines (flag 4) move to a separate order with **status 20** (Order.php:2685-2733).
- They are **excluded from every campaign total** (status 20 ∉ PAID).
- Amount: `SUM(final_amount) FROM orders WHERE campaign_id = C AND status_id IN (20, 21)` (`scopeAreTips`, Order.php:1336). Example: campaign 172 (tips-only) = 1,302.25.
- **It is Coolecto revenue, not the org's.** "Frais ADF"/"Ristourne"/"Stripe" are banned words in the UI (CLAUDE.md). Answer wording needs care.

### M11. Refunds
- **Campaign attribute** `total_refunds` = `SUM(orders.final_amount) WHERE status_id = 5` over {C} + its survey child; on a main campaign, the sum over **subs only** (`getAttrRecursive`, Campaign.php:580-591, 675-678). Negative number.
- **Account statement** = `SUM(amt)` of lines of status-5 orders over B(C), **no manual** (OrderCalculationService.php:255-263). `nb_refunds` = distinct orders.
  - Example 3454: attribute −164.25 vs statement −146.25. The difference is a manual-payment refund.
- `orders.final_amount` of a refund order can differ from its lines' sum when "no refund fees" is used (OrderDetail.php:1357-1362).

### M12. Disputes
- **Account statement** "disputes" = `SUM(amt)` of lines of orders with status 7,9,10 over B(C), no manual (OrderCalculationService.php:265-273; Order.php:1315-1322). This sums the negative dispute order (7) **and** the positive originals that won (9) or lost (10). The exact accounting meaning is to be confirmed with the owner.
- **Count** `disputes_count` = count of orders with status 8,9,10 per status (OrderDisputesTrait.php:32-36).

### M13. Order counts: four definitions
| label | code | statuses | campaigns | manual |
|---|---|---|---|---|
| "on N orders" under average basket (dashboard) | `unique_orders` (Campaign.php:593-597) | 2,6,7,8,9,10,11 (includes negative dispute orders 7) | R′(C) | yes |
| journey milestone count | `CampaignDashboardService::paidOrdersCount` (90-93) | PAID incl. 5 and 7 | {C} | yes |
| account-statement "transactions" | `nb_transactions − nb_refunds − nb_disputes` (AccountStatementService.php:92) | PAID | B(C) | no |
| transactions page rows | `showInList` + `notIgnoredInExperience` (CampaignOrdersTransactions.php:28-31; Order.php:1382-1397) | 2,6,8,9,10 | C | yes |

Example 3454: 2,136 (unique) vs 2,151 (journey). `statsForCampaign` (Order.php:1489-1500) is a fifth variant: `COUNT(*)` over PAID.

### M14. Average basket
`avg_transaction = total_sales / unique_orders` (Campaign.php:784-789). The stats-service variant divides by distinct orders in collected statuses over R(C) (CampaignStatsService.php:139-155). The account-statement variant is `M2 / its transactions` (AccountStatementService.php:192-195).

### M15. Items sold
- **`products_sold`** (Campaign.php:635-641): `SUM(IF(flag IS NULL, IF(answer_id IS NULL OR total_amount <> 0, quantity, 0), 0))` over R(C), paid, `valid IN (1..4)`. Refund copies carry a negative quantity, so this is net.
- **Delivery report "quantity"** (`campaign.orders.groups`, app/Kompo/Reports/CampaignOrdersGroups.php:24-30) = `SUM(quantity)` with `paidAndCounts` over **{C} only**, per date. It **includes donation and covered-fee lines** (quantity 1 each). Its "participants" = `COUNT(DISTINCT recipient_id)` on the same lines, and "groups" = `campaign->groups()->count()` (all groups, sellers or not).
- Per participant: `OrderDetail::productsSold` (OrderDetail.php:659-662) = qty of flag-NULL lines, no answer exclusion.

### M16. Tickets sold / scanned (ticketing campaigns, type 5)
- **Sold** (`DashboardTicketsView.php:154`, `ScannedTicketsLog.php:73`) = `Reservation::getSoldTickets` = `COUNT(*) FROM reservations WHERE campaign_id = C AND order_id IS NOT NULL` (Reservation.php:124-130). **No order-status filter and no void filter.**
  - Example 2093: 13,065 vs 13,035 with `status_id = 1` and a paid order.
  - One reservation = one ticket unit; products with `equivalent_units` create several (Reservation.php:109-113).
- **Scanned in** = same plus `scanned_at IS NOT NULL AND marked_as_out_at IS NULL`. **Scanned out** = `marked_as_out_at IS NOT NULL` (Reservation.php:132-140). `scanned_amount` holds the number of scans.
- **Reservation status**: 1 valid, 2 void (Reservation.php:25-26). NULL on 79,708 rows: QR/venue tickets never get a status. `scopeValid` = status 1 + paid order (Reservation.php:191-195).
- Participant tickets: `getTotalSoldOfTickets` = qty × `equivalent_units` of paid lines (Recipient.php:293-300).

### M17. Draw tickets (tirage, type 5 with `ticketing_type = 1`)
- **Draw sales report** (app/Kompo/Reports/DrawSalesInformation.php:26-37):
  - tickets sold = `SUM(quantity)` of flag-NULL lines of **any order status**;
  - valid = `valid = 1`; refunded = negative lines;
  - void = lines whose reservation is void;
  - numbers generated = reservations valid + void;
  - sales = `SUM(total_amount)` with `valid IN (1..4)`, **any order status**.
  - No local impact today (0 such lines on unpaid draw orders), but it is a latent mismatch with the dashboard.
- **50/50 draw**: the prize is half the ticket sales unless a prize amount is set (`prizeAmount5050`, Campaign.php:1344). `raw_profit` halves ticket sales (Campaign.php:754-758). `max_prize_amount` caps it (1930-1968).
- Prize/draw records live in `prizes` (168 rows) and `draws` (48). `drawings` and `tickets` (App\Models\Draw\Ticket) are **empty**: draw tickets are `reservations` with a `ticket_number`.

### M18. Auction (encan, type 7): bids and totals
- **Engagement** `total_engagement` (Campaign.php:691-706): per product (variants excluded by `WithoutVariantsScope`):
  - fixed-price product: Σ(amount × quantity) of all valid bids;
  - otherwise: the top bid's amount × quantity.
  - A bid stops counting when its pledge has an order in a non-paid, non-open status for that product (`PledgeAmount::scopeValidToEngagement`, PledgeAmount.php:74-86).
  - Soft-deleted pledges are **not** excluded by that scope (inferred from whereDoesntHave semantics; matched numerically).
- **Goal progress for auctions uses engagement**, not paid sales (Campaign.php:734).
- Bids = `pledge_amounts` rows (`istopbid` flag). Bidders = `pledges` (`deleted_at IS NULL`).
- `engagement_profit` = engagement − costs − rate × engagement (708-721).
- Example 2847: engagement 14,657 vs paid total_sales 13,891.41; 239 bids by 75 bidder sessions.

### M19. Memberships (type 9)
- Memberships = `Order::membership()`: campaign type 9, `main_order_id = id`, `subs_order_id IS NULL` (Order.php:1426-1431).
- Active = `validMembership`: `canceled_at IS NULL`, `expires_at >= now OR NULL`, `start_from <= now OR NULL` (1433-1438). Callers add `paid()` (Customer.php:107-118).
- Recurring donations: `scopeRecurringDonations` (1417-1424).
- Verified on campaign 2110: 87 paid memberships, 20 active now (app = SQL).

### M20. Registrations
- **Registration campaigns (type 11)**: `OrderDetail::paidRegistrationsOfTeams` = lines `paidAndCounts`, `product_id NOT NULL`, campaign type 11. Count = `SUM(quantity)`, amount = `SUM(total_amount)` per campaign (OrderDetail.php:509-513; Teams/TeamChildCampaignsTable.php:26-30).
- **P2P registrations**: orders with `p2p_registration = 1` (1,224 orders). **Excluded from every P2P total** unless `campaigns.count_inscriptions_amount_in_totals` is set (`applyP2pRegistrationsSetting`, OrderCalculationService.php:318-327). That column is 0 on all 4,116 campaigns, so they are always excluded today.
- Registered P2P participants = `Recipient::onlyRegistered` (paid registration line or free group type, has donors, has group, has names; Recipient.php:357-372).

### M21. Goal and % of goal (campaign)
- **Columns**: `campaigns.goal` (decimal, 1,403 > 0), `goal_type` (1 total, 2 profit; Campaign.php:182-183), `show_goal` (public display).
- **Numerator `goal_sales`** (Campaign.php:723-736):
  - profit goal → `profits` (writes cache, see M8);
  - P2P → `total_p2p_sales_without_covered_fees_nor_tips`: B(C), manual included, P2P registrations excluded, flags 2/4 excluded (613-621);
  - auction → `total_engagement`;
  - otherwise → `total_sales` (M1).
- **`pct_goal`** = `round(goal_sales / goal, 2) × 100`, **uncapped**. A main campaign uses `Σ subs.goal_sales / Σ subs.goal` (738-747). It is shown on the main-campaign dashboard, in exports and in the public end report.
- **Staff dashboard gauge** = `min(100, round(total_sales / goal × 100))` (CampaignProgressCard.php:41-63). **It uses M1, not `goal_sales`, and is capped.** It also drives "missing X$" and "goal reached".
- **Public page bar** = `goal_sales / goal` uncapped (CampaignPublicPageTrait.php:38-50). With participants passed in, it uses Σ participant goal_sales / Σ participant goals.
- Verified divergences: P2P 3604 pct_goal 99 % vs gauge 100 %; auction 2847: 147 % vs 100 %; draw 2093: 116 % vs 100 %. Main 5171: pct_goal **342 %** (subs' goals sum 1,750) while `campaigns.goal` = 100,000 and the gauge shows 6 %.

### M22. Group goal and total
- **Goal** (Group.php:315-326): if `campaign.force_group_goal` → `campaign.default_group_goal`; else `groups.goal ?? campaign.default_group_goal ?? campaign.group_goal`. Local: `groups.goal` on 116 groups; force flags 0 everywhere.
- **Total (`total_donated`)** (Group.php:283-288 → `Recipient::totalDonatedFor`, Recipient.php:762-775):
  - covers the lines of every recipient with `group_id = G OR group2_id = G`, plus **group-level donations** (`recipient_id IS NULL AND od.group_id = G`) split equally among the group's recipients (P2P: registered only) (app/Services/SplittingGroupDonationsTrait.php:15-69);
  - B(C), manual included, flags 2/4 excluded, P2P registrations excluded.
  - SQL check: direct lines of members + group-level lines (exact when the group has ≥ 1 counted member).
- **% of goal** = `round(goal_sales / goal × 100)`, integer, uncapped (ResolvesGoal.php:21-26). For a profit goal, the numerator is the group's profit.
- **P2P team leaderboard total** (`CampaignTeamsLeaderboardService::statsByGroup`, 86-106): `IFNULL(recipients.group_id, od.group_id)` with the same filters. It ranks by total, then order count, then first to reach it.

### M23. Participant goal and totals
- **Goal** (Recipient.php:598-610): if `force_recipient_goal` → `campaign.default_recipient_goal`; else `recipients.personal_goal ?? group.participant_goal ?? campaign.default_recipient_goal ?? campaign.participant_goal`. `personal_goal` is 0 rows locally; `allow_personal_goal` is 0 everywhere.
- **goal_sales** (579-596):
  - profit goal → participant profit (M8.5);
  - **P2P → own lines only, no group split** (flags 2/4 excluded);
  - otherwise → `total_donated` (with group-donation split).
  - So in a P2P campaign a group donation counts for the group but not for its members' goals.
- **Participant dashboard total** `totalSales` (`getCampaignTransactionsInfo`, Recipient.php:1129-1189; `OrderDetail::getTotalSales`, 799-810) = `SUM(total_amount)` (**discount ignored**) of the participant's lines, minus covered fees and tips **only if the campaign has any cover fees**. B(C), manual included, P2P registrations excluded, no group split.
- `nbTransactions` = distinct paid orders, **manual excluded** (Campaign.php:987-993).
- **Group leaderboard** (GroupLeaderboardService.php, via `odSubQueryGroupByRecipients`, helpers/order-calculations-service.php:34-44): participant_total includes the group split. Only sellers are ranked; non-sellers are counted, never named.
- `sellersCount` (dashboard) = distinct `recipient_id` on paid lines of B(C), manual included (CampaignDashboardService.php:64-70).
- **Active participants** = P2P: `onlyRegistered`; else `was_disabled IS NULL` (Campaign.php:277-289).
- Verified pct: participant in 3604 = 2,382 % of a 500 goal (uncapped).

### M24. Participant balance (`PARTICIPANTS_SEE_BALANCE`, mostly main/travel campaigns)
`inclBalance = Σ order_details.included_in_balance` of the participant's lines (Recipient.php:1180). The column is precomputed per line (§1.11). Which figure participants see is set by `campaigns.participants_metric` (Campaign.php:1739-1746).

### M25. Sub-campaign / main-campaign rollups
- `total_sales(main)` = main's own orders + all subs (recursive) + surveys. 5171: 6,160.53 subs + 252.02 own = 6,412.55.
- `getAttrRecursive` attributes (`total_refunds`, `total_p2p_sales`, `disputes_count`, `total_engagement`, `engagement_costs`) on a main = Σ over **subs only**; the main's own orders are excluded (Campaign.php:580-591).
- Account statement on a main covers B(main) = the main alone. Subs have their own statements.
- `pct_goal(main)` ignores `main.goal` (M21).

### M26. Transfers / deposits ("Your money")
- `transfers.status`: 1 ready, 10 confirmed, 20 sent (Transfer.php:16-18). `sent_as_method`: 1 Stripe, 2 Interac, 3 EFT, 4 cheque (23-26).
- **Account statement**:
  - `deposited_amount` = `SUM(amount)` of transfers in B(C) with `status = 20`, whose `bill_items.status_bi >= 10` (visible) and `bill_items.plan_id` ∈ team plans (AccountStatementService.php:161-169, 463-469; BillItem.php:92-115);
  - `in_transit` = all visible − deposited;
  - EFT reconciliation figures at 493-522.
- **Dashboard payout card** = `ownTransfers` (`transfers.team_id = campaign.team_id`) with `status < 20 AND sent_at IS NULL`. **Shown only when the sum > 0**; date = `MIN(to_date)` (CampaignDashboardService.php:96-113; Campaign.php:340-343). There is no bill-item visibility filter, unlike the statement.
- Team-wide deposits page: `getTeamDepositsQuery` = `transfers.team_id = T` (AccountStatementService.php:474-480).
- Journal amounts use `IFNULL(old_amount, amount)` (428). `old_amount` is set on 19 edited transfers.
- **`transfers.team_id` ≠ campaign team on 613 rows** (supplier payouts). Tenant scope must use `transfers.team_id`.

### M27. Account balance
- **Account statement** (AccountStatementService.php:142-145):
  `net_profits + nonCodFeesForBalance − deposited − in_transit − (nonCodUnpaidInvoices + nonCodUninvoicedFees)`. It depends on `bill_items` (invoices, payment terms COD vs non-COD, Team.php:809-812).
  Verified values: 0, 0, 0.11, 300.52, 0. They are **not reproducible as a simple aggregate**; compute at ingest.
- `Campaign::getCampaignBalance` (Campaign.php:2101-2113) is **a second balance**: paid non-manual gross over B(C) − Σ `BillItem::toReceiveAmount` (BillItem.php:185-188). It is used elsewhere; the statement's is the one shown to managers.

### M28. Tax receipts
- `tax_receipts.status`: 1 created, 2 sent, 3 canceled (TaxReceipt.php:16-18). Local: 7,481 sent, 256 created, 109 canceled.
- **Table page** (`finance.tax-receipts`, Finance/TaxReceiptsTable.php:25-34, 103-117):
  - rows `team_id = current team`, optionally `forThisCampaign` (order.campaign_id = C) and `issued_at` range;
  - shown amount = **`donation_amount`** (eligible amount);
  - **canceled receipts are listed** (no status filter).
- **Account statement info line** (AccountStatementNew.php:126-133): count and `SUM(total_amount)` of `forThisCampaign(C)->notCanceled()`. `total_amount` is an **accessor**: when the column is NULL (612 rows) it falls back to the sum of `val` of the order chain (TaxReceipt.php:45-48).
  - Example 657: 73 receipts; the column sum = 3,562.70 but the app shows 9,971.45 (13 NULL rows). **A Cypher sum over the raw column gives a wrong number.**
- `cost_amount` accessor = `total_amount − donation_amount` (50-53).
- Cancel happens automatically on refund (PerformsRefundsTrait.php:109).

### M29. opening_balance
`campaigns.opening_balance` (double) is written by `app/Kompo/Dashboard/StepOpeningBalance.php:14-22` and **never read** anywhere in app/ or views. 3 rows filled. It is not a metric. Do not expose it, or ask the owner (§6 D11).

### M30. Daily/weekly rhythm (dashboard)
`dailySales` and `weekTotals` = `SUM(amt)` over B(C) lines, manual included, P2P registrations excluded, grouped by **`DATE(orders.created_at)`** (CampaignDashboardService.php:25-61). Week-over-week variation = `(this − last)/last × 100`.

---

## 3. One word, several numbers

| user word | candidates (all real screens) | verified example |
|---|---|---|
| "raised / amassé" | M1 dashboard (manual incl., covered fees incl., R(C)) · M2 statement gross (no manual, B(C)) · `goal_sales` (P2P: no covered fees/no registrations; auction: engagement; profit goal: profit) · participant `totalSales` | 3454: 168,382.46 vs 71,037.57 · 3604: 467,715.78 vs 448,015.78 vs 397,696.01 |
| "profit" | M8.1 dashboard · M8.3 statement net · M8.4 per order · M8.5 participant | 3454: 163,535.50 vs 66,190.61 |
| "orders / transactions" | unique_orders · journey count · statement count · list rows | 3454: 2,136 vs 2,151 |
| "% of goal" | gauge (capped, total_sales) · pct_goal (uncapped, goal_sales) · public bar | 3604: 100 vs 99 · 5171: 6 vs 342 |
| "refunds" | attribute (incl. manual, final_amount) · statement (lines, no manual) | 3454: −164.25 vs −146.25 |
| "tickets sold" | reservations with an order (any status) · valid + paid · draw report quantity (any status) | 2093: 13,065 vs 13,035 |
| "tax receipts total" | `donation_amount` (table) · `total_amount` accessor (statement) · raw column | 657: 9,971.45 (both accessors) vs 3,562.70 raw |
| "date of a sale" | `effective_date` (lists, statement) · `created_at` (dashboard rhythm) | 7.5 % of paid orders differ by day |

---

## 4. Verification (local DB, 2026-10-26)

Method: for each campaign, the app's own method was run in tinker inside `DB::beginTransaction()` … `DB::rollBack()`, and an independent SQL query was written from the definition above. Scripts are in the session scratchpad (`verify.php`, `verify2.php`). Only numbers are recorded; campaign names and personal data were not read.

Campaigns: **3454** product sale (cover fees, profit share, 211 manual orders, refunds, disputes, sub of 5 mains) · **657** donation (62 manual of 80 orders, tax receipts) · **2093** draw/tirage prize pool (13k tickets) · **3604** P2P (survey child 3605, 4,339 tax receipts, 5,767 orders) · **2847** auction · bonus **5171** main campaign (3 subs) · **2110** membership.

| metric | 3454 | 657 | 2093 | 3604 | 2847 | 5171 |
|---|---|---|---|---|---|---|
| M1 total_sales | 168,382.46 ✓ | 9,971.45 ✓ | 9,266.08 ✓ | 467,715.78 ✓ | 13,891.41 ✓ | 6,412.55 ✓ |
| M5 covered_fees (attr) | 612.47 ✓ | 81.45 ✓ | 393.08 ✓ | 7,282.81 ✓ | 329.41 ✓ | 151.02 ✓ |
| M4 non_product_sales | 102,144.46 ✓ | 81.45 ✓ | 566.08 ✓ | −36,914.23 ✓ | 329.41 ✓ | 869.80 ✓ |
| M7 costs (supplier-retained) | 0 ✓ | 0 ✓ | 0 ✓ | 0 ✓ | 0 ✓ | 0 ✓ |
| M6 platform_fees (dashboard) | 4,846.96 ✓ | 419.67 ✓ | 631.14 ✓ | 27,942.23 ✓ | 840.14 ✓ | 423.01 ✓ |
| M8.1 raw_profit | 163,535.50 ✓ | 9,551.78 ✓ | 8,634.94 ✓ | 439,773.55 ✓ | 13,051.27 ✓ | 5,989.54 ✓ |
| M13 unique_orders | 2,136 ✓ | 76 ✓ | 258 ✓ | 5,754 ✓ | 49 ✓ | 215 ✓ |
| M14 avg_transaction | 78.83 ✓ | 131.20 ✓ | 35.92 ✓ | 81.29 ✓ | 283.50 ✓ | 29.83 ✓ |
| M15 products_sold | 10,279 ✓ | 73 ✓ | 870 ✓ | 5,348 ✓ | 95 ✓ | 440 ✓ |
| M11 total_refunds (attr) | −164.25 ✓ | −1,350.00 ✓ | −250.00 ✓ | −1,388.12 ✓ | −200.00 ✓ | 0 ✓ |
| manual sales | 97,344.89 ✓ | 2,950.00 ✓ | 0 ✓ | 19,700.00 ✓ | 0 ✓ | 0 ✓ |
| M13 journey paidOrdersCount | 2,151 ✓ | 80 ✓ | 263 ✓ | 5,762 ✓ | 50 ✓ | 59 ✓ |
| M23 sellersCount | 191 ✓ | 0 ✓ | 39 ✓ | 799 ✓ | 0 ✓ | 0 ✓ |
| active participants | 298 ✓ | 0 ✓ | 43 ✓ | 881 (app only) | 53 ✓ | 1 ✓ |
| M2 statement gross | 71,037.57 ✓ | 7,021.45 ✓ | 9,266.08 ✓ | 448,015.78 ✓ | 13,891.41 ✓ | n/a |
| M6 statement platform fees | 4,846.96 ✓ | 419.67 ✓ | 631.14 ✓ | 27,942.23 ✓ | 840.14 ✓ | n/a |
| M8.3 statement net profits | 66,190.61 ✓ | 6,601.78 ✓ | 8,634.94 ✓ | 420,073.55 ✓ | 13,051.27 ✓ | n/a |
| M11 statement refunds | −146.25 ✓ | 0 ✓ | −250.00 ✓ | −1,388.12 ✓ | −200.00 ✓ | n/a |
| M12 statement disputes | −168.31 ✓ | 0 ✓ | 0 ✓ | −50.00 ✓ | 0 ✓ | n/a |
| M5 statement covered fees | 612.47 ✓ | 81.45 ✓ | 393.08 ✓ | 7,285.77 ✓ | 329.41 ✓ | n/a |
| M4 statement add. donations | 4,687.10 ✓ | 0 ✓ | 173.00 ✓ | 0 ✓ | 0 ✓ | n/a |
| statement manual payments | 97,344.89 ✓ | 2,950.00 ✓ | 0 ✓ | 19,700.00 ✓ | 0 ✓ | n/a |
| M26 deposited | 66,190.61 ✓ | 6,601.78 ✓ | 8,634.83 ✓ | 419,840.53 ✓ | 13,051.27 ✓ | n/a |
| M27 account balance | 0 (app only) | 0 | 0.11 | 300.52 | 0 | n/a |
| M26 dashboard next payout | 0 ✓ | 0 ✓ | 0 ✓ | none shown; raw SQL −67.50 (rule: shown only if > 0) ✓ | 0 ✓ | 0 ✓ |
| M28 tax receipts count | 0 ✓ | 73 ✓ | 0 ✓ | 4,339 ✓ | 0 ✓ | 0 ✓ |
| M28 tax receipts total (accessor) | 0 ✓ | **9,971.45 vs raw column 3,562.70 ✗** | 0 ✓ | 404,177.93 ✓ | 0 ✓ | 0 ✓ |
| M16 tickets sold / scanned | 0/0 ✓ | 0/0 ✓ | 13,065 ✓ / 0 ✓ | 0/0 ✓ | 0/0 ✓ | 0/0 ✓ |
| M23 top participant totalSales | 4,914.43 ✓ | — | 664.00 ✓ | 11,910.00 ✓ | — | — |
| M23 participant nbTransactions | 175 ✓ | — | 26 ✓ | 84 ✓ | — | — |
| M23 participant total_donated | 4,914.43 ✓ | — | 664.00 ✓ | 11,910.00 ✓ | — | — |
| M22 group total_donated | 43,707.57 ✓ | — | 8,826.00 ✓ | 15,600.00 ✓ | — | — |
| M22 P2P team_total (top 3) | — | — | — | 26,330.42 ✓ · 22,310.00 ✓ · 18,775.00 ✓ | — | — |
| M21 goal / goal_sales / pct_goal / gauge | no goal | 15,000 / 9,971.45 / 66 / 66 | 8,000 / 9,266.08 / 116 / 100 | 400,000 / 397,696.01 ✓ / 99 / 100 | 10,000 / 14,657 / 147 / 100 | 100,000 / 6,412.55 / 342 / 6 |
| M18 auction engagement | — | — | — | — | 14,657 ✓ | — |
| M19 memberships (2110) | 87 paid ✓, 20 active ✓ | | | | | |

**Result.** Every definition reproduced the app exactly (to the cent) with plain SQL, with two exceptions:
1. **Tax receipt totals** cannot come from the raw column: the accessor fallback must be computed at ingest.
2. **Account balance** depends on bill-item and payment-term rules: ingest the service's value rather than re-derive it.

The mismatches that matter are **between screens**, not between the SQL and the app (§3). The model must be told which screen's definition to use.

---

## 5. Implications for the graph (proposals, owner to confirm)

- **Precompute campaign-level numbers at ingest** with `graph.computed` (CondoEdge precedent: `config/ai-entities/finance.php:625-630`, `Charge::real_due_amount`). Do not let Cypher re-derive them.
  - Candidates: `total_sales`, `goal_sales`, `pct_goal`, `raw_profit` (**never `profits`**: it writes), `platform_fees`, `covered_fees`, `non_product_sales`, `products_sold`, `unique_orders`, `avg_transaction`, `total_refunds`, `total_manual_sales`, `total_engagement` (auction), and the account-statement stats (`AccountStatementService::bootAndSetupCalculations(id)->performCalculations()->getAllStats()`: gross, fees, net, refunds, disputes, deposited, balance).
  - Watch the cost: about 4,116 campaigns × several CTE queries. The CTE is cached 15 s.
- **Order-level**: store a signed `net_amount` = Σ line_amount (not `final_amount`; they differ on refunds with fee retention), plus `status_id`, `is_manual`, `effective_date`, `created_at`, and the flag sums (`covered_fees_amount`, `donation_amount`, `voucher_amount`) as properties. Then "sales between dates" questions are a Cypher sum over Order nodes with the same status set.
- **Canonical status sets** must be literal in the calculations block: PAID = [2,5,6,7,8,9,10,11]; never `status_id = 2` alone.
- **Tenant path** for every order-derived node is the order's campaign → `campaigns.team_id`, except transfers (`transfers.team_id`), tax receipts (`tax_receipts.team_id`) and customers (`customers.team_id`). See data-classification.md.
- **Recipients/groups** names are minors' data; leaderboards by name need an owner decision (§6 D9).

---

## 6. Decisions the owner must make

1. **D1: What does "raised" mean?** Options: (a) M1 dashboard `total_sales` (incl. manual payments, covered fees, subs, surveys); (b) statement gross M2 (online only); (c) `goal_sales` (what the public bar shows: P2P without covered fees, auction = bids engaged). Recommendation to discuss: (a), the number on the dashboard, with "including X$ of offline payments" when manual > 0.
2. **D2: Covered fees in "raised"?** The dashboard includes them. The P2P goal and the participant total (when the campaign has cover fees) exclude them. The same question gives different answers per campaign type.
3. **D3: Account-statement "sales and donations"** adds refunds and disputes back and drops added donations (M3). Is that intended, or a bug?
4. **D4: Which "profit"?** Dashboard (`raw_profit`, includes manual payments in sales but not in fees) vs statement net (online only). Should "profit" questions include manual payments? Also confirm product costs = supplier-retained costs only (M7) vs all costs (transactions page, participant profit).
5. **D5: Which order count?** `unique_orders` counts negative dispute orders (status 7). The journey count also counts refund orders (5). Recommend one definition (e.g. distinct status 2/6/8/9/10/11 orders, i.e. `showInList` + 11) and say so in answers.
6. **D6: Which "% of goal"?** Staff gauge (total_sales, capped at 100) vs `pct_goal` (goal_sales, uncapped). For main campaigns `pct_goal` ignores `campaigns.goal` (5171: 342 % vs 6 %). Is that intended?
7. **D7: Tickets sold**: include reservations of unpaid/void orders (dashboard) or only valid + paid? Same for the draw sales report, which ignores order status.
8. **D8: Dates**: answer period questions on `effective_date` (statement, lists) or `created_at` (dashboard rhythm)? And how should the 774 paid orders with a NULL effective_date be handled?
9. **D9: Participant names**: staff see participants' names in the app, but they are often minors. May the graph hold `first_name`/`last_name` for staff-only answers ("who sold the most")? Or should it be ids/initials only, plus a link to the app page?
10. **D10: Tips**: Coolecto revenue, excluded from campaign totals. Should the assistant ever mention them to an org? (Banned UI words: "Frais ADF", "Ristourne", "Stripe".)
11. **D11: `opening_balance`** is collected but never used. Drop it, or define what it should add to?
12. **D12: Main campaigns**: totals include the main's own orders, but `getAttrRecursive` metrics (refunds, engagement, disputes) exclude them. A sub in several mains is counted in each. Which rollup should the assistant use, and should it warn about shared subs?
13. **D13: Tax-receipt amount**: answer with `donation_amount` (eligible, shown in the table) or `total_amount` (paid, shown on the statement)? Should canceled receipts be listed (the table lists them)?
14. **D14: Team-wide totals**: the app has no "total raised by the organization" figure. If the assistant sums campaigns, should it count sub-campaigns once and include child teams (network parents: `Team::getReportTeamIds`, Team.php:638-641)? Users with `restricted_campaigns` must only see their campaigns.
