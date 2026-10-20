<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\CypherSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Task 5.4 — Cypher schema validation (the never-built "G4").
 *
 * The measured failures it must catch: invented relationships
 * `(c:Contact)<-[:CUSTOMER]-(i)`, `Invoice-[:USER]->Contact`,
 * `TeamRole-[:BELONGS_TO_USER]->User`, reversed directions, a nonexistent
 * `a.balance`, unknown labels. And it must NEVER flag a valid query.
 */
class CypherSchemaValidatorTest extends TestCase
{
    private CypherSchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new CypherSchemaValidator();
    }

    /** A small schema shaped like PipelineContext::$graphSchema (projected + full-graph keys). */
    private function contextSchema(): array
    {
        return CypherSchemaValidator::schemaFromContext([
            'labels' => ['Invoice', 'Contact'],
            'all_labels' => ['Invoice', 'Contact', 'Unit', 'Union', 'Team', 'User', 'TeamRole', 'Account', 'ContactUnit', 'BoardMember'],
            'all_relationship_types' => ['CUSTOMER', 'BELONGS_TO_UNION', 'UNION', 'TEAM', 'USER', 'CONTACT', 'UNIT', 'BELONGS_TO_CONTACT'],
            'all_relationship_patterns' => [
                ['from_label' => 'Invoice', 'type' => 'CUSTOMER', 'to_label' => 'Unit'],
                ['from_label' => 'Invoice', 'type' => 'BELONGS_TO_UNION', 'to_label' => 'Union'],
                ['from_label' => 'Account', 'type' => 'BELONGS_TO_UNION', 'to_label' => 'Union'],
                ['from_label' => 'BoardMember', 'type' => 'BELONGS_TO_UNION', 'to_label' => 'Union'],
                ['from_label' => 'BoardMember', 'type' => 'BELONGS_TO_CONTACT', 'to_label' => 'Contact'],
                ['from_label' => 'Unit', 'type' => 'UNION', 'to_label' => 'Union'],
                ['from_label' => 'Union', 'type' => 'TEAM', 'to_label' => 'Team'],
                ['from_label' => 'Contact', 'type' => 'USER', 'to_label' => 'User'],
                ['from_label' => 'ContactUnit', 'type' => 'CONTACT', 'to_label' => 'Contact'],
                ['from_label' => 'ContactUnit', 'type' => 'UNIT', 'to_label' => 'Unit'],
            ],
            'properties' => [
                'Invoice' => ['id', 'calc_due_amount', 'customer_type', 'customer_id', 'due_at'],
                'Contact' => ['id', 'first_name', 'last_name', 'user_id'],
                'Account' => ['id', 'name', 'union_id'],
            ],
            'property_types' => [
                'Invoice' => ['id' => 'Integer', 'due_at' => 'String(ISO date)'],
                'Account' => ['id' => 'Integer', 'name' => 'String'],
            ],
            'all_property_keys' => [
                'id', 'name', 'calc_due_amount', 'customer_type', 'customer_id', 'due_at', 'first_name', 'last_name',
                'user_id', 'union_id', 'team_id', 'deleted_at', 'sold_at', 'year', 'bm_role', 'role', 'current_team_id', 'email',
            ],
        ]);
    }

    private function violations(string $cypher): array
    {
        return $this->validator->validate($cypher, $this->contextSchema());
    }

    // ------------------------------------------------------------- violations

    /** @test */
    public function an_invented_customer_edge_is_reported_with_the_existing_edges_of_the_source(): void
    {
        $v = $this->violations(
            'MATCH (un:Union) MATCH (i:Invoice)-[:BELONGS_TO_UNION]->(un) '
            . 'MATCH (c:Contact)<-[:CUSTOMER]-(i) RETURN c.first_name'
        );

        $this->assertCount(1, $v);
        $this->assertStringContainsString('(Contact)<-[:CUSTOMER]-(Invoice)', $v[0]);
        $this->assertStringContainsString('does not exist', $v[0]);
        $this->assertStringContainsString('Existing relationships from Invoice:', $v[0]);
        $this->assertStringContainsString('(Invoice)-[:CUSTOMER]->(Unit)', $v[0]);
    }

    /** @test */
    public function an_edge_type_used_between_the_wrong_labels_is_reported(): void
    {
        $v = $this->violations('MATCH (i:Invoice)-[:USER]->(c:Contact) RETURN i');

        $this->assertCount(1, $v);
        $this->assertStringContainsString('(Invoice)-[:USER]->(Contact)', $v[0]);
        $this->assertStringContainsString('Existing :USER relationships: (Contact)-[:USER]->(User)', $v[0]);
    }

    /** @test */
    public function a_reversed_direction_names_the_existing_direction(): void
    {
        $v = $this->violations('MATCH (un:Union)-[:UNION]->(u:Unit) RETURN u.id');

        $this->assertCount(1, $v);
        $this->assertStringContainsString('(Union)-[:UNION]->(Unit)', $v[0]);
        $this->assertStringContainsString('did you mean (Unit)-[:UNION]->(Union)?', $v[0]);
    }

    /** @test */
    public function an_unknown_relationship_type_is_reported_with_a_close_suggestion(): void
    {
        $v = $this->violations('MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u:User) RETURN u');

        $this->assertCount(1, $v);
        $this->assertStringContainsString('Relationship type :BELONGS_TO_USER does not exist', $v[0]);
    }

    /** @test */
    public function a_nonexistent_property_on_a_labelled_variable_is_reported(): void
    {
        $v = $this->violations('MATCH (a:Account)-[:BELONGS_TO_UNION]->(u:Union) RETURN a.name, toFloat(a.balance) AS balance');

        $this->assertCount(1, $v);
        $this->assertStringContainsString('Property `balance` does not exist on :Account', $v[0]);
        $this->assertStringContainsString('Existing :Account properties: id, name, union_id', $v[0]);
    }

    /** @test */
    public function a_property_typo_gets_the_closest_existing_property(): void
    {
        $v = $this->violations('MATCH (i:Invoice) RETURN i.calc_due_amout');

        $this->assertCount(1, $v);
        $this->assertStringContainsString('Did you mean `calc_due_amount`?', $v[0]);
    }

    /** @test */
    public function an_unknown_label_is_reported_with_a_suggestion(): void
    {
        $v = $this->violations('MATCH (c:Contacts) RETURN c');

        $this->assertSame(['Label :Contacts does not exist in the graph. Did you mean :Contact?'], $v);
    }

    /** @test */
    public function a_label_carried_through_a_with_rename_is_still_checked(): void
    {
        $v = $this->violations('MATCH (a:Account) WITH a AS acct MATCH (acct)-[:CUSTOMER]->(u:Unit) RETURN acct.balance');

        $this->assertCount(2, $v);
        $this->assertStringContainsString('(Account)-[:CUSTOMER]->(Unit)', $v[0]);
        $this->assertStringContainsString('Property `balance` does not exist on :Account', $v[1]);
    }

    /** @test */
    public function an_inline_map_key_that_exists_nowhere_is_reported(): void
    {
        $v = $this->violations('MATCH (i:Invoice {balance: 3}) RETURN i.id');

        $this->assertCount(1, $v);
        $this->assertStringContainsString('Property `balance` does not exist on :Invoice', $v[0]);
    }

    // ---------------------------------------------------------- valid queries

    /**
     * @test
     * @dataProvider validQueries
     */
    public function valid_queries_produce_no_violation(string $cypher): void
    {
        $this->assertSame([], $this->violations($cypher));
    }

    public static function validQueries(): array
    {
        return [
            'multi-hop' => ['MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit)-[:UNION]->(un:Union) '
                . 'WHERE cu.sold_at IS NULL RETURN u.id, un.name'],
            'optional match' => ['MATCH (b:Invoice) WHERE toFloat(b.calc_due_amount) > 0 OPTIONAL MATCH (b)-[:CUSTOMER]->(u:Unit) '
                . 'RETURN sum(toFloat(b.calc_due_amount)) AS total, collect(DISTINCT u.id)[..10] AS units'],
            'with aliasing (computed alias shadows the node name)' => ['MATCH (a:Account) WITH a.name AS a RETURN a'],
            'with aliasing (rename keeps the label)' => ['MATCH (c:Contact) WITH c AS person RETURN person.first_name'],
            'with aggregation then re-match' => ['MATCH (b:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) WITH un, max(b.year) AS latest '
                . 'MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un) WHERE cur.year = latest '
                . 'MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) RETURN un.name, c.first_name, cur.bm_role'],
            'dead back-reference after WITH is a fresh variable' => ['MATCH (u:Unit) WITH count(u) AS n MATCH (u)-[:BELONGS_TO_UNION]->(x:Union) RETURN n, x.name'],
            'map projection' => ['MATCH (c:Contact) RETURN c {.first_name, .last_name, full: c.first_name + " " + c.last_name} AS contact'],
            'union' => ['MATCH (i:Invoice) RETURN i.id AS id UNION MATCH (a:Account) RETURN a.id AS id'],
            'union rebinding the same variable to another label' => ['MATCH (x:Invoice)-[:CUSTOMER]->(u:Unit) RETURN x.id AS id '
                . 'UNION MATCH (x:Account)-[:BELONGS_TO_UNION]->(u:Union) RETURN x.id AS id'],
            'exists subquery' => ['MATCH (c:Contact) WHERE EXISTS { MATCH (c)-[:USER]->(:User) } RETURN c.id'],
            'pattern predicate' => ['MATCH (i:Invoice) WHERE (i)-[:CUSTOMER]->(:Unit) AND NOT (i)-[:BELONGS_TO_UNION]->(:Union {name: "x"}) RETURN i.id'],
            'undirected edge' => ['MATCH (u:Unit)-[:UNION]-(un:Union) RETURN un.name'],
            'variable length' => ['MATCH (c:Contact)-[:USER*1..3]->(x:Invoice) RETURN x.id'],
            'untyped edge' => ['MATCH (c:Contact)-[r]->(x) RETURN type(r), x.anything'],
            'unwind alias' => ['MATCH (c:Contact) UNWIND [c] AS c2 RETURN c2.nonexistent_key'],
            'list comprehension variable' => ['MATCH (c:Contact) RETURN [x IN collect(c) | x.whatever] AS xs'],
            'reduce accumulator' => ['MATCH (i:Invoice) RETURN reduce(total = 0, a IN collect(i) | total + a.whatever) AS s'],
            'namespaced function' => ['MATCH (i:Invoice) RETURN date.truncate("month", date()) AS m, i.due_at'],
            'sparse property present elsewhere in the graph' => ['MATCH (un:Union) WHERE un.deleted_at IS NULL RETURN un.team_id'],
            'string that looks like a pattern' => ['MATCH (i:Invoice) WHERE i.customer_type = "(c:Nope)<-[:FAKE]-(x)" RETURN i.id'],
            'fk join without an edge' => ['MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
                . 'OPTIONAL MATCH (tr:TeamRole) WHERE tr.team_id = t.id AND tr.user_id = u.id RETURN u.email, tr.role'],
            'call subquery local variable' => ['CALL { MATCH (a:Account) RETURN a.name AS n } MATCH (a)-[:CUSTOMER]->(u:Unit) RETURN n, u.id'],
            'pattern comprehension' => ['MATCH (i:Invoice) RETURN [(i)-[:CUSTOMER]->(u:Unit) | u.id] AS units'],
            'path variable' => ['MATCH p = (i:Invoice)-[:CUSTOMER]->(u:Unit) RETURN length(p), nodes(p)'],
            'label disjunction' => ['MATCH (x:Invoice|Account)-[:BELONGS_TO_UNION]->(u:Union) RETURN x.id'],
            'backtick identifiers' => ['MATCH (`the invoice`:`Invoice`)-[:`CUSTOMER`]->(u:Unit) RETURN `the invoice`.`due_at`'],
            'quantified path pattern' => ['MATCH ((a)-[:CUSTOMER]->(b)){1,3} RETURN size(a) AS hops'],
            'quantified path pattern between labelled endpoints' => ['MATCH (c:Contact) ((x)-[:USER]->(y)){1,2} (u:User) RETURN c.id, u.id'],
            'quantified path pattern with labelled group nodes' => ['MATCH ((u:Unit)-[:UNION]->(un:Union)){1,3} RETURN size(u) AS n'],
            'optional match with an unbound variable' => ['MATCH (i:Invoice) OPTIONAL MATCH (i)-[:CUSTOMER]->(x) RETURN i.id, x.name'],
            'optional match whose only labelled end is the target' => ['OPTIONAL MATCH (z)-[:USER]->(u:User) RETURN z.first_name, u.id'],
            'count wrapper' => ['CALL { MATCH (i:Invoice)-[:CUSTOMER]->(u:Unit) RETURN i } RETURN count(*) AS total'],
            'unlexable input yields nothing' => ['MATCH (i:Invoice) WHERE i.x = "unterminated RETURN i'],
        ];
    }

    /** @test */
    public function missing_schema_facts_disable_the_checks_that_need_them(): void
    {
        // No full-graph keys at all (a context without all_* facts): nothing is provable.
        $facts = CypherSchemaValidator::schemaFromContext(['labels' => ['Invoice']]);

        $this->assertSame([], $this->validator->validate(
            'MATCH (c:Nope)<-[:INVENTED]-(i:Invoice) RETURN i.balance',
            $facts
        ));
    }

    /** @test */
    public function patterns_unknown_still_checks_types_but_not_triples(): void
    {
        $facts = CypherSchemaValidator::schemaFromContext([
            'all_labels' => ['Invoice', 'Contact'],
            'all_relationship_types' => ['CUSTOMER'],
            'all_relationship_patterns' => null,
        ]);

        $this->assertSame([], $this->validator->validate('MATCH (c:Contact)<-[:CUSTOMER]-(i:Invoice) RETURN i', $facts));
        $this->assertCount(1, $this->validator->validate('MATCH (c:Contact)<-[:INVENTED]-(i:Invoice) RETURN i', $facts));
    }

    /**
     * Phase 5A: a failed full-graph pattern scan leaves relationshipPatterns NULL
     * ("edges unknown"), never [] — edge + direction checks must report nothing.
     * An EMPTY pattern list is treated the same way (never "no edges exist").
     *
     * @test
     * @dataProvider unknownPatternShapes
     */
    public function unknown_relationship_patterns_never_produce_edge_or_direction_violations(mixed $patterns): void
    {
        $facts = CypherSchemaValidator::schemaFromFullSchema([
            'labels' => ['Invoice', 'Contact', 'Unit', 'Union'],
            'relationships' => ['CUSTOMER', 'UNION'],
            'relationshipPatterns' => $patterns,
            'propertyKeys' => ['id'],
        ]);

        $this->assertSame([], $this->validator->validate(
            'MATCH (c:Contact)<-[:CUSTOMER]-(i:Invoice) MATCH (un:Union)-[:UNION]->(u:Unit) RETURN i.id, u.id',
            $facts
        ));
    }

    public static function unknownPatternShapes(): array
    {
        return ['null (scan failed)' => [null], 'empty list' => [[]]];
    }

    // ------------------------------------------------------------------ corpus

    /**
     * Every real CondoEdge cypher that EXECUTED WITH ROWS in the 2026-09-29/30 eval
     * runs, plus the host's canonical calculation cyphers, validated against the live
     * CondoEdge graph schema (structure only: labels, edge patterns, property keys,
     * sampled property names): zero violations.
     *
     * @test
     */
    public function the_condoedge_corpus_of_executed_queries_validates_clean(): void
    {
        $dir = dirname(__DIR__, 3) . '/Fixtures/schema-validator';
        $schema = json_decode((string) file_get_contents($dir . '/condoedge-live-schema.json'), true);
        $corpus = json_decode((string) file_get_contents($dir . '/condoedge-cypher-corpus.json'), true);
        $facts = CypherSchemaValidator::schemaFromFullSchema($schema);

        $this->assertGreaterThanOrEqual(40, count($corpus));

        $flagged = [];
        foreach ($corpus as $entry) {
            $violations = $this->validator->validate($entry['cypher'], $facts);
            if ($violations !== []) {
                $flagged[] = $entry['source'] . ': ' . $entry['cypher'] . ' => ' . implode(' | ', $violations);
            }
        }

        $this->assertSame([], $flagged);
    }

    /**
     * The historical invented-edge / invented-property drafts from the same eval
     * history ARE caught against the live schema.
     *
     * @test
     * @dataProvider historicalFailures
     */
    public function the_historical_failures_are_caught_against_the_live_schema(string $cypher, string $expected): void
    {
        $dir = dirname(__DIR__, 3) . '/Fixtures/schema-validator';
        $facts = CypherSchemaValidator::schemaFromFullSchema(
            json_decode((string) file_get_contents($dir . '/condoedge-live-schema.json'), true)
        );

        $violations = $this->validator->validate($cypher, $facts);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString($expected, implode("\n", $violations));
    }

    public static function historicalFailures(): array
    {
        return [
            'invented CUSTOMER edge to Contact' => [
                'MATCH (u:Union)-[:TEAM]->(t:Team) WHERE t.id IN $ai_team_ids MATCH (i:Invoice)-[:BELONGS_TO_UNION]->(u) '
                . 'WHERE toFloat(i.calc_due_amount) > 0 MATCH (c:Contact)<-[:CUSTOMER]-(i) RETURN c.first_name LIMIT 100',
                '(Contact)<-[:CUSTOMER]-(Invoice)',
            ],
            'invented USER edge from Invoice' => [
                'MATCH (i:Invoice)-[:BELONGS_TO_UNION]->(u:Union) OPTIONAL MATCH (c:Contact)<-[:USER]-(i) RETURN c.first_name',
                '(Contact)<-[:USER]-(Invoice)',
            ],
            'reversed USER edge' => [
                'MATCH (u:User) OPTIONAL MATCH (u)-[:USER]->(c:Contact) RETURN u.name, c.first_name',
                'did you mean (Contact)-[:USER]->(User)?',
            ],
            'invented TeamRole edge' => [
                'MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u:User) RETURN u.name',
                '(TeamRole)-[:BELONGS_TO_USER]->(User)',
            ],
            'invented Account.balance' => [
                'MATCH (a:Account)-[:BELONGS_TO_UNION]->(u:Union) RETURN a.name, toFloat(a.balance) AS balance',
                'Property `balance` does not exist on :Account',
            ],
        ];
    }
}
