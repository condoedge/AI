<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * TaskInstructionsSection
 *
 * Provides final task instructions for the LLM.
 * Priority: 90 (last section)
 */
class TaskInstructionsSection extends BasePromptSection
{
    protected string $name = 'task_instructions';
    protected int $priority = 90;

    /**
     * Custom instructions that can override or extend default
     */
    private ?string $customInstructions = null;

    /**
     * Set custom task instructions
     *
     * @param string $instructions Custom instructions
     * @return self
     */
    public function setInstructions(string $instructions): self
    {
        $this->customInstructions = $instructions;
        return $this;
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $output = $this->header('YOUR TASK');

        if ($this->customInstructions !== null) {
            $output .= $this->customInstructions . "\n\n";
            $output .= "CYPHER QUERY:";
            return $output;
        }

        // The OUTPUT-FORMAT, relationship-direction and data-format rules already live in the
        // QUERY GENERATION RULES block; this section carries only its unique signal: the
        // escape hatch and the generation cue.
        $output .= "Generate a Cypher query that answers the user's question, following the QUERY GENERATION RULES.\n\n";

        $output .= "If the question cannot be answered with a Cypher query based on the provided context, or a query is not required to answer it, return the exact text: 'NO QUERY REQUIRED'.\n\n";

        $output .= "CYPHER QUERY:";

        return $output;
    }
}
