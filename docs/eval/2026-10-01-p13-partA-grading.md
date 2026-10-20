# Grade: p13-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p13-1001-A.json`, generated 2026-10-01T15:42. ai `badd2f1` + 501 dirty files, config_sha1 `e3b8b25b…` (p12 `ba08294d…`).
- **Models:** unchanged (QU gpt-4o-mini, QG gpt-4o, composition gpt-4o-mini). Preflight OK.
- **Harness:** 12 ok, 1 gate-failed (`team-roster-then-excel#1 columns_include`: "Missing column(s): email (inspected result rows)"). 0 INFRA/VOID, so all 13 turns are graded.
- **Rubric/strictness:** same as p9–p12. Pass ≥ 0.70, partial 0.40–0.69, fail < 0.40. Action = code-attached chips plus doors named in the text.

## Headline

- **The P0 currency fix works.** Every code-rendered money cell is correct now: `$456.12`, `$7,022.87`, `$0.00`. Owner-arrears and supplier are back to the p12 counterfactual (0.85 / 0.90).
- **The Excel turn is a real pass, and the gate failure is a false negative on the column name.**
  - The file is a fresh `current_result` holding **all 523 of 523 members**, with name, **email** and role.
  - The email column is aliased `contact` (`u.email AS contact`). The gate token-matches column keys, so `contact` does not satisfy `email`. Traced below.
- **Answer mean on all 13 turns is 0.654 (p12 0.554, +0.100). On p11's 11 turns it is 0.618** (p12 0.505, p11 0.573). Both are the best in the series.
  - The gains come from the currency fix and the Excel turn.
  - Small losses on team-info, ambiguous t0, tear-down, buyer and board (all composition or QU variance). One of them, ambiguous t0, exposes a **new code defect**: the navigation branch.
- **Security:** no `safe_stop`, no SecurityException and no security_gate rejection on any of the 13 turns. No false rejections.

## Per-turn table

