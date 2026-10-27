<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

/**
 * An aggregating final RETURN can only be ordered by what it projects: Neo4j refuses
 * "ORDER BY c.start_date" when c.start_date is not returned. The model writes it, and writes
 * it again when repaired, so code projects the property instead — only when the record's id
 * is returned (the groups then stay the same) and the query is a single RETURN (no UNION).
 */
final class AggregateOrderBy
{
    private const AGGREGATE = '/\b(?:sum|count|avg|min|max|collect)\s*\(/i';

    public static function fix(string $cypher): string
    {
        if (preg_match('/\bUNION\b/i', $cypher)
            || !preg_match('/^(.*\bRETURN\s+)(.+?)(\s+ORDER\s+BY\s+)(.+?)(\s+(?:SKIP|LIMIT)\b.*)?$/is', $cypher, $m)
            || !preg_match(self::AGGREGATE, $m[2])) {
            return $cypher;
        }
        [, $head, $items, $orderBy, $order, $tail] = $m + [5 => ''];

        $changed = false;
        $keys = [];
        foreach (explode(',', $order) as $key) {
            if (!preg_match('/^\s*([A-Za-z_]\w*)\.(\w+)(\s+(?:ASC|DESC|ASCENDING|DESCENDING))?\s*$/i', $key, $k)) {
                $keys[] = $key;
                continue;
            }
            [, $var, $property] = $k;
            $direction = $k[3] ?? '';
            if (preg_match('/\b' . preg_quote("{$var}.{$property}", '/') . '\s+AS\s+(\w+)/i', $items, $alias)) {
                $keys[] = ($keys === [] ? '' : ' ') . $alias[1] . $direction;
                $changed = true;
            } elseif (preg_match('/\b' . preg_quote($var, '/') . '\.id\b/', $items)) {
                $alias = "{$var}_{$property}";
                $items .= ", {$var}.{$property} AS {$alias}";
                $keys[] = ($keys === [] ? '' : ' ') . $alias . $direction;
                $changed = true;
            } else {
                $keys[] = $key;
            }
        }

        return $changed ? $head . $items . $orderBy . implode(',', $keys) . $tail : $cypher;
    }
}
