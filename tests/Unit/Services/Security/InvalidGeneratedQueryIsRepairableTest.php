<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Services\ExecutionErrorClassifier;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * A generated query Neo4j itself would reject (UNION branches with different columns) is a generation error the
 * repair round can fix, not a scoping refusal: it never runs, and it is reported as the statement error Neo4j gives.
 * A VALID query the scoping cannot rewrite still safe-stops (TenantScopingLeakTest).
 */
class InvalidGeneratedQueryIsRepairableTest extends TestCase
{
    /** @test */
    public function a_union_with_different_columns_is_a_repairable_statement_error_and_never_runs(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('entities.Project.security.team_path', 'team_id');
        config()->set('entities.Maintenance.security.team_path', 'team_id');

        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldNotReceive('query');
        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $security->shouldReceive('getTeamFilterWithParameters')->andReturnUsing(
            fn ($u, $label, $alias) => ['filter' => "WHERE {$alias}.team_id IN \$ai_team_ids", 'parameters' => ['ai_team_ids' => [3]]]
        );
        $this->app->instance(AiSecurityService::class, $security);

        $query = 'MATCH (p:Project) WHERE p.team_id IN $ai_team_ids RETURN p.name AS project_name '
            . 'UNION MATCH (m:Maintenance) WHERE m.team_id IN $ai_team_ids RETURN m.name AS maintenance_name';

        try {
            (new QueryExecutor($store, []))->execute($query, [], ['user' => (object) ['id' => 7]]);
            $this->fail('An invalid generated query must not come back as an empty (safe-stopped) result');
        } catch (QueryExecutionException $e) {
            $this->assertStringContainsString('Neo.ClientError.Statement.SyntaxError', $e->getMessage());
            $this->assertTrue(ExecutionErrorClassifier::isRepairable($e), 'the repair round regenerates it');
        }
    }
}
