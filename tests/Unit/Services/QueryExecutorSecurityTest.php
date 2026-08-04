<?php
// tests/Unit/Services/QueryExecutorSecurityTest.php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class QueryExecutorSecurityTest extends TestCase
{
    private QueryExecutor $executor;
    private $mockGraphStore;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockGraphStore = Mockery::mock(GraphStoreInterface::class);
        $this->executor = new QueryExecutor($this->mockGraphStore, []);
    }

    private function invokeInjectTeamFilter(string $query, string $filter): ?string
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('injectTeamFilter');
        $method->setAccessible(true);

        return $method->invoke($this->executor, $query, $filter);
    }

    /** @test */
    public function extracts_primary_entity_from_cypher(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('extractPrimaryEntity');
        $method->setAccessible(true);

        $this->assertEquals('Invoice', $method->invoke($this->executor, 'MATCH (i:Invoice) RETURN i'));
        $this->assertEquals('Person', $method->invoke($this->executor, 'MATCH (p:Person)-[:BELONGS_TO]->(t:Team)'));
        $this->assertEquals('InvoiceItem', $method->invoke($this->executor, 'MATCH (ii:InvoiceItem)'));
    }

    /** @test */
    public function extracts_entity_alias_from_cypher(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('extractEntityAlias');
        $method->setAccessible(true);

        $this->assertEquals('i', $method->invoke($this->executor, 'MATCH (i:Invoice) RETURN i', 'Invoice'));
        $this->assertEquals('inv', $method->invoke($this->executor, 'MATCH (inv:Invoice)', 'Invoice'));
        $this->assertEquals('person', $method->invoke($this->executor, 'MATCH (person:Person)', 'Person'));
    }

    /** @test */
    public function detects_count_queries(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('isCountQuery');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->executor, 'MATCH (i:Invoice) RETURN count(i)'));
        $this->assertTrue($method->invoke($this->executor, 'MATCH (i:Invoice) RETURN COUNT(*)'));
        $this->assertFalse($method->invoke($this->executor, 'MATCH (i:Invoice) RETURN i'));
    }

    /** @test */
    public function injects_team_filter_when_no_where_exists(): void
    {
        $query = 'MATCH (i:Invoice) RETURN i';
        $filter = 'WHERE i.team_id IN $ai_team_ids';

        $result = $this->invokeInjectTeamFilter($query, $filter);

        $this->assertStringContainsString('WHERE i.team_id IN $ai_team_ids', $result);
        $this->assertStringContainsString('RETURN i', $result);
        // Filter must precede RETURN.
        $this->assertLessThan(strpos($result, 'RETURN i'), strpos($result, 'WHERE i.team_id'));
    }

    /** @test */
    public function injects_team_filter_when_where_exists(): void
    {
        $query = 'MATCH (i:Invoice) WHERE i.status = "active" RETURN i';
        $filter = 'WHERE i.team_id IN $ai_team_ids';

        $result = $this->invokeInjectTeamFilter($query, $filter);

        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $result);
        $this->assertStringContainsString('i.status = "active"', $result);
        $this->assertStringContainsString('AND', $result);
    }

    /** @test */
    public function injects_filter_before_with_clause(): void
    {
        // The team predicate MUST land on the primary MATCH, i.e. BEFORE the WITH
        // that may project the entity variable away.
        $query = 'MATCH (i:Invoice) WITH i, i.amount as amount RETURN amount';
        $filter = 'WHERE i.team_id IN $ai_team_ids';

        $result = $this->invokeInjectTeamFilter($query, $filter);

        $this->assertStringContainsString('WHERE i.team_id IN $ai_team_ids', $result);
        $filterPos = strpos($result, 'WHERE i.team_id');
        $withPos = strpos($result, 'WITH i');
        $this->assertLessThan($withPos, $filterPos);
    }

    /** @test */
    public function injects_filter_before_order_clause(): void
    {
        $query = 'MATCH (i:Invoice) ORDER BY i.created_at RETURN i';
        $filter = 'WHERE i.team_id IN $ai_team_ids';

        $result = $this->invokeInjectTeamFilter($query, $filter);

        $this->assertStringContainsString('WHERE i.team_id IN $ai_team_ids', $result);
        $filterPos = strpos($result, 'WHERE i.team_id');
        $orderPos = strpos($result, 'ORDER BY');
        $this->assertLessThan($orderPos, $filterPos);
    }

    /** @test */
    public function injects_filter_into_every_union_branch(): void
    {
        $query = 'MATCH (i:Invoice) RETURN i UNION MATCH (i:Invoice) WHERE i.paid = true RETURN i';
        $filter = 'WHERE i.team_id IN $ai_team_ids';

        $result = $this->invokeInjectTeamFilter($query, $filter);

        // Both branches must be scoped.
        $this->assertEquals(2, substr_count($result, 'i.team_id IN $ai_team_ids'));
        // UNION keyword preserved.
        $this->assertStringContainsString('UNION', $result);
        // First branch (no pre-existing WHERE) → standalone WHERE before its RETURN.
        $this->assertStringContainsString('MATCH (i:Invoice) WHERE i.team_id IN $ai_team_ids RETURN i', $result);
        // Second branch (pre-existing WHERE) → merged with AND.
        $this->assertStringContainsString('i.paid = true AND i.team_id IN $ai_team_ids', $result);
    }

    /** @test */
    public function injects_filter_into_every_union_all_branch(): void
    {
        $query = 'MATCH (i:Invoice) RETURN i UNION ALL MATCH (i:Invoice) RETURN i';
        $filter = 'WHERE i.team_id IN $ai_team_ids';

        $result = $this->invokeInjectTeamFilter($query, $filter);

        $this->assertEquals(2, substr_count($result, 'i.team_id IN $ai_team_ids'));
        $this->assertStringContainsString('UNION ALL', $result);
    }

    /** @test */
    public function injects_match_based_filter_before_with_on_primary_match(): void
    {
        // Relationship-style filter (MATCH ... WHERE ...) must splice in before
        // the WITH that closes the primary MATCH scope.
        $query = 'MATCH (i:Invoice) WITH i RETURN i';
        $filter = 'MATCH (i)-[:BELONGS_TO]->(t:Team) WHERE t.id IN $ai_team_ids';

        $result = $this->invokeInjectTeamFilter($query, $filter);

        $this->assertNotNull($result);
        $filterPos = strpos($result, '[:BELONGS_TO]');
        $withPos = strpos($result, 'WITH i');
        $this->assertLessThan($withPos, $filterPos);
    }

    /** @test */
    public function injects_filter_into_multi_match_query(): void
    {
        $query = 'MATCH (i:Invoice) MATCH (i)-[:HAS]->(li:LineItem) RETURN i, li';
        $filter = 'WHERE i.team_id IN $ai_team_ids';

        $result = $this->invokeInjectTeamFilter($query, $filter);

        // Predicate references the primary entity (i) and precedes the RETURN.
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $result);
        $this->assertLessThan(strpos($result, 'RETURN'), strpos($result, 'i.team_id'));
    }

    /** @test */
    public function inject_returns_null_when_branch_has_no_safe_anchor(): void
    {
        // No RETURN / WITH / ORDER anchor → cannot safely splice → null (caller
        // then fails closed instead of running unfiltered).
        $result = $this->invokeInjectTeamFilter('MATCH (i:Invoice)', 'WHERE i.team_id IN $ai_team_ids');

        $this->assertNull($result);
    }

    /** @test */
    public function returns_default_alias_when_entity_not_found_in_query(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('extractEntityAlias');
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, 'MATCH (x:OtherEntity)', 'Invoice');
        $this->assertEquals('i', $result);

        $result = $method->invoke($this->executor, 'MATCH (x:OtherEntity)', 'Person');
        $this->assertEquals('p', $result);
    }

    /** @test */
    public function returns_null_when_no_entity_in_query(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('extractPrimaryEntity');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($this->executor, 'RETURN 1'));
        $this->assertNull($method->invoke($this->executor, 'CALL db.labels()'));
    }

    // =========================================================================
    // Fail-closed / user-threading behaviour (P0-5)
    // =========================================================================

    /**
     * Build a QueryExecutor whose applySecurityFilter we can drive, with the
     * AiSecurityService swapped for a mock in the container.
     */
    private function bindSecurityService(AiSecurityService $service): void
    {
        $this->app->instance(AiSecurityService::class, $service);
    }

    private function invokeApplySecurityFilter(string $query, array $options): array
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('applySecurityFilter');
        $method->setAccessible(true);

        return $method->invoke($this->executor, $query, $options);
    }

    /** @test */
    public function uses_threaded_options_user_over_null_auth_user(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');

        // auth()->user() is null in this unit context. The THREADED user must be
        // the one passed to the security service.
        $threadedUser = (object) ['id' => 99];

        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->once()
            ->with($threadedUser, 'Invoice', Mockery::any())
            ->andReturn(['filter' => 'WHERE i.team_id IN $ai_team_ids', 'parameters' => ['ai_team_ids' => [1, 2]]]);
        $this->bindSecurityService($security);

        $result = $this->invokeApplySecurityFilter(
            'MATCH (i:Invoice) RETURN i',
            ['user' => $threadedUser]
        );

        $this->assertTrue($result['filter_applied']);
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $result['query']);
        $this->assertSame(['ai_team_ids' => [1, 2]], $result['parameters']);
    }

    /** @test */
    public function deny_throws_when_sensitive_entity_and_no_resolvable_user(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');

        // No user threaded, no auth user → fail closed for a sensitive entity.
        $security = Mockery::mock(AiSecurityService::class);
        $this->bindSecurityService($security);

        $this->expectException(SecurityException::class);

        $this->invokeApplySecurityFilter('MATCH (i:Invoice) RETURN i', []);
    }

    /** @test */
    public function bypass_runs_unfiltered_when_no_resolvable_user(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'bypass');

        $security = Mockery::mock(AiSecurityService::class);
        $this->bindSecurityService($security);

        $result = $this->invokeApplySecurityFilter('MATCH (i:Invoice) RETURN i', []);

        // Fail-open preserved under 'bypass': unfiltered, no filter applied.
        $this->assertFalse($result['filter_applied']);
        $this->assertSame('MATCH (i:Invoice) RETURN i', $result['query']);
    }

    /** @test */
    public function deny_throws_when_query_shape_cannot_be_filtered(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');

        $user = (object) ['id' => 7];

        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturn(['filter' => 'WHERE i.team_id IN $ai_team_ids', 'parameters' => ['ai_team_ids' => [1]]]);
        $this->bindSecurityService($security);

        $this->expectException(SecurityException::class);

        // No RETURN/WITH/ORDER anchor → injection impossible → fail closed.
        $this->invokeApplySecurityFilter('MATCH (i:Invoice)', ['user' => $user]);
    }

    /** @test */
    public function no_entity_query_is_not_subject_to_fail_closed(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');

        $security = Mockery::mock(AiSecurityService::class);
        $this->bindSecurityService($security);

        // A scalar / introspection query has no sensitive entity, so even with
        // no user and 'deny' it must NOT throw.
        $result = $this->invokeApplySecurityFilter('RETURN 1', []);

        $this->assertFalse($result['filter_applied']);
        $this->assertSame('RETURN 1', $result['query']);
    }

    /** @test */
    public function deny_throws_on_label_free_node_access_that_yields_no_entity(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');

        $user = (object) ['id' => 7];

        // A query that binds nodes but carries NO :Label yields no extractable
        // entity; the old code ran it UNFILTERED (cross-tenant read). Tenancy
        // cannot be proven, so under 'deny' it must fail closed.
        $security = Mockery::mock(AiSecurityService::class);
        $this->bindSecurityService($security);

        $this->expectException(SecurityException::class);

        $this->invokeApplySecurityFilter('MATCH (n {id: 1}) RETURN n', ['user' => $user]);
    }

    /** @test */
    public function bypass_runs_label_free_node_access_unfiltered(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'bypass');

        $user = (object) ['id' => 7];

        $security = Mockery::mock(AiSecurityService::class);
        $this->bindSecurityService($security);

        // Under 'bypass' the legacy fail-open behaviour is preserved (loudly).
        $result = $this->invokeApplySecurityFilter('MATCH (n {id: 1}) RETURN n', ['user' => $user]);

        $this->assertFalse($result['filter_applied']);
        $this->assertSame('MATCH (n {id: 1}) RETURN n', $result['query']);
    }

    /** @test */
    public function team_ids_are_bound_as_parameters_not_spliced(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');

        $user = (object) ['id' => 7];

        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturn(['filter' => 'WHERE i.team_id IN $ai_team_ids', 'parameters' => ['ai_team_ids' => [3, 5]]]);
        $this->bindSecurityService($security);

        $result = $this->invokeApplySecurityFilter('MATCH (i:Invoice) RETURN i', ['user' => $user]);

        // No inline list literal; IDs travel as bound parameters.
        $this->assertStringNotContainsString('IN [', $result['query']);
        $this->assertStringContainsString('$ai_team_ids', $result['query']);
        $this->assertSame(['ai_team_ids' => [3, 5]], $result['parameters']);
    }

    /** @test */
    public function or_predicate_is_parenthesized_so_team_filter_constrains_both_branches(): void
    {
        // `WHERE a OR b` followed by ` AND team` parses as `a OR (b AND team)` —
        // AND binds tighter than OR — so the `a` branch runs unscoped (cross-tenant
        // leak). The existing disjunction must be grouped: `WHERE (a OR b) AND team`.
        $query = 'MATCH (i:Invoice) WHERE i.status = "active" OR i.flagged = true RETURN i';
        $filter = 'WHERE i.team_id IN $ai_team_ids';

        $result = $this->invokeInjectTeamFilter($query, $filter);

        $this->assertStringContainsString(
            '(i.status = "active" OR i.flagged = true) AND i.team_id IN $ai_team_ids',
            $result
        );
    }

    /** @test */
    public function structurally_unsafe_rewrite_degrades_to_safe_stop_not_a_throw(): void
    {
        // A scopeable, permitted query whose per-binding filter cannot be spliced
        // into a structurally valid rewrite (here: a non-lexable MATCH clause) must
        // NOT throw and must NOT run unfiltered. applySecurityFilter signals a
        // safe-stop; execute() degrades to a clean empty result, so a malformed
        // query never reaches the graph store.
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');

        $user = (object) ['id' => 7];

        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        // A 'match'-style filter with unbalanced delimiters → injector cannot build a
        // valid rewrite → returns null → safe-stop.
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturn([
                'filter' => 'MATCH (i)-[:BELONGS_TO]->(t:Team WHERE t.id IN $ai_team_ids',
                'parameters' => ['ai_team_ids' => [1]],
            ]);
        $this->bindSecurityService($security);

        $result = $this->invokeApplySecurityFilter('MATCH (i:Invoice) RETURN i', ['user' => $user]);

        $this->assertTrue($result['safe_stop'] ?? false, 'Expected a structural safe-stop');
        $this->assertFalse($result['filter_applied']);
        $this->assertSame('', $result['query']);
    }

    /** @test */
    public function safe_stop_makes_execute_return_clean_empty_result_without_querying_store(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');

        // The store must never be queried when the rewrite is structurally unsafe.
        $this->mockGraphStore->shouldNotReceive('query');

        $user = (object) ['id' => 7];

        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturn([
                'filter' => 'MATCH (i)-[:BELONGS_TO]->(t:Team WHERE t.id IN $ai_team_ids',
                'parameters' => ['ai_team_ids' => [1]],
            ]);
        $this->bindSecurityService($security);

        $result = $this->executor->execute('MATCH (i:Invoice) RETURN i', [], ['user' => $user]);

        $this->assertTrue($result->success);
        $this->assertSame([], $result->data);
        $this->assertTrue($result->metadata['safe_stop'] ?? false);
        $this->assertFalse($result->metadata['security_filter_applied']);
    }

    // =========================================================================
    // ACCEPTANCE GATE (generator-emits-scope) — PRIMARY path
    // =========================================================================

    /** @test */
    public function gate_accepts_a_generator_scoped_query_as_is_without_re_injecting(): void
    {
        // The generator already emitted the resolver-correct team predicate on the
        // binding's own alias. The gate ACCEPTS the query AS-IS (no double WHERE,
        // no re-splice), merges the bound parameters, and marks the filter applied.
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        config()->set('entities.Invoice.security.team_path', 'team_id');

        $user = (object) ['id' => 7];

        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->with($user, 'Invoice', Mockery::any())
            ->andReturn(['filter' => 'WHERE i.team_id IN $ai_team_ids', 'parameters' => ['ai_team_ids' => [4, 9]]]);
        $this->bindSecurityService($security);

        $original = 'MATCH (i:Invoice) WHERE i.team_id IN $ai_team_ids RETURN i';
        $result = $this->invokeApplySecurityFilter($original, ['user' => $user]);

        $this->assertTrue($result['filter_applied']);
        // Accepted verbatim — exactly one team predicate, no second WHERE spliced in.
        $this->assertSame($original, $result['query']);
        $this->assertSame(1, substr_count($result['query'], 'i.team_id IN $ai_team_ids'));
        $this->assertSame(['ai_team_ids' => [4, 9]], $result['parameters']);
    }

    /** @test */
    public function gate_rejects_one_unscoped_binding_and_injector_fallback_scopes_it(): void
    {
        // ONE sensitive binding lacks its team predicate → the gate REFUSES to
        // accept (leak-impossibility via the NEW path, not the splicer), and the
        // fallback injector scopes it. Proves: a query reaching the store always
        // carries the predicate, whether the generator emitted it or not.
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        config()->set('entities.Invoice.security.team_path', 'team_id');

        $user = (object) ['id' => 7];

        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturn(['filter' => 'WHERE i.team_id IN $ai_team_ids', 'parameters' => ['ai_team_ids' => [4]]]);
        $this->bindSecurityService($security);

        // No team predicate in the source → gate rejects → injector adds it.
        $result = $this->invokeApplySecurityFilter('MATCH (i:Invoice) RETURN i', ['user' => $user]);

        $this->assertTrue($result['filter_applied']);
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $result['query']);
        $this->assertSame(['ai_team_ids' => [4]], $result['parameters']);
    }

    /** @test */
    public function gate_rejects_inlined_team_ids_and_falls_back_to_bound_injection(): void
    {
        // The generator INLINED the ids (`IN [1,2]`) instead of using the bound
        // parameter — the gate must not accept it; the injector re-scopes with the
        // bound $ai_team_ids form, and no inline list reaches the store.
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        config()->set('entities.Invoice.security.team_path', 'team_id');

        $user = (object) ['id' => 7];

        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturn(['filter' => 'WHERE i.team_id IN $ai_team_ids', 'parameters' => ['ai_team_ids' => [4]]]);
        $this->bindSecurityService($security);

        $result = $this->invokeApplySecurityFilter('MATCH (i:Invoice) WHERE i.team_id IN [1, 2] RETURN i', ['user' => $user]);

        $this->assertTrue($result['filter_applied']);
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $result['query']);
    }

    /** @test */
    public function gate_accepts_a_column_consistent_scoped_union_as_is(): void
    {
        // The Parking/Storage/Vehicles-style UNION the generator now emits: every
        // branch MATCHes its own labelled node, scopes it on its own alias, and
        // projects the SAME column name. The gate accepts it AS-IS (column-consistent
        // → structurally valid → no re-splice that would break the projection).
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        config()->set('entities.Parking.security.team_path', 'team_id');
        config()->set('entities.Storage.security.team_path', 'team_id');
        config()->set('entities.Vehicle.security.team_path', 'team_id');

        $user = (object) ['id' => 7];

        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturnUsing(function ($u, $label, $alias) {
                return ['filter' => "WHERE {$alias}.team_id IN \$ai_team_ids", 'parameters' => ['ai_team_ids' => [4]]];
            });
        $this->bindSecurityService($security);

        $original = 'MATCH (p:Parking) WHERE p.team_id IN $ai_team_ids RETURN p.id AS result '
            . 'UNION MATCH (s:Storage) WHERE s.team_id IN $ai_team_ids RETURN s.id AS result '
            . 'UNION MATCH (v:Vehicle) WHERE v.team_id IN $ai_team_ids RETURN v.id AS result';

        $result = $this->invokeApplySecurityFilter($original, ['user' => $user]);

        $this->assertTrue($result['filter_applied']);
        $this->assertSame($original, $result['query']);
        // Each branch scoped exactly once on its own alias.
        $this->assertSame(1, substr_count($result['query'], 'p.team_id IN $ai_team_ids'));
        $this->assertSame(1, substr_count($result['query'], 's.team_id IN $ai_team_ids'));
        $this->assertSame(1, substr_count($result['query'], 'v.team_id IN $ai_team_ids'));
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
