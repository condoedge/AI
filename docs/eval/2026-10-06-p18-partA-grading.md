# Grade: p18-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p18-1001-A.json`, chunk 1 run at 2026-10-06T18:38-04:00. ai `badd2f1` + 559 dirty files (p17: 545). config_sha1 `4aae3535…` (p17: `29053c6d…`).
- **Models:** unchanged (QU gpt-4o-mini, QG gpt-4o, composition gpt-4o-mini).
- **Harness (summed from the rows):** 13/13 `ok`, 21/21 gates passed, 0 INFRA/VOID. The top-level counters (`total_rows: 7`, `usage_total` 88,208) describe one chunk only.
- **Rubric / strictness:** identical to p14/p16/p17. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action = code-attached chips plus doors named in the text.
- **Not verified:** nothing was executed against the graph, no phpunit, no artisan. Single run: every "variance" verdict rests on a prompt diff against p17, not on repeats. The root cause given for the "rows" showing-line was read from code and config, not run.

---

# 1 — GRADING

## Headline

- **Answer mean 0.788** (p17 0.765, +0.023). **Action mean 0.796** (p17 0.796, unchanged: the attached chips are identical on all 13 turns).
- **Counts 11 pass / 2 partial / 0 fail** (p17 10 / 3 / 0). Buyer-arrears moves from partial to pass.
- Gains: buyer +0.15, team-info +0.05, roster +0.10, ambiguous t0 +0.10, 310 t0 +0.05.
- Losses: tear-down -0.10 and meetings -0.05, both model variance (prompt diffs in section 3).
- Tear-down sits exactly on the partial/fail boundary (0.40).

## Per-turn table

| # | Turn | p16 ans / act | p17 ans / act | p18 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.40 / 0.70 | 0.80 / 0.70 | **0.85 / 0.70** | pass (+0.05 / =) | Lead is one sentence: "Your team is called Novo Gestion, and it is a management company with 523 members." Record block follows; "(that's you)" appears once (p17: three times). The lead still restates 3 of the 5 block values against "do NOT restate their values in your sentence". Chips unchanged: My profile, Team settings. Still no portfolio / finances / meetings doors. |
| 1 | roster t0 | 0.80 / 0.45 | 0.75 / 0.60 | **0.85 / 0.60** | pass (+0.10 / =) | "There are 523 members in your team, and here are their roles:" then the table. Total stated with its noun, no bullet echo (composition output 137 -> 18 tokens), role labels, "(that's you)". Remaining: the system line still reads "Showing 25 of 523 **rows**"; no ORDER BY, so 3 of the 6 staff are in the 25 rows shown; no export chip (Team settings only). |
| 2 | roster t1 Excel | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | `previous_result`, complete, 523 rows, 523 distinct emails, headers Name / Email / Role. The file now carries role labels (was raw codes; not scored in p17, so not scored here). Same hedge "with the available information". |
| 3 | 310 t0 | 0.85 / 0.95 | 0.85 / 0.95 | **0.90 / 0.95** | pass (+0.05 / =) | Two-sentence lead, no numbered list, no merged total, three union rows at $0.00 / 0 open invoices. Still "three different **records**", not three units. Chips: 3 "Unit 310 — <union>" plus Receivables report. |
| 4 | 310 t1 PDF | 0.90 / 0.85 | 0.90 / 0.85 | **0.90 / 0.85** | pass (=) | `previous_result`, 3 rows, union column kept. Persisting: raw `0` money cells in the payload; a QG call (8,146 tokens) for an empty cypher. |
| 5 | ambiguous t0 | 0.25 / 0.40 | 0.70 / 0.65 | **0.80 / 0.65** | pass (+0.10 / =) | Branch `clarify`, one question: "Which one is meant: outstanding balance of one unit, balance of an account, balance of a union, or balance of a contact unit?" The unit reading is now offered first. "balance of a contact unit" is a link-table kind, not a user reading; no fund reading; "Which one is meant:" is stiff. No chips for the readings. |
| 6 | ambiguous t1 | 0.80 / 0.95 | 0.85 / 0.95 | **0.85 / 0.95** | pass (=) | Three unit-310 rows, canonical cypher, no merged total. The header leak of p17 is gone, but the lead is three sentences, says "record(s)" three times and ends with the filler "Here are the details for each record:". |
| 7 | tear-down t0 | 0.30 / 0.55 | 0.50 / 0.55 | **0.40 / 0.55** | partial (-0.10 / =) | Art. 1042-1047 is still passage [1]. The answer drops art. 1045's condition in its first bullet: "Walls that are not part of the foundations or main walls are generally considered common portions [1]" — false as a general rule and the opposite of the interior-partition distinction the standard asks for. Art. 1100 becomes "possibly other co-owners" and "may also require additional approvals" (the text says hypothecary creditors, and forbids the change in relative value). The 3/4-90% majority (art. 1098, in [2]) is gone entirely. No article number invented, document cited, "alteration" mapped. No chip, no path to deepen. |
| 8 | buyer-arrears t0 | 0.60 / 0.70 | 0.60 / 0.70 | **0.75 / 0.70** | **pass (+0.15 / =)** | Liability with interest grounded in [1]; the statement mechanism and its 15 days are now used. The paraphrase is loose: "you will only be responsible for the amounts listed in that statement" and "unless you obtain a statement indicating otherwise" are not what art. 1069 says (bound only if the statement is provided within 15 days). The article number is no longer named. No chip. |
| 9 | board t0 | 0.50 / 0.70 | 0.55 / 0.75 | **0.55 / 0.75** | partial (=) | Gains: total stated in the lead (37), closer gone, role column hidden. Losses: "There are 37 **records** covering 12 different **Union Names**" is the instruction read back; the next sentence, "The information about the board of directors and the president is not specified in the available data", sits above a table of 25 board members and is broader than p17's "roles … are not recorded". No "our board". Names still split in two columns. System line "25 of 37 rows". Chips: 5 full-name chips over 5 unions, Download Excel. |
| 10 | board t1 meetings | 0.35 / 0.90 | 0.85 / 0.95 | **0.80 / 0.95** | pass (-0.05 / =) | "There are currently no upcoming meetings scheduled for your **union**" above three meetings of three different unions (p17: "for your team"). Agenda half of the question still not addressed. Chips: 3 meeting records plus Meetings. |
| 11 | owner-arrears t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | Same cypher, 4 units largest first, $1,211.48. Still "4 owners" for 4 units / 5 owners; second lead sentence is filler. |
| 12 | supplier t0 | 0.90 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | $11,493.17, 4 open bills, four figures exact. One-sentence lead; the reversed "owed by each supplier" line of p17 is gone. Voice still "You currently owe … your suppliers" (same slip scored 0.85 in p14). Chip: Payables. |

