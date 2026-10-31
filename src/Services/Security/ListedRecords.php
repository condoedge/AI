<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Support\Audiences;

/**
 * Which records of an entity an audience may LIST, beside the team and row scope.
 *
 * A screen may hide some records of a list from a role while the records that name them stay
 * readable: a role may see the orders of every vendor, but list only the vendors shared with it.
 * So the rule depends on how the query reaches the record:
 *
 *   ai.audiences.map.<audience>.listed_records = ['Vendor' => [
 *       'where' => ['visibility' => [2, 3]],   // the rows this audience may list
 *       'named_on' => ['Order', 'Quote'],      // records that may still name any of them
 *   ]]
 *
 * Only a real join to one of those records counts, and only when the query reads that record: a
 * join that projects none of it is the same list by another route. An OPTIONAL MATCH from the
 * record itself keeps every row, and EXISTS or a comment joins nothing, so none of them lift the
 * rule. A UNION is not analysed per branch: it fails closed.
 */
final class ListedRecords
{
    private const CLAUSE = 'OPTIONAL\s+MATCH|MATCH|WHERE|WITH|RETURN|UNWIND|CALL|ORDER\s+BY|SKIP|LIMIT|UNION';

    /** A keyword, never a label or a property that spells one: `(n:Match)`, `x.with`. */
    private const NOT_A_NAME = '(?<![:`.])\b';

    private const NODE = '\(\s*`?(\w*)`?\s*(?::\s*`?(\w+)`?)?[^()]*\)';

    private const LINK = '\s*<?-(?:\[[^\]]*\])?-?>?\s*';

    /**
     * The predicate this binding must also meet, for the query as written; null when none applies.
     * A rule the host wrote wrong restricts everything: 'false' never reads a row.
     */
    public static function predicateFor(mixed $user, NodeBinding $binding, string $cypher): ?string
    {
        $rule = Audiences::listedRecords($user)[$binding->label] ?? null;
        if (!is_array($rule)) {
            return null;
        }
        if (self::isNamedOnReadableRecord($cypher, $binding, (array) ($rule['named_on'] ?? []))) {
            return null;
        }

        $parts = [];
        foreach ((array) ($rule['where'] ?? []) as $property => $values) {
            $values = (array) $values;
            if (!is_string($property) || preg_match('/^[A-Za-z_]\w*$/', $property) !== 1 || $values === []
                || array_filter($values, 'is_int') !== $values) {
                return 'false';
            }
            $parts[] = $binding->alias . '.' . $property . ' IN [' . implode(', ', $values) . ']';
        }

        return $parts === [] ? 'false' : implode(' AND ', $parts);
    }

    /** @param list<string> $namedOn */
    private static function isNamedOnReadableRecord(string $cypher, NodeBinding $binding, array $namedOn): bool
    {
        $text = self::structure($cypher);
        if ($namedOn === [] || preg_match('/' . self::NOT_A_NAME . 'UNION\b/i', $text) === 1) {
            return false;
        }

        $labels = self::labelsByVariable($text);
        $projected = '';
        $parts = preg_split('/' . self::NOT_A_NAME . '(' . self::CLAUSE . ')\b/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($i = 1, $count = count($parts); $i + 1 < $count; $i += 2) {
            if (preg_match('/^(WITH|RETURN)$/i', $parts[$i]) === 1) {
                $projected .= ' ' . $parts[$i + 1];
            }
        }

        for ($i = 1; $i + 1 < $count; $i += 2) {
            [$keyword, $body] = [$parts[$i], $parts[$i + 1]];

            // A record bound by an OPTIONAL MATCH is an attachment of what that clause starts from;
            // one bound by a MATCH stays whatever an OPTIONAL MATCH attaches to it.
            $isOptional = stripos($keyword, 'OPTIONAL') === 0;
            $isMatch = $isOptional || stripos($keyword, 'MATCH') === 0;
            if (!$isMatch || ($isOptional && !$binding->optional)) {
                continue;
            }

            preg_match_all('/(?=' . self::NODE . self::LINK . self::NODE . ')/s', $body, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                [$a, $aLabel, $b, $bLabel] = [$pair[1], $pair[2], $pair[3], $pair[4]];
                $aLabel = $aLabel !== '' ? $aLabel : ($labels[$a] ?? '');
                $bLabel = $bLabel !== '' ? $bLabel : ($labels[$b] ?? '');
                if (($a === $binding->alias && in_array($bLabel, $namedOn, true) && self::reads($projected, $b))
                    || ($b === $binding->alias && in_array($aLabel, $namedOn, true) && self::reads($projected, $a))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A property or a function of the variable, not the variable carried along. */
    private static function reads(string $projected, string $variable): bool
    {
        if ($variable === '') {
            return false;
        }
        $name = preg_quote($variable, '/');

        return preg_match('/(?<![\w.])' . $name . '\s*\.|\(\s*(?:DISTINCT\s+)?' . $name . '\s*\)/i', $projected) === 1;
    }

    /** The query without what joins nothing: comments, text, and the blocks of EXISTS or CALL. */
    private static function structure(string $cypher): string
    {
        $text = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/s", "''", $cypher);
        $text = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', ' ', $text);
        do {
            $text = (string) preg_replace('/\{[^{}]*\}/s', ' ', $text, -1, $count);
        } while ($count > 0);

        return $text;
    }

    /** @return array<string, string> */
    private static function labelsByVariable(string $text): array
    {
        $labels = [];
        if (preg_match_all('/\(\s*`?(\w+)`?\s*:\s*`?(\w+)`?/', $text, $nodes, PREG_SET_ORDER)) {
            foreach ($nodes as $node) {
                $labels[$node[1]] ??= $node[2];
            }
        }

        return $labels;
    }
}
