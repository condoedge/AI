<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * SchemaSection
 *
 * Adds graph schema information (labels, relationships, properties).
 * Priority: 20
 */
class SchemaSection extends BasePromptSection
{
    protected string $name = 'schema';
    protected int $priority = 20;

    public function format(string $question, array $context, array $options = []): string
    {
        $schema = $context['graph_schema'] ?? [];

        if (empty($schema)) {
            return $this->header('GRAPH SCHEMA') . "No schema information available.\n\n";
        }

        $output = $this->header('GRAPH SCHEMA');

        // Node labels
        if (!empty($schema['labels'])) {
            $output .= "Available Node Labels:\n";
            foreach ($schema['labels'] as $label) {
                $output .= "  - {$label}\n";
            }
            $output .= "\n";
        }

        // Relationship types
        if (!empty($schema['relationships'])) {
            $output .= "Available Relationship Types:\n";
            foreach ($schema['relationships'] as $relType) {
                $output .= "  - {$relType}\n";
            }
            $output .= "\n";
        }

        // Node properties by label (if structured)
        if (!empty($schema['properties']) && is_array($schema['properties'])) {
            $firstValue = reset($schema['properties']);
            if (is_array($firstValue)) {
                // Structured by label
                $output .= "Node Properties by Label:\n";
                foreach ($schema['properties'] as $label => $properties) {
                    if (is_array($properties)) {
                        $output .= "  {$label}: " . implode(', ', $properties) . "\n";
                    }
                }
                $output .= "\n";
            }
        }

        return $output;
    }
}
