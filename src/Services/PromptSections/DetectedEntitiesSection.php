<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * DetectedEntitiesSection
 *
 * Shows which entities were detected in the user's question
 * along with their metadata (aliases, descriptions, properties).
 * Priority: 60
 */
class DetectedEntitiesSection extends BasePromptSection
{
    protected string $name = 'detected_entities';
    protected int $priority = 60;

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $entityMetadata = $context->entityMetadata;
        $detected = $entityMetadata['detected_entities'] ?? [];
        $metadata = $entityMetadata['entity_metadata'] ?? [];

        if (empty($detected)) {
            return '';
        }

        $output = $this->header('DETECTED ENTITIES IN QUESTION');

        foreach ($detected as $entityName) {
            $meta = $metadata[$entityName] ?? [];

            $output .= "{$entityName}:\n";

            if (!empty($meta['description'])) {
                $output .= "  Description: {$meta['description']}\n";
            }

            if (!empty($meta['aliases'])) {
                $output .= "  Also known as: " . implode(', ', $meta['aliases']) . "\n";
            }

            if (!empty($meta['common_properties'])) {
                $output .= "  Key properties:\n";
                foreach ($meta['common_properties'] as $prop => $desc) {
                    if (is_string($desc)) {
                        $output .= "    - {$prop}: {$desc}\n";
                    }
                }
            }
            
            $output .= "\n";
        }

        return $output;
    }

    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        $entityMetadata = $context->entityMetadata;
        return !empty($entityMetadata['detected_entities']);
    }
}
