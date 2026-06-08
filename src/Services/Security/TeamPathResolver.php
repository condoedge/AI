<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Domain\Traits\ResolvesEntityConfigKey;

/**
 * Resolves team_path config to Cypher filter clauses.
 *
 * Team IDs are emitted as a single bound Cypher parameter ($ai_team_ids)
 * rather than string-concatenated into an `IN [...]` literal, eliminating the
 * splice-injection surface. The IDs are also validated as integers as a
 * defence-in-depth backstop.
 */
class TeamPathResolver
{
    use ResolvesEntityConfigKey;

    /**
     * Cypher parameter name used to bind the permitted team IDs.
     */
    public const TEAM_IDS_PARAM = 'ai_team_ids';

    /**
     * Resolve team path config to Cypher filter.
     *
     * The returned `filter` references the bound parameter `$ai_team_ids`; the
     * caller binds the (validated, integer) IDs via the `parameters` key.
     *
     * @param string $entity Entity name
     * @param string $alias Cypher alias for entity (e.g., 'i' for Invoice)
     * @param array<int> $teamIds Team IDs to filter by
     * @return array{type: string, filter: string, parameters: array<string, array<int>>}|null
     */
    public function resolve(string $entity, string $alias, array $teamIds): ?array
    {
        $teamPath = $this->getTeamPath($entity);

        if ($teamPath === null) {
            return null;
        }

        // Defence-in-depth: never trust the IDs as anything but integers.
        $teamIds = $this->normalizeTeamIds($teamIds);
        $param = '$' . self::TEAM_IDS_PARAM;
        $parameters = [self::TEAM_IDS_PARAM => $teamIds];

        // Direct column: 'team_id'
        if (is_string($teamPath) && !str_contains($teamPath, '.') && $teamPath !== 'auto') {
            return [
                'type' => 'direct',
                'filter' => "WHERE {$alias}.{$teamPath} IN {$param}",
                'parameters' => $parameters,
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
                'filter' => "MATCH ({$alias})-[:{$teamPath['rel']}]->(t:{$teamPath['target']}) WHERE t.id IN {$param}",
                'parameters' => $parameters,
            ];
        }

        return null;
    }

    /**
     * Coerce team IDs to a clean list of integers (drops non-numeric values).
     *
     * @param array<int> $teamIds
     * @return array<int>
     */
    private function normalizeTeamIds(array $teamIds): array
    {
        return array_values(array_map(
            'intval',
            array_filter($teamIds, fn($id) => is_numeric($id))
        ));
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

        $param = '$' . self::TEAM_IDS_PARAM;
        $parameters = [self::TEAM_IDS_PARAM => $teamIds];

        // Has foreign_key = direct filter
        if (isset($teamRel['foreign_key'])) {
            return [
                'type' => 'direct',
                'filter' => "WHERE {$alias}.{$teamRel['foreign_key']} IN {$param}",
                'parameters' => $parameters,
            ];
        }

        // No foreign_key = relationship traversal
        return [
            'type' => 'relationship',
            'filter' => "MATCH ({$alias})-[:{$teamRel['type']}]->(t:Team) WHERE t.id IN {$param}",
            'parameters' => $parameters,
        ];
    }

    /**
     * Resolve parent path like 'union.team_id' or multi-hop 'unit.union.team_id'.
     */
    private function resolveParentPath(string $entity, string $alias, string $path, array $teamIds): ?array
    {
        $parts = explode('.', $path);
        $finalColumn = array_pop($parts); // e.g., 'team_id'

        if (empty($parts)) {
            return null;
        }

        // Build relationship chain for multi-hop paths
        $matchChain = '';
        $currentEntity = $entity;
        $currentAlias = $alias;
        $lastAlias = '';
        $usedAliases = [$alias => true];

        foreach ($parts as $i => $parentKey) {
            $parentEntity = ucfirst($parentKey);

            // Find relationship from current entity to parent entity
            $configKey = $this->getEntityConfigKey($currentEntity);
            $relationships = config("entities.{$configKey}.graph.relationships", []);

            $parentRel = collect($relationships)
                ->first(fn($r) => ($r['target_label'] ?? null) === $parentEntity);

            if (!$parentRel) {
                return null;
            }

            // Generate unique alias
            $parentAlias = strtolower(substr($parentEntity, 0, 1));
            $suffix = 0;
            while (isset($usedAliases[$parentAlias])) {
                $suffix++;
                $parentAlias = strtolower(substr($parentEntity, 0, 1)) . $suffix;
            }
            $usedAliases[$parentAlias] = true;

            $matchChain .= "MATCH ({$currentAlias})-[:{$parentRel['type']}]->({$parentAlias}:{$parentEntity}) ";

            $currentEntity = $parentEntity;
            $currentAlias = $parentAlias;
            $lastAlias = $parentAlias;
        }

        return [
            'type' => 'parent',
            'filter' => "{$matchChain}WHERE {$lastAlias}.{$finalColumn} IN \$" . self::TEAM_IDS_PARAM,
            'parameters' => [self::TEAM_IDS_PARAM => $teamIds],
        ];
    }
}
