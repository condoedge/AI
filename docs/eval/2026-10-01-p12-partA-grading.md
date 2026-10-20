# Grade: p12-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p12-1001-A.json`, generated 2026-10-01T12:30. ai `badd2f1` + 485 dirty files, config_sha1 `ba08294d…` (p11 was `5566d2f6…`: the CondoEdge config changed).
- **Models:** unchanged. QU gpt-4o-mini, QG gpt-4o, composition gpt-4o-mini. Preflight OK.
- **Harness:** 12 ok, 1 gate-failed (`team-roster-then-excel#1 columns_include`: "Missing column(s): email (inspected export payload header row)"). **0 INFRA/VOID**, so all 13 turns are graded.
- **Rubric/strictness:** same as p9–p11. Pass ≥ 0.70, partial 0.40–0.69, fail < 0.40. Action = code-attached chips plus doors named in the text.

## Headline

- **A new P0 regression masks most of this round's gains.** Every money cell in a code-rendered table is corrupted. `$456.12` displays as `6.12`, `$7,022.87` as `,022.87`, `$0.00` as `.00`.
- Cause: `DataAnswerPlan::finalize()` passes the rendered table as the *replacement* argument of `preg_replace` (`src/Services/Response/DataAnswerPlan.php:277`). The `$`-prefixed amounts that the new currency symbol produces (CondoEdge `config/ai.php:387` `currency_symbol '$'`) are then read as back-references:
  - `$45` and `$7` resolve to empty groups.
  - `$0` re-inserts `{{table}}`, which line 278 then strips.
- The composition prompt's DATA SUMMARY has the right values (`$456.12`), so the corruption happens after the LLM, in code.
- Four turns are hit: 310 t0, ambiguous t1, owner-arrears and supplier. Owner-arrears and supplier now **display wrong amounts**, which counts as fabrication under the standard.

## Per-turn table

