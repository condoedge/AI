<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Domain\Traits\ResolvesEntityConfigKey;
use Condoedge\Ai\DTOs\SemanticSelectionResult;
use Condoedge\Ai\Services\ScopeSemanticMatcher;

/**
 * Builds the detected-entity / detected-scope / entity-metadata structure for a
 * question, either from a SemanticSelectionResult (primary path) or via the
 * scope-matcher + string fallback (when no semantic selection is available).
 */
class EntityMetadataBuilder
{
    use ResolvesEntityConfigKey;

    public function __construct(
        private readonly array $entityConfigs = [],
        private readonly ?ScopeSemanticMatcher $scopeMatcher = null,
    ) {
    }

    /**
     * Build metadata from semantic selection results.
     */
    public function fromSemanticSelection(string $question, SemanticSelectionResult $semanticContext): array
    {
        $detectedEntities = [];
        $detectedScopes = [];
        $entityMetadata = [];

        foreach ($semanticContext->entities as $entityName => $entityInfo) {
            $detectedEntities[] = $entityName;

            if (!empty($entityInfo->config['metadata'])) {
                $entityMetadata[$entityName] = $entityInfo->config['metadata'];
            } elseif (isset($this->entityConfigs[$entityName]['metadata'])) {
                $entityMetadata[$entityName] = $this->entityConfigs[$entityName]['metadata'];
            }
        }

        // Semantic-selection scopes come from the context_index (cross-entity). Scope matching
        // relies on that index plus the scope_examples collections; the QU layer narrows scopes
        // to the relevant entity types. No hardcoded stop-word lists are used.
        foreach ($semanticContext->scopes as $scopeName => $scopeInfo) {
            $entityName = $scopeInfo->entity;
            $fullEntityName = $this->findFullEntityName($entityName, $this->entityConfigs);

            $scopeConfig = [];
            if ($fullEntityName && isset($this->entityConfigs[$fullEntityName]['metadata']['scopes'][$scopeName])) {
                $scopeConfig = $this->entityConfigs[$fullEntityName]['metadata']['scopes'][$scopeName];
            }

            $detectedScopes[$scopeName] = $this->buildScopeData(
                $scopeName,
                array_merge($scopeConfig, [
                    'match_score' => $scopeInfo->score,
                    'match_type' => 'semantic',
                    'matched_example' => $scopeInfo->matchedText,
                ]),
                $fullEntityName ?: $entityName
            );

            if ($fullEntityName && !in_array($fullEntityName, $detectedEntities)) {
                $detectedEntities[] = $fullEntityName;
                if (isset($this->entityConfigs[$fullEntityName]['metadata'])) {
                    $entityMetadata[$fullEntityName] = $this->entityConfigs[$fullEntityName]['metadata'];
                }
            }
        }

        return [
            'detected_entities' => array_values(array_unique($detectedEntities)),
            'entity_metadata' => $entityMetadata,
            'detected_scopes' => $detectedScopes,
        ];
    }

    /**
     * Get entity metadata for relevant entities detected in the question.
     *
     * Detection strategy: entity labels, entity aliases, then scope terms.
     */
    public function fromQuestion(string $question): array
    {
        $questionLower = strtolower($question);
        $detectedEntities = [];
        $detectedScopes = [];
        $entityMetadata = [];

        if ($this->scopeMatcher !== null) {
            $semanticScopes = $this->scopeMatcher->findMatchingScopes(
                $question,
                $this->entityConfigs,
                config('ai.semantic_matching.scope_threshold', 0.7),
                config('ai.semantic_matching.max_scopes', 5)
            );

            foreach ($semanticScopes as $scopeName => $scopeData) {
                $entityName = $scopeData['entity'] ?? '';

                $fullEntityName = $this->findFullEntityName($entityName, $this->entityConfigs);

                if ($fullEntityName) {
                    $detectedEntities[] = $fullEntityName;
                    $entityMetadata[$fullEntityName] = $this->entityConfigs[$fullEntityName]['metadata'] ?? [];
                }

                $detectedScopes[$scopeName] = $this->buildScopeData($scopeName, $scopeData, $fullEntityName ?: $entityName);
            }
        }

        // Also do string-based detection for entities (semantic matching is for scopes)
        foreach ($this->entityConfigs as $entityName => $config) {
            $metadata = $config['metadata'] ?? null;

            if (!$metadata) {
                continue;
            }

            // Skip if already detected via semantic matching
            if (in_array($entityName, $detectedEntities)) {
                continue;
            }

            $isDetected = false;

            $shortName = class_basename($entityName);
            if (stripos($question, $shortName) !== false) {
                $isDetected = true;
            }

            if (!$isDetected && !empty($metadata['aliases'])) {
                foreach ($metadata['aliases'] as $alias) {
                    if (strpos($questionLower, strtolower($alias)) !== false) {
                        $isDetected = true;
                        break;
                    }
                }
            }

            // String-based scope detection (fallback when semantic matcher not available or didn't find)
            if (!empty($metadata['scopes']) && $this->scopeMatcher === null) {
                foreach ($metadata['scopes'] as $scopeName => $scopeConfig) {
                    // Skip numeric keys (malformed config)
                    if (is_numeric($scopeName)) {
                        continue;
                    }

                    if (strpos($questionLower, strtolower($scopeName)) !== false) {
                        $isDetected = true;

                        // Only add if not already detected semantically
                        if (!isset($detectedScopes[$scopeName])) {
                            $detectedScopes[$scopeName] = $this->buildScopeData($scopeName, $scopeConfig, $entityName);
                        }
                    }
                }
            }

            if ($isDetected) {
                $detectedEntities[] = $entityName;
                $entityMetadata[$entityName] = $metadata;
            }
        }

        $detectedEntities = array_unique($detectedEntities);

        return [
            'detected_entities' => array_values($detectedEntities),
            'entity_metadata' => $entityMetadata,
            'detected_scopes' => $detectedScopes,
        ];
    }

    /**
     * Get metadata for all configured entities that declare it.
     *
     * @return array All entity metadata indexed by entity name
     */
    public function all(): array
    {
        $allMetadata = [];

        foreach ($this->entityConfigs as $entityName => $config) {
            if (isset($config['metadata'])) {
                $allMetadata[$entityName] = $config['metadata'];
            }
        }

        return $allMetadata;
    }

    /**
     * Build standardized scope data array.
     */
    private function buildScopeData(string $scopeName, array $scopeConfig, string $entityName): array
    {
        return [
            'entity' => class_basename($entityName),
            'scope' => $scopeName,
            'description' => $scopeConfig['description'] ?? '',
            'specification_type' => $scopeConfig['specification_type'] ?? 'property_filter',
            'concept' => $scopeConfig['concept'] ?? ($scopeConfig['description'] ?? ''),
            'relationship_spec' => $scopeConfig['relationship_spec'] ?? null,
            'parsed_structure' => $scopeConfig['parsed_structure'] ?? null,
            'filter' => $scopeConfig['filter'] ?? null,
            'pattern' => $scopeConfig['pattern'] ?? null,
            'pattern_params' => $scopeConfig['pattern_params'] ?? null,
            'business_rules' => $scopeConfig['business_rules'] ?? [],
            'examples' => $scopeConfig['examples'] ?? [],
            'role_value' => $scopeConfig['role_value'] ?? null,
            'cypher_pattern' => $scopeConfig['cypher_pattern'] ?? '',
            'match_score' => $scopeConfig['match_score'] ?? null,
            'match_type' => $scopeConfig['match_type'] ?? 'string',
            'matched_example' => $scopeConfig['matched_example'] ?? null,
        ];
    }
}
