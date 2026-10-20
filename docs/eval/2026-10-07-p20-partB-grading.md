# Grade: p20-1001-B (Part B) vs p19 (and p18)

- Run: `CondoEdge/storage/eval/p20-1001-B.json`, generated 2026-10-06T23:03. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3 "Novo Gestion", owns no unit). 0 void, 0 infra, 14 rows `ok`, 0 gate failures.
- Rubric and strictness as p14 / p16 / p17 / p18 / p19. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1. Follow-up chips are scored in the action score with the strictness used for page chips.
- Same ai package sha (badd2f1), dirty tree 589 -> 592 files. Host config sha 5eae9d5f -> 998a334f. Models unchanged (QU and composition gpt-4o-mini, query generation and follow-up writer gpt-4o, embeddings text-embedding-3-small).
- Top-level counters describe one chunk only. Everything below is summed from the 14 rows: 156,576 tokens, 35 LLM calls.

---

# 1. Per-turn grading

| Turn | p18 ans / act | p19 ans / act | p20 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|
| rights t0 | 0.65 / 0.70 | 0.75 / 0.80 | **0.70 / 0.80** | pass (borderline) | Five passages, all Chapter III, no article twice: [1] art. 1063-1066, [2] 1075.1-1079, [3] 1067-1069, [4] 1070-1070.2, [5] 1071.1-1074.2. Only [1] and [3] are cited. Present: free use (1063), by-laws as an explicit duty, insurance (1064.1), access for work (1066); new and correct: indemnity for work (1067), revision within five years (1068). **Contingency fund still absent although art. 1072 is now in passage [5]** (never cited). Lost against p19: disposal of the fraction, register access (1070, now in uncited [4]), and every "art." number. The Rights list holds two duties: "**You are entitled to a proportional share of the common expenses**" and the 15-day notice; contribution appears in both lists (p18's mirrored-lists defect is back). Art. 1065 misstated again ("If you sell or lease your unit, you must notify"); art. 1069 (the buyer's liability for arrears) presented as every co-owner's duty "including any interest due at the time of acquisition". Platitude closer removed by code. Action: two chips, one natural, one acceptable; the fund and the register could not be offered (section 3). |
| rights t1 | 0.80 / 0.70 | 0.80 / 0.75 | **0.85 / 0.75** | pass | **The contingency fund is now explained** (art. 1071: major repairs and replacement, sums set from a study) [2], annual contribution and notice (1072) [2], vote lost after three months (1094) [4], proportion and restricted-use portions (1064) [1]. No "feel free to ask!" (closer dropped by code). Still: opens with the covered rule (two bullets from art. 1064 before anything new); a last bullet on insurance is off the thread and attributes the co-owner's liability insurance (art. 1064.1, covered in t0) to art. 1073 [2]; "like a private garden or rooftop" is an example no passage gives; "you may lose" softens art. 1094; no legal recovery (not in the corpus). Action: one chip, on the thread, **not answerable from its passage** (chip table). The p19 off-topic chip is gone. |
| rights t2 | 0.80 / 0.90 | 0.80 / 0.90 | **0.75 / 0.90** | pass | "You have no unit linked to your account, so there are no records related to your contribution for this year. This is why nothing matched your question regarding the contribution amount. You can check the Receivables report for more information." `empty_result_check {scope: own, ownership_check {Unit, count 0}}`, `checked_kinds: [invoices, unions]`. The sentence saying what was checked ("in invoices…") is gone; the second sentence is system wording and adds nothing; the door is still not explained as the portfolio's. No claim about the team. Receivables report chip. |
| resilience t0 | 0.85 / 0.90 | 0.90 / 0.95 | **0.90 / 0.95** | pass | Answer now starts at "523 members." (lead cut, rule `restatement`), then Name / Email / Role, staff first, "Showing 25 of 523 members — ask for the full list or an export." Chips: Team settings + Download Excel (`mode: rerun`). Cleaner than p19, not a grade step. Residual: "(that's you)" on both the name and the email cell; the count printed twice by code; no ORDER BY, `t.id = 3` literal. |
| resilience t1 | 0.85 / 0.90 | 0.90 / 0.95 | **0.90 / 0.95** | pass | Same query plus `u.last_logged_at AS last_logged_at`. Lead kept: "Here are the last login times for the members of your team, Novo Gestion." Facts line "523 members." Header **"Last Logged At"** (p19: "Last Login"; generator alias). Last login is blank on 23 of the 25 rows shown (18 of 523 recorded) and nothing says so. Chips as t0. |
| resilience t2 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | `search_probes: {skipped: navigation_target}`, team-and-members.md 0.728, grounded steps, cited [1], Team settings chip. **No `follow_ups` stage**, two LLM calls (p19: three). Roster survives (t3 exports 523). |
| resilience t3 | 0.90 / 0.85 | 0.90 / 0.85 | **0.90 / 0.85** | pass | `recorded_result_export {generation_skipped: true}`; 523 rows, Name / Email / Role / Last Logged At, role labels kept, title "Team members last login". 5,308 tokens. Residual: no row count ("the available information"); header wording inherited from t1. |
| app-nav t0 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | No probes, how-to doc first (0.704), two files (p19: three), cited [1], Team settings chip. No `follow_ups` stage. |
| cold-roster t0 | 0.85 / 0.85 | 0.85 / 0.85 | **0.85 / 0.85** | pass | `RETURN u.name AS name, u.email AS email, u.id AS user_id`. 523 rows, both columns filled, four harness gates pass. The constant "Team Name" column of p19 is gone. No roles, no row count in the answer. |
| french t0 | 0.85 / 0.85 | 0.90 / 0.85 | **0.85 / 0.90** | pass | Entirely French. Starts at "523 membres." (lead cut, `restatement`). Table is **Membre / Rôle only** (`RETURN u.name AS membre, tr.role AS rôle`): the e-mail column p19 showed is gone, which is the bare-minimum column set the roster standard marks as weak. French role labels, "(c'est vous)" once, "Affichage de 25 membres sur 523 — demandez la liste complète ou un export." Chips: "Réglages de l'équipe" + **"Télécharger Excel"** (`mode: rerun`, `language: fr`). |
| french t1 | 0.85 / 0.85 | 0.90 / 0.85 | **0.90 / 0.85** | pass | `recorded_result_export {generation_skipped: true}`. "Champs inclus : Membre, Rôle.", file headers Membre / Rôle (the same columns as turn 1, as the standard asks), "En date du", "Télécharger Excel", 523 rows. Residual: title "Membres de **mon** équipe", no row count. The missing e-mails are charged on t0. |
| digest t0 | 0.90 / 0.85 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Composition prompt byte-identical to p19. Nine sources, `failed_sources: []`, true totals (130, 75, 189, 13, 36, 8), one "Nothing pending in:" line, one closing question. Residual as p19: "Most recent meetings show no scheduled items.", bills total twice. 16 chips, the three "Go through <section>" chips unchanged. |
| digest t1 | 0.55 / 0.90 | 0.85 / 0.90 | **0.90 / 0.85** | pass | Normal pipeline, no digest block. `record_enrichment {columns: {union_name: Union}, details: [place, president], security_filter_applied: true}`. Record block: Name, Start Date 2023-10-24 16:00, **Place: ZOOM**, **President: Julie Rousseau (that's you)**, Union Name: SDC du 1240 St-Sébastien. Past tense, name + date + union + place as the standard asks. Residual: the lead repeats name and place that the block prints; "Your last meeting". Action: Meeting 140 chip plus a new **"My profile" chip** that has nothing to do with the question (section 3). |
| digest t2 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Composition prompt byte-identical to p19. `export_followup_offered: excel`, `failed_sources: []`, single closing line. Residual: "EXCEL" in capitals. 17 chips as p19. |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p18 | 0.825 | 12 / 2 / 0 | 0.868 |
| p19 | 0.868 | 14 / 0 / 0 | 0.889 |
| **p20** | **0.864** (-0.004) | **14 / 0 / 0** | **0.889** (0) |

- p20 answers, t0 to t13: 0.70, 0.85, 0.75, 0.90, 0.90, 0.90, 0.90, 0.90, 0.85, 0.85, 0.90, 0.90, 0.90, 0.90 (sum 12.10).
- p20 actions, t0 to t13: 0.80, 0.75, 0.90, 0.95, 0.95, 1.00, 0.85, 1.00, 0.85, 0.90, 0.85, 0.90, 0.85, 0.90 (sum 12.45).
- Answer gains: rights t1 +0.05, digest t1 +0.05. Answer losses: rights t0 -0.05, rights t2 -0.05, french t0 -0.05.
- Action gain: french t0 +0.05. Action loss: digest t1 -0.05.
- Both answer gains come from this iteration's code. All three answer losses are model variance on prompts whose instructions did not change (section 3). The one action loss is code.
- Rights t0 sits on the pass line (0.70): one more slip makes it a partial.

---

# 2. Fix verification

| Fix | Exercised on | Result | Evidence |
|---|---|---|---|
| Lead beside the facts line: may be empty, model is shown the line, code cuts | resilience t0, t1; french t0 | **Works; 2 cuts, both right, nothing needed was removed** | Prompt now reads `The system prints: "523 members."` and "That sentence is OPTIONAL — the lead may be EMPTY … write ONLY {{table}}". The model never used the empty option (15, 20 and 16 output tokens); code cut two of the three leads. Table below. |
| ... "Not recorded: …" rule | none | **Not exercised** | `facts_line: ["count"]` on all three rosters; no turn printed a "Not recorded" line. Resilience t1 is the case that would need one (last login blank on 505 of 523 rows) and the line does not say it. |
| Uncited closing paragraph dropped on law answers | rights t0, t1 | **Works; 2 drops, both GOOD** | `closing_dropped` on both turns; neither answer lost a conclusion or a caveat. Table below. |
| Follow-up excerpts exclude what the conversation covered | rights t1 | **Verified** | `deepening.covered: [1063-1069]`. Excerpts offered: art. 1072, 1071, 1070, 1074. None from a covered article (p19 offered 1063 and 1068.1). No chip on a t0 article. |
| Follow-up excerpts prefer unused paragraphs of cited articles | rights t0, t1 | **Works as designed, with a side effect** | t0 excerpts: art. 1066 paragraph 2, 1068 paragraph 2, 1069 paragraph 2, 1065, all from cited [1] and [3]. t1: 1072 paragraph 2 and the 1071 study paragraph first. Side effect on t0: the four slots were all taken by this tier, so uncited passages [4] (register) and [5] (contribution and funds) were never offered (section 3, item 2). |
| No follow-up call on how-to turns | resilience t2, app-nav t0 | **Verified** | No `follow_ups` stage and no `follow_ups` prompt on either; 2 calls each (p19: 3). Saves 681 and 771 gpt-4o tokens and 1.1 to 1.3 s. |
| Digest "Go through <section>" chips | digest t0, t2 | **Unchanged** | Same three chips, same labels, same order, on both turns; t0 still matches the closing question. |
| Freed-slot rule | rights t1 (not t0) | **Works; it saved the loss-of-vote rule** | t1 `passage_overlap: {merged: [[59, 63, 66]], filled: [108, 39], single_group: [108, 39]}`. Chunk 108 became [4] and is cited for art. 1094; it had one vote (search query, rank 5) and would have been refused under p19's rule. Chunk 39 became [5] (art. 1044-1048, what counts as common portions) and is never cited. No off-topic material is cited. |
| ... on rights t0 | rights t0 | **Not exercised; fund text retrieved by ordinary selection, not used** | No `passage_overlap` key: nothing merged this run, so no slot was freed. Chunk 75 came in as an ordinary pick and is passage [5]. It prints art. 1071.1 to 1074.2: **art. 1072 is there** (contributions "include … the amounts to be paid into the contingency fund"), **art. 1071 itself is not**, although the passage header says "art. 1071, 1072, 1073, 1074". The answer never cites [5] and never mentions a fund. |
| ... token cost | rights t0, t1 | +1,105 and +534 composition input | t0 5,049 -> 6,154 (five passages instead of three; the two extra are uncited). t1 6,982 -> 7,516 (merged passage plus chunk 39). |
| Meeting detail fields | digest t1 | **Verified (0.85 -> 0.90)** | Enrichment Cypher `… RETURN p0.name AS p0_0, n.place AS place, n.president AS president LIMIT 1`, 23 ms. Block prints Place: ZOOM and President. Side effect: the president is the user, which attaches "My profile" (section 3, item 1). |
| French export chip label | french t0 | **Verified** | Chip text "Télécharger Excel", `language: fr`, title "Membres de mon équipe". |
| Security | all 14 | **Nothing wrong** | `security_gate.passed: true` 14 / 14; `safe_stop: false` on 6 / 6 executions; `security_filter_applied: true` on 6 / 6 plus the enrichment query; no SecurityException text anywhere in the traces. The enrichment query now also returns two properties of the record itself; it is still recorded before scope injection and still uses OPTIONAL MATCH, so the adversarial check suggested in p19 stands. |
| Admission | all 14 | **Nothing wrong** | No `admission` stage, no busy answer; both digests `failed_sources: []`. |

## Every lead (table and record turns)

| Turn | Lead the model wrote | Cut? | What the user sees first | Better or worse than p19 | Judgement |
|---|---|---|---|---|---|
| resilience t0 | "Here are the members of your team along with their roles:" | Cut, `restatement` | "523 members." | Better | Pure introduction; the count is on the facts line and the roles are in the table. Nothing needed was lost. |
| french t0 | "Voici la liste des membres de votre équipe avec leurs rôles :" | Cut, `restatement` | "523 membres." | Better | Same. The only cost is that "votre équipe" was the one insider phrase of the answer. |
| resilience t1 | "Here are the last login times for the members of your team, Novo Gestion." | Kept | the lead | Same | One sentence, true, no count, no contradiction. It is still an introduction, which the prompt forbids; it says nothing about the blank column. |
| digest t1 (record, no facts line) | "Your last meeting was the annual general assembly for SDC 1240 SAINT-SÉBASTIEN, which took place on October 24, 2023, via ZOOM." | Kept (no rule applies) | the lead | Slightly better | Answers "when" in the first sentence. Not filler, hedge or contradiction; it repeats the name and place that the block prints, against "do NOT restate their values". |

No `one_sentence` cut and no `not_recorded` cut occurred in Part B.

## Every closing drop

| Turn | Dropped text | Verdict | Why |
|---|---|---|---|
| rights t0 | "These rights and duties are designed to ensure a harmonious living environment and the proper management of the condominium." | **GOOD** | Platitude. The answer is a structured list and loses no conclusion or caveat. |
| rights t1 | "Understanding these obligations will help you fulfill your responsibilities as a co-owner and ensure the smooth operation of your condominium community." | **GOOD** | Platitude. p19 ended this turn with the banned "feel free to ask!"; that position is now empty. |

## Every follow-up chip

| Turn | Chip question | Source | Label | Answerable from its passage |
|---|---|---|---|---|
| rights t0 | What notices must the syndicate give to a lessee of my unit? | passage [1], art. 1066 paragraph 2 | A (borderline: a duty of the syndicate toward a tenant, reached from "sell or lease") | Only by reference: "the notices prescribed by articles 1922 and 1931 regarding improvements and work"; neither article is in the corpus |
| rights t0 | What is the threshold for applying for a revision of expenses? | passage [3], art. 1068 paragraph 2 | **N** (pulls the thread of the answer's fifth right) | Yes (a difference of more than one-tenth) |
| rights t1 | What is the minimum contribution required for the self-insurance fund? | passage [2], art. 1072 paragraph 2 | A (on the contribution thread) | **No**: the paragraph only says the Government sets the terms by regulation; it gives no amount |
| digest t0 | Go through Open tasks (most urgent first) | section open_tasks (5 of 130) | N (same as the closing sentence) | Yes, rows exist; click not exercised |
| digest t0 | Go through Overdue receivables | section overdue_receivables (5 of 75) | N | Yes; click not exercised |
| digest t0 | Go through Units with expired insurance | section expired_unit_insurance (5 of 189) | A | Yes; click not exercised |
| digest t2 | Go through Open tasks (most urgent first) | section open_tasks | A (the ask was a file) | Yes; click not exercised |
| digest t2 | Go through Overdue receivables | section overdue_receivables | A | Yes; click not exercised |
| digest t2 | Go through Units with expired insurance | section expired_unit_insurance | A | Yes; click not exercised |

Totals: 9 chips; 3 N, 6 A, 0 O (p19: 10 chips; 3 N, 6 A, 1 O). Knowledge turns alone: 1 N, 2 A, 0 O; one of the three is fully answerable from its passage. The writer declined 5 of 8 excerpts: t0 art. 1069 paragraph 2 and art. 1065; t1 the art. 1071 study paragraph (the answer says it), art. 1070 and art. 1074. All five declines are correct. The writer's system prompt is identical to p19; only the excerpt selection changed.

---

# 3. Regressions vs p19 (traced against `p19-1001-B.json`)

## From this iteration's code

1. **"My profile" chip on the meeting record (digest t1, action -0.05).** The new detail field `president` holds "Julie Rousseau". `ActionAttacher.php:743-748` attaches `my_profile` when a single-row result contains any value that is the user's own (`$ownRow && count($rows) === 1`), whatever the row's entity. The same value triggers the SELF-REFERENCE block in the composition prompt (+7 lines). "(that's you)" beside the president is correct; the chip is not.
2. **Follow-up tiering crowds out the natural chip (rights t0, no score change, missed gain).** `FollowUpWriter::excerpts` (`src/Services/Response/FollowUpWriter.php:554-570`) deals tier 1 (unused paragraphs of cited articles), then tier 2, then tier 3 (uncited passages) and keeps the first four. On t0 tier 1 alone gave four (two each from [1] and [3]), so [5] (art. 1072, contributions and the contingency fund) and [4] (art. 1070, the register) could not be offered. Those are the two threads the standard names and the answer lacks. One of the four slots went to art. 1065, which the answer had already used: the word-overlap test read "acquires a fraction" as unused beside the answer's "sell or lease".
3. **A chip its passage cannot answer (rights t1, held the action at 0.75).** Tier 1 offered art. 1072 paragraph 2, a sentence that only delegates to regulation. gpt-4o wrote "What is the minimum contribution…?" although its prompt requires that the excerpt answer the question in full. Code chose the excerpt; the model made the slip.

## Model variance (prompts compared)

- **Rights t0 (-0.05).** The composition instructions are identical to p19 apart from the passage numbers. The passages differ because QU and probe wording differ ("managing common expenses" in probe 2 this run): five passages with no merge instead of three. gpt-4o-mini then took its extra items from [3] (art. 1067-1069), ignored [4] and [5], misfiled two duties as rights and dropped the article numbers. No code of this iteration is involved: the freed-slot rule did not run on this turn.
- **Rights t2 (-0.05).** Prompt identical except the QU-written subject ("contribution amount for this year" for "annual contribution amount"). The model left out the checked-kinds sentence.
- **French t0 (-0.05).** Generation prompt identical except QU wording ("membre", "rôle" singular; the rewrite). gpt-4o returned two columns with French aliases and no e-mail.
- **"Last Logged At" header** (resilience t1, t3): generator alias `last_logged_at` for p19's `last_login`.
- **Cold roster**: Name and Email only; the constant "Team Name" column is gone, roles are still absent. Prompt identical except the date.
- **Rights t1** insurance bullet and "garden or rooftop" example: composition. Chunk 39 (filled, art. 1044) lists "parks and gardens" among common portions and may have fed the example; it is not cited.
- **Digest t1** QU: `is_possessive: false` this run (p19: collective); the generated query is still team-scoped and returns the same record. 536 fewer generation tokens.
- **Digest t0 and t2**: composition prompts byte-identical to p19.
- **Latency**: understanding 5.9 -> 5.4 s on identical input (68,708 tokens both runs); digest t2 composition 3.3 -> 5.4 s on an identical prompt. API variance.

## Found while tracing, not scored

- **Passage headers fold sub-articles into whole numbers.** Passage [5] of rights t0 is headed "art. 1071, 1072, 1073, 1074" and starts at art. 1071.1; art. 1071 is not printed. The same folding labels the art. 1068.1 reference as 1068 in `follow_ups`. On t1 it happens to be harmless (chunk 74 does print art. 1071).
- **Without article numbers in the answer, coverage is inferred from words.** Rights t0 named no article, so `deepening.covered` on t1 became every article of the cited passages (1063-1069), including art. 1069 which t0 misapplied.

Carried over, unchanged: "(that's you)" on two cells of one row; "EXCEL" in capitals; "mon équipe" in the French export title; `t.id = 3` literal and no ORDER BY in roster queries; no row count in export answers; the previous-turn document block that re-prints art. 1064 on rights t1; the previous-result sample in the digest t1 composition prompt (5,172 input for a five-field record).

Fixed from p19's list: off-topic chip on rights t1 (#1), French export label (#2), meeting place (#3), freed-slot refusal (#5, on t1), uncited closer (#6), follow-up call on how-to turns (#8).

---

# 4. Remaining defect ranking

Turns below 0.90 on answer: rights t0 (0.70), rights t2 (0.75), rights t1, cold-roster, french t0 (0.85). Actions below 0.90: rights t1 (0.75), rights t0 (0.80), resilience t3, cold-roster, french t1, digest t1 (0.85).

| # | Defect | Turns | Points at stake | Lost at | Variance? | Most structural fix | Determinism |
|---|---|---|---|---|---|---|---|
| 1 | Follow-up tiers: cited-article paragraphs take every slot; uncited on-topic passages are never offered | rights t0 (any overview turn) | 0.10 action, and the only remaining route to the fund on t0 | `FollowUpWriter::excerpts` 554-570 | No | Reserve slots per tier (at most two from tier 1, at least one from a question-supported uncited passage) instead of filling in tier order. | Very high |
| 2 | Identity chip attached because a detail field names the user | digest t1 (any single record with the user as president, author, assignee) | 0.05 action | `ActionAttacher.php:743-748` | No | Apply the own-row test only when the row's primary entity is the user, or only to the query's own columns, not to enrichment details. | Very high |
| 3 | Chip whose excerpt cannot answer it | rights t1 | 0.05-0.10 action | follow-up writer on a code-chosen excerpt | Model slip, repeatable on delegating paragraphs | Have the writer return the answer words copied from the excerpt and drop a chip that has none; no word list needed. | Medium-high |
| 4 | Roster column set and header wording depend on the generator | french t0 (-0.05), cold-roster, resilience t1 / t3 | 0.05 each, three turns | Query generation | Yes, every run differs | Host-declared default list columns for a member roster (name, e-mail, role) and display labels per property (`last_logged_at` -> "Last login"), applied by code. | High |
| 5 | Own-data empty answer: what was checked is left to the model | rights t2 | 0.05-0.10 answer | Composition | Wording varies | Code-write the checked line as a facts line (p19 #4, still open) and pass the attached action's description so the door reads as the portfolio's. | High |
| 6 | Overview does not use the fund passage even when it is retrieved | rights t0 | 0.10-0.15 answer | Composition | Yes | None deterministic. This run moves the loss from retrieval to composition: with art. 1072 in the prompt at 65% relevance the model still left it out. | Low |
| 7 | Rights and duties misfiled, a rule applied to the wrong person (art. 1065, 1069), article numbers dropped | rights t0 | 0.05-0.10 answer | Composition | Pure variance | None deterministic. | Low |
| 8 | A mostly blank column is shown without a word | resilience t1 | 0.05 answer | Facts line (`DataAnswerPlan`) | No | Let the facts line state how many rows have the asked-for column recorded ("Last login recorded for 18 of 523"). This is the "Not recorded" path that Part B never reached. | High |
| 9 | Export answers state no row count | resilience t3, cold-roster, french t1 | 0.05 each | Export composer | No | Code-print the row count (p19 #7, still open). | High |
| 10 | Passage header promises an article the text does not print (sub-articles folded) | rights t0 [5] | 0 now; feeds the deepening contract and chip labels | Article locator / header | No | List sub-articles as themselves in the header and in the contract. | High |
| 11 | Deepening answer opens with the covered rule and adds an off-thread bullet | rights t1 | 0.05 answer | Composition | Pure variance | None deterministic. | Low |
| 12 | Record lead restates values the block prints | digest t1 | 0-0.05 answer | Composition | Variance | Keep only the lead's answer to the asked field; low priority, the lead does answer "when". | Medium |
| 13 | Digest chip surface (16-17 chips, heading reused verbatim, go-through chips on an export turn) | digest t0, t2 | 0-0.05 action | Digest chip builder | No | As p19 #9. | High |
| 14 | Previous-turn document block re-prints an article already in the passages | rights t1 | 0 points; about 500 tokens | Conversation context | No | As p19 #12. | High |
| 15 | The model writes an introduction although the lead may be empty | resilience t0, french t0 | 0 points (code cuts it); 15-16 output tokens | Composition | Constant | None needed. | n/a |

## Pure model variance

- Rights t0: which articles the overview picks, the misfiled lists, art. 1065 and 1069, no article numbers.
- Rights t1: the insurance bullet, the invented example, "may lose".
- Rights t2: whether the checked kinds are named.
- French t0 and cold-roster column sets; the "Last Logged At" alias.
- Probe 2 wording, which decides whether the fund passage is retrieved (it was this run, by ordinary selection).
- Resilience t1 lead; digest t1 lead.

## Not fixable without a stronger composition model or a change to the standard

- **Rights t0 "contingency fund" in the overview.** Two runs ago the passage was not retrieved; this run art. 1072 is in the prompt and unused. Retrieval is no longer the blocker. Certain presence needs a stronger composition model or a curated overview; the cheap substitute is defect #1 (offer it as a chip).
- **Rights t0 fidelity**: who notifies under art. 1065, who owes under art. 1069, duty filed as right. No deterministic guard separates a misreading from a paraphrase.
- **Rights t1 "legal recovery"**: still not in the indexed corpus. The standard should name art. 1094, 1086 and 1069, or the corpus needs the articles.
- **Rights t1 opening with the covered rule**: the prompt already says "open with what is new".

Everything else in the table (#1-#5, #8-#10, #13, #14) is deterministic.

---

# 5. Tokens and latency vs p19

| Stage | p19 input / output (calls) | p20 input / output (calls) | Delta input |
|---|---|---|---|
| understanding | 68,708 / 2,743 (14) | 68,708 / 2,741 (14) | 0 |
| search_probes | 1,353 / 301 (2) | 1,354 / 311 (2) | +1 |
| query_generation | 40,445 / 674 (6) | 39,581 / 649 (6) | -864 |
| composition | 37,718 / 1,623 (11) | 39,295 / 1,649 (11) | **+1,577** |
| follow_ups (gpt-4o) | 3,587 / 166 (4) | 2,159 / 129 (2) | **-1,428** |
| **Total (in + out)** | **157,318** (37 calls) | **156,576** (35 calls) | **-742 (-0.5%)** |

- Composition: rights t0 5,049 -> 6,154 (+1,105, five passages); rights t1 6,982 -> 7,516 (+534, merged passage and one filled chunk); resilience t0 and t1 +105 and +106 (the facts line quoted, the empty-lead rule); french t0 2,653 -> 2,576 (two columns); app-nav 2,844 -> 2,592 (two files); digest t1 5,128 -> 5,172; digests identical.
- Query generation: rights t2 8,984 -> 8,659 and digest t1 8,145 -> 7,609 (QU selected fewer entities); the four roster calls are within 5 tokens.
- Follow-ups per call: rights t0 1,108 / 79, rights t1 1,051 / 50. The two how-to calls (672 / 9 and 756 / 15) are gone.

| Stage latency | p19 mean / median | p20 mean / median |
|---|---|---|
| understanding | 5.9 s / 5.9 s | 5.4 s / 5.5 s (max 5.8 s) |
| file_context (4 knowledge turns) | 5.6, 4.4, 2.2, 2.4 s | 5.2, 4.3, 2.3, 2.2 s |
| of which probe call | 2.6 s, 2.3 s | 2.8 s, 2.2 s |
| query_generation | 2.3 s / 2.2 s (6) | 1.9 s / 1.8 s (6) |
| execution | 0.6 s / 0.3 s (6) | 0.2 s / 0.2 s (6) |
| empty_result_check | 0.05 s | 0.04 s |
| record_enrichment | 0.02 s (1) | 0.02 s (1) |
| recorded_result_export | 0 s (2) | 0 s (2) |
| composition (14 rows) | 2.0 s / 1.8 s | 2.4 s / 1.9 s |
| follow_ups | 1.4 s / 1.4 s (4) | 1.7 s / 1.7 s (2; 1.9 and 1.5 s) |
| digest | 0.6 s | 0.5 s |
| **pipeline** | **10.7 s / 10.2 s** (max 17.6 s) | **10.0 s / 9.0 s** (max 18.4 s) |

- How-to turns are faster without the follow-up call: resilience t2 11.4 -> 10.6 s, app-nav 11.5 -> 9.4 s.
- Law turns are slower: rights t0 17.6 -> 18.4 s, rights t1 16.6 -> 17.1 s. Composition took 5.7 s on each (p19: 4.3 s) with the larger prompts and 12 to 40 more output tokens.
- Most of the mean gain is the understanding call (0.5 s on identical input) and query generation (0.4 s); both are API variance, not code.
- The trace still counts the follow-up call inside the pipeline time and does not show whether the answer reaches the user before it returns.
