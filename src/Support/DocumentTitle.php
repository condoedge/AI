<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * DocumentTitle — a document's OWN TITLE, read off its structure.
 *
 * A file name says where a document is stored, not what it is
 * ("HR_Policy_EN.md"); the document itself usually does, in its first
 * heading ("# Employee Handbook — Leave Policy"). That heading is what lets an
 * answer name its source (eval p27: the composer saw the file name only and wrote
 * "the HR policy rules").
 *
 * The rule is structural only — no file-name pattern, no vocabulary, no language:
 * the title is the FIRST TOP-LEVEL MARKDOWN HEADING of the document's FIRST
 * CHUNK, when the first heading of that chunk is a top-level one. A document
 * whose first chunk has no heading (a scanned upload), or opens on a section
 * heading, has NO title: nothing is guessed from a first line. A top-level
 * heading further down the document is a part of it, never its title.
 *
 * The title is document text (whatever its author typed): it is DATA. Every
 * prompt that prints it does so through {@see UntrustedLabel::documentTitle()}:
 * the composer reads it inside the fence of its passage (the "Title:" line), the
 * follow-up writer as the sanitised label of an excerpt.
 *
 * It is stamped on every chunk at processing time ({@see self::METADATA_KEY}) and
 * otherwise read at question time off the stored first chunk ({@see self::of()}).
 */
final class DocumentTitle
{
    /** Chunk metadata key of the title stamped at processing time. */
    public const METADATA_KEY = 'document_title';

    /** A stored / derived title is at most this long (a heading longer than this is no title line). */
    public const MAX_CHARS = 200;

    /**
     * The title a document's first chunk gives it: its first markdown heading when
     * that heading is a top-level one (`# Title`), its marks removed. Fenced code
     * is opaque (a `# comment` of a script is not a heading) — the chunker's own
     * reading of a heading ({@see \Condoedge\Ai\Services\SemanticChunker}).
     *
     * @return string '' when the chunk gives the document no title.
     */
    public static function fromFirstChunk(string $content): string
    {
        $inFence = false;
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence || preg_match('/^(#{1,6})\s+(.*\S)\s*$/', $line, $m) !== 1) {
                continue;
            }

            // The first heading decides: a document that opens on a section has no title.
            return strlen($m[1]) === 1 ? self::clean($m[2]) : '';
        }

        return '';
    }

    /**
     * The title stamped on a chunk at processing time; null when it carries none.
     */
    public static function stored(mixed $chunk): ?string
    {
        $metadata = is_object($chunk) && is_array($chunk->metadata ?? null) ? $chunk->metadata : [];
        $title = $metadata[self::METADATA_KEY] ?? null;
        $title = is_string($title) ? self::clean($title) : '';

        return $title !== '' ? $title : null;
    }

    /**
     * The title of the document these chunks belong to: the one stamped on them,
     * else the one its FIRST chunk (chunk index 0) gives it. Chunks that do not
     * include the first one say nothing ('').
     *
     * @param iterable<mixed> $chunks Chunks of ONE file, in any order.
     */
    public static function of(iterable $chunks): string
    {
        $first = null;
        foreach ($chunks as $chunk) {
            if (!is_object($chunk)) {
                continue;
            }
            $stored = self::stored($chunk);
            if ($stored !== null) {
                return $stored;
            }
            if (isset($chunk->chunkIndex) && (int) $chunk->chunkIndex === 0) {
                $first ??= $chunk;
            }
        }

        return $first !== null ? self::fromFirstChunk((string) ($first->content ?? '')) : '';
    }

    /**
     * A heading's plain title: closing hashes and emphasis / code marks removed,
     * one line, capped.
     */
    private static function clean(string $heading): string
    {
        $heading = mb_scrub($heading, 'UTF-8');
        $heading = preg_replace('/\s+#+\s*$/u', '', $heading) ?? $heading;
        $heading = str_replace(['**', '__', '`'], '', $heading);
        $heading = trim(preg_replace('/\s+/u', ' ', $heading) ?? $heading);

        return mb_strlen($heading, 'UTF-8') > self::MAX_CHARS
            ? rtrim(mb_substr($heading, 0, self::MAX_CHARS, 'UTF-8'))
            : $heading;
    }
}
