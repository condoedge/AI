<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * THE structural "same word, different spelling" test for entity labels and aliases.
 *
 * An entity config lists the words people use for an entity ("fob", "télécommande").
 * Spellings that are DERIVABLE from a term already known — its plural, its
 * accent-less form, its snake_case / spaced / glued form, another casing — carry no
 * information; listing them only hides the real synonyms behind every consumer's
 * cap. Every alias consumer goes through here instead, so a derivable spelling
 * matches whether or not the config lists it.
 *
 * Two terms are the same variant when their key() is equal. The key is built by
 * exactly these steps, nothing else:
 *   1. words are split at camelCase boundaries ("VisitDetail" → "Visit Detail",
 *      "EFTFile" → "EFT File");
 *   2. lower-casing;
 *   3. accent folding: Unicode NFD, combining marks removed ("accès" → "acces").
 *      Without ext-intl (and without the symfony polyfill) a Latin letter table is
 *      used instead;
 *   4. a trailing plural mark is dropped PER TOKEN: one final "s" or "x", only on a
 *      token made of letters alone, only when at least three letters remain, and
 *      never a doubled "ss" ("fobs" → "fob", "niveaux" → "niveau"; "bus", "class",
 *      "a320s" are left alone);
 *   5. the separators — whitespace, underscore, hyphen/dash — are removed.
 *
 * Deliberately NOT done (a term the rules cannot prove redundant is kept): no
 * stemming, no "-es"/"-ies"/irregular plurals ("accesses", "people"), no
 * language-specific rule, no word list. Purely structural.
 */
final class AliasNormalizer
{
    /** Whitespace, underscore, ASCII hyphen and the Unicode hyphen/dash block. */
    private const SEPARATORS = '/[\s_\-\x{2010}-\x{2015}]+/u';

    /** lower→Upper ("visitDetail") and ACRONYM→Word ("EFTFile") boundaries. */
    private const CAMEL_BOUNDARY = '/(?<=\p{Ll})(?=\p{Lu})|(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u';

    /** A plain word that carries a trailing plural mark: 3+ letters, then one s/x. */
    private const PLURAL = '/^\p{L}{3,}[sx]$/u';

    private const MEMO_LIMIT = 4096;

    /** @var array<string, string> term => key */
    private static array $keys = [];

    /** @var array{0: string, 1: string, 2: list<string>}|null the last text given to mentions() */
    private static ?array $lastText = null;

    /**
     * The comparison key of a term ('' for an empty / separator-only term).
     */
    public static function key(string $term): string
    {
        if (isset(self::$keys[$term])) {
            return self::$keys[$term];
        }

        $key = implode('', self::split($term, false));

        if (count(self::$keys) >= self::MEMO_LIMIT) {
            self::$keys = [];
        }

        return self::$keys[$term] = $key;
    }

    /**
     * Whether two terms are spellings of the same word(s). An empty term matches nothing.
     */
    public static function sameVariant(string $a, string $b): bool
    {
        $key = self::key($a);

        return $key !== '' && $key === self::key($b);
    }

    /**
     * Lower-cased, accent-folded text (steps 2-3) — separators and plurals untouched.
     */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        // Pure ASCII has no accent to fold.
        if (!preg_match('/[\x80-\xff]/', $text)) {
            return $text;
        }

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $stripped = preg_replace('/\p{Mn}+/u', '', $decomposed);

                return is_string($stripped) ? $stripped : $text;
            }

            return $text;
        }

        return self::foldLatin($text);
    }

    /**
     * Accent folding without ext-intl (and without the symfony polyfill):
     * the precomposed lower-case Latin letters only.
     */
    private static function foldLatin(string $text): string
    {
        return strtr($text, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        ]);
    }

    /**
     * The folded words of a free text (a user question): steps 1-4, with the
     * punctuation around each word dropped ("(fobs)?" → "fob").
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        return self::split($text, true);
    }

    /**
     * A label as the words a person types: "VisitDetail" → "visit detail".
     * Case and separators only — accents and plurals are kept.
     */
    public static function phrase(string $label): string
    {
        $spaced = (string) preg_replace(self::CAMEL_BOUNDARY, ' ', $label);
        $spaced = (string) preg_replace(self::SEPARATORS, ' ', $spaced);

        return trim(mb_strtolower($spaced, 'UTF-8'));
    }

    /**
     * The real synonyms of an alias list: every alias that is neither a variant of
     * one of $labels nor of an alias already kept, in declaration order.
     *
     * Among the spellings of one word the most natural is kept, at the position of
     * the first: accented over accent-less, then no underscore over underscore,
     * then spaced over glued ("unit owner" over "unitowner"); the first one on a tie.
     *
     * @param iterable<mixed> $aliases non-string / blank entries are ignored
     * @return list<string>
     */
    public static function distinct(iterable $aliases, string ...$labels): array
    {
        $known = [];
        foreach ($labels as $label) {
            $known[self::key($label)] = true;
        }

        $kept = [];
        foreach ($aliases as $alias) {
            if (!is_string($alias) || trim($alias) === '') {
                continue;
            }
            $alias = trim($alias);
            $key = self::key($alias);
            if ($key === '' || isset($known[$key])) {
                continue;
            }
            if (!isset($kept[$key]) || self::naturalness($alias) > self::naturalness($kept[$key])) {
                $kept[$key] = $alias;
            }
        }

        return array_values($kept);
    }

    /**
     * Whether a free text mentions a term, in any of its spellings.
     *
     * Either the folded term is a substring of the folded text (the loose match the
     * alias detection always used, now accent-insensitive), or a run of adjacent
     * words of the text has the term's key ("visit_details" / "Visit Details" for
     * "VisitDetail", "fobs" for "fob", "acces" for "accès").
     */
    public static function mentions(string $text, string $term): bool
    {
        $needle = trim(self::fold($term));
        if ($needle === '' || trim($text) === '') {
            return false;
        }

        if (self::$lastText === null || self::$lastText[0] !== $text) {
            self::$lastText = [$text, self::fold($text), self::tokens($text)];
        }
        [, $folded, $tokens] = self::$lastText;

        if (str_contains($folded, $needle)) {
            return true;
        }

        $key = self::key($term);
        if ($key === '') {
            return false;
        }

        $length = strlen($key);
        $count = count($tokens);
        for ($start = 0; $start < $count; $start++) {
            $run = '';
            for ($i = $start; $i < $count && strlen($run) < $length; $i++) {
                $run .= $tokens[$i];
            }
            if ($run === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Steps 1-4: the folded tokens of a term or text.
     *
     * @return list<string>
     */
    private static function split(string $text, bool $trimPunctuation): array
    {
        $text = (string) preg_replace(self::CAMEL_BOUNDARY, ' ', $text);
        $parts = preg_split(self::SEPARATORS, self::fold($text), -1, PREG_SPLIT_NO_EMPTY);

        $tokens = [];
        foreach ($parts ?: [] as $token) {
            if ($trimPunctuation) {
                $token = (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $token);
            }
            if ($token === '') {
                continue;
            }
            if (!str_ends_with($token, 'ss') && preg_match(self::PLURAL, $token)) {
                $token = substr($token, 0, -1);
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @return array{0: int, 1: int, 2: int} [accented, no underscore, word count]
     */
    private static function naturalness(string $spelling): array
    {
        return [
            self::fold($spelling) !== mb_strtolower($spelling, 'UTF-8') ? 1 : 0,
            str_contains($spelling, '_') ? 0 : 1,
            count(self::split($spelling, false)),
        ];
    }
}
