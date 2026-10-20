# Grade: p24-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p24-1001-A.json`, run 2026-10-07 06:41–06:47 -04:00. ai `badd2f1` + 617 dirty files (p23: 610). config_sha1 `017a8b13…` (p23: `676831b1…`).
- **Models:** unchanged (QU / composition / passage selection gpt-4o-mini, QG and follow-up writer gpt-4o).
- **Harness (summed from the rows):** 13/13 `ok`, 21/21 gates passed, 0 void, 0 infra; `errors`, `fallbacks`, `context_errors` empty on all 13. Top-level counters (`total_rows: 7`, `usage_total` 81,622) describe one chunk only.
- **Rubric / strictness:** identical to p20–p23. Pass >= 0.70, partial 0.40–0.69, fail < 0.40. Follow-up chips are scored in the action column.
- **What this run changes:** the QU call runs under a strict output schema; the navigation guard is widened; a turn with a navigation target is never answered by page narration.
- **Not verified:**
  - Nothing was executed against the graph; no phpunit, no artisan; no chip clicked, no file opened.
  - Single run. "Schema effect" below means p22 = p23 (both plain JSON, byte-identical QU prompt) and p24 differs. That is consistent with a schema effect, not proof of one.
  - The schema is not stored in the trace. Its presence is inferred from +279 input tokens on every QU call and from `ai.understanding.strict_schema` defaulting to true.
  - The raw QU reply is not stored, so whether the navigation guard dropped a target cannot be seen.
