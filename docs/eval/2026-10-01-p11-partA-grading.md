# Grade: p11-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p11-1001-A.json`, generated 2026-10-01T08:53. ai git `badd2f1` + 474 dirty files, config_sha1 `5566d2f6…` (same as p9/p10).
- **Models:** QU gpt-4o-mini, query generation gpt-4o, composition gpt-4o-mini (unchanged).
- **Harness:** 10 ok, 1 gate-failed (`team-roster-then-excel#1 columns_include`: "Missing column(s): email"), 2 infra (`my-balance-then-pdf#0`, `#1`: OpenAI SSL "unexpected eof" plus Qdrant 408 in semantic selection). `#1` also fails `produces_file`.
- **VOID:** both `my-balance-then-pdf` turns are VOID. They carry no score, and every mean below leaves them out (11 graded turns). For reference only: #0 still produced the correct 3-union $0 answer and chips (on the full-schema fallback). #1 lost QU entirely (`TransientLlmException`), which gave `question_type: hybrid, intent: other, export_format: null`, and the turn answered "No results were found for your request to create a PDF…". That is a resilience defect, listed below, but it is not graded.
- **Rubric/strictness:** identical to p9/p10 (pass ≥ 0.70, partial 0.40–0.69, fail < 0.40; action = code-attached chips plus doors named in the text).

## Per-turn table

