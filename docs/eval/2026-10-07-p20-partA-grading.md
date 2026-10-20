# Grade: p20-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p20-1001-A.json`, run at 2026-10-06T22:56-04:00. ai `badd2f1` + 592 dirty files (p19: 589). config_sha1 `998a334f…` (p19: `5eae9d5f…`).
- **Models:** QU gpt-4o-mini, QG gpt-4o, composition gpt-4o-mini, follow-up writer gpt-4o (unchanged).
- **Harness (summed from the rows):** 13/13 `ok`, 21/21 gates passed, 0 void, 0 infra, `errors` and `fallbacks` empty on all 13. The top-level counters (`total_rows: 7`, `usage_total` 79,737) describe one chunk only.
- **Rubric / strictness:** identical to p19. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Follow-up question chips are scored in the action column.
- **Not verified:** nothing was executed against the graph; no phpunit, no artisan. No chip was clicked, so no board Excel file was produced: the one-column name is verified on screen and in the composition prompt only. Single run: every "variance" verdict rests on a prompt diff against p19, not on repeats.

---

# 1 — GRADING

## Headline

- **Answer mean 0.831** (p19 0.808, +0.023). **Action mean 0.862** (p19 0.854, +0.008).
- **Counts 12 pass / 1 partial / 0 fail** (p19 10 / 3 / 0). Board and buyer move from partial to pass; tear-down stays partial and loses 0.05.
- Answer gains: buyer +0.15 (variance, on an identical prompt), board +0.10 (code), roster +0.05, owner-arrears +0.05 (code).
- Answer loss: tear-down -0.05 (the closing drop removed its only conclusion; the private-portion half is gone by variance).
- Action gains: tear-down +0.05, buyer +0.05 (more and better follow-up chips). No action loss.

## Per-turn table

