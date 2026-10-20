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
    public function natural_language_question_analysis_is_not_part_of_the_extractor(): void
    {
        // Phase 2.B: entities / follow-up / intent of a QUESTION are the QU LLM's
        // judgment. The substring matcher ("unit" inside "community") is gone.
        $this->assertFalse(method_exists($this->extractor, 'extractFromQuestion'));
    }
}
