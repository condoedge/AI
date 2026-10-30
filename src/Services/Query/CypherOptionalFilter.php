<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

use Condoedge\Ai\Services\Security\CypherClauses;
use Condoedge\Ai\Services\Security\CypherLexer;
use Condoedge\Ai\Services\Security\Token;

/**
 * A TEXT CONDITION THAT FILTERS NOTHING in a generated query. The generator wrote
 *
 *   MATCH (b:Bill) … OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE toLower(s.name) CONTAINS 'x'
 *   WITH un, s, b WITH un, sum(b.total) AS billed RETURN …
 *
 * An OPTIONAL MATCH keeps every row when it finds nothing, so the condition drops no row
 * and the total covers them all. Nothing rejects such a query — it is valid Cypher.
 *
 * OPTIONAL MATCH … WHERE is common and legitimate, so only this shape is reported, per
 * UNION branch, on the top-level clauses (a subquery is left alone):
 *
 *  - the WHERE of an OPTIONAL MATCH compares a variable that clause binds first (never
 *    mentioned before it) with a text literal: `=`, CONTAINS, STARTS WITH or ENDS WITH,
 *    possibly through functions on either side;
 *  - a later WITH / RETURN computes sum / count / avg / min / max;
 *  - and NO variable that clause binds is required afterwards. Carrying one through a
 *    WITH (`WITH un, s, b`), a group key that a later WITH rolls up (`s.name AS supplier`)
 *    and a collect() beside the total only show it: the total is not computed from it. Any
 *    other use is a requirement: inside another aggregate or a CASE, in a later WHERE /
 *    MATCH / ORDER BY, or as a column of the RETURN, whose rows it then keeps apart.
 *
 * A draft-quality check of the generator only: it runs on the model's draft, before the
 * security layer writes its own `(s IS NULL OR …)` conditions, and is never part of that
 * layer. Cypher grammar on the {@see CypherLexer} tokens, no vocabulary. Input the lexer
 * rejects yields no violation (the validator and the security layer deal with it).
 */
final class CypherOptionalFilter
{
    /** Aggregates that compute a total. collect() lists values, it totals nothing. */
    private const TOTALS = ['sum', 'count', 'avg', 'min', 'max'];

    /** A tainted name holding the optional's value, which keeps rows apart when returned. */
    private const VALUE = 'value';

    /** A tainted name holding a collect() of it, shown beside the total. */
    private const LIST = 'list';

    public function __construct(
        private readonly CypherLexer $lexer = new CypherLexer()
    ) {
    }

    /**
     * One teachable violation per OPTIONAL MATCH that filters nothing (empty = none).
     *
     * @return list<string>
     */
    public function violations(string $cypher): array
    {
        $violations = [];
        foreach ($this->scan($cypher) as $found) {
            $conditions = '`' . implode('`, `', $found['conditions']) . '`';
            $bound = '`' . implode('`, `', $found['bound']) . '`';

            $violations[] = "{$conditions} on `{$found['pattern']}` filters nothing: an OPTIONAL MATCH keeps every row when it finds no match, "
                . "and the total here is not computed from {$bound}, so it covers all the rows. "
                . 'To narrow the result, use MATCH instead of OPTIONAL MATCH. '
                . "Keep OPTIONAL MATCH only when every row must stay, and then compute the total from {$bound}.";
        }

        return $violations;
    }

    /**
     * The text of every condition that filters nothing, in query order (whitespace collapsed).
     *
     * @return list<string>
     */
    public function ineffectiveConditions(string $cypher): array
    {
        return array_values(array_unique(array_merge(...array_column($this->scan($cypher), 'conditions') ?: [[]])));
    }

