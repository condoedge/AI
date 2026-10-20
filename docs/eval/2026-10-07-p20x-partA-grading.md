# A/B grade: p20x-1001-A (variant X) vs p20-1001-A (baseline), Part A, 13 turns

- **Baseline:** `CondoEdge/storage/eval/p20-1001-A.json`, run 2026-10-06T22:56, config_sha1 `998a334f…`, graded in `ai/docs/eval/2026-10-07-p20-partA-grading.md` (0.831 / 0.862, 12 / 1 / 0).
- **Variant X:** `CondoEdge/storage/eval/p20x-1001-A.json`, run 2026-10-06T23:11, config_sha1 `1452c74b…`. Same ai sha `badd2f1` + 592 dirty files.
- **The one difference recorded:** `fingerprint.models.response_generation` = `openai/gpt-4o-mini` (baseline) vs `openai/gpt-4o` (X). QU gpt-4o-mini, QG gpt-4o, embedding and both prompt-section lists are identical.
- **Harness, summed from the rows:** X 13/13 `ok`, 21/21 gates passed, 0 void, 0 infra, `errors` and `fallbacks` empty on all 13 (same as baseline).
- **Rubric / strictness:** the baseline's. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Follow-up chips are scored in the action column.
- **Not verified:** nothing executed (no phpunit, no artisan, no graph query, no chip clicked). One run each. The follow-up excerpt explanation on the buyer turn comes from reading `FollowUpWriter::excerpts()`, not from running it. The text X wrote after `{{table}}` on the supplier turn is not in the trace.

---

# 1 — Turn by turn

## Headline

- **Answer mean: baseline 0.831, X 0.838 (+0.008; sum 10.80 -> 10.90).**
- **Action mean: baseline 0.862, X 0.850 (-0.012; sum 11.20 -> 11.05).**
- **Counts: both 12 pass / 1 partial / 0 fail.** X's partial (tear-down, 0.40) sits on the fail boundary.
- X is better on 2 turns (roster +0.05, supplier +0.10), worse on 1 (tear-down -0.05, confounded), equal on 10.
- **The two law turns did not improve.** Tear-down is slightly worse, buyer is equal. The gain a stronger composer was expected to bring there did not appear in this run.

## Composition prompt comparison (what is confounded)

| Turn | System prompt | User prompt | Confounded? |
|---|---|---|---|
| 0, 3, 6, 9, 10, 11, 12 | byte-identical (4,579 bytes) | byte-identical | No |
| 1 roster | identical | 1 line differs: table header `Name` -> `User Name` (QG alias `u.name AS user_name`) | No for the lead; the header itself is QG variance |
| 2, 4, 5 | no composition call in either run | — | No (code-written answers) |
| 7 tear-down | identical | **100 diff lines, 18,653 vs 20,175 bytes** | **Yes** — see below |
| 8 buyer | identical | Same 5 passages, same text (sorted lines identical); [2]-[4] reordered, relevance labels differ by 1-12 points; passage [1] (art. 1068.1-1070) is first in both | Not materially |

Tear-down confound (QU variance: `search_query` "rules regulations modifying wall kitchen living room unit" in X vs "modification rules for wall between kitchen and living room in unit" in the baseline):

| | Baseline passages | X passages |
|---|---|---|
| [1] | art. 1042-1051 (56%) | art. 1098-1102 (72%) |
| [2] | art. 1098-1102 (71%) | art. 1064-1068 (68%) |
| [3] | art. 1064-1068 (66%) | **art. 1042-1047 (45%)** |
| [4] | art. 1055-1058, content of the declaration (65%) | art. 1070-1071, registers and contingency fund (52%) |
| [5] | art. 1076-1081 (56%) | art. 1076-1081 (64%) |

Art. 1044, 1045 and 1100 are in both prompts. X has art. 1045 in third place at the lowest relevance, has no passage on the declaration (the baseline cited it as [4]), and carries an off-topic passage instead. Art. 1063 and 1097 are in neither.

## Per-turn table

