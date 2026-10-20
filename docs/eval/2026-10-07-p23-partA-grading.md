# Grade: p23-1001-A (Part A, 13 turns, user 13 Julie Rousseau, team 3)

- **Source:** `CondoEdge/storage/eval/p23-1001-A.json`, run at 2026-10-07T03:31–03:38-04:00. ai `badd2f1` + 610 dirty files (p22: 606). config_sha1 `676831b1…` (p22: `9f458592…`).
- **Models:** unchanged (QU / composition / passage selection gpt-4o-mini, QG and follow-up writer gpt-4o).
- **Harness (summed from the rows):** 13/13 `ok`, 21/21 gates passed, 0 void, 0 infra, `errors`, `fallbacks` and `context_errors` empty on all 13. The top-level counters (`total_rows: 7`, `usage_total` 80,423) describe one chunk only.
- **Rubric / strictness:** identical to p20–p22. Pass >= 0.70, partial 0.40–0.69, fail < 0.40. Follow-up chips are scored in the action column.
- **Not verified:** nothing was executed against the graph; no phpunit, no artisan. No chip was clicked, no file opened. Single run: every "variance" verdict rests on a prompt diff against p22, not on repeats. One thing WAS executed: `FollowUpWriter::carried()` / `answerWords()` replayed by reflection on the stored law answers, with and without the stem keys (section 2).

---

# 1 — GRADING

## Headline

- **Answer mean 0.838** (p22 0.831, **+0.008**). **Action mean 0.873** (p22 0.881, **−0.008**).
- **Counts 11 pass / 2 partial / 0 fail** (p22 11 / 2 / 0).
- Only the tear-down turn moved: answer **+0.10** (0.40 → 0.50), action **−0.10** (0.75 → 0.65). Both movements are model variance; neither comes from iteration-8 code.
- **No iteration-8 change was exercised by Part A.** Precedence check: wired on all 9 generations, 0 violations, 0 repairs, 0 extra calls. Label-echo guard: 0 drops. Digest framing: no digest turn. Stem-insensitive test: 0 excerpt decisions changed. Host ownership-path edit: the path is in no Part A prompt.
- The eleven data / export / clarify turns score exactly as in p22 (answer sum 9.80).

## Per-turn table

| # | Turn | p21 ans / act | p22 ans / act | p23 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (= / =) | Lead "Your team is called Novo Gestion, and it is classified as a management company with 523 members." (p22 had quotes and "a total of"). Composition prompt byte-identical to p22: model variance. Still restates three block values. Same four chips, no members door. |
| 1 | roster t0 | 0.85 / 0.85 | 0.85 / 0.85 | **0.85 / 0.85** | pass (=) | Answer byte-identical. Same lead cut (`restatement`), same cypher, same 100 / 523 rows. |
| 2 | roster t1 Excel | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | Same text, same 523-row payload. Link title back to "Team Members and Roles" (QU `subject_label` case variance). QG ran, as designed. |
| 3 | 310 t0 | 0.95 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass (=) | "…currently have a balance of $0.00 each, with no open invoices or amounts due." Composition prompt byte-identical: variance. Same cypher, same 3 rows. |
| 4 | 310 t1 PDF | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | Byte-identical. `recorded_result_export` {generation_skipped: true, recorded_rows: 3}; one call, 5,159 tokens. |
| 5 | ambiguous t0 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass (=) | Byte-identical. Same four reply chips. |
| 6 | ambiguous t1 | 0.95 / 0.95 | 0.95 / 0.95 | **0.95 / 0.95** | pass (=) | "…all have a balance owed of $0.00…": the lead echoes QU's `search_query` ("unit 310 balance owed", p22 "…outstanding balance"), the only composition-prompt difference. Same cypher, same 3 rows. |
| 7 | tear-down t0 | 0.35 / 0.70 | 0.40 / 0.75 | **0.50 / 0.65** | **partial (+0.10 / −0.10)** | Four numbered points and a closing. Better than p22: approval is now conditional ("If the wall in question is deemed a common portion…"); art. 1100 is stated accurately as its own case [2]; a co-owner majority is named for changes to the destination [2] (art. 1098; "structure" is not in the passage, and the 3/4–90% figure is not given); **art. 1063 is cited for the first time** [3] — but only its proviso (by-laws, rights of others, destination), not the rule it qualifies. Unchanged: the opening "You cannot … without following specific regulations"; art. 1045 with half its condition ("walls that separate private portions from common portions"); approval attributed to [1], which does not hold it; **no sentence says a wall wholly inside her private portion is hers to alter**. No article number named or invented. Level of p19 (0.50). Action: two chips, one borderline, one off-topic (section 2). |
| 8 | buyer-arrears t0 | 0.50 / 0.90 | 0.60 / 0.85 | **0.60 / 0.85** | partial (= / =) | Same content as p22: liability with interest at acquisition [1]; the statement as advice only ("It is advisable to request a statement … before finalizing your purchase [1]"); **15-day protection not stated**. Names "Article 1069" (correct, in [1]). Adds a "This means that…" paragraph that repeats paragraph 1. Composition prompt identical to p22 except two relevance figures (55 → 54%, 47 → 48%): pure variance. One chip, same excerpt, statute wording ("as a prospective acquirer"). |
| 9 | board t0 | 0.75 / 0.75 | 0.75 / 0.75 | **0.75 / 0.75** | pass (=) | Answer byte-identical. The model's own lead this run was TRUE and answered the president half; code cut it (section 2, HARMFUL). Same 5 record chips over 5 of 12 unions. |
| 10 | board t1 meetings | 0.85 / 0.95 | 0.85 / 0.95 | **0.85 / 0.95** | pass (=) | "There are currently no upcoming meetings scheduled. Here are the most recent meetings:" Composition prompt byte-identical. The generated query changed (now joins `Schedule` for agenda items); 0 rows again, same fallback, same 3 meetings. Agenda half still unaddressed. |
| 11 | owner-arrears t0 | 0.95 / 0.90 | 0.95 / 0.90 | **0.95 / 0.90** | pass (=) | "…behind on their fees … is $1,211.48." + "4 units." + the same four rows. Query gained `LIMIT 100`. Filler second sentence cut (`one_sentence`). |
| 12 | supplier t0 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass (=) | Byte-identical. "You … owe … your suppliers" voice slip unchanged. |

