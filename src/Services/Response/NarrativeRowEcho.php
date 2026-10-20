<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Support\AliasNormalizer;

/**
 * NarrativeRowEcho — model-written LIST and TABLE blocks that restate the rows the system
 * renders right after the narrative (eval p17: a 10-bullet list repeating the first ten
 * table rows on A roster / B resilience t0 / B french t0, a numbered list of the three
 * unit-310 rows — against "Do NOT list the rows" in the same prompt, in different words
 * every run).
 *
 * Decided by STRUCTURE only, never by wording:
 *
 *   - a BLOCK is a markdown list (bullets or numbers, with its continuation lines) or a
 *     pipe table, as the model wrote it in the narrative;
 *   - an ITEM is one list entry / one table data row;
 *   - an item ECHOES a row when it contains one of the caller's KEYS — the values that
 *     identify the rendered rows (their own label cells, real values), matched as whole
 *     words, case-, emphasis- and whitespace-insensitively;
 *   - the block is an echo when it has at least MIN_ITEMS items, at least ECHO_RATIO of
 *     them echo a row, and they name at least two DIFFERENT keys (one value repeated on
 *     every line is a subject, not a list of rows).
 *
 * A list that does not name the rows (the legal conditions of a data + document turn) is
 * never touched, and neither is a block that cites a passage ("[2]"): that is
 * document-grounded content. Sentences are out of reach on purpose — a generic closer has
 * no structure that tells it from an answer.
 *
 * Eval p19 — beside a code-written facts line the lead is bounded by structure too, with the
 * same restraint (when unsure, the text stays): {@see firstSentence()} cuts what follows the
 * lead's first sentence at a safe boundary, {@see restates()} tells a lead whose every
 * content word the question or that line already says.
 */
final class NarrativeRowEcho
{
    /** Share of a block's items that must name a rendered row. */
    public const ECHO_RATIO = 0.6;

    /** A single entry may be the answer itself ("the president is …"). */
    private const MIN_ITEMS = 2;

    /** A key shorter than this matches too easily ("1", "A"). */
    private const MIN_KEY_LENGTH = 2;

    private const LIST_ITEM = '/^\s{0,3}(?:[-*+•]|\d{1,3}[.)])\s+(\S.*)$/u';

    private const TABLE_SEPARATOR = '/^\s*\|?\s*:?-{2,}:?\s*(?:\|\s*:?-{2,}:?\s*)*\|?\s*$/';

    /** A citation marker — "[3]", never a markdown link text "[3](…)" (as DataAnswerPlan). */
    private const CITATION = '/\[\d{1,3}\](?!\()/';

    /**
     * @param list<string> $keys the values that identify the rendered rows
     * @return array{0: string, 1: int} [the narrative without its echo blocks, blocks dropped]
     */
    public static function strip(string $narrative, array $keys): array
    {
        $keys = self::foldKeys($keys);
        if ($keys === [] || trim($narrative) === '') {
            return [$narrative, 0];
        }

        $lines = preg_split('/\R/u', $narrative) ?: [];
        $dropped = 0;
        foreach (array_reverse(self::blocks($lines)) as $block) {
            if (self::isEcho($block['items'], $keys)
                && preg_match(self::CITATION, implode("\n", array_slice($lines, $block['start'], $block['length']))) !== 1) {
                array_splice($lines, $block['start'], $block['length']);
                $dropped++;
            }
        }

        return [$dropped > 0 ? implode("\n", $lines) : $narrative, $dropped];
    }

    // =========================================================================
    // The lead beside a code-written facts line (eval p19)
    // =========================================================================

    /** A kept "first sentence" holds at least this many words — "Yes." / "Good news!" is an opener, not the answer. */
    private const MIN_SENTENCE_WORDS = 4;

    /**
     * A word that can carry information of its own: one holding a digit, or of at least this
     * many letters (the function words of the languages answered in are shorter). A length,
     * never a vocabulary.
     */
    private const CONTENT_WORD_LETTERS = 6;

    /**
     * Share of a lead's distinct words already said by the question / the system line from
     * which it is a restatement — MEASURED on the p19 leads and on written-for-the-purpose ones
     * ({@see restates()}): the two p19 leads that only restate sit at 0.64 and 0.82, other
     * restatements at 0.56–1.00; a real answer made ONLY of the question's words reaches 0.57
     * ("No one is behind on their fees."; "No member has a role." 0.40). At 0.6 every real
     * answer measured is kept — the price is the two weakest restatements (0.56, 0.57), which
     * stay. When unsure, the lead stays.
     */
    public const RESTATEMENT_SHARE = 0.6;

