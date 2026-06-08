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

        return $output;
    }
}
