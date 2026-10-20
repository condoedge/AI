# Grade: p17-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p17-1001-A.json`, generated 2026-10-06T16:50. ai `badd2f1` + 545 dirty files. config_sha1 `29053c6d…` (p16: `09c0aa90…`).
- **Models:** unchanged (QU gpt-4o-mini, QG gpt-4o, composition gpt-4o-mini). New stages: `clarity_check`, `clarify`, `empty_result_check`.
- **Harness:** 13/13 `ok`, 21/21 gates passed, 0 INFRA/VOID (summed from the rows; the top-level counters cover one chunk).
- **Rubric/strictness:** identical to p14/p16. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action = code-attached chips plus doors named in the text.
- **Not verified:** nothing was executed against the graph. Single run, so "variance" verdicts rest on prompt diffs against p16, not on repeats.

---

# 1 — GRADING

## Headline

- **Answer mean 0.765** (p16 0.646, +0.119). **Action mean 0.796** (p16 0.758, +0.038).
- **Counts 10 pass / 3 partial / 0 fail** (p16 7 / 3 / 3). All three p16 fails are gone: ambiguous t0 and meetings now pass, tear-down is back to partial.
- Four fixes carry the gain: clarity check (+0.45), meetings fallback (+0.50), Team overview (+0.40), probe fusion (+0.20).
- Two small answer regressions (roster -0.05, supplier -0.05). No action regression.
- The three partials are the two knowledge turns and the board turn.

## Per-turn table

