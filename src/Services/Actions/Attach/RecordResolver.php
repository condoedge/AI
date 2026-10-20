<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\Attach;

use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Response\EntityIdIndex;
use Condoedge\Ai\Services\Response\ResponseEntityEnricher;
use Condoedge\Ai\Services\Response\TableRenderer;

/**
 * RecordResolver — which RECORD each result row is (actions plan B1).
 *
 * Purely structural, never a column-name word list:
 *
 *   - a row's own `_label` / enricher `entity_type` owns its configured id field;
 *   - the cypher's projections `v.id AS alias` with `(v:Label)` attribute that column
 *     to Label (the same parse EntityIdIndex uses for the link lint);
 *   - a `<label>_id` / `<label>Id` column belongs to the label whose
 *     alphanumeric-lowercased name equals the prefix (identifier normalization).
 *
 * Only labels in $actionLabels (entities the user can open) count. When a row carries
 * several records (a unit AND its union) the record is what the user ASKED about: a
 * declared row label first; then, over many rows, the first QU-named label whose id
 * VARIES (an id repeated on every row is a parent, never the row's record), else the
 * varying id source with the most distinct ids; a single row: QU order, then row order
 * ({@see choose()}).
 *
 * The record's NAME comes from its OWN columns (projected from the same node variable —
 * `u.name AS unit_name` beside `u.id AS unit_id` — or prefixed by its label): the
 * properties its entity config declares as its name (`metadata.name_properties`, e.g.
 * Contact: first_name + last_name), joined in the declared order — else its first non-id
 * text column — else the row's first text column belonging to no other record
 * ({@see ownName()}). A nameless record carries the row's other named record as `context`.
 * Homonyms (different records, same name) get a DISAMBIGUATOR from another node's column
 * first — three units named "310" differ by their union — never a date/time value or an
 * aggregate's per-row result ({@see disambiguate()}).
 *
 * Records of a multi-record result also carry their GROUP: the value of the coarser column
 * of ANOTHER node that the rows name beside them (each board member's union) — what a
 * capped chip list is spread over ({@see grouped()}).
 */
final class RecordResolver
{
    /** @var array<string, string> normalized label => canonical label */
    private array $labels = [];

    /** @var list<string> */
    private array $idFields;

    /** @var array<string, string> node variable => label */
    private array $variables = [];

    /** @var array<string, array{0: string, 1: string}> result column => [variable, property] */
    private array $projections = [];

    /** @var list<string> normalized preferred labels, in preference order */
    private array $preferred = [];

    /** @var array<string, true> result columns aliasing an aggregate (`min(i.due_at) AS oldest_due`) */
    private array $aggregateAliases = [];

    /** @var array<string, list<string>> label => its entity's declared name properties (memo) */
    private array $nameProperties = [];

    /** Cypher's aggregating functions (language grammar, not vocabulary) — as RecordIdProjection. */
    private const AGGREGATES = 'count|sum|avg|min|max|collect|stdev|stdevp|percentilecont|percentiledisc';

    /**
     * @param list<string> $actionLabels    entity labels that have an openable (primary) action
     * @param list<string> $preferredLabels QU entity types, in QU order
     * @param list<string>|null $idFields   ai.entity_id_fields (null = config)
     */
    public function __construct(
        array $actionLabels,
        ?string $cypher = null,
        array $preferredLabels = [],
        ?array $idFields = null,
    ) {
        foreach ($actionLabels as $label) {
            if (is_string($label) && self::normalize($label) !== '') {
                $this->labels[self::normalize($label)] = $label;
            }
        }
        foreach ($preferredLabels as $label) {
            if (is_string($label) && self::normalize($label) !== '') {
                $this->preferred[] = self::normalize($label);
            }
        }
        $this->idFields = array_values(array_filter(
            $idFields ?? (self::config('ai.entity_id_fields') ?? ['id', '_id', 'neo4j_id']),
            'is_string'
        ));
        $this->parseCypher((string) $cypher);
    }

