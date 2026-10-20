# Entity-config audit — CondoEdge `config/ai-entities/*.php`

Read-only audit, 2026-10-06. No project file, git state, graph or database was modified. Paths: package = `C:\Users\jkend\Documents\Projects\kompo\ai` (`src/...`), host = `C:\Users\jkend\Documents\Projects\kompo\CondoEdge` (`config/ai-entities/...`).

## 0. Verdict in one page

- The config is 11,624 lines, 73 entities, 16 files (3 of them are 16-line `return []` stubs: `billing.php`, `imports.php`, `settings.php`).
- **1,633 lines (14%) are provably inert** — no runtime code reads them, or they are filtered out before reaching any prompt. Removing them changes no prompt, no query, no ingest. They are the only items rated SAFE.
- **A further ~5,700 lines are plausible removals but every one of them changes behaviour** (prompt content, entity selection, ingest mapping, or needs a package change first). They are rated NEEDS-CHECK, each with the specific check.
- **Three of the task's expected categories yield nothing to cut by hand:**
  - *Descriptions*: 13,251 chars in total (avg 181 per entity; only User, Resource, VisitDetail exceed 600). Full descriptions are rendered in schema cards. Nothing to cut.
  - *Comments/history*: 208 comment lines (1.8%). Most guard a fix that an eval graded. Nothing worth cutting except the 3 stub files.
  - *"Config duplicates what the graph auto-derives"*: true for the prompt, but not removable. 71% of the property names printed in cards are also found by the 25-node type sampler, and all rendered edges come from live patterns. But `graph.properties` and `graph.relationships` are the **ingest mapping** and the input to tenant scoping, and only 5 of the host's 137 Nodeable models mention `$fillable`, the package's only fallback. These lists are machine-derivable, not deletable (§3.12).
- **The evidence for "never used" is weaker than it looks.** `ai_query_logs` is dev/eval traffic, not production: 2,036 rows, 941 distinct cyphers, but only **120 distinct questions**, 1–2 users since June. The local graph is a dev graph. Every usage-based removal therefore needs a production check.
- **Biggest real finding is not a line count.** 133 config properties and 225 of 389 config relationships do not exist in the live graph, and some of them are printed into prompts as facts (e.g. every Invoice card tells the model to join on `team_id` and `eft_line_id`, which are set on 0 of 6,006 Invoice nodes).

| Rating | Lines | Share |
|---|---|---|
| SAFE (inert, no behaviour change) | 1,633 | 14.0% |
| NEEDS-CHECK, package change required first (vector blocks) | 1,395 | 12.0% |
| NEEDS-CHECK, usage-based (never-selected entities) | 1,675 | 14.4% |
| NEEDS-CHECK, prompt-affecting (generic scopes, stale relationships, variant aliases, stale props) | ~2,650 | ~23% |
| Must keep / no case for removal (about 3,000 of these are machine-derivable lists, §3.12) | ~4,270 | ~37% |

Categories overlap slightly (a never-selected entity also contains SAFE lines); the SAFE figure is exact, the others are upper bounds.

---

## 1. How entity config is consumed

73 entities, 4 top-level blocks each (`graph`, `vector`, `security`, `metadata`). Field inventory was taken from the evaluated arrays, consumers from the package source.

### 1.1 Field → consumer map

| Field | Instances | Lines | Runtime consumer (file:line) | Reaches a prompt? |
|---|---|---|---|---|
| `graph.label` | 73 | 73 | `EntityLabelIndex`, `SchemaCardBuilder:558`, `QueryUnderstandingService:195`, ingest (`GraphConfig:86`) | yes |
| `graph.properties` | 919 props | 1,079 | Ingest mapping (`GraphConfig:87`); schema `properties` by label (`ContextRetriever:1130-1153`) → card `props:` line, merged with the names the type sampler finds on 25 nodes (`SchemaCardBuilder:386-405`, `PropertyTypeSampler:30, 85-88`); `context_index` property points (`SemanticContextSelector:525-552`) | yes, every card |
| `graph.computed` / `with` / `casts` | 3 / 3 / 1 entities | 17 | Ingest (`GraphConfig:66-83`, `GraphPropertyMapper`); card props (`SchemaCardBuilder:392`) | yes |
| `graph.relationships[].type/target_label/foreign_key/direction` | 389 | (2,918 total) | **Security** `TeamPathResolver:180-209, 238-256`; ingest sync (`RelationshipConfig::fromArray:56-71`, needs `foreign_key`); card edges/`declares()`/fk hints (`SchemaCardBuilder:258-381`); pruner adjacency (`EntitySelectionPruner:421-432`); selector expansion (`SemanticContextSelector:696-727`) | yes |
| `…[].morph` | 46 | 46 | `RelationshipConfig:69, 93-107` (ingest) | no |
| `…[].pivot` | 29 | 29 | `SchemaCardBuilder:293, 356`; `EntitySelectionPruner:427` | indirectly |
| `…[].description` | 24 | 24 | Card note, first sentence only, only if the edge is rendered (`SchemaCardBuilder:180-185`) | yes |
| `…[].related_model` | 6 | 6 | Only `ai:enhance` (`EnhanceConfigCommand:128`) — dev-time | no |
| **`…[].inverse_type`** | 312 | 312 | **none** — written by `RelationshipDiscoverer:352-393`, read only from freshly discovered in-memory arrays (`:532-603`, `EntityAutoDiscovery:486`) | no |
| **`…[].inverse`** | 55 | 55 | **none** (written `RelationshipDiscoverer:363, 391`) | no |
| **`…[].inferred`** | 17 | 17 | **none** (written `RelationshipDiscoverer:198`) | no |
| **`…[].discriminator_fields`** | 6 | 18 | **none** at runtime (discovery only, `EntityAutoDiscovery:479`) | no |
| **`…[].pivot_conditions`** | 3 | 3 | **none** (zero occurrences in `src/`) | no |
| `vector.collection/embed_fields/metadata` | 73 | 1,395 | Ingest only (`DataIngestionService:91, 268, 604-615`, `BatchDataIngestionService:402-413`). **No query-time code searches these collections** (all `vectorStore->search` callers: `SemanticContextSelector:80, 288`, `ScopeSemanticMatcher:65`, `SchemaProjector:351`, `SimilarQuerySearcher:60`, `QdrantChunkStore`) | no |
| `security.team_path` | 73 | 73 | **Security** `TeamPathResolver:160-170` | yes (tenant rules) |
| `security.sensible_columns` | 12 non-empty | 129 | **PII** `PiiMasker:333`, `VectorPayloadBuilder:42-43`, `ResponseGenerator`, `DataAnswerPlan` | no (masking) |
| **`security.team_resolution`** | 73 | 73 | **none** (written `EntityAutoDiscovery:553-573`) | no |
| **`security.team_query_scope`** | 73 | 73 | **none** (written `EntityAutoDiscovery:554, 578`) | no |
| **`security.multiple_teams`** | 73 | 73 | **none** (written `EntityAutoDiscovery:555, 564`) | no |
| **`security.has_owner_bypass`** | 73 | 73 | **none** (written `EntityAutoDiscovery:556, 583`) | no |
| `metadata.aliases` | 751 | 900 | QU catalog, **first 5 only** (`QueryUnderstandingService:196`); card, **first 8 unique** after folding separators/case and dropping the label itself (`SchemaCardBuilder:227-242`); every alias embedded in `context_index` (`SemanticContextSelector:448-459`) and `semantic_entities` (`SemanticIndexer:133-142`); lexical fusion (`ContextRetriever:1110`); keyword fallback (`EntityMetadataBuilder:136-142`) | yes |
| `metadata.description` | 73 | 73 | QU catalog truncated to 117 chars (`QueryUnderstandingService:200-202`); full text in a full card, first sentence in a compact card (`SchemaCardBuilder:211-225`); embedded whole (`SemanticContextSelector:432-435`) | yes |
| `metadata.common_properties` | 932 | 1,147 | Card enum legends and notes (`SchemaCardBuilder:158-178, 420-432`); response code→label (`CodedColumns:192-197`) | partly — see §3.4 |
| `metadata.scopes.*.concept/examples` | 202 scopes, 696 examples | (2,816 total) | Embedded (`SemanticContextSelector:557-598`, `ScopeSemanticMatcher:129-181`); printed when detected (`DetectedScopesSection:52-94`) | when detected |
| `…scopes.*.cypher_pattern` | 192 | 192 | Printed when detected (`DetectedScopesSection:74, 103, 205`). **Prompt hint only — never injected into the query by code** | when detected |
| `…scopes.*.specification_type` | 198 | 198 | Selects the render branch (`DetectedScopesSection:56-78`) | when detected |
| `…scopes.*.filter` | 193 | 455 | Rendered **only** when `specification_type === 'property_filter'` (`DetectedScopesSection:65-66`) | 88 of 202 scopes |
| `…scopes.*.parsed_structure` | 3 | 44 | Only for `relationship_traversal` (`DetectedScopesSection:59-63`) | 3 scopes |
| `…scopes.*.business_rules`, `role_value` | 1, 3 | 9 | `DetectedScopesSection:80-86, 194` | when detected |
| **`…scopes.*.parameters`** | 5 | 32 | **none** | no |
| **`…scopes.*.name`, `source_model`** | 4, 4 | 8 | **none** (written `InheritanceResolver:222, 341, 399`) | no |
| `metadata.money_properties` | 5 entities | 5 | `MoneyColumns:148`, `TableRenderer`, `DataAnswerPlan` | no (response) |
| `metadata.examples` (entity level) | 8 entities, 54 | 70 | Embedded as entity points (`SemanticContextSelector:462-472`) | no |
| **`metadata.security_notes`** | 2 | 2 | **none** (written `AiConfigEnhancer:342-343`) | no |
| **`metadata.child_models`** | 2 | 6 | **none** at runtime (`DiscoverEntitiesCommand:196` prints it during discovery) | no |