| # | Turn | p18 ans / act | p19 ans / act | p20 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.85 / 0.70 | 0.85 / 0.70 | **0.85 / 0.70** | pass (= / =) | Composition prompt byte-identical to p19. Lead still restates name, type and member count above the record block ("classified as a management company with 523 members"). Record mode: no facts line, so the new lead bounding does not apply. No `record_enrichment` stage. Chips: My profile, Team settings; still no portfolio / finances / meetings doors. |
| 1 | roster t0 | 0.85 / 0.60 | 0.80 / 0.85 | **0.85 / 0.85** | pass (+0.05 / =) | Lead: "Here are the members of your team, Novo Gestion, along with their roles:" — the hedge "several members" is gone. It is still the introduction the prompt now forbids, and the restatement rule did not cut it (section 2). Code line "523 members.", "Showing 25 of 523 members". Still no ORDER BY: 3 of the 6 staff are in the 25 rows (22 are "Unit Owner or Renter"). Chips: Team settings, Download Excel (`mode: rerun`). |
| 2 | roster t1 Excel | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | Same payload: `previous_result`, complete, 523 rows, 523 distinct emails, roles 517 / 2 / 1 / 1 / 1 / 1, headers Name / Email / Role. QG still runs (7,148 tokens, same cypher). Same hedge "with the available information". |
| 3 | 310 t0 | 0.90 / 0.95 | 0.90 / 0.95 | **0.90 / 0.95** | pass (=) | Lead: "Unit 310 currently owes $0.00 across all unions." Shorter, still the merged singular, and "all unions" now overstates (3 of the 12 unions). The code line "3 different units named 310 — one per union." corrects it. Table and chips unchanged. |
| 4 | 310 t1 PDF | 0.90 / 0.85 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | `recorded_result_export` {generation_skipped: true, recorded_rows: 3}; QU call only (5,170 tokens). Money cells "$0.00", Union column kept. Same hedge. |
| 5 | ambiguous t0 | 0.80 / 0.65 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | Answer and four reply chips identical to p19, one chip per reading (`clarity_check.readings: 4`, `clarify.candidate_readings: 4`, `fallback_question: false`). "balance of an invoice" still the weak reading. |
| 6 | ambiguous t1 | 0.85 / 0.95 | 0.90 / 0.95 | **0.90 / 0.95** | pass (=) | "Unit 310 has an outstanding balance of $0.00 across all three unions, with no open invoices or invoices due." One sentence, merged singular again; "three" is at least exact. Same cypher, rows and chips. |
| 7 | tear-down t0 | 0.40 / 0.55 | 0.50 / 0.65 | **0.45 / 0.70** | partial (-0.05 / +0.05) | Four numbered points, no summary. Art. 1045 keeps its condition ("if it separates a private portion from another private portion or from a common portion … presumed common [1]"). Lost against p19: nothing on a wall that is inside her own portion (p19: "more flexibility"), and no conclusion — the closing was dropped by code (`closing_dropped`). "Major alterations typically require a majority vote among co-owners [2]" is not in passage [2] (art. 1098-1102; art. 1097 is not retrieved). Art. 1100 is now only a conditional point. Art. 1044 unused in the answer but offered as a chip. No article number invented (none is named). No citation retry. Two follow-up chips: one natural, one off-topic. |
| 8 | buyer-arrears t0 | 0.75 / 0.70 | 0.60 / 0.80 | **0.75 / 0.85** | **pass (+0.15 / +0.05)** | The 15-day rule is back: "If this statement is provided to you within 15 days of your request, you are only responsible for the fees listed in that statement [1]." Near word for word the p18 answer, hence the p18 score. Still missing: the reverse (no statement within 15 days, not bound); "only the fees listed" is an inference; the first paragraph has no marker; the article is not named; the summary repeats paragraph 1. Three follow-up chips: one natural, two acceptable. |
| 9 | board t0 | 0.55 / 0.75 | 0.65 / 0.75 | **0.75 / 0.75** | **pass (+0.10 / =)** | No lead: both model sentences were cut by code (section 2). The answer opens on "37 board members across 12 unions. Not recorded: Board role." Names in ONE column (Union Name / Name / Year). "Showing 25 of 37 board members". The false sentence of p19 is gone. Cost: "who is the president" is answered only by the label "Not recorded: Board role."; no "our board". Chips unchanged: 5 contact chips over 5 of 12 unions, Download Excel. |
| 10 | board t1 meetings | 0.80 / 0.95 | 0.85 / 0.95 | **0.85 / 0.95** | pass (=) | "There are currently no upcoming meetings scheduled." above three meetings of three unions, each with its union. "for your team" is gone (QU missed "our": `is_possessive: false`), nothing wrong is said. Agenda half still not addressed. Chips: 3 meeting records plus Meetings. |
| 11 | owner-arrears t0 | 0.85 / 0.90 | 0.90 / 0.90 | **0.95 / 0.90** | pass (+0.05 / =) | One sentence: "The total balance of owners behind on fees in SDC Carré Soho Phase 3 is $1,211.48." The filler second sentence was cut by code. "4 units.", 4 rows largest first, exact cents, owners. Chips: 4 units, Receivables report. |
| 12 | supplier t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | "You currently owe a total of $11,493.17 to your suppliers, with 4 open bills." Four figures exact. Voice slip unchanged. Chip: Payables. |

## Means

| Run | Answer, all 13 | Action, all 13 | Answer sum | Action sum |
|---|---|---|---|---|
| p18 | 0.788 | 0.796 | 10.25 | 10.35 |
| p19 | 0.808 | 0.854 | 10.50 | 11.10 |
| **p20** | **0.831** | **0.862** | **10.80** | **11.20** |

Of the +0.30 answer points, +0.20 come from code (board, roster, owner-arrears) and +0.15 from variance (buyer); -0.05 on tear-down.

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p18 | 11 | 2 | 0 |
| p19 | 10 | 3 | 0 |
| **p20** | **12** | **1** | **0** |

- **Partial:** tear-down (0.45).
- **Lowest passes:** buyer 0.75, board 0.75.

---

# 2 — FIX VERIFICATION

