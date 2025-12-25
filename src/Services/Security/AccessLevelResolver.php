<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * AccessLevelResolver
 *
 * Resolves multi-level access tags for RAG queries based on user permissions.
 * Integrates with Kompo Auth package to determine what data the AI can reveal.
 *
 * Access Levels (in order of increasing sensitivity):
 * - Level 0: global_count     - Total counts, no filters (public)
 * - Level 1: team_count       - Counts within user's teams
 * - Level 2: team_filtered_count - Filtered counts (with threshold protection)
 * - Level 3: team_details     - Record data (excluding sensibleColumns)
 * - Level 4: team_sensitive   - Record data (including sensibleColumns)
 *
 * @package Condoedge\Ai\Services\Security
 */
class AccessLevelResolver
{
    /**
     * Access levels in order of increasing sensitivity
     */
    protected const LEVELS = [
        'global_count',        // Level 0: Anyone
        'team_count',          // Level 1: Team member
        'team_filtered_count', // Level 2: READ permission
        'team_details',        // Level 3: READ permission
        'team_sensitive',      // Level 4: sensibleColumns permission
    ];

    /**
     * Resolve access tags for a user and entity
     *
     * @param Authenticatable|null $user User to check permissions for
     * @param string $entityClass Entity class name or short name
     * @return array List of access tags the user has
     */
    public function resolveForEntity(?Authenticatable $user, string $entityClass): array
    {
        $entity = class_basename($entityClass);
        $tags = [];

        // Level 0: Everyone gets global counts
        $tags[] = "{$entity}_global_count";

        if (!$user) {
            return $tags;
        }

        // Level 1: Team members get team counts
        if ($this->userHasTeamAccess($user)) {
            $tags[] = "{$entity}_team_count";
        }

        // Level 2 & 3: READ permission gets filtered counts and details
        if ($this->userHasPermission($user, $entity, 'read')) {
            $tags[] = "{$entity}_team_filtered_count";
            $tags[] = "{$entity}_team_details";
        }

        // Level 4: sensibleColumns permission gets sensitive data
        if ($this->userHasPermission($user, "{$entity}.sensibleColumns", 'read')) {
            $tags[] = "{$entity}_team_sensitive";
        }

        return $tags;
    }

    /**
     * Build full context for entity including tags, thresholds, and restrictions
     *
     * @param Authenticatable|null $user User to check permissions for
     * @param string $entityClass Entity class name
     * @return array Complete access context
     */
    public function buildContextForEntity(?Authenticatable $user, string $entityClass): array
    {
        $entity = class_basename($entityClass);
        $model = $this->resolveModel($entityClass);

        return [
            'entity' => $entity,
            'access_tags' => $this->resolveForEntity($user, $entityClass),
            'threshold' => $this->getThreshold($entity),
            'sensible_columns' => $this->getSensibleColumns($model),
            'identifying_fields' => $this->getIdentifyingFields($entity),
            'user_teams' => $user ? $this->getUserTeamIds($user) : [],
        ];
    }

    /**
     * Get count threshold for an entity
     *
     * Threshold protects against identifying individuals through specific counts.
     * If a filtered count is below the threshold, we say "fewer than N" instead.
     *
     * @param string $entity Entity name
     * @return int Threshold value
     */
    public function getThreshold(string $entity): int
    {
        return config("ai.access_control.thresholds.{$entity}")
            ?? config('ai.access_control.default_threshold', 5);
    }

    /**
     * Get sensible columns from model
     *
     * @param object|null $model Model instance
     * @return array List of sensible column names
     */
    protected function getSensibleColumns(?object $model): array
    {
        if (!$model) {
            return [];
        }

        if (property_exists($model, 'sensibleColumns')) {
            $reflection = new \ReflectionProperty($model, 'sensibleColumns');
            $reflection->setAccessible(true);
            return $reflection->getValue($model) ?? [];
        }

        return [];
    }

    /**
     * Get identifying fields for an entity
     *
     * Identifying fields are those that, when used in filters,
     * could potentially identify specific individuals.
     *
     * @param string $entity Entity name
     * @return array List of identifying field names
     */
    protected function getIdentifyingFields(string $entity): array
    {
        $global = config('ai.access_control.identifying_fields.*', []);
        $specific = config("ai.access_control.identifying_fields.{$entity}", []);

        return array_unique(array_merge($global, $specific));
    }

    /**
     * Check if user has team access
     *
     * @param Authenticatable $user User to check
     * @return bool True if user has any team access
     */
    protected function userHasTeamAccess(Authenticatable $user): bool
    {
        if (method_exists($user, 'currentTeamId')) {
            return $user->currentTeamId() !== null;
        }

        if (method_exists($user, 'teams')) {
            return $user->teams()->exists();
        }

        return false;
    }

    /**
     * Check if user has permission
     *
     * @param Authenticatable $user User to check
     * @param string $key Permission key (e.g., "Person" or "Person.sensibleColumns")
     * @param string $type Permission type (e.g., "read", "write")
     * @return bool True if user has permission
     */
    protected function userHasPermission(Authenticatable $user, string $key, string $type): bool
    {
        if (!method_exists($user, 'hasPermission')) {
            return false;
        }

        return $user->hasPermission($key, $type);
    }

    /**
     * Get user's accessible team IDs
     *
     * @param Authenticatable $user User to get teams for
     * @return array List of team IDs
     */
    protected function getUserTeamIds(Authenticatable $user): array
    {
        if (method_exists($user, 'getAccessibleTeamIds')) {
            return $user->getAccessibleTeamIds();
        }

        if (method_exists($user, 'teams')) {
            return $user->teams()->pluck('id')->toArray();
        }

        $currentTeamId = method_exists($user, 'currentTeamId')
            ? $user->currentTeamId()
            : null;

        return $currentTeamId ? [$currentTeamId] : [];
    }

    /**
     * Resolve model class from entity name
     *
     * @param string $entityClass Entity class name or short name
     * @return object|null Model instance or null if not found
     */
    protected function resolveModel(string $entityClass): ?object
    {
        if (class_exists($entityClass)) {
            return new $entityClass;
        }

        // Try common namespaces
        $namespaces = config('ai.model_namespaces', ['App\\Models']);
        foreach ($namespaces as $namespace) {
            $fullClass = "{$namespace}\\{$entityClass}";
            if (class_exists($fullClass)) {
                return new $fullClass;
            }
        }

        return null;
    }

    /**
     * Check if user has access to a specific level
     *
     * @param Authenticatable|null $user User to check
     * @param string $entity Entity name
     * @param string $level Access level (e.g., 'team_details')
     * @return bool True if user has access to this level
     */
    public function hasAccessLevel(?Authenticatable $user, string $entity, string $level): bool
    {
        $tags = $this->resolveForEntity($user, $entity);
        return in_array("{$entity}_{$level}", $tags);
    }
}
