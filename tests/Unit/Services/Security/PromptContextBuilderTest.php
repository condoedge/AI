<?php

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\PromptContextBuilder;
use Condoedge\Ai\Services\Security\AccessLevelResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Mockery;

class PromptContextBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock the AccessLevelResolver in the container
        $this->app->bind(AccessLevelResolver::class, function () {
            return new AccessLevelResolver();
        });
    }

    /** @test */
    public function it_builds_prompt_with_access_tags(): void
    {
        $user = $this->createMockUserWithPermission('Person', 'read');

        $builder = new PromptContextBuilder($user);
        $prompt = $builder->buildAccessSection(['Person']);

        $this->assertStringContainsString('Person_global_count', $prompt);
        $this->assertStringContainsString('Person_team_count', $prompt);
        $this->assertStringContainsString('Person_team_details', $prompt);
    }

    /** @test */
    public function it_includes_threshold_instructions(): void
    {
        config(['ai.access_control.thresholds.Person' => 10]);
        config(['ai.access_control.default_threshold' => 5]);

        $user = $this->createMockUserWithPermission('Person', 'read');

        $builder = new PromptContextBuilder($user);
        $prompt = $builder->buildAccessSection(['Person']);

        $this->assertStringContainsString('THRESHOLD', $prompt);
        $this->assertStringContainsString('10', $prompt);
        $this->assertStringContainsString('fewer than 10', $prompt);
    }

    /** @test */
    public function it_lists_restricted_sensible_columns(): void
    {
        $user = $this->createMockUserWithTeam(1); // No sensibleColumns permission

        $builder = new PromptContextBuilder($user);
        $builder->setEntitySensibleColumns('Person', ['ssn', 'salary', 'date_of_birth']);
        $prompt = $builder->buildAccessSection(['Person']);

        $this->assertStringContainsString('RESTRICTED', $prompt);
        $this->assertStringContainsString('ssn', $prompt);
        $this->assertStringContainsString('salary', $prompt);
    }

    /** @test */
    public function it_does_not_show_restricted_when_user_has_sensitive_access(): void
    {
        $user = $this->createMockUserWithSensitiveAccess('Person');

        $builder = new PromptContextBuilder($user);
        $builder->setEntitySensibleColumns('Person', ['ssn', 'salary']);
        $prompt = $builder->buildAccessSection(['Person']);

        $this->assertStringContainsString('Person_team_sensitive', $prompt);
        $this->assertStringNotContainsString('RESTRICTED', $prompt);
    }

    /** @test */
    public function it_includes_identifying_fields_warning(): void
    {
        config(['ai.access_control.identifying_fields.*' => ['date_of_birth', 'email']]);

        $user = $this->createMockUserWithPermission('Person', 'read');

        $builder = new PromptContextBuilder($user);
        $prompt = $builder->buildAccessSection(['Person']);

        $this->assertStringContainsString('date_of_birth', $prompt);
        $this->assertStringContainsString('IDENTIFYING', $prompt);
    }

    /** @test */
    public function it_builds_full_context_with_aggregates(): void
    {
        $user = $this->createMockUserWithPermission('Person', 'read');

        $builder = new PromptContextBuilder($user);
        $prompt = $builder->buildFullContext(
            ['Person'],
            [],
            ['Total Persons' => 150, 'Active Persons' => 120]
        );

        $this->assertStringContainsString('Available Aggregates', $prompt);
        $this->assertStringContainsString('Total Persons: 150', $prompt);
        $this->assertStringContainsString('Active Persons: 120', $prompt);
    }

    /** @test */
    public function it_builds_full_context_with_semantic_results(): void
    {
        $user = $this->createMockUserWithPermission('Person', 'read');

        $semanticResults = [
            [
                'entity_type' => 'Person',
                'entity_id' => 1,
                'content' => 'John Doe is a software developer',
            ],
            [
                'entity_type' => 'Person',
                'entity_id' => 2,
                'content' => 'Jane Smith works in marketing',
            ],
        ];

        $builder = new PromptContextBuilder($user);
        $prompt = $builder->buildFullContext(['Person'], $semanticResults);

        $this->assertStringContainsString('Semantic Context', $prompt);
        $this->assertStringContainsString('Person (ID: 1)', $prompt);
        $this->assertStringContainsString('John Doe', $prompt);
    }

    /** @test */
    public function it_builds_system_prompt(): void
    {
        $user = $this->createMockUserWithPermission('Person', 'read');

        $builder = new PromptContextBuilder($user);
        $prompt = $builder->buildSystemPrompt(['Person']);

        $this->assertStringContainsString('AI assistant', $prompt);
        $this->assertStringContainsString('access control', $prompt);
        $this->assertStringContainsString('IMPORTANT RULES', $prompt);
        $this->assertStringContainsString('Person_team_details', $prompt);
    }

    /** @test */
    public function it_handles_multiple_entities(): void
    {
        $user = $this->createMockUserWithPermission('Person', 'read');

        $builder = new PromptContextBuilder($user);
        $prompt = $builder->buildAccessSection(['Person', 'Event', 'Team']);

        $this->assertStringContainsString('Data Access for Person', $prompt);
        $this->assertStringContainsString('Data Access for Event', $prompt);
        $this->assertStringContainsString('Data Access for Team', $prompt);
    }

    /**
     * Create a mock user with team access
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
     * Create a mock user with read permission
     */
    private function createMockUserWithPermission(string $entity, string $type): Authenticatable
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('currentTeamId')->andReturn(1);
        $user->shouldReceive('hasPermission')
            ->with($entity, $type)
            ->andReturn(true);
        $user->shouldReceive('hasPermission')->andReturn(false);
        $user->shouldReceive('getAccessibleTeamIds')->andReturn([1]);

        return $user;
    }

    /**
     * Create a mock user with sensitive access
     */
    private function createMockUserWithSensitiveAccess(string $entity): Authenticatable
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('currentTeamId')->andReturn(1);
        $user->shouldReceive('hasPermission')
            ->with($entity, 'read')
            ->andReturn(true);
        $user->shouldReceive('hasPermission')
            ->with("{$entity}.sensibleColumns", 'read')
            ->andReturn(true);
        $user->shouldReceive('hasPermission')->andReturn(false);
        $user->shouldReceive('getAccessibleTeamIds')->andReturn([1]);

        return $user;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
