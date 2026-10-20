# Grade: p16-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p16-1001-A.json`, generated 2026-10-06T14:12. ai `badd2f1` + 530 dirty files. config_sha1 `09c0aa90…` (p14: `e3b8b25b…`).
- **Models:** unchanged (QU gpt-4o-mini, QG gpt-4o, composition gpt-4o-mini). New stage `search_probes` runs on the QU model.
- **Harness:** 13/13 `ok`, 0 gate failures, 0 INFRA/VOID. All 13 turns graded.
- **Rubric/strictness:** identical to p14. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action = code-attached chips plus doors named in the text.
- **Not verified:** nothing was executed against the graph. Dataset facts come from the suite standards and from `CondoEdge/config/ai.php` comments (e.g. team 3 has 50 meetings, the latest on 2023-10-24).

---

# PART 1 — GRADING

## Headline

- **Answer mean 0.646** (p14 0.658, -0.012). **Action mean 0.758** (p14 0.769, -0.011).
- **Counts 7 pass / 3 partial / 3 fail** (p14 7 / 5 / 1). Two partials dropped to fail: tear-down (0.40 -> 0.30) and meetings (0.40 -> 0.35).
- **One real code-caused regression on answers:** tear-down. The new probe angles pushed the definitional passage (art. 1042-1047, the one holding art. 1045 on partition walls) out of the three retrieved passages. The answer is now built on art. 1100, which does not apply to a wall inside one unit.
- **One code-caused regression on actions:** roster. It now has no chip at all (p14 had Team settings).
- Everything else that moved is composition variance on prompts that are byte-identical or differ only by a label.
- **Security:** all 13 `security_gate` passed, no `safe_stop`, no SecurityException, `security_filter_applied:true` on every executed cypher (false only on the PDF turn, which ran no query). No false rejections.

## Per-turn table