## Means

| Run | Answer, all 13 | Action, all 13 | Answer sum | Action sum |
|---|---|---|---|---|
| p21 | 0.819 | 0.881 | 10.65 | 11.45 |
| p22 | 0.831 | 0.881 | 10.80 | 11.45 |
| **p23** | **0.838** | **0.873** | **10.90** | **11.35** |

- vs p22: answers +0.10 (tear-down); actions −0.10 (tear-down).
- Without the two law turns: 0.891 (p22 0.891, p21 0.891).

## Counts

| Run | Pass | Partial | Fail |
|---|---|---|---|
| p21 | 11 | 1 | 1 |
| p22 | 11 | 2 | 0 |
| **p23** | **11** | **2** | **0** |

- **Partial:** tear-down 0.50, buyer 0.60. **Lowest pass:** board 0.75.

---

# 2 — VERIFICATION

| Item | Status | Evidence |
|---|---|---|
| Precedence check (OR / AND) | **Wired, never fired** | `precedence_repaired: []` and `precedence_violations: []` on all 9 `query_generation` stages (keys absent in p22). `attempts: 1`, `repair_attempts: 0` on all 9. 9 QG calls in both runs; 39 LLM calls in both runs. |
| Any cypher mixing OR and AND at one level | **None — no cypher in Part A contains OR at all** | All 9 generated queries and the meetings fallback read in full. Every WHERE is a pure AND chain. The check passed trivially; Part A does not test it. |
| Extra QG tokens from the check | **None** | QG 60,885 → 62,492 (+1,607), all from QU variance: board +1,217 (`is_possessive` false → true adds the User / Team cards, the collective-possessive block and a clarified intent), 310 t1 +364 (entity `Contact` instead of `InvoiceDetail`), the rest within ±45. No precedence wording was added to the QG prompt: five QG prompts differ from p22 by the date line only. |
| Host config edit (ownership path parenthesised) | **Not exercised** | config_sha1 changed. 0 lines with `WHERE … OR` in the 9 QG prompts of either run: no personal-ownership question in Part A renders that path. |
| Queries changed vs p22 | **2 of 9; same rows, same answers** | Turn 10: p22 `… RETURN m.name, m.start_date, m.description AS agenda … LIMIT 1`; p23 `… WITH m ORDER BY m.start_date ASC LIMIT 1 MATCH (s:Schedule)-[:BELONGS_TO_MEETING]->(m) RETURN …, s.title AS agenda_item, s.description …`. Both 0 rows; fallback cypher identical; 3 identical rows. QG prompt identical but the date: model variance. Turn 11: gained `LIMIT 100` (QU dropped the concept "owners", one prompt line less); 4 identical rows. `data_sample` identical on all 13 turns. |
| Label-echo guard | **Not exercised, no false positive** | 0 `label_echo_dropped` in the file. The tear-down answer has a colon line followed by a list ("Here are the key points to consider:") and was correctly left alone. |
| Digest framing capitalisation | **Not exercised** | `digest_request: false` on all 13 turns. |
| Stem-insensitive "already told" test | **Active, changed no decision** | Replay below. |
| Closing drops | **None** | No `closing_dropped` on any row. |
| Security / admission | **Clean** | 13/13 `security_gate` passed. 9 executions, all `safe_stop: false`, `security_filter_applied: true`, `repair_attempted: false`. No SecurityException, no `admission` stage, no busy answer, no validation retry. Unchanged watch item: the meetings fallback cypher is logged without a team predicate (`scope: "team"`). |

