<?php

declare(strict_types=1);

namespace Condoedge\Ai\Security;

use Condoedge\Ai\Domain\Traits\ResolvesEntityConfigKey;

/**
 * Resolves security axis paths to Cypher filter clauses.
 *
 * Generalizes the TeamPathResolver logic for any security axis.
 * Supports direct column, auto-detect, parent path, and relationship traversal.
 */
class SecurityAxisPathResolver
{
    use ResolvesEntityConfigKey;

    /**
     * Resolve an axis path to a Cypher filter clause.
     *
     * @param string $axisName Axis name (e.g., 'team')
     * @param string $entity Entity name
     * @param string $alias Cypher alias
     * @param array<int> $allowedIds Accessible IDs for this axis
     * @return array{type: string, filter: string}|null
     */
    public function resolve(string $axisName, string $entity, string $alias, array $allowedIds): ?array
    {
        $path = $this->getAxisPath($axisName, $entity);

        if ($path === null) {
            return null;
        }

        $idList = implode(', ', $allowedIds);

        // Direct column: 'team_id'
        if (is_string($path) && !str_contains($path, '.') && $path !== 'auto') {
            return [
                'type' => 'direct',
                'filter' => "WHERE {$alias}.{$path} IN [{$idList}]",
            ];
        }

        // Auto-detect from relationships
        if ($path === 'auto') {
            return $this->autoDetect($axisName, $entity, $alias, $allowedIds);
        }

        // Parent path: 'invoice.team_id'
        if (is_string($path) && str_contains($path, '.')) {
            return $this->resolveParentPath($entity, $alias, $path, $allowedIds);
        }

        // Relationship path: ['rel' => 'MEMBER_OF', 'target' => 'Team']
        if (is_array($path) && isset($path['rel'])) {
            return [
                'type' => 'relationship',
                'filter' => "MATCH ({$alias})-[:{$path['rel']}]->(t:{$path['target']}) WHERE t.id IN [{$idList}]",
            ];
        }

        return null;
    }

    /**
     * Get axis path from entity config with fallback to axis default.
     */
    private function getAxisPath(string $axisName, string $entity): mixed
    {
        $configKey = $this->getEntityConfigKey($entity);

        // Check entity-level axis path config
        $entityAxisPath = config("entities.{$configKey}.security.axes.{$axisName}.path");
        if ($entityAxisPath !== null) {
            return $entityAxisPath;
        }

        // Check entity-level field config (shorthand)
        $entityField = config("entities.{$configKey}.security.axes.{$axisName}.field");
        if ($entityField !== null) {
            return $entityField;
        }

        // Check entity-level join config
        $entityJoin = config("entities.{$configKey}.security.axes.{$axisName}.join");
        if ($entityJoin !== null) {
            return $entityJoin;
        }

        // Legacy support: entity-level team_path for 'team' axis
        if ($axisName === 'team') {
            $legacyPath = config("entities.{$configKey}.security.team_path");
            if ($legacyPath !== null) {
                return $legacyPath;
            }
        }

        // Use axis default path from SecurityAxis config
        $axisDefaultPath = config("ai.security.axes.{$axisName}.default_path");
        if ($axisDefaultPath !== null) {
            return $axisDefaultPath;
        }

        // Legacy fallback for 'team' axis
        if ($axisName === 'team') {
            return config('ai.security.default_team_path', 'auto');
        }

        return 'auto';
    }

    /**
     * Auto-detect axis path from relationships config.
     */
    private function autoDetect(string $axisName, string $entity, string $alias, array $allowedIds): ?array
    {
        $configKey = $this->getEntityConfigKey($entity);
        $relationships = config("entities.{$configKey}.graph.relationships", []);

        // Determine target label from axis name (e.g., 'team' -> 'Team')
        $targetLabel = ucfirst($axisName);

        // Find relationship to the axis target
        $rel = collect($relationships)
            ->first(fn($r) => ($r['target_label'] ?? null) === $targetLabel);

        if (!$rel) {
            return null;
        }

        $idList = implode(', ', $allowedIds);

        // Has foreign_key = direct filter
        if (isset($rel['foreign_key'])) {
            return [
                'type' => 'direct',
                'filter' => "WHERE {$alias}.{$rel['foreign_key']} IN [{$idList}]",
            ];
        }

        // No foreign_key = relationship traversal
        return [
            'type' => 'relationship',
            'filter' => "MATCH ({$alias})-[:{$rel['type']}]->(t:{$targetLabel}) WHERE t.id IN [{$idList}]",
        ];
    }

    /**
     * Resolve parent path like 'invoice.team_id'.
     */
    private function resolveParentPath(string $entity, string $alias, string $path, array $allowedIds): ?array
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
        $idList = implode(', ', $allowedIds);

        return [
            'type' => 'parent',
            'filter' => "MATCH ({$alias})-[:{$parentRel['type']}]->({$parentAlias}:{$parentEntity}) WHERE {$parentAlias}.{$column} IN [{$idList}]",
        ];
    }
}
