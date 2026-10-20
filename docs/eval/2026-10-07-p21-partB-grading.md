# Grade: p21-1001-B (Part B) vs p20 (and p19)

- Run: `CondoEdge/storage/eval/p21-1001-B.json`, generated 2026-10-07T00:20. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3 "Novo Gestion", owns no unit). 0 void, 0 infra, 14 rows `ok`, 0 gate failures.
- Rubric and strictness as p14 to p20. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1. Follow-up chips are scored in the action score.
- Same ai package sha (badd2f1), dirty tree 592 -> 597 files. Host config sha 998a334f -> 7902f5bf. Models unchanged (QU and composition gpt-4o-mini, query generation and follow-up writer gpt-4o).
- Top-level counters describe one chunk only. Everything below is summed from the 14 rows: 164,358 tokens, 36 LLM calls (p20: 156,576 / 35).
- Headline: answers 0.864 -> **0.879**, actions 0.889 -> **0.893**, but **13 pass / 1 partial / 0 fail** (p20: 14 / 0 / 0). Six answer gains come from code-written lines and one generator swing; the two law overview turns lost 0.05 each, and rights t0 fell under the pass line.

---

# 1. Per-turn grading

| Turn | p19 ans / act | p20 ans / act | p21 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|
| rights t0 | 0.75 / 0.80 | 0.70 / 0.80 | **0.65 / 0.80** | **partial** | Passages: [1] art. 1063-1066, [2] 1067-1069, [3] 1075.1-1079, [4] **1038-1040 (establishment, off topic)**, [5] 1070. **The fund passage (chunk 75, p20's [5]) was not selected this run**; only [1] and [2] are cited. First sentence is an introduction, not the answer, and uncited ("As a co-owner, you have specific rights and duties outlined in the Quebec Civil Code. Here are the main points:"). Of the four elements the standard names: free use (1063) yes; **contribution is filed under Rights as an entitlement ("You are entitled to contribute to common expenses") and is absent from Duties**; its second sentence reverses art. 1064 ("If you have restricted use of common portions, you may only contribute to expenses related to their maintenance and repairs"); contingency fund absent; by-laws only as a proviso inside "Use and Enjoyment" (p20 had it as an explicit duty); access for work (1066) yes. Also present and correct: indemnity (1067), revision within five years (1068), liability insurance (1064.1). Art. 1065 misstated again ("If you sell or lease your portion, you must notify"; the acquirer notifies). No article numbers, no disposal of the fraction, no register. Gone against p20: the art. 1069 misapplication. **Platitude closer shipped** (not dropped by the narrowed rule). Action: one chip, natural and fully answerable; the register passage was offered to the writer and declined (section 2). |
| rights t1 | 0.80 / 0.75 | 0.85 / 0.75 | **0.80 / 0.75** | pass | Sentence 2 gives the rule with its citation ("in proportion to the relative value ... (art. 1064) [1]"), so the answer does start on the question. But the covered rule (art. 1064, t0) is then said three times before anything new: lead, "Proportional Contribution" bullet, half of the "Common Expenses" bullet; the deepening contract in the same prompt allows one sentence. New and correct: budget and notice (art. 1072) [2], contingency fund for major repairs and replacement (art. 1071) [2] (the study detail of p20 is gone), vote lost after three months (art. 1094) [5], still softened to "may lose". Restricted-use sentence correct this time. Gone against p20: the off-thread insurance bullet and the invented "garden or rooftop" example. **Platitude closer shipped.** No legal recovery (not in the corpus). Action: **no chip at all** (`proposed: 0`); p20's chip was on the thread but unanswerable. All four excerpts offered were off the thread; the writer was right to decline them (section 3, item 2). |
| rights t2 | 0.80 / 0.90 | 0.75 / 0.90 | **0.80 / 0.90** | pass | "You have no unit linked to your account, so there are no contributions to show for this year." then **"Attached below this answer: Receivables report."** (the prompt's own label, copied by the model) then the code-written line "Checked: invoices, units (your own records) -- nothing matched your question." `empty_result_check {scope: own, ownership_check {Unit, count 0}}`. What was checked is now stated and true; the model did not restate it; no claim about the team; not a $0 amount. The echoed label holds it at 0.80: it replaces p20's natural pointer sentence and the door is still not explained as the portfolio's. Receivables report chip. |
| resilience t0 | 0.90 / 0.95 | 0.90 / 0.95 | **0.90 / 0.95** | pass | Lead cut (`restatement`), answer starts at "523 members.", Name / Email / Role, "Showing 25 of 523 members -- ask for the full list or an export." Header is "User Name" this run (generator alias `user_name`; p20 "Name"). Chips: Team settings + Download Excel (`mode: rerun`). Residual as p20: "(that's you)" on two cells, `t.id = 3`, no ORDER BY. |
| resilience t1 | 0.90 / 0.95 | 0.90 / 0.95 | **0.95 / 0.95** | pass | Same query plus `u.last_logged_at AS last_login`. Facts line **"523 members. Last Login recorded for 2 of the 25 shown."** (`facts_line: [count, partly_recorded]`): correct, the table shows a value on Julie Rousseau and Service Comptable and blanks on the other 23. Header "Last Login" (p20 "Last Logged At"). Residual: the introduction survives ("Here are the last login details for your team members:", no `lead_trimmed`). |
| resilience t2 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | First sentence now answers "where" ("You can add new members to your team through the **Team Settings** section."), then four grounded steps [1], the contact distinction cited [2], Team settings chip. `search_probes: {skipped: navigation_target}`, no `follow_ups` stage, two calls. Better opening than p20, not a grade step. Roster survives (t3 exports 523). |
| resilience t3 | 0.90 / 0.85 | 0.90 / 0.85 | **0.95 / 0.85** | pass | `recorded_result_export {generation_skipped: true}`, one LLM call, 5,305 tokens. **"I prepared the Excel file with all 523 rows."** (file holds 523 rows, `export_source_complete: true`). User Name / Email / Role / Last Login, role labels kept, title "Team Members Last Login". |
| app-nav t0 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | First sentence answers and is cited ("...through the **Team Settings** section of the platform [1]."), four steps, contact distinction [2], Team settings chip. Three files this run (p20 two; +317 composition tokens). "For contacts, you would refer to a different guide" is vague. No `follow_ups` stage. |
| cold-roster t0 | 0.85 / 0.85 | 0.85 / 0.85 | **0.90 / 0.85** | pass | `RETURN u.name AS name, u.email AS contact_info, u.id AS user_id`; header mapped to "Email". **"I prepared the Excel file with all 523 members."** 523 rows, both columns filled, four harness gates pass. No roles (welcome, not required). |
| french t0 | 0.90 / 0.85 | 0.85 / 0.90 | **0.90 / 0.90** | pass | Entirely French. Lead cut (`restatement`), starts at "523 membres." **Nom / Courriel / Rôle**: the e-mail column is back (`RETURN u.name AS name, u.email AS email, tr.role AS role`; generator variance, same prompt). French role labels, "Affichage de 25 membres sur 523 -- demandez la liste complète ou un export." Chips "Réglages de l'équipe" + "Télécharger Excel". Residual: "(c'est vous)" on two cells; "votre équipe" only lived in the cut lead. |
| french t1 | 0.90 / 0.85 | 0.90 / 0.85 | **0.95 / 0.85** | pass | **"J'ai préparé le fichier Excel avec les 523 lignes."** "Champs inclus : Nom, Courriel, Rôle.", same columns as turn 1, "En date du", "Télécharger Excel", 523 rows. **Generation was NOT skipped** this run: a query-generation call ran (6,544 in / 43 out, 1.5 s), returned an empty query, and the export fell back to the recorded result. Invisible in the answer, visible in cost and time (5.7 -> 10.4 s). Residual: title "Membres de **mon** équipe". |
| digest t0 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Composition prompt byte-identical to p20. Nine sources, `failed_sources: []`, true totals (130, 75, 189, 13, 36, 8), one "Nothing pending in:" line, one closing question. The meetings framing line is better this run ("No meetings are currently scheduled."). 16 chips unchanged. |
| digest t1 | 0.85 / 0.90 | 0.90 / 0.85 | **0.90 / 0.90** | pass | Normal pipeline, no digest block. Record block: Meeting Name, Date 2023-10-24 16:00, **Place: ZOOM**, **President: Julie Rousseau (that's you)**, Union Name: SDC du 1240 St-Sébastien. **One chip only, the Meeting 140 record; "My profile" is gone** and the prompt's attached list no longer names it. Residual: the lead restates name and place; "Your last meeting". |
| digest t2 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Composition prompt byte-identical to p20. `export_followup_offered: excel`, `failed_sources: []`, single closing line. Residual: "EXCEL" in capitals; "Most recent meetings show no scheduled items."; "All 4 approved bills are unpaid". 17 chips as p20. |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p19 | 0.868 | 14 / 0 / 0 | 0.889 |
| p20 | 0.864 | 14 / 0 / 0 | 0.889 |
| **p21** | **0.879** (+0.015) | **13 / 1 / 0** | **0.893** (+0.004) |

- p21 answers, t0 to t13: 0.65, 0.80, 0.80, 0.90, 0.95, 0.90, 0.95, 0.90, 0.90, 0.90, 0.95, 0.90, 0.90, 0.90 (sum 12.30).
- p21 actions, t0 to t13: 0.80, 0.75, 0.90, 0.95, 0.95, 1.00, 0.85, 1.00, 0.85, 0.90, 0.85, 0.90, 0.90, 0.90 (sum 12.50).
- Answer gains (+0.05 each): rights t2, resilience t1, resilience t3, cold-roster, french t0, french t1. Answer losses (-0.05 each): rights t0, rights t1.
- Action gain: digest t1 +0.05. No action loss.
- Five of the six answer gains are this iteration's code-written lines (checked line, "recorded for N of M", three export counts). French t0 is generator variance going the other way from p20.
- The partial is rights t0. p20 warned that it sat on the pass line.

---

# 2. Fix verification

| Fix (iteration 6) | Exercised on | Result | Evidence |
|---|---|---|---|
| #1a Prompt: "START WITH THE DIRECT ANSWER", no closing summary, no invitation | rights t0, t1; resilience t2; app-nav | **Followed on 2 of 4, partly on 1, ignored on 1; the "no closing summary" half was ignored on both law turns** | The line replaces "Start with a direct answer to the question when the data supports one" and is the only instruction change in these four prompts (diffed against p20). Table below. |
| #1b Closing drop narrowed to paragraphs grounded in nothing (< 0.30) | rights t0, t1 | **0 drops; 2 platitudes survived. The two GOOD drops of p20 are no longer made** | No `closing_dropped` on any turn. Replayed with the rule's own test: both p21 closers score 4/9 = 0.44 and both p20 closers 4/10 = 0.40, all above the 0.30 floor. The t0 closer is also protected by "duties", a word of the question that no passage holds. Table below. |
| #2 Identity chip only when the self value is in a column of an identity node | digest t1 | **Verified (action 0.85 -> 0.90)** | `attached_actions`: one `record / view` chip. Prompt line "Attached below this answer" lists the meeting only (p20: "...; My profile"). "(that's you)" beside the president is kept. Roster turns keep their Team settings identity chip. |
| #3 Follow-up slots reserved per tier; never a used article; `excerpts` traced | rights t0, t1 | **Works as designed on t0; on t1 it leaves nothing on the thread** | t0 `excerpts`: art. 1066 (tier 1), 1068 (tier 1), 1069 (tier 2), 1070 (tier 3, `question_supported: true`). Two cited-article paragraphs at most, one reserved slot for the register passage, art. 1065 (used by the answer, offered in p20) no longer offered. t1 `excerpts`: 1073 (2), 1093 (2), 1074 (2), 1070 (3, supported): no tier-1 excerpt and nothing about contributions (section 3, item 2). |
| ... rights t0: fund and register passages | rights t0 | **Register: offered, declined by the writer. Fund: could not be offered, its passage was not retrieved** | E4 is art. 1070 from uncited [5], the reserved slot. It reached the writer cut at about 150 characters ("...a register containing the name and mailing address of each..."); the writer left it empty. No passage of this run holds art. 1071 or 1072. |
| #4 Prompt: "f IS the answer to q" | rights t0, t1 | **No unanswerable chip this run (p20 had 2 of 3)** | The one chip is answered in full by its excerpt. The writer declined the art. 1066 notices excerpt that p20 turned into a chip answerable only by reference. One chip is not a measurement. |
| #5 Related doors for the user's own team record | none | Not exercised in Part B | Roster turns carry the same two chips as p20. |
| #6 Identity values count as known words (roster intro cut) | resilience t0, t1; french t0 | **2 cuts as p20; the t1 introduction still survives** | t0 and french t0: `lead_trimmed [restatement]`. t1: "Here are the last login details for your team members:" is kept. It no longer names the team, but "team" and "details" are in neither the question ("Ok, but could you add their last login to it?") nor the line, so the every-word test of `DataAnswerPlan::boundedLead` does not fire. Table below. |
| #7a Export sentence states the count, no hedge | resilience t3, cold-roster, french t1 | **Verified, 3 of 3, all true** | "with all 523 rows", "with all 523 members", "avec les 523 lignes"; each file holds 523 rows and `export_source_complete: true`. "the available information" is gone. |
| #7b Export follow-ups skip generation | resilience t3, french t1 | **EN yes; FR no** | t3: `recorded_result_export {generation_skipped: true}`, 1 call. french t1: stages `query_generation` (cypher "", confidence 1) and `execution` (0 rows, 0.02 ms), then `export {export_source: previous_result, rows: 523}`. QU this run: `entity_types: []`, `property_concepts: []`, `subject_label: "Liste des membres de l'équipe"` against the recorded "Membres de mon équipe" (section 3, item 3). |
| #8a Code-written "Checked: ..." line | rights t2 | **Verified; true to the query; not restated by the model** | `checked_line: "Checked: invoices, units (your own records) -- nothing matched your question."`, `checked_kinds: [invoices, units]`. Executed query: `(c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) ... MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.budget_id IS NOT NULL AND substring(i.invoiced_at, 0, 4) = '2026'`: invoices of the user's own units, which is what the line says. The model's 31 output tokens name no record kind. |
| #8b "... recorded for N of the M shown" | resilience t1 | **Verified; correct** | "Last Login recorded for 2 of the 25 shown." 2 of the 25 displayed rows carry a value. Over the whole result it is 18 of 523 (counted in the t3 export rows); the line speaks only of the rows shown, which is true and modest. |
| #9 Clarify chips send a self-contained question | none | Not exercised in Part B | No clarify turn. |
| Freed passage slot (p20) | rights t1 | Still works; the off-topic fill is now refused | `passage_overlap {merged: [[74, 77]], filled: [108], single_group: [108], unsupported: [39]}`. Chunk 108 is [5] and is cited for art. 1094. Chunk 39 (art. 1044-1048), filled and never cited in p20, is rejected as `unsupported`. |
| No follow-up call on how-to turns (p20) | resilience t2, app-nav | Still true | No `follow_ups` stage, two calls each. |
| Security | all 14 | **Nothing wrong** | `security_gate.passed: true` 14 / 14; `safe_stop: false` on every execution; `security_filter_applied: true` on the 6 queries that ran, plus the enrichment query. The seventh `execution` entry is french t1's empty query (0 rows, 0.02 ms, nothing executed, filter flag false). No SecurityException text in any trace. |
| Admission | all 14 | **Nothing wrong** | No `admission` stage, no busy answer, both digests `failed_sources: []`. |

## Direct answer first, and every closer

| Turn | First sentence | Direct answer? | Last paragraph | Dropped? | Verdict |
|---|---|---|---|---|---|
| rights t0 | "As a co-owner, you have specific rights and duties outlined in the Quebec Civil Code. Here are the main points:" | **No**: introduction, no citation | "These rights and duties are designed to ensure the proper management and enjoyment of the co-owned property." | No (share 0.44; question word "duties") | **SURVIVED: platitude** |
| rights t1 | "As a co-owner, you have a duty to contribute to common expenses, which is defined in the Quebec Civil Code." then "Specifically, each co-owner contributes ... in proportion to the relative value of their fraction (art. 1064) [1]." | **Partly**: the rule arrives in sentence 2, cited; sentence 1 restates the question | "These regulations ensure that all co-owners share the financial responsibilities associated with the upkeep and management of the property." | No (share 0.44) | **SURVIVED: platitude** |
| resilience t2 | "You can add new members to your team through the **Team Settings** section." | **Yes**; the citation comes after step 4 | "For more details, you can access the Team settings." | No | Survived: pointer to the attached chip, which the prompt asks for. Fine. |
| app-nav t0 | "You can add new members to your team through the **Team Settings** section of the platform [1]." | **Yes**, cited | "You can access the Team Settings directly through the link below." | No | Survived: pointer to the attached chip. Fine. |

- Drops in Part B: none, so no GOOD and no HARMFUL drop to list.
- Survivors that should not ship: 2 platitudes (rights t0, rights t1). Invitations shipped: none.
- Under p20's rule both platitudes would have been dropped. This is the trade the fix log states (a surviving closer is cosmetic, a dropped conclusion costs a verdict); Part B holds only its cost side. No conclusion was at stake on these two turns.

## Every lead (table and record turns)

| Turn | Lead the model wrote | Cut? | What the user sees first | Against p20 |
|---|---|---|---|---|
| resilience t0 | "Here are the members of your team along with their roles:" | Cut, `restatement` | "523 members." | Same |
| french t0 | "Voici la liste des membres de votre équipe avec leurs rôles :" | Cut, `restatement` | "523 membres." | Same |
| resilience t1 | "Here are the last login details for your team members:" | **Kept** | the lead | Same defect, different words (p20: "...for the members of your team, Novo Gestion.") |
| digest t1 (record) | "Your last meeting was the annual general assembly for SDC 1240 SAINT-SÉBASTIEN, which took place on October 24, 2023, via Zoom." | Kept (no rule applies) | the lead | Same |

The model never used the empty lead (15, 14 and 16 output tokens on the three rosters). No `one_sentence` and no `not_recorded` cut occurred.

## Every code-written line that is new in p21

| Turn | Line | True? | Restated or contradicted by the model? |
|---|---|---|---|
| rights t2 | "Checked: invoices, units (your own records) -- nothing matched your question." | Yes (query above; scope `own`) | No |
| resilience t1 | "523 members. Last Login recorded for 2 of the 25 shown." | Yes (2 of 25) | No |
| resilience t3 | "I prepared the Excel file with all 523 rows." | Yes (523 rows in the file) | n/a (no model call) |
| cold-roster | "I prepared the Excel file with all 523 members." | Yes | n/a |
| french t1 | "J'ai préparé le fichier Excel avec les 523 lignes." | Yes | n/a |

Small things: "rows / lignes" on the recorded-result path against "members" on the fresh-query path; "Last Login" capitalised inside the sentence; no "Not recorded" line was triggered.

## Every follow-up chip

| Turn | Chip question | Source (tier) | Label | Answerable from its passage |
|---|---|---|---|---|
| rights t0 | What is the condition for applying for a revision of contributions? | passage [2], art. 1068 paragraph 2 (tier 1) | **N** (pulls the answer's fourth right) | **Yes** (a difference in excess of one-tenth) |
| rights t1 | (none) | | | |
| digest t0 | Go through Open tasks (most urgent first) | section open_tasks (5 of 130) | N (same as the closing sentence) | Yes, rows exist; click not exercised |
| digest t0 | Go through Overdue receivables | section overdue_receivables (5 of 75) | N | Yes; click not exercised |
| digest t0 | Go through Units with expired insurance | section expired_unit_insurance (5 of 189) | A | Yes; click not exercised |
| digest t2 | Go through Open tasks (most urgent first) | section open_tasks | A (the ask was a file) | Yes; click not exercised |
| digest t2 | Go through Overdue receivables | section overdue_receivables | A | Yes; click not exercised |
| digest t2 | Go through Units with expired insurance | section expired_unit_insurance | A | Yes; click not exercised |

Totals: 7 chips; 3 N, 4 A, 0 O (p20: 9 chips; 3 N, 6 A, 0 O). Knowledge turns alone: 1 chip, N, answerable (p20: 3 chips, 1 N and 2 A, one of three answerable).

Excerpts offered and what the writer did with them:

| Turn | Excerpt | Tier | Length reaching the writer | Writer | Right call? |
|---|---|---|---|---|---|
| rights t0 | art. 1066 paragraph 2 (notices to a lessee or occupant) | 1 | full paragraph | declined | Yes: a duty of the syndicate toward tenants; p20's borderline chip |
| rights t0 | art. 1068 paragraph 2 (one-tenth threshold) | 1 | full paragraph | **taken** | Yes |
| rights t0 | art. 1069 (buyer bound to pay arrears) | 2 | **"...is bound..."**, cut before the rule | declined | Yes as shown; the text shown holds no fact |
| rights t0 | art. 1070 (register kept at the disposal of the co-owners) | 3, question-supported | cut at "name and mailing address of each..." | declined | Debatable: "Can I consult the register of the co-ownership?" is a natural next question and the visible words answer it. The passage heading reads "Rights and obligations of the syndicate", which invites the "tangential" exit. |
| rights t1 | art. 1073 (syndicate's insurance) | 2 | about 500 characters | declined | Yes, off the thread |
| rights t1 | art. 1093 (who counts as a developer) | 2 | full | declined | Yes, off topic |
| rights t1 | art. 1074 (insurance condition) | 2 | full | declined | Yes, off the thread |
| rights t1 | art. 1070 (register) | 3, question-supported | about 230 characters | declined | Yes, off the thread |

- The slot reservation does what was asked: at most two tier-1 paragraphs, one slot held for a question-supported uncited passage, no article the answer used.
- The contingency fund could not be offered on t0: no retrieved passage holds it this run.
- Seven of eight excerpts were declined. Six of the seven declines are plainly right, so the low chip count is a selection problem, not a writer problem.

---

# 3. Regressions vs p20 (traced against `p20-1001-B.json`)

## From this iteration's code

1. **Platitude closers ship again (rights t0, rights t1; cosmetic, about 0.02 of each turn's loss).** `AnswerValidator::withoutUncitedClosing` now keeps a last paragraph when 0.30 of its content words are in the passages, the cited blocks or the question (`CLOSING_GROUNDED_SHARE`), or when it holds a question word that no passage holds. A platitude written in the answer's own vocabulary passes: 0.44 on both turns. p20's two GOOD drops score 0.40 under the same test, so they would survive too. The prompt half of the fix ("no closing summary") was ignored by the model on both turns.
2. **No on-thread excerpt on a deepening turn, so no chip (rights t1; action stays 0.75 instead of rising).** Three things combine in `FollowUpWriter::excerpts`:
   - Tier 1 (unused paragraphs of a cited article) is only produced when the word test "sees" the article in the answer (`unusedParagraphs` returns nothing unless `seen()`). p21's answer names art. 1071 and 1072 but paraphrases them briefly, so the test sees neither and tier 1 is empty. Approximate replay of the test (`REFLECTED_SHARE` 0.5, `SEEN_WORDS` 8): the answer carries 13 of the 32 content words of art. 1072 paragraph 1 (0.41) and 8 of 27 of art. 1071 paragraph 1 (0.30); p20's wordier answer carried 16 of 32 of art. 1072 (0.50), was seen, and got two tier-1 excerpts. Art. 1094 is seen in both runs but has a single paragraph; art. 1064 is covered by t0. The unused paragraphs are in the prompt: the contingency fund study, the 0.5% rule before the study exists (art. 1071), the self-insurance paragraph (art. 1072).
   - Sub-articles of a used article are unreachable. Art. 1072.1 (the board consults the meeting before any special contribution: on the thread, fully answerable) and art. 1071.1 are not tier 1 (the answer names "1072", not "1072.1") and not tier 2 (the contract counts whole numbers, and 1071 and 1072 are used).
   - What remains is tier 2 by distance (1073, 1074: insurance; 1093: the definition of a developer) and the reserved register passage. All four are off the thread.
   The first point also needs the model's wording to trigger; the second is always true. The tier-1 and sub-article logic was read in the current source, not compared with p20's source (no git): whether p20 ran the same word test is inferred from its two tier-1 excerpts.
3. **Reserved and tier-2 excerpts arrive cut mid-sentence (rights t0).** The four excerpts share one character budget (`withinBudget`) and the two tier-1 paragraphs take most of it: art. 1069 reaches the writer as "...is bound..." and art. 1070 stops before it says what the register holds. With "f IS the answer to q" in the prompt, an excerpt that stops before its rule cannot produce a chip. The slot is reserved and then spent on text that cannot be used.
4. **Direct-answer line against the deepening contract (rights t1, part of the -0.05).** The same prompt says "your FIRST sentence ... answers the question as asked" and, in the follow-up block, "open with what is new ... at most one sentence restating a covered rule". For "tell me more about my duty to contribute" the direct answer is the covered rule. The model opened with art. 1064, then repeated it in the first two bullets. p20 also spent two bullets on art. 1064, so the instruction made an existing habit slightly worse.

## Model variance (prompts compared)

- **Rights t0 (-0.05, pass to partial).** Instructions identical to p20 except the direct-answer line, which was not followed. Retrieval differs: search query "co-owner rights and duties" (p20 "main rights and duties co-owner") and a different probe 2; chunk 75 (fund) was found by one angle instead of two and lost its place to chunk 32 (art. 1038-1040), so passages are [59, 64, 82, 32, 69] against p20's [59, 82, 64, 69, 75]. gpt-4o-mini then filed the contribution as an entitlement, misread the restricted-use rule and left by-laws as a proviso. The closer (item 1) is the only code share.
- **Rights t2 "Attached below this answer: Receivables report."** The prompt line that carries this label is identical in p20; the model copied it instead of writing a pointer sentence. The new "do NOT restate it" block sits right above it. Net for the turn is still +0.05.
- **French t1 regeneration (no score change; +6,587 tokens, +1.5 s).** `PreviousResultExport::asksNothingNew` needs the turn's subject to be a spelling of the recorded one, or a re-label with at least one named attribute and a primary entity. QU returned a new label, no entity and no property concept, so neither branch holds and generation ran. QU's instructions are identical to p20 (only the previous-answer summary and column names differ). The iteration-6 change covers a re-label that names its attributes, not this one.
- **French t0 (+0.05).** Same generation prompt as p20 apart from QU's rewrite; gpt-4o returned name, e-mail and role this time.
- **Headers**: "User Name" (alias `user_name`) and "Last Login" (alias `last_login`) this run; "Name" and "Last Logged At" in p20.
- **Query-generation input**: resilience t1 6,130 -> 6,856 (QU selected Notification and Contact instead of Activity), digest t1 7,609 -> 8,137 (QU read "our" as collective this run: User and Team schema plus the collective-possessive block). Same queries, same results.
- **Digest t0 and t2**: composition prompts byte-identical to p20; only the framing sentences differ.
- **Latency**: understanding 5.4 -> 6.2 s mean on identical input (68,708 tokens both runs; french t1 took 8.7 s).

## Found while tracing, not scored

- The roster introduction on a follow-up turn survives because the every-word test knows the current question and the facts line, not the turn it follows; "team" comes from the previous question.
- `retrieved_chunks` on rights t0 holds chunk 32, which appears in no angle's top five in `angle_chunks`; the trace does not show how it was chosen.
- The french t1 no-op leaves an `execution` stage with `security_filter_applied: false` in the trace. Nothing ran, but a reader counting that flag will see 6 of 7.

Carried over, unchanged: "(that's you)" on two cells of one row; "EXCEL" in capitals; "mon équipe" in the French export title; `t.id = 3` literal and no ORDER BY in roster queries; art. 1065 misstated on rights t0; "may lose" on art. 1094; the record lead that restates block values; the digest chip surface.

Fixed from p20's list: stray "My profile" chip (#2), all slots taken by cited-article paragraphs (#1, on t0), unanswerable chip (#3, this run), what was checked on the empty answer (#5), blank column unmentioned (#8), export row count (#9), off-topic filled chunk on rights t1.

---

# 4. Remaining defect ranking

Turns below 0.90 on answer: rights t0 (0.65), rights t1 (0.80), rights t2 (0.80). Actions below 0.90: rights t1 (0.75), rights t0 (0.80), resilience t3, cold-roster, french t1 (0.85).

| # | Defect | Turns | Points at stake | Lost at | Variance? | Most structural fix | Determinism |
|---|---|---|---|---|---|---|---|
| 1 | No on-thread follow-up excerpt on a deepening turn: tier 1 depends on the word test, sub-articles of a used article are unreachable | rights t1 (any "tell me more" turn) | 0.10-0.15 action | `FollowUpWriter::excerpts` (tier 1 and `newBlocks`) | Half: the word test needs a brief paraphrase to fail; the sub-article hole is constant | When the answer NAMES an article, offer its paragraphs the answer does not carry without requiring `seen()`. Treat an unnamed sub-article (1072.1) of a used article as a new article for tier 2, nearest first. | Very high |
| 2 | Reserved and tier-2 excerpts cut before their rule by the shared budget | rights t0 | 0.05-0.10 action | `FollowUpWriter::withinBudget` | No | Give every excerpt at least its first full sentence; take the cut from the long tier-1 paragraphs first. | Very high |
| 3 | Overview misfiles and misreads: duty written as entitlement, art. 1064 restricted use reversed, art. 1065, by-laws only as proviso | rights t0 | 0.10-0.15 answer; decides pass or partial | Composition | **Pure variance** | None deterministic. p20x showed a larger composer does not fix it. | Low |
| 4 | Fund passage in or out of the overview's five passages depending on probe wording | rights t0 | 0.10 answer, and the fund chip | Passage selection | Yes | Not a prompt matter. An off-topic division (art. 1038-1040) took the slot; the chapter-level branch coherence already exists, a division-level tie-break toward the question's own division would have kept chunk 75. Needs measurement before it is a fix. | Medium |
| 5 | Prompt label copied into the answer ("Attached below this answer: Receivables report.") | rights t2 | 0.05-0.10 answer | Composition | Model slip on a label that reads like a sentence | Hand the attached actions to the model as a tagged block, not as a sentence it can copy; or strip a sentence that starts with the prompt's own label (the system's string, not a vocabulary). | High |
| 6 | Platitude closer survives the narrowed drop | rights t0, t1 | up to 0.05 answer each | `AnswerValidator::closingReadsAsContent` | No | Use the position the prompt now asks for: when the FIRST block of the answer carries a citation, the verdict is protected, so the old rule (drop the uncited last paragraph) is safe; keep the 0.30 test only when the first block is uncited. On this run it would drop the rights t1 closer and keep the t0 one. | High |
| 7 | Direct-answer instruction conflicts with the deepening contract | rights t1 | 0.05 answer | `GuidelinesSection` beside the follow-up block | Partly | When the deepening block is present, word the first-sentence rule as "the first sentence gives the new rule that bears on the question". | High |
| 8 | Pronoun-only export follow-up regenerates when QU re-labels the subject | french t1 | 0 points; 6.6k tokens and 1.5 s | `PreviousResultExport::asksNothingNew` | QU variance triggers it | A re-label with no entity and no property concept names nothing new: accept it when every content word of the new label is in the recorded subject, its column names or its node labels ("Liste des membres de l'équipe" fails only on "liste"), or treat the generator's empty query as final and trace it as a skip. Needs the "add their last login" counter-case kept in the tests. | High |
| 9 | Roster introduction survives on a follow-up turn | resilience t1 | 0 points now | `DataAnswerPlan::boundedLead` | Constant on follow-ups | On a follow-up turn, count the recorded subject and the previous question as known words. | High |
| 10 | Roster headers follow the generator's alias | resilience t0, t1, t3 | 0-0.05 | Query generation | Yes | As p20 #4: host display labels per property, applied by code. | High |
| 11 | "(that's you)" on two cells of one row | rosters EN and FR | 0-0.05 | Table renderer | No | Mark the name cell only. | Very high |
| 12 | Record lead restates block values | digest t1 | 0-0.05 | Composition | Variance | As p20 #12. | Medium |
| 13 | Digest chip surface | digest t0, t2 | 0-0.05 action | Digest chip builder | No | As p20 #13. | High |
| 14 | Export action surface: one download link, title "mon équipe", "EXCEL" | resilience t3, cold-roster, french t1, digest t2 | 0.05 action each | Export composer | No | Title from the recorded subject in the third person; one second door (the roster page). | High |

## Pure model variance

- Rights t0: which articles the overview uses, duty or right, the reading of art. 1064 and 1065, article numbers, the introduction in place of the answer.
- Rights t1: how often the covered rule is restated, how much of art. 1071 is kept, "may lose".
- Rights t2: the copied label.
- French t0 and cold-roster column sets; header aliases.
- QU on french t1 (label, empty entity list); QU possessive reading on digest t1.
- Probe 2 wording, which decides whether the fund passage is selected.

## Reading of the run

- The code-written lines are the reliable gain: five turns, +0.05 each, all true to their data, none restated.
- The law turns did not respond to the prompt change: one of two answers opens on the answer, both keep a closing summary, and the narrowed drop no longer removes it.
- Rights t0 has now scored 0.75, 0.70 and 0.65 over three runs on near-identical prompts. Its mean is at the pass line; this run is the low draw, not a new failure mode.
- The follow-up surface on law turns is thin (one chip over two turns). Defects #1 and #2 are both deterministic and both in `FollowUpWriter`.

---

# 5. Tokens and latency vs p20

| Stage | p20 input / output (calls) | p21 input / output (calls) | Delta input |
|---|---|---|---|
| understanding | 68,708 / 2,741 (14) | 68,708 / 2,729 (14) | 0 |
| search_probes | 1,354 / 311 (2) | 1,355 / 305 (2) | +1 |
| query_generation | 39,581 / 649 (6) | 47,412 / 674 (7) | **+7,831** |
| composition | 39,295 / 1,649 (11) | 39,282 / 1,448 (11) | -13 |
| follow_ups (gpt-4o) | 2,159 / 129 (2) | 2,369 / 76 (2) | +210 |
| **Total (in + out)** | **156,576** (35 calls) | **164,358** (36 calls) | **+7,782 (+5.0%)** |

- Query generation: french t1 is +6,544 / 43 on its own (the call p20 skipped). Resilience t1 +726 and digest t1 +528 are QU entity selection. Without the french t1 call the run is 157,771 tokens (+0.8%).
- Composition: rights t0 6,154 -> 5,545 (shorter passages), rights t1 7,516 -> 7,388, rights t2 +41 (checked-line instruction), resilience t1 +37 (the longer facts line), app-nav +317 (third file), french t0 +182 (e-mail column), digest t1 +84 (user e-mail line). Output -201: shorter law answers (420 -> 337, 392 -> 328).
- Follow-ups: the system prompt grew by the "f IS the answer" sentences (1,108 -> 1,186 and 1,051 -> 1,183 input); output fell with the chip count (79 -> 49, 50 -> 27).

| Stage latency | p20 mean / median | p21 mean / median |
|---|---|---|
| understanding | 5.4 s / 5.5 s (max 5.8) | 6.2 s / 5.9 s (max 8.7, french t1) |
| file_context (4 knowledge turns) | 5.2, 4.3, 2.3, 2.2 s | 5.0, 4.8, 2.0, 2.5 s |
| of which probe call | 2.8 s, 2.2 s | 2.5 s, 2.4 s |
| query_generation | 1.9 s / 1.8 s (6) | 2.1 s / 1.9 s (7; rights t2 3.4 s) |
| execution | 0.2 s / 0.2 s (6) | 0.2 s / 0.2 s (6 real) |
| empty_result_check | 0.04 s | 0.03 s |
| record_enrichment | 0.02 s (1) | 0.02 s (1) |
| recorded_result_export | 0 s (2) | 0 s (1) |
| composition (14 rows) | 2.4 s / 1.9 s | 2.0 s / 1.6 s |
| follow_ups | 1.7 s (1.9, 1.5) | 1.7 s (1.5, 1.9) |
| digest | 0.5 s | 0.5 s |
| **pipeline** | **10.0 s / 9.0 s** (max 18.4) | **10.7 s / 10.1 s** (max 18.5) |

- The mean turn is 0.8 s slower. Understanding accounts for it (+0.9 s per turn on identical input: API variance); composition is 0.4 s faster (law turns 5.7 and 5.7 s -> 4.1 and 4.4 s with shorter answers).
- French t1 went from 5.7 to 10.4 s: 3.2 s of understanding variance and 1.5 s for the generation call that was not skipped.
- Per turn, p20 -> p21 (s): 18.4 -> 17.0, 17.1 -> 18.5, 10.7 -> 11.1, 8.6 -> 10.4, 8.5 -> 9.3, 10.6 -> 9.9, 5.5 -> 6.1, 9.4 -> 9.9, 7.7 -> 8.8, 8.7 -> 9.3, 5.7 -> 10.4, 8.3 -> 8.9, 9.2 -> 10.3, 11.1 -> 10.5.
- The trace still counts the follow-up call inside the pipeline time.
