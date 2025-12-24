<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

/**
 * SystemPromptSection
 *
 * The initial system prompt that sets the LLM's role.
 * Priority: 10
 */
class SystemPromptSection extends BaseResponseSection
{
    protected string $name = 'system';
    protected int $priority = 10;

    private ?string $customPrompt = null;

    /**
     * Set a custom system prompt
     */
    public function setPrompt(string $prompt): self
    {
        $this->customPrompt = $prompt;
        return $this;
    }

    public function format(array $context, array $options = []): string
    {
        if ($this->customPrompt !== null) {
            return $this->customPrompt . "\n\n";
        }

        return "You are a data analyst who explains query results clearly and accurately.\n\n";
    }
}