| # | Turn | p14 ans / act | p16 ans / act | p17 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.45 / 0.70 | 0.40 / 0.70 | **0.80 / 0.70** | pass (+0.40 / =) | Cypher now returns `team_name, team_type, count(DISTINCT u) AS members, contact_email, contact_phone`. Answer: "Novo Gestion … a management company with 523 members", own email marked. All required facts present. Deductions: the two-sentence lead repeats all five values that the code-rendered record block lists right below; "(that's you)" appears three times (the self-reference patcher added "(that's you — your own contact details)" to a sentence that already said "Your own contact email"). Chips: My profile, Team settings. Still no portfolio / finances / meetings doors. |
| 1 | roster t0 | 0.80 / 0.60 | 0.80 / 0.45 | **0.75 / 0.60** | pass (-0.05 / +0.15) | Role labels shown ("Lead union manager", "Accountant", "Administrative assistant", "Unit Owner or Renter"). Team settings chip is back. No text after the table. **New: a 10-row bullet list repeats the table** before it, against "Do NOT list the rows". The total is still only in the system line and still "523 rows". No export chip. The query has no ORDER BY: the Excel of the next turn shows 6 staff (1 lead, 2 manager, 1 accountant, 1 assistant, 1 concierge); only 3 are in the 25 rows shown. |
| 2 | roster t1 Excel | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | `previous_result`, `export_source_complete:true`, 523 rows, 523 distinct emails, header Name / Email / Role. Same hedge "with the available information". New inconsistency, not scored: the file holds raw codes (`lead`, `contact`) while the screen now shows labels. |
| 3 | 310 t0 | 0.80 / 0.95 | 0.85 / 0.95 | **0.85 / 0.95** | pass (=) | Canonical cypher, 3 rows, union column, $0.00 / 0 open invoices each. No merged total, "Oldest Due" hidden, no closer. But a numbered list repeats the table, and the lead says "Unit 310 has three different records", not three units. Chips: 3 "Unit 310 — <union>" plus Receivables report. |
| 4 | 310 t1 PDF | 0.90 / 0.85 | 0.90 / 0.85 | **0.90 / 0.85** | pass (=) | `previous_result`, 3 rows, union column kept. Persisting: raw `0` money cells in the PDF; a QG call (8,156 tokens) for an empty cypher. |
| 5 | ambiguous t0 | 0.25 / 0.40 | 0.25 / 0.40 | **0.70 / 0.65** | **pass (+0.45 / +0.25)** | Branch `clarify`; no query ran. Answer in full: "Which balance of an account, invoice, bill, or transaction do you mean?" One crisp question, as the standard requires. Readings are weak: no unit, no fund; "balance of a transaction" is not a plausible reading. The understanding stage had already matched the calculation `unit_outstanding_balance`, and it is not offered. No chips for the readings. |
| 6 | ambiguous t1 | 0.85 / 0.95 | 0.80 / 0.95 | **0.85 / 0.95** | pass (+0.05 / =) | Resolves to the three unit-310 rows, canonical cypher. No list, no merged total. Wording leaks column headers: "3 different records … each associated with a different Union Name. The table shows that…". |
| 7 | tear-down t0 | 0.40 / 0.55 | 0.30 / 0.55 | **0.50 / 0.55** | partial (+0.20 / =) | Art. 1042-1047 is back as passage [1]. Art. 1045 is paraphrased with its qualifier, and art. 1100 is now conditional ("if the wall is between two private portions"). Still wrong in direction: opens with "You cannot unilaterally tear down the wall", which no passage supports for a wall inside one unit. Bearing walls (art. 1044, in passage [1]) are not mentioned; the 3/4-90% majority (art. 1098, in [2]) becomes "possibly a majority vote". Art. 1063 and 1097 were not retrieved. No article number invented. No chip, no path to deepen. |
| 8 | buyer-arrears t0 | 0.60 / 0.70 | 0.60 / 0.70 | **0.60 / 0.70** | partial (=) | Art. 1069 named, liability grounded in [1]. The statement is again only advice ("to understand any outstanding amounts"). The 15-day protection is in passage [1] and unused. No chip. |
| 9 | board t0 | 0.50 / 0.70 | 0.50 / 0.70 | **0.55 / 0.75** | partial (+0.05 / +0.05) | No longer one board; "Bm Role" hidden; roles "not recorded" said plainly. But the lead is system wording ("The rows cover 12 different Union Names"), the next sentence says board information "is not explicitly detailed in the data provided" above a table of 25 board members, and a generic closer sits before the table ("please let me know!"). Chips: 5 full-name chips over 5 unions, Download Excel. |
| 10 | board t1 meetings | 0.40 / 0.90 | 0.35 / 0.90 | **0.85 / 0.95** | **pass (+0.50 / +0.05)** | "There are currently no upcoming meetings scheduled for your team. Here are the most recent meetings that have taken place:" plus 3 named, dated meetings with their union. The agenda half of the question is not addressed. Chips: 3 meeting records plus Meetings. |
| 11 | owner-arrears t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | Same cypher, 4 units largest first, $1,211.48. Still "4 owners" for 4 units / 5 owners. The closer after the table is gone. |
| 12 | supplier t0 | 0.85 / 0.90 | 0.90 / 0.90 | **0.85 / 0.90** | pass (-0.05 / =) | $11,493.17, 4 open bills, four figures exact. Voice slipped to "You currently owe … your suppliers"; "amounts owed by each supplier" has the direction reversed. Chip: Payables. |

## Means

| Run | Answer, all 13 | Action, all 13 | Answer, 11-turn subset | Action, 11-turn subset |
|---|---|---|---|---|
| p14 | 0.658 | 0.769 | 0.623 | 0.745 |
| p16 | 0.646 | 0.758 | 0.605 | 0.732 |
| **p17** | **0.765** | **0.796** | **0.750** | **0.777** |

- Answer sum 9.95 (p16 8.40). Action sum 10.35 (p16 9.85).
- The 11-turn subset excludes owner-arrears and supplier, as in p16.

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p14 | 7 | 5 | 1 |
| p16 | 7 | 3 | 3 |
| **p17** | **10** | **3** | **0** |

- **Pass:** team-info, roster, Excel, 310 t0, PDF, ambiguous t0, ambiguous t1, meetings, owner-arrears, supplier.
- **Partial:** tear-down, buyer, board.

---

# 2 — FIX VERIFICATION