## Means

| Run | Answer, all 13 | Action, all 13 | Answer sum | Action sum |
|---|---|---|---|---|
| p16 | 0.646 | 0.758 | 8.40 | 9.85 |
| p17 | 0.765 | 0.796 | 9.95 | 10.35 |
| **p18** | **0.788** | **0.796** | **10.25** | **10.35** |

11-turn subset (without owner-arrears and supplier, as in p16/p17): answer 0.777 (p17 0.750), action 0.777 (p17 0.777).

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p16 | 7 | 3 | 3 |
| p17 | 10 | 3 | 0 |
| **p18** | **11** | **2** | **0** |

- **Pass:** team-info, roster, Excel, 310 t0, PDF, ambiguous t0, ambiguous t1, buyer, meetings, owner-arrears, supplier.
- **Partial:** tear-down (0.40), board (0.55).

---

# 2 — FIX VERIFICATION

| Fix | Status | Evidence |
|---|---|---|
| Table lead limited to one or two sentences | **Works by instruction; not enforced** | New line on all 8 table turns: "Write ONLY the lead — one or two sentences that answer the question…" (`DataAnswerPlan.php:1683`). Composition output on the 8 table turns: 506 -> 259 tokens. Leads: 1 sentence on turns 0, 1, 12; 2 on 3, 9, 10, 11; **3 on turn 6** ("Here are the details for each record:"). Nothing in `finalize()` cuts a third sentence. |
| Row-echo blocks dropped before a code table | **Not exercised** | `response_metadata.row_echo_dropped` is absent on all 13 turns (it is written only when > 0, `ResponseGenerator.php:287`). The model wrote no list on any turn, so `NarrativeRowEcho::strip` (`DataAnswerPlan.php:470`) had nothing to drop. The p17 echoes (roster bullets, 310 numbered list) are gone at the source. |
| Record-mode lead leaves the values to the block | **Mostly** | Prompt: "ONE sentence … leave every other value to the system … do NOT restate their values in your sentence" (`:1671-1676`). Answer: one sentence, contact email and phone no longer repeated, but name, type and member count still are. `record_rendered: true`, `self_reference_guard: "table"`, no patch. |
| Truncated list states the true total with the record kind | **Roster yes, board no** | Roster prompt: "Your FIRST sentence states the number of records (523 in all) … Name what is counted in the question's own words — never as \"rows\"" -> "There are 523 members in your team". The noun is the model's, not the host's: the prompt said "records", so the kind was null. Board, same instruction -> "There are 37 records". |
| Showing-line names the kind | **NOT operating in English** | Both truncated turns end "Showing 25 of 523 rows" / "Showing 25 of 37 rows". Cause, read from code: `DataAnswerPlan::recordKind()` (`:789-816`) returns the host plural only when the translator has a different string for it in the answer's language, **or** the answer's language equals `app.fallback_locale`. Host: `'display_name_plural' => 'members'` on User only (`CondoEdge/config/ai-entities/general.php:1311`); `fr.json` has `"members": "membres"`, `en.json` has no `members` key; `CondoEdge/config/app.php:96` sets `fallback_locale` to `fr`. So an English answer gets null and the neutral line. The unit test passes because it sets `app.fallback_locale => 'en'` (`DataAnswerPlanLeadTest.php:559`). A French roster would show "membres". Board has no kind in any language: no other entity declares a plural. |
| Header derives from the property when the alias shares no word with it | **Not exercised** | Cypher is byte-identical to p17 on 12 of 13 turns; every alias matches its property (`u.email AS email`, `t.contact_email AS contact_email`). Board still returns `c.first_name, c.last_name` unaliased. |
| Export files carry role labels in the answer's language | **Verified on Excel; PDF has no coded column** | Excel `export_document_rows`: "Unit Owner or Renter" 517, "Union manager" 2, "Lead union manager" 1, "Accountant" 1, "Administrative assistant" 1, "Concierge" 1 (p17: `contact` 517, `manager` 2, `lead`, …). New `export_document_headers` {name: Name, email: Email, role: Role}; the `columns_include` gate reads them. PDF: headers Union Name … Invoices Due, 3 rows, money cells still raw `0`. |
| Branch coherence (no indivision passage) | **Verified on buyer** | `file_context.branch_coherence`: kept "CHAPTER III — DIVIDED CO-OWNERSHIP OF IMMOVABLES" (weight 3.22), dropped "CHAPTER II — UNDIVIDED CO-OWNERSHIP" (0.78), chunks 11 and 14 removed. Passages now 66, 60, 83, 72, 108 (p17: 66, 60, **11**, 108, 83). Not triggered on tear-down (no indivision chunk in any angle). |
| "ALREADY COVERED / NEW" block on follow-up knowledge turns | **Not exercised** | Part A has no knowledge follow-up; the string is in no prompt. Code present at `ResponseSections/FileContextSection.php:145`. |
| Tear-down still retrieves 1042-1047 | **Verified** | Chunk 38 is passage [1] (score 0.44). Final passages 38, 113, 61, 82, 89 — the same five as p17. Art. 1063 (chunk 60) and 1097 (chunk 108) are still missing; 82 and 89 are still noise. `probes_dropped`: 2 of 4 (similarity 0.814), 0 in p17. |
| Clarity readings seeded from the host's named calculations | **Verified; model-written sentence** | Clarity prompt now ends "Figures the application can compute: Q1: Outstanding balance of one unit — What a specific unit still owes." Readings: "outstanding balance of one unit", "balance of an account", "balance of a union", "balance of a contact unit". Stage `clarify` {candidate_readings: 4, **fallback_question: false**}: the answer is the model's own `question` field, not the bulleted lang-key fallback (`AiManager.php:2434-2439`). The `unit` kind is left out of the kinds list because the calculation covers it; the model then picked "contact unit". |
| Digest framing not given the shown count | **Part B only** | — |
| Pruner: ownership tier only for personal possession | **Verified** | Board (collective "our"): tiers {BoardMember: seed, Union: tenant, User: identity, Team: identity}; p17 also had Contact: ownership. Tear-down (personal "my"): Contact and ContactUnit kept as ownership. QG input on board 6,236 -> 5,898 tokens. Side effect to watch: the Contact schema card is no longer in the board QG prompt, yet the query needs `c.first_name` / `c.last_name`; it came out identical this run. |
| QU possessive | **12 of 13 (p17 13 of 13)** | Turn 10 "When is **our** next meeting…": `is_possessive: false`, `possessive_words: []`, `clarified_question: ""` (p17: true, ["our"], collective). The QU prompt differs from p17 only in the previous-answer summary line. Outcome unaffected (same fallback, same chips). |
| Security / admission | **Clean** | 13/13 `security_gate` passed; `safe_stop: false` on all 10 executions; `security_filter_applied: true` on the 9 that ran a cypher (false on the PDF turn, empty cypher). No SecurityException, no `admission` stage, no busy answer, no errors, no fallbacks. |