| # | Turn | p11 | p12 | **p13 answer** | **p13 action** | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.45 | 0.45 | **0.40** | 0.70 | partial (−0.05) | Same cypher as p12 (name, contact_email, contact_phone, team_address, created_at, updated_at). Still no team type and no member count. **New padding:** the narrative repeats every table field as bullets, then shows the same table. The empty Team Address column is still rendered, although the text says "address is not recorded". "(that's your own email)" is kept and `no_self_contact` passes. The prompt is identical to p12 apart from the wording (4) and the language anchor, so this is composition variance. **Chips:** My profile, Team settings. There are no members/portfolio/finance doors. |
| 1 | roster t0 | 0.80 | 0.80 | **0.80** | 0.60 | pass (action +0.05) | Cypher is `MATCH … MATCH (u:User) WHERE tr.user_id = u.id RETURN u.name, tr.role LIMIT 100`, with true_total = anchor_total = 523. The query used non-OPTIONAL MATCH this time, so there is no fan-out and **FanOutCollapse did not fire**. "Showing 25 of 523 rows — ask for the full list or an export." The count is still only in the code line, and still called "rows". **Change (3) works:** the My profile chip is gone and only Team settings is attached. There is still no export chip, although the text invites an export. |
| 2 | roster t1 Excel | 0.35 | 0.35 | **0.85** | 0.90 | **pass (+0.50)** | The file is `current_result`, `export_source_complete:true`, 523/523 rows (2 homonym names, each with a distinct email), columns **Member Name, Contact (= User.email, 0 blanks), Role**. Phone is neither promised nor blamed, which the standard requires. "I prepared the Excel file with the available information. Fields included: Member Name, Contact, Role." Title is "Team Members Contact Information" (QU subject_label). Deductions: the email column is headed "Contact" next to a Role column whose value is mostly "contact", which is ambiguous to a reader. The gate fails on that alias (see the trace). **Action:** Download Excel link. |
| 3 | 310 t0 | VOID | 0.75 | **0.80** | 0.95 | pass (+0.05) | Correct data: 3 union-labelled rows, all $0.00, 0 open invoices. **The table now reads `$0.00`** (P0 fixed). Deductions: the numbered list repeats the table, which is the p10 padding coming back. The lead sums the three homonyms into one "total Balance", which the prompt's FIRST-sentence directive mandates. It ends with "just let me know!" **Chips:** 3 union Unit 310 chips plus Receivables report. |
| 4 | 310 t1 PDF | VOID | 0.90 | **0.90** | 0.85 | pass (=) | `previous_result`, 3 rows, union column kept, "No values found for: Oldest Due". Same minor defects as p12: the PDF cells show raw `0` and not `$0.00`, because the export path does not use the currency formatter, and a QG call ran for an empty cypher (1 call, about 9k tokens). **Action:** Download PDF. |
| 5 | ambiguous t0 | 0.25 | 0.25 | **0.20** | 0.30 | **fail (−0.05)** | QU: `navigation_target:own_profile`, `needs_clarification:false`, clarified "account balance associated with my profile". QG returned `needs_query:false` (empty cypher), and the composer took the **new navigation branch**: "You can view your balance by accessing the 'My profile' page attached below." Julie owns no unit, so this sends her to a page that has no balance. It is a misleading door instead of an honest empty, and still not the required clarifying question. **Chip:** My profile, which is wrong for this question. See New defects. |
| 6 | ambiguous t1 | 0.85 | 0.75 | **0.85** | 0.95 | pass (+0.10) | Same cypher and data. Table money is correct, all 3 union rows are shown, there is no numbered-list padding, and the lead is correct (3 records, $0.00). Mild issues: the merged "total balance across all of them" (prompt-mandated) and the closing "feel free to check the individual records". **Chips:** 3 union Unit 310 chips plus Receivables. |
| 7 | tear-down t0 | 0.45 | 0.55 | **0.45** | 0.55 | partial (−0.10) | Same 4 chunks (38, 61, 113, 2). The answer quotes art. 1045 accurately as the presumption for walls separating portions [1]. It then says only "check whether the wall is classified as a common portion". **The p12 branch "if it is entirely within your private portion you have more leeway" is lost**, so it no longer distinguishes an interior partition from main walls. Point 3 (altering boundaries between private portions, [3]) does not apply to her case. The p12 mis-citation of `[2]` is gone. No invented article numbers, no deepen path. The prompt is identical except for the language anchor, so this is composition variance. |
| 8 | buyer-arrears t0 | 0.75 | 0.70 | **0.60** | 0.70 | partial (−0.10) | Chunk 66 (1068.1–1070) is first. The liability is stated correctly and grounded in [1]. It advises requesting the statement "as this will inform you of any amounts due". **It omits the protection:** the buyer is bound only if the syndicate provides the statement within 15 days. That is the second half the standard requires, and p12 had the 15 days. Retrieval changed slightly (chunk [2] is now 1063–1067 instead of 1071–1074), but 1069 was in the prompt both times, so this is composition variance. No door to "how to request the statement". |
| 9 | board t0 | 0.40 | 0.55 | **0.50** | 0.70 | partial (−0.05) | Same cypher and 37 rows, with the union column, and "Showing 25 of 37". "Roles, including president, not recorded" is stated, and change (4) is in the prompt. The opener still says the board "and the president are not specified", and **now calls the rows "the list of members from your union"**: singular, while the table spans 8 unions. The "Bm Role" header still sits over an all-empty column. Ends with "feel free to check the relevant records". **Chips:** 5 of 8 unions (no "+N more"), Team settings, Contacts, Download Excel. |
| 10 | board t1 meetings | 0.40 | 0.40 | **0.40** | 0.90 | partial (=) | Same non-optional `MATCH (s:Schedule)` after `LIMIT 1`, so the result is empty. "I couldn't find any linked records for upcoming meetings or their agendas." No past-meeting fallback, and the "linked records" jargon persists. **Chip:** Meetings. |
| 11 | owner-arrears t0 | 0.70 | 0.35 | **0.85** | 0.90 | **pass (+0.50)** | All 4 units, largest first, with **correct balances** ($456.12 / $397.81 / $307.55 / $50.00), the total $1,211.48, owners (5 names), and date-only Oldest Due. Union 8 only. Deductions: "4 **owners**" should be 4 units / 5 owners, and the weak closing "You can review the details…" does not name the receivables report. **Chips:** Unit 305/311/308/605 plus Receivables report. |
| 12 | supplier t0 | 0.90 | 0.40 | **0.90** | 0.90 | **pass (+0.50)** | "Currently, **we owe** a total of $11,493.17 to our suppliers, with 4 open bills." The voice is back to "we". The breakdown is exactly $7,022.87 / $3,075.60 / $819.82 / $574.88. Remaining issue: Oldest Due still mixes datetimes (3 rows) with one date. **Chip:** Payables. |

