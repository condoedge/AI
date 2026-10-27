<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\RowScopeResolverInterface;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * A user the host narrows below the team (RowScope) runs every query on the row rule:
 * the real security service picks it, the gate verifies it, the injector splices it.
 */
class RowScopeExecutionTest extends TestCase
{
    private $graphStore;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        config()->set('entities.Meeting.security', ['team_path' => 'team_id', 'row_scope_path' => 'union_id']);
        config()->set('entities.Motion.security', ['team_path' => 'meeting.team_id', 'row_scope_path' => 'meeting.union_id']);
        config()->set('entities.Motion.graph.relationships', [['type' => 'BELONGS_TO_MEETING', 'target_label' => 'Meeting']]);
        config()->set('entities.Supplier.security', ['team_path' => 'team_id']);

        $adapter = Mockery::mock(AiAuthAdapterInterface::class);
        $adapter->shouldReceive('isEnabled')->andReturn(true);
        $adapter->shouldReceive('checkIfPermissionsExist')->andReturn(true);
        $adapter->shouldReceive('getTeamsWithPermission')->andReturn([3]);
        $adapter->shouldReceive('hasGlobalCountPermission')->andReturn(false);
        $this->app->instance(AiSecurityService::class, new AiSecurityService($adapter, new TeamPathResolver()));

        $this->app->instance('test.row_scope', new class implements RowScopeResolverInterface {
            public function rowScopeIds(mixed $user): ?array { return [8]; }
        });
        config()->set('ai.security.row_scope_resolver', 'test.row_scope');

        $this->graphStore = Mockery::mock(GraphStoreInterface::class);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function a_team_scoped_query_is_still_narrowed_to_the_row_scope(): void
    {
        $out = $this->apply('MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids RETURN m.name');

        $this->assertStringContainsString('m.union_id IN $ai_row_scope_ids', $out['query']);
        $this->assertSame([8], $out['parameters']['ai_row_scope_ids']);
        $this->assertSame([3], $out['parameters']['ai_team_ids'], 'the generated team predicate still binds');
    }

    /** @test */
    public function a_generated_row_predicate_is_accepted_as_is(): void
    {
        $q = 'MATCH (m:Meeting) WHERE m.union_id IN $ai_row_scope_ids RETURN m.name';

        $this->assertSame($q, $this->apply($q)['query']);
    }

    /** @test */
    public function a_row_predicate_on_the_team_parameter_is_not_accepted(): void
    {
        $out = $this->apply('MATCH (m:Meeting) WHERE m.union_id IN $ai_team_ids RETURN m.name');

        $this->assertStringContainsString('m.union_id IN $ai_row_scope_ids', $out['query']);
    }

    /** @test */
    public function a_parent_path_entity_gets_the_row_traversal(): void
    {
        $out = $this->apply('MATCH (mo:Motion) RETURN mo.title');

        $this->assertMatchesRegularExpression('/MATCH \(mo\)-\[:BELONGS_TO_MEETING\]->\(\w+:Meeting\) WHERE \w+\.union_id IN \$ai_row_scope_ids/', $out['query']);
    }

    /** @test */
    public function an_entity_without_a_row_path_is_refused(): void
    {
        $this->expectException(SecurityException::class);
        $this->apply('MATCH (s:Supplier) RETURN s.name');
    }

    /** @test */
    public function a_caller_cannot_pass_its_own_row_ids(): void
    {
        $executed = [];
        $this->graphStore->shouldReceive('query')->andReturnUsing(function (string $q, array $p) use (&$executed) {
            $executed[] = [$q, $p];
            return [];
        });

        (new QueryExecutor($this->graphStore, ['log_slow_queries' => false]))->execute(
            'MATCH (m:Meeting) WHERE m.union_id IN $ai_row_scope_ids RETURN m.name',
            ['ai_row_scope_ids' => [1, 2, 3]],
            ['user' => (object) ['id' => 35]]
        );

        $this->assertCount(1, $executed);
        $this->assertSame([8], $executed[0][1]['ai_row_scope_ids']);
    }

    /** @test */
    public function a_caller_row_ids_parameter_is_dropped_for_an_unnarrowed_user(): void
    {
        config()->set('ai.security.row_scope_resolver', null);
        $executed = [];
        $this->graphStore->shouldReceive('query')->andReturnUsing(function (string $q, array $p) use (&$executed) {
            $executed[] = [$q, $p];
            return [];
        });

        (new QueryExecutor($this->graphStore, ['log_slow_queries' => false]))->execute(
            'MATCH (m:Meeting) RETURN m.name',
            ['ai_row_scope_ids' => [1, 2, 3]],
            ['user' => (object) ['id' => 13]]
        );

        $this->assertCount(1, $executed);
        $this->assertArrayNotHasKey('ai_row_scope_ids', $executed[0][1]);
    }

    private function apply(string $query): array
    {
        $executor = new QueryExecutor($this->graphStore, []);
        $method = (new \ReflectionClass($executor))->getMethod('applySecurityFilter');
        $method->setAccessible(true);

        return $method->invoke($executor, $query, ['user' => (object) ['id' => 35]]);
    }
}