---

# 3 — REGRESSIONS VS p17

| Turn | Delta | Cause | Evidence |
|---|---|---|---|
| tear-down, answer | -0.10 | **Pure model variance** | The composition prompt is the same 17,055 characters in both runs; the only differing lines are the five "relevance: NN%" figures. Same five passages in the same order, same guidelines. p17 kept art. 1045's condition and made art. 1100 conditional; p18 dropped both. This turn has now scored 0.40, 0.30, 0.50, 0.40 over p14-p18. |
| meetings, answer | -0.05 | **Model variance** | "your union" instead of "your team". Composition prompt diff vs p17: the two format lines (new lead wording), and one "- Email: [email#1]" line gone. The insider-voice block and "Current team name: Novo Gestion" are unchanged. The email line went because QU missed "our" this run (see section 2), which is itself variance. |

No action regression: `attached_actions` is identical to p17 on all 13 turns.

Not regressions, but new observations:
- **Buyer +0.15 cannot be credited with certainty to branch coherence.** Passage [1] and every instruction are unchanged; only passage [3] changed (indivision art. 1017-1022 -> art. 1070-1072). The second paragraph of art. 1069 was dropped in p13, p14, p16 and p17 on equivalent prompts.
- Board lead: p17 "The rows cover 12 different Union Names" -> p18 "There are 37 records covering 12 different Union Names". Code change (`DataAnswerPlan.php:1706, 1709`): the count joined the sentence and the model copied the instruction's own nouns.
- Roster QG latency 2.0 s -> 11.5 s on an identical prompt size (5,467 input tokens): provider latency.

