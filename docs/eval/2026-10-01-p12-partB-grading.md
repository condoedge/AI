# Grade: p12-1001-B (Part B) vs p11 / p9 / p1c

- Run: `CondoEdge/storage/eval/p12-1001-B.json`, generated 2026-10-01T12:39. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3, owns no unit). Preflight ok.
- **No INFRA/VOID turns.** Harness: `label_counts` ok 14, `gate_failures` [].
- Rubric and strictness are the same as p11 and p9. Thresholds: pass >= 0.70, partial 0.40-0.69, fail < 0.40.
- Action 0-1 means: right chips, nothing misleading, no invented links.
- The harness is still lenient:
  - It labels digest t1 "ok" although the answer is French to an English question (again).
  - It labels french t0 "ok" although the roles column is missing.

## Per-turn table

| Turn | p1c | p9 | p11 | **p12 answer** | **p12 action** | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|---|
| rights t0 | 0.50 | 0.45 | VOID | **0.50** | 0.70 | partial | **Clean this run:** Qdrant ok, file_context 8.9 s. Refs are chunk 9 (art. 1015-1020), chunk 64 (1067-1069) and chunk 59 (1063-1066). It covers contribution in proportion to relative value, by-laws, not obstructing work, insurance (1064.1) and 15-day notice (1065). **Wrong regime:** Rights 1-2 ("share presumed equal", "use the undivided property") cite [1] = art. 1015/1016, which is *undivided* co-ownership, not condominium. The contingency fund is missing. There is no explicit expandable door. No chips, and no mention of buttons or attachments (F5 ok). |
| rights t1 | 0.50 | 0.40 | 0.55 | **0.55** | 0.70 | partial | Same chunks as p11 (59, 74, 66+). Proportional contribution, restricted-use costs and board-fixed contributions incl. contingency and self-insurance funds are cited [1]/[2], with no repetition. **Still missing** non-payment consequences (art. 1094 was not retrieved). It still embellishes "size or value of your unit" and ends with a padded "Summary" paragraph. **F5 fixed:** the p11 meta line "No links or files are attached below this answer." is gone, as is "feel free to ask". |
| rights t2 | 0.50 | 0.40 | 0.40 | **0.40** | 0.50 | partial | Answer: "I checked … invoices and union records linked to you … couldn't find any matching records for this year." It is honest and the scope guard holds (no team or $0 claim). **Still** no "you own no unit" statement and no `my_statement` door, because the empty-result prompt's WHAT WAS CHECKED still lacks "no Contact linked to user 13". **Latent query regression:** the Cypher is back to an open-only filter (`status IN ['2','3','4'] AND due_amount >= 0.01`, summing `total_amount`) with **no year filter**. That measures outstanding balance, not this year's contribution. "For this year" is unsupported by the query, and would give a wrong figure for a user who does own a unit. |
| resilience t0 | 0.75 | 0.75 | 0.70 | **0.75** | 0.75 | pass | **523 fixed:** the query is MATCH-only, `true_total = anchor_total = 523`, "Showing 25 of 523 rows — ask for the full list or an export." "Julie Rousseau (that's you)" marker present. Roles are raw enums, there are no emails, and it ends with "just let me know!" (the guideline forbids that). **Chip regression:** "My profile" noise is back next to Team settings. |
| resilience t1 | 0.85 | 0.80 | 0.75 | **0.80** | 0.50 | pass | Correct data op: same Cypher + `last_logged_at`, 523, blanks for null logins. **Action:** the only chip is "My profile", which is irrelevant; there is no export or Team settings chip. The answer's closer steers to it: "feel free to check your profile." |
| resilience t2 | 0.90 | 0.90 | 0.90 | **0.90** | 1.00 | pass | Grounded in team-and-members.md [1], members vs contacts, Team settings chip. The roster survives (`last_result_sample_count: 10`). The p11 "documentation attached below" mislabel is gone. |
| resilience t3 | 0.70 | 0.65 | 0.70 | **0.75** | 0.80 | pass | `previous_result`, title "Team members last login". Columns are Member Name, Role and Last Login, with **250 of 523** (correct total, 250 unique rows; p11 was 100 of 525). Still not the full list (`export_source_complete: false`). Waste: query generation ran again (6.2k tokens, 1.7 s) for an empty Cypher. |
| app-nav t0 | 0.90 | 0.90 | 0.90 | **0.90** | 1.00 | pass | Same grounded steps, [1], correct Team settings chip. |
| cold-roster t0 | 0.78 | 0.30 | 0.60 | **0.80** | 0.85 | pass | **Fix verified:** `MATCH (u:User) WHERE u.current_team_id = t.id RETURN u.name, u.email` gives one row per member. It has 250 unique rows, `true_total = anchor_total = 523`, "250 of 523 rows", and `empty_columns: []`. The redundant and mismatched Contact columns are gone, and contact info is User.email. Remaining: no role column ("roles welcome"), no "(that's you)" on Julie's row, and the Cypher hard-codes `t.id = 3` rather than `$ai_team_ids` (still scope-filtered: `security_filter_applied: true`). |
| french t0 | 0.60 | 0.80 | 0.80 | **0.35** | 0.75 | **FAIL** | **Roles missing:** the table has a single "Membre" column, while the text says "avec leurs rôles". That is a false completeness claim on the core ask; the standard requires "names AND roles for every member shown". **Cause:** QU `entity_types: ["Team"]` (p11 was `["Team","TeamRole"]`), so entity selection was Team and User only and the generator used the `current_team_id` path with no TeamRole. The new "These rows answer: 'membres équipe rôles' … Present them as that answer" subject line pushed composition to present the role-less rows as the answer. Its "say plainly which value is not recorded" clause was ignored. Otherwise all French, 523, "Affichage de 25 lignes sur 523", "(c'est vous)", but it ends with "n'hésitez pas à demander". **Chip fix verified:** "Réglages de l'équipe" is in French; "Mon profil" noise is back. |
| french t1 | 0.70 | 0.75 | 0.75 | **0.60** | 0.80 | partial | French copy ("J'ai préparé…", "250 lignes sur 523", "Télécharger Excel", "En date du", "Affiche 250 lignes sur 523"). **Title regression:** it is "**Team members roles**", fully English (p11 was "Team — Membres équipe rôles"). The export re-ran a new Cypher (`current_result`) with Membre, Email and Role (250 rows, no null role or email). It delivers the roles t0 lacked, but the columns are not the same as the previous turn, and the "Email"/"Role" headers are English. |
| digest t0 | 0.50 | 0.65 | 0.60 | **0.70** | 0.85 | pass (borderline) | **Fixes verified:** inline "Showing N of M" under every section (130 / 4 / 75 / 189 / 13 / 36 / 8) and no trailing append. Meetings carry union (+ place ZOOM), 146 is absent, and past fallback is used. Work orders show Supplier Name + Job + Sent At, 5 of 8, with no empty Price. Money rows match the standard (bills 15403 / BIL-000003/4/5; COT-000002/489/487/490/488). `failed_sources` and `skipped_sources` are []. **Defects:** (1) **no "$" on any amount**: `DigestResponseComposer::rowsTable()` (src/Services/Response/DigestResponseComposer.php:289) never passes `money_columns`, so TableRenderer does not format money. (2) "None pending." sits above the past-meetings table. (3) The empty source got its own heading ("### Supplier bills awaiting approval / Nothing pending in: …") instead of the collapsed line near the end (LLM did not follow the rule). (4) Raw codes: Urgent 1, Status 1/3, Flag Color 2/3/4, Customer Type "unit". (5) Reviews are named "Contact information". (6) No closing deepening offer, no intro line, no totals. Chips: 8 sections + 5 records (Unit 203 chip has no union). |
| digest t1 | 0.55 | 0.15 | 0.30 | **0.30** | 0.90 | **FAIL** | **Language fix did NOT take:** "La dernière réunion a eu lieu le 24 octobre 2023 à 16h00 … Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN." This is an English question with QU `language: en`. The guideline now *does* carry "(the question's language: 'en')", but gpt-4o-mini still answered in French. It was fed the 37-row previous-digest JSON sample plus a French FACTS block. `AnswerValidator` explicitly has no language check (src/Services/Response/AnswerValidator.php:31-32). Content: past-filtered, name+date, no 146, no digest block (negative control ok), and the chip (id 140) is correct. **Still** no union and no ZOOM place (the Cypher projects only name, date, id), and no "our". |
| digest t2 | 0.60 | 0.60 | 0.65 | **0.70** | 0.75 | pass (borderline) | Same sections and tables as t0 (identical render), `export_followup_offered: excel`, no failed or skipped sources. It ends with "The file is attached below." plus a real `export_excel` chip. The same digest defects as t0 apply: no $, "None pending.", empty-source heading, raw codes. The export chip title is still "**tasks needing attention**", which undersells "everything". |

