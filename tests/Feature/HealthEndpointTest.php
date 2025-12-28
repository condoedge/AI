<?php
// tests/Feature/HealthEndpointTest.php

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class HealthEndpointTest extends TestCase
{
    /** @test */
    public function it_returns_health_status(): void
    {
        // Mock healthy services
        $graphStore = Mockery::mock(GraphStoreInterface::class);
        $graphStore->shouldReceive('getSchema')->andReturn(['labels' => []]);
        $this->app->instance(GraphStoreInterface::class, $graphStore);

        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $vectorStore->shouldReceive('listCollections')->andReturn([]);
        $this->app->instance(VectorStoreInterface::class, $vectorStore);

        config(['ai.llm.default' => 'openai']);
        config(['ai.llm.openai.api_key' => 'test-key-123']);

        $response = $this->getJson('/api/ai/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'services' => [
                    'neo4j',
                    'qdrant',
                    'llm',
                ],
                'timestamp',
            ]);
    }

    /** @test */
    public function it_returns_503_when_service_unhealthy(): void
    {
        // Mock failing Neo4j
        $graphStore = Mockery::mock(GraphStoreInterface::class);
        $graphStore->shouldReceive('getSchema')
            ->andThrow(new \RuntimeException('Connection refused'));
        $this->app->instance(GraphStoreInterface::class, $graphStore);

        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $vectorStore->shouldReceive('listCollections')->andReturn([]);
        $this->app->instance(VectorStoreInterface::class, $vectorStore);

        config(['ai.llm.default' => 'openai']);
        config(['ai.llm.openai.api_key' => 'test-key']);

        $response = $this->getJson('/api/ai/health');

        $response->assertStatus(503)
            ->assertJson(['status' => 'unhealthy']);
    }
}