    /** A sentence end: terminal punctuation, its closing marks, whitespace, then an upper-case start. */
    private const SENTENCE_END = '/(?<end>[.!?][\"\'\x{201D}\x{2019}\x{00BB})\]*_]*)\s+(?=[\"\'\x{201C}\x{2018}\x{00AB}(\[*_]*\p{Lu})|(?<para>\R[ \t]*\R\s*)/u';

    /**
     * [the lead's first sentence, the text cut after it] — the cut is '' when the lead is one
     * sentence or no boundary is SAFE (eval p19: two of six leads were two sentences beside
     * "ONE sentence"; the second one was filler both times).
     *
     * A boundary is terminal punctuation followed by whitespace and an upper-case letter, or a
     * blank line. Never cut — by SHAPE, no abbreviation list:
     *   - inside a decimal or "art. 12" (no upper-case letter follows);
     *   - after an initial ("J. Rousseau"), a dotted token ("A.G.A.", an email) or a short
     *     capitalized token ("St.", "Inc.", "Yes."): that period may not end a sentence — the
     *     next boundary is used instead;
     *   - before the kept text holds MIN_SENTENCE_WORDS words (an opener is not the answer).
     * And nothing is cut at all when the lead holds a list / table / heading / quote (its
     * sentences are not the whole of it) or when the text that would be cut cites a passage
     * ("[2]" — document-grounded content of a data + document turn).
     *
     * @return array{0: string, 1: string}
     */
    public static function firstSentence(string $lead): array
    {
        $lead = trim($lead);
        if ($lead === '') {
            return ['', ''];
        }
        foreach (preg_split('/\R/u', $lead) ?: [] as $line) {
            if (preg_match(self::LIST_ITEM, $line) === 1 || self::isTableLine($line)
                || preg_match('/^\s{0,3}(?:#{1,6}\s|>|```)/', $line) === 1) {
                return [$lead, ''];
            }
        }
        foreach (self::boundaries($lead) as [$keptEnd, $restStart]) {
            $kept = rtrim(substr($lead, 0, $keptEnd));
            $cut = trim(substr($lead, $restStart));
            if ($cut === '' || count(self::words($kept)) < self::MIN_SENTENCE_WORDS) {
                continue;
            }

            return preg_match(self::CITATION, $cut) === 1 ? [$lead, ''] : [$kept, $cut];
        }

        return [$lead, ''];
    }

    /**
     * The SENTENCES of a text, in order — split only where {@see firstSentence()} would cut:
     * at its safe boundaries (same shapes, no abbreviation list), and never before the piece
     * holds MIN_SENTENCE_WORDS words, so an opener stays with the sentence it opens ("Yes.",
     * a clause number "1069." before its rule). Where no boundary is safe the text is ONE
     * sentence: a consumer that keeps whole sentences then keeps more, never half of one.
     *
     * For a text that must be shortened without being cut inside a sentence (the excerpts of
     * {@see FollowUpWriter}: eval p21, a rule reached its reader as "…is bound…").
     *
     * @return list<string> the trimmed sentences; [] for an empty text
     */
    public static function sentences(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $sentences = [];
        $from = 0;
        foreach (self::boundaries($text) as [$keptEnd, $restStart]) {
            $sentence = trim(substr($text, $from, $keptEnd - $from));
            if (count(self::words($sentence)) < self::MIN_SENTENCE_WORDS) {
                continue;
            }
            $sentences[] = $sentence;
            $from = $restStart;
        }
        if (($last = trim(substr($text, $from))) !== '') {
            $sentences[] = $last;
        }

        return $sentences;
    }

