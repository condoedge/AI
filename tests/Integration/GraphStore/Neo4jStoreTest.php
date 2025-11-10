<?php

namespace AiSystem\Tests\Integration\GraphStore;

use AiSystem\GraphStore\Neo4jStore;
use AiSystem\Tests\TestCase;

/**
 * Integration tests for Neo4jStore
 *
 * These tests connect to a real Neo4j instance
 * Ensure docker-compose is running before running these tests
 */
class Neo4jStoreTest extends TestCase
{
    protected Neo4jStore $neo4j;
    protected string $testLabel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neo4j = new Neo4jStore();
        $this->testLabel = 'TestNode' . uniqid();

        // Skip if Neo4j is not available
        if (!$this->neo4j->testConnection()) {
            $this->markTestSkipped('Neo4j is not available. Start docker-compose.');
        }
    }

    protected function tearDown(): void
    {
        // Cleanup: delete all test nodes
        try {
            $this->neo4j->query("MATCH (n:{$this->testLabel}) DETACH DELETE n");
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }

        parent::tearDown();
    }

    public function test_connection()
    {
        $this->assertTrue($this->neo4j->testConnection());
    }

    public function test_create_node()
    {
        $nodeId = $this->neo4j->createNode($this->testLabel, [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ]);

        $this->assertNotEmpty($nodeId);
        $this->assertEquals(1, $nodeId); // Should return application ID
    }

    public function test_node_exists()
    {
        $this->neo4j->createNode($this->testLabel, [
            'id' => 123,
            'name' => 'Test'
        ]);

        $this->assertTrue($this->neo4j->nodeExists($this->testLabel, 123));
        $this->assertFalse($this->neo4j->nodeExists($this->testLabel, 999));
    }

    public function test_get_node()
    {
        $this->neo4j->createNode($this->testLabel, [
            'id' => 456,
            'name' => 'Jane Smith',
            'email' => 'jane@example.com'
        ]);

        $node = $this->neo4j->getNode($this->testLabel, 456);

        $this->assertNotNull($node);
        $this->assertEquals(456, $node['id']);
        $this->assertEquals('Jane Smith', $node['name']);
        $this->assertEquals('jane@example.com', $node['email']);
    }

    public function test_update_node()
    {
        $this->neo4j->createNode($this->testLabel, [
            'id' => 789,
            'name' => 'Original Name'
        ]);

        $result = $this->neo4j->updateNode($this->testLabel, 789, [
            'name' => 'Updated Name',
            'email' => 'updated@example.com'
        ]);

        $this->assertTrue($result);

        $node = $this->neo4j->getNode($this->testLabel, 789);
        $this->assertEquals('Updated Name', $node['name']);
        $this->assertEquals('updated@example.com', $node['email']);
    }

    public function test_delete_node()
    {
        $this->neo4j->createNode($this->testLabel, [
            'id' => 111,
            'name' => 'To Delete'
        ]);

        $this->assertTrue($this->neo4j->nodeExists($this->testLabel, 111));

        $result = $this->neo4j->deleteNode($this->testLabel, 111);

        $this->assertTrue($result);
        $this->assertFalse($this->neo4j->nodeExists($this->testLabel, 111));
    }

    public function test_create_relationship()
    {
        $fromLabel = $this->testLabel . 'From';
        $toLabel = $this->testLabel . 'To';

        $this->neo4j->createNode($fromLabel, ['id' => 1, 'name' => 'Person']);
        $this->neo4j->createNode($toLabel, ['id' => 2, 'name' => 'Team']);

        $result = $this->neo4j->createRelationship(
            fromLabel: $fromLabel,
            fromId: 1,
            toLabel: $toLabel,
            toId: 2,
            type: 'MEMBER_OF',
            properties: ['since' => '2024-01-01']
        );

        $this->assertTrue($result);

        // Verify relationship exists
        $rels = $this->neo4j->query("
            MATCH (from:{$fromLabel} {id: 1})-[r:MEMBER_OF]->(to:{$toLabel} {id: 2})
            RETURN r
        ");

        $this->assertCount(1, $rels);
        $this->assertEquals('2024-01-01', $rels[0]['r']['since']);
    }

    public function test_delete_relationship()
    {
        $fromLabel = $this->testLabel . 'From2';
        $toLabel = $this->testLabel . 'To2';

        $this->neo4j->createNode($fromLabel, ['id' => 10, 'name' => 'A']);
        $this->neo4j->createNode($toLabel, ['id' => 20, 'name' => 'B']);

        $this->neo4j->createRelationship(
            fromLabel: $fromLabel,
            fromId: 10,
            toLabel: $toLabel,
            toId: 20,
            type: 'CONNECTED_TO'
        );

        $result = $this->neo4j->deleteRelationship(
            fromLabel: $fromLabel,
            fromId: 10,
            toLabel: $toLabel,
            toId: 20,
            type: 'CONNECTED_TO'
        );

        $this->assertTrue($result);

        // Verify relationship deleted
        $rels = $this->neo4j->query("
            MATCH (from:{$fromLabel} {id: 10})-[r:CONNECTED_TO]->(to:{$toLabel} {id: 20})
            RETURN r
        ");

        $this->assertEmpty($rels);
    }

    public function test_query()
    {
        // Create test data
        $this->neo4j->createNode($this->testLabel, ['id' => 1, 'name' => 'Alice', 'age' => 30]);
        $this->neo4j->createNode($this->testLabel, ['id' => 2, 'name' => 'Bob', 'age' => 25]);
        $this->neo4j->createNode($this->testLabel, ['id' => 3, 'name' => 'Charlie', 'age' => 35]);

        // Query with parameters
        $results = $this->neo4j->query("
            MATCH (n:{$this->testLabel})
            WHERE n.age >= \$minAge
            RETURN n.name as name, n.age as age
            ORDER BY n.age
        ", ['minAge' => 30]);

        $this->assertCount(2, $results);
        $this->assertEquals('Alice', $results[0]['name']);
        $this->assertEquals(30, $results[0]['age']);
        $this->assertEquals('Charlie', $results[1]['name']);
        $this->assertEquals(35, $results[1]['age']);
    }

    public function test_get_schema()
    {
        // Create some test data to ensure schema has content
        $this->neo4j->createNode($this->testLabel, ['id' => 1, 'name' => 'Test']);

        $schema = $this->neo4j->getSchema();

        $this->assertArrayHasKeys(['labels', 'relationshipTypes', 'propertyKeys'], $schema);
        $this->assertIsArray($schema['labels']);
        $this->assertIsArray($schema['relationshipTypes']);
        $this->assertIsArray($schema['propertyKeys']);

        // Our test label should be in the schema
        $this->assertContains($this->testLabel, $schema['labels']);
    }

    public function test_complex_query_with_relationships()
    {
        $personLabel = $this->testLabel . 'Person';
        $teamLabel = $this->testLabel . 'Team';

        // Create people
        $this->neo4j->createNode($personLabel, ['id' => 1, 'name' => 'Alice']);
        $this->neo4j->createNode($personLabel, ['id' => 2, 'name' => 'Bob']);
        $this->neo4j->createNode($personLabel, ['id' => 3, 'name' => 'Charlie']);

        // Create team
        $this->neo4j->createNode($teamLabel, ['id' => 10, 'name' => 'Alpha Team']);

        // Create relationships
        $this->neo4j->createRelationship($personLabel, 1, $teamLabel, 10, 'MEMBER_OF');
        $this->neo4j->createRelationship($personLabel, 2, $teamLabel, 10, 'MEMBER_OF');

        // Query team with member count
        $results = $this->neo4j->query("
            MATCH (t:{$teamLabel} {id: 10})<-[:MEMBER_OF]-(p:{$personLabel})
            RETURN t.name as team_name, count(p) as member_count
        ");

        $this->assertCount(1, $results);
        $this->assertEquals('Alpha Team', $results[0]['team_name']);
        $this->assertEquals(2, $results[0]['member_count']);
    }

    public function test_transaction_operations()
    {
        // Begin transaction
        $tx = $this->neo4j->beginTransaction();
        $this->assertNotNull($tx);

        // Commit
        $this->assertTrue($this->neo4j->commit($tx));

        // Rollback
        $tx2 = $this->neo4j->beginTransaction();
        $this->assertTrue($this->neo4j->rollback($tx2));
    }
}
