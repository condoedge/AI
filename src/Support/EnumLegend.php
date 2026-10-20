<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * THE structural parse of an enum legend declared in an entity-config property
 * description, e.g. "Status (1=Draft, 2=Approved, 10=Voided)",
 * "Priority flag (-2=Low, -1=Regular, 1=High)" or — a property that stores STRING
 * keys — "Role key (lead=Lead union manager, manager=Union manager)".
 *
 * Shared by the schema cards (inline `{code=label}` legends for the query generator)
 * and the answer tables (code cells shown as their label, p12 H3 / p16 E7). Purely
 * structural — `<code>=<label>` pairs, at least two of them; no word lists and no
 * graph scans: only what the host config declares.
 *
 * A legend is ONE kind of code. Integer codes are read first, exactly as before: a
 * description that declares an integer legend is never widened by a `word=…` pair
 * in its prose. Only when it declares none are string codes read — an identifier
 * (letters, digits, `_`, `-`) that is not a property path (`tr.role = …` is a
 * predicate) nor quoted, in pairs separated by `,` or `;`.
 */
final class EnumLegend
{
    private const PAIR = '/(?<![\w.])(-?\d+)\s*=\s*([^,;=]+?)(?=\s*(?:[,;]|\.(?:\s|$)|$|\s+-?\d+\s*=))/u';

    private const STRING_PAIR = '/(?<![\p{L}\p{N}_.\'"`-])(\p{L}[\p{L}\p{N}_-]*)\s*=\s*([^,;=]+?)(?=\s*(?:[,;]|\.(?:\s|$)|$))/u';

    /**
     * @return array<string, string> code => label, in declaration order ([] when fewer than two pairs)
     */
    public static function parse(string $description): array
    {
        $description = trim((string) preg_replace('/\s+/u', ' ', $description));
        if ($description === '') {
            return [];
        }

        $legend = self::pairs(self::PAIR, $description);

        return $legend !== [] ? $legend : self::pairs(self::STRING_PAIR, $description);
    }

    /**
     * @return array<string, string> the pattern's code => label pairs ([] when fewer than two)
     */
    private static function pairs(string $pattern, string $description): array
    {
        if (!preg_match_all($pattern, $description, $matches, PREG_SET_ORDER) || count($matches) < 2) {
            return [];
        }

        $legend = [];
        foreach ($matches as $match) {
            $label = trim($match[2]);
            // "(1=Draft, 2=Approved)" — the legend's closing parenthesis is not part of the label.
            while (str_ends_with($label, ')') && substr_count($label, ')') > substr_count($label, '(')) {
                $label = rtrim(substr($label, 0, -1));
            }
            if ($label !== '') {
                $legend[$match[1]] ??= $label;
            }
        }

        return count($legend) >= 2 ? $legend : [];
    }
}
