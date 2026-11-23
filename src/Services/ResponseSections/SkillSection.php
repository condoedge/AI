<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * SkillSection - the host's expert knowledge for this question (ai.skills)
 *
 * Prints the skill the turn selected: its instructions, then each selected
 * reference whole. The text is the host's own, written for the assistant, so it is
 * not fenced as retrieved content and carries no citation number.
 *
 * Priority: 44 (before the retrieved files at 45 and the data at 50); zero tokens
 * when the turn selected no skill.
 */
class SkillSection extends BaseResponseSection
{
    protected string $name = 'skill';
    protected int $priority = 44;

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return !empty($context->skill['references']);
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $references = $context->skill['references'] ?? [];
        if (empty($references)) {
            return '';
        }

        $output = $this->header('EXPERT KNOWLEDGE');
        $output .= "The application's own reference knowledge on the subject of this question, written for you. "
            . "It is a valid ground for the answer, like the data and the files: explain and reason from it in your own words, "
            . "for this user and their situation, and say so when it does not settle the question. "
            . "It is not a document of the user's: never cite it by number or name it as a file. "
            . "When the user's data is shown below, apply the knowledge to it; figures come from the data, never from an example here.\n\n";

        $instructions = trim((string) ($context->skill['instructions'] ?? ''));
        if ($instructions !== '') {
            $output .= "How to use it:\n{$instructions}\n\n";
        }

        foreach ($references as $reference) {
            $output .= "--- {$reference['title']} ---\n\n" . trim((string) $reference['content']) . "\n\n";
        }

        return $output . "---\n\n";
    }
}
