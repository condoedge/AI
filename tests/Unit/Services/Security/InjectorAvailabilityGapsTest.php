<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\CypherLexer;
use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\Scope\FilterClause;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\SecurityFilterInjector;
use Condoedge\Ai\Tests\TestCase;

/**
 * Task 1.26 — fail-closed AVAILABILITY gaps in the tenant-scoping rewrite.
 *
 * (1) When the clause right before a scope's anchor cannot carry a WHERE
 *     (UNWIND … AS x, SET/REMOVE/DELETE/CREATE/MERGE/FOREACH, a CALL (…) {…}
 *     subquery), a WHERE-kind filter was spliced there as a bare `WHERE …` —
 *     invalid Cypher (Neo4j SyntaxError → the query never ran). It must be
 *     emitted at row level as `WITH * WHERE …`; a MATCH-hop after an update
 *     clause likewise needs `WITH *` first. The structural validator must reject
 *     a bare WHERE directly after those clause heads (defence in depth).
 * (2) Variables NAMED like a clause-head keyword (`limit`, `skip`, `offset` …)
 *     ended the WHERE predicate early, so a WHERE-kind filter opened a second,
 *     adjacent WHERE. They must be read as variables in operand position.
 *
 * Every assertion also pins that scoping stays COMPLETE (never passed through
 * unscoped).
 */
class InjectorAvailabilityGapsTest extends TestCase
{
    private CypherScopeAnalyzer $analyzer;
    private SecurityFilterInjector $injector;

