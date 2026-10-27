<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * ArticleLocator — the ONE parser of the articles a passage DEFINES.
 *
 * A passage defines an article when a line STARTS with its clause number
 * ("2104. A member who joins…", "**2105.1.** It must be possible…") or with an
 * "Article 12" heading. In-text mentions ("in accordance with article 2105") and
 * amendment-history lines ("*2019, c. 12, a. 2104*") are cross-references, not
 * definitions. Continuation chunks with no line-start number fall back to the
 * chunk metadata `article_no` / `article_range` (Phase 7 chunking).
 *
 * Shared by: FileContextSection (passage labels), AnswerValidator (the target of
 * a citation move must DEFINE the article) and the chunker. Structural numeric
 * patterns only — never a vocabulary.
 */
final class ArticleLocator
{
    /** Ranges wider than this are a metadata error, not a passage — ignored. */
    private const MAX_RANGE_SPAN = 200;

    /** In a text, a dash between two article numbers is a range only up to this span. */
    private const MAX_REFERENCE_RANGE = 20;

    /**
     * Article numbers the snippet defines, ascending and unique.
     *
     * @return list<int>
     */
    public static function definedArticles(string $snippet): array
    {
        $articles = [];
        // Clause numbers of 3+ digits at line start, optionally bold, with sub-articles.
        if (preg_match_all('/^[\s#*>\-]*(\d{3,5})(?:\.\d+)*\.(?:\*+)?\s/m', $snippet, $m)) {
            $articles = $m[1];
        }
        // "Article 12" / "Art. 12" headings at line start.
        if (preg_match_all('/^[\s#*>\-]*art(?:icle)?s?\.?\s*(\d{1,5})\b/imu', $snippet, $m)) {
            $articles = [...$articles, ...$m[1]];
        }

        return self::normalize($articles);
    }

    /**
     * Articles a retrieved reference covers: what its snippet defines, else its
     * chunk metadata (`article_no`, `article_range` as "2103-2105", [2103, 2105]
     * or {from, to}; top-level or under `metadata`). Empty = unknown.
     *
     * @return list<int>
     */
    public static function forReference(array $reference): array
    {
        $defined = self::definedArticles((string) ($reference['snippet'] ?? ''));
        if ($defined !== []) {
            return $defined;
        }

        $meta = is_array($reference['metadata'] ?? null) ? $reference['metadata'] : [];
        $articles = [];

        $number = $reference['article_no'] ?? $meta['article_no'] ?? null;
        if (is_numeric($number)) {
            $articles[] = (int) $number;
        }

        $range = $reference['article_range'] ?? $meta['article_range'] ?? null;
        [$from, $to] = self::bounds($range);
        if ($from !== null && $to !== null && $to >= $from && $to - $from <= self::MAX_RANGE_SPAN) {
            $articles = [...$articles, ...range($from, $to)];
        }

        return self::normalize($articles);
    }

