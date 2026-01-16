<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Domain\Traits\ResolvesEntityConfigKey;

/**
 * Resolves team_path config to Cypher filter clauses.
 */
class TeamPathResolver
{
    use ResolvesEntityConfigKey;
    /**
     * Resolve team path config to Cypher filter.
     *
     * @param string $entity Entity name
     * @param string $alias Cypher alias for entity (e.g., 'i' for Invoice)
     * @param array<int> $teamIds Team IDs to filter by
     * @return array{type: string, filter: string}|null
     */
    public function resolve(string $entity, string $alias, array $teamIds): ?array
    {
        $teamPath = $this->getTeamPath($entity);

        if ($teamPath === null) {
            return null;
        }

        $teamIdList = implode(', ', $teamIds);

        // Direct column: 'team_id'
        if (is_string($teamPath) && !str_contains($teamPath, '.') && $teamPath !== 'auto') {
            return [
                'type' => 'direct',
                'filter' => "WHERE {$alias}.{$teamPath} IN [{$teamIdList}]",
            ];
        }

        // Auto-detect from relationships
        if ($teamPath === 'auto') {
            return $this->autoDetect($entity, $alias, $teamIds);
        }

        // Parent path: 'invoice.team_id'
        if (is_string($teamPath) && str_contains($teamPath, '.')) {
            return $this->resolveParentPath($entity, $alias, $teamPath, $teamIds);
        }

        // Relationship path: ['rel' => 'MEMBER_OF', 'target' => 'Team']
        if (is_array($teamPath) && isset($teamPath['rel'])) {
            return [
                'type' => 'relationship',
                'filter' => "MATCH ({$alias})-[:{$teamPath['rel']}]->(t:{$teamPath['target']}) WHERE t.id IN [{$teamIdList}]",
            ];
        }

        return null;
    }

    /**
     * Get team_path from config with fallback to default.
     */
    private function getTeamPath(string $entity): mixed
    {
        $configKey = $this->getEntityConfigKey($entity);
        $entityConfig = config("entities.{$configKey}.security.team_path");

        if ($entityConfig !== null) {
            return $entityConfig;
        }

        // Use default if not configured
        return config('ai.security.default_team_path', 'auto');
    }

    /**
     * Auto-detect team path from relationships config.
     */
    private function autoDetect(string $entity, string $alias, array $teamIds): ?array
    {
        $configKey = $this->getEntityConfigKey($entity);
        $relationships = config("entities.{$configKey}.graph.relationships", []);

        // Find relationship to Team
        $teamRel = collect($relationships)
            ->first(fn($r) => ($r['target_label'] ?? null) === 'Team');

        if (!$teamRel) {
            return null;
        }

        $teamIdList = implode(', ', $teamIds);

        // Has foreign_key = direct filter
        if (isset($teamRel['foreign_key'])) {
            return [
                'type' => 'direct',
                'filter' => "WHERE {$alias}.{$teamRel['foreign_key']} IN [{$teamIdList}]",
            ];
        }

        // No foreign_key = relationship traversal
        return [
            'type' => 'relationship',
            'filter' => "MATCH ({$alias})-[:{$teamRel['type']}]->(t:Team) WHERE t.id IN [{$teamIdList}]",
        ];
    }

    /**
     * Resolve parent path like 'invoice.team_id'.
     */
    private function resolveParentPath(string $entity, string $alias, string $path, array $teamIds): ?array
    {
        [$parentKey, $column] = explode('.', $path, 2);
        $parentEntity = ucfirst($parentKey);

        // Find relationship to parent
        $configKey = $this->getEntityConfigKey($entity);
        $relationships = config("entities.{$configKey}.graph.relationships", []);
        $parentRel = collect($relationships)
            ->first(fn($r) => ($r['target_label'] ?? null) === $parentEntity);

        if (!$parentRel) {
            return null;
        }

        $parentAlias = strtolower(substr($parentEntity, 0, 1));
        $teamIdList = implode(', ', $teamIds);

        return [
            'type' => 'parent',
            'filter' => "MATCH ({$alias})-[:{$parentRel['type']}]->({$parentAlias}:{$parentEntity}) WHERE {$parentAlias}.{$column} IN [{$teamIdList}]",
        ];
    }
}