---

# 4 — REMAINING DEFECT RANKING

Scope: turns below 0.85 on answer or action — 0, 1, 5, 7, 8, 9, 10. Stage letters: (a) understanding, (b) retrieval, (c) query generation, (d) data in the prompt but ignored, (e) a composition instruction, (f) action attachment.

## Ranked table

Points = answer plus action shortfall the defect explains.

| Rank | Defect | Turn(s) | Stage | Points | Fix determinism | Model variance? |
|---|---|---|---|---|---|---|
| 1 | Knowledge turns can carry no door | 7, 8 | (f), (e) | ~0.75 | high | No — by construction |
| 2 | Fixed-content lead sentences are asked of the model and come back in instruction words (board count / groups / not-recorded) | 9 (also 3, 6) | (e), (d) | ~0.50 | high if code-rendered | Contradiction yes; wording no |
| 3 | Truncated roster: no export chip, staff buried | 1 | (f), (c) | ~0.45 | high | No |
| 4 | Clarify: link-table kind offered as a reading, no fund, no reading chips | 5 | (a), (f) | ~0.50 | high (chips), medium (readings) | Which kinds: yes |
| 5 | Team-info: no portfolio / finance / meeting doors | 0 | (f) | ~0.30 | high (config only) | No |
| 6 | Record kind is dropped in English ("rows", "records") | 1, 9 | (e) code | ~0.15 | certain | No |
| 7 | Tear-down retrieval misses art. 1063 and 1097; 2 of 5 passages are single-probe noise | 7 | (b) | ~0.25 | medium | Probe wording yes |
| 8 | Meetings: agenda half unanswered | 10 | (e), config | ~0.15 | medium | "your union": yes |
| 9 | Tear-down composition: art. 1045 condition dropped, 1100 distorted, 1098 unused | 7 | (d) | ~0.35 | low | **Pure variance** |
| 10 | Buyer: loose paraphrase of the 15-day rule | 8 | (d) | ~0.25 | low | **Pure variance** |

