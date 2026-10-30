<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\Services\Query\CypherOptionalFilter;
use PHPUnit\Framework\TestCase;

/**
 * A question about one supplier returned every bill of the year: the generator put the
 * condition on an OPTIONAL MATCH, which keeps every row when it finds nothing, and summed
 * values that do not come from that match. Only that shape is reported; OPTIONAL MATCH …
 * WHERE is otherwise common and legitimate.
 */
class CypherOptionalFilterTest extends TestCase
{
    /** The query that returned the total of every bill, 7 runs of 12. */
    private const MEASURED = "MATCH (b:Bill)-[:BELONGS_TO_UNION]->(un:Union) WHERE b.status IN ['3', '5', '6'] AND toLower(un.name) CONTAINS toLower('Soho') "
        . "OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE toLower(s.name) CONTAINS toLower('assurance') "
        . "WITH un, s, b, CASE WHEN b.type = 2 THEN -b.total_amount ELSE b.total_amount END AS amount "
        . "WITH un, left(b.billed_at, 4) AS year, sum(amount) AS billed, sum(b.due_amount) AS payable "
        . "RETURN un.name AS union_name, year, round(sum(billed - payable), 2) AS total_paid ORDER BY year";

    private function check(): CypherOptionalFilter
    {
        return new CypherOptionalFilter();
    }

    /** @test */
    public function the_measured_query_is_reported_with_the_condition_and_how_to_fix_it(): void
    {
        $this->assertSame(["toLower(s.name) CONTAINS toLower('assurance')"], $this->check()->ineffectiveConditions(self::MEASURED));

        $violations = $this->check()->violations(self::MEASURED);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString("toLower(s.name) CONTAINS toLower('assurance')", $violations[0]);
        $this->assertStringContainsString('OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier)', $violations[0]);
        $this->assertStringContainsString('use MATCH', $violations[0]);
    }

    /**
     * @test
     * @dataProvider ineffective
     */
    public function a_text_condition_on_a_match_the_total_does_not_use_is_reported(string $cypher, string $condition): void
    {
        $this->assertSame([$condition], $this->check()->ineffectiveConditions($cypher));
    }

    public static function ineffective(): array
    {
        $bills = 'MATCH (b:Bill)-[:BELONGS_TO_UNION]->(un:Union) OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE ';

        return [
            'the variable is simply dropped' => [
                $bills . "toLower(s.name) CONTAINS toLower('assurance') WITH un, left(b.billed_at, 4) AS year, sum(b.total_amount) AS billed RETURN un.name, year, billed",
                "toLower(s.name) CONTAINS toLower('assurance')",
            ],
            'kept as a group key, then rolled up: the total still covers every row' => [
                $bills . "toLower(s.name) CONTAINS toLower('assurance') WITH un, s, b WITH un, s.name AS supplier, count(b) AS bills, sum(b.total_amount) AS billed "
                    . 'WITH un, round(sum(billed), 2) AS total, collect({supplier: supplier, bills: bills}) AS per_supplier RETURN un.name, total, per_supplier',
                "toLower(s.name) CONTAINS toLower('assurance')",
            ],
            'only listed beside the total' => [
                $bills . "s.name = 'Acme' RETURN un.name, collect(DISTINCT s.name) AS suppliers, sum(b.total_amount) AS billed",
                "s.name = 'Acme'",
            ],
            'equals' => [$bills . "s.name = 'Acme' RETURN sum(b.total_amount) AS total", "s.name = 'Acme'"],
            'the literal on the left' => [$bills . "'Acme' = s.name RETURN sum(b.total_amount) AS total", "'Acme' = s.name"],
            'double quotes' => [$bills . 's.name = "Acme" RETURN sum(b.total_amount) AS total', 's.name = "Acme"'],
            'STARTS WITH' => [$bills . "toLower(s.name) STARTS WITH 'acme' RETURN count(b) AS n", "toLower(s.name) STARTS WITH 'acme'"],
            'ENDS WITH' => [$bills . "s.name ENDS WITH 'inc' RETURN count(*) AS n", "s.name ENDS WITH 'inc'"],
            'one alternative of an OR' => [
                $bills . "(s.name CONTAINS 'a' OR b.id > 5) RETURN avg(b.total_amount) AS mean",
                "s.name CONTAINS 'a'",
            ],
            'beside a null guard' => [
                $bills . "toLower(s.name) CONTAINS 'a' AND (s IS NULL OR s.team_id IN \$ai_team_ids) RETURN max(b.total_amount) AS top",
                "toLower(s.name) CONTAINS 'a'",
            ],
            'lower-case keywords' => [
                "match (b:Bill) optional match (b)-[:SUPPLIER]->(s:Supplier) where s.name contains 'a' return min(b.total_amount) as low",
                "s.name contains 'a'",
            ],
            'a relationship variable' => [
                "MATCH (b:Bill) OPTIONAL MATCH (b)-[r:SUPPLIER]->(:Supplier) WHERE r.kind = 'main' RETURN sum(b.total_amount) AS total",
                "r.kind = 'main'",
            ],
            'a property or a map key spelled like the variable is not a use' => [
                $bills . "s.name = 'Acme' RETURN sum(b.s) AS total, collect({s: b.id}) AS ids",
                "s.name = 'Acme'",
            ],
            'an alias reusing the name after the variable left the scope' => [
                $bills . "s.name = 'Acme' WITH b, b.total_amount AS s RETURN sum(s) AS total",
                "s.name = 'Acme'",
            ],
            'an aggregate of the branch counts, whatever the other branch does' => [
                $bills . "s.name = 'Acme' RETURN sum(b.total_amount) AS total UNION MATCH (s:Supplier) RETURN sum(s.total) AS total",
                "s.name = 'Acme'",
            ],
            'the second branch' => [
                'MATCH (s:Supplier) RETURN sum(s.total) AS total UNION ' . $bills . "s.name = 'Acme' RETURN sum(b.total_amount) AS total",
                "s.name = 'Acme'",
            ],
        ];
    }

