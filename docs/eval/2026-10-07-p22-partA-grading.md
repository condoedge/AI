# Grade: p22-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p22-1001-A.json`, run at 2026-10-07T02:21-04:00. ai `badd2f1` + 606 dirty files (p21: 597). config_sha1 `9f458592…` (p21: `7902f5bf…`).
- **Models:** QU gpt-4o-mini, QG gpt-4o, composition gpt-4o-mini, follow-up writer gpt-4o (unchanged). New passage-selection call: gpt-4o-mini per `config/ai.php` (role `passage_selector`, else the QU model); the trace lists models per turn, not per stage.
- **Harness (summed from the rows):** 13/13 `ok`, 21/21 gates passed, 0 void, 0 infra, `errors`, `fallbacks` and `context_errors` empty on all 13. The top-level counters (`total_rows: 7`, `usage_total` 80,021) describe one chunk only.
- **Rubric / strictness:** identical to p20 and p21. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Follow-up question chips are scored in the action column.
- **Not verified:** nothing was executed against the graph; no phpunit, no artisan. No chip was clicked and no file was opened. Single run: every "variance" verdict rests on a prompt diff against p20 / p21, not on repeats. Which clause of `closingReadsAsContent` kept the tear-down closer was read from the code, not executed.

---

# 1 — GRADING

## Headline

- **Answer mean 0.831** (p21 0.819, **+0.012**; p20 0.831, =). **Action mean 0.881** (p21 0.881, =; p20 0.862, +0.019).
- **Counts 11 pass / 2 partial / 0 fail** (p21 11 / 1 / 1; p20 12 / 1 / 0). Tear-down leaves fail for partial, on the boundary (0.40). Buyer stays partial (0.60) and does not regain its p20 pass.
- Only the two law turns moved. Answers: tear-down +0.05, buyer +0.10 vs p21. Actions: tear-down +0.05, buyer -0.05.
- The eleven data / export / clarify turns score exactly as in p21 (answer sum 9.80, mean 0.891; p20 0.873).
- **The new passage-selection step put art. 1063 into the tear-down prompt for the first time, and the answer did not use it.** The tear-down defect has moved from retrieval to composition.

## Per-turn table

