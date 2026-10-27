<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Query\FanOutCollapse;
use Condoedge\Ai\Support\AliasNormalizer;

/**
 * QueriedKinds — the kinds of records a query LOOKED FOR (eval p18-B rights t2).
 *
 * An empty own-data answer said "I checked … in invoices, project records, and fund records":
 * the kinds were Query Understanding's guess (Invoice, Project, Fund) while the query that ran
 * summed the invoices of the user's orders. What was checked is what the executed query READS:
 *
 *   - the node variables its final RETURN projects from (`RETURN sum(i.total_amount)` → the
 *     label of `i`), in RETURN order;
 *   - when the RETURN only passes aliases on (`WITH sum(i.due_amount) AS due … RETURN due`),
 *     the variables the WITH clauses compute from (a property access or a function argument —
 *     a variable merely carried along, `WITH un, …`, is not read).
 *
 * A node the query only walks through (the user's contact, a link node) is the SCOPE, not what
 * was looked for, and is never named. Structural only — the cypher's own grammar, no vocabulary.
 *
 * {@see names()} gives each kind the HOST's word: its declared plural, else its display name,
 * else the label as typed words — never a raw type name. A kind the host marks
 * `metadata.askable => false` (a structural entity) is not named.
 */
final class QueriedKinds
{
    private const CLAUSE_END = 'MATCH|OPTIONAL|WHERE|WITH|RETURN|ORDER\s+BY|SKIP|LIMIT|UNWIND|CALL|UNION|CREATE|MERGE|SET|DELETE|DETACH|FOREACH';

    /**
     * The labels of the node variables the query's projections read — the final RETURN's
     * first, in its order. [] when there is no query or nothing can be read from it.
     *
     * @return list<string>
     */
    public static function labels(?string $cypher): array
    {
        $cypher = trim((string) $cypher);
        if ($cypher === '') {
            return [];
        }

        $nodes = TableRenderer::variableLabels($cypher);
        if ($nodes === []) {
            return [];
        }

        $read = [];
        try {
            $items = FanOutCollapse::returnItems($cypher);
        } catch (\Throwable $e) {
            $items = [];
        }
        foreach ($items as $item) {
            foreach (self::identifiers((string) $item['expression']) as $identifier) {
                if (isset($nodes[$identifier])) {
                    $read[$nodes[$identifier]] = true;
                }
            }
        }

        if ($read === []) {
            foreach (self::computedFrom($cypher) as $variable) {
                if (isset($nodes[$variable])) {
                    $read[$nodes[$variable]] = true;
                }
            }
        }

        return array_map('strval', array_keys($read));
    }

    /**
     * {@see labels()} as the host names them, for a prompt written in the schema's language:
     * the declared plural (`metadata.display_name_plural`), else the display name
     * (`metadata.display_name`), else the label as words ("TeamMember" → "team member").
     *
     * @return list<string>
     */
    public static function names(?string $cypher): array
    {
        $names = [];
        foreach (self::labels($cypher) as $label) {
            try {
                $config = EntityLabelIndex::shared()->configForLabel($label) ?? [];
            } catch (\Throwable $e) {
                $config = [];
            }
            if (($config['metadata']['askable'] ?? true) === false) {
                continue;
            }

            $schema = HostText::schemaLanguage();
            $name = HostText::inLanguage($config['metadata']['display_name_plural'] ?? $config['display_name_plural'] ?? null, $schema)
                ?? HostText::inLanguage($config['metadata']['display_name'] ?? $config['display_name'] ?? null, $schema)
                ?? AliasNormalizer::phrase($label);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The variable-like identifiers of an expression: never a property name (after a dot), a
     * function name (before a parenthesis), a parameter ($name) or anything inside a string.
     *
     * @return list<string>
     */
    private static function identifiers(string $expression): array
    {
        $expression = self::withoutStrings($expression);
        preg_match_all('/(?<![.\w$`])([A-Za-z_]\w*)\b(?!\s*\()/', $expression, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * The variables the query's WITH clauses compute from: read through a property
     * (`i.due_amount`) or as a function's argument (`count(DISTINCT u)`).
     *
     * @return list<string>
     */
    private static function computedFrom(string $cypher): array
    {
        $cypher = self::withoutStrings($cypher);
        // STARTS WITH / ENDS WITH are operators, not a WITH clause.
        $cypher = (string) preg_replace('/\b(STARTS|ENDS)\s+WITH\b/i', '$1_WITH', $cypher);
        if (!preg_match_all('/\bWITH\b(.*?)(?=\b(?:' . self::CLAUSE_END . ')\b|$)/is', $cypher, $clauses)) {
            return [];
        }

        $variables = [];
        foreach ($clauses[1] as $body) {
            if (preg_match_all('/(?<![.\w$`])([A-Za-z_]\w*)\s*\.\s*[A-Za-z_`]/', $body, $m)) {
                array_push($variables, ...$m[1]);
            }
            if (preg_match_all('/\(\s*(?:DISTINCT\s+)?([A-Za-z_]\w*)\s*\)/i', $body, $m)) {
                array_push($variables, ...$m[1]);
            }
        }

        return array_values(array_unique($variables));
    }

    private static function withoutStrings(string $text): string
    {
        return (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $text);
    }
}
