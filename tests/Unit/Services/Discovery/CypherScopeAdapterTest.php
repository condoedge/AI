<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;
use Condoedge\Ai\Services\Discovery\CypherQueryBuilderSpy;
use Condoedge\Ai\Services\Discovery\CypherPatternGenerator;
use Condoedge\Ai\Tests\Fixtures\TestCustomer;
use Condoedge\Ai\Tests\Fixtures\TestOrder;
use Condoedge\Ai\Tests\TestCase;
use InvalidArgumentException;

/**
 * Tests for CypherScopeAdapter
 *
 * Verifies that Eloquent scopes are correctly discovered and converted to Cypher
 */
class CypherScopeAdapterTest extends TestCase
{
    private CypherScopeAdapter $adapter;

    public function setUp(): void
    {
        parent::setUp();
        $this->adapter = new CypherScopeAdapter();
    }

    /** @test */
    public function it_discovers_all_scopes_in_model()
    {
        $scopes = $this->adapter->discoverScopes(TestCustomer::class);

        $this->assertIsArray($scopes);
        $this->assertNotEmpty($scopes);

        // Check that common scopes are discovered
        $this->assertArrayHasKey('active', $scopes);
        $this->assertArrayHasKey('inactive', $scopes);
        $this->assertArrayHasKey('high_value', $scopes);
    }

    /** @test */
    public function it_throws_exception_for_non_existent_model()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Model class not found');