| # | Turn | p9 | p10 | **p11 answer** | **p11 action** | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.30 | 0.50 | **0.45** | 0.70 | partial (−0.05) | "(that's you)" kept on `j.rousseau@novogestion.ca`; gate `no_self_contact` passes; insider voice. Same QU (`entity_types: []`). The cypher, however, **dropped `t.team_type`** (p10 projected it, raw "Team Type 2"), so the type is now completely absent. Still no member count, and it still pads with an empty Team Address column and Created/Updated timestamps. Cause: QG variance. **Chips:** My profile, Team settings. No members/portfolio/finance doors. |
| 1 | roster t0 | 0.80 | 0.80 | **0.80** | 0.60 | pass | Same cypher as p10 (TeamRole-anchored). 25-row name+role table, "Showing 25 of 525 rows — ask for the full list or an export." (`true_total` 525, `anchor_total` 523: a small TeamRole fan-out, so "rows" rather than "members"). No member count in the narrative. **Chips:** only Team settings now (the irrelevant My profile is gone, +0.05). Still **no export chip** although the text invites an export. |
| 2 | roster t1 Excel | 0.45 | 0.35 | **0.35** | 0.70 | **fail (flat)** | Change (1) partly landed. QU still says `intent: "file_listing"`, so the QU-prompt edit did not flip it. But the new guard in `isFileListingIntent` (`AiManager.php:2683`) took effect: file_context was `skipped: question_type`, and **a query ran and extended the previous one** (same MATCH/WHERE, `member_name` and `role` kept, `cd.value AS contact_info` added). The user still gets **exactly the p10 file**: `export_source: previous_result`, "Fields included: Member Name, Role.", 100 of 525, and no disclosure that contact info is missing. Full trace and root cause are in "Excel trace" below. **Action:** a code-built Download Excel link, but the file lacks the requested fields. |
| 3 | 310 t0 | 0.85 | 0.85 | **VOID** | VOID | infra | (For reference: same cypher, 3 rows with union, $0, the right chips.) |
| 4 | 310 t1 PDF | 0.85 | 0.90 | **VOID** | VOID | infra | (For reference: the QU call failed, which made this a non-export "No results were found…" answer. That would be the canonical context-loss fail.) |
| 5 | ambiguous t0 | 0.15 | 0.25 | **0.25** | 0.40 | fail (flat) | Same as p10. `needs_clarification:false`, `candidate_readings:[]`, and her own ContactUnits returned 0 rows. Honest empty ("no linked records available for you"), but it is not the required clarifying question, and it ends with "just let me know!" (guideline violation). No chips and no door. |
| 6 | ambiguous t1 | 0.85 | 0.85 | **0.85** | 0.95 | pass | Same cypher and data: 3 per-union $0 rows. The HOMONYMS lead is correct here ("3 different records for Unit 310, each associated with a different Union Name"). The numbered list repeats the table (padding), and "0.00" has no $. **Chips:** 3 union-labelled Unit 310 chips plus Receivables report. |
| 7 | tear-down t0 | 0.50 | 0.40 | **0.45** | 0.55 | partial (+0.05) | Same 4 chunks (38: 1042–1047, 61: 1064–1068, 113: 1098–1102, ai-help units doc). Improved: it now raises private vs common, and the p10 mis-citation of [2]=1064 is gone. Still **wrong lean**: "The wall you want to remove is **likely considered a common portion**" quotes 1045 (walls separating portions) for a wall inside one unit, which separates nothing. It never says an interior partition inside a private portion is private unless it is a main/bearing wall (1044). Point 4 again generalizes 1100's "no change of relative value" condition to "any alteration". Point 2's "[3]" majority claim is loosely tied to 1098. No invented article numbers. Ends "you will likely need to consult with the syndicate…": there is no unattached "unit management section" door any more (F9 complete), but there is no concrete deepen path either. |
| 8 | buyer-arrears t0 | 0.75 | 0.65 | **0.75** | 0.70 | pass (+0.10) | Chunk 66 first; 1069 cited [1], grounded. It restores p9's advice to "request a statement of the common expenses from the syndicate before finalizing the purchase", with the 15-day window. Nuance is still slightly off: "if provided within 15 days … you would only be responsible for the fees listed in that statement". 1069 says the prospective acquirer is bound **only if** the statement is provided within 15 days, i.e. the protection is the syndicate's failure to answer. It also opens categorically ("you will be liable"). Generic closing ("refer to the relevant articles"). No chips and no false link claim. This is composition variance, not a code change. |
| 9 | board t0 | 0.55 | 0.45 | **0.40** | 0.70 | partial (−0.05) | Same cypher and data (latest year per union, 37 rows, union column). The narrative is worse: "**I couldn't find specific information about the board of directors or the president in the available data** … let me know if you would like to see a list of all contacts". It **denies the board table rendered directly under it**, never says plainly that no president/role is recorded, and ends with an offer (guideline violation). The DATA SUMMARY still never names its row subject, and the header is still "Bm Role" (same cause as p10). **Chips:** 5 Union chips for 8 unions (no "+N more"), Team settings, Contacts, Download Excel. |
| 10 | board t1 meetings | 0.40 | 0.45 | **0.40** | 0.90 | partial (−0.05) | Same cypher: non-optional `MATCH (s:Schedule)` after picking the next meeting. Empty result. Answer: "there are **no records linked to you** that provide this information". Meetings are the union's/team's, not hers, so this wording misframes the absence. There is no past-meeting fallback (required by the standard). **Chip:** Meetings, named correctly in the text. |
| 11 | owner-arrears t0 | 0.75 | 0.75 | **0.70** | 0.90 | pass (−0.05) | Change (2) **confirmed**. No HOMONYMS line in the composition prompt, and the lead is now clean: "The following owners in SDC Carré Soho Phase 3 are currently behind on their fees:". Data is correct: 305 456.12, 311 397.81 (Bailly, Gobeil), 308 307.55, 605 50.00; union 8 only; ContactUnit owners; largest first. But **the total $1,211.48 is still not stated** even though `COMPUTED TOTALS … = 1,211.48` is in the prompt. With the "4 different records" sentence gone, **the count is no longer stated either**. The standard requires both, hence −0.05. Table still has no $ and shows "2021-12-01 15:00". Closing is generic ("check the respective records"). **Chips:** Unit 305/311/308/605 plus Receivables report, exactly right. |
| 12 | supplier t0 | 0.90 | 0.95 | **0.90** | 0.90 | pass (−0.05) | Same cypher. "Currently, we owe our suppliers a total of 11,493.17. There are 4 open bills…" is exact and in insider voice, but has **no $** (p10 had "$11,493.17"; composition variance). Per-supplier table matches all four figures; Oldest Due shows the time of day. **Chip:** Payables. |

## Means (VOID excluded: 11 graded turns)

| Run | Answer mean (same 11 turns) | Action mean (same 11 turns) | 13-turn answer mean (as published) |
|---|---|---|---|
| p9 | 0.582 | — | 0.623 |
| p10 | 0.582 | 0.723 | 0.627 (action 0.750) |
| **p11** | **0.573** | **0.727** | n/a (2 VOID) |

- **Answer:** p11 is −0.009 vs p10 on the like-for-like 11 turns. Gains: buyer-arrears +0.10, tear-down +0.05. Losses: team-info, board, meetings, owner-arrears and supplier at −0.05 each. Every move is composition/QG variance on unchanged retrieval or cypher. None of them comes from a p11 code change.
- **Action:** +0.004 vs p10 (roster +0.05: the irrelevant chip is gone).
- The 13-turn p10 figure (0.627) is not comparable with p11, because the two VOID turns were p10's 0.85 and 0.90.

