<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\PatternLibrary;

/**
 * Unit tests for PatternLibrary service
 *
 * Tests pattern loading, validation, instantiation, and semantic description generation
 */
class PatternLibraryTest extends TestCase
{
    private array $testPatterns;

    public function setUp(): void
    {
        parent::setUp();

        // Define test patterns
        $this->testPatterns = [
            'property_filter' => [
                'description' => 'Filter entities by property value',
                'parameters' => [
                    'entity' => 'Entity label',
                    'property' => 'Property name',
                    'operator' => 'Comparison operator',
                    'value' => 'Value to compare',
                ],
                'semantic_template' => 'Find {entity} where {property} {operator} {value}',
            ],
            'relationship_traversal' => [
                'description' => 'Find entities through relationships',
                'parameters' => [
                    'start_entity' => 'Starting entity',
                    'path' => 'Relationship path',
                    'filter_entity' => 'Entity to filter',
                    'filter_property' => 'Property to filter',
                    'filter_value' => 'Filter value',
                ],
                'semantic_template' => 'Find {start_entity} connected through {path} where {filter_entity}.{filter_property} equals {filter_value}',
            ],
        ];
    }

    /** @test */
    public function it_loads_patterns_from_constructor()
    {
        $library = new PatternLibrary($this->testPatterns);

        $this->assertEquals($this->testPatterns, $library->getAllPatterns());
    }

    /** @test */
    public function it_gets_pattern_by_name()
    {
        $library = new PatternLibrary($this->testPatterns);

        $pattern = $library->getPattern('property_filter');

        $this->assertNotNull($pattern);
        $this->assertEquals('Filter entities by property value', $pattern['description']);
        $this->assertArrayHasKey('parameters', $pattern);
        $this->assertArrayHasKey('semantic_template', $pattern);
    }

    /** @test */
    public function it_returns_null_for_unknown_pattern()
    {
        $library = new PatternLibrary($this->testPatterns);

        $pattern = $library->getPattern('unknown_pattern');

        $this->assertNull($pattern);
    }

    /** @test */
    public function it_checks_if_pattern_exists()
    {
        $library = new PatternLibrary($this->testPatterns);

        $this->assertTrue($library->hasPattern('property_filter'));
        $this->assertTrue($library->hasPattern('relationship_traversal'));
        $this->assertFalse($library->hasPattern('nonexistent'));
    }

    /** @test */
    public function it_gets_all_pattern_names()
    {
        $library = new PatternLibrary($this->testPatterns);

        $names = $library->getPatternNames();

        $this->assertEquals(['property_filter', 'relationship_traversal'], $names);
    }

    /** @test */
    public function it_instantiates_pattern_with_valid_parameters()
    {
        $library = new PatternLibrary($this->testPatterns);

        $result = $library->instantiatePattern('property_filter', [
            'entity' => 'Person',
            'property' => 'status',
            'operator' => 'equals',
            'value' => 'active',
        ]);

        $this->assertEquals('property_filter', $result['pattern_name']);
        $this->assertArrayHasKey('pattern_def', $result);
        $this->assertArrayHasKey('parameters', $result);
        $this->assertArrayHasKey('semantic_description', $result);
    }

    /** @test */
    public function it_builds_semantic_description()
    {
        $library = new PatternLibrary($this->testPatterns);

        $result = $library->instantiatePattern('property_filter', [
            'entity' => 'Person',
            'property' => 'status',
            'operator' => 'equals',
            'value' => 'active',
        ]);

        $this->assertEquals(
            'Find Person where status equals active',
            $result['semantic_description']
        );
    }

    /** @test */
    public function it_throws_exception_for_unknown_pattern()
    {
        $library = new PatternLibrary($this->testPatterns);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown pattern: nonexistent');

        $library->instantiatePattern('nonexistent', []);
    }

    /** @test */
    public function it_throws_exception_for_missing_required_parameter()
    {
        $library = new PatternLibrary($this->testPatterns);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required parameter 'property'");

        $library->instantiatePattern('property_filter', [
            'entity' => 'Person',
            // Missing: property, operator, value
        ]);
    }

    /** @test */
    public function it_validates_all_required_parameters()
    {
        $library = new PatternLibrary($this->testPatterns);

        // This should succeed - all params provided
        $result = $library->instantiatePattern('property_filter', [
            'entity' => 'Person',
            'property' => 'age',
            'operator' => 'greater_than',
            'value' => '30',
        ]);

        $this->assertNotNull($result);
    }

