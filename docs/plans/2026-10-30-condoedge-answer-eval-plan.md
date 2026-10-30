# CondoEdge answer evaluation: root causes and plan (2026-10-30)

Goal: fix the evaluation's findings at their root with the fewest regressions. Engine first when the fault is generic; otherwise a CondoEdge-only clarification. No large rewrites: the package was solid locally.

## 1. What was tested, and its limits

Real pipeline, real model, `ai:eval`, one-turn conversations, 2026-10-30 (reports in the session scratchpad, `repro/`):
- Union 136 (the evaluated syndicate) as owner (user 745, unit 1-305), board member (user 1266), manager (user 13): 27 questions.
- Union 8 (has money data locally) as manager: 12 questions.

The evaluators' exact wording was not available except C1 and B25; the others are written from the report's descriptions.

Limits of the local machine, each verified:
- Union 136 has **0 bills and 0 invoices** in local MySQL, so "$0 receivable" is true here. D3, D5, D6 and D7 cannot be reproduced for this syndicate locally.
- Only **3 uploaded files** are indexed locally (`file_chunks`: 2 unions), none from union 136, and their PDFs are not on disk. Dev has far more. This is the main local/dev difference.
- Local ingestion for union 136 is **correct**: graph = MySQL for units (81) and buildings (2). Union 8's graph matches for reconciliations.

## 2. Root causes

### R1. Engine: an empty data path is filled in by documents (D2, D5, likely D9)
Every question above was classified `data_query`, so classification is not the fault. The chain is:
1. The data path finds nothing (no query, or 0 rows). Reproduced on most turns.
2. `skip_for_question_types` is `[]` in CondoEdge (`config/ai.php:657`, commit `0c5fe062`, 2026-10-26), so documents are searched on data questions too. It was set so residents' how-to questions reach the help articles. That goal is valid, but the same switch lets uploaded PDFs in.
3. The composer answers from what it has. Locally that is a help article ("available documents"). On dev it can be a 2020 PV or an invoice PDF, stated as current.

Step 3 with uploads could not be reproduced locally; it is the explanation that fits the report's citations. Phase 0 checks it.

### R2. Engine: a record name is matched exactly (D4, and anything naming a syndicate)
Generated queries use `u.name = '...'`, while the record is `SDC O Quai du nord`. Reproduced: 0 rows, and "not found" where the graph has 81 units. The query often drops "SDC" even when the user typed it (6 of 12 on union 8, 0 rows each). `NameResolutionSection` only gives partial matching for people's names.

### R3. CondoEdge: no calculation for the finance questions (D3, D5, D6, D7, D16)
The generator improvises. Reproduced:
- Snow removal spend: answered from a **budget line** as "spent $2000", or from `Bill.notes CONTAINS`. The real path is BillDetail → Account, which is in the graph. Worst case seen: a budget amount stated as money spent.
- Receivables with a syndicate named: `sum(toFloat(a.subname))` on accounts. The existing `team_total_owed` calculation matched for one phrasing and not for the other, which is the report's "depends on phrasing".
- Trial balance, bank balances, budget vs actual, insurance per year: no query at all.

### R4. CondoEdge: the owner's data path cannot read their own unit (D8, most of D2)
`audiences.map.coproprietaire.data_entities` leaves out Unit, Contact, Invoice. This looks deliberate: `row_scope` narrows to the union, so opening Unit would show every neighbour's unit. So the assistant has nothing to answer from.

### R5. CondoEdge: uploaded files are readable beyond their record (D9, D15). PRIVACY
File 13690, cited to the owner of 1-305, is attached to **unit 2-301** (id 2254) with `public=3`. `File::scopeAccessibleBy` gives a contact every `public=3` file of the union and ignores the parent record. Locally 343 unit-attached files are in this state (186 units, 15 unions; 50 in union 136). This is local data; confirm on dev.