| # | Turn | p10 | p11 | **p12 answer** | **p12 action** | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.50 | 0.45 | **0.45** | 0.70 | partial (flat) | Same cypher shape as p11: `t.name, contact_email, contact_phone, team_address, created_at, updated_at`. Still no `team_type` and no member count. Still padded with an empty Team Address column and timestamps. "(that's you)" is kept and `no_self_contact` passes. Ends with "If you need further assistance, you can check your profile or team settings using the buttons below", which the chips back up. **Chips:** My profile, Team settings. There are no members/portfolio/finance doors. |
| 1 | roster t0 | 0.80 | 0.80 | **0.80** | 0.55 | pass (action −0.05) | FanOutCollapse fired (`row_total 525 → anchor_total 523`, User). The rewritten cypher is `… WITH u, collect(DISTINCT tr.role)[0..11] AS role WHERE u IS NOT NULL RETURN u.name AS member_name, role LIMIT 100`, so the disclosure is now the true 523 ("Showing 25 of 523 rows — ask for the full list or an export."). The count is still given as "rows" in the code line only, not in the narrative. **Chips:** My profile (irrelevant; it came back) and Team settings. The text invites an export, but there is still no export chip. |
| 2 | roster t1 Excel | 0.35 | 0.35 | **0.35** | 0.70 | **fail (flat)** | The file is again `previous_result` "Member Name, Role". It is now 250 of 523 rows, up from 100, because the display LIMIT was widened to `ai.export.document_row_limit` 250. **No contact info, and nothing discloses that it is missing**: "I prepared the Excel file with the available information." The fresh query **chose `u.email` correctly** (the config fix landed), but it failed twice with a Neo4j SyntaxError, ``Variable `t` not defined``, on `t.contact_phone AS phone`. Full trace below. It burns 13.6k QG tokens for nothing. |
| 3 | 310 t0 | 0.85 | VOID | **0.75** | 0.95 | pass (−0.10 vs p10) | Correct data: 3 rows with union, 0 open invoices. The new first-sentence directive works: "There are 3 different records for unit 310, with a total Balance of $0.00 and Invoices Due of $0.00." The p10 numbered-list padding is gone. **But the table cells show `.00`** (the `$0` back-reference bug). Summing three homonyms into one "total" is also slightly odd. **Chips:** 3 union-labelled Unit 310 chips plus Receivables report. |
| 4 | 310 t1 PDF | 0.90 | VOID | **0.90** | 0.85 | pass (= p10) | `previous_result`, complete, 3 rows, union column kept. "No values found for: Oldest Due" (the empty column is dropped and disclosed). The context-loss failure is gone. Minor issues: the PDF shows raw `0` instead of 0.00, and a QG call ran for an empty cypher (9.5k tokens wasted; p10 made none). **Action:** Download PDF. |
| 5 | ambiguous t0 | 0.25 | 0.25 | **0.25** | 0.40 | fail (flat) | `needs_clarification:false`, `candidate_readings:[]`. Her own ContactUnits returned 0 rows. Honest empty: "I checked for your account balance records, but it appears that no such records are linked to you." The "let me know" ending is gone. It is still not the required clarifying question. No chips. |
| 6 | ambiguous t1 | 0.85 | 0.85 | **0.75** | 0.95 | pass (−0.10) | Same cypher and data, and the lead is correct (3 records, $0.00). The table shows `.00` (back-reference bug). Ends with "just let me know!" (guideline violation). The numbered-list padding is gone. **Chips:** 3 union Unit 310 chips plus Receivables. |
| 7 | tear-down t0 | 0.40 | 0.45 | **0.55** | 0.55 | partial (+0.10) | Same 4 chunks (38, 61, 113, plus the ai-help doc). The wrong lean "likely a common portion" is gone. It now branches correctly: if the wall is common (1045) it needs permission; "if the wall is entirely within your private portion, you may have more leeway", unless it affects structure or common areas. It still never names main/bearing walls (1044) as the test. It still cites `[2]` (chunk 61, 1064–1068, expense contribution) for alterations, a mis-citation. No invented article numbers. No concrete deepen path. |
| 8 | buyer-arrears t0 | 0.65 | 0.75 | **0.70** | 0.70 | pass (−0.05) | Chunk 66 is first, and "Article 1069" is named and grounded. It advises requesting the statement "before completing the purchase" and gives the 15 days. The closing summary distorts it: "liable … **unless you have obtained a statement from the syndicate that specifies otherwise**". The real protection is that the buyer is not bound if the syndicate fails to answer within 15 days. No chips. This is composition variance. |
| 9 | board t0 | 0.45 | 0.40 | **0.55** | 0.70 | partial (+0.15) | Changes (3) landed in the prompt: `These rows answer: "board of directors members and president"` and `NOT RECORDED: Bm Role is empty on all 37 rows`. The "couldn't find" denial is gone, and it now states that roles, including the president, are not available. The opener still contradicts itself: "The current board of directors members and the president are not explicitly recorded … However, I can provide you with the list of board members." Union column present, "Showing 25 of 37". The header is still the opaque "Bm Role" with an all-empty column. Ends with "let me know!" **Chips:** 5 of 8 unions (no "+N more"), Team settings, Contacts, Download Excel. |
| 10 | board t1 meetings | 0.45 | 0.40 | **0.40** | 0.90 | partial (flat) | Same non-optional `MATCH (s:Schedule)` after `LIMIT 1` on the next meeting, so the result is empty. "I couldn't find any **linked records** related to meetings or schedules. It seems there are no upcoming meetings…" It offers no fallback to a past meeting, only "You can review the existing meetings". **Chip:** Meetings. |
| 11 | owner-arrears t0 | 0.75 | 0.70 | **0.35** | 0.90 | **fail (−0.35)** | Change (2) landed in the prose and dates. The prompt has "Your FIRST sentence states the number of records (4) and the total (Balance = $1,211.48)". The answer: "There are 4 owners in SDC Carré Soho Phase 3 who are behind on their fees, with a total balance of $1,211.48." That should be 4 *units* and 5 owners. Oldest Due is now date-only (2021-12-01…). **But the table displays 305 `6.12`, 311 `7.81`, 308 `7.55`, 605 `.00`**, which are wrong amounts that contradict the headline. The prompt's DATA SUMMARY has $456.12/$397.81/$307.55/$50.00, so the code corrupted them. Under the standard ("list all four … with balances"), displayed wrong balances are a fail. Owners, scope (union 8) and ordering are correct. **Chips:** Unit 305/311/308/605 plus Receivables. |
| 12 | supplier t0 | 0.95 | 0.90 | **0.40** | 0.90 | partial (−0.50) | "You currently owe a total of **$11,493.17** to your suppliers, with 4 open bills." The total and count are exact and the $ is back. The voice slipped from "we" to "you", against the insider-voice standard. **The per-supplier table is corrupted**: `,022.87`, `,075.60`, `9.82`, `4.88` instead of $7,022.87/$3,075.60/$819.82/$574.88. The standard requires the breakdown to match those four figures. Oldest Due mixes times and dates. **Chip:** Payables. |

