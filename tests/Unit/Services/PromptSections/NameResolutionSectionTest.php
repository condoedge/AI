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
    /**
     * @test Eval p12-1001-A: REQUESTED ATTRIBUTES demanded "phone: RETURN the mapped
     * property as a column" and its PRECEDENCE line overrode the User card's "no
     * phone on record" — the generator borrowed the TEAM's t.contact_phone.
     */
    public function an_attribute_no_property_of_the_listed_records_holds_is_omitted_never_borrowed(): void
    {
        $output = $this->format($this->contextWith(propertyConcepts: ['contact', 'email', 'phone']));

        // Demanded only when a property holds it for the listed records…
        $this->assertStringContainsString('- phone: RETURN it as a column when a property holds it for the records being listed', $output);
        $this->assertStringNotContainsString('- phone: RETURN the mapped property as a column', $output);
        // …otherwise omitted and reported, never taken from another entity's field.
        $this->assertStringContainsString('NOT RECORDED', $output);
        $this->assertStringContainsString('do NOT return a column for it', $output);
        $this->assertStringContainsString('NEVER fill it from another entity', $output);
        // The precedence over description column lists stays, but never over "not recorded".
        $this->assertStringContainsString('OVERRIDE any column list', $output);
        $this->assertStringContainsString('never overrides a schema card that says an attribute is not recorded', $output);
    }

    /** @test Eval p13-A excel: "contact info" came back as `u.email AS contact`. */
    public function requested_columns_are_named_after_the_property_not_the_users_word(): void
    {
        $output = $this->format($this->contextWith(propertyConcepts: ['contact', 'role']));

        $this->assertStringContainsString('alias each returned column after the PROPERTY it holds (u.email AS email)', $output);
        $this->assertStringContainsString('never after the word the user used for it', $output);
    }

    /** @test */
    public function a_follow_up_names_the_entity_the_previous_rows_are(): void
    {
        // The roster's rows are User records (the recorded anchor label) — not the
        // QU's first entity type (Team) nor the conversation focus (Team).
        $context = new PipelineContext();
        $context->qu = new QueryUnderstandingResult(
            entityTypes: ['Team', 'TeamRole', 'User'],
            propertyConcepts: ['phone'],
            followUpReference: true,
        );
        $context->conversationContext = ['last_anchor_label' => 'User', 'focused_entity' => 'Team'];

        $output = $this->format($context);

        $this->assertStringContainsString('the User records being listed', $output);
        $this->assertStringContainsString('- phone: RETURN it as a column when a property holds it for the User records being listed', $output);
        $this->assertStringContainsString('not recorded for User', $output);
    }

    /** @test */
    public function a_fresh_question_does_not_reuse_the_previous_rows_entity(): void
    {
        $context = new PipelineContext();
        $context->qu = new QueryUnderstandingResult(propertyConcepts: ['phone'], followUpReference: false);
        $context->conversationContext = ['last_anchor_label' => 'User'];

        $output = $this->format($context);

        $this->assertStringNotContainsString('User records', $output);
        $this->assertStringContainsString('the records being listed', $output);
    }
}
