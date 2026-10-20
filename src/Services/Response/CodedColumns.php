<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Query\FanOutCollapse;
use Condoedge\Ai\Support\EnumLegend;

/**
 * CodedColumns — result columns that hold a CODE rather than a readable value
 * (p12 H3: the digest showed "Urgent 1", "Status 1/3", "Flag Color 2/3/4").
 *
 * Structural, over the cypher's RETURN projections only (`v.prop AS alias`, or an
 * unaliased `v.prop`, keyed by the expression as Neo4j keys it):
 *  - the projected property's entity config declares an enum legend in its
 *    description (metadata.common_properties, "1=High, -1=Regular" or — string keys —
 *    "lead=Lead union manager, manager=Union manager" — {@see EnumLegend}, the same
 *    legend the schema cards show the generator): every code the legend names is shown
 *    as its label (eval p16 E7: a roster printed "contact" / "manager");
 *  - the property's entity IS configured but declares no legend, the property is no
 *    identifier (ai.entity_id_fields, a declared relationship foreign_key), and every
 *    value is a small integer (a PHP int, |v| <= 9): a bare code nobody can read — the
 *    column is omitted ({@see apply()} only);
 *  - everything else (strings without a legend — including numeric-looking names such
 *    as unit "203" —, decimals, aggregates, expressions, unconfigured labels) is
 *    untouched.
 *
 * SEVERAL CODES IN ONE CELL (eval p17: the roster's roles once a member's rows are folded
 * into one). The one aggregate that still holds the property's own values is resolved —
 * `collect([DISTINCT] v.prop)[slice] AS alias`, in the RETURN or in the WITH the RETURN
 * passes through by its alias (the shape FanOutCollapse::plan writes) — and its cell, a
 * list or the "a; b" string FanOutCollapse::joinLists makes of it, is labelled element by
 * element. A plain projection's cell that is not itself a code but whose EVERY joined part
 * is one (FanOutCollapse::collapseByColumns) is labelled the same way. Every other
 * aggregate (count, min, a collected expression) says nothing about a legend.
 *
 * Two entry points: {@see labelled()} maps codes to labels and never removes a column
 * (a data answer shows the columns the user asked for); {@see apply()} also omits the
 * legend-less bare-code columns (the digest's generic source queries).
 *
 * No column-name word lists: only the projections and the host's declared legends.
 */
final class CodedColumns
{
    /** Largest absolute integer still read as a bare code when no legend is declared. */
    private const BARE_CODE_MAX = 9;

    private const RETURN_END = 'ORDER\s+BY|SKIP|LIMIT|UNION';

    /**
     * Column => [code => label] for every projected property with a declared legend.
     *
     * @return array<string, array<string, string>>
     */
    public static function legends(?string $cypher): array
    {
        $legends = [];
        foreach (self::projections($cypher) as $column => $projection) {
            $config = self::configFor($projection[0]);
            if ($config === null) {
                continue;
            }
            $legend = EnumLegend::parse(self::description($config, $projection[1]));
            if ($legend !== []) {
                $legends[$column] = $legend;
            }
        }

        return $legends;
    }

    /**
     * $rows with legend codes shown as labels — nothing else changes and no column is
     * removed (the data-answer mapping).
     *
     * A legend label is HOST text (like a host action label, p11 G5): given $locale —
     * the answer's language — it is resolved through the translator, so a French answer
     * shows the host's French label when the host ships one; the declared label
     * otherwise. Without a locale nothing is translated.
     *
     * @param array<int, mixed> $rows
     * @return array<int, mixed>
     */
    public static function labelled(array $rows, ?string $cypher, ?string $locale = null): array
    {
        return self::map($rows, $cypher, false, $locale);
    }

