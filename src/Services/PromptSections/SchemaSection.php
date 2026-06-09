<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

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

    /**
     * Skip the section entirely when there is no schema, rather than narrating its
     * absence ("No schema information available."), which is noise to the model.
     */
    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        return !empty($context->graphSchema);
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $schema = $context->graphSchema;

        if (empty($schema)) {
            return '';
        }

        $output = $this->header('GRAPH SCHEMA');

        if (!empty($schema['labels'])) {
            $output .= "Available Node Labels:\n";
            foreach ($schema['labels'] as $label) {
                $output .= "  - {$label}\n";
            }
            $output .= "\n";
        }

        if (!empty($schema['relationships'])) {
            $output .= "Available Relationship Types:\n";
            foreach ($schema['relationships'] as $relType) {
                $output .= "  - {$relType}\n";
            }
            $output .= "\n";
        }

        if (!empty($schema['properties']) && is_array($schema['properties'])) {
            $firstValue = reset($schema['properties']);
            if (is_array($firstValue)) {
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
