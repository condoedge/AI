<?php
// tests/Unit/Services/QueryExecutorSecurityTest.php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class QueryExecutorSecurityTest extends TestCase
{
    private QueryExecutor $executor;
    private $mockGraphStore;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockGraphStore = Mockery::mock(GraphStoreInterface::class);
        $this->executor = new QueryExecutor($this->mockGraphStore, []);
    }

    /** @test */
    public function extracts_primary_entity_from_cypher(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('extractPrimaryEntity');
        $method->setAccessible(true);

        $this->assertEquals('Invoice', $method->invoke($this->executor, 'MATCH (i:Invoice) RETURN i'));
        $this->assertEquals('Person', $method->invoke($this->executor, 'MATCH (p:Person)-[:BELONGS_TO]->(t:Team)'));
        $this->assertEquals('InvoiceItem', $method->invoke($this->executor, 'MATCH (ii:InvoiceItem)'));
    }

    /** @test */
    public function extracts_entity_alias_from_cypher(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('extractEntityAlias');
        $method->setAccessible(true);

        $this->assertEquals('i', $method->invoke($this->executor, 'MATCH (i:Invoice) RETURN i', 'Invoice'));
        $this->assertEquals('inv', $method->invoke($this->executor, 'MATCH (inv:Invoice)', 'Invoice'));
        $this->assertEquals('person', $method->invoke($this->executor, 'MATCH (person:Person)', 'Person'));
    }

    /** @test */
    public function detects_count_queries(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('isCountQuery');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->executor, 'MATCH (i:Invoice) RETURN count(i)'));
        $this->assertTrue($method->invoke($this->executor, 'MATCH (i:Invoice) RETURN COUNT(*)'));
        $this->assertFalse($method->invoke($this->executor, 'MATCH (i:Invoice) RETURN i'));
    }

    /** @test */
    public function injects_team_filter_when_no_where_exists(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('injectTeamFilter');
        $method->setAccessible(true);

        $query = 'MATCH (i:Invoice) RETURN i';
        $filter = 'WHERE i.team_id IN [1, 3, 7]';

        $result = $method->invoke($this->executor, $query, $filter);

        $this->assertStringContainsString('WHERE i.team_id IN [1, 3, 7]', $result);
        $this->assertStringContainsString('RETURN i', $result);
    }

    /** @test */
    public function injects_team_filter_when_where_exists(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('injectTeamFilter');
        $method->setAccessible(true);

        $query = 'MATCH (i:Invoice) WHERE i.status = "active" RETURN i';
        $filter = 'WHERE i.team_id IN [1, 3, 7]';

        $result = $method->invoke($this->executor, $query, $filter);

        $this->assertStringContainsString('i.team_id IN [1, 3, 7]', $result);
        $this->assertStringContainsString('i.status = "active"', $result);
        $this->assertStringContainsString('AND', $result);
    }

    /** @test */
    public function injects_filter_before_with_clause(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('injectTeamFilter');
        $method->setAccessible(true);

        $query = 'MATCH (i:Invoice) WITH i, i.amount as amount RETURN amount';
        $filter = 'WHERE i.team_id IN [1, 2]';

        $result = $method->invoke($this->executor, $query, $filter);

        $this->assertStringContainsString('WHERE i.team_id IN [1, 2]', $result);
        // Filter should appear before WITH
        $filterPos = strpos($result, 'WHERE i.team_id');
        $withPos = strpos($result, 'WITH i');
        $this->assertLessThan($withPos, $filterPos);
    }

    /** @test */
    public function injects_filter_before_order_clause(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('injectTeamFilter');
        $method->setAccessible(true);

        $query = 'MATCH (i:Invoice) ORDER BY i.created_at RETURN i';
        $filter = 'WHERE i.team_id IN [1, 2]';

        $result = $method->invoke($this->executor, $query, $filter);

        $this->assertStringContainsString('WHERE i.team_id IN [1, 2]', $result);
        // Filter should appear before ORDER
        $filterPos = strpos($result, 'WHERE i.team_id');
        $orderPos = strpos($result, 'ORDER BY');
        $this->assertLessThan($orderPos, $filterPos);
    }

    /** @test */
    public function returns_default_alias_when_entity_not_found_in_query(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('extractEntityAlias');
        $method->setAccessible(true);

        // When entity is not found, return lowercase first letter
        $result = $method->invoke($this->executor, 'MATCH (x:OtherEntity)', 'Invoice');
        $this->assertEquals('i', $result);

        $result = $method->invoke($this->executor, 'MATCH (x:OtherEntity)', 'Person');
        $this->assertEquals('p', $result);
    }

    /** @test */
    public function returns_null_when_no_entity_in_query(): void
    {
        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('extractPrimaryEntity');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($this->executor, 'RETURN 1'));
        $this->assertNull($method->invoke($this->executor, 'CALL db.labels()'));
    }
}
