# Grade: p18-1001-B (Part B) vs p17 (and p16)

- Run: `CondoEdge/storage/eval/p18-1001-B.json`, generated 2026-10-06T18:45. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3 "Novo Gestion", owns no unit). 0 void, 0 infra, 14 rows `ok`, 0 gate failures (p17: 1).
- Rubric and strictness as p14 / p16 / p17. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1.
- Same ai package sha (badd2f1), dirty tree 545 -> 559 files. Host config sha 29053c6d -> 4aae3535. Models unchanged (QU and composition gpt-4o-mini, query generation gpt-4o, embeddings text-embedding-3-small).
- Top-level counters describe chunk 1 only. Everything below is summed from the 14 rows: 167,079 tokens, 35 LLM calls.

---

# 1. Per-turn grading

| Turn | p16 ans / act | p17 ans / act | p18 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|
| rights t0 | 0.80 / 0.70 | 0.70 / 0.70 | **0.65** / 0.70 | partial (borderline) | No indivision passage and no art. 1015: passages are chunks 58 (art. 1062-1065), 61 (1064-1068), 64 (1067-1069), 32 (1038-1040), 69 (1070), all Chapter III. All citations resolve. But: **contingency fund still missing** (art. 1071-1072 not retrieved); **access for work (art. 1066) dropped** although it is in [2] (p17 had it); the "Rights" list mirrors the "Duties" list (contribution, insurance, notification appear in both); one unsupported right, "You are entitled to know how common expenses are calculated and have a say in the management of these expenses (§1064)"; "the right to take out third-party liability insurance, which is a requirement"; platitude closer; "§1063" notation instead of "art.". Two of the four items the standard names are absent. No chip. |
| rights t1 | 0.40 / 0.70 | 0.45 / 0.70 | **0.80** / 0.70 | pass | Builds on the new passages: funds as part of the contribution (art. 1072) [2], buyer bound to arrears with interest (art. 1069) [3], **loss of the vote after three months unpaid (art. 1094) [5]**, by-laws on assessment and collection (art. 1054) [4]; one bullet restates art. 1064. No overview re-served, no indivision. Residual: the contingency fund is named, not explained (art. 1071 is in [2], unused); "you **may** lose your right to vote" softens "is deprived"; art. 1086 (director disqualification) not retrieved; no "legal recovery" (not in the corpus); platitude closing paragraph. No chip. |
| rights t2 | 0.40 / 0.50 | 0.80 / 0.90 | **0.80** / 0.90 | pass | "You have no unit linked to your account, so there are no records related to your contribution for this year. I checked for annual contribution amounts in invoices, union records, and fund records … You may want to review the receivables report." `empty_result_check`: `scope: own`, `ownership_check {Unit, count 0}`. Receivables report chip. Same residue as p17: "union records, and fund records" (QU kinds), door not explained as the portfolio's. |
| resilience t0 | 0.75 / 0.90 | 0.80 / 0.90 | **0.85** / 0.90 | pass | Lead is one sentence with the true total and the kind: "There are 523 members in your team, and here are their roles:". No bullet echo, no "complete list" claim. Name / Email / Role, role labels, staff first, Team settings chip. Residual: showing line still "Showing 25 of 523 **rows**"; "(that's you)" on both the name and the email cell; team name no longer said; no ORDER BY, `t.id = 3` literal. |
| resilience t1 | 0.85 / 0.75 | 0.85 / 0.90 | **0.85** / 0.90 | pass | Same query plus `u.last_logged_at AS last_login`; labels kept; chip kept. Lead: "There are 523 **records** in total, and I've included the last login information …" (kind not named). Showing line "rows". |
| resilience t2 | 0.85 / 1.00 | 0.90 / 1.00 | **0.90** / 1.00 | pass | `search_probes: {skipped: navigation_target}`, team-and-members.md 0.698, no CCQ, one composition call (3,026 in). Grounded steps, cited [1], chip named in one sentence. Roster survives (t3 exports 523). |
| resilience t3 | 0.85 / 0.85 | 0.85 / 0.85 | **0.90** / 0.85 | pass | `export_source: previous_result`, 523 rows, Name / Email / Role / Last Login, title "Team Members Last Login". **File now carries role labels** (Lead union manager, Accountant, Administrative assistant, Unit Owner or Renter). Residual: no row count in the answer ("the available information"); query generation still ran (7,509 in) and its query was executed (100 rows) for nothing. |
| app-nav t0 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90** / 1.00 | pass | Same as resilience t2: no probes, how-to doc first (0.739), one call (2,592 in), cited [1], Team settings chip. |
| cold-roster t0 | 0.85 / 0.85 | 0.80 / 0.85 | **0.85** / 0.85 | pass | `RETURN u.name AS member_name, u.email AS contact_email, u.id AS user_id`. 523 rows, headers "Member Name", "Contact Email", every row filled. Both harness gates pass on the delivered file. Residual: no row count in the answer, no roles, `t.id = 3` literal. |
| french t0 | 0.75 / 0.85 | 0.85 / 0.85 | **0.85** / 0.85 | pass | "Il y a 523 membres dans votre équipe. Voici la liste …", French role labels, "(c'est vous)", ends "Affichage de 25 **membres** sur 523 — demandez la liste complète ou un export.", chip "Réglages de l'équipe". No row echo. **New defect: table headers are English ("Name / Email / Role")** in a French answer (p17: "Membre / Rôle"). The echo gain and the header loss cancel. |
| french t1 | 0.85 / 0.85 | 0.85 / 0.85 | **0.85** / 0.85 | pass | 523 rows, "En date du", "Télécharger Excel", **French role labels in the file** (Gestionnaire en chef, Comptable, Adjoint.e administratif, Copropriétaire ou locataire). But "Champs inclus : Name, Email, Role." and English headers in the file (p17: "Membre, Rôle"). Title "Membres de mon équipe". Query generation runs (5,824 in) for an empty Cypher. |
| digest t0 | 0.90 / 0.85 | 0.75 / 0.85 | **0.90** / 0.85 | pass | All framing sentences state true totals: "130 tasks are currently open.", "75 overdue receivables are outstanding.", "189 units have expired insurance.", 13, 36, 8. Nine sources, `failed_sources: []`, single "Nothing pending in:" line, single closing question. Residual: "Most recent meetings show no scheduled items." (clumsy), bills total stated twice. |
| digest t1 | 0.55 / 0.90 | 0.55 / 0.90 | **0.55** / 0.90 | partial | Normal pipeline, right meeting, date, past tense, Meeting 140 chip. **No union, no place.** Cypher: `MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids AND m.start_date < toString(date()) RETURN m.name AS meeting_name, m.start_date AS date, m.id AS meeting_id ORDER BY m.start_date DESC LIMIT 1`. Two displayable columns, so the record block does not fire and the answer is prose. |
| digest t2 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90** / 0.90 | pass | Correct framings, one closing line, `export_followup_offered: excel`, `failed_sources: []`. Residual: "EXCEL" in capitals, "All 4 approved bills are not yet paid", bills total twice. |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p16 | 0.757 | 11 / 3 / 0 | 0.829 |
| p17 | 0.782 | 12 / 2 / 0 | 0.868 |
| **p18** | **0.825** (+0.043) | **12 / 2 / 0** | **0.868** (+0.000) |

