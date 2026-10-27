<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\Actions\Attach\RecordResolver;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Response\CodedColumns;
use Condoedge\Ai\Services\Response\EntityIdIndex;

/**
 * RecordParentEnricher — ONE record is shown with what it belongs to, and with its
 * own key fields.
 *
 * A single-record lookup used to show only what the generator happened to project
 * (eval p18-B digest t1, "When was our last meeting then?": name, date and id — the
 * group the meeting belongs to was projected in 1 run of 4, so the answer could not
 * say WHICH group met, and with two displayable columns the record
 * block never rendered). No prompt line fixed it over four runs; this does, by code:
 *
 * when a result is exactly ONE row that is a record of a configured entity and
 * carries that record's id, the row gains the DISPLAY NAME of each many-to-one
 * PARENT the entity config declares — one extra query by id, through the pipeline's
 * scoped executor, zero LLM calls:
 *
 *     MATCH (n:Meeting) WHERE n.id = $ai_record_id
 *     OPTIONAL MATCH (n)-[:BELONGS_TO_GROUP]->(p0:Group)
 *     RETURN p0.name AS p0_0 LIMIT 1
 *
 * WHICH PARENTS — structure of the host's entity config only, no vocabulary:
 *  - a `graph.relationships` entry of the record's entity that carries a
 *    `foreign_key` which is one of the entity's OWN `graph.properties`, pointing
 *    outwards (the "belongs to" direction). A has-many declared with the other
 *    table's key, a morph / pivot / inverse / incoming / polymorphic declaration
 *    is not that ({@see \Condoedge\Ai\Services\Actions\Attach\ActionAttacher}'s
 *    belongs-to rule);
 *  - the parent is a configured entity a user can ask about (`metadata.askable`
 *    is not false), is not the record's own entity (a parent of the same kind
 *    would read as the record's own name), and is not an IDENTITY label
 *    (`ai.response_generation.identity_labels`: the current user / team are the
 *    SCOPE every visible record shares — and a user key names an actor in a role
 *    a header cannot state — never a description of the record);
 *  - the parent has a name: its `metadata.name_properties` when declared (a split
 *    name is joined in the declared order), else its `name` property; a parent
 *    without either, or whose name is one of its `security.sensible_columns`, is
 *    skipped;
 *  - the row does not already name that parent (the query projected the parent's
 *    name property, or a column prefixed by the parent's label holds text);
 *  - at most MAX_PARENTS parents, in declaration order.
 *
 * The added column is `<parent label>_name` (`group_name` → the header "Group
 * Name"); two declared relationships to the same parent label are told apart by
 * their foreign key (`from_account_id` → `from_account_name`).
 *
 * ITS OWN DETAIL FIELDS (eval p19-B digest t1: the group was shown, the meeting's
 * place — recorded — was not, because the generator did not project it). An entity
 * may declare which of its OWN properties are worth showing on a single record:
 *
 *     'metadata' => ['detail_properties' => ['start_date', 'place', 'organizer']],
 *
 * Those the row does not already show come back in the SAME query by id
 * (`n.place AS place`) and become columns of the row, under the property's own
 * name, before the parents' names:
 *  - only a plain identifier that is one of the entity's `graph.properties` and
 *    none of its `security.sensible_columns`;
 *  - not one the row already carries: a column of that name, or a RETURN item of
 *    the query that reads the property from a node of the record's label (under
 *    any alias, alone or inside an expression);
 *  - at most MAX_DETAILS, in declared order;
 *  - a field holding nothing (null, blank) adds NO column — nothing to hide later;
 *  - a field holding a code reads as its label ({@see CodedColumns}, over the
 *    lookup's own projection: the turn's query does not project it).
 * Zero config (no `detail_properties`): nothing is fetched, nothing changes.
 *
 * SECURITY INVARIANT (as EmptyResultInspector / DigestRunner): the only path to the
 * graph is QueryExecutorInterface::execute() with the turn's own options — the
 * acting user, provenance and the turn's rate_limit_batch (the lookup rides the
 * turn's single charge) — read-only, a skip_security key stripped. The executor
 * tenant-scopes the record AND every optional parent binding, so a record of
 * another tenant yields no row and nothing is added. The id is a bound parameter,
 * never spliced into the text. A failed, blocked or empty lookup returns null: the
 * original row stands, the turn never fails.
 */
