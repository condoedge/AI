<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * GenericContextSection
 *
 * Adds generic context like current date/time.
 * Priority: 15 (after project context, before schema)
 */
class GenericContextSection extends BasePromptSection
{
    protected string $name = 'generic_context';
    protected int $priority = 15;

    public function format(string $question, array $context, array $options = []): string
    {
        $output = $this->header('CONTEXT INFORMATION');
        $output .= "Current date: " . date('Y-m-d H:i:s') . "\n\n";

        return $output;
    }
}