## Means

| Run | Answer, p11's 11 turns | Action, 11 turns | Answer, all 13 | Action, all 13 |
|---|---|---|---|---|
| p10 | 0.582 | 0.723 | 0.627 | 0.750 |
| p11 | 0.573 | 0.727 | n/a (2 VOID) | n/a |
| p12 | 0.505 | 0.723 | 0.554 | 0.750 |
| **p13** | **0.618** | **0.736** | **0.654** | **0.762** |

- **Answer:** +0.100 on 13 turns and +0.113 on 11 vs p12. On the 11 turns it is +0.045 vs p11.
  - Gains: Excel +0.50, owner +0.50, supplier +0.50, ambiguous t1 +0.10, 310 t0 +0.05.
  - Losses: team-info −0.05, ambiguous t0 −0.05, tear-down −0.10, buyer −0.10, board −0.05. These sum to −0.35, all composition or QU variance; ambiguous t0 also surfaces a code-path defect.
- **Action:** roster +0.05 (My profile chip removed), Excel +0.20 (a real file), ambiguous t0 −0.10 (wrong My profile door).

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p11 (11 turns) | 5 | 4 | 2 |
| p12, same 11 | 3 | 5 | 3 |
| **p13, same 11** | **5** | **5** | **1** |
| p12, all 13 | 5 | 5 | 3 |
| **p13, all 13** | **7** | **5** | **1** |

- **Pass:** roster t0, Excel, 310 t0, 310 PDF, ambiguous t1, owner-arrears, supplier.
- **Partial:** team-info (0.40), tear-down, buyer, board, meetings.
- **Fail:** ambiguous t0.

## Excel trace (team-roster-then-excel #1): why the gate says `email` is missing

1. **Roster t0** generated `MATCH (t:Team) WHERE t.id = 3 MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) MATCH (u:User) WHERE tr.user_id = u.id RETURN u.name AS member_name, tr.role AS role LIMIT 100`.
   - Execution gave `true_total 523, anchor_total 523`.
   - No fan-out occurred, so there is no `fan_out_collapse` stage. The pre-collapse and recorded cyphers are identical.
2. **Excel QU:**
   - `question_type:data_query`, `intent:file_listing`, `follow_up_reference:true`, `export_format:excel`, `navigation_target:own_team`
   - `entity_types:[Team, TeamRole, User, ContactDirectory]`
   - **`property_concepts:["contact","role"]`**: p12 had `["contact","email","phone"]`, so the QU no longer expands to phone.
   - `subject_label:"Team Members Contact Information"`
3. **QG prompt:**
   - **Active Filter:** `` `t.id = 3` ``. Clean; p12 had a garbled slice of the collapsed query.
   - **Previous Query:** the roster cypher above, served from `last_generated_cypher` (`src/Services/Context/ConversationContextManager.php:143`, `src/Services/PromptSections/ConversationContextSection.php:191–196`). The EXTEND block follows.
   - **REQUESTED ATTRIBUTES** for "the User records being listed": `- contact: RETURN it as a column when a property holds it for the User records being listed; otherwise omit it (not recorded for User)` and `- role: …`, with the new NOT RECORDED / never-borrow rule. No phone line.
4. **Generated cypher:** 1 attempt, 0 repairs, 0 schema violations, 6,850 QG tokens:
   ```
   MATCH (t:Team) WHERE t.id = 3 MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) MATCH (u:User) WHERE tr.user_id = u.id
   RETURN u.name AS member_name, u.email AS contact, tr.role AS role LIMIT 100
   ```
   - It is a correct extension: same MATCH/WHERE, previous columns kept, User.email added. No borrowed `t.contact_phone`.
