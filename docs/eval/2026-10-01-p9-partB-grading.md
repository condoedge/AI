# Grade: p9-1001-B (Part B) vs baseline / p1c

- Run: `CondoEdge/storage/eval/p9-1001-B.json`, 2026-10-01T01:33. 6 conversations, 14 turns, user 13 (Julie Rousseau, lead of team 3, owns no unit). Preflight ok, INFRA/VOID: none.
- Standards: `suite-partB.json` (current). "SC" = standard changed since the p1c grading (money / digest / rights-t2 scope guard).
- Thresholds: pass >= 0.70, partial 0.40-0.69, fail < 0.40. Action score 0-1 = right chips present, nothing misleading, no invented/wrong links.
- Harness: `label_counts` ok 13 / gate-failed 1 (cold-roster). The harness is still lenient. It labels digest t1 "ok", although the answer has two automatic fails. It also labels rights t2 "ok", although the turn carries a misleading "New invoice" chip.

## Per-turn table

| Conv | T | Question | Base | p1c | **p9** | Action | Verdict | Family | Why (trace evidence) |
|---|---|---|---|---|---|---|---|---|---|
| rights | 0 | Main rights/duties | 0.60 | 0.50 | **0.45** | 0.60 | partial | legal-grounding / retrieval | **Citation attribution is now correct.** Ref [1] is chunk 61 (1064-1068, centre 1066) and holds the claims for 1064 contribution, 1064.1 insurance, 1065 notify within 15 days and **1066 no interference with work (access for work, present)**. Ref [2] is chunk 64 (1067-1069) and holds 1067 indemnity and 1068 revision. **No "majority" error** this time. **Defects:** (1) **1063, free use and enjoyment of the private portion, is missing.** It is the core right. Chunk 59 (1063-1066) was not retrieved on t0; it only appears on t1. (2) **Rights and duties are miscategorised:** "Contribution to common expenses" and "Notification of changes" are listed under *Rights*. (3) 1069 is misread as a general duty ("pay all common expenses … including any interest due at the time of acquisition"); it is the acquirer's arrears liability. (4) No contingency fund. (5) Third chunk 82 (1075.1-1079, insurer subrogation) is noise. (6) A generic "Next Steps / Review the Code" block, and a **dangling "Here are the relevant links to the documents: - Quebec Civil Code - Rights and Obligations of Co-Owners"** whose link was stripped. Note: the "article-level" chunks are really about 5-article windows (chunk 61 = 1064 to 1068), not single articles. |
| rights | 1 | More on duty to contribute | 0.65 | 0.50 | **0.40** | 0.50 | partial (borderline fail) | legal-grounding / composition | **Gain:** **1072** is now retrieved (chunk 74: 1071, 1071.1, 1072). "Board fixes annual contribution after consulting the GM, incl. contingency and self-insurance funds; notified of amount and due date [2]" is correct and new. Proportional share and the restricted-use rule are cited correctly to 1064 [1]. **Losses:** (1) **Repetition padding**, which the standard treats as a fail. A "Responsibilities" block re-serves t0's 1065 notification and 1064.1 insurance, neither of which is about contribution. The cause is that chunk 59 is a 1063-1066 window, so the neighbours ride along. (2) Still **no consequences of non-payment**: 1094 loss of vote was not retrieved and there is no legal recovery. (3) The contingency fund (1071) is only mentioned in passing. (4) "Next steps are attached below for your convenience" is **false**, because no chip is attached (see Regressions). |
| rights | 2 | How much is my contribution this year | 0.00 | 0.50 | **0.40** | **0.00** | partial | honesty / actions | SC. The Cypher is `Contact{user_id:13}→ContactUnit→Unit→Invoice`, restricted to 2026, open status and `due_amount>=0.01`, and returns 0 rows. **Good:** no invented amount, and no "team owes nothing" claim, so the scope guard is respected. **Bad:** (1) It still never says she **owns no unit**; it says "couldn't find any matching records linked to you". (2) The query only looks for *unpaid* invoices, so even an owner who had paid would be told "nothing found", which conflates "billed" with "owing". (3) **"You can create a new invoice if needed."** comes with a **`new_invoice` chip**. That is a wrong and misleading next step for a person asking what she owes, and the `my_statement` door from p1c is gone. |
| resilience | 0 | Members with roles | 0.75 | 0.75 | **0.75** | 0.75 | pass | — | Deterministic table with **25** rows shown (p1c showed 10). "Showing 25 of 523 rows — ask for the full list or an export" is exact, and the gate passes. Only name and role are shown: the Cypher no longer projects email (p1c did), which is weaker on the proactivity bar. Roles are raw enums (lead/accountant/contact). It ends with "feel free to check your profile or team settings", an offer-style ending. Chips: Team settings is correct; My profile is irrelevant noise. |
| resilience | 1 | Add their last login | 0.85 | 0.85 | **0.80** | 0.70 | pass | — | It is a correct data operation: the same Cypher plus `u.last_logged_at`, 25 of 523 shown. Compared with p1c it **lost email and the "(that's you)" tag**, and nulls render as blank cells instead of "Not logged in". It ends with "just let me know!". Chips are the same identity pair; there is no export chip because the retrieval is capped at 100 of 523. |
| resilience | 2 | Where can I add new members? | 0.90 | 0.90 | **0.90** | 1.00 | pass | — | Grounded steps from team-and-members.md [1], members are distinguished from contacts, and the **Team settings chip is correct**. The roster survives (`last_result_sample_count:10`). |
| resilience | 3 | Export that members list | 0.70 | 0.70 | **0.65** | 0.70 | partial | export / memory | `previous_result`, fu=true. Columns are Member Name, Role and Last Login; "100 of 523" is stated. **The title is "Add new team members"**, which is the *interleaved docs turn's* subject. `AiManager::exportSourceMeta` uses `conversation.last_subject`, the knowledge turn overwrote it, and the rows survived. A wrong-subject title on exactly the turn that tests docs-turn contamination is worse than p1c's bare "Member". The download link works, but its title is wrong. Minor: query generation and execution still ran (3.1 s) although the export used the previous result. |
| app-nav | 0 | Where can I add new members? (cold) | 0.90 | 0.90 | **0.90** | 1.00 | pass | — | Same as resilience t2: grounded, [1], correct Team settings chip, no hedged guessing. Now 9.7 s (p1c 52 s). |
| cold-roster | 0 | Excel of team w/ contact info | 0.78 | 0.78 | **0.30** | 0.70 | **FAIL** (gate) | query-shape / export | The **promised column "Contact Reduced Mobility" is empty in every row**, which the standard makes an automatic fail. The column was never requested: the generator projected Contact's whole property list. **The disclosed total is also wrong**: "100 of 530" counts rows created by the OPTIONAL MATCH Contact fan-out, not the 523 members. Other problems: duplicate columns (User Email = Contact Email, Contact First/Last = User Name), raw `Contact Language = 1`, no role, and empty contact columns on staff rows. See the investigation below. |
| french | 0 | Liste membres + rôles | 0.80 | 0.60 (INFRA) | **0.80** | 0.60 | pass | — | Prose is entirely French, "votre équipe", "Affichage de 25 lignes sur 523 — demandez la liste complète ou un export" (an FR door). It is a User-anchored Cypher, so there are no null rows and the count is correctly 523. Role values are raw English enums. Chips: "Mon profil" is translated but **"Team settings" is English** on a French turn. |
| french | 1 | Exporte-la en Excel | 0.00 | 0.70 | **0.75** | 0.80 | pass | export | French copy ("J'ai préparé…", "100 lignes sur 523", "Télécharger Excel"), with the same members and columns as t0 and no blank rows. The title **"Team — Membres équipe rôles"** has an English entity prefix plus keyword-salad. Oddity: QU intent `file_listing` ran a file lookup that returned 50 DB file ids (harmless, 211 ms). |
| digest | 0 | What should I take care of next? | 0.55 | 0.50 | **0.65** | 0.85 | partial | composition (SC) | **Money sections are correct.** Approved-unpaid shows all 4 rows with exact amounts (no total stated; it would be $11,493.17). Overdue receivables shows "**Showing 5 of 75**" with the 5 oldest rows exactly matching the standard, but **does not state the $16,959.86 total**. Tasks 5 of 130, insurance 5 of 189 (with union), reviews 5 of 13, inbox 5 of 36, with truncation lines **inline**. Meetings: the 3 verified past meetings, named and dated; 146 is absent. **Remaining defects:** (1) **no union on the meeting rows** (required): the digest source projects only name/start/place; (2) "None pending." instead of "no meetings scheduled — most recent past:" (the heading helps); (3) the combined empty line still sits under a "Supplier bills awaiting approval" heading; (4) **no closing deepening offer** (exactly one is required); (5) raw codes (Urgent 1, Status 1/3, Flag Color 2/3/4). **Actions:** 7 correct section chips plus record chips Bill 15403→123, COT-000002→531, Meeting 140, Unit 203→282 and BIL-000003→154, all with ids verified against the rows. It uses `edit` on bills and invoices where `view` is safer, and there is no task record chip. |
| digest | 1 | When was our last meeting? | 0.50* | 0.55 | **0.15** | 0.80 | **FAIL** | projection / composition | Negative control OK (no digest block). The Cypher has the correct past filter, but it **projects only `date` and `meeting_id`**. Answer: **"Your last meeting is scheduled for October 24, 2023, at 16:00."** That is **two automatic fails**: "scheduled for" for a past meeting, and a **date-only answer** (no name, union or ZOOM place). The meeting name was in the prompt's previous-digest rows, but the deterministic FACTS block ("Date: 2023-10-24 16:00 … state the values from FACTS in prose, copied verbatim") narrowed the model to the date. The chip View Meeting 140 is the correct record, labelled with a raw "Meeting 2023-10-24 16:00:00", and is the only thing that rescues the user. |
| digest | 2 | Excel of everything needing attention | 0.55 | 0.60 | **0.60** | 0.75 | partial | composition (SC) | The digest owns the turn: `export_followup_offered:"excel"`, failed and skipped sources empty, and money rows correct. **New:** a real `export_excel` chip, and the answer says "The file is attached below." This supersedes the suite's v1 "reply 'export that'" contract, so **the standard needs revising**. Problems: the chip title is "tasks needing attention" (taken from the QU search_query), which undersells "everything". The model dropped the per-section "showing N of M" lines; code re-appended them (`digest_truncation_appended`) **after** the closing line, so the p1c trailing-lines defect is back on this turn. Same union / "None pending." / mis-headed empty-line defects as t0. |

