<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\Services\Query\CypherBooleanPrecedence;
use PHPUnit\Framework\TestCase;

/**
 * Eval p22-1001-B, rights t2 ("So how much is my contribution this year?"): the
 * generator wrote `WHERE (unit branch) OR (contact branch) AND status … AND year …`.
 * AND binds tighter than OR, so the status / year filters applied to the contact
 * branch only. The check is Cypher grammar on the lexer tokens: an OR / XOR and an
 * AND in ONE predicate at the SAME nesting level.
 */
class CypherBooleanPrecedenceTest extends TestCase
{
    private const P22 = "MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL "
        . "MATCH (i:Invoice) WHERE (i.customer_type = 'unit' AND i.customer_id = u.id) OR (i.customer_type = 'contact' AND i.customer_id = c.id) "
        . "AND i.status IN ['2', '3', '4'] AND substring(i.invoiced_at, 0, 4) = '2026' RETURN SUM(i.total_amount) AS annual_contribution LIMIT 100";

    private function check(): CypherBooleanPrecedence
    {
        return new CypherBooleanPrecedence();
    }

    /** @test */
    public function the_p22_contribution_query_is_ambiguous_and_the_predicate_is_quoted(): void
    {
        $predicates = $this->check()->ambiguousPredicates(self::P22);

        $this->assertSame([
            "(i.customer_type = 'unit' AND i.customer_id = u.id) OR (i.customer_type = 'contact' AND i.customer_id = c.id) "
            . "AND i.status IN ['2', '3', '4'] AND substring(i.invoiced_at, 0, 4) = '2026'",
        ], $predicates);

        $violations = $this->check()->violations(self::P22);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString($predicates[0], $violations[0]);
        $this->assertStringContainsString('(A OR B) AND C AND D', $violations[0]);
    }

    /** @test */
    public function the_parenthesized_form_passes(): void
    {
        $fixed = "MATCH (i:Invoice) WHERE ((i.customer_type = 'unit' AND i.customer_id = 1) OR (i.customer_type = 'contact' AND i.customer_id = 2)) "
            . "AND i.status IN ['2', '3', '4'] AND substring(i.invoiced_at, 0, 4) = '2026' RETURN SUM(i.total_amount) AS total LIMIT 100";

        $this->assertSame([], $this->check()->violations($fixed));
    }

    /**
     * @test
     * @dataProvider ambiguous
     */
    public function a_mixed_predicate_at_one_level_is_reported(string $cypher, string $predicate): void
    {
        $this->assertSame([$predicate], $this->check()->ambiguousPredicates($cypher));
    }