| Fix | Status | Evidence |
|---|---|---|
| Ambiguous t0: one clarifying question | **Works; readings weak** | Stages `clarity_check` {ask:true, reason:"ambiguous", readings:4} (1.85 s, 1,726 tokens), `clarify` {candidate_readings:4, fallback_question:false}; branch `clarify`; no QG, no execution. Readings: "the balance of an account / an invoice / a bill / a transaction". The standard's unit and fund readings are absent. |
| Ambiguous t1 resolves | **Verified** | 3 rows (units 159, 301, 472), canonical `unit_outstanding_balance` cypher, union column, gate `disambiguation_column_present` passes. |
| Tear-down: 1042-1047 back | **Verified** | Chunk 38 is passage [1] (score 0.46). `angle_chunks`: search_query -> [38], clarified_question -> [38], probe_2 (en) -> [38, 113, 37]. |
| Tear-down: question angle keeps a slot | **Did not operate** | The question angle returned no passage (`angle_chunks[1] = []`), so `question_passages` is absent from the stage. It did operate on buyer (`question_passages: 66`). |
| Full heading path | **Verified** | "§MODALITIES OF OWNERSHIP › CHAPTER III — DIVIDED CO-OWNERSHIP OF IMMOVABLES › DIVISION II — FRACTIONS OF CO-OWNERSHIP — art. 1042–1047". On buyer the indivision passage is now labelled "CHAPTER II — UNDIVIDED CO-OWNERSHIP" and the answer does not use it. |
| 5 passages per file | **Verified; 2 of 5 are noise on tear-down** | Tear-down: chunks 38, 113, 61, 82 (art. 1075.1-1079, insurer subrogation), 89 (art. 1082-1084, emphyteusis). Buyer: 66, 60, 11, 108, 83. |
| Near-duplicate probes dropped | **Verified on buyer, none dropped on tear-down** | Buyer `probes_dropped`: 3 of 4 (similarity 0.861 to the question, 0.889 to another probe). Tear-down kept all 4. |
| Meetings fallback | **Verified** | Stage `empty_result_check` {scope:"team", fallback_source:"upcoming_meetings", fallback_rows:3, executions:2}; branch `data`. Prompt: "FALLBACK ROWS … Your FIRST sentence must say plainly that there is none of what was asked". The fallback runs through the secured executor (`EmptyResultInspector::sourceFallback` unsets `skip_security`). The stage does not record `security_filter_applied` for the fallback execution. |
| Team overview | **Verified** | Row: `team_type: 2`, `members: 523`; prompt shows "Management company"; answer states both. Record block rendered by code (`record_rendered: true`). |
| Roster: Team settings chip | **Verified** | `attached_actions: [{role: identity, key: team_settings}]`. QU entity types `[Team, User, TeamRole]`. |
| Roster: roles as labels | **Verified on screen, not in the export** | Screen and prompt: "Lead union manager", "Unit Owner or Renter". Excel payload: `lead`, `contact`. `CodedColumns::labelled` is called only from `DataAnswerPlan.php:555`. |
| Roster: answer ends at the table | **Verified, with a side effect** | Nothing follows the table but the system line. The padding moved in front of the table (10-row bullet list). |
| Board: GROUPS line, not one board | **Verified in substance** | Prompt: "GROUPS: The 37 rows span 12 different Union Name: 825 de Bruxelles (5); … They are NOT one group". Answer: "The rows cover 12 different Union Names." |
| Board: full-name chips over unions | **Verified** | "Amélie Quirion — 825 de Bruxelles", "Julien Ménard — SDC 2205 Chemin d'oka", "Bertrand Bossé — SDC Carré Soho Phase 2", "Nicole De Grandmont — SDC Carré Soho Phase 3", "Catherine Westgate — SDC Les Terrasses 7250 St-Hubert". |
| Board: empty role column hidden, "not recorded" said | **Verified** | `empty_columns_hidden: ["Bm Role"]`; "the specific roles of the board members, including who is the president, are not recorded". |
| Board: no generic closer | **Not met** | "If you need further details or specific names, please let me know!" sits before `{{table}}`, where the tail-drop does not reach. |
| 310: lead says three units, table carries figures, no merged total | **Partly** | No merged total on either turn; COMPUTED TOTALS block gone from the prompt; "Oldest Due" hidden. t0 still lists the three rows in prose and says "records". t1 is clean. |
| QU possessive | **Verified, 13/13 right** | personal: turns 0, 1, 7 (`["my"]`). collective: turns 9, 10 (`["our"]`), 12 (`["we","our"]`). None: turns 2, 3, 4, 5, 6, 8, 11. "Show me the balance." is no longer possessive. `possessive_forms` is not in the trace. |
| AccountBalance not selectable | **Verified** | In no QU entity list, no selected-entity list, no QU or QG prompt, and not among the clarity-check kinds. |
| Security | **No false rejection** | 13/13 `security_gate` passed; `safe_stop:false` on all 10 executions; `security_filter_applied:true` on the 9 that ran a cypher (false on the PDF turn, empty cypher). No SecurityException. |
| Rate-limit admission | **Not triggered** | No `admission` stage and no busy answer on any turn. |

