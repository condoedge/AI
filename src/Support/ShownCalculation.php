<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * A calculation the answer writes out, checked by code.
 *
 * The model works on the rows it was given, so a follow-up like "that amount x2 + 0.5" or "what
 * share of the total is that?" gets its result. What it may not do is state a figure nobody can
 * trace, or one that is wrong: eval p11 measured a model-summed total off by exactly $1,000, and on
 * 2026-10-30 "11,996.81 x 1.05" came back as 12,586.65. So a figure outside the turn's evidence
 * stands only when the answer shows the operation that gives it and every operand is a figure of
 * the turn. A result that slipped is corrected to what the operation gives. Parsed and evaluated
 * here, never handed to eval().
 */
final class ShownCalculation
{
    public const INSTRUCTION = 'When the user asks for a calculation on figures you were given, do it: '
        . 'write the operation in full with those figures, then = and its result. The system checks it.';

    /** Integers this small are never checked as stated figures either: a count of months, a factor. */
    private const FREE_OPERAND = 31;

    /** What a share is multiplied by to read as a percentage. */
    private const PERCENT_BASE = 100.0;

    /**
     * How far a stated result may be from its operation and still be a slip of it. Farther, the text
     * is not this operation's result ("2021-3 = 15 bills") and is never rewritten.
     */
    private const SLIP = 0.10;

    private const UNIT = '(?:[$€£]|[A-Z]{3}\b)';

    /** Regex: what follows a figure that no operator goes on from. */
    public const NOT_AN_OPERAND = '(?!\s*(?:[$€£]|[A-Z]{3}\b)?\s*[+\-−–×xX*\/÷])';

    /**
     * The result of every operation shown in the text that checks out, in order. A result may be an
     * operand of the next step ("1.05 × 2 = 2.10, 2.10 + 0.5 = 2.60").
     *
     * @param list<float> $evidence the turn's figures
     * @return list<float>
     */
    public static function results(string $text, array $evidence): array
    {
        return self::checked($text, $evidence)['results'];
    }

    /**
     * Every figure a checked operation stands behind beside the turn's evidence: its result, and a
     * percentage written as its factor. These may be stated.
     *
     * @param list<float> $evidence the turn's figures
     * @return list<float>
     */
    public static function figures(string $text, array $evidence): array
    {
        $checked = self::checked($text, $evidence);

        return [...$checked['results'], ...$checked['factors']];
    }

    /**
     * What each result that slipped should have been, by the result as written.
     *
     * @param list<float> $evidence the turn's figures
     * @return array<string, float>
     */
    public static function corrections(string $text, array $evidence): array
    {
        return self::checked($text, $evidence)['corrections'];
    }

    /** The text through the line of its last operation; null when it shows none. */
    public static function through(string $text): ?string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $last = null;
        foreach ($lines as $index => $line) {
            if (preg_match(self::pattern(), self::plain($line)) === 1) {
                $last = $index;
            }
        }

