<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Services\PatternLibrary;

/**
 * PatternLibrarySection
 *
 * Shows available reusable query patterns.
 * Priority: 70
 */
class PatternLibrarySection extends BasePromptSection
{
    protected string $name = 'pattern_library';
    protected int $priority = 70;

    private PatternLibrary $patternLibrary;

    public function __construct(PatternLibrary $patternLibrary)
    {
        $this->patternLibrary = $patternLibrary;
    }

    public function format(string $question, array $context, array $options = []): string
    {
        $patterns = $this->patternLibrary->getAllPatterns();

        if (empty($patterns)) {
            return '';
        }

        $output = $this->header('AVAILABLE QUERY PATTERNS');
        $output .= "You can use these reusable patterns to construct queries:\n\n";

        foreach ($patterns as $name => $pattern) {
            $output .= "PATTERN: {$name}\n";
            $output .= "  Purpose: {$pattern['description']}\n";
            $output .= "  Template: {$pattern['semantic_template']}\n";

            if (!empty($pattern['parameters'])) {
                $output .= "  Parameters: " . implode(', ', array_keys($pattern['parameters'])) . "\n";
            }

            $output .= "\n";
        }

        return $output;
    }

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        return !empty($this->patternLibrary->getAllPatterns());
    }
}
