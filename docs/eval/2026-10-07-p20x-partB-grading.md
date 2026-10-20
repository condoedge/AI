# Grade: p20x-1001-B (variant X) vs p20-1001-B (baseline), Part B

- Baseline: `CondoEdge/storage/eval/p20-1001-B.json`, generated 2026-10-06T23:03, graded in `ai/docs/eval/2026-10-07-p20-partB-grading.md` (0.864 answers / 0.889 actions, 14 / 0 / 0).
- Variant X: `CondoEdge/storage/eval/p20x-1001-B.json`, generated 2026-10-06T23:18. 14 rows, all `ok`, 0 void, 0 infra, 0 gate failures. Everything below is summed from the 14 rows.
- Same ai package sha (badd2f1, 592 dirty files). Host config sha 998a334f -> 1452c74b. The only model difference in the fingerprint: `response_generation` gpt-4o-mini -> **gpt-4o**. Understanding (gpt-4o-mini), query generation and follow-up writer (gpt-4o) and embeddings are unchanged.
- Rubric and strictness as the baseline grading: pass >= 0.70, partial 0.40-0.69, fail < 0.40; chips scored in the action score.
- **Result: X answers 0.857 (-0.007), actions 0.886 (-0.004), 14 pass / 0 partial / 0 fail.** Three turns move on the answer score (+0.05, -0.10, -0.05); eleven are equal. The net is inside the run-to-run noise the baseline model shows against itself.

---

# 1. Per-turn comparison

"Confounded" compares the two composition prompts of the turn. "Identical" means byte-identical after flattening the messages.