## Means and counts

| Run | Mean, all 14 | Mean, p11's 13 (excl. rights t0) | Pass / Partial / Fail (14) | P/Pa/F (13) | Action mean 14 | Action mean 13 |
|---|---|---|---|---|---|---|
| p1c | 0.666 | 0.679 | — | 7 / 6 / 0 | — | — |
| p9 | 0.607 | 0.619 | — | 6 / 5 / 2 | 0.70 | 0.704 |
| p11 | (t0 void) | 0.662 | — | 7 / 5 / 1 | — | 0.769 |
| **p12** | **0.643** | **0.654** (-0.008 vs p11) | **8 / 4 / 2** | **8 / 3 / 2** | **0.775** | **0.781** (+0.012) |

- p12 answer scores, t0 to t13: 0.50, 0.55, 0.40, 0.75, 0.80, 0.90, 0.75, 0.90, 0.80, 0.35, 0.60, 0.70, 0.30, 0.70.
- p12 action scores, t0 to t13: 0.70, 0.70, 0.50, 0.75, 0.50, 1.00, 0.80, 1.00, 0.85, 0.75, 0.80, 0.85, 0.90, 0.75.
- Biggest gains vs p11:
  - cold-roster: +0.20.
  - digest t0: +0.10.
  - resilience t0, resilience t1, resilience t3 and digest t2: +0.05 each.
