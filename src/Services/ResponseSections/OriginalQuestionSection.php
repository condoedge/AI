<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

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

    public function format(array $context, array $options = []): string
    {
        $question = $context['question'] ?? '';

        if (empty($question)) {
            return '';
        }

        return "Original Question: {$question}\n\n";
    }

    public function shouldInclude(array $context, array $options = []): bool
    {
        return !empty($context['question']);
    }
}