Bold rows have no runtime reader. Confirmed three ways: word-level search of `src/`, of the host's `app/ config/ routes/ database/ resources/`, and of the host `vendor/` (the only hits are unrelated Google API classes). `ai:validate-config` requires none of them (`ValidateConfigCommand:56-113`).

### 1.2 Facts about the pipeline that change what "unused" means

1. **Entity selection is seeded by Query Understanding, not by the vector index.** `EntitySelectionPruner` uses direct vector hits only when nothing else seeded the selection (`EntitySelectionPruner.php:30-32`). In p9–p14, QU returned no entity type on 9 of 103 query-generation turns. So aliases, entity examples and property points in `context_index` influence selection on roughly 9% of data turns; scope points are searched on every turn (`SemanticContextSelector:221-235`).
2. **`context_index` holds no relationship points.** `createRelationshipPoints` reads `$config['relationships']` (top level, `SemanticContextSelector:483`); the host config only has `graph.relationships`. The live collection has exactly 2,691 points = 73 entity + 751 alias + 54 example + 919 property + 894 scope. Property points are bare `Label.property` strings because the indexer reads `metadata.property_descriptions` (`:530`), a key the host never sets — `common_properties` is not embedded at all.
3. **Scopes are advisory text.** A detected scope is printed as "USE THIS EXACT CYPHER PATTERN"; nothing rewrites the query with it. Tenant security is separate (`TeamPathResolver`). Scopes are therefore not security config.
4. **Removing an entity from config does not remove it from the graph.** Auto-sync skips unconfigured models silently (`HasNodeableConfig.php:106-112`), so existing nodes stay and go stale. The live graph has **51 labels with no config, holding 406,399 of 738,895 nodes (55%)** — the residue of the 2026-07-23 trim. They still surface: `Blueprint`, `Servicing` and `BudgetDetailQuote` received schema cards in p9–p14 through the closure tier.

---

## 2. Evidence base and its limits

| Set | What it is | Size | Limit |
|---|---|---|---|
| Eval traces | `CondoEdge/storage/eval/p9…p14-1001-{A,B}.json` (p10 has part A only) | 11 files, 148 turns, 103 query-generation prompts | A fixed gold question set |
| Query log | `ai_query_logs` (model `src/Models/AiQueryLog.php`) | 2,036 rows, 941 distinct cyphers (1,981 success / 55 failed), 2026-03-19 → 2026-10-01 | **120 distinct questions**; 551 rows from 6 users in March, then 1–2 users. Dev and eval traffic, not production. `context_stats` and `metadata` columns are empty on all rows, so injected scopes are not recorded |
| Live graph | Neo4j via the package graph store, read-only `MATCH … RETURN` | 115 labels, 738,895 nodes, 1,119,067 relationships, 248 (from, type, to) patterns | Local dev graph. 8 of the 9 zero-node config labels also have 0 MySQL rows locally |
| Vector store | `getCollectionInfo` only | `context_index` 2,691, `scope_examples` 894, `semantic_entities` 1,319, `semantic_scopes` 484, `questions` 12; per-entity collections: 53 exist with 302,481 points, 20 configured ones missing | — |

Prompt composition measured on the 103 query-generation prompts (average 28,312 chars):

| Section | Avg chars | Share |
|---|---|---|
| GRAPH SCHEMA (cards) | 8,115 | 28.7% |
| QUERY GENERATION RULES | 5,463 | 19.3% |
| PROJECT CONTEXT | 4,021 | 14.2% |
| ACTION REQUESTS | 2,195 | 7.8% |
| DETECTED BUSINESS CONCEPTS (scopes) | 1,003 | 3.5% |
| everything else | 7,515 | 26.5% |

Inside the schema cards (5.1 cards per prompt): props line 35.1% (2,121 chars), notes 31.2% (1,886), descriptions 19.5% (1,182), edges 8.9% (537), aliases 4.0% (244), fk hints 1.3% (76).

The QU prompt averages 25,794 chars (measured on the 27 QU calls of p14), of which the entity catalog is **13,587 chars (53%)**, sent on every turn including knowledge questions. Token conversion used below: 4.76 chars/token (measured: 25,794 chars → 5,418 input tokens).

---

## 3. Findings by category

### 3.1 Dead keys — SAFE (1,036 lines)

| Item | Lines | Where |
|---|---|---|
| `security.team_resolution`, `team_query_scope`, `multiple_teams`, `has_owner_bypass` | 292 | 4 lines in each of the 73 `security` blocks, e.g. `calendar.php:75-78` |
| `relationships[].inverse_type` | 312 | e.g. `calendar.php:28, 36, 45, 53` |
| `relationships[].inverse` | 55 | supplier 30, general 15, finance 9, contact 1 |
| `relationships[].inferred` | 17 | finance 9, supplier 4, general 2, filemanager 1, phone 1 — e.g. `finance.php:1840, 1846, 1852, 1858` |
| `relationships[].discriminator_fields` | 18 | finance only (6 blocks) |
| `relationships[].pivot_conditions` | 3 | condo only |
| scope `filter` on scopes whose type is not `property_filter` | 291 | parametric 268, contextual 14, temporal 5, others 4 — e.g. `calendar.php:107-109, 121` |
| scope `parameters`, `name`, `source_model` | 40 | 5 + 4 + 4 scopes |
| `metadata.security_notes`, `metadata.child_models` | 8 | `calendar.php:151`, `condo.php:1261`, `general.php:514, 1136` |

Evidence: §1.1. These keys are discovery output. Nothing reads them back from the file.

Note on `security.*`: these four are **not** the scoping config. Tenant scoping reads only `security.team_path` and `graph.relationships` (`TeamPathResolver:160-256`). Removing the four dead keys cannot affect scoping; `team_path` and `sensible_columns` stay.

One caution: a later `ai:discover` run would write all of these back. The removal only lasts if discovery stops emitting them.

### 3.2 Property notes that never render — SAFE as a set (549 lines)

`common_properties` has 932 entries. `SchemaCardBuilder` drops a description when it is boilerplate (`:420-432`):