final class RecordParentEnricher
{
    /** Most parents one record is enriched with. */
    public const MAX_PARENTS = 3;

    /** Most of its own detail fields one record is enriched with (a record block stays a glance). */
    public const MAX_DETAILS = 4;

    /** The bound parameter carrying the record's id. */
    public const ID_PARAMETER = 'ai_record_id';

    /** Cypher's aggregating functions (language grammar, not vocabulary) — as FanOutCollapse. */
    private const AGGREGATE_CALL = '/\b(count|sum|avg|min|max|collect|stDev|stDevP|percentileCont|percentileDisc)\s*\(/i';

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private readonly EntityLabelIndex $index;

    /** Graph executions made since the last takeExecutions() (observation only). */
    private int $executions = 0;

    public function __construct(
        private readonly QueryExecutorInterface $executor,
        ?EntityLabelIndex $index = null,
    ) {
        $this->index = $index ?? EntityLabelIndex::shared();
    }

    /** The number of graph executions made since the last call — and reset (trace evidence). */
    public function takeExecutions(): int
    {
        [$count, $this->executions] = [$this->executions, 0];

        return $count;
    }

    /**
     * The single row of $rows with its record's own detail fields and its parents'
     * names added, or null when nothing is added (not one record of a configured
     * entity, an aggregate, no id, no detail field and no parent to add, or the lookup
     * failed / was blocked / found nothing).
     *
     * WHICH record the row is: {@see RecordResolver} over every configured entity —
     * the rule the record chips follow (`v.id AS alias` projections and `<label>_id`
     * columns; of several records on one row, the first in $preferredLabels order,
     * else the first id column).
     *
     * @param ?string $cypher the query that produced $rows
     * @param array<int, mixed> $rows
     * @param array<string, mixed> $execOptions the turn's execution options
     * @param list<string> $preferredLabels the entities the question is about (QU order)
     * @param string|null $locale the answer's language, for the label of a coded detail field (null = as declared)
     * @return array{row: array<string, mixed>, entity: string, id: int|string, columns: array<string, string>, details: list<string>, cypher: string, execution: QueryExecutionResult}|null
     *         columns: added column => the parent label it names; details: the added
     *         columns that are the record's own properties
     */
    public function enrich(?string $cypher, array $rows, array $execOptions, array $preferredLabels = [], ?string $locale = null): ?array
    {
        $row = count($rows) === 1 ? reset($rows) : null;
        if (!is_array($row) || $row === [] || $cypher === null || trim($cypher) === '') {
            return null;
        }

        $items = FanOutCollapse::returnItems($cypher);
        if ($items === [] || $this->aggregates($items)) {
            return null;
        }

        $record = $this->record($cypher, $row, $preferredLabels);
        if ($record === null) {
            return null;
        }
        ['label' => $label, 'id' => $id] = $record;
        $config = $this->index->configForLabel($label);
        if ($config === null) {
            return null;
        }

        $parents = $this->parents($label, $config, $row, $cypher, $items);
        $details = $this->details($label, $config, $row, $cypher, $items);
        if ($parents === [] && $details === []) {
            return null;
        }

        $query = $this->query($label, $parents, $details);

        // Same authority as every other pipeline execution: no bypass, read-only.
        unset($execOptions['skip_security'], $execOptions['limit']);
        $execOptions['read_only'] = true;

        try {
            $this->executions++;
            $execution = $this->executor->execute($query, [self::ID_PARAMETER => $id], $execOptions);
        } catch (\Throwable $e) {
            \Log::warning('Parent lookup of a single record failed — the row stands as returned: ' . $e->getMessage());

            return null;
        }

        $found = $execution->success && empty($execution->errors) && ($execution->metadata['safe_stop'] ?? false) !== true
            ? ($execution->data[0] ?? null)
            : null;
        if (!is_array($found)) {
            return null;
        }

        // The record's own fields first, then what it belongs to. A code reads as its
        // label: the legend is found through the lookup's own projection.
        $added = [];
        if ($details !== []) {
            try {
                $found = CodedColumns::labelled([$found], $query, $locale)[0] ?? $found;
            } catch (\Throwable $e) {
                // The raw value stands.
            }
            foreach ($details as $property) {
                $value = $found[$property] ?? null;
                if ((is_string($value) && trim($value) !== '') || is_int($value) || is_float($value)) {
                    $row[$property] = is_string($value) ? trim($value) : $value;
                    $added[] = $property;
                }
            }
        }

        $columns = [];
        foreach ($parents as $position => $parent) {
            $parts = [];
            foreach (array_keys($parent['name_properties']) as $nth) {
                $value = $found["p{$position}_{$nth}"] ?? null;
                if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
                    $parts[] = trim((string) $value);
                }
            }
            if ($parts !== []) {
                $row[$parent['column']] = implode(' ', $parts);
                $columns[$parent['column']] = $parent['label'];
            }
        }

