<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * Brace / string / comment aware lexer for the subset of Cypher we tenant-scope.
 *
 * It is deliberately small and conservative: it does NOT understand Cypher
 * semantics, it only produces a flat token stream in which string literals are
 * collapsed into opaque LITERAL tokens (so a label written inside `'...'` is
 * never mistaken for a graph pattern), comments are dropped like whitespace
 * (their spans are still reported so nothing is spliced inside one), and in
 * which every structural token carries its byte offset and the
 * paren `()` / bracket `[]` / brace `{}` nesting depth at which it occurs.
 *
 * The analyzer recognizes the safe-scopeable subset purely from this stream;
 * anything the lexer cannot classify (an unterminated literal) makes the whole
 * input un-analyzable, which the analyzer turns into a fail-closed rejection.
 */
final class CypherLexer
{
    public const T_WORD = 'word';       // keyword or identifier (case-insensitive value)
    public const T_LITERAL = 'literal'; // string literal (opaque); comments emit no token
    public const T_PUNCT = 'punct';     // single structural/operator char
    /**
     * A keyword word that is part of a multi-word string OPERATOR (`STARTS WITH`,
     * `ENDS WITH`). Both words carry this type (value = raw text) so no consumer's
     * `isWord('WITH')` / T_WORD clause-keyword scan can mistake the operator's
     * `WITH` for the WITH clause. See {@see markStringOperators()}.
     */
    public const T_OPERATOR = 'operator';

    /**
     * Infix / prefix keywords that are ALWAYS followed by an OPERAND (an
     * expression start, alias or name) — never by a binary operator. When one
     * directly precedes `starts`/`ends`, that word is an identifier
     * (`AS ends`, `WHERE starts`, `NOT starts`, `YIELD starts`), so a following
     * `WITH` is the CLAUSE. Cypher grammar tokens, not semantic vocabulary.
     */
    private const OPERAND_EXPECTING_KEYWORDS = [
        // boolean / comparison / predicate infix + prefix operators
        'WHERE', 'AND', 'OR', 'XOR', 'NOT', 'IN', 'CONTAINS', 'IS', 'STARTS', 'ENDS',
        // CASE expression parts
        'CASE', 'WHEN', 'THEN', 'ELSE',
        // projection / aliasing / ordering (the next token is an item or name)
        'AS', 'DISTINCT', 'BY', 'YIELD', 'WITH', 'RETURN', 'UNWIND',
        // update clauses whose body starts with an operand
        'DELETE', 'SET', 'REMOVE',
    ];

    /**
     * Clause / modifier HEADS that Neo4j also accepts as VARIABLE names
     * (`AS limit`, `AS order`, `AS filter` …). Such a word before `starts`/`ends`
     * is ambiguous and resolved by ITS OWN position:
     *   - after an operand-expecting token (`WHERE limit`, `= skip`, `(order`,
     *     `NOT filter`, the operator's WITH) it is a VARIABLE, so the following
     *     `STARTS WITH` is the operator;
     *   - otherwise (after a completed clause `)`/identifier/literal/`*`, after
     *     `{`/`|` which open clause bodies, or at the start) it is a CLAUSE HEAD
     *     and `starts`/`ends` is its operand variable, so the WITH is the clause.
     */
    private const CLAUSE_HEAD_KEYWORDS = [
        'SKIP', 'LIMIT', 'OFFSET', 'ORDER', 'USE', 'NEXT', 'FILTER', 'LET', 'ON',
        'ALL', 'CALL', 'MATCH', 'OPTIONAL', 'CREATE', 'MERGE', 'UNION', 'FOREACH',
        'DETACH', 'NODETACH', 'FINISH', 'INSERT',
    ];

    /**
     * Punctuation after which a clause head may begin (or which ends an operand),
     * so a following clause-head keyword is NOT in operand position.
     */
    private const NON_OPERAND_CONTEXT_PUNCT = [')', ']', '}', '{', '|', '*', ';'];

