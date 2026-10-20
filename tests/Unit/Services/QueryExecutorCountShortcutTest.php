<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Global-count shortcut (security review 2026-10-01, P2).
 *
 * A user with the `{Entity}.AiGlobalCount` permission may run a COUNT over a
 * single sensitive entity unfiltered. Detection used to be
 * `preg_match('/\bCOUNT\s*\(/i', $query)` ANYWHERE in the text — so a `count(`
 * in a WITH head, a string literal, or next to row data in the RETURN made a
 * plain row query run with NO tenant filter, returning every tenant's rows.
 *
 * The shortcut now applies ONLY when the canonical query's single top-level
 * RETURN projects nothing but `count(…)` aggregates (lexer tokens: strings and
 * comments can never count).
 */
class QueryExecutorCountShortcutTest extends TestCase
{
    private QueryExecutor $executor;

    public function setUp(): void
    {
        parent::setUp();
        $this->executor = new QueryExecutor(Mockery::mock(GraphStoreInterface::class), []);

        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        config()->set('entities.Invoice.security.team_path', 'team_id');

        // The user HOLDS global-count permission for Invoice.
        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(true);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturnUsing(fn ($u, $label, $alias) => [
                'filter' => "WHERE {$alias}.team_id IN \$ai_team_ids",
                'parameters' => ['ai_team_ids' => [4]],
            ]);
        $this->app->instance(AiSecurityService::class, $security);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function apply(string $query): array
    {
        $m = (new \ReflectionClass($this->executor))->getMethod('applySecurityFilter');
        $m->setAccessible(true);
        return $m->invoke($this->executor, $query, ['user' => (object) ['id' => 7]]);
    }

    private function isCount(string $query): bool
    {
        $m = (new \ReflectionClass($this->executor))->getMethod('isCountQuery');
        $m->setAccessible(true);
        return $m->invoke($this->executor, $query);
    }

    public static function rowQueriesThatMentionCount(): array
    {
        return [
            'count in WITH head, rows returned' => ['MATCH (i:Invoice) WITH i, count(*) AS c RETURN i.amount, c'],
            'count( inside a string literal' => ["MATCH (i:Invoice) WHERE i.note <> 'count(' RETURN i.amount"],
            'count next to row data in RETURN' => ['MATCH (i:Invoice) RETURN i.client_name, count(i) AS n'],
            'count next to node in RETURN' => ['MATCH (i:Invoice) RETURN count(i) AS n, i'],
            'count inside an expression' => ['MATCH (i:Invoice) RETURN count(i) + i.amount AS x'],
            'collect beside count' => ['MATCH (i:Invoice) RETURN count(i) AS n, collect(i.amount) AS amounts'],
            'count over a WITH, final RETURN rows' => ['MATCH (i:Invoice) WITH count(i) AS n MATCH (j:Invoice) RETURN j.amount, n'],
            'count( in a property name' => ['MATCH (i:Invoice) RETURN i.count AS c'],
            'union with a row branch' => ['MATCH (i:Invoice) RETURN count(i) AS x UNION MATCH (i:Invoice) RETURN i.amount AS x'],
        ];
    }

    /**
     * @test
     * @dataProvider rowQueriesThatMentionCount
     */
    public function row_queries_mentioning_count_are_still_team_filtered(string $query): void
    {
        $this->assertFalse($this->isCount($query), 'not a pure count projection');

        $result = $this->apply($query);

        $this->assertFalse($result['safe_stop'] ?? false, 'expected an executed, scoped query');
        $this->assertTrue($result['filter_applied'], "ran UNFILTERED for a global-count user: {$result['query']}");
        $this->assertStringContainsString('.team_id IN $ai_team_ids', $result['query']);
    }

    public static function pureCountQueries(): array
    {
        return [
            'count(i)' => ['MATCH (i:Invoice) RETURN count(i)'],
            'COUNT(*) AS total' => ['MATCH (i:Invoice) WHERE i.status = "open" RETURN COUNT(*) AS total'],
            'count distinct' => ['MATCH (i:Invoice) RETURN count(DISTINCT i.client_id) AS clients'],
            'two counts' => ['MATCH (i:Invoice) RETURN count(i) AS a, count(i.paid_at) AS b'],
            'executeCount wrapper' => ['CALL { MATCH (i:Invoice) RETURN i } RETURN count(*) AS total'],
            'count after WITH' => ['MATCH (i:Invoice) WITH i RETURN count(i) AS n LIMIT 1'],
        ];
    }

    /**
     * @test
     * @dataProvider pureCountQueries
     */
    public function pure_aggregate_count_keeps_the_global_count_shortcut(string $query): void
    {
        $this->assertTrue($this->isCount($query));

        $result = $this->apply($query);

        $this->assertFalse($result['filter_applied']);
        $this->assertSame($query, $result['query']);
    }
}
