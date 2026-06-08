# Smart Agent Implementation Plan — File/Team Retrieval + Implicit-Rule Interpretation

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (or superpowers:subagent-driven-development) to implement this plan task-by-task. **DO NOT COMMIT** (the user manages git). Companion audit: `docs/plans/2026-06-07-ai-pipeline-audit-and-smart-agent-design.md`.

**Goal:** Make `condoedge/ai` reliably (a) find a user's **local docs and team-scoped files** with correct authorization, and (b) interpret **rule/bylaw questions asked without the word "rules"**, grounded in the building's own documents — without hardcoded semantic word lists.

**Architecture:** Keep the existing `AiManager::answerQuestion` pipeline. Fix the file-retrieval plumbing and authorization in the package; reproduce CondoEdge's real `FilePolicy` in an `App\Models\File::scopeAccessibleBy` override; extend Query Understanding to emit a confidence + `rule_intent` from the *same* semantic LLM call; add a union-scoped **rules-corpus** routing step; and add grounding/abstention so empty/insufficient context never produces a fabricated permission.

**Tech Stack:** PHP 8.1, Laravel 10, Orchestra Testbench + PHPUnit 10 (package), Neo4j (Bolt), Qdrant, OpenAI/Anthropic. Two repos: `kompo/ai` (package) and `kompo/CondoEdge` (consumer).

**Decisions baked in:** residents+staff use the chat (file leak is a hard blocker) · build smart-agent + its P0 deps · formalize `Instruction` + `File` type 20 (+ CCQ) as the rules corpus · edits allowed in both repos.

**Cross-repo note:** a separate `condoedge/chatbot` package also embeds files (`ChatbotDocumentEmbedding`). This plan improves `condoedge/ai` only; see Open Item O1.

---

## Conventions

- Package root: `C:/Users/jkend/Documents/Projects/kompo/ai`. App root: `C:/Users/jkend/Documents/Projects/kompo/CondoEdge`.
- Package tests: `vendor/bin/phpunit --filter <name>` (Testbench). App tests: `php artisan test --filter <name>` (needs app DB; if unavailable, fall back to `php -l` + a tinker assertion, noted per task).
- TDD: write failing test → run (red) → minimal impl → run (green). One assertion of behavior per step.
- No hardcoded semantic/keyword lists for intent (project rule). Use the LLM/embeddings.

---

## Phase 0 — Harness & safety net