| Class | Entries | Chars | Fate |
|---|---|---|---|
| Dropped: same text on ≥3 entities | 281 | 6,135 | never rendered |
| Dropped: property is a relationship foreign key | 166 | 4,749 | never rendered |
| Dropped: restates the property name | 93 | 782 | never rendered |
| Orphan: no such property in config or graph | 9 | 267 | never rendered (`Bill.union_id`, `Bill.supplier_id`, `Bill.approved_by`, `Bill.recurrence_id`, `Bill.approval_reset`, `Bill.eft_line_id`, `Entry.invert_temp`, `Entry.unvoid_flag`, `Resource.union_id`) |
| Enum legend (`1=Draft, 2=Approved…`) | 48 | 3,918 | rendered inline in props; also used by `CodedColumns` for response labels — **keep** |
| Informative note, property used in some cypher | 170 | 8,072 | rendered in full cards |
| Informative note, property never in any cypher | 165 | 5,828 | rendered in full cards — see §3.9 |

The 549 never-rendered entries are not enum legends, so `CodedColumns` ignores them too. The ≥3-entities rule depends on global frequency, so I simulated the removal: with all 549 gone, no remaining entry changes class, and removing only the fk and restates-name subsets does not make any generic entry start rendering. Verification before applying: render all 73 cards before and after and diff — the output must be byte-identical.

Per file: finance 147, supplier 116, general 92, condo 58, messaging 33, meetings 32, contact 18, task 15, discussions 14, calendar 7, phone 7, filemanager 6, eft 4.

### 3.3 Stub files — SAFE (48 lines)

`billing.php`, `imports.php`, `settings.php` are 12 comment lines plus `return [];`. The loader globs the directory (`CondoEdge/config/entities.php:21-23`), so deleting them changes nothing. The comments record why billing, imports and settings were pruned; that rationale survives in git and in the header comments of the other files.

### 3.4 Vector blocks — NEEDS-CHECK, package change first (1,395 lines)

- Every entity carries `vector.collection`, `embed_fields`, `metadata` (73 + 224 + 1,025 + 73 lines). 799 of the 806 `vector.metadata` entries repeat `graph.properties`.
- Ingest embeds each record into a per-entity Qdrant collection: 302,481 points in 53 collections locally.
- No package code searches those collections (§1.1), and the host `app/` and `routes/` contain no vector-store usage at all.
- **Not removable by config edit today.** `getVectorConfig()` throws `LogicException` when the block is missing (`HasNodeableConfig.php:375-387`). The batch path treats that as "graph-only entity" (`BatchDataIngestionService.php:409-411`), but the single-entity path catches it as a vector failure and **deletes the graph node it just wrote** (`DataIngestionService.php:89-110`).
- Related, not verified live: 14 entities already have `embed_fields => []` (BoardMember, ContactUnit, AccountBalance, TeamRole, BudgetDetail, Voter, Vote, MaintenanceDate, WorkOrder, Conciliation, Recurrence, UnionContract, BillApproval, UnionApproval). `VectorConfig` throws `InvalidArgumentException` for an empty list (`VectorConfig.php:29-31`), which is a `LogicException`, so on the single-entity path these would hit the same rollback. Their nodes exist (e.g. 2,927 ContactUnit), so batch ingest works; whether auto-sync on save silently removes them is worth one test.
- Check: (1) confirm nothing outside this repo reads the per-entity collections; (2) make `vector` optional in the single-entity path; (3) then drop the blocks and stop embedding 300K records per ingest.

### 3.5 Never-selected entities — NEEDS-CHECK (14 entities, 1,675 lines)

No schema card, no QU pick, no cypher in p9–p14, and no cypher in the query log:

| Entity | file:lines | Lines | Nodes | Referenced by | In `ai.php` |
|---|---|---|---|---|---|
| EftFile | `eft.php:9-136` | 128 | 0 | — | no |
| Folder | `filemanager.php:9-126` | 118 | 0 | File | no |
| UnionApproval | `finance.php:2539-2621` | 83 | 0 | — | no |
| MessageForward | `messaging.php:326-415` | 90 | 4 | — | no |
| Conciliation | `finance.php:1406-1493` | 88 | 121 | Account | no |
| SupplierContact | `supplier.php:1501-1692` | 192 | 237 | Supplier, Team | no |
| Tax | `finance.php:2199-2293` | 95 | 320 | Account, BillDetail, InvoiceDetail, Team | no |
| Component | `supplier.php:9-176` | 168 | 455 | Maintenance, Union | no |
| BillDetail | `finance.php:1039-1146` | 108 | 766 | Bill | no |
| Vote | `meetings.php:422-507` | 86 | 1,827 | Motion, Voter | no |
| Discussion | `discussions.php:132-255` | 124 | 3,103 | Channel, Maintenance, Project | no |
| CallLog | `phone.php:9-125` | 117 | 4,428 | Contact | no |
| TaskDetail | `task.php:274-413` | 140 | 6,163 | Task | **yes (3)** |
| Message | `messaging.php:188-325` | 138 | 63,500 | Attachment, Review, Thread | **yes (4)** |

None is a tenant hop in another entity's `team_path`. EftFile carries a `sensible_columns` entry (`return_accountno`), which matters only if its nodes ever exist.

Prompt effect if all 14 go: the QU catalog loses 2,566 chars, about **540 tokens on every turn (10% of the QU prompt)**. No effect on query-generation prompts in the eval set, since none was ever carded.

Why this is not SAFE:
- The log is 120 distinct dev/eval questions. A condo manager asking about call logs, discussions or votes is plausible and simply not in the set.
- TaskDetail and Message are referenced by `ai.php` (digest / attention sources / actions). Keep those two regardless.
- Removal leaves the nodes in the graph (§1.2 point 4), where they stay reachable by closure and stop being updated. An entity removal has to be paired with deleting the label's nodes, and with confirming how the security gate treats a label that has nodes but no config (`SensitiveLabelCoverageGate.php:336-346` derives its sensitive set from `config('entities')`).

Strongest subset: **EftFile, Folder, UnionApproval, MessageForward — 419 lines**, 4 nodes in total, no `ai.php` reference, at most one inbound reference. Check: row counts in the production MySQL tables and a search of production questions.

Nine more entities are RARE (≤3 logged cyphers, absent from p9–p14; 1,042 lines): Channel, BillApproval, Fund, Motion, Voter, EmailAccount, MaintenanceContract, MaintenanceDetail, SupplierRating. Same check, weaker case.

**Zero-node labels are not removal candidates on that ground alone.** Pet (16 logged cyphers), Reservation (25), Instruction (7), UnionContract (4), BillApproval (3) and SupplierRating (1) were all asked about. They are empty only in the dev data.

### 3.6 Scopes — NEEDS-CHECK (up to 1,241 lines)

202 scopes, 2,816 lines, 696 example questions.

- **22 scopes were ever injected** in p9–p14 (159 injections over 88 of 103 prompts): `Team.members` 46, `Invoice.for_unit` 14, `Meeting.upcoming` 11, `Meeting.past` 11, `BoardMember.current_board_members` 6, `BoardMember.for_union` 6, `Meeting.for_team` 6, `Bill.open` 6, `Invoice.open` 6, `Invoice.is_contribution` 6, `ContactDirectory.phone` 5, `ContactDirectory.is_email` 5, `BudgetDetail.not_invoiced` 5, `Contact.for_current_team` 5, `Contact.for_team` 5, `Meeting.for_union` 5, `Supplier.for_auth_user` 4, `Bill.for_supplier` 2, `Activity.for_team` 2, `Supplier.for_team` 1, `ContactUnit.current` 1, `Supplier.from_central_list` 1. These 22 (297 lines) are in use — keep.
- 180 scopes (2,369 lines) were never injected. That is a property of the 120-question set, not proof of uselessness; the log does not record scopes, so there is no second source.
- **94 scopes (1,241 lines) are generic tenancy/ownership filters** generated from Eloquent scopes: `for_union` ×28, `for_auth_user` ×24, `for_team` ×18, `for_unit` ×10, `for_supplier` ×8, `for_current_team` ×3, `search_me` ×3. They restate what TENANT SCOPING RULES and CURRENT USER CONTEXT already say. 84 of them (1,111 lines) were never injected.
- **99 scope patterns contain `$placeholders`** (`$unionId` 28, `$userId` 24, `$teamId` 17, `$unitId` 10, `$supplierId` 8, …). Across 941 logged cyphers and all trace cyphers, the only parameter that ever appears is `$ai_team_ids`, which the security layer adds. The "exact pattern" is never followed literally.
- **10 scopes have no `cypher_pattern`** (101 lines): `contact.php:354-434` (Contact `match_name`, `search_me`, `match_email`, `for_auth_user`, `for_team`, `for_current_team`) and `general.php:485-504` (TeamRole `notContactUser`, `forAuthUser`, `forCurrentTeam`, `forTeam`). When detected they print a concept and example questions with nothing actionable. `Contact.for_team` and `Contact.for_current_team` were injected together on 5 turns, taking 2 of the 3 scope slots each time; Contact appeared in the cypher on 2 of those turns.
- 3 scopes are stale against the dev graph: `Resource.booked_during` and `Resource.never_reserved` use `[:RESOURCE]` and `:Reservation` (no such edge or node locally — dev data only), and `Unit.ordered_by_name` calls APOC (already flagged in `docs/onboarding/04-production-runbook.md:24`).
- 20 scopes (247 lines) sit on never-selected entities and go with them.

