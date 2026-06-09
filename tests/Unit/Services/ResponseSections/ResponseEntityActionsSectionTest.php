<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Services\ResponseSections\ResponseEntityActionsSection;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class ResponseEntityActionsSectionTest extends TestCase
{
    private ResponseEntityActionsSection $section;
    private $mockDiscovery;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $this->app->instance(EntityAutoDiscovery::class, $this->mockDiscovery);

        $this->section = new ResponseEntityActionsSection();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_uses_focused_entity_from_conversation_context(): void
    {
        $this->mockDiscovery->shouldReceive('hasEntityActions')
            ->with('Person')
            ->andReturn(true);
        $this->mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
            ]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        // NO QUERY case: empty cypher + empty data, entity type/IDs come from conversation context.
        $context = new PipelineContext();
        $context->queryResult = new QueryGenerationResult(cypher: '');
        $context->executionResult = new QueryExecutionResult(success: true, data: []);
        $context->conversationContext = [
            'focused_entity' => 'Person',
            'last_result_sample' => [
                ['id' => '152463', 'name' => 'John Doe'],
                ['id' => '152464', 'name' => 'Jane Smith'],
            ],
        ];

        $result = $this->section->shouldInclude($context, []);
        $this->assertTrue($result);

        $output = $this->section->format($context, []);

        $this->assertStringContainsString('Person Actions', $output);
        $this->assertStringContainsString('entity://Person/152463/profile', $output);
        $this->assertStringContainsString('John Doe', $output);
    }

    /** @test */
    public function it_falls_back_to_cypher_parsing_when_no_conversation_context(): void
    {
        $this->mockDiscovery->shouldReceive('hasEntityActions')
            ->with('Person')
            ->andReturn(true);
        $this->mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
            ]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        // No conversation context: entity type parsed from cypher, IDs from execution data.
        $context = new PipelineContext();
        $context->queryResult = new QueryGenerationResult(
            cypher: 'MATCH (p:Person) WHERE p.name = "John" RETURN p'
        );
        $context->executionResult = new QueryExecutionResult(
            success: true,
            data: [
                ['id' => '152463', 'name' => 'John Doe'],
            ]
        );

        $result = $this->section->shouldInclude($context, []);
        $this->assertTrue($result);

        $output = $this->section->format($context, []);

        $this->assertStringContainsString('Person Actions', $output);
        $this->assertStringContainsString('152463', $output);
    }

    /** @test */
    public function it_includes_all_entity_ids_from_context(): void
    {
        $this->mockDiscovery->shouldReceive('hasEntityActions')
            ->with('Person')
            ->andReturn(true);
        $this->mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
            ]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        $context = new PipelineContext();
        $context->queryResult = new QueryGenerationResult(cypher: '');
        $context->executionResult = new QueryExecutionResult(success: true, data: []);
        $context->conversationContext = [
            'focused_entity' => 'Person',
            'last_result_sample' => [
                ['id' => '100', 'name' => 'Alice'],
                ['id' => '101', 'name' => 'Bob'],
                ['id' => '102', 'name' => 'Charlie'],
            ],
        ];

        $output = $this->section->format($context, []);

        $this->assertStringContainsString('Alice', $output);
        $this->assertStringContainsString('100', $output);
        $this->assertStringContainsString('Bob', $output);
        $this->assertStringContainsString('101', $output);
    }
}