    /**
     * Tokenize the query.
     *
     * @return array{tokens: list<Token>, literalSpans: list<array{0:int,1:int}>, error: ?string}
     */
    public function tokenize(string $cypher): array
    {
        $tokens = [];
        $literalSpans = [];
        $len = strlen($cypher);
        $i = 0;

        // Running nesting depths. () bracket [] brace {} tracked independently so
        // the analyzer can ask "paren depth here" and "brace depth here" separately.
        $paren = 0;
        $bracket = 0;
        $brace = 0;

        while ($i < $len) {
            $c = $cypher[$i];

            // Whitespace.
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i++;
                continue;
            }

            // Defence in depth (the executor canonicalizes first — see
            // CypherCanonicalizer): any other control character is whitespace or
            // garbage to Neo4j but would be a structural token here, desyncing
            // adjacency (`OPTIONAL<FF>MATCH`, `(i<FF>:Invoice)`) → un-analyzable.
            $ord = ord($c);
            if (($ord < 0x20) || $ord === 0x7F) {
                return ['tokens' => [], 'literalSpans' => [], 'error' => 'non_canonical_whitespace'];
            }

            // Line comment //... to end of line — Neo4j ends it at \r OR \n.
            if ($c === '/' && $i + 1 < $len && $cypher[$i + 1] === '/') {
                $start = $i;
                $i += 2;
                while ($i < $len && $cypher[$i] !== "\n" && $cypher[$i] !== "\r") {
                    $i++;
                }
                // Comments are WHITESPACE in Cypher: recorded as a span (never
                // spliced into) but emitted as NO token, so no adjacency check can
                // be broken by one — `MATCH (/*c*/i:Invoice)` once hid the binding
                // from the analyzer AND the coverage gate (ran unscoped), and
                // `OPTIONAL /*c*/ MATCH` hid the OPTIONAL-ness.
                $literalSpans[] = [$start, $i];
                continue;
            }

            // Block comment /* ... */
            if ($c === '/' && $i + 1 < $len && $cypher[$i + 1] === '*') {
                $start = $i;
                $i += 2;
                while ($i + 1 < $len && !($cypher[$i] === '*' && $cypher[$i + 1] === '/')) {
                    $i++;
                }
                if ($i + 1 >= $len) {
                    return ['tokens' => [], 'literalSpans' => [], 'error' => 'unterminated_comment'];
                }
                $i += 2; // consume */
                $literalSpans[] = [$start, $i]; // whitespace: span only, no token (see above)
                continue;
            }

            // Quoted string ('...' or "...") and backtick identifier (`...`).
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $start = $i;
                $i++;
                $closed = false;
                while ($i < $len) {
                    $cc = $cypher[$i];
                    if ($cc === '\\' && $quote !== '`') {
                        // Backslash escape inside a string (not in backtick idents).
                        $i += 2;
                        continue;
                    }
                    if ($cc === $quote) {
                        if ($quote === '`' && $i + 1 < $len && $cypher[$i + 1] === '`') {
                            // `` is an ESCAPED backtick inside the identifier (Neo4j):
                            // `x``y` is ONE identifier x`y, never two adjacent words.
                            $i += 2;
                            continue;
                        }
                        $i++;
                        $closed = true;
                        break;
                    }
                    $i++;
                }
                if (!$closed) {
                    return ['tokens' => [], 'literalSpans' => [], 'error' => 'unterminated_string'];
                }
                $literalSpans[] = [$start, $i];

                if ($quote === '`') {
                    // Backtick-quoted IDENTIFIER (label/variable/property name). It
                    // is structurally a word, so emit it as a T_WORD carrying the
                    // unquoted content — but its span is still recorded above so the
                    // injector never splices inside it.
                    $inner = str_replace('``', '`', substr($cypher, $start + 1, $i - $start - 2));
                    $tokens[] = new Token(self::T_WORD, $inner, $start, $paren, $bracket, $brace);
                } else {
                    // String literal — opaque.
                    $tokens[] = new Token(self::T_LITERAL, '', $start, $paren, $bracket, $brace);
                }
                continue;
            }