Prompt effect: the scope section averages 1,003 chars per prompt (about 210 tokens), of which example questions are roughly 260 chars. Removing generic scopes does not shrink a prompt by itself; it frees the top-3 scope slots (`ai.scope_matching.top_k = 3`) for specific concepts.

Check: A/B eval run with the 94 generic scopes removed and `ai:index-context` re-run. Watch the turns where `BoardMember.for_union`, `Meeting.for_team`, `Meeting.for_union` and `Supplier.for_auth_user` are currently injected (21 injections, entity used in the cypher on all 21). The 10 pattern-less scopes are the cheapest first step.

### 3.7 Aliases — NEEDS-CHECK (159 variant lines; 389 lines beyond position 5)

751 aliases, 900 lines.

- **159 aliases are separator/case variants or the label itself** (`visit_detail`, `visit_details`, `visit detail`, `visit details`). The card renderer folds these away (`SchemaCardBuilder:233-237`), so they never reach a query-generation prompt.
- They do reach the QU prompt, and that is the problem: QU sees the first 5 aliases, and **137 of the 362 catalog alias slots are spent on these variants**. `VisitDetail (aka: visit_detail, visit_details, visit detail, visit details, détail de visite)` shows one real synonym out of five; the other French and domain terms sit at positions 6–16 where QU never sees them.
- 389 aliases are beyond position 5 (never in QU); 152 unique aliases are beyond the card cap of 8 (never in any prompt). All 751 are embedded in `context_index`, which only seeds selection when QU names nothing (9 of 103 turns), and are used by lexical fusion.
- 14 aliases are claimed by two entities: `condo fees`, `contributions`, `cotisations`, `charges` (Fund and Invoice); `attachment`, `attachments`, `pièce jointe` (Attachment and File); `commentaire(s)` (Note and Review); `activité(s)` (Activity and Event); `avis` (Announcement and Notification); `team users` (TeamRole and User); `soumission` (QuoteRequest and WorkOrder).

Removing the 159 variants is not inert: it shifts real synonyms into the QU window. That is probably an improvement, and it costs no tokens (the catalog stays 5 aliases wide), but it changes routing, so it needs the eval. The hand-ordered alias lists (Unit, Invoice, ContactDirectory, Contact) already put real synonyms first and must keep their order.

### 3.8 Stale properties and relationships — NEEDS-CHECK (prompt-visible)

**Properties.** Of 822 config properties on labels that have nodes, **133 are set on no node at all**:

- 58 are `deleted_at` — listed on 58 labels, never set on any node (soft-deleted rows are not in the graph). It is still printed, untyped, in every card, and generated cyphers reference it: ContactUnit 35 distinct cyphers, Meeting 18, Announcement 13, Invoice 13, Bill 10.
- 75 others across 30 labels (list in Appendix B). Several were used in generated cyphers although always null: `Team.team_address` 24, `Unit.square_foot` 9, `Review.reviewed_at` 9, `Unit.room_count` 7, `Unit.intercom` 6, `WorkOrder.accepted_at` 6.
- Prompt cost: 223 chars per prompt (about 47 tokens).

The card header tells the model "never use a property that is not listed", so a listed-but-empty property reads as usable.

**Relationships.** Of 389 configured relationships, 164 are live edges in the declared direction; 225 are not:

| Class | Count | Lines | Runtime effect |
|---|---|---|---|
| Has-many / inverse declarations with no usable FK, labels already linked by a live edge in the other direction | 102 | 681 | No edge rendered, no fk hint, never synced (no FK → `RelationshipConfig::fromArray` returns null). Pruner adjacency already comes from the live pattern. Remaining effect: selector expansion, and `TeamPathResolver`'s first-match-by-target lookup |
| Outgoing FK, edge absent | 41 | 305 | Rendered as `fk (no edge, join with WHERE)` hints. Useful when the FK column is populated, misleading when it is not |
| Target label not in config | 37 | 239 | 12 point at `Tag`, 6 at `Address`; 3 targets (`FileFolder`, `BudgetDetailDate`, `EftLine`) do not exist in the graph either |
| An endpoint has 0 nodes locally | 25 | 166 | Dev-data artefact — keep |
| Pivot (belongsToMany), never synced | 16 | 88 | Skipped by card and pruner (`pivot` check) |
| Same shape, labels not otherwise linked | 4 | 26 | The config entry is the only adjacency link |

Three concrete, prompt-visible cases:

1. `finance.php:1842-1847` — Invoice `BELONGS_TO_EFT_LINE` → `EftLine`. The label was removed on 2026-07-23 and has no nodes; `eft_line_id` is set on 0 of 6,006 Invoice nodes. Yet **all 34 Invoice cards** in p9–p14 carry `eft_line_id → EftLine.id (no :BELONGS_TO_EFT_LINE)`.
2. `finance.php:1854-1859` — Invoice `BELONGS_TO_TEAM` (inferred), `team_id` set on 0 of 6,006 nodes. All 34 Invoice cards carry `team_id → Team.id`. A model that follows the hint gets zero rows; the real path is `union.team_id`.
3. `condo.php:1826-1832` — Unit `REPRESENTED_UNION`, `represented_union_id` set on 0 of 1,877 nodes, hint shown in all 45 Unit cards.

Checks: on the production graph, `MATCH (n:Label) WHERE n.prop IS NOT NULL RETURN count(n)` for each stale property and FK. For any relationship removal, confirm no `team_path` resolves through it — `TeamPathResolver:240-241` takes the **first** relationship whose `target_label` matches, so order matters. For `deleted_at`, confirm ingest drops soft-deleted rows, then expect the generator to stop emitting `deleted_at IS NULL`.

Side finding on scoping (not a removal): 9 entities have a `team_path` whose first hop is not a live edge. Eight are zero-node labels. The ninth is VisitDetail (`maintenanceDate.maintenance.team_id`, `VisitDetail-[:BELONGS_TO_MAINTENANCE_DATE]->MaintenanceDate` absent with 42 VisitDetail nodes): the tenant clause requires that edge, so a scoped VisitDetail query matches nothing locally. The 15 logged VisitDetail cyphers (all from 2026-03-19) returned 0 rows or a single aggregate row. That fails closed, but the answer is wrong.

### 3.9 Informative notes on unused properties — NEEDS-CHECK, low value (165 entries: 159 + 6)

Notes cost 1,886 chars per prompt (about 400 tokens) — the second-largest part of the schema cards. Only 248 of those chars describe a property the same turn's cypher used. But a note can be doing its job by steering the model away from a property, so "not used" does not show "useless".

The narrow candidate is the 159 notes on properties that appear in no cypher anywhere (177 chars per prompt, about 37 tokens) plus 6 timestamp notes that slip past the boilerplate filter because their wording differs slightly (`Unit.created_at: Record creation timestamp`, etc.; 62 chars per prompt). Small gain; only worth doing with an eval run already planned.

### 3.10 Entity-level examples — NEEDS-CHECK, low value (70 lines)

8 entities carry `metadata.examples` (VisitDetail 10, Resource 15, Union 5, Unit 5, Contact 5, Invoice 5, Meeting 5, Building 4). They are embedded as entity points and influence only the vector tier (§1.2 point 1). Never printed.

### 3.11 Selected but never used — observation, not a removal

