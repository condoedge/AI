<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\QueryResultFilter;
use Condoedge\Ai\Services\Security\AccessLevelResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Mockery;

class QueryResultFilterTest extends TestCase
{
    private QueryResultFilter $filter;
    private $mockResolver;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function test_filters_sensitive_columns_from_results(): void
    {
        // Arrange
        $user = $this->createUserWithoutSensitiveAccess();
        $this->setupFilterWithMockedResolver(
            sensitiveColumns: ['salary', 'ssn'],
            hasSensitiveAccess: false
        );

        $results = [
            [
                'id' => 1,
                'name' => 'John Doe',
                'salary' => 75000, // Sensitive field
                'ssn' => '123-45-6789', // Sensitive field
            ],
        ];

        // Act
        $filtered = $this->filter->filterResults($results, 'Employee', $user);

        // Assert: Sensitive fields removed
        $this->assertArrayHasKey('id', $filtered[0]);
        $this->assertArrayHasKey('name', $filtered[0]);
        $this->assertArrayNotHasKey('salary', $filtered[0]);
        $this->assertArrayNotHasKey('ssn', $filtered[0]);
    }

    public function test_preserves_sensitive_columns_for_authorized_user(): void
    {
        // Arrange
        $user = $this->createUserWithSensitiveAccess();
        $this->setupFilterWithMockedResolver(
            sensitiveColumns: ['salary', 'ssn'],
            hasSensitiveAccess: true
        );

        $results = [
            [
                'id' => 1,
                'name' => 'John Doe',
                'salary' => 75000,
                'ssn' => '123-45-6789',
            ],
        ];

        // Act
        $filtered = $this->filter->filterResults($results, 'Employee', $user);

        // Assert: All fields preserved
        $this->assertEquals($results, $filtered);
    }

    public function test_filters_nested_sensitive_columns(): void
    {
        // Arrange
        $user = $this->createUserWithoutSensitiveAccess();
        $this->setupFilterWithMockedResolver(
            sensitiveColumns: ['salary', 'ssn'],
            hasSensitiveAccess: false
        );

        $results = [
            [
                'id' => 1,
                'name' => 'John Doe',
                'employee.salary' => 75000, // Nested sensitive field
                'employee.ssn' => '123-45-6789', // Nested sensitive field
            ],
        ];

        // Act
        $filtered = $this->filter->filterResults($results, 'Employee', $user);

        // Assert: Nested sensitive fields removed
        $this->assertArrayHasKey('id', $filtered[0]);
        $this->assertArrayHasKey('name', $filtered[0]);
        $this->assertArrayNotHasKey('employee.salary', $filtered[0]);
        $this->assertArrayNotHasKey('employee.ssn', $filtered[0]);
    }

    public function test_filters_multiple_rows(): void
    {
        // Arrange
        $user = $this->createUserWithoutSensitiveAccess();
        $this->setupFilterWithMockedResolver(
            sensitiveColumns: ['salary'],
            hasSensitiveAccess: false
        );

        $results = [
            ['id' => 1, 'name' => 'John', 'salary' => 75000],
            ['id' => 2, 'name' => 'Jane', 'salary' => 80000],
            ['id' => 3, 'name' => 'Bob', 'salary' => 65000],
        ];

        // Act
        $filtered = $this->filter->filterResults($results, 'Employee', $user);

        // Assert: All rows filtered
        $this->assertCount(3, $filtered);
        foreach ($filtered as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayNotHasKey('salary', $row);
        }
    }

    public function test_handles_empty_results(): void
    {
        // Arrange
        $user = $this->createUserWithoutSensitiveAccess();
        $this->setupFilterWithMockedResolver(
            sensitiveColumns: ['salary'],
            hasSensitiveAccess: false
        );

        $results = [];

        // Act
        $filtered = $this->filter->filterResults($results, 'Employee', $user);

        // Assert: Empty array returned
        $this->assertEmpty($filtered);
    }

