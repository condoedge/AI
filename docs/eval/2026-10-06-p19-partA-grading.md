# Grade: p19-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p19-1001-A.json`, chunk 1 run at 2026-10-06T21:31-04:00. ai `badd2f1` + 589 dirty files (p18: 559). config_sha1 `5eae9d5f…` (p18: `4aae3535…`).
- **Models:** QU gpt-4o-mini, QG gpt-4o, composition gpt-4o-mini (unchanged). New: the follow-up writer runs on gpt-4o (`gpt-4o-2024-08-06` appears in `usage.models` on the two knowledge turns only).
- **Harness (summed from the rows):** 13/13 `ok`, 21/21 gates passed, 0 INFRA/VOID, 0 errors, 0 fallbacks. The top-level counters (`total_rows: 7`, `usage_total` 79,490) describe one chunk only.
- **Rubric / strictness:** identical to p14/p16/p17/p18. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action = code-attached chips plus doors named in the text; follow-up question chips are scored in the action column with the strictness applied to page chips.
- **Not verified:** nothing was executed against the graph, no phpunit, no artisan. No chip was clicked: the re-run Excel chip, the four clarify reply chips and the two legal follow-up chips are judged from their metadata and the passage text only. Single run: every "variance" verdict rests on a prompt diff against p18, not on repeats.

---

# 1 — GRADING

## Headline

- **Answer mean 0.808** (p18 0.788, +0.019). **Action mean 0.854** (p18 0.796, +0.058).
- **Counts 10 pass / 3 partial / 0 fail** (p18 11 / 2 / 0). Buyer-arrears falls back from pass to partial on a byte-identical composition prompt.
- Answer gains: clarify +0.10, tear-down +0.10, board +0.10, ambiguous t1 +0.05, meetings +0.05, owner-arrears +0.05.
- Answer losses: buyer -0.15 (variance), roster -0.05 (code change: the lead has nothing left to say).
- Action gains: clarify +0.25, roster +0.25, tear-down +0.10, buyer +0.10, PDF +0.05. No action loss.

## Per-turn table