| # | Turn | p20 ans / act | p21 ans / act | p22 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.85 / 0.70 | 0.85 / 0.90 | **0.85 / 0.90** | pass (= / =) | Lead "…it is classified as a management company with a total of 523 members" (p21 "operates as"; p20's wording). Still restates three block values. Record block and "(that's you)" unchanged. Chips as p21: Team settings, Units, Meetings, Receivables report. No members door. |
| 1 | roster t0 | 0.85 / 0.85 | 0.85 / 0.85 | **0.85 / 0.85** | pass (=) | Answer byte-identical to p21. Same lead cut (`restatement`), same cypher, no ORDER BY: 3 staff rows, 22 "Unit Owner or Renter". Chips unchanged. |
| 2 | roster t1 Excel | 0.90 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | "I prepared the Excel file with all 523 rows." Same payload (`previous_result`, complete, 523 rows, Name / Email / Role). Only the link title changed case ("Team members and roles"): QU `subject_label` variance. QG still ran (section 2). |
| 3 | 310 t0 | 0.90 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass (=) | Answer byte-identical to p21. Cypher now two MATCH clauses, same predicates, same 3 rows. Lead and homonym line still say the same thing twice. |
| 4 | 310 t1 PDF | 0.90 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | Byte-identical to p21. `recorded_result_export` {generation_skipped: true, recorded_rows: 3}; QU call only (5,163 tokens). "No values found for: Oldest Due." unchanged. |
| 5 | ambiguous t0 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | Byte-identical to p20 and p21. Four reply chips, `params.question` still the bare reading. |
| 6 | ambiguous t1 | 0.90 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass (=) | Answer byte-identical to p21. Cypher gained `ORDER BY balance DESC`; same 3 rows, same chips. |
| 7 | tear-down t0 | 0.45 / 0.70 | 0.35 / 0.70 | **0.40 / 0.75** | **partial (+0.05 / +0.05 vs p21; -0.05 / +0.05 vs p20)** | Opens "You cannot tear down the wall … without following specific regulations." The flat "No" is gone, the commitment is not: no passage says this of a wall inside one unit. Art. 1045 is quoted with half its condition ("walls that separate private portions from common portions are presumed to be common") and applied to her wall by "This means that you would need to obtain approval from the syndicate and potentially the other co-owners [1]" — approval is not in [1]. Paragraph 2 gives art. 1100 with its condition and the right passage ("consent from the syndicate and possibly from your hypothecary creditors if the alteration affects the boundaries of your private portion [2]"), under a muddled premise ("if the wall is not part of the main structure"). **Art. 1063 is in passage [3] and is not used or cited.** No interior / presumed-common distinction, no majorities. Better than p21: no false general rule, art. 1100 grounded, a closing sentence that says what to do. Worse than p20: the "it depends" structure and the full art. 1045 condition are gone. No article number invented. Two chips, one of them art. 1063. |
| 8 | buyer-arrears t0 | 0.75 / 0.85 | 0.50 / 0.90 | **0.60 / 0.85** | **partial (+0.10 / -0.05 vs p21; -0.15 / = vs p20)** | Liability "with interest, at the time of the acquisition [1]" — exact to art. 1069 para. 1. The statement of common expenses is back, as advice only: "you can request a statement … which can help you understand any outstanding fees [1]". **The 15-day protection is not stated** — the p17 / p19 answer, hence their score. No summary paragraph, both paragraphs cited. One chip, from the unused half of art. 1069 para. 2; its wording reads as already answered. |
| 9 | board t0 | 0.75 / 0.75 | 0.75 / 0.75 | **0.75 / 0.75** | pass (=) | Answer byte-identical to p20 and p21. The model wrote two sentences this run, one false ("…the president is available in the records."); code cut both (section 2). President still answered only by the label. Same 5 contact chips over 5 of 12 unions, Download Excel. |
| 10 | board t1 meetings | 0.85 / 0.95 | 0.85 / 0.95 | **0.85 / 0.95** | pass (=) | "There are currently no upcoming meetings scheduled." above the same three meetings. "for your team" is gone again (as p20). Agenda half still not addressed. Same chips. |
| 11 | owner-arrears t0 | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | "The total balance for owners behind on fees in SDC Carré Soho Phase 3 is $1,211.48." + "4 units." + the same four rows. Filler second sentence cut (`one_sentence`). |
| 12 | supplier t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | "You currently owe a total of $11,493.17 to your suppliers, with 4 open bills." Byte-identical to p20. Voice slip unchanged. Chip: Payables. |

## Means

| Run | Answer, all 13 | Action, all 13 | Answer sum | Action sum |
|---|---|---|---|---|
| p20 | 0.831 | 0.862 | 10.80 | 11.20 |
| p21 | 0.819 | 0.881 | 10.65 | 11.45 |
| **p22** | **0.831** | **0.881** | **10.80** | **11.45** |

- vs p21: answers +0.15 (tear-down +0.05, buyer +0.10); actions 0 (tear-down +0.05, buyer -0.05).
- vs p20: answers 0 (exports and 310 leads +0.20, tear-down -0.05, buyer -0.15); actions +0.25 (team-info +0.20, tear-down +0.05).
- Without the two law turns: 0.891 (p21 0.891, p20 0.873).

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p20 | 12 | 1 | 0 |
| p21 | 11 | 1 | 1 |
| **p22** | **11** | **2** | **0** |

- **Partial:** tear-down (0.40, on the fail boundary; calibrated on p18 0.40: half-conditioned art. 1045, no committed "No", no false rule) and buyer (0.60; same as p16 / p17 / p19, statement as advice only).
- **Lowest passes:** board 0.75.

---

# 2 — FIX VERIFICATION

