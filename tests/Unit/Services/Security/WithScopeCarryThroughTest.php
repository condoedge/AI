<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\ScopeAcceptanceGate;
use Condoedge\Ai\Services\Security\Scope\FilterClause;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\SecurityFilterInjector;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * G2/L5 item E — WITH-scope carry-through (iteration-11 verdict, board blocker).
 *
 * Reproduced live 2026-07-23: the canonical board query — scoped by the
 * generator on `un` (`un.team_id IN $ai_team_ids`), aggregating through
 * `WITH un, max(b.year) AS latest_year`, then re-matching BoardMembers of the
 * SAME (already-scoped, re-projected) union — is valid Cypher returning 3 rows
 * via a direct graph query, yet QueryExecutor::execute answered safe_stop:true
 * with 0 rows: the analyzer treated `b` (projected away by the WITH) as
 * `sensitive_binding_not_live`, the plan became un-scopeable, and the
 * analyzer-independent coverage gate fail-closed the whole query.
 *
 * SEMANTICS PINNED HERE: a top-level WITH is a SEGMENT BOUNDARY, not a wall.
 * Every sensitive binding anchors at the FIRST WITH/RETURN/ORDER after its own
 * binding site — i.e. it is scoped in its OWN segment, while its alias is
 * still live. A variable re-projected through the WITH (`WITH un, …`) retains
 * that established scoping downstream (its later label-free `(un)` mention is
 * a back-reference, not a new binding); a post-WITH MATCH that binds a NEW
 * sensitive node gets its own scope anchored in the post-WITH segment.
 * FAIL-CLOSED IS PRESERVED for genuinely unscopeable shapes: label-free new
 * bindings, dead back-references, alias-free labels.
 */
class WithScopeCarryThroughTest extends TestCase
{
    /** The canonical current-board query (exact live shape, completed RETURN). */
    private const BOARD_QUERY = 'MATCH (b:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) '
        . 'WHERE un.team_id IN $ai_team_ids AND un.deleted_at IS NULL '
        . 'WITH un, max(b.year) AS latest_year '
        . 'MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un) '
        . 'WHERE cur.year = latest_year '
        . 'RETURN cur.first_name AS first_name, cur.last_name AS last_name, cur.role AS role, cur.year AS year '
        . 'ORDER BY cur.role';

    private CypherScopeAnalyzer $analyzer;
    private SecurityFilterInjector $injector;

    public function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new CypherScopeAnalyzer();
        $this->injector = new SecurityFilterInjector();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** CondoEdge-shaped per-binding filters: Union direct, BoardMember via union hop. */
    private function condoedgeFilter(): callable
    {
        return static function (NodeBinding $b): ?FilterClause {
            return match ($b->label) {
                'BoardMember' => new FilterClause(
                    'match',
                    "MATCH ({$b->alias})-[:BELONGS_TO_UNION]->(u1:Union) WHERE u1.team_id IN \$ai_team_ids",
                    ['ai_team_ids' => [3]]
                ),
                default => new FilterClause(
                    'where',
                    "WHERE {$b->alias}.team_id IN \$ai_team_ids",
                    ['ai_team_ids' => [3]]
                ),
            };
        };
    }

    // =====================================================================
    // The exact live regression: the board query must be scopeable
    // =====================================================================

    /** @test */
    public function board_query_is_scopeable_with_every_binding_enumerated(): void
    {
        $plan = $this->analyzer->analyze(self::BOARD_QUERY);

        $this->assertTrue(
            $plan->isScopeable,
            "Board query must be scopeable, got rejected ({$plan->reason})"
        );

        $tuples = [];
        foreach ($plan->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $b) {
                $tuples[] = ['alias' => $b->alias, 'label' => $b->label];
            }
        }

