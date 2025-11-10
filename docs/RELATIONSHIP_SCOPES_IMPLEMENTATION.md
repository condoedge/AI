# Relationship-Based Scopes - Implementation Guide

This document provides the specific code changes needed to implement relationship-based scopes in the Entity Metadata System.

## Overview

Three main files need updates:
1. **ContextRetriever.php** - Detect pattern types and format for LLM
2. **QueryGenerator.php** - Enhanced prompt with relationship pattern guidance
3. **EntityMetadataTest.php** - Comprehensive test coverage

## 1. ContextRetriever.php Changes

### Change 1.1: Update getEntityMetadata() to Include Pattern Type

**Location**: Line 379-392 in `src/Services/ContextRetriever.php`

**Current Code**:
```php
// Check for scope terms
if (!empty($metadata['scopes'])) {
    foreach ($metadata['scopes'] as $scopeName => $scopeConfig) {
        if (strpos($questionLower, strtolower($scopeName)) !== false) {
            $isDetected = true;

            // Record the detected scope
            $detectedScopes[$scopeName] = [
                'entity' => $entityName,
                'scope' => $scopeName,
                'description' => $scopeConfig['description'] ?? '',
                'cypher_pattern' => $scopeConfig['cypher_pattern'] ?? '',
                'filter' => $scopeConfig['filter'] ?? [],
            ];
        }
    }
}
```

**New Code**:
```php
// Check for scope terms
if (!empty($metadata['scopes'])) {
    foreach ($metadata['scopes'] as $scopeName => $scopeConfig) {
        if (strpos($questionLower, strtolower($scopeName)) !== false) {
            $isDetected = true;

            // Record the detected scope with pattern type
            $detectedScopes[$scopeName] = [
                'entity' => $entityName,
                'scope' => $scopeName,
                'description' => $scopeConfig['description'] ?? '',
                'pattern_type' => $scopeConfig['pattern_type'] ?? 'simple',
                'cypher_pattern' => $scopeConfig['cypher_pattern'] ?? '',
                'cypher_template' => $scopeConfig['cypher_template'] ?? '',
                'filter' => $scopeConfig['filter'] ?? [],
                'relationship' => $scopeConfig['relationship'] ?? null,
                'modification_guidance' => $scopeConfig['modification_guidance'] ?? '',
            ];
        }
    }
}
```

**Changes**:
- Added `pattern_type` with default value 'simple' for backward compatibility
- Added `cypher_template` for complex patterns
- Added `relationship` structure for structured relationship patterns
- Added `modification_guidance` for complex patterns

### Change 1.2: Add formatScopesForLLM() Method

**Location**: Add new method at end of class (after line 578)

**New Method**:
```php
/**
 * Format detected scopes for LLM prompt
 *
 * Converts detected scopes into LLM-friendly format with clear guidance
 * on how to use simple vs. relationship vs. complex patterns.
 *
 * @param array $detectedScopes Detected scopes from getEntityMetadata()
 * @return string Formatted text for LLM prompt
 */
public function formatScopesForLLM(array $detectedScopes): string
{
    if (empty($detectedScopes)) {
        return '';
    }

    $output = "Detected Business Terms (Scopes):\n\n";

    // Group by pattern type
    $grouped = [
        'simple' => [],
        'relationship' => [],
        'complex' => [],
    ];

    foreach ($detectedScopes as $scopeName => $scopeInfo) {
        $patternType = $scopeInfo['pattern_type'] ?? 'simple';
        $grouped[$patternType][$scopeName] = $scopeInfo;
    }

    // Format simple scopes
    if (!empty($grouped['simple'])) {
        $output .= "SIMPLE PROPERTY FILTERS (use in WHERE clause):\n";
        foreach ($grouped['simple'] as $scopeName => $info) {
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → WHERE {$info['cypher_pattern']}\n\n";
        }
    }

    // Format relationship scopes
    if (!empty($grouped['relationship'])) {
        $output .= "RELATIONSHIP PATTERNS (MUST use complete MATCH pattern):\n";
        foreach ($grouped['relationship'] as $scopeName => $info) {
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → Use this EXACT pattern:\n\n";

            // Clean and indent the cypher pattern
            $cypherLines = explode("\n", trim($info['cypher_pattern']));
            foreach ($cypherLines as $line) {
                $output .= "  " . trim($line) . "\n";
            }

            $output .= "\n  CRITICAL: This requires relationship traversal.\n";
            $output .= "  You MUST use this complete MATCH pattern, not a simple property filter.\n";
            $output .= "  You can extend the WHERE clause or modify the RETURN clause as needed.\n\n";
        }
    }

    // Format complex scopes
    if (!empty($grouped['complex'])) {
        $output .= "COMPLEX PATTERNS (use template as-is):\n";
        foreach ($grouped['complex'] as $scopeName => $info) {
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → Template:\n\n";

            $templateLines = explode("\n", trim($info['cypher_template']));
            foreach ($templateLines as $line) {
                $output .= "  " . trim($line) . "\n";
            }
            $output .= "\n";

            if (!empty($info['modification_guidance'])) {
                $output .= "  Note: {$info['modification_guidance']}\n\n";
            }
        }
    }

    return $output;
}
```

