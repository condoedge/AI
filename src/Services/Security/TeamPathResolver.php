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
     * Resolve team path config to a Cypher filter together with the bound team IDs.
     *
     * The returned `filter` references the bound parameter `$ai_team_ids`; the
     * caller binds the (validated, integer) IDs via the `parameters` key. The
     * clause text is produced by {@see ruleFor()} (the single source of truth that
     * the generator prompt section and the acceptance gate also consume), so the
     * resolver, the generator and the gate agree on the filter shape by construction.
     *
     * @param string $entity Entity name
     * @param string $alias Cypher alias for entity (e.g., 'i' for Invoice)
     * @param array<int> $teamIds Team IDs to filter by
     * @return array{type: string, filter: string, parameters: array<string, array<int>>}|null
     */
    public function resolve(string $entity, string $alias, array $teamIds): ?array
    {
        $rule = $this->ruleFor($entity, $alias);

        if ($rule === null) {
            return null;
        }

        // Defence-in-depth: never trust the IDs as anything but integers.
        $teamIds = $this->normalizeTeamIds($teamIds);

        return [
            'type' => $rule['type'],
            'filter' => $rule['filter'],
            'parameters' => [self::TEAM_IDS_PARAM => $teamIds],
        ];
    }

    /**
     * The team-ID-free filter TEMPLATE for an entity binding.
     *
     * This is the single source of truth for "how is this label tenant-scoped":
     * it returns the Cypher clause that constrains $alias to $ai_team_ids WITHOUT
     * needing the actual permitted team IDs (the user/permission resolution stays
     * in {@see \Condoedge\Ai\Services\Security\AiSecurityService}). {@see resolve()}
     * is reimplemented on top of it (so the executed filter is byte-identical), the
     * generator prompt section renders it as an instruction, and the
     * {@see ScopeAcceptanceGate} verifies the generator emitted exactly this shape.
     *
     * Returns null when the label is NOT tenant-scoped (no team_path / no default),
     * which the caller treats as "this binding needs no predicate".
     *
     * @return array{type: string, kind: string, alias: string, column: ?string, filter: string}|null
     */
    public function ruleFor(string $entity, string $alias): ?array
    {
        $teamPath = $this->getTeamPath($entity);

        if ($teamPath === null) {
            return null;
        }

        $param = '$' . self::TEAM_IDS_PARAM;

        // Direct column: 'team_id'
        if (is_string($teamPath) && !str_contains($teamPath, '.') && $teamPath !== 'auto') {
            return [
                'type' => 'direct',
                'kind' => 'where',
                'alias' => $alias,
                'column' => $teamPath,
                'filter' => "WHERE {$alias}.{$teamPath} IN {$param}",
            ];
        }

        // Auto-detect from relationships
        if ($teamPath === 'auto') {
            return $this->autoDetectRule($entity, $alias);
        }

        // Parent path: 'invoice.team_id'
        if (is_string($teamPath) && str_contains($teamPath, '.')) {
            return $this->resolveParentPathRule($entity, $alias, $teamPath);
        }

        // Relationship path: ['rel' => 'MEMBER_OF', 'target' => 'Team']
        if (is_array($teamPath) && isset($teamPath['rel'])) {
            return [
                'type' => 'relationship',
                'kind' => 'match',
                'alias' => $alias,
                'column' => null,
                'filter' => "MATCH ({$alias})-[:{$teamPath['rel']}]->(t:{$teamPath['target']}) WHERE t.id IN {$param}",
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

        return config('ai.security.default_team_path', 'auto');
    }

    /**
     * Auto-detect team path TEMPLATE from relationships config.
     *
     * @return array{type: string, kind: string, alias: string, column: ?string, filter: string}|null
     */
    private function autoDetectRule(string $entity, string $alias): ?array
    {
        $configKey = $this->getEntityConfigKey($entity);
        $relationships = config("entities.{$configKey}.graph.relationships", []);

        $teamRel = collect($relationships)
            ->first(fn($r) => ($r['target_label'] ?? null) === 'Team');

        if (!$teamRel) {
            return null;
        }

        $param = '$' . self::TEAM_IDS_PARAM;

        // Has foreign_key = direct filter
        if (isset($teamRel['foreign_key'])) {
            return [
                'type' => 'direct',
                'kind' => 'where',
                'alias' => $alias,
                'column' => $teamRel['foreign_key'],
                'filter' => "WHERE {$alias}.{$teamRel['foreign_key']} IN {$param}",
            ];
        }

        // No foreign_key = relationship traversal
        return [
            'type' => 'relationship',
            'kind' => 'match',
            'alias' => $alias,
            'column' => null,
            'filter' => "MATCH ({$alias})-[:{$teamRel['type']}]->(t:Team) WHERE t.id IN {$param}",
        ];
    }

    /**
     * Resolve parent path TEMPLATE like 'union.team_id' or multi-hop
     * 'unit.union.team_id'.
     *
     * @return array{type: string, kind: string, alias: string, column: ?string, filter: string}|null
     */
    private function resolveParentPathRule(string $entity, string $alias, string $path): ?array
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
            'kind' => 'match',
            'alias' => $alias,
            'column' => $finalColumn,
            'filter' => "{$matchChain}WHERE {$lastAlias}.{$finalColumn} IN \$" . self::TEAM_IDS_PARAM,
        ];
    }
}