## Stem-insensitive test, replayed

`carried()` run on the stored answers, with the stem keys and with them removed (14 paragraph decisions):

| Turn | Paragraph | Exact words | Stem-only words | Carried with / without stems |
|---|---|---|---|---|
| tear-down p23 | art. 1066 para. 1 | 8 of 16 | 0 | 8 / 8 |
| tear-down p23 | art. 1066 para. 2 | 6 of 32 | 0 | 0 / 0 |
| tear-down p23 | art. 1063 | 10 of 17 | 0 | 10 / 10 |
| tear-down p23 | art. 1100 para. 1 | 17 of 32 | 0 | 17 / 17 |
| tear-down p23 | art. 1100 para. 2 | 6 of 16 | 0 | 0 / 0 |
| tear-down p23 | art. 1045 | 7 of 15 | 0 | 0 / 0 |
| buyer p23 and p22 | art. 1069 para. 2 | 13 of 29 | 1 ("acquirer") | 0 / 0 |

- No decision differs with or without stems on either law turn. The buyer excerpts are the same four, same tiers, as p22.
- **Margin to watch:** art. 1069 para. 2 (the 15-day rule) now stands at 14 of 29 = 48.3%. One more shared word makes it "already told" and removes the only excerpt that can produce the 15-day chip. Without stems it was two words away.

## Law turns: passages and passage selection

| | Tear-down (turn 7) | Buyer (turn 8) |
|---|---|---|
| Pool / candidates | 20 / 20 | 20 / 20 (same list as p22) |
| Picked | chunk 58 = **art. 1063** (as p22) | chunk 94 = **art. 1086** (as p22) |
| Added as | `joined` into chunk 61 → passage [3] art. 1063–1068 | `passage` → sixth passage [6] |
| Relevant candidate left in the pool | art. 1097 (candidate [AM]); cap is 1 | none needed |
| Tokens / latency | 3,985 (3,978 in / 7 out) / 1,567 ms | 3,998 / 1,327 ms |
| Answer cites the added passage? | **Yes, [3]** — art. 1063's proviso only | **No** — cites [1] only |
| Helped? | Partly: the limits are stated, the right is not | No (second run with the same tangential pick) |

Final passages in the prompt:

| # | Tear-down | Used? | Buyer | Used? |
|---|---|---|---|---|
| [1] | art. 1042–1047, 45% | cited (art. 1045, half its condition) | art. 1068–1070, 54% | cited twice (art. 1069 para. 1, half of para. 2) |
| [2] | art. 1098–1102, 71% | cited twice (art. 1098 loosely, art. 1100) | art. 1063–1067, 51% | no |
| [3] | art. **1063**–1068, 66% | **cited (art. 1063 proviso)** | art. 1076–1080, 47% | no |
| [4] | art. 1055–1058, 65% — content of the declaration | no; tangential | art. 1070–1072, 48% | no |
| [5] | art. 1076–1081, 56% — syndicate liability, actions | no; off-topic | art. 1092–1097, 49% | no |
| [6] | — | | art. 1086, 44%, **added** | no |

