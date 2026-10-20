# Grade: p24-1001-B (Part B) vs p23 and p22

- Run: `CondoEdge/storage/eval/p24-1001-B.json`, generated 2026-10-07T06:51. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3 "Novo Gestion", owns no unit). 0 void, 0 infra, 14 rows `ok`, 16 / 16 harness gates passed, `security_gate.passed` 14 / 14.
- Rubric and strictness as p14 to p23. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1. Follow-up chips are scored in the action score.
- Same ai package sha (badd2f1), dirty tree 610 -> 617 files. Host config sha 676831b1 -> 017a8b13. Models unchanged (QU and composition gpt-4o-mini, query generation and follow-up writer gpt-4o).
- Top-level counters describe one chunk only. Everything below is summed from the 14 rows: **169,365 tokens, 37 LLM calls** (p23: 180,244 / 40; p22: 165,205 / 37).
- Headline: answers 0.846 -> **0.882**, actions 0.825 -> **0.900**, **14 pass / 0 partial / 0 fail** (p23: 13 / 1 / 0; p22: 0.879 / 0.900, 14 / 0 / 0).
- Both p23 routing flips are back to their p22 route: app-nav has `navigation_target: own_team` and its Team settings button; digest t2 has `digest_request: true` and is the digest with its export. All 14 routes equal p22's.
- The QU system prompt is byte-identical to p22 and p23 on every first turn (sha b6730279dd). The strict schema travels outside the prompt text: +279 input tokens on every QU call, output tokens down 32%.
- The content fields did move: on 7 of 14 turns a field that p22 and p23 agreed on (`intent`, `entity_types`, `property_concepts` or `subject_label`) is different in p24. Effect on answers: one better (rights t2 gets its year filter), one worse (resilience t1 header "Last Logged At", carried into the t3 file), five with no effect on the answer.
- One run cannot separate a schema effect from ordinary variance on a single turn. Where p22 and p23 agreed and p24 differs on an identical prompt, the table says "schema (probable)".
- Four of the code changes of this iteration were **not exercised**: `checked_line_echo` (the model did not copy the line), the page-narration rule (page context is null on all 14 turns), the widened navigation guard (no trace field), the label-echo guard (no echo).

---

# 1. Per-turn grading

