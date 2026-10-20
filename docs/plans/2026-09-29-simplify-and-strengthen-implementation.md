# Kompo AI — Simplify & Strengthen: Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (or superpowers:subagent-driven-development) to implement this plan task-by-task.
> **Companion (the WHY + evidence):** `docs/plans/2026-09-29-simplify-and-strengthen.md` — read it first.
> **Progress log:** tick the checkbox of each task in THIS file when it is done and append one line to §99 (Progress log).
> This file is the single source of truth if the session loses context.

**Goal:** Turn kompo/ai into a near-zero-config plug-in that answers correctly: delete everything that does not
carry value, fix every verified bug, and build the missing mechanisms (correct Cypher, right files + file info,
deeper conversation memory, deterministic composition checks).

**Architecture:** Same pipeline shape (Security gate → QU (one LLM call) → retrieval → Cypher generation →
scoped execution → composition), but: the host config becomes a thin domain override (deep merge), the regex
pre-processing layer disappears in favor of QU seeing the conversation, the schema is rendered once as generated
"schema cards" and generated Cypher is validated against it, a turn ledger replaces the one-deep snapshot, files are
chunked by structure and carry cards, and composition is checked in code once (single validation pass).

**Tech stack:** PHP 8.1+/Laravel package `condoedge/ai` (namespace `Condoedge\Ai`), Neo4j 5 (laudis client),
Qdrant, OpenAI (LLM + embeddings), PHPUnit 10 + Orchestra Testbench, Kompo UI. Host app: CondoEdge.

---

## 0. Ground rules (read before ANY task)

**Paths**
- Package: `C:\Users\jkend\Documents\Projects\kompo\ai` (this repo).
- Host: `C:\Users\jkend\Documents\Projects\kompo\CondoEdge`. `vendor/condoedge/ai` there is a SYMLINK to the package —
  package edits are live in the host immediately.
- CondoEdge is **experimental, not in prod**: wiping/reconfiguring its AI config, graph and vector indexes is allowed.

**Encoding trap:** Windows PowerShell 5.1 `Get-Content`/`Set-Content` without `-Encoding UTF8` mangles UTF-8 (—, →, é).
Edit files with the Edit tool, or Python with `encoding="utf-8"`. (This plan file was mangled once and repaired.)

**Never**
- Never commit, stash, branch or run any git write command. The user manages git.
- Never add hardcoded semantic word lists (EN/FR keyword arrays, regex intent detection). Semantics come from the QU LLM call.
- Never delete code without the **Skeptic check** (below).
- Never live-test in the browser while an eval is running (shared OpenAI TPM).

**Searching the package** — use code-index MCP tools (Grep/Glob are hook-blocked):
`mcp__code-index__set_project_path("C:\Users\jkend\Documents\Projects\kompo\ai")` once, then
`search_code_advanced`, `find_files`, `get_file_summary`. For CondoEdge use `Read` or PowerShell `Select-String` scoped
to that folder.

**Unit tests** (package root; the full suite cannot load because the package's own `vendor/condoedge/utils` lacks
`ChatMessagesQuery` — run per file/dir):
```
php -d opcache.cache_id=kompoai-<unique> -d memory_limit=2G vendor/bin/phpunit tests/Unit/<Dir or File> --no-progress
```
(Since Task 0.6: the full `tests/Unit` suite loads and runs (~2423 tests). ALWAYS pass a unique `opcache.cache_id` per
concurrent php process — the CLI opcache is shared on this Windows box and concurrent processes (incl. a running eval)
crash with `zend_mm_heap corrupted`. Remaining known reds after 0.6: 5 TenantScopingLeakTest (Task 1.20),
6 MessageInvocationRenderTest (stale tests: expect string, code returns Kompo\Html; 3 MissingAppKey), 1 order-dependent
ActionLinkHandlerExportTest (`maatwebsite/excel` vs JSON-escaped `maatwebsite\/excel`) → Task 1.25.)
Baseline per-dir results are in the session scratchpad `baseline-summary.txt`; copy the summary into §98 before
Phase 1 so later phases can compare. Known pre-existing failures: `tests/Unit/Kompo/*` (missing utils class),
`NativeToolActionRoundTripTest` (needs ChatBubbleRenderer), `FileAccessResolverTest` (Windows rename flake — passes isolated).

**Skeptic check (mandatory before every deletion)** — user rule: "we want just things that really give value, and we
want skeptical recheck when wanting to remove". For each item to delete, record in §97 (Deletion ledger):
1. `search_code_advanced` for the class/method/key name (literal) in the package → list every non-test caller.
2. `Select-String -Path C:\Users\jkend\Documents\Projects\kompo\CondoEdge\{app,config,routes,resources} -Recurse -Pattern '<Name>'` → host callers.
3. For config keys: search both `config('ai.<key>` and `'ai.<key>'` and `cfg('ai.<key>'`.
4. Evidence of value: search `docs/eval/*.md` for the feature name — was it measured load-bearing? (KEEP list in the
   companion doc §4 wins every tie.)
5. Argue the opposite for 1 minute: "what answer would get worse without this?" If you can name one concrete turn
   from the gold suite, do NOT delete — downgrade to "simplify" instead.
Only delete when 1–3 show no production caller AND 4–5 show no value. Delete tests that only test the deleted code.

**Eval protocol (the regression test — user rule: judged manually, NOT by programmatic judges)**
Run inside CondoEdge (≈12 min per part with throttle):
```
cd C:\Users\jkend\Documents\Projects\kompo\CondoEdge
php artisan ai:eval --suite=storage/eval/suite-partA.json --user=13 --fresh --throttle=20000 --report=storage/eval/<run>-A.json
php artisan ai:eval --suite=storage/eval/suite-partB.json --user=13 --fresh --throttle=20000 --report=storage/eval/<run>-B.json
```
Pre-flight (1 call, abort if not 200):
```
powershell: $key=(Select-String .env -Pattern '^OPENAI_API_KEY=').Line.Split('=',2)[1].Trim('"'); Invoke-WebRequest https://api.openai.com/v1/embeddings -Method Post -Headers @{Authorization="Bearer $key"} -ContentType application/json -Body '{"model":"text-embedding-3-small","input":"ping"}' -UseBasicParsing | Select StatusCode
```
Grading = READ each turn's trace (question, QU output, cypher, rows, files, final prompt, answer) against the turn's
`standard` in `tests/eval/gold-conversations.json` and write a short verdict per turn (pass / partial / fail + one-line
why) into `docs/eval/2026-09-29-<run>.md`, compared side by side with the previous run. A run whose answers are canned
errors (quota/429) is VOID — discard it, never grade it. Regression = any turn that was pass/partial and got worse
for a reason traceable to the phase's change → fix or revert that change before moving on.
Single-turn swings on untouched paths are noise (documented ±0.40): only act on regressions with a traceable cause.

**TDD for code changes:** bug fixes and new mechanisms get a failing unit test first (red), then the fix (green).
Deletions: run the tests of every touched directory after deleting.

---

## Phase 0 — Baseline (no code changes)

- [x] **0.1 Record unit-test baseline.** Copy scratchpad `baseline-summary.txt` into §98.
- [x] **0.2 Pre-flight OpenAI** (command above). Must be 200.
- [x] **0.3 Run the eval baseline** `base-0929-A/B` (current code = iter-12 code, never measured).
- [ ] **0.4 Grade it manually** → `docs/eval/2026-09-29-baseline.md`: per-turn verdict + list of the red turns grouped by
  family (composition / follow-up / legal grounding / schema violation / links / export). This is the reference every
  later phase compares against.
- [x] **0.5 Data hygiene (experimental DB, allowed):** delete the junk meeting named "test" (id 146 per July notes —
  verify with a SELECT first; show the row in the log before deleting).

- [x] **0.4 Graded** → `docs/eval/2026-09-29-baseline.md`: 27 turns, mean **0.568**, 11 pass / 9 partial / 7 fail, 0 VOID.
- [x] **0.7 Suite maintenance** (done 2026-09-29; verified field-diff: only rights[2], digest[0], digest[1] standards changed;
  backups in scratchpad `*.orig`. Julie owns NO unit — verified; team-3 latest past meetings: 140 AGA 1240 St-Sébastien
  2023-10-24 ZOOM, 136, 135). Scope was: rewrite digest t0/t1 standards (meeting 146 past + soft-deleted → past-meetings
  fallback) and rights t2 "my contribution" (honest "you own no unit"); master + split files, nothing else.
- [x] **0.6 DONE** — tests/bootstrap.php + tests/ManifestIsolationExtension.php (per-process/per-class services manifest,
  relative paths because Laravel treats `C:\` as relative) + phpunit.xml bootstrap/extension; 0 rename errors in repeated and
  concurrent runs (verified by main loop: Chat+Context 227 OK). Package dev dep `condoedge/utils` pinned-updated 0.2.116→0.2.316
  (lock backup in scratchpad) so tests/Unit/Kompo loads. Machine notes for the owner: php.ini has `opcache.enable_cli=On`
  (shared CLI opcache → heap corruption across concurrent processes) and `xdebug.mode=profile` (slows every run).
- [ ] ~~0.6 original text~~ **Stabilize the test environment (Windows rename flake):** in `phpunit.xml` `<php>` add
  `<env name="APP_SERVICES_CACHE" value="<sys temp>/kompo-ai-services.php"/>` and `APP_PACKAGES_CACHE` likewise (or set
  them per process in the base TestCase `getEnvironmentSetUp` using `sys_get_temp_dir().'/'.getmypid()`), then re-run
  `tests/Unit/Services/Chat` + `tests/Unit/Services/Context` — the 14 rename errors must disappear. Also try
  `composer update condoedge/utils` in the package (dev only) so `tests/Unit/Kompo` can load; if it pulls breaking changes,
  revert and note it.

---

## Phase 1 — Verified bug fixes (TDD, small, no re-ingest)

### ✅ DONE 2026-09-29 — Task 1.20 (do FIRST in Phase 1): Tenant-scoping leak tests are silently broken
> Result: test-only fix (DTO access + recorded store calls); all 5 shapes safe-stopped before the store — NO leak.
> 16 tests/66 assertions green (main-loop verified); Security dirs 174+30 green. Follow-up noted: `tests/Feature/**` is not in
> any phpunit testsuite (e.g. AiSystemFeatureTest indexes results as arrays and never runs) → handle in Task 2.A.13.
**Files:** `tests/Unit/Services/Security/TenantScopingLeakTest.php:~140`.
- The test indexes `QueryExecutionResult` as an array → 5 leak shapes (CALL subqueries, nested CALL, label-free hop reuse,
  aliased collect in CALL, CALL with team wrapper) are NOT verified. Fix the test to read the DTO (`->data`, `->metadata`,
  or whatever the executor's contract exposes — read `src/DTOs/QueryExecutionResult.php`).
- If any shape then FAILS (reaches the graph store unscoped), that is a **security bug**: fix it in the scoping layer
  (fail-closed) before anything else, with the shape as the red test.

### ✅ DONE 2026-09-30 — Task 1.21 (found by baseline): Scope injector splits `STARTS WITH` / `ENDS WITH`
> Lexer T_OPERATOR post-pass + OPERAND_EXPECTING vs CLAUSE_HEAD keyword split with position check; analyzer/validator
> `isOrderVariable()` (ORDER not followed by BY = variable). Adversarial security review: NO bypass across 35 shapes (+45 probe
> queries after follow-up). Security 194 / 30 green, QueryExecutor suites green, EXPLAIN-verified. Remaining availability gaps
> (fail-closed) moved to Task 1.26. LESSON: every hand-rolled grammar fix here uncovers another shape → strengthens Phase 11
> (generator-emits-scope + `ai_team_id` WHERE) as the real simplification.
**Files:** `src/Services/Security/CypherScopeAnalyzer.php` (`findAnchorIndex` ~591 and any clause-boundary tokenizer that
treats `WITH` as a clause keyword), tests in `tests/Unit/Services/Security/` (OptionalMatchScopingTest pattern).
- Red test: the exact failing query shape from base-0929-B rights t2 — a `WHERE ... toString(i.invoiced_at) STARTS WITH toString(date().year) ...`
  (+ an `ENDS WITH` and a `CONTAINS`-adjacent variant) must be scoped without splitting the predicate, and the result must
  pass `EXPLAIN`-level syntax (at least: no `WITH` clause injected after `STARTS`). Keep fail-closed behavior.
- Fix: `WITH` preceded by `STARTS` / `ENDS` (case-insensitive, whitespace/comments between) is an operator, not a clause.
  Check every place that splits/segments on `WITH` (search `'WITH'` in Security/**).

> ✅ 2026-09-30 Task 1.22 review fixes (i)-(iii) + Task 1.17 DONE: export-ask guard (swaps QU to
> `asFollowUpOnPreviousResult()` so the PREVIOUS result is exported), `xlsx/xls → excel` format-code alias (QU was dropping it),
> accurate docblock, closing JSON reminder; QU `ai.understanding.max_tokens` 1200 (legacy `ai.query_understanding.max_tokens`=500
> removed — provider was passing it; AiServiceProvider now wires the new key), truncation detection via new
> `LlmInvalidJsonException{rawText,truncated}` (OpenAI finish_reason=length / Anthropic stop_reason=max_tokens) + one retry at 2×.
> DOC DEBT: docs/onboarding/04-production-runbook.md:194 still documents AI_QUERY_UNDERSTANDING_MAX_TOKENS=500 → Task 12.6.
> Accepted risk: a genuinely ambiguous NEW export mid-conversation exports the previous result instead of asking.

### Task 1.22 status (2026-09-30): implemented + reviewed (approve-with-fixes). PENDING FIXES (send to implementer after batch A
releases AiManager.php): (i) second structured clarify guard — no clarify when `qu->exportFormat !== null` AND a recorded result
exists (`last_cypher_query` non-empty or `last_result_count > 0`) + fix the test comment; (ii) reword the docblock claim
"row values excluded" → "structured result memory carries no values; assistant summaries (user-visible text) capped at 200 chars"
(decision: keep summaries — they carry anaphora referents, same provider already saw them); (iii) one-line closing reminder
("Return ONLY the JSON object") after the conversation block. Deferred idea: move the block into its own message (keeps system
prompt fully static; smaller injection surface) — revisit in Phase 5.

> 2026-09-30 STATUS 1.26: implemented, then adversarial review REJECTED it — CRITICAL cross-tenant bypass REGRESSION introduced
> by 1.26: `anchorClauseKind()` treated YIELD as a clause reset, so a `yield` word inside an OPTIONAL MATCH's open WHERE flipped
> the kind → mandatory binding's scope AND-merged into the optional WHERE (cross-tenant rows survive with b=null). HEAD was correct.
> Fix dispatched (compute clause kind only from tokens before an open WHERE; YIELD only after CALL; comment-aware label position).
> MUST be re-reviewed adversarially before Phase 1 closes. Live in working tree meanwhile (CondoEdge experimental).
> 2026-09-30 FIX LANDED: open-WHERE-aware clause kind, YIELD only after CALL, label-position flags, operandPosition, AS-alias rule,
> shared `CypherClauses.php`, and **comments emit no tokens** — which ALSO closed two PRE-EXISTING (HEAD) bypasses:
> `MATCH (/*c*/i:Invoice) RETURN i` ran UNSCOPED (6006 rows live) and `OPTIONAL /*c*/ MATCH` lost optionality. Security 279 green,
> leak probe 117 queries LEAK=0. Adversarial re-review: fix APPROVED as delta; but its live-execution oracle (scratchpad
> `rr.php`) PROVED 3 MORE PRE-EXISTING (HEAD) bypasses, each 6006 rows with empty team ids: (1) `//` comment ends at `\r` in Neo4j
> but lexer only stops at `\n`; (2) FF/VT/NBSP/U+2028… accepted as whitespace by Neo4j, lexed as punct; (3) ScopeAcceptanceGate
> accepts `team_id IN $ai_team_ids OR true` / `NOT …` as self-scoped (can happen WITHOUT an attacker via LLM precedence).
> Fix dispatched as CLASS-CLOSING design: canonicalize before analysis (strip comments, reject non-ASCII whitespace/controls
> outside strings, execute exactly the analyzed text) + top-level-AND-conjunct rule for self-scope. Re-review with the oracle
> required. → Phase 11 (generator-emits-scope + `ai_team_id`) is now HIGH priority, not optional.
> 2026-09-30 1.26b LANDED: `CypherCanonicalizer` in QueryExecutor::applySecurityFilter before analysis (strip comments incl.
> `\r`-terminated, reject non-ASCII whitespace/controls/format chars, executes exactly the canonical text); lexer `\r`/Unicode/
> escaped-backtick fixes; sensitive alias must be plain ASCII identifier (closes backtick-alias filter-text injection — 2 MORE HEAD
> bypasses found: `(é:Invoice)`, `` (`x``y`:Invoice) ``, `` (`true OR x`:Invoice) ``); self-scope = whole top-level AND-conjunct in
> plain MATCH WHERE. Oracle: HEAD leaks 15 shapes × 6006 rows; repo 0. Security 350 green.
> 2026-09-30 1.26c: re-review found 1 more PRE-EXISTING bypass — self-scope gate compared alias case-insensitively
> (`MATCH (x:Invoice),(X:Invoice) WHERE X.team_id IN …` → 6006 rows). Fixed: exact identifier compares in ScopeAcceptanceGate,
> SensitiveLabelCoverageGate (binding keys + label memo), validator UNION columns; caller-supplied `ai_team_ids` dropped
> (security params merge last); `skip_security` option DELETED (unused in package + CondoEdge). Security 362 green.
> ✅ FINAL ADVERSARIAL VERDICT: APPROVE — "no known bypass as of now". Total pre-existing HEAD bypasses closed this phase: 7
> (comment-hidden binding, comment-split OPTIONAL, `\r` comments, Unicode whitespace, self-scope OR/NOT, backtick/non-ASCII alias
> + filter-text injection, alias case). Caveat: gates exercised with stub `direct` rules; resolver label case-sensitivity unchecked
> (no case-only-different labels exist in the graph today).

