<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Services\Security\TeamFilteredQuery;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Log;
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

    /** @test */
    public function it_merges_team_filter_with_existing_where_clause(): void
    {
        $mockGraph = Mockery::mock(GraphStoreInterface::class);
        $mockGraph->shouldReceive('query')
            ->once()
            ->withArgs(function ($query, $params) {
                // Verify team filter is applied AND original WHERE condition is preserved
                return str_contains($query, 'BELONGS_TO_TEAM')
                    && str_contains($query, 't.id IN $teamIds')
                    && str_contains($query, 'n.age > 18')
                    && isset($params['teamIds'])
                    && $params['teamIds'] === [1, 2];
            })
            ->andReturn([['name' => 'Adult User']]);

        $executor = new QueryExecutor($mockGraph);

        $teamFilter = new TeamFilteredQuery([1, 2]);
        $result = $executor->execute(
            'MATCH (n:Person) WHERE n.age > 18 RETURN n',
            [],
            ['team_filter' => $teamFilter]
        );

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function test_team_filter_fails_closed_when_pattern_not_recognized(): void
    {
        // Arrange: Query with unusual pattern that won't match regex
        $unusualQuery = "CALL db.labels() YIELD label RETURN label";
        $teamFilter = new TeamFilteredQuery([1, 2, 3]);

        $mockGraph = Mockery::mock(GraphStoreInterface::class);
        // Query should NOT be executed - we expect an exception before this
        $mockGraph->shouldNotReceive('query');

        $executor = new QueryExecutor($mockGraph);

        // Act & Assert: Should throw exception, not execute unfiltered
        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('Team filter required but could not be applied');

        $executor->execute($unusualQuery, [], [
            'team_filter' => $teamFilter,
            'require_team_filter' => true,
        ]);
    }

    /** @test */
    public function test_team_filter_logs_and_skips_when_not_required(): void
    {
        // Arrange: Query that doesn't match pattern, but filter not required
        $unusualQuery = "CALL db.labels() YIELD label RETURN label";
        $teamFilter = new TeamFilteredQuery([1, 2, 3]);

        Log::shouldReceive('warning')
            ->once()
            ->with(
                Mockery::pattern('/Team filter could not be applied/'),
                Mockery::any()
            );

        // Allow any other log calls
        Log::shouldReceive('warning')->andReturnNull();

        $mockGraph = Mockery::mock(GraphStoreInterface::class);
        $mockGraph->shouldReceive('query')
            ->once()
            ->andReturn([['label' => 'Person']]);

        $executor = new QueryExecutor($mockGraph);

        // Act: Should log warning but execute (backwards compatibility)
        $result = $executor->execute($unusualQuery, [], [
            'team_filter' => $teamFilter,
            'require_team_filter' => false, // explicit opt-out
        ]);

        // Assert: Query executed (with warning logged)
        $this->assertTrue($result['success']);
    }

    /** @test */
    public function test_team_filter_defaults_to_required_when_not_specified(): void
    {
        // Arrange: Query with unusual pattern, require_team_filter not specified
        $unusualQuery = "CALL db.labels() YIELD label RETURN label";
        $teamFilter = new TeamFilteredQuery([1, 2, 3]);

        $mockGraph = Mockery::mock(GraphStoreInterface::class);
        // Query should NOT be executed - we expect an exception before this
        $mockGraph->shouldNotReceive('query');

        $executor = new QueryExecutor($mockGraph);

        // Act & Assert: Should throw exception because require_team_filter defaults to true
        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('Team filter required but could not be applied');

        $executor->execute($unusualQuery, [], [
            'team_filter' => $teamFilter,
            // require_team_filter not specified - should default to true
        ]);
    }
}