\* Baseline digest t1 was graded under an older standard (about 0 under the current one).

## Totals

| Run | Mean (14) | Pass / Partial / Fail | Action mean |
|---|---|---|---|
| Baseline | 0.609 | 7 / 5 / 2 | — |
| p1c | **0.666** | 7 / 7 / 0 | — |
| **p9** | **0.607** (-0.059 vs p1c, about baseline) | **6 / 6 / 2** | **0.70** |

- Per-turn p9 scores: 0.45, 0.40, 0.40, 0.75, 0.80, 0.90, 0.65, 0.90, 0.30, 0.80, 0.75, 0.65, 0.15, 0.60.
- Action scores: 0.60, 0.50, 0.00, 0.75, 0.70, 1.00, 0.70, 1.00, 0.70, 0.60, 0.80, 0.85, 0.80, 0.75.
- Action: perfect on how-to turns (1.0) and strong on the digest (0.85). The weak spots are empty-result next steps (rights t2, 0.0) and the legal turns, where the prompt promises buttons that never arrive (0.5-0.6).

## Cold-roster gate: what happened and the fix

1. **Did the query add a column nobody asked for? Yes.**
   - QU tagged "contact info" as entity types `[Team, Contact, ContactDirectory]`.
   - The pruned selection gave the generator the Contact card, and it projected every scalar Contact property: first/last name, invitation_email, company, language, **`reduced_c_mobility`**, id.
   - `reduced_c_mobility` is an accessibility flag (config description: "1=yes, null=no"). It is not contact information and is arguably sensitive.
   - p1c's query was users-only (name, email, last login) and passed.