Cards that were rendered in p9–p14 and never appeared in that turn's cypher: Account 23 cards (avg 731 chars), AccountBalance 19 (QU picks it for "balance"/"owe" questions; avg 325 chars), InvoiceDetail 5, BudgetDetail 5, Budget 5, Transaction 4 (avg 1,275 chars). That is about 34,700 chars across 103 prompts, roughly 70 tokens per prompt. This is a selection matter (QU catalog wording, tenant-hop cards), not config bloat; `2026-09-30-phase1b.md:33` already records the AccountBalance guess as a failure.

### 3.12 Config that repeats the live graph — machine-derivable, not removable (about 3,000 lines)

| Config | Lines | What the graph already provides | Why it still has to exist |
|---|---|---|---|
| `graph.properties` | 1,079 | In the 103 prompts, 6,524 of 9,144 printed property entries (71%) carry a sampled type, meaning the sampler found the name on its 25 nodes and would have listed it without the config. The config's own contribution is the 2,620 untyped entries (375 chars per prompt, about 79 tokens): 1,462 never set on any node, 1,158 sparse | Ingest writes exactly these columns (`GraphConfig:38-40` rejects an empty list). The fallback is `['id'] + $fillable` (`HasNodeableConfig.php:577-584`); only 5 host models under `app/Models` mention `$fillable` |
| 164 live-edge relationships | 1,157 | Rendered edges come from live patterns (`SchemaCardBuilder:258-278`) | Ingest creates the edges from these entries; `TeamPathResolver` reads them for scoping |
| `vector.metadata` | 1,025 | 799 of 806 entries repeat `graph.properties` | See §3.4 |

None of this is hand-knowledge: property lists and belongs-to relationships are what `ai:discover` generates from the database schema. The route to near-zero config for these ~3,000 lines is the machine-owned discovery cache already planned in `docs/plans/2026-09-29-simplify-and-strengthen-implementation.md` (task 12.2), with host files holding only overrides — not deleting the lists.

---

## 4. Ranked candidates

Ranked by confidence × size. "Prompt effect" is per turn.

| # | Candidate | Lines | Rating | Evidence | Prompt effect | Check |
|---|---|---|---|---|---|---|
| 1 | Never-rendered `common_properties` entries (§3.2) | 549 | SAFE | Filter rules `SchemaCardBuilder:420-432`; removal simulated, no rendering change | none | Diff rendered cards before/after — must be identical |
| 2 | Relationship keys `inverse_type`, `inverse`, `inferred`, `discriminator_fields`, `pivot_conditions` | 405 | SAFE | No reader in `src/`, host or vendor | none | Stop discovery re-emitting them |
| 3 | `security.team_resolution/team_query_scope/multiple_teams/has_owner_bypass` | 292 | SAFE | Written only by `EntityAutoDiscovery:553-583` | none | Same |
| 4 | Scope `filter` on non-`property_filter` scopes | 291 | SAFE | Only reader `DetectedScopesSection:65-66` | none | — |
| 5 | Stub files `billing.php`, `imports.php`, `settings.php` | 48 | SAFE | `return []`, glob loader | none | — |
| 6 | Scope `parameters`/`name`/`source_model`, `security_notes`, `child_models` | 48 | SAFE | No runtime reader | none | — |
| 7 | `vector` blocks | 1,395 | NEEDS-CHECK | No query-time search of per-entity collections | none on prompts; removes 302K embeddings from ingest | Package change first: `DataIngestionService:89-110` rolls back the graph node when the block is missing |
| 8 | Invoice `BELONGS_TO_EFT_LINE` + `eft_line_id`, Invoice `BELONGS_TO_TEAM` + `team_id`, Unit `REPRESENTED_UNION` + `represented_union_id` | ~25 | NEEDS-CHECK | 0 nodes carry the FK; hints printed in 34/34 Invoice and 45/45 Unit cards | about −12 tokens, and removes three false join hints | Production graph: count non-null FK; confirm Invoice `team_path` stays `union.team_id` |
| 9 | 10 scopes with no `cypher_pattern` | 101 | NEEDS-CHECK | `contact.php:354-434`, `general.php:485-504`; 10 injections with no pattern | frees up to 2 of 3 scope slots on affected turns | Eval run; re-index |
| 10 | EftFile, Folder, UnionApproval, MessageForward | 419 | NEEDS-CHECK | 4 nodes total, no usage anywhere, not in `ai.php` | −150 tokens (QU catalog) | Production row counts and question logs; delete nodes; confirm gate behaviour for unconfigured labels |
| 11 | 159 separator/case alias variants | 159 | NEEDS-CHECK | Folded away by `SchemaCardBuilder:233-237`; occupy 137/362 QU alias slots | 0 tokens; changes which aliases QU sees | Eval run (routing) |
| 12 | 94 generic tenancy/ownership scopes | 1,241 | NEEDS-CHECK | `$placeholders` never appear in 941 cyphers; duplicates tenant rules | frees scope slots | A/B eval; watch the 21 injections listed in §3.6 |
| 13 | 102 has-many/inverse relationship declarations | 681 | NEEDS-CHECK | Not rendered, not synced, adjacency already live | none expected | Diff cards and pruner selections before/after; confirm no `team_path` first-match changes |
| 14 | Remaining 8 never-selected entities (excluding TaskDetail, Message) | 978 | NEEDS-CHECK | No usage in 120 questions | −310 tokens (QU catalog) | Production question logs |
| 15 | 75 stale non-`deleted_at` properties | ~150 | NEEDS-CHECK | Never set on any node | −34 tokens | Production graph counts; ingest mapping changes |
| 16 | `deleted_at` on 58 labels | ~116 | NEEDS-CHECK | Never set on any node | −13 tokens | Confirm ingest drops soft-deleted rows; watch for validator rejections |
| 17 | 159 notes on never-queried properties + 6 timestamp notes | ~165 | NEEDS-CHECK | Rendered, property in no cypher | −50 tokens | Eval run |
| 18 | Entity-level `examples` | 70 | NEEDS-CHECK | Vector tier only | none | Eval turns where QU returns no entity |

SAFE total: 549 + 405 + 292 + 291 + 48 + 48 = **1,633 lines**. Per file (items 1–3 only, which can be attributed exactly; 1,246 lines): finance 308, supplier 258, general 182, condo 194, meetings 71, messaging 69, contact 42, task 36, discussions 33, calendar 15, phone 15, filemanager 14, eft 9.

Total possible prompt saving if every NEEDS-CHECK item passed its check: about 470 tokens on the QU prompt (9%; the 12 never-selected entities other than TaskDetail and Message) and about 110 tokens on the query-generation prompt (under 2% of roughly 5,950). The config is large on disk but the pruner already keeps most of it out of prompts; the case for these removals is correctness and maintainability more than tokens.

---

## 5. Must not be removed

| Item | Where | Why |
|---|---|---|
| `security.team_path` (all 73) | every `security` block | The tenant-scoping rule. `TeamPathResolver:160-170`. 49 are relationship paths, 24 direct columns |
| `graph.relationships[].type / target_label / foreign_key` | all files | `TeamPathResolver:180-256` builds the scoping MATCH chain from them; also the ingest edge mapping. A relationship that looks redundant can be the hop a `team_path` resolves through |
| `security.sensible_columns` (12 entities) | Union, Unit, Contact, User, Team, Bank, Supplier, UnionContract, EftFile, Attachment, Message, CallLog | PII masking (`PiiMasker:333`) and exclusion from embeddings (`VectorPayloadBuilder:42-43`). `2026-10-01-p9-partB-grading.md:60` asks for more here (`reduced_c_mobility`), not less |
| `metadata.money_properties` | `condo.php:2174`, `finance.php:926, 1390, 2046, 2516` | Money formatting and computed totals (`MoneyColumns:148`); the $11,493.17 and $1,211.48 totals graded in p9–p13 depend on it |
| `graph.computed`, `with`, `casts` | `condo.php:1804-1810`, `finance.php:775-779, 1808-1812` | Ingest-time `due_amount`, `total_amount`, `outstanding_balance`. The 2026-09-30 comment at `finance.php:766, 1798` records why the old `calc_*` columns were replaced |
| `graph.properties` as a whole | all files | Ingest mapping and the card's property list. Individual stale entries are §3.8; the list itself is not a duplicate of the graph |
| 48 enum legends in `common_properties` | list in §3.2 / Appendix | Rendered inline in cards and used by `CodedColumns` for response labels (`2026-10-01-p13-partB-grading.md:68`: Task status "Open/Processing", flag colours) |
| ContactDirectory aliases, `contact_type_id` legend, `is_email` scope | `contact.php:506-540` | Narrowed and verified against MySQL on 2026-10-01 (`2026-10-01-p9.md:102, 117`; `p11-partA-grading.md:62-68`; `p12-partA-grading.md:150`) |
| User description and email note | `general.php` User entry | The "contact info = User.email" rule (`p11-partA-grading.md:65, 101`); the roster export passed at 0.85 after it (`p13-partA-grading.md:25`) |
| BoardMember scope hotfix comments and pattern | `contact.php:95-110` | 2026-07-23 iter-10 board deadlock fix |
| "No CURRENT_TEAM edge" comment | `general.php:1266-1272` | Records a deliberate omission tied to business rule #3 |
| `Team.members` scope | `general.php` Team entry | Most-injected scope in the eval (46 times, Team in the cypher on 40) |
| Resource scope fixes | `condo.php:1177-1220` | FIX(90c W5) comments explain why the patterns are predicates |
| `REMOVED:` comments | `finance.php`, `contact.php` | They stop a later discovery run from re-adding colliding aliases (`facture`, `receipt`) and phantom entities |
| WorkOrder, Task, Thread, Meeting, Reservation, Bill, Invoice entities | — | Referenced by `ai.php` digest / attention sources / actions |
| The 22 injected scopes | §3.6 | Observed in graded turns |

