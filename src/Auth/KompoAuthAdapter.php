<?php
// src/Auth/KompoAuthAdapter.php

declare(strict_types=1);

namespace Condoedge\Ai\Auth;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;

use Kompo\Auth\Models\Teams\Permission;

/**
 * Kompo Auth adapter for AI security.
 * 
 * It must be used just if kompo/auth installed
 */
class KompoAuthAdapter implements AiAuthAdapterInterface
{
    /**
     * {@inheritdoc}
     */
    public function getTeamsWithPermission($user, string $entity): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        if (config('ai.security.permission_level', 'teams') === 'teams') {
            return $user->getAllAccessibleTeamIds();
        }

        $permissionChain = config('ai.security.permission_chain', [
            '{Entity}.AiRetrieving',
            '{Entity}',
        ]);

        $permissionType = $this->getReadPermissionType();

        foreach ($permissionChain as $pattern) {
            $permission = str_replace('{Entity}', $entity, $pattern);
            $teams = $user->getTeamsIdsWithPermission($permission, $permissionType);

            if ($teams->isNotEmpty()) {
                return $teams->toArray();
            }
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function hasGlobalCountPermission($user, string $entity): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $pattern = config('ai.security.global_count_permission', '{Entity}.AiGlobalCount');
        $permission = str_replace('{Entity}', $entity, $pattern);

        return $user->hasPermission($permission, $this->getReadPermissionType());
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        if (!config('ai.security.enabled', true)) {
            return false;
        }

        // globalSecurityBypass() lives in kompo/auth; a stray call without it
        // must not fatal. Absent the bypass, security stays enabled (closed).
        if (function_exists('globalSecurityBypass')) {
            return !globalSecurityBypass();
        }

        return true;
    }


    public function checkIfPermissionsExist($user, string $entity): bool
    {
        // Without kompo/auth's Permission model we cannot probe existence; stay
        // on the team-filtering path (true) so access is denied, not skipped.
        if (!class_exists(Permission::class)) {
            return true;
        }

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

    /**
     * Get the READ permission type for Kompo Auth.
     *
     * Uses PermissionTypeEnum::READ if available, falls back to 1.
     *
     * @return mixed
     */
    protected function getReadPermissionType(): mixed
    {
        if (class_exists(\Kompo\Auth\Models\Teams\PermissionTypeEnum::class)) {
            return \Kompo\Auth\Models\Teams\PermissionTypeEnum::READ;
        }

        // Fallback for environments without Kompo Auth
        return 1;
    }
}
