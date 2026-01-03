<?php

namespace Condoedge\Ai\Models\Plugins;

use Condoedge\Utils\Models\Plugins\ModelPlugin;
use Illuminate\Database\Eloquent\Builder;

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
     * Boot the plugin and register the accessibleBy scope via Builder macro
     *
     * @return void
     */
    public function onBoot(): void
    {
        Builder::macro('accessibleBy', function ($user) {
            /** @var Builder $this */
            if (!$user) {
                return $this->whereRaw('1 = 0');
            }

            $useUserFilter = config('ai.file_context.fallback_filters.use_user_filter', true);
            $useTeamFilter = config('ai.file_context.fallback_filters.use_team_filter', true);
            $userId = $user->id ?? $user->getKey();
            $teamId = $useTeamFilter ? safeCurrentTeamId() : null;

            if (!$useUserFilter && !$useTeamFilter) {
                return $this->whereRaw('1 = 0');
            }

            // Use OR logic: user_id matches OR team_id matches
            return $this->where(function ($q) use ($userId, $teamId, $useUserFilter, $useTeamFilter) {
                if ($useUserFilter) {
                    $q->where('user_id', $userId);
                }

                if ($useTeamFilter && $teamId) {
                    $q->orWhere('team_id', $teamId);
                }
            });
        });
    }
}