### Task 1.26 (found by 1.21 review): structural validator accepts rewrites Neo4j rejects
`CypherStructuralValidator::isValid()` returns true for a WHERE-kind filter injected after `UNWIND … AS x`, after `SET i:X`, or after
`CALL (i) {…}` (→ `… UNWIND [1] AS x WHERE i.team_id IN …` = SyntaxError at Neo4j; fails closed, availability only).
Fix in `SecurityFilterInjector`: when the anchor segment ends in UNWIND / SET / CALL-subquery, emit `WITH * WHERE …` (or a MATCH-hop)
instead of a bare WHERE; make the validator reject a bare WHERE after those clause heads. Red tests per shape; EXPLAIN-verify.
Also (from 1.21 follow-up): where-kind filters after `WHERE limit STARTS WITH 'x'` / `… AND skip ENDS WITH 'x'` — analyzer's
`isPredicateTerminatingKeyword` + validator still treat SKIP/LIMIT variables as clause heads → apply the lexer's position check
(`isOperandContext`) there. Node alias named like a keyword (`MATCH (order:Invoice)`) is invisible to the analyzer (coverage gate
safe-stops it — OK, note only). Probe tooling: scratchpad `probe_fix.php <queries file>` + `queries*.txt`.
DECISION: keep 1.26 small (fail-closed availability); if it balloons, stop and prioritize Phase 11 instead.

### Task 1.22 (found by baseline): QU sees the conversation (= Task 5.1, promoted) + clarify guards
Do Task 5.1 here (QU `analyze()` receives the compact conversation block). Additionally:
- Clarify must NOT trigger when a previous result exists and QU judges the ask a follow-up (pronoun/anaphora to the last
  result) — guard in code: `needs_clarification && followUpReference && previous result available` → no clarify.
- Clarifying question text must be in `understanding.language` (ask QU to emit it in the question's language; if the
  fallback string is used, translate via lang JSON). Red test: FR follow-up "Parfait — exporte-la en Excel." with a previous
  roster → export of previous result, no clarify; an FR ambiguous first turn → clarifying question in French.
- Harness evidence: QU `follow_up_reference` was false on all 14 part-B turns → after this task it must be true on the
  obvious follow-ups (resilience t1/t3, french t1, roster t1, balance t1).

### Task 1.23 (found by baseline): Error path returns an empty answer
**Files:** `AiManager::answerQuestion` catch paths / `ResponseGenerator` error composition. Any stage error must produce a
non-empty answer in the QU language (`ai.error.encountered_issue` via `trans(..., [], $locale)`). Red test: executor throws →
answer non-empty.

> ✅ 2026-09-30 BATCH A DONE (1.23, 1.24, 1.6, 1.9) + reviewed + review fixes: true_total = ROW count again (completeness,
> truncation, export `complete`); separate `anchor_total/anchor_variable/anchor_label` (count DISTINCT, QU-entity-type-preferred
> anchor, MATCH-bound vars only) used only for "how many <entities>" wording; mixed turns (exec failed / safe-stop + file context +
> not pure data_query) answer from documents + deterministic `ai.error.data_part_failed|blocked` sentence + "Results: NOT AVAILABLE"
> prompt notice; few-shot stored only after proven execution; threshold 0.80 via `exampleMinSimilarity()` (is_numeric guard);
> unscored examples dropped. Services 2014 green. Open notes: generationFailed+docs still answers from docs w/o notice; fan-outs
> below the cap get no distinct count.

### Task 1.24 (found by baseline): Distinct totals
The companion true-total count (`executeWithTrueTotal`, AiManager ~481-541) reports join ROWS (523) as members (530 distinct).
Count `DISTINCT` on the anchor/first returned entity variable. Red test with a fan-out join fixture.

> ✅ 2026-09-30 BATCH C DONE + reviewed + review fixes applied: 1.5, 1.8, 1.13 (incl. Regenerate/EditMessage flows — no txn
> across LLM calls anywhere), 1.14 (buildPromptContext default reads config → processQuestion + eval runner now 10 msgs too),
> 1.15, 1.16, 1.18 (FriendlyError; ai.chat.error-* keys added — they were missing), 1.25. Full tests/Unit was 2512 OK before
> review fixes; Chat 88 / Console 38 green after. Notes: health route now `['api','auth']` → CondoEdge must set
> `ai.health.middleware` (e.g. auth:sanctum or allowlist) in Phase 3; edit now persists even if regenerate throws (only the
> missing-user precondition can throw there).

### Task 1.25: Stale/order-dependent tests
- `tests/Unit/Kompo/MessageInvocationRenderTest.php` (~306): 3 tests expect a string but `src` now returns a `Kompo\Html`
  element → update assertions to the element's rendered content; 3 `MissingAppKeyException` → set an app key in that test's
  environment setup.
- `ActionLinkHandlerExportTest::export_excel_error_surfaces_a_visible_message_not_a_silent_drop` fails only in the full run
  (asserts `maatwebsite/excel` against JSON-escaped output) → assert on the decoded message.

### ✅ DONE 2026-09-30 — Task 1.1: Deep, list-safe config merge
> ConfigMerger (+`relationship_weights` added to REPLACE_WHOLE: package ships e-commerce example weights) + provider swap;
> 8 tests green; provider/facade/config tests green. Main-loop CondoEdge diff old(array_merge) vs new: 0 removed, 0 real
> changes (5 flagged = closure-identity artefacts: entity_actions $mutation closures + PatternLibrarySection closure),
> 36 package keys ADDED (rag.*, response_generation.{length_guidance,truncation_honesty,self_reference_guard},
> semantic_context.*_score, file_context.{recall_floor,recall_limit,snippet_chunks,search_collections,enumeration.*},
> chat.{conversation_limit,page_descriptions,page_explanation_intents,tutorials,floating,modal,drawer}, embedding.vector_size).
**Files:** Modify `src/AiServiceProvider.php:113-118`. Create `src/Support/ConfigMerger.php`. Test `tests/Unit/Support/ConfigMergerTest.php`.

**Step 1 — failing test:**
```php
<?php
namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\ConfigMerger;
use PHPUnit\Framework\TestCase;

class ConfigMergerTest extends TestCase
{
    public function test_nested_associative_keys_are_merged(): void
    {
        $defaults = ['rag' => ['rerank_enabled' => false, 'token_budget' => 4000]];
        $host = ['rag' => ['token_budget' => 8000]];
        $this->assertSame(['rag' => ['rerank_enabled' => false, 'token_budget' => 8000]], ConfigMerger::merge($defaults, $host));
    }

    public function test_lists_are_replaced_whole(): void
    {
        $defaults = ['chat' => ['example_questions' => ['x', 'y', 'z']]];
        $host = ['chat' => ['example_questions' => ['a', 'b']]];
        $this->assertSame(['a', 'b'], ConfigMerger::merge($defaults, $host)['chat']['example_questions']);
    }

    public function test_empty_host_array_replaces(): void
    {
        $this->assertSame([], ConfigMerger::merge(['query_patterns' => ['p' => 1]], ['query_patterns' => []])['query_patterns']);
    }

    public function test_domain_registries_are_replaced_whole(): void
    {
        $defaults = ['entities' => ['App\\Models\\Customer' => ['graph' => []]]];
        $host = ['entities' => ['App\\Models\\Unit' => ['graph' => []]]];
        $this->assertSame(['App\\Models\\Unit'], array_keys(ConfigMerger::merge($defaults, $host)['entities']));
    }
}
```
**Step 2:** run → FAIL (class missing).

**Step 3 — implementation:**
```php
<?php
declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * Host config is a thin override of the package defaults: associative arrays merge recursively,
 * lists and domain registries (keyed by host classes/keys) are replaced whole, empty arrays replace.
 */
final class ConfigMerger
{
    /** Top-level registries the host owns entirely — never blend package examples into them. */
    public const REPLACE_WHOLE = ['entities', 'entity_actions', 'generic_actions', 'attention_sources', 'calculations', 'user_entity_paths'];

    public static function merge(array $defaults, array $overrides, int $depth = 0): array
    {
        foreach ($overrides as $key => $value) {
            $replaceWhole = $depth === 0 && in_array($key, self::REPLACE_WHOLE, true);
            if (!$replaceWhole
                && is_array($value) && $value !== [] && !array_is_list($value)
                && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($defaults[$key])) {
                $defaults[$key] = self::merge($defaults[$key], $value, $depth + 1);
                continue;
            }
            $defaults[$key] = $value;
        }
        return $defaults;
    }
}
```
In `AiServiceProvider::register()` replace the `mergeConfigFrom(...)` call with:
```php
if (!($this->app instanceof \Illuminate\Contracts\Foundation\CachesConfiguration && $this->app->configurationIsCached())) {
    $config = $this->app->make('config');
    $config->set('ai', \Condoedge\Ai\Support\ConfigMerger::merge(require __DIR__ . '/../config/ai.php', $config->get('ai', [])));
}
```
**Step 4:** tests green; then in CondoEdge `php artisan config:clear && php artisan tinker --execute="dump(config('ai.rag.rerank_enabled'), config('ai.response_generation.truncation_honesty'))"` — both must now be non-null.

### Task 1.2: Package default `snippet_length` must fit the context window
**Files:** `config/ai.php:~1104` (`file_context.snippet_length`). Set default `env('AI_FILE_SNIPPET_LENGTH', 6000)`.
Verify `FileContextProvider` (lines ~122, ~238) uses it for the ±`context_window` join. Add a unit test in the existing
`FileContextProviderTest` asserting a 2-window multi-chunk snippet is not cut to one chunk with package defaults.

### Task 1.3: Citation link lookup by `ref`, not array position
**Files:** `src/Services/Response/FileCitationHandler.php` (`createElementForMatch`). Test: new
`tests/Unit/Services/Response/FileCitationHandlerRefLookupTest.php`.
- Red test: `files = [['ref'=>3,'id'=>30,...], ['ref'=>1,'id'=>10,...]]`; answer cites `[1]` → element must carry id 10;
  `[3]` → id 30 (today: `[1]`→id 30, `[3]`→null).
- Fix: replace positional lookup with
```php
$file = null;
foreach ($files as $candidate) {
    if ((int) ($candidate['ref'] ?? 0) === $citationNumber) { $file = $candidate; break; }
}
if ($file === null) { return null; }
```

### Task 1.4: Citation namespace collision between current and previous-turn files
**Files:** `src/Services/ResponseSections/ResponseConversationContextSection.php:48-66`, `ResponseFileEnricher`.
- Previous-turn files must NOT be rendered with bare `[N]` markers. Render them as `(previously cited: <filename>, §<heading if any>)`
  with no numeric marker, and without raw 6000-char fenced snippets (cap 400 chars, spotlighted like current snippets).
- Red test: prompt text for a turn with previous file ref 1 and current file ref 1 contains exactly one `[1]`.

### Task 1.5: `MessagesQuery` fallback reads the wrong field
**Files:** `src/Kompo/MessagesQuery.php:601`. Change to
`$sample = $this->conversation?->context_snapshot['last_result_sample'] ?? [];`
(or `$this->conversation?->getLastResultSample()` — check `AiConversation.php:152`). Kompo tests can't load in the
package; verify by reading + a CondoEdge tinker call.

### Task 1.6: `safe_stop` must never read as "no data"
**Files:** `src/Services/QueryExecutor.php:115-123,~877`, `src/Services/AiManager.php` (where execution result flows to
composition), `ResponseGenerator::generateEmptyResponse`.
- Red test: executor returns `safe_stop: true` → final answer metadata `status = 'blocked_by_scoping'` and answer text uses a
  new translation key `ai.error.scoping_blocked` ("I couldn't run that query safely for your team…") in the QU language —
  never the empty-result template.
- Add EN/FR strings to the package lang JSON files.

### Task 1.7: Stop sending cross-tenant example nodes to the LLM
**Files:** `src/Services/ContextRetriever.php:~587` and `~854` (example sampling), `PromptSections/ExampleEntitiesSection`.
- Decision: remove example-node sampling entirely (Phase 5 schema cards carry enum values/property types instead).
  For now: stop fetching + stop rendering (section returns ''), keep the class until Phase 5 deletes it.
- Red test: `ContextRetriever` retrieval result has no `examples` key / section renders empty.

### Task 1.8: Lock down `/api/ai/health`
**Files:** `src/Http/Controllers/HealthController.php:24,33`, route registration (search `HealthController`).
- Route gets `auth` middleware by default (`config('ai.health.middleware', ['api','auth'])`); response returns
  `status` booleans only, never `$e->getMessage()` (log it instead).

### Task 1.9: Few-shot store stores only proven queries; similarity threshold
**Files:** `src/Services/AiManager.php:388-405`, `src/Services/ContextRetriever.php:49`.
- Move the `storeQuery(...)` call from generation time to AFTER execution, only when `rows > 0` and no error/safe_stop.
  (Find the execution step in `answerQuestion` ~575-655; pass the parameterized cypher through.)
- `DEFAULT_SCORE_THRESHOLD` 0.0 → config `ai.query_generation.example_min_similarity` default 0.80.
- Red tests: (a) a generation whose execution returns 0 rows stores nothing; (b) retrieval drops examples below 0.80.
- Then **purge the store** once in CondoEdge (`php artisan ai:eval ... --fresh` already calls `AiManager::clearStoredQueries`; or tinker).

### Task 1.10: TemplateMatcher hard-fail on unknown label
Superseded by Task 2.B.3 (TemplateMatcher deletion). Do nothing here; tick when 2.B.3 is done.

### Task 1.11: Reranker order is discarded
**Files:** `src/Services/Retrieval/LlmReranker.php`, `src/Services/Context/FileContextProvider.php:284-288`, `src/Services/AiManager.php:~822`.
- Reranker attaches `rerank_score` (float 0..1) to each returned candidate array (candidates are arrays here — if objects,
  wrap). FileContextProvider sorts by `rerank_score` when present, else `relevance`; `priority_score` becomes a tiebreak only
  (Task 1.12). AiManager must not re-sort away the reranked order.
- Red test: reranker returns order [c,a,b] → final `relevant_files` order is c,a,b.

### Task 1.12: Host priority boost is a tiebreak, authority note goes to the response prompt
**Files:** `FileContextProvider` sort, `ResponseSections/FileContextSection.php:62-72`, `PromptSections/FileContextSection.php:50`.
- Sort key = (`rerank_score` ?? `relevance`) DESC, then `priority_score` DESC.
- Render `authority_note` in the RESPONSE FileContextSection next to the file line.
- Red test: team file 0.41 (+1.0 priority) vs law chunk 0.80 → law first.

> ✅ 2026-09-30 BATCH B DONE (1.2, 1.3, 1.4, 1.7, 1.11, 1.12) + reviewed + review fixes: `FileReferenceOrder` tiers
> (reranked > raw > fused, each on its own scale, priority tiebreak, stable); fusion_score survives to the final order;
> ref-first citation lookup; previous-turn files unnumbered + spotlighted in BOTH response and query prompts; eval trace score =
> ordering score; example nodes no longer fetched/rendered. tests/Unit 2706 green.

