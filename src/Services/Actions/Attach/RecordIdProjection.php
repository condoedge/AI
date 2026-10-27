<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\Attach;

/**
 * RecordIdProjection — record rows carry their id (actions plan B1).
 *
 * Code attaches each result record's link from its id; a record row without its id gets
 * no chip. The QueryRules line asks the generator to project ids, but measured live
 * (2026-10-01) gpt-4o-mini kept copying its few-shot examples without them. This
 * deterministic, purely STRUCTURAL projection closes the gap: for every labelled node
 * variable the FINAL `RETURN` uses as a record — a property read OUTSIDE any aggregate,
 * and the variable never appears inside an aggregate — it appends `v.id AS <label>_id`.
 *
 * Adding a record's id to a projection that already groups by that record's properties
 * never merges rows and only splits rows that were different records sharing the same
 * values (more correct). It is never applied to: aggregated variables (`i.status,
 * count(i)` stays a breakdown), `RETURN *`, UNION / CALL {} queries, a variable whose id
 * is already projected, or an alias that would collide. MATCH / WHERE (tenant scoping)
 * are never touched. The `<label>_id` column is an internal id: tables and exports drop
 * it (ResultSetExporter `*_id` redaction); RecordResolver / EntityIdIndex attribute it.
 */
final class RecordIdProjection
{
    /** Cypher's aggregating functions (language grammar, not vocabulary). */
    private const AGGREGATES = 'count|sum|avg|min|max|collect|stdev|stdevp|percentilecont|percentiledisc';

    /** A clause keyword: never a property (`u.limit`), part of a name, or a backtick name. */
    private const KW = '(?<![.\w`$])\b';

    /** @var array<string, string> normalized label => label */
    private array $labels = [];

    private string $idField;

    /** @var list<string> */
    private array $idFields;

    /**
     * @param list<string> $labels   entity labels whose records can carry a link
     * @param list<string>|null $idFields ai.entity_id_fields (null = config)
     */
    public function __construct(array $labels, ?array $idFields = null)
    {
        foreach ($labels as $label) {
            if (is_string($label) && self::normalize($label) !== '') {
                $this->labels[self::normalize($label)] = $label;
            }
        }
        $fields = array_values(array_filter($idFields ?? (self::config('ai.entity_id_fields') ?? ['id']), 'is_string'));
        $this->idFields = $fields !== [] ? $fields : ['id'];
        $this->idField = in_array('id', $this->idFields, true) ? 'id' : $this->idFields[0];
    }

    public function apply(string $cypher): string
    {
        return $this->project($cypher)['cypher'];
    }