## Per-defect trace and fix

### 1. Knowledge turns can carry no door (turns 7, 8) — unchanged since p17

- **Standard demands:** "a clear path to deepen (e.g. 'ask about the approval process')"; "Opens the door to follow-ups (how to request the statement)".
- **(f)** `ActionAttacher::nextSteps` returns `[]` when no rows came back and documents ground the turn (`src/Services/Actions/Attach/ActionAttacher.php:583-588`).
- **(e)** The prompt forbids the text door as well: "Do NOT end with an offer or a question ("let me know", "would you like…", "feel free to ask"), and do not mention links, buttons or attachments." (`src/Services/ResponseSections/GuidelinesSection.php:207`) and "never mention links, buttons or attachments (neither that some are attached nor that none are)".
- **Fix:** a follow-up chip role for knowledge turns. The composer returns up to two follow-up questions in a structured field; code keeps one only if it cites a retrieved passage number and renders it as a chip that sends that question. Buyer has [1] (the statement request), tear-down has [2] (majorities). Then use the data variant of the guideline (`:206`), which allows naming an attached step.

### 2. Lead sentences the model only reads back (turn 9; wording on 3, 6)

- **Standard demands:** group per union, state the total, president "if recorded", insider voice ("our board").
- **(e) Instruction, quoted:** "Your FIRST sentence states the number of records (37 in all) and that the rows cover 12 different Union Name (see GROUPS above), then answers the question. Name what is counted in the question's own words — never as "rows"." (`DataAnswerPlan.php:1706, 1709, 1719-1722`). Answer: "There are 37 records covering 12 different Union Names."
- **(d) Ignored:** "NOT RECORDED: Bm Role is empty on all 37 rows … say plainly in your answer that it is not recorded" (`:1604`) became "The information about the board of directors and the president is not specified in the available data", against the guideline "never say this information could not be found".
- **Same mechanism on 310:** "Your FIRST sentence must say that there are 3 different Unit Name '310' records (one per Union Name)" (`:1467`) -> "Unit 310 has three different records".
- **(c)** `RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year` -> two name columns.
- **Most structural fix:** stop asking the model for sentences whose content is fixed. Render them in code, as the showing-line already is: a count-and-groups line ("37 board members across 12 unions"), a not-recorded line ("Role is not recorded for these board members"), a homonym line ("3 different units are named 310, one per union"). The model keeps one sentence: the answer. This needs host nouns: `display_name_plural` on BoardMember, Union, Unit, Meeting, Contact, Bill, Invoice in `CondoEdge/config/ai-entities/*.php`, and defect 6 fixed.
- Also: compose one name column from the entity's `name_properties` in the table renderer, as the chips already do.

### 3. Roster (turn 1)

- **Standard demands:** "(c) offers a table/export door for the full list"; "Accept a staff-focused roster OR a representative sample".
- **(f)** Export chip withheld on a capped retrieval by design (`ActionAttacher.php:661-672`: "A capped retrieval (100 of 523) would download a silently partial file"), while the next turn exports 523 / 523 by re-running without the display limit (`AiManager.php:3673, 3807`).
- **(c)** `… RETURN u.name AS name, u.email AS email, tr.role AS role LIMIT 100` has no ORDER BY; 3 of 6 staff are in the 25 rows shown.
- **Fix:** offer the Excel chip in re-run mode when the true total is known. For a truncated result with a low-cardinality coded column, run a companion group count (as the true-total companion already does): it yields a FACTS line ("1 Lead union manager, 2 Union managers, … 517 Unit Owners or Renters") and an ordering, rarest value first.

### 4. Clarify readings (turn 5)

