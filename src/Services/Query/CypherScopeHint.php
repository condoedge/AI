<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

/**
 * SCOPE HINT for an execution repair (eval p12-1001-A, team-roster-then-excel #1).
 *
 * Neo4j's "Variable `x` not defined" says WHAT is missing, not WHY. The live
 * extension of a per-member roster projected `t.contact_phone` after
 * `WITH u, collect(DISTINCT tr.role)… AS role` — a WITH that keeps only `u` and
 * `role` in scope. Fed only the raw error, the model kept `t.contact_phone` and
 * the same error came back. This derives the cause from the error text and the
 * generator's OWN draft:
 *
 *  - the first top-level WITH after `x` was bound that does not carry it
 *    (neither `x`, an `… AS x` alias, nor `*`) → "carry it through that WITH or
 *    aggregate it there";
 *  - no binding at all (no pattern variable, no WITH / UNWIND alias) → "bind it".
 *
 * Purely structural: Cypher clause grammar on the canonical text with string
 * literals and backtick identifiers masked (FanOutCollapse::mask), so a quoted
 * word never reads as a clause. `STARTS WITH` / `ENDS WITH` are operators, and a
 * WITH inside a subquery `{ … }` or brackets has its own scope — neither counts.
 */
final class CypherScopeHint
{
    /** The Neo4j error shape this hint explains. */
    private const UNDEFINED_VARIABLE = '/Variable\s+`((?:[^`]|``)+)`\s+not\s+defined/i';

    /** Top-level clause keywords that end a WITH projection. */
    private const CLAUSE = '/(?<![.\w$])\b(WITH|WHERE|OPTIONAL\s+MATCH|MATCH|RETURN|UNWIND|CALL|ORDER\s+BY|SKIP|LIMIT|MERGE|CREATE|SET|DELETE|DETACH|REMOVE|FOREACH|UNION)\b/i';

    /** Longest WITH clause quoted back to the model. */
    private const MAX_CLAUSE_CHARS = 160;

    /**
     * The hint for $error on $cypher (the generator's own draft), or null when the
     * error is not an undefined-variable error or no structural cause is found.
     */
    public static function forError(string $error, string $cypher): ?string
    {
        if (preg_match(self::UNDEFINED_VARIABLE, $error, $m) !== 1) {
            return null;
        }
        $variable = str_replace('``', '`', $m[1]);

        $canonical = FanOutCollapse::canonical($cypher);
        if ($canonical === null || $canonical === '') {
            return null;
        }
        $masked = FanOutCollapse::mask($canonical);

        $clauses = self::topLevelClauses($masked);
        $inScope = false;
        $from = 0;
        foreach ($clauses as $index => [$keyword, $offset]) {
            if ($keyword !== 'WITH') {
                continue;
            }
            $bound = $inScope || self::bindsBetween($masked, $variable, $from, $offset);
            $end = $clauses[$index + 1][1] ?? strlen($masked);
            $projection = substr($canonical, $offset + 4, $end - $offset - 4);
            $carried = self::carried($projection);
            $from = $end;

            if (in_array('*', $carried, true)) {
                $inScope = $bound;
                continue;
            }
            if (in_array($variable, $carried, true)) {
                $inScope = true;
                continue;
            }
            if ($bound) {
                return self::droppedHint($variable, 'WITH ' . trim($projection));
            }
            $inScope = false;
        }

        if (!self::bindsBetween($masked, $variable, 0, strlen($masked))) {
            return "Scope hint: `{$variable}` is never bound — no MATCH pattern, WITH alias or UNWIND alias "
                . "defines it. Bind it in a MATCH / OPTIONAL MATCH pattern (or carry the alias that holds it), "
                . 'or use a variable that is bound.';
        }

        return null;
    }

    private static function droppedHint(string $variable, string $with): string
    {
        $with = trim((string) preg_replace('/\s+/', ' ', $with));
        if (mb_strlen($with) > self::MAX_CLAUSE_CHARS) {
            $with = rtrim(mb_substr($with, 0, self::MAX_CLAUSE_CHARS)) . '…';
        }

        return "Scope hint: `{$variable}` is bound earlier, but `{$with}` does not carry it, so `{$variable}` "
            . 'is undefined after that WITH. Fix it in that clause: add `' . $variable . '` to that WITH (when the '
            . 'WITH aggregates, it then becomes a grouping key), or aggregate the value you need from it there '
            . "(e.g. head(collect({$variable}.<property>)) AS <alias>) and use that alias afterwards — "
            . "or remove the use of `{$variable}` after the WITH.";
    }

    /**
     * Top-level clause keywords in order, as [normalized keyword, offset]. A WITH
     * preceded by STARTS / ENDS is the string operator; anything inside (), [] or
     * {} belongs to a nested scope.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private static function topLevelClauses(string $masked): array
    {
        preg_match_all(self::CLAUSE, $masked, $matches, PREG_OFFSET_CAPTURE);

        $clauses = [];
        foreach ($matches[1] as [$word, $offset]) {
            $keyword = strtoupper((string) preg_replace('/\s+/', ' ', $word));
            $prefix = substr($masked, 0, (int) $offset);
            if ($keyword === 'WITH' && preg_match('/\b(STARTS|ENDS)\s*$/i', $prefix) === 1) {
                continue;
            }
            if (self::depth($prefix) !== 0) {
                continue;
            }
            $clauses[] = [$keyword, (int) $offset];
        }

        return $clauses;
    }

    private static function depth(string $maskedPrefix): int
    {
        return substr_count($maskedPrefix, '(') + substr_count($maskedPrefix, '[') + substr_count($maskedPrefix, '{')
            - substr_count($maskedPrefix, ')') - substr_count($maskedPrefix, ']') - substr_count($maskedPrefix, '}');
    }

    /**
     * Whether $variable is bound in masked[$from, $to): a node / relationship
     * pattern variable, a named path, or an `AS variable` alias (WITH / UNWIND).
     */
    private static function bindsBetween(string $masked, string $variable, int $from, int $to): bool
    {
        if ($to <= $from) {
            return false;
        }
        $segment = substr($masked, $from, $to - $from);
        $v = preg_quote($variable, '/');

        return preg_match('/\(\s*' . $v . '\s*[:){]/', $segment) === 1
            || preg_match('/\[\s*' . $v . '\s*[:\]*{]/', $segment) === 1
            || preg_match('/(?<![\w$])' . $v . '\s*=\s*(?:shortestPath|allShortestPaths)?\s*\(/i', $segment) === 1
            || preg_match('/\bAS\s+' . $v . '(?![\w])/i', $segment) === 1;
    }

    /**
     * The variables a WITH projection keeps in scope: each item's alias, or the
     * item itself when it is a bare variable; `*` keeps everything.
     *
     * @return array<int, string>
     */
    private static function carried(string $projection): array
    {
        $projection = trim((string) preg_replace('/^\s*DISTINCT\b/i', '', $projection));
        $carried = [];
        foreach (FanOutCollapse::splitTopLevel($projection) as $item) {
            $item = trim($item);
            if ($item === '*') {
                $carried[] = '*';
            } elseif (preg_match('/\bAS\s+(`(?:[^`]|``)+`|[A-Za-z_][A-Za-z0-9_]*)\s*$/i', $item, $m) === 1) {
                $carried[] = str_replace('``', '`', trim($m[1], '`'));
            } elseif (preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?$/', $item, $m) === 1) {
                $carried[] = $m[1];
            }
        }

        return $carried;
    }
}
