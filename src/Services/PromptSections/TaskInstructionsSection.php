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

    /** The generation cue: the reply is the structured JSON object (Task 5.6). */
    public const RESPONSE_CUE = 'JSON RESPONSE ({"needs_query", "cypher", "reason"}):';

    /** The cue when a domain calculation matches: the last thing read is what is asked of the reply. */
    public const CALCULATION_RESPONSE_CUE = 'JSON RESPONSE ({"needs_query", "calculation", "values", "cypher", "reason"}):';

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
            $output .= self::RESPONSE_CUE;
            return $output;
        }

        // The OUTPUT-FORMAT, relationship-direction and data-format rules already live in the
        // QUERY GENERATION RULES block; this section carries only its unique signal: the
        // structured no-query escape hatch and the response cue.
        $output .= "Generate a Cypher query that answers the user's question, following the QUERY GENERATION RULES.\n\n";

        $output .= "If the question cannot be answered with a Cypher query based on the provided context, or a query is not required to answer it, "
            . "respond with {\"needs_query\": false, \"cypher\": null, \"reason\": \"<why>\"}.\n\n";

        if (!empty($context->selectionInfo['calculations']['matched'])) {
            $output .= "A DOMAIN CALCULATION above matches this question: name it by its key, or its variant's, with \"calculation\" and \"values\". "
                . "Write \"cypher\" only if none of them can return what is asked.\n\n";
            $output .= self::CALCULATION_RESPONSE_CUE;

            return $output;
        }

        $output .= self::RESPONSE_CUE;

        return $output;
    }
}
