# Grade: p25-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p25-1001-A.json`, run 2026-10-07 15:00–15:07 -04:00. ai `badd2f1` + 666 dirty files (p24: 617). config_sha1 `03be375a…` (p24 `017a8b13…`; not comparable, the fingerprint changed what it hashes).
- **Models:** unchanged (QU / composition gpt-4o-mini, QG and follow-up writer gpt-4o). The map reader's model is not named in the fingerprint or per stage.
- **Harness (summed from the rows):** 13/13 `ok`, 21/21 gates passed, 0 void, 0 infra; `errors`, `fallbacks`, `context_errors` empty on all 13. Top-level counters (`total_rows: 7`, `usage_total` 88,887 / 18 calls) describe the first chunk only.
- **Rubric / strictness:** identical to p20–p24. Pass >= 0.70, partial 0.40–0.69, fail < 0.40. Follow-up chips are scored in the action column.
- **Not verified:**
  - Nothing was executed against the graph; no phpunit, no artisan, no git; no chip clicked, no file opened.
  - Single run. Every "variance" label below means "the prompt of that call is byte-identical to p24 and the output differs".
  - The map reader's raw reply (its `issue` and `answerable` text) is not stored beyond the picks; the issue sentence is read from the QUESTION ANALYSIS block.
  - The cause of the slower graph execution (section 5) cannot be read from the trace.
- **Code read, not run:** `AiManager::generateAndExecute`, `PreviousResultExport::asksNothingNew` / `namesNothing` (for the extra call on the PDF turn); `DocumentMapTurn` gate block.

---

# 1 — GRADING

## Headline

- **Answer mean 0.846** (p24 0.838, **+0.008**). **Action mean 0.885** (p24 0.877, **+0.008**).
- **Counts 12 pass / 1 partial / 0 fail** (p24 11 / 2 / 0).
- **Tear-down passes for the first time, at the line: 0.70** (p24 0.50). The gate opened, the three governing rules are stated and each is cited to the right passage, art. 1100 is not used.
- **Buyer unchanged at 0.60.** Gate closed, no invented claim, the 15-day rule is absent (not garbled).
- **One answer regressed: team-info 0.85 → 0.75.** Composition variance plus a self-reference patch that doubled the "that's you" marker. Not caused by the removals or the document map.
- **Data turns are unchanged**: same row counts, `data_sample`, table columns, facts lines and chips on all 11 data / export / clarify turns; 6 of 9 queries character-identical (of the other three, one differs by line breaks only, one regained `ORDER BY … LIMIT`, one dropped `ASC`).
- **No document work ran on a data turn.** The extra call in chunk 1 is a query-generation call on the PDF turn (section 4).

## Per-turn table