| Turn | Baseline ans / act | X ans / act | Answer | Confounded? | Why (decisive sentences) |
|---|---|---|---|---|---|
| rights t0 | 0.70 / 0.80 | **0.75 / 0.75** | better (+0.05) | **Yes, by retrieval.** Instructions identical; passages differ. Baseline had the fund passage ([5], art. 1071.1-1074.2); X did not (chunk 74 refused as `unsupported` by the freed-slot rule; X got art. 1038-1040 and 1081-1083 instead). Art. 1063-1066 and 1069 are in both prompts. | Baseline: "**You are entitled to a proportional share of the common expenses**" (a duty filed as a right), "If you **sell** or lease your unit, you must notify the syndicate" (art. 1065 misstated), "pay all common expenses … including any interest due at the time of acquisition" (art. 1069 applied to every co-owner). X: "If you **acquire a fraction or lease your private portion**, you must notify the syndicate within 15 days, providing necessary details such as the lessee's name and lease terms [1]" (art. 1065 correct), four rights that are rights and four duties that are duties, contribution only under Duties, art. 1069 not used, register access back ("consult the syndicate's register … obtain copies … for a reasonable fee [2]"). Against X: no article number anywhere (same as baseline), by-laws only as the condition of the first right, no disposal of the fraction, and a marginal fourth right from a syndicate passage that is loosely worded ("Your insurer cannot subrogate against you or other co-owners", art. 1075.1). Fund absent in both; in X it was not in the prompt. Action: three chips; the third ("What is the deadline to notify the syndicate if I lease my unit?") re-asks what the answer already states ("within 15 days"). |
| rights t1 | 0.85 / 0.75 | **0.75 / 0.75** | worse (-0.10) | **Partly.** Passages [1] to [4] carry the same text; [5] differs (uncited in both). The follow-up contract is stricter in X ("ALREADY COVERED: art. 1063-1070, 1075-1079 (passage [1] holds nothing new)"); the previous-turn block differs. Art. 1094 is in passage [4] in both prompts. | Baseline: "If you fail to pay your share of common expenses for more than three months, you may lose your right to vote … (art. 1094) [4]"; also an off-thread bullet that misattributes ("you are required to have third-party liability insurance as well (art. 1073) [2]") and an invented example ("like a private garden or rooftop"). X: no off-thread bullet, no invented example, both funds explained ("a contingency fund for major repairs and replacements, and a self-insurance fund to cover deductibles and certain repairs [2]"), consultation of the general meeting stated. But **no consequence of non-payment at all** (art. 1094 was in the prompt and is uncited), no article numbers (baseline had them), an unsupported clause under a citation ("which are crucial for the financial health of the condominium [2]"), and "you are **only** required to contribute to the maintenance and ordinary repairs of those portions" reverses the "only" of art. 1064. Still opens with two bullets from covered passage [1], against a prompt that says [1] holds nothing new. Range 0.75-0.80. |
| rights t2 | 0.75 / 0.90 | **0.70 / 0.90** | worse (-0.05) | No, identical | Baseline: "You have no unit linked to your account, so there are no records related to your contribution for this year. This is why nothing matched your question regarding the contribution amount." X: "**It seems that** you don't have a unit linked to your account, which is why I couldn't find any information about your contribution for this year." Neither names what was checked (`checked_kinds: [invoices, unions]`). X drops the redundant system-wording sentence but hedges the one fact the prompt marks "VERIFIED BY THE SYSTEM … lead with it, in plain words", and "couldn't find any information" reads as a failed search. No claim about the team in either. Range 0.70-0.75. |
| resilience t0 | 0.90 / 0.95 | 0.90 / 0.95 | equal | No, identical | Final answers byte-identical ("523 members." then the table). Baseline wrote "Here are the members of your team along with their roles:" and code cut it; **X wrote no lead** (3 output tokens, no `lead_trimmed`). |
| resilience t1 | 0.90 / 0.95 | 0.90 / 0.95 | equal | No (one alias in the sample header) | Baseline: "Here are the last login times for the members of your team, Novo Gestion." X: "Here is the list of team members with their last login details." Both are introductions, both kept, neither mentions the blank column. Header "Last Login" in X is the generator's alias, not composition. |
| resilience t2 | 0.90 / 1.00 | 0.90 / 1.00 | equal | No (alias in the previous-result sample) | Same grounded steps, one [1]. X is shorter (149 vs 158 tokens) and drops the member-versus-contact note; its last line names the button ("through the button attached below this answer"). |
| resilience t3 | 0.90 / 0.85 | 0.90 / 0.85 | equal | No composition call | Code-written. 523 rows, Name / Email / Role / Last Login. X ran one extra query-generation call that returned no query (7,923 tokens, 1.5 s) before exporting the recorded result; understanding-stage variance. |
| app-nav t0 | 0.90 / 1.00 | 0.90 / 1.00 | equal | No (X has one extra irrelevant passage, meetings.md) | Same steps. Baseline keeps "adding a team member is different from adding a contact … [1]"; X drops it and ends "You can access the Team Settings using the button attached below." 120 vs 155 tokens. |
| cold-roster t0 | 0.85 / 0.85 | 0.85 / 0.85 | equal | No composition call | Code-written. X's generator returned "User Name, Contact Email, Team Name" (the constant column of p19 is back); baseline Name, Email. Generator variance. |
| french t0 | 0.85 / 0.90 | 0.85 / 0.90 | equal | No, identical | Final answers byte-identical ("523 membres.", Membre / Rôle). Baseline wrote "Voici la liste des membres de votre équipe avec leurs rôles :" and code cut it; **X wrote no lead** (3 output tokens). |
| french t1 | 0.90 / 0.85 | 0.90 / 0.85 | equal | No composition call | Identical. |
| digest t0 | 0.90 / 0.90 | 0.90 / 0.90 | equal (better inside the step) | No, identical | Baseline: "Most recent meetings show no scheduled items." X: "The most recent meeting was the Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN on October 24, 2023." Closing: "Want me to go through the open tasks one by one?" vs "Need help tackling the 130 open tasks one by one?" The bills total is still stated twice. X adds "that need attention" and "several marked as high urgency" (true of the five sample rows). |
| digest t1 | 0.90 / 0.85 | 0.90 / 0.85 | equal | No (column aliases, one masking line) | Baseline: "Your last meeting was the annual general assembly for SDC 1240 SAINT-SÉBASTIEN, which took place on October 24, 2023, via ZOOM." X: "The last meeting was the "Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN," which took place on October 24, 2023, via ZOOM." Both restate name and place that the record block prints. Same "My profile" chip. |
| digest t2 | 0.90 / 0.90 | 0.90 / 0.90 | equal (better inside the step) | No, identical | Baseline: "Most recent meetings have no upcoming items scheduled." and "The **EXCEL** file with everything shown is attached below." X: "The most recent meetings took place in October 2023." and "The **Excel** file with everything shown is attached below." The prompt itself writes "EXCEL"; X normalised it. Bills total still twice. |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p20 baseline (gpt-4o-mini composition) | 0.864 | 14 / 0 / 0 | 0.889 |
| **p20x (gpt-4o composition)** | **0.857** (-0.007) | **14 / 0 / 0** | **0.886** (-0.004) |

