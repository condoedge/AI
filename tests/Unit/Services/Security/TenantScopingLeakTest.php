<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * CROSS-TENANT LEAK regression suite.
 *
 * The analyzer ({@see \Condoedge\Ai\Services\Security\CypherScopeAnalyzer})
 * deliberately under-detects sensitive labels that hide inside `CALL { … }`
 * subqueries or behind label-free relationship hops. The old executor concluded
 * "no sensitive binding → nothing to scope" (or routed to the config-gated
 * fail-open bypass) and ran those queries UNFILTERED — a cross-tenant read.
 *
 * The invariant: a query that references ANY tenant-sensitive label (a label for
 * which {@see \Condoedge\Ai\Services\Security\TeamPathResolver::ruleFor()} returns
 * a filter) but cannot be PROVEN fully scoped must SAFE-STOP (clean empty result),
 * unconditionally — never run unfiltered, regardless of the `on_permission_unexistent`
 * bypass switch.
 *
 * These cases were RED against the pre-fix executor.
 */
class TenantScopingLeakTest extends TestCase
{
    private QueryExecutor $executor;
    private $mockGraphStore;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockGraphStore = Mockery::mock(GraphStoreInterface::class);
        $this->executor = new QueryExecutor($this->mockGraphStore, []);

        // Make Invoice / Person tenant-sensitive via the SAME source of truth the
        // generator and gate use (TeamPathResolver::ruleFor reads entity team_path).
        config()->set('ai.security.enabled', true);
        config()->set('entities.Invoice.security.team_path', 'team_id');
        config()->set('entities.Person.security.team_path', 'team_id');
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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

    /**
     * A permissive security service that would HAPPILY scope a binding the analyzer
     * actually enumerates — so any leak is purely a coverage/analyzer gap, not a
     * denied permission.
     */
    private function permissiveSecurity(): AiSecurityService
    {
        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturnUsing(function ($u, $label, $alias) {
                return ['filter' => "WHERE {$alias}.team_id IN \$ai_team_ids", 'parameters' => ['ai_team_ids' => [1, 2]]];
            });

        return $security;
    }

    /**
     * The leak shapes the analyzer cannot enumerate: a sensitive label inside CALL{}
     * (and nested CALL{}), behind a label-free relationship hop, or aliased/collected.
     * Each must SAFE-STOP — never run, never produce an unscoped row.
     *
     * @return array<string, array{0:string}>
     */
    public static function leakShapes(): array
    {
        return [
            'call_subquery_invoice' => ['CALL { MATCH (i:Invoice) RETURN i LIMIT 5 } RETURN i'],
            'nested_call_invoice' => ['CALL { CALL { MATCH (i:Invoice) RETURN i } RETURN i } RETURN i'],
            'label_free_hop_reuse' => ['MATCH (i:Invoice) WITH i MATCH (i)-[:HAS_LINE]->(li) RETURN i, li'],
            'aliased_collected_in_call' => ['CALL { MATCH (i:Invoice) WITH collect(i) AS xs RETURN xs } RETURN xs'],
            'call_with_team_wrapper' => ['MATCH (t:Team) CALL { MATCH (i:Invoice) RETURN i } RETURN t, i'],
        ];
    }

    /**
     * @test
     * @dataProvider leakShapes
     */
    public function sensitive_label_that_cannot_be_proven_scoped_is_safe_stopped(string $cypher): void
    {
        // Even under the fail-OPEN bypass switch, a sensitive-but-unprovable query
        // must NOT run unfiltered.
        config()->set('ai.security.on_permission_unexistent', 'bypass');

        $user = (object) ['id' => 7];
        $this->bindSecurityService($this->permissiveSecurity());

        $result = $this->invokeApplySecurityFilter($cypher, ['user' => $user]);

        $this->assertTrue($result['safe_stop'] ?? false, "Must safe-stop (not bypass): {$cypher}");
        $this->assertFalse($result['filter_applied'], "Must not mark filter applied: {$cypher}");
        $this->assertSame('', $result['query'], "Safe-stop must yield no query: {$cypher}");
    }

