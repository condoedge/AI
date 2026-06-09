# Safe-by-Construction Tenant Scoping — Design

**Status:** Proposed
**Owner:** Security architecture
**Scope:** `condoedge/ai` team-filter tenant scoping for LLM-authored Cypher
**Date:** 2026-06-08

---

## 0. Problem in one paragraph

Today, tenant scoping is **regex + string-splice over LLM-authored Cypher**
(`QueryExecutor::extractPrimaryEntity` / `extractEntityAlias` /
`injectTeamFilter` / `injectTeamFilterIntoBranch`, with the filter itself
authored by `AiSecurityService::getTeamFilterWithParameters` →
`TeamPathResolver::resolve`). It is **safe-by-default** (fail-closed:
`on_permission_unexistent='deny'`, `on_missing_team_path='deny'`, and any shape
that cannot be anchored returns `null` → `SecurityException`) but **not
safe-by-construction**: correctness depends on three regexes correctly
identifying *the* single primary entity/alias and on "the first
`WITH|RETURN|ORDER` token" being the true primary-MATCH boundary. The
adversarial corpus proves that assumption false for 17 of 25 shapes — 9 clean
cross-tenant **LEAKs**, 6 **OVER-DENYs** (valid queries that error/deny), 2
both. The current safety on the leak cases is *incidental to token order*, not
structural; the fail-closed gates only convert *some* bad shapes to deny.

This design makes every query that runs **provably team-scoped on the correct
alias, or fail closed** — by construction, not by token luck — while *reducing*
the over-DENY surface, keeping the bound-`$ai_team_ids` parameter, staying
model-agnostic / host-injectable, and preserving every behavior the 23 security
tests assert.

---

## 1. Current gaps (from the corpus)

The corpus root causes, mapped to the code that produces them:

| # | Root cause | Code site | Corpus ids | Class |
|---|---|---|---|---|
| G1 | **Positional anchor** (first `WITH\|RETURN\|ORDER`) has no notion of nesting (`CALL{}`, comprehensions) or of *which* clause closes the primary scope. Splices a `WHERE` before any `MATCH`, inside a `CALL{` header, etc. | `injectTeamFilterIntoBranch` :864–871 | 1, 9, 14, 19, 22 | OVER_DENY / LEAK+OVER_DENY |
| G2 | **Entity/alias resolved once for the whole query**, then the *same* filter string is reused in every UNION branch and at every splice point. RETURN-projection alias can disagree with the variables bound at the splice location. | `extractPrimaryEntity` :753 / `extractEntityAlias` :776 / `injectTeamFilter` :819 | 5, 6, 10, 11, 18, 19, 22 | LEAK / LEAK+OVER_DENY |
| G3 | **Only the primary entity / first alias is scoped.** Additional independent `MATCH` roots, second bindings of the same label, `OPTIONAL MATCH` roots, comprehension patterns, and post-`;` statements are never scoped. | whole approach | 7, 13, 17(multi), 24, 25 | LEAK |
| G4 | **OR-grouping assumes head is a single boolean expression.** A second `MATCH`/clause between `WHERE` and the anchor gets swallowed into the parenthesized predicate → invalid Cypher. | `injectTeamFilterIntoBranch` :893–895 | 12 | OVER_DENY |
| G5 | **Regexes parse the raw string including string literals and comprehension internals.** A label inside `'...'` or `[(a)-->(b) \| ...]` is mistaken for a graph pattern. | `extractPrimaryEntity` fallback :766 | 13, 14, 23 | LEAK (latent) |
| G6 | **UNION splitting is brace-blind.** A `UNION` inside `CALL{}` is treated as top-level and the query is fractured at the wrong nesting depth. | `injectTeamFilter` :822 | 22 | LEAK+OVER_DENY |
| G7 | **No multi-statement (`;`) handling.** Filter lands on the first `RETURN` only; trailing statements run verbatim. | (absent) | 24 | LEAK |

