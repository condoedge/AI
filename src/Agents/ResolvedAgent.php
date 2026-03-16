<?php

declare(strict_types=1);

namespace Condoedge\Ai\Agents;

use Condoedge\Ai\Models\AiAgentCustomization;

/**
 * Result of merging a base agent definition with user/team customization.
 *
 * Security fields (allowedEntities) always come from the base definition
 * and cannot be overridden by customizations.
 */
class ResolvedAgent
{
    public function __construct(
        public readonly AgentDefinition $base,
        public readonly ?AiAgentCustomization $customization = null,
    ) {}

    /**
     * Get the merged system prompt (base + custom instructions).
     */
    public function getSystemPrompt(): string
    {
        $prompt = $this->base->systemPrompt;

        if ($this->customization && $this->customization->custom_instructions) {
            $prompt .= "\n\n" . $this->customization->custom_instructions;
        }

        return $prompt;
    }

    /**
     * Get the merged business rules (base + custom rules).
     */
    public function getBusinessRules(): array
    {
        $rules = $this->base->businessRules;

        if ($this->customization && !empty($this->customization->custom_business_rules)) {
            $rules = array_merge($rules, $this->customization->custom_business_rules);
        }

        return $rules;
    }

    /**
     * Get example questions (custom if exists, otherwise base).
     */
    public function getExampleQuestions(): array
    {
        if ($this->customization && !empty($this->customization->custom_example_questions)) {
            return $this->customization->custom_example_questions;
        }

        return $this->base->exampleQuestions;
    }

    /**
     * Get allowed entities - ALWAYS from base (security, never overridable).
     */
    public function getAllowedEntities(): array
    {
        return $this->base->allowedEntities;
    }

    /**
     * Get agent ID.
     */
    public function getId(): string
    {
        return $this->base->id;
    }

    /**
     * Get agent name.
     */
    public function getName(): string
    {
        return $this->base->name;
    }

    /**
     * Get allowed tools - ALWAYS from base (security, never overridable).
     */
    public function getAllowedTools(): array
    {
        return $this->base->allowedTools;
    }
}