## Counts (11 graded turns)

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p10 (same 11) | 4 | 5 | 2 |
| **p11** | **5** | **4** | **2** |

- **Pass:** roster t0, ambiguous t1, buyer-arrears (up from partial), owner-arrears (at the 0.70 threshold), supplier.
- **Partial:** team-info, tear-down, board t0, meetings.
- **Fail:** excel, ambiguous t0.

## Fix verification

| Change | Status | Evidence |
|---|---|---|
| **(1a)** `isFileListingIntent` returns false on `follow_up_reference` with a recorded result | **CONFIRMED (deterministic guard works)** | QU still emits `intent:"file_listing"`. The guard at `AiManager.php:2683` fired: `file_context {skipped: question_type, file_count: 0}`, query_generation ran (2 calls, 13.3k tokens), execution 100 rows. There is no 50-file enumeration any more. |
| **(1b)** QU prompt: "file_listing never applies to receiving records as a file" | **NOT EFFECTIVE** | QU output is still `intent:"file_listing"` (with `follow_up_reference:true`, `export_format:"excel"`). Harmless now only because of (1a). |
| **(1c)** "extends the previous roster query, keeps Role, adds contact info (email)" | **QUERY: PARTIAL. FILE: NOT DELIVERED** | The query extends the previous one and keeps Role, but adds `cd.value AS contact_info` from **ContactDirectory** (multi-valued, mixed emails and phones) instead of `u.email`. This fans out to 1,354 rows for 523 members and violates ROW GRAIN. The export then **discarded the fresh result** and shipped `previous_result` (Member Name, Role). See the trace below. |
| **(2)** identity-aware HOMONYMS (`RecordResolver`) | **CONFIRMED (directive). Total still missing** | owner-arrears composition prompt has no HOMONYMS line, and the lead sentence is clean. ambiguous t1 (three real 310 homonyms) still gets its HOMONYMS lead, so there is no false negative. But $1,211.48 is **not stated**, so the expectation "total stated" is **not met**. Cause below. |

## Excel trace (team-roster-then-excel #1): why `email` is missing

1. **QU** (gpt-4o-mini): `intent:file_listing`, `follow_up_reference:true`, `export_format:excel`, `entity_types:[Team, TeamRole, User, ContactDirectory]`, `property_concepts:["contact","role"]`, `clarified_question:"What are the contact information entities related to the team members in my team?"`.
   - QU turns "contact info" into the concept "contact" and the **entity ContactDirectory** (whose aliases include "contact info"), not into User.email.
2. **Generation prompt:**
   - REQUESTED ATTRIBUTES lists `contact: RETURN the mapped property as a column` and `role: …`.
   - The schema cards include User (props `email`, note: "Important to add when giving information about to team, important to add in excels sheets") **and** ContactDirectory (`value`, aliases "contact info", "coordonnees") plus Contact.
   - The Conversation Context section carries the previous cypher and the "**THIS REQUEST OPERATES ON THE PREVIOUS RESULT.** EXTEND the Previous Query above…" directive.
   - The system prompt has the ROW GRAIN rule ("aggregate it per record … collect(DISTINCT x.value)").
   - Detected scopes also inject IS_EMAIL with a **wrong pattern** `n.contact_type_id IN [1, 2, 3]` (email, phone and web), from `CondoEdge/config/ai-entities/contact.php:520`. It was unused here, but it is a config bug.
3. **Generated cypher** (attempt 2, after a schema-direction repair on `(u)-[:USER]->(c)`):
   ```
   MATCH (t:Team) WHERE t.id IN $ai_team_ids AND t.id = 3
   OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t)
   OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id
   OPTIONAL MATCH (c:Contact)-[:USER]->(u)
   OPTIONAL MATCH (cd:ContactDirectory)-[:CONTACT]->(c)
   RETURN u.name AS member_name, tr.role AS role, cd.value AS contact_info LIMIT 100
   ```
   - The extension itself is correct (same MATCH/WHERE, columns kept).
   - The new attribute is the multi-valued `cd.value` with no aggregation, and `u.email` (the attainable field, which the schema note says belongs in Excel sheets) is ignored.
   - Execution: `row_count 100, true_total 1354, anchor_total 523`, a **2.6× fan-out**. The 10-row sample covers only 4 members (Antoine ×2, Filip ×2, Tristan ×2, Nada ×4), each repeated once per email or phone.
   - The repair prompt ("Previous attempt failed … Fix it") only fixes the edge direction. Nothing reacts to the detected fan-out.
