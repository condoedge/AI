<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\TaskInstructionsSection;
use PHPUnit\Framework\TestCase;

/**
 * E1: TaskInstructionsSection is collapsed to its UNIQUE signal — the no-query escape
 * hatch (needs_query=false, Task 5.6) and the response cue — dropping the 7-item list that merely restated
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
    public function it_keeps_the_structured_no_query_escape_hatch_and_the_response_cue(): void
    {
        $output = $this->format();

        // Task 5.6: the escape hatch is the JSON contract, not an exact-match sentinel.
        $this->assertStringContainsString('"needs_query": false', $output);
        $this->assertStringNotContainsString('NO QUERY REQUIRED', $output);
        $this->assertStringEndsWith(TaskInstructionsSection::RESPONSE_CUE, $output);
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
        $this->assertStringEndsWith(TaskInstructionsSection::RESPONSE_CUE, $output);
    }

    /** @test Measured: told only at the top of the block, the model kept retyping the query. */
    public function a_matched_calculation_is_the_last_thing_asked_of_the_reply(): void
    {
        $context = new \Condoedge\Ai\DTOs\PipelineContext();
        $context->selectionInfo['calculations'] = ['matched' => ['fees' => 0.8]];

        $this->assertStringEndsWith(TaskInstructionsSection::CALCULATION_RESPONSE_CUE, (new TaskInstructionsSection())->format('q', $context));

        $context->selectionInfo['calculations'] = ['matched' => []];

        $this->assertStringEndsWith(TaskInstructionsSection::RESPONSE_CUE, (new TaskInstructionsSection())->format('q', $context));
    }
}
