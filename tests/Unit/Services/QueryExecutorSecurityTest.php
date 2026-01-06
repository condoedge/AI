<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\Security\TeamFilteredQuery;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class QueryExecutorSecurityTest extends TestCase
{
    /** @test */
    public function it_applies_team_filter_when_provided_in_options(): void
    {
        $mockGraph = Mockery::mock(GraphStoreInterface::class);
        $mockGraph->shouldReceive('query')
            ->once()
            ->withArgs(function ($query, $params) {
                // Verify team filter is applied
                return str_contains($query, 'BELONGS_TO_TEAM')
                    && isset($params['teamIds'])
                    && $params['teamIds'] === [1, 2];
            })
            ->andReturn([['name' => 'Test']]);

        $executor = new QueryExecutor($mockGraph);

        $teamFilter = new TeamFilteredQuery([1, 2]);
        $result = $executor->execute(
            'MATCH (n:Person) RETURN n',
            [],
            ['team_filter' => $teamFilter]
        );

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function it_does_not_apply_team_filter_when_not_provided(): void
    {
        $mockGraph = Mockery::mock(GraphStoreInterface::class);
        $mockGraph->shouldReceive('query')
            ->once()
            ->withArgs(function ($query, $params) {
                // No team filter in query
                return !str_contains($query, 'BELONGS_TO_TEAM');
            })
            ->andReturn([]);

        $executor = new QueryExecutor($mockGraph);

        $result = $executor->execute('MATCH (n:Person) RETURN n');

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function it_passes_team_ids_as_parameters(): void
    {
        $mockGraph = Mockery::mock(GraphStoreInterface::class);
        $mockGraph->shouldReceive('query')
            ->once()
            ->withArgs(function ($query, $params) {
                return isset($params['teamIds']) && $params['teamIds'] === [5, 10, 15];
            })
            ->andReturn([]);

        $executor = new QueryExecutor($mockGraph);

        $teamFilter = new TeamFilteredQuery([5, 10, 15]);
        $executor->execute(
            'MATCH (n:Customer) RETURN n',
            [],
            ['team_filter' => $teamFilter]
        );
    }
}