- **Standard demands:** "a short clarifying question offering the plausible readings" — "(a unit's fees? the union's accounts? a fund?)".
- **(a)** `ClarityChecker::kinds()` (`src/Services/Understanding/ClarityChecker.php:351-367`) lists every record kind, including link tables. With `unit` removed as covered by the calculation (`covered()`, `:681`), the model's third candidate was "contact unit". Prompt: "\"candidates\": every kind from the list whose records can have what is asked".
- **(e)** "Which one is meant:" is the prompt's own phrase: "\"question\": ONE short clarifying question … and asks which one is meant."
- **(f)** `clarifyResponse` returns the readings in metadata only (`AiManager.php:2451-2473`); no invocations.
- **Fix:** exclude kinds that have no list, view or report action (pivots such as ContactUnit, detail rows) from the clarity list; prefer kinds with a `report` placement (Fund, Account). Render each reading as a chip that sends it as the reply. Reword the `question` instruction around the asked word ("Which balance do you mean: …?").

### 5. Team-info doors (turn 0) — unchanged since p17

- **Standard demands:** doors to "members, portfolio/unions, finances, meetings".
- **(f)** One own-team row gets identity chips only; the ONE RECORD branch (`ActionAttacher.php:626-635`) attaches the record's other read actions and Team has none.
- **Fix:** config only. A `related` placement on existing generic actions for Team (Meetings, Units, Receivables report, Payables) in `CondoEdge/config/ai.php`, attached when the single row is the user's current team.

### 6. Record kind dropped in English (turns 1, 9)

- **(e) code:** `DataAnswerPlan::recordKind()` `:803-813` treats host literals as written in `app.fallback_locale` (`fr` in CondoEdge), so the English literal `'members'` is refused for an English answer.
- **Fix:** let the host declare the plural per language (`'display_name_plural' => ['en' => 'members', 'fr' => 'membres']`), or test whether the translator has the key instead of comparing its output to the input. Add a test with `app.fallback_locale => 'fr'` and an English answer. This is the prerequisite for defect 2.

### 7. Tear-down retrieval (turn 7) — unchanged since p17

- **Standard demands:** interior partition vs bearing / common walls; who approves what; majorities when common portions or the destination are affected.
- **(b) Retrieved:** art. 1042-1047, 1098-1102, 1064-1068, 1075.1-1079, 1082-1084. **Missing:** art. 1063 (chunk 60: free use of the private portion) and art. 1097 (chunk 108: majority for work on common portions). Chunk 60 scored 0.515 on the buyer turn, where it is irrelevant.
- **Why:** the user's question clears no passage (`angle_chunks[1] = []`); search_query returns only chunk 38; probe_1 (fr and en), which asserts an answer ("The co-owner must obtain the syndicate of co-owners' authorization before making any alteration…"), alone supplies chunks 82 and 89.
- **Fix:** a passage beyond the first needs support from two angle groups or from a question-family angle, else the slot stays empty (`AiManager::mergeFileAngleResults`, `AiManager.php:1941`). Index per article with the heading path as prefix and expand to neighbours at render time. Probe slot 2 should name what the thing is ("a partition inside a private portion"), not a second rule.

### 8. Meetings agenda (turn 10)

- **Standard demands:** "next upcoming meeting with date and agenda motions if present; if none scheduled, an honest 'none upcoming' with the most recent past meeting offered as fallback."
- **(e)** The FALLBACK ROWS note covers the first half only: "Your FIRST sentence must say plainly that there is none of what was asked…". Nothing tells the model that the agenda part has no rows either.
- **Fix:** have the `upcoming_meetings` fallback source (CondoEdge config) project the number of agenda items per meeting, or add to the note the asked concepts the fallback rows do not carry (QU `property_concepts: ["agenda"]`). "your union" is variance.

### 9. Tear-down composition (turn 7) — pure variance

- **(d)** Art. 1045 in the prompt: "Partitions or walls that are not part of the foundations and main walls of a building **but which separate a private portion from a common portion or from another private portion** are presumed common." The answer keeps the condition in its first paragraph and drops it in the bullet. Art. 1098 (three-quarters / 90%) is in passage [2] and unused.
- **Guard, if wanted:** an uncited-claim / article-coverage check on knowledge turns using the existing retry slot (`response_metadata.validation`). Lower priority than defect 7, which changes what the model is given.

### 10. Buyer paraphrase (turn 8) — pure variance

- **(d)** Passage [1]: "the prospective acquirer is bound to pay the common expenses only if the statement is provided to him by the syndicate within 15 days of the request." Answer: "you will only be responsible for the amounts listed in that statement".

## Losses I judge NOT fixable without a stronger composition model or a change to the standard

