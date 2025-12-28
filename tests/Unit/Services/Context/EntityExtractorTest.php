<?php

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Tests\TestCase;

class EntityExtractorTest extends TestCase
{
    private EntityExtractor $extractor;

    public function setUp(): void
    {
        parent::setUp();
        $this->extractor = new EntityExtractor();
    }

    /** @test */
    public function it_extracts_entity_from_count_question(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'How many customers do we have?',
            ['labels' => ['Customer', 'Order', 'Product']]
        );

        $this->assertEquals('Customer', $result['focused_entity']);
        $this->assertEquals('count', $result['query_type']);
    }

    /** @test */
    public function it_extracts_entity_from_list_question(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'Show me all orders',
            ['labels' => ['Customer', 'Order', 'Product']]
        );

        $this->assertEquals('Order', $result['focused_entity']);
        $this->assertEquals('list', $result['query_type']);
    }

    /** @test */
    public function it_extracts_multiple_entities(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'Show customers with their orders',
            ['labels' => ['Customer', 'Order', 'Product']]
        );

        $this->assertContains('Customer', $result['mentioned_entities']);
        $this->assertContains('Order', $result['mentioned_entities']);
    }

    /** @test */
    public function it_detects_aggregation_query_type(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'What is the total revenue from orders?',
            ['labels' => ['Customer', 'Order']]
        );

        $this->assertEquals('aggregate', $result['query_type']);
    }

    /** @test */
    public function it_extracts_from_cypher_query(): void
    {
        $result = $this->extractor->extractFromCypher(
            'MATCH (c:Customer)-[:PLACED]->(o:Order) WHERE o.total > 100 RETURN c, o LIMIT 10'
        );

        $this->assertContains('Customer', $result['entities']);
        $this->assertContains('Order', $result['entities']);
        $this->assertContains('PLACED', $result['relationships']);
    }

    /** @test */
    public function it_handles_plural_forms(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'List all products',
            ['labels' => ['Customer', 'Order', 'Product']]
        );

        $this->assertEquals('Product', $result['focused_entity']);
    }

    /** @test */
    public function it_returns_null_when_no_entity_found(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'Hello, how are you?',
            ['labels' => ['Customer', 'Order']]
        );

        $this->assertNull($result['focused_entity']);
    }
}
