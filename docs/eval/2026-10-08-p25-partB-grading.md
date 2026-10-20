# Grade: p25-1001-B (Part B) vs p24 and p23

- Run: `CondoEdge/storage/eval/p25-1001-B.json`, generated 2026-10-07T15:11. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3 "Novo Gestion", owns no unit). 0 void, 0 infra, 14 rows `ok`, 16 / 16 harness gates passed, `security_gate.passed` 14 / 14.
- Rubric and strictness as p14 to p24. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1. Follow-up chips are scored in the action score.
- Same ai package sha (badd2f1), dirty tree 617 -> 666 files. Host config sha 017a8b13 -> 03be375a (not comparable, the fingerprint now hashes `{ai, entities}`). Models unchanged.
- Top-level counters describe one chunk only (98,144 tokens / 21 calls = rows 0-6). Summed from the 14 rows: **171,375 tokens, 36 LLM calls** (p24: 169,365 / 37).
- Headline: answers 0.882 -> **0.882**, actions 0.900 -> **0.907**, **14 pass / 0 partial / 0 fail** (p24: 14 / 0 / 0).
- Removal check (A): **clean**. All 14 routes equal p24's. The 6 query-generation prompts carry the same schema lines as p24 (sorted-line diff = the date, QU-derived lines and conversation context only). 4 of 6 generated queries are byte-identical; the 2 that differ (rights t2, french t0) differ after a QU output change on a byte-identical QU prompt. Row counts, totals, digest sections, digest tables, chips and export files are unchanged except the french roster's columns.
- New path (B): **the map reader ran on 1 of 14 turns (rights t0) and its gate stayed closed. No QUESTION ANALYSIS block was rendered on any turn.** Part B holds no live evidence for the gate-open path. Follow-up (rights t1) kept probes + selection; both how-to turns ran neither (navigation target).
- The document scope works: no Chapter II chunk appears in any angle ranking, prompt, map or answer. `branch_coherence` did not need to fire (p24: 5 chunks dropped on t0, 1 on t1).

---

# 1. Per-turn grading