2. **Was it promised? Yes.**
   - The deterministic export composer lists every projected column ("Fields included: … Contact Reduced Mobility") and writes it into the Excel header.
   - The title, "Current team members contact information", is fine.
3. **Is it a data reality? Yes, completely.**
   - Read-only check: `contacts.reduced_c_mobility IS NOT NULL` gives **0 rows in the whole DB**; team 3 has 1,401 contacts and none are flagged.
   - The column came with migration `2025_10_01_000001_add_reduced_mob_to_contacts_table` and was never populated.
   - Because null means "no", an all-blank column is meaningless as well as empty.
4. **Collateral finding:** the total is wrong.
   - 523 users have `current_team_id=3`.
   - The `OPTIONAL MATCH (c:Contact)-[:USER]->(u)` fans out: 524 team-3 contacts are linked to users, so some users have several contacts. The result is 530 rows, disclosed as "100 of 530".
5. **Fix (recommended, in order):**
   - (a) **Deterministic, generic:** in the export and table composer, drop any column that is null or blank in *every* row before writing the header and the "Fields included" line. Optionally add one line: "X was empty for everyone." This is data-driven, needs no word lists, and makes the gate structurally impossible to fail.
   - (b) **CondoEdge config:** add `reduced_c_mobility` (and `dob`, already listed) to Contact `security.sensible_columns`, or otherwise exclude it from default projections, so it is only returned when explicitly asked for.
   - (c) **Query shape:** keep roster exports anchored one row per User (TeamRole/User, 523). Pull contact fields with `OPTIONAL MATCH … WITH u, head(collect(c))` so there is no fan-out, and project the role. Map the `language` enum to labels.
   - (d) **Harness:** add a gate that compares the disclosed total with the anchor's distinct count (530 vs 523 slipped through).

