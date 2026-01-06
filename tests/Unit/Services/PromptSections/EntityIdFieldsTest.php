<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection;
use Condoedge\Ai\Tests\TestCase;

class EntityIdFieldsTest extends TestCase
{
    /** @test */
    public function it_uses_configured_id_fields(): void
    {
        config(['ai.entity_id_fields' => ['custom_id', 'reference_id']]);
        config(['ai.entity_actions' => [
            'Person' => [
                'profile' => ['action' => fn($id) => null, 'aliases' => [], 'label' => 'View'],
            ],
        ]]);

        $section = new EntityActionAwarenessSection();

        $context = [
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_result_sample' => [
                    ['custom_id' => 'ABC123', 'name' => 'John'],  // Uses custom_id
                ],
            ],
        ];

        $output = $section->format('show profile', $context);

        // Should find the ID using custom_id field
        $this->assertStringContainsString('ABC123', $output);
    }

    /** @test */
    public function it_falls_back_to_default_id_fields(): void
    {
        config(['ai.entity_id_fields' => null]);  // No config
        config(['ai.entity_actions' => [
            'Person' => [
                'profile' => ['action' => fn($id) => null, 'aliases' => [], 'label' => 'View'],
            ],
        ]]);

        $section = new EntityActionAwarenessSection();

        $context = [
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_result_sample' => [
                    ['id' => '999', 'name' => 'Jane'],
                ],
            ],
        ];

        $output = $section->format('show profile', $context);

        // Should find using default 'id' field
        $this->assertStringContainsString('999', $output);
    }
}