- X answers, t0 to t13: 0.75, 0.75, 0.70, 0.90, 0.90, 0.90, 0.90, 0.90, 0.85, 0.85, 0.90, 0.90, 0.90, 0.90 (sum 12.00; baseline 12.10).
- X actions, t0 to t13: 0.75, 0.75, 0.90, 0.95, 0.95, 1.00, 0.85, 1.00, 0.85, 0.90, 0.85, 0.90, 0.85, 0.90 (sum 12.40; baseline 12.45).
- Answer gain: rights t0 +0.05. Answer losses: rights t1 -0.10, rights t2 -0.05.
- Action loss: rights t0 -0.05 (a redundant third chip from the follow-up writer, which is not the changed model).
- Rights t2 now sits on the pass line (0.70); rights t0 moved off it.

---

# 2. Per-defect comparison

| Point | Baseline (gpt-4o-mini) | X (gpt-4o) | Verdict |
|---|---|---|---|
| Rights t0: rights and duties filed correctly | Two duties under Rights; contribution in both lists | 4 rights, 4 duties, none misfiled, no mirrored lists | X better; same passage [1] in both prompts |
| Rights t0: contingency fund | Absent, with art. 1072 in passage [5] | Absent; **the fund passage was not in the prompt** | **Not tested.** The baseline's defect #6 (fund retrieved but unused) cannot be judged from this run |
| Rights t0: art. 1065 (who notifies) | "If you sell or lease your unit, you must notify" (wrong person) | "If you acquire a fraction or lease your private portion, you must notify … the lessee's name and lease terms" (correct) | X better; p19 and p20 both misstated it |
| Rights t0: art. 1069 (who owes) | Stated as every co-owner's duty | Not used (art. 1069 was in passage [2]) | X avoids the error by omission |
| Rights t0: article numbers named | None | None | Equal; no gain |
| Rights t0: nothing invented | Nothing invented | Nothing invented; art. 1075.1 loosely worded and a doubtful "main right" | Equal, one soft spot in X |
| Rights t0: by-laws, access for work | Both as explicit duties | Access for work as a duty; by-laws only as the condition of free use | Baseline slightly better |
| Rights t1: opens with what is new | No, two bullets from art. 1064 | No, two bullets from art. 1064, although X's prompt says passage [1] holds nothing new | Equal; no gain |
| Rights t1: off-thread bullet | Insurance bullet, misattributed to art. 1073 | None | X better |
| Rights t1: grounded | "like a private garden or rooftop" invented; "may lose" softens art. 1094 | No invented example; one unsupported clause under [2]; "only" misplaced on art. 1064 | X slightly better |
| Rights t1: consequences of non-payment | Loss of vote stated, art. 1094 [4] | **Absent** (art. 1094 in the prompt, uncited) | X worse; this is the turn's loss |
| Rights t1: article numbers | Named on every bullet | None | X worse |
| Rights t2: names what was checked | No | No | Equal; no gain |
| Rights t2: hedge, system wording | System wording ("This is why nothing matched your question") | Hedge ("It seems that"), "couldn't find any information" | X slightly worse |
| How-to turns | Grounded, one [1], contact note kept | Grounded, one [1], shorter, contact note dropped, button named | Equal |
| Digest framing sentences | "Most recent meetings show no scheduled items." | Names and dates the most recent meeting; all totals correct | X better, inside the grade step |
| Digest closing offer | One question (t0); one export line with "EXCEL" (t2) | One question (t0); one export line with "Excel" (t2) | X better on t2 wording |
| Roster lead, EN (resilience t0) | Introduction written, cut by code (`restatement`) | **Empty lead used**, nothing to cut | X better at the model; same final text |
| Roster lead, FR (french t0) | Introduction written, cut by code (`restatement`) | **Empty lead used** | X better at the model; same final text |
| Roster lead with a new column (resilience t1) | Introduction, kept | Introduction, kept | Equal; the blank column is still not mentioned |
| `lead_trimmed` | 2 cuts | 0 cuts (nothing written on 2 of 3 turns) | Empty option used 2 of 3 (baseline 0 of 3) |
| French quality | Only French the model wrote was the cut lead | The model wrote no French at all | Not exercised by the variant |
| Citation validation | 0 violations, 0 retries, 0 patches | 0 violations, 0 retries, 0 patches | Equal |
| Composition calls and retries | 11 calls, 1 per LLM turn, 0 retries | 11 calls, 1 per LLM turn, 0 retries | Equal |
| `closing_dropped` | 2 (rights t0, t1), both platitudes | 2 (rights t0, t1), both platitudes | Equal; gpt-4o still writes the closer |
| Padding / closers | Platitude closers (dropped by code) | Platitude closers (dropped by code); one padded clause survives on t1; law and how-to answers 5-23% shorter | Roughly equal |

