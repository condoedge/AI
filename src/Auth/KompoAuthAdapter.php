<?php
// src/Auth/KompoAuthAdapter.php

declare(strict_types=1);

namespace Condoedge\Ai\Auth;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;

/**
 * Kompo Auth adapter for AI security.
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
        return config('ai.security.enabled', true) && !globalSecurityBypass();
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
