# Grade: p26-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p26-1001-A.json`, run 2026-10-07 16:43–16:50 -04:00. ai `badd2f1` + 677 dirty files (p25: 666). config_sha1 `bc70a847…` (p25 `03be375a…`; the entity configs changed in removal round 3).
- **Models:** unchanged (QU / composition gpt-4o-mini, QG gpt-4o).
- **Harness (summed from the rows):** 13/13 `ok`, 21/21 gates passed, 0 void, 0 infra; `errors`, `fallbacks`, `context_errors` empty on all 13. Top-level counters (`total_rows: 7`, `usage_total` 81,608 / 17 calls) describe the first chunk only.
- **Rubric / strictness:** identical to p20–p25. Pass >= 0.70, partial 0.40–0.69, fail < 0.40. Follow-up chips are scored in the action column.
- **Not verified:**
  - Nothing was executed against the graph; no phpunit, no artisan, no git; no chip clicked, no file opened.
  - Single run. "Variance" below means the prompt of that call is byte-identical to p25 (or differs only by the date line) and the output differs.
  - Two of the hardening items are not exercised by Part A: the map turn decided per turn (both law conversations have one turn) and "an open gate never drops an article the user named" (no question names an article).
  - Why the scope block changed on the supplier turn is inferred from the QU text change, not traced in code.
  - Graph cache state cannot be read from the trace (section 5).

---

# 1 — GRADING

## Headline

- **Answer mean 0.865** (p25 0.846, **+0.019**). **Action mean 0.885** (p25 0.885, =).
- **Counts 13 pass / 0 partial / 0 fail** (p25 12 / 1 / 0). First run with no partial. Two of the passes are thin: buyer 0.70 (on the line), tear-down 0.75.
- **Three answers moved, all up:** team-info 0.75 → 0.85 (back to its p24 form), tear-down 0.70 → 0.75, buyer 0.60 → 0.70.
- **Removal round 3: no visible effect.** One of the seven changed cards reaches a Part A prompt (ContactDirectory, on the Excel turn); the query it produced is character-identical. Rows, `data_sample`, table columns, facts lines and chips are identical on all 11 data / export / clarify turns.
- **Document-map hardening works as described on the turn that shows it:** the analysis block holds only role labels, article numbers and passage numbers; passages are ordered governing rules first; art. 1097 now stands alone as passage [3] and its citation card is right.
- **No score regression.** One call fewer than p25 (the PDF turn no longer pays a generation call), 3.0% fewer tokens, 13 s less pipeline time.

## Per-turn table

