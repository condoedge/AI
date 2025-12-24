<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

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

    public function format(string $question, array $context, array $options = []): string
    {
        $output = $this->header('USER QUESTION');
        $output .= "{$question}\n\n";

        return $output;
    }
}