    /**
     * The safe sentence boundaries of a text, in order: [where the text before it ends, where
     * the text after it starts] (byte offsets). See {@see firstSentence()} for what is safe.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function boundaries(string $text): array
    {
        if (preg_match_all(self::SENTENCE_END, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $boundaries = [];
        foreach ($matches as $match) {
            if (isset($match['para']) && $match['para'][1] >= 0) {
                $boundaries[] = [$match['para'][1], $match['para'][1] + strlen($match['para'][0])];
                continue;
            }
            if ($match['end'][0][0] === '.' && !self::endsSentence(substr($text, 0, $match['end'][1]))) {
                continue;
            }
            $boundaries[] = [$match['end'][1] + strlen($match['end'][0]), $match[0][1] + strlen($match[0][0])];
        }

        return $boundaries;
    }

    /**
     * Whether a period closing $before can end a sentence, by the SHAPE of the token it
     * closes: not a single letter (an initial), not a token that holds a letter and another
     * period ("A.G.A", "e.g", an email address), not a capitalized token of at most four
     * letters ("St", "Dr", "Inc", "Ltd", "No" — and "Yes" / "Oui", openers the next sentence
     * completes). Any other token — a word, a number, an amount — ends it.
     */
    private static function endsSentence(string $before): bool
    {
        if (preg_match('/(\S+)$/u', $before, $m) !== 1) {
            return true;
        }
        $token = (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $m[1]);

        return !(preg_match('/^\p{L}$/u', $token) === 1
            || (str_contains($token, '.') && preg_match('/\p{L}/u', $token) === 1)
            || preg_match('/^\p{Lu}\p{L}{0,3}$/u', $token) === 1);
    }

    /**
     * Whether a lead only RESTATES what the user asked and what the system line right below it
     * already says (eval p19: once code printed the count, the groups and "not recorded", the
     * lead of a list question had nothing left — "Here are the members of your team along with
     * their roles:" above "523 members." and the table).
     *
     * Structural overlap over {@see AliasNormalizer} words (case, accents, plural mark folded;
     * figures kept whole), $references being the question and the system line:
     *   - the lead has at least one CONTENT word (a figure, or CONTENT_WORD_LETTERS letters) and
     *     EVERY one of them is in the references — a value, a name or a figure of its own
     *     ("…Lead union manager", "Amélie Quirion", "$1,211.48", "anything") is an answer;
     *   - and at least RESTATEMENT_SHARE of all its distinct words are — a short answer made of
     *     the question's words around a small word that carries it ("No member has a role.")
     *     is not one.
     * A lead citing a passage or carrying a link is never one. Not a judgement of truth: a
     * hedge or a false sentence in words of its own is out of reach here, as it is for any
     * structural test.
     *
     * @param list<string> $references the question, the code-written system line
     */
    public static function restates(string $lead, array $references): bool
    {
        if (trim($lead) === '' || preg_match(self::CITATION, $lead) === 1 || str_contains($lead, '](')) {
            return false;
        }

        $known = array_flip(self::words(implode("\n", array_filter($references, 'is_string'))));
        $words = self::words($lead);
        if ($known === [] || $words === []) {
            return false;
        }

        $content = 0;
        $said = 0;
        foreach ($words as $word) {
            $isKnown = isset($known[$word]);
            if (preg_match('/\d/', $word) === 1 || mb_strlen((string) preg_replace('/[^\p{L}]+/u', '', $word)) >= self::CONTENT_WORD_LETTERS) {
                if (!$isKnown) {
                    return false;
                }
                $content++;
            }
            $said += $isKnown ? 1 : 0;
        }

        return $content > 0 && $said / count($words) >= self::RESTATEMENT_SHARE;
    }

    /** A word of a row value a lead can be said to NAME: a figure, or at least this many letters. */
    private const VALUE_WORD_LETTERS = 4;