**Purpose**: Format scope information in a way that clearly guides the LLM on how to use each pattern type.

## 2. QueryGenerator.php Changes

### Change 2.1: Update buildPrompt() Method

**Location**: Lines 334-414 in `src/Services/QueryGenerator.php`

Replace the section that adds entity metadata (lines 345-383) with this enhanced version:

**Current Code**:
```php
// Add entity metadata if available
if (!empty($context['entity_metadata'])) {
    $metadata = $context['entity_metadata'];

    // Add detected scopes (business terminology mapping)
    if (!empty($metadata['detected_scopes'])) {
        $prompt .= "Detected Business Terms (Scopes):\n";
        foreach ($metadata['detected_scopes'] as $scopeName => $scopeInfo) {
            $prompt .= "- '{$scopeName}' means {$scopeInfo['description']} ";
            $prompt .= "→ Use filter: {$scopeInfo['cypher_pattern']}\n";
        }
        $prompt .= "\n";
    }

    // Add entity-specific metadata for context
    if (!empty($metadata['entity_metadata'])) {
        $prompt .= "Entity-Specific Information:\n";
        foreach ($metadata['entity_metadata'] as $entityName => $entityMeta) {
            $prompt .= "- {$entityName}: {$entityMeta['description']}\n";

            // Add common properties
            if (!empty($entityMeta['common_properties'])) {
                $prompt .= "  Properties: ";
                $propDescriptions = [];
                foreach ($entityMeta['common_properties'] as $prop => $desc) {
                    $propDescriptions[] = "{$prop} ({$desc})";
                }
                $prompt .= implode(', ', array_slice($propDescriptions, 0, 5)) . "\n";
            }

            // Add available scopes
            if (!empty($entityMeta['scopes'])) {
                $prompt .= "  Available filters: ";
                $scopeNames = array_keys($entityMeta['scopes']);
                $prompt .= implode(', ', $scopeNames) . "\n";
            }
        }
        $prompt .= "\n";
    }
}
```

**New Code**:
```php
// Add entity metadata with enhanced relationship pattern guidance
if (!empty($context['entity_metadata'])) {
    $metadata = $context['entity_metadata'];

    // Add detected scopes with pattern type formatting
    if (!empty($metadata['detected_scopes'])) {
        $prompt .= $this->formatScopesForPrompt($metadata['detected_scopes']);
    }

    // Add entity-specific metadata for context
    if (!empty($metadata['entity_metadata'])) {
        $prompt .= "Entity-Specific Information:\n";
        foreach ($metadata['entity_metadata'] as $entityName => $entityMeta) {
            $prompt .= "- {$entityName}: {$entityMeta['description']}\n";

            // Add common properties
            if (!empty($entityMeta['common_properties'])) {
                $prompt .= "  Properties: ";
                $propDescriptions = [];
                foreach ($entityMeta['common_properties'] as $prop => $desc) {
                    $propDescriptions[] = "{$prop}";
                }
                $prompt .= implode(', ', array_slice($propDescriptions, 0, 8)) . "\n";
            }

            // Add relationship documentation
            if (!empty($entityMeta['relationships'])) {
                $prompt .= "  Relationships:\n";
                foreach ($entityMeta['relationships'] as $relType => $relInfo) {
                    $prompt .= "    - {$relType} → {$relInfo['target']}: {$relInfo['description']}\n";
                }
            }
        }
        $prompt .= "\n";
    }
}
```

