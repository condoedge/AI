# Iteration 2c — consolidated day delta

| # | conversation/turn | baseline | iter-1 | iter-2c | trend | note |
|---|---|---|---|---|---|---|
| 1 | team-info-insider t0 | FAIL 0.25 | FAIL 0.35 | FAIL 0.55 | ↑↑ | (b) "That's your own email" landed; N/A bug gone; members half still dropped (conflicting CURRENT_TEAM guidance + entity slice) |
| 2 | team-roster t0 | FAIL 0.40 | FAIL 0.35 | FAIL 0.50 | ↑ | (a) TeamRole join now projects roles; fails only on 10-of-100 presented as complete |
| 3 | roster-excel t1 | FAIL 0.15 | FAIL 0.20 | FAIL 0.40 | ↑ | Email-only regression gone (name+email+role exported); still 10-row sample, columns_include gate red |
| 4 | my-balance t0 | pass 0.70 | pass 0.85 | pass 0.78 | ↔* | Seed re-pinned to unit 310 (d) — not comparable; canonical formula + toFloat + honest verifiable zero all present |
| 5 | balance-pdf t1 | pass 0.55 | pass 0.72 | pass 0.62 | ↔* | Export works end-to-end; payload is 3 indistinguishable "310 \| 0 \| 0" rows; QU misroutes, shortcut saves it |
| 6 | show-balance t0 | FAIL 0.05 | FAIL 0.10 | FAIL 0.20 | ↑ | Fabricated $22,753.51 is GONE (figure now grounded); still guesses instead of clarifying — pure L5 |
| 7 | clarify-t1 | pass 0.60 | pass 0.80 | pass 0.72 | ↔* | Seed changed (d); canonical formula, verifiable zero; flattens 3 units named "310" |
| 8 | tear-down-wall t0 | FAIL 0.30 | FAIL 0.25 | FAIL 0.35 | ↔ | L3 untouched as expected: no legal-vocab expansion, wrong chunk, uncited legal claims, cites_document gate red |
| 9 | buyer-arrears t0 | FAIL 0.30 | FAIL 0.15 | FAIL 0.25 | ↔ | L3 untouched: wrong CCQ window (1075–1081 vs needed 1069), ungrounded answer shipped despite failed gate |
| 10 | board t0 | FAIL 0.15 | FAIL 0.30 | FAIL 0.40 | ↑ | (a) tenant anchoring correct, grounded names; 10-of-47 as "complete", no year filter, no union attribution |
| 11 | meetings t1 | FAIL 0.25 | FAIL 0.40 | FAIL 0.55 | ↑ | Zero fabrication on empty result; single-query pipeline can't reach past-meeting fallback; stale context block |
| 12 | supplier-payables t0 | pass 0.60 | pass 0.75 | pass 0.90 | ↑↑ | (a)+(c) full win: canonical payables formula, toFloat, honest verifiable zero — best row of the day |
| 13 | rights t0 | FAIL 0.35 | FAIL 0.60 | FAIL 0.40 | ↓ | Only genuine down-move; L3 retrieval (one chunk, arts 1089–97) + new art. 1090 misstatement + mis-injected empty-results guideline |
| 14 | rights-t1 | FAIL 0.55 | FAIL 0.55 | pass 0.70 | ↑✓ | Flipped to pass: genuine deepening, grounded citations; retrieval-thin remains |

\* rows 4/5/7 ran different seeds after (d) — score deltas there are not clean run-over-run comparisons.

**Totals:** baseline 10/14 FAIL, mean 0.37 → iter-1 10/14 FAIL, mean 0.46 → **iter-2c 9/14 FAIL, mean 0.52**. Deterministic gate failures: exactly 3 (roster-excel columns_include; tear-down + buyer-arrears cites_document), all in untouched L3/L4 scope. First run to reduce the fail count.

# What is now definitively fixed (day wins)