Possessive note: on turn 8 ("If I buy a unit, am I responsible…") QU quoted no word, while on turn 12 it quoted the subject "we". The outcome is right on both; whether a subject "I" gets quoted looks run-dependent.

---

# 3 — REGRESSIONS VS p16

| Turn | Delta | Cause | Evidence |
|---|---|---|---|
| roster, answer | -0.05 | **Code change, probable** (instruction reworded), with model variance on top | p16 ANSWER FORMAT: "a direct answer first, then the placeholder {{table}} on its own line exactly where the table belongs, then at most one short closing next step." p17 (`DataAnswerPlan.php:1368`): "a direct answer first **and anything else you have to say**, then the placeholder {{table}} … as the LAST line". The p16 answer had one sentence before the table; p17 has a 10-row list plus "You are listed as the Lead union manager." "Do NOT list the rows" is in both prompts. |
| supplier, answer | -0.05 | **Model variance** | Insider-voice block identical in both prompts. The diff is the format line above, an added "- Email: [email#1]" line and the placeholder note (QU now marks the turn collective, which pulls User/Team into the selection). p14 made the same "you owe" slip on a p16-equivalent prompt. |

The same reworded line is the likely source of pre-table padding elsewhere in p17:

| Turn | Text before the table in p17 | p16 |
|---|---|---|
| 310 t0 | numbered list of the 3 rows + "All records show…" | one lead sentence, closer after the table |
| board | "If you need further details or specific names, please let me know!" | closer after the table |
| owner-arrears | "Here are the details of the owners and their outstanding balances:" | closer after the table |
| supplier | "Here's a breakdown of the amounts owed by each supplier:" | closer after the table |

The closers that p16 put after the table are gone on all 8 table turns. Part of that text moved in front of the placeholder, where `finalize()` (`DataAnswerPlan.php:382-406`) keeps everything.

Not regressions:
- Board lost "+32 more -> Contacts" by design (BoardMember has no list page of its own).
- Buyer's third passage is the indivision chapter again (chunk 11), now labelled as such and unused.

---

# 4 — REMAINING DEFECT RANKING

Scope: turns below 0.85 on answer or action — 0, 1, 5, 7, 8, 9. Stage letters: (a) understanding, (b) retrieval, (c) query generation, (d) data in the prompt but ignored, (e) a composition instruction, (f) action attachment.

## Ranked table

Points = answer plus action shortfall the defect explains.