## Regressions vs p1c (with cause)

1. **cold-roster -0.48 (FAIL).** See above. Cause: entity selection let QU's `Contact` type drive a full-property projection, plus an unpopulated column, plus the fan-out total.
2. **digest t1 -0.40 (FAIL).**
   - Query generation projected only date and id.
   - The new deterministic FACTS block ("copy verbatim … No table") listed only the date.
   - There is no temporal guard: AnswerValidator checks numbers, citations and links, not "scheduled for" against a past date.
   - **Fix:** a lookup on a record must project its display label plus its union (a schema-card rule for Meeting). Include label and union in FACTS when the row has an id. Add a validator rule that flags future-tense verbs next to a date earlier than today.
3. **rights t2 -0.10.**
   - `ActionAttacher::nextSteps()`, EMPTY branch, attaches list and create for the **first QU entity type** (Invoice), which gives `new_invoice`.
   - The response prompt then says "When one of the attached actions is the natural next step, say so", so the LLM endorsed it.
   - `my_statement` is no longer offered.
   - **Fix:** never attach `create` on an empty *possessive or self* lookup (`qu.is_possessive`). Prefer the user's statement or identity action. Pass the "owns no unit" fact (no Contact linked to the user) into the WHAT WAS CHECKED block.
4. **rights t1 -0.10.**
   - **Repetition padding:** the 1063-1066 window chunk drags in 1064.1 and 1065, and the model re-serves them.
   - **False "next steps are attached"** line: the system and guideline prompt *unconditionally* says buttons are attached, but `nextSteps()` returns `[]` whenever `relevant_files` is non-empty, so legal turns get zero chips.
   - **Fix:** make the "attached as buttons" sentence conditional on there being at least one chip. For follow-ups, cite only the article(s) on the asked thread (true single-article passages, or demote neighbour articles in the window).
5. **rights t0 -0.05.**
   - 1063 was not retrieved on t0.
   - Duties are filed under "Rights".
   - The stripped-link remnant ("Here are the relevant links … - Quebec Civil Code …") is left in the text.
   - **Fix:** after stripping LLM links, remove the orphaned lead-in and list line as well. Retrieval for "rights and duties" should guarantee the Division IV head (1063).
6. **resilience t3 -0.05.**
   - `last_subject` is written by the knowledge turn, and the export title comes from it while the rows come from the earlier data turn.
   - **Fix:** store the subject *with* the recorded result rows and only update it when rows are recorded.
7. **resilience t1 -0.05.** The model dropped email and the self-tag, and nulls render as blank (LLM variance plus the projection change).

## Wins

| Turn | Change | Improvement |
|---|---|---|
| french t0 | 0.60 → 0.80 | No infra; User-anchored query; exact 523; 25 shown; FR door |
| digest t0 | 0.50 → 0.65 | Section chips and verified record chips; money sections live and exact (5 of 75, 4 bills); inline truncation lines |
| french t1 | 0.70 → 0.75 | Correct 523 and no blank rows |
| rights t0/t1 | (qualitative) | Every [n] now attributes to a passage that contains the article; no majority/¾ error; 1066 and 1072 now present |
| roster turns | (qualitative) | 25 rows shown, deterministic tables, exact "N of 523" |
| how-to turns | 1.0 action | Correct Team settings chip, no wrong links anywhere |

LLM-written links are gone: there are no `action://docs` or wrong-record links in any turn.

## Tokens and latency

- **Tokens:** 156,346 total (149,407 in / 6,939 out), 32 LLM calls, about 11.2k per turn. The heaviest turns are digest t1 (18.8k, carrying 32 previous digest rows) and rights t2 (16.0k). p1c recorded no usage, so there is no comparison.
- **Latency:** mean **11.7 s**, median **10.3 s**, sum 164 s. That compares with p1c 25.7 / 24.6 and baseline 16.9 / 16.6. **This is the fastest run in the series.**
- Slowest turns:
  - rights t0: 21.8 s, of which files 10.9 s.
  - The two digest turns: about 16.5 s each, of which composition is about 10.8 s.
- QU understanding now costs a flat 3-6 s on every turn and is the largest fixed cost.
- There are no Qdrant or OpenAI spikes this run, so latency is cleanly measured.
