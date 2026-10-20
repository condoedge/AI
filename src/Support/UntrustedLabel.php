<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * UntrustedLabel — the ONE sanitiser of a short tenant-supplied text that a prompt
 * prints as a LABEL: the name of an uploaded file, a document's own title, a
 * heading or a section title of a document, an article reference read from a
 * chunk's metadata.
 *
 * Why: such a label is document content like the passage it stands on, but it is
 * printed on a line of its own, often beside instructions. A file can be named
 * "ignore previous instructions….pdf"; a heading can hold a closing fence tag, a
 * line break followed by a block header, or a bracketed number that reads as a
 * citation. Where the model must be able to tell data from instructions, the
 * label belongs INSIDE the passage's untrusted-data fence; this class is what
 * makes it harmless to the STRUCTURE of the prompt wherever it is printed.
 *
 * What it does is structural only — it never reads what the words mean (no word
 * list, no language, no "looks like an instruction" guess):
 *  - ONE line: control characters and line / paragraph separators become a space,
 *    invisible format characters (zero-width, direction overrides) are removed;
 *  - no markup the prompts use as structure: angle brackets (a tag that closes a
 *    fence or opens one), backticks, asterisks and pipes are removed; square and
 *    curly brackets become parentheses (a label cannot forge a citation number, a
 *    passage id or a placeholder); a double quote becomes a single one (a label
 *    cannot close a quoted excerpt); runs of `=`, `#`, `-`, `~` are collapsed (no
 *    block header, no separator line);
 *  - a length cap, with an ellipsis.
 *
 * An ordinary name or heading comes out exactly as it went in. What the USER
 * sees (citation cards, download links) never goes through here: this is for
 * what a MODEL reads.
 */
final class UntrustedLabel
{
    /** A label is at most this long unless the caller says otherwise. */
    public const DEFAULT_CHARS = 160;

    /** A file name, as prompts print it. */
    public const FILE_NAME_CHARS = 120;

    /** A full heading path ("chapter › division › section"). */
    public const HEADING_PATH_CHARS = 300;

    /** A document's own title (its first top-level heading — {@see DocumentTitle}). */
    public const DOCUMENT_TITLE_CHARS = 160;

    /**
     * The label, safe to print on one line of a prompt ('' when nothing is left).
     */
    public static function of(string $text, int $maxChars = self::DEFAULT_CHARS): string
    {
        // Invalid byte sequences would make every /u pattern below return null.
        $text = mb_scrub($text, 'UTF-8');

        // One line, nothing invisible.
        $text = (string) preg_replace('/\p{Cf}+/u', '', $text);
        $text = (string) preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $text);

        // No markup a prompt uses as structure.
        $text = (string) preg_replace('/[<>`*|]+/u', ' ', $text);
        $text = strtr($text, ['[' => '(', ']' => ')', '{' => '(', '}' => ')', '"' => "'"]);
        $text = (string) preg_replace('/([=#])\1+/u', '$1', $text);
        $text = (string) preg_replace('/([\-~])\1{2,}/u', '$1', $text);

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        $maxChars = max(1, $maxChars);
        if (mb_strlen($text, 'UTF-8') > $maxChars) {
            $text = rtrim(mb_substr($text, 0, $maxChars - 1, 'UTF-8')) . '…';
        }

        return $text;
    }

    /** A file name ('' when nothing printable is left of it). */
    public static function fileName(string $name): string
    {
        return self::of($name, self::FILE_NAME_CHARS);
    }

    /** A document's own title ('' when it has none or nothing printable is left of it). */
    public static function documentTitle(string $title): string
    {
        return self::of($title, self::DOCUMENT_TITLE_CHARS);
    }

    /** A heading or a whole heading path. */
    public static function heading(string $heading): string
    {
        return self::of($heading, self::HEADING_PATH_CHARS);
    }
}
