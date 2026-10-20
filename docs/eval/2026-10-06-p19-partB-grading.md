# Grade: p19-1001-B (Part B) vs p18 (and p17)

- Run: `CondoEdge/storage/eval/p19-1001-B.json`, generated 2026-10-06T21:38. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3 "Novo Gestion", owns no unit). 0 void, 0 infra, 14 rows `ok`, 0 gate failures.
- Rubric and strictness as p14 / p16 / p17 / p18. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1. Follow-up chips are scored in the action score with the strictness used for page chips.
- Same ai package sha (badd2f1), dirty tree 559 -> 589 files. Host config sha 4aae3535 -> 5eae9d5f. Models unchanged (QU and composition gpt-4o-mini, query generation gpt-4o, embeddings text-embedding-3-small); the new follow-up call runs on gpt-4o.
- Top-level counters describe one chunk only. Everything below is summed from the 14 rows: 157,318 tokens, 37 LLM calls.

---

# 1. Per-turn grading

| Turn | p17 ans / act | p18 ans / act | p19 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|
| rights t0 | 0.70 / 0.70 | 0.65 / 0.70 | **0.75 / 0.80** | pass | Three passages, all Chapter III, no article printed twice: [1] art. 1063-1066, [2] art. 1075.1-1082 (syndicate, never cited), [3] art. 1068.1-1070.2. Free use and disposal (1063), register access (1070), contribution in proportion (1064), insurance (1064.1), **access for work back (1066)**; no mirrored Rights/Duties lists, no unsupported right, "art." notation. Still: **contingency fund absent** (chunk 74 was the first candidate for a freed slot and was refused, see section 3); "If you **sell** or lease your portion, you must notify the syndicate within 15 days, providing details about the new owner" misstates art. 1065 (the acquirer notifies; details are required for a lessee only); generic opener and platitude closer. Action: two follow-up chips, both acceptable, neither the natural next question (see chip table). |
| rights t1 | 0.45 / 0.70 | 0.80 / 0.70 | **0.80 / 0.75** | pass | Builds on new articles: 1072 (funds are part of the contribution; the syndicate notifies amount and date) [2], 1094 (vote lost after three months, now stated firmly) [5], 1054 [4]; plus two bullets from art. 1064 (one restates t0, one adds the restricted-use rule). No overview re-served. Lost against p18: art. 1069 (arrears follow the fraction, with interest). Fund still named, not explained; no "legal recovery" (not in the corpus); closes "…feel free to ask!" against the prompt's explicit ban. Action: one natural chip (contingency fund, art. 1071) and **one off-topic chip that re-asks what t0 already answered** (use of the private portion, art. 1063). |
| rights t2 | 0.80 / 0.90 | 0.80 / 0.90 | **0.80 / 0.90** | pass | "You have no unit linked to your account, so there are no records related to your contribution for this year. I checked for annual contribution amounts in invoices and other related records, but none matched your query. You might want to review the receivables report." `empty_result_check`: `scope: own`, `ownership_check {Unit, count 0}`; `checked_kinds: [unions, invoices]`. "union records, and fund records" is gone; replaced by the vaguer "other related records"; "matched your query" is system wording; the door is still not explained as the portfolio's. Receivables report chip. |
| resilience t0 | 0.80 / 0.90 | 0.85 / 0.90 | **0.90 / 0.95** | pass | Lead "Here are the members of your team along with their roles:" (one sentence, no count), facts line "523 members.", Name / Email / Role, role labels, staff first, "Showing 25 of 523 **members** — ask for the full list or an export." Chips: Team settings + **Download Excel (`mode: rerun`)**. Residual: "(that's you)" on both the name and the email cell; the count is printed twice by code (facts line and showing line); lead ends in a colon and is followed by a fragment; no ORDER BY, `t.id = 3` literal. The re-run click is not exercised by the trace. |
| resilience t1 | 0.85 / 0.90 | 0.85 / 0.90 | **0.90 / 0.95** | pass | Same query plus `u.last_logged_at AS last_login`. Lead "I have included the last login information for each team member.", facts line "523 members.", showing line "members". Chips as t0 (export title "Team Members Last Login"). Last login is blank on most rows and nothing says so (18 of 523 recorded, per the t3 file). |
| resilience t2 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | `search_probes: {skipped: navigation_target}`, team-and-members.md 0.728, one composition call (3,026 in), grounded steps, cited [1], Team settings chip. Follow-up writer declined (1 excerpt, contacts guide): no chip, acceptable. Roster survives (t3 exports 523). |
| resilience t3 | 0.85 / 0.85 | 0.90 / 0.85 | **0.90 / 0.85** | pass | Stage `recorded_result_export {generation_skipped: true}`, no query generation, no execution. File **byte-identical to p18** (markdown sha 1ff57e3ef6, 523 rows, Name / Email / Role / Last Login, role labels kept, title "Team Members Last Login"). 5,303 tokens (p18: 12,920). Residual: no row count in the answer ("the available information"). |
| app-nav t0 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | As resilience t2: no probes, how-to doc first (0.731), cited [1], Team settings chip. Writer declined both excerpts (contacts guide, meetings guide): no chip, acceptable. |
| cold-roster t0 | 0.80 / 0.85 | 0.85 / 0.85 | **0.85 / 0.85** | pass | `RETURN u.name AS member_name, u.email AS contact_email, t.name AS team_name, u.id AS user_id`. 523 rows, every cell filled, both harness gates pass on the delivered file. New: a **"Team Name" column holding "Novo Gestion" on all 523 rows** (generator variance). No roles, no row count in the answer. |
| french t0 | 0.85 / 0.85 | 0.85 / 0.85 | **0.90 / 0.85** | pass | "Voici la liste des membres de votre équipe avec leurs rôles :", "523 membres.", headers **Nom / Courriel / Rôle**, French role labels, "(c'est vous)", "Affichage de 25 membres sur 523 — demandez la liste complète ou un export." Chips: "Réglages de l'équipe" + export chip (`mode: rerun`, `language: fr`) **labelled "Download Excel" in English**. The new door and the English label cancel on the action score. |
| french t1 | 0.85 / 0.85 | 0.85 / 0.85 | **0.90 / 0.85** | pass | `recorded_result_export {generation_skipped: true}`. "Champs inclus : **Nom, Courriel, Rôle**.", file headers Nom / Courriel / Rôle, "En date du", "Télécharger Excel", French role labels; rows identical to p18 (rows sha 526140d0b2, 523). 5,174 tokens (p18: 11,034). Residual: title "Membres de **mon** équipe", no row count. |
| digest t0 | 0.75 / 0.85 | 0.90 / 0.85 | **0.90 / 0.90** | pass | Composition prompt byte-identical to p18. Nine sources, `failed_sources: []`, true totals only (130, 75, 189, 13, 36, 8), one "Nothing pending in:" line, one closing question. Residual as p18: "Most recent meetings show no scheduled items.", bills total twice. Action: 8 section + 5 record chips as before, plus three "Go through <section>" chips; the first is the one-click yes to the closing question. |
| digest t1 | 0.55 / 0.90 | 0.55 / 0.90 | **0.85 / 0.90** | pass | Normal pipeline, no digest block. Stage `record_enrichment {enriched: true, columns: {union_name: Union}, security_filter_applied: true}`. Answer: one lead sentence, then a record block: Meeting Name, Date 2023-10-24 16:00, **Union Name: SDC du 1240 St-Sébastien**. Past tense, Meeting 140 chip. Residual: **place (ZOOM) is recorded and not shown**; the lead repeats the meeting name that the block prints; "Your last meeting". |
| digest t2 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Composition prompt byte-identical to p18. `export_followup_offered: excel`, `failed_sources: []`, single closing line. Residual: "EXCEL" in capitals, "currently open and not yet closed". Download Excel chip is followed by three "Go through" chips on a turn whose ask was the file (17 chips in all); neutral. |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p17 | 0.782 | 12 / 2 / 0 | 0.868 |
| p18 | 0.825 | 12 / 2 / 0 | 0.868 |
| **p19** | **0.868** (+0.043) | **14 / 0 / 0** | **0.889** (+0.021) |