| # | Turn | p17 ans / act | p18 ans / act | p19 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.80 / 0.70 | 0.85 / 0.70 | **0.85 / 0.70** | pass (= / =) | Answer and composition prompt are byte-identical to p18. Lead still restates name, type and member count above the record block. No facts line in record mode, no `record_enrichment` stage (the row is an aggregate without the team id). Chips: My profile, Team settings. Still no portfolio / finances / meetings doors. |
| 1 | roster t0 | 0.75 / 0.60 | 0.85 / 0.60 | **0.80 / 0.85** | pass (-0.05 / +0.25) | Lead: "Your team consists of **several members** with various roles, including yourself as the Lead Union Manager." — the hedge the standard names, saved from the automatic fail only because the code line "523 members." follows it. Showing-line now "Showing 25 of 523 **members**". Still no ORDER BY: 3 of the 6 staff are in the 25 rows. Chips: Team settings, **Download Excel** (`mode: rerun`, `language: en`). |
| 2 | roster t1 Excel | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | `previous_result`, complete, 523 rows, 523 distinct emails, role labels, headers Name / Email / Role — payload identical to p18. Generation was NOT skipped (7,264 tokens for the same cypher), see section 2. Same hedge "with the available information". |
| 3 | 310 t0 | 0.85 / 0.95 | 0.90 / 0.95 | **0.90 / 0.95** | pass (=) | One-sentence lead, then the code line "3 different units named 310 — one per union." ("records" is gone). The lead now merges them: "Unit 310 currently owes $0.00 **across all associated unions**" — one unit with several unions, against the HOMONYMS note ("never merge them into one sentence"). Gain and loss cancel. Table and chips unchanged. |
| 4 | 310 t1 PDF | 0.90 / 0.85 | 0.90 / 0.85 | **0.90 / 0.90** | pass (= / +0.05) | Stage `recorded_result_export` {generation_skipped: true, recorded_rows: 3}; no QG call (p18: 8,146 tokens). Money cells are "$0.00" in rows and markdown (p18: raw `0`). Union column kept. The file is this turn's door, hence the action gain. Same hedge. |
| 5 | ambiguous t0 | 0.70 / 0.65 | 0.80 / 0.65 | **0.90 / 0.90** | pass (+0.10 / +0.25) | "Which one do you mean: outstanding balance of one unit, balance of an account, balance of a fund, or balance of an invoice?" — the standard's three readings (unit, accounts, fund), no link-table kind, "is meant" gone. "balance of an invoice" is a stretch. Four `ask_follow_up` reply chips, one per reading. Each chip sends a fragment that still lacks its object (which unit, which fund). |
| 6 | ambiguous t1 | 0.85 / 0.95 | 0.85 / 0.95 | **0.90 / 0.95** | pass (+0.05 / =) | One sentence (p18: three, "record(s)" three times, filler) plus the code line. Same merged singular as turn 3: "across all recorded unions". Canonical cypher, three rows, same chips. |
| 7 | tear-down t0 | 0.50 / 0.55 | 0.40 / 0.55 | **0.50 / 0.65** | partial (+0.10 / +0.10) | Art. 1045 keeps its condition and the answer says a wall that is part of the private portion leaves "more flexibility" — the distinction the standard asks for, loosely. But art. 1100 (boundaries between contiguous private portions) is applied to her interior wall in points 2 and 4 (hypothecary creditors, amending the declaration and cadastral plan "at your expense"); the majorities are "possibly other co-owners"; art. 1044 (main walls) unused; four numbered sections plus intro and summary. No article number invented. First draft had no citation (`citation_missing`, retried, recovered). One follow-up chip, borderline (section 2). |
| 8 | buyer-arrears t0 | 0.60 / 0.70 | 0.75 / 0.70 | **0.60 / 0.80** | **partial (-0.15 / +0.10)** | Art. 1069 named, liability with interest grounded in [1], stated three times. The statement is again only advice ("to understand any outstanding amounts"): the 15-day protection is in passage [1] and unused — word for word the p17 answer. One follow-up chip on the seller's certificate (art. 1068.1): related, answerable, not the standard's door ("how to request the statement"). |
| 9 | board t0 | 0.55 / 0.75 | 0.55 / 0.75 | **0.65 / 0.75** | partial (+0.10 / =) | Code line "37 board members across 12 unions. Not recorded: Board role." and "Showing 25 of 37 board members" are right. The model's lead is two filler sentences and the first is false: "The information about the board of directors **and the president is available in the records**. You can find the details in the table below." — directly above "Not recorded: Board role". No "our board". Names still in two columns. Chips unchanged: 5 contact chips over 5 of 12 unions, Download Excel. |
| 10 | board t1 meetings | 0.85 / 0.95 | 0.80 / 0.95 | **0.85 / 0.95** | pass (+0.05 / =) | "no upcoming meetings scheduled for your **team**" (p18 "your union") above three meetings of three unions. Agenda half of the question still not addressed. Chips: 3 meeting records plus Meetings. |
| 11 | owner-arrears t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.90 / 0.90** | pass (+0.05 / =) | Same cypher, 4 units largest first, $1,211.48 in the first sentence. The count is now the code line "4 units." (p18: "4 owners" for 4 units / 5 owners). The lead is two sentences against "ONE sentence"; the second is filler. |
| 12 | supplier t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | "Currently, you owe a total of $11,493.17 to your suppliers, with 4 open bills." Four figures exact. Voice slip unchanged ("you … your suppliers"). Chip: Payables. |

## Means

| Run | Answer, all 13 | Action, all 13 | Answer sum | Action sum |
|---|---|---|---|---|
| p17 | 0.765 | 0.796 | 9.95 | 10.35 |
| p18 | 0.788 | 0.796 | 10.25 | 10.35 |
| **p19** | **0.808** | **0.854** | **10.50** | **11.10** |

11-turn subset (without owner-arrears and supplier): answer 0.795 (p18 0.777), action 0.845 (p18 0.777).

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p17 | 10 | 3 | 0 |
| p18 | 11 | 2 | 0 |
| **p19** | **10** | **3** | **0** |

