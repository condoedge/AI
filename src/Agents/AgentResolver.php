<?php

declare(strict_types=1);

namespace Condoedge\Ai\Agents;

use Condoedge\Ai\Models\AiAgentCustomization;

/**
 * Resolves the effective agent: base definition + user/team customization.
 *
 * Security fields (allowedEntities, axes) are NEVER overridable by customizations.
 */
class AgentResolver
{
    public function __construct(
        private readonly AgentRegistry $registry,
    ) {}

    /**
     * Resolve the effective agent for a user/team context.
     *
     * @param string|null $agentId Agent ID (null = default)
     * @param int|null $userId User ID for customization lookup
     * @param int|null $teamId Team ID for customization lookup
     */
    public function resolve(?string $agentId, ?int $userId = null, ?int $teamId = null): ResolvedAgent
    {
        $base = $agentId
            ? ($this->registry->get($agentId) ?? $this->registry->getDefault())
            : $this->registry->getDefault();

        $customization = $this->findCustomization($base->id, $userId, $teamId);

        return new ResolvedAgent($base, $customization);
    }

    /**
     * Find the most specific customization for this agent.
     *
     * Priority: user+team > user > team
     */
    private function findCustomization(string $agentId, ?int $userId, ?int $teamId): ?AiAgentCustomization
    {
        if ($userId === null && $teamId === null) {
            return null;
        }

        // Try user+team specific first
        if ($userId !== null && $teamId !== null) {
            $customization = AiAgentCustomization::where('agent_id', $agentId)
                ->where('user_id', $userId)
                ->where('team_id', $teamId)
                ->first();

            if ($customization) {
                return $customization;
            }
        }

        // Try user-specific
        if ($userId !== null) {
            $customization = AiAgentCustomization::where('agent_id', $agentId)
                ->where('user_id', $userId)
                ->whereNull('team_id')
                ->first();

            if ($customization) {
                return $customization;
            }
        }

        // Try team-specific
        if ($teamId !== null) {
            $customization = AiAgentCustomization::where('agent_id', $agentId)
                ->whereNull('user_id')
                ->where('team_id', $teamId)
                ->first();

            if ($customization) {
                return $customization;
            }
        }

        return null;
    }
}
