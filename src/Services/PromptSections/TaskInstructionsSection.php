<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

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

    public function format(string $question, array $context, array $options = []): string
    {
        $output = $this->header('YOUR TASK');

        if ($this->customInstructions !== null) {
            $output .= $this->customInstructions . "\n\n";
            $output .= "CYPHER QUERY:";
            return $output;
        }

        $output .= "Generate a Cypher query that:\n";
        $output .= "1. Accurately answers the user's question\n";
        $output .= "2. Uses the EXACT relationship directions shown in the schema\n";
        $output .= "3. Uses the EXACT data formats shown in the example entities\n";
        $output .= "4. Respects all business rules from the detected concepts above\n";
        $output .= "5. Uses the appropriate query patterns from the library\n";
        $output .= "6. Follows all query generation rules\n";
        $output .= "7. Returns clean Cypher only (no markdown, no explanations, no formatting)\n\n";
        $output .= "CYPHER QUERY:";

        return $output;
    }
}