| # | Turn | Baseline ans / act | X ans / act | X vs baseline | Confounded? | Why (decisive sentences) |
|---|---|---|---|---|---|---|
| 0 | team-info t0 | 0.85 / 0.70 | **0.85 / 0.70** | equal | No | B: "Your team is called Novo Gestion, and it is classified as a management company with 523 members." X: "Your team, Novo Gestion, is a management company with 523 members." Tighter, same restatement of three block values. Same record block, same two chips. |
| 1 | roster t0 | 0.85 / 0.85 | **0.90 / 0.85** | **better +0.05** | Header only | B: "Here are the members of your team, Novo Gestion, along with their roles:" (the intro the prompt forbids). X: **no lead** — the answer opens on the code line "523 members." (3 output tokens, no `lead_trimmed`). Same 25 rows, same "Showing 25 of 523 members", same chips. Cost: no "your team" wording is left at all. Header "User Name" is QG variance. |
| 2 | roster t1 Excel | 0.90 / 0.90 | **0.90 / 0.90** | equal | No composition call | Same code sentence and hedge. X: "Fields included: User Name, Email, Role." (QG alias carried into the file header). 523 rows, complete source in both. |
| 3 | 310 t0 | 0.90 / 0.95 | **0.90 / 0.95** | equal | No | B: "Unit 310 currently owes $0.00 across all unions." X: "Unit 310 currently owes $0.00 across all its associated unions." Same merged singular above "3 different units named 310 — one per union."; X no longer overstates "all unions" but now says one unit has several unions. |
| 4 | 310 t1 PDF | 0.90 / 0.90 | **0.90 / 0.90** | equal | No composition call | Answers byte-identical. |
| 5 | ambiguous t0 | 0.90 / 0.90 | **0.90 / 0.90** | equal | No composition call | Answers byte-identical, same four reply chips. |
| 6 | ambiguous t1 | 0.90 / 0.95 | **0.90 / 0.95** | equal | No | B: "Unit 310 has an outstanding balance of $0.00 across all three unions, with no open invoices or invoices due." X: "Unit 310 does not have any outstanding balances or open invoices across all three unions." Same merged singular. |
| 7 | tear-down t0 | 0.45 / 0.70 | **0.40 / 0.70** | **worse -0.05** | **Yes** | See the law section below. X states art. 1045 with its condition and names main walls, but has no majority or co-owner consent at all, cites one passage only, leaves two of four points uncited, and adds a paragraph of general knowledge on insurance. No conclusion in either. |
| 8 | buyer-arrears t0 | 0.75 / 0.85 | **0.75 / 0.70** | answer equal; **action -0.15** | Not materially | See the law section. X is shorter, fully cited, and drops the baseline's inference; it also drops the word "only", so the protection is implied by "However" and not stated. Reverse missing and article not named in both. X has **no follow-up chip** (baseline 3). |
| 9 | board t0 | 0.75 / 0.75 | **0.75 / 0.75** | equal | No | Shipped answers byte-identical: "37 board members across 12 unions. Not recorded: Board role." + table. B reached it after code cut two model sentences, one of them false ("I couldn't find specific information about the board of directors…"). X wrote **no lead** (3 output tokens, no `lead_trimmed`). "Who is the president" is still answered only by the label. |
| 10 | board t1 meetings | 0.85 / 0.95 | **0.85 / 0.95** | equal | No | B: "There are currently no upcoming meetings scheduled." X: "There are no upcoming meetings scheduled at the moment." Same second sentence, same three rows and chips. Agenda half unanswered in both. |
| 11 | owner-arrears t0 | 0.95 / 0.90 | **0.95 / 0.90** | equal (shipped) | No | B: "The total balance of owners behind on fees in SDC Carré Soho Phase 3 is $1,211.48." X: "The total balance of fees overdue for owners in SDC Carré Soho Phase 3 is $1,211.48." X's draft had a second sentence that listed all four rows; code cut it (`lead_trimmed`, `one_sentence`). |
| 12 | supplier t0 | 0.85 / 0.90 | **0.95 / 0.90** | **better +0.10** | No | B: "You currently owe a total of $11,493.17 to your suppliers, with 4 open bills." X: "**We currently owe our suppliers** a total of $11,493.17, with four open bills. Here is a breakdown of the amounts owed to each supplier:" The voice slip is gone; the second sentence is filler (-0.05). X also wrote text after `{{table}}`, dropped by code (`narrative_tail_dropped`). |