### R6. Engine: uploads beat help articles on how-to questions (C6, C15, C19, D12)
With the evaluators' exact wording these are `knowledge_query`. Locally, with no uploads competing, they get good answers from the help articles. On dev they were answered from a moving-rules PDF. The Phase 2 rule does not touch them, because they are not data questions. Needs dev traces before any change.

### Not reproduced or not yet known
- D14 (generic error): B25, G9 and G10 ran without error locally. Dev traces and logs needed.
- D3's exact gap ($46,330.94 vs $45,595.55): hypothesis, open invoices vs unit balance net of advances; "overdue" = due date passed vs the report's 30-day "current" bucket.
- D10: queries use `start_date`, consistent with "recurrence start dates". The Upcoming block's rule is still to read.
- D11, D12: depend on what is indexed on dev.
- D13: confirmed in code and in the runs (English column labels such as "Building Count").

## 3. Plan

Every phase: failing test first; package suite; re-run the `repro/` suites and compare; CondoEdge replay output compared with `cassette-replay-tasks-baseline.out` (the cassette is stale, so "same 54 mismatches, nothing new" is the bar); then the same questions on dev.

### Phase 0. Evidence from dev (no code change)
The evaluators' conversations already hold production traces (`metadata.trace`, on by default): stages, query with literals redacted, row counts, cited files, errors. Read-only export on dev:

```bash
php artisan tinker --execute="file_put_contents(storage_path('app/ai-eval-traces.json'), \Condoedge\Ai\Models\AiMessage::where('created_at', '>=', '<EVALUATION START DATE>')->get()->toJson(JSON_UNESCAPED_UNICODE));"
```

This contains personal data, so it is the owner's call how to share it. It answers, with no re-run and no model cost:
- For D2/D5: was the query empty or 0 rows, and which files were cited? This confirms or refutes R1.
- D14's actual errors. D3's and D6's actual queries.
- Is file 13690 really attached to another unit on dev?

Also on dev: which uploaded files are indexed, and whether the law corpus is (D11).
Only if traces are not enough: install `ai-eval` on dev and run the `repro/` suites.

### Phase 1. Privacy (R5). CondoEdge only. DONE in code 2026-10-30
A contact reads a unit-attached file only if the unit is theirs. One rule in `File::scopeAccessibleBy`; check its other callers first, because a chip must not lead to a 500 (D15). Tests with two units in one union.

### Phase 2. Documents vs data (R1). Engine, opt-in. DONE in code 2026-10-30
Keep searching as today, and decide at composition:
- Rows came back: uploaded files are dropped for this turn. Help articles stay (the 2026-10-25 how-to goal is kept).
- No rows: uploaded files stay, with one added rule: a figure from a document must name the document and its year, and is never stated as current.

New config, off by default, so Coolecto and the existing behaviour are unchanged; CondoEdge opts in. The gate is one place in `AiManager`, using the chunk source that already exists (`physical` vs uploaded). The added rule appears only on those turns, so other prompts stay byte-identical.

Rejected:
- Setting `skip_for_question_types` back to `['data_query']`: loses the help articles and reopens the 2026-10-25 failure.
- Skipping the upload search on every data question: a fact only present in a document (a deductible, say) labelled `data_query` would be lost.
- Moving file search after query generation: bigger change to the pipeline's order at a critical time.

### Phase 3. Record names (R2). Engine. DONE in code 2026-10-30
One generic generator rule: a name the user gives for a record is matched case-insensitively and partially, never with `=`. Measure on the name questions and on the gold suite. A deterministic retry on an empty result only if the rule proves unreliable, with adversarial review because it rewrites Cypher (see the 2026-10-01 leak).

### Phase 4. Finance calculations (R3). CondoEdge config only. DONE in code 2026-10-30; check against the screens on dev
Each checked against the matching screen, with French phrasings so it matches however the question is worded:
- receivables by aging bucket, as on the aging report;
- monthly contribution total;
- spend by account, supplier and period (paid vs payable);
- trial balance at a date (`Account::getAccountsCreditDebit` is the source of truth);
- latest reconciliation per account, with its remaining amount;
- bank and fund balances;
- budget vs actual;
- next maintenance occurrences.

