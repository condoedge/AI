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
        return "Original Question:\n" . $this->spotlight($question, 'user_question') . "\n\n";
    }

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return !empty($context->question);
    }
}