## Means and counts

| Run | Answer mean | Action mean | Answer sum | Action sum | Pass | Partial | Fail |
|---|---|---|---|---|---|---|---|
| Baseline p20 | 0.831 | 0.862 | 10.80 | 11.20 | 12 | 1 | 0 |
| **Variant X** | **0.838** | **0.850** | **10.90** | **11.05** | **12** | **1** | **0** |

- Partial in both: tear-down (0.45 -> 0.40).
- Lowest passes in X: buyer 0.75, board 0.75 (unchanged).

---

# 2 — Per-defect comparison

## Law turn: tear-down wall (confounded)

Baseline, four points, all cited:
- "The wall you wish to remove may be classified as a common portion if it separates a private portion from another private portion or from a common portion. According to the law, such walls are presumed to be common [1]."
- "Major alterations typically require a majority vote among co-owners [2]." (not in passage [2])
- "If you are altering boundaries between private portions, you can do so without a general meeting's approval, but you must still obtain consent from the syndicate and any relevant creditors [2]."
- "Check the declaration of co-ownership for any specific restrictions or requirements regarding modifications to your unit [4]."

X, four points, two cited:
- "walls that are not part of the foundations or main walls of the building but separate a private portion from a common portion or another private portion are presumed to be common portions [3]. This means that if the wall in question is a main wall or affects common portions, you may need approval from the syndicate."
- "If the wall is considered a common portion, you will need the consent of the syndicate to make alterations [3]. Even for private portions, significant changes might require approval to ensure they do not affect the structural integrity or the relative value of the property."
- "Check your declaration of co-ownership for any specific rules or restrictions…" (no marker)
- "**Insurance and Liability**: Ensure that any modifications comply with insurance requirements… You may also need to inform your insurance provider about the changes." (no marker)

| Point | Baseline | X |
|---|---|---|
| Conclusion to the yes / no question | None shipped (written, then dropped by code) | None shipped and **none written** |
| Private vs common portion | Art. 1045 with its condition; nothing on a wall inside her own portion; main walls absent | Art. 1045 with its condition; main walls named (the "This means" link does not follow); private portions get one uncited hedge ("might require approval"), not the "freer inside your own portion" the standard asks for |
| Majorities / consents | Syndicate approval; "a majority vote among co-owners [2]" (ungrounded); art. 1100 consents of syndicate and creditors (grounded, loosely applicable) | Syndicate consent only, cited to [3], which does not say it. **No majority, no co-owner consent, art. 1098-1102 unused although it was passage [1] at 72%** |
| Ungrounded claims | 1 sentence | 5: syndicate consent attributed to [3], "structural integrity", the declaration "often outlines…", and the two insurance sentences. No passage in either prompt covers renovation insurance (both contain only art. 1064.1, liability insurance) |
| Invented article number | None | None |
| Passages cited | [1], [2], [4] (`referenced_files` 3) | [3] only (`referenced_files` 1) |
| Length | 1,113 characters | 1,310 characters |

Grade: X 0.40 (baseline 0.45). X gains the main-wall mention; it loses the consents, the citation coverage and conciseness, and it answers partly from general knowledge, which the prompt forbids ("never answer such questions from general knowledge"). Read strictly, rubric point 1 could make this a fail; the baseline grader did not fail one ungrounded sentence, so I keep it at the bottom of partial.

What the confound explains and what it does not:
- Explained by retrieval: the uncited declaration point (X had no art. 1055-1058 passage to cite).
- Not explained by retrieval: ignoring art. 1098-1102 and 1100 (first passage in X), and the insurance paragraph (same instruction, same art. 1064.1 text in both prompts).

