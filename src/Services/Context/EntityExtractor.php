<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

/**
 * EntityExtractor
 *
 * Structural extraction of entity labels and relationship types from an
 * EXECUTED Cypher query — used to record what a turn actually queried in the
 * conversation snapshot.
 *
 * It deliberately does NOT analyse natural-language questions (Phase 2.B): the
 * entities, follow-up judgment and intent of a question are produced by the
 * Query Understanding LLM call, never by substring/word-list matching.
 */
class EntityExtractor
{
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
}
