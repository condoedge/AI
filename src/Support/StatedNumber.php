<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * STRUCTURAL standalone-number detection — the SINGLE SOURCE shared by the
 * ResponseGenerator's truncation-honesty guard and the eval harness's
 * discloses_total gate, so runtime guard and gate can never disagree on what
 * "the answer states N" means.
 *
 * No semantic word lists: the target is always one of the turn's own runtime
 * values (the true total / the remainder). The only transformations applied
 * are TYPOGRAPHIC/SYNTACTIC, never lexical:
 *
 *  - Locale digit grouping of the TARGET number ("1,000", "1 000", "1.000",
 *    NBSP/narrow-NBSP groups) collapses to the plain digit run before
 *    matching — a grouped total IS the total.
 *  - Markdown ordered-list markers at line starts ("1. ", "2) ") are stripped:
 *    they are list syntax, not stated quantities.
 *  - Multi-part digit tokens (dates "2026-07-23", times "14:30", decimals
 *    "10.5", versions "1.2.3", phone-ish runs) are stripped: a number embedded
 *    in such a token is a fragment of a different value, not a stated count.
 *  - EXCEPT the two multi-part forms that ARE disclosures of the turn's own
 *    runtime values, checked on the raw text BEFORE the strip: the tight
 *    fraction "<shown>/<total>" ("10/523"), and a standalone ascending range
 *    "<a>-<b>" whose inclusive span equals the shown count ("rows 1-10" or
 *    "rows 41-50" for shown=10).
 *
 * The final check is the literal standalone digit run: "100" is never
 * satisfied by "1000".
 */
final class StatedNumber
{
    /** Digit-grouping separators between 3-digit groups (comma, dot, space, NBSP, narrow NBSP). */
    private const GROUP_SEPARATOR_CLASS = '[,\. \x{00A0}\x{202F}]';

    /**
     * The truncation-disclosure rule, shared VERBATIM by the runtime guard
     * (ResponseGenerator's truncation honesty) and the eval gate
     * (ExpectationGate::gateDisclosesTotal) so guard and gate can never
     * disagree: the text must state the EXACT TOTAL as a standalone literal
     * AND additionally the SHOWN count or the exact REMAINDER.
     *
     * A bare total ("more than 523 results are available") is NOT a
     * disclosure: without the shown count or the remainder the reader cannot
     * tell how much was withheld, and the total alone is indistinguishable
     * from an approximation. Structural only — the three numbers are the
     * turn's own runtime values.
     */
    public static function disclosesTruncation(string $text, int $total, int $shown): bool
    {
        // "shown/total" as one tight token ("10/523") states BOTH numbers, but
        // would be destroyed by the multi-part-token strip — check it on the
        // raw text first. Structural: built from the turn's own runtime values.
        if (self::statesShownOverTotal($text, $total, $shown)) {
            return true;
        }

        if (!self::appears($text, $total)) {
            return false;
        }

        $remainder = $total - $shown;

        return self::appears($text, $shown)
            || self::statesShownAsRangeSpan($text, $shown)
            || ($remainder > 0 && self::appears($text, $remainder));
    }

    /**
     * The tight fraction token "<shown>/<total>" ("10/523"). Both runtime
     * values in one token IS a full disclosure; the structural-noise strip
     * would otherwise destroy it as a multi-part token.
     */
    private static function statesShownOverTotal(string $text, int $total, int $shown): bool
    {
        if ($total <= 0 || $shown <= 0) {
            return false;
        }

        return preg_match('/(?<!\d)' . $shown . '\/' . $total . '(?!\d)/', $text) === 1;
    }