    /** @test */
    public function it_handles_complex_pattern_parameters()
    {
        $library = new PatternLibrary($this->testPatterns);

        $result = $library->instantiatePattern('relationship_traversal', [
            'start_entity' => 'Person',
            'path' => [
                ['relationship' => 'HAS_ROLE', 'target_entity' => 'PersonTeam'],
            ],
            'filter_entity' => 'PersonTeam',
            'filter_property' => 'role_type',
            'filter_value' => 'volunteer',
        ]);

        $this->assertArrayHasKey('semantic_description', $result);
        $this->assertStringContainsString('Person', $result['semantic_description']);
        $this->assertStringContainsString('PersonTeam', $result['semantic_description']);
    }

    /** @test */
    public function it_handles_empty_pattern_library()
    {
        $library = new PatternLibrary([]);

        $this->assertEmpty($library->getAllPatterns());
        $this->assertEmpty($library->getPatternNames());
        $this->assertNull($library->getPattern('any_pattern'));
        $this->assertFalse($library->hasPattern('any_pattern'));
    }

    /** @test */
    public function it_preserves_parameter_values_in_instantiation()
    {
        $library = new PatternLibrary($this->testPatterns);

        $params = [
            'entity' => 'Order',
            'property' => 'status',
            'operator' => 'not_equals',
            'value' => 'cancelled',
        ];

        $result = $library->instantiatePattern('property_filter', $params);

        $this->assertEquals($params, $result['parameters']);
    }

    /** @test */
    public function it_includes_pattern_definition_in_result()
    {
        $library = new PatternLibrary($this->testPatterns);

        $result = $library->instantiatePattern('property_filter', [
            'entity' => 'Person',
            'property' => 'status',
            'operator' => 'equals',
            'value' => 'active',
        ]);

        $this->assertEquals(
            $this->testPatterns['property_filter'],
            $result['pattern_def']
        );
    }

    /** @test */
    public function it_handles_pattern_with_no_semantic_template()
    {
        $patterns = [
            'custom_pattern' => [
                'description' => 'Custom pattern without template',
                'parameters' => ['param1' => 'Description'],
                // No semantic_template
            ],
        ];

        $library = new PatternLibrary($patterns);

        $result = $library->instantiatePattern('custom_pattern', [
            'param1' => 'value1',
        ]);

        $this->assertEquals('', $result['semantic_description']);
    }

    /** @test */
    public function it_replaces_all_placeholders_in_template()
    {
        $library = new PatternLibrary($this->testPatterns);

        $result = $library->instantiatePattern('relationship_traversal', [
            'start_entity' => 'Customer',
            'path' => 'PLACED → Order',
            'filter_entity' => 'Order',
            'filter_property' => 'total',
            'filter_value' => '1000',
        ]);

        $description = $result['semantic_description'];

        $this->assertStringContainsString('Customer', $description);
        $this->assertStringContainsString('Order', $description);
        $this->assertStringContainsString('total', $description);
        $this->assertStringContainsString('1000', $description);
    }

    /** @test */
    public function it_loads_from_config_when_no_patterns_provided()
    {
        // This will attempt to load from config file
        $library = new PatternLibrary();

        // Should either load patterns or return empty array (depending on config availability)
        $patterns = $library->getAllPatterns();

        $this->assertIsArray($patterns);
    }

    /** @test */
    public function it_handles_numeric_parameter_values()
    {
        $library = new PatternLibrary($this->testPatterns);

        $result = $library->instantiatePattern('property_filter', [
            'entity' => 'Product',
            'property' => 'price',
            'operator' => 'greater_than',
            'value' => 99.99,
        ]);

        $this->assertStringContainsString('99.99', $result['semantic_description']);
    }

    /** @test */
    public function it_handles_array_parameter_values()
    {
        $patterns = [
            'test_pattern' => [
                'description' => 'Test pattern with array param',
                'parameters' => [
                    'items' => 'Array of items',
                ],
                'semantic_template' => 'Process {items}',
            ],
        ];

        $library = new PatternLibrary($patterns);

        $result = $library->instantiatePattern('test_pattern', [
            'items' => ['item1', 'item2', 'item3'],
        ]);

        // Should handle array conversion
        $this->assertNotEmpty($result['semantic_description']);
    }

    /** @test */
    public function it_maintains_pattern_immutability()
    {
        $library = new PatternLibrary($this->testPatterns);

        $pattern1 = $library->getPattern('property_filter');
        $pattern2 = $library->getPattern('property_filter');

        $this->assertEquals($pattern1, $pattern2);

        // Modifying returned pattern shouldn't affect original
        $pattern1['modified'] = true;

        $pattern3 = $library->getPattern('property_filter');
        $this->assertArrayNotHasKey('modified', $pattern3);
    }
}
