<?php

namespace Condoedge\Ai\Models\Plugins;

use Condoedge\Utils\Models\Plugins\ModelPlugin;

/**
 * File Access Scope Plugin
 *
 * Automatically registers the `accessibleBy` scope on File models,
 * implementing user_id and optional team_id filtering for AI file context.
 *
 * This plugin provides a default implementation that can be overridden
 * by implementing a custom `scopeAccessibleBy` on your File model.
 */
class FileAccessScopePlugin extends ModelPlugin
{
    /**
     * Boot the plugin and register the accessibleBy scope
     *
     * @return void
     */
    public function onBoot(): void
    {
        // The scope is added via model method, not event listener
    }

    /**
     * Define model methods that the plugin adds
     *
     * @return array
     */
    public function managableMethods(): array
    {
        return [
            'scopeAccessibleBy' => function ($query, $user) {
                return $this->applyAccessibleByScope($query, $user);
            },
        ];
    }

    /**
     * Apply the accessibleBy scope logic
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param mixed $user
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyAccessibleByScope($query, $user)
    {
        if (!$user) {
            // No user - return impossible condition
            return $query->whereRaw('1 = 0');
        }

        $useUserFilter = config('ai.file_context.fallback_filters.use_user_filter', true);
        $useTeamFilter = config('ai.file_context.fallback_filters.use_team_filter', true);

        // If both filters are disabled, return impossible condition for security
        if (!$useUserFilter && !$useTeamFilter) {
            return $query->whereRaw('1 = 0');
        }

        // Apply filters with AND logic (both must match when both enabled)
        if ($useUserFilter) {
            $query->where('user_id', $user->id ?? $user->getKey());
        }

        if ($useTeamFilter) {
            $teamId = safeCurrentTeamId();
            if ($teamId) {
                $query->where('team_id', $teamId);
            }
        }

        return $query;
    }
}
