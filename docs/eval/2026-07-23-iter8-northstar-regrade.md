# Semantic scorecard — iter-8 (2026-07-23, clean throttled run)

Note on normalization: team-info-insider t0 was reported by its grader as "score: 3" on a 0–1 rubric; treated as **0.30** (consistent with its failure list and verdict).

| Conversation / turn | Verdict | Score | Primary lever | One-line reason |
|---|---|---|---|---|
| team-info-insider t0 | FAIL | 0.30 | code | Presents Julie's own email as "how to reach your team" (insider auto-fail) + 10-of-100 roster shown as complete |
| team-roster-then-excel t0 | FAIL | 0.35 | code | 10 of 100 members presented as the complete roster despite explicit truncation note in prompt |
| team-roster-then-excel t1 | FAIL | 0.40 | understanding | QU dropped the roster anchor (entity_types=[]) → Excel lost the Role column; raw-question title |
| my-balance-then-pdf t0 | PASS | 0.65 | prompt-instructions | Honest zero, canonical formula — but hides that THREE units named "310" exist |
| my-balance-then-pdf t1 | FAIL | 0.45 | code | Canned 87ms export path: no data in chat, PDF titled "Unit" with three indistinguishable duplicate rows |
| ambiguous-balance-clarify t0 | FAIL | 0.20 | understanding | No clarify branch — QU guessed a reading, then queried nonexistent `a.balance` and narrated nulls as fact |
| ambiguous-balance-clarify t1 | PASS | 0.70 | prompt-instructions | Grounded honest zero, but collapses 3 homonymous units into one |
| tear-down-wall-law t0 | FAIL | 0.50 | prompt-instructions | Dilutes art. 1097's ¾ vote to "majority" — materially wrong legal advice; never applies the bearing-wall pivot |
| buyer-arrears-law t0 | FAIL | 0.25 | retrieval | Missed arts. 1059–1074 (the controlling provision); zero citations shipped despite enforcement recording "failed"; invented declaration carve-out |
| board-and-meetings t0 | FAIL | 0.30 | code | 10 of 47 as "the current board"; no year filter, unions mixed; president dead-end is a truncation artifact |
| board-and-meetings t1 | FAIL | 0.35 | code | System-voice "no results found"; no past-meeting fallback possible (no re-query path) |
| supplier-payables t0 | PASS | 0.85 | prompt-instructions | Textbook honest zero with verification columns; only miss is a generic closer |
| rights-duties-progressive t0 | PASS | 0.82 | code | Strong grounded rights overview; citation [3] misattributed; dropped art. 1090 absence qualifier |
| rights-duties-progressive t1 | PASS | 0.68 | retrieval | Genuine deepening, but arrears/recovery articles never retrieved; another misattributed citation |
| long-conversation-resilience t0 | FAIL | 0.45 | code | 10 of 100 shown as complete roster; no email column despite config flag |
| long-conversation-resilience t1 | FAIL | 0.10 | understanding | Seeded L5 trap fired: "add their last login" misrouted to docs; resolver rewrote "to it"→"to teams"; falsely claims data doesn't exist |
| long-conversation-resilience t2 | PASS | 0.90 | — | Fully doc-grounded how-to with citation, correct action link, memory undamaged |
| long-conversation-resilience t3 | PASS | 0.72 | code | Preserve-on-empty PROVEN: full 100-row export survives a failed turn + docs turn; title "Member" + missing email column |
| app-navigation-howto t0 | FAIL | 0.60 | prompt-instructions | Doc-grounded, fabrication-free steps — but omits the required team_settings action link; law chunk outranks app doc 0.98 vs 0.57 |
| cold-roster-excel t0 | FAIL | 0.55 | code | Reversed edge direction (`(u)-[:USER]->(c)`) → promised "Contact Info" column silently empty in all 100 rows |

**Totals:** 13 fail / 7 pass (65% fail). **All-20 mean: 0.506.** **Comparable-14 subset** (excluding the 6 new turns: resilience ×4, app-navigation, cold-roster): **9/14 fail, mean 0.486.**

**Honest historical comparison (14-turn basis):** baseline 10/14 @ 0.37 → iter-1 10/14 @ 0.46 → iter-2c 9/14 @ 0.52 → iter-3 11/14 @ 0.47 → **iter-8 9/14 @ 0.486**. Two fewer fails than iter-3 and +0.02 mean, but essentially flat against the iter-2c plateau. The five iterations since iter-3 built *new capability* (exports, conversation memory, app-knowledge corpus) — validated by the new turns (t2 0.90, t3 0.72, app-nav grounded, cold-roster produces a real deliverable) — rather than lifting old-turn quality. The new-turn mean is 0.55, dragged down by the intentionally-seeded L5 red (0.10).

# North-star verdict