    public static function ambiguous(): array
    {
        return [
            'AND then OR' => [
                'MATCH (n:A) WHERE n.a = 1 AND n.b = 2 OR n.c = 3 RETURN n',
                'n.a = 1 AND n.b = 2 OR n.c = 3',
            ],
            'XOR with AND' => [
                'MATCH (n:A) WHERE n.a = 1 XOR n.b = 2 AND n.c = 3 RETURN n',
                'n.a = 1 XOR n.b = 2 AND n.c = 3',
            ],
            'lower-case keywords' => [
                'match (n:A) where n.a = 1 or n.b = 2 and n.c = 3 return n',
                'n.a = 1 or n.b = 2 and n.c = 3',
            ],
            'inside a parenthesis' => [
                'MATCH (n:A) WHERE (n.a = 1 OR n.b = 2 AND n.c = 3) AND n.d = 4 RETURN n',
                'n.a = 1 OR n.b = 2 AND n.c = 3',
            ],
            'pattern comprehension predicate' => [
                'MATCH (a:A) RETURN [(a)-[:R]->(b:B) WHERE b.x = 1 OR b.y = 2 AND b.z = 3 | b.id] AS ids',
                'b.x = 1 OR b.y = 2 AND b.z = 3',
            ],
            'list predicate' => [
                'MATCH (a:A) WHERE any(x IN a.xs WHERE x = 1 OR x = 2 AND x <> 3) RETURN a',
                'x = 1 OR x = 2 AND x <> 3',
            ],
            'CASE WHEN condition' => [
                'MATCH (a:A) RETURN CASE WHEN a.x = 1 OR a.y = 2 AND a.z = 3 THEN 1 ELSE 0 END AS flag',
                'a.x = 1 OR a.y = 2 AND a.z = 3',
            ],
            'CASE expression as an operand does not hide the mix around it' => [
                'MATCH (a:A) WHERE a.x = 1 OR CASE WHEN a.y = 1 THEN 1 ELSE 0 END = 1 AND a.z = 2 RETURN a',
                'a.x = 1 OR CASE WHEN a.y = 1 THEN 1 ELSE 0 END = 1 AND a.z = 2',
            ],
            'EXISTS subquery predicate' => [
                'MATCH (a:A) WHERE EXISTS { MATCH (a)-[:R]->(b:B) WHERE b.x = 1 OR b.y = 2 AND b.z = 3 } RETURN a',
                'b.x = 1 OR b.y = 2 AND b.z = 3',
            ],
            'inline node predicate' => [
                'MATCH (a:A WHERE a.x = 1 OR a.y = 2 AND a.z = 3) RETURN a',
                'a.x = 1 OR a.y = 2 AND a.z = 3',
            ],
            'a projected expression' => [
                'MATCH (a:A) RETURN a.x = 1 OR a.y = 2 AND a.z = 3 AS flag, a.id AS id',
                'a.x = 1 OR a.y = 2 AND a.z = 3',
            ],
            'NOT does not disambiguate' => [
                'MATCH (n:A) WHERE NOT n.a = 1 OR n.b = 2 AND n.c = 3 RETURN n',
                'NOT n.a = 1 OR n.b = 2 AND n.c = 3',
            ],
            'second WHERE of the query' => [
                'MATCH (n:A) WHERE n.a = 1 WITH n MATCH (m:B) WHERE m.a = n.a OR m.b = 2 AND m.c = 3 RETURN m',
                'm.a = n.a OR m.b = 2 AND m.c = 3',
            ],
            'line breaks are collapsed in the quote' => [
                "MATCH (n:A)\nWHERE n.a = 1\n  OR n.b = 2\n  AND n.c = 3\nRETURN n",
                'n.a = 1 OR n.b = 2 AND n.c = 3',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider unambiguous
     */
    public function an_unambiguous_query_passes(string $cypher): void
    {
        $this->assertSame([], $this->check()->violations($cypher), $cypher);
    }

    public static function unambiguous(): array
    {
        return [
            'OR alone' => ['MATCH (n:A) WHERE n.a = 1 OR n.b = 2 OR n.c = 3 RETURN n'],
            'AND alone' => ['MATCH (n:A) WHERE n.a = 1 AND n.b = 2 AND n.c = 3 RETURN n'],
            'XOR alone' => ['MATCH (n:A) WHERE n.a = 1 XOR n.b = 2 RETURN n'],
            '(a OR b) AND c' => ['MATCH (n:A) WHERE (n.a = 1 OR n.b = 2) AND n.c = 3 RETURN n'],
            'a AND (b OR c)' => ['MATCH (n:A) WHERE n.a = 1 AND (n.b = 2 OR n.c = 3) RETURN n'],
            '(a AND b) OR c' => ['MATCH (n:A) WHERE (n.a = 1 AND n.b = 2) OR n.c = 3 RETURN n'],
            '(a AND b) OR (c AND d)' => ['MATCH (n:A) WHERE (n.a = 1 AND n.b = 2) OR (n.c = 3 AND n.d = 4) RETURN n'],
            'NOT with AND' => ['MATCH (n:A) WHERE NOT n.a = 1 AND NOT (n.b = 2 OR n.c = 3) RETURN n'],
            'no predicate at all' => ['MATCH (n:A) RETURN n LIMIT 10'],
            'OR in one WHERE, AND in another' => [
                'MATCH (n:A) WHERE n.a = 1 OR n.b = 2 MATCH (m:B) WHERE m.a = 1 AND m.b = 2 RETURN n, m',
            ],
            'OR before a WITH, AND after it' => [
                'MATCH (n:A) WHERE n.a = 1 OR n.b = 2 WITH n WHERE n.c = 3 AND n.d = 4 RETURN n',
            ],
            'operators inside string literals' => [
                "MATCH (n:A) WHERE n.name = 'this OR that' AND n.note = \"x AND y OR z\" RETURN n",
            ],
            'operators inside comments' => [
                "MATCH (n:A) WHERE n.a = 1 /* OR n.z = 9 */ AND n.b = 2 // OR n.q = 1\n AND n.c = 3 RETURN n",
            ],
            'backtick identifiers named like operators' => [
                'MATCH (n:A) WHERE n.`or` = 1 AND n.`x OR y` = 2 RETURN n',
            ],
            'a property key named like an operator' => ['MATCH (n:A) WHERE n.or = 1 AND n.and = 2 RETURN n'],
            'list literal next to an AND' => [
                "MATCH (n:A) WHERE n.status IN ['a', 'b'] AND (n.x = 1 OR n.y = 2) RETURN n",
            ],
            'OR inside a list predicate, AND outside' => [
                'MATCH (n:A) WHERE n.a = 1 AND any(x IN n.xs WHERE x = 1 OR x = 2) RETURN n',
            ],
            'AND inside a function argument, OR outside' => [
                'MATCH (n:A) WHERE n.a = 1 OR coalesce(n.b = 2 AND n.c = 3, false) RETURN n',
            ],
            'two projected items' => [
                'MATCH (n:A) RETURN n.a = 1 OR n.b = 2 AS either, n.c = 3 AND n.d = 4 AS both',
            ],
            'CASE branches are separate predicates' => [
                'MATCH (n:A) RETURN CASE WHEN n.a = 1 OR n.b = 2 THEN 1 WHEN n.c = 3 AND n.d = 4 THEN 2 ELSE 0 END AS k',
            ],
            'AND inside a CASE operand, OR outside' => [
                'MATCH (n:A) WHERE n.a = 1 OR CASE WHEN n.b = 2 AND n.c = 3 THEN true ELSE false END RETURN n',
            ],
            'comprehension filter and projection are separate' => [
                'MATCH (n:A) RETURN [x IN n.xs WHERE x = 1 OR x = 2 | x > 0 AND x < 9] AS flags',
            ],
            'map values are separate' => [
                'MATCH (n:A) RETURN {either: n.a = 1 OR n.b = 2, both: n.c = 3 AND n.d = 4} AS m',
            ],
            'UNION parts are separate' => [
                'MATCH (n:A) WHERE n.a = 1 OR n.b = 2 RETURN n.id AS id UNION MATCH (n:B) WHERE n.a = 1 AND n.b = 2 RETURN n.id AS id',
            ],
            'OR in WHERE, AND in ORDER BY' => [
                'MATCH (n:A) WHERE n.a = 1 OR n.b = 2 RETURN n ORDER BY n.c = 3 AND n.d = 4',
            ],
            'string operators' => [
                "MATCH (n:A) WHERE n.name STARTS WITH 'a' OR n.name ENDS WITH 'b' RETURN n",
            ],
            'an alias named like an operator' => ['MATCH (n:A) RETURN n.a AS or, n.b AS and'],
        ];
    }

    /** @test */
    public function input_the_lexer_rejects_yields_no_violation(): void
    {
        $this->assertSame([], $this->check()->violations("MATCH (n:A) WHERE n.a = 'x OR n.b = 2 AND n.c = 3 RETURN n"));
        $this->assertSame([], $this->check()->violations('MATCH (n:A WHERE n.a = 1 OR n.b = 2 AND n.c = 3 RETURN n'));
        $this->assertSame([], $this->check()->violations(''));
    }

    /** @test */
    public function each_ambiguous_predicate_is_reported_once(): void
    {
        $cypher = 'MATCH (n:A) WHERE n.a = 1 OR n.b = 2 AND n.c = 3 MATCH (m:B) WHERE m.a = 1 AND m.b = 2 OR m.c = 3 RETURN n, m';

        $this->assertSame(
            ['n.a = 1 OR n.b = 2 AND n.c = 3', 'm.a = 1 AND m.b = 2 OR m.c = 3'],
            $this->check()->ambiguousPredicates($cypher)
        );
        $this->assertCount(2, $this->check()->violations($cypher));
    }

    /** @test */
    public function a_very_long_predicate_is_quoted_shortened(): void
    {
        $long = implode(' AND ', array_map(static fn (int $i): string => "n.p{$i} = {$i}", range(1, 80)));
        $violations = $this->check()->violations("MATCH (n:A) WHERE n.a = 1 OR {$long} RETURN n");

        $this->assertCount(1, $violations);
        $this->assertLessThan(CypherBooleanPrecedence::MAX_QUOTED_CHARS + 400, mb_strlen($violations[0]));
        $this->assertStringContainsString('…', $violations[0]);
    }
}