- Tear-down retrieval: `votes_below_floor` {"question"} (p22: search_query and question); art. 1042–1047 leads as [1]. Passages [4] / [5] changed with the probe wording (this run "…respecter les règles de la déclaration de copropriété" and "…destination de l'immeuble"; p22 "…approbation du syndicat"). One off-topic passage instead of two.
- **Does the tear-down answer distinguish a wall inside her own private portion (art. 1063) from walls presumed common (art. 1044–1045) and works needing approval?** Not explicitly. It makes approval depend on the wall being "deemed a common portion" and lists art. 1063's limits, but it never states the positive rule (free use and enjoyment of the private portion) and never says an interior partition is outside art. 1045. Art. 1044 (main walls) is unused. The opening still presumes a restriction.
- Composition frame (everything before the passages, and from "When using information…" to the end) is byte-identical to p22.

## Every follow-up chip

Labels: N natural, A acceptable, O off-topic or already answered.

| Turn | Chip question | Source | Label | Answerable from its passage |
|---|---|---|---|---|
| 5 clarify | Outstanding balance of one unit | reading 1 | N | n/a (reply) |
| 5 clarify | Balance of an account | reading 2 | N | n/a |
| 5 clarify | Balance of a fund | reading 3 | N | n/a |
| 5 clarify | Balance of an invoice | reading 4 | A | n/a; weakest reading |
| 7 tear-down | Whose consents are needed for amending the declaration of co-ownership? | passage [2], art. 1100 para. 2, tier 1 | **A, borderline O** | Yes for the art. 1100 case (creditors, co-owners, syndicate). Two of the three are already in point 3 of the answer; the wording is wider than its excerpt, which covers only the amendment that follows a boundary alteration. It is the first chip on the approval thread. |
| 7 tear-down | What must the syndicate provide to the occupant for non-urgent work? | passage [3], art. 1066 para. 2, tier 1 | **O (off-topic)** | Yes (written notice: nature, start date, duration, vacancy). It is about work the syndicate does in an occupied unit, not hers. |
| 8 buyer | When am I bound to pay common expenses as a prospective acquirer? | passage [1], art. 1069 para. 2, tier 1 | A | Yes: only if the statement is provided within 15 days. Read cold it looks answered; statute wording (p22: "as a buyer"). |

- Totals: 7 chips — 3 N, 3 A, 1 O (p22: 4 N, 3 A, 0 O). Legal chips: 3 — 0 N, 2 A, 1 O (p22: 1 N, 2 A, 0 O).
- Declined excerpts, 5, all correctly: tear-down art. 1099 and art. 1064; buyer art. 1069 para. 3, art. 1068.1, art. 1063.
- Lost against p22: "Are main walls of buildings considered common portions?" (N) and the art. 1063 chip (now used by the answer).

## Every lead cut

| Turn | What the model wrote | Cut (rule) | What remains | Verdict |
|---|---|---|---|---|
| 1 roster | "Here are the members of your team, Novo Gestion, along with their roles:" | whole lead, `restatement` | "523 members." + table + "Showing 25 of 523" | **GOOD** (no value lost; still no "your team" left). |
| 9 board | "The board of directors members are listed below, but the specific role of president is not recorded in the data." | whole lead, `not_recorded` | "37 board members across 12 unions. Not recorded: Board role." + table | **HARMFUL (mild)**. The sentence is true and is the plain answer to "who is the president". In p20–p22 the model's sentence here was false and the cut was right; the rule (`DataAnswerPlan::boundedLead`: drop any lead naming no row value when the facts line prints "Not recorded") cannot tell the two apart. No fact is lost, so the score holds at 0.75. |
| 11 owner-arrears | S2 "Here are the details of the owners who are currently overdue on their fees." | S2 `one_sentence` | S1 with the total + "4 units." + table | **GOOD**: filler. |

Leads not cut: team-info, both 310 leads, meetings, supplier.

## Closings and label echo

- **Closing dropped: none.** Tear-down's "Before proceeding, it would be wise to review the declaration of co-ownership and consult with the syndicate…" was kept. **Keeping it is right** (the only sentence that says what to do). Buyer: both paragraphs cited, no candidate.
- **Label-echo dropped: none**, and none was needed.

---

# 3 — REGRESSIONS VS p22

