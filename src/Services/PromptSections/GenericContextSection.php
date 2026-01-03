<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * GenericContextSection
 *
 * Adds generic context like current date/time.
 * Priority: 15 (after project context, before schema)
 *
 * Only included when the question references time/date concepts
 * or when explicitly enabled via options.
 */
class GenericContextSection extends BasePromptSection
{
    protected string $name = 'generic_context';
    protected int $priority = 15;

    /**
     * Time-related keywords that indicate the section should be included
     */
    private const TIME_KEYWORDS = [
        'today', 'yesterday', 'tomorrow', 'week', 'month', 'year',
        'recent', 'latest', 'last', 'current', 'now', 'this',
        'date', 'time', 'when', 'ago', 'since', 'before', 'after',
    ];

    /**
     * Only include when question references time concepts or explicitly enabled
     */
    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        // Always include if explicitly enabled
        if (!empty($options['include_generic_context'])) {
            return true;
        }

        // Check if question contains time-related keywords
        $questionLower = strtolower($question);
        foreach (self::TIME_KEYWORDS as $keyword) {
            if (str_contains($questionLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    public function format(string $question, array $context, array $options = []): string
    {
        $output = $this->header('CONTEXT INFORMATION');
        $output .= "Current date: " . date('Y-m-d H:i:s') . "\n\n";

        return $output;
    }
}