Measured against the initial prompt — real value, measurably — the assistant today genuinely earns its keep in three places. First, **honest financial zeros**: supplier-payables (0.85) and both balance turns apply the canonical formulas with verification columns and refuse to invent figures against a zero-receivables dataset; a real Novo Gestion lead could trust those numbers. Second, **grounded legal counsel at the overview level**: rights-duties (0.82/0.68) delivers a genuinely expandable, corpus-faithful CCQ briefing that a lead could forward. Third — and this is the real iter-4-through-8 payoff — **the export/memory machine now works**: cold and follow-up exports ship real 100-row grounded spreadsheets, and resilience t3 proved the flagship scenario (export a roster after a failed turn AND an interleaved docs turn) end to end. That plumbing was the single most-requested capability and it is no longer a demo.

Where it still feels like a demo is precisely where a lead would notice fastest: **anything that returns a list lies about completeness**. Four separate turns presented 10 rows as a complete roster/board when 47–100 existed, with the truncation fact sitting verbatim in the composer's own prompt. A lead who knows her portfolio has 100+ users and multiple boards gets an answer that is confidently, checkably wrong — this is the single biggest trust-killer in the run and it caps every enumeration conversation regardless of how good retrieval and scoping are. Second, **follow-ups and ambiguity**: resilience t1 (0.10) told the user the platform lacks data it demonstrably has, and ambiguous-balance t0 (0.20) guessed instead of asking — both are the "interesting concept" failure mode the north star forbids. Third, **legal precision under paraphrase**: telling a manager that altering common portions needs a "majority" when the law says three-quarters, or inventing a declaration carve-out for buyer arrears, is worse than no answer — it's plausible wrong advice with a citation veneer.

The unsentimental summary: the system now has trustworthy *infrastructure* (scoping, exports, memory, honest zeros) and untrustworthy *composition and comprehension edges* (counts, follow-ups, thresholds, homonyms). The deterministic gates (2/20 failures) wildly overstate health versus the semantic 13/20 — the remaining value is almost entirely in layers the gates don't see.

# Value-gap map (the "general improvements" queue)

Ordered by user value × breadth.

**G1 — Deterministic count/truncation honesty in composition. [CODE]**
Rows: team-info t0, roster t0, board t0, resilience t0; contributes to cold-roster t0. The model *has* the "(Showing first 10 of N)" fact and the "never present partial as complete" rule and ignores both — prompt iteration is exhausted here. Change: a blocking post-composition guard (same pattern as citation enforcement, but blocking): if `result_count > rows_shown`, the answer MUST contain the total (or "N more") or it's regenerated/patched deterministically; plus a companion `count(*)` when LIMIT is hit so the true total is knowable (cold-roster's "100 of 100-capped" is currently unknowable). Expected gain: flips roster t0 and resilience t0 outright (truncation is their auto-fail), lifts team-info t0 and board t0 by ~0.2 each.

**G2 — L5 follow-up understanding + clarify branch. [UNDERSTANDING/CODE — queued lever CONFIRMED]**
Rows: resilience t1 (0.10), ambiguous t0 (0.20), roster t1 (0.40) — the three worst data-turn scores in the run. Evidence confirms every queued sub-symptom: reference resolver corrupts anaphors ("to it"→"to teams"), augmentation follow-ups fall into the docs corpus, entity_types empties on follow-up, no clarify branch exists anywhere (QU manufactures confident `clarified_question` from "Show me the balance."). Change: follow-up-aware QU that extends the recorded query for augmentation asks ("add column X" → re-emit prior cypher + projection), and a first-class clarify outcome when readings genuinely diverge. Expected gain: three flips, and it removes the "platform doesn't have that data" false claims — the most damaging single utterance class.

**G3 — Export composition quality (fast-path parity). [CODE]**
Rows: balance t1, cold-roster t0, roster t1, resilience t3 (weak pass). The 2–87ms template path bypasses everything shipped since iter-3: subject-based titles ("Member", "Unit", and a raw question-echo title survived despite question-echo being "BANNED"), the data-first-then-link contract, entity-config columns (email flagged "important in excels" yet absent), and dedup/context columns (three identical "310 | 0 | 0" rows). Change: route export answers through composition or enrich the template with (a) persisted-subject title, (b) inline data preview, (c) entity-config default columns, (d) disambiguation columns. Expected gain: flips balance t1, lifts resilience t3 and roster t1.

**G4 — Cypher schema validation pre-execution. [CODE]**
Rows: ambiguous t0 (nonexistent `a.balance` → 100 null rows narrated as fact), cold-roster t0 (reversed `USER` edge → silently empty promised column), board t0 (president artifact). Change: validate projected properties and relationship directions against the schema the prompt already contains; reject/regenerate on violation. These are silent-wrongness bugs — the worst kind under the north star. Expected gain: flips cold-roster t0, major lift on ambiguous t0 (with G2).