| Fix | Status | Evidence |
|---|---|---|
| Lead may be empty; model is shown the exact line | **Prompt verified; the model never left it empty** | Composition prompt diff vs p19 on turns 1, 3, 6, 9, 11: `The system prints: "523 members."` (and the four other lines), "what that line states is settled", and on 1, 3, 6, 9 "That sentence is OPTIONAL — the lead may be EMPTY … write ONLY {{table}}". All five turns still got a lead from the model. The two list questions (1, 9) are the ones the instruction was written for. |
| Code cuts a second sentence | **Verified, 2 of 2** | `lead_trimmed` rule `one_sentence` on board and owner-arrears (`DataAnswerPlan::boundedLead`, lines 624-653). |
| Code drops a lead that restates the question / the line | **Not triggered; one miss** | No `restatement` entry on any turn. Roster's "Here are the members of your team, Novo Gestion, along with their roles:" survives: `NarrativeRowEcho::restates` returns false as soon as one content word is in neither the question nor the line, and "Novo Gestion" is such a word. The unit test covers the same sentence without the team name. |
| Code drops a lead naming no row value beside "Not recorded: …" | **Verified** | Board: rule `not_recorded` removed "I couldn't find specific information about the board of directors and the president for your union in the available data." |
| Name as one column | **Verified on screen and in the prompt; not exercised in a file** | Board table "Union Name \| Name \| Year" (p19: First Name \| Last Name), 25 rows; composition DATA SUMMARY the same. The board Excel chip (`mode: table`) was not clicked. Roster file headers Name / Email / Role as before. |
| "Download Excel" chip export / label | **Present; translation not exercised in Part A** | Roster `export_excel` {mode: rerun, language: en}; board `export_excel` {mode: table}. Both turns are English. |
| Uncited closing paragraph dropped | **Works as coded: 1 drop, 1 kept by design** | Tear-down: `closing_dropped` set. Buyer: "In summary, yes, …" kept — only one earlier block cites a passage (rule needs two), and the paragraph carries question words no passage holds ("previous", "unpaid", "condo"), which `closingReadsAsContent` keeps on purpose (`AnswerValidator::withoutUncitedClosing`, lines 406-435). |
| Follow-ups: unused paragraphs of cited articles | **Verified** | Buyer excerpt E1 is the third paragraph of art. 1069 alone ("The statement provided is adjusted to the last annual budget"), the two used paragraphs are not offered. Tear-down: art. 1044 and 1046 of cited passage [1] are offered (p19: the whole passage counted as used). |
| Follow-ups: exclude conversation-covered articles | **Not exercised** | Both law turns are first turns. |
| Follow-ups: skip on navigation turns | **Not exercised** | No navigation turn in Part A. |
| Excerpt carries the fractional article number | **Half** | Excerpt headers now read "art. 1068.1", "art. 1068.2", "art. 1076.1" and are no longer cut at 240 characters. `follow_ups.article` still stores the integer: two chips both say 1068. |
| Clarify reply chips one per reading | **Verified (not clicked)** | 4 `ask_follow_up` invocations for 4 readings, zero LLM. |
| Single-record detail fields | **Not exercised** | No `record_enrichment` stage on any of the 13 turns. The only one-row results are the team aggregate (turn 0) and the supplier total (turn 12), neither a record with an id. |
| Retrieval freed-slot rule | **Exercised on tear-down only; neutral** | `passage_overlap` {merged: [[38, 42]], filled: [84], single_group: [84]}. See below. Buyer: no `passage_overlap`, same five chunks as p19. |
| Security / admission | **Clean** | 13/13 `security_gate` passed. 9 executions, all `safe_stop: false`, `security_filter_applied: true`, no repair. No SecurityException, no `admission` stage, no busy answer. Unchanged watch item: the meetings fallback cypher is logged without a team predicate (`scope: "team"`). |

## Every lead on a table / record turn

