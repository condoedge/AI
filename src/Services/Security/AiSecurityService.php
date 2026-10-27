<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Support\Audiences;
use Illuminate\Support\Facades\Log;

/**
 * Main security service for AI queries.
 * Orchestrates permission checking and team filter generation.
 *
 * Below the team, two optional limits: an audience may list the entities it reads
 * (ai.audiences.map.<key>.data_entities), and a user the host narrows (RowScope) gets each
 * entity's row rule instead of its team rule — entities without a row_scope_path are refused.
 */
class AiSecurityService
{
    public function __construct(
        private readonly AiAuthAdapterInterface $authAdapter,
        private readonly TeamPathResolver $pathResolver
    ) {}

    /**
     * Get team filter for entity query.
     *
     * @param mixed $user User model
     * @param string $entity Entity name
     * @param string $alias Cypher alias
     * @return string|null Filter clause or null if no filtering needed
     * @throws SecurityException When access denied
     */
    public function getTeamFilter($user, string $entity, string $alias): ?string
    {
        return $this->getTeamFilterWithParameters($user, $entity, $alias)['filter'];
    }

    /**
     * Get team filter together with the bound Cypher parameters.
     *
     * The filter references a bound parameter ($ai_team_ids) instead of an
     * inline `IN [...]` literal; the returned `parameters` map must be merged
     * into the query parameters by the executor.
     *
     * @param mixed $user User model
     * @param string $entity Entity name
     * @param string $alias Cypher alias
     *
     * `rule` is the {@see TeamPathResolver::ruleFor()} template the filter was built from
     * (the row rule for a narrowed user), so the acceptance gate verifies that same rule.
     *
     * @return array{filter: string|null, parameters: array<string, mixed>, rule?: array|null}
     * @throws SecurityException When access denied
     */
    public function getTeamFilterWithParameters($user, string $entity, string $alias): array
    {
        $none = ['filter' => null, 'parameters' => []];

        if (!$this->authAdapter->isEnabled()) {
            return $none;
        }

        if (!$this->audienceAllows($user, $entity)) {
            $this->logDeniedAccess($user, $entity, 'audience');
            throw new SecurityException("You do not have permission to query {$entity} data");
        }

        $rowIds = RowScope::idsFor($user);

        if ($this->authAdapter->checkIfPermissionsExist($user, $entity) === false) {
            $action = config('ai.security.on_permission_unexistent', 'deny');

            // The bypass would read the entity unfiltered: never for a narrowed user.
            if ($action === 'deny' || $rowIds !== null) {
                throw new SecurityException(
                    "Cannot verify permissions for {$entity} data"
                );
            }

            // 'bypass' - return null (no filter)
            return $none;
        }

        $teamIds = $this->authAdapter->getTeamsWithPermission($user, $entity);

        if (empty($teamIds)) {
            $this->logDeniedAccess($user, $entity, 'no_permission');
            throw new SecurityException(
                "You do not have permission to query {$entity} data"
            );
        }

        if ($rowIds !== null && $this->pathResolver->ruleFor($entity, $alias) !== null) {
            return $this->rowScoped($user, $entity, $alias, $teamIds, $rowIds);
        }

        $resolved = $this->pathResolver->resolve($entity, $alias, $teamIds);

        if ($resolved === null) {
            return ['filter' => $this->handleMissingTeamPath($user, $entity), 'parameters' => []];
        }

        return [
            'filter' => $resolved['filter'],
            'parameters' => $resolved['parameters'] ?? [],
            'rule' => $this->pathResolver->ruleFor($entity, $alias),
        ];
    }

    /**
     * Whether the user may read the entity at all (audience list, row path when narrowed);
     * the team permission is still checked at query time. Used to keep labels out of the prompt.
     */
    public function canRead($user, string $entity): bool
    {
        if (!$this->authAdapter->isEnabled()) {
            return true;
        }
        if (!$this->audienceAllows($user, $entity)) {
            return false;
        }

        $rowIds = RowScope::idsFor($user);
        if ($rowIds === null || $this->pathResolver->ruleFor($entity, 'n') === null) {
            return true;
        }

        return $rowIds !== [] && $this->pathResolver->ruleFor($entity, 'n', true) !== null;
    }

    /**
     * Check if team filter should be skipped for COUNT query.
     */
    public function shouldSkipTeamFilterForCount($user, string $entity): bool
    {
        // A global count would cross the row scope or the audience list.
        if (RowScope::idsFor($user) !== null || Audiences::dataEntities($user) !== null) {
            return false;
        }

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

    /** The row rule for a narrowed user; the team ids stay bound so a generated team predicate still runs. */
    private function rowScoped($user, string $entity, string $alias, array $teamIds, array $rowIds): array
    {
        $rule = $this->pathResolver->ruleFor($entity, $alias, true);
        if ($rule === null || $rowIds === []) {
            $this->logDeniedAccess($user, $entity, $rule === null ? 'no_row_scope_path' : 'empty_row_scope');
            throw new SecurityException("You do not have permission to query {$entity} data");
        }

        return [
            'filter' => $rule['filter'],
            'parameters' => [
                TeamPathResolver::TEAM_IDS_PARAM => array_values(array_map('intval', array_filter($teamIds, 'is_numeric'))),
                TeamPathResolver::ROW_SCOPE_IDS_PARAM => $rowIds,
            ],
            'rule' => $rule,
        ];
    }

    private function audienceAllows($user, string $entity): bool
    {
        $entities = Audiences::dataEntities($user);

        return $entities === null || in_array($entity, $entities, true);
    }

    /**
     * Handle missing team path based on config.
     */
    private function handleMissingTeamPath($user, string $entity): ?string
    {
        $this->logMissingTeamPath($user, $entity);

        $action = config('ai.security.on_missing_team_path', 'deny');

        if ($action === 'deny') {
            throw new SecurityException(
                "Cannot verify permissions for {$entity} data"
            );
        }

        // 'bypass' - return null (no filter)
        return null;
    }

    /**
     * Log denied access attempt.
     */
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

    /**
     * Log missing team path.
     */
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
}
