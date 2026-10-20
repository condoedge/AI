# Grade: p13-1001-B (Part B) vs p12 / p11 / p1c

- Run: `CondoEdge/storage/eval/p13-1001-B.json`, generated 2026-10-01T15:54. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3, owns no unit). Preflight ok (embedding, gpt-4o-mini, gpt-4o).
- Rubric and strictness are the same as p12. Thresholds: pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1 means right chips, nothing misleading, no invented links.
- Harness: `label_counts` ok 13 / infra 1; `gate_failures` [].

## INFRA turn: cold-roster-excel#0 (VOID in means, graded for information)

- **Exact infra text** (`trace.context_errors` and `trace.fallbacks`):
  `fallback semantic_selector: vector_search -> keyword_matching (semantic index not available: Failed to get collection info for 'context_index': Qdrant returned error 408: )`
- **Cause: Qdrant, not code.** Qdrant returned HTTP 408 (request timeout) on the `context_index` collection-info call. The understanding stage took 31.9 s, so the request hung for about 25 s before the keyword fallback took over. No PHP exception and no `errors[]` were recorded.
- **Was the answer degraded? No.**
  - Keyword selection over-selected entities (Team, Contact, ContactDirectory, ContactUnit, Unit, Union, User). User still came in through the identity tier, and the generator emitted the correct roster query: `MATCH (t:Team) WHERE t.id IN $ai_team_ids MATCH (u:User) WHERE u.current_team_id = t.id RETURN u.name, u.email, u.id`.
  - That query is *better* than p12's, which hard-coded `t.id = 3`.
  - The export holds **all 523 rows**: `export_source_complete: true`, 523 unique (name, email) tuples, and `empty_columns: []`. p12 delivered 250 of 523.
  - The only side effect is latency: 45.1 s for the turn, including a 10.7 s execution for the full re-fetch.
- **Informational grade: answer 0.85, action 0.85.** Remaining gaps: no role column ("roles welcome"), and the column header "Contact Email" is generic.

## Per-turn table

