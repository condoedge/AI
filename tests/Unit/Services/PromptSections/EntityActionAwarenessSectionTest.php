<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection;
use Condoedge\Ai\Tests\TestCase;

class EntityActionAwarenessSectionTest extends TestCase
{
    private EntityActionAwarenessSection $section;

    public function setUp(): void
    {
        parent::setUp();

        $this->section = new EntityActionAwarenessSection();
    }

    /** @test */
    public function it_should_include_when_entity_actions_configured(): void
    {
        config(['ai.entity_actions' => ['Person' => ['profile' => []]]]);

        $result = $this->section->shouldInclude('give me the profile link', [], []);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_formats_action_awareness_with_context_ids(): void
    {
        config(['ai.entity_actions' => [
            'Person' => [
                'profile' => [
                    'aliases' => ['profile link', 'profile page'],
                    'label' => 'View Profile',
                ],
            ],
        ]]);

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

        $result = $this->section->shouldInclude('show me all people', [], []);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_formats_generic_actions_correctly(): void
    {
        config(['ai.entity_actions' => []]);
        config(['ai.generic_actions' => [
            'settings' => [
                'aliases' => ['settings page', 'preferences'],
                'label' => 'Settings',
            ],
        ]]);

        $output = $this->section->format('open settings', [], []);

        $this->assertStringContainsString('settings page', $output);
        $this->assertStringContainsString('(generic)', $output);
    }

    /** @test */
    public function it_has_correct_name_and_priority(): void
    {
        $this->assertEquals('entity_action_awareness', $this->section->getName());
        $this->assertEquals(58, $this->section->getPriority());
    }
}
