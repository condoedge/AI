# Grade: p22-1001-B (Part B) vs p21 and p20

- Run: `CondoEdge/storage/eval/p22-1001-B.json`, generated 2026-10-07T02:29. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3 "Novo Gestion", owns no unit). 0 void, 0 infra, 14 rows `ok`, 0 gate failures.
- Rubric and strictness as p14 to p21. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1. Follow-up chips are scored in the action score.
- Same ai package sha (badd2f1), dirty tree 597 -> 606 files. Host config sha 7902f5bf -> 9f458592. Models unchanged (QU and composition gpt-4o-mini, query generation and follow-up writer gpt-4o).
- Top-level counters describe one chunk only. Everything below is summed from the 14 rows: 165,205 tokens, 37 LLM calls (p21: 164,358 / 36; p20: 156,576 / 35).
- Headline: answers 0.879 -> **0.879**, actions 0.893 -> **0.900**, **14 pass / 0 partial / 0 fail** (p21: 13 / 1 / 0). Three answer gains on the rights conversation (+0.05 each) are offset by three losses elsewhere (-0.05 each): one new button-list echo the guard cannot see, and two generator / composer swings on unchanged prompts.

---

# 1. Per-turn grading

| Turn | p20 ans / act | p21 ans / act | p22 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|
| rights t0 | 0.70 / 0.80 | 0.65 / 0.80 | **0.70 / 0.85** | pass (borderline) | Six passages: [1] art. 1063-1066, [2] 1067-1069, [3] 1075.1-1079, [4] 1038-1040 (establishment, off topic), [5] 1070, **[6] art. 1054 (by-laws), added by passage selection**. Cited: [1], [2], [6]. Of the standard's four elements: free use yes; contribution yes, now as an explicit duty ("You are obligated to pay all common expenses ... including interest if applicable [2]", which is art. 1069, the acquirer's rule, as in p20); **contingency fund absent** (no passage holds art. 1071-1072); **by-laws now an explicit duty with its own citation [6]** (p21: only a proviso); access for work yes. Art. 1065 correct this time ("If you acquire or lease"). Against that: the Rights list holds **three duties** (contribution as "You are entitled to contribute", insurance "You must take out", the 15-day notice "you must notify"), the notice appears in both lists ("As mentioned"), revision (1068) and the register are gone, no article numbers, platitude closer shipped. Opening is an introduction, as p20. Net equal to p20. Action: two chips, both answerable in full from their excerpt (section 2); the fund still cannot be offered and the register was declined again. |
| rights t1 | 0.85 / 0.75 | 0.80 / 0.75 | **0.85 / 0.80** | pass | p20's shape, heading for heading. New and correct: annual contribution fixed by the board, funds included, notice (art. 1072) [2]; contingency fund for major repairs and replacement, sums from a study (art. 1071) [2]; vote lost after three months, **stated firmly this time** ("you will lose") (art. 1094) [4]; by-laws set the assessment and collection procedure (art. 1054) [3], on the thread. Gone against p20: the off-thread insurance bullet and the invented "garden or rooftop" example. Still: opens with an introduction, then two bullets on the covered art. 1064 (the deepening contract allows one sentence); "if you do not use certain common areas, you may not be liable for their upkeep" stretches the restricted-use rule; "a study conducted by the board" (the board obtains it); two padding sentences; **"In summary, ..." closer shipped**. No legal recovery (not in the corpus). Action: two chips; the special-contribution chip (art. 1072.1) is exactly the thread and fully answerable; the other repeats a sentence of the answer. |
| rights t2 | 0.75 / 0.90 | 0.80 / 0.90 | **0.85 / 0.90** | pass | "You have no unit linked to your account, so there are no contributions to show for this year." then "You may want to check the receivables report for more information." then the code line "Checked: invoices for your units -- nothing matched your question." **The echoed label is gone**; a natural pointer sentence is back. `empty_result_check {scope: own, ownership_check {Unit, count 0}, aggregate_over_nothing: true}`: the query was a `SUM(...)` that returned one row over nothing, and the pipeline still treated it as empty and did not print a $0 amount. No claim about the team. Residual: the door is still not said to be the portfolio's; "for your units" right after "no unit" reads oddly. Receivables report chip. |
| resilience t0 | 0.90 / 0.95 | 0.90 / 0.95 | **0.90 / 0.95** | pass | Lead cut (`restatement`), starts at "523 members.", Name / Email / Role, "Showing 25 of 523 members -- ask for the full list or an export." Header "Name" (alias `name`; p21 "User Name"). Chips Team settings + Download Excel. Residual as before: "(that's you)" on two cells, `t.id = 3`, no ORDER BY. |
| resilience t1 | 0.90 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass | Same query plus `u.last_logged_at AS last_login`. "523 members. Last Login recorded for 2 of the 25 shown." (`facts_line: [count, partly_recorded]`), true to the 25 rows. Lead kept: "Here are the last login details for the team members:". |
| resilience t2 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | "You can add new members to your team through the **Team Settings** section.", four grounded steps, contact distinction cited [1], closing pointer to the chip. `search_probes {skipped: navigation_target}`, no passage-selection call, no `follow_ups` stage, two calls. Roster survives (t3 exports 523). |
| resilience t3 | 0.90 / 0.85 | 0.95 / 0.85 | **0.95 / 0.85** | pass | `recorded_result_export {generation_skipped: true}`, one LLM call, 5,301 tokens. "I prepared the Excel file with all 523 rows." Name / Email / Role / Last Login, 523 rows (18 with a last login), title "Team members last login". |
| app-nav t0 | 0.90 / 1.00 | 0.90 / 1.00 | **0.85 / 1.00** | pass | Steps grounded, contact distinction cited [1], two files (p21 three). **New: the answer ends "Here are the next steps you can take:" + a bullet "- Team settings"**: the button listed in the text, after a pointer sentence that already names it. No `label_echo_dropped` (the guard reads one line at a time; section 2). Opening is p20's ("To add new members to your team, you need to follow these steps in the **Team Settings**:"). Chip correct. |
| cold-roster t0 | 0.85 / 0.85 | 0.90 / 0.85 | **0.90 / 0.85** | pass | `RETURN u.name AS user_name, u.email AS contact_email, u.id AS user_id`. "I prepared the Excel file with all 523 members." 523 rows, both columns filled, four harness gates pass. Headers "User Name" / "Contact Email" this run (p21 "Name" / "Email"). No roles. |
| french t0 | 0.85 / 0.90 | 0.90 / 0.90 | **0.85 / 0.90** | pass | Entirely French, lead cut, "523 membres.", French role labels, truncation line, both chips in French. **Membre / Rôle only** (`RETURN u.name AS membre, tr.role AS rôle`): the e-mail column of p21 is gone again, as in p20. Same instructions; generator variance. |
| french t1 | 0.90 / 0.85 | 0.95 / 0.85 | **0.95 / 0.85** | pass | `recorded_result_export {generation_skipped: true}`, **no query-generation call**, one LLM call, 5,176 tokens, 6.4 s (p21: 11,734 tokens, 10.4 s). "J'ai préparé le fichier Excel avec les 523 lignes." "Champs inclus : Membre, Rôle.", same columns as turn 1, 523 rows, "En date du", "Télécharger Excel". Residual: title "Membres de **mon** équipe". |
| digest t0 | 0.90 / 0.90 | 0.90 / 0.90 | **0.85 / 0.90** | pass | Composition prompt byte-identical to p21. Nine sources, `failed_sources: []`, true totals (130, 75, 189, 13, 36, 8), bills $11,493.17, one "Nothing pending in:" line, one closing question. **All eight framing sentences start in lower case** ("there are 130 tasks not yet closed.", "no meetings are currently scheduled."): cosmetic, in every section. 16 chips unchanged. |
| digest t1 | 0.90 / 0.85 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Normal pipeline, no digest block. Record block: Meeting Name, Date 2023-10-24 16:00, Place ZOOM, President Julie Rousseau (that's you), Union Name SDC du 1240 St-Sébastien. One chip, the meeting record. Lead restates name, date and place and adds "You presided over this meeting" (true to the row). |
| digest t2 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Composition prompt byte-identical to p21. `export_followup_offered: excel`, `failed_sources: []`, single closing line. Residual: "EXCEL" in capitals; "Most recent meetings show no scheduled items." 17 chips. |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p20 | 0.864 | 14 / 0 / 0 | 0.889 |
| p21 | 0.879 | 13 / 1 / 0 | 0.893 |
| **p22** | **0.879** (0 vs p21, +0.015 vs p20) | **14 / 0 / 0** | **0.900** (+0.007 vs p21, +0.011 vs p20) |

- p22 answers, t0 to t13: 0.70, 0.85, 0.85, 0.90, 0.95, 0.90, 0.95, 0.85, 0.90, 0.85, 0.95, 0.85, 0.90, 0.90 (sum 12.30).
- p22 actions, t0 to t13: 0.85, 0.80, 0.90, 0.95, 0.95, 1.00, 0.85, 1.00, 0.85, 0.90, 0.85, 0.90, 0.90, 0.90 (sum 12.60).
- Against p21: answer gains rights t0, t1, t2 (+0.05 each); answer losses app-nav, french t0, digest t0 (-0.05 each). Action gains rights t0, t1 (+0.05 each). No action loss.
- Against p20: answer gains rights t2 (+0.10), resilience t1, resilience t3, cold-roster, french t1 (+0.05 each); losses app-nav and digest t0 (-0.05 each).
- The digest t0 loss is purely cosmetic and pure variance; without it the answer mean is 0.882.
- Rights t0 is back on the pass line (0.70), not above it.

---

# 2. Fix verification

| Change (iteration 7) | Exercised on | Result | Evidence |
|---|---|---|---|
| Revert of "START WITH THE DIRECT ANSWER ... ENDS on the last of them" | rights t0, t1; resilience t2; app-nav | **Gone from all four prompts** | The line is in p21's composition user prompt on these turns and in none of p22's. p22 carries p20's line "Start with a direct answer to the question when the data supports one". Rights t0 instructions differ from p20 only by "[6]" in the three citation lists; system prompts byte-identical across p20, p21, p22. |
| ... shape of the law answers | rights t0, t1 | **Back to p20's shape; t1 slightly better in content, t0 equal** | t0: numbered "Rights of Co-Owners / Duties of Co-Owners" lists and the introduction of p20. t1: p20's headings ("Contribution to Common Expenses", "Financial Management"), without p20's off-thread bullet and invented example. Both closers now ship, because p20's closing drop is still narrowed (below). |
| ... how-to turns | resilience t2, app-nav | t2 keeps the direct first sentence without the line; app-nav back to p20's opening | Both still name Team Settings in sentence one. |
| Passage selection | rights t0, t1 | **One call each; t0 added one passage, used and cited; t1 added nothing** | Table below. |
| ... not on how-to or data turns | resilience t2, app-nav; 8 data / export turns; 2 digests | **Confirmed** | No `passage_selection` key in `file_context` and no `passage_selection` usage on any of them. Data turns: `file_context {skipped: question_type}`. |
| `votes_below_floor` | resilience t2, app-nav | Recorded, no effect on the files chosen | `{contacts.md: [search_query, clarified_question, search_tags]}` on both. Same two files as p21 on t2. Not present on the law turns (every angle admitted a passage of the one law file). |
| Follow-up excerpts never cut mid-sentence | rights t0, t1 | **Verified, 8 of 8 excerpts end on a sentence** | Table below. p21 sent "...is bound..." and "...mailing address of each...". |
| Sub-articles of a used article offered on a deepening turn | rights t1 | **Verified; a chip resulted** | `excerpts`: art. 1072 (tier 1), **art. 1072.1 (tier 1)**, 1070 (tier 2), 1073 (tier 2). Chip "Does the board need to consult co-owners before deciding on special contributions?" Art. 1071.1 was not among the four. |
| Label-echo guard | rights t2 | **Echo gone; the guard itself did not fire** | No `label_echo_dropped` on any of the 14 turns. The narrator line now reads `The buttons attached below this answer are "Receivables report" -- the user already sees them: never list or repeat them.` and the model wrote a normal pointer sentence. The fix seen here is the rewording, not the guard. |
| ... any new echo | app-nav | **Yes, one, and the guard cannot see it** | "Here are the next steps you can take:" / blank line / "- Team settings". `AttachedActions::withoutLabelEcho` tests one line or sentence at a time: the heading line holds the colon but no label, the bullet holds the label but no colon, so neither matches. |
| Export follow-up skips generation (French) | french t1 | **Skipped; the new rule was not what skipped it** | Stages `recorded_result_export {generation_skipped: true}` then `export {previous_result, rows: 523}`; usage is understanding only. QU returned `subject_label: "Membres de mon équipe"` with `entity_types: [Team, User, TeamRole]`, identical to p20, which also skipped. p21's trigger (a new label, no entity, no concept) did not recur, so the added case in `PreviousResultExport::asksNothingNew` is present in the source but unexercised by this run. |
| ... resilience t3 still skips | resilience t3 | Verified | `generation_skipped: true`, one call. |
| ... files unchanged | resilience t3, cold-roster, french t1 | Verified | 523 rows each. t3: Name, Email, Role, Last Login (18 filled). cold-roster: two columns, 523 filled each. french t1: the two columns of french t0. Column sets follow each turn's query, as before. |
| Closing drops | all | **0 drops** | No `closing_dropped` on any turn. Table below. |
| Checked line | rights t2 | **Moved, same code** | "Checked: invoices for your units ..." (p21: "invoices, units (your own records)"). `checked_kinds` is read from the executed query's projections: p21's query returned unit columns, p22's returns only `SUM(i.total_amount)`. QU selected Account instead of Budget / BudgetDetail this run, which changed the generated query. |
| "recorded for N of M" | resilience t1 | Unchanged | "523 members. Last Login recorded for 2 of the 25 shown." |
| Rosters EN + FR | resilience t0, french t0 | EN unchanged; FR lost its e-mail column | Generator variance: query-generation system prompt byte-identical to p21 on french t0. |
| Digest t0 to t2 | digest | Data and chips unchanged; t0 framing in lower case | t0 and t2 composition prompts byte-identical to p21. |
| Security | all 14 | **Nothing wrong** | `security_gate.passed: true` 14 / 14; `safe_stop: false` on all 6 executions; `security_filter_applied: true` on the 6 queries plus the enrichment query, none false. No SecurityException text in any trace. |
| Admission | all 14 | **Nothing wrong** | No `admission` stage, no busy answer, both digests `failed_sources: []`, `skipped_sources: []`. |

## Passage selection, per knowledge turn

| Turn | Final passages | Pool / picked / added | Cited in the answer | Effect | Cost |
|---|---|---|---|---|---|
| rights t0 | [1] 1063-1066, [2] 1067-1069, [3] 1075.1-1079, [4] 1038-1040, [5] 1070, **[6] 1054** | 20 / 1 / 1 (chunk 47, `as: passage`) | [1], [2], **[6]** ("Compliance with By-Laws ... [6]") | **Helped**: by-laws become an explicit, cited duty, one of the standard's four elements. On topic, nothing misled. | 3,951 in / 7 out, 1,558 ms |
| rights t1 | [1] 1063-1070 (merged), [2] 1071-1074, [3] 1052-1056, [4] 1092-1097, [5] 1044-1048 | 20 / 0 / 0 | [1], [2], [3], [4] | **Neutral**: nothing added. | 4,070 in / 5 out, 1,606 ms |
| resilience t2 | [1] team-and-members.md, [2] contacts.md | no call | [1] | n/a | 0 |
| app-nav t0 | [1] team-and-members.md, [2] contacts.md | no call | [1] | n/a | 0 |

- Rights t0, indivision (art. 1012-1037): **none** in the passages. `branch_coherence` dropped chunks 8, 9, 11, 15 of Chapter II, and the candidate pool starts at chunk 37.
- Rights t0, contingency fund (art. 1071-1072): **not in the passages, not in the answer.** Art. 1072 was candidate [AH] and art. 1072.1 / 1073 candidate [AI]; art. 1071 itself was not in the pool of 20. The prompt allows one id and the selector spent it on art. 1054.
- The step only adds: the off-topic [4] (art. 1038-1040) and the uncited [3] (art. 1075.1-1079) stay.
- Rights t1 [5] (art. 1044-1048, off topic, uncited) is not from selection: `passage_overlap {merged: [[59, 63, 66]], filled: [108, 39]}`, the same as p20. p21 merged only two chunks, so one slot was free and chunk 39 was turned away.
- Total cost of the step: 8,033 tokens and 3.2 s over two turns, for one useful passage.

## Every closer, and every drop

| Turn | Last paragraph | Dropped? | Verdict |
|---|---|---|---|
| rights t0 | "These rights and duties ensure that all co-owners contribute to the well-being and maintenance of the condominium while enjoying their individual rights." | No | Survived: platitude, **cosmetic** |
| rights t1 | "In summary, your duty to contribute to common expenses is both a legal obligation and a critical aspect of maintaining the shared property and ensuring its proper management." | No | Survived: platitude, **cosmetic** |
| rights t2 | "You may want to check the receivables report for more information." (then the code line) | No | **GOOD**: the pointer the prompt asks for |
| resilience t2 | "For more details, you can check the Team Settings directly." | No | **GOOD**: pointer to the chip |
| app-nav t0 | "For more details, you can access the **Team Settings** directly." then "Here are the next steps you can take:" + "- Team settings" | No | Pointer GOOD; the list after it is a button echo, **cosmetic defect** |

- Drops in Part B: none, so no GOOD and no HARMFUL drop. No conclusion was lost anywhere.
- Leads: resilience t0 and french t0 cut (`restatement`); resilience t1 and digest t1 leads kept, as p21.

## Every follow-up chip

| Turn | Chip question | Source (tier) | Label | Answerable from its excerpt |
|---|---|---|---|---|
| rights t0 | Can the apportionment of expenses for major repairs be different? | art. 1064 paragraph 2 (tier 1) | **A** (drops "common portions for restricted use") | Yes |
| rights t0 | Can I apply for a revision of the expense apportionment? | art. 1068 paragraph 1 (tier 2) | **N** (a right the answer did not give) | Yes |
| rights t1 | How will I be informed about my contribution amount and due date? | art. 1072 paragraph 3 (tier 1) | **O** (the answer already says "You will be notified of the amount due and the payment date") | Yes, by repeating the answer |
| rights t1 | Does the board need to consult co-owners before deciding on special contributions? | art. 1072.1 (tier 1) | **N** | Yes |
| digest t0 | Go through Open tasks (most urgent first) | section open_tasks | N | Yes; click not exercised |
| digest t0 | Go through Overdue receivables | section overdue_receivables | N | Yes; click not exercised |
| digest t0 | Go through Units with expired insurance | section expired_unit_insurance | A | Yes; click not exercised |
| digest t2 | Go through Open tasks (most urgent first) | section open_tasks | A (the ask was a file) | Yes; click not exercised |
| digest t2 | Go through Overdue receivables | section overdue_receivables | A | Yes; click not exercised |
| digest t2 | Go through Units with expired insurance | section expired_unit_insurance | A | Yes; click not exercised |

Totals: 10 chips; 4 N, 5 A, 1 O (p21: 7 chips; 3 N, 4 A, 0 O). Knowledge turns alone: 4 chips, 2 N, 1 A, 1 O, all four answerable (p21: 1 chip; p20: 3 chips, one answerable).

Excerpts offered and what the writer did:

| Turn | Excerpt | Tier | Complete sentence? | Writer | Right call? |
|---|---|---|---|---|---|
| rights t0 | art. 1064 paragraph 2 (different apportionment for major repairs) | 1 | Yes | taken | Yes |
| rights t0 | art. 1066 paragraph 2, first sentence (notices to a lessee) | 1 | Yes | declined | Yes |
| rights t0 | art. 1068 paragraph 1 (revision within five years) | 2 | Yes | taken | Yes |
| rights t0 | art. 1070 paragraph 1 (register at the disposal of co-owners) | 3, question-supported | Yes | declined | Debatable, as p21. The full sentence reached the writer this time, so the cut was not what blocked this chip. |
| rights t1 | art. 1072 paragraph 3 (notice of amount and date) | 1 | Yes | taken | **No**: the answer already carries it |
| rights t1 | art. 1072.1 (consultation before a special contribution) | 1 | Yes | taken | Yes |
| rights t1 | art. 1070 (register) | 2 | Yes | declined | Yes, off the thread |
| rights t1 | art. 1073, first two sentences (syndicate's insurance) | 2 | Yes | declined | Yes, off the thread |

---

# 3. Regressions

## Against p21

1. **App-nav: the button listed in the answer (-0.05). Model slip on a reworded line; the guard is structurally blind to it.**
   - The composition prompt differs from p21 by the reverted line, the reworded narrator line, and one file fewer. The phrase "next steps" is the prompt's own ("next steps are attached as buttons below your answer"); no stored Part B answer of p20 or p21 contains it.
   - Whether the rewording invites the list cannot be told from one occurrence in two how-to turns. That it shipped is code: the guard never joins a colon-terminated line with the list items under it.
2. **French t0: e-mail column gone (-0.05). Pure generator variance.** Query-generation system prompt byte-identical to p21; gpt-4o returned `membre, rôle` (as in p20) instead of `name, email, role`.
3. **Digest t0: framing sentences in lower case (-0.05). Pure composer variance.** Composition prompt byte-identical to p21 and p20.
4. **One already-answered chip (rights t1; action +0.05 instead of +0.10). Code.** The tier-1 test treated art. 1072 paragraph 3 as unused although the answer paraphrases it ("notified" / "notifies", "payment date" / "date when it is payable"), and the writer turned it into a question.
5. **Cost of the new call when it adds nothing (rights t1; 4,075 tokens, 1.6 s). Code, by design.**

## Against p20

1. **Platitude closers ship on both law turns (cosmetic).** p20's code dropped both. The narrowed closing rule of iteration 6 was kept when the prompt line was reverted, so neither the prompt nor the code removes them now. Known from p21; unchanged.
2. **App-nav echo and digest t0 lower case**, as above.
3. Nothing else is below p20. Rights t0 equals p20; rights t1 equals p20 on the answer and is above it on the action.

## Model variance on unchanged instructions (prompts compared)

- **Rights t0**: instructions identical to p20 apart from "[6]". Retrieval before selection is p21's set (chunks 59, 64, 82, 32, 69); probe 2 differs again. The composer filed three duties under Rights and reused art. 1069 as a general duty (as p20; absent in p21).
- **Rights t1**: probe 2 is the one p20 dropped as a duplicate; this run it was kept, which gives p20's merge and p20's two fills.
- **Rights t2**: QU selected Account instead of Budget / BudgetDetail; the generator wrote an aggregate query. Answer and checked line follow from that.
- **Headers**: "Name" on the resilience roster, "User Name" / "Contact Email" on the cold roster.
- **Query-generation input**: resilience t1 6,856 -> 6,129 (QU entity selection; same query).
- **Latency**: rights t1 understanding 12.2 s on an input of 4,935 tokens (p21 7.3 s); app-nav understanding 8.3 s (p21 5.1 s).

## Found while tracing, not scored

- **Rights t2 query has an unparenthesised OR**: `WHERE (unit branch) OR (contact branch) AND i.status IN [...] AND substring(i.invoiced_at, 0, 4) = '2026'`. The status and year filters bind to the contact branch only. Harmless here (the user has no contact, so nothing matches), but for a real owner the sum would include every invoice of the unit, any year, any status.
- Rights t1 excerpt E3 is headed "DIVISION IV -- RIGHTS AND OBLIGATIONS OF CO-OWNERS -- art. 1070"; art. 1070 is in Division V. The merged passage carries its first chunk's heading.
- After t0 cited [6], the t1 prompt lists art. 1054 as "ALREADY COVERED"; the t1 answer still used its second paragraph (collection procedure), which t0 had not given. Harmless.
- No trace line records that passage selection was skipped on how-to turns; its absence is the only sign.

Carried over, unchanged: "(that's you)" on two cells of one row; "EXCEL" in capitals; "mon équipe" in the French export title; `t.id = 3` literal and no ORDER BY in roster queries; the roster introduction on a follow-up turn; the record lead that restates block values; the digest chip surface.

Fixed from p21's list: excerpts cut mid-sentence (#2), no on-thread excerpt on the deepening turn (#1, sub-article half), echoed label on rights t2 (#5), French export regeneration (#8, as observed).

---

# 4. Remaining defect ranking

Turns below 0.90 on answer: rights t0 (0.70), rights t1, rights t2, app-nav, french t0, digest t0 (0.85 each). Actions below 0.90: rights t1 (0.80), rights t0, resilience t3, cold-roster, french t1 (0.85).

| # | Defect | Turns | Points at stake | Lost at | Variance? | Most structural fix | Determinism |
|---|---|---|---|---|---|---|---|
| 1 | Contingency fund absent from the overview: selection may add one passage and spent it on by-laws; two off-topic passages keep their slots | rights t0 | 0.10 answer, plus the fund chip | Passage selection (cap of 1, add-only) | Partly: which candidate is picked is a model choice | Allow two additions when the pool holds them (art. 1072 was a candidate). Measure on replays first, as was done for tear-down: the risk is near-miss additions. | Medium |
| 2 | Overview misfiling: duties listed under Rights, one duty in both lists, art. 1069 as a general duty | rights t0 | 0.10-0.15 answer; decides pass or partial | Composition | **Pure variance** | None deterministic. | Low |
| 3 | Button list echoed across two lines passes the guard | app-nav | 0.05 answer | `AttachedActions::withoutLabelEcho` | Model slip triggers it | Treat a line ending in a colon together with the list items under it as one unit: when every item is only a button label, drop the heading and the items. Still structure only. | Very high |
| 4 | Already-answered chip: tier 1 offers a paragraph the answer paraphrases | rights t1 | 0.05 action | `FollowUpWriter` (unused-paragraph test) | Needs a paraphrase with different word forms | Compare on word stems or prefixes rather than whole words, in the unused-paragraph test and again on the written fact before a chip is kept. | High |
| 5 | Platitude closer survives the narrowed drop | rights t0, t1 | up to 0.05 answer each | `AnswerValidator` closing rule | No | As p21 #6: when the first block of the answer carries a citation, the old rule is safe. On this run neither first block is cited, so this would not have fired; the alternative is to drop a last paragraph that adds no cited claim and no word absent from the body. | High |
| 6 | Covered rule restated in two bullets before anything new | rights t1 | 0.05 answer | Composition (deepening block ignored) | Mostly variance | Unchanged from p20. | Low |
| 7 | Digest framing sentences in lower case | digest t0 | 0.05 answer | Composition | **Pure variance** | Code capitalises the first letter of each framing line when it places it. | Very high |
| 8 | Roster column set follows the generator (e-mail in or out) | french t0, cold-roster | 0.05 answer | Query generation | **Pure variance** | As p20 #4: host-declared default columns for a roster, applied by code. | High |
| 9 | Empty-answer door not said to be the portfolio's; "for your units" beside "no unit" | rights t2 | 0.05-0.10 answer | Composition and the checked-line template | Partly | When the ownership count is 0, word the scope as "your own records"; let code add one clause naming what the attached report covers. | High |
| 10 | Unparenthesised OR / AND in a generated WHERE | rights t2 (latent) | 0 here; wrong money for a real owner | Query generation | Variance | Lint the generated WHERE for mixed OR and AND at one level without parentheses; send it to repair. | High |
| 11 | Off-topic single-group fill when a merge frees two slots | rights t1 | 0 points; about 340 prompt tokens | `passage_overlap` fill | Probe wording | Fill a freed slot only with a question-supported passage; otherwise leave it empty. | High |
| 12 | Selection call that adds nothing | rights t1 | 0 points; 4.1k tokens, 1.6 s | Passage selection | No | Cost of the design. Worth a replay count of how often deepening turns get an addition. | n/a |
| 13 | Roster introduction survives on a follow-up turn | resilience t1 | 0 now | `DataAnswerPlan::boundedLead` | Constant | As p21 #9. | High |
| 14 | Headers follow the generator's alias | resilience, cold-roster | 0-0.05 | Query generation | Yes | As p21 #10. | High |
| 15 | "(that's you)" on two cells of one row | rosters EN | 0-0.05 | Table renderer | No | Mark the name cell only. | Very high |
| 16 | Export action surface: one link, "mon équipe", "EXCEL" | exports, digest t2 | 0.05 action each | Export composer | No | As p21 #14. | High |
| 17 | Record lead restates block values | digest t1 | 0-0.05 | Composition | Variance | As p20 #12. | Medium |

## Pure model variance

- Rights t0: which items go under Rights, the reuse of art. 1069, the reading of art. 1065, article numbers.
- Rights t1: how often the covered rule is restated; padding sentences.
- App-nav: the "next steps" list (the miss is code, the slip is the model's).
- French t0 and cold-roster column sets; header aliases.
- Digest t0 capitalisation.
- QU entity selection on rights t2 and resilience t1; the unparenthesised OR.
- Probe 2 wording on both law turns.

## Reading of the run

- The revert did what it was meant to: the law answers are p20's again and no answer lost its condition. It did not bring back p20's closing drop, so both platitudes ship.
- Passage selection paid for itself once in two calls. It fixed the by-laws element on rights t0 and could not fix the fund, because it may add one passage and removes none.
- The follow-up changes are the clearest gain: four law chips, all answerable, and the special-contribution chip is the one p21 asked for. One chip repeats the answer.
- Rights t2 is the best it has been (0.85). The rewording did it; the guard has yet to be seen working.
- The French skip is real in cost (-6.6k tokens, -4 s) but came from QU returning the recorded label, not from the new rule.
- Three of the six sub-0.90 answers are swings on byte-identical or unchanged instructions.

---

# 5. Tokens and latency vs p21

| Stage | p21 input / output (calls) | p22 input / output (calls) | Delta input |
|---|---|---|---|
| understanding | 68,708 / 2,729 (14) | 68,700 / 2,777 (14) | -8 |
| search_probes | 1,355 / 305 (2) | 1,354 / 311 (2) | -1 |
| **passage_selection (new)** | none | **8,021 / 12 (2)** | **+8,021** |
| query_generation | 47,412 / 674 (7) | 40,093 / 681 (6) | **-7,319** |
| composition | 39,282 / 1,448 (11) | 39,179 / 1,668 (11) | -103 |
| follow_ups (gpt-4o) | 2,369 / 76 (2) | 2,258 / 151 (2) | -111 |
| **Total (in + out)** | **164,358** (36 calls) | **165,205** (37 calls) | **+847 (+0.5%)** |

- Passage selection: rights t0 3,951 / 7, rights t1 4,070 / 5. About 4k prompt tokens per law turn (rights t0 13.0k -> 17.1k, rights t1 14.9k -> 19.4k: +30%).
- Query generation: french t1 -6,544 input and -43 output (the skipped call), resilience t1 -727 (QU entity selection).
- Composition: rights t0 +124 (passage [6]), rights t1 +338 (merged [1] and the art. 1044-1048 fill), app-nav -314 (two files), french t0 -177 (no e-mail column), resilience t2 -69. Output +220: longer law answers (337 -> 409, 328 -> 431).
- Follow-ups: input down with shorter excerpts (1,186 -> 1,144 and 1,183 -> 1,114); output up with the chips (49 -> 75, 27 -> 76).

| Stage latency | p21 mean / median | p22 mean / median |
|---|---|---|
| understanding | 6.2 s / 5.9 s (max 8.7) | 6.5 s / 6.1 s (max 12.2, rights t1) |
| file_context (4 knowledge turns) | 5.0, 4.8, 2.0, 2.5 s | 6.2, 6.4, 2.7, 2.7 s |
| of which probe call | 2.5 s, 2.4 s | 2.3 s, 2.3 s |
| of which passage selection | none | **1.6 s, 1.6 s** |
| query_generation | 2.1 s / 1.9 s (7) | 2.1 s / 2.0 s (6) |
| execution | 0.2 s / 0.2 s (6 real) | 0.3 s / 0.2 s (6) |
| empty_result_check | 0.03 s | 0.04 s |
| record_enrichment | 0.03 s (1) | 0.06 s (1) |
| recorded_result_export | 0 s (1) | 0 s (2) |
| composition (14 rows) | 2.0 s / 1.6 s | 2.3 s / 1.6 s |
| follow_ups | 1.7 s (1.5, 1.9) | 1.8 s (1.8, 1.7) |
| digest | 0.5 s | 0.6 s |
| **pipeline** | **10.7 s / 10.1 s** (max 18.5) | **11.5 s / 10.2 s** (max 25.5) |

- The mean turn is 0.7 s slower; the median is unchanged.
- Law turns: +1.2 s and +1.5 s of file_context, almost all the selection call; composition +1.8 s and +0.9 s with longer answers.
- Rights t1 at 25.5 s is the slowest turn of the three runs: 4.8 s of it is understanding on unchanged input (API latency), 1.6 s the new call.
- French t1 10.4 -> 6.4 s (no generation call, and 2.5 s less understanding).
- Per turn, p21 -> p22 (s): 17.0 -> 20.1, 18.5 -> 25.5, 11.1 -> 10.3, 10.4 -> 9.8, 9.3 -> 8.2, 9.9 -> 10.9, 6.1 -> 5.6, 9.9 -> 13.3, 8.8 -> 9.5, 9.3 -> 10.2, 10.4 -> 6.4, 8.9 -> 10.1, 10.3 -> 10.2, 10.5 -> 10.2.
- The trace still counts the follow-up call inside the pipeline time.
