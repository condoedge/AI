<?php

declare(strict_types=1);

namespace Condoedge\Ai\Observers;

use Condoedge\Ai\Facades\AI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * RelatedModelSyncObserver
 *
 * Observes related models and triggers re-sync of parent entities when they change.
 * This ensures Neo4j and Qdrant stay in sync when pivot tables or related models
 * are created, updated, or deleted.
 *
 * Configuration in config/ai.php:
 * ```php
 * 'sync_triggers' => [
 *     'Person' => [
 *         'on_related' => ['PersonTeam'],
 *         'foreign_key' => 'person_id',
 *     ],
 * ],
 * ```
 *
 * @package Condoedge\Ai\Observers
 */
class RelatedModelSyncObserver
{
    protected array $syncTriggers;

    public function __construct()
    {
        $this->syncTriggers = config('ai.sync_triggers', []);
    }

    /**
     * Handle model created event
     *
     * @param Model $model
     * @return void
     */
    public function created(Model $model): void
    {
        $this->triggerRelatedSync($model, 'created');
    }

    /**
     * Handle model updated event
     *
     * @param Model $model
     * @return void
     */
    public function updated(Model $model): void
    {
        $this->triggerRelatedSync($model, 'updated');
    }

    /**
     * Handle model deleted event
     *
     * @param Model $model
     * @return void
     */
    public function deleted(Model $model): void
    {
        $this->triggerRelatedSync($model, 'deleted');
    }

    /**
     * Find and trigger syncs for related parent models
     *
     * @param Model $model The model that changed
     * @param string $event The event type (created, updated, deleted)
     * @return void
     */
    protected function triggerRelatedSync(Model $model, string $event): void
    {
        $modelClass = class_basename($model);

        foreach ($this->syncTriggers as $parentEntity => $config) {
            $relatedModels = $config['on_related'] ?? [];

            if (in_array($modelClass, $relatedModels)) {
                $this->syncParentEntity($model, $parentEntity, $config, $event);
            }
        }
    }

    /**
     * Sync the parent entity when related model changes
     *
     * @param Model $relatedModel The related model that changed
     * @param string $parentEntity Parent entity class name
     * @param array $config Sync trigger configuration
     * @param string $event The event type
     * @return void
     */
    protected function syncParentEntity(
        Model $relatedModel,
        string $parentEntity,
        array $config,
        string $event
    ): void {
        // Determine the foreign key to find the parent
        $foreignKey = $config['foreign_key'] ?? strtolower($parentEntity) . '_id';
        $parentId = $relatedModel->getAttribute($foreignKey);

        if (!$parentId) {
            Log::debug("RelatedModelSyncObserver: No parent ID found", [
                'related_model' => class_basename($relatedModel),
                'parent_entity' => $parentEntity,
                'foreign_key' => $foreignKey,
            ]);
            return;
        }

        // Resolve parent model class
        $parentClass = $this->resolveParentClass($parentEntity);
        if (!$parentClass) {
            Log::warning("RelatedModelSyncObserver: Could not resolve parent class", [
                'parent_entity' => $parentEntity,
            ]);
            return;
        }

        // Find the parent model
        $parent = $parentClass::find($parentId);
        if (!$parent) {
            Log::debug("RelatedModelSyncObserver: Parent model not found", [
                'parent_entity' => $parentEntity,
                'parent_id' => $parentId,
            ]);
            return;
        }

        // Check if parent implements Nodeable
        if (!$parent instanceof \Condoedge\Ai\Domain\Contracts\Nodeable) {
            Log::debug("RelatedModelSyncObserver: Parent doesn't implement Nodeable", [
                'parent_class' => $parentClass,
            ]);
            return;
        }

        // Sync the parent's relationships in Neo4j/Qdrant
        try {
            Log::info("RelatedModelSyncObserver: Syncing parent entity", [
                'parent_entity' => $parentEntity,
                'parent_id' => $parentId,
                'triggered_by' => class_basename($relatedModel),
                'event' => $event,
            ]);

            AI::syncRelationships([$parent]);

        } catch (\Throwable $e) {
            Log::error("RelatedModelSyncObserver: Failed to sync parent entity", [
                'parent_entity' => $parentEntity,
                'parent_id' => $parentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve full class name for parent entity
     *
     * @param string $entity Entity name
     * @return string|null Full class name or null if not found
     */
    protected function resolveParentClass(string $entity): ?string
    {
        // Check if it's already a full class name
        if (class_exists($entity)) {
            return $entity;
        }

        $namespaces = config('ai.model_namespaces', ['App\\Models']);

        foreach ($namespaces as $namespace) {
            $fullClass = "{$namespace}\\{$entity}";
            if (class_exists($fullClass)) {
                return $fullClass;
            }
        }

        return null;
    }

    /**
     * Get the configured sync triggers
     *
     * @return array
     */
    public function getSyncTriggers(): array
    {
        return $this->syncTriggers;
    }
}