- **Pass:** team-info, roster, Excel, 310 t0, PDF, ambiguous t0, ambiguous t1, meetings, owner-arrears, supplier.
- **Partial:** tear-down (0.50), buyer (0.60), board (0.65).

---

# 2 — FIX VERIFICATION

| Fix | Status | Evidence |
|---|---|---|
| Follow-up chips, clarify turn | **Verified (not clicked)** | Turn 5: 4 `ask_follow_up` invocations, `attached_actions` role `follow_up`, one per `candidate_readings` entry, `params.question` = the reading, chip text = the reading capitalized. Zero LLM (no `follow_ups` stage on this turn). |
| Follow-up chips, legal turns | **Works; 2 chips, both acceptable, none natural** | New stage `follow_ups` after composition on turns 7 and 8: {proposed: 1, kept: 1, dropped: [], passages: [1]} on both. Four excerpts offered per turn, three declined per turn; all six declined excerpts are off the question (art. 1064 common expenses, 1082 emphyteusis, 1070.1.1 language of documents; 1063, 1076, 1070.1.1). No off-topic chip. Chip table below. |
| Facts line | **Verified on 6 turns** | `facts_line`: roster ["count"] "523 members."; 310 t0 and t1 ["homonyms"] "3 different units named 310 — one per union."; board ["count","groups","not_recorded"] "37 board members across 12 unions. Not recorded: Board role."; owner-arrears ["count"] "4 units.". Nouns are host plurals, none from the model. Absent, correctly, on the record block (turn 0), the fallback rows (turn 10) and the unpacked single row (turn 12). |
| One-sentence lead that does not restate the facts line | **Restating: held. One sentence: 2 of 6 broken. Side effect: empty leads** | Prompt: "Write ONLY the lead — ONE sentence that answers the question" and "The system itself prints … do NOT restate any of it … your sentence only answers the question" (`DataAnswerPlan.php:2165-2181`). No lead restates a count or a group. Two sentences on board and owner-arrears; nothing cuts the second. On the two list questions the answer IS the table, so the sentence has no content: "several members with various roles" (roster), "available in the records … table below" (board, and false about the president). See section 3. |
| Showing-line names the kind | **Verified** | "Showing 25 of 523 members", "Showing 25 of 37 board members" (p18: "rows" on both). |
| Roster: Download Excel on the capped list | **Verified present (not clicked)** | Invocation `export_excel` {title: "Team Members and Roles", mode: "rerun", language: "en"}, role `next_step`. `result_count` 100, `result_total` 523. The composition prompt lists it: "Attached below this answer: Team settings; Download Excel." |
| Export follow-up skips generation | **PDF yes; Excel no, by the rule as written** | PDF: `recorded_result_export` {generation_skipped: true, format: pdf, recorded_rows: 3}; usage is the QU call only (5,163 tokens; p18 13,293). Excel: QG ran (7,264 tokens) and regenerated the identical cypher. QU concepts ["email","role"] are both carried by the recorded result, but QU relabelled the subject "Team Members Contact Information" (recorded: "Team Members and Roles"), which fails condition 3 of `PreviousResultExport::asksNothingNew`. The class comment names this exact case as intended. The file is the same either way. |
| Files match what was asked | **Verified** | Excel: 523 rows, 523 distinct non-empty emails, roles 517 / 2 / 1 / 1 / 1 / 1 as labels, headers Name / Email / Role, gate `columns_include` passed. PDF: 3 rows, headers Union Name / Unit Name / Balance / Open Invoices / Invoices Due, gate `disambiguation_column_present` passed. |
| PDF money cells formatted | **Verified** | `export_document_rows`: balance "$0.00", invoices_due "$0.00" (p18: integer 0). Markdown table the same. |
| Single-record enrichment | **Not exercised** | No `record_enrichment` stage on any of the 13 turns. The only one-row results are the team aggregate (turn 0: `count(DISTINCT u)`, no record id returned) and the supplier total (turn 12), neither a record with an id. |
| Non-overlapping legal passages | **Holds; merge not exercised** | Articles printed in the composition prompt, counted per turn: 25 on tear-down, 26 on buyer, 0 duplicates (p18 Part A also 0). No `passage_overlap` entry in either `file_context` stage, so no two kept passages shared a chunk. |
| Clarify kinds limited to entities with a page | **Verified** | Clarity prompt 7,748 -> 4,737 characters, bullet lines 81 -> 31 (fix log: 72 -> 22 kinds), call 1,867 -> 1,171 tokens (-37%). Readings: unit, account, fund, invoice (p18: unit, account, union, **contact unit**). `fallback_question: false`. |
| Board "contacts" -> "board members" | **Verified** | Cypher links `(cur)-[:BELONGS_TO_CONTACT]->(c:Contact)` in a plain MATCH; facts line and showing-line both say "board members". The prompt's NOT RECORDED note now reads "Board role" (p18 "Bm Role"). |
| QU possessive | **13 of 13** (p18 12) | Turn 10 "our": true, ["our"], collective. |
| Security / admission | **Clean** | 13/13 `security_gate` passed. 9 executions (p18: 10; the PDF turn no longer executes), `safe_stop: false` and `security_filter_applied: true` on all 9, no repair attempts. No SecurityException, no `admission` stage, no busy answer, `errors` and `fallbacks` empty on all 13. Unchanged watch item: the meetings fallback cypher is logged without a team predicate (`scope: "team"` is applied by the executor and is not visible in the trace); same rows as p17 / p18. |

