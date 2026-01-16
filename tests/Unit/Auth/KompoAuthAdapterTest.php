<?php
// tests/Unit/Auth/KompoAuthAdapterTest.php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Auth;

use Condoedge\Ai\Auth\KompoAuthAdapter;
use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class KompoAuthAdapterTest extends TestCase
{
    private KompoAuthAdapter $adapter;

    public function setUp(): void
    {
        parent::setUp();
        $this->adapter = new KompoAuthAdapter();
    }

    /** @test */
    public function implements_interface(): void
    {
        $this->assertInstanceOf(AiAuthAdapterInterface::class, $this->adapter);
    }

    /** @test */
    public function is_enabled_reads_config(): void
    {
        config(['ai.security.enabled' => true]);
        $this->assertTrue($this->adapter->isEnabled());

        config(['ai.security.enabled' => false]);
        $this->assertFalse($this->adapter->isEnabled());
    }

    /** @test */
    public function get_teams_checks_ai_retrieving_first(): void
    {
        config(['ai.security.enabled' => true]);
        config(['ai.security.permission_chain' => ['{Entity}.AiRetrieving', '{Entity}']]);

        $user = Mockery::mock();
        $user->shouldReceive('getTeamsIdsWithPermission')
            ->with('Invoice.AiRetrieving', Mockery::any())
            ->once()
            ->andReturn(collect([1, 3, 7]));

        $result = $this->adapter->getTeamsWithPermission($user, 'Invoice');

        $this->assertEquals([1, 3, 7], $result);
    }

    /** @test */
    public function get_teams_falls_back_to_entity_permission(): void
    {
        config(['ai.security.enabled' => true]);
        config(['ai.security.permission_chain' => ['{Entity}.AiRetrieving', '{Entity}']]);

        $user = Mockery::mock();
        $user->shouldReceive('getTeamsIdsWithPermission')
            ->with('Invoice.AiRetrieving', Mockery::any())
            ->once()
            ->andReturn(collect([]));  // Empty - no AiRetrieving permission

        $user->shouldReceive('getTeamsIdsWithPermission')
            ->with('Invoice', Mockery::any())
            ->once()
            ->andReturn(collect([1, 3]));  // Fallback works

        $result = $this->adapter->getTeamsWithPermission($user, 'Invoice');

        $this->assertEquals([1, 3], $result);
    }

    /** @test */
    public function get_teams_returns_empty_when_disabled(): void
    {
        config(['ai.security.enabled' => false]);

        $user = Mockery::mock();
        $user->shouldNotReceive('getTeamsIdsWithPermission');

        $result = $this->adapter->getTeamsWithPermission($user, 'Invoice');

        $this->assertEmpty($result);
    }

    /** @test */
    public function has_global_count_permission_checks_correctly(): void
    {
        config(['ai.security.enabled' => true]);
        config(['ai.security.global_count_permission' => '{Entity}.AiGlobalCount']);

        $user = Mockery::mock();
        $user->shouldReceive('hasPermission')
            ->with('Invoice.AiGlobalCount', Mockery::any())
            ->once()
            ->andReturn(true);

        $this->assertTrue($this->adapter->hasGlobalCountPermission($user, 'Invoice'));
    }
}
