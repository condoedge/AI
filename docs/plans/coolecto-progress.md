# Coolecto integration: progress log

Design: [2026-10-22-coolecto-integration-plan.md](2026-10-22-coolecto-integration-plan.md). One entry per step: what changed (file:line), the guard
result, the tests run.

## Owner decisions

| Decision | Answer (2026-10-22) |
|---|---|
| Assistant name in Coolecto | **Coco** |
| SISC (also loads the package through a symlink, no `ai.domain`) | out of scope: neutral defaults reach it, nothing is done for it |
| Admin gate for the review page | `isAdmin()` |
| cms wiki (38 articles) | replace (ingest only the new KB) |
| Domain-flavoured package tests (4,319 rows) | after Coolecto v1 |
| CondoEdge composer.json drift (A1) | leave composer.json as is (see A1) |

## Environment facts

- `CondoEdge/vendor/condoedge/ai`, `SISC/vendor/condoedge/ai` and `ai-eval/vendor/condoedge/ai` are symlinks to `kompo/ai`: package edits are live
  there instantly.
- `ActiviteDeFinancement/vendor/condoedge/ai` is a **copy** (dated 2026-10-21). It must be re-synced (or turned into a path link) before Phase D.
- `CondoEdge/vendor/condoedge/ai-eval` is a symlink to `kompo/ai-eval`. `kompo/ai-eval` is not a git repository.
- The eval package is named `condoedge/ai-eval` (namespace `Condoedge\AiEval`), not `kompo/ai-eval`.
- ai-eval baseline: 103 tests green (2026-10-22).

## Phase A: regression guard

### A1. Composer drift: no change (owner decision)
- `CondoEdge/composer.json` lost the ai-eval require-dev line and the `../ai-eval` path repository in Ezequiel's commits b5766055 and 8af7b721
  ("Fix composer ai repo", 2026-10-21). That removal was deliberate.
- `CondoEdge/composer.lock` is gitignored (`.gitignore`) and still lists `condoedge/ai-eval` (dev, path `../ai-eval`), and
  `vendor/condoedge/ai-eval` is a symlink, so `php artisan ai:eval` works today.
- **Risk:** a `composer update` in CondoEdge drops `ai:eval`. Re-adding it locally takes the two removed blocks back; don't commit them.
- No composer command was run.

### A2. Cassette record/replay (ai-eval, no package change)
- New `ai-eval/src/Cassette/`:
  - `Cassette.php`: the tape (LLM calls per turn in order, embeddings by sha1(model + text), store calls per turn), date-time masking
    (`\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}`), first-hunk diff, typed-exception replay (class + scalar fields).
  - `CassetteLlmProvider.php`, `CassetteLlmResolver.php`, `CassetteEmbeddingProvider.php`, `CassetteVectorStore.php`, `CassetteGraphStore.php`:
    decorators of the package's container bindings.
  - `CassetteBindings.php`: `extend()`s `LlmProviderInterface`, `LlmResolver`, `EmbeddingProviderInterface` (kept inside a
    `MemoizedEmbeddingProvider`, because `AiManager::embedAnglesInOneBatch` checks for it), `VectorStoreInterface`, `GraphStoreInterface`;
    raises `ai.rate_limits.*` to 100000 in both modes.
  - `CassetteStageObserver.php`: the eval recorder, which also tells the cassette each stage's prompt, so a mismatch names its stage.
- Wiring: `AiEvalServiceProvider::register()` (env `AI_EVAL_CASSETTE=record|replay`, `AI_EVAL_CASSETTE_PATH`);
  `ConversationEvalRunner::run()` marks turns `<conversation id>#<turn index>`; `PipelineEvalDriver` uses the stage observer;
  `EvalCommand::finishCassette()` prints each turn's first mismatch (stage + diff) and the data drift, and exits 1 on any mismatch.
- Semantics: an LLM request that differs is a MISMATCH (replay continues on the recorded reply); an extra call or a missing embedding is a MISMATCH and
  throws; a store call with other arguments is a MISMATCH; the same store call with another result is DATA DRIFT (reported, not failing).
- **Limit:** requests are compared at the `LlmProviderInterface` boundary. Request shaping inside `AbstractHttpLlmProvider` (JSON system line,
  schema options) is outside the guard; Phase B doesn't touch it.
- Tests: `ai-eval/tests/Unit/Cassette/CassetteTest.php` (9), `CassetteWiringTest.php` (2). ai-eval suite: 114 tests green.
- Commands:
  ```
  cd CondoEdge
  AI_EVAL_CASSETTE=record AI_EVAL_CASSETTE_PATH=storage/eval/gold.cassette.json CACHE_DRIVER=array php -d memory_limit=2G artisan ai:eval --suite=tests/eval/gold-conversations.json --user=13 --fresh --throttle=20000 --report=storage/eval/gold-record.json < /dev/null
  AI_EVAL_CASSETTE=replay AI_EVAL_CASSETTE_PATH=storage/eval/gold.cassette.json CACHE_DRIVER=array php -d memory_limit=2G artisan ai:eval --suite=tests/eval/gold-conversations.json --user=13 --fresh --skip-preflight --report=storage/eval/gold-replay.json < /dev/null
  ```
  `storage/eval/` is gitignored in CondoEdge (`.gitignore`: `/storage/eval`).

### A3. Prompt snapshots (package)
- `ai/tests/Unit/Snapshots/PromptSnapshotTest.php`: understanding (first turn + follow-up), clarity_check, query_rules, composition,
  conversational, under `tests/Fixtures/prompt-snapshots/host-config.php`. Snapshots in `tests/Fixtures/prompt-snapshots/*.txt`, captured on
  unchanged `src/` (2026-10-22). Refresh only deliberately: `UPDATE_PROMPT_SNAPSHOTS=1`.
- Compared in LF: PHP heredoc prompts carry the checkout's line endings (the work tree is CRLF via core.autocrlf, so CondoEdge's live QU
  prompt contains `\r\n`). The cassette still compares exact bytes.
- When a slot moves out of the package, the text CondoEdge supplies for it goes into `host-config.php`.

### A2 result: record + guard proven (2026-10-22)
- **Record** (live, today's code, user 13, `--fresh --throttle=20000`): 39 turns, 105 LLM calls, 91 embeddings, 422 store calls, 0 recorded errors.
  1 gate failure (`context-degradation-study` turn 8: `columns_include` email) and 1 `context-not-retrieved` (turn 9): answer quality from the
  live model, not guard issues. Cassette: `CondoEdge/storage/eval/gold.cassette.json` (5.1 MB, gitignored).
- **First replay** showed 10 store mismatches in `rights-duties-progressive#0`, 0 LLM mismatches. Cause: `AiManager::anyDocumentMapStored()`
  (AiManager.php:1655) caches its `collectionExists`/`count` probe for 60 s; the throttled recording outlived it, the fast replay didn't. Fix
  (ai-eval): store calls now match by call + arguments within the turn, not by position (a call with new arguments is still a mismatch; a probe a
  cache answered is ignored). Test `store_calls_match_by_call_and_arguments_not_position`.
- A replay now exits on the cassette verdict alone: replayed answers are the recorded ones, so the fact-gates repeat the recording's verdicts.
  Test `a_replay_exits_on_the_cassette_alone_since_its_answers_are_the_recorded_ones`.
- **Two replays of unchanged code: 0 mismatches, 0 data drift each** (~40 s per replay).
- **Deliberate change** (`QueryUnderstandingService.php` "…not Neo4j property names." → "…names!"): 39 mismatches, each first one
  `[<turn>#0 call 0] understanding (changed)` with the one-line diff. Reverted; `git status` showed `src/` clean again.
- ai-eval suite: 118 tests green.

### Tooling for comment-only steps
- `ai-eval/bin/same-tokens.php <repo> [file...]`: every changed PHP file keeps its code tokens (comments and inter-token whitespace dropped,
  compared in LF) identical to git HEAD. Core: `ai-eval/src/Tools/TokenStream.php`, tests `tests/Unit/Tools/TokenStreamTest.php`.

## Phase B: de-condo the package

Replay helper used for every step (prints the cassette verdict):
`AI_EVAL_CASSETTE=replay AI_EVAL_CASSETTE_PATH=storage/eval/gold.cassette.json CACHE_DRIVER=array php -d memory_limit=2G artisan ai:eval --suite=tests/eval/gold-conversations.json --user=13 --fresh --skip-preflight --report=storage/eval/gold-replay-<step>.json < /dev/null`
(from `CondoEdge/`).

### B1. Real-name and product comments (20 rows, 18 files)
- Rewritten: config/ai.php:920 (line removed), IngestEntitiesCommand.php:371, FileContextEnricherInterface.php:12-13,
  ActionAttacher.php:584-585, IdentityLinkAction.php:17, AiManager.php:680, QueryRulesSection.php:97, QueryExecutor.php:710,
  DataAnswerPlan.php:338,342,673, EntityIdIndex.php:32, ExportResponseComposer.php:357, HostText.php:128, NarrativeRowEcho.php:243,
  ResponseFileEnricher.php:113, ResponseCurrentUserContextSection.php:85, PropertyTypeSampler.php:15, SecurityFilterInjector.php:69,
  GreenTheme.php:12-13. Real names became fictional ones (Jane Doe, Acme Gestion, Northwind); "CondoEdge" became "a host".
- Guard: `php ai-eval/bin/same-tokens.php ai` → 18 files, 0 with code changes. `grep -E "CondoEdge|Viva Tour|825 de Bruxelles|Amélie Quirion|Novo Gestion"`
  over src/ and config/ → 0. Replay: 0 mismatches. Snapshots: green.
- Line endings: the work tree was already mixed before this step (209 LF / 175 CRLF files in src/); the edited files kept theirs (the replay
  would show a heredoc that changed).

### B2(a). QU examples → `ai.domain.understanding`
- New `src/Support/DomainProfile.php` (`text($slot)`), the only reader of `ai.domain`. New `domain` block in `config/ai.php` (after `project`).
- Six slots, not five: the two clarified-question examples and the knowledge example are each a `question` + `clarified` pair, so the instruction
  frame around them stays in the package: `implicit_entity_example`, `property_concept_examples`, `possessive_examples`,
  `clarified_example.{question,clarified}`, `no_owner_example.{question,clarified}`, `knowledge_example.{question,clarified}`.
- `QueryUnderstandingService::buildMessages()` reads them before the heredoc. The knowledge line is a small heredoc that starts with a blank line,
  so its line break is the file's own (CRLF in this work tree), exactly like the line it replaced; an empty pair drops the line.
- Move 1 (package default = today's text): replay **0**. Move 2 (same text in `CondoEdge/config/ai.php` `domain.understanding`, after
  `project`; and in `tests/Fixtures/prompt-snapshots/host-config.php`): replay **0**. Move 3 (package default → neutral orders/returns
  examples): replay **0**.
- Tests: `tests/Unit/Support/DomainProfileTest.php` (4, incl. "package defaults name no host domain"),
  `tests/Unit/Services/QueryUnderstandingDomainSlotsTest.php` (2). Filter run `QueryUnderstanding|Clarif|DomainProfile|Snapshot`: 186 green.

### B2(b). Query-rule examples → `ai.domain.query_rules`
- Four slots (QueryRulesSection.php:134 one, :148 two fragments, :156 one): `join_example`, `record_examples`, `record_id_example`,
  `row_grain_examples`, read through `DomainProfile::text()` in `formatBusinessRules()` / `formatFieldProjection()`.
- Move 1: replay **0**. Move 2 (CondoEdge `domain.query_rules` + snapshot host config): replay **0**. Move 3 (neutral: Customer/Order, `o.id AS order_id`):
  replay **0**.
- `QueryRulesSectionTest` now extends the package TestCase (the section reads config) and sets CondoEdge's `record_id_example` for its
  `u.id AS unit_id` assertion. New `QueryRulesSectionDomainSlotsTest` (1).

### B2(c). `own_profile_ambiguous_entity_labels` → `ai.domain.ambiguous_profile_labels`
- `DomainProfile::list()` added. `QueryUnderstandingService::ownProfileAmbiguousEntityLabels()` reads only the domain slot; the old
  `ai.query_understanding.own_profile_ambiguous_entity_labels` key and its constructor-config read are gone (no host set it: grep of
  CondoEdge/SISC/Coolecto config = 0).
- The gold suite exercises it: `rights-duties-progressive#1` (possessive lookup with Contact among the entity types).
- Move 1: replay **0**. Move 2 (CondoEdge `['Contact']`): replay **0**. Move 3 (default `[]`): replay **0**.
- Tests: `DomainProfileTest::a_list_slot_reads_strings_only`, `QueryUnderstandingEntityTypeValidationTest::with_no_ambiguous_labels_configured_…`;
  the two tests that relied on the old `Contact` default now set CondoEdge's value. Filter run: 163 green.

### B2(d). Alias dictionary → `ai.domain.aliases`
- Dev-time only (`ai:discover`), so the guard is a `generate()` snapshot: new `tests/Unit/Snapshots/AliasSnapshotTest.php` (11 fixture models
  named like CondoEdge entities; `tests/Fixtures/prompt-snapshots/aliases.json`), captured with `AliasGenerator.php` at HEAD.
- `AliasGenerator::businessTerms()` reads `array_merge(BUSINESS_TERMS, DomainProfile::map('aliases'))`: the 17 generic terms stay in the class;
  a host entry for a word replaces the generic one. `DomainProfile::map()` added.
- The 34 condo entries (old AliasGenerator.php:58-93) moved verbatim by script. Move 1 (package default = them): snapshot unchanged, replay **0**.
  Move 2 (CondoEdge `domain.aliases` + fixture, same 34 entries): replay **0**. Move 3 (package default `[]`): snapshot unchanged under the
  fixture, replay **0**.
- Tests: `tests/Unit/Services/Discovery/AliasGeneratorDomainAliasesTest.php` (2), `DomainProfileTest::a_map_slot_…`.

### B2(e). `ai:discover` / `ai:enhance` brief → `ai.domain.discovery`
- Dev-time only. Guard: new `discovery` snapshot in `PromptSnapshotTest` (enhance + generateTestQuestions + improveFromFeedback messages),
  captured with `AiConfigEnhancer.php` at HEAD (10 condo fragments).
- Eight slots: `app_brief` (AiConfigEnhancer.php:124), `persona_examples` (:181), `possessive_path_example` (:211),
  `possessive_alias_examples` (:212), `question_examples` (:213), `relationship_examples` (:216), `junction_example` (:217),
  `possessive_chain_example` (:226). `systemPrompt()` went from nowdoc to heredoc (checked first: no `$`, no backslash in it). Empty `app_brief` →
  "an AI assistant for this application: <project.description>" (`appBrief()`).
- Move 1: snapshot unchanged, replay **0**. Move 2 (CondoEdge `domain.discovery` + fixture, copied by script): snapshot unchanged, replay **0**.
  Move 3 (neutral orders/customers examples, `app_brief` ''): snapshot unchanged under the fixture, replay **0**.
- CondoEdge's live `config('ai.domain.*')` checked equal to the fixture for all five blocks (tinker).
- Tests: `tests/Unit/Services/Discovery/AiConfigEnhancerDomainSlotsTest.php` (2).

### B2(f). Role words → `ai.domain.role_terms`
- SISC is out of scope (owner), and CondoEdge's only role-like scope is `members` (`config/ai-entities/general.php:765`). So there was no host
  text to move: the three lists (CypherScopeAdapter.php:507 with 8 words, :542 with 6, TraversalScopeGenerator.php:218/242 with 9) became one
  `TraversalScopeGenerator::roleNames()` = generic `admins, members, managers, staff, customers` + `ai.domain.role_terms` (default `[]`).
- Intended difference: `staff`/`customers` are now role words at the old :542 site too, and `parents` stays a role word only via host config.
  For CondoEdge discover output this changes nothing (`members` is in every list). SISC would need `role_terms: [volunteers, scouts, leaders, parents]`.
- Replay **0**. Tests: `tests/Unit/Services/Discovery/RoleTermsTest.php` (2); filter `RoleTerms|Discovery|Scope|DomainProfile|Snapshot|RealBusiness`:
  531 green, 2 skipped.

### B3. Legal-corpus logic kept; year false-positive fixed
- `ArticleLocator::referencedArticles()` (ArticleLocator.php:126): a bare whole 4-digit clause number now counts only at the start of a line
  (`^[ \t]*NNNN.` with `/m`); a sub-article (`NNNN.N.`) still counts anywhere. "amended in 2024." is no longer article 2024. No word list.
- Test: `ArticleLocatorReferenceTest::a_year_at_the_end_of_a_sentence_is_not_an_article` (failed first). Filter
  `ArticleLocator|FollowUp|ResponseFileEnricher|Deepening|AnswerValidator|Article`: 327 green. Replay **0**.

### B4. Remaining domain comments, docs
- Work list from a fresh `decondo/scan.py` run (line numbers had moved): 382 comment rows in 89 files of `src/` + `config/ai.php`, plus 63 rows in
  `README.md` / `resources/docs`. Split into three balanced groups, rewritten by three background agents (comments only, Edit tool, neutral
  examples: orders, customers, projects, members, "a host"; legal examples as generic numbered provisions such as "art. 12" or 2xx numbers).
  Agent vocabularies differ slightly (one used account/branch for unit/union); each comment is self-consistent.
- Guard:
  - `same-tokens.php`: 88 changed PHP files, every one `same` except the 8 with this session's intended code changes (config/ai.php,
    AiConfigEnhancer, AliasGenerator, CypherScopeAdapter, TraversalScopeGenerator, QueryRulesSection, QueryUnderstandingService, ArticleLocator).
    For those 8 a token-level LCS diff against HEAD shows only the slot / role-term / ArticleLocator changes: no agent touched code.
  - Snapshots unchanged; replay **0**.