| Turn | What the model wrote | Cut (rule) | What remains | Better than p19? | Anything needed lost? |
|---|---|---|---|---|---|
| 0 team-info | 1 sentence restating three block values | none (record mode, no facts line) | same sentence | same | no |
| 1 roster | "Here are the members of your team, Novo Gestion, along with their roles:" | none | filler intro + "523 members." + table | yes: no "several members" hedge | no. The remaining lead is filler, against the prompt |
| 3 310 t0 | "Unit 310 currently owes $0.00 across all unions." | none | lead + homonym line + table | same; shorter, but "all unions" overstates | no. Lead still contradicts the line in number (one unit vs three) |
| 6 310 t1 | "Unit 310 has an outstanding balance of $0.00 across all three unions, with no open invoices or invoices due." | none | lead + homonym line + table | same | no. Same merged singular |
| 9 board | S1 "I couldn't find specific information about the board of directors and the president for your union in the available data." S2 "Please check the table below for the members listed, but note that their roles are not recorded." | S2 `one_sentence`; S1 `not_recorded` | no lead; "37 board members across 12 unions. Not recorded: Board role." + table | **yes**: p19's false "is available in the records" is gone, and so is this run's false "couldn't find … the board" above 37 board members | **Mildly.** The half of S2 "their roles are not recorded" was the only plain-language answer to "who is the president". The code line says it as a label and never uses the word "president". |
| 10 meetings | "There are currently no upcoming meetings scheduled. Here are the most recent meetings that have taken place:" | none (fallback rows, no facts line) | unchanged | same | no |
| 11 owner-arrears | S1 total $1,211.48. S2 "Here are the details of the owners who are currently overdue on their fees." | S2 `one_sentence` | S1 + "4 units." + table | yes | no: S2 was pure filler |
| 12 supplier | 1 sentence, total and count | none (no facts line) | unchanged | same | no |

Verdict on the lead fix: 3 cuts, all three removed filler or a false sentence; no cut removed a value. One cut (board S2) took a useful clause with it, because the one-sentence cut runs before the not-recorded test and the surviving sentence was the worse of the two. No remaining lead is a hedge. Remaining filler: roster. Remaining contradiction in number: both 310 leads.

## Every closing drop

| Turn | Dropped text | Verdict | Why |
|---|---|---|---|
| 7 tear-down | "In summary, you will likely need to confirm whether the wall is a common portion and seek the necessary approvals before proceeding with any demolition." | **HARMFUL (mild, about 0.05)** | The question is yes / no. This was the only sentence shaped as an answer and the only one telling her what to do first. The body still says what it depends on (point 1: the wall may be common if it separates portions; point 2: if common, the syndicate must approve), so the dependency is not lost — but the answer now opens with "To determine whether … consider the following points" and stops on "check the declaration", with no bottom line. Not an invitation or a platitude. |
| 8 buyer | none (paragraph kept by design) | n/a | "In summary, yes, you would be responsible …" is the direct answer. Keeping it was right, though it repeats paragraph 1. |

The rule cannot tell an uncited conclusion from an uncited invitation; on tear-down it removed a conclusion. The buyer guard (a question word no passage holds) did not fire on tear-down because "wall" is a word of art. 1045.

## Every follow-up chip

Labels: N natural, A acceptable, O off-topic or already answered.

