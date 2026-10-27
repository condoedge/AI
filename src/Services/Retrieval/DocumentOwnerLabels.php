<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Response\HostText;
use Condoedge\Ai\Support\AliasNormalizer;
use Condoedge\Ai\Support\FileLink;

/**
 * DocumentOwnerLabels — WHOSE DOCUMENT a passage is, in words.
 *
 * An uploaded file is linked to a record by a key (`order:873`,
 * {@see FileLink}). A key is never shown: each one is turned into
 *
 *   kind  the entity's display name (`metadata.display_name`, said in the
 *         answer's language when the host can — else its label as words);
 *   name  the record's own name: its `metadata.name_properties` joined in the
 *         declared order, else its `name` property — never a sensitive column.
 *
 * The name is read BY ID through the pipeline's scoped executor, with the turn's
 * own options (the acting user: a record of another tenant yields no row),
 * read-only, a skip_security key stripped, the id a bound parameter — one lookup
 * per distinct key, at most MAX_KEYS per call. A key that cannot be named (not
 * visible to the user, no name, an unconfigured kind, a failed lookup) gets NO
 * label; the caller then says nothing rather than print an id.
 */
final class DocumentOwnerLabels
{
    /** Most keys named in one call (a turn shows a handful of passages). */
    public const MAX_KEYS = 8;

    public const ID_PARAMETER = 'ai_record_id';

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private readonly EntityLabelIndex $index;

    public function __construct(private readonly QueryExecutorInterface $executor, ?EntityLabelIndex $index = null)
    {
        $this->index = $index ?? EntityLabelIndex::shared();
    }

    /**
     * @param list<string> $keys
     * @param array<string, mixed> $execOptions the turn's execution options
     * @param string|null $locale the answer's language (null: as declared)
     * @return array<string, array{kind: string, name: string}> key => its words; a key that cannot be named is absent
     */
    public function labels(array $keys, array $execOptions = [], ?string $locale = null): array
    {
        unset($execOptions['skip_security'], $execOptions['limit']);
        $execOptions['read_only'] = true;

        $labels = [];
        foreach (array_slice(array_values(array_unique(array_filter($keys, 'is_string'))), 0, self::MAX_KEYS) as $key) {
            $label = $this->labelOf($key, $execOptions, $locale);
            if ($label !== null) {
                $labels[$key] = $label;
            }
        }

        return $labels;
    }

    /** @return array{kind: string, name: string}|null */
    private function labelOf(string $key, array $execOptions, ?string $locale): ?array
    {
        $kind = FileLink::kindOf($key);
        $id = FileLink::idOf($key);
        [$entity, $config] = $this->entityOfKind($kind);
        if ($entity === null || $id === '') {
            return null;
        }
        $nameProperties = $this->nameProperties($config);
        if ($nameProperties === []) {
            return null;
        }

        $returns = [];
        foreach ($nameProperties as $nth => $property) {
            $returns[] = "n.{$property} AS n{$nth}";
        }
        $query = "MATCH (n:`{$entity}`) WHERE n.id = \$" . self::ID_PARAMETER . ' RETURN ' . implode(', ', $returns) . ' LIMIT 1';

        try {
            $execution = $this->executor->execute($query, [self::ID_PARAMETER => ctype_digit($id) ? (int) $id : $id], $execOptions);
        } catch (\Throwable $e) {
            return null;
        }
        $row = $execution->success && empty($execution->errors) && ($execution->metadata['safe_stop'] ?? false) !== true
            ? ($execution->data[0] ?? null)
            : null;
        if (!is_array($row)) {
            return null;
        }

        $parts = [];
        foreach (array_keys($nameProperties) as $nth) {
            $value = $row["n{$nth}"] ?? null;
            if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
                $parts[] = trim((string) $value);
            }
        }
        if ($parts === []) {
            return null;
        }

        $display = $config['metadata']['display_name'] ?? $config['display_name'] ?? null;

        return [
            'kind' => HostText::inLanguage($display, $locale)
                ?? (is_string($display) && trim($display) !== '' ? trim($display) : AliasNormalizer::phrase($entity)),
            'name' => implode(' ', $parts),
        ];
    }

    /** @return array{0: ?string, 1: array<string, mixed>} the entity label a key's kind names, and its config */
    private function entityOfKind(string $kind): array
    {
        if ($kind === '') {
            return [null, []];
        }
        try {
            $entities = function_exists('config') ? config('entities', []) : [];
        } catch (\Throwable $e) {
            $entities = [];
        }
        foreach (is_array($entities) ? $entities : [] as $config) {
            $label = is_array($config) ? ($config['graph']['label'] ?? null) : null;
            if (is_string($label) && strtolower($label) === $kind && preg_match(self::IDENTIFIER, $label) === 1) {
                return [$label, $config];
            }
        }

        return [null, []];
    }

    /**
     * The properties that name a record (the rule of RecordParentEnricher): its
     * declared `metadata.name_properties`, else `name`; [] when it has no name or
     * one of them is a sensitive column.
     *
     * @return list<string>
     */
    private function nameProperties(array $config): array
    {
        $properties = array_values(array_filter((array) ($config['graph']['properties'] ?? []), 'is_string'));
        $declared = array_values(array_filter(
            (array) ($config['metadata']['name_properties'] ?? []),
            static fn ($property): bool => is_string($property) && $property !== ''
        ));
        $names = $declared !== [] ? $declared : (in_array('name', $properties, true) ? ['name'] : []);

        $sensitive = array_filter((array) ($config['security']['sensible_columns'] ?? []), 'is_string');
        foreach ($names as $property) {
            if (preg_match(self::IDENTIFIER, $property) !== 1
                || in_array($property, $sensitive, true)
                || ($properties !== [] && !in_array($property, $properties, true))) {
                return [];
            }
        }

        return $names;
    }
}