### Task 1.13: Don't hold a DB transaction across LLM calls
**Files:** `src/Services/Chat/UseCases/AskQuestionUseCase.php:51,83`.
- Remove the `DB::transaction` wrappers around `runTurn`; wrap only the persistence writes (user message add; recordResponse +
  assistant message add) in two short transactions inside `runTurn`.
- Existing UseCase tests must stay green; add one asserting `answerQuestion` is invoked outside a transaction (mock
  `DB::transactionLevel()` === 0 inside a fake AI facade).

### Task 1.14: Wire conversation history size
**Files:** `AskQuestionUseCase.php:116,125`, `ConversationContextManager::buildPromptContext`.
- Pass `(int) config('ai.chat.max_history_messages', 10)`. (Phase 6 replaces this with the ledger; this is the interim fix.)
- Assistant summary cap 300 → `config('ai.chat.history_answer_chars', 800)`.

### Task 1.15: `ai:ingest` prints "Unknown error"
**Files:** `src/Console/Commands/IngestEntitiesCommand.php:~271`. Handle string errors:
`$msg = is_array($error) ? ($error['message'] ?? json_encode($error)) : (string) $error;` + red test on the formatter.

### Task 1.16: Unconfigured Nodeable models log errors on every save
**Files:** the auto-sync observer/trait path (search `No configuration found`). Unconfigured model → silent skip +
one `Log::debug`. Red test: saving an unconfigured Nodeable does not call `Log::error`.

### Task 1.17: QU output truncation
**Files:** `src/Services/QueryUnderstandingService.php:50,67-74`. `max_tokens` 500 → config `ai.understanding.max_tokens`
default 1200; when the JSON fails to parse because of `finish_reason=length`, log a warning with the raw tail and retry once
with double tokens. Red test with a scripted provider returning truncated JSON then valid JSON.

### Task 1.18: Duplicate English error mapping
**Files:** `AskQuestionUseCase::getUserFriendlyError` (240-257). Replace with `ChatPresenter::friendlyError`'s type-based
translation (move the mapping into a shared `Services/Chat/Support/FriendlyError.php` used by both) and pass the QU
language when available. Delete the substring map.

