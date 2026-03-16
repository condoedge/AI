<?php

declare(strict_types=1);

namespace Condoedge\Ai\Agents;

/**
 * Registry of available agent definitions.
 */
class AgentRegistry
{
    /** @var array<string, AgentDefinition> */
    private array $agents = [];

    private string $defaultId = 'general_assistant';

    public function register(AgentDefinition $agent): void
    {
        $this->agents[$agent->id] = $agent;
    }

    public function get(string $id): ?AgentDefinition
    {
        return $this->agents[$id] ?? null;
    }

    /** @return array<string, AgentDefinition> */
    public function all(): array
    {
        return $this->agents;
    }

    public function setDefaultId(string $id): void
    {
        $this->defaultId = $id;
    }

    public function getDefault(): AgentDefinition
    {
        return $this->agents[$this->defaultId]
            ?? reset($this->agents)
            ?: new AgentDefinition(
                id: 'general_assistant',
                name: 'AI Assistant',
                systemPrompt: 'You are a helpful data analyst.',
            );
    }
}