| Change | Status | Evidence |
|---|---|---|
| "START WITH THE DIRECT ANSWER …" reverted | **Verified gone; outcome half obtained** | 0 occurrences in both p22 law prompts (p21: 1 each). Tear-down instruction block (from "Task:" to the end) is byte-identical to p20; buyer differs only by "[5] or [6]". Composition system prompt byte-identical across p20 / p21 / p22. Source: `GuidelinesSection.php` lines 159-165 keep the generic line with the revert comment. Buyer: the statement is back, the 15-day rule is not. Tear-down: no "No", still a committed opening; not p20's shape. |
| Passage selection | **Works as built on 2 of 2; useful add on tear-down, tangential add on buyer; the answer used neither** | Tables below. It picked exactly `max_added` = 1 on both turns, `unknown_ids: 0`. |
| `votes_below_floor` | **Verified on tear-down; not triggered on buyer** | Tear-down: `{"…CCQ_Co-ownership_EN.md": ["search_query", "question"]}` — both angles admitted nothing (`angle_chunks` empty) and voted; `question_passages: 38`; art. 1042-1047 leads as [1] at 43% relevance ahead of [2] at 73%. Buyer: every angle admitted chunks, no key. |
| Follow-up excerpts never cut mid-sentence | **Verified, 8 of 8** | Every excerpt in both writer prompts ends on a full sentence (p21: 4 of 8 ended in "…", p20: 3 of 8). Art. 1063 is now offered whole; art. 1068.1 stops after its second sentence. Writer system prompt byte-identical to p21. |
| Label-echo guard | **Not exercised** | No `label_echo_dropped` on any of the 13 rows, and no answer contains a label list (no "attached", "button", "below", "click"). The guard skips turns with follow-up chips only (`AttachedActions::withoutLabelEcho`), so the law turns cannot trigger it. |
| Reworded narrator line | **Verified in all 8 composed data prompts; no effect on the answers** | "Attached below this answer (the user already sees these buttons; never list or repeat them): Team settings; …" became "The buttons attached below this answer are "Team settings"; … — the user already sees them: never list or repeat them." It is the ONLY composition-prompt change on turns 0, 1, 3, 6, 10, 11, 12 (+10 to +18 characters). |
| Excel follow-up "with their contact info" | **Generation ran, as designed** | Stages: `query_generation`, `execution`, `export`; no `recorded_result_export`. QU `property_concepts: ["contact", "email", "role"]` as in p21. QG 7,181 tokens (7,072 in / 109 out), 2.0 s; turn total 12,369 tokens, 8.9 s. Same cypher, same 523-row file. The PDF follow-up skipped generation (5,163 tokens, 5.9 s). |
| Closing drops | **None** | No `closing_dropped` on any row. See "Every closing" below. |
| Security / admission | **Clean** | 13/13 `security_gate` passed. 9 executions, all `safe_stop: false`, `security_filter_applied: true`, no repair. No SecurityException, no `admission` stage, no busy answer. Unchanged watch item: the meetings fallback cypher is logged without a team predicate (`scope: "team"`). |

## Passage selection per law turn

| | Tear-down (turn 7) | Buyer (turn 8) |
|---|---|---|
| Pool / candidates | 20 / 20 (chunks 33 … 126) | 20 / 20 (chunks 33 … 124) |
| Picked | chunk 58 = **art. 1063** (candidate [AJ]) | chunk 94 = **art. 1086** (candidate [AM]) |
| Added as | `joined` into chunk 61 → passage [3] becomes art. 1063-1068 (p20, p21: 1064-1068) | `passage` → a sixth passage [6] |
| Was the pick reachable by search? | No: chunk 58 is in no angle's list | No |
| Relevant candidate left in the pool | **art. 1097** ([AN], 3/4 majority for work on common portions) — the cap is 1 | none needed |
| Tokens | 4,039 (4,032 in / 7 out) | 3,998 (3,991 in / 7 out) |
| Latency | 1,505 ms | 1,505 ms |
| `file_context` total | 6.78 s (p21 5.24 s) | 5.59 s (p21 4.52 s) |
| Composition prompt | 17,534 → 21,826 characters; 5,519 → 6,513 tokens (mostly the two different passages [4] / [5], not the add) | 21,010 → 21,519 characters; 6,263 → 6,458 tokens |
| Answer cites the added passage? | **No.** Cites [1] and [2] only | **No.** Cites [1] only |
| Helped or misled? | **Neither in the answer; helped the chips.** The follow-up stage lists art. 1063 as unused (tier 3) and the writer made it a chip | **Neither.** Art. 1086 (a co-owner three months in arrears cannot be a director) is a near miss by the selector's own definition; the selector could see that art. 1069 was already selected |

Final passages in the prompt:

