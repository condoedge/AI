# Iteration 11 delta (board hotfix + digest debut + validated config)

| Conversation / turn | Iter-10 | Iter-11 | Δ | Verdict |
|---|---|---|---|---|
| team-info-insider t0 | 0.50 F | 0.55 F | +0.05 | fail (member count/type missing; audit-column padding) |
| team-roster-then-excel t0 | 0.85 P | 0.90 P | +0.05 | pass |
| team-roster-then-excel t1 | 0.35 F | 0.70 P | **+0.35 FLIP** | pass (context survived; 100/530 cap + fan-out noted) |
| my-balance-then-pdf t0 | 0.35 F | 0.40 F | +0.05 | fail (homonym collapse at composition) |
| my-balance-then-pdf t1 | 0.80 P | 0.80 P | 0.00 | pass |
| ambiguous-balance t0 | 0.15 F | 0.15 F | 0.00 | fail (no clarify branch; forced resolution) |
| ambiguous-balance t1 | 0.78 P | 0.40 F | **−0.38 REGRESSION** | fail (homonym collapse — new) |
| tear-down-wall-law t0 | 0.35 F | 0.40 F | +0.05 | fail (3/4-vote misstated as majority; placeholder link) |
| buyer-arrears-law t0 | 0.50 F | 0.35 F | −0.15 | fail (art. 1069 never retrieved; invented rule) |
| board-and-meetings t0 | 0.05 F | 0.05 F | 0.00 | fail (**still errors — hotfix not effective in-run**) |
| board-and-meetings t1 | 0.05 F | 0.35 F | +0.30 | fail (junk 'test' meeting; placeholder link) |
| owner-arrears-pivot t0 | 0.45 F | 0.50 F | +0.05 | fail (invented :CUSTOMER edge; unverifiable zero) |
| supplier-payables t0 | 0.85 P | 0.90 P | +0.05 | pass |
| rights-duties t0 | 0.75 P | 0.55 F | **−0.20 REGRESSION** | fail (uncited claim; 1090 indivision dropped) |
| rights-duties t1 | 0.55 F | 0.45 F | −0.10 | fail (citation integrity broken) |
| rights-duties t2 | 0.45 F | 0.55 F | +0.10 | fail (generic empty template, no verifiable zero) |
| resilience t0 | 0.85 P | 0.80 P | −0.05 | pass |
| resilience t1 (SENTINEL) | 0.80 P | 0.15 F | **−0.65 SENTINEL RED** | fail (anaphor → knowledge_query, cypher=null) |
| resilience t2 | 0.90 P | 0.85 P | −0.05 | pass |
| resilience t3 | 0.60 P | 0.65 P | +0.05 | pass |
| app-navigation t0 | 0.95 P | 0.85 P | −0.10 | pass |
| cold-roster-excel t0 | 0.72 P | 0.78 P | +0.06 | pass |
| french-flow t0 | 0.85 P | 0.85 P | 0.00 | pass |
| french-flow t1 | 0.72 P | 0.72 P | 0.00 | pass |
| **whats-next-digest t0** | — | 0.50 F | NEW | fail (junk meeting surfaced; links missing; layout) |
| **whats-next-digest t1** | — | 0.65 P | NEW | pass (negative control correct) |
| **whats-next-digest t2** | — | 0.40 F | NEW | fail (self-inflicted rate limit; collapse regression) |
| **probe global-roster** | — | 0.80 P | NEW | SHIP candidate |
| **probe global-supplier-owed** | — | 0.75 P | NEW | SHIP candidate |
| **probe global-tasks-open** | — | 0.35 F | NEW | HOLD |
| **probe global-app-nav** | — | 0.90 P | NEW | SHIP candidate |
| **probe global-roster-fr** | — | 0.80 P | NEW | SHIP candidate |
| **probe global-supplier-owed-fr** | — | 0.55 F | NEW | HOLD |
| **probe global-tasks-open-fr** | — | 0.30 F | NEW | HOLD |
| **probe global-app-nav-fr** | — | 0.90 P | NEW | SHIP candidate |
| **probe bench-law-repairs** | — | 0.85 P | NEW | bench (keep) |

**Totals**
- **Comparable-24:** 13/24 fail (iter-10: 11/24), mean **0.569** (iter-10: 0.590) — mean **down 0.021**, fails **up 2**. The drop is concentrated in three turns: the sentinel red (−0.65, pre-registered trigger), ambiguous t1 (−0.38, genuine composition regression), rights t0 (−0.20). One real flip up: roster t1 (+0.35).
- **All-turns (36):** 18/36 fail, mean **0.594**.
- **Deterministic:** 26/27 green; the 1 red is the sentinel (resilience t1 min_rows=0).

