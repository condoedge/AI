<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Contracts\AudienceResolverInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\ResolvedAudience;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * An audience sees the bills of every supplier, but lists only the suppliers shared with it. Each
 * query goes through the real executor path, as a rewrite of the query.
 */
class ListedRecordsTest extends TestCase
{
    private const VISIBLE = 's.visibility IN [3, 2]';

    private string $audience = 'board';

    public function setUp(): void
    {
        parent::setUp();

        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        config()->set('entities.Supplier.security', ['team_path' => 'team_id']);
        config()->set('entities.Bill.security', ['team_path' => 'team_id']);
        config()->set('entities.Unit.security', ['team_path' => 'team_id']);
        config()->set('entities.Union.security', ['team_path' => 'team_id']);
        config()->set('ai.audiences.map', [
            'board' => ['listed_records' => ['Supplier' => ['where' => ['visibility' => [3, 2]], 'named_on' => ['Bill']]]],
            'staff' => [],
        ]);

        $adapter = Mockery::mock(AiAuthAdapterInterface::class);
        $adapter->shouldReceive('isEnabled')->andReturn(true);
        $adapter->shouldReceive('checkIfPermissionsExist')->andReturn(true);
        $adapter->shouldReceive('getTeamsWithPermission')->andReturn([3]);
        $adapter->shouldReceive('hasGlobalCountPermission')->andReturn(false);
        $this->app->instance(AiSecurityService::class, new AiSecurityService($adapter, new TeamPathResolver()));

        $test = $this;
        $this->app->instance('test.audience', new class($test) implements AudienceResolverInterface {
            public function __construct(private ListedRecordsTest $test)
            {
            }

            public function resolve(mixed $user): ?ResolvedAudience
            {
                return new ResolvedAudience($this->test->audienceKey());
            }
        });
        config()->set('ai.audiences.resolver', 'test.audience');
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function audienceKey(): string
    {
        return $this->audience;
    }

    /** @test */
    public function a_list_of_the_records_shows_only_what_the_audience_may_list(): void
    {
        $out = $this->apply('MATCH (s:Supplier) WHERE s.team_id IN $ai_team_ids RETURN s.name');

        $this->assertStringContainsString(self::VISIBLE, $out['query']);
        $this->assertTrue($out['listed_records_narrowed']);
    }

    /** @test */
    public function a_count_of_them_is_narrowed_too(): void
    {
        $this->assertStringContainsString(self::VISIBLE, $this->apply('MATCH (s:Supplier) RETURN count(s) AS c')['query']);
    }

    /** @test */
    public function a_record_named_on_a_readable_record_keeps_its_name(): void
    {
        foreach ([
            'MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) RETURN b.number, s.name',
            'MATCH (s:Supplier)<-[:SUPPLIER]-(b:Bill) RETURN s.name, sum(b.total) AS total',
            'MATCH (s:Supplier)<-[:SUPPLIER]-(b:Bill) RETURN s.name, count(b) AS bills',
            'MATCH (b:Bill) MATCH (b)-[:SUPPLIER]->(s:Supplier) RETURN b.total, s.name',
            'MATCH (b:Bill) OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) RETURN b.total, s.name',
            // Measured: the label Union read as the keyword UNION refused this real query.
            "MATCH (b:Bill)-[:BELONGS_TO_UNION]->(un:Union) WHERE b.status IN ['3', '5', '6'] AND left(b.billed_at, 4) = '2021' MATCH (b)-[:SUPPLIER]->(s:Supplier) "
                . "WHERE toLower(s.name) CONTAINS toLower('Hydro-Québec') WITH s, b, CASE WHEN b.type = 2 THEN -b.total_amount ELSE b.total_amount END AS amount "
                . 'WITH s.name AS supplier, count(b) AS bills, sum(amount) AS billed, sum(b.due_amount) AS payable ORDER BY billed DESC '
                . 'WITH supplier, round(sum(billed), 2) AS total_billed RETURN supplier, total_billed LIMIT 100',
        ] as $query) {
            $out = $this->apply($query);

            $this->assertStringNotContainsString('visibility', $out['query'], $query);
            $this->assertStringContainsString('team_id IN $ai_team_ids', $out['query'], 'Team scope stays: ' . $query);
            $this->assertArrayNotHasKey('listed_records_narrowed', $out);
        }
    }

    /** @test Each of these reads the whole list while mentioning the record that could name them. */
    public function what_joins_nothing_does_not_lift_the_rule(): void
    {
        foreach ([
            'optional from the record' => 'MATCH (s:Supplier) OPTIONAL MATCH (s)<-[:SUPPLIER]-(b:Bill) RETURN s.name',
            'exists' => 'MATCH (s:Supplier) WHERE EXISTS { MATCH (s)<-[:SUPPLIER]-(:Bill) } OR true RETURN s.name',
            'not exists' => 'MATCH (s:Supplier) WHERE NOT EXISTS { MATCH (s)<-[:SUPPLIER]-(:Bill) } RETURN s.name',
            'comment' => "MATCH (s:Supplier) /* MATCH (b:Bill)-[:SUPPLIER]->(s) */ RETURN s.name",
            'text' => "MATCH (s:Supplier) WHERE s.name <> 'MATCH (b:Bill)-[:SUPPLIER]->(s)' RETURN s.name",
            'cross join' => 'MATCH (s:Supplier), (b:Bill) RETURN s.name',
            'the list through a record it never reads' => 'MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) RETURN DISTINCT s.name',
            'carried, never read' => 'MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) WITH s, b RETURN s.name',
            'anonymous' => 'MATCH (:Bill)-[:SUPPLIER]->(s:Supplier) RETURN s.name',
            'another record' => 'MATCH (s:Supplier)-[:IN]->(u:Unit) RETURN s.name',
            'union' => 'MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) RETURN s.name AS n UNION MATCH (s:Supplier) RETURN s.name AS n',
        ] as $case => $query) {
            $out = $this->apply($query);

            // Filtered, or refused outright when the rewrite cannot be proven: never the whole list.
            $this->assertTrue(($out['safe_stop'] ?? false) === true || str_contains($out['query'], self::VISIBLE), $case);
        }
    }

    /** @test An audience without the rule, like the managers, lists everything. */
    public function an_audience_without_the_rule_is_untouched(): void
    {
        $this->audience = 'staff';

        $out = $this->apply('MATCH (s:Supplier) RETURN s.name');

        $this->assertStringNotContainsString('visibility', $out['query']);
        $this->assertArrayNotHasKey('listed_records_narrowed', $out);
    }

    /** @test */
    public function an_existing_or_cannot_escape_the_rule(): void
    {
        $query = $this->apply("MATCH (s:Supplier) WHERE s.name = 'a' OR s.name = 'b' RETURN s.name")['query'];

        $this->assertMatchesRegularExpression('/\(s\.name = \'a\' OR s\.name = \'b\'\) AND .*' . preg_quote(self::VISIBLE, '/') . '/', $query);
    }

    /** @test A rule the host wrote wrong reads nothing, and never injects what it holds. */
    public function a_malformed_rule_reads_nothing(): void
    {
        foreach ([
            ['where' => ['visibility' => ['1) OR (true']]],
            ['where' => ['bad prop' => [1]]],
            ['where' => ['visibility' => []]],
            ['where' => []],
        ] as $rule) {
            config()->set('ai.audiences.map.board.listed_records.Supplier', $rule);

            $query = $this->apply('MATCH (s:Supplier) RETURN s.name')['query'];

            $this->assertStringContainsString('AND false', $query);
            $this->assertStringNotContainsString('OR (true', $query);
        }
    }

    private function apply(string $query): array
    {
        $executor = new QueryExecutor(Mockery::mock(GraphStoreInterface::class), []);
        $method = (new \ReflectionClass($executor))->getMethod('applySecurityFilter');
        $method->setAccessible(true);

        return $method->invoke($executor, $query, ['user' => (object) ['id' => 35]]);
    }
}