4. **Export source decision** (`AiManager.php:2076–2093` → `2102`):
   - `previous` = the re-executed roster (100 rows of `member_name, role`, `complete:false`, total 525). `currentColumns` = `[member_name, role, contact_info]`, a strict name superset, so `shared = [member_name, role]`.
   - `sameGrain` additionally requires `sharedValuesContained()` (`AiManager.php:2490–2529`): ≥ 90 % of the previous rows' `(member_name, role)` tuples must appear among the fresh rows' tuples.
   - The fresh 100-row window holds only ≈ 35–40 distinct members because of the fan-out, so at most ≈ 40 % of the previous 100 members are contained. **sameGrain = false → preferCurrent = false → requestedFields = []**.
   - This is a deduction, not an observation, but a tight one. Had sameGrain been true, preferCurrent would necessarily have been true (the previous columns are a subset and the count is larger), and the source would have been `current_result`. The observed `previous_result` therefore implies that the containment check failed.
   - Result: line 2102 exports `previous_result`, and because `requested_fields` is empty the composer has nothing to disclose. Export metadata: `export_source: previous_result`, `export_source_complete: false`, `result_count 100`, `result_total 525`, `export_document_rows` = `{member_name, role}` ×100. Answer: "Fields included: Member Name, Role. The document shows 100 of 525 rows."
5. **Harness gate:** `columns_include` inspected the **fresh result rows** (`actual: [member_name, role, contact_info]`), not the exported file. **Even a `current_result` export of this query would still fail it**, because the column is `contact_info` holding mixed emails and phones, not an email column.

**Root cause:**
- **Primary: query generation does not respect ROW GRAIN on the follow-up extension.** "Contact info" is mapped to the one-to-many ContactDirectory instead of `User.email`, so the rows fan out.
- **Secondary: the export same-grain check is not fan-out tolerant.** `sharedValuesContained` compares a truncated 100-row window of a fanned-out result with 100 distinct previous rows, so it can never succeed when the extension adds a one-to-many column. The fallback to `previous_result` then silently drops the requested field, because `requestedFields` is only set when sameGrain is true.

**Recommended fixes (in order of leverage):**
1. **Export, deterministic** (`src/Services/AiManager.php:2080–2090`):
   - Before the containment test, collapse the fresh rows by the shared-column tuple. Join the extra columns' distinct values with "; ", which gives one row per member: `contact_info = "a@x.com; +1 514…"`. Then run `sharedValuesContained` on the collapsed rows, and re-execute the fresh cypher without the display LIMIT (up to `ai.export.document_row_limit`) when `true_total > row_count`.
   - Use `anchor_total` (already stamped, 523 here, equal to the previous anchor) as the same-grain signal: equal anchor count plus a name superset means same grain, fanned out.
   - When the previous result is kept anyway, set `requestedFields = $currentColumns` whenever `$shared !== []`, not only on sameGrain, so the composer discloses "contact info not included" instead of presenting the file as complete.
2. **Generation:**
   - **Fan-out repair:** when execution returns `true_total > anchor_total` on a list-intent query, trigger one repair round. Message: "your query returns N rows for M <anchor_label>; aggregate the one-to-many columns per record with collect(DISTINCT …)". Hook point: next to `executeWithTrueTotal` (`AiManager.php:460–482`), which already computes both numbers.
   - **Members' contact info:** make the User card's default "contact info = email" explicit (CondoEdge `config/ai-entities` user description), and stop QU from seeding ContactDirectory for "their contact info" about team members.
3. **Config bug:** `CondoEdge/config/ai-entities/contact.php:520` IS_EMAIL pattern should be `n.contact_type_id = 1`.
4. **Harness:** on an export turn `columns_include` should inspect `export_document_rows`, the file actually delivered, not the fresh result. It should also accept value-typed evidence (a column whose values are emails), so a `contact_info`/`user_email` alias is not a false red or a false green.

## Owner-arrears: why the total is still missing

