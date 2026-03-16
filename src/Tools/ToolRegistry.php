<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tools;

use Condoedge\Ai\Agents\ResolvedAgent;

/**
 * Registry of all available tools that can be called by the LLM.
 */
class ToolRegistry
{
    /** @var array<string, ToolDefinition> */
    private array $tools = [];

    /**
     * Register a tool.
     */
    public function register(ToolDefinition $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    /**
     * Get a tool by name.
     */
    public function get(string $name): ?ToolDefinition
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Get all registered tools.
     *
     * @return array<string, ToolDefinition>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Get tools allowed for a specific agent.
     *
     * @return array<string, ToolDefinition>
     */
    public function getForAgent(ResolvedAgent $agent): array
    {
        $allowedTools = $agent->getAllowedTools();

        // Empty allowed_tools means all tools are available
        if (empty($allowedTools)) {
            return $this->tools;
        }

        return array_filter(
            $this->tools,
            fn(ToolDefinition $tool) => in_array($tool->name, $allowedTools),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Convert all tools to OpenAI function calling schema.
     */
    public function toOpenAiSchema(): array
    {
        return array_values(array_map(
            fn(ToolDefinition $tool) => $tool->toOpenAiFunctionSchema(),
            $this->tools
        ));
    }

    /**
     * Convert agent-filtered tools to OpenAI schema.
     */
    public function toOpenAiSchemaForAgent(ResolvedAgent $agent): array
    {
        return array_values(array_map(
            fn(ToolDefinition $tool) => $tool->toOpenAiFunctionSchema(),
            $this->getForAgent($agent)
        ));
    }

    /**
     * Check if a tool exists.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }
}