| Rank | Defect | Turn(s) | Stage | Points | Fix determinism | Model variance? |
|---|---|---|---|---|---|---|
| 1 | Knowledge turns can carry no door | 7, 8 | (f), (e) | ~0.75 | high | No — by construction |
| 2 | Truncated roster: count optional and called "rows", no export chip, staff buried, rows re-listed | 1 | (e), (f), (c), (d) | ~0.65 | high | Re-listing yes; the rest no |
| 3 | Clarify readings come from record kinds only; no reading chips | 5 | (a), (f) | ~0.65 | high | Which kinds are picked: yes |
| 4 | Board lead in system words, self-contradiction, closer, split names | 9 | (e), (d) | ~0.55 | medium-high | Contradiction and closer yes |
| 5 | Team-info: no portfolio / finance / meeting doors; lead repeats the record block | 0 | (f), (e) | ~0.50 | high (doors), medium (lead) | Lead yes |
| 6 | Tear-down retrieval misses art. 1063 and 1097; 2 of 5 passages are single-probe noise | 7 | (b) | ~0.30 | medium | Probe wording yes |
| 7 | Table-mode lead is unbounded ("anything else you have to say") | 1, 3, 9, 11, 12 | (e) | ~0.25 | high | Triggered by the instruction |
| 8 | Buyer: second paragraph of art. 1069 dropped | 8 | (d) | ~0.35 | medium-low | **Pure variance** |
| 9 | Tear-down: art. 1044 and 1098 in the prompt, unused; unsupported "cannot unilaterally" | 7 | (d) | ~0.20 | low | **Pure variance** |

## Per-defect trace and fix

### 1. Knowledge turns can carry no door (turns 7, 8)

- **Standard demands:** "a clear path to deepen (e.g. 'ask about the approval process')"; "Opens the door to follow-ups (how to request the statement)".
- **Where lost:** (f) `ActionAttacher::nextSteps` (`src/Services/Actions/Attach/ActionAttacher.php:583-588`) returns `[]` when no rows came back and documents ground the turn. (e) The knowledge guideline forbids the text door too: "Do NOT end with an offer or a question ("let me know", "would you like…", "feel free to ask"), and do not mention links, buttons or attachments."
- **Fix:** a follow-up chip role for knowledge turns. The composer returns up to two follow-up questions in a structured field; code keeps one only if it cites a retrieved passage number, and renders it as a chip that sends that question. Tear-down had [2] (majorities) and buyer had [1] (the statement) available as targets. Then switch the knowledge guideline to the data variant's wording, which already allows naming an attached step.

### 2. Roster (turn 1)

- **Standard demands:** "(a) states the explicit total member count, (b) discloses 'N more' … (c) offers a table/export door"; "Accept a staff-focused roster OR a representative sample".
- **(e) Count is optional:** "do NOT state how many rows are shown — if you give a count, the full count is 523" (`DataAnswerPlan.php:1402-1408`). A first-sentence instruction exists only for grand totals or groups (`:1378-1398`). The system line is "Showing :shown of :total rows" (`resources/lang/en.json:204`).
- **(d) Ignored:** "Do NOT list the rows and do NOT write your own table".
- **(c) No ordering:** `… RETURN u.name AS name, u.email AS email, tr.role AS role LIMIT 100`. Three of six staff are in the 25 rows shown.
- **(f) Export chip withheld** on a capped retrieval (`ActionAttacher.php:661-670`), while the next turn exports 523/523.
- **Fix:**
  - Truncated table -> the first-sentence instruction always carries the full count with the subject's display noun; the lang line takes the same noun ("25 of 523 members").
  - Companion group-count query for a low-cardinality coded column when the result is truncated, as the true-total companion already does. It yields a FACTS line ("1 Lead union manager, 2 Union managers, … 517 Unit Owners or Renters") and an ordering, rarest value first.
  - Export chip in re-run mode, the mode the export turn already uses.
  - Apply `CodedColumns::labelled` in the export composer so the file matches the screen.

### 3. Clarify readings (turn 5)

- **Standard demands:** "a short clarifying question offering the plausible readings" — "(a unit's fees? the union's accounts? a fund?)".
- **(a) Where lost:** `ClarityChecker::kinds()` (`src/Services/Understanding/ClarityChecker.php:273-289`) gives the model record kinds and synonyms only. The prompt asks for "every kind from the list whose records can have what is asked", without properties, so "transaction" qualifies and "unit" does not. The same turn's understanding stage reports `matched_calculations: ["unit_outstanding_balance"]`.
- **(f)** `attached_actions: null`; the four readings exist in `response_metadata.clarification.candidate_readings` and are not rendered as choices.
- **Fix:**
  - Seed the readings from the calculations the selector matched, using their configured labels (`CondoEdge/config/ai.php:267-291`: "Outstanding balance of one unit", "Total owed to the union/team", "Total owed TO suppliers"). Zero extra LLM calls.
  - Limit the remaining kinds to those with a `report` placement or a property matching the asked concept (Fund and Account reports are placed at `config/ai.php:1314-1325`).
  - Render each reading as a chip that sends it as the reply.

