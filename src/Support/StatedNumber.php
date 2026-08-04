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