- p18 answers, t0 to t13: 0.65, 0.80, 0.80, 0.85, 0.85, 0.90, 0.90, 0.90, 0.85, 0.85, 0.85, 0.90, 0.55, 0.90 (sum 11.55).
- p18 actions, t0 to t13: 0.70, 0.70, 0.90, 0.90, 0.90, 1.00, 0.85, 1.00, 0.85, 0.85, 0.85, 0.85, 0.90, 0.90 (sum 12.15). No action changed.
- Answer gains: rights t1 +0.35, digest t0 +0.15, resilience t0 +0.05, resilience t3 +0.05, cold-roster +0.05. Answer loss: rights t0 -0.05.
- Rights t1 moves from partial to pass; rights t0 moves from pass to partial (0.65, borderline). Partials: rights t0 and digest t1.

---

# 2. Fix verification

| Fix | Exercised on | Result | Evidence |
|---|---|---|---|
| Branch coherence | rights t0, t1 | **Verified** | t0 `branch_coherence`: `dropped: [8, 9, 11, 15]`, `kept: CHAPTER III — DIVIDED CO-OWNERSHIP OF IMMOVABLES`, `dropped_branches: [CHAPTER II — UNDIVIDED CO-OWNERSHIP]`, weights 2.3354 vs 1.6646. t1: `dropped: [11]`, 3.3849 vs 0.6151. The evidence floor is re-read inside the kept branch: `question_passages` 8 -> 58 on t0. All five passage headers on both turns are Chapter III. Art. 1015 is not cited. |
| ... its side effect | rights t0 | **Contingency fund still not retrieved** | The two freed slots went to chunk 32 (art. 1038-1040, establishment) and chunk 69 (art. 1070, registers). No angle reached chunk 74 (art. 1071-1074) this run; see Regressions #1. |
| "ALREADY COVERED / NEW" block | rights t1 | **Verified and effective (0.45 -> 0.80)** | Prompt: "FOLLOW-UP — the user has already read your previous answer: - ALREADY COVERED in this conversation: art. 1062–1069 (passage [1] holds nothing new). - NEW in these passages: art. 1071–1074 in [2]; art. 1070 in [3]; art. 1052–1056 in [4]; art. 1092–1097 in [5]. This answer must build on the NEW material …". Trace `deepening.new: {2: [1071..1074], 3: [1070], 4: [1052..1056], 5: [1092..1097]}`. The answer cites art. 1072 [2], 1069 [3], 1094 [5], 1054 [4]. Dropping chunk 11 freed the slot that chunk 108 (art. 1092-1097) took, which is how art. 1094 arrived. Caveat: "covered" is the article range of the previously cited passages (1062-1069), not what the previous answer said; art. 1069 and 1066 were never stated in t0 and are listed as covered. |
| Table lead limited to one or two sentences | resilience t0, t1, french t0 | **Verified** | Prompt: "Write ONLY the lead — one or two sentences that answer the question — then the placeholder {{table}} …". Leads are one or two sentences on all three (composition output 18, 30 and 26 tokens). |
| Row-echo blocks dropped | resilience t0, french t0 | **Guard not exercised; no echo to drop** | No `row_echo_dropped` key on any turn (it is written only when > 0, `ResponseGenerator.php:287`). The model wrote no list on any of the three table turns (p17: 10-bullet echo on two of three). The "complete list" sentence is gone too. |
| True total with the record kind; showing line names the kind | resilience t0, t1, french t0 | **French verified; English NOT** | FR: prompt "Your FIRST sentence states the number of membres (523 in all)"; answer "Il y a 523 membres …" and "Affichage de 25 membres sur 523". EN: prompt "states the number of records (523 in all) … Name what is counted in the question's own words — never as "rows""; t0 "523 members" (model), t1 "523 records"; showing line "Showing 25 of 523 rows" on both. Cause: `DataAnswerPlan::recordKind` (`DataAnswerPlan.php:789-813`) returns the host plural untranslated only when the answer language equals `app.fallback_locale`. The host declares `display_name_plural => 'members'` in English (`CondoEdge/config/ai-entities/general.php:1311`) and sets `fallback_locale => 'fr'` (`CondoEdge/config/app.php:96`). `trans('members', [], 'en')` returns the key, the locale test fails, the kind is null. |
| Header from the property when the alias shares no word | cold-roster | **Code present, not exercised** | Alias this run is `contact_email`, which shares "email" with the property, so the alias stands ("Contact Email"). Rule: `TableRenderer::label` (`TableRenderer.php:261-300`). Alias for `u.email` over four runs: contact_email, email, contact_info, contact_email. |
| Harness gate inspects the delivered file | cold-roster | **Verified** | `columns_include` actual `["Member Name","Contact Email"]`; `promised_columns_nonempty` actual `{headers, data_rows: 523, empty_columns: []}`. |
| Export files carry role labels in the answer's language | resilience t3, french t1 | **Verified** | `export_document_rows`: "Lead union manager", "Accountant", "Administrative assistant", "Unit Owner or Renter" (EN); "Gestionnaire en chef", "Comptable", "Adjoint.e administratif", "Copropriétaire ou locataire" (FR). Cold-roster has no role column. |
| Digest: model not given the shown count | digest t0, t2 | **Verified** | Prompt: "Facts: 130 in total.", "Facts: 75 in total.", "Facts: 189 in total.", "Facts: all 4 shown.". All twelve truncated-section framings over the two turns state the true total only. |
| Digest t1: union projected, record block, union shown | digest t1 | **NOT fixed (was listed open)** | Union not projected (Cypher above). Composition prompt: "FACTS … - Meeting Name: … - Date: 2023-10-24 16:00" and "ANSWER FORMAT: state the values from FACTS in prose, copied verbatim — never recompute them. No table." `statedInProse` keeps one-row results of at most `RECORD_PROSE_MAX_COLUMNS = 2` columns in prose (`DataAnswerPlan.php:83, 906-912`). Union not shown. |
| Pruner: ownership tier only for personal possession | digest t1 | **Verified** | `selection_tiers: {Meeting: seed, User: identity, Team: identity}` (p17 added Contact, ContactUnit, Unit, Union). Generation prompt 9,619 -> 8,142 input tokens (-15%). `ContextRetriever.php:936-940` now passes `ownershipPaths: isPersonalPossessive()`. Rights t2 (personal) keeps the tier, as it should. |
| Clarify readings seeded from calculation labels | — | **Not exercised in Part B** | `needs_clarification: false` on 14 of 14; no ambiguous turn in this part. |
| Security | all 14 | **Nothing wrong** | `security_gate.passed: true` 14 / 14; `safe_stop: false` on 8 / 8 executions; `security_filter_applied: true` on the 7 executed queries (french t1 executed nothing, empty Cypher); no SecurityException text. |
| Admission | all 14 | **Nothing wrong** | No `admission` stage, no busy answer; both digests `sources_failed: []`. |
| Possessive scope | all 14 | **None wrong; one questionable (unchanged)** | Same values as p17 on every turn. Digest t1 "our" -> collective. Digest t2 "my attention" -> personal, which still adds the ownership tier (Contact, ContactUnit, Unit, Union); harmless, the digest owns the turn and no query is generated. |

