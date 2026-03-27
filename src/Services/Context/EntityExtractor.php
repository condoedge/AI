<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Illuminate\Support\Str;

/**
 * EntityExtractor
 *
 * Extracts entity types and query patterns from questions and Cypher queries.
 * Used to track what entities are being discussed in a conversation.
 */
class EntityExtractor
{
    /**
     * Query type patterns - ordered from most specific to least specific
     * Aggregate is checked before count since "total" can appear in both
     */
    private array $queryTypePatterns = [
        'aggregate' => '/\b(sum|total|average|avg|max|min|revenue)\b/i',
        'count' => '/\b(how many|count|number of|combien)\b/i',
        'list' => '/\b(show|list|display|get|find|all|montre|affiche|donne)\b/i',
        'detail' => '/\b(detail|specific|particular|information about)\b/i',
        'compare' => '/\b(compare|versus|vs|difference|between)\b/i',
    ];

    /**
     * Extract entities and query type from a natural language question
     *
     * @param string $question The user's question
     * @param array $schema Schema with 'labels' key containing available entity types
     * @param array $entityConfigs Optional entity configs for alias matching
     * @return array{focused_entity: ?string, query_type: ?string, mentioned_entities: array}
     */
    public function extractFromQuestion(string $question, array $schema, array $entityConfigs = []): array
    {
        $labels = $schema['labels'] ?? [];
        $questionLower = strtolower($question);

        $mentionedEntities = [];
        $focusedEntity = null;

        // Build alias→label map from entity configs for multilingual matching
        $aliasMap = $this->buildAliasMap($labels, $entityConfigs);

        // First pass: match against graph labels (English)
        foreach ($labels as $label) {
            $labelLower = strtolower($label);
            $pluralLower = Str::plural($labelLower);

            if (str_contains($questionLower, $labelLower) || str_contains($questionLower, $pluralLower)) {
                $mentionedEntities[] = $label;

                if ($focusedEntity === null) {
                    $focusedEntity = $label;
                }
            }
        }

        // Second pass: if no entities found via labels, try aliases (multilingual)
        if (empty($mentionedEntities) && !empty($aliasMap)) {
            foreach ($aliasMap as $alias => $label) {
                if (str_contains($questionLower, $alias)) {
                    if (!in_array($label, $mentionedEntities)) {
                        $mentionedEntities[] = $label;
                    }
                    if ($focusedEntity === null) {
                        $focusedEntity = $label;
                    }
                }
            }
        }

        // Detect query type
        $queryType = $this->detectQueryType($question);

        return [
            'focused_entity' => $focusedEntity,
            'query_type' => $queryType,
            'mentioned_entities' => array_unique($mentionedEntities),
        ];
    }

    /**
     * Build a map of lowercase aliases to their graph labels.
     * Only includes aliases that are 3+ chars to avoid false positives.
     */
    private function buildAliasMap(array $labels, array $entityConfigs): array
    {
        $aliasMap = [];

        foreach ($entityConfigs as $entityClass => $config) {
            $label = $config['graph']['label'] ?? class_basename($entityClass);

            if (!in_array($label, $labels)) {
                continue;
            }

            foreach ($config['metadata']['aliases'] ?? [] as $alias) {
                $aliasLower = strtolower($alias);
                // Skip very short aliases (< 3 chars) to avoid false matches
                if (strlen($aliasLower) >= 3) {
                    $aliasMap[$aliasLower] = $label;
                }
            }
        }

        return $aliasMap;
    }

    /**
     * Extract entities and relationships from a Cypher query
     *
     * @param string $cypher The Cypher query
     * @return array{entities: array, relationships: array}
     */
    public function extractFromCypher(string $cypher): array
    {
        $entities = [];
        $relationships = [];

        // Extract node labels (e.g., :Customer, :Order)
        if (preg_match_all('/\(:?(\w+)\)|\[:\s*(\w+)\s*\]|:(\w+)\s*[{)\]]/', $cypher, $matches)) {
            foreach ($matches[1] as $match) {
                if (!empty($match) && $match !== strtolower($match)) {
                    $entities[] = $match;
                }
            }
        }

        // More precise label extraction
        if (preg_match_all('/\((\w+):(\w+)/', $cypher, $nodeMatches)) {
            foreach ($nodeMatches[2] as $label) {
                $entities[] = $label;
            }
        }

        // Extract relationship types (e.g., [:PLACED], -[:HAS]->)
        if (preg_match_all('/\[:(\w+)\]/', $cypher, $relMatches)) {
            $relationships = $relMatches[1];
        }

        return [
            'entities' => array_unique($entities),
            'relationships' => array_unique($relationships),
        ];
    }

    /**
     * Detect the type of query from the question
     */
    private function detectQueryType(string $question): ?string
    {
        foreach ($this->queryTypePatterns as $type => $pattern) {
            if (preg_match($pattern, $question)) {
                return $type;
            }
        }

        return null;
    }
}
