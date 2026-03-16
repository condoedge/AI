<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Abstract interface for AI security authentication.
 * Allows swapping auth implementations.
 */
interface AiAuthAdapterInterface
{
    /**
     * Get team IDs where user has permission for entity.
     * Checks permission chain: {Entity}.AiRetrieving -> {Entity}
     *
     * @param mixed $user User model
     * @param string $entity Entity name (e.g., 'Invoice')
     * @return array<int> Team IDs
     */
    public function getTeamsWithPermission($user, string $entity): array;

    /**
     * Check if user has global count permission for entity.
     * Permission: {Entity}.AiGlobalCount
     *
     * @param mixed $user User model
     * @param string $entity Entity name
     * @return bool True if can see global counts
     */
    public function hasGlobalCountPermission($user, string $entity): bool;

    /**
     * Get accessible IDs for a specific security axis.
     *
     * For known axes (e.g., 'team'), implementations should use their native
     * permission system. For custom axes, the SecurityAxis resolver closure
     * takes precedence (called before this method).
     *
     * @param mixed $user User model
     * @param string $axisName Axis name (e.g., 'team', 'role', 'union')
     * @param string $entity Entity name (e.g., 'Invoice')
     * @return array<int> Accessible IDs
     */
    public function getAccessibleIdsForAxis($user, string $axisName, string $entity): array;

    /**
     * Check if security is enabled.
     */
    public function isEnabled(): bool;
}