### Change 2.2: Add formatScopesForPrompt() Method

**Location**: Add new private method at end of class (after line 527)

**New Method**:
```php
/**
 * Format detected scopes for LLM prompt
 *
 * Groups scopes by pattern type and formats with appropriate guidance.
 *
 * @param array $detectedScopes Detected scopes from entity metadata
 * @return string Formatted scope information for prompt
 */
private function formatScopesForPrompt(array $detectedScopes): string
{
    if (empty($detectedScopes)) {
        return '';
    }

    $output = "Detected Business Terms (Scopes):\n\n";

    // Group by pattern type
    $grouped = [
        'simple' => [],
        'relationship' => [],
        'complex' => [],
    ];

    foreach ($detectedScopes as $scopeName => $scopeInfo) {
        $patternType = $scopeInfo['pattern_type'] ?? 'simple';
        $grouped[$patternType][$scopeName] = $scopeInfo;
    }

    // Format simple scopes
    if (!empty($grouped['simple'])) {
        $output .= "SIMPLE PROPERTY FILTERS (use in WHERE clause):\n";
        foreach ($grouped['simple'] as $scopeName => $info) {
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → WHERE {$info['cypher_pattern']}\n\n";
        }
    }

    // Format relationship scopes
    if (!empty($grouped['relationship'])) {
        $output .= "RELATIONSHIP PATTERNS (MUST use complete MATCH pattern):\n";
        foreach ($grouped['relationship'] as $scopeName => $info) {
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → Use this EXACT pattern:\n\n";

            // Clean and indent the cypher pattern
            $cypherLines = explode("\n", trim($info['cypher_pattern']));
            foreach ($cypherLines as $line) {
                $output .= "  " . trim($line) . "\n";
            }

            $output .= "\n  CRITICAL: This requires relationship traversal.\n";
            $output .= "  You MUST use this complete MATCH pattern, not a simple property filter.\n";
            $output .= "  You can extend the WHERE clause or modify the RETURN clause as needed.\n\n";
        }
    }

    // Format complex scopes
    if (!empty($grouped['complex'])) {
        $output .= "COMPLEX PATTERNS (use template as-is):\n";
        foreach ($grouped['complex'] as $scopeName => $info) {
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → Template:\n\n";

            $templateLines = explode("\n", trim($info['cypher_template']));
            foreach ($templateLines as $line) {
                $output .= "  " . trim($line) . "\n";
            }
            $output .= "\n";

            if (!empty($info['modification_guidance'])) {
                $output .= "  Note: {$info['modification_guidance']}\n\n";
            }
        }
    }

    return $output;
}
```

### Change 2.3: Update Rules Section in buildPrompt()

**Location**: Lines 395-403 in `src/Services/QueryGenerator.php`

**Current Code**:
```php
// Add rules
$prompt .= "Rules:\n";
$prompt .= "- Use only labels/relationships from the schema\n";
$prompt .= "- When business terms (like 'volunteers', 'customers', 'pending') are used, apply the corresponding filters shown above\n";
$prompt .= "- Return ONLY the Cypher query (no explanations)\n";
$prompt .= "- Always include LIMIT to prevent large result sets\n";

if (!$allowWrite) {
    $prompt .= "- NO DELETE, DROP, CREATE, MERGE, SET, or other write operations\n";
}
```

**New Code**:
```php
// Add rules with relationship pattern guidance
$prompt .= "Rules:\n";
$prompt .= "1. Use only labels/relationships from the schema\n";
$prompt .= "2. When using RELATIONSHIP PATTERNS from detected scopes:\n";
$prompt .= "   - Use the EXACT MATCH pattern provided\n";
$prompt .= "   - These patterns define business concepts through graph traversal\n";
$prompt .= "   - You can extend the WHERE clause but NOT change the MATCH pattern\n";
$prompt .= "   - Always use DISTINCT when returning nodes from relationship traversals\n";
$prompt .= "3. When using SIMPLE FILTERS from detected scopes:\n";
$prompt .= "   - Apply as WHERE conditions on the entity node\n";
$prompt .= "   - Can be combined with other filters using AND/OR\n";
$prompt .= "4. When using COMPLEX PATTERNS:\n";
$prompt .= "   - Use the template as-is with minimal modifications\n";
$prompt .= "   - Follow the modification guidance provided\n";
$prompt .= "5. Return ONLY the Cypher query (no explanations)\n";
$prompt .= "6. Always include LIMIT to prevent large result sets\n";

if (!$allowWrite) {
    $prompt .= "7. NO DELETE, DROP, CREATE, MERGE, SET, or other write operations\n";
}
```