    public function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new CypherScopeAnalyzer();
        $this->injector = new SecurityFilterInjector();
    }

    /** WHERE-kind (direct team_id) filter for every sensitive binding. */
    private function directFilter(): callable
    {
        return static fn (NodeBinding $b): FilterClause => new FilterClause(
            'where',
            "WHERE {$b->alias}.team_id IN \$ai_team_ids",
            ['ai_team_ids' => [14]]
        );
    }

    /** MATCH-hop filter for every sensitive binding. */
    private function hopFilter(): callable
    {
        return static fn (NodeBinding $b): FilterClause => new FilterClause(
            'match',
            "MATCH ({$b->alias})-[:BELONGS_TO_UNION]->(u1:Union) WHERE u1.team_id IN \$ai_team_ids",
            ['ai_team_ids' => [14]]
        );
    }

    private function rewrite(string $cypher, callable $filter): string
    {
        $plan = $this->analyzer->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "must stay scopeable, rejected ({$plan->reason}): {$cypher}");

        $out = $this->injector->inject($cypher, $plan, $filter);
        $this->assertNotNull($out, "must be injectable (fail-closed here blocks a valid query): {$cypher}");
        $this->assertTrue(
            CypherStructuralValidator::isValid($out),
            'rewrite is not structurally valid: ' . $out . ' | ' . (CypherStructuralValidator::diagnose($out) ?? '')
        );

        return $out;
    }

    // =====================================================================
    // (1) Anchor clause cannot carry a WHERE
    // =====================================================================

    /**
     * @test
     * @dataProvider noWhereAnchorClauses
     */
    public function where_kind_filter_after_a_non_where_clause_is_emitted_at_row_level(string $cypher, string $clauseTail): void
    {
        $out = $this->rewrite($cypher, $this->directFilter());

        $this->assertStringContainsString(
            $clauseTail . ' WITH * WHERE i.team_id IN $ai_team_ids',
            $out,
            "scope must be enforced at row level after the clause: {$out}"
        );
        $this->assertStringNotContainsString($clauseTail . ' WHERE', $out, "bare WHERE after the clause: {$out}");
    }

    /**
     * @test
     * @dataProvider noWhereAnchorClauses
     */
    public function match_hop_filter_after_a_non_where_clause_stays_valid(string $cypher, string $clauseTail): void
    {
        $out = $this->rewrite($cypher, $this->hopFilter());

        $this->assertMatchesRegularExpression(
            '/MATCH \(i\)-\[:BELONGS_TO_UNION\]->\((ai_s\d+):Union\) WHERE \1\.team_id IN \$ai_team_ids/',
            $out,
            "i left unscoped: {$out}"
        );
        // After an update clause a MATCH needs WITH first; `WITH *` is emitted
        // for every non-WHERE clause (harmless after UNWIND / CALL).
        $this->assertStringContainsString($clauseTail . ' WITH * MATCH (i)-', $out);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function noWhereAnchorClauses(): array
    {
        return [
            'UNWIND … AS x' => [
                'MATCH (i:Invoice) UNWIND [1] AS x WITH i, x AS ends RETURN i',
                'UNWIND [1] AS x',
            ],
            'SET label' => [
                'MATCH (i:Invoice) SET i:Seen WITH i RETURN i',
                'SET i:Seen',
            ],
            'CALL (i) { … } subquery' => [
                'MATCH (i:Invoice) CALL (i) { RETURN i.total AS t } WITH i, t RETURN i',
                'CALL (i) { RETURN i.total AS t }',
            ],
            'UNWIND after a WHERE' => [
                'MATCH (i:Invoice) WHERE i.a = 1 UNWIND [1] AS x RETURN i, x',
                'UNWIND [1] AS x',
            ],
        ];
    }

    /** @test */
    public function where_kind_filter_after_a_match_is_unchanged(): void
    {
        // Control: the common shape keeps its plain spliced WHERE (no WITH *).
        $out = $this->rewrite('MATCH (i:Invoice) RETURN i', $this->directFilter());
        $this->assertSame('MATCH (i:Invoice) WHERE i.team_id IN $ai_team_ids RETURN i', $out);
    }

    // =====================================================================
    // (1b) Structural validator — defence in depth
    // =====================================================================

    /** @test */
    public function validator_rejects_a_bare_where_after_a_non_where_clause(): void
    {
        foreach ([
            'MATCH (i:Invoice) UNWIND [1] AS x WHERE i.team_id IN $ai_team_ids RETURN i',
            'MATCH (i:Invoice) SET i:Seen WHERE i.team_id IN $ai_team_ids RETURN i',
            'MATCH (i:Invoice) CALL (i) { RETURN i.total AS t } WHERE i.team_id IN $ai_team_ids RETURN i',
            'MATCH (i:Invoice) REMOVE i:Seen WHERE i.a = 1 RETURN i',
            'MATCH (i:Invoice) DETACH DELETE i WHERE i.a = 1 RETURN count(*)',
            'CREATE (n:Note) WHERE n.a = 1 RETURN n',
            'MERGE (n:Note {id: 1}) WHERE n.a = 1 RETURN n',
            // Inside the count wrapper (brace depth 1) too.
            'CALL { MATCH (i:Invoice) UNWIND [1] AS x WHERE i.a = 1 RETURN i } RETURN count(*) AS total',
        ] as $bad) {
            $this->assertNotNull(CypherStructuralValidator::diagnose($bad), "must be rejected: {$bad}");
        }
    }

    /** @test */
    public function validator_accepts_where_where_it_is_legal(): void
    {
        foreach ([
            'MATCH (i:Invoice) UNWIND [1] AS x WITH * WHERE i.team_id IN $ai_team_ids RETURN i',
            'MATCH (i:Invoice) SET i:Seen WITH * WHERE i.team_id IN $ai_team_ids RETURN i',
            'MATCH (i:Invoice) CALL (i) { MATCH (i)-->(x) WHERE x.a = 1 RETURN x } WITH * WHERE i.a = 1 RETURN i',
            'CALL db.labels() YIELD label WHERE label STARTS WITH \'A\' RETURN label',
            'MATCH (i:Invoice) OPTIONAL MATCH (i)-->(x) WHERE x.a = 1 RETURN i',
            'CALL { MATCH (i:Invoice) UNWIND [1] AS x WITH * WHERE i.a = 1 RETURN i } RETURN count(*) AS total',
        ] as $ok) {
            $this->assertNull(CypherStructuralValidator::diagnose($ok), $ok);
        }
    }

    // =====================================================================
    // (2) Keyword-named variables must not end the WHERE (WHERE-kind filter)
    // =====================================================================

    /**
     * @test
     * @dataProvider keywordNamedVariableWheres
     */
    public function keyword_named_variable_does_not_end_the_where(string $cypher, string $expectedTail): void
    {
        $out = $this->rewrite($cypher, $this->directFilter());

        $this->assertStringContainsString($expectedTail, $out, "WHERE split at the keyword-named variable: {$out}");
        $this->assertSame(1, substr_count($out, 'j.team_id IN $ai_team_ids'));
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $out);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function keywordNamedVariableWheres(): array
    {
        return [
            'limit STARTS WITH' => [
                "MATCH (i:Invoice) WITH i, i.ref AS limit MATCH (j:Invoice) WHERE limit STARTS WITH 'x' RETURN j",
                "WHERE limit STARTS WITH 'x' AND j.team_id IN \$ai_team_ids RETURN j",
            ],
            'AND skip ENDS WITH' => [
                "MATCH (i:Invoice) WITH i, i.ref AS skip MATCH (j:Invoice) WHERE j.a = 1 AND skip ENDS WITH 'x' RETURN j",
                "WHERE j.a = 1 AND skip ENDS WITH 'x' AND j.team_id IN \$ai_team_ids RETURN j",
            ],
            'offset comparison' => [
                'MATCH (i:Invoice) WITH i, i.n AS offset MATCH (j:Invoice) WHERE j.n > offset RETURN j',
                'WHERE j.n > offset AND j.team_id IN $ai_team_ids RETURN j',
            ],
            'limit with top-level OR' => [
                "MATCH (i:Invoice) WITH i, i.ref AS limit MATCH (j:Invoice) WHERE j.a = 1 OR limit = 'x' RETURN j",
                "WHERE (j.a = 1 OR limit = 'x') AND j.team_id IN \$ai_team_ids RETURN j",
            ],
        ];
    }

    /** @test */
    public function lexer_flags_keyword_named_variables_but_not_real_clauses(): void
    {
        $flagged = static function (string $cypher): array {
            $out = [];
            foreach ((new CypherLexer())->tokenize($cypher)['tokens'] as $t) {
                // Only clause-keyword words (every property / label name is
                // flagged too since the 1.26 follow-up; that is not the point here).
                if ($t->keywordAsIdentifier
                    && in_array(strtoupper($t->value), ['SKIP', 'LIMIT', 'OFFSET', 'ORDER', 'MATCH', 'OPTIONAL', 'UNION', 'BY'], true)) {
                    $out[] = strtolower($t->value) . '@' . $t->offset;
                }
            }
            return $out;
        };

        // Variables (operand position, or ORDER without BY) ARE flagged.
        $q = 'WITH 1 AS x, x.ref AS limit MATCH (j) WHERE limit = 1 AND skip > 2 RETURN count(order) AS c';
        $this->assertSame([
            'limit@' . strpos($q, 'limit MATCH'),
            'limit@' . strpos($q, 'limit ='),
            'skip@' . strpos($q, 'skip'),
            'order@' . strpos($q, 'order'),
        ], $flagged($q));

        // Real clause SKIP / LIMIT / OFFSET / ORDER BY / MATCH … — never flagged.
        foreach ([
            'MATCH (n) RETURN n LIMIT 1',
            'MATCH (n) RETURN n ORDER BY n.x SKIP 1 LIMIT 2',
            'MATCH (n) RETURN n ORDER BY n.x DESC LIMIT 2',
            'MATCH (n) RETURN count(*) AS c ORDER BY c LIMIT $l',
            'MATCH (n) WITH * LIMIT 1 RETURN n',
            "MATCH (n) RETURN 'a' LIMIT 1",
            'MATCH (n) RETURN n SKIP $s OFFSET 1',
            'MATCH (n) RETURN n ORDER /* c */ BY n.x',
            'MATCH (n) OPTIONAL MATCH (n)-->(m) RETURN m',
            'MATCH (n) RETURN n UNION ALL MATCH (n) RETURN n',
            'MATCH (n) WHERE n.a IS NULL MATCH (m) RETURN m',
            // A real LIMIT/SKIP after an ALIAS named like an operand-expecting
            // keyword (`AS where`, `AS in`, …) — the alias ENDS an operand.
            'MATCH (n) WITH n AS where LIMIT 1 RETURN where',
            'MATCH (n) WITH n, 1 AS in LIMIT 1 RETURN n',
            'MATCH (n) WITH n AS set SKIP 1 RETURN set',
            'MATCH (n) WITH n AS yield LIMIT 1 RETURN yield',
            'MATCH (n) WITH n AS by SKIP 1 LIMIT 2 RETURN by',
        ] as $cypher) {
            $this->assertSame([], $flagged($cypher), "a real clause keyword was flagged as a variable: {$cypher}");
        }

        // A property key named like a clause is an identifier; the clause is not.
        $this->assertSame(['limit@' . strpos('MATCH (n) RETURN n.limit LIMIT 1', 'limit')], $flagged('MATCH (n) RETURN n.limit LIMIT 1'));
    }
}
