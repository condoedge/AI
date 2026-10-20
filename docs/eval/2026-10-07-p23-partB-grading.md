# Grade: p23-1001-B (Part B) vs p22 and p21

- Run: `CondoEdge/storage/eval/p23-1001-B.json`, generated 2026-10-07T03:42. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3 "Novo Gestion", owns no unit). 0 void, 0 infra, 14 rows `ok`, 16 / 16 harness gates passed.
- Rubric and strictness as p14 to p22. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1. Follow-up chips are scored in the action score.
- Same ai package sha (badd2f1), dirty tree 606 -> 610 files. Host config sha 9f458592 -> 676831b1 (the ownership-path edit). Models unchanged.
- Top-level counters describe one chunk only. Everything below is summed from the 14 rows: **180,244 tokens, 40 LLM calls** (p22: 165,205 / 37).
- Headline: answers 0.879 -> **0.846**, actions 0.900 -> **0.825**, **13 pass / 1 partial / 0 fail** (p22: 14 / 0 / 0).
- The drop is not from iteration 8. Two query-understanding flags flipped on prompts that are byte-identical in their instructions: `digest_request` on digest t2 (the digest lost the turn) and `navigation_target` on app-nav (the Team settings button was lost and three unneeded calls ran). With those two turns at their p22 routing the run would read about 0.886 / 0.907.
- Iteration 8 was almost entirely **unexercised** by this part: no generated query held an OR, no label echo occurred, and the "already told" excerpt did not need the stem rule this time. Only the digest capitalisation shows a result, and the trace cannot say whether code or the model produced it.
- "All harness gates passed" hides both regressions: neither digest t2 nor app-nav carries a gate (`gates: []` on both).

---

# 1. Per-turn grading