    /**
     * A standalone two-part range token "<a>-<b>" whose inclusive span equals
     * the shown count ("rows 1-10" or "rows 41-50" for shown=10) STATES the
     * shown count structurally. Guards exclude any part of a longer multi-part
     * token (dates "2026-07-23", times, versions): the char before <a> and
     * after <b> must be neither a digit nor a token separator, and the range
     * must ascend. Structural arithmetic on the turn's own runtime value only.
     */
    private static function statesShownAsRangeSpan(string $text, int $shown): bool
    {
        if ($shown <= 0) {
            return false;
        }

        if (!preg_match_all('/(?<![\d.\/:\-])(\d+)-(\d+)(?![\d.\/:\-])/', $text, $matches, PREG_SET_ORDER)) {
            return false;
        }

        foreach ($matches as $match) {
            $from = (int) $match[1];
            $to = (int) $match[2];
            if ($to >= $from && ($to - $from + 1) === $shown) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $text states $number as a standalone literal.
     */
    public static function appears(string $text, int $number): bool
    {
        if ($number <= 0) {
            return false;
        }

        $text = self::collapseGroupedTarget($text, $number);
        $text = self::stripStructuralNoise($text);

        return preg_match('/(?<!\d)' . $number . '(?!\d)/', $text) === 1;
    }

    /**
     * Rewrite locale-grouped renderings of the TARGET number ("1,500" for 1500)
     * into the plain digit run, so the standalone match sees them. Only the
     * exact target is collapsed — unrelated grouped/decimal tokens are left for
     * the structural-noise strip.
     */
    private static function collapseGroupedTarget(string $text, int $number): string
    {
        if ($number < 1000) {
            return $text;
        }

        $digits = (string) $number;
        $groups = [];
        for ($i = strlen($digits); $i > 0; $i -= 3) {
            $start = max(0, $i - 3);
            array_unshift($groups, substr($digits, $start, $i - $start));
        }

        $pattern = '/(?<!\d)' . implode(self::GROUP_SEPARATOR_CLASS, $groups) . '(?!\d)/u';

        return preg_replace($pattern, $digits, $text) ?? $text;
    }

    /**
     * Every stated QUANTITY in free text, parsed typographically (Phase 8-B
     * AnswerValidator). Locale groupings are understood ("11,493.17",
     * "11 493,17 $", NBSP / narrow-NBSP groups, "1,50" decimal comma). Tokens
     * that are structurally something else are skipped: ordered-list markers,
     * multi-part tokens joined by - / : (dates, times, ranges, fractions), and
     * digits glued to letters ("310A", "2e"). An ambiguous token ("1,500" =
     * 1500 or 1.5) yields every reading in `values`; callers accept any.
     *
     * @return list<array{raw: string, values: list<float>, decimals: int, money: bool, offset: int}>
     */
    public static function quantities(string $text): array
    {
        // Same structural strip as appears(), but offset-preserving (blanked, not
        // removed) so a caller can patch the original text at `offset`.
        $blank = static fn (array $m): string => str_repeat(' ', strlen($m[0]));
        $text = (string) preg_replace_callback('/^[ \t]*\d+[.)](?=\s)/m', $blank, $text);
        $text = (string) preg_replace_callback('/\d+(?:[\/:\-–]\d+)+/u', $blank, $text);

        $pattern = '/(?<![\p{L}\p{N}_])([1-9]\d{0,2}(?:[,.\x{00A0}\x{202F} ]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d+)?)(?![\p{L}\p{N}_])/u';
        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $out = [];
        foreach ($matches as $match) {
            [$raw, $offset] = $match[1];
            $values = self::readings($raw);
            if ($values === []) {
                continue;
            }
            $before = substr($text, max(0, $offset - 3), min(3, $offset));
            $after = substr($text, $offset + strlen($raw), 3);
            $out[] = [
                'raw' => $raw,
                'values' => $values,
                'decimals' => self::decimalsOf($raw),
                'money' => preg_match('/[$€£]/u', $before . $after) === 1,
                'offset' => $offset,
            ];
        }

        return $out;
    }

    /**
     * Whether $value is supported by $evidence: equal within a cent, or equal to
     * an evidence figure rounded to the answer's own precision ("11 493 $" for
     * 11493.17). Pure arithmetic tolerance — formatting never decides.
     *
     * @param list<float> $values   readings of one stated token
     * @param list<float> $evidence
     */
    public static function supported(array $values, int $decimals, array $evidence): bool
    {
        foreach ($values as $value) {
            $value = abs($value);
            foreach ($evidence as $known) {
                $known = abs($known);
                if (abs($value - $known) < 0.005) {
                    return true;
                }
                if ($decimals < 2 && abs(round($known, $decimals) - $value) < 0.005) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every numeric reading of a raw token. "11,493.17" → 11493.17; "11 493,17"
     * → 11493.17; "1,500" → 1500 and 1.5; "10,5" → 10.5.
     *
     * @return list<float>
     */
    private static function readings(string $raw): array
    {
        $raw = str_replace(["\u{00A0}", "\u{202F}"], ' ', $raw);

        if (self::isGrouping($raw)) {
            // Last separator followed by 1-2 digits is the decimal mark.
            $decimal = '';
            if (preg_match('/^(.*\d{3})([.,])(\d{1,2})$/', $raw, $m) === 1) {
                [$raw, $decimal] = [$m[1], '.' . $m[3]];
            }
            $grouped = (float) (preg_replace('/[^\d]/', '', $raw) . $decimal);
            $values = [$grouped];
            // "1,500" / "1.500" alone may also be a 3-decimal reading.
            if ($decimal === '' && preg_match('/^\d{1,3}[.,]\d{3}$/', $raw) === 1) {
                $values[] = (float) str_replace(',', '.', $raw);
            }

            return $values;
        }

        return [(float) str_replace(',', '.', $raw)];
    }

    private static function decimalsOf(string $raw): int
    {
        $pattern = self::isGrouping($raw) ? '/\d{3}[.,](\d{1,2})$/' : '/[.,](\d+)$/';

        return preg_match($pattern, $raw, $m) === 1 ? strlen($m[1]) : 0;
    }

    private static function isGrouping(string $raw): bool
    {
        return preg_match('/^[1-9]\d{0,2}(?:[,. \x{00A0}\x{202F}]\d{3})+(?:[.,]\d{1,2})?$/u', $raw) === 1;
    }

    /**
     * Remove digit occurrences that are STRUCTURALLY something other than a
     * stated quantity: ordered-list markers and multi-part digit tokens.
     */
    private static function stripStructuralNoise(string $text): string
    {
        // Markdown ordered-list markers at line starts ("1. ", "12) ").
        $text = preg_replace('/^[ \t]*\d+[.)](?=\s)/m', '', $text) ?? $text;

        // Multi-part digit tokens: dates, times, decimals, versions, phone runs.
        return preg_replace('/\d+(?:[.\/:\-]\d+)+/', ' ', $text) ?? $text;
    }
}
