# Grade: p11-1001-B (Part B) vs p9 / p1c

- Run: `CondoEdge/storage/eval/p11-1001-B.json`, generated 2026-10-01T09:05. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3, owns no unit). Preflight ok. Same rubric and strictness as `docs/eval/2026-10-01-p9-partB-grading.md`.
- Thresholds: pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1 = right chips present, nothing misleading, no invented or wrong links.
- **INFRA / VOID: rights-duties-progressive#0.** The exact error text is:
  - `infra_reason`: "vector store / retrieval failure: file_context: Failed to search in collection 'documentation_chunks': Qdrant returned error 408: "
  - `trace.errors`: `{stage: file_context, class: RuntimeException, message: "Failed to search in collection 'documentation_chunks': Qdrant returned error 408: "}`
  - file_context took 61,293 ms, and the pipeline took 72.1 s.
  - The turn still answered from 3 "physical" chunks. For information only, not graded: its ref [1] is chunk 8, "DIVISION II", art. 1015. That article belongs to the *undivided* co-ownership regime, so the answer is grounded in the wrong regime.
- Harness: `label_counts` ok 13 / infra 1, `gate_failures` []. The harness is still lenient. It labels digest t1 "ok" although the answer is in French to an English question. Its `discloses_total` gate passes "25 of 525" although there are 523 members.

## Per-turn table