    /**
     * @return list<array{pattern: string, conditions: list<string>, bound: list<string>}>
     */
    private function scan(string $cypher): array
    {
        if (trim($cypher) === '') {
            return [];
        }

        $lexed = $this->lexer->tokenize($cypher);
        if ($lexed['error'] !== null) {
            return [];
        }

        /** @var list<Token> $tokens */
        $tokens = $lexed['tokens'];
        $found = [];

        foreach ($this->branches($tokens) as [$from, $to]) {
            $clauses = $this->clauses($tokens, $from, $to);

            foreach ($clauses as $c => $clause) {
                $where = $clauses[$c + 1] ?? null;
                if ($clause['head'] !== 'OPTIONAL' || $where === null || $where['head'] !== 'WHERE') {
                    continue;
                }

                $bound = array_filter(
                    $this->patternVariables($tokens, $clause),
                    fn (string $name): bool => !$this->mentions($tokens, $from, $clause['start'], [$name => self::VALUE])
                );
                if ($bound === []) {
                    continue;
                }

                $names = array_fill_keys($bound, self::VALUE);
                $conditions = $this->textConditions($tokens, $where, $names, $cypher);

                if ($conditions !== [] && $this->totalIgnores($tokens, array_slice($clauses, $c + 2), $names)) {
                    $found[] = [
                        'pattern' => $this->text($cypher, $tokens[$clause['start']]->offset, $tokens[$where['start']]->offset),
                        'conditions' => $conditions,
                        'bound' => array_values($bound),
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * Token ranges of the UNION branches.
     *
     * @param list<Token> $tokens
     * @return list<array{0: int, 1: int}>
     */
    private function branches(array $tokens): array
    {
        $branches = [];
        $from = 0;
        foreach ($tokens as $i => $t) {
            if ($t->isTopLevel() && $t->isWord('UNION') && !$this->isIdentifier($tokens, $i)) {
                $branches[] = [$from, $i];
                $from = $i + 1;
            }
        }
        $branches[] = [$from, count($tokens)];

        return $branches;
    }

    /**
     * The top-level clauses of a branch. `from` is the first token of a clause's body and
     * `start` its head; OPTIONAL MATCH is one clause, its WHERE the next.
     *
     * @param list<Token> $tokens
     * @return list<array{head: string, start: int, from: int, to: int}>
     */
    private function clauses(array $tokens, int $from, int $to): array
    {
        $clauses = [];
        for ($i = $from; $i < $to; $i++) {
            $t = $tokens[$i];
            if (!$t->isTopLevel() || $t->type !== CypherLexer::T_WORD || $this->isIdentifier($tokens, $i)
                || !(CypherClauses::in($t->value, CypherClauses::CLAUSE_HEADS) || $t->isWord('WHERE'))) {
                continue;
            }
            if ($t->isWord('MATCH') && ($tokens[$i - 1] ?? null)?->isWord('OPTIONAL') === true) {
                $clauses[array_key_last($clauses)]['from'] = $i + 1;
                continue;
            }
            if ($clauses !== []) {
                $clauses[array_key_last($clauses)]['to'] = $i;
            }
            $clauses[] = ['head' => strtoupper($t->value), 'start' => $i, 'from' => $i + 1, 'to' => $to];
        }

        return $clauses;
    }

    /**
     * The node, relationship and path variables a pattern names.
     *
     * @param list<Token> $tokens
     * @param array{head: string, start: int, from: int, to: int} $clause
     * @return list<string>
     */
    private function patternVariables(array $tokens, array $clause): array
    {
        $names = [];
        for ($i = $clause['from']; $i < $clause['to']; $i++) {
            $t = $tokens[$i];
            if ($t->type !== CypherLexer::T_WORD || $t->braceDepth > 0) {
                continue;
            }
            $previous = $tokens[$i - 1];
            $next = $tokens[$i + 1] ?? null;
            $inPattern = ($previous->isPunct('(') || $previous->isPunct('['))
                && $next !== null && in_array($next->value, [':', ')', ']', '{', '*'], true);
            $path = ($i === $clause['from'] || $previous->isPunct(',')) && $next?->isPunct('=') === true;
            if ($inPattern || $path) {
                $names[$t->value] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * The comparisons of a WHERE that set one of $names against a text literal.
     *
     * @param list<Token> $tokens
     * @param array{head: string, start: int, from: int, to: int} $where
     * @param array<string, string> $names
     * @return list<string>
     */
    private function textConditions(array $tokens, array $where, array $names, string $cypher): array
    {
        $conditions = [];
        for ($i = $where['from']; $i < $where['to']; $i++) {
            $width = $this->comparisonWidth($tokens, $i);
            if ($width === 0) {
                continue;
            }

            $left = $this->operandStart($tokens, $i - 1, $where['from']);
            $right = $this->operandEnd($tokens, $i + $width, $where['to']);

            if (($this->mentions($tokens, $left, $i, $names) && $this->isTextOperand($tokens, $i + $width, $right))
                || ($this->mentions($tokens, $i + $width, $right, $names) && $this->isTextOperand($tokens, $left, $i))) {
                $conditions[] = $this->text($cypher, $tokens[$left]->offset, $tokens[$right]->offset ?? strlen($cypher));
            }
        }

        return $conditions;
    }

    /**
     * How many tokens the text comparison at $i spans (0 = none here).
     *
     * @param list<Token> $tokens
     */
    private function comparisonWidth(array $tokens, int $i): int
    {
        $t = $tokens[$i];
        if ($t->type === CypherLexer::T_OPERATOR) {
            return strcasecmp($t->value, 'WITH') === 0 ? 0 : 2;
        }
        if ($t->isWord('CONTAINS') && !$this->isIdentifier($tokens, $i)) {
            return 1;
        }
        // The lexer splits `<=`, `>=` and `=~` into single characters.
        $previous = ($tokens[$i - 1] ?? null)?->value;

        return $t->isPunct('=') && !in_array($previous, ['<', '>', '!'], true) && !($tokens[$i + 1] ?? null)?->isPunct('~') ? 1 : 0;
    }

    /**
     * First token of the operand ending at $i.
     *
     * @param list<Token> $tokens
     */
    private function operandStart(array $tokens, int $i, int $limit): int
    {
        $depth = 0;
        for ($j = $i; $j >= $limit; $j--) {
            $t = $tokens[$j];
            if ($t->isPunct(')') || $t->isPunct(']') || $t->isPunct('}')) {
                $depth++;
            } elseif ($t->isPunct('(') || $t->isPunct('[') || $t->isPunct('{')) {
                if ($depth === 0) {
                    return $j + 1;
                }
                $depth--;
            } elseif ($depth === 0 && $this->endsOperand($tokens, $j)) {
                return $j + 1;
            }
        }

        return $limit;
    }

    /**
     * Token after the operand starting at $i.
     *
     * @param list<Token> $tokens
     */
    private function operandEnd(array $tokens, int $i, int $limit): int
    {
        $depth = 0;
        for ($j = $i; $j < $limit; $j++) {
            $t = $tokens[$j];
            if ($t->isPunct('(') || $t->isPunct('[') || $t->isPunct('{')) {
                $depth++;
            } elseif ($t->isPunct(')') || $t->isPunct(']') || $t->isPunct('}')) {
                if ($depth === 0) {
                    return $j;
                }
                $depth--;
            } elseif ($depth === 0 && $this->endsOperand($tokens, $j)) {
                return $j;
            }
        }

        return $limit;
    }

    /** @param list<Token> $tokens */
    private function endsOperand(array $tokens, int $i): bool
    {
        $t = $tokens[$i];
        if ($t->isPunct(',')) {
            return true;
        }

        return $t->type === CypherLexer::T_WORD && !$this->isIdentifier($tokens, $i)
            && CypherClauses::in($t->value, ['AND', 'OR', 'XOR', 'NOT', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END']);
    }

    /**
     * A literal, possibly through functions: every word calls a function, so no variable,
     * property or parameter is involved.
     *
     * @param list<Token> $tokens
     */
    private function isTextOperand(array $tokens, int $from, int $to): bool
    {
        $literal = false;
        for ($i = $from; $i < $to; $i++) {
            $literal = $literal || $tokens[$i]->type === CypherLexer::T_LITERAL;
            if ($tokens[$i]->type === CypherLexer::T_WORD && ($tokens[$i + 1] ?? null)?->isPunct('(') !== true) {
                return false;
            }
        }

        return $literal;
    }

    /**
     * Whether the clauses after the OPTIONAL MATCH compute a total without requiring what it binds.
     *
     * @param list<Token> $tokens
     * @param list<array{head: string, start: int, from: int, to: int}> $clauses
     * @param array<string, string> $tainted the names holding what it binds
     */
    private function totalIgnores(array $tokens, array $clauses, array $tainted): bool
    {
        $total = false;
        foreach ($clauses as $clause) {
            if ($clause['head'] !== 'WITH' && $clause['head'] !== 'RETURN') {
                if ($this->mentions($tokens, $clause['from'], $clause['to'], $tainted)) {
                    return false;
                }
                continue;
            }

            $projected = $this->project($tokens, $clause, $tainted);
            if ($projected === null) {
                return false;
            }
            $total = $total || $projected['total'];
            $tainted = $projected['tainted'];

            if ($clause['head'] === 'RETURN' && in_array(self::VALUE, $tainted, true)) {
                return false;
            }
        }

        return $total;
    }

    /**
     * What a WITH / RETURN leaves in scope, or null when an item requires a tainted name.
     *
     * @param list<Token> $tokens
     * @param array{head: string, start: int, from: int, to: int} $clause
     * @param array<string, string> $tainted
     * @return array{total: bool, tainted: array<string, string>}|null
     */
    private function project(array $tokens, array $clause, array $tainted): ?array
    {
        $total = false;
        $next = [];
        $shadowed = [];

        foreach ($this->items($tokens, $clause) as $index => [$from, $to, $alias]) {
            $bare = $to - $from === 1 ? $tokens[$from]->value : null;
            if ($bare === '*') {
                $next += $tainted;
                continue;
            }

            if (!$this->mentions($tokens, $from, $to, $tainted)) {
                $total = $total || $this->hasTotal($tokens, $from, $to);
                $shadowed[$alias ?? $bare ?? ''] = true;
                continue;
            }

            if ($this->isWholeCollect($tokens, $from, $to)) {
                $next[$alias ?? "#{$index}"] = self::LIST;
            } elseif ($bare !== null) {
                $next[$alias ?? $bare] = $tainted[$bare];
            } elseif ($alias !== null && $this->isValueOf($tokens, $from, $to, $tainted)) {
                $next[$alias] = self::VALUE;
            } else {
                return null;
            }
        }

        return ['total' => $total, 'tainted' => array_diff_key($next, $shadowed)];
    }

    /**
     * The items of a projection: their expression's token range and their alias.
     *
     * @param list<Token> $tokens
     * @param array{head: string, start: int, from: int, to: int} $clause
     * @return list<array{0: int, 1: int, 2: ?string}>
     */
    private function items(array $tokens, array $clause): array
    {
        $from = $clause['from'];
        if ($from < $clause['to'] && $tokens[$from]->isWord('DISTINCT')) {
            $from++;
        }

        $items = [];
        for ($i = $from; $i <= $clause['to']; $i++) {
            if ($i < $clause['to'] && !($tokens[$i]->isTopLevel() && $tokens[$i]->isPunct(','))) {
                continue;
            }
            $aliased = $i - $from >= 3 && $tokens[$i - 2]->isTopLevel() && $tokens[$i - 2]->isWord('AS');
            $items[] = [$from, $aliased ? $i - 2 : $i, $aliased ? $tokens[$i - 1]->value : null];
            $from = $i + 1;
        }

        return $items;
    }

    /** @param list<Token> $tokens */
    private function hasTotal(array $tokens, int $from, int $to): bool
    {
        for ($i = $from; $i < $to; $i++) {
            if ($tokens[$i]->type === CypherLexer::T_WORD && CypherClauses::in($tokens[$i]->value, self::TOTALS)
                && ($tokens[$i + 1] ?? null)?->isPunct('(') === true && !($tokens[$i - 1] ?? null)?->isPunct('.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `collect(…)` and nothing around it.
     *
     * @param list<Token> $tokens
     */
    private function isWholeCollect(array $tokens, int $from, int $to): bool
    {
        if (!$tokens[$from]->isWord('collect') || ($tokens[$from + 1] ?? null)?->isPunct('(') !== true) {
            return false;
        }
        for ($i = $from + 2; $i < $to; $i++) {
            if ($tokens[$i]->isPunct(')') && $tokens[$i]->parenDepth === $tokens[$from + 1]->parenDepth) {
                return $i === $to - 1;
            }
        }

        return false;
    }

    /**
     * Nothing but the value of a tainted name, possibly through functions (`s.name`,
     * `toLower(s.name)`, `coalesce(s.name, 'none')`): no other variable, no aggregate,
     * no operator, no collected list.
     *
     * @param list<Token> $tokens
     * @param array<string, string> $tainted
     */
    private function isValueOf(array $tokens, int $from, int $to, array $tainted): bool
    {
        for ($i = $from; $i < $to; $i++) {
            $t = $tokens[$i];
            if ($t->type === CypherLexer::T_LITERAL) {
                continue;
            }
            if ($t->type === CypherLexer::T_PUNCT) {
                if (!in_array($t->value, ['(', ')', ',', '.'], true)) {
                    return false;
                }
                continue;
            }
            if (($tokens[$i - 1] ?? null)?->isPunct('.') === true) {
                continue;
            }
            $isCall = ($tokens[$i + 1] ?? null)?->isPunct('(') === true;
            if ($isCall ? CypherClauses::in($t->value, [...self::TOTALS, 'collect']) : ($tainted[$t->value] ?? null) !== self::VALUE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a name of $names is used as a variable in the token range.
     *
     * @param list<Token> $tokens
     * @param array<string, string> $names
     */
    private function mentions(array $tokens, int $from, int $to, array $names): bool
    {
        for ($i = $from; $i < $to; $i++) {
            if ($tokens[$i]->type === CypherLexer::T_WORD && isset($names[$tokens[$i]->value]) && $this->isVariable($tokens, $i)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A word that is neither a property, a label / type, a map key nor a function name.
     *
     * @param list<Token> $tokens
     */
    private function isVariable(array $tokens, int $i): bool
    {
        $previous = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;
        $inMap = $tokens[$i]->braceDepth > 0;

        return $previous?->isPunct('.') !== true
            && !($previous?->isPunct(':') === true && !$inMap)
            && !($inMap && $next?->isPunct(':') === true && ($previous?->isPunct('{') === true || $previous?->isPunct(',') === true))
            && $next?->isPunct('(') !== true;
    }

    /**
     * A label / property / backtick word, or an alias: never grammar.
     *
     * @param list<Token> $tokens
     */
    private function isIdentifier(array $tokens, int $i): bool
    {
        return $tokens[$i]->keywordAsIdentifier || ($tokens[$i - 1] ?? null)?->isWord('AS') === true;
    }

    private function text(string $cypher, int $from, int $to): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', substr($cypher, $from, $to - $from)));
    }
}
