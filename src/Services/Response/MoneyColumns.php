<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Discovery\EntityLabelIndex;

/**
 * MoneyColumns — which RESULT columns carry a declared money property (P-3,
 * Phase 8-A review).
 *
 * The deterministic data answer states system-computed totals; summing "any
 * decimal column" summed rates, unit prices, shares and tax rates. Now a column is
 * summable only when every property it derives from is declared money in the
 * entity config:
 *
 *   'metadata' => ['money_properties' => ['due_amount', 'total_amount']]
 *
 * (a separate list because metadata.common_properties entries are plain
 * description strings; graph.computed money accessors are listed here too).
 *
 * Columns map to properties through the cypher's projections only: `v.prop AS a`
 * (any function wrapping, e.g. round(sum(v.prop), 2)), aliases carried through
 * WITH chains, and the keys of collected map literals ({k: expr}). An expression
 * mixing a non-money source (b.due_amount * t.rate), a count, or anything not
 * resolvable is NOT money — when unknown, nothing is summed.
 */
final class MoneyColumns
{
    private const CLAUSE_END = 'MATCH|OPTIONAL|WHERE|WITH|RETURN|ORDER\s+BY|SKIP|LIMIT|UNWIND|CALL|UNION|CREATE|MERGE|SET|DELETE|DETACH|FOREACH';

    /**
     * @return array<int, string> money column names as result keys (aliases, unaliased `v.prop`, collected map keys)
     */
    public static function fromCypher(?string $cypher): array
    {
        $cypher = trim((string) $cypher);
        if ($cypher === '') {
            return [];
        }

        // String literals never carry structure; STARTS/ENDS WITH is not a WITH clause.
        $cypher = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $cypher);
        $cypher = (string) preg_replace('/\b(STARTS|ENDS)\s+WITH\b/i', '$1_WITH', $cypher);

        $labels = [];
        if (preg_match_all('/\(\s*([A-Za-z_]\w*)\s*:\s*`?([A-Za-z_]\w*)`?/', $cypher, $m, PREG_SET_ORDER)) {
            foreach ($m as $node) {
                $labels[$node[1]] ??= $node[2];
            }
        }

        $aliasMoney = [];
        $mapKeys = [];
        preg_match_all('/\b(WITH|RETURN)\b(.*?)(?=\b(?:' . self::CLAUSE_END . ')\b|$)/is', $cypher, $clauses, PREG_SET_ORDER);
        foreach ($clauses as $clause) {
            $body = trim((string) preg_replace('/^DISTINCT\b/i', '', trim($clause[2])));
            foreach (self::splitTopLevel($body) as $item) {
                $item = trim($item);
                if ($item === '' || $item === '*') {
                    continue;
                }

                // The alias in the user's language: `AS coût` is as much a column as `AS cost`.
                if (preg_match('/^(.*)\s+AS\s+`?([\p{L}_][\p{L}\p{N}_]*)`?\s*$/isu', $item, $as)) {
                    [$expr, $alias] = [trim($as[1]), $as[2]];
                } elseif (preg_match('/^([A-Za-z_]\w*)\.([A-Za-z_]\w*)$/', $item, $prop)) {
                    // Unaliased `v.prop`: Neo4j keys the result column by the expression
                    // text itself ("v.prop"), not the bare property — naming it "prop"
                    // never matched a result column, so unaliased money was never summed.
                    [$expr, $alias] = [$item, $item];
                } else {
                    continue; // bare pass-through keeps its earlier resolution
                }

                // Collected map literals: each key resolves on its own expression.
                $flat = (string) preg_replace_callback('/\{([^{}]*)\}/', function (array $map) use ($labels, &$aliasMoney, &$mapKeys): string {
                    foreach (self::splitTopLevel($map[1]) as $entry) {
                        if (preg_match('/^\s*([A-Za-z_]\w*)\s*:\s*(.+)$/s', $entry, $kv)) {
                            $mapKeys[$kv[1]] = self::isMoney(trim($kv[2]), $labels, $aliasMoney);
                        }
                    }

                    return '{}';
                }, $expr);

                $aliasMoney[$alias] = self::isMoney($flat, $labels, $aliasMoney);
            }
        }

        $money = [];
        foreach ($aliasMoney + $mapKeys as $name => $isMoney) {
            if ($isMoney) {
                $money[] = (string) $name;
            }
        }
        foreach ($mapKeys as $name => $isMoney) {
            if ($isMoney && !in_array($name, $money, true)) {
                $money[] = (string) $name;
            }
        }

        return array_values(array_unique($money));
    }

    /**
     * Money iff the expression has at least one source and EVERY source is money:
     * `v.prop` whose label declares prop in money_properties, or an earlier alias
     * already resolved as money. A count() is never money.
     *
     * @param array<string, string> $labels     node variable => label
     * @param array<string, bool>   $aliasMoney alias => resolved money flag
     */
    private static function isMoney(string $expr, array $labels, array $aliasMoney): bool
    {
        if (preg_match('/\bcount\s*\(/i', $expr)) {
            return false;
        }

        $sources = [];
        if (preg_match_all('/\b([A-Za-z_]\w*)\.([A-Za-z_]\w*)\b/', $expr, $props, PREG_SET_ORDER)) {
            foreach ($props as $p) {
                $label = $labels[$p[1]] ?? null;
                $sources[] = $label !== null && in_array($p[2], self::moneyProperties($label), true);
            }
        }
        $withoutProps = (string) preg_replace('/\b[A-Za-z_]\w*\.[A-Za-z_]\w*\b/', ' ', $expr);
        if (preg_match_all('/\b([A-Za-z_]\w*)\b(?!\s*[(.:])/', $withoutProps, $idents)) {
            foreach ($idents[1] as $ident) {
                if (array_key_exists($ident, $aliasMoney)) {
                    $sources[] = $aliasMoney[$ident];
                }
            }
        }

        return $sources !== [] && !in_array(false, $sources, true);
    }

    /** @return array<int, string> */
    private static function moneyProperties(string $label): array
    {
        try {
            $config = EntityLabelIndex::shared()->configForLabel($label) ?? [];
        } catch (\Throwable $e) {
            return [];
        }

        $list = $config['metadata']['money_properties'] ?? [];

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /**
     * Split on commas outside (), [], {}.
     *
     * @return array<int, string>
     */
    private static function splitTopLevel(string $text): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $parts[] = $current;

        return $parts;
    }
}
