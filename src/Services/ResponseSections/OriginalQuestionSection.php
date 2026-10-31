<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * OriginalQuestionSection
 *
 * Shows the user's original question.
 * Priority: 30
 */
class OriginalQuestionSection extends BaseResponseSection
{
    protected string $name = 'question';
    protected int $priority = 30;

    public function format(PipelineContext $context, array $options = []): string
    {
        $question = $context->question;

        if (empty($question)) {
            return '';
        }

        // Spotlight the untrusted question so the model treats it as data, not instructions.
        $output = "Original Question:\n" . $this->spotlight($question, 'user_question') . "\n\n";

        // A follow-up leans on the conversation ("that number"), and these words alone do not say
        // what it refers to: measured 2026-10-30, the answer asked the user for a figure the rows
        // held. Query Understanding has already resolved it.
        $resolved = $context->qu->followUpReference ? trim($context->qu->clarifiedQuestion) : '';
        if ($resolved !== '' && $resolved !== trim($question)) {
            $output .= "Read with the conversation, it asks:\n" . $this->spotlight($resolved, 'resolved_question') . "\n\n";
        }

        return $output;
    }

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return !empty($context->question);
    }
}