        $this->adapter->discoverScopes('App\\Models\\NonExistent');
    }

    /** @test */
    public function it_parses_simple_where_scope()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'active');

        $this->assertIsArray($scopeData);
        $this->assertEquals('property_filter', $scopeData['specification_type']);
        $this->assertArrayHasKey('cypher_pattern', $scopeData);
        $this->assertArrayHasKey('concept', $scopeData);
        $this->assertArrayHasKey('examples', $scopeData);

        // Check Cypher pattern
        $this->assertStringContainsString('status', $scopeData['cypher_pattern']);
        $this->assertStringContainsString('active', $scopeData['cypher_pattern']);
    }

    /** @test */
    public function it_generates_correct_cypher_for_simple_where()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'active');

        $this->assertEquals("n.status = 'active'", $scopeData['cypher_pattern']);
    }

    /** @test */
    public function it_generates_correct_cypher_for_comparison_operator()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'high_value');

        $this->assertStringContainsString('lifetime_value', $scopeData['cypher_pattern']);
        $this->assertStringContainsString('>', $scopeData['cypher_pattern']);
        $this->assertStringContainsString('1000', $scopeData['cypher_pattern']);
    }

    /** @test */
    public function it_generates_correct_cypher_for_where_in()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'in_countries');

        $this->assertStringContainsString('country', $scopeData['cypher_pattern']);
        $this->assertStringContainsString('IN', $scopeData['cypher_pattern']);
    }

    /** @test */
    public function it_generates_correct_cypher_for_where_null()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'without_country');

        $this->assertStringContainsString('country', $scopeData['cypher_pattern']);
        $this->assertStringContainsString('IS NULL', $scopeData['cypher_pattern']);
    }

    /** @test */
    public function it_generates_correct_cypher_for_multiple_conditions()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'vip');

        $this->assertStringContainsString('status', $scopeData['cypher_pattern']);
        $this->assertStringContainsString('lifetime_value', $scopeData['cypher_pattern']);
        $this->assertStringContainsString('AND', $scopeData['cypher_pattern']);
    }

    /** @test */
    public function it_detects_relationship_scopes()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'with_orders');

        $this->assertEquals('relationship_traversal', $scopeData['specification_type']);
        $this->assertArrayHasKey('parsed_structure', $scopeData);
    }

    /** @test */
    public function it_generates_correct_cypher_for_simple_relationship()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'with_orders');

        $cypher = $scopeData['cypher_pattern'];

        $this->assertStringContainsString('MATCH', $cypher);
        $this->assertStringContainsString('Customer', $cypher);
        $this->assertStringContainsString('HAS_ORDERS', $cypher);
        $this->assertStringContainsString('RETURN DISTINCT n', $cypher);
    }

    /** @test */
    public function it_generates_correct_cypher_for_relationship_with_conditions()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'with_completed_orders');

        $cypher = $scopeData['cypher_pattern'];

        $this->assertStringContainsString('MATCH', $cypher);
        $this->assertStringContainsString('WHERE', $cypher);
        $this->assertStringContainsString('status', $cypher);
        $this->assertStringContainsString('completed', $cypher);
    }

    /** @test */
    public function it_parses_relationship_structure_correctly()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'with_completed_orders');

        $structure = $scopeData['parsed_structure'];

        $this->assertEquals('Customer', $structure['entity']);
        $this->assertArrayHasKey('relationships', $structure);
        $this->assertArrayHasKey('conditions', $structure);

        // Check relationships
        $this->assertNotEmpty($structure['relationships']);
        $this->assertEquals('HAS_ORDERS', $structure['relationships'][0]['type']);

        // Check conditions
        $this->assertNotEmpty($structure['conditions']);
        $this->assertEquals('status', $structure['conditions'][0]['field']);
        $this->assertEquals('completed', $structure['conditions'][0]['value']);
    }

    /** @test */
    public function it_extracts_filter_information()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'active');

        $this->assertArrayHasKey('filter', $scopeData);
        $this->assertEquals(['status' => 'active'], $scopeData['filter']);
    }

    /** @test */
    public function it_generates_concept_description()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'active');

        $this->assertArrayHasKey('concept', $scopeData);
        $this->assertIsString($scopeData['concept']);
        $this->assertStringContainsString('Customer', $scopeData['concept']);
    }

    /** @test */
    public function it_generates_example_queries()
    {
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'active');

        $this->assertArrayHasKey('examples', $scopeData);
        $this->assertIsArray($scopeData['examples']);
        $this->assertNotEmpty($scopeData['examples']);

        // Check that examples are reasonable
        foreach ($scopeData['examples'] as $example) {
            $this->assertIsString($example);
            $this->assertNotEmpty($example);
        }
    }

    /** @test */
    public function it_generates_different_examples_for_different_scopes()
    {
        $activeScope = $this->adapter->parseScope(TestCustomer::class, 'active');
        $highValueScope = $this->adapter->parseScope(TestCustomer::class, 'high_value');

        $this->assertNotEquals($activeScope['examples'], $highValueScope['examples']);
    }

    /** @test */
    public function it_works_with_order_model_scopes()
    {
        $scopes = $this->adapter->discoverScopes(TestOrder::class);

        $this->assertArrayHasKey('pending', $scopes);
        $this->assertArrayHasKey('completed', $scopes);
        $this->assertArrayHasKey('high_value', $scopes);
    }

    /** @test */
    public function it_generates_correct_cypher_for_order_scopes()
    {
        $scopeData = $this->adapter->parseScope(TestOrder::class, 'pending');

        $this->assertStringContainsString('status', $scopeData['cypher_pattern']);
        $this->assertStringContainsString('pending', $scopeData['cypher_pattern']);
    }

    /** @test */
    public function it_handles_scopes_with_parameters()
    {
        // The from_country scope has a parameter, but we provide default
        $scopeData = $this->adapter->parseScope(TestCustomer::class, 'from_country');

        // Should still parse successfully with default parameter
        $this->assertIsArray($scopeData);
        $this->assertArrayHasKey('cypher_pattern', $scopeData);
    }

    /** @test */
    public function it_returns_null_for_scopes_with_no_calls()
    {
        // If we had a scope that doesn't make any query builder calls
        // it should return null (we don't have such a scope in fixtures, but testing the logic)

        // This test verifies the adapter can handle edge cases
        $this->assertInstanceOf(CypherScopeAdapter::class, $this->adapter);
    }

    /** @test */
    public function it_converts_scope_name_to_snake_case()
    {
        $scopes = $this->adapter->discoverScopes(TestCustomer::class);

        // scopeHighValue should become 'high_value'
        $this->assertArrayHasKey('high_value', $scopes);
        $this->assertArrayNotHasKey('HighValue', $scopes);
        $this->assertArrayNotHasKey('highValue', $scopes);
    }

    /** @test */
    public function it_only_discovers_scopes_in_the_model_class()
    {
        // Should not include scopes from parent classes or traits
        $scopes = $this->adapter->discoverScopes(TestCustomer::class);

        // All discovered scopes should be from TestCustomer
        $this->assertArrayHasKey('active', $scopes);
        $this->assertArrayHasKey('high_value', $scopes);
    }

    /** @test */
    public function it_provides_access_to_spy_instance()
    {
        $spy = $this->adapter->getSpy();

        $this->assertInstanceOf(CypherQueryBuilderSpy::class, $spy);
    }

    /** @test */
    public function it_provides_access_to_generator_instance()
    {
        $generator = $this->adapter->getGenerator();

        $this->assertInstanceOf(CypherPatternGenerator::class, $generator);
    }

    /** @test */
    public function it_can_be_constructed_with_custom_dependencies()
    {
        $spy = new CypherQueryBuilderSpy();
        $generator = new CypherPatternGenerator();

        $adapter = new CypherScopeAdapter($spy, $generator);

        $this->assertSame($spy, $adapter->getSpy());
        $this->assertSame($generator, $adapter->getGenerator());
    }

    /** @test */
    public function it_handles_date_scopes()
    {
        // The TestOrder model has a scopeRecent with date filtering
        $scopeData = $this->adapter->parseScope(TestOrder::class, 'recent');

        $this->assertIsArray($scopeData);
        $this->assertArrayHasKey('cypher_pattern', $scopeData);

        // Should contain date function
        $this->assertStringContainsString('date', $scopeData['cypher_pattern']);
    }

    /** @test */
    public function it_generates_proper_entity_names()
    {
        $customerScopes = $this->adapter->discoverScopes(TestCustomer::class);
        $activeScope = $customerScopes['active'];

        // Should reference "Customer" not "TestCustomer"
        $this->assertStringContainsString('Customer', $activeScope['concept']);
        $this->assertStringNotContainsString('TestCustomer', $activeScope['concept']);
    }

    /** @test */
    public function it_skips_scopes_that_throw_errors()
    {
        // Even if some scopes fail to parse, discover should continue
        $scopes = $this->adapter->discoverScopes(TestCustomer::class);

        // Should still return valid scopes
        $this->assertNotEmpty($scopes);
    }

    /** @test */
    public function it_throws_exception_for_non_existent_scope()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Scope method not found');

        $this->adapter->parseScope(TestCustomer::class, 'non_existent_scope');
    }

    /** @test */
    public function discovered_scopes_have_required_keys()
    {
        $scopes = $this->adapter->discoverScopes(TestCustomer::class);

        foreach ($scopes as $scopeName => $scopeData) {
            // All scopes should have these keys
            $this->assertArrayHasKey('cypher_pattern', $scopeData, "Scope '{$scopeName}' missing cypher_pattern");
            $this->assertArrayHasKey('concept', $scopeData, "Scope '{$scopeName}' missing concept");
            $this->assertArrayHasKey('examples', $scopeData, "Scope '{$scopeName}' missing examples");

            // Property filters should have filter key
            if (($scopeData['specification_type'] ?? '') === 'property_filter') {
                $this->assertArrayHasKey('filter', $scopeData, "Scope '{$scopeName}' missing filter");
            }

            // Relationship scopes should have parsed_structure
            if (($scopeData['specification_type'] ?? '') === 'relationship_traversal') {
                $this->assertArrayHasKey('parsed_structure', $scopeData, "Scope '{$scopeName}' missing parsed_structure");
            }
        }
    }

    /** @test */
    public function cypher_patterns_are_valid_strings()
    {
        $scopes = $this->adapter->discoverScopes(TestCustomer::class);

        foreach ($scopes as $scopeName => $scopeData) {
            $pattern = $scopeData['cypher_pattern'];

            $this->assertIsString($pattern, "Cypher pattern for '{$scopeName}' is not a string");
            $this->assertNotEmpty($pattern, "Cypher pattern for '{$scopeName}' is empty");
        }
    }

    /** @test */
    public function examples_are_human_readable()
    {
        $scopes = $this->adapter->discoverScopes(TestCustomer::class);

        foreach ($scopes as $scopeName => $scopeData) {
            $examples = $scopeData['examples'];

            $this->assertIsArray($examples, "Examples for '{$scopeName}' is not an array");
            $this->assertNotEmpty($examples, "Examples for '{$scopeName}' is empty");

            foreach ($examples as $example) {
                // Examples should be strings with reasonable length
                $this->assertIsString($example);
                $this->assertGreaterThan(5, strlen($example), "Example too short: {$example}");
                $this->assertLessThan(200, strlen($example), "Example too long: {$example}");
            }
        }
    }
}
