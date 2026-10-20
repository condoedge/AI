<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\ProjectContextSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * Task 5.11 — business rules may be scoped to entities, so a rule about one entity
 * ("unit names are identifiers", "never scope Invoice by team_id") ships only when
 * that entity is in the prompt. Plain-string rules always ship.
 */
class ProjectContextSectionTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['ai.project' => [
            'name' => 'Condo',
            'description' => 'Condo management.',
            'business_rules' => [
                'GENERAL: several things in one question → one query.',
                ['rule' => 'UNIT NAMES are identifiers — exact match on u.name.', 'entities' => ['Unit']],
                ['rule' => 'NEVER scope Invoice by team_id.', 'entities' => ['Invoice', 'Bill']],
                ['rule' => 'ARRAY WITHOUT ENTITIES always ships.'],
            ],
        ]]);
    }

    private function context(array $labels): PipelineContext
    {
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => $labels];

        return $context;
    }

    /** @test */
    public function entity_scoped_rules_ship_only_with_their_entities(): void
    {
        $output = (new ProjectContextSection())->format('members of my team', $this->context(['Team', 'User']));

        $this->assertStringContainsString('GENERAL: several things', $output);
        $this->assertStringContainsString('ARRAY WITHOUT ENTITIES', $output);
        $this->assertStringNotContainsString('UNIT NAMES', $output);
        $this->assertStringNotContainsString('NEVER scope Invoice', $output);
    }

    /** @test */
    public function a_rule_ships_when_any_of_its_entities_is_selected(): void
    {
        $output = (new ProjectContextSection())->format('what does unit 310 owe', $this->context(['Unit', 'Invoice']));

        $this->assertStringContainsString('  - UNIT NAMES are identifiers', $output);
        $this->assertStringContainsString('  - NEVER scope Invoice by team_id.', $output);
    }

    /** @test */
    public function every_rule_ships_without_a_projected_schema(): void
    {
        $output = (new ProjectContextSection())->format('anything', new PipelineContext());

        foreach (['GENERAL', 'UNIT NAMES', 'NEVER scope Invoice', 'ARRAY WITHOUT ENTITIES'] as $rule) {
            $this->assertStringContainsString($rule, $output);
        }
    }

    /** @test */
    public function malformed_rules_are_skipped(): void
    {
        config(['ai.project.business_rules' => ['ok rule', ['entities' => ['Unit']], 42, null]]);

        $output = (new ProjectContextSection())->format('anything', new PipelineContext());

        $this->assertStringContainsString('  - ok rule', $output);
        $this->assertSame(1, substr_count($output, '  - '));
    }
}