## 3. Test File Changes

Create a new test file for relationship pattern testing:

**File**: `tests/Unit/Services/RelationshipScopesTest.php`

```php
<?php

declare(strict_types=1);

namespace AiSystem\Tests\Unit\Services;

use AiSystem\Tests\TestCase;
use AiSystem\Services\ContextRetriever;
use AiSystem\Services\QueryGenerator;
use AiSystem\Contracts\VectorStoreInterface;
use AiSystem\Contracts\GraphStoreInterface;
use AiSystem\Contracts\EmbeddingProviderInterface;
use AiSystem\Contracts\LlmProviderInterface;
use Mockery;

/**
 * Tests for Relationship-Based Scopes Enhancement
 *
 * Verifies the system's ability to:
 * - Detect relationship pattern types
 * - Format relationship patterns for LLM
 * - Generate queries using relationship patterns
 * - Combine relationship and simple patterns
 */
class RelationshipScopesTest extends TestCase
{
    private $mockVectorStore;
    private $mockGraphStore;
    private $mockEmbeddingProvider;
    private $mockLlmProvider;
    private $contextRetriever;
    private $queryGenerator;
    private $testEntityConfigs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockGraphStore = Mockery::mock(GraphStoreInterface::class);
        $this->mockEmbeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);
        $this->mockLlmProvider = Mockery::mock(LlmProviderInterface::class);

        // Define test entity configurations with relationship patterns
        $this->testEntityConfigs = [
            'Person' => [
                'graph' => [
                    'label' => 'Person',
                    'properties' => ['id', 'name', 'status'],
                    'relationships' => [
                        ['type' => 'HAS_ROLE', 'target_label' => 'PersonTeam'],
                    ],
                ],
                'metadata' => [
                    'aliases' => ['person', 'people'],
                    'description' => 'Individuals in the system',
                    'scopes' => [
                        // Simple pattern
                        'active' => [
                            'pattern_type' => 'simple',
                            'description' => 'People with active status',
                            'filter' => ['status' => 'active'],
                            'cypher_pattern' => "p.status = 'active'",
                        ],
                        // Relationship pattern
                        'volunteers' => [
                            'pattern_type' => 'relationship',
                            'description' => 'People with volunteer role',
                            'relationship' => [
                                'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
                                'where' => "pt.role_type = 'volunteer'",
                                'return_distinct' => true,
                            ],
                            'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
CYPHER,
                        ],
                        // Complex pattern
                        'multi_team_members' => [
                            'pattern_type' => 'complex',
                            'description' => 'People on multiple teams',
                            'cypher_template' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(:PersonTeam)-[:ON_TEAM]->(t:Team)
WITH p, count(DISTINCT t) as team_count
WHERE team_count > 1
RETURN p
CYPHER,
                            'modification_guidance' => 'Adjust team_count threshold',
                        ],
                    ],
                    'common_properties' => [
                        'id' => 'Unique identifier',
                        'name' => 'Person name',
                        'status' => 'Status: active, inactive',
                    ],
                    'relationships' => [
                        'HAS_ROLE' => [
                            'description' => 'Person has role on team',
                            'target' => 'PersonTeam',
                        ],
                    ],
                ],
            ],
        ];

        $this->contextRetriever = new ContextRetriever(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider,
            $this->testEntityConfigs
        );

        $this->queryGenerator = new QueryGenerator(
            $this->mockLlmProvider,
            $this->mockGraphStore,
            []
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Pattern Type Detection Tests
    // =========================================================================

    public function test_detects_simple_pattern_type()
    {
        $metadata = $this->contextRetriever->getEntityMetadata('Show me active people');

        $this->assertArrayHasKey('active', $metadata['detected_scopes']);
        $this->assertEquals('simple', $metadata['detected_scopes']['active']['pattern_type']);
    }

    public function test_detects_relationship_pattern_type()
    {
        $metadata = $this->contextRetriever->getEntityMetadata('Show me volunteers');

        $this->assertArrayHasKey('volunteers', $metadata['detected_scopes']);
        $this->assertEquals('relationship', $metadata['detected_scopes']['volunteers']['pattern_type']);
    }

    public function test_detects_complex_pattern_type()
    {
        $metadata = $this->contextRetriever->getEntityMetadata('Show people on multiple teams');

        $this->assertArrayHasKey('multi_team_members', $metadata['detected_scopes']);
        $this->assertEquals('complex', $metadata['detected_scopes']['multi_team_members']['pattern_type']);
    }

    public function test_relationship_scope_includes_complete_pattern()
    {
        $metadata = $this->contextRetriever->getEntityMetadata('Show me volunteers');

        $scope = $metadata['detected_scopes']['volunteers'];
        $this->assertArrayHasKey('cypher_pattern', $scope);
        $this->assertStringContainsString('[:HAS_ROLE]->', $scope['cypher_pattern']);
        $this->assertStringContainsString('PersonTeam', $scope['cypher_pattern']);
        $this->assertStringContainsString('role_type', $scope['cypher_pattern']);
    }

    public function test_relationship_scope_includes_relationship_structure()
    {
        $metadata = $this->contextRetriever->getEntityMetadata('Show me volunteers');

        $scope = $metadata['detected_scopes']['volunteers'];
        $this->assertArrayHasKey('relationship', $scope);
        $this->assertIsArray($scope['relationship']);
        $this->assertArrayHasKey('pattern', $scope['relationship']);
        $this->assertArrayHasKey('where', $scope['relationship']);
    }

    public function test_complex_scope_includes_template()
    {
        $metadata = $this->contextRetriever->getEntityMetadata('Show people on multiple teams');

        $scope = $metadata['detected_scopes']['multi_team_members'];
        $this->assertArrayHasKey('cypher_template', $scope);
        $this->assertStringContainsString('WITH p, count', $scope['cypher_template']);
    }

    // =========================================================================
    // Scope Formatting Tests
    // =========================================================================

    public function test_formats_simple_scopes_correctly()
    {
        $metadata = $this->contextRetriever->getEntityMetadata('Show me active people');
        $formatted = $this->contextRetriever->formatScopesForLLM($metadata['detected_scopes']);

        $this->assertStringContainsString('SIMPLE PROPERTY FILTERS', $formatted);
        $this->assertStringContainsString("'active'", $formatted);
        $this->assertStringContainsString("WHERE p.status = 'active'", $formatted);
    }

    public function test_formats_relationship_scopes_correctly()
    {
        $metadata = $this->contextRetriever->getEntityMetadata('Show me volunteers');
        $formatted = $this->contextRetriever->formatScopesForLLM($metadata['detected_scopes']);

        $this->assertStringContainsString('RELATIONSHIP PATTERNS', $formatted);
        $this->assertStringContainsString("'volunteers'", $formatted);
        $this->assertStringContainsString('MATCH (p:Person)-[:HAS_ROLE]->', $formatted);
        $this->assertStringContainsString('CRITICAL', $formatted);
        $this->assertStringContainsString('relationship traversal', $formatted);
    }

    public function test_formats_complex_scopes_correctly()
    {
        $metadata = $this->contextRetriever->getEntityMetadata('Show people on multiple teams');
        $formatted = $this->contextRetriever->formatScopesForLLM($metadata['detected_scopes']);

        $this->assertStringContainsString('COMPLEX PATTERNS', $formatted);
        $this->assertStringContainsString("'multi_team_members'", $formatted);
        $this->assertStringContainsString('WITH p, count', $formatted);
        $this->assertStringContainsString('modification_guidance', $formatted);
    }

    public function test_groups_mixed_pattern_types()
    {
        $metadata = $this->contextRetriever->getEntityMetadata('Show active volunteers');
        $formatted = $this->contextRetriever->formatScopesForLLM($metadata['detected_scopes']);

        // Should contain both simple and relationship sections
        $this->assertStringContainsString('SIMPLE PROPERTY FILTERS', $formatted);
        $this->assertStringContainsString('RELATIONSHIP PATTERNS', $formatted);
    }

    // =========================================================================
    // Integration Tests with Context Retrieval
    // =========================================================================

    public function test_context_includes_pattern_type_in_metadata()
    {
        $question = 'How many volunteers do we have?';

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
            ->andReturn(['labels' => ['Person'], 'relationshipTypes' => ['HAS_ROLE'], 'propertyKeys' => []]);

        $context = $this->contextRetriever->retrieveContext($question);

        $this->assertArrayHasKey('volunteers', $context['entity_metadata']['detected_scopes']);
        $this->assertEquals('relationship',
            $context['entity_metadata']['detected_scopes']['volunteers']['pattern_type']);
    }

    // =========================================================================
    // Real-World Scenario Tests
    // =========================================================================

    public function test_volunteer_questions_detect_relationship_pattern()
    {
        $questions = [
            'How many volunteers do we have?',
            'Show me all volunteers',
            'List volunteers on teams',
            'Who are our volunteers?',
        ];

        foreach ($questions as $question) {
            $metadata = $this->contextRetriever->getEntityMetadata($question);

            $this->assertArrayHasKey('volunteers', $metadata['detected_scopes'],
                "Failed to detect volunteers scope in: {$question}");
            $this->assertEquals('relationship',
                $metadata['detected_scopes']['volunteers']['pattern_type'],
                "Wrong pattern type for: {$question}");
        }
    }

    public function test_combined_simple_and_relationship_scopes()
    {
        $question = 'Show me active volunteers';
        $metadata = $this->contextRetriever->getEntityMetadata($question);

        // Should detect both scopes
        $this->assertArrayHasKey('active', $metadata['detected_scopes']);
        $this->assertArrayHasKey('volunteers', $metadata['detected_scopes']);

        // Should have correct pattern types
        $this->assertEquals('simple', $metadata['detected_scopes']['active']['pattern_type']);
        $this->assertEquals('relationship', $metadata['detected_scopes']['volunteers']['pattern_type']);
    }

    public function test_backward_compatibility_with_undefined_pattern_type()
    {
        // Create entity config without pattern_type
        $legacyConfigs = [
            'Person' => [
                'graph' => ['label' => 'Person', 'properties' => ['id', 'status']],
                'metadata' => [
                    'aliases' => ['person'],
                    'description' => 'People',
                    'scopes' => [
                        'active' => [
                            // No pattern_type specified
                            'description' => 'Active people',
                            'filter' => ['status' => 'active'],
                            'cypher_pattern' => "status = 'active'",
                        ],
                    ],
                ],
            ],
        ];

        $retriever = new ContextRetriever(
            $this->mockVectorStore,
            $this->mockGraphStore,
            $this->mockEmbeddingProvider,
            $legacyConfigs
        );

        $metadata = $retriever->getEntityMetadata('Show active people');

        // Should default to 'simple'
        $this->assertEquals('simple', $metadata['detected_scopes']['active']['pattern_type']);
    }
}
```

