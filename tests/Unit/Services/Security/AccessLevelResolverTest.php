<?php

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\AccessLevelResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Mockery;

class AccessLevelResolverTest extends TestCase
{
    private AccessLevelResolver $resolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AccessLevelResolver();
    }

    /** @test */
    public function it_returns_global_count_for_null_user(): void
    {
        $tags = $this->resolver->resolveForEntity(null, 'Person');

        $this->assertContains('Person_global_count', $tags);
        $this->assertCount(1, $tags);
    }

    /** @test */
    public function it_returns_team_count_for_team_members(): void
    {
        $user = $this->createMockUserWithTeam(1);

        $tags = $this->resolver->resolveForEntity($user, 'Person');

        $this->assertContains('Person_global_count', $tags);
        $this->assertContains('Person_team_count', $tags);
    }

    /** @test */
    public function it_returns_team_details_for_users_with_read_permission(): void
    {
        $user = $this->createMockUserWithPermission('Person', 'read');

        $tags = $this->resolver->resolveForEntity($user, 'Person');

        $this->assertContains('Person_team_filtered_count', $tags);
        $this->assertContains('Person_team_details', $tags);
    }

    /** @test */
    public function it_returns_team_sensitive_for_users_with_sensible_columns_permission(): void
    {
        $user = $this->createMockUserWithPermission('Person.sensibleColumns', 'read');

        $tags = $this->resolver->resolveForEntity($user, 'Person');

        $this->assertContains('Person_team_sensitive', $tags);
    }

    /** @test */
    public function it_does_not_include_higher_levels_without_permission(): void
    {
        $user = $this->createMockUserWithTeam(1); // Only team access, no READ permission

        $tags = $this->resolver->resolveForEntity($user, 'Person');

        $this->assertContains('Person_team_count', $tags);
        $this->assertNotContains('Person_team_filtered_count', $tags);
        $this->assertNotContains('Person_team_details', $tags);
        $this->assertNotContains('Person_team_sensitive', $tags);
    }

    /** @test */
    public function it_returns_entity_specific_threshold(): void
    {
        config(['ai.access_control.thresholds.Person' => 10]);

        $threshold = $this->resolver->getThreshold('Person');

        $this->assertEquals(10, $threshold);
    }

    /** @test */
    public function it_returns_default_threshold_when_not_configured(): void
    {
        config(['ai.access_control.default_threshold' => 5]);
        config(['ai.access_control.thresholds' => []]);

        $threshold = $this->resolver->getThreshold('SomeEntity');

        $this->assertEquals(5, $threshold);
    }

    /** @test */
    public function it_builds_context_for_entity(): void
    {
        config(['ai.access_control.thresholds.Person' => 10]);
        config(['ai.access_control.identifying_fields.*' => ['date_of_birth', 'email']]);

        $user = $this->createMockUserWithPermission('Person', 'read');

        $context = $this->resolver->buildContextForEntity($user, 'Person');

        $this->assertArrayHasKey('entity', $context);
        $this->assertArrayHasKey('access_tags', $context);
        $this->assertArrayHasKey('threshold', $context);
        $this->assertArrayHasKey('sensible_columns', $context);
        $this->assertArrayHasKey('identifying_fields', $context);
        $this->assertArrayHasKey('user_teams', $context);

        $this->assertEquals('Person', $context['entity']);
        $this->assertEquals(10, $context['threshold']);
        $this->assertContains('date_of_birth', $context['identifying_fields']);
    }

    /** @test */
    public function it_checks_access_level(): void
    {
        $user = $this->createMockUserWithPermission('Person', 'read');

        $this->assertTrue($this->resolver->hasAccessLevel($user, 'Person', 'team_details'));
        $this->assertFalse($this->resolver->hasAccessLevel($user, 'Person', 'team_sensitive'));
    }

    /**
     * Create a mock user with team access
     *
     * @param int $teamId
     * @return Authenticatable
     */
    private function createMockUserWithTeam(int $teamId): Authenticatable
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('currentTeamId')->andReturn($teamId);
        $user->shouldReceive('hasPermission')->andReturn(false);
        $user->shouldReceive('getAccessibleTeamIds')->andReturn([$teamId]);

        return $user;
    }

    /**
     * Create a mock user with specific permission
     *
     * @param string $key
     * @param string $type
     * @return Authenticatable
     */
    private function createMockUserWithPermission(string $key, string $type): Authenticatable
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('currentTeamId')->andReturn(1);
        $user->shouldReceive('hasPermission')->with($key, $type)->andReturn(true);
        $user->shouldReceive('hasPermission')->andReturn(false);
        $user->shouldReceive('getAccessibleTeamIds')->andReturn([1]);

        return $user;
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
