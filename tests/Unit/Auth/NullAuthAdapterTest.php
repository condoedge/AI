<?php
// tests/Unit/Auth/NullAuthAdapterTest.php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Auth;

use Condoedge\Ai\Auth\NullAuthAdapter;
use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The NullAuthAdapter is the fail-closed default used when kompo/auth is not
 * installed. It must never call any kompo/auth global or class, and it must
 * deny by default (no teams) while still routing the security service through
 * team filtering (checkIfPermissionsExist() => true).
 */
class NullAuthAdapterTest extends TestCase
{
    private NullAuthAdapter $adapter;

    public function setUp(): void
    {
        parent::setUp();
        $this->adapter = new NullAuthAdapter();
    }

    /** @test */
    public function implements_interface(): void
    {
        $this->assertInstanceOf(AiAuthAdapterInterface::class, $this->adapter);
    }

    /** @test */
    public function get_teams_with_permission_denies_by_default(): void
    {
        config(['ai.security.enabled' => true]);

        $user = Mockery::mock();
        // Must never delegate to a user permission method.
        $user->shouldNotReceive('getAllAccessibleTeamIds');
        $user->shouldNotReceive('getTeamsIdsWithPermission');
        $user->shouldNotReceive('hasPermission');

        $this->assertSame([], $this->adapter->getTeamsWithPermission($user, 'Invoice'));
    }

    /** @test */
    public function has_global_count_permission_denies_by_default(): void
    {
        config(['ai.security.enabled' => true]);

        $user = Mockery::mock();
        $user->shouldNotReceive('hasPermission');

        $this->assertFalse($this->adapter->hasGlobalCountPermission($user, 'Invoice'));
    }

    /** @test */
    public function is_enabled_honors_config_without_kompo_auth_globals(): void
    {
        // The package env has no globalSecurityBypass(); these calls must not fatal.
        config(['ai.security.enabled' => true]);
        $this->assertTrue($this->adapter->isEnabled());

        config(['ai.security.enabled' => false]);
        $this->assertFalse($this->adapter->isEnabled());
    }

    /**
     * Returning true keeps AiSecurityService on the team-filtering path; with no
     * teams that path throws (deny). Returning false would make the service SKIP
     * filtering, i.e. fail OPEN.
     *
     * @test
     */
    public function check_if_permissions_exist_keeps_security_on_filtering_path(): void
    {
        $user = Mockery::mock();

        $this->assertTrue($this->adapter->checkIfPermissionsExist($user, 'Invoice'));
    }

    /** @test */
    public function container_resolves_fail_closed_adapter_without_kompo_auth(): void
    {
        // This package's own environment has no kompo/auth installed.
        $this->assertFalse(function_exists('globalSecurityBypass'));
        $this->assertFalse(class_exists(\Kompo\Auth\Models\Teams\Permission::class));

        $resolved = $this->app->make(AiAuthAdapterInterface::class);

        $this->assertInstanceOf(NullAuthAdapter::class, $resolved);

        // None of these may error or grant access.
        config(['ai.security.enabled' => true]);
        $this->assertTrue($resolved->isEnabled());
        $this->assertSame([], $resolved->getTeamsWithPermission(Mockery::mock(), 'Invoice'));
    }
}
