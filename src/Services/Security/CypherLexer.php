<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * Brace / string / comment aware lexer for the subset of Cypher we tenant-scope.
 *
 * It is deliberately small and conservative: it does NOT understand Cypher
 * semantics, it only produces a flat token stream in which string literals,
 * backtick-quoted identifiers and comments are collapsed into opaque LITERAL
 * tokens (so a label written inside `'...'` is never mistaken for a graph
 * pattern), and in which every structural token carries its byte offset and the
 * paren `()` / bracket `[]` / brace `{}` nesting depth at which it occurs.
 *
 * The analyzer recognizes the safe-scopeable subset purely from this stream;
 * anything the lexer cannot classify (an unterminated literal) makes the whole
 * input un-analyzable, which the analyzer turns into a fail-closed rejection.
 */
final class CypherLexer
{
    public const T_WORD = 'word';       // keyword or identifier (case-insensitive value)
    public const T_LITERAL = 'literal'; // string / backtick / comment (opaque)
    public const T_PUNCT = 'punct';     // single structural/operator char

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

            // Line comment //... to end of line.
            if ($c === '/' && $i + 1 < $len && $cypher[$i + 1] === '/') {
                $start = $i;
                $i += 2;
                while ($i < $len && $cypher[$i] !== "\n") {
                    $i++;
                }
                $literalSpans[] = [$start, $i];
                $tokens[] = new Token(self::T_LITERAL, '', $start, $paren, $bracket, $brace);
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
                $literalSpans[] = [$start, $i];
                $tokens[] = new Token(self::T_LITERAL, '', $start, $paren, $bracket, $brace);
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
                    $inner = substr($cypher, $start + 1, $i - $start - 2);
                    $tokens[] = new Token(self::T_WORD, $inner, $start, $paren, $bracket, $brace);
                } else {
                    // String literal — opaque.
                    $tokens[] = new Token(self::T_LITERAL, '', $start, $paren, $bracket, $brace);
                }
                continue;
            }

            // Word: identifier / keyword / number-ish (letters, digits, _ and $).
            if (ctype_alpha($c) || $c === '_' || $c === '$') {
                $start = $i;
                $i++;
                while ($i < $len) {
                    $cc = $cypher[$i];
                    if (ctype_alnum($cc) || $cc === '_' || $cc === '$') {
                        $i++;
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

        return ['tokens' => $tokens, 'literalSpans' => $literalSpans, 'error' => null];
    }
}
