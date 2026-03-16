<?php

declare(strict_types=1);

namespace Condoedge\Ai\Security;

use Condoedge\Ai\Domain\Contracts\Nodeable;

/**
 * Resolves security properties and category for an entity record.
 *
 * Reads entity security config and resolves actual values from the model,
 * supporting both direct field access and Eloquent relationship joins.
 */
class EntitySecurityResolver
{
    /**
     * Resolve security properties for a record.
     *
     * Handles 'field' (direct column) and 'join' (via Eloquent relation).
     *
     * @return array e.g. ['_sec_team_id' => 5, '_sec_role_id' => 3, '_category' => 'finance']
     */
    public function resolve(Nodeable $entity, array $securityConfig): array
    {
        $properties = [];
        $axes = $securityConfig['axes'] ?? [];

        foreach ($axes as $axisName => $axisConfig) {
            $value = $this->resolveAxisValue($entity, $axisConfig);

            if ($value !== null) {
                $properties["_sec_{$axisName}_id"] = $value;
            }
        }

        // Resolve category
        $category = $this->resolveCategory($entity, $securityConfig);
        if ($category !== null) {
            $properties['_category'] = $category;
        }

        return $properties;
    }

    /**
     * Resolve category value (static or dynamic).
     */
    public function resolveCategory(Nodeable $entity, array $securityConfig): ?string
    {
        // Static category
        if (isset($securityConfig['category'])) {
            return $securityConfig['category'];
        }

        // Dynamic category from field
        if (isset($securityConfig['category_field'])) {
            $data = $entity->toArray();
            return $data[$securityConfig['category_field']] ?? null;
        }

        return null;
    }

    /**
     * Resolve the value for a single axis from the entity.
     */
    private function resolveAxisValue(Nodeable $entity, array $axisConfig): mixed
    {
        // Direct field access
        if (isset($axisConfig['field'])) {
            $data = $entity->toArray();
            return $data[$axisConfig['field']] ?? null;
        }

        // Join via Eloquent relation (e.g., 'company.union_id')
        if (isset($axisConfig['join'])) {
            return $this->resolveJoinValue($entity, $axisConfig['join']);
        }

        return null;
    }

    /**
     * Resolve a value through Eloquent relationship chain.
     *
     * @param Nodeable $entity
     * @param string $joinPath e.g. 'company.union_id'
     */
    private function resolveJoinValue(Nodeable $entity, string $joinPath): mixed
    {
        $parts = explode('.', $joinPath);
        $column = array_pop($parts);
        $current = $entity;

        // Traverse relations
        foreach ($parts as $relation) {
            if (!method_exists($current, $relation)) {
                return null;
            }

            $current = $current->{$relation};

            if ($current === null) {
                return null;
            }
        }

        // Get the final column value
        if (is_object($current) && isset($current->{$column})) {
            return $current->{$column};
        }

        if (is_array($current) && isset($current[$column])) {
            return $current[$column];
        }

        return null;
    }
}