| Turn | p21 ans / act | p22 ans / act | p23 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|
| rights t0 | 0.65 / 0.80 | 0.70 / 0.85 | **0.80 / 0.85** | pass | Best overview of the series. Rights: use and enjoyment (art. 1063) [1], vote at the general meeting (art. 1096) [2], access to the register (art. 1070) [3]. Duties: contribution in proportion to relative value (art. 1064) [1], liability insurance (art. 1064.1) [1], 15-day notice on lease or acquisition (art. 1065) [1], no interference with work (art. 1066) [1]. **Nothing misfiled, no duplicate, article numbers on every item, all seven checked true against the passage text.** Platitude closer dropped by code (`closing_dropped`). Against the standard's four elements: free use yes; contribution yes, **contingency fund absent** (no passage holds art. 1071-1072); **by-laws only as a proviso** under Use and Enjoyment (p22 had them as a cited duty: passage selection added art. 1054 then, nothing now); access for work yes. Small stretch: "major works" is art. 1097, cited as 1096 (same passage). Opening is still an introduction. Action: three chips, all answerable from their excerpt (1 natural, 2 acceptable); the fund still cannot be offered. |
| rights t1 | 0.80 / 0.75 | 0.85 / 0.80 | **0.75 / 0.90** | pass | Correct and new: annual contributions set by the board, funds included (art. 1072) [2]; notice of amount and due date (art. 1072) [2]; vote lost after three months (art. 1094) [4]; declaration and by-laws on assessment and collection (art. 1054) [3]. The restricted-use rule is stated correctly this time. Against p22: **no contingency-fund point of its own** (the standard names it; the fund is only mentioned inside the annual contribution); **off-thread insurance bullet is back** (art. 1064.1, already given in t0); vote loss hedged ("may lose", art. 1094 says "is deprived"); two bullets again on the covered art. 1064; **closer shipped with a platitude and a bare invitation** ("...feel free to ask!"). No legal recovery (not in the corpus). Action: two chips, both natural and answerable: special contributions (art. 1072.1) and "What is the contingency fund used for?" (art. 1071), which covers the answer's gap. **The already-answered chip of p22 is gone.** |
| rights t2 | 0.80 / 0.90 | 0.85 / 0.90 | **0.80 / 0.90** | pass | Same two sentences as p22 ("You have no unit linked to your account, so there are no contributions to show for this year. You may want to check the receivables report for more information."), then **the checked line twice**: "Checked: invoices for your units — nothing matched your question." once written by the model, once appended by code. `empty_result_check {scope: own, ownership_check {Unit, count 0}, aggregate_over_nothing: true}`. No $0 amount, no claim about the team, no label echo. Receivables report chip. |
| resilience t0 | 0.90 / 0.95 | 0.90 / 0.95 | **0.90 / 0.95** | pass | Same query, answer byte-identical to p22 (sha 999b10f7). "523 members.", Name / Email / Role, truncation line, Team settings + Download Excel. |
| resilience t1 | 0.95 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass | Same query, answer byte-identical to p22 (sha 8ef84287). "523 members. Last Login recorded for 2 of the 25 shown." |
| resilience t2 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | `navigation_target: own_team`, probes skipped, two calls. Four grounded steps, contact distinction cited [1], one more grounded sentence (roles and removal are on the same page: "Managing existing members" in the doc), pointer "through the link below". Team settings chip. The first sentence no longer names Team Settings (step 1 does). No echo. Roster survives (t3 exports 523). |
| resilience t3 | 0.95 / 0.85 | 0.95 / 0.85 | **0.95 / 0.85** | pass | `recorded_result_export {generation_skipped: true}`, one call, 5,304 tokens. File rows byte-identical to p22 (sha 96b97a8ab5): Name / Email / Role / Last Login, 523 rows, 18 with a last login. Title "Team Members Last Login" (capitalisation follows the QU label). |
| app-nav t0 | 0.90 / 1.00 | 0.85 / 1.00 | **0.85 / 0.30** | pass (answer); action failed | Text is the cleanest yet: direct first sentence naming Team Settings, four grounded steps, contact distinction cited [1], no hedge, no echo. **No button at all**: `invocations` and `attached_actions` are absent, so the standard's "ending with the CORRECT action link" is not met and the answer ends without a door. QU did not return `navigation_target` (p22: `own_team`) on a byte-identical prompt (sha acc6494654 both runs). Consequences in section 2. |
| cold-roster t0 | 0.90 / 0.85 | 0.90 / 0.85 | **0.90 / 0.85** | pass | New query adds `collect(DISTINCT cd.value) AS contact_info` from ContactDirectory. "I prepared the Excel file with all 523 rows." User Name / User Email / **Contact Info** (phones and e-mails, filled on 516 of 523; the 7 empty are staff accounts). Same 523 members as p22 (same sorted-name hash). Four gates pass, no empty promised column. Richer than p22 for "with contact info"; the new column mixes phones and e-mails and repeats the e-mail; "rows" instead of "members"; no roles. |
| french t0 | 0.90 / 0.90 | 0.85 / 0.90 | **0.90 / 0.90** | pass | Entirely French, "523 membres.", **Nom / Courriel / Rôle** (e-mail column back; aliases `name, email, role`, headers still rendered in French), truncation line, both chips in French. Residual: "(c'est vous)" on two cells. |
| french t1 | 0.95 / 0.85 | 0.95 / 0.85 | **0.95 / 0.85** | pass | `recorded_result_export {generation_skipped: true}`, one call, 5,201 tokens, 6.1 s. "Champs inclus : Nom, Courriel, Rôle.", the columns of turn 1, 523 rows, "En date du", "Télécharger Excel". Residual: title "Membres de **mon** équipe". |
| digest t0 | 0.90 / 0.90 | 0.85 / 0.90 | **0.90 / 0.90** | pass | Composition prompt byte-identical to p22 (sha c984fa26a1). Nine sources, `failed_sources: []`, true totals (130, 75, 189, 13, 36, 8), bills $11,493.17. **All eight framing lines start with a capital or a figure** ("There are 75 overdue receivables.", "No meetings are scheduled.", "130 tasks are currently open."). 53 table lines identical to p22. 16 chips unchanged. |
| digest t1 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Same query, no digest block. Record block unchanged (name, 2023-10-24 16:00, ZOOM, president, union). Lead restates name, date and place; "You presided" is gone. One record chip. |
| digest t2 | 0.90 / 0.90 | 0.90 / 0.90 | **0.40 / 0.45** | **partial** | **The digest did not own the turn.** QU returned `digest_request: false` (p22: true) with `export_format: excel`; the pipeline generated one query over Task and exported it: "I prepared the Excel file with all 131 tasks. Fields included: Task Title, Task Status, Task Urgency, Last Updated." No `response_metadata.digest`, no `export_followup_offered`, 7 of the 8 pending sections absent with no word about them, no section chips, no follow-ups. Honest about what it holds, real rows, no refusal, no "which data?" — that keeps it out of the fail band. File quality: 121 of 131 rows show urgency "0", timestamps are raw ("2023-10-17T22:54:09.000000Z"), and 131 contradicts turn 1's "130 tasks". |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p21 | 0.879 | 13 / 1 / 0 | 0.893 |
| p22 | 0.879 | 14 / 0 / 0 | 0.900 |
| **p23** | **0.846** (-0.033) | **13 / 1 / 0** | **0.825** (-0.075) |