| Turn | p23 ans / act | p24 ans / act | p25 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|
| rights t0 | 0.80 / 0.85 | 0.70 / 0.80 | **0.80 / 0.85** | pass | Reader ran, gate closed, passages are plain search's: art. 1062-1069 (one merged block), 1078-1082, 1093-1098, 1070-1070.2, 1038-1040. **Every right is under Rights and every duty under Duties** (p24: three duties under Rights, one in both lists). Article numbers on every point. Art. 1065 correct ("acquire or lease"; p24 "sell"). Art. 1069 not misused. Platitude closer dropped by code. Standard's elements: free use yes; contribution yes; **contingency fund absent** (art. 1071-1072 not in the prompt); by-laws yes, as a duty; no interference with work yes. Against it: "right to participate in the general meetings ... and vote (§1094) [3]" is pinned on the article that takes the vote away, and the condition is not said; by-laws compliance pinned on §1066 (it is art. 1063); "§" instead of "art."; the register-consultation right in [4] unused. Action: two chips, both answerable from their excerpt, both threads the answer opened or left (revision; sale certificate). |
| rights t1 | 0.75 / 0.90 | 0.80 / 0.85 | **0.75 / 0.90** | pass | Probes + selection path (0 picked). New and correct: annual fixing by the board with both funds named (art. 1072) [2]; notice of amount and date (art. 1072) [2]; loss of vote after three months (art. 1094) [5]; assessment and collection rules (art. 1054) [4]. Against p24: **"you may lose your right to vote"** (art. 1094: "is deprived"); "after consultation with the general meeting" dropped. Still two bullets on the covered art. 1064 and an introduction; no fund point of its own (art. 1071 is in [2]). Closer dropped by code. Action: three chips, none already answered: special-contribution consultation (N), what the fund is for (N, the answer's gap), how relative value is determined (A, its excerpt only says the act "indicates how"). |
| rights t2 | 0.80 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass | "You have no unit linked to your account, so there are no records to show for your contribution this year. You may want to check the receivables report..." then the checked line **once** (`checked_line_echo` fired: the model copied the line, code removed the copy). `empty_result_check {scope: own, ownership_check {Unit, count 0}}`, 0 rows. No $0, no claim about the team. Query now takes **both ownership branches, OR parenthesised, year filter present** (latent gain, scores nothing with this user). New residual: the checked line reads "invoices, **unions** for your units" because the query joins Union for a name column. |
| resilience t0 | 0.90 / 0.95 | 0.90 / 0.95 | **0.90 / 0.95** | pass | Query, composition prompt, answer (sha 999b10f7) and metadata byte-identical to p24. |
| resilience t1 | 0.95 / 0.95 | 0.90 / 0.95 | **0.90 / 0.95** | pass | Query and composition prompt byte-identical to p24; same 100 of 523, header "Last Logged At", facts line unchanged. Lead is now "Here are the last login details for the team members:" (p24: "I can provide..."), not trimmed. Same score: the header is the charged defect. |
| resilience t2 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | `navigation_target: own_team`, probes skipped, no reader, no selection, no follow-up call, two calls. Composition prompt equal to p24's except one relevance figure (73% -> 75%). Four grounded steps, contact distinction cited [1], Team settings button. Roster survives (t3 exports 523). |
| resilience t3 | 0.95 / 0.85 | 0.95 / 0.85 | **0.95 / 0.85** | pass | `generation_skipped: true`, one call. Answer and file byte-identical to p24 (row hash 377b276c, 523 rows, 18 with a last login). |
| app-nav t0 | 0.85 / 0.30 | 0.90 / 1.00 | **0.90 / 1.00** | pass | QU prompt and composition prompt byte-identical to p24. Target set, button attached (`role: identity`), two help documents, no law passage, two calls. Four grounded steps, [1] cited, one pointer sentence. "you would refer to a different guide" is vaguer than p24's wording; no score effect. |
| cold-roster t0 | 0.90 / 0.85 | 0.90 / 0.85 | **0.90 / 0.85** | pass | Query, answer and file byte-identical to p24 (row hash d37d0394, 523 rows, User Name / Contact Email). |
| french t0 | 0.90 / 0.90 | 0.90 / 0.90 | **0.85 / 0.90** | pass | Entirely French, "523 membres.", truncation line, both chips in French. **Two columns only: Membre / Rôle** (p23, p24: Nom / Courriel / Rôle). The query is `RETURN u.name AS membre, tr.role AS rôle`. Meets the standard's "names AND roles"; scored as p22's two-column answer was. |
| french t1 | 0.95 / 0.85 | 0.95 / 0.85 | **0.95 / 0.85** | pass | `generation_skipped: true`, one call. "Champs inclus : Membre, Rôle.", 523 rows, French title and copy. Same members and columns as its own t0; the missing e-mail column is charged at t0. |
| digest t0 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Digest route, composition prompt and metadata byte-identical to p24: nine sources, 0 failed, 0 skipped, eight sections, totals 130 / 75 / 189 / 13 / 36 / 8, bills $11,493.17. 53 table lines identical (hash a8ab247e). 16 chips unchanged. Framing sentences reworded by the composer; two are wordier ("..., indicating many invoices past due"; "..., with history provided instead"). |
| digest t1 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Query, enrichment query, row and composition prompt identical; no digest block. Record block unchanged. Lead now paraphrases the name in English ("the annual general assembly for SDC 1240 SAINT-SÉBASTIEN"); the block carries the exact name. |
| digest t2 | 0.40 / 0.45 | 0.90 / 0.90 | **0.90 / 0.90** | pass | `digest_request: true` with `export_format: excel`; metadata byte-identical to p24: nine sources, `export_followup_offered: excel`, "Download Excel" chip, 17 chips. Same 53 table lines. Single closing line "The EXCEL file with everything shown is attached below." |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p23 | 0.846 | 13 / 1 / 0 | 0.825 |
| p24 | 0.882 | 14 / 0 / 0 | 0.900 |
| **p25** | **0.882** (0 vs p24) | **14 / 0 / 0** | **0.907** (+0.007 vs p24) |

- p25 answers, t0 to t13: 0.80, 0.75, 0.85, 0.90, 0.90, 0.90, 0.95, 0.90, 0.90, 0.85, 0.95, 0.90, 0.90, 0.90 (sum 12.35).
- p25 actions, t0 to t13: 0.85, 0.90, 0.90, 0.95, 0.95, 1.00, 0.85, 1.00, 0.85, 0.90, 0.85, 0.90, 0.90, 0.90 (sum 12.70).
- Against p24, answers: rights t0 +0.10; rights t1 -0.05, french t0 -0.05. Actions: rights t0 +0.05, rights t1 +0.05.
- All three answer moves are 0.05 to 0.10 on single turns in a single run. None is outside what p22 to p24 showed as run-to-run spread on the same turns (rights t0: 0.70, 0.80, 0.70; rights t1: 0.85, 0.75, 0.80).

---

# 2. Document turns

## Which path ran

| Turn | Path | Reader picks (role) | Gate | Final passages | Calls p24 -> p25 | Tokens p24 -> p25 | Seconds p24 -> p25 |
|---|---|---|---|---|---|---|---|
| rights t0 | **reader** (no probe writer, no selection) | chunk 58 art. 1062-1063 (general_rule), 59 art. 1064 (general_rule), 60 art. 1064.1-1065 (general_rule), 61 art. 1066 (condition_or_exception), 62 art. 1067 (consequence). `answerable: yes`, map 90 passages / 25,009 chars, not cut | **closed**, `opened_by: []` | [1] art. 1062-1069 (chunks 58 + 61 + 64 merged), [2] 1078-1082, [3] 1093-1098, [4] 1070-1070.2, [5] 1038-1040 | 5 -> 4 | 17,364 -> 19,329 (+1,965) | 18.7 -> 18.0 (file context 6.2 -> 4.8) |
| rights t1 | **probes + selection** (follow-up on cited passages) | none | none | [1] 1063-1066, [2] 1070.1-1074.1, [3] 1068.1-1070, [4] 1052-1056, [5] 1092-1097 | 5 -> 5 | 18,838 -> 19,024 (+186) | 18.4 -> 19.7 |
| resilience t2 | **none** (`search_probes {skipped: navigation_target}`) | none | none | [1] team-and-members.md, [2] contacts.md | 2 -> 2 | 8,680 -> 8,652 | 12.2 -> 11.2 |
| app-nav t0 | **none** (same) | none | none | [1] team-and-members.md, [2] contacts.md | 2 -> 2 | 7,937 -> 7,946 | 11.1 -> 15.8 |

- Rights t0, why the gate closed: all five picks are chunks the plain angles already ranked (first angle: 58, 59, 60, 61, 62). No governing rule that search had missed, so the passages and the prompt are plain search's. This is the documented expectation for the rights question.
- Rights t0, reader cost: 6,000 in / 81 out, 2.78 s. It replaced a probe call (850 tokens, 2.37 s in p24) and a selection call (3,973 tokens, 1.44 s): +1,258 tokens, -1 call, -1.0 s.
- Rights t0, what the reader did not name: the contingency fund (map lines BM, BO), the by-laws article 1054, the loss of vote, the register-consultation right. It read "rights and duties of a co-owner" as Division IV only. Two roles are off (art. 1066 is a duty, not an exception; art. 1067 is a right, not a consequence); roles are unused when the gate is closed.
- How-to turns: the reader is excluded by plan when QU returns a navigation target (`AiManager::mapTurnPlanned`). It was not needed: every angle ranks the right section first, and the answers are grounded without it.
- Rights t1: 4 probes written, one pair dropped as duplicate (0.821); selection pool 20, 0 picked, 3,997 tokens, 1.76 s.

## Rights t0, claim by claim

Composition prompt: system prompt byte-identical to p24 (sha 646c8720); user instructions identical except the passage count ("[1]...[5]" for "[1]...[6]"). What differs is the passage list.

| Claim in the answer | Passage | Verdict |
|---|---|---|
| Right to use and enjoy the private portion and the common areas, subject to the by-laws and the other co-owners' rights (§1063) [1] | art. 1063 | Supported. "Destination of the immovable" left out. |
| "You can dispose of your fraction as you see fit" (§1063) [1] | art. 1063 "has the disposal of his fraction" | Supported; "as you see fit" is the composer's. |
| Right to participate in general meetings and vote (§1094) [3] | art. 1094 (vote lost after three months unpaid), art. 1096 (decisions by majority of co-owners present or represented) | **Loosely supported, wrong pin.** No passage states the right; it follows from 1094 and 1096. The condition art. 1094 actually sets is not said. |
| Duty to contribute in proportion to the value of the fraction; restricted-use portions (§1064) [1] | art. 1064 | Supported. |
| Third-party liability insurance (§1064.1) [1] | art. 1064.1 | Supported. |
| "If you acquire or lease your fraction, you must notify the syndicate within 15 days, providing details about the lessee" (§1065) [1] | art. 1065 | Supported. Correct this run (p24: "sell"). |
| "You must comply with the by-laws ... and not interfere with necessary maintenance or repairs" (§1066) [1] | art. 1063 (by-laws), art. 1066 (work) | Supported; by-laws pinned on the wrong article; "even inside your private portion" left out. |
| Compensation for loss due to work (§1067) [1] | art. 1067 | Supported (the co-owners-who-did-the-work branch left out). |
| Revision of relative value and apportionment "if there is a significant discrepancy" (§1068) [1] | art. 1068 | Supported; five years and one-tenth left out (the chip picks this up). |

- Ungrounded claims: 0. Misapplied rules: 1 mild (§1094 as the source of the voting right). p24 had 2 (art. 1065 "sell"; art. 1069 as a general duty).
- Filing: correct. Rights 3, duties 4, two rights under "Additional Considerations".
- Art. 1063-1066: all four used. Art. 1067, 1068 used.
- Contingency fund (art. 1071-1072): **not in the prompt, not in the answer.** The chunk holding it (74) was the next candidate for a freed slot and was left out (`passage_overlap.unsupported: [74]`); the two freed slots went to art. 1070 and art. 1038-1040 (`filled: [69, 32]`, both `single_group`).
- By-laws: present as a duty and inside the first right. Art. 1054 itself is no longer in the prompt (in p24 it was the selection's one addition).
- Indivision: none. 0 Chapter II lines in the map, the prompt or the answer.
- Citations: [1] eight times, [3] once.

What moved and why:

- The instructions did not change, so nothing in the composition prompt's wording explains the better filing.
- The passages changed by design: no probe angles and no selection on a first turn, and Chapter II out of the rankings. Plain search admitted chunks 58, 61 and 64, which overlap, so Division IV reached the composer as **one block under its own heading** instead of two passages with others between them.
- p23 also had a merged Division IV block and also filed everything correctly; p22 and p24 had it split and misfiled. That is 2 of 2 against 0 of 2: suggestive, not established.
- The reader's picks contributed nothing to this answer (gate closed).
- Art. 1065 read correctly and article numbers written: composer variance on identical instructions.

## Rights t1, claim by claim

| Claim in the answer | Passage | Verdict |
|---|---|---|
| Contribution in proportion to the relative value of the fraction (art. 1064) [1] | art. 1064 | Supported. Covered in t0 (restatement). |
| Common expenses cover maintenance and operation of the common portions; restricted-use rule (art. 1064) [1] | art. 1064; the first half is art. 1072's wording | Supported. Second restated bullet. |
| Board fixes the annual contributions, which include operating costs and the contingency and self-insurance funds; you are notified of amount and due date (art. 1072) [2] | art. 1072 | Supported. **New.** "After consultation with the general meeting" left out. |
| "you **may** lose your right to vote" after more than three months unpaid, until paid (art. 1094) [5] | art. 1094 "is deprived" | Supported, **softened**. New to the user. |
| The declaration sets the rules on assessment and collection (art. 1054) [4] | art. 1052 + 1054 (the by-laws are part of the declaration) | Supported. **New.** |

- New material: yes, three of five points. Not a restatement of t0. The fund is named only inside the annual contribution; art. 1071 (what the fund is for, 0.5% rule) and art. 1072.1 (special contributions) are in [2] and unused; both became chips.
- Ungrounded claims: 0. Misapplied rules: 0.
- The 12 lines of art. 1070.1-1070.2 that entered passage [2] this run (register, French, maintenance log) did not leak into the answer.
- Trace finding, no effect this run: the deepening block tells the composer "ALREADY COVERED: art. 1062-1069, **1093-1098**" and "NEW: ... art. 1092 in [5]". The loss of vote was never told to the user; t0 cited [3] once, for the voting right, and the whole passage counts as covered. The composer used art. 1094 anyway. Had it followed the block, the standard's "loss of voting rights" would be gone.
- Design or variance: the composition prompt differs from p24's in three places (passage [2] 12 lines longer, the covered / new list, the previous-turn excerpts). The hedge and the dropped consultation are composer wording. Variance.

## How-to turns

| Check | resilience t2 | app-nav t0 |
|---|---|---|
| How-to document only | Yes: team-and-members.md, contacts.md | Yes, same two |
| Law passage | None ("CCQ" appears only in the system description) | None |
| Navigation target | `own_team` | `own_team` |
| Page button | Team settings (`role: identity`) | Team settings (`role: identity`) |
| Reader / probes / selection / follow-up call | none / skipped / none / none | none / skipped / none / none |
| Claims | Four steps, contact distinction, pointer to the Contacts guide: all in [1] | Four steps, contact distinction: all in [1] |
| Ungrounded | 0 | 0 |

- Resilience t2 closes with "You can access the Team Settings directly through the link below." The prompt asks for one short sentence naming the button, so this is as instructed.
- QU returned `intent: other` on resilience t2 (p24: `page_explanation`); the target was still set and nothing downstream changed.

## Every follow-up chip

| Turn | Chip | Source | Label | Answerable from its excerpt |
|---|---|---|---|---|
| rights t0 | When can I apply for a revision of common expenses? | art. 1068 (tier 1) | **N** | Yes (one-tenth; five years is in the passage) |
| rights t0 | What must I provide when selling my fraction? | art. 1068.1 (tier 1) | **N** | Yes (the syndicate's certificate) |
| rights t1 | Does the board need to consult co-owners for special contributions? | art. 1072.1 (tier 1) | **N** | Yes |
| rights t1 | What is the contingency fund used for in a condominium? | art. 1071 (tier 2) | **N** | Yes |
| rights t1 | How is the relative value of each fraction determined? | art. 1053 (tier 2) | **A** | Thinly: the excerpt says the act "indicates how" |
| digest t0 | Go through Open tasks (most urgent first) | section | N | click not exercised |
| digest t0 | Go through Overdue receivables | section | N | click not exercised |
| digest t0 | Go through Units with expired insurance | section | A | click not exercised |
| digest t2 | the same three | section | A, A, A (the ask was a file) | click not exercised |

- Totals: 11 chips; 6 N, 5 A, **0 O** (p24: 3 N, 8 A, 0 O). Knowledge turns: 4 N, 1 A (p24: 1 N, 4 A).
- Declined excerpts: art. 1062 and 1078 on t0, art. 1092 on t1 (all off the thread; right to decline).
- The t0 excerpts come from the merged Division IV block (tier 1 twice); in p24 the tier-1 excerpt was art. 1066's lessee paragraph.

## Lead cuts, closing drops, echo drops

| Turn | What code removed | Rule | Verdict |
|---|---|---|---|
| resilience t0 | "Here are the members of your team along with their roles:" | `restatement` | **GOOD** |
| french t0 | "Voici la liste des membres de votre équipe avec leurs rôles :" | `restatement` | **GOOD** |
| rights t0 | "These rights and duties are designed to ensure the smooth operation..." | `closing_dropped` | **GOOD** (p24 shipped the same kind of sentence) |
| rights t1 | "Understanding these points will help you fulfill your responsibilities..." | `closing_dropped` | **GOOD** |
| rights t2 | the model's copy of the "Checked:" line | `checked_line_echo` | **GOOD**, first live evidence of this removal |
| resilience t1 | nothing | n/a | "Here are the last login details for the team members:" stayed, although t0's twin sentence was cut. Cosmetic. |
| all | no `label_echo_dropped` | n/a | n/a |

Totals: 2 lead cuts, 2 closing drops, 1 echo removal, all GOOD. 0 HARMFUL.

## Verification items

| Item | Result | Evidence |
|---|---|---|
| Citations on gated answers | No answer was composed with the gate open | rights t0 (closed) cites [1], [3]; rights t1 cites [1], [2], [4], [5]; both how-to turns cite [1]. |
| Rights t2 query | `MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL MATCH (n:Invoice)-[:BELONGS_TO_UNION]->(un:Union) WHERE ((n.customer_type = 'unit' AND n.customer_id = u.id) OR (n.customer_type = 'contact' AND n.customer_id = c.id)) AND n.budget_id IS NOT NULL AND substring(n.invoiced_at, 0, 4) = '2026' RETURN n.invoice_number AS invoice_number, n.due_amount AS amount, un.name AS union_name, n.id AS invoice_id LIMIT 100` | |
| ... year filter | **Yes** | `substring(n.invoiced_at, 0, 4) = '2026'` |
| ... OR parenthesised | **Yes** | `((... ) OR (...)) AND ...`; `precedence_violations: []`, `repair_attempts: 0` |
| ... both ownership branches | **Yes** (p24: unit branch only) | |
| ... Checked line | Once: "Checked: invoices, unions for your units — nothing matched your question." | `checked_kinds: [invoices, unions]`; `checked_line_echo` holds the removed copy. |
| Digest t0 / t1 / t2 routes | digest / data, single record / digest with export | as p24 |
| Export follow-ups skip generation | **Both skipped** | resilience t3 and french t1: `recorded_result_export {generation_skipped: true, recorded_rows: 100}`, then `export {previous_result, rows: 523}`, one call each. |
| Security | **Nothing wrong** | `safe_stop: false` on all 6 executions; `security_filter_applied: true` on the 6 queries and the enrichment query; no SecurityException text in any row. |
| Admission | **Nothing wrong** | No `admission` stage, no busy answer, `error: ''` and `errors: []` on all 14 rows. |
| Harness gates on app-nav and digest t2 | Still none | `gates: []` on both. |

---

# 3. Data, digest and export turns vs p24

| Turn | Route | Generated query | Rows / total | Columns | Facts line | Sections / chips | Difference and cause |
|---|---|---|---|---|---|---|---|
| rights t2 | data, empty result (same) | **Different** | 0 / n.a. (same) | invoice number, amount, **union name** (p24: invoiced at) | n.a. | Receivables report (same) | QU returned the same three entity types in another order (Invoice, Fund, Union) on a prompt that differs only in the conversation summary; the Union card and its scope line moved down in the generation prompt; the generator wrote the declared two-branch ownership path and joined Union. Checked line gained "unions". Schema lines identical. **QU and generator variance.** |
| resilience t0 | data | Identical | 100 / 523 | Name / Email / Role | `count` | Team settings + Download Excel | None (answer and metadata byte-identical). |
| resilience t1 | data | Identical | 100 / 523 | + Last Logged At | "Last Logged At recorded for 2 of the 25 shown." | same two | Lead sentence only (composer, identical prompt). `lead_trimmed` absent because the composer wrote one sentence. |
| resilience t3 | export, recorded | none (skipped) | 523 | Name, Email, Role, Last Logged At | n.a. | Download Excel | None (file row hash equal). |
| cold-roster t0 | export | Identical | 100 / 523 -> file 523 | User Name / Contact Email | n.a. | Download Excel | None (file row hash equal). QU concept "info" for "information": no effect. |
| french t0 | data | **Different** | 100 / 523 | **Membre / Rôle** (p24: Nom / Courriel / Rôle) | `count` | Réglages de l'équipe + Télécharger Excel | QU returned `property_concepts: [membre, rôle]` (p23, p24: plural) on a byte-identical prompt. The generation prompt differs from p24's in exactly those two lines and the date. The generator returned the two requested attributes under the user's words (against the prompt's own alias rule) and dropped e-mail. **QU then generator variance.** `pii_masked_values` 10 -> 1 follows. |
| french t1 | export, recorded | none (skipped) | 523 | Membre, Rôle | n.a. | Télécharger Excel | Inherits t0's two columns. |
| digest t0 | digest | none | 37 rows, nine sources | 53 table lines identical | per-section totals identical | eight sections, 16 chips | Framing sentences only (composer, byte-identical prompt). |
| digest t1 | data, single record | Identical (and enrichment query) | 1 | record block identical | n.a. | one record chip | Lead sentence only. QU `follow_up_reference: true` (p24 false): no effect. |
| digest t2 | digest + export | none | 37 rows, nine sources | 53 table lines identical | identical | eight sections, 17 chips incl. Download Excel | Framing sentences only. |

- Generated queries: 6. Byte-identical to p24: 4. Different: 2, both downstream of a QU content change, neither touching a schema line.
- Row counts and totals on every data turn: 0, 523, 523, 523, 523, 1, as p24.
- Digest metadata (sources, counts, fallbacks, chips) byte-identical on both digest turns.
- QU system prompt byte-identical to p24 on every first turn (sha efbbc495); QU prompts on later turns differ only in the conversation summary.
- Nothing in these turns points at the removed code, the removed config lines, the reranker, the fuser or the deleted labels.

---

# 4. Regressions and open items, with traced cause

1. **French t0: e-mail column gone (-0.05 answer); the t1 file has two columns.**
   - `property_concepts` went from plural to singular on an identical QU prompt.
   - The REQUESTED ATTRIBUTES block then listed "membre" and "rôle"; the generator aliased with those words and returned nothing else.
   - The prompt's rule "alias each returned column after the PROPERTY it holds, never after the word the user used" was not followed; no code checks it.
   - Not caused by the removals: the schema lines of the prompt are p24's.
2. **Rights t1: vote loss hedged, consultation dropped (-0.05 answer). Composer variance.**
   - Passage [5] with art. 1094 and passage [2] with art. 1072 are in the prompt as in p24.
3. **Rights t1, latent: the deepening block marks art. 1093-1098 as already covered.**
   - Cause: t0's prompt now holds the general-meeting passage (plain search without probes), t0 cited it once, and coverage is counted per cited passage, not per article used.
   - No effect this run (the composer used art. 1094 regardless). A composer that obeys the block loses the standard's non-payment consequence.
4. **Rights t1: passage [2] grew by art. 1070.1-1070.2 (+166 composition input tokens, no effect on the answer).**
   - `passage_overlap.merged: [[74, 71]]`. With Chapter II out of the rankings, chunk 72 took the place chunk 11 held in p24 in three of five angles (fifth to fourth in two, into the top five in one), was admitted, and its window joined the fund passage. Probable cause: side effect of the document scope.
5. **Rights t0: contingency fund still absent (p24 defect #2, unchanged; 0.10 answer at stake).**
   - p24's lever was the selection call (one addition, spent on art. 1054). On a first turn that call no longer runs.
   - The reader is now the only step that could bring the fund in, and it picked Division IV only.
   - The fund chunk was first in line for a freed slot and got none; art. 1038-1040 (how a co-ownership is established) holds a slot the answer does not use.
   - Art. 1054 left the prompt with the selection call. No score effect: by-laws are covered from art. 1063.
6. **Checked line wording: "invoices, unions for your units".** The line lists every label the query touched; a label joined only for a display column is not something that was checked. Cosmetic.
7. **Gate-open path not exercised.** 1 reader call in 14 turns, gate closed, no analysis block. Part B cannot say anything about the gated composition.
8. **Tokens +2,010 (+1.2%), mean turn +1.3 s.** Section 5.

Not regressions:

- Rights t2's query is better than p24's (both branches, parenthesised OR, year filter). Variance; no code enforces it.
- Digest framing sentences and the digest t1 lead: composer wording on byte-identical prompts.

Improved against p24's defect list:

- #1 overview misfiling: correct this run (see the merged-passage note in section 2; not proven structural).
- #5 own-invoice query: both branches this run (variance).
- #6 thin law chips: 4 N of 5 (p24: 1 N of 5).
- #9 platitude closer on rights t0: dropped by code.
- #11 `checked_line_echo`: now seen live once.

Unchanged: #3 "Last Logged At" header, #7 no harness gate on app-nav or digest t2, #12 carried cosmetics ("(that's you)" on two cells, "mon équipe" title, "EXCEL" in capitals, `t.id = 3` and no ORDER BY in roster queries, "rows" in the resilience t3 export line).

---

# 5. Tokens, calls and latency vs p24

| Stage | p24 input / output (calls) | p25 input / output (calls) | Delta (in + out) |
|---|---|---|---|
| understanding | 72,624 / 1,885 (14) | 72,607 / 1,873 (14) | -29 |
| search_probes | 1,357 / 309 (2) | 677 / 136 (1) | -853 (rights t0 has no probe call) |
| passage_selection | 7,865 / 12 (2) | 3,992 / 5 (1) | -3,880 (rights t0 has no selection call) |
| document_map_reader | none | **6,000 / 81 (1)** | **+6,081** (rights t0) |
| query_generation | 41,755 / 644 (6) | 41,751 / 693 (6) | +45 |
| composition | 38,714 / 1,684 (11) | 39,370 / 1,628 (11) | +600 (rights t0 +682, rights t1 +80, french t0 -182) |
| follow_ups (gpt-4o) | 2,344 / 172 (2) | 2,398 / 164 (2) | +46 |
| **Total** | **169,365** (37 calls) | **171,375** (36 calls) | **+2,010 (+1.2%), -1 call** |

- By turn vs p24: rights t0 +1,965, rights t1 +186, rights t2 +70, french t0 -193; the other ten within 40 tokens.
- Rights t0 alone: reader 6,081 against probes 850 + selection 3,973 (+1,258); composition +682 on a longer passage list.
- First chunk (rows 0-6) 98,144 / 21 calls (p24 95,977 / 22); second chunk 73,231 / 15 calls (p24 73,388 / 15).

| Stage latency | p24 mean / median | p25 mean / median |
|---|---|---|
| understanding (14) | 4.8 s / 4.9 s (max 5.6) | **5.7 s / 5.5 s (max 8.0)** |
| file_context (4 knowledge turns) | 6.2, 6.4, 2.6, 3.1 s | 4.8, 6.7, 2.7, 4.8 s |
| of which probe call | 2.4 s, 2.3 s | none, 2.7 s |
| of which passage selection | 1.4 s, 2.0 s | none, 1.8 s |
| of which map reader | none | 2.8 s (rights t0) |
| query_generation (6) | 2.0 s / 2.0 s | 2.5 s / 2.0 s (rights t2 4.7 s) |
| execution (6) | 0.23 s / 0.20 s | 0.53 s / 0.32 s (rights t2 1.8 s) |
| empty_result_check | 0.02 s | 0.03 s |
| record_enrichment | 0.03 s | 0.09 s |
| recorded_result_export (2) | 0 s | 0 s |
| composition (14 rows) | 2.3 s / 1.6 s | 2.4 s / 1.8 s |
| follow_ups (2) | 1.9 s | 2.0 s |
| digest (2) | 0.6 s | 0.6 s |
| **pipeline** | **9.8 s / 9.0 s** (max 18.7) | **11.1 s / 9.7 s** (max 19.7) |

- Per turn, p24 -> p25 (s): 18.7 -> 18.0, 18.4 -> 19.7, 9.1 -> 13.9, 8.3 -> 9.5, 8.3 -> 9.4, 12.2 -> 11.2, 3.8 -> 5.3, 11.1 -> 15.8, 7.1 -> 10.1, 8.5 -> 9.4, 4.5 -> 5.4, 9.2 -> 8.4, 8.9 -> 9.9, 9.2 -> 9.4.
- The mean turn is 1.3 s slower. 0.9 s of it is the QU call, on prompts that are byte-identical or differ by a summary line: API latency at the hour of the run, not a code effect.
- Rights t2 +4.8 s: generation 2.4 -> 4.7 s and execution 0.5 -> 1.8 s (the two-branch query with the Union join), QU +0.9 s.
- App-nav +4.7 s: QU +3.0 s, file context 3.1 -> 4.8 s with no LLM call in either run (embedding and search time; the trace does not split it).
- The one turn the new path touched is 0.7 s faster: file context 6.2 -> 4.8 s, one call in place of two.