| Turn | Delta | Cause | Evidence |
|---|---|---|---|
| tear-down, action | **−0.10** | **Model variance in the answer, exposing an existing excerpt heuristic. Not iteration 8.** | (1) The answer now cites [3], so passage [3]'s other articles become excerpt sources. (2) `seen()` judges art. 1066 "used": its first paragraph shares exactly 8 words with the answer (owner, with, private, portion, work, required, immovable, syndicate) — the `SEEN_WORDS = 8` threshold, all exact matches, 0 by stem. Its second paragraph is then a tier-1 excerpt. (3) The answer no longer carries art. 1045 by words (7 of 15), so passage [1] counts as covered whole and art. 1044 — p22's natural chip — gets no slot. (4) The writer (gpt-4o, system prompt byte-identical) wrote a question for the art. 1066 excerpt instead of declining it. |
| board, lead cut | no score change | **Model variance meeting a deterministic rule** | First run in four where the composer's lead was true; the `not_recorded` rule cut it as before. Composition prompt +393 characters (the user's e-mail line and the placeholder note, from QU `is_possessive` true). |
| meetings, query shape | no score change | **Model variance (QG)** | QG prompt identical but the date. Latent risk: `MATCH (s:Schedule)` after `LIMIT 1` is not OPTIONAL, so an upcoming meeting without agenda items would return 0 rows and the answer would say none is scheduled. |
| buyer, chip wording | no score change | **Model variance (writer)** | Same four excerpts, same system prompt. "as a prospective acquirer" copies the statute. |
| latency | +7.6 s (138.0 → 145.6 s) | **Provider** | Section 5. No extra call anywhere. |

Movement in the other direction: tear-down answer **+0.10**, model variance on a byte-identical instruction block with two different passages ([4], [5]).

Moved without a score change:

- Composition prompt byte-identical, wording differs (pure sampling variance): team-info, 310 t0, meetings, owner-arrears.
- QU variance carried into the output: Excel link title case, 310 t1 lead ("balance owed").
- Byte-identical answers: roster, PDF, clarify, board, supplier.

**No regression traces to the precedence check, the label-echo guard, the digest capitalisation, the stem test or the host config edit.**

---

# 4 — REMAINING DEFECT RANKING

Missing points: answers 2.10, actions 1.65. Stage letters: (a) understanding, (b) retrieval, (c) query generation, (d) data in the prompt but ignored, (e) a composition instruction or code rule, (f) action attachment.

| Rank | Defect | Turn(s) | Stage | Points | Fix determinism | Model variance? |
|---|---|---|---|---|---|---|
| 1 | Board: president answered only by a label — and this run the code cut the model's correct sentence; record chips for 5 of 12 unions | 9 | (e), (f) | ~0.50 | **high** | No |
| 2 | Tear-down: art. 1063 used as a limit only; no "inside your own portion" statement; art. 1045 half-conditioned; committed opening | 7 | (d), (e) | ~0.40 | medium | Which articles and which half: yes |
| 3 | Buyer: 15-day protection left out (5 of 7 graded runs on this instruction) | 8 | (d) | ~0.40 (0.15 vs the best draw) | medium-high | **Pure variance** on a fixed prompt |
| 4 | Tear-down chips: an article "used" by 8 common words yields an off-topic tier-1 excerpt; a cited passage whose article is not "seen" yields none | 7 | (f) | ~0.35 | **high** | Trigger: yes (answer wording). Rule: no |
| 5 | Roster: staff buried in an unordered sample; no insider wording after the cut | 1 | (c), (e) | ~0.30 | high | No |
| 6 | Team-info: lead restates block values; no members door | 0 | (e), (f) | ~0.25 | high | Restating: yes |
| 7 | Clarify: "balance of an invoice"; reply chips send a bare reading | 5 | (a), (f) | ~0.20 | medium | Which kinds: yes |
| 8 | Meetings: agenda half unanswered; non-optional agenda join can hide a real upcoming meeting | 10 | (c), (e) | ~0.15 now; a wrong answer when it bites | medium | Query shape: **pure variance** |
| 9 | Export leftovers: "rows" vs "units"; "No values found for: Oldest Due." | 2, 4 | (e) code | ~0.15 | certain | No |
| 10 | Voice: "you owe … your suppliers" | 12 | (d) | ~0.15 | low | **Pure variance** |
| 11 | 310: lead and homonym line say the same thing twice | 3, 6 | (e) | ~0.10 | high | No |
| 12 | Buyer chip reads as already answered, statute wording | 8 | (f) | ~0.15 | low | **Pure variance** |
| 13 | Tear-down retrieval: one off-topic and one tangential passage of five | 7 | (b) | ~0.05, ~1.5k prompt tokens | low | **Pure variance** (probe wording) |
| 14 | Passage selection always picks its quota: art. 1086 on buyer, two runs running | 8 | (b) | 0; 4.0k tokens, 1.3 s | medium | No (same pick twice) |
| 15 | Stem test leaves art. 1069 para. 2 one word from "already told" | 8 | (f) | 0 now; the 15-day chip when it flips | medium | Trigger: yes |
| 16 | Excel follow-up pays a QG call (by design) | 2 | (e) code | 0; 7.2k tokens, 2.1 s | certain | No |