- [ ] **1.27 Phase-1 eval regressions (from p1-0930 grading) — do after Phase 2.A deletions land:**
  (a) digest t2 −0.55 (CAUSED BY 1.22): `AiManager.php:~1286` skips the digest when QU followUpReference is true; with the
  conversation block QU now flags "an excel of everything needing my attention" as follow-up → exports previous 1-row result.
  Fix: digestRequest wins over followUpReference for broad attention asks (QU rule: a broad attention/digest ask is not a
  follow-up to a single-topic turn) + code precedence; red test digest+followUp+excel.
  (b) rights t1 −0.25 (PARTLY CAUSED BY 1.4): previous-turn snippets capped at 400 chars dropped the art. 1090–1097 text the
  deepening turn relied on. Fix: config `ai.response_generation.previous_snippet_chars` default 1500 (unnumbered, spotlighted);
  also consider down-weighting the corpus header chunk for law queries (retrieval picked 1009–1022 indivision chunk).
  (c) rights t2 still 0.15: query now runs but drops the year filter and answers "No results were found…" → honest-zero
  composition for self-anchored empty results (say what was checked) — belongs to Phase 8 (AnswerValidator/empty template)
  unless cheap now via `ResponseGenerator::generateEmptyResponse:921`.
  (d) digest t1 date-only projection (pre-existing): query rule "last/next X lookups return name + union + place" → Phase 5/10.
  (e) team-info t0 0.55→0 CRASH (latent since June, exposed now): `ResponseEntityEnricher.php:48` uses `$row['_label'] ?? $row['type']`
  as entity label → int team_type → TypeError under strict types. Fix: `_label` only (or `type` only if a string naming a
  registered entity); enrichment failure non-fatal (log + unenriched rows) in AiManager AND the eval driver (~292) so traces survive;
  unit test row ['type'=>2].
  (f) roster t0 0.85→0.55 (CAUSED BY batch-A anchor_total): emit the distinct line ONLY when anchor_total < true_total; anchor
  from the RETURN projection's first node var (QU types only as tie-break among RETURN vars); human noun (entity display label /
  plural from config) not the raw label.
  (g) roster-excel t1 0.65→0.35 (1.22 exposed existing export rule): on a follow-up export, if the CURRENT query succeeded and its
  columns ⊇ previous columns (strict superset), export the CURRENT result; never list "Fields included" without disclosing requested
  fields that are missing.
  → After (a)-(g): re-run eval A+B; Phase 1 closes only when no traceable regression remains vs baseline.
  ✅ 2026-09-30 (a)(b)(c)(e)(f)(g) + export strips ENRICHMENT_KEYS/_label DONE; Unit 2653 green; eval p1b-0930 RUNNING.
  Review approve-with-fixes → **1.28 (after p1b eval)**: I1 superset check must also require VALUE overlap on shared columns
  (prev rows' shared-column tuples ⊆ ~90% of current) — else a re-anchored query with generic columns (name/email) exports the wrong
  entity; same condition for requestedFields disclosure; test. I2 normalize QU at parse: digestRequest ⇒ followUpReference=false
  (fallthrough when DigestRunner returns [] would re-export the previous result). M1 log exception trace on enrichment failure.
  M2 single `ResponseEntityEnricher::DECORATION_KEYS` (ENRICHMENT_KEYS + _label) used by resultColumns + exporter; comment on
  `entity_type` collision. M3 anchor noun fallback 'entity' (not the variable name). M4 one memoized "entity config by graph label"
  lookup (enricher/ResultsDataSection/PolymorphicTargetResolver). M5 possessive example only when checked_own_data.
  + previous_snippet_chars default 6000 (rights t1 regression; baseline behavior) — sent to implementer.
  p1b RESULT (docs/eval/2026-09-30-phase1b.md): A 0.492 / B 0.602; comparable-26 (excl. infra-timeout turn) 0.566 vs baseline 0.557.
  NEW ITEMS from p1b grading → later phases: (i) homonym units merged in prose ("310" ×3) → Phase 8 deterministic table/disambiguation
  rule; (ii) enum values undecoded in answers ("Team Type 2") → Phase 5 schema cards carry enum labels + results formatter decodes;
  (iii) empty-result "what was checked" built from QU types not the executed cypher ("account balances" claimed) → Phase 8;
  (iv) digest t1: past meeting worded as "scheduled/will take place", fallback rows tagged upcoming_meetings, link to a task id
  (`entity://Meeting/1903`) → Phase 8 link lint (id must exist in rows of THAT entity type) + Phase 10.3 fallback tag/union;
  (v) QueryExecutor timeout: executor budget 30s == Neo4jStore CURLOPT_TIMEOUT 30s (no headroom), results arriving after budget
  discarded; on follow-up timeout retry once then fall back to previous list → Phase 5.5 (repair on execution error).
  M7 → Phase 10.3: after a digest, "excel of everything needing my attention" re-runs the digest and only ACKNOWLEDGES the export —
  should deliver the file.
- [x] ✅ 2026-09-30 **1.29 DONE — suspicion WRONG, real cause found:** 2-collection RRF fusion already ran at baseline (not
  Task 1.1). The flattening came from the Batch-B review fix M-2/M-4 carrying the per-angle FILE-level RRF sum (Σ1/(60+r) = 0.5560599
  when one file owns all 45 slots) onto every chunk + ranking extra chunks by it. Fix: fusion only cuts each angle's candidate pool
  inside FileContextProvider; references carry cosine `relevance` (+ new `chunk_relevance`) and optional `rerank_score`; eval trace
  score = chunk's own cosine + chunk_index. Unit 2664 green. Retrieval replay: with baseline QU angles the fixed code returns the
  baseline chunks incl. 1071–1073. The MISSING 1071–1073 on the follow-up is caused by QU DRIFT: follow-up QU (since 1.22 gives QU
  the conversation block — the previous answer says "outlined in the Quebec Civil Code") adds search tag "Quebec Civil Code" → hits
  the title chunk ci0 (cosine 0.61–0.67) → raises file max → snippet-depth margin excludes ci26. → Phase 7.1: title/TOC chunks must
  never set the file max nor take slots; keep corpus/document names out of QU search tags (QU prompt rule); capture the QU prompt in
  the trace (Phase 4). 1069 never retrieved for buyer-arrears even at baseline (Phase 7.1/7.2 article chunks + exact article match).
  Original note:
- [ ] ~~**1.29 (found by p1c grading) Retrieval relevance flattened since p1:**~~ every law chunk scores exactly 0.5560599, how-to
  chunks 0.016 (RRF-like) → within-file relevance ordering lost; header chunk (1009-1022) takes slots; 1071-1073 chunk no longer
  retrieved. Suspect: Task 1.1 deep merge exposed package `file_context.search_collections` (multi-collection fusion) to CondoEdge;
  verify by trace + config (effective-ai.before.json). Fix so per-chunk relevance survives fusion (fuse per FILE rank, keep the
  chunk's own cosine for intra-file ordering), or restore single-collection search if CondoEdge doesn't need fusion. Red test +
  retrieval check on the rights/tear-down/buyer-arrears questions (compare chunk lists vs baseline trace).
  Also from p1c: (i) QU tags a pure legal turn with Union/Invoice/Bill → "Current Focus: Bill" + Bill action links in a legal
  prompt → Phase 5 (entity selection); (ii) semantic-selection fallback on Qdrant errors must be recorded in trace context_errors
  (INFRA) → Phase 4.4; (iii) truncation guard should check stated total AND listed count ("525 distincts" while prompt said 523;
  "10 shown" listing 8) → Phase 8.
- [x] **1.19 Phase-1 verification:** DONE via p1/p1b/p1c runs (docs/eval/2026-09-30-phase1*.md). Phase 1 CLOSED except 1.29. run unit tests for every touched dir; run eval `p1-0929-A/B`; grade vs baseline.

---

## Phase 2 — Deletions (every item goes through the Skeptic check first; record in §97)

> ✅ REVIEWED 2026-09-30: approve — no live breakage (ctor shifts all named/safe, no consumers of removed keys/getters, facades +
> interfaces consistent, all KEEP items intact; Unit 2632 OK, Integration 94 OK/40 env-skipped, ai:diagnose 10/10).
> Found: CondoEdge `route:list` fails on `larecipe.index` (pre-existing) → 12.x: why does the package hard-require
> binarytorch/larecipe (a docs site) at all? candidate: move docs out / make larecipe optional (zero-config + dependency weight).
> ✅ 2026-09-30 PHASE 2.A DELETIONS LANDED (per skeptic ledger §97): −3,904 lines (src+config 70,343 → 66,439;
> config 2,972 → 1,842). Full Unit 2632 OK / 6 skipped; CondoEdge boots. tests/Feature → tests/Unit/Feature (8 moved, 3 deleted).
> Renamed chat keys to what code reads (welcome_title/welcome_message/show_typing); added 18 previously-undefined keys (incl.
> security toggles). KEPT per ledger: keywordBasedSelection, calculateConfidence, identifying_fields (owner decision),
> response_generation.temperature. Phase-3 host follow-ups: CondoEdge config still has dead keys + old welcome/show_typing_indicator
> names (list in agent report → copy into 3.1). Doc debt for 12.6: README.md, docs/onboarding/00..04 (lines listed in report).
> Newly dead: EntityMetadataBuilder::all(); semantic_matching.{enabled,fallback_to_exact,thresholds,collections,cache_embeddings}.

### 2.A Zero-risk dead code
- [ ] **2.A.1 Pattern library:** `config/ai-patterns.php`, `query_patterns` block in `config/ai.php` (~1336-1360),
  `PatternLibrary` class, `PatternLibrarySection`, its registration in `config/ai.php` prompt sections list, the
  `pattern_library` module, related tests. (Evidence: host `__DIR__` has no ai-patterns.php → empty at runtime in every eval.)
- [ ] **2.A.2 Dead chat services:** `SendMessageService`, `RegenerateMessageService`; shrink `AiChatService` to
  `isAvailable()` (or move it to a tiny `AiAvailability` helper used by `AbstractChatSurface:65`).
- [ ] **2.A.3 Tutorial:** `src/Services/Tutorial/**` + `chat.tutorials.*` config + tests.
- [ ] **2.A.4 `AnthropicEmbeddingProvider`** (placeholder, no real API) + its binding branch.
- [ ] **2.A.5 `HasInternalModules` extension hooks** `extendBuild/extendBefore/extendAfter/replaceModule` (no callers).
- [ ] **2.A.6 `FileSearchService`** unused methods: `searchByMetadata`, `hybridSearch`, `getRelatedFiles`, `getFilesByUser`, `getFilesByTeam`.
- [ ] **2.A.7 Never-written snapshot fields:** `last_insights`, `last_visualizations`, `last_execution_stats`,
  `last_available_actions`, `last_detected_template` in `ConversationContextManager::recordResponse` (lines 130-214),
  `buildPromptContext`, `ConversationContextSection:113-128`, and the 7 dead `AiConversation` getters (101-169).
- [ ] **2.A.8 `extractInsights` / `suggestVisualizations`** (`ResponseGenerator.php:1111-1282`) + consumers.
- [ ] **2.A.9 `ContextRetriever` dead methods** (~908-938) and `keywordBasedSelection` (`SemanticContextSelector:507-580`),
  `QueryGenerator::calculateConfidence` (:470; replace confidence with null / remove field where unused).
- [ ] **2.A.10 Dead config keys** — the ~71 keys listed in the companion doc §3. Also define the read-but-undefined keys
  (`schema_cache_ttl`, `export.redact_rows`, `export.internal_columns`, `security.enforce_scopeable_subset`,
  `file_context.team_grain_unsafe`, `query_generation.scope_bypass_threshold`, `ingestion.memory_limit`) with their current
  code defaults. Fix chat key drift: `welcome.title`/`welcome.message`/`show_typing_indicator` vs code's
  `welcome_title`/`welcome_message`/`show_typing` — keep ONE spelling (the one the code reads).
- [ ] **2.A.11 Package `config/entities.php`** → return `[]` with a comment pointing to docs; update the `require` in `config/ai.php`.
- [ ] **2.A.12 `RelatedModelSyncObserver`** (sync_triggers empty everywhere) + registration.
- [ ] **2.A.13** ~~Orphan test file `tests/Unit/Services/Security/CypherStructuralValidator.php`~~ **SKEPTIC CHECK OVERTURNED
  (2026-09-30): it is a static test helper USED by the Security tests — KEEP** (rename to `…/Support/StructuralValidatorHelper.php`
  optional). Duplicate tiny `PatternLibraryMinimalTest` goes with 2.A.1. Also: `tests/Feature/**` is in NO phpunit testsuite
  (never runs) — run it once, keep the passing/valuable ones by moving them into Unit/Integration, delete the rest (skeptic check).
- [ ] **2.A.15 Leftovers of Task 1.7 (example entities removed):** `TestQuestionCommand.php:116` "Example entities" block,
  TokenBudgeter example-entities accounting, `includeExamples`/`examplesPerLabel` options, docblocks
  (SemanticPromptBuilder:42, ContextRetrieverInterface:18-38), `resources/docs/1.0` (AI_INCLUDE_EXAMPLES, facades.md:185,
  prompt-sections.md:246). `ExampleEntitiesSection` class + registration deleted in 5.2.
- [ ] **2.A.14** Verify: tests of touched dirs green; `php artisan config:clear` + `php artisan about` boots in CondoEdge.

> ✅ 2026-09-30 **2.B CLOSED** (reviewed + fixes: English possessive regex in QueryUnderstandingResult::empty() deleted;
> conversation-context-management.md rewritten 1220→270 lines; QU failure stamps `export_intent_unavailable: qu_failed`; FR
> export strings accented; dead enable_templates test options removed; ai-eval dead trace fields dropped): deleted ReferenceResolver (+2 tests), TemplateMatcher (+test),
> EntityExtractor::extractFromQuestion + EN/FR lists, ConversationContextManager::processQuestion, per-turn AI::getSchema() in
> AskQuestionUseCase, FileContextProvider EN follow-up word list (→ QU followUpReference), ExportResponseComposer
> detectExportIntent/questionLanguage + inline FR/EN (→ 15 `ai.export.*` lang keys, QU language). Focus now = executed cypher's
> first label, else QU entityTypes[0]; previous focus soft-carried only when QU names no types; mentioned_entities capped at 10;
> no regex export fallback (QU export_format with xlsx alias). All test dirs green; ai-eval 97 green.
> WATCH in next eval: previous-turn focus carry now depends on QU; newly-live follow-up file-search angle ("Team: …").
> Follow-ups: ai-eval TurnTraceBuilder is_follow_up/is_follow_up_regex/resolved_entity now meaningless → drop;
> docs chat/conversation-context-management.md needs a real rewrite (12.6); `composer dump-autoload` for stale classmap.

### 2.B Hardcoded-semantics layer (replaced by QU; lands together with Task 5.1 "QU sees the conversation")
Do these AFTER Task 5.1 is green.
- [ ] **2.B.1 `ReferenceResolver`** (161 LOC; `resolve()` verbatim by contract; outputs unconsumed) — delete, and delete
  `processQuestion`'s resolver call in `ConversationContextManager` / `AskQuestionUseCase:115-117`.
- [ ] **2.B.2 `EntityExtractor::extractFromQuestion`** regex path — `focused_entity` comes from QU `entityTypes[0]` after QU runs
  (set in the ledger, Phase 6). Remove the per-turn `AI::getSchema()` call in `AskQuestionUseCase::getSchema` if nothing else needs it.
- [ ] **2.B.3 `TemplateMatcher`** (190 LOC, English-only, throws outside retry) + its call in `QueryGenerator:128-137`.
- [ ] **2.B.4** `FileContextProvider.php:~935` EN-only follow-up list → use QU `followUpReference`.
- [ ] **2.B.5** `ExportResponseComposer::detectExportIntent` / `questionLanguage` + inline FR/EN strings → QU `exportFormat` /
  `language` + lang JSON keys.

- [ ] **2.C Phase-2 verification:** eval `p2-0929-A/B`, grade vs p1.

---

## Phase 3 — Host config becomes a thin override (CondoEdge)

> ✅ 2026-09-30 PHASE 3 DONE: CondoEdge config/ai.php 2067 → 1298 lines (registries + gate closures copied byte-for-byte; 16
> genuinely-different values; domain comments kept; new header documents deep-merge + "never leave `'x' => []`").
> Effective-config diff before/after: REMOVED 60 dead keys (exact analysis list), ADDED 0, CHANGED only
> health.middleware → ['web','auth'] (api-group+session guard could never authenticate; no Sanctum in CondoEdge);
> 76/76 closure hashes identical; registry counts identical; ai:diagnose 10/10; kept `debug => env('AI_DEBUG', true)` on purpose.
> Backup: scratchpad ai.php.before-phase3. 3.5 eval SKIPPED deliberately: effective config proven identical → a run would only
> measure noise. Analysis + dumps: scratchpad phase3-analysis.md, effective-ai.{before,after}.json.

- [ ] **3.1** With Task 1.1 live, write a throwaway script (scratchpad) that loads package `config/ai.php` and host
  `config/ai.php`, and prints every host leaf identical to the package default. Delete those leaves/sections from the host
  (18 identical sections ≈ 600 lines: auto_sync, access_control, security, sync_triggers, model_namespaces, graph, vector,
  llm, embedding, query_generation, query_execution, rate_limits, response_generation, rag, relationship_weights,
  semantic_matching, semantic_context, file_processing, entity_id_fields) + the dead `discovery` block.
- [ ] **3.2** Keep in host ONLY: project, identity, persona, guardrails.domain_scope, entities loader, entity_actions,
  generic_actions, calculations, attention_sources, user_entity_paths, corpus_register, physical_paths, example_questions,
  page_examples, and the ~15 genuinely different values (list them in a header comment).
- [ ] **3.3** Remove the stale host copy of anything the package now defaults (e.g. `query_patterns => []`).
- [ ] **3.4** Diff effective config before/after: dump `config('ai')` to JSON before 3.1 and after 3.3 (scratchpad) and review
  every changed leaf — only the 21 "newly reaching" package keys may change. Decide per key whether the new default is right
  for CondoEdge (e.g. `rag.rerank_enabled` stays false until Phase 7).
- [ ] **3.5** Boot check + eval `p3-0929-A` only (config-only change; partA is enough unless a red appears).

---

## Phase 4 — Eval harness → dev-only package + production pipeline observer

> ✅ 2026-09-30 4.1–4.3 DONE + reviewed + fixes: PipelineObserverInterface + Null/Recording/Guarded observers +
> PipelineObserverScope; every stage/branch/prompt/usage emitted (observation only — answer arrays byte-identical under a
> throwing observer); MessageTraceObserver persisted to assistant metadata.trace (prompts off by default; cypher literals masked
> via TraceRedactor; QU whitelist; errors masked+capped; hard size guarantee ≤ max_bytes measured like the Eloquent cast;
> cascade-deleted with the conversation); token usage via ReportsTokenUsageInterface (reset before every call). Unit 2717 green.
> ✅ 2026-09-30 PHASE 4 CLOSED: review approve-with-fixes → all applied: pipelineOptions allowlist (`store_examples` only);
> `--fresh` no longer wipes the few-shot store (eval never writes examples; explicit `--fresh-all-examples`); fallback buffer
> capped + URLs/hosts masked; dead prompt-capture code deleted (CarriesPrompts, capture branches, PromptCaptureTest); export turns
> expose data_sample (in-memory only, never persisted); ai-eval confirmed require-dev. ai Unit 2620 green, ai-eval 97 green.
> p4-A graded 0.488 (flat vs p1b 0.492; docs/eval/2026-09-30-phase4.md).
> ✅ 2026-09-30 4.4–4.7 LANDED: new package ../ai-eval (condoedge/ai-eval, require-dev in
> CondoEdge via path repo — composer: 1 install only); driver = production AskQuestionUseCase + RecordingPipelineObserver
> (AskQuestionInput gained observer/pipelineOptions; CompositePipelineObserver); trace keeps all old fields except soft scores,
> adds QU prompt, usage, branch, fallbacks, labels infra/void; preflight + fingerprint; attribution = hard labels; dropped flat
> mode/QuestionSetGenerator/--generate/--tags/--iterations/--offline; deleted TestQuestionCommand + TestConversationCommand;
> removed 5 public-for-eval AiManager methods; AiChatService → isAvailable only; ai:enhance uses AI::answerQuestion + recorder.
> Production observability: AiLogger::fallback buffered per turn; silent catches now emit stageError (still swallow).
> ai Unit 2604 green (eval tests moved), ai-eval 94 green. p4-0930-A: INFRA/VOID 0; 1 gate fail (buyer-arrears cites_document).
> Command: `php artisan ai:eval --suite=... --user=13 --fresh --throttle=20000 --report=...` (+ `--ask="..."`, `--skip-preflight`).
> Leftover dead code: CarriesPrompts + capture_prompts metadata prompts in QueryGenerator/ResponseGenerator + PromptCaptureTest.
> conversation_context stage ≈6s/turn (schema + processQuestion regex layer) → removed by Phase 2.B.

- [ ] **4.1 `PipelineObserver` hook (production).** Create `src/Contracts/PipelineObserverInterface.php`
  (`stageStarted(string $stage, array $data)`, `stageFinished(string $stage, array $data, float $ms)`,
  `promptBuilt(string $stage, array $messages)`, `error(string $stage, \Throwable $e)`) and a `NullPipelineObserver`.
  `AiManager::answerQuestion` reads `$options['observer'] ?? app(PipelineObserverInterface::class)` and emits at every stage
  (security, understanding, file_context, query_generation, execution, composition, and the digest/clarify/page-narration
  branches). ResponseGenerator/QueryGenerator emit `promptBuilt` via the same observer passed through context.
  Tests: a recording observer sees the stage sequence for a data turn, a clarify turn, and a knowledge turn.
- [ ] **4.2 Production trace:** a `MessageTraceObserver` that collects stage timings, QU output, retrieved file ids, cypher,
  errors, token usage (Task 4.3) into `ai_messages.metadata.trace` (config `ai.trace.enabled`, default true; prompts stored
  only when `ai.trace.capture_prompts` true).
- [ ] **4.3 Token usage:** `AbstractHttpLlmProvider` returns/records `usage` (prompt/completion tokens) from OpenAI and
  Anthropic responses; expose via a `LastUsage` accessor or a returned DTO; observer sums per turn.
- [ ] **4.4 Create package `C:\Users\jkend\Documents\Projects\kompo\ai-eval`** (`condoedge/ai-eval`, namespace
  `Condoedge\AiEval`, its own `composer.json` requiring `condoedge/ai`, its own ServiceProvider registering `ai:eval`).
  Move: `src/Services/Eval/**`, `src/DTOs/Eval/**`, `src/Console/Commands/EvalCommand.php`, eval tests. Rewrite
  `PipelineEvalDriver` + `ConversationEvalRunner` to drive `AskQuestionUseCase` on a scratch conversation with a recording
  observer (no copied pipeline). Shrink `AttributionService` to hard labels (stage-error+step, context-not-retrieved,
  gate-failed) + raw signals (~100 LOC). Drop flat mode, `QuestionSetGenerator`, `--generate`, `--tags`, `--iterations`.
  Keep `--suite`, `--user`, `--fresh`, `--throttle`, `--report`, `--limit`, `--from-logs`.
  Add: quota pre-flight (abort on non-200), build fingerprint in the report (package git SHA via `git rev-parse HEAD`
  read-only + sha1 of effective `config('ai')` + prompt section list).
- [ ] **4.8 Trace gaps (from p4 grading), small, in ai-eval + observer data:** first composition draft as its own field (today
  parsed out of the retry prompt); the EXECUTED (scoped/canonicalized) cypher alongside the generated one; understanding
  sub-timings (QU call / entity selection / schema); per-angle retrieval hits (which phrasing found which chunk); per-LLM-call ms;
  data_sample cap configurable. Blame field is always empty — drop it.

- [ ] **4.5 Install in CondoEdge as dev dependency:** add to CondoEdge `composer.json`
  `"repositories": [{"type": "path", "url": "../ai-eval", "options": {"symlink": true}}]` and
  `composer require --dev condoedge/ai-eval:@dev` (only this package). Verify `php artisan ai:eval --help`.
- [ ] **4.6 Delete from the package:** `TestQuestionCommand`, `TestConversationCommand` (fold into `ai:eval --ask="..."` in
  ai-eval if still wanted), the 6 public-for-eval AiManager methods become private/protected.
- [ ] **4.7 Verification:** run eval `p4-0929-A/B` with the NEW harness; traces must contain the same fields as before
  (compare one turn's trace keys old vs new) + usage + fingerprint.

---

## Phase 5 — Correct queries

> 2026-09-30 BATCH A reviewed: approve-with-fixes (privacy PASS — probe returns only type names + 2 aggregate shape flags;
> exact-Cypher tearDown guard). Fixes dispatched: I-1 partial schema (failed rel-pattern scan) cached as "no edges" for the TTL →
> never cache null patterns, builder falls back to configured edges; I-2 flush only when ingest creates new label/rel type;
> I-3 probe only carded labels + negative cache; I-4 live-verify regex escaping; M-2 conditional date rule; M-3 generic UNION
> rule independent of scoping; M-6 doc references.
> 2026-09-30 BATCH A LANDED (5.2 schema cards, 5.3 schema cache, 5.8 cleanup, 5.9 file names only): Unit 2642.
> Prompt size: q1 −0.2%, q2 −9.3%, q3 −21.8% (schema blocks −24/−36%) — target −40% NOT met; cause = ENTITY SELECTION BLOAT
> (q1 15 labels incl. Signature/Tag/Tax/Channel; q3 33 labels incl. Bill/Fund/Invoice for a board question) + ~20K chars static
> blocks (CURRENT USER CONTEXT 6.9K, ACTION REQUESTS 5.2K, PROJECT CONTEXT 4.7K, CALCULATIONS 3.7K, QUERY RULES 3.8K).
> getSchema 1152ms → 1ms warm (was paid twice/turn). Cards fixed an invented edge (fk hint names the missing rel type) and
> decoded bm_role=5 → 'President'. Dates confirmed ISO strings in graph (host calc note `datetime()` was WRONG → fixed).
> - 2026-09-30 **5.10 + 5.11 LANDED (batch C; review IN FLIGHT)**: EntitySelectionPruner (seed/scope/calculation/vector-
>   fallback/identity/tenant/closure/ownership tiers, cap 12) + selector bug fixes (FQCN keying, best scope per key, seed-scope
>   search) + CalculationMatcher (embedding) + static-block shrink (user context, action awareness, calculations, entity-scoped
>   business rules — HOST EDIT, backup p5c/host-ai.php.bak). Gold prompts −50% total vs 5A (q1 −61%, q3 −56%, q2 −11..−27%);
>   all 5 questions correct; board question now returns the CURRENT board (max year/union) — was mixing years. Unit green.
>   Review: approve-with-fixes (executor/validator independent of selection → no security weakening). Fixes dispatched: split
>   toFloat legacy-money clause out of the entity-scoped MONEY OWED rule; tenant hops before identity under the cap; ContactUnit
>   rule scoped to ContactUnit+Contact; empty-projection guard; Contact gate from config; tenant hops for closure labels.
> - [x] **5.10 (NEW) Entity-selection pruning:** selected-label count is now the prompt-size driver. Select from QU entity types +
>   one-hop closure over live relationship patterns + anchors; vectors only for scopes; cap (config, e.g. 12) with compact
>   one-line cards for low-ranked labels. Measure prompt size + cypher on the 3 gold questions again.
> - [ ] **5.11 (NEW) Shrink static blocks** (CURRENT USER CONTEXT 6.9K, ACTION REQUESTS 5.2K): render only what the question needs.
> - **CORRECTION (9.0a investigation, scratchpad 90a-money-model.md): the graph is NOT stale** — 6,006 = the NON-soft-deleted
>   MySQL invoices (28,570 soft-deleted 2021–23); Bills/Entries/Transactions match too. Real due = ledger entries
>   (Charge::real_due_amount, Unit::outstandingBalance), but the local DB never backfilled entries.invoice_id/bill_id after the
>   Dec-2024 refactor → the app itself shows $0 here. Plan: package `graph.computed` (property → accessor/method) + `graph.with`
>   (eager-loads) in entity config so ingest stores app-computed due_amount/total_amount/outstanding_balance; rewrite host
>   calculations (team_total_owed, unit_outstanding_balance, owners_behind, supplier_payables) + string-status scopes
>   (finance.php, ai.php ~1178/1187/1197). **OWNER DECISIONS 2026-09-30: (1) BACKFILL the local entries links (approved DB write;
>   entries table backed up first; follows the union-136 route rule) — IN FLIGHT; (2) ENFORCE `access_control.identifying_fields`
>   in Phase 8 (mask PII fields in rows sent to the LLM; re-insert real values deterministically in rendered tables/exports).**
>   ✅ **BACKFILL DONE** (scratchpad 90b/; backup entries-before.sql 5.4MB; restore cmd in agent report): used the app's own
>   current tagging rule (Transaction::createEntry / Invoice::createMainEntry / Bill::createJournalEntries: tag the control-account
>   12*/22* main entry) — the union-136 route applied literally would have broken ~1,060 invoices. 3,170 invoice + 1,087 bill
>   entries tagged; idempotent. App methods now: team 3 = 75 open invoices due $16,959.86 (27 units / $16,548.03, top units
>   961/43/51); 4 open bills $11,493.17; global 182 invoices $43,774.79, 24 bills $50,056.90 — equals the pre-refactor formula.
>   Opening-balance charges (detail line on 1200/2200) count as owed = app behavior, kept. NOTE: entries.updated_at NOT bumped →
>   the re-ingest must be FULL. Status alone ≠ due (paid invoice 6569 still owes $150.90) → scopes must pair status with due.
>   ✅ Package `graph.computed`/`graph.with`/`graph.casts` DONE (Unit 2757; GraphPropertyMapper::mapNodeProperties = single
>   mapping point for all ingest paths). Review approve-with-fixes → **9.0-p-fix (apply AFTER the host re-ingest finishes — don't
>   change ingestion code under a running ingest):** I-1 reject static / non-public / Illuminate-declared methods as computed
>   sources (delete/truncate/save/touch footgun) + mirror in validate-config; I-2 drop the unvalidated `->with()` from the
>   ai:ingest chunk query (a bad relation aborts the whole model; eagerLoadGraphRelations already loads per chunk with
>   isolation); M-1 warn on computed-key collisions (graph.properties / id / team & scope columns) + restore the extracted value on
>   failure + validatePropertyKey; M-2 load() vs loadMissing on update; M-3 docs (accessors must use loaded relations; bypasses
>   $hidden; legacy getFooAttribute given as 'foo'); M-4 tests (relation rejection, 'exec'/'count' never called as functions,
>   camelCase Attribute, enum/date normalization, invalid with doesn't fail ai:ingest); M-5 drop double sanitize.
>   ✅ **9.0-h DONE (host money config)**: Invoice/Bill `computed` due_amount/total_amount + `with`; Unit outstanding_balance
>   (cast float); string status legends; all calc_* + int Invoice/Bill statuses removed (other entities' statuses ARE integers —
>   kept); 4 canonical calcs rewritten (team_total_owed, unit_outstanding_balance, owners_behind NEW, supplier_payables as ONE row
>   with per_supplier list); user_entity_paths fixed (Pet/Reservation removed — no live rows; Announcement unit branch dropped;
>   Thread path via m.sender_id). Verified via scoped executor for team 3 = app to the cent: 75 inv / $16,959.86; 27 units /
>   $16,548.03 (top 961/43/51); 4 bills / $11,493.17. Live Q&A correct on all 3 money questions.
>   FINDING → Phase 8: gpt-4o-mini mis-adds rows even with a total column present ($10,493.17 vs $11,493.17) → deterministic
>   totals/table rendering + numbers-exist check are REQUIRED, not optional. Freshness: unit balances change on Entry/Transaction
>   saves → unit re-sync or nightly re-ingest (Phase 12 doctor/schedule).
>   ⚠ FULL re-ingest CRASHED at 19:41 after 2h09m: `Allowed memory size of 2147483648 bytes exhausted` in
>   src/VectorStore/QdrantStore.php. Memory grew monotonically ACROSS models in one process (84MB → 1.9GB after Attachment
>   53,511 rows) = package leak in the ingest/vector path → **9.3b (NEW, install-story blocker): fix the cross-model memory
>   leak in ai:ingest (unset/flush per model, gc, avoid accumulating Qdrant payload buffers / query logs / model caches; run
>   each model in a fresh process as fallback) + test with a memory ceiling.** Models completed through Messaging\Attachment;
>   remaining models to be re-ingested per-model after the script's sync/index steps finish.
>   (original note) FULL re-ingest of all 73 models RUNNING (started 17:32; status: scratchpad 90h/full-status.txt; after END run
>   `php scratchpad/90h/post.php`). Don't change ingestion code until it ends. Then: rewrite host calculations/scopes/attention sources with
>   string statuses + computed props, full re-ingest, fix gold standards that assume $0.
> - ✅ 2026-09-30 BATCH B reviewed + fixes applied: AccessMode + reserved `ai_*` ParameterMissing never repaired; error
>   feedback redacts data literals (List/Map/typed wrappers/quoted strings; errs toward over-redaction); truncated JSON reply →
>   teachable retry (never runs a fragment); quantified-path + unbound-OPTIONAL validator cases (0 false violations); corpus
>   fixture anonymized. Unit 2777 green.
> - 2026-09-30 **BATCH B LANDED (5.4/5.5/5.6)**: CypherSchemaValidator (0 false violations on 41 real
>   row-returning cyphers; flagged all 9 real defects in 129 historical cyphers incl. :CUSTOMER/:USER inventions and a.balance —
>   3 of them had returned 100 rows of null/wrong values) wired into the generation retry budget; live repair observed
>   (invented TeamRole-[:BELONGS_TO_USER]->User rejected → FK join); ExecutionErrorClassifier (repair only on
>   Neo.ClientError.Statement.*); JSON generation output (needs_query/cypher/reason), 'NO QUERY REQUIRED' removed. Unit 2740.
>   Findings: owners-behind still joins invoices by customer_type='contact' (domain issue → 9.0 host rewrite); host
>   user_entity_paths reference labels/types absent from the graph (:Pet, :Reservation, :BELONGS_TO_UNIT, :BELONGS_TO_SENDER) →
>   9.0/12.4 lint; test fixtures hold real syndicate names → anonymize.
> - Phase 5A review fixes DONE (Unit 2666): null relationshipPatterns never cached / fall back to configured edges; flush only
>   on new label/rel type/pattern; probe only carded labels + 10-min negative cache; regex probe verified LIVE (due_at →
>   String(ISO date)); conditional date rule; one generic UNION rule in QueryRules; docs updated.
> - ~~VERIFIED 2026-09-30 (main loop, MySQL vs graph): MySQL invoices = 34,576, graph Invoice nodes = 6,006 (graph holds
>   ~17% → badly stale).~~ WRONG (soft deletes not checked). `calc_due_amount` is NULL in MySQL too (34,571/34,576) — the column is simply never populated, so EVERY
>   canonical money formula built on it is wrong; the "honest verifiable zero" narrative of July was grading against a dead column.
>   `invoices.status` is a STRING column in MySQL ("1","2","3","4","5","10") → host int filters are wrong regardless of ingestion.
>   → NEW **9.0 (do before any further money/status eval):** (a) find how CondoEdge computes what an invoice/unit owes (model
>   accessors / invoice_details / payments tables — read app/Models/Finance*, Invoice*, Payment*); rewrite host `calculations` +
>   scopes + attention sources on real fields with string status values; (b) full re-ingest so the graph matches MySQL;
>   (c) update the gold-suite standards that assume "every balance is $0".
> - ✅ 2026-09-30 **Gold standards updated for real money** (scratchpad gold-money/, .orig backups): honest-zero rule retired;
>   supplier-payables exact $11,493.17 (min_rows 1), owner-arrears Soho Ph3 = 4 units / $1,211.48 (min_rows 4), unit-310 homonyms
>   159/301/472 each $0 (0 open) but "no unit owes" generalization fails, digest overdue 5-of-75 + total $16,959.86, rights t3 Julie
>   owns no unit but must not claim the team owes nothing; probe:invoice-status re-pointed 10934 (deleted) → 7129. Stale seeds left:
>   probe:invoice-lines (10934 deleted), probe:budget-invoices (budget 33's invoices all soft-deleted).
> - **DATA FINDINGS → Phase 9 PRIORITY UP:** `Invoice.status`/`Bill.status` are STRINGS ("1".."5") in the graph → host scopes
>   (`n.status IN [2,3,4]`) and attention sources (`b.status = 2`) are SILENTLY EMPTY; `Invoice.calc_due_amount` is NULL on 6002 of
>   6006 invoices → "what does unit 310 owe" always $0 — the July "dataset has zero receivables" conclusion may be a SYNC BUG, verify
>   against MySQL in Phase 9. Typed ingestion (9.1) + full re-ingest must come BEFORE more money/status evals are trusted.

- [ ] **5.1 QU sees the conversation.** `QueryUnderstandingService::analyze()` gets a compact conversation block (last 3
  turns: question, subject, entity types, columns, row count, cited file names) — from `conversation_context` today, from the
  ledger after Phase 6. Update the QU prompt text that already says "from the conversation context" (:142, :262) to
  reference the block. Red tests: follow-up "add their emails to it" with a previous roster turn → `followUpReference` true
  and `entityTypes` includes the previous types (scripted provider asserting the prompt contains the block).
  → then do Phase 2.B.
- [ ] **5.2 Schema cards replace 4 sections.** New `src/Services/Schema/SchemaCardBuilder.php`: for each SELECTED label
  → `Label {prop: type, ...; enum prop: [v1,v2,...] (≤8 values)} ; (Label)-[:TYPE]->(Other) ; (Other)-[:TYPE2]->(Label)`
  built from Neo4j `db.schema.nodeTypeProperties()` + `relationshipPatterns` (+ entity config description/aliases).
  New `PromptSections/SchemaCardsSection` replaces `SchemaSection`, `RelationshipsSection`, `DetectedEntitiesSection`,
  `ExampleEntitiesSection` (skeptic-check each; keep any unique fact by moving it into the card).
  Record prompt token size before/after on 3 gold questions (target −40%).
- [ ] **5.3 Cache `getSchema()`** (4 Neo4j queries incl. full-graph aggregation, twice per turn): cache key
  `ai:schema:<graph fingerprint>` until next ingest (ingest commands call `AI::flushSchemaCache()`).
- [ ] **5.4 Cypher schema validation + repair loop (G4).** New `src/Services/Security/CypherSchemaValidator.php` using the
  existing `CypherLexer`/`CypherScopeAnalyzer` bindings: every node label exists; every `(a:A)-[:T]->(b:B)` exists in that
  direction in `relationshipPatterns`; every `x.prop` exists on x's label (skip computed aliases). Returns a list of
  human-readable violations ("`(Contact)-[:CUSTOMER]->(Invoice)` does not exist; did you mean `(Contact)-[:OWNS]->(Unit)`?").
  Wire into the existing generation retry (`QueryGenerator:~245,283-285`): violations become the retry feedback.
  Optionally run `QueryExecutor::explain()` and treat unknown label/rel/property notifications as violations.
  Red tests: invented `:CUSTOMER` edge, reversed direction, nonexistent `a.balance` → violations; valid calc cypher → none.
- [ ] **5.5 Repair on execution error:** Neo4j error at execution → one regeneration with the error text as feedback
  (same retry budget), before falling back to the error answer.
- [ ] **5.6 Structured generation output:** QueryGenerator asks for `{"needs_query": bool, "cypher": string|null, "reason": string}`
  via `chatJson`; delete the exact-match `'NO QUERY REQUIRED'` path (:216). Red test: reply with needs_query=false → no execution.
- [ ] **5.7 Verified few-shot library:** seed the few-shot store from host `calculations` Cypher (+ eval-passed pairs recorded
  in `docs/eval` grading — optional) tagged `verified: true`; `SimilarQueriesSection` shows verified examples first and drops
  the "may come from older, worse interactions" hedging once only proven examples exist (Task 1.9).
- [ ] **5.8 Sections cleanup:** remove duplicated UNION rules (`SemanticPromptBuilder:456-459` vs
  `TenantScopingRulesSection:77-84` — keep one), fix the date rule contradicting host calcs (`QueryRulesSection:99-101`) —
  after Phase 9 typed ingestion the rule becomes "dates are date values"; until then make it consistent with host calcs.
  `StatisticsSection` folded into `ResultsDataSection`.
- [ ] **5.9 Query-side FileContextSection → filenames only** (no 5×6000-char text, no citation rules; delete the
  "building rules override general laws" line). This also lets file search run in parallel with generation (Phase 7.6).
- [ ] **5.10 Verification:** eval `p5-0929-A/B`; specifically read: board t0, owner-arrears (invented edges), ambiguous t0/t1
  (clarify), resilience t1 (anchor). Record prompt sizes.

---

## Phase 6 — Conversation memory: the turn ledger

- [ ] **6.1 Ledger entry** written by `ConversationContextManager::recordResponse` on EVERY assistant message into
  `ai_messages.metadata.turn`: `{turn_no, question, subject, question_type, entity_types, cypher, row_count, columns,
  sample_rows (≤10), true_total, cited_files: [{file_id, name, chunk_indexes, headings}], export_format, status}`.
- [ ] **6.2 `buildPromptContext(conversation, turns = config('ai.chat.ledger_turns', 6))`:** newest row-producing turn in full
  (sample rows), older turns compact (subject, count, columns, cypher first line, file names); beyond N, a rolling summary
  (`ai_conversations.context_snapshot.rolling_summary`, refreshed every N turns by one cheap LLM call, config toggle).
- [ ] **6.3 QU returns `refers_to_turn`** (int|null) using the compact ledger (Task 5.1 block). Augmentation anchor, export of
  previous result, and sticky file scope (7.5) use that turn instead of "the last one". Preserve-on-empty becomes natural
  (ledger never overwrites).
- [ ] **6.4 Snapshot as a view:** `context_snapshot` keeps only `rolling_summary` + pointers; readers
  (`AiConversation::getLastResultSample`, ActionToolGate, ExportResponseComposer, ConversationResumeBuilder, MessagesQuery)
  read the ledger. Remove `mentioned_entities` unbounded growth.
- [ ] **6.5 Regenerate passes `page_context`** (`AskQuestionUseCase::regenerate` 69-90 drops it — take it from the user
  message metadata).
- [ ] **6.6 Verification:** eval A/B + run `storage/eval/suite-long.json` (context-degradation study, never run before):
  read depth markers (msg 17 return-after-switch export, 19 verbatim recall, 21 numeric recall, 23 global summary).
  Record where it degrades → set `ledger_turns` / `conversation_limit.max_messages` from evidence.

---

## Phase 7 — Right files + file info

> ✅ 2026-10-01 **7-A DONE** (7.1 structure-aware chunking w/ heading_path/article_no/article_range/articles[]/is_preamble/page
> metadata + heading-prefixed embeddings; 7.2 exact-article payload-filter angle; 7.1b preamble never sets file max + QU rule
> "no document/corpus names in tags"; 7.5-lite previous-turn shows the CITED passage; 7.3 ACL Qdrant pre-filter on file_chunks
> (post-filter kept as gate); multi-passage per file per angle + cross-angle RRF passage choice). Re-ingest: documentation_chunks
> 53→149 points (CCQ 47→128), file_chunks 3932→3964 (old points deleted). Retrieval: **art. 1069 now retrieved** (buyer
> arrears); how-to lands on the exact section; **1097 (¾ vote) still NOT retrieved** for tear-down → needs QU rewrite toward
> "common portions / alteration work" or a reranker (7.7). Open: ArticleLocator::bounds sub-article range bug (fix dispatched);
> 36 duplicate uploads of one declaration flood slots (dedupe by content hash — 7.x); TokenBudgetEnforcementTest flaky.
> **ACTIONS track opened 2026-10-01 → docs/plans/2026-10-01-actions-review.md** (ranked list; A1/A2 HIGH host gating issues).

- [ ] **7.1 Structure-aware chunking.** `SemanticChunker` (`:130`, `groupIntoChunks:208-219`) splits on markdown headings
  and article markers (bold/leading numbered articles like `**1069.**` / `1069.` at line start — structural pattern, not a
  word list), one article/section per chunk, oversize sections split with overlap WITHIN the section. `FileProcessor.php:333`
  stores `metadata: {heading_path, article_no, page, section_title}`; the embedded text is prefixed with the heading path.
  Red tests on a fixture excerpt of `public/laws/CCQ_Co-ownership_EN.md` (1066–1069, 1093): art. 1069 is its own chunk with
  `article_no=1069`.
- [ ] **7.2 Exact article/number match:** when QU (or the question) references an article number or a defined term the corpus
  indexes, add a Qdrant payload filter angle `article_no IN [...]`. (This replaces the need for BM25 on files; HybridFuser is
  then skeptic-checked for deletion in 7.9.)
- [ ] **7.3 ACL as Qdrant pre-filter:** `QdrantChunkStore` payload gets `file_id` (already) and search accepts
  `file_id MatchAny` from `FileAccessResolver` (resolver already computes the set); remove the over-fetch (15 files / 45 chunks)
  post-filter. Red test: user's own file ranks even when 50 foreign similar chunks exist.
- [ ] **7.4 File cards.** At ingest (`ai:ingest --docs` and on upload processing) one cheap LLM call per file over headings +
  first chunks → `{title, summary (2-3 sentences), outline (≤12 headings), doc_type, language}` stored on the file record
  (new table `ai_file_cards` keyed by file_id+hash, or file metadata JSON) and as one "card" point in Qdrant. Render in the
  RESPONSE prompt: `[N] <name> — <doc_type>, uploaded by <user> on <date> — <summary> — §<heading of cited chunk>` using the
  FileModel facts already loaded in `FileSearchService:181-186`. Group multiple chunks of one file under one `[N]` as
  `[N]` + `(§a, §b)`. Listing questions ("what files do we have about X", "who uploaded X") answer from cards.
  Cards replace hand-written `corpus_register` (generate register expansions from card outlines) — keep corpus_register
  as an optional override only.
- [ ] **7.5 Sticky file scope:** when QU `followUpReference`/`refers_to_turn` points at a turn with cited files, add a retrieval
  angle filtered to those file_ids and expand ±1 chunk around the previously cited chunk indexes.
- [ ] **7.6 Parallel angles:** embed all retrieval angles in ONE `embedBatch` call; run Qdrant searches concurrently
  (Guzzle pool / `Http::pool`), fuse with RRF; start file retrieval concurrently with query generation (possible after 5.9).
- [ ] **7.7 Reranker (user intent: semantic bridging, e.g. "tear down a wall" ↔ "can I make changes in my condo"):**
  RECALL of the bridge stays with QU register expansion (measured load-bearing); the LlmReranker's job is PRECISION over the
  fused candidates (judges meaning, not vector similarity). With 1.11 fixed and article-level chunks, enable
  `rag.rerank_enabled` for file candidates in CondoEdge and A/B on the law turns (tear-down, buyer-arrears, rights t0-t2,
  app-nav) by reading traces: are the right articles in the top 3, is the how-to doc above the CCQ on app questions?
  Keep if it helps any of them without hurting others; otherwise delete reranker + interface binding (skeptic check).
- [ ] **7.8 Default doc path:** `physical_paths` defaults to `resources/ai-docs/**/*.{md,txt}`; `corpus_languages` defaults
  to the app locales.
- [ ] **7.9 Delete** (skeptic check): `HybridFuser` + `hybrid_enabled` if 7.2 covers exact matches; host
  `CondoEdgeFileContextEnricher` tiers if cards' `source`/`authority` tag replaces them (NOTE: that host enricher classifies law
  files by a FILENAME WORD LIST (ccq/law/loi…) — violates the no-word-lists rule; cards' doc_type must replace it).
  Also delete the unscoped public `ContextRetriever::getExampleEntities` + interface method once Phase 5 schema cards land
  (Task 1.7 made it caller-less).
- [ ] **7.10 Re-ingest docs** in CondoEdge (`php artisan ai:ingest --docs` after clearing `documentation_chunks`).
- [ ] **7.11 Verification:** eval A/B; read every law turn's retrieved chunks (article numbers) and app-nav; ask 3 live
  questions manually after the run: "what documents do we have about the declaration?", "who uploaded <file>?",
  "what does <file> say about pets?".

---

## Phase 8 — Composition: one validation pass, deterministic tables

> 2026-09-30 **8-A LANDED (review IN FLIGHT)**: TableRenderer (one renderer; RenderTableAction/markdownTable/toMatrix replaced;
> Excel from rows via export_document_rows, markdown parse only as legacy fallback); DataAnswerPlan (stats + compact masked
> ≤10-row summary + query/system totals + homonym notes + `{{table}}`; code inserts all rows up to cap 25 + "Showing X of Y");
> PiiMasker enforces access_control.identifying_fields + entity sensible_columns with per-turn tokens (owner decision).
> LIVE: suppliers $11,493.17 exact (was $10,493.17/$10,473.17); 25/25 unit balances exact + "Showing 25 of 27"; three '310's
> reported separately with unions; 100 member emails → 0 in prompt, real in table. Services 2212 green.
> 8-A review fixes APPLIED (security review of bindCypher + SafeMarkdownRenderer IN FLIGHT): one per-turn PII token map created
> before QU (QU + query-gen + response + tool-loop results all masked); tokens in generated cypher → bound `$pii_<hmac>` params
> (persisted as last_cypher_parameters); ends-with column matching, text-only masking, word boundaries; sums ONLY
> `metadata.money_properties` (new entity key; CondoEdge declares Invoice/Bill due/total, Unit outstanding_balance, Transaction
> amount, BudgetDetail amount); query-total only if = sum (complete) / ≥ visible money sum (truncated); cell markdown escaping;
> SafeMarkdownRenderer renders pipe tables. LIVE: gmail follow-up — 0 real emails reach QU/query-gen/composition; 193 rows found.
> ✅ 8-A CLOSED: security review found NO exploit; hardening applied (bindCypher single-pass scanner skipping comments/backticks,
> odd-backslash guard; SafeMarkdownRenderer links held aside, nonce placeholders, entity-decoded scheme check, no `//`; code in
> table cells restored; last_cypher_parameters filtered to pii_* on read; retention docs). Services 2247 green.
> ✅ **8-B CLOSED** (review fixes: total patch only within 0.5%/digit-transposition of the single grand total, money-column row
> figures only — real mis-sums go to the retry + `validation.unresolved`; no "[N]" in ANY rendered prompt (pipeline test);
> identity ids seeded + action params checked only for held labels; shared Support/ArticleLocator (move target must DEFINE the
> article; chunk metadata article_no/article_range fallback); case-insensitive labels; no retry when all violations are
> deterministically fixable). Recheck PhysicalDocsRetrievalTest after Phase 7-A lands.
> 2026-09-30 **8-B LANDED**: AnswerValidator (citations incl. placeholder forms + article attribution;
> numbers vs rows/facts/counts/prompt; entity links per label via EntityIdIndex; truncation + self-ref ported; language SKIPPED —
> no non-word-list detector) → ONE combined retry → deterministic patches. ResponseGenerator 1188→999 lines (3 stacked guards +
> re-assert deleted). Prompt: passages labelled with own chunk score + article locator; real-number citation instruction; ids
> listed only under their own label (root cause of entity://Union/3). Live: buyer-arrears 3 runs all valid citations, no [N];
> suppliers exact. REMAINING legal weakness = retrieval (1069 never retrieved; "majority" vs ¾) → Phase 7 NEXT.
> Batch B next: single AnswerValidator (citations 8.2b, numbers-exist, link lint, language), guard simplification.

- [ ] **8.1 Structured composition output:** ResponseGenerator asks for
  `{"answer_md": string, "cited": [{"ref": int, "quote": string}], "rows_used": int|null}` (JSON mode); code renders
  `answer_md`. Fallback to plain text on parse failure (log).
- [ ] **8.2 Single `AnswerValidator`** (new `src/Services/Response/AnswerValidator.php`) collecting ALL violations:
  truncation honesty (existing StatedNumber logic), self-reference (existing), citation integrity (each `[N]` exists AND
  the article number/figure it states appears in chunk N), numbers-exist (every number in the answer appears in rows,
  snippets, or true_total — StatedNumber parses), links (`entity://X/<id>` — id must be numeric AND present in the result
rows / available-actions list; baseline showed invented `entity://Union/3` where 3 was the team id), no "No results were
found" wording when rows exist or when a verifiable zero is available, answer language = QU
  language. ONE combined retry with all violations listed; then deterministic patches (existing truncation patch,
  self-reference patch, strip invalid links). Replaces `ResponseGenerator.php:212-307` (three stacked retries + re-assert).
  Keep the measured-load-bearing checks' behavior byte-compatible where tested; port their tests.
- [ ] **8.2b Citation mechanics (evidence p1/p1b/p4 buyer-arrears: first draft lacks valid markers EVERY run; retry is a coin
  flip; p4 shipped literal "([N] 2)"):** name the real numbers in instruction + retry ("cite as [1], [2] or [3]"); strip "[N]"
  placeholders from the draft before feeding it back; deterministic post-pass "[N] k" → "[k]", drop bare "[N]"; never ship a
  failed-enforcement answer containing placeholder text; label each passage with its article range + its OWN chunk score (today
  all passages of one file show the file's best score "relevance: 50%").
- [ ] **8.3 Deterministic tables:** new `src/Services/Response/TableRenderer.php` (redaction-aware, column labels from
  config, homonym disambiguation column kept) renders the result table from ALL rows (display cap config, with
  "showing X of TRUE_TOTAL"); the LLM receives a compact summary + first rows and writes only the narrative around a
  `{{table}}` placeholder. Replace `RenderTableAction::normalize`, `ExportResponseComposer::markdownTable`,
  `ResultSetExporter::toMatrix` with it. Excel export receives rows directly (stop rows→markdown→cells round trip in
  `ExportExcelAction:61`).
- [ ] **8.4 Stop teaching placeholder links:** `ResponseEntityActionsSection.php:107,147` shows concrete example IDs from the
  actual rows (or none); lint in 8.2 strips any survivor.
- [ ] **8.5 Streaming:** stream the final composition call (`LlmProviderInterface::stream()` exists, unused) to the Kompo chat
  (check MessagesQuery/SuggestionSubmit flow; if Kompo can't stream, add SSE endpoint + small JS). Validation (8.2) runs on the
  complete text; if it patches, the final message replaces the streamed draft.
- [ ] **8.6 Delete** (skeptic check): now-unused guard retry code paths, `retryIsFaithful` branches tied to the old flow,
  `StatisticsSection` (if not done in 5.8).
- [ ] **8.7 Verification:** eval A/B; read roster/board/show-balance (tables complete?), law turns (citations valid?),
  team-info (self-reference), placeholder links count = 0.

---

## Phase 9 — Ingestion correctness + full re-ingest (CondoEdge wipe allowed)

- [ ] **9.1 Typed ingestion:** `GraphPropertyMapper` coerces by DB column type (`SchemaInspector::getColumnTypes()` /
  `PropertyDiscoverer::discoverWithTypes()` — exist, never called): decimal/int → numbers, date/datetime → Neo4j date/datetime.
  Red tests per type. Then remove `toFloat()` shims from host calcs and the "money may be strings" business rule, and update
  the date rule in `QueryRulesSection`.
- [ ] **9.2 Edge refresh on update:** on model update, delete outgoing edges of the configured relationship types for that node
  and re-MERGE (Batch + single paths). Red test: unit reassigned union → only the new BELONGS_TO edge remains.
- [ ] **9.3 Single ingestion path:** delete `DataIngestionService::ingestBatch` + helpers (~250, buggy label-less match), route
  `AiManager::ingestBatch` to `BatchDataIngestionService`; merge the 3 near-identical sync jobs into `SyncEntityJob($op)`;
  pick ONE relationship implementation (RelationshipSyncer vs Batch).
- [ ] **9.4 Index consolidation:** one `ai:index` command (context + scopes + semantic entities, embedded once); delete the
  unread `semantic_scopes`/`semantic_templates` collections, `semantic_matching` config, `IndexScopesCommand` duplicate path;
  `ai:setup` calls `ai:index` once.
- [ ] **9.5 NodeableConfig path** (437 LOC, no CondoEdge model uses it): skeptic check → delete model-property config path.
- [ ] **9.6 Full re-ingest** (CondoEdge): wipe Neo4j AI labels + Qdrant AI collections → `ai:ingest` (all) → `ai:ingest --docs` →
  `ai:index`. Verify money is numeric (`MATCH (i:Invoice) RETURN apoc.meta.cypher.type(i.calc_due_amount)` or
  `valueType()` on Neo4j 5) and MySQL vs Neo4j counts per label.
- [ ] **9.7 Verification:** eval A/B; read every money/date turn.

---

## Phase 10 — Native tool loop: measured decision, then Digest investment

**Assessment (answer to "is it worth? can we make it better?"):** the tool loop is the one mechanism that gives the
model *multi-query* ("my team AND me"), *self-repair* (it sees an error/empty result and tries again) and a natural base for
the Digest — all current weak spots. But today it is a side channel inside the response stage, off everywhere, never
measured, and it duplicates orchestration. Both providers already implement `ToolCallingLlmInterface`, so measuring it
is a flag flip. Decision rule below — keep it only if it earns its complexity.

- [ ] **10.1 A/B as-is:** CondoEdge `.env` `AI_ACTION_NATIVE_TOOLS=true` + `native_query_tool=true`; run eval A/B; read every
  turn vs Phase 9 run. Also run 3 manual multi-part questions ("info about my team and me", "open tasks and next meeting",
  "unpaid bills per supplier and their contact").
- [ ] **10.2 Decision (write it in §99 with evidence):**
  - If tool mode is ≥ fixed pipeline on the suite AND clearly better on multi-part questions → **promote**: make the loop the
    query stage for `question_type=data_query` with multi-part QU flag; tools = `run_query` (goes through 5.4 validation +
    scoped executor), `get_schema_card(label)` (5.2), `search_documents(query, file_ids?)` (Phase 7), `get_file_card(file_id)`;
    budget ≤4 tool calls; then normal composition + AnswerValidator. Remove the response-stage side channel.
  - Else → **delete** (skeptic check): ToolLoopRunner, ActionToolGate native branch, ActionToolSpec, GraphQueryTool,
    ToolLoopResult, ActionToolChannel, `retryIsFaithful` tool branches, `native_tools` config (~1,100 LOC). Keep the markdown
    `action://` link channel.
- [ ] **10.3 Digest investment (user: invest, only real value):**
  - Execute sources concurrently (or through the promoted tool loop) with the rate limiter's batch allowance.
  - Rank items deterministically in code: overdue > due soon > needs approval > informational; dedupe across sources;
    each item = one line + action link + date/amount; LLM writes only the 2-sentence intro (no per-item prose).
  - Per-role source relevance (host config: which sources matter for which team role) and an empty-source policy
    (omit, don't pad).
  - Cache digest data per team for `ai.digest.cache_minutes` (default 10).
  - CondoEdge `config/ai.php` (~1963) `upcoming_meetings.fallback_cypher` returns id/name/start_date/place only — add the
    union hop `(m)-[:BELONGS_TO_UNION]-(un:Union)` + `un.name AS union_name` (as `expired_unit_insurance` does); the
    updated digest standard requires name + date + union.
  - Data hygiene filter: ignore records flagged as test/draft via host-declared predicates in the source's canonical cypher
    (config, not word lists).
  - Discovery proposes candidate attention sources (entities with due/status/date columns) for the host to accept.
  - Verification: whats-next-digest conversation (3 turns) + 2 manual role variants; read for "would a property manager act on
    every line?".

---

## Phase 11 — Tenant scoping v2 (security-critical; only after Phase 9 re-ingest)

- [ ] **11.1** Write `ai_team_id` (array or scalar) onto every tenant-sensitive node at ingest, computed from the entity's
  `team_path` (Eloquent chain) — human-confirmed per entity in config (security).
- [ ] **11.2** Generation-side scoping: the prompt rule becomes "every tenant-sensitive node variable must have
  `WHERE <v>.ai_team_id IN $ai_team_ids`"; the `ScopeAcceptanceGate` runs INSIDE the generation retry loop with per-binding
  feedback; `SensitiveLabelCoverageGate` stays fail-closed.
- [ ] **11.3** Keep the AST injector as fallback during A/B: eval A/B + the scoping corpus tests + a red-team set of
  cross-tenant attempts (OPTIONAL MATCH, WITH, UNION, EXISTS, subqueries) — zero leaks required.
- [ ] **11.4** Only if 11.3 shows parity → delete `SecurityFilterInjector` (424) + injector-only analyzer paths; update the
  host business rule "NEVER tenant-scope Invoice by team_id" (obsolete).

---

## Phase 12 — Install story (zero-config)

- [ ] **12.1 Tenant resolver default:** `ai.auth.team_resolver` (callable/class) default = `$user->current_team_id` ids; delete
  CondoEdge's 4-method adapter if the default covers it.
- [ ] **12.2 Discovery writes a machine-owned cache** `bootstrap/cache/ai-entities.php` (never overwrites host files; no
  `--force` hand-edit loss); loader = generated first, then host overrides (`config/ai-entities/*.php` shrink to
  descriptions/aliases/curated scopes). Move `EntityAutoDiscovery::get*Actions` (765-900) to `AiActionRegistry` so Discovery
  is dev/install-time only.
- [ ] **12.3 `ai:install`** = discover → lint → ingest (typed nodes, edges, docs) → index → integrity report.
- [ ] **12.4 `ai:doctor`** = MySQL vs Neo4j counts per label, dangling FKs, orphan nodes, index config-hash staleness,
  provider pre-flight, config lint (properties exist as columns, target labels exist, team_path resolves, known
  `specification_type` set — normalize the 13 host variants), unknown/dead keys. Replaces `ValidateConfigCommand`.
- [ ] **12.5 Chat UI wiring:** one layout hook/macro instead of adding `AiChatFloating` to 10 views by hand.
- [ ] **12.6 Proof:** in a scratch Laravel app (or CondoEdge with host AI config moved aside), follow the README install
  steps only; chat answers a basic data question. Update `docs/onboarding/*` to the new surface (they describe removed parts).

---

## Phase 13 — Close-out

- [ ] **13.1** Final eval A/B + suite-long + page probes; write `docs/eval/2026-09-29-final.md` (baseline → final per turn).
- [ ] **13.2** LOC report (src before/after), host config lines before/after, deletion ledger summary.
- [ ] **13.3** Update memory (`project_target_vision_plugin`) with outcome and anything left open.

---

## 97. Deletion ledger (skeptic check records)

Phase 2.A skeptic check (2026-09-30, read-only, during p1 eval). VERDICT CHANGES vs plan: 2.A.9b keywordBasedSelection = KEEP (live fallback when semantic index missing); 2.A.9c calculateConfidence = KEEP (live, feeds example metadata); AiChatService = SIMPLIFY not delete (isAvailable + ai:test-conversation). OPEN OWNER DECISION: access_control.identifying_fields is documented as LLM PII-minimization but NOTHING enforces it.

# Skeptic ledger: Phase 2.A deletions (read-only check, 2026-09-30)

How this was checked:
- Package callers: code-index `search_code_advanced` / `find_files` over `C:\Users\jkend\Documents\Projects\kompo\ai`.
- Host callers: PowerShell `Select-String -SimpleMatch` over CondoEdge `app/ config/ routes/ resources/` (*.php, *.js, *.vue, *.json, blade).
- Runtime values: one read-only `artisan tinker --execute` in CondoEdge with `-d opcache.cache_id=kompoai-skeptic`.
- Config keys: a PHP script that bootstraps the host app, flattens the package `config/ai.php`, and scans `src/`, host `app/`, `routes/` and `resources/`. Script and output are in this folder: `keyscan.php` and `keyscan.out`.
- Measured value: `docs/eval/*.md` has 12 files. None of the candidate features are mentioned in them. The "pattern" hits there are DetectedScopes and concept cypher patterns, not PatternLibrary.
- Nothing was edited. No phpunit run (it would write `.phpunit.result.cache` and some suites touch live Neo4j/Qdrant while the eval is running).

Runtime facts from CondoEdge tinker:
- `count(config('ai.query_patterns'))=0`
- `count(app(PatternLibrary)->getAllPatterns())=0`
- `config('ai.sync_triggers')=[]`
- `config('ai.chat.service')=AiChatService`
- `config('ai.chat.system_prompt')=null`
- `ai.chat.tutorials.enabled` is inherited from the package (false). The host does not define it.

## Verdict table

| Item | Package callers (non-test) | Host callers | Measured value? | Counter-argument | Verdict | Exact deletion list |
|---|---|---|---|---|---|---|
| **2.A.1 Pattern library** | `AiServiceProvider.php:225-228` singleton (`new PatternLibrary(config('ai.query_patterns', []))`), `:851` provides(); `SemanticPromptBuilder.php:65,99,473-507` (ctor param, `minimal()`/`simple()`, `getPatternLibrary()`); `QueryGenerator.php:86-87` default `new PatternLibrary()` (only when no builder is injected; the provider always injects one); `config/ai.php:1679` closure registering `PatternLibrarySection`; `config/ai.php:1387-1389` `query_patterns`. `PatternLibrary::loadPatterns()` FALLS BACK to the package `config/ai-patterns.php` when config is empty. This only matters for `new PatternLibrary()` without args (tests and the QueryGenerator default), not the singleton path. | Only the host copy of the block in `config/ai.php:1336-1341` (file_exists → false; CondoEdge has no ai-patterns.php). **Runtime: 0 patterns (tinker-verified).** | No. Every measured eval ran with 0 patterns. The section also only emits patterns whose name matches a detected scope's `specification_type`/`pattern`. | None at runtime: the section is inert in CondoEdge. Bonus: removing the closure at `config/ai.php:1679` removes the thing that breaks `php artisan config:cache` (runbook 04:274). Entity_actions closures remain, so config:cache is not fully fixed. | **DELETE** | `config/ai-patterns.php` (507 lines); `config/ai.php` `query_patterns` block (~1379-1390) + closure line 1679 (and the `use SemanticPromptBuilder` if it becomes unused); `src/Services/PatternLibrary.php` (249); `src/Services/PromptSections/PatternLibrarySection.php`; `SemanticPromptBuilder`: `$patternLibrary` prop/ctor param, `getPatternLibrary()`, docblock line 48, and dead `minimal()`/`simple()` (L7-4, 0 callers); `QueryGenerator.php:86-87` default → `new SemanticPromptBuilder()`; `AiServiceProvider` 47 (use), 225-228, `patternLibrary:` arg at 232, 851. Tests: delete `PatternLibraryTest`, `PatternLibraryMinimalTest`, `PromptSections/PatternLibrarySectionTest`; update ctor calls in `Integration/ConversationContextIntegrationTest:48`, `PromptCaptureTest:50`, `SemanticPromptBuilderRoleNoteTest:21`, `QueryGeneratorRoleSeparationTest:67`, `TokenBudgetEnforcementTest:24`; `ConfigMergerTest:25` uses `query_patterns` only as an example key (rename). Docs: README 164/237/592, resources/docs patterns.md + module-pipeline + prompt-sections + configuration + extending + components, `config/entities.php:443`. Host follow-up (Phase 3.3): delete the host `query_patterns` block. |
| **2.A.2 SendMessageService / RegenerateMessageService / AiChatService** | SendMessageService: **0** (not even container-registered). RegenerateMessageService: only `AiServiceProvider.php:544` singleton + `:892` provides(). AiChatService is **NOT fully dead**: `AbstractChatSurface.php:64-65` calls `isAvailable()` via the interface; `TestConversationCommand.php:64,106,170,184` (`ai:test-conversation`, registered at provider:723) calls `askWithConversation()`. Dead inside AiChatService: `regenerateResponse()`, `getContextManager()`/`$contextManager`, `getUserFriendlyError()` (the live copy is `ChatPresenter`/`FriendlyError`), `getSchema()`+`getSchemaForContext()` (interface method, 0 callers), and most of `getDefaultConfig()` (`system_prompt`, `include_suggestions`, `include_metrics`, `max_suggestions`, `max_history_messages` are merged into `$options` but never read; only `style`/`user`/`allow_anonymous` are used). | `config/ai.php:1163` (`service` default), `:1275` (`system_prompt` copy). No code. | No. | Deleting the whole class breaks the chat "online" badge (`isOnline`) and the `ai:test-conversation` dev command. `ai.chat.system_prompt` has **no effect today**: it is computed and dropped, so a host setting it gets nothing. | Send/Regenerate: **DELETE**. AiChatService: **SIMPLIFY** | DELETE `src/Services/Chat/SendMessageService.php`, `src/Services/Chat/RegenerateMessageService.php`, provider lines 56 (use), 542-544, 892; tests `Unit/Services/Chat/SendMessageServiceTest.php`, `RegenerateMessageServiceTest.php`. SIMPLIFY AiChatService down to `askWithConversation()` + `isAvailable()`: delete `regenerateResponse`, `getContextManager`/`$contextManager`, `getSchema`/`getSchemaForContext` (and `getSchema` from `AiChatServiceInterface`), `getUserFriendlyError`, `getDefaultSystemPrompt`, and shrink `getDefaultConfig` to `style`. Config: delete `ai.chat.system_prompt` (1301). Keep `ai.chat.service`, which is live at provider:533. Alternative: move `isAvailable` to a helper, point TestConversationCommand at `AskQuestionUseCase`, then delete class + interface + `chat.service`. Tests: delete `AiChatServiceIdentityTest` (it only tests system_prompt); trim `Chat/AiChatServiceContextTest` (getContextManager, getSchemaForContext, regenerateResponse cases). |
| **2.A.3 Tutorial** | **0** outside tests. `src/Services/Tutorial/AiTutorialComposer.php` (335 lines); DTOs live in **`src/DTOs/TutorialPlan.php` + `src/DTOs/TutorialStep.php`**, not under Services/Tutorial. `chat.tutorials.*` is read only by the composer. | 0 (host does not define `tutorials`; package default `enabled=false`). | No. | None: opt-in, off by default, no UI or route calls `compose()`. | **DELETE** | `src/Services/Tutorial/` dir, `src/DTOs/TutorialPlan.php`, `src/DTOs/TutorialStep.php`, `config/ai.php` `chat.tutorials` block (~1255-1268); tests `Unit/Services/Tutorial/AiTutorialComposerTest.php`, `Unit/Services/Tutorial/TutorialConfigDefaultTest.php`, `Unit/DTOs/TutorialPlanTest.php`. |
| **2.A.4 AnthropicEmbeddingProvider** | `AiServiceProvider.php:21` (use), `:148` match arm `'anthropic' => new AnthropicEmbeddingProvider(config('ai.embedding.anthropic'))`. The class throws `RuntimeException` on `embed()`/`embedBatch()` (placeholder; Anthropic has no embeddings API). | 0 (host embedding default is openai). | No. | None: selecting it can only crash. Keep `AnthropicLlmProvider` (live, LlmResolver:45 / provider:161). | **DELETE** | `src/EmbeddingProviders/AnthropicEmbeddingProvider.php` (141); provider line 21 + 148 (the match's `default` arm already throws "Unsupported embedding provider"); `config/ai.php` `embedding.anthropic` block 460-464 + the "'openai' or 'anthropic'" comment at 449; test `Unit/EmbeddingProviders/AnthropicEmbeddingProviderTest.php`. |
| **2.A.5 HasInternalModules extension hooks** | `extendBuild`/`extendBefore`/`extendAfter`/`replaceModule`/`clearGlobalExtensions`: **0** in src and 0 in tests (only docblocks). `applyGlobalExtensions()` is called in `SemanticPromptBuilder.php:107` and `ResponseGenerator.php:74`, but it iterates an always-empty array. `processModules()` before/after callback plumbing is always empty. | 0. | No. | This is the documented public extension API (README 248-350, resources/docs module-pipeline/extending), but no host uses it. CondoEdge customizes via the config section lists. | **DELETE** (+SIMPLIFY `processModules`) | Trait: `$globalExtensions`, `$beforeCallbacks`, `$afterCallbacks`, `applyGlobalExtensions`, `replaceModule`, `extendAfter`, `extendBefore`, `extendBuild`, `clearGlobalExtensions`; reduce `processModules(callable $before, callable $module, callable $after)` to a single module callback and update its 2 callers (SemanticPromptBuilder, ResponseGenerator) + the `applyGlobalExtensions()` calls at SPB:107 / RG:74. Keep `addModule`/`removeModule`/`getModule`/`hasModule`/`getModules` (`getModule` is live in SPB/RG). Docblocks: `PromptSectionInterface:17`, `ResponseSectionInterface:17`, `ResponseGenerator:29`; docs README + 3 resources/docs pages + onboarding/01:312. |
| **2.A.6 FileSearchService extras** | `searchByMetadata`/`hybridSearch`/`getRelatedFiles`/`getFilesByUser`/`getFilesByTeam`: **0** src callers. They appear only in the `Facades/FileSearch.php:14-18` `@method` docblock. Their private helpers `buildMetadataQuery` (542) and `filterByMetadata` (588) are used only by them. `getFileRelationships` is live (searchByContent 189/340); keep it. | 0 (`FileSearch` facade not referenced by host). | No. | None. They are also **unscoped** (no FileAccessResolver; `getFilesByTeam(int)` returns any team's files) and `getRelatedFiles` interpolates `$relationshipType` into Cypher (`-[r:{$type}]-`), an injection shape. Deleting them removes attack surface. | **DELETE** | `FileSearchService.php` 363-517 (5 methods) + `buildMetadataQuery` + `filterByMetadata`; `Facades/FileSearch.php` lines 14-18; tests: `Integration/DualStorageCoordinationTest` (the hybrid/related/metadata/byUser/byTeam cases ~176-321) and `Unit/FacadeTest.php:68` (drop `searchByMetadata`,`hybridSearch` from the advertised list; `assertDocblockMethodsExist` stays consistent once the docblock is trimmed). |
| **2.A.7 never-written snapshot fields** | **Verified never written in production.** The only production writer, `AskQuestionUseCase.php:180-189`, passes only `data`, `referenced_files`, `metadata`. `ConversationEvalRunner.php:224,245` passes the same shape. So `detected_template`, `insights`, `stats`, `available_actions`, `visualizations` are always absent. Readers: `ConversationContextManager.php:130-135,184-214,263,268-271`; `PromptSections/ConversationContextSection.php:118-136` (+ `formatAvailableActions()` at 199, only caller is 135). NOTE: `last_query_type` is **live** (written by processQuestion:72); do not delete it with the batch. AiConversation "getters ~101-169" are not getters of these 5 fields. They are separately dead (0 src callers): `getLastQueryType`, `getLastCypherQuery`, `getFocusedEntityFilter`, `getPreviousCypherQuery`, `getLastResultCount`, `updateEntityContext` (all tests-only). Live, keep: `getFocusedEntity`, `getMentionedEntities`, `getLastResultSample` (MessagesQuery:602). | 0. | No. | None: these prompt blocks can never render for real turns. Old DB `context_snapshot` rows may still hold stale values from earlier versions; deleting the readers stops them leaking into prompts, which is an improvement. | **DELETE** | CCM lines 130, 132-135 (keep 131 queryType), 184-188, 193-214, and keys 263, 268-271 in `buildPromptContext`; `ConversationContextSection` 118-136 + `formatAvailableActions()`; `AiConversation` dead getters listed above (optional, same rationale). Tests: `Unit/Services/Context/ConversationContextManagerTest` 322, 356-417 cases; `Unit/Services/PromptSections/ConversationContextSectionTest` 191-260 cases; `Feature/ConversationContextFlowTest` (14 refs); `Unit/Models/AiConversationContextTest:71,80` + `Integration/ConversationContextIntegrationTest:226,347,360,367` if the getters go; docs `resources/docs/1.0/chat/conversation-context-management.md` 319-348, 788-807. |
| **2.A.8 ResponseGenerator::extractInsights / suggestVisualizations** | Called only at `ResponseGenerator.php:309-313`. Results are returned as `insights`/`visualizations` in the response and passed through `AiManager.php:776-777,1191-1192,1874-1875`. **Nobody reads them**: AskQuestionUseCase, ChatTurnResult, Kompo renderers, PipelineEvalDriver and CCM (which only gets `data/referenced_files/metadata`) all ignore them. Interface: `ResponseGeneratorInterface.php:75,84`. | 0. | No. | None: the insights are heuristic strings (avg/high/low) never shown or fed back; the chart suggestions are never rendered. Only `Feature/AiSystemFeatureTest` asserts them, and that suite does not run. | **DELETE** (keep array keys as `[]` for one release, or drop them) | `ResponseGenerator` `extractInsights` (1111-~1148), `suggestVisualizations` (1150-~1282) + private helpers used only by them; `$includeInsights`/`$includeVisualization` (159-160, 309-313); interface methods 75-84; the `insights`/`visualizations` keys in RG 380-381/972-973/983-984/1086-1087, AiManager 776-777/1191-1192/1391-1392/1816-1817/1874-1875, DigestResponseComposer 97-98, ExportResponseComposer 502-503/580-581 (or leave as `[]`); lang keys `ai.insight.*` (found_results, average_value, high_values, low_values, no_results, error_occurred) in `resources/lang/en.json`/`fr.json`; config `response_generation.include_insights`, `include_visualizations` (both dead already). Tests: ResponseGenerator insight/visualization tests (grep `extractInsights` in tests/Unit), `Feature/AiSystemFeatureTest` 178-201/345-374/504-508. |
| **2.A.9a ContextRetriever dead methods** (identified by name) | `getRelationshipWeight` (890), `filterRelationshipsByImportance` (899), `computeRetrievalConfidence` (917): **0** src callers (tests only). Also dead: `getAllEntityMetadata` (634, tests only). `getExampleEntities` (586) + `ContextRetrieverInterface:155` also have **0 src callers** (Task 1.7 removed the sampling; only EvalCommandTest mocks it). After these go, `TokenBudgeter::confidence()` is dead and ContextRetriever's `$tokenBudgeter` (92, 142, 158) is unused. | 0. | No. | `computeRetrievalConfidence` is the only home of a retrieval-confidence signal an abstain/clarify loop could use (audit L4-7 "wire it or cut it"). It feeds nothing today, and the math stays recoverable from git/TokenBudgeter tests. | **DELETE** | ContextRetriever `getRelationshipWeight`, `filterRelationshipsByImportance`, `computeRetrievalConfidence`, `getAllEntityMetadata`, and (plan says after Phase 5) `getExampleEntities` + interface method + `isValidLabel` if it becomes unused; `$tokenBudgeter` prop/ctor param; `TokenBudgeter::confidence()`; config `ai.relationship_weights` (681, only reader is the dead method; host copy at host config:705). Tests: `Unit/Services/ContextRetrieverConfidenceTest.php` (whole file), `TokenBudgeterTest` 67-84, `EntityMetadataTest` 400-450, `ContextRetrieverTest` 1040-1265. |
| **2.A.9b SemanticContextSelector::keywordBasedSelection** | **LIVE fallback**: `SemanticContextSelector.php:72-74` calls it whenever the semantic index collection does not exist (fresh install, Qdrant outage, after `FreshEvalStateCleaner` wipes collections before re-index). | 0. | Not measured. | Without it, a missing index yields NO entity selection, so the query generator gets no entity context at all. With it, name/alias/scope substring matching still selects entities. That is a concrete degradation, not dead code. (Tension: it is hardcoded string matching, against the no-hardcoded-semantics rule. Replacing it is a design change, not a deletion.) | **KEEP** (plan claim "dead" is wrong) | — (optional later: replace with "select all entities, capped" + loud warning). |
| **2.A.9c QueryGenerator::calculateConfidence** | **LIVE**: `QueryGenerator.php:261` sets `QueryGenerationResult::$confidence`, which flows to `AiManager.php:355` (`generateQuery` array), `:1592` (stored as metadata on similar-query examples), TestQuestionCommand:186, PipelineEvalDriver:298. No code gates on the value. | 0. | No. | Removing it changes only logged/stored metadata (heuristic: 0.5 + 0.1/label mention + 0.1 if LIMIT). No answer changes, but it is a DTO/API field, not dead code. | **KEEP / defer** (plan claim "dead" is wrong) | — (optional SIMPLIFY: drop the confidence field end-to-end in a separate change). |
| **2.A.11 package config/entities.php** | Not merged at runtime: the provider only publishes it (`AiServiceProvider.php:689`); `HasNodeableConfig::getConfigPath()` 607 falls back to it only when `config_path()` does not exist. **It already evaluates to `[]`**: `count(require ...)` = 0, and its 543 lines are all commented examples/docs. | Host has its own `config/entities.php` + `config/ai-entities/*` (not affected). | n/a | None at runtime. The docs value (commented how-to) is duplicated in resources/docs. | **SIMPLIFY** | Replace body with a short stub (`<?php return [];` + 3-line pointer to `ai:discover` and the docs). Also fix `config/entities.php:443` "see config/ai-patterns.php". No tests depend on its content. |
| **2.A.12 RelatedModelSyncObserver** | Registered only by `AiServiceProvider::registerSyncObservers()` (728, 752-777), which returns early when `ai.sync_triggers` is empty. `config('ai.sync_triggers')=[]` in CondoEdge (tinker) and package default `[]` (config:291). After removal, also dead: `resolveModelClass()` (786), `ai.model_namespaces` (read only at provider:768 + observer:176), and `AiManager::syncRelationships()` (157; only caller is the observer; `SyncRelationshipsCommand` uses the ingestion service directly) + facade `@method` AI.php:16. | Host defines `sync_triggers` (host config:447) but empty. | No. | None today (never registered). Future need (re-sync a parent when a related model changes) is already covered by `ai:sync-relationships` and per-entity auto_sync. | **DELETE** | `src/Observers/RelatedModelSyncObserver.php` (197); provider 728 call + `registerSyncObservers` + `resolveModelClass`; config `sync_triggers` (291) + `model_namespaces` (298); optional `AiManager::syncRelationships` + AI.php:16; test `Unit/Observers/RelatedModelSyncObserverTest.php`; docs onboarding/00:74, 01:437, resources/docs configuration.md:357. Host Phase 3: drop the host `sync_triggers`/`model_namespaces` copies. |
| **2.A.15 Task-1.7 leftovers** | `TestQuestionCommand.php:115-119` prints `relevantEntities`, which is always `[]`. `TokenBudgeter::stats()` (29-~80, incl. example_entities accounting 71-78) has **0 src callers** (tests only), so the whole `stats()` is dead, not just the example part; after that and `confidence()`, the `$entityConfigs` ctor param is unused. `includeExamples`/`examplesPerLabel`: only docblocks (`ContextRetriever.php:227,244,478-482`, `ContextRetrieverInterface.php:18,38,51,57`, `AiManager.php:173`). `ExampleEntitiesSection` is still registered (config/ai.php:1672) but is a hard no-op. `rag.include_examples` config is dead. | 0. | No. | None. Keep `ExampleEntitiesSection` only if Phase 5 schema cards will replace it in place; otherwise delete it + the config line now. | **DELETE** | TestQuestionCommand 115-119; `TokenBudgeter::stats()` (+ `$entityConfigs` ctor + `new TokenBudgeter($this->entityConfigs)` at ContextRetriever:158); option docblocks listed; `PipelineContext::$relevantEntities` if no other reader (check before deleting); `rag.include_examples`, `rag.include_schema` config; tests `TokenBudgeterTest:41-58`, the `includeExamples`/`examplesPerLabel` BC tests in `ContextRetrieverTest` 309-429, 1281-1293 (and drop the options from `ContextRetrieverAnchorSeedTest:79`, `ContextRetrieverHybridRerankTest:247,274,299`); docs resources/docs usage/*.md. Optional: `ExampleEntitiesSection` + config line 1672 + `ExampleEntitiesSectionTest`. |
| **tests/Feature/** | `phpunit.xml` has testsuites **Unit** and **Integration only**. **No Feature suite (verified)**, so these 11 files never run in `vendor/bin/phpunit`. All `use Condoedge\Ai\...` imports still resolve (autoload check). | — | — | — | per file below | see below |

### tests/Feature/** file by file

| File | Tests | What it covers | Assessment |
|---|---|---|---|
| ActionApprovalRoundTripTest.php | 6 | approval gate end-to-end (P1-C2), config-registered mutating action | **Valuable**: add to a suite |
| ActionLinkIntegrationTest.php | 4 | ActionLinkHandler with mocks | **Valuable** |
| ActionLinkWiringTest.php | 10 | entity:// / action:// markdown link grammar through ActionLinkHandler + registry | **Valuable** |
| AiSystemFeatureTest.php | 28 | real OpenAI + Neo4j + Qdrant (skip-guarded); asserts `insights`/`visualizations` | **Stale/external**: delete, or move to a manual `live` group after 2.A.8 |
| ConversationContextFlowTest.php | 28 | multi-turn snapshot flow; 14 asserts on `last_insights`/`last_detected_template` (never written) | **Mostly stale**: delete with 2.A.7, or trim to entity/file-ref parts (overlaps `Integration/ConversationContextIntegrationTest`) |
| FileContextIntegrationTest.php | 20 | FileContextProvider → Section key contract, mocked storage (1 skip) | **Valuable** |
| FilePreviewIntegrationTest.php | 24 | citation slot/proxy structure | **Valuable** |
| HealthEndpointTest.php | 4 | GET /api/ai/health, mocked | **Valuable but duplicative** of `Unit/Http/HealthControllerTest`; merge or keep |
| MessagesQueryFileCitationTest.php | 4 | ContentLinkProcessor slot/proxy metadata | **Valuable** |
| NativeToolActionRoundTripTest.php | 6 | native tool loop round-trip | **Valuable while the tool loop exists** (A/B pending; follows that decision) |
| Commands/IngestPhysicalFilesTest.php | 19 | `ai:ingest --docs`, mocked stores | **Valuable** |

Recommendation: add a `Feature` testsuite (or move the 8 valuable files into Unit/Integration), delete AiSystemFeatureTest, and delete or trim ConversationContextFlowTest with 2.A.7.

## 2.A.10 Dead config keys (package `config/ai.php`, excluding `entities`, `query_patterns`)

289 leaves were scanned against `src/`, host `app/`, `routes/` and `resources/`. Results: 149 read directly; 113 under a parent-array read (resolved below); 3 dynamic; the rest dead or uncertain. The script also scanned `routes/api.php` by hand: `health.middleware` is **live** (routes/api.php:10), and `auto_discovery.role_mappings` is **live** (dynamic read TraversalScopeGenerator:333). Both were removed from the dead list.

### Definitively dead (no read of the key, and no whole-array consumer that indexes it)
- `auto_discovery.properties`, `.relationships`, `.scopes`, `.aliases`, `.embed_fields`, `.alias_mappings`, `.exclude_properties`. Discovery reads only `enabled`, `excluded_models`, `role_mappings`, and there is no `config('ai.auto_discovery')` array read.
- `access_control.default_threshold`, `.thresholds`, `.identifying_fields.*`. There is **no** `config('ai.access_control…')` read anywhere; `identifying_fields` appears only in comments. **Warning:** config and comments describe it as "the LLM PII-minimization list", but nothing enforces it. Deleting it loses nothing, and the gap is real. Flag to the user instead of deleting silently.
- `rag.vector_search_limit`, `rag.similarity_threshold`, `rag.include_schema`, `rag.include_examples`.
- `file_processing.supported_types`, `.chunk_size`, `.chunk_overlap`, `.preserve_sentences`, `.preserve_paragraphs`, `.default_search_limit`, `.min_search_score`. FileProcessor/SemanticChunker take these from `$options`/constants; there is no config read.
- `file_context.include_snippets` (only `FileContextConfigTest` asserts it).
- `query_generation.default_limit`, `.max_limit`, `.timeout`, `.template_confidence_threshold`, `.cache_ttl`. QueryGenerator reads `temperature`, `max_retries`, `allow_write_operations`, `scope_bypass_threshold`, `enable_templates`, `max_complexity` only.
- `query_execution.max_timeout`.
- `response_generation.temperature` (RG hardcodes `$options['temperature'] ?? 0.3`; env `AI_RESPONSE_TEMPERATURE` has no effect), `.include_insights`, `.include_visualizations` (both option-only), `.summarize_threshold`.
- `chat.persist_history`, `chat.max_messages`, `chat.session_key_prefix`, `chat.show_typing_indicator` (settings read `show_typing`), `chat.auto_focus`, `chat.enable_markdown`, `chat.enable_code_highlight`, `chat.floating.{position,offset_x,offset_y,size,style}`, `chat.modal.{size,full_screen}`, `chat.drawer.{position,width}`, `chat.welcome.{title,message}` (UserChatSettings reads flat `welcome_title`/`welcome_message`, so these nested keys are a silent no-op).
- `chat.system_prompt` (read into AiChatService config, then never used; see 2.A.2).
- Dies with its feature: `chat.tutorials.*` (2.A.3), `embedding.anthropic.*` (2.A.4), `relationship_weights.*` (2.A.9, only the dead `getRelationshipWeight` reads it), `sync_triggers` + `model_namespaces` (2.A.12).

### Uncertain / cosmetic
- `semantic_matching.enabled`, `.fallback_to_exact`, `.thresholds.{entity_detection,scope_detection,template_detection,label_inference}`, `.collections.{entities,scopes,templates}`, `.cache_embeddings`. They are only **printed** by `IndexSemanticCommand` status output (`config('ai.semantic_matching')`, lines 182-194), so they have no behavioral effect. Meanwhile the code that matters reads **undefined** keys `semantic_matching.scope_threshold` / `.max_scopes` (EntityMetadataBuilder:98-99). This is a mismatch. Fix it by renaming or deleting, and decide which side wins.
- `auto_sync.operations.{create,update,delete}`: live through a dynamic read (HasNodeableConfig:101).
- The remaining ~86 parent-read keys (`graph.neo4j.*`, `vector.qdrant.*`, `llm.*`, `embedding.openai.*`, `eval.*`, `resilience.llm.*`, `query_execution.*` except max_timeout, `semantic_context.enabled/dimension`, `scope_matching.threshold`, `digest.*`, `export.auto_identity_fields.sources.*`) are consumed by array index in their readers. Treat as live.

### Read in src but defined nowhere (package or host config; code default applies)
`ai.query_generation.templates` (IndexSemanticCommand:119), `ai.ingestion.memory_limit` (IngestEntitiesCommand:75, SyncRelationshipsCommand:72), `ai.chat.page_examples.{type}` (WelcomeSuggestions:70; parent `chat.page_examples` is defined), `ai.chat.enable_animations`, `ai.chat.animation_speed`, `ai.chat.typing_animation_style` (AiUserSetting:43-45 + UserChatSettings dynamic), `ai.file_context.team_grain_unsafe` (FileAccessScopePlugin:52, **security toggle**, default false), `ai.export.pdf_document_styles`, `ai.export.redact_rows`, `ai.export.internal_columns`, `ai.export.internal_patterns` (ResultSetExporter / ExportResponseComposer), `ai.semantic_context.always_include_identity_labels` (ContextRetriever:719, ExportResponseComposer:144), `ai.schema_cache_ttl` (SchemaInspector:606), `ai.security.enforce_scopeable_subset` (TenantScopingRulesSection:144, QueryGenerator:359, SemanticPromptBuilder:443, **security toggle**, default true), `ai.query_parameters` (QueryParameterizer:24/69/134), `ai.semantic_matching.scope_threshold`, `ai.semantic_matching.max_scopes` (EntityMetadataBuilder:98-99), `ai.modules.{Class}` (HasInternalModules:97 fallback, unused because both users set `$defaultModulesConfigKey`).

The other `'ai.x'` strings the scan found (`ai.common.*`, `ai.messages.*`, `ai.settings.*`, `ai.edit.*`, `ai.error.*`, `ai.insight.*`, `ai.response.*`, `ai.clarify.*`, `ai.help.title`, `ai.chat.screen`/`thinking`/`yesterday`, `ai.actions.confirm/cancel/failed`, `ai.digest.truncation_footer`) are translation keys or route names, not config.

## Counts
- Candidates reviewed: 14 (2.A.1-2.A.9 split into 9a/9b/9c, 2.A.11, 2.A.12, 2.A.15; 2.A.10 separately) + tests/Feature.
- DELETE: 11 (2.A.1, 2.A.2 Send+Regenerate, 2.A.3, 2.A.4, 2.A.5, 2.A.6, 2.A.7, 2.A.8, 2.A.9a, 2.A.12, 2.A.15).
- SIMPLIFY: 2 (AiChatService, package entities.php).
- KEEP: 2 (keywordBasedSelection is a live fallback; calculateConfidence is live metadata). The plan labelled both dead, and both claims are wrong.
- Dead config leaves: 51 definitive (auto_discovery 7, access_control 3, rag 4, file_processing 7, file_context 1, query_generation 5, query_execution 1, response_generation 4, chat 19) + 4 feature-coupled groups (tutorials, embedding.anthropic, relationship_weights, sync_triggers/model_namespaces); 10 uncertain (semantic_matching display-only); 18 keys read but defined nowhere.
- tests/Feature: 11 files, 0 run (no suite). 8 valuable, 1 duplicative, 2 stale.

## 98. Unit-test baseline

Recorded 2026-09-29 (per directory, package root, before any change):
- Green: Auth 12, Config 7, Console 35, Contracts 1, Domain 86, DTOs 42, EmbeddingProviders 44, GraphStore 15,
  LlmProviders 85, Models 14, Observers 7, Policies 10, Resilience 19, Security 30, StressTests 47 (1 skipped),
  Support 13, VectorStore 5, root files (ActionI18nKeys, ServiceProviderRoleWiring, Facade, ServiceProviderFileContext,
  ServiceProviderRegistration) all OK.
- `tests/Unit/Services`: 1834 tests, **19 errors**:
  - 14 = Windows testbench flake `rename(...bootstrap/cache/ser*.tmp → services.php): Access is denied` (ConversationResumeBuilder,
    RegenerateAnswerUseCase ×3, AugmentationAnchorSnapshot ×3, ConversationContextManager ×6, PhysicalFileIndexer). Re-running
    Chat+Context dirs gave 58 flake errors — environment, not code → Task 0.6.
  - **5 = REAL: `TenantScopingLeakTest` (call_subquery_invoice, nested_call_invoice, label_free_hop_reuse,
    aliased_collected_in_call, call_with_team_wrapper) — test indexes a `QueryExecutionResult` DTO as an array (line 140),
    so these security leak shapes are currently NOT verified** → Task 1.20.
- `tests/Unit/Kompo/*`: cannot load (package vendor `condoedge/utils` lacks `ChatMessagesQuery`).

## 99. Progress log

- 2026-09-29 — plan written; OpenAI pre-flight 200 (quota restored).
- 2026-09-29 — Execution started (subagent-driven, this session). 0.1+0.2 done. 0.3 baseline eval running (storage/eval/base-0929-A/B.json in CondoEdge). 0.6 dispatched (test-infra only while eval runs). 0.5 deferred until eval finishes (MySQL delete would auto-sync the graph mid-run).
- 2026-09-29 — 0.3 done (base-0929-A 13 rows, B 14 rows; 1 gate fail french-flow t1 produces_file; B had a Neo4j exec error on 'my contribution this year'). 0.5 done: meeting 146 'test' (team 3, union 5, created 2026-07-23) SOFT-deleted via Eloquent (reversible; ids 74/115 'test' belong to other teams, untouched). Grading 0.4 dispatched (2 graders).