## Law turn: buyer (prompt equivalent)

- Baseline: "You can also request a statement of the common expenses due from the syndicate before purchasing the unit, which will inform you of any outstanding amounts. If this statement is provided to you within 15 days of your request, you are only responsible for the fees listed in that statement [1]." Then: "In summary, yes, you would be responsible for any unpaid condo fees…"
- X: "This obligation includes the payment of interest on those fees [1]. However, as a prospective buyer, you can request a statement of the common expenses due from the syndicate of co-owners. If the syndicate provides this statement within 15 days of your request, you are bound to pay those expenses [1]."
- Art. 1069: "the prospective acquirer is bound to pay the common expenses only if the statement is provided to him by the syndicate within 15 days of the request."

| Point | Baseline | X |
|---|---|---|
| 15-day rule | Present, with an inference the article does not make ("only … the fees listed in that statement") | Present, closer to the text, but without "only": the sentence states an obligation, not a protection |
| Reverse (no statement within 15 days, not bound) | Missing | Missing |
| Article named | No | No |
| Direct answer first | Yes | Yes |
| Markers | 1; first paragraph and summary uncited | 2; every claim cited |
| Interest | "if applicable" (not in the text) | Stated plainly |
| Repetition | Summary repeats paragraph 1 | None; 492 characters vs 835 |

Grade: equal at 0.75. X is cleaner and better grounded; the baseline says more clearly that the statement protects the buyer, with a loose paraphrase. Neither reaches the reverse.

## Lead sentence beside the code-written facts line

| Turn | Baseline draft -> shipped | X draft -> shipped | Result |
|---|---|---|---|
| 1 roster (lead OPTIONAL) | Filler intro, shipped | **Empty lead** | X better |
| 3 310 (OPTIONAL) | Merged singular, "all unions" | Merged singular, "all its associated unions" | Equal; both contradict "3 different units" in number |
| 6 310 (OPTIONAL) | Merged singular | Merged singular | Equal |
| 9 board (OPTIONAL) | Two sentences, one false; both cut by code (`one_sentence`, `not_recorded`) | **Empty lead**, nothing to cut | Shipped text equal; X did not need the code |
| 11 owner-arrears (ONE sentence) | Total + filler sentence; filler cut (`one_sentence`) | Total + **row-by-row sentence** ("The owners behind on their fees are Sofiane Chaouche (Unit 305), …"); cut (`one_sentence`) | Shipped text equal; both broke the one-sentence rule, X also the no-row-sentence rule |
| 12 supplier (no facts line, 1-2 sentences) | One sentence | Two sentences, second is filler; plus a tail after `{{table}}`, dropped | X shipped one filler sentence |
| 10 meetings (no facts line) | Two sentences | Two sentences | Equal |

- **Empty lead used:** baseline 0 of 4 turns that offer it, X 2 of 4 — the two list questions the instruction was written for. On 3 and 6 X's lead adds the amount, which is allowed.
- **`lead_trimmed`:** baseline 3 cuts on 2 turns (9, 11); X 1 cut on 1 turn (11).
- No hedge and no false sentence in any shipped lead of either run.

## Voice

- Supplier: baseline "You currently owe … to your suppliers" (same slip in p18, p19, p20); X "We currently owe our suppliers". The standard asks for "we owe".
- Roster: baseline "your team, Novo Gestion"; X has no sentence, so no voice either way.
- Team-info: "Your team" in both. No turn refers the user to her own team from outside in either run.
- Meetings: neither says "our" / "your team".

## Citation discipline, validation, retries

| | Baseline | X |
|---|---|---|
| Composition calls | 10 | 10 |
| Turns with `validation.retried` | 0 | 0 |
| `violations_first` / `violations_final` | empty on all | empty on all |
| Tear-down markers | [1], [2] x2, [4]; 4 of 4 points cited, 1 unsupported | [3] x2; 2 of 4 points cited, 1 unsupported |
| Buyer markers | 1 | 2 |