## Leads the model wrote (X)

| Turn | Lead | Cut? | First thing the user sees |
|---|---|---|---|
| resilience t0 | none (3 output tokens, the placeholder only) | nothing to cut | "523 members." |
| french t0 | none (3 output tokens) | nothing to cut | "523 membres." |
| resilience t1 | "Here is the list of team members with their last login details." | Kept | the lead |
| digest t1 (record) | "The last meeting was the "Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN," which took place on October 24, 2023, via ZOOM." | Kept (no rule applies) | the lead |

The trace does not store the raw model output; the empty leads are inferred from 3 output tokens and the absence of `lead_trimmed`.

## Closing drops (X)

| Turn | Dropped text | Verdict |
|---|---|---|
| rights t0 | "These rights and duties ensure the smooth operation and maintenance of the condominium while protecting the interests of all co-owners." | GOOD. Platitude; no conclusion or caveat lost. |
| rights t1 | "These obligations ensure the proper maintenance and operation of the condominium, protecting both your investment and the community's shared assets." | GOOD. Platitude. |

## Differences that are NOT the variant (other stages)

- **Rights t0 passages.** Search query "co-owner rights and duties" (baseline "main rights and duties co-owner") and different probe wording, both from gpt-4o-mini. X merged chunks 66 and 69, filled with chunk 88 and refused chunk 74 (the fund) as unsupported.
- **Rights t1 contract.** X's t0 cited three passages, so the covered set grew to art. 1063-1070 and 1075-1079.
- **Rights t0 chips.** Three chips in X against two; written by the follow-up writer (gpt-4o in both runs) on code-chosen excerpts.
- **Rights t1 chip.** "What does the declaration of co-ownership include regarding common expenses?" (excerpt art. 1052, which does not answer it; art. 1053 in the same passage does). Same 0.75 as baseline.
- **Column aliases.** "Last Login" (resilience t1, t3), "Meeting Name / Date" (digest t1), "User Name / Contact Email / Team Name" (cold roster): query generation.
- **Resilience t3.** One extra query-generation call in X.

---

# 3. Composition cost and latency

Model recorded: the fingerprint gives `response_generation` openai / gpt-4o-mini (baseline) and openai / gpt-4o (X). The per-turn `usage.models` list resolves these to `gpt-4o-mini-2024-07-18` and `gpt-4o-2024-08-06`. The trace has no per-stage model field; the attribution rests on the four turns whose only LLM calls are understanding and composition (resilience t2, app-nav t0, digest t0, digest t2): baseline lists only gpt-4o-mini there, X lists both. French t1 (understanding only) lists only gpt-4o-mini in both runs.

| Composition stage | Baseline | X |
|---|---|---|
| LLM calls | 11 (1 per turn on 11 turns; 3 export turns have none) | 11 (same turns) |
| Retries | 0 | 0 |
| Input tokens | 39,295 | 39,711 (+416, all from prompt differences upstream) |
| Output tokens | 1,649 | 1,506 (-143, -9%) |
| Latency, 11 LLM turns: mean / median / max | 2.96 s / 2.49 s / 5.72 s | 2.21 s / 2.17 s / 4.13 s |
| Latency, all 14 rows: mean | 2.37 s | 1.79 s |

Per turn (input / output tokens, composition latency):

