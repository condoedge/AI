# Grade: p26-1001-B (Part B) vs p25 and p24

- Run: `CondoEdge/storage/eval/p26-1001-B.json`, generated 2026-10-07T16:53. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3 "Novo Gestion", owns no unit). 0 void, 0 infra, 14 rows `ok`, 16 / 16 harness gates passed, `security_gate.passed` 14 / 14.
- Rubric and strictness as p14 to p25. Pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action 0-1. Follow-up chips are scored in the action score.
- Same ai package sha (badd2f1), dirty tree 666 -> 677 files. Host config sha 03be375a -> bc70a847 (the entity configs changed in removal round 3). Models unchanged.
- Top-level counters describe one chunk only (99,322 tokens / 21 calls = rows 0-6). Summed from the 14 rows: **172,739 tokens, 36 LLM calls** (p25: 171,375 / 36).
- Headline: answers 0.882 -> **0.875**, actions 0.907 -> **0.900**, **14 pass / 0 partial / 0 fail** (p25: 14 / 0 / 0). Two passes sit on the 0.70 line (rights t0, rights t1).
- Removal check (A): **clean**. All 14 routes equal p25's. Exactly one schema line differs in the six query-generation prompts (cold-roster: the ContactDirectory card lost `(ContactDirectory)-[:CONTACT_TYPE]->(ContactType)`), and that query is byte-identical to p25's. Both digest composition prompts and both digest metadata blocks are byte-identical to p25's: nine sources, 0 failed, 0 skipped, eight sections, same totals, same 53 table lines, same chips. No column that p25 showed is missing; the four undeclared foreign keys appear in no prompt or trace of either run.
- Document map (B): the reader ran on 1 of 14 turns (rights t0), gate **closed**, **no QUESTION ANALYSIS block on any turn**. Rights t1 was treated as a follow-up by the new per-turn rule (`document_map: {fallback: follow_up}`, no reader call, probes + selection). Both how-to turns ran no reader. Part B again holds no live evidence for the gate-open path, so "the block carries no free text" cannot be confirmed here; what can be confirmed is that the reader's free-text `issue` sentence appears in no other prompt of its turn.
- The two answer drops (rights t0 -0.10, rights t1 -0.05) are composer output on passages that hold the missing rules; neither traces to the removals or to the reader. French t0 is back to three columns (+0.05).

---

# 1. Per-turn grading