- Kept on purpose:
  - `co_owners` / `$coOwners` (FileSearchService, FileContextProvider, AiManager): the other records a document is filed on, a generic concept;
    it is also persisted for the next turn's file context, so renaming would be a runtime change for nothing.
  - multi-tenancy "tenant", Cypher `UNION`, "unit test", text "units" in SemanticChunker, "building" as a verb.
- Fixed by hand after the agents: FanOutCollapse.php:307-308 and CypherStructuralValidator.php:17 (`:Union` keyword-collision examples →
  `:CreditUnion` / `union_count`, `(x:Match)`), RecordResolver.php:849, OpenModalAction.php:18, ActionLinkHandler.php:24 (`unit_id` examples).
- `docs/` (eval 2,004 / audit 284 / plans 283 / onboarding 168 hits) stays in the repository, excluded from installs: new `.gitattributes`
  `/docs export-ignore`.
- Tests: per the owner, the 4,319 domain-flavoured test rows wait until after Coolecto v1.

### B5. Lint: `tests/Unit/NoDomainContentTest.php`
- PHP port of `scan.py`'s term groups (condo, product incl. SISC, dwelling, host labels, governance, legal corpus) over what the package ships:
  `src/`, `config/`, `resources/`, `routes/`, `database/`, `README.md`, `composer.json`. `docs/` and `tests/` are out of scope (see above).
- Sharper than the scanner, so it needs almost no allow-list: no bare "unit" (unit tests, text units) — `unit_id`/`unit_name`/"unit owner"
  instead; "building" only as a noun phrase; legal corpus = Québec / CCQ / Civil Code / `art. 103x–110x` / "declaration of co-ownership" (the
  `art.` grammar itself is generic and unchecked). NOISE: quality assessment, Cypher UNION, `co_owners`.
- First run (mid-B4): 328 hits. After B4 + sharpening: **0**.

### Phase B gate (2026-10-22)
- Full package suite: **4,978 tests, 47 skipped, green** (`php -d memory_limit=2G vendor/bin/phpunit`, 20 min; last green before: 4,953 / 47).
- Lint `NoDomainContentTest`: 0 hits. Snapshots: unchanged. Replay: 0 mismatches after every step.

## Phase D (started early, host-side only)

### D. KB copied into Coolecto
- Script (scratchpad, not in any repo): `kb_copy.py <source aide> <ActiviteDeFinancement/resources/ai-help/aide>`. Source untouched. Quotes a
  frontmatter value containing `: ` (16 values: titres like "Pair à pair : …", chemins like "Avant le paiement : …"), maps `pages: [welcome]`
  → `onboarding.guest` (1 file: organisateur/compte-creer-un-compte-organisme.md), skips `INDEX.md` (a table of contents: ingested, it would
  match every topic for every audience). Everything else byte-for-byte.
- Result: 458 articles in `resources/ai-help/aide/{acheteur,fournisseur,organisateur,participant}/`.
- Validation (`kb_validate.php`, Coolecto's own symfony/yaml + `php artisan route:list --json`, 522 routes): **0 YAML errors, 0 missing keys,
  0 unknown routes, 0 `public` ≠ folder.**

## Phase C: package features for Coolecto

### C1. Frontmatter (P0)
- New `src/Support/Frontmatter.php`: `split($text)` → [fields, body] (Symfony YAML when the class exists and the block parses; else a line reader
  that takes `key: value` / `key: [a, b]`, so an unquoted ": " in a value still reads); `metadata($fields, $fileName)` → payload fields under
  the package's English names `title, audience[], pages[], breadcrumb, keywords[], related[], slug` (slug defaults to the file name).
- **Deviation from the brief, on purpose:** the payload key is `breadcrumb`, not `chemin`, and `keywords`/`related`, not `mots_cles`/`voir_aussi`:
  the package stays language-neutral. The host maps its own names: `ai.file_context.frontmatter.keys` (Coolecto: title→titre, audience→public,
  breadcrumb→chemin, keywords→mots_cles, related→voir_aussi). `embed_keywords` (default false) is the keywords flag.
- `FileProcessor::processFile()`: for md/markdown/mdown/mdx, the block is removed before chunking (stored text, offsets and embedded text exclude
  it); `createFileChunks()` takes the frontmatter title over the first heading and stamps the other fields on every chunk; `embedPrefix()` puts the
  breadcrumb (and, with the flag, the keywords) on the first line(s) of every embedded text, before the chunker's heading prefix.
- `symfony/yaml: ^6.0|^7.0` declared in the package composer.json (installed in ai/, ai-eval/, Coolecto; **not** in CondoEdge's vendor until its
  next composer update — the line reader covers that, and CondoEdge's docs have no frontmatter).
- Guard: the 7 docs under CondoEdge's `physical_paths` (public/laws, docs/ai-help): 0 start with `---`, so their chunks cannot change.
  `FileProcessorFrontmatterTest::a_document_without_frontmatter_is_processed_exactly_as_before` passed before and after the change. Replay **0**.
- Tests: `tests/Unit/Support/FrontmatterTest.php` (5), `tests/Unit/Services/FileProcessorFrontmatterTest.php` (3).

### C2. Audiences (P0)
- New: `src/Contracts/AudienceResolverInterface.php` (`resolve($user): ?ResolvedAudience`), `src/DTOs/ResolvedAudience.php` (key, role),
  `src/Support/Audiences.php` — the only reader of the new `ai.audiences` block (`resolver` class-string, `map[key] = {documents[], insider_voice,
  prompt ({role}), example_questions[]}`; package default: resolver null, map []).
  - `documents($user)`: null = no filter (unconfigured); [] = only documents with no audience (unresolved user, unknown key, failing resolver:
    fail closed).
- **Filter (code, not model):** `QdrantChunkStore::audienceClause()` — `should: [metadata.audience ∈ audiences, is_empty(metadata.audience)]` —
  in `buildQdrantFilter()` (content) and `buildFilenameFilter()`; the scroll fallback of filename search applies the same rule in PHP
  (`audienceAllows()`). `FileSearchService` forwards the `audiences` option to every collection (uploads carry no audience, so they pass).
  `FileContextProvider::audienceOptions($user)` adds it to its three searches (filename, content, `rankedChunks`). Every other question-time read
  of the docs collection derives from those results: the ±window (same file), `passageAt` (a `rankedChunks` result), the document map turn (the
  turn's files + `rankedChunks`). File listing (`enumerateAccessibleFiles`) lists uploads only.
- **Role text:** `Audiences::promptBlock()` ("THE USER'S ROLE (how to frame the answer for them):\n…") in `ResponseCurrentUserContextSection`
  (after the identity facts), `ConversationalReplyComposer::userPrompt()` (after THE USER), `DigestResponseComposer` (end of the system prompt).
  `insider_voice=false` drops the section's INSIDER VOICE lines. The digest keeps its own "insider voice" wording (a staff data feature; Coolecto v1
  has query generation off). **QU untouched.**
- **Welcome suggestions:** `WelcomeSuggestions::resolve()` — page examples, then the audience's `example_questions`, then the global list.
- Replay **0** (after the filter, and after the prompts). Tests: `AudiencesTest` (4), `QdrantChunkStoreAudienceFilterTest` (5),
  `FileSearchServiceAudienceTest` (2), `FileContextProviderAudienceTest` (2), `AudienceRoleTextTest` (4), `WelcomeSuggestionsAudienceTest` (3);
  fixture `tests/Fixtures/FixedAudienceResolver.php`. Related filters (FileContext|FileSearch|QdrantChunkStore|DocumentMap 494;
  Digest|Conversational|CurrentUserContext|Snapshot 199; Suggestion|Welcome 24): green.

### C3. Incremental ingest (P0)
- `FileProcessor::processFile()` option `skip_unchanged` (only the docs ingest passes it; uploads, jobs and `ai:process-files` keep the old
  "already processed" refusal): the sha1 of the file's bytes is stamped as `metadata.source_hash`; a stored file with the same hash — or with
  none (chunks stored before hashes: no re-embedding on upgrade; `--fresh` stamps them once) — returns `success(0 chunks, skipped)`; a changed
  one is replaced (the existing force path, which carries the document map of unchanged chunks).
- `ai:ingest --docs` passes it, so a re-run exits 0 ("Skipped: N") and `ai:setup` (which stops on a failed step) is re-runnable.
  New `--prune`: deletes the stored `physical:` files no longer among the configured paths (never another id; never reached when the paths match
  nothing). Uses `storedFileIds()` when the chunk store has it.
- Fix found on the way: `Frontmatter::metadata()` guards `config()` like `FileProcessor::configValue()` (FileProcessingPipelineTest runs without a
  container; it caught it — its assertion now prints the processing error).
- Replay **0**. Tests: `FileProcessorIncrementalTest` (4), `IngestDocsIncrementalTest` (3); file-processing filters (65) and the ingest command
  tests (13) green.

### C4. Docs-only mode (P0)
- `ai.query_generation.enabled` (env `AI_QUERY_GENERATION_ENABLED`, default true). Read by `AiManager::queryGenerationEnabled()`.
- Off: `shouldSkipFileSearchFor()` never skips (a data-looking question still searches the documents); `generateAndExecute()` (after the
  file-listing branch) answers from the found documents whatever the question type (`source=file_context`, stage `knowledge_query`), or, with
  none, returns `generation_skipped=docs_only` (stage `docs_only`) — the QueryGenerator and the executor are never called.
- The abstention: `AiManager` passes `docs_only => true` to `generateEmptyResponse()`, which then says "None of the documents answers this
  question…" instead of "No matching records were found" and skips the WHAT WAS CHECKED / checked line (nothing was checked).
- `ResponseGuardrailsSection` rule 3, only when off: "You answer from the documents only and cannot see the user's own records… say where in
  the application they can see them, following the documents' steps and labels; never state a figure." (A data question with an article attached
  must not get an invented figure; the eval decides whether the line earns its place.)
- Not done here: ContextRetriever still loads the graph schema (try/catch) — verify in Phase D whether Coolecto needs Neo4j at all (plan W6).
- Replay **0**. Tests: `AiManagerDocsOnlyTest` (2; an error answer re-throws its exception), `ResponseGeneratorDocsOnlyEmptyTest` (2),
  `ResponseGuardrailsSectionTest::docs_only_mode_adds_the_no_records_rule`. Filter `AiManager|ResponseGenerator|Guardrails|Snapshot|FileSearchSkip`:
  531 green.

### Owner question (2026-10-22): translate the KB to English? Keep 458 files in the repo?
- Recommendation given: no translation up front. Retrieval is cross-lingual (embeddings + `corpus_languages=['fr']` probes) and the composer answers
  in the user's language. The real English risk is the quoted UI labels (« … ») — an LLM translation would invent labels; the true English labels
  are in `resources/lang/en`. Measure with the 6 English eval turns (Phase E); if they degrade, give the composer the label map from the lang files.
- The 458 files in `resources/ai-help/aide/` (~1.4 MB) are the simplest store: versioned with the UI they describe, reviewed in PRs, re-embedded
  incrementally (C3). The repo copy becomes the source of truth.

### C5. Trace stamps (P1)
- `AskQuestionUseCase::runTurn()`: when `ai.audiences` is configured, an `audience` stage (`{audience, role}`) is emitted just before the
  `pipeline` stage — so it reaches the persisted trace (MessageTraceObserver) and the eval recorder alike — and the assistant message gets
  `metadata.audience = {key, role}` (`{null, null}` for an unresolved user). Nothing without `ai.audiences`.
- Note: `Audiences::of()` is called a few times per turn (stamp, the three searches, the prompts); Coolecto's resolver reads user columns only.
  Memoize if a host's resolver is expensive.
- Replay **0**. Tests: `AskQuestionUseCaseAudienceStampTest` (3); `tests/Unit/Services/Chat` 127 green.

### C6. Navigation buttons from setup (P1)
- **Deviation from the plan, on purpose:** the catalogue is its own block, `ai.navigation = {max: 2, pages: {key: {route, label, description,
  audiences?, authorize}}}`, not `generic_actions` entries. Entries of `generic_actions` are advertised to the model and dispatched as
  `action://` closures; a navigation page has no closure and must never be invoked by the model. The pages still render through the registry's
  built-in `link` action (by route name).
- New `src/Services/Actions/Attach/NavigationPages.php`:
  - `offeredTo($user)`: the pages in the user's audience (none listed = all), with a **required** `authorize` closure that passes, on a route that
    exists and has no required `{param}` (so `switch.campaign/{id}` never qualifies);
  - `pick()`: one small call (llm role `navigation`, stage `navigation`), strict schema `{pages: [enum of the offered keys]}`; code keeps known,
    unique keys up to `max`.
- `ActionAttacher::withNavigation()` (same gates as the follow-ups: documents retrieved, a real answer, no export, no navigation ask, the turn's own
  chips; never throws): each pick becomes a `next_step` chip `link {route, style: button}` labelled with the page's label. Called by `AiManager`
  after `withAnswerFollowUps()`. Articles are never read for buttons (`pages[]` frontmatter is retrieval metadata only, C7).
- CondoEdge has no `ai.navigation.pages`: no call, no change. Replay **0**.
- Tests: `NavigationPagesTest` (3), `NavigationChipsTest` (3); filter `Attach|Navigation|AiManager|ServiceProvider|Snapshot|NoDomain`: 625 green.

### C7. Page awareness (P1)
- `AiChatFloating::created()`: with `ai.chat.capture_page_route` on (opt-in, default false — on, CondoEdge's live pages that send no page
  context would start rendering the page sections), the route the button is rendered on becomes `page_context.route`, kept in the Kompo store so
  the `openChat` request carries it (Kompo saves `store` in the boot info; `prop()` reads parameter, else store). A host's page_context wins; a
  Kompo request (`X-Kompo-Info` header) is never taken for the page.
- `page_examples` by route: `WelcomeSuggestions` looks up `PageContext::descriptionKey()` (entity type, else route) and indexes the array instead
  of a dot path (a route name holds dots — `config('ai.chat.page_examples.campaign.page.products')` would have walked nested keys).
  `page_descriptions` already indexed correctly.
- **Not done (optional in the plan):** the soft retrieval boost from chunk `pages`. Deferred until the Coolecto eval shows a retrieval miss it
  would fix ("anything added must earn its place").
- Replay **0**. Tests: `AiChatFloatingPageRouteTest` (4), `WelcomeSuggestionsAudienceTest::a_page_without_an_entity_is_matched_by_its_route`;
  `tests/Unit/Kompo` 128 green.

### Phase C gate (2026-10-22)
- Full package suite: **5,032 tests, 47 skipped, green** (4:54).

### D-prep. Qdrant collection prefix (package, owner decision)
- The local Qdrant is shared: CondoEdge owns `file_chunks, questions, scope_examples, semantic_entities, documentation_chunks, context_index`, and
  `questions` / `semantic_entities` are fixed names in the package. New `ai.vector.qdrant.collection_prefix` (env `QDRANT_COLLECTION_PREFIX`,
  default ''): `QdrantStore::request()` puts every `/collections/{name}` under the prefix (one place; the curl call moved to `send()`),
  `listCollections()` shows only this host's collections by their own names, `collectionExists()` compares the prefixed name (it compared raw
  names — with a prefix it would always have answered false).
- Replay **0**. Tests: `QdrantStoreCollectionPrefixTest` (4); `Qdrant|VectorStore` 99 green.

### D1. Package installed in Coolecto (local)
- `vendor/condoedge/ai` (a stale v0.0.9 copy) renamed to `vendor/condoedge/ai.v009-bak` and replaced by a junction to `kompo/ai`
  (`mklink /J`), like CondoEdge and SISC (owner decision). No composer.json / lock change.