# Criteria check (the 7, honest)

| # | Criterion | Status | Evidence |
|---|---|---|---|
| 1 | balance t0 flip [G3-gated] | **UNMET (expected — G3 not built)** | 0.35→0.40, still fail; homonym collapse persists at composition |
| 2 | owner-arrears +0.15 [G3] | **UNMET (expected)** | 0.45→0.50, only +0.05; :CUSTOMER edge invention still the root cause |
| 3 | rights t2 ≥ 0.70 [G3/digest-context] | **UNMET (expected)** | 0.55; empty-result composer still context-stripped |
| 4 | zero placeholder entity links [G3 lint] | **UNMET (expected)** | ≥5 instances: `Unit/ID/view` (tear-down), `Meeting/ID/view` (board t1, digest t1), `Supplier/ID/view` + `Bill/ID/edit` (probe Q2-FR) |
| 5 | closers ≤ 2/24 [G3] | **UNMET (expected)** | ≥4 bare closers in comparable-24 (board t1, supplier, rights t0, rights t1) |
| 6 | **BOARD HOTFIX** t0/t1 non-error, floor 0.35/0.30, target t0 ≥ 0.50 [BUILT — judged hard] | **UNMET — the round's biggest miss** | t0 = 0.05, still the identical RuntimeException: the trace shows the validator *still* rejecting the canonical `WITH un, max(b.year)` pattern ("entity variable must still be in scope"), retry included. The claimed "verified live pre-run" did not survive into the measured run — either the hotfix wasn't in the run's build or the validator fix is incomplete. t1 = 0.35 meets its 0.30 floor but only because it dodged the validator; it surfaced the junk 'test' meeting. This criterion gets no credit. Root-cause the deploy/verify gap before re-registering it. |
| 7 | No regressions | **UNMET** | Sentinel t1 red is the pre-registered promotion trigger (counted, not excused). Balance t1 held (0.80), supplier held (0.90), french held exactly (0.85/0.72), cold-roster held (0.78), app-nav held pass (0.85, −0.10). **But ambiguous t1 regressed 0.78→0.40** (homonym collapse — response prompt has no homonym guard while query-gen does) and rights t0 flipped 0.75→0.55. Deterministic 26/27 vs the 24/24 bar. |

**Score: 0/7 met.** Criteria 1–5 are honestly unmet-as-expected (no credit taken, none claimed). Criterion 6 is the hard failure: the one built thing this suite could measure did not show up in the measurement.

# Digest debut verdict

**Real value or demo? Verdict: real branch, not yet real value — a working skeleton with three disqualifying defects.**

What is genuinely real (not demo-ware): the routing decision fires on the broad ask and *not* on the specific follow-up (t1 negative control passed at 0.65 — no digest block, normal pipeline, correct grounded date); all 9 configured sources render in registry order; truncation totals are literal and true (130/189/13/36); empty sources collapse (t0); rows match data_sample verbatim; nothing fabricated across all three turns.

What disqualifies it from "value" today: (1) the junk 'test' meeting (2026-07-25) is surfaced uncritically twice with `used_fallback:false` — the flagship fallback-honesty behavior does not work; (2) t2 tripped the pipeline's **own** rate limiter (9 queries/digest × back-to-back turns) and printed "could not be checked" for two sources — an automatic fail under its own bars and a real product defect for any user who asks twice; (3) composition is nondeterministic — empty-collapse worked at t0 and regressed to per-section lines at t2, and section action links went from one (t0) to zero (t2).

**The combined-export acknowledgment (t2): honest — yes.** `export_followup_offered='excel'` is set, the answer explicitly offers "reply 'export that'" as the single closing offer, and no false "I sent it" claim is made; the export ask was neither dropped nor faked. This is the correct B1 behavior and the one thing t2 got fully right.

Suite notes to action: the t0 standard carries false premises (claims no meetings exist while meeting 146 does; claims 9 approved-unpaid bills / 8 work orders that return 0) — fix the seed. And **recommend the harness exempt digest turns from the understanding-heuristic signal** — the false "understanding" attributions are known noise.

# Probes ship decisions (threshold ≥ 0.70)