**G5 — Legal grounding fidelity. [CODE + RETRIEVAL — L3 queued lever CONFIRMED]**
Rows: buyer-arrears (0.25), wall-law (0.50), rights t0/t1 (citation misattribution). Three sub-parts: (a) make citation enforcement **blocking** — buyer-arrears shipped an uncited, partly fabricated legal answer *after* enforcement recorded "failed"; downgrade to honest "not found in documents" instead; (b) L3 article-boundary retrieval so 1059–1074 (buyer arrears/statement mechanism) and arrears/recovery articles are reachable — confirmed exactly as queued; (c) prompt rule: preserve numeric thresholds and conditions verbatim when paraphrasing law, cite per-claim. Expected gain: flips buyer-arrears, lifts wall-law and both rights turns.

**G6 — Portfolio disambiguation (homonyms, unions, member≠resident). [CONFIG/PROMPT]**
Rows: balance t0/t1, ambiguous t1, board t0; and the roster turns' undifferentiated "Contact"-role residents (this is the queued **member≠owner pivot**, confirmed in list form). Change: canonical domain-calculation formulas project union/building name whenever matching by name; guidance for multi-match ("three units named 310 across your portfolio"); roster answers frame staff vs resident accounts. Config-first. Expected gain: converts two weak passes to strong, lifts board t0 and all roster turns.

**G7 — Universal concrete closer + action links on how-to. [PROMPT — small, broad]**
Rows: ~9 turns end with generic "feel free to ask"; app-nav t0 fails its own standard by omitting the matching team_settings link. Root cause identified verbatim by three graders: the proactivity rule is scoped to "data answers" only. Change: extend to all answer types; require the matching action link on navigation questions. Cheap, one prompt edit, lifts nearly every score by 0.05–0.1.

**Queued-lever audit:** L5 — CONFIRMED, promoted (G2). L3 art-1069 — CONFIRMED (G5b). Multi-query orchestration — evidence supports it (count companion, president re-query, past-meeting fallback all need a second query) but keep DESIGN-FIRST per owner; G1's count companion is a narrow safe slice. Empty-error-answer bug — did NOT reproduce this run (ambiguous t0 returned prose); keep on watch, de-prioritize. member≠owner pivot — confirmed, folded into G6. Domain-curator reduction — no evidence either way this run.

# Recommended next single lever

**G1: blocking count/truncation honesty (code).** Rationale over L5: equal-or-greater breadth, near-zero design risk (reuses the shipped citation-enforcement pattern), purely deterministic so it can't regress composition quality, and it removes the single most credibility-destroying behavior for a real lead — while L5 touches QU internals and deserves the design-first treatment already mandated for orchestration work.

**Pre-registered success criteria:**
1. **Must flip to pass:** team-roster-then-excel t0, long-conversation-resilience t0 (truncation is each one's auto-fail).
2. **Must improve ≥0.15:** team-info-insider t0, board-and-meetings t0 (truncation removed; other defects remain).
3. **cold-roster-excel t0:** answer states a true total (companion count) instead of "first 100 — more may exist" hedging.
4. **No regressions:** supplier-payables, my-balance t0, rights-duties ×2, resilience t2/t3 hold their scores; export gates stay green.
5. Deterministic gate added to every list-turn seed: if row_count > displayed rows, answer must contain the total or explicit "N more".

# Suite health

**Harness/gate calibration (urgent):** the deterministic layer reported 2/20 failures against a semantic 13/20 — the gates are systematically too loose. min_rows≥1 passes truncation lies, insider-voice violations, and null-artifact answers. Add deterministic gates for: own-email-as-contact (team-info), truncation disclosure (all list turns), promised-column-nonempty (cold-roster's blank Contact Info). Also fix the harness bug on resilience t3: internal `verdict_label='failed'` contradicts the passing artifact evidence. And normalize the grader-output schema — one grader emitted "3" on a 0–1 scale.

**Seeds needing revision:** (1) tear-down-wall-law and rights-duties pin co-owner questions on Julie, a management-company LEAD with no unit — reframe as manager-voice ("what do I tell a co-owner who wants to remove a wall?") or add a resident persona; (2) roster t1's "phone where present" is untestable dead weight (no phone property on User/Contact) — name attainable columns; (3) my-balance/ambiguous standards must acknowledge the 3× homonymous "310" and require disambiguation; (4) board-and-meetings should name a union or gold-standard the clarify/per-union grouping; (5) cold-roster standard must define which population "my team" means and forbid a silently empty promised column.

**Coverage gaps (≤3 new conversations):**
1. **Law→data bridge:** third turn on rights-duties — "So how much is my contribution this year?" — tests the highest-value behavior (CCQ duty → user's live statement via my_statement) against the known-zero dataset; currently optional, never exercised.
2. **Owner/arrears pivot:** "Which owners in [named union] are behind on their fees?" — exercises the member≠owner distinction, per-union scoping, and privacy handling of arrears in one realistic collections workflow (the queued value-gap has no seed today).
3. **French conversation:** the entire suite runs EN for a Québec management firm. One FR conversation (data turn + export follow-up) tests FR answer, FR export title, and the lay→CCQ register expansions in the direction real users will use them.