- p19 answers, t0 to t13: 0.75, 0.80, 0.80, 0.90, 0.90, 0.90, 0.90, 0.90, 0.85, 0.90, 0.90, 0.90, 0.85, 0.90 (sum 12.15).
- p19 actions, t0 to t13: 0.80, 0.75, 0.90, 0.95, 0.95, 1.00, 0.85, 1.00, 0.85, 0.85, 0.85, 0.90, 0.90, 0.90 (sum 12.45).
- Answer gains: digest t1 +0.30, rights t0 +0.10, resilience t0 +0.05, resilience t1 +0.05, french t0 +0.05, french t1 +0.05. No answer loss.
- Action gains: rights t0 +0.10, rights t1 +0.05, resilience t0 +0.05, resilience t1 +0.05, digest t0 +0.05. No action loss.
- Both p18 partials are now passes (rights t0 at 0.75, digest t1 at 0.85). Rights t0 remains the weakest turn.

---

# 2. Fix verification

| Fix | Exercised on | Result | Evidence |
|---|---|---|---|
| Follow-up chips, knowledge turns | rights t0, t1; resilience t2; app-nav t0 | **Works; 4 chips, 1 natural, 2 acceptable, 1 off-topic** | Stage `follow_ups`: t0 `{proposed 2, kept 2, passages [3,3]}`, t1 `{proposed 2, kept 2, passages [1,2]}`, both how-to turns `{proposed 0, kept 0}`. The writer declined 7 of 11 excerpts offered (t0: insurer subrogation art. 1075.1, syndicate acquiring fractions art. 1076; t1: seller's certificate art. 1068.1, declaration content art. 1052; both how-to turns: contacts and meetings guides). Every kept question is answerable from its excerpt. The off-topic one is a code gap, not a model slip (section 3). |
| Follow-up chips, digest turns | digest t0, t2 | **Verified** | Three zero-LLM chips on each turn, for the first three truncated sections in registry order (130, 75, 189 rows); English on English questions. Label is the section heading verbatim: "Go through Open tasks (most urgent first)". On t0 it matches the closing sentence "Want me to go through the open tasks one by one?". The click path is not exercised by any suite turn. French not exercised. |
| Single-record enrichment | digest t1 | **Verified (0.55 -> 0.85)** | `record_enrichment`: `MATCH (n:Meeting) WHERE n.id = $ai_record_id OPTIONAL MATCH (n)-[:BELONGS_TO_UNION]->(p0:Union) RETURN p0.name AS p0_0 LIMIT 1`, 22 ms, `security_filter_applied: true`. `data_sample` row carries `union_name`. `record_rendered: true`; the block is code-written. The enricher adds parents' names only: the meeting's own `place` (ZOOM) is still absent. |
| Facts line and one-sentence lead | resilience t0, t1; french t0 | **Verified** | `facts_line: ["count"]` on all three; "523 members." / "523 membres." Leads are one sentence and state no count (composition output 15, 16, 16 tokens). Prompt: "The system itself prints, on a line right above the table, how many members there are: do NOT restate any of it". |
| Showing line names the kind | resilience t0, t1; french t0 | **Verified, EN and FR** | "Showing 25 of 523 members", "Affichage de 25 membres sur 523". p18's "rows" is gone. |
| French headers | french t0, t1 | **Verified on screen, in the file and in "Champs inclus"** | Screen: Nom / Courriel / Rôle. File `export_document_headers: {name: Nom, email: Courriel, role: Rôle}`. Cypher aliases are still English (`name`, `email`, `role`), so the translation is the renderer's. |
| Export follow-ups skip generation | resilience t3, french t1 | **Verified** | Stage `recorded_result_export {generation_skipped: true, format: excel, recorded_rows: 100}` on both; no `query_generation`, no `execution`; one LLM call each. t3 file byte-identical to p18; french t1 rows identical, headers now French; role labels kept in both languages. 12,920 -> 5,303 and 11,034 -> 5,174 tokens. |
| No article printed twice | rights t0, t1 | **Verified for the passage list; one leftover outside it** | t0 `passage_overlap: {merged: [[85,82],[66,69]], unsupported: [74,75,36]}`; 20 distinct articles in the prompt, 0 duplicates (p18: 5 duplicated). t1: no overlap among chunks 59, 74, 66, 47, 108. Leftover: the "Files Referenced in Previous Response" block of the t1 prompt re-prints art. 1064 and 1069 as `<retrieved_document source=previous_turn>` (about 2,000 characters); it predates this run. |
| Rights t0 without indivision | rights t0 | **Verified** | `branch_coherence: dropped [8, 9, 11, 14, 17]`, kept Chapter III, weights 2.935 vs 2.065. All three passage headers are Chapter III. |
| Rights t1 builds on new articles | rights t1 | **Verified** | `deepening: {covered: [1063,1064,1065,1066,1070], new: {2: [1071-1074], 3: [1068,1069], 4: [1052-1056], 5: [1092-1097]}}`. "Covered" is now the articles t0 cited, not passage ranges (p18 listed 1062-1069). The answer cites new art. 1072, 1094, 1054 and covered art. 1064. |
| Rights t2 names what was queried | rights t2 | **Mostly verified** | Kinds now come from the executed RETURN: `checked_kinds: [unions, invoices]`; prompt "record kinds: unions, invoices". "fund records" is gone. "unions" is in the list only because the query returns `un.name` as a grouping column; the model blurred it to "other related records". |
| Export chip on capped rosters | resilience t0, t1; french t0 | **Verified, one label defect** | `export_excel {mode: rerun, language: en/fr, title}` on all three. French turn: chip text "Download Excel". Cause: `ActionAttacher::exportChip` (`src/Services/Actions/Attach/ActionAttacher.php:1139`) passes `$action->label()`, which is the hardcoded string in `ExportExcelAction::label()` (`ExportExcelAction.php:35-38`); the other chips go through `$this->trans(...)` (line 1104) and the export composer uses `ai.export.download_excel` ("Télécharger Excel" exists in `resources/lang/fr.json:246`). |
| Security | all 14 | **Nothing wrong** | `security_gate.passed: true` 14 / 14; `safe_stop: false` on 6 / 6 executions; `security_filter_applied: true` on 6 / 6 plus the enrichment query; no SecurityException text. The trace records the enrichment Cypher before scope injection, and it uses OPTIONAL MATCH: worth one adversarial check outside the eval. |
| Admission | all 14 | **Nothing wrong** | No `admission` stage, no busy answer; both digests `sources_failed: []`. |

## Every follow-up chip

| Turn | Chip question | Source | Label | Answerable from source |
|---|---|---|---|---|
| rights t0 | What must I provide when selling my fraction of the property? | passage [3], art. 1068.1 (trace says 1068) | A | yes (certificate of the syndicate on the condition of the immovable) |
| rights t0 | What financial obligations do I have when acquiring a fraction? | passage [3], art. 1069 | A (borderline: she asks as a co-owner, not a buyer) | yes (all common expenses due, with interest) |
| rights t1 | What rights do I have regarding the use of my private portion? | passage [1], art. 1063 | **O** (off the contribution thread, and t0 already answered it) | yes |
| rights t1 | What is the purpose of the contingency fund established by the syndicate? | passage [2], art. 1071 | **N** (the answer names the fund without explaining it) | yes |
| digest t0 | Go through Open tasks (most urgent first) | section open_tasks (5 of 130) | N (same as the closing sentence) | yes, rows exist; click not exercised |
| digest t0 | Go through Overdue receivables | section overdue_receivables (5 of 75) | N | yes; click not exercised |
| digest t0 | Go through Units with expired insurance | section expired_unit_insurance (5 of 189) | A | yes; click not exercised |
| digest t2 | Go through Open tasks (most urgent first) | section open_tasks | A (the ask was a file) | yes; click not exercised |
| digest t2 | Go through Overdue receivables | section overdue_receivables | A | yes; click not exercised |
| digest t2 | Go through Units with expired insurance | section expired_unit_insurance | A | yes; click not exercised |

Totals: 10 chips; 3 N, 6 A, 1 O. Knowledge turns alone: 1 N, 2 A, 1 O, and two turns with no chip (correct declines).

---

# 3. Regressions vs p18 (traced against `p18-1001-B.json`)

No answer or action score went down. Three new defects come from this iteration's code, and one missed gain.

1. **Off-topic chip on rights t1 — code.** The writer picks "new" excerpts against THIS answer's citations only (`FollowUpWriter::excerpts`, `src/Services/Response/FollowUpWriter.php:339-435`, `DeepeningContract::between` on the current answer). Art. 1063 was not named in the t1 answer, so it was offered as E1, although the same turn's `deepening.covered` lists 1063 and the composition prompt says "passage [1] holds nothing new". gpt-4o then wrote a valid question about it. The writer also sees neither t0's answer nor t0's chips.
   - Second effect of the same selection: `MAX_EXCERPTS = 4` with one excerpt per passage in retrieval order spent a slot on covered art. 1063, so passage [5] (art. 1092-1097) and the second excerpt of [3] (art. 1069, arrears with interest, on topic) were never offered.
2. **"Download Excel" on a French answer (french t0) — code.** New chip path; cause in section 2.
3. **Latency on knowledge turns — code.** The follow-up call adds 1.54, 1.64, 1.14 and 1.32 s inside the pipeline timing; the two how-to turns pay 1.1-1.3 s and about 700 gpt-4o tokens each for zero chips. Pipeline mean 10.30 -> 10.74 s.
4. **Missed gain on rights t0: the contingency-fund passage was refused — code.** Merging freed two slots. The first candidate was chunk 74 (art. 1071-1074). `withoutOverlappingPassages` (`src/Services/AiManager.php:2190-2292`, test at 2265) requires two vote groups for a freed slot; chunk 74 was picked by probe 2 in French (rank 2) and its window by probe 2 in English (chunk 75, rank 5), which count as one group. Both slots stayed empty. Passage [2] (chunks 85 + 82, art. 1075.1-1082) has the same single-group support, was admitted by the budget rule, and was never cited. Not a regression in outcome: p18 did not retrieve chunk 74 either.

Model variance (prompts compared):
- **Cold-roster "Team Name" column.** Generation prompt identical to p18 except the date and one QU-written line; the generator added `t.name AS team_name`.
- **Rights t1 closer "feel free to ask!"** and the loss of art. 1069: the prompt forbids the closer and lists 1068-1069 as NEW in [3].
- **Rights t0 art. 1065 misstatement** (p18 had the same reading: "inform the syndicate of any changes in ownership").
- **Rights t2 "matched your query", "other related records".**
- **Digest t1 lead repeats the meeting name** although the prompt says not to restate the record's values.
- **App-nav third file** (meetings.md 0.403, +252 composition tokens), from a differently worded QU rewrite.
- Digest t0 and t2: composition prompts byte-identical to p18; wording differences are variance.
- Query generation mean 1.94 -> 2.32 s on near-identical prompts: API variance.

Carried over, unchanged: "(that's you)" on two cells of one row; "EXCEL" in capitals; "mon équipe" in the French export title; `t.id = 3` literal and no ORDER BY in roster queries; no row count in export answers; the 37-row previous-result sample in the digest t1 composition prompt (5,128 input for a three-field answer).

Fixed from p18's list: single-record union (#2), export chip on capped lists (#3), generation on export follow-ups (#4), overlapping windows (#5), French headers (#6), checked kinds (#8), English "rows", knowledge turns without a door (#9, partly).

---

# 4. Remaining defect ranking

Turns below 0.90 on answer: rights t0 (0.75), rights t1 (0.80), rights t2 (0.80), cold-roster (0.85), digest t1 (0.85). Actions below 0.90: rights t1 (0.75), rights t0 (0.80), resilience t3, cold-roster, french t0, french t1 (0.85).

| # | Defect | Turns | Points at stake | Lost at | Variance? | Most structural fix | Determinism |
|---|---|---|---|---|---|---|---|
| 1 | Follow-up excerpts ignore what the conversation already covered | rights t1 (and any deepening turn) | 0.10-0.15 action | `FollowUpWriter::excerpts` | No | Pass the turn's `deepening.covered` articles and exclude them from `$newArticles` (`FollowUpWriter.php:399`); add earlier user questions and earlier chips to the `askedForms` given to `verify`. Order a passage's excerpts by the answer's own subject instead of first-in-passage. | Very high |
| 2 | Export chip label not localized | french t0 | 0.05-0.10 action | `ActionAttacher.php:1139` | No | Translate with `ai.export.download_excel` for the answer's locale, as the composer does. | Very high |
| 3 | Single record shows parents but not its own recorded fields | digest t1 | 0.10 answer | `Query/RecordParentEnricher` | No | Let the enrichment query also return the record's own declared card columns (the host's meeting card already has `place`). Then drop from the lead any value the block prints other than the one asked. | High |
| 4 | Own-data empty answer: vague checked kinds, system wording, unexplained door | rights t2 | 0.10 answer | Composition | Wording varies | Code-write the checked line as a facts line ("Checked: invoices of units linked to you"), counting only kinds matched as records, not grouping columns; pass the attached action's description so the door reads as the portfolio's. | High |
| 5 | Overview retrieval: single-group passage kept, single-group candidate refused, slots left empty | rights t0 | 0.10-0.15 answer | `AiManager::withoutOverlappingPassages` | Probe wording varies | Hold budget-admitted extras and freed-slot candidates to one rule. Whether chunk 74 then lands still depends on a probe; p18's idea of searching with the primary passage as an extra angle remains untested. | Medium |
| 6 | Uncited closing paragraph on law answers ("feel free to ask!", "These rights and duties are designed…") | rights t0, t1 | 0.05 each | Composition | Model, but constant across runs | Drop a trailing paragraph with no citation after a cited list, by position, as already done after `{{table}}`. | Very high |
| 7 | Export answers state no row count; constant column kept in a file | resilience t3, cold-roster, french t1 | 0.05 each | Export composer | Column is variance | Code-print the row count; state a column with one distinct value once above the table instead of on every row. | High |
| 8 | Follow-up call runs when it cannot produce a chip | resilience t2, app-nav t0 | 0 points; 1.1-1.3 s and ~700 tokens each | `FollowUpWriter::write` | No | Skip when every excerpt comes from a file the answer did not cite and sits below the relevance floor (0.434, 0.403 here). | High |
| 9 | Digest chip surface: 16-17 chips, a page chip and an ask chip with the same words, heading reused verbatim, "Go through" chips on an export turn | digest t0, t2 | 0-0.05 action | Digest chip builder | No | A short label per source in the registry; no go-through chips when the turn attaches the export. Add a suite turn that clicks one. | High |
| 10 | Law overview misreads who bears a duty (art. 1065) | rights t0 | 0.05-0.10 answer | Composition | Pure variance | None deterministic. | Low |
| 11 | Deepening residue: fund named not explained, art. 1069 dropped, covered rule restated first | rights t1 | 0.05-0.10 answer | Composition | Pure variance | The contingency-fund chip now covers the first item. | Low |
| 12 | Previous-turn document block re-prints articles already in the passages | rights t1 | 0 points; ~500 tokens | Conversation context | No | Omit a previous-turn excerpt whose articles are in this turn's passages. | High |

