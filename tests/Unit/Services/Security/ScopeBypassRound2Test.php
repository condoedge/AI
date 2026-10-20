<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Second adversarial review of the scope code (2026-10-01). Every case below
 * ran with a cross-tenant result against the live local Neo4j before the fix.
 *
 * PRINCIPLE: any pattern / label / path syntax the analyzer cannot fully model
 * makes the query REJECTED (safe-stop or SecurityException), never silently
 * skipped. End-to-end through QueryExecutor::applySecurityFilter with the REAL
 * analyzer, coverage gate, acceptance gate and injector.
 */
class ScopeBypassRound2Test extends TestCase
{
    private QueryExecutor $executor;

    public function setUp(): void
    {
        parent::setUp();
        $this->executor = new QueryExecutor(Mockery::mock(GraphStoreInterface::class), []);
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        foreach (['Invoice', 'Union', 'Contact', 'Unit', 'Team', 'Payment'] as $l) {
            config()->set("entities.$l.security.team_path", 'team_id');
        }
        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $security->shouldReceive('getTeamFilterWithParameters')->andReturnUsing(fn ($u, $label, $alias) => [
            'filter' => "WHERE {$alias}.team_id IN \$ai_team_ids", 'parameters' => ['ai_team_ids' => [3]],
        ]);
        $this->app->instance(AiSecurityService::class, $security);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function apply(string $q): array
    {
        $m = (new \ReflectionClass($this->executor))->getMethod('applySecurityFilter');
        $m->setAccessible(true);
        try {
            return $m->invoke($this->executor, $q, ['user' => (object) ['id' => 7]]);
        } catch (SecurityException $e) {
            return ['exception' => $e->getMessage(), 'query' => '', 'filter_applied' => false];
        }
    }

    private function assertRejected(string $q, bool $analyzerRejects = true): void
    {
        $r = $this->apply($q);
        $rejected = isset($r['exception']) || (($r['safe_stop'] ?? false) === true && $r['query'] === '');
        $this->assertTrue($rejected, "expected fail-closed rejection, got: " . json_encode($r));
        if ($analyzerRejects) {
            $this->assertFalse((new CypherScopeAnalyzer())->analyze($q)->isScopeable, "analyzer must not call this scopeable: {$q}");
        }
    }

    // ------------------------------------------------------------------
    // 1. already-bound variable re-labelled in an OPTIONAL MATCH
    // ------------------------------------------------------------------

    public static function forwardOptionalRelabel(): array
    {
        return [
            'label-free MATCH then OPTIONAL label' => ['MATCH (x) OPTIONAL MATCH (x:Invoice) RETURN x'],
            'label-free hop then OPTIONAL label' => ['MATCH (t:Team)--(x) OPTIONAL MATCH (x:Unit) RETURN t.name, x'],
            'OPTIONAL label-free then OPTIONAL label' => ['OPTIONAL MATCH (x) OPTIONAL MATCH (x:Unit) RETURN x'],
            'label-free carried through WITH' => ['MATCH (x) WITH x OPTIONAL MATCH (x:Invoice) RETURN x'],
        ];
    }

    /**
     * @test
     * @dataProvider forwardOptionalRelabel
     */
    public function label_free_binding_relabelled_only_by_an_optional_match_is_rejected(string $q): void
    {
        // An OPTIONAL label never constrains an already-bound variable: x stays
        // ANY node (label-free full scan) → tenancy unprovable → reject.
        $this->assertRejected($q);
    }

    public static function preBoundOptionalRelabel(): array
    {
        return [
            'UNWIND-bound' => ['MATCH (u:Union) WITH collect(u) AS us UNWIND us AS x OPTIONAL MATCH (x:Invoice) RETURN x', 'x'],
            'WITH-AS-bound' => ['MATCH (u:Union) WITH u AS node OPTIONAL MATCH (node:Invoice) RETURN node', 'node'],
            'second label on an optional alias' => ['OPTIONAL MATCH (x:Union) OPTIONAL MATCH (x:Invoice) RETURN x', 'x'],
        ];
    }

    /**
     * @test
     * @dataProvider preBoundOptionalRelabel
     */
    public function pre_bound_alias_in_optional_match_is_scoped_at_row_level(string $q, string $alias): void
    {
        $r = $this->apply($q);
        $this->assertTrue($r['filter_applied'] ?? false, json_encode($r));
        // The OPTIONAL clause cannot filter an already-bound variable; the scope
        // must be a strictly filtering row-level predicate.
        $this->assertMatchesRegularExpression(
            '/WITH \* WHERE (.* AND )?\(' . $alias . ' IS NULL OR ' . $alias . '\.team_id IN \$ai_team_ids\)/',
            $r['query']
        );
        $this->assertDoesNotMatchRegularExpression(
            '/OPTIONAL MATCH \(' . $alias . ':Invoice\) WHERE \(' . $alias . ' IS NULL/',
            $r['query'],
            'scope for a pre-bound alias placed in the OPTIONAL clause (cannot filter it)'
        );
    }

    // ------------------------------------------------------------------
    // 2. GQL / inline label syntax invisible to the analyzer + coverage gate
    // ------------------------------------------------------------------

    public static function invisibleLabels(): array
    {
        return [
            'IS label' => ['MATCH (x IS Invoice) RETURN x'],
            'IS label conj' => ['MATCH (x IS Invoice & Unit) RETURN x'],
            'inline WHERE label test' => ['MATCH (x WHERE x:Invoice) RETURN x'],
            'inline WHERE after label' => ['MATCH (x:Union WHERE x.name = "a") RETURN x'],
            // Expression label tests are legal on an already-scoped binding; the
            // analyzer keeps them, the COVERAGE GATE fails closed on a sensitive
            // label it cannot see bound (x is only scoped as Country).
            'expression label test in WHERE' => ['MATCH (x:Country) WHERE x:Invoice RETURN x', false],
            'expression IS label test in WHERE' => ['MATCH (x:Country) WHERE x IS Invoice RETURN x', false],
            'label test in RETURN' => ['MATCH (x:Country) RETURN x, x:Invoice AS flag', false],
            'IS label in OPTIONAL MATCH' => ['MATCH (t:Team) OPTIONAL MATCH (t)--(x IS Invoice) RETURN t, x'],
            'IS label inside EXISTS' => ['MATCH (t:Team) WHERE EXISTS { MATCH (t)--(x IS Invoice) } RETURN t'],
        ];
    }

    /**
     * @test
     * @dataProvider invisibleLabels
     */
    public function unmodelled_label_syntax_is_rejected(string $q, bool $analyzerRejects = true): void
    {
        $this->assertRejected($q, $analyzerRejects);
    }

    // ------------------------------------------------------------------
    // 3 + 6. keyword used as an identifier
    // ------------------------------------------------------------------

    public static function keywordIdentifiers(): array
    {
        return [
            'path var named match' => ['MATCH (m:Union) OPTIONAL MATCH match = (m) RETURN m.name'],
            'path var named match, generator-scoped' => ['MATCH (m:Union) OPTIONAL MATCH match = (m) WHERE m.team_id IN $ai_team_ids RETURN m.name'],
            'path var named match, two nodes' => ['MATCH (c:Contact) OPTIONAL MATCH match = (c)--(u:Union) RETURN c.name, u.name'],
            'path var named optional' => ['MATCH (m:Unit) OPTIONAL MATCH optional = (m)-[:R]->(x:Payment) RETURN m, x'],
            'node alias named with' => ['MATCH (with:Unit) RETURN with'],
            'operand named match' => ['MATCH (a:Team) OPTIONAL MATCH (x:Unit) WHERE x.v = match OPTIONAL MATCH (y:Contact) RETURN a, x, y'],
            'WITH alias named match' => ['MATCH (x:Unit) WITH x AS match RETURN match'],
        ];
    }

    /**
     * @test
     * @dataProvider keywordIdentifiers
     */
    public function keyword_named_identifiers_are_rejected(string $q): void
    {
        $this->assertRejected($q);
    }

    /** @test */
    public function structural_validator_rejects_a_dangling_operator(): void
    {
        $v = new \Condoedge\Ai\Services\Security\CypherStructuralValidator();
        // The exact rewrite the injector produced from `WHERE x.v = match` (item 6).
        $this->assertFalse($v->isValid(
            'MATCH (a:Team) OPTIONAL MATCH (x:Unit) WHERE (x.v =) AND (x IS NULL OR x.team_id IN $ai_team_ids) match OPTIONAL MATCH (y:Contact) RETURN a,x,y'
        ));
        $this->assertFalse($v->isValid('MATCH (a:Team) OPTIONAL MATCH (x:Unit) WHERE x.v = match OPTIONAL MATCH (y:Contact) RETURN a, x, y'));
        $this->assertFalse($v->isValid('MATCH (a:Team) WHERE a.x = 1 AND RETURN a'));
        // Controls.
        $this->assertTrue($v->isValid('MATCH (a:Team) WHERE a.x = 1 AND NOT (a)--(:Unit) RETURN a, count(*) AS c'));
        $this->assertTrue($v->isValid('MATCH (a:Team) WHERE limit = 1 RETURN a ORDER BY a.x SKIP 1 LIMIT 2'));
    }

    // ------------------------------------------------------------------
    // 4. label expressions
    // ------------------------------------------------------------------

    public static function labelExpressions(): array
    {
        return [
            'disjunction' => ['MATCH (x:Country|Invoice) RETURN x'],
            'disjunction colon' => ['MATCH (x:Country|:Invoice) RETURN x'],
            'conjunction &' => ['MATCH (x:Country&Invoice) RETURN x'],
            'negation' => ['MATCH (x:!Country) RETURN x'],
            'wildcard' => ['MATCH (x:%) RETURN x'],
            'parenthesized' => ['MATCH (x:(Country|Invoice)) RETURN x'],
            'optional disjunction' => ['MATCH (t:Team) OPTIONAL MATCH (t)--(x:Country|Unit) RETURN t, x'],
            'team disjunction' => ['MATCH (x:Team|Invoice) RETURN x'],
        ];
    }

    /**
     * @test
     * @dataProvider labelExpressions
     */
    public function label_expressions_are_rejected(string $q): void
    {
        $this->assertRejected($q);
    }

    /** @test */
    public function plain_label_conjunction_scopes_every_label(): void
    {
        $plan = (new CypherScopeAnalyzer())->analyze('MATCH (x:Unit:Invoice) RETURN x');
        $this->assertTrue($plan->isScopeable);
        $labels = array_map(fn ($b) => $b->alias . ':' . $b->label, $plan->scopes[0]->sensitiveBindings);
        $this->assertSame(['x:Unit', 'x:Invoice'], $labels);

        $r = $this->apply('MATCH (x:Unit:Invoice) RETURN x');
        $this->assertTrue($r['filter_applied']);
    }

    // ------------------------------------------------------------------
    // 5. variable-length paths / path selectors expose unscoped intermediates
    // ------------------------------------------------------------------

    public static function pathExposures(): array
    {
        return [
            'path var over var-length' => ['MATCH p=(a:Unit)-[*1..6]-(b:Unit) RETURN nodes(p)'],
            'bound var-length rel' => ['MATCH (a:Unit)-[r*1..3]-(b:Unit) RETURN [x IN r | startNode(x)]'],
            'bound unbounded var-length rel' => ['MATCH (a:Unit)-[r*]->(b:Unit) RETURN r'],
            'SHORTEST selector' => ['MATCH (a:Team) OPTIONAL MATCH p = SHORTEST 1 (a)-[*]-(x:Unit) RETURN a, x, nodes(p)'],
            'ANY selector' => ['MATCH p = ANY (a:Unit)-[*]-(b:Unit) RETURN nodes(p)'],
            'shortestPath()' => ['MATCH p = shortestPath((a:Unit)-[*]-(b:Unit)) RETURN nodes(p)'],
            'quantified path pattern' => ['MATCH (a:Unit) ((n:Payment)-[:R]->(m:Payment)){1,3} (b:Unit) RETURN n'],
            'COLLECT subquery path' => ['MATCH (a:Unit) RETURN COLLECT { MATCH p = (a)-[*]-(b:Unit) RETURN nodes(p) } AS ns'],
        ];
    }

    /**
     * @test
     * @dataProvider pathExposures
     */
    public function var_length_path_exposure_is_rejected(string $q): void
    {
        $this->assertRejected($q);
    }

    // ------------------------------------------------------------------
    // 7. code the analyzer cannot see: procedures / functions that run Cypher
    //    from a string, and nested subqueries / comprehensions that introduce
    //    NEW (unscoped) node variables. Live before the fix: apoc.cypher.* →
    //    all 6006 invoices; COLLECT{} / CALL () {} → 97,846 foreign nodes;
    //    COUNT{} → 63,498 foreign invoice links.
    // ------------------------------------------------------------------

    public static function invisibleCode(): array
    {
        return [
            'apoc.cypher.run procedure' => ["CALL apoc.cypher.run('MATCH (i:Invoice) RETURN i.id AS id', {}) YIELD value RETURN count(value) AS n"],
            'apoc.cypher function' => ["RETURN size(apoc.cypher.runFirstColumnMany('MATCH (i:Invoice) RETURN i.id', {})) AS n"],
            'apoc.cypher function after MATCH' => ["MATCH (u:Unit) RETURN u, apoc.cypher.runFirstColumnSingle('MATCH (i:Invoice) RETURN count(i)', {}) AS n"],
            'fulltext procedure' => ["CALL db.index.fulltext.queryNodes('idx', 'foo') YIELD node RETURN node"],
            'backticked procedure' => ["CALL `apoc`.`cypher`.`run`('MATCH (i:Invoice) RETURN i', {}) YIELD value RETURN value"],
            'COLLECT subquery new var' => ['RETURN COLLECT { MATCH (n) RETURN n.team_id } AS xs'],
            'COUNT subquery new var' => ['MATCH (t:Team) RETURN COUNT { MATCH (x)-[:BELONGS_TO_UNION]->(y) } AS n'],
            'scoped CALL subquery' => ['CALL () { MATCH (n) RETURN n } RETURN count(n) AS c'],
            'scoped CALL subquery importing' => ['MATCH (u:Unit) CALL (u) { MATCH (u)--(n) RETURN n } RETURN n'],
            'pattern comprehension new var' => ['MATCH (t:Team) RETURN [(x)-[:BELONGS_TO_UNION]->(y) | x.id] AS ids'],
            'EXISTS unanchored' => ['MATCH (t:Team) WHERE EXISTS { MATCH (z) WHERE z.ssn = "1" } RETURN t'],
        ];
    }

    /**
     * @test
     * @dataProvider invisibleCode
     */
    public function invisible_code_paths_are_rejected(string $q): void
    {
        $this->assertRejected($q);
    }

    /** @test */
    public function pure_value_functions_and_metadata_procedures_still_run(): void
    {
        $r = $this->apply("MATCH (i:Invoice) RETURN apoc.date.format(i.ts, 'ms', 'yyyy') AS y, date.truncate('month', date()) AS m");
        $this->assertTrue($r['filter_applied'], json_encode($r));

        $r = $this->apply('CALL db.labels() YIELD label RETURN label');
        $this->assertFalse($r['safe_stop'] ?? false);
        $this->assertArrayNotHasKey('exception', $r);
    }

    /** @test */
    public function anonymous_var_length_between_scoped_endpoints_is_still_scoped(): void
    {
        $r = $this->apply('MATCH (a:Unit)-[*1..3]-(b:Unit) RETURN a.name, b.name');
        $this->assertTrue($r['filter_applied'], json_encode($r));
        $this->assertStringContainsString('a.team_id IN $ai_team_ids', $r['query']);
        $this->assertStringContainsString('b.team_id IN $ai_team_ids', $r['query']);
    }

    // ------------------------------------------------------------------
    // Positive controls: the shapes the generator actually emits are unchanged
    // ------------------------------------------------------------------

    public static function legitShapes(): array
    {
        return [
            ['MATCH (u:Unit)-[:UNION]->(n:Union) WHERE n.team_id IN $ai_team_ids RETURN u.name'],
            ['MATCH (t:Team) WHERE t.id = 3 OPTIONAL MATCH (c:Contact)-[:TEAM]->(t) RETURN t, c'],
            ['MATCH (i:Invoice)<-[:FOR]-(p:Payment) WITH i, sum(p.amount) AS paid RETURN i.id, paid ORDER BY paid DESC LIMIT 5'],
            ['MATCH (i:Invoice) WHERE i.status STARTS WITH "o" RETURN count(i) AS n'],
            ['MATCH (n:Unit)-[:UNION]->(un:Union) MATCH (a:Invoice) WHERE ((a)-[:BELONGS_TO_UNION]->(un) OR (a)-[:FOR]->(n)) RETURN a'],
            ['MATCH (x:Unit {name: "310"})-[:UNION|BELONGS_TO_UNION]->(n:Union) RETURN x'],
            ['MATCH (a:Unit)<--(p:Payment), (a)-->(n:Union) RETURN a, p, n'],
            ['UNWIND [1, 2] AS k MATCH (x:Unit) WHERE x.k = k RETURN x'],
            // live eval p11-1001-A row 12 shape: a map VALUE named like a label
            ['MATCH (c:Contact) WITH c.name AS contact, count(*) AS n RETURN collect({contact: contact, n: n}) AS per_contact'],
        ];
    }

    /**
     * @test
     * @dataProvider legitShapes
     */
    public function legit_generator_shapes_still_scope(string $q): void
    {
        $this->assertTrue((new CypherScopeAnalyzer())->analyze($q)->isScopeable, (new CypherScopeAnalyzer())->analyze($q)->reason ?? '');
        $r = $this->apply($q);
        $this->assertTrue($r['filter_applied'] ?? false, json_encode($r));
    }
}