- p23 answers, t0 to t13: 0.80, 0.75, 0.80, 0.90, 0.95, 0.90, 0.95, 0.85, 0.90, 0.90, 0.95, 0.90, 0.90, 0.40 (sum 11.85).
- p23 actions, t0 to t13: 0.85, 0.90, 0.90, 0.95, 0.95, 1.00, 0.85, 0.30, 0.85, 0.90, 0.85, 0.90, 0.90, 0.45 (sum 11.55).
- Answer gains vs p22: rights t0 +0.10, french t0 +0.05, digest t0 +0.05. Losses: digest t2 -0.50, rights t1 -0.10, rights t2 -0.05.
- Action gains: rights t1 +0.10. Losses: app-nav -0.70, digest t2 -0.45.
- Without the two QU flag flips (app-nav at 0.90 / 1.00 for its cleaner text, digest t2 at 0.90 / 0.90): 0.886 / 0.907.

## Movement vs p22, with the cause

| Turn | Move (ans / act) | Cause | Code or variance |
|---|---|---|---|
| rights t0 | +0.10 / 0 | Probe 2 came back a near-duplicate and was dropped (`probes_dropped`, similarity 0.882), so chunks 58, 61, 64 merged into one passage holding art. 1062-1069 and a general-meeting passage took a free slot. The composer filed everything correctly. The closer was dropped by the same closing rule as p22 (its words were less grounded this time). | Variance (probe wording, composer). Composition instructions identical to p22 apart from the passage numbers. Not bankable. |
| rights t1 | -0.10 / +0.10 | Composer left the fund out, re-served insurance, hedged the vote, added an invitation. System prompt identical; the user prompt differs only in the "ALREADY COVERED / NEW" lists and the previously cited passages, which follow from t0. Action: the art. 1072 paragraph 3 excerpt was not offered, art. 1071 was. | Answer: variance. Action: consistent with the iteration-8 rule, not isolated by it (section 2). |
| rights t2 | -0.05 / 0 | The model copied the quoted checked line; code appended its own copy without looking. Composition prompt differs from p22 by one subject label. | Variance triggers it; the duplicate shipping is code (`ResponseGenerator.php` line 1148, unconditional append). |
| app-nav | 0 / -0.70 | `navigation_target` absent from QU on a byte-identical prompt. | Pure QU variance; the size of the damage is structural (one optional field carries the button, the probe skip and the how-to routing). |
| french t0 | +0.05 / 0 | QU wrote the requested attributes as "membres, rôles" instead of "membre, rôle"; the generator returned name, email, role. | Variance. |
| digest t0 | +0.05 / 0 | Framing lines capitalised. | Iteration 8 code or the model: not recorded (section 2). |
| digest t2 | -0.50 / -0.45 | `digest_request: false` from QU. Understanding prompt identical to p22 except the two assistant summaries of the earlier turns. | Pure QU variance. First flip on this turn in p20-p23. |
| cold-roster | 0 / 0 | QU's clarified intent mentions "contact information entities"; the generator joined ContactDirectory. | Variance, favourable. |

---

# 2. Verification