### 4. Board (turn 9)

- **Standard demands:** group per union, state the total, insider voice ("our board"); a grounded list.
- **(e) Lead copied from the instruction:** "Your FIRST sentence states that the rows cover 12 different Union Name (see GROUPS above), then answers the question." (`DataAnswerPlan.php:1386, 1396`). The answer: "The rows cover 12 different Union Names."
- **(d) Ignored:** "Present them as that answer; never say this information could not be found." The NOT RECORDED line ("say plainly in your answer that it is not recorded") was generalized to the whole board.
- **(e) Closer before the table:** see defect 7.
- **(c)** Cypher still returns `c.first_name, c.last_name, cur.bm_role, cur.year` unaliased -> two name columns.
- **Fix:**
  - Word the instruction in the user's terms: subject label plus the parent entity's display plural ("the current board members of 12 different unions, 37 people in all"), never "rows" or a column header.
  - NOT RECORDED: add that every other column is recorded and is the answer.
  - Table renderer: compose one name column from the entity's `name_properties`, as the chips already do.
  - Cut the display at a group boundary and name how many groups are not shown (4 unions here).

### 5. Team-info (turn 0)

- **Standard demands:** doors to "members, portfolio/unions, finances, meetings"; "Concise, no padding".
- **(f)** One own-team row gets identity chips only. The ONE RECORD branch (`ActionAttacher.php:626-635`) attaches the record's other read actions, and Team has none.
- **(e)** "Write ONLY the lead: one or two sentences that answer the question, any value copied verbatim from the row above" lets the lead restate all five fields the record block lists.
- **Fix:**
  - Config: a `related` placement on existing generic actions for Team (Meetings, Units, Receivables report, Payables), attached when the single row is the user's current team (`CondoEdge/config/ai.php:1073-1383`).
  - Lead instruction for the record layout: name the record and what it is; leave values to the block.
  - Skip the narrative self-reference patch when the record block carries the marker.

### 6. Tear-down retrieval (turn 7)

- **Standard demands:** interior partition vs bearing/common walls; who approves what; majorities when common portions or the destination are affected.
- **(b) Retrieved:** art. 1042-1047, 1098-1102, 1064-1068, 1075.1-1079, 1082-1084. **Missing:** art. 1063 (chunk 60, art. 1063-1067: free use of the private portion) and art. 1097 (chunk 108, art. 1092-1097: majority for work on common portions). Chunk 61 was retrieved and starts one article after 1063.
- **Why:**
  - The user's question clears no passage at `min_relevance_score` 0.4; search_query and clarified_question clear only chunk 38.
  - probe_1 asserts an answer ("Co-owners must obtain the syndicate of co-owners' authorization before making an alteration of portions in their unit") and alone supplies chunks 61, 82 and 89 (`angle_chunks[3]`, `[4]`).
  - probe_2 (fr) returned nothing.
- **Fix:**
  - A passage beyond the first needs support from two vote groups or from a question-family angle; otherwise leave the slot empty (`AiManager::mergeFileAngleResults`, called at `AiManager.php:1182`).
  - Index per article with the heading path as prefix, and expand to neighbours at render time. Windows of 5-6 overlapping articles dilute art. 1063 and 1045.
  - Probe slot 2 should state what the thing is ("a partition inside a private portion"), not a second rule.

### 7. Unbounded table-mode lead (turns 1, 3, 9, 11, 12)

- **(e)** `DataAnswerPlan.php:1368`: "Write ONLY the narrative: a direct answer first and anything else you have to say". The record layout at `:1358` already says "Write ONLY the lead: one or two sentences".
- **Fix:** use the record wording in table mode, and enforce it in `finalize()` by structure: drop list and table blocks from the lead when the table is rendered, and keep at most the first two or three sentences. No vocabulary involved.
- This also removes the remaining 310 t0 list. The HOMONYMS line still opens with "report each one separately with its Union Name" (`:1166`) before "do not list them again in prose" (`:1172`); drop the first clause in table mode.