- Losses vs p11:
  - french t0: -0.45.
  - french t1: -0.15.
- p12 ties p11 on t2 and is above p1c on rights t0. Without the French regression, the 13-turn mean would be about 0.70, the best yet.

## Fix verification

| Fix | Exercised on | Result |
|---|---|---|
| Roster totals 523 (FanOutCollapse / anchor_total) | resilience t0/t1/t3, cold-roster, french t0/t1 | **Verified by outcome.** Every turn discloses 523, and `true_total == anchor_total == 523`. **The deterministic guard was not exercised:** the generator emitted MATCH-only or filtered OPTIONAL MATCH queries, and no fan-out or collapse appears in any trace. It remains untested on a real fan-out. |
| Cold-roster: one row per member, contact = User.email | cold-roster | **Verified.** 250 unique rows of User Name + User Email, no Contact join. |
| Digest: language follows the question | digest t1 (normal branch after a digest) | **FAIL.** French answer again (see table). The pin is present in the guidelines but is not enough with the French-heavy prior sample. There is no validator or retry. |
| Digest: all-empty columns dropped | digest t0/t2 | **Verified** (`withoutEmptyColumns`, no Price). |
| Digest: inline "showing N of M" | digest t0/t2 | **Verified** on both. |
| Digest: meetings with union | digest t0/t2 | **Verified** (+ place). Not carried into digest t1's single-meeting answer. |
| Digest: work orders with supplier + job | digest t0/t2 | **Verified.** Distinct supplier rows for the same quote request, 5 of 8. |
| FR "Réglages de l'équipe" chip | french t0 | **Verified.** "Mon profil" noise is back, though. |
| Chip-less turns never mention buttons/attachments | rights t0/t1/t2 | **Verified.** The empty-result prompt now says "never mention links, buttons or attachments". Note: the base system prompt still says unconditionally "next steps are attached as buttons", but it caused no leak this run. |
| Money "$" | digest t0/t2 (only money surfaces this run) | **FAIL in digest.** CondoEdge config sets `currency_symbol '$'`, but the digest's `rowsTable()` omits `money_columns`, so amounts render "7,022.87". It cannot be verified on DataAnswerPlan tables (no money data turn this run). |
| Date-only columns | digest | **Works as designed.** Receivables Due At is date-only. The bills Due At column has 3 of 4 rows with times, so it keeps times ("2022-06-29 13:51"). That is correct per the value rule but odd for a due date. |
| "These rows answer:" subject line | data turns t3, t4, t9, t12 | **Present** in the composition prompts. **Side effect:** on french t0 it framed a role-less result as "the answer", contributing to the false "avec leurs rôles". |
| Security hardening (fail-closed MATCH grammar, procedure allowlist) | all 10 generated Cyphers | **No false rejections.** Every execution has `safe_stop: false` with no errors, and no SecurityException, security block or rejection appears in any trace. The only "rejected" hits are the work-orders source description text. The security gate passed on all 14 turns, and `security_filter_applied: true` on every data execution. |