| Probe | Score | Decision |
|---|---|---|
| global-roster | 0.80 | **SHIP** |
| global-supplier-owed | 0.75 | **SHIP** |
| global-tasks-open | 0.35 | **HOLD** — asserts "none urgent" against ~10 real urgent tasks; needs an urgent-count aggregate in the query and an urgent-count gate |
| global-app-nav | 0.90 | **SHIP** |
| global-roster-fr | 0.80 | **SHIP** |
| global-supplier-owed-fr | 0.55 | **HOLD** — broken `entity://Supplier/ID/view` / `Bill/ID/edit` placeholder links; fix is the systemic link validator, not the seed |
| global-tasks-open-fr | 0.30 | **HOLD** — same shared defect as EN tasks; fix the shared query/gate once, both heal |
| global-app-nav-fr | 0.90 | **SHIP** |
| bench-law-repairs | 0.85 | **bench — keep as bench** (per design, never gated) |

**Exact config changes:** uncomment the gated entries for **global-roster, global-supplier-owed, global-app-nav (EN)** and **Q1-FR (global-roster-fr), Q4-FR (global-app-nav-fr)**. Keep commented: **Q3 (global-tasks-open), Q2-FR (global-supplier-owed-fr), Q3-FR (global-tasks-open-fr)** until (a) the tasks query gains an urgent aggregate + gate and (b) the link validator rejects unsubstituted `ID` placeholders. **bench-law-repairs stays in the bench set** — no gate change.

# Sentinel & next lever

**Settled, not discretionary:** resilience t1 redded deterministically (min_rows 0, third run) post-digest. Per iteration-10's pre-registered rule, **G2/L5 is AUTO-PROMOTED to the next lever.** No re-litigation.

**Scope of G2/L5, from the accumulated case file:**
1. **Anaphor corruption** — "add their last login" enriched to "add their last login to teams"; the anaphor resolver mangles rather than resolves.
2. **Augmentation anchor-loss** — column-add follow-ups lose the prior data-query anchor entirely: routed `knowledge_query`, `entity_types=[]`, `cypher=null`. G2/L5 must carry the previous turn's entity_types and query as a deterministic anchor for augmentation follow-ups.
3. **Clarify branch** — QU has no ambiguity signal; "Show me the balance" was force-resolved into a guessed reading (0.15). G2/L5 adds an ambiguity confidence output that routes to a clarifying-question branch instead of a confident wrong table.
4. **is_follow_up reliability** — follow-up classification misfires both ways, and the harness heuristic falsely attributes digest failures to understanding; harden the classifier and exempt digest turns from that signal.

**Pre-registered iteration-12 criteria:**
1. **Sentinel green:** resilience t1 routes as data_query, cypher non-null, min_rows ≥ 1, score ≥ 0.60 [G2/L5-gated].
2. **Clarify live:** ambiguous t0 produces a clarifying question offering the plausible readings; score ≥ 0.60 [G2/L5-gated].
3. **Regression healed:** ambiguous t1 ≥ 0.70 (homonym guard added to the response-generation prompt — cheap, not G3-sized; carry balance t0 as its co-beneficiary, target flip).
4. **Board, again, judged harder:** t0 ≥ 0.50 non-error, with in-run trace evidence the validator accepts the canonical max-year pattern — pre-run verification alone no longer counts.
5. **Holds:** supplier / app-nav / french / cold-roster / roster t0-t1 / balance t1 / rights t0 all hold pass; deterministic 27/27.
6. **Digest (if touched):** failed_sources empty across 3 consecutive digest turns; empty-collapse renders as ONE line deterministically.

# KEEP/REVERT the round

**KEEP — with two mandatory carry-forwards.** The comparable-24 mean dipped (0.590→0.569) and fails rose (11→13), which argues for scrutiny, but the drop decomposes into: the sentinel red (pre-registered trigger doing exactly its job — that's the suite working, not the bundle failing), and two composition-side regressions (ambiguous t1, rights t0) not plausibly caused by the shipped changes' code paths. Against that, the round delivered durable assets: a validated question config with five probes at ≥0.75 ready to ship, a digest branch whose routing, source registry, honest totals, and negative control are demonstrably real, the invoice team_id-NULL scoping rule, and a genuine flip (roster t1, +0.35). Reverting would discard all of that to recover ~0.02 of mean.

Carry-forwards that are conditions of the KEEP: (1) **root-cause why the board hotfix was absent from the measured run** — a "verified live pre-run" fix that doesn't appear in the run is a process failure bigger than the board question itself; (2) **ambiguous t1's homonym-collapse regression is an open defect** owned by iteration-12 criterion 3, not written off as grader noise.