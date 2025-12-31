<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\GraphStore;

use Condoedge\Ai\GraphStore\Neo4jStore;
use Condoedge\Ai\Tests\TestCase;
use Mockery;
use ReflectionClass;
use ReflectionMethod;

/**
 * Neo4jStoreSecurityTest
 *
 * Tests that Neo4jStore uses parameter binding instead of string interpolation
 * to prevent Cypher injection attacks.
 */
class Neo4jStoreSecurityTest extends TestCase
{
    protected Neo4jStore $store;

    /** @var array<string, mixed> Captured query parameters */
    protected array $capturedQuery = [];
    protected array $capturedParams = [];

    public function setUp(): void
    {
        parent::setUp();

        // Create a partial mock that captures query calls
        $this->store = $this->createQueryCapturingStore();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Creates a Neo4jStore that captures query calls without executing them
     */
    protected function createQueryCapturingStore(): Neo4jStore
    {
        $store = Mockery::mock(Neo4jStore::class)->makePartial();

        // Mock the query method to capture calls
        $store->shouldReceive('query')
            ->andReturnUsing(function (string $cypher, array $params = []) {
                $this->capturedQuery[] = $cypher;
                $this->capturedParams[] = $params;

                // Return mock response based on query type
                if (str_contains($cypher, 'CREATE')) {
                    return [['id' => 123, 'nodeId' => 123, 'appId' => 1]];
                }
                if (str_contains($cypher, 'MERGE') || str_contains($cypher, 'MATCH')) {
                    return [['r' => ['id' => 456]]];
                }
                return [];
            });

        return $store;
    }

    // =========================================================================
    // createNode() Parameter Binding Tests
    // =========================================================================

    /** @test */
    public function createNode_uses_parameter_binding_for_properties(): void
    {
        $properties = [
            'id' => 1,
            'name' => 'Test Node',
            'email' => 'test@example.com',
        ];

        $this->store->createNode('TestLabel', $properties);

        // Get the captured query
        $query = $this->capturedQuery[0] ?? '';
        $params = $this->capturedParams[0] ?? [];

        // Query should use $properties parameter, not interpolated values
        $this->assertStringContainsString('$properties', $query);

        // Parameters should include the properties
        $this->assertArrayHasKey('properties', $params);
        $this->assertEquals($properties, $params['properties']);

        // Query should NOT contain raw property values
        $this->assertStringNotContainsString("'Test Node'", $query);
        $this->assertStringNotContainsString("'test@example.com'", $query);
    }

    /** @test */
    public function createNode_uses_SET_clause_with_parameter(): void
    {
        $properties = ['id' => 1, 'name' => 'Test'];

        $this->store->createNode('TestLabel', $properties);

        $query = $this->capturedQuery[0] ?? '';

        // Should use SET n = $properties pattern
        $this->assertMatchesRegularExpression('/SET\s+\w+\s*=\s*\$properties/i', $query);
    }

    /** @test */
    public function createNode_escapes_label_but_parameterizes_properties(): void
    {
        $properties = ['id' => 1, 'name' => "O'Malley's \"Test\" Data"];

        $this->store->createNode('TestLabel', $properties);

        $query = $this->capturedQuery[0] ?? '';
        $params = $this->capturedParams[0] ?? [];

        // Label should be escaped with backticks
        $this->assertStringContainsString('`TestLabel`', $query);

        // Properties with special characters should be in parameters, not query
        $this->assertStringNotContainsString("O'Malley", $query);
        $this->assertStringNotContainsString('"Test"', $query);

        // Properties should be passed as parameters
        $this->assertEquals("O'Malley's \"Test\" Data", $params['properties']['name']);
    }

    /** @test */
    public function createNode_handles_empty_properties(): void
    {
        $this->store->createNode('EmptyNode', []);

        $query = $this->capturedQuery[0] ?? '';
        $params = $this->capturedParams[0] ?? [];

        // Should still use parameter binding even for empty properties
        $this->assertStringContainsString('$properties', $query);
        $this->assertArrayHasKey('properties', $params);
        $this->assertEquals([], $params['properties']);
    }

    /** @test */
    public function createNode_handles_properties_with_injection_attempts(): void
    {
        // Property value that could be an injection attempt
        $properties = [
            'id' => 1,
            'name' => '}) DETACH DELETE (n) //',
            'email' => "test@example.com' OR '1'='1",
        ];

        $this->store->createNode('TestLabel', $properties);

        $query = $this->capturedQuery[0] ?? '';
        $params = $this->capturedParams[0] ?? [];

        // Injection attempts should be in parameters, not query string
        $this->assertStringNotContainsString('DETACH DELETE', $query);
        $this->assertStringNotContainsString("OR '1'='1", $query);

        // Values should be safely passed as parameters
        $this->assertEquals('}) DETACH DELETE (n) //', $params['properties']['name']);
        $this->assertEquals("test@example.com' OR '1'='1", $params['properties']['email']);
    }

    // =========================================================================
    // createRelationship() Parameter Binding Tests
    // =========================================================================

    /** @test */
    public function createRelationship_uses_parameter_binding_for_ids(): void
    {
        $this->store->createRelationship(
            fromLabel: 'Person',
            fromId: 1,
            toLabel: 'Team',
            toId: 2,
            type: 'MEMBER_OF',
            properties: []
        );

        $query = $this->capturedQuery[0] ?? '';
        $params = $this->capturedParams[0] ?? [];

        // IDs should be parameters, not interpolated
        $this->assertStringContainsString('$fromId', $query);
        $this->assertStringContainsString('$toId', $query);

        // Parameters should include the IDs
        $this->assertArrayHasKey('fromId', $params);
        $this->assertArrayHasKey('toId', $params);
        $this->assertEquals(1, $params['fromId']);
        $this->assertEquals(2, $params['toId']);
    }

    /** @test */
    public function createRelationship_uses_parameter_binding_for_properties(): void
    {
        $properties = [
            'since' => '2024-01-01',
            'role' => 'Developer',
        ];

        $this->store->createRelationship(
            fromLabel: 'Person',
            fromId: 1,
            toLabel: 'Team',
            toId: 2,
            type: 'MEMBER_OF',
            properties: $properties
        );

        $query = $this->capturedQuery[0] ?? '';
        $params = $this->capturedParams[0] ?? [];

        // Should use parameter binding for relationship properties
        $this->assertStringContainsString('$properties', $query);

        // Properties should be in parameters
        $this->assertArrayHasKey('properties', $params);
        $this->assertEquals($properties, $params['properties']);

        // Raw values should NOT appear in query
        $this->assertStringNotContainsString("'2024-01-01'", $query);
        $this->assertStringNotContainsString("'Developer'", $query);
    }

    /** @test */
    public function createRelationship_uses_SET_clause_with_parameter(): void
    {
        $this->store->createRelationship(
            fromLabel: 'Person',
            fromId: 1,
            toLabel: 'Team',
            toId: 2,
            type: 'MEMBER_OF',
            properties: ['since' => '2024-01-01']
        );

        $query = $this->capturedQuery[0] ?? '';

        // Should use SET r = $properties pattern
        $this->assertMatchesRegularExpression('/SET\s+\w+\s*=\s*\$properties/i', $query);
    }

    /** @test */
    public function createRelationship_escapes_labels_and_type(): void
    {
        $this->store->createRelationship(
            fromLabel: 'PersonNode',
            fromId: 1,
            toLabel: 'TeamNode',
            toId: 2,
            type: 'MEMBER_OF',
            properties: []
        );

        $query = $this->capturedQuery[0] ?? '';

        // Labels and type should be escaped with backticks
        $this->assertStringContainsString('`PersonNode`', $query);
        $this->assertStringContainsString('`TeamNode`', $query);
        $this->assertStringContainsString('`MEMBER_OF`', $query);
    }

    /** @test */
    public function createRelationship_handles_properties_with_injection_attempts(): void
    {
        $properties = [
            'note' => '}) DETACH DELETE (n) //',
            'comment' => "malicious' OR '1'='1",
        ];

        $this->store->createRelationship(
            fromLabel: 'Person',
            fromId: 1,
            toLabel: 'Team',
            toId: 2,
            type: 'WORKS_WITH',
            properties: $properties
        );

        $query = $this->capturedQuery[0] ?? '';
        $params = $this->capturedParams[0] ?? [];

        // Injection attempts should NOT appear in query
        $this->assertStringNotContainsString('DETACH DELETE', $query);
        $this->assertStringNotContainsString("OR '1'='1", $query);

        // Values should be safely passed as parameters
        $this->assertEquals('}) DETACH DELETE (n) //', $params['properties']['note']);
    }

    /** @test */
    public function createRelationship_handles_empty_properties(): void
    {
        $this->store->createRelationship(
            fromLabel: 'Person',
            fromId: 1,
            toLabel: 'Team',
            toId: 2,
            type: 'MEMBER_OF',
            properties: []
        );

        $query = $this->capturedQuery[0] ?? '';
        $params = $this->capturedParams[0] ?? [];

        // Should still use parameter binding for consistency
        $this->assertStringContainsString('$properties', $query);
        $this->assertArrayHasKey('properties', $params);
    }

    // =========================================================================
    // arrayToCypherProps() Deprecation Tests
    // =========================================================================

    /** @test */
    public function arrayToCypherProps_triggers_deprecation_warning(): void
    {
        // Create real store instance to test deprecation
        $store = new Neo4jStore([
            'uri' => 'bolt://localhost:7687',
            'username' => 'neo4j',
            'password' => 'test',
            'database' => 'neo4j',
        ]);

        // Use reflection to access protected method
        $reflection = new ReflectionClass($store);
        $method = $reflection->getMethod('arrayToCypherProps');
        $method->setAccessible(true);

        // Capture deprecation warning using custom error handler
        // Note: The @ operator suppresses display but doesn't prevent triggering
        $deprecationTriggered = false;
        $deprecationMessage = '';

        set_error_handler(function ($errno, $errstr) use (&$deprecationTriggered, &$deprecationMessage) {
            if ($errno === E_USER_DEPRECATED) {
                $deprecationTriggered = true;
                $deprecationMessage = $errstr;
            }
            return true; // Don't execute PHP internal error handler
        }, E_USER_DEPRECATED);

        try {
            $method->invoke($store, ['key' => 'value']);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue(
            $deprecationTriggered,
            'arrayToCypherProps should trigger deprecation warning'
        );
        $this->assertStringContainsString(
            'arrayToCypherProps',
            $deprecationMessage,
            'Deprecation message should mention the method name'
        );
    }

    /** @test */
    public function arrayToCypherProps_still_works_for_backward_compatibility(): void
    {
        $store = new Neo4jStore([
            'uri' => 'bolt://localhost:7687',
            'username' => 'neo4j',
            'password' => 'test',
            'database' => 'neo4j',
        ]);

        $reflection = new ReflectionClass($store);
        $method = $reflection->getMethod('arrayToCypherProps');
        $method->setAccessible(true);

        // Suppress deprecation warning for this test
        $originalHandler = set_error_handler(fn() => true);

        try {
            $result = $method->invoke($store, ['name' => 'test', 'age' => 25]);
        } finally {
            restore_error_handler();
        }

        // Should still produce valid output for backward compatibility
        $this->assertStringContainsString('name:', $result);
        $this->assertStringContainsString('$name', $result);
    }

    // =========================================================================
    // General Security Tests
    // =========================================================================

    /** @test */
    public function queries_do_not_contain_interpolated_property_values(): void
    {
        // Test various property types that should never appear as literals
        $testCases = [
            ['name' => "Robert'); DROP TABLE users;--"],
            ['email' => '<script>alert("xss")</script>'],
            ['data' => '${system("rm -rf /")}'],
            ['cmd' => 'CALL apoc.cypher.run("MATCH (n) DETACH DELETE n", {})'],
        ];

        foreach ($testCases as $properties) {
            $this->capturedQuery = [];
            $this->capturedParams = [];

            $this->store->createNode('TestLabel', array_merge(['id' => 1], $properties));

            $query = $this->capturedQuery[0] ?? '';
            $params = $this->capturedParams[0] ?? [];

            foreach ($properties as $key => $value) {
                $this->assertStringNotContainsString(
                    $value,
                    $query,
                    "Query should not contain raw property value: {$value}"
                );

                $this->assertEquals(
                    $value,
                    $params['properties'][$key],
                    "Property '{$key}' should be passed as parameter"
                );
            }
        }
    }

    /** @test */
    public function id_values_are_always_parameterized(): void
    {
        // Create relationship with various ID types
        $this->store->createRelationship(
            fromLabel: 'Node',
            fromId: 12345,
            toLabel: 'Node',
            toId: 67890,
            type: 'CONNECTS',
            properties: []
        );

        $query = $this->capturedQuery[0] ?? '';

        // Raw ID values should NOT appear in query
        $this->assertStringNotContainsString('12345', $query);
        $this->assertStringNotContainsString('67890', $query);

        // Should use parameter placeholders
        $this->assertStringContainsString('$fromId', $query);
        $this->assertStringContainsString('$toId', $query);
    }
}