## Regressions vs p11 (traced cause)

1. **french t0: the roles column was dropped (0.80 → 0.35).**
   - QU (gpt-4o-mini) returned `entity_types ["Team"]` even though `property_concepts` contained "rôle". TeamRole was therefore never selected (selection: Team seed, User identity), and the generator listed users by `current_team_id`.
   - Composition then claimed "avec leurs rôles". The new subject-line instruction ("Present them as that answer") outweighed its own "say which value is not recorded" clause.
   - Fix direction: semantic mapping of QU `property_concepts` to entities in selection, so "rôle" seeds TeamRole. Use embeddings, not a word list.
   - Also add a post-execution check: when a requested concept has no projected column, the answer must say so.
2. **french t1: the export title is now fully English ("Team members roles").** The columns also differ from the previous turn, because the export re-ran its own Cypher (`current_result`).
3. **"My profile" identity-chip noise is back** on resilience t0, resilience t1 and french t0; the p11 chip-grain fix had removed it.
   - resilience t1 now has *only* My profile, and the model steers to it ("feel free to check your profile").
   - A row-capped list (25 shown of 523) should get the export chip instead.
4. **rights t2 query regressed (latent):** the open-only `due_amount` filter is back and the year filter is gone, while the answer says "for this year". It is invisible here only because Julie owns no unit.
5. **Closers are still present** on data turns ("just let me know!", "n'hésitez pas à demander", "feel free to check your profile"), despite the guideline.

## New / persistent defects

- **The digest t1 language bug persists** (the p11 F-language fix is ineffective).
  - Recommend the deterministic check p11 proposed: compare the answer language with `qu.language` and retry once. This needs an LLM-free language signal; the language is already known for the question.
  - Alternatively, strip or limit the previous-digest JSON sample on non-digest turns.
- **Digest renderer is missing money formatting:** pass `money_columns` from the digest sources' declared money properties into `rowsTable()`.
- **Digest prompt wording:** the FALLBACK rule lets the LLM write "None pending." for meetings; the meetings block should say "none scheduled". The EMPTY-collapse rule is also not followed, which argues for rendering the collapsed line deterministically.
- **Digest raw codes are unhumanized** (urgent, status, flag color, customer type), and reviews show as "Contact information".
- **digest t1 still has no union or place:** the Cypher projects only name, date and id.
- **rights t0 cites undivided-co-ownership articles** (1015/1016) as condominium rights. Retrieval ranks chunk 9 first (0.649), just above chunk 64 (0.648).

## Tokens and latency vs p11

- **Tokens: 165,733 total** (158,477 in / 7,256 out), 33 calls. p11 was 164,366 / 33, so this is **+1,367 (+0.8%)**. Per turn it is essentially flat, within ±0.5k.
  - Without rights t0: 155,880.
  - Heaviest: digest t1 at 20,266 (query generation 9.7k, carrying the previous digest rows) and rights t2 at 15,438.
  - Still avoidable: export follow-ups re-run query generation (resilience t3: 6.2k tokens and 1.7 s for an empty Cypher).
- **Latency: mean 14.5 s, median 13.8 s, on 14 turns.** p11 was 27.0 s / 19.5 s (infra-contaminated). This is a clean measurement: no Qdrant 408, and QU averages 6.35 s against p11's 11.8 s.
  - Code-side hotspots: QU at about 6-7.5 s on every turn (about 45% of a typical turn), and digest composition at 13.3 s / 14.9 s.
  - That digest composition is gpt-4o-mini re-typing pre-rendered tables, at about 1.28k output tokens. Emitting the digest tables deterministically and having the LLM write only the framing would save about 12 s per digest turn and remove the collapse, "None pending" and offer variance.
