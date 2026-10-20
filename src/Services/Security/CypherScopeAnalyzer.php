<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\Scope\Scope;
use Condoedge\Ai\Services\Security\Scope\ScopePlan;

/**
 * Recognizer for the safe-scopeable Cypher subset + enumerator of every node
 * binding scope that must be tenant-scoped.
 *
 * Pure / deterministic / no I/O. It operates on the {@see CypherLexer} token
 * stream so string literals, comments and pattern-comprehension internals can
 * never be mistaken for graph patterns, and so UNION / CALL{} boundaries are
 * found by structural nesting depth rather than flat regex.
 *
 * `analyze()` returns a {@see ScopePlan} whose `isScopeable` is true ONLY when
 * EVERY tenant-sensitive (labelled) node binding has been proven anchored and
 * live at its scope's anchor; anything it cannot prove is rejected (the caller
 * then fails closed). "Tenant-sensitive" = any labelled node binding — whether a
 * given label is actually team-scoped, denied or bypassed is decided downstream
 * by AiSecurityService, exactly as before.
 *
 * @see docs/audit/AST-tenant-scoping-design.md
 */
final class CypherScopeAnalyzer
{
    /** Clause keywords that close a scope's primary-MATCH region (the anchor). */
    private const ANCHOR_KEYWORDS = ['WITH', 'RETURN', 'ORDER'];

    public function __construct(
        private readonly CypherLexer $lexer = new CypherLexer()
    ) {
    }

    /**
     * Recognize the subset and enumerate every node-binding scope.
     */
    public function analyze(string $cypher): ScopePlan
    {
        $lexed = $this->lexer->tokenize($cypher);

        if ($lexed['error'] !== null) {
            // Unlexable input cannot be proven safe. Treat as node-accessing if the
            // raw text mentions MATCH so the caller fails closed rather than running.
            $accesses = (bool) preg_match('/\bMATCH\b/i', $cypher);
            return new ScopePlan(false, [], [], $accesses, $lexed['error']);
        }

        /** @var list<Token> $tokens */
        $tokens = $lexed['tokens'];
        $literalSpans = $lexed['literalSpans'];

        $accessesNodes = $this->hasNodeMatch($tokens, 0, count($tokens));

        // Rule 2: single statement. A top-level ';' that is not the final token
        // means there is a trailing statement we would never scope.
        if ($this->hasTopLevelStatementSeparator($tokens)) {
            return new ScopePlan(false, [], $literalSpans, $accessesNodes, 'multi_statement');
        }

        // Rule 2b: closed syntax subset (security review 2026-10-01, round 2).
        // Anything the analyzer cannot fully model — GQL `IS` labels, inline
        // pattern WHERE, label expressions, path selectors / quantified paths,
        // bound variable-length relationships, keyword-named variables, code
        // run from a string (apoc.cypher.*) or any other procedure / namespaced
        // function outside a pure-value allowlist — is REJECTED here, never
        // silently skipped. Treated as node-accessing so the caller fails closed.
        $syntaxReason = $this->unsupportedSyntaxReason($tokens, $cypher);
        if ($syntaxReason !== null) {
            return new ScopePlan(false, [], $literalSpans, true, $syntaxReason);
        }

        // Rule 5: bounded nesting. The only accepted CALL{} is our own outer count
        // wrapper `CALL { <inner> } RETURN count(*) ...`.
        $countWrapper = $this->matchCountWrapper($tokens);
        if ($countWrapper !== null) {
            // The inner of the count wrapper must be a single (UNION-free) part:
            // a UNION inside CALL{} is exactly the brace-blind adversarial shape we
            // refuse to scope (corpus 22) → reject rather than risk mis-splitting.
            if ($this->hasTopLevelUnion(
                $tokens,
                $countWrapper['innerStart'],
                $countWrapper['innerEnd'],
                $countWrapper['innerBaseParen'],
                $countWrapper['innerBaseBracket'],
                $countWrapper['innerBaseBrace']
            )) {
                return new ScopePlan(false, [], $literalSpans, $accessesNodes, 'unsupported_subquery');
            }

            // Analyze the inner query (its own subset). Offsets stay absolute.
            $innerPlan = $this->analyzeRange(
                $tokens,
                $countWrapper['innerStart'],
                $countWrapper['innerEnd'],
                $literalSpans,
                $accessesNodes
            );

            if (!$innerPlan->isScopeable) {
                return new ScopePlan(false, [], $literalSpans, $accessesNodes, $innerPlan->reason);
            }

            return new ScopePlan(true, $innerPlan->scopes, $literalSpans, $accessesNodes, null);
        }

        // Any other top-level (or nested) CALL{} subquery is out of subset.
        if ($this->hasUnsupportedCallSubquery($tokens, 0, count($tokens))) {
            return new ScopePlan(false, [], $literalSpans, $accessesNodes, 'unsupported_subquery');
        }

        return $this->analyzeRange($tokens, 0, count($tokens), $literalSpans, $accessesNodes);
    }

    /**
     * Validator entry point for the generation retry loop. Returns LLM-teachable
     * errors for out-of-subset shapes.
     *
     * @return array{valid: bool, errors: list<string>, reason: ?string}
     */
    public function validateSubset(string $cypher): array
    {
        $plan = $this->analyze($cypher);

        if ($plan->isScopeable) {
            return ['valid' => true, 'errors' => [], 'reason' => null];
        }

        return [
            'valid' => false,
            'errors' => [$this->explain($plan->reason)],
            'reason' => $plan->reason,
        ];
    }

    /**
     * Analyze a contiguous token range as an independent query (the whole query,
     * or the inner query of the count wrapper). Splits on top-level UNION and
     * produces one Scope per part.
     *
     * @param list<Token> $tokens
     * @param array<array{0:int,1:int}> $literalSpans
     */
    private function analyzeRange(array $tokens, int $start, int $end, array $literalSpans, bool $accessesNodes): ScopePlan
    {
        // Reject nested CALL{} subqueries inside this range (the count wrapper's
        // inner query, or a plain query, may not itself contain a subquery).
        if ($this->hasUnsupportedCallSubquery($tokens, $start, $end)) {
            return new ScopePlan(false, [], $literalSpans, $accessesNodes, 'unsupported_subquery');
        }

        $baseParen = $tokens[$start]->parenDepth ?? 0;
        $baseBracket = $tokens[$start]->bracketDepth ?? 0;
        $baseBrace = $tokens[$start]->braceDepth ?? 0;

        $parts = $this->splitUnionParts($tokens, $start, $end, $baseParen, $baseBracket, $baseBrace);

        $scopes = [];
        foreach ($parts as $part) {
            $scope = $this->analyzePartScope(
                $tokens,
                $part[0],
                $part[1],
                $baseParen,
                $baseBracket,
                $baseBrace
            );

            if ($scope instanceof ScopePlan) {
                // A rejection bubbled up from the part.
                return new ScopePlan(false, [], $literalSpans, $accessesNodes, $scope->reason);
            }

            // A part yields one Scope, or a list of Scopes (one per WITH-segment
            // carrying sensitive bindings — see analyzePartScope).
            foreach (is_array($scope) ? $scope : [$scope] as $segmentScope) {
                $scopes[] = $segmentScope;
            }
        }

        return new ScopePlan(true, $scopes, $literalSpans, $accessesNodes, null);
    }

