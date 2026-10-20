<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * WHOSE DOCUMENT a passage is — the one rendering of it, written by code and
 * shared by everything that tells a model which document a rule comes from: the
 * passage headers (ResponseSections\FileContextSection), the document headers of
 * the map a reader is shown (Services\Retrieval\DocumentMapReader) and the rule
 * lines of the question analysis (ResponseSections\QuestionAnalysisSection).
 * One wording everywhere: a model told "GENERAL document" in one place and
 * something else in another has two things to reconcile.
 *
 *  - a PROJECT file (`source: physical`) is a general document: it applies to
 *    everyone and is no record's own;
 *  - an UPLOADED file is the document of the record it is filed on, by kind and
 *    NAME (`scope_label`, resolved by the retrieval layer from the record's key —
 *    an id is never printed), with the record that owns it; one filed on no
 *    record is the organisation's; one whose record could not be named says only
 *    that it is one record's.
 *
 * A record's name is host data typed by a user: it is one sanitised line
 * ({@see UntrustedLabel}).
 */
final class DocumentScopeLabel
{
    /** The key of every general (project) document. */
    public const GENERAL = 'general';

    /** The key of an uploaded document filed on no record. */
    public const ORGANISATION = 'organisation';

    /**
     * @param array<string, mixed> $file A file reference (a passage).
     * @return array{key: string, text: string}|null the scope of the passage's
     *         document: a key that tells scopes apart, and its words; null for a
     *         reference that says nothing about its owner (a legacy passage).
     */
    public static function of(array $file): ?array
    {
        if (($file['source'] ?? null) === 'physical') {
            return ['key' => self::GENERAL, 'text' => 'GENERAL document, applies to everyone'];
        }
        if (!array_key_exists('owner', $file)) {
            return null;
        }
        $owner = is_string($file['owner']) && $file['owner'] !== '' ? $file['owner'] : null;
        if ($owner === null) {
            return ['key' => self::ORGANISATION, 'text' => 'document of the whole organisation (filed on no record)'];
        }

        $label = is_array($file['scope_label'] ?? null) ? $file['scope_label'] : [];
        $named = static function (mixed $kind, mixed $name): string {
            $kind = UntrustedLabel::of(is_string($kind) ? $kind : '', 60);
            $name = UntrustedLabel::of(is_string($name) ? $name : '', 80);

            return $kind !== '' && $name !== '' ? "{$kind} \"{$name}\"" : '';
        };
        $record = $named($label['kind'] ?? null, $label['name'] ?? null);
        if ($record === '') {
            return ['key' => $owner, 'text' => 'document of one specific record'];
        }
        $root = $named($label['root_kind'] ?? null, $label['root_name'] ?? null);

        // The same text filed on other records too: one passage, every owner named.
        $also = array_values(array_filter(array_map(
            static fn (mixed $other): string => is_array($other) ? $named($other['kind'] ?? null, $other['name'] ?? null) : '',
            array_slice((array) ($label['also'] ?? []), 0, 5)
        )));

        return ['key' => $owner, 'text' => "document of {$record}" . ($root !== '' ? " ({$root})" : '')
            . ($also !== [] ? '; the same text is also filed on ' . implode(', ', $also) : '')];
    }

    /**
     * Whether the document is a GENERAL one (a project file) — as opposed to a
     * document that belongs to a record or to the organisation.
     *
     * @param array{key: string, text: string}|null $scope {@see self::of()}
     */
    public static function isGeneral(?array $scope): bool
    {
        return ($scope['key'] ?? null) === self::GENERAL;
    }

    /**
     * The distinct scopes among some passages (scope key => its words), in the
     * order met. More than one: the turn's documents are of different scopes.
     *
     * @param array<int, mixed> $files
     * @return array<string, string>
     */
    public static function among(array $files): array
    {
        $scopes = [];
        foreach ($files as $file) {
            $scope = is_array($file) ? self::of($file) : null;
            if ($scope !== null) {
                $scopes[$scope['key']] ??= $scope['text'];
            }
        }

        return $scopes;
    }
}