### 8. Buyer, second paragraph of art. 1069 (turn 8) — pure variance

- **(d)** Passage [1] holds: "in such a case, the prospective acquirer is bound to pay the common expenses only if the statement is provided to him by the syndicate within 15 days of the request." The answer cites art. 1069 and uses its first paragraph only. Same in p13, p14, p16, p17.
- **Guard:** after composition, for each article the answer names, compare each paragraph of that article with the answer by embedding; retry once quoting an uncovered paragraph. The validation/retry slot exists (`response_metadata.validation`).

### 9. Tear-down composition (turn 7) — pure variance

- **(d)** Art. 1044 ("foundations and main walls of buildings" presumed common) and art. 1098 (three-quarters / 90%) are in the prompt and unused. "You cannot unilaterally tear down the wall" has no passage behind it; the guideline "state only what the retrieved file content actually supports" was not followed.
- **Guard:** an uncited-claim check on knowledge turns (a sentence with a normative claim and no passage number triggers the existing retry). Lower priority than defect 6, which changes what the model is given.

## Minor defects on turns at or above 0.85

- Excel: raw role codes in the file; "with the available information" hedge.
- PDF: raw `0` money cells; a QG call for an empty cypher.
- 310 turns: "records" and "Union Name" in the lead.
- Meetings: agenda not addressed; the prompt still carries 10 unrelated board rows as "Previous Query Results Sample".
- Owner-arrears: "4 owners" for 4 units / 5 owners.
- Supplier: "you owe"; "owed by each supplier"; Oldest Due mixes datetimes and a date.
- QU rewrites in schema words ("Which board member entities exist in the graph…", "Which user entities are associated with my team…"); these reach QG as the clarified intent.

---

# 5 — TOKENS AND LATENCY VS p16

Tokens, summed per turn:

| Stage | p16 | p17 | Delta |
|---|---|---|---|
| understanding (13 calls) | 65,835 | 65,459 | -376 |
| query_generation | 79,501 (11 calls) | 72,282 (10 calls) | -7,219 |
| composition | 28,993 (11 calls) | 31,995 (10 calls) | +3,002 |
| search_probes (2 calls) | 1,667 | 1,666 | -1 |
| clarity_check (1 call) | — | 1,726 | +1,726 |
| **Total** | **175,996 (37 calls)** | **173,128 (36 calls)** | **-2,868 (-1.6%)** |

- Ambiguous t0 costs 6,657 tokens instead of 14,463: no QG and no composition call.
- Knowledge composition grew with 5 passages: tear-down 4,368 -> 5,501, buyer 4,332 -> 5,976.
- QU is flat: the clarify keys removed and the possessive fields added cancel out.

Latency:

| Stage | p16 sum / mean | p17 sum / mean |
|---|---|---|
| understanding | 83.2 s / 6.40 s | 85.2 s / 6.55 s (max 11.5 s, turn 0) |
| file_context (2 knowledge turns) | 16.4 s (8.28 s, 8.11 s) | 9.8 s (5.21 s, 4.63 s) |
| of which probe call | 6.2 s | 4.8 s |
| query_generation | 28.4 s / 2.58 s | 24.7 s / 2.47 s |
| execution | 6.6 s / 0.66 s | 4.5 s / 0.50 s (roster 3.6 s) |
| composition | 26.2 s / 2.01 s | 22.3 s / 1.86 s |
| clarity_check | — | 1.85 s (1 turn) |
| empty_result_check | — | 0.22 s (1 turn, 2 executions) |
| **pipeline** | **160.8 s / 12.4 s, median 11.5 s, max 22.4 s** | **148.7 s / 11.4 s, median 10.6 s, max 17.8 s** |

- Understanding is 57% of pipeline time and is unchanged.
- The slowest turn is now team-info (17.8 s), on an 11.5 s understanding call; tear-down fell from 22.4 s to 15.8 s.
