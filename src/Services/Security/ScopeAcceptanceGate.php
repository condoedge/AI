<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\Scope\Scope;
use Condoedge\Ai\Services\Security\Scope\ScopePlan;

/**
 * Acceptance gate for the generator-emits-scope path (PRIMARY safety mechanism).
 *
 * Pure / deterministic / no I/O — same posture as {@see CypherScopeAnalyzer} and
 * {@see CypherStructuralValidator}. Given a {@see ScopePlan} (already enumerating
 * every tenant-sensitive node binding) and a per-binding REQUIRED rule (the
 * {@see TeamPathResolver::ruleFor()} template, which is the single source of truth
 * shared with the generator prompt section), it PROVES that the generator's own
 * query already constrains every sensitive binding by `$ai_team_ids` on its own
 * live alias / required path.
 *
 * It is the positive precondition counterpart of the invariant test's per-binding
 * re-check: a query is accepted AS-IS only when every required binding is proven
 * self-scoped, confined to the binding's OWN scope (so a sibling UNION branch's
 * predicate can never satisfy a different branch), and not hidden inside a string
 * literal. Any failure → returns false → the caller falls back to the injector
 * (and, if that cannot produce valid scoped Cypher, fails closed). The generator is
 * NEVER trusted for safety: a missed/wrong/inlined/wrong-shape predicate yields
 * false, never true.
 *
 * ACCEPTANCE IS DELIBERATELY DIRECT-COLUMN-ONLY. The gate proves only the shape it
 * can verify with ZERO false-positive risk: a `<alias>.<column> IN $ai_team_ids`
 * predicate on the binding's OWN alias. Relationship / parent traversals (whose
 * intermediate aliases are the generator's free choice) are NOT accepted here — the
 * gate returns false and the proven {@see SecurityFilterInjector} scopes them. This
 * keeps the leak-impossibility invariant absolute: a binding the gate cannot prove
 * is self-scoped is always re-scoped by the injector, never executed unscoped.
 */
final class ScopeAcceptanceGate
{
    public function __construct(
        private readonly CypherLexer $lexer = new CypherLexer()
    ) {
    }

