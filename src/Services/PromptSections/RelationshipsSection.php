<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * RelationshipsSection
 *
 * Adds entity relationships with EXACT directions.
 * CRITICAL for correct query generation.
 * Priority: 30
 */
class RelationshipsSection extends BasePromptSection
{
    protected string $name = 'relationships';
    protected int $priority = 30;

    public function format(string $question, array $context, array $options = []): string
    {
        $schema = $context['graph_schema'] ?? [];
        $entityConfigs = $this->getRelevantEntityConfigs($context);

        if (empty($entityConfigs)) {
            return '';
        }

        $output = $this->header('ENTITY RELATIONSHIPS (with directions)');
        $output .= "IMPORTANT: Use these EXACT directions in your queries!\n\n";

        $hasRelationships = false;

        foreach ($entityConfigs as $entityName => $config) {
            $graphConfig = $config['graph'] ?? [];
            $relationships = $graphConfig['relationships'] ?? [];

            // Filter to only show relationships that exist in schema
            if (!empty($schema['relationships'])) {
                $relationships = array_filter($relationships, function ($rel) use ($schema) {
                    return in_array($rel['type'] ?? '', $schema['relationships']);
                });
            }

            if (empty($relationships)) {
                continue;
            }

            $hasRelationships = true;
            $label = $graphConfig['label'] ?? $entityName;
            $output .= "{$label}:\n";

            foreach ($relationships as $rel) {
                $type = $rel['type'] ?? 'RELATED_TO';
                $targetLabel = $rel['target_label'] ?? 'Unknown';
                $foreignKey = $rel['foreign_key'] ?? 'id';
                $direction = $rel['direction'] ?? 'outgoing';
                $pivot = $rel['pivot'] ?? false;
                $description = $rel['description'] ?? '';

                if ($pivot) {
                    // Skip pivot/junction table relationships for clarity
                    continue;
                }

                // Show the relationship pattern with direction
                if ($direction === 'incoming') {
                    $output .= "  ({$label})<-[:{$type}]-({$targetLabel})\n";
                    $output .= "    Cypher: MATCH (x:{$label})<-[:{$type}]-(y:{$targetLabel})\n";
                } else {
                    $output .= "  ({$label})-[:{$type}]->({$targetLabel})\n";
                    $output .= "    Cypher: MATCH (x:{$label})-[:{$type}]->(y:{$targetLabel})\n";
                }
                $output .= "    Foreign key: {$foreignKey}\n\n";
                $output .= !empty($description) ? "    Description: {$description}\n\n" : '';
            }
        }

        return $hasRelationships ? $output : '';
    }

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        return !empty($this->getRelevantEntityConfigs($context));
    }

    /**
     * Get only entity configs relevant to the current context.
     * Uses semantic selection if available, falls back to category-based loading.
     */
    private function getRelevantEntityConfigs(array $context): array
    {
        // If semantic selection identified specific entities, use only those
        $selectedEntities = $context['selection_info']['selected_entities'] ?? [];

        if (!empty($selectedEntities)) {
            $allConfigs = config('entities', []);
            return array_filter($allConfigs, function ($config) use ($selectedEntities) {
                $label = $config['graph']['label'] ?? '';
                return in_array($label, $selectedEntities);
            });
        }

        // If entity_metadata has detected entities, use those
        $metadata = $context['entity_metadata'] ?? [];
        if (!empty($metadata)) {
            $allConfigs = config('entities', []);
            $detectedLabels = array_map(fn($m) => $m['label'] ?? '', $metadata);
            return array_filter($allConfigs, function ($config) use ($detectedLabels) {
                $label = $config['graph']['label'] ?? '';
                return in_array($label, $detectedLabels);
            });
        }

        // Fallback: load all (but this should be avoided)
        return config('entities', []);
    }
}
