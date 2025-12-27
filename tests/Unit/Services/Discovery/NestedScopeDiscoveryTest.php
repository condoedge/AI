<?php

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class NestedScopeDiscoveryTest extends TestCase
{
    private CypherScopeAdapter $adapter;

    public function setUp(): void
    {
        parent::setUp();
        $this->adapter = app(CypherScopeAdapter::class);
    }

    /** @test */
    public function it_discovers_scope_with_where_has_and_nested_scope(): void
    {
        // This is the pattern: whereHas('personTeams', fn($q) => $q->volunteer())
        $scopes = $this->adapter->discoverScopes(TestPersonWithNestedScopes::class);

        $this->assertArrayHasKey('has_volunteer_team_occupation', $scopes);

        $scope = $scopes['has_volunteer_team_occupation'];
        $this->assertEquals('relationship_traversal', $scope['specification_type']);
        $this->assertNotEmpty($scope['cypher_pattern']);

        // Should contain the role filter from the nested volunteer() scope
        $this->assertStringContainsString('role_type', $scope['cypher_pattern']);
    }

    /** @test */
    public function it_resolves_nested_scope_to_its_conditions(): void
    {
        $scopes = $this->adapter->discoverScopes(TestPersonTeamWithScopes::class);

        // The volunteer() scope should be discovered
        $this->assertArrayHasKey('volunteer', $scopes);
        $this->assertEquals('property_filter', $scopes['volunteer']['specification_type']);
    }

    /** @test */
    public function it_handles_multiple_levels_of_nesting(): void
    {
        $scopes = $this->adapter->discoverScopes(TestPersonWithDeepNesting::class);

        $this->assertArrayHasKey('active_volunteers', $scopes);

        $scope = $scopes['active_volunteers'];
        // Should capture both the whereHas and the nested active + volunteer conditions
        $this->assertStringContainsString('MATCH', $scope['cypher_pattern']);
    }

    /** @test */
    public function it_handles_simple_where_inside_where_has(): void
    {
        $scopes = $this->adapter->discoverScopes(TestPersonWithSimpleWhereHas::class);

        $this->assertArrayHasKey('has_active_teams', $scopes);

        $scope = $scopes['has_active_teams'];
        $this->assertEquals('relationship_traversal', $scope['specification_type']);
        $this->assertStringContainsString('status', $scope['cypher_pattern']);
    }
}

// Test fixtures - PersonTeam with scopes
class TestPersonTeamWithScopes extends Model
{
    protected $table = 'person_teams';

    public function scopeVolunteer($query)
    {
        return $query->where('role_type', 3);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}

// Person model with nested scope calling related model's scope
class TestPersonWithNestedScopes extends Model
{
    protected $table = 'persons';

    public function personTeams()
    {
        return $this->hasMany(TestPersonTeamWithScopes::class, 'person_id');
    }

    public function scopeHasVolunteerTeamOccupation($query)
    {
        return $query->whereHas('personTeams', fn($q) => $q->volunteer());
    }
}

// Person model with deep nesting (chained scopes)
class TestPersonWithDeepNesting extends Model
{
    protected $table = 'persons';

    public function personTeams()
    {
        return $this->hasMany(TestPersonTeamWithScopes::class, 'person_id');
    }

    public function scopeActiveVolunteers($query)
    {
        return $query->whereHas('personTeams', fn($q) => $q->active()->volunteer());
    }
}

// Person model with simple where inside whereHas (no nested scope)
class TestPersonWithSimpleWhereHas extends Model
{
    protected $table = 'persons';

    public function personTeams()
    {
        return $this->hasMany(TestPersonTeamWithScopes::class, 'person_id');
    }

    public function scopeHasActiveTeams($query)
    {
        return $query->whereHas('personTeams', fn($q) => $q->where('status', 'active'));
    }
}