    /**
     * The projected cypher + whether a record variable was AMBIGUOUS (two labels in scope,
     * a multi-label node) and therefore left without an id — the caller then keeps the
     * generator's original for few-shot storage (fix 9).
     *
     * @return array{cypher: string, ambiguous: bool}
     */
    public function project(string $cypher): array
    {
        $unchanged = ['cypher' => $cypher, 'ambiguous' => false];
        if ($this->labels === [] || trim($cypher) === '') {
            return $unchanged;
        }

        $masked = self::mask($cypher);

        // The UNION clause (never a label or alias named like it) / a CALL {} subquery.
        if (preg_match('/\sUNION(?:\s+ALL)?\s+(?:MATCH|OPTIONAL|WITH|UNWIND|CALL|RETURN)\b|' . self::KW . 'CALL\s*\{/i', $masked) === 1) {
            return $unchanged;
        }

        if (preg_match_all('/' . self::KW . 'RETURN\b/i', $masked, $returns, PREG_OFFSET_CAPTURE) < 1) {
            return $unchanged;
        }
        $returnAt = (int) end($returns[0])[1];
        $scope = $this->scopeAt(substr($masked, 0, $returnAt));

        $bodyStart = $returnAt + strlen('RETURN');
        if (preg_match('/^\s+DISTINCT\b/i', substr($masked, $bodyStart), $distinct) === 1) {
            $bodyStart += strlen($distinct[0]);
        }
        $rest = substr($masked, $bodyStart);
        $bodyLength = preg_match('/' . self::KW . '(?:ORDER\s+BY|SKIP|LIMIT)\b/i', $rest, $tail, PREG_OFFSET_CAPTURE) === 1
            ? (int) $tail[0][1]
            : strlen($rest);
        $body = rtrim(substr($rest, 0, $bodyLength), " \t\r\n;");
        if ($body === '' || str_starts_with(ltrim($body), '*')) {
            return $unchanged;
        }

        $spans = $this->aggregateSpans($body);
        $aggregated = [];
        foreach ($spans as [$from, $to]) {
            if (preg_match_all('/\b([A-Za-z_]\w*)\b/', substr($body, $from, $to - $from), $ids)) {
                foreach ($ids[1] as $identifier) {
                    $aggregated[$identifier] = true;
                }
            }
        }

        $records = [];
        $hasId = [];
        if (preg_match_all('/\b([A-Za-z_]\w*)\.([A-Za-z_]\w*)\b/', $body, $refs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($refs as $ref) {
                [$variable, $offset] = $ref[1];
                if (in_array($ref[2][0], $this->idFields, true)) {
                    $hasId[$variable] = true;
                }
                if (!$this->inSpans((int) $offset, $spans) && !in_array($variable, $records, true)) {
                    $records[] = $variable;
                }
            }
        }

        $additions = [];
        $ambiguous = false;
        foreach ($records as $variable) {
            if (!array_key_exists($variable, $scope) || isset($aggregated[$variable]) || isset($hasId[$variable])) {
                continue; // not a bound node in scope (alias, comprehension variable), aggregated, or identified
            }
            $labels = array_values(array_unique($scope[$variable]));
            if (count($labels) !== 1) {
                $ambiguous = $ambiguous || count($labels) > 1;
                continue; // unlabelled, or two labels: never guess which entity this is
            }
            $label = $this->labels[self::normalize($labels[0])] ?? null;
            if ($label === null) {
                continue;
            }
            $alias = self::snake($label) . '_id';
            if (preg_match('/\bAS\s+`?' . preg_quote($alias, '/') . '`?(?!\w)/i', $body) === 1) {
                continue;
            }
            $additions[] = "{$variable}.{$this->idField} AS {$alias}";
        }

        if ($additions === []) {
            return ['cypher' => $cypher, 'ambiguous' => $ambiguous];
        }

        $insertAt = $bodyStart + strlen($body);

        return [
            'cypher' => substr($cypher, 0, $insertAt) . ', ' . implode(', ', $additions) . substr($cypher, $insertAt),
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * The node variables in scope right before the final RETURN, with every label bound to
     * them while they stayed in scope. A WITH keeps only what it projects bare (`WITH u`,
     * `u AS u`, `*`); an alias (`u AS v`) is a new, unlabelled value; a variable dropped and
     * matched again starts afresh.
     *
     * @return array<string, list<string>>
     */
    private function scopeAt(string $beforeReturn): array
    {
        $parts = preg_split('/' . self::KW . 'WITH\b/i', $beforeReturn) ?: [$beforeReturn];
        $scope = [];
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $listLength = preg_match('/' . self::KW . '(?:MATCH|OPTIONAL|WHERE|UNWIND|ORDER\s+BY|SKIP|LIMIT|CALL)\b/i', $part, $next, PREG_OFFSET_CAPTURE) === 1
                    ? (int) $next[0][1]
                    : strlen($part);
                $scope = $this->carried(substr($part, 0, $listLength), $scope);
                $part = substr($part, $listLength);
            }
            $this->bind($part, $scope);
        }

        return $scope;
    }

    /**
     * @param array<string, list<string>> $scope
     * @return array<string, list<string>>
     */
    private function carried(string $list, array $scope): array
    {
        $list = trim((string) preg_replace('/^\s*DISTINCT\b/i', '', $list));
        $all = str_starts_with($list, '*');
        $kept = $all ? $scope : [];
        foreach (self::splitTopLevel($all ? ltrim(substr($list, 1), " \t\r\n,") : $list) as $item) {
            $item = trim($item);
            if (preg_match('/^([A-Za-z_]\w*)$/', $item, $m) === 1 || preg_match('/^([A-Za-z_]\w*)\s+AS\s+\1$/i', $item, $m) === 1) {
                if (array_key_exists($m[1], $scope)) {
                    $kept[$m[1]] = $scope[$m[1]];
                }
            } elseif (preg_match('/\bAS\s+([A-Za-z_]\w*)$/i', $item, $m) === 1) {
                $kept[$m[1]] = []; // a new value: never a labelled node
            }
        }

        return $kept;
    }

    /**
     * Record every `(v:Label…)` binding of a query part into $scope.
     *
     * @param array<string, list<string>> $scope
     */
    private function bind(string $part, array &$scope): void
    {
        if (!preg_match_all('/\(\s*([A-Za-z_]\w*)\s*((?::\s*`?[A-Za-z_]\w*`?\s*(?:\|\s*`?[A-Za-z_]\w*`?\s*)*)*)/', $part, $nodes, PREG_SET_ORDER)) {
            return;
        }
        foreach ($nodes as $node) {
            $variable = $node[1];
            preg_match_all('/[A-Za-z_]\w*/', str_replace('`', '', $node[2]), $labels);
            if (!array_key_exists($variable, $scope)) {
                $scope[$variable] = [];
            }
            foreach ($labels[0] as $label) {
                $scope[$variable][] = $label;
            }
        }
    }

    /**
     * The cypher with string literals, comments, backtick-quoted names (not labels) and the
     * `STARTS WITH` / `ENDS WITH` operators masked by same-length filler, so every offset
     * maps onto the original and no keyword is ever found inside them.
     */
    private static function mask(string $cypher): string
    {
        $fill = static fn (array $m) => $m[0][0] . str_repeat('x', max(0, strlen($m[0]) - 2)) . substr($m[0], -1);
        $masked = (string) preg_replace_callback("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/s", $fill, $cypher);
        $masked = (string) preg_replace_callback('#//[^\n]*|/\*.*?\*/#s', static fn (array $m) => str_repeat(' ', strlen($m[0])), $masked);
        $masked = (string) preg_replace_callback('/(?<!:)(?<!:\s)`[^`]*`/', $fill, $masked);

        return (string) preg_replace_callback('/\b(STARTS|ENDS)(\s+)WITH\b/i', static fn (array $m) => $m[1] . $m[2] . 'xxxx', $masked);
    }

    /**
     * Split on commas outside (), [] and {}.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $text): array
    {
        $items = [];
        $depth = 0;
        $current = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $items[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $items[] = $current;
        }

        return $items;
    }

    /**
     * [from, to) offsets of every aggregate call (function name through its closing paren).
     *
     * @return list<array{0: int, 1: int}>
     */
    private function aggregateSpans(string $body): array
    {
        $spans = [];
        if (!preg_match_all('/\b(?:' . self::AGGREGATES . ')\s*\(/i', $body, $calls, PREG_OFFSET_CAPTURE)) {
            return $spans;
        }
        foreach ($calls[0] as [$match, $offset]) {
            $depth = 0;
            $length = strlen($body);
            for ($i = (int) $offset + strlen($match) - 1; $i < $length; $i++) {
                if ($body[$i] === '(') {
                    $depth++;
                } elseif ($body[$i] === ')' && --$depth === 0) {
                    $spans[] = [(int) $offset, $i + 1];
                    break;
                }
            }
        }

        return $spans;
    }

    /** @param list<array{0: int, 1: int}> $spans */
    private function inSpans(int $offset, array $spans): bool
    {
        foreach ($spans as [$from, $to]) {
            if ($offset >= $from && $offset < $to) {
                return true;
            }
        }

        return false;
    }

    private static function snake(string $label): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $label));
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
