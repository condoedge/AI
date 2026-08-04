<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * Conservative, lexer-backed STRUCTURAL validity gate for rewritten Cypher.
 *
 * This is NOT a full Cypher parser. It is a defence-in-depth runtime check that
 * catches the specific malformations a tenant-scoping splice can introduce — the
 * failures Neo4j would otherwise reject at parse time:
 *
 *   1. Unbalanced / unterminated delimiters or strings (lexer error).
 *   2. A clause keyword (MATCH, RETURN, WITH, WHERE, …) in CLAUSE position inside
 *      parentheses — i.e. swallowed into a WHERE boolean expression or a pattern
 *      group. A word in LABEL position (`(x:Union)`) or PROPERTY position
 *      (`a.Order`) is not a clause keyword and is allowed.
 *   3. A top-level boolean connective (AND / OR / XOR) appearing in CLAUSE
 *      position rather than inside a WHERE predicate — e.g. `MATCH (...) AND x`.
 *   4. Two adjacent top-level WHERE clauses (`WHERE ... WHERE ...`) with no
 *      intervening pattern clause — the multi-binding splice mangle.
 *   5. UNION branches that project a DIFFERENT set of column names — Neo4j rejects
 *      these with "All sub queries in an UNION must have the same columns". A
 *      relationship/parent scope spliced into one branch (or a generator that
 *      scopes branches inconsistently) can introduce this; catching it here turns a
 *      runtime SyntaxError into a deterministic fail-closed safe-stop.
 *
 * String literals and backtick identifiers are opaque to the lexer, so a keyword
 * written inside a quoted string is never matched (offsets / depths are the
 * lexer's, not naive substring scans).
 *
 * {@see \Condoedge\Ai\Services\SecurityFilterInjector} runs this on every rewrite
 * before the query may reach Neo4j; a structurally invalid rewrite is discarded
 * (the caller fails closed gracefully) rather than crashing on a raw SyntaxError.
 */
final class CypherStructuralValidator
{
    /** Clause keywords that open a top-level clause (must never sit in clause position inside parens). */
    private const CLAUSE_KEYWORDS = [
        'MATCH', 'OPTIONAL', 'WITH', 'RETURN', 'ORDER', 'SKIP', 'LIMIT',
        'CALL', 'UNION', 'CREATE', 'MERGE', 'DELETE', 'SET', 'REMOVE',
        'FOREACH', 'UNWIND', 'WHERE',
    ];

    /** Boolean connectives that are only legal inside a WHERE predicate (at top level). */
    private const BOOLEAN_CONNECTIVES = ['AND', 'OR', 'XOR'];

    public function __construct(
        private readonly CypherLexer $lexer = new CypherLexer()
    ) {
    }

    public function isValid(string $cypher): bool
    {
        return $this->diagnose($cypher) === null;
    }

    /**
     * @return string|null Null when structurally valid, else a human-readable reason.
     */
    public function diagnose(string $cypher): ?string
    {
        $lex = $this->lexer->tokenize($cypher);
        if ($lex['error'] !== null) {
            return 'lexer: ' . $lex['error'];
        }

        /** @var list<Token> $tokens */
        $tokens = $lex['tokens'];

        // Tracks whether the most recent top-level (paren-depth-0) clause keyword
        // was WHERE — only then may a top-level AND/OR/XOR appear.
        $inTopLevelWhere = false;
        // Index of the previous top-level WORD clause keyword, to catch `WHERE ... WHERE`.
        $prevTopLevelClause = null;

        foreach ($tokens as $idx => $t) {
            if ($t->type !== CypherLexer::T_WORD) {
                continue;
            }

            // A clause keyword inside parentheses is only a defect when it is in
            // CLAUSE position. A word that follows ':' (a node/relationship label)
            // or '.' (a property name) is an identifier, not a clause keyword.
            // Inside a BRACE block ({…}) a clause keyword is legal: an existential
            // subquery (`EXISTS { MATCH … WHERE … }`, the null-tolerant scope the
            // injector emits for OPTIONAL-bound variables) or a map key.
            if ($t->parenDepth > 0 && $t->braceDepth === 0 && $this->isClauseKeyword($t->value)
                && !$this->isIdentifierPosition($tokens, $idx)) {
                return "clause keyword '{$t->value}' trapped inside parentheses at offset {$t->offset}";
            }

            if ($t->parenDepth !== 0) {
                continue; // only reason about clause structure at top level
            }

            // Same label/property guard at top level: a property `n.Where` or a label
            // on a top-level node pattern is an identifier, not a clause boundary.
            if ($this->isIdentifierPosition($tokens, $idx)) {
                continue;
            }

            if ($this->matches($t->value, 'WHERE')) {
                // Two adjacent top-level WHEREs with no pattern clause between them
                // is the multi-binding splice mangle (`... WHERE ... WHERE ...`).
                if ($prevTopLevelClause !== null
                    && $this->matches($tokens[$prevTopLevelClause]->value, 'WHERE')) {
                    return "two adjacent WHERE clauses at offset {$t->offset}";
                }
                $inTopLevelWhere = true;
                $prevTopLevelClause = $idx;
                continue;
            }

            if ($this->isClauseKeyword($t->value)) {
                // Any other top-level clause keyword closes the WHERE predicate.
                $inTopLevelWhere = false;
                $prevTopLevelClause = $idx;
                continue;
            }

            // A bare boolean connective at clause position (not inside a WHERE)
            // means a predicate was spliced after a pattern clause — e.g.
            // `MATCH (...) AND ...`. Invalid.
            if (!$inTopLevelWhere && $this->isBooleanConnective($t->value)) {
                return "boolean connective '{$t->value}' outside a WHERE predicate at offset {$t->offset}";
            }
        }

        // UNION branches must all project the same column names (Neo4j requirement).
        $unionDefect = $this->diagnoseUnionColumns($tokens);
        if ($unionDefect !== null) {
            return $unionDefect;
        }

        return null;
    }

    /**
     * Detect UNION branches whose terminal RETURN projects a different set of
     * column NAMES (order-independent, mirroring Neo4j). Returns null when there is
     * no top-level UNION or all branches agree.
     *
     * @param list<Token> $tokens
     */
    private function diagnoseUnionColumns(array $tokens): ?string
    {
        $n = count($tokens);
        $branches = [];   // list of [start,end) token ranges
        $branchStart = 0;
        $hasUnion = false;

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->type === CypherLexer::T_WORD && $this->matches($t->value, 'UNION')
                && $t->parenDepth === 0 && $t->bracketDepth === 0 && $t->braceDepth === 0) {
                $hasUnion = true;
                $branches[] = [$branchStart, $i];
                // Skip an optional ALL after UNION.
                if ($i + 1 < $n && $this->matches($tokens[$i + 1]->value, 'ALL')) {
                    $i++;
                }
                $branchStart = $i + 1;
            }
        }

        if (!$hasUnion) {
            return null;
        }

        $branches[] = [$branchStart, $n];

        $reference = null;
        foreach ($branches as $branch) {
            $columns = $this->branchReturnColumns($tokens, $branch[0], $branch[1]);

            // A branch without a resolvable top-level RETURN is left to the other
            // structural checks; do not fabricate a column-mismatch from it.
            if ($columns === null) {
                continue;
            }

            if ($reference === null) {
                $reference = $columns;
                continue;
            }

            if ($columns !== $reference) {
                return 'UNION branches project different columns ('
                    . implode(',', $reference) . ' vs ' . implode(',', $columns) . ')';
            }
        }

        return null;
    }

    /**
     * The ordered, normalized list of output column NAMES of the LAST top-level
     * RETURN in a branch, or null when no top-level RETURN is present.
     *
     * A column's name is its explicit `AS <alias>` when present, else the verbatim
     * (whitespace-collapsed, lowercased) projection expression — exactly what Neo4j
     * compares across UNION branches.
     *
     * @param list<Token> $tokens
     * @return list<string>|null
     */
    private function branchReturnColumns(array $tokens, int $start, int $end): ?array
    {
        $returnIdx = null;
        for ($i = $start; $i < $end; $i++) {
            $t = $tokens[$i];
            if ($t->type === CypherLexer::T_WORD && $this->matches($t->value, 'RETURN')
                && $t->parenDepth === 0 && $t->bracketDepth === 0 && $t->braceDepth === 0
                && !$this->isIdentifierPosition($tokens, $i)) {
                $returnIdx = $i;
            }
        }

        if ($returnIdx === null) {
            return null;
        }

        // Projection runs from just after RETURN to the first top-level post-RETURN
        // clause keyword (ORDER/SKIP/LIMIT) or the branch end.
        $projStart = $returnIdx + 1;
        if ($projStart < $end && $this->matches($tokens[$projStart]->value, 'DISTINCT')) {
            $projStart++;
        }

        $projEnd = $end;
        for ($i = $projStart; $i < $end; $i++) {
            $t = $tokens[$i];
            if ($t->type === CypherLexer::T_WORD && $t->parenDepth === 0
                && $t->bracketDepth === 0 && $t->braceDepth === 0
                && ($this->matches($t->value, 'ORDER') || $this->matches($t->value, 'SKIP')
                    || $this->matches($t->value, 'LIMIT'))) {
                $projEnd = $i;
                break;
            }
        }

        // Split the projection on top-level commas into items, then derive each
        // item's output name.
        $columns = [];
        $itemStart = $projStart;
        for ($i = $projStart; $i <= $projEnd; $i++) {
            $atComma = $i < $projEnd && $tokens[$i]->isPunct(',')
                && $tokens[$i]->parenDepth === 0 && $tokens[$i]->bracketDepth === 0
                && $tokens[$i]->braceDepth === 0;

            if ($atComma || $i === $projEnd) {
                $columns[] = $this->columnName($tokens, $itemStart, $i);
                $itemStart = $i + 1;
            }
        }

        return $columns;
    }

    /**
     * The normalized output name of a single projection item [start,end).
     *
     * @param list<Token> $tokens
     */
    private function columnName(array $tokens, int $start, int $end): string
    {
        // An explicit `AS <alias>` at the item's top level names the column.
        for ($i = $start; $i < $end; $i++) {
            $t = $tokens[$i];
            if ($t->type === CypherLexer::T_WORD && $this->matches($t->value, 'AS')
                && $t->parenDepth === 0 && $t->bracketDepth === 0 && $t->braceDepth === 0) {
                if ($i + 1 < $end && $tokens[$i + 1]->type === CypherLexer::T_WORD) {
                    return strtolower($tokens[$i + 1]->value);
                }
            }
        }

        // Otherwise the verbatim expression text (token values joined), lowercased.
        $parts = [];
        for ($i = $start; $i < $end; $i++) {
            $t = $tokens[$i];
            $parts[] = $t->type === CypherLexer::T_PUNCT ? $t->value : strtolower($t->value);
        }

        return implode('', $parts);
    }

    /**
     * Whether the word at $idx is in identifier position: it directly follows a
     * ':' (label) or '.' (property access), so it is not a clause keyword.
     *
     * @param list<Token> $tokens
     */
    private function isIdentifierPosition(array $tokens, int $idx): bool
    {
        $prev = $tokens[$idx - 1] ?? null;
        return $prev !== null && ($prev->isPunct(':') || $prev->isPunct('.'));
    }

    private function isClauseKeyword(string $word): bool
    {
        foreach (self::CLAUSE_KEYWORDS as $kw) {
            if ($this->matches($word, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function isBooleanConnective(string $word): bool
    {
        foreach (self::BOOLEAN_CONNECTIVES as $kw) {
            if ($this->matches($word, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function matches(string $word, string $keyword): bool
    {
        return strcasecmp($word, $keyword) === 0;
    }
}
