<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * ExampleEntitiesSection
 *
 * Shows actual data from Neo4j to help LLM understand:
 * - Data types (string vs date vs integer)
 * - Date formats (ISO string vs Neo4j date)
 * - Property naming conventions
 *
 * CRITICAL for correct type handling in queries.
 * Priority: 40
 */
class ExampleEntitiesSection extends BasePromptSection
{
    protected string $name = 'example_entities';
    protected int $priority = 40;

    public function format(string $question, array $context, array $options = []): string
    {
        $entities = $context['relevant_entities'] ?? [];

        if (empty($entities)) {
            return '';
        }

        $output = $this->header('EXAMPLE ENTITIES (actual data format)');
        $output .= "IMPORTANT: Use these to understand data types! Dates as strings need string comparison.\n\n";

        foreach ($entities as $label => $examples) {
            $output .= "{$label} examples:\n";

            foreach (array_slice($examples, 0, 2) as $index => $entity) {
                $output .= "  Example " . ($index + 1) . ":\n";

                foreach ($entity as $property => $value) {
                    $formattedValue = $this->formatValue($value);
                    $output .= "    {$property}: {$formattedValue}\n";
                }
                $output .= "\n";
            }
        }

        return $output;
    }

    /**
     * Format a value with type hint for LLM understanding
     */
    private function formatValue(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true (boolean)' : 'false (boolean)';
        }

        if (is_int($value)) {
            return $value . ' (integer)';
        }

        if (is_float($value)) {
            return $value . ' (float)';
        }

        if (is_string($value)) {
            // Check for date pattern
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                return "'{$value}' (string date - compare as: property < '{$value}')";
            }
            // Truncate long strings
            if (strlen($value) > 50) {
                $value = substr($value, 0, 47) . '...';
            }
            return "'{$value}' (string)";
        }

        if (is_array($value)) {
            return json_encode($value) . ' (array)';
        }

        return (string) $value;
    }

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        return !empty($context['relevant_entities']);
    }
}