| # | Turn | p24 ans / act | p25 ans / act | p26 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.85 / 0.90 | 0.75 / 0.90 | **0.85 / 0.90** | pass (**+0.10** / =) | "Your team, Novo Gestion, is a management company with 523 members. Here are the details:" + the five-line block with "(that's you)" on the e-mail. No e-mail in the lead, so no double marker: `violations_first: []`, `self_reference_guard: table` (p25: `self_reference_unmarked`, patched). Composition prompt byte-identical to p25. Same cypher, block, four chips. |
| 1 | roster t0 | 0.85 / 0.85 | 0.85 / 0.85 | **0.85 / 0.85** | pass (=) | Final answer byte-identical. Same lead cut (`restatement`), cypher, 100 / 523 rows, chips. |
| 2 | roster t1 Excel | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | Answer and 523-row payload identical; cypher identical. The only Part A prompt that shows a removed card line (section 3). |
| 3 | 310 t0 | 0.95 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass (=) | Answer byte-identical. Same cypher, 3 rows, chips. QU left `clarified_question` empty again (as p24); the `<clarified_intent>` block is gone from the QG prompt (−32 tokens). |
| 4 | 310 t1 PDF | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | Same text and 3-row payload; title "Unit 310 balance". **One call, 5,374 tokens** (p25: two calls, 12,587): QU returned a subject this run, the stage is `recorded_result_export` with `generation_skipped: true`. |
| 5 | ambiguous t0 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | Byte-identical question and four reply chips. Clarity-check prompt identical, 1,171 tokens. |
| 6 | ambiguous t1 | 0.95 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass (=) | "…all have an outstanding balance of $0.00…" (p24 wording). The query lost `ORDER BY balance DESC LIMIT 100` again; same 3 rows, table, homonym line, chips. |
| 7 | tear-down t0 | 0.50 / 0.75 | 0.70 / 0.80 | **0.75 / 0.80** | **pass (+0.05 / =)** | Gate OPEN. Three rules stated, each cited to the passage that holds it and now named by article (1063, 1045, 1097 — all correct). p25's misapplied sentence is replaced by a true conditional. Still no sentence on a wall inside her own portion, no main walls, no direct lead (section 2). Citation card [3] now shows art. 1097 (p25: 1100). Two chips: one natural, one tangential. |
| 8 | buyer-arrears t0 | 0.60 / 0.80 | 0.60 / 0.85 | **0.70 / 0.85** | **pass (+0.10 / =), on the line** | Gate CLOSED. Liability at acquisition with interest, "Article 1069" named. The statement and its 15 days are stated and cited [1], with the "only" moved: "you will only be responsible for the fees listed in that statement". The closing hedge "unless otherwise specified in the documentation provided by the syndicate" is not what art. 1069 says. Same pattern as p12 (0.70) and p18 / p20 (0.75). Three chips: two natural, one weak. |
| 9 | board t0 | 0.75 / 0.75 | 0.75 / 0.75 | **0.75 / 0.75** | pass (=) | Final answer byte-identical. The model's true sentence on the president was cut again (HARMFUL, mild). Same cypher, 37 rows, 5 record chips + export. |
| 10 | board t1 meetings | 0.85 / 0.95 | 0.85 / 0.95 | **0.85 / 0.95** | pass (=) | "There are currently no upcoming meetings scheduled for your team. Here are the most recent meetings that have taken place:". The query now also collects the agenda; 0 rows, same fallback, same 3 meetings, same chips. Agenda half still unaddressed in the answer. |
| 11 | owner-arrears t0 | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | "The total balance for owners behind on fees in SDC Carré Soho Phase 3 is $1,211.48." + "4 units." + same four rows. Query lost `LIMIT 100`, otherwise identical. Same `one_sentence` cut. |
| 12 | supplier t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | Answer byte-identical ("you owe … your suppliers" voice slip unchanged). Cypher identical. |

## Means

| Run | Answer, all 13 | Action, all 13 | Answer sum | Action sum |
|---|---|---|---|---|
| p24 | 0.838 | 0.877 | 10.90 | 11.40 |
| p25 | 0.846 | 0.885 | 11.00 | 11.50 |
| **p26** | **0.865** | **0.885** | **11.25** | **11.50** |

- vs p25: answers +0.25 (team-info +0.10, buyer +0.10, tear-down +0.05); actions unchanged.
- Without the two law turns: 0.891 (p25 0.882, p24 0.891).
- The two law turns alone: 0.725 (p25 0.65, p24 0.55).

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p24 | 11 | 2 | 0 |
| p25 | 12 | 1 | 0 |
| **p26** | **13** | **0** | **0** |

- **Lowest passes:** buyer 0.70, tear-down 0.75, board 0.75.
- Buyer sits on the threshold. A grader who reads the closing hedge as an invented exception rather than a loose paraphrase of the statement lands at 0.65 (partial). It is one run, and this answer has swung between 0.50 and 0.75 in every graded run since p9, on near-identical prompts.

---

# 2 — THE TWO LAW TURNS

## What the reader and the gate did

