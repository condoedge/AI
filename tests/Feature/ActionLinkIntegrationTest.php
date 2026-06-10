<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Services\ResponseSections\ResponseEntityActionsSection;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class ActionLinkIntegrationTest extends TestCase
{
    /** @test */
    public function it_provides_action_links_for_no_query_response(): void
    {
        // Setup mock discovery with Person actions
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('hasEntityActions')
            ->with('Person')
            ->andReturn(true);
        $mockDiscovery->shouldReceive('getAuthorizedEntityActions')
            ->with('Person', Mockery::any())
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link', 'profile page'], 'label' => 'View Profile'],
            ]);
        $mockDiscovery->shouldReceive('getAuthorizedGenericActions')->andReturn([]);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        $section = new ResponseEntityActionsSection();

        // Simulate NO QUERY scenario - cypher is empty but we have conversation context.
        // The pipeline now carries a typed PipelineContext DTO, not a raw array.
        $context = new PipelineContext();
        $context->question = 'give me the profile link for John';
        $context->queryResult = new QueryGenerationResult(cypher: ''); // NO QUERY REQUIRED
        $context->executionResult = new QueryExecutionResult(success: true, data: []); // nothing executed
        $context->conversationContext = [
            'focused_entity' => 'Person',
            'last_result_sample' => [
                ['id' => '152463', 'name' => 'John Doe', 'full_name' => 'John Doe'],
            ],
            'last_result_count' => 1,
        ];

        $shouldInclude = $section->shouldInclude($context, []);
        $this->assertTrue($shouldInclude, 'Section should include based on focused_entity');

        $output = $section->format($context, []);

        // Verify the AI receives proper instructions
        $this->assertStringContainsString('Person Actions', $output);
        $this->assertStringContainsString('entity://Person/152463/profile', $output);
        $this->assertStringContainsString('John Doe', $output);
        $this->assertStringContainsString('profile link', $output); // alias shown
    }

    /** @test */
    public function it_handles_multiple_entities_from_context(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('hasEntityActions')
            ->with('Person')
            ->andReturn(true);
        $mockDiscovery->shouldReceive('getAuthorizedEntityActions')
            ->with('Person', Mockery::any())
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
            ]);
        $mockDiscovery->shouldReceive('getAuthorizedGenericActions')->andReturn([]);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        $section = new ResponseEntityActionsSection();

        $context = new PipelineContext();
        $context->queryResult = new QueryGenerationResult(cypher: '');
        $context->executionResult = new QueryExecutionResult(success: true, data: []);
        $context->conversationContext = [
            'focused_entity' => 'Person',
            'last_result_sample' => [
                ['id' => '100', 'name' => 'Alice Johnson'],
                ['id' => '101', 'name' => 'Bob Smith'],
                ['id' => '102', 'name' => 'Carol White'],
            ],
        ];

        $output = $section->format($context, []);

        // All entity IDs should be available
        $this->assertStringContainsString('entity://Person/100/profile', $output);
        $this->assertStringContainsString('entity://Person/101/profile', $output);
        $this->assertStringContainsString('entity://Person/102/profile', $output);
        $this->assertStringContainsString('Alice Johnson', $output);
        $this->assertStringContainsString('Bob Smith', $output);
    }
}
