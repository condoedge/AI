<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

use Condoedge\Ai\Services\Security\CypherClauses;
use Condoedge\Ai\Services\Security\CypherLexer;
use Condoedge\Ai\Services\Security\Token;

/**
 * AMBIGUOUS BOOLEAN PRECEDENCE in a generated query (eval p22-1001-B, rights t2).
 *
 * The generator wrote
 *
 *   WHERE (account branch) OR (contact branch) AND i.status IN […] AND year = '2026'
 *
 * AND binds tighter than OR (and than XOR), so the status and year filters applied
 * to the contact branch only: for a real customer every account invoice of every year
 * would have been summed. Nothing rejects such a query — it is valid Cypher.
 *
 * This reports every predicate that mixes an OR / XOR with an AND at the SAME
 * nesting level, so the generator's repair loop asks for explicit parentheses.
 * A draft-quality check of the generator only: it is never part of the security
 * pipeline (scope injection, gates), which runs on whatever is executed.
 *
 * Pure Cypher grammar on the {@see CypherLexer} token stream — string literals are
 * opaque, comments are whitespace, a backtick / property / label word is an
 * identifier — and no vocabulary:
 *
 *  - a LEVEL is what one pair of `( )`, `[ ]`, `{ }` or one `CASE … END` encloses
 *    (or the query's top level);
 *  - inside a level, a PREDICATE ends at anything a boolean expression cannot
 *    continue across: a clause head, WHERE, `,`, `|`, `;`, AS, WHEN / THEN / ELSE;
 *  - a predicate holding an OR (or XOR) and an AND is reported. `(a OR b) AND c`,
 *    `a AND (b OR c)` and `(a AND b) OR c` each keep one operator per level and
 *    pass; NOT is unary and never counts.
 *
 * Input the lexer rejects yields no violation (the validator and the security
 * layer deal with it).
 */
final class CypherBooleanPrecedence
{
    /** Longest predicate quoted back to the model. */
    public const MAX_QUOTED_CHARS = 400;

    /** Words (besides the clause heads) a boolean expression cannot continue across. */
    private const PREDICATE_BOUNDARIES = ['WHERE', 'AS', 'WHEN', 'THEN', 'ELSE', 'YIELD'];

    /** Punctuation that separates sibling expressions inside one level. */
    private const SEPARATORS = [',', '|', ';'];

    public function __construct(
        private readonly CypherLexer $lexer = new CypherLexer()
    ) {
    }

    /**
     * One teachable violation per ambiguous predicate (empty = nothing ambiguous).
     *
     * @return list<string>
     */
    public function violations(string $cypher): array
    {
        $violations = [];
        foreach ($this->scan($cypher) as $found) {
            $quoted = mb_strlen($found['text']) > self::MAX_QUOTED_CHARS
                ? rtrim(mb_substr($found['text'], 0, self::MAX_QUOTED_CHARS)) . ' …'
                : $found['text'];
            $or = $found['operator'];

            $violations[] = "Ambiguous boolean precedence in the predicate `{$quoted}`: it mixes {$or} and AND at one level, "
                . "and AND binds tighter than {$or}, so a condition written after the {$or} alternatives applies to the last alternative only. "
                . "Wrap the {$or} alternatives in one parenthesis: (A {$or} B) AND C AND D. "
                . "When an AND group really is one alternative, parenthesize that group: (A AND B) {$or} C.";
        }

        return $violations;
    }

    /**
     * The text of every ambiguous predicate, in query order (whitespace collapsed).
     *
     * @return list<string>
     */
    public function ambiguousPredicates(string $cypher): array
    {
        return array_column($this->scan($cypher), 'text');
    }

    /**
     * @return list<array{text: string, operator: string}>
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

        // Innermost level last. `case` levels are closed by END, the others by
        // their delimiter (the lexer guarantees those are balanced).
        $levels = [$this->level('root', 0)];

        foreach ($tokens as $i => $t) {
            $top = count($levels) - 1;

            if ($t->type === CypherLexer::T_PUNCT) {
                if ($t->value === '(' || $t->value === '[' || $t->value === '{') {
                    $levels[] = $this->level('delimiter', $t->offset + 1);
                } elseif ($t->value === ')' || $t->value === ']' || $t->value === '}') {
                    // A CASE left open inside the delimiter closes with it.
                    while (count($levels) > 1) {
                        $closed = array_pop($levels);
                        $this->report($found, $closed, $cypher, $t->offset);
                        if ($closed['kind'] === 'delimiter') {
                            break;
                        }
                    }
                } elseif (in_array($t->value, self::SEPARATORS, true)) {
                    $this->report($found, $levels[$top], $cypher, $t->offset);
                    $levels[$top] = $this->level($levels[$top]['kind'], $t->offset + 1);
                }
                continue;
            }

            // Identifiers are never grammar: a label / property / backtick word
            // (`n.or`, `n.end`) or an alias (`AS or`).
            if ($t->type !== CypherLexer::T_WORD || $t->keywordAsIdentifier
                || (($tokens[$i - 1] ?? null)?->isWord('AS') === true)) {
                continue;
            }

            if ($t->isWord('CASE')) {
                $levels[] = $this->level('case', $t->offset + strlen($t->value));
                continue;
            }

            if ($t->isWord('END') && $levels[$top]['kind'] === 'case') {
                // The CASE expression is one operand of the predicate around it,
                // which continues after END.
                $this->report($found, array_pop($levels), $cypher, $t->offset);
                continue;
            }

            if (CypherClauses::in($t->value, CypherClauses::CLAUSE_HEADS)
                || CypherClauses::in($t->value, self::PREDICATE_BOUNDARIES)) {
                $this->report($found, $levels[$top], $cypher, $t->offset);
                $levels[$top] = $this->level($levels[$top]['kind'], $t->offset + strlen($t->value));
                continue;
            }

            if ($t->isWord('AND')) {
                $levels[$top]['and'] = true;
            } elseif ($t->isWord('OR') || $t->isWord('XOR')) {
                $levels[$top]['or'] ??= strtoupper($t->value);
            }
        }

        while ($levels !== []) {
            $this->report($found, array_pop($levels), $cypher, strlen($cypher));
        }

        // Inner predicates close before outer ones: report in query order.
        usort($found, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        $unique = [];
        foreach ($found as $entry) {
            $unique[$entry['text']] ??= ['text' => $entry['text'], 'operator' => $entry['operator']];
        }

        return array_values($unique);
    }

    /**
     * @return array{kind: string, start: int, or: ?string, and: bool}
     */
    private function level(string $kind, int $start): array
    {
        return ['kind' => $kind, 'start' => $start, 'or' => null, 'and' => false];
    }

    /**
     * Record the predicate that ends at byte $end when it mixed the operators.
     *
     * @param list<array{text: string, operator: string, offset: int}> $found
     * @param array{kind: string, start: int, or: ?string, and: bool} $level
     */
    private function report(array &$found, array $level, string $cypher, int $end): void
    {
        if ($level['or'] === null || !$level['and']) {
            return;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', substr($cypher, $level['start'], $end - $level['start'])));
        if ($text === '') {
            return;
        }

        $found[] = ['text' => $text, 'operator' => $level['or'], 'offset' => $level['start']];
    }
}