    /** @param list<Token> $tokens */
    private function hasTopLevelUnion(
        array $tokens,
        int $start,
        int $end,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): bool {
        for ($i = $start; $i < $end; $i++) {
            $t = $tokens[$i];
            if ($t->isWord('UNION') && $this->atDepth($t, $baseParen, $baseBracket, $baseBrace)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Analyze ONE UNION-free part. Returns a Scope, a list of Scopes (one per
     * WITH-segment carrying sensitive bindings), or a (rejecting) ScopePlan.
     *
     * WITH-SEGMENTATION (item E — board-query carry-through, 2026-07-23): a
     * top-level WITH is a SEGMENT BOUNDARY, not a wall. Every sensitive binding
     * anchors at the FIRST WITH|RETURN|ORDER at the part's depth AFTER its own
     * binding site — i.e. in its OWN segment, where its alias is by
     * construction still live (there can be no top-level WITH strictly between
     * a binding and the first anchor keyword after it). Bindings sharing an
     * anchor share a Scope; a query like
     *
     *   MATCH (b:BoardMember)-[:R]->(un:Union) WHERE un.team_id IN $ai_team_ids
     *   WITH un, max(b.year) AS y
     *   MATCH (cur:BoardMember)-[:R]->(un) WHERE cur.year = y RETURN cur
     *
     * therefore yields TWO scopes: {b, un} anchored at the WITH (spliced
     * predicates land BEFORE it, while b and un are live) and {cur} anchored at
     * the RETURN. A variable re-projected through the WITH (`WITH un, …`)
     * retains its established scoping — its later label-free `(un)` mention is
     * a live back-reference, not a new binding. Previously the whole part had
     * ONE anchor after the LAST binding, so `b` (projected away by the WITH)
     * read as 'sensitive_binding_not_live' and the entire valid query
     * fail-closed (live board-query safe-stop).
     *
     * FAIL-CLOSED preserved: label-free NEW bindings, alias-free labels,
     * comprehension-buried sensitive patterns, anchor-less bindings, and DEAD
     * back-references (a label-free `(u)` after a WITH that projected u away)
     * are all still rejected.
     *
     * @param list<Token> $tokens
     * @return Scope|list<Scope>|ScopePlan
     */
    private function analyzePartScope(
        array $tokens,
        int $start,
        int $end,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ) {
        // 1) Enumerate every labelled node-binding OCCURRENCE across the WHOLE
        // part + detect un-anchorable sensitive patterns (comprehensions,
        // label-free primaries, dead back-references).
        $extract = $this->extractBindings(
            $tokens,
            $start,
            $end,
            $baseParen,
            $baseBracket,
            $baseBrace
        );

        if ($extract['reason'] !== null) {
            return new ScopePlan(false, [], [], true, $extract['reason']);
        }

        /** @var list<array{alias:string,label:string,tokenIdx:int,optional:bool}> $occurrences */
        $occurrences = $extract['occurrences'];

        if (empty($occurrences)) {
            // No sensitive bindings → a pure scalar / introspection part
            // (e.g. `CALL db.labels()`); nothing to scope.
            return new Scope([], $start >= 0 && $start < count($tokens) ? $tokens[$start]->offset : 0);
        }

        // 2) Per-occurrence anchor: the first WITH|RETURN|ORDER at this part's
        // depth AFTER the occurrence (a leading `WITH const` before any MATCH is
        // therefore never mistaken for an anchor — closes G1). Group occurrences
        // by anchor: one Scope per segment that carries sensitive bindings.
        $groups = [];
        foreach ($occurrences as $occ) {
            $anchorIdx = $this->findAnchorIndex($tokens, $occ['tokenIdx'], $end, $baseParen, $baseBracket, $baseBrace);
            if ($anchorIdx === null) {
                // A sensitive binding with nowhere to anchor the predicate → fail
                // closed (preserves inject_returns_null_when_branch_has_no_safe_anchor).
                return new ScopePlan(false, [], [], true, 'no_anchor');
            }
            $groups[$anchorIdx][] = $occ;
        }
        ksort($groups);

        $scopes = [];
        $prevAnchorIdx = null;
        foreach ($groups as $anchorIdx => $groupOccurrences) {
            // Segment lower bound: just after the previous USED anchor (or the
            // part start). Bounds the WHERE search and the OPTIONAL-MATCH anchor
            // check to this segment, and is exported so the acceptance gate never
            // lets an earlier segment's predicate vouch for a later re-binding.
            $segStart = $prevAnchorIdx === null ? $start : $prevAnchorIdx + 1;
            $prevAnchorIdx = $anchorIdx;

            // Dedupe occurrences of the same alias+label WITHIN the segment
            // (a re-match in the same segment is one binding). The same alias in
            // a LATER segment gets its own scope — that is the carry-through fix.
            $bindings = [];
            $seen = [];
            foreach ($groupOccurrences as $occ) {
                $key = $occ['alias'] . '|' . $occ['label'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                if (!$occ['optional']) {
                    $bindings[] = new NodeBinding($occ['alias'], $occ['label'], false);
                    continue;
                }

                // OPTIONAL binding: record its OWN OPTIONAL MATCH clause so the
                // injector scopes it THERE — a WHERE on a later OPTIONAL MATCH
                // only filters that later pattern, never this variable (P1).
                // ONLY when this occurrence BINDS the variable (its first
                // appearance in the part): an OPTIONAL MATCH cannot filter an
                // already-bound variable (UNWIND … AS x, YIELD node, an earlier
                // pattern) — the scope then goes row-level (round 2, item 1).
                $clause = $this->aliasAppearsBefore($tokens, $start, $occ['tokenIdx'] + 1, $occ['alias'])
                    ? ['insertOffset' => -1, 'whereOffset' => -1, 'whereEndOffset' => -1]
                    : $this->optionalClauseInfo(
                        $tokens,
                        $occ['tokenIdx'],
                        $segStart,
                        $anchorIdx,
                        $baseParen,
                        $baseBracket,
                        $baseBrace
                    );
                $bindings[] = new NodeBinding(
                    $occ['alias'],
                    $occ['label'],
                    true,
                    $clause['insertOffset'],
                    $clause['whereOffset'],
                    $clause['whereEndOffset']
                );
            }

            // This segment's own WHERE (between its bindings and its anchor) and
            // its top-level OR, for precedence-correct AND-merge.
            $whereInfo = $this->findWhere($tokens, $segStart, $anchorIdx, $baseParen, $baseBracket, $baseBrace);

            // The clause a spliced WHERE would attach to (OPTIONAL MATCH, or one
            // that cannot carry a WHERE at all — UNWIND/SET/CALL{}/…).
            // STRUCTURAL: when this segment's WHERE is still OPEN at the anchor
            // (no clause between it and the anchor), the spliced predicate joins
            // THAT WHERE — so the clause it belongs to is decided by the tokens
            // BEFORE it. Words inside an open predicate are expressions and can
            // never change which clause the WHERE belongs to (the 1.26 bypass:
            // a `yield` variable/label inside an OPTIONAL WHERE flipped the kind).
            $whereOpen = $whereInfo['whereOffset'] >= 0
                && $whereInfo['whereEndOffset'] >= $tokens[$anchorIdx]->offset;
            $anchorClause = $this->anchorClauseKind(
                $tokens,
                $segStart,
                $anchorIdx,
                $whereOpen ? $whereInfo['whereOffset'] : $tokens[$anchorIdx]->offset,
                $baseParen,
                $baseBracket,
                $baseBrace
            );

            $scopes[] = new Scope(
                sensitiveBindings: $bindings,
                anchorOffset: $tokens[$anchorIdx]->offset,
                whereOffset: $whereInfo['whereOffset'],
                // Predicate end is bounded at the first clause keyword after the WHERE
                // (an intervening MATCH/WITH/… before the anchor), so the injector wraps
                // ONLY the predicate when parenthesizing a top-level OR (c12).
                whereEndOffset: $whereInfo['whereOffset'] >= 0 ? $whereInfo['whereEndOffset'] : -1,
                whereHasTopLevelOr: $whereInfo['hasTopLevelOr'],
                // Whether a WHERE spliced at the anchor would attach to an OPTIONAL
                // MATCH (and thus filter only the optional match, not the row).
                anchorClauseIsOptionalMatch: $anchorClause === 'optional_match',
                segmentStartOffset: $segStart > $start && $segStart < count($tokens)
                    ? $tokens[$segStart]->offset
                    : 0,
                anchorClauseRejectsWhere: $anchorClause === 'no_where'
            );
        }

        return count($scopes) === 1 ? $scopes[0] : $scopes;
    }

    /**
     * Kind of the LAST clause before the anchor — the one a WHERE spliced
     * immediately before the anchor would attach to (Cypher attaches a WHERE to
     * the clause that precedes it):
     *
     *   'optional_match' — an OPTIONAL MATCH: a WHERE there filters the optional
     *       match only (an unmatched row survives with a null variable), so it
     *       must NOT carry row-level (mandatory-binding) predicates;
     *   'no_where' — a clause that cannot carry a WHERE at all (UNWIND, SET,
     *       REMOVE, DELETE, CREATE, MERGE, FOREACH, a CALL subquery / a CALL
     *       without YIELD): a bare WHERE there is a SyntaxError, so the injector
     *       must re-open a row-level `WITH *` first;
     *   'where_ok' — MATCH, CALL … YIELD (or nothing): a plain WHERE is fine.
     *
     * FAIL-SAFE DIRECTION: only a reset to 'where_ok' can leak (a mandatory
     * predicate AND-merged into an OPTIONAL MATCH's WHERE filters the optional
     * part only). 'optional_match' and 'no_where' both make the injector enforce
     * the predicate at row level (`WITH * WHERE …`), so every uncertain word must
     * fail toward NOT resetting:
     *   - only tokens before $stopOffset are read (the open WHERE, see caller);
     *   - identifier-position words (labels / properties / label expressions,
     *     comments are no tokens — Token::$keywordAsIdentifier) never count;
     *   - a word in OPERAND position (`= match`, `AND with`) never resets to
     *     'where_ok' (Token::$operandPosition);
     *   - YIELD resets only directly after a CALL clause head.
     *
     * @param list<Token> $tokens
     */
    private function anchorClauseKind(
        array $tokens,
        int $start,
        int $anchorIdx,
        int $stopOffset,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): string {
        $kind = 'where_ok';
        $lastHead = null;

        for ($i = $start; $i < $anchorIdx && $i < count($tokens); $i++) {
            $t = $tokens[$i];
            if ($t->offset >= $stopOffset) {
                break;
            }
            if ($t->type !== CypherLexer::T_WORD || $t->keywordAsIdentifier
                || !$this->atDepth($t, $baseParen, $baseBracket, $baseBrace)) {
                continue;
            }

            if ($t->isWord('MATCH')) {
                $prev = $tokens[$i - 1] ?? null; // comments emit no token
                if ($prev !== null && $prev->isWord('OPTIONAL') && !$prev->keywordAsIdentifier) {
                    $kind = 'optional_match';
                } elseif (!$t->operandPosition) {
                    $kind = 'where_ok';
                }
                $lastHead = 'MATCH';
            } elseif ($t->isWord('YIELD')) {
                if ($lastHead === 'CALL' && !$t->operandPosition) {
                    $kind = 'where_ok';
                    $lastHead = 'YIELD';
                }
            } elseif ($t->isWord('WITH')) {
                // A top-level WITH is an anchor, so one here is in expression
                // position; never let it reset.
                continue;
            } elseif (CypherClauses::in($t->value, CypherClauses::NO_WHERE_HEADS)) {
                $kind = 'no_where';
                $lastHead = strtoupper($t->value);
            }
        }

        return $kind;
    }

    // ---------------------------------------------------------------------
    // Closed syntax subset (fail closed on anything not modelled)
    // ---------------------------------------------------------------------

    /**
     * Namespaced FUNCTIONS that are pure value transforms (no graph access, no
     * Cypher-from-string). Prefix match, lower-cased. Anything else namespaced
     * (`apoc.cypher.*`, `apoc.nodes.*`, `db.*`, `gds.*`, …) is rejected: it can
     * read the graph outside every pattern the analyzer sees — live,
     * `apoc.cypher.runFirstColumnMany('MATCH (i:Invoice) …')` returned every
     * tenant's 6006 invoices.
     */
    private const PURE_FUNCTION_PREFIXES = [
        'date.', 'datetime.', 'localdatetime.', 'localtime.', 'time.', 'duration.', 'point.',
        'apoc.date.', 'apoc.temporal.', 'apoc.text.', 'apoc.coll.', 'apoc.map.', 'apoc.number.', 'apoc.math.',
    ];

    /** Metadata-only procedures (return label / type / key NAMES, never nodes). */
    private const METADATA_PROCEDURES = [
        'db.labels', 'db.relationshiptypes', 'db.propertykeys',
        'db.schema.nodetypeproperties', 'db.schema.reltypeproperties',
    ];

    /**
     * Words that may never be used as an (unquoted) variable / alias. SKIP /
     * LIMIT / OFFSET / ORDER are NOT listed: the lexer resolves them
     * positionally (Token::$keywordAsIdentifier) and every walk honors that;
     * YIELD likewise (anchorClauseKind's operand guard).
     */
    private const RESERVED_IDENTIFIERS = [
        'MATCH', 'OPTIONAL', 'WITH', 'RETURN', 'CALL', 'UNION',
        'CREATE', 'MERGE', 'DELETE', 'SET', 'REMOVE', 'FOREACH', 'UNWIND', 'WHERE',
    ];

    /**
     * The reason the query falls outside the modelled syntax subset, or null.
     *
     * @param list<Token> $tokens
     */
    private function unsupportedSyntaxReason(array $tokens, string $cypher): ?string
    {
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->type !== CypherLexer::T_WORD) {
                continue;
            }

            $quoted = ($cypher[$t->offset] ?? '') === '`';
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            // Label / property / label-expression NAME position (`n.limit`, `b:Match`).
            $namePosition = $prev !== null && $prev->type === CypherLexer::T_PUNCT
                && in_array($prev->value, ['.', ':', '!', '&', '|', '%'], true);

            // (a) Keyword used as a variable (item 3/6): `OPTIONAL MATCH match = (m)`
            // flipped every clause walk; `WHERE x.v = match` truncated the
            // predicate the injector AND-merged into. Clause keywords in operand
            // position or followed by `=` are identifiers → not modelled → reject
            // (backtick-quote to use such a name).
            if (!$quoted && !$namePosition
                && CypherClauses::in($t->value, self::RESERVED_IDENTIFIERS)
                && (($next !== null && $next->isPunct('='))
                    || (!$t->keywordAsIdentifier && $this->isDefiniteOperandSlot($prev)))) {
                return 'keyword_as_identifier';
            }

            // (b) Namespaced function / procedure call `ns.name(`.
            if (!$namePosition && $next !== null && $next->isPunct('.')) {
                $parts = [$t->value];
                $j = $i;
                while (isset($tokens[$j + 2]) && $tokens[$j + 1]->isPunct('.')
                    && $tokens[$j + 2]->type === CypherLexer::T_WORD) {
                    $parts[] = $tokens[$j + 2]->value;
                    $j += 2;
                }
                if (count($parts) >= 2 && isset($tokens[$j + 1]) && $tokens[$j + 1]->isPunct('(')) {
                    $isProcedure = $prev !== null && $prev->isWord('CALL') && !$prev->keywordAsIdentifier;
                    if (!$this->isAllowedNamespacedCall(implode('.', $parts), $isProcedure)) {
                        return 'unsupported_procedure_or_function';
                    }
                }
            }

            // (c) Any CALL of a procedure (paren-less form included) outside the
            // metadata allowlist. `CALL {` / `CALL (` subqueries are handled by
            // hasUnsupportedCallSubquery / the count wrapper.
            if ($t->isWord('CALL') && !$t->keywordAsIdentifier && $next !== null && $next->type === CypherLexer::T_WORD) {
                $parts = [$next->value];
                $j = $i + 1;
                while (isset($tokens[$j + 2]) && $tokens[$j + 1]->isPunct('.')
                    && $tokens[$j + 2]->type === CypherLexer::T_WORD) {
                    $parts[] = $tokens[$j + 2]->value;
                    $j += 2;
                }
                if (!$this->isAllowedNamespacedCall(implode('.', $parts), true)) {
                    return 'unsupported_procedure_or_function';
                }
            }

            // (d) Every MATCH clause's pattern (at ANY depth: subqueries too) must
            // parse under the strict pattern grammar.
            if ($t->isWord('MATCH') && !$t->keywordAsIdentifier && !$quoted && !$namePosition) {
                $reason = $this->patternClauseReason($tokens, $i + 1, $t);
                if ($reason !== null) {
                    return $reason;
                }
            }
        }

        // (e) Node patterns OUTSIDE a MATCH clause (pattern predicates, EXISTS{}
        // / COUNT{} / COLLECT{} bodies, pattern comprehensions) are recognized by
        // their relationship arrows; each such node must parse strictly too
        // (`(x IS Invoice)--(y)`, `(a)--(x:A|B)` inside EXISTS {}).
        for ($i = 0; $i < $n; $i++) {
            if (!$tokens[$i]->isPunct('(') || !$this->isArrowConnectedNode($tokens, $i)) {
                continue;
            }
            if ($this->strictNode($tokens, $i) === null) {
                return 'unsupported_pattern_syntax';
            }
        }

        return null;
    }

    /**
     * Whether the token BEFORE a word proves the word is an OPERAND (a
     * variable / alias), never a clause head: a comparison / arithmetic / list
     * punctuation, an opening paren or bracket (node alias, argument), a
     * string-operator word, or a boolean / aliasing keyword. Positive evidence
     * only (Token::$operandPosition alone over-fires after variables named
     * like operators, e.g. `WITH ends MATCH …`). SKIP / LIMIT / ORDER the
     * lexer already resolved as identifiers are handled by every walk.
     */
    private function isDefiniteOperandSlot(?Token $prev): bool
    {
        if ($prev === null) {
            return false;
        }
        if ($prev->type === CypherLexer::T_OPERATOR) {
            return true; // `STARTS WITH match`
        }
        if ($prev->type === CypherLexer::T_PUNCT) {
            return in_array($prev->value, ['=', '<', '>', '+', '-', '/', '^', ',', '(', '['], true);
        }
        if ($prev->type === CypherLexer::T_WORD && !$prev->keywordAsIdentifier) {
            foreach (['AND', 'OR', 'XOR', 'NOT', 'WHERE', 'AS', 'IN', 'WHEN', 'THEN', 'ELSE', 'CASE', 'BY'] as $kw) {
                if ($prev->isWord($kw)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isAllowedNamespacedCall(string $name, bool $isProcedure): bool
    {
        $lower = strtolower($name);
        if ($isProcedure) {
            return in_array($lower, self::METADATA_PROCEDURES, true);
        }
        foreach (self::PURE_FUNCTION_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Strict grammar for the pattern of the MATCH clause whose first pattern
     * token is at $pos ($match = the MATCH keyword token, giving the depth):
     *
     *   pattern  := part (',' part)*
     *   part     := [var '='] node (rel node)*
     *   node     := '(' [var] (':' Label)* [map] ')'
     *   rel      := ['<'] '-' ['[' [var] [':' T ('|' [':'] T)*] ['*' range] [map] ']'] '-' ['>']
     *
     * followed by WHERE, a clause head, a closing delimiter or the end. Rejected:
     * GQL `IS` labels, inline `WHERE` in a node / relationship, label
     * expressions (`|` `&` `!` `%` `(…)`), path selectors (SHORTEST / ANY / ALL /
     * shortestPath()), quantified path patterns, match modes, USING hints, a
     * variable-length relationship bound to a variable, and a path variable over
     * a variable-length part (both expose unscoped intermediate nodes).
     *
     * @param list<Token> $tokens
     */
    private function patternClauseReason(array $tokens, int $pos, Token $match): ?string
    {
        $n = count($tokens);

        while (true) {
            $pathVar = false;
            $hasVarLength = false;

            if (isset($tokens[$pos + 1]) && $tokens[$pos]->type === CypherLexer::T_WORD
                && $tokens[$pos + 1]->isPunct('=')) {
                $pathVar = true;
                $pos += 2;
            }

            $node = $this->strictNode($tokens, $pos, $match);
            if ($node === null) {
                return 'unsupported_pattern_syntax';
            }
            $pos = $node['close'] + 1;

            while (isset($tokens[$pos]) && ($tokens[$pos]->isPunct('-') || $tokens[$pos]->isPunct('<'))) {
                $rel = $this->strictRelationship($tokens, $pos);
                if ($rel === null) {
                    return 'unsupported_pattern_syntax';
                }
                if ($rel['boundVarLength']) {
                    return 'unsupported_var_length_binding';
                }
                $hasVarLength = $hasVarLength || $rel['varLength'];

                $node = $this->strictNode($tokens, $rel['next'], $match);
                if ($node === null) {
                    return 'unsupported_pattern_syntax';
                }
                $pos = $node['close'] + 1;
            }

            if ($pathVar && $hasVarLength) {
                return 'unsupported_var_length_binding';
            }

            if (isset($tokens[$pos]) && $tokens[$pos]->isPunct(',') && $this->sameDepthAs($tokens[$pos], $match)) {
                $pos++;
                continue;
            }
            break;
        }

        if ($pos >= $n) {
            return null;
        }
        $t = $tokens[$pos];
        if ($t->type === CypherLexer::T_PUNCT) {
            // A closing delimiter of the enclosing context, or a trailing ';'.
            return in_array($t->value, ['}', ')', ']', ';'], true) ? null : 'unsupported_pattern_syntax';
        }
        if ($t->type === CypherLexer::T_WORD && !$t->keywordAsIdentifier && $this->sameDepthAs($t, $match)
            && ($t->isWord('WHERE') || CypherClauses::in($t->value, CypherClauses::CLAUSE_HEADS))) {
            return null;
        }

        return 'unsupported_pattern_syntax';
    }

    /**
     * Strictly parse a node pattern `( [var] (':' Label)* [map] )` at $open.
     * Returns the alias, the label conjunction and the closing-paren index, or
     * null for anything else (IS labels, inline WHERE, label expressions,
     * nested parens of a quantified path pattern, a `$param` map, …).
     *
     * @param list<Token> $tokens
     * @return array{alias: ?string, labels: list<string>, close: int}|null
     */
    private function strictNode(array $tokens, int $open, ?Token $depthOf = null): ?array
    {
        $o = $tokens[$open] ?? null;
        if ($o === null || !$o->isPunct('(') || ($depthOf !== null && !$this->sameDepthAs($o, $depthOf))) {
            return null;
        }

        $n = count($tokens);
        $k = $open + 1;
        $alias = null;
        $labels = [];

        if ($k < $n && $tokens[$k]->type === CypherLexer::T_WORD) {
            $alias = $tokens[$k]->value;
            $k++;
        }
        while ($k + 1 < $n && $tokens[$k]->isPunct(':') && $tokens[$k + 1]->type === CypherLexer::T_WORD) {
            $labels[] = $tokens[$k + 1]->value;
            $k += 2;
        }
        if ($k < $n && $tokens[$k]->isPunct('{')) {
            $close = $this->matchingClose($tokens, $k, '}');
            if ($close === null) {
                return null;
            }
            $k = $close + 1;
        }
        if ($k < $n && $tokens[$k]->isPunct(')') && $tokens[$k]->parenDepth === $o->parenDepth
            && $tokens[$k]->bracketDepth === $o->bracketDepth && $tokens[$k]->braceDepth === $o->braceDepth) {
            return ['alias' => $alias, 'labels' => $labels, 'close' => $k];
        }

        return null;
    }

    /**
     * Strictly parse a relationship pattern starting at $pos ('<' or '-').
     *
     * @param list<Token> $tokens
     * @return array{next:int, varLength:bool, boundVarLength:bool}|null
     */
    private function strictRelationship(array $tokens, int $pos): ?array
    {
        $n = count($tokens);
        if (isset($tokens[$pos]) && $tokens[$pos]->isPunct('<')) {
            $pos++;
        }
        if (!isset($tokens[$pos]) || !$tokens[$pos]->isPunct('-')) {
            return null;
        }
        $pos++;

        $varLength = false;
        $bound = false;

        if (isset($tokens[$pos]) && $tokens[$pos]->isPunct('[')) {
            $close = $this->matchingClose($tokens, $pos, ']');
            if ($close === null) {
                return null;
            }
            $k = $pos + 1;
            if ($k < $close && $tokens[$k]->type === CypherLexer::T_WORD) {
                $bound = true; // relationship variable
                $k++;
            }
            if ($k < $close && $tokens[$k]->isPunct(':')) {
                $k++;
                if ($k >= $close || $tokens[$k]->type !== CypherLexer::T_WORD) {
                    return null;
                }
                $k++;
                while ($k < $close && $tokens[$k]->isPunct('|')) {
                    $k++;
                    if ($k < $close && $tokens[$k]->isPunct(':')) {
                        $k++;
                    }
                    if ($k >= $close || $tokens[$k]->type !== CypherLexer::T_WORD) {
                        return null;
                    }
                    $k++;
                }
            }
            if ($k < $close && $tokens[$k]->isPunct('*')) {
                $varLength = true;
                $k++;
                while ($k < $close && ($tokens[$k]->isPunct('.')
                    || ($tokens[$k]->type === CypherLexer::T_WORD && preg_match('/^[0-9.]+$/', $tokens[$k]->value) === 1))) {
                    $k++;
                }
            }
            if ($k < $close && $tokens[$k]->isPunct('{')) {
                $mapClose = $this->matchingClose($tokens, $k, '}');
                if ($mapClose === null || $mapClose >= $close) {
                    return null;
                }
                $k = $mapClose + 1;
            }
            if ($k !== $close) {
                return null; // inline WHERE, `!TYPE`, `&`, …
            }
            $pos = $close + 1;
        }

        if (!isset($tokens[$pos]) || !$tokens[$pos]->isPunct('-')) {
            return null;
        }
        $pos++;
        if (isset($tokens[$pos]) && $tokens[$pos]->isPunct('>')) {
            $pos++;
        }

        return ['next' => $pos, 'varLength' => $varLength, 'boundVarLength' => $bound && $varLength];
    }

    /**
     * Index of the delimiter closing the opener at $open (same reported depth).
     *
     * @param list<Token> $tokens
     */
    private function matchingClose(array $tokens, int $open, string $closer): ?int
    {
        $o = $tokens[$open];
        for ($i = $open + 1, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->isPunct($closer) && $t->parenDepth === $o->parenDepth
                && $t->bracketDepth === $o->bracketDepth && $t->braceDepth === $o->braceDepth) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Whether the '(' at $open is a NODE of a path pattern: its matching ')' is
     * directly followed by a relationship start (`)-[`, `)--`, `)<-`), or it is
     * directly preceded by a relationship end (`]-(`, `--(`, `->(`). Grouping /
     * call parens (`(a + b) - c`, `x > (y)`, `count(x)`) never match.
     *
     * @param list<Token> $tokens
     */
    private function isArrowConnectedNode(array $tokens, int $open): bool
    {
        $p1 = $tokens[$open - 1] ?? null;
        $p2 = $tokens[$open - 2] ?? null;
        if ($p1 !== null && $p2 !== null
            && (($p1->isPunct('-') && ($p2->isPunct('-') || $p2->isPunct(']')))
                || ($p1->isPunct('>') && $p2->isPunct('-')))) {
            return true;
        }

        $close = $this->matchingClose($tokens, $open, ')');
        if ($close === null) {
            return false;
        }
        $a1 = $tokens[$close + 1] ?? null;
        $a2 = $tokens[$close + 2] ?? null;
        return $a1 !== null && $a2 !== null
            && (($a1->isPunct('-') && ($a2->isPunct('-') || $a2->isPunct('[')))
                || ($a1->isPunct('<') && $a2->isPunct('-')));
    }

    private function sameDepthAs(Token $a, Token $b): bool
    {
        return $a->parenDepth === $b->parenDepth
            && $a->bracketDepth === $b->bracketDepth
            && $a->braceDepth === $b->braceDepth;
    }

    // ---------------------------------------------------------------------
    // Structural helpers
    // ---------------------------------------------------------------------

    /**
     * Whether a token is at the given relative depth (depth equal to the base of
     * the range it belongs to). Used to find "top-level for this part" tokens.
     */
    private function atDepth(Token $t, int $baseParen, int $baseBracket, int $baseBrace): bool
    {
        return $t->parenDepth === $baseParen
            && $t->bracketDepth === $baseBracket
            && $t->braceDepth === $baseBrace;
    }

    /** @param list<Token> $tokens */
    private function hasTopLevelStatementSeparator(array $tokens): bool
    {
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->isPunct(';') && $t->isTopLevel()) {
                // Allowed only if it is the final structural token (trailing ';').
                for ($j = $i + 1; $j < $n; $j++) {
                    return true; // there is something after it → multi statement
                }
            }
        }
        return false;
    }

    /**
     * Match the EXACT count wrapper `CALL { <inner> } RETURN count(*) [AS x]`
     * produced by executeCount(). The outer projection must be ONLY count(*) — it
     * may not reference inner variables (that is corpus 9, an out-of-subset shape).
     *
     * Returns inner token range + inner base depths, or null.
     *
     * @param list<Token> $tokens
     * @return array{innerStart:int, innerEnd:int, innerBaseParen:int, innerBaseBracket:int, innerBaseBrace:int}|null
     */
    private function matchCountWrapper(array $tokens): ?array
    {
        $n = count($tokens);
        if ($n < 4) {
            return null;
        }

        // Must START with: CALL {
        if (!$tokens[0]->isWord('CALL') || !$tokens[0]->isTopLevel()) {
            return null;
        }
        if (!$tokens[1]->isPunct('{') || !$tokens[1]->isTopLevel()) {
            return null;
        }

        // Find the matching top-level '}' that closes this CALL block. The opening
        // '{' is reported at brace depth 0; its matching close is reported at depth 0
        // too (the lexer decrements before reporting the closer).
        $closeIdx = null;
        for ($i = 2; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->isPunct('}') && $t->parenDepth === 0 && $t->bracketDepth === 0 && $t->braceDepth === 0) {
                $closeIdx = $i;
                break;
            }
        }

        if ($closeIdx === null) {
            return null;
        }

        // The outer projection must be EXACTLY: RETURN count ( ... ) [AS <word>]
        // and nothing else. Anything richer (commas, extra projections, references
        // to inner vars) is not the wrapper we generate → not accepted here.
        if (!$this->isExactCountReturn($tokens, $closeIdx + 1, $n)) {
            return null;
        }

        return [
            'innerStart' => 2,
            'innerEnd' => $closeIdx,
            'innerBaseParen' => 0,
            'innerBaseBracket' => 0,
            'innerBaseBrace' => 1,
        ];
    }

    /**
     * Whether tokens [start,end) are exactly `RETURN count ( <anything balanced> )
     * [AS <word>]` with no trailing tokens (ignoring a trailing ';').
     *
     * @param list<Token> $tokens
     */
    private function isExactCountReturn(array $tokens, int $start, int $end): bool
    {
        // Trim a trailing ';'.
        while ($end > $start && $tokens[$end - 1]->isPunct(';')) {
            $end--;
        }

        if ($end - $start < 4) {
            return false; // need at least: RETURN count ( )
        }

        $i = $start;
        if (!$tokens[$i]->isWord('RETURN')) {
            return false;
        }
        $i++;
        if (!$tokens[$i]->isWord('count')) {
            return false;
        }
        $i++;
        if (!$tokens[$i]->isPunct('(')) {
            return false;
        }
        // Skip a balanced ( ... ). The opener is at paren depth 0; its matching
        // close is reported at depth 0 too.
        $i++;
        $closed = false;
        for (; $i < $end; $i++) {
            if ($tokens[$i]->isPunct(')') && $tokens[$i]->parenDepth === 0
                && $tokens[$i]->bracketDepth === 0 && $tokens[$i]->braceDepth === 0) {
                $closed = true;
                $i++;
                break;
            }
        }
        if (!$closed) {
            return false;
        }

        // Optional: AS <word>
        if ($i < $end && $tokens[$i]->isWord('AS')) {
            $i++;
            if ($i >= $end || $tokens[$i]->type !== CypherLexer::T_WORD) {
                return false;
            }
            $i++;
        }

        return $i === $end; // nothing else may follow
    }

    /**
     * Detect a CALL{} subquery in the range that is NOT permitted. (Within an
     * already-extracted inner/plain query, NO CALL{} is permitted at all.)
     *
     * @param list<Token> $tokens
     */
    private function hasUnsupportedCallSubquery(array $tokens, int $start, int $end): bool
    {
        for ($i = $start; $i < $end - 1; $i++) {
            if ($tokens[$i]->isWord('CALL') && !$tokens[$i]->keywordAsIdentifier) {
                // CALL followed by '{' (ignoring nothing — lexer drops whitespace).
                // The variable-scope form `CALL (x) { … }` is analyzed in place:
                // any node its body MATCHes must be a live back-reference
                // (extractBindings), so `CALL () { MATCH (n) … }` — live: 97,846
                // foreign nodes — is rejected there.
                if ($tokens[$i + 1]->isPunct('{')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Whether the range contains a MATCH that binds a node pattern `(`.
     *
     * @param list<Token> $tokens
     */
    private function hasNodeMatch(array $tokens, int $start, int $end): bool
    {
        // ANY MATCH clause counts (`MATCH p = (…)`, `MATCH SHORTEST …` bind
        // nodes too), so an un-modelled shape is never mistaken for a scalar
        // query that may run unfiltered.
        for ($i = $start; $i < $end; $i++) {
            if ($tokens[$i]->isWord('MATCH') && !$tokens[$i]->keywordAsIdentifier) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split a range into UNION parts on top-level (relative-depth) UNION [ALL].
     * Returns inclusive-exclusive [start,end) token index ranges.
     *
     * @param list<Token> $tokens
     * @return list<array{0:int,1:int}>
     */
    private function splitUnionParts(
        array $tokens,
        int $start,
        int $end,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): array {
        $parts = [];
        $partStart = $start;

        for ($i = $start; $i < $end; $i++) {
            $t = $tokens[$i];
            if ($t->isWord('UNION') && $this->atDepth($t, $baseParen, $baseBracket, $baseBrace)) {
                $parts[] = [$partStart, $i];
                // Skip an optional ALL after UNION.
                $next = $i + 1;
                if ($next < $end && $tokens[$next]->isWord('ALL')) {
                    $i = $next;
                }
                $partStart = $i + 1;
            }
        }

        $parts[] = [$partStart, $end];

        return $parts;
    }

    /**
     * First WITH|RETURN|ORDER at the part's own depth, or null.
     *
     * @param list<Token> $tokens
     */
    private function findAnchorIndex(
        array $tokens,
        int $start,
        int $end,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): ?int {
        for ($i = $start; $i < $end; $i++) {
            $t = $tokens[$i];
            if (!$this->atDepth($t, $baseParen, $baseBracket, $baseBrace)) {
                continue;
            }
            if ($t->keywordAsIdentifier) {
                continue; // a variable named `order` (no BY), not ORDER BY
            }
            foreach (self::ANCHOR_KEYWORDS as $kw) {
                if ($t->isWord($kw)) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Extract every sensitive (labelled) node-binding OCCURRENCE in the part,
     * and detect sensitive bindings hidden in comprehensions / subqueries or
     * carried by a label-free node access (which we cannot anchor → reject).
     *
     * Scans the WHOLE part: anchors are derived per occurrence afterwards
     * (WITH-segmentation), so a leading `WITH const` cannot pre-empt the real
     * primary-MATCH boundary, and a re-binding of the same alias after a WITH
     * is a NEW occurrence that gets scoped in its own segment.
     *
     * Back-references: a label-free `(u)` whose alias received a :Label earlier
     * in the part is a back-reference ONLY while the alias is still LIVE at
     * that point (projected through every intervening top-level WITH). A DEAD
     * back-reference — the alias was projected away — is a fresh unconstrained
     * variable wearing a scoped variable's name; tenancy is unprovable → reject.
     *
     * @param list<Token> $tokens
     * @return array{occurrences: list<array{alias:string,label:string,tokenIdx:int,optional:bool}>, reason: ?string}
     */
    private function extractBindings(
        array $tokens,
        int $start,
        int $end,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): array {
        // Pre-pass: for every alias, the token indexes where it receives a :Label
        // at the part's own depth (used for live-back-reference verification).
        $labelledAliasIdx = [];
        for ($i = $start; $i < $end; $i++) {
            if (!$tokens[$i]->isPunct('(')) {
                continue;
            }
            if ($tokens[$i]->bracketDepth > $baseBracket || $tokens[$i]->braceDepth > $baseBrace) {
                continue;
            }
            $parsed = $this->parseNodePattern($tokens, $i, $end);
            if ($parsed !== null && $parsed['label'] !== null && $parsed['alias'] !== null) {
                $labelledAliasIdx[$parsed['alias']][] = $i;
            }
        }

        $occurrences = [];

        for ($i = $start; $i < $end; $i++) {
            $t = $tokens[$i];

            // A node pattern opens with '('.
            if (!$t->isPunct('(')) {
                continue;
            }

            $parsed = $this->parseNodePattern($tokens, $i, $end);
            if ($parsed === null) {
                continue;
            }

            // Is this pattern buried in a comprehension `[ ... ]` or subquery `{ ... }`?
            $insideComprehension = $t->bracketDepth > $baseBracket;
            $insideSubquery = $t->braceDepth > $baseBrace;

            if ($parsed['label'] !== null && ($insideComprehension || $insideSubquery)) {
                // A sensitive node we cannot anchor structurally → reject.
                return ['occurrences' => [], 'reason' => 'unsupported_pattern_scope'];
            }

            // Label-free nodes inside comprehensions / subqueries: allowed ONLY as
            // a live back-reference to an outer labelled (hence scoped) binding.
            // A NEW variable or an anonymous node there is an unscoped graph
            // read the injector never sees — live, `COLLECT { MATCH (n) … }`,
            // `CALL () { MATCH (n) … }` and `COUNT { MATCH (x)-->(y) }` returned
            // 97,846 foreign nodes / 63,498 foreign links (round 2). Only real
            // pattern nodes count (inside a MATCH clause, or arrow-connected);
            // grouping / call parens like `toUpper(x)` are not node patterns.
            if ($insideComprehension || $insideSubquery) {
                if (!$this->isNestedPatternNode($tokens, $i, $start)) {
                    continue;
                }
                $alias = $parsed['alias'];
                if ($alias === null
                    || !$this->isLiveBackReference($tokens, $labelledAliasIdx[$alias] ?? [], $i, $alias, $baseParen, $baseBracket, $baseBrace, false, $start)) {
                    return ['occurrences' => [], 'reason' => 'unsupported_pattern_scope'];
                }
                continue;
            }

            // Only top-level labelled patterns are tenant-sensitive bindings.
            if ($parsed['label'] === null) {
                // A label-free node bound by a MATCH whose alias is NEVER labelled
                // anywhere in the part is a genuinely untyped node access; tenancy
                // is unprovable → reject.
                if (!$this->isMatchedNodePattern($tokens, $i, $start, $baseParen, $baseBracket, $baseBrace)) {
                    continue;
                }
                $alias = $parsed['alias'];
                if ($alias !== null && $this->isLiveBackReference($tokens, $labelledAliasIdx[$alias] ?? [], $i, $alias, $baseParen, $baseBracket, $baseBrace, true, $start)) {
                    continue; // live back-reference to a labelled binding
                }
                if ($alias !== null && !empty($labelledAliasIdx[$alias])) {
                    // Labelled somewhere, but DEAD here (projected away by an
                    // intervening WITH): a fresh unconstrained variable wearing a
                    // scoped name — never silently treat it as the scoped binding.
                    return ['occurrences' => [], 'reason' => 'sensitive_binding_not_live'];
                }
                return ['occurrences' => [], 'reason' => 'label_free_sensitive'];
            }

            // Labelled but alias-free (`(:Label)`) → no variable to scope on.
            if ($parsed['alias'] === null) {
                return ['occurrences' => [], 'reason' => 'label_free_sensitive'];
            }

            // The alias is interpolated VERBATIM into the team filter
            // (`WHERE <alias>.team_id IN $ai_team_ids`, `MATCH (<alias>)-…`). A
            // backtick alias carrying Cypher (`` (`true OR x`:Invoice) `` →
            // `WHERE true OR x.team_id IN …`) turned the filter into a tautology
            // and ran every tenant's rows (Task 1.26b). Only a plain ASCII
            // identifier may be scoped; anything else (backtick / Unicode /
            // escaped aliases) is un-provable → fail closed.
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $parsed['alias']) !== 1) {
                return ['occurrences' => [], 'reason' => 'non_identifier_alias'];
            }

            $optional = $this->isOptionalMatchedPattern($tokens, $i, $start, $baseParen, $baseBracket, $baseBrace);
            foreach ($parsed['labels'] ?? [$parsed['label']] as $label) {
                $occurrences[] = [
                    'alias' => $parsed['alias'],
                    'label' => $label,
                    'tokenIdx' => $i,
                    // Bound by OPTIONAL MATCH → may be null at the anchor; the
                    // injector must scope it null-tolerantly. (A variable ALSO
                    // bound by an earlier plain MATCH is never null; marking it
                    // optional anyway only adds a dead IS-NULL disjunct — the
                    // team scope is still enforced on every non-null value.)
                    'optional' => $optional,
                ];
            }
        }

        return ['occurrences' => $occurrences, 'reason' => null];
    }

    /**
     * Whether the label-free node reference at $refIdx is a LIVE back-reference:
     * some earlier labelled occurrence of $alias exists whose projection chain
     * keeps the alias in scope at $refIdx (or a labelled occurrence follows in
     * the same part — a label re-check, which the labelled occurrence itself
     * scopes — provided the alias flows UNBROKEN between the two).
     *
     * BOTH directions require alias liveness across any intervening top-level
     * WITH. The forward direction is NOT exempt: in
     * `MATCH (u) WITH collect(u.x) AS xs MATCH (u:User) ...` the WITH drops u,
     * so the labelled `(u:User)` is a FRESH variable that must never vouch for
     * the earlier label-free full-graph scan — accepting it would launder an
     * unscoped cross-tenant read into an apparently-scoped query.
     *
     * @param list<Token> $tokens
     * @param list<int>   $labelledIdxs Token indexes where $alias is labelled.
     */
    private function isLiveBackReference(
        array $tokens,
        array $labelledIdxs,
        int $refIdx,
        string $alias,
        int $baseParen,
        int $baseBracket,
        int $baseBrace,
        bool $allowForward = true,
        int $partStart = 0
    ): bool {
        foreach ($labelledIdxs as $labelledIdx) {
            if ($labelledIdx > $refIdx) {
                // Forward label re-check (`MATCH (u) MATCH (u:Union)`): the
                // labelled occurrence constrains and scopes the variable —
                // but ONLY while it is the SAME variable, i.e. no intervening
                // WITH projects the alias away between the reference and the
                // labelled occurrence (mirror of the backward guard below),
                // and ONLY when that occurrence is a MANDATORY MATCH. An
                // OPTIONAL MATCH never constrains an already-bound variable
                // (no match → the row survives with x unchanged), so
                // `MATCH (x) OPTIONAL MATCH (x:Invoice)` left x a label-free
                // full scan — live: 738,895 rows incl. 3,963 foreign invoices.
                if (!$allowForward
                    || $this->isOptionalMatchedPattern($tokens, $labelledIdx, $partStart, $baseParen, $baseBracket, $baseBrace)) {
                    continue;
                }
                if ($this->aliasLiveBetween($tokens, $refIdx, $labelledIdx, $alias, $baseParen, $baseBracket, $baseBrace)) {
                    return true;
                }
                continue;
            }
            if ($this->aliasLiveBetween($tokens, $labelledIdx, $refIdx, $alias, $baseParen, $baseBracket, $baseBrace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the node pattern at index $open is part of a MATCH/OPTIONAL MATCH
     * graph pattern (i.e. a node the query actually binds), as opposed to a node
     * pattern appearing only inside an expression. A label-free node bound by a
     * MATCH makes tenancy unprovable.
     *
     * @param list<Token> $tokens
     */
    private function isMatchedNodePattern(
        array $tokens,
        int $open,
        int $partStart,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): bool {
        // Walk left to the nearest clause keyword at the part's depth. If it is a
        // MATCH (optionally preceded by OPTIONAL), this node is matched.
        for ($i = $open - 1; $i >= $partStart; $i--) {
            $t = $tokens[$i];
            if (!$this->atDepth($t, $baseParen, $baseBracket, $baseBrace)) {
                continue;
            }
            if ($t->type !== CypherLexer::T_WORD || $t->keywordAsIdentifier) {
                continue;
            }
            if ($t->isWord('MATCH')) {
                return true;
            }
            if ($this->isClauseKeyword($t)) {
                // Hit a non-MATCH clause keyword first → not a matched node here.
                return false;
            }
        }
        return false;
    }

    /**
     * Whether the node pattern at index $open belongs to an OPTIONAL MATCH
     * clause: walking left to the nearest clause keyword at the part's depth
     * finds a MATCH directly preceded by OPTIONAL. Mirrors
     * {@see isMatchedNodePattern} (same walk, same depth rules).
     *
     * @param list<Token> $tokens
     */
    private function isOptionalMatchedPattern(
        array $tokens,
        int $open,
        int $partStart,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): bool {
        for ($i = $open - 1; $i >= $partStart; $i--) {
            $t = $tokens[$i];
            if (!$this->atDepth($t, $baseParen, $baseBracket, $baseBrace)) {
                continue;
            }
            if ($t->type !== CypherLexer::T_WORD || $t->keywordAsIdentifier) {
                continue; // e.g. a path variable named `match` (`OPTIONAL MATCH match = …`)
            }
            if ($t->isWord('MATCH')) {
                $prev = $tokens[$i - 1] ?? null;
                return $prev !== null && $prev->isWord('OPTIONAL') && !$prev->keywordAsIdentifier;
            }
            if ($this->isClauseKeyword($t)) {
                // Hit a non-MATCH clause keyword first → not an OPTIONAL-matched
                // node here (expression context / other clause).
                return false;
            }
        }
        return false;
    }

    /**
     * Whether the '(' at $open (inside a comprehension / subquery) is a real
     * graph-pattern NODE: arrow-connected, or inside a MATCH clause at its own
     * nesting level. Grouping / function-call parens are not.
     *
     * @param list<Token> $tokens
     */
    private function isNestedPatternNode(array $tokens, int $open, int $partStart): bool
    {
        if ($this->isArrowConnectedNode($tokens, $open)) {
            return true;
        }

        $o = $tokens[$open];
        for ($i = $open - 1; $i >= $partStart; $i--) {
            $t = $tokens[$i];
            if ($t->braceDepth < $o->braceDepth || $t->bracketDepth < $o->bracketDepth || $t->parenDepth < $o->parenDepth) {
                return false; // left the nested context
            }
            if (!$this->sameDepthAs($t, $o) || $t->type !== CypherLexer::T_WORD || $t->keywordAsIdentifier) {
                continue;
            }
            if ($t->isWord('MATCH')) {
                return true;
            }
            if ($this->isClauseKeyword($t)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Whether $alias (as a word: a node variable, a WITH / UNWIND / YIELD /
     * AS name, …) appears anywhere in [$start, $idx) — i.e. the variable may
     * already be BOUND when the pattern at $idx is matched. Property keys
     * (`n.x`) do not count. Over-approximation is the safe direction: it only
     * moves an OPTIONAL scope from the clause WHERE to a strictly filtering
     * row-level `WITH * WHERE`.
     *
     * @param list<Token> $tokens
     */
    private function aliasAppearsBefore(array $tokens, int $start, int $idx, string $alias): bool
    {
        for ($i = $start; $i < $idx; $i++) {
            $t = $tokens[$i];
            if ($t->type === CypherLexer::T_WORD && $t->value === $alias
                && !(isset($tokens[$i - 1]) && $tokens[$i - 1]->isPunct('.'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Locate the OPTIONAL MATCH clause that binds the node pattern at $open and
     * return where its OWN WHERE is (or where a fresh one goes).
     *
     *   insertOffset   — first clause-level token after the clause's pattern:
     *                    its WHERE keyword, or the next clause head / the anchor;
     *   whereOffset    — the clause's own WHERE keyword (-1 if none);
     *   whereEndOffset — just past that WHERE's predicate: the next clause head
     *                    at this depth, else the anchor (-1 if no WHERE).
     *
     * Only tokens at the part's own depth count, so a WHERE inside an inline
     * node predicate / EXISTS{} / comprehension, or a MATCH inside an EXISTS{}
     * subquery, never terminates the clause. Keyword-named identifiers
     * (Token::$keywordAsIdentifier) are skipped. Returns -1 offsets when the
     * clause cannot be located (the injector then fails toward a strictly
     * filtering row-level predicate, never toward an unscoped one).
     *
     * @param list<Token> $tokens
     * @return array{insertOffset:int, whereOffset:int, whereEndOffset:int}
     */
    private function optionalClauseInfo(
        array $tokens,
        int $open,
        int $segStart,
        int $anchorIdx,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): array {
        $unknown = ['insertOffset' => -1, 'whereOffset' => -1, 'whereEndOffset' => -1];

        // 1) Walk left to the clause's MATCH (same walk as isOptionalMatchedPattern).
        $matchIdx = null;
        for ($i = $open - 1; $i >= $segStart; $i--) {
            $t = $tokens[$i];
            if (!$this->atDepth($t, $baseParen, $baseBracket, $baseBrace) || $t->type !== CypherLexer::T_WORD
                || $t->keywordAsIdentifier) {
                continue;
            }
            if ($t->isWord('MATCH')) {
                $prev = $tokens[$i - 1] ?? null;
                if ($prev !== null && $prev->isWord('OPTIONAL') && !$prev->keywordAsIdentifier) {
                    $matchIdx = $i;
                }
                break;
            }
            if ($this->isClauseKeyword($t)) {
                break;
            }
        }
        if ($matchIdx === null) {
            return $unknown;
        }

        // 2) Walk right to the first clause-level terminator: the clause's own
        // WHERE, or the next clause head (OPTIONAL/MATCH/WITH/UNWIND/…/anchor).
        $isTerminator = function (Token $t) use ($baseParen, $baseBracket, $baseBrace): bool {
            return $this->atDepth($t, $baseParen, $baseBracket, $baseBrace)
                && $t->type === CypherLexer::T_WORD
                && !$t->keywordAsIdentifier
                && ($t->isWord('WHERE') || CypherClauses::in($t->value, CypherClauses::CLAUSE_HEADS));
        };

        $termIdx = null;
        for ($i = $open + 1; $i <= $anchorIdx && $i < count($tokens); $i++) {
            if ($i === $anchorIdx || $isTerminator($tokens[$i])) {
                if ($tokens[$i]->operandPosition) {
                    // A clause keyword in OPERAND position (`WHERE x.v = match`)
                    // is an expression, not the clause end (item 6) — never
                    // split a predicate there. (The syntax pre-pass rejects it.)
                    return $unknown;
                }
                $termIdx = $i;
                break;
            }
        }
        if ($termIdx === null) {
            return $unknown;
        }

        if (!$tokens[$termIdx]->isWord('WHERE') || $termIdx === $anchorIdx) {
            return ['insertOffset' => $tokens[$termIdx]->offset, 'whereOffset' => -1, 'whereEndOffset' => -1];
        }

        // 3) The clause has its own WHERE: its predicate ends at the next clause
        // head at this depth (never at a nested MATCH), else at the anchor.
        $endIdx = $anchorIdx;
        for ($i = $termIdx + 1; $i < $anchorIdx; $i++) {
            $t = $tokens[$i];
            if ($t->isWord('WHERE') && $this->atDepth($t, $baseParen, $baseBracket, $baseBrace) && !$t->keywordAsIdentifier) {
                return $unknown; // a second WHERE at this depth → shape not understood
            }
            if ($this->atDepth($t, $baseParen, $baseBracket, $baseBrace) && $this->isPredicateTerminatingKeyword($t)) {
                $endIdx = $i;
                break;
            }
        }

        return [
            'insertOffset' => $tokens[$termIdx]->offset,
            'whereOffset' => $tokens[$termIdx]->offset,
            'whereEndOffset' => $tokens[$endIdx]->offset,
        ];
    }

    /**
     * Parse a node pattern starting at the '(' token index. Returns alias/label
     * or null if this '(' does not open a node pattern (e.g. a grouping paren or
     * a function call).
     *
     * Handles: (a:Label) (a:Label {..}) (a) ({..}) (:Label) (a:`Label`)
     *
     * @param list<Token> $tokens
     * @return array{alias: ?string, label: ?string}|null
     */
    private function parseNodePattern(array $tokens, int $open, int $end): ?array
    {
        // Token after '(' :
        $idx = $open + 1;
        if ($idx >= $end) {
            return null;
        }

        $alias = null;
        $label = null;

        $t = $tokens[$idx];

        // Optional alias word.
        if ($t->type === CypherLexer::T_WORD && !$this->looksLikeKeyword($t->value)) {
            // Could be alias. But a grouping paren like (a + b) would have a word
            // then an operator; we only treat as node pattern if followed by ':'
            // or ')' or '{'.
            $next = $tokens[$idx + 1] ?? null;
            if ($next === null) {
                return null;
            }
            if ($next->isPunct(':')) {
                $alias = $t->value;
                $idx += 2; // consume alias + ':'
            } elseif ($next->isPunct(')')) {
                // (a) — anonymous-labelled binding, no label → not sensitive.
                return ['alias' => $t->value, 'label' => null];
            } elseif ($next->isPunct('{')) {
                // (a {props}) — label-free node with props.
                return ['alias' => $t->value, 'label' => null];
            } else {
                // Grouping/expression paren, not a node pattern.
                return null;
            }
        } elseif ($t->isPunct(':')) {
            // (:Label) — labelled, alias-free.
            $idx += 1;
        } elseif ($t->isPunct('{')) {
            // ({props}) — label-free, alias-free.
            return ['alias' => null, 'label' => null];
        } elseif ($t->isPunct(')')) {
            // () — empty node.
            return ['alias' => null, 'label' => null];
        } else {
            // Something else (expression) — not a node pattern.
            return null;
        }

        // Now expect a label word, then optionally more `:Label` (a plain label
        // CONJUNCTION — every label is a separate binding, each scoped; label
        // expressions `| & ! %` are rejected by the syntax pre-pass).
        if ($idx < $end && $tokens[$idx]->type === CypherLexer::T_WORD) {
            $label = $tokens[$idx]->value;
            $labels = [$label];
            $k = $idx + 1;
            while ($k + 1 < $end && $tokens[$k]->isPunct(':') && $tokens[$k + 1]->type === CypherLexer::T_WORD) {
                $labels[] = $tokens[$k + 1]->value;
                $k += 2;
            }
            return ['alias' => $alias, 'label' => $label, 'labels' => $labels];
        }

        // alias with ':' but no word label (unusual) — treat as label-free.
        return ['alias' => $alias, 'label' => null];
    }

    /**
     * Whether $alias is still live at the anchor, given it was bound at
     * $bindingIdx. A WITH projection between the binding and the anchor that does
     * NOT carry $alias (and is not `WITH *`) drops it.
     *
     * @param list<Token> $tokens
     */
    private function aliasLiveBetween(
        array $tokens,
        int $bindingIdx,
        int $anchorIdx,
        string $alias,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): bool {
        // Find the LAST WITH at this part's depth strictly after the binding and
        // before the anchor.
        $withIdx = null;
        for ($i = $bindingIdx + 1; $i < $anchorIdx; $i++) {
            $t = $tokens[$i];
            if ($t->isWord('WITH') && $this->atDepth($t, $baseParen, $baseBracket, $baseBrace)) {
                $withIdx = $i;
            }
        }

        if ($withIdx === null) {
            return true; // no projection between binding and anchor → stays live
        }

        // The WITH projection list runs until the next clause keyword at this
        // depth (WHERE, ORDER, MATCH, UNWIND, …), else up to the bound. Stopping
        // only at WHERE/ORDER under-bounded the list when the range extends past
        // the projection into a following clause (the live-back-reference check
        // passes an arbitrary reference index as the bound).
        $projEnd = $anchorIdx;
        for ($i = $withIdx + 1; $i < $anchorIdx; $i++) {
            $t = $tokens[$i];
            if ($this->atDepth($t, $baseParen, $baseBracket, $baseBrace)
                && ($t->isWord('WHERE') || $this->isPredicateTerminatingKeyword($t))) {
                $projEnd = $i;
                break;
            }
        }

        $live = [];
        $i = $withIdx + 1;
        while ($i < $projEnd) {
            $itemStart = $i;
            $itemEnd = $projEnd;
            for ($j = $i; $j < $projEnd; $j++) {
                $tj = $tokens[$j];
                if ($tj->isPunct(',') && $this->atDepth($tj, $baseParen, $baseBracket, $baseBrace)) {
                    $itemEnd = $j;
                    break;
                }
            }
            $this->collectWithItemAlias($tokens, $itemStart, $itemEnd, $baseParen, $baseBracket, $baseBrace, $live);
            $i = $itemEnd + 1;
        }

        return in_array('*', $live, true) || in_array($alias, $live, true);
    }

    /**
     * Add the alias that a single WITH item keeps live to $live.
     *
     * @param list<Token> $tokens
     * @param list<string> $live
     */
    private function collectWithItemAlias(
        array $tokens,
        int $start,
        int $end,
        int $baseParen,
        int $baseBracket,
        int $baseBrace,
        array &$live
    ): void {
        // '*' → everything live. We signal that with the sentinel '*'.
        for ($i = $start; $i < $end; $i++) {
            if ($tokens[$i]->isPunct('*')) {
                $live[] = '*';
                return;
            }
        }

        // 'expr AS y' → carries y.
        for ($i = $start; $i < $end; $i++) {
            if ($tokens[$i]->isWord('AS')) {
                if ($i + 1 < $end && $tokens[$i + 1]->type === CypherLexer::T_WORD) {
                    $live[] = $tokens[$i + 1]->value;
                }
                return;
            }
        }

        // Bare single-variable item `x` (item is exactly one word, possibly with
        // trailing property access `x.foo` which does NOT keep x as a node var).
        if ($end - $start === 1 && $tokens[$start]->type === CypherLexer::T_WORD) {
            $live[] = $tokens[$start]->value;
        }
        // `x.foo` (word '.' word ...) does not carry x as a live node variable.
    }

    /**
     * Find this part's own WHERE (between bindings and the anchor), the byte offset
     * just past its predicate, and whether that predicate contains a top-level OR.
     *
     * The predicate END is bounded at the FIRST top-level clause keyword that
     * follows the WHERE — an intervening MATCH / OPTIONAL MATCH / WITH / etc. that
     * appears before the anchor. Without this bound the injector would wrap that
     * whole intervening clause inside the OR-parenthesization, producing invalid
     * Cypher (a clause keyword trapped inside a WHERE boolean expression). If no
     * such clause exists, the predicate ends at the anchor. Detection is token- and
     * depth-based, so a keyword inside a string literal (opaque T_LITERAL) or inside
     * a `(...)`/`[...]`/`{...}` group (depth > base) is never matched.
     *
     * @param list<Token> $tokens
     * @return array{whereOffset:int, whereEndOffset:int, hasTopLevelOr:bool}
     */
    private function findWhere(
        array $tokens,
        int $start,
        int $anchorIdx,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): array {
        $whereIdx = null;
        for ($i = $start; $i < $anchorIdx; $i++) {
            $t = $tokens[$i];
            if ($t->isWord('WHERE') && $this->atDepth($t, $baseParen, $baseBracket, $baseBrace)) {
                $whereIdx = $i; // last WHERE before anchor at this depth
            }
        }

        if ($whereIdx === null) {
            return ['whereOffset' => -1, 'whereEndOffset' => -1, 'hasTopLevelOr' => false];
        }

        // Bound the predicate at the first top-level clause keyword after the WHERE.
        // Default to the anchor offset when nothing intervenes.
        $whereEndOffset = $anchorIdx < count($tokens) ? $tokens[$anchorIdx]->offset : $tokens[$whereIdx]->offset;
        for ($i = $whereIdx + 1; $i < $anchorIdx; $i++) {
            $t = $tokens[$i];
            if ($this->atDepth($t, $baseParen, $baseBracket, $baseBrace)
                && $this->isPredicateTerminatingKeyword($t)) {
                $whereEndOffset = $t->offset;
                break;
            }
        }

        // Top-level OR within the WHERE predicate (between WHERE and its end), at the
        // part's own depth (so OR inside (...) does not count).
        $hasOr = false;
        for ($i = $whereIdx + 1; $i < $anchorIdx; $i++) {
            $t = $tokens[$i];
            if ($t->offset >= $whereEndOffset) {
                break;
            }
            // XOR binds LOOSER than AND exactly like OR: `a XOR b AND team`
            // parses as `a XOR (b AND team)`, so the appended scope would only
            // constrain the b side (cross-tenant rows with `a` true leak).
            if (($t->isWord('OR') || $t->isWord('XOR')) && $this->atDepth($t, $baseParen, $baseBracket, $baseBrace)) {
                $hasOr = true;
                break;
            }
        }

        return [
            'whereOffset' => $tokens[$whereIdx]->offset,
            'whereEndOffset' => $whereEndOffset,
            'hasTopLevelOr' => $hasOr,
        ];
    }

    /**
     * Whether the token is a clause keyword that terminates a WHERE predicate: the
     * predicate may not extend past it. Mirrors the set the executor splices before.
     * A keyword-NAMED variable (`WHERE limit …`, `AND skip …`, an `order` with no
     * BY — flagged once by the lexer, Token::$keywordAsIdentifier) is not one.
     */
    private function isPredicateTerminatingKeyword(Token $t): bool
    {
        if ($t->type !== CypherLexer::T_WORD || $t->keywordAsIdentifier) {
            return false;
        }
        return CypherClauses::in($t->value, CypherClauses::CLAUSE_HEADS);
    }

    /** Whether a token begins a clause (so a node pattern scan should stop). */
    private function isClauseKeyword(Token $t): bool
    {
        if ($t->type !== CypherLexer::T_WORD || $t->keywordAsIdentifier) {
            return false;
        }
        $kw = ['MATCH', 'OPTIONAL', 'WHERE', 'WITH', 'RETURN', 'ORDER', 'UNION', 'CREATE', 'MERGE'];
        foreach ($kw as $k) {
            if (strcasecmp($t->value, $k) === 0) {
                return true;
            }
        }
        return false;
    }

    private function looksLikeKeyword(string $word): bool
    {
        $kw = ['MATCH', 'OPTIONAL', 'WHERE', 'WITH', 'RETURN', 'ORDER', 'BY', 'AND',
            'OR', 'NOT', 'UNION', 'ALL', 'AS', 'DISTINCT', 'CALL', 'YIELD'];
        foreach ($kw as $k) {
            if (strcasecmp($word, $k) === 0) {
                return true;
            }
        }
        return false;
    }

    private function explain(?string $reason): string
    {
        return match ($reason) {
            'multi_statement' => 'Query must be a single statement: remove the trailing ";" and the second statement.',
            'unsupported_subquery' => 'CALL {} subqueries are not allowed; express the query with plain MATCH/WHERE/RETURN.',
            'no_anchor' => 'Query must contain a WITH, RETURN or ORDER BY clause that closes each MATCH scope.',
            'label_free_sensitive' => 'Every matched node must carry an explicit :Label and a variable, e.g. MATCH (i:Invoice).',
            'sensitive_binding_not_live' => 'The returned/matched entity variable must still be in scope at the RETURN/WITH; do not project it away before scoping.',
            'unsupported_pattern_scope' => 'Do not match nodes inside pattern comprehensions, EXISTS{}, COUNT{} or COLLECT{} (only variables already matched outside may be referenced there); use a top-level MATCH.',
            'unsupported_pattern_syntax' => 'Use only plain patterns: (alias:Label {props})-[:TYPE]->(alias:Label). No IS labels, inline WHERE inside a pattern, label expressions (| & ! %), SHORTEST/shortestPath or quantified paths.',
            'unsupported_var_length_binding' => 'Do not bind variable-length relationships or paths to a variable (no p = (…)-[*]-(…), no [r*]); match the endpoints only.',
            'keyword_as_identifier' => 'Do not use Cypher keywords (match, optional, with, return, where, …) as variable or alias names.',
            'unsupported_procedure_or_function' => 'Procedure calls and namespaced functions (apoc.cypher.*, db.index.*, …) are not allowed; only date/duration/apoc.date/apoc.text value functions.',
            'unterminated_string', 'unterminated_comment', 'unbalanced_delimiters' => 'Query is malformed (unbalanced quotes, comments or delimiters).',
            default => 'Query is not in the tenant-scopeable subset.',
        };
    }
}