---

## 6. Things found on the way that are not config removals

1. **Orphan labels in the graph**: 51 labels, 406,399 nodes (55% of the graph) have no entity config — `Distribution` 193,980, `MessageRead` 86,357, `ThreadBox` 49,082, `TaskRead` 19,305, `Tag` 19,149, `DiscussionRead` 11,773. They are no longer synced and still get schema cards through closure. Any further entity removal adds to this unless nodes are deleted too.
2. **`context_index` relationship points are never created** (`SemanticContextSelector:483` reads the wrong key), and property points carry no descriptions (`:530` reads `property_descriptions`). 919 bare `Label.property` points are being embedded for little benefit.
3. **`semantic_scopes`** (484 points, built by `ai:index-semantic`) is never searched by any code. `scope_examples` (894 points) duplicates the scope points already in `context_index`.
4. **`embed_fields => []` on 14 entities** may cause the single-entity ingest path to roll back the graph node (§3.4). Read from code, not reproduced.
5. **VisitDetail tenant rule resolves through a missing edge** (§3.8).
6. The task brief mentions 4 stub files and ~857 distinct cyphers; I found 3 stubs and 941 distinct cyphers.

---

## Appendix A — per-entity table

Columns: `cards` = schema cards in the 103 p9–p14 query-generation prompts; `QU` = turns where QU named the entity; `trace cy` = p9–p14 cyphers using the label; `log cy` = distinct logged cyphers using the label. Class: NEVER-SELECTED = all four are 0; RARE = no p9–p14 presence and ≤3 logged cyphers; SELECTED-UNUSED = carded, never in a p9–p14 cypher, ≤3 logged cyphers; ACTIVE = the rest.