Two further structural facts from the mechanism map that constrain any fix:

- **`executeCount()` wraps the inner query** as `CALL { <inner> } RETURN
  count(*) AS total` (QueryExecutor.php:232) and runs the *outer* query through
  `applySecurityFilter`. So whatever subset/analyzer we build **must treat a
  single outer `CALL { ... }` wrapper as first-class**, recursing into the inner
  query for scoping — otherwise count/pagination either over-denies or leaks.
  (This is corpus id 8/9's failure mode, but it is *our own* code generating the
  wrapper, so it cannot simply be rejected.)
- The **`$ai_team_ids` filter is already a bound parameter** authored by
  `TeamPathResolver`; the splice never injects a list literal. That part is
  correct and is preserved unchanged.

---

## 2. Chosen approach + why

**Chosen: (C) a *safe-scopeable subset* enforced by a `CypherScopeAnalyzer`
recognizer, plus a *structural* `SecurityFilterInjector` that scopes every
node-binding scope it proves, and fails closed on anything it cannot prove —
layered behind the existing template fast-path and the LLM generate→validate
retry loop.**

This is the pragmatic safe-by-construction point: we do **not** hand-roll a full
Cypher grammar/AST rewriter (option A) nor a partial tokenizer scope-tracker
that silently mis-scopes on unmodeled constructs (option B). Instead we *define
a subset we can prove scopeable* and **recognize-or-reject** it with a small,
auditable, brace/string/comment-aware analyzer. Inside the subset, injection is
total and trivial because every accepted query has a *finite, enumerated set of
node-binding scopes each with exactly one anchor*. Safety reduces to one small
claim — **"the recognizer accepts only in-subset queries"** — instead of "a
parser+rewriter handles all of Cypher" or "my scope-tracker has no blind spots."

### Why not the alternatives

- **(A) Real PHP openCypher AST parser + rewrite.** No maintained PHP openCypher
  parser exists (C/Java/JS/Python only); the ANTLR PHP runtime is pre-1.0
  (v0.9.1); the openCypher grammar is huge and the codegen path is Java. You'd
  vendor a large generated parser into an *injectable Laravel package* and still
  hand-write the tenant rewriter. Parser bugs become security bugs.
  **Effort: very high. Risk: high. Package fit: poor.** Rejected.

- **(B) Tokenizer + scope-tracker short of a grammar.** Removes the regex bug
  classes, but "which variables are node-bound and in-scope here" needs *more*
  of the grammar than budgeted (pattern comprehensions, `EXISTS{}`, quantified
  paths, `FOREACH`, map/list projections). Each unmodeled construct is a *silent
  mis-scope* (a leak), unless paired with a fail-closed escape hatch — at which
  point it **is** option C with a wider subset. We adopt B's good part (a
  brace/string/comment-aware tokenizer) *inside* C's recognizer, and treat "grow
  the tokenizer's structural understanding" as the way to *widen* the subset
  later without ever weakening the boundary.

- **(D) Parameterized templates only.** Safest, but caps the assistant hard and
  throws away NL→Cypher. Kept as the **fast path / floor** (`TemplateMatcher`
  already runs first), not the only strategy.

### The layered posture (defense in depth)

```
1. TemplateMatcher (already first)        — deterministic, pre-scoped, zero risk
2. LLM generate → CypherScopeAnalyzer     — reject out-of-subset, teach the LLM to
   ::validate() in the retry loop            regenerate in-subset (usefulness layer)
3. SemanticPromptBuilder system prompt    — instruct the model to emit the subset
4. SecurityFilterInjector (execution)     — STRUCTURAL re-analysis + scope every
   replaces injectTeamFilter*                proven node scope, else null → DENY
5. applySecurityFilter fail-closed deny   — unchanged backstop: subset gap = DENY
```