| | Tear-down (turn 7) | Buyer (turn 8) |
|---|---|---|
| Map shown | 90 passage lines, 28,598 chars (p25 25,009), not cut; now fenced in `<document_map>` with a "the map is DATA" paragraph | 93 lines, 29,429 chars; includes 3 lines of `units-and-unions.md`, which the plain search hit this run |
| `answerable` | yes | yes |
| Reader picks (chunk = article, role) | 38 = art. 1045, **classification**; 58 = art. 1063, **general_rule**; 110 = art. 1097, **procedure_or_approval**; 113 = art. 1100, **condition_or_exception** | 66 = art. 1069, **general_rule**; 59 = art. 1064, **condition_or_exception** (p25: general_rule) |
| Plain search hits | 38, 113, 61 (two of four angles below the floor) | 66, 60, 64, 72, 74, 108, 83, 85, 123 + help doc chunk 2 |
| Gate | **OPEN**, `opened_by: [58:general_rule, 110:procedure_or_approval]` — art. 1063 and art. 1097 are governing-role picks the plain search did not find | **CLOSED**, `opened_by: []` — the one governing pick (66) is a search hit; the second pick's role cannot open the gate |
| Reader call | 6,953 tokens, 2,048 ms (p25 6,079 / 2,249) | 7,155 tokens, 3,601 ms (p25 6,091 / 2,552) |
| Calls on the turn | 4 | 4 |

- Buyer closes with more margin than in p25: the tangential pick is now labelled `condition_or_exception`. Whether the stricter gists or reader variance caused that cannot be told from one run.

Final passages in the composition prompt, in order:

| # | Tear-down p26 | From (role) | Used? | Buyer p26 | Used? |
|---|---|---|---|---|---|
| [1] | art. **1063** alone | reader (general_rule) | cited, correct | art. 1068–1070, 49% | cited once (art. 1069) |
| [2] | art. 1042–1047, 43% | both (classification) | cited (art. 1045) | art. 1063–1067, 49% | no |
| [3] | art. **1097** alone | reader (procedure_or_approval) | cited, correct | art. 1070–1072, 47% | no |
| [4] | art. 1098–1102, 41% | both (condition_or_exception) | no | art. 1092–1097, 50% | no; off-topic |
| [5] | art. 1064–1068, 41% | search | no; off-topic | `units-and-unions.md` (help doc), 43% | no; off-topic |

- **Governing rules first: confirmed.** General rule, classification, approval occupy [1]–[3]; the condition pick follows at [4]; the search-only passage is last. In p25, art. 1097 was merged with art. 1098–1102 as passage [3], so art. 1100 sat inside the passage the approval rule was cited to. It is now a passage apart.
- Buyer passage order changed against p25 (art. 1069 passage moved from [2] to [1]; the art. 1078–1082 passage was replaced by the help doc). Cause: QU variance — `clarified_question` and `search_tags` differ on a byte-identical QU prompt, which changes two of the four search angles. The frame of the composition prompt outside the passages is byte-identical to p25.

## The analysis block as printed (tear-down composition prompt)

```
=== QUESTION ANALYSIS ===

The rules that govern the question, in order:
1. the general rule — art. 1063, in passage [1]
2. what the thing concerned is / which category it falls in — art. 1045, in passage [2]
3. who approves or decides, and how — art. 1097, in passage [3]

Build the answer on these rules, in this order: state each one with its own conditions, as its passage words them, and cite its passage. When the answer turns on which category the thing concerned falls in, say what decides the category instead of giving a flat yes or no.
```

- **No free text: confirmed.** p25's line "What the question is really about: …" is gone. The reader's `issue` sentence is stored in the stage data only; a search of both composition prompts for its wording finds nothing. No document heading appears in the block.
- The § lines (chapter › division) are still printed on each passage's header line in RELEVANT FILE CONTENT, outside the `<retrieved_document>` tags, as in p25.
- Buyer: no QUESTION ANALYSIS block (gate closed).

## Tear-down — claim by claim

