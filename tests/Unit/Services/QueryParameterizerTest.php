<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\QueryParameterizer;
use Condoedge\Ai\Tests\TestCase;

/**
 * Tests for QueryParameterizer — the identity-aware (de)parameterizer that
 * keeps user-specific values out of stored example queries.
 *
 * Covers:
 * - parameterize() replaces user_id/team_id literals with placeholders in both
 *   the property ({user_id: 7}) and WHERE (n.user_id = 7) syntaxes.
 * - deparameterize() restores placeholders to the current actor's values.
 * - Explicit identity (forUser / constructor) is honored over the ambient
 *   auth() session — the core L0-7 fix.
 * - forUser(null) yields a null actor (no ambient fallback) so a no-user
 *   pipeline parameterizes nothing.
 * - Project-configured parameters from config('ai.query_parameters') are applied.
 */
class QueryParameterizerTest extends TestCase
{
    /** @test */
    public function it_parameterizes_user_and_team_in_property_syntax(): void
    {
        $parameterizer = new QueryParameterizer(userId: 7, teamId: 3, hasExplicitIdentity: true);

        $cypher = 'MATCH (u:User {user_id: 7})-[:IN]->(t:Team {team_id: 3}) RETURN u';
        $result = $parameterizer->parameterize($cypher);

        $this->assertStringContainsString('user_id: $currentUserId', $result);
        $this->assertStringContainsString('team_id: $currentTeamId', $result);
        $this->assertStringNotContainsString('user_id: 7', $result);
        $this->assertStringNotContainsString('team_id: 3', $result);
    }

    /** @test */
    public function it_parameterizes_user_in_where_syntax(): void
    {
        $parameterizer = new QueryParameterizer(userId: 42, teamId: null, hasExplicitIdentity: true);

        $cypher = 'MATCH (n:Order) WHERE n.user_id = 42 RETURN n';
        $result = $parameterizer->parameterize($cypher);

        $this->assertStringContainsString('n.user_id = $currentUserId', $result);
        $this->assertStringNotContainsString('= 42', $result);
    }

    /**
     * A host User that exposes currentTeam() as a relation METHOD (returns a relation object,
     * not a model) and the team via the magic PROPERTY accessor — the shape that previously
     * broke every data query because extractTeamId() read ->id off the relation method's result.
     *
     * @test
     */
    public function forUser_resolves_team_id_from_a_belongsto_currentTeam_relation(): void
    {
        $user = new class {
            public $id = 7;
            public function currentTeam()
            {
                return new \stdClass(); // a relation object: has no ->id
            }
            public function __get($name)
            {
                return $name === 'currentTeam' ? (object) ['id' => 42] : null;
            }
        };

        $result = QueryParameterizer::forUser($user)
            ->parameterize('MATCH (n:Meeting) WHERE n.user_id = 7 AND n.team_id = 42 RETURN n');

        $this->assertStringContainsString('n.user_id = $currentUserId', $result);
        $this->assertStringContainsString('n.team_id = $currentTeamId', $result);
        $this->assertStringNotContainsString('= 42', $result);
    }

    /** @test */
    public function it_deparameterizes_placeholders_back_to_actor_values(): void
    {
        $parameterizer = new QueryParameterizer(userId: 99, teamId: 5, hasExplicitIdentity: true);

        $cypher = 'MATCH (u:User {user_id: $currentUserId})-[:IN]->(t:Team {team_id: $currentTeamId}) RETURN u';
        $result = $parameterizer->deparameterize($cypher);

        $this->assertStringContainsString('user_id: 99', $result);
        $this->assertStringContainsString('team_id: 5', $result);
        $this->assertStringNotContainsString('$currentUserId', $result);
        $this->assertStringNotContainsString('$currentTeamId', $result);
    }

    /** @test */
    public function null_explicit_identity_is_a_no_op_for_parameterize(): void
    {
        // forUser(null) => explicit actor whose ids are null: no ambient auth()
        // fallback, so nothing is parameterized.
        $parameterizer = QueryParameterizer::forUser(null);

        $cypher = 'MATCH (n:Order) WHERE n.user_id = 42 RETURN n';
        $this->assertSame($cypher, $parameterizer->parameterize($cypher));
    }

    /** @test */
    public function for_user_extracts_id_and_team_from_user_object(): void
    {
        $user = (object) ['id' => 12, 'current_team_id' => 8];

        $parameterizer = QueryParameterizer::forUser($user);
        $params = $parameterizer->resolveParameters();

        $byField = [];
        foreach ($params as $p) {
            $byField[$p['field']] = $p['value'];
        }

        $this->assertSame(12, $byField['user_id']);
        $this->assertSame(8, $byField['team_id']);
    }

    /** @test */
    public function explicit_identity_overrides_ambient_auth(): void
    {
        // Even with no auth() session, the explicit actor drives parameterization.
        $this->assertNull(auth()->id());

        $parameterizer = QueryParameterizer::forUser((object) ['id' => 555]);

        $cypher = 'MATCH (n:Order) WHERE n.user_id = 555 RETURN n';
        $result = $parameterizer->parameterize($cypher);

        $this->assertStringContainsString('n.user_id = $currentUserId', $result);
    }

    /** @test */
    public function it_applies_project_configured_parameters(): void
    {
        config()->set('ai.query_parameters', [
            ['field' => 'org_id', 'placeholder' => '$currentOrgId', 'value' => 77],
        ]);

        $parameterizer = new QueryParameterizer(userId: null, teamId: null, hasExplicitIdentity: true);

        $cypher = 'MATCH (n:Doc) WHERE n.org_id = 77 RETURN n';
        $result = $parameterizer->parameterize($cypher);

        $this->assertStringContainsString('n.org_id = $currentOrgId', $result);
        $this->assertStringNotContainsString('= 77', $result);

        // And round-trips back.
        $this->assertStringContainsString(
            'n.org_id = 77',
            $parameterizer->deparameterize($result)
        );
    }

    /** @test */
    public function config_resolver_callback_is_invoked_for_custom_parameters(): void
    {
        config()->set('ai.query_parameters', [
            ['field' => 'branch_id', 'placeholder' => '$currentBranchId', 'resolver' => fn () => 21],
        ]);

        $parameterizer = new QueryParameterizer(userId: null, teamId: null, hasExplicitIdentity: true);

        $cypher = 'MATCH (n:Sale {branch_id: 21}) RETURN n';
        $result = $parameterizer->parameterize($cypher);

        $this->assertStringContainsString('branch_id: $currentBranchId', $result);
    }
}