    /**
     * @test
     * @dataProvider leakShapes
     */
    public function leak_shapes_never_reach_the_graph_store(string $cypher): void
    {
        config()->set('ai.security.on_permission_unexistent', 'bypass');

        // The store must NEVER be queried for an unprovable sensitive shape.
        // Mockery's never() only fails at Mockery::close() (a mocked `array` return
        // silently yields []), so also record every call and assert on it in-body.
        $storeCalls = [];
        $this->mockGraphStore->shouldReceive('query')->never()
            ->andReturnUsing(function (string $query) use (&$storeCalls) {
                $storeCalls[] = $query;
                return [];
            });

        $user = (object) ['id' => 7];
        $this->bindSecurityService($this->permissiveSecurity());

        // execute() returns a QueryExecutionResult DTO (readonly public properties),
        // not an array.
        $result = $this->executor->execute($cypher, [], ['user' => $user]);

        $this->assertSame([], $storeCalls, "Leak shape reached the graph store: {$cypher}");
        $this->assertInstanceOf(QueryExecutionResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame([], $result->data, "Unprovable sensitive query must return no rows: {$cypher}");
        $this->assertSame([], $result->errors, "Safe-stop is a clean result, not an error: {$cypher}");
        $this->assertTrue($result->metadata['safe_stop'] ?? false, "execute() metadata must mark safe_stop: {$cypher}");
        $this->assertFalse($result->metadata['security_filter_applied'], "filter must not be marked applied: {$cypher}");
    }

    /**
     * A node query referencing ONLY non-sensitive labels (no team_path) is NOT
     * subject to the new unconditional safe-stop: do not over-reject.
     *
     * @test
     */
    public function non_sensitive_label_inside_call_is_not_over_rejected(): void
    {
        config()->set('ai.security.on_permission_unexistent', 'bypass');

        $user = (object) ['id' => 7];

        $security = Mockery::mock(AiSecurityService::class);
        $this->bindSecurityService($security);

        // Team has NO team_path → not tenant-sensitive → the CALL{} shape carries no
        // sensitive label, so it is not safe-stopped by the coverage gate. (It still
        // flows through the legacy unsupported-subquery / bypass handling.)
        $result = $this->invokeApplySecurityFilter('CALL { MATCH (t:Team) RETURN t } RETURN t', ['user' => $user]);

        $this->assertArrayNotHasKey('safe_stop', $result, 'Non-sensitive CALL{} must not safe-stop via coverage gate');
    }

    /**
     * The scopeable shapes the generator/injector handle must STILL produce a scoped,
     * runnable query (filter_applied=true) — the fix must not over-reject them.
     *
     * @return array<string, array{0:string}>
     */
    public static function scopeableShapes(): array
    {
        return [
            'simple' => ['MATCH (i:Invoice) RETURN i'],
            'with_anchor' => ['MATCH (i:Invoice) WITH i RETURN i'],
            'unwind_then_match' => ['UNWIND [1,2,3] AS n MATCH (i:Invoice) RETURN i'],
            'optional' => ['MATCH (u:Person) OPTIONAL MATCH (i:Invoice) RETURN u, i'],
        ];
    }

    /**
     * The executeCount wrapper `CALL { <inner> } RETURN count(*)` is the ONE CALL{}
     * shape the analyzer accepts (it scopes the inner query). The coverage gate must
     * NOT safe-stop it: the inner sensitive binding IS enumerated, so it is scoped.
     *
     * @test
     */
    public function count_wrapper_over_sensitive_entity_is_scoped_not_safe_stopped(): void
    {
        config()->set('ai.security.on_permission_unexistent', 'deny');

        $user = (object) ['id' => 7];
        $this->bindSecurityService($this->permissiveSecurity());

        $result = $this->invokeApplySecurityFilter(
            'CALL { MATCH (i:Invoice) RETURN i } RETURN count(*) AS total',
            ['user' => $user]
        );

        $this->assertArrayNotHasKey('safe_stop', $result, 'Count wrapper must not safe-stop');
        $this->assertTrue($result['filter_applied'], 'Count wrapper inner binding must be scoped');
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $result['query']);
    }

    /**
     * @test
     * @dataProvider scopeableShapes
     */
    public function scopeable_sensitive_shapes_still_scope_and_run(string $cypher): void
    {
        config()->set('ai.security.on_permission_unexistent', 'deny');

        $user = (object) ['id' => 7];
        $this->bindSecurityService($this->permissiveSecurity());

        $result = $this->invokeApplySecurityFilter($cypher, ['user' => $user]);

        $this->assertArrayNotHasKey('safe_stop', $result, "Scopeable shape must not safe-stop: {$cypher}");
        $this->assertTrue($result['filter_applied'], "Scopeable shape must be scoped: {$cypher}");
        $this->assertStringContainsString('IN $ai_team_ids', $result['query'], "Scoped query must carry the team predicate: {$cypher}");
    }
}