| # | Claim in the answer | Passage text it rests on | Verdict |
|---|---|---|---|
| 0 | "To determine whether you can tear down the wall…, we need to consider a few legal aspects from the Quebec Civil Code regarding co-ownership" | — | Neutral preamble. No verdict; not a direct answer either. |
| 1 | "**Disposal of Private Portions**: According to Article 1063, each co-owner has the right to dispose of their fraction and enjoy their private portion, provided they comply with the by-laws and do not impair the rights of other co-owners or the destination of the immovable [1]." | art. 1063: "has the disposal of his fraction; he has free use and enjoyment of his private portion … provided he complies with the by-laws of the immovable and does not impair the rights of the other co-owners or the destination of the immovable" | **Supported**, right passage, all three limits. Weaker than p25: "free use" is dropped (only "enjoy" remains) and the heading puts the weight on disposal, which is not the right at stake. |
| 2a | "Article 1045 states that partitions or walls that separate a private portion from a common portion or from another private portion are presumed to be common." | art. 1045 | **Supported.** The qualifier "not part of the foundations and main walls" is dropped. |
| 2b | "This means that if the wall you wish to remove is classified as a common wall, you would need approval from the co-owners to proceed with the demolition [2]." | approval is art. 1097, in [3] | **True as a conditional; cited to the wrong passage** (the next point cites it correctly). No longer misapplied: p25 said the wall "may not be solely yours to modify". It still does not test her facts against the rule. |
| 3a | "Article 1097 specifies that decisions regarding alterations to common portions require the approval of co-owners representing three-quarters of the votes of those present or represented at a meeting." | art. 1097, 2° | **Supported**, precise majority. |
| 3b | "Therefore, if the wall is deemed common, you will need to obtain this approval before making any changes [3]." | art. 1097 | Supported as a conditional. |
| 4 | "whether you can tear down the wall depends on its classification as either a private or common portion. If it's a common wall, you will need to seek approval from the other co-owners." | [1]–[3] | Supported. **Only the common branch is completed.** |

- Ungrounded claims: **0**. Misapplied rules: **0** (p25: 1). Article numbers named: three, all correct and each in the passage cited. None invented.
- The three points asked for:
  1. Free use of the private portion and its limits (art. 1063) — **stated, limits complete, "free use" weakened to "enjoy"**.
  2. Separating walls presumed common — **stated for art. 1045; art. 1044 (main walls) is still not in the answer**.
  3. Three-quarters of the votes for alterations to common portions (art. 1097) — **stated**.
- Art. 1100: **not used**, although its passage [4] is in the prompt. Art. 1066: not used.
- **Does it say plainly what holds if the wall is inside her own portion? No.** The private branch is still left for the reader to assemble from point 1.
- Citation cards: [1] art. 1063, [2] art. 1045, [3] art. 1097 — all right. p25's wrong card (art. 1100 behind the art. 1097 claim) is fixed by art. 1097 being its own passage.

**What separates this answer from p25's 0.70 (+0.05):**
- The misapplied sentence is gone. That sentence was the reason p25 sat on the threshold.
- The three rules are named by article, correctly, and every card matches.
- Against that: rule 1 is worded more weakly, the approval claim in point 2 carries the wrong passage number, and the answer is longer (1,345 characters; p25 1,190).

**What still separates it from 0.80 and above (unchanged list, minus item 1 of p25):**
1. No sentence says that a partition wholly inside her private portion, not a main wall, is hers to alter within the limits of point 1.
2. Her facts are not applied: a wall between two rooms of one unit separates neither a private portion from a common one nor two private portions, so the art. 1045 presumption does not reach it.
3. Main / bearing walls (art. 1044) appear only in a chip. The block still names one article for the classification role.
4. The syndicate and the declaration of co-ownership are not named as where to check.
5. No direct lead: preamble first, conclusion last.

- Cause attribution: the composition prompt changed on this turn (block without the issue line; art. 1097 split out; one more passage), so the +0.05 cannot be separated from composition variance in a single run.

## Buyer — claim by claim

| # | Claim in the answer | Passage text | Verdict |
|---|---|---|---|
| 1 | "you are responsible for paying all common expenses due for that unit at the time of acquisition. This includes any unpaid condo fees from the previous owner." | art. 1069 para. 1 | **Supported.** No marker. |
| 2 | "Article 1069 of the Quebec Civil Code states that a person acquiring a fraction of an immovable is bound to pay all common expenses due with respect to that fraction at the time of the acquisition, including interest if applicable." | art. 1069 para. 1: "…with interest, at the time of the acquisition" | **Supported**; article named correctly. "if applicable" softens "with interest". No marker. |
| 3 | "you can request a statement of the common expenses from the syndicate before purchasing, which will inform you of any outstanding fees." | art. 1069 para. 2: "A person contemplating the acquisition … may request from the syndicate … a statement of the common expenses due" | **Supported.** |
| 4 | "If this statement is provided within 15 days of your request, you will only be responsible for the fees listed in that statement [1]." | "the prospective acquirer is bound to pay the common expenses only if the statement is provided to him by the syndicate within 15 days of the request" | **Half right.** The 15 days and the right passage are there. The "only" has moved: the text makes the 15-day delivery the condition of being bound at all; the answer turns it into a cap on the amount. "Only the fees listed" is an inference. The reverse — no statement within 15 days, not bound — is not said. |
| 5 | "In summary, yes, you would be responsible for the previous owner's unpaid condo fees unless otherwise specified in the documentation provided by the syndicate." | — | **Not what the passage says.** The statement does not "specify otherwise"; it is a loose restatement of claim 4. Same family as p12's "unless you have obtained a statement … that specifies otherwise" and p18's "unless you obtain a statement indicating otherwise". |