## 4. Additional Test Cases to Add to EntityMetadataTest.php

Add these test cases to the existing `EntityMetadataTest.php` file:

```php
/**
 * Test that pattern_type defaults to 'simple' for backward compatibility
 */
public function test_pattern_type_defaults_to_simple()
{
    $metadata = $this->service->getEntityMetadata('Show me all volunteers');

    $this->assertArrayHasKey('volunteers', $metadata['detected_scopes']);

    // Should have pattern_type
    $this->assertArrayHasKey('pattern_type', $metadata['detected_scopes']['volunteers']);

    // In test config, volunteers is simple pattern, so should be 'simple'
    // (Update if test config changes to relationship pattern)
    $this->assertEquals('simple', $metadata['detected_scopes']['volunteers']['pattern_type']);
}

/**
 * Test that scope includes all necessary fields for relationship patterns
 */
public function test_relationship_scope_includes_all_fields()
{
    // This test would need entity config with relationship pattern
    // Add after updating test configs with relationship patterns

    $requiredFields = [
        'entity',
        'scope',
        'description',
        'pattern_type',
        'cypher_pattern',
        'relationship',
    ];

    // Verify all fields are present
    // $metadata = $this->service->getEntityMetadata('Show volunteers');
    // $scope = $metadata['detected_scopes']['volunteers'];
    // foreach ($requiredFields as $field) {
    //     $this->assertArrayHasKey($field, $scope);
    // }
}
```

