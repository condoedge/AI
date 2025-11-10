<?php

namespace AiSystem\Tests\Integration\VectorStore;

use AiSystem\VectorStore\QdrantStore;
use AiSystem\Tests\TestCase;

/**
 * Integration tests for QdrantStore
 *
 * These tests connect to a real Qdrant instance
 * Ensure docker-compose is running before running these tests
 */
class QdrantStoreTest extends TestCase
{
    protected QdrantStore $qdrant;
    protected string $testCollection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qdrant = new QdrantStore();
        $this->testCollection = $this->getTestCollectionName('qdrant');

        // Skip if Qdrant is not available
        if (!$this->qdrant->testConnection()) {
            $this->markTestSkipped('Qdrant is not available. Start docker-compose.');
        }
    }

    protected function tearDown(): void
    {
        // Cleanup: delete test collection
        try {
            if ($this->qdrant->collectionExists($this->testCollection)) {
                $this->qdrant->deleteCollection($this->testCollection);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }

        parent::tearDown();
    }

    public function test_connection()
    {
        $this->assertTrue($this->qdrant->testConnection());
    }

    public function test_create_collection()
    {
        $result = $this->qdrant->createCollection($this->testCollection, 128, 'cosine');

        $this->assertTrue($result);
        $this->assertTrue($this->qdrant->collectionExists($this->testCollection));
    }

    public function test_collection_exists()
    {
        // Non-existent collection
        $this->assertFalse($this->qdrant->collectionExists('nonexistent_collection_xyz'));

        // Create and verify
        $this->qdrant->createCollection($this->testCollection, 128);
        $this->assertTrue($this->qdrant->collectionExists($this->testCollection));
    }

    public function test_delete_collection()
    {
        $this->qdrant->createCollection($this->testCollection, 128);
        $this->assertTrue($this->qdrant->collectionExists($this->testCollection));

        $result = $this->qdrant->deleteCollection($this->testCollection);

        $this->assertTrue($result);
        $this->assertFalse($this->qdrant->collectionExists($this->testCollection));
    }

    public function test_upsert_points()
    {
        $this->qdrant->createCollection($this->testCollection, 3);

        $points = [
            [
                'id' => 1,
                'vector' => [0.1, 0.2, 0.3],
                'payload' => ['name' => 'John Doe', 'age' => 30]
            ],
            [
                'id' => 2,
                'vector' => [0.4, 0.5, 0.6],
                'payload' => ['name' => 'Jane Smith', 'age' => 25]
            ]
        ];

        $result = $this->qdrant->upsert($this->testCollection, $points);

        $this->assertTrue($result);
    }

    public function test_search()
    {
        $this->qdrant->createCollection($this->testCollection, 3);

        // Insert test data
        $points = [
            ['id' => 1, 'vector' => [1.0, 0.0, 0.0], 'payload' => ['category' => 'A']],
            ['id' => 2, 'vector' => [0.9, 0.1, 0.0], 'payload' => ['category' => 'A']],
            ['id' => 3, 'vector' => [0.0, 1.0, 0.0], 'payload' => ['category' => 'B']],
        ];
        $this->qdrant->upsert($this->testCollection, $points);

        // Search for similar to [1.0, 0.0, 0.0]
        $results = $this->qdrant->search(
            collection: $this->testCollection,
            vector: [1.0, 0.0, 0.0],
            limit: 2
        );

        $this->assertCount(2, $results);
        $this->assertEquals(1, $results[0]['id']);
        $this->assertArrayHasKey('score', $results[0]);
        $this->assertArrayHasKey('payload', $results[0]);
    }

    public function test_search_with_filter()
    {
        $this->qdrant->createCollection($this->testCollection, 3);

        $points = [
            ['id' => 1, 'vector' => [1.0, 0.0, 0.0], 'payload' => ['category' => 'A']],
            ['id' => 2, 'vector' => [0.9, 0.1, 0.0], 'payload' => ['category' => 'A']],
            ['id' => 3, 'vector' => [0.8, 0.2, 0.0], 'payload' => ['category' => 'B']],
        ];
        $this->qdrant->upsert($this->testCollection, $points);

        // Search with filter
        $results = $this->qdrant->search(
            collection: $this->testCollection,
            vector: [1.0, 0.0, 0.0],
            limit: 10,
            filter: ['category' => 'A']
        );

        $this->assertLessThanOrEqual(2, count($results));

        foreach ($results as $result) {
            $this->assertEquals('A', $result['payload']['category']);
        }
    }

    public function test_get_point()
    {
        $this->qdrant->createCollection($this->testCollection, 3);

        $point = [
            'id' => 123,
            'vector' => [0.1, 0.2, 0.3],
            'payload' => ['name' => 'Test User']
        ];

        $this->qdrant->upsert($this->testCollection, [$point]);

        $retrieved = $this->qdrant->getPoint($this->testCollection, 123);

        $this->assertNotNull($retrieved);
        $this->assertEquals(123, $retrieved['id']);
        // Note: Qdrant normalizes vectors with cosine distance, so we just check it exists
        $this->assertIsArray($retrieved['vector']);
        $this->assertCount(3, $retrieved['vector']);
        $this->assertEquals('Test User', $retrieved['payload']['name']);
    }

    public function test_delete_points()
    {
        $this->qdrant->createCollection($this->testCollection, 3);

        $points = [
            ['id' => 1, 'vector' => [0.1, 0.2, 0.3], 'payload' => []],
            ['id' => 2, 'vector' => [0.4, 0.5, 0.6], 'payload' => []],
        ];

        $this->qdrant->upsert($this->testCollection, $points);

        $result = $this->qdrant->deletePoints($this->testCollection, [1]);

        $this->assertTrue($result);
        $this->assertNull($this->qdrant->getPoint($this->testCollection, 1));
        $this->assertNotNull($this->qdrant->getPoint($this->testCollection, 2));
    }

    public function test_get_collection_info()
    {
        $this->qdrant->createCollection($this->testCollection, 128, 'cosine');

        $info = $this->qdrant->getCollectionInfo($this->testCollection);

        $this->assertIsArray($info);
        $this->assertArrayHasKey('config', $info);
        $this->assertEquals(128, $info['config']['params']['vectors']['size']);
    }

    public function test_count()
    {
        $this->qdrant->createCollection($this->testCollection, 3);

        // Initially empty
        $this->assertEquals(0, $this->qdrant->count($this->testCollection));

        // Add points
        $points = [
            ['id' => 1, 'vector' => [0.1, 0.2, 0.3], 'payload' => ['type' => 'A']],
            ['id' => 2, 'vector' => [0.4, 0.5, 0.6], 'payload' => ['type' => 'B']],
            ['id' => 3, 'vector' => [0.7, 0.8, 0.9], 'payload' => ['type' => 'A']],
        ];
        $this->qdrant->upsert($this->testCollection, $points);

        $this->assertEquals(3, $this->qdrant->count($this->testCollection));

        // Count with filter
        $countA = $this->qdrant->count($this->testCollection, ['type' => 'A']);
        $this->assertEquals(2, $countA);
    }
}