        return $last === null ? null : rtrim(implode("\n", array_slice($lines, 0, $last + 1)));
    }

    /** @return array{results: list<float>, factors: list<float>, corrections: array<string, float>} */
    private static function checked(string $text, array $evidence): array
    {
        $checked = ['results' => [], 'factors' => [], 'corrections' => []];
        if (!preg_match_all(self::pattern(), self::plain($text), $shown, PREG_SET_ORDER)) {
            return $checked;
        }

        foreach ($shown as $match) {
            [, $operation, $written] = $match;
            $factors = [];
            $value = self::evaluate($operation, [...$evidence, ...$checked['results']], $checked['corrections'], $factors);
            $result = StatedNumber::quantities($written)[0] ?? null;
            if ($value === null || $result === null) {
                continue;
            }
            $tolerance = 0.5 * 10 ** -$result['decimals'] + 1e-9;

            // The same value spelled out: "800 + (800 * 0.15) = 800 + 120 = 920". Its figures stand
            // because the two sides agree, and they carry the next step.
            $expanded = $match[3] ?? '';
            $unused = [];
            if ($expanded !== '' && abs((float) self::evaluate($expanded, null, [], $unused) - $value) <= $tolerance) {
                array_push($checked['results'], ...self::numbersIn($expanded));
                array_push($checked['factors'], ...$factors);
                continue;
            }

            foreach ($result['values'] as $stated) {
                if (abs(abs($value) - abs($stated)) <= $tolerance) {
                    $checked['results'][] = $stated;
                    array_push($checked['factors'], ...$factors);
                    continue 2;
                }
            }
            if (abs($value) > 1e-9 && abs(abs($value) - abs($result['values'][0])) / abs($value) <= self::SLIP) {
                $checked['corrections'][$written] = abs($value);
                array_push($checked['factors'], ...$factors);
            }
        }

        return $checked;
    }

    /** One operation, then = and its result (a lookahead: the result may start the next step). */
    private static function pattern(): string
    {
        $number = '(?<![\p{N}_.,])(?:' . StatedNumber::QUANTITY . ')';
        $unit = self::UNIT;
        // A term may name what it is: "57,015.71 (Operating fund) + ...".
        $term = "(?:{$unit}\s*)?{$number}(?:\s*{$unit})?(?:\s*%)?(?:\s*\([^()\d=]*\))?";
        $open = '(?:\(\s*)*';
        $close = '(?:\s*\))*';
        $operator = '\s*[+\-−–×xX*\/÷]\s*';
        $operation = "{$open}{$term}{$close}(?:{$operator}{$open}{$term}{$close})+";

        return "/({$operation})\s*=\s*(?=(?:{$unit}\s*)?({$number}))(?=({$operation})\s*=)?/u";
    }

    private static function plain(string $text): string
    {
        return str_replace(['**', '\\(', '\\)'], '', str_replace('\\times', '×', $text));
    }

    /** Null when an operand is not a figure of the turn, or the operation cannot be evaluated. */
    private static function evaluate(string $operation, ?array $known, array $corrections, array &$factors): ?float
    {
        $bare = self::withoutLabels($operation);
        if (!preg_match_all('/' . StatedNumber::QUANTITY . '|[+\-−–×xX*\/÷()%]/u', $bare, $found)) {
            return null;
        }

        $tokens = [];
        foreach ($found[0] as $token) {
            if ($token === '%') {
                $last = array_key_last($tokens);
                if ($last === null || !is_float($tokens[$last])) {
                    return null;
                }
                $tokens[$last] /= 100;
            } elseif (preg_match('/^\d/', $token) === 1) {
                $operand = $corrections[$token] ?? self::operand($token, $known, $factors);
                if ($operand === null) {
                    return null;
                }
                $tokens[] = (float) $operand;
            } else {
                $tokens[] = strtr($token, ['−' => '-', '–' => '-', '×' => '*', 'x' => '*', 'X' => '*', '÷' => '/']);
            }
        }

        $position = 0;
        $value = self::sum($tokens, $position);

        return $value !== null && $position === count($tokens) ? $value : null;
    }

    /** Units and the names terms carry: only numbers and operators stay. */
    private static function withoutLabels(string $operation): string
    {
        return (string) preg_replace(['/\([^()\d=]*\)/u', '/[A-Z]{3}\b/'], ' ', $operation);
    }

    /** @return list<float> */
    private static function numbersIn(string $operation): array
    {
        $numbers = [];
        foreach (StatedNumber::quantities(self::withoutLabels($operation)) as $quantity) {
            array_push($numbers, ...$quantity['values']);
        }

        return $numbers;
    }

    /** $known null: any figure, for a side that only has to agree with one already checked. */
    private static function operand(string $raw, ?array $known, array &$factors): ?float
    {
        $quantity = StatedNumber::quantities($raw)[0] ?? null;
        foreach ($quantity['values'] ?? [] as $value) {
            $free = $known === null || ($quantity['decimals'] === 0 && $value <= self::FREE_OPERAND);
            if ($free || StatedNumber::supported([$value], $quantity['decimals'], $known)) {
                return (float) $value;
            }
            if ($value === self::PERCENT_BASE || self::isPercentFactor($value, $known)) {
                $factors[] = (float) $value;

                return (float) $value;
            }
        }

        return null;
    }

    /** A percentage of the turn written as its factor: 5 % as 0.05, 1.05 or 0.95. */
    private static function isPercentFactor(float $value, array $known): bool
    {
        $percents = [$value * 100, ($value - 1) * 100, (1 - $value) * 100];
        foreach ($percents as $percent) {
            if ($percent > 0.0 && StatedNumber::supported([$percent], 2, $known)) {
                return true;
            }
        }

        return false;
    }

    private static function sum(array $tokens, int &$position): ?float
    {
        $value = self::product($tokens, $position);
        while ($value !== null && in_array($tokens[$position] ?? null, ['+', '-'], true)) {
            $operator = $tokens[$position++];
            $next = self::product($tokens, $position);
            if ($next === null) {
                return null;
            }
            $value = $operator === '+' ? $value + $next : $value - $next;
        }

        return $value;
    }

    private static function product(array $tokens, int &$position): ?float
    {
        $value = self::primary($tokens, $position);
        while ($value !== null && in_array($tokens[$position] ?? null, ['*', '/'], true)) {
            $operator = $tokens[$position++];
            $next = self::primary($tokens, $position);
            if ($next === null || ($operator === '/' && abs($next) < 1e-9)) {
                return null;
            }
            $value = $operator === '*' ? $value * $next : $value / $next;
        }

        return $value;
    }

    private static function primary(array $tokens, int &$position): ?float
    {
        $token = $tokens[$position++] ?? null;
        if (is_float($token)) {
            return $token;
        }
        if ($token !== '(') {
            return null;
        }
        $value = self::sum($tokens, $position);

        return ($tokens[$position++] ?? null) === ')' ? $value : null;
    }
}
