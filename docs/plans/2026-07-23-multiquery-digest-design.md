# Multi-Query Attention Digest — v1 Design

**Date:** 2026-07-23
**Status:** APPROVED WITH CHANGES (review verdict 2026-07-23) — amendments B1 (combined digest+export turn) and B2 (cap reconciliation + structural overflow visibility) applied in place below, plus the review's non-blocking notes (no-skip_security test, single-statement fallback-error semantics, line-ref corrections, shared-rate-limiter concurrency caveat).
**Feature:** "What should I take care of next?" — one turn, many canonical sources, one grounded digest answer. Plus config-declared fallback queries ("no upcoming meetings — the most recent one was …").

Inputs: the 2026-07-23 digest content survey (14 candidate sources probed against the dev DB, team 3) and a code-index verification pass of every integration seam cited below. All file/line references verified today against the package at `C:/Users/jkend/Documents/Projects/kompo/ai` (ns `Condoedge\Ai`).

---

## 0. Design principles (what "we should get", per owner directive)

1. A management lead asking "what's next / what needs attention" gets ONE answer that walks the workspace's attention surfaces in lead-value order — urgent tasks before insurance lapses before stale quotes — with real rows, action links, and honest empties.
2. The digest is **deterministic config-driven DB work**. The only intelligence is (a) the QU LLM judging that the turn IS a digest ask, and (b) the response LLM rendering rows it was handed. No LLM plans queries, no LLM summarizes per source. LLM budget for a digest turn: **QU + composition = 2 calls** — one FEWER than a normal data turn (QU + query-gen + response).
3. **White-label rule:** the package ships the mechanism (registry reader, runner, composer, QU signal). Every CondoEdge specific — labels, enum literals (`status = 2` = APPROVAL_SENT), cypher, ordering semantics — lives in the HOST's published `config/ai.php` under `ai.attention_sources`. Same split as `ai.calculations` (package `config/ai.php:1503`, rendered by `CalculationsSection`).
4. **Security invariant:** every source query executes through `QueryExecutorInterface::execute()` with the SAME `$options` (same `user`, same `conversation_id`, same read-only default) the main pipeline builds — so tenant scoping (`QueryExecutor::applySecurityFilter`, `QueryExecutor.php:634`), the read-only gate (`QueryExecutor.php:132`), and rate limiting (`QueryExecutor.php:126`) apply identically per source. A loop that bypasses any of that is a critical defect, not a perf optimization.
5. **Honesty rubric applies per source:** a section with nothing due says so; capped sections disclose the true total (reuse the companion-count pattern, `AiManager::companionTrueTotal`, `AiManager.php:497`); a failing source renders as an error line, never as silent absence and never as digest death.

---

## 1. CONCEPT — host-config registry `ai.attention_sources`

### 1.1 Schema

New top-level config key, sibling of `ai.calculations` / `ai.generic_actions`. Package `config/ai.php` ships it EMPTY with a doc block (examples only, no CondoEdge semantics — white-label rule). Array order = render order = lead-value ranking.

```php
'attention_sources' => [
    '<source_key>' => [
        // Required
        'label'       => 'Open and urgent tasks',        // section heading, host language
        'description' => 'Tasks not yet closed; urgent first, then stale-first.',
            // one sentence of intent — rendered into the composition prompt so the
            // response LLM knows what the section MEANS (and, in v2, into the QU
            // catalog for normal-path fallback matching; see §5)
        'cypher'      => 'MATCH (t:Task) WHERE ... RETURN ... ORDER BY ...',
            // canonical scoped query. Authoring rules in §1.2. NO trailing LIMIT —
            // the runner passes the per-source cap via $options['limit'] and the
            // executor appends it (QueryExecutor.php:139).

        // Optional
        'fallback_cypher' => null,   // run ONLY when primary returns 0 rows (§5)
        'fallback_label'  => null,   // e.g. 'Most recent meetings (none scheduled)' —
                                     // REQUIRED when fallback_cypher is set: fallback
                                     // rows must never masquerade as primary rows
        'limit'       => 5,          // per-source row cap; default ai.digest.default_row_cap
        'action_key'  => 'tasks',    // generic action for the section link:
                                     // [label](action://tasks) — resolved by
                                     // MessageBubbleRenderer (action:// / entity://
                                     // parsing, MessageBubbleRenderer.php:276)
        'entity'      => 'Task',     // graph label; enables per-row entity-action
                                     // enrichment via the existing
                                     // AiManager::enrichExecutionResult seam
                                     // (AiManager.php:1168)
        'empty_text'  => null,       // null  => an empty source collapses into the
                                     //          combined "nothing pending in: …" line
                                     // string => always render the section with this
                                     //          note (for sources whose emptiness is
                                     //          itself the news)
        'enabled'     => true,       // ship-but-hold switch (see maintenance_dates, §1.3)
    ],
],

'digest' => [
    'max_sources'     => 8,   // hard cap on sources executed per digest run (package default;
                              // the CondoEdge host sets 9 to run all nine enabled entries — §1.3/B2).
                              // Overflow is never silent: skipped keys are recorded in
                              // metadata.digest.skipped_sources and a warning is logged.
    'default_row_cap' => 5,   // per-source row cap when the entry omits 'limit'
],
```

