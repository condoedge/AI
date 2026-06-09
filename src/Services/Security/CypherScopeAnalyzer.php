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

            $scopes[] = $scope;
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
     * Analyze ONE UNION-free part. Returns a Scope, or a (rejecting) ScopePlan.
     *
     * @param list<Token> $tokens
     * @return Scope|ScopePlan
     */
    private function analyzePartScope(
        array $tokens,
        int $start,
        int $end,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ) {
        // 1) Enumerate node bindings across the WHOLE part + detect un-anchorable
        // sensitive patterns (comprehensions, label-free primaries).
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

        /** @var list<NodeBinding> $bindings */
        $bindings = $extract['bindings'];
        $lastBindingTokenIdx = $extract['lastBindingTokenIdx'];

        // 2) The anchor closes the LAST node-binding MATCH scope: the first
        // WITH|RETURN|ORDER at this part's depth AFTER the last binding. A leading
        // `WITH const` before any MATCH is therefore NOT mistaken for the anchor
        // (closes G1).
        $anchorSearchFrom = $lastBindingTokenIdx >= 0 ? $lastBindingTokenIdx : $start;
        $anchorIdx = $this->findAnchorIndex($tokens, $anchorSearchFrom, $end, $baseParen, $baseBracket, $baseBrace);

        if ($anchorIdx === null) {
            // No anchor after the bindings.
            if (empty($bindings)) {
                // No sensitive bindings AND no anchor → a pure scalar / introspection
                // part (e.g. `CALL db.labels()`); nothing to scope.
                return new Scope([], $start >= 0 && $start < count($tokens) ? $tokens[$start]->offset : 0);
            }
            // A sensitive binding with nowhere to anchor the predicate → fail closed
            // (preserves inject_returns_null_when_branch_has_no_safe_anchor).
            return new ScopePlan(false, [], [], true, 'no_anchor');
        }

        $anchorOffset = $tokens[$anchorIdx]->offset;

        // 3) Liveness: every sensitive binding's alias must still be bound at the
        // anchor. A WITH between the BINDING and the anchor that projects the alias
        // away makes it un-scopeable there → fail closed. (A leading WITH BEFORE a
        // binding does not constrain that binding.)
        foreach ($bindings as $binding) {
            $bindingIdx = $extract['bindingTokenIdx'][$binding->alias] ?? $start;
            if (!$this->aliasLiveBetween(
                $tokens,
                $bindingIdx,
                $anchorIdx,
                $binding->alias,
                $baseParen,
                $baseBracket,
                $baseBrace
            )) {
                return new ScopePlan(false, [], [], true, 'sensitive_binding_not_live');
            }
        }

        // 4) Locate this part's own WHERE (between bindings and the anchor) and
        // its top-level OR, for precedence-correct AND-merge.
        $whereInfo = $this->findWhere($tokens, $start, $anchorIdx, $baseParen, $baseBracket, $baseBrace);

        return new Scope(
            sensitiveBindings: $bindings,
            anchorOffset: $anchorOffset,
            whereOffset: $whereInfo['whereOffset'],
            // Predicate end is bounded at the first clause keyword after the WHERE
            // (an intervening MATCH/WITH/… before the anchor), so the injector wraps
            // ONLY the predicate when parenthesizing a top-level OR (c12).
            whereEndOffset: $whereInfo['whereOffset'] >= 0 ? $whereInfo['whereEndOffset'] : -1,
            whereHasTopLevelOr: $whereInfo['hasTopLevelOr']
        );
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
            if ($tokens[$i]->isWord('CALL')) {
                // CALL followed by '{' (ignoring nothing — lexer drops whitespace).
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
        for ($i = $start; $i < $end - 1; $i++) {
            if ($tokens[$i]->isWord('MATCH') && $tokens[$i + 1]->isPunct('(')) {
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
            foreach (self::ANCHOR_KEYWORDS as $kw) {
                if ($t->isWord($kw)) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Extract every sensitive (labelled) node binding in the part, and detect
     * sensitive bindings hidden in comprehensions / subqueries or carried by a
     * label-free node access (which we cannot anchor → reject).
     *
     * Scans the WHOLE part (not just up to a pre-chosen anchor): the anchor is
     * derived from the LAST binding afterwards, so a leading `WITH const` cannot
     * pre-empt the real primary-MATCH boundary.
     *
     * @param list<Token> $tokens
     * @return array{bindings: list<NodeBinding>, bindingTokenIdx: array<string,int>, lastBindingTokenIdx: int, reason: ?string}
     */
    private function extractBindings(
        array $tokens,
        int $start,
        int $end,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): array {
        // Pre-pass: every alias that receives a :Label ANYWHERE in the part (at the
        // part's own depth). A later label-free `(i)` re-reference of a labelled
        // alias is a back-reference, not a new untyped binding.
        $labelledAliases = $this->collectLabelledAliases($tokens, $start, $end, $baseParen, $baseBracket, $baseBrace);

        $bindings = [];
        $bindingTokenIdx = [];
        $seenAliases = [];
        $lastBindingTokenIdx = -1;

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
                return ['bindings' => [], 'bindingTokenIdx' => [], 'lastBindingTokenIdx' => -1, 'reason' => 'unsupported_pattern_scope'];
            }

            // Patterns inside comprehensions/subqueries that are NOT sensitive are
            // allowed and simply ignored (non-sensitive comprehensions are fine).
            if ($insideComprehension || $insideSubquery) {
                continue;
            }

            // Only top-level labelled patterns are tenant-sensitive bindings.
            if ($parsed['label'] === null) {
                // A label-free node bound by a MATCH whose alias is NEVER labelled
                // anywhere in the part is a genuinely untyped node access; tenancy
                // is unprovable → reject. A label-free `(i)` whose alias IS labelled
                // elsewhere is a back-reference and is fine.
                if (!$this->isMatchedNodePattern($tokens, $i, $start, $baseParen, $baseBracket, $baseBrace)) {
                    continue;
                }
                $alias = $parsed['alias'];
                if ($alias !== null && isset($labelledAliases[$alias])) {
                    continue; // back-reference to a labelled binding
                }
                return ['bindings' => [], 'bindingTokenIdx' => [], 'lastBindingTokenIdx' => -1, 'reason' => 'label_free_sensitive'];
            }

            // Labelled but alias-free (`(:Label)`) → no variable to scope on.
            if ($parsed['alias'] === null) {
                return ['bindings' => [], 'bindingTokenIdx' => [], 'lastBindingTokenIdx' => -1, 'reason' => 'label_free_sensitive'];
            }

            $lastBindingTokenIdx = $i;
            if (!isset($seenAliases[$parsed['alias']])) {
                $bindings[] = new NodeBinding($parsed['alias'], $parsed['label']);
                $bindingTokenIdx[$parsed['alias']] = $i;
                $seenAliases[$parsed['alias']] = true;
            } else {
                // Re-binding the same alias later (e.g. a second labelled MATCH on
                // the same variable): track the latest position for liveness.
                $bindingTokenIdx[$parsed['alias']] = $i;
            }
        }

        return [
            'bindings' => $bindings,
            'bindingTokenIdx' => $bindingTokenIdx ?? [],
            'lastBindingTokenIdx' => $lastBindingTokenIdx,
            'reason' => null,
        ];
    }

    /**
     * Aliases that receive an explicit :Label at the part's own graph-pattern depth.
     *
     * @param list<Token> $tokens
     * @return array<string, true>
     */
    private function collectLabelledAliases(
        array $tokens,
        int $start,
        int $end,
        int $baseParen,
        int $baseBracket,
        int $baseBrace
    ): array {
        $labelled = [];
        for ($i = $start; $i < $end; $i++) {
            if (!$tokens[$i]->isPunct('(')) {
                continue;
            }
            // Only consider top-level graph patterns (not comprehension internals).
            if ($tokens[$i]->bracketDepth > $baseBracket || $tokens[$i]->braceDepth > $baseBrace) {
                continue;
            }
            $parsed = $this->parseNodePattern($tokens, $i, $end);
            if ($parsed !== null && $parsed['label'] !== null && $parsed['alias'] !== null) {
                $labelled[$parsed['alias']] = true;
            }
        }
        return $labelled;
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
            if ($t->type !== CypherLexer::T_WORD) {
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

        // Now expect a label word (possibly the first of a label disjunction).
        if ($idx < $end && $tokens[$idx]->type === CypherLexer::T_WORD) {
            $label = $tokens[$idx]->value;
            return ['alias' => $alias, 'label' => $label];
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

        // The WITH projection list runs until the next WHERE/ORDER at this depth,
        // else up to the anchor.
        $projEnd = $anchorIdx;
        for ($i = $withIdx + 1; $i < $anchorIdx; $i++) {
            $t = $tokens[$i];
            if ($this->atDepth($t, $baseParen, $baseBracket, $baseBrace)
                && ($t->isWord('WHERE') || $t->isWord('ORDER'))) {
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
            if ($t->isWord('OR') && $this->atDepth($t, $baseParen, $baseBracket, $baseBrace)) {
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
     */
    private function isPredicateTerminatingKeyword(Token $t): bool
    {
        if ($t->type !== CypherLexer::T_WORD) {
            return false;
        }
        $kw = [
            'MATCH', 'OPTIONAL', 'WITH', 'RETURN', 'ORDER', 'SKIP', 'LIMIT',
            'CALL', 'UNION', 'CREATE', 'MERGE', 'DELETE', 'SET', 'REMOVE',
            'FOREACH', 'UNWIND',
        ];
        foreach ($kw as $k) {
            if (strcasecmp($t->value, $k) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Whether a token begins a clause (so a node pattern scan should stop). */
    private function isClauseKeyword(Token $t): bool
    {
        if ($t->type !== CypherLexer::T_WORD) {
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
            'unsupported_pattern_scope' => 'Do not match sensitive labelled nodes inside pattern comprehensions or EXISTS{}; use a top-level MATCH.',
            'unterminated_string', 'unterminated_comment', 'unbalanced_delimiters' => 'Query is malformed (unbalanced quotes, comments or delimiters).',
            default => 'Query is not in the tenant-scopeable subset.',
        };
    }
}
