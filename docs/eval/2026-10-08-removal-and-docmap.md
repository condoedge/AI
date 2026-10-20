# 2026-10-08 — removal of unused parts · document-map / reasoning experiment

Owner direction (2026-10-08): (1) "Remove everything that is not used." (2) Make the assistant REASON about documents
and "see further than what the user says" — the tear-down-wall question is the benchmark; idea: preprocessing
(summaries at indexing) and finding by it. Then: "be really skeptical about my idea". State after [p24](2026-10-07-p24.md).

## 1. Removal — round 1 (done, verified; unit suite 4,550 green; 8-turn live smoke identical routes)
Backups + manifest: `CondoEdge/storage/app/ai-backups/2026-10-08-removal/` (MANIFEST.md, package/, host/, ai-eval/,
graph/ — ≈ 525 MB incl. raw mail of the deleted InboundEmail nodes: personal data, git-ignored).

| Where | Removed |
|---|---|
| Host entity configs | 1,579 lines (11,280 → 9,701): keys nothing reads (team_resolution, inverse_type, inferred, dead scope filters, property notes the card builder always drops, 3 stub files). All 73 schema cards + 49 legends byte-identical before/after |
| Package | 2,026 lines net: dead config keys, 24 lang keys ×2, the per-angle reranker entirely (disabled everywhere; file order proven unchanged), TypingIndicator, executePaginated / explain / test and their config keys |
| Graph | 22 orphan labels, 93,912 nodes, 2,340 relationships — each dumped and verified before deletion |

Less than the audit promised, because the recheck found readers: ≈ 440 config lines the audit called dead are read
now (e.g. `relationships[].inverse` by the action attacher); **27 "orphan" labels (117,264 nodes) are still
targeted by declared relationships / prompt text / join paths** — deleting them means removing those config
relationship entries first: a prompt change, to be measured on its own. Address, Distribution, MessageForward,
Folder untouched (separate decisions).

Assessment only (nothing changed): **97.5% of the vector store is never read** — 53 per-entity collections
(302,481 points) are written at every ingest / sync and searched by no code; 28 collections (31,185 points) are
leftovers of entities no longer configured; `semantic_scopes` is written and never read. Used: file_chunks,
documentation_chunks, context_index, questions, scope_examples and semantic_entities (fallback paths).
Document search does NOT tie a file to a record: it filters by the user's accessible file ids only — with many
buildings' documents, another building's similar by-law can surface.

Round 2 (done, verified: 4,514 tests in the targeted sweep, 8-turn smoke identical routes):
- HybridFuser removed entirely (off everywhere; a deterministic probe of ContextRetriever is byte-identical).
- Ingest / sync / delete write the GRAPH ONLY: no embedding call, no per-entity vector upsert (1 embedding call +
  1 upsert saved per record saved; ≈ 300k embedded records per full ingest). `VectorPayloadBuilder` and the
  `semantic_scopes` writer deleted. Stored vector data NOT touched (342,810 points, owner decision pending).
  Idempotent live re-sync of Team #3: node, 77,008 relationships and its vector point unchanged.
- Eval fingerprint hashes `{ai, entities}`; the host's duplicate `ai.entities` key is gone (`config_sha1` values
  changed once — not comparable with p24's).
- Totals so far: package −4,979 lines, host −1,595, 22 graph labels / 93,912 nodes.
- KEPT because code still reads them: the entity `vector` blocks in the host entity configs (1,395 lines) and the
  `getVectorConfig()` contract (read by ai:validate-config, the discoverer, the Nodeable trait) — removing them is a
  contract change on every host model: next removal step. Some graph-side stress tests (10 MB payloads, binary data,
  concurrent updates) went with the dual-storage tests and are not replaced.

Round 3 (done, verified, reversible — owner: "be careful… estimations and control" / "Remove them"):
- QDRANT 88 → 6 collections: 82 collections no code reads (334,150 points) snapshotted, downloaded (3.1 GB,
  sha256 verified 82/82, restore tested on 7), then deleted. Control: Qdrant's per-collection search counters
  since start (20,939 searches) and over a six-question exercise show 0 searches on every deleted collection;
  the six read collections are byte-identical. Storage 3.3 GB → 153 MB.