## Means

| Run | Answer, p11's 11 turns (excl. 310 t0/t1) | Action, 11 turns | Answer, all 13 | Action, all 13 |
|---|---|---|---|---|
| p10 | 0.582 | 0.723 | 0.627 | 0.750 |
| p11 | 0.573 | 0.727 | n/a (2 VOID) | n/a |
| **p12** | **0.505** | **0.723** | **0.554** | **0.750** |

- **Answer:** −0.068 vs p11 on the 11 turns and −0.073 vs p10 on all 13. The whole drop comes from the money back-reference bug:
  - owner-arrears −0.35, supplier −0.50, ambiguous t1 −0.10 and 310 t0 −0.10 (310 t0 vs p10).
  - Real gains: board +0.15, tear-down +0.10. Buyer moved −0.05 (variance).
- **Counterfactual with the table bug fixed:** owner-arrears ≈ 0.85, supplier ≈ 0.90, ambiguous t1 ≈ 0.80, 310 t0 ≈ 0.85. That gives **13-turn 0.642 and 11-turn 0.600**, both the best in the series.
- **Action:** flat. Roster −0.05 because My profile came back.

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p11 (11 turns) | 5 | 4 | 2 |
| **p12, same 11** | **3** | **5** | **3** |
| **p12, all 13** | **5** | **5** | **3** |

- **Pass:** roster t0, 310 t0, 310 PDF, ambiguous t1, buyer.
- **Partial:** team-info, tear-down, board, meetings, supplier.
- **Fail:** Excel, ambiguous t0, owner-arrears.

## Fix verification

| Change | Status | Evidence |
|---|---|---|
| (1a) FanOutCollapse | **WORKS (roster)** | The `fan_out_collapse` stage on roster t0 is `collapsed:true, row_total 525, anchor_total 523, anchor_label User`, and the disclosure is now 523. **It is also the trigger of the Excel failure** (see below). |
| (1b) Export LIMIT widened to 250 | **WORKS** | The previous-result re-execution returned 250 rows: "250 of 523", `export_source_complete:false`. It is still incomplete, because `ai.export.document_row_limit` defaults to 250 (`AiManager.php:2753`). |
| (1c) Export same-population check and collapse | **NOT EXERCISED** | The fresh query failed, so `currentUsable=false` (`AiManager.php:2295`) and the check never ran. |
| (1d) Disclosure of requested-but-missing fields | **DID NOT FIRE** | `requested_fields` comes only from `$currentColumns`, which is `[]` when execution failed (`AiManager.php:2295–2326`). The answer says only "Fields included: Member Name, Role." |
| (1e) Members' contact info = User.email | **WORKS (QG level)** | The User card shows "A member's contact info is User.email (no phone on record)", and ContactDirectory shows "Not for team members". QG wrote `u.email AS email` and no ContactDirectory join. |
| (2) Owner-arrears first sentence: count and total | **WORKS** | "There are 4 owners … total balance of $1,211.48" (should say "4 units"). It also applies to 310 ("3 different records … $0.00"). |
| (2) Money with "$" | **REGRESSION (P0)** | The narrative and the DATA SUMMARY are right. Every code-rendered table money cell is corrupted (back-reference bug, see Headline). |
| (2) Dates without time | **WORKS (owner)**, partial elsewhere | Owner Oldest Due is date-only. In supplier, 3 of 4 cells carry a time, so the date-only majority test keeps the time. |
| (3) Board subject line and NOT RECORDED | **WORKS (prompt), PARTIAL (answer)** | Both lines are present. The "couldn't find" denial is gone, but the opener still says the board members "are not explicitly recorded". |
| (4) Failed-QU honest message | **NOT EXERCISED** | No QU failure this run. The branch exists at `AiManager.php:2447–2467`. |
| (5) Security hardening | **NO FALSE REJECTIONS** | Across all 13 turns: no `safe_stop:true`, no SecurityException and no security_gate failure. The only failed executions are the two Excel attempts, and those are Neo4j SyntaxErrors in LLM-authored cypher. The tenant injector added `WITH * WHERE t.id IN $ai_team_ids` correctly, before the aggregating WITH. `security_filter_applied:false` on those two rows only reflects the failure. |
| (6) Language guideline names the QU language | **WORKS** | "ALWAYS respond in the SAME LANGUAGE … (the question's language: 'en')". All answers are in English. |

## Excel trace (team-roster-then-excel #1): why `email` is still missing

