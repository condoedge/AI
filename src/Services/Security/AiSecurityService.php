<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Security\SecurityAxesRegistry;
use Condoedge\Ai\Security\SecurityAxisPathResolver;
use Illuminate\Support\Facades\Log;
use Kompo\Auth\Models\Teams\Permission;

/**
 * Main security service for AI queries.
 * Orchestrates permission checking and multi-axis filter generation.
 */
class AiSecurityService
{
    public function __construct(
        private readonly AiAuthAdapterInterface $authAdapter,
        private readonly TeamPathResolver $pathResolver,
        private readonly SecurityAxesRegistry $axesRegistry,
        private readonly SecurityAxisPathResolver $axisPathResolver,
    ) {}

    /**
     * Get security filters for all configured axes.
     *
     * Returns an array of Cypher WHERE clauses (one per axis), to be combined with AND.
     *
     * @param mixed $user User model
     * @param string $entity Entity name
     * @param string $alias Cypher alias
     * @return string[] Array of filter clauses
     * @throws SecurityException When access denied
     */
    public function getSecurityFilters($user, string $entity, string $alias): array
    {
        if (!$this->authAdapter->isEnabled()) {
            return [];
        }

        $axes = $this->axesRegistry->all();

        // If no axes configured, fall back to legacy team-only behavior
        if (empty($axes)) {
            $legacyFilter = $this->getTeamFilter($user, $entity, $alias);
            return $legacyFilter ? [$legacyFilter] : [];
        }

        $filters = [];

        foreach ($axes as $name => $axis) {
            $ids = $this->axesRegistry->resolveAxis($name, $user);

            if (empty($ids)) {
                if ($axis->required) {
                    $this->logDeniedAccess($user, $entity, "no_ids_for_axis_{$name}");
                    throw new SecurityException(
                        "You do not have permission to query {$entity} data (axis: {$name})"
                    );
                }
                // Non-required axis with no IDs = skip filter
                continue;
            }

            $resolved = $this->axisPathResolver->resolve($name, $entity, $alias, $ids);

            if ($resolved === null) {
                $action = config('ai.security.on_missing_team_path', 'deny');
                if ($action === 'deny') {
                    $this->logMissingAxisPath($user, $entity, $name);
                    throw new SecurityException(
                        "Cannot verify permissions for {$entity} data (axis: {$name})"
                    );
                }
                // 'bypass' = skip this axis filter
                continue;
            }

            $filters[] = $resolved['filter'];
        }

        return $filters;
    }

    /**
     * Get team filter for entity query (backward compatibility).
     *
     * @param mixed $user User model
     * @param string $entity Entity name
     * @param string $alias Cypher alias
     * @return string|null Filter clause or null if no filtering needed
     * @throws SecurityException When access denied
     */
    public function getTeamFilter($user, string $entity, string $alias): ?string
    {
        if (!$this->authAdapter->isEnabled()) {
            return null;
        }

        if ($this->checkIfPermissionsExist($user, $entity) === false) {
            $action = config('ai.security.on_permission_unexistent', 'deny');

            if ($action === 'deny') {
                throw new SecurityException(
                    "Cannot verify permissions for {$entity} data"
                );
            }

            // 'bypass' - return null (no filter)
            return null;
        }

        // Get teams where user has permission
        $teamIds = $this->authAdapter->getTeamsWithPermission($user, $entity);

        if (empty($teamIds)) {
            $this->logDeniedAccess($user, $entity, 'no_permission');
            throw new SecurityException(
                "You do not have permission to query {$entity} data"
            );
        }

        // Resolve team path to filter
        $resolved = $this->pathResolver->resolve($entity, $alias, $teamIds);

        if ($resolved === null) {
            return $this->handleMissingTeamPath($user, $entity);
        }

        return $resolved['filter'];
    }

    /**
     * Check if team filter should be skipped for COUNT query.
     */
    public function shouldSkipTeamFilterForCount($user, string $entity): bool
    {
        if (!$this->authAdapter->isEnabled()) {
            return true;
        }

        return $this->authAdapter->hasGlobalCountPermission($user, $entity);
    }

    /**
     * Get teams for entity (for use in query building).
     */
    public function getTeamsForEntity($user, string $entity): array
    {
        if (!$this->authAdapter->isEnabled()) {
            return [];
        }

        return $this->authAdapter->getTeamsWithPermission($user, $entity);
    }

    private function checkIfPermissionsExist($user, string $entity): bool
    {
        $permissionChain = config('ai.security.permission_chain', [
            '{Entity}.AiRetrieving',
            '{Entity}',
        ]);

        while ($pattern = array_shift($permissionChain)) {
            $permission = str_replace('{Entity}', $entity, $pattern);
            if (Permission::findByKey($permission) !== null) {
                return true;
            }
        }

        return false;
    }

    private function handleMissingTeamPath($user, string $entity): ?string
    {
        $this->logMissingTeamPath($user, $entity);

        $action = config('ai.security.on_missing_team_path', 'deny');

        if ($action === 'deny') {
            throw new SecurityException(
                "Cannot verify permissions for {$entity} data"
            );
        }

        return null;
    }

    private function logDeniedAccess($user, string $entity, string $reason): void
    {
        if (!config('ai.security.log_denied_access', true)) {
            return;
        }

        Log::channel(config('ai.security.log_channel', 'ai-security'))->warning(
            "AI query access denied",
            [
                'user_id' => $user->id ?? null,
                'entity' => $entity,
                'reason' => $reason,
            ]
        );
    }

    private function logMissingTeamPath($user, string $entity): void
    {
        if (!config('ai.security.log_missing_team_path', true)) {
            return;
        }

        Log::channel(config('ai.security.log_channel', 'ai-security'))->warning(
            "Entity has no team_path configured",
            [
                'user_id' => $user->id ?? null,
                'entity' => $entity,
                'action' => config('ai.security.on_missing_team_path', 'deny'),
            ]
        );
    }

    private function logMissingAxisPath($user, string $entity, string $axisName): void
    {
        if (!config('ai.security.log_missing_team_path', true)) {
            return;
        }

        Log::channel(config('ai.security.log_channel', 'ai-security'))->warning(
            "Entity has no path configured for security axis",
            [
                'user_id' => $user->id ?? null,
                'entity' => $entity,
                'axis' => $axisName,
                'action' => config('ai.security.on_missing_team_path', 'deny'),
            ]
        );
    }
}