---

# 3. Regressions vs p17 (traced against `p17-1001-B.json`)

1. **Rights t0 -0.05 (0.70 -> 0.65). Code change exposes two older weaknesses, plus model variance.**
   - The first five angles and their rankings are identical in both runs (`angle_chunks` byte-equal). What differs:
   - **Probe slot 2 was lost (probe-writer variance).** p17 kept seven angles; its probe 2 ("… la gestion des dépenses communes et la participation aux décisions du syndicat") ranked chunk 74 first (`[74, 88, 59, 72, 75]`). p18's probe 2 ("… la gestion des dépenses communes et l'entretien des parties communes") was dropped as a duplicate of probe 1 (`probes_dropped`, similarity 0.842 against a 0.8 threshold, `AiManager.php:1450-1514`). The slot is dropped, not replaced, so no angle reaches the contingency-fund passage.
   - **Branch coherence moved the primary from chunk 59 to chunk 58 (code change).** The window rule in `mergeFileAngleResults` (`AiManager.php:2037-2054`) admits an extra chunk when it sits more than `context_window` (2) away from a kept one, but every snippet is expanded by two chunks each side. Chunks 58, 61 and 64 are three apart, so their windows overlap: art. 1064, 1064.1 and 1065 are printed in both [1] and [2], art. 1067 and 1068 in both [2] and [3]. About a fifth of the passage text is duplicated. p17's pair (59, 64) did not overlap.
   - **The freed slots went to low-value passages.** Chunk 32 (art. 1038-1040) and chunk 69 (art. 1070) are in no angle's top five.
   - **Composition (variance).** The answer repeats exactly the three articles that are duplicated in the prompt (1064, 1064.1, 1065) under both "Rights" and "Duties", omits art. 1066, and adds a right the passages do not state. That the duplication in the prompt causes the duplication in the answer is plausible, not established.
