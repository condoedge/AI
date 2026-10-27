# Coolecto data questions: owner decisions

Approved by the owner on 2026-10-26 ("Approve all"), exactly as recommended in
`ai/docs/plans/2026-10-26-coolecto-data-questions.md` Phase 0.

| # | Decision |
|---|---|
| S1 | v1 data answers: owner and manager roles only. Every other audience and role stays docs-only. |
| S2 | Owners/managers with `restricted_campaigns`: only those campaigns (+ the ones they created). Team-wide figures that cannot be narrowed are refused to them. |
| S3 | Coolecto gets its own Neo4j (local: a second Docker container; production: the owner's hosting call). |
| D1 | "Raised" = the dashboard figure (`total_sales`, offline payments and covered fees included); mention "including X $ received outside Coolecto" when offline > 0. Statement's online figure only for statement/deposit questions. |
| D2 | Covered fees are included in "raised". |
| D3 | The statement's "sales and donations" line is never used by the assistant; owner to check it as a possible app bug. |
| D4 | "Profit" = dashboard profit (`raw_profit`); statement net only for statement/deposit questions. |
| D5 | Order count = dashboard "on N orders" (`unique_orders`). |
| D6 | % of goal = dashboard raised / goal, uncapped. P2P, auction and profit goals use `goal_sales` and say so. Main campaigns: owner to confirm later (5171: 6 % vs 342 %). |
| D7 | Tickets sold = dashboard definition (reservations with an order); remaining = stock − sold. |
| D8 | A sale's date = payment date (`effective_date`), falling back to `created_at`. |
| D9 | Participant first and last names, for owners/managers only; no other participant field. |
| D10 | Tips are never mentioned. |
| D11 | `opening_balance` is ignored. |
| D12 | Per campaign: the app's own figure. Team totals: over distinct orders, so a shared sub-campaign counts once. |
| D13 | Tax receipt amount = eligible amount (`donation_amount`); canceled receipts excluded unless asked. |
| D14 | Organisation-wide totals per calendar year, own team only (no child teams in v1), computed at ingest. |
| F1 | Orders sync through the queue; campaign figures refresh ≤ 5 min after a payment/refund plus nightly; answers say "as of HH:MM". |

App defects in `access.md` §7: the owner asked for all of them to be fixed (`ai/docs/plans/2026-10-26-coolecto-security-fixes.md`).

Owner, 2026-10-26 (later):
- Production: Coolecto's Neo4j runs on the same server as the app.
- The injection gate's French gap is left as is (the data limits are the protection).
- Local: Coolecto `.env` points at its own Neo4j (bolt://localhost:7688); reachable.