    /**
     * @test
     * @dataProvider legitimate
     */
    public function a_legitimate_query_passes(string $cypher): void
    {
        $this->assertSame([], $this->check()->violations($cypher));
    }

    public static function legitimate(): array
    {
        $bills = 'MATCH (b:Bill)-[:BELONGS_TO_UNION]->(un:Union) OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE ';

        return [
            'every unit stays listed, the aggregate is over the optional variable' => [
                "MATCH (u:Unit) OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.status <> '1' AND i.due_amount >= 0.01 RETURN u.name, count(i)",
            ],
            'the same with an equality on text' => [
                "MATCH (u:Unit) OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.status = '2' RETURN u.name, count(i) AS open, sum(i.due_amount) AS due",
            ],
            'another variable of the optional pattern is summed' => [
                "MATCH (a:Account)-[:BELONGS_TO_UNION]->(un:Union) OPTIONAL MATCH (e:Entry)-[:ACCOUNT]->(a) MATCH (e)-[:TRANSACTION]->(t:Transaction) WHERE t.type <> '8' WITH un, a, sum(e.debit - e.credit) AS total RETURN un.name, total",
            ],
            'one pattern binds both the filtered and the summed variable' => [
                "MATCH (a:Account) OPTIONAL MATCH (t:Transaction)<-[:TRANSACTION]-(e:Entry)-[:ACCOUNT]->(a) WHERE t.type = 'x' WITH a, sum(e.debit - e.credit) AS total RETURN a.name, total",
            ],
            'a scope rewrite is no text condition' => [
                $bills . '(s IS NULL OR s.team_id IN $ai_team_ids) RETURN sum(b.total_amount) AS total',
            ],
            'a null test only' => [$bills . 's IS NOT NULL RETURN sum(b.total_amount) AS total'],
            'a number' => [$bills . 's.id = 5 RETURN sum(b.total_amount) AS total'],
            'a parameter' => [$bills . 's.name = $pii_0123456789ab RETURN sum(b.total_amount) AS total'],
            'another property' => [$bills . 's.name = b.supplier_name RETURN sum(b.total_amount) AS total'],
            'not equals' => [$bills . "s.name <> 'Acme' RETURN sum(b.total_amount) AS total"],
            'at least' => [$bills . "s.name >= 'Acme' RETURN sum(b.total_amount) AS total"],
            'the condition is on a variable bound before' => [
                "MATCH (s:Supplier) OPTIONAL MATCH (b:Bill)-[:SUPPLIER]->(s) WHERE toLower(s.name) CONTAINS 'a' RETURN sum(b.total_amount) AS total",
            ],
            'a MATCH does filter' => [
                "MATCH (b:Bill) MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE toLower(s.name) CONTAINS 'a' RETURN sum(b.total_amount) AS total",
            ],
            'a later filter keeps only the matches' => [
                $bills . "s.name = 'Acme' WITH un, s, b WHERE s IS NOT NULL RETURN sum(b.total_amount) AS total",
            ],
            'the total is conditional on the match' => [
                $bills . "s.name = 'Acme' RETURN sum(CASE WHEN s IS NULL THEN 0 ELSE b.total_amount END) AS total",
            ],
            'the match is counted' => [$bills . "s.name = 'Acme' RETURN un.name, count(s) AS matched, sum(b.total_amount) AS total"],
            'carried, then summed' => [
                $bills . "s.name = 'Acme' WITH un, s AS supplier, b RETURN un.name, sum(supplier.total) AS total",
            ],
            'a later pattern goes through it' => [
                $bills . "s.name = 'Acme' OPTIONAL MATCH (s)-[:CONTACT]->(c:Contact) RETURN sum(b.total_amount) AS total, count(c) AS contacts",
            ],
            'the rows stay separated by the match' => [$bills . "s.name = 'Acme' RETURN s.name AS supplier, sum(b.total_amount) AS total"],
            'an unaliased value is read as a use, whatever a later clause does with it' => [
                "MATCH (b:Bill) OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE s.name = 'Acme' WITH b, sum(b.total_amount) AS total, s.id WITH b, sum(CASE WHEN s.id > 0 THEN total ELSE 0 END) AS t RETURN t",
            ],
            'the same through a group key' => [
                $bills . "s.name = 'Acme' WITH s.name AS supplier, sum(b.total_amount) AS total RETURN supplier, total",
            ],
            'a list, no total' => [$bills . "s.name = 'Acme' RETURN b.id, b.total_amount"],
            'a collect is no total' => [$bills . "s.name = 'Acme' RETURN un.name, collect(b.id) AS bills"],
            'an aggregate before the optional is no total of it' => [
                "MATCH (b:Bill) WITH sum(b.total_amount) AS total OPTIONAL MATCH (s:Supplier) WHERE s.name = 'Acme' RETURN total",
            ],
            'the other branch uses it, this one holds no total' => [
                $bills . "s.name = 'Acme' RETURN b.id AS id UNION MATCH (b:Bill) RETURN count(b) AS id",
            ],
            'the words inside a string' => [
                "MATCH (b:Bill) WHERE b.note = \"OPTIONAL MATCH (s:S) WHERE s.name = 'a' RETURN sum(b.t)\" RETURN sum(b.total_amount) AS total",
            ],
            'the words inside a comment' => [
                "MATCH (b:Bill) /* OPTIONAL MATCH (s:S) WHERE s.name = 'a' */ RETURN sum(b.total_amount) AS total",
            ],
            'a subquery is left alone' => [
                "MATCH (b:Bill) CALL { WITH b OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE s.name = 'a' RETURN s } RETURN sum(b.total_amount) AS total",
            ],
            'input the lexer rejects' => ["MATCH (b:Bill) OPTIONAL MATCH (b)-->(s) WHERE s.name = 'a RETURN sum(b.t)"],
            'empty' => ['   '],
        ];
    }

    /** @test */
    public function each_ineffective_condition_is_reported_once(): void
    {
        $cypher = "MATCH (b:Bill) OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE s.name = 'a' OR s.name = 'b' "
            . "OPTIONAL MATCH (b)-[:OWNER]->(o:Owner) WHERE o.name = 'a' RETURN sum(b.total_amount) AS total";

        $this->assertSame(["s.name = 'a'", "s.name = 'b'", "o.name = 'a'"], $this->check()->ineffectiveConditions($cypher));
        $this->assertCount(2, $this->check()->violations($cypher), 'One violation per clause.');
    }
}