2. **French table headers are English (french t0 on screen, french t1 in the file and in "Champs inclus : Name, Email, Role"). Generator variance; nothing in code controls header language.**
   - p17 Cypher: `RETURN u.name AS membre, tr.role AS rôle`. p18: `RETURN u.name AS name, u.email AS email, tr.role AS role`. Headers are the humanized alias or property name (`TableRenderer::label`), never translated.
   - Latent: by its documented rule, the new header-from-property step would head a French alias that shares no word with its property (`membre` for `u.name`) with the English property name. Not exercised here.
   - Not scored down net (the echo and label gains offset it); the standard calls a mixed answer a fail, so it is exposed.
3. **Roster leads no longer name the team** (p17: "your team, Novo Gestion"). The lead is now count first, one or two sentences. Cosmetic, not scored.
4. **"§1063" notation** on rights t0 (p17: "art. 1063"; p18 t1 uses "art."). Variance; the prompt uses "§" for the heading path.

Carried over from p17, unchanged: query generation on the two export follow-ups (7,509 + 5,824 input tokens; the regenerated roster query is executed on resilience t3); "(that's you)" on two cells of one row; "EXCEL" in capitals; "mon équipe" in the French export title; `t.id = 3` literal and no ORDER BY in all roster queries; no row count in export answers.