    /**
     * Whether a lead names at least one of the given row values — shares with them a figure or
     * a word of VALUE_WORD_LETTERS letters ({@see AliasNormalizer} folding). A lead citing a
     * passage or carrying a link counts as naming one (it is content of its own). Structure
     * only, and it errs towards "names one": a common word of a value is enough.
     *
     * @param list<string> $values
     */
    public static function namesAny(string $lead, array $values): bool
    {
        if (preg_match(self::CITATION, $lead) === 1 || str_contains($lead, '](')) {
            return true;
        }

        $inValues = array_flip(self::words(implode("\n", $values)));
        foreach (self::words($lead) as $word) {
            $nameable = preg_match('/\d/', $word) === 1
                || mb_strlen((string) preg_replace('/[^\p{L}]+/u', '', $word)) >= self::VALUE_WORD_LETTERS;
            if ($nameable && isset($inValues[$word])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The distinct folded words of a text (markdown emphasis and escapes removed).
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return array_values(array_unique(AliasNormalizer::tokens(str_replace(['*', '`', '\\'], '', $text))));
    }

    /**
     * The list and table blocks of the text, in order.
     *
     * @param list<string> $lines
     * @return list<array{start: int, length: int, items: list<string>}>
     */
    private static function blocks(array $lines): array
    {
        $blocks = [];
        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            if (preg_match(self::LIST_ITEM, $lines[$i]) === 1) {
                [$end, $items] = self::listBlock($lines, $i);
            } elseif (self::isTableLine($lines[$i])) {
                [$end, $items] = self::tableBlock($lines, $i);
            } else {
                $i++;
                continue;
            }
            $blocks[] = ['start' => $i, 'length' => $end - $i, 'items' => $items];
            $i = $end;
        }

        return $blocks;
    }

    /**
     * A list starting at $start: its entries, their indented continuation lines, and the
     * blank lines BETWEEN entries (a loose list). Ends before the first other line.
     *
     * @param list<string> $lines
     * @return array{0: int, 1: list<string>} [index after the block, items]
     */
    private static function listBlock(array $lines, int $start): array
    {
        $items = [];
        $end = $start;
        $count = count($lines);
        for ($i = $start; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match(self::LIST_ITEM, $line, $m) === 1) {
                $items[] = $m[1];
                $end = $i + 1;
                continue;
            }
            if (trim($line) === '') {
                // A blank line stays inside the list only when the list goes on after it.
                $next = $i + 1;
                while ($next < $count && trim($lines[$next]) === '') {
                    $next++;
                }
                if ($next < $count && (preg_match(self::LIST_ITEM, $lines[$next]) === 1 || self::isContinuation($lines[$next]))) {
                    continue;
                }
                break;
            }
            if (self::isContinuation($line) && $items !== []) {
                $items[count($items) - 1] .= ' ' . trim($line);
                $end = $i + 1;
                continue;
            }
            break;
        }

        return [$end, $items];
    }

    /** An indented, non-empty line: the continuation of the list entry above it. */
    private static function isContinuation(string $line): bool
    {
        return preg_match('/^(?: {2,}|\t)\s*\S/u', $line) === 1;
    }

    private static function isTableLine(string $line): bool
    {
        return str_starts_with(ltrim($line), '|');
    }

    /**
     * A pipe table starting at $start: its consecutive `|` lines. The data rows are the
     * items — the header (the line above a separator) and the separator are not.
     *
     * @param list<string> $lines
     * @return array{0: int, 1: list<string>} [index after the block, items]
     */
    private static function tableBlock(array $lines, int $start): array
    {
        $end = $start;
        $count = count($lines);
        while ($end < $count && self::isTableLine($lines[$end])) {
            $end++;
        }

        $rows = array_slice($lines, $start, $end - $start);
        $hasHeader = isset($rows[1]) && preg_match(self::TABLE_SEPARATOR, $rows[1]) === 1;
        $items = [];
        foreach ($rows as $index => $row) {
            if (($hasHeader && $index === 0) || preg_match(self::TABLE_SEPARATOR, $row) === 1) {
                continue;
            }
            $items[] = str_replace('|', ' ', $row);
        }

        return [$end, $items];
    }

    /**
     * @param list<string> $items
     * @param list<string> $keys folded keys
     */
    private static function isEcho(array $items, array $keys): bool
    {
        if (count($items) < self::MIN_ITEMS) {
            return false;
        }

        $echoing = 0;
        $named = [];
        foreach ($items as $item) {
            $text = self::fold($item);
            $hit = false;
            foreach ($keys as $key) {
                if (str_contains($text, $key)
                    && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($key, '/') . '(?![\p{L}\p{N}])/u', $text) === 1) {
                    $named[$key] = true;
                    $hit = true;
                }
            }
            if ($hit) {
                $echoing++;
            }
        }

        return count($named) >= 2 && $echoing / count($items) >= self::ECHO_RATIO;
    }

    /**
     * @param list<string> $keys
     * @return list<string> distinct folded keys
     */
    private static function foldKeys(array $keys): array
    {
        $folded = [];
        foreach ($keys as $key) {
            $key = is_string($key) ? self::fold($key) : '';
            if (mb_strlen($key) >= self::MIN_KEY_LENGTH) {
                $folded[$key] = true;
            }
        }

        return array_map('strval', array_keys($folded));
    }

    /**
     * Text as compared: lower-cased, markdown emphasis / escapes and typographic variants
     * removed, whitespace (non-breaking included) collapsed.
     */
    private static function fold(string $text): string
    {
        $text = str_replace(['*', '_', '`', '\\'], '', $text);
        $text = str_replace(["\u{2019}", "\u{2018}"], "'", $text);
        $text = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text);

        return mb_strtolower(trim($text));
    }
}