| Item | Result | Evidence |
|---|---|---|
| Rights t2 cypher: OR parenthesised? | **No OR in the query at all** | `MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.budget_id IS NOT NULL AND i.status IN ['2','3','4'] AND i.due_amount >= 0.01 RETURN sum(i.total_amount) AS annual_contribution LIMIT 100`. The generator took the `[:CUSTOMER]` edge (unit branch only) instead of the two-branch ownership path. |
| ... precedence repair fired? | **No** | `attempts: 1`, `repair_attempts: 0`, `precedence_repaired: []`, `precedence_violations: []`. Nothing to repair. |
| ... host config edit present? | Yes, unused | The prompt's ownership path now reads `WHERE ((n.customer_type = "unit" AND ...) OR (n.customer_type = "contact" AND ...))`. The generator did not use it this run. |
| ... answer and checked line true to the executed query? | **True, printed twice** | One row (a sum over nothing), ownership check Unit count 0. "No unit linked to your account" is the verified reason. "Checked: invoices for your units" matches the query, which reads invoices through units only. The query has no year predicate; "for this year" still holds because nothing matched in any year. |
| ... new latent defect on the same turn | **"This year" is not filtered** | No `invoiced_at` predicate; `due_amount >= 0.01` restricts the sum to invoices still owing; invoices billed to the contact are left out. For a real owner the figure would be the invoiced total of unpaid contribution invoices of every year. Harmless here. Not the precedence class, so the new check cannot see it. Source: QU selected Fund / Budget / BudgetDetail (p22: Account), the `IS_CONTRIBUTION` scope was detected, and the generator applied the "money owed" rule. |
| Precedence check on other turns | **Fired on none** | 7 generations, all `precedence_violations: []`, `precedence_repaired: []`, `attempts: 1`. p22 rows do not carry the fields. |
| Any cypher mixing OR and AND at one level | **None** | No OR or XOR in any of the 7 generated queries or in the enrichment query. The check is wired and cost nothing; it has yet to be seen working on live output. |
| Queries changed vs p22 | 3 changed, 3 identical, 1 new | Table below. |
| App-nav / how-to: label echo | **None on either turn** | Resilience t2 ends on a pointer sentence, no list. App-nav has no buttons, so nothing to echo. |
| ... steps intact | Yes | Four steps on both turns, all from the doc. |
| ... `label_echo_dropped` values | **None recorded on any of the 14 turns: 0 GOOD, 0 HARMFUL** | The two-line guard (`AttachedActions::withoutLabelLists`) is in the source and unexercised. |
| Digest t0 framing capitalised | **Yes, 8 of 8** | 5 lines start with a figure, 3 with a capital. Composition prompt byte-identical to p22, where all 8 were lower case. No trace field records `DigestResponseComposer::sentenceCase` changing a letter, so the model may have capitalised them itself. |
| ... code-rendered sections untouched | Yes | 53 table lines identical to p22; headings, "Showing 5 of N." lines, "Total Due Amount: $11,493.17" and "Nothing pending in:" unchanged; `digest` metadata identical. |
| Digest t2 framing | **Not exercised** | The turn was not a digest. |
| Rights t0: indivision passage absent? | **Absent** | `branch_coherence` dropped chunks 8, 9, 11, 13 of Chapter II; the five passages are all Chapter III. |
| Rights t0: contingency fund present? | **No, in neither passages nor answer** | Passages: art. 1062-1069, 1095-1100, 1070-1070.2, 1076.1-1081, 1038-1041. The fund appears only inside art. 1070 ("contingency fund study") and art. 1076-1081. Art. 1072 was candidate [AI] and art. 1054 candidate [AE]; the selector picked nothing. |
| Passage selection | 3 calls, 0 passages added | Table below. |
| "Already told" chip of p22 rights t1 gone? | **Gone** | Excerpts offered: art. 1072.1 (tier 1), 1067, 1071, 1052 (tier 2). Art. 1072 paragraph 3 is not among them although the answer again says "The syndicate must notify each co-owner of their contribution amount and the due date". |
| ... was it the stem rule? | **Not shown by this run** | This answer repeats the paragraph's own words (syndicate, each, co-owner, contribution, amount, date), which reaches the 0.5 share on whole words. "notify" / "notifies" share 5 letters, under `STEM_LETTERS = 7`. The rule exists (`FollowUpWriter::STEM_LETTERS`) and is consistent with the result. |
| ... natural chip lost? | **None on rights t1** (special contributions kept, fund chip new). On rights t0 the chips changed with the passages: p22's natural "revision of the apportionment" (art. 1068) was not offered; "consult the register" (art. 1070.1) is new and natural. | `follow_ups` stage, both turns. |
| Export follow-ups: generation skipped? | **Yes, both** | Resilience t3 and french t1: `recorded_result_export {generation_skipped: true, recorded_rows: 100}` then `export {previous_result, rows: 523}`; usage is understanding only. |
| ... files unchanged? | Resilience t3 identical; french t1 follows its turn 1 | t3: same row hash as p22. French t1: Nom / Courriel / Rôle (p22: Membre / Rôle) because turn 1's query returned the e-mail this run; same 523 members. |
| Security | **Nothing wrong** | `security_gate.passed: true` 14 / 14; `safe_stop: false` on all 7 executions; `security_filter_applied: true` on the 7 queries and the enrichment query. No SecurityException text in the trace. The cold-roster query's new OPTIONAL MATCH carries `c.team_id IN $ai_team_ids` on the match that returns `cd.value`; the names and e-mails are those of users whose current team is 3. |
| Admission | **Nothing wrong** | No `admission` stage, no busy answer, digest t0 `failed_sources: []`, `skipped_sources: []`. |

## Queries, p22 against p23

| Turn | Query | Rows / answer |
|---|---|---|
| rights t2 | Changed (above) | Both: one row summing nothing, same two sentences; p23 prints the checked line twice. |
| resilience t0, t1 | Identical | 100 of 523; answers byte-identical. |
| cold-roster | Changed: + ContactDirectory, `collect(DISTINCT cd.value)` | Same 523 members; one more column, 516 filled. |
| french t0 | Changed: `name, email, role` (p22 `membre, rôle`) | Same 100 of 523, same first 25 members; e-mail column added. |
| digest t1 | Identical | Same record. |
| digest t2 | New (p22 ran the digest, no generated query) | 131 tasks, `status IN [1, 2, 3]`, ordered by urgency. |