| Turn | p1c | p11 | p12 | **p13 answer** | **p13 action** | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|---|
| rights t0 | 0.50 | VOID | 0.50 | **0.45** | 0.70 | partial | **Undivided-regime contamination is worse than p12.** Refs: [1] chunk 9 (art. 1015-1019, *indivision*) and [2] chunk 58 (1062+). Chunk 64 was retrieved but not cited, and chunk 59 (1063-1066) was not retrieved. Rights 1, 2a, 3 and 4 ("shares presumed equal", "use the undivided property", "fruits accrue to the indivision", "indemnity for improvements") and duty 4 are all indivision articles presented as condo rights. Correct items: contribution, by-laws, insurance (1064.1), 15-day notice (1065). Missing: contingency fund, access for work, any expandable door. No chips and no mention of buttons (F5 ok). File context took 27.6 s and QU 15.0 s (slow Qdrant). |
| rights t1 | 0.50 | 0.55 | 0.55 | **0.45** | 0.70 | partial | Proportional contribution (1064) and the restricted-use split are cited [1]. **Contingency fund omitted** although chunk 74 (art. 1071, contingency fund) was retrieved and cited as [2], and only used for "special contributions" and "notification". Non-payment consequences are still missing (1094 not retrieved). Item 5 "Insurance Requirements" re-serves t0 and is off-thread (repetition padding). It still embellishes "size or value of your unit". |
| rights t2 | 0.50 | 0.40 | 0.40 | **0.40** | 0.50 | partial | "I checked … invoices, union records, and your contact unit information linked to you … couldn't find any records". Honest, and the scope guard holds (no team or $0 claim). **Still** no "you own no unit" statement and no `my_statement` or portfolio door. The query restored a year filter (`substring(i.invoiced_at,0,4)='2026'`), but it is still open-only (`status IN 2/3/4 AND due_amount >= 0.01`, returning `due_amount AS contribution_amount`). For a real owner it would measure the outstanding balance, not the year's contribution. No chip. |
| resilience t0 | 0.75 | 0.70 | 0.75 | **0.75** | 0.90 | pass | TeamRole query, `true_total = anchor_total = 523`, "Showing 25 of 523 rows — ask for the full list or an export." **The "My profile" chip is gone (fix verified)**; Team settings is the only chip. No "(that's you)", which is correct because Julie is not in the first 25 rows. Remaining: raw enum roles (contact/manager), no emails, the "feel free to check the team settings" closer, and the Cypher hard-codes `t.id = 3`. |
| resilience t1 | 0.85 | 0.75 | 0.80 | **0.85** | 0.75 | pass | Correct data op: same Cypher + `u.last_logged_at`, 523, blanks for null logins. **No closer and no My-profile chip** (p12's only chip was My profile, and the answer steered to it). There are no chips at all; nothing misleads, and the truncation line points to export. |
| resilience t2 | 0.90 | 0.90 | 0.90 | **0.90** | 1.00 | pass | Grounded in team-and-members.md [1], members vs contacts, Team settings chip. The roster survives (`last_result_sample_count: 10`). |
| resilience t3 | 0.70 | 0.70 | 0.75 | **0.85** | 0.85 | pass | `previous_result`. **All 523 rows** (`export_source_complete: true`; p12 had 250 of 523), with Member Name, Role and Last Login (505 null logins, honestly blank). Title "Team Members Last Login" is an acceptable subject. Waste: query generation still ran (6.4k tokens, 2.0 s) for an empty Cypher, and QU took 14.7 s. |
| app-nav t0 | 0.90 | 0.90 | 0.90 | **0.90** | 1.00 | pass | Same grounded steps, [1], correct Team settings chip. The turn took **65.0 s** (QU 26.9 s + file_context 34.8 s). No fallback was recorded, so it was slow Qdrant without a timeout. |
| cold-roster t0 | 0.78 | 0.60 | 0.80 | **VOID** (info 0.85) | (info 0.85) | infra | See the INFRA section. Not degraded; better than p12 (full 523, `$ai_team_ids`). |
| french t0 | 0.60 | 0.80 | 0.35 | **0.60** | 0.85 | partial | **Roles are back:** QU `entity_types ["Team","TeamRole"]`, columns Membre and Rôle, "(c'est vous)" on Julie (lead), all French, chip "Réglages de l'équipe" and no "Mon profil". **New defects:** (1) The generator used `OPTIONAL MATCH (tr)-[:BELONGS_TO_USER]->(u:User)`, so **2 blank-name rows** appear in the shown 25 (TeamRoles with no user). (2) It discloses **525**, not 523 (`true_total 525, anchor_total 523`), which contradicts the English turns. The FanOutCollapse guard did not fire, although its doc-comment (AiManager.php:1879) names exactly this case; see Regressions. Also ends with "n'hésitez pas à demander". |
| french t1 | 0.70 | 0.75 | 0.60 | **0.80** | 0.85 | pass | **Title localized:** "Membres de l'équipe et rôles" (subject_label), "En date du", "Télécharger Excel", "Champs inclus : Membre, Rôle". `previous_result` carries the **same columns as t0** (p12 re-ran a different Cypher). It inherits t0's 2 blank-name rows and the 525 count (525 rows, `export_source_complete: true`). |
| digest t0 | 0.50 | 0.60 | 0.70 | **0.85** | 0.85 | pass | **Code-rendered sections verified** (`digest_composition.mode: framing`): headings, tables, inline "Showing 5 of 130/75/189/13/36/8", and "Total Due Amount: $11,493.17". **"$" on every amount.** Labels: Urgent "High", Status "Open/Processing", Flag Color "Sort/Wait/Reply" (matches the messaging.php legend 2/3/4). All money rows match the standard. Meetings: heading "Most recent meetings — none scheduled", "No meetings are currently scheduled.", 3 past meetings with union and place, **no "None pending."**, no 146. **Exactly one** "Nothing pending in: Supplier bills awaiting approval." line. Work orders show Supplier + Job, 5 of 8. Exactly one closing offer. `failed_sources` and `skipped_sources` are []. Minor: (a) the tasks framing says "the oldest one from February 18, 2023", but the source orders urgent-first, so "oldest of 130" is unsupported (the prompt invites "the oldest or most urgent item"); (b) the bills total is stated twice (framing + code line); (c) bills Due At mixes date-only and timed values. |
| digest t1 | 0.55 | 0.30 | 0.30 | **0.55** | 0.90 | partial | **Language fix verified:** the answer is in English ("The last meeting was … October 24, 2023, at 16:00"); the end-of-prompt anchor "Write the answer in the question's language ('en')" took. Normal pipeline, no digest block, correct id 140 chip, past filter, no 146. **Still missing the union:** the Cypher now projects `u.name AS union_name` ("SDC du 1240 St-Sébastien"), but composition dropped it. Place (ZOOM) is not projected. No "our". The standard calls name + date + union required. |
| digest t2 | 0.60 | 0.65 | 0.70 | **0.75** | 0.90 | pass | Same code-rendered sections as t0, `export_followup_offered: excel`, real `export_excel` chip titled **"Items needing your attention"** (p12: "tasks needing attention"). **Two closers:** "The file is attached below. Want me to go through the open tasks one by one?" The standard requires the export acknowledgment to be the single closing offer. This is prompt-induced: DigestResponseComposer.php:517-519 asks for the export sentence "before the closing offer". The framing drifted to shown counts ("There are 5 overdue receivables out of a total of 75", "5 urgent tasks are currently open out of a total of 130"), which is weaker than t0's true-total phrasing. |

## Means and counts

Non-void set = the 13 turns excluding cold-roster (VOID). p12 had no void turns, so p12's comparable set is these same 13.

| Run | Mean, 13 non-void | Mean, 14 (cold-roster info incl.) | Mean, p11's 13 (excl. rights t0) | P / Pa / F (13 non-void) | Action mean, 13 non-void | Action mean, 14 |
|---|---|---|---|---|---|---|
| p1c | 0.658 | 0.666 | 0.679 | — | — | — |
| p12 | 0.631 | 0.643 | 0.654 | 7 / 4 / 2 | 0.769 | 0.775 |
| **p13** | **0.700** (+0.069 vs p12) | **0.711** | **0.731** | **8 / 5 / 0** | **0.827** (+0.058) | **0.829** |

- **p13 is the best run yet** on every comparable set: 0.700 vs p1c's 0.658 on the same 13, and 0.731 vs p1c's best prior 0.679 on p11's 13. It is the first run with zero fails.
- p13 answer scores, t0 to t13: 0.45, 0.45, 0.40, 0.75, 0.85, 0.90, 0.85, 0.90, (0.85 info), 0.60, 0.80, 0.85, 0.55, 0.75.
- p13 action scores, t0 to t13: 0.70, 0.70, 0.50, 0.90, 0.75, 1.00, 0.85, 1.00, (0.85 info), 0.85, 0.85, 0.85, 0.90, 0.90.
- Gains vs p12:
  - french t0: +0.25.
  - digest t1: +0.25.
  - french t1: +0.20.
  - digest t0: +0.15.
  - resilience t3: +0.10.
  - resilience t1 and digest t2: +0.05 each.
- Losses vs p12:
  - rights t0: -0.05.
  - rights t1: -0.10.

## Fix verification

| Fix | Exercised on | Result |
|---|---|---|
| Digest code-renders `{{section:key}}` (heading, table, Showing N of M, money total); model writes framing + one offer | digest t0/t2 | **Verified.** `mode: framing`, `sections_appended: []`, all 6 truncation lines inline. Composition: **237 / 201 output tokens** (p12: 1,278 / 1,284), **3.39 s / 3.38 s** (p12: 13.3 / 14.9 s). Input rose slightly (2,450 / 2,507 vs 2,020 / 2,070) because the rows are still sent as context. That saves about 10-11.5 s and about 1,060 output tokens per digest turn. |
| "$" money in digest tables | digest t0/t2 | **Verified.** All bill and receivable amounts and the total line show "$x,xxx.xx". |
| Coded columns as labels; legend-less small-int code columns dropped | digest t0/t2 | **Verified.** Urgent High; Status Open/Processing (task.php legend 1=Open, 3=Processing); Flag Color Sort/Wait/Reply (2/3/4). **No real count was wrongly dropped.** Every source's projected columns appear: the receivables, bills and work-orders sources project no count columns, and the maintenance sources, which have `nb_visits`, are not among the 9 run. p12's "Customer Type unit" is gone (the source now projects unit and union). |
| One "Nothing pending in: …" line | digest t0/t2 | **Verified** (a single line before the closer, no heading for the empty source). |
| Past meetings without "None pending." | digest t0/t2 | **Verified** ("— none scheduled" heading + "No meetings are (currently) scheduled." + past table with union and place). |
| Localized digest title / export chip title | digest t2, french t1 | **Verified.** Digest export chip "Items needing your attention" (no longer "tasks needing attention"). FR export title "Membres de l'équipe et rôles". This was an English conversation, so FR digest localization was not exercised. |
| Reply-language anchor (digest t1) | digest t1 | **Verified.** The answer is in English, after a French-heavy 37-row previous digest. |
| french t0 roles (QU TeamRole; "These rows answer" says describe only what columns hold) | french t0 | **Verified for roles:** QU `["Team","TeamRole"]` and a Rôle column for every row. The new prompt wording is present ("Describe only what the columns actually hold … never claim the table shows it"); it was not stress-tested because roles were present. **New:** 2 blank-name rows and a total of 525. |
| My profile chip no longer on roster turns | resilience t0/t1, french t0, cold-roster | **Verified.** There is no identity chip besides Team settings / Réglages de l'équipe on any roster turn. |
| FR export title localized (subject_label) | french t1 | **Verified** (title = QU subject_label "Membres de l'équipe et rôles"). |
| Currency splice fix (no "6.12" corruption) | digest t0/t2 | **Verified.** All 11 money tokens per digest match the source rows exactly. No corrupted decimals anywhere in the 14 answers. |
| Security: safe_stop / SecurityException | all 14 | **No false rejections.** The security gate passed on all 14 turns, and every execution has `safe_stop: false` with `errors: []`. `security_filter_applied: true` on all 7 executed Cyphers (the 2 export turns run no Cypher). No SecurityException, block or rejection appears anywhere; the only regex hit was the word "rejected" in the digest standard text. |

## Regressions vs p12 (traced cause)

1. **french t0: 2 blank-name rows and a disclosed total of 525 for 523 members** (net +0.25 vs p12, but this is a new defect).
   - The generator chose `OPTIONAL MATCH (tr:TeamRole)… OPTIONAL MATCH (tr)-[:BELONGS_TO_USER]->(u:User)`. The English turns use `MATCH (u:User) WHERE tr.user_id = u.id`.
   - Execution recorded `true_total 525 > anchor_total 523`. That is the documented FanOutCollapse case: its doc-comment at AiManager.php:1879 cites "an OPTIONAL User over TeamRoles disclosed 525 for 523 members", and FanOutCollapse.php:85 adds `WHERE anchor IS NOT NULL`. Yet no collapse happened.
   - **Most likely cause (from reading the code, not confirmed in the trace):** the French alias `rôle` is non-ASCII. `FanOutCollapse::splitAlias()` (FanOutCollapse.php:881) only accepts aliases matching `` `…` `` or `[A-Za-z_][A-Za-z0-9_]*`. So `tr.role AS rôle` parses as an alias-less expression, the reference check (line 155) fails, and `plan()` returns null. The original 525-row result is kept.
   - The trace records no collapse decision. Add the collapse outcome and reason to the execution stage data to confirm this.
   - Fix direction: accept Unicode identifiers (`[\p{L}_][\p{L}\p{N}_]*` with `/u`) in splitAlias and in the reference parsing. Alternatively, always drop null-anchor rows when anchor_total < true_total.
2. **rights t0 -0.05:** retrieval swapped chunk 59 (1063-1066) for chunk 58 (1062+) and still admits chunk 9 (indivision, 1015-1019) as [1]. Composition built four "rights" on the indivision articles. There is no co-ownership-regime filter, and chunk 9 survives at 0.629.
3. **rights t1 -0.10:** an LLM omission. The contingency fund (art. 1071) was in the cited [2] chunk but not surfaced, and the insurance item re-serves t0. No code path changed here; this is composition variance on gpt-4o-mini.
4. **Digest t2 closing:** the prompt now mandates an export sentence *plus* a deepening offer (DigestResponseComposer.php:517-519, "before the closing offer"). That produces two closers, against the suite's single-offer rule. p12 ended with the export acknowledgment only. Fix: when `exportFormat` is set, make the export sentence *the* closing line.
5. **Latency (infra-contaminated):** mean 22.8 s / median 16.3 s (p12: 14.5 / 13.8 s).
   - QU stage mean 12.2 s vs 6.35 s. The understanding stage includes semantic entity selection against Qdrant.
   - Outliers: app-nav 65.0 s (QU 26.9 s + file_context 34.8 s), rights t0 48.2 s (file_context 27.6 s), cold-roster 45.1 s (408).
   - QU token counts are unchanged, so this is Qdrant slowness in the same window as the 408, not a code regression. It needs a clean re-run to measure.

## New / persistent defects

- **Digest framing prompt** (DigestResponseComposer.php:527): "the oldest or most urgent item" led to the unsupported "oldest one from February 18, 2023" on an urgent-first task list. On t2 the framing used shown counts ("5 … out of a total of 75"). Suggest giving the framing only `true_total` plus the source's declared sort meaning, and dropping "oldest" unless the source sorts by age.
- **The bills money total is said twice** (framing sentence + code total line).
- **digest t1 omits the union** despite projecting it. The place is not projected.
- **rights t2:** still no "you own no unit" / my_statement door, and the query is still open-only `due_amount`.
- **Closers persist** on data turns: "feel free to check the team settings" (resilience t0) and "n'hésitez pas à demander" (french t0).
- **Resilience Cyphers hard-code `t.id = 3`.** They are scope-filtered, but cold-roster and french now use `$ai_team_ids`.
- **Export follow-ups still run query generation** for an empty Cypher: resilience t3 6.4k tokens / 2.0 s, french t1 5.6k / 2.2 s.

## Tokens and latency vs p12

- **Tokens: 168,733 total** (163,585 in / 5,148 out), 33 calls. p12 was 165,733 (158,477 / 7,256), so this is **+3,000 (+1.8%)**.
  - **Output fell 29%** (-2,108), almost all from digest composition (-1,041 / -1,083 per digest turn).
  - Input rose 5,108: cold-roster +1,668 (keyword over-selection gave a bigger schema), french t0 +565, digest t1 +501, rights t2 +688, app-nav +329.
  - Excluding cold-roster: 155,639 vs p12's 154,307 (+0.9%).
  - Digest turns: 8,242 / 8,508 (p12: 8,838 / 9,119).
- **Latency:** mean 22.8 s, median 16.3 s on 14 turns (excluding cold-roster: 21.1 / 16.3). p12 was 14.5 / 13.8.
  - This is infra-contaminated (see Regressions #5).
  - Code-side, the digest turns *improved*: 11.8 s / 12.4 s vs 22.2 s / 21.6 s, with composition 3.4 s vs 13-15 s.
  - QU remains the dominant cost when Qdrant is healthy.
