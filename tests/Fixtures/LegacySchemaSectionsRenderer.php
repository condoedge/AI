<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * FROZEN copy of the three deleted query-prompt sections' format() logic
 * (SchemaSection, RelationshipsSection, DetectedEntitiesSection as of 2026-09-30),
 * kept ONLY so the Task 5.2 size test can compute "old three sections combined"
 * against the schema-card block for the same fixture. Not used by src/.
 */
final class LegacySchemaSectionsRenderer
{
    public static function render(PipelineContext $context): string
    {
        return self::schema($context) . self::relationships($context) . self::detectedEntities($context);
    }

    private static function header(string $title): string
    {
        return "\n=== {$title} ===\n\n";
    }

    private static function schema(PipelineContext $context): string
    {
        $schema = $context->graphSchema;
        if (empty($schema)) {
            return '';
        }

        $output = self::header('GRAPH SCHEMA');

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

        if (!empty($schema['relationshipPatterns'])) {
            $output .= "Relationship Patterns by Label:\n";
            foreach ($schema['relationshipPatterns'] as $pattern) {
                $count = isset($pattern['count']) ? ' count=' . (int) $pattern['count'] : '';
                $output .= "  - (:{$pattern['from_label']})-[:{$pattern['type']}]->(:{$pattern['to_label']}){$count}\n";
            }
            $output .= "\n";
        }

        if (!empty($schema['properties'])) {
            $output .= "Node Properties by Label:\n";
            foreach ($schema['properties'] as $label => $properties) {
                $output .= "  {$label}: " . implode(', ', $properties) . "\n";
            }
            $output .= "\n";
        }

        return $output;
    }

    private static function relationships(PipelineContext $context): string
    {
        $schema = $context->graphSchema;
        $selected = $context->selectionInfo['selected_entities'] ?? [];
        $entityConfigs = array_filter(config('entities', []), fn ($c) => in_array($c['graph']['label'] ?? '', $selected, true));
        if (empty($entityConfigs)) {
            return '';
        }

        $validPatterns = $schema['all_relationship_patterns'] ?? $schema['relationshipPatterns'] ?? [];

        $output = self::header('ENTITY RELATIONSHIPS (with directions)');
        $output .= "CRITICAL: Use ONLY these relationship types and directions. Do NOT invent relationship types.\n\n";
        $has = false;
        $shown = [];

        foreach ($entityConfigs as $entityName => $config) {
            $label = $config['graph']['label'] ?? $entityName;
            $relationships = [];
            $fallback = [];
            foreach ($config['graph']['relationships'] ?? [] as $rel) {
                if (self::patternExists($label, $rel, $validPatterns)) {
                    $relationships[] = $rel;
                } else {
                    $fallback[] = $rel;
                }
            }
            $relationships = array_filter($relationships, fn ($rel) => empty($rel['pivot']));
            $fallback = array_filter($fallback, fn ($rel) => empty($rel['pivot'])
                && ($rel['direction'] ?? 'outgoing') !== 'incoming'
                && !empty($rel['foreign_key'])
                && in_array($rel['foreign_key'], $schema['properties'][$label] ?? [], true));

            if (empty($relationships) && empty($fallback)) {
                continue;
            }

            $has = true;
            $output .= "{$label}:\n";
            foreach ($relationships as $rel) {
                $type = $rel['type'];
                $target = $rel['target_label'];
                $direction = $rel['direction'] ?? 'outgoing';
                $signature = $direction === 'incoming' ? "{$target}-{$type}->{$label}" : "{$label}-{$type}->{$target}";
                if (isset($shown[$signature])) {
                    continue;
                }
                $shown[$signature] = true;
                if ($direction === 'incoming') {
                    $output .= "  ({$label})<-[:{$type}]-({$target})\n";
                    $output .= "    Cypher: MATCH (x:{$label})<-[:{$type}]-(y:{$target})\n";
                } else {
                    $output .= "  ({$label})-[:{$type}]->({$target})\n";
                    $output .= "    Cypher: MATCH (x:{$label})-[:{$type}]->(y:{$target})\n";
                }
                if (!empty($rel['description'])) {
                    $output .= "    Description: {$rel['description']}\n";
                }
                $output .= "\n";
            }
            foreach ($fallback as $rel) {
                $type = $rel['type'];
                $target = $rel['target_label'];
                $fk = $rel['foreign_key'];
                $output .= "  Direct FK filter to {$target} (no graph edge shown for :{$type}):\n";
                $output .= "    Property: {$label}.{$fk} stores {$target}.id\n";
                $output .= "    Cypher: MATCH (x:{$label}) WHERE x.{$fk} = <{$target}.id> RETURN x\n";
                $output .= "    Do NOT traverse :{$type} from {$label} to {$target} unless that exact edge is shown in this ENTITY RELATIONSHIPS block.\n\n";
            }
        }

        return $has ? $output : '';
    }

    private static function patternExists(string $label, array $rel, array $patterns): bool
    {
        $direction = $rel['direction'] ?? 'outgoing';
        $from = $direction === 'incoming' ? $rel['target_label'] : $label;
        $to = $direction === 'incoming' ? $label : $rel['target_label'];
        foreach ($patterns as $p) {
            if ($p['type'] === $rel['type'] && $p['from_label'] === $from && $p['to_label'] === $to) {
                return true;
            }
        }

        return false;
    }

    private static function detectedEntities(PipelineContext $context): string
    {
        $detected = $context->entityMetadata['detected_entities'] ?? [];
        $metadata = $context->entityMetadata['entity_metadata'] ?? [];
        if (empty($detected)) {
            return '';
        }

        $output = self::header('DETECTED ENTITIES IN QUESTION');
        foreach ($detected as $entityName) {
            $meta = $metadata[$entityName] ?? [];
            $output .= "{$entityName}:\n";
            if (!empty($meta['description'])) {
                $output .= "  Description: {$meta['description']}\n";
            }
            if (!empty($meta['aliases'])) {
                $output .= '  Also known as: ' . implode(', ', $meta['aliases']) . "\n";
            }
            if (!empty($meta['common_properties'])) {
                $output .= "  Key properties:\n";
                foreach ($meta['common_properties'] as $prop => $desc) {
                    $output .= "    - {$prop}: {$desc}\n";
                }
            }
            $output .= "\n";
        }

        return $output;
    }
}