| # | Tear-down | Used? | Buyer | Used? |
|---|---|---|---|---|
| [1] | art. 1042-1047 (chunk 38), 43% | cited | art. 1068.1-1070 (chunk 66), 55% | cited twice (art. 1069 para. 1 and half of para. 2) |
| [2] | art. 1098-1102 (chunk 113), 73% | cited (art. 1100) | art. 1063-1067 (chunk 60), 51% | no |
| [3] | art. **1063**-1068 (chunk 61 + 58), 71% | **no** | art. 1076-1080 (chunk 83), 47% | no |
| [4] | art. 1075-1081 (chunks 84 + 81), 66% — insurance indemnity, syndicate powers | no, off-topic | art. 1070.1.1-1072 (chunk 72), 47% | no |
| [5] | art. 1070.1.1-1072 (chunk 72), 54% — registers, contingency fund | no, off-topic | art. 1092-1097 (chunk 108), 49% | no |
| [6] | — | | art. 1086 (chunk 94), 44%, **added** | no |

Answers to the tear-down questions:

- **Is art. 1063 in the prompt?** Yes, as the first article of passage [3], full text ("free use and enjoyment of his private portion … provided he complies with the by-laws of the immovable and does not impair the rights of the other co-owners or the destination of the immovable").
- **Does the answer distinguish a wall inside the private portion from walls presumed common, and works needing approval?** No. It treats the wall as presumed common from its second sentence. Art. 1044 (main walls) is unused as well.
- **Art. 1097** is still not in the prompt. It was in the candidate pool; one pick is allowed.
- **Passages [4] and [5] are both off-topic** (p21: art. 1055-1058 on the declaration and art. 1076-1081). Cause: probe wording this run ("…sous réserve de l'approbation du syndicat…") pulled Division V chunks 84, 81, 86, 72; `passage_overlap` {merged: [[84, 81]], filled: [72], unsupported: [89]}. Selection never removes, so it is not the cause.
- The added article sits inside a 3,495-character passage whose six other articles are about common expenses, insurance, notices and indemnities. Nothing in the prompt tells the model that this article was added as the general rule the question falls under.

## Every follow-up chip

Labels: N natural, A acceptable, O off-topic or already answered.

| Turn | Chip question | Source | Label | Answerable from its passage |
|---|---|---|---|---|
| 5 clarify | Outstanding balance of one unit | reading 1 | N | n/a (reply) |
| 5 clarify | Balance of an account | reading 2 | N | n/a |
| 5 clarify | Balance of a fund | reading 3 | N | n/a |
| 5 clarify | Balance of an invoice | reading 4 | A | n/a; weakest reading |
| 7 tear-down | Are main walls of buildings considered common portions? | passage [1], art. 1044, tier 2 | N | Yes: "foundations and main walls of buildings" are in the list. |
| 7 tear-down | Can I freely use and enjoy my private portion? | passage [3], art. 1063, tier 3 (`question_supported: false`) | A | Yes, with its three conditions. Statute wording, not hers — but it is the door to the rule the answer left out. |
| 8 buyer | When am I bound to pay the common expenses as a buyer? | passage [1], art. 1069 para. 2, tier 1 | A | Yes: only if the statement is provided within 15 days of the request. Read cold, "when" looks answered already ("at the time of acquisition"); the passage answers the condition. |

- Totals: 7 chips — 4 N, 3 A, 0 O (p21: 6 — 5 N, 1 A, 0 O). Legal chips: 3 — 1 N, 2 A, 0 O (p21: 2 N).
- Declined excerpts, 5, all correctly: tear-down art. 1046 and art. 1075; buyer art. 1069 para. 3, art. 1068.1, art. 1063.
- Buyer: p21's chip ("Can I request a statement of common expenses before buying a unit?") is gone because the answer now says that half itself. The excerpt offered is the whole sentence of para. 2, of which the answer used the first clause.
- Tear-down: the door the standard names ("ask about the approval process") is still not a chip. Art. 1100 and 1098 sit in cited passage [2] and have no excerpt slot; art. 1097 is not in the prompt.

## Every lead cut

| Turn | What the model wrote | Cut (rule) | What remains | Verdict |
|---|---|---|---|---|
| 1 roster | "Here are the members of your team, Novo Gestion, along with their roles:" | whole lead, `restatement` | "523 members." + table + "Showing 25 of 523" | **GOOD** (no value lost). Side effect unchanged: no "your team" left. |
| 9 board | S1 "The information about the board of directors and the president is available in the records." S2 "You can find the details in the table below." | S2 `one_sentence`, S1 `not_recorded` | "37 board members across 12 unions. Not recorded: Board role." + table | **GOOD**: S1 is false (no president is recorded), S2 is filler. Third different false sentence in three runs, all caught. |
| 11 owner-arrears | S2 "Here are the details of the owners who are currently overdue on their fees." | S2 `one_sentence` | S1 with the total + "4 units." + table | **GOOD**: pure filler. |