        return $columns === [] && $added === [] ? null : [
            'row' => $row,
            'entity' => $label,
            'id' => $id,
            'columns' => $columns,
            'details' => $added,
            'cypher' => $query,
            'execution' => $execution,
        ];
    }

    /**
     * @param list<array{label: string, type: string, column: string, name_properties: list<string>}> $parents
     * @param list<string> $details the record's own properties to return, each under its own name
     */
    private function query(string $label, array $parents, array $details = []): string
    {
        $matches = [];
        $returns = [];
        foreach ($parents as $position => $parent) {
            $matches[] = "OPTIONAL MATCH (n)-[:{$parent['type']}]->(p{$position}:{$parent['label']})";
            foreach ($parent['name_properties'] as $nth => $property) {
                $returns[] = "p{$position}.{$property} AS p{$position}_{$nth}";
            }
        }
        foreach ($details as $property) {
            $returns[] = "n.{$property} AS {$property}";
        }

        return implode(' ', [
            "MATCH (n:{$label}) WHERE n.{$this->idField()} = \$" . self::ID_PARAMETER,
            ...$matches,
            'RETURN ' . implode(', ', $returns) . ' LIMIT 1',
        ]);
    }

    /**
     * The record's own DETAIL fields to fetch: the properties its entity declares worth
     * showing on a single record (`metadata.detail_properties`) that the row does not
     * already show (class doc). [] when the entity declares none — the zero-config default.
     *
     * @param array<string, mixed> $config the record's entity config
     * @param array<string, mixed> $row
     * @param list<array{column: string, expression: string, aliased: bool}> $items the query's RETURN items
     * @return list<string>
     */
    private function details(string $label, array $config, array $row, string $cypher, array $items): array
    {
        $declared = (array) ($config['metadata']['detail_properties'] ?? []);
        if ($declared === []) {
            return [];
        }

        $own = array_values(array_filter((array) ($config['graph']['properties'] ?? []), 'is_string'));
        $sensitive = array_filter((array) ($config['security']['sensible_columns'] ?? []), 'is_string');
        // The record's own node variables in the query: what `m.place` is read from.
        $variables = array_keys(array_filter($this->nodeVariables($cypher), static fn (string $of): bool => $of === $label));

        $details = [];
        foreach ($declared as $property) {
            if (!is_string($property) || preg_match(self::IDENTIFIER, $property) !== 1
                // The lookup's own aliases for the parents' names.
                || preg_match('/^p\d+_\d+$/', $property) === 1
                || !in_array($property, $own, true)
                || in_array($property, $sensitive, true)
                || in_array($property, $details, true)
                || array_key_exists($property, $row)) {
                continue;
            }
            foreach ($items as $item) {
                $expression = FanOutCollapse::mask($item['expression']);
                foreach ($variables as $variable) {
                    if (preg_match('/(?<![\w.])' . preg_quote($variable, '/') . '\s*\.\s*`?' . preg_quote($property, '/') . '`?(?!\w)/', $expression) === 1) {
                        continue 3; // the row already shows it, under whatever alias
                    }
                }
            }

            $details[] = $property;
            if (count($details) >= self::MAX_DETAILS) {
                break;
            }
        }

        return $details;
    }

    /**
     * The declared many-to-one parents of $label worth naming on this row (class doc).
     *
     * @param array<string, mixed> $config the record's entity config
     * @param array<string, mixed> $row
     * @param list<array{column: string, expression: string, aliased: bool}> $items the query's RETURN items
     * @return list<array{label: string, type: string, column: string, name_properties: list<string>}>
     */
    private function parents(string $label, array $config, array $row, string $cypher, array $items): array
    {
        $own = array_values(array_filter((array) ($config['graph']['properties'] ?? []), 'is_string'));
        $identity = $this->identityLabels();

        $candidates = [];
        foreach ((array) ($config['graph']['relationships'] ?? []) as $relationship) {
            if (!is_array($relationship)
                || ($relationship['inverse'] ?? false) === true
                || isset($relationship['pivot'])
                || isset($relationship['morph'])
                || strtolower((string) ($relationship['direction'] ?? 'outgoing')) !== 'outgoing') {
                continue;
            }
            $type = $relationship['type'] ?? null;
            $target = $relationship['target_label'] ?? null;
            $key = $relationship['foreign_key'] ?? $relationship['foreignKey'] ?? null;
            if (!is_string($type) || preg_match(self::IDENTIFIER, $type) !== 1
                || !is_string($target) || preg_match(self::IDENTIFIER, $target) !== 1
                || $target === RelationshipConfig::POLYMORPHIC_TARGET
                || !is_string($key) || !in_array($key, $own, true)
                || $target === $label
                || in_array($target, $identity, true)) {
                continue;
            }

            $parentConfig = $this->index->configForLabel($target);
            if ($parentConfig === null || ($parentConfig['metadata']['askable'] ?? true) === false) {
                continue;
            }
            $nameProperties = $this->nameProperties($parentConfig);
            if ($nameProperties === []) {
                continue;
            }

            $candidates[$target . "\0" . $key] ??= [
                'label' => $target,
                'type' => $type,
                'key' => $key,
                'name_properties' => $nameProperties,
            ];
        }

        $perLabel = array_count_values(array_column($candidates, 'label'));
        $variables = $this->nodeVariables($cypher);

        $parents = [];
        foreach ($candidates as $candidate) {
            $column = self::snake($candidate['label']) . '_name';
            if ($perLabel[$candidate['label']] > 1) {
                // Two relationships to one parent kind: the foreign key tells them apart.
                $stem = preg_match('/^(.+)_id$/', $candidate['key'], $m) === 1 ? $m[1] : $candidate['key'];
                $column = $stem . '_name';
            }
            if (preg_match(self::IDENTIFIER, $column) !== 1
                || $this->alreadyNamed($row, $candidate['label'], $candidate['name_properties'], $column, $items, $variables)) {
                continue;
            }
            foreach ($parents as $kept) {
                if ($kept['column'] === $column) {
                    continue 2;
                }
            }

            $parents[] = [
                'label' => $candidate['label'],
                'type' => $candidate['type'],
                'column' => $column,
                'name_properties' => $candidate['name_properties'],
            ];
            if (count($parents) >= self::MAX_PARENTS) {
                break;
            }
        }

        return $parents;
    }

    /**
     * The properties that NAME a parent: its declared `metadata.name_properties`, else
     * `name` when it is one of its graph properties. [] when it has no name, a declared
     * property is not a plain identifier, or one of them is a sensitive column.
     *
     * @param array<string, mixed> $config
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

    /**
     * Whether the row already names this parent: the target column exists, the query
     * projects one of the parent's name properties from a node of the parent's label,
     * or a non-id column prefixed by the parent's label holds text.
     *
     * @param array<string, mixed> $row
     * @param list<string> $nameProperties
     * @param list<array{column: string, expression: string, aliased: bool}> $items
     * @param array<string, string> $variables node variable => label
     */
    private function alreadyNamed(array $row, string $parent, array $nameProperties, string $column, array $items, array $variables): bool
    {
        if (array_key_exists($column, $row)) {
            return true;
        }

        foreach ($items as $item) {
            if (preg_match('/^([A-Za-z_]\w*)\.([A-Za-z_]\w*)$/', trim($item['expression']), $m) === 1
                && ($variables[$m[1]] ?? null) === $parent
                && in_array($m[2], $nameProperties, true)) {
                return true;
            }
        }

        $prefix = self::snake($parent);
        foreach ($row as $name => $value) {
            $name = (string) $name;
            if (!is_string($value) || trim($value) === '' || EntityIdIndex::idColumnPrefix($name) !== null) {
                continue;
            }
            $snake = self::snake($name);
            if ($snake === $prefix || str_starts_with($snake, $prefix . '_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The record the row IS — its entity label and its id, with the type the graph
     * returned the id in (it is bound as a parameter: `n.id = "140"` never matches an
     * integer id). Null when the row carries no attributable id of a configured entity.
     *
     * @param array<string, mixed> $row
     * @param list<string> $preferredLabels
     * @return array{label: string, id: int|string}|null
     */
    private function record(string $cypher, array $row, array $preferredLabels): ?array
    {
        try {
            $record = (new RecordResolver($this->entityLabels(), $cypher, $preferredLabels))->resolve([$row])[0] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
        $label = is_array($record) ? ($record['label'] ?? null) : null;
        if (!is_string($label) || preg_match(self::IDENTIFIER, $label) !== 1 || !isset($record['id'])) {
            return null;
        }
        $id = (string) $record['id'];

        $preferred = FanOutCollapse::idColumnFor($label);
        $ordered = array_key_exists($preferred, $row) ? [$preferred => $row[$preferred]] + $row : $row;
        foreach ($ordered as $value) {
            if ((is_int($value) || is_string($value)) && (string) $value === $id) {
                return ['label' => $label, 'id' => $value];
            }
        }

        return null;
    }

    /** @return list<string> the graph label of every configured entity */
    private function entityLabels(): array
    {
        try {
            $entities = function_exists('config') ? config('entities', []) : [];
        } catch (\Throwable $e) {
            $entities = [];
        }

        $labels = [];
        foreach (is_array($entities) ? $entities : [] as $config) {
            $label = is_array($config) ? ($config['graph']['label'] ?? null) : null;
            if (is_string($label) && $label !== '') {
                $labels[$label] = true;
            }
        }

        return array_keys($labels);
    }

    /** @param list<array{column: string, expression: string, aliased: bool}> $items */
    private function aggregates(array $items): bool
    {
        foreach ($items as $item) {
            if (preg_match(self::AGGREGATE_CALL, FanOutCollapse::mask($item['expression'])) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The labelled node variables of the query (string literals masked).
     *
     * @return array<string, string> variable => label (its first)
     */
    private function nodeVariables(string $cypher): array
    {
        $masked = FanOutCollapse::mask(FanOutCollapse::canonical($cypher) ?? $cypher);
        preg_match_all('/\(\s*([A-Za-z_]\w*)\s*:\s*`?([A-Za-z_]\w*)`?/', $masked, $nodes, PREG_SET_ORDER);

        $variables = [];
        foreach ($nodes as $node) {
            $variables[$node[1]] ??= $node[2];
        }

        return $variables;
    }

    /** @return list<string> the labels of the current user / current team nodes */
    private function identityLabels(): array
    {
        try {
            $labels = function_exists('config') ? config('ai.response_generation.identity_labels', ['user' => 'User', 'team' => 'Team']) : [];
        } catch (\Throwable $e) {
            $labels = [];
        }

        return array_values(array_filter((array) $labels, static fn ($label): bool => is_string($label) && $label !== ''));
    }

    /** The id property records are matched by (ai.entity_id_fields — `id` when listed, as RecordIdProjection). */
    private function idField(): string
    {
        try {
            $fields = function_exists('config') ? config('ai.entity_id_fields') : null;
        } catch (\Throwable $e) {
            $fields = null;
        }
        $fields = array_values(array_filter((array) ($fields ?? ['id']), static fn ($field): bool => is_string($field) && preg_match(self::IDENTIFIER, $field) === 1));

        return $fields === [] || in_array('id', $fields, true) ? 'id' : $fields[0];
    }

    /** `OrderLine` / `groupName` → `order_line` / `group_name` (identifier normalization). */
    private static function snake(string $identifier): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $identifier));
    }
}
