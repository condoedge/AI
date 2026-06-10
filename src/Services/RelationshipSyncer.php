<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Illuminate\Support\Facades\Log;

/**
 * RelationshipSyncer
 *
 * Owns the per-entity (non-batch) graph relationship logic for the ingestion
 * pipeline: creating an entity's relationships right after its node is created,
 * and reconciling missing relationships after a bulk ingest.
 *
 * Extracted from DataIngestionService so relationship handling is a single,
 * focused, independently testable responsibility.
 */
class RelationshipSyncer
{
    private readonly PolymorphicTargetResolver $polymorphicResolver;

    public function __construct(
        private readonly GraphStoreInterface $graphStore,
        ?PolymorphicTargetResolver $polymorphicResolver = null,
    ) {
        $this->polymorphicResolver = $polymorphicResolver ?? new PolymorphicTargetResolver();
    }

    /**
     * Create relationships for an entity.
     *
     * Only creates relationships where the target node exists in Neo4j.
     * Silently skips relationships to non-existent targets to allow flexible
     * ingestion order (e.g., Users before Persons).
     *
     * Use syncRelationships() or 'php artisan ai:sync-relationships' to
     * reconcile missing relationships after all entities are ingested.
     *
     * @param Nodeable $entity Source entity
     * @param GraphConfig $config Graph configuration
     * @return int Number of relationships created
     * @throws \Exception If relationship creation fails
     */
    public function createRelationships(Nodeable $entity, GraphConfig $config): int
    {
        $data = $entity->toArray();
        $count = 0;
        $skipped = 0;

        foreach ($config->relationships as $relationshipConfig) {
            $foreignKeyValue = $data[$relationshipConfig->foreignKey] ?? null;

            // Skip if foreign key is not set or is null
            if ($foreignKeyValue === null) {
                continue;
            }

            // Polymorphic targets: resolve the concrete label from the row's
            // morph-type value, then run the identical pipeline below.
            $targetLabel = $relationshipConfig->targetLabel;
            if ($relationshipConfig->isPolymorphic()) {
                $morphValue = $data[$relationshipConfig->morphTypeColumn()] ?? null;
                $targetLabel = $this->polymorphicResolver->resolveLabel($morphValue);

                if ($targetLabel === null) {
                    Log::debug("Skipping polymorphic relationship: morph value has no catalog label", [
                        'from_label' => $config->label,
                        'from_id' => $entity->getId(),
                        'morph_column' => $relationshipConfig->morphTypeColumn(),
                        'morph_value' => $morphValue,
                        'relationship_type' => $relationshipConfig->type,
                    ]);
                    $skipped++;
                    continue;
                }
            }

            // Check if target node exists before creating relationship
            try {
                $targetExists = $this->graphStore->nodeExists(
                    $targetLabel,
                    $foreignKeyValue
                );

                if (!$targetExists) {
                    Log::debug("Skipping relationship: target node not found", [
                        'from_label' => $config->label,
                        'from_id' => $entity->getId(),
                        'to_label' => $targetLabel,
                        'to_id' => $foreignKeyValue,
                        'relationship_type' => $relationshipConfig->type,
                        'message' => 'Target node will be created later. Run ai:sync-relationships after bulk ingestion.',
                    ]);
                    $skipped++;
                    continue;
                }
            } catch (\Exception $e) {
                Log::warning("Failed to check if target node exists, skipping relationship", [
                    'from_label' => $config->label,
                    'from_id' => $entity->getId(),
                    'to_label' => $targetLabel,
                    'to_id' => $foreignKeyValue,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
                continue;
            }

            // Extract relationship properties if defined
            $relationshipProperties = [];
            if ($relationshipConfig->hasProperties()) {
                foreach ($relationshipConfig->properties as $key => $sourceField) {
                    if (isset($data[$sourceField])) {
                        $relationshipProperties[$key] = $data[$sourceField];
                    }
                }
            }

            try {
                // Check if relationship already exists to avoid duplicates
                $relationshipExists = $this->graphStore->relationshipExists(
                    fromLabel: $config->label,
                    fromId: $entity->getId(),
                    toLabel: $targetLabel,
                    toId: $foreignKeyValue,
                    type: $relationshipConfig->type
                );

                if ($relationshipExists) {
                    Log::debug("Relationship already exists, skipping", [
                        'from_label' => $config->label,
                        'from_id' => $entity->getId(),
                        'to_label' => $targetLabel,
                        'to_id' => $foreignKeyValue,
                        'type' => $relationshipConfig->type,
                    ]);
                    continue;
                }

                $this->graphStore->createRelationship(
                    fromLabel: $config->label,
                    fromId: $entity->getId(),
                    toLabel: $targetLabel,
                    toId: $foreignKeyValue,
                    type: $relationshipConfig->type,
                    properties: $relationshipProperties
                );

                $count++;
            } catch (\Exception $e) {
                Log::warning("Failed to create relationship", [
                    'from_label' => $config->label,
                    'from_id' => $entity->getId(),
                    'to_label' => $targetLabel,
                    'to_id' => $foreignKeyValue,
                    'type' => $relationshipConfig->type,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        if ($skipped > 0) {
            Log::info("Relationships created with some skipped", [
                'entity_class' => get_class($entity),
                'entity_id' => $entity->getId(),
                'created' => $count,
                'skipped' => $skipped,
                'message' => 'Run "php artisan ai:sync-relationships" to create missing relationships',
            ]);
        }

        return $count;
    }

    /**
     * Reconcile relationships for a set of already-ingested entities.
     *
     * @param Nodeable[] $entities Entities to reconcile
     * @return array Summary of the synchronization
     */
    public function syncRelationships(array $entities): array
    {
        $summary = [
            'total_entities' => count($entities),
            'total_relationships_checked' => 0,
            'relationships_created' => 0,
            'relationships_skipped' => 0,
            'relationships_failed' => 0,
            'relationships_unresolved_morphs' => 0,
            'errors' => [],
        ];

        if (empty($entities)) {
            return $summary;
        }

        Log::info("Starting relationship synchronization", [
            'total_entities' => count($entities),
        ]);

        foreach ($entities as $entity) {
            try {
                if (!$entity instanceof Nodeable) {
                    throw new \InvalidArgumentException(
                        'Entity must implement Nodeable interface'
                    );
                }

                $entityId = $entity->getId();
                $graphConfig = $entity->getGraphConfig();
                $data = $entity->toArray();

                // Check if source node exists
                $sourceExists = $this->graphStore->nodeExists($graphConfig->label, $entityId);
                if (!$sourceExists) {
                    Log::warning("Source node does not exist, skipping relationships", [
                        'entity_class' => get_class($entity),
                        'entity_id' => $entityId,
                        'label' => $graphConfig->label,
                    ]);
                    continue;
                }

                // Process each configured relationship
                foreach ($graphConfig->relationships as $relationshipConfig) {
                    $summary['total_relationships_checked']++;

                    $foreignKeyValue = $data[$relationshipConfig->foreignKey] ?? null;

                    // Skip if foreign key is not set
                    if ($foreignKeyValue === null) {
                        continue;
                    }

                    // Polymorphic targets: resolve the concrete label per row.
                    // Unresolved morphs are counted separately from
                    // relationships_failed — "failed" means the target isn't
                    // ingested YET (re-running fixes it); an unmapped morph
                    // value is permanent until config/morph map changes.
                    $targetLabel = $relationshipConfig->targetLabel;
                    if ($relationshipConfig->isPolymorphic()) {
                        $morphValue = $data[$relationshipConfig->morphTypeColumn()] ?? null;
                        $targetLabel = $this->polymorphicResolver->resolveLabel($morphValue);

                        if ($targetLabel === null) {
                            Log::debug("Skipping polymorphic relationship: morph value has no catalog label", [
                                'from_label' => $graphConfig->label,
                                'from_id' => $entityId,
                                'morph_column' => $relationshipConfig->morphTypeColumn(),
                                'morph_value' => $morphValue,
                                'relationship_type' => $relationshipConfig->type,
                            ]);
                            $summary['relationships_unresolved_morphs']++;
                            continue;
                        }
                    }

                    try {
                        // Check if target node exists
                        $targetExists = $this->graphStore->nodeExists(
                            $targetLabel,
                            $foreignKeyValue
                        );

                        if (!$targetExists) {
                            Log::debug("Target node still doesn't exist", [
                                'from_label' => $graphConfig->label,
                                'from_id' => $entityId,
                                'to_label' => $targetLabel,
                                'to_id' => $foreignKeyValue,
                            ]);
                            $summary['relationships_failed']++;
                            continue;
                        }

                        // Check if relationship already exists
                        $relationshipExists = $this->graphStore->relationshipExists(
                            fromLabel: $graphConfig->label,
                            fromId: $entityId,
                            toLabel: $targetLabel,
                            toId: $foreignKeyValue,
                            type: $relationshipConfig->type
                        );

                        if ($relationshipExists) {
                            Log::debug("Relationship already exists", [
                                'from_label' => $graphConfig->label,
                                'from_id' => $entityId,
                                'to_label' => $targetLabel,
                                'to_id' => $foreignKeyValue,
                                'type' => $relationshipConfig->type,
                            ]);
                            $summary['relationships_skipped']++;
                            continue;
                        }

                        // Extract relationship properties
                        $relationshipProperties = [];
                        if ($relationshipConfig->hasProperties()) {
                            foreach ($relationshipConfig->properties as $key => $sourceField) {
                                if (isset($data[$sourceField])) {
                                    $relationshipProperties[$key] = $data[$sourceField];
                                }
                            }
                        }

                        // Create the relationship
                        $this->graphStore->createRelationship(
                            fromLabel: $graphConfig->label,
                            fromId: $entityId,
                            toLabel: $targetLabel,
                            toId: $foreignKeyValue,
                            type: $relationshipConfig->type,
                            properties: $relationshipProperties
                        );

                        Log::info("Relationship created during sync", [
                            'from_label' => $graphConfig->label,
                            'from_id' => $entityId,
                            'to_label' => $targetLabel,
                            'to_id' => $foreignKeyValue,
                            'type' => $relationshipConfig->type,
                        ]);

                        $summary['relationships_created']++;

                    } catch (\Exception $e) {
                        Log::error("Failed to sync relationship", [
                            'from_label' => $graphConfig->label,
                            'from_id' => $entityId,
                            'to_label' => $targetLabel,
                            'to_id' => $foreignKeyValue,
                            'type' => $relationshipConfig->type,
                            'error' => $e->getMessage(),
                        ]);

                        $summary['relationships_failed']++;
                        $summary['errors'][$entityId][] = sprintf(
                            "Failed to create %s relationship to %s:%s - %s",
                            $relationshipConfig->type,
                            $targetLabel,
                            $foreignKeyValue,
                            $e->getMessage()
                        );
                    }
                }

            } catch (\Exception $e) {
                Log::error("Failed to sync relationships for entity", [
                    'entity_class' => get_class($entity),
                    'entity_id' => $entity->getId(),
                    'error' => $e->getMessage(),
                ]);

                $summary['errors'][$entity->getId()][] = $e->getMessage();
            }
        }

        Log::info("Relationship synchronization completed", $summary);

        return $summary;
    }
}