            // Non-ASCII outside a literal: a Unicode whitespace / control / format
            // character (NBSP, U+2028, ZWSP, …) is whitespace to Neo4j → reject
            // (defence in depth, see above); invalid UTF-8 → reject. Any other
            // non-ASCII character is part of an IDENTIFIER (Neo4j accepts Unicode
            // letters, e.g. `(é:Invoice)`) — previously each byte was a T_PUNCT,
            // which hid the binding from the analyzer AND the coverage gate.
            if ($ord >= 0x80) {
                $uch = $this->utf8CharAt($cypher, $i);
                if ($uch === null) {
                    return ['tokens' => [], 'literalSpans' => [], 'error' => 'invalid_encoding'];
                }
                if (preg_match('/^[\p{Cc}\p{Cf}\p{Z}]$/u', $uch) === 1) {
                    return ['tokens' => [], 'literalSpans' => [], 'error' => 'non_canonical_whitespace'];
                }
            }

            // Word: identifier / keyword / number-ish (letters, digits, _, $ and
            // non-ASCII identifier characters).
            if (ctype_alpha($c) || $c === '_' || $c === '$' || $ord >= 0x80) {
                $start = $i;
                $i += $ord >= 0x80 ? strlen((string) $this->utf8CharAt($cypher, $i)) : 1;
                while ($i < $len) {
                    $cc = $cypher[$i];
                    if (ctype_alnum($cc) || $cc === '_' || $cc === '$') {
                        $i++;
                        continue;
                    }
                    if (ord($cc) >= 0x80) {
                        $uch = $this->utf8CharAt($cypher, $i);
                        if ($uch === null) {
                            return ['tokens' => [], 'literalSpans' => [], 'error' => 'invalid_encoding'];
                        }
                        if (preg_match('/^[\p{Cc}\p{Cf}\p{Z}]$/u', $uch) === 1) {
                            return ['tokens' => [], 'literalSpans' => [], 'error' => 'non_canonical_whitespace'];
                        }
                        $i += strlen($uch);
                        continue;
                    }
                    break;
                }
                $value = substr($cypher, $start, $i - $start);
                $tokens[] = new Token(self::T_WORD, $value, $start, $paren, $bracket, $brace);
                continue;
            }

            // Numbers (kept as words so they don't masquerade as structure).
            if (ctype_digit($c)) {
                $start = $i;
                $i++;
                while ($i < $len && (ctype_alnum($cypher[$i]) || $cypher[$i] === '.' || $cypher[$i] === '_')) {
                    $i++;
                }
                $value = substr($cypher, $start, $i - $start);
                $tokens[] = new Token(self::T_WORD, $value, $start, $paren, $bracket, $brace);
                continue;
            }

            // Structural / operator punctuation. Depth is updated so that the
            // OPENING delimiter is reported at the OUTER depth and everything
            // inside is at the inner depth; the closing delimiter reports the
            // outer depth too.
            $tokenParen = $paren;
            $tokenBracket = $bracket;
            $tokenBrace = $brace;

            switch ($c) {
                case '(':
                    $paren++;
                    break;
                case ')':
                    $paren = max(0, $paren - 1);
                    $tokenParen = $paren;
                    break;
                case '[':
                    $bracket++;
                    break;
                case ']':
                    $bracket = max(0, $bracket - 1);
                    $tokenBracket = $bracket;
                    break;
                case '{':
                    $brace++;
                    break;
                case '}':
                    $brace = max(0, $brace - 1);
                    $tokenBrace = $brace;
                    break;
            }

