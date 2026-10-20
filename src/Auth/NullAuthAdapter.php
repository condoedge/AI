<?php
// src/Auth/NullAuthAdapter.php

declare(strict_types=1);

namespace Condoedge\Ai\Auth;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;

/**
 * Fail-closed auth adapter used when kompo/auth is not installed.
 *
 * It never references any kompo/auth global or class, so secured queries can
 * run standalone without fataling. It denies by default: no teams are granted,
 * yet it keeps AiSecurityService on the team-filtering path so the empty team
 * set results in a denial rather than an unfiltered (fail-open) query.
 */
class NullAuthAdapter implements AiAuthAdapterInterface
{
    /**
     * {@inheritdoc}
     */
    public function getTeamsWithPermission($user, string $entity): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function hasGlobalCountPermission($user, string $entity): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return (bool) config('ai.security.enabled', true);
    }

    /**
     * Keep AiSecurityService on the team-filtering path.
     *
     * Returning false would make the service skip filtering entirely (fail
     * open); returning true means the empty team set from
     * getTeamsWithPermission() denies access instead.
     */
    public function checkIfPermissionsExist($user, string $entity): bool
    {
        return true;
    }
}
