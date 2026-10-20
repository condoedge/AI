<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\ResponseSections\GuidelinesSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * The "do not reveal how you got the answer" rule used to be stated up to ~3x
 * (style block + detailed config block + an avoid list). It must now appear once.
 */
class GuidelinesSectionDedupTest extends TestCase
{
    private function format(string $style): string
    {
        return (new GuidelinesSection())->format(new PipelineContext(), ['style' => $style]);
    }

    /** @test */
    public function it_states_the_no_mechanics_rule_at_most_once_for_friendly(): void
    {
        $output = strtolower($this->format('friendly'));

        // The phrase that the old text repeated across sections.
        $this->assertLessThanOrEqual(
            1,
            substr_count($output, 'do not reveal how you found the answer'),
            'The "do not reveal mechanics" guidance is duplicated.'
        );

        // No leftover "Do NOT include:" avoid block restating the same constraints.
        $this->assertLessThanOrEqual(
            1,
            substr_count($output, 'the query returned'),
            'The "The query returned" phrasing is repeated across sections.'
        );
    }

    /** @test */
    public function it_keeps_one_language_and_one_max_length_line(): void
    {
        $output = $this->format('friendly');

        $this->assertSame(1, substr_count($output, 'SAME LANGUAGE'));
        $this->assertStringContainsString('under 100 words', $output);
    }

    /** @test */
    public function technical_style_does_not_hide_mechanics(): void
    {
        $output = strtolower($this->format('technical'));

        $this->assertStringNotContainsString('do not reveal how you found the answer', $output);
    }
}
