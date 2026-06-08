<?php

namespace Condoedge\Ai\Models\Plugins;

use Condoedge\Utils\Models\Plugins\ModelPlugin;
use Illuminate\Database\Eloquent\Builder;

/**
 * File Access Scope Plugin
 *
 * Registers a fallback `accessibleBy` scope on File models for AI file context.
 *
 * Authorization precedence:
 * 1. If the File model defines its own `scopeAccessibleBy`, that host rule wins.
 *    (The macro detects and delegates to it, because a global Builder macro would
 *    otherwise shadow the named scope in Builder::__call.)
 * 2. Otherwise this fallback fails CLOSED — it returns no rows. The host is
 *    expected to define its own per-file visibility rule.
 *
 * The legacy team-grain OR-filter (user_id OR current team_id) is intentionally
 * NOT the default: it exposes every team file to every team member, ignoring
 * per-file visibility. It is available only as an explicit, documented opt-in via
 * config('ai.file_context.team_grain_unsafe') for hosts that knowingly accept
 * team-level granularity.
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

            // Defer to a host-defined scopeAccessibleBy when present. A global
            // Builder macro is resolved before named scopes in Builder::__call,
            // so without this delegation the host's own rule would never run.
            $model = $this->getModel();
            if ($model !== null && $model->hasNamedScope('accessibleBy')) {
                return $model->callNamedScope('accessibleBy', [$this, $user]);
            }

            if (!$user) {
                return $this->whereRaw('1 = 0');
            }

            // Fail closed by default: without a host rule, deny everything rather
            // than masquerade the coarse team-grain filter as secure.
            if (!config('ai.file_context.team_grain_unsafe', false)) {
                return $this->whereRaw('1 = 0');
            }

            // Explicit opt-in: coarse team-grain visibility (user_id OR team_id).
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
