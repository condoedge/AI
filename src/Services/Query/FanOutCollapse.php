<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

use Condoedge\Ai\Services\Security\CypherCanonicalizer;
use Condoedge\Ai\Services\Security\TeamPathResolver;

/**
 * FAN-OUT COLLAPSE (eval p11 D1/D2) — purely structural helpers, no vocabulary.
 *
 * A list query that joins a one-to-many entity (members × contact values) returns
 * one row per JOINED record: "100 of 1354 rows" for 523 members, a 100-row window
 * holding ~40 members. These helpers bring such a result back to one row per
 * ANCHOR record:
 *
 *  - plan() / perAnchorQuery(): rewrites the final RETURN so the query itself
 *    groups by the anchor node (`WITH anchor, collect(DISTINCT joined)[0..N+1] …`)
 *    — MATCH / WHERE (tenant scoping) are kept verbatim, the trailing LIMIT keeps
 *    its meaning (now: anchor records).
 *  - isCollapsed(): verifies a re-run result really IS one row per anchor before
 *    any caller trusts (and stamps) it.
 *  - joinLists(): the collected list values rendered as "a; b" cells, capped.
 *  - collapseByColumns(): the same grouping in PHP over rows already fetched,
 *    keyed by given columns plus the record identity (the export same-grain
 *    check).
 *  - isPinned(): whether the anchor is filtered against a value (u.name = '310'):
 *    then the rows are about the joined records of that anchor, not a list of
 *    anchors, and must not be collapsed.
 *  - uncappedQuery(): the export re-run without its display LIMIT, bounded by an
 *    explicit document LIMIT.
 *
 * Every analysis runs on the CANONICAL text (CypherCanonicalizer: comments
 * removed) with string literals and backtick identifiers masked, so neither a
 * comment nor a quoted word can move a clause boundary.
 *
 * Cypher KEYWORDS below are grammar, not semantics: they are never compared with
 * user text.
 */
final class FanOutCollapse
{
    /** Cypher expression keywords — never variable references. */
    private const EXPRESSION_KEYWORDS = [
        'as', 'distinct', 'case', 'when', 'then', 'else', 'end', 'and', 'or', 'xor', 'not',
        'in', 'is', 'null', 'true', 'false', 'starts', 'ends', 'with', 'contains',
    ];

    /** Aggregating functions: an aggregating RETURN already has its own grain. */
    private const AGGREGATE_PATTERN = '/\b(count|sum|avg|min|max|collect|stDev|stDevP|percentileCont|percentileDisc)\s*\(/i';

    /** A clause keyword boundary: never a property (`u.limit`), part of a name or a `$param`. */
    private const KW = '(?<![.\w$])';

    /** Separator of joined values in one cell. */
    public const JOIN_SEPARATOR = '; ';

    /** Appended to a cell whose collected values exceeded the cell limit. */
    public const TRUNCATION_MARKER = '…';

    /**
     * The query rewritten to one row per $anchor node, or null when the shape is
     * not safely rewritable. See plan().
     */
    public static function perAnchorQuery(string $cypher, string $anchor, ?int $cellLimit = null): ?string
    {
        return self::plan($cypher, $anchor, $cellLimit)['query'] ?? null;
    }

