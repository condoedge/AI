# Grade: p14-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p14-1001-A.json`, generated 2026-10-01T17:47. ai `badd2f1` + 503 dirty files. config_sha1 `e3b8b25b…` is unchanged from p13.
- **Models:** unchanged (QU gpt-4o-mini, QG gpt-4o, composition gpt-4o-mini). Preflight OK.
- **Harness:** 13/13 ok, 0 gate failures, 0 INFRA/VOID. All 13 turns are graded.
- **Rubric/strictness:** same as p9–p13. Pass ≥ 0.70, partial 0.40–0.69, fail < 0.40. Action = code-attached chips plus doors named in the text.

## Headline

- **Both targeted fixes work.**
  - **Column names:** the Excel cypher is now `… tr.role AS role, u.email AS email`. The header reads "Member Name | Role | Email", and the `columns_include` gate passes (it failed in p13).
  - **Navigation guard:** on ambiguous t0, QU now returns `navigation_target:null`. Nothing is attached, the composer takes the honest-empty branch, and the misleading "view your balance on My profile" answer is gone.
  - The team-settings chip is kept where the question is about the team (team-info and roster, entity type Team). It was dropped only on the board turn, where it was irrelevant.
- **Answer mean on all 13 turns: 0.658** (p13 0.654, +0.004). **On p11's 11 turns: 0.623** (p13 0.618). Both are the best in the series, but only marginally.
  - **Gains:** Excel +0.05, ambiguous t0 +0.05, team-info +0.05.
  - **Losses (composition variance):** tear-down −0.05 (art. 1045 paraphrased without its "which separate…" condition) and supplier −0.05 ("you owe" instead of "we owe").
- **Action mean: 0.769 on 13 turns / 0.745 on 11** (p13 0.762 / 0.736). The only change is ambiguous t0, which no longer gets the wrong My profile chip (+0.10).
- **Ambiguous t0 is still a fail (0.25).** It is back to the p12 honest empty, not the clarifying question the standard requires.
- **Security:** no `safe_stop`, no SecurityException and no security_gate rejection on any turn. **No false rejections.**

## Per-turn table

