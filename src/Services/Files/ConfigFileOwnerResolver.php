<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Files;

use Condoedge\Ai\Contracts\FileOwnerResolverInterface;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Support\FileLink;

/**
 * The default owner of an uploaded file, with no host code: read from what the
 * host's entity configuration already declares about its File entity
 * ({@see RecordAncestry}).
 *
 *   owner     = the file's morph parent (`unit:309`); else the first record it
 *               declares it belongs to (`union:14`); else null — a file of the
 *               tenant as a whole;
 *   ancestors = the records that own that record, most specific first: the
 *               parent's own belongs-to records (one read of the parent row),
 *               then the file's own. A file owned through its own foreign key
 *               costs no read at all.
 *
 * WHICH entity is "the file": the configured file model
 * (`ai.file_context.file_model`, else the FileModel facade's class), else the
 * object's own class.
 */
final class ConfigFileOwnerResolver implements FileOwnerResolverInterface
{
    private readonly RecordAncestry $ancestry;

    public function __construct(?RecordAncestry $ancestry = null, private readonly ?EntityLabelIndex $index = null)
    {
        $this->ancestry = $ancestry ?? new RecordAncestry();
    }

    public function ownerOf(object $file): array
    {
        $attributes = method_exists($file, 'getAttributes') ? (array) $file->getAttributes() : get_object_vars($file);
        $label = $this->fileLabel($file);
        if ($label === null) {
            return ['owner' => null, 'ancestors' => []];
        }

        $own = $this->ancestry->belongsTo($label, $attributes);
        $parent = $this->ancestry->morphParent($label, $attributes);

        if ($parent === null) {
            $own = $this->ancestry->mostSpecificFirst($own);

            return ['owner' => $own[0] ?? null, 'ancestors' => array_values(array_slice($own, 1))];
        }

        $parentLabel = $this->ancestry->labelOfKey($parent);
        $id = FileLink::idOf($parent);
        $above = $parentLabel !== null ? $this->ancestry->ancestorsOf($parentLabel, ctype_digit($id) ? (int) $id : $id) : [];

        return [
            'owner' => $parent,
            'ancestors' => $this->ancestry->mostSpecificFirst(array_values(array_filter(
                array_unique(array_merge($above, $own)),
                static fn (string $key): bool => $key !== $parent
            ))),
        ];
    }

    private function fileLabel(object $file): ?string
    {
        $index = $this->index ?? EntityLabelIndex::shared();

        foreach ($this->fileClasses($file) as $class) {
            $label = $index->labelForClass($class);
            if ($label !== null) {
                return $label;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function fileClasses(object $file): array
    {
        $classes = [];
        try {
            $configured = function_exists('config') ? config('ai.file_context.file_model') : null;
            if (is_string($configured) && $configured !== '') {
                $classes[] = $configured;
            }
        } catch (\Throwable $e) {
            // No container.
        }
        try {
            if (class_exists(\Condoedge\Utils\Facades\FileModel::class)) {
                $classes[] = \Condoedge\Utils\Facades\FileModel::getClass();
            }
        } catch (\Throwable $e) {
            // The facade is not available (isolated package tests).
        }
        if (!$file instanceof \stdClass) {
            $classes[] = $file::class;
        }

        return array_values(array_filter($classes, 'is_string'));
    }
}