5. **Execution:** success, 100 rows, true_total 523, anchor_total 523, `security_filter_applied:true`, no errors, no repair, no collapse (row_total equals anchor_total).
6. **Export:**
   - `export_source:"current_result"`, `export_source_complete:true`, `rows:523`. The LIMIT was widened under the 2000 Excel limit, against 250 of 523 in p12.
   - Header `| Member Name | Contact | Role |`, all 523 emails non-empty.
   - Answer: "Fields included: Member Name, Contact, Role."
   - The "Requested fields not included" disclosure correctly did not fire, because nothing requested failed.
7. **Gate:** `ExpectationGate::gateColumnsInclude` (`CondoEdge/vendor/condoedge/ai-eval/src/Services/ExpectationGate.php:195–229`) inspects the **keys** of the result-row sample (`member_name, contact, role`) and token-matches each expected concept. `name` matches `member_name`; `email` matches no key. Hence "Missing column(s): email (inspected result rows)".

**Root cause:**
- The data is right; the failure is the **column alias**.
- The QU kept the user's generic concept word "contact" ("their contact info"), and `NameResolutionSection.php:96–98` renders it verbatim as `- contact: RETURN it as a column`.
- The generator then named the output column after the *concept*, not after the property it mapped to: `u.email AS contact`.
- The result is a gate miss, plus a real readability problem: a "Contact" header above emails, next to a Role column whose value is mostly "contact".

**Fix:**
- **Product side, preferred.** In `src/Services/PromptSections/NameResolutionSection.php:97`, append to each concept line, or once after the loop: "alias the column after the property it maps to (e.g. `u.email AS email`, `u.phone AS phone`), never after the concept word". Add a unit test in `tests/Unit/Services/PromptSections/NameResolutionSectionTest.php`.
  - A structural alternative: when a RETURN alias equals a QU concept and the expression is a single `var.prop`, have TableRenderer / the export header use the property name ("Email").
- **Gate side:** do not loosen it. A value-shape check (email regex on the column values) would also be defensible, but the header ambiguity is a real UX defect, so fix the product.

## Fix verification

| Change | Status | Evidence |
|---|---|---|
| (1) P0: tables spliced with `preg_replace_callback` | **WORKS** | Owner-arrears $456.12/$397.81/$307.55/$50.00, supplier $7,022.87/$3,075.60/$819.82/$574.88, and 310 t0 and ambiguous t1 `$0.00`: all render correctly in the final answer. PDF export cells are still raw `0` (a separate formatter gap, not the P0). |
| (2a) Generator extends `last_generated_cypher` (pre-collapse) | **WORKS in the prompt; collapse path NOT EXERCISED** | The Previous Query is the generated roster cypher and the Active Filter is clean (`t.id = 3`). The roster did not fan out this run (MATCH rather than OPTIONAL MATCH, 523 = 523), so the pre-collapse and collapsed cyphers would have been identical. The p12 failure mode was therefore not re-tested. |
| (2b) REQUESTED ATTRIBUTES only for properties of the listed entity | **WORKS (no borrowing)** | The block names "the User records being listed" and carries the NOT RECORDED / never-borrow rule. No team phone appears in the cypher. QU no longer emits "phone", so the not-recorded path itself was not stressed. |
| (2c) CypherScopeHint repair hint | **NOT EXERCISED** | 0 execution errors in all 13 turns. The code is present (`src/Services/Query/CypherScopeHint.php`, wired in `QueryGenerator.php`). |
| (2d) Failed-extension disclosure "Requested fields not included" | **NOT EXERCISED** | The extension succeeded. Correctly, there was no false disclosure. |
| (2e) Export limit Excel 2000 | **WORKS** | 523/523 exported, `export_source_complete:true`, against 250/523 in p12. |
| (3) My profile chip only when the user is QU's primary type or the only row | **WORKS (roster)** | Roster chips: Team settings only. Team-info (the only row is her own team) keeps My profile plus Team settings, which is acceptable. |
| (4) "These rows answer" describes only what the columns hold | **LANDED (prompt); mixed effect** | The new sentence is in every data prompt (team-info, roster, board, owner, supplier). Board still says the president is "not specified" and mislabels the rows as "members from your union". |
| (5) Language anchor at end of user prompt | **WORKS** | Every composition prompt ends with "Write the answer in the question's language ('en')…". All 13 answers are in English. |
| (6) Localized subject_label for export titles | **WORKS (en)** | Export titles: "Team Members Contact Information" and "Unit 310 Balance Owed". No French turn was available to test localization the other way. |
| (7) Security | **NO FALSE REJECTIONS** | All 13 security_gate stages passed. No `safe_stop:true` and no SecurityException. `security_filter_applied:true` on every executed data cypher. `false` appears only on the two empty-cypher turns (PDF, ambiguous t0), where nothing executed. The only `passed:false` in the file is the harness `columns_include` gate. |