    /**
     * The record behind each row (row index => record); rows without an attributable
     * id of an openable label are absent. `context` is another record on the same row,
     * named, for a record that has no name of its own ("Invoice PDF — Unit 301").
     *
     * `name_columns` are the columns the name was read from (several for a split name,
     * `name_column` being the first); `group` is the record's coarser parent value, when the
     * rows name one ({@see grouped()}).
     *
     * @param array<int, mixed> $rows
     * @return array<int, array{label: string, id: string, name: ?string, name_column: ?string, name_columns: list<string>, disambiguator: ?string, group: ?string, context: array{label: string, name: string}|null}>
     */
    public function resolve(array $rows): array
    {
        [$records, $variables] = $this->records($rows);

        if (count($records) < 2) {
            return $records;
        }

        return $this->grouped($this->disambiguate($records, $variables, $rows), $variables, $rows);
    }

    /**
     * The result's HOMONYM groups by record IDENTITY (the data answer's HOMONYMS note — the
     * same rule the chips' disambiguator follows): records sharing a name (case/space-
     * insensitive) with ≥2 DISTINCT ids, and the column that tells them apart
     * ({@see disambiguatorColumn()} — another node's column first, never a date/time value
     * nor an aggregate's per-row result). Every record a row carries counts — the row's own
     * and the other nodes it names by their own columns — so "unit 310" in three unions is
     * found whichever id column comes first, while a parent repeated beside each of its
     * children (one union id on every unit row) is ONE record, never a homonym. A group
     * with no usable disambiguator is left out.
     *
     * Null when record identity is unknown — some row carries no attributable id — so the
     * caller can fall back to a value-only analysis.
     *
     * @param array<int, mixed> $rows
     * @return list<array{column: string, value: string, count: int, rows: int, by: string, values: list<string>}>|null
     */
    public function homonyms(array $rows, int $max = 5): ?array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        if ($rows === []) {
            return null;
        }

        [$records, $variables, $candidates] = $this->records($rows);
        if (count($records) !== count($rows)) {
            return null;
        }
        if (count($records) < 2) {
            return [];
        }

        // Every named record on every row: the row's record, then the other records it
        // names by their OWN columns (a union's name beside its unit).
        $entries = [];
        foreach ($records as $index => $record) {
            $entries[] = ['index' => $index, 'record' => $record, 'var' => $variables[$index]];
            foreach ($candidates[$index] as $other) {
                if ($other['label'] === $record['label'] && $other['id'] === $record['id']) {
                    continue;
                }
                [$name, $column, $columns] = $this->ownName($rows[$index], $other);
                if ($name !== null) {
                    $entries[] = [
                        'index' => $index,
                        'record' => ['label' => $other['label'], 'id' => $other['id'], 'name' => $name, 'name_column' => $column, 'name_columns' => $columns],
                        'var' => $other['var'],
                    ];
                }
            }
        }

        $display = array_map(fn (array $row) => $this->withoutIds($row), $rows);

        $groups = [];
        foreach ($this->groups(array_column($entries, 'record')) as $members) {
            $first = $entries[$members[0]];
            $indexes = array_values(array_unique(array_map(static fn (int $m) => $entries[$m]['index'], $members)));
            $by = $this->disambiguatorColumn(
                $indexes,
                (array) $first['record']['name_columns'],
                $first['record']['label'],
                $first['var'],
                $display
            );
            if ($by === null) {
                continue;
            }

            $values = [];
            foreach ($indexes as $index) {
                $value = $display[$index][$by] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $values[self::fold($value)] ??= trim($value);
                }
            }
            $ids = [];
            foreach ($members as $m) {
                $ids[$entries[$m]['record']['label'] . '|' . $entries[$m]['record']['id']] = true;
            }

