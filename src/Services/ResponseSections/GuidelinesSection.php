<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

/**
 * GuidelinesSection
 *
 * Provides guidelines for response generation with configurable verbosity.
 * Priority: 70
 *
 * Available styles:
 * - 'minimal': Just the answer, nothing else (e.g., "Admin System" or "42")
 * - 'concise': One sentence answer (e.g., "The next birthday is Admin System on Nov 29.")
 * - 'friendly': Natural conversation style, 2-3 sentences max
 * - 'detailed': Full explanation with some context
 * - 'technical': Includes query details and execution info
 *
 * Configuration via config/ai.php:
 * ```php
 * 'response_generation' => [
 *     'default_style' => 'friendly',
 *     'hide_technical_details' => true,
 *     'hide_execution_stats' => true,
 *     'hide_project_info' => true,
 * ]
 * ```
 */
class GuidelinesSection extends BaseResponseSection
{
    protected string $name = 'guidelines';
    protected int $priority = 70;

    /**
     * Style prompts - from minimal to technical
     */
    private array $stylePrompts = [
        'minimal' => 'Give ONLY the direct answer. No explanation, no context, no extra words. Just the answer value.',
        'concise' => 'One sentence only. Direct answer with the key fact. No explanation of how you got it.',
        'friendly' => 'Answer in a natural, conversational way. 2-3 sentences maximum. Focus on what the user asked, not how you found it.',
        'detailed' => 'Provide a helpful explanation with relevant context. Avoid technical jargon.',
        'technical' => 'Include technical details like query structure and execution metrics for debugging.',
    ];

    /**
     * Things to avoid based on configuration
     */
    private array $avoidGuidelines = [];

    /**
     * Custom guidelines
     */
    private array $customGuidelines = [];

    /**
     * Add a custom guideline
     */
    public function addGuideline(string $guideline): self
    {
        $this->customGuidelines[] = $guideline;
        return $this;
    }

    /**
     * Set all custom guidelines
     */
    public function setGuidelines(array $guidelines): self
    {
        $this->customGuidelines = $guidelines;
        return $this;
    }

    /**
     * Add or override a style
     */
    public function addStyle(string $name, string $prompt): self
    {
        $this->stylePrompts[$name] = $prompt;
        return $this;
    }

    /**
     * Set things to avoid in the response
     */
    public function setAvoidGuidelines(array $avoid): self
    {
        $this->avoidGuidelines = $avoid;
        return $this;
    }

    /**
     * Add something to avoid
     */
    public function addAvoid(string $avoid): self
    {
        $this->avoidGuidelines[] = $avoid;
        return $this;
    }

    public function format(array $context, array $options = []): string
    {
        $style = $options['style'] ?? config('ai.response_generation.default_style', 'friendly');
        $format = $options['format'] ?? 'text';
        $maxLength = $options['max_length'] ?? $this->getMaxLengthForStyle($style);

        // Load config-based restrictions
        $hideTechnical = $options['hide_technical'] ?? config('ai.response_generation.hide_technical_details', true);
        $hideStats = $options['hide_stats'] ?? config('ai.response_generation.hide_execution_stats', true);
        $hideProjectInfo = $options['hide_project'] ?? config('ai.response_generation.hide_project_info', true);

        $output = "Task: Answer the user's question based on the data.\n\n";
        $output .= "Guidelines:\n";
        $output .= "- " . ($this->stylePrompts[$style] ?? $this->stylePrompts['friendly']) . "\n";
        $output .= "- Start with a direct answer to the question\n";
        $output .= "- Use specific data from the results\n";
        $output .= "- Keep response under {$maxLength} words\n";

        // Style-specific guidelines
        if (in_array($style, ['minimal', 'concise', 'friendly'])) {
            $output .= "- Do NOT explain how you found the answer\n";
            $output .= "- Do NOT mention the query or database\n";
            $output .= "- Do NOT include execution time or performance metrics\n";
            $output .= "- Do NOT mention the project name or system name\n";
            $output .= "- Do NOT say things like 'The query returned...' or 'This was obtained by...'\n";
            $output .= "- Speak directly to the user as if you just know the answer\n";
        }

        // Config-based restrictions (for detailed style)
        if ($style === 'detailed') {
            if ($hideTechnical) {
                $output .= "- Do NOT reference the Cypher query or database structure\n";
            }
            if ($hideStats) {
                $output .= "- Do NOT mention execution time or query performance\n";
            }
            if ($hideProjectInfo) {
                $output .= "- Do NOT mention the project name or system architecture\n";
            }
        }

        // Format-specific guidelines
        if ($format === 'markdown') {
            $output .= "- Format response in Markdown\n";
        } elseif ($format === 'json') {
            $output .= "- Structure response as JSON with 'summary' and 'details' keys\n";
        }

        // Things to avoid
        $allAvoid = array_merge($this->avoidGuidelines, $this->getDefaultAvoid($style));
        if (!empty($allAvoid)) {
            $output .= "\nDo NOT include:\n";
            foreach (array_unique($allAvoid) as $avoid) {
                $output .= "- {$avoid}\n";
            }
        }

        // Custom guidelines
        if (!empty($this->customGuidelines)) {
            $output .= "\nAdditional guidelines:\n";
            foreach ($this->customGuidelines as $guideline) {
                $output .= "- {$guideline}\n";
            }
        }

        return $output . "\n";
    }

    /**
     * Get appropriate max length for style
     */
    private function getMaxLengthForStyle(string $style): int
    {
        return match ($style) {
            'minimal' => 20,
            'concise' => 50,
            'friendly' => 100,
            'detailed' => 200,
            'technical' => 300,
            default => 100,
        };
    }

    /**
     * Get default things to avoid based on style
     */
    private function getDefaultAvoid(string $style): array
    {
        $baseAvoid = [
            'Query execution time or milliseconds',
            'Technical implementation details',
            'Database or query language references',
            'Project or system architecture descriptions',
            'Phrases like "The query returned" or "was obtained by"',
        ];

        if (in_array($style, ['minimal', 'concise'])) {
            $baseAvoid[] = 'Any explanation or context';
            $baseAvoid[] = 'Phrases like "Based on the data" or "According to"';
        }

        if ($style === 'technical') {
            // Technical style can include everything
            return [];
        }

        return $baseAvoid;
    }
}