| # | Turn | p12 | p13 | **p14 answer** | **p14 action** | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.45 | 0.40 | **0.45** | 0.70 | partial (+0.05) | Same cypher as p13 (name, contact_email, contact_phone, team_address, created_at, updated_at). "Your team is called Novo Gestion", then the table. **The p13 bullet padding is gone.** The composition prompt is byte-identical to p13, so this is composition variance. `(that's you)` is on her email and `no_self_contact` passes. Still missing the team type and member count. The empty Team Address column and the timestamp columns are still rendered. QU `navigation_target:own_team` is kept (property_concepts empty, so the guard leaves it). **Chips:** My profile, Team settings. There are no members/portfolio/finance doors. |
| 1 | roster t0 | 0.80 | 0.80 | **0.80** | 0.60 | pass (=) | Same cypher, true_total = anchor_total = 523. "Showing 25 of 523 rows — ask for the full list or an export." The count is still only in the code line and still called "rows". QU navigation_target own_team → **null** (guard), and Team settings is **still attached** (entity Team), as intended. There is still no export chip. |
| 2 | roster t1 Excel | 0.35 | 0.85 | **0.90** | 0.90 | **pass (+0.05)** | **Fix (1) verified.** Cypher `RETURN u.name AS member_name, tr.role AS role, u.email AS email LIMIT 100`: 1 attempt, 0 repairs. Export `current_result`, `export_source_complete:true`, **523/523 rows, 523 distinct non-empty emails**, 2 homonym names. Header **Member Name, Role, Email**, and gate `columns_include` passes (`actual: [member_name, role, email]`). "Fields included: Member Name, Role, Email." Phone is neither promised nor blamed. The ambiguous "Contact" header is gone. The only deduction left is the hedge "with the available information". **Action:** Download Excel. |
| 3 | 310 t0 | 0.75 | 0.80 | **0.80** | 0.95 | pass (=) | Correct data: 3 union-labelled rows, all `$0.00`, 0 open invoices. The cypher differs from p13 (split MATCH, no LIMIT; QG warns) but the result is equivalent. Persisting issues: the numbered list repeats the table, the three homonyms are summed into one "total Balance of $0.00" (prompt-mandated), and it ends with "just let me know!" **Chips:** 3 union Unit 310 chips plus Receivables report. |
| 4 | 310 t1 PDF | 0.90 | 0.90 | **0.90** | 0.85 | pass (=) | `previous_result`, 3 rows, union column kept, "No values found for: Oldest Due". Same minor defects: raw `0` money cells in the PDF, and a QG call for an empty cypher (9,952 tokens). Title "Unit 310 Balance". **Action:** Download PDF. |
| 5 | ambiguous t0 | 0.25 | 0.20 | **0.25** | 0.40 | **fail (+0.05)** | **Fix (2) verified.** QU `navigation_target:null` (p13 `own_profile`), with `property_concepts:["balance"]` and `data_query`. QG `needs_query:false` (empty cypher). Nothing is attached, so the composer takes the `empty_result` branch. Answer: "I checked for your account balance records, but it appears that there are no linked records available for you." This is the p12 honest empty, nearly word for word: true (she owns no unit), but **still not the required clarifying question** (`needs_clarification:false`, no candidate readings). It also has "linked records" jargon and says "I checked", although no query ran. No wrong door. |
| 6 | ambiguous t1 | 0.75 | 0.85 | **0.85** | 0.95 | pass (=) | Same data as 310 t0, LIMIT 100. The lead says 3 records at $0.00 (merged total, prompt-mandated). The table is correct and has no numbered-list padding. The closing "You can view more information about each unit using the links below" mentions links, against the instruction. **Chips:** 3 union Unit 310 chips plus Receivables. |
| 7 | tear-down t0 | 0.55 | 0.45 | **0.40** | 0.55 | partial (−0.05) | Same 4 chunks (1042–1047, 1064–1068, 1098–1102, units doc). The first sentence frames the separating-wall condition correctly. It then **paraphrases art. 1045 without its qualifier**: "walls that are not part of the foundations and main walls … are presumed to be common portions [1]". As written, that makes an interior partition sound common, which is the opposite of what the standard needs. "Major changes typically require a majority vote [3]" is a vague generalisation of 1098 (which requires three-quarters / 90% to change the destination). Point 3 (consent of creditors when altering private-portion boundaries) still does not apply to her case. No path to deepen. The prompt is identical to p13 apart from relevance percentages, so this is composition variance. |
| 8 | buyer-arrears t0 | 0.70 | 0.60 | **0.60** | 0.70 | partial (=) | Article 1069 is named explicitly and the liability is grounded in [1]. "Request a statement … before completing the purchase" is given only as advice. **The protective effect is still missing:** the standard requires saying that the statement mechanism protects a buyer who requests it, and p12 had the 15 days. The prompt is byte-identical to p13, so this is composition variance. No door to "how to request the statement". |
| 9 | board t0 | 0.55 | 0.50 | **0.50** | 0.70 | partial (=) | Same cypher and 37 rows, union column, "Showing 25 of 37". "Roles are not recorded … president's name is also not specified." It still says "**your union**" (singular) over 8 unions, and "Bm Role" still sits over an all-empty column. The COLUMN NAMES rule is in the prompt, but the cypher returns unaliased `c.first_name, cur.bm_role, cur.year`, so the rule is not obeyed here. **Chips:** 5 of 8 unions (no "+N more"), Contacts, Download Excel. Team settings was dropped (QU nav own_team → null); it was irrelevant on this turn. |
| 10 | board t1 meetings | 0.40 | 0.40 | **0.40** | 0.90 | partial (=) | **The cypher changed** (QG variance: the prompt differs only by the COLUMN NAMES line and history). It is now `MATCH (m:Meeting) WHERE … m.start_date > toString(date()) RETURN m.name, m.start_date, m.description … LIMIT 1`. The non-optional `Schedule` MATCH is gone, so this empty is a genuine "nothing upcoming". The answer is unchanged in substance: "no linked records available … You can review the existing meetings for more context." There is still no past-meeting fallback and the "linked records" jargon persists. **Chip:** Meetings. |
| 11 | owner-arrears t0 | 0.35 | 0.85 | **0.85** | 0.90 | pass (=) | Same cypher. All 4 units, largest first: $456.12 / $397.81 / $307.55 / $50.00, total $1,211.48, owners, date-only Oldest Due. "4 **owners**" should still be 4 units / 5 owners, and the closing is still generic. **Chips:** Unit 305/311/308/605 plus Receivables report. |
| 12 | supplier t0 | 0.40 | 0.90 | **0.85** | 0.90 | pass (−0.05) | Exact: $11,493.17, 4 open bills, $7,022.87 / $3,075.60 / $819.82 / $574.88. **The voice slipped to "you owe … your suppliers"**; the standard requires "we owe". The prompt differs only in the "These rows answer" label, so this is composition variance. Oldest Due still mixes datetimes and a date. "Using the link below" mentions a link, against the instruction. **Chip:** Payables. |