    /**
     * The per-anchor rewrite and the column roles needed to verify its result, or
     * null when the shape is not safely rewritable: UNION, final RETURN inside a
     * subquery, DISTINCT / aggregating projection, `*`, SKIP, a projected value
     * that is not a pattern variable (or a backticked one), a joined node / map
     * projection (I3: no scalar cell), more than one column from the same joined
     * variable (I2: per-column DISTINCT lists would de-pair one record's values),
     * an ORDER BY on anything out of scope after the grouping WITH (S6).
     *
     * Items referencing only the anchor are kept verbatim (the anchor is in scope
     * after the grouping WITH); every other item becomes collect(DISTINCT expr)
     * under its original column name — capped at $cellLimit + 1 values when a
     * limit is given (one past it, so the cell can say it was truncated). A
     * joined record's `<x>_id` id projection (RecordIdProjection) is dropped:
     * after grouping it would be a list of ids, not a record. `WHERE anchor IS
     * NOT NULL` drops rows whose OPTIONAL anchor matched nothing (a TeamRole
     * without a user is not a member).
     *
     * @return array{query: string, anchor: string, anchor_columns: array<int, string>, collected_columns: array<int, string>, cell_limit: ?int}|null
     */
    public static function plan(string $cypher, string $anchor, ?int $cellLimit = null): ?array
    {
        $cypher = self::canonical($cypher);
        if ($cypher === null) {
            return null;
        }
        $masked = self::mask($cypher);

        if (self::hasUnionClauseMasked($masked)) {
            return null;
        }
        $returnPos = self::finalReturnOffset($masked);
        if ($returnPos === null) {
            return null;
        }
        $head = substr($cypher, 0, $returnPos);
        $maskedHead = substr($masked, 0, $returnPos);

        $variables = self::patternVariables($maskedHead);
        if (!in_array($anchor, $variables, true)) {
            return null;
        }

        $tail = substr($cypher, $returnPos + strlen('RETURN'));
        $maskedTail = substr($masked, $returnPos + strlen('RETURN'));
        if (preg_match('/' . self::KW . '\bSKIP\b/i', $maskedTail) === 1 || substr_count($maskedTail, '{') !== substr_count($maskedTail, '}')) {
            return null;
        }

        $projection = $tail;
        $orderBy = null;
        $limit = null;
        if (preg_match('/' . self::KW . '\b(ORDER\s+BY|LIMIT)\b/i', $maskedTail, $clause, PREG_OFFSET_CAPTURE) === 1) {
            $projection = substr($tail, 0, (int) $clause[0][1]);
            $rest = substr($tail, (int) $clause[0][1]);
            $maskedRest = substr($maskedTail, (int) $clause[0][1]);
            if (preg_match('/' . self::KW . '\bLIMIT\b/i', $maskedRest, $limitAt, PREG_OFFSET_CAPTURE) === 1) {
                $limit = trim(substr($rest, (int) $limitAt[0][1]));
                $rest = substr($rest, 0, (int) $limitAt[0][1]);
            }
            if (preg_match('/^\s*ORDER\s+BY\b/i', $rest) === 1) {
                $orderBy = trim((string) preg_replace('/^\s*ORDER\s+BY\b/i', '', $rest));
            }
        }

        $projection = trim($projection);
        $maskedProjection = self::mask($projection);
        if ($projection === '' || preg_match('/^DISTINCT\b/i', $projection) === 1 || preg_match(self::AGGREGATE_PATTERN, $maskedProjection) === 1) {
            return null;
        }

        $cap = $cellLimit !== null && $cellLimit > 0 ? '[0..' . ($cellLimit + 1) . ']' : '';
        $with = [];
        $returnItems = [];
        $aliases = [];
        $anchorColumns = [];
        $collectedColumns = [];
        $joinedUse = [];
        $aggregated = 0;
        foreach (self::splitTopLevel($projection) as $item) {
            if ($item === '*') {
                return null;
            }
            [$expression, $alias] = self::splitAlias($item);
            $references = self::references($expression);
            if (array_diff($references, $variables) !== []) {
                return null;
            }

            $column = $alias !== null ? trim($alias, '`') : $expression;
            if ($alias !== null) {
                $aliases[] = $column;
            }
            if (array_diff($references, [$anchor]) === []) {
                $returnItems[] = $item;
                $anchorColumns[] = $column;
                continue;
            }

            // A joined record's id projection: a list of ids is no record id.
            if ($alias !== null && preg_match('/_id`?$/i', $alias) === 1
                && preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $expression) === 1) {
                array_pop($aliases);
                continue;
            }

            // I3: a joined node / relationship / path, or a map, has no scalar cell.
            if (in_array($expression, $variables, true) || str_contains(self::mask($expression), '{')) {
                return null;
            }

            // I2: two columns of one joined record would be collected apart.
            foreach (array_diff($references, [$anchor]) as $joined) {
                $joinedUse[$joined] = ($joinedUse[$joined] ?? 0) + 1;
                if ($joinedUse[$joined] > 1) {
                    return null;
                }
            }

            // The grouping alias is the column's own name when it is a plain
            // identifier free of every pattern variable (a recorded query the
            // generator later EXTENDS stays readable); otherwise an internal one.
            $aggregated++;
            $collectedColumns[] = $column;
            if ($alias !== null && preg_match('/^[\p{L}_][\p{L}\p{N}_]*$/u', $alias) === 1
                && !in_array($alias, $variables, true)) {
                $with[] = "collect(DISTINCT {$expression}){$cap} AS {$alias}";
                $returnItems[] = $alias;
                continue;
            }
            $internal = '__fo' . $aggregated;
            $with[] = "collect(DISTINCT {$expression}){$cap} AS {$internal}";
            $returnItems[] = $internal . ' AS ' . ($alias ?? '`' . str_replace('`', '', $expression) . '`');
        }