1. **Roster turn (t0):** FanOutCollapse rewrote the query to one row per member:
   ```
   … OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id
   WITH u, collect(DISTINCT tr.role)[0..11] AS role WHERE u IS NOT NULL
   RETURN u.name AS member_name, role LIMIT 100
   ```
   - The grouping is `WITH <anchor>, collect…` (`src/Services/Query/FanOutCollapse.php:221–223`). It **drops every other pattern variable (`t`, `tr`)**.
   - `collapseFanOut` returns the collapsed cypher as the turn's QueryGenerationResult (`src/Services/AiManager.php:1993–2000`). Only `metadata.pre_collapse_cypher` keeps the original.
   - That collapsed cypher is recorded as `last_cypher_query` (`src/Services/Context/ConversationContextManager.php:131`).
2. **Excel turn QU:** `intent:file_listing` (the guard still neutralizes it), `follow_up_reference:true`, `export_format:excel`, `entity_types:[Team, TeamRole, User, ContactDirectory]`, and **`property_concepts:["contact","email","phone"]`**.
3. **QG prompt:**
   - "Previous Query" is the **collapsed** cypher (`src/Services/PromptSections/ConversationContextSection.php:78`), and the "Active Filter" line is a garbled slice of it (`… OPTIONAL MATCH … WITH u, collect(...) …`). The block also says "EXTEND the Previous Query … keep its MATCH/WHERE and returned columns".
   - REQUESTED ATTRIBUTES (`src/Services/PromptSections/NameResolutionSection.php:64–78`) mandates `- phone: RETURN the mapped property as a column`, with "PRECEDENCE: … OVERRIDE any … entity description". That overrides the User card's "no phone on record".
4. **Generated cypher** (attempt 1; attempt 2 only added `AND u.current_team_id = t.id`):
   ```
   MATCH (t:Team) WHERE t.id = 3 AND t.id IN $ai_team_ids
   OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t)
   OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id
   WITH u, collect(DISTINCT tr.role)[0..11] AS role WHERE u IS NOT NULL
   RETURN u.name AS member_name, role, u.email AS email, t.contact_phone AS phone LIMIT 100
   ```
   - `u.email` is correct.
   - To satisfy the mandated "phone", the model borrowed the **team's** `t.contact_phone`, which is the wrong entity even semantically. `t` is out of scope after `WITH u, …`, so offset 417 is exactly `t.contact_phone`, and Neo4j raises ``SyntaxError: Variable `t` not defined``.
   - The execution repair (`src/Services/QueryGenerator.php:548`) appends only the raw error. The model kept `t.contact_phone`, so the same error came back and generation gave up.
5. **Export decision:**
   - `executionFailed` gives `currentUsable=false` and `currentColumns=[]` (`AiManager.php:2295–2296`), so `requestedFields=[]`.
   - `refersToPrevious` then exports `previous_result` (`AiManager.php:2335–2349`): the collapsed roster re-executed with `LIMIT 250` (`AiManager.php:2665–2672`), 250 of 523, columns Member Name and Role.
   - The disclosure has no requested fields, so it is silent. The `fan_out_collapse` stage did not run on this turn, because there was no fresh result.
6. **Gate:** `columns_include` inspected the delivered file's header `[Member Name, Role]`, which is the correct thing to check, and correctly reports email as missing.

**Root cause:** the conversation records the **presentation rewrite** (the collapsed per-anchor cypher) as the query that the generator must EXTEND. That rewrite has dropped `t`/`tr` from scope. The REQUESTED ATTRIBUTES block forces a non-existent "phone", so the model reaches for `t.contact_phone` and produces an unexecutable extension. The export then falls back to the previous result without disclosing the missing email.

**Fixes, in order of leverage:**
1. **Record the generator-facing query pre-collapse**, and keep the collapsed one for re-execution:
   - Persist `pre_collapse_cypher` from `AiManager.php:1999` as a separate snapshot key (`last_generated_cypher`, next to `ConversationContextManager.php:131`).
   - Render that key in `ConversationContextSection.php:78`, and derive the Active Filter from it.
   - The previous-result re-execution (`AiManager.php:2638`) keeps using the collapsed `last_cypher_query`.
   - Expected effect: the extension becomes `… RETURN u.name AS member_name, tr.role AS role, u.email AS email LIMIT 100`. That fans out 525/523, `collapseFanOut` collapses it, the same-population check passes, and the export is `current_result` with email.
