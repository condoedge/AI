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
        $entityConfigs = config('entities', []);

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

                // Show the relationship pattern with direction
                if ($direction === 'incoming') {
                    $output .= "  ({$label})<-[:{$type}]-({$targetLabel})\n";
                    $output .= "    Cypher: MATCH (x:{$label})<-[:{$type}]-(y:{$targetLabel})\n";
                } else {
                    $output .= "  ({$label})-[:{$type}]->({$targetLabel})\n";
                    $output .= "    Cypher: MATCH (x:{$label})-[:{$type}]->(y:{$targetLabel})\n";
                }
                $output .= "    Foreign key: {$foreignKey}\n\n";
            }
        }

        return $hasRelationships ? $output : '';
    }

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        $entityConfigs = config('entities', []);
        return !empty($entityConfigs);
    }
}