        // S6: after `WITH anchor, …` only the anchor and the RETURN aliases are in
        // scope — an unaliased joined column (`ORDER BY c.email`) or an alias that
        // shadows a pattern variable (`c.email AS c … ORDER BY c.email`) is not.
        if ($orderBy !== null && $orderBy !== '') {
            $allowed = array_merge([$anchor], array_diff($aliases, $variables));
            foreach (self::splitTopLevel($orderBy) as $orderItem) {
                $orderExpression = trim((string) preg_replace('/\s+(ASC|DESC|ASCENDING|DESCENDING)\s*$/i', '', $orderItem));
                if (in_array(trim($orderExpression, '`'), $aliases, true)) {
                    continue;
                }
                if (array_diff(self::references($orderExpression), $allowed) !== []) {
                    return null;
                }
            }
        }

        $grouping = $aggregated === 0
            ? "WITH DISTINCT {$anchor}"
            : 'WITH ' . implode(', ', array_merge([$anchor], $with));

        return [
            'query' => rtrim($head)
                . ' ' . $grouping
                . " WHERE {$anchor} IS NOT NULL"
                . ' RETURN ' . implode(', ', $returnItems)
                . ($orderBy !== null && $orderBy !== '' ? ' ORDER BY ' . $orderBy : '')
                . ($limit !== null ? ' ' . $limit : ''),
            'anchor' => $anchor,
            'anchor_columns' => $anchorColumns,
            'collected_columns' => $collectedColumns,
            'cell_limit' => $cap !== '' ? $cellLimit : null,
        ];
    }

    /**
     * Whether $rows (a re-run of plan()['query']) really is one row per anchor:
     * every collected column holds a list of scalars (or null) — the grouping
     * projection ran — and the anchor columns' value tuples are pairwise
     * distinct. Conservative: two anchors with identical displayed values (and no
     * id column) fail the check; the caller then keeps the original rows.
     *
     * @param array<int, mixed> $rows
     * @param array{anchor_columns: array<int, string>, collected_columns: array<int, string>} $plan
     */
    public static function isCollapsed(array $rows, array $plan): bool
    {
        if ($rows === [] || $plan['anchor_columns'] === []) {
            return false;
        }

        $tuples = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return false;
            }
            foreach ($plan['collected_columns'] as $column) {
                if (!array_key_exists($column, $row)) {
                    return false;
                }
                $value = $row[$column];
                if ($value === null) {
                    continue;
                }
                if (!is_array($value) || !array_is_list($value)) {
                    return false;
                }
                foreach ($value as $member) {
                    if ($member !== null && !is_scalar($member)) {
                        return false;
                    }
                }
            }
            $tuple = [];
            foreach ($plan['anchor_columns'] as $column) {
                if (!array_key_exists($column, $row)) {
                    return false;
                }
                $tuple[] = self::scalarString($row[$column]);
            }
            $tuples[implode("\x1F", $tuple)] = true;
        }

        return count($tuples) === count($rows);
    }

    /**
     * The cell limit a plan() query was built with (its `collect(DISTINCT …)[0..N+1]`
     * slices), or null for any other query.
     */
    public static function cellLimitOf(string $cypher): ?int
    {
        $canonical = self::canonical($cypher);
        if ($canonical === null
            || preg_match('/\bcollect\s*\(\s*DISTINCT\b.*?\)\s*\[\s*0\s*\.\.\s*(\d+)\s*\]\s+AS\b/is', self::mask($canonical), $m) !== 1) {
            return null;
        }
        $limit = (int) $m[1] - 1;

        return $limit > 0 ? $limit : null;
    }

    /**
     * Whether the query has a UNION clause — never a `:CreditUnion` label or a
     * `union_count` alias (same rule as RecordIdProjection), never inside a
     * string or a comment. Unanalyzable text counts as a UNION (refuse).
     */
    public static function hasUnionClause(string $cypher): bool
    {
        $canonical = self::canonical($cypher);

        return $canonical === null || self::hasUnionClauseMasked(self::mask($canonical));
    }

    /**
     * Whether $anchor is filtered against a VALUE — conservative (S2): any WHERE
     * predicate whose one side references the anchor (through any function
     * wrapping: `toLower(u.number)`, `id(u)`) and whose other side references no
     * variable (a literal, `$param`, list, `toInteger($x)` …) — `=`, `<>`, `<`,
     * `>`, `IN`, `STARTS WITH`, `CONTAINS` … alike; or an inline property map
     * `(v:Label {…})`. Not a pin: a comparison with another variable (a join),
     * a test against true / false / null, `IS [NOT] NULL`, a pattern predicate,
     * and `IN $ai_team_ids` (the tenant scoping parameter). Unanalyzable text is
     * pinned (no collapse).
     */
    public static function isPinned(string $cypher, string $anchor): bool
    {
        $canonical = self::canonical($cypher);
        if ($canonical === null) {
            return true;
        }
        $masked = self::mask($canonical);
        $a = preg_quote($anchor, '/');

        if (preg_match('/\(\s*' . $a . '\s*(?::\s*[\w`|&!%]+\s*)*\{/', $masked) === 1) {
            return true;
        }

        // `STARTS WITH` / `ENDS WITH` are operators, never the WITH clause.
        $masked = (string) preg_replace_callback(
            '/' . self::KW . '\b(STARTS|ENDS)(\s+)(WITH)\b/i',
            static fn (array $m): string => $m[1] . str_repeat('_', strlen($m[2])) . $m[3],
            $masked
        );

        preg_match_all(
            '/' . self::KW . '\bWHERE\b(.*?)(?=' . self::KW . '\b(?:MATCH|OPTIONAL|WITH|RETURN|UNWIND|CALL|ORDER|SKIP|LIMIT|UNION|MERGE|CREATE|SET|DELETE|DETACH|REMOVE|FOREACH)\b|$)/is',
            $masked,
            $segments
        );
        foreach ($segments[1] as $segment) {
            foreach (self::predicateAtoms($segment) as $atom) {
                if (self::atomPinsAnchor($atom, $anchor)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The export re-run of $cypher without its DISPLAY cap, bounded by an
     * EXPLICIT `LIMIT $documentLimit` (I1: the executor appends its own limit
     * only when no `LIMIT` word appears anywhere — a subquery LIMIT, a 'limit'
     * string or c.limit made the stripped query unbounded). Only the FINAL
     * top-level LIMIT is considered: one >= the display cap (the value the system
     * itself imposes — the generator's habitual `ORDER BY u.name LIMIT 100`) is
     * the DISPLAY limit and is widened, any ORDER BY kept; LIMITs inside
     * subqueries / WITH clauses are untouched. Null = do not widen: a trailing
     * LIMIT below the display cap (S3: the user's top-N), any SKIP in the final
     * projection (pagination), a LIMIT that is not a plain number, a final
     * RETURN inside a subquery, unanalyzable text.
     */
    public static function uncappedQuery(string $cypher, int $displayCap, int $documentLimit): ?string
    {
        $cypher = self::canonical($cypher);
        if ($cypher === null || $cypher === '') {
            return null;
        }
        $masked = self::mask($cypher);
        $returnPos = self::finalReturnOffset($masked);
        if ($returnPos === null) {
            return null;
        }
        $maskedTail = substr($masked, $returnPos);
        if (preg_match('/' . self::KW . '\bSKIP\b/i', $maskedTail) === 1) {
            return null;
        }

        $base = $cypher;
        if (preg_match('/' . self::KW . '\bLIMIT\s+(\d+)\s*$/i', $masked, $m, PREG_OFFSET_CAPTURE) === 1) {
            if ((int) $m[1][0] < $displayCap) {
                return null;
            }
            $base = rtrim(substr($cypher, 0, (int) $m[0][1]));
        }
        if (preg_match('/' . self::KW . '\bLIMIT\b/i', substr(self::mask($base), $returnPos)) === 1) {
            return null;
        }

        return $base . ' LIMIT ' . max(1, $documentLimit);
    }

    /**
     * Every list value (a collect() column) rendered as its distinct, non-empty
     * scalar members joined with JOIN_SEPARATOR; an empty list becomes null. Map
     * values (nodes, objects) are left untouched. With $cellLimit, a list of more
     * than $cellLimit members shows the first $cellLimit followed by
     * TRUNCATION_MARKER; $truncated counts those cells.
     *
     * @param array<int, mixed> $rows
     * @return array<int, mixed>
     */
    public static function joinLists(array $rows, ?int $cellLimit = null, ?int &$truncated = null): array
    {
        $truncated = 0;
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                if (is_array($value) && array_is_list($value)) {
                    $cut = $cellLimit !== null && $cellLimit > 0 && count($value) > $cellLimit;
                    $joined = self::joinValues($cut ? array_slice($value, 0, $cellLimit) : $value);
                    if ($cut) {
                        $truncated++;
                        $joined = ($joined !== null ? $joined . self::JOIN_SEPARATOR : '') . self::TRUNCATION_MARKER;
                    }
                    $rows[$i][$key] = $joined;
                }
            }
        }

        return $rows;
    }

    /**
     * Rows grouped by the values of $keyColumns (normalized: trimmed, case-
     * insensitive; column keys matched case-insensitively). Each group keeps its
     * first row's key values; every other column carries the group's distinct
     * non-empty values — the single value as-is, several joined with
     * JOIN_SEPARATOR, none as null. Group order = first-seen order.
     *
     * $identityColumn (I4): the record's id column — part of the key, so two
     * distinct records with equal displayed values never merge; a row without an
     * id is never merged with any other.
     *
     * @param array<int, mixed> $rows
     * @param array<int, string> $keyColumns normalized (lower-cased) column keys
     * @return array<int, array<string, mixed>>
     */
    public static function collapseByColumns(array $rows, array $keyColumns, ?string $identityColumn = null): array
    {
        $keyColumns = array_map(static fn (string $c): string => strtolower(trim($c)), $keyColumns);
        $identity = $identityColumn !== null ? strtolower(trim($identityColumn)) : null;
        if ($identity !== null && !in_array($identity, $keyColumns, true)) {
            $keyColumns[] = $identity;
        }
        /** @var array<string, array{names: array<string, string>, keys: array<string, mixed>, values: array<string, array<string, mixed>>}> $groups */
        $groups = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = [];
            $names = [];
            foreach ($row as $key => $value) {
                $k = strtolower(trim((string) $key));
                $normalized[$k] = $value;
                $names[$k] = (string) $key;
            }

            $tuple = implode("\x1F", array_map(
                static fn (string $c): string => mb_strtolower(trim(self::scalarString($normalized[$c] ?? null))),
                $keyColumns
            ));
            if ($identity !== null && trim(self::scalarString($normalized[$identity] ?? null)) === '') {
                $tuple = "\x1E" . $index; // no id: never merged
            }

            if (!isset($groups[$tuple])) {
                $groups[$tuple] = ['names' => $names, 'keys' => [], 'values' => []];
                foreach ($keyColumns as $c) {
                    $groups[$tuple]['keys'][$c] = $normalized[$c] ?? null;
                }
            }

            foreach ($normalized as $k => $value) {
                if (in_array($k, $keyColumns, true)) {
                    continue;
                }
                $groups[$tuple]['names'][$k] ??= $names[$k];
                $groups[$tuple]['values'][$k] ??= [];
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }
                $groups[$tuple]['values'][$k][self::scalarString($value)] ??= $value;
            }
        }

        $collapsed = [];
        foreach ($groups as $group) {
            $out = [];
            foreach ($group['names'] as $k => $name) {
                if (array_key_exists($k, $group['keys'])) {
                    $out[$name] = $group['keys'][$k];
                    continue;
                }
                $values = array_values($group['values'][$k] ?? []);
                $out[$name] = match (count($values)) {
                    0 => null,
                    1 => $values[0],
                    default => implode(self::JOIN_SEPARATOR, array_map([self::class, 'scalarString'], $values)),
                };
            }
            $collapsed[] = $out;
        }

        return $collapsed;
    }

    /**
     * The id column RecordIdProjection projects for a $label record
     * (`v.id AS <snake label>_id`).
     */
    public static function idColumnFor(string $label): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $label)) . '_id';
    }

    /**
     * The comment-stripped canonical text (CypherCanonicalizer), trimmed and
     * without a trailing `;` — or null when it is not canonicalizable.
     */
    public static function canonical(string $cypher): ?string
    {
        $canonical = (new CypherCanonicalizer())->canonicalize($cypher)['query'];

        return $canonical === null ? null : rtrim(trim($canonical), "; \t\n\r");
    }

    /**
     * String literal and backtick identifier CONTENTS replaced by same-length
     * filler (delimiters and offsets preserved), so no quoted word is ever read
     * as a keyword. Apply to canonical() text (comments already removed).
     */
    public static function mask(string $cypher): string
    {
        return (string) preg_replace_callback(
            '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`(?:[^`]|``)*`/s',
            static fn (array $m): string => $m[0][0] . str_repeat('x', max(0, strlen($m[0]) - 2)) . substr($m[0], -1),
            $cypher
        );
    }

    /**
     * Byte offset of the final RETURN keyword of masked canonical text, or null
     * when there is none or it is not top-level (inside a CALL { } subquery).
     */
    public static function finalReturnOffset(string $masked): ?int
    {
        if (preg_match_all('/' . self::KW . '\bRETURN\b/i', $masked, $returns, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }
        $offset = (int) end($returns[0])[1];
        $head = substr($masked, 0, $offset);

        return substr_count($head, '{') === substr_count($head, '}') ? $offset : null;
    }

    /**
     * The column names a query's final RETURN produces, in order — each item's
     * alias (unquoted), else the item text as Neo4j names it — or [] when there
     * is no top-level final RETURN (or it is `*`). Structural only: used to name
     * the fields a query that never produced rows asked for (eval p12-1001-A: a
     * failed export extension).
     *
     * @return array<int, string>
     */
    public static function returnColumns(string $cypher): array
    {
        return array_column(self::returnItems($cypher), 'column');
    }

    /**
     * The items of a query's final RETURN, in order: the column name Neo4j gives the
     * item (its alias unquoted, else the item text), the expression as written, and
     * whether it carries an alias — or [] when there is no top-level final RETURN, it
     * is `*`, or the query is a UNION (one column, several expressions). Structural
     * only: THE parse of "what does each result column project".
     *
     * @return list<array{column: string, expression: string, aliased: bool}>
     */
    public static function returnItems(string $cypher): array
    {
        $cypher = self::canonical($cypher);
        if ($cypher === null || self::hasUnionClause($cypher)) {
            return [];
        }
        $masked = self::mask($cypher);
        $returnPos = self::finalReturnOffset($masked);
        if ($returnPos === null) {
            return [];
        }

        $maskedTail = substr($masked, $returnPos + strlen('RETURN'));
        $end = preg_match('/' . self::KW . '\b(ORDER\s+BY|SKIP|LIMIT)\b/i', $maskedTail, $clause, PREG_OFFSET_CAPTURE) === 1
            ? (int) $clause[0][1]
            : strlen($maskedTail);
        $projection = trim((string) preg_replace('/^\s*DISTINCT\b/i', '', substr($cypher, $returnPos + strlen('RETURN'), $end)));

        $items = [];
        foreach (self::splitTopLevel($projection) as $item) {
            if ($item === '*') {
                return [];
            }
            [$expression, $alias] = self::splitAlias($item);
            $items[] = [
                'column' => $alias !== null ? str_replace('``', '`', trim($alias, '`')) : $expression,
                'expression' => $expression,
                'aliased' => $alias !== null,
            ];
        }

        return $items;
    }

    /**
     * Top-level comma split (ignores commas inside (), [], {} and string literals).
     *
     * @return array<int, string>
     */
    public static function splitTopLevel(string $list): array
    {
        $items = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($list);
        for ($i = 0; $i < $length; $i++) {
            $char = $list[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $current .= $list[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $items[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $items[] = trim($current);
        }

        return $items;
    }

    private static function hasUnionClauseMasked(string $masked): bool
    {
        return preg_match('/\sUNION(?:\s+ALL)?\s+(?:MATCH|OPTIONAL|WITH|UNWIND|CALL|RETURN)\b/i', $masked) === 1;
    }

    /**
     * The boolean atoms of a (masked) WHERE body: split at depth-0 AND / OR /
     * XOR, a leading NOT dropped, a fully parenthesized atom split again.
     *
     * @return array<int, string>
     */
    private static function predicateAtoms(string $expression): array
    {
        $expression = trim($expression);
        while (preg_match('/^NOT\b/i', $expression) === 1) {
            $expression = trim(substr($expression, 3));
        }
        if ($expression === '') {
            return [];
        }
        if ($expression[0] === '(' && self::closingParen($expression, 0) === strlen($expression) - 1) {
            return self::predicateAtoms(substr($expression, 1, -1));
        }

        $depths = self::depths($expression);
        preg_match_all('/' . self::KW . '\b(?:AND|OR|XOR)\b/i', $expression, $operators, PREG_OFFSET_CAPTURE);
        $atoms = [];
        $start = 0;
        foreach ($operators[0] as [$word, $offset]) {
            if ($depths[$offset] !== 0) {
                continue;
            }
            $atoms[] = substr($expression, $start, $offset - $start);
            $start = $offset + strlen($word);
        }
        if ($start === 0) {
            return [$expression];
        }
        $atoms[] = substr($expression, $start);

        $flat = [];
        foreach ($atoms as $atom) {
            foreach (self::predicateAtoms($atom) as $inner) {
                $flat[] = $inner;
            }
        }

        return $flat;
    }

    /** Whether one comparison atom filters $anchor against a value (see isPinned()). */
    private static function atomPinsAnchor(string $atom, string $anchor): bool
    {
        $depths = self::depths($atom);
        preg_match_all(
            '/=~|<>|<=|>=|(?<![-<>])=(?!~)|<(?![-=>])|(?<![-=])>(?!=)|' . self::KW . '\b(?:IN|CONTAINS|STARTS_+WITH|ENDS_+WITH)\b/i',
            $atom,
            $operators,
            PREG_OFFSET_CAPTURE
        );
        foreach ($operators[0] as [$operator, $offset]) {
            if ($depths[$offset] !== 0) {
                continue;
            }
            $left = trim(substr($atom, 0, $offset));
            $right = trim(substr($atom, $offset + strlen($operator)));

            return (in_array($anchor, self::references($left), true) && self::isValueSide($right))
                || (in_array($anchor, self::references($right), true) && self::isValueSide($left));
        }

        return false;
    }

    /** A comparison side free of variables that can single out records. */
    private static function isValueSide(string $side): bool
    {
        if ($side === '' || self::references($side) !== []) {
            return false;
        }
        if (preg_match('/^(?:true|false|null)$/i', $side) === 1) {
            return false;
        }

        return !TeamPathResolver::isScopeParameter($side);
    }

    /**
     * The (), [], {} nesting depth at every byte of $expression (masked text:
     * no quotes to track).
     *
     * @return array<int, int>
     */
    private static function depths(string $expression): array
    {
        $depths = [];
        $depth = 0;
        $length = strlen($expression);
        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];
            if ($char === ')' || $char === ']' || $char === '}') {
                $depth = max(0, $depth - 1);
            }
            $depths[$i] = $depth;
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            }
        }

        return $depths;
    }

    /** Offset of the parenthesis closing the one at $open, or -1. */
    private static function closingParen(string $expression, int $open): int
    {
        $depth = 0;
        $length = strlen($expression);
        for ($i = $open; $i < $length; $i++) {
            if ($expression[$i] === '(') {
                $depth++;
            } elseif ($expression[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /** @param array<int, mixed> $values */
    private static function joinValues(array $values): ?string
    {
        $distinct = [];
        foreach ($values as $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $string = trim(self::scalarString($value));
            if ($string !== '') {
                $distinct[$string] = true;
            }
        }

        return $distinct === [] ? null : implode(self::JOIN_SEPARATOR, array_keys($distinct));
    }

    private static function scalarString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Node and relationship variables bound in MATCH / OPTIONAL MATCH / MERGE
     * patterns of the (masked) query head. A backticked variable is never bound
     * here (its references are then unknown → not rewritable).
     *
     * @return array<int, string>
     */
    private static function patternVariables(string $maskedHead): array
    {
        $variables = [];
        preg_match_all(
            '/\bMATCH\b(.*?)(?=\b(?:WHERE|WITH|RETURN|UNWIND|CALL|OPTIONAL|MATCH|ORDER|SKIP|LIMIT|MERGE|CREATE)\b|$)/is',
            $maskedHead,
            $segments
        );
        foreach ($segments[1] as $segment) {
            preg_match_all('/\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*[:){]/', $segment, $nodes);
            preg_match_all('/\[\s*([A-Za-z_][A-Za-z0-9_]*)\s*[:\]*{]/', $segment, $relationships);
            preg_match_all('/(?:^|,)\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:shortestPath|allShortestPaths)?\s*\(/i', $segment, $paths);
            foreach (array_merge($nodes[1], $relationships[1], $paths[1]) as $variable) {
                $variables[$variable] = true;
            }
        }

        return array_keys($variables);
    }

    /**
     * Variable references of an expression: identifiers left after removing string
     * literals, backticked property names, parameters, function names, property
     * accesses, map keys and expression keywords. A backticked VARIABLE is a
     * reference (by its unquoted name).
     *
     * @return array<int, string>
     */
    private static function references(string $expression): array
    {
        $e = (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', ' ', $expression);
        $e = (string) preg_replace('/\.\s*`(?:[^`]|``)*`/', ' ', $e);
        $references = [];
        preg_match_all('/`((?:[^`]|``)*)`/', $e, $backticked);
        foreach ($backticked[1] as $name) {
            $references[str_replace('``', '`', $name)] = true;
        }
        $e = (string) preg_replace('/`(?:[^`]|``)*`/', ' ', $e);
        $e = (string) preg_replace('/\$[A-Za-z_][A-Za-z0-9_]*/', ' ', $e);
        $e = (string) preg_replace('/[A-Za-z_][A-Za-z0-9_.]*\s*\(/', '(', $e);
        $e = (string) preg_replace('/\.\s*[A-Za-z_][A-Za-z0-9_]*/', ' ', $e);
        $e = (string) preg_replace('/[A-Za-z_][A-Za-z0-9_]*\s*:/', ' ', $e);

        preg_match_all('/(?<![\w])[A-Za-z_][A-Za-z0-9_]*/', $e, $identifiers);
        foreach ($identifiers[0] as $identifier) {
            if (!in_array(strtolower($identifier), self::EXPRESSION_KEYWORDS, true)) {
                $references[$identifier] = true;
            }
        }

        return array_map('strval', array_keys($references));
    }

    /** @return array{0: string, 1: ?string} [expression, alias (as written, may be backticked)] */
    private static function splitAlias(string $item): array
    {
        // Unicode letters are valid in unquoted Cypher identifiers (eval p13-B: `AS rôle`).
        if (preg_match('/^(.*\S)\s+AS\s+(`[^`]+`|[\p{L}_][\p{L}\p{N}_]*)\s*$/isu', $item, $m) === 1) {
            return [trim($m[1]), $m[2]];
        }

        return [trim($item), null];
    }
}