| # | Turn | p23 ans / act | p24 ans / act | p25 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.75 / 0.90** | pass (**−0.10** / =) | Lead is now two sentences restating all five block values, with a stutter: "Your contact email is j.rousseau@… (that's you — your own contact details), which is your own email, and the contact phone number is…". `validation.violations_first: [self_reference_unmarked]`, `patched`, `self_reference_guard: patched` (p24: `table`, no violation). Composition prompt byte-identical. Same cypher, block, four chips. |
| 1 | roster t0 | 0.85 / 0.85 | 0.85 / 0.85 | **0.85 / 0.85** | pass (=) | Answer byte-identical. Same lead cut (`restatement`), same cypher, 100 / 523 rows, same chips. |
| 2 | roster t1 Excel | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | Answer and 523-row payload byte-identical; cypher identical. |
| 3 | 310 t0 | 0.95 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass (=) | Answer byte-identical. Same cypher, 3 rows, chips. QU now fills `clarified_question`; QG prompt gains the `<clarified_intent>` block (+28 tokens). |
| 4 | 310 t1 PDF | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | Same text and 3-row payload; title "Unit 310 Balance" (capital B, from t3's `subject_label`). **Costs one more call**: QG ran (7,248 tokens, 1.8 s) and returned an empty query; the export still came from the recorded result. |
| 5 | ambiguous t0 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | Byte-identical: same four readings, same four reply chips. Clarity-check prompt identical, 1,171 tokens. |
| 6 | ambiguous t1 | 0.95 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass (=) | "…each have an outstanding balance of $0.00…" (p24 "all have"). QU identical, QG prompt identical but for the date; the query regained `ORDER BY balance DESC LIMIT 100`. Same 3 rows, table, homonym line, chips. |
| 7 | tear-down t0 | 0.50 / 0.65 | 0.50 / 0.75 | **0.70 / 0.80** | **pass (+0.20 / +0.05)** | Gate OPEN (art. 1063 and art. 1097 were not in the plain search's passages). Answer: general rule with its limits [1]; separating walls presumed common, full condition [2]; three-quarters of the votes for alterations to common portions [3]; "depends on whether it is … common … or private". No art. 1100, no art. 1066, no article number named. Still: one misapplied inference, the private branch is not spelled out, main walls absent, no direct lead (section 2). One chip, natural. |
| 8 | buyer-arrears t0 | 0.60 / 0.85 | 0.60 / 0.80 | **0.60 / 0.85** | **partial (= / +0.05)** | Gate CLOSED. Same content as p24: liability with interest at acquisition [2], the statement as advice only (now uncited), **15-day protection not stated**, second paragraph repeats the first. No invented rule. Chips: the 15-day chip is back (weaker wording), plus the same two as p24. |
| 9 | board t0 | 0.75 / 0.75 | 0.75 / 0.75 | **0.75 / 0.75** | pass (=) | Final answer byte-identical. The model's lead was **true** this run and was cut (HARMFUL, mild — as p23). Same cypher, 37 rows, 5 record chips over 5 of 12 unions. QU returned `property_concepts: [board, president]` again; the REQUESTED ATTRIBUTES block is back in the QG prompt (+382 tokens). |
| 10 | board t1 meetings | 0.85 / 0.95 | 0.85 / 0.95 | **0.85 / 0.95** | pass (=) | "There are currently no upcoming meetings scheduled. Here are the most recent meetings:". Query identical but for `ASC`; 0 rows, same fallback, same 3 meetings, same chips. Agenda half still unaddressed. |
| 11 | owner-arrears t0 | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | "The total balance of owners behind on fees for SDC Carré Soho Phase 3 is $1,211.48." + "4 units." + same four rows. Cypher identical but for line breaks. Same `one_sentence` cut. |
| 12 | supplier t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | "Currently, you owe a total of $11,493.17 to your suppliers, with 4 open bills." Voice slip unchanged. Cypher identical. |

## Means

| Run | Answer, all 13 | Action, all 13 | Answer sum | Action sum |
|---|---|---|---|---|
| p23 | 0.838 | 0.873 | 10.90 | 11.35 |
| p24 | 0.838 | 0.877 | 10.90 | 11.40 |
| **p25** | **0.846** | **0.885** | **11.00** | **11.50** |

- vs p24: answers +0.10 (tear-down +0.20, team-info −0.10); actions +0.10 (tear-down +0.05, buyer +0.05).
- Without the two law turns: 0.882 (p24 0.891) — the team-info drop.
- The two law turns alone: 0.65 (p24 0.55).

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p23 | 11 | 2 | 0 |
| p24 | 11 | 2 | 0 |
| **p25** | **12** | **1** | **0** |

- **Partial:** buyer 0.60. **Lowest passes:** tear-down 0.70, team-info 0.75, board 0.75.
- The tear-down pass sits on the threshold. A grader who counts the misapplied sentence in point 2 as an error rather than a hedged defect lands at 0.65. It is one run.

---

# 2 — THE TWO LAW TURNS

## What the reader and the gate did

| | Tear-down (turn 7) | Buyer (turn 8) |
|---|---|---|
| Map shown | 90 passage lines, 25,009 chars, not cut; headings are Chapter I and Chapter III only — **no Chapter II (undivided) line in the map** | same map |
| `answerable` | yes | yes |
| Reader picks (chunk = article, role) | 38 = art. 1045, **classification**; 58 = art. 1063, **general_rule**; 113 = art. 1100, **condition_or_exception**; 110 = art. 1097, **procedure_or_approval** | 66 = art. 1069, **general_rule**; 59 = art. 1064, **general_rule** |
| Plain search hits | 38, 113, 61 (two of four angles below the floor) | 66, 60, 108, 72, 85, 64, 74, 83 |
| Gate | **OPEN**, `opened_by: [58:general_rule, 110:procedure_or_approval]` | **CLOSED**, `opened_by: []` |
| QUESTION ANALYSIS block | present: 1. general rule — art. 1063 [1]; 2. category — art. 1045 [2]; 3. who approves — art. 1097 [3] | absent; instruction frame identical to p24 |
| Reader call | 6,079 tokens, 2,249 ms | 6,091 tokens, 2,552 ms |
| Calls on the turn | 4 (p24: 5) | 4 (p24: 5) |

- Art. 1100 was picked by the reader as `condition_or_exception`. That role is not listed in the block, so the composer was not told to use it. Its text is still in passage [3].
- Buyer: the gate closed because chunk 59 (art. 1064) lies inside the plain search's passage around hit 60 (passage [1], art. 1063–1067). This is inferred from the passage's article range; a closed gate stores no chunk list. Had that passage not reached chunk 59, a tangential `general_rule` pick would have opened the gate. Closed by the rule as written, with a thin margin.

Final passages in the prompt:

| # | Tear-down p25 | From | Used? | Buyer p25 | Used? |
|---|---|---|---|---|---|
| [1] | art. **1063** alone (no relevance figure) | reader | cited, correctly | art. 1063–1067, 51% | no |
| [2] | art. 1042–1047, 43% | both | cited (art. 1045) | art. 1068–1070, 49% | cited once (art. 1069 para. 1) |
| [3] | art. **1097**–1102, 41% | both | cited (art. 1097) | art. 1092–1097, 50% | no; off-topic |
| [4] | art. 1064–1068, 41% | search | no; off-topic | art. 1070–1072, 47% | no |
| [5] | — | | | art. 1078–1082, 47% | no; off-topic |

- p24 tear-down had five passages (art. 1042–1047, 1098–1102, 1063–1068, 1055–1062, 1076.1–1081). **Art. 1097 was not in the p24 prompt at all**; art. 1063 was buried third inside a six-article passage.
- All passages on both turns are Chapter III.

## Tear-down — claim by claim

| # | Claim in the answer | Passage text it rests on | Verdict |
|---|---|---|---|
| 0 | "To determine whether you can tear down the wall…, we need to consider the following rules from the Quebec Civil Code regarding co-ownership" | — | Neutral preamble. No verdict, no presumed restriction (p24 opened with "You generally cannot…"). Not a direct answer either. |
| 1 | "Each co-owner has the right to use and enjoy their private portion, provided they comply with the by-laws of the immovable and do not impair the rights of other co-owners or the destination of the immovable [1]." | art. 1063: "free use and enjoyment of his private portion … provided he complies with the by-laws of the immovable and does not impair the rights of the other co-owners or the destination of the immovable" | **Supported.** Right and all three limits, cited to the right passage. "free" dropped. |
| 2a | "partitions or walls that separate a private portion from a common portion or from another private portion are presumed to be common" [2] | art. 1045, both halves of the condition | **Supported.** Full condition (p24 had half). The qualifier "not part of the foundations and main walls" is dropped. |
| 2b | "This means that the wall in question may not be solely yours to modify without approval [2]." | art. 1045 | **Misapplied (hedged).** A wall between her kitchen and her living room separates neither a private portion from a common one nor two private portions. Art. 1045 as quoted does not reach it. The sentence turns the rule toward "probably common" when the text points the other way for an interior partition. |
| 3a | "Decisions concerning alterations that affect common portions require the approval of co-owners representing three-quarters of the votes of those present or represented at a meeting [3]." | art. 1097, 2°: "work for the alteration, enlargement or improvement of the common portions"; "three-quarters of the votes of the co-owners present or represented" | **Supported**, precise majority. |
| 3b | "If the wall is deemed common, you would need to follow this process to obtain permission." | art. 1097 | Supported as a conditional. |
| 4 | "whether you can tear down the wall depends on whether it is classified as a common portion or a private portion. If it is common, you will need to seek approval from the other co-owners." | [1]–[3] | Supported. Says what decides. **Only the common branch is completed**; the private branch is left to the reader to assemble from point 1. |

- Ungrounded claims: **0**. Misapplied rule: **1** (2b, hedged). Invented or named article numbers: none (the answer cites by passage only).
- The three points asked for: (1) free use of the private portion with its limits — **stated**; (2) separating walls presumed common — **stated for art. 1045; art. 1044 (main walls) is not in the answer**; (3) three-quarters for alterations to common portions — **stated**. Art. 1100: **not used**. Art. 1066: not used.
- Citations on the gated answer: [1], [2], [3], one per rule, each to the passage that holds it. Harness `cites_document: 3`.

**What earns the pass (0.70):**
- The three governing rules are each stated with their conditions and cited correctly. In p24 two of the four citations pointed at passages that did not hold the claim, and the three-quarters rule was missing.
- No flat verdict, no art. 1100 as "your case", no invented specifics.
- 1,190 characters (p24 1,292).

**What still blocks 0.80 and above:**
1. Sentence 2b applies art. 1045 to a wall it does not describe.
2. No sentence says a partition wholly inside her private portion, not a main wall, is hers to alter within the limits of point 1. The standard asks for that distinction in those terms.
3. Main / bearing walls (art. 1044) appear only in the chip. The block names one article per role (art. 1045), and art. 1044 sits in the same passage unused.
4. The syndicate and the declaration of co-ownership are not named as where to check.
5. No direct lead: the answer opens with a preamble and gives its conclusion last.

- Traced cause of 1–3: the block tells the composer to state the rules and "say what decides the category". Nothing tells it to test the user's own facts (two rooms of the same unit) against the category rule, and the classification line names art. 1045 only.

## Buyer — claim by claim

| # | Claim in the answer | Passage text | Verdict |
|---|---|---|---|
| 1 | "you are responsible for paying all common expenses due with respect to that unit at the time of acquisition, including any unpaid fees from the previous owner" | art. 1069 para. 1 | **Supported.** |
| 2 | "a person acquiring a fraction of an immovable under divided co-ownership is bound to pay all common expenses due with respect to that fraction, with interest, at the time of the acquisition [2]" | art. 1069 para. 1, near verbatim | **Supported**, cited correctly. |
| 3 | "Therefore, if there are any outstanding condo fees from the previous owner, you would be responsible for those as well." | — | Repeats claim 1. |
| 4 | "It's advisable to request a statement of common expenses from the syndicate before finalizing the purchase to understand any existing debts associated with the unit." | art. 1069 para. 2 holds the mechanism | The statement exists in the passage; "advisable" is the model's. **No citation on this sentence** (p24 had one). |

- Gate closed, as intended. No QUESTION ANALYSIS block.
- **15-day protection: not stated at all** — neither correct nor garbled. No invented claim ("the declaration may specify different rules" or similar): none.
- "Article 1069" is no longer named (p24 named it, correctly). Neutral.
- Against the standard: liability correct; the statement is offered as information, not as the mechanism that protects a buyer who requests it. Same level as p24: **0.60**.

## Every follow-up chip

Labels: N natural, A acceptable, O off-topic or already answered.

| Turn | Chip question | Source | Label | Note |
|---|---|---|---|---|
| 5 clarify | Outstanding balance of one unit | reading 1 | N | same as p24 |
| 5 clarify | Balance of an account | reading 2 | N | same |
| 5 clarify | Balance of a fund | reading 3 | N | same |
| 5 clarify | Balance of an invoice | reading 4 | A | same; weakest reading |
| 7 tear-down | Are main walls of buildings considered common portions? | passage [2], art. 1044, tier 2 | **N** | The thread the answer leaves out (bearing walls); answerable from art. 1044. |
| 8 buyer | When am I bound to pay the common expenses for a condo unit? | passage [2], art. 1069 para. 2, tier 1 | **A** | The 15-day excerpt is offered again and this chip comes from it. Wording lost "as a prospective acquirer" (p23), so it reads as half answered ("at the time of acquisition"). |
| 8 buyer | How is the statement of common expenses adjusted for a condo unit? | passage [2], art. 1069 para. 3, tier 1 | **A, borderline O** | Same chip as p24; a technical detail. |
| 8 buyer | What must a seller provide regarding the condition of a condo unit? | passage [2], art. 1068.1, tier 2 | **N** | Same thread as p24's chip. |

- Totals: 8 chips — 5 N, 3 A, 0 O (p24: 8 — 5 N, 3 A, 0 O). Legal chips: 4 — 2 N, 2 A (p24: 4 — 2 N, 2 A).
- Tear-down: 1 proposed, 1 kept. Excerpts offered: art. 1044, art. 1046 (passage [2]), art. 1064, art. 1064.1 (passage [4], `question_supported: false`). The writer declined the three off-thread ones, correctly.
- Nothing was offered from passage [3] (art. 1098 on the destination, art. 1100). Cause not traced; the "already told" word-share test is the likely one.
- Buyer: 3 proposed, 3 kept; art. 1063 excerpt (passage [1]) declined, correctly.
- Data chips (turns 0, 1, 3, 6, 9, 10, 11, 12): identical to p24, key for key and label for label.

## Citation cards (action side)

- `referenced_files` for tear-down citation [3] carries `article_no: 1100` and the art. 1100 text as its `passage`, while the claim it backs is art. 1097. The card follows the search hit (chunk 113), not the article the sentence draws on (chunk 110).
- Cards [1] (art. 1063) and [2] (art. 1045) are right. p24 had the same behaviour (card "1066" behind a claim on art. 1063); it is not new, it is now the only wrong label on a correct answer.

## Lead cuts, closing drops, label-echo drops

| Turn | What the model wrote | Cut (rule) | What remains | Verdict |
|---|---|---|---|---|
| 1 roster | "Here are the members of your team, Novo Gestion, along with their roles:" | whole lead, `restatement` | "523 members." + table + "Showing 25 of 523" | **GOOD** |
| 9 board | "The board of directors members are listed below, but the specific role of president is not recorded in the available data." | whole lead, `not_recorded` | "37 board members across 12 unions. Not recorded: Board role." + table | **HARMFUL (mild)** — the sentence is true and is the plain answer to "who is the president". Same case as p23; no fact lost, score holds. |
| 11 owner-arrears | "Here are the details of the owners who are currently behind on their fees." | S2, `one_sentence` | S1 with the total + "4 units." + table | **GOOD** |

- No `closing_dropped`, no `label_echo_dropped` anywhere in the file. The tear-down closing ("In summary…") is kept, rightly.
- Team-info is the opposite case: nothing was cut and a marker was **added** (section 4).

## Security / admission

- 13/13 `security_gate` passed. 9 executed queries: `safe_stop: false`, `security_filter_applied: true`, `repair_attempted: false`, `precedence_violations: []`, `attempts: 1`.
- No `SecurityException`, no `admission` stage, no busy answer, no validation retry (`retried: false` on all).
- PDF turn: an `execution` stage is logged with an **empty cypher**, `row_count: 0`, `security_filter_applied: false`, 0.018 ms. Nothing ran; it is trace noise from the extra QG call, not an unscoped query.
- Unchanged watch item: the meetings fallback cypher is logged without a team predicate (`scope: "team"`).

---

# 3 — DATA-TURN DIFF VS p24

| # | Cypher | Rows (`row_count` / total) | Table columns | Facts line | Chips | Cause of any difference |
|---|---|---|---|---|---|---|
| 0 | identical | 1 | identical block | — | identical (4) | Lead text only: composition variance + patch |
| 1 | identical | 100 / 523 | Name, Email, Role | "523 members." | identical | — |
| 2 | identical | 100 / 523; export 523 | Name, Email, Role | — | — | — |
| 3 | identical | 3 | identical (5 shown) | homonym line | identical (3 record + 1) | — |
| 4 | none in p24; **empty string** in p25 | export 3 | identical (5 + "No values found for: Oldest Due.") | — | — | QG ran and returned no query (section 4); title letter case from t3's QU |
| 5 | n/a | — | — | — | identical 4 reply chips | — |
| 6 | **+ `ORDER BY balance DESC LIMIT 100`** | 3 | identical | homonym line | identical | QG variance on a prompt identical but for the date; p24's `missing LIMIT` warning is gone |
| 9 | identical | 37 | Union Name, Name, Year | "37 board members across 12 unions. Not recorded: Board role." | identical (5 + export) | QG prompt regained REQUESTED ATTRIBUTES (QU concepts no longer empty); query unchanged |
| 10 | `ORDER BY m.start_date` (p24 `… ASC`) | 0 → 3 fallback | identical | — | identical (3 + Meetings) | QG variance; `clarified_question` wording differs |
| 11 | identical after whitespace (one line instead of six) | 4 | identical (6) | "4 units." | identical (4 + report) | QU dropped Union from `entity_types`; Union card moves from seed to tenant tier (QG −674 tokens) |
| 12 | identical | 1 (4 shown) | identical | — | identical | QU concept order; one attribute line moved |

- `data_sample` is byte-identical on all 13 turns. Stage data (execution, export, empty-result check, clarity check) is identical on every data turn apart from timings, t6's confidence / warning and t11's entity order.
- **Schema cards:** every QG prompt difference is the date line, a QU-derived line (clarified intent, requested attributes) or a card changing tier / order because QU's entity list changed (t11 only). On turns 0, 1, 2, 6, 10, 12 the card text is byte-identical; on 3 and 9 the only additions are the QU-derived blocks. Consistent with "cards byte-identical".
- Nothing in the data path reads the removed parts: `file_context` is `skipped: question_type` on all 10 data / export turns; no reranker or fusion stage existed in p24 either.

**Verdict: no data answer, row count, column, facts line or chip changed. Removals: no visible effect.**

---

# 4 — REGRESSIONS VS p24, WITH TRACED CAUSE

| Turn | Delta | Cause class | Evidence |
|---|---|---|---|
| team-info, answer | **−0.10** | **Composition variance crossing a deterministic patch.** Not removals, not the document map. | Composition prompt byte-identical to p24. The model wrote the e-mail into the lead with "which is your own email"; the validator did not read that as a marker (`self_reference_unmarked`), and the patch inserted "(that's you — your own contact details)" beside it. In p24 the lead held no e-mail and the guard stopped at `table`. |
| PDF turn, cost | no score change; **+1 call, +7,212 tokens, +1.2 s** | **QU variance → the recorded-result shortcut's "unknown" branch.** Not document work. | QU returned `entity_types: []`, `property_concepts: []`, `search_query: ""`, **`subject_label: ""`** (p24: "Unit 310 balance"). `PreviousResultExport::asksNothingNew` needs a subject: `sameVariant('', …)` fails and `namesNothing` returns false on an empty label ("a turn without a subject is not judged"). So QG ran (7,202 in / 46 out), returned no query, and the export used the recorded result anyway. QU prompt differs from p24 by one capital letter ("Unit 310 Balance"). **The call was not needed**: answer and payload are identical. |
| board, lead cut | no score change | Rule blind spot, as p23 | The model's lead was true this run; `not_recorded` cut it. Composition prompt byte-identical to p24, where the lead was false. |
| buyer, statement sentence | no score change | Composition variance | The advice sentence lost its [n]. |
| tear-down, citation card [3] | no score change | Pre-existing card rule | Card shows art. 1100 for a claim on art. 1097. |

Movement in the other direction:

- **Tear-down +0.20 / +0.05.** Caused by the gated design: QU output is byte-identical to p24, so the reader, the passage order and the block are the only changes on the turn.
- **Buyer action +0.05.** The 15-day excerpt is offered again. The answer no longer contains "provide", so art. 1069 para. 2 falls back under the "already told" line. Variance in the answer, same margin p23 and p24 flagged; not an effect of the gate.
- **Board QU**: `property_concepts` is `[board, president]` again (p24: empty).
- **Ambiguous t1**: the query has its ORDER BY / LIMIT back.

**The chunk-1 difference (18 calls / 88.9k vs 17 / 81.6k):** turn 4 (PDF), stage `query_generation`, 7,248 tokens. The other six turns of the chunk differ by +53 tokens in total. No reader call, no `document_map` data, no probe on any data turn.

---

# 5 — TOKENS, CALLS AND LATENCY VS p24

Tokens, summed per turn:

| Stage | p24 (calls) | p25 (calls) | Delta |
|---|---|---|---|
| understanding | 68,313 (13) | 68,257 (13) | −56 |
| query_generation | 62,214 (9) | 69,207 (10) | **+6,993** (PDF turn +7,248; board +382; owner-arrears −674; rest within ±28) |
| composition | 33,709 (10) | 32,399 (10) | **−1,310** (tear-down −1,388; buyer +59; team-info +29) |
| search_probes | 1,669 (2) | — | −1,669 |
| passage_selection | 8,058 (2) | — | −8,058 |
| document_map_reader | — | 12,170 (2) | +12,170 |
| clarity_check | 1,171 (1) | 1,171 (1) | 0 |
| follow_ups | 2,391 (2) | 2,339 (2) | −52 |
| **Total** | **177,525 (39)** | **185,543 (38)** | **+8,018 (+4.5%)** |

- Input 173,147 → 181,347; output 4,378 → 4,196.
- Without the PDF turn's unneeded call: 178,295 in 37 calls, +0.4% on p24.
- Document retrieval calls: 9,727 tokens in 4 calls → 12,170 in 2 calls (+25% tokens, half the calls).
- Knowledge turns: tear-down 17,523 → 17,224 (5 → 4 calls); buyer 17,445 → 18,803 (5 → 4 calls). Together +3.0%. The open gate pays for itself on tear-down through a shorter composition prompt (4 passages, one of them a single article).

Latency:

| Stage | p24 sum / mean | p25 sum / mean |
|---|---|---|
| understanding | 70.7 s / 5.44 s (max 6.5 s) | 69.3 s / 5.33 s (max 6.5 s) |
| file_context (2 knowledge turns) | 12.0 s (6.42 s, 5.59 s) | **9.5 s (5.02 s, 4.45 s)** |
| of which reader call | — (probe 4.6 s + selection 2.9 s) | 4.8 s (2.25 s, 2.55 s) |
| query_generation | 22.2 s / 2.47 s over 9 | 24.1 s / 2.41 s over 10 (22.3 s without the PDF call) |
| execution | 0.86 s / 95 ms over 9 | **4.06 s / 452 ms over 9** |
| composition (13 stages) | 18.2 s | **23.1 s** (export turns 0.20 s → 2.00 s with no LLM call; tear-down 3.54 → 4.15 s; buyer 2.53 → 2.75 s) |
| follow_ups (2 turns) | 3.43 s | 3.86 s |
| clarity_check | 1.96 s | 2.28 s |
| **pipeline** | **129.5 s / 9.96 s, median 9.79 s, max 17.3 s** | **136.3 s / 10.49 s, median 9.96 s, max 17.6 s** |

- +6.8 s overall: composition +4.9 s, execution +3.2 s, QG +1.9 s (the PDF call), file_context −2.5 s, understanding −1.4 s.
- Knowledge turns end to end: tear-down 17.3 → 17.6 s, buyer 14.7 → 14.4 s. The 2.5 s saved in file_context is spent again in composition and follow-ups.
- **Execution is 4.7 times slower on identical queries and row counts** (e.g. roster 114 → 847 ms; the same 310 query took 121 ms on t3 and 500 ms on t6). The export turns' composition, which re-runs the recorded query, slowed by the same order. The trace does not say why; a graph that lost 93,912 nodes and may have been restarted, or a busy machine, are candidates. Worth one check outside the trace.

---

# 6 — WHAT TO DO NEXT (short)

1. **Tear-down, to move from 0.70 to a safe pass:** have the block ask the composer to apply the category rule to the user's stated facts (a wall between two rooms of one unit), and name every article of the classification pick's passage that decides the category (art. 1044 with art. 1045).
2. **Buyer 15 days:** unchanged from p23 / p24; the gate does not touch it.
3. **PDF shortcut:** an export follow-up whose QU output is entirely empty (no entity, no concept, no label) falls to "unknown" and pays a QG call. 7.2k tokens when it happens.
4. **Team-info patch:** the self-reference validator does not recognise "which is your own email" as a marker and doubles it.
5. **Citation cards:** label the card with the article the cited sentence draws on, not the search hit's.
6. **Gate margin on buyer:** closed because of a neighbour window; a tangential `general_rule` pick outside the search's passages would open it.
7. **Execution latency:** check the graph outside the eval.
