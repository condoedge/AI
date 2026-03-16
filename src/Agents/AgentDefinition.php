<?php

declare(strict_types=1);

namespace Condoedge\Ai\Agents;

/**
 * Value Object representing a base agent definition.
 *
 * Defines an agent's identity, capabilities, and behavior.
 * Security-related fields (allowedEntities) cannot be overridden by customizations.
 */
class AgentDefinition
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $systemPrompt,
        public readonly array $allowedEntities = [],
        public readonly array $allowedTools = [],
        public readonly array $businessRules = [],
        public readonly array $exampleQuestions = [],
        public readonly array $promptSections = [],
        public readonly array $responseSections = [],
        public readonly ?string $description = null,
    ) {}

    /**
     * Create from array configuration.
     */
    public static function fromArray(string $id, array $config): self
    {
        return new self(
            id: $id,
            name: $config['name'] ?? $id,
            systemPrompt: $config['system_prompt'] ?? 'You are a helpful assistant.',
            allowedEntities: $config['allowed_entities'] ?? [],
            allowedTools: $config['allowed_tools'] ?? [],
            businessRules: $config['business_rules'] ?? [],
            exampleQuestions: $config['example_questions'] ?? [],
            promptSections: $config['prompt_sections'] ?? [],
            responseSections: $config['response_sections'] ?? [],
            description: $config['description'] ?? null,
        );
    }
}
