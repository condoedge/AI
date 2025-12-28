<?php

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Tests\TestCase;

class ReferenceResolverTest extends TestCase
{
    private ReferenceResolver $resolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ReferenceResolver();
    }

    /** @test */
    public function it_detects_follow_up_question(): void
    {
        $this->assertTrue($this->resolver->isFollowUp('and those in Sales team?'));
        $this->assertTrue($this->resolver->isFollowUp('show me the top 5'));
        $this->assertTrue($this->resolver->isFollowUp('filter them by status'));
        $this->assertFalse($this->resolver->isFollowUp('How many customers do we have?'));
    }

    /** @test */
    public function it_resolves_those_reference(): void
    {
        $context = [
            'focused_entity' => 'Customer',
            'mentioned_entities' => ['Customer', 'Order'],
            'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ];

        $result = $this->resolver->resolve('and those in Sales team?', $context);

        $this->assertEquals('Customer', $result['resolved_entity']);
        $this->assertEquals('filter', $result['operation']);
        $this->assertStringContainsString('Customer', $result['enriched_question']);
    }

    /** @test */
    public function it_resolves_them_reference(): void
    {
        $context = [
            'focused_entity' => 'Order',
            'last_cypher_query' => 'MATCH (o:Order) RETURN o LIMIT 10',
        ];

        $result = $this->resolver->resolve('sort them by date', $context);

        $this->assertEquals('Order', $result['resolved_entity']);
        $this->assertEquals('modify', $result['operation']);
    }

    /** @test */
    public function it_resolves_the_same_reference(): void
    {
        $context = [
            'focused_entity' => 'Customer',
            'last_cypher_query' => 'MATCH (c:Customer) WHERE c.status = "active" RETURN c',
        ];

        $result = $this->resolver->resolve('show the same but with orders', $context);

        $this->assertEquals('extend', $result['operation']);
        $this->assertNotNull($result['base_query']);
    }

    /** @test */
    public function it_handles_implicit_continuation(): void
    {
        $context = [
            'focused_entity' => 'Customer',
            'last_query_type' => 'count',
        ];

        $result = $this->resolver->resolve('show me the top 5 by revenue', $context);

        $this->assertEquals('Customer', $result['resolved_entity']);
    }

    /** @test */
    public function it_returns_unresolved_when_no_context(): void
    {
        $result = $this->resolver->resolve('show them', []);

        $this->assertFalse($result['resolved']);
        $this->assertNull($result['resolved_entity']);
    }

    /** @test */
    public function it_builds_enriched_question(): void
    {
        $context = [
            'focused_entity' => 'Customer',
            'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ];

        $result = $this->resolver->resolve('and in Sales team?', $context);

        $this->assertStringContainsString('Customer', $result['enriched_question']);
        $this->assertStringContainsString('Sales', $result['enriched_question']);
    }

    /** @test */
    public function it_detects_reference_type(): void
    {
        $this->assertEquals('pronoun', $this->resolver->detectReferenceType('show them'));
        $this->assertEquals('demonstrative', $this->resolver->detectReferenceType('those customers'));
        $this->assertEquals('definite', $this->resolver->detectReferenceType('the orders'));
        $this->assertEquals('implicit', $this->resolver->detectReferenceType('top 5 by revenue'));
        $this->assertNull($this->resolver->detectReferenceType('How many users?'));
    }
}
