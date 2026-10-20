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

        // Already a config key: scope/entity hits from the semantic context
        // index carry the FULL class name (that is what indexing stores in the
        // point metadata). Comparing only basenames dropped those lookups —
        // detected scopes then resolved to an EMPTY config and emitted blank
        // prompt blocks (observed live 2026-07-23: CURRENT_BOARD_MEMBERS).
        if (isset($entityConfigs[$shortName])) {
            return $shortName;
        }

        foreach ($entityConfigs as $fullName => $config) {
            if (strcasecmp(class_basename($fullName), $shortName) === 0
                || strcasecmp($fullName, $shortName) === 0) {
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