## Call and token accounting

Second half (turns 7 to 13): p22 15 calls / 71,699 tokens (69,448 input) -> p23 **18 calls / 85,641 tokens (83,323 input)**: +3 calls, +13,942 tokens.

| Turn | Stage | p22 | p23 | Delta tokens | Needed? |
|---|---|---|---|---|---|
| app-nav | search_probes | skipped (`navigation_target`) | 1 call, 810 | +810 | **No.** The probe was written in law terms ("The syndicate of co-owners may decide to add new members...") and pulled the Civil Code into an app how-to. |
| app-nav | passage_selection | no call | 1 call, 3,742 (18 law candidates, picked none) | +3,742 | **No.** |
| app-nav | composition | 2,745 | 5,814 | +3,069 | **No.** Four law passages (art. 1081-1085, 1094-1102, 1075-1078, 1103.1-1106) were added to the prompt; the answer cites [1] only. |
| app-nav | follow_ups | no call | 1 call, 1,066 (4 tier-3 excerpts, proposed 0) | +1,066 | **No.** The writer rightly declined all four. |
| app-nav | understanding | 4,966 | 4,947 | -19 | |
| **app-nav total** | | 7,711 / 2 calls | 16,379 / 5 calls | **+8,668, +3 calls** | Zero benefit, and the button was lost. |
| digest t2 | query_generation | no call | 1 call, 7,786 | +7,786 | **No.** Wrong route. |
| digest t2 | composition | 1 call, 2,732 | no call | -2,732 | |
| **digest t2 total** | | 7,924 / 2 calls | 12,980 / 2 calls | **+5,056, 0 calls** (understanding +2) | |
| french t0 | composition | 2,597 | 2,776 | +179 (e-mail column in the prompt) | Yes |
| others | | | | +39 (cold-roster +30, french t1 +25, digest t0 -25, digest t1 -1, french t0 other stages +10) | |

- All three extra calls and 8,668 of the 13,942 extra tokens are one turn, app-nav, and all follow from one missing QU field. The CCQ file was below the floor on the three angles of the question itself (`votes_below_floor: {CCQ: [search_query, question, clarified_question]}`) and entered through the model-written probe alone.
- First half: +1,097 tokens, 0 calls (rights t0 +669, mostly the merged passage; rights t2 +336, three entity cards instead of one; rights t1 +75).
- Iteration 8 itself cost nothing: no repair attempt, no extra call.

## Passage selection, per knowledge turn

| Turn | Final passages | Pool / picked / added | Cited | Effect | Cost |
|---|---|---|---|---|---|
| rights t0 | [1] 1062-1069 (merged), [2] 1095-1100, [3] 1070-1070.2, [4] 1076.1-1081, [5] 1038-1041 | 18 / 0 / 0 | [1], [2], [3] | **None.** Art. 1054 [AE] and art. 1072 [AI] were candidates and neither was picked; p22 picked art. 1054. | 3,826 in / 5 out, 1.3 s |
| rights t1 | [1] 1063-1070 (merged), [2] 1071-1074.1, [3] 1052-1056, [4] 1092-1097, [5] 1044-1048 | 20 / 0 / 0 | [1], [2], [3], [4] | None, as p22 (same prompt, same result). | 4,070 in / 5 out, 1.9 s |
| resilience t2 | [1] team-and-members.md, [2] contacts.md | no call | [1] | n/a | 0 |
| app-nav t0 | [1] team-and-members.md, [2]-[5] four law passages | 18 / 0 / 0 | [1] | None; the call should not have run. | 3,737 in / 5 out, 1.1 s |

Total: 3 calls, 11,648 tokens, 4.3 s, no passage added (p22: 2 calls, 8,033 tokens, one useful passage).

## Closers

| Turn | Last paragraph | Dropped? | Verdict |
|---|---|---|---|
| rights t0 | "These rights and duties are designed to ensure the smooth operation and maintenance of the condominium, balancing individual ownership with the collective interests of all co-owners." | **Yes** | **GOOD**: platitude, nothing lost. Same rule as p22; this wording fell under the grounded share. |
| rights t1 | "Understanding these responsibilities is crucial ... If you have any further questions ..., feel free to ask!" | No | Survived: platitude plus a bare invitation, cosmetic. |
| rights t2 | Pointer sentence, then the checked line twice | No | Pointer GOOD; duplicate is a defect. |
| resilience t2 | "You can access the Team Settings directly through the link below." | No | GOOD |
| app-nav | Contact distinction [1] (no pointer, no button) | No | Ends without a door. |