- Gate: **closed**. No analysis block. Frame identical to p25.
- Liability at acquisition: **correct**. The statement: **stated as a mechanism, cited**. The 15-day protection: **stated, imprecise** — not absent (p22–p25), not garbled into a false rule.
- Invented claim: none of the kind the gate was built against ("the declaration may specify different rules"). One loose hedge (claim 5), which a strict reader may count.
- One citation marker in the whole answer, on the right sentence; card art. 1069.
- Score against precedent: the same 15-day sentence earned 0.75 in p18 and p20; a distorting closing brought p12 to 0.70. Here: the sentence plus a vaguer closing ("documentation"), article named → **0.70**.
- Cause of the change from p25: art. 1069 is now passage [1] (QU variance moved it), plus composition variance. Not the document-map hardening: the gate is closed and the frame is unchanged.

## Every follow-up chip

Labels: N natural, A acceptable, O off-topic or already answered.

| Turn | Chip question | Source | Label | Note |
|---|---|---|---|---|
| 5 clarify | Outstanding balance of one unit | reading 1 | N | same as p25 |
| 5 clarify | Balance of an account | reading 2 | N | same |
| 5 clarify | Balance of a fund | reading 3 | N | same |
| 5 clarify | Balance of an invoice | reading 4 | A | same; weakest reading |
| 7 tear-down | Are main walls of buildings considered common portions? | passage [2], art. 1044, tier 2 | **N** | The thread the answer leaves out; same chip as p25. |
| 7 tear-down | Do I have an undivided right of ownership in common portions? | passage [2], art. 1046, tier 2 | **A, borderline O** | Does not help decide the wall question. p25's writer declined this same excerpt; the follow-up prompt differs only by the answer text and the excerpt list. Writer variance. |
| 8 buyer | How is the statement of common expenses adjusted before purchase? | passage [1], art. 1069 para. 3, tier 1 | **A, borderline O** | Same thread as p25's chip; a technical detail. |
| 8 buyer | What must the seller provide to the buyer regarding the condo's condition? | passage [1], art. 1068.1, tier 2 | **N** | Same thread as p25. |
| 8 buyer | What can I request from the syndicate before buying a condo unit? | passage [1], art. 1068.2, tier 2 | **N** | New. Close to the standard's door ("how to request the statement"); answerable from art. 1068.2. |

- Totals: 9 chips — 6 N, 3 A, 0 O (p25: 8 — 5 N, 3 A, 0 O). Legal chips: 5 — 3 N, 2 A (p25: 4 — 2 N, 2 A).
- The p25 chip from the 15-day excerpt is gone, rightly: the answer now states the 15 days.
- Tear-down excerpts offered: art. 1044, art. 1046 ([2]); art. 1098 ([4]); art. 1064 ([5]) — the last two `question_supported: false` and declined. No chip on the approval process, which is the standard's example door.
- Data chips (turns 0, 1, 3, 6, 9, 10, 11, 12): identical to p25, key for key and label for label.

## Lead cuts, closing drops, label-echo drops