| Turn | Baseline | X |
|---|---|---|
| rights t0 | 6,154 / 420, 5.68 s | 5,759 / 400, 3.83 s |
| rights t1 | 7,516 / 392, 5.72 s | 7,999 / 331, 4.13 s |
| rights t2 | 1,309 / 47, 2.51 s | 1,309 / 40, 1.63 s |
| resilience t0 | 2,723 / 15, 1.25 s | 2,723 / 3, 1.28 s |
| resilience t1 | 3,145 / 20, 1.60 s | 3,144 / 16, 1.41 s |
| resilience t2 | 3,036 / 158, 2.82 s | 3,026 / 149, 2.17 s |
| resilience t3 | no call, 0.24 s | no call, 0.18 s |
| app-nav t0 | 2,592 / 155, 2.13 s | 2,844 / 120, 2.31 s |
| cold-roster t0 | no call, 0.25 s | no call, 0.39 s |
| french t0 | 2,576 / 16, 1.47 s | 2,576 / 3, 1.31 s |
| french t1 | no call, 0.17 s | no call, 0.15 s |
| digest t0 | 2,532 / 187, 2.49 s | 2,532 / 215, 2.40 s |
| digest t1 | 5,172 / 42, 1.44 s | 5,259 / 42, 1.69 s |
| digest t2 | 2,540 / 197, 5.43 s | 2,540 / 187, 2.17 s |

- X was not slower on any turn by more than 0.25 s and was 1.6 to 1.9 s faster on the two law turns. The baseline's 5.43 s on digest t2 is an outlier on an identical prompt, so part of the mean gap is API variance.
- Whole run: 165,345 tokens and 36 calls in X against 156,576 and 35. The difference is the extra query-generation call on resilience t3 (7,964 tokens), not composition. Pipeline mean 9.64 s against 9.96 s (max 16.7 s against 18.4 s).
- Money: token volume is the same, so the cost ratio is the price ratio of the two models. At the list prices I recall (gpt-4o-mini $0.15 / $0.60 per million input / output; gpt-4o $2.50 / $10.00) composition for these 14 turns costs about $0.007 in the baseline and about $0.114 in X, roughly 16 to 17 times more. **These prices are from memory and were not checked; cached-input discounts are ignored.**

---

# 4. Verdict

**Attributable to the composition model: about -0.10 answer points over the 14 turns (mean -0.007), with a plausible range of -0.15 to +0.05. That is no measurable gain.**

| Turn | Delta | Attributable to composition? | Confidence |
|---|---|---|---|
| rights t0 | +0.05 | Yes for the part scored: filing and art. 1065 rest on passage [1], which both prompts contain. The fund is confounded and not counted either way. | Medium-low |
| rights t1 | -0.10 | Mostly: art. 1094 sat in the same passage [4] in both prompts and X did not use it. The contract and history differ. | Low-medium |
| rights t2 | -0.05 | Yes, identical prompt. One hedge word decides it. | Low (wording variance; the baseline model moved by the same 0.05 between p19 and p20 on this prompt) |
| other 11 turns | 0 | Final text identical or equivalent | High |

Confidence in the sign of the net: **low**. One run each, and the three moving turns are the three rights turns, which also moved by 0.05 each between p19 and p20 with the baseline model (t0 and t2 as pure model variance on unchanged instructions). A net of -0.10 on 14 turns is inside that noise. Confidence that the variant does **not** deliver a gain of 0.3 points or more on Part B: reasonably high, because eleven turns are settled by code or already at 0.85-0.90 and cannot move.

What looks like a real model effect, independent of the scores:
- **The empty lead.** gpt-4o used it on 2 of 2 list turns; gpt-4o-mini has never used it (0 of 3 here, and the baseline grading records the same). The code cut already hides this, so it is worth 0 points.
- **Fidelity on the overview.** Correct notifier under art. 1065 and correct filing of rights and duties, on passages both runs share.
- **Digest framing.** Factual sentences instead of the awkward meetings line; "Excel" instead of the prompt's "EXCEL". Inside the grade step.
- **Shorter and faster.** 9% fewer output tokens, law turns 1.6 to 1.9 s faster.

What the stronger model did **not** fix:
- Article numbers (none on either law turn; the baseline had them on t1).
- Opening the deepening turn with the covered rule, even with a stricter contract.
- The uncited platitude closer on both law turns (code still drops it).
- Naming what was checked on the own-data empty answer.
- The introduction lead on the column-augmentation turn, and silence on the blank column.

What this run cannot answer: whether gpt-4o would put the contingency fund in the overview when its passage is in the prompt. That was the baseline's main "needs a stronger composition model" item, and retrieval did not deliver the passage to X on rights t0. On rights t1, where the passage was present and on topic, X explained both funds well. A repeat of rights t0 with the fund passage in the prompt is the one measurement that would change this verdict.
