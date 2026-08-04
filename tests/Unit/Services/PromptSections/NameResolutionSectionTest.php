<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\PromptSections\NameResolutionSection;
use PHPUnit\Framework\TestCase;

/**
 * W3.1 (ws-a-query-1 / ws-a-query-8): property concepts the user asks to see must be
 * framed as RETURN columns (a projection), not only as properties to map/filter on.
 *
 * Pure string behavior — no container needed.
 */
class NameResolutionSectionTest extends TestCase
{
    private function contextWith(array $propertyConcepts = [], array $personNames = [], string $intent = 'other'): PipelineContext
    {
        $context = new PipelineContext();
        $context->qu = new QueryUnderstandingResult(
            personNames: $personNames,
            propertyConcepts: $propertyConcepts,
            intent: $intent,
        );

        return $context;
    }

    private function format(PipelineContext $context): string
    {
        return (new NameResolutionSection())->format('q', $context, []);
    }

    /** @test */
    public function it_instructs_the_generator_to_return_requested_property_concepts_as_columns(): void
    {
        $output = $this->format($this->contextWith(propertyConcepts: ['email']));

        // The concept must be projected (RETURN), not merely mapped/filtered.
        $this->assertStringContainsString('RETURN', $output);
        $this->assertStringContainsString('column', $output);
        $this->assertStringContainsString('email', $output);
    }

    /** @test */
    public function it_does_not_frame_property_concepts_as_filter_only(): void
    {
        $output = $this->format($this->contextWith(propertyConcepts: ['email']));

        // Regression guard for the old behavior: concepts framed ONLY as "map to a property".
        $this->assertStringContainsString('output column', $output);
    }

    /** @test */
    public function it_includes_property_concept_section_only_when_concepts_present(): void
    {
        $section = new NameResolutionSection();

        $this->assertFalse($section->shouldInclude('q', $this->contextWith()));
        $this->assertTrue($section->shouldInclude('q', $this->contextWith(propertyConcepts: ['email'])));
        $this->assertTrue($section->shouldInclude('q', $this->contextWith(personNames: ['John'])));
    }

    /** @test */
    public function it_still_emits_person_name_search_guidance(): void
    {
        $output = $this->format($this->contextWith(personNames: ['John Smith']));

        $this->assertStringContainsString('PERSON NAME SEARCH', $output);
        $this->assertStringContainsString('CONTAINS', $output);
    }

    /** @test */
    public function requested_attributes_override_entity_description_column_lists(): void
    {
        // Lever L2-B(ii): an entity description's "columns like name, email, ..."
        // steered the generator into dropping requested attributes (e.g. roles).
        // The REQUESTED ATTRIBUTES block must state its precedence explicitly.
        $output = $this->format($this->contextWith(propertyConcepts: ['roles']));

        $this->assertStringContainsString('PRECEDENCE', $output);
        $this->assertStringContainsString('OVERRIDE any column list', $output);
        $this->assertStringContainsString('entity', $output);
        // Attributes on related entities are reached by joining, not dropped.
        $this->assertStringContainsString('JOIN', $output);
    }
}
