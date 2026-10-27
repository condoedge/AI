<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

use Condoedge\Ai\DTOs\FileChunk;

/**
 * DocumentMap
 *
 * The pure rules of the document map, shared by the index-time builder
 * (Services\Files\DocumentMapBuilder) and the question-time code so the two can
 * never disagree on what a usable record is.
 *
 * A map RECORD sits beside a chunk in its vector payload (top-level key `map`,
 * read back by the chunk store as `$chunk->metadata['map']`). Two kinds:
 *
 *   an ENTRY    { v, hash, gist,             scope?, scope_ref?, scope_why? }
 *   a FAILURE   { v, hash, failed, attempts, scope?, scope_ref?, scope_why? }
 *
 *  - `gist`      a plain-language statement of everything the passage establishes;
 *  - `failed`    why the chunk has no gist: FAILED_REJECTED (its gist did not
 *                retrieve its own passage, twice) or FAILED_ERROR (a call or the
 *                verification search failed); `attempts` counts the tries. A
 *                failure is a chunk that WAS attempted: it is simply absent from
 *                the map a reader is shown, it is not paid for again by every
 *                backfill, and it no longer keeps its whole file unmapped;
 *  - `scope`     'in' | 'out' — whether the chunk's top-level part governs the
 *                situation of the application's users. ABSENT whenever nothing
 *                was decided: absent means "do not filter". Only 'out' ever
 *                hides a chunk;
 *  - `scope_ref` the fingerprint ({@see self::scopeRef()}) of the application
 *                description the part was judged against. A record of a divided
 *                document without the current one is scope-PENDING: the part is
 *                judged (again) by the next build;
 *  - `scope_why` the judging model's one-line reason, kept for audit.
 *
 * Why a hash: the map is written AFTER ingest onto chunks that already exist. A
 * store that updates content in place would keep a gist describing the OLD text,
 * so a record names the content it was written from (`hash`) and a record whose
 * hash differs from the chunk's current content is STALE: it counts as absent
 * everywhere, with no invalidation step to forget. The same hash is what lets a
 * re-ingest of unchanged text carry its record over (FileProcessor).
 */
final class DocumentMap
{
    /** Record layout version: a record of another version counts as absent. */
    public const VERSION = 1;

    /** Joins the heading levels of a part ('Title › Chapter II'). */
    public const PART_SEPARATOR = ' › ';

    /**
     * A document is divided into parts only when its deepest heading path has
     * this many levels: title › part › section. With fewer levels the headings
     * under the title are the document's ordinary sections, not parallel parts
     * (measured on the host corpus: the 24 chunks of the two-level guides are one
     * subject each; the three-and-four-level code holds 3 parts of 3, 27 and 98
     * passages that state similar rules for different regimes).
     */
    public const MIN_DEPTH_FOR_PARTS = 3;

    /** Heading levels that name a part (title + part). */
    public const PART_LEVELS = 2;

    /** `failed`: the gist did not retrieve its own passage, on both attempts. */
    public const FAILED_REJECTED = 'rejected';

    /** `failed`: a model call or the verification search failed (transient). */
    public const FAILED_ERROR = 'error';

    /** A transient failure is tried again by the build job up to this many attempts in all. */
    public const MAX_TRANSIENT_ATTEMPTS = 3;

    /**
     * The share of a document's content chunks that must carry a gist for its map
     * to be read at question time. Measured on the host corpus: 1 gist of 134 was
     * rejected (0.7%), none of the 21 guide chunks. A document that loses more
     * than one chunk in ten has something systematically wrong with it (scanned
     * tables, boilerplate) and a reader shown such a map would reason over a
     * document with holes: it keeps the plain search instead.
     */
    public const MIN_MAPPED_SHARE = 0.9;

    /**
     * Cache key of "does any stored chunk carry a map?" — the answer a question
     * turn remembers for a minute (AiManager::anyDocumentMapStored()) and the map
     * builders forget after a run that wrote a gist, so a freshly built map is
     * used on the next question.
     */
    public const ANY_STORED_CACHE_KEY = 'ai:document_map:any';