| Turn | Chip question | Source | Label | Answerable from its passage |
|---|---|---|---|---|
| 5 clarify | Outstanding balance of one unit | reading 1 | N | n/a (reply) |
| 5 clarify | Balance of an account | reading 2 | N | n/a |
| 5 clarify | Balance of a fund | reading 3 | N | n/a |
| 5 clarify | Balance of an invoice | reading 4 | A | n/a; weakest reading |
| 7 tear-down | Are main walls and common equipment considered common portions? | passage [1], art. 1044 | N (wording is the statute's; "and common equipment" is noise) | Yes: "foundations and main walls of buildings, and common equipment" are presumed common. This is the bearing-wall door the standard names and p19 could not offer. |
| 7 tear-down | What ownership rights do I have in the common portions? | passage [1], art. 1046 | **O** | Yes ("undivided right of ownership … equal to the relative value of his fraction"), but an undivided share tells her nothing about removing a wall. |
| 8 buyer | How is the statement of common expenses adjusted for a condo purchase? | passage [1], art. 1069 para. 3 | A | Yes: "adjusted to the last annual budget of the co-owners". On the statement, but not a question a buyer asks. |
| 8 buyer | What certificate must the seller provide about the condo's condition? | passage [1], art. 1068.1 (metadata 1068) | A | Yes. Same chip as p19. |
| 8 buyer | Can I request documents from the syndicate before buying a condo unit? | passage [1], art. 1068.2 (metadata 1068) | N | Yes: the promisor "may request the syndicate to provide him with the documents or information … at the latter's expense". |

Totals: 9 chips (p19: 6) — 5 N, 3 A, 1 O. Legal chips alone: 5 (p19: 2) — 2 N, 2 A, 1 O (p19: 0 N, 2 A, 0 O).
Declined excerpts: 3, all correctly — art. 1064 and 1076.1 on tear-down (1076.1 comes from the filled passage), art. 1063 on buyer.
The standard's two named doors ("ask about the approval process", "how to request the statement") are still not chips; the second is now in the buyer answer itself.

## Freed-slot rule on tear-down / buyer

- **Tear-down.** Chunks 38 and 42 merged into one passage [1] (art. 1042-1051), which freed a slot; chunk 84 (art. 1076-1081: movable hypothec, syndicate liability, judgments, lease resiliation, injunction, latent defects) filled it, voted by one group only (probe_1). The answer cites [1], [2] and [4], never [5]; the writer declined its excerpt. **It neither helped nor misled.** It cost about 3,100 characters of prompt. The merge itself helped: art. 1042-1047 is passage [1] (p19: [3]) and art. 1045 is quoted with its condition.
- Art. 1063 (free use of the private portion) and art. 1097 (majority for works on common portions) are still not retrieved. Art. 1063 sits in chunk 60, which the buyer turn retrieves. The freed slot went to an off-topic chunk while the one article that would answer "inside my own unit" stayed out.
- **Buyer.** No overlap, no fill; passages 66, 60, 83, 72, 108 as in p19.

---

# 3 — REGRESSIONS VS p19

| Turn | Delta | Cause | Evidence |
|---|---|---|---|
| tear-down, answer | -0.05 | **Code change (closing drop) plus model variance** | Composition system prompt identical (4,557 characters). User prompt differs only in the passages and in p19's one-time regeneration block; the instructions are the same. The conclusion was written and then removed by `withoutUncitedClosing`. The missing private-portion sentence is variance: p19's first draft and retry both had "more flexibility", p20's single draft does not. |
| tear-down, passages | changed | **QU variance** | QU prompt byte-identical; `search_query` "modification rules for wall between kitchen and living room in unit" (p19: "rules regulations modifying wall kitchen living room unit") now clears chunk 38, which leads the consensus. Passages 38+42, 113, 61, 50, 84 (p19: 113, 61, 38, 89, 72). |
| meetings, lead wording | score unchanged | **QU variance** | `is_possessive: false` (p19: true, "our"), so no identity tiers reach QG (-570 tokens) or composition, and "for your team" is gone. The QU prompt differs from p19 only in the summary of the previous answer (the board answer now starts with the code line). QG wrote a different cypher (`m.description AS agenda`); both return 0 rows and the same fallback. Possessive: 12 of 13 (p19: 13). |
| roster chip role | none visible | **Code or QU variance, not traced** | "Team settings" is attached with role `identity` (p19: `next_step`). QU now selects `Team` as an entity type. Same chip, same text. |

No action regression: every p19 chip is still attached; 3 legal follow-up chips are new.

Not regressions:

- **Buyer +0.15 is variance.** The composition prompt differs from p19 in one character ("relevance: 55%" -> "54%"). The 15-day paragraph has now been used in 2 of 7 graded runs (p18, p20).
- **Tear-down had no citation retry** (p19: one). Same instructions; variance. It is the whole composition token saving of this run.
- **Board +0.10 is code**, and it worked by removing a false sentence the model wrote again in new words ("couldn't find … the board of directors" above 37 board members). The new prompt text did not stop the model writing it.

---

# 4 — REMAINING DEFECT RANKING

Missing points: answers 2.20, actions 1.80. Stage letters: (a) understanding, (b) retrieval, (c) query generation, (d) data in the prompt but ignored, (e) a composition instruction or code rule, (f) action attachment.

| Rank | Defect | Turn(s) | Stage | Points | Fix determinism | Model variance? |
|---|---|---|---|---|---|---|
| 1 | Tear-down retrieval: art. 1063 and 1097 never retrieved; the freed slot goes to an off-topic chunk | 7 | (b) | ~0.25 | medium | Search query: yes |
| 2 | Team-info: no portfolio / finance / meeting doors; lead restates block values | 0 | (f), (e) | ~0.45 | high (config) for the doors | Restating: yes |
| 3 | Board: president answered only by a label; record chips for 5 of 12 unions; no "our board" | 9 | (e), (f) | ~0.40 | high | No |
| 4 | Roster: staff buried in an unordered sample; filler intro survives the restatement rule | 1 | (c), (e) | ~0.30 | high | Intro wording: yes |
| 5 | Closing drop removes an uncited conclusion | 7 | (e) code | ~0.05 here; every yes / no law answer is exposed | medium | Whether the model cites its summary: yes |
| 6 | Follow-up chips: one off-topic chip; the standard's doors never offered; integer article in metadata | 7, 8 | (f) | ~0.45 | medium | Wording: yes |
| 7 | 310 leads: one "Unit 310" across unions above "3 different units" | 3, 6 | (e) | ~0.10 | high | Wording: yes |
| 8 | Export sentence hedges on complete files; Excel follow-up still pays a QG call | 2, 4 | (e) code | ~0.20 | certain | No |
| 9 | Meetings: agenda half unanswered | 10 | (e), config | ~0.15 | medium | No |
| 10 | Clarify: "balance of an invoice"; reply chips send a reading without its object | 5 | (a), (f) | ~0.15 | medium | Which kinds: yes |
| 11 | Tear-down composition: no private-portion half, ungrounded "majority vote [2]", majorities vague | 7 | (d) | ~0.30 | low | **Pure variance** |
| 12 | Buyer: reverse of the 15-day rule missing, "only the fees listed" inferred, article not named | 8 | (d) | ~0.25 | low | **Pure variance** (0.60 or 0.75 on the same prompt) |
| 13 | Voice: "you owe … your suppliers" | 12 | (d) | ~0.15 | low | **Pure variance** |

## Most structural fix per defect

1. **Tear-down retrieval.** Fill a freed slot only with a chunk two vote groups agree on, or leave it empty: chunk 84 had one vote and bought nothing. Better, index per article with the heading path, so "free use and enjoyment of his private portion" (art. 1063) can be reached by a question about altering one's own unit.
2. **Team-info doors.** Config only, unchanged since p17: `related` placements for Meetings, Units, Receivables and Payables when the single row is the user's own team.
3. **Board.** Have the code line name what was asked: when a QU `property_concept` ("president") maps to a not-recorded column, print "Not recorded: Board role (so the president cannot be identified)" or a host string to that effect. Run the `not_recorded` test on each sentence before the one-sentence cut, so a sentence stating the gap in plain words can survive when the first one is dropped. Spread record chips over unions.
4. **Roster.** Rarest role first (or a role distribution on the facts line). For the lead: treat words of the user's own team name and other identity values as known in `restates`, so "…your team, Novo Gestion, along with their roles:" is cut.
5. **Closing drop.** Ask for the direct answer FIRST in the prompt, where position protects it (the first paragraph can never be dropped), and let QU flag a closed question so the rule can keep the last paragraph when no earlier paragraph outside the cited list answers it. As coded, the rule trades a platitude on one run for a conclusion on another, and it cannot tell them apart.
6. **Follow-ups.** Store the full article number. Rank excerpts by overlap with the question's content words so art. 1046 falls below the threshold on a wall question.
7. **310 leads.** With a homonym line, give the model the plural subject ("the three units named 310") in the instruction, or drop a lead whose subject is the bare shared name.
8. **Export.** A lang string without "with the available information" when `export_source_complete` is true; relax condition 3 of `PreviousResultExport::asksNothingNew` when the asked concepts are all carried.
9. **Meetings agenda.** Name in the fallback note the asked concepts the fallback rows do not carry.
10. **Clarify.** Reply chips send a full sentence; rank readings backed by a host calculation first.

## Losses that need a stronger composition model or a changed standard

- **Tear-down composition (~0.30).** Six graded runs: 0.40, 0.30, 0.50, 0.40, 0.50, 0.45. Retrieving art. 1063 should lift the ceiling to about 0.60. A reliable pass needs a stronger composer, or a standard that accepts "walls separating portions are presumed common; inside your own portion you are freer; ask the syndicate".
- **Buyer (~0.25).** Passes when the model uses the second paragraph of art. 1069 (2 runs of 7), partial otherwise, on the same prompt. No code change in this iteration moved it.
- **Voice slips**, 0.05-0.15 on passing turns.

Defects 1 to 10 need no model change and explain about 2.50 of the 4.00 points still missing.

---

# 5 — TOKENS AND LATENCY VS p19

Tokens, summed per turn:

| Stage | p19 | p20 | Delta |
|---|---|---|---|
| understanding (13 calls) | 65,499 | 65,528 | +29 |
| query_generation (9 calls) | 62,599 | 61,961 | -638 |
| composition (11 -> 10 calls) | 39,003 | 33,265 | -5,738 |
| search_probes (2 calls) | 1,685 | 1,651 | -34 |
| clarity_check (1 call) | 1,171 | 1,171 | 0 |
| follow_ups (2 calls, gpt-4o) | 2,117 | 2,225 | +108 |
| **Total (38 -> 37 calls)** | **172,074** | **165,801** | **-6,273 (-3.6%)** |

- Input 166,525 -> 160,570; output 5,549 -> 5,231.
- Composition: tear-down -6,135 (no citation retry this run — variance, not a saving of the code). The new lead instructions add about 100-115 tokens on each of the four turns that carry them (roster +102, 310 +104, clarified 310 +112, board +113) and +30 on owner-arrears; meetings -91 (no identity block).
- QG: meetings -570 (QU missed "our"), Excel -116; the rest within +/-25.
- follow_ups: output 102 -> 169 tokens for 5 chips instead of 2; input +41 although the excerpts are longer.
- Like for like (without p19's retry): about 165,500 -> 165,800. The iteration is token-neutral.

Latency:

| Stage | p19 sum / mean | p20 sum / mean |
|---|---|---|
| understanding | 78.7 s / 6.05 s (max 6.9 s) | 72.9 s / 5.61 s (max 6.5 s) |
| file_context (2 knowledge turns) | 9.6 s (5.21 s, 4.41 s) | 9.1 s (4.78 s, 4.35 s) |
| of which probe call | 5.2 s | 4.6 s |
| query_generation | 22.0 s / 2.44 s over 9 (max 3.5 s) | 18.5 s / 2.05 s over 9 (max 2.8 s) |
| execution | 1.6 s / 0.18 s over 9 | 1.3 s / 0.14 s over 9 |
| composition (10 LLM-composed turns) | 22.3 s / 2.23 s (tear-down 7.24 s) | 16.8 s / 1.68 s (tear-down 3.02 s) |
| follow_ups (2 turns) | 3.16 s / 1.58 s | 2.94 s / 1.47 s (1.41 s, 1.53 s) |
| clarity_check | 1.76 s | 1.69 s |
| recorded_result_export | 0 ms | 0 ms |
| empty_result_check | 0.04 s | 0.04 s |
| **pipeline** | **139.6 s / 10.7 s, median 9.9 s, max 20.2 s** | **123.8 s / 9.5 s, median 9.2 s, max 14.6 s** |

- The 15.8 s saved are provider latency and the missing retry (understanding -5.7 s, composition -5.5 s, QG -3.5 s); no code change of this iteration removes a call.
- Knowledge turns: tear-down 20.2 s -> 14.6 s, buyer 15.0 s -> 13.8 s; they remain the two slowest.
- Understanding is 59% of pipeline time (p19: 56%).