The validator passed X's tear-down answer although half of its points carry no marker; it does not require a marker per paragraph.

## `closing_dropped`

| Turn | Baseline | X |
|---|---|---|
| 7 tear-down | "In summary, you will likely need to confirm whether the wall is a common portion and seek the necessary approvals before proceeding with any demolition." **Harmful (mild):** the only conclusion | "Before proceeding, it is advisable to consult with the syndicate and possibly a professional to assess the structural implications and obtain necessary permissions." **Not harmful:** advice from general knowledge, not a conclusion. The answer now ends on the insurance paragraph |
| 8 buyer | None dropped; the "In summary, yes…" paragraph kept | None written |

X's missing conclusion on tear-down is not caused by the drop rule: X never wrote one.

## Row echo / padding

- Shipped padding, baseline: roster intro; buyer summary repeating paragraph 1.
- Shipped padding, X: the insurance paragraph (tear-down); "Here is a breakdown of the amounts owed to each supplier:" (supplier).
- Caught by code, baseline: board (2 sentences), owner-arrears (1 filler sentence).
- Caught by code, X: owner-arrears (row-by-row sentence, 81 output tokens vs 43); supplier (text after `{{table}}`, 53 output tokens vs 26; content not recorded).
- `row_echo_dropped`: absent in both runs.

## Language

English on all 13 turns in both runs; record names left in French as stored. No difference.

## Actions (not composed by the variant model)

- 11 turns: identical chips.
- Tear-down: baseline 2 follow-up chips (1 natural, 1 off-topic); X 1 chip, natural ("Are main walls of buildings considered common portions in a condominium?"). The writer (gpt-4o in both) was offered art. 1044 and 1046 in both runs and declined 1046 in X. Equal, 0.70.
- **Buyer: baseline 3 chips, X 0 (`follow_ups` proposed 0).** The follow-up prompt offered X only excerpts from uncited passages (art. 1071, 1063, 1076, 1092), all off-topic, and the writer declined them — correctly. The baseline was offered art. 1069 para. 3, 1068.1 and 1068.2 from the cited passage. `FollowUpWriter::excerpts()` picks those by word overlap between the answer and each paragraph; when the words do not "see" a used article, the whole cited passage counts as covered. X's shorter paraphrase most likely fell under that threshold. So this action loss is an indirect effect of the answer's wording on a code heuristic, not retrieval variance (same passages). Inferred from the code, not executed.

---

# 3 — Composition cost and latency

**Model name in the trace:** only `fingerprint.models.response_generation` distinguishes the runs (`openai/gpt-4o-mini` vs `openai/gpt-4o`). The per-turn `usage.models` is an unattributed list, `["gpt-4o-mini-2024-07-18", "gpt-4o-2024-08-06"]` on every composed turn of both runs (QU and QG / follow-ups already use both), so no trace field proves per call which model composed. The preflight checks only the QU and QG models.

| Composition stage (10 LLM-composed turns: 0, 1, 3, 6-12) | Baseline | X | Delta |
|---|---|---|---|
| Calls | 10 | 10 | 0 |
| Retries | 0 | 0 | 0 |
| Input tokens | 32,622 | 32,843 | +221 (tear-down passages +220, roster +1) |
| Output tokens | 643 | 596 | -47 |
| Total tokens | 33,265 | 33,439 | +174 |
| Latency sum | 16.84 s | 18.07 s | +1.24 s (+7%) |
| Latency mean per turn | 1.68 s | 1.81 s | +0.12 s |
| Latency max | 3.02 s (tear-down) | 3.74 s (tear-down) | +0.72 s |

Per turn (input / output tokens, ms):

