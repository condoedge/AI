<?php

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Exceptions\CypherInjectionException;
use Condoedge\Ai\Services\Security\TeamFilteredQuery;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class TeamFilteredQueryTest extends TestCase
{
    /** @test */
    public function it_filters_qdrant_search_by_team_ids(): void
    {
        $filter = new TeamFilteredQuery([1, 2, 3]);

        $qdrantFilter = $filter->toQdrantFilter();

        $this->assertEquals([
            'must' => [
                ['key' => '_team_ids', 'match' => ['any' => [1, 2, 3]]],
            ]
        ], $qdrantFilter);
    }

    /** @test */
    public function it_filters_qdrant_with_owner_bypass(): void
    {
        $filter = new TeamFilteredQuery([1, 2], 99);

        $qdrantFilter = $filter->toQdrantFilter();

        $this->assertEquals([
            'should' => [
                ['key' => '_team_ids', 'match' => ['any' => [1, 2]]],
                ['key' => '_owner_id', 'match' => ['value' => 99]],
            ]
        ], $qdrantFilter);
    }

    /** @test */
    public function it_returns_empty_filter_when_no_restrictions(): void
    {
        $filter = new TeamFilteredQuery([]);

        $qdrantFilter = $filter->toQdrantFilter();

        $this->assertEquals([], $qdrantFilter);
    }

    /** @test */
    public function it_builds_cypher_where_clause_with_team_ids(): void
    {
        $filter = new TeamFilteredQuery([1, 2]);

        $cypherClause = $filter->toCypherWhereClause('n');

        $this->assertStringContainsString('BELONGS_TO_TEAM', $cypherClause);
        $this->assertStringContainsString('$teamIds', $cypherClause);
    }

    /** @test */
    public function it_builds_cypher_match_clause(): void
    {
        $filter = new TeamFilteredQuery([1, 2]);

        $matchClause = $filter->toCypherMatchClause('Person');

        $this->assertStringContainsString('MATCH (n:Person)', $matchClause);
        $this->assertStringContainsString('BELONGS_TO_TEAM', $matchClause);
        $this->assertStringContainsString('$teamIds', $matchClause);
    }

    /** @test */
    public function it_returns_count_from_neo4j_with_team_filter(): void
    {
        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('query')
            ->with(Mockery::on(function ($cypher) {
                return str_contains($cypher, 'BELONGS_TO_TEAM')
                    && str_contains($cypher, 'count(');
            }), Mockery::any())
            ->andReturn([['count' => 42]]);

        $filter = new TeamFilteredQuery([1, 2]);
        $count = $filter->countInNeo4j($graph, 'Person');

        $this->assertEquals(42, $count);
    }

    /** @test */
    public function it_applies_additional_filters_to_count(): void
    {
        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('query')
            ->with(Mockery::on(function ($cypher) {
                return str_contains($cypher, 'n.status = $status');
            }), Mockery::on(function ($params) {
                return isset($params['status']) && $params['status'] === 'active';
            }))
            ->andReturn([['count' => 10]]);

        $filter = new TeamFilteredQuery([1, 2]);
        $count = $filter->countInNeo4j($graph, 'Person', ['status' => 'active']);

        $this->assertEquals(10, $count);
    }

    /** @test */
    public function it_applies_threshold_protection(): void
    {
        $this->assertEquals(10, TeamFilteredQuery::applyThreshold(10, 5));
        $this->assertEquals('fewer than 5', TeamFilteredQuery::applyThreshold(3, 5));
        $this->assertEquals(0, TeamFilteredQuery::applyThreshold(0, 5));
        $this->assertEquals(5, TeamFilteredQuery::applyThreshold(5, 5));
    }

    /** @test */
    public function it_searches_qdrant_with_filter(): void
    {
        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $vectorStore->shouldReceive('search')
            ->with(
                'test_collection',
                Mockery::type('array'),
                10,
                Mockery::on(function ($filter) {
                    return isset($filter['must'][0]['key']) && $filter['must'][0]['key'] === '_team_ids';
                })
            )
            ->andReturn([
                ['id' => 1, 'score' => 0.9],
                ['id' => 2, 'score' => 0.8],
            ]);

        $filter = new TeamFilteredQuery([1, 2]);
        $results = $filter->searchQdrant($vectorStore, 'test_collection', array_fill(0, 1536, 0.1));

        $this->assertCount(2, $results);
    }

    /** @test */
    public function it_reports_whether_filters_are_active(): void
    {
        $noFilter = new TeamFilteredQuery([]);
        $this->assertFalse($noFilter->hasFilters());

        $teamFilter = new TeamFilteredQuery([1, 2]);
        $this->assertTrue($teamFilter->hasFilters());

        $ownerFilter = new TeamFilteredQuery([], 99);
        $this->assertTrue($ownerFilter->hasFilters());
    }

    /** @test */
    public function it_gets_global_count(): void
    {
        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('query')
            ->with(Mockery::on(function ($cypher) {
                return str_contains($cypher, 'MATCH (n:Person)')
                    && !str_contains($cypher, 'BELONGS_TO_TEAM');
            }), [])
            ->andReturn([['count' => 100]]);

        $count = TeamFilteredQuery::globalCount($graph, 'Person');

        $this->assertEquals(100, $count);
    }

    /** @test */
    public function it_rejects_injection_in_label_for_count(): void
    {
        $this->expectException(CypherInjectionException::class);

        $graph = Mockery::mock(GraphStoreInterface::class);
        $filter = new TeamFilteredQuery([1, 2]);

        // Attempt injection via label
        $filter->countInNeo4j($graph, 'Person} MATCH (x) DELETE x //');
    }

    /** @test */
    public function it_rejects_injection_in_label_for_match_clause(): void
    {
        $this->expectException(CypherInjectionException::class);

        $filter = new TeamFilteredQuery([1, 2]);

        // Attempt injection via label
        $filter->toCypherMatchClause('Person} MATCH (x) DELETE x //');
    }

    /** @test */
    public function it_rejects_injection_in_label_for_global_count(): void
    {
        $this->expectException(CypherInjectionException::class);

        $graph = Mockery::mock(GraphStoreInterface::class);

        // Attempt injection via label
        TeamFilteredQuery::globalCount($graph, 'Person}) DETACH DELETE n //');
    }

    /** @test */
    public function it_rejects_injection_in_filter_field(): void
    {
        $this->expectException(CypherInjectionException::class);

        $graph = Mockery::mock(GraphStoreInterface::class);
        $filter = new TeamFilteredQuery([1, 2]);

        // Attempt injection via filter field name
        $filter->countInNeo4j($graph, 'Person', ['status} RETURN n //' => 'active']);
    }

    /** @test */
    public function it_rejects_injection_in_node_alias(): void
    {
        $this->expectException(CypherInjectionException::class);

        $filter = new TeamFilteredQuery([1, 2]);

        // Attempt injection via node alias
        $filter->toCypherWhereClause('n) DELETE n //');
    }

    /** @test */
    public function it_rejects_reserved_cypher_keywords_as_labels(): void
    {
        $this->expectException(CypherInjectionException::class);

        $filter = new TeamFilteredQuery([1, 2]);

        // Reserved keyword as label
        $filter->toCypherMatchClause('MATCH');
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