- **Tear-down, the interior-partition distinction (~0.35).** The law states it by omission: a wall that does not separate two portions is simply not covered by art. 1045, so it follows the private portion (art. 1063). gpt-4o-mini has reversed or blurred this in all four graded runs (p14, p16, p17, p18), the last two on the same prompt. Retrieving art. 1063 (defect 7) should lift the ceiling to roughly 0.55-0.60; a reliable pass needs a stronger composer, or a standard that accepts "walls separating portions are presumed common; ask the syndicate" as sufficient.
- **Buyer, the exact 15-day rule (~0.15-0.25).** Present this run, absent in four earlier ones, loose when present. A coverage guard can force the paragraph in; it cannot make the paraphrase exact.
- **Voice and noun slips on passing turns** ("You currently owe … your suppliers", "4 owners" for 4 units, "your union", the team-info lead restating three values). The governing lines are in the prompt and ignored intermittently; these cost 0.05-0.15 each and move between turns from run to run.

Everything else in the table is fixable in code or host config: defects 1-6 need no model change, and together explain about 2.65 of the 5.40 points still missing across both axes (answers 2.75, actions 2.65).

## Minor defects on turns at or above 0.85

- Excel: "with the available information" hedge.
- PDF: raw `0` money cells; a QG call for an empty cypher.
- 310 turns: "records"; third lead sentence on t1 (the one-or-two-sentence limit is an instruction only).
- Owner-arrears: "4 owners"; filler second sentence.
- Supplier: "You … your suppliers"; Oldest Due mixes datetimes and a date.
- QU rewrites in schema words ("Which board member entities exist in the graph…", "Which owner entities in the SDC Carré Soho Phase 3 union…") still reach QG.
- Pruner: the Contact card left the board QG prompt although the query reads Contact properties.

---

# 5 — TOKENS AND LATENCY VS p17

Tokens, summed per turn:

| Stage | p17 | p18 | Delta |
|---|---|---|---|
| understanding (13 calls) | 65,459 | 65,495 | +36 |
| query_generation (10 calls) | 72,282 | 69,480 | -2,802 |
| composition (10 calls) | 31,995 | 32,236 | +241 |
| search_probes (2 calls) | 1,666 | 1,690 | +24 |
| clarity_check (1 call) | 1,726 | 1,867 | +141 |
| **Total (36 calls)** | **173,128** | **170,768** | **-2,360 (-1.4%)** |

- Input 167,779 -> 165,587; output 5,349 -> 5,181.
- QG: meetings -1,998 (QU missed "our", so no identity or ownership tier: variance, not the pruner); team-info -355 (QU no longer extracts "information" as a requested attribute); board -338 (pruner, Contact card gone); ambiguous t1 -212; the other six within +/-50.
- Composition: buyer +302 (art. 1070-1072 is longer than the indivision passage it replaced). Output on the 8 table turns 506 -> 259 tokens.
- Clarity check +141: the "Figures the application can compute" block and the `reworded` field.

Latency:

| Stage | p17 sum / mean | p18 sum / mean |
|---|---|---|
| understanding | 85.2 s / 6.55 s (max 11.5 s) | 77.8 s / 5.98 s (max 7.0 s) |
| file_context (2 knowledge turns) | 9.8 s (5.21 s, 4.63 s) | 10.0 s (5.16 s, 4.83 s) |
| of which probe call | 4.8 s | 4.6 s |
| query_generation (10 turns) | 24.7 s / 2.47 s | 33.1 s / 3.31 s (roster 11.5 s; 2.41 s without it) |
| execution (10 turns) | 4.5 s / 0.45 s (roster 3.6 s) | 1.5 s / 0.15 s (roster 0.63 s) |
| composition (10 LLM-composed turns) | 21.7 s / 2.17 s | 18.2 s / 1.82 s |
| clarity_check | 1.85 s | 1.75 s |
| empty_result_check | 0.22 s | 0.06 s |
| **pipeline** | **148.7 s / 11.4 s, median 10.6 s, max 17.8 s** | **142.8 s / 11.0 s, median 10.4 s, max 19.3 s** |

- Understanding is 54% of pipeline time (p17 57%).
- The slowest turn is now the roster (19.3 s) on one 11.5 s QG call; its prompt size is unchanged.
- Composition is 16% faster on the composed turns, in line with the shorter leads.
