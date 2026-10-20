# Grade: p21-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p21-1001-A.json`, run at 2026-10-07T00:13-04:00. ai `badd2f1` + 597 dirty files (p20: 592). config_sha1 `7902f5bf…` (p20: `998a334f…`).
- **Models:** QU gpt-4o-mini, QG gpt-4o, composition gpt-4o-mini, follow-up writer gpt-4o (unchanged).
- **Harness (summed from the rows):** 13/13 `ok`, 21/21 gates passed, 0 void, 0 infra, `errors` and `fallbacks` empty on all 13. The top-level counters (`total_rows: 7`, `usage_total` 80,022) describe one chunk only.
- **Rubric / strictness:** identical to p20. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Follow-up question chips are scored in the action column.
- **Not verified:** nothing was executed against the graph; no phpunit, no artisan. No chip was clicked and no file was opened. Single run: every "variance" verdict rests on a prompt diff against p20's trace, not on repeats.

---

# 1 — GRADING

## Headline

- **Answer mean 0.819** (p20 0.831, **-0.012**). **Action mean 0.881** (p20 0.862, +0.019).
- **Counts 11 pass / 1 partial / 1 fail** (p20 12 / 1 / 0). Buyer falls from pass to partial; tear-down falls from partial to fail (0.35, on the boundary: 0.40 would be a partial).
- Answer gains, all code: the two export sentences +0.05 each, the two 310 leads +0.05 each (+0.20).
- Answer losses, both on the law turns: buyer -0.25, tear-down -0.10 (-0.35). Both follow the one prompt line this iteration changed for law answers.
- Action gains: team-info +0.20 (new doors), buyer +0.05 (the standard's own door is now the chip). No action loss.
- Without the two law turns the answer mean is 0.891 (p20 0.873).

## Per-turn table

| # | Turn | p19 ans / act | p20 ans / act | p21 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.85 / 0.70 | 0.85 / 0.70 | **0.85 / 0.90** | pass (= / **+0.20**) | Lead still restates three block values ("Your team is called "Novo Gestion," and it operates as a management company with a total of 523 members."). Record block unchanged, "(that's you)" on the e-mail. Chips: Team settings, **Units, Meetings, Receivables report**; "My profile" is gone. Three of the standard's four doors (portfolio, finances, meetings); no members door, no Payables. |
| 1 | roster t0 | 0.80 / 0.85 | 0.85 / 0.85 | **0.85 / 0.85** | pass (=) | The model wrote the same intro as p20 on a byte-identical prompt; code cut it (`lead_trimmed` rule `restatement`). The answer opens on "523 members." and ends on "Showing 25 of 523 members". Filler gone, but with it the only "your team" wording the standard asks for: net zero. Still no ORDER BY: 3 staff rows, 22 "Unit Owner or Renter". Chips unchanged. |
| 2 | roster t1 Excel | 0.90 / 0.90 | 0.90 / 0.90 | **0.95 / 0.90** | pass (+0.05 / =) | "I prepared the Excel file with all 523 rows." Count stated, hedge gone. Payload as before: `previous_result`, complete, 523 rows, headers Name / Email / Role. **Query generation still ran** (7,185 tokens, same cypher): no `recorded_result_export` stage (section 2). |
| 3 | 310 t0 | 0.90 / 0.95 | 0.90 / 0.95 | **0.95 / 0.95** | pass (+0.05 / =) | "The 3 units named 310 currently owe $0.00 each, with no open invoices or invoices due." Plural subject, "each", no "all unions". The code line "3 different units named 310 — one per union." now repeats the lead. Same rows, same chips. |
| 4 | 310 t1 PDF | 0.90 / 0.90 | 0.90 / 0.90 | **0.95 / 0.90** | pass (+0.05 / =) | "I prepared the PDF with all 3 units." `recorded_result_export` {generation_skipped: true, recorded_rows: 3}; QU call only (5,159 tokens). Union column kept. "No values found for: Oldest Due." unchanged. |
| 5 | ambiguous t0 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | Answer and four reply chips identical to p20. Chip `params.question` is still the bare reading ("outstanding balance of one unit"). |
| 6 | ambiguous t1 | 0.90 / 0.95 | 0.90 / 0.95 | **0.95 / 0.95** | pass (+0.05 / =) | "The 3 units named 310 all have an outstanding balance of $0.00, with no open invoices or invoices due." Same cypher, rows and chips as p20. |
| 7 | tear-down t0 | 0.50 / 0.65 | 0.45 / 0.70 | **0.35 / 0.70** | **fail (-0.10 / =)** | Opens "No, you cannot tear down the wall … without proper authorization." A committed answer no passage supports for a wall inside one unit. Art. 1045 is paraphrased with half its condition, then applied to her wall by "Therefore". Paragraph 2 states it with no condition ("if the wall is not part of the foundations or main walls of the building, it is classified as a common portion") and cites [2], where art. 1045 is in [1]. "Consent of the syndicate … [1]" is not in [1]; "majority vote [2]" is not in [2]. No interior / bearing distinction, art. 1100 gone. Two paragraphs, no article number invented. One chip, natural. |
| 8 | buyer-arrears t0 | 0.60 / 0.80 | 0.75 / 0.85 | **0.50 / 0.90** | **partial (-0.25 / +0.05)** | "Yes, if you buy a unit, you are responsible for the previous owner's unpaid condo fees." plus art. 1069 para. 1 [1] and a restatement. Correct and grounded as far as it goes. **The statement of common expenses is not mentioned at all**, so neither is the 15-day protection: half of the standard is missing and the "yes" is unqualified. One chip, exactly the standard's door: "Can I request a statement of common expenses before buying a unit?" |
| 9 | board t0 | 0.65 / 0.75 | 0.75 / 0.75 | **0.75 / 0.75** | pass (=) | Same answer as p20: "37 board members across 12 unions. Not recorded: Board role.", Union Name / Name / Year, 25 of 37. One model sentence, cut by `not_recorded`. President still answered only by the label. Same 5 contact chips over 5 of 12 unions, Download Excel. |
| 10 | board t1 meetings | 0.85 / 0.95 | 0.85 / 0.95 | **0.85 / 0.95** | pass (=) | "There are currently no upcoming meetings scheduled for your team." above the same three meetings with union and place. Agenda half still not addressed. Same chips. |
| 11 | owner-arrears t0 | 0.90 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | One sentence with the total $1,211.48, "4 units.", four rows largest first, owners. Filler second sentence cut (`one_sentence`), as in p20. |
| 12 | supplier t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | "Currently, you owe a total of $11,493.17 to your suppliers, with 4 open bills." Four figures exact. Voice slip unchanged. Chip: Payables. |

## Means

| Run | Answer, all 13 | Action, all 13 | Answer sum | Action sum |
|---|---|---|---|---|
| p19 | 0.808 | 0.854 | 10.50 | 11.10 |
| p20 | 0.831 | 0.862 | 10.80 | 11.20 |
| **p21** | **0.819** | **0.881** | **10.65** | **11.45** |

Answer: +0.20 from code (exports, 310 leads), -0.35 on the two law turns. Action: +0.25 (team-info doors +0.20, buyer chip +0.05).

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p19 | 10 | 3 | 0 |
| p20 | 12 | 1 | 0 |
| **p21** | **11** | **1** | **1** |

- **Fail:** tear-down (0.35; calibrated against p15 0.35 and p18 0.40, which had the same unconditional art. 1045 sentence without the committed "No").
- **Partial:** buyer (0.50; the 0.60 runs p16 / p17 / p19 at least gave the statement as advice).
- **Lowest passes:** board 0.75.

---

# 2 — FIX VERIFICATION

| Fix | Status | Evidence |
|---|---|---|
| Law answers: direct answer first, cited, no closing summary | **Shape obtained 2 of 2; citation in the first sentence 0 of 2; content worse on both** | The guideline line is the only instruction change in both composition prompts (system prompt byte-identical, 4,557 characters). Both answers open with "No, …" / "Yes, …"; neither first sentence carries a marker (the [1] comes two sentences later). Neither has a summary paragraph. See section 3 for what the committed first sentence cost. |
| Uncited-closing drop narrowed to ungrounded closers | **Not exercised** | No `closing_dropped` on any of the 13 rows. Tear-down: the last block cites [2], so it is not a candidate. Buyer: one block. Whether the narrowed rule would have kept p20's dropped conclusion cannot be read from this run. |
| Team-info doors, no "My profile" | **Verified** | `attached_actions`: team_settings (identity), units, meetings, report_receivables (next_step). The composition prompt lists the same four. `no_self_contact` gate passed. |
| Roster intro naming the own team is cut | **Verified** | `lead_trimmed: [{rule: "restatement", text: "Here are the members of your team, Novo Gestion, along with their roles:"}]`. Composition prompt identical to p20 (7,737 characters) and the model wrote the identical sentence: the cut is the code's. |
| Export sentence states the count, no hedge | **Verified on both** | Excel: "with all 523 rows" (`export_source_complete: true`, 523 rows). PDF: "with all 3 units". The noun differs: "rows" on the regenerated Excel path, "units" on the recorded PDF path. |
| Excel follow-up skips query generation | **NOT obtained this run** | Stages on turn 2: `query_generation` (7,185 tokens, 2.3 s), `execution`, `export`; no `recorded_result_export`. QU named `property_concepts: ["contact", "email", "role"]` (p20: `["email", "role"]`). `PreviousResultExport::asksNothingNew` returns false on the first concept the recorded result does not carry, and its own doc lists "contact" after name / email / role as NEW. With p20's QU output the new rule would have fired. The question says "contact info", so QU naming "contact" is the expected case, not the exception. |
| 310 lead: plural subject given by code | **Verified on both 310 turns** | New prompt line on turns 3 and 6: "The rows are 3 DIFFERENT records, so the SUBJECT of your sentence is PLURAL — "the 3 units named 310"…". Both leads start "The 3 units named 310". |
| Facts line "… recorded for N of the M shown" | **Not exercised** | No answer carries it. Board's asked column is entirely blank ("Not recorded: Board role."), not partly. Meetings has one blank Place of three, but no facts line and Place was not asked. |
| Code-written "Checked: …" line | **Not exercised** | No `checked_line` on any row. It is written only on the empty-result path (`ResponseGenerator`, line 1119); the one empty execution (meetings) has fallback rows. |
| Follow-up slots reserved per tier, never a used article | **Verified** | `follow_ups.excerpts`: tear-down 1044 (tier 2), 1046 (tier 2), 1064 (tier 3), 1055 (tier 3); buyer 1069 (tier 1) twice, 1068.1 (tier 2), 1063 (tier 3). Art. 1045 and art. 1069 para. 1, the ones the answers used, are not offered. Stage excerpts store "1068.1" as a string; `response_metadata.follow_ups.article` is an integer on the two kept chips, neither fractional. |
| Chip whose excerpt cannot answer it | **Verified by outcome** | Writer prompt adds ""f" IS the answer to "q"…" (system prompt 1,743 → 2,078 characters). Proposed 1 of 4 on each law turn (p20: 2 of 4 and 3 of 4); nothing dropped by code. |
| Buyer: "request a statement of common expenses" offered | **Yes** | Chip from art. 1069 para. 2, tier 1. It is offered because the answer no longer uses that paragraph: the door opened where the answer lost its second half. |
| Host example query without a literal unit name | **Verified, no effect on the cypher** | QG prompt, turns 3 and 6: `u.name = "310"` became `u.name = "<UNIT NAME FROM THE QUESTION>"`, plus a note ("when the user named NO unit, never invent or reuse an example name…"), +312 characters. Turn 6 cypher is identical to p20; turn 3 merges the two MATCH clauses, same predicate (`u.name = '310' AND un.team_id IN $ai_team_ids`), 3 rows. The clarify turn runs no query generation. |
| Clarify reply chips send a self-contained question (iteration-6 item 9) | **Not visible** | `params.question` is "outstanding balance of one unit" etc., as in p20. |
| Security / admission | **Clean** | 13/13 `security_gate` passed. 9 executions, all `safe_stop: false`, `security_filter_applied: true`, no repair. No SecurityException, no `admission` stage, no busy answer. Unchanged watch item: the meetings fallback cypher is logged without a team predicate (`scope: "team"`). |

## Every lead cut

| Turn | What the model wrote | Cut (rule) | What remains | Verdict |
|---|---|---|---|---|
| 1 roster | "Here are the members of your team, Novo Gestion, along with their roles:" | whole lead, `restatement` | "523 members." + table + "Showing 25 of 523" | **GOOD by the rule** (no value lost). Side effect: the answer has no "your team" left. |
| 9 board | "I could not find specific information about the members of your board of directors or the president in the available data." | whole lead, `not_recorded` | "37 board members across 12 unions. Not recorded: Board role." + table | **GOOD**: a false sentence above 37 board members. One sentence this run (p20: two). |
| 11 owner-arrears | S2 "Here are the details of the owners who are currently overdue on their fees." | S2, `one_sentence` | S1 with the total + "4 units." + table | **GOOD**: pure filler, as in p20. |

Leads not cut: team-info (restates three block values), both 310 leads (now correct), meetings, supplier.

## Every closing drop

None on the 13 turns. The one uncited closer left is a sentence, not a paragraph: tear-down ends "You should consult your syndicate for guidance on the specific procedures and approvals needed for such modifications." inside the paragraph that cites [2], where the rule cannot see it. It is advice, not an invitation; harmless.

## Every follow-up chip

Labels: N natural, A acceptable, O off-topic or already answered.

| Turn | Chip question | Source | Label | Answerable from its passage |
|---|---|---|---|---|
| 5 clarify | Outstanding balance of one unit | reading 1 | N | n/a (reply) |
| 5 clarify | Balance of an account | reading 2 | N | n/a |
| 5 clarify | Balance of a fund | reading 3 | N | n/a |
| 5 clarify | Balance of an invoice | reading 4 | A | n/a; weakest reading |
| 7 tear-down | What are considered common portions in a building? | passage [1], art. 1044, tier 2 | N | Yes: the list includes "foundations and main walls of buildings". Plainer wording than p20's chip, but "main walls" is no longer in the question. |
| 8 buyer | Can I request a statement of common expenses before buying a unit? | passage [1], art. 1069 para. 2, tier 1 | N | Yes, and the paragraph also gives the 15-day protection the answer left out. |

Totals: 6 chips (p20: 9) — 5 N, 1 A, 0 O. Legal chips alone: 2 (p20: 5) — 2 N, 0 A, 0 O (p20: 2 N, 2 A, 1 O).

Declined excerpts, 6, all correctly: tear-down art. 1046 (p20's off-topic chip), 1064, 1055; buyer art. 1069 para. 3 and 1068.1 (p20's two "acceptable" chips), 1063. One loss: art. 1068.2 ("request documents from the syndicate", N in p20) has no excerpt slot under the tier reservation. The tear-down door the standard names ("ask about the approval process") is still not a chip.

## New code-written lines

| Line | Turn | Text | Correct? |
|---|---|---|---|
| Export count | 2 | "I prepared the Excel file with all 523 rows." | Yes (523 of 523). |
| Export count | 4 | "I prepared the PDF with all 3 units." | Yes (3 of 3). |
| Plural subject (given to the model) | 3, 6 | "the 3 units named 310" | Yes; the homonym line below now says the same thing a second time. |
| "recorded for N of the M shown" | — | not printed | — |
| "Checked: …" | — | not printed | — |

---

# 3 — REGRESSIONS VS p20

| Turn | Delta | Cause | Evidence |
|---|---|---|---|
| buyer, answer | **-0.25** | **Probably the code change (the direct-answer line); one run** | QU prompt byte-identical. Composition system prompt byte-identical. User prompt: the passages block is the same 16,849 characters (same five chunks 66, 60, 72, 108, 83; [3]-[5] rotated), and the instructions differ in ONE line: "Start with a direct answer to the question when the data supports one" became "START WITH THE DIRECT ANSWER: your FIRST sentence … answers the question as asked (to a yes/no question: yes, no, or what it depends on) … the answer ENDS on the last of them: no closing summary, no invitation." Output 167 → 83 tokens. The statement of common expenses was in every graded answer from p16 to p20 and in p20x (as advice or with the 15 days); it is absent in the first run under the new line. The model answered the yes / no and stopped. |
| tear-down, answer | **-0.10** | **Code change (same line) plus model variance** | Same single instruction line changed. Same five chunks (38, 113, 61, 50, 84). Passage [1] is art. 1042-1047 (p20: 1042-1051): chunk 42 was not merged this run, and nothing the answer needs was in 1048-1051. So the passages that matter are the same and the difference is composition. The line requires a committed first sentence; the model chose "No" where the passages only support "it depends". p17 opened "You cannot unilaterally…" under the old prompt, so the wrong direction is inside the model's variance — but the new line makes a commitment mandatory and puts it first. The unconditional art. 1045 sentence is the same slip as p15 and p18. |
| tear-down, passages | [1] shorter | **QU variance** | QU prompt byte-identical; `search_query` "modifying wall between kitchen and living room in unit" (p20: "modification rules for wall…"); the second probe's French angle returns no chunk and its English angle only 38, so 42 never enters and there is no `passage_overlap`. |
| Excel follow-up, tokens | no saving | **QU variance on a rule that excludes this wording by design** | Section 2. The QU prompt differs from p20 only in the summary of the previous answer (it now starts "523 members." because the roster lead was cut). |
| follow-up chips, count | 5 → 2 legal chips | **Code change, intended** | Stricter writer prompt and tier slots. The three chips lost were p20's 2 A and 1 O; one N (art. 1068.2) lost its slot. |
| board QG prompt | -667 tokens | **QU variance** | `entity_types: ["BoardMember"]` (p20: plus Union), smaller schema block; same cypher, same 37 rows. |

Not regressions:

- **Roster** score unchanged: the cut is correct; it only moved the turn's remaining loss from "filler" to "no insider wording".
- **Meetings** "for your team" is back in the lead although QU again set `is_possessive: false`; the composition prompt is identical to p20 apart from the date.

Reading of the law-turn result: the prompt line did what it says (first sentence answers, no summary), and both answers got shorter and worse. On a question whose honest answer has a condition or an exception, a forced one-sentence verdict drops the condition (tear-down) or the exception (buyer). One run each; the buyer evidence is the stronger of the two because nothing else in the prompt changed.

---

# 4 — REMAINING DEFECT RANKING

Missing points: answers 2.35, actions 1.55. Stage letters: (a) understanding, (b) retrieval, (c) query generation, (d) data in the prompt but ignored, (e) a composition instruction or code rule, (f) action attachment.

| Rank | Defect | Turn(s) | Stage | Points | Fix determinism | Model variance? |
|---|---|---|---|---|---|---|
| 1 | Law answers: the committed first sentence drops the condition / the exception that is in the cited passage | 7, 8 | (e), (d) | ~0.60 (buyer ~0.40, tear-down ~0.20; 0.35 of it lost this run) | medium-high | Partly: which half is dropped varies; that something is dropped follows the instruction |
| 2 | Board: president answered only by a label; record chips for 5 of 12 unions; no "our board" | 9 | (e), (f) | ~0.50 | high | No |
| 3 | Tear-down retrieval: art. 1063 and 1097 never retrieved | 7 | (b) | ~0.25 | medium | Search query: yes |
| 4 | Roster: staff buried in an unordered sample; no insider wording after the cut | 1 | (c), (e) | ~0.30 | high | No |
| 5 | Tear-down chips: the approval-process door is never offered | 7 | (f) | ~0.30 | medium | Wording: yes |
| 6 | Team-info: lead restates block values; no members door | 0 | (e), (f) | ~0.25 | high | Restating: yes |
| 7 | Meetings: agenda half unanswered | 10 | (e), config | ~0.15 | medium | No |
| 8 | Clarify: "balance of an invoice"; reply chips send a bare reading | 5 | (a), (f) | ~0.20 | medium | Which kinds: yes |
| 9 | Excel follow-up pays a QG call whenever QU names "contact" | 2 | (e) code | 0 points; ~7.2k tokens and 2.3 s per export | certain | Which concepts QU names: yes |
| 10 | Export leftovers: "rows" vs "units"; "No values found for: Oldest Due." | 2, 4 | (e) code | ~0.20 | certain | No |
| 11 | 310: lead and homonym line say the same thing twice | 3, 6 | (e) | ~0.15 | high | No |
| 12 | First sentence of a law answer carries no passage number (0 of 2) | 7, 8 | (d) | 0 by itself | high if checked by code | Yes |
| 13 | Tear-down composition: ungrounded "consent of the syndicate [1]", "majority vote [2]", wrong passage for art. 1045 | 7 | (d) | ~0.20 | low | **Pure variance** |
| 14 | Voice: "you owe … your suppliers" | 12 | (d) | ~0.15 | low | **Pure variance** |

## Most structural fix per defect

1. **Law first sentence.** Two options, cheapest first. (a) Reword the line so a verdict must carry what the passage attaches to it: the first sentence gives the answer TOGETHER with the condition or exception the cited article states, and "it depends on X" is the required form when the passages decide the question only under a condition. (b) Structural, no wording test: the follow-up stage already computes tier-1 excerpts, the unused paragraphs of the very article the answer cites. When one exists (buyer: art. 1069 para. 2), regenerate once with "the article you cite also says: …", as the citation retry does. That would have restored the statement on buyer; it costs one composition call (about 6k tokens) on the turns where it fires. In this one run the previous line ("Start with a direct answer … when the data supports one") scored higher on both turns.
2. **Board.** Unchanged from p20: name the asked concept in the code line when it maps to a not-recorded column; spread record chips over unions.
3. **Tear-down retrieval.** Unchanged: per-article indexing with the heading path, so art. 1063 can be reached by a question about altering one's own unit.
4. **Roster.** Rarest role first, or a role distribution on the facts line. If the lead is cut whole, let the code line carry the possessive ("523 members in your team.").
5. **Tear-down chips.** Art. 1100 and 1098 sit in cited passage [2] and were not offered: tier 2 took both slots from passage [1]. Spread tier-2 slots over the cited passages.
6. **Team-info.** Add a members door (roster) to the host placements; cut a lead whose values are all in the record block.
7. **Meetings agenda.** Name in the fallback note the asked concepts the fallback rows do not carry.
8. **Clarify.** Chips send a full sentence; rank readings backed by a host calculation first.
9. **Excel skip.** Resolve a named concept through the host's property mapping before condition 4: the QG prompt of this very turn says "contact: … otherwise omit it (not recorded for User)". A concept the entity has no further property for cannot add a column, so it is not a new ask.
10. **Export nouns.** Use the recorded subject noun on both paths.
11. **310.** Skip the homonym line when the lead already states the count and the shared name, or leave the lead empty.
12. **First-sentence marker.** If the rule is kept, check it in code (the first sentence has no marker while a later sentence of the same paragraph has one: move the marker), not by instruction.

Defects 1 to 12 need no model change and cover about 2.90 of the 3.90 points still missing; defects 13 and 14 are 0.35; the rest is 0.05-0.10 residuals on passing turns.

---

# 5 — TOKENS AND LATENCY VS p20

Tokens, summed per turn:

| Stage | p20 | p21 | Delta |
|---|---|---|---|
| understanding (13 calls) | 65,528 | 65,530 | +2 |
| query_generation (9 calls) | 61,961 | 61,427 | -534 |
| composition (10 calls) | 33,265 | 32,954 | -311 |
| search_probes (2 calls) | 1,651 | 1,660 | +9 |
| clarity_check (1 call) | 1,171 | 1,171 | 0 |
| follow_ups (2 calls, gpt-4o) | 2,225 | 2,197 | -28 |
| **Total (37 calls)** | **165,801** | **164,939** | **-862 (-0.5%)** |

- Input 160,570 → 159,977; output 5,231 → 4,962.
- QG: board -667 (QU variance, smaller schema); 310 turns +65 and +68 (the longer example note); Excel +37. **The Excel turn still spends 7,185 QG tokens**: the saving this iteration aimed at did not happen.
- Composition: tear-down -411 (passage [1] 1,384 characters shorter, output 254 → 155); 310 turns +62 and +54 (plural-subject line); buyer -13 with output 167 → 83.
- follow_ups: writer system prompt +335 characters, output 169 → 98 tokens (2 questions instead of 5).
- The iteration is token-neutral; the only planned saving (about 7k on the Excel turn) is not realised.

Latency:

| Stage | p20 sum / mean | p21 sum / mean |
|---|---|---|
| understanding | 72.9 s / 5.61 s (max 6.5 s) | 79.2 s / 6.09 s (max 7.5 s) |
| file_context (2 knowledge turns) | 9.1 s (4.78 s, 4.35 s) | 9.8 s (5.24 s, 4.52 s) |
| of which probe call | 4.6 s | 4.8 s |
| query_generation | 18.5 s / 2.05 s over 9 (max 2.8 s) | 22.4 s / 2.48 s over 9 (max 3.4 s) |
| execution | 1.3 s / 0.14 s over 9 | 0.9 s / 0.10 s over 9 |
| composition (10 LLM-composed turns) | 16.8 s / 1.68 s (tear-down 3.02 s) | 15.7 s / 1.57 s (tear-down 2.43 s) |
| follow_ups (2 turns) | 2.94 s / 1.47 s | 3.12 s / 1.56 s (1.59 s, 1.53 s) |
| clarity_check | 1.69 s | 1.99 s |
| recorded_result_export | 0 ms | 0 ms |
| empty_result_check | 0.04 s | 0.05 s |
| **pipeline** | **123.8 s / 9.5 s, median 9.2 s, max 14.6 s** | **133.5 s / 10.3 s, median 10.1 s, max 15.8 s** |

- +9.7 s overall, all provider latency on unchanged calls (understanding +6.3 s, QG +3.9 s); no stage was added and none removed.
- Knowledge turns: tear-down 14.6 s → 15.2 s, buyer 13.8 s → 15.8 s; still the two slowest.
- Understanding is 59% of pipeline time (p20: 59%).