Layer 4 is the **security boundary** and is *independent of* layers 2–3: even if
a non-subset query reaches execution (LLM ignored the prompt, validator was
bypassed, a future caller hand-feeds Cypher), the injector re-runs the analyzer
and **fails closed** rather than splicing blindly. Layers 2–3 exist to *reduce
over-DENY* (teach generation to stay in-subset so the boundary rarely rejects),
not to provide safety. This is the key separation: **usefulness lives in
generation; safety lives in the injector.**

---

## 3. Component design — who owns what

Three new collaborators; the existing security authorship chain
(`AiSecurityService` → `TeamPathResolver` → bound `$ai_team_ids`) is **unchanged**.

```
QueryExecutor::applySecurityFilter()
   │  (entity/alias detection + injection moves OUT of QueryExecutor)
   ├── CypherScopeAnalyzer::analyze(string $cypher): ScopePlan
   │       owns: brace/string/comment-aware tokenization, subset recognition,
   │             enumeration of every node-binding scope + its anchor + bound vars
   │
   ├── AiSecurityService::getTeamFilterWithParameters($user, $entity, $alias)
   │       owns: permission check + filter authorship  (UNCHANGED — still throws
   │             on deny, still returns bound $ai_team_ids)
   │
   └── SecurityFilterInjector::inject(ScopePlan $plan, FilterFactory $f): InjectionResult
           owns: STRUCTURAL splice per scope using byte offsets from the plan;
                 returns null (→ fail closed) on any residual ambiguity
```

### 3.1 `CypherScopeAnalyzer` — the recognizer + scope map

```php
namespace Condoedge\Ai\Services\Security;

final class CypherScopeAnalyzer
{
    /**
     * Recognize the safe-scopeable subset and enumerate every node-binding
     * scope that must be tenant-scoped. Returns a ScopePlan whose ->isScopeable
     * is true ONLY when the analyzer has PROVEN every sensitive node binding is
     * anchored and reachable; otherwise ->reason explains the rejection.
     *
     * Pure / deterministic / no I/O. Operates on a token stream produced by a
     * brace/string/comment-aware lexer (closes G5/G6/G7 by construction).
     */
    public function analyze(string $cypher): ScopePlan;

    /**
     * Validator entry point used by QueryGenerator's retry loop. Thin wrapper
     * over analyze() that returns LLM-teachable errors for out-of-subset shapes.
     *
     * @return array{valid: bool, errors: string[], reason: ?string}
     */
    public function validateSubset(string $cypher): array;
}
```

```php
/** Immutable result of analysis. */
final class ScopePlan
{
    /** @param Scope[] $scopes  @param StringSpan[] $literalSpans */
    public function __construct(
        public readonly bool   $isScopeable,   // true ⇒ every sensitive scope is provably anchorable
        public readonly array  $scopes,        // one Scope per node-binding scope that needs filtering
        public readonly array  $literalSpans,  // byte ranges of string literals (never spliced into)
        public readonly ?string $reason = null // rejection reason for over-DENY logging / LLM feedback
    ) {}
}

/** One independently-scoped region (top-level part, UNION branch, or recognised CALL{} body). */
final class Scope
{
    /** @param NodeBinding[] $sensitiveBindings */
    public function __construct(
        public readonly array $sensitiveBindings, // EVERY labelled, tenant-sensitive node bound in this scope
        public readonly int   $anchorOffset,      // byte offset of this scope's primary-MATCH boundary
        public readonly int   $whereOffset = -1,  // byte offset of this scope's own WHERE (-1 if none)
        public readonly bool  $whereHasTopLevelOr = false // precedence-aware (token depth 0), not substring
    ) {}
}

/** A single labelled node binding that is live at its scope's anchor. */
final class NodeBinding
{
    public function __construct(
        public readonly string $alias,   // e.g. 'i'   (guaranteed bound & live at anchorOffset)
        public readonly string $label    // e.g. 'Invoice'
    ) {}
}
```

**What the analyzer proves before setting `isScopeable = true`** (each closes a
corpus root cause):