| Turn | p1c | p9 | **p11 answer** | **p11 action** | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|
| rights t0 | 0.50 | 0.45 | **VOID** | VOID | infra | Qdrant 408 on `documentation_chunks` (see above). Excluded from both means. |
| rights t1 | 0.50 | 0.40 | **0.55** | 0.60 | partial | **Gains:** no repetition padding. The chunks are 59 (1063-1066), 74 (1071-1072) and 66 (1068.1-1069). Proportional contribution and restricted-use costs are cited to [1]. Board-fixed annual contributions incl. contingency and self-insurance funds, with notice of amount and due date, are cited to [2]. 1069 is now framed correctly as the *acquirer's* liability. **Still missing:** consequences of non-payment (1094 loss of vote, legal recovery), which the standard requires; 1094 was not retrieved. The contingency fund is only mentioned inside the 1072 bullet. "Size or value of your unit" embellishes "relative value". **Action / F5:** the false "attached below" claim is gone. But the model printed a **meta line, "No links or files are attached below this answer."**, as visible text. It still ends with "feel free to ask!" and a generic "Next Steps" block. Cause: the Links section correctly says nothing is attached, but the system prompt still says unconditionally "next steps are attached as buttons", and the guideline says "next steps are attached as buttons below your answer". These contradict each other. |
| rights t2 | 0.50 | 0.40 | **0.40** | 0.50 | partial | The Cypher is `Contact{user_id:13}←ContactUnit→Unit←Invoice`, 2026, `budget_id` not null, returning 0 rows. The open-only `due_amount` filter of p9 is gone, which is better. **F4 verified:** there is no chip at all, so no `new_invoice`. The scope guard holds: no team or $0 claim. **Still:** it never says she **owns no unit**. It says "couldn't find any matching records linked to you". It also claims it checked "invoices, union details, and account information", but WHAT WAS CHECKED only lists the QU entity types (Invoice, Union, Account), not the query path, so the claim overstates what was checked. It ends with "If you'd like, I can assist…", an offer the guideline forbids. No `my_statement` door. The p9 fix was never implemented: pass "no Contact linked to user 13" into the empty-result block. |
| resilience t0 | 0.75 | 0.75 | **0.70** | 0.80 | pass (borderline) | Table of 25 rows, name and role. **Regression: "Showing 25 of 525"**, but there are 523 members. The query is `Team → OPTIONAL TeamRole → OPTIONAL MATCH User`, so 2 TeamRoles with no matching User count as rows. The French turn uses MATCH and gets exactly 523. `execution.anchor_total = 523` was computed but **not used**; the disclosure uses `true_total = 525`. There are no emails, roles are raw enums, and it ends with "just let me know!". Chips: Team settings only; the My profile noise from p9 is gone (chip-grain fix). |
| resilience t1 | 0.85 | 0.80 | **0.75** | 0.55 | pass | It is a correct data operation: same Cypher plus `last_logged_at`. Same 525 error. Nulls are blank cells, and it ends with "just let me know!". **Action regression: zero chips.** p9 had the identity pair. A 525-row list with no export door is a weaker next step. |
| resilience t2 | 0.90 | 0.90 | **0.90** | 1.00 | pass | Grounded in team-and-members.md [1], distinguishes members from contacts, and has the Team settings chip. The roster survives (`last_result_sample_count: 10`). Minor: "the Team Settings **documentation** attached below" mislabels the page chip as documentation. |
| resilience t3 | 0.70 | 0.65 | **0.70** | 0.80 | pass | **F6 verified:** the title is "**Team members last login**", from the roster subject, not "Add new team members". The source is `previous_result`, and columns are Member Name, Role and Last Login. Still "100 of 525": wrong total, and the export is capped at 100 rows (`export_source_complete: false`). Waste: query generation (6.1k tokens) **and** execution (1.9 s) re-ran although the export used the previous result. QU intent is `file_listing`, but **the isFileListingIntent guard held**: file_context was skipped. |
| app-nav t0 | 0.90 | 0.90 | **0.90** | 1.00 | pass | Same grounded steps, [1], and the correct Team settings chip. |
| cold-roster t0 | 0.78 | 0.30 | **0.60** | 0.80 | partial | **The gate now passes.** There is no "Contact Reduced Mobility" or Language column, the full Contact projection is gone, and `empty_columns: []`. **ROW GRAIN failed:** `OPTIONAL MATCH (c:Contact)-[:USER]->(u)` without `head(collect(c))` still fans out, giving `true_total 530` against `anchor_total 523`, disclosed as "**100 of 530**". The standard requires the TRUE total, so this is an honesty defect. The ROW GRAIN rule *is* in the query prompt (traced), but gpt-4o ignored it, and nothing deterministic enforces anchor_total. Also: redundant columns (User Email = Contact Email, Contact First/Last = User Name), mismatched pairs (user "Fatima-Zahra Bouhadou" / contact "Julie Dandurand"), staff rows blank in 4 of 6 columns, and no role. The title "Current team members contact information" is fine. Julie's own row (row 1) has no "(that's you)" marker in the export. |
| french t0 | 0.60 | 0.80 | **0.80** | 0.65 | pass | All French. MATCH-based query, so exactly **523**. "Affichage de 25 lignes sur 523" is a FR door. Roles are raw English enums. Chips: "Mon profil" is gone, and the Team settings chip **is still in English** on a French turn. |
| french t1 | 0.70 | 0.75 | **0.75** | 0.80 | pass | French copy, "100 lignes sur 523", "Télécharger Excel", same members and columns. The title is still "**Team — Membres équipe rôles**": English entity prefix plus keyword salad. QU intent is `file_listing`; **the guard held** (no file lookup this time, while p9 returned 50 DB files). Query generation still ran (1.9 s, 5.5k tokens) and produced an empty Cypher. |
| digest t0 | 0.50 | 0.65 | **0.60** | 0.85 | partial | Money is correct: 4 approved-unpaid bills, overdue 5 of 75 with the right oldest rows. The meetings section shows the 3 past meetings, and 146 is absent. **Fixed:** the empty source now sits under its own heading ("Nothing pending in: Supplier bills awaiting approval"). The meeting record chip is now labelled by name. **Regressions:** (1) the per-section "showing N of M" lines are back to **trailing after all sections** (`digest_truncation_appended` × 5); only the new work-orders section kept its line inline. (2) **New source "Work orders awaiting supplier acceptance"** (5 of 8) projects only sent_at, price and status. It has no supplier or job name, the **Price column is empty in every row** (the F2 empty-column rule is not applied in digest rendering), status is a raw "3", and two rows look duplicated. Still: no union on meetings, "None pending." above a past-meetings table, no totals ($11,493.17 / $16,959.86), no closing deepening offer, raw codes (Urgent 1, Status 1/3, Flag 2/3/4), and pending reviews named "Contact information". Actions: 8 section chips plus 5 record chips (ids verified); `edit` is used on bills and invoices, and there is no task chip. |
| digest t1 | 0.55 | 0.15 | **0.30** | 0.90 | **FAIL** | **F3 verified at the content level.** The Cypher is past-filtered and **projects `m.name`**. The prompt carries "Date: 2023-10-24 16:00 — PAST (before today) — describe it in the past tense". The answer uses past tense, name and date, with no 146 and no digest block, so the negative control passes. **But the answer is in FRENCH to an English question:** "La dernière réunion a eu lieu le 24 octobre 2023 à 16h00, et il s'agissait de l'Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN." QU had `language: "en"`, and the system prompt says "never switch to another language". The composition prompt (gpt-4o-mini) is dominated by French data: the previous 37 digest rows as JSON plus a French FACTS name. This data-branch prompt has **no explicit closing language pin**; only the empty-result branch has "(the question's language: 'en')". No validator checks language. Also still **no union and no ZOOM place** (not projected), and no insider voice ("La dernière réunion" rather than "our"). On content alone it would score about 0.70. Shown to an English user in French, it fails. The chip "View Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN" (id 140) is correct and now named. |
| digest t2 | 0.60 | 0.60 | **0.65** | 0.75 | partial | The digest owns the turn: `export_followup_offered: excel`, and no failed or skipped sources. **Fixed:** the truncation lines are **inline** under each heading, and "Showing all 4." appears for bills. The real `export_excel` chip and "The file is attached below." are correct; the suite contract is outdated and is not penalized. Still: the chip title is "tasks needing attention", which undersells "everything". It has the same work-orders noise section, the same empty Price column, no meeting union, "None pending.", and raw codes. |

