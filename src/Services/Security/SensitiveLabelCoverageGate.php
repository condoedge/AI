<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Services\Security\Scope\ScopePlan;

/**
 * Analyzer-INDEPENDENT coverage check for tenant-sensitive node labels.
 *
 * The {@see CypherScopeAnalyzer} enumerates the scopeable subset, but by design it
 * cannot see every sensitive label — labels nested inside `CALL { … }` subqueries,
 * or carried by a label-free relationship hop, are invisible to it. Relying on the
 * analyzer alone therefore lets a query that references tenant data slip through
 * UNSCOPED whenever the analyzer under-detects (the cross-tenant leak).
 *
 * This gate closes that gap WITHOUT trusting the analyzer: it lexically scans the
 * FULL query (string literals stay opaque T_LITERAL, so a label inside a quoted
 * string is never matched; backtick identifiers are real labels and ARE matched)
 * at EVERY nesting depth, finds each node-label-position occurrence of a
 * tenant-sensitive label, and proves that the analyzer's {@see ScopePlan} actually
 * enumerated that same `(alias:Label)` binding. A lexical sensitive binding the
 * plan did NOT enumerate is a coverage gap → the query is UNPROVABLE → the caller
 * fails closed (safe-stop), never runs it unfiltered.
 *
 * "Tenant-sensitive" is derived from the SAME single source of truth the generator
 * prompt, the acceptance gate and the injector use: a label is sensitive iff
 * {@see TeamPathResolver::ruleFor()} returns a non-null filter for it. A label with
 * no team_path is not sensitive and never triggers a coverage stop.
 */
final class SensitiveLabelCoverageGate
{
    /** @var array<string, bool> Per-instance memo of "is this label tenant-scoped". */
    private array $sensitiveCache = [];

    public function __construct(
        private readonly TeamPathResolver $resolver,
        private readonly CypherLexer $lexer = new CypherLexer()
    ) {
    }

    /**
     * Whether the query references at least one tenant-sensitive label in node-label
     * position (anywhere, including inside CALL{} and any nesting).
     */
    public function referencesSensitiveLabel(string $cypher): bool
    {
        return $this->sensitiveBindings($cypher) !== [];
    }

    /**
     * Whether EVERY tenant-sensitive node binding present in the query text is also
     * enumerated by the analyzer's plan (matched by alias + label). When false, the
     * analyzer under-detected at least one sensitive binding (e.g. inside a CALL{}),
     * so the query cannot be proven scoped and the caller must fail closed.
     */
    public function planCoversSensitiveBindings(string $cypher, ScopePlan $plan): bool
    {
        $lexical = $this->sensitiveBindings($cypher);
        if ($lexical === []) {
            return true; // nothing tenant-sensitive to cover
        }

        $enumerated = $this->planBindingKeys($plan);

        foreach ($lexical as $key => $_present) {
            if (!isset($enumerated[$key])) {
                return false; // a sensitive binding the analyzer never saw
            }
        }

        return true;
    }