- GRAPH: 22 of the 27 labels deleted (111,486 nodes, 41,463 relationships; dumped, counts verified twice; restore
  helper tested) after a per-label recheck against 1,340 distinct logged production queries + 222 eval queries.
  29 config entries removed (157 lines, 9 files) → 7 schema cards lose one line each (Account, BillDetail,
  Component, ContactDirectory, InvoiceDetail, Maintenance, File); 66 cards byte-identical. Graph now 71 labels,
  533,497 nodes.
- KEPT FOR DECISION (evidence in the manifest): Member (114; used by logged queries, wrong path for "team
  members"), AnnouncementRead (84; the `Announcement.unread` scope is written through it), AccessType (3; the only
  place an access device's kind lives), PhoneConfiguration (2; the only place the phone lines' names live),
  BudgetDetailQuote (5,575; the only link between a budget line and a unit — "unit 310's share of the budget").
- Removed but user-askable (restorable): who read a task / discussion, mailbox placement, tag names (never
  joinable to items), the predefined invoice / bill item catalogue (lines themselves untouched), unit shares of a
  fund, per-line taxes.

Round 4 (done, verified, reversible — the dead per-entity vector contract; backups under `round4/`):
- RECHECK first: nothing functional read an entity's `vector` block. The three semantic indexes
  (`semantic_entities`, `context_index`, `scope_examples`) are built from `graph` + `metadata` only — proven by
  running the real index builders against a capturing in-memory store before / after: byte-identical.
- HOST: the 73 `vector` blocks removed (1,395 lines; entity configs 9,544 → 8,149). Effective config: 985 leaves
  gone, all `vector.*`, nothing else changed, order preserved. All 73 schema cards byte-identical — not a prompt
  change. `config_sha1` changes once (the fingerprint hashes the entity config).
- PACKAGE: `Nodeable::getVectorConfig()`, `VectorConfig`, the `NodeableConfig` vector builder methods, the trait's
  `$embedFields` / default-collection handling, `EmbedFieldDetector`, the discoverer's `vector` output, the
  validator's vector rules, `SemanticIndexer::indexTemplates()` / `rebuildIndexes()`, `ai:index-semantic --templates`
  and `query_generation.templates` — 634 lines of src / config, 466 of tests (21 tests of removed code, 1 added).
  `Nodeable` is now `getGraphConfig()`, `getId()`, `toArray()`. No host model overrode anything; no other app on
  this machine uses the package (SISC has only a leftover vendor symlink), so the method was removed, not deprecated.
- ai-eval: the cache-flag clearing nothing sets (29 lines). DOCS: 23 pages of `resources/docs/1.0` rewritten to
  graph-only ingest + the six Qdrant collections (`usage/data-ingestion#what-is-stored-where`).
- Verified: 157 targeted phpunit runs / 4,738 tests green; `ai:validate-config` clean; `ai:discover --dry-run` emits
  no vector block; 8-turn smoke 8/8, routes and row counts as before; Qdrant untouched (six collections, same counts).
- TO DECIDE: `ai:ingest --fresh` still empties EVERY Qdrant collection (uploaded-file chunks, stored examples, the
  indexes) although ingest no longer rebuilds any of them — behaviour left as is, now documented.
- Totals now: package −6,079 lines of code / config / tests, host −3,147, 44 graph labels / 205,398 nodes,
  82 Qdrant collections / 334,150 points.

## 2. Document map + reasoning — measured experiment (scratch only; `scratchpad/docmap/`)
Design tested: a map built at indexing (one plain-language gist per article, a summary per section); ONE
map-reading call per document question (issue, needed rules by role, what the answer depends on; ids verified by
code) replacing probe writer + passage selector; an analysis block for the composer.

**What the numbers say**
- The gap is REASONING, not text: with the WHOLE chapter in the prompt, 0 of 5 tear-down answers state the
  own-portion rule or the three-quarters majority.
- The analysis block (variant B) fixes tear-down: the three-part rule (own portion, art. 1063 → walls presumed
  common, art. 1045 → three-quarters for works on common portions, art. 1097) in **4 of 5** runs (today 0 of 5);
  no flat "you cannot". On 8 NEW "see further" questions written beforehand: better on 4, equal on 3, slightly
  worse on 1.