    /**
     * Whether EVERY sensitive binding in the plan is already self-scoped by the
     * query text.
     *
     * @param callable(NodeBinding): ?array{type:string,kind:string,alias:string,column:?string,filter:string} $ruleFor
     *        The resolver template for the binding's label, or null when the label
     *        is not tenant-scoped (no predicate required).
     */
    public function isFullySelfScoped(string $cypher, ScopePlan $plan, callable $ruleFor): bool
    {
        if (!$plan->isScopeable) {
            return false;
        }

        $lexed = $this->lexer->tokenize($cypher);
        if ($lexed['error'] !== null) {
            return false;
        }

        /** @var list<Token> $tokens */
        $tokens = $lexed['tokens'];

        foreach ($plan->scopes as $scope) {
            if (empty($scope->sensitiveBindings)) {
                continue;
            }

            $range = $this->scopeTokenRange($tokens, $scope);

            foreach ($scope->sensitiveBindings as $binding) {
                $rule = $ruleFor($binding);

                // Not tenant-scoped → no predicate required for this binding.
                if ($rule === null) {
                    continue;
                }

                if (!$this->bindingIsScoped($tokens, $range[0], $range[1], $binding, $rule)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The [startTokenIdx, endTokenIdx) range of the part that owns $scope, found by
     * locating the scope's anchor offset and bounding the search at the surrounding
     * top-level UNION keywords. Confining each binding's predicate search to its own
     * part is what stops a sibling UNION branch's predicate from satisfying another
     * branch.
     *
     * @param list<Token> $tokens
     * @return array{0:int,1:int}
     */
    private function scopeTokenRange(array $tokens, Scope $scope): array
    {
        $n = count($tokens);

        // Locate the anchor token by its byte offset.
        $anchorIdx = $n;
        for ($i = 0; $i < $n; $i++) {
            if ($tokens[$i]->offset === $scope->anchorOffset) {
                $anchorIdx = $i;
                break;
            }
        }

        // Lower bound: the token just after the previous top-level UNION before the
        // anchor (or 0). Upper bound is the anchor itself — every WHERE/MATCH filter
        // for the part lives before its WITH/RETURN/ORDER anchor.
        $start = 0;
        for ($i = 0; $i < $anchorIdx; $i++) {
            $t = $tokens[$i];
            if ($t->isWord('UNION')
                && $t->parenDepth === 0 && $t->bracketDepth === 0 && $t->braceDepth === 0) {
                $start = $i + 1;
                if ($start < $n && $tokens[$start]->isWord('ALL')) {
                    $start++;
                }
            }
        }

        // WITH-SEGMENT lower bound (item E): a part split by top-level WITHs
        // yields one scope per segment; each binding's predicate must be found in
        // its OWN segment. Without this bound, a predicate emitted BEFORE the
        // WITH would vouch for the SAME alias re-bound AFTER it — accepting the
        // later, unscoped match as self-scoped (a leak).
        if ($scope->segmentStartOffset > 0) {
            for ($i = $start; $i < $anchorIdx; $i++) {
                if ($tokens[$i]->offset >= $scope->segmentStartOffset) {
                    $start = max($start, $i);
                    break;
                }
            }
        }

        return [$start, $anchorIdx];
    }

    /**
     * Whether $binding carries its required $ai_team_ids predicate within
     * [$start,$end) (its own scope), on its own live alias / required path.
     *
     * @param list<Token> $tokens
     * @param array{type:string,kind:string,alias:string,column:?string,filter:string} $rule
     */
    private function bindingIsScoped(array $tokens, int $start, int $end, NodeBinding $binding, array $rule): bool
    {
        // SAFETY POSTURE: the gate only ACCEPTS a shape it can verify with ZERO
        // false-positive risk — a direct-column predicate on the binding's OWN
        // alias. Relationship / parent traversals (whose intermediate aliases are
        // the generator's free choice) cannot be proven leak-free by structural
        // matching alone, so the gate deliberately does NOT accept them: it returns
        // false and the proven fallback injector scopes them instead. A
        // false-negative here is harmless (the injector re-applies the resolver
        // filter); a false-positive would be a leak, which this avoids by
        // construction.
        return $rule['type'] === 'direct'
            && $this->hasDirectPredicate($tokens, $start, $end, $binding->alias, (string) $rule['column'], $rule['param'] ?? '$' . TeamPathResolver::TEAM_IDS_PARAM);
    }

    /**
     * Whether `<alias> . <column> IN $ai_team_ids` is a TOP-LEVEL AND-CONJUNCT of
     * the WHERE of a MANDATORY (non-OPTIONAL) MATCH inside the scope — the only
     * position where it provably constrains EVERY row (Task 1.26b).
     *
     * Previously the token sequence was accepted ANYWHERE in the scope, so
     * `… IN $ai_team_ids OR true`, `… OR x.status = 'open'` (an LLM precedence
     * slip, no attacker needed), `NOT x.team_id IN …`, `IN $ai_team_ids + [5]`,
     * `= false`, a CASE / comprehension, or the WHERE of an OPTIONAL MATCH (which
     * filters only the optional part) all passed as self-scoped and the executor
     * ran them AS-IS. Now:
     *   - the WHERE must sit at the part's own depth and belong to a plain MATCH;
     *   - its predicate (up to the next clause head) may contain NO OR / XOR / NOT
     *     at that depth — a pure conjunction;
     *   - the 5-token predicate must be a WHOLE conjunct: preceded by WHERE/AND
     *     and followed by AND or the predicate end.
     * Anything else returns false → the injector AND-merges the resolver filter
     * (parenthesizing an existing OR). A false negative is harmless.
     *
     * @param list<Token> $tokens
     */
    private function hasDirectPredicate(array $tokens, int $start, int $end, string $alias, string $column, string $scopeParam): bool
    {
        if (!isset($tokens[$end])) {
            return false; // no anchor token → cannot establish the part's depth
        }
        $base = $tokens[$end];

        for ($w = $start; $w < $end; $w++) {
            $t = $tokens[$w];
            if (!$t->isWord('WHERE') || $t->keywordAsIdentifier || !$this->sameDepth($t, $base)) {
                continue;
            }
            if (!$this->whereBelongsToMandatoryMatch($tokens, $start, $w, $base)) {
                continue;
            }

            // Predicate end: next clause head (or WHERE) at this depth, else the anchor.
            $predEnd = $end;
            for ($j = $w + 1; $j < $end; $j++) {
                $tj = $tokens[$j];
                if ($this->sameDepth($tj, $base) && $tj->type === CypherLexer::T_WORD && !$tj->keywordAsIdentifier
                    && ($tj->isWord('WHERE') || CypherClauses::in($tj->value, CypherClauses::CLAUSE_HEADS))) {
                    $predEnd = $j;
                    break;
                }
            }

            // A pure top-level conjunction only.
            $conjunctStarts = [$w + 1];
            $pure = true;
            for ($j = $w + 1; $j < $predEnd; $j++) {
                $tj = $tokens[$j];
                if (!$this->sameDepth($tj, $base) || $tj->type !== CypherLexer::T_WORD || $tj->keywordAsIdentifier) {
                    continue;
                }
                if ($tj->isWord('OR') || $tj->isWord('XOR') || $tj->isWord('NOT')) {
                    $pure = false;
                    break;
                }
                if ($tj->isWord('AND')) {
                    $conjunctStarts[] = $j + 1;
                }
            }
            if (!$pure) {
                continue;
            }

            foreach ($conjunctStarts as $k => $cs) {
                $ce = $conjunctStarts[$k + 1] ?? null;
                $conjunctEnd = $ce !== null ? $ce - 1 : $predEnd; // the AND / predicate end
                if ($conjunctEnd - $cs === 5 && $this->isAliasColumnInParam($tokens, $cs, $conjunctEnd, $alias, $column, $scopeParam)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the WHERE at $whereIdx attaches to a plain (non-OPTIONAL) MATCH: the
     * nearest clause head before it at the same depth is MATCH, not preceded by
     * OPTIONAL. A WHERE on an OPTIONAL MATCH filters only the optional part.
     *
     * @param list<Token> $tokens
     */
    private function whereBelongsToMandatoryMatch(array $tokens, int $start, int $whereIdx, Token $base): bool
    {
        for ($j = $whereIdx - 1; $j >= $start; $j--) {
            $tj = $tokens[$j];
            if (!$this->sameDepth($tj, $base) || $tj->type !== CypherLexer::T_WORD || $tj->keywordAsIdentifier) {
                continue;
            }
            if ($tj->isWord('MATCH')) {
                $prev = $tokens[$j - 1] ?? null;
                return !($prev !== null && $prev->isWord('OPTIONAL'));
            }
            if ($tj->isWord('WHERE') || CypherClauses::in($tj->value, CypherClauses::CLAUSE_HEADS)) {
                return false;
            }
        }
        return false;
    }

    private function sameDepth(Token $a, Token $b): bool
    {
        return $a->parenDepth === $b->parenDepth
            && $a->bracketDepth === $b->bracketDepth
            && $a->braceDepth === $b->braceDepth;
    }

    /**
     * Whether tokens at $i form `<alias> . <column> IN <$scopeParam>` (the rule's parameter).
     *
     * @param list<Token> $tokens
     */
    private function isAliasColumnInParam(array $tokens, int $i, int $end, string $alias, string $column, string $scopeParam): bool
    {
        // EXACT (case-sensitive) identifier compares: Cypher variables, property
        // keys and parameters are case-sensitive — a strcasecmp let
        // `X.team_id IN $ai_team_ids` vouch for a different variable `x`, and the
        // query ran AS-IS returning every tenant's rows (Task 1.26c). Only the
        // KEYWORD `IN` is compared case-insensitively.
        return $tokens[$i]->type === CypherLexer::T_WORD
            && $tokens[$i]->value === $alias
            && $tokens[$i + 1]->isPunct('.')
            && $tokens[$i + 2]->type === CypherLexer::T_WORD
            && $tokens[$i + 2]->value === $column
            && $this->isInScopeParam($tokens, $i + 3, $end, $scopeParam);
    }

    /**
     * Whether tokens starting at $i are `IN <$expected>` (the bound parameter form,
     * NOT an inline `IN [ ... ]`).
     *
     * @param list<Token> $tokens
     */
    private function isInScopeParam(array $tokens, int $i, int $end, string $expected): bool
    {
        if ($i + 1 >= $end) {
            return false;
        }
        if ($tokens[$i]->type !== CypherLexer::T_WORD || strcasecmp($tokens[$i]->value, 'IN') !== 0) {
            return false;
        }
        // The lexer keeps `$ai_team_ids` as one word token (the '$' is a word char).
        $param = $tokens[$i + 1];
        // Parameters are case-sensitive: `$AI_TEAM_IDS` is a DIFFERENT parameter.
        return $param->type === CypherLexer::T_WORD
            && $param->value === $expected;
    }
}
