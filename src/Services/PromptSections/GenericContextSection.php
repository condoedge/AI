<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * GenericContextSection
 *
 * Adds generic context like the current date/time.
 * Priority: 15 (after project context, before schema)
 *
 * The current-date line is always included: it is a single, language-independent
 * fact that the query generator needs for any relative-date reasoning ("today",
 * "ce mois-ci", "vergangene Woche", ...). It must not be gated on a hardcoded
 * keyword list, which would silently fail for non-English languages and violate
 * the project's no-hardcoded-semantics rule.
 */
class GenericContextSection extends BasePromptSection
{
    protected string $name = 'generic_context';
    protected int $priority = 15;

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $output = $this->header('CONTEXT INFORMATION');
        $output .= "Current date: " . date('Y-m-d H:i:s') . "\n\n";

        return $output;
    }
}