Leads not cut: team-info, both 310 leads, meetings, supplier.

## Every closing

- **Dropped: none** on the 13 turns, so nothing to label GOOD or HARMFUL.
- **Kept, tear-down:** "In summary, you should consult with the syndicate and ensure you have the necessary permissions before proceeding with any demolition work." It was a candidate (uncited last paragraph after two cited blocks) and `closingReadsAsContent` kept it as grounded. **Keeping it is right**: it is the only sentence telling her what to do, and p20's drop of the same kind of sentence was graded harmful.
- Buyer: both paragraphs are cited; no candidate.

## Code-written lines

Unchanged from p21: "I prepared the Excel file with all 523 rows." (correct), "I prepared the PDF with all 3 units." (correct), "3 different units named 310 — one per union." (repeats the lead), "No values found for: Oldest Due." No "Checked: …" and no "recorded for N of the M shown" line was printed.

---

# 3 — REGRESSIONS

## vs p21

| Turn | Delta | Cause | Evidence |
|---|---|---|---|
| buyer, action | **-0.05** | **Consequence of the answer change, plus writer wording** | The answer now uses the "request a statement" clause, so the writer cannot offer it; it asked about the other clause of the same sentence and worded it "When am I bound to pay…". Writer prompt byte-identical to p21. |
| tear-down, passages [4] / [5] | two off-topic passages (p21: one) | **Model variance (probe and QU wording)** | QU prompt byte-identical; `search_query` "modification rules wall kitchen living room unit" (p21 "modifying wall between kitchen and living room in unit"); both probes name "the approval of the syndicate". Art. 1055-1058 (declaration) lost its slot. |
| tear-down, tokens and time | composition +994 tokens; turn 15.2 s → 17.2 s | **Code (selection call +1.5 s, +4,039 tokens) plus the longer passages above** | Section 5. |
| buyer, tokens and time | +4,232 tokens; `file_context` +1.1 s | **Code (selection call)** | Paid for a passage the answer did not need. |
| meetings, wording | "for your team" gone | **Model variance** | Composition prompt differs from p21 only by the narrator line; p20 had the same sentence without it. Score unchanged. |
| board, QU | `is_possessive: true → false`, no clarified question | **Model variance** | QU prompt byte-identical. QG prompt 1,995 characters shorter, same cypher, 37 rows, identical answer. The composition prompt lost the user's e-mail line with it. |

Moved without a score change on the turns that had to stay put, each with its cause:

- team-info: "operates as" → "is classified as" (model variance; p20's wording).
- Excel: link title "Team Members and Roles" → "Team members and roles" (QU `subject_label` variance; labels are mixed-case in both runs).
- 310 t0: cypher split into two MATCH clauses; QU dropped the concept "debt", so one "debt: RETURN it as a column…" line left the QG prompt. Same 3 rows.
- 310 t1: cypher gained `ORDER BY balance DESC`; QU concept "debt" → "outstanding". Same 3 rows.
- owner-arrears: cypher lost `LIMIT 100`; "behind on their fees" → "behind on fees". Same 4 rows.
- roster, PDF, clarify, board: answers byte-identical.

Nothing on roster / export / 310 / team-info moved because of the label-echo guard or the narrator line.

## vs p20

| Turn | Delta | Cause | Evidence |
|---|---|---|---|
| buyer, answer | **-0.15** | **Model variance; the added passage cannot be excluded on one run** | The p22 prompt is p20's prompt plus passage [6] (art. 1086, 9 lines) and "[5] or [6]" in three places; two relevance figures differ by one point. Same five passages in the same order. p19 and p20 already differed by the same 0.15 on a byte-identical prompt. Under this instruction the turn has scored 0.60, 0.60, 0.75, 0.60, 0.75, 0.60 (p16-p20, p22). |
| tear-down, answer | **-0.05** | **Model variance on a different passage set** | Instruction block byte-identical to p20. Passages: [1] art. 1042-1047 (p20 1042-1051), [3] now starts at art. 1063, [4] / [5] replaced. p20 produced four conditional points; p22 a committed opening. p17 opened "You cannot unilaterally…" under the same instruction, so this is inside the model's range. |
| tokens | +7,856 (+4.7%) | **Code** | The selection call, 8,037 tokens on two turns. |

Reading of the revert: it removed what p21 added (the bare verdict on buyer, the flat "No" on tear-down) and returned both turns to their pre-p21 range, at the low end of it. It did not restore p20's two answers, because those were the high draws of that range.

---

# 4 — REMAINING DEFECT RANKING

Missing points: answers 2.20, actions 1.55. Stage letters: (a) understanding, (b) retrieval, (c) query generation, (d) data in the prompt but ignored, (e) a composition instruction or code rule, (f) action attachment.

| Rank | Defect | Turn(s) | Stage | Points | Fix determinism | Model variance? |
|---|---|---|---|---|---|---|
| 1 | Tear-down: art. 1063 is in the prompt and unused; committed opening; art. 1045 applied to an interior wall | 7 | (d), (e) | ~0.45 | medium | Which articles the model uses: yes. That the added article carries no signal: no |
| 2 | Buyer: the 15-day protection sits in the sentence the answer cites and is left out 4 runs of 6 | 8 | (d) | ~0.40 (0.15 lost this run vs p20) | medium-high | **Pure variance** on a fixed prompt |
| 3 | Board: president answered only by a label; record chips for 5 of 12 unions; no "our board" | 9 | (e), (f) | ~0.50 | high | No |
| 4 | Roster: staff buried in an unordered sample; no insider wording after the cut | 1 | (c), (e) | ~0.30 | high | No |
| 5 | Tear-down doors: no approval-process chip; art. 1097 in the pool, not in the prompt | 7 | (b), (f) | ~0.25 | medium | Wording: yes |
| 6 | Team-info: lead restates block values; no members door | 0 | (e), (f) | ~0.25 | high | Restating: yes |
| 7 | Passage selection always picks its quota: a tangential sixth passage on buyer | 8 | (b) | 0 this run; 4.0k tokens and 1.5 s per knowledge turn | medium | Which candidate: yes. That one is picked: 2 of 2 here |
| 8 | Tear-down retrieval: two of five passages off-topic | 7 | (b) | ~0.05, plus ~2k prompt tokens | low | **Pure variance** (probe wording) |
| 9 | Meetings: agenda half unanswered | 10 | (e), config | ~0.15 | medium | No |
| 10 | Clarify: "balance of an invoice"; reply chips send a bare reading | 5 | (a), (f) | ~0.20 | medium | Which kinds: yes |
| 11 | Export leftovers: "rows" vs "units"; "No values found for: Oldest Due." | 2, 4 | (e) code | ~0.15 | certain | No |
| 12 | 310: lead and homonym line say the same thing twice | 3, 6 | (e) | ~0.10 | high | No |
| 13 | Buyer chip wording reads as already answered | 8 | (f) | ~0.05 | low | **Pure variance** |
| 14 | Voice: "you owe … your suppliers" | 12 | (d) | ~0.15 | low | **Pure variance** |
| 15 | Excel follow-up pays a QG call (by design) | 2 | (e) code | 0 points; 7.2k tokens, 2.0 s | certain | No |

## Most structural fix per defect

1. **Tear-down composition.** The selector's pick is the one passage a model judged necessary, and it reaches composition unmarked, joined into the middle of a passage about other things. Carry the signal through: give an added chunk its own numbered passage (as on buyer) and place it first, or state on its § line that it is the general rule the question falls under. If one more call is acceptable, regenerate once when an added passage is not cited, as the citation retry does. This is the only change that turns the 4.0k tokens already spent into answer points.
2. **Buyer 15 days.** The follow-up stage already finds the unused clause of the cited paragraph (tier 1). When a tier-1 excerpt exists, regenerate once with "the paragraph you cite also says: …". About 6.5k tokens on the turns where it fires. An instruction-only alternative ("give the condition or exception the cited passage attaches to the rule") is cheaper and untested.
3. **Board.** Unchanged since p20: name the asked concept in the code line when it maps to a not-recorded column; spread record chips over unions.
4. **Roster.** Rarest role first, or a role distribution on the facts line; let the code line carry the possessive when the lead is cut whole.
5. **Tear-down doors.** Spread tier-2 excerpt slots over the cited passages so art. 1100 gets one; `max_added: 2` would bring art. 1097 in, at the cost item 7 describes.
6. **Team-info.** Add a members door; cut a lead whose values are all in the record block.
7. **Selection quota.** Make "nothing" a real outcome: ask for what the selected passages leave out before asking for ids, and drop the pick when that field is empty. Then re-measure the pick rate; the config comment already records that it "added on every turn" at a shorter view.
8. **Off-topic passages.** No cheap fix; probe wording is not stabilised by seeds (measured in p21).
9-12. Unchanged from p21 (fallback note names the missing concept; chips send a full sentence; one noun on both export paths; skip the homonym line when the lead states it).

Defects 1, 3-7 and 9-12 need no model change and cover about 2.35 of the 3.75 points missing; defects 2, 8, 13 and 14 are variance, about 0.65; the rest is 0.05-0.10 residuals on passing turns.

---

# 5 — TOKENS AND LATENCY VS p21

Tokens, summed per turn:

| Stage | p20 | p21 | p22 | Delta vs p21 |
|---|---|---|---|---|
| understanding (13 calls) | 65,528 | 65,530 | 65,527 | -3 |
| query_generation (9 calls) | 61,961 | 61,427 | 60,885 | -542 |
| composition (10 calls) | 33,265 | 32,954 | 34,104 | +1,150 |
| search_probes (2 calls) | 1,651 | 1,660 | 1,671 | +11 |
| **passage_selection (2 calls, new)** | — | — | **8,037** | **+8,037** |
| clarity_check (1 call) | 1,171 | 1,171 | 1,171 | 0 |
| follow_ups (2 calls, gpt-4o) | 2,225 | 2,197 | 2,262 | +65 |
| **Total** | **165,801** (37 calls) | **164,939** (37) | **173,657** (39) | **+8,718 (+5.3%)** |

- Input 159,977 → 168,622; output 4,962 → 5,035.
- Passage selection: 4,039 + 3,998 tokens, 14 output tokens in all. It is 92% of the increase.
- Composition: tear-down +994 (two longer off-topic passages, about +85 tokens for art. 1063), buyer +195 (passage [6]), board -73 (QU variance), narrator line +2 to +8 per data turn.
- QG: board -521 (QU variance, smaller schema); the rest within ±25.
- Knowledge turns now cost 17,576 (tear-down; p21 12,504) and 17,384 (buyer; p21 13,152) tokens.
- Excel follow-up: 12,369 tokens, of which QG 7,181 (unchanged, by design).

Latency:

| Stage | p21 sum / mean | p22 sum / mean |
|---|---|---|
| understanding | 79.2 s / 6.09 s (max 7.5 s) | 81.0 s / 6.23 s (max 8.5 s) |
| file_context (2 knowledge turns) | 9.8 s (5.24 s, 4.52 s) | 12.4 s (6.78 s, 5.59 s) |
| of which probe call | 4.8 s | 4.6 s |
| of which passage selection | — | **3.0 s (1.51 s, 1.51 s)** |
| query_generation | 22.4 s / 2.48 s over 9 (max 3.4 s) | 21.0 s / 2.33 s over 9 (max 3.3 s) |
| execution | 0.9 s / 0.10 s over 9 | 0.8 s / 0.09 s over 9 |
| composition (10 LLM-composed turns) | 15.7 s / 1.57 s (tear-down 2.43 s) | 16.9 s / 1.69 s (tear-down 2.72 s) |
| follow_ups (2 turns) | 3.12 s / 1.56 s | 3.06 s / 1.53 s |
| clarity_check | 1.99 s | 2.04 s |
| empty_result_check | 0.05 s | 0.43 s |
| **pipeline** | **133.5 s / 10.3 s, median 10.1 s, max 15.8 s** | **138.0 s / 10.6 s, median 10.3 s, max 17.2 s** |

- +4.5 s overall: +3.0 s is the selection call, +1.8 s understanding (provider), +1.2 s composition, -1.4 s QG.
- Knowledge turns: tear-down 15.2 s → 17.2 s, buyer 15.8 s → 15.6 s (its understanding call was 1.2 s faster). Still the two slowest.
- Meetings 9.8 s → 12.3 s: understanding 8.5 s (provider) and a 0.43 s fallback query.
- Understanding is 59% of pipeline time (p21: 59%).
