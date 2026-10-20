# Grade: p16-1001-B (Part B) vs p14

- Run: `CondoEdge/storage/eval/p16-1001-B.json`, generated 2026-10-06T14:30. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3, owns no unit). Every row `verdict_label: ok`, `void: false`, `infra: false`, all gates passed.
- Rubric and strictness are the same as p14. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1: right chips, nothing misleading, no invented links.
- Same ai package sha as p14 (badd2f1, dirty tree 504 -> 530 files). Host config sha changed (e3b8b25 -> 09c0aa9). Models unchanged (QU and composition gpt-4o-mini, query generation gpt-4o).
- **Harness note:** the file holds 14 rows, but its top-level counters describe only the first chunk: `meta.suite = storage/eval/chunks/suite-partB-1.json`, `question_count 7`, `conversation_count 2`, `label_counts {ok: 7}`, `usage_total 92,778 / 21 calls`, `conversations` lists 2 of 6. All token and latency figures below are summed from the 14 rows, not from `usage_total`.

---

# Part 1 — Grading

## Per-turn table

| Turn | p14 ans / act | p16 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|
| rights t0 | 0.45 / 0.70 | **0.80** / 0.70 | pass | Retrieval is now chunks 58 (art. 1062-1065), 61 (1064-1068) and 8 (1015-1018, indivision). The answer uses only [1] and [2]; `referenced_files` holds exactly those two, so there is no indivision contamination (p14 had four contaminated items). Covered: free use within limits and by-laws (1063), contribution (1064), insurance (1064.1), 15-day notice (1065), non-interference with work (1066), indemnity (1067), court revision (1068). Every citation resolves to the passage that contains the article. Missing: **the contingency fund** (art. 1071-1072 not retrieved). Seven items plus a platitude closer ("help maintain the integrity and functionality of the condominium community"). No chips. |
| rights t1 | 0.45 / 0.70 | **0.40** / 0.70 | partial | Three accurate bullets from art. 1064 (proportional to relative value, restricted-use portions, major-repair apportionment). No t0 items re-served (p14 re-served two). But: **no contingency fund** (p14 had it from art. 1072), **no non-payment consequences**, and the "Liability for Common Charges" bullet cites [2] = art. 1019, an **indivision** article ("…related to the undivided property"; the answer drops "undivided"). Invented examples: "shared hallways, gardens, or amenities that are not accessible to all". "Size or value of your unit" persists. One of the three specifics the standard names is delivered. |
| rights t2 | 0.40 / 0.50 | **0.40** / 0.50 | partial | Same as p14. The Cypher tests ownership (`Contact {user_id: 13}` -> ContactUnit -> Unit, then 2026 invoices) and returns 0 rows. Answer: "I checked the records … invoices and union records linked to you. Unfortunately, there were no matching records found for your contribution this year." The scope guard holds (no team or $0 claim). Still no "you own no unit" statement, no deepening offer, no chip. |
| resilience t0 | 0.75 / 0.90 | **0.75** / 0.90 | pass | Identical Cypher and rows to p14. 523 disclosed, "Showing 25 of 523 rows — ask for the full list or an export", Team settings chip. Raw enum roles (contact / manager). Closer "If you need further details or actions regarding your team, just let me know!" breaks the guideline in the same prompt. 24 of the 25 shown rows are resident "contact" accounts; no staff row is visible. |
| resilience t1 | 0.85 / 0.75 | **0.85** / 0.75 | pass | Identical to p14: same Cypher plus `u.last_logged_at AS last_login`, 523, blanks for null logins, no closer. No chip (QU `entity_types []` on the follow-up). |
| resilience t2 | 0.85 / 0.40 | **0.85** / **1.00** | pass | **Navigation fix verified:** QU `navigation_target: own_team`; Team settings chip attached. Steps grounded in team-and-members.md, cited [2] (resolves to that file). The roster survives (t3 exports 523 rows). Cost: the first draft had no citation (`violations_first: ["citation_missing"]`, retried, recovered), so composition ran twice (11,355 input tokens, 5.8 s). Three of the five passages in the prompt are CCQ law; see Regressions #3. |
| resilience t3 | 0.85 / 0.85 | **0.85** / 0.85 | pass | Identical to p14: `previous_result`, 523 rows, Member Name / Role / Last Login, `export_source_complete: true`, title "Team Members Last Login". Query generation still runs for an empty Cypher (7,912 input tokens, 1.8 s). |
| app-nav t0 | 0.90 / 1.00 | **0.90** / 1.00 | pass | `navigation_target: own_team` (p14 null), `entity_types ["Team"]`, chip attached. Grounded steps cited [1]. "You can access the Team Settings directly using the button below" is now allowed by the guideline ("When one of them answers a WHERE/HOW question, say so in one short sentence by its name"). Four of five passages in the prompt are irrelevant (three CCQ). |
| cold-roster t0 | 0.85 / 0.85 | **0.85** / 0.85 | pass | `MATCH (t:Team {id: 3}) OPTIONAL MATCH (u:User) WHERE u.current_team_id = t.id RETURN u.name AS user_name, u.email AS email`. 523 rows exported, `empty_columns: []`, headers User Name / Email (the alias now follows the property; p14 had `contact_email`). The answer still does not say how many members the file holds. `t.id = 3` is hard-coded. |
| french t0 | 0.80 / 0.85 | **0.75** / 0.85 | pass | Fully French, 523 disclosed ("Affichage de 25 lignes sur 523"), "votre équipe", chip "Réglages de l'équipe". Weaker than p14: the shown sample no longer contains Julie ("lead", "c'est vous") or any staff role — 24 of 25 rows are "contact" — and the team name is gone. English enum values in a French answer; closer "n'hésitez pas à demander". |
| french t1 | 0.85 / 0.85 | **0.85** / 0.85 | pass | 523 rows, "En date du", "Télécharger Excel", "Champs inclus : Membre, Rôle". The title is now "Membres de mon équipe et rôles" (t0's subject label): more descriptive than p14's, but it carries the possessive "mon" into a shareable file. Query generation still runs for an empty Cypher (5,599 input tokens). |
| digest t0 | 0.85 / 0.85 | **0.90** / 0.85 | pass | **Label fix verified:** "Open tasks (most urgent first)", framing "There are 130 open tasks in total" (the "open and urgent" overclaim is gone). All sections correct against the standard: 5 of 130, 4 bills totalling $11,493.17, 5 of 75 (the five oldest invoices match), none scheduled plus three past meetings with union and place, 5 of 189, 5 of 13, 5 of 36, 5 of 8, one "Nothing pending in:" line, one closer. `sources_failed: []`. Remaining: the bills total is stated twice, the lead is bland, and "Most recent meetings show no scheduled items" is awkward. |
| digest t1 | 0.55 / 0.90 | **0.55** / 0.90 | partial | Normal pipeline, no digest block. Correct meeting, date and tense; Meeting 140 chip. **The union is now in the data and the answer still omits it:** the Cypher returns `u.name AS union_name` and the prompt's FACTS list "Union Name: SDC du 1240 St-Sébastien". Place is not projected. The standard requires name + date + union. |
| digest t2 | 0.75 / 0.90 | **0.90** / 0.90 | pass | **All three digest fixes verified.** One closing line: "The EXCEL file with everything shown is attached below." No second offer. No "— " prefix on the framings. New label. `export_followup_offered: excel`, `failed_sources: []`, `export_excel` chip. Remaining: "EXCEL" in capitals (copied from the prompt NOTE), "with 5 shown" duplicates the code's "Showing 5 of N" line, bills total stated twice. |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p14 | 0.725 | 10 / 4 / 0 | 0.786 |
| **p16** | **0.757** (+0.032) | **11 / 3 / 0** | **0.829** (+0.043) |

- p16 answers, t0 to t13: 0.80, 0.40, 0.40, 0.75, 0.85, 0.85, 0.85, 0.90, 0.85, 0.75, 0.85, 0.90, 0.55, 0.90.
- p16 actions, t0 to t13: 0.70, 0.70, 0.50, 0.90, 0.75, 1.00, 0.85, 1.00, 0.85, 0.85, 0.85, 0.85, 0.90, 0.90.
- Answer gains: rights t0 +0.35, digest t2 +0.15, digest t0 +0.05. Answer losses: rights t1 -0.05, french t0 -0.05.
- Action gain: resilience t2 +0.60. No action loss.
- Rights t0 moves from partial to pass. The three remaining partials are rights t1, rights t2 and digest t1.

## Change verification

| Change | Exercised on | Result |
|---|---|---|
| Synonym normalization | all 14 QU prompts | **Verified in the prompt.** 73 entity lines, 64 with an `aka` list, at most 12 synonyms (Union, User); nine detail entities carry none. No routing error on any turn. QU input falls about 610 tokens per turn. |
| Probe key docs removed from QU | all 14 | **Verified.** The `corpus_translations` and `register_expansions` instruction lines are gone from the QU prompt (25,374 -> 22,422 chars). |
| DocumentProbeWriter (`search_probes` stage) | rights t0, rights t1, resilience t2, app-nav | **Runs as designed; mixed effect.** Four probes per turn, 2.1-2.7 s, about 830 tokens. Helps rights t0. Hurts rights t1 (Regressions #1). Pollutes both how-to turns with CCQ passages (Regressions #3). |
| Citation normalization "(art. 1063) [1]" | rights t0, t1 | **Not exercised.** Neither answer contains an annotated citation; both use bare [1] / [2]. All cited numbers resolve to `referenced_files`, and uncited passages are dropped from it. `validation.patched` is empty on both. |
| QU navigation rule | resilience t2, app-nav, all data turns | **Verified.** `own_team` on both "Where can I add new members?" turns (p14: null on both). Null on every roster, export and digest turn. |
| Digest: single closing line with a file, no "— " prefix, new label | digest t0, t2 | **Verified** (see table). |
| Union per-record chips removed | all 14 | **Verified by absence.** No invocation has `entity_type: Union`. Record chips are Bill, Invoice, Meeting and Unit only. |
| Five newly placed generic actions | — | **Not exercised.** The set of chip keys is identical to p14's. |
| Rate limiter (one charge per turn) | all 14 | **No failure observed.** Every execution succeeded and both digest runs report `sources_failed: []`. The trace carries no limiter field, so the charge count itself cannot be confirmed. |

## Regressions vs p14 (traced cause)

1. **Rights t1 lost the contingency-fund passage (-0.05). Cause: code change, not variance.**
   - QU output is effectively the same in both runs (`search_query` "duty to contribute to common expenses"; identical `clarified_question`).
   - p14 angles: search query, question, clarified question, joined tags. Retrieved chunks 59 (1063-1066), **74 (1071-1074.1)**, 66 (1068.1-1070).
   - p16 angles: search query, question, and four probes that all restate art. 1064 ("Each co-owner has the obligation to contribute to common expenses in proportion to the relative value of their private portions", "Common expenses are the responsibility of each co-owner, based on the relative value…", and their French twins). Retrieved chunks 59, **11 (1017-1022, indivision)**, 66.
   - `AiManager.php:1041-1054` drops the clarified-question and tags angles whenever probes were written. Its comment says those two searches "changed none of the retrieved passages"; this turn contradicts that.
   - Four of six angles are now paraphrases of one article, so the per-file rank fusion favours whatever resembles art. 1064. Art. 1019 (indivision, "liable proportionately to their shares for the costs of administration and the other common charges") is that neighbour.
2. **French t0 shows a less useful sample (-0.05). Cause: generator variance.** p14 wrote `WHERE t.id IN $ai_team_ids`, p16 wrote `WHERE t.id = 3`; neither has an ORDER BY, and the two forms return rows in a different order. p14 happened to start with Julie (lead) and the staff accounts.
3. **How-to turns now carry CCQ passages (no score change, real cost). Cause: code change.**
   - The probe writer has one register, the CCQ one, and applies it to app how-to questions. Probes written for "Where can I add new members?": "The declaration of co-ownership provides the option to add new members to the syndicate of co-owners"; "To add new members to my team, one must refer to the board of directors of the syndicate of co-owners."
   - Resilience t2: passages went from 2 to 5 (three CCQ). CCQ chunk 88 (art. 1081-1083) ranked first at 0.699, ahead of team-and-members.md at 0.698 (p14: 0.758, alone at the top). The composition prompt grew from 8.4k to 19.2k chars.
   - That turn's first draft came back uncited and was regenerated: 17,775 tokens against 8,874 in p14.
4. **Knowledge turns are 4-6 s slower.** `file_context` is 7.1-8.9 s on the four knowledge turns (p14: 4.6-5.2 s, excluding its cold-start outlier). The probe call accounts for 2.1-2.7 s; six angles instead of four account for the rest.
5. **Cosmetic:** "EXCEL" in capitals (digest t2); "mon équipe" in the French export title.

## Tokens and latency vs p14

| Stage | p14 input / output (calls) | p16 input / output (calls) | Delta input |
|---|---|---|---|
| understanding | 77,609 / 2,742 (14) | 69,089 / 2,753 (14) | -8,520 (-11%) |
| search_probes | — | 2,687 / 557 (4) | +2,687 |
| query_generation | 52,660 / 737 (8) | 53,506 / 742 (8) | +846 |
| composition | 32,773 / 1,478 (11) | 41,772 / 1,702 (12) | +8,999 |
| **Total** | **167,999** (33 calls) | **172,808** (38 calls) | **+4,809 (+2.9%)** |

- The QU saving (about 8.5k) is cancelled by composition. Of the +9.0k, resilience t2 alone is +8.9k (retry plus CCQ passages) and app-nav is +1.5k.
- Still wasted: query generation on the two export follow-ups that run no query (7,912 + 5,599 input tokens), and a 37-row previous-result sample in the digest t1 composition prompt (5,010 input tokens for a one-row answer).

| Stage latency | p14 mean / median | p16 mean / median |
|---|---|---|
| understanding | 9.4 s / 5.0 s | 6.1 s / 6.0 s |
| file_context (4 knowledge turns) | 24.6, 5.1, 4.6, 5.2 s | 8.9, 8.2, 7.1, 7.3 s |
| query_generation | 2.0 s / 1.9 s | 2.7 s / 2.5 s |
| execution | 1.8 s / 0.6 s | 0.7 s / 0.6 s |
| composition | 2.0 s / 1.7 s | 2.4 s / 1.9 s |
| digest | 1.3 s | 0.9 s |
| **pipeline** | **16.6 s / 10.7 s** | **12.9 s / 11.7 s** |

- The mean improves only because p14's 81 s cold first turn is gone (max is now 20.3 s). The median is 1.0 s worse.
- QU is 1.0 s slower at the median despite the smaller prompt, with no fallback recorded; that looks like provider latency.

---

# Part 2 — Defect ranking

## Where each sub-0.85 turn loses its points

### Rights t2 — answer 0.40, action 0.50

- **Standard demands:** state that she owns no unit, so nothing is billed to her personally (not a $0 balance); say what was checked; one deepening offer (a specific unit, the portfolio receivables, or a statement link).
- **Answer lacks:** the "you own no unit" statement, any offer, any chip.
- **(a) Understanding: correct.** `data_query`, `is_possessive: true`, entity types Invoice and Union. Selection added the ownership tier (`Contact`, `ContactUnit`, `Unit`: "ownership").
- **(c) Query generation: correct shape.** `MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE … substring(i.invoiced_at, 0, 4) = '2026' AND i.status IN ['2', '3', '4'] RETURN i.invoice_number, i.due_amount AS contribution, …`. Execution: `row_count: 0`, `anchor_total: null`. Latent fault for a real owner: `status IN ['2','3','4']` plus `due_amount AS contribution` reports the open balance of unpaid invoices, not the year's contribution.
- **(e) Composition instruction — this is where it is lost.** The empty-result prompt (`ResponseGenerator.php:908-927`) says:
  - "WHAT WAS CHECKED: annual contribution amount for co-owner (record kinds: Invoice, Union) — within the user's OWN linked records."
  - "Do NOT end with a bare invitation like "feel free to ask" or an offer ("let me know", "would you like…")."
  - The checked scope is built from QU fields (`AiManager.php:2675-2677`: `searchQuery`, `entityTypes`, `isPossessive`), not from the executed query. Nothing tells the model that the first MATCH — the ownership path — is the part that matched nothing. The model cannot say "you own no unit" without inventing it.
  - The same prompt forbids the single offer the standard welcomes.
- **(f) Action attachment:** nothing attached. `my_statement` has no placement by design (host `config/ai.php:1106-1117`: for staff it opens the first unit of the current union). No other door exists for an empty own-data result.
- **Not variance.** Same outcome in p9, p11, p12, p13, p14 and p16.

### Rights t1 — answer 0.40, action 0.70

- **Standard demands:** proportion to relative value; the contingency fund; consequences of non-payment (loss of voting rights, legal recovery); no re-served overview.
- **Answer gets wrong or lacks:** no contingency fund, no consequences, one indivision article presented as a condo duty, invented examples.
- **(a) Understanding: correct** (`knowledge_query`; entity types even include `Fund`).
- **(b) Retrieval — main loss.**
  - Came back: chunk 59 (art. 1063, 1064, 1064.1, 1065, 1066), chunk 11 (art. 1017-1022, **Chapter II indivision**), chunk 66 (art. 1068.1, 1068.2, 1069, 1070).
  - Standard needs: art. 1064 (retrieved), **1071 and 1072** (contingency fund and annual contribution; chunk 74, retrieved in p14, lost here), **1072.1** (special contributions), **1094** (no vote after three months unpaid), **1086** (disqualified as director), 1069 (acquirer liable for arrears; retrieved as [3], unused).
  - Cause: Regressions #1. The probe prompt adds to it: "Both probes answer THIS question. Keep the specific thing it is about: do not widen it to its general topic and do not drift to neighbouring subjects" (`DocumentProbeWriter.php:219-220`). For a "tell me more" turn the neighbours are the answer.
  - Chunk 59 mostly duplicates what the turn already has: art. 1063 and 1066 are re-supplied under "Files Referenced in Previous Response". One of three slots brings nothing new.
- **(d) Present but ignored:** art. 1069 in [3] ("is bound to pay all common expenses due with respect to that fraction, with interest") is the only non-payment text in the prompt, and it is unused.
- **(e) Composition:** the passage header reads "§DIVISION II — RIGHTS AND OBLIGATIONS OF CO-OWNERS — art. 1017–1022". It is word-for-word the heading of the right division ("§DIVISION IV — RIGHTS AND OBLIGATIONS OF CO-OWNERS"). The chapter ("CHAPTER II — UNDIVIDED CO-OWNERSHIP") is not shown: `FileContextProvider.php:645` passes only `section_title`, the last element of `heading_path`.
- **Standard critique (rubric 7):** "legal recovery" has no article in the indexed corpus (no "legal hypothec" text in `CCQ_Co-ownership_EN.md`). Only art. 1094, 1086 and 1069 can ground the consequences. The standard should name those.

### Digest t1 — answer 0.55, action 0.90

- **Standard demands:** name + date + union, place if recorded.
- **Answer lacks:** the union (SDC du 1240 St-Sébastien) and the place (ZOOM).
- **(a) Understanding: correct** (`data_query`, Meeting, no digest). `property_concepts: ["date"]` only.
- **(c) Query generation: improved, incomplete.** `… RETURN m.name AS meeting_name, m.start_date AS date, u.name AS union_name, m.id AS meeting_id ORDER BY m.start_date DESC LIMIT 1`. The union is back (p14 dropped it). `m.place` is in the schema card ("place: Location or venue of the meeting") and is not projected.
- **(d) Present in the prompt and ignored — this is where the union is lost.** Prompt lines:
  - "FACTS (exact values from the data — copy them verbatim): - Meeting Name: Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN - Date: 2023-10-24 16:00 - Union Name: SDC du 1240 St-Sébastien"
  - "ANSWER FORMAT: state the values from FACTS in prose, copied verbatim — never recompute them. No table." (`DataAnswerPlan.php:803`)
  - The model stated two of three facts. The place was also available in the previous-result sample (`"place": "ZOOM"` on id 140) and went unused.
- **Model non-compliance with an explicit instruction.** Needs a guard, not wording.

### Resilience t0 — answer 0.75; French t0 — answer 0.75

- **Standard demands:** useful fields beyond the bare minimum, one concrete way to deepen, (French) a concrete French next step.
- **Answers lack:** a real closing door from the model, readable role labels, a sample that shows who runs the team.
- **(a), (c): correct.** `MATCH (t:Team) WHERE t.id = 3 MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) MATCH (u:User) WHERE tr.user_id = u.id RETURN u.name AS member_name, tr.role AS role LIMIT 100`. 523 rows, no ORDER BY.
- **(e) Composition instructions contradict each other:**
  - ResultsDataSection / `DataAnswerPlan.php:823`: "then the placeholder {{table}} … then at most one short closing next step."
  - `GuidelinesSection.php:199`: "Do NOT end with an offer or a question ("let me know", "would you like…", "feel free to ask")".
  - The model resolves this with the forbidden generic closer: "just let me know!" / "n'hésitez pas à demander". It did the same in p14 with other words. The only concrete door in the answer is the code-written "ask for the full list or an export".
- **Data shape:** role values are raw strings (contact, manager, lead, accountant, assistant) with no labels. The host description of TeamRole lists other names ("Owner, Manager, Editor, Basic, Scanner", `config/ai-entities/general.php:458`).
- **Unordered sample:** which 25 of 523 rows show depends on the predicate form the generator happens to write.

### Rights t0 — answer 0.80, action 0.70

- **Standard demands:** free use within limits; contribution to common expenses **and the contingency fund**; respecting the declaration and by-laws; access for work; not an exhaustive dump.
- **Answer lacks:** the contingency fund. It also ends on a platitude.
- **(b) Retrieval:** chunks 58, 61 and 8. Art. 1071-1072 are in chunk 74 (Division V), not retrieved. One of the three slots went to chunk 8, indivision art. 1015-1018 (score 0.69, higher than chunk 61's 0.60). The model ignored it this time. In p14 it built four items from it.
- The whole law is one file, and `max_chunks_per_file` is 3, so the entire CCQ competes for three slots on every law question.

### Action scores below 0.85

- **Rights t0, t1 (0.70):** no chip. The cited file has `can_download: false`, `download_url: null`, so no source link can attach.
- **Rights t2 (0.50):** above.
- **Resilience t1 (0.75):** the follow-up's QU has `entity_types: []`, so `ActionAttacher::identityChips()` (`ActionAttacher.php:372`, `mentions('Team') || navigationTarget === 'own_team'`) attaches nothing. The context carries `focused_entity: "Team"`, unused for attachment.

## Ranked defects (points lost x how deterministic the fix looks)

| # | Defect | Turns | Points at stake | Stage | Variance? | Fix determinism |
|---|---|---|---|---|---|---|
| 1 | Empty own-data result never names the empty ownership link | rights t2 | 0.50 answer + 0.35 action | (e) + (f) | No — six runs identical | High |
| 2 | Single-record answer drops a FACT the prompt lists | digest t1 | 0.30-0.35 | (d) | Model non-compliance | Very high (code guard) |
| 3 | Probes replace the clarified-question and tags angles; four of six angles restate one article | rights t1 | 0.25-0.30 | (b) | No — code change | High |
| 4 | Passage header hides the chapter; indivision reads as condo law | rights t1, rights t0 (p14: 4 items) | 0.10-0.20 now; larger when the model uses it | (b) + (e) | Use of the passage varies by run; the cause is constant | High to implement |
| 5 | Model writes a forbidden generic closer after the table | resilience t0, french t0 | 0.10 each | (e) | Yes — same prompt, different wording each run, always a violation | Very high (code owns the tail) |
| 6 | One CCQ register drives probes for app how-to questions | resilience t2, app-nav | 0 answer points; +8.9k tokens, +3.6 s, how-to doc 0.001 from losing rank 1 | (b) | No — code/config | High |
| 7 | Roster sample is unordered and roles are raw enum strings | resilience t0, french t0, cold-roster | 0.10-0.15 each | (c) + config | Order is generator variance | Medium |
| 8 | Follow-up turns lose the identity chip; deepening retrieval re-fetches what the turn already holds | resilience t1 (action 0.25); rights t1 | 0.25 action + part of #3 | (f), (b) | No | High |

Not ranked (small): digest bills total stated twice and "EXCEL" in capitals; export answers give no row count; query generation runs on export follow-ups with nothing to generate (13.5k tokens per run); `t.id = 3` hard-coded in four Cyphers.

## Proposed structural fixes for the top 8

1. **Anchor-emptiness probe on empty own-data results.**
   - Where: `AiManager.php` around line 2675 (the empty-result options) and `ResponseGenerator.php:912-927`.
   - When a possessive query returns 0 rows and the Cypher starts from the user's identity path (the selection already tags `Contact` / `ContactUnit` / `Unit` as "ownership"), run that first MATCH alone as a count through the same scoped executor (the `companionAnchorTotal` path at `AiManager.php:498` already does this for fan-outs).
   - If the count is 0, replace the QU-derived "WHAT WAS CHECKED" with a fact: "No unit is linked to this user's account, so nothing is billed to them personally. Say exactly that; it is not a $0 balance."
   - Build the checked list from the labels in the executed Cypher, not from QU entity types.
   - Attach one door for staff: the entity's report placement (Invoice -> `report_receivables`), and let the prompt name it as the single next step instead of forbidding every offer.
2. **Fact-coverage guard for single-record answers.**
   - Where: `AnswerValidator.php` (new violation next to `NUMBER_UNSUPPORTED`) and `DataAnswerPlan.php:801-805`.
   - In `MODE_SINGLE`, every FACTS value must appear verbatim in the answer. On a miss, patch deterministically by appending "Label: value" (the `patched` list exists for this) rather than regenerating.
   - Stronger variant: code renders the fact line and the model writes only the lead sentence.
   - For the place: single-record lookups should project the entity's display columns. The digest source for meetings already defines them (name, union, start date, place); reuse that set per entity instead of leaving it to the generator.
3. **Keep the clarified-question angle when probes are written.**
   - Where: `AiManager.php:1041-1054`.
   - Add `clarifiedQuestion` back unconditionally (embeddings are batched; the cost is one search). Drop only the tags angle.
   - De-weight the probes in the per-file fusion: the two languages of one probe should count as one vote, not two.
   - Add rights t1 to the probe writer's live test set; its current evidence (5 questions) missed this case.
4. **Show the full heading path on every passage.**
   - Where: `FileContextProvider.php:645`. Use `implode(' › ', $metadata['heading_path'])` for `locator['heading']`. `SemanticChunker.php:310` already stores the path.
   - The header then reads "CHAPTER II — UNDIVIDED CO-OWNERSHIP › DIVISION II — RIGHTS AND OBLIGATIONS OF CO-OWNERS". No word list, no corpus-specific rule.
   - Add one generic line to the knowledge guideline (`GuidelinesSection.php:59`): a passage applies only when its section path matches the situation asked about.
   - Follow-up: make `max_chunks_per_file` scale with file size, so a whole code in one file is not held to three passages.
5. **Code owns everything after `{{table}}`.**
   - Where: `DataAnswerPlan.php:823` and the table substitution step.
   - Remove "then at most one short closing next step" from the prompt, discard any model text after the placeholder, and append a code-built next step from real capabilities: the anchor entity's unprojected display properties (email, last login) plus export. That is the "one concrete way to deepen" the standard asks for, and it cannot drift.
6. **Per-corpus probe register.**
   - Where: host `config/ai.php:552-577` and `DocumentProbeWriter`.
   - Replace the single `corpus_register` with one entry per path group (`public/laws/**` with the CCQ register in fr and en; `docs/ai-help/**` with no register, or an app-guide one in en).
   - Search each probe only against its own group (payload filter on the source path), or skip the probe call when the base angles' top hit comes from a group without a register.
   - Removes the CCQ passages from how-to prompts and 2+ s from those turns.
   - Side note: the register lists "arrears", a word that does not occur in the English law file (it says "common expenses due" and "has not paid his share").
7. **Deterministic roster sample and role labels.**
   - Truncated lists need an order. Either the executor adds a stable default ORDER BY when the generator wrote none and the result hits the cap, or the answer gets a code-computed group summary for low-cardinality columns ("523 members: 1 lead, … contacts") from a companion count query.
   - Give `TeamRole.role` value labels in `config/ai-entities/general.php:403-458` using the enum notation the schema cards already support (`status:Integer{1=Initialized|2=Notices sent}`), with French labels, and correct the description's role names.
8. **Carry the conversation focus into attachment and retrieval on follow-ups.**
   - Attachment: in `ActionAttacher::identityChips()` (`ActionAttacher.php:372`), when `followUpReference` is true and QU listed no entity types, use the conversation's `focused_entity` for the `mentions()` test. Restores Team settings on resilience t1.
   - Retrieval: on a knowledge follow-up, exclude chunks already cited in the previous turn from the new passage slots (they are re-supplied under "Files Referenced in Previous Response" anyway). In rights t1 that frees the slot chunk 59 took.

## Defects that are pure model variance (need a guard, not a prompt tweak)

- **#5 generic closers:** same prompt in p14 and p16, different closing sentence each run, always against the guideline.
- **#2 dropped FACT:** explicit "copy them verbatim" instruction, one of three facts omitted.
- **Resilience t2 uncited first draft:** app-nav cited the same document on the first attempt in the same run; the validator caught it, at the price of a second call.
- **French t0 sample order and the `t.id = 3` literal:** the generator alternates between `t.id = 3` and `t.id IN $ai_team_ids` on the same prompt.
- **Whether the indivision passage is used:** p14 used it on t0, p16 ignored it on t0 and used it on t1. The cause (#4) is constant; the symptom is not.

Deterministic, not variance: #1 (six runs), #3 and #6 (code change), #8.
