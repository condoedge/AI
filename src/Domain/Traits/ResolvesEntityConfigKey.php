<?php

declare(strict_types=1);

namespace Condoedge\Ai\Domain\Traits;

/**
 * Provides entity config key resolution.
 *
 * Config keys are namespaced (App\Models\Person) but we often receive
 * short names (Person) from Cypher queries or other sources.
 */
trait ResolvesEntityConfigKey
{
    /**
     * Find full namespaced entity name from short name.
     *
     * @param string $shortName Short entity name (e.g., 'Person')
     * @param array|null $entityConfigs Entity configs (defaults to config('entities'))
     * @return string|null Full namespaced name or null if not found
     */
    protected function findFullEntityName(string $shortName, ?array $entityConfigs = null): ?string
    {
        $entityConfigs ??= config('entities', []);

        foreach ($entityConfigs as $fullName => $config) {
            if (strcasecmp(class_basename($fullName), $shortName) === 0) {
                return $fullName;
            }
        }

        return null;
    }

    /**
     * Get the config key for an entity (resolves short name to full if needed).
     *
     * @param string $entity Entity name (short or full)
     * @return string Config key to use
     */
    protected function getEntityConfigKey(string $entity): string
    {
        return $this->findFullEntityName($entity) ?? $entity;
    }
}
