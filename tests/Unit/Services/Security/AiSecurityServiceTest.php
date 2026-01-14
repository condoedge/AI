<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Services\Security\TeamPathResolver;
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
        $this->mockAdapter->shouldReceive('getTeamsWithPermission')
            ->with($user, 'Invoice')
            ->andReturn([1, 3, 7]);

        $this->mockResolver->shouldReceive('resolve')
            ->with('Invoice', 'i', [1, 3, 7])
            ->andReturn(['type' => 'direct', 'filter' => 'WHERE i.team_id IN [1, 3, 7]']);

        $result = $this->service->getTeamFilter($user, 'Invoice', 'i');

        $this->assertEquals('WHERE i.team_id IN [1, 3, 7]', $result);
    }

    /** @test */
    public function throws_when_no_permission(): void
    {
        $user = Mockery::mock();
        $user->id = 123;

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
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
}
