<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\ResponseSections\PageDescriptionSection;
use PHPUnit\Framework\TestCase;

/**
 * PageDescriptionSection grounds the read-only "Explain this page" narration. It must
 * stay silent (zero tokens) for every ordinary turn and, when a page description is set
 * on the turn, emit the host description plus the current route/label as grounding.
 *
 * Pure string behavior — no container needed.
 */
class PageDescriptionSectionTest extends TestCase
{
    /** @test */
    public function it_is_skipped_when_no_page_description_is_set(): void
    {
        $section = new PageDescriptionSection();

        $this->assertFalse($section->shouldInclude(new PipelineContext(), []));
    }

    /** @test */
    public function it_is_included_when_a_page_description_is_set(): void
    {
        $section = new PageDescriptionSection();

        $context = new PipelineContext();
        $context->pageDescription = 'This page shows a single unit and its payment history.';

        $this->assertTrue($section->shouldInclude($context, []));
    }

    /** @test */
    public function it_emits_the_description_with_the_route_and_label(): void
    {
        $section = new PageDescriptionSection();

        $context = new PipelineContext();
        $context->pageDescription = 'This page shows a single unit and its payment history.';
        $context->pageContext = ['route' => 'units.show', 'label' => 'Unit 402'];

        $output = $section->format($context, []);

        $this->assertStringContainsString('PAGE DESCRIPTION', $output);
        $this->assertStringContainsString('units.show', $output);
        $this->assertStringContainsString('Unit 402', $output);
        $this->assertStringContainsString('payment history', $output);
        // It instructs narration, not a data lookup.
        $this->assertStringContainsString('do NOT run or claim any data lookup', $output);
    }

    /** @test */
    public function it_emits_the_description_alone_when_no_route_is_present(): void
    {
        $section = new PageDescriptionSection();

        $context = new PipelineContext();
        $context->pageDescription = 'This is the dashboard overview.';

        $output = $section->format($context, []);

        $this->assertStringContainsString('dashboard overview', $output);
        $this->assertStringNotContainsString('Current screen (route)', $output);
    }
}