## 5. Configuration File Updates

Update `config/entities.php` to use the new pattern types. See `config/entities-with-relationship-patterns.example.php` for complete examples.

**Minimal Migration Example**:

```php
// Before
'volunteers' => [
    'description' => 'People who volunteer',
    'filter' => ['type' => 'volunteer'],
    'cypher_pattern' => "type = 'volunteer'",
],

// After (relationship pattern)
'volunteers' => [
    'pattern_type' => 'relationship',
    'description' => 'People with volunteer role on teams',
    'relationship' => [
        'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
        'where' => "pt.role_type = 'volunteer'",
        'return_distinct' => true,
    ],
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
CYPHER,
],
```

## 6. Validation and Error Handling

Add optional validation method to ContextRetriever:

```php
/**
 * Validate scope configuration
 *
 * @param array $scopeConfig Scope configuration to validate
 * @param string $scopeName Name of the scope
 * @param string $entityName Name of the entity
 * @throws \InvalidArgumentException If configuration is invalid
 */
private function validateScopeConfiguration(array $scopeConfig, string $scopeName, string $entityName): void
{
    $patternType = $scopeConfig['pattern_type'] ?? 'simple';

    switch ($patternType) {
        case 'simple':
            if (empty($scopeConfig['cypher_pattern'])) {
                throw new \InvalidArgumentException(
                    "{$entityName}.{$scopeName}: Simple pattern requires cypher_pattern"
                );
            }
            break;

        case 'relationship':
            if (empty($scopeConfig['cypher_pattern']) && empty($scopeConfig['relationship'])) {
                throw new \InvalidArgumentException(
                    "{$entityName}.{$scopeName}: Relationship pattern requires cypher_pattern or relationship config"
                );
            }
            break;

        case 'complex':
            if (empty($scopeConfig['cypher_template'])) {
                throw new \InvalidArgumentException(
                    "{$entityName}.{$scopeName}: Complex pattern requires cypher_template"
                );
            }
            break;

        default:
            throw new \InvalidArgumentException(
                "{$entityName}.{$scopeName}: Unknown pattern_type '{$patternType}'. Must be: simple, relationship, or complex"
            );
    }
}
```