    /**
     * The opening of a provision shown to the gist of its later pieces is at most
     * this long: an opening, not a second passage (measured: shown 500 characters
     * that held the first rows of a table, the gist described those rows).
     */
    public const LEAD_IN_CHARS = 300;

    /**
     * A line that opens a numbered or lettered sub-clause: "31.10 …", "20.1 …",
     * "1- …", "(a) …", "b) …", "2° …". A shape, not a vocabulary.
     */
    private const SUB_CLAUSE_LINE = '/^\s*(?:\(?\d+(?:\.\d+)+\)?|\(?(?:\d{1,3}|\p{L})[)\-°])\s/u';

    private const HASH_LENGTH = 16;

    /** A stored reason is kept at most this long. */
    private const WHY_CHARS = 200;

    /**
     * The fingerprint a record keeps of the content it was written from.
     */
    public static function hash(string $content): string
    {
        return substr(sha1($content), 0, self::HASH_LENGTH);
    }

    /**
     * The chunk's FRESH record of either kind, or null.
     *
     * Fresh = present, an array, of this VERSION and written from the chunk's
     * current content (hash). Anything else is treated exactly like a chunk that
     * was never looked at.
     *
     * @param FileChunk|array<string, mixed> $chunk A FileChunk, a search result
     *        (['chunk' => FileChunk, …]), or an array carrying `content` and the
     *        record under `metadata.map` (hydrated chunk) or `map` (raw payload).
     * @return array<string, mixed>|null
     */
    public static function recordOf(FileChunk|array $chunk): ?array
    {
        [$content, $metadata, $raw] = self::read($chunk);

        $record = $metadata['map'] ?? ($raw['map'] ?? null);
        if (!is_array($record) || $content === null) {
            return null;
        }

        if (($record['v'] ?? null) !== self::VERSION) {
            return null;
        }

        if (!is_string($record['hash'] ?? null) || !hash_equals(self::hash($content), $record['hash'])) {
            return null;
        }

        return $record;
    }

    /**
     * The chunk's fresh ENTRY (a record carrying a non-empty gist), or null.
     *
     * @param FileChunk|array<string, mixed> $chunk
     * @return array<string, mixed>|null
     */
    public static function entryOf(FileChunk|array $chunk): ?array
    {
        $record = self::recordOf($chunk);

        return $record !== null && is_string($record['gist'] ?? null) && trim($record['gist']) !== ''
            ? $record
            : null;
    }

    /**
     * The chunk's fresh FAILURE (a record that says why there is no gist), or null.
     *
     * @param FileChunk|array<string, mixed> $chunk
     * @return array{failed: string, attempts: int}|null
     */
    public static function failureOf(FileChunk|array $chunk): ?array
    {
        $record = self::recordOf($chunk);
        if ($record === null || self::entryOf($chunk) !== null
            || !is_string($record['failed'] ?? null) || $record['failed'] === '') {
            return null;
        }

        return ['failed' => $record['failed'], 'attempts' => max(1, (int) ($record['attempts'] ?? 1))];
    }

    /**
     * The record of a chunk whose gist could not be built.
     *
     * @return array{v: int, hash: string, failed: string, attempts: int}
     */
    public static function failure(string $content, string $reason, int $attempts = 1): array
    {
        return [
            'v' => self::VERSION,
            'hash' => self::hash($content),
            'failed' => $reason,
            'attempts' => max(1, $attempts),
        ];
    }

