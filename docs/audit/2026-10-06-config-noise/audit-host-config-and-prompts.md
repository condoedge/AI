# Audit — host config (`CondoEdge/config/ai.php`) and prompt noise (p14 traces)

Read-only audit, 2026-10-06. No project file edited, no git writes, no phpunit/artisan run.

## 0. Method and caveats (read first)

- **Host config read in full**: `C:\Users\jkend\Documents\Projects\kompo\CondoEdge\config\ai.php` (1,496 lines; mtime 2026-10-02 08:15).
  It was modified AFTER the p14 traces (2026-10-01 18:26–19:47). Every text I quote from a trace prompt matched the current file,
  but line numbers below are from the current file, and small drift vs what p14 ran is possible.
- **Traces**: 11 files `storage/eval/p9..p14-1001-{A,B}.json` (p10-B does not exist) = 148 turns, 103 query-generation calls, 90 non-null Cyphers.
  Part 2 numbers use p14 A+B only (27 turns, 342,294 input tokens — matches the files' own `usage_total`).
- **The eval is ONE persona.** All 148 turns are pinned to `j.rousseau@novogestion.ca`, role `lead`, team 3, same ~27 questions per run.
  So "NEVER-FIRED in traces" is weak evidence for anything resident-facing or board-facing. I separate three strengths of evidence:
  - **UNREACHABLE** = no code path in the package can produce it (code-level proof, independent of the eval).
  - **NEVER-FIRED (eval gap)** = reachable, just not exercised by this suite. Not a removal candidate.
  - **FIRED** = seen in traces with count.
- Package working tree is dirty (fingerprint: sha `badd2f1` + ~503 dirty files); QU prompt code has already changed since p14
  (e.g. the `navigation_target` wording in `src/Services/QueryUnderstandingService.php:248` differs from the p14 prompt).
- Token figures = chars ÷ the stage's measured chars/token from real usage (understanding 4.68, generation 4.18, composition 4.28).
- "Static" = lines present in EVERY instance of that section across the 27 turns (line-level intersection), i.e. true boilerplate.
- Traces do not record provider cache hits (usage has only input/output/total/calls), so the cost effect of prefix caching is unknown.
- Comment classification in 1.6 is heuristic (regex on date/iteration tags); treat those counts as ±10%.

---

## PART 1 — Host config inventory

### 1.1 Top-level sections (current file)

Total 1,496 lines = **643 comment (43%)**, 78 blank, ~775 code.

| Lines | Section | Total | Comment | Code | Verdict |
|---|---|---|---|---|---|
| 1–173 | preamble: `$staffOnly`, `$permanentOnly`, `$boardOrStaff`, `$hasPermission`, `$inCurrentTeam`, `$mutation`, `$reservationTeam`, `$taskTeam` | 173 | 68 | ~95 | KEEP (security gates) — see 1.2c |
| 199 | `debug` | 11 | 9 | 1 | USED (`src/Support/AiLogger.php:19`); differs from default (false) |
| 210–244 | `project` (description, domain, 12 business_rules) | 56 | 37 | 18 | USED every generation + composition turn; see 1.3d |
| 266–299 | `calculations` (4) | 40 | 9 | 30 | USED; 3 of 4 matched in traces |
| 306–312 | `guardrails.domain_scope` | 18 | 10 | 7 | USED (`ResponseGuardrailsSection.php:51`); duplicates `project.description` — 1.4 |
| 324–351 | `identity` (name, persona) | 37 | 13 | 22 | USED in generation AND composition system prompts; see 1.4 / Part 2 |
| 361–373 | `llm_roles` | 25 | 12 | 12 | USED (fingerprint: QU gpt-4o-mini, gen gpt-4o, response gpt-4o-mini) |
| 386–388 | `response_generation.currency_symbol` | 16 | 12 | 3 | USED (`Response/TableRenderer.php:360`; "$0.00" visible in p14 tables) |
| 402–528 | `user_entity_paths` (11) | 133 | 54 | 78 | USED; 5 of 11 rendered — 1.3c |
| 535–542 | `scope_matching` | 14 | 8 | 4 | USED (`AiServiceProvider.php:414`, `SemanticContextSelector.php:244`); differs from defaults (0.70 / 5) |
| 549–581 | `file_context` (physical_paths, corpus_languages, corpus_register) | 46 | 30 | 13 | paths USED; corpus_* render into the QU prompt but produce NO output since p10 — Part 2 §2.3 U2 |
| 595–597 | `health.middleware` | 17 | 13 | 3 | USED (`routes/api.php:10`) |
| 612–615 | `export` row limits | 10 | 5 | 4 | USED (`Response/ExportResponseComposer.php:361-362`); 26 export turns |
| 622–705 | `chat` (example_questions, page_examples) | 90 | 63 | 25 | example_questions USED (UI); `page_examples` = 8 EMPTY arrays — 1.6 |
| 712–715 | `ui.theme` | 13 | 9 | 3 | USED (`UI/UserChatThemeFactory.php:39`); differs from default ('green') |
| 725–727 | `entities` loader | 26 | 22 | 3 | USED |
| 751–1024 | `entity_actions` (16 entities, 21 actions) | 299 | 90 | 188 | 5 FIRED, 12 never-fired (reachable), 4 with no producer — 1.2 |
| 1050–1054 | `attached_actions.identity.team` | 6 | 0 | 5 | USED (team_settings identity chip fired 37×) |
| 1056–1306 | `generic_actions` (28) | 277 | 94 | 178 | 10 FIRED, 5 never-fired (reachable), 13 UNREACHABLE — 1.2 |
| 1333–1477 | `attention_sources` (11) | 160 | 63 | 85 | 9 ran in all 10 digest turns; 2 `enabled=false` |
| 1493–1495 | `digest.max_sources = 9` | 5 | 0 | 4 | USED (9 enabled sources > package default 8) |

**No top-level section equals the package default** (`ai/config/ai.php`). Checked: debug (default false), project (generic text), identity.persona (generic),
llm_roles (default empty), scope_matching (0.70/5), currency_symbol (null), health (['api','auth']), export (250 / unset), ui.theme ('green'), digest (8).
Only trivial duplicate: `llm_roles.*.provider => 'openai'` (lines 363, 370) = `llm.default`.

Note for the near-zero-config goal: `debug`, `response_generation`, `scope_matching`, `export`, `ui` (≈64 lines incl. comments) only change the
default of an env-backed key the package already reads (`AI_DEBUG`, `AI_RESPONSE_CURRENCY_SYMBOL`, `AI_SCOPE_MATCHING_THRESHOLD/TOP_K`,
`AI_EXPORT_DOCUMENT_ROW_LIMIT`, `AI_EXPORT_PDF_DOCUMENT_ROW_LIMIT`, `AI_UI_THEME`). They could live in `.env` — optional, operational trade-off, not noise.

### 1.2 Actions

**Routes: no dangling route.** 45 route references in the config; 44 are defined by `->name()` in `CondoEdge/routes/web.php`
(e.g. `unit.view` web.php:124, `report.unit-statement` :399, `bills.table` :161, `union.manager` :277). The 45th, `teams.show` (ai.php:1163),
is the Jetstream vendor route (`vendor/laravel/jetstream/routes/livewire.php:40`) and fired 37× in traces.

**How an action can reach the user today** (code-level, `ai/src`): invocations are created ONLY in
`Services/Actions/Attach/ActionAttacher.php` (lines 133, 207, 241, 403, 483, 545, 571) — record chips (entity's first read action),
identity chips, next steps by `entity`+`kind` placement (`genericFor()` :584-602), export chip, digest section `action_key`.
Every one of those filters `!$action->mutates()` (:331, :608). Model-written `action://` / `entity://` links are stripped by
`Services/Response/NarrativeLinkFilter.php` (:18-22; applied at `ResponseGenerator.php:604`, `:965`, `DigestResponseComposer.php:112`).
`MessageBubbleRenderer.php:267-269` states the native tool loop that used to produce other invocations "was removed 2026-10-01".
Action `aliases` are never rendered into any prompt (`EntityActionAwarenessSection.php:88-93` prints key + label only) and never matched:
the only readers of `aliases()` are `EntityAutoDiscovery.php:793/824/854`, whose output is used for key/label only
(`ResponseEntityEnricher.php:145-152`). 12 distinctive alias strings probed across all prompts of all 148 turns: 0 hits.

#### a) FIRED (keep)

| Action | ai.php | Invocations (148 turns) |
|---|---|---|
| `Unit.view` | 759–763 | 66 |
| `Union.view` | 795–799 | 27 — but see bug note below |
| `Bill.edit` | 970–975 | 20 |
| `Meeting.view` | 811–815 | 15 |
| `Invoice.edit` | 960–965 | 10 |
| `team_settings` | 1156–1167 | 37 (identity chip) |
| `report_receivables` | 1212–1220 | 28 |
| `contacts` 1176–1184, `meetings` 1089–1096, `bills_table` 1295–1305 | | 16 each |
| `tasks` 1261–1269, `units` 1185–1193, `inbox` 1277–1285 | | 10 each (digest sections) |
| `suppliers` | 1286–1294 | 8 |
| `new_invoice` | 1112–1120 | 1 |
| package built-ins `my_profile` 17, `export_excel` 11 | — | — |

Bug note (not noise, wrong behaviour): `Union.view` (ai.php:796) ignores `$id` — `_Link($text)->href('union.view')` with no parameter
("current-union context"). It fired 27× as RECORD chips, each labelled with a different union's name, all opening the same page.
NEEDS-CHECK: click two different union chips on the board answer.

#### b) NEVER-FIRED but reachable (eval gap — do NOT remove on this evidence)

Entity (would attach as record chip or "one record → its other read actions"): `Unit.statement` 764–772, `Unit.add_vehicle` 773–780,
`Unit.add_access` 781–790, `Union.manage` 800–806, `Supplier.view` 820–824, `Project.view` 829–833, `Maintenance.view` 838–842,
`Contact.view` 949–956, `Budget.view` 981–986, `Transaction.edit` 992–997, `Announcement.edit` 1004–1009, `File.info` 1017–1022.
Generic with `entity`+`kind` placement: `make_reservation` 1068–1075, `new_bill` 1121–1129, `new_announcement` 1130–1139, `budget` 1194–1202,
`transactions` 1203–1211.

#### c) UNREACHABLE by construction (dead config under the current "code attaches links" design)

**13 generic actions with no `entity`/`kind`, not an attention-source `action_key`, not an identity mapping** — nothing in `ai/src` can attach them:

| Key | ai.php | Lines |
|---|---|---|
| `calendar` | 1062–1067 | 6 |
| `support` | 1076–1081 | 6 |
| `my_statement` | 1082–1088 | 7 |
| `docs` | 1097–1102 | 6 |
| `union_stats` | 1140–1148 | 9 |
| `dashboard` | 1149–1155 | 7 |
| `union_manager` | 1168–1175 | 8 |
| `report_owners_registry` | 1221–1232 | 12 |
| `report_balance_sheet` | 1233–1239 | 7 |
| `report_profit_loss` | 1240–1246 | 7 |
| `report_financial_statements` | 1247–1253 | 7 |
| `chart_of_accounts` | 1254–1260 | 7 |
| `files_manager` | 1270–1276 | 7 |

≈96 lines. They still cost tokens: all 13 are listed in the `- Generic:` line of the generation prompt's ACTION REQUESTS section on every
generation turn (≈450 of that section's 2,197 chars). Extra finding: `union_manager` (1168–1175) is gated `$staffOnly` while its own route
comment and its entity twin `Union.manage` (800–806) say `Role::permanent` → a concierge would be offered a 403 link if it ever became reachable.
Decision for the owner: either give them a placement (`entity`/`kind`) or a navigation attach path — then they are useful — or delete them.
Marked NEEDS-CHECK (1-minute confirmation: ask "open the calendar" as the lead; expect no Calendar chip).

**4 write actions with no producer**: `approve_reservation` / `reject_reservation` (860–887), `close_task` (895–909),
`complete_task_detail` (915–935) ≈ 76 lines, plus the helpers that exist only for them: `$hasPermission` (70–100), `$inCurrentTeam` (102–119),
`$mutation` (121–168), `$reservationTeam` / `$taskTeam` (170–173) ≈ 104 lines. `ActionAttacher` never attaches a mutating action and the tool loop
that proposed them was removed 2026-10-01, so nothing can currently propose an approval card. (The config itself notes at 482–486 that the
`:Reservation` label is absent from the live graph.) **I am NOT recommending removal — these are security gates and stay on the KEEP list.**
This is a decision point: if the mutation surface is re-wired the gates are essential; if it is abandoned, ~180 lines guard nothing.

**All action `aliases` arrays** (one line on each of the 49 host actions, ~49 lines; ≈250 alias strings): read by no matcher and no prompt.
SAFE to delete as data (the interface method stays). Removing them changes no prompt byte.

### 1.3 Other registries

**a) Calculations (ai.php:266–299)** — matched by `Retrieval/CalculationMatcher` (`ContextRetriever.php:794-810`), rendered by `PromptSections/CalculationsSection.php:56`.

| Key | Lines | Matched (stage data) | Rendered in gen prompt |
|---|---|---|---|
| `unit_outstanding_balance` | 275–281 | 22 | 23 |
| `owners_behind` | 282–288 | 15 | 11 |
| `supplier_payables` | 289–298 | 6 | 6 |
| `team_total_owed` | 267–274 | 0 | 2 |

All KEEP. `team_total_owed` is an eval gap (no "how much are we owed in total" question) — add a question rather than remove.
The model reproduced the canonical Cypher nearly verbatim when shown (unit 310, suppliers), so the section is high-value per token.

**b) Attention sources (1333–1477)** — 10 digest turns, all 9 enabled sources ran every time, 0 errors.
With rows 10/10: `open_tasks` 1336–1343, `bills_approved_unpaid` 1357–1363, `overdue_receivables` 1371–1377, `expired_unit_insurance` 1400–1406,
`pending_contact_reviews` 1416–1422, `flagged_inbox_threads` 1425–1431. `upcoming_meetings` 1386–1395: fallback used 10/10 (as designed).
`work_orders_awaiting_acceptance` 1445–1451: rows 8/10. `bills_pending_approval` 1346–1352: 0 rows 10/10 (honest-empty, renders the "nothing pending" line).
NEVER-FIRED by construction: `maintenance_dates_unconfirmed` 1458–1465 and `maintenance_contracts_lapsed` 1469–1476 (`enabled => false`, staged pending re-ingest; 24 lines incl. comments).
All `action_key`s resolve to existing generic actions. KEEP all (memory: digest = invest).

**c) user_entity_paths (402–528)** — rendered by `PromptSections/CurrentUserContextSection.php:226-269` only for selected labels.

