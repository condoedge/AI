<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig;
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

        // Use the FULL Neo4j relationship evidence (not only the filtered semantic subset)
        // so the prompt never advertises configured app relationships as graph edges unless
        // Neo4j proves they exist.
        $hasRelTypeEvidence = array_key_exists('all_relationship_types', $schema)
            || array_key_exists('relationships', $schema);
        $validRelTypes = $schema['all_relationship_types'] ?? $schema['relationships'] ?? [];
        $hasRelPatternEvidence = array_key_exists('all_relationship_patterns', $schema)
            || array_key_exists('relationshipPatterns', $schema)
            || array_key_exists('relationship_patterns', $schema);
        $validRelPatterns = $schema['all_relationship_patterns']
            ?? $schema['relationshipPatterns']
            ?? $schema['relationship_patterns']
            ?? [];

        $output = $this->header('ENTITY RELATIONSHIPS (with directions)');
        $output .= "CRITICAL: Use ONLY these relationship types and directions. Do NOT invent relationship types.\n\n";

        $hasRelationships = false;
        $shown = []; // Track what we've shown to avoid duplicates

        foreach ($entityConfigs as $entityName => $config) {
            $graphConfig = $config['graph'] ?? [];
            $label = $graphConfig['label'] ?? $entityName;
            $relationships = $graphConfig['relationships'] ?? [];
            $fallbackRelationships = [];

            // STRICT filter: only show relationships whose type actually exists in Neo4j
            if ($hasRelPatternEvidence) {
                $availableRelationships = [];
                foreach ($relationships as $rel) {
                    if ($this->relationshipPatternExists($label, $rel, $validRelPatterns)) {
                        $availableRelationships[] = $rel;
                    } else {
                        $fallbackRelationships[] = $rel;
                    }
                }
                $relationships = $availableRelationships;
            } elseif ($hasRelTypeEvidence) {
                $availableRelationships = [];
                foreach ($relationships as $rel) {
                    if (in_array($rel['type'] ?? '', $validRelTypes, true)) {
                        $availableRelationships[] = $rel;
                    } else {
                        $fallbackRelationships[] = $rel;
                    }
                }
                $relationships = $availableRelationships;
            }

            // Skip pivot relationships and relationships without direction
            $relationships = array_filter($relationships, function ($rel) {
                return empty($rel['pivot']);
            });
            $fallbackRelationships = array_filter($fallbackRelationships, function ($rel) {
                return empty($rel['pivot']);
            });

            $fallbackRelationships = array_filter(
                $fallbackRelationships,
                fn ($rel) => $this->canRenderForeignKeyFallback($label, $rel, $schema)
            );

            if (empty($relationships) && empty($fallbackRelationships)) {
                continue;
            }

            $hasRelationships = true;
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

                // Polymorphic targets: the '_polymorphic' sentinel is a
                // sync-layer detail — a label that never exists in Neo4j —
                // so render an unlabeled node instead of leaking it.
                if ($targetLabel === RelationshipConfig::POLYMORPHIC_TARGET) {
                    if ($direction === 'incoming') {
                        $output .= "  ({$label})<-[:{$type}]-(x)  // polymorphic: x may be any entity type\n";
                        $output .= "    Cypher: MATCH (x:{$label})<-[:{$type}]-(y)\n";
                    } else {
                        $output .= "  ({$label})-[:{$type}]->(x)  // polymorphic: x may be any entity type\n";
                        $output .= "    Cypher: MATCH (x:{$label})-[:{$type}]->(y)\n";
                    }
                } elseif ($direction === 'incoming') {
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

            foreach ($fallbackRelationships as $rel) {
                $type = $rel['type'] ?? 'RELATED_TO';
                $targetLabel = $rel['target_label'] ?? 'Unknown';
                $foreignKey = $rel['foreign_key'];

                $output .= "  Direct FK filter to {$targetLabel} (no graph edge shown for :{$type}):\n";
                $output .= "    Property: {$label}.{$foreignKey} stores {$targetLabel}.id\n";
                $output .= "    Cypher: MATCH (x:{$label}) WHERE x.{$foreignKey} = <{$targetLabel}.id> RETURN x\n";
                $output .= "    Do NOT traverse :{$type} from {$label} to {$targetLabel} unless that exact edge is shown in this ENTITY RELATIONSHIPS block.\n\n";
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

        $selectedEntities = $context->selectionInfo['selected_entities'] ?? [];
        if (!empty($selectedEntities)) {
            return array_filter($allConfigs, function ($config) use ($selectedEntities) {
                $label = $config['graph']['label'] ?? '';
                return in_array($label, $selectedEntities);
            });
        }

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

    private function relationshipPatternExists(string $sourceLabel, array $relationship, array $patterns): bool
    {
        $type = $relationship['type'] ?? null;
        $targetLabel = $relationship['target_label'] ?? null;
        if (!$type || !$targetLabel || $targetLabel === RelationshipConfig::POLYMORPHIC_TARGET) {
            return false;
        }

        $direction = $relationship['direction'] ?? 'outgoing';
        $expectedFrom = $direction === 'incoming' ? $targetLabel : $sourceLabel;
        $expectedTo = $direction === 'incoming' ? $sourceLabel : $targetLabel;

        foreach ($patterns as $pattern) {
            if (!is_array($pattern)) {
                continue;
            }

            if (($pattern['type'] ?? $pattern['relationshipType'] ?? $pattern['relationship_type'] ?? null) !== $type) {
                continue;
            }

            $fromLabel = $pattern['from_label'] ?? $pattern['fromLabel'] ?? $pattern['from'] ?? null;
            $toLabel = $pattern['to_label'] ?? $pattern['toLabel'] ?? $pattern['to'] ?? null;

            if ($fromLabel === $expectedFrom && $toLabel === $expectedTo) {
                return true;
            }
        }

        return false;
    }

    private function canRenderForeignKeyFallback(string $sourceLabel, array $relationship, array $schema): bool
    {
        if (($relationship['direction'] ?? 'outgoing') === 'incoming') {
            return false;
        }

        $foreignKey = $relationship['foreign_key'] ?? null;
        if (!$foreignKey || !is_string($foreignKey)) {
            return false;
        }

        $propertiesByLabel = $schema['properties'] ?? [];
        $sourceProperties = is_array($propertiesByLabel)
            ? ($propertiesByLabel[$sourceLabel] ?? [])
            : [];

        if (is_array($sourceProperties) && in_array($foreignKey, $sourceProperties, true)) {
            return true;
        }

        $propertyKeys = $schema['propertyKeys'] ?? [];
        return is_array($propertyKeys) && in_array($foreignKey, $propertyKeys, true);
    }
}