## Most structural fix per top defect

1. **Board.** Keep a lead that names the asked concept beside "not recorded" (the p23 sentence), or have the code line name the concept the user asked for ("President: not recorded") instead of the column header. Spread record chips over unions.
2. **Tear-down composition.** Unchanged from p22: the selector's pick reaches composition unmarked inside a six-article passage. Give it its own passage, first, or mark its § line as the general rule. This run shows the composer reads it when it lands, and takes the wrong half.
3. **Buyer 15 days.** Unchanged: regenerate once with the tier-1 unused clause of the cited paragraph, or an instruction to give the condition the cited passage attaches.
4. **Tear-down chips.** In a passage cited without an article number, when `drawnOn()` names the article a cited statement draws on (here art. 1063, 10 words against art. 1066's 8), take that as the used article and do not add every article `seen()` admits. Not run; inferred from the code and the replay.
8. **Meetings query.** Prompt rule for requested attributes on a related entity: OPTIONAL MATCH, never a join that can empty the anchor row.

Defects 1, 4, 5, 6, 9, 11 need no model change and cover about 1.65 of the 3.75 points missing. Defects 3, 10, 12, 13 are pure variance, about 0.75.

---

# 5 — TOKENS AND LATENCY VS p22

Tokens, summed per turn:

| Stage | p22 | p23 | Delta |
|---|---|---|---|
| understanding (13 calls) | 65,527 | 65,527 | 0 |
| query_generation (9 calls) | 60,885 | 62,492 | +1,607 |
| composition (10 calls) | 34,104 | 33,333 | −771 |
| search_probes (2 calls) | 1,671 | 1,661 | −10 |
| passage_selection (2 calls) | 8,037 | 7,983 | −54 |
| clarity_check (1 call) | 1,171 | 1,171 | 0 |
| follow_ups (2 calls, gpt-4o) | 2,262 | 2,369 | +107 |
| **Total** | **173,657** (39 calls) | **174,536** (39 calls) | **+879 (+0.5%)** |

- Input 168,622 → 169,336; output 5,035 → 5,200.
- QG +1,607: board +1,217, 310 t1 +364 (both QU variance, section 2). **No repair call.**
- Composition −771: tear-down −871 (shorter passages [4] / [5]; output 138 → 260 tokens), board +83, buyer +20.
- Knowledge turns: tear-down 16,724 (p22 17,576), buyer 17,425 (p22 17,384).

Latency:

| Stage | p22 sum / mean | p23 sum / mean |
|---|---|---|
| understanding | 81.0 s / 6.23 s (max 8.5 s) | 83.4 s / 6.41 s (max 10.1 s, supplier) |
| file_context (2 knowledge turns) | 12.4 s (6.78 s, 5.59 s) | 13.4 s (7.78 s, 5.64 s) |
| of which probe call | 4.6 s | 4.6 s |
| of which passage selection | 3.0 s (1.51 s, 1.51 s) | 2.9 s (1.57 s, 1.33 s) |
| query_generation | 21.0 s / 2.33 s over 9 (max 3.3 s) | 24.6 s / 2.73 s over 9 (max 4.9 s) |
| execution | 0.8 s / 0.09 s over 9 | 1.0 s / 0.11 s over 9 |
| composition (10 LLM-composed turns) | 16.9 s / 1.69 s (tear-down 2.72 s) | 17.5 s / 1.75 s (tear-down 3.45 s) |
| follow_ups (2 turns) | 3.06 s / 1.53 s | 3.25 s / 1.63 s |
| clarity_check | 2.04 s | 2.06 s |
| empty_result_check | 0.43 s | 0.06 s |
| **pipeline** | **138.0 s / 10.6 s, median 10.3 s, max 17.2 s** | **145.6 s / 11.2 s, median 10.2 s, max 19.4 s** |

- +7.6 s overall: QG +3.6 s, understanding +2.4 s, file_context +1.0 s, composition +0.6 s.
- QG: two slow single calls (310 t0 4.5 s, owner-arrears 4.9 s), both `attempts: 1`. Provider latency, not the precedence check.
- Tear-down 17.2 s → 19.4 s: search / embedding inside `file_context` +1.1 s, composition +0.7 s (longer answer).
- Understanding is 57% of pipeline time (p22: 59%).
