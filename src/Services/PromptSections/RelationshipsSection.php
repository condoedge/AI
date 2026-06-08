<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

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

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $schema = $context->graphSchema;
        $entityConfigs = $this->getRelevantEntityConfigs($context);

        if (empty($entityConfigs)) {
            return '';
        }

        // Use the FULL Neo4j relationship types list (not the filtered semantic subset)
        // This ensures we only show relationships that actually exist in the graph
        $validRelTypes = $schema['all_relationship_types'] ?? $schema['relationships'] ?? [];

        $output = $this->header('ENTITY RELATIONSHIPS (with directions)');
        $output .= "CRITICAL: Use ONLY these relationship types and directions. Do NOT invent relationship types.\n\n";

        $hasRelationships = false;
        $shown = []; // Track what we've shown to avoid duplicates

        foreach ($entityConfigs as $entityName => $config) {
            $graphConfig = $config['graph'] ?? [];
            $relationships = $graphConfig['relationships'] ?? [];

            // STRICT filter: only show relationships whose type actually exists in Neo4j
            if (!empty($validRelTypes)) {
                $relationships = array_filter($relationships, function ($rel) use ($validRelTypes) {
                    return in_array($rel['type'] ?? '', $validRelTypes);
                });
            }

            // Skip pivot relationships and relationships without direction
            $relationships = array_filter($relationships, function ($rel) {
                return empty($rel['pivot']);
            });

            if (empty($relationships)) {
                continue;
            }

            $hasRelationships = true;
            $label = $graphConfig['label'] ?? $entityName;
            $output .= "{$label}:\n";

            foreach ($relationships as $rel) {
                $type = $rel['type'] ?? 'RELATED_TO';
                $targetLabel = $rel['target_label'] ?? 'Unknown';
                $direction = $rel['direction'] ?? 'outgoing';
                $description = $rel['description'] ?? '';

                // Avoid showing the same relationship twice from different entities
                $relSignature = $direction === 'incoming'
                    ? "{$targetLabel}-{$type}->{$label}"
                    : "{$label}-{$type}->{$targetLabel}";
                if (isset($shown[$relSignature])) {
                    continue;
                }
                $shown[$relSignature] = true;

                if ($direction === 'incoming') {
                    $output .= "  ({$label})<-[:{$type}]-({$targetLabel})\n";
                    $output .= "    Cypher: MATCH (x:{$label})<-[:{$type}]-(y:{$targetLabel})\n";
                } else {
                    $output .= "  ({$label})-[:{$type}]->({$targetLabel})\n";
                    $output .= "    Cypher: MATCH (x:{$label})-[:{$type}]->(y:{$targetLabel})\n";
                }
                if (!empty($description)) {
                    $output .= "    Description: {$description}\n";
                }
                $output .= "\n";
            }
        }

        return $hasRelationships ? $output : '';
    }

    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        return !empty($this->getRelevantEntityConfigs($context));
    }

    /**
     * Get only entity configs relevant to the current context.
     * Uses semantic selection if available, falls back to category-based loading.
     */
    private function getRelevantEntityConfigs(PipelineContext $context): array
    {
        $allConfigs = config('entities', []);

        // If semantic selection identified specific entities, use only those
        $selectedEntities = $context->selectionInfo['selected_entities'] ?? [];
        if (!empty($selectedEntities)) {
            return array_filter($allConfigs, function ($config) use ($selectedEntities) {
                $label = $config['graph']['label'] ?? '';
                return in_array($label, $selectedEntities);
            });
        }

        // If entity_metadata has detected entities, resolve them to configs
        $metadata = $context->entityMetadata;
        $detectedEntities = $metadata['detected_entities'] ?? [];
        if (!empty($detectedEntities)) {
            return array_filter($allConfigs, function ($config, $fqcn) use ($detectedEntities) {
                $label = $config['graph']['label'] ?? '';
                return in_array($fqcn, $detectedEntities) || in_array($label, $detectedEntities);
            }, ARRAY_FILTER_USE_BOTH);
        }

        // Fallback: load all (but this should be avoided)
        return $allConfigs;
    }
}