| Path | Lines | Rendered (103 gen turns) |
|---|---|---|
| Invoice | 419–427 | 14 |
| Meeting | 474–481 | 11 |
| Unit | 403–408 | 10 |
| Contact | 409–414 | 6 |
| BoardMember | 522–527 | 6 |
| Vehicle 428–433, Access 440–445, Extra 448–453, Announcement 461–468, Resource 491–496, Thread 511–519 | | 0 |

The six never-rendered paths are resident paths and the eval has no resident → eval gap, KEEP.
One real problem: the `Thread` path Cypher (513–516) uses `EXISTS { MATCH (c:Contact) … }`, which the package's own QUERY SHAPE RULES forbid
(`SemanticPromptBuilder.php:432`: "Do not use CALL {} subqueries, pattern comprehensions over sensitive entities, or EXISTS {} that bind sensitive nodes").
NEEDS-CHECK: ask "what's the status of my support request?" as a resident and see whether the coverage gate safe-stops it.
The `entities` / `relationships` arrays (2 lines × 11 paths) are read (`SemanticContextSelector.php:760-761`, `EntitySelectionPruner.php:99`)
but are fully derivable from the path's own Cypher string.

**d) Business rules (ai.php:214–242)** — rendered by `PromptSections/ProjectContextSection.php`; plain strings ship on every generation turn,
`['rule','entities']` entries only when the entity is selected. "Relevant" = crude check of whether the final Cypher touches what the rule is about (p14, 15 Cyphers).