    /**
     * The article a single LINE starts (Phase 7 chunk boundaries), with its
     * sub-article number kept ("2105.1") — the form stored in chunk metadata
     * `article_no`. Same line-start grammar as definedArticles(): a 3+ digit clause
     * number ("**2104.** …", "2101.1. …") or an "Article 12" / "Art. 12" heading.
     */
    public static function lineArticle(string $line): ?string
    {
        if (preg_match('/^[\s#*>\-]*(\d{3,5}(?:\.\d+)*)\.(?:\*+)?\s/', $line, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^[\s#*>\-]*art(?:icle)?\.?\s*(\d{1,5}(?:\.\d+)*)\b(?!\.\d)/iu', $line, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Article numbers a TEXT references — a question (exact-article retrieval angle) or
     * an answer (the articles it cites) — in order of appearance, unique, as strings
     * ("2104", "2105.1"). Numeric-reference grammar only:
     *  - an "art"/"art."/"article(s)" marker followed by one or more numbers separated
     *    by PUNCTUATION: comma, semicolon, ampersand, slash ("arts. 2103, 2104",
     *    "art. 2101; 2103"). A word is never a separator — it ends the list — so no
     *    vocabulary is involved ("articles 2101 and 2103" names 2101);
     *  - in such a list, a dash between two whole numbers that ascend by at most
     *    MAX_REFERENCE_RANGE is a RANGE and names every article between its bounds
     *    ("art. 2100–2103" = 2100, 2101, 2102, 2103). A descending pair, a sub-article
     *    bound or a wider span keeps the two numbers written;
     *  - or a bare 4-digit clause number written in clause form: a sub-article anywhere
     *    ("2105.1."), a whole number only at the start of a line ("2104. The board…"),
     *    since a whole one ending a sentence is a year as often ("amended in 2024.").
     * A bare number without one of these forms is a quantity, not a reference.
     *
     * @return list<string>
     */
    public static function referencedArticles(string $text): array
    {
        $found = [];
        $number = '\d{1,5}(?:\.\d+)?';
        $separator = '[,;&\/\x{2013}\x{2014}-]';

        if (preg_match_all("/\bart(?:icle)?s?\.?\s*({$number}(?:\s*{$separator}\s*{$number})*)/iu", $text, $m)) {
            foreach ($m[1] as $list) {
                $found = [...$found, ...self::listedArticles($list, $number, $separator)];
            }
        }
        if (preg_match_all('/(?<![\d.])(\d{4}\.\d+)\.(?=\s|$)|^[ \t]*(\d{4})\.(?=\s|$)/mu', $text, $m)) {
            foreach (array_keys($m[0]) as $i) {
                $found[] = $m[1][$i] !== '' ? $m[1][$i] : $m[2][$i];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The numbers of one reference list ("2015, 2100–2102; 2108"), a short ascending
     * range of whole numbers expanded to its members.
     *
     * @return list<string>
     */
    private static function listedArticles(string $list, string $number, string $separator): array
    {
        preg_match_all("/({$number})|({$separator})/u", $list, $tokens, PREG_SET_ORDER);

        $articles = [];
        $previous = null;
        $dash = false;
        foreach ($tokens as $token) {
            if (($token[2] ?? '') !== '') {
                $dash = !in_array($token[2], [',', ';', '&', '/'], true);
                continue;
            }

            $current = $token[1];
            if ($dash && $previous !== null && ctype_digit($previous) && ctype_digit($current)
                && (int) $current > (int) $previous && (int) $current - (int) $previous <= self::MAX_REFERENCE_RANGE) {
                for ($article = (int) $previous + 1; $article < (int) $current; $article++) {
                    $articles[] = (string) $article;
                }
            }
            $articles[] = $current;
            $previous = $current;
            $dash = false;
        }

        return $articles;
    }

    /** @return array{0: ?int, 1: ?int} */
    private static function bounds(mixed $range): array
    {
        // Bounds may carry a sub-article suffix ("2101.1–2102", "2110–2110.1"):
        // anchor on whole numbers (never a digit right after a dot) and keep the
        // base article number of each bound.
        if (is_string($range) && preg_match('/(?<![\d.])(\d{1,5})(?:\.\d+)*\s*[-–—]\s*(\d{1,5})(?:\.\d+)*/u', $range, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (is_string($range) && preg_match('/^\s*(\d{1,5})(?:\.\d+)*\s*$/', $range, $m) === 1) {
            return [(int) $m[1], (int) $m[1]];
        }
        if (is_array($range)) {
            $from = $range['from'] ?? $range[0] ?? null;
            $to = $range['to'] ?? $range[1] ?? $from;
            if (is_numeric($from) && is_numeric($to)) {
                return [(int) $from, (int) $to];
            }
        }

        return [null, null];
    }

    /** @return list<int> */
    private static function normalize(array $articles): array
    {
        $articles = array_values(array_unique(array_map('intval', $articles)));
        sort($articles);

        return $articles;
    }
}
