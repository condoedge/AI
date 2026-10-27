<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * EntityIdIndex — which entity ids the turn's result rows actually carry, per
 * entity label (Phase 8-B link lint).
 *
 * Measured live: `entity://Project/3/view` where 3 was the TEAM id, and
 * `entity://Meeting/1903` where 1903 was a task id — the model attaches any id it
 * saw to any label it knows. A link is only honest when its id came from a row of
 * THAT label. Attribution is purely structural:
 *
 *   - a row's own label (`_label`, or the enricher's `entity_type`) owns the row's
 *     configured id field(s) (`ai.entity_id_fields`);
 *   - enricher `action_links` (`entity://Label/id/…`) attribute their id directly;
 *   - the cypher's projections `v.id AS alias` / map entries `{key: v.id}` with
 *     `(v:Label)` attribute that column to Label;
 *   - a `<label>_id` / `<label>Id` column belongs to the label whose
 *     alphanumeric-lowercased name equals the prefix (identifier normalization,
 *     not vocabulary);
 *   - an id column nothing attributes stays UNATTRIBUTED and is accepted for any
 *     label (the index never rejects what it cannot disprove);
 *   - withIdentity() seeds the current user / team ids (action defaults).
 *
 * Labels are matched case- and punctuation-insensitively ("OrderLine" ≡
 * "order_line" ≡ "orderline"). Host entity keys and graph labels coincide
 * (measured on a 73-entity host: every config basename equals its graph.label).
 */
final class EntityIdIndex
{
    private const ANY = '*';

    /** @var array<string, array<string, true>> bucket => id => true */
    private array $ids = [];

    /** @var array<string, string> result column => label (from the cypher) */
    private array $columnLabels = [];

    /** @var list<string> */
    private array $idFields;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(array $rows, ?string $cypher = null, ?array $idFields = null)
    {
        $this->idFields = array_values(array_filter(
            $idFields ?? (self::config('ai.entity_id_fields') ?? ['id', '_id', 'neo4j_id']),
            'is_string'
        ));
        $this->columnLabels = $this->columnLabelsFromCypher((string) $cypher);
        $this->walk($rows);
    }

    /** The turn's index: current rows, plus the previous turn's sample (follow-up links). */
    public static function forContext(PipelineContext $context): self
    {
        $rows = is_array($context->executionResult->data ?? null) ? $context->executionResult->data : [];
        $sample = $context->conversationContext['last_result_sample'] ?? [];
        if (is_array($sample)) {
            $rows = [...$rows, ...array_values($sample)];
        }

        return new self($rows, $context->queryResult->cypher ?? null);
    }

    /**
     * Seed the turn's IDENTITY ids (Phase 8-B I3): the current user and the
     * user's team(s) are real ids the app hands to actions (team_settings with
     * currentTeamId, create-form defaults) even when no row carries them.
     * Labels come from `ai.response_generation.identity_labels` (default User / Team).
     *
     * @param list<string|int> $teamIds
     */
    public function withIdentity(string|int|null $userId, array $teamIds = []): self
    {
        $labels = (array) (self::config('ai.response_generation.identity_labels') ?? []);
        if ($userId !== null && (string) $userId !== '') {
            $this->add((string) ($labels['user'] ?? 'User'), (string) $userId);
        }
        foreach ($teamIds as $teamId) {
            if (is_scalar($teamId) && (string) $teamId !== '') {
                $this->add((string) ($labels['team'] ?? 'Team'), (string) $teamId);
            }
        }

        return $this;
    }

    /** Whether $id is an id this turn carries for $label (case-insensitive), or an unattributed id. */
    public function has(string $label, string $id): bool
    {
        $id = trim($id);

        return isset($this->ids[self::normalize($label)][$id]) || isset($this->ids[self::ANY][$id]);
    }

    /** Whether the index holds ids attributed to $label (case/punctuation-insensitive). */
    public function holdsLabel(string $label): bool
    {
        $key = self::normalize($label);

        return $key !== '' && !empty($this->ids[$key]);
    }