            $groups[] = [
                'column' => (string) $first['record']['name_column'],
                'value' => (string) $first['record']['name'],
                'count' => count($ids),
                'rows' => count($indexes),
                'by' => $by,
                'values' => array_values($values),
            ];
            if (count($groups) >= $max) {
                break;
            }
        }

        return $groups;
    }

    /**
     * The node a result column is read from, as the query itself says: `v.prop AS column`
     * (or the bare `v.prop`) → the label of `v` and the property; a column that is a node
     * variable itself (`RETURN u`) → its label. Null for a column no labelled node of the
     * query owns (an aggregate, a literal, a column added after the query ran): the query
     * decides nothing about it. Every label counts here, openable or not.
     *
     * @return array{label: string, property: ?string}|null
     */
    public function columnOwner(string $column): ?array
    {
        [$variable, $property] = $this->projections[$column] ?? [null, null];
        if ($variable !== null && isset($this->variables[$variable])) {
            return ['label' => $this->variables[$variable], 'property' => $property];
        }

        return isset($this->variables[$column]) ? ['label' => $this->variables[$column], 'property' => null] : null;
    }

    /** Result columns aliasing an aggregate (`min(i.due_at) AS oldest_due`) — per-row facts, never identities. */
    public function aggregateAliases(): array
    {
        return array_map('strval', array_keys($this->aggregateAliases));
    }

    /**
     * Each attributable row's record, its node variable and every candidate it carries.
     *
     * @param array<int, mixed> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, ?string>, 2: array<int, list<array<string, mixed>>>}
     */
    private function records(array $rows): array
    {
        $candidates = [];
        foreach ($rows as $index => $row) {
            if (is_array($row) && ($found = $this->candidatesOf($row)) !== []) {
                $candidates[(int) $index] = $found;
            }
        }

        // The result's GRAIN (eval p9 F7): distinct ids per id SOURCE (node variable, else
        // column) — two Contact columns (owner_id, tenant_id) are two sources, never summed.
        $distinct = [];
        foreach ($candidates as $found) {
            foreach ($found as $candidate) {
                $distinct[$candidate['source']][$candidate['id']] = true;
            }
        }
        $grain = array_map('count', $distinct);
        $multiRow = count($candidates) > 1;

        $records = [];
        $variables = [];
        foreach ($candidates as $index => $found) {
            $chosen = $this->choose($found, $grain, $multiRow);
            $records[$index] = $this->recordOf($rows[$index], $chosen, $found);
            $variables[$index] = $chosen['var'];
        }

        return [$records, $variables, $candidates];
    }

    /**
     * Homonym groups among named records: members (keys of $records) sharing a name column
     * and a folded name, with ≥2 distinct records (label + id).
     *
     * @param array<int, array<string, mixed>> $records
     * @return list<list<int>>
     */
    private function groups(array $records): array
    {
        $byName = [];
        foreach ($records as $key => $record) {
            if (($record['name'] ?? null) === null || ($record['name_column'] ?? null) === null) {
                continue;
            }
            $byName[$record['name_column'] . "\0" . self::fold((string) $record['name'])][] = $key;
        }

        $groups = [];
        foreach ($byName as $members) {
            $ids = [];
            foreach ($members as $key) {
                $ids[$records[$key]['label'] . '|' . $records[$key]['id']] = true;
            }
            if (count($ids) >= 2) {
                $groups[] = $members;
            }
        }

        return $groups;
    }

    /**
     * The row's record — what the user ASKED about (coordinator decision, eval p9 F7):
     *
     *   1. a row's declared label (`_label` / `entity_type`: the digest source's entity);
     *   2. multi-row result: among the candidates whose id VARIES across the result, the
     *      first in QU entity-type order; none QU-named → the one with the most distinct
     *      ids. An id CONSTANT over many rows (the union beside each of its units) is a
     *      parent, never the per-row record;
     *   3. single row, or nothing varies: QU order, else the first id column.
     *
     * @param list<array{label: string, id: string, var: ?string, source: string, marker: bool}> $candidates
     * @param array<string, int> $grain source => distinct ids across the result
     * @return array{label: string, id: string, var: ?string, source: string, marker: bool}
     */
    private function choose(array $candidates, array $grain, bool $multiRow): array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['marker']) {
                return $candidate;
            }
        }

        $quRank = fn (array $candidate): int => ($position = array_search(self::normalize($candidate['label']), $this->preferred, true)) === false
            ? PHP_INT_MAX
            : (int) $position;

        $pool = $candidates;
        if ($multiRow) {
            $varying = array_values(array_filter($candidates, static fn (array $c) => ($grain[$c['source']] ?? 0) > 1));
            if ($varying !== []) {
                $named = array_values(array_filter($varying, static fn (array $c) => $quRank($c) !== PHP_INT_MAX));
                if ($named === []) {
                    $best = $varying[0];
                    foreach ($varying as $candidate) {
                        if (($grain[$candidate['source']] ?? 0) > ($grain[$best['source']] ?? 0)) {
                            $best = $candidate;
                        }
                    }

                    return $best;
                }
                $pool = $named;
            }
        }

        $best = $pool[0];
        foreach ($pool as $candidate) {
            if ($quRank($candidate) < $quRank($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{label: string, id: string, var: ?string, source: string, marker: bool} $chosen
     * @param list<array{label: string, id: string, var: ?string, source: string, marker: bool}> $found
     * @return array{label: string, id: string, name: ?string, name_column: ?string, name_columns: list<string>, disambiguator: ?string, group: ?string, context: array{label: string, name: string}|null}
     */
    private function recordOf(array $row, array $chosen, array $found): array
    {
        [$name, $nameColumn, $nameColumns] = $this->nameOf($row, $chosen, $found);

        // No name of its own: the row's other record, by ITS own columns, as context.
        $context = null;
        if ($name === null) {
            foreach ($found as $other) {
                if ($other['label'] === $chosen['label'] && $other['id'] === $chosen['id']) {
                    continue;
                }
                [$otherName] = $this->ownName($row, $other);
                if ($otherName !== null) {
                    $context = ['label' => $other['label'], 'name' => $otherName];
                    break;
                }
            }
        }

        return [
            'label' => $chosen['label'],
            'id' => $chosen['id'],
            'name' => $name,
            'name_column' => $nameColumn,
            'name_columns' => $nameColumns,
            'disambiguator' => null,
            'group' => null,
            'context' => $context,
        ];
    }

    /**
     * Every attributable record id the row carries, in row order (a row's own label marker
     * first).
     *
     * @param array<string, mixed> $row
     * @return list<array{label: string, id: string, var: ?string, source: string, marker: bool}>
     */
    private function candidatesOf(array $row): array
    {
        $candidates = [];

        // 1. The row's own label marker owns its id field.
        foreach (['_label', 'entity_type'] as $marker) {
            $label = $row[$marker] ?? null;
            if (is_string($label) && ($canonical = $this->canonical($label)) !== null) {
                foreach ($this->idFields as $field) {
                    if ($this->isIdValue($row[$field] ?? null)) {
                        $candidates[] = ['label' => $canonical, 'id' => (string) $row[$field], 'var' => null, 'source' => 'marker', 'marker' => true];
                        break;
                    }
                }
                break;
            }
        }

        // 2./3. Projected id columns and `<label>_id` columns, in row order.
        foreach ($row as $column => $value) {
            $column = (string) $column;
            if (!$this->isIdValue($value)) {
                continue;
            }
            [$variable, $property] = $this->projections[$column] ?? [null, null];
            if ($variable !== null && in_array($property, $this->idFields, true)) {
                $canonical = $this->canonical($this->variables[$variable] ?? '');
                if ($canonical !== null) {
                    $candidates[] = ['label' => $canonical, 'id' => (string) $value, 'var' => $variable, 'source' => 'var:' . $variable, 'marker' => false];
                }
                continue;
            }
            $prefix = EntityIdIndex::idColumnPrefix($column);
            if ($prefix !== null && ($canonical = $this->canonical($prefix)) !== null) {
                $variable = $this->variableFor($canonical);
                $candidates[] = ['label' => $canonical, 'id' => (string) $value, 'var' => $variable, 'source' => $variable !== null ? 'var:' . $variable : 'col:' . $column, 'marker' => false];
            }
        }

        return $candidates;
    }

    /**
     * Homonyms — two DIFFERENT records sharing a name (case/space-insensitive) — get a
     * DISAMBIGUATOR: the first text column whose values differ inside the group, ranked
     *   1. a column of ANOTHER node (the union of a unit) before an unattributed one,
     *      and the record's own attributes ("310 — occupied") last;
     *   2. the coarser column (fewer distinct values across the result — the parent);
     *   3. column order.
     * Never a date / time value nor an aggregate's per-row result (`min(i.due_at) AS
     * oldest_due`): per-row facts by nature, never an identity (eval p9 F7). One record
     * repeated over several rows (a union beside each of its units) is no homonym.
     *
     * @param array<int, array<string, mixed>> $records
     * @param array<int, ?string> $variables row index => the record's node variable
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function disambiguate(array $records, array $variables, array $rows): array
    {
        $display = [];
        foreach ($records as $index => $record) {
            $display[$index] = $this->withoutIds((array) $rows[$index]);
        }

        foreach ($this->groups($records) as $members) {
            $first = $members[0];
            $by = $this->disambiguatorColumn($members, (array) $records[$first]['name_columns'], $records[$first]['label'], $variables[$first], $display);
            if ($by === null) {
                continue;
            }
            foreach ($members as $index) {
                $value = $display[$index][$by] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $records[$index]['disambiguator'] = trim($value);
                }
            }
        }

        return $records;
    }

    /**
     * The records' GROUP (eval p16-A board: five chips, all from the first of eight unions):
     * the value of the column that says which PARENT each record belongs to, so a capped chip
     * list can be spread over the parents instead of spent on the first one.
     *
     * Per record label, the group column is — structurally, never by its name —
     *   1. a column of ANOTHER node (projected from another variable, or prefixed by another
     *      label): never the record's own attribute, its name, an id;
     *   2. text, never a date / time value nor an aggregate's per-row result (as for
     *      disambiguators);
     *   3. COARSER than the records: at least two values, fewer values than records;
     *   4. a parent, not a child: most records carry ONE value over all their rows. The
     *      contacts listed beside each union vary inside one union; a person sitting on two
     *      boards is the tolerated exception, not the rule.
     * The coarsest such column wins, then column order. No such column → no group.
     *
     * @param array<int, array<string, mixed>> $records
     * @param array<int, ?string> $variables row index => the record's node variable
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function grouped(array $records, array $variables, array $rows): array
    {
        $display = [];
        $byLabel = [];
        foreach ($records as $index => $record) {
            $display[$index] = $this->withoutIds((array) $rows[$index]);
            $byLabel[$record['label']][] = $index;
        }

        foreach ($byLabel as $members) {
            $column = $this->groupColumn($members, $records, $variables, $display);
            if ($column === null) {
                continue;
            }
            foreach ($members as $index) {
                $value = $display[$index][$column] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $records[$index]['group'] = trim($value);
                }
            }
        }

        return $records;
    }

    /**
     * @param list<int> $members row indexes of one label's records
     * @param array<int, array<string, mixed>> $records
     * @param array<int, ?string> $variables
     * @param array<int, array<string, mixed>> $display rows without ids
     */
    private function groupColumn(array $members, array $records, array $variables, array $display): ?string
    {
        $recordCount = count(array_unique(array_map(static fn (int $index) => $records[$index]['id'], $members)));
        if ($recordCount < 2) {
            return null;
        }

        $columns = [];
        $nameColumns = [];
        foreach ($members as $index) {
            $columns += array_fill_keys(array_map('strval', array_keys($display[$index])), true);
            $nameColumns += array_fill_keys((array) $records[$index]['name_columns'], true);
        }
        $label = $records[$members[0]]['label'];

        $best = null;
        $bestRank = null;
        $order = 0;
        foreach (array_keys($columns) as $column) {
            $column = (string) $column;
            $order++;
            if (isset($nameColumns[$column]) || isset($this->aggregateAliases[$column])) {
                continue;
            }

            $values = [];
            $perRecord = [];
            foreach ($members as $index) {
                if (!$this->ofAnotherNode($column, $label, $variables[$index])) {
                    continue 2;
                }
                $value = $display[$index][$column] ?? null;
                if ($value === null) {
                    continue;
                }
                if (!is_string($value) || self::isTemporal($value)) {
                    continue 2;
                }
                if (trim($value) !== '') {
                    $values[self::fold($value)] = true;
                    $perRecord[$records[$index]['id']][self::fold($value)] = true;
                }
            }
            if (count($values) < 2 || count($values) >= $recordCount) {
                continue;
            }
            $singleValued = count(array_filter($perRecord, static fn (array $own) => count($own) === 1));
            if ($singleValued * 2 <= $recordCount) {
                continue;
            }

            $rank = [count($values), $order];
            if ($bestRank === null || $rank < $bestRank) {
                [$best, $bestRank] = [$column, $rank];
            }
        }

        return $best;
    }

    /**
     * Whether a column is ANOTHER node's attribute: projected from a variable that is not
     * the record's, or (unprojected) prefixed by another label. An unattributed column is
     * nobody's.
     */
    private function ofAnotherNode(string $column, string $recordLabel, ?string $recordVar): bool
    {
        [$ownerVar] = $this->projections[$column] ?? [null];
        if ($ownerVar !== null) {
            return $recordVar === null || $ownerVar !== $recordVar;
        }
        $ownerLabel = $this->columnLabel($column);

        return $ownerLabel !== null && $ownerLabel !== $recordLabel;
    }

    /**
     * @param list<int> $members row indexes of the group
     * @param list<string> $nameColumns the columns the records' name is read from
     * @param ?string $recordVar the records' node variable
     * @param array<int, array<string, mixed>> $display rows without ids
     */
    private function disambiguatorColumn(array $members, array $nameColumns, string $recordLabel, ?string $recordVar, array $display): ?string
    {
        $columns = [];
        foreach ($members as $index) {
            foreach ($display[$index] as $column => $value) {
                $columns[(string) $column] = true;
            }
        }

        $best = null;
        $bestRank = null;
        $order = 0;
        foreach (array_keys($columns) as $column) {
            $order++;
            if (in_array((string) $column, $nameColumns, true) || isset($this->aggregateAliases[$column])) {
                continue;
            }
            $values = [];
            $usable = true;
            foreach ($members as $index) {
                $value = $display[$index][$column] ?? null;
                if ($value === null) {
                    continue;
                }
                if (!is_string($value) || self::isTemporal($value)) {
                    $usable = false;
                    break;
                }
                if (trim($value) !== '') {
                    $values[self::fold($value)] = true;
                }
            }
            if (!$usable || count($values) < 2) {
                continue;
            }

            [$ownerVar] = $this->projections[$column] ?? [null];
            $ownerLabel = $ownerVar === null ? $this->columnLabel($column) : null;
            $tier = match (true) {
                $ownerVar !== null => $recordVar !== null && $ownerVar === $recordVar ? 2 : 0,
                $ownerLabel !== null => $ownerLabel === $recordLabel ? 2 : 0,
                default => 1,
            };

            $spread = [];
            foreach ($display as $row) {
                $value = $row[$column] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $spread[self::fold($value)] = true;
                }
            }

            $rank = [$tier, count($spread), $order];
            if ($bestRank === null || $rank < $bestRank) {
                [$best, $bestRank] = [$column, $rank];
            }
        }

        return $best;
    }

    /**
     * The candidate's name from ITS OWN columns only.
     *
     * An entity whose name is SPLIT over several properties declares them
     * (`metadata.name_properties`, e.g. Contact: ['first_name', 'last_name']): the record is
     * named by every declared part the row carries, joined in the DECLARED order whatever
     * order or alias the query projected them in (eval p16-A board: `c.first_name,
     * c.last_name` named five contacts "Amélie", "Mauricio"… and made two different Juliens
     * homonyms). A declared part the row lacks is left out.
     *
     * Otherwise — nothing declared, or none of the declared parts projected — the first own
     * column (text before integers — a unit "310" may be stored as an int; an amount never
     * outranks a name).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $candidate
     * @return array{0: ?string, 1: ?string, 2: list<string>} name, its (first) column, every column it is read from
     */
    private function ownName(array $row, array $candidate): array
    {
        $display = $this->withoutIds($row);

        $parts = [];
        $columns = [];
        foreach ($this->namePropertiesOf((string) $candidate['label']) as $property) {
            foreach ($display as $column => $value) {
                if ($this->ownedProperty((string) $column, $candidate) !== $property) {
                    continue;
                }
                if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
                    $parts[] = trim((string) $value);
                    $columns[] = (string) $column;
                }
                break; // the first column projecting this property
            }
        }
        if ($parts !== []) {
            return [implode(' ', $parts), $columns[0], $columns];
        }

        $own = ['string' => null, 'int' => null];
        foreach ($display as $column => $value) {
            if (!(is_string($value) || is_int($value)) || trim((string) $value) === '') {
                continue;
            }
            if ($this->owns((string) $column, $candidate)) {
                $own[is_string($value) ? 'string' : 'int'] ??= [trim((string) $value), (string) $column, [(string) $column]];
            }
        }

        return $own['string'] ?? $own['int'] ?? [null, null, []];
    }

    /**
     * The properties the label's entity config declares as its name
     * (`metadata.name_properties`); [] when it declares none or is not configured.
     *
     * @return list<string>
     */
    private function namePropertiesOf(string $label): array
    {
        if (!array_key_exists($label, $this->nameProperties)) {
            try {
                $config = EntityLabelIndex::shared()->configForLabel($label) ?? [];
            } catch (\Throwable $e) {
                $config = [];
            }
            $this->nameProperties[$label] = array_values(array_filter(
                (array) ($config['metadata']['name_properties'] ?? []),
                static fn ($property): bool => is_string($property) && $property !== ''
            ));
        }

        return $this->nameProperties[$label];
    }

    /**
     * The candidate's own PROPERTY a column holds: the projected property (`c.first_name`,
     * `c.first_name AS prenom`), or — unprojected — the column name after its label prefix
     * (`contact_first_name`, `contactFirstName` → first_name). Null when the column is not
     * the candidate's own.
     *
     * @param array<string, mixed> $candidate
     */
    private function ownedProperty(string $column, array $candidate): ?string
    {
        if (!$this->owns($column, $candidate)) {
            return null;
        }
        [, $property] = $this->projections[$column] ?? [null, null];
        if ($property !== null) {
            return $property;
        }

        $label = (string) $candidate['label'];
        $snake = self::snake($label);
        if (stripos($column, $snake . '_') === 0) {
            return substr($column, strlen($snake) + 1);
        }

        return self::snake(substr($column, strlen($label)));
    }

    /** `ContactUnit` / `FirstName` → `contact_unit` / `first_name` (identifier normalization). */
    private static function snake(string $identifier): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $identifier));
    }

    /**
     * Whether a column is the candidate's own attribute: projected from its node variable,
     * or (unprojected) prefixed by its label (`unit_name` → Unit, identifier normalization).
     *
     * @param array<string, mixed> $candidate
     */
    private function owns(string $column, array $candidate): bool
    {
        [$owner] = $this->projections[$column] ?? [null];
        if ($owner !== null) {
            return $candidate['var'] !== null && $owner === $candidate['var'];
        }

        return $this->columnLabel($column) === $candidate['label'];
    }

    /**
     * The record's display name: its own column, else the row's first text value that
     * belongs to NO other record on the row (never the unit's name on an invoice chip).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $chosen
     * @param list<array<string, mixed>> $found
     * @return array{0: ?string, 1: ?string, 2: list<string>}
     */
    private function nameOf(array $row, array $chosen, array $found): array
    {
        $own = $this->ownName($row, $chosen);
        if ($own[0] !== null) {
            return $own;
        }

        foreach ($this->withoutIds($row) as $column => $value) {
            $column = (string) $column;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            [$owner] = $this->projections[$column] ?? [null];
            if ($owner !== null && $chosen['var'] !== null) {
                continue; // another node's attribute
            }
            foreach ($found as $other) {
                if ($other['label'] !== $chosen['label'] && $this->owns($column, $other)) {
                    continue 2;
                }
            }

            return [trim($value), $column, [$column]];
        }

        return [null, null, []];
    }

    /** The action label an unprojected column is prefixed by (`unit_name`, `unitName`), if any. */
    private function columnLabel(string $column): ?string
    {
        $best = null;
        foreach ($this->labels as $label) {
            $snake = self::snake($label);
            if (stripos($column, $snake . '_') === 0 || preg_match('/^' . preg_quote(lcfirst($label), '/') . '(?=[A-Z])/', $column) === 1) {
                if ($best === null || strlen($label) > strlen($best)) {
                    $best = $label;
                }
            }
        }

        return $best;
    }

    /** An ISO-8601 date, date-time or time of day ({@see TableRenderer::isTemporalValue()}). */
    private static function isTemporal(string $value): bool
    {
        return TableRenderer::isTemporalValue($value);
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * The row without decoration keys, configured id fields, projected id columns and
     * `<label>_id` columns of any registered action label.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withoutIds(array $row): array
    {
        $out = [];
        foreach ($row as $column => $value) {
            $column = (string) $column;
            if (in_array($column, ResponseEntityEnricher::DECORATION_KEYS, true) || in_array($column, $this->idFields, true)) {
                continue;
            }
            [, $property] = $this->projections[$column] ?? [null, null];
            if ($property !== null && in_array($property, $this->idFields, true)) {
                continue;
            }
            if (EntityIdIndex::idColumnPrefix($column) !== null) {
                continue;
            }
            $out[$column] = $value;
        }

        return $out;
    }

    private function parseCypher(string $cypher): void
    {
        if (trim($cypher) === '') {
            return;
        }

        $cypher = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $cypher);

        if (preg_match_all('/\(\s*([A-Za-z_]\w*)\s*:\s*`?([A-Za-z_]\w*)`?/', $cypher, $m, PREG_SET_ORDER)) {
            foreach ($m as $node) {
                $this->variables[$node[1]] ??= $node[2];
            }
        }

        // `v.prop AS alias` and bare `v.prop` (column "v.prop") — RETURN/WITH projections.
        if (preg_match_all('/\b([A-Za-z_]\w*)\.([A-Za-z_]\w*)\b(?:\s+AS\s+([A-Za-z_]\w*))?/i', $cypher, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (!isset($this->variables[$hit[1]])) {
                    continue;
                }
                $column = isset($hit[3]) && $hit[3] !== '' ? $hit[3] : $hit[1] . '.' . $hit[2];
                $this->projections[$column] ??= [$hit[1], $hit[2]];
            }
        }

        // `agg(…) AS alias` (two nesting levels, an optional list slice `[..3]`).
        $call = '\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)';
        if (preg_match_all('/\b(?:' . self::AGGREGATES . ')\s*' . $call . '(?:\s*\[[^\]]*\])?\s+AS\s+`?([A-Za-z_]\w*)`?/i', $cypher, $m)) {
            foreach ($m[1] as $alias) {
                $this->aggregateAliases[$alias] = true;
            }
        }
    }

    private function variableFor(string $label): ?string
    {
        foreach ($this->variables as $variable => $variableLabel) {
            if (self::normalize($variableLabel) === self::normalize($label)) {
                return $variable;
            }
        }

        return null;
    }

    private function canonical(string $label): ?string
    {
        return $this->labels[self::normalize($label)] ?? null;
    }

    private function isIdValue(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && trim((string) $value) !== '';
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
