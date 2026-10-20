<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\ScopeAcceptanceGate;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\Scope\Scope;
use Condoedge\Ai\Services\Security\Scope\ScopePlan;
use Condoedge\Ai\Services\Security\SensitiveLabelCoverageGate;
use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Task 1.26c — identifier case-sensitivity + caller-parameter override.
 *
 * Cypher KEYWORDS are case-insensitive, but variables, aliases, property keys,
 * labels, relationship types and PARAMETERS are case-sensitive. The acceptance
 * gate compared the alias with strcasecmp, so in
 *   MATCH (x:Invoice), (X:Invoice) WHERE X.team_id IN $ai_team_ids RETURN DISTINCT x.id
 * the predicate on X vouched for x and the query ran AS-IS — live, all 6006
 * Invoice rows (reviewer's gate4.php). Every identifier compare in the scoping
 * layer is now exact.
 *
 * Also pinned: the security-derived `$ai_team_ids` always wins over a
 * caller-supplied parameter of the same name.
 */
class IdentifierCaseSensitivityTest extends TestCase
{
    private const CASE_ALIAS_QUERY = 'MATCH (x:Invoice), (X:Invoice) WHERE X.team_id IN $ai_team_ids RETURN DISTINCT x.id';

    public function setUp(): void
    {
        parent::setUp();
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        config()->set('entities.Invoice.security.team_path', 'team_id');
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function directRule(): callable
    {
        return static fn (NodeBinding $b): array => [
            'type' => 'direct',
            'kind' => 'where',
            'alias' => $b->alias,
            'column' => 'team_id',
            'filter' => "WHERE {$b->alias}.team_id IN \$ai_team_ids",
        ];
    }

    // =====================================================================
    // Acceptance gate — exact alias / column / parameter
    // =====================================================================

    /**
     * @test
     * @dataProvider caseMismatchedPredicates
     */
    public function gate_never_accepts_a_predicate_on_a_differently_cased_identifier(string $cypher): void
    {
        $plan = (new CypherScopeAnalyzer())->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");
        $this->assertFalse(
            (new ScopeAcceptanceGate())->isFullySelfScoped($cypher, $plan, $this->directRule()),
            "gate accepted a case-mismatched identifier as self-scoped: {$cypher}"
        );
    }

    /** @return array<string, array{0:string}> */
    public static function caseMismatchedPredicates(): array
    {
        return [
            'alias X vouches for x (reviewer)' => [self::CASE_ALIAS_QUERY],
            'alias case only' => ['MATCH (x:Invoice) WITH x MATCH (X:Invoice) WHERE X.team_id IN $ai_team_ids RETURN x.id, X.id'],
            'property key TEAM_ID' => ['MATCH (x:Invoice) WHERE x.TEAM_ID IN $ai_team_ids RETURN x.id'],
            'parameter $AI_TEAM_IDS' => ['MATCH (x:Invoice) WHERE x.team_id IN $AI_TEAM_IDS RETURN x.id'],
        ];
    }

    /** @test */
    public function gate_still_accepts_exact_identifiers_with_any_keyword_case(): void
    {
        $cypher = 'match (x:Invoice), (X:Invoice) where x.team_id in $ai_team_ids and X.team_id IN $ai_team_ids return x.id';
        $plan = (new CypherScopeAnalyzer())->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");
        $this->assertTrue((new ScopeAcceptanceGate())->isFullySelfScoped($cypher, $plan, $this->directRule()));
    }

    // =====================================================================
    // Coverage gate — exact alias keys and label memo
    // =====================================================================

    /** @test */
    public function coverage_gate_does_not_let_x_cover_X(): void
    {
        // A plan that (hypothetically) enumerated only `x` must NOT cover `X`.
        $cypher = 'MATCH (x:Invoice), (X:Invoice) RETURN x.id, X.id';
        $plan = new ScopePlan(true, [new Scope([new NodeBinding('x', 'Invoice')], strpos($cypher, 'RETURN'))]);

        $this->assertFalse(
            (new SensitiveLabelCoverageGate(new TeamPathResolver()))->planCoversSensitiveBindings($cypher, $plan),
            'X|Invoice must be a separate binding from x|Invoice'
        );
    }

    /** @test */
    public function coverage_gate_label_memo_is_case_exact(): void
    {
        // `invoice` (a DIFFERENT label, not configured) must not poison the memo
        // for the sensitive `Invoice`.
        $cypher = 'MATCH (a:invoice), (i:Invoice) RETURN a, i';
        $plan = new ScopePlan(true, [new Scope([new NodeBinding('a', 'invoice')], strpos($cypher, 'RETURN'))]);

        $this->assertFalse(
            (new SensitiveLabelCoverageGate(new TeamPathResolver()))->planCoversSensitiveBindings($cypher, $plan),
            'the sensitive i:Invoice binding must be detected even after `invoice` was looked up'
        );
    }

    // =====================================================================
    // Structural validator — UNION column names are case-sensitive
    // =====================================================================

    /** @test */
    public function union_columns_differing_only_in_case_are_rejected(): void
    {
        $this->assertNotNull(CypherStructuralValidator::diagnose(
            'MATCH (x:Invoice) RETURN x.id AS Id UNION MATCH (y:Invoice) RETURN y.id AS id'
        ));
        $this->assertNull(CypherStructuralValidator::diagnose(
            'MATCH (x:Invoice) RETURN x.id AS id UNION MATCH (y:Invoice) return y.id as id'
        ));
    }

    // =====================================================================
    // End to end through QueryExecutor
    // =====================================================================

    /** @return array{0: \Condoedge\Ai\DTOs\QueryExecutionResult, 1: list<array{0:string,1:array}>} */
    private function runExecutor(string $cypher, array $parameters = []): array
    {
        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturnUsing(fn ($user, string $entity, string $alias) => [
                'filter' => "WHERE {$alias}.team_id IN \$ai_team_ids",
                'parameters' => ['ai_team_ids' => [3]],
            ]);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $this->app->instance(AiSecurityService::class, $security);

        $executed = [];
        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('query')->andReturnUsing(function (string $q, array $p) use (&$executed) {
            $executed[] = [$q, $p];
            return [];
        });

        $result = (new QueryExecutor($graph, ['log_slow_queries' => false]))
            ->execute($cypher, $parameters, ['user' => (object) ['id' => 13]]);

        return [$result, $executed];
    }

    /** @test */
    public function executor_scopes_x_even_when_X_is_self_scoped(): void
    {
        [$result, $executed] = $this->runExecutor(self::CASE_ALIAS_QUERY);

        if (($result->metadata['safe_stop'] ?? false) === true) {
            $this->assertSame([], $executed);
            return;
        }
        $this->assertCount(1, $executed);
        $this->assertNotSame(self::CASE_ALIAS_QUERY, $executed[0][0], 'ran AS-IS (x unscoped)');
        $this->assertMatchesRegularExpression('/(?<![\w.])x\.team_id IN \$ai_team_ids/', $executed[0][0]);
    }

    /** @test */
    public function security_team_ids_win_over_a_caller_supplied_parameter(): void
    {
        [, $executed] = $this->runExecutor(
            'MATCH (x:Invoice) RETURN x.id AS id',
            ['ai_team_ids' => [1, 2, 99], 'other' => 'kept']
        );

        $this->assertCount(1, $executed);
        $this->assertSame([3], $executed[0][1]['ai_team_ids'], 'caller overrode the security-derived $ai_team_ids');
        $this->assertSame('kept', $executed[0][1]['other']);
    }

    /** @test */
    public function a_caller_supplied_team_ids_parameter_never_reaches_a_self_scoped_query(): void
    {
        // Gate-accepted path: the generator's own `$ai_team_ids` must bind the
        // SECURITY value, not the caller's.
        [, $executed] = $this->runExecutor(
            'MATCH (x:Invoice) WHERE x.team_id IN $ai_team_ids RETURN x.id AS id',
            ['ai_team_ids' => [99]]
        );

        $this->assertCount(1, $executed);
        $this->assertSame([3], $executed[0][1]['ai_team_ids']);
    }

    /** @test */
    public function skip_security_option_is_no_longer_honored(): void
    {
        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturn(['filter' => 'WHERE x.team_id IN $ai_team_ids', 'parameters' => ['ai_team_ids' => [3]]]);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $this->app->instance(AiSecurityService::class, $security);

        $executed = [];
        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('query')->andReturnUsing(function (string $q) use (&$executed) {
            $executed[] = $q;
            return [];
        });

        (new QueryExecutor($graph, ['log_slow_queries' => false]))
            ->execute('MATCH (x:Invoice) RETURN x.id AS id', [], ['user' => (object) ['id' => 13], 'skip_security' => true]);

        $this->assertCount(1, $executed);
        $this->assertStringContainsString('x.team_id IN $ai_team_ids', $executed[0], 'skip_security bypassed tenant scoping');
    }
}
