<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Entity Access Checker Interface
 *
 * Determines if a user can access a specific entity.
 * Implementations should check against your application's permission system.
 */
interface EntityAccessCheckerInterface
{
    /**
     * Check if user can access an entity
     *
     * @param string $entityType The entity type (e.g., 'Person', 'Customer')
     * @param string|int $entityId The entity ID
     * @param mixed $user The user to check access for
     * @return bool True if user can access the entity
     */
    public function canAccess(string $entityType, string|int $entityId, mixed $user): bool;
}