- **Incident, fixed:** `php artisan package:discover` registered `Condoedge\Ai\AiServiceProvider` and LaRecipe's provider, but Coolecto's
  `vendor/composer/autoload_*.php` dated from 2026-08-27: the 2026-10-21 install of condoedge/ai + binarytorch/larecipe never regenerated the
  autoloader (`Condoedge\Ai\` was absent from autoload_psr4). Artisan (and the local site) failed with "Class LaRecipeServiceProvider not found"
  / "Target class [auth] does not exist" until `composer dump-autoload` (regenerates the autoload files from installed.json; no download, no
  version change). Coolecto now boots; 13 `ai:*` commands registered.
- Note: Coolecto's git HEAD moved during the session (owner's concurrent work); nothing here touched git.

### D2. Migrations (local `financement`, owner approved)
- The 7 package `ai_*` migrations run by `--path` (conversations, query logs, context snapshot, user settings + columns, file access logs, drop
  extracted_entities).
- **Left pending on purpose:** the package's `add_processed_at_to_files_table` adds an indexed `processed_at` column to Coolecto's own `files`
  table (the package's upload-processing marker; Coolecto v1 processes no uploads). The package auto-loads its migrations, so **a plain
  `php artisan migrate` (e.g. a deploy) will run it.** Owner decision needed.

### D3. Coolecto host code and config (working tree, no commit)
- `app/Ai/AudienceResolver.php`: the sidebar precedence (DashboardSidebar::render): scanner → organisateur(scanner); no current team → none;
  participant mode → participant; customer mode → acheteur; supplier mode → fournisseur(role); else organisateur(current_role). Uses the user's own
  methods (`isInSupplierMode()`), never `currentSupplierId()` / `currentTeamId()`, which read `auth()` and abort outside a web request.
- `app/Ai/AiAuthAdapter.php` → `[$user->current_team_id]`; bound in `AppServiceProvider::register()`.
- `app/Ai/NavigationGates.php`: one method per route middleware (anyone, currentCampaign, editorOnCampaign, managerOnCampaign, manager,
  campaignCreator, supplier, supplierManager).
- `AuthServiceProvider`: `Gate::define('viewAiConversations', fn ($user) => $user->isAdmin())` (owner decision).
- `config/ai.php` (thin override, not a published copy): project, identity "Coco" (+ English persona: the assistant answers in the user's
  language), guardrails.domain_scope, `domain.understanding` fundraising examples, `audiences` (resolver + the 4 role texts of plan §5:
  organisateur reads organisateur+acheteur, fournisseur reads fournisseur, participant reads participant+acheteur, acheteur reads acheteur; insider
  voice only for organisateur/fournisseur; example questions per audience), `navigation.pages` (27 parameter-less pages from survey §1 "Top features
  per type", labels = the sidebar's own translation keys where it has one, gates as `[NavigationGates::class, method]`),
  `query_generation.enabled=false`, `file_context` (paths `resources/ai-help/aide/**/*.md`, collection `help_articles`, `corpus_languages=['fr']`,
  frontmatter key map), `llm_roles` (QU/follow-ups/navigation gpt-4o-mini, composition gpt-4o, query_generation gpt-4o as the fallback of the
  document-scope role; `OPENAI_MODEL` is Coolecto's own gpt-4.1-nano), identity links (profile.show, org.manage — no teams.show in Coolecto),
  chat (French welcome, examples, `page_examples` for the 5 routes of plan §9, `capture_page_route`, feedback on), `admin.layout=layouts.dashboard`.
- **Config caching:** Coolecto's deploy notes run `php artisan config:cache`; config closures make it fail. The AI config has none (verified by
  walking the merged config) — but Coolecto's existing `share-buttons` config already holds closures, so `config:cache` fails today regardless.
- Package changes this step needed (each replayed **0** on CondoEdge):
  - `NavigationPages`: `authorize` may be a `[class, method]` pair (container-resolved; a cached config holds no closure); labels go through
    `__()` so a host translation key shows the user's own UI label (FR/EN). Tests +2.
  - `QdrantChunkStore::hasFileChunks()`: a collection that does not exist yet holds no chunks (the first ingest into a new collection failed on
    a 404 count before anything created it). `QdrantChunkStoreFirstIngestTest` (2).
  - `ContextRetriever::retrieveContext()`: docs only → after Query Understanding, no entity selection, few-shot search or graph schema (plan W6:
    every docs-only turn was asking Neo4j for the schema — CondoEdge's local Neo4j, failing auth — and logging an error). `ContextRetrieverDocsOnlyTest`.
- `.env` (local, gitignored): QDRANT_HOST/PORT, `QDRANT_COLLECTION_PREFIX=coolecto_`, OPENAI_EMBEDDING_MODEL, AI_CHAT_ENABLE_FEEDBACK=true;
  `.env.example`: the same keys without secrets.
- Mount: `resources/views/layouts/dashboard.blade.php` passes `'aiChat' => true`; new `resources/views/kompo/content-bottom.blade.php` (Kompo's
  in-app hook) renders `AiChatFloating::toHtmlStatic()` when that flag is set and a user is signed in. Visual check: pending (npm build).

### D4. Ingest
- `ai:ingest --docs`: **458/458**, 2,580 chunks in `coolecto_help_articles`; **0** chunks with frontmatter text (`titre:`, `voir_aussi:`, `---`),
  **0** without audience, **0** without title, **0** whose audience differs from the folder. Audiences: organisateur 1,765, acheteur 332,
  fournisseur 301, participant 182.
- Re-run: Skipped 458, exit 0, ~1 min (C3 on real data).
- `ai:documents:map --backfill`: running.
- Smoke turn (owner, "Est-ce que je peux mettre un billet à 0 $ pour les bénévoles?"): only organisateur + acheteur articles retrieved, the right
  two first; correct answer with « Montant » / « Gratuit »; buttons: a follow-up question (off-topic: a donation question) + "Produits"
  → campaign.page.products. 17.9 s (QU 4.8 s, document search 5.7 s, composition 2.9 s, follow-ups 3.1 s, navigation 1.3 s).
- Chat mount verified in headless Chrome (owner user, `campaign.page.products`, signed login URL): FAB bottom-right, panel opens with "Coco /
  Online", French examples from `page_examples`, no colour breakage from the team-theme remap. `npm run dev`: compiled, exit 0.

## Phase E — Coolecto evals

### E1. Per-conversation user + `--only` (ai-eval, C2)
- `EvalSuiteLoader`: a conversation's optional `user` (id); `EvalCommand`: each conversation runs (and is logged in) as its own user, else
  `--user`; `--only=<ids or tags, comma-separated>`. `EvalCommandUsersTest` (3).

### E2. Gates (ai-eval, C3)
- `retrieves_doc` (slug or slugs; a passage's file name minus extension), `doc_audiences` (every retrieved passage's audience in the list; a
  passage without one fails; vacuous when nothing retrieved), `quotes_grounded` (every « » / “ ” quote of the answer is in a retrieved passage,
  case/space-insensitive; vacuous when nothing quoted), `no_placeholders` ({{x}}, {x}, [UPPER], :name, XXX — citations [1] and links pass),
  `branch_in`, `no_cypher`, `max_tokens` (usage total), `audience_is`. `ExpectationGateDocsTest` (6).
- Trace: `EvalRow::$audience` from the `audience` stage; each retrieved chunk carries its `audience`. `TurnTraceBuilderAudienceTest`.
- Package (replayed **0** on CondoEdge): `FileContextProvider::chunkLocator()` puts the chunk's `audience` on the reference (no prompt section
  reads it); `AiManager::fileContextTrace()` passes it to the observer. Test in `FileContextProviderDocumentTitleTest`.
- ai-eval 128 green; package filter FileContext|AiManager|Trace 549 green.

### E3. Suite (drafted, awaiting owner review — no graded run yet)
- `storage/app/eval/coolecto/` (Coolecto; `storage/app` is gitignored, `storage/eval` is not): 6 chunk files, 80 turns, + `REVIEW.md`.
- Askers are existing local users resolved by the host's own AudienceResolver (no seeding): owner 53, manager 5285, basic 4980 (organisateur),
  buyer 655 (acheteur), participant 247059, supplier owner 5058 (fournisseur).
- 42 FAQ turns: articles sampled per audience (22/8/7/5, seed 20261022, articles with ≥1 « label » and ≥120 words); questions written by an
  agent that saw only slug + audience + title; leak filter (no word 4-gram shared with the article, no 2+-word label) — 10 rejected, rewritten,
  0 left. Gold in `standard`: slug, audience, chemin, labels.
- 38 trap turns: follow-ups (3), role limits for a basic member (4), buyer escalation (3), another audience's topic (6), English (6), staff
  data (4), small talk (3), out of scope (3), KB gaps (3: QuickBooks, PayPal, mobile app — 0 articles mention them).
- All 6 files load through `EvalSuiteLoader` (expect keys and values validated).

### Owner decisions (2026-10-22, after E3)
- Suite: taken as reviewed (owner reads English only). French is the target; **at the end, replicate the whole suite in English and get the
  same results** (open item for the final report).
- ai-eval installed in Coolecto: path repository `../ai-eval` (symlink) + `require-dev condoedge/ai-eval:@dev`. Dry run first: 1 install,
  0 updates. `composer.json` changed (composer.lock is gitignored in Coolecto). `ai:eval` available.
- `add_processed_at_to_files_table`: run on local `financement` (owner: yes). No migration pending.
- `vendor/condoedge/ai.v009-bak` deleted (owner).
- Document map backfill: ~1 document/min (43/458 after 40 min, ~7 h total, no concurrency option). Left running. Baseline eval runs with
  `AI_DOCUMENT_MAP=false` in the eval process env; a second run with the map on after the backfill, compared on the same suite.

### E4. First baseline run (chunk 1) and fixes
- Chunk 1 (owner, 18 turns, map off): branch `knowledge_query` everywhere, audience organisateur 18/18, cites 16/16, quotes_grounded 16/16,
  no_placeholders/no_cypher/max_latency 18/18; wall clock 11–27 s (avg ~18 s). retrieves_doc 13/17: own-06 is a gold false negative
  (answered from `campagne-objectif`, better than the gold), own-03 answered from the registration-questions article (valid), own-04 and
  own-08 are partial misses (own-08's paraphrase "s'inscrit comme membre" reads as registration). Follow-ups resolved correctly. No forbidden
  UI term (Stripe, ristourne, chèque, frais ADF, virement) in any answer.
- **Bug found by the gate:** `doc_audiences` failed 18/18 "no audience" — the ingest stores `metadata.audience` as a LIST (frontmatter
  `public` may name several), the reference only took a string. Fixed in `FileContextProvider::chunkLocator()` / `AiManager::fileContextTrace()`
  (list), ai-eval trace + gate (a passage passes when one of its audiences is allowed). Tests updated to the stored shape; replay **0**.
- Package `resources/lang/fr.json`: 70 values were missing their accents ("Parametres du chat", "Selectionnez", "reponse"); fixed by an
  agent, verified mechanically (same keys/order/placeholders, identical once accents are stripped, no \u escapes). The 71st,
  `ai.actions.my_team`, is printed into CondoEdge's query-generation prompt (action list) → replay showed 23 one-character mismatches, so
  it stays "Mon equipe" in the package (replay **0**) and Coolecto overrides it in `resources/lang/fr.json` as "Mon organisme" (the UI word
  for a team). Open for the owner: CondoEdge shows "Mon equipe", and its English action list carries French labels — a fix needs a cassette
  re-record.
- Owner's merge left conflict markers in `resources/lang/{en,fr}/campaign.php` for a while; eval runs paused until it was resolved
  (navigation labels read `campaign.*`).

### E5. Baseline, all 6 chunks (80 turns, map off) — `storage/app/eval/coolecto/reports/*-baseline.json`
- Gates (failed/run): audience_is 0/80, **doc_audiences 0/80**, no_cypher 0/80, no_placeholders 0/80, max_latency_ms 0/80, branch_in 0/3,
  cites_document 1/52, quotes_grounded 1/51, retrieves_doc 7/54. No turn errored.
- Per audience: organisateur 45 turns (8 with a failed gate), acheteur 15 (0), participant 10 (1), fournisseur 10 (0); ~17 s and ~9.1k tokens
  per turn on average, alike across audiences.
- Reading the traces:
  - Good: grounded steps and labels throughout; buyers always sent to the organisation, never promised a refund or delay; basic members told
    when a role is required; English questions answered in English with the French UI labels glossed; no number invented on staff-data
    questions; small talk and out-of-scope short and on-identity.
  - **part-fu-share t2** ("Et je peux l'envoyer par texto aussi?"): QU read it as `knowledge_query` but named no entity/property/person, so
    the conversational precondition held; the conversational composer (gpt-4o) judged it conversation and answered ungrounded ("prenez une
    photo du code QR…") although the sharing article has a « Texto » button. Search tags / question type do not separate it from small talk
    ("C'est qui toi?" and "Merci" carry tags too). The composer declined 3 of the 4 real questions that reached it. Fix needs a change to the
    composer prompt (shared with CondoEdge → cannot replay 0): **held for the owner.**
  - Abstentions said "les documents disponibles ne contiennent pas…" (9 turns) and other-audience traps rarely said the useful thing (only the
    organisation can do/see this).
  - mgr-05: an organiser answer ended with buyer-article advice "contactez l'organisme".
  - mgr-08: quoted « Dates de début et de fin », in no retrieved passage. gap-01 offered speculative workarounds (export/third party).
  - Navigation buttons sometimes loose (raffle → "Participants"; personal-tax question → "Reçus d'impôt").
  - Suite: basic-03 expected `cites_document: false`; citing the roles article is right — expectation to drop.
- Fixes (fix1):
  - Package: `ai.domain.response.documents_name` (GuidelinesSection's "If {documents} do not contain the answer…"); default "the available
    documents" = today's text. `GuidelinesSectionDocumentsNameTest` (2); replay **0**.
  - Coolecto config only: documents_name "Coolecto's help articles"; organisateur role text: a buyer article's "contact the organisation"
    means them; acheteur/participant/fournisseur: say plainly what only the organisation manages instead of "the articles do not cover it".

### E6. fix1 results (chunks 2–6, 62 turns) — `reports/*-fix1.json`
- Gates: doc_audiences 0/62, audience_is 0/62, no_cypher/no_placeholders/max_latency 0/62; retrieves_doc 2/37 (baseline same chunks 5/37),
  quotes_grounded 1/35, cites_document 1/36 (basic-03: wrong expectation). ~16–17 s and ~9.1k tokens per turn (unchanged).
- Read: other-audience questions now say what is reserved to the organisation (buyx-05, partx-01/02, supx-01/02); abstentions name "les
  articles d'aide de Coolecto" ("documents disponibles" 9 → 1 answer); mgr-05 no longer tells the organiser to contact itself.
- part-05 quotes « Message envoyé à [nombre] personnes » (a label reshaped with a placeholder): open, minor.
- Suite corrections from reading (not to make gates pass): `retrieves_doc` also accepts `campagne-objectif` (own-06), `campagne-dates` (mgr-06),
  `inscriptions-questions-par-cours` (own-03) — each answered the question correctly; basic-03 drops `cites_document: false`.

### E7. Conversational composer: follow-ups (owner: "do it now", re-record CondoEdge; "if it's worse, add the text to Coolecto only")
- An appended clause in the verdict line ("A follow-up that asks for more about the recent conversation … : false") changed nothing live
  (part-fu-share t2 still conversational 3/3).
- Variant test on recorded prompts (`conv_variants.php`, 8 samples each, composer settings):
  | case (expected) | gpt-4o: old / clause / task line | gpt-4o-mini: old / task line |
  |---|---|---|
  | part-fu-share t2 (false) | 8/8 · 8/8 · **0/8** conversational | — · 0/8 |
  | 3 real questions declined in the baseline (false) | 0/8 each, all variants | gap-03 8/8 under every variant (mini weakness) |
  | 3 small-talk turns (true) | 8/8 each, all variants | 8/8 each |
  | CondoEdge context-degradation-study t9 "What was the first question I asked you?" (true; CondoEdge runs gpt-4o-mini) | — | 8/8 · **8/8** |
- Kept: the task line — "A question about the application or about what the user can do in it is never conversation — also when it is a
  short follow-up on the recent conversation that you could answer from it." Test in `ConversationalReplyComposerTest`; conversational prompt
  snapshot refreshed (deliberate).
- Same re-record: package `ai.actions.my_team` back to "Mon équipe" (Coolecto keeps its own "Mon organisme").
- CondoEdge: cassette backed up as `storage/eval/gold.cassette.2026-10-22-before-composer.json`; original recording 39 turns: 37 ok,
  1 gate-failed (columns_include), 1 context-not-retrieved. New recording: running.

### E8. Chat theme (owner request)
- `app/Ai/CoolectoChatTheme.php` (Coolecto): the package theme contract in Coolecto's AI colours — `btn-grad-darker` magenta
  (#D61250 → #DD2476 → #C10B50) for actions, `btn-grad` orange→magenta (#FF512F → #DD2476) for the avatar/accent, light pink tints. In app/
  because Tailwind scans app/ and vendor/condoedge/, not config/. `config/ai.php`: `ui.factory = ConfigChatThemeFactory`, `ui.theme =
  CoolectoChatTheme::class` (one look for everyone; the indigo/green picker only shows with the user factory). `npm run dev` compiled the hex
  classes; screenshot: avatar, "+", "Start New Chat", active tab and FAB in magenta.
- **Result (E7):** Coolecto live: part-fu-share t2 now routed to search (variant test 0/8 conversational; full re-run pending in the
  next chunk run). CondoEdge **re-recorded** (`gold-record-composer-followup.json`, cassette replaced; old one kept as
  `gold.cassette.2026-10-22-before-composer.json`): 39 turns, 37 ok, same gate-failed t8 (columns_include email) and same
  context-not-retrieved t9 as the original; the composer turn t9 gives the same answer. One divergence:
  context-degradation-study t10 ("How many members did you say our team has, again?") returned 0 rows in 4/4 live runs (original:
  523). Cause traced: the system prompt is identical; the earlier turns of this 11-turn conversation came out differently live
  (previous result = 100 member rows instead of urgent tasks), and the generator then returns an empty query (confidence 1) —
  model behaviour on a follow-up over a data result, not today's edits (also reproduced with "Mon equipe" restored). **Open item
  for CondoEdge**, not Coolecto. New baseline replays 0.

### E9. Owner requests (2026-10-22, evening)
- Theme lighter: `CoolectoChatTheme` → primary #E9488A (gradient #EC5B92 → #E9488A → #E2407F), avatar #FF7A59 → #F0568F →
  #E9488A, lighter tints. Built and checked in headless Chrome.
- **Route names never reach the model** (user saw "…which is "org.manage"…"): `AiChatFloating` capture stores `page_name` (the
  page's `ai.navigation.pages` label, translated; '' when not listed) next to `route`; `PageContext` DTO carries it (key only when
  set); `ResponsePageContextSection` and `PageContextSection` name the page by it ("page: Mon organisme", "Call this page by that
  name only, never by a route or any technical identifier") and never print the route of a captured page; a host's own page
  context without `page_name` is unchanged. Tests `PageNameInPromptsTest` (4), `AiChatFloatingPageRouteTest` (+1). Replay **0**.
  Live: "Qu'est-ce que je peux faire sur cette page?" on org.manage → « Sur la page "Mon organisme" … », no route.
- **File references off by config:** `ai.chat.show_file_references` (default true): off → no citation links and no "Referenced
  files" cards (markers still stripped from the text). Coolecto sets false (help articles live in the source tree). Test in
  `MessagesQueryFileCitationTest`. Replay **0**.
- Plans written (design + execution, owner review pending):
  - `docs/plans/2026-10-22-page-section-anchors.md` — section ids from title translation keys, planner command, `?section=`
    transport (hash is lost on Kompo turbo), DOM-relative tab opening, package `sections` on navigation pages.
  - `docs/plans/2026-10-22-task-orchestrator-campaign-creation.md` — options A–F compared; chosen: use-case takeover +
    `TaskDefinition` registry, `ai_tasks` + hash-chained `ai_task_events`, one schema-bound call per turn, code-built summary,
    version-bound confirm token, Coolecto `CreateCampaignTask` reusing `createCampaignFromResults` + a `CampaignSettingsWriter`
    sharing the cards' rules, limits, 10 new articles, evals, rollout.
  - `docs/plans/2026-10-22-condoedge-kb-new-session-prompt.md` — self-contained prompt for a new session (CondoEdge KB).
- Full package suite after E9: 5,051 tests, 2 failures — both `ConversationResumeBuilderTest` asserting the old unaccented French
  ("Dernier resultat affiche", "Derniere reponse"); expectations updated to the accented strings → 11/11 green. Suite otherwise
  green (47 skipped).

### E10 — Document map on vs off (2026-10-22)

- Backfill done: 458 help articles mapped in 4.3 h (5,851 gpt-4o-mini + 374 gpt-4o calls, ~4.0M tokens).
- Clean A/B on the same code, full 80-turn suite (`*-map0` = `AI_DOCUMENT_MAP=false`, `*-map1` = on):
  gate failures 3 → 5 (map adds own-07 quotes_grounded, own-fu-refund#1 retrieves_doc; fixes none),
  latency 13.3 → 13.5 s/turn, tokens 9.4k → 11.9k/turn (+27%).
- Repeats (3× each): own-07 is noise (fails once with the map off too). own-fu-refund#1 is real:
  0/4 failures off, 4/4 on.
- Cause: a map turn skips the search probes (`AiManager::mapTurnPlanned` → no `writeSearchProbes`).
  On a follow-up ("Pis l'acheteur, est-ce qu'il est averti quand c'est fait?") the probe was the angle
  carrying the refund context; without it the top-5 is receipt/delivery articles, the reader only sees
  those 5 files' map, its governing pick is already held, so the gate stays closed and nothing rescues.
- Side note: no article says whether the buyer is told about a refund — map-off "passes" by citing the
  organiser-side confirmation message. KB gap, not only a retrieval one.
- Recommendation: map off for Coolecto (short how-to articles; the map was measured on long by-laws).
  Package option if kept on: still write probes on follow-up turns of a map turn.
- 2026-10-23: map turned off in Coolecto `config/ai.php` (`file_context.document_map.enabled => env('AI_DOCUMENT_MAP', false)`;
  merged config checked). The follow-up/probe issue and the mixed-corpus rule are now step 4 of Phase 6 in
  `2026-10-22-condoedge-kb-new-session-prompt.md`, to settle when CondoEdge's help articles land (its map stays on).

### F0 — Plan execution starts (2026-10-23)
- Owner confirmed Phase 0 of both plans (anchors: all 4 decisions; orchestrator: option A + chip start, §4.3 limits, all types
  except raffle/membership, all optional groups, Members/Scanners never see the chip).
- Route gap fixed first, separately: `Step1InternalForm::authorizeBoot()` aborts 403 for Member/Scanner (same rule as the sidebar).
  Test `tests/Feature/Onboarding/NewCampaignAccessTest.php` (red: member/scanner got the page; green: 3/3); onboarding folder 91 passed.
  Finding: condoedge/utils `ErrorViewHandler` answers HTTP 200 on every error page with APP_DEBUG off and shows a failed
  `authorizeBoot()` (AuthorizationException) as a *server error* — hence `abort(403)`. Not fixed (utils package; owner to decide).
- Execution model (owner: right token spend, same guidelines as planned): one implementer agent per batch of tasks, sequential;
  the controller verifies each batch's gates itself; one combined spec+quality review per plan. Contract for every agent:
  `docs/plans/AGENT-BRIEF.md`. Research notes moved from the session scratchpad to `docs/plans/research/`.
- Order: anchors (A: tasks 1–5 host ids → B: 6 planner → C: 7 package → D: 8–9 landing + runtime check → E: 10 config + eval →
  review), then orchestrator phases 1→6, then the CondoEdge KB prompt (needs the owner's answers, so it runs where the owner can answer).
- Anchors batch A (Tasks 1–5) done; owner committed it (`e0afe97f4`). Verified: `tests/Feature/Ai` + `tests/Unit/Ai` 23 passed.
  Deviation (accepted): settings ids sit on the card wrapper built in `Campaign::campaignSettingsEls()` (six steps already carry
  komponent ids other code refreshes, three override `render()`); experience-tab copies get no id. `SectionId` in
  `app/Kompo/Common`. Pre-existing, unchanged: 6 failures in `InscriptionNetworkPageTest`. Open: utils `_LazyTabs` counts a null
  conditional tab when choosing the eager `tab_number` (participants page without inscription tab; no visible effect).
  Agent cost: ~300k tokens, 49 min. Batch B (Task 6 planner) started.
- Anchors batch B (Task 6 planner) done (~176k tokens, 11 min). Verified: `tests/Unit/Ai` + `tests/Feature/Ai` 30 passed; the
  registry `resources/ai-help/sections.php` is byte-identical on a second run. Scanner follows `render()` to `SectionId::for()`
  calls (the plan's title heuristics were unnecessary). 11 routes (adds products + supplier account statement, both carry ids),
  110 sections, extras vs render tests are conditional only. `--check-articles`: 112/210 chemins name a section (53%).
  Kept by the controller: "after a conditional return" marks a section conditional (all dashboard cards); `sec-team-my-joined-teams` stays.
- Batch C (Task 7, package) started. Config shape decided: `pages[*].sections = [id => ['label' => key, 'conditional' => 0|1]]`.
- Anchors batch C (Task 7, package) done (~134k tokens, 9 min): `NavigationPages` offers sections only for pages that declare
  them (anyOf schema branch per page, enum ids + null; `max_tokens` 60 → 120 only then), `pick()` returns `[page => ?section]`,
  `ActionAttacher::withNavigation` adds `params.section` + "Page › Section" label. New `NavigationSectionsTest` (8, incl.
  byte-identical snapshot without sections). Filter 106 passed; full package suite 5,059 tests, 47 skipped, green.
- Replay reported 6 mismatches, all `Today is 2026-10-22` vs `…-23` (cassette recorded the day before). Fixed in ai-eval, not by
  re-recording: the tape keeps `recorded_on`; replay masks the recording day (recorded side) and today (live side) as `<today>`
  (`Cassette::maskDay`; tests `the_recording_day_replays_as_today_on_a_later_day`, `a_date_that_is_not_today_still_is_a_mismatch`;
  ai-eval 130 passed). Existing CondoEdge cassette backed up (`gold.cassette.2026-10-23-before-recorded-on.json`) and stamped
  `recorded_on: 2026-10-22` (file mtime). Replay with batch C: **0 mismatches, 0 drift**.
- Open (owner): `ActionAttacher::dedupe()` keys every entity-less chip `generic:link`, so of two navigation pages only the first
  button survives (pre-existing; likely part of "weak navigation chips").
- Batch D (Tasks 8–9, landing script + headless verification) started.
- Anchors batch D (Tasks 8–9) done (~250k tokens, long wall time from headless runs). `resources/js/ai-sections.js` loaded as a
  `.reloadable-script` in `layouts/app` (Kompo turbo re-appends it after the swap, so no `PageAdf` `onLoad`); tab label found by
  slot index in the same `.vlTabs` (Kompo tabs have no `aria-controls`); scrolls only the content scroller, not `scrollIntoView`
  (the document overflows by 32px). Measured offsets: `scroll-margin-top` 16px, 72px under the settings fixed tab bar (plan's 96
  was a guess). `scripts/ai/verify-sections.mjs`: 0 failures on all 11 routes, full load + turbo + unknown id; dashboard checked
  with organiser 51 (53's campaign is still in setup). Controller re-ran communications, recipients, dashboard (51) and
  supplier.info: 0 failures, same figures. `public/js/ai-sections.js` is a dev build, like the other tracked page bundles.
- Batch E (Task 10: config `sections`, `link_section_in` gate, 8 turns, 6-chunk run vs `map0`) started.
- Anchors batch E (Task 10) done (~185k tokens, 33 min). `App\Ai\Sections\NavigationSections::onPages()` wraps
  `navigation.pages` in Coolecto `config/ai.php` (all 11 registry routes have a page). ai-eval gate `link_section_in` (vacuous
  without a link; ai-eval had no route gates, follows the docs gates). 8 `sec-*` turns in chunks 1/2/5 (+ REVIEW.md rows): 8/8
  pass. 6 chunks map0 → sec: 3 gate flips (part-05 quotes, sup-05 retrieves/cites) passed 2/2 re-runs = noise; own-10 fixed.
  Tokens/turn 9,393 → 11,138 (+19%, navigation stage 488 → 2,190); latency 13.4 → 14.2 s. Replay 0. Controller verified:
  Coolecto Ai tests 33 passed, ai-eval 131 passed, diff read. Pre-existing: `config:cache` fails on `kompo-auth.php` (`Rule::unique`).
  Findings for the owner: (1) the one `dashboard` page serves organiser, buyer and supplier, so its organiser card sections are
  offered to buyers/suppliers ("Tableau de bord › Transactions" to a buyer, 2/2); (2) `campaign.dash-team-and-results` label has
  a `:participants` placeholder (the card fills it via `ParticipantVocabulary`), shown raw in a chip; (3) some wrong section
  picks (own-08, own-11, own-fu-refund t2) — page right, section off.
- Anchors combined review (~176k tokens): nothing on schema/enum, unoffered-page leaks or CondoEdge identity. Fixed by the
  controller: landing `cancel()` stripped the next page's `?section=` (cancel now only stops waiting); `tabLabelFor` guards a
  panel outside `.vlTabs`; scanner `returnedKeys()` resolves a method by name only when one file in app/ defines it, else
  unresolved; planner page list = `PAGES` only (dropped the unset `ai.navigation.section_pages`); `ActionAttacher` follow-ups
  docblock back above its method; neutral examples in `NavigationPages` comments; ai-eval test
  `a_replay_that_still_says_the_recording_day_is_a_mismatch` (passes as written: characterises existing logic).
  Owner decisions applied: dashboard split into `campaign_dashboard` (organisateur, registry sections) and `my_dashboard`
  (fournisseur, acheteur, `sections => []`; a page declaring `sections` keeps them); `onPages(..., labels:)` gives
  `sec-campaign-dash-team-and-results` the new key `campaign.dash-participants-and-results` (fr/en). Tests: Coolecto Ai 34 passed
  (new: dashboard split, no `:placeholder` in a chip label), ai-eval 132, package filter 106, replay 0, registry byte-identical,
  `npm run dev` ok, verify-sections recipients + communications 0 failures. buyx-02 → `user.transactions`, supx-02 →
  `supplier.clients`, no dashboard section. Not changed: wrong section picks (own-08/11, refund t2), `tab_key` null on tabbed
  pages (title key is the tab key), link-presence gate (owner's call). **Anchors plan complete.**
- Orchestrator Phase 1, batch 1a done (~161k tokens, 12 min): `TaskDefinitionInterface`, `DTOs/Tasks/*` (TaskField with
  `isValid`, `group`; TaskDraft; TaskSummary; TaskOutcome; TaskLimits; TaskKnowledge), models `AiTask`/`AiTaskEvent` (events
  immutable via Eloquent), migration `2026_10_23_000001_create_ai_tasks_tables` (package test DB only), `TaskStore` (optimistic
  version, `loadForUpdate`, hash chain + `verifyChain`), `TaskPlanner` (pure). Fixture `BookRoomTask`. Filter Task 40 passed
  (controller re-ran), full suite 5,094 / 47 skipped green, replay 0. Accepted deviations: `TaskRun` → `AiTask`; `TaskField.group`
  (no group = required); type rule prepended to `rules`; only FK is events→tasks. Controller decisions for 1b: (1) newest-event
  deletion → `ai_tasks.last_event_hash` in the same unreleased migration; (2) MySQL chain check deferred to Phase 3 (needs the
  owner's OK to migrate the dev DB); (3) draft keeps declined groups; (4) `version` bumps on draft changes only, counters do not.
- Batch 1b (TaskStepCaller, TaskOrchestrator, token claims, use-case hook, prompt snapshot) started.
- Orchestrator batch 1b done (~340k tokens, 29 min): `TaskStepCaller`, `TaskOrchestrator` (state machine, limits, audit,
  idempotent execute in a savepoint inside the confirm transaction), `DTOs/Tasks/TaskStep`, `Support/Spotlight` (fence moved out
  of `BasePromptSection`, same output), `PendingActionToken::issueForTask/bindsCurrent` (+ `ActionDispatcher::approve` check),
  `AskQuestionUseCase` hook (admission via `QueryExecutor::admit`; `$tasks` null unless `ai.tasks.enabled` + definitions),
  `FileSearchService`/`QdrantChunkStore` `slugs` filter, role `task_orchestrator` (falls back to `query_understanding`), snapshot
  `task_step.txt`. Controller's 4 decisions applied (last_event_hash, declined groups, `TaskStore::record` without version bump,
  `TaskField::$pii`). Groups: synthetic bool field `_group` asked when nothing else is left. Deviations accepted: up to 2 calls
  a turn (ask after a full answer), daily limits also checked at `start`, turn/call/TTL limit → `limit_hit` + `expired`, `ip`
  null (brief rule 4), `claims()` skips unregistered definitions. Agent: filter Task 87 passed, full 5,143 / 47 skipped (before
  its last small edit), replay 0. Controller: filter Task|Spotlight|token|dispatcher|use case|file search|snapshots 175 passed,
  replay 0; a full-suite re-run was killed by the host for low memory (not a test failure) — to re-run when memory allows.
  Phase 2 decisions: `reopen(task, field)` behind "Modifier"; `start` on a conversation with an active task resumes it; a
  refused confirm appends `confirm_refused`; Qdrant payload indexes on `metadata.slug`/`audience` deferred (shared Qdrant: ask).
- Orchestrator Phase 2 (chat UI) done (~307k tokens, 19 min): `reopen`, resume on `start`, `confirm_refused`; `HandlesTasks` on
  `MessagesQuery` (all actions scoped to the conversation + user, else 403); `MessageBubbleRenderer::taskElements()` (progress
  line, choice chips via follow-up path); `Kompo/Actions/TaskSummaryCard`; regenerate hidden + refused server-side on task
  messages, edit hidden on task answers; `WelcomeSuggestions::tasks()` chip start; admin timeline via
  `ConversationInsights::taskTimeline()` with chain status; "Tasks" filter in `AdminConversationsTable`. Added `label()` to
  `TaskDefinitionInterface`. Controller: filter (Task|MessagesQuery|MessageBubble|Admin|Welcome|NoDomainContent|Snapshot) 191
  passed, replay 0. Gap sent back: plan §4.3 requires explicit per-row confirmation of money/legal rows — badge + one Confirm
  is not enough; adding a per-row acknowledgement checked server-side in `confirm` (`confirm_refused` reason `unacknowledged`).
  Owner approved (2026-10-23): run the ai_tasks/ai_task_events migration on Coolecto's dev DB when Phase 3 starts.
- Phase 2 follow-up done (~46k tokens): per-row acknowledgement of money/legal rows, checked by code in `confirm`
  (`confirm_refused` reason `unacknowledged` + missing keys; `confirmed` payload records the keys). `TaskSummaryCard` is now a
  small Kompo Form (Query cards don't post with `withAllFormValues`). Controller: filter 194 passed, replay 0.
- Phase 3 prep (controller): Coolecto dev DB had 3 other pending migrations (suppliers show_to_admin, surveys end_contact,
  choice result message) — left alone. Ran only `--path=vendor/condoedge/ai/database/migrations/2026_10_23_000001_create_ai_tasks_tables.php`
  (pretend output matched §4.2). MySQL hash chain check (tinker, rolled back): intact → true, edited `value_after` → false.
- Full package suite after Phase 2: 5,166 tests, 47 skipped, green (4 min).
- Phase 3 batch 3a done (~239k tokens, 33 min): `App\Services\Campaigns\CampaignSettingsWriter` (fluent groups dates/goal/
  donation/coverFees/receipts/contact/reason/leaderboard → `write()` validates all then one transaction, `ValidationException`
  keyed by field; 403 outside current team; a group refused when its card is not in `getSettingsSteps()`; `forced()` for parent-
  team cover fees). Shared rule statics on 8 cards (`datesRules`, `goalRules`, `donationRules`, `coverFeesRules`, `receiptsRules`,
  `contactRules`, `reasonRules`, `leaderboardRules`); date guards moved to `CampaignSetAndHandleDatesTrait`
  (`validateDeliveryDateAgainstDates`, `validateEndDateAgainstChosenDelivery`, `abortIfDeliveriesSentForSingleDate`);
  `Campaign::coverFeesDisabledByParent()`. Removed 2 unreachable overrides in `StepCampaignDatesSupplier` (controller checked: its
  `beforeSave`/`rules` never call parent). Controller re-ran writer + shared-rules tests: 35 passed. Agent: Campaigns 349 passed +
  1 deadlock from its own parallel runs (passed alone), Ordering 195 passed. Not supported: raffle dates (staff-only),
  inscription dates (onboarding answers), delivery date (own card), round-up. Decisions for 3b: donation options group skipped on
  Donation/P2P/Cheerup (those are default products; §4.3 forbids product prices); reason offered for Product/Donation only;
  `execute` runs in the user's confirm request (`getSettingsSteps()` needs auth).
- Batch 3b (CreateCampaignTask, execute, golden tests, config) started.
- Phase 3 batch 3b done (~392k tokens, 40 min): `app/Ai/Tasks/CreateCampaignTask.php` (716 lines; fields per type per §4.4,
  raffle/membership never offered, groups with `pii` contact email, supplier template → the template-copy branch with a legal
  terms row), `config/ai.php` `tasks` (`AI_TASKS`, default false), 8 `coco-*` keys. Pure extractions: `Onboarding::
  campaignFromAnswers()` (out of `createCampaignFromResults`), `CampaignCopyTrait::copySupplierTemplate()` (out of
  `AcceptCopySupplierCampaignModal`), `CampaignSettingsWriter::errors()`. Golden test drives the real wizard screens per type and
  compares columns, products, settings, sub-campaigns (excludes id, timestamps, qr_code, onboarding_id, reminder_end_job_id);
  shown to fail on injected drift. Controller re-ran: Feature/Ai 50 passed, Onboarding 91 passed; read the extraction diffs.
  Agent: Market 86 passed + 1 failure from the pending `suppliers.show_to_admin` migration (not ours), Unlocks 76 passed.
  Owner (2026-10-24): supplier terms accepted in chat as a legal row + explicit tick — yes; end-to-end check uses a fresh
  throw-away organiser + team, kept after the run. Open: the outcome's dashboard link is not rendered (package UI gap);
  `tracked_events` written only with a request actor; knowledge slugs wait for Phase 4.
- Batch 3c (headless end-to-end with the real model + outcome link) started.
- Phase 3 batch 3c done (~265k tokens, 39 min). `TaskOutcome::withLink()`, outcome link chip in `MessageBubbleRenderer`;
  `CreateCampaignTask` links via `switch.campaign` (+ `redirect_to` dashboard). Replay 0. E2E (2nd server `php -S :8099` with env
  overrides, `.env` untouched; test org user 264617 / team 426841, kept): a1 stuck (model never declined a group → loop; fixed
  with a `_group` hint clause in the task prompt — task prompt, not QU: accepted), a2 done (campaign 286831, enabled 0, 21 turns,
  35 calls, 39k tokens, 340 s), b done (286832, `rate`/`pay_fixed_fees` untouched after "mets les frais à 0 %"; 18 turns, 32
  calls, 35k tokens), c cancelled (3 turns). Chains ok. Controller checked the reported `switch.campaign` hole: false —
  `User::switchCampaign` refuses a campaign outside `currentTeamCampaigns`; pre-existing open redirect on `redirect_to` noted for
  the owner. Too long: every optional group is its own yes/no turn (plan §4.2 offers them together).
- Batch 3d (fixes: one combined group offer that also captures values, today's date in the task prompt + no past end date,
  enum labels in prompt/chips/draft; re-run E2E a + b) started.
- Batch 3d done (~160k tokens on top of 3c): one `_groups` offer (comma-separated option values, `none`; audited per group),
  group fields listed with the offer (a value there accepts its group), guard dropping suggestion-equal values on the offer turn,
  stale model question not reused; "Today is" from `ResultsDataSection::today()` (public static); past end date refused
  (`campaign.coco-end-date-in-the-past`); enum labels in prompt/draft/chips (`chooseTask` → `TaskOrchestrator::choose`),
  contract gains `groupLabel()`. Snapshot `task_step` refreshed (intended). Controller: package filter 170 passed, Coolecto
  Feature/Ai 52 passed, replay 0. E2E: product a2 → a3 21 → 12 turns, 35 → 14 calls, 39k → 19k tokens; donation b → b2 18 → 7
  turns, 32 → 10 calls, 35k → 12k tokens, dates right first time, `rate` untouched. Campaigns 287172, 287173; 2nd test organiser
  264795 (team 427197 came with it) after the 3/day limit hit. Remaining: gpt-4o-mini ignores some declines ("c'est tout");
  gpt-4.1-mini handled them in a probe → owner's call on `llm_roles.task_orchestrator`. The suggestion guard cost one extra
  question once (it dropped a real "yes").
- Owner (2026-10-24): `llm_roles.task_orchestrator = gpt-4.1-mini` (Coolecto config only). Re-runs, no code change: a3 → a4
  12 → 10 turns, 18.8k → 15.1k tokens, group offer 4× → 1×, 136 s; b2 → b3 7 → 8 turns, 12.1k → 14.3k tokens, offer 1×, 117 s.
  "Non merci, c'est tout" / "Non, c'est correct comme ça" declined first time. Values right (287434, 287435), `rate` untouched.
  4.1-mini proposes the type instead of setting it (+1 turn). Cost at list prices ≈ under 1 ¢ a run either way (prices not in
  repo; ai_tasks stores total tokens only). Controller: suggestion-equal guard removed (dropped a spoken "yes" twice; 4.1-mini
  does not echo; money/legal rows still need a tick).
- Suggestion guard removed (TDD: `a_value_equal_to_its_suggestion_on_the_offer_is_kept_and_accepts_its_group`). Controller:
  filter 170 passed. **Phase 3 complete.**
- Phase 4 (articles) batch 4a started: write the articles + English review sheet + code-cited fact sheet; no ingest until the
  owner has reviewed. Overlap to check: demarrage-types-de-campagnes, demarrage-creer-une-nouvelle-campagne,
  compte-politiques-du-reseau, compte-reseau-d-organismes, frais-couverture-des-frais-par-les-acheteurs, billets-types-…
- Phase 4 batch 4a done (~333k tokens, 17 min): 8 new articles in `resources/ai-help/aide/organisateur/`
  (choisir-le-type-de-campagne, comparer-{dons-pair-a-pair-incitation, repas-benefice-et-billets, formulaire-et-inscriptions,
  encan-et-autres}, valeurs-par-defaut-a-la-creation (merged #7), regles-de-reseau-a-la-creation, creer-sa-campagne-avec-coco);
  #6 dropped (recus-envoi-automatique-des-recus covers it). Fact sheet `research/articles-creation-facts.md`, English review
  sheet `research/articles-creation-review.md` (6 proposed edits to existing articles, not made). 172 « » labels, 0 missing;
  no forbidden term, no fee figure; frontmatter parses. Controller: `CreateCampaignTask::ARTICLES` updated (dropped slugs
  replaced, + demarrage-types-de-campagnes, billets-types-…, catalogue-choisir-un-fournisseur-a-la-creation,
  demarrage-mise-en-ligne); TDD `test_every_article_it_cites_exists` (red on the dropped slugs, then green); Feature/Ai 53
  passed. Controller decisions: website guides OK as a source of situation examples only; the 60-call cap stays out of the
  article (internal). Waiting: owner review before ingest.
- Owner (2026-10-24): ingest + retrieval check yes; apply the 6 edits to existing repo articles; no default amounts in articles.
  **Open (owner, later):** every Form is created with a participant list while the wizard's "groupe fermé?" answer
  (`closed-campaign`) is never read — a wizard bug, not in this plan. Batch 4b (edits, ingest, retrieval eval) started.
- Phase 4 batch 4b done (~50k tokens on top of 4a): 6 edits to existing repo articles (auction card typo, Form participant list,
  Billets/Encan wizard questions, per-date default 0 vs 1, added-donation scope, « billets (hors tirage) »); checks clean.
  Ingest re-embedded all 466 files (Success 466; collection 2,580 → 2,638 points, replaced in place, no duplicates): git
  autocrlf puts CRLF on disk while the stored hashes were of LF bytes. Retrieval eval `7-creation-articles.json` (15 turns,
  tasks off): 14/15 retrieved + cited; the miss (edt-06 "questions when creating an auction") answered correctly from the new
  comparer-encan-et-autres → controller widened its gold to accept either article (gate is any-of). ~13.3k tokens/turn, no
  $/% stated. Controller fix (package): `FileProcessor::sourceHash()` hashes bytes with CRLF read as LF (TDD
  `a_file_whose_line_endings_changed_is_unchanged`); file-processing filter 59 passed; replay 0. One more full ingest restamps
  the hashes (running), then a no-op run must skip all 466.
- Restamp ingest: Success 458, Skipped 8; immediate re-run: Success 0, Skipped 466. **Phase 4 complete.**
- Phase 5 (task evals) started: ai-eval task turn actions + gates `task_status_is`, `task_field_equals`,
  `no_execute_without_confirm`, `audit_complete`; suite `storage/app/eval/coolecto/tasks-*.json`.
- Phase 5 done (~349k tokens, 30 min). ai-eval: task turn actions (`start_task`, `choose`, `reopen`, `cancel`, `confirm
  {acknowledge, token: current|previous}`, `writes_to`) through `TaskOrchestrator` (`TaskActionDriver`, `TaskEvidence`); gates
  `task_status_is`, `task_field_equals`, `no_execute_without_confirm`, `audit_complete`. Suites `tasks-{personas,adversarial,
  limits}.json`: 13/13 conversations pass all gates (5 personas done in 6–9 turns, 9–14 calls, 18–29k tokens, 24–41 s;
  member refused; fees-zero/injection: rate/pay_fixed_fees/enabled untouched, no raffle; old summary + unacknowledged refused;
  two tabs → one execute; 4th creation refused). Bug fixed: `fundraiser_goal` asked until a type is chosen only (registrations
  looped). Controller: ai-eval 144, Feature/Ai 54, replay 0, the 8 campaigns (287668–71, 287788–91) enabled 0, rate 0.03,
  pay_fixed_fees null, test teams only. Test orgs 265055–265059 (teams 427716/18/20/22), kept. Model notes: label instead of
  value for "Mon fournisseur"; invented field names after the summary (rejected); raffle answer promised to "put you in
  contact". Turn-limit run skipped (limits hard-coded). Follow-ups sent: exact label → value mapping, `manualUrl()` link on
  every limit message, `confirm_refused` reason `not_waiting`.
- Phase 5 follow-ups done (~49k tokens): exact translated-label → enum value in `TaskStepCaller`; `TaskDefinitionInterface::
  manualUrl()` (Coolecto: `new-campaign`) as a link chip on all 4 limit messages; start-time limit refusal → `Log::info` + answer
  metadata (no task row); `confirm_refused` reason `not_waiting`. School persona re-run typing the supplier answer: all gates,
  9 turns, 26k tokens (campaign 288026, organiser E 265247 / team 428099, kept). Controller: package filter 151, Feature/Ai 55,
  replay 0. **Phase 5 complete.**
- Phase 6 (rollout: pilot allowlist, admin KPI, feedback triage of task turns) started.
- Phase 6 done (~168k tokens, 17 min): `ai.tasks.pilot_team_ids` (`AI_TASKS_PILOT_TEAMS`, empty = all) enforced in
  `CreateCampaignTask::startableBy` (`inPilot()`); `.env.example` gets `AI_TASKS=`, `AI_TASKS_PILOT_TEAMS=`; admin KPI card
  "Campagnes créées avec Coco" (+ "N en ligne") in `AdminDashboard::kpiRow2` via `AdminDashboardService::getCocoCampaignStats()`;
  👎 on task messages already worked (2 package tests added); admin finds them with Feedback = negative + Tasks = with a task;
  triage note `research/tasks-feedback-triage-note.md`. Controller: Feature/Ai + KPI test 58 passed. **All 6 phases built.**
- Orchestrator combined review started.
- Orchestrator combined review (~225k tokens): nothing on token/ack checks, conversation scoping, forbidden fields,
  raffle/membership, role/pilot/suspended re-checks, savepoint rollback, hash chain, extracted creation paths (behaviour-
  preserving), CondoEdge inertness, fencing of user text, host-agnostic src. Findings: (1) CRITICAL — a turn/reopen committing
  after a concurrent confirm resets a DONE task to reviewing/collecting (commit re-checks version only; `record()` never bumps
  it) → a second confirm executes again (controller confirmed in code); (2) concurrent confirms of two tasks pass the daily
  limit (count not locked); (3) `prune` keeps values invalid under the field's current options (has_recipients 3 written on a
  template copy); (4) `WriteCommunicationContentJob` dispatched inside the execute transaction (no afterCommit); (5) rejected
  values of invented fields audited in clear; (6) supplier terms + team/network names unfenced in the prompt; (7) execute
  failure logs `$e->getMessage()` (may hold PII); (8) double start opens two tasks. Suggestions: unreachable task check in
  `ActionDispatcher`, duplicated risk list, `askHint` typo, `fields()`/`startableBy` re-queried per turn/render. Note for the
  owner: the package migration will create the 2 (idle) task tables at CondoEdge's next `migrate`. Fix batch started.
- Review-fix batch done (~268k tokens, 24 min): (1) `commit()` refuses under the lock when the status changed since the caller
  read it (race tests via a closure reply in `ScriptedJsonLlm`); (2) confirms serialised by `Cache::lock` per definition+user
  then team, outside the transaction (`ai.tasks.busy` after 10 s); (3) `prune` + `confirm` drop values the current field rejects
  and ask again; (4) already `->afterCommit()` (reviewer read the wrong line; controller checked); (5) undeclared-field values
  audited as `provided`; (6) supplier terms / organisation / network fenced with `Spotlight` in Coolecto; (7) execute failure
  logs class + code only; (8) start serialised per conversation. Unreachable `ActionDispatcher` task check removed (file back to
  HEAD); one risk list (`TaskSummary::RISKS_TO_CONFIRM`); typo fixed. Controller: package filter 195, Coolecto Ai + writer 85,
  replay 0. Adversarial re-run 51/51 turns, all gates (campaigns 288881–83 offline, untouched fees; organiser 265789 / team
  429170). Left open: `fields()` memoisation; supplier template names in chip options unfenced (they are the chip labels).
- Full package suite after the review fixes: `Tests: 5193, Assertions: 17617, Skipped: 47.`. **Task-orchestrator plan complete.** Next: CondoEdge KB prompt (needs the owner), then Phase E remainder.

### Assistant picture (owner request, 2026-10-24)
- `ai.chat.assistant_avatar` (env `AI_CHAT_ASSISTANT_AVATAR`, default null): a public path or URL replaces the sparkle on the theme gradient
  in the header, bubbles, typing bubble and welcome screen (`HasAvatars::assistantImageHtml`, `asset()`, alt = identity name). Default
  unchanged. `AssistantAvatarImageTest` (3); filter Avatar|ChatIdentity|ChatComponentsBoot|MessagesQueryTest|ThemedToggle|Snapshot 68 green.

### E11. Feedback loop C6–C8 (agent batch, 2026-10-24)
- C6: `ConversationActions::recordFeedback` stamps `metadata.feedback_at`; a non-negative rating drops the comment. 👎 saves the rating and
  opens `Modals/FeedbackCommentModal` (optional, pre-filled) → `recordFeedbackComment()` (own 👎 answers only, trimmed, max 500, removed
  when empty). Comment + time in `AdminFeedbackTable` and `AdminTranscriptModal` (escaped).
- C7: `Services/Admin/EvalCase::fromTranscript()` → suite JSON (turns = user messages; expect skeleton `branch_in`, `retrieves_doc`,
  `audience_is`; no user id/name/email); transcript link copies it with `ConversationLimitBanner::copyResumeJs`. A real Coolecto
  conversation's output loads in ai-eval's `EvalSuiteLoader`.
- C8: `InsightsSql` audienceKey / noSources / regenerated; conversations filter "Answers": regenerated / no sources / slow
  (`ai.admin.slow_answer_ms`, default 20000); `ConversationInsights::audienceKpis()` cards on `AiConversationsAdminPage` only with
  `ai.audiences` (CondoEdge unchanged). Read-only checks on both MySQL hosts (ONLY_FULL_GROUP_BY on).
- Doc: `docs/plans/coolecto-feedback-loop.md` (+ the tasks triage note appended).
- Controller checks: filter (batch + Avatar) `OK (146 tests, 622 assertions)`; replay with C6–C8 + assistant picture **0 mismatches, 0 drift**.
- Open: 👎 click not tried in a browser (would write a real conversation); "no sources" share is high (task turns and small talk count);
  the admin grids need a host CSS rebuild (`npm run dev`) for `grid-cols-6/7/8`. Agent cost ~320k tokens, 26 min.

### E12. Owner test of campaign creation (2026-10-25)
- `AI_TASKS` was not set in Coolecto's `.env` (owner has since enabled it). Gap vs the plan: the task chip was attached on the welcome
  screen only; the plan also attaches it when the navigation pick offers `new_campaign`. Agent adding it minimally (navigation page
  `task` key + chip in `withNavigation`) and one answering-prompt line naming the user's startable tasks (owner asked Coco "je sais que tu
  as une tâche…" and it denied having one).
- Citation separators: with `show_file_references` off (and on), "[2], [3], [4]" was stripped to ", , ," in the prose.
  `FileCitationHandler::stripLinks` now removes a run of markers with its separators and the space before it. Test
  `test_stripping_a_run_of_citations_leaves_no_separators` (red: "types , ,  ,."); `FilePreviewIntegrationTest` expectation updated
  (it asserted the double spaces). Filter FileCitation|ContentLinkProcessor|FilePreviewIntegration|MessagesQuery|Snapshot|Citation:
  131 green. Rendering only (no prompt reads it).
- Task chip in answers (agent, ~192k tokens): new built-in `start_task` (`src/Services/Actions/BuiltIn/StartTaskAction.php`, internal,
  never listed in prompts; chip → the welcome chip's `startTask` handler, `startableBy` re-checked at render and on click); navigation
  page entries take an optional `task` key (`NavigationPages::offeredTo`; pick prompt/schema unchanged); `ActionAttacher::withNavigation`
  puts the task chip before the picked page's button. Coolecto `new_campaign` → `'task' => CreateCampaignTask::KEY`. Answer prompt:
  `ResponseCurrentUserContextSection` adds "TASKS THIS USER CAN START: …" (runs only on the button; never say it was done) when a task
  is startable; nothing otherwise. Tests: NavigationChipsTest +2, TaskChatUiTest +1, ResponseCurrentUserContextSectionTest +2 (red
  first); package filter 492 green. "Live task already open" check skipped (cannot happen in a live turn: collecting/reviewing turns go
  to the orchestrator; `start()` resumes an open task). Known: the button only comes with a navigation pick of `new_campaign`.
- Owner test: after reopening the name, "Pizza's" was asked again twice (audit: task 428 events 1860-1866, no `extracted`). Reproduced
  3/3 offline with the reopened draft; "Pizza", "Le BBQ d'été" extracted 4/4. Cause: the step searches help passages with the reply's
  words — "Pizza's" pulled the pizza-dinner FAQ and the model read the reply as about meals. Without passages: "Pizza's" 4/4, and
  non-answers ("c'est quoi un bon nom?", "je sais pas encore", "Pourquoi…") still 0/4. Prompt rules (text rule; "passages never decide
  what the MESSAGE means") changed nothing (0/4). Fix: `TaskStepCaller::passages()` returns none when every asked field is free text.
  Test `a_step_asking_only_free_text_searches_no_passage` (red: searched); package Task|Snapshot|NoDomain 168 green.
- Coolecto `CreateCampaignTaskTest::supplier_and_organisation_text_reaches_the_model_fenced` failed after the owner's commit (9738cc525,
  checked out with CRLF): a multi-line string literal in the assertion became "\r\n". Assertion now uses "\n". Coolecto tests/Feature/Ai:
  59 passed (473 assertions).
- CondoEdge replay (agent, `nav-task-chip`): 834 mismatches / 29 stage errors — expected since the CondoEdge KB wiring (audience role
  block in prompts, `help_articles` collection, docs on data turns); none of the new strings appear. The cassette must be re-recorded
  (owner approval) before replays guard package work again.

### F1. Next feature request (owner, 2026-10-25)
- Owner: "10/10" on the creation flow after the fixes. Asks: (1) after creating a campaign, Coco helps get it online; (2) a full set of
  confirmed tasks from the articles — participants, groups, contacts, editing campaign values "and more possible things (listed by you
  … investigate to not be biased just for the things I said)".
- Research agent started (read-only): `docs/plans/research/tasks-catalogue.md` (every write action from the 458 articles: write path,
  authorization, side effects, risk class, personal data, orchestrator fit, size, local usage counts; not-a-task list; ranked shortlist)
  and `docs/plans/research/campaign-go-live.md` (mandatory cards per type, what decides "online", what a go-live follow-up can do).
  Then: owner decisions → plan (writing-plans) → build.
- Research done (agent, ~353k tokens, 29 min): `research/tasks-catalogue.md` (454 write actions: 396 organiser, 58 supplier; 337 fit one
  draft → one confirmed write; 117 don't: multi-record 21, bulk 10, upload 10, payment/external 16, iterative 23, other 37; 38 touch
  minors' data; usage 12 months: 2,015 campaigns created, 68 % copies; 79,879 participants; 7,125 groups; 27,298 products) and
  `research/campaign-go-live.md` (online = every « Obligatoire » setup card done, on the next full page load; Inscriptions wait for
  « Mettre en ligne »; the bank account never blocks; CreateCampaignTask ticks dates + reason only; a follow-up can read
  `missingRequiredSteps()` and offer `?section=` chips).
- Controller verified two Coolecto security bugs: `RoleManagementModal::rules()` accepts any `role` (incl. `admin` → `isAdmin()` via
  `current_role`, HasTeamsTrait.php:55-58); `routes/web.php:62` `qr-code-check` has no middleware (marks tickets scanned, returns the
  buyer's e-mail; "TODO should be a signed route"). Other findings (not verified yet): Kompo `/_kompo` writes bypass route middleware —
  Members can reach fee/delete writes; ids from the browser loaded without team checks; `SupplierPaymentSettingsForm::afterSave` wipes
  `pay_fixed_fees` on client campaigns; Shopify callback can log a browser in as the supplier; CampaignSettingsWriter raffle gaps.
- Owner decisions (2026-10-25): next plan = setup set (copy a campaign for a new edition; change settings on a campaign; add/edit a
  product, ticket type or donation amount; groups or "no groups"; organisation info; e-mails setup none/templates) + invite a team member
  + the go-live help after creation. Participants left out (minors). Outward actions only with a ticked confirm row naming the
  recipient, never bulk, never to buyers/participants. Defaults stated to the owner: each task re-checks the page's role server-side;
  one task run per record; the confirm card warns when finishing the last required card puts the campaign online. Security: fix both
  confirmed bugs now, test first.
- OPENAI_MODEL (owner question, 2026-10-25): in Coolecto it only drives `app/Ai/VenuePlanReader.php` (Responses API, tools,
  `reasoning.effort` high/medium). Live check with that request shape: `gpt-4.1-nano-2025-04-14` (current `.env`) → "Unsupported
  parameter: 'reasoning.effort' is not supported with this model" (the plan reader cannot work today); `gpt-5.6-luna` → completed. The
  key also lists gpt-5.6-sol/terra, gpt-6-luna. Coco's roles name their own models in `config/ai.php` (unchanged).
- Agents running: Coolecto security fixes (role escalation, qr-code-check); VenuePlanReader refactor onto `AiServiceContract` (owner:
  reuse the contract, abstract the Responses-with-tools call, clean the noise; characterization tests first); tasks v2 plan
  (`docs/plans/2026-10-25-coolecto-tasks-v2.md`).
- Security fix 1 (agent, ~97k tokens): `RoleManagementModal` — role must be in `TeamRole::rolesFor($team)` (`:105`, owner/manager/
  editor/basic/scanner, +agent with child orgs; never admin) and the membership must belong to the current team (`authorizeBoot`,
  `:30`). `tests/Feature/Teams/RoleManagementModalTest.php` (3; 2 red first); controller re-run `tests/Feature/Teams`: 28 passed.
- Security fix 2 stopped by design: guest scanning is intended — the signed `guest.scan` page (`routes/web.php:220`, signed group
  `:202`) lets a logged-out phone scan and post to `qr-code-check`. Proposed fix that keeps it: store the campaign id in the session when
  the signed page opens; `QrCodeConfirmationModal` accepts only a logged-in user of the campaign's team or that session. Owner to confirm.
- Model check for the roles (owner asked about 5.5/5.6): chat-completions probes — all 5.x refuse `max_tokens` (need
  `max_completion_tokens`, which gpt-4o accepts too); gpt-5.6-luna and gpt-5.5 refuse `temperature: 0`; 5.x accept `reasoning_effort:
  none`, gpt-4o refuses it. Prices (OpenAI, per 1M in/out): luna 0.20/1.20, 5.4-nano 0.20/1.25, 4o-mini 0.15/0.60, 4.1-mini 0.40/1.60,
  5.4-mini 0.75/4.50, terra 2.00/12.00, 4o 2.50/10.00, 5.5 5.00/30.00. Package agent adding `max_completion_tokens`, per-role
  `temperature => null` and `reasoning_effort`; then an eval matrix on the 80-turn suite + task suites.
- Package GPT-5.x support (agent, ~113k tokens): `OpenAiLlmProvider` sends `max_completion_tokens` (callers keep the `max_tokens` option
  name); a role with `'temperature' => null` sends none (`LlmResolver::overrides()` keeps a null temperature in `forRole()`/`isolated()`);
  a role's `reasoning_effort` is passed through. Tests: `OpenAiRequestShapeTest` (4), `LlmResolverTest` +1, `ChatJsonSchemaTest` wire
  field; filter OpenAi|Llm|Role|Provider|Snapshot|NoDomain 367 OK (29 skipped). Deviation: an Anthropic role with temperature null
  gets the provider's 0.3 default. Replay `gpt5-wire`: 92 mismatches / 434 drifts — caused by the CondoEdge document-map backfill
  running (map data appears mid-replay: the reader's question_analysis call replaces the probe call); the cassette compares caller
  options, `max_completion_tokens`/`reasoning_effort` appear 0 times in the diff. Re-check after the backfill + re-record.
- VenuePlanReader refactor (agent, ~123k tokens): `AiServiceContract::respond(array $input, array $options = []): AiResponse` (new
  `app/Ai/AiResponse.php`: id, text, functionCalls, refused, incomplete), implemented in `ChatGptService` over HTTP (SDK 0.10.3 has no
  Responses API; same timeout/retry/error). Reader 380 → 350 lines (≈95 are the prompt + 15 tool schemas): no URL/Http/parsing left,
  `done()` and boolean flags removed, model from the binding. Tests: `tests/Feature/Ai/VenuePlanReaderTest.php` (7, characterization:
  green on the old code), `ChatGptServiceTest` +3 (red first); Ai + ChatGptService 89 passed. Differences: an `app('ai-model')` override
  would now apply; an empty API key fails at binding (job still marks the plan failed).
- Owner approved the ticket-scan guard (session-remembered signed campaign or a logged-in team member) → same security agent.
- Unlisted package roles fall back to listed ones (passage_selector, document_map_reader → query_understanding; document_scope →
  query_generation; AiServiceProvider.php:354-405), never to OPENAI_MODEL — switching OPENAI_MODEL only affects VenuePlanReader.
- Model matrix running (`scratchpad/model-matrix.sh`): all Coolecto suites (1–7 + tasks-personas/adversarial/limits) on the current roles
  (`*-m-base`), then every role on gpt-5.6-luna with `temperature => null`, `reasoning_effort => none` (`*-m-luna`); `config/ai.php`
  backed up and restored by a trap.
- Tasks v2 plan written (agent, ~327k tokens): `docs/plans/2026-10-25-coolecto-tasks-v2.md` (996 lines; batches A package — outward
  risk, outcome follow-up chips, edit-existing-record contract, several task chips; B Coolecto foundations — shared trait, onboarding
  steps → task/section, GoLiveFollowUp, CampaignSettingsWriter gaps; C settings/e-mails/groups; D copy/product; E organisation/invite;
  F articles, evals, rollout, review). Phase 0 answered by the owner the same day (all as proposed; see the plan's "Answered" line).
  Start of Batch A waits for: the Coolecto model matrix (it exercises the package live), the CondoEdge map backfill, and a re-record
  with replay 0.
- Security fix 2 done (owner-approved guard): `QrCodeScannerView.php:37-38` remembers the signed `guest.scan` campaign in session
  `scan_campaign_ids`; `QrCodeConfirmationModal::authorizeBoot()` (`:29-40`) allows that session or a campaign in the user's
  `currentTeamCampaigns()`, else 403. `tests/Feature/Ticketing/QrCodeCheckTest.php` (4; 2 red first). Ticketing + Teams: 55 passed.
  Visible: forged/anonymous scans refused; a member restricted to some campaigns can no longer scan the others. Open: revoking a
  guest scanner does not end an already-open session; the signed link has no expiry.

## 2026-10-25 — Model matrix: current roles vs gpt-5.6-luna everywhere
All 10 Coolecto suites (103 Q&A turns, 98 task turns), user 53, luna with `temperature null`, `reasoning_effort none`; config restored after.
- ai-eval fix first: `quotes_grounded` now reads ’ as ' and a trailing ellipsis as a cut quote (17 of luna's 23 failures were luna's curly apostrophes). Test added; ai-eval 146 green.
- Q&A gate failures after the fix: base 6 (3 latency > 30 s, 2... retrieval, 1 quote), luna 11 (4 retrieval, 6 quotes — 4 are English translations of French labels, 2 invented — 1 latency, 1 link section).
- Cost per 100 Q&A turns: base $1.77, luna $0.28. Mean latency 16.1 s vs 16.5 s, p90 20.4 vs 20.2.
- Answers read side by side: luna is shorter and names more real labels; one wrong-article answer (own-08, registrations instead of membership).
- Tasks: the daily assistant limit hit user 53 (6 base and 2 luna conversations never started), so task scores are not comparable as a whole. In the conversations both ran, luna looped twice on "start date?" after "C'est tout" (unacknowledged, donations-receipts) — the decline-ignoring that ruled out gpt-4o-mini. Keep gpt-4.1-mini for task_orchestrator.
- tasks-limits fails on both models (fixture: the 4th creation still started) — not model-related.
Recommendation: luna for query_understanding, query_generation, response_generation, follow_up_writer, navigation; keep task_orchestrator on gpt-4.1-mini. Awaiting the owner.

## 2026-10-25 — Tasks v2 Batch A (package A1–A4)
- A1 `outward` risk: `TaskField::RISKS` and `TaskSummary::RISKS_TO_CONFIRM` gain it; card badge `ai.tasks.risk-outward` (en/fr).
- A2 follow-ups: `TaskOutcome::withFollowUps()` (keeps `{url,label}` / `{task,label}` only; stored as `follow_ups`); the renderer puts them after
  the outcome link (`MessageBubbleRenderer::outcomeLinks()`); a task item renders `StartTaskAction::element()` only while
  `StartTaskAction::invocation()` passes.
- A3 `TaskEditsRecordInterface` (new): start prefills `initialValues()` (audited `extracted` + `prefilled: 1`); step prompt adds
  ` Currently: <value>.` (never pii); `summaryOf()` drops rows equal to the record (review + confirm); no change → `ai.tasks.nothing-to-change`,
  groups/declined cleared, offer again; card shows `before` as `ai.tasks.was`. Fixture `tests/Fixtures/Tasks/EditBookingTask.php`.
  New snapshot `task_step_edit.txt`; `task_step.txt` untouched (mtime 2026-10-24).
- A4 navigation `task` accepts a list (one chip per startable task, in order); `ai.tasks.welcome` (default `[]`) via new
  `WelcomeSuggestions::welcomeTasks()`.
- Tests added: 23 (TaskField 1, TaskOrchestrator 1, TaskOutcome 1, EditRecordTask 8, TaskChatUi 8, NavigationChips 3, PromptSnapshot 1).
  Filter `Task|Navigation|Welcome|MessageBubble|Snapshot|NoDomainContent`: OK 239. Full package suite: OK 5248 (47 skipped). Replay not run (controller).
- Deviations: (1) a group's other fields are prefilled when one of its fields gets a value (not on a bare acceptance, which would skip
  every question and land on "nothing to change"); (2) "changed" compares the draft to `currentValues() + initialValues()`, so the
  bound-record field (e.g. `campaign_id`) is not a change but its row still shows; (3) `WelcomeSuggestions::tasks()` unchanged (it also
  feeds the answer prompt's TASKS line), the welcome list is a new `welcomeTasks()`; (4) `ActionAttacher::dedupe()` no longer collapses
  two `start_task` chips into one (it collapsed every generic action by key; links still are).
- Batch A controller check (2026-10-25): filter 239 green re-run; `task_step.txt` unchanged (dated 10-24). `ai.tasks.acknowledge-first`
  reworded for any marked row (en "First tick each item marked on the summary.", fr « Cochez d'abord chaque élément signalé dans le
  résumé. »). First replay `tasks-v2-a`: 2 mismatches = the 60 s "any map stored?" probe firing on another turn than recorded (the
  replay ran slower). ai-eval `Cassette::store` now accepts a store call whose call + arguments were recorded on any turn
  (test `a_cached_probe_that_expires_on_another_turn_than_recorded_is_not_a_mismatch`; ai-eval 147 green). Replay `tasks-v2-a2`:
  **0 mismatches, 0 drift**. Batch A gate passed.

## 2026-10-25 — Tasks v2 Batch B (Coolecto B1–B5)
- B1 `app/Ai/Tasks/OnCurrentCampaign.php` (trait): `inPilot` (moved from CreateCampaignTask, which uses the trait), `campaignStartable($user, $gate)`,
  `campaignField`, `initialValues`, `boundCampaign` (team-scoped `findOrFail`), `validateBinding` (`campaign.coco-campaign-switched`),
  `liveRows` (`_live` / `_goes_live`, ticked `outward`, label `campaign.coco-the-campaign`), `followUpOutcome`.
- B2 `CampaignOnboardingStep::assistantTask()` / `assistantLink()` (`['route','section','gate']`, default dashboard/anyone); overrides on
  CampaignInfo, Welcome (only when it asks the reason), CampaignReason, TeamInfo (`org.manage`, gate manager), Products, Participants,
  Communications, Dates. Page links use gate `currentCampaign` (as navigation pages do).
- B3 `app/Ai/Tasks/GoLiveFollowUp.php`: message from `missingRequiredSteps()` in band order (`coco-steps-left` / `coco-all-steps-done`,
  Inscriptions + `coco-press-go-live`); ≤4 chips, task chip when `StartTaskAction::invocation` passes else the section link via
  `switch.campaign?redirect_to=...?section=`; dashboard links dropped (the outcome link already opens it); dedup by target; titles made
  plain text (team wording comes HTML-escaped). CreateCampaignTask returns `followUpOutcome()`.
- B4 CampaignSettingsWriter: unknown fields refused (`error.feature-not-available`) instead of dropped; groups `name`, `profitShare`, `pause`;
  `contact_info` (+ `no_contact_info` as the card's beforeSave), `donation_without_purchase`; refusal on raffle dates
  (`campaign.coco-raffle-dates-on-their-card`) and on a locked raffle (`campaign.this-info-are-locked`); public `offers($group)` and
  `current($field)`; `StepCampaignName::nameRules()`, `StepCampaignProfitShare::profitShareRules()`.
- B5 `config/ai.php` `tasks.welcome => [create_campaign]`; no page task added (no new task class exists yet).
- Tests added: OnCurrentCampaignTest 6, OnboardingStepAssistantTest 4, GoLiveFollowUpTest 8, CreateCampaignTaskTest +1, CampaignSettingsWriterTest
  +11 (one old test replaced: unknown fields are now refused), NavigationSectionsConfigTest +1. Gate: Feature/Ai 85 passed (golden included),
  Feature/Campaigns 361 passed, Feature/Onboarding 91 passed, Unit/Ai 15 passed. Contacts golden shown failing on injected drift.
- Headless check done without LLM (scripted orchestrator in tinker, organiser 264617): outcome bubble « Votre campagne « Dons test Coco B3 » est
  créée. Il reste 3 étapes obligatoires … : Bienvenue, Compléter ma campagne, Vos courriels. » + chips Bienvenue / Compléter ma campagne; the
  chip switched to the campaign and scrolled to « Campaign reason ». Screenshots `scratchpad/b3-chat.png`, `b3-section.png`. Rows deleted
  (campaign through `Campaign::delete()`, onboarding, tracked event, conversation/task/events), current campaign restored.
- Deviations: `campaignField()` returns null without a current-team campaign (the package refuses an enum without options);
  `coco-campaign-created` shortened to « Votre campagne « :name » est créée. » (the follow-up sentence says what is left);
  `GoLiveFollowUp` returns no message/chips for an online campaign; ProductsStep names `edit_product` for every kind but form/Inscriptions —
  the kind gate (§D2) belongs to EditProductTask::startableBy.
- Batch B controller check (2026-10-25): re-ran `tests/Feature/Ai` 85, `tests/Feature/Onboarding` 91, `tests/Unit/Ai` 15,
  `tests/Feature/Campaigns` 361 — all passed. Screenshot `scratchpad/b3-chat.png` read: created message + « Bienvenue » /
  « Compléter ma campagne » chips beside the dashboard link. Owner questions pending: shortened « campagne créée » text; step
  titles as chip labels. Batch B gate passed.

## 2026-10-25 — Tasks v2 Batch C (Coolecto C1–C3)
- Shared: the settings mapping moved from CreateCampaignTask into `OnCurrentCampaign` (`settingsGroups()` / `settingsWriter()` / `display()` /
  `firstErrors()` / `totalGoal()`): only a group's fields, only values that differ from the record, a changed date keeps the campaign's time of day.
  Create tests + create golden unchanged and green.
- C1 `UpdateCampaignSettingsTask` (`update_campaign_settings`, gate campaignCreator, limits 20/40): 11 groups, each present when
  `CampaignSettingsWriter::offers()`; `currentValues()` typed per field; rows campaign + changed rows with `before` + `_live`/`_goes_live`
  (`campaign_info` only on non-P2P/non-raffle start date; reason → `campaign_reason`, `welcome` when the welcome asks it); `validate()` = writer
  errors + the save() date guards on a copy. Golden per card (11, Kompo card vs task), shown failing on injected drift (dates time, reason escaping).
- C2 `SetUpEmailsTask` (`set_up_emails`, campaignCreator, 10/20): startable while the `communications` card applies and is not done;
  `emails_choice` none|templates (templates only with a start date); `_emails` outward row says templates are created **switched on**
  (column default 1). `Campaign::setNoCommunications()` extracted (modal calls it). `generateDefaultCommunications` queues the rewrite through
  `DB::afterCommit` (not `->afterCommit()`: the test harness `Bus::fake`s that job and the fake ignores the flag; the rollback test fails without it).
- C3 `SetUpGroupsTask` (`set_up_groups`, editorOnCampaign, 10/20): without-list campaigns, not P2P/Inscriptions/sub-campaign with main participants,
  card not done. `uses_groups` + `group_names` asked together; names alone mean yes. `Campaign::setWithoutGroups()` / `startUsingGroups()` extracted
  (OnboardingGroupsChoice calls them). Goldens vs bulk modal and vs the choice.
- B5: definitions + page tasks `campaign_settings`, `campaign_communications`, `campaign_participants`.
- Tests: UpdateCampaignSettingsTaskTest 24, SetUpEmailsTaskTest 9, SetUpGroupsTaskTest 11. Gate: Feature/Ai 129, Unit/Ai 15, Feature/Onboarding 91,
  Feature/Campaigns 361 — all passed.
- E2E (real model gpt-4.1-mini, orchestrator driven in tinker, organiser 264617, throw-away campaigns, queue discarded, mail array):
  settings "repousse la fin d'une semaine et affiche un objectif de 5 000 $" → end 2027-04-07 23:59, start kept 08:00, goal 5000 total,
  rate/pay_fixed_fees untouched (4 calls, 11.4k tokens; one extra turn: Coco re-offers the remaining groups); e-mails "utilise les modèles" →
  `_emails` ticked, 12 communications enabled (2 calls, 2.5k); groups run 1 lost the names (the names field was not listed with the yes/no) →
  fixed test-first, run 3 "nos groupes sont 501, 502 et 503" → 3 groups in one turn (2 calls, 2.7k). All rows deleted, current campaign restored.
- Not changed: the auction end-reminder job dispatched by dates needs its `jobs` row id (cannot be after-commit); on the database queue it rolls back with the transaction.
- Batch C controller check (2026-10-25): re-ran `tests/Feature/Ai` 129, `tests/Unit/Ai` 15, `tests/Feature/Onboarding` 91,
  `tests/Feature/Campaigns` 361 — all passed. Batch C gate passed. Owner questions pending: rename/reason/pause message keeps only
  the campaign's language; "Contact email" label now also covers the phone; the extra "other groups?" turn after a single change.

## 2026-10-25 — Tasks v2 Batch D (Coolecto D1–D2)
- D1 `CopyCampaignTask` (`copy_campaign`, campaignCreator + `TeamLockGuard` on a preview, limits 3/10): sources = the team's own campaigns
  (`getTeamCampaigns`), no main / sub-survey / supplier-left, newest 30, label « name — year »; `elements` list (products, polls, communications
  as the source offers them; never participants), `name` (default « <source> (copie) »), optional `dates` group through the writer (past end refused).
  `Campaign::copyForNewEdition($elements, $main, $alsoProductsOf)` extracted from `CopyCampaignForm::handle` (form calls it; supplier hand-off
  stays in the form). A new name / dates go through `CampaignSettingsWriter`; `_emails_enabled` outward row when enabled e-mails are copied;
  tracked `campaign_copied_by_ai`; `followUpOutcome()` on the copy.
- D2 `EditProductTask` (`edit_product`, campaignCreator, 20/40, `TaskEditsRecordInterface`): `product_id` = new + the campaign's 60 newest top-level
  products; name/price required for new (grouped on an edit), groups visibility, description, stock, limits, fees (tickets). Not startable on seated,
  raffle/locked, auction, membership, Inscriptions, form, main, multi-date meals. `currentValues()` includes `product_id`, so picking a product
  changes nothing by itself ("nothing to change" works). `ProductWriter` (new) + `Product::prepareFor($campaign, $input)` (beforeSave guards,
  form calls it) + `CampaignProductForm::productRules()` (static). `errors()` = rules + a write rolled back (guards that read the DB run too).
- Tests: CopyCampaignTaskTest 10, EditProductTaskTest 12, CopyForNewEditionGoldenTest 2 (sale; form + polls), ProductWriterGoldenTest 6 (new own,
  donation amount, ticket 10 % fee, single-date ticket linked to its date, price edit, supplier price in range; rows compared with assertSame).
  Goldens shown failing on injected drift (copy without e-mails, goal/show_goal; writer without toggle normalisation, without the date link).
  Gate: Feature/Ai 151, Unit/Ai 15, Feature/Campaigns 369, Feature/Onboarding 91, Feature/Market 87 — all passed.
- B5: definitions + `campaign_products.task` edit_product, `new_campaign.task` [create, copy], `tasks.welcome` [create, copy]
  (NavigationSectionsConfigTest updated to the plan's welcome list).
- E2E (gpt-4.1-mini, tinker, organiser 264617, mail array, queue discarded): copy « Copie la vente du printemps dernier pour cet automne, avec les
  produits et les courriels » → source picked, products + e-mails; asked the name (did not propose the default), then dates → copy 300677 offline,
  15 Sep–31 Oct 23:59, 2 products, 16 e-mails enabled, 0 participants, `_emails_enabled` ticked (3 turns, 5 calls, 6.9k tokens). Product: « Ajoute des
  tartes aux pommes à 15 $ » → new product in one turn + « c'est tout » (2 turns, 4 calls, 6.7k); « Le pot de miel passe à 12 $ » → one row
  12,00 $ was 10,00 $ (2 turns, 3 calls, 5.3k). All rows deleted (campaigns, products, communications, onboardings, conversation hard-deleted),
  current campaign 287172 restored; logs moved to scratchpad/d/logs. Tracked events are not written from tinker (no visitor identity); tested.
- Deviations: copy elements leave out `venue` (the form offers it but `handle` ignores it: the seat plan always follows); `copyForNewEdition` has a
  third parameter for the form's extra supplier campaigns; a new single-date ticket is linked to that date (`campaignDates()->sync`, as the form's
  date list starts — never `handleLinkedDates`); the writer sets the form's shown nullable toggles to 0 as the form does; dry runs consume product ids.
- Batch D controller check (2026-10-25): re-ran `tests/Feature/Ai` 151, `tests/Unit/Ai` 15, `tests/Feature/Onboarding` 91,
  `tests/Feature/Campaigns` 369, `tests/Feature/Market` 87 — all passed. Batch D gate passed. Owner questions pending: the copy form's
  no-op "Venue" box; copy name required (one turn); fees in the first message must be repeated at the fees group.

## 2026-10-25 — Tasks v2 Batch E (Coolecto E1–E2)
- E1 `CompleteOrganisationTask` (`complete_organisation`, gate `manager` + pilot + not suspended + not supplier mode, limits 3/5, `TaskEditsRecordInterface`
  with `initialValues() = []`): fields name, org_type_id, business_type, primary_contact (pii), phone (pii, `App\Rules\PhoneNumber`, written as the
  form's input posts it, `+1…`). Name/business type/e-mail are required while the team lacks them (`teamInfoComplete`), else groups `identity` / `contact`.
  Logo, address (taxes), language, money format, school board, outgoing e-mail are not fields. Card rows = changed values with `before`; name row says
  "shown on all your campaigns and e-mails". Write: `forceFill` + `save` + `Team::recordInfoChanges($team->getChanges())` (extracted from
  `TeamInfoForm::completed`, form calls it); validate = `TeamInfoForm::teamInfoRules()` (static, form's `rules()` returns it) on the team as saved.
  Outcome `followUpOutcome()` on the current campaign, else plain.
- E2 `InviteTeamMemberTask` (`invite_team_member`, gate `addTeamMember` policy (chief admin) + pilot + not suspended, re-checked in `execute`, limits 3/5):
  `email` (pii, `email` rule: one address), `role` = `TeamRole::rolesFor($team)` ∩ manager/editor/member/scanner. Validate =
  `TeamInvitationForm::invitationValidator()` (new public static wrapping the now public static `invitationRules()` / `ensureUserIsNotAlreadyOnTeam()`;
  the form's `validate()` calls it) + Coco's role list; `execute` aborts 403 on any other role. Ticked `_invitation` outward row, same neutral text with or
  without an existing account (Q5 a). Write `Team::inviteUser($email, $role, false, null)`.
- Mail after commit: `TeamInvitation::sendByEmail` and `Change::addWithMessage` (owner change notice) now send inside `DB::afterCommit` (immediate
  outside a transaction, so the forms behave as before). Both were needed for the rollback test (shown failing without each).
- B5: definitions + `my_organisation.task` [complete_organisation, invite_team_member]; `OnboardingStepAssistantTest` now checks step tasks against
  the registered definitions (shown failing before the config change).
- Tests: CompleteOrganisationTaskTest 10, InviteTeamMemberTaskTest 10 (incl. role outside `rolesFor`/owner/agent refused at field, validate and execute;
  another team never reached; exactly one `TeamInvitationMail`, none on rollback; goldens). Goldens shown failing on injected drift (raw phone; `as_owner`).
  Gate: Feature/Ai 171, Unit/Ai 15, Feature/Teams 28, Feature/Onboarding 91, Feature/Campaigns 369 — all passed.
- E2E (gpt-4.1-mini via the orchestrator in tinker, organiser 264617, team 426841 snapshotted; `mail.default=array` + `Mail::fake()` + `Notification::fake()`
  checked in every step, queue discarded): « Complète les infos de notre organisme : nous sommes un organisme à but non lucratif, le courriel de contact est
  info@example.test » → asked type (missing), then phone; card showed only type, e-mail (was …) and phone (+15145550199), not the unchanged name/constitution;
  `changes` row as the form's (3 turns, 6 calls, 7.9k tokens). « Invite marie.coco-e2e@example.test comme éditrice » → review in one turn, `_invitation`
  ticked, one invitation row (editor, as_owner 0), one TeamInvitationMail captured by the fake (1 turn, 2 calls, 2.8k). Cleanup: team row restored
  byte-identical, changes/invitation/conversations/tasks deleted, change-notice cache flag cleared, current campaign 287172. Logs scratchpad/e2e_e/logs.
- Deviations: `invitationValidator()` added so the form's custom "already invited" message is shared; `Change::addWithMessage` mail deferred too (same
  rollback bug, hit by E1); `TeamInfoForm::afterSave` tax sync not called by the task (it derives from the address, which the task never changes).
- Batch E controller check (2026-10-25): re-ran `tests/Feature/Ai` 171, `tests/Unit/Ai` 15, `tests/Feature/Teams` 28,
  `tests/Feature/Onboarding` 91, `tests/Feature/Campaigns` 369 — all passed. Batch E gate passed. Owner questions pending: owner
  change notice (≤ 1 per 4 h) kept for Coco changes; the invitation form still offers role "owner"; Coco asks for a missing
  phone/type the user did not mention once the group is accepted.
- 2026-10-25: package `ConversationalReplyComposer` now probes the host documents before the conversational call (CondoEdge KB
  fix; see the CondoEdge log). Full package suite 5252 green.
- Model matrix 2 started (owner: "I can't go back in quality… run it"): base vs sol (gpt-5.6-sol, reasoning medium, for
  query_generation + response_generation + task_orchestrator) vs sol+luna (luna for QU, follow-ups, navigation). Task
  conversations one by one, user 53's eval-created done tasks aged 2 days before each (owner-approved). Script
  `scratchpad/model-matrix2.sh`, reports `storage/app/eval/coolecto/reports/m2/`.
- Matrix 2 results (2026-10-25). Q&A suites 1–7 (103 turns): gate failures base 4, sol 12, sol+luna 10; mean 13.1 / 15.7 /
  17.6 s; $1.64 / $2.79 / $2.79 per 100 turns. Sol's extra quote failures are mostly checker artefacts (comma inside curly quotes
  "Facultatif,"; base writes the same translated labels in straight quotes, which the gate skips) or marked examples; one real
  failure: cre-08 empty answer (composition retried on citation_missing, ended empty, 1.3k output tokens, not the cap).
  Task conversations: the first pass ran into the daily limit (the task suites run as eval users `coco-eval-*`, not 53: the
  ageing missed them). Rerun (`scratchpad/model-matrix2-tasks.sh`, ages the conversation's own eval user, reports `m2t/`):
  base 12/12 and raffle 3/3; sol and sol+luna: raffle 0/3 (« billets de tirage » quietly set campaign_type 3 = Vente de
  billets) and fees-zero fails (sol loops on "objectif?" after « C'est tout »; sol+luna cancels). Decision: task_orchestrator
  stays gpt-4.1-mini; luna on the cheap roles brings nothing.
- Regression found and fixed: the composer's document probe (above) routed Coolecto talk-02/03 (« C'est qui toi? », « Merci,
  t'es vraiment utile! ») to knowledge_query in every setup: QU's clarified readings (« Expression de gratitude pour l'aide
  fournie. ») score 0.45–0.52 on Coolecto's articles while some how-to rewordings score 0.42 — no threshold separates them.
  Coolecto's own verdict (4o) already routed every how-to turn right before the probe. Replaced: probe removed; the composer
  rides an optional role `conversational_reply` (falls back to response_generation). OpenAI provider: a role with a
  reasoning effort other than none gets `REASONING_ALLOWANCE` (8000) added to max_completion_tokens (the cap counts hidden
  reasoning; the composer's 300-token budget could be spent thinking). ai-eval `quotes_grounded`: trailing , ; : inside the
  quote is the sentence's. Tests red first; package 5251 green, ai-eval 149 green.
- Matrix 3 running: base vs sol (query_generation + response_generation only) on the 13 differing conversations, 3 runs each
  (`scratchpad/model-matrix3.sh`, reports `m3/`).

## 2026-10-26 — Owner answers to the pending Coolecto questions
1 keep the short « campagne créée » text; 2 keep step titles as chip labels; 3 FIX: fees given early must not be asked again at
the fees group; 4 FIX: a rename/reason/pause change must not drop the other language's text; 5 RENAME "Contact email" → "Contact";
6 DROP the "other groups?" offer after a single change; 7 REMOVE the copy form's no-op Venue box; 8 keep asking the copy's name
(the user decides); 9 keep the owner change notice; 10 REMOVE role "owner" from the invitation form; 11 keep asking missing required
fields; 12 FIX: Form wizard must respect the "closed group?" answer; 13 no change: utils' error pages are a controlled response.
Owner guidance for every fix: fix the cause in general (better responses overall), never a one-case patch; nothing host-specific
in the ai package; clarifications only where they truly help.
- 2026-10-26: Coolecto security fixes done (plan `2026-10-26-coolecto-security-fixes.md`): all 18 verified and fixed; 88 tests in
  `tests/Feature/Access/` (70 red on a pristine copy, 18 "right person still gets in"); controller re-run 88 passed. Full suite
  2213 passed / 8 failed (all pre-existing or DB deadlocks from parallel runs, pass alone). Found beyond the plan: any logged-in user
  could scan any campaign's tickets via `/qr-scanner?flag=guest&campaign_id=X` (Kompo fills routed-page props from the query
  string) — fixed. Left open: `DrawWinners` crashes on a missing `draws.drawing_id` column; registrations menu link still shown to
  basic members; other routed pages trusting `prop()` not audited; `CampaignOrderPreview` has no own check.
- 2026-10-26: Owner-answer batch done (items 3,4,5,6,7,10,12 + DrawWinners, registrations link, routed-page audit B8 with 27 tests,
  CampaignOrderPreview/RecipientPreview own checks, printable restrictions, Monday token → `MONDAY_API_TOKEN`). Controller re-run:
  Access/Unit-Ai/Teams/Draw/Onboarding/Monday 222 passed; non-AI Unit 321 passed. Open, not from this batch: `/docs` (LaRecipe from
  the package, public, `auth => false`) fails AllGetRoutesAreOkTest; 2 dev-merge failures (CommunicationPlanFirstEmail, Vonage).
  2 edited help articles re-indexed (464 skipped).
- 2026-10-26: Data plan Phases 2–3 (Tasks 2–5) done: figures match the app on 7 fixtures (26 figures), full ingest 61 min
  (940k nodes, 1.9M rels), nightly figures 18 min. Production must set `AI_AUTO_SYNC_ENABLED=false` until its Neo4j exists.
- 2026-10-26: Task 6 gate: an asker whose audience lists no data entity (`data_entities: []`) is docs-only even with
  query_generation on (AiManager::queryGenerationEnabled($user), also for the file-search skip). Test red → green; package 5300 green.
- 2026-10-26: Owner approved: start a task by replying to the answer that offers it + side questions mid-task answered normally
  (plan `2026-10-26-task-offer-and-side-questions.md`); response time is a requirement (no extra call on normal/offer turns).
  Agent building it; Phases 4–5 (calculations, fixtures, gates, data suite) given to a second agent. Live runs after both.
- 2026-10-26: Task offer + side questions built (TaskOfferInterface, TaskOffer `task_offer` role, step `about_task`); all 8
  Coolecto tasks have EN/FR offer sentences; eval suite `storage/app/eval/coolecto/tasks-offer.json` (not run yet). Controller
  fix: the offer line follows the question's language (`metadata.question_language` set by AiManager); buttons keep the app's
  language (they name screens). Package 5319 green; Coolecto Feature/Ai + Unit/Ai 240 green.
- 2026-10-26/27: Owner: D6 "find the right answer", P2P flag yes, profit % stored yes. Done (tests red→green where noted):
  D6 = a main campaign is measured against its own goal (15 of 70 mains set one; the app ignored it: 771 showed 0 %), else its
  sub-campaigns' goals: `Campaign::goalTarget()/goalCounted()`, `pct_goal` over them (MainCampaignGoalTest 3 red→green); graph
  `goal_target`, `goal_counted`, `profit_pct` (app's getProfitPct on raw_profit), Participant `is_active` (Campaign::activeRecipients,
  batched per campaign); calculations use them; suite rebuilt (5171 → 6 %, 3604 without sale → 29). Also: names written as text
  (GraphNode::toGraphArray), chat opened on the current campaign (App\Ai\ChatPageContext), ai-eval `max_rows` gate on 7 refusal turns.
  BLOCKED on memory: Claude Code killed the Campaign/Product re-ingest, the document map backfill and a test run (low system memory).
  To do once memory allows: Coolecto tests (Unit/Ai, Feature/Ai, Feature/Campaigns, Goals…), re-ingest Campaign + Product +
  Participant, `ai:documents:map --backfill`, then live run `scratchpad/live-run-1026.sh`.
- 2026-10-27: Memory freed (owner "go"): Coolecto tests green, re-ingest done (Campaign 4116, Product 33189, Participant 169509),
  document map backfill done (466 files; 1 file without a usable map keeps the plain search). Live run `reports/live-1026/`:
  8-data ×3 = 57/55/? ok of 80 (27 turns fail at least once, most every time); tasks-offer 4/7; tasks-personas 44/46;
  tasks-adversarial all ok; tasks-limits 0/1; help suites 1–7: 7 failed checks (2 latency over 30 s, 2 no_cypher, 3 retrieval).
- 2026-10-27 causes found and fixed (tests red first; package 5327 green, ai-eval 161 green):
  - `coolecto_context_index` did not exist in Qdrant (entity selection fell back to keywords: "mises" → Transfer). Built with
    `php artisan ai:index-context` (112 points; Coolecto-prefixed only). **Production rollout step.** Alone it changed no verdict.
  - Task offer never fired on "How do I invite a member?": QU reads it as navigation `own_team`, which replaces the page buttons
    that carry the task chips. Fix: a built-in identity link (my_team / my_profile) gets the start chips of the tasks named by the
    navigation page that opens the same route (`IdentityLinkAction::route()`, `NavigationPages::tasksOn()`,
    `ActionAttacher::pageTaskChips()`). No extra call.
  - A role refused the data (editor, ticket scanner) got "I encountered an issue, try again": now `ai.error.no_access` (branch
    `access_denied`), and `ai.error.data_part_denied` under a documents answer. ai-eval: `access_denied` is an answer, not a
    stage-error. A refusal was already never retried.
  - The query writer declined (needs_query false, "not recorded in the schema") on data questions a domain formula matched
    (statement total, year over year, platform fees): reproducible 2/2 on gpt-4o JSON mode. Fix: one redraft naming the matched
    formula, only on that decline path (replay: 3/3 then query, twice). Remaining declines (no formula matched) are now answered
    "the data I can read does not hold this" (`not_in_data`), never "I checked transfers… none matched" (no lookup ran).
  - A whole number stored as a float showed as "116,00": the answer card now writes "116" (money keeps its cents).
  - Suite: goal-left expected the goal too; its standard asks only the amount left → template figure trimmed, suite rebuilt.
- Open (not fixed, measured): "notre profit / notre % / combien on a vendu" on a campaign dashboard is answered for every campaign
  of the organisation (COLLECTIVE POSSESSIVE rule wins over the page context; a one-line prompt change had no effect on replay);
  "profit net" picks the account statement's figure; draw tickets answered per product with the row count read as tickets;
  the record card shows helper columns ("Is P2p: 0"); tickets-present goes to the help articles.
- 2026-10-27 (later): more general fixes, tests red first (package 5336 green, ai-eval 161, Coolecto Unit/Ai 66):
  - Conversation summary cut text by bytes: a 🏈 in a campaign name broke UTF-8 and OpenAI refused the whole request on every
    follow-up ("Pis le panier moyen?"). `mb_strcut` in ConversationContextSection, QueryUnderstandingService (descriptions),
    ExportResponseComposer (file title).
  - `AggregateOrderBy`: an aggregating RETURN ordered by an unreturned property (ORDER BY c.start_date) is projected by code
    (only when the record's id is returned, single RETURN); the model kept writing it, even in its repair.
  - Owner decision "ask the user": on a record's page, a per-record formula matched + "our" + a query over every record of the
    page's kind → Coco asks "« record » only, or your whole organization?" with one button per reading
    (`AiManager::pageScopeClarification`, lang `ai.clarify.page-scope*`); the reply to it is answered. CondoEdge has no
    per-record formula (unaffected). Suite: 6 conversations get a "this campaign only" turn carrying the figures.
  - The team link's task chips only on a navigation ask (a data answer reading the team had offered "invite a member").
  - Coolecto config: participants_without_sale rewritten without EXISTS{} (+ test: every formula passes the scope analyzer);
    tax receipts exclude canceled + total donation_amount; deposit/receipt dates matched by day; period questions → period
    sales by payment date; French words in auction/membership/without-sale descriptions; yearly figure never a month.
  - Owner: task steps stay on gpt-4.1-mini. tasks-limits needs 3 creations in the last 24 h (state, not code; the limit is
    unit-tested).
- 2026-10-27 (end): the pick turn added to 33 page-context "our" conversations (115 turns). Final data run 98/115 ok (+5 whose
  only failure was min_rows on a restated answer; relaxed). Structural validator: AND inside a RETURN/WITH expression is no
  longer refused (only right after MATCH/MERGE/CREATE). ai-eval cassette ignores CRLF/LF (the prompt files lost their CRLF):
  CondoEdge gold replay 0 mismatches, 0 drift, 39/39. Report: `docs/eval/2026-10-27-coolecto-data-v1.md` (English, with
  the 115 questions in English and each turn's result).
- 2026-10-27 (owner test in the browser): "Can you help me creating a campaign in my team?" offered "invite a member" /
  "complete my organisation" — QU read it as navigation own_team and my afternoon fix hung the team page's tasks on the team
  link. Replaced (tests red first): the team link carries no task; page buttons run for every document-grounded answer,
  navigation ask or not, so the page the answer is about brings its tasks; a page the identity link already opens gets only
  its task chips (`ActionAttacher::identityRoutes`). Live: picks « Nouvelle campagne » → create / copy tasks. tasks-offer
  7/7 (invite offer kept); package 5338 green; CondoEdge gold replay 0 mismatches (it has no navigation pages).
  Also: the browser chat reads `.env` — the evals set AI_QUERY_GENERATION_ENABLED / AI_TASKS inline.
- 2026-10-27 (owner): "I want to collect money from pizza selling…" → Coco recommended « Vente de produits »; should be
  « Repas bénéfice ». Cause: the step sees only type names + "recommend one from the goal"; the article's line ("vous vendez
  des produits" / "vous servez un repas… dîners à l'école") leaves pizza open. Fixed in content, not code: article
  `choisir-le-type-de-campagne` (meal ordered for a date vs food sold as a product, with examples; re-indexed) and the
  CreateCampaignTask type hint ("prepared food eaten as a meal is a benefit meal unless delivered as a product; packaged
  food is a product sale; give it as your own advice"). Tried and dropped: "ask when two types fit" (gpt-4.1-mini then asked
  "product or meal?" for chocolate). Live, 4 starts: pizza EN/FR → Repas bénéfice, chocolate → Vente de produits, spaghetti
  take-out → Repas bénéfice. Personas 43/46 (school-products = known model issue; task-registrations = daily limit reached
  by today's runs). Left: the step sometimes says « selon les instructions ».