| Turn | Baseline | X |
|---|---|---|
| 0 team-info | 2,228 / 24, 1,771 | 2,228 / 18, 1,336 |
| 1 roster | 2,724 / 19, 1,345 | 2,725 / 3, 1,267 |
| 3 310 | 2,558 / 17, 1,322 | 2,558 / 18, 1,455 |
| 6 310 clarified | 2,556 / 28, 1,450 | 2,556 / 21, 1,509 |
| 7 tear-down | 5,676 / 254, 3,017 | 5,896 / 274, 3,741 |
| 8 buyer | 6,109 / 167, 2,289 | 6,109 / 101, 2,088 |
| 9 board | 2,912 / 43, 1,502 | 2,912 / 3, 1,777 |
| 10 meetings | 3,100 / 22, 1,273 | 3,100 / 24, 1,476 |
| 11 owner-arrears | 2,471 / 43, 1,533 | 2,471 / 81, 1,769 |
| 12 supplier | 2,288 / 26, 1,335 | 2,288 / 53, 1,658 |

- The two empty-lead calls (3 output tokens) still took 1.27 s and 1.78 s: latency is dominated by the input, not the output.
- Turns 2 and 4 (export) and 5 (clarify) make no composition call in either run.
- Token volume is equal; the cost difference is the price per token. At list prices as I remember them (not in the trace: gpt-4o about $2.50 / $10 per million input / output, gpt-4o-mini about $0.15 / $0.60), composition goes from about $0.005 to about $0.09 for this part, roughly 17 times, or under one cent more per composed answer.

Other stages, for context (not the variant):

| | Baseline | X |
|---|---|---|
| Pipeline sum | 123.8 s | 148.0 s |
| Understanding sum | 72.9 s | 92.9 s (turn 2: 14.1 s, turn 5: 10.9 s, turn 6: 9.7 s) |
| Query generation sum | 18.5 s | 21.6 s |
| Follow-ups tokens | 2,225 | 2,056 |

Of the +24.2 s of pipeline time, 19.9 s are the understanding call (gpt-4o-mini in both runs) and 3.2 s query generation; composition accounts for 1.1 s.

---

# 4 — Verdict

**Answer points attributable to the composition model: about +0.15 on the sum (+0.012 on the mean), from two turns with equivalent prompts.**

| Turn | Delta | Attributable to the composition model? |
|---|---|---|
| Supplier | +0.10 | Yes, on a byte-identical prompt. "We owe our suppliers" against a slip the baseline model made in three graded runs out of three. |
| Roster | +0.05 | Yes. The prompt differs in one table-header token. Empty lead where the baseline wrote the forbidden intro. |
| Tear-down | -0.05 | **Not separable.** The prompt differs (passage set and order, QU variance). The general-knowledge paragraph and the unused art. 1098-1102 are the model's own choices; the missing declaration citation is retrieval. |
| Buyer | 0 | Equal answer on an equivalent prompt. |
| Other 9 | 0 | Shipped answers equal; on board and owner-arrears the code's lead cuts made them equal. |

- Counting tear-down against the variant, the net is +0.10; leaving it out, +0.15.
- **Action points: -0.15** (buyer, 3 chips -> 0), an indirect effect of the answer's wording on the follow-up excerpt heuristic.
- Net across both columns: about zero.

Confidence, given one run each:
- **Low that the answer gain is real in size.** On the same prompt the baseline model has moved buyer between 0.60 and 0.75 and tear-down between 0.30 and 0.50 across graded runs. A sum difference of 0.10-0.15 is inside that noise.
- **Moderate that two behaviours are real model effects:** the empty lead on list questions (2 of 4 offers taken vs 0 of 4) and the "we / our" voice on the supplier turn. Both follow instructions the baseline model has ignored repeatedly.
- **Moderate that the variant does not fix the law turns.** The roughly 0.55 points the baseline grading attributes to "a stronger composition model" (tear-down composition, buyer reverse) were not recovered: no conclusion, no majorities and general-knowledge padding on tear-down; no reverse and no article name on buyer. One run cannot exclude that it helps on average, but this run gives no sign of it.
- X also broke prompt rules that the code then repaired on two turns (row-by-row sentence, text after `{{table}}`), and shipped one ungrounded paragraph on a law turn that the validator did not flag.

What would settle it: three to five repeats per arm on the two law turns and the supplier turn, with the tear-down search query pinned so both arms compose from the same passages.
