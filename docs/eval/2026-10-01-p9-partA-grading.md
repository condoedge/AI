# Grade: p9-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p9-1001-A.json`, generated 2026-10-01T01:26. ai git `badd2f1` + 469 dirty files, config_sha1 `5566d2f6…`.
- **Models:** QU gpt-4o-mini, query generation gpt-4o, composition gpt-4o-mini.
- **Harness result:** 12 ok, 1 gate-failed (team-info#0 `no_self_contact`). INFRA/VOID: 0. I agree: there are no errors, fallbacks or retries, and every validation came back clean.
- **Standards:** current `suite-partA.json` (money standards rewritten 2026-09-30).
  - **"Standard changed"** marks turns whose truth moved since the p4 grading:
    - owner-arrears: was an honest zero, is now 4 units / $1,211.48.
    - supplier: was an honest $0, is now $11,493.17 on 4 bills.
    - The unit-310 turns kept the same $0 truth; only the wording was tightened, to a per-unit verifiable zero.
- **Scoring:** answer score uses pass ≥ 0.70, partial 0.40–0.69, fail < 0.40. The action score (0–1) grades the code-attached chips (`response_metadata.invocations`) and any links in the text, separately from the answer.

## Per-turn table

| # | Turn | Base | p4 | **p9 answer** | **Action** | Verdict | Family | Why (trace evidence) |
|---|---|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.55 | 0.50 | **0.30** | 0.70 | **fail (regression)** | self-contact via code table + cypher coverage | **Answer:** "Your team is called Novo Gestion" (insider), followed by the code-rendered table `Novo Gestion \| j.rousseau@novogestion.ca \| 514-400-7414`. The user's own email is presented as the team's **Contact Email** with no that's-you marker (gate failed; see investigation). There is no team type ("management company") and no member count: the cypher returns only Team id/name/contact/address. It closes with the filler "feel free to check the attached links below". **Chips:** My profile, Team settings. Both are relevant identity doors and nothing is wrong or invented. There are no members, portfolio or finance doors. |
| 1 | roster t0 | 0.85 | 0.85 | **0.80** | 0.55 | pass | — | 25-row name+role table in insider voice, with the code line "Showing 25 of 523 rows — ask for the full list or an export." Names and roles are present for every row shown. It is slightly weaker than p4: the total is only in the code line, worded as "rows" rather than "523 members", and the cypher no longer projects `u.email`. **Chips:** My profile (irrelevant here) and Team settings (fits). The text invites an export, but **no export chip is attached**, while board t0 did get one, so the export door is inconsistent. There are no member record chips. |
| 2 | roster t1 Excel | 0.65 | 0.75 | **0.45** | 0.75 | partial (**regression**) | follow-up detection / export source | QU `follow_up_reference:false` led to `export_source: current_result`, a **fresh query** instead of the previous result. The new cypher **drops TeamRole** and OPTIONAL-joins ContactDirectory: the columns are Member Name, Member Email, Contact Info and **Role is missing** (the standard requires name, role, email). The join fans out to 2–5 rows per person, so the total drifts from 523 to **1352** ("100 of 1352 rows"), and 1352 is not a member count. The title has a "User —" prefix. Contact-directory phones are real data, so they are not fabrication, but the file contains duplicate person rows. The disclosure of 100 of 1352 is honest about the file. **Action:** a code-built Download Excel link is present, which is the core action. There are no other chips, and none are needed. |
| 3 | 310 t0 | 0.35 | 0.35 | **0.85** | 0.95 | **pass (fixed)** | — (standard wording tightened, same $0 truth) | 3 rows, each with its union: SDC Carré Soho Phase 3, SDC Les Terrasses 7250 St-Hubert and 825 de Bruxelles. Each shows 0.00 with 0 open invoices, from `u.outstanding_balance` plus open invoices, team-scoped. The closing "no outstanding amounts owed for Unit 310 across the different unions" stays scoped to 310 and does not generalize to the portfolio. **Minor:** the bullet list repeats the table (padding), and "0.00" has no $. **Chips:** exactly the expected set: 3 unit chips labelled by union ("Unit 310 — SDC Carré Soho Phase 3", "…St-Hubert", "…825 de Bruxelles", ids 159/301/472), plus Receivables report. |
| 4 | 310 t1 PDF | 0.80 | 0.85 | **0.85** | 0.85 | pass | — | `previous_result`, complete, 3 rows, union column kept, title "Unit 310 current balance owed". **Minor:** the PDF prints raw `0` instead of 0.00, and the Oldest Due column is empty. **Action:** a code-built Download PDF link. Per-unit chips would be a nice extra. A query-generation call still runs (cypher "", 1.7 s) even though the source is previous_result. |
| 5 | ambiguous t0 | 0.10 | 0.00 | **0.15** | 0.20 | fail | clarify (QU) | Still no clarification (`needs_clarification:false`, no candidate_readings). QU reads the question as "my account balance" (`navigation_target: own_profile`). The cypher on her own ContactUnits returns empty, because she owns no unit. The answer, "You can view your balance by checking the 'My profile' page attached below.", implies a personal balance exists. That is a deflection over an empty result, though better than p4's fabricated-looking $296,870.50 headline. **Chip:** My profile, which misleads: it leads to no balance. |
| 6 | ambiguous t1 | 0.75 | 0.35 | **0.85** | 0.95 | **pass (fixed)** | — (standard wording tightened) | Same 3 per-union rows at $0 with 0 open invoices. It does not re-ask or restart. The closing "Unit 310 owes nothing at this time" is singular wording, but it follows the explicit three-way breakdown. Same padding as t0. **Chips:** the same 3 union-labelled unit chips plus Receivables. |
| 7 | tear-down t0 | 0.45 | 0.40 | **0.50** | 0.50 | partial | legal reasoning + link text | **Retrieval:** chunk 38 (1042–1047, including **1045** partitions), chunk 61 (1064–1068), chunk 113 (1098–1102, including **1100** boundaries) and the ai-help doc [4]. **Right:** 1045 is correctly paraphrased, the 1100 boundary alteration needs no general-meeting approval but does need syndicate consent [3], no article numbers are invented, and it has no p4-style "majority vote" error. **Wrong:** the 1045 claim is cited `[1][2]`, but [2] is 1064 (expense contribution), a mis-citation. It never reaches the decisive point: a kitchen/living wall *inside one unit* separates no portions, so it is private (1042) unless it is a main/bearing wall (1044). "Possibly from your neighbors" is speculative. **Links:** the text ends "Here are some links…: View the current union / Manage a union / Units catalog". These are route names lifted from the ai-help doc [4], but **no chips are attached** (no invocations), so the reply mentions links that do not exist. They are app-management pages, irrelevant to the legal question, but at least no misleading chip was actually attached. |
| 8 | buyer-arrears t0 | 0.35 | 0.25 | **0.75** | 0.70 | **pass (fixed)** | — | Article-level retrieval now puts chunk 66 (1068.1–1070), which includes **1069**, first. The answer states 1069 correctly: the acquirer is bound to pay all common expenses due at acquisition. It cites [1] correctly and recommends obtaining the syndicate's statement of common expenses before the purchase. **Missing:** the protective nuance that the buyer is bound only if the statement is provided within 15 days of the request. "With interest" is omitted. There is no placeholder, and validation is clean. **Doors:** generic ("Review the CCQ document… check the units and unions documentation", which is irrelevant). **Action:** no chips, which is correct for a legal turn. Nothing misleading, but no useful door either (e.g. how to request the statement). |
| 9 | board t0 | 0.45 | 0.40 | **0.55** | 0.55 | partial | composition / table headers / chips | The cypher takes the latest year per union. The table is grouped by `union_name` (8 unions), and the code line reads "Showing 25 of 37 rows". "President is not indicated" is honest (bm_role is null throughout). **Defects:** the narrative says "your union… members of your board" (singular) over 8 unions. The headers leak cypher aliases: "**C.first Name \| C.last Name \| Cur.bm Role \| Cur.year**", because the cypher returns `c.first_name` without an alias. The empty role column is shown. "several members" is a hedge. It ends with "please let me know!". **Chips:** "825 de Bruxelles" (**one** union chip for an 8-union table, an arbitrary first row), Team settings, Contacts and Download Excel. The export is good; the single union chip is misleading. |
| 10 | board t1 meetings | 0.30 | 0.40 | **0.40** | 0.90 | partial | empty-result composition + cypher | `empty_result`. The cypher's `MATCH (s:Schedule)` is **non-optional**, so a scheduled meeting with no agenda rows would be hidden as "none". The answer is the vague "no linked records available… This includes details from meeting schedules" and never says plainly whether a meeting is upcoming. There is no fallback to the most recent past meeting. **Chip:** Meetings, exactly the expected door. |
| 11 | owner-arrears t0 | 0.35 | 0.40 | **0.75** | 0.40 | pass (**standard changed**) | composition (total) + chip builder | All 4 units are correct, with owners, balances largest first, overdue counts and oldest due dates, all matching the verified truth: 305 $456.12 (2 invoices, 2021-12-01), 311 $397.81 (Bailly/Gobeil), 308 $307.55, 605 $50.00. It is scoped to union 8, uses ContactUnit owners (link 1/2), and leaves out the non-unit custom client. **Missing:** the **total $1,211.48** is never stated, though the standard requires the exact total. The count is phrased as "4 different records for owners". There is no $, and the dates carry time-of-day noise ("2021-12-01 15:00"). The narrative list repeats the table. **Chips:** ONE record chip, "**SDC Carré Soho Phase 3 — 2021-12-01T15:00:00.000000Z**". It is a Union chip, not the 4 unit chips, and its disambiguator is a raw ISO timestamp. Receivables report is present (good). There is no export chip. |
| 12 | supplier t0 | 0.85 | 0.85 | **0.90** | 0.90 | pass (**standard changed**) | — | **$11,493.17** exact, taken from the query's computed total, on **4 open bills**. The per-supplier figures match all four (BFL $7,022.87, Roli-Vert $3,075.60, Hydro-Québec $819.82, Arken $574.88). The status '3'/'5' and due_amount ≥ 0.01 semantics are correct. **Minor:** it says "you owe", not "we owe". **Chip:** Payables (`bills_table`), the expected door. |

## Stats

- **Answer means (13 turns):**

  | Run | Mean |
  |---|---|
  | Baseline | 0.523 |
  | p4 | 0.488 |
  | **p9** | **0.623** |

  p9 is +0.135 vs p4.
  - On standard-unchanged turns only (11 turns, excluding owner-arrears and supplier): p4 0.464 → p9 **0.586** (+0.12).
- **Action mean (13 turns):** **0.685**.
  - Strong: unit-310 ×2, meetings, payables, pdf.
  - Weak: ambiguous t0 (misleading My profile), owner-arrears (wrong-grain, timestamp-labelled chip), tear-down (link names in the text with no chips), board t0 (a single arbitrary union chip), roster (no export chip).
- **Counts (pass / partial / fail):**

  | Run | Pass | Partial | Fail |
  |---|---|---|---|
  | Baseline | 4 | 4 | 5 |
  | p4 | 4 | 5 | 4 |
  | **p9** | **7** | **4** | **2** |

  - Pass: roster t0, 310 t0, pdf, 310 t1, buyer-arrears, owner-arrears, supplier.
  - Partial: excel, tear-down, board t0, meetings.
  - Fail: team-info, ambiguous t0.
- **Harness vs grader:** the harness has 12 ok and 1 gate-failed. It misses:
  - the excel Role drop and the 523→1352 fan-out (`columns_include` only checks name and email);
  - the owner-arrears missing total;
  - the ambiguous-t0 deflection;
  - the tear-down mis-citation and its link names with no links behind them;
  - the board alias headers.

## Investigation: team-info#0 `no_self_contact`

- **The email is in the code-rendered table, not the narrative.**
  - The model's narrative is "Your team is called Novo Gestion. Here are the details associated with your team: {{table}} If you need further information…". It contains no email.
  - `DataAnswerPlan::finalize()` (`src/Services/Response/DataAnswerPlan.php:216`) then swaps `{{table}}` for `tableMarkdown()`, which renders with `'redact' => false`, i.e. with the **real** `j.rousseau@novogestion.ca` in the "Contact Email" column.
- **The guard did fire, on the token, and the prompt handling was correct.**
  - The composition prompt masked the value to `[email#1]` and carried the SELF-REFERENCE section: "The email '[email#1]' … IS the current user's own email … If it is relevant, say it is their own".
  - But the same prompt tells the model "Do NOT list the rows, do NOT write your own table". So the model never mentions the value and never adds a marker, and the guard has nothing to act on.
- **Why the validator could not catch it.** In `ResponseGenerator::generate()` (lines 208–251) the order is:
  1. Validation runs on the **masked narrative before the table exists**.
  2. `mentionsSelf()` uses the token needle (line 477).
  3. The draft has no token, so there is no violation and no `self_reference_guard` flag (it is absent from the metadata), and `validation` comes back clean.
  4. `unmask` → `finalize` then inserts the real value **after** every check.
- **Regression?** Yes, vs p4, where the model wrote the table itself and added "That's your own email" (`marker_present:true`).
  - **The cause is the deterministic code-rendered table (Phase 8 batch A)**, which bypasses the self-reference guard.
  - PII masking itself works correctly (the token-level guard and prompt notice fired). Its contribution is that the validator only ever sees the masked, table-less narrative, so it is structurally blind to whatever the table re-inserts.
- **Recommended fix (deterministic, no LLM):**
  1. **Mark self values in the table renderer.** Thread the current user's email (already resolved by `ResponseGenerator::currentUserEmail()`) into `DataAnswerPlan`. In `tableMarkdown()`, when a cell equals it (trimmed, case-insensitive), append the localized existing key `ai.response.self_reference_marker`, giving "j.rousseau@novogestion.ca (that's you)" or "(c'est vous)". Apply the same rule to the export document rows, or at least to the on-screen table.
  2. **Validate what ships.** After `finalize()`, run the self-reference check once more on the final answer with the **real** email needle and apply the existing deterministic patch (`ai.response.self_reference_patch`). Any future post-validation insertion path is then covered too. Set the `self_reference_guard` metadata from this final check.
  3. **Test.** Table mode with a row whose email equals the user's → the marker is present in the shipped answer, and `self_reference_guard = patched|table`. Mirror the existing `the_self_reference_guard_still_works_on_masked_values` test.
  - **The gate will accept fix 1.** The gate (`ai-eval/src/Services/ExpectationGate.php::gateNoSelfContact`) passes whenever the answer contains any phrase from `SelfReferenceNoticeSection::markerPhrases()`, which is the same source as the runtime. A table cell suffixed with the localized marker therefore passes it, and the gate needs no change.
  4. *(Optional)* In the DATA SUMMARY the model sees, annotate the token cell itself ("[email#1] — the user's own") so the narrative can naturally say "the team contact listed is you".
- **Separately (answer content, not the gate):** team-info still lacks the team type and member count. The generic team query should project the decoded type and `count(members)`. That is a query-generation coverage gap and has been unchanged since baseline.

## Regressions vs p4 (with cause)

1. **team-info −0.20:** the self-contact leak through the code-rendered table (above). It also still lacks type and member count.
2. **excel t1 −0.30:** two causes stack.
   - **(a)** With the regex follow-up layer removed, QU alone decided `follow_up_reference:false` for "give me **that** as an Excel". So the export took `current_result` from a fresh query.
   - **(b)** The roster cypher no longer projects `u.email` (pruned prompt). Even a previous_result export would have needed the superset re-query, and the re-query dropped TeamRole and joined ContactDirectory without aggregation, causing the 1352-row fan-out.
   - **Fixes:**
     - Restore a deterministic export-follow-up backstop: an export_format with a non-empty last result means previous_result, or a superset query that **keeps the previous projection** (role) and adds email.
     - When ContactDirectory is joined, aggregate it per user (`collect(DISTINCT cd.value)`).
3. **roster t0 −0.05 (noise):** the total moved into the code line as "rows", with no "523 members" in the narrative, and email is no longer projected (which fed regression 2).

## Wins

- **Unit-310 homonyms fixed ×2 (+0.50 each).** The answers are per-union, with a verifiable $0 and open-invoice count per unit. The disambiguated chips are exactly right: "Unit 310 — <union>" ×3. This is the clearest win from the actions work.
- **buyer-arrears +0.50.** Article-level chunks made 1069 retrievable. It is stated correctly with [1], and there is no "[N]" placeholder. The validator is clean.
- **owner-arrears:** all 4 real units, owners and balances are correct under the new real-money standard. Only the total line is missing.
- **supplier:** exact $11,493.17 / 4 bills, with the per-supplier figures matching.
- **board t0:** the per-union grouping is now in the table, and the export chip and disclosure are present.
- **No fabrication, no invented links, no wrong language** anywhere. The code chips never pointed to a wrong target, and the pdf export is stable.

## Action-layer findings (for the chip builder)

1. **Wrong grain or deduped record chips.** Both cases come from the same mechanism: the builder keys on the first or union id and dedupes.
   - Owner-arrears: one **Union** chip instead of 4 **Unit** chips (rows carry `unit_id`). Its label is disambiguated with a **raw ISO timestamp** (`— 2021-12-01T15:00:00.000000Z`).
   - Board t0: one union chip for an 8-union table.
   - **Fix:** choose the row-grain entity (the id column that varies across rows), and humanize or skip date columns as disambiguators.
2. **Export chip consistency.** board t0 got Download Excel, but roster t0 (truncated at 25 of 523, whose text says "ask for … an export") and owner-arrears got none. Attach export by answer shape (a table, especially a truncated one) consistently.
3. **Legal turns pull app route names from the ai-help doc into the prose** ("View the current union / Manage a union / Units catalog") while attaching no chips. The prose then refers to links that do not exist.
   - **Fix:** keep ai-help docs out of knowledge_query composition when the question is legal, or resolve the doc's named routes into real attached chips. Add a validator rule: "the text refers to attached links but none are attached".
4. **My profile on ambiguous t0 is misleading** for a user who owns no unit. Gate identity chips on the empty result: a profile chip should not stand in for a balance that does not exist.

## Tokens / latency vs p4

- **Tokens:**

  | Run | Total | Calls | Per turn | Composition calls |
  |---|---|---|---|---|
  | p4 | 258,055 | 38 | ≈19.9k | — |
  | **p9** | **173,974** | 35 | ≈13.4k | 1 per turn (no retries) |

  - That is **−32.6%** tokens.
  - Legal turns went 11.6k/21.1k → 10.1k/10.5k.
  - excel t1 rose 5.5k → 11.6k because of the fresh re-query (regression 2).
- **Latency (s):**

  | Run | Sum | Mean | Median |
  |---|---|---|---|
  | p4 | 370 | 28.5 | 29.4 |
  | **p9** | **142.8** | **11.0** | **9.6** |

  - That is **−61%** on the mean.
  - Data turns take 8.5–12.4 s. Legal turns take 19.5 s and 21.4 s, driven by file_context at 9.1/10.3 s.
  - **Caveat:** p4 ran in a slow environment, so only part of this is code-attributable.
  - The stable floor is now **understanding ≈ 5 s on every turn** (3.8–6.8 s, one gpt-4o-mini call, about 5.2k input tokens) and query_generation ≈ 2 s. Understanding is the largest fixed cost and the next latency target.
  - The pdf turn still spends 1.7 s on an empty query-generation call for a previous_result export.