| Turn | p22 ans / act | p23 ans / act | p24 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|
| rights t0 | 0.70 / 0.85 | 0.80 / 0.85 | **0.70 / 0.80** | pass (borderline) | p22's answer again, almost heading for heading. Same six passages as p22 (chunks 59, 69, 64, 32, 82 + 47 added by selection). Standard's four elements: free use yes [1]; contribution yes; **contingency fund absent**; by-laws an explicit cited duty [6] (art. 1054, the selection addition, used); no interference with work yes [1]. Against that: **three duties filed under Rights** ("You are entitled to contribute", "You must obtain ... insurance", "you must notify"), the notice in both lists ("As mentioned"), no article numbers, platitude closer shipped. New and correct: the restricted-use rule of art. 1064. New and wrong: "If you **sell** or lease your unit, you must notify" (art. 1065 puts the notice on the person who acquires; p22 had it right). Art. 1069 (the acquirer's rule) again used as a general duty [3]. Action: two chips, both answerable, both narrow (lessee notices; what the register holds); p22's natural "revision of the apportionment" chip was not offered. |
| rights t1 | 0.85 / 0.80 | 0.75 / 0.90 | **0.80 / 0.85** | pass | New and correct: board fixes the annual contribution after consulting the meeting, contingency fund included (art. 1072) [2]; notice of amount and due date (art. 1072) [2]; vote lost after three months, **stated firmly** (art. 1094) [5]; assessment and collection rules in the declaration (art. 1054) [4]. Against p23: no off-thread insurance bullet, no hedge, **closer dropped by code** (`closing_dropped`: platitude plus "feel free to ask"). Against p22: **no contingency-fund point of its own** (p22 gave art. 1071; the passage is [2] here too). Still two bullets on the covered art. 1064 and an introduction. No legal recovery (not in the corpus). Action: three chips, all answerable from their excerpt, none already answered: relative value (natural), who sets up the contingency fund and who sets the self-insurance minimum (both on the thread, both thin "who" questions). |
| rights t2 | 0.85 / 0.90 | 0.80 / 0.90 | **0.85 / 0.90** | pass | "You have no unit linked to your account, so there are no contributions to show for this year. You might want to check the receivables report for more information." then the checked line **once**. `empty_result_check {scope: own, ownership_check {Unit, count 0}}`, 0 rows (a list query this time, not a sum over nothing). No $0 amount, no claim about the team. Receivables report chip. Residual as p22: the door is not said to be the portfolio's; "for your units" beside "no unit". |
| resilience t0 | 0.90 / 0.95 | 0.90 / 0.95 | **0.90 / 0.95** | pass | Same query, answer byte-identical to p22 and p23 (sha 999b10f7). "523 members.", Name / Email / Role, truncation line, Team settings + Download Excel. |
| resilience t1 | 0.95 / 0.95 | 0.95 / 0.95 | **0.90 / 0.95** | pass | Same rows, same 523. Column alias `last_logged_at` (p22, p23: `last_login`), so the header is **"Last Logged At"** and the facts line reads "Last Logged At recorded for 2 of the 25 shown." Lead: "I can provide the last login information for your team members." (an offer placed above the table that already provides it); code cut the second sentence "Here are the details:" (`lead_trimmed: one_sentence`). Data correct, figures true; wording a step down. |
| resilience t2 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | `navigation_target: own_team`, `search_probes {skipped: navigation_target}`, no selection, no follow-up call, two calls. Four grounded steps, contact distinction cited [1] with the pointer to the Contacts guide (in the doc), roles and removal on the same page (in the doc), pointer to Team Settings, Team settings chip. Roster survives (t3 exports 523). |
| resilience t3 | 0.95 / 0.85 | 0.95 / 0.85 | **0.95 / 0.85** | pass | `recorded_result_export {generation_skipped: true}`, one call, 5,527 tokens, 3.8 s. 523 rows, same row values as p22 and p23 (same hash), 18 with a last login. Title "Team members last login". Header "Last Logged At" inherited from t1 (charged there). |
| app-nav t0 | 0.85 / 1.00 | 0.85 / 0.30 | **0.90 / 1.00** | pass | **Route restored.** `navigation_target: own_team`, Team settings button attached (`role: identity`), probes skipped, no passage selection, no follow-up call, two calls, 7,937 tokens (p23: five calls, 16,379). Two help documents in the prompt, no law passage. Four grounded steps, contact distinction cited [1], one pointer sentence naming Team Settings. **No button list in the text** (p22's echo is gone), no hedge. |
| cold-roster t0 | 0.90 / 0.85 | 0.90 / 0.85 | **0.90 / 0.85** | pass | Query and file identical to p22 (same row hash): User Name / Contact Email, 523 rows, both filled, "all 523 members", four gates pass. p23's Contact Info column (phones) is not there; neither is its e-mail duplication. Title "Current Team Members" (p22, p23: "... Contact Info"). No roles. |
| french t0 | 0.85 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Answer byte-identical to p23 (sha badadbf9). Entirely French, "523 membres.", Nom / Courriel / Rôle, truncation line, both chips in French. Residual: "(c'est vous)" on two cells. |
| french t1 | 0.95 / 0.85 | 0.95 / 0.85 | **0.95 / 0.85** | pass | Answer and file byte-identical to p23. `generation_skipped: true`, one call, 5,388 tokens, 4.5 s. "Champs inclus : Nom, Courriel, Rôle.", 523 rows, "En date du", "Télécharger Excel". Residual: title "Membres de **mon** équipe". |
| digest t0 | 0.85 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Digest route, composition prompt byte-identical to p22 and p23. Nine sources, `failed_sources: []`, `skipped_sources: []`, eight sections, true totals (130, 75, 189, 13, 36, 8), bills $11,493.17, one "Nothing pending in:" line, one closing question. All eight framing lines start with a figure or a capital. 53 table lines identical to p22 and p23. 16 chips unchanged. |
| digest t1 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Same query, one row, no digest block. Record block unchanged (name, 2023-10-24 16:00, ZOOM, president, union). Lead restates name, date and place. One record chip. |
| digest t2 | 0.90 / 0.90 | 0.40 / 0.45 | **0.90 / 0.90** | pass | **Route restored.** `digest_request: true` with `export_format: excel`; digest block present, nine sources, `failed_sources: []`, `export_followup_offered: excel`, all eight sections, "Download Excel" chip (`export_excel`, mode table, title "Items needing your attention"), single closing line "The EXCEL file with everything shown is attached below." 53 table lines identical to digest t0. 17 chips as p22. Residual as p22: "EXCEL" in capitals; "Most recent meetings show no scheduled items." |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p22 | 0.879 | 14 / 0 / 0 | 0.900 |
| p23 | 0.846 | 13 / 1 / 0 | 0.825 |
| **p24** | **0.882** (+0.036 vs p23, +0.004 vs p22) | **14 / 0 / 0** | **0.900** (+0.075 vs p23, 0 vs p22) |

- p24 answers, t0 to t13: 0.70, 0.80, 0.85, 0.90, 0.90, 0.90, 0.95, 0.90, 0.90, 0.90, 0.95, 0.90, 0.90, 0.90 (sum 12.35).
- p24 actions, t0 to t13: 0.80, 0.85, 0.90, 0.95, 0.95, 1.00, 0.85, 1.00, 0.85, 0.90, 0.85, 0.90, 0.90, 0.90 (sum 12.60).
- Against p23, answers: digest t2 +0.50, rights t1 +0.05, rights t2 +0.05, app-nav +0.05; rights t0 -0.10, resilience t1 -0.05. Actions: app-nav +0.70, digest t2 +0.45; rights t0 -0.05, rights t1 -0.05.
- Against p22, answers: app-nav +0.05, french t0 +0.05, digest t0 +0.05; rights t1 -0.05, resilience t1 -0.05. Actions: rights t1 +0.05; rights t0 -0.05.
- The gain over p23 is the two routes (+0.55 answer, +1.15 action over two turns). Outside those two turns the run is 0.05 below p23 on answers and 0.10 below on actions, both on the rights conversation and resilience t1.

---

# 2. Query understanding and route, per turn

Routes: all 14 equal p22. Against p23 two differ, both restored: app-nav (navigation target) and digest t2 (digest).

Read "schema (probable)" as: p22 and p23 returned the same value on a byte-identical or summary-only-different prompt, p24 returned another. "Variance" means the three runs do not agree anyway.

| Turn | Route p22 / p23 / p24 | QU fields that differ in p24 | Cause | Downstream: query, rows, columns, facts line, chips | Effect on the answer |
|---|---|---|---|---|---|
| rights t0 | knowledge x3 | `property_concepts` + "co-owner"; `clarified_question` reworded ("regulations and guidelines regarding..."); `search_tags` | schema (probable) | The clarified question is a retrieval angle and feeds the probe writer. Final passages are p22's six exactly; p23's merged passage and its general-meeting passage are not there. Selection picked art. 1054 (as p22; p23 picked nothing). | **None vs p22.** Vs p23 worse (-0.10), but p23's set came from a duplicate probe, not from QU: p22 and p23 had the same clarified question and different passages. |
| rights t1 | knowledge x3 | `entity_types` Fund -> Budget; `property_concepts` + "duty"; `subject_label` "Duty to Contribute to Expenses"; clarified question "my duty..." | schema (probable) | Entities are unused on the knowledge route. Passages: [1] 1063-1066, [2] 1071-1074, [3] 1068.1-1070, [4] 1052-1056, [5] 1092-1097 (p23 had 1044-1048 as a fill; p24 has none off topic except [3]). One probe pair dropped as duplicate (0.82). Selection: 0 picked (as p22, p23). | **Not attributable.** Answer between p22 and p23; the passage holding art. 1071 is in the prompt in all three runs. |
| rights t2 | data, empty result x3 | `property_concepts` "expenses" -> **"year"**; `subject_label` "Contribution Amount This Year"; clarified question "... for the current year" | schema (probable) | "year" became a requested attribute, and the query now carries `substring(n.invoiced_at, 0, 4) = '2026'` (p23 had no year filter). Query is a list (invoice number, due amount, invoiced at), 0 rows. Checked line unchanged. | **Better, latent.** The answer text is p22's; the query now tests the year the answer names. |
| resilience t0 | data x3 | none (label case as p22) | n/a | Query identical, 100 of 523, Name / Email / Role, facts line `count`, chips Team settings + Download Excel. | None. |
| resilience t1 | data x3 | `intent` other -> list; `entity_types` [] -> [User, Team]; `property_concepts` [] -> **["login"]**; clarified question in "entities" wording | schema (probable) | A REQUESTED ATTRIBUTES block entered the generation prompt (absent in p22, p23). It carries the rule "alias each returned column after the PROPERTY it holds", so the alias is `last_logged_at`. Header and facts-line noun "Last Logged At". Entity cards BoardMember and Union replaced Activity. Generation input 6,129 -> 7,436 tokens. Same 100 of 523 rows, same chips. | **Worse, cosmetic (-0.05).** The same header reaches the t3 file. |
| resilience t2 | knowledge, navigation x3 | `intent` other -> **page_explanation**; `entity_types` [] -> [Team, User]; `subject_label` "Add New Members" | schema (probable; the code comment records "on every run under the strict reply schema") | `navigation_target: own_team` kept. Probes skipped, two help documents, button attached. The page-narration rule returns before reading the page; with `page_context: null` no narration could have happened in this harness either way. | **None.** |
| resilience t3 | export (recorded) x3 | `intent` list (p22 list, p23 other); `clarified_question` empty | variance | Skip fired, 523 rows, title from the label "Team members last login". | None. |
| app-nav t0 | knowledge, navigation / **knowledge, no target** / knowledge, navigation | `navigation_target` own_team (p23 absent); `intent` page_explanation; `entity_types` [] (as p23) | **schema: the field is required now** | Button back, probe writer, passage selection and follow-up writer not called (-3 calls, -8,442 tokens, -5.2 s vs p23). | **Better** (+0.05 answer, +0.70 action vs p23). |
| cold-roster t0 | export x3 | `subject_label` "Current Team Members" (p22, p23 "... Contact Info"); clarified question as p22 | schema (probable) for the label; p23's "contact information entities" wording was the outlier | Query byte-identical to p22: no ContactDirectory join. User Name / Contact Email, 523 rows. Export title shortened. | **None vs p22.** Vs p23: one column fewer (phones), no mixed cell; same score. |
| french t0 | data x3 | none vs p23 (`property_concepts` "membres, rôles") | n/a | Query and answer byte-identical to p23: name, email, role. | None vs p23; +0.05 vs p22 (e-mail column). |
| french t1 | export (recorded) x3 | `intent` file_listing (as p22; p23 list); `person_names` [] (p22, p23 had the user's name); `property_concepts` [] (as p22) | variance | Skip fired, same file as p23. `file_listing` together with `export_format` did not divert the turn. | None. |
| digest t0 | digest x3 | `search_query`, tags; `subject_label` "Pending Tasks" (as p22) | variance | Digest, nine sources, 37 rows, 16 chips. | None. |
| digest t1 | data, single record x3 | clarified question "most recent" | variance | Query identical, one row, enrichment identical. | None. |
| digest t2 | digest / **export of tasks** / digest | `digest_request` true (p23 false) | **schema: the field is required now** | Digest with every section and the export chip instead of a generated query over Task (131 rows). -4,808 tokens, -13.3 s vs p23. | **Better** (+0.50 / +0.45 vs p23). |

- Content fields that moved where p22 and p23 agreed: 7 turns of 14 (rights t0, t1, t2, resilience t1, t2, app-nav, cold-roster). Net on scores: -0.05 (resilience t1). The one gain (rights t2's year filter) is latent and scores nothing with this user.
- `subject_label` case follows p22 ("Team members last login"), not p23's title case. Export titles follow it.

## Verification items

| Item | Result | Evidence |
|---|---|---|
| App-nav: navigation target set | **Yes** | `navigation_target: own_team`; `search_probes {skipped: navigation_target}`. |
| ... page button attached | **Yes** | `attached_actions: [{role: identity, key: team_settings, text: "Team settings"}]`. |
| ... no probe / passage-selection / follow-up calls | **None** | Usage: understanding 5,061 / 122 and composition 2,595 / 159 only. No `follow_ups` stage. |
| ... how-to document only | **Yes** | Two files, both help documents (team-and-members.md, contacts.md); cited [1] only. contacts.md was under the floor on three angles and entered as a question passage, as in p22. No CCQ passage. |
| Digest t0: digest route, all sections | **Yes** | Nine sources run, 0 failed, 0 skipped; eight sections plus the combined empty line. |
| Digest t2: digest export with every section, "Download Excel" | **Yes** | `export_followup_offered: excel`; chip `export_excel` (mode table, "Items needing your attention"); eight sections; closing line acknowledges the file. The trace does not hold the file's rows, so its content is not verified here (as p22). |
| Digest t1: single meeting record | **Yes** | One row, no digest block, record chip. |
| Rights t0 / t1: knowledge route | **Yes** | `branch: knowledge_query` on both, no cypher. |
| ... passages | t0: art. 1063-1066, 1070-1070.2, 1067-1069, 1038-1040, 1075.1-1079, 1054. t1: 1063-1066, 1071-1074, 1068.1-1070, 1052-1056, 1092-1097. | `retrieved_chunks`. |
| ... selection additions and use | t0: pool 20, **1 picked, 1 added (art. 1054), cited [6]**. t1: pool 20, 0 picked. | 2 calls, 7,877 tokens, 1.4 s and 2.0 s. |
| ... indivision absent | **Absent** | `branch_coherence` dropped chunks 7, 8, 9, 11, 15 of Chapter II on t0 and chunk 11 on t1. |
| ... contingency fund present | **t0: no**, in neither passages nor answer (the chunk with art. 1071-1072 was selection candidate 74; the one pick went to by-laws). **t1: in the passages [2]; in the answer only inside the annual contribution.** | |
| Rights t2: own-data query | Yes | `MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL MATCH (n:Invoice)-[:CUSTOMER]->(u) WHERE n.budget_id IS NOT NULL AND substring(n.invoiced_at, 0, 4) = '2026' RETURN n.invoice_number AS invoice_number, n.due_amount AS amount, n.invoiced_at AS invoiced_at, n.id AS invoice_id LIMIT 100` |
| ... year filter | **Yes** | `substring(n.invoiced_at, 0, 4) = '2026'` (p22 yes, p23 no). |
| ... both ownership branches | **No, unit branch only** | `(n:Invoice)-[:CUSTOMER]->(u)`. The prompt's ownership path offers both branches, parenthesised; the generator did not use it (as p23). |
| ... OR parenthesised | **No OR in the query** | `precedence_violations: []`, `repair_attempts: 0`. |
| ... Checked line printed once | **Once** | One "Checked:" in the answer. `checked_line_echo` is absent: the model wrote 34 output tokens and did not copy the line, so the new removal did not run. |
| Resilience t2 (how-to) | As p22 | Section 1. |
| Resilience t3 (export skip) | **Skipped** | `recorded_result_export {generation_skipped: true, recorded_rows: 100}`, then `export {previous_result, rows: 523}`. |
| Roster EN | Name / Email / Role; "523 members."; Team settings + Download Excel (rerun, "Team members and roles") | byte-identical to p22, p23. |
| Roster FR | Nom / Courriel / Rôle; "523 membres."; Réglages de l'équipe + Télécharger Excel | byte-identical to p23. |
| French export follow-up skip | **Skipped** | `generation_skipped: true`, one call. |
| Precedence check | Fired on none | 6 generations, `attempts: 1`, no OR in any query or in the enrichment query. |
| Security | **Nothing wrong** | `safe_stop: false` on all 6 executions; `security_filter_applied: true` on the 6 queries and the enrichment query. No SecurityException text. |
| Admission | **Nothing wrong** | No `admission` stage, no busy answer, no error on any row. |
| Harness gates on the two turns that flipped in p23 | **Still none** | `gates: []` on app-nav and on digest t2. This run's two restored routes are graded by reading, not by a gate. |

## Lead cuts, closing drops, echo drops

| Turn | What code removed | Rule | Verdict |
|---|---|---|---|
| resilience t0 | "Here are the members of your team along with their roles:" | `restatement` | **GOOD** |
| resilience t1 | "Here are the details:" | `one_sentence` | **GOOD** (nothing lost). The sentence it kept is the weaker one: "I can provide the last login information for your team members." |
| french t0 | "Voici la liste des membres de votre équipe avec leurs rôles :" | `restatement` | **GOOD** |
| rights t1 | "Understanding these obligations is crucial ... If you have further questions ..., feel free to ask!" | `closing_dropped` | **GOOD**: platitude plus bare invitation; p23 shipped the same kind of paragraph. |
| rights t0 | nothing | n/a | Platitude closer survived ("These rights and obligations ensure the smooth operation..."). Cosmetic. |
| all | no `label_echo_dropped`, no `checked_line_echo` | n/a | 0 GOOD, 0 HARMFUL. |

Totals: 3 lead cuts GOOD, 1 closing drop GOOD, 0 HARMFUL.

## Every follow-up chip

| Turn | Chip question | Source (tier) | Label | Answerable from its excerpt |
|---|---|---|---|---|
| rights t0 | What notices must the syndicate give to a lessee? | art. 1066 paragraph 2 (tier 1) | **A** (narrow; a landlord's case) | Thinly: the excerpt points to articles 1922 and 1931 |
| rights t0 | What information does the syndicate's register contain about co-owners? | art. 1070 (tier 3, question-supported) | **A** (a right the answer did not give, asked as a content question) | Yes |
| rights t1 | Who determines the terms for the minimum contribution to the self-insurance fund? | art. 1072 last paragraph (tier 1) | **A** (on the thread, narrow) | Yes ("the Government, by regulation") |
| rights t1 | Who establishes the contingency fund for major repairs and replacements? | art. 1071 (tier 2) | **A** (the right thread and the answer's gap; a thin "who" question) | Yes |
| rights t1 | What specifies the relative value of each fraction in a co-ownership? | art. 1053 (tier 2) | **N** | Yes |
| digest t0 | Go through Open tasks (most urgent first) | section | N | Yes; click not exercised |
| digest t0 | Go through Overdue receivables | section | N | Yes; click not exercised |
| digest t0 | Go through Units with expired insurance | section | A | Yes; click not exercised |
| digest t2 | Go through Open tasks (most urgent first) | section | A (the ask was a file) | Yes; click not exercised |
| digest t2 | Go through Overdue receivables | section | A | Yes; click not exercised |
| digest t2 | Go through Units with expired insurance | section | A | Yes; click not exercised |

- Totals: 11 chips; 3 N, 8 A, **0 O** (p23: 8 chips; 5 N, 3 A, 0 O. p22: 10 chips; 4 N, 5 A, 1 O).
- Knowledge turns: 5 chips, 1 N, 4 A, all answerable (p23: 5 chips, 3 N, 2 A).
- Excerpts: 8 of 8 end on a whole sentence. Declined: art. 1038 and 1075.1 on t0, art. 1093 on t1 (all right, off the thread).
- Three of the five law chips are "who" or "what holds it" questions with a one-word answer. None is wrong; none opens much.

---

# 3. Regressions vs p22

Net: answers +0.004, actions equal, same calls, +2.5% tokens. Three items are below p22.

1. **Resilience t1: header and facts line "Last Logged At", offer-shaped lead (-0.05 answer). Schema effect (probable), through a prompt rule.**
   - p22 and p23 QU returned `property_concepts: []` and `entity_types: []` on this follow-up; p24 returned `["login"]` and `[User, Team]` on the same instructions.
   - A non-empty concept list adds the REQUESTED ATTRIBUTES block to the generation prompt. Its line "alias each returned column after the PROPERTY it holds, never after the word the user used" produced `u.last_logged_at AS last_logged_at`.
   - The table header and the facts-line noun are the alias made readable. The export follow-up reuses the recorded columns, so the file has the same header.
   - The lead is the composer's (composition prompt differs from p23 by the header only); `one_sentence` kept its first sentence.
   - Cost: +1,307 generation input tokens on this turn.
2. **Rights t1: no contingency-fund point of its own (-0.05 answer). Composer variance.**
   - Art. 1071 is in passage [2] in p22, p23 and p24. p22 wrote a fund bullet; p23 and p24 did not.
   - The QU differences on this turn (Fund -> Budget, reworded clarified question) change retrieval angles only; the passage that matters was retrieved every time.
   - Offset on the action side: three answerable chips, none already answered (+0.05 vs p22).
3. **Rights t0: weaker chips (-0.05 action). Excerpt choice and writer variance.**
   - p22 was offered art. 1064 paragraph 2 and art. 1068 paragraph 1 and wrote one natural chip (revision). p24 was offered art. 1066, 1070, 1038, 1075.1; art. 1068 was not among them although the answer does not give the revision right. Not traced further.
   - The answer itself is p22's level: same passages, same misfiling. One legal detail is worse (art. 1065 "sell"), one is new and correct (restricted use).
4. **Tokens +4,160 vs p22 (+2.5%). Schema, by design.** Understanding +3,032 (input +3,924, which is about +279 on each of 14 calls; output -892); generation +1,625 (resilience t1 +1,309, rights t2 +337); the other four stages -497.

Not regressions:

- Rights t0 at 0.70 is p22's score. p23's 0.80 came from a duplicate probe that merged three chunks; it did not recur.
- Cold-roster has no phone column: that was p23's one-off join. The file equals p22's.

Fixed or improved from p23's list:

- #1 digest ownership on the combined turn and #2 the navigation target: both routes hold on this run. One run each; the harness still has no gate on either.
- #3 checked line twice: printed once. The model did not copy it, so the fix is unproven by this run.
- #6 invitation closer on rights t1: dropped by code.
- #7 period missing from the query: the year filter is there, because QU returned "year" as a concept. No code checks it.

---

# 4. Remaining defect ranking

Turns below 0.90 on answer: rights t0 (0.70), rights t1 (0.80), rights t2 (0.85). Actions below 0.90: rights t0 (0.80), rights t1, resilience t3, cold-roster, french t1 (0.85).

| # | Defect | Turns | Points at stake | Lost at | Variance? | Most structural fix | Determinism |
|---|---|---|---|---|---|---|---|
| 1 | Overview misfiling: duties listed under Rights, one duty in both lists, art. 1069 as a general duty, art. 1065 misread, no article numbers | rights t0 | 0.10-0.15 answer; decides pass or partial | Composition | **Pure variance** (p23 filed all seven correctly on the same instructions) | None deterministic. | Low |
| 2 | Contingency fund absent from the overview: selection may add one passage and spends it on by-laws; two off-topic passages ([4] establishment, [5] insurer subrogation) keep their slots | rights t0 | 0.10 answer, plus the fund chip | Passage selection (cap of 1, add-only) | Partly | As p22 #1 and p23 #4: allow two additions when the pool holds them (the art. 1071-1072 chunk was a candidate again). Measure on replays. | Medium |
| 3 | Header and facts-line noun are the raw property name when QU returns a concept | resilience t1, t3 file | 0.05 answer | Query generation rule "alias after the property", then the header renderer | Triggered by the QU content change; stable once triggered | Let the host schema give a display label per property and have the renderer use it when the alias equals a property name. The rule that produced the alias is right for mapping; only the label is missing. | Very high |
| 4 | Deepening answer restates the covered rule in two bullets and leaves a named specific (the fund) inside another point | rights t1 | 0.05-0.10 answer | Composition | Mostly variance | Unchanged from p20. | Low |
| 5 | Own-invoice query takes the `[:CUSTOMER]` edge: unit branch only, no status filter, "amount" is `due_amount` (what is still owed, not the contribution) | rights t2 (latent) | 0 here; wrong money for a real owner | Query generation | Variance (p22 used both branches) | When the ownership scope is the user's own and the prompt carries a declared ownership path for the entity, code checks that the query contains that path's two customer types, and sends it to the repair loop otherwise. The year filter needs the same kind of check (p23 #7): it is present now only because QU happened to return "year". | High |
| 6 | Law chips are thin: one-word "who" answers, one that answers by pointing at other articles | rights t0, t1 | 0.05-0.10 action | Follow-up writer; excerpt choice | Partly | The writer's own rule already bars questions that another text settles; code can drop a chip whose fact cites only article numbers. The "who" shape is a writer choice. | Medium |
| 7 | Harness: no gate on digest ownership (digest t2) or on the attached page action (app-nav) | suite | detection only | Suite | No | Add `expect` entries for `response_metadata.digest` with `export_followup_offered`, and for an attached `team_settings` action. Without them the next flip passes green again. | Very high |
| 8 | `one_sentence` keeps the first lead sentence whatever it says | resilience t1 | 0-0.05 answer | `DataAnswerPlan` lead rule | Composer wording | When the first sentence carries no figure and no name from the rows, drop it too (the facts line already opens the answer). | High |
| 9 | Platitude closer survives when its words are grounded enough | rights t0 | 0-0.05 answer | `AnswerValidator` closing rule | Wording decides (dropped on t1, kept on t0) | As p22 #5. | High |
| 10 | Selection call that adds nothing | rights t1 | 0 points; 3.9k tokens, 2.0 s | Passage selection | No | Cost of the design: 2 useful additions in 7 Part B calls over three runs, both art. 1054 on rights t0. | n/a |
| 11 | No live evidence for: `checked_line_echo`, the page-narration rule under a navigation target, the widened navigation guard, the two-line echo guard, the precedence check | all | 0 | Trace | No | The narration rule needs a suite turn with a page context; the navigation guard needs a trace field when it drops a target. | Very high |
| 12 | Carried, unchanged: "(that's you)" on two cells; "mon équipe" title; "EXCEL" in capitals; "Most recent meetings show no scheduled items."; `t.id = 3` and no ORDER BY in roster queries; "rows" in the export line of resilience t3; record lead restates block values; the door on rights t2 not said to be the portfolio's | several | 0-0.05 each | as p22, p23 | mixed | as p22, p23 | High |

## Pure model variance this run

- Composer: filing on rights t0, the fund point on rights t1, the lead on resilience t1.
- Generator: the `[:CUSTOMER]` path on rights t2; cold-roster back to two columns.
- Probe wording on both law turns (one duplicate pair dropped on t1).
- Follow-up writer: question shapes.
- QU, outside what the schema fixes: `intent` on resilience t3 and french t1, search tags and clarified questions everywhere.

## Reading of the run

- As a regression check on routes: clean. 14 of 14 routes are p22's, both flips are gone, no new route moved.
- As a regression check on data answers: 6 generated queries, 3 byte-identical to p22 (resilience t0, cold-roster, digest t1), 1 identical to p23 (french t0), 2 changed (rights t2 better, resilience t1 one alias). Row counts and totals are unchanged on every data turn (523, 523, 523, 523, 1, 0).
- The schema's side effect on content fields is real on this part (7 of 14 turns) and cheap: one cosmetic loss, one latent gain.
- The part is back at its p22 level, not above it. What separates 0.88 from 0.90 is the rights conversation, which no change of this iteration touches.
- Both restored turns still have no gate.

---

# 5. Tokens and latency vs p23

| Stage | p22 input / output (calls) | p23 input / output (calls) | p24 input / output (calls) | Delta input vs p23 |
|---|---|---|---|---|
| understanding | 68,700 / 2,777 (14) | 68,721 / 2,764 (14) | **72,624 / 1,885** (14) | **+3,903** (+279 per call); output -879 (-32%) |
| search_probes | 1,354 / 311 (2) | 2,020 / 436 (3) | 1,357 / 309 (2) | -663 (app-nav) |
| passage_selection | 8,021 / 12 (2) | 11,633 / 15 (3) | 7,865 / 12 (2) | -3,768 (app-nav -3,737) |
| query_generation | 40,093 / 681 (6) | 48,135 / 770 (7) | 41,755 / 644 (6) | -6,380 (digest t2 -7,682, resilience t1 +1,307) |
| composition | 39,179 / 1,668 (11) | 40,713 / 1,438 (10) | 38,714 / 1,684 (11) | -1,999 (app-nav -3,085, rights t0 -766, rights t1 -704, digest t2 +2,540) |
| follow_ups (gpt-4o) | 2,258 / 151 (2) | 3,401 / 198 (3) | 2,344 / 172 (2) | -1,057 (app-nav -1,039) |
| **Total (in + out)** | **165,205** (37 calls) | **180,244** (40 calls) | **169,365** (37 calls) | **-10,879 (-6.0%) vs p23; +4,160 (+2.5%) vs p22** |

- By turn vs p23: app-nav -8,442, digest t2 -4,808, rights t1 -663, rights t0 -393, resilience t1 +1,553; the other nine +159 to +250 each (the schema on the QU call).
- QU call: input 4,909 -> 5,187 per call on average, output 197 -> 135. Total QU tokens 71,485 -> 74,509 (+4.2%).
- First chunk 95,977 / 22 calls (p23 94,603 / 22); second chunk 73,388 / 15 calls (p23 85,641 / 18).

| Stage latency | p22 mean / median | p23 mean / median | p24 mean / median |
|---|---|---|---|
| understanding | 6.5 s / 6.1 s (max 12.2) | 7.4 s / 6.1 s (max 20.1) | **4.8 s / 4.9 s (max 5.6)** |
| file_context (4 knowledge turns) | 6.2, 6.4, 2.7, 2.7 s | 5.9, 9.9, 2.5, 7.0 s | 6.2, 6.4, 2.6, 3.1 s |
| of which probe call | 2.3 s, 2.3 s | 2.2 s, 5.5 s, 2.7 s | 2.4 s, 2.3 s |
| of which passage selection | 1.6 s, 1.6 s | 1.3 s, 1.9 s, 1.1 s | 1.4 s, 2.0 s |
| query_generation | 2.1 s / 2.0 s (6) | 2.3 s / 2.2 s (7) | 2.0 s / 2.0 s (6) |
| execution | 0.3 s / 0.2 s (6) | 0.3 s / 0.2 s (7) | 0.2 s / 0.2 s (6) |
| empty_result_check | 0.04 s | 0.03 s | 0.02 s |
| record_enrichment | 0.06 s | 0.02 s | 0.03 s |
| recorded_result_export | 0 s (2) | 0 s (2) | 0 s (2) |
| composition (14 rows) | 2.3 s / 1.6 s | 2.0 s / 1.4 s | 2.3 s / 1.6 s |
| follow_ups | 1.8 s (2) | 1.7 s (3) | 1.9 s (2) |
| digest | 0.6 s (2) | 0.5 s (1) | 0.6 s (2) |
| **pipeline** | **11.4 s / 10.2 s** (max 25.5) | **12.9 s / 10.5 s** (max 24.9) | **9.8 s / 9.0 s** (max 18.7) |

- Per turn, p23 -> p24 (s): 18.4 -> 18.7, 24.9 -> 18.4, 10.6 -> 9.1, 9.3 -> 8.3, 9.8 -> 8.3, 14.1 -> 12.2, 5.2 -> 3.8, 16.3 -> 11.1, 9.8 -> 7.1, 9.4 -> 8.5, 6.1 -> 4.5, 10.4 -> 9.2, 13.4 -> 8.9, 22.5 -> 9.2.
- The mean turn is 3.1 s faster than p23 and 1.6 s faster than p22; the median 1.5 s and 1.2 s.
- Understanding carries most of it: 2.6 s less per call on average, every call between 3.6 s and 5.6 s. Shorter output (-32%) accounts for part; the rest may be API latency at the hour of the run, and one run cannot split the two.
- The two restored routes carry the rest: digest t2 -13.3 s (15.8 s of it one slow QU call in p23), app-nav -5.2 s (3.9 s of file context and 1.3 s of follow-ups).
- Slower than p23: composition on resilience t2 (3.0 -> 4.8 s) and app-nav (2.2 -> 3.1 s), on longer answers.