Fixed regressions from p17: row echo and the "complete list" sentence (0 of 3 turns); digest framing counts (0 wrong of 12); "Contact Info" header and its gate failure; raw role codes in files; indivision cited as condo law; the follow-up that ignored its new passages.

---

# 4. Remaining defect ranking

Turns below 0.85 on answer or action: rights t0 (0.65 / 0.70), rights t1 (0.80 / 0.70), rights t2 (0.80), digest t1 (0.55).

## Ranked (points x how deterministic the fix looks)

| # | Defect | Turns | Points at stake | Lost at | Variance? | Fix determinism |
|---|---|---|---|---|---|---|
| 1 | Single-record lookup shows only what the generator projects; record block bypassed at two columns | digest t1 | 0.30-0.35 answer | Cypher projection, then `DataAnswerPlan` threshold | Projection: union in 1 of 4 runs (p16 only) | High |
| 2 | Law answers carry no door | rights t0, t1 | 0.15-0.30 action each | Action attachment | No | High (host config + attacher) |
| 3 | Overview retrieval: lost probe slot, overlapping windows, low-value fill; contingency fund never reaches the prompt | rights t0 | 0.10-0.15 answer | Retrieval | Probe wording varies; window overlap is constant | High for the overlap and the slot; whether chunk 74 lands needs a replay |
| 4 | Empty own-data answer: checked kinds from QU, door not explained | rights t2 | 0.10-0.15 answer | Composition instruction | No | High |
| 5 | Overview composition: omits a rule that is in the prompt, mirrors rights and duties, adds an unsupported right | rights t0 | 0.10-0.15 answer | Composition | Pure model variance | Low |
| 6 | Deepening residue: fund named but not explained, art. 1086 not retrieved, softened rule, platitude closer | rights t1 | 0.05-0.10 answer | Composition, retrieval | Mostly variance | Medium (closer: very high) |
| 7 | English record kind never resolves ("rows", "records") | resilience t0, t1 (both 0.85) | 0.05 each | Code / host locale | No | Very high |
| 8 | Headers not in the answer's language | french t0, t1 (both 0.85) | 0.05 each; exposed to the "mixed answer" fail | Table renderer | Alias language varies | High |

