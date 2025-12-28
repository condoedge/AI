<?php

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class RelationshipWeightsTest extends TestCase
{
    /** @test */
    public function it_returns_configured_weight_for_relationship(): void
    {
        config(['ai.relationship_weights' => [
            'PURCHASED' => 1.0,
            'default' => 0.5,
        ]]);

        $retriever = $this->createMockedContextRetriever();

        $weight = $retriever->getRelationshipWeight('PURCHASED');

        $this->assertEquals(1.0, $weight);
    }

    /** @test */
    public function it_returns_default_weight_for_unknown_relationship(): void
    {
        config(['ai.relationship_weights' => [
            'default' => 0.5,
        ]]);

        $retriever = $this->createMockedContextRetriever();

        $weight = $retriever->getRelationshipWeight('UNKNOWN_TYPE');

        $this->assertEquals(0.5, $weight);
    }

    /** @test */
    public function it_filters_relationships_by_threshold(): void
    {
        config(['ai.relationship_weights' => [
            'PURCHASED' => 1.0,
            'VIEWED' => 0.3,
            'default' => 0.5,
        ]]);

        $retriever = $this->createMockedContextRetriever();

        $relationships = [
            ['type' => 'PURCHASED'],
            ['type' => 'VIEWED'],
        ];

        $filtered = $retriever->filterRelationshipsByImportance($relationships, 0.5);

        $this->assertCount(1, $filtered);
    }

    private function createMockedContextRetriever(): ContextRetriever
    {
        // Create with mocked dependencies (order: VectorStore, GraphStore, EmbeddingProvider)
        return new ContextRetriever(
            Mockery::mock(\Condoedge\Ai\Contracts\VectorStoreInterface::class),
            Mockery::mock(\Condoedge\Ai\Contracts\GraphStoreInterface::class),
            Mockery::mock(\Condoedge\Ai\Contracts\EmbeddingProviderInterface::class)
        );
    }
}