## Means

| Run | Answer, p11's 11 turns | Action, 11 turns | Answer, all 13 | Action, all 13 |
|---|---|---|---|---|
| p11 | 0.573 | 0.727 | n/a (2 VOID) | n/a |
| p12 | 0.505 | 0.723 | 0.554 | 0.750 |
| p13 | 0.618 | 0.736 | 0.654 | 0.762 |
| **p14** | **0.623** | **0.745** | **0.658** | **0.769** |

- **Answer:** sum 8.55 against 8.50.
  - Gains: Excel +0.05, ambiguous t0 +0.05, team-info +0.05.
  - Losses: tear-down −0.05, supplier −0.05.
- **Action:** ambiguous t0 +0.10 (wrong door removed). Board loses Team settings, but the chip was irrelevant there, so its action score is unchanged.

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p13, p11's 11 | 5 | 5 | 1 |
| **p14, p11's 11** | **5** | **5** | **1** |
| p13, all 13 | 7 | 5 | 1 |
| **p14, all 13** | **7** | **5** | **1** |

- **Pass:** roster, Excel, 310 t0, PDF, ambiguous t1, owner-arrears, supplier.
- **Partial:** team-info, tear-down, buyer, board, meetings.
- **Fail:** ambiguous t0.

## Fix verification

| Change | Status | Evidence |
|---|---|---|
| (1) COLUMN NAMES rule | **WORKS on the target turn; not obeyed everywhere** | The line `COLUMN NAMES: alias each returned column after the PROPERTY it holds (u.email AS email)…` is in every QG prompt that has a REQUESTED ATTRIBUTES block (turns 1–6, 9–12). Excel: `u.email AS email`, header "Email", gate passes. Board ignores it: `c.first_name, c.last_name, cur.bm_role, cur.year` are left unaliased, so the "Bm Role" header persists. The rule is prompt-only; the generator does not always comply. |
| (2) Navigation guard | **WORKS** | Two layers: the QU prompt line 248 ("whenever the user asks for information, values or records … null") and the code guard at `src/Services/QueryUnderstandingService.php:726` (`data_query` with non-empty `property_concepts` → null). Effects: ambiguous t0 own_profile → null, roster own_team → null, Excel own_team → null, board own_team → null. Team-info keeps own_team (no property concepts), which is correct. Ambiguous t0 now goes to the `empty_result` branch with no chip. The team-settings chip is still attached on team-info and roster (entity Team). |
| (2) Answer path, ambiguous t0 | **Honest, but not a clarification** | The answer is true ("no linked records … for you") but never asks which balance. Residual code risk: `ResponseGenerator.php:896–897` still enters the navigation branch whenever the cypher is empty **and** any chip is attached, regardless of navigation_target. It did not fire here only because nothing was attached. A `needs_query:false` data question that picks up an identity chip would be hijacked again. Suggested fix: gate the branch on `is_string(navigation_target)` alone. |
| (3) Unicode alias collapse | **No Part A impact** | Accented names (Valérie, Carré, Hydro-Québec) are intact in rows, exports and cypher literals (`'SDC Carré Soho Phase 3'` matched 4 rows). No alias issue appeared in Part A. |
| (4) Nothing else changed in Part A paths | **CONFIRMED** | Composition prompts are byte-identical to p13 on team-info, roster, buyer, meetings and owner-arrears. The other composition diffs are the "These rows answer" label wording, relevance percentages, the attached-chip list on board, and the empty-result branch on ambiguous t0. QG prompt diffs are the COLUMN NAMES line, the date, the clarified question, and entity-selection variance (310 t0 picked up Contact, ContactUnit and Bank through calculation/closure tiers: +5.4k chars). |
| Security | **NO FALSE REJECTIONS** | All 13 `security_gate` stages passed. No `safe_stop:true` and no SecurityException. `security_filter_applied:true` on every executed cypher; `false` only on the two empty-cypher turns (PDF, ambiguous t0). There is no `"passed": false` anywhere in the file. |