    /**
     * $rows with legend codes shown as labels and legend-less bare-code columns removed.
     *
     * @param array<int, mixed> $rows
     * @return array<int, mixed>
     */
    public static function apply(array $rows, ?string $cypher, ?string $locale = null): array
    {
        return self::map($rows, $cypher, true, $locale);
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, mixed>
     */
    private static function map(array $rows, ?string $cypher, bool $dropBareCodes, ?string $locale): array
    {
        $projections = self::projections($cypher);
        if ($projections === [] || $rows === []) {
            return $rows;
        }

        $legends = [];
        $collected = [];
        $drop = [];
        foreach ($projections as $column => [$label, $property, $isCollected]) {
            $config = self::configFor($label);
            if ($config === null) {
                continue; // nothing is known about this label — never guess
            }
            $legend = EnumLegend::parse(self::description($config, $property));
            if ($legend !== []) {
                $legends[$column] = self::translated($legend, $locale);
                $collected[$column] = $isCollected;
            } elseif ($dropBareCodes && !$isCollected && !self::isIdentifier($config, $property) && self::isBareCode($rows, $column)) {
                $drop[$column] = true;
            }
        }
        if ($legends === [] && $drop === []) {
            return $rows;
        }

        return array_map(static function ($row) use ($legends, $collected, $drop) {
            if (!is_array($row)) {
                return $row;
            }
            foreach ($legends as $column => $legend) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = self::labelledCell($row[$column], $legend, $collected[$column]);
                }
            }

            return array_diff_key($row, $drop);
        }, $rows);
    }

    /**
     * One cell of a legend column.
     *
     *   - the cell IS a code the legend names — an integer (or its string form) for an
     *     integer legend, the stored key for a string legend: its label;
     *   - a LIST (a collected column before it is joined): each element that is a code
     *     reads as its label, the others keep their value;
     *   - an "a; b" string: of a COLLECTED column — the joined list, so each part is
     *     labelled on its own; of a plain projection — only when every part is a code (a
     *     free value that merely contains the separator is never taken apart). The
     *     truncation marker of a capped cell stays where it is;
     *   - anything else — a value the legend does not name, a decimal, a map — keeps its value.
     *
     * @param array<string, string> $legend
     */
    private static function labelledCell(mixed $value, array $legend, bool $collected): mixed
    {
        $label = static function (mixed $code) use ($legend): ?string {
            if (!is_int($code) && !is_string($code)) {
                return null;
            }

            return $legend[trim((string) $code)] ?? null;
        };

        if (($direct = $label($value)) !== null) {
            return $direct;
        }

        if (is_array($value)) {
            return array_is_list($value)
                ? array_map(static fn ($member) => $label($member) ?? $member, $value)
                : $value;
        }

        if (!is_string($value) || !str_contains($value, FanOutCollapse::JOIN_SEPARATOR)) {
            return $value;
        }

        $parts = explode(FanOutCollapse::JOIN_SEPARATOR, $value);
        $labels = [];
        foreach ($parts as $part) {
            $known = $label($part);
            if ($known === null && !$collected && trim($part) !== FanOutCollapse::TRUNCATION_MARKER) {
                return $value;
            }
            $labels[] = $known ?? $part;
        }

        return implode(FanOutCollapse::JOIN_SEPARATOR, $labels);
    }

    /**
     * The legend with each label in $locale when the host ships a translation for it (the
     * label itself is the translation key, as for any host literal); unchanged otherwise.
     *
     * @param array<string, string> $legend
     * @return array<string, string>
     */
    private static function translated(array $legend, ?string $locale): array
    {
        if ($locale === null || trim($locale) === '' || !function_exists('trans')) {
            return $legend;
        }

        foreach ($legend as $code => $label) {
            try {
                $line = trans($label, [], $locale);
            } catch (\Throwable $e) {
                continue;
            }
            if (is_string($line) && trim($line) !== '') {
                $legend[$code] = $line;
            }
        }

        return $legend;
    }

    /**
     * A record id or a declared foreign key (ai.entity_id_fields, the entity config's
     * relationship foreign_key properties) is an identifier, never a code.
     */
    private static function isIdentifier(array $config, string $property): bool
    {
        try {
            $ids = function_exists('config') ? (array) config('ai.entity_id_fields', ['id', '_id', 'neo4j_id']) : ['id'];
        } catch (\Throwable $e) {
            $ids = ['id'];
        }
        if (in_array(strtolower($property), array_map(static fn ($id) => strtolower((string) $id), $ids), true)) {
            return true;
        }
        foreach ((array) ($config['graph']['relationships'] ?? []) as $relationship) {
            if (is_array($relationship) && ($relationship['foreign_key'] ?? null) === $property) {
                return true;
            }
        }

        return false;
    }

    /** Every non-null value a small PHP integer (and at least one value). */
    private static function isBareCode(array $rows, string $column): bool
    {
        $seen = false;
        foreach ($rows as $row) {
            $value = is_array($row) ? ($row[$column] ?? null) : null;
            if ($value === null) {
                continue;
            }
            if (!is_int($value) || abs($value) > self::BARE_CODE_MAX) {
                return false;
            }
            $seen = true;
        }

        return $seen;
    }

    /** An identifier as the generator writes an alias: any letters (`rôle`), backticked or not. */
    private const ALIAS = '`?([\p{L}_][\p{L}\p{N}_]*)`?';

    /** `collect([DISTINCT] v.prop)` with its optional slice — the one aggregate that keeps the property's values. */
    private const COLLECTED = 'collect\s*\(\s*(?:DISTINCT\s+)?([A-Za-z_]\w*)\.([A-Za-z_]\w*)\s*\)\s*(?:\[[^\]]*\])?';

    /**
     * Result column => [node label, property, collected] for the property projections of the
     * final RETURN clause: a direct `v.prop [AS alias]`, a collected
     * `collect([DISTINCT] v.prop)[slice] AS alias`, or the bare alias (`role`, `__fo1 AS
     * role`) of such a collect made in a WITH before it ({@see collectedAliases()}).
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    private static function projections(?string $cypher): array
    {
        $cypher = trim((string) $cypher);
        if ($cypher === '') {
            return [];
        }

        $stripped = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $cypher);
        if (!preg_match_all('/\bRETURN\b(.*?)(?=\b(?:' . self::RETURN_END . ')\b|$)/is', $stripped, $clauses, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $final = end($clauses[1]);
        $body = trim((string) preg_replace('/^DISTINCT\b/i', '', trim((string) $final[0])));
        $labels = TableRenderer::variableLabels($cypher);
        $carried = null;

        $out = [];
        foreach (self::splitTopLevel($body) as $item) {
            $item = trim($item);
            // The alias is a result-column name the generator chose — any identifier,
            // non-ASCII (`AS rôle`, eval p16-B french-flow) or backticked included.
            if (preg_match('/^([A-Za-z_]\w*)\.([A-Za-z_]\w*)(?:\s+AS\s+' . self::ALIAS . ')?$/iu', $item, $m) === 1) {
                [$variable, $property, $collected] = [$m[1], $m[2], false];
                $column = isset($m[3]) && $m[3] !== '' ? $m[3] : $m[1] . '.' . $m[2];
            } elseif (preg_match('/^' . self::COLLECTED . '\s+AS\s+' . self::ALIAS . '$/iu', $item, $m) === 1) {
                [$variable, $property, $collected, $column] = [$m[1], $m[2], true, $m[3]];
            } elseif (preg_match('/^' . self::ALIAS . '(?:\s+AS\s+' . self::ALIAS . ')?$/iu', $item, $m) === 1) {
                $carried ??= self::collectedAliases(substr($stripped, 0, (int) $final[1]));
                if (!isset($carried[$m[1]])) {
                    continue;
                }
                [$variable, $property] = $carried[$m[1]];
                $collected = true;
                $column = isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1];
            } else {
                continue;
            }

            $label = $labels[$variable] ?? null;
            if ($label === null) {
                continue;
            }
            $out[$column] = [$label, $property, $collected];
        }

        return $out;
    }

    /**
     * The aliases a WITH before the final RETURN binds to `collect([DISTINCT] v.prop)[slice]`
     * — alias => [variable, property]. An alias bound again afterwards to anything else (its
     * LAST `AS alias` is not that collect) is no longer the collected property.
     *
     * @param string $head the query text before its final RETURN's projection, string literals blanked
     * @return array<string, array{0: string, 1: string}>
     */
    private static function collectedAliases(string $head): array
    {
        if (!preg_match_all('/\b' . self::COLLECTED . '\s+AS\s+' . self::ALIAS . '(?![\p{L}\p{N}_])/iu', $head, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $aliases = [];
        foreach ($matches as $match) {
            $alias = $match[3][0];
            $end = $match[0][1] + strlen($match[0][0]);
            $rebound = preg_match(
                '/\bAS\s+`?' . preg_quote($alias, '/') . '`?(?![\p{L}\p{N}_])/iu',
                $head,
                $later,
                0,
                $end
            ) === 1;
            if ($rebound) {
                unset($aliases[$alias]);
                continue;
            }
            $aliases[$alias] = [$match[1][0], $match[2][0]];
        }

        return $aliases;
    }

    private static function configFor(string $label): ?array
    {
        try {
            return EntityLabelIndex::shared()->configForLabel($label);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function description(array $config, string $property): string
    {
        $description = $config['metadata']['common_properties'][$property] ?? null;

        return is_string($description) ? $description : '';
    }

    /** @return array<int, string> */
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