| # | Turn | p14 ans / act | p16 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|
| 0 | team-info t0 | 0.45 / 0.70 | **0.40 / 0.70** | partial (-0.05) | Same cypher as p13/p14 (name, contact_email, contact_phone, team_address, created_at, updated_at). Still no team type and no member count. The bullet list repeating the table row is back (it was gone in p14). "Team address is not recorded" is stated and the empty Team Address column is still rendered. `(that's your own email)` is correct. Composition prompt identical to p14 except the date. Chips: My profile, Team settings. |
| 1 | roster t0 | 0.80 / 0.60 | **0.80 / 0.45** | pass (= / -0.15) | New cypher (`User.current_team_id = 3` + OPTIONAL TeamRole), true_total 523. The table now carries name, email and role, and the three staff rows come first. The count is still only in the system line and still called "rows". **No chip at all**: QU entity types are now `[TeamRole, User]` (p14 `[Team, TeamRole]`), so the Team settings identity chip is not attached. The only door is the text "ask for the full list or an export". |
| 2 | roster t1 Excel | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | `u.name AS name, u.email AS email, tr.role AS role`. Export from `previous_result`, `export_source_complete:true`, 523/523 rows, 523 distinct emails. Header Name, Email, Role. Same hedge "with the available information". Chip: Download Excel. |
| 3 | 310 t0 | 0.80 / 0.95 | **0.85 / 0.95** | pass (+0.05) | Canonical cypher, 3 rows, union column, all $0.00 with 0 open invoices. The numbered list and the "just let me know" of p14 are gone. Remaining: "3 different records" wording, merged totals in the lead (prompt-mandated), "through the links below". Prompt byte-identical to p14. Chips: 3 "Unit 310 — <union>" plus Receivables report. |
| 4 | 310 t1 PDF | 0.90 / 0.85 | **0.90 / 0.85** | pass (=) | `previous_result`, 3 rows, union column kept, "No values found for: Oldest Due". Persisting: raw `0` money cells in the PDF, and a QG call (8,194 tokens) for an empty cypher. |
| 5 | ambiguous t0 | 0.25 / 0.40 | **0.25 / 0.40** | fail (=) | QU: `needs_clarification:false`, `entity_types:[]`, `is_possessive:true`, clarified to "What is the current balance associated with my account?". QG now writes a query (p14 `needs_query:false`): the personal-ownership path `Contact {user_id: 13}` -> 0 rows. Answer: "no records matching that information are linked to you". True, and "I checked" is now accurate, but it is still not the clarifying question the standard requires. No chip. |
| 6 | ambiguous t1 | 0.85 / 0.95 | **0.80 / 0.95** | pass (-0.05) | Same data. The numbered list that repeats the table is back (in p14 it was on 310 t0 instead). "Here are the details for each Union". The prompt differs from p14 only by the label and Current Focus. |
| 7 | tear-down t0 | 0.40 / 0.55 | **0.30 / 0.55** | **fail (-0.10)** | Retrieved passages: art. 1098-1102, 1064-1068, 1076-1081. **Art. 1042-1047 (p14's top passage) is gone.** The answer's core claim is art. 1100: "co-owners of contiguous private portions can alter the boundaries … consent from their hypothecary creditors and the syndicate". That article covers the boundary between two co-owners' units, not a wall inside one unit. Point 3 (common or structural wall -> "additional approvals may be required") has no citation. No majorities, no path to deepen. No invented article number. |
| 8 | buyer-arrears t0 | 0.60 / 0.70 | **0.60 / 0.70** | partial (=) | Art. 1069 named, liability grounded in [1]. The statement is presented only as a way to "inform you of any outstanding fees". The protective effect (bound only if the statement is provided within 15 days) is in the prompt and is not used. |
| 9 | board t0 | 0.50 / 0.70 | **0.50 / 0.70** | partial (=) | Same cypher minus `un.id AS union_id`, 37 rows, union column, "Showing 25 of 37". "Your board of directors" (singular) over at least 8 unions. "Bm Role" header over an all-empty column. Closes with "feel free to check the details". Chips: 5 Contact chips labelled by first name only (Amélie, Mauricio, Denis, Cédric, Hélène — all one union), "+32 more" -> Contacts, Download Excel. The p14 union chips are gone as intended. |
| 10 | board t1 meetings | 0.40 / 0.90 | **0.35 / 0.90** | **fail (-0.05)** | Cypher is team-scoped and future-only (`m.start_date > toString(date())`), with agenda items collected. 0 rows is a genuine "nothing upcoming". Answer: "I couldn't find any linked records related to meetings or schedules for your union." That denies meetings exist at all (p14 at least said "upcoming" and "existing meetings"). No past-meeting fallback. Chip: Meetings. |
| 11 | owner-arrears t0 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | Same cypher, 4 units largest first, $1,211.48, owners, date-only Oldest Due. Still "4 owners" for 4 units / 5 owners. Closing says "below". Chips: 4 units plus Receivables report. |
| 12 | supplier t0 | 0.85 / 0.90 | **0.90 / 0.90** | pass (+0.05) | "We currently owe our suppliers a total of $11,493.17, with 4 open bills." Insider voice is right this run. Four figures exact. Oldest Due still mixes datetimes and a date. Chip: Payables. |

## Means

| Run | Answer, all 13 | Action, all 13 | Answer, p11's 11 turns | Action, 11 turns |
|---|---|---|---|---|
| p14 | 0.658 | 0.769 | 0.623 | 0.745 |
| **p16** | **0.646** | **0.758** | **0.605** | **0.732** |

- Answer sum 8.40 (p14 8.55). Action sum 9.85 (p14 10.00).
- The 11-turn subset excludes owner-arrears and supplier. That exclusion is inferred from p14's sums (1.70 answers, 1.80 actions); the p14 document does not name the two turns.

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p14, all 13 | 7 | 5 | 1 |
| **p16, all 13** | **7** | **3** | **3** |

- **Pass:** roster, Excel, 310 t0, PDF, ambiguous t1, owner-arrears, supplier.
- **Partial:** team-info, buyer, board.
- **Fail:** ambiguous t0, tear-down, meetings.

## Movements vs p14, with traced cause

| Turn | Delta | Cause | Evidence |
|---|---|---|---|
| tear-down, answer | -0.10 | **Code change** (DocumentProbeWriter + probes replacing two angles) | p14 angles: search query, question, clarified question, joined tags -> chunks 38 (art. 1042-1047), 61 (1064-1068), 113 (1098-1102) plus a help doc. p16 angles: search query, question and four probes -> chunks 113, 61, 84 (1076-1081). All four probes state the same hypothesis ("requires the authorization of the syndicate … declaration of co-ownership"), so four of six rank-fusion votes go to the same passages. |
| roster, action | -0.15 | **Code/config change** (synonym normalization), probable | The User aliases are now "team members, member, staff, team users, users of my team…" and Team lost "team, teams". QU now returns `[TeamRole, User]`; the identity chip needs `Team` in QU's entity types (`ActionAttacher::identityChips`, line 372). Single run, so QU variance cannot be fully excluded. |
| team-info, answer | -0.05 | Composition variance | Prompt identical to p14 except the date line. The bullet list violates "Do NOT list the rows". |
| ambiguous t1, answer | -0.05 | Composition variance | Prompt differs only by the subject label and Current Focus. |
| meetings, answer | -0.05 | Composition variance | Prompt differs only by the previous-turn sample (no `union_id`). |
| 310 t0, answer | +0.05 | Composition variance | Prompt byte-identical to p14. |
| supplier, answer | +0.05 | Composition variance | Prompt differs only by the "These rows answer" label. |

Not regressions:
- Ambiguous t0 took a different path (a query ran) with the same outcome.
- Board lost the union chips by design.
- The buyer turn's third passage changed from art. 1017-1022 (undivided co-ownership) to 1076-1080 (divided). Both are irrelevant to the question.

## Change verification

| Change | Status | Evidence |
|---|---|---|
| Synonym normalization | **Works; one side effect** | QU prompt 25.5k -> 22.6k chars. Aliases are real synonyms now ("Union (aka: condo, condominium, condo association, HOA, …)"), self/plural forms removed. Understanding tokens -10.8%. Ambiguous t0 no longer picks the bogus `AccountBalance` type. Side effect: roster loses its Team settings chip. |
| 5 newly placed generic actions | **Not exercised in Part A** | Chips seen (Meetings, Contacts, Receivables report, Payables, Download Excel) all existed in p14. |
| Union per-record chips removed | **Verified** | Board: 5 union chips in p14 -> 0 in p16; the cypher no longer returns `union_id`. Unit chips keep their union disambiguator ("Unit 310 — 825 de Bruxelles"). |
| DocumentProbeWriter | **Runs; mixed result** | Stage `search_probes` on both knowledge turns: 4 probes each, 3.19 s and 2.97 s, 841 and 826 tokens. Buyer: third passage moved to the divided co-ownership chapter. Tear-down: lost art. 1042-1047, never reached art. 1063 or 1097. |
| Probe key docs removed from the QU prompt | **Verified** | No `corpus_translations` / `register_expansions` text in the QU prompt; both come back empty in QU output. |
| Citation normalization | **Not exercised** | Both knowledge answers use plain "[1]"; no annotated citation appears. |
| Rate limiter | **No visible effect** | No rate-limit event, error or rejection in any stage; nothing in the trace records the charge. |
| Navigation guard | **Holds** | `own_team` kept only on team-info (no property concepts); null on the other 12 turns. |
| COLUMN NAMES rule | **Obeyed on Excel, not on board** | Board still returns `c.first_name, c.last_name, cur.bm_role, cur.year` unaliased. |

## Tokens and latency vs p14

Tokens (summed per turn; the file's own `usage_total` of 95,858 covers only the last merged chunk):

| Stage | p14 | p16 | Delta |
|---|---|---|---|
| understanding (13 calls) | 73,772 | 65,835 | -7,937 (-10.8%) |
| query_generation (11 calls) | 81,627 | 79,501 | -2,126 |
| composition (11 calls) | 28,832 | 28,993 | +161 |
| search_probes (2 calls) | — | 1,667 | +1,667 |
| **Total** | **184,231 (35 calls)** | **175,996 (37 calls)** | **-8,235 (-4.5%)** |

Latency:

| Stage | p14 sum / mean | p16 sum / mean |
|---|---|---|
| understanding | 84.1 s / 6.47 s | 83.2 s / 6.40 s |
| file_context (2 knowledge turns) | 10.3 s (5.78 s, 4.55 s) | 16.4 s (8.28 s, 8.11 s) |
| query_generation | 28.8 s / 2.62 s | 28.4 s / 2.58 s |
| execution | 6.1 s / 0.56 s | 6.6 s / 0.60 s |
| composition | 25.1 s / 1.93 s | 26.2 s / 2.01 s |
| **pipeline** | **154.5 s / 11.9 s, median 11.7 s** | **160.8 s / 12.4 s, median 11.5 s** |

- The probe call adds about 3 s to each knowledge turn; file_context went from about 5 s to about 8 s.
- Tear-down is the slowest turn at 22.4 s (p14 14.4 s), with a 9.7 s understanding call on top.
- Data turns are flat.

---

# PART 2 — DEFECT RANKING

Scope: every turn below 0.85 on answers or actions — turns 0, 1, 5, 6, 7, 8, 9, 10. Stage letters: (a) understanding, (b) retrieval, (c) query generation, (d) data in the prompt but ignored, (e) a composition instruction, (f) action attachment.

## Per-turn trace

### Turn 5 — ambiguous t0 "Show me the balance." (0.25 / 0.40)

- **Standard demands:** one short clarifying question offering the plausible readings. **Got:** an honest empty for a reading the user never stated.
- **(a) Understanding — where it is lost.** QU output: `entity_types: []`, `property_concepts: ["balance"]`, `is_possessive: true`, `needs_clarification: false`, `candidate_readings: []`, clarified question "What is the current balance associated with my account?". The question contains no possessive; QU invented one.
  - QU instruction (QueryUnderstandingService `clarifyInstruction()`, lines 503-512): "False whenever context (the conversation block, the page, possessive phrasing) makes one reading clearly dominant … Prefer answering over asking: flag only genuine forks, never mild vagueness."
  - The navigation instruction in the same prompt uses "their balance" as its example of own data, which primes the personal reading.
- **(c) Query generation follows the invented reading.** QG receives `<clarified_intent>What is the current balance associated with my account?</clarified_intent>`, one matched calculation (`unit_outstanding_balance`) and the personal-ownership paths. Cypher: `MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL … RETURN un.name AS union_name, u.name AS unit_name, round(u.outstanding_balance, 2) AS balance …` -> 0 rows, because Julie owns no unit.
- **(e) Composition is told to abstain.** Empty-result prompt (`ResponseGenerator.php:908-927`): "WHAT WAS CHECKED: account balance — within the user's OWN linked records. State plainly what was checked and that nothing matched (e.g. that no such records are linked to them)".
- **(f)** Nothing attached; QU named no entity, so the empty branch has no list page to offer.
- **Variance:** none. Never clarified in p12, p13, p14 or p16. Deterministic.

### Turn 7 — tear-down (0.30 / 0.55)

- **Standard demands:** interior partition inside a private portion distinguished from bearing/common walls; who approves what (syndicate/board; co-owner majorities when common portions or the destination are affected); a path to deepen. **Got:** art. 1100 applied to the wrong situation, an uncited generic caveat, no majorities, no door.
- **(a) Understanding:** fine. `knowledge_query`, concepts `modification`, `wall`. (`Instruction` appears as an entity type via its new alias "règlement"; harmless here.)
- **(b) Retrieval — where it is lost.**
  - Returned: art. **1098, 1099, 1100, 1101, 1102** (score 0.71); **1064, 1064.1, 1065, 1066, 1067, 1068** (0.66); **1076.1, 1077, 1078, 1079, 1080, 1081** (0.55).
  - Needed: **1045** (partition walls presumed common when they separate a private portion from a common portion or another private portion), **1063** (free use of the private portion within the by-laws, others' rights and the destination), **1097** (majority for work on common portions), **1098** (destination) and 1056 for the declaration's limits. Only 1098 came back.
  - p14 had chunk 38 (art. 1042-1047) as its top passage.
  - The four probes (`file_context` stage, `angles`): "The alteration of a private portion requires the authorization of the syndicate of co-owners and must comply with established rules." / "The modification of a private portion, such as tearing down a wall, must adhere to the provisions of the declaration of co-ownership." and their French renderings. Probe 2 was meant to be "what that thing is, whose it is"; it is a restatement of probe 1 and it breaks the prompt's own rule against naming where a rule comes from.
  - The probes assert an answer the law does not contain for interior walls. They are four of six equal-weight votes in `AiManager::fuseAngleChunkRanks` (lines 1372-1390), and the file is capped at `max_chunks_per_file = 3`.
  - The trace has no per-angle rankings, so which angle carried chunk 38 in p14 cannot be shown.
- **(d)/(e) Composition.** Given only art. 1100 as a "consent" rule, the model applied it. The guideline "If the retrieved data cannot actually answer the question … say so plainly" (GuidelinesSection) was not followed. Art. 1098 was in the prompt and unused. Point 3 breaks "never answer such questions from general knowledge".
- **(e)/(f) No path to deepen, by construction.** Guideline: "Do NOT end with an offer or a question … and do not mention links, buttons or attachments." The Links section reads "Do not write URLs or action links, and never mention links, buttons or attachments". `ActionAttacher::nextSteps` returns `[]` whenever documents ground the turn (lines 451-455), so a knowledge turn can carry no chip.
- **Variance:** the probes run at temperature 0 but take QU's clarified question as input, which changes from run to run. Retrieval is therefore no longer stable for a fixed question.

### Turn 10 — meetings (0.35 / 0.90)

- **Standard demands:** honest "none upcoming" plus the most recent past meeting as fallback. **Got:** "couldn't find any linked records related to meetings or schedules for your union".
- **(a)** Fine: `[Meeting, Schedule]`, lookup, possessive.
- **(c) Query generation — half the loss.** `MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids AND m.start_date > toString(date()) WITH m ORDER BY m.start_date ASC LIMIT 1 OPTIONAL MATCH (s:Schedule)-[:BELONGS_TO_MEETING]->(m) RETURN m.name AS meeting_name, …`. Correct for "next", 0 rows. Nothing then looks for the most recent past meeting. The config already holds that query: `attention_sources.upcoming_meetings.fallback_cypher` with `fallback_label` "Most recent meetings — none scheduled" (`CondoEdge/config/ai.php:1457-1466`); only the digest path uses it.
- **(e) Composition instruction — the other half.** Empty-result prompt: "WHAT WAS CHECKED: next meeting agenda (record kinds: Meeting, Schedule) — within the user's OWN linked records. State plainly what was checked and that nothing matched (e.g. that no such records are linked to them)".
  - `checked_own_data` is QU's `is_possessive` (`AiManager.php:2677`), not the scope of the query that ran. The query was team-scoped.
  - The prompt never says the query was restricted to future dates, so the model cannot say "none upcoming" and falls back to "no records".
  - This instruction is also the source of the "linked records" jargon.
- **(f)** Meetings chip correct.
- **Variance:** the wrong scope and missing fallback are deterministic (p12-p16). How broadly the absence is worded varies per run.

### Turn 0 — team-info (0.40 / 0.70)

- **Standard demands:** name, that it is a management company, member count, contact info with that's-you handling; doors to members, portfolio, finances, meetings. **Got:** name and contact info, timestamps as padding, no type, no count.
- **(a)** `entity_types: [Team]`, `property_concepts: []`. Reasonable for a broad ask, but it means no REQUESTED ATTRIBUTES block reaches QG.
- **(c) Query generation — where it is lost.** `MATCH (t:Team) WHERE t.id = 3 RETURN t.id AS team_id, t.name AS team_name, t.contact_email …, t.team_address …, t.created_at …, t.updated_at … LIMIT 100`. The Team card in the same prompt lists `team_type:Integer{1=Self-managed|2=Manager|3=Ambassador}` and explains how to reach members ("match users whose current_team_id equals the team's id"). With nothing naming the useful columns, QG returns scalar properties and timestamps. Same cypher in p13, p14 and p16.
- **(d) Ignored instruction.** "Do NOT list the rows and do NOT write your own table" (Results block, ANSWER FORMAT). The answer bullet-lists the row. Variance: absent in p14 on an identical prompt.
- **(e)** The system renders the all-empty Team Address column while the prompt also says "NOT RECORDED: Team Address is empty on all 1 rows", so the answer states it and shows it.
- **(f)** Identity chips only. No next-step rule produces members/portfolio/finance doors for a single own-team row.

### Turn 9 — board (0.50 / 0.70)

- **Standard demands:** name the union or group per union; never one undifferentiated cross-union board; total and "N more"; never a truncation artifact. **Got:** a correct per-union table under the sentence "The members of your board of directors".
- **(a)** QU clarified to "the board members and the president of **my condo association**" — singular, for a lead whose team manages several.
- **(c)** Cypher is right in substance (latest year per union). It returns `c.first_name, c.last_name, cur.bm_role, cur.year` unaliased, against the COLUMN NAMES rule. That yields the header "Bm Role" and split name columns.
- **(e) Composition is never told how many boards there are.** The Results block has DATA SUMMARY (10 of 37 rows), "NOT RECORDED: Bm Role is empty on all 37 rows" and two HOMONYMS lines for the first names "Julien" and "Catherine". The homonym lines are noise produced by the split name. No line says the rows span N unions, so the model writes "your board".
- **Table rendering.** The empty "Bm Role" column is rendered. The 25-row cap cuts in the middle of a union.
- **(f)** Chips "Amélie; Mauricio; Denis; Cédric; Hélène": first name only, because `RecordResolver::nameOf` takes one name column; all five come from the first union.
- **Variance:** none in substance (same in p12-p16).

### Turn 8 — buyer arrears (0.60 / 0.70)

- **Standard demands:** the buyer can be held to the arrears, and the statement mechanism protects a buyer who requests it; door to "how to request the statement". **Got:** the liability, and the statement as plain advice.
- **(b) Retrieval is right.** Art. 1068.1, 1068.2, **1069**, 1070 as [1]; 1063-1067; 1076-1080.
- **(d) Data in the prompt, ignored.** Art. 1069, second paragraph, in passage [1]: "in such a case, the prospective acquirer is bound to pay the common expenses only if the statement is provided to him by the syndicate within 15 days of the request." The answer cites art. 1069 and uses only its first paragraph.
- **(e)/(f)** Same as tear-down: no chip is possible on a knowledge turn and offers are forbidden.
- **Variance: pure.** Same passage in p12, p13, p14 and p16; only p12 stated the 15 days.

### Turn 1 — roster (0.80 / 0.45)

- **Standard demands:** explicit total member count, "N more", and a table/export door. **Got:** "Showing 25 of 523 rows — ask for the full list or an export" and no chip.
- **(e)** ANSWER FORMAT: "do NOT state how many rows are shown — if you give a count, the full count is 523". The count is optional, so the narrative gives none. The system line (`ai.response.table_showing_of`, `ai/resources/lang/en.json:204`) says "rows", not members.
- **(f) Action attachment — where the action score is lost.**
  - Identity chip: needs `Team` in QU's entity types; QU now returns `[TeamRole, User]`.
  - List page: `team_settings` has no `entity`/`kind` placement in `CondoEdge/config/ai.php:1193`, so User and TeamRole have no list page.
  - Export chip: withheld by design when the retrieval is capped (`ActionAttacher::nextSteps`, lines 509-518: 100 of 523 retrieved). The very next turn exports all 523 rows through the export path, so a complete export is available; the chip just cannot reach it.

### Turn 6 — ambiguous t1 (0.80 / 0.95), and turn 3 by the same mechanism

- **Standard demands:** the three homonyms kept distinguishable, an honest per-unit zero. Met. **Lost:** a numbered list repeating the table, and merged totals in the lead.
- **(e) Three instructions in the same Results block contradict each other** (`DataAnswerPlan.php:752-757` and `:834`):
  - "HOMONYMS: 3 DIFFERENT records share the Unit Name '310' … never merge them into one sentence, one record or one figure. Your FIRST sentence must say that there are 3 different Unit Name '310' records (one per Union Name) and give each one's figures with its Union Name."
  - "Your FIRST sentence states the number of records (3) and the totals (Balance = $0.00; Invoices Due = $0.00), copied verbatim".
  - "Do NOT list the rows and do NOT write your own table."
- **Variance:** the model settles the contradiction differently each run. The numbered list was on 310 t0 in p14 and on 310 t1 in p16, with identical prompts.

## Ranked defects

Points lost = answer plus action shortfall the defect explains. Fix determinism = how much of it a code or config change removes without relying on the model.

| Rank | Defect | Turn(s) | Stage | Points lost | Fix determinism | Model variance? |
|---|---|---|---|---|---|---|
| 1 | Anchorless "balance" is never clarified; QU invents a possessive | 5 | (a), then (c), (e) | ~1.10 (0.65 ans, 0.45 act) | high | No — deterministic in 4 runs |
| 2 | Broad team question returns no type and no member count | 0 | (c) | ~0.60 | high (config) | No for the columns; yes for the bullet padding |
| 3 | Empty future-dated query: no past fallback, wrong "own linked records" scope | 10 | (c), (e) | ~0.55 | high | Substance no; wording yes |
| 4 | Truncated roster has no door; count is "rows" | 1 | (f), (e) | ~0.50 | high (config + code) | Chip loss follows QU's entity pick |
| 5 | Probe angles outvote the evidence angles; definitional passage evicted | 7 | (b) | ~0.55 | medium | **Yes** — probes vary with QU's rewrite |
| 6 | Knowledge turns can carry no door | 7, 8 | (e), (f) | ~0.55 | medium-high | No — by construction |
| 7 | Cross-union board presented as one board; split names; empty column; first-name chips | 9 | (c), (e), (f) | ~0.50 | medium-high | No |
| 8 | Homonym, totals and no-list directives contradict | 3, 6 | (e) | ~0.25 | high | **Yes** — and the contradiction causes it |
| 9 | Second paragraph of the cited article dropped | 8 | (d) | ~0.30 | medium-low | **Pure variance** |
| 10 | Article applied outside its conditions | 7 | (d) | overlaps 5 | low | **Yes** |
| 11 | All-empty columns rendered inline | 0, 3, 6, 9 | renderer | ~0.15 | high | No |

## Top 8 — proposed structural fixes

**1. Clarify when a possessive reading has no personal anchor (turn 5).**
- Guard in code, not in the QU prompt; the prompt wording has failed four runs.
- Trigger on structured signals only: `question_type = data_query`, no follow-up, no conversation result, no page context, QU named no record or person, and either `entity_types = []` or the personal-ownership path the query would use has no anchor for this user (no `Contact {user_id}` owning a unit — known before any query runs).
- Build the candidate readings from what the selector already matched: the labels of the configured `calculations` and entities that hold the requested concept ("Outstanding balance of one unit", "Total owed to the union/team", "Total owed TO suppliers"). `AiManager::fallbackClarifyingQuestion` already renders a readings list with zero LLM calls.
- Where: `AiManager::clarifyResponse` (line 1822) and the parse guard in `QueryUnderstandingService` (lines 624-633). The `entity_types = []` test alone would not have fired in p14, which returned the bogus `AccountBalance`; the missing-anchor test covers that case.
- Also remove "their balance" from the navigation instruction's examples in the QU prompt.

**2. Give Team a default overview projection (turn 0).**
- Config: add a canonical entry to `calculations` in `CondoEdge/config/ai.php:266`, e.g. `team_overview`: name, decoded `team_type`, member count per role (`User.current_team_id = t.id` joined to TeamRole), contact email and phone, `t.id`. No timestamps.
- Or put the default columns in the Team description in `CondoEdge/config/ai-entities/general.php:1068`; the QG rules already say descriptions "offer default columns".
- Doors: with member count and roles in the result, attach the list pages of the joined entities (needs fix 4's placement for members).

**3. Run the configured fallback on an empty time-bounded query, and describe the real scope (turn 10).**
- On `empty_result`, if an enabled `attention_sources` entry declares the query's primary entity and has a `fallback_cypher`, run it and hand the rows to the composer under its `fallback_label`. The digest path already does this (`DigestSourceResult::usedFallback`).
- A package-level version: detect a comparison against `date()` in the executed cypher and re-run once with it inverted, newest first.
- `AiManager.php:2677`: set `checked_own_data` from the executed cypher (does it use a configured personal-ownership path?) instead of `qu->isPossessive`.
- `ResponseGenerator.php:916-927`: pass the restriction that emptied the result ("upcoming only") so the answer can say "none upcoming".

**4. Give the truncated roster its doors (turn 1).**
- Config: `'entity' => ['User', 'TeamRole'], 'kind' => 'list'` on `team_settings` (`CondoEdge/config/ai.php:1193`). The truncated-list rule then attaches it regardless of what QU names.
- Code: when the retrieval is capped, attach the export chip in the mode the export turn already uses (re-run, `export_source_complete:true`, 523/523) instead of withholding it (`ActionAttacher.php:509-518`).
- Code: `identityChips` line 372 should also treat a query anchored on the current team as "about the team", not only QU naming `Team`.
- Lang: `ai.response.table_showing_of` should take the record kind's display name ("523 members").

**5. Stop the probes from outvoting the evidence (turn 7).**
- `AiManager::fuseAngleChunkRanks` (lines 1372-1390): fuse by group. The user's question and QU's search query are one group each; all renderings of one probe slot across languages count as one vote. Use the angle embeddings already computed by `embedAnglesInOneBatch` to collapse probes that are near-duplicates of each other.
- `mergeFileAngleResults`: always keep the best passage of the verbatim-question angle among the file's passages.
- `DocumentProbeWriter::parse` (lines 252-300) only removes exact-text duplicates; reject a second probe that is semantically the same as the first.
- Probe prompt (`buildMessages`): slot 1 asks for "the rule itself", which makes the model assert an answer. Make slot 2 structurally about the object — return the document's term for the thing as its own JSON key and require the probe to have it as its subject.
- `max_chunks_per_file = 3` is the whole grounding budget when the corpus is one law file; raise it to 5 for article-chunked documents.
- Record per-angle chunk rankings in the `file_context` stage so the next regression can be traced.

**6. Let knowledge turns carry a door (turns 7, 8).**
- `ActionAttacher::nextSteps` (lines 451-455) returns nothing when documents ground the turn. Add a follow-up chip role built from the retrieved passages' own metadata (section title and article range, already printed in the prompt header: "§DIVISION VII — GENERAL MEETING OF THE CO-OWNERS — art. 1098-1102").
- GuidelinesSection: the knowledge variant forbids both offers and any mention of attachments; once a chip exists, use the data variant's wording ("When one of them answers a WHERE/HOW question, say so in one short sentence by its name").

**7. Tell the composer how the rows are grouped, and fix names and columns (turn 9).**
- `DataAnswerPlan`: when the disambiguating parent column has several distinct values over all rows, emit a computed fact ("37 rows across N Union Name values") and require the first sentence to say so. Cut the display at a group boundary.
- After QG: a deterministic pass that aliases unaliased `var.prop` returns to the property name. It enforces the COLUMN NAMES rule that the model skipped here.
- `RecordResolver::nameOf` (line 341): compose the chip name from all of the entity's name columns, and add the disambiguator when the records span parents.
- Removes the false first-name HOMONYMS lines as a side effect.

**8. Resolve the homonym / totals / no-list contradiction (turns 3, 6).**
- `DataAnswerPlan.php:752-757`: when the table shows every row, drop "and give each one's figures with its {by}" — the table does that.
- `DataAnswerPlan.php:834`: skip the first-sentence totals line when a homonym group covers all rows; a sum across different records that share a name is the merge the homonym line forbids.

## Defects that are pure model variance (need a guard, not a prompt tweak)

- **Buyer, second paragraph of art. 1069 dropped** — identical passage in four runs, stated once. Guard: after composition, for each article the answer names, compare each paragraph of that article (paragraphs are structural in the chunk) with the answer by embedding; if one is uncovered, retry once quoting it. The validation/retry slot already exists in `response_metadata.validation`.
- **Team-info bullet list, 310 numbered list** — the answer re-lists rows the system renders. Guard: detect a list or table in the narrative whose cells repeat the rendered rows and strip it; fix 8 removes the instruction conflict behind the 310 case.
- **Supplier voice ("we" vs "you")** — right this run, wrong in p14, same prompt. Guard: none exists; the insider-voice check would have to run on data turns too.
- **Meetings, breadth of the stated absence** — removed by fix 3 rather than by wording.
- **Tear-down, article applied outside its conditions** — depends on what retrieval returns each run. Guard: a citation-applicability check on knowledge turns; lower priority than fix 5, which removes the trigger.

## Persisting minor defects (turns at or above 0.85)

- PDF: raw `0` money cells; a QG call for an empty cypher.
- Owner-arrears: "4 owners" for 4 units / 5 owners; "below" in the closing.
- Supplier: Oldest Due mixes datetimes and a date.
- Excel: "with the available information" hedge.
- 310 t0: "through the links below".
- 310 t1: conversation Current Focus is `Contact` after the ambiguous turn's ownership query.