- Every `MATCH` / `OPTIONAL MATCH` node pattern that carries a **tenant-sensitive
  label** is captured as a `NodeBinding` in its enclosing `Scope` — *not just the
  first/returned one* (closes **G3**: ids 7, 25, 17-multi).
- Each binding's `alias` is **proven live at its scope's `anchorOffset`** (the
  variable still in scope at the splice point), so the emitted predicate never
  references an unbound variable (closes **G1/G2**: ids 1, 10, 19).
- The analyzer derives the alias/label from the **actual node pattern in the
  scope**, never from the RETURN projection (closes **G2**: ids 5, 6, 18).
- Tokenization is **string-literal and comment aware**; labels inside `'...'`,
  `` `...` ``-quoted identifiers, line/block comments, or pattern comprehensions
  are not mistaken for graph patterns (closes **G5**: ids 13, 14, 23).
- `UNION` split and `CALL {}` boundaries are found by **brace/paren depth on the
  token stream**, not by flat regex (closes **G6**: id 22, and the brace-blind
  part of id 8/9/22).
- Top-level `;` (multi-statement) ⇒ **reject** (`isScopeable = false`, reason
  `multi_statement`) (closes **G7**: id 24).

**The subset itself is `isScopeable = false` (reject → fail closed / LLM retry)
for any shape the analyzer cannot prove**, including: a node-binding scope whose
sensitive binding is **not live at its anchor**; a sensitive **label-free**
primary (`MATCH (n {id:1})`, id 4); pattern-comprehension / `EXISTS {}` that
binds a *sensitive* node the analyzer cannot anchor (ids 13, 14); nested
constructs the lexer can tokenize but the recognizer does not yet model. See §4.

### 3.2 `SecurityFilterInjector` — the structural splice

```php
namespace Condoedge\Ai\Services\Security;

final class SecurityFilterInjector
{
    /**
     * Scope EVERY sensitive binding in EVERY scope of the plan, using byte
     * offsets (no re-parsing, no regex anchoring). Returns the rewritten query,
     * or null if any scope cannot be safely rewritten (caller then fails closed).
     *
     * @param callable(NodeBinding):FilterClause $filterFor
     *        Resolves a per-binding filter from AiSecurityService/TeamPathResolver.
     *        Each binding is scoped on ITS OWN entity/alias/team_path.
     */
    public function inject(string $cypher, ScopePlan $plan, callable $filterFor): ?string;
}
```

```php
/** A ready-to-splice filter for ONE binding (already references bound $ai_team_ids). */
final class FilterClause
{
    public function __construct(
        public readonly string $kind,   // 'where' | 'match'   (direct column vs relationship/parent traversal)
        public readonly string $clause, // e.g. 'i.team_id IN $ai_team_ids'  OR  'MATCH (i)-[:BELONGS_TO]->(t:Team) WHERE t.id IN $ai_team_ids'
        public readonly array  $parameters // ['ai_team_ids' => [..int..]]
    ) {}
}
```

**Why offsets, not regex:** the analyzer already located every anchor, WHERE,
and literal span on a real token stream. The injector splices **right-to-left by
descending offset** so earlier offsets stay valid, AND-combining per scope:

- `kind = 'where'`: if the scope has its own WHERE → if `whereHasTopLevelOr`
  wrap the *existing predicate token range only* in parens and append
  `AND <clause>` (closes **G4**: id 12 — the second MATCH is a *separate clause*
  in the token stream, never inside the predicate range); else append
  `AND <clause>`; if no WHERE → insert `WHERE <clause>` at `anchorOffset`.
- `kind = 'match'`: splice `<clause>` immediately before `anchorOffset`.
- **Multiple sensitive bindings in one scope** ⇒ all are scoped (the multi-root
  leak, G3); a scope with N bindings yields N AND-combined predicates / MATCH
  extensions on their respective aliases.
