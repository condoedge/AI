# Baseline Grading — 2026-07-23 (first measured run)

> Suite: `../CondoEdge/tests/eval/gold-conversations.json` (9 conversations, 14 turns) as Julie Rousseau (user 13, lead, team 3 Novo Gestion), `--fresh`.
> Report: `../CondoEdge/storage/eval/baseline-2026-07-23.json`. Graded by 9 strict agents + synthesis (`wf_e87efbb0-d00`).
> Command result: exit 1 — 3 gate failures. Stage averages: understanding 0.90, retrieval 0.87, **prompt 0.63, generation 0.63**.

## Scorecard — 10 of 14 turns FAIL

| # | Conversation | Turn | Ask (short) | Verdict | Score | Primary lever | One-line reason |
|---|---|---|---|---|---|---|---|
| 1 | team-info-insider | 0 | "Info about my team" | FAIL | 0.25 | prompt | Lists Julie's own email as the contact for "reaching" her own team; response prompt has no identity block |
| 2 | team-roster-then-excel | 0 | Team roster with roles | FAIL | 0.40 | prompt | Roles never queried (entity-description column list overrode REQUESTED ATTRIBUTES); 10 of 100 rows shown as "the roster" |
| 3 | team-roster-then-excel | 1 | "That as an Excel w/ contacts" | FAIL | 0.15 | code | Export reused 10-row sample (90 members dropped), no roles/phone; columns_include GATE FAILED |
| 4 | my-balance-then-pdf | 0 | "My balance" | pass | 0.70 | prompt | Grounded $0 via correct canonical formula — but bare aggregate can't distinguish "$0 owed" from "no unit linked" |
| 5 | my-balance-then-pdf | 1 | "Create a PDF of this" | pass | 0.55 | code | Saved only by the deterministic export fallback (QU read it as "how do I make PDFs"); skeletal unlabeled PDF; harness misblamed retrieval |
| 6 | ambiguous-balance-clarify | 0 | "Show me the balance" | FAIL | 0.05 | prompt | **Fabricated debit balance $22,753.51** not in any shown row; no clarify path exists; prompt forces a direct 2–3 sentence answer over a 100-row GL dump |
| 7 | ambiguous-balance-clarify | 1 | "I mean what my unit owes" | pass | 0.60 | code | Clarification resolved and grounded; is_follow_up flag inverted between turns |
| 8 | tear-down-wall-law | 0 | Can I tear down a wall? | FAIL | 0.30 | retrieval | Right document, wrong section (arts. 1069–1070.2 expenses/register); ungrounded legal claims; cites_document GATE FAILED |
| 9 | buyer-arrears-law | 0 | Buyer liable for arrears? | FAIL | 0.30 | retrieval | Wrong CCQ section (1093–1109); central claim answered from priors, uncited; cites_document GATE FAILED |
| 10 | board-and-meetings | 0 | "Our board of directors" | FAIL | 0.15 | prompt | "My board" anchored on Julie's personal Contact → 0 rows despite 291 board edges; refers the lead to "contact the management" |
| 11 | board-and-meetings | 1 | Next meeting + agenda | FAIL | 0.25 | prompt | Meeting anchored via unit-ownership path (lead owns no unit) → 0 rows; dead-end without action://meetings; agenda ≠ motions |
| 12 | supplier-payables | 0 | Total owed to suppliers | pass | 0.60 | prompt | Grounded $0, correct semantics — but scoping-vs-canonical-formula conflict dropped verifying columns; $0 unverifiable |
| 13 | rights-duties-progressive | 0 | My rights/duties as co-owner | FAIL | 0.35 | retrieval | Retrieved meetings/voting chunk instead of rights/duties articles; asserted un-retrieved "financial statements" right |
| 14 | rights-duties-progressive | 1 | "Tell me more" (expenses) | FAIL | 0.55 | retrieval | Missed art. 1064 proportionality + non-payment consequences (art. 1094 was in context, unused); 2–3 sentence cap suppressed depth |

## Red rows ranked (user value destroyed)

1. **Roster→Excel (0.15, gate-failed)** — export silently drops 90 of 100 members (uses the 10-row display sample), no roles/phone; the "NO QUERY REQUIRED" action rule overrode the "export is still a DATA question" rule so nothing was re-fetched. Levers **L4+L2**.
2. **Team-info (0.25)** — "You can reach them at… j.rousseau@novogestion.ca": the system tells Julie to contact herself. Response prompt has no identity block/insider rule (P1); cypher copied from the 87%-similar poisoned example (P2). Levers **L1+L2**.
3. **Show-me-the-balance (0.05) — trust destroyer** — fabricated **$22,753.51** debit figure (visible rows: 2253.51, 773.69, 44291.57); no clarify path anywhere; prompt mandates a direct 2–3-sentence answer over unanswerable data. Levers **L1+L5+L2**.
4. **Board (0.15)** — "our board" anchored on her personal Contact → 0 rows while the same prompt contained the correct team-scoped roster pattern; then refers the lead to "the management". Levers **L2+L1**.
5. **Next meeting (0.25)** — unit-ownership path for a lead with no unit → 0 rows though team meeting data exists (prior example used `union_id=5 AND team_id IN $ai_team_ids`). Levers **L2+L1**.
6. **Buyer-arrears law (0.30, gate-failed)** — legal advice answered from priors, uncited; wrong CCQ section retrieved. Levers **L3+L1**.
7. **Tear-down law (0.30, gate-failed)** — same; punts Julie to "your board or property manager" (she IS management). Levers **L3+L1**.
8. **Roster turn 1 (0.40)** — roles asked, never queried, then deflected; 10-of-100 presented as complete. Levers **L2+L4**.
9. **Rights/duties (0.35)** — wrong section + fabricated "financial statements" right; 2–3 sentence cap forbids the required structured overview. Levers **L3+L1**.
10. **Tell-me-more (0.55)** — grounded but thin; art. 1094 was verbatim in context and unused. Levers **L3+L1**.