| Turn | What the model wrote | Cut (rule) | What remains | Verdict |
|---|---|---|---|---|
| 1 roster | "Here are the members of your team, along with their roles:" | whole lead, `restatement` | "523 members." + table + "Showing 25 of 523" | **GOOD** |
| 9 board | "Here are the board members:" | `one_sentence` | — | **GOOD** |
| 9 board | "I found the members of the board of directors, but the specific role of president is not recorded in the data." | `not_recorded` | "37 board members across 12 unions. Not recorded: Board role." + table | **HARMFUL (mild)** — a true sentence that answers "who is the president". Third run in four. No fact lost; score holds. |
| 11 owner-arrears | "Here are the details of the owners who are currently overdue on their fees." | S2, `one_sentence` | S1 with the total + "4 units." + table | **GOOD** |

- No `closing_dropped`, no `label_echo_dropped` anywhere in the file. Tear-down's "In summary…" and buyer's "In summary…" are kept; buyer's would have been better dropped, but no rule covers it.

## Security / admission

- 13/13 `security_gate` passed. 9 executed queries: `safe_stop: false`, `security_filter_applied: true`, `repair_attempted: false`, `precedence_violations: []`, `attempts: 1`.
- No `SecurityException`, no `admission` stage, no busy answer, no generic error answer ("I encountered an issue"), no validation retry.
- The PDF turn's empty `execution` stage of p25 is gone (no generation call, so nothing to log).
- Unchanged watch item: the meetings fallback cypher is logged without a team predicate (`scope: "team"`).

---

# 3 — DATA-TURN DIFF VS p25