| Label | file:lines | lines | nodes | cards | QU | trace cy | log cy | aliases (total/unique) | scopes (total/injected) | props (cfg/never-in-graph) | rels (cfg/not-live) | class | flags |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Discussion | discussions.php:132-255 | 124 | 3103 | 0 | 0 | 0 | 0 | 8/7 | 1/0 | 10/2 | 6/3 | NEVER-SELECTED |  |
| EftFile | eft.php:9-136 | 128 | 0 | 0 | 0 | 0 | 0 | 7/4 | 1/0 | 26/0 | 1/1 | NEVER-SELECTED | 0-nodes, PII |
| Folder | filemanager.php:9-126 | 118 | 0 | 0 | 0 | 0 | 0 | 6/5 | 1/0 | 11/0 | 6/6 | NEVER-SELECTED | 0-nodes |
| BillDetail | finance.php:1039-1146 | 108 | 766 | 0 | 0 | 0 | 0 | 6/1 | 0/0 | 11/1 | 5/2 | NEVER-SELECTED |  |
| Conciliation | finance.php:1406-1493 | 88 | 121 | 0 | 0 | 0 | 0 | 7/6 | 0/0 | 13/1 | 1/0 | NEVER-SELECTED |  |
| Tax | finance.php:2199-2293 | 95 | 320 | 0 | 0 | 0 | 0 | 11/10 | 1/0 | 10/2 | 1/0 | NEVER-SELECTED |  |
| UnionApproval | finance.php:2539-2621 | 83 | 0 | 0 | 0 | 0 | 0 | 6/1 | 1/0 | 8/0 | 1/1 | NEVER-SELECTED | 0-nodes |
| Vote | meetings.php:422-507 | 86 | 1827 | 0 | 0 | 0 | 0 | 6/5 | 1/0 | 7/1 | 2/0 | NEVER-SELECTED |  |
| Message | messaging.php:188-325 | 138 | 63500 | 0 | 0 | 0 | 0 | 6/5 | 2/0 | 14/1 | 6/3 | NEVER-SELECTED | ai.php, PII |
| MessageForward | messaging.php:326-415 | 90 | 4 | 0 | 0 | 0 | 0 | 6/3 | 1/0 | 9/1 | 2/0 | NEVER-SELECTED |  |
| CallLog | phone.php:9-125 | 117 | 4428 | 0 | 0 | 0 | 0 | 7/4 | 1/0 | 13/0 | 4/2 | NEVER-SELECTED | PII |
| Component | supplier.php:9-176 | 168 | 455 | 0 | 0 | 0 | 0 | 9/8 | 2/0 | 19/2 | 6/4 | NEVER-SELECTED |  |
| SupplierContact | supplier.php:1501-1692 | 192 | 237 | 0 | 0 | 0 | 0 | 10/5 | 4/0 | 12/2 | 7/5 | NEVER-SELECTED |  |
| TaskDetail | task.php:274-413 | 140 | 6163 | 0 | 0 | 0 | 0 | 8/5 | 4/0 | 9/1 | 4/2 | NEVER-SELECTED | ai.php |
| BillApproval | finance.php:952-1038 | 87 | 0 | 0 | 0 | 0 | 3 | 6/1 | 1/0 | 7/0 | 2/2 | RARE | 0-nodes |
| Channel | discussions.php:9-131 | 123 | 138 | 0 | 0 | 0 | 2 | 4/3 | 2/0 | 8/1 | 6/3 | RARE | tenant-hop |
| MaintenanceContract | supplier.php:449-612 | 164 | 56 | 0 | 0 | 0 | 2 | 11/6 | 3/0 | 16/1 | 4/1 | RARE | ai.php |
| Fund | finance.php:1639-1777 | 139 | 384 | 0 | 0 | 0 | 1 | 21/20 | 2/0 | 9/2 | 5/4 | RARE |  |
| Motion | meetings.php:230-317 | 88 | 248 | 0 | 0 | 0 | 1 | 8/7 | 0/0 | 9/1 | 3/1 | RARE | tenant-hop |
| Voter | meetings.php:508-617 | 110 | 2015 | 0 | 0 | 0 | 1 | 6/5 | 1/0 | 11/2 | 4/1 | RARE |  |
| EmailAccount | messaging.php:91-187 | 97 | 14147 | 0 | 0 | 0 | 1 | 9/6 | 0/0 | 12/1 | 3/1 | RARE | ai.php |
| MaintenanceDetail | supplier.php:738-840 | 103 | 54 | 0 | 0 | 0 | 1 | 6/1 | 1/0 | 9/3 | 3/1 | RARE |  |
| SupplierRating | supplier.php:1693-1823 | 131 | 0 | 0 | 0 | 0 | 1 | 11/6 | 2/0 | 13/0 | 3/3 | RARE | 0-nodes |
| Budget | finance.php:1147-1280 | 134 | 46 | 5 | 0 | 0 | 0 | 11/10 | 3/0 | 9/1 | 3/2 | SELECTED-UNUSED | ai.php, tenant-hop |
| BudgetDetail | finance.php:1281-1405 | 125 | 818 | 5 | 0 | 0 | 0 | 6/1 | 1/1 | 11/2 | 5/2 | SELECTED-UNUSED | money/computed |
| InvoiceDetail | finance.php:2074-2198 | 125 | 15706 | 5 | 7 | 0 | 0 | 6/1 | 0/0 | 12/1 | 7/3 | SELECTED-UNUSED |  |
| Entry | finance.php:1494-1638 | 145 | 12329 | 2 | 0 | 0 | 2 | 11/10 | 1/0 | 16/1 | 5/0 | SELECTED-UNUSED |  |
| Activity | general.php:9-131 | 123 | 15226 | 3 | 0 | 0 | 1 | 5/4 | 2/1 | 12/2 | 3/0 | SELECTED-UNUSED |  |
| Bank | finance.php:585-750 | 166 | 166 | 3 | 0 | 0 | 0 | 9/8 | 2/0 | 18/8 | 6/4 | SELECTED-UNUSED | PII |
| File | general.php:132-411 | 280 | 8286 | 1 | 0 | 0 | 1 | 12/11 | 10/0 | 20/4 | 6/2 | SELECTED-UNUSED | ai.php |
| Notification | general.php:519-691 | 173 | 6557 | 1 | 0 | 0 | 0 | 5/4 | 5/0 | 13/1 | 4/0 | SELECTED-UNUSED |  |
| Recurrence | general.php:692-830 | 139 | 15 | 1 | 0 | 0 | 0 | 4/3 | 3/0 | 11/1 | 4/1 | SELECTED-UNUSED |  |
| TeamInvitation | general.php:1141-1216 | 76 | 4 | 1 | 1 | 0 | 0 | 10/5 | 0/0 | 7/0 | 2/0 | SELECTED-UNUSED |  |
| Attachment | messaging.php:9-90 | 82 | 53511 | 1 | 0 | 0 | 0 | 6/5 | 0/0 | 10/1 | 2/0 | SELECTED-UNUSED | PII |
| Union | condo.php:1264-1665 | 402 | 89 | 57 | 32 | 40 | 438 | 22/21 | 3/0 | 39/8 | 28/27 | ACTIVE | ai.php, tenant-hop, PII |
| User | general.php:1217-1375 | 159 | 1370 | 80 | 19 | 34 | 379 | 19/18 | 0/0 | 10/1 | 9/8 | ACTIVE | ai.php, tenant-hop, PII |
| Team | general.php:975-1140 | 166 | 28 | 80 | 45 | 41 | 358 | 9/8 | 1/1 | 10/2 | 10/9 | ACTIVE | ai.php, PII |
| Unit | condo.php:1760-2215 | 456 | 1877 | 45 | 30 | 27 | 248 | 22/21 | 6/0 | 36/7 | 25/23 | ACTIVE | ai.php, tenant-hop, PII, money/computed |
| Contact | contact.php:140-453 | 314 | 3887 | 45 | 11 | 25 | 223 | 22/21 | 6/2 | 14/2 | 16/14 | ACTIVE | ai.php, tenant-hop, PII |
| TeamRole | general.php:412-518 | 107 | 1490 | 32 | 28 | 27 | 184 | 20/11 | 4/0 | 6/0 | 2/0 | ACTIVE |  |
| ContactUnit | contact.php:584-735 | 152 | 2927 | 29 | 1 | 15 | 145 | 17/12 | 4/1 | 11/1 | 3/1 | ACTIVE | ai.php |
| Invoice | finance.php:1778-2073 | 296 | 6006 | 34 | 38 | 23 | 100 | 17/16 | 8/3 | 21/3 | 8/3 | ACTIVE | ai.php, tenant-hop, money/computed |
| Meeting | meetings.php:8-229 | 222 | 103 | 11 | 11 | 11 | 64 | 13/12 | 6/4 | 17/3 | 7/4 | ACTIVE | ai.php, tenant-hop |
| Account | finance.php:9-509 | 501 | 36595 | 23 | 3 | 0 | 34 | 12/11 | 26/0 | 19/1 | 9/4 | ACTIVE | ai.php, tenant-hop |
| BoardMember | contact.php:9-139 | 131 | 292 | 6 | 6 | 6 | 33 | 16/11 | 2/2 | 7/1 | 2/0 | ACTIVE | ai.php |
| AccountBalance | finance.php:510-584 | 75 | 1386 | 19 | 19 | 0 | 23 | 6/1 | 0/0 | 9/1 | 1/0 | ACTIVE | ai.php |
| Bill | finance.php:751-951 | 201 | 558 | 6 | 9 | 6 | 30 | 13/12 | 3/2 | 13/1 | 6/4 | ACTIVE | ai.php, tenant-hop, money/computed |
| Schedule | meetings.php:318-421 | 104 | 875 | 6 | 6 | 5 | 26 | 6/5 | 0/0 | 13/1 | 4/2 | ACTIVE |  |
| Supplier | supplier.php:1138-1500 | 363 | 1091 | 6 | 6 | 6 | 24 | 12/11 | 6/3 | 33/9 | 18/15 | ACTIVE | ai.php, tenant-hop, PII |
| Resource | condo.php:1076-1263 | 188 | 6 | 0 | 0 | 0 | 35 | 27/26 | 3/0 | 14/3 | 2/1 | ACTIVE | ai.php, tenant-hop |
| Building | condo.php:271-399 | 129 | 92 | 0 | 0 | 0 | 30 | 12/11 | 1/0 | 13/9 | 4/3 | ACTIVE |  |
| Announcement | condo.php:118-270 | 153 | 10 | 0 | 0 | 0 | 29 | 9/8 | 4/0 | 7/1 | 5/2 | ACTIVE | ai.php |
| ContactDirectory | contact.php:454-582 | 129 | 7727 | 10 | 11 | 2 | 15 | 7/6 | 4/2 | 7/0 | 2/0 | ACTIVE |  |
| Maintenance | supplier.php:177-448 | 272 | 118 | 0 | 0 | 0 | 27 | 8/7 | 5/0 | 24/2 | 13/6 | ACTIVE | ai.php, tenant-hop |
| Reservation | condo.php:965-1075 | 111 | 0 | 0 | 0 | 0 | 25 | 6/5 | 2/0 | 9/0 | 3/3 | ACTIVE | 0-nodes, ai.php |
| Transaction | finance.php:2294-2538 | 245 | 4505 | 4 | 4 | 0 | 15 | 16/15 | 6/0 | 18/5 | 9/4 | ACTIVE | ai.php, money/computed |
| Pet | condo.php:877-964 | 88 | 0 | 0 | 0 | 0 | 16 | 10/9 | 1/0 | 8/0 | 1/1 | ACTIVE | 0-nodes |
| MaintenanceDate | supplier.php:613-737 | 125 | 565 | 0 | 0 | 0 | 16 | 6/1 | 3/0 | 11/2 | 2/1 | ACTIVE | ai.php, tenant-hop |
| VisitDetail | calendar.php:8-153 | 146 | 42 | 0 | 0 | 0 | 15 | 16/13 | 2/0 | 9/2 | 4/2 | ACTIVE |  |
| Visit | condo.php:2302-2441 | 140 | 49 | 0 | 0 | 0 | 14 | 7/6 | 3/0 | 7/1 | 6/4 | ACTIVE |  |
| Task | task.php:9-273 | 265 | 2082 | 0 | 0 | 0 | 13 | 12/11 | 9/0 | 15/1 | 10/5 | ACTIVE | ai.php, tenant-hop |
| Access | condo.php:9-117 | 109 | 251 | 0 | 0 | 0 | 12 | 15/14 | 1/0 | 7/2 | 4/1 | ACTIVE | ai.php |
| Extra | condo.php:522-656 | 135 | 2241 | 0 | 0 | 0 | 12 | 10/9 | 3/0 | 8/1 | 4/2 | ACTIVE | ai.php |
| Note | condo.php:746-876 | 131 | 269 | 0 | 0 | 0 | 12 | 8/7 | 3/0 | 6/2 | 5/0 | ACTIVE |  |
| Review | general.php:831-974 | 144 | 17 | 1 | 0 | 0 | 9 | 4/3 | 2/0 | 15/4 | 5/2 | ACTIVE | ai.php |
| Event | condo.php:400-521 | 122 | 50 | 0 | 0 | 0 | 9 | 10/9 | 3/0 | 9/1 | 2/0 | ACTIVE |  |
| Vehicle | condo.php:2216-2301 | 86 | 379 | 0 | 0 | 0 | 9 | 12/11 | 1/0 | 7/1 | 1/0 | ACTIVE | ai.php |
| Project | supplier.php:841-1009 | 169 | 49 | 0 | 0 | 0 | 9 | 10/9 | 3/0 | 11/1 | 7/2 | ACTIVE | ai.php, tenant-hop |
| Instruction | condo.php:657-745 | 89 | 0 | 0 | 6 | 0 | 7 | 8/7 | 1/0 | 7/0 | 2/2 | ACTIVE | 0-nodes |
| QuoteRequest | supplier.php:1010-1137 | 128 | 37 | 0 | 0 | 0 | 6 | 12/7 | 1/0 | 9/2 | 6/4 | ACTIVE | ai.php, tenant-hop |
| WorkOrder | supplier.php:1824-1983 | 160 | 117 | 0 | 0 | 0 | 6 | 14/9 | 3/0 | 14/4 | 4/1 | ACTIVE | ai.php |
| Thread | messaging.php:416-570 | 155 | 44686 | 0 | 0 | 0 | 5 | 8/7 | 3/0 | 13/1 | 6/3 | ACTIVE | ai.php, tenant-hop |
| UnionContract | condo.php:1666-1759 | 94 | 0 | 0 | 0 | 0 | 4 | 8/5 | 1/0 | 8/0 | 2/2 | ACTIVE | 0-nodes, PII |