- **`CALL { <inner> }` wrapper** (from `executeCount`): the inner query is a
  recognised nested scope in the plan; the injector scopes *inside* the braces
  on the inner bindings, leaving the outer `RETURN count(*)` untouched. This is
  the one nested form we *accept* (because we generate it); all other `CALL{}`
  remain out-of-subset → reject.

`FilterClause.parameters` from every spliced binding are **merged** and returned
so `applySecurityFilter` can pass them up (the existing
`array_merge($securityResult['parameters'], $parameters)` contract at
QueryExecutor.php:96 is preserved; caller keys still win).

### 3.3 `applySecurityFilter` — rewired, same contract

`applySecurityFilter` keeps its **exact public return contract**
(`array{query, filter_applied: bool, parameters: array}`) and **all fail-closed
branches**, but delegates detection/injection:

```php
private function applySecurityFilter(string $cypherQuery, array $options): array
{
    // Branches A/B (skip_security, !enabled)         — UNCHANGED
    // User resolution ($options['user'] ?? auth())   — UNCHANGED

    $plan = app(CypherScopeAnalyzer::class)->analyze($cypherQuery);

    // No user:
    //   - plan has a sensitive scope OR queryAccessesNodes ⇒ failClosedOrBypass(no_resolvable_user)
    //   - else (pure scalar/introspection)             ⇒ UNFILTERED      (Branch C — UNCHANGED semantics)
    // User present:
    //   - plan->isScopeable === false                  ⇒ failClosedOrBypass(reason)   (Branch D/F — fail closed)
    //   - plan has no sensitive scope (RETURN 1, …)     ⇒ UNFILTERED                   (UNCHANGED)
    //   - COUNT + AiGlobalCount on the sole entity      ⇒ UNFILTERED                   (Branch E — UNCHANGED)
    //
    // Otherwise: for each sensitive binding, call AiSecurityService
    // (UNCHANGED — still throws on deny, still returns bound $ai_team_ids),
    // build per-binding FilterClause, then:
    $injected = app(SecurityFilterInjector::class)->inject($cypherQuery, $plan, $filterFor);
    if ($injected === null) {
        return $this->failClosedOrBypass($cypherQuery, $primaryEntity, 'injection_not_applicable');
    }
    return ['query' => $injected, 'filter_applied' => true, 'parameters' => $mergedParameters];
}
```

The legacy private regex helpers (`extractPrimaryEntity`, `extractEntityAlias`,
`injectTeamFilter`, `injectTeamFilterIntoBranch`) are **retained as thin
shims**that delegate to the analyzer/injector for the single-scope case, so the
reflection-based unit tests in §6 that call them directly keep passing
byte-identically (see §6 mapping). New code paths go through the analyzer.

### 3.4 `QueryGenerator` + prompt wiring (usefulness layer)

- In `QueryGenerator::generate`'s validate step (QueryGenerator.php:197), after
  `$this->validate(...)`, also call
  `CypherScopeAnalyzer::validateSubset($cypher)`; if not in-subset, append its
  `errors` to `$lastError` so the **existing retry loop** (max_retries) feeds the
  reason back and the model regenerates in-subset. *No new loop* — reuses 149–255.
- In `SemanticPromptBuilder::buildPrompts` (SemanticPromptBuilder.php:215),
  append the subset rules to the **system** role (identity/static rules), e.g.
  "one labelled `MATCH` primary per query part and per UNION branch; the returned
  variable must be a labelled node bound in that part; no `CALL{}` subqueries
  except a single outer count wrapper; no multi-statement `;`; parenthesize any
  top-level `OR`." This turns "constrain the LLM" into an enforced spec.

---

## 4. The safe-subset rules + how generation is constrained

A query is **in-subset** (analyzer `isScopeable = true`) iff **all** hold:

1. **Read-only.** No `CypherValidator::DANGEROUS_KEYWORDS` (already enforced).
2. **Single statement.** No top-level `;` (token depth 0). → reject `multi_statement`.
3. **Per-part labelled primary.** In each top-level query part and each `UNION`
   branch, every tenant-sensitive node binding carries an explicit `:Label` and
   each such binding's alias is **live at that part's anchor** (first
   `WITH|RETURN|ORDER` at brace depth 0 of that part). No sensitive **label-free**
   node binding (`(n {…})`) — reject `label_free_sensitive`.