- **One thing was executed:** `FollowUpWriter::carried()` / `answerWords()` replayed by reflection on the stored law answers of p23 and p24 (working-tree code; it reproduces the p23 grader's figures on the p23 answer).

---

# 1 — GRADING

## Headline

- **Answer mean 0.838** (p23 0.838, **=**). **Action mean 0.877** (p23 0.873, **+0.004**).
- **Counts 11 pass / 2 partial / 0 fail** (p23 11 / 2 / 0).
- **No data answer regressed.** All 9 executed queries return the same row counts and the same `data_sample` as p23. Table columns, facts lines and their nouns, and data chips are identical on the 11 data / export / clarify turns.
- Only the two law turns moved, both in the action column: tear-down **+0.10**, buyer **−0.05**. Neither traces to the schema.
- The schema changed QU content fields on most turns (section 2). That altered 6 of 9 QG prompts and 2 generated queries, and no answer.

## Per-turn table

| # | Turn | p22 ans / act | p23 ans / act | p24 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (= / =) | Lead "…and it is a management company with 523 members." (p23 "is classified as"). Composition prompt byte-identical: variance. Same cypher, same block, same four chips. `navigation_target: own_team` kept (intent lookup, no concepts), answered from data. |
| 1 | roster t0 | 0.85 / 0.85 | 0.85 / 0.85 | **0.85 / 0.85** | pass (=) | Answer byte-identical. Same lead cut (`restatement`), same cypher, 100 / 523 rows. Export chip title back to "Team members and roles" (as p22). |
| 2 | roster t1 Excel | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | Same text, same 523-row payload, same columns. QU dropped `TeamRole` from `entity_types`; selection restored it; cypher identical. |
| 3 | 310 t0 | 0.95 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass (=) | "…currently owe $0.00 each, with no open invoices or invoices due." Composition prompt byte-identical. Same cypher, same 3 rows, same chips. |
| 4 | 310 t1 PDF | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | Byte-identical. `recorded_result_export` {generation_skipped: true, recorded_rows: 3}; one call, 5,375 tokens. |
| 5 | ambiguous t0 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | Byte-identical: same four readings, same four reply chips. Clarity-check prompt identical. |
| 6 | ambiguous t1 | 0.95 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass (=) | "…all have an outstanding balance of $0.00…" (echo of QU `search_query`, as in p22). Query lost `ORDER BY balance DESC LIMIT 100`; same 3 rows, same table. |
| 7 | tear-down t0 | 0.40 / 0.75 | 0.50 / 0.65 | **0.50 / 0.75** | **partial (= / +0.10)** | Same four points as p23. Approval conditional ("If the wall is part of the common portions…") [2]; art. 1100 as its own case [2]; art. 1063 cited for its proviso only [3]. Unsupported "structure" is gone. Still: opening presumes a restriction and now adds "and obtaining necessary approvals"; art. 1045 with half its condition; permission attributed to [1][3], which do not hold it; **no sentence says a wall wholly inside her private portion is hers to alter**. No article number named or invented. Chips: one borderline, one natural; the off-topic art. 1066 chip is gone (section 2 of verification). |
| 8 | buyer-arrears t0 | 0.60 / 0.85 | 0.60 / 0.85 | **0.60 / 0.80** | **partial (= / −0.05)** | Same content: liability with interest at acquisition [1], "Article 1069" (correct), the statement as advice only, **15-day protection not stated**, second paragraph repeats the first. Chips: the 15-day chip is lost (its excerpt is now judged "already told"); two chips instead, one natural, one weak. |
| 9 | board t0 | 0.75 / 0.75 | 0.75 / 0.75 | **0.75 / 0.75** | pass (=) | Answer byte-identical. The model's lead was false this run and was cut (GOOD). Same cypher, 37 rows, 5 record chips over 5 of 12 unions. QU returned `property_concepts: []`; `bm_role` was still returned. |
| 10 | board t1 meetings | 0.85 / 0.95 | 0.85 / 0.95 | **0.85 / 0.95** | pass (=) | "There are currently no upcoming meetings scheduled. Here are the most recent meetings that have taken place:" Query no longer touches `Schedule`; 0 rows again, same fallback, same 3 meetings, same chips. Agenda half still unaddressed. |
| 11 | owner-arrears t0 | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | Answer byte-identical ($1,211.48, "4 units.", four rows). Cypher identical but for line breaks. Filler second sentence cut (`one_sentence`). |
| 12 | supplier t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | Byte-identical ($11,493.17, 4 open bills). "You … owe … your suppliers" voice slip unchanged. Cypher identical. |

## Means

| Run | Answer, all 13 | Action, all 13 | Answer sum | Action sum |
|---|---|---|---|---|
| p22 | 0.831 | 0.881 | 10.80 | 11.45 |
| p23 | 0.838 | 0.873 | 10.90 | 11.35 |
| **p24** | **0.838** | **0.877** | **10.90** | **11.40** |

- vs p23: answers 0; actions +0.05 (tear-down +0.10, buyer −0.05).
- Without the two law turns: 0.891 (p23 0.891, p22 0.891).

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p22 | 11 | 2 | 0 |
| p23 | 11 | 2 | 0 |
| **p24** | **11** | **2** | **0** |

- **Partial:** tear-down 0.50, buyer 0.60. **Lowest pass:** board 0.75.

---

# 2 — QU FIELDS VS p23, AND WHAT THEY DID DOWNSTREAM

## How much moved

Three-way comparison over the 13 turns (p22 and p23 are plain JSON on a byte-identical QU prompt; the p24 QU prompt text is also byte-identical, so the schema is the only change in the call).

| Field | p24 differs from p23 | of which p22 = p23 (schema-consistent) | Plain-JSON noise (p22 vs p23) |
|---|---|---|---|
| `question_type`, `intent`, `navigation_target`, `export_format`, `digest_request`, `needs_clarification`, `follow_up_reference`, `candidate_readings` | **0** | 0 | 0 |
| `entity_types` | 6 (t2, t4, t6, t8, t11, t12) | **5** (all but t6) | 2 (t6, t9) |
| `property_concepts` | 9 | 2 (t10, t12) | 7 |
| `subject_label` | 9, of which 3 case-only | 3 (t5, t8, t12) | 6 |
| `is_possessive` | 1 (t10 false → true) | 1 | 1 (t9) |
| `search_query` | 3 (t5, t6, t8) | 1 | 3 |

- Routing fields did not flip on any Part A turn in any of the three runs. Part A does not test the flips the schema was built for (they were in Part B).
- `entity_types` is the field the schema visibly moves: 5 turns where two plain runs agreed and the schema run differs.
- `property_concepts` under the schema is longer on 8 turns (generic words: debt, amount, due, payments, responsibility, ownership, kitchen, living room, date) and **empty on one** (board).
- `subject_label` is shorter and sentence-case ("Balance", "Supplier debt", "Team members and roles").

## Per turn

Effect labels: better / worse / none, on the answer the user saw.

| # | QU difference vs p23 | Selection / QG prompt | Cypher | Rows, columns, facts line, chips | Effect |
|---|---|---|---|---|---|
| 0 | `clarified_question` wording only | QG prompt: date and that line | identical | identical | none |
| 1 | `subject_label` case | QG prompt: date only | identical | identical; export title lower-case | none |
| 2 | `entity_types` [Team, User, TeamRole, ContactDirectory] → [User, ContactDirectory, Team] | Same five entities selected (TeamRole restored as seed); card order changed | identical | 523 rows, Name / Email / Role; link title lower-case | none |
| 3 | `property_concepts` [balance, dues] → [balance, debt, amount]; `clarified_question` empty | REQUESTED ATTRIBUTES: "dues" line → "debt" + "amount"; `<clarified_intent>` block gone | identical | identical | none |
| 4 | `entity_types` lost Union; concepts [balance] → [balance, invoices, due] | Selection same three entities; no QG call (recorded result) | n/a | identical PDF payload | none |
| 5 | `search_query` "balance inquiry" → "balance"; `subject_label` "Balance Inquiry" → "Balance" | Clarity-check prompt byte-identical | n/a | same 4 readings, same 4 reply chips | none |
| 6 | `entity_types` Contact → **Fund**; concepts + "invoice"; `search_query` "…balance owed" → "…outstanding balance" | Fund card and scope rule replace Contact card and the contact-wording block (QG −302 tokens) | lost `ORDER BY balance DESC LIMIT 100` | same 3 rows, columns, homonym line, chips; lead wording follows `search_query` | none. Fund is a distractor (an echo of the "balance of a fund" reading). |
| 7 | concepts + "kitchen", "living room"; `clarified_question` shorter; `subject_label` | Probe 2 wording changed (it reads `clarified_question`); retrieval merged chunks 54 + 50: passage [4] is now art. 1055–1062 (+500 composition tokens); [1] relevance 45 → 58% | n/a | answer same level; chips differ (section 2 of verification) | none on the answer; the chip gain comes from answer wording, not from QU |
| 8 | `entity_types` + **Union**; concepts [fees] → [fees, responsibility, ownership]; `search_query` word order; `subject_label` | Retrieval: passage with art. 1092–1097 replaced by art. 1105–1107 (off-topic); 5 passages instead of 6; passage selection picked art. 1068 instead of art. 1086 | n/a | answer same content; chips differ | none on the answer. Chip loss is traced to the "already told" test (section 3). |
| 9 | `property_concepts` [board, president] → **[]** | **REQUESTED ATTRIBUTES block absent** from the QG prompt (−385 tokens) | identical (`cur.bm_role` still returned) | identical: "37 board members across 12 unions. Not recorded: Board role." | none this run. **Worse understanding**: the question names "president" and QU returned no concept. The "Not recorded" line survives only because QG returned the role column unprompted. |
| 10 | `is_possessive` false → true ("our", collective); concepts + "date"; `clarified_question` now filled | + User and Team cards, + collective-possessive block, + e-mail line, + `<clarified_intent>` (QG +539 tokens; composition +92) | p23 joined `Schedule` (non-optional) for agenda items; p24 returns the meeting only, **no agenda at all** | 0 rows both; identical fallback, 3 rows, chips | none (0 upcoming meetings). Different latent failure: p23's join could hide a meeting without agenda items; p24's query cannot show an agenda. |
| 11 | `entity_types` + Unit, reordered; concepts + "dues", "payments" | Unit seed instead of calculation tier; two more attribute lines | identical (line breaks only) | identical | none |
| 12 | `entity_types` Union → **Transaction**; concepts + "amount"; `subject_label` | Transaction card added; Union card shortened (tenant tier) (QG −198 tokens) | identical | identical | none. Transaction is a distractor for a payables question. |

- **Verdict on the regression check: 0 of 13 answers changed for the worse, 0 for the better, through QU content fields.**
- The canonical queries held where a calculation matched (310, owner-arrears, supplier) or the entity is unambiguous (team, roster, board). The two queries that changed shape (t6, t10) return the same rows.
- Three things to watch, none costing points today: distractor entities entering as seeds (Fund, Transaction, Union), inflated concept lists, and the empty concept list on board.

## Navigation guard

- Only t0 carries a target (`own_team`, intent lookup, no concepts): kept, as intended. Answered from data with the Team settings chip; no `page_narration` stage; `page_context` is null in this suite.
- No list / count / aggregate / compare turn shows a target in any of the three runs, so the widened guard has nothing visible to drop. The roster turn (intent list) was already null in p22 and p23 through the concepts signal.
- The one turn where only the new signal could act is board (intent list, concepts empty in p24). Whether it acted is not observable.
- **Not exercised by Part A; no false positive.**

---

# 2b — VERIFICATION

| Item | Status | Evidence |
|---|---|---|
| Strict schema on the QU call | **Active on 13 of 13** | Input +279 tokens on every call (4,783 → 5,062 on t0; same delta on all 13); prompt text byte-identical to p23. |
| Queries changed vs p23 | **2 of 9; same rows** | t6 lost ORDER BY / LIMIT; t10 lost the Schedule join. Seven identical (t11 differs by line breaks). |
| Row counts, `data_sample` | **Identical on all 13** | 1, 100 / 523, 100 / 523, 3, 3 (export), –, 3, –, –, 37, 0 → 3 fallback, 4, 1. |
| Table columns, facts lines, nouns | **Identical on all data turns** | "523 members.", "3 different units named 310 — one per union.", "37 board members across 12 unions. Not recorded: Board role.", "4 units."; exports "all 523 rows", "all 3 units". |
| Data chips | **Identical on all 9 data / export turns** | Only the export title's letter case differs (t1). |
| Precedence check | Wired, never fired | `precedence_violations: []`, `attempts: 1`, `repair_attempts: 0` on all 9. |
| Closing drops / label-echo drops | **None** | No `closing_dropped`, no `label_echo_dropped` in the file. Tear-down's closing kept; keeping it is right. |
| Security / admission | **Clean** | 13/13 `security_gate` passed. 9 executions: `safe_stop: false`, `security_filter_applied: true`, `repair_attempted: false`. No SecurityException, no `admission` stage, no busy answer, no validation retry. Unchanged watch item: the meetings fallback cypher is logged without a team predicate (`scope: "team"`). |

## Law turns: passages and passage selection

| | Tear-down (turn 7) | Buyer (turn 8) |
|---|---|---|
| Pool / candidates | 20 / 20 | 20 / 20 |
| Picked | chunk 58 = **art. 1063** (as p22, p23) | chunk 63 = **art. 1068** (p22, p23: art. 1086) |
| Added as | `joined` into chunk 61 → passage [3] art. 1063–1068 | `joined` into chunk 66 → passage [1] now art. 1068–1070 |
| Tokens / latency | 4,061 / 1,481 ms | 3,997 / 1,409 ms |
| Answer cites the added text? | **Yes, [3]** — art. 1063's proviso only, as p23 | **No** — it cites [1] for art. 1069 only |
| Helped? | Partly: the limits are stated, the right is not | No. Third run with a tangential pick; art. 1068 is about revising relative values. It also fed a tier-2 excerpt the writer had to decline. |

Final passages in the prompt:

| # | Tear-down | Used? | Buyer | Used? |
|---|---|---|---|---|
| [1] | art. 1042–1047, 58% | cited (art. 1045, half its condition) | art. 1068–1070, 49% | cited twice (art. 1069 para. 1, half of para. 2) |
| [2] | art. 1098–1102, 71% | cited twice (art. 1098 loosely, art. 1100) | art. 1063–1067, 49% | no |
| [3] | art. **1063**–1068, 66% | cited twice (once wrongly for permission; art. 1063 proviso) | art. 1070.1.1–1072, 47% | no |
| [4] | art. 1055–**1062**, 64% — declaration content and registration | no; tangential, twice as long as in p23 | art. 1076–1080, 47% | no |
| [5] | art. 1076.1–1081, 56% | no; off-topic | art. 1105–1107, 42% — developer hand-over | no; off-topic |

- **Does the tear-down answer distinguish a wall inside her own private portion from walls presumed common and from works needing approval?** No better than p23. Approval depends on the wall being "part of the common portions"; art. 1063's limits are listed; the positive rule (free use and enjoyment of the private portion) is not stated; art. 1044 is unused in the answer.
- Composition instruction frame is byte-identical to p23 on both turns (system prompt same hash; user prompt differs only inside the passages).

## Every follow-up chip

Labels: N natural, A acceptable, O off-topic or already answered.

| Turn | Chip question | Source | Label | Note |
|---|---|---|---|---|
| 5 clarify | Outstanding balance of one unit | reading 1 | N | same as p23 |
| 5 clarify | Balance of an account | reading 2 | N | same |
| 5 clarify | Balance of a fund | reading 3 | N | same |
| 5 clarify | Balance of an invoice | reading 4 | A | same; weakest reading |
| 7 tear-down | Whose consents are needed for amending the declaration of co-ownership? | passage [2], art. 1100 para. 2, tier 1 | **A, borderline O** | Same chip as p23; two of the three consents are already in point 3. |
| 7 tear-down | What are presumed to be common portions in a co-ownership? | passage [1], art. 1044, tier 2 | **N** | New. On the thread the answer opens (is my wall common?); answerable (main walls, foundations…). Generic wording. |
| 8 buyer | How is the statement of common expenses adjusted for a condo unit? | passage [1], art. 1069 para. 3, tier 1 | **A, borderline O** | Answerable (adjusted to the last annual budget). A technical detail, not the buyer's question. |
| 8 buyer | What must a seller provide to a buyer in a condo sale? | passage [1], art. 1068.1, tier 2 | **N** | Answerable (the syndicate's certificate on the condition of the immovable). A buyer's natural next question. |

- Totals: 8 chips — 5 N, 3 A, 0 O (p23: 7 chips — 3 N, 3 A, 1 O). Legal chips: 4 — 2 N, 2 A, 0 O (p23: 3 — 0 N, 2 A, 1 O).
- Declined excerpts, 4, all correctly: tear-down art. 1066 (lessee notices) and art. 1099; buyer art. 1068 and art. 1063.
- **Lost against p23:** the buyer's 15-day chip ("When am I bound to pay common expenses as a prospective acquirer?"), the only chip that led to the fact the answer leaves out.
- Excerpt texts differ from p23 in shape (art. 1066 excerpt is now one sentence; art. 1068.1 has a third sentence). Cause not traced; not a QU effect.

## "Already told" test, replayed

| Turn | Paragraph | p23: carried words / content words | p24: carried / content words | Decision p23 → p24 |
|---|---|---|---|---|
| buyer | art. 1069 para. 1 | 15 / 22 | 17 / 22 | told → told |
| buyer | **art. 1069 para. 2 (15-day rule)** | 13 exact + 1 stem = 14 / 29 (48.3%) | **14 exact + 2 stem = 16 / 29 (55.2%)** | **not told → "told"** |
| buyer | art. 1069 para. 3 | 2 / 7 | 3 / 7 | not told → not told |
| tear-down | art. 1045 | 7 / 15 (46.7%) | 8 / 15 (53.3%) | not told → told |
| tear-down | art. 1066 para. 1 | 8 exact / 16 | 7 exact + 1 stem ("required") = 8 / 16 | seen → seen |
| tear-down | art. 1063 | 10 / 17 | 10 / 17 | told → told |
| tear-down | art. 1100 para. 1 | 17 / 32 | 17 / 32 | told → told |

- Buyer: the new answer adds "provide" ("as this can provide clarity"), which matches "provide" exactly and "provided" by stem. The 15-day paragraph crosses the 50% line and is dropped as an excerpt, **while the answer still does not state the 15 days**. Without the stem keys it would stand at 14 / 29 and stay an excerpt. This is the margin the p23 grading flagged.
- Tear-down: art. 1045 is now carried, so passage [1] yields its sibling art. 1044 as an excerpt (the natural chip). Art. 1066 is still "seen" on exactly 8 words, one of them by stem; its off-topic excerpt was offered again and the writer declined it this time.

## Every lead cut

| Turn | What the model wrote | Cut (rule) | What remains | Verdict |
|---|---|---|---|---|
| 1 roster | "Here are the members of your team, Novo Gestion, along with their roles:" | whole lead, `restatement` | "523 members." + table + "Showing 25 of 523" | **GOOD** (no value lost; still no "your team" left). |
| 9 board | S1 "I could not find the specific names of the board of directors or the president in the available data." | S1, `not_recorded` | "37 board members across 12 unions. Not recorded: Board role." + table | **GOOD**: the sentence is false (37 names follow). |
| 9 board | S2 "The records do not include board roles." | S2, `one_sentence` | same | **GOOD / neutral**: true, and the code line says the same. |
| 11 owner-arrears | S2 "Here are the details of the owners who are currently behind on their fees." | S2, `one_sentence` | S1 with the total + "4 units." + table | **GOOD**: filler. |

- No HARMFUL cut this run. The board lead was false in 4 of the last 5 runs (p20–p22, p24) and true once (p23): the rule's blind spot stands, the model's sentence is variance on a byte-identical composition prompt.
- Leads not cut: team-info, both 310 leads, meetings, supplier.

## Clarify turn

- Readings and reply chips are identical to p23 (and p22): outstanding balance of one unit / balance of an account / balance of a fund / balance of an invoice.
- QU differs only in `search_query` and `subject_label`; `entity_types: []`, `property_concepts: ["balance"]`, `needs_clarification` and `candidate_readings` are unchanged. The clarity-check prompt is byte-identical, 1,171 tokens in both runs.

---

# 3 — REGRESSIONS VS p23

| Turn | Delta | Cause class | Evidence |
|---|---|---|---|
| buyer, action | **−0.05** | **Model variance in the answer, crossing a deterministic rule (the stem-insensitive "already told" test of iteration 8). Not the schema.** | Replay above: art. 1069 para. 2 goes 48.3% → 55.2% on one added word; the excerpt disappears; the writer (system prompt byte-identical) then writes on art. 1069 para. 3 and art. 1068.1. The composition prompt did change through QU free text (different passages [3]–[5], art. 1068 joined into [1]), but the answer's content is the same as p23. |
| board, QU concepts | no score change | **Schema-run QU output; class uncertain** (the field differed in all three runs) | `property_concepts: []` for a question naming "president"; REQUESTED ATTRIBUTES block absent; cypher identical. |
| meetings, query shape | no score change | **QG variance on a prompt the QU changed; schema-consistent** (`is_possessive` and the concept list were stable across p22 / p23) | QG prompt +2.2k characters; the query no longer reads agenda items. Third different query in three runs for this turn. |
| ambiguous t1, query shape | no score change | **QG variance on a prompt the QU changed; plain variance** (`entity_types` differed in all three runs) | Fund card in, Contact card out; ORDER BY / LIMIT dropped; `warnings: missing LIMIT`. |
| supplier, 310 t1, owner-arrears, buyer: entity lists | no score change | **Schema-consistent** (p22 = p23, p24 differs) | Transaction, Fund and Union enter as seeds; cyphers identical. |
| tear-down, passage [4] | no score change | **QU free-text variance → probe wording → retrieval** | Probe 2 now ends "…dispositions de la déclaration de copropriété"; chunks 54 + 50 merged; +500 composition tokens of registration articles. |

Movement in the other direction:

- **Tear-down action +0.10.** Model variance in the answer: art. 1045 is now carried by words, so art. 1044 gets an excerpt slot; the writer declined art. 1066. The off-topic chip of p23 is gone, a natural one is back.
- **Board lead cut** is right again (the model's sentence was false).
- **QU latency −12.7 s, pipeline −16.1 s** (section 5).

Moved without a score change, pure wording: team-info, 310 t0, 310 t1, meetings leads. Byte-identical answers: roster, PDF, clarify, board, owner-arrears, supplier (6 of 13; p23 had 5).

**No answer or data regression traces to the strict schema, the widened navigation guard or the page-narration rule.**

---

# 4 — REMAINING DEFECT RANKING

Missing points: answers 2.10, actions 1.60. Stage letters: (a) understanding, (b) retrieval, (c) query generation, (d) data in the prompt but ignored, (e) a composition instruction or code rule, (f) action attachment.

| Rank | Defect | Turn(s) | Stage | Points | Fix determinism | Model variance? |
|---|---|---|---|---|---|---|
| 1 | Board: president answered only by a column label; record chips for 5 of 12 unions | 9 | (e), (f) | ~0.50 | **high** | No |
| 2 | Tear-down: art. 1063 used as a limit only; no "inside your own portion" statement; art. 1045 half-conditioned; committed opening | 7 | (d), (e) | ~0.40 | medium | Which half: yes |
| 3 | Buyer: 15-day protection left out (6 of 8 graded runs on this instruction) | 8 | (d) | ~0.40 | medium-high | **Pure variance** on a fixed frame |
| 4 | **Buyer chips: the "already told" test drops the 15-day excerpt on a coincidence of common words, so the chip that repairs defect 3 is gone** | 8 | (f) | ~0.10 now; it removes the only path to the missing fact | **high** | Trigger: yes. Rule: no |
| 5 | Roster: staff buried in an unordered sample; no insider wording after the cut | 1 | (c), (e) | ~0.30 | high | No |
| 6 | Team-info: lead restates block values; no members door | 0 | (e), (f) | ~0.25 | high | Restating: yes |
| 7 | Tear-down chips: art. 1066 still "seen" on 8 common words (one by stem) and offered as a tier-1 excerpt; only the writer's choice kept it out this run. The art. 1100 chip repeats the answer. | 7 | (f) | ~0.25 | **high** | Trigger: yes |
| 8 | Clarify: "balance of an invoice"; reply chips send a bare reading | 5 | (a), (f) | ~0.20 | medium | Which kinds: yes |
| 9 | Meetings: agenda half unanswered; three different queries in three runs (description as agenda / non-optional join / no agenda) | 10 | (c), (e) | ~0.15 now; a wrong or half answer once a meeting is scheduled | medium | Query shape: **pure variance** |
| 10 | Export leftovers: "rows" vs "units"; "No values found for: Oldest Due." | 2, 4 | (e) code | ~0.15 | certain | No |
| 11 | Voice: "you owe … your suppliers" | 12 | (d) | ~0.15 | low | **Pure variance** |
| 12 | 310: lead and homonym line say the same thing twice | 3, 6 | (e) | ~0.10 | high | No |
| 13 | **QU content fields under the schema: distractor entities as seeds (Fund, Transaction, Union), generic concept words, and an empty concept list on board** | 6, 8, 9, 12 | (a) | 0 today; 3 of 9 data turns carry a card the question does not need | medium | Yes |
| 14 | Passage selection always picks its quota: art. 1068 on buyer (third tangential pick in three runs) | 8 | (b) | 0; 4.0k tokens, 1.4 s, one excerpt to decline | medium | Which pick: yes |
| 15 | Tear-down retrieval: passages [4] and [5] unused; [4] doubled in length | 7 | (b) | ~0.05, ~2k prompt tokens | low | **Pure variance** (probe wording) |
| 16 | Excel follow-up pays a QG call (by design) | 2 | (e) code | 0; 7.2k tokens, 3.5 s | certain | No |

## Most structural fix per top defect

1. **Board.** Have the code line name the concept the user asked for ("President: not recorded") instead of the column header, and spread record chips over unions. With `property_concepts` now sometimes empty, that line cannot rely on the QU concept list alone.
2. **Tear-down composition.** Unchanged from p22 / p23: give the selector's pick (art. 1063) its own passage, first, or mark it as the general rule.
3. **Buyer 15 days.** Unchanged: regenerate once with the unused clause of the cited paragraph, or instruct the composer to give the condition the cited passage attaches.
4. **"Already told" test.** A paragraph whose figures are absent from the answer is not told: art. 1069 para. 2 holds "15 days", the answer holds no "15". Checking the paragraph's numbers before the word share would have kept the excerpt in p24 and costs nothing. Not run; inferred from the replay.
7. **Tear-down chips.** As p23: in a passage cited without an article number, take the article `drawnOn()` names (art. 1063, 10 words) as the used one, and do not add every article `seen()` admits on 8 words.
13. **QU content fields.** Compare the entity lists of a schema run and a plain run on the full suite before relying on `entity_types` for anything new; the calculation match is what protects the three money turns today.

Defects 1, 4, 5, 6, 7, 10, 12 need no model change and cover about 1.65 of the 3.70 points missing. Defects 3, 11, 15 are pure variance, about 0.60.

---

# 5 — TOKENS AND LATENCY VS p23

Tokens, summed per turn:

| Stage | p23 | p24 | Delta |
|---|---|---|---|
| understanding (13 calls) | 65,527 (62,876 in / 2,651 out) | 68,313 (66,503 in / 1,810 out) | **+2,786** (+3,627 in, −841 out) |
| query_generation (9 calls) | 62,492 | 62,214 | −278 |
| composition (10 calls) | 33,333 | 33,709 | +376 |
| search_probes (2 calls) | 1,661 | 1,669 | +8 |
| passage_selection (2 calls) | 7,983 | 8,058 | +75 |
| clarity_check (1 call) | 1,171 | 1,171 | 0 |
| follow_ups (2 calls, gpt-4o) | 2,369 | 2,391 | +22 |
| **Total** | **174,536** (39 calls) | **177,525** (39 calls) | **+2,989 (+1.7%)** |

- Input 169,336 → 173,147; output 5,200 → 4,378.
- **QU call:** +279 input tokens on every call (the schema), output 204 → 139 per call (**−32%**). Net +214 tokens per turn, +4.3% on the stage. Output tokens are the dearer ones on this model, so the cost is close to neutral.
- QG −278, all from QU content fields: meetings +539 (possessive block, User / Team cards), board −385 (no REQUESTED ATTRIBUTES block), ambiguous t1 −302 (Fund for Contact), supplier −198, owner-arrears +76; the rest within ±17. No repair call.
- Composition +376: tear-down +500 (passage [4] doubled), buyer −218 (5 passages instead of 6), meetings +92.
- Knowledge turns: tear-down 17,523 (p23 16,724), buyer 17,445 (p23 17,425).

Latency:

| Stage | p23 sum / mean | p24 sum / mean |
|---|---|---|
| understanding | 83.4 s / 6.41 s (max 10.1 s) | **70.7 s / 5.44 s (max 6.5 s)** |
| file_context (2 knowledge turns) | 13.4 s (7.78 s, 5.64 s) | 12.0 s (6.42 s, 5.59 s) |
| of which probe call | 4.6 s | 4.6 s |
| of which passage selection | 2.9 s (1.57 s, 1.33 s) | 2.9 s (1.48 s, 1.41 s) |
| query_generation | 24.6 s / 2.73 s over 9 (max 4.9 s) | 22.2 s / 2.47 s over 9 (max 3.5 s) |
| execution | 1.0 s / 0.11 s over 9 | 0.9 s / 0.10 s over 9 |
| composition (10 LLM-composed turns) | 17.5 s / 1.75 s (tear-down 3.45 s) | 18.0 s / 1.80 s (tear-down 3.54 s) |
| follow_ups (2 turns) | 3.25 s / 1.63 s | 3.43 s / 1.71 s |
| clarity_check | 2.06 s | 1.96 s |
| empty_result_check | 0.06 s | 0.04 s |
| **pipeline** | **145.6 s / 11.2 s, median 10.2 s, max 19.4 s** | **129.5 s / 9.96 s, median 9.8 s, max 17.3 s** |

- −16.1 s overall: understanding −12.7 s, QG −2.4 s, file_context −1.4 s, composition +0.5 s.
- Understanding is faster on 11 of 13 turns (slower on team-info and ambiguous t1) and loses its outlier (supplier 10.1 s → 5.7 s). Fewer output tokens fits that; one run cannot separate it from provider latency.
- Understanding is 55% of pipeline time (p23: 57%).