## Appendix B — config properties set on no node (excluding `deleted_at`)

Local dev graph. Labels with 0 nodes are omitted.

- **Access** (251 nodes): `access_for`
- **Activity** (15226 nodes): `note`
- **Bank** (166 nodes): `contact_id`, `note`, `phone`, `address`, `is_investment`, `supplier_id`, `eft_disabled`
- **BoardMember** (292 nodes): `created_at`
- **BudgetDetail** (818 nodes): `reference_date`
- **Building** (92 nodes): `construction_year`, `floor_count`, `elevator_count`, `heating_type_id`, `ventilation_type_id`, `water_heater_type_id`, `pool_type_id`, `note_index`
- **Component** (455 nodes): `supplier_id`
- **Contact** (3887 nodes): `reduced_c_mobility`
- **Discussion** (3103 nodes): `summary`
- **File** (8286 nodes): `order`, `outlook_attm_id`, `expiration_date`
- **Fund** (384 nodes): `is_disabled`
- **Invoice** (6006 nodes): `team_id`, `eft_line_id`
- **Maintenance** (118 nodes): `supplier_id`
- **MaintenanceDate** (565 nodes): `supplier_id`
- **MaintenanceDetail** (54 nodes): `reminder_at`, `completed_at`
- **Meeting** (103 nodes): `doc_title`, `meeting_public`
- **Note** (269 nodes): `note_date`
- **QuoteRequest** (37 nodes): `maintenance_id`
- **Resource** (6 nodes): `restricted_from`, `restricted_to`
- **Review** (17 nodes): `reviewed_at`, `reviewed_by`, `message_id`
- **Supplier** (1091 nodes): `payment_method`, `account_number`, `work_zone`, `is_accounting_contact_id`, `is_emergency_responding`, `invitation_email`, `tax1_number`, `tax2_number`
- **SupplierContact** (237 nodes): `user_id`
- **Tax** (320 nodes): `notes`
- **Team** (28 nodes): `team_address`
- **Transaction** (4505 nodes): `cn_invoice_id`, `cn_bill_id`, `reverse_transaction_id`, `reversed_transaction_id`
- **Union** (89 nodes): `share_pct`, `emergency_contact`, `emergency_phone`, `emergency_email`, `insurer_broker_un`, `send_statements_auto`, `send_statements_day`, `send_statements_balance_due`
- **Unit** (1877 nodes): `room_count`, `square_foot`, `intercom`, `loan_address`, `insurance_coverage_type_id`, `represented_union_id`
- **VisitDetail** (42 nodes): `maintenance_date_id`
- **Voter** (2015 nodes): `voting_power`
- **WorkOrder** (117 nodes): `received_at`, `accepted_at`, `rejected_at`

## Appendix C — per-file line breakdown

| file | entities | entity lines | dead security keys | dead relationship keys | never-rendered notes | vector block | alias lines | alias variants | aliases beyond 5 | scopes | scopes injected | scope lines | relationship lines | props on no node | comment lines |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| calendar.php | 1 | 146 | 4 | 4 | 7 | 16 | 18 | 3 | 11 | 2 | 0 | 26 | 36 | 2 | 0 |
| condo.php | 15 | 2433 | 60 | 76 | 58 | 276 | 216 | 17 | 111 | 36 | 0 | 545 | 663 | 36 | 17 |
| contact.php | 4 | 726 | 16 | 8 | 18 | 63 | 71 | 12 | 42 | 16 | 7 | 232 | 190 | 4 | 46 |
| discussions.php | 2 | 247 | 8 | 11 | 14 | 32 | 16 | 2 | 3 | 3 | 0 | 40 | 85 | 3 | 0 |
| eft.php | 1 | 128 | 4 | 1 | 4 | 24 | 9 | 3 | 2 | 1 | 0 | 14 | 10 | 0 | 0 |
| filemanager.php | 1 | 118 | 4 | 4 | 6 | 18 | 8 | 1 | 1 | 1 | 0 | 13 | 38 | 0 | 0 |
| finance.php | 16 | 2613 | 64 | 97 | 147 | 317 | 198 | 40 | 84 | 55 | 6 | 762 | 576 | 30 | 37 |
| general.php | 9 | 1367 | 36 | 54 | 92 | 160 | 106 | 21 | 45 | 27 | 2 | 352 | 342 | 15 | 11 |
| meetings.php | 5 | 610 | 20 | 19 | 32 | 92 | 49 | 5 | 14 | 8 | 4 | 105 | 148 | 8 | 0 |
| messaging.php | 5 | 562 | 20 | 16 | 33 | 93 | 45 | 9 | 10 | 6 | 0 | 75 | 138 | 5 | 0 |
| phone.php | 1 | 117 | 4 | 4 | 7 | 19 | 9 | 3 | 2 | 1 | 0 | 14 | 30 | 0 | 0 |
| supplier.php | 11 | 1975 | 44 | 98 | 116 | 246 | 131 | 39 | 54 | 33 | 3 | 480 | 564 | 28 | 0 |
| task.php | 2 | 405 | 8 | 13 | 15 | 39 | 24 | 4 | 10 | 13 | 0 | 158 | 98 | 2 | 0 |
| **total** | 73 | 11447 | 292 | 405 | 549 | 1395 | 900 | 159 | 389 | 202 | 22 | 2816 | 2918 | 133 | 111 |

"Entity lines" excludes file headers; the three stub files hold no entities. "Dead relationship keys" = `inverse_type` + `inverse` + `inferred` + `discriminator_fields` + `pivot_conditions`.

## Appendix D — method

- Field inventory: each `config/ai-entities/*.php` file was `require`d inside `php artisan tinker` and walked recursively; line counts come from an indentation-based parse of the source files (73 entities and 11,447 of 11,640 physical lines attributed to a field).
- Consumers: read from package source; "no reader" claims were checked by word-level search over `src/`, the host app directories and host `vendor/`.
- Traces: the `GRAPH SCHEMA` and `DETECTED BUSINESS CONCEPTS` sections of every `prompts.query_generation` entry were parsed into cards and scopes; labels, properties and relationship types were extracted from each cypher by pattern.
- Graph: three read-only queries (label counts, `(from, type, to)` pattern counts, property-key counts per label).
- Card-rendering rules (`isInformative`, `EnumLegend::parse`, alias folding) were re-implemented to classify config entries; the re-implementation reproduces the measured notes total within 3% (1,834 computed vs 1,886 measured chars per prompt).
- Not done: no eval was run, no production data was seen, no config was rendered through the real `SchemaCardBuilder`. Every NEEDS-CHECK item names the check that would settle it.