    /**
     * Where a document's map stands, over its CONTENT chunks (preamble chunks —
     * title / heading-only text, metadata `is_preamble` — establish nothing and
     * are never mapped):
     *
     * @param array<int, FileChunk|array<string, mixed>> $chunks The document's chunks.
     * @return array{content: int, mapped: int, failed: int, pending: int}
     *         mapped: chunks with a fresh entry; failed: with a fresh failure;
     *         pending: never attempted for their current content.
     */
    public static function state(array $chunks): array
    {
        $state = ['content' => 0, 'mapped' => 0, 'failed' => 0, 'pending' => 0];
        foreach ($chunks as $chunk) {
            if (self::isPreamble($chunk)) {
                continue;
            }
            $state['content']++;
            if (self::entryOf($chunk) !== null) {
                $state['mapped']++;
            } elseif (self::failureOf($chunk) !== null) {
                $state['failed']++;
            } else {
                $state['pending']++;
            }
        }

        return $state;
    }

    /**
     * Whether a document's map can be read at question time: every content chunk
     * was ATTEMPTED for its current content (none pending) and at least
     * MIN_MAPPED_SHARE of them carry a gist. A chunk whose gist failed is absent
     * from the map; it does not make the document unusable.
     *
     * @param array<int, FileChunk|array<string, mixed>> $chunks The document's chunks.
     */
    public static function isUsable(array $chunks): bool
    {
        $state = self::state($chunks);

        return $state['content'] > 0
            && $state['pending'] === 0
            && $state['mapped'] >= self::MIN_MAPPED_SHARE * $state['content'];
    }

    /**
     * The scope a chunk's fresh record carries: 'out', 'in', or null when nothing
     * hides or confirms it (no record, a stale one, no verdict).
     *
     * @param FileChunk|array<string, mixed> $chunk
     */
    public static function scopeOf(FileChunk|array $chunk): ?string
    {
        $scope = self::recordOf($chunk)['scope'] ?? null;

        return $scope === 'out' || $scope === 'in' ? $scope : null;
    }

    /**
     * The fingerprint of the application description a scope verdict was judged
     * against: a verdict is only as good as that description, so it is judged
     * again when the description changes.
     */
    public static function scopeRef(string $application): string
    {
        return self::hash((string) preg_replace('/\s+/u', ' ', trim($application)));
    }

    /**
     * The distinct top-level parts of a document, in reading order ([] for a
     * document that is one part).
     *
     * @param array<int, FileChunk|array<string, mixed>> $chunks The document's chunks.
     * @return list<string>
     */
    public static function parts(array $chunks): array
    {
        $depth = self::documentDepth($chunks);
        $parts = [];
        foreach ($chunks as $chunk) {
            if (self::isPreamble($chunk)) {
                continue;
            }
            $part = self::partOf(self::headingPathOf($chunk), $depth);
            if ($part !== null) {
                $parts[$part] = true;
            }
        }

        return array_keys($parts);
    }

    /**
     * Whether a scope verdict is still owed for this document: it is divided into
     * parallel parts and one of its records was not judged against the CURRENT
     * application description — a first build whose scope call failed, a record
     * written after the verdict, a description that changed since. With no
     * description at all nothing can be judged: only a scope left from an earlier
     * description is owed its removal.
     *
     * @param array<int, FileChunk|array<string, mixed>> $chunks The document's chunks.
     */
    public static function scopePending(array $chunks, string $application): bool
    {
        if (count(self::parts($chunks)) < 2) {
            return false;
        }

        $ref = self::scopeRef($application);
        $described = trim($application) !== '';
        $depth = self::documentDepth($chunks);
        foreach ($chunks as $chunk) {
            $record = self::isPreamble($chunk) ? null : self::recordOf($chunk);
            if ($record === null || self::partOf(self::headingPathOf($chunk), $depth) === null) {
                continue;
            }
            $judged = $record['scope_ref'] ?? null;
            if ($described ? $judged !== $ref : ($judged !== null || isset($record['scope']))) {
                return true;
            }
        }

        return false;
    }