## Every follow-up chip

| Turn | Chip question | Source (tier) | Label | Answerable from its excerpt |
|---|---|---|---|---|
| rights t0 | What notices does the syndicate give if I lease my unit? | art. 1066 paragraphs 2-3 (tier 1) | **A** (narrow) | Yes |
| rights t0 | How can I consult the syndicate's register and documents? | art. 1070.1 (tier 1) | **N** | Yes |
| rights t0 | What binds me and other co-owners in the condominium? | art. 1062 (tier 2) | **A** (vague wording; it is the declaration thread) | Yes |
| rights t1 | Does the board need to consult co-owners for special contributions? | art. 1072.1 (tier 1) | **N** | Yes |
| rights t1 | What is the contingency fund used for in a condominium? | art. 1071 (tier 2) | **N** (the answer names the fund and does not say what it is for) | Yes |
| digest t0 | Go through Open tasks (most urgent first) | section | N | Yes; click not exercised |
| digest t0 | Go through Overdue receivables | section | N | Yes; click not exercised |
| digest t0 | Go through Units with expired insurance | section | A | Yes; click not exercised |

- Totals: 8 chips; 5 N, 3 A, **0 O** (p22: 10 chips; 4 N, 5 A, 1 O). Knowledge turns: 5 chips, 3 N, 2 A, all answerable (p22: 4 chips, 2 N, 1 A, 1 O).
- Digest t2's three chips are gone with its digest. App-nav: the writer was called and proposed nothing.
- Excerpts: 12 of 12 end on a whole sentence. Declined: art. 1095 on t0 (right), art. 1067 and 1052 on t1 (right, off the thread).

---

# 3. Regressions vs p22

1. **Digest t2: the digest lost the turn (-0.50 answer, -0.45 action). Pure QU variance; no code change involved.**
   - QU output differs from p22 in one routing field: `digest_request` true -> false. `search_query` ("tasks needing attention"), `subject_label` ("Tasks Needing Attention"), `entity_types: []` and `export_format: excel` are the same in both runs: the model read the question as about tasks both times and flagged the digest only once.
   - The understanding prompt differs from p22 only in the two assistant summaries of turns 1 and 2 (line 109 and 111 of 120).
   - With the flag off, the turn is an ordinary export: one generated query over Task, 131 rows.
   - The harness did not catch it: this turn has no gate, although its standard names `response_metadata.digest` and `export_followup_offered`.
2. **App-nav: no Team settings button, three unneeded calls (-0.70 action, +8,668 tokens, +3.0 s). Pure QU variance; structural exposure.**
   - Understanding prompt byte-identical to p22; `navigation_target` absent, `entity_types: []`.
   - One missing field removed the identity chip, re-enabled the probes, admitted the law file, and with it passage selection and the follow-up writer.
   - No gate on this turn either.
3. **Rights t2: checked line printed twice (-0.05). Model slip; code ships it.**
   - The prompt quotes the line verbatim so the model will not restate it; this run it copied it (output 35 -> 47 tokens). `ResponseGenerator.php` line 1148 appends `$checkedLine` without testing whether the text already ends with it.
   - Not in the p20-p22 gradings. The label-echo guard does not apply (no button label, no colon list).
4. **Rights t1: weaker deepening answer (-0.10). Composer variance.** Fund point dropped, insurance re-served, vote hedged, invitation closer.
5. **Rights t0: by-laws back to a proviso.** Selector variance: same system prompt, art. 1054 among the candidates, nothing picked. Outweighed on this run by the filing.
6. **Passage selection cost up, yield down**: 3 calls and 11.6k tokens for no addition.

Not regressions, found while tracing:

- Rights t2's query drops the year and adds an "unpaid only" filter (section 2). The precedence defect of p22 did not recur and was not tested.
- Digest t2's file: urgency "0" on 121 rows and raw timestamps with microseconds, where the chat table shows labels and local times. 131 open tasks against the digest's 130.
- Cold-roster "Contact Info" joins phones and e-mails in one cell and repeats the e-mail column in most rows.
- Understanding took 20.1 s on digest t2 (one call, 5,031 input tokens): API latency.
- The digest capitalisation and the stem rule leave no trace field, so neither can be confirmed from a run.

Fixed or improved from p22's list: already-answered chip (#4, as observed), digest lower case (#7, as observed), platitude closer on rights t0 (dropped this run by the existing rule), e-mail column on the French roster (variance).