### 1.2 Canonical-cypher authoring rules (host-facing, documented in the config doc block)

These exist because the executor's structural scope analyzer + sensitive-label coverage gate (`QueryExecutor.php:656-679`) must be able to PROVE each query scoped; an unprovable query safe-stops to an empty result (fail-closed, `QueryExecutor.php:106`) — which for a digest renders as an honest per-source error/empty, never a cross-tenant leak.

1. **Stay inside the scopeable subset**: single primary labeled binding, plain `MATCH`/`OPTIONAL MATCH` hops, no `UNION`, no `CALL {}` subqueries, no label-free hops to sensitive nodes. The scope analyzer covers exactly this shape; the coverage gate safe-stops anything it can't prove.
2. **Parameters**: none beyond the `$ai_team_ids` convention. The security layer binds `$ai_team_ids` from the REAL pipeline user (`$options['user']`, `QueryExecutor.php:654`) and merges it into the parameters (`QueryExecutor.php:119-123`). Authors MAY write the tenant predicate explicitly (the same per-label predicate `TenantScopingRulesSection` declares) or omit it and let the filter inject — either way the query executes through the identical security path as every generated query. Never inline team ids.
3. **Dates are STRINGS in the graph** (survey finding #1 — `x < datetime()` silently returns 0 rows; verified 0 vs 765 expired units). Every date comparison MUST be against `toString(datetime())` / `toString(date())`. **Companion fix shipped with this feature:** the `team_total_owed` calculation note in the host config recommends `i.due_at < datetime()` — that latent bug gets corrected to the string-compare form in the same host-config PR.
4. **No trailing LIMIT** — the runner owns the cap so the companion true-total count can strip/skip it uniformly.
5. **RETURN discipline**: return ONLY the fields the section renders (survey column "fields a lead needs") plus the entity id needed for `entity://` links. Small rows keep the single composition prompt small.
6. Always guard `deleted_at IS NULL` where the label soft-deletes.

### 1.3 CondoEdge v1 entries (host `config/ai.php`)

Chosen from the survey's ranked table. Honest-empty-today sources are DELIBERATELY included — the registry describes what a lead cares about, not what the dev DB happens to contain; the behavior must be right the day data appears. Order = survey rank.

**Cap reconciliation (amendment B2):** the table below ships NINE enabled entries, so the HOST config sets `ai.digest.max_sources = 9` — every enabled entry runs; nothing is silently dropped. Independently of that reconciliation, cap overflow is now STRUCTURALLY VISIBLE: when enabled sources exceed `max_sources`, the runner records the skipped keys (surfaced as `metadata.digest.skipped_sources`) and logs a warning, so graders and the host author can always tell a capped-out source from a never-configured one (`DigestRunnerTest` covers it).

| key | survey # | live today (team 3) | fallback | notes |
|---|---|---|---|---|
| `open_tasks` | 1 | YES — 130 open / 10 urgent | — | `ORDER BY t.urgent DESC, t.updated_at ASC`; direct `team_id`; action `tasks`, entity `Task` |
| `bills_pending_approval` | 2 | honest-empty (0) | — | `status = 2` (APPROVAL_SENT); action `bills_table` (boardOrStaff), entity `Bill` |
| `bills_approved_unpaid` | 2 | YES — 9 | — | `status = 3` (PAYMENT_APPROVED) — a distinct attention item, not a fallback of the previous |
| `overdue_receivables` | 3 | honest-empty (0) | — | reuse `team_total_owed` semantics with the string-date overdue split; action `report_receivables`, entity `Invoice` |
| `upcoming_meetings` | 4 | honest-empty (0 anywhere) | **YES** — 3 most recent past, `fallback_label` "Most recent meetings — none scheduled" | the flagship fallback case; action `meetings`, entity `Meeting` |
| `expired_unit_insurance` | 6 | YES — 189 | — | string-compare `insurance_term < toString(date())`; action `units`, entity `Unit` |
| `pending_contact_reviews` | 7 | YES — 13 | — | `reviewed_at IS NULL`; direct `team_id`; **action gap** (survey finding #4): nearest is `contacts` — v1 ships `action_key => 'contacts'`; a dedicated generic action is a 1-line host addition recommended in the same PR |
| `flagged_inbox_threads` | 8 | YES — 36 flagged | — | `flag_color IN [2,3,4]`; direct `team_id`; action `inbox`, entity `Thread` |
| `work_orders_awaiting_acceptance` | 9 | YES — 8 | — | `sent_at IS NOT NULL AND accepted_at IS NULL AND rejected_at IS NULL`; action `suppliers`, entity `WorkOrder` (the rows ARE WorkOrder rows — labeling them `Project` would mis-target row enrichment; scoping rides the configured `quoteRequest.project.team_id` path) |
| `maintenance_dates_unconfirmed` | 5 | **`enabled => false`** | — | THE strongest live source (82 upcoming, all unconfirmed) but `MaintenanceDate` has ZERO graph edges and no team/union property (survey finding #2) → cannot be tenant-scoped in the graph. Security invariant wins: ships disabled with a comment pointing at the W7 edge re-sync; flipping `enabled` is the only change needed after re-ingest. The coverage gate would safe-stop it anyway — this makes the intent explicit instead of relying on the gate. |
| `maintenance_contracts_lapsed` | 11 | 10 (all lapsed) | — | `enabled => false` for the same W7 union-hop reason (scoping via `Maintenance` hop unverified in graph) |

Enum literals (Bill 2/3/6, Task 1, Thread flags 2/3/4, MaintenanceDate 1/2/3) appear ONLY in these host entries, sourced from the survey's model-constant verification — never in package code (survey finding #3, hard rule 3).

Dropped (survey): recurrences, visits, union_contracts (label absent), unread notifications (per-user noise), reservations/events (Reservation label absent; events stale — revisit at re-ingest), announcements & team invitations & QRs-in-flight (below the 8-source cap in lead value; can be appended later — the registry is open-ended).

---

## 2. INTENT — QU emits `digest_request` (same single call)

### 2.1 Emission

`QueryUnderstandingService::buildMessages` (`QueryUnderstandingService.php:115-155`) gains one key in the JSON contract, concept-described exactly like `export_format` (line 138) and `follow_up_reference` (line 140) — the LLM judges the concept; no keyword lists anywhere (hard rule 3):

> `"digest_request"`: boolean. True ONLY when the user asks BROADLY what needs their attention across their workspace — what to take care of next, what is coming up, what is pending or overdue overall — WITHOUT restricting the question to a single topic or domain. Judge the scope semantically, in any language or phrasing. False when the question targets one specific subject (one entity type, one record, one metric), even if urgency-flavored ("which bills are overdue?" is a normal question, not a digest), and false for follow-ups that operate on a previous answer.

The key is emitted only when the host has configured a non-empty `ai.attention_sources` — same conditional-prompt-line pattern as `translationInstruction()` (zero tokens, zero behavior change for hosts without the feature).

### 2.2 Parsing + DTO

- `QueryUnderstandingResult` (`src/DTOs/QueryUnderstandingResult.php`) gains `public readonly bool $digestRequest = false` with a doc block mirroring `followUpReference`'s.
- `QueryUnderstandingService` parse site (~line 316, next to `exportFormat`/`followUpReference`): `digestRequest: (bool) ($data['digest_request'] ?? false)`. Absent/invalid → false → normal pipeline (fail-safe).

### 2.3 Routing in `AiManager::answerQuestion` (`AiManager.php:560`)

Precedent: `pageNarrationResponse` (`AiManager.php:582-585`) — an early-return branch after context retrieval, before file search. The digest branch slots in immediately after it:

```
$ctx = $this->context->retrieveContext($question, $options);
... conversation/page context merge ...
pageNarrationResponse? -> return
digestResponse($ctx, $question, $options)? -> return      // NEW
$ctx = $this->retrieveAndSetFileContext(...)               // digest turns never reach this
$ctx = $this->generateAndExecute(...)
```

`digestResponse()` returns non-null (and owns the turn) only when ALL hold:

1. `$ctx->qu->digestRequest === true`;
2. `$ctx->qu->followUpReference === false` (a follow-up on a previous result is never a digest);
3. `config('ai.attention_sources')` has at least one enabled entry.

Consequences, by construction:

- **Normal query generation is SKIPPED entirely** — no `generateAndExecute`, no query-gen LLM call. The deterministic source loop replaces it.
- **File search is SKIPPED** — the branch returns before `retrieveAndSetFileContext` (same structural position that makes page narration skip it). A digest is a data walk; document retrieval adds latency and nothing else. (This is stronger than the config-driven `shouldSkipFileSearchFor` skip at `AiManager.php:655` — the digest never consults it.)
- QU false negative → the turn runs the normal pipeline and the generator does its best single-query answer: degraded, not broken. QU false positive → the user asked something broad-ish and gets a well-formed attention digest: mildly over-eager, not wrong. Both failure modes are acceptable; neither fabricates.
- The catch-all error path in `answerQuestion` (`AiManager.php:593-617`) already wraps the branch — a digest that throws unexpectedly degrades to the language-aware canned error like any other turn.

### 2.4 Combined digest + export turn (amendment B1)

QU emits `export_format` independently of `digest_request` (`QueryUnderstandingService.php:138` vs the new key), so "give me an Excel of everything needing my attention" yields `digestRequest = true` AND `exportFormat = 'excel'`. Because the digest branch early-returns BEFORE `buildFinalResponse` — where export intent is normally resolved (`resolveExportIntent`) — leaving this unspecified would silently drop the export ask. **v1 behavior, specified:**

- The digest still runs and owns the turn (the export ask never reroutes it to the normal pipeline — the underlying data question IS the digest).
- `DigestResponseComposer` is TOLD about the export ask: it must (a) explicitly acknowledge it, stating the file is available as an immediate follow-up ("say 'export that'" / click the export link on the next turn — the `data` write-back of §4.3 already makes that follow-up export work on the recorded digest rows), and (b) make that acknowledgment the turn's SINGLE deepening offer (replacing the usual free-choice offer — one offer, not a menu, per §4.1).
- Structural visibility for graders: `metadata.digest.export_followup_offered = '<format>'` is stamped on combined turns, so the trace proves the ask was acknowledged rather than dropped.
- The gold suite carries a combined-ask turn (turn 3 of the `whats-next-digest` conversation) pinning this behavior.
- In-turn file generation (routing the source-keyed rows through the exporter directly) is a v2 option; v1 deliberately keeps the digest turn to QU + ONE composition call.

---

## 3. EXECUTION — `DigestRunner`

New package service `src/Services/Digest/DigestRunner.php`, constructor-injected with `QueryExecutorInterface` (bound in `AiServiceProvider`). Deterministic; makes ZERO LLM calls.

```php
class DigestRunner
{
    // AttentionSourceRegistry is the config reader (ai.attention_sources +
    // ai.digest, lazily read so a singleton always sees live config). It also
    // answers hasEnabled() / sourcesToRun() / skippedSourceKeys() — the B2
    // overflow visibility is registry data, consumed by AiManager/composer.
    public function __construct(QueryExecutorInterface $executor, ?AttentionSourceRegistry $registry = null) {}

    public function registry(): AttentionSourceRegistry;

    /** @param array $execOptions the SAME options AiManager threads to every execution */
    public function run(array $execOptions): array  // list<DigestSourceResult>
}
```

`AiManager::digestResponse` builds `$execOptions` EXACTLY like `generateAndExecute` does (`AiManager.php:1125-1128`): `$options + ['original_question' => $question, 'conversation_id' => ...]` — so `$options['user']` (the resolver-correct pipeline user, preferred over `auth()->user()` at `QueryExecutor.php:654`), read-only default, and provenance logging are all identical to a normal turn. **This is the security invariant made structural: the runner has no path to Neo4j except `execute()` with those options.** Hardening (review note): the executor honors `$options['skip_security']` (`QueryExecutor.php:639`); the runner therefore STRIPS that key from whatever options it receives before every execution, and `DigestRunnerTest` asserts the executor never sees it — the invariant holds even against a poisoned options array.

Per enabled source (config order, hard-capped at `ai.digest.max_sources`; enabled entries beyond the cap are SKIPPED with their keys recorded — exposed via `AttentionSourceRegistry::skippedSourceKeys()`, surfaced in `metadata.digest.skipped_sources`, and logged as a warning by the runner; a host-disabled entry (`enabled => false`) is deliberate configuration and is NOT listed as skipped):

1. `$result = $executor->execute($source['cypher'], [], $execOptions + ['limit' => $sourceCap])` — the executor appends `LIMIT`, applies the security filter (inject or validate + bind `$ai_team_ids`), enforces read-only, counts against the shared rate limiter.
2. **True-total honesty** (same trigger discipline as `AiManager::companionTrueTotal`, `AiManager.php:497-526`): when `count(rows) === $sourceCap`, run `$executor->executeCount($source['cypher'], [], $execOptions)` — the canonical cypher carries no LIMIT, so no stripping needed. Count failure → total unknown → the composer hedges ("more may exist"), never fails the source. (v1 duplicates this ~10-line trigger logic in the runner rather than refactoring `AiManager`'s private helper; extracting a shared `TrueTotalResolver` is a v2 cleanup noted in §7.)
3. **Fallback slot**: primary succeeded with 0 rows AND `fallback_cypher` set → execute the fallback through the identical path (same options, same cap, own try/catch). Result is stamped `usedFallback = true` and rendered under `fallback_label`, never under the primary label (§5).
4. **Error semantics — stated ONCE, here (review note; §5.1 defers to this):**
   - a failing PRIMARY (throw, execution error, or coverage-gate safe-stop) records `error` on the source; it renders as one honest per-source error line ("this source could not be checked right now") — never as honest-empty, never as digest death; remaining sources still run;
   - a failing FALLBACK (the primary already succeeded with 0 rows) records `fallbackError` — the source renders as the PRIMARY's honest-empty (which is the truth) plus a small "recent-history context could not be loaded" note; it is never presented as a failed section.
   The per-source try/catch wraps the whole of steps 1-3.
5. **Entity-action enrichment**: for sources declaring `entity`, `AiManager` passes each source's `QueryExecutionResult` through the existing public seam `enrichExecutionResult()` (`AiManager.php:1168`) so rows carry `has_actions` / `available_actions` exactly like normal-turn rows — per-row `entity://` links come for free in composition.

Result DTO `src/DTOs/Digest/DigestSourceResult.php`:

```php
{ key, label, description, rows: array, rowCount: int, trueTotal: ?int,
  capHit: bool, usedFallback: bool, fallbackLabel: ?string, actionKey: ?string,
  entity: ?string, emptyText: ?string, error: ?string, fallbackError: ?string }
// capHit = the row cap was reached; with trueTotal null the composer hedges "more may exist".
```

**Budget/latency check:** worst case = 8 primaries + 8 companion counts + a few fallbacks ≈ ≤ 20 sequential executor round-trips at ~tens of ms each — well under a normal turn's LLM latency. Rate-limiter note: the shared `query_executor` limiter defaults to 30/min (`QueryExecutor.php:64-68`, `ai.rate_limits.queries_per_minute`); one digest turn can consume ~half that window. Mitigation in §6 risks.

---

## 4. COMPOSITION — one response-LLM call

New `src/Services/Response/DigestResponseComposer.php`, modeled on `ExportResponseComposer` (same "composer owns a special final response" pattern consumed by `buildFinalResponse`, `AiManager.php:1194`; public seam `composeResponse` at `AiManager.php:1186`). The digest branch calls it directly from `digestResponse()`; it makes exactly ONE LLM call.

### 4.1 Prompt (deterministic assembly)

Per source, in registry order, a compact block:

- `label` + `description` (what the section means);
- rows as a compact table (the RETURN-disciplined fields);
- `showing X of Y` when `trueTotal > rowCount` (Y = trueTotal), or `showing X` (complete) otherwise;
- `usedFallback` → the block is headed by `fallback_label` and carries an explicit instruction: these are NOT the asked-for items; present them as recent history with the honest "none scheduled/pending" statement first;
- `error` → block reduced to "this source could not be checked right now" (no rows, no fabrication);
- available links: `[{$label}](action://{$action_key})` for the section, and per-row entity actions from enrichment — same `action://` / `entity://` markdown contract `MessageBubbleRenderer` already resolves (`MessageBubbleRenderer.php:276`; format documented at `config/ai.php:1412,1466`).

Rendering instructions (concept-level, in the composer's system prompt):

- group by source, registry order, section headings = labels, answer in the QUESTION's language (`$ctx->qu->language` — same language policy as error paths, `AiManager.php:1296-1304`);
- **honest-empty collapse**: every source with 0 rows, no fallback rows, no error, and `empty_text === null` is collapsed into ONE line — "Nothing pending in: bills awaiting approval, overdue receivables." Sources with `empty_text` render their configured note as their section;
- never invent rows, totals, or urgency not present in the blocks;
- proactivity culture: end with exactly ONE deepening offer, chosen from the rendered sections (e.g. "want the full urgent-task list?") — one, not a menu;
- **combined digest+export turn (B1, §2.4)**: when the turn also carried an export ask, the composer's instructions replace the free-choice deepening offer with the export acknowledgment — state that the file of everything just shown is available as an immediate follow-up ("say 'export that'"), as the turn's single offer.

### 4.2 Deterministic truncation guard (reuse of the pattern, not the code path)

`ResponseGenerator`'s truncation honesty is single-result-shaped (`truncationHonestyApplies` / `enforceTruncationDisclosure` / blocking re-assert, `ResponseGenerator.php:253-335,500`). The digest composer applies the SAME blocking discipline per source: after the LLM returns, for every source where `trueTotal > rowCount`, verify the answer discloses that source's true total (digit presence check, same technique); any miss → deterministically append a one-line footer "Tasks: showing 5 of 130." and stamp `metadata['digest_truncation_appended']`. Disclosure is never left to LLM goodwill.

### 4.3 Response shape + trace/eval

The composer returns the standard response array (`question, answer, insights, visualizations, cypher: null, data, stats, referenced_files: [], metadata`), with:

```php
'metadata' => [
    'digest' => [
        'sources' => [                       // one entry PER CONFIGURED-AND-RUN source
            ['key' => 'open_tasks', 'row_count' => 5, 'true_total' => 130,
             'used_fallback' => false, 'error' => null],
            ...
        ],
        'empty_sources'  => ['bills_pending_approval', 'overdue_receivables'],
        'failed_sources' => [],
        'skipped_sources' => [],             // B2: enabled entries dropped by max_sources — loud, never silent
        // present ONLY on combined digest+export turns (B1): proves the export
        // ask was acknowledged as the follow-up offer instead of silently dropped
        // 'export_followup_offered' => 'excel',
    ],
    ...
]
```

**EvalRow needs NO schema change**: response metadata already rides into the serialized trace verbatim (`EvalRow::$responseMetadata` → `toArray()['trace']['response_metadata']`, `EvalRow.php:82,226`) — the same channel the export markers use (`EvalRowTraceTest.php:35`; "riding the metadata keeps AiManager untouched", `ResponseGenerator.php:368`). Out-of-process graders verify per-source row counts, honest-empty collapse, fallback labeling, truncation disclosure, skipped sources (B2) and the combined-turn export acknowledgment (B1) from the trace alone. v1 adds ONE 3-turn digest conversation to `tests/eval/gold-conversations.json` (`whats-next-digest`: turn 1 the digest ask; turn 2 "when was our last meeting then?" — a single-domain probe of the fallback data that must NOT trigger the digest; turn 3 the B1 combined ask "give me an excel of everything needing my attention"), with standards written against `response_metadata.digest`. The suite's existing single-domain urgency questions (supplier-payables, owner-arrears-pivot) double as negative digest controls. A dedicated `ExpectationGate` digest gate is v2.

`data` carries the concatenated per-source rows (source-keyed) so the conversation write-back records a real `last_result_sample` — "export that as an excel" after a digest follows the existing previous-result export path unchanged.

---

## 5. FALLBACK QUERIES — the second driver

### 5.1 In the digest (ships in v1)

Config slot `fallback_cypher` + mandatory `fallback_label`, executed ONLY when the primary succeeded with 0 rows (§3 step 3). Flagship: `upcoming_meetings` → 3 most recent past meetings, labeled "Most recent meetings — none scheduled" (dev DB reality: latest meeting anywhere is 2023-12-14; team 3's is 2023-10-24 — the fallback is what makes that section useful instead of a bare zero). Honesty invariants: fallback rows render under `fallback_label` only; the "none scheduled" statement comes FIRST; a fallback source still counts as empty for the digest's "is anything pending" framing (it is context, not a pending item). Fallback-error semantics: defined ONCE in §3 step 4 (a failed fallback renders as the primary's honest-empty plus an error note, never as a failed section).

### 5.2 In the NORMAL single-question path — explicitly DEFERRED to v2

**Decision: defer. Rationale (not hand-waved):**

The trigger would be: a normal turn's generated query returns 0 rows AND the question "maps to" an attention source that declares a fallback. The only mapping available today without a new LLM judgment is structural — QU `entity_types` ∩ source `entity` (e.g. Meeting↔`upcoming_meetings`). That mapping is dishonest at the exact moment it fires: zero-row Meeting questions include "meetings attended by John in 2022", "board meetings at building X in March" — bolting "here are the 3 most recent meetings" onto those answers presents data the user did not ask for under the question's framing. That is the same defect class as the previous-result export misfire this codebase already fought (re-anchored anaphora exporting the wrong rows — `AiManager.php:1216-1220`): plausible-looking, wrong-provenance data. The honest-zero rubric ("no meetings matched") beats a confidently mislabeled fallback.

Doing it honestly requires a SEMANTIC match: "this normal question IS this source's canonical question." That judgment belongs to the QU LLM (hard rule 3) and costs nothing extra structurally: **v2 mechanism (sketched, not hand-waved):** render the enabled sources' `key + description` into the QU prompt as a catalog (exactly how the entity catalog is rendered today, `QueryUnderstandingService.php:124-125`) and add one key — `"attention_source_hint": key-or-null, set ONLY when the question asks precisely what that source's description describes`. Same single QU call, zero added calls. Normal path then: query ran, 0 rows, `attention_source_hint` set, that source declares a fallback → run it through the executor with the turn's options and hand it to the response prompt as clearly-labeled fallback context. Deferred only because it needs its own eval evidence (hint precision on the gold suite) before it can be trusted — not because the design is unknown.

---

## 6. RISKS & REJECTED ALTERNATIVES

### Rejected

1. **LLM-planned multi-query** (agent decides which queries to run, possibly iterating): rejected for v1. (a) Budget — each planning round is an LLM call; violates the "digest turn ≤ normal turn" rule. (b) Security surface — dynamically planned cypher multiplies the scope-analyzer/coverage-gate attack surface exactly where the tenant-scoping architecture is known to be fragile (post-hoc injection on complex shapes); config-declared canonical queries are reviewed once and stay inside the provably-scopeable subset. (c) Determinism — eval graders can verify a fixed registry; a planner's output varies per run. (d) The survey shows the value is in a KNOWN, ranked attention surface — planning adds cost precisely where curation already answered the question.
2. **Per-source LLM summarization** (summarize each source, then stitch): rejected — 8+ LLM calls per turn versus the budget rule; and stitching de-contextualized summaries loses the cross-source ranking that IS the digest's value.
3. **SQL-executor side path for graph-unscopeable sources** (MaintenanceDate today): rejected for v1 — a second execution path would need its own tenant-scoping proof, duplicating the security-critical machinery for one source. The source ships `enabled => false` until the W7 edge re-sync makes it graph-scopeable (§1.3).

### Risks

| risk | mitigation |
|---|---|
| Rate-limiter budget: one digest ≈ up to ~16-20 of 30/min shared executor attempts | `max_sources` cap + counts fire only on cap-hit (rare at cap 5) + per-source catch renders a rate-limited source honestly; host may raise `ai.rate_limits.queries_per_minute`; per-caller limiter split is a v2 option |
| **Shared-limiter CONCURRENCY (review note): the `query_executor` limiter key is global, not per-user** — one digest turn's ~16-20 attempts can starve CONCURRENT users' normal turns in the same 60s window, not just the digest user's own | acknowledged as a real multi-user cost, not just a self-inflicted one: per-source catch keeps every starved turn honest (error line, not silence); hosts with concurrent chat load should raise `queries_per_minute` when enabling the digest; the per-caller limiter split is the v2 fix and is prioritized accordingly |
| String-date bugs in host-authored cypher silently emptying a section | authoring rule §1.2.3 + the config doc block shows the `toString()` form; eval conversation with a known-nonempty source catches regressions; `team_total_owed` latent bug fixed in the same PR |
| Graph staleness (103 vs 145 meetings; threads stale) — digest presents stale data confidently | inherited pipeline-wide W7 issue, not digest-specific; digest adds no new staleness; re-ingest is tracked separately; composition never claims real-time freshness |
| QU misclassification | both failure directions degrade safely (§2.3); the negative eval conversation (single-domain urgency question) guards the false-positive rate |
| A slow source (unindexed predicate) stretching the loop | executor timeout applies per source (default 30s, `QueryExecutor.php:84`); per-source catch converts a timeout into an error line; canonical queries are simple single-label scans |
| Coverage-gate safe-stop on a mis-authored query rendering a permanently "empty" section | safe-stop metadata (`safe_stop => true`) is visible in the per-source result — runner maps it to an error line ("could not be checked"), NOT to honest-empty, so misconfiguration is loud |
| Prompt bloat from 8 sources × 5 rows | RETURN discipline (§1.2.5) + caps keep the block ≈ 40 small rows — comparable to one normal 100-row turn today |

---

## 7. V1 SCOPE LINE

**Ships now (v1):**

- Package: `ai.attention_sources` + `ai.digest` config schema (empty, documented) in package `config/ai.php`; `QueryUnderstandingResult::$digestRequest` + QU prompt line (conditional on configured sources) + parse; `DigestRunner` + `DigestSourceResult` (incl. `skipped_sources` visibility, B2); `DigestResponseComposer` (one LLM call, honest-empty collapse, per-source truncation guard, `metadata.digest`, B1 export acknowledgment); `AiManager::digestResponse` routing branch (skips file search + query gen); provider bindings. TDD throughout; explicit-path tests: `DigestRunnerTest` (loop, caps + skipped_sources, per-source catch, fallback trigger, safe-stop mapping, options passthrough incl. `user`, **never passes `skip_security`**), `QueryUnderstandingDigestRequestTest`, `DigestResponseComposerTest` (collapse, fallback labeling, truncation footer, B1 acknowledgment), `AiManagerDigestRoutingTest` (skip proofs, follow-up guard, B1 combined turn). `php -l` on every touched file.
- Host (CondoEdge `config/ai.php`): the §1.3 registry (9 enabled with `max_sources = 9` per B2, + 2 disabled-pending-W7 entries) and the `ai.digest` keys. The `team_total_owed` date-note fix and a dedicated `pending_reviews` generic action remain RECOMMENDED companion changes but are outside this change-set (host edits restricted to `attention_sources` + `digest` keys this session).
- Eval: the `whats-next-digest` gold conversation (3 turns incl. the B1 combined ask) with `response_metadata.digest`-based standards; suite parts regenerated through `EvalSuiteLoader` validation (no live `ai:eval` run this session).

**Deferred (v2):**

- Normal-path fallback via QU `attention_source_hint` (§5.2 — designed, needs eval evidence).
- `maintenance_dates_unconfirmed` + `maintenance_contracts_lapsed` enablement (gated on W7 edge re-sync).
- Dedicated `ExpectationGate` digest gate; `TrueTotalResolver` extraction; per-caller rate-limiter split; role-aware source filtering (board vs staff vs lead see different registries); reservations/events sources (post re-ingest); dedicated generic actions for reviews/projects/maintenance plan.