4. **Anchor exists per scope.** Each scope has a `WITH|RETURN|ORDER` boundary at
   its own depth. → reject `no_anchor` (preserves the
   `inject_returns_null_when_branch_has_no_safe_anchor` behavior).
5. **Bounded nesting.** No `CALL {}` **except** a single outer
   `CALL { <inner> } RETURN count(*)` wrapper (our own count form), whose inner
   query must itself be in-subset. Any other `CALL{}` → reject `unsupported_subquery`.
6. **No sensitive bindings hidden in pattern comprehensions / `EXISTS{}`.** If a
   comprehension/existential subquery binds a node with a **tenant-sensitive
   label**, reject `unsupported_pattern_scope` (we cannot prove its scoping).
   Comprehensions over **non-sensitive** labels are allowed.
7. **OR precedence is explicit.** A scope's WHERE may contain a top-level `OR`;
   the injector groups it (token-depth-0 detection, not substring). No rejection
   needed — handled structurally.

**How generation is constrained to the subset:** the prompt (§3.4) instructs the
model to emit exactly this shape; the analyzer's `validateSubset` in the retry
loop rejects out-of-subset drafts with a teachable reason; the
`TemplateMatcher` fast path handles the most common shapes deterministically and
already pre-scoped. **Residual out-of-subset queries that still reach execution
fail closed at the injector** — usefulness degrades to a retry/deny, never to a
leak.

**Subset *widening* is monotonic:** to support, say, multi-MATCH cartesian
products with two sensitive roots, we teach the analyzer to enumerate *both*
bindings and the injector to scope both — the subset grows, the boundary never
weakens. This is the controlled path from C toward B's coverage.

---

## 5. The fail-closed boundary (invariant)

> **INVARIANT.** A query is executed by `graphStore->query()` only if either
> (a) it binds no tenant-sensitive node (proven by `CypherScopeAnalyzer`), or
> (b) `SecurityFilterInjector` returned a non-null rewrite in which **every**
> sensitive binding in **every** scope carries a `$ai_team_ids`-bound predicate
> on **its own** live alias. Everything else throws `SecurityException`
> (`on_*='deny'`) or runs unfiltered **only** under explicit `bypass`/`skip`.

The boundary is enforced at **three independent points** so no single bug opens
it:

1. **Analyzer** — `isScopeable = false` ⇒ `applySecurityFilter` →
   `failClosedOrBypass` (existing deny throw).
2. **Per-binding authorship** — `AiSecurityService::getTeamFilterWithParameters`
   still throws on missing permission / empty team set / missing team_path under
   `deny` (UNCHANGED).
3. **Injector** — `inject(...) === null` ⇒ `failClosedOrBypass`
   (`injection_not_applicable`), exactly as the regex injector returning `null`
   does today.

`bypass` and `skip_security` retain their **exact** legacy fail-open semantics
(tests `bypass_runs_unfiltered_when_no_resolvable_user`,
`bypass_runs_label_free_node_access_unfiltered`). The
fail-closed-vs-unfiltered matrix from the mechanism map is preserved; the only
*intended behavioral change* is that several corpus shapes move from **leak →
scoped** (G2/G3) or **over-deny → scoped** (G1/G4) — strictly safer or strictly
more useful, never less safe.

---

## 6. Test plan — the corpus becomes tests

All new tests live beside the guard suites; the **23 existing
`QueryExecutorSecurityTest` tests + `QueryExecutorRateLimitTest` +
`AiSecurityServiceTest` MUST stay green**. Run per file:

```
php -d opcache.enable_cli=0 vendor/bin/phpunit <file> --no-coverage
```

### 6.1 Regression — existing 23 must not move