---

# 4. Remaining defect ranking

Turns below 0.90 on answer: digest t2 (0.40), rights t1 (0.75), rights t0, rights t2 (0.80), app-nav (0.85). Actions below 0.90: app-nav (0.30), digest t2 (0.45), rights t0, resilience t3, cold-roster, french t1 (0.85).

| # | Defect | Turns | Points at stake | Lost at | Variance? | Most structural fix | Determinism |
|---|---|---|---|---|---|---|---|
| 1 | One QU boolean decides whether the digest owns a combined digest + export turn | digest t2 | 0.50 answer, 0.45 action | Query understanding (`digest_request`) | **Pure variance** as a trigger | No word list. Options by structure: when the conversation holds a recorded digest and the turn asks for an export that names no entity and no concept (both empty here), treat it as the digest's export; or ask QU for the flag with its reason and re-ask once when the export names nothing. Needs replays on the stored QU prompts first. | Medium |
| 2 | How-to routing, the probe skip and the page button all hang on `navigation_target` | app-nav | 0.70 action, 8.7k tokens, 3 s | Query understanding, then `file_context` | **Pure variance** as a trigger | (a) Do not admit a file that only a model-written probe reaches when the question's own three angles are all under the floor for it (`votes_below_floor` already records exactly this). (b) Run passage selection and the follow-up writer only when a law-type document is question-supported. (c) Let the host map a help document to its page action, so the button follows the cited document. | High for (a) and (b); (c) needs host config |
| 3 | Checked line printed twice when the model copies it | rights t2 | 0.05 answer | `ResponseGenerator` empty-result branch | Model slip triggers it | Remove a verbatim copy of the line from the model's text before appending it. | Very high |
| 4 | Contingency fund absent from the overview; selection added nothing with art. 1072 among the candidates | rights t0 | 0.10 answer, plus the fund chip | Passage selection | Selector choice | As p22 #1: allow two additions; measure on replays. This run shows the single pick is not stable either (art. 1054 picked in p22, nothing now). | Medium |
| 5 | Deepening answer re-serves covered points and drops a named specific | rights t1 | 0.10 answer | Composition | **Pure variance** | None deterministic. | Low |
| 6 | Platitude or invitation closer survives | rights t1 | 0.05 answer | `AnswerValidator` closing rule | Wording decides | As p22 #5. The invitation half is structural: a last sentence that addresses the user with a question or an offer and carries no citation. | High |
| 7 | "This year" (or any period in the question) absent from the generated query | rights t2 (latent) | 0 here; wrong money for a real owner | Query generation | Variance | Have QU return the period it read as a field; code checks that the query constrains a date property when a period was read, and sends it to the repair loop otherwise. | High |
| 8 | Export of a generated list prints raw enum values and raw timestamps | digest t2 file | 0.05 action | Export composer | No | Run the export cells through the table renderer's value formatting. | Very high |
| 9 | Harness: no gate on digest ownership (digest t2) or on the attached page action (app-nav) | suite | detection only | Suite | No | Add `expect` entries for `response_metadata.digest` and for an attached `team_settings` action. | Very high |
| 10 | Selection call that adds nothing | rights t0, t1, app-nav | 0 points; 11.6k tokens, 4.3 s | Passage selection | No | Cost of the design; 1 useful addition in 5 Part B calls over two runs. | n/a |
| 11 | Precedence check, two-line echo guard, stem rule, sentence case: no live evidence yet | all | 0 | Trace | No | Record a trace field when sentence case or the stem rule changes an outcome; keep the unit tests as the proof until a run exercises them. | Very high |
| 12 | Roster column set and headers follow the generator | french t0, cold-roster | 0-0.05 | Query generation | **Pure variance** (favourable this run) | As p22 #8. | High |
| 13 | Carried, unchanged: "(that's you)" on two cells; "mon équipe" title; `t.id = 3` and no ORDER BY in roster queries; roster introduction kept on resilience t1; record lead restates block values; off-topic fills on rights t0 [4], [5] and rights t1 [5] | several | 0-0.05 each | as p22 #11, #13-#17 | mixed | as p22 | High |

## Pure model variance this run

- QU: `digest_request` on digest t2, `navigation_target` on app-nav, entity selection on rights t2, requested-attribute wording on french t0, clarified intent on cold-roster.
- Probe wording on rights t0 (duplicate probe, hence the merge).
- Selector: nothing picked on rights t0.
- Composer: filing on rights t0 (good), content on rights t1 (worse), the copied checked line on rights t2, the opening of resilience t2.
- Generator: the `[:CUSTOMER]` path and the missing year on rights t2, ContactDirectory on cold-roster, the e-mail column on french t0.