    /** Whether $id appears anywhere in the rows as an id (any label). */
    public function hasAny(string $id): bool
    {
        $id = trim($id);
        foreach ($this->ids as $bucket) {
            if (isset($bucket[$id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ids attributable to $label (its own — rows, `<label>_id` columns,
     * identity — and the unattributed ones).
     *
     * @return list<string>
     */
    public function idsFor(string $label): array
    {
        $ids = array_keys(($this->ids[self::normalize($label)] ?? []) + ($this->ids[self::ANY] ?? []));

        return array_map('strval', $ids);
    }

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    // =========================================================================

    private function walk(array $rows): void
    {
        foreach ($rows as $row) {
            if (is_array($row)) {
                $this->indexRow($row);
            }
        }
    }

    private function indexRow(array $row): void
    {
        $rowLabel = null;
        foreach (['_label', 'entity_type'] as $key) {
            if (is_string($row[$key] ?? null) && $row[$key] !== '') {
                $rowLabel = $row[$key];
                break;
            }
        }

        foreach ($row as $column => $value) {
            $column = (string) $column;

            if ($column === 'action_links' && is_array($value)) {
                foreach ($value as $link) {
                    if (is_string($link) && preg_match_all('#entity://([^/\s)]+)/([^/\s)?]+)#', $link, $m, PREG_SET_ORDER)) {
                        foreach ($m as $hit) {
                            $this->add($hit[1], $hit[2]);
                        }
                    }
                }
                continue;
            }

            if (is_array($value)) {
                // Nested record (map) or collected list of records.
                array_is_list($value) ? $this->walk($value) : $this->indexRow($value);
                continue;
            }

            if (!is_scalar($value) || $value === '' || is_bool($value)) {
                continue;
            }

            if (in_array($column, $this->idFields, true)) {
                $this->add($rowLabel ?? $this->columnLabels[$column] ?? self::ANY, (string) $value);
            } elseif (isset($this->columnLabels[$column])) {
                $this->add($this->columnLabels[$column], (string) $value);
            } elseif (($prefix = self::idColumnPrefix($column)) !== null) {
                $this->add($prefix, (string) $value);
            }
        }
    }

    private function add(string $label, string $id): void
    {
        $id = trim($id);
        $key = $label === self::ANY ? self::ANY : self::normalize($label);
        if ($id !== '' && $key !== '') {
            $this->ids[$key][$id] = true;
        }
    }

    /**
     * The label part of a `<label>_id` / `<label>Id` name ("project_id" → "project",
     * "orderLineId" → "orderLine"); null for any other name.
     */
    public static function idColumnPrefix(string $name): ?string
    {
        return preg_match('/^(.+?)(?:_id|_ID|Id|ID)$/', $name, $m) === 1 && self::normalize($m[1]) !== '' ? $m[1] : null;
    }

    /**
     * Result columns the cypher projects from an id property of a labelled node.
     *
     * @return array<string, string>
     */
    private function columnLabelsFromCypher(string $cypher): array
    {
        if (trim($cypher) === '' || $this->idFields === []) {
            return [];
        }

        $cypher = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $cypher);

        $labels = [];
        if (preg_match_all('/\(\s*([A-Za-z_]\w*)\s*:\s*`?([A-Za-z_]\w*)`?/', $cypher, $m, PREG_SET_ORDER)) {
            foreach ($m as $node) {
                $labels[$node[1]] ??= $node[2];
            }
        }

        $fields = implode('|', array_map(static fn (string $f) => preg_quote($f, '/'), $this->idFields));
        $columns = [];
        $assign = function (string $column, string $variable) use ($labels, &$columns): void {
            if (!isset($labels[$variable])) {
                return;
            }
            $label = $labels[$variable];
            $columns[$column] = isset($columns[$column]) && $columns[$column] !== $label ? self::ANY : $label;
        };

        // `v.id AS alias` and bare `RETURN v.id` (column "v.id").
        if (preg_match_all('/\b([A-Za-z_]\w*)\.(' . $fields . ')\b(?:\s+AS\s+([A-Za-z_]\w*))?/i', $cypher, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $assign(isset($hit[3]) && $hit[3] !== '' ? $hit[3] : $hit[1] . '.' . $hit[2], $hit[1]);
            }
        }
        // Map literal entries `{key: v.id}`.
        if (preg_match_all('/([A-Za-z_]\w*)\s*:\s*([A-Za-z_]\w*)\.(' . $fields . ')\b/', $cypher, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $assign($hit[1], $hit[2]);
            }
        }

        return array_filter($columns, static fn (string $label) => $label !== self::ANY);
    }

    private static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
    }

    private static function config(string $key): mixed
    {
        try {
            return function_exists('config') ? config($key) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