## Regressions vs p12 (with traced cause)

1. **Ambiguous t0: honest empty became a misleading navigation answer** (−0.05 answer, −0.10 action).
   - QU (gpt-4o-mini) set `navigation_target:own_profile` (p12: null), and QG answered `needs_query:false`.
   - `ResponseGenerator.php:895–896` then sets `$navigation = !$attached->isEmpty() && (cypher === '' || is_string(navigation_target))`. That swaps the empty-result instruction for "This request needs no data lookup: it asks to open or reach a page" (line 900).
   - The branch has no check that the question is a navigation ask. This one was a `data_query`/`lookup` for a balance, with property_concepts `["balance"]`.
   - Fix: require a navigation intent, e.g. QU `property_concepts` empty, or an intent/question_type that denotes navigation. A data-concept question with an empty cypher should fall to the honest-empty or clarification path.
2. **Tear-down −0.10 and buyer −0.10:** composition variance. The prompts are identical apart from the language anchor, and the 1069 passage was present for buyer. The private-portion branch and the 15-day protection were dropped.
3. **Team-info −0.05:** the narrative duplicates the 1-row table as bullets (composition variance). The table/narrative contract ("do not list the rows") is apparently not given on 1-row results. Worth checking in `DataAnswerPlan`.
4. **Board −0.05:** "members from your union" (singular) for a list that spans 8 unions.
5. **310 t0:** the numbered-list padding came back. It scores +0.05 overall only because the P0 is fixed.

## New and persisting defects

- **New:**
  - The navigation branch hijacks empty data questions (above).
  - The concept-word alias (`u.email AS contact`) gives an ambiguous "Contact" header and the gate miss.
- **Persisting:**
  - **team-info:** no type and no member count, an empty Team Address column, timestamps as padding.
  - **ambiguous t0:** never clarifies (`needs_clarification:false`, no candidate readings).
  - **meetings:** non-optional Schedule MATCH after LIMIT 1, "linked records" jargon, no past-meeting fallback.
  - **board:** "Bm Role" header over an empty column, no "+N more" union chip, contradictory opener.
  - **roster:** no export chip, count given as "rows".
  - **owner-arrears:** "4 owners" for 4 units / 5 owners.
  - **supplier:** mixed datetime/date in Oldest Due.
  - **PDF:** raw `0` money cells, and a QG call for an empty cypher.
  - **The FIRST-sentence totals directive:** contradicts the HOMONYMS "never merge into one figure" rule on the 310 turns.

## Tokens / latency vs p12

- **Tokens:**

  | Run | Total | Calls |
  |---|---|---|
  | p12 | 189,377 | 36 |
  | **p13** | **182,180** | **35** |

  - −7.2k overall (−3.8%).
  - The Excel turn went from 19,370 to **12,627** (−6.7k): 1 QG attempt instead of 2 failed ones, and no composition LLM call.
  - 310 t0 −1.0k, PDF −1.0k, buyer −1.0k, ambiguous t1 +1.8k. Every other turn moved by less than ±0.3k.
- **Latency:**
  - Pipeline sum **244.0 s**, mean 18.8 s, median 17.6 s, against p12 207.8 / 16.0 / 14.6.
  - On p11's 11 turns: 221.1 s, mean 20.1 s, median 18.0 s, against p12 167.3 / 15.2 / 14.6.
  - The increase is provider-side. Single-call understanding took 33.2 s on Excel (5.8k tokens, 1 call), 20.7 s on team-info and 14.2 s on board, and supplier QG took 13.8 s for 1 attempt.
  - Excel execution took 8.4 s: count, 100 rows, then the 523-row export re-execution.
  - Turns without slow provider calls took 9–14 s, in line with p12.
