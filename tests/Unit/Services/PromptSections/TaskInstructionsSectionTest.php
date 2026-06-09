<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\TaskInstructionsSection;
use PHPUnit\Framework\TestCase;

/**
 * E1: TaskInstructionsSection is collapsed to its UNIQUE signal — the NO QUERY REQUIRED
 * escape hatch and the "CYPHER QUERY:" cue — dropping the 7-item list that merely restated
 * QueryRulesSection's OUTPUT-FORMAT / relationship / data-format rules.
 *
 * Pure string behavior — no container needed.
 */
class TaskInstructionsSectionTest extends TestCase
{
    private function format(): string
    {
        return (new TaskInstructionsSection())->format('q', new PipelineContext(), []);
    }

    /** @test */
    public function it_keeps_the_no_query_required_escape_hatch_and_the_cypher_cue(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('NO QUERY REQUIRED', $output);
        $this->assertStringContainsString('CYPHER QUERY:', $output);
    }

    /** @test */
    public function it_drops_the_rules_duplicated_from_query_rules(): void
    {
        $output = $this->format();

        // These echoed QueryRulesSection and are gone now.
        $this->assertStringNotContainsString('EXACT relationship directions', $output);
        $this->assertStringNotContainsString('EXACT data formats', $output);
        $this->assertStringNotContainsString('no markdown', $output);
        $this->assertStringNotContainsString('query patterns from the library', $output);
    }

    /** @test */
    public function a_custom_instruction_still_overrides_with_the_cypher_cue(): void
    {
        $section = new TaskInstructionsSection();
        $section->setInstructions('Do the special thing.');

        $output = $section->format('q', new PipelineContext(), []);

        $this->assertStringContainsString('Do the special thing.', $output);
        $this->assertStringContainsString('CYPHER QUERY:', $output);
    }
}