    public function test_handles_null_user(): void
    {
        // Arrange
        $this->setupFilterWithMockedResolver(
            sensitiveColumns: ['salary'],
            hasSensitiveAccess: false
        );

        $results = [
            ['id' => 1, 'name' => 'John', 'salary' => 75000],
        ];

        // Act
        $filtered = $this->filter->filterResults($results, 'Employee', null);

        // Assert: Sensitive fields removed for null user
        $this->assertArrayHasKey('id', $filtered[0]);
        $this->assertArrayHasKey('name', $filtered[0]);
        $this->assertArrayNotHasKey('salary', $filtered[0]);
    }

    public function test_apply_count_threshold_returns_protected_string(): void
    {
        // Arrange
        $this->setupFilterWithMockedResolver(
            sensitiveColumns: [],
            hasSensitiveAccess: false,
            threshold: 5
        );

        // Act
        $result = $this->filter->applyCountThreshold(3, 'Employee');

        // Assert: Returns protected string
        $this->assertEquals('fewer than 5', $result);
    }

    public function test_apply_count_threshold_returns_actual_count_when_above_threshold(): void
    {
        // Arrange
        $this->setupFilterWithMockedResolver(
            sensitiveColumns: [],
            hasSensitiveAccess: false,
            threshold: 5
        );

        // Act
        $result = $this->filter->applyCountThreshold(10, 'Employee');

        // Assert: Returns actual count
        $this->assertEquals(10, $result);
    }

    public function test_apply_count_threshold_returns_zero_unchanged(): void
    {
        // Arrange
        $this->setupFilterWithMockedResolver(
            sensitiveColumns: [],
            hasSensitiveAccess: false,
            threshold: 5
        );

        // Act
        $result = $this->filter->applyCountThreshold(0, 'Employee');

        // Assert: Zero is returned unchanged
        $this->assertEquals(0, $result);
    }

    /**
     * Set up filter with mocked resolver
     *
     * @param array $sensitiveColumns Columns to be considered sensitive
     * @param bool $hasSensitiveAccess Whether user has sensitive access
     * @param int $threshold Count threshold
     */
    private function setupFilterWithMockedResolver(
        array $sensitiveColumns = [],
        bool $hasSensitiveAccess = false,
        int $threshold = 5
    ): void {
        $this->mockResolver = Mockery::mock(AccessLevelResolver::class);

        $this->mockResolver
            ->shouldReceive('buildContextForEntity')
            ->andReturn([
                'entity' => 'Employee',
                'access_tags' => [],
                'threshold' => $threshold,
                'sensible_columns' => $sensitiveColumns,
                'identifying_fields' => [],
                'user_teams' => [1],
            ]);

        $this->mockResolver
            ->shouldReceive('hasAccessLevel')
            ->andReturn($hasSensitiveAccess);

        $this->mockResolver
            ->shouldReceive('getThreshold')
            ->andReturn($threshold);

        $this->filter = new QueryResultFilter($this->mockResolver);
    }

    /**
     * Create a mock user without sensitive data access
     *
     * @return Authenticatable
     */
    private function createUserWithoutSensitiveAccess(): Authenticatable
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('currentTeamId')->andReturn(1);
        $user->shouldReceive('hasPermission')->with('Employee', 'read')->andReturn(true);
        $user->shouldReceive('hasPermission')->with('Employee.sensibleColumns', 'read')->andReturn(false);
        $user->shouldReceive('hasPermission')->andReturn(false);
        $user->shouldReceive('getAccessibleTeamIds')->andReturn([1]);

        return $user;
    }

    /**
     * Create a mock user with sensitive data access
     *
     * @return Authenticatable
     */
    private function createUserWithSensitiveAccess(): Authenticatable
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('currentTeamId')->andReturn(1);
        $user->shouldReceive('hasPermission')->with('Employee', 'read')->andReturn(true);
        $user->shouldReceive('hasPermission')->with('Employee.sensibleColumns', 'read')->andReturn(true);
        $user->shouldReceive('hasPermission')->andReturn(false);
        $user->shouldReceive('getAccessibleTeamIds')->andReturn([1]);

        return $user;
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