The retained shim methods make these pass byte-identically. Explicit mapping of
the asserted behaviors that constrain the design:

| Existing test | What the new design must still produce |
|---|---|
| `injects_team_filter_when_no_where_exists` | `WHERE i.team_id …` before `RETURN i` (single scope, no WHERE) |
| `injects_team_filter_when_where_exists` | `… AND i.team_id …` merged with existing WHERE |
| `injects_filter_before_with_clause` / `…_order_clause` | predicate before `WITH i` / `ORDER BY` (anchor = primary boundary) |
| `injects_filter_into_every_union_branch` / `…_all_branch` | **2** occurrences, each branch scoped, `UNION`/`UNION ALL` preserved |
| `injects_match_based_filter_before_with_on_primary_match` | `MATCH …[:BELONGS_TO]…` before `WITH i` (kind=`match`) |
| `injects_filter_into_multi_match_query` | predicate on `i`, before `RETURN` |
| `inject_returns_null_when_branch_has_no_safe_anchor` | `null` (rule 4, `no_anchor`) |
| `or_predicate_is_parenthesized_…` | `(… OR …) AND i.team_id …` (rule 7, token-depth OR grouping) |
| `extracts_primary_entity_*` / `extracts_entity_alias_*` / `returns_default_alias_…` / `returns_null_when_no_entity_…` / `detects_count_queries` | shim delegates to analyzer; same returns |
| `uses_threaded_options_user_…` | `$options['user']` still preferred; `getTeamFilterWithParameters($threadedUser,'Invoice',…)` called once |
| `deny_throws_*` (3) / `bypass_runs_*` (2) / `no_entity_query_is_not_subject…` / `team_ids_are_bound_as_parameters_not_spliced` | identical fail-closed/bypass/param-binding outcomes |

### 6.2 New `CypherScopeAnalyzerTest` + `SecurityFilterInjectorTest` — corpus-driven

Each corpus id becomes one or more assertions. **The invariant**: every case
either produces a query in which *all* sensitive bindings are `$ai_team_ids`-scoped
on their own live alias, **or** rejects/denies — **never a silent unscoped run**.

| id | name | New expected behavior | Closes |
|---|---|---|---|
| 1 | leading `WITH` const before MATCH | scope `i` after its MATCH (valid Cypher), not offset 0 | G1 |
| 2,3 | projecting/post-WITH `WHERE` | scoped before WITH (control stays green) | — |
| 4 | label-free primary | reject `label_free_sensitive` → deny | rule 3 |
| 5 | sensitive entity is 2nd MATCH, RETURN aliases child | scope the **Invoice** root (its own team_path), not LineItem | G2/G3 |
| 6 | RETURN hides sensitive root | scope `p:Person`, not `o:Organization` | G2 |
| 7 | multi-MATCH two roots | **both** `i` and `p` scoped | G3 |
| 8 | `CALL{}` outer Team + inner Invoice | reject (not the count wrapper) `unsupported_subquery` → deny | rule 5 |
| 9 | aggregating `CALL{}` with inner `WITH` | reject `unsupported_subquery` (no mid-CALL splice) | rule 5/G1 |
| 10 | UNION, 2nd branch alias absent | each branch scoped on **its own** binding (`p` in branch 2) | G2/G6 |
| 11 | UNION alias collision diff labels | branch 2 scoped on **Payment**'s team_path, not Invoice's | G2 |
| 12 | top-level OR + 2nd MATCH in head | `(a OR b) AND team`, `MATCH (x:Foo)` stays a clause (valid) | G4 |
| 13 | comprehension binds Invoice | if Invoice sensitive ⇒ reject `unsupported_pattern_scope` | rule 6/G5 |
| 14 | comprehension-only, no top MATCH | reject (sensitive comprehension binding) → deny | rule 6/G5 |
| 15,16,20,21 | controls (function WHERE, nested OR in AND, backtick label, agg-before-RETURN) | scoped correctly, stay green | — |
| 17 | OPTIONAL MATCH primary / multi | single ⇒ scoped; `MATCH(a) OPTIONAL MATCH(i:Invoice)` ⇒ **both** scoped | G3 |
| 18 | label-free RETURN, Team in rel | reject (no sensitive labelled binding for `n`) → deny, not scope Team.id | G2/rule 3 |
| 19 | WITH-agg then 2nd sensitive MATCH | scope `i` before WITH **and** `p` after; both live at their anchors | G1/G2/G3 |
| 22 | `UNION` inside `CALL{}` | reject `unsupported_subquery` (brace-aware) → deny | G6/rule 5 |
| 23 | label inside string literal | literal span ignored; scope on real `n:Note` | G5 |
| 24 | multi-statement `;` | reject `multi_statement` → deny | G7/rule 2 |
| 25 | two Invoice roots via Unit | **both** `i` and `i2` scoped | G3 |