- **Numeric fabrication (the day's worst defect)** — show-balance no longer invents money. Evidence: "The single figure quoted (-$2,253.51 for Impôts fonciers) is genuinely present in data_sample (account_id 11109), so no numeric fabrication." The baseline's fabricated $22,753.51 claim is absent from every iter-2c verdict. Attribute: (a) purged few-shot store + anti-copy framing.
- **Self-reference notice** — mechanism (b) verified working: "the team contact email is flagged as 'That's your own email' rather than presented as a way to reach the team, and no external 'contact them at' framing appears."
- **Team-name N/A bug** — gone; team-info now scores "every stated fact (team name, contact email, phone…) matches trace.data_sample exactly." Attribute: (b).
- **Money-as-string arithmetic** — (c) toFloat verified in both balance and payables paths: "toFloat on calc_due_amount" (my-balance) and "the Cypher follows the canonical payables formula exactly" (supplier, 0.90).
- **Tenant-scoping injector on OPTIONAL MATCH** — (e) verified on live data: "min_rows gate passed (3>=1), so the zero is verifiable, not a no-unit-found artifact"; unit-310 min_rows gates passed deterministically.
- **Honest verifiable zeros** — the (d) rubric note plus (a)/(c) produce the intended behavior: "Textbook honest-zero handling per the DATASET REALITY paragraph" (my-balance); supplier "explicitly conveys the verification substance ('There are no open bills or outstanding payables'), not a bare unexplained $0."
- **Roster roles** — (a) TeamRole guidance landed: "role attribute correctly projected via TeamRole join."
- **Export email-only regression** — roster-excel now ships "name + email + role columns, so it avoids the known email-only regression."
- **Team anchoring on collective possessives** — board t0: "tenant scoping matches the mandated pattern exactly"; no cross-tenant leakage anywhere in the run.

# What remains red, clustered by lever

**L3 — file retrieval & citation discipline (3 red rows: tear-down 0.35, buyer-arrears 0.25, rights-t0 0.40; plus thinness dragging rights-t1)**
- Wrong chunk windows: buyer-arrears retrieved arts. 1075–1081 when the answer lives in 1069 ("Search query… pulled the wrong window of the correct document"); rights-t0 got only arts. 1089–1097 for a broad rights/duties question.
- No lay→legal vocabulary expansion: tear-down's search_query is "the verbatim lay question" with surface tags ('tear down','wall') — the alteration/private-portion articles were unreachable.
- Citation gate detected but not enforced: buyer-arrears — "the pipeline detected the violation (verdict_label 'gate-failed') yet still delivered the ungrounded answer — no enforcement/regeneration on gate failure." 2 of 3 remaining deterministic gate failures are cites_document here.
- Single-chunk retrieval is structurally too thin: "One chunk per turn is structurally too thin for 'tell me more' deepening questions."
- Expected gain: query expansion + multi-chunk retrieval + gate-triggered regeneration plausibly flips all 3 rows → 9 fails → 6, mean +~0.10.

**L4 — export pipeline (1 red row: roster-excel 0.40; degrades balance-pdf to 0.62)**
- Silent 10-of-100 truncation into the export: "Only the 10-row sample is retained across turns, and document-mode export composes from it instead of table-mode export over the full stored result set."
- columns_include gate fails because "the export is a free-floating document payload not backed by a result set."
- Degenerate payloads: balance-pdf's document is three identical "310 | 0 | 0" rows with "no column that tells the units apart"; the 0ms templated path "bypassed the LLM contract entirely."
- Expected gain: flips roster-excel, lifts balance-pdf ~0.62→0.8; 1 fail removed.

**L5 — follow-up QU / clarify path (1 red row: show-balance 0.20; misroutes on roster-excel t1 & balance-pdf t1)**
- No ambiguity branch at all: "the QU stage has no ambiguity-detection branch at all, which is exactly what this seed is designed to expose"; plus is_possessive=true on an utterance "with no possessive at all."
- Follow-up anaphora broken: "Create a PDF of this" → "What are the steps or tools required to create a PDF document?", is_follow_up=false, entity_types=[] — rescued only by the deterministic export shortcut.
- Expected gain: flips show-balance, cleans up latency/misrouting on two export turns; 1 fail removed.

**Other — result-set truncation & partial-list disclosure (code; depresses 3 red rows: roster t0, board t0, show-balance t0)**
The pipeline truncates 100→10 (and 47→10) rows before composition, then the composer presents the sample as complete despite the verbatim instruction "'Never present a partial list as complete'" being in the prompt — and on show-balance it enabled an ungrounded "'All other accounts show a balance of $0' asserted over 90 rows the response model never saw." This is the largest single score drag outside L3 but is not one of the three named levers; it needs its own fix (total-count disclosure + table/export door, or full-set composition).

**Other — prompt-assembly residue**: conflicting CURRENT_TEAM guidance (team-info), empty CYPHER PATTERN for CURRENT_BOARD_MEMBERS (board), empty-results guideline mis-injected on file-grounded turns (rights, both turns), stale filter block passed under new focus (meetings). Small individually, cheap to fix.

# Verdict on the day

**KEEP the full bundle (L1+L2+fixes).** The trajectory is monotonic and real: mean 0.37 → 0.46 → 0.52, fails 10 → 10 → 9, and iter-2c is the first run to convert a fail (rights-t1 → pass 0.70). Deterministic gate failures are down to exactly 3, all in scope the bundle never touched, and the injector fix is verified against live data (unit-310 min_rows passed). Every pre-registered expectation held except "team-info gains members" (still dropped, now traced to self-contradicting prompt guidance) and "board keeps anchoring" (held, but the 'current' year filter shipped as an empty concept block).

**New regressions:** one honest down-move — rights-t0 slid 0.60 → 0.40, on an untouched L3 path; the verdict adds a new art. 1090 misstatement (indivision condition dropped) and flags the empty-results guideline being mis-injected into a file-grounded turn, which is prompt-assembly fallout worth a targeted check, not a bundle revert. The dips on rows 4/5/7 are seed-change artifacts (d), not regressions. Nothing in the bundle leaked tenants, fabricated numbers, or broke a previously green gate.

# Next lever (single recommendation)

**L3 — file retrieval.** Tie-break reasoning: (1) largest expected gain — 3 red rows vs 1 each for L4/L5, worth roughly −3 fails and +0.10 mean; (2) highest-severity failure class — ungrounded legal assertions are the only remaining fabrication-adjacent behavior, and the graders call it out as "insidious… roughly right, but nothing in the trace supports it"; (3) cleanest attribution — L3 was untouched all day, holds 2 of the 3 remaining deterministic gate failures, and the fixes are well-specified by the verdicts themselves (lay→legal query expansion, wider/multi-chunk windows, and hard enforcement/regeneration when cites_document fails). L5's clarify path is the next-most-valuable but buys only one row; L4's truncation fix should ride along cheaply afterward since the root cause (10-row sample as the only cross-turn state) is already precisely located.