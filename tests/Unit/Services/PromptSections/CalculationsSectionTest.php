<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\CalculationsSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * CalculationsSection — host-defined canonical formulas reach the generation prompt.
 */
class CalculationsSectionTest extends TestCase
{
    /** @test */
    public function it_renders_configured_calculations_with_formula_and_cypher(): void
    {
        config()->set('ai.calculations', [
            'team_total_owed' => [
                'label' => 'Total owed to the team',
                'description' => 'Outstanding receivables.',
                'formula' => 'SUM of Invoice.calc_due_amount where calc_due_amount > 0',
                'cypher' => 'MATCH (i:Invoice) WHERE i.calc_due_amount > 0 RETURN sum(i.calc_due_amount) AS total',
                'notes' => 'Never sum calc_total_amount for "owed".',
            ],
        ]);

        $section = new CalculationsSection();
        $context = new PipelineContext();

        $this->assertTrue($section->shouldInclude('how much are we owed?', $context));

        $output = $section->format('how much are we owed?', $context);

        $this->assertStringContainsString('DOMAIN CALCULATIONS', $output);
        $this->assertStringContainsString('Total owed to the team — Outstanding receivables.', $output);
        $this->assertStringContainsString('Formula: SUM of Invoice.calc_due_amount', $output);
        $this->assertStringContainsString('Cypher: MATCH (i:Invoice)', $output);
        $this->assertStringContainsString('Notes: Never sum calc_total_amount', $output);
        $this->assertStringContainsString('use its canonical formula EXACTLY', $output);
    }

    /** @test */
    public function it_is_excluded_entirely_when_no_calculations_are_configured(): void
    {
        config()->set('ai.calculations', []);

        $section = new CalculationsSection();
        $context = new PipelineContext();

        $this->assertFalse($section->shouldInclude('anything', $context));
        $this->assertSame('', $section->format('anything', $context));
    }

    /** @test */
    public function malformed_entries_are_skipped_not_fatal(): void
    {
        config()->set('ai.calculations', [
            'bad' => 'just a string',
            'good' => ['label' => 'Good metric', 'formula' => 'SUM of x'],
        ]);

        $output = (new CalculationsSection())->format('q', new PipelineContext());

        $this->assertStringContainsString('Good metric', $output);
        $this->assertStringNotContainsString('just a string', $output);
    }
}