## Regressions vs p13 (with traced cause)

1. **Tear-down −0.05: composition variance.**
   - The prompt is identical apart from relevance percentages.
   - The model rephrased art. 1045 and dropped "but which separate a private portion from a common portion or from another private portion".
   - That inverts the presumption for an interior partition. This is a grounding-fidelity slip, not a retrieval change.
2. **Supplier −0.05: composition variance.**
   - The only prompt difference is the "These rows answer" label: "current supplier debt" against "current supplier debt amount".
   - The voice flipped from "we owe" to "you owe".
3. **Board loses the Team settings chip.** This follows from the guard (own_team → null). It is not scored as a regression, because the chip was irrelevant to a board question.
4. **Not regressions:**
   - The meetings cypher changed through QG variance, and the new one is more correct (no non-optional Schedule join).
   - The 310 t0 cypher dropped its LIMIT (QG warns). The result is identical.

## New and persisting defects

- **New / residual:**
  - The `ResponseGenerator` navigation branch still keys on "empty cypher + any attachment" (see Fix verification).
  - The COLUMN NAMES rule is not enforced on dotted, unaliased returns (board).
  - The empty-result composer says "I checked …" when QG returned `needs_query:false` and nothing executed (ambiguous t0).
- **Persisting:**
  - **ambiguous t0:** never clarifies.
  - **team-info:** no type and no member count, empty Team Address column, timestamps as padding.
  - **meetings:** no past-meeting fallback, "linked records" jargon.
  - **board:** "your union" (singular), "Bm Role" over an empty column, no "+N more" union chip.
  - **roster:** no export chip, count given as "rows".
  - **owner-arrears:** "4 owners" for 4 units / 5 owners.
  - **supplier:** mixed datetime/date in Oldest Due.
  - **PDF:** raw `0` money cells, and a QG call for an empty cypher.
  - **The FIRST-sentence totals directive:** contradicts the never-merge rule for homonyms on 310.
  - **buyer:** the 15-day protection is still missing.
  - **Link mentions:** "links below" / "link below" appear despite the instruction (ambiguous t1, supplier).

## Tokens / latency vs p13

- **Tokens:**

  | Run | Total | Calls |
  |---|---|---|
  | p13 | 182,180 | 35 |
  | **p14** | **184,231** | **35** |

  - +2.1k overall (+1.1%).
  - 310 t0 +1.5k: entity selection added Contact, ContactUnit and Bank to the QG prompt.
  - PDF +1.6k: larger QG prompt for an empty cypher.
  - Ambiguous t1 −1.4k.
  - Every other turn moved by less than ±0.2k.
  - The COLUMN NAMES line costs about 30 tokens per QG call.
- **Latency:**

  | Run | Sum | Mean | Median |
  |---|---|---|---|
  | p13 | 244.0 s | 18.8 s | 17.6 s |
  | **p14** | **154.5 s** | **11.9 s** | **11.7 s** |
  | p13, p11's 11 turns | 221.1 s | 20.1 s | 18.0 s |
  | **p14, p11's 11 turns** | **135.0 s** | **12.3 s** | **11.7 s** |

  - The gain is provider-side and in understanding: Excel 33.2 → 5.4 s, team-info 20.7 → 14.7 s, board 14.2 → 5.9 s.
  - Supplier QG went from 13.8 to 3.2 s.
  - The only slow call was owner-arrears understanding at 11.1 s (17.2 s pipeline).