## Pure model variance

- Cold-roster "Team Name" column and alias wording.
- Rights t0: art. 1065 misstatement; rights t0 and t1 closers; rights t1 choice of articles among the NEW ones.
- Rights t2 "your query" / "other related records".
- Digest t1 lead repeating the name.
- Probe 2 wording, which decides whether the fund passage is a candidate at all.
- Which acceptable follow-up question gpt-4o keeps on rights t0.

## Not fixable without a stronger composition model or a change to the standard

- **Rights t1 "legal recovery":** still no recovery provision in the indexed corpus. The standard should name art. 1094, 1086 and 1069, or the corpus needs the articles.
- **Rights t0 "contingency fund" in an overview:** the fund sits under the syndicate's division; this run shows it reaches candidate status only through one probe. Certain presence means a curated answer.
- **Rights t0 fidelity on art. 1065** (who notifies) and using every relevant rule once: no deterministic guard distinguishes a misreading from a paraphrase.
- **Rights t1 depth on art. 1071:** two runs with the passage labelled NEW and unused. The chip is now the practical answer.

Everything else in the table (#1-#4, #6-#9, #12) is deterministic.

---

# 5. Tokens and latency vs p18

| Stage | p18 input / output (calls) | p19 input / output (calls) | Delta input |
|---|---|---|---|
| understanding | 68,674 / 2,762 (14) | 68,708 / 2,743 (14) | +34 |
| search_probes | 1,353 / 276 (2) | 1,353 / 301 (2) | 0 |
| query_generation | 53,744 / 776 (8) | 40,445 / 674 (6) | **-13,299** |
| composition | 37,790 / 1,704 (11) | 37,718 / 1,623 (11) | -72 |
| follow_ups (new, gpt-4o) | — | 3,587 / 166 (4) | +3,587 |
| **Total (in + out)** | **167,079** (35 calls) | **157,318** (37 calls) | **-9,761 (-5.8%)** |

- Query generation: the two export follow-ups no longer generate (7,509 + 5,824 input gone); the six remaining calls are within 35 tokens of p18.
- Composition: rights t0 5,465 -> 5,049 (-416, no duplicated articles, three passages); rights t1 7,102 -> 6,982; app-nav 2,592 -> 2,844 (third file); digest t1 4,995 -> 5,128 (record format and union); rosters +22 to +40; digests identical.
- Follow-ups per call: rights t0 1,117 / 69, rights t1 1,042 / 73, resilience t2 672 / 9, app-nav 756 / 15.

| Stage latency | p18 mean / median | p19 mean / median |
|---|---|---|
| understanding | 5.8 s / 5.4 s | 5.9 s / 5.9 s (max 6.6 s; no outlier) |
| file_context (4 knowledge turns) | 5.0, 5.4, 2.1, 2.5 s | 5.6, 4.4, 2.2, 2.4 s |
| of which probe call | 2.5 s, 2.7 s | 2.6 s, 2.3 s |
| query_generation | 1.9 s / 1.9 s (8) | 2.3 s / 2.2 s (6) |
| execution | 0.3 s / 0.2 s (8) | 0.6 s / 0.3 s (6; rights t2 1.8 s) |
| empty_result_check | 0.05 s | 0.05 s |
| record_enrichment (new) | — | 0.02 s (1) |
| recorded_result_export (new) | — | 0 s (2) |
| composition (14 rows) | 2.0 s / 1.7 s | 2.0 s / 1.8 s |
| follow_ups (new) | — | 1.4 s / 1.4 s (4; 1.1-1.6 s) |
| digest | 0.7 s | 0.6 s |
| **pipeline** | **10.3 s / 9.4 s** (max 17.2 s) | **10.7 s / 10.2 s** (max 17.6 s) |

- Export follow-ups are faster: resilience t3 7.4 -> 5.5 s, french t1 7.2 -> 6.4 s.
- Knowledge turns are slower by the follow-up call: rights t0 15.8 -> 17.6 s, resilience t2 9.6 -> 11.4 s, app-nav 10.9 -> 11.5 s; rights t1 16.6 s against 17.2 s only because p18 had an 8.2 s understanding call.
- The trace counts the follow-up call inside the pipeline time; it does not show whether the answer is delivered to the user before the call returns.
