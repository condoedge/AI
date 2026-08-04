<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\QueryRulesSection;
use PHPUnit\Framework\TestCase;

/**
 * E1: QueryRulesSection rides in the SYSTEM message while the schema/entities/scopes it
 * refers to are emitted in the later USER message. Its rules must therefore name the
 * blocks they depend on ("the GRAPH SCHEMA block") instead of using position words like
 * "above" / "the ... section", which are wrong once the role split moves the data away.
 *
 * Pure string behavior — no container needed.
 */
class QueryRulesSectionTest extends TestCase
{
    private function format(bool $allowWrite = false): string
    {
        return (new QueryRulesSection())->format('q', new PipelineContext(), ['allowWrite' => $allowWrite]);
    }

    /** @test */
    public function it_is_system_role(): void
    {
        $this->assertSame('system', (new QueryRulesSection())->role());
    }

    /** @test */
    public function it_does_not_use_position_words_to_reference_other_blocks(): void
    {
        $output = $this->format();

        // No "above" / "the ... section" references that break under the role split.
        $this->assertStringNotContainsString(' above', $output);
        $this->assertStringNotContainsString('DETECTED ENTITIES section', $output);
    }

    /** @test */
    public function it_names_the_blocks_it_depends_on(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('GRAPH SCHEMA block', $output);
        $this->assertStringContainsString('EXAMPLE ENTITIES block', $output);
        $this->assertStringContainsString('DETECTED BUSINESS CONCEPTS block', $output);
        $this->assertStringContainsString('DETECTED ENTITIES block', $output);
        $this->assertStringContainsString('ENTITY RELATIONSHIPS block', $output);
    }

    /** @test */
    public function it_still_emits_the_core_rule_categories(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('SCHEMA COMPLIANCE', $output);
        $this->assertStringContainsString('OUTPUT FORMAT', $output);
    }

    /** @test */
    public function it_forbids_inventing_edges_from_foreign_keys(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('Do NOT infer relationship traversals from *_id properties', $output);
        $this->assertStringContainsString('current_team_id', $output);
        $this->assertStringContainsString('filter on that property with WHERE', $output);
    }

    /** @test */
    public function it_emits_the_read_only_constraint_when_writes_are_disallowed(): void
    {
        $this->assertStringContainsString('READ-ONLY CONSTRAINT', $this->format(allowWrite: false));
        $this->assertStringNotContainsString('READ-ONLY CONSTRAINT', $this->format(allowWrite: true));
    }

    /** @test */
    public function it_forces_requested_attributes_into_the_return_clause(): void
    {
        $output = $this->format();

        // ws-a-query-1: requested attributes must be projected, not just filtered.
        $this->assertStringContainsString('RETURN', $output);
        $this->assertStringContainsString('REQUESTED ATTRIBUTES block', $output);
        $this->assertStringContainsString('output column', $output);
        $this->assertStringContainsString('projection requirement', $output);
    }

    /** @test */
    public function requested_attributes_take_precedence_over_entity_description_column_lists(): void
    {
        // Lever L2-B(ii): entity descriptions may suggest default columns; the
        // user's requested attributes must always win over those suggestions.
        $output = $this->format();

        $this->assertStringContainsString('REQUESTED ATTRIBUTES OVERRIDE any column list', $output);
        $this->assertStringContainsString('requested attributes always win', $output);
    }
}
