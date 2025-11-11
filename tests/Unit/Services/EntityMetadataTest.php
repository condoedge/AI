<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Mockery;

/**
 * Unit Tests for Entity Metadata Functionality
 *
 * These tests verify the ContextRetriever's ability to:
 * - Detect entities from natural language questions
 * - Retrieve semantic metadata for detected entities
 * - Identify scope terms (volunteers, customers, pending orders, etc.)
 * - Map business terminology to entity filters
 * - Include metadata in context retrieval
 *
 * All dependencies are mocked - NO real database calls are made.
 */
class EntityMetadataTest extends TestCase
{
    private $mockVectorStore;
    private $mockGraphStore;
    private $mockEmbeddingProvider;
    private $service;
    private $testEntityConfigs;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockGraphStore = Mockery::mock(GraphStoreInterface::class);
        $this->mockEmbeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);

        // Define test entity configurations with metadata
        $this->testEntityConfigs = [
            'Person' => [
                'graph' => [
                    'label' => 'Person',
                    'properties' => ['id', 'name', 'type', 'role', 'status'],
                ],
                'metadata' => [
                    'aliases' => ['person', 'people', 'user', 'users', 'individual'],
                    'description' => 'Represents individuals in the system',
                    'scopes' => [
                        'volunteers' => [
                            'description' => 'People who volunteer',
                            'filter' => ['type' => 'volunteer'],
                            'cypher_pattern' => "type = 'volunteer'",
                            'examples' => [
                                'Show me all volunteers',
                                'How many volunteers do we have?',
                            ],
                        ],
                        'customers' => [
                            'description' => 'People who are customers',
                            'filter' => ['type' => 'customer'],
                            'cypher_pattern' => "type = 'customer'",
                            'examples' => [
                                'Show me all customers',
                                'List customers',
                            ],
                        ],
                        'staff' => [
                            'description' => 'Staff members',
                            'filter' => ['role' => 'staff'],
                            'cypher_pattern' => "role = 'staff'",
                            'examples' => ['List staff members'],
                        ],
                    ],
                    'common_properties' => [
                        'id' => 'Unique identifier',
                        'name' => 'Person name',
                        'type' => 'Person type: volunteer, customer, staff',
                        'role' => 'Person role',
                        'status' => 'Current status',
                    ],
                ],
            ],
            'Order' => [
                'graph' => [
                    'label' => 'Order',
                    'properties' => ['id', 'total', 'status'],
                ],
                'metadata' => [
                    'aliases' => ['order', 'orders', 'purchase', 'purchases'],
                    'description' => 'Customer orders',
                    'scopes' => [
                        'pending' => [
                            'description' => 'Orders awaiting processing',
                            'filter' => ['status' => 'pending'],
                            'cypher_pattern' => "status = 'pending'",
                            'examples' => ['Show pending orders'],
                        ],
                        'completed' => [
                            'description' => 'Completed orders',
                            'filter' => ['status' => 'completed'],
                            'cypher_pattern' => "status = 'completed'",
                            'examples' => ['Show completed orders'],
                        ],
                    ],
                    'common_properties' => [
                        'id' => 'Order ID',
                        'total' => 'Total amount',
                        'status' => 'Order status',
                    ],
                ],
            ],
            'Team' => [
                'graph' => [
                    'label' => 'Team',
                    'properties' => ['id', 'name'],
                ],
                // No metadata - should be skipped
            ],
        ];

        $this->service = new ContextRetriever(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider,
            $this->testEntityConfigs
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // getEntityMetadata() Tests - Entity Detection
    // =========================================================================

    /**
     * Test that getEntityMetadata detects entity by exact label name
     */
    public function test_get_entity_metadata_detects_entity_by_label_name()
    {
        $question = 'Show me all Person records';

        $metadata = $this->service->getEntityMetadata($question);

        $this->assertArrayHasKey('detected_entities', $metadata);
        $this->assertContains('Person', $metadata['detected_entities']);
        $this->assertArrayHasKey('Person', $metadata['entity_metadata']);
    }

    /**
     * Test that getEntityMetadata detects entity by alias
     */
    public function test_get_entity_metadata_detects_entity_by_alias()
    {
        $question = 'How many people are in the database?';

        $metadata = $this->service->getEntityMetadata($question);

        $this->assertContains('Person', $metadata['detected_entities']);
        $this->assertArrayHasKey('Person', $metadata['entity_metadata']);
    }

    /**
     * Test that getEntityMetadata detects multiple aliases
     */
    public function test_get_entity_metadata_detects_multiple_aliases()
    {
        $testCases = [
            'Show me all users',
            'List individuals',
            'How many people',
        ];

        foreach ($testCases as $question) {
            $metadata = $this->service->getEntityMetadata($question);
            $this->assertContains('Person', $metadata['detected_entities'],
                "Failed to detect Person entity in: {$question}");
        }
    }

    /**
     * Test that getEntityMetadata is case-insensitive
     */
    public function test_get_entity_metadata_is_case_insensitive()
    {
        $testCases = [
            'Show me all PEOPLE',
            'How many People are there?',
            'List all pEoPle',
        ];

        foreach ($testCases as $question) {
            $metadata = $this->service->getEntityMetadata($question);
            $this->assertContains('Person', $metadata['detected_entities'],
                "Failed to detect Person entity (case-insensitive) in: {$question}");
        }
    }

    /**
     * Test that getEntityMetadata detects multiple entities
     */
    public function test_get_entity_metadata_detects_multiple_entities()
    {
        $question = 'Show me all people and their orders';

        $metadata = $this->service->getEntityMetadata($question);

        $this->assertCount(2, $metadata['detected_entities']);
        $this->assertContains('Person', $metadata['detected_entities']);
        $this->assertContains('Order', $metadata['detected_entities']);
        $this->assertArrayHasKey('Person', $metadata['entity_metadata']);
        $this->assertArrayHasKey('Order', $metadata['entity_metadata']);
    }

    /**
     * Test that getEntityMetadata skips entities without metadata
     */
    public function test_get_entity_metadata_skips_entities_without_metadata()
    {
        $question = 'Show me all teams';

        $metadata = $this->service->getEntityMetadata($question);

        // Team entity exists but has no metadata, should not be detected
        $this->assertNotContains('Team', $metadata['detected_entities']);
        $this->assertArrayNotHasKey('Team', $metadata['entity_metadata']);
    }

    /**
     * Test that getEntityMetadata returns empty for unknown terms
     */
    public function test_get_entity_metadata_returns_empty_for_unknown_terms()
    {
        $question = 'Show me all unicorns and dragons';

        $metadata = $this->service->getEntityMetadata($question);

        $this->assertEmpty($metadata['detected_entities']);
        $this->assertEmpty($metadata['entity_metadata']);
        $this->assertEmpty($metadata['detected_scopes']);
    }

    // =========================================================================
    // getEntityMetadata() Tests - Scope Detection
    // =========================================================================

    /**
     * Test that getEntityMetadata detects scope terms
     */
    public function test_get_entity_metadata_detects_scope_terms()
    {
        $question = 'How many volunteers do we have?';

        $metadata = $this->service->getEntityMetadata($question);

        $this->assertArrayHasKey('detected_scopes', $metadata);
        $this->assertArrayHasKey('volunteers', $metadata['detected_scopes']);

        $scope = $metadata['detected_scopes']['volunteers'];
        $this->assertEquals('Person', $scope['entity']);
        $this->assertEquals('volunteers', $scope['scope']);
        $this->assertEquals("type = 'volunteer'", $scope['cypher_pattern']);
        $this->assertEquals(['type' => 'volunteer'], $scope['filter']);
    }

    /**
     * Test that scope detection triggers entity detection
     */
    public function test_scope_detection_triggers_entity_detection()
    {
        $question = 'Show me all customers';

        $metadata = $this->service->getEntityMetadata($question);

        // Scope term 'customers' should trigger Person entity detection
        $this->assertContains('Person', $metadata['detected_entities']);
        $this->assertArrayHasKey('customers', $metadata['detected_scopes']);
    }

    /**
     * Test that multiple scopes can be detected
     */
    public function test_get_entity_metadata_detects_multiple_scopes()
    {
        $question = 'Show me pending and completed orders';

        $metadata = $this->service->getEntityMetadata($question);

        $this->assertArrayHasKey('pending', $metadata['detected_scopes']);
        $this->assertArrayHasKey('completed', $metadata['detected_scopes']);

        $this->assertEquals('Order', $metadata['detected_scopes']['pending']['entity']);
        $this->assertEquals('Order', $metadata['detected_scopes']['completed']['entity']);
    }

    /**
     * Test that scopes from different entities are detected
     */
    public function test_get_entity_metadata_detects_scopes_from_different_entities()
    {
        $question = 'Show me volunteers and pending orders';

        $metadata = $this->service->getEntityMetadata($question);

        $this->assertArrayHasKey('volunteers', $metadata['detected_scopes']);
        $this->assertArrayHasKey('pending', $metadata['detected_scopes']);

        $this->assertEquals('Person', $metadata['detected_scopes']['volunteers']['entity']);
        $this->assertEquals('Order', $metadata['detected_scopes']['pending']['entity']);
    }

    /**
     * Test scope detection is case-insensitive
     */
    public function test_scope_detection_is_case_insensitive()
    {
        $testCases = [
            'Show me all VOLUNTEERS',
            'How many Volunteers?',
            'List vOlUnTeErS',
        ];

        foreach ($testCases as $question) {
            $metadata = $this->service->getEntityMetadata($question);
            $this->assertArrayHasKey('volunteers', $metadata['detected_scopes'],
                "Failed to detect 'volunteers' scope in: {$question}");
        }
    }

    // =========================================================================
    // getEntityMetadata() Tests - Metadata Structure
    // =========================================================================

    /**
     * Test that entity metadata includes all expected keys
     */
    public function test_entity_metadata_includes_all_expected_keys()
    {
        $question = 'Show me all people';

        $metadata = $this->service->getEntityMetadata($question);

        $personMetadata = $metadata['entity_metadata']['Person'];

        $this->assertArrayHasKey('aliases', $personMetadata);
        $this->assertArrayHasKey('description', $personMetadata);
        $this->assertArrayHasKey('scopes', $personMetadata);
        $this->assertArrayHasKey('common_properties', $personMetadata);
    }

    /**
     * Test that scope metadata includes required fields
     */
    public function test_scope_metadata_includes_required_fields()
    {
        $question = 'Show me all volunteers';

        $metadata = $this->service->getEntityMetadata($question);
        $scope = $metadata['detected_scopes']['volunteers'];

        $this->assertArrayHasKey('entity', $scope);
        $this->assertArrayHasKey('scope', $scope);
        $this->assertArrayHasKey('description', $scope);
        $this->assertArrayHasKey('cypher_pattern', $scope);
        $this->assertArrayHasKey('filter', $scope);
    }

    /**
     * Test that getEntityMetadata returns proper structure
     */
    public function test_get_entity_metadata_returns_proper_structure()
    {
        $question = 'Show me volunteers';

        $metadata = $this->service->getEntityMetadata($question);

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('detected_entities', $metadata);
        $this->assertArrayHasKey('entity_metadata', $metadata);
        $this->assertArrayHasKey('detected_scopes', $metadata);

        $this->assertIsArray($metadata['detected_entities']);
        $this->assertIsArray($metadata['entity_metadata']);
        $this->assertIsArray($metadata['detected_scopes']);
    }

    // =========================================================================
    // getAllEntityMetadata() Tests
    // =========================================================================

    /**
     * Test that getAllEntityMetadata returns all metadata
     */
    public function test_get_all_entity_metadata_returns_all_metadata()
    {
        $allMetadata = $this->service->getAllEntityMetadata();

        $this->assertArrayHasKey('Person', $allMetadata);
        $this->assertArrayHasKey('Order', $allMetadata);
        $this->assertArrayNotHasKey('Team', $allMetadata); // No metadata
    }

    /**
     * Test that getAllEntityMetadata returns complete metadata structure
     */
    public function test_get_all_entity_metadata_returns_complete_structure()
    {
        $allMetadata = $this->service->getAllEntityMetadata();

        $personMetadata = $allMetadata['Person'];

        $this->assertArrayHasKey('aliases', $personMetadata);
        $this->assertArrayHasKey('description', $personMetadata);
        $this->assertArrayHasKey('scopes', $personMetadata);
        $this->assertArrayHasKey('common_properties', $personMetadata);
    }

    /**
     * Test that getAllEntityMetadata returns empty when no entities have metadata
     */
    public function test_get_all_entity_metadata_returns_empty_when_no_metadata()
    {
        // Create service with entity configs without metadata
        $configsWithoutMetadata = [
            'Team' => [
                'graph' => ['label' => 'Team'],
            ],
        ];

        $service = new ContextRetriever(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider,
            $configsWithoutMetadata
        );

        $allMetadata = $service->getAllEntityMetadata();

        $this->assertEmpty($allMetadata);
    }

    // =========================================================================
    // retrieveContext() Integration Tests
    // =========================================================================

    /**
     * Test that retrieveContext includes entity metadata
     */
    public function test_retrieve_context_includes_entity_metadata()
    {
        $question = 'How many volunteers do we have?';

        // Mock dependencies
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $context = $this->service->retrieveContext($question);

        $this->assertArrayHasKey('entity_metadata', $context);
        $this->assertArrayHasKey('detected_entities', $context['entity_metadata']);
        $this->assertArrayHasKey('detected_scopes', $context['entity_metadata']);
    }

    /**
     * Test that retrieveContext handles metadata retrieval failure gracefully
     */
    public function test_retrieve_context_handles_metadata_failure_gracefully()
    {
        $question = 'Test question';

        // Create a service that will throw an exception when getting metadata
        $brokenService = new class(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider,
            [] // Empty configs will cause issues
        ) extends ContextRetriever {
            public function getEntityMetadata(string $question): array
            {
                throw new \Exception('Metadata retrieval failed');
            }
        };

        // Mock dependencies
        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $context = $brokenService->retrieveContext($question);

        // Should have error but not crash
        $this->assertNotEmpty($context['errors']);
        $this->assertStringContainsString('Entity metadata retrieval failed', $context['errors'][0]);
    }

    /**
     * Test that context includes metadata for detected scopes
     */
    public function test_context_includes_metadata_for_detected_scopes()
    {
        $question = 'Show me all volunteers';

        $this->mockEmbeddingProvider
            ->shouldReceive('embed')
            ->once()
            ->andReturn([0.1]);

        $this->mockVectorStore
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $this->mockGraphStore
            ->shouldReceive('getSchema')
            ->once()
            ->andReturn(['labels' => [], 'relationshipTypes' => [], 'propertyKeys' => []]);

        $context = $this->service->retrieveContext($question);

        $this->assertArrayHasKey('volunteers', $context['entity_metadata']['detected_scopes']);

        $volunteerScope = $context['entity_metadata']['detected_scopes']['volunteers'];
        $this->assertEquals('Person', $volunteerScope['entity']);
        $this->assertEquals("type = 'volunteer'", $volunteerScope['cypher_pattern']);
    }

    // =========================================================================
    // Real-World Scenario Tests
    // =========================================================================

    /**
     * Test volunteer detection scenario
     */
    public function test_volunteer_detection_scenario()
    {
        $questions = [
            'How many volunteers do we have?',
            'Show me all volunteers',
            'List active volunteers',
            'Who are our volunteers?',
        ];

        foreach ($questions as $question) {
            $metadata = $this->service->getEntityMetadata($question);

            $this->assertContains('Person', $metadata['detected_entities'],
                "Failed for: {$question}");
            $this->assertArrayHasKey('volunteers', $metadata['detected_scopes'],
                "Failed to detect volunteers scope in: {$question}");
        }
    }

    /**
     * Test customer detection scenario
     */
    public function test_customer_detection_scenario()
    {
        $questions = [
            'Show me all customers',
            'How many customers placed orders?',
            'List customers',
        ];

        foreach ($questions as $question) {
            $metadata = $this->service->getEntityMetadata($question);

            $this->assertContains('Person', $metadata['detected_entities'],
                "Failed for: {$question}");
            $this->assertArrayHasKey('customers', $metadata['detected_scopes'],
                "Failed to detect customers scope in: {$question}");
        }
    }

    /**
     * Test order status detection scenario
     */
    public function test_order_status_detection_scenario()
    {
        $testCases = [
            'Show pending orders' => 'pending',
            'List completed orders' => 'completed',
            'How many pending orders?' => 'pending',
        ];

        foreach ($testCases as $question => $expectedScope) {
            $metadata = $this->service->getEntityMetadata($question);

            $this->assertContains('Order', $metadata['detected_entities'],
                "Failed to detect Order entity for: {$question}");
            $this->assertArrayHasKey($expectedScope, $metadata['detected_scopes'],
                "Failed to detect '{$expectedScope}' scope in: {$question}");
        }
    }

    /**
     * Test complex multi-entity, multi-scope scenario
     */
    public function test_complex_multi_entity_scenario()
    {
        $question = 'Show me all volunteers who have pending orders';

        $metadata = $this->service->getEntityMetadata($question);

        // Should detect both entities
        $this->assertCount(2, $metadata['detected_entities']);
        $this->assertContains('Person', $metadata['detected_entities']);
        $this->assertContains('Order', $metadata['detected_entities']);

        // Should detect both scopes
        $this->assertArrayHasKey('volunteers', $metadata['detected_scopes']);
        $this->assertArrayHasKey('pending', $metadata['detected_scopes']);

        // Verify scope details
        $this->assertEquals('Person', $metadata['detected_scopes']['volunteers']['entity']);
        $this->assertEquals('Order', $metadata['detected_scopes']['pending']['entity']);
    }
}
