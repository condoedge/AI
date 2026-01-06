<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class EntityActionAwarenessSectionTest extends TestCase
{
    private EntityActionAwarenessSection $section;
    private $mockDiscovery;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $this->app->instance(EntityAutoDiscovery::class, $this->mockDiscovery);

        $this->section = new EntityActionAwarenessSection();
    }

    /** @test */
    public function it_should_include_when_entity_actions_configured(): void
    {
        $this->mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
            ]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        config(['ai.entity_actions' => ['Person' => ['profile' => []]]]);

        $result = $this->section->shouldInclude('give me the profile link', [], []);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_formats_action_awareness_with_context_ids(): void
    {
        $this->mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link', 'profile page'], 'label' => 'View Profile'],
            ]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        config(['ai.entity_actions' => ['Person' => ['profile' => []]]]);

        $context = [
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_result_sample' => [
                    ['id' => '152463', 'name' => 'John Doe'],
                ],
            ],
        ];

        $output = $this->section->format('give me the profile link', $context, []);

        $this->assertStringContainsString('ACTION REQUESTS', $output);
        $this->assertStringContainsString('profile link', $output);
        $this->assertStringContainsString('NOT database fields', $output);
        $this->assertStringContainsString('NO QUERY REQUIRED', $output);
        $this->assertStringContainsString('152463', $output);
    }

    /** @test */
    public function it_returns_empty_when_no_actions_configured(): void
    {
        config(['ai.entity_actions' => []]);
        config(['ai.generic_actions' => []]);

        $this->mockDiscovery->shouldReceive('getEntityActions')->andReturn([]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        $result = $this->section->shouldInclude('show me all people', [], []);

        $this->assertFalse($result);
    }
}