## Root-cause clusters → general levers (ordered by breadth × generality)

**L1 — Rebuild the response-generation prompt (CONFIG-ONLY; merges preflight P1+P4).** Touches 8+ of 14 turns.
(a) inject the CURRENT USER CONTEXT block (name/email/team/role) that query-gen already has, with an insider-voice rule; (b) replace "2-3 sentences maximum" with question-type-adaptive length; (c) add a cannot-answer/ask-one-clarifying-question affordance; (d) citation-or-decline for legal/rule claims; (e) offer relevant `action://` links + one natural next step on empty/zero results; (f) set a real project description (P4).
*Proof:* row 1 flips green; insider/deflection claims vanish from rows 8,10,11; prompt avg 0.63→≥0.85; row 6 stops fabricating.

**L2 — Query-gen anchoring + few-shot hygiene (CONFIG + tiny code; merges P2).**
Purge poisoned stored examples + make `--fresh` clear the similar-queries store; persona-aware anchoring ("for a team lead, 'our board/meetings' scopes via `$ai_team_ids`, not the personal Contact/ContactUnit path"); REQUESTED ATTRIBUTES override entity-description column lists; canonical formulas keep an identifying column (add columns, never change the aggregation).
*Proof:* rows 10/11 return >0 rows; row 2 projects roles; the "87% similar" copied query disappears from traces.

**L3 — File-retrieval quality & budget (CODE; merges P3).**
Section/article-aware ranking for the legal corpus (right doc, wrong section is the failure mode); fix the broken relevance scorer (50 files at "score 1" with EMPTY snippets on row 3); remove recall-floor injection into data queries; skip file search for data_query intents (recovers most of the 17s/turn).
*Proof:* cites_document gates pass with on-topic articles; latency drops.

**L4 — Export pipeline correctness (CODE).**
Export the FULL previous result set (not the 10-row display sample) or re-run un-LIMITed; resolve the "NO QUERY REQUIRED" vs "export is a DATA question" prompt contradiction in favor of re-querying when new columns are asked; real document template (title, labels, currency, as-of date); harness: stop blaming retrieval on export-from-previous turns.
*Proof:* columns_include passes with 100 rows.

**L5 — Follow-up understanding (CODE).**
Resolve anaphora ("this/that") against the previous result before classification; fix the inverted `is_follow_up` tracker (turn 0 flagged true with 0 samples; the real follow-up flagged false); add a clarify outcome to QU.
*Proof:* QU's clarified_question for "Create a PDF of this" references the balance; flags match ground truth.

## Recommended next single change: **L1** (one lever, config-level, zero pipeline code)
Must flip green: **row 1**. Must materially improve: rows 6,8,9,10,11,13,14 (insider/deflection/fabrication claims disappear even where retrieval failures remain — an honest "could not find it in the documents" is the acceptable interim outcome for 8/9). Everything else frozen: same seeds, same data, no query-gen/retrieval changes this iteration.

## Bad seeds (suite revisions — do together with L1 iteration or after)
- **my-balance-then-pdf t0**: pin ground truth (does Julie own a unit? true balance) — outcome-agnostic standard is ungradeable.
- **ambiguous-balance-clarify t1**: replace with manager-realistic "what unit 310 owes" / "what is owed to union 5".
- **supplier-payables t0**: resolves to $0 → zero discrimination; pin an expected non-zero gate or seed data with open bills.
- **rights-duties-progressive**: persona-inconsistent (manager asking "my rights as co-owner") — move to a resident persona; keep the progressive-deepening design.
- tear-down-wall-law: keep (minor persona wrinkle only).

## Watch items
- Origin of the fabricated $22,753.51 (hallucination vs arithmetic over the 90 hidden rows) — one full-row trace decides whether L1's grounding rule alone fixes it.
- Supplier $0: real or dead scoping edge? (`(:Bill)-[:SUPPLIER]->(:Supplier)` count=558; no BELONGS_TO_UNION count for Bill) — one direct graph query decides.
- Team-3 board/meeting ground truth (291/230 edges suggest data exists) — confirm before judging post-L1 honest-empty answers.
- Where the clarify path lives (QU intent vs response affordance) — decide after one QU-schema trace.
- is_follow_up inversion — may be a trivial flag bug that also explains row 5's Unit→Contact focus flip.
- Harness blame on export turns (row 5 "retrieval 0.30") — fix before the next run or the scorecard mis-ranks export rows.