### 6.3 Property / fuzz test (boundary proof)

A `TenantScopingInvariantTest` generates/replays the corpus + mutations and
asserts, for every input, the §5 invariant via a checker: parse the *output*
with the analyzer and assert **either** rejected/denied **or** every sensitive
binding has a `$ai_team_ids` predicate on a live alias. This makes "safe by
construction" continuously verifiable as the subset widens. The
`executeCount` `CALL{ … } RETURN count(*)` wrapper is included as a first-class
accepted case (inner scoped, outer untouched).

### 6.4 Generation-loop test

`QueryGeneratorSubsetTest`: a stubbed `LlmProviderInterface` returns an
out-of-subset draft then an in-subset draft; assert `validateSubset` rejection is
fed back via `$lastError` and the in-subset retry is accepted (reuses existing
149–255 loop; no new control flow).

---

## 7. Concrete deltas (files)

- **New:** `src/Services/Security/CypherScopeAnalyzer.php`,
  `src/Services/Security/SecurityFilterInjector.php`, and the small value
  objects `ScopePlan` / `Scope` / `NodeBinding` / `FilterClause`
  (one file or a `Security/Scope/` namespace).
- **Edit:** `src/Services/QueryExecutor.php` — `applySecurityFilter` delegates to
  analyzer+injector; legacy regex helpers become shims (preserve reflection
  tests); contract + fail-closed branches unchanged.
- **Edit:** `src/Services/QueryGenerator.php` — call `validateSubset` in the
  validate step; feed reason to existing retry loop.
- **Edit:** `src/Services/SemanticPromptBuilder.php` — append subset rules to the
  system role.
- **Unchanged:** `src/Services/Security/AiSecurityService.php`,
  `src/Services/Security/TeamPathResolver.php` (bound `$ai_team_ids` authorship),
  `config/ai.php` security keys.
- **New tests:** `tests/Unit/Services/Security/CypherScopeAnalyzerTest.php`,
  `SecurityFilterInjectorTest.php`, `TenantScopingInvariantTest.php`,
  `tests/Unit/Services/QueryGeneratorSubsetTest.php`; **keep green**
  `QueryExecutorSecurityTest`, `QueryExecutorRateLimitTest`,
  `AiSecurityServiceTest`.

---

## 8. Why this is the pragmatic best

It is **safe-by-construction** (the property is proven for a *recognized
grammar*, not per query) at **medium effort, low residual safety risk, excellent
package fit** (pure PHP, no pre-1.0 deps, small auditable surface), it **drops
into the existing generate→validate→execute pipeline**, it keeps the **bound
`$ai_team_ids`** authorship untouched, it is **model-agnostic and
host-injectable**, and it **preserves every behavior the 23 security tests
assert** while turning 9 leaks into scoped queries and 6 over-denies into either
scoped queries or principled, teachable rejections. A full hand-rolled grammar
(A) is rejected as over-engineering for an injectable package; a bare
scope-tracker (B) is folded in as the *tokenizer inside the recognizer* and as
the future widening path — never as the safety boundary.
