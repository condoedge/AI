# Grade: p17-1001-B (Part B) vs p16 (and p14)

- Run: `CondoEdge/storage/eval/p17-1001-B.json`, generated 2026-10-06T16:57. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3 "Novo Gestion", owns no unit). 0 void, 0 infra. 13 rows `ok`, 1 row `gate-failed` (cold-roster-excel#0, `columns_include: email`).
- Rubric and strictness are the same as p14 / p16. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1: right chips, nothing misleading, no invented links.
- Same ai package sha (badd2f1), dirty tree 530 -> 545 files. Host config sha changed (09c0aa9 -> 29053c6d). Models unchanged (QU and composition gpt-4o-mini, query generation gpt-4o, embeddings text-embedding-3-small).
- **Harness note:** the top-level counters describe chunk 1 only (`question_count 7`, `label_counts {ok: 7}`, `usage_total 88,801 / 19 calls`). Everything below is summed from the 14 rows: 167,340 tokens, 35 LLM calls.

---

# 1. Per-turn grading

| Turn | p14 ans / act | p16 ans / act | p17 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|
| rights t0 | 0.45 / 0.70 | 0.80 / 0.70 | **0.70** / 0.70 | pass (borderline) | 5 passages now: chunks 59 (art. 1063-1066), 11 (1017-1022, **indivision**), 8 (1015-1018, **indivision**), 64 (1067-1069), 82 (1075.1-1079). Eight items, annotated citations "(art. 1063) [1]" all resolve. One item is indivision presented as condo law: "your creditors may seize it (art. 1015) [3]" — [3] is headed "CHAPTER II — UNDIVIDED CO-OWNERSHIP". **Contingency fund still missing** (art. 1071-1072 not in the final five). Platitude closer ("ensure a balanced and fair management of the condominium"). No chip. |
| rights t1 | 0.45 / 0.70 | 0.40 / 0.70 | **0.45** / 0.70 | partial | Retrieval fixed: chunk 74 (art. 1071-1074.1) is passage [2]. The answer uses it only for "the syndicate will notify each co-owner of the amount … and the due date" (last sentence of art. 1072). **The contingency fund is in the prompt and is not mentioned.** No non-payment consequences (art. 1094 / 1086 not retrieved; art. 1069 retrieved as [3], unused). Three of four bullets restate art. 1064. No indivision citation this time (chunk 11 is [4], uncited). Ends with "feel free to ask!" against the guideline. No chip. |
| rights t2 | 0.40 / 0.50 | 0.40 / 0.50 | **0.80** / **0.90** | pass | "You have no unit linked to your account, so there are no records of your annual contribution for this year. I checked for any invoices or union records … none matched your request. You may want to review the receivables report." Ownership fact stated, no team / $0 claim, what was checked is said, one door, **Receivables report chip**. Residual: "union records" (QU jargon), "none matched your request", the door sentence does not say the report is the portfolio she manages. |
| resilience t0 | 0.75 / 0.90 | 0.75 / 0.90 | **0.80** / 0.90 | pass | Name / Email / Role table, role labels ("Lead union manager", "Accountant", "Administrative assistant", "Unit Owner or Renter"), team named, Julie marked "(that's you)", staff rows first, 523 disclosed, no generic closer, ends at the code line "Showing 25 of 523 rows — ask for the full list or an export". New defects: a **10-bullet list duplicating the first 10 table rows** (prompt says "Do NOT list the rows"), "You can find the **complete** list of team members in the table below" (the table holds 25 of 523), "(that's you)" printed on both the name and the email cell. |
| resilience t1 | 0.85 / 0.75 | 0.85 / 0.75 | **0.85** / **0.90** | pass | Same query plus `u.last_logged_at AS last_login`; labels kept; blanks for null logins; no closer. **Team settings chip is back.** QU `is_possessive: false` (p16: true, a false positive). |
| resilience t2 | 0.85 / 0.40 | 0.85 / 1.00 | **0.90** / 1.00 | pass | `search_probes: {skipped: navigation_target}`; passages are team-and-members.md (0.698) and contacts.md (0.434), no CCQ; one composition call (3,026 tokens; p16 11,355 over two calls). Grounded steps, cited [1], chip named in one sentence. Roster survives (t3 exports 523 rows). |
| resilience t3 | 0.85 / 0.85 | 0.85 / 0.85 | **0.85** / 0.85 | pass | `export_source: previous_result`, 523 rows, Name / Email / Role / Last Login, title "Team members last login". The file carries raw role codes (lead, accountant, assistant, contact) while the screen showed labels. No row count in the answer. Query generation ran (7,490 tokens) and this time its query was also executed (100 rows, 118 ms) for nothing. |
| app-nav t0 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90** / 1.00 | pass | Same as resilience t2: no probes, how-to doc first (0.731), one call (2,592 tokens; p16 4,113), cited [1], Team settings chip. |
| cold-roster t0 | 0.85 / 0.85 | 0.85 / 0.85 | **0.80** / 0.85 | pass | `MATCH (u:User) WHERE u.current_team_id = 3 RETURN u.name AS name, u.email AS contact_info, u.id AS user_id`. 523 rows exported, emails present in every row under the header **"Contact Info"** (hence the harness gate failure on "email"). The user gets names + emails; the header is vaguer than the data, no roles, no row count in the answer, `3` hard-coded. |
| french t0 | 0.80 / 0.85 | 0.75 / 0.85 | **0.85** / 0.85 | pass | Fully French, French role labels (Gestionnaire en chef, Comptable, Adjoint.e administratif, Copropriétaire ou locataire), "votre équipe, Novo Gestion", "(c'est vous)", 523 disclosed, ends at "demandez la liste complète ou un export", chip "Réglages de l'équipe". Same 10-bullet row echo as resilience t0. |
| french t1 | 0.85 / 0.85 | 0.85 / 0.85 | **0.85** / 0.85 | pass | 523 rows, "En date du", "Télécharger Excel", "Champs inclus : Membre, Rôle". Title "Membres de mon équipe" (possessive in a shareable file). File roles are raw English codes although the screen showed French labels. QU `is_possessive: false` (p16: true). Query generation still runs (5,779 tokens) for an empty Cypher. |
| digest t0 | 0.85 / 0.85 | 0.90 / 0.85 | **0.75** / 0.85 | pass | Structure, rows, totals, single "Nothing pending in:" line, single closer, `sources_failed: []` all correct. **Five framing sentences misstate the counts:** "5 tasks are currently open out of a total of 130", "5 overdue receivables are noted from a total of 75", "5 units have expired insurance out of 189 total", "5 contact reviews are pending from a total of 13", "5 work orders await quotes from a total of 8". The code line "Showing 5 of N." follows each, but read alone the sentences say 5 of 189 units are uninsured. Bills total stated twice. |
| digest t1 | 0.55 / 0.90 | 0.55 / 0.90 | **0.55** / 0.90 | partial | Normal pipeline, no digest block, right meeting, date, tense, Meeting 140 chip. **No union, no place.** The Cypher no longer returns the union (`RETURN m.name AS meeting_name, m.start_date AS date, m.id AS meeting_id`), the row has two displayable columns, so the code-rendered record block does not apply and the answer is prose. |
| digest t2 | 0.75 / 0.90 | 0.90 / 0.90 | **0.90** / 0.90 | pass | Correct framings ("130 tasks are currently open", "189 units have expired insurance"), one closing line "The EXCEL file with everything shown is attached below.", `export_followup_offered: excel`, `failed_sources: []`, `export_excel` chip. Remaining: "EXCEL" in capitals, bills total twice. |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p14 | 0.725 | 10 / 4 / 0 | 0.786 |
| p16 | 0.757 | 11 / 3 / 0 | 0.829 |
| **p17** | **0.782** (+0.025) | **12 / 2 / 0** | **0.868** (+0.039) |

- p17 answers, t0 to t13: 0.70, 0.45, 0.80, 0.80, 0.85, 0.90, 0.85, 0.90, 0.80, 0.85, 0.85, 0.75, 0.55, 0.90 (sum 10.95).
- p17 actions, t0 to t13: 0.70, 0.70, 0.90, 0.90, 0.90, 1.00, 0.85, 1.00, 0.85, 0.85, 0.85, 0.85, 0.90, 0.90 (sum 12.15).
- Answer gains: rights t2 +0.40, french t0 +0.10, rights t1 +0.05, resilience t0 +0.05, resilience t2 +0.05. Answer losses: digest t0 -0.15, rights t0 -0.10, cold-roster -0.05.
- Action gains: rights t2 +0.40, resilience t1 +0.15. No action loss.
- Rights t2 moves from partial to pass. Remaining partials: rights t1 and digest t1.

---

# 2. Fix verification

| Fix | Exercised on | Result | Evidence |
|---|---|---|---|
| Empty own-data result: ownership fact + door | rights t2 | **Verified** | Stage `empty_result_check` (63 ms): `scope: own`, `ownership_check: {entity: Unit, display_name: unit, count: 0}`, 1 execution. Prompt: "VERIFIED BY THE SYSTEM: this user has NO unit linked to their account. That is the reason nothing matched — lead with it … never state or imply that the team has none or owes nothing." Answer leads with it; no team claim; `attached_actions: [{role: next_step, key: report_receivables}]`. |
| Probe fusion: question angle keeps a slot | rights t0, t1 | **Verified, with a side effect** | `angle_groups: [search_query, question, clarified_question, probe_1, probe_1, probe_2, probe_2]`; `question_passages: {CCQ: 8}` on t0, `{CCQ: 59}` on t1. On t0 the question's own best passage is chunk 8, the indivision "RIGHTS AND OBLIGATIONS OF CO-OWNERS" division, so the floor now pins an indivision passage. |
| One vote per probe slot | rights t0, t1 | **Verified** | Both languages of a probe share one group (`probe_1`, `probe_2`). |
| Near-duplicate probes dropped | rights t1 | **Verified** | `probes_dropped`: the fr and en forms of probe 2, `duplicate_of: probe`, similarity 0.828. The slot is lost, not replaced (five angles instead of seven). |
| Five passages per file | rights t0, t1 | **Verified** | Five CCQ passages on both turns (p16: three). Composition input +1,675 and +2,266 tokens. |
| Full heading path on passage headers | rights t0, t1 | **Verified in the prompt; does not stop the misuse** | Header: "§MODALITIES OF OWNERSHIP › CHAPTER II — UNDIVIDED CO-OWNERSHIP › DIVISION II — RIGHTS AND OBLIGATIONS OF CO-OWNERS — art. 1015, 1016, 1017, 1018", plus the line "The § line of a passage is its place in the document (chapter › section): it states the rule for THAT part only, never for another part or situation." **Undivided passages are still used as condo law:** rights t0 cites art. 1015 [3]. On t1 the indivision passage ([4]) is retrieved and not cited. Two of five passages are indivision on t0, one of five on t1. |
| Rights t1 retrieves 1071-1072 besides 1064 | rights t1 | **Verified at retrieval; lost at composition** | Chunk 74 is rank 1 or 2 on all five angles; final passages 59, 74, 66, 11, 47. The answer cites [2] once, for the notification sentence, and never names the contingency fund. |
| One-row record block rendered by code (name, date, union) | digest t1 | **NOT verified — did not fire** | Response metadata has no `table_rendered` / record layout; the prompt still reads "ANSWER FORMAT: state the values from FACTS in prose, copied verbatim … No table." with FACTS = Meeting Name and Date only. `DataAnswerPlan::statedInProse` keeps one-row results of at most `RECORD_PROSE_MAX_COLUMNS = 2` columns in prose (`DataAnswerPlan.php:74, 586-593, 641`). The union is absent because the Cypher no longer projects it (see Regressions #2). |
| Roster answers end at the table | resilience t0, t1, french t0 | **Verified** | All three end with the code line "Showing 25 of 523 rows …". Prompt: "then the placeholder {{table}} on its own line as the LAST line of your answer — the system discards any text after it." No generic closer on any table turn. |
| Roles as labels (French on French turns) | resilience t0, t1, french t0 | **Verified on screen; not in files** | EN and FR labels as quoted in the table above. The two follow-up export files (resilience t3, french t1) still carry lead / accountant / assistant / contact. |
| Follow-up keeps the Team settings chip | resilience t1 | **Verified** | QU `entity_types: []`, `follow_up_reference: true`; `attached_actions: [{role: identity, key: team_settings}]`. |
| QU possessive (`possessive_words`, `possessive_scope`) | all 14 | **Verified; none wrong** | See the list below. `possessive_forms` is not written to the trace, so the forms cannot be checked. |
| How-to turns: no legal probes, how-to doc first, one composition call | resilience t2, app-nav | **Verified** | `search_probes: {skipped: navigation_target}`, `angle_groups: [search_query, question, clarified_question, search_tags]`, two help-doc passages, zero CCQ, `violations_first: []`, one composition call each. `file_context` 2.1 s (p16: 7.1-7.3 s). |
| Digest: sections by code, single closing line with a file | digest t0, t2 | **Verified** | `digest_composition.mode: framing`, nine sources, `failed_sources: []`, `skipped_sources: []`; t2 `export_followup_offered: excel` and one closing line. The t0 framing sentences are wrong this run (model variance, section 3). |
| Security: false rejections | all 14 | **None** | `security_gate.passed: true` on 14 of 14; `safe_stop: false` on every execution; no SecurityException text; `security_filter_applied: true` on every executed query. |
| Rate-limit admission | all 14 | **None** | No `admission` stage and no busy answer in either run; both digests report `sources_failed: []`. |

**QU possessive per turn**

| Turn | Question words | `possessive_words` / scope | Judgment |
|---|---|---|---|
| rights t0 / t1 / t2 | "my main rights", "my duty", "my contribution" | ["my"] / personal | Correct |
| resilience t0, cold-roster | "my team", "my current team" | ["my"] / personal | Correct by form; the referent is the team. Harmless: no ownership tier was selected on these turns |
| resilience t1 | "their last login" | [] / null | Correct (p16 said possessive) |
| resilience t2, app-nav | "Where can I add new members?" | [] / null | Correct |
| resilience t3 | "export that members list" | [] / null | Correct (p16 said possessive) |
| french t0 | "mon équipe" | ["mon"] / personal | Correct |
| french t1 | "exporte-la" | [] / null | Correct (p16 said possessive) |
| digest t0 | "What should I take care of next?" | [] / null | Correct |
| digest t1 | "our last meeting" | ["our"] / collective | Correct. Clarified question: "the last meeting associated with the user's group" (p16: "my condo union", an unverified owner claim) |
| digest t2 | "everything needing my attention" | ["my"] / personal | Questionable: "my attention" names no owned record. Harmless here (the digest owns the turn), but the selection adds the ownership tier (Contact, ContactUnit, Unit, Union) |

Side finding: on the collective turn (digest t1) the selection still adds the ownership tier (`selection_tiers`: Contact, ContactUnit, Unit, Union = "ownership"). `ContextRetriever.php:933` passes `$ctx->qu->isPossessive` to `EntitySelectionPruner`, not `isPersonalPossessive()`. That turn has the largest generation prompt of the run (9,619 input tokens).

---

# 3. Regressions vs p16 (traced)

1. **Rights t0 -0.10: indivision cited as condo law again. Constant cause; p17 code makes it more likely.**
   - p16 had chunk 8 in the prompt (three passages: 58, 61, 8) and the model ignored it. p17 has two indivision passages out of five (11 and 8) and the model cites art. 1015 [3].
   - `question_passages: {CCQ: 8}`: the evidence floor (`AiManager.php:1775-1783`, applied in `mergeFileAngleResults` at line 1791) guarantees chunk 8 a slot, because the raw question "What are my main rights and duties as a co-owner here?" embeds closest to the indivision division of the same title. The `search_query` angle ranks 9, 7, 8 first (all indivision).
   - Chunk 74 (art. 1071-1074, contingency fund) is rank 1 on the French probe 2 and rank 4 on the English one; it loses its place to the two indivision passages.
   - The full heading path and the "§ line" instruction are in the prompt and did not prevent the citation.
   - Whether the model uses the passage is variance (used in p14, ignored in p16, used in p17).
2. **Digest t1 (score unchanged at 0.55, mechanism worse): the union left the data. Code change removed the cue; the projection is left to the generator.**
   - p16 Cypher: `MATCH (m:Meeting)-[:BELONGS_TO_UNION]->(u:Union) WHERE u.team_id IN $ai_team_ids … RETURN m.name, m.start_date, u.name AS union_name, m.id`. p17: `MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids … RETURN m.name AS meeting_name, m.start_date AS date, m.id AS meeting_id`.
   - Prompt diff for this turn: p16 carried the block "IMPORTANT - USER OWNERSHIP PATHS … To find the current user's Meeting: … Meeting BELONGS_TO_UNION that union" and the clarified question "associated with my condo union". p17 replaces the block with one paragraph ("COLLECTIVE POSSESSIVE: … anchor the query on the current team scope … do NOT route it through the user's personal ownership") and the clarified question is "the user's group". The only worked Meeting -> Union example is gone.
   - With two displayable columns the row falls under `RECORD_PROSE_MAX_COLUMNS = 2`, so the record block is bypassed and the composition prompt is the p16 one minus the union line (the diff is exactly one line: "- Union Name: SDC du 1240 St-Sébastien").
   - p14 also dropped the union under the old prompt, so the projection was never deterministic.
3. **Digest t0 -0.15: wrong framing sentences. Pure model variance.**
   - The composition prompt is byte-identical to p16's (empty diff). It says "Facts: 5 shown of 189 in total — speak of the 189." p16 wrote "There are 130 open tasks in total"; p17 t0 wrote "5 units have expired insurance out of 189 total"; p17 t2, same prompt shape, wrote "189 units have expired insurance."
4. **Cold-roster -0.05 and the harness gate: header "Contact Info". Generator variance.**
   - Alias for `u.email` over three runs: `contact_email` (p14), `email` (p16), `contact_info` (p17). The header is derived from the alias. QU `property_concepts: ["contact", "info"]` feeds the wording.
5. **Row echo before the table (resilience t0, french t0; net score still up). Code change plus model non-compliance.**
   - `DataAnswerPlan.php:1368` changed "a direct answer first, then the placeholder {{table}} …, then at most one short closing next step" to "a direct answer first **and anything else you have to say**, then the placeholder {{table}} …". The next line still says "Do NOT list the rows". Two of three table turns now list the ten DATA SUMMARY rows as bullets; p16 listed none.
6. **Screen and file disagree on roles (resilience t3, french t1; not scored down).** `CodedColumns::labelled` is hooked into `DataAnswerPlan.php:555` only; the export composer writes raw codes.
7. **Export follow-up now executes a regenerated query (resilience t3).** Query generation returned the full roster Cypher and it ran (100 rows) although `export_source` is `previous_result`. p16 returned an empty Cypher. Generator variance; cost only.
8. **Cosmetic:** "(that's you)" on both the name and the email cell of the same row; "EXCEL" in capitals; "mon équipe" in the French export title.

Fixed regressions from p16: how-to turns carry no CCQ passages and no retry; knowledge turns are 3.4-3.7 s faster in `file_context`; rights t1 has the contingency-fund passage back.

---

# 4. Remaining defect ranking

Turns below 0.85 on answer or action: rights t0 (0.70 / 0.70), rights t1 (0.45 / 0.70), rights t2 (0.80), resilience t0 (0.80), cold-roster (0.80), digest t0 (0.75), digest t1 (0.55).

## Ranked (points x how deterministic the fix looks)

| # | Defect | Turns | Points at stake | Lost at | Variance? | Fix determinism |
|---|---|---|---|---|---|---|
| 1 | Single-record lookup returns only what the generator projects; record block bypassed at two columns | digest t1 | 0.30-0.35 | Cypher projection, then `DataAnswerPlan` threshold | Projection varies (p14 no union, p16 union, p17 no union); the p17 trigger is a code change | High |
| 2 | "Tell me more" follow-up has no deepening contract: new passage present and unused; consequences not retrieved | rights t1 | 0.35-0.40 | Composition (data present, ignored) + retrieval (art. 1094 / 1086) | Use of [2] is model behaviour; nothing in the prompt asks for it | Medium-high |
| 3 | Indivision branch competes with the condo branch of the same file; evidence floor pins it | rights t0 (and t1 latent) | 0.20-0.25 | Retrieval | Usage varies, cause constant since p14 | High |
| 4 | Digest framing gets the shown count and misuses it | digest t0 | 0.15 | Composition | Pure model variance | Very high |
| 5 | Narrative lists the rows and calls a 25-row table "complete" | resilience t0, french t0 | 0.05-0.10 each | Composition | Non-compliance, invited by the new wording | Very high |
| 6 | Empty own-data answer: checked kinds from QU, door not explained | rights t2 | 0.10-0.15 | Composition instruction | No | High |
| 7 | Column header follows the alias, not the property | cold-roster | 0.05 + harness gate | Cypher alias | Pure generator variance | High |
| 8 | Export files carry raw codes | resilience t3, french t1 | 0.05-0.10 each (not yet deducted) | Export composer | No | Very high |
| 9 | Law answers have no action | rights t0, t1 | 0.15-0.30 action each | Action attachment | No | Medium (host config) |

## Detail per turn

### Digest t1 — answer 0.55

- **Standard still demands:** name + date + **union**, place if recorded.
- **Understanding:** correct (`data_query`, Meeting, collective, no digest).
- **Cypher — where it is lost:** union and place not projected. The generation prompt offers only: "COLLECTIVE possessives … anchor them on the current team/union scope ($ai_team_ids over Team/Union-level entities, e.g. Meeting via BELONGS_TO_UNION with team_id IN $ai_team_ids)" and "return that human-readable identifying column even when the question asks only for its date". Nothing requires the parent name on a single-record lookup; the HOMONYM SAFETY line applies only "when matching any entity by its human NAME".
- **Composition:** "ANSWER FORMAT: state the values from FACTS in prose, copied verbatim … No table." The model complied; both facts are in the answer.
- **Most structural fix — a record card per entity.**
  - The host already defines the meeting card once: `CondoEdge/config/ai.php:1469` (`m.name, un.name AS union_name, m.start_date, m.place`, OPTIONAL union hop).
  - Declare it on the entity (card columns including the parent's name) and let the digest source and single-record lookups share it.
  - After execution, when the result is one row carrying the record's id column (`meeting_id`), run the card query by id through the scoped executor (same mechanism as the `empty_result_check` stage and `companionAnchorTotal`), merge the missing columns, and render with `layout: record`.
  - Where: `AiManager.php` next to the empty-result check; `DataAnswerPlan.php:571-616` and `statedInProse` (line 635).
  - Smaller step: a one-row result whose row holds a record id is always a record (code-listed fields), whatever its column count; `RECORD_PROSE_MAX_COLUMNS` then applies only to aggregate rows. That makes the block fire but does not bring the union back.

### Rights t1 — answer 0.45, action 0.70

- **Standard still demands:** the contingency fund; consequences of non-payment (loss of voting rights, recovery); no re-served overview.
- **Understanding:** correct. **Retrieval with article numbers:** 59 (1063-1066), 74 (1071, 1071.1, 1072, 1072.1, 1073, 1074), 66 (1068.1-1070), 11 (1017-1022, indivision), 47 (1052-1056). Missing: 1094 (no vote after three months unpaid) and 1086.
- **Data present but ignored — the prompt lines:** "**1071.** The syndicate establishes, according to the estimated cost of major repairs and the cost of replacement of common portions, a contingency fund …" and "**1072.** Each year, the board of directors … fixes their contribution for common expenses, which include … the amounts to be paid into the contingency fund and the self-insurance fund." Also unused: art. 1069 in [3] and art. 1054 in [5] ("The by-laws also deal with the procedure of assessment and collection of contributions to the common expenses").
- **Composition instruction:** the only shaping line is "This is a knowledge/document question: a short structured overview is appropriate … Stay focused; no padding." Nothing marks the turn as a deepening of a previous answer, and nothing separates new passages from what the user already has. Art. 1064 is in the prompt twice ([1] and "Files Referenced in Previous Response").
- Hypothesis, not established: the new line "it states the rule for THAT part only" may discourage using a passage headed "RIGHTS AND OBLIGATIONS OF THE SYNDICATE" for a co-owner's duty.
- **Most structural fix — a deepening contract built by code.**
  - On a knowledge follow-up, compute from chunk article metadata: "ALREADY COVERED in your previous answer: art. 1063, 1064, 1064.1, 1065, 1066, 1067, 1068" and "NEW in this turn's passages: art. 1071, 1072, 1072.1, 1069, 1054 …; build the answer from the NEW ones". Add it to the knowledge prompt next to the "Files Referenced in Previous Response" block.
  - Validator rule in `AnswerValidator`: on such a turn, if no cited passage is new relative to the previous turn's citations, or a new on-branch passage goes uncited while old ones are restated, retry once.
  - Retrieval: do not spend a slot on a chunk the previous turn cited (chunk 59 here); it is re-supplied anyway.
  - Probe writer (`DocumentProbeWriter`, prompt line "Both probes answer THIS question. Keep the specific thing it is about: do not widen it …"): make slot 2 a different facet by construction, the consequence of not respecting the rule. On this turn slot 2 was a paraphrase, dropped at 0.828, and the slot was wasted. Replace a dropped slot instead of losing it.
  - Trailing paragraph without a citation after a cited list ("Understanding these obligations is crucial … feel free to ask!") can be dropped by position, as is done after `{{table}}`.
- **Standard critique (unchanged from p16):** "legal recovery" has no article in the indexed corpus; name art. 1094, 1086 and 1069 instead.

### Rights t0 — answer 0.70, action 0.70

- **Standard still demands:** the contingency fund; not an exhaustive dump.
- **Retrieval:** 59 (1063-1066), 11 (1017-1022), 8 (1015-1018), 64 (1067-1069), 82 (1075.1-1079). Two slots go to Chapter II, one to insurance; art. 1071-1072 (chunk 74) are out.
- **Composition:** the header and the "§ line" rule are present and were not enough.
- **Most structural fix — branch coherence inside a file.**
  - Where: `AiManager::mergeFileAngleResults` (`AiManager.php:1791`) and the evidence floor (lines 1775-1783).
  - When the kept passages of one file sit under different top-level branches of `heading_path` that contain a sub-heading of the same title (here "RIGHTS AND OBLIGATIONS OF CO-OWNERS" under Chapter II and Chapter III), keep the branch with the larger fused vote mass and drop the other; apply the question-passage floor inside the winning branch. No word list, no corpus rule.
  - On this turn that removes chunks 11 and 8 and frees two slots. The candidates for them are chunks 74 (art. 1071-1074), 88 (1081-1083) and 75, each ranked by both languages of probe 2; whether 74 wins a slot is not established by this trace and needs a replay.
  - Alternative on the host side: declare which heading branch applies to which kind of union (`Union.type_id` 1 = divided, 2 = undivided, already in the schema) and filter passages by branch for the user's union.
- **Action:** see #9 below.

### Digest t0 — answer 0.75

- **Standard still demands:** true totals stated as literal numbers, nothing that misstates them.
- **Composition instruction that is misread:** "Facts: 5 shown of 189 in total — speak of the 189." (`DigestResponseComposer.php:605`).
- **Pure model variance.** Fix: stop giving the model the shown count. Code already prints "Showing 5 of 189."; write "Facts: 189 in total." in the prompt, or have code write the count sentence and leave the model the lead only.

### Resilience t0 — answer 0.80 (french t0 0.85, same defect)

- **Standard still demands:** a table that is not padded, honest about being partial.
- **Composition instruction:** "Write ONLY the narrative: a direct answer first and anything else you have to say, then the placeholder {{table}} …" against "Do NOT list the rows and do NOT write your own table".
- **Fix:** reword `DataAnswerPlan.php:1368` to "one or two sentences"; add a row-echo guard where text after `{{table}}` is already dropped: remove narrative list lines that reproduce DATA SUMMARY row values, and a sentence calling the table complete when `total > shown`. Mark the current user once per row (`selfMarks`, `DataAnswerPlan.php:425`).
- Still open from p16: no ORDER BY on a 100-row cap; `t.id = 3` literal in all five roster queries.

### Rights t2 — answer 0.80

- **Standard still demands:** what was checked in plain words; a door that is clearly the portfolio's.
- **Composition instruction:** "WHAT WAS CHECKED: annual contribution amount for co-owner (record kinds: Invoice, Union) — within the user's OWN linked records." The kinds still come from QU (`ResponseGenerator.php:943-946`, `checked_entity_types`), which gives "union records".
- **Fix:** take the kinds from the labels of the executed Cypher with the host's display names (unit, invoice), and pass the attached action's description into the prompt so the sentence can say what the Receivables report covers.

### Cold-roster t0 — answer 0.80

- **Standard still demands:** names + emails under headers that say so; total disclosed.
- **Cypher:** `u.email AS contact_info`. **Fix:** when a RETURN item is a bare property access, name the header from the property's label, not the alias (the Cypher is already parsed for `CodedColumns`); state the exported row count in the export answer (code knows 523).

### Actions below 0.85 — rights t0, t1 (0.70)

- No chip: the cited file has `can_download: false`, no viewer. A host placement for a document-view action on cited corpus files would give law answers a door.

## Pure model variance (needs a guard, not a prompt tweak)

- Digest framing counts (#4): identical prompt, right in p16 and in p17 t2, wrong in p17 t0.
- Column alias for `u.email` (#7): three runs, three aliases.
- Row echo and the "complete list" sentence (#5): explicit "Do NOT list the rows" ignored on two of three turns.
- Closers on knowledge answers: "feel free to ask!" (rights t1), platitude (rights t0), both against the guideline.
- Whether the indivision passage is cited (#3): used in p14 and p17, ignored in p16; the cause is constant.
- Union projection on a one-record lookup (#1): varies by run; only the trigger in p17 is a code change.

Deterministic, not variance: #6, #8, #9, the ownership tier on collective turns, query generation on export follow-ups.

---

# 5. Tokens and latency vs p16

| Stage | p16 input / output (calls) | p17 input / output (calls) | Delta input |
|---|---|---|---|
| understanding | 69,089 / 2,753 (14) | 68,618 / 2,775 (14) | -471 |
| search_probes | 2,687 / 557 (4) | 1,354 / 293 (2) | -1,333 |
| query_generation | 53,506 / 742 (8) | 54,712 / 814 (8) | +1,206 |
| composition | 41,772 / 1,702 (12) | 36,925 / 1,849 (11) | -4,847 |
| **Total (in + out)** | **172,808** (38 calls) | **167,340** (35 calls) | **-5,468 (-3.2%)** |

- Composition: how-to turns save 9,850 (resilience t2 11,355 -> 3,026; app-nav 4,113 -> 2,592); law turns cost 3,941 more (rights t0 3,700 -> 5,375; rights t1 4,539 -> 6,805) for the five-passage budget.
- Query generation: roster prompts grew about 590 tokens each (4,877 -> 5,466; 4,883 -> 5,470).
- Still wasted: query generation on the two export follow-ups (7,490 + 5,779), the 37-row previous-result sample in the digest t1 composition prompt (4,995 input tokens for a two-fact answer), the ownership tier on the collective turn.

| Stage latency | p16 mean / median | p17 mean / median |
|---|---|---|
| understanding | 6.1 s / 6.0 s | 5.7 s / 5.8 s |
| file_context (4 knowledge turns) | 8.9, 8.2, 7.1, 7.3 s | 5.3, 4.8, 2.1, 2.1 s |
| of which probe call | 2.1-2.7 s on four turns | 2.7 s, 2.3 s on two turns; none on how-to |
| query_generation | 2.7 s / 2.5 s | 1.9 s / 1.8 s |
| execution | 1.0 s / 1.0 s (6) | 0.3 s / 0.2 s (7) |
| empty_result_check | — | 0.06 s (1) |
| composition (14 rows) | 2.4 s / 1.9 s | 2.3 s / 2.2 s |
| composition (11 LLM turns) | 2.9 s / 3.0 s | 2.8 s / 2.4 s |
| digest | 0.9 s | 0.6 s |
| **pipeline** | **12.9 s / 11.7 s** (max 20.3 s) | **10.4 s / 9.7 s** (max 16.4 s) |

- The gain comes from `file_context` (-3.4 to -5.2 s on knowledge turns) and from faster generation and execution; the two law turns pay about 1 s more in composition for the larger prompt.
