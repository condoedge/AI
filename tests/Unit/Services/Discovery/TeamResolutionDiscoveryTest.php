<?php

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class TeamResolutionDiscoveryTest extends TestCase
{
    private EntityAutoDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discovery = app(EntityAutoDiscovery::class);
    }

    /** @test */
    public function it_discovers_direct_team_id_column(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'events';
            protected $fillable = ['name', 'team_id'];
        };

        // Use reflection to call protected method
        $reflection = new \ReflectionClass($this->discovery);
        $method = $reflection->getMethod('discoverSecurityConfig');
        $method->setAccessible(true);

        $config = $method->invoke($this->discovery, $model);

        $this->assertEquals('team_id', $config['team_resolution']);
    }

    /** @test */
    public function it_discovers_custom_team_id_column(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'projects';
            protected $fillable = ['name', 'organization_id'];
            protected $TEAM_ID_COLUMN = 'organization_id';
        };

        // Use reflection to call protected method
        $reflection = new \ReflectionClass($this->discovery);
        $method = $reflection->getMethod('discoverSecurityConfig');
        $method->setAccessible(true);

        $config = $method->invoke($this->discovery, $model);

        $this->assertEquals('organization_id', $config['team_resolution']);
    }

    /** @test */
    public function it_discovers_security_related_team_ids_method(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'persons';

            public function securityRelatedTeamIds()
            {
                return $this->personTeams()->pluck('team_id');
            }

            public function personTeams()
            {
                return $this->hasMany('PersonTeam');
            }
        };

        // Use reflection to call protected method
        $reflection = new \ReflectionClass($this->discovery);
        $method = $reflection->getMethod('discoverSecurityConfig');
        $method->setAccessible(true);

        $config = $method->invoke($this->discovery, $model);

        $this->assertEquals('method:securityRelatedTeamIds', $config['team_resolution']);
        $this->assertTrue($config['multiple_teams']);
    }

    /** @test */
    public function it_discovers_scope_security_for_teams(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'persons';

            public function scopeSecurityForTeams($query, $teamIds)
            {
                return $query->whereHas('personTeams', fn($q) => $q->whereIn('team_id', $teamIds));
            }
        };

        // Use reflection to call protected method
        $reflection = new \ReflectionClass($this->discovery);
        $method = $reflection->getMethod('discoverSecurityConfig');
        $method->setAccessible(true);

        $config = $method->invoke($this->discovery, $model);

        $this->assertEquals('scope:securityForTeams', $config['team_query_scope']);
    }
}
