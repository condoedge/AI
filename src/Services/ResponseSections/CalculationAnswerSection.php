<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * CalculationAnswerSection - how to answer from the calculation that ran (ai.calculations)
 *
 * A calculation's `notes` tell the generator how to ask for it; its `answer_notes` tell the answer
 * what the rows mean and what to do with them (a reply to draft, a reading to give of each row).
 * Printed only on a turn whose rows came from a calculation that declares some: zero tokens otherwise.
 */
class CalculationAnswerSection extends BaseResponseSection
{
    protected string $name = 'calculation_answer';
    protected int $priority = 52;

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return $this->notes($context) !== '';
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $notes = $this->notes($context);

        return $notes === '' ? '' : $this->header('HOW TO ANSWER FROM THIS RESULT') . $notes . "\n\n";
    }

    private function notes(PipelineContext $context): string
    {
        $key = $context->queryResult?->metadata['calculation'] ?? null;
        $key = is_string($key) ? explode('.', $key, 2)[0] : null;

        return $key === null ? '' : trim((string) config("ai.calculations.{$key}.answer_notes", ''));
    }
}