- **But it REGRESSES the buyer answer in 5 of 5** — ungrounded claims ("the declaration may specify different rules
  for previous debts"): the reader names neighbouring articles as "condition" / "procedure" and the block makes the
  composer use them. Softer wording does not help; a literal check of the picks vetoes exactly the inference picks.
- My skepticism on "finding by summaries" was HALF wrong: gist embeddings move art. 1063 from rank 26 to 4 — but
  push the contingency-fund articles from 36–84 to 71–109, so they can only be an extra vote, never a replacement.
- The map-reader as RETRIEVAL (its picks ∪ plain search, instead of probes + selector): needed-rule recall 55 of 56
  on the acceptance set (today 47) and 29 of 40 on the new questions (today 24), similar latency. Probes + selector
  together add 2 of 56; the probes LOWER recall on the rights question (16 vs 20 of 20).
- A map with section summaries does NOT fix wrong-chapter picks (it makes them worse; the small model cannot tell
  which regime a condo is in). What does: ONE call per document at indexing, on the stronger model, deciding which
  top-level parts apply to this application ("Chapter II — undivided co-ownership: no", 3 of 3) — a document SCOPE.
- Gists are lossy unless generated one chunk per call: batched 8 per call, 53% of chunks lost a condition and 14
  gists described the wrong chunk; one per call: 9.7% lose something (a floor), ≈ $0.17 and 39 min per 1,000 chunks.

## 3. Phase 1b — the GATED design (scratch only; 127 answers read claim by claim by blind reviewers)
Gate g5: the analysis block is used only when the map reader names a general-rule, classification or approval
rule that plain search did NOT find (an inference question). Closed: passages and prompt exactly as plain search.
Open: reader picks first by role + a block listing the governing rules only (no "depends on", no reader-proposed
conditions — those produced the buyer inventions and the misapplied art. 1100 / 1066).

| | Today | Gated candidate |
|---|---|---|
| Gate-OPEN turns (27) — quality | 0.45 | **0.62** |
| — runs with an ungrounded claim (claims) | 21 (40) | **12 (18)** |
| — needed rules stated | 29 / 66 | **47 / 66** |
| — answers that reason from the rules | 10 | **22** |
| — flat verdicts | 8 | 2 |
| Gate-CLOSED turns (26) — quality | 0.68 | 0.69 |
| — runs with an ungrounded claim (claims) | 11 (23) | 11 (20) |
| Median knowledge turn | 18.2 s, 17.5k tokens, 5 calls | **15.9 s**, 20.4k tokens (+16%), 4 calls |

Tear-down 0.28 → **0.74** (three-part rule 4 of 5, 0 ungrounded claims; today 14). The gate stays closed on buyer
5/5, non-payment, rights, French, how-to and all 4 direct questions; opens on tear-down 5/5 and on 11 of the 12 new
inference questions. Per question: better 11, equal 8, worse 4 (rights t1 follow-up, syndicate access, special
assessment — off-target picks lead the answer —, fund-study interval — closed turn, variance).
Kill criterion "buyer 5/5 clean" NOT met as written: 2 of 5 closed runs garble the 15-day rule (today 1 of 5 with an
invented exception) — closed mode is today's composer on plain-search passages, so this is not the block's doing;
no arm states the 15-day protection correctly.
Not to build: the reader as retrieval WITHOUT the block (worse on rights t0 / t1). Large-library mode (map cut to
≈ 40 chunks around the plain-search hits): fine when the governing section is near the hits, loses the needed rule
before the reader sees it on 4 of 16 inference questions — unproven for cross-section inference.

**Decision: build it** — document scope + gists in the chunk payload (no table, no migration), the map reader
replacing probe writer + passage selector, the gated analysis block behind a flag; files without a map keep
today's path. Then a full graded run.

**Verdict of phase 1 (superseded by 3):** do not build the analysis block as specified; build the document scope; phase 1b tests a
structural gate — the analysis only when the reader names a governing rule that plain search did not find (an
inference question), today's composition untouched otherwise — with every answer read, zero new ungrounded claims
on direct questions as the kill criterion, and measured end-to-end cost.
