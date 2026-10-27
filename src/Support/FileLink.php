<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

use Condoedge\Ai\DTOs\FileChunk;

/**
 * FileLink — what ties a chunk of an UPLOADED file to the record it is filed on.
 *
 * Three top-level payload keys, written with the chunk (and by `ai:files:link`
 * for chunks stored before they existed), beside — never inside — `metadata`:
 *
 *   owner      `<entity label, lower case>:<id>` of the record the file is filed
 *              on (`project:309`); null for a file that belongs to no record;
 *   ancestors  the records that own that record, most specific first
 *              (`["team:14"]`);
 *   dedupe     sha1 of the chunk's normalised text + its owner: two chunks with
 *              the same key are ONE passage (the same text filed twice on the
 *              same record). The owner is part of the key, so the same text
 *              under another owner stays another passage.
 *
 * PROJECT files (the physical collection) carry none of them: they are global
 * by construction. The tenant is never a key — access is the ACL's business.
 *
 * In memory a chunk carries its link as `metadata['link']`
 * ({@see \Condoedge\Ai\Services\QdrantChunkStore} lifts it to the three payload
 * keys on write and folds them back on read), the way a document map travels as
 * `metadata['map']`.
 */
final class FileLink
{
    public const OWNER = 'owner';
    public const ANCESTORS = 'ancestors';
    public const DEDUPE = 'dedupe';

    /** Where a chunk carries its link in memory (FileChunk::$metadata). */
    public const METADATA_KEY = 'link';

    /** The key of a record: `<label, lower case>:<id>`. */
    public static function key(string $label, int|string $id): string
    {
        return strtolower($label) . ':' . $id;
    }

    /** The kind part of a key (`project` of `project:309`); '' when it is not a key. */
    public static function kindOf(string $key): string
    {
        $at = strpos($key, ':');

        return $at === false || $at === 0 ? '' : substr($key, 0, $at);
    }

    /** The id part of a key (`309` of `project:309`); '' when it is not a key. */
    public static function idOf(string $key): string
    {
        $at = strpos($key, ':');

        return $at === false ? '' : substr($key, $at + 1);
    }

    /** Whitespace-normalised text: what "the same text" means for the dedupe key. */
    public static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function dedupeKey(string $text, ?string $owner): string
    {
        return sha1(self::normalise($text) . "\u{1F}" . ($owner ?? ''));
    }

    /**
     * The three payload keys of one chunk.
     *
     * @param array{owner?: ?string, ancestors?: array<int, mixed>} $owner
     * @return array{owner: ?string, ancestors: list<string>, dedupe: string}
     */
    public static function payload(array $owner, string $text): array
    {
        $key = is_string($owner['owner'] ?? null) && $owner['owner'] !== '' ? $owner['owner'] : null;
        $ancestors = array_values(array_unique(array_filter(
            (array) ($owner['ancestors'] ?? []),
            static fn (mixed $ancestor): bool => is_string($ancestor) && $ancestor !== '' && $ancestor !== $key
        )));

        return [
            self::OWNER => $key,
            self::ANCESTORS => $ancestors,
            self::DEDUPE => self::dedupeKey($text, $key),
        ];
    }

    /**
     * The link stored in a point payload; null for a point that was never linked
     * (no `dedupe` key: a project file, or an upload stored before the keys).
     *
     * @return array{owner: ?string, ancestors: list<string>, dedupe: string}|null
     */
    public static function fromPayload(array $payload): ?array
    {
        $dedupe = $payload[self::DEDUPE] ?? null;
        if (!is_string($dedupe) || $dedupe === '') {
            return null;
        }
        $owner = $payload[self::OWNER] ?? null;

        return [
            self::OWNER => is_string($owner) && $owner !== '' ? $owner : null,
            self::ANCESTORS => array_values(array_filter((array) ($payload[self::ANCESTORS] ?? []), 'is_string')),
            self::DEDUPE => $dedupe,
        ];
    }

    /**
     * The link a chunk carries in memory, or null.
     *
     * @return array{owner: ?string, ancestors: list<string>, dedupe: string}|null
     */
    public static function ofChunk(mixed $chunk): ?array
    {
        $link = $chunk instanceof FileChunk ? ($chunk->metadata[self::METADATA_KEY] ?? null) : null;

        return is_array($link) ? self::fromPayload($link) : null;
    }

    /**
     * The least specific record of a link — its last ancestor, else its owner
     * (for a file filed on a project: the project's team). Null without an owner.
     *
     * @param array{owner?: ?string, ancestors?: array<int, mixed>}|null $link
     */
    public static function root(?array $link): ?string
    {
        if ($link === null) {
            return null;
        }
        $ancestors = array_values(array_filter((array) ($link[self::ANCESTORS] ?? []), 'is_string'));
        $owner = $link[self::OWNER] ?? null;

        return $ancestors !== [] ? $ancestors[count($ancestors) - 1] : (is_string($owner) && $owner !== '' ? $owner : null);
    }
}