    /** A model's reason as it is stored: one line, capped. */
    public static function why(string $reason): string
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        return mb_strlen($reason, 'UTF-8') > self::WHY_CHARS ? rtrim(mb_substr($reason, 0, self::WHY_CHARS, 'UTF-8')) . '…' : $reason;
    }

    /**
     * The top-level part of its document a chunk belongs to.
     *
     * @param array<int, mixed> $headingPath The chunk's heading path (metadata `heading_path`).
     * @param int $documentDepth The document's depth ({@see documentDepth()}).
     * @return string|null The first PART_LEVELS levels joined by PART_SEPARATOR
     *         (fewer when the chunk sits higher in the document); null when the
     *         document is one part, or the chunk is under no heading at all.
     */
    public static function partOf(array $headingPath, int $documentDepth): ?string
    {
        if ($documentDepth < self::MIN_DEPTH_FOR_PARTS) {
            return null;
        }

        $levels = array_slice(self::cleanPath($headingPath), 0, self::PART_LEVELS);

        return $levels === [] ? null : implode(self::PART_SEPARATOR, $levels);
    }

    /**
     * The document's depth: the longest heading path among its CONTENT chunks
     * (0 for a document without headings). Preamble chunks are left out, as in
     * the measurement the part rule comes from.
     *
     * @param array<int, FileChunk|array<string, mixed>> $chunks The document's chunks.
     */
    public static function documentDepth(array $chunks): int
    {
        $depth = 0;
        foreach ($chunks as $chunk) {
            if (self::isPreamble($chunk)) {
                continue;
            }
            $depth = max($depth, count(self::headingPathOf($chunk)));
        }

        return $depth;
    }

    /**
     * The OPENING of the provision a chunk continues, or null when the chunk
     * starts its own provision.
     *
     * The chunker splits an article (or a section) longer than a chunk within
     * itself and numbers the pieces (metadata `part` / `part_count`). The pieces
     * after the first hold sub-clauses whose subject stands in the first one
     * ("the administrators must:" … "31.10 keep the insurance in force"): read
     * alone, a later piece no longer says who its clauses bind. Measured on a
     * scanned by-law: the gists of such pieces named the wrong persons, and
     * one of them drew a wrong rule into an answer.
     *
     * Purely structural: the piece numbered 1 of the same article under the same
     * heading path is found by walking back over consecutive pieces; its opening
     * is its first paragraph, cut before the first line that opens a numbered or
     * lettered sub-clause and after the first line that announces a list (ends
     * with a colon), at most LEAD_IN_CHARS characters.
     *
     * @param array<int, FileChunk> $chunks The document's chunks, in reading order.
     * @param int $position The chunk's key in $chunks.
     */
    public static function leadInOf(array $chunks, int $position): ?string
    {
        $chunk = $chunks[$position] ?? null;
        if (!$chunk instanceof FileChunk) {
            return null;
        }

        $part = (int) ($chunk->metadata['part'] ?? 0);
        if ($part < 2) {
            return null;
        }

        $articles = $chunk->metadata['articles'] ?? [];
        $path = self::headingPathOf($chunk);
        $first = null;
        for ($step = 1; $step < $part; $step++) {
            $earlier = $chunks[$position - $step] ?? null;
            if (!$earlier instanceof FileChunk
                || (int) ($earlier->metadata['part'] ?? 0) !== $part - $step
                || ($earlier->metadata['articles'] ?? []) !== $articles
                || self::headingPathOf($earlier) !== $path) {
                return null; // not the pieces of one provision in a row
            }
            $first = $earlier;
        }
        if ($first === null) {
            return null;
        }

        $opening = [];
        foreach (explode("\n", trim($first->content)) as $n => $line) {
            if ($n > 0 && (trim($line) === '' || preg_match(self::SUB_CLAUSE_LINE, $line) === 1)) {
                break;
            }
            $opening[] = rtrim($line);
            if (str_ends_with(rtrim($line), ':')) {
                break; // the line that announces a list: what follows is the list
            }
        }
        $opening = trim(implode("\n", $opening));
        if ($opening === '') {
            return null;
        }

        if (mb_strlen($opening, 'UTF-8') > self::LEAD_IN_CHARS) {
            $cut = mb_substr($opening, 0, self::LEAD_IN_CHARS, 'UTF-8');
            $space = max((int) mb_strrpos($cut, ' ', 0, 'UTF-8'), (int) mb_strrpos($cut, "\n", 0, 'UTF-8'));
            $opening = rtrim($space > 0 ? mb_substr($cut, 0, $space, 'UTF-8') : $cut) . '…';
        }

        return $opening;
    }

    /**
     * The OTHER pieces of the split provision a chunk is a piece of (metadata
     * `part` / `part_count`, same article and heading path, at the places their
     * numbers give them), as keys of $chunks. Empty for a chunk that holds a whole
     * provision.
     *
     * @param array<int, FileChunk> $chunks The document's chunks, in reading order.
     * @param int $position The chunk's key in $chunks.
     * @return list<int>
     */
    public static function siblingPiecesOf(array $chunks, int $position): array
    {
        $chunk = $chunks[$position] ?? null;
        if (!$chunk instanceof FileChunk) {
            return [];
        }

        $part = (int) ($chunk->metadata['part'] ?? 0);
        $count = (int) ($chunk->metadata['part_count'] ?? 0);
        if ($part < 1 || $count < 2) {
            return [];
        }

        $articles = $chunk->metadata['articles'] ?? [];
        $path = self::headingPathOf($chunk);
        $pieces = [];
        for ($n = 1; $n <= $count; $n++) {
            $at = $position + $n - $part;
            $other = $n !== $part ? ($chunks[$at] ?? null) : null;
            if ($other instanceof FileChunk
                && (int) ($other->metadata['part'] ?? 0) === $n
                && (int) ($other->metadata['part_count'] ?? 0) === $count
                && ($other->metadata['articles'] ?? []) === $articles
                && self::headingPathOf($other) === $path) {
                $pieces[] = $at;
            }
        }

        return $pieces;
    }

    /**
     * The chunk's heading path as a clean list of non-empty titles.
     *
     * @param FileChunk|array<string, mixed> $chunk
     * @return list<string>
     */
    public static function headingPathOf(FileChunk|array $chunk): array
    {
        [, $metadata, $raw] = self::read($chunk);

        $path = $metadata['heading_path'] ?? ($raw['heading_path'] ?? []);

        return is_array($path) ? self::cleanPath($path) : [];
    }

    /**
     * Whether the chunk is title / heading-only text (never mapped).
     *
     * @param FileChunk|array<string, mixed> $chunk
     */
    public static function isPreamble(FileChunk|array $chunk): bool
    {
        [, $metadata, $raw] = self::read($chunk);

        return !empty($metadata['is_preamble'] ?? ($raw['is_preamble'] ?? false));
    }

    /**
     * @param FileChunk|array<string, mixed> $chunk
     * @return array{0: ?string, 1: array<string, mixed>, 2: array<string, mixed>} content, metadata, raw array
     */
    private static function read(FileChunk|array $chunk): array
    {
        if ($chunk instanceof FileChunk) {
            return [$chunk->content, $chunk->metadata, []];
        }

        if (($chunk['chunk'] ?? null) instanceof FileChunk) {
            return [$chunk['chunk']->content, $chunk['chunk']->metadata, []];
        }

        return [
            is_string($chunk['content'] ?? null) ? $chunk['content'] : null,
            is_array($chunk['metadata'] ?? null) ? $chunk['metadata'] : [],
            $chunk,
        ];
    }

    /**
     * @param array<int, mixed> $path
     * @return list<string>
     */
    private static function cleanPath(array $path): array
    {
        $levels = [];
        foreach ($path as $level) {
            if (!is_scalar($level)) {
                continue;
            }
            $level = trim((string) $level);
            if ($level !== '') {
                $levels[] = $level;
            }
        }

        return $levels;
    }
}
