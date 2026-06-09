<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security\Scope;

/**
 * Immutable result of {@see \Condoedge\Ai\Services\Security\CypherScopeAnalyzer::analyze()}.
 *
 * `isScopeable` is true ONLY when the analyzer has PROVEN every tenant-sensitive
 * node binding in the query is anchored and live at its scope's anchor — i.e. the
 * query is inside the safe-scopeable subset. Otherwise `reason` names the rejection
 * (for fail-closed logging and LLM-teachable feedback).
 *
 * `accessesNodes` distinguishes a node-binding query (which must be scoped or
 * fail-closed) from a pure scalar / introspection query (`RETURN 1`, `CALL
 * db.labels()`) that is not subject to team scoping at all.
 */
final class ScopePlan
{
    /**
     * @param Scope[] $scopes One Scope per node-binding region that needs filtering.
     * @param array<array{0:int,1:int}> $literalSpans Byte ranges of string literals / comments
     *                                                 / backtick identifiers (never spliced into).
     */
    public function __construct(
        public readonly bool $isScopeable,
        public readonly array $scopes,
        public readonly array $literalSpans = [],
        public readonly bool $accessesNodes = false,
        public readonly ?string $reason = null
    ) {
    }

    /**
     * Whether the query binds at least one tenant-sensitive (labelled) node that
     * must be team scoped. A scopeable plan with no sensitive bindings (e.g.
     * `RETURN 1`) is not sensitive and runs unfiltered.
     */
    public function hasSensitiveBindings(): bool
    {
        foreach ($this->scopes as $scope) {
            if (!empty($scope->sensitiveBindings)) {
                return true;
            }
        }

        return false;
    }

    /**
     * First sensitive binding (primary entity), for legacy single-scope callers
     * and fail-closed logging. Null when there is no sensitive binding.
     */
    public function primaryBinding(): ?NodeBinding
    {
        foreach ($this->scopes as $scope) {
            if (!empty($scope->sensitiveBindings)) {
                return $scope->sensitiveBindings[0];
            }
        }

        return null;
    }
}
