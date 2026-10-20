<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

use Condoedge\Ai\Services\Security\CypherLexer;

/**
 * Literal masking for the PERSISTED turn trace (privacy): string and number
 * literals become `?`, so a stored trace keeps the query's SHAPE (labels,
 * relationships, properties, parameters) without the user values it carried.
 */
final class TraceRedactor
{
    /**
     * Mask a Cypher query with the package's own lexer: string-literal tokens
     * and numeric words become `?`; comments become an empty comment. Backtick
     * identifiers and `$params` are kept. An input the lexer cannot tokenize
     * (unterminated string, ...) falls back to the text masker.
     */
    public static function maskCypher(string $cypher): string
    {
        try {
            $lexed = (new CypherLexer())->tokenize($cypher);
        } catch (\Throwable) {
            return self::maskText($cypher);
        }

        if (($lexed['error'] ?? null) !== null) {
            return self::maskText($cypher);
        }

        $spanEnd = [];
        foreach ($lexed['literalSpans'] as [$start, $end]) {
            $spanEnd[$start] = $end;
        }

        $replacements = [];
        $tokenStarts = [];
        foreach ($lexed['tokens'] as $token) {
            $tokenStarts[$token->offset] = true;

            if ($token->type === CypherLexer::T_LITERAL && isset($spanEnd[$token->offset])) {
                $replacements[] = [$token->offset, $spanEnd[$token->offset], '?'];
                continue;
            }

            $isBacktick = ($cypher[$token->offset] ?? '') === '`';
            if ($token->type === CypherLexer::T_WORD && !$isBacktick
                && $token->value !== '' && ctype_digit($token->value[0])) {
                $replacements[] = [$token->offset, $token->offset + strlen($token->value), '?'];
            }
        }

        // A recorded span with no token at its start is a comment: mask it too.
        foreach ($spanEnd as $start => $end) {
            if (!isset($tokenStarts[$start])) {
                $replacements[] = [$start, $end, '/*?*/'];
            }
        }

        usort($replacements, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($replacements as [$start, $end, $with]) {
            $cypher = substr($cypher, 0, $start) . $with . substr($cypher, $end);
        }

        return $cypher;
    }

    /**
     * Mask quoted strings (terminated or not) and standalone numbers in free
     * text (error messages, un-lexable queries).
     */
    public static function maskText(string $text): string
    {
        $patterns = [
            "/'(?:[^'\\\\]|\\\\.)*'/s",
            '/"(?:[^"\\\\]|\\\\.)*"/s',
            "/'[^']*$/s",
            '/"[^"]*$/s',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '?', $text) ?? $text;
        }

        $masked = preg_replace('/(?<![\p{L}\p{N}_$])\d+(?:[.,]\d+)*(?![\p{L}\p{N}_])/u', '?', $text);
        if ($masked === null) {
            // Invalid UTF-8: byte-level fallback.
            $masked = preg_replace('/(?<![A-Za-z0-9_$\x80-\xFF])\d+(?:[.,]\d+)*(?![A-Za-z0-9_\x80-\xFF])/', '?', $text) ?? $text;
        }

        return $masked;
    }
}