Each must be checked on dev, because union 136 has no local money data.

### Phase 5. The owner's own unit (R4). Small engine seam + CondoEdge. DONE in code 2026-10-30 (ai.audiences.facts_resolver, App\Ai\ResidentFacts)
A host-provided facts block for the asker: their unit, share, balance, contribution, insurance date, vehicles. It is computed by the app's own code from the contact's own units, so no query can reach a neighbour.

Rejected: a unit-level row scope. It is the same class of change as the cross-tenant leak, and too risky now.

### Phase 6. The rest
D13 labels (FR/EN, 24 h, no emoji), D14 after Phase 0, D10, D11, D12.

## 4. D1 (layout), done in code
Below 768 px the chat is full screen in one column, with the conversation list behind a button, whatever `AI_CHAT_LAYOUT` says. The CSS is printed inline by the surface, so no asset build is needed in either app. Desktop keeps the same layout. Not yet seen in a real browser: the Chrome extension was not connected.

## 5. Status at the end of 2026-10-30
Deploy on dev: `php artisan ai:ingest --model='App\Models\Contact\BoardMember'` (new `member_name`). The prompts changed, so the gold cassette must be re-recorded before replay can guard again.

Open:
- Known misses: "âge de compte", "coût de l'électricité" and "entretien ménager" with a long syndicate name match no calculation (CalculationMatcher scores 0.34-0.41 against 0.45); "retards de paiement" gives open invoices (11,961.24), screen 12,112.14.
- Dev only: R1 and R6 (uploads beat help articles), D14 errors, D15 chip 500, D11 law corpus, D12.
- Union.accounting_level is not in the graph (trial balance level filter).

## 6. Owner rule, 2026-10-30
If the software does not show it to that user, the assistant refuses clearly: no data, no figures from PDFs.
- Board `data_entities` follow their screens: no Invoice, AccountBalance, Entry, Transaction, Fund, Budget, BudgetDetail. Account stays for its name (payables list).
- What their screens do show comes from `App\Ai\ResidentFacts`: unit page for residents; finances tab, statistics page and reserve fund screen for the board.
- `AskerFactsAnswerer`: one small call answers a no-rows data question from those facts, else the refusal `ai.error.no_access`.
- `uploads_yield_to_data`: for a limited audience, a data question never keeps uploaded files, rows or not. Staff with no rows keep them, with document and year named.

## 7. Query repairs and inflated totals, 2026-10-30
- `ai.query_generation.execution_repair_attempts` (package 1, CondoEdge 2): repairs see the latest draft and error. The double failure that prompted it did not recur in 12 runs, so its benefit is unmeasured.
- Measured on "assurances par année", union 8 (truth 8,849.87 and 1,519.02): 7 of 12 runs said 101,330.67, every bill of 2021, because the condition sat on an OPTIONAL MATCH. Fixed in the `spend_by_supplier` template and by a generic query rule. After: 4 of 6 correct, 2 of 6 "not found", 0 wrong.
- Open: the same question still varies between correct and "not found". Repeat important questions when evaluating: one run proves little.

## 8. Stability check, 2026-10-30 (manager, union 8, graded against the app and database)
- 12 questions x 6 runs after all fixes: 72 of 72 right figures (3 bank answers list the accounts without a total line).
- Trickier wording, 4-5 runs each: conciergerie 6,519.61 correct 4/4; snow removal per year 800 and 1,200 correct 5/5; "age de compte" and apostrophe names correct.
- Open: electricity per year correct 3/5, the other 2 call the two-year total 13,387.72 "pour l'annee". "Total des factures" reads as resident invoices (140,570.99), a valid reading.
- CondoEdge `calculation_matching`: min_similarity 0.40, margin 0.05 (measured on 7 should and 10 should-not questions).
- All of this is one union with local data: repeat on dev for union 136.