## Detail per turn

### Digest t1 — answer 0.55

- **Standard still demands:** name + date + **union**, place if recorded.
- **Understanding:** correct (`data_query`, Meeting, collective, no digest).
- **Cypher — where it is lost:** union and place not projected. The generation prompt only says "When the rows are records, return that human-readable identifying column even when the question asks only for its date or amount" and "COLLECTIVE possessives … anchor them on the current team/union scope ($ai_team_ids over Team/Union-level entities, e.g. Meeting via BELONGS_TO_UNION with team_id IN $ai_team_ids)". Nothing requires the parent's name.
- **Data present but ignored:** the union and place of this very meeting are in the composition prompt, in the "Previous Query Results Sample" block (the digest rows of t0: `"name": "Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN", "union_name": "SDC du 1240 St-Sébastien", … "place": "ZOOM"`, same id 140). The instruction that keeps them out: "ANSWER FORMAT: state the values from FACTS in prose, copied verbatim — never recompute them. No table." The model complied.
- **Most structural fix — a record card per entity.**
  - The host already writes the meeting card once, in the digest source: `CondoEdge/config/ai.php:1466-1470` (`m.id, m.name, un.name AS union_name, m.start_date, m.place`, OPTIONAL union hop). Declare those card columns on the Meeting entity and let the digest source and single-record lookups share them.
  - After execution, when the result is one row holding a record id column (`meeting_id`), run the card query by id through the scoped executor (the mechanism of the `empty_result_check` stage), merge the missing columns, render with `layout: record`.
  - Where: `AiManager.php` next to the empty-result check; `DataAnswerPlan::statedInProse` (`DataAnswerPlan.php:906-912`): a one-row result whose row holds a record id is a record whatever its column count; `RECORD_PROSE_MAX_COLUMNS` then applies to aggregate rows only.
- Four runs, four prompts, union projected once: this is not fixable in the generation prompt.

### Rights t0 — answer 0.65, action 0.70

