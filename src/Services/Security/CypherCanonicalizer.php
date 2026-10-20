<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * ONE canonicalization step that runs BEFORE any tenant-scoping analysis (Task
 * 1.26b). Closes the class of lexer/Neo4j DESYNC bypasses: anything Neo4j treats
 * as whitespace or as a comment boundary that the security lexer reads
 * differently lets text be invisible to the analyzer yet executed by Neo4j —
 * e.g. `// … \r UNION ALL MATCH (i:Invoice) …` (Neo4j ends `//` at CR) or
 * `OPTIONAL<FF>MATCH` / `(i<FF>:Invoice)` (FF is whitespace to Neo4j, T_PUNCT to
 * the lexer).
 *
 * Outside string literals ('…', "…", backslash escapes) and backtick
 * identifiers (`…`, `` escaped):
 *   - REJECT (`non_canonical_whitespace`) any character that is not ASCII space,
 *     \t, \n or \r AND is a control (Cc — C0, DEL, C1 incl. NEL), a format
 *     character (Cf — ZWSP, BOM, …) or a Unicode space / line / paragraph
 *     separator (Zs, Zl, Zp — NBSP, EM SPACE, IDEOGRAPHIC SPACE, U+2028 …);
 *   - REMOVE every comment — `/* … *\/` (not nested, like Neo4j) and `//` up to
 *     (not including) the first \r or \n — each replaced by ONE space;
 *   - reject invalid UTF-8 (`invalid_encoding`) and an unterminated block
 *     comment (`unterminated_comment`).
 * The generator never needs comments, so stripping them loses nothing.
 *
 * The caller must then analyze, gate, inject, validate AND EXECUTE only the
 * returned canonical text, so what runs is byte-identical to what was analyzed.
 */
final class CypherCanonicalizer
{
    public const REASON_WHITESPACE = 'non_canonical_whitespace';
    public const REASON_ENCODING = 'invalid_encoding';
    public const REASON_COMMENT = 'unterminated_comment';

    /**
     * @return array{query: ?string, reason: ?string} The canonical text, or null
     *         with the rejection reason.
     */
    public function canonicalize(string $cypher): array
    {
        if (!mb_check_encoding($cypher, 'UTF-8')) {
            return ['query' => null, 'reason' => self::REASON_ENCODING];
        }

        $out = '';
        $len = strlen($cypher);
        $i = 0;

        while ($i < $len) {
            $c = $cypher[$i];

            // String literal / backtick identifier: copied VERBATIM (opaque).
            if ($c === "'" || $c === '"' || $c === '`') {
                $end = $this->literalEnd($cypher, $i);
                $out .= substr($cypher, $i, $end - $i);
                $i = $end;
                continue;
            }

            // Block comment (not nested — Neo4j ends it at the first */).
            if ($c === '/' && ($cypher[$i + 1] ?? '') === '*') {
                $close = strpos($cypher, '*/', $i + 2);
                if ($close === false) {
                    return ['query' => null, 'reason' => self::REASON_COMMENT];
                }
                $out .= ' ';
                $i = $close + 2;
                continue;
            }

            // Line comment: ends at \r OR \n (exactly Neo4j's rule), which is kept.
            if ($c === '/' && ($cypher[$i + 1] ?? '') === '/') {
                $j = $i + 2;
                while ($j < $len && $cypher[$j] !== "\n" && $cypher[$j] !== "\r") {
                    $j++;
                }
                $out .= ' ';
                $i = $j;
                continue;
            }

            $ord = ord($c);
            if ($ord < 0x80) {
                if (($ord < 0x20 && $c !== "\t" && $c !== "\n" && $c !== "\r") || $ord === 0x7F) {
                    return ['query' => null, 'reason' => self::REASON_WHITESPACE];
                }
                $out .= $c;
                $i++;
                continue;
            }

            // Multi-byte UTF-8 character (encoding already validated).
            $width = $ord >= 0xF0 ? 4 : ($ord >= 0xE0 ? 3 : 2);
            $char = substr($cypher, $i, $width);
            if (preg_match('/^[\p{Cc}\p{Cf}\p{Z}]$/u', $char) === 1) {
                return ['query' => null, 'reason' => self::REASON_WHITESPACE];
            }
            $out .= $char;
            $i += $width;
        }

        return ['query' => $out, 'reason' => null];
    }

    /**
     * Byte offset just past the literal / backtick identifier opening at $start
     * (the end of input when unterminated — the lexer then rejects it).
     */
    private function literalEnd(string $cypher, int $start): int
    {
        $quote = $cypher[$start];
        $len = strlen($cypher);
        $i = $start + 1;

        while ($i < $len) {
            $cc = $cypher[$i];
            if ($quote !== '`' && $cc === '\\') {
                $i += 2; // escape inside a string
                continue;
            }
            if ($cc === $quote) {
                if ($quote === '`' && ($cypher[$i + 1] ?? '') === '`') {
                    $i += 2; // `` is an escaped backtick inside an identifier
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }

        return $len;
    }
}
