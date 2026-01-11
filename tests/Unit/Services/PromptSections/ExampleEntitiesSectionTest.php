<?php

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\Services\PromptSections\ExampleEntitiesSection;
use Condoedge\Ai\Tests\TestCase;

class ExampleEntitiesSectionTest extends TestCase
{
    private ExampleEntitiesSection $section;

    public function setUp(): void
    {
        parent::setUp();
        $this->section = new ExampleEntitiesSection();
    }

    /** @test */
    public function it_has_correct_name_and_priority(): void
    {
        $this->assertEquals('example_entities', $this->section->getName());
        $this->assertEquals(40, $this->section->getPriority());
    }

    /** @test */
    public function it_excludes_section_when_no_relevant_entities(): void
    {
        $result = $this->section->shouldInclude('How many orders?', [], []);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_includes_section_when_relevant_entities_exist(): void
    {
        $context = [
            'relevant_entities' => [
                'Customer' => [['name' => 'John']],
            ],
        ];

        $result = $this->section->shouldInclude('How many orders?', $context, []);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_formats_entities_correctly(): void
    {
        $context = [
            'relevant_entities' => [
                'Customer' => [
                    ['name' => 'John', 'email' => 'john@example.com'],
                    ['name' => 'Jane', 'email' => 'jane@example.com'],
                ],
            ],
        ];

        $result = $this->section->format('Show customers', $context, []);

        $this->assertStringContainsString('EXAMPLE ENTITIES', $result);
        $this->assertStringContainsString('Customer examples:', $result);
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('Jane', $result);
    }

    /** @test */
    public function it_only_shows_semantically_selected_entities(): void
    {
        $context = [
            'relevant_entities' => [
                'Customer' => [['name' => 'John'], ['name' => 'Jane']],
                'Order' => [['id' => 1], ['id' => 2]],
                'Product' => [['sku' => 'ABC']],
            ],
            'selection_info' => [
                'selected_entities' => ['Customer', 'Order'],
                'method' => 'semantic',
            ],
        ];

        $result = $this->section->format('Show customers with orders', $context, []);

        $this->assertStringContainsString('Customer', $result);
        $this->assertStringContainsString('Order', $result);
        $this->assertStringNotContainsString('Product', $result);
    }

    /** @test */
    public function it_shows_all_entities_when_no_selection_info(): void
    {
        $context = [
            'relevant_entities' => [
                'Customer' => [['name' => 'John']],
                'Order' => [['id' => 1]],
                'Product' => [['sku' => 'ABC']],
            ],
        ];

        $result = $this->section->format('Show everything', $context, []);

        $this->assertStringContainsString('Customer', $result);
        $this->assertStringContainsString('Order', $result);
        $this->assertStringContainsString('Product', $result);
    }

    /** @test */
    public function it_shows_all_entities_when_selection_info_has_empty_selected_entities(): void
    {
        $context = [
            'relevant_entities' => [
                'Customer' => [['name' => 'John']],
                'Order' => [['id' => 1]],
            ],
            'selection_info' => [
                'selected_entities' => [],
                'method' => 'semantic',
            ],
        ];

        $result = $this->section->format('Show everything', $context, []);

        $this->assertStringContainsString('Customer', $result);
        $this->assertStringContainsString('Order', $result);
    }

    /** @test */
    public function it_returns_empty_string_when_no_entities(): void
    {
        $result = $this->section->format('Show customers', [], []);

        $this->assertEquals('', $result);
    }

    /** @test */
    public function it_formats_different_value_types_correctly(): void
    {
        $context = [
            'relevant_entities' => [
                'TestEntity' => [
                    [
                        'string_value' => 'test',
                        'int_value' => 42,
                        'float_value' => 3.14,
                        'bool_true' => true,
                        'bool_false' => false,
                        'null_value' => null,
                        'date_value' => '2024-01-15',
                    ],
                ],
            ],
        ];

        $result = $this->section->format('Test', $context, []);

        $this->assertStringContainsString("'test' (string)", $result);
        $this->assertStringContainsString('42 (integer)', $result);
        $this->assertStringContainsString('3.14 (float)', $result);
        $this->assertStringContainsString('true (boolean)', $result);
        $this->assertStringContainsString('false (boolean)', $result);
        $this->assertStringContainsString('null', $result);
        $this->assertStringContainsString('(string date', $result);
    }
}