QU prompts are byte-identical to p25 on 12 of 13 turns (turn 4 differs by one letter, the previous subject's label). Every QU difference below is therefore model variance.

| # | QU fields | QG prompt (cards and other lines) | Cypher | Rows | Table columns / facts line / chips | Cause of any difference |
|---|---|---|---|---|---|---|
| 0 | identical | date only | identical | 1 | identical block, 4 chips | Lead text: composition variance (prompt byte-identical) |
| 1 | identical | date only | identical | 100 / 523 | Name, Email, Role; "523 members."; identical | Model lead wording differs; cut by the same rule; final answer identical |
| 2 | identical | **ContactDirectory card lost `(ContactDirectory)-[:CONTACT_TYPE]->(ContactType)`** (−14 tokens) | identical | 100 / 523; export 523 | identical | **Card change (removal round 3). No effect.** The type is still on the card as the `contact_type_id` value list |
| 3 | `clarified_question` empty (p25 filled); label "Unit 310 balance" | `<clarified_intent>` block gone (−32 tokens) | identical | 3 | identical; homonym line; 3 record chips + report | QU variance |
| 4 | filled: `entity_types [Unit, Invoice]`, concepts, `subject_label` (p25: all empty) | **no QG call** (p25: one, 7,248 tokens) | none | export 3 | identical; title lower-case "b" | QU variance → the recorded-result shortcut took its normal branch |
| 5 | `entity_types [Unit, Invoice, Transaction, Fund]` (p25 empty); tags differ | n/a; clarity-check prompt identical | n/a | — | same 4 reply chips | QU variance; five entities selected instead of three, nothing downstream reads them |
| 6 | identical | date only | **lost `ORDER BY balance DESC LIMIT 100`** | 3 | identical; homonym line; chips identical | QG variance (the p24 form); "missing LIMIT" warning back, confidence 0.9 → 0.8 |
| 9 | identical | date only | identical | 37 | Union Name, Name, Year; same facts line; 5 chips + export | Model lead differs, both sentences cut; final answer identical |
| 10 | `search_tags` differ | date only | **now `WITH m ORDER BY m.start_date LIMIT 1 OPTIONAL MATCH (s:Schedule)-[:BELONGS_TO_MEETING]->(m) … collect(s.title) AS agenda`** | 0 → 3 fallback | identical; 3 record chips + Meetings | QG variance. A better query (it asks for the agenda); no visible effect because no meeting is upcoming |
| 11 | identical | date only | **lost `LIMIT 100`** | 4 | 6 columns; "4 units."; 4 chips + report | QG variance; warning only |
| 12 | concept order; "debts" for "debt"; label plural | one attribute line moved; **detected-scope block changed** (`FOR_AUTH_USER` + `FROM_CENTRAL_LIST` → `FOR_SUPPLIER` + `FOR_AUTH_USER`); clarified-intent wording | identical | 1 (4 shown) | identical | QU variance (the scope block follows the QU search text — inferred) |

- `data_sample` is byte-identical on all 13 turns.
- **Schema cards:** of the seven cards that lost a line, only ContactDirectory is selected on any Part A turn (the Excel turn). Account, BillDetail, Component, InvoiceDetail, Maintenance and File appear in no Part A generation prompt. No removed label is named in any p26 generation prompt. On turns 0, 1, 3, 6, 9, 10, 11 the card text is byte-identical to p25.
- Composition prompts are byte-identical to p25 on turns 0, 1, 3, 6, 9, 10, 11; turn 12 differs by the clarified-question wording.
- `file_context` is `skipped: question_type` on all 10 data / export turns. No reader call, no document-map data on any data turn.

**Verdict: no answer got worse or better because of the removal.** The one card change that reaches a prompt produced the same query. Part A cannot speak for the six cards it never selects; the deleted vector collections were read by nothing on these turns.

---

# 4 — REGRESSIONS VS p25, WITH TRACED CAUSE

No answer or action score fell. Items below are changes for the worse that did not move a score.

| Turn | What | Cause class | Evidence |
|---|---|---|---|
| tear-down, chips | A tangential second chip (art. 1046) | Writer variance | Same excerpt offered in p25 and declined; follow-up prompt differs only by the answer text and the excerpt list. |
| tear-down, rule 1 wording | "dispose of their fraction and enjoy" — "free use" lost | Composition variance on a changed prompt | Passage [1] text identical to p25. |
| tear-down, point 2 | Approval claim cited [2]; the rule is in [3] | Composition variance | Point 3 cites it correctly. |
| buyer, closing | "unless otherwise specified in the documentation provided by the syndicate" | Composition variance | Frame byte-identical to p25; seen before in p12 and p18. |
| board, lead cut | True sentence cut by `not_recorded` | Rule blind spot, as p23 and p25 | Composition prompt byte-identical to p25. |
| 6 and 11, query | `LIMIT` dropped | QG variance | Prompts identical but for the date; row counts unchanged (3, 4). |
| roster, execution | 2,709 ms (p25 847, p24 114) | Not readable from the trace | Section 5. |
| reader | +1,938 tokens over two calls (+15.9%) | Regenerated gists are longer | Map 25,009 → 28,598 chars on tear-down. |

Movement in the other direction:

- **Team-info +0.10.** Composition variance: the prompt is byte-identical to p25; the model did not put the e-mail in the lead, so the guard had nothing to patch. The validator weakness p25 named (it does not recognise "which is your own email" as a marker) is untested this run, not shown fixed.
- **Buyer +0.10.** QU variance moved the art. 1069 passage to [1]; the composer used the 15-day paragraph. Not an effect of the hardening.
- **Tear-down +0.05.** The block lost its free-text line and art. 1097 became its own passage; the misapplied sentence is gone and the card is right. The card fix is structural (it follows from the passage split); the answer gain is one run.
- **PDF turn −1 call, −7,213 tokens.** QU returned a subject. The p25 defect (an export follow-up whose QU output is entirely empty pays a generation call) is untested this run, not shown fixed.
- **Meetings query** now asks for the agenda.

---

# 5 — TOKENS, CALLS AND LATENCY VS p25

Tokens, summed per turn:

| Stage | p25 (calls) | p26 (calls) | Delta |
|---|---|---|---|
| understanding | 68,257 (13) | 68,309 (13) | +52 |
| query_generation | 69,207 (10) | 61,912 (9) | **−7,295** (PDF turn −7,248; rest −47, of which the removed card line −14) |
| composition | 32,399 (10) | 32,031 (10) | −368 (buyer −443, tear-down +93, team-info −28) |
| document_map_reader | 12,170 (2) | 14,108 (2) | **+1,938** (tear-down +874, buyer +1,064) |
| clarity_check | 1,171 (1) | 1,171 (1) | 0 |
| follow_ups | 2,339 (2) | 2,503 (2) | +164 |
| **Total** | **185,543 (38)** | **180,034 (37)** | **−5,509 (−3.0%)** |

- Input 181,347 → 175,780; output 4,196 → 4,254.
- vs p24 (177,525 in 39 calls): +1.4% tokens, two calls fewer.
- Knowledge turns: tear-down 17,224 → 18,290 (+6.2%); buyer 18,803 → 19,492 (+3.7%). The increase is the longer map.

Latency:

| Stage | p25 sum / mean | p26 sum / mean |
|---|---|---|
| understanding | 69.3 s / 5.33 s (max 6.5 s) | 65.3 s / 5.02 s (max 5.9 s) |
| file_context (2 knowledge turns) | 9.5 s (5.02 s, 4.45 s) | 10.1 s (4.32 s, 5.81 s) |
| of which reader call | 4.8 s (2.25 s, 2.55 s) | 5.6 s (2.05 s, 3.60 s) |
| query_generation | 24.1 s / 2.41 s over 10 | 19.5 s / 2.17 s over 9 |
| execution | 4.06 s over 9, median 500 ms | **4.26 s over 9, median 191 ms** |
| composition (13 stages) | 23.1 s | **18.3 s** (export turns 2.00 s → 0.41 s; tear-down 4.15 → 3.43 s; buyer 2.75 → 2.66 s) |
| follow_ups (2 turns) | 3.86 s | 3.50 s |
| clarity_check | 2.28 s | 1.68 s |
| **pipeline** | **136.3 s / 10.49 s, median 9.96 s, max 17.6 s** | **123.1 s / 9.47 s, median 8.75 s, max 15.3 s** |

- −13.2 s overall: composition −4.7 s, QG −4.6 s (one call fewer and faster calls), understanding −4.0 s, file_context +0.7 s, execution +0.2 s.
- Knowledge turns end to end: tear-down 17.7 → 15.3 s, buyer 14.4 → 15.3 s (the reader call took 1.0 s longer).

**Is graph execution back to normal? Mostly, not cleanly.**

| Query (in run order) | p25 | p26 |
|---|---|---|
| chunk 1: team-info | 629 ms | 350 ms |
| roster | 847 ms | **2,709 ms** |
| Excel | 576 ms | 275 ms |
| 310 | 121 ms | 141 ms |
| 310 again | 500 ms | 63 ms |
| chunk 2: board | 629 ms | 191 ms |
| meetings | 251 ms | 154 ms |
| owner-arrears | 77 ms | 333 ms |
| supplier | 434 ms | 39 ms |

- Six of nine queries are faster than in p25; the median fell from 500 to 191 ms. The last queries of each chunk (63 ms, 39 ms) are in the warm range measured after p25 (22–55 ms).
- The sum is unchanged because of one query: the roster took 2.7 s, its slowest in three runs (p24 114 ms). Without it the other eight total 1.55 s (mean 193 ms), still about twice p24's mean of 95 ms.
- The pattern — slow early in the run, normal late — fits a cache that was cold again after round 3 deleted 111,486 nodes and 41,463 relationships shortly before this run. The trace cannot confirm it.
- The export turns' composition, which re-runs the recorded query, is back near p24 (0.41 s for both; p24 0.20 s, p25 2.00 s).

---

# 6 — WHAT TO DO NEXT (short)

1. **Tear-down, 0.75 → 0.80+:** unchanged from p25 — have the block ask the composer to apply the category rule to the user's stated facts, and name every article of the classification passage that decides the category (art. 1044 with art. 1045). The private branch is still unsaid.
2. **Buyer:** the 15-day sentence appears or not by variance, and when it appears the "only" is misplaced (p11, p18, p20, p26). The pass is on the line.
3. **Board lead:** `not_recorded` keeps cutting a true sentence.
4. **Untested this run, still open from p25:** the team-info marker patch and the export follow-up with an empty QU subject. Neither path was taken.
5. **Roster query at 2.7 s:** one warm re-run outside the eval settles whether it is cache or a real slowdown.
6. **Reader cost:** the regenerated gists add about 15% to the map (+1.9k tokens over two calls).
7. **Removal coverage:** Part A selects one of the seven changed cards. Account, BillDetail, Component, InvoiceDetail, Maintenance and File need Part B or a targeted question to be measured.