        $this->assertEqualsCanonicalizing([
            ['alias' => 'b', 'label' => 'BoardMember'],
            ['alias' => 'un', 'label' => 'Union'],
            ['alias' => 'cur', 'label' => 'BoardMember'],
        ], $tuples, 'b, un (pre-WITH segment) and cur (post-WITH segment) must all be enumerated');
    }

    /** @test */
    public function board_query_pre_with_bindings_anchor_at_the_with_and_cur_after_it(): void
    {
        $plan = $this->analyzer->analyze(self::BOARD_QUERY);
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");

        $withOffset = strpos(self::BOARD_QUERY, 'WITH un,');
        $this->assertNotFalse($withOffset);

        foreach ($plan->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $b) {
                if (in_array($b->alias, ['b', 'un'], true)) {
                    $this->assertSame(
                        $withOffset,
                        $scope->anchorOffset,
                        "{$b->alias} must anchor at the WITH (its own segment), where it is still live"
                    );
                } else {
                    $this->assertGreaterThan(
                        $withOffset,
                        $scope->anchorOffset,
                        'cur (bound post-WITH) must anchor in the post-WITH segment'
                    );
                }
            }
        }
    }

    /** @test */
    public function board_query_injector_scopes_every_segment_and_stays_valid(): void
    {
        $plan = $this->analyzer->analyze(self::BOARD_QUERY);
        $out = $this->injector->inject(self::BOARD_QUERY, $plan, $this->condoedgeFilter());

        $this->assertNotNull($out, 'board query must be injectable (fail-closed here is the live 0-row bug)');

        $withPos = strpos($out, 'WITH un,');
        $returnPos = strpos($out, 'RETURN cur.first_name');
        $this->assertNotFalse($withPos);
        $this->assertNotFalse($returnPos);

        // b's relationship scope lands BEFORE the WITH — while b is live.
        $this->assertMatchesRegularExpression(
            '/MATCH \(b\)-\[:BELONGS_TO_UNION\]->\((ai_s\d+):Union\) WHERE \1\.team_id IN \$ai_team_ids/',
            $out
        );
        $bScopePos = strpos($out, 'MATCH (b)-[:BELONGS_TO_UNION]->(ai_s');
        $this->assertNotFalse($bScopePos);
        $this->assertLessThan($withPos, $bScopePos, "b's scope must precede the WITH: {$out}");

        // cur's relationship scope lands in the post-WITH segment, before RETURN.
        $curScopePos = strpos($out, 'MATCH (cur)-[:BELONGS_TO_UNION]->(ai_s');
        $this->assertNotFalse($curScopePos, "cur's scope missing: {$out}");
        $this->assertGreaterThan($withPos, $curScopePos);
        $this->assertLessThan($returnPos, $curScopePos);

        // Original clauses intact, parameters stay bound.
        $this->assertStringContainsString('WITH un, max(b.year) AS latest_year', $out);
        $this->assertStringContainsString('WHERE cur.year = latest_year', $out);
        $this->assertStringNotContainsString('IN [', $out);

        $this->assertTrue(
            CypherStructuralValidator::isValid($out),
            'Board rewrite is not structurally valid Cypher: ' . $out
                . ' | reason: ' . (CypherStructuralValidator::diagnose($out) ?? '')
        );
    }

    /** @test */
    public function board_query_count_wrapper_is_scopeable_too(): void
    {
        // The companion count executes `CALL { <board query sans ORDER BY> } RETURN count(*)`
        // through the same security layer — it must carry through as well.
        $inner = str_replace(' ORDER BY cur.role', '', self::BOARD_QUERY);
        $plan = $this->analyzer->analyze('CALL { ' . $inner . ' } RETURN count(*) AS total');

        $this->assertTrue($plan->isScopeable, "count wrapper rejected: {$plan->reason}");
    }

    // =====================================================================
    // End-to-end: the executor must NOT safe-stop the board query
    // =====================================================================

    /** @test */
    public function executor_runs_the_board_query_instead_of_safe_stopping(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        // Sensitive labels for the coverage gate / TeamPathResolver.
        config()->set('entities.BoardMember.security.team_path', 'union.team_id');
        config()->set('entities.BoardMember.graph.relationships', [
            ['type' => 'BELONGS_TO_UNION', 'target_label' => 'Union'],
        ]);
        config()->set('entities.Union.security.team_path', 'team_id');

        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturnUsing(function ($user, string $entity, string $alias) {
                return $entity === 'Union'
                    ? ['filter' => "WHERE {$alias}.team_id IN \$ai_team_ids", 'parameters' => ['ai_team_ids' => [3]]]
                    : ['filter' => "MATCH ({$alias})-[:BELONGS_TO_UNION]->(u1:Union) WHERE u1.team_id IN \$ai_team_ids", 'parameters' => ['ai_team_ids' => [3]]];
            });
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $this->app->instance(AiSecurityService::class, $security);

        $graph = Mockery::mock(GraphStoreInterface::class);
        $executedQuery = null;
        $graph->shouldReceive('query')
            ->once()
            ->andReturnUsing(function (string $cypher, array $params) use (&$executedQuery) {
                $executedQuery = $cypher;
                return [['first_name' => 'A', 'last_name' => 'B', 'role' => 'president', 'year' => 2026]];
            });

        $executor = new QueryExecutor($graph, ['log_slow_queries' => false]);

        $result = $executor->execute(self::BOARD_QUERY, [], ['user' => (object) ['id' => 13]]);

        $this->assertTrue($result->success);
        $this->assertNotTrue($result->metadata['safe_stop'] ?? false, 'board query must NOT safe-stop');
        $this->assertTrue($result->metadata['security_filter_applied'] ?? false, 'scoping must be PRESERVED, not bypassed');
        $this->assertCount(1, $result->data);
        $this->assertNotNull($executedQuery);
        // The executed rewrite still tenant-scopes cur (the post-WITH BoardMember).
        $this->assertStringContainsString('MATCH (cur)-[:BELONGS_TO_UNION]->', (string) $executedQuery);
        $this->assertStringContainsString('$ai_team_ids', (string) $executedQuery);
    }

    // =====================================================================
    // Negative tests — fail-closed / still-scoped for post-WITH shapes
    // =====================================================================

    /** @test */
    public function post_with_unscoped_sensitive_match_still_gets_scoped(): void
    {
        // The generator scoped segment 1 but forgot the post-WITH Person: the
        // injector must still scope p in its own segment.
        $cypher = 'MATCH (t:Team) WHERE t.id IN $ai_team_ids '
            . 'WITH t MATCH (p:Person)-[:MEMBER_OF]->(t) RETURN p.name';

        $plan = $this->analyzer->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");

        $directFilter = static fn (NodeBinding $b): FilterClause => new FilterClause(
            'where',
            "WHERE {$b->alias}.team_id IN \$ai_team_ids",
            ['ai_team_ids' => [3]]
        );

        $out = $this->injector->inject($cypher, $plan, $directFilter);

        $this->assertNotNull($out);
        $this->assertStringContainsString('p.team_id IN $ai_team_ids', $out, "post-WITH Person left unscoped: {$out}");
        $this->assertTrue(CypherStructuralValidator::isValid($out));
    }

    /** @test */
    public function post_with_label_free_new_binding_still_fails_closed(): void
    {
        // (x) after the WITH is a genuinely untyped NEW binding — unprovable tenancy.
        $plan = $this->analyzer->analyze(
            'MATCH (u:Union) WHERE u.team_id IN $ai_team_ids WITH u MATCH (x) RETURN x'
        );

        $this->assertFalse($plan->isScopeable, 'label-free post-WITH binding must stay fail-closed');
    }

    /** @test */
    public function dead_back_reference_after_projection_still_fails_closed(): void
    {
        // u is projected AWAY by the WITH; the later `(u)` is NOT a live
        // back-reference but a fresh unconstrained variable — must not be
        // silently treated as the scoped Union.
        $plan = $this->analyzer->analyze(
            'MATCH (u:Union) WITH 1 AS one MATCH (u)-[:HAS]->(t:Team) RETURN t'
        );

        $this->assertFalse($plan->isScopeable, 'dead back-reference must stay fail-closed');
    }

    /** @test */
    public function forward_label_rebinding_across_a_dropping_with_cannot_vouch_for_an_earlier_label_free_match(): void
    {
        // Adversarial laundering shape: the middle `MATCH (u)` is a label-free
        // FULL-GRAPH scan whose collected values survive to RETURN as `secrets`,
        // while the later `MATCH (u:User)` — separated by a WITH that DROPS u —
        // is a FRESH variable that must not vouch for it. Accepting this scopes
        // only the labelled bindings and leaks every tenant's u.secret.
        $plan = $this->analyzer->analyze(
            'MATCH (x:User) WITH count(x) AS c '
            . 'MATCH (u) WITH collect(u.secret) AS secrets, c '
            . 'MATCH (u:User) RETURN secrets, c'
        );

        $this->assertFalse($plan->isScopeable, 'forward-masked label-free scan must stay fail-closed');
        $this->assertSame('sensitive_binding_not_live', $plan->reason);
    }

    /** @test */
    public function forward_label_recheck_in_the_same_segment_stays_scopeable(): void
    {
        // Control: `MATCH (u) MATCH (u:Union)` with NO intervening WITH is the
        // legitimate forward label re-check — ONE variable, constrained and
        // scoped by its labelled occurrence.
        $plan = $this->analyzer->analyze('MATCH (u) MATCH (u:Union) RETURN u');

        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");
    }

    /** @test */
    public function forward_label_recheck_across_a_carrying_with_stays_scopeable(): void
    {
        // A WITH that CARRIES u keeps it the same variable — the later labelled
        // occurrence still legitimately constrains the earlier reference.
        $plan = $this->analyzer->analyze('MATCH (u) WITH u, 1 AS one MATCH (u:Union) RETURN u');

        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");
    }

    /** @test */
    public function acceptance_gate_never_satisfies_a_post_with_rebinding_with_a_pre_with_predicate(): void
    {
        // Same alias, same label, re-bound AFTER the WITH: segment 1's predicate
        // must not vouch for segment 2's re-binding (that would leak the second
        // match unscoped through the acceptance gate).
        $cypher = 'MATCH (a:Union) WHERE a.team_id IN $ai_team_ids '
            . 'WITH collect(a) AS xs MATCH (a:Union) RETURN a';

        $plan = $this->analyzer->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");

        $gate = new ScopeAcceptanceGate();
        $rule = static fn (NodeBinding $b): array => [
            'type' => 'direct',
            'kind' => 'where',
            'alias' => $b->alias,
            'column' => 'team_id',
            'filter' => "WHERE {$b->alias}.team_id IN \$ai_team_ids",
        ];

        $this->assertFalse(
            $gate->isFullySelfScoped($cypher, $plan, $rule),
            'post-WITH re-binding must NOT be accepted on the strength of the pre-WITH predicate'
        );
    }

    /** @test */
    public function single_segment_queries_keep_their_existing_shape(): void
    {
        // Control: no WITH → one scope anchored at RETURN, exactly as before.
        $plan = $this->analyzer->analyze('MATCH (i:Invoice) WHERE i.a = 1 RETURN i');

        $this->assertTrue($plan->isScopeable);
        $this->assertCount(1, $plan->scopes);
        $this->assertSame(strpos('MATCH (i:Invoice) WHERE i.a = 1 RETURN i', 'RETURN'), $plan->scopes[0]->anchorOffset);
    }
}