| Line | Rule (start) | Chars | Shipped /103 | p14 shipped / relevant |
|---|---|---|---|---|
| 240 | HOMONYM SAFETY | 706 | 103 (always) | 15 / 3 |
| 225 | COLLECTIVE possessives | 608 | 103 (always) | 15 / 4 |
| 216 | Team members: NO CURRENT_TEAM… | 612 | 80 | 11 / 1 |
| 219 | A request to CREATE or EXPORT… | 437 | 103 (always) | 15 / 2 |
| 218 | When ONE question asks for several… | 399 | 103 (always) | 15 / 4 |
| 214 | When searching by name… | 289 | 103 (always) | 15 / 3 |
| 231 | MONEY OWED | 538 | 52 | 7 / 5 |
| 220 | When you are in a team… | 129 | 103 (always) | 15 / 0 |
| 217 | ContactUnit links… | 226 | 45 | 8 / 2 |
| 242 | NEVER tenant-scope Invoice by team_id | 352 | 34 | 4 / 4 |
| 215 | Unit names are identifiers… | 192 | 45 | 6 / 4 |
| 236 | AccountBalance strings → toFloat | 201 | 19 | 3 / 0 |

Always-on rules = 2,568 chars ≈ 614 tokens on every generation turn. Specific findings:
- **Line 220** is about how to SPEAK to the user ("shouldn't reference it as external"), ships only to the Cypher generator, is ungrammatical,
  and is already covered three times on the composition side (1.4 #1). SAFE to remove.
- **Line 216** tells the generator "Members of a team = MATCH (u:User) WHERE u.current_team_id = <team id>". In the 69 Cyphers where it shipped,
  the model used `:TeamRole` in 24, `current_team_id` in 7, both in 3, neither in 35 — and the TeamRole answers are the ones judged ok
  (p14: `MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) MATCH (u:User) WHERE tr.user_id = u.id`). The rule is ignored and arguably wrong
  (current team ≠ membership). The same pattern is repeated by the `Team.MEMBERS` scope (shown 46×) and the package's identity anchors.
  NEEDS-CHECK: verify against the app's real membership semantics, then drop or rewrite to TeamRole.
- **Line 236** shipped 19×, AccountBalance was in 0 final Cyphers (it is pulled in by the word "balance") — see Part 2 G6.
- **Line 225** duplicates a package block — 1.4 #2.

### 1.4 DUPLICATES (same thing said twice or more)

| # | What | Copies |
|---|---|---|
| 1 | Insider voice ("never refer the user to their own team as external") | persona ai.php:340–342 · business rule ai.php:220 · package `ResponseSections/ResponseCurrentUserContextSection.php:69` (3-bullet INSIDER VOICE block, always emitted) |
| 2 | Collective vs personal possessives | business rule ai.php:225 (608 chars, every gen turn) · package `PromptSections/CurrentUserContextSection.php:246-249` (emitted whenever a path ships, 37 turns) |
| 3 | Unit number = `name`, exact match | rule ai.php:214 · rule ai.php:215 · Unit entity description ("IMPORTANT: The "name" property is the unit number…") + its `name:` prop note · calc cypher ai.php:279 |
| 4 | Money owed = due_amount / outstanding_balance, statuses are strings | rule ai.php:231 · Invoice/Unit schema-card prop notes · calculations formula+notes ai.php:270–287 · comment block 255–263 |
| 5 | Homonyms: return union_name next to unit name | rule ai.php:240 · calc notes ai.php:280 and 287 · code-computed HOMONYMS block at composition (`Response/DataAnswerPlan`) |
| 6 | What the product's domain is | `project.description` ai.php:211 · `guardrails.domain_scope` ai.php:307–311 · persona ai.php:329–331 — all three land in the same composition prompt |
| 7 | Links are buttons / don't write URLs / `{{record:N}}` | persona ai.php:331–334 · package `Actions/Attach/AttachedActions.php:30` (NARRATOR_LINE) |
| 8 | Don't end with offers | persona ai.php:346–347 · package `ResponseSections/GuidelinesSection.php:199-200` |
| 9 | Export request is still a data question | rule ai.php:219 · package `EntityActionAwarenessSection.php:114-123` · QU `export_format`/`search_query` docs |
| 10 | Never scope Invoice by team_id | rule ai.php:242 · the package-generated TENANT SCOPING line `(i:Invoice) → MATCH (i)-[:BELONGS_TO_UNION]->(u:Union) WHERE u.team_id IN $ai_team_ids` |
| 11 | "Manage union" page | generic `union_manager` ai.php:1168–1175 · entity `Union.manage` ai.php:800–806 (same route, same label, different gate) |
| 12 | Link text | closure default `$text = '…'` and `'label' => '…'` on all 45 link actions (4 differ slightly, e.g. 'View account statement' vs 'Account statement') |
| 13 | Single-query-for-several-things | rule ai.php:218 · last sentence of rule ai.php:216 |

### 1.5 CLOSURE-HEAVY

- **45 navigation closures** in `entity_actions` / `generic_actions`, every one of the shape
  `fn ($id?, $text) => _Link($text)->href|get('<route>', [<param> => $id])[->inModal()|->inNewTab()]`. `Services/Actions/ConfigAiAction.php`
  accepts only a closure today (no declarative route form), so this needs a small package feature (`'route'`, `'param'`, `'target'`);
  the package already does it for identity links (`attached_actions.identity.*.route`). Would shrink both registries to roughly a third.
  Related: closures and `new ModelMethodMutationAction(...)` objects inside a config file are expected to break `php artisan config:cache`
  (Laravel cannot serialize closures) — NOT run here; worth one manual check, it is a real plug-in friction.
- `$staffOnly` / `$permanentOnly` / `$boardOrStaff` (lines 21–68, 48 lines) are three role lists wrapped in identical null-check + try/catch.
  Semantics are security → KEEP; the boilerplate could be one helper taking a role list.
- `user_entity_paths.*.entities` / `.relationships`: derivable from the Cypher (22 lines).

### 1.6 COMMENT / HISTORY bloat

643 comment lines. Approximate split: **≈281 in blocks narrating past fixes** (dated / iteration-tagged: "9.0-h", "p11 G2", "Survey #", "90c", "FIX:", "REMOVED 2026-09-30"…),
137 banner/box lines, 63 route pointers (`web.php:NNN`), 15 commented-out code, ≈147 other explanation.

History-narrating comment lines per section: chat 58 of 63 · generic_actions 57 of 94 · user_entity_paths 49 of 54 · attention_sources 49 of 63 ·
project 33 of 37 · entity_actions 29 of 90 · preamble 28 of 68.
Largest blocks: 1308–1332 (25), 1026–1049 (24), 644–666 (23), 121–141 (21), 246–265 (20), 497–510 (14), 599–611 (13), 623–634 (12), 1433–1444 (12), 375–385 (11), 482–490 (9).

Specific dead block: **`chat.page_examples` (644–704, 61 lines)** — every entry is commented out, so the key evaluates to 8 empty arrays, which
`Kompo/Support/WelcomeSuggestions.php:34-35` treats as "fall back to the global list". Behaviour is identical without the key. SAFE to move the
staged questions to a doc.

Security-relevant comments (why a gate is `$staffOnly`, "this closure is the SOLE gate", why not `hasPermission()`) are worth keeping; the dated
verification narratives are what a changelog/doc is for. A conservative trim (history blocks + page_examples + alias lines; the page_examples
comments are already inside the 281) is ≈340 lines (≈23% of the file) with zero behaviour change.

---

## PART 2 — Prompt noise (p14 A+B, 27 turns)

### 2.1 Totals by stage

| Stage | Calls | Input tokens | Share | Avg in / call | Static boilerplate | Static tokens / call |
|---|---|---|---|---|---|---|
| understanding (gpt-4o-mini) | 27 | 148,747 | 43.5% | 5,509 | 98.8% | ≈5,441 |
| query_generation (gpt-4o) | 19 | 132,823 | 38.8% | 6,991 | 52.3% | ≈3,658 |
| composition (gpt-4o-mini) | 22 | 60,724 | 17.7% | 2,760 | 55.6% | ≈1,535 |
| **all** | 68 | **342,294** | 100% | 12,678 per conversation turn | **≈73%** (≈250k tokens) | |

Output tokens are negligible by comparison (9,936 total). Per-turn range: understanding 5,415–5,750; generation 4,314–9,898; composition 1,136–5,342
(knowledge turns are the big ones because of file passages). Generation runs on gpt-4o, so its tokens are the expensive ones.

### 2.2 The 10 biggest sections (by tokens over the run)

| # | Section (prompt) | Turns | Avg tok | Run total | % of all input | Static? | Emitted by |
|---|---|---|---|---|---|---|---|
| 1 | AVAILABLE ENTITY TYPES (understanding) | 27 | 2,902 | 78,357 | 22.9% | STATIC | `QueryUnderstandingService.php:189-212` (catalog), `:231` |
| 2 | GRAPH SCHEMA (generation) | 19 | 1,962 | 37,275 | 10.9% | dynamic | `PromptSections/SchemaCardsSection.php:68` |
| 3 | PROJECT CONTEXT incl. business rules (generation) | 19 | 960 | 18,241 | 5.3% | 73% static | `PromptSections/ProjectContextSection.php` ← host ai.php:210–244 |
| 4 | PERSONA (generation + composition) | 39 | 322–329 | 12,686 | 3.7% | STATIC | `AssistantIdentity` via `SemanticPromptBuilder.php:241`, `ResponseSections/IdentitySection.php:35` ← host ai.php:327–350 |
| 5 | RELEVANT FILE CONTENT (composition) | 6 | 1,822 | 10,930 | 3.2% | dynamic | `ResponseSections/FileContextSection` |
| 6 | ACTION REQUESTS (generation) | 19 | 525 | 9,979 | 2.9% | mostly static | `PromptSections/EntityActionAwarenessSection.php:49-128` |
| 7 | QGR 5. FIELD PROJECTION (generation) | 19 | 470 | 8,924 | 2.6% | STATIC | `PromptSections/QueryRulesSection.php:140-156` |
| 8 | key "corpus_translations" (understanding) | 27 | 309 | 8,339 | 2.4% | STATIC | `QueryUnderstandingService.php:484` |
| 9 | Guidelines (composition) | 17 | 452 | 7,691 | 2.2% | ~static | `ResponseSections/GuidelinesSection.php:141-216` |
| 10 | REQUESTED ATTRIBUTES (generation) | 16 | 433 | 6,924 | 2.0% | ≥1,650 of ~1,810 chars is instruction text | `PromptSections/NameResolutionSection.php:77-95` |

Next: SECURITY & DATA-PRIVACY POLICY 6,834 (static) · generation CURRENT USER CONTEXT 6,602 · key "register_expansions" 6,015 (static) ·
TENANT SCOPING RULES 5,396 · composition Results 5,390 (dynamic) · key "follow_up_reference" 5,335 · RESPONSE GUARDRAILS 5,325 · key "digest_request" 4,988.

### 2.3 Was each section USED on the turn? (evidence)

**Understanding prompt (25,374 static chars every turn; sent before the question type is known)**

- **U1 — Entity catalogue (13,587 chars, 53% of the prompt).** 73 entities; over all 148 turns the QU model emitted only 20 distinct labels.
  The list is load-bearing as a mechanism (QU `entity_types` seed selection, pruning, chips, PII masking — `ContextRetriever.php:411, 841-846`,
  `ActionAttacher.php:464`), so KEEP the list; the fat is in each line: 199 of 357 "aka" aliases are trivial variants of the label itself
  (`visit_detail, visit_details, visit detail, visit details`) = 2,565 chars ≈ 548 tokens/turn, and 37 of 73 descriptions are cut mid-sentence by
  the 120-char truncation (`…`). NEEDS-CHECK (A/B): drop label-derivable aliases; cut descriptions at a sentence boundary.
- **U2 — `corpus_translations` + `register_expansions` (2,489 chars ≈ 532 tokens/turn, 14.4k/run).** Parsed output was non-empty on 3 of 6 knowledge
  turns in p9 and on **0 of 26 knowledge turns from p10 to p14** (plus 4 stray data turns). QU output is ~200 tokens when they are absent and
  ~280–330 when present, so this is the model not emitting them, not truncation (max_tokens 500). Knowledge answers were still judged ok with
  3–4 passages retrieved. So the cross-lingual / register feature the host config describes (ai.php:562–580) is currently NOT running, and its
  instructions are pure noise on every turn. The register sentence is also pasted twice inside the prompt (once per key, ~430 chars each).
  NEEDS-CHECK — first decide: fix it (it silently regressed at p10) or remove it. Do not just delete: a French-only question set may depend on it.
- **U3 — `needs_clarification` + `candidate_readings` + `clarifying_question` (1,235 chars ≈ 264 tokens/turn).** `needs_clarification` was true on
  0 of 148 turns, including all 6 runs of "Show me the balance." (conversation `ambiguous-balance-clarify`), which went to `empty_result`
  ("no linked records", or "view your balance on the My profile page") and was labelled ok. NEVER-FIRED on the one case built for it. NEEDS-CHECK.
- **U4 — `intent` (684 chars).** The doc says "Never "file_listing" when the user asks to RECEIVE records … AS a file"; the model returned
  `file_listing` on 13 export follow-ups ("Create a PDF of this.", "Parfait — exporte-la en Excel."). The exports still worked, i.e. the rule is
  both disobeyed and harmless — a sign this 20-key prompt is past what gpt-4o-mini follows reliably.
- USED: `digest_request` (10 turns), `navigation_target` (50), `follow_up_reference` (24), `export_format` (31), `search_tags`, `clarified_question`
  (fed to generation as `<clarified_intent>`), `subject_label` (export titles). `person_names`: 0/148 but the suite has no names — keep.

**Generation prompt**

- **G1 — Persona + vendor-secrecy + "never reveal this query language" in the SYSTEM message (1,914 chars ≈ 458 tokens × 19).** The output of this
  call is a JSON Cypher object the user never sees. The persona talks about buttons, `{{record:N}}`, emails, "PDF or Excel?" preference questions.
  Nothing in it can be used by a Cypher generator; "ask a short preference question instead of guessing" is actively the wrong instruction here.
  Emitted by `SemanticPromptBuilder.php:241-244`. NEEDS-CHECK (A/B with a one-line role note instead).
- **G2 — ACTION REQUESTS (2,197 chars ≈ 525 tokens × 19).** Lists ~35 generic action keys (13 of them unreachable, plus built-ins `open_modal`,
  `render_table`, `link`), then an 800-char paragraph ("ACTIONS NEVER REPLACE DATA QUESTIONS") whose code comment says it exists because the list
  itself once made the model skip a data question. Its only possible effect is `needs_query:false` for pure navigation asks. Across 103 generation
  calls: 13 null Cyphers — 10 export follow-ups (unrelated to this section), 1 "Create a PDF of this." and **2 "Show me the balance."** answered
  with "view your balance on the My profile page" (p9-A, p13-A) — consistent with exactly the failure the guardrail was written against.
  Observed benefit: none; observed harm: plausibly 2. NEEDS-CHECK (A/B: remove `EntityActionAwarenessSection` from the host's
  `query_generator_sections`, or reduce it to entity lines only).
- **G3 — QGR 5 FIELD PROJECTION (1,965 chars) vs REQUESTED ATTRIBUTES (≈1,650 chars of instructions around a 2-line bullet list).** Same rules
  twice in one prompt — quotes in 2.4 #1. Dedupe saves ≈400 tokens on 16 of 19 generation turns.
- **G4 — Business rules**: table in 1.3d. Always-on 614 tokens; several irrelevant to most turns.
- **G5 — TENANT SCOPING RULES (avg 1,188 chars ≈ 284 tokens).** 77 per-label lines shipped, 43 for labels in the final Cypher. The model put
  `$ai_team_ids` in 62 of 90 Cyphers (8 of 15 in p14) while the executor reported `security_filter_applied=true` on 89 of 103 executions — code injects
  scope when the model does not. No real `UNION`, `CALL {}` or `EXISTS {}` appears in any of the 90 Cyphers, so the "UNION RULES (critical)" block
  (≈420 chars) and the UNION bullets in QUERY SHAPE RULES / QGR 6 were irrelevant to every eval turn. **KEEP — security, and your memory notes say
  generator-emitted scope is the intended direction.** Listed only for the contradiction in 2.4 #5.
- **G6 — GRAPH SCHEMA (dynamic, avg 8,208 chars).** 31% of card characters are for labels absent from the final Cypher (29% over all runs);
  on used cards 826 props were listed, 143 referenced; `notes:` lines are 23% of card chars. Not all of that is waste (the model needs candidates),
  but one cluster is: on "balance" questions `AccountBalance` (19 renders), `Account` (23) and `Bank` (3) were rendered and used in **0** Cyphers,
  dragging in rule ai.php:236 and three tenant-scope lines (≈2k chars per such turn). The largest card is `Union` (avg 4,211 chars, ~40 props such as
  insurer/emergency fields). These are entity-config (`config/ai-entities/*`) issues, out of this file's scope — NEEDS-CHECK there.
- **G7 — DETECTED BUSINESS CONCEPTS (285 tokens on 17 of 19).** Mixed: the `BoardMember.CURRENT_BOARD_MEMBERS` scope was copied verbatim into a
  correct answer (clear value); `Team.MEMBERS` (shown 46×, all runs) and `Invoice.FOR_UNIT` (14×) patterns were not used by the Cyphers that passed.
  Scope definitions live in the entity configs; keep the section, review those two scopes.
- **G8 — QGR 6 "Optimize for performance (use indexes, avoid cartesian products)", "Use descriptive variable names"**: generic filler (≈150 chars).
  "Always include LIMIT clause" was followed in 71 of 90.
- No `SIMILAR QUERIES` section ever rendered (similar_queries = 0 on 148 turns) — zero tokens, nothing to trim.

**Composition prompt**

- **C1 — Persona (1,377 chars ≈ 322 tokens × 20).** ≈835 chars of it restate package sections that are already in the same prompt (insider voice,
  links/buttons, no offers, don't guess) — 1.4 #1, #7, #8. Despite the rule appearing twice, 4 of 27 p14 answers still end with an offer
  ("just let me know!"), so repetition is not buying compliance.
- **C2 — Guidelines.** The "For claims about laws, regulations, policies…" bullet (≈330 chars) ships on data turns with no file content
  (11 of 14 data compositions in p14, 62 of 79 over all runs). Language is instructed three times (system rule 238 chars + first guideline
  ≈250 + final reminder 104). "Direct answer first" three times (two guidelines + ANSWER FORMAT in Results).
- **C3 — Project Context (324 chars) + domain statement in RESPONSE GUARDRAILS (≈480) + persona's first two sentences (≈200)**: three descriptions
  of the same domain per prompt.
- USED: Results/COMPUTED TOTALS/HOMONYMS/ANSWER FORMAT (code-generated, clearly followed — the unit-310 answer reports the three unions separately),
  Links-and-actions line, RELEVANT FILE CONTENT (citations), CURRENT USER CONTEXT.
- SECURITY & DATA-PRIVACY POLICY (342 tok) and RESPONSE GUARDRAILS (266 tok): static, 608 tokens per composition call. KEEP.
- Digest composer prompt (2 turns): compact, purpose-built, all 8 rendered sections used. No candidate.

### 2.4 Rules that duplicate or contradict each other

1. **Requested attributes, said twice (duplicate).**
   QUERY GENERATION RULES §5 — `QueryRulesSection.php:140`: "If the REQUESTED ATTRIBUTES block lists data concepts, each mapped property MUST appear in the RETURN clause as an output column"
   and `:150`: "PRECEDENCE: REQUESTED ATTRIBUTES OVERRIDE any column list suggested in an entity description…".
   REQUESTED ATTRIBUTES — `NameResolutionSection.php:81`: "every mapped property MUST appear in the RETURN clause as an output column (in addition to an identifying column…), not only in a WHERE filter"
   and `:89`: "PRECEDENCE: these requested attributes OVERRIDE any column list suggested by an entity description elsewhere in this prompt."
2. **Scope pattern: "exactly" vs canonical formula "EXACTLY" (contradiction + internal duplicate).**
   §2 `QueryRulesSection.php:112`: "EXCEPTION: If the DETECTED BUSINESS CONCEPTS block provides a CYPHER PATTERN, use that pattern EXACTLY as written";
   §4 `:130`: "CRITICAL: If the DETECTED BUSINESS CONCEPTS block has a CYPHER PATTERN, you MUST use it in your WHERE clause";
   DOMAIN CALCULATIONS `CalculationsSection.php:57`: "use its canonical formula EXACTLY — do not invent a different aggregation."
   On "How much does unit 310 owe right now?" both were present (scope `FOR_UNIT`: `n.customer_type = 'unit' AND n.customer_id = $unitId`, and the
   `unit_outstanding_balance` formula). They cannot both be obeyed; the model followed the formula (correct) and ignored the "MUST".
3. **Canonical formula vs LIMIT (contradiction).** §6 `QueryRulesSection.php:162`: "Always include LIMIT clause (default LIMIT 100)" vs
   "use its canonical formula EXACTLY … ADD columns; never change the aggregation itself" — none of the four host formulas (ai.php:271, 279, 286, 296) has a LIMIT.
4. **DISTINCT (duplicate/tension).** §4 `:133` "Use DISTINCT when specified to avoid duplicates" vs §6 `:163` "Use DISTINCT when traversing relationships".
5. **Tenant scope: "you MUST add it" vs "it is added automatically" (contradiction, host vs package).**
   `TenantScopingRulesSection.php:65-67`: "every query MUST keep results within the user's teams. For each labelled node you MATCH and use, add its team filter EXACTLY as shown"
   vs host calculation ai.php:270: "(tenant scoping adds the team filter automatically)" and the formulas, which contain no `$ai_team_ids`.
   The model resolved it inconsistently (62 of 90 Cyphers carry the filter). Fix the host wording, keep the package rule.
6. **Only-what-is-listed, three times (duplicate).** §1 `QueryRulesSection.php:90` "Use only labels and relationships from the GRAPH SCHEMA block";
   §3 `:122` "Use a relationship traversal only when the exact source label, relationship type, target label, and direction are listed…";
   GRAPH SCHEMA header `SchemaCardsSection.php:72` "Never use a label, property, relationship type or direction that is not listed."
   The fk-no-edge rule is likewise in §3 and in the schema header.
7. **Output format twice (duplicate).** §7 `QueryRulesSection.php:175-178` and YOUR TASK `TaskInstructionsSection.php:53` + its closing
   `JSON RESPONSE ({"needs_query", "cypher", "reason"})` both restate the JSON contract.
8. **UNION, three places (duplicate; unused in every eval turn).** QUERY SHAPE RULES `SemanticPromptBuilder.php:431` "One query part per UNION branch; each branch must MATCH its own labelled nodes";
   `TenantScopingRulesSection.php:80-81` "Treat each UNION branch as a complete, independent query: MATCH its own labelled nodes…"; §6 "With UNION, every branch's RETURN must project the same column names".
   (Security-adjacent → KEEP; just noting.)
9. **Offers (contradiction).** `GuidelinesSection.php:199`: "Do NOT end with an offer or a question ("let me know", "would you like…", "feel free to ask")" vs
   `ResponseGuardrailsSection.php:45`: "Instead, offer to perform the action and ask the user to confirm it." vs
   `Response/DataAnswerPlan.php:823`: "then at most one short closing next step." vs persona ai.php:347–349: "ask a short preference question ("PDF or Excel?"…)".
10. **Buttons exist vs don't mention buttons (contradiction on chip-less turns).** Persona ai.php:331–332 (always): "Links to pages and files are added as buttons under your answer: rely on the "Attached below this answer" list"
    vs `GuidelinesSection.php:200` (when no chip is attached): "…and do not mention links, buttons or attachments." Chip-less composition turns: 15 of 112 (6 of 20 in p14).
    The code comment at `GuidelinesSection.php:193-195` records a live failure from exactly this clash.
11. **Language, three times (duplicate).** system `identity.language_policy` · `GuidelinesSection.php:147` · `ResponseGenerator.php:851` final reminder.
12. **Insider voice, three times** and **no-offers / no-URLs, twice** — host persona vs package sections (1.4 #1, #7, #8).
13. **Host path vs package rule.** `user_entity_paths.Thread` uses `EXISTS { MATCH (c:Contact)…}` (ai.php:515) vs QUERY SHAPE RULES "Do not use … EXISTS {} that bind sensitive nodes" (`SemanticPromptBuilder.php:432`).
14. **Team members.** Rule ai.php:216 + scope `Team.MEMBERS` + identity anchors (`CurrentUserContextSection.php:76`) all say `User.current_team_id`; passing answers use `TeamRole`.

---

## 3. Ranked candidates (confidence × size)

Tokens are per p14-style run (27 turns, 342k input). "Lines" are host-config lines.

| # | Candidate | Where | Size | Mark | Evidence / the check |
|---|---|---|---|---|---|
| 1 | Entity catalogue: drop label-derivable aliases, cut descriptions at sentence end | `ai/src/Services/QueryUnderstandingService.php:189-212` | ≈548 tok/turn → 14.8k/run (4.3%); whole list is 22.9% | NEEDS-CHECK | 199/357 aliases are case/plural/snake variants; 37/73 descriptions truncated mid-sentence. Check: one A/B eval run, compare `entity_types` per turn. |
| 2 | `corpus_translations` + `register_expansions` instructions (and host `corpus_languages` / `corpus_register`) | `QueryUnderstandingService.php:484, :632`; host ai.php:562–580 | 532 tok/turn → 14.4k/run (4.2%) | NEEDS-CHECK (decide fix vs remove) | Empty output on 26/26 knowledge turns p10–p14; answers still ok. Check: log raw QU JSON on 5 knowledge questions (incl. French); if still empty, either repair or A/B a run with both keys removed. |
| 3 | Persona + vendor-secrecy + "never reveal query language" in the GENERATION system message | `ai/src/Services/SemanticPromptBuilder.php:241-244` | 458 tok × 19 → 8.7k/run (2.5%, gpt-4o) | NEEDS-CHECK | Output is JSON Cypher, never shown to the user. Check: A/B one run with a one-line role note. |
| 4 | ACTION REQUESTS section in generation | `ai/src/Services/PromptSections/EntityActionAwarenessSection.php:49-128` | 525 tok × 19 → 10.0k/run (2.9%) | NEEDS-CHECK | 0 beneficial effects in 103 calls; 2 "Show me the balance." no-query outcomes consistent with the failure its own guardrail describes. Check: A/B with the section removed from the host `query_generator_sections`; watch `ambiguous-balance-clarify` and navigation asks. |
| 5 | 13 unreachable generic actions | host ai.php: 1062–1067, 1076–1088, 1097–1102, 1140–1155, 1168–1175, 1221–1260, 1270–1276 | ≈96 lines + ≈110 tok per generation turn | NEEDS-CHECK (product decision) | No attach path in `ActionAttacher`; 0 invocations in 148 turns. Check: ask "open the calendar"; then either add placement or delete. |
| 6 | Dedupe QGR §5 vs REQUESTED ATTRIBUTES | `QueryRulesSection.php:140-156`, `NameResolutionSection.php:77-95` | ≈400 tok × 16 → ≈6.4k/run (≈2%) | NEEDS-CHECK | Verbatim-equivalent rules (2.4 #1). Check: A/B; watch the roster + "contact info" and "add their last login" turns. |
| 7 | Clarification trio in QU | `QueryUnderstandingService.php` `clarifyInstruction()` (called `:226`) | 264 tok/turn → 7.1k/run (2.1%) | NEEDS-CHECK | True on 0/148 incl. the 6 turns designed for it. Check: decide whether clarification should happen at QU at all; A/B removed vs repaired. |
| 8 | Host config history comments + `chat.page_examples` (8 empty arrays) | host ai.php: blocks listed in 1.6; 644–704 | ≈290 lines combined | SAFE | Comments and empty arrays; no behaviour. Keep the gate-rationale comments. |
| 9 | Action `aliases` arrays | host ai.php: one line in each of 49 actions | ≈49 lines | SAFE | No reader matches or prints them; 0 probe hits in any prompt. |
| 10 | Persona sentences that restate package sections (links/buttons, insider voice, no offers) | host ai.php:331–334, 340–342, 346–347 | ≈835 chars → ≈195 tok × 20 compositions ≈ 3.9k/run (generation side is counted in #3) | NEEDS-CHECK | Triplicated (1.4); contradicts the chip-less guideline on 15/112 turns. Check: A/B; watch insider-voice gate (`team-info-insider`) and no-offer endings. |
| 11 | Business rule "When you are in a team…" | host ai.php:220 | 129 chars × every generation turn | SAFE | Voice rule sent only to the Cypher generator; already covered 3× at composition. |
| 12 | Business rule COLLECTIVE possessives → entity-gate or drop | host ai.php:221–225 | 608 chars always-on → ≈145 tok × 19 | NEEDS-CHECK | Package emits the same guidance with the paths (`CurrentUserContextSection.php:246-249`). Check: A/B; watch "our board", "our next meeting", "owe our suppliers". |
| 13 | Business rule "Team members: NO CURRENT_TEAM…" | host ai.php:216 | 612 chars on 80/103 turns | NEEDS-CHECK | Ignored by passing answers (TeamRole 24 vs current_team_id 7 of 69). Check: confirm membership semantics in the app, then rewrite or drop (and the `Team.MEMBERS` scope with it). |
| 14 | Law/citation guideline on data turns | `ai/src/Services/ResponseSections/GuidelinesSection.php:187` | ≈77 tok on 11/14 data compositions | NEEDS-CHECK (small) | No file content on those turns. Check: gate on `fileContext.relevant_files`; A/B. |
| 15 | HOMONYM SAFETY rule → gate to entities / shorten | host ai.php:240 | 706 chars always-on | NEEDS-CHECK | Relevant to 3/15 p14 Cyphers; repeated in calc notes and enforced by code at composition. This rule fixed a graded failure — check with the `my-balance-then-pdf` conversation before touching. |
| 16 | `AccountBalance` pulled in by "balance" (+ rule ai.php:236) | entity config (alias/description), not this file | ≈2k chars on such turns | NEEDS-CHECK | Rendered 19×, used 0×. Check in `config/ai-entities/finance.php`. |
| 17 | QGR filler + internal duplicates (§2 EXCEPTION vs §4 CRITICAL, §6 performance/variable-name bullets, §7 vs YOUR TASK) | `QueryRulesSection.php:112, 130, 162-169, 175-178` | ≈700 chars → ≈170 tok × 19 | NEEDS-CHECK | 2.4 #2, #4, #7. Low risk, but the DBC "MUST" also needs a precedence line vs calculations. |
| 18 | `union_manager` duplicate of `Union.manage` | host ai.php:1168–1175 | 8 lines | SAFE to merge (part of #5) | Same route/label; wrong gate tier. |

Upper bound if every NEEDS-CHECK prompt item passes its A/B: ≈1,340 tok/turn in understanding (24% of that prompt), ≈1,580 in generation (23%),
≈335 in composition (12%) → ≈74k of 342k input tokens per run (≈21%). Host config: ≈340 lines with zero behaviour change (#8, #9), ≈96 more if #5 is deleted.

Suggested order (cheapest proof first): #8, #9, #11 (no eval needed) → #2 and #7 (diagnose: these are features that silently do nothing) →
one A/B run each for #3, #4, #6 (all generation-side, gpt-4o tokens) → #1 → host persona/rules #10, #12, #13.
Run each A/B as its own eval run and read the traces by hand, per your removal rule.

---

## 4. KEEP list (explicit)

| Item | Where | Why |
|---|---|---|
| `$staffOnly`, `$permanentOnly`, `$boardOrStaff` | host ai.php:21–68 | Authorize gates; for several routes the closure is the ONLY gate (ai.php:784, 947–948, 1002, 1014–1015, 1107–1109). |
| `$hasPermission`, `$inCurrentTeam`, `$mutation`, `$reservationTeam`, `$taskTeam` and the 4 write actions | host ai.php:70–173, 860–935 | Security gates. Currently guard actions nothing can propose (1.2c) — owner decision, not a removal recommendation. |
| Every `'authorize' => …` on entity/generic actions | throughout 751–1306 | Same. One to FIX, not remove: `union_manager` should be `$permanentOnly`. |
| TENANT SCOPING RULES + QUERY SHAPE RULES | `TenantScopingRulesSection.php`, `SemanticPromptBuilder.php:416-439` | Tenant isolation; injector fallback is known-fragile (confirmed cross-tenant leak fixed 2026-10-01). Only fix the host's contradicting wording (2.4 #5). |
| SECURITY & DATA-PRIVACY POLICY, RESPONSE GUARDRAILS, model-vendor non-disclosure (composition) | `PrivacyAndSecurityGuidelinesSection.php:28`, `ResponseGuardrailsSection.php:44` | Privacy/anti-injection/out-of-scope; 608 static tokens per composition, justified. |
| `guardrails.domain_scope` | host ai.php:306–312 | Drives the out-of-scope decline; if deduping the three domain descriptions, keep this one. |
| `calculations` (all 4) | host ai.php:266–299 | Fired 43×; formulas reproduced verbatim in correct answers. `team_total_owed` needs an eval question. |
| `attention_sources` (9 enabled + 2 staged) and `digest.max_sources` | host ai.php:1333–1495 | Ran 10/10 digests with 0 errors; digest = invest. |
| `user_entity_paths` (all 11) | host ai.php:402–528 | 5 rendered and followed; the other 6 are resident paths the lead-only eval cannot exercise. Check `Thread` (EXISTS). |
| Fired actions (1.2a) and never-fired-but-reachable actions (1.2b) | — | Reachable; absence from a single-persona suite is not evidence. |
| Entity-gated business rules: MONEY OWED (231), Invoice team_id (242), Unit names (215), ContactUnit (217) | host ai.php | Relevant on most turns they ship (5/7, 4/4, 4/6); encode verified data realities. |
| `llm_roles`, `currency_symbol`, `export` limits, `health.middleware`, `scope_matching`, `ui.theme`, `debug`, `file_context.physical_paths`, `chat.example_questions`, `attached_actions.identity.team`, `entities` loader | host ai.php | All read by package code and all differ from package defaults. |
| QU keys `digest_request`, `navigation_target`, `follow_up_reference`, `export_format`, `search_tags`, `clarified_question`, `subject_label`, `entity_types` | `QueryUnderstandingService.php:235-249` | Each fired and is consumed downstream. |
| DOMAIN CALCULATIONS, DETECTED BUSINESS CONCEPTS, REQUESTED ATTRIBUTES (the mechanism), GRAPH SCHEMA cards, code-computed Results blocks (totals, HOMONYMS, ANSWER FORMAT) | package | Dynamic, and visibly followed in the traces. |

---

## 5. Things I did not verify

- Whether `php artisan config:cache` fails on this config (closures) — not run by instruction.
- Whether provider prefix caching discounts the static QU prompt — not recorded in traces.
- Resident / board-member behaviour of any item — the eval has only the lead persona.
- Entity configs (`config/ai-entities/*.php`: scopes, aliases, card notes) were only seen through the prompts; G6/G7 and candidate #16 need a pass over those files.
- The `relevant`-to-Cypher column in 1.3d is a regex heuristic on the final Cypher, not a judgement of whether the rule shaped the query.

Helper scripts used (same scratchpad folder, read-only over the traces): `sections.js`, `static.js`, `usage.js`, `usage2.js`, `cards.js`, `misc.js`, `und.js`, `und2.js`, `routes.js`, `comments.js`, `cfgtop.js`, `cy.js`.