## Means and counts

| Run | Mean, 13 non-void turns (excl. rights t0) | Mean, all 14 (as published) | Pass / Partial / Fail (13) | Action mean (13) |
|---|---|---|---|---|
| p1c | 0.679 | 0.666 | 7 / 6 / 0 | — |
| p9 | 0.619 | 0.607 | 6 / 5 / 2 | 0.704 (0.70 on 14) |
| **p11** | **0.662** (+0.043 vs p9, -0.017 vs p1c) | — (t0 void) | **7 / 5 / 1** (+1 void) | **0.769** (+0.065) |

- p11 answer scores (t1 to t13): 0.55, 0.40, 0.70, 0.75, 0.90, 0.70, 0.90, 0.60, 0.80, 0.75, 0.60, 0.30, 0.65.
- p11 action scores: 0.60, 0.50, 0.80, 0.55, 1.00, 0.80, 1.00, 0.80, 0.65, 0.80, 0.85, 0.90, 0.75.
- Biggest gains: cold-roster +0.30, rights t1 +0.15, digest t1 +0.15 (content fixed, language broke), digest t2 +0.05, resilience t3 +0.05.
- Action gains: rights t2 0.00 → 0.50 (no misleading chip), cold-roster 0.70 → 0.80, resilience t0 0.75 → 0.80.

## Fix verification

| Fix | Exercised on | Result |
|---|---|---|
| F2a: all-empty columns dropped | cold-roster | **Partial.** The roster has no Reduced Mobility or Language columns, and the gate passes. **Not applied to digest tables:** the work-orders "Price" column is empty in all 5 rows on t0 and t2. |
| F2b: ROW GRAIN (523, not 530) | cold-roster, roster turns | **FAIL.** The rule is present in the query prompt (verified), but the generator still emits a fan-out OPTIONAL MATCH, so the disclosure reads 530. The English roster turns show 525 (orphan TeamRoles via OPTIONAL MATCH User). `anchor_total = 523` is computed on every turn but never used for disclosure. A prompt-only fix is not enough. Recommend a deterministic fallback: when `anchor_total < true_total`, dedupe by anchor id or disclose anchor_total. |
| F2c: no full Contact projection | cold-roster | **Verified.** Only names, email, company and id are projected. Redundant user/contact columns remain. |
| F3: past tense plus meeting name | digest t1 | **Verified** (PAST tag in the prompt, name projected, "a eu lieu"). Union and place are still missing. A **new language defect masks the gain.** |
| F4: no "New invoice" chip on a possessive empty result | rights t2 | **Verified** (no chips). There is still no "owns no unit" statement and no `my_statement` door. |
| F5: no-chip turns do not claim "attached below" | rights t1, t2 | **Partial.** There is no false claim. But t1 leaks the meta sentence "No links or files are attached below this answer." The system prompt and guidelines still say unconditionally that "next steps are attached as buttons", which contradicts the new Links block. |
| F6: export title from the roster subject | resilience t3 | **Verified** ("Team members last login"). |
| Chip-grain rework | all | **Mostly good.** The My profile noise is gone, and digest record chips carry real names. The roster follow-up (resilience t1) now gets zero chips, and the FR turn still shows the English "Team settings". |
| "(that's you)" marker | roster tables, export | **Not exercised in chat** (Julie is not in the first 25 rows). It is absent from the cold-roster Excel (Julie is row 1). The instruction is present in the prompt. |
| isFileListingIntent guard | resilience t3, french t1 (QU intent `file_listing`) | **Verified.** file_context was skipped on both; p9 ran a 50-file lookup on french t1. |
| Identity-aware HOMONYMS | — | Not exercised by this suite. |