## Reading of the run

- As a regression check on iteration 8: nothing it changed did harm, and almost nothing it changed was reached. The precedence check produced no false positive on 7 queries and no cost.
- The two large losses are routing decisions made by gpt-4o-mini on unchanged instructions. Part B had not seen either flip in p20-p22; both turns have no harness gate.
- Rights t0 at 0.80 is a draw of retrieval and composition, not a fix; rights t1 fell by as much on the same instructions.
- The action mean is now more exposed than the answer mean: one missing field cost 0.70 on one turn.

---

# 5. Tokens and latency vs p22

| Stage | p22 input / output (calls) | p23 input / output (calls) | Delta input |
|---|---|---|---|
| understanding | 68,700 / 2,777 (14) | 68,721 / 2,764 (14) | +21 |
| search_probes | 1,354 / 311 (2) | 2,020 / 436 (3) | +666 (app-nav) |
| passage_selection | 8,021 / 12 (2) | 11,633 / 15 (3) | +3,612 (app-nav +3,737, rights t0 -125) |
| query_generation | 40,093 / 681 (6) | 48,135 / 770 (7) | **+8,042** (digest t2 +7,682, rights t2 +353) |
| composition | 39,179 / 1,668 (11) | 40,713 / 1,438 (10) | +1,534 (app-nav +3,085, rights t0 +766, french t0 +179, digest t2 -2,540) |
| follow_ups (gpt-4o) | 2,258 / 151 (2) | 3,401 / 198 (3) | +1,143 (app-nav +1,039) |
| **Total (in + out)** | **165,205** (37 calls) | **180,244** (40 calls) | **+15,039 (+9.1%)** |

- By turn: app-nav +8,668, digest t2 +5,056, rights t0 +669, rights t2 +336, french t0 +189, rights t1 +75, the other eight +46.
- 13,724 of the 15,039 extra tokens come from the two QU flips.
- First chunk 94,603 / 22 calls (p22 93,506 / 22); second chunk 85,641 / 18 calls (p22 71,699 / 15).

| Stage latency | p22 mean / median | p23 mean / median |
|---|---|---|
| understanding | 6.5 s / 6.1 s (max 12.2) | 7.4 s / 6.1 s (max 20.1, digest t2) |
| file_context (4 knowledge turns) | 6.2, 6.4, 2.7, 2.7 s | 5.9, 9.9, 2.5, 7.0 s |
| of which probe call | 2.3 s, 2.3 s | 2.2 s, 5.5 s, 2.7 s (app-nav) |
| of which passage selection | 1.6 s, 1.6 s | 1.3 s, 1.9 s, 1.1 s (app-nav) |
| query_generation | 2.1 s / 2.0 s (6) | 2.3 s / 2.2 s (7; max 3.4) |
| execution | 0.3 s / 0.2 s (6) | 0.3 s / 0.2 s (7) |
| empty_result_check | 0.04 s | 0.03 s |
| record_enrichment | 0.06 s | 0.02 s |
| recorded_result_export | 0 s (2) | 0 s (2) |
| composition (14 rows) | 2.3 s / 1.5 s | 2.0 s / 1.4 s |
| follow_ups | 1.8 s (2) | 1.7 s (3: 1.9, 1.8, 1.3) |
| digest | 0.6 s (2) | 0.5 s (1) |
| **pipeline** | **11.4 s / 10.2 s** (max 25.5) | **12.9 s / 10.5 s** (max 24.9) |

- Per turn, p22 -> p23 (s): 20.1 -> 18.4, 25.5 -> 24.9, 10.3 -> 10.6, 9.8 -> 9.3, 8.2 -> 9.8, 10.9 -> 14.1, 5.6 -> 5.2, 13.3 -> 16.3, 9.5 -> 9.8, 10.2 -> 9.4, 6.4 -> 6.1, 10.1 -> 10.4, 10.2 -> 13.4, 10.2 -> 22.5.
- The mean turn is 1.4 s slower; the median 0.3 s. Three turns carry it: digest t2 +12.3 s (14.3 s more understanding on one call and 2.1 s of generation, less 3.6 s of composition), app-nav +3.0 s (4.4 s more file context and 1.3 s of follow-ups, offset by a faster understanding call), resilience t2 +3.1 s (understanding).
- Rights t1's file context at 9.9 s is a 5.5 s probe call on a prompt identical to p22's (API latency).
- The precedence check added no measurable time: query generation is 2.1 s on the six turns both runs generated.