            $tokens[] = new Token(self::T_PUNCT, $c, $i, $tokenParen, $tokenBracket, $tokenBrace);
            $i++;
        }

        if ($paren !== 0 || $bracket !== 0 || $brace !== 0) {
            return ['tokens' => [], 'literalSpans' => [], 'error' => 'unbalanced_delimiters'];
        }

        return [
            'tokens' => $this->markKeywordIdentifiers($this->markStringOperators($tokens, $cypher), $cypher),
            'literalSpans' => $literalSpans,
            'error' => null,
        ];
    }

    /**
     * Re-type the two words of every `STARTS WITH` / `ENDS WITH` string operator
     * as T_OPERATOR, so the operator's `WITH` is never read as the WITH CLAUSE by
     * any consumer (scope analyzer anchors / segmentation, liveness, structural
     * validator). Fixed ONCE here rather than at every WITH-handling call site.
     *
     * A `WITH` is the operator's iff, ignoring comments:
     *   1. the word before it is an unquoted `STARTS`/`ENDS`, and
     *   2. the token before THAT ends an operand (`)` `]` `}`, a string literal, a
     *      number, a `$param`, a backtick identifier, a property key after `.`,
     *      any word that is not an operand-expecting keyword, or a clause-head
     *      keyword used as a VARIABLE, i.e. itself in operand position —
     *      `WHERE limit STARTS WITH …`; see CLAUSE_HEAD_KEYWORDS).
     * Otherwise `starts`/`ends` is itself an identifier (`AS ends WITH …`,
     * `WHERE starts WITH …`, `n.starts WITH …`, `= ends WITH …`) and the WITH is
     * a clause.
     *
     * FAIL-SAFE ASYMMETRY: every ambiguous case falls back to CLAUSE (the prior
     * behavior, whose only failure mode is a rejected / non-executing rewrite).
     * Misreading a real clause WITH as an operator would erase a segment boundary,
     * so the operator reading requires the positive operand-ending evidence above.
     *
     * @param list<Token> $tokens
     * @return list<Token>
     */
    private function markStringOperators(array $tokens, string $cypher): array
    {
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $with = $tokens[$i];
            if (!$with->isWord('WITH') || $this->isBacktickQuoted($with, $cypher)) {
                continue;
            }

            $kwIdx = $this->previousToken($i);
            if ($kwIdx === null) {
                continue;
            }
            $kw = $tokens[$kwIdx];
            if (!($kw->isWord('STARTS') || $kw->isWord('ENDS')) || $this->isBacktickQuoted($kw, $cypher)) {
                continue;
            }

            $operandIdx = $this->previousToken($kwIdx);
            if ($operandIdx === null || !$this->endsOperand($tokens, $operandIdx, $cypher)) {
                continue;
            }

            $tokens[$kwIdx] = $this->asOperator($kw);
            $tokens[$i] = $this->asOperator($with);
        }

        return $tokens;
    }

    /**
     * Index of the token before $idx, or null. Comments emit no tokens (they are
     * whitespace), so this is plain adjacency.
     */
    private function previousToken(int $idx): ?int
    {
        return $idx > 0 ? $idx - 1 : null;
    }

    /**
     * Whether the token at $idx completes an operand, so a following
     * `STARTS`/`ENDS` sits in binary-operator position.
     *
     * @param list<Token> $tokens
     */
    private function endsOperand(array $tokens, int $idx, string $cypher): bool
    {
        $t = $tokens[$idx];

        if ($t->type === self::T_PUNCT) {
            return $t->value === ')' || $t->value === ']' || $t->value === '}';
        }

        if ($t->type === self::T_LITERAL) {
            // Comments are skipped by the caller, so this is a string literal.
            return true;
        }

        if ($t->type !== self::T_WORD) {
            return false; // e.g. the WITH of a preceding string operator
        }

        $first = $t->value[0] ?? '';
        if ($this->isBacktickQuoted($t, $cypher) || $first === '$' || ctype_digit($first)) {
            return true; // quoted identifier / parameter / number
        }

        // Property key (`n.order STARTS WITH …`): any name, even a keyword.
        $prevIdx = $this->previousToken($idx);
        if ($prevIdx !== null && $tokens[$prevIdx]->isPunct('.')) {
            return true;
        }

        if ($this->inKeywordList($t->value, self::OPERAND_EXPECTING_KEYWORDS)) {
            return false;
        }

        if ($this->inKeywordList($t->value, self::CLAUSE_HEAD_KEYWORDS)) {
            // Keyword-named variable (`WHERE limit STARTS WITH …`) vs. clause head
            // (`… FILTER starts WITH i`): decided by the word's own position.
            return $this->isOperandContext($tokens, $prevIdx, $cypher);
        }

        return true; // identifier / NULL / TRUE / FALSE
    }

    /**
     * Whether a word immediately after the token at $prevIdx sits in OPERAND
     * position (so a clause-head keyword there is a VARIABLE). Conservative: only
     * positive operand-expecting evidence counts; anything else (including no
     * previous token) reads as clause context.
     *
     * The single shared position check: used for the string-operator reading and
     * for {@see markKeywordIdentifiers()} (consumed by the analyzer + validator).
     *
     * @param list<Token> $tokens
     */
    private function isOperandContext(array $tokens, ?int $prevIdx, string $cypher): bool
    {
        if ($prevIdx === null) {
            return false; // statement start: a clause head
        }
        $prev = $tokens[$prevIdx];

        if ($prev->type === self::T_OPERATOR) {
            return true; // the WITH / STARTS / ENDS of a string operator
        }

        if ($prev->type === self::T_PUNCT) {
            // `=`, `<`, `+`, `,`, `(`, `[`, `:` … expect an operand next.
            return !in_array($prev->value, self::NON_OPERAND_CONTEXT_PUNCT, true);
        }

        if ($prev->type === self::T_WORD) {
            if (!$this->inKeywordList($prev->value, self::OPERAND_EXPECTING_KEYWORDS)
                || $this->isBacktickQuoted($prev, $cypher)) {
                return false;
            }
            // A keyword word that is itself a property key / label (`n.set`,
            // `n:Where`) or an ALIAS (`AS where`, `AS in`) is an identifier that
            // ENDS an operand, not an operator.
            $beforePrev = $this->previousToken($prevIdx);
            return $beforePrev === null
                || !($this->isLabelPositionPunct($tokens[$beforePrev]) || $tokens[$beforePrev]->isWord('AS'));
        }

        return false; // string literal: an operand just ended
    }

    /**
     * Punctuation after which the next word is a LABEL / PROPERTY / label-
     * expression NAME, never a clause head: `.` (property), `:` (label / map key),
     * `!` `&` `|` (label expressions `b:!X`, `b:A&X`, `b:A|X`). `%` is excluded on
     * purpose: it is the wildcard label (`b:%`) — no name follows it, so a word
     * after it can be a REAL clause (`WHERE b:% MATCH (c)`).
     */
    private function isLabelPositionPunct(Token $t): bool
    {
        return $t->type === self::T_PUNCT && in_array($t->value, ['.', ':', '!', '&', '|'], true);
    }

    /**
     * Annotate every WORD token with its position, resolved ONCE here and read by
     * the analyzer + validator (no consumer re-derives it):
     *
     * Token::$operandPosition — the word sits where an OPERAND is expected
     * ({@see isOperandContext}: after `=`, `,`, `(`, AND, WHERE, …), so it is an
     * expression, not a clause head. Consumers may only use it where ignoring a
     * clause is FAIL-SAFE (see CypherScopeAnalyzer::anchorClauseKind).
     *
     * Token::$keywordAsIdentifier — the word is DEFINITELY an identifier, so no
     * clause scan may read it as a clause keyword:
     *   - label / property / label-expression name ({@see isLabelPositionPunct},
     *     comments skipped): `b:Yield`, `b:!Match`, `b:A&With`, `b:/*c*\/Set`,
     *     `n.limit`; or a backtick-quoted word;
     *   - `SKIP` / `LIMIT` / `OFFSET` in operand position: `WHERE limit …`,
     *     `AND skip > 1`, `AS offset`. A real SKIP/LIMIT/OFFSET follows a completed
     *     projection item (identifier, alias, number, `$param`, `)`, `*`, literal);
     *   - `ORDER` NOT followed by `BY` (exact grammar: the clause is ORDER BY).
     * Deliberately NOT set for pattern-clause heads merely in operand position
     * (`= contains MATCH (x)`): wrongly flagging a real MATCH could hide a
     * label-free binding from the analyzer.
     *
     * @param list<Token> $tokens
     * @return list<Token>
     */
    private function markKeywordIdentifiers(array $tokens, string $cypher): array
    {
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->type !== self::T_WORD) {
                continue;
            }

            $prevIdx = $this->previousToken($i);
            $operand = $this->isOperandContext($tokens, $prevIdx, $cypher);

            if ($this->isBacktickQuoted($t, $cypher)
                || ($prevIdx !== null && $this->isLabelPositionPunct($tokens[$prevIdx]))) {
                $identifier = true;
            } elseif ($t->isWord('ORDER')) {
                $identifier = !$this->nextIsWord($tokens, $i, 'BY');
            } elseif ($t->isWord('SKIP') || $t->isWord('LIMIT') || $t->isWord('OFFSET')) {
                $identifier = $operand;
            } elseif (isset($tokens[$i + 1]) && $tokens[$i + 1]->isPunct('=')
                && (CypherClauses::in($t->value, CypherClauses::CLAUSE_HEADS) || $t->isWord('WHERE'))) {
                // `OPTIONAL MATCH match = (m)`: a clause keyword directly followed
                // by `=` is a VARIABLE (a path variable), never a clause head — no
                // clause can start with `=`. Read as a head it flipped the
                // OPTIONAL-ness of the clause for every walk (security review
                // 2026-10-01, item 3). The analyzer additionally rejects it.
                $identifier = true;
            } else {
                $identifier = false;
            }

            if ($identifier || $operand) {
                $tokens[$i] = new Token(
                    $t->type, $t->value, $t->offset, $t->parenDepth, $t->bracketDepth, $t->braceDepth,
                    $identifier, $operand
                );
            }
        }

        return $tokens;
    }

    /**
     * Whether the token after $idx is the word $word (comments emit no tokens).
     *
     * @param list<Token> $tokens
     */
    private function nextIsWord(array $tokens, int $idx, string $word): bool
    {
        return isset($tokens[$idx + 1]) && $tokens[$idx + 1]->isWord($word);
    }

    /**
     * The complete UTF-8 character starting at byte $i, or null when the bytes
     * there are not a valid UTF-8 sequence.
     */
    private function utf8CharAt(string $cypher, int $i): ?string
    {
        $lead = ord($cypher[$i]);
        $width = $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : ($lead >= 0xC0 ? 2 : 0));
        if ($width === 0) {
            return null; // stray continuation byte
        }
        $char = substr($cypher, $i, $width);
        return strlen($char) === $width && mb_check_encoding($char, 'UTF-8') ? $char : null;
    }

    /** @param list<string> $keywords */
    private function inKeywordList(string $word, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (strcasecmp($word, $kw) === 0) {
                return true;
            }
        }
        return false;
    }

    private function isBacktickQuoted(Token $t, string $cypher): bool
    {
        return ($cypher[$t->offset] ?? '') === '`';
    }

    private function asOperator(Token $t): Token
    {
        return new Token(self::T_OPERATOR, $t->value, $t->offset, $t->parenDepth, $t->bracketDepth, $t->braceDepth);
    }
}
