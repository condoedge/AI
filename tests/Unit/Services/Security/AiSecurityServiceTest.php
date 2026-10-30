<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Contracts\RowScopeResolverInterface;
use Condoedge\Ai\DTOs\ResolvedAudience;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Tests\Fixtures\FixedAudienceResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Mockery;

class AiSecurityServiceTest extends TestCase
{
    private AiSecurityService $service;
    private $mockAdapter;
    private $mockResolver;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockAdapter = Mockery::mock(AiAuthAdapterInterface::class);
        $this->mockResolver = Mockery::mock(TeamPathResolver::class);

        $this->service = new AiSecurityService($this->mockAdapter, $this->mockResolver);
    }

    /** @test */
    public function get_team_filter_returns_filter_for_entity(): void
    {
        $user = Mockery::mock();

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('checkIfPermissionsExist')->andReturn(true);
        $this->mockAdapter->shouldReceive('getTeamsWithPermission')
            ->with($user, 'Invoice')
            ->andReturn([1, 3, 7]);

        $this->mockResolver->shouldReceive('resolve')
            ->with('Invoice', 'i', [1, 3, 7])
            ->andReturn([
                'type' => 'direct',
                'filter' => 'WHERE i.team_id IN $ai_team_ids',
                'parameters' => ['ai_team_ids' => [1, 3, 7]],
            ]);
        $this->mockResolver->shouldReceive('ruleFor')->with('Invoice', 'i')->andReturn(null);

        $result = $this->service->getTeamFilter($user, 'Invoice', 'i');

        $this->assertEquals('WHERE i.team_id IN $ai_team_ids', $result);
    }

    /** @test */
    public function get_team_filter_with_parameters_returns_filter_and_bound_ids(): void
    {
        $user = Mockery::mock();

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('checkIfPermissionsExist')->andReturn(true);
        $this->mockAdapter->shouldReceive('getTeamsWithPermission')
            ->with($user, 'Invoice')
            ->andReturn([1, 3, 7]);

        $this->mockResolver->shouldReceive('resolve')
            ->with('Invoice', 'i', [1, 3, 7])
            ->andReturn([
                'type' => 'direct',
                'filter' => 'WHERE i.team_id IN $ai_team_ids',
                'parameters' => ['ai_team_ids' => [1, 3, 7]],
            ]);
        $this->mockResolver->shouldReceive('ruleFor')->with('Invoice', 'i')->andReturn(null);

        $result = $this->service->getTeamFilterWithParameters($user, 'Invoice', 'i');

        $this->assertEquals('WHERE i.team_id IN $ai_team_ids', $result['filter']);
        $this->assertSame(['ai_team_ids' => [1, 3, 7]], $result['parameters']);
    }

    /** @test */
    public function deny_throws_when_permission_metadata_unexistent(): void
    {
        $user = Mockery::mock();

        config(['ai.security.on_permission_unexistent' => 'deny']);

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('checkIfPermissionsExist')->andReturn(false);

        $this->expectException(SecurityException::class);

        $this->service->getTeamFilter($user, 'Invoice', 'i');
    }

    /** @test */
    public function bypass_returns_null_when_permission_metadata_unexistent(): void
    {
        $user = Mockery::mock();

        config(['ai.security.on_permission_unexistent' => 'bypass']);

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('checkIfPermissionsExist')->andReturn(false);

        $this->assertNull($this->service->getTeamFilter($user, 'Invoice', 'i'));
    }

    /** @test */
    public function throws_when_no_permission(): void
    {
        $user = Mockery::mock();
        $user->id = 123;

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('checkIfPermissionsExist')->andReturn(true);
        $this->mockAdapter->shouldReceive('getTeamsWithPermission')
            ->with($user, 'Invoice')
            ->andReturn([]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Invoice');

        $this->service->getTeamFilter($user, 'Invoice', 'i');
    }

    /** @test */
    public function handles_missing_team_path_with_deny(): void
    {
        $user = Mockery::mock();
        $user->id = 123;

        config(['ai.security.on_missing_team_path' => 'deny']);

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('checkIfPermissionsExist')->andReturn(true);
        $this->mockAdapter->shouldReceive('getTeamsWithPermission')
            ->andReturn([1, 3]);

        $this->mockResolver->shouldReceive('resolve')
            ->andReturn(null);  // No team path

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $this->expectException(SecurityException::class);

        $this->service->getTeamFilter($user, 'SystemSetting', 's');
    }

    /** @test */
    public function handles_missing_team_path_with_bypass(): void
    {
        $user = Mockery::mock();

        config(['ai.security.on_missing_team_path' => 'bypass']);

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('checkIfPermissionsExist')->andReturn(true);
        $this->mockAdapter->shouldReceive('getTeamsWithPermission')
            ->andReturn([1, 3]);

        $this->mockResolver->shouldReceive('resolve')
            ->andReturn(null);  // No team path

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $result = $this->service->getTeamFilter($user, 'SystemSetting', 's');

        $this->assertNull($result);  // No filter = bypass
    }

    /** @test */
    public function should_skip_team_filter_for_global_count(): void
    {
        $user = Mockery::mock();

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('hasGlobalCountPermission')
            ->with($user, 'Invoice')
            ->andReturn(true);

        $result = $this->service->shouldSkipTeamFilterForCount($user, 'Invoice');

        $this->assertTrue($result);
    }

    /** @test */
    public function returns_null_when_security_disabled(): void
    {
        $user = Mockery::mock();

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(false);

        $result = $this->service->getTeamFilter($user, 'Invoice', 'i');

        $this->assertNull($result);
    }

    // =====================================================================
    // Row scope: a narrowed user gets the row rule; audiences list entities.
    // =====================================================================

    /** @test */
    public function a_narrowed_user_gets_the_row_rule_with_both_parameters(): void
    {
        config()->set('entities.Meeting.security', ['team_path' => 'team_id', 'row_scope_path' => 'union_id']);
        $this->bindRowScope([8]);

        $resolved = $this->realService()->getTeamFilterWithParameters($this->user(), 'Meeting', 'm');

        $this->assertSame('WHERE m.union_id IN $ai_row_scope_ids', $resolved['filter']);
        $this->assertSame(['ai_team_ids' => [3], 'ai_row_scope_ids' => [8]], $resolved['parameters']);
        $this->assertSame('$ai_row_scope_ids', $resolved['rule']['param']);
    }

    /** @test */
    public function a_narrowed_user_cannot_read_an_entity_without_a_row_path(): void
    {
        config()->set('entities.Supplier.security', ['team_path' => 'team_id']);
        $this->bindRowScope([8]);

        $this->expectException(SecurityException::class);
        $this->realService()->getTeamFilterWithParameters($this->user(), 'Supplier', 's');
    }

    /** @test */
    public function a_user_narrowed_to_nothing_reads_nothing(): void
    {
        config()->set('entities.Meeting.security', ['team_path' => 'team_id', 'row_scope_path' => 'union_id']);
        $this->bindRowScope([]);

        $this->expectException(SecurityException::class);
        $this->realService()->getTeamFilterWithParameters($this->user(), 'Meeting', 'm');
    }

    /** @test */
    public function a_narrowed_user_never_gets_the_missing_permission_bypass(): void
    {
        config()->set('ai.security.on_permission_unexistent', 'bypass');
        config()->set('entities.Meeting.security', ['team_path' => 'team_id', 'row_scope_path' => 'union_id']);
        $this->bindRowScope([8]);

        $this->expectException(SecurityException::class);
        $this->realService(permissionsExist: false)->getTeamFilterWithParameters($this->user(), 'Meeting', 'm');
    }

    /** @test */
    public function an_entity_outside_the_audiences_list_is_refused(): void
    {
        config()->set('entities.Meeting.security', ['team_path' => 'team_id', 'row_scope_path' => 'union_id']);
        config()->set('entities.Bill.security', ['team_path' => 'team_id', 'row_scope_path' => 'union_id']);
        $this->bindAudience(['Meeting']);

        $this->assertSame('WHERE m.team_id IN $ai_team_ids', $this->realService()->getTeamFilterWithParameters($this->user(), 'Meeting', 'm')['filter']);

        $this->expectException(SecurityException::class);
        $this->realService()->getTeamFilterWithParameters($this->user(), 'Bill', 'b');
    }

    /** @test */
    public function an_unnarrowed_user_keeps_the_team_rule(): void
    {
        config()->set('entities.Meeting.security', ['team_path' => 'team_id', 'row_scope_path' => 'union_id']);
        $this->bindRowScope(null);

        $resolved = $this->realService()->getTeamFilterWithParameters($this->user(), 'Meeting', 'm');

        $this->assertSame('WHERE m.team_id IN $ai_team_ids', $resolved['filter']);
        $this->assertSame(['ai_team_ids' => [3]], $resolved['parameters']);
        $this->assertSame('$ai_team_ids', $resolved['rule']['param']);
    }

    /** @test */
    public function a_narrowed_user_or_a_listed_audience_never_gets_the_global_count_shortcut(): void
    {
        config()->set('entities.Meeting.security', ['team_path' => 'team_id', 'row_scope_path' => 'union_id']);
        $this->assertTrue($this->realService()->shouldSkipTeamFilterForCount($this->user(), 'Meeting'));

        $this->bindRowScope([8]);
        $this->assertFalse($this->realService()->shouldSkipTeamFilterForCount($this->user(), 'Meeting'));

        $this->bindRowScope(null);
        $this->bindAudience(['Motion']);
        $this->assertFalse($this->realService()->shouldSkipTeamFilterForCount($this->user(), 'Meeting'));
    }

    /** @test */
    public function can_read_follows_the_audience_list_and_the_row_paths(): void
    {
        config()->set('entities.Meeting.security', ['team_path' => 'team_id', 'row_scope_path' => 'union_id']);
        config()->set('entities.Supplier.security', ['team_path' => 'team_id']);
        config()->set('entities.Country.security', ['team_path' => null]);
        config()->set('ai.security.default_team_path', null);
        $service = $this->realService();

        $this->assertTrue($service->canRead($this->user(), 'Supplier'), 'unnarrowed: the team decides');

        $this->bindRowScope([8]);
        $this->assertTrue($service->canRead($this->user(), 'Meeting'));
        $this->assertFalse($service->canRead($this->user(), 'Supplier'));
        $this->assertTrue($service->canRead($this->user(), 'Country'), 'not tenant data');

        $this->bindRowScope([]);
        $this->assertFalse($service->canRead($this->user(), 'Meeting'));

        $this->bindRowScope(null);
        $this->bindAudience(['Supplier']);
        $this->assertFalse($service->canRead($this->user(), 'Meeting'));
        $this->assertTrue($service->canRead($this->user(), 'Supplier'));
    }

    private function realService(bool $permissionsExist = true): AiSecurityService
    {
        $adapter = Mockery::mock(AiAuthAdapterInterface::class);
        $adapter->shouldReceive('isEnabled')->andReturn(true);
        $adapter->shouldReceive('checkIfPermissionsExist')->andReturn($permissionsExist);
        $adapter->shouldReceive('getTeamsWithPermission')->andReturn([3]);
        $adapter->shouldReceive('hasGlobalCountPermission')->andReturn(true);

        return new AiSecurityService($adapter, new TeamPathResolver());
    }

    private function user(): object
    {
        return (object) ['id' => 35];
    }

    private function bindRowScope(?array $ids): void
    {
        $this->app->instance('test.row_scope', new class ($ids) implements RowScopeResolverInterface {
            public function __construct(private ?array $ids) {}
            public function rowScopeIds(mixed $user): ?array { return $this->ids; }
        });
        config()->set('ai.security.row_scope_resolver', 'test.row_scope');
        $this->app->forgetScopedInstances(); // a new scope is a new request: the memo is fresh
    }

    private function bindAudience(array $dataEntities): void
    {
        FixedAudienceResolver::$next = new ResolvedAudience('resident');
        config()->set('ai.audiences.resolver', FixedAudienceResolver::class);
        config()->set('ai.audiences.map.resident', ['data_entities' => $dataEntities]);
    }

    /** @test */
    public function a_row_scope_path_of_team_reads_at_team_level_for_a_narrowed_user(): void
    {
        config()->set('entities.Supplier.security', ['team_path' => 'team_id', 'row_scope_path' => 'team']);

        $rule = (new \Condoedge\Ai\Services\Security\TeamPathResolver())->ruleFor('Supplier', 's', true);

        $this->assertSame('WHERE s.team_id IN $ai_team_ids', $rule['filter']);
    }
}