### Task 0.1: Confirm the package test harness runs
**Step 1:** Run `composer test-unit` (or `vendor/bin/phpunit --testsuite Unit`) in `kompo/ai`.
**Expected:** Suite executes (some may fail/skip on missing Neo4j/Qdrant — that's fine). Capture the green baseline count.
**Step 2:** Confirm a representative existing test runs: `vendor/bin/phpunit --filter FileContext`.

### Task 0.2: Confirm the app test harness (best-effort)
**Step 1:** In `CondoEdge`, run `php artisan test --filter File` (or `vendor/bin/phpunit --filter File`).
**Expected:** Either runs against a test DB, or documents that the app DB is unavailable. If unavailable, app-side tasks use `php -l` + a `php artisan tinker` assertion instead of PHPUnit; note this in each app task.

---

## Phase A — File-access authorization (stops SEC‑1 leak; makes team files correct)

> Today `accessibleBy` = `user_id OR team_id` only (`FileAccessScopePlugin.php:42-50`), ignoring `public`/role/union. Residents can have other people's confidential files quoted back. We reproduce the real `FilePolicy`/`scopeVisibility`/`getLibrary` semantics with the **passed `$user`** (not `auth()`), so it is correct in CLI/queue/threaded contexts too.

### Task A1 (package): `FileAccessResolver` honors the configured model + fails closed
**Files:**
- Modify: `src/Services/Context/FileAccessResolver.php` (`getAccessibleFileIds`, `getFallbackAccessibleFileIds`)
- Test: `tests/Unit/Services/Context/FileAccessResolverModelResolutionTest.php`

**Step 1 — failing test:** Bind `config(['ai.file_context.file_model' => FakeAccessibleFile::class])` where `FakeAccessibleFile` has a `scopeAccessibleBy` returning a known id set; assert `getAccessibleFileIds($user)` uses that model (returns the known ids), NOT the utils facade.
**Step 2:** Run `vendor/bin/phpunit --filter FileAccessResolverModelResolution` → red.
**Step 3 — impl:** Add `private function fileModel(): string { return config('ai.file_context.file_model') ?: \Condoedge\Utils\Facades\FileModel::class; }` and resolve the query via that class (`$class::query()->accessibleBy($user)`), falling back to the facade only if the class is missing. Keep the closure-resolver precedence. When `shouldEnforceSecurity()` and the resolved set cannot be computed (exception) **and** no fallback filter is enabled, return `[]` (fail closed) — never silently "all".
**Step 4:** Run → green.

### Task A2 (CondoEdge): `App\Models\File::scopeAccessibleBy($query, $user)` reproduces real authorization
**Files:**
- Modify: `app/Models/File.php` (add scope; do not touch `scopeVisibility`)
- Test: `tests/Feature/Ai/FileAccessibleByScopeTest.php` (or tinker assertion per 0.2)

**Real semantics to reproduce (from `FilePolicy::view`, `scopeVisibility`, `getLibrary`):**
- **Management** (`isManagement()`): files where `team_id ∈ userTeamIds($user)` (all visibilities).
- **Board contact** (`isBoardMember()`): `union_id ∈ linkedUnionIds($user)` AND `public ∈ [PUBLIC_ALL, PUBLIC_BOARD]` (plus discussion-parented files already union-bounded).
- **Mere contact** (`isMereContact()`): `union_id ∈ linkedUnionIds($user)` AND `public = PUBLIC_ALL`.
- Always exclude soft-deleted (SoftDeletes already global).

**Step 1 — failing test:** seed a union with three files (PUBLIC_MANAGERS, PUBLIC_BOARD, PUBLIC_ALL) + one file in a *different* union/team. For a mere-contact user assert `File::accessibleBy($user)->pluck('id')` = only the PUBLIC_ALL file in their union; for a board user = PUBLIC_ALL+PUBLIC_BOARD in their unions; for a manager = all files of their teams; assert the foreign-union file never appears for any.
**Step 2:** Run → red.
**Step 3 — impl** (concrete):
```php
// app/Models/File.php
public function scopeAccessibleBy($query, $user)
{
    if (!$user) {
        return $query->whereRaw('1 = 0');
    }

    // Resolve role + scopes against the PASSED user (not auth()), so CLI/queue/threaded contexts are correct.
    $teamIds  = method_exists($user, 'getTeamIds') ? $user->getTeamIds() : collect();
    $unionIds = method_exists($user, 'linkedUnionIds') ? $user->linkedUnionIds() : collect();

    if ($user->isManagement()) {
        return $query->whereIn('team_id', $teamIds);
    }

    if ($user->isBoardMember()) {
        return $query->whereIn('union_id', $unionIds)
            ->whereIn('public', [static::PUBLIC_ALL, static::PUBLIC_BOARD]);
    }

    if ($user->isMereContact()) {
        return $query->whereIn('union_id', $unionIds)
            ->where('public', static::PUBLIC_ALL);
    }

    // Unknown role → deny (fail closed).
    return $query->whereRaw('1 = 0');
}
```
> Because Eloquent prefers a model's local scope over a Builder macro, this override automatically supersedes the package's `accessibleBy` macro for `App\Models\File`. Requires Task A1 so the resolver actually queries `App\Models\File`. Set `config('ai.file_context.file_model') = App\Models\File::class` (already the default — A1 makes it honored).
**Step 4:** Run → green. Add a regression assert: a contact cannot retrieve a `PUBLIC_MANAGERS` insurance file of another resident.

### Task A3 (CondoEdge): make `AiAuthAdapter` role/union-aware (graph-side parity)
**Files:** Modify `app/Auth/AiAuthAdapter.php`.
**Why:** currently `getTeamsWithPermission` always returns `[currentTeamId()]` and `checkIfPermissionsExist` always true, collapsing graph access to the current team. Return `userTeamIds($user)` (all of the user's teams) and keep `checkIfPermissionsExist` honest.
**Step 1 — test:** assert `getTeamsWithPermission($user,'File')` returns all of the user's team ids, not just current. **Step 2:** red. **Step 3:** implement using `$user->getTeamIds()`. **Step 4:** green.
> Note: deeper graph fail-closed work (SEC‑2/SEC‑3) is Backlog B-1; A3 is the minimal correctness bump that pairs with the file fix.

---

## Phase B — File-retrieval reliability (so accessible files are actually found)

### Task B1 (package): create the `file_name` full-text payload index (+ filename fallback)
**Files:** Modify `src/VectorStore/QdrantStore.php` (`createCollection` and/or add `ensurePayloadIndex`), `src/Services/QdrantChunkStore.php` (`searchByFilename` fallback). Test: `tests/Unit/VectorStore/PayloadIndexTest.php` (mock HTTP) + `tests/Unit/Services/QdrantChunkStoreFilenameTest.php`.
**Step 1 — test:** assert `createCollection()` issues a `PUT /collections/{c}/index` for `file_name` (field_schema text); and that `searchByFilename` does not throw when the index call 404s (degrades to scroll+PHP substring match).
**Step 2:** red. **Step 3:** implement payload-index creation after collection creation; wrap filename `match:{text}` in try/catch → fallback to `scroll` + `str_contains(strtolower)`. **Step 4:** green.

### Task B2 (package): isolate filename vs content sub-searches
**Files:** Modify `src/Services/Context/FileContextProvider.php:97-120`. Test: `tests/Unit/Services/Context/FileContextIsolationTest.php`.
**Step 1 — test:** make `searchService->searchByFilename` throw; assert `searchRelevantFiles` still returns content results (filename failure must not wipe all file context).
**Step 2:** red. **Step 3:** wrap the filename loop and the content search in independent try/catch, log at debug, continue. **Step 4:** green.

### Task B3 (package): best-chunk scoring + forward `min_score`
**Files:** Modify `src/Services/FileSearchService.php:62-88` (use max chunk score, or top-k mean) and `src/Services/Context/FileContextProvider.php:117` (pass `min_score`). Test: `tests/Unit/Services/FileSearchServiceScoringTest.php`.
**Step 1 — test:** a file with chunks [0.8, 0.2, 0.2, 0.2] must survive a 0.4 threshold (score ≥ 0.4) — i.e. scored by best chunk, not average 0.35.
**Step 2:** red. **Step 3:** `$score = collect($fileChunks)->max('score');` (keep avg available as `avg_score` metadata if useful). Forward `min_score` into Qdrant filter to cut weak chunks early. **Step 4:** green.

### Task B4 (package): collection routing for physical docs
**Files:** Modify `src/Services/FileProcessor.php` (accept/thread a `collection`), `src/Services/QdrantChunkStore.php` (per-call collection or a 2nd doc-store binding), `src/Services/Context/FileContextProvider.php`/`FileSearchService.php` (search both `file_chunks` + `documentation_chunks`). Test: `tests/Feature/PhysicalDocsRetrievalTest.php`.
**Step 1 — test:** ingest a physical doc via the docs path → assert a chunk lands in `documentation_chunks` and is returned by `getFileContext` (source `physical`). **Step 2:** red. **Step 3:** thread the collection option end-to-end; have content search union results from both collections (physical IDs keep `physical:` prefix → always pass access filter). **Step 4:** green.
> If you instead decide single-collection is correct, delete the dead `physical_collection` config + the two-collection docblocks and document it (resolve Open Item O2 first).

---

## Phase C — Implicit-rule routing (find the building's rules without the word "rules")

### Task C1 (package): QU emits confidence + `rule_intent` from the same semantic call
**Files:** Modify `src/Services/QueryUnderstandingService.php` (prompt + `normalizeResponse`), `src/DTOs/QueryUnderstandingResult.php` (add `confidence: float`, `ruleIntent: bool`). Test: `tests/Unit/Services/QueryUnderstandingResultTest.php` (normalize) + a prompt-contract test with a stubbed LLM.
**Step 1 — test:** feed a stubbed JSON `{... "question_type":"knowledge_query","confidence":0.9,"rule_intent":true}`; assert the DTO carries `confidence=0.9`, `ruleIntent=true`; malformed → `confidence=0.0`, `ruleIntent=false` (safe defaults).
**Step 2:** red. **Step 3:** extend the system prompt to additionally return `"confidence"` (0–1, the model's certainty in `question_type`) and `"rule_intent"` (boolean: does answering require consulting governing rules/bylaws/declarations, even if unstated?). Add normalize clamping. **No keyword lists.** **Step 4:** green.

### Task C2 (package): gate the knowledge_query Cypher-skip on confidence AND file presence
**Files:** Modify `src/Services/AiManager.php:845-858` (`generateAndExecute`). Test: `tests/Unit/Services/AiManagerKnowledgeGatingTest.php`.
**Step 1 — test:** (a) `knowledge_query` + empty fileContext → must NOT report confident success; falls back to data path or returns an explicit "no governing document found" marker. (b) `knowledge_query` + non-empty fileContext + high confidence → skips Cypher. (c) low confidence → data path attempted.
**Step 2:** red. **Step 3:** use the already-computed `$hasFileContext` (line 847): skip Cypher only when `questionType==='knowledge_query' && $hasFileContext && qu.confidence >= config('ai.query_understanding.knowledge_skip_confidence', 0.6)`; else run `generateQuery`; if knowledge intent but no doc, set a `no_governing_document` metadata flag consumed in Phase D. **Step 4:** green.

### Task C3 (package): rules-corpus routing with authority precedence
**Files:** New `src/Services/Context/RuleCorpusRouter.php` (interface `RuleCorpusRouterInterface` so CondoEdge can extend); wire into `AiManager::retrieveAndSetFileContext` when `qu.ruleIntent`. Test: `tests/Unit/Services/Context/RuleCorpusRouterTest.php`.
**Step 1 — test:** given `ruleIntent=true`, the router runs a semantic search restricted to the rules corpus (a configurable set of collections/file-types/tags), union-scoped via the access resolver, and tags results with `authority` so building-specific docs outrank the generic statute.
**Step 2:** red. **Step 3:** default implementation = search file context with the rules-corpus filter (`config('ai.rules_corpus')`: collections + file `type` allowlist incl. `20` + a `tags` hint), attach `authority_rank` (building declaration > union règlement > CCQ). Inject an explicit precedence note into file context metadata for the response prompt. **Step 4:** green.

### Task C4 (CondoEdge): formalize the rules corpus (Instruction + File type 20 + CCQ)
**Files:** `config/ai-entities/*` (ensure `Instruction` is a first-class entity with embeddable `description`/title + `Union` relationship; `Instruction` already `implements Nodeable`), `config/ai.php` (`file_context.physical_paths` includes the CCQ doc; add `ai.rules_corpus` config = collections/file-types/tags; fix the `Pet` `user_entity_paths` relation type `BELONGS_TO_UNIT`→`UNIT`), and an `App\Services\Ai\CondoEdgeRuleCorpusRouter` implementing the package interface scoped by `linkedUnionIds`.
**Step 1 — test/verify:** assert a "can I have a dog?" style question (stubbed QU `rule_intent=true`) routes to `Instruction`/`File(type=20)`/CCQ for the user's union and returns the building doc above the CCQ. (If app DB unavailable, assert config shape + router unit behavior.)
**Step 2:** red. **Step 3:** implement config + router override + ensure ingestion indexes Instruction text and union File(type=20) into the rules-corpus collection. **Step 4:** green.

---

## Phase D — Grounding/abstention + prompt safety (so rule answers never hallucinate)

### Task D1 (package): abstention guideline + wire `generateEmptyResponse`
**Files:** Modify `src/Services/ResponseSections/GuidelinesSection.php` (add grounding rule) or new high-priority `GroundingSection`; modify `src/Services/AiManager.php` (`buildFinalResponse`/`generateAndExecute`) to route empty data / `no_governing_document` through `ResponseGenerator::generateEmptyResponse()`. Test: `tests/Unit/Services/ResponseGroundingTest.php`.
**Step 1 — test:** with empty execution data AND empty file context, the assembled response prompt contains an explicit abstention instruction ("if the data and documents do not contain the answer, say you could not find it; never invent values, names, file contents, or rule outcomes"); and `no_governing_document` yields a "could not find a rule in your building's documents" style answer, not a fabricated permission.
**Step 2:** red. **Step 3:** add the guideline text; wire the empty/abstain path. **Step 4:** green.

### Task D2 (package): move persona/rules/privacy into the system prompt; delimit untrusted content
**Files:** Modify `src/Services/QueryGenerator.php:207` and `src/Services/ResponseGenerator.php:244` to pass a system string (static persona + rules + privacy) as `complete($userPrompt, $systemPrompt, …)`; mark which `*Section`s are "system" vs "user"; wrap conversation history + file snippets as untrusted; remove the fictional "URGENCE mode" text from `PrivacyAndSecurityGuidelinesSection`. Test: `tests/Unit/Services/SystemPromptSeparationTest.php`.
**Step 1 — test:** assert `complete()` receives a non-null system prompt containing the read-only + privacy rules, and that the user message no longer contains the persona block; assert "URGENCE" text is gone.
**Step 2:** red. **Step 3:** implement the split (add `isSystem()` to the section contract or partition by class allowlist). **Step 4:** green.

---

## Verification (end-to-end, after phases)

- `tests/Feature/SmartAgentFileRetrievalTest.php`: a board user asks for a named union doc → it is found, a foreign-union doc is not, a `PUBLIC_MANAGERS` doc is excluded for a contact.
- `tests/Feature/SmartAgentRuleQuestionTest.php`: "can I have a dog?" (stubbed QU) → routes to the building's Instruction/File(20), answer cites the building rule above the CCQ, and abstains cleanly when no doc exists.
- Run `composer test-unit`; record green delta vs Task 0.1 baseline.

---

## Backlog (tracked, not in this plan's active scope — see audit doc §4/§6)

- **B-1 (P0, security):** thread `$options['user']` into `QueryExecutor::execute`; prefer it over `auth()->user()`; **fail closed** when a sensitive entity can't be filtered; apply the team filter to every UNION branch; ship `on_permission_unexistent='deny'` (SEC‑2/SEC‑3).
- **B-2 (P1):** use `qu.searchQuery` for graph/entity selection; unify the 0.45/0.65 threshold; fix dead relationship indexing (CTX‑1).
- **B-3 (P1):** apply PII/sensibleColumns + `_owner_id` on the batch ingest path (PII‑1).
- **B-4 (P2):** unify `ai.discovery`/`ai.auto_discovery`; lint enhancer-authored `cypher_pattern` in `ai:config:validate` (DISC‑1/2).
- **B-5 (P2):** fix `ai:setup`, `RelatedModelSyncObserver`, `/api/ai/health` leak, physical-file UI crash, conversation team-scoping; rotate the committed OpenAI key.

## Phase R — Remove `condoedge/chatbot` entirely (CondoEdge only; `condoedge/ai` has no chatbot coupling)

> Decision: `condoedge/ai` owns file Q&A; `condoedge/chatbot` (full feature: chat widget, `ChatbotController`+routes, Odoo lead capture, analytics, conversations, embeddings) is retired. The `FileUploaded→HandleFileUpload` listener is already commented out, so part is pre-disabled.

**R1 — App code decoupling (reversible; do first so removing the package can't fatally break autoload):**
- `app/Models/File.php`: remove `use Condoedge\Chatbot\Models\ChatbotDocumentEmbedding;` and `use ...\QdrantService;` (:18-19); remove `chatbot_indexed/chatbot_indexed_at/content_hash/chatbot_indexing_enabled` from `$fillable` (:66-71) and `$casts` (:73-77); delete `cleanupChatbotEmbeddings()` (:379-415) and its call in `delete()` (:366).
- `app/Providers/EventServiceProvider.php`: remove `use Condoedge\Chatbot\Listeners\HandleFileUpload;` (:8) and the `FileUploaded::class => [...]` mapping (already commented) — also decide whether `App\Events\FileUploaded` + its `$chatbotIndexingRequested` flag are still used (only dispatcher is `FileUploadModalManager`); strip the chatbot flag, keep the event only if used elsewhere, else remove.
- `app/View/File/FileUploadModalManager.php`: remove the chatbot-indexing UI option and the `new FileUploaded(... chatbotIndexingRequested)` dispatch (keep a plain dispatch only if another listener needs it).
- `routes/web.php:109-111`: remove the two `/api/chatbot/internal/*` routes → `ChatbotController`. `routes/api.php`: remove chatbot route(s).
- `config/app.php`: remove any chatbot provider/config reference.
- `resources/js/app.js:48`: remove `Vue.component('VlChatbotWidget', require('../../vendor/condoedge/chatbot/...'))` (MUST — vendor path disappears).
- `resources/views/layouts/app.blade.php` + `home.blade.php`: remove the chatbot widget include.
- `config/ai-entities/general.php`: remove `chatbot_indexed*`/`content_hash` from the File entity property/embed lists (:251-254, :323-326, :512-515) and the "AI chatbot indexing" phrase in its description (:368). (Leave `config/ai-entities-backups/**` snapshots untouched.)
- `resources/lang/en/file.php` + `fr/file.php`: remove the `"chatbot-indexing"` key (:20). (`resources/lang/CondoEdge2026.babel` is a generated translation-tool artifact — note but do not hand-edit.)
- `tests/Support/ChatbotTestUserFactory.php`: delete (no non-chatbot usage in app).
- `.env.example`: remove the `# AI Chatbot Features` block (`CHATBOT_AI_ENABLED`, `CHATBOT_FALLBACK_ENABLED`); KEEP `OPENAI_*`/`NEO4J_*` (shared with `condoedge/ai`). `.gitignore`: drop `chatbot_test.txt`.
- Verify: `php -l` each edited PHP file; grep CondoEdge for `[Cc]hatbot` returns only the `.babel` artifact + backups.

**R2 — Composer (staged; user runs):** remove `"condoedge/chatbot": "*"` from `composer.json` require. The command `composer update condoedge/chatbot` (removal) is left for the user to run after R1, since it rewrites the lockfile and re-runs package discovery.

**R3 — Database (reversible drop migration; user runs `migrate`):** create `database/migrations/<ts>_drop_chatbot_tables_and_file_fields.php` with `down()` re-adding, dropping in `up()`: the four `files` columns and tables `chatbot_document_embeddings`, `chatbot_conversations`, `chatbot_analytics` (guard each with `Schema::hasTable`/`hasColumn`). Also remove the stale duplicate `database/migrations/Migrated/2025_07_17_133245_create_chatbot_document_embeddings_table.php`. Leave running `php artisan migrate` to the user.

**R-verify:** after R1, `php -l` clean; after the user runs composer+migrate, the app boots and file upload works without chatbot.

## Open items to confirm during execution

- **O1:** `condoedge/chatbot` already embeds files in parallel. Should `condoedge/ai` own file Q&A going forward (and chatbot be retired), or coexist? Affects whether to dual-index.
- **O2:** Is the two-collection (`file_chunks`/`documentation_chunks`) split intended for isolation (then B4 routes), or should we collapse to one collection?
- **O3:** Confirm `App\Models\File` has columns `public`, `union_id`, `team_id`, `user_id`, `parent_type` as used in A2 (verified in `FilePolicy`/`scopeVisibility`; double-check the migration before relying on `public` tri-state values).
