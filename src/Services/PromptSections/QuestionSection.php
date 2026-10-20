<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * QuestionSection
 *
 * Adds the user's question to the prompt.
 * Priority: 80
 */
class QuestionSection extends BasePromptSection
{
    protected string $name = 'question';
    protected int $priority = 80;

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $output = $this->header('USER QUESTION');
        // Spotlight the untrusted question so the model treats it as data, not instructions.
        $output .= $this->spotlight($question, 'user_question') . "\n\n";

        // Surface the Query-Understanding clarified intent so the Cypher LLM sees the
        // disambiguated underlying data relationships alongside the raw question.
        // Only include it when it adds signal (non-empty AND different from the question).
        $clarified = $context->qu->clarifiedQuestion;
        if ($clarified !== '' && trim($clarified) !== trim($question)) {
            // Spotlight: this is derived/untrusted understanding, not an instruction.
            $output .= $this->spotlight($clarified, 'clarified_intent') . "\n\n";
        }

        return $output;
    }
}