- The p11 composition prompt has `COMPUTED TOTALS … TOTAL of Balance over all 4 rows = 1,211.48`, and the ANSWER FORMAT only says "Every total … you state must be copied verbatim". Nothing asks the model to **state** the count or the total.
- The guidelines say "Lead with the direct answer", and for a "which owners…" question gpt-4o-mini treats the list itself as the direct answer. With the HOMONYMS sentence removed, the count disappeared too.
- Worse, the same ANSWER FORMAT line says "do NOT state how many rows are shown" (`DataAnswerPlan.php:699–701`). That line is meant to stop a "showing X of Y" duplicate, but on a complete result it actively discourages stating the count.
- **Fix:** in `src/Services/Response/DataAnswerPlan.php` `answerFormatLines()` (lines 671–702), when `grandTotals()` is non-empty and the result is complete with more than one row, add "Your first sentence states the number of records (N) and the COMPUTED TOTAL." Also scope the "do NOT state how many rows are shown" line to truncated results only. `totalsLines()` (lines 494–522) would also do better to tag money totals with the currency, so "$1,211.48" gets formatted.

## Regressions vs p10 (with traced cause)

None is caused by a p11 code change. All are −0.05:
1. **team-info −0.05:** QG dropped `t.team_type` (same QU, same prompt family), so the type is now absent rather than raw. QG variance.
2. **board −0.05:** same data and prompt structure. The composer now flatly denies the board ("couldn't find specific information about the board of directors"). The p10 cause persists: the DATA SUMMARY has no row-subject line, and "Bm Role" is an opaque header with every role empty.
3. **meetings −0.05:** "no records linked to you" wording. The non-optional Schedule MATCH persists.
4. **owner-arrears −0.05:** the HOMONYMS removal (intended) also removed the only count statement, and the total is still not stated (see above).
5. **supplier −0.05:** "$" dropped from the total (composition variance).
6. **Excel tokens:** 5.7k → 19.0k tokens with no user-visible gain (the query runs but its result is discarded).

## New / persisting defects

1. **Export same-grain check is not fan-out tolerant**, and the previous-result fallback discloses nothing (new and exposed by fix 1a). See the Excel trace.
2. **Fan-out is detected but never repaired:** `true_total 1354 / anchor_total 523` was available and unused.
3. **IS_EMAIL scope pattern wrong** (`contact_type_id IN [1,2,3]`) in `CondoEdge/config/ai-entities/contact.php:520`.
4. **QU failure → false "No results" answer** (VOID turn #1): when understanding throws, the pipeline proceeds with an empty QU (`hybrid/other`, no export_format) and composes "No results were found for your request to create a PDF". It should retry, or return an honest "temporary problem, please retry", never a context-loss answer.
5. **QU still emits file_listing for export follow-ups** (prompt fix 1b ineffective). Only the guard protects it.
6. Persisting:
   - owner-arrears total and count not stated.
   - board self-denial and no "+N more" chip (8 unions, 5 chips).
   - team-info missing type and member count, with padding columns.
   - ambiguous t0 never clarifies.
   - meetings: non-optional Schedule MATCH and no past-meeting fallback.
   - roster: no export chip.
   - money rendered without $ in tables, and times of day in date columns.

## Tokens / latency vs p10

- **Tokens:**

  | Run | Total | Calls |
  |---|---|---|
  | p10 | 165,080 | 33 |
  | **p11** | **180,883** | **36** |

  - +9.6% overall.
  - Excel +13.4k (the query generation now runs, with 2 attempts because of the edge-direction repair).
  - The VOID PDF turn was +5.0k (failed QU, then a full-schema QG of 9.3k).
  - Per-stage costs on other turns are flat (understanding ≈ 5.5k, QG within ±0.6k, composition within ±0.9k).
- **Latency: environment-dominated, so not comparable.** Pipeline sum 653.8 s (mean 50.3, median 23.6) vs p10 140.1 s (10.8 / 10.1).
  - The two VOID turns took 143.7 s and 243.6 s (understanding 135 s / 228 s, SSL EOF retries).
  - Excluding them: sum 266.6 s, mean 24.2 s, median 17.1 s (p10 same 11 turns: 114.4 / 10.4 / 10.1).
  - Inflated understanding calls: roster 39.5 s, excel 22.9 s, ambiguous 20.5 s / 15.9 s, tear-down 26.0 s. Ambiguous t0 execution also took 20.1 s.
  - All of this is consistent with the same network incident. Token counts are unchanged, so it is not a prompt-size effect.
  - Unaffected turns (board ×2, owner, supplier, team-info) run 9.5–14.3 s, in line with p10.