## Every follow-up chip

Labels: N natural, A acceptable, O off-topic.

| Turn | Chip question | Source | Label | Answerable from the source |
|---|---|---|---|---|
| 5 clarify | outstanding balance of one unit | reading 1, host calculation Q1 | N | n/a (reply). Needs a unit: a second question will follow. |
| 5 clarify | balance of an account | reading 2 | N | n/a. The standard's "the union's accounts". |
| 5 clarify | balance of a fund | reading 3 | N | n/a. The standard's "a fund". |
| 5 clarify | balance of an invoice | reading 4 | A | n/a. An invoice has an amount due, not a balance; the weakest reading. |
| 7 tear-down | What majority is needed to change the destination of the immovable? | passage [1], art. 1098 | A (borderline) | Yes: "three-quarters of the co-owners representing 90% of the votes", item 1°. |
| 8 buyer | What certificate must the seller provide to the buyer in a condo sale? | passage [1], art. 1068.1 (metadata says 1068) | A | Yes: "a certificate of the syndicate attesting to the condition of the immovable", given by the syndicate within 15 days. |

Totals: 6 chips — 3 N, 3 A, 0 O. 6 excerpts declined, all correctly.

Why the two legal chips are A and not N:

- **Tear-down.** Removing a wall between a kitchen and a living room does not change the destination of the immovable, and nobody types "destination of the immovable". It is kept as acceptable because the answer itself raises the point ("especially if the change affects the destination") and the standard lists destination majorities.
- **Buyer.** The certificate attests to the condition of the building, not to the arrears she asked about. It is a real buyer-side protection, so on the purchase but one step off the question.

Two structural limits of the excerpt selection (`FollowUpWriter::excerpts`, `DeepeningContract::citedArticles`), both read from code:

- **A cited article is "used" as a whole.** On buyer the best door is the second paragraph of art. 1069 (the 15-day statement), which the answer dropped. Art. 1069 is named in the answer, so it is covered and never offered; the first new article of the passage (1068.1) is offered instead.
- **A passage cited without an article number is covered as a whole.** On tear-down passage [3] (art. 1042-1047) is cited as "[3]" with no article named, so `citedArticles` falls back to every article of the passage. Art. 1044 (main walls are common) — the natural "what if it is a bearing wall?" — produced no excerpt.

Minor: the article line pattern keeps the integer part only, so `follow_ups.article` is 1068 for art. 1068.1. Excerpts are cut at 240 characters.