- **Standard still demands:** the contingency fund; access for necessary work; a concise overview without padding.
- **Understanding:** correct.
- **Retrieval (article numbers):** [1] 1062-1065, [2] 1064-1068, [3] 1067-1069, [4] 1038-1040, [5] 1070. Missing: 1071-1072 (chunk 74).
- **Data present but ignored — the prompt line:** "**1066.** No co-owner may interfere with the carrying out, even inside his private portion, of work required for the preservation of the immovable dec…" in [2]. Not used.
- **Composition instruction:** "This is a knowledge/document question: a short structured overview is appropriate — you may use brief headings or bullet points when they genuinely organize the answer. Stay focused; no padding."
- **Most structural fixes (retrieval):**
  - **Non-overlapping passages.** `AiManager::mergeFileAngleResults` (`AiManager.php:2037-2054`): require a distance greater than twice `context_window` between kept chunks of a file, or merge adjacent windows into one contiguous passage (here one passage "art. 1062-1069") counted as one slot. Frees two slots and removes the duplicated text.
  - **Never lose a probe slot.** `AiManager::withoutDuplicateProbes` (`AiManager.php:1450-1514`): when slot 2 duplicates slot 1, replace it. The replacement that needs no extra LLM call and no word list: use the kept primary passage itself as an extra angle (search with the text of the question's best on-branch passage, here art. 1063-1064). On t1 every angle about "contribute to the common expenses" ranks chunk 74 first or second, so this angle is likely to bring art. 1071-1072 in; that needs a replay to confirm.
  - **Fill freed slots by vote, not by rank depth.** After branch coherence, a slot no angle supports in its top five (chunks 32, 69) is better left empty than filled.
  - Probe-writer prompt line that produced the duplicate: "`<code>_2`: a different line of attack on the SAME question — what the documents say of the specific thing it is about: what that thing is, whose it is, who answers for it." For a broad question the model restates slot 1.
- **Composition (see "not fixable" below).**
- **Action:** see #2.

### Rights t1 — answer 0.80, action 0.70

- **Standard still demands:** the contingency fund as a specific; "legal recovery".
- **Retrieval (article numbers):** [1] 1063-1066, [2] 1071-1074, [3] 1068-1070, [4] 1052-1056, [5] 1092-1097. Missing: art. 1086 (director disqualification after three months unpaid, in the corpus at line 286).
- **Data present but ignored — the prompt line:** "**1071.** The syndicate establishes, according to the estimated cost of major repairs and the cost of replacement of common portions, a contingency fund to be used exclusively for such repairs and replacement." The answer names the fund from art. 1072 only.
- **Fixes:** compute "covered" from the articles the previous answer cited (`cited_articles` is already on each reference) instead of passage ranges, in `DeepeningContract` / `FileContextSection::deepeningContract` (`FileContextSection.php:114-153`); drop a trailing paragraph that carries no citation after a cited list, by position, as is done after `{{table}}`.

### Rights t2 — answer 0.80

- **Standard still demands:** what was checked in plain words; a door that is clearly the portfolio's.
- **Composition instruction:** "WHAT WAS CHECKED: annual contribution amount for this year (record kinds: Invoice, Union, Fund) — within the user's OWN linked records." The kinds come from QU (`AiManager.php:3490`, `ResponseGenerator.php:950`), which gives "union records, and fund records"; the executed Cypher touched Contact, ContactUnit, Unit and Invoice.
- **Fix:** take the kinds from the labels of the executed Cypher with the host's display names; pass the attached action's description into the prompt so the sentence can say the Receivables report covers the portfolio she manages.

### Actions below 0.85 — rights t0, t1 (0.70)

- **Where it is lost:** action attachment. Cited references have `can_download: false`, `download_url: null`, `preview_url: null`; no chip is attached on a `knowledge_query` turn without a navigation target.
- **Fix:** in `src/Services/Actions/Attach/ActionAttacher.php`, a follow-up chip built from data the pipeline already has: on rights t0 the kept division's uncited articles, on t1 the NEW list of the deepening contract ("art. 1071–1074 in [2]"), offered as one "tell me more" chip. Or a host placement of the existing generic `docs` (Documentation) action on answers citing the law corpus (`CondoEdge/config/ai.php`, actions).

### Cheap, on turns at 0.85

- **English record kind:** `DataAnswerPlan::recordKind` (`DataAnswerPlan.php:803-813`). Replace the `app.fallback_locale` test with an explicit setting for the language host AI literals are written in (new `ai.response.literal_locale`, default `en`), or accept the declared plural when the translator has no line for it in any locale. Gives "Showing 25 of 523 members" and "the number of members (523 in all)".
- **Header language:** `TableRenderer::label` (`TableRenderer.php:281-300`): pass the humanized header through the host translator for the answer's locale (the host has `Name => Nom`, `Email => Courriel` in `resources/lang/fr.json`; "Role" has no line yet). Same call in `ExportResponseComposer::humanizeHeader` (`ExportResponseComposer.php:757`).
- **Self mark once per row; export answer states the row count; skip query generation when `export_source` is `previous_result`** (13,333 input tokens over the two export follow-ups).

## Pure model variance

- Rights t0 composition: omission of art. 1066, mirrored lists, the invented right, "§" notation.
- Probe 2 wording (kept in p17, duplicate in p18).
- Union projection on a one-record lookup (1 of 4 runs).
- Alias wording and alias language (`contact_email` / `email` / `contact_info`; `membre` / `name`).
- "may lose" for "is deprived" (rights t1); platitude closers on both law turns.

## Not fixable without a stronger composition model or a change to the standard

- **Rights t1 "legal recovery":** the indexed corpus has no recovery provision (no legal hypothec article in `public/laws/CCQ_Co-ownership_EN.md`; the only other non-payment consequence is art. 1086). Change the standard to name art. 1094, 1086 and 1069, or add the articles to the corpus.
- **Rights t0 composition fidelity:** using every relevant rule of a five-passage prompt once, without inventing a right, is beyond what gpt-4o-mini does reliably here. A deterministic guard cannot tell an unsupported sentence from a paraphrase; a grounding check is a second model call. Removing the duplicated text is the only deterministic lever, and its effect is unproven.
- **Rights t0 "contingency fund" in an overview:** the fund sits under "RIGHTS AND OBLIGATIONS OF THE SYNDICATE", not under the co-owner's division. Retrieval can be made likelier to bring it (fixes above) but not certain; if it must be certain, the standard is asking for a curated answer.
- **Rights t1 depth on art. 1071:** the passage is in the prompt under a NEW label and the model still used only its neighbour.

Everything else in the ranking (#1, #2, #4, #7, #8, the window overlap, the lost slot) is deterministic.

---

# 5. Tokens and latency vs p17

| Stage | p17 input / output (calls) | p18 input / output (calls) | Delta input |
|---|---|---|---|
| understanding | 68,618 / 2,775 (14) | 68,674 / 2,762 (14) | +56 |
| search_probes | 1,354 / 293 (2) | 1,353 / 276 (2) | -1 |
| query_generation | 54,712 / 814 (8) | 53,744 / 776 (8) | -968 |
| composition | 36,925 / 1,849 (11) | 37,790 / 1,704 (11) | +865 |
| **Total (in + out)** | **167,340** (35 calls) | **167,079** (35 calls) | **-261 (-0.2%)** |

- Query generation: digest t1 9,619 -> 8,142 (-1,477, the pruner); rights t2 8,499 -> 8,953 (+454, not traced); the rest within 50 tokens.
- Composition: rights t1 6,805 -> 7,102 (+297, the follow-up block and a fifth law passage in place of the indivision one); french t0 2,385 -> 2,613 (+228, the query now returns the email column); rights t0 +90; rosters and digests +60 each. Output -145: the row echoes are gone.
- Still wasted: query generation on the two export follow-ups (13,333 input); the 37-row previous-result sample in the digest t1 composition prompt (4,995 input for a two-fact answer); about a fifth of the rights t0 passage text is duplicated.

| Stage latency | p17 mean / median | p18 mean / median |
|---|---|---|
| understanding | 5.7 s / 5.8 s | 5.8 s / 5.4 s (two outliers: 8.2 s, 8.6 s) |
| file_context (4 knowledge turns) | 5.3, 4.8, 2.1, 2.1 s | 5.0, 5.4, 2.1, 2.5 s |
| of which probe call | 2.7 s, 2.3 s | 2.5 s, 2.7 s |
| query_generation | 1.9 s / 1.8 s | 1.9 s / 1.9 s |
| execution | 0.3 s / 0.2 s (8) | 0.3 s / 0.2 s (8) |
| empty_result_check | 0.06 s | 0.05 s |
| composition (14 rows) | 2.3 s / 2.2 s | 2.0 s / 1.7 s |
| composition (11 LLM turns) | 2.8 s / 2.4 s | 2.5 s / 2.4 s |
| digest | 0.6 s | 0.7 s |
| **pipeline** | **10.4 s / 9.7 s** (max 16.4 s) | **10.3 s / 9.4 s** (max 17.2 s) |

- Flat. The two roster turns that echoed rows in p17 compose faster (resilience t0 2.1 -> 1.7 s, french t0 2.6 -> 1.4 s; 18 and 26 output tokens). The maximum is rights t1, from an 8.2 s understanding call, not from the new code.