2. **Do not force attributes that no listed property holds** (`NameResolutionSection.php:76–77`). Add: "map only to a property of the records being listed or of an entity they join to. If none holds it (e.g. phone for team members), omit it — never borrow another record's field (a team's phone is not a member's phone)." Optionally also stop QU from expanding "contact info" to "phone" when the anchor card says there is none.
3. **Scope-aware repair hint** (`QueryGenerator.php:548`): on ``Variable `x` not defined`` after a `WITH`, add "x was dropped by a WITH: carry it in that WITH or remove the column".
4. **Disclose on a failed extension** (`AiManager.php:2295–2326`): when the export refers to the previous result and the fresh query failed, set `requestedFields` from the failed cypher's RETURN aliases (or from the QU property_concepts). The composer then says "email could not be added".
5. Optional: raise or split `ai.export.document_row_limit` (250 < 523), or page the export, so the file is complete.

## Regressions (with traced cause)

1. **P0: money cells corrupted in every code-rendered table** (310 t0, ambiguous t1, owner-arrears, supplier).
   - `DataAnswerPlan.php:277`: `preg_replace(self::PLACEHOLDER_PATTERN, "\n\n" . $block . "\n\n", $narrative, 1)` is called with `$block` holding `$456.12`. PCRE back-references `$45`, `$7` and `$0` eat the leading digits.
   - It was exposed by the new `currency_symbol '$'` (CondoEdge `config/ai.php:387`) together with `TableRenderer::formatMoney` (`src/Services/Response/TableRenderer.php:334`).
   - Fix: `preg_replace_callback(self::PLACEHOLDER_PATTERN, static fn () => "\n\n{$block}\n\n", $narrative, 1)`, or escape `\` and `$` in `$block`. Add a unit test with a `$456.12` cell.
   - Same latent class: `AnswerValidator.php:319–323` uses a dynamic replacement string, so escape it too.
2. **Supplier voice "we owe" → "you owe"** (composition variance). Minor, and folded into the score.
3. **Roster action −0.05:** the My profile chip came back (identity chip on `navigation_target:own_team`).
4. **Buyer −0.05:** a misleading closing summary (variance).

## New / persisting defects

- **New:**
  - The collapsed cypher is recorded as the generator's Previous Query, and its Active Filter is garbled (see above).
  - REQUESTED ATTRIBUTES forces absent attributes.
  - The repair has no scope hint.
  - There is no disclosure when the extension fails.
  - The PDF turn ran a 9.5k-token QG call that returned an empty cypher.
- **Persisting:**
  - team-info: no type, no member count, padding columns.
  - ambiguous t0 never clarifies.
  - meetings: non-optional Schedule MATCH, "linked records" jargon, no past-meeting fallback.
  - board: "Bm Role" header, no "+N more" union chip, contradictory opener.
  - roster: no export chip, and the count is given as "rows".
  - tear-down: never names the main/bearing-wall test (1044), and `[2]` is mis-cited.
  - owner-arrears: "4 owners" for 4 units / 5 owners.
- **Withdrawn from p11:** p11 flagged the IS_EMAIL scope pattern (`contact_type_id IN [1,2,3]`) as wrong. The ContactDirectory enum in this run's prompt reads 1 = Email, 2 = Email (other), 3 = Email (business), so the pattern is correct and that p11 finding is withdrawn.

## Tokens / latency vs p11

- **Tokens:**

  | Run | Total | Calls |
  |---|---|---|
  | p11 | 180,883 | 36 |
  | **p12** | **189,377** | **36** |

  - +8.5k overall (+4.7%).
  - PDF +4.5k: a full QG call for an empty cypher, against p11's failed-QU turn.
  - 310 t0 +1.2k and buyer +1.0k. Every other turn moved by less than ±0.5k.
  - The Excel turn still spends 19.4k (13.6k QG across 2 failed attempts) for an unchanged file.
- **Latency:**
  - Pipeline sum 207.8 s, mean 16.0 s, median 14.6 s (p11: 653.8 s, inflated by the SSL incident).
  - On p11's 11 turns: 167.3 s, mean 15.2 s, median 14.6 s, vs p11 266.6 / 24.2 / 17.1.
  - Turns unaffected in p11 (team-info, board ×2, owner, supplier) now take 13.6–15.8 s, vs 9.5–14.3 s in p11. That is about 2–3 s slower, mostly in understanding (≈ 7–9 s vs ≈ 5.5 s at similar QU token counts), so it looks like provider latency.
  - 310 t0 took 32.3 s (understanding 26.2 s).
