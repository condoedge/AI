<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\SemanticSelectionResult;
use Condoedge\Ai\Support\AiLogger;

/**
 * Projects a relevance-filtered slice of the full graph schema, either from a
 * SemanticSelectionResult or from a label set, plus the embedding-based
 * entity-label fallback used when no semantic selection is available.
 */
class SchemaProjector
{
    private ?array $categoryMap = null;

    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly AiLogger $logger,
        private readonly array $entityConfigs = [],
    ) {
    }

    /**
     * Filter schema based on semantic relevance.
     *
     * Only includes labels, relationships, and properties that are relevant
     * to the question based on semantic matching.
     */
    public function filterByRelevance(array $fullSchema, SemanticSelectionResult $semanticContext): array
    {
        $relevantLabels = [];
        $relevantRelationships = [];
        $relevantProperties = [];
        $relevantPropertyKeys = [];

        foreach ($semanticContext->entities as $entityName => $entityInfo) {
            $shortName = class_basename($entityName);
            $relevantLabels[] = $shortName;

            if (isset($fullSchema['properties'][$shortName])) {
                $relevantProperties[$shortName] = $fullSchema['properties'][$shortName];
                // Also add to property keys list for flat access
                foreach ($fullSchema['properties'][$shortName] as $prop) {
                    $relevantPropertyKeys[] = $prop;
                }
            }
        }

        foreach ($semanticContext->relationships as $relKey => $relInfo) {
            $relevantRelationships[] = $relInfo->type ?: $relKey;

            // Ensure connected entities are included
            if (!empty($relInfo->fromEntity) && !in_array($relInfo->fromEntity, $relevantLabels)) {
                $relevantLabels[] = $relInfo->fromEntity;
                if (isset($fullSchema['properties'][$relInfo->fromEntity])) {
                    $relevantProperties[$relInfo->fromEntity] = $fullSchema['properties'][$relInfo->fromEntity];
                    foreach ($fullSchema['properties'][$relInfo->fromEntity] as $prop) {
                        $relevantPropertyKeys[] = $prop;
                    }
                }
            }
            if (!empty($relInfo->toEntity) && !in_array($relInfo->toEntity, $relevantLabels)) {
                $relevantLabels[] = $relInfo->toEntity;
                if (isset($fullSchema['properties'][$relInfo->toEntity])) {
                    $relevantProperties[$relInfo->toEntity] = $fullSchema['properties'][$relInfo->toEntity];
                    foreach ($fullSchema['properties'][$relInfo->toEntity] as $prop) {
                        $relevantPropertyKeys[] = $prop;
                    }
                }
            }
        }

        $filteredLabels = array_values(array_intersect(
            $fullSchema['labels'] ?? [],
            $relevantLabels
        ));

        $filteredRelationships = !empty($relevantRelationships)
            ? array_values(array_intersect(
                $fullSchema['relationships'] ?? [],
                $relevantRelationships
            ))
            : [];

        $relevantPropertyKeys = array_values(array_unique($relevantPropertyKeys));

        return [
            'labels' => $filteredLabels,
            'relationships' => $filteredRelationships,
            'properties' => $relevantProperties,
            'propertyKeys' => $relevantPropertyKeys,
        ];
    }

    /**
     * Filter schema to only include specific labels.
     */
    public function filterByLabels(array $schema, array $labels): array
    {
        $filtered = $schema;

        // Filter properties to only include matched labels
        if (!empty($filtered['properties'])) {
            $filtered['properties'] = array_filter(
                $filtered['properties'],
                fn($label) => in_array($label, $labels),
                ARRAY_FILTER_USE_KEY
            );
        }

        // Filter labels list
        if (!empty($filtered['labels'])) {
            $filtered['labels'] = array_values(array_filter(
                $filtered['labels'],
                fn($label) => in_array($label, $labels)
            ));
        }

        return $filtered;
    }

    /**
     * Find relevant entity labels using the semantic_entities Qdrant collection.
     * Used as a fallback when context_index is unavailable.
     */
    public function findRelevantEntityLabels(string $question): array
    {
        try {
            $embedding = $this->embeddingProvider->embed($question);
            $results = $this->vectorStore->search('semantic_entities', $embedding, 10);

            $labels = [];
            foreach ($results as $result) {
                $raw = $result['payload']['label'] ?? $result['payload']['entity']
                    ?? $result['metadata']['label'] ?? $result['metadata']['entity'] ?? null;
                if (!$raw) {
                    continue;
                }
                // Normalize: if FQCN (App\Models\Team), extract short name (Team)
                $label = class_basename($raw);
                if (!in_array($label, $labels)) {
                    $labels[] = $label;
                }
            }

            $labels = $this->expandWithRelatedLabels($labels);

            return array_slice($labels, 0, 15);
        } catch (\Exception $e) {
            $this->logger->error('context_retriever', 'findRelevantEntityLabels failed: ' . $e->getMessage());
            $this->logger->fallback('context_retriever', 'semantic_entity_labels', 'full_schema', $e->getMessage());
            return []; // Fall back to full schema
        }
    }

    /**
     * Expand matched labels with closely related entities (same category).
     */
    private function expandWithRelatedLabels(array $labels): array
    {
        $categoryMap = $this->getCategoryMap();
        $expanded = $labels;

        foreach ($labels as $label) {
            foreach ($categoryMap as $category => $categoryLabels) {
                if (in_array($label, $categoryLabels)) {
                    foreach ($categoryLabels as $relatedLabel) {
                        if (!in_array($relatedLabel, $expanded)) {
                            $expanded[] = $relatedLabel;
                        }
                    }
                    break;
                }
            }
        }

        return $expanded;
    }

    /**
     * Get category map from entity configs (derived from FQCN namespace).
     */
    private function getCategoryMap(): array
    {
        if ($this->categoryMap !== null) {
            return $this->categoryMap;
        }

        $map = [];
        foreach ($this->entityConfigs as $fqcn => $config) {
            $parts = explode('\\', $fqcn);
            $category = (count($parts) > 3) ? $parts[2] : 'General';
            $label = $config['graph']['label'] ?? class_basename($fqcn);
            $map[$category][] = $label;
        }

        $this->categoryMap = $map;

        return $map;
    }
}
