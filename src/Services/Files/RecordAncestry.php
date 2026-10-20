<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Files;

use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\PolymorphicTargetResolver;
use Condoedge\Ai\Support\FileLink;
use Illuminate\Database\Eloquent\Model;

/**
 * RecordAncestry — which records OWN a record, read from the host's entity
 * configuration only (structure, never a vocabulary):
 *
 *  - a `graph.relationships` entry that points outwards and carries a
 *    `foreign_key` which is one of the entity's OWN `graph.properties` is a
 *    "belongs to" (the rule of {@see \Condoedge\Ai\Services\Query\RecordParentEnricher});
 *    a pivot / morph / inverse / incoming declaration is not;
 *  - its target is a configured entity, not the entity itself and not an
 *    IDENTITY label (`ai.response_generation.identity_labels`: the current user /
 *    team are the scope every visible record shares, never an owner);
 *  - the polymorphic one (`target_label: _polymorphic`) names the record's morph
 *    parent: its concrete label comes from the morph-type column through the
 *    host's morph map ({@see PolymorphicTargetResolver}).
 *
 * A record's ancestors are ONE hop: the belongs-to keys of its own row (one read
 * of that row by id, memoized). Keys are {@see FileLink::key()}.
 */
final class RecordAncestry
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** Most ancestor lookups kept in memory (a backfill walks thousands of files). */
    private const MEMO_SIZE = 2000;

    private readonly EntityLabelIndex $index;

    private readonly PolymorphicTargetResolver $morphs;

    /** @var array<string, list<string>> */
    private array $memo = [];

    /**
     * @param \Closure(string, int|string): ?array|null $loader Reads one record's
     *        attributes by entity label and id (null: unknown). Default: the
     *        entity's model, unscoped — only its foreign keys are read, nothing
     *        of it is shown.
     */
    public function __construct(
        private readonly ?\Closure $loader = null,
        ?EntityLabelIndex $index = null,
        ?PolymorphicTargetResolver $morphs = null,
    ) {
        $this->index = $index ?? EntityLabelIndex::shared();
        $this->morphs = $morphs ?? new PolymorphicTargetResolver();
    }

    /**
     * The key of the record's morph parent, or null (no polymorphic belongs-to
     * declared, nothing stored, or a type that names no configured entity).
     *
     * @param array<string, mixed> $attributes The record's own attributes.
     */
    public function morphParent(string $label, array $attributes): ?string
    {
        foreach ($this->outgoing($label) as $relationship) {
            if ($relationship['target'] !== RelationshipConfig::POLYMORPHIC_TARGET) {
                continue;
            }
            $id = $attributes[$relationship['key']] ?? null;
            $target = $this->morphs->resolveLabel($attributes[$relationship['type_column']] ?? null);
            if ($target === null || !$this->isKey($id) || !$this->isOwnerLabel($target, $label)) {
                continue;
            }

            return FileLink::key($target, $id);
        }

        return null;
    }

    /**
     * The keys of the records the record declares it belongs to, in declaration
     * order (the morph parent is not one of them: {@see self::morphParent()}).
     *
     * @param array<string, mixed> $attributes The record's own attributes.
     * @return list<string>
     */
    public function belongsTo(string $label, array $attributes): array
    {
        $keys = [];
        foreach ($this->outgoing($label) as $relationship) {
            $id = $attributes[$relationship['key']] ?? null;
            if ($relationship['target'] === RelationshipConfig::POLYMORPHIC_TARGET
                || !$this->isKey($id)
                || !$this->isOwnerLabel($relationship['target'], $label)) {
                continue;
            }
            $keys[FileLink::key($relationship['target'], $id)] = true;
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * The keys of the records that own the record `$label` #$id, most specific
     * first: its morph parent, then its declared belongs-to records. [] when the
     * record is unknown or owned by nothing.
     *
     * @return list<string>
     */
    public function ancestorsOf(string $label, int|string $id): array
    {
        $memoKey = $label . ':' . $id;
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $attributes = $this->load($label, $id);
        $keys = [];
        if ($attributes !== null) {
            $parent = $this->morphParent($label, $attributes);
            $keys = array_values(array_unique(array_merge($parent !== null ? [$parent] : [], $this->belongsTo($label, $attributes))));
        }

        if (count($this->memo) >= self::MEMO_SIZE) {
            $this->memo = [];
        }

        return $this->memo[$memoKey] = $keys;
    }

    /**
     * The columns of the entity's own row its owners are read from: the foreign
     * keys of its belongs-to relationships (and the type column of a morph one).
     * A change of one of them moves the record to another owner.
     *
     * @return list<string>
     */
    public function linkColumns(string $label): array
    {
        $columns = [];
        foreach ($this->outgoing($label) as $relationship) {
            if ($relationship['target'] === RelationshipConfig::POLYMORPHIC_TARGET) {
                $columns[$relationship['key']] = true;
                $columns[$relationship['type_column']] = true;
            } elseif ($this->isOwnerLabel($relationship['target'], $label)) {
                $columns[$relationship['key']] = true;
            }
        }

        return array_map('strval', array_keys($columns));
    }

    /**
     * The keys ordered MOST SPECIFIC FIRST: a record that is itself owned by
     * other records of the list comes before them (a wing before its building),
     * whatever order the host declared the relationships in. One read per key
     * (memoized) — and none at all for a list of fewer than two.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public function mostSpecificFirst(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        if (count($keys) < 2) {
            return $keys;
        }

        $depth = [];
        foreach ($keys as $position => $key) {
            $label = $this->labelOfKey($key);
            $id = FileLink::idOf($key);
            $above = $label === null ? [] : $this->ancestorsOf($label, ctype_digit($id) ? (int) $id : $id);
            $depth[$key] = [-count(array_intersect($above, $keys)), $position];
        }
        usort($keys, static fn (string $a, string $b): int => $depth[$a] <=> $depth[$b]);

        return $keys;
    }

    /** The entity label a key's kind names (`Unit` of `unit:309`); null when none does. */
    public function labelOfKey(string $key): ?string
    {
        $kind = FileLink::kindOf($key);
        if ($kind === '') {
            return null;
        }
        foreach ($this->entities() as $configKey => $config) {
            $label = is_array($config) ? ($config['graph']['label'] ?? null) : null;
            $label = is_string($label) && $label !== '' ? $label : (is_string($configKey) ? class_basename($configKey) : '');
            if ($label !== '' && strtolower($label) === $kind) {
                return $label;
            }
        }

        return null;
    }

    /**
     * The outgoing foreign-key relationships the entity declares on its own row.
     *
     * @return list<array{target: string, key: string, type_column: string}>
     */
    private function outgoing(string $label): array
    {
        $config = $this->index->configForLabel($label);
        if ($config === null) {
            return [];
        }
        $own = array_values(array_filter((array) ($config['graph']['properties'] ?? []), 'is_string'));

        $relationships = [];
        foreach ((array) ($config['graph']['relationships'] ?? []) as $relationship) {
            if (!is_array($relationship)
                || ($relationship['inverse'] ?? false) === true
                || isset($relationship['pivot'])
                || isset($relationship['morph'])
                || strtolower((string) ($relationship['direction'] ?? 'outgoing')) !== 'outgoing') {
                continue;
            }
            $target = $relationship['target_label'] ?? $relationship['targetLabel'] ?? null;
            $key = $relationship['foreign_key'] ?? $relationship['foreignKey'] ?? null;
            if (!is_string($target) || !is_string($key) || preg_match(self::IDENTIFIER, $key) !== 1
                || ($own !== [] && !in_array($key, $own, true))) {
                continue;
            }
            $relationships[] = [
                'target' => $target,
                'key' => $key,
                'type_column' => str_ends_with($key, '_id') ? substr($key, 0, -3) . '_type' : $key . '_type',
            ];
        }

        return $relationships;
    }

    /** A configured entity that can own a record of $of: not itself, not an identity label. */
    private function isOwnerLabel(string $target, string $of): bool
    {
        return preg_match(self::IDENTIFIER, $target) === 1
            && $target !== $of
            && !in_array($target, $this->identityLabels(), true)
            && $this->index->configForLabel($target) !== null;
    }

    private function isKey(mixed $id): bool
    {
        return (is_int($id) || (is_string($id) && $id !== '')) && (string) $id !== '0';
    }

    /** @return array<string, mixed>|null */
    private function load(string $label, int|string $id): ?array
    {
        try {
            $row = $this->loader !== null ? ($this->loader)($label, $id) : $this->loadFromModel($label, $id);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function loadFromModel(string $label, int|string $id): ?array
    {
        foreach ($this->entities() as $class => $config) {
            if (!is_string($class) || !is_array($config) || ($config['graph']['label'] ?? class_basename($class)) !== $label
                || !class_exists($class) || !is_subclass_of($class, Model::class)) {
                continue;
            }
            /** @var Model $model */
            $model = new $class();
            $row = $model->newQueryWithoutScopes()->toBase()->where($model->getKeyName(), $id)->first();

            return $row === null ? null : (array) $row;
        }

        return null;
    }

    /** @return array<int|string, mixed> */
    private function entities(): array
    {
        try {
            $entities = function_exists('config') ? config('entities', []) : [];
        } catch (\Throwable $e) {
            $entities = [];
        }

        return is_array($entities) ? $entities : [];
    }

    /** @return list<string> */
    private function identityLabels(): array
    {
        try {
            $labels = function_exists('config') ? config('ai.response_generation.identity_labels', ['user' => 'User', 'team' => 'Team']) : [];
        } catch (\Throwable $e) {
            $labels = [];
        }

        return array_values(array_filter((array) $labels, static fn ($label): bool => is_string($label) && $label !== ''));
    }
}
