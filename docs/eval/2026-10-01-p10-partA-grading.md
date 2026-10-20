# Grade: p10-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p10-1001-A.json`, generated 2026-10-01T02:15. ai git `badd2f1` + 473 dirty files, config_sha1 `5566d2f6…` (same as p9).
- **Models:** QU gpt-4o-mini, query generation gpt-4o, composition gpt-4o-mini (same as p9).
- **Harness:** 12 ok, 1 gate-failed (`team-roster-then-excel#1 columns_include`: "Missing column(s): email"). INFRA/VOID 0, no errors, no fallbacks, no retries, all validations clean.
- **Rubric/strictness:** identical to the p9 grading (pass ≥ 0.70, partial 0.40–0.69, fail < 0.40; action score grades code-attached chips in `response_metadata.invocations` plus any links/doors named in the text).

## Per-turn table

| # | Turn | p4 | p9 | **p10 answer** | **p10 action** | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.50 | 0.30 | **0.50** | 0.70 | partial (**+0.20, F1 fixed**) | Code table now renders `j.rousseau@novogestion.ca (that's you)`; `self_reference_guard: "table"`, gate `no_self_contact` passes (`marker_present:true`). Insider voice ("Your team is called Novo Gestion"). Still missing the required **type and member count**: cypher now projects `t.team_type` but it is shown raw as **"Team Type 2"** (not "management company"), no `count(members)`. Padding: empty "Team Address" column and Created At / Updated At timestamps in the on-screen table. Closing "check the options below" is backed by real chips. **Chips:** My profile, Team settings (same as p9; no members/portfolio/finance doors). |
| 1 | roster t0 | 0.85 | 0.80 | **0.80** | 0.55 | pass | 25-row name+role table, own row now marked "Julie Rousseau (that's you)" (nice F1 side-effect). Code line "Showing 25 of 523 rows — ask for the full list or an export." Total still only as "rows" in the code line, no "523 members" in the narrative. Cypher changed to TeamRole-anchored (`MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) MATCH (u:User)…`), total unchanged at 523. **Chips:** My profile (irrelevant), Team settings. Text invites an export but **no export chip** (unchanged gap). |
| 2 | roster t1 Excel | 0.75 | 0.45 | **0.35** | 0.70 | **fail (regression −0.10)** | F2 half-landed: QU `follow_up_reference:true` → `export_source: previous_result`, Role kept, one row per member, no 1352 fan-out, no "User —" title prefix ("Team members roles"). **But no contact info at all:** "Fields included: Member Name, Role." — the user explicitly asked "with their contact info"; email is absent (gate `columns_include` failed) and the answer does not say it was left out. Only **100 of 523** rows in the file (`export_source_complete:false`, disclosed). **Cause:** QU `intent: "file_listing"` (p9: `list`) → `file_context {enumeration:true, file_count:50}` → `file_listing` stage → `generateAndExecute()` short-circuits at `AiManager.php:1721` (`$isEnumeration && $hasFileContext`), so **no query generation ran** (`cypher: null`, only 1 LLM call) and the FRESH-QUERY ENRICHMENT path (`AiManager.php:2061–2093`) had no superset query to prefer; `requested_fields` is empty so nothing missing is disclosed. **Action:** code-built Download Excel link present (core door), but the file lacks the asked-for fields. |
| 3 | 310 t0 | 0.35 | 0.85 | **0.85** | 0.95 | pass | Identical cypher and data to p9: 3 rows with union, 0.00, 0 open invoices. Closing stays scoped to unit 310. Minor: numbered list repeats the table (padding), "0.00" without $, empty Oldest Due column on screen. **Chips:** "Unit 310 — SDC Carré Soho Phase 3 / …St-Hubert / …825 de Bruxelles" (ids 159/301/472) + Receivables report — exactly right. |
| 4 | 310 t1 PDF | 0.85 | 0.85 | **0.90** | 0.85 | pass (+0.05) | `previous_result`, complete, 3 rows, union column kept. All-empty **Oldest Due dropped** from the document and disclosed ("No values found for: Oldest Due") — F2 empty-column drop works. Title "Unit 310 current debt". No wasted query-generation call this time (p9 spent 1.7 s on an empty one) — but only because of the same `file_listing` misroute as turn 2 (harmless here). Minor: PDF still prints raw `0`, not 0.00. **Action:** Download PDF link. |
| 5 | ambiguous t0 | 0.00 | 0.15 | **0.25** | 0.40 | fail (+0.10) | Still no clarification (`needs_clarification:false`, `candidate_readings:[]`); QU reads "my account balance", cypher on her own ContactUnits → empty. Answer is now an honest empty: "there are no linked records available for you" — no deflection to a profile page, no implied personal balance. Still not the required clarifying question; ends with "please let me know" (guideline violation). **Chips:** none (p9 had a misleading My profile) — F4/F5 behave: no create chip, no "attached below" claim. No useful door either. |
| 6 | ambiguous t1 | 0.35 | 0.85 | **0.85** | 0.95 | pass | Same 3 per-union $0 rows (cypher equivalent, `LIMIT 100` added), no re-ask. Narrative list repeats the table (padding); "Unit 310 owes nothing" singular wording after the 3-way breakdown. **Chips:** 3 union-labelled unit chips + Receivables. |
| 7 | tear-down t0 | 0.40 | 0.50 | **0.40** | 0.55 | partial (regression −0.10) | Same chunks as p9 (38: 1042–1047, 61: 1064–1068, 113: 1098–1102, ai-help doc [4]). **Wrong lead:** "You cannot tear down the wall … without proper authorization" — stated unconditionally; a kitchen/living wall inside one unit separates no portions, so it is private (1042) unless a main wall (1044); the answer never makes that distinction. It applies 1045 by implication and then imports 1100's "no change to relative value" condition (which governs boundary changes between contiguous private portions) as a condition for removing a common wall — misapplied. **Mis-citation persists:** `[1][2]` with [2] = 1064 (expense contribution). No invented article numbers. **Links:** the p9 named route list is gone (F9), but it still ends "you can check the unit management section" — a door taken from the ai-help doc with nothing attached. Prompt diff vs p9 is only the new "No links … attached" line, so the content regression is composition variance (gpt-4o-mini). |
| 8 | buyer-arrears t0 | 0.25 | 0.75 | **0.65** | 0.70 | partial (regression −0.10) | Chunk 66 (1068.1–1070) still first; 1069 cited [1], no placeholder. **Distorted nuance:** "responsible … unless you have a statement from the syndicate indicating that there are no outstanding fees" — the actual protection is that the buyer is bound only if the syndicate provides the requested statement **within 15 days**; the answer turns it into something else. Opens categorically ("you are responsible for the unpaid condo fees") and drops p9's recommendation to request the statement before buying; closes with generic "explore the relevant documents". Retrieval slightly reordered ([2] is now 1071–1074 contingency/insurance, irrelevant). **Action:** no chips, no false link claim (correct for legal; no useful door). |
| 9 | board t0 | 0.40 | 0.55 | **0.45** | 0.70 | partial (answer −0.10, action +0.15) | F8 confirmed: headers now "First Name \| Last Name \| Bm Role \| Year" (no "C.first Name"). Same cypher/data (latest year per union, 37 rows, union column). **New narrative defect:** "I couldn't find specific information about your board of directors … I can provide you with a list of contacts from your union, which may include board members" and "You can review the details to identify the board members and the president" — the rows ARE board members; the answer mislabels them as contacts and pushes the work back to the user ("your union" singular over 8 unions). President absent is not stated plainly. Prompt diff vs p9 is only the headers + chip list; "Bm Role" is still an abbreviation and the DATA SUMMARY never names the row subject (board members), so the composer lost the "board" signal. Empty role column still shown. **Chips:** 5 Union chips (825 de Bruxelles, Chemin d'oka, Carré Soho Ph 2, Carré Soho Ph 3, Terrasses St-Hubert) — right grain, but the table spans 8 unions and **no "+N more" chip** was attached; plus Team settings, Contacts, Download Excel. |
| 10 | board t1 meetings | 0.40 | 0.40 | **0.45** | 0.90 | partial (+0.05) | `empty_result`. Cypher still has a **non-optional** `MATCH (s:Schedule)` after selecting the next meeting, so a meeting without agenda rows would be hidden. Answer is a bit clearer than p9 ("I couldn't find any details about upcoming meetings or their agendas") but still uses "no linked records available" jargon and offers no fallback to the most recent past meeting. **Chip:** Meetings (expected door; "check the Meetings section" correctly refers to it). |
| 11 | owner-arrears t0 | 0.40 | 0.75 | **0.75** | 0.90 | pass (action +0.50, F7 fixed) | Same correct data: 305 $456.12 (2 overdue, 2021-12-01), 311 $397.81 (Bailly, Gobeil), 308 $307.55, 605 $50.00, union 8 only, ContactUnit owners. Narrative dates are now clean (no time of day), but the **total $1,211.48 is still never stated** although `COMPUTED TOTALS: TOTAL of Balance over all 4 rows = 1,211.48` is in the prompt (it was in p9's prompt too). **Cause:** the composition prompt's HOMONYMS directive — "4 DIFFERENT records share the Union Name 'SDC Carré Soho Phase 3' — they differ by Oldest Due … Your FIRST sentence must say that there are 4 different … records (one per Oldest Due)" — treats the filter-constant union as a homonym with a date as disambiguator (and two rows share 2022-01-01). The model obeys ("There are 4 different records for owners … each with its own oldest due date") and the total gets crowded out. No $, table still shows "2021-12-01 15:00". **Chips:** 4 Unit chips (Unit 305/311/308/605, ids 154/160/157/186) + Receivables report — exactly the expected grain; no timestamp label. No export chip. |
| 12 | supplier t0 | 0.85 | 0.90 | **0.95** | 0.90 | pass (+0.05) | "Currently, **we owe** our suppliers a total of **$11,493.17**, with 4 open bills." — exact, from FACTS (query total), insider voice now correct, $ formatted. Per-supplier table matches all four figures. Minor: time-of-day in Oldest Due. **Chip:** Payables (`bills_table`); "using the button below" is backed by a real chip. |

## Stats

- **Answer means (13 turns):**

  | Run | Mean |
  |---|---|
  | Baseline | 0.523 |
  | p4 | 0.488 |
  | p9 | 0.623 |
  | **p10** | **0.627** |

  p10 is +0.004 vs p9 (flat). On the 11 standard-unchanged turns (excl. owner-arrears and supplier): p9 0.586 → p10 **0.586** (flat). Gains (team-info +0.20, ambiguous +0.10, pdf/meetings/supplier +0.05) are cancelled by excel −0.10 and three composition-variance drops (tear-down, buyer-arrears, board −0.10 each).
- **Action mean (13 turns):** p9 0.685 → **p10 0.750** (+0.065). Drivers: owner-arrears 0.40 → 0.90 (F7), board 0.55 → 0.70 (per-union chips), ambiguous 0.20 → 0.40 (misleading chip gone), tear-down 0.50 → 0.55. Weak spots: roster (no export chip), ambiguous (no door), excel (file missing the asked-for fields), board (no "+N more").
- **Counts (pass / partial / fail):**

  | Run | Pass | Partial | Fail |
  |---|---|---|---|
  | Baseline | 4 | 4 | 5 |
  | p4 | 4 | 5 | 4 |
  | p9 | 7 | 4 | 2 |
  | **p10** | **6** | **5** | **2** |

  - Pass: roster t0, 310 t0, pdf, 310 t1, owner-arrears, supplier.
  - Partial: team-info (up from fail), tear-down, buyer-arrears (down from pass), board t0, meetings.
  - Fail: excel (down from partial), ambiguous t0.
- **Harness vs grader:** agree on excel (gate caught the missing email). The harness misses: the board "couldn't find … contacts which may include board members" misstatement, the owner-arrears missing total, the tear-down wrong lead/mis-citation, the buyer-arrears distorted 15-day rule, the ambiguous-t0 non-clarification, and team-info's raw "Team Type 2" / missing member count.

## Fix verification

| Fix | Status | Evidence |
|---|---|---|
| **F1** team-info "(that's you)" + final self-reference guard | **CONFIRMED** | t0 table cell `j.rousseau@novogestion.ca (that's you)`, `self_reference_guard:"table"`, gate `no_self_contact` passed (`email_present:true, marker_present:true`). Also marks her row in roster t0. Remaining t0 gaps (type decoding, member count) are query-coverage, not F1. |
| **F8** board headers | **CONFIRMED (cosmetic)** | Headers "First Name / Last Name / Bm Role / Year". "Bm Role" is still an abbreviation; better to humanize from the schema label ("Board role"). |
| **MoneyColumns** (unaliased money columns summed) | **NOT EXERCISED** | Every money column in Part A is aliased. No change to totals: owner-arrears `COMPUTED TOTALS 1,211.48` (same as p9), supplier FACTS 11,493.17/4, 310 rows 0.00. |
| **F2** excel follow-up → extend previous roster query | **PARTIAL / NOT EFFECTIVE** | Follow-up detection works (`follow_up_reference:true` → `previous_result`), Role kept, one row per member, no fan-out, empty-column drop works (seen on the PDF: Oldest Due dropped + disclosed). But the extension never happened: QU `intent:"file_listing"` routed to file enumeration, `generateAndExecute` returned early, no cypher → file = Member Name, Role only; 100 of 523 rows; no disclosure that the requested contact info is missing. Gate `columns_include` failed. |
| **F6** export title from rows' subject | **CONFIRMED** | Excel "Team members roles" (no "User —" prefix), PDF "Unit 310 current debt". |
| **F4** no create chip on possessive empty results | **CONFIRMED** | ambiguous t0 (possessive, empty): no chips at all; meetings (empty): only Meetings. |
| **F7** chip grain + disambiguator + "+N more" | **CONFIRMED for grain/label, NOT for "+N more"** | owner-arrears: 4 Unit chips "Unit 305/311/308/605", no timestamp label. board: per-union chips (5). But 8 unions are in the table and no "+N more" chip is attached; `ActionAttacher::recordChips` (lines 229–244) only emits it when `genericFor(label,'list')` resolves, so most likely no Union list action is registered for this user (unverified). |
| **F5** no-chip turns don't claim "attached below" | **CONFIRMED** | No-chip turns 5/7/8 carry the new prompt line "No links, buttons or files are attached to this answer…"; none of the answers claims an attachment. |
| **F9** legal turns no longer name unattached links | **MOSTLY** | tear-down no longer lists "View the current union / Manage a union / Units catalog", but still says "check the unit management section" (from the ai-help doc [4], still in the legal composition context). buyer-arrears: "explore the relevant documents" (harmless). |
| **F3** date tense tags + human-readable record names | **CONFIRMED (prompt)** | t0 prompt: "Created At: 2021-01-03 20:04 — PAST (before today) — describe it in the past tense…"; t11: "DATES RELATIVE TO TODAY … Today is 2026-10-01". No tense error anywhere. Narrative dates lose the time of day; the rendered tables still show "2021-12-01 15:00". |
| **ROW GRAIN** query rule | **NO HARMFUL SIDE-EFFECT** | Present in every query-generation prompt. Cyphers identical or equivalent on 310 ×2, board, owner-arrears, supplier; roster re-anchored on TeamRole with the same 523 total; all totals and row counts unchanged (3, 3, 37, 4/1,211.48, 1/11,493.17). Meetings still uses a non-optional Schedule MATCH (pre-existing). Cost: +~200 query-gen tokens per data turn. |

## Regressions vs p9 (with cause)

1. **excel t1 −0.10 (0.45 → 0.35, now fail):**
   - **Trace:** `understanding.intent = "file_listing"` (p9: `list`); stages `file_context {enumeration:true, file_count:50}` → `file_listing {files:50}` → `export {export_source: previous_result, rows:100}`; `cypher: null`; usage has only the understanding call.
   - **Mechanism:** `AiManager::generateAndExecute()` returns early at line 1721 on any enumeration intent, before query generation, so the F2 "extend the previous query" path (`AiManager.php:2061–2093`, superset → `preferCurrent`) never had a fresh query to compare. `isFileListingIntent()` (line 2667) has no guard for `export_format` or `follow_up_reference`.
   - **Likely trigger (hypothesis):** the F2 rewrite of the QU `follow_up_reference` doc ("delivering it again (in chat or as a downloadable **file**)") sits next to the `file_listing` definition; both export follow-ups (Excel and PDF) flipped to `file_listing` in p10 and in no other turn.
   - **Fix:** (a) deterministic: `isFileListingIntent()` returns false when `qu->exportFormat !== null` or when `followUpReference` is true with a recorded data result; (b) QU prompt: "an export request takes the intent of the data being exported, never file_listing"; (c) when QU `property_concepts` (contact/email/phone) are not covered by the exported columns, disclose it ("email not included").
   - Also: previous-result exports are capped at 100 rows (the recorded cypher's `LIMIT 100`); re-run without the display limit up to `ai.export.document_row_limit`.
2. **board t0 −0.10 (answer):** prompt diff vs p9 is only the humanized headers and the longer chip list. The narrative now says it "couldn't find" board info and calls the board rows "contacts which may include board members". The DATA SUMMARY never names its row subject, and "Bm Role" is an opaque header, so gpt-4o-mini lost the board signal. **Fix:** humanize headers from schema property labels (bm_role → "Board role"), and add a subject line to the DATA SUMMARY ("rows: board members — latest year per union").
3. **tear-down −0.10, buyer-arrears −0.10:** composition variance on the same retrieval (tear-down prompt differs only by the new no-links line; buyer-arrears chunk 66 still first, [2]/[3] reordered). Both are legal-reasoning quality issues already flagged in p9 (no private-wall distinction / 15-day statement nuance), not caused by a p10 code change.

## New / persisting defects

1. **QU file_listing misroute on export follow-ups** (above). It also enumerates 50 files on every export turn (+~260 ms of file_context).
2. **Spurious HOMONYMS directive on owner-arrears:** the union (a constant filter value) is treated as a homonym disambiguated by a date. It forces the first sentence and crowds out the computed total ($1,211.48 has been in the prompt since p9). F7's "never a date as disambiguator" rule was applied to chips only, not to the composition HOMONYMS builder. Fix: skip homonym groups whose shared column is constant across all rows (or is a filter literal in the cypher), and never use date columns as disambiguators there either.
3. **team-info:** raw `team_type 2` rendered; no member count; empty Team Address column and created/updated timestamps on screen (the empty-column drop applies only to exports).
4. **board:** no "+N more" chip with 8 unions / 5 chips.
5. **Ambiguous t0:** QU still never sets `needs_clarification` for "Show me the balance."; answer ends "please let me know".
6. **Meetings:** non-optional Schedule MATCH; no past-meeting fallback (pre-existing).
7. **Roster t0:** no export chip despite "ask for … an export" (pre-existing).

## Tokens / latency vs p9

- **Tokens:**

  | Run | Total | Calls | Per turn |
  |---|---|---|---|
  | p9 | 173,974 | 35 | ≈13.4k |
  | **p10** | **165,080** | **33** | **≈12.7k** |

  - **−5.1%**, entirely from the two export turns skipping query generation (excel 11.6k → 5.7k, pdf 13.5k → 5.7k). The excel saving is a side-effect of the misroute defect, so it is not a real win; the pdf saving is legitimate (p9 wasted a QG call there).
  - Query generation grew on every data turn (+180 to +1,630 tokens; ROW GRAIN rule ≈ +200, 310 t0 +1.6k). Composition +0.1–0.3k (dates / no-links lines). Understanding is flat at ≈5.5k.
- **Latency (s):**

  | Run | Sum | Mean | Median |
  |---|---|---|---|
  | p9 | 142.8 | 11.0 | 9.6 |
  | **p10** | **140.5** | **10.8** | **10.2** |

  - Excluding the 310 t0 outlier (20.9 s: understanding 13.6 s, an OpenAI latency spike, with QG 4.7 s), the mean is **10.0 s**.
  - Legal turns 19.5/21.4 s → 12.0/12.3 s (file_context 9.1/10.3 s → 3.7/4.9 s, likely environmental).
  - Export turns 10.1/5.6 s → 5.5/4.9 s.
  - QG mean 2.2 s → 2.9 s (prompt growth plus the t0/t11 spikes). Understanding (≈5 s median) is still the largest fixed cost.