## Regressions vs p9 (with traced cause)

1. **digest t1: wrong response language (new defect, severe).**
   - English question; QU `language: "en"`; answer entirely in French.
   - Cause: gpt-4o-mini composition over a prompt dominated by French data, namely the previous-digest JSON sample (`last_result_sample_count: 37`) and the French FACTS name.
   - The data branch lacks the explicit closing "(the question's language: 'en')" pin, which only the empty-result branch has (line 1275 of the trace JSON).
   - AnswerValidator has no language check. The harness also labels the turn "ok".
   - Fix: append the explicit language pin last in *every* composition branch. Add a cheap deterministic validator that compares the answer's language with `qu.language` and retries once.
2. **Roster totals 523 → 525 (resilience t0, t1, t3).**
   - The query generator switched to `OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id`, so 2 orphan TeamRoles become rows.
   - `anchor_total = 523` is ignored by the disclosure.
   - This is the same root cause as cold-roster's 530.
3. **digest t0: trailing truncation lines are back**, the p1c and p9-t2 defect.
   - The model omitted the inline lines, and `digest_truncation_appended` re-added all 5 *after* the last section.
   - On t2 the lines were inline, so it is LLM variance.
   - Fix: have the deterministic renderer insert each "showing N of M" line *under its own heading* instead of appending at the end.
4. **digest t0/t2: new "work orders awaiting supplier acceptance" source is noise.** It has no identifying column (supplier or job), an all-empty Price column, raw status 3, and two rows that look duplicated. The source projection needs a label (supplier name or work title) and the empty-column drop.
5. **resilience t1 action 0.70 → 0.55.** The chip-grain rework removed both identity chips and added nothing. A row-capped list (100 of 52x) should get the export chip.
6. **rights t1: "No links or files are attached below this answer."** leaks into visible text. The F5 instruction contradicts the unconditional "attached as buttons" wording in the system prompt and guidelines.

## Tokens and latency vs p9

- **Tokens:** 164,366 total (157,304 in / 7,062 out), 33 calls. That compares with p9's 156,346 / 32, so **+8,020 (+5.1%)**. Without the void t0 it is 154,799.
  - Heaviest turns: digest t1 at 19,874 (query generation 9.4k, carrying the previous digest rows) and rights t2 at 15,901.
  - Avoidable cost: the export-from-previous turns still run query generation (t3 6.1k tokens and 2.8 s plus a 1.9 s re-execution; french t1 5.5k tokens and 1.9 s for an empty Cypher).
- **Latency: mean 27.0 s on 14 turns (23.5 s on 13 non-void), median 19.4 s.** p9 was 11.7 s mean and 10.3 s median, so this is about 2.3× slower.
  - QU "understanding" averages 11.8 s (p9 was a flat 3-6 s), peaking at 31.6 s on digest t1 and 30.0 s on digest t2. The token counts are unchanged (about 5.5k in), which points to provider-side latency.
  - The Qdrant 408 on rights t0 (61.3 s) is the same degraded window.
  - digest t0: digest stage 29.7 s and composition 15.6 s, for a 56.4 s turn. It now runs 9 sources (+ work orders).
  - **Treat this run's latency as infra-contaminated, not a code regression.** Re-measure on a clean run before drawing conclusions. The only code-side latency items are the redundant query generation and execution on the export follow-ups.
