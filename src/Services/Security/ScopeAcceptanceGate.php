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
            && $this->hasDirectPredicate($tokens, $start, $end, $binding->alias, (string) $rule['column']);
    }

    /**
     * Token sequence `<alias> . <column> IN $ai_team_ids` present at top level
     * inside the scope. Token-based, so a match inside a string literal (opaque
     * T_LITERAL) can never satisfy it, and `IN [` (inlined ids) never matches.
     *
     * @param list<Token> $tokens
     */
    private function hasDirectPredicate(array $tokens, int $start, int $end, string $alias, string $column): bool
    {
        for ($i = $start; $i + 4 < $end; $i++) {
            if ($this->isAliasColumnInParam($tokens, $i, $end, $alias, $column)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether tokens at $i form `<alias> . <column> IN $ai_team_ids`.
     *
     * @param list<Token> $tokens
     */
    private function isAliasColumnInParam(array $tokens, int $i, int $end, string $alias, string $column): bool
    {
        return $tokens[$i]->type === CypherLexer::T_WORD
            && strcasecmp($tokens[$i]->value, $alias) === 0
            && $tokens[$i + 1]->isPunct('.')
            && $tokens[$i + 2]->type === CypherLexer::T_WORD
            && strcasecmp($tokens[$i + 2]->value, $column) === 0
            && $this->isInTeamIdsParam($tokens, $i + 3, $end);
    }

    /**
     * Whether tokens starting at $i are `IN $ai_team_ids` (the bound parameter form,
     * NOT an inline `IN [ ... ]`).
     *
     * @param list<Token> $tokens
     */
    private function isInTeamIdsParam(array $tokens, int $i, int $end): bool
    {
        if ($i + 1 >= $end) {
            return false;
        }
        if ($tokens[$i]->type !== CypherLexer::T_WORD || strcasecmp($tokens[$i]->value, 'IN') !== 0) {
            return false;
        }
        // The lexer keeps `$ai_team_ids` as one word token (the '$' is a word char).
        $param = $tokens[$i + 1];
        return $param->type === CypherLexer::T_WORD
            && strcasecmp($param->value, '$' . TeamPathResolver::TEAM_IDS_PARAM) === 0;
    }
}