    /**
     * Every `(alias:Label)` node binding whose label is tenant-sensitive, keyed by
     * "alias|Label" (case-insensitive alias, label as written). Scans ALL depths so
     * a binding inside CALL{} / a comprehension is included.
     *
     * @return array<string, true>
     */
    private function sensitiveBindings(string $cypher): array
    {
        $lexed = $this->lexer->tokenize($cypher);
        if ($lexed['error'] !== null) {
            // Unlexable input: surface every sensitive label the raw text names so the
            // caller fails closed rather than running an un-analyzable sensitive query.
            return $this->rawSensitiveLabelKeys($cypher);
        }

        /** @var list<Token> $tokens */
        $tokens = $lexed['tokens'];
        $n = count($tokens);

        $found = [];
        /** @var array<int, true> $bindingLabelIdx token indexes of labels inside a plain `(alias:L1:L2…` binding */
        $bindingLabelIdx = [];
        for ($i = 0; $i < $n; $i++) {
            if (!$tokens[$i]->isPunct('(')) {
                continue;
            }

            $binding = $this->parseLabelledBinding($tokens, $i, $n);
            if ($binding === null) {
                continue;
            }

            $alias = $binding['alias'] ?? '';
            foreach ($binding['labels'] as $labelIdx => $label) {
                $bindingLabelIdx[$labelIdx] = true;
                if ($this->isSensitiveLabel($label)) {
                    // EXACT alias (variables are case-sensitive: `x` and `X` are two bindings).
                    $found[$alias . '|' . $label] = true;
                }
            }
        }

        // A sensitive label in ANY other label position — GQL `x IS Invoice`,
        // an expression label test `WHERE x:Invoice` / `(x WHERE x:Invoice)`, a
        // label-expression operand `:Country|Invoice`, `:!Invoice`, `&Invoice` —
        // is a binding the plan can never enumerate as `alias|Label` (round 2,
        // item 2: these ran UNCHANGED with filter_applied=false, all 6006
        // invoices). Surface it under a sentinel key so coverage fails closed.
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->type !== CypherLexer::T_WORD || isset($bindingLabelIdx[$i]) || !$this->isLabelPosition($tokens, $i)) {
                continue;
            }
            if ($this->isSensitiveLabel($t->value)) {
                $found['?label-position@' . $t->offset . '|' . $t->value] = true;
            }
        }

        return $found;
    }

    /**
     * Whether the word at $i sits where a LABEL name goes outside a plain node
     * binding: after `:` (expression label test, map value — over-approximation
     * is the fail-closed direction), after a label-expression operator
     * `| & !`, or after `IS` / `IS NOT`. Relationship TYPES (`-[:T|U]->`) are
     * not labels.
     *
     * @param list<Token> $tokens
     */
    private function isLabelPosition(array $tokens, int $i): bool
    {
        $prev = $tokens[$i - 1] ?? null;
        if ($prev === null) {
            return false;
        }
        if ($prev->isPunct(':') && $this->isMapKeySeparator($tokens, $i - 1)) {
            return false; // `{supplier: supplier}` — a map VALUE, never a label
        }
        if ($prev->type === CypherLexer::T_PUNCT && in_array($prev->value, [':', '|', '&', '!'], true)) {
            return !$this->insideRelationshipBracket($tokens, $i);
        }
        if ($prev->isWord('IS')) {
            return true;
        }
        if ($prev->isWord('NOT') && isset($tokens[$i - 2]) && $tokens[$i - 2]->isWord('IS')) {
            return true;
        }
        return false;
    }

    /**
     * Whether the ':' at $colon separates a KEY from its value in a map literal
     * / map projection: `{ key: … , key: … }` directly inside a `{` that is not
     * a subquery body (EXISTS / COUNT / COLLECT / CALL … {), with the key word
     * right after that `{` or after a `,` at the same depth.
     *
     * @param list<Token> $tokens
     */
    private function isMapKeySeparator(array $tokens, int $colon): bool
    {
        $key = $tokens[$colon - 1] ?? null;
        $beforeKey = $tokens[$colon - 2] ?? null;
        $c = $tokens[$colon];
        if ($key === null || $beforeKey === null || $key->type !== CypherLexer::T_WORD || $c->braceDepth === 0) {
            return false;
        }
        if (!$beforeKey->isPunct('{') && !$beforeKey->isPunct(',')) {
            return false;
        }

        // Innermost enclosing opener of the ':' must be a '{' of a map.
        for ($j = $colon - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if ($t->isPunct('{') && $t->braceDepth === $c->braceDepth - 1
                && $t->parenDepth === $c->parenDepth && $t->bracketDepth === $c->bracketDepth) {
                $before = $tokens[$j - 1] ?? null;
                $subquery = $before !== null && ($before->isPunct(')')
                    || $before->isWord('EXISTS') || $before->isWord('COUNT')
                    || $before->isWord('COLLECT') || $before->isWord('CALL'));
                return !$subquery;
            }
            if (($t->isPunct('(') && $t->parenDepth === $c->parenDepth - 1)
                || ($t->isPunct('[') && $t->bracketDepth === $c->bracketDepth - 1)) {
                return false; // innermost opener is a paren / bracket
            }
        }
        return false;
    }

    /**
     * Whether token $i is inside a relationship pattern bracket `-[ … ]`.
     *
     * @param list<Token> $tokens
     */
    private function insideRelationshipBracket(array $tokens, int $i): bool
    {
        $depth = $tokens[$i]->bracketDepth;
        if ($depth === 0) {
            return false;
        }
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if ($t->isPunct('[') && $t->bracketDepth === $depth - 1) {
                $before = $tokens[$j - 1] ?? null;
                return $before !== null && $before->isPunct('-');
            }
        }
        return false;
    }

    /**
     * Parse a `( [alias] : Label … )` node pattern at the '(' index. Returns the
     * alias (may be null) and the FIRST label word, or null when this paren does not
     * open a labelled node pattern.
     *
     * Also returns every label of a plain conjunction `(a:L1:L2)` keyed by its
     * token index (labels[idx] = name). Anything after them (`|`, `&`, `IS`, …)
     * is left to the label-position scan.
     *
     * @param list<Token> $tokens
     * @return array{alias: ?string, label: string, labels: array<int, string>}|null
     */
    private function parseLabelledBinding(array $tokens, int $open, int $end): ?array
    {
        $idx = $open + 1;
        if ($idx >= $end) {
            return null;
        }

        $alias = null;
        $t = $tokens[$idx];

        if ($t->type === CypherLexer::T_WORD) {
            // `(alias : Label …)` — the word is the alias only when a ':' follows.
            $next = $tokens[$idx + 1] ?? null;
            if ($next === null || !$next->isPunct(':')) {
                return null; // (a), (a {..}), grouping/expression paren — no label here
            }
            $alias = $t->value;
            $idx += 2; // consume alias + ':'
        } elseif ($t->isPunct(':')) {
            // `(:Label)` — labelled, alias-free.
            $idx += 1;
        } else {
            return null;
        }

        if ($idx >= $end || $tokens[$idx]->type !== CypherLexer::T_WORD) {
            return null;
        }

        $labels = [$idx => $tokens[$idx]->value];
        $k = $idx + 1;
        while ($k + 1 < $end && $tokens[$k]->isPunct(':') && $tokens[$k + 1]->type === CypherLexer::T_WORD) {
            $labels[$k + 1] = $tokens[$k + 1]->value;
            $k += 2;
        }

        return ['alias' => $alias, 'label' => $tokens[$idx]->value, 'labels' => $labels];
    }

    /**
     * Bindings derived without the lexer (unlexable input fallback): conservatively
     * surface every sensitive label named anywhere in the raw text so the coverage
     * check cannot be satisfied. Keyed with a sentinel alias so the plan (built from
     * the same unlexable input, hence empty) never "covers" them.
     *
     * @return array<string, true>
     */
    private function rawSensitiveLabelKeys(string $cypher): array
    {
        $found = [];
        foreach ($this->sensitiveLabels() as $label) {
            if (preg_match('/:\s*`?' . preg_quote($label, '/') . '`?\b/i', $cypher)) {
                $found['|' . $label] = true;
            }
        }
        return $found;
    }

    /**
     * The analyzer-enumerated sensitive bindings, keyed identically to
     * {@see sensitiveBindings()} ("alias|Label").
     *
     * @return array<string, true>
     */
    private function planBindingKeys(ScopePlan $plan): array
    {
        $keys = [];
        foreach ($plan->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $binding) {
                $keys[$binding->alias . '|' . $binding->label] = true; // exact, see sensitiveBindings()
            }
        }
        return $keys;
    }

    /**
     * Whether $label is tenant-scoped per the resolver (the single source of truth).
     * Memoized per request; the alias does not affect whether a rule exists.
     */
    private function isSensitiveLabel(string $label): bool
    {
        // EXACT label key: labels are case-sensitive, so a lookup of an
        // unconfigured `invoice` must never poison the memo for `Invoice`.
        $key = $label;
        if (!array_key_exists($key, $this->sensitiveCache)) {
            $this->sensitiveCache[$key] = $this->resolver->ruleFor($label, strtolower(substr($label, 0, 1))) !== null;
        }
        return $this->sensitiveCache[$key];
    }

    /**
     * Every tenant-sensitive label declared in the entity config (graph.label, or the
     * class basename of the config key when no explicit label is set).
     *
     * @return list<string>
     */
    private function sensitiveLabels(): array
    {
        $labels = [];
        foreach (config('entities', []) as $fqcn => $config) {
            $label = $config['graph']['label'] ?? class_basename($fqcn);
            if (is_string($label) && $label !== '' && $this->isSensitiveLabel($label)) {
                $labels[$label] = true;
            }
        }
        return array_keys($labels);
    }
}