Call this in `getEntityMetadata()` when processing scopes:

```php
// In getEntityMetadata(), after line 380
try {
    $this->validateScopeConfiguration($scopeConfig, $scopeName, $entityName);
} catch (\InvalidArgumentException $e) {
    // Log error but don't break detection
    error_log("Invalid scope configuration: " . $e->getMessage());
    continue;
}
```

## 7. Testing Checklist

Before considering implementation complete:

- [ ] Simple pattern detection still works (backward compatibility)
- [ ] Relationship pattern detection identifies pattern_type correctly
- [ ] Complex pattern detection identifies pattern_type correctly
- [ ] formatScopesForLLM() generates correct output for all types
- [ ] buildPrompt() includes formatted scopes correctly
- [ ] Rules section emphasizes relationship pattern requirements
- [ ] All existing tests still pass
- [ ] New relationship pattern tests pass
- [ ] Manual testing with real LLM confirms correct query generation
- [ ] Documentation is complete and clear

## 8. Deployment Steps

1. **Backup current config**: `cp config/entities.php config/entities.backup.php`
2. **Apply code changes**: Update ContextRetriever.php and QueryGenerator.php
3. **Update tests**: Add RelationshipScopesTest.php
4. **Run tests**: `php vendor/bin/phpunit tests/Unit/Services/RelationshipScopesTest.php`
5. **Update entity configs**: Migrate relationship-based scopes
6. **Test with real queries**: Verify LLM generates correct Cypher
7. **Monitor and adjust**: Review logs for any issues

## Summary

This implementation enhances the Entity Metadata System to support three pattern types:

1. **Simple**: Property filters (existing behavior)
2. **Relationship**: Graph traversals (new)
3. **Complex**: Aggregations and calculations (new)

The changes maintain full backward compatibility while providing powerful new capabilities for modeling real-world business concepts that require relationship traversal.