---

# 3 — REGRESSIONS VS p18

| Turn | Delta | Cause | Evidence |
|---|---|---|---|
| buyer, answer | -0.15 | **Pure model variance** | The composition prompt is byte-identical to p18 (25,681 characters, 0 differing lines; same passages 66, 60, 83, 72, 108). p18 used the 15-day paragraph of art. 1069; p19 reproduces the p17 answer ("to understand any outstanding amounts"). The paragraph has now been used in 1 of 6 graded runs. |
| roster, answer | -0.05 | **Code change** | Composition prompt diff vs p18, 4 lines. Removed: "Your FIRST sentence states the number of records (523 in all)". Added: "ONE sentence that answers the question" and "do NOT restate any of it (no count, no grouping…) — your sentence only answers the question". For "Who are the members…" the answer is the table; with the count forbidden, the model wrote "several members with various roles". p18's lead ("There are 523 members in your team, and here are their roles:") is now disallowed. |
| board, lead (inside a +0.10 turn) | new contradiction | **Code change sets it up; the direction is variance** | Same diff, plus the NOT RECORDED note now says "that line IS the answer to any part of the question about it … do not restate it in your own words". Told to answer "who is the president" in one sentence without saying it is not recorded, the model said it "is available in the records". p18 erred the other way ("not specified in the available data" above 25 board members). |
| 310 t0 and t1, lead (score unchanged) | merged singular | **Code change sets it up** | The HOMONYMS note no longer asks for a first sentence about three records ("The system states this itself … do not restate it"). The lead now speaks of one "Unit 310 … across all associated unions". The code line directly below corrects it. |

No action regression: every chip of p18 is still attached; 7 chips are new (Download Excel on the roster, 4 clarify replies, 2 legal questions).

Not regressions, but new observations:

- **Tear-down retrieval moved on QU variance.** The QU prompt is identical to p18 (0 differing lines); `search_query` came back "rules regulations modifying wall kitchen living room unit" (p18: "modifying wall between kitchen and living room in unit"). That angle now clears no passage (p18: chunk 38), so art. 1042-1047 dropped from passage [1] to [3]. Final passages 113, 61, 38, 89, 72 (p18: 38, 113, 61, 82, 89). Chunk 72 (art. 1070.2-1072, score 0.415) is in no angle's logged top five; I could not trace which pick admitted it. Art. 1063 and 1097 are still missing. No probe dropped this run (p18: 2).
- **Tear-down composition instructions are unchanged** (the diff vs p18 is the five passage headers and the statute text only). The +0.10 is variance in the other direction; this turn has scored 0.40, 0.30, 0.50, 0.40, 0.50.
- **Citation retry on tear-down.** `validation.violations_first: ["citation_missing"]`, `retried: true`, `citation_enforcement: "recovered"`. p18 had no retry on the same instructions. Cost: a second composition call (+6,581 tokens, composition 2.96 s -> 7.24 s).
- **Meetings +0.05** ("your team") follows QU catching "our" again (identity tiers back, QG +555 tokens). Variance, as was the p18 loss.
- **Board QG +668 tokens:** Union tier `seed` (p18 `tenant`). QU variance; the cypher is identical.

---

# 4 — REMAINING DEFECT RANKING

Missing points: answers 2.50, actions 1.90. Stage letters: (a) understanding, (b) retrieval, (c) query generation, (d) data in the prompt but ignored, (e) a composition instruction, (f) action attachment.