| Turn | p24 ans / act | p25 ans / act | p26 ans / act | Verdict | Why (trace evidence) |
|---|---|---|---|---|---|
| rights t0 | 0.70 / 0.80 | 0.80 / 0.85 | **0.70 / 0.85** | pass (borderline) | Reader ran, gate closed. Passages: [1] art. 1062-1069 (merged), [2] 1070-1070.2, [3] 1093-1098, [4] 1078-1082, [5] 1052-1056 (p25: 1038-1040). Filing correct (4 rights, 4 duties), "art. N" and a marker on every point, closer dropped by code, no indivision. Standard's elements: free use yes; contribution yes; by-laws yes; **contingency fund absent** (not in the prompt, as p24 and p25); **no interference with work (art. 1066) absent** although the article is in [1] (p24, p25 had it). **Art. 1065 misread again: "If you sell or lease your unit, you must notify"** (the notice is on the person who acquires; p25 had it right). Gains: the vote is now stated with its condition ("provided you are up to date with your common expenses", art. 1094); the register right is used (art. 1070). Action: two chips, both natural and answerable from their excerpt. |
| rights t1 | 0.80 / 0.85 | 0.75 / 0.90 | **0.70 / 0.80** | pass (borderline) | Probes + selection path, same five passages as p25. New and correct: board fixes the annual contribution and notifies the amount (art. 1072) [2]; consultation of the meeting before a special contribution (art. 1072.1) [2]; loss of vote after three months **stated firmly** ("they lose their right to vote", art. 1094) [5]. Against: **the contingency fund is not named at all** (p25 named both funds; art. 1071 and 1072 are in [2]); two ungrounded embellishments ("such as repairs, utilities, and insurance"; "special assessments for unforeseen expenses or major repairs"); an off-thread, already-covered insurance bullet (art. 1064.1); one restated bullet (art. 1064) and an introduction; **platitude closer shipped** (not dropped by code this run); "after consultation with the general meeting" on the annual fixing left out; citations written "(1064)" without "art.". Action: two chips (p25: three); one natural (what the fund is for), **one already answered** (how the amount is notified). |
| rights t2 | 0.85 / 0.90 | 0.85 / 0.90 | **0.85 / 0.90** | pass | Same two sentences as p25, then the checked line once: "Checked: invoices for your units — nothing matched your question." (p25's "invoices, unions" residual is gone because the query no longer joins Union). `empty_result_check {scope: own, ownership_check {Unit, count 0}}`, 0 rows, no $0, no claim about the team, Receivables report chip. Query: year filter yes; unit branch only through the declared `[:CUSTOMER]` relationship, so there is no OR (p25: both branches). Latent step back, scores nothing with this user. |
| resilience t0 | 0.90 / 0.95 | 0.90 / 0.95 | **0.90 / 0.95** | pass | Query, composition prompt, answer (sha 999b10f7) identical to p25. |
| resilience t1 | 0.90 / 0.95 | 0.90 / 0.95 | **0.90 / 0.95** | pass | Query, composition prompt and table identical; 100 of 523, "Last Logged At", facts line unchanged. Lead now "I have included the last login information for the team members." (not trimmed). Header is the charged defect. |
| resilience t2 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | `navigation_target: own_team`, probes skipped, no reader, no selection, no follow-up call, two calls. Guide only, four grounded steps, contact distinction cited [1], Team settings button. Closing sentence now names the user menu, not "the link below". Roster survives (t3 exports 523). |
| resilience t3 | 0.95 / 0.85 | 0.95 / 0.85 | **0.95 / 0.85** | pass | `generation_skipped: true`, one call. File rows identical to p25 (hash 377b276c, 523 rows, Name / Email / Role / Last Logged At). Title "Team Members Last Login" (QU subject label, capitalised this run). |
| app-nav t0 | 0.90 / 1.00 | 0.90 / 1.00 | **0.90 / 1.00** | pass | QU prompt and composition prompt byte-identical to p25. Target set, button attached (`role: identity`), two help documents, no law passage, two calls. Four grounded steps, [1] cited; one extra grounded sentence on roles and removal (in the guide, uncited); pointer sentence names "Team settings". |
| cold-roster t0 | 0.90 / 0.85 | 0.90 / 0.85 | **0.90 / 0.85** | pass | Query, answer (sha e02078fb) and file identical to p25 (row hash d37d0394, 523 rows, User Name / Contact Email). The one prompt line the removal changed is in this turn; no effect. |
| french t0 | 0.90 / 0.90 | 0.85 / 0.90 | **0.90 / 0.90** | pass | Answer byte-identical to p23 and p24 (sha badadbf9): entirely French, "523 membres.", **Nom / Courriel / Rôle**, truncation line, both chips in French. QU returned the plural concepts again. |
| french t1 | 0.95 / 0.85 | 0.95 / 0.85 | **0.95 / 0.85** | pass | `generation_skipped: true`, one call. "Champs inclus : Nom, Courriel, Rôle.", 523 rows, French title and copy. |
| digest t0 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Composition prompt and metadata byte-identical to p25: nine sources, 0 failed, 0 skipped, eight sections, totals 130 / 75 / 189 / 13 / 36 / 8, bills $11,493.17, 53 table lines identical, 16 chips. Framing sentences reworded, plainer ("There are 75 overdue receivables."; "No meetings are currently scheduled."). |
| digest t1 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | Query, enrichment query, row and composition prompt identical; no digest block. Lead now quotes the exact name and adds "You were the president of this meeting." (true: the row's president is the user). Record block unchanged. |
| digest t2 | 0.90 / 0.90 | 0.90 / 0.90 | **0.90 / 0.90** | pass | `digest_request: true`, `export_format: excel`; metadata byte-identical to p25, `export_followup_offered: excel`, Download Excel chip, 17 chips, same 53 table lines, single closing line "The EXCEL file with everything shown is attached below." |

## Means and counts

| Run | Answer mean (14) | Pass / Partial / Fail | Action mean (14) |
|---|---|---|---|
| p24 | 0.882 | 14 / 0 / 0 | 0.900 |
| p25 | 0.882 | 14 / 0 / 0 | 0.907 |
| **p26** | **0.875** (-0.007 vs p25) | **14 / 0 / 0** | **0.900** (-0.007 vs p25) |

- p26 answers, t0 to t13: 0.70, 0.70, 0.85, 0.90, 0.90, 0.90, 0.95, 0.90, 0.90, 0.90, 0.95, 0.90, 0.90, 0.90 (sum 12.25).
- p26 actions, t0 to t13: 0.85, 0.80, 0.90, 0.95, 0.95, 1.00, 0.85, 1.00, 0.85, 0.90, 0.85, 0.90, 0.90, 0.90 (sum 12.60).
- Against p25, answers: rights t0 -0.10, rights t1 -0.05, french t0 +0.05. Actions: rights t1 -0.10.
- The answer moves are single turns in a single run. Rights t0 is inside the spread p22 to p25 showed (0.70, 0.80, 0.70, 0.80). Rights t1 at 0.70 is 0.05 below the lowest of those four runs (0.85, 0.75, 0.80, 0.75).

---

# 2. Document turns

## Which path ran

| Turn | Path | Reader picks (role) | Gate | Final passages | Calls p25 -> p26 | Tokens p25 -> p26 | Seconds p25 -> p26 |
|---|---|---|---|---|---|---|---|
| rights t0 | **reader** (no probe writer, no selection) | chunk 58 art. 1062-1063 (general_rule), 59 art. 1064 (general_rule), 60 art. 1064.1-1065 (general_rule), 61 art. 1066 (condition_or_exception), 62 art. 1067 (consequence). `answerable: yes`, map 90 passages / 28,598 chars, not cut. Same five picks and roles as p25, governing rules first | **closed**, `opened_by: []` | [1] art. 1062-1069 (chunks 58 + 61 + 64 merged), [2] 1070-1070.2, [3] 1093-1098, [4] 1078-1082, [5] 1052-1056 | 4 -> 4 | 19,329 -> 20,243 (+914) | 18.0 -> 17.6 (file context 4.8 -> 4.4) |
| rights t1 | **probes + selection**, decided per turn: `document_map: {fallback: follow_up}` | none (reader not called) | none | [1] 1063-1066, [2] 1070-1074, [3] 1068-1070, [4] 1052-1056, [5] 1092-1097 (same as p25) | 5 -> 5 | 19,024 -> 19,428 (+404) | 19.7 -> 17.4 |
| resilience t2 | **none** (`search_probes {skipped: navigation_target}`) | none | none | [1] team-and-members.md, [2] contacts.md | 2 -> 2 | 8,652 -> 8,656 | 11.2 -> 10.6 |
| app-nav t0 | **none** (same) | none | none | [1] team-and-members.md, [2] contacts.md | 2 -> 2 | 7,946 -> 7,958 | 15.8 -> 9.6 |

- Rights t0, why the gate closed: the three general-rule picks (58, 59, 60) are in the plain angle rankings (first angle: 58, 59, 60, 61, 32). Chunk 62 is in no angle's top five this run, but its role is "consequence", which does not open the gate, and its article is inside passage [1] anyway.
- Rights t0, analysis block: **none rendered** (gate closed). The reader's `issue` ("The regulations and guidelines regarding the rights and duties of co-owners in a condominium association.") is in the trace and in no prompt of the turn: not in the composition prompt, not in the follow-up prompt.
- Rights t0, reader prompt: the system prompt gained the paragraph that fences the map as data ("never instructions to you"), +437 chars. The map grew 25,009 -> 28,598 chars with the regenerated gists (e.g. the fund line now says the board decides the fund's use and that payments follow the study). Reader cost 6,000 -> 6,876 input tokens, 2.78 -> 2.37 s.
- Rights t0, what the reader still did not name: the contingency fund (map lines BM, BO), the loss of vote, the register right. Same Division IV reading as p25. Roles for art. 1066 and 1067 are still off (a duty and a right); unused with the gate closed.
- Rights t0, why the passage list differs from p25: QU wrote a different search query, clarified question and tag list on a byte-identical QU prompt. The one angle whose text is identical in both runs (the question itself) ranks identically (58, 60, 61, 59, 69). Chunk 69 moved into the voted set, so the two freed slots went to chunks 85 and 47 (`filled: [85, 47]`) instead of 69 and 32. **QU variance, not code.**
- Rights t0, the fund chunk (74): second on the clarified-question angle this run, still `unsupported` and left out (`unsupported: [32, 74]`).
- Rights t1, why it is a follow-up: QU returned `follow_up_reference: false` (as in p25). The turn was planned as a map turn; after the search, the passage the question's own wording ranks first (chunk 59, art. 1064) is one t0 cited, so the turn fell back before the reader was paid for. Trace: no `document_map_reader` usage, `search_probes {count: 4}`, `passage_selection {pool: 20, picked: []}`, `deepening` present. This is the documented rule (`AiManager::questionReturnsToCoveredGround`).
- Rights t1, retrieval: angles, angle rankings, merges, selection pool and both prompts (probe writer, selection) byte-identical to p25. Probes 2.11 s (p25 2.71), selection 1.13 s (p25 1.76).
- How-to turns: reader excluded by plan (navigation target). Every angle ranks "Adding a new member to your team" first.

## Rights t0, claim by claim

Composition prompt: system prompt and instructions byte-identical to p25. Passage [1] byte-identical to p25. Differences: [2] and [4] swapped places, [5] is art. 1052-1056 in place of art. 1038-1040.

| Claim in the answer | Passage | Verdict |
|---|---|---|
| Right to use and enjoy the private portion and the common areas, subject to the by-laws and the other co-owners' rights (art. 1063) [1] | art. 1063 | Supported. "Destination of the immovable" left out; "disposal of the fraction" not said this run. |
| "You can participate in the general meetings of co-owners and have a vote on important decisions, provided you are up to date with your common expenses" (art. 1094) [3] | art. 1094 (deprived of the vote after more than three months unpaid), art. 1096-1098 | Supported, and the condition is now said (p25: not said). "Up to date" is tighter than the article's three months. The pin is still the article that removes the vote. |
| Right to access the syndicate's registers: minutes, financial statements, by-laws (art. 1070) [2] | art. 1070 (register kept at the disposal of the co-owners), art. 1070.1 (consultation, copy) | Supported. New this run. |
| "If you suffer a loss due to work done by the syndicate, you may be entitled to compensation" (art. 1067) [1] | art. 1067 | Supported. Kinds of injury and the co-owners-who-did-the-work branch left out. |
| Duty to contribute in proportion to the value of the fraction (art. 1064) [1] | art. 1064 | Supported. Restricted-use rule not said (the first chip picks it up). |
| Third-party liability insurance (art. 1064.1) [1] | art. 1064.1 | Supported. |
| "**If you sell or lease your unit**, you must notify the syndicate within 15 days, providing details about the new occupant" (art. 1065) [1] | art. 1065: the person who **acquires** notifies; the co-owner who leases notifies | **Misapplied** for the sale half (p24's error; p25 was right). Lease half supported. |
| "You must adhere to the by-laws ..., which govern the use and maintenance of both private and common portions" (art. 1054) [5] | art. 1054 (what the by-laws contain), art. 1063 (compliance) | Supported; the duty itself is art. 1063, the pin describes the by-laws. |

- Ungrounded claims: 0. Misapplied rules: 1 (art. 1065 "sell"). p25: 0 and 1 mild.
- Filing: correct. Rights 4, duties 4.
- Art. 1063-1066: 1063, 1064, 1064.1, 1065 used. **Art. 1066 not used**: the standard's "allowing necessary access for work" is missing (p24 and p25 both had it). Art. 1067 used; art. 1068 (revision) not used.
- Contingency fund (art. 1071-1072): **not in the prompt, not in the answer.**
- Indivision: none. No Chapter II heading in the map; no indivision wording in the prompt or the answer.
- Citations: [1] five times, [2], [3], [5] once each. Article written "art. N" on every point (p25: "§N").
- Closer dropped by code: "These rights and duties are designed to ensure a harmonious living environment and the proper management of the condominium."

What moved and why:

- The instructions and passage [1] did not change, so the lost work duty and the "sell" reading are composer output on the same text. Variance.
- p25 folded by-laws and work into one duty pinned on art. 1066. With art. 1054 present in [5] this run, the composer pinned by-laws there and wrote no work duty. The trace cannot show that one caused the other.
- The reader's picks contributed nothing (gate closed), as in p25.

## Rights t1, claim by claim

| Claim in the answer | Passage | Verdict |
|---|---|---|
| Contribution in proportion to the relative value of the fraction (1064) [1] | art. 1064 | Supported. Covered in t0 (restatement). |
| "Common expenses include costs related to the maintenance and operation of the co-ownership, **such as repairs, utilities, and insurance**" (1072) [2] | art. 1072: "the expenses arising from the co-ownership and the operation of the immovable, and the amounts to be paid into the contingency fund and the self-insurance fund" | First half supported. **The examples are not in any passage** (ungrounded), and the two funds the article does name are left out. |
| The board determines the annual contributions and notifies each co-owner of the amount due (1072) [2] | art. 1072 | Supported. **New.** "After consultation with the general meeting" and the payment date left out. |
| "If there are special assessments **for unforeseen expenses or major repairs**, the board must consult the general meeting of co-owners before deciding on any additional contributions" (1072.1) [2] | art. 1072.1 | Rule supported. **New** (p25 left it to a chip). The qualifier is the composer's. |
| "If a co-owner fails to pay their share of common expenses for more than three months, **they lose their right to vote** in meetings until all dues are settled" (1094) [5] | art. 1094 "is deprived of his right to vote" | Supported, **stated firmly** (p25: "may lose"). |
| Each co-owner must have liability insurance (1064.1) [1] | art. 1064.1 | Supported, **off the thread** and covered in t0. |
| Closing: "Understanding these obligations is crucial for maintaining the financial health of your condominium and ensuring compliance with the co-ownership regulations." | none | Platitude, shipped. |

- New material: yes, three of five points (annual fixing and notice, special contribution, loss of vote). Not a re-serving of t0.
- Ungrounded: 2 embellishments. Misapplied rules: 0.
- Contingency fund: **absent from the answer** (art. 1071 and art. 1072 are in [2]); it survives only as a chip. Assessment and collection rules (art. 1054) not used this run.
- The deepening block reads "ALREADY COVERED: art. 1054, 1063-1065, 1067, 1070, 1094" and "NEW: art. 1066 in [1]; art. 1071-1074 in [2]; ...". It is per article this run because t0 named its articles as "art. N" (`cited_articles`); p25's t0 wrote "§N", none was recognised, and whole passages counted (1062-1069, 1093-1098). No code change; it follows the previous answer's citation format.
  - Effect 1: art. 1094 is listed as covered, truthfully (t0 stated the voting condition). The composer still gave the consequence. Had it obeyed, the standard's loss-of-vote point would be gone, as p25's note warned.
  - Effect 2: art. 1054 is covered because t0 cited it for by-laws; t0 never mentioned assessment and collection. Coverage is per article, not per claim.
  - Effect 3: art. 1064 and 1064.1 are covered and both were restated, against the block.
- Composition prompt vs p25: the covered / new lines, and two more previous-turn excerpts (art. 1070.1, art. 1054) because t0 cited four passages instead of two (+465 input tokens).

## How-to turns

| Check | resilience t2 | app-nav t0 |
|---|---|---|
| How-to document only | Yes: team-and-members.md, contacts.md | Yes, same two |
| Law passage | None | None |
| Navigation target | `own_team` | `own_team` |
| Page button | Team settings (`role: identity`) | Team settings (`role: identity`) |
| Reader / probes / selection / follow-up call | none / skipped / none / none | none / skipped / none / none |
| Claims | Four steps (incl. the signed link), contact distinction with the pointer to the Contacts guide: all in [1] | Four steps, contact distinction, roles and removal on the same page: all in [1] |
| Ungrounded | 0 | 0 |
| Calls / tokens | 2 / 8,656 | 2 / 7,958 |

- Resilience t2 closes with "You can access the **Team Settings** directly through the user menu." (grounded; p25 said "through the link below").
- App-nav t0 closes with "For more details, you can refer to the **Team settings** section." It names the attached button once; the prompt says not to list the buttons. Not charged in p25 for the same kind of sentence; not charged here.
- QU on resilience t2: `intent: other` (as p25); target set.

## Every follow-up chip

| Turn | Chip | Source | Label | Answerable from its excerpt |
|---|---|---|---|---|
| rights t0 | Can the apportionment of my contribution to expenses be different? | art. 1064, second paragraph (tier 1) | **N** | Yes |
| rights t0 | How can I consult the register and documents as a co-owner? | art. 1070.1 (tier 1) | **N** | Yes (director present, reasonable hours, copy at reasonable cost) |
| rights t1 | How will I be informed about my contribution amount? | art. 1072, last paragraph (tier 1) | **O** (already answered) | Yes, but the answer above it already says the board "notifies each co-owner of the amount due"; only "without delay" and the date are new |
| rights t1 | What is the purpose of the contingency fund in a condominium? | art. 1071 (tier 2) | **N** | Yes (the answer's gap) |
| digest t0 | Go through Open tasks (most urgent first) | section | N | click not exercised |
| digest t0 | Go through Overdue receivables | section | N | click not exercised |
| digest t0 | Go through Units with expired insurance | section | A | click not exercised |
| digest t2 | the same three | section | A, A, A (the ask was a file) | click not exercised |

- Totals: 10 chips; 5 N, 4 A, **1 O** (p25: 11; 6 N, 5 A, 0 O). Knowledge turns: 3 N, 1 O (p25: 4 N, 1 A).
- Declined excerpts: art. 1062 and 1078 on t0; art. 1066 and 1093 on t1 (off the thread; right to decline).
- The O chip: the tier-1 excerpt on rights t1 is a sentence of art. 1072, an article the answer had just cited. In p25 tier 1 was art. 1072.1, which that answer had not used. The excerpt ranking does not set aside sentences of articles the answer named.

## Lead cuts, closing drops, echo drops

| Turn | What code removed | Rule | Verdict |
|---|---|---|---|
| resilience t0 | "Here are the members of your team along with their roles:" | `restatement` | **GOOD** |
| french t0 | "Voici la liste des membres de votre équipe avec leurs rôles :" | `restatement` | **GOOD** |
| rights t0 | "These rights and duties are designed to ensure a harmonious living environment..." | `closing_dropped` | **GOOD** |
| rights t1 | nothing | n/a | **Missed**: the platitude closer shipped (p25 dropped its twin). Probable cause, computed by hand from `AnswerValidator::closingReadsAsContent`, not traced: 4 of its 11 content words are passage words (obligations, financial, co-ownership, regulation), above the 0.30 grounded share. |
| rights t2 | nothing | n/a | The model did not copy the "Checked:" line this run; nothing to remove. |
| resilience t1 | nothing | n/a | "I have included the last login information for the team members." stayed. Cosmetic. |
| all | no `label_echo_dropped` | n/a | n/a |

Totals: 2 lead cuts, 1 closing drop, 0 echo removals, all GOOD. 0 HARMFUL. 1 closer that should have gone.

## Verification items

| Item | Result | Evidence |
|---|---|---|
| Analysis block | None on 14 turns | "QUESTION ANALYSIS" in no composition prompt; role labels in no composition prompt. |
| Rights t2 query | `MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL MATCH (n:Invoice)-[:CUSTOMER]->(u) WHERE n.budget_id IS NOT NULL AND substring(n.invoiced_at, 0, 4) = '2026' RETURN n.invoice_number AS invoice_number, n.due_amount AS amount, n.invoiced_at AS invoiced_at, n.id AS invoice_id LIMIT 100` | |
| ... year filter | **Yes** | `substring(n.invoiced_at, 0, 4) = '2026'` |
| ... OR parenthesised | **No OR in the query** | Unit branch only, through the declared `(Invoice)-[:CUSTOMER]->(Unit)`. `precedence_violations: []`, `schema_violations: []`, `repair_attempts: 0`. |
| ... both ownership branches | **No** (p25 yes, p24 no) | The contact branch of the declared scope is not in the query. |
| ... Checked line | Once: "Checked: invoices for your units — nothing matched your question." | `checked_kinds: [invoices]`; no `checked_line_echo`. |
| Digest t0 / t1 / t2 routes | digest / data, single record / digest with export | as p25 |
| Export follow-ups skip generation | **Both skipped** | resilience t3 and french t1: `recorded_result_export {generation_skipped: true, recorded_rows: 100}`, then `export {previous_result, rows: 523}`, one call each. |
| French roster columns | Nom / Courriel / Rôle on t0; "Champs inclus : Nom, Courriel, Rôle." on t1 | query `RETURN u.name AS name, u.email AS email, tr.role AS role` |
| Security | **Nothing wrong** | `safe_stop: false` on all 6 executions; `security_filter_applied: true` on the 6 queries and the enrichment query; no SecurityException text in any row. |
| Admission | **Nothing wrong** | No `admission` stage, no busy answer, no "I encountered an issue", `error: ''` and `errors: []` on all 14 rows. |
| Harness gates on app-nav and the digest turns | Still none | `gates: []`. |

---

# 3. Data, digest and export turns vs p25

| Turn | Route | QU fields that differ | Generation prompt diff | Generated query | Rows / total | Columns | Facts line | Sections / chips | Cause |
|---|---|---|---|---|---|---|---|---|---|
| rights t2 | data, empty result (same) | search tags only | date; the two previous-answer summaries; one previous-turn excerpt fewer (t1 cited 3 passages, p25 4). Schema lines identical | **Different**: unit branch via `[:CUSTOMER]`, no Union join | 0 / n.a. | invoice number, amount, invoiced at (p25: union name) | n.a. | Receivables report | **Generator variance** on a prompt whose schema and scope lines are p25's. Checked line lost "unions" as a result. |
| resilience t0 | data | subject label casing, clarified question | date; clarified question | Identical | 100 / 523 | Name / Email / Role | `count` | Team settings + Download Excel | None (answer byte-identical). |
| resilience t1 | data | entity order (Team, User, TeamRole), clarified question empty | date; Team card and its scope line moved up; no clarified-intent block | Identical | 100 / 523 | + Last Logged At | "Last Logged At recorded for 2 of the 25 shown." | same two | Lead sentence only (composer, identical prompt). QU variance had no effect. |
| resilience t3 | export, recorded | subject label casing, `intent: file_listing` (p25 `list`) | none (skipped) | none | 523 | Name, Email, Role, Last Logged At | n.a. | Download Excel | File title casing only. Row hash equal. |
| cold-roster t0 | export | concept "information" (p25 "info") | date; that concept line; **ContactDirectory card: `out:` lost `(ContactDirectory)-[:CONTACT_TYPE]->(ContactType)`** | Identical | 100 / 523 -> file 523 | User Name / Contact Email | n.a. | Download Excel | **The one card change of removal round 3 visible in Part B. No effect.** |
| french t0 | data | concepts plural again ("membres", "rôles"), search query | date; those two lines | **Different** from p25, identical to p23 / p24 | 100 / 523 | Nom / Courriel / Rôle (p25: Membre / Rôle) | `count` | Réglages de l'équipe + Télécharger Excel | **QU variance** on a byte-identical QU prompt, then the generator. `pii_masked_values` 1 -> 10 follows. |
| french t1 | export, recorded | entity order, tags | none (skipped) | none | 523 | Nom, Courriel, Rôle | n.a. | Télécharger Excel | Inherits t0's three columns. |
| digest t0 | digest | one tag | no generation | none | 37 rows, nine sources | 53 table lines identical | per-section totals identical | eight sections, 16 chips | Framing sentences only (composer, byte-identical prompt). |
| digest t1 | data, single record | `follow_up_reference` false (p25 true), clarified question | date; the previous digest's summary line; clarified question | Identical (and enrichment query) | 1 | record block identical | n.a. | one record chip | Lead sentence only. |
| digest t2 | digest + export | tags, clarified question | no generation | none | 37 rows, nine sources | 53 table lines identical | identical | eight sections, 17 chips incl. Download Excel | Framing sentences only. |

Removal round 3, direct answers:

- **Did any digest section change, fail or lose rows? No.** Per source, both digest turns, equal to p25: open_tasks 5 of 130; bills_pending_approval 0; bills_approved_unpaid 4; overdue_receivables 5 of 75; upcoming_meetings 3 (fallback); expired_unit_insurance 5 of 189; pending_contact_reviews 5 of 13; flagged_inbox_threads 5 of 36; work_orders_awaiting_acceptance 5 of 8. `failed_sources: []`, `skipped_sources: []`, `empty_sources` unchanged. Metadata sha equal on both turns.
- **Any column dropped as a bare code that p25 showed? No.** Roster tables (27 lines), digest tables (53 lines) and the two English export files hash equal to p25's. The French tables differ because the query returned e-mail again (a gain). `invoice_item_id`, `bill_item_id`, `servicing_id`, `service_element_id` occur in no prompt and no trace of p25 or p26 Part B.
- **Deleted labels in prompts:** p25 had one mention (ContactType, cold-roster). p26 has none.
- **Schema cards in Part B prompts:** of the seven changed cards only ContactDirectory is selected on any Part B turn. Account, BillDetail, Component, InvoiceDetail, Maintenance and File are in no generation prompt here, so Part B says nothing about them.
- QU system prompt byte-identical to p25 on all six first turns; later-turn QU prompts differ only in conversation summary lines.
- Generated queries: 6. Byte-identical to p25: 4. Different: 2 (rights t2: generator variance; french t0: QU variance, back to p24's query).
- Row counts and totals on every data turn: 0, 523, 523, 523, 523, 1, as p25.
- The first digest of the run took 1.86 s (p25 0.68 s); the second 0.44 s (p25 0.56 s). Same rows both times. Consistent with a cold cache after the node deletion; not measured further.

---

# 4. Regressions and open items, with traced cause

1. **Rights t0: work-access duty gone, art. 1065 "sell" back (-0.10 answer).**
   - Art. 1066 and art. 1065 are in passage [1], which is byte-identical to p25's; system prompt and instructions identical.
   - The passage list changed in [5] (art. 1052-1056 for 1038-1040) and in order, through QU variance on a byte-identical QU prompt.
   - The reader picked the same five chunks with the same roles; gate closed; no block. Not the reader. Not the removals (a document turn, no schema).
   - Composer variance. The "sell" reading is p24's error returning.
2. **Rights t1: fund not named, two embellishments, off-thread insurance bullet, closer shipped (-0.05 answer).**
   - Passages identical to p25's; art. 1071 and 1072 in [2].
   - The composer restated two covered articles against the deepening block and added examples no passage holds. Composer variance.
   - The closer was not dropped: see the lead / closing table (word-share rule, hand-computed).
3. **Rights t1: one chip already answered, two chips instead of three (-0.10 action).**
   - Tier-1 excerpt taken from art. 1072, which the answer had just used. The follow-up writer proposed 2, kept 2.
4. **Contingency fund absent on rights t0 (as p24 and p25) and now also on rights t1.**
   - t0: chunk 74 ranks second on one angle and is still left out as `unsupported`; the freed slots went to art. 1078-1082 (unused by the answer) and art. 1052-1056 (used once).
   - The reader does not name the fund for the rights question (same as p25, with the regenerated gists).
5. **Rights t2, latent: the query lost the contact branch.** Generator variance; nothing enforces the declared two-branch scope. No score effect with this user.
6. **Deepening block, latent (two sides).**
   - Per-article coverage depends on the previous answer writing "art. N". With "§N" it falls back to whole passages (p25).
   - Per-article coverage marks an article covered for one of its rules when the answer used another (art. 1054: by-laws cited, assessment and collection never said).
7. **Gate-open path not exercised in Part B.** 1 reader call in 14 turns, gate closed, no analysis block. The hardening of the block itself cannot be graded from this file.
8. **Tokens +1,364 (+0.8%).** Section 5.

Not regressions:

- French t0 back to Nom / Courriel / Rôle (+0.05): QU variance reversing p25's.
- Rights t2 checked line without "unions": follows from the query, not from a fix.
- Digest framing sentences, digest t1 lead, resilience t1 lead, export title casing: composer or QU wording on identical prompts.

Improved against p25's list:

- #1 French e-mail column: back.
- #2 vote loss hedged: stated firmly this run.
- #3 (art. 1093-1098 marked covered): narrower this run, see item 6.
- #6 "invoices, unions" checked line: gone this run.
- Rights t0 "§" for "art.", the unsaid voting condition, the unused register right: all three corrected this run (composer).

Unchanged: "Last Logged At" header; no harness gate on app-nav or the digest turns; "(that's you)" on two cells; "mon équipe" title; "EXCEL" in capitals; `t.id = 3` and no ORDER BY in roster queries; "rows" in the resilience t3 export line.

---

# 5. Tokens, calls and latency vs p25

| Stage | p25 input / output (calls) | p26 input / output (calls) | Delta (in + out) |
|---|---|---|---|
| understanding | 72,607 / 1,873 (14) | 72,626 / 1,894 (14) | +40 |
| search_probes | 677 / 136 (1) | 677 / 136 (1) | 0 |
| passage_selection | 3,992 / 5 (1) | 3,992 / 5 (1) | 0 |
| document_map_reader | 6,000 / 81 (1) | **6,876 / 76 (1)** | **+871** (longer gists, fenced prompt) |
| query_generation | 41,751 / 693 (6) | 41,638 / 660 (6) | -146 |
| composition | 39,370 / 1,628 (11) | 40,078 / 1,548 (11) | +628 (rights t1 +429, french t0 +182, rights t0 +40) |
| follow_ups (gpt-4o) | 2,398 / 164 (2) | 2,382 / 151 (2) | -29 |
| **Total** | **171,375** (36 calls) | **172,739** (36 calls) | **+1,364 (+0.8%), same calls** |

- By turn vs p25: rights t0 +914, rights t1 +404, french t0 +192, rights t2 -139; the other ten within 31 tokens.
- Calls per turn unchanged: 4, 5, 3, 3, 3, 2, 1, 2, 2, 3, 1, 2, 3, 2.
- First chunk (rows 0-6) 99,322 / 21 calls (p25 98,144 / 21); second chunk 73,417 / 15 calls (p25 73,231 / 15).

| Stage latency | p25 mean / median | p26 mean / median |
|---|---|---|
| understanding (14) | 5.7 s / 5.5 s (max 8.0) | **5.1 s / 4.8 s (max 7.1)** |
| file_context (4 knowledge turns) | 4.8, 6.7, 2.7, 4.8 s | 4.4, 6.8, 2.5, 2.4 s |
| of which probe call | none, 2.7 s | none, 2.1 s |
| of which passage selection | none, 1.8 s | none, 1.1 s |
| of which map reader | 2.8 s (rights t0) | 2.4 s (rights t0) |
| query_generation (6) | 2.5 s / 2.0 s | 2.2 s / 1.9 s (rights t2 3.3 s) |
| execution (6) | 0.53 s / 0.32 s | **0.23 s / 0.16 s** |
| empty_result_check | 0.03 s | 0.19 s |
| record_enrichment | 0.09 s | 0.03 s |
| recorded_result_export (2) | 0 s | 0 s |
| composition (14 rows) | 2.4 s / 1.8 s | 2.0 s / 1.6 s |
| follow_ups (2) | 2.0 s | 1.6 s |
| digest (2) | 0.68, 0.56 s | **1.86**, 0.44 s |
| **pipeline** | **11.1 s / 9.7 s** (max 19.7) | **9.7 s / 8.9 s** (max 17.6) |

- Per turn, p25 -> p26 (s): 18.0 -> 17.6, 19.7 -> 17.4, 13.9 -> 9.9, 9.5 -> 8.3, 9.4 -> 8.3, 11.2 -> 10.6, 5.3 -> 4.5, 15.8 -> 9.6, 10.1 -> 9.1, 9.4 -> 8.5, 5.4 -> 5.0, 8.4 -> 9.4, 9.9 -> 8.2, 9.4 -> 8.8.
- The mean turn is 1.4 s faster. 0.6 s of it is the QU call on prompts that are identical or differ by a summary line: API latency at the hour of the run. Execution is back at p24's level (0.23 s), which matches p25's cold-cache explanation.
- Only digest t0 is slower (+1.0 s): the digest stage itself, 0.68 -> 1.86 s, same nine queries and rows.
- App-nav -6.2 s: QU 8.0 -> 4.8 s and file context 4.8 -> 2.4 s with no LLM call in either run.
- No code path changed its call count in Part B.