| Rank | Defect | Turn(s) | Stage | Points | Fix determinism | Model variance? |
|---|---|---|---|---|---|---|
| 1 | The model is still asked for a lead when code has already said everything: filler, a false sentence, a merged singular, a second sentence | 9, 1, 3, 6, 11 | (e) | ~0.50 | high | Wording yes; the empty lead no |
| 2 | Follow-up excerpts: a cited article or passage is "used" as a whole, so the best next question is never offered | 7, 8 | (f) | ~0.55 | medium-high (selection is code; wording is the model) | Wording yes |
| 3 | Team-info: no portfolio / finance / meeting doors; lead restates three block values | 0 | (f), (e) | ~0.45 | high (config) for the doors | Restating: yes |
| 4 | Board: names in two columns, no "our board", record chips for 5 of 12 unions | 9 | (c), (f) | ~0.35 | high for the name column | "our": yes |
| 5 | Tear-down retrieval misses art. 1063 and 1097; passage order depends on the QU search query | 7 | (b), (a) | ~0.25 | medium | Search query: yes |
| 6 | Roster: staff buried in an unordered sample | 1 | (c) | ~0.25 | high | No |
| 7 | Export sentence hedges ("with the available information") on complete files | 2, 4 | (e) code | ~0.20 | certain | No |
| 8 | Meetings: agenda half unanswered | 10 | (e), config | ~0.15 | medium | No |
| 9 | Clarify: "balance of an invoice"; reply chips send a reading without its object | 5 | (a), (f) | ~0.15 | medium | Which kinds: yes |
| 10 | Tear-down composition: art. 1100 applied to an interior wall, majorities vague, art. 1044 unused, long | 7 | (d) | ~0.40 | low | **Pure variance** |
| 11 | Buyer: 15-day protection of art. 1069 dropped | 8 | (d) | ~0.35 | low | **Pure variance** |
| 12 | Voice: "you owe … your suppliers" | 12 | (d) | ~0.15 | low | **Pure variance** |

## Most structural fix per defect

**1. The lead with nothing to say (turns 9, 1, 3, 6, 11).** The p18 fix moved the fixed content into code and kept the sentence. For a list question the sentence then has no content of its own.
- Let the lead be empty. Tell the model: "if the system line and the table already answer the question, write only {{table}}", and accept that in `finalize()`.
- Enforce "ONE sentence" in code: cut everything after the first sentence of the lead when a facts line was rendered (two of six leads broke the instruction).
- When the question asks about a field the facts line reports as not recorded (QU `property_concepts: ["president"]` against `not_recorded: Board role`), render no model lead at all: the code line is the answer, as the prompt already says.
- For homonyms, give the model the plural ("the three units named 310") or drop the lead; "Unit 310 … across all associated unions" is what "do not restate it" produced.

**2. Follow-up excerpt selection (turns 7, 8).**
- Offer the unused paragraphs of a cited article: split the article on its paragraphs and keep those whose words the answer does not carry (the writer's own `excerptStating` word check, reversed). Buyer then gets "What if the syndicate does not send the statement within 15 days?" from art. 1069, the door the standard names.
- When a passage is cited without an article number, treat only its `article_no` (the passage's lead article, 1045 here) as covered, not every article of the chunk. Tear-down then gets art. 1044.
- Keep the fractional article number (1068.1) in `follow_ups.article`.

**3. Team-info doors (turn 0) — unchanged since p17.** Config only: a `related` placement for Meetings, Units, Receivables report and Payables when the single row is the user's own team.

**4. Board (turn 9).** Compose one name column from the entity's `name_properties` in the table renderer, as the chips already do. Spread the record chips over unions or replace them with one chip per union.

**5. Tear-down retrieval (turn 7) — unchanged since p17.** A passage beyond the first needs two vote groups or a question-family angle (the rule now applied to freed slots only); index per article with the heading path as prefix. The search query should not be able to reorder the passages on its own.

**6. Roster order (turn 1).** The companion distribution of the coded column, already listed as "later": a facts line ("1 Lead union manager, 2 Union managers, … 517 Unit Owners or Renters") and the rarest value first.

**7. Export hedge (turns 2, 4).** When `export_source_complete` is true, use a lang string without "with the available information".

**8. Meetings agenda (turn 10).** Add to the fallback note the asked concepts the fallback rows do not carry (QU `property_concepts`), or project the number of agenda items in the `upcoming_meetings` source.

**9. Clarify (turn 5).** Have each reply chip send a full sentence built from the reading ("Show me the outstanding balance of one unit"), and rank readings that match a host calculation or a report placement ahead of plain record kinds.

## Losses not fixable without a stronger composition model or a change to the standard

- **Tear-down, the interior-partition distinction and the misuse of art. 1100 (~0.40).** Five graded runs, 0.30 to 0.50, the last three on the same instructions. Retrieving art. 1063 should lift the ceiling to about 0.55-0.60. A reliable pass needs a stronger composer, or a standard that accepts "walls separating portions are presumed common; inside your portion you are freer; ask the syndicate".
- **Buyer, the 15-day rule (~0.35).** Used once in six runs on equivalent prompts. Fix 2 can put it behind a chip; it cannot put it in the answer.
- **Voice slips** ("you owe … your suppliers") on passing turns, 0.05-0.15 each.

Defects 1 to 4 and 6 to 9 need no model change and explain about 2.60 of the 4.40 points still missing.

## Minor defects on turns at or above 0.85

- Facts lines "523 members." and "4 units." are bare fragments between the lead and the table.
- Excel follow-up still pays a QG call (7,264 tokens) for an identical cypher because QU relabels the subject.
- Supplier: Oldest Due mixes datetimes and a date.
- QU rewrites in schema words still reach QG.

---

# 5 — TOKENS AND LATENCY VS p18

Tokens, summed per turn:

| Stage | p18 | p19 | Delta |
|---|---|---|---|
| understanding (13 calls) | 65,495 | 65,499 | +4 |
| query_generation (10 -> 9 calls) | 69,480 | 62,599 | -6,881 |
| composition (10 -> 11 calls) | 32,236 | 39,003 | +6,767 |
| search_probes (2 calls) | 1,690 | 1,685 | -5 |
| clarity_check (1 call) | 1,867 | 1,171 | -696 |
| **follow_ups (2 calls, new, gpt-4o)** | — | 2,117 | +2,117 |
| **Total (36 -> 38 calls)** | **170,768** | **172,074** | **+1,306 (+0.8%)** |

- Input 165,587 -> 166,525; output 5,181 -> 5,549.
- QG: PDF -8,146 (generation skipped); board +668 and meetings +555 (QU tiers, variance); Excel +73; the other six within +/-30.
- Composition: tear-down +6,581 (the citation retry, a second call); the other nine within +/-90.
- follow_ups: 1,111 (tear-down) and 1,006 (buyer); input 2,015, output 102.
- Without the tear-down retry the run would be about 165,500 tokens (-3.1%).

Latency:

| Stage | p18 sum / mean | p19 sum / mean |
|---|---|---|
| understanding | 77.8 s / 5.98 s (max 7.0 s) | 78.7 s / 6.05 s (max 6.9 s) |
| file_context (2 knowledge turns) | 10.0 s (5.16 s, 4.83 s) | 9.6 s (5.21 s, 4.41 s) |
| of which probe call | 4.6 s | 5.2 s |
| query_generation | 33.1 s / 3.31 s over 10 (one 11.5 s call) | 22.0 s / 2.44 s over 9 (max 3.5 s) |
| execution | 1.5 s / 0.15 s over 10 | 1.6 s / 0.18 s over 9 |
| composition (10 LLM-composed turns) | 18.2 s / 1.82 s | 22.3 s / 2.23 s (tear-down 7.24 s; 1.68 s without it) |
| **follow_ups (2 turns, new)** | — | 3.16 s / 1.58 s (1.65 s, 1.52 s) |
| clarity_check | 1.75 s | 1.76 s |
| recorded_result_export | — | 0 ms |
| empty_result_check | 0.06 s | 0.05 s |
| **pipeline** | **142.8 s / 11.0 s, median 10.4 s, max 19.3 s** | **139.6 s / 10.7 s, median 9.9 s, max 20.2 s** |

- The follow-up call adds about 1.6 s to each knowledge turn: buyer 12.8 s -> 15.0 s, tear-down 14.5 s -> 20.2 s (of which 4.3 s is the citation retry). The knowledge turns are now the two slowest.
- PDF turn 7.8 s -> 6.1 s.
- Understanding is 56% of pipeline time (p18 54%).